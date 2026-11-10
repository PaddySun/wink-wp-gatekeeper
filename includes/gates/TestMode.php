<?php
/**
 * TestMode——验证码链路演练旁路（02 §2.8【v1.5】安全门五件套的读时判定与生命周期）。
 *
 * 定位：test_mode = 「验证码链路演练模式」（站长验证内容闸/云审/统计链路时临时旁路
 * 人机验证）。Wordfence 的 test_mode 是裸旁路——不可照抄，wink 版自带五件套：
 *   1. 显式开关默认关（schema captcha.test_mode + 内部键 test_mode_since；Tab② 黄条+二次确认）；
 *   2. 事件标注 drill + 统计隔离（rule_hit=test_mode_pass、meta.drill:true、drill 行不进统计）；
 *   3. 管理员可见提醒（Tab① 红点行 + admin-bar 黄条——Bootstrap/SettingsPage 消费）；
 *   4. 24h 自动过期（懒过期：消费点读时校验，无 cron；最坏暴露面收敛到 24h）；
 *   5. 关闭后残留清理（on/off/expired 全程留痕；旁路期不写 VerifyCache/TrustStub——闸侧）。
 *
 * 分层：active()/is_expired()/lifecycle_rule() 为纯逻辑（单测覆盖）；enforce()/
 * record_lifecycle() 为 WP 边界（option 写 + 事件表直插——沿 DriverHealth 留痕行经
 * insert 直入不经 should_record 的先例；function_exists 守卫保纯单测环境零依赖）。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

use WinkGatekeeper\Settings\SettingsSchema;
use WinkGatekeeper\Stats\EventsTable;
use WinkGatekeeper\Verdict;

final class TestMode {

	/**
	 * 有效期（秒；24h 自动过期——安全门 #4）。
	 */
	public const TTL_SECONDS = 86400;

	/**
	 * option 名（enforce 懒过期写回用；与 SettingsPage::OPTION_NAME 同值——不耦合设置页类）。
	 */
	public const OPTION_NAME = 'wink_gatekeeper_settings';

	/**
	 * 旁路放行事件 rule_hit（04 §3 矩阵 captcha 行 v1.5·C1 特例）。
	 */
	public const RULE_PASS = 'test_mode_pass';

	/**
	 * 生命周期事件 rule_hit（gate=system、stage=health；meta.drill:true）。
	 */
	public const RULE_ON      = 'test_mode_on';
	public const RULE_OFF     = 'test_mode_off';
	public const RULE_EXPIRED = 'test_mode_expired';

	/**
	 * 开关字面态（安全门 #1：schema captcha.test_mode）。
	 *
	 * @param array<string, mixed> $settings 设置树。
	 * @return bool
	 */
	public static function flag_on( array $settings ): bool {
		$captcha = isset( $settings['captcha'] ) && is_array( $settings['captcha'] ) ? $settings['captcha'] : array();
		return array_key_exists( 'test_mode', $captcha ) && true === $captcha['test_mode'];
	}

	/**
	 * 旁路是否生效（任何消费点的读时判定）：开关 on + since 有效 + 24h 内。
	 * since 缺失/为 0（开关被旁路写入的防御）→ 视同关（fail-safe）。
	 *
	 * @param array<string, mixed> $settings 设置树。
	 * @param int                  $now      当前时间戳。
	 * @return bool
	 */
	public static function active( array $settings, int $now ): bool {
		if ( ! self::flag_on( $settings ) ) {
			return false;
		}
		$captcha = is_array( $settings['captcha'] ) ? $settings['captcha'] : array();
		$since   = isset( $captcha['test_mode_since'] ) && is_numeric( $captcha['test_mode_since'] ) ? (int) $captcha['test_mode_since'] : 0;
		return $since > 0 && ( $now - $since ) < self::TTL_SECONDS;
	}

	/**
	 * 懒过期触发条件：开关 on 且（since 缺失或超 24h）——enforce() 消费。
	 *
	 * @param array<string, mixed> $settings 设置树。
	 * @param int                  $now      当前时间戳。
	 * @return bool
	 */
	public static function is_expired( array $settings, int $now ): bool {
		if ( ! self::flag_on( $settings ) ) {
			return false;
		}
		$captcha = is_array( $settings['captcha'] ) ? $settings['captcha'] : array();
		$since   = isset( $captcha['test_mode_since'] ) && is_numeric( $captcha['test_mode_since'] ) ? (int) $captcha['test_mode_since'] : 0;
		return $since <= 0 || ( $now - $since ) >= self::TTL_SECONDS;
	}

	/**
	 * 生命周期事件 rule 派生（纯函数）：on/off 切换各一条；无切换 null（零事件）。
	 * expired 由 enforce() 直派（读时触发，不经本函数）。
	 *
	 * @param bool $old 切换前开关态。
	 * @param bool $new 切换后开关态。
	 * @return string|null
	 */
	public static function lifecycle_rule( bool $old, bool $new ): ?string {
		if ( $new === $old ) {
			return null;
		}
		return $new ? self::RULE_ON : self::RULE_OFF;
	}

	/**
	 * 懒过期收口（WP 边界；Bootstrap::assemble_gates 装配前消费——单消费点收口，闸内
	 * 只读 settings 已被本函数修正的树）：开关 on 且超 24h → 清 test_mode/test_mode_since
	 * + 落 test_mode_expired 事件 + 写回 option，返回修正树。
	 *
	 * @param array<string, mixed> $settings merge_defaults 后的完整设置树。
	 * @return array<string, mixed> 修正后设置树（闸/设置页/提醒位消费）。
	 */
	public static function enforce( array $settings ): array {
		if ( ! self::is_expired( $settings, time() ) ) { // WP 边界时钟。
			return $settings;
		}

		$settings['captcha']['test_mode']       = false;
		$settings['captcha']['test_mode_since'] = 0;

		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_NAME, $settings, false ); // WP 边界（懒过期持久化）。
		}
		self::record_lifecycle( self::RULE_EXPIRED );

		return $settings;
	}

	/**
	 * 生命周期事件留痕（WP 边界；on/off/expired 三态全程可审计——04 §3 矩阵 system 行）：
	 * gate=system、stage=health、driver=gate、verdict=pass、meta.drill:true。不进统计
	 * （EventsTable::insert 直入不经 should_record；StatsCache 零消费——drill 恒排除口径）。
	 *
	 * @param string $rule_hit RULE_ON|RULE_OFF|RULE_EXPIRED。
	 * @return void
	 */
	public static function record_lifecycle( string $rule_hit ): void {
		if ( ! function_exists( 'wp_salt' ) ) {
			return; // 纯单测环境守卫（无 WP 无事件表——G5 零依赖）。
		}
		EventsTable::insert(
			'system',
			'health',
			'gate',
			Verdict::pass( 'gate', $rule_hit, array( $rule_hit ) ),
			array( 'drill' => true ),
			''
		);
	}

	/**
	 * Tab① 红点行/admin-bar 黄条/二次确认区的展示态（WP 边界：读 option 合并默认后判定）。
	 *
	 * @return bool
	 */
	public static function wp_active(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}
		$stored = get_option( self::OPTION_NAME, array() ); // WP 边界。
		$merged = SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() );
		return self::active( $merged, time() ); // WP 边界时钟。
	}
}
