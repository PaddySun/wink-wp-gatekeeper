<?php
/**
 * M5 UI-C 批 · 存量审核操作面骨架契约测试（2026-11-14，U3）。
 *
 * 骨架契约面（源码契约测试；行为面由 B 机真机承载）：
 *   ① D6 入口分工红线——Tab⑦ 有发起钮、Tab⑥ 报告卡**无发起钮**（防重复教训钉死）；
 *   ② Tab⑦ 操作卡 DOM 锚（wk-tools-existing-audit-card/audit-start-btn/audit-state-line/
 *      audit-report-link）+admin_action 四操作（start/pause/resume/reset）双闸；
 *   ③ Tab⑥ 报告卡（wk-stats-existing-audit）进度行/判违规与未核实两账分离/仅标记无删除；
 *   ④ Tab① A-U3 黄条（运行中通栏，有话才出现）；
 *   ⑤ CLI 命令注册（wp wink-gatekeeper audit-existing）+cron tick 装配。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Settings\SettingsPage;

final class UiM5Tab7AuditContractTest extends TestCase {

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
	 * ① D6 入口分工红线
	 * =================================================================== */

	public function test_tab7_has_start_button_and_tab6_report_has_none(): void {
		$tools = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_tab_tools' );
		self::assertStringContainsString( 'wk-tools-audit-start-btn', $tools, 'Tab⑦ 有发起钮（D6）' );

		$stats = self::source( 'includes/settings/SettingsPage.php' );
		self::assertStringContainsString( 'wk-stats-existing-audit', $stats, 'Tab⑥ 有报告卡' );
		self::assertStringNotContainsString( 'wk-tools-audit-start-btn', preg_replace( '/function render_tab_tools[\s\S]*?^\t\}/m', '', $stats ) ?? '', '发起钮 id 不得出现在 Tab⑦ 之外' );
	}

	/* ===================================================================
	 * ② Tab⑦ 操作卡
	 * =================================================================== */

	public function test_tab7_card_anchors(): void {
		$tools = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_tab_tools' );
		foreach ( array( 'wk-tools-existing-audit-card', 'wk-tools-audit-state-line', 'wk-tools-audit-report-link' ) as $anchor ) {
			self::assertStringContainsString( $anchor, $tools, "Tab⑦ 锚 {$anchor}" );
		}
		self::assertStringContainsString( '昵称+正文', $tools, '送审串红线注：邮箱与网址不进送审内容' );
	}

	public function test_tab7_admin_actions_double_gated(): void {
		$sp = self::source( 'includes/settings/SettingsPage.php' );
		foreach ( array( 'start', 'pause', 'resume', 'reset' ) as $op ) {
			self::assertStringContainsString( 'wink_gatekeeper_audit_' . $op, $sp, "admin_action {$op} 注册" );
		}
		$handler = self::functionBody( $sp, 'handle_audit_action' );
		self::assertStringContainsString( 'check_admin_referer', $handler, 'nonce 双闸' );
		self::assertStringContainsString( 'manage_options', $handler, 'manage_options 双闸' );
	}

	public function test_tab7_reset_uses_confirmation_wording(): void {
		$tools = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_tab_tools' );
		self::assertStringContainsString( '重新体检', $tools, '重置动作按钮' );
		self::assertStringContainsString( '作废', $tools, '重新体检=清上次标记重来的警示措辞' );
	}

	/* ===================================================================
	 * ③ Tab⑥ 报告卡
	 * =================================================================== */

	public function test_tab6_report_card_anchors_and_two_ledgers(): void {
		$sp = self::source( 'includes/settings/SettingsPage.php' );
		self::assertStringContainsString( 'render_stats_existing_audit_card', self::functionBody( $sp, 'render_tab_stats' ), 'render_tab_stats 编排含报告卡' );
		$card = self::functionBody( $sp, 'render_stats_existing_audit_card' );
		foreach ( array( 'wk-stats-existing-audit', 'wk-existing-progress-line', 'wk-existing-empty-notice', 'wk-existing-content-block' ) as $anchor ) {
			self::assertStringContainsString( $anchor, $card, "Tab⑥ 锚 {$anchor}" );
		}
		self::assertStringContainsString( '未核实', $card, '两账分离：未核实分列' );
		self::assertStringNotContainsString( '批量删除', $card, '仅标记红线：零批量删除按钮' );
		self::assertStringNotContainsString( 'wk-tools-audit-start-btn', $card, 'D6 红线：报告卡无发起钮' );
	}

	public function test_tab6_report_deep_links_to_tab7_for_start(): void {
		// Tab⑥ 空态引导去 Tab⑦ 发起（入口分工）——深链 tab_url('tools')。
		$card = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_stats_existing_audit_card' );
		self::assertStringContainsString( "tab_url( 'tools' )", $card, '空态深链 → Tab⑦' );
	}

	/* ===================================================================
	 * ④ Tab① A-U3 黄条
	 * =================================================================== */

	public function test_tab1_running_bar(): void {
		$alerts = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_overview_alerts' );
		self::assertStringContainsString( 'wk-overview-existing-bar', $alerts, 'Tab① 体检中黄条锚' );
		self::assertStringContainsString( '历史评论体检进行中', $alerts, '黄条文案' );
	}

	/* ===================================================================
	 * ⑤ CLI + cron 装配
	 * =================================================================== */

	public function test_bootstrap_registers_cli_and_cron(): void {
		$boot = self::source( 'includes/Bootstrap.php' );
		self::assertStringContainsString( 'WP_CLI', $boot, 'CLI 注册守卫' );
		self::assertStringContainsString( 'ExistingAuditCli::register', $boot, 'CLI 命令注册' );
		self::assertStringContainsString( 'ExistingAuditTask::HOOK_TICK', $boot, 'cron tick 钩子注册' );
		self::assertStringContainsString( 'cron_schedules', $boot, '自定义 interval 注册' );
	}

	public function test_cli_command_class_exists(): void {
		$src = self::source( 'includes/audit/ExistingAuditCli.php' );
		self::assertStringContainsString( 'function audit_existing', $src, 'wp wink-gatekeeper audit-existing 子命令' );
		foreach ( array( 'start', 'run', 'pause', 'resume', 'reset', 'status' ) as $verb ) {
			self::assertStringContainsString( "'" . $verb . "'", $src, "CLI 动词 {$verb}" );
		}
	}
}
