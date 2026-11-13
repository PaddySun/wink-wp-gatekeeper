<?php
/**
 * option 键卫生测试（M4UX 定稿 §2 P0-2/P0-7，F1 批）。
 *
 * P0-2（R-AK §D P0-1）：uninstall.php 漏删 wink_gatekeeper_health（云审停转状态树）——
 *   开 cleanup_on_uninstall 卸载后残留，重装首日读到旧停转态（「新装第一天驱动被停转」
 *   诡异态，静态推断）。钉两面：①uninstall delete_option 键集 === 全插件 OPTION_NAME
 *   常量集（新增 option 自动入列防再漂移）；②头注清单与实际同步。
 *
 * P0-7（R-AK §D P0-6）：CommentGate/DiePage 两处 'wink_gatekeeper_settings' 魔法串
 *   换 SettingsPage::OPTION_NAME 常量（规范源唯一——防未来改 option 名时字面量位必漏）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\TestMode;
use WinkGatekeeper\Settings\SettingsPage;
use WinkGatekeeper\Stats\DriverHealth;
use WinkGatekeeper\Stats\StatsCache;
use WinkGatekeeper\Stats\Usage;

final class OptionHygieneTest extends TestCase {

	private static function uninstall_src(): string {
		$src = file_get_contents( __DIR__ . '/../uninstall.php' );
		self::assertIsString( $src, 'uninstall.php 必须可读' );
		return (string) $src;
	}

	/* ---------- P0-2：uninstall 键集一致性 ---------- */

	public function test_uninstall_deletes_exactly_all_plugin_option_names(): void {
		$src = self::uninstall_src();

		preg_match_all( "/delete_option\(\s*'([^']+)'\s*\)/", $src, $matches );
		$deleted = array_values( array_unique( $matches[1] ) );
		sort( $deleted );

		// 全插件 OPTION_NAME 常量集（新增 option 挂进本清单自动受钉——防漏删再漂移）。
		$expected = array_values( array_unique( array(
			SettingsPage::OPTION_NAME,
			TestMode::OPTION_NAME, // 与 SettingsPage 同值——同键去重后仍须在删除清单。
			StatsCache::OPTION_NAME,
			Usage::OPTION_NAME,
			DriverHealth::OPTION_NAME,
		) ) );
		sort( $expected );

		$this->assertSame( $expected, $deleted, 'uninstall delete_option 键集 === 全插件 OPTION_NAME 常量集（漏删=卸载残留）' );
		$this->assertContains( DriverHealth::OPTION_NAME, $deleted, 'wink_gatekeeper_health（云审停转状态树）必须在卸载清单' );
	}

	public function test_uninstall_header_documents_four_options(): void {
		$src = self::uninstall_src();

		$this->assertStringContainsString( '4 个 option', $src, '头注清单与实际删除数同步（3→4）' );
		$this->assertStringContainsString( DriverHealth::OPTION_NAME, $src, '头注点名 health 键（R-AK §D P0-1：头注自己写的清单就是承诺）' );
	}

	/* ---------- P0-7：option 名魔法串清零 ---------- */

	public function test_gates_and_intercept_use_option_name_constant(): void {
		foreach ( array(
			'includes/gates/CommentGate.php',
			'includes/intercept/DiePage.php',
		) as $rel ) {
			$src = file_get_contents( __DIR__ . '/../' . $rel );
			self::assertIsString( $src, "{$rel} 必须可读" );

			$this->assertStringNotContainsString( "'wink_gatekeeper_settings'", $src, "{$rel} 魔法串必须换 SettingsPage::OPTION_NAME 常量（防漂移）" );
			$this->assertStringContainsString( 'SettingsPage::OPTION_NAME', $src, "{$rel} 须引用规范源常量" );
		}
	}
}
