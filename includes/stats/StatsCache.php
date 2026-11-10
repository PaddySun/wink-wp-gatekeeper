<?php
/**
 * 统计计数缓存（04 §4：option wink_gatekeeper_stats，仪表盘快渲染）。
 *
 * 结构：{today, by_gate, by_driver, total{by_gate, by_driver, reject}, last_reset}。
 * 口径：pass 默认不记（闸层只对落库事件调 record——落库与否归 EventsTable 写入矩阵，
 * 本层不过滤）；跨日翻转（today != 当前日 → 今日树清零、累计已在 total 中持续滚动）。
 *
 * 纯逻辑（单测覆盖）：seed()/incr()/reset()——计数树合并递增。
 * WP 边界（自查清单）：record()/get()/reset_all()——option 读写 + 重置同时清事件表。
 *
 * @package WinkGatekeeper\Stats
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Stats;

use WinkGatekeeper\Settings\SettingsSchema;
use WinkGatekeeper\Verdict;

final class StatsCache {

	public const OPTION_NAME = 'wink_gatekeeper_stats';

	/**
	 * 种子结构（04 §4 jsonc）。
	 *
	 * @return array<string, mixed>
	 */
	public static function seed(): array {
		return array(
			'today'      => '',
			'by_gate'    => array(),
			'by_driver'  => array(),
			'total'      => array(
				'by_gate'   => array(),
				'by_driver' => array(),
				'reject'    => 0, // 仪表盘「今日拦截」徽标的累计口径。
			),
			'last_reset' => 0,
		);
	}

	/**
	 * 计数树递增（纯函数；跨日翻转自动清零今日树——累计树持续滚动不受影响）。
	 *
	 * @param array<string, mixed> $cache   当前缓存树。
	 * @param string               $date    当前日期 YYYYMMDD（边界层站时区）。
	 * @param string               $gate    闸 id。
	 * @param string               $driver  驱动 id。
	 * @param string               $verdict 判定（pass 默认不会进到这里——写入矩阵过滤）。
	 * @return array<string, mixed> 新缓存树。
	 */
	public static function incr( array $cache, string $date, string $gate, string $driver, string $verdict ): array {
		$cache = self::normalize( $cache );

		if ( $cache['today'] !== $date ) {
			// 跨日翻转：今日树清零重建（累计树不动——04 §4「翻转即清零并累入 total」：
			// total 在每次 incr 时同步累计，翻转无需搬运）。
			$cache['today']    = $date;
			$cache['by_gate']   = array();
			$cache['by_driver'] = array();
		}

		$cache['by_gate'][ $gate ][ $verdict ]    = ( $cache['by_gate'][ $gate ][ $verdict ] ?? 0 ) + 1;
		$cache['by_driver'][ $driver ][ $verdict ] = ( $cache['by_driver'][ $driver ][ $verdict ] ?? 0 ) + 1;

		$cache['total']['by_gate'][ $gate ][ $verdict ]      = ( $cache['total']['by_gate'][ $gate ][ $verdict ] ?? 0 ) + 1;
		$cache['total']['by_driver'][ $driver ][ $verdict ]  = ( $cache['total']['by_driver'][ $driver ][ $verdict ] ?? 0 ) + 1;
		if ( 'reject' === $verdict ) {
			$cache['total']['reject']++;
		}

		return $cache;
	}

	/**
	 * by_gate attempts 维直记（【v1.5·F4-6】04 §4 三维扩展——登录失败尝试数）。
	 *
	 * attempts 是「失败尝试」不是判定事件（04 §3 写入矩阵无对应行）——不经 by_driver、
	 * 不推高 total.reject 徽标；仅 by_gate.{gate}.attempts 今日+累计双记（纯函数）。
	 *
	 * @param array<string, mixed> $cache 当前缓存树。
	 * @param string               $date  当前日期 YYYYMMDD（边界层站时区）。
	 * @param string               $gate  闸 id（仅 login 消费——register/lostpassword 无此维）。
	 * @return array<string, mixed> 新缓存树。
	 */
	public static function bump_attempts( array $cache, string $date, string $gate ): array {
		$cache = self::normalize( $cache );

		if ( $cache['today'] !== $date ) {
			$cache['today']     = $date;
			$cache['by_gate']   = array();
			$cache['by_driver'] = array();
		}

		$cache['by_gate'][ $gate ]['attempts']            = ( $cache['by_gate'][ $gate ]['attempts'] ?? 0 ) + 1;
		$cache['total']['by_gate'][ $gate ]['attempts']   = ( $cache['total']['by_gate'][ $gate ]['attempts'] ?? 0 ) + 1;

		return $cache;
	}

	/**
	 * drill（test_mode/演练）行判定（【v1.5】02 §3.2a/04 §4——drill 行不 bump 不进统计；
	 * Tab⑥/by_gate/钩子订阅侧查询与计数一律排除 drill=true 行）。
	 *
	 * @param array<string, mixed> $meta 事件 meta。
	 * @return bool
	 */
	public static function is_drill( array $meta ): bool {
		return ! empty( $meta['drill'] );
	}

	/**
	 * recorder 统计键映射（【v1.5·F4-4/F4-6】02 §3.2a 口径统一——captcha 判定收口按
	 * verdict 三维 bump，计数与落库解耦：落库与否仍按 04 §3 写入矩阵（EventsTable::
	 * should_record），本函数只决定 StatsCache 消费哪些键）：
	 *
	 *   captcha + pass   → ['captcha_pass']（04 §4 三维之一；no_driver/iprep_fail_no_driver
	 *                      「无驱动跳过」留痕不是判定收口——零消费）
	 *   captcha + reject → ['reject', 'captcha_reject']（拦截=既有 reject 维全阶段口径
	 *                      + 调用数组成维；04 §4 调用=captcha_pass+captcha_reject+error）
	 *   captcha + error  → ['error']（既有故障维）
	 *   其余阶段          → reject/review/error → 单键；pass → 零（pass 默认不记）。
	 *
	 * 纯函数（单测覆盖）；返回键列表由调用方逐键 StatsCache::record()。
	 *
	 * @param string            $stage   阶段（stub|captcha|review|开放枚举）。
	 * @param \WinkGatekeeper\Verdict $verdict 判定。
	 * @return string[] StatsCache 记录键（可能为空数组）。
	 */
	public static function stat_keys_for( string $stage, Verdict $verdict ): array {
		$verdict_value = $verdict->verdict();

		if ( Verdict::PASS === $verdict_value ) {
			if ( 'captcha' === $stage ) {
				foreach ( $verdict->hits() as $hit ) {
					if ( 'no_driver' === $hit || 'iprep_fail_no_driver' === $hit ) {
						return array();
					}
				}
				return array( 'captcha_pass' );
			}
			return array();
		}

		if ( 'captcha' === $stage && Verdict::REJECT === $verdict_value ) {
			return array( Verdict::REJECT, 'captcha_reject' );
		}

		return array( $verdict_value );
	}

	/**
	 * 重置为种子（保留今日日期标记；last_reset 记时间戳）。
	 *
	 * @param array<string, mixed> $cache 当前缓存树。
	 * @param int                  $now   重置时间戳。
	 * @return array<string, mixed>
	 */
	public static function reset( array $cache, int $now ): array {
		$seed            = self::seed();
		$seed['today']   = $cache['today'] ?? '';
		$seed['last_reset'] = $now;
		return $seed;
	}

	/**
	 * 结构归一（缺键补种子的防御性拷贝；异形输入回落种子）。
	 *
	 * @param array<string, mixed> $cache
	 * @return array<string, mixed>
	 */
	private static function normalize( array $cache ): array {
		if ( ! isset( $cache['today'], $cache['by_gate'], $cache['by_driver'], $cache['total'] )
			|| ! is_array( $cache['by_gate'] ) || ! is_array( $cache['by_driver'] ) || ! is_array( $cache['total'] ) ) {
			return self::seed();
		}
		$cache['total'] = array_merge(
			array( 'by_gate' => array(), 'by_driver' => array(), 'reject' => 0 ),
			$cache['total']
		);
		return $cache;
	}

	/* -------------------------------------------------------------------
	 * WP 边界（option 读写；单测环境不可达，静态自查清单覆盖）
	 * ------------------------------------------------------------------ */

	/**
	 * 记一笔（闸内调用；stats.enabled 显式 false 时跳过——统计面板总开关）。
	 *
	 * FIX-δ 2026-11-10（DEFECT-ROOTCAUSE P1-δ）：
	 *   - 开关读取经 SettingsSchema::merge_defaults 兜底——存储树缺 stats 子树/缺整个
	 *     option 时按默认 enabled=true 计数（杜绝「事件 10 条 vs 计数 5 条」的静默跳过）；
	 *   - update_option 返回 false 时记 error_log 一次（04 §4 v1.3 统计 best-effort，
	 *     失败不抛异常不影响判定，但须留痕——面板读数与事件表口径分裂至少可诊断）。
	 *
	 * @param string $gate    闸 id。
	 * @param string $driver  驱动 id。
	 * @param string $verdict 判定。
	 * @return void
	 */
	public static function record( string $gate, string $driver, string $verdict ): void {
		$settings = get_option( 'wink_gatekeeper_settings', array() );
		$settings = SettingsSchema::merge_defaults( is_array( $settings ) ? $settings : array() );
		if ( empty( $settings['stats']['enabled'] ) ) {
			return; // 统计开关显式关：不计数（事件表落库口径不受影响——写入矩阵独立）。
		}

		$cache   = get_option( self::OPTION_NAME, null );
		$cache   = is_array( $cache ) ? $cache : self::seed();
		$cache   = self::incr( $cache, current_time( 'Ymd' ), $gate, $driver, $verdict );
		$updated = update_option( self::OPTION_NAME, $cache, false );
		if ( false === $updated ) {
			error_log( '[wink-wp-gatekeeper] 统计计数写入失败（update_option 返回 false）：option=' . self::OPTION_NAME ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * attempts 维记一笔（【v1.5·F4-6】WP 边界——LoginGate::hook_wp_login_failed 消费）。
	 *
	 * 与 record() 同款防御：开关读取经 merge_defaults 兜底；update_option 失败记
	 * error_log 一次（04 §4 统计 best-effort）。attempts 直记不经事件表（失败尝试
	 * 非判定事件、04 §3 写入矩阵无对应行）。
	 *
	 * @param string $gate 闸 id（生产=login）。
	 * @return void
	 */
	public static function record_attempts( string $gate ): void {
		$settings = get_option( 'wink_gatekeeper_settings', array() );
		$settings = SettingsSchema::merge_defaults( is_array( $settings ) ? $settings : array() );
		if ( empty( $settings['stats']['enabled'] ) ) {
			return; // 统计开关显式关：不计数（与 record() 同口径）。
		}

		$cache   = get_option( self::OPTION_NAME, null );
		$cache   = is_array( $cache ) ? $cache : self::seed();
		$cache   = self::bump_attempts( $cache, current_time( 'Ymd' ), $gate );
		$updated = update_option( self::OPTION_NAME, $cache, false );
		if ( false === $updated ) {
			error_log( '[wink-wp-gatekeeper] 统计 attempts 写入失败（update_option 返回 false）：option=' . self::OPTION_NAME ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * 读缓存树（缺省种子）。
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$cache = get_option( self::OPTION_NAME, null );
		return is_array( $cache ) ? self::normalize( $cache ) : self::seed();
	}

	/**
	 * 重置统计（03 Tab⑥ AJAX：同时清计数缓存与事件表——「重置统计」清两者，04 §4）。
	 *
	 * @return void
	 */
	public static function reset_all(): void {
		update_option( self::OPTION_NAME, self::reset( self::get(), time() ), false );
		self::truncate();
	}

	/**
	 * 清空事件表（重置统计对账用）。
	 *
	 * @return void
	 */
	public static function truncate(): void {
		global $wpdb;
		$table = self::table_name( $wpdb->prefix );
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * 本插件拦截留档且仍在垃圾分队的评论数（Tab⑥ 黄条数据源，03 Tab⑥ v1.3，C2）。
	 *
	 * 口径：wp_comments.comment_approved='spam' INNER JOIN commentmeta
	 * （meta_key='wink_gatekeeper_archive'，Bootstrap 留档器写入）——只计本插件留档、
	 * 不计 Akismet 等其它来源的 spam；站长从垃圾分区恢复（误拦处置）后自动出数。
	 *
	 * @return int
	 */
	public static function archived_spam_count(): int {
		global $wpdb;

		$comments = $wpdb->prefix . 'comments';
		$meta     = $wpdb->prefix . 'commentmeta';

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$comments} AS c INNER JOIN {$meta} AS m ON m.comment_id = c.comment_ID " // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			. "WHERE c.comment_approved = 'spam' AND m.meta_key = 'wink_gatekeeper_archive'"
		);
	}
}
