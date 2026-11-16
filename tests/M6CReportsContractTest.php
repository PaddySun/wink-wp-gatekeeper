<?php
/**
 * M6 批 C · 报告迁移与样式重构契约测试（0.6.5，2026-11-16——施工图 §3b C-3）。
 *
 * 骨架契约面（源码契约测试；行为面由 B 机真机承载）：
 *   ① Tab⑥ 存量审核报告卡退役（零残留反钉）——报告迁 Tab⑦ 对应操作卡下方
 *     （用户原话「⑥ 统计页面中展示的消息已经够杂乱」）；
 *   ② Tab⑦ 内容体检报告=wk-overview-report 同款形态（服务商+起止时间+口径三行
 *     没问题/待审核/垃圾箱+原因明细 details 折叠）；
 *   ③ 存量网址核查报告新增（同款式+队列残留数+运行记录 option）；
 *   ④ 点击反馈：入队后立即呈现「已入队 N 条·开始核查」；
 *   ⑤ 两处「统计页存量审核报告」文案改指 Tab⑦ 工具页；处置人话文案（可手动恢复）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;

final class M6CReportsContractTest extends TestCase {

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
	 * ① Tab⑥ 报告卡退役（零残留反钉）
	 * =================================================================== */

	public function test_tab6_existing_audit_report_card_retired_zero_residue(): void {
		$sp = self::source( 'includes/settings/SettingsPage.php' );
		self::assertStringNotContainsString( 'wk-stats-existing-audit', $sp, 'Tab⑥ 报告卡锚零残留（整卡退役）' );
		self::assertStringNotContainsString( 'render_stats_existing_audit_card', $sp, '旧报告卡渲染函数退役' );

		$stats_body = self::functionBody( $sp, 'render_tab_stats' );
		self::assertStringNotContainsString( '存量审核报告', $stats_body, 'render_tab_stats 编排零存量审核报告卡' );
	}

	public function test_tab6_running_bar_kept_and_repointed_to_tools(): void {
		// 「进行中」黄条保留（Tab① 通栏——有话才出现）；「看报告 →」随报告迁移改指 Tab⑦。
		$alerts = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_overview_alerts' );
		self::assertStringContainsString( 'wk-overview-existing-bar', $alerts, '运行中黄条保留' );
		self::assertStringContainsString( "tab_url( 'tools' )", $alerts, '看报告链接改指 Tab⑦（报告新址）' );
	}

	/* ===================================================================
	 * ② Tab⑦ 内容体检报告（wk-overview-report 同款）
	 * =================================================================== */

	public function test_tab7_existing_audit_report_below_op_card(): void {
		$sp    = self::source( 'includes/settings/SettingsPage.php' );
		$tools = self::functionBody( $sp, 'render_tab_tools' );
		foreach ( array(
			'wk-tools-existing-audit-report',  // 报告卡锚。
			'wk-report-tickets',               // wk-overview-report 同款票面形态。
			'wk-tools-existing-audit-reasons', // 原因明细 details 折叠锚。
			'没问题',                          // 口径三行之一。
			'待审核',                          // 口径三行之二。
			'垃圾箱',                          // 口径三行之三。
			'服务商',                          // 报告头部=服务商。
			'发起',                            // 发起时间。
			'完成',                            // 完成时间。
		) as $anchor ) {
			self::assertStringContainsString( $anchor, $tools, "Tab⑦ 报告含 {$anchor}" );
		}
	}

	public function test_tab7_reports_show_recovery_wording(): void {
		// C-2 红线人话：判违规进垃圾箱、没看清进待审，都能手动恢复（两操作卡文案）。
		$tools = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_tab_tools' );
		self::assertStringContainsString( '都能手动恢复', $tools, '可逆面人话在场' );
		self::assertStringContainsString( '进垃圾箱', $tools, 'reject 去向人话' );
		self::assertStringContainsString( '进待审', $tools, 'review/inconclusive 去向人话' );
	}

	public function test_tab7_reports_link_to_moderation_queues(): void {
		// 用户口径原文：进入 edit-comments.php?comment_status=moderated / spam 处理。
		$tools = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_tab_tools' );
		self::assertStringContainsString( 'comment_status=moderated', $tools, '待审队列深链' );
		self::assertStringContainsString( 'comment_status=spam', $tools, '垃圾箱深链' );
	}

	/* ===================================================================
	 * ③ 存量网址核查报告（同款式+队列残留数）
	 * =================================================================== */

	public function test_tab7_url_audit_report_card(): void {
		$sp    = self::source( 'includes/settings/SettingsPage.php' );
		$tools = self::functionBody( $sp, 'render_tab_tools' );
		foreach ( array(
			'wk-tools-url-audit-report', // 报告卡锚。
			'wk-report-tickets',         // 同款票面。
			'残留',                      // 队列残留数行。
		) as $anchor ) {
			self::assertStringContainsString( $anchor, $tools, "Tab⑦ 网址核查报告含 {$anchor}" );
		}
	}

	public function test_backlog_record_option_exists_and_uninstalled(): void {
		$worker_src = self::source( 'includes/urlaudit/UrlAuditWorker.php' );
		self::assertStringContainsString( "OPTION_BACKLOG_RECORD = 'wink_gatekeeper_url_audit_backlog_record'", $worker_src, '运行记录 option 常量在场' );
		self::assertStringContainsString( 'function backlog_report_view', $worker_src, '报告视图纯函数在场（三态渲染契约的计算面）' );
		self::assertStringContainsString( 'function backlog_report_data', $worker_src, 'WP 边界反查方法在场' );

		$uninstall = self::source( 'uninstall.php' );
		self::assertStringContainsString( 'wink_gatekeeper_url_audit_backlog_record', $uninstall, '卸载清单随 option 走' );
	}

	public function test_backlog_report_view_consumed_by_renderer(): void {
		$tools = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_tab_tools' );
		self::assertStringContainsString( 'backlog_report_data', $tools, 'Tab⑦ 渲染消费报告视图（meta 反查最小方案）' );
	}

	/* ===================================================================
	 * ④ 点击反馈
	 * =================================================================== */

	public function test_backlog_click_feedback_line(): void {
		$sp = self::source( 'includes/settings/SettingsPage.php' );
		$handler = self::functionBody( $sp, 'handle_url_audit_backlog' );
		self::assertStringContainsString( 'wk-url-backlog', $handler, '入队后回跳携带已入队数' );

		$tools = self::functionBody( $sp, 'render_tab_tools' );
		self::assertStringContainsString( 'wk-tools-url-audit-feedback', $tools, '点击反馈行锚' );
		self::assertStringContainsString( '已入队', $tools, '「已入队 N 条·开始核查」人话行' );
		self::assertStringContainsString( '开始核查', $tools, '反馈行含开始核查' );
	}

	/* ===================================================================
	 * ⑤ 文案改指 Tab⑦
	 * =================================================================== */

	public function test_copy_points_to_tools_not_stats(): void {
		$sp = self::source( 'includes/settings/SettingsPage.php' );
		self::assertStringNotContainsString( '统计页存量审核报告', $sp, 'Tab③ inert notice 文案改指工具页' );
		self::assertStringNotContainsString( '统计页的存量审核报告', $sp, 'Tab⑧ A 关闭提示文案改指工具页' );
		self::assertSame( 2, substr_count( (string) $sp, '存量网址仍可在工具页' ), '两处提示（Tab③+Tab⑧）均改指工具页存量网址核查' );
	}

	public function test_old_stats_report_links_retired(): void {
		$tools = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_tab_tools' );
		self::assertStringNotContainsString( 'wk-tools-audit-report-link', $tools, '「查看体检报告→统计页」链接退役（报告就在下方）' );
		self::assertStringNotContainsString( 'wk-tools-url-audit-report-link', $tools, '「查看网址核查报告→统计页」链接退役' );
		self::assertStringNotContainsString( '查看体检报告', $tools, '旧链接文案零残留' );
	}
}
