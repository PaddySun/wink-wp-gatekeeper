<?php
/**
 * W3 批 A · N7 token 双组化 + scheme 明暗定型契约测试（施工图
 * docs/W3-重设计施工图-2026-11-18.md §2 批 A；任务书 Q1=WP 高亮维持 / Q3=登录流水按现数据面）。
 *
 * 三层断言面：
 *   ① token 双组存在：admin-settings.css 暗色组（design-system §02 全表逐值）+
 *     .wk-scheme-light 浅色覆盖组（design-system §02·P4 浅色 token 表 16 行逐行照抄，
 *     含深金文字变体 #8A6A0E 关键值与语义四色浅底三件套）；
 *   ② 硬编码散点清零：旧裸色值仅允许出现在 token 定义行（--name: 行）——
 *     规则层引 token（#E43E40 排除面=历史草案错值反钉）；
 *   ③ scheme 定型函数：SettingsPage::admin_color_scheme_class() 纯函数明度二分
 *     （deep 集合=R1：Midnight/Coffee/Ectoplasm/Ocean；light 集合=Fresh/Light/Blue/
 *     Modern + Sunrise 施工批裁定〔静态推断，可单数组调整翻案〕；未知/空/自定义→
 *     暗色保底）+ render() 输出走 esc_attr（WP 规范）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Settings\SettingsPage;

final class W3SchemeTokensTest extends TestCase {

	/** @var string admin-settings.css 全文。 */
	private static string $css = '';

	private static function css(): string {
		if ( '' === self::$css ) {
			$src = file_get_contents( __DIR__ . '/../assets/css/admin-settings.css' );
			self::assertIsString( $src, 'admin-settings.css 必须可读' );
			self::$css = (string) $src;
		}
		return self::$css;
	}

	private static function settings_src(): string {
		$src = file_get_contents( __DIR__ . '/../includes/settings/SettingsPage.php' );
		self::assertIsString( $src, 'SettingsPage.php 必须可读' );
		return (string) $src;
	}

	/* ===================================================================
	 * ① token 双组存在
	 * =================================================================== */

	public function test_dark_token_group_present_verbatim(): void {
		$css = self::css();
		// design-system §02 暗色组全表（底色/文字/品牌金/语义四色 dim-line 三件套）。
		$dark_tokens = array(
			'--bg:#111114',
			'--panel:#17171c',
			'--card:#1d1d24',
			'--card-2:#222229',
			'--line:#2c2c35',
			'--line-strong:#3a3a45',
			'--text:#f1f1f5',
			'--text-2:#a8a8b4',
			'--text-3:#6d6d7a',
			'--gold:#e3b341',
			'--gold-strong:#f0c75e',
			'--gold-deep:#b98f2c',
			'--gold-dim:rgba(227,179,65,.13)',
			'--gold-line:rgba(227,179,65,.4)',
			'--red:#ff5a5f',
			'--red-dim:rgba(255,90,95,.12)',
			'--red-line:rgba(255,90,95,.42)',
			'--amber:#ffab2e',
			'--amber-dim:rgba(255,171,46,.11)',
			'--amber-line:rgba(255,171,46,.42)',
			'--green:#3ecf8e',
			'--green-dim:rgba(62,207,142,.1)',
			'--green-line:rgba(62,207,142,.4)',
			'--blue:#5da8ff',
			'--blue-dim:rgba(93,168,255,.11)',
			'--blue-line:rgba(93,168,255,.42)',
		);
		foreach ( $dark_tokens as $token ) {
			self::assertStringContainsString( $token, $css, "暗色 token 缺失：{$token}" );
		}
	}

	public function test_light_token_group_present_per_p4_table(): void {
		$css        = self::css();
		$light_span = self::scheme_light_block( $css );
		self::assertNotSame( '', $light_span, '.wk-scheme-light 作用域块必须存在' );
		// design-system §02·P4 浅色 token 表逐行照抄（16 行——静态推断新值已标注于 CSS 注释）。
		$light_tokens = array(
			'--bg:#f4f4f6',      // 行1 页面底。
			'--panel:#fafafc',   // 行2 面板（静态推断新值）。
			'--card:#ffffff',    // 行3 卡面。
			'--card-2:#f4f4f6',  // 行3 次级卡面。
			'--line:#e5e5ea',    // 行4 描线。
			'--line-strong:#c9c9d2', // 行4 强描线。
			'--text:#17171b',    // 行5 文字一级。
			'--text-2:#5d5d68',  // 行6 文字二级。
			'--text-3:#8a8a94',  // 行7 文字三级（静态推断新值）。
			'--gold-text:#8a6a0e', // 行9 深金文字态（P4 关键值——金在浅底禁作 #E3B341 文字色）。
			'--gold-line:#a8871f', // 行10 金描边态（静态推断新值）。
			'--gold-dim:#faf1db',  // 行11 金淡衬（静态推断新值）。
			'--red:#c43d42',     // 行12 危险文字态。
			'--red-dim:#fdecec', // 行12 危险底。
			'--red-line:#e89a9d', // 行12 危险线。
			'--amber:#8a6d1f',   // 行13 警示文字态（沿用 §05 样张取值）。
			'--amber-dim:#fff8e6', // 行13 警示底。
			'--amber-line:#e2b93b', // 行13 警示线。
			'--green:#1e7a4c',   // 行14 正常文字态。
			'--green-dim:#e8f7f0', // 行14 正常底。
			'--green-line:#7ed0ac', // 行14 正常线。
			'--blue:#1d5fb8',    // 行15 信息文字态。
			'--blue-dim:#eaf2fe', // 行15 信息底。
			'--blue-line:#8fb8f0', // 行15 信息线。
		);
		foreach ( $light_tokens as $token ) {
			self::assertStringContainsString( $token, $light_span, "浅色 token 缺失（§02·P4 表行）：{$token}" );
		}
	}

	/**
	 * 提取 .wk-scheme-light 作用域块（选择器+{ 到配平 }）。
	 */
	private static function scheme_light_block( string $css ): string {
		$start = strpos( $css, '.wk-scheme-light {' );
		if ( false === $start ) {
			return '';
		}
		$open  = strpos( $css, '{', $start );
		$depth = 0;
		$len   = strlen( $css );
		for ( $i = (int) $open; $i < $len; $i++ ) {
			if ( '{' === $css[ $i ] ) {
				$depth++;
			} elseif ( '}' === $css[ $i ] ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $css, $start, $i - $start + 1 );
				}
			}
		}
		return '';
	}

	public function test_wink_red_excluded_value_never_appears(): void {
		// 排除面（施工图 W3DesignSystemTest 条目）：#E43E40=历史草案错值，两态均不得出现
		// ——界面危险语义暗色=#FF5A5F / 浅色文字态=#C43D42 / logo 恒 #FF4D4F。
		self::assertDoesNotMatchRegularExpression( '/#E43E40/i', self::css(), '#E43E40 错值排除面' );
	}

	public function test_logo_red_constant_both_schemes(): void {
		$css = self::css();
		self::assertStringContainsString( '--logo-red:#ff4d4f', $css, 'logo 恒 #FF4D4F（双色注：品牌位图语义两态同值）' );
		// 浅色组不得覆盖 logo 红（不翻转纪律）。
		$light = self::scheme_light_block( $css );
		self::assertStringNotContainsString( '--logo-red', $light, 'logo 红不随明暗翻转' );
	}

	/* ===================================================================
	 * ② 硬编码散点清零
	 * =================================================================== */

	public function test_hex_colors_only_on_token_definition_lines(): void {
		$violations = array();
		foreach ( preg_split( '/\r?\n/', self::css() ) as $line ) {
			$trim = trim( $line );
			if ( '' === $trim || str_starts_with( $trim, '/*' ) || str_starts_with( $trim, '*' ) ) {
				continue; // 空行/注释行（文件头 docblock 色值=文档层）豁免。
			}
			if ( preg_match( '/#[0-9a-fA-F]{3,8}\b/', $line ) && ! preg_match( '/^\s*--[a-z0-9-]+\s*:/', $line ) ) {
				$violations[] = $trim;
			}
		}
		self::assertSame(
			array(),
			$violations,
			"规则层零裸色值（一律引 token；hex 仅允许在 --token 定义行）：\n" . implode( "\n", array_slice( $violations, 0, 12 ) )
		);
	}

	public function test_legacy_brand_hex_retired_from_rules(): void {
		// 老品牌色板（:root 12 变量时代）在规则层清零——新树 dark 组承值、规则引 token。
		foreach ( array( '#f7f7f8', '#e9e9ec', '#86868f', '#fff2f2', '#ffd9d9', '#e8383a' ) as $legacy ) {
			self::assertDoesNotMatchRegularExpression( '/' . preg_quote( $legacy, '/' ) . '/i', self::css(), "老品牌色 {$legacy} 退役（token 树承载）" );
		}
	}

	/* ===================================================================
	 * ③ scheme 定型函数（明度二分集合表）
	 * =================================================================== */

	public function test_deep_schemes_resolve_dark(): void {
		foreach ( array( 'midnight', 'coffee', 'ectoplasm', 'ocean' ) as $scheme ) {
			self::assertSame( 'wk-scheme-dark', SettingsPage::admin_color_scheme_class( $scheme ), "deep 集合 {$scheme} → 暗色组" );
		}
	}

	public function test_light_schemes_resolve_light(): void {
		foreach ( array( 'fresh', 'light', 'blue', 'modern' ) as $scheme ) {
			self::assertSame( 'wk-scheme-light', SettingsPage::admin_color_scheme_class( $scheme ), "light 集合 {$scheme} → 浅色组" );
		}
	}

	public function test_sunrise_ruled_light_with_override_note(): void {
		// 施工批裁定（任务书授权「Sunrise 等边界归属=施工批定」）：sunrise=原 3.0 浅色系
		// （页底浅色的静态推断，未实机核——翻案只动 ADMIN_COLOR_LIGHT_SCHEMES 单数组）。
		self::assertSame( 'wk-scheme-light', SettingsPage::admin_color_scheme_class( 'sunrise' ), 'Sunrise 归浅色（施工批裁定，可调）' );
		$src = self::settings_src();
		self::assertStringContainsString( 'sunrise', $src, 'Sunrise 归类显式入集合表（防静默回落遮蔽裁定）' );
	}

	public function test_unknown_scheme_falls_back_to_dark(): void {
		// 兜底条款（§02·P4）：侦测失败/未知 scheme/自定义 scheme → 恒暗色组（品牌保底）。
		foreach ( array( '', 'bogus', 'custom_starlight', 'MIDNIGHT-CLONE' ) as $scheme ) {
			self::assertSame( 'wk-scheme-dark', SettingsPage::admin_color_scheme_class( $scheme ), "未知/异常 scheme「{$scheme}」→ 暗色保底" );
		}
	}

	public function test_scheme_sets_are_class_constants(): void {
		$src = self::settings_src();
		self::assertStringContainsString( 'ADMIN_COLOR_DEEP_SCHEMES', $src, 'deep 集合常量化（可单测可调）' );
		self::assertStringContainsString( 'ADMIN_COLOR_LIGHT_SCHEMES', $src, 'light 集合常量化（可单测可调）' );
	}

	/* ===================================================================
	 * ④ render() 输出规范（WP 规范=class 走 esc_attr）
	 * =================================================================== */

	public function test_render_output_uses_esc_attr_for_scheme_class(): void {
		$src    = self::settings_src();
		$render = self::function_body( $src, 'render' );
		self::assertStringContainsString( 'wp_get_user_admin_color', $render, 'PHP 主路径=wp_get_user_admin_color()（§02·P4 双路径之服务端定型）' );
		self::assertStringContainsString( 'admin_color_scheme_class(', $render, '定型走纯函数二分' );
		self::assertStringContainsString( 'esc_attr( $scheme_class )', $render, 'class 输出走 esc_attr（WP 规范）' );
		self::assertStringContainsString( 'wk-wrap', $render, 'scheme class 落 .wk-wrap 容器根' );
	}

	/**
	 * 提取具名函数/方法体（花括号配平）。
	 */
	private static function function_body( string $src, string $name ): string {
		$start = strpos( $src, 'function ' . $name );
		self::assertNotFalse( $start, "函数 {$name} 必须存在" );
		$open  = strpos( $src, '{', (int) $start );
		$depth = 0;
		$len   = strlen( $src );
		for ( $i = (int) $open; $i < $len; $i++ ) {
			if ( '{' === $src[ $i ] ) {
				$depth++;
			} elseif ( '}' === $src[ $i ] ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $src, (int) $open, $i - $open + 1 );
				}
			}
		}
		self::fail( "函数 {$name} 花括号不配平" );
		return '';
	}
}
