<?php
/**
 * M5 UI-D 批 · Tab③ 网址核查配置区+Tab⑥ 统计行骨架契约测试（2026-11-14，U5）。
 *
 * 骨架契约面（源码契约测试；行为面由 B 机真机承载）：
 *   ① Tab③「网址核查」分区（UI 块 2-B 落点裁决）——启用/三档 profile（0.95 门禁不露旋钮）/
 *      博查开关/高级折叠（双车道额度/robots/清缓存）/inert notice 条件显隐（site_field 关）；
 *   ② Tab⑥ 网址核查统计行（K8/K9/K10 口径锚）+报告卡 host 聚合节（两账分离延续）；
 *   ③ Bootstrap 装配——comment_post 入队/双车道 cron/清缓存 admin action 双闸；
 *   ④ 缓存键前缀+博查计量键注册。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;

final class UiM5Tab3UrlAuditContractTest extends TestCase {

	private static function source( string $rel ): string {
		$src = file_get_contents( __DIR__ . '/../' . $rel );
		self::assertIsString( $src, "{$rel} 必须可读" );
		return (string) $src;
	}

	private static function functionBody( string $src, string $name ): string {
		$start = strpos( $src, 'function ' . $name );
		self::assertNotFalse( $start, "函数 {$name} 必须存在" );
		$open  = strpos( $src, '{', $start );
		$depth = 0;
		$len   = strlen( $src );
		for ( $i = $open; $i < $len; $i++ ) {
			if ( '{' === $src[ $i ] ) {
				$depth++;
			} elseif ( '}' === $src[ $i ] ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $src, $open, $i - $open + 1 );
				}
			}
		}
		self::fail( "函数 {$name} 花括号不配平" );
		return '';
	}

	/* ===================================================================
	 * ① Tab③ 网址核查分区
	 * =================================================================== */

	public function test_url_audit_card_anchors(): void {
		$src = self::source( 'includes/settings/SettingsPage.php' );
		foreach ( array( 'wk-review-url-audit-card', 'wk-url-audit-profile-group', 'wk-url-audit-bocha-row', 'wk-url-audit-advanced', 'wk-url-audit-inert-notice' ) as $anchor ) {
			self::assertStringContainsString( $anchor, $src, "锚 {$anchor}" );
		}
	}

	public function test_card_default_off_and_zero_change_note(): void {
		$card = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_url_audit_card' );
		self::assertStringContainsString( "array( 'url_audit', 'enabled' )", $card, '启用开关绑定' );
		self::assertStringContainsString( '零行为变化', $card, '关闭=零行为变化说明（增强层非依赖层）' );
		self::assertSame( 1, substr_count( $card, '零行为变化' ), '段级一次' );
	}

	public function test_profile_three_tiers_no_knob_for_095(): void {
		$card = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_url_audit_card' );
		foreach ( array( 'conservative', 'balanced', 'aggressive' ) as $tier ) {
			self::assertStringContainsString( $tier, $card, "三档 profile 含 {$tier}" );
		}
		self::assertStringContainsString( '系统掌握', $card, '0.95 门禁不露旋钮——一句系统掌握说明（D8）' );
		self::assertDoesNotMatchRegularExpression( '/name="[^"]*0\.95[^"]*"/', $card, '0.95 不作为可提交控件值' );
	}

	public function test_advanced_fold_contains_quotas_and_robots(): void {
		$src   = self::source( 'includes/settings/SettingsPage.php' );
		$block = self::blockBetween( $src, 'wk-url-audit-advanced', 'wk-url-audit-inert-notice' );
		self::assertStringContainsString( "array( 'url_audit', 'batch_quota' )", $block, '批量车道日额度（高级折叠内）' );
		self::assertStringContainsString( "array( 'url_audit', 'incr_quota' )", $block, '新评论车道日额度' );
		self::assertStringContainsString( "array( 'url_audit', 'robots_comply' )", $block, 'robots 开关' );
		self::assertStringContainsString( 'UrlAuditWorker::ACTION_PURGE', $block, '清缓存 admin action' );
	}

	public function test_inert_notice_gated_on_site_field(): void {
		// 增量面失效提示：Tab⑧「收集网站字段」关（=未收集）时显示——与 Tab⑧ 卡内提示
		// 文案不同语境不同（两页两语境各一次）。
		$card = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_url_audit_card' );
		self::assertStringContainsString( 'site_field', $card, '门控 site_field 键' );
		self::assertStringContainsString( '网址', $card, '提示指向本功能' );
	}

	public function test_purge_action_double_gated(): void {
		$handler = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'handle_url_audit_purge' );
		self::assertStringContainsString( 'check_admin_referer', $handler, 'nonce 双闸' );
		self::assertStringContainsString( 'manage_options', $handler, 'manage_options 双闸' );
	}

	/* ===================================================================
	 * ② Tab⑥ 统计行+报告 host 聚合
	 * =================================================================== */

	public function test_tab6_url_stats_line(): void {
		$stats = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_stats_comment_section' );
		self::assertStringContainsString( 'wk-stats-url-line', $stats, '网址核查统计行锚（UI 块 3.3）' );
		self::assertStringContainsString( '撤销', $stats, '自动标垃圾可撤销（机器处置可逆信任基础）' );
	}

	public function test_report_host_aggregate_section(): void {
		// 【断言翻转·M6 批C·C-3】原钉=按网站归堆表在 Tab⑥ 报告卡
		// （render_stats_existing_audit_card）内。翻钉原因=用户 2026-11-16 反馈（施工图
		// §3b C-3）：Tab⑥ 报告卡退役迁 Tab⑦——host 表随报告迁入 Tab⑦ 网址核查报告卡
		// （details 折叠；「重新核查此站」站长举报误判入口保留，nw §4.3）。
		$tools = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_tab_tools' );
		self::assertStringContainsString( 'wk-tools-url-audit-hosts', $tools, '按网站归堆表锚（随报告迁 Tab⑦）' );
		self::assertStringContainsString( '重新核查此站', $tools, 'host 级清缓存站长入口（nw §4.3）' );
	}

	/* ===================================================================
	 * ③ Bootstrap 装配
	 * =================================================================== */

	public function test_bootstrap_wiring(): void {
		$src = self::source( 'includes/Bootstrap.php' );
		self::assertStringContainsString( 'UrlAuditWorker::HOOK_TICK', $src, '双车道 cron tick' );
		self::assertStringContainsString( 'UrlAuditWorker::ACTION_PURGE', $src, '清缓存 admin action 注册' );
		self::assertStringContainsString( 'enqueue_url_audit_job', $src, 'comment_post 增量入队' );
		self::assertStringContainsString( 'bocha_search', self::source( 'includes/urlaudit/BochaSearch.php' ), '博查计量键登记（K3——Usage 键单一来源）' );
	}

	public function test_worker_lanes_and_cache_prefix(): void {
		$worker = self::source( 'includes/urlaudit/UrlAuditWorker.php' );
		self::assertStringContainsString( 'url_audit_batch', $worker, '批量车道 caller（K2）' );
		self::assertStringContainsString( 'url_audit_incr', $worker, '增量车道 caller' );
		$cache = self::source( 'includes/urlaudit/UrlAuditCache.php' );
		self::assertStringContainsString( 'wk_ua_', $cache, '缓存 transient 前缀（卸载清单同步面）' );
	}

	private static function blockBetween( string $src, string $startMarker, string $endMarker ): string {
		$start = strpos( $src, $startMarker );
		self::assertNotFalse( $start, "HTML 须含 {$startMarker}" );
		$end = strpos( $src, $endMarker, $start + strlen( $startMarker ) );
		self::assertNotFalse( $end, "须在 {$startMarker} 之后含 {$endMarker}" );
		return substr( $src, $start, $end - $start );
	}
}
