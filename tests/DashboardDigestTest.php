<?php
/**
 * DashboardDigest 仪表盘卡片视图模型单元测试（O3 §七 步骤 1——三视图分级之视图①）。
 *
 * 规格（O3-统计卡片设计建议.md §二/§三/§七）：
 *   - 不变式：视图①③零 EventsTable 明细查询——widget_overview 只吃 option 树（StatsCache/
 *     Usage/DriverHealth）+ 设置树 + 可注入布尔（验证码配置态/cron 存活），输出三卡视图模型；
 *   - 卡 1 系统健康：四闸状态点 + 验证码配置态 + 驱动当日停转红警报 + cron 存活；
 *   - 卡 2 调用量：各驱动今日行 + 配额「N / M」达限标记 + 站点今日总外呼 + 容灾放行行；
 *   - 卡 3 概览迷你条：今日拦截/待审/驱动错误三格求和（by_gate 全闸遍历）；
 *   - 全可注入形态：缺键安全回落（空态），零 WP 依赖。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Settings\SettingsSchema;
use WinkGatekeeper\Stats\DashboardDigest;
use WinkGatekeeper\Stats\DriverHealth;
use WinkGatekeeper\Stats\StatsCache;
use WinkGatekeeper\Stats\Usage;

final class DashboardDigestTest extends TestCase {

	private const YMD       = '20261112';
	private const YMD_DASH  = '2026-11-12';

	/**
	 * 组装输入（默认全绿/空态基线；逐测覆盖注入值）。
	 *
	 * @param array<string, mixed> $overrides 覆盖键。
	 * @return array<string, mixed>
	 */
	private function args( array $overrides = array() ): array {
		$base = array(
			'settings'          => SettingsSchema::defaults(),
			'stats'             => StatsCache::seed(),
			'usage'             => Usage::seed(),
			'health'            => DriverHealth::seed(),
			'today_ymd'         => self::YMD,
			'today_dash'        => self::YMD_DASH,
			'captcha_configured' => true,
			'captcha_driver'    => 'tencent',
			'captcha_driver_label' => '腾讯天御验证码 2.0',
			'driver_labels'     => array(),
			'cron_alive'        => true,
			'debug'             => false,
		);
		return array_merge( $base, $overrides );
	}

	/* ================= 卡 1：系统健康 ================= */

	public function test_health_card_lists_four_gate_states(): void {
		$out = DashboardDigest::widget_overview( $this->args() );

		$gates = $out['health']['gates'];
		$this->assertCount( 4, $gates, '四闸状态点' );
		$this->assertSame( array( 'comment', 'login', 'register', 'lostpassword' ), array_column( $gates, 'gate' ) );
		$this->assertSame( '评论门', $gates[0]['label'] );
		$this->assertSame( '登录门', $gates[1]['label'] );
		$this->assertTrue( $gates[0]['enabled'], '默认设置树四闸全启用' );
		$this->assertTrue( $gates[3]['enabled'] );
	}

	public function test_health_card_reflects_disabled_gate(): void {
		$settings                       = SettingsSchema::defaults();
		$settings['gates']['login']['enabled'] = false;

		$out = DashboardDigest::widget_overview( $this->args( array( 'settings' => $settings ) ) );

		$login = null;
		foreach ( $out['health']['gates'] as $gate ) {
			if ( 'login' === $gate['gate'] ) {
				$login = $gate;
			}
		}
		$this->assertNotNull( $login );
		$this->assertFalse( $login['enabled'], '关闭闸=灰点（enabled=false 透传）' );
	}

	public function test_health_card_captcha_configured_green(): void {
		$out = DashboardDigest::widget_overview( $this->args() );
		$this->assertTrue( $out['health']['captcha']['configured'] );
		$this->assertSame( 'tencent', $out['health']['captcha']['driver_id'] );
		$this->assertSame( '腾讯天御验证码 2.0', $out['health']['captcha']['label'] );
	}

	public function test_health_card_captcha_unconfigured_yellow_state(): void {
		$out = DashboardDigest::widget_overview( $this->args( array( 'captcha_configured' => false ) ) );
		$this->assertFalse( $out['health']['captcha']['configured'], '未配置=黄态（闸开着但验证码阶段整体跳过）' );
	}

	public function test_health_card_halt_alerts_from_health_tree(): void {
		$health = DriverHealth::seed();
		$health = DriverHealth::mark( $health, self::YMD_DASH, 'tencent_tms', DriverHealth::STATE_CONFIG_ERROR );
		$health = DriverHealth::mark( $health, self::YMD_DASH, 'tencent', DriverHealth::STATE_QUOTA_EXHAUSTED );

		$out = DashboardDigest::widget_overview( $this->args( array( 'health' => $health ) ) );

		$halts = $out['health']['halts'];
		$this->assertCount( 2, $halts, '当日停转驱动=红警报行' );
		$this->assertSame( 'tencent_tms', $halts[0]['driver_id'] );
		$this->assertSame( DriverHealth::STATE_CONFIG_ERROR, $halts[0]['state'] );
		$this->assertSame( '配置有问题', $halts[0]['note'], '停转原因走 Labels 人话（状态码不进站长文案）' );
		$this->assertSame( '额度用完', $halts[1]['note'] );
		$this->assertStringContainsString( '密钥', $halts[0]['detail'], 'config_error 一句话原因含密钥提示' );
	}

	public function test_health_card_halt_stale_date_is_empty(): void {
		// 跨日翻转：健康树还是昨天日期=次日自动恢复语义，无红警报。
		$health = DriverHealth::seed();
		$health = DriverHealth::mark( $health, '2026-11-11', 'tencent_tms', DriverHealth::STATE_CONFIG_ERROR );

		$out = DashboardDigest::widget_overview( $this->args( array( 'health' => $health ) ) );
		$this->assertSame( array(), $out['health']['halts'], '昨日停转=今日已恢复（日翻转语义）' );
	}

	public function test_health_card_cron_alive_flag(): void {
		$out = DashboardDigest::widget_overview( $this->args( array( 'cron_alive' => false ) ) );
		$this->assertFalse( $out['health']['cron_alive'], 'cron 丢失=红点态透传（渲染层出重新激活链接）' );

		$out = DashboardDigest::widget_overview( $this->args( array( 'cron_alive' => true ) ) );
		$this->assertTrue( $out['health']['cron_alive'] );
	}

	/* ================= 卡 2：第三方调用量 ================= */

	public function test_usage_rows_per_driver_with_quota(): void {
		$usage = Usage::seed();
		$usage = Usage::bump( $usage, self::YMD_DASH, 'tencent' );
		for ( $i = 0; $i < 127; $i++ ) {
			$usage = Usage::bump( $usage, self::YMD_DASH, 'tencent' );
		}
		$usage = Usage::bump( $usage, self::YMD_DASH, 'tencent_tms' );

		$settings                              = SettingsSchema::defaults();
		$settings['review']['cloud']['tencent_tms']['daily_quota'] = 500;

		$out = DashboardDigest::widget_overview( $this->args( array(
			'usage'         => $usage,
			'settings'      => $settings,
			'driver_labels' => array( 'tencent' => '腾讯天御验证码 2.0', 'tencent_tms' => '腾讯云文本审核' ),
		) ) );

		$rows = $out['usage']['rows'];
		$this->assertCount( 2, $rows );
		$this->assertSame( 'tencent', $rows[0]['driver_id'] );
		$this->assertSame( 128, $rows[0]['used'] );
		$this->assertSame( 0, $rows[0]['quota'], '验证码驱动无日配额=0（不渲染 N / M 行）' );
		$this->assertFalse( $rows[0]['quota_reached'] );
		$this->assertSame( '腾讯天御验证码 2.0', $rows[0]['label'] );
		$this->assertSame( 'tencent_tms', $rows[1]['driver_id'] );
		$this->assertSame( 1, $rows[1]['used'] );
		$this->assertSame( 500, $rows[1]['quota'] );
		$this->assertFalse( $rows[1]['quota_reached'], '未达限' );
	}

	public function test_usage_quota_reached_red_flag(): void {
		$usage = Usage::seed();
		for ( $i = 0; $i < 500; $i++ ) {
			$usage = Usage::bump( $usage, self::YMD_DASH, 'tencent_tms' );
		}

		$settings                              = SettingsSchema::defaults();
		$settings['review']['cloud']['tencent_tms']['daily_quota'] = 500;

		$out = DashboardDigest::widget_overview( $this->args( array( 'usage' => $usage, 'settings' => $settings ) ) );

		$this->assertTrue( $out['usage']['rows'][0]['quota_reached'], '达限=红态（与 DriverHealth quota_exhausted 联动说明）' );
	}

	public function test_usage_site_total_line(): void {
		$usage = Usage::seed();
		$usage = Usage::bump( $usage, self::YMD_DASH, 'tencent' );
		$usage = Usage::bump( $usage, self::YMD_DASH, 'tencent' );
		$usage = Usage::bump( $usage, self::YMD_DASH, 'tencent_tms' );

		$out = DashboardDigest::widget_overview( $this->args( array( 'usage' => $usage ) ) );
		$this->assertSame( 3, $out['usage']['site_total'], '站点今日总外呼=Usage::daily_total 口径' );
	}

	public function test_usage_disaster_pass_counter_key(): void {
		// 容灾放行计数键（O3 §三卡 2：StatsCache 特例键 disaster_pass——卡片读 option 非 COUNT 明细）。
		$stats = StatsCache::seed();
		$stats = StatsCache::incr( $stats, self::YMD, 'comment', 'tencent', 'disaster_pass' );
		$stats = StatsCache::incr( $stats, self::YMD, 'comment', 'tencent', 'disaster_pass' );

		$out = DashboardDigest::widget_overview( $this->args( array( 'stats' => $stats ) ) );
		$this->assertSame( 2, $out['usage']['disaster_pass'], '今日容灾放行 N 次——读 by_gate.comment.disaster_pass 今日键' );
	}

	public function test_usage_empty_state(): void {
		$out = DashboardDigest::widget_overview( $this->args() );
		$this->assertSame( array(), $out['usage']['rows'], '空态：无驱动行' );
		$this->assertSame( 0, $out['usage']['site_total'] );
		$this->assertSame( 0, $out['usage']['disaster_pass'] );
	}

	public function test_usage_yesterday_usage_not_counted(): void {
		// 跨日翻转：usage 树还是昨日=不计数（Usage bump 翻转由写入侧负责——读取侧按现有树直读）。
		$usage = Usage::seed();
		$usage = Usage::bump( $usage, '2026-11-11', 'tencent' );

		$out = DashboardDigest::widget_overview( $this->args( array( 'usage' => $usage ) ) );
		// Usage 树无跨日翻转读取逻辑——树内 date 键与今日不一致时视作空态（不虚报昨日数）。
		$this->assertSame( array(), $out['usage']['rows'], '昨日 usage 树=今日空态（不虚报）' );
		$this->assertSame( 0, $out['usage']['site_total'] );
	}

	/* ================= 卡 3：今日拦截概览迷你条 ================= */

	public function test_overview_sums_across_gates_today(): void {
		$stats = StatsCache::seed();
		$stats = StatsCache::incr( $stats, self::YMD, 'comment', 'local_rules', 'reject' );
		$stats = StatsCache::incr( $stats, self::YMD, 'comment', 'local_rules', 'reject' );
		$stats = StatsCache::incr( $stats, self::YMD, 'comment', 'local_rules', 'review' );
		$stats = StatsCache::incr( $stats, self::YMD, 'comment', 'tencent', 'error' );
		$stats = StatsCache::incr( $stats, self::YMD, 'login', 'tencent', 'reject' );

		$out = DashboardDigest::widget_overview( $this->args( array( 'stats' => $stats ) ) );

		$this->assertSame( 3, $out['overview']['reject'], '拦截=全闸 reject 求和（by_gate 今日树遍历）' );
		$this->assertSame( 1, $out['overview']['review'], '待审=全闸 review 求和' );
		$this->assertSame( 1, $out['overview']['error'], '驱动错误=全闸 error 求和' );
	}

	public function test_overview_empty_state_all_zero(): void {
		$out = DashboardDigest::widget_overview( $this->args() );
		$this->assertSame( 0, $out['overview']['reject'] );
		$this->assertSame( 0, $out['overview']['review'] );
		$this->assertSame( 0, $out['overview']['error'] );
	}

	public function test_overview_yesterday_stats_not_counted(): void {
		// 今日树只有昨日数据（尚未发生今日 incr 翻转）——概览条不虚报昨日拦截。
		$stats = StatsCache::seed();
		$stats = StatsCache::incr( $stats, '20261111', 'comment', 'local_rules', 'reject' );

		$out = DashboardDigest::widget_overview( $this->args( array( 'stats' => $stats ) ) );
		$this->assertSame( 0, $out['overview']['reject'], '今日树日期 != 今日 → 视作空态（跨日翻转由写入侧完成）' );
	}

	/* ================= 调试行 + 形态防御 ================= */

	public function test_debug_note_shows_schema_version_only_in_debug(): void {
		$out = DashboardDigest::widget_overview( $this->args() );
		$this->assertSame( '', $out['debug_note'], '非 debug=空（仿 Wordfence Generated in 只在 WP_DEBUG 显示）' );

		$out = DashboardDigest::widget_overview( $this->args( array( 'debug' => true ) ) );
		$this->assertStringContainsString( (string) SettingsSchema::SCHEMA_VERSION, $out['debug_note'] );
	}

	public function test_malformed_trees_fall_back_safe(): void {
		// 异形输入（option 损坏/部分缺键）——防御性回落空态，不抛异常不虚构数字。
		$out = DashboardDigest::widget_overview( $this->args( array(
			'stats'  => array( 'garbage' => true ),
			'usage'  => 'not-an-array',
			'health' => null,
		) ) );

		$this->assertSame( 0, $out['overview']['reject'] );
		$this->assertSame( 0, $out['usage']['site_total'] );
		$this->assertSame( array(), $out['health']['halts'] );
		$this->assertCount( 4, $out['health']['gates'], '设置树损坏时四闸点仍按默认树渲染' );
	}

	public function test_view_model_has_three_card_keys(): void {
		$out = DashboardDigest::widget_overview( $this->args() );
		$this->assertSame( array( 'health', 'usage', 'overview', 'debug_note' ), array_keys( $out ), '视图模型=三卡+调试行' );
	}
}
