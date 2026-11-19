<?php
/**
 * M6·批 H4 · 手测二轮修正五件（2026-11-17，施工图 M6-H4-修正批-设计定稿 §0——TDD 契约面）。
 *
 * 五件（FEEDBACK-2 落地）：H4-1 popup 自动开层（aliyun.js getInstance→instance.show）/
 * H4-2 体检黄条挪卡顶 / H4-3 网址核查未开启黄条化 / H4-4 复核块套防线组 / H4-5 静态 logo。
 * 全 source-scan 契约（JS 件无单测基建；PHP 件结构迁移面）+条件-锚位序断言。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests {

	use PHPUnit\Framework\TestCase;

	final class M6H4FixTest extends TestCase {

		private static function source( string $rel ): string {
			$src = file_get_contents( dirname( __DIR__ ) . '/' . $rel );
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

		/* H4-1 popup 自动开层 */
		public function test_h41_popup_autoshow_after_init(): void {
			$src = self::source( 'assets/js/drivers/aliyun.js' );
			self::assertStringContainsString( "'popup' === mode", $src, 'popup 形态判定在场' );
			self::assertStringContainsString( 'instance.show();', $src, '【H4-1】getInstance 内主动开层（一步到位——两步点击根因修复）' );
			// 条件序：show 调用须在 popup 条件守卫之后（ getInstance 回调体内——断言用带分号调用形态防注释首现）。
			$guard = strpos( $src, "'popup' === mode" );
			$show  = strpos( $src, 'instance.show();' );
			self::assertGreaterThan( $guard, $show, 'show() 在 popup 守卫内' );
			self::assertStringContainsString( 'try', substr( $src, $show - 120, 240 ), 'try-catch 防御（instance API 静态推断）' );
		}

		/* H4-2 体检黄条卡顶（位置=黄条锚先于说明文锚） */
		public function test_h42_audit_bar_at_card_top(): void {
			$body = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_tab_tools' );
			$h2   = strpos( $body, 'Existing comment audit' );
			$desc = strpos( $body, 'Hands all existing comments to the provider' );
			$bar  = strpos( $body, 'wk-tools-audit-running-bar' );
			self::assertNotFalse( $h2 );
			self::assertNotFalse( $desc );
			self::assertNotFalse( $bar, '运行中黄条在场' );
			self::assertGreaterThan( $h2, $bar, '【H4-2】黄条在 h2 后（卡顶）' );
			self::assertLessThan( $desc, $bar, '黄条先于说明文（与网址核查黄条同位）' );
		}

		/* H4-3 网址核查未开启黄条 */
		public function test_h43_url_audit_off_notice(): void {
			$body = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_tab_tools' );
			self::assertStringContainsString( 'wk-tools-url-audit-off-notice', $body, '【H4-3】未开启独立黄条锚' );
			self::assertStringContainsString( 'The URL check is off — enable “URL check” on the “Cloud moderation” tab first, then come back to start the existing-URL check.', $body, '文案保持' );
			self::assertMatchesRegularExpression( "/self::notice_yellow_bar\(\s*'warn'/", $body, '插件级黄条 warn 级（【W3·批B 翻转】U-1 式黄条迁 notice_yellow_bar）' );
		}

		/* H4-4 复核块套防线组 */
		public function test_h44_intercept_block_grouped(): void {
			$body = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_stats_comment_section' );
			self::assertStringContainsString( 'wk-defense-group', $body, '防线组类在场' );
			$group = strpos( $body, 'Post-publish review line (URL check)' );
			$block = strpos( $body, 'wk-stats-url-intercept-block' );
			self::assertNotFalse( $group, '【H4-4】复核块组标题' );
			self::assertGreaterThan( $group, $block, '组标题先于块（h4+卡节奏对齐）' );
		}

		/* H4-5 静态 logo（眨眼段退役） */
		public function test_h45_static_logo_no_blink(): void {
			$js = self::source( 'assets/js/admin-brand.js' );
			self::assertStringNotContainsString( 'playBlink', $js, '【H4-5】眨眼播放段退役（设置页静态 logo——用户拍板）' );
			self::assertStringNotContainsString( 'data-blink-src', $js, '眨眼源切换逻辑退役' );
			$php = self::source( 'includes/settings/SettingsPage.php' );
			self::assertStringNotContainsString( 'data-blink-src', $php, 'logo img 不再携带眨眼源属性（src 本就是静态版）' );
			self::assertStringContainsString( 'logo-wink.svg', $php, '静态版 logo 引用在场' );
		}
	}
}
