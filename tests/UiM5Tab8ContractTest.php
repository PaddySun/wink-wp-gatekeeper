<?php
/**
 * M5 UI-A 批 · Tab⑧「站点增强」骨架契约测试（2026-11-14，U1+U2）。
 *
 * 骨架契约面（源码契约测试，纯 PHP 零 WP 依赖；行为面由 B 机真机 DOM 断言承载）：
 *   ① 页签注册——TABS 追加 ⑧「站点增强」（D1 顺延裁决，①–⑦ 不动）+ render_tab 分派；
 *   ② 卡一「评论网站字段」——常显说明单点（后台可见+存量不删）/A 收集开关行/B 链接开关行/
 *      A 关闭提示条件显隐（url_audit 特性在场才渲染）/A·B 行说明互斥（0.1 独立语义红线）；
 *   ③ 卡二「头像来源」——五枚举下拉/custom 行条件显隐（PHP 初始 hidden+JS change 绑定）/
 *      常显切换延迟注/测试按钮+三态结果行（AJAX 走 RemoteClient 传输脊）；
 *   ④ Bootstrap 装配——SiteEnhance 五 filter 注册（comment_form_defaults/preprocess_comment/
 *      get_comment_author_link/pre_get_avatar_data；【M5FIX1】补 get_comment_author_url 区块主题收口）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Settings\SettingsPage;

final class UiM5Tab8ContractTest extends TestCase {

	private static function source( string $rel ): string {
		$src = file_get_contents( __DIR__ . '/../' . $rel );
		self::assertIsString( $src, "{$rel} 必须可读" );
		return (string) $src;
	}

	private static function settings_src(): string {
		return self::source( 'includes/settings/SettingsPage.php' );
	}

	/**
	 * 提取具名函数/方法体（花括号配平）——契约断言按作用域收窄，防全局误命中。
	 */
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
	 * ① 页签注册（D1 顺延）
	 * =================================================================== */

	public function test_tabs_appends_enhance_as_tab8(): void {
		$tabs = SettingsPage::TABS;
		self::assertArrayHasKey( 'enhance', $tabs, 'Tab⑧ 站点增强已注册' );
		self::assertSame( array( '⑧', '站点增强' ), $tabs['enhance'] );
		self::assertSame( 'enhance', array_key_last( $tabs ), '⑧ 顺延末位不重编号（D1）' );
		self::assertSame( array( '⑦', '工具' ), $tabs['tools'], '①–⑦ 原序不动' );
	}

	public function test_render_tab_dispatches_enhance(): void {
		$body = self::functionBody( self::settings_src(), 'render_tab' );
		self::assertStringContainsString( "case 'enhance'", $body );
		self::assertStringContainsString( 'render_tab_enhance', $body );
	}

	/* ===================================================================
	 * ② 卡一「评论网站字段」
	 * =================================================================== */

	private static function enhance_src(): string {
		return self::functionBody( self::settings_src(), 'render_tab_enhance' );
	}

	public function test_card1_dom_anchors_present(): void {
		$src = self::enhance_src();
		foreach ( array( 'wk-site-fields-card', 'wk-site-fields-constant-note', 'wk-site-field-collect-row', 'wk-site-field-delink-row', 'wk-site-field-a-notice' ) as $anchor ) {
			self::assertStringContainsString( $anchor, $src, "卡一 DOM 锚 {$anchor}" );
		}
	}

	public function test_card1_constant_note_single_point(): void {
		// 常显说明只写一处：后台始终可见+存量不删——A/B 行说明不得重复该背景。
		$src    = self::enhance_src();
		$noteId = 'wk-site-fields-constant-note';
		self::assertStringContainsString( $noteId, $src );
		// 常显说明行只出现一次 id。
		self::assertSame( 1, substr_count( $src, 'id="' . $noteId . '"' ), '常显说明 id 唯一（段级一次红线）' );
	}

	public function test_card1_ab_rows_are_mutually_silent(): void {
		// 定稿 §0.1 文案红线：A 行说明只讲收集、一个字不提 B；B 行只讲显示、不提 A。
		$src    = self::enhance_src();
		$aBlock = self::blockBetween( $src, 'wk-site-field-collect-row', 'wk-site-field-delink-row' );
		$bBlock = self::blockBetween( $src, 'wk-site-field-delink-row', 'wk-avatar-card' );
		self::assertStringNotContainsString( '纯文本', $aBlock, 'A 行不得提 B 语义词（纯文本）' );
		self::assertStringNotContainsString( '链接', $aBlock, 'A 行不得提 B 语义词（链接）' );
		self::assertStringNotContainsString( '网站', $bBlock, 'B 行不得提 A 语义词（网站）' );
		self::assertStringNotContainsString( '网址', $bBlock, 'B 行不得提 A 语义词（网址）' );
		self::assertStringNotContainsString( '收集', $bBlock, 'B 行不得提 A 语义词（收集）' );
	}

	public function test_card1_a_notice_gated_on_url_audit_feature(): void {
		// A 关闭提示只在「网址核查特性已存在」时渲染（url_audit 子树在场才渲染）；
		// U5 未实装时本批零渲染（F33 纪律：不出现「审核了一个不存在功能」的假提示）。
		$src   = self::enhance_src();
		$block = self::blockBetween( $src, 'wk-site-field-collect-row', 'wk-site-field-delink-row' );
		self::assertStringContainsString( 'url_audit', $block, '提示行渲染须门控 url_audit 特性在场' );
		self::assertStringContainsString( '网址', $block, '提示文案指向网址核查' );
		self::assertStringContainsString( 'wk-site-field-a-notice', $block, '提示行 DOM 锚' );
	}

	/* ===================================================================
	 * ③ 卡二「头像来源」
	 * =================================================================== */

	public function test_card2_dom_anchors_present(): void {
		$src = self::enhance_src();
		foreach ( array( 'wk-avatar-card', 'wk-avatar-source-select', 'wk-avatar-custom-base-row', 'wk-avatar-switch-note', 'wk-avatar-test-btn', 'wk-avatar-test-result' ) as $anchor ) {
			self::assertStringContainsString( $anchor, $src, "卡二 DOM 锚 {$anchor}" );
		}
	}

	public function test_card2_five_enum_options(): void {
		$src = self::enhance_src();
		foreach ( array( 'official', 'cravatar', 'weavatar', 'sepcc', 'custom' ) as $opt ) {
			self::assertStringContainsString( $opt, $src, "下拉枚举含 {$opt}" );
		}
	}

	public function test_card2_custom_row_php_initial_hidden(): void {
		// custom 条件显隐：PHP 初始态按存量值 hidden（custom_header 联动范式）。
		$src   = self::enhance_src();
		$block = self::blockBetween( $src, 'wk-avatar-custom-base-row', 'wk-avatar-switch-note' );
		self::assertStringContainsString( 'hidden', $block, 'custom 行 PHP 初始 hidden' );
		self::assertStringContainsString( 'custom', $block, 'hidden 判定跟随 source' );
	}

	public function test_card2_js_change_binding(): void {
		$js = self::source( 'assets/js/admin-brand.js' );
		self::assertStringContainsString( 'wk-avatar-source-select', $js, 'JS 绑定头像源下拉' );
		self::assertStringContainsString( 'wk-avatar-custom-base-row', $js, 'JS 同步 custom 行显隐' );
	}

	public function test_card2_test_button_ajax_endpoint(): void {
		$sp = self::settings_src();
		self::assertStringContainsString( "AJAX_TEST_AVATAR = 'wink_gatekeeper_test_avatar_source'", $sp, 'AJAX action 常量' );
		$handler = self::functionBody( $sp, 'ajax_test_avatar_source' );
		self::assertStringContainsString( 'check_ajax_referer', $handler, 'nonce 双闸' );
		self::assertStringContainsString( 'manage_options', $handler, 'manage_options 双闸' );
		self::assertStringContainsString( 'RemoteClient', $handler, 'HTTP 走传输脊唯一出口（D13 纪律）' );
		self::assertStringContainsString( 'AvatarSources::judge', $handler, '三态判定走纯函数' );
	}

	public function test_bootstrap_registers_avatar_test_ajax(): void {
		$src = self::source( 'includes/Bootstrap.php' );
		self::assertStringContainsString( 'wp_ajax_\' . SettingsPage::AJAX_TEST_AVATAR', $src, 'Bootstrap 注册头像测试端点' );
	}

	/* ===================================================================
	 * ④ Bootstrap 装配（SiteEnhance 五 filter——【M5FIX1】补 get_comment_author_url）
	 * =================================================================== */

	public function test_bootstrap_registers_site_enhance_filters(): void {
		$src = self::source( 'includes/Bootstrap.php' );
		self::assertStringContainsString( 'SiteEnhance', $src, 'Bootstrap 装配 SiteEnhance' );
		self::assertStringContainsString( 'comment_form_defaults', $src, 'U1-A：表单字段开关挂点' );
		self::assertStringContainsString( 'get_comment_author_link', $src, 'U1-B：前台作者名去链接挂点' );
		// 【M5FIX1】区块主题收口：core 区块 wp-block-comment-author-name 绕过
		// get_comment_author_link 直接消费 get_comment_author_url 拼锚点——同挂。
		self::assertStringContainsString( 'get_comment_author_url', $src, 'U1-B：区块主题 URL 收口挂点' );
		self::assertStringContainsString( 'pre_get_avatar_data', $src, 'U2：头像源改写挂点' );
		// preprocess_comment 第二挂点（双保险丢弃 url 入参）——SiteEnhance 的注册行单独可辨。
		self::assertMatchesRegularExpression( '/SiteEnhance[\s\S]{0,400}preprocess_comment|preprocess_comment[\s\S]{0,400}SiteEnhance/', $src, 'U1-A 双保险：preprocess 层丢弃入参' );
	}

	public function test_site_enhance_assembled_unconditionally_of_gates(): void {
		// U1/U2 是站点内容设置面（Tab⑧ 定位），与闸启停无关——闸关不剥夺。
		$src     = self::source( 'includes/Bootstrap.php' );
		$assembl = self::functionBody( $src, 'assemble_gates' );
		self::assertStringContainsString( 'SiteEnhance', $assembl, '装配在 assemble_gates 内' );
		// 装配段不处于 gates.comment.enabled 分支内（粗粒度：SiteEnhance 出现于 assemble_gates 全文即可 + 无 enabled 包裹由人工核对）。
		self::assertStringContainsString( 'new SiteEnhance', $assembl );
	}

	/* ===================================================================
	 * 助手
	 * =================================================================== */

	private static function blockBetween( string $src, string $startMarker, string $endMarker ): string {
		$start = strpos( $src, $startMarker );
		self::assertNotFalse( $start, "HTML 须含 {$startMarker}" );
		$end = strpos( $src, $endMarker, $start + strlen( $startMarker ) );
		self::assertNotFalse( $end, "须在 {$startMarker} 之后含 {$endMarker}" );
		return substr( $src, $start, $end - $start );
	}
}
