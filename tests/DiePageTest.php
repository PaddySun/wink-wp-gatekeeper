<?php
/**
 * DiePage wp_die 品牌拦截页单元测试（03 §3：纯 HTML 构造器）。
 *
 * 规格：内联样式（不依赖主题）；logo 130ms 单次眨眼 + prefers-reduced-motion 静态；
 * 证据行等宽字体（Paper 底 / Wink Red 强调 / Blush 提示底）；back_link；
 * 不向访客暴露：IP、原始评论内容、驱动内部错误码细节、云端 reason（LLM 理由只进后台）——
 * 访客可见证据仅事件编号/规则名/时间。wp_die 调用属 WP 边界（show()），build() 为纯函数。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Intercept\DiePage;

final class DiePageTest extends TestCase {

	private static function build( array $evidence = array( 'event_no' => 'WK-20261109-0042', 'rule' => 'banned_word', 'time' => '2026-11-09 21:33:05' ) ): string {
		return DiePage::build(
			'这串字符我们眼熟',
			'这串字符我们眼熟——换句人话再试试？',
			$evidence,
			'admin@example.com',
			'https://example.com/wp-content/plugins/wink/assets/brand/logo-wink.svg',
			'zh-CN'
		);
	}

	public function test_contains_title_message_and_lang(): void {
		$html = self::build();
		$this->assertStringContainsString( '<html lang="zh-CN">', $html );
		$this->assertStringContainsString( '这串字符我们眼熟', $html );
		$this->assertStringContainsString( '换句人话再试试', $html );
	}

	public function test_inline_styles_do_not_depend_on_theme(): void {
		$html = self::build();
		$this->assertStringContainsString( '<style>', $html );
		// Paper 底 / Wink Red 强调（03 §3 品牌规范）。
		$this->assertStringContainsString( '#F7F7F8', $html );
		$this->assertStringContainsString( '#FF4D4F', $html );
	}

	public function test_evidence_rows_are_monospace(): void {
		$html = self::build();
		$this->assertStringContainsString( 'WK-20261109-0042', $html );
		$this->assertStringContainsString( 'banned_word', $html );
		$this->assertStringContainsString( 'ui-monospace', $html ); // 等宽「作证」。
	}

	public function test_reduced_motion_is_respected(): void {
		$html = self::build();
		$this->assertStringContainsString( 'prefers-reduced-motion', $html );
	}

	public function test_logo_blink_once_animation_present(): void {
		$html = self::build();
		$this->assertStringContainsString( '130ms', $html ); // 单次眨眼 130ms。
		$this->assertStringContainsString( 'logo-wink.svg', $html );
	}

	public function test_back_link_present(): void {
		$html = self::build();
		$this->assertStringContainsString( '返回', $html );
	}

	public function test_contact_email_shown_when_provided(): void {
		$html = self::build();
		$this->assertStringContainsString( 'admin@example.com', $html );
	}

	public function test_contact_line_omitted_when_email_empty(): void {
		$html = DiePage::build( '标题', '文案', array(), '', 'https://x/logo.svg', 'zh-CN' );
		$this->assertStringNotContainsString( '联系站长', $html );
	}

	public function test_user_values_are_escaped(): void {
		$html = DiePage::build(
			'<script>t</script>',
			'内容 <b>加粗</b>',
			array( 'rule' => '<img src=x onerror=alert(1)>' ),
			'',
			'https://x/logo.svg',
			'zh-CN'
		);
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_empty_evidence_renders_no_evidence_block(): void {
		$html = DiePage::build( '标题', '文案', array(), '', 'https://x/logo.svg', 'zh-CN' );
		$this->assertStringNotContainsString( '事件编号', $html );
	}

	/* -----------------------------------------------------------------
	 * FIX-ε（M2 debug 2026-11-10）：wp_die 宿主骨架样式中和
	 * 证据：tests/e2e/testkit/evidence/u2-1-tooshort.html——wp_die 把整段 build()
	 * 嵌进自身骨架 <body id="error-page"><div class="wp-die-message">，宿主
	 * body{max-width:700px;border;box-shadow;margin:2em auto} 与
	 * #error-page{margin-top:50px}（id 特异性 1,0,0）压过我们的元素选择器，
	 * body 被钉在 700px 贴左盒内——flex 居中只在盒内生效（U2 #2 拦截页靠左）。
	 * ---------------------------------------------------------------- */

	public function test_host_skeleton_styles_are_neutralized(): void {
		$html = self::build();
		// 中和声明须整体出现：id 选择器同特异性压制 + 文档序靠后稳赢级联。
		$this->assertStringContainsString(
			'body#error-page{max-width:none;margin:0;padding:32px 16px;border:0;box-shadow:none;display:flex;justify-content:center;}',
			$html
		);
	}

	public function test_centering_style_present_for_standalone_document(): void {
		// 独立文档语境（无宿主）既有居中不回归：body flex 居中 + 卡片 max-width。
		$html = self::build();
		$this->assertStringContainsString( 'display:flex;justify-content:center;', $html );
		$this->assertStringContainsString( '.wk-card{max-width:520px', $html );
	}

	public function test_neutralizer_sits_after_host_rules_in_nested_document(): void {
		// U2 实拍形态夹具（u2-1-tooshort.html 11-48 行骨架）：宿主样式在前，
		// 我们的 <style> 经 wp_die 嵌套落在其后——中和规则必须在宿主规则之后
		// （文档序靠后 + 特异性不低于 #error-page 才能终结级联争夺）。
		$host_style = '<style>body{background:#fff;border:1px solid #ccd0d4;margin:2em auto;padding:1em 2em;max-width:700px;box-shadow:0 1px 1px rgba(0,0,0,.04);}#error-page{margin-top:50px;}</style>';

		preg_match( '/<style>.*?<\/style>/s', self::build(), $matches );
		$this->assertNotEmpty( $matches, 'build() 须自带 <style> 块' );
		$ours = $matches[0];

		$nested = '<!DOCTYPE html><html lang="zh-Hans"><head><meta charset="utf-8"></head>'
			. '<body id="error-page"><div class="wp-die-message">' . $host_style . $ours . '<div class="wk-card"></div></div></body></html>';

		$host_pos = strpos( $nested, 'max-width:700px' );
		$this->assertNotFalse( $host_pos, '夹具须含宿主 max-width:700px（U2 实拍）' );
		$neutralizer_pos = strpos( $nested, 'body#error-page{' );
		$this->assertNotFalse( $neutralizer_pos, '嵌套文档须含 body#error-page 中和规则' );
		$this->assertGreaterThan( $host_pos, $neutralizer_pos, '中和规则须位于宿主样式之后（文档序靠后才稳赢）' );
	}
	/**
	 * M2 反馈2 T2 回归：存量 option copy 子树缺新键（如 duplicate_comment）时，
	 * resolve_copy 必须回默认文案——修复前 show() 直接索引得 null 致品牌页 500（真机）。
	 */
	public function test_resolve_copy_backfills_missing_keys_from_defaults(): void {
		$stored = array(
			'messages' => array( 'too_short' => '站长自定义的太短文案', 'banned_word' => 123 ),
			'titles'   => array( 'too_short' => '自定义标题' ),
		);

		$resolved = DiePage::resolve_copy( $stored );

		$this->assertSame( '站长自定义的太短文案', $resolved['messages']['too_short'] );
		$this->assertSame( '自定义标题', $resolved['titles']['too_short'] );
		$this->assertSame( Copywork::defaults()['messages']['duplicate_comment'], $resolved['messages']['duplicate_comment'] );
		$this->assertSame( Copywork::defaults()['titles']['duplicate_comment'], $resolved['titles']['duplicate_comment'] );
		$this->assertSame( Copywork::defaults()['messages']['banned_word'], $resolved['messages']['banned_word'] );
	}

	public function test_resolve_copy_empty_tree_yields_full_defaults(): void {
		$resolved = DiePage::resolve_copy( array() );
		$this->assertSame( Copywork::defaults()['messages'], $resolved['messages'] );
		$this->assertSame( Copywork::defaults()['titles'], $resolved['titles'] );
	}
}