<?php
/**
 * W2 · 「无按钮模式」契约测试（2026-11-18，施工图 docs/W2-无按钮模式-设计草案-2026-11-18
 * §5.1 批 W2-1 / §5.2 测试计划；拍板 Q1=A 页面加载即渲染 · Q2=①一刀切默认 on_submit ·
 * Q6=腾讯同批 · Q7=0.8.4）。
 *
 * 契约面（批 W2-1 = schema/开关/PHP render 面）：
 *   ① schema：captcha.submit_flow 新键出厂默认 'on_submit'（一刀切——存量站点经
 *      merge_defaults 补键即切新形态，H-2/H-3 breaking 默认平移先例）；显式 'button'
 *      存量值原样保留（经典形态回退通道）。
 *   ② Sanitize：'captcha.submit_flow' => 'submit_flow' 白名单（on_submit|button，
 *      非法值归 on_submit=新形态默认）。
 *   ③ render 契约：on_submit 形态 = 容器 data-submit-gate="1"（gatekeeper.js submit-gate
 *      拦截链装配锚）+ 按钮 hidden 属性（SDK button 参数仍绑真实元素——静态推断 Q3）；
 *      wk_stub/票据域/embed 挂载点/noscript 原样；button 形态产物与 0.8.3 逐字节同构
 *      （经典回归锚）。Q6 腾讯同批：TencentCaptchaDriver::render 同款分支。
 *   ④ Tab② 「提交触发形态」radio 组（SettingsPage render_tab_captcha source-scan）。
 *   ⑤ front-gate.css：.wk-captcha-btn[hidden]{display:none}（必做——.wk-captcha-btn
 *      的 display:inline-block 会压过 hidden 属性，同 mount.hidden 课程同族坑）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\captcha\AliyunCaptchaDriver;
use WinkGatekeeper\Drivers\captcha\TencentCaptchaDriver;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Settings\Sanitize;
use WinkGatekeeper\Settings\SettingsSchema;

final class W2NoButtonModeTest extends TestCase {

	/* ---------- 工具 ---------- */

	private static function source( string $rel ): string {
		$src = file_get_contents( dirname( __DIR__ ) . '/' . $rel );
		self::assertIsString( $src, "{$rel} 必须可读" );
		return (string) $src;
	}

	/**
	 * 提取具名 PHP 函数体（花括号配平）——UI 契约断言按函数作用域收窄（M6HUiSemantics 同法）。
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
		$this->fail( "函数 {$name} 花括号不配平" );
		return '';
	}

	private static function client_returning( $raw ): RemoteClient {
		return new RemoteClient( static function () use ( $raw ) {
			return $raw;
		}, null, static function (): void {} );
	}

	/** 阿里驱动（submit_flow 可注入；其余凭据同 AliyunCaptchaDriverTest helper 形态）。 */
	private static function aliyun( ?string $submit_flow = null, string $mode = 'embed' ): AliyunCaptchaDriver {
		$aliyun = array( 'access_key_id' => 'LTAIkeksi', 'access_key_secret' => 'sekreto', 'scene_id' => 'scene-9', 'prefix' => 'wkpre', 'mode' => $mode );
		$captcha = array( 'aliyun' => $aliyun );
		if ( null !== $submit_flow ) {
			$captcha['submit_flow'] = $submit_flow;
		}
		return new AliyunCaptchaDriver( array(
			'captcha' => $captcha,
			'copy'    => array( 'messages' => array() ),
			'brand'   => array( 'contact_email' => 'admin@example.com' ),
		), self::client_returning( array( 'response' => array( 'code' => 500 ), 'body' => '' ) ) );
	}

	/** 天御驱动（Q6 腾讯同批——render 同款 submit_flow 分支）。 */
	private static function tencent( ?string $submit_flow = null ): TencentCaptchaDriver {
		$tencent  = array( 'app_id' => 199999164, 'secret_id' => 'AKIDx', 'secret_key' => 'sk', 'app_secret_key' => 'ASK' );
		$captcha  = array( 'tencent' => $tencent );
		if ( null !== $submit_flow ) {
			$captcha['submit_flow'] = $submit_flow;
		}
		return new TencentCaptchaDriver( array(
			'captcha' => $captcha,
			'copy'    => array( 'messages' => array() ),
			'brand'   => array( 'contact_email' => 'admin@example.com' ),
		), self::client_returning( array( 'response' => array( 'code' => 500 ), 'body' => '' ) ) );
	}

	/* ---------- ① schema：submit_flow 新键 + 一刀切默认（Q2=①） ---------- */

	public function test_schema_submit_flow_defaults_to_on_submit(): void {
		$this->assertSame(
			'on_submit',
			SettingsSchema::defaults()['captcha']['submit_flow'],
			'【Q2=①一刀切】出厂默认 on_submit（无按钮模式）——新装与存量升级同切新形态'
		);
	}

	public function test_merge_defaults_backfills_submit_flow_for_legacy_options(): void {
		// 存量树无此键（0.8.3 及以前）→ merge_defaults 补新默认（一刀切升级语义，零迁移代码）。
		$merged = SettingsSchema::merge_defaults( array( 'captcha' => array( 'driver' => 'aliyun' ) ) );
		$this->assertSame( 'on_submit', $merged['captcha']['submit_flow'], '存量补键=升级即切新形态（H-2/H-3 breaking 默认平移先例）' );
	}

	public function test_merge_defaults_preserves_explicit_button_flow(): void {
		// 站长显式回退经典形态 → 原样保留（Tab② radio 写入值不被 merge 覆盖）。
		$merged = SettingsSchema::merge_defaults( array( 'captcha' => array( 'submit_flow' => 'button' ) ) );
		$this->assertSame( 'button', $merged['captcha']['submit_flow'], '显式 button（经典）原样保留' );
	}

	/* ---------- ② Sanitize 白名单 ---------- */

	public function test_sanitize_rule_type_is_submit_flow(): void {
		$this->assertSame( 'submit_flow', Sanitize::rules()['captcha.submit_flow'], '规则表新类型（aliyun_mode 同款白名单形态）' );
	}

	public function test_sanitize_submit_flow_whitelist(): void {
		$this->assertSame( 'on_submit', Sanitize::clean_value( 'on_submit', 'submit_flow' ) );
		$this->assertSame( 'button', Sanitize::clean_value( 'button', 'submit_flow' ), '经典形态显式值保留' );
		$this->assertSame( 'on_submit', Sanitize::clean_value( 'no_button', 'submit_flow' ), '非法值归 on_submit（新形态默认）' );
		$this->assertSame( 'on_submit', Sanitize::clean_value( '', 'submit_flow' ) );
		$this->assertSame( 'on_submit', Sanitize::clean_value( null, 'submit_flow' ) );

		list( $clean, ) = Sanitize::clean( array( 'captcha' => array( 'submit_flow' => 'button' ) ) );
		$this->assertSame( 'button', $clean['captcha']['submit_flow'], '整树清洗：白名单类型对原始标量比对（aliyun_mode 同款语义）' );
	}

	/* ---------- ③ render 契约：on_submit（默认形态） ---------- */

	public function test_aliyun_render_on_submit_hides_button_and_marks_gate(): void {
		// 默认构造（settings 无 submit_flow 键）即 on_submit——一刀切语义在驱动读取层同向。
		$html = self::aliyun()->render( array( 'gate' => 'comment', 'stub' => 'STUBSIG' ) );

		$this->assertStringContainsString( 'data-submit-gate="1"', $html, '容器 submit-gate 装配锚（gatekeeper.js 拦截链）' );
		$this->assertMatchesRegularExpression(
			'/<button type="button" class="wk-captcha-btn wk-verify-btn button" data-wk-state="unverified" hidden aria-live="polite">/',
			$html,
			'按钮带 hidden 属性（SDK button 参数仍绑真实元素——静态推断 Q3 真机复验）'
		);
		// 既有面原样（§0 W-7：wk_stub/票据域/embed 挂载点/noscript 不动）。
		$this->assertStringContainsString( 'name="wk_stub" value="STUBSIG"', $html );
		$this->assertStringContainsString( 'name="wk_captcha_verify_param"', $html );
		$this->assertStringContainsString( 'class="wk-gate-embed"', $html );
		$this->assertStringContainsString( 'id="wk-captcha-element-comment"', $html );
		$this->assertStringContainsString( 'data-interaction="click"', $html, '分派契约不翻转（R1：适配器交互声明照旧）' );
	}

	public function test_aliyun_render_on_submit_popup_mode_has_no_mount(): void {
		$html = self::aliyun( null, 'popup' )->render( array( 'gate' => 'login', 'stub' => 'S' ) );
		$this->assertStringContainsString( 'data-submit-gate="1"', $html );
		$this->assertMatchesRegularExpression( '/wk-captcha-btn[^>]*hidden/', $html );
		$this->assertStringNotContainsString( 'wk-gate-embed', $html, 'popup 形态零占位（无挂载点）' );
	}

	public function test_aliyun_render_button_flow_is_byte_identical_classic(): void {
		// 经典形态（button）产物与 0.8.3 逐字节同构——经典回归锚（含 embed 挂载点尾段）。
		$expected = '<span class="wk-gate" data-gate="comment" data-driver="aliyun"'
			. ' data-interaction="click"'
			. ' data-mode="embed"'
			. ' data-token-fields="wk_captcha_verify_param"'
			. ' data-scene-id="scene-9"'
			. ' data-prefix="wkpre">'
			. '<input type="hidden" name="wk_stub" value="STUBSIG">'
			. '<input type="hidden" name="wk_captcha_verify_param" value="">'
			. '<span class="wk-gate-inline">'
			. '<button type="button" class="wk-captcha-btn wk-verify-btn button" data-wk-state="unverified"'
			. ' aria-live="polite">完成人机验证</button>'
			. '</span>'
			. '<noscript>需要启用 JavaScript 完成人机验证。</noscript>'
			. '</span>'
			. '<div class="wk-gate-embed" hidden><div id="wk-captcha-element-comment"></div></div>';
		$this->assertSame( $expected, self::aliyun( 'button' )->render( array( 'gate' => 'comment', 'stub' => 'STUBSIG' ) ), 'button 形态产物与 0.8.3 逐字节同构（经典回归锚）' );
	}

	/* ---------- ③b render 契约：Q6 腾讯同批 ---------- */

	public function test_tencent_render_on_submit_hides_button_and_marks_gate(): void {
		$html = self::tencent()->render( array( 'gate' => 'comment', 'stub' => 's' ) );
		$this->assertStringContainsString( 'data-submit-gate="1"', $html, '天御同款分支（Q6 腾讯同批收口）' );
		$this->assertMatchesRegularExpression( '/<button type="button" class="wk-captcha-btn wk-verify-btn button" data-wk-state="unverified" hidden aria-live="polite">/', $html );
		$this->assertStringContainsString( 'name="wk_captcha_ticket"', $html, '双票据写域原样' );
		$this->assertStringContainsString( '<div class="wk-gate-embed" hidden></div>', $html, '嵌入挂载点原样' );
	}

	public function test_tencent_render_button_flow_keeps_classic_shape(): void {
		$html = self::tencent( 'button' )->render( array( 'gate' => 'comment', 'stub' => 's' ) );
		$this->assertStringNotContainsString( 'data-submit-gate', $html, '经典形态零 submit-gate 锚' );
		$this->assertDoesNotMatchRegularExpression( '/wk-captcha-btn[^>]*hidden aria-live/', $html, '经典形态按钮不带 hidden' );
	}

	public function test_render_never_leaks_secrets_w2(): void {
		// 红线照抄（AliyunCaptchaDriverTest:342 同款——新形态下重钉）。
		$html = self::aliyun()->render( array( 'gate' => 'comment', 'stub' => 'S' ) );
		$this->assertStringNotContainsString( 'LTAIkeksi', $html, 'AccessKeyId 绝不出前端' );
		$this->assertStringNotContainsString( 'sekreto', $html, 'AccessKeySecret 绝不出前端' );
	}

	/* ---------- ④ Tab② 「提交触发形态」radio 组 ---------- */

	public function test_settings_page_tab2_submit_flow_radio(): void {
		$body = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_tab_captcha' );

		$this->assertStringContainsString( '提交触发形态', $body, 'Tab② radio 组标题（「前端形态（mode）」旁）' );
		$this->assertStringContainsString( "'captcha', 'submit_flow'", $body, '字段名 captcha.submit_flow（schema 同位）' );
		$this->assertStringContainsString( '无按钮模式', $body, 'on_submit 选项文案（默认形态）' );
		$this->assertStringContainsString( '独立验证按钮', $body, 'button 选项文案（经典回退）' );
		$this->assertSame(
			2,
			substr_count( $body, "'captcha', 'submit_flow'" ),
			'radio 组恰好一组（两选项共用同名 name——不多不少）'
		);
	}

	/* ---------- ⑤ front-gate.css hidden 补丁 ---------- */

	public function test_front_gate_css_hides_hidden_captcha_button(): void {
		$css = self::source( 'assets/css/front-gate.css' );
		$this->assertStringContainsString( '.wk-captcha-btn[hidden]', $css, 'hidden 属性选择器在场' );
		$this->assertMatchesRegularExpression(
			'/\.wk-captcha-btn\[hidden\][^{]*\{[^}]*display:\s*none/',
			$css,
			'display:none 必做——.wk-captcha-btn 的 display:inline-block 会压过 hidden 属性（mount.hidden 课程同族坑）'
		);
	}
}
