<?php
/**
 * B1b 批次契约测试（F4-4/F4-6 recorder 口径 + F4-5 embed 同宽 + B2 掩码 + B3 sleep
 * + P2-3 半 + 版本三处同步 0.3.0——R1 G7 纪律延续）。
 *
 * 源码契约断言面（与 B1aContractTest 同模式）：纯逻辑单测不可达的 WP 边界接线
 * （Bootstrap filter 注册 / hook_ 系列 / CSS）以源码形态钉死，防回归。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;

final class B1bContractTest extends TestCase {

	private static function source( string $rel ): string {
		$src = file_get_contents( __DIR__ . '/../' . $rel );
		self::assertIsString( $src, "{$rel} 必须可读" );
		return (string) $src;
	}

	/* ---------- F4-4/F4-6：recorder 口径统一（02 §3.2a v1.5） ---------- */

	public function test_account_form_gate_recorder_uses_stat_keys_and_drill_guard(): void {
		$src = self::source( 'includes/gates/AccountFormGate.php' );
		$this->assertStringContainsString( 'StatsCache::stat_keys_for', $src, '默认 recorder 须按 verdict 三维映射计数（不再 reject 单档）' );
		$this->assertStringContainsString( 'StatsCache::is_drill( $meta )', $src, 'recorder 须排除 drill（test_mode/演练）行不计数' );
		$this->assertStringContainsString( '计数与落库解耦', $src, '落库口径（写入矩阵）与统计计数解耦注记在场' );
	}

	public function test_login_gate_bumps_attempts_on_wp_login_failed(): void {
		$src = self::source( 'includes/gates/LoginGate.php' );
		$this->assertStringContainsString( "StatsCache::record_attempts( 'login' )", $src, 'wp_login_failed 路径直 bump by_gate.login.attempts（04 §4——不经事件表）' );
		$this->assertStringContainsString( 'attempts_countable', $src, 'attempts 计数须过双信号分类门控（empty_* 不计）' );
	}

	public function test_settings_page_renders_account_four_dimensions(): void {
		$src = self::source( 'includes/settings/SettingsPage.php' );
		// UI 批 1 承继形态：v1.5 四维小字（调用/通过/拦截/登录失败尝试）升级为登录段
		// 概览行（拦下/验证放行/密码输错——人话译词）+ 三张门分账表（说明列带 attempts）。
		$this->assertStringContainsString( 'account_gate_rows', $src, 'Tab⑥ 登录段须渲染三张门分账（v1.5 四维小字的承继形态）' );
		$this->assertStringContainsString( '密码输错', $src, '四维之密码输错（attempts）在登录概览行展示' );
		$this->assertStringContainsString( '验证放行', $src, '四维之验证放行（captcha_pass）在登录概览行展示' );
		$this->assertStringContainsString( 'render_stats_login_section', $src, '登录防护段独立成段（DESIGN-UI-tab6 口径二）' );
	}

	/* ---------- F4-5：embed 容器同宽（03 §2.2 v1.5 通用条款） ---------- */

	public function test_embed_width_universal_clause_in_css(): void {
		$src = self::source( 'assets/css/front-gate.css' );
		$this->assertStringContainsString( 'min-width: min(300px, 100%)', $src, '.wk-gate-embed 基础样式通用规格（302px 是天御实测值不是契约值）' );
		$this->assertStringContainsString( '.login .wk-gate-embed', $src, '登录/注册/找回页同一容器样式段承担同宽' );
		$this->assertMatchesRegularExpression( '/\.login \.wk-gate-embed \{[^}]*width: 100%/', $src, '登录域 embed 容器与 status 同占表单内容宽（F4-5 同宽）' );
		$this->assertDoesNotMatchRegularExpression( '/min-width:\s*min\(320px/', $src, '阿里 320px 特例只是注记不落通用代码（05 §1.2——适配器承载）' );
	}

	/* ---------- B2：登录错误掩码（WORDFENCE-ADOPTION B2——呈现层） ---------- */

	public function test_login_error_mask_class_shape(): void {
		$src = self::source( 'includes/gates/LoginErrorMask.php' );
		$this->assertStringContainsString( "const FILTER_ENABLED = 'wink_gatekeeper_login_error_mask_enabled'", $src, '掩码开关 filter 名（保守默认开+可关）' );
		foreach ( array( 'invalid_username', 'incorrect_password', 'invalid_email', 'invalidcombo' ) as $code ) {
			$this->assertStringContainsString( "'{$code}'", $src, '掩码码表须含 ' . $code );
		}
		$this->assertStringContainsString( '只动呈现层', $src, 'docblock 契约：不动内部错误码分类（F39 双信号依赖内部码）' );
	}

	public function test_bootstrap_registers_presentation_mask_filters(): void {
		$src = self::source( 'includes/Bootstrap.php' );
		$this->assertStringContainsString( "'wp_login_errors'", $src, '登录页错误聚合输出处（wp-login.php 呈现层）掩码 filter' );
		$this->assertStringContainsString( "'lostpassword_errors'", $src, '找回页错误聚合输出处掩码 filter（复现样本=Unknown email address）' );
		$this->assertStringContainsString( 'filter_wp_login_errors', $src );
		$this->assertStringContainsString( 'filter_lostpassword_errors', $src );
	}

	/* ---------- B3：认证失败随机 sleep（02 §3.2 v1.5 量级注记引用） ---------- */

	public function test_login_gate_fail_sleep_wiring(): void {
		$src = self::source( 'includes/gates/LoginGate.php' );
		$this->assertStringContainsString( 'const FAIL_SLEEP_MAX_MS = 2000', $src );
		$this->assertStringContainsString( "apply_filters( 'wink_gatekeeper_login_fail_sleep_enabled', true )", $src, '量级开关（高流量/共享主机可关——默认 true）' );
		$this->assertStringContainsString( 'usleep( self::fail_sleep_delay_ms() * 1000 )', $src, 'WP 边界消费：WP_Error 组装前随机延迟 0–2000ms' );
		$this->assertStringContainsString( '02 §3.2', $src, 'sleep 落点须引用 02 量级注记（代码注释）' );
	}

	/* ---------- P2-3 半：AccountFormGate 证据行时间 wp_date ---------- */

	public function test_account_form_gate_evidence_time_wp_date_guard(): void {
		$src = self::source( 'includes/gates/AccountFormGate.php' );
		$this->assertStringContainsString( "function_exists( 'wp_date' )", $src, 'wp_date 须有 WP 边界守卫（纯 PHP 单测零依赖）' );
		$this->assertStringContainsString( "wp_date( 'Y-m-d H:i:s', \$timestamp )", $src );
		$this->assertDoesNotMatchRegularExpression( '/=\s*date\(\s*\'Y-m-d H:i:s\'/', $src, '证据行时间不得再直调 date()（AccountFormGate:289——P2-3 半）' );
		$this->assertMatchesRegularExpression( "/return date\( 'Y-m-d H:i:s', \\\$timestamp \);/", $src );
	}

	/* ---------- 版本三处同步（R1 G7） ---------- */

	public function test_version_is_bumped_to_0_2_4_in_three_places(): void {
		$entry  = self::source( 'wink-wp-gatekeeper.php' );
		$readme = self::source( 'readme.txt' );

		$this->assertMatchesRegularExpression( '/^ \* Version:\s+0\.5\.4$/m', $entry, '插件头 Version 须为 0.5.4' );
		$this->assertStringContainsString( "define( 'WINK_GATEKEEPER_VERSION', '0.5.4' )", $entry, '版本常量须为 0.5.4' );
		$this->assertMatchesRegularExpression( '/^Stable tag: 0.5.4$/m', $readme, 'readme Stable tag 须为 0.5.4' );
		$this->assertStringContainsString( '= 0.3.0 =', $readme, 'Changelog 须有 0.3.0 条目' );
		$this->assertStringContainsString( 'F4-4', $readme, 'Changelog 须含设计锚点' );
		$this->assertStringContainsString( 'F4-6', $readme, 'Changelog 须含设计锚点' );
		$this->assertStringContainsString( 'F4-5', $readme, 'Changelog 须含设计锚点' );
		$this->assertStringContainsString( 'B2', $readme, 'Changelog 须含设计锚点' );
		$this->assertStringContainsString( 'B3', $readme, 'Changelog 须含设计锚点' );
	}
}
