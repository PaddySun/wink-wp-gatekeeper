<?php
/**
 * Tab① 点名册推导纯函数测试（UI 批 3——DESIGN-UI-tab1 B 提案块 C + agent C §E-b）。
 *
 * 点名册=渲染侧推导：registry 全集×配置树×DriverHealth×Usage（零存储变更——N9 不采纳）。
 * 推导矩阵：排班态（当班/待命/未配置）×健康态（正常/故障/额度用完）全组合 + 微排序 +
 * 今日一句摘要 + 战报小票/副行 + 友好时间 + 岔子行。全部纯函数零 WP 依赖。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Stats\OverviewRoster;

final class OverviewRosterTest extends TestCase {

	/* ---------- 夹具 ---------- */

	/**
	 * registry 行夹具（id/label/type/settings.required 声明——DriverConfigSpec::missing_fields 消费面）。
	 *
	 * @param string   $id       驱动 id。
	 * @param string   $label    人话名。
	 * @param string   $type     captcha|review。
	 * @param string[] $required 必填字段。
	 * @return array<string, mixed>
	 */
	private static function meta( string $id, string $label, string $type, array $required ): array {
		$settings = array();
		foreach ( $required as $field ) {
			$settings[ $field ] = array( 'type' => 'password', 'sanitize' => 'password', 'label' => $field, 'required' => true );
		}
		return array( 'id' => $id, 'label' => $label, 'type' => $type, 'settings' => $settings );
	}

	/**
	 * 推导上下文夹具（缺键安全回落）。
	 *
	 * @param array<string, mixed> $overrides 覆盖键。
	 * @return array<string, mixed>
	 */
	private static function ctx( array $overrides = array() ): array {
		return array_merge( array(
			'captcha_driver' => '',
			'cloud_enabled'  => false,
			'chain'          => array(),
			'configs'        => array(),
			'halted'         => array(),
			'usage'          => array(),
			'counts'         => array(),
		), $overrides );
	}

	/**
	 * 按 id 取卡（断言辅助）。
	 *
	 * @param array<int, array<string, mixed>> $cards 卡组。
	 * @param string                           $id    驱动 id。
	 * @return array<string, mixed>
	 */
	private static function card( array $cards, string $id ): array {
		foreach ( $cards as $c ) {
			if ( ( $c['driver_id'] ?? '' ) === $id ) {
				return $c;
			}
		}
		self::fail( "卡 {$id} 不存在" );
		return array();
	}

	/* ---------- 排班态推导矩阵（当班/待命/未配置） ---------- */

	public function test_captcha_duty_matrix(): void {
		$drivers = array(
			self::meta( 'tencent', '腾讯天御验证码', 'captcha', array( 'secret_id' ) ),
			self::meta( 'aliyun', '阿里云验证码 2.0', 'captcha', array( 'access_key_id' ) ),
		);

		// 当班：选中 + 必填齐；另一家配好未排班=待命。
		$cards = OverviewRoster::cards( $drivers, self::ctx( array(
			'captcha_driver' => 'tencent',
			'configs'        => array(
				'tencent' => array( 'secret_id' => 'abc' ),
				'aliyun'  => array( 'access_key_id' => 'x' ),
			),
		) ) );
		$this->assertSame( 'active', self::card( $cards, 'tencent' )['duty'] );
		$this->assertSame( 'standby', self::card( $cards, 'aliyun' )['duty'], '已配置未排班=待命' );

		// 未配置：必填缺（配置树空）。
		$cards = OverviewRoster::cards( $drivers, self::ctx( array( 'captcha_driver' => 'tencent' ) ) );
		$this->assertSame( 'unconfigured', self::card( $cards, 'tencent' )['duty'], '选中但必填缺=未配置（排班态优先于选中）' );

		// 未选中且必填缺 = 未配置（非待命）。
		$cards = OverviewRoster::cards( $drivers, self::ctx( array(
			'configs' => array( 'tencent' => array( 'secret_id' => 'abc' ) ),
		) ) );
		$this->assertSame( 'standby', self::card( $cards, 'tencent' )['duty'] );
		$this->assertSame( 'unconfigured', self::card( $cards, 'aliyun' )['duty'] );
	}

	public function test_review_duty_matrix(): void {
		$drivers = array(
			self::meta( 'tencent_tms', '腾讯 TMS 文本审核', 'review', array( 'secret_id' ) ),
			self::meta( 'baidu', '百度文本审核', 'review', array( 'api_key' ) ),
		);
		$ctx = array(
			'cloud_enabled' => true,
			'chain'         => array( 'tencent_tms' ),
			'configs'       => array(
				'tencent_tms' => array( 'secret_id' => 'a' ),
				'baidu'       => array( 'api_key' => 'b' ),
			),
		);

		$cards = OverviewRoster::cards( $drivers, self::ctx( $ctx ) );
		$this->assertSame( 'active', self::card( $cards, 'tencent_tms' )['duty'], '云审启用+在链+必填齐=当班' );
		$this->assertSame( 'standby', self::card( $cards, 'baidu' )['duty'], '配好未入链=待命' );

		// 云审总开关关：在链也不当班（待命——Bootstrap 不装配）。
		$ctx_off           = $ctx;
		$ctx_off['cloud_enabled'] = false;
		$cards = OverviewRoster::cards( $drivers, self::ctx( $ctx_off ) );
		$this->assertSame( 'standby', self::card( $cards, 'tencent_tms' )['duty'], '总开关关=待命（不当班）' );
	}

	/* ---------- 健康态推导（正常/故障/额度用完——DriverHealth state map 消费） ---------- */

	public function test_health_states_and_notes(): void {
		$drivers = array( self::meta( 'tencent_tms', '腾讯 TMS 文本审核', 'review', array( 'secret_id' ) ) );
		$base    = array(
			'cloud_enabled' => true,
			'chain'         => array( 'tencent_tms' ),
			'configs'       => array( 'tencent_tms' => array( 'secret_id' => 'a' ) ),
		);

		// 正常（无停转行=null——F8）。
		$card = self::card( OverviewRoster::cards( $drivers, self::ctx( $base ) ), 'tencent_tms' );
		$this->assertTrue( $card['healthy'] );
		$this->assertSame( '正常', $card['health_word'] );
		$this->assertSame( '', $card['halt_note'] );

		// 故障（config_error——密钥被拒）。
		$ctx           = $base;
		$ctx['halted'] = array( 'tencent_tms' => 'config_error' );
		$card          = self::card( OverviewRoster::cards( $drivers, self::ctx( $ctx ) ), 'tencent_tms' );
		$this->assertFalse( $card['healthy'] );
		$this->assertSame( '故障', $card['health_word'] );
		$this->assertStringContainsString( '密钥被拒', $card['halt_note'] );

		// 额度用完（quota_exhausted——次日自动恢复）。
		$ctx['halted'] = array( 'tencent_tms' => 'quota_exhausted' );
		$card          = self::card( OverviewRoster::cards( $drivers, self::ctx( $ctx ) ), 'tencent_tms' );
		$this->assertSame( '额度用完', $card['health_word'] );
		$this->assertStringContainsString( '次日自动恢复', $card['halt_note'] );
		$this->assertSame( 'active', $card['duty'], '残留停转态不盖排班态——当班为准' );

		// 状态码不出站长文案（反钉）。
		$this->assertStringNotContainsString( 'config_error', $card['health_word'] . $card['halt_note'] );
	}

	/* ---------- 微排序（异常置顶 + 组内稳定） ---------- */

	public function test_sort_anomaly_first_then_active_standby_unconfigured(): void {
		$drivers = array(
			self::meta( 'tencent', '腾讯天御验证码', 'captcha', array( 'secret_id' ) ),
			self::meta( 'aliyun', '阿里云验证码 2.0', 'captcha', array( 'access_key_id' ) ),
			self::meta( 'tencent_tms', '腾讯 TMS 文本审核', 'review', array( 'secret_id' ) ),
			self::meta( 'aliyun_green', '阿里云文本审核 PLUS', 'review', array( 'access_key_id' ) ),
			self::meta( 'baidu', '百度文本审核', 'review', array( 'api_key' ) ),
		);
		$ctx = array(
			'captcha_driver' => 'tencent',
			'cloud_enabled'  => true,
			'chain'          => array( 'tencent_tms' ),
			'configs'        => array(
				'tencent'      => array( 'secret_id' => 'a' ),
				'aliyun'       => array( 'access_key_id' => 'b' ),
				'tencent_tms'  => array( 'secret_id' => 'c' ),
				'aliyun_green' => array( 'access_key_id' => 'd' ),
			),
			'halted'         => array( 'baidu' => 'config_error' ), // 故障但未配置——异常置顶。
		);

		$order = array_map( static fn( array $c ): string => (string) $c['driver_id'], OverviewRoster::cards( $drivers, self::ctx( $ctx ) ) );

		// 异常卡最左；其后 当班（tencent→tencent_tms registry 序）→ 待命（aliyun→aliyun_green）→ 未配置。
		$this->assertSame( array( 'baidu', 'tencent', 'tencent_tms', 'aliyun', 'aliyun_green' ), $order );
	}

	/* ---------- 今日一句摘要 ---------- */

	public function test_captcha_summary_lines(): void {
		$drivers = array( self::meta( 'tencent', '腾讯天御验证码', 'captcha', array() ) );
		$base    = array(
			'captcha_driver' => 'tencent',
			'usage'          => array( 'tencent' => 12 ),
			'counts'         => array( 'tencent' => array( 'captcha_pass' => 10, 'captcha_reject' => 2 ) ),
		);

		// 拦下 N 次（captcha_reject 账户域净口径 + reject 评论域——F3/E-c）。
		$card = self::card( OverviewRoster::cards( $drivers, self::ctx( $base ) ), 'tencent' );
		$this->assertSame( '今日验 12 次 · 拦下 2 次', $card['summary'] );

		// 全部通过（零拦下零出错——推算口径，脚注另交代）。
		$ctx           = $base;
		$ctx['counts'] = array( 'tencent' => array( 'captcha_pass' => 12 ) );
		$card          = self::card( OverviewRoster::cards( $drivers, self::ctx( $ctx ) ), 'tencent' );
		$this->assertSame( '今日验 12 次 · 全部通过', $card['summary'] );

		// 零调用（Labels::zero_call_word 显式空态）。
		$ctx           = $base;
		$ctx['usage']  = array();
		$ctx['counts'] = array();
		$card          = self::card( OverviewRoster::cards( $drivers, self::ctx( $ctx ) ), 'tencent' );
		$this->assertSame( '今日没用到（0 次）', $card['summary'] );

		// 拦下+出错并存。
		$ctx           = $base;
		$ctx['counts'] = array( 'tencent' => array( 'captcha_reject' => 2, 'reject' => 1, 'error' => 1 ) );
		$card          = self::card( OverviewRoster::cards( $drivers, self::ctx( $ctx ) ), 'tencent' );
		$this->assertSame( '今日验 12 次 · 拦下 3 次 · 出错 1 次', $card['summary'] );
	}

	public function test_review_summary_lines(): void {
		$drivers = array( self::meta( 'tencent_tms', '腾讯 TMS 文本审核', 'review', array() ) );
		$base    = array(
			'cloud_enabled' => true,
			'chain'         => array( 'tencent_tms' ),
			'usage'         => array( 'tencent_tms' => 8 ),
			'counts'        => array( 'tencent_tms' => array( 'reject' => 2, 'review' => 1 ) ),
		);

		// 拦下 + 转人工。
		$card = self::card( OverviewRoster::cards( $drivers, self::ctx( $base ) ), 'tencent_tms' );
		$this->assertSame( '今日审 8 次 · 拦下 2 次 · 转人工 1 次', $card['summary'] );

		// 有调用零拦下 = 没有可疑内容。
		$ctx           = $base;
		$ctx['counts'] = array();
		$card          = self::card( OverviewRoster::cards( $drivers, self::ctx( $ctx ) ), 'tencent_tms' );
		$this->assertSame( '今日审 8 次 · 没有可疑内容', $card['summary'] );

		// 零调用零判定（云审 reject 必随调用发生——零调用即全零）。
		$ctx           = $base;
		$ctx['usage']  = array();
		$ctx['counts'] = array();
		$card          = self::card( OverviewRoster::cards( $drivers, self::ctx( $ctx ) ), 'tencent_tms' );
		$this->assertSame( '今日没用到（0 次）', $card['summary'] );

		// 出错段。
		$ctx           = $base;
		$ctx['counts'] = array( 'tencent_tms' => array( 'error' => 2 ) );
		$card          = self::card( OverviewRoster::cards( $drivers, self::ctx( $ctx ) ), 'tencent_tms' );
		$this->assertSame( '今日审 8 次 · 出错 2 次', $card['summary'] );
	}

	public function test_standby_and_unconfigured_summary(): void {
		$drivers = array(
			self::meta( 'aliyun', '阿里云验证码 2.0', 'captcha', array( 'access_key_id' ) ),
			self::meta( 'baidu', '百度文本审核', 'review', array( 'api_key' ) ),
		);
		$ctx = array(
			'configs' => array( 'aliyun' => array( 'access_key_id' => 'k' ) ),
			'usage'   => array( 'aliyun' => 0, 'baidu' => 3 ),
		);

		$cards = OverviewRoster::cards( $drivers, self::ctx( $ctx ) );
		$this->assertSame( '今日 0 次（未排班）', self::card( $cards, 'aliyun' )['summary'], '待命=未排班显式空态' );
		$this->assertSame( '', self::card( $cards, 'baidu' )['summary'], '未配置=无摘要（给去处不给数字）' );
	}

	/* ---------- 卡片静态面：角色/动作词/跳转 tab ---------- */

	public function test_card_static_fields(): void {
		$drivers = array(
			self::meta( 'tencent', '腾讯天御验证码', 'captcha', array() ),
			self::meta( 'tencent_tms', '腾讯 TMS 文本审核', 'review', array() ),
		);
		$ctx = array( 'captcha_driver' => 'tencent', 'cloud_enabled' => true, 'chain' => array( 'tencent_tms' ) );

		$cards = OverviewRoster::cards( $drivers, self::ctx( $ctx ) );
		$this->assertSame( '人机验证', self::card( $cards, 'tencent' )['role'] );
		$this->assertSame( '内容审核', self::card( $cards, 'tencent_tms' )['role'] );
		$this->assertSame( 'captcha', self::card( $cards, 'tencent' )['config_tab'] );
		$this->assertSame( 'review', self::card( $cards, 'tencent_tms' )['config_tab'] );
		$this->assertSame( '当班', self::card( $cards, 'tencent' )['duty_word'] );
	}

	/* ---------- 战报小票主行/副行 ---------- */

	public function test_ticket_main_lines(): void {
		$this->assertSame( '平静的一天', OverviewRoster::ticket_main( 0, 'comment' ), '零值显式空态' );
		$this->assertSame( '拦下 3 条', OverviewRoster::ticket_main( 3, 'comment' ), '评论量词=条' );
		$this->assertSame( '拦下 2 次', OverviewRoster::ticket_main( 2, 'login' ), '登录量词=次' );
		$this->assertSame( '拦下 1 次', OverviewRoster::ticket_main( 1, 'register' ) );
	}

	public function test_ticket_subline_top3_and_mapping(): void {
		$dist = array(
			'banned_word' => 2,
			'no_chinese'  => 1,
			'too_short'   => 4,
			'too_long'    => 9,
		);
		$line = OverviewRoster::ticket_subline( $dist );
		// 计数倒序取前 3：too_long 9 · too_short 4 · banned_word 2。
		$this->assertSame( '超字数上限 9 · 字数太少 4 · 关键词黑名单 2', $line );
		$this->assertStringNotContainsString( 'banned_word', $line, '规则键不外露' );

		$this->assertSame( '', OverviewRoster::ticket_subline( array() ), '空分布=空副行' );
	}

	/* ---------- 友好时间（今天/昨天/日期三档） ---------- */

	public function test_friendly_time(): void {
		$this->assertSame( '今天 09:12', OverviewRoster::friendly_time( '2026-11-12 09:12:30', '2026-11-12', '2026-11-11' ) );
		$this->assertSame( '昨天 21:04', OverviewRoster::friendly_time( '2026-11-11 21:04:00', '2026-11-12', '2026-11-11' ) );
		$this->assertSame( '11月8日 03:00', OverviewRoster::friendly_time( '2026-11-08 03:00:00', '2026-11-12', '2026-11-11' ), '更早=日期档（月日去前导零）' );
		$this->assertSame( '—', OverviewRoster::friendly_time( '', '2026-11-12', '2026-11-11' ), '空时间安全回落' );
	}

	/* ---------- 岔子行组装（批 2 fault_timeline_rows 输出消费） ---------- */

	/**
	 * fault_timeline_rows 输出形态夹具（InterceptDigest 已钉测的键集）。
	 *
	 * @param string $created_at 时间。
	 * @param string $driver_label 服务商名。
	 * @param string $rule_word 岔子人话。
	 * @param string $handling 处理话。
	 * @return array<string, mixed>
	 */
	private static function tl_row( string $created_at, string $driver_label, string $rule_word, string $handling ): array {
		return array(
			'id'           => 1,
			'event_no'     => 'WK-20261112-0001',
			'created_at'   => $created_at,
			'gate'         => 'system',
			'gate_word'    => '服务商健康',
			'stage'        => 'health',
			'driver'       => 'tencent_tms',
			'driver_label' => $driver_label,
			'verdict'      => 'error',
			'rule_hit'     => 'driver_halted:quota_exhausted',
			'rule_word'    => $rule_word,
			'handling'     => $handling,
			'raw'          => array(),
		);
	}

	public function test_incident_lines_limit_and_composition(): void {
		$rows = array(
			self::tl_row( '2026-11-12 09:12:00', '腾讯 TMS 文本审核', '当日额度用完', '今日暂停，防线跳过它继续' ),
			self::tl_row( '2026-11-11 21:04:00', '百度文本审核', '服务商恢复正常', '服务商恢复正常' ),
			self::tl_row( '2026-11-10 08:00:00', '百度文本审核', '网络连不上', '已按兜底策略处理' ),
			self::tl_row( '2026-11-09 08:00:00', '百度文本审核', '网络连不上', '已按兜底策略处理' ),
			self::tl_row( '2026-11-08 08:00:00', '百度文本审核', '网络连不上', '已按兜底策略处理' ),
			self::tl_row( '2026-11-07 08:00:00', '百度文本审核', '网络连不上', '已按兜底策略处理' ),
			self::tl_row( '2026-11-06 08:00:00', '百度文本审核', '网络连不上', '已按兜底策略处理' ),
		);

		$lines = OverviewRoster::incident_lines( $rows, '2026-11-12', '2026-11-11', 5 );
		$this->assertCount( 5, $lines, '入口性质只留 5 条（明细归 Tab⑥）' );

		// 行 1：停转——岔子+处理两句拼接。
		$this->assertSame( '今天 09:12', $lines[0]['time_word'] );
		$this->assertSame( '腾讯 TMS 文本审核', $lines[0]['driver'] );
		$this->assertSame( '当日额度用完；今日暂停，防线跳过它继续', $lines[0]['text'] );

		// 行 2：解除——handling 与 rule_word 同语不重复拼接。
		$this->assertSame( '昨天 21:04', $lines[1]['time_word'] );
		$this->assertSame( '服务商恢复正常', $lines[1]['text'] );

		// 行 3：更早=日期档。
		$this->assertSame( '11月10日 08:00', $lines[2]['time_word'] );

		// LIMIT 钳界（0/负数=1，超大=行数上限）。
		$this->assertCount( 1, OverviewRoster::incident_lines( $rows, '2026-11-12', '2026-11-11', 0 ) );
		$this->assertCount( 7, OverviewRoster::incident_lines( $rows, '2026-11-12', '2026-11-11', 50 ), '钳上界 10——7 行全出' );
	}

	public function test_incident_lines_defensive_on_malformed_rows(): void {
		$lines = OverviewRoster::incident_lines( array( array( 'id' => 1 ), 'junk', null ), '2026-11-12', '2026-11-11', 5 );
		$this->assertCount( 1, $lines, '非数组行跳过、缺键行安全回落' );
		$this->assertSame( '—', $lines[0]['time_word'] );
		$this->assertSame( '', $lines[0]['driver'] );
	}
}
