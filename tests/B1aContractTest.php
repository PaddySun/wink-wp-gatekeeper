<?php
/**
 * B1a 批次契约测试（P2-3 wp_date 时区 + 版本三处同步——R1 G7 纪律）。
 *
 * P2-3 规格：CommentGate 访客可见证据行时间（core_rejection / reject_with_message 两处）
 * 由 date() 改 wp_date()（跟随站点时区——访客可见时间差修复）；Tc3Signer gmdate 不动
 * （签名协议语义，UTC 是协议常量）。WP 边界函数 only：function_exists 守卫保纯 PHP
 * 单测环境零依赖（G5）。
 *
 * 版本规格（R1 G7）：插件头 Version / WINK_GATEKEEPER_VERSION 常量 / readme.txt
 * Stable tag 三处同步 0.2.3；Changelog 行含测试数与设计锚点。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;

final class B1aContractTest extends TestCase {

	private static function source( string $rel ): string {
		$src = file_get_contents( __DIR__ . '/../' . $rel );
		self::assertIsString( $src, "{$rel} 必须可读" );
		return (string) $src;
	}

	/* ---------- P2-3：访客可见时间 wp_date（站点时区） ---------- */

	public function test_comment_gate_formats_evidence_time_via_wp_date_guard(): void {
		$src = self::source( 'includes/gates/CommentGate.php' );
		$this->assertStringContainsString( "function_exists( 'wp_date' )", $src, 'wp_date 须有 WP 边界守卫（纯 PHP 单测零依赖）' );
		$this->assertStringContainsString( "wp_date( 'Y-m-d H:i:s', \$timestamp )", $src );
		// 两处原始 date() 直调清零（core_rejection :806 / reject_with_message :1100——P2-3 修复面）。
		$this->assertDoesNotMatchRegularExpression( '/=\s*date\(\s*\'Y-m-d H:i:s\'/', $src, '证据行时间不得再直调 date()' );
		// function_exists 守卫内的回退路径保留（唯一 date() 残留点）。
		$this->assertMatchesRegularExpression( "/return date\( 'Y-m-d H:i:s', \\\$timestamp \);/", $src );
	}

	public function test_tc3_signer_gmtime_untouched(): void {
		// 签名协议语义不放宽（P2-3 条款原文：Tc3Signer gmdate 不动）。
		$src = self::source( 'includes/http/Tc3Signer.php' );
		$this->assertStringContainsString( 'gmdate', $src );
	}

	/**
	 * 【v1.5·FIX】comment_flood 原生上下文品牌页接管（用户实测：flood 文案品牌化但
	 * 页面壳保持 WP 原生 wp_die——对齐 duplicate_comment 接管形态；AJAX/REST 分支豁免）。
	 */
	public function test_flood_trigger_reveals_die_page_on_native_context(): void {
		$src = $this->source( 'includes/gates/CommentGate.php' );
		$this->assertStringContainsString( "DiePage::show( 'comment_flood'", $src, 'flood 原生上下文须走 DiePage 品牌页' );
		$hook = substr( $src, (int) strpos( $src, 'function hook_comment_flood_trigger' ) );
		$hook = substr( $hook, 0, (int) strpos( $hook, 'function filter_comment_flood_message' ) );
		$this->assertStringContainsString( 'DOING_AJAX', $hook, 'flood 接管须豁免 AJAX/REST 分支' );
	}

	/* ---------- 版本三处同步（R1 G7） ---------- */

	public function test_version_is_bumped_to_0_2_3_in_three_places(): void {
		// 版本断言随批次推进更新（当前 0.4.2=UI-FIX 密度与样式克制批次；历史批次契约面在 UiTab6StatsContractTest）。
		$entry = self::source( 'wink-wp-gatekeeper.php' );
		$readme = self::source( 'readme.txt' );

		$this->assertMatchesRegularExpression( '/^ \* Version:\s+0\.4\.2$/m', $entry, '插件头 Version 须为 0.4.2' );
		$this->assertStringContainsString( "define( 'WINK_GATEKEEPER_VERSION', '0.4.2' )", $entry, '版本常量须为 0.4.2' );
		$this->assertMatchesRegularExpression( '/^Stable tag: 0\.4\.2$/m', $readme, 'readme Stable tag 须为 0.4.2' );
		$this->assertStringContainsString( '819 tests / 3657 assertions', $readme, 'Changelog 须含测试数（R1 G7 纪律）' );
		$this->assertStringContainsString( 'F4-1', $readme, 'Changelog 须含设计锚点' );
		$this->assertStringContainsString( 'F4-3', $readme, 'Changelog 须含设计锚点' );
	}
}
