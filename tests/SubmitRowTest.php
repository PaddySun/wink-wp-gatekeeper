<?php
/**
 * 前台形态度测试（03 §2.1 v1.3 重写：并排自适应 + 双态按钮 + 渐进增强 DOM）。
 *
 * 覆盖：
 *   - SubmitRow 纯注入逻辑：验证按钮容器进提交按钮同级（同一 <p> 内、提交控件之前）——
 *     主题 submit input/button 两形态；无提交控件时回退前置（堆叠兼容）；
 *   - 驱动 render() 新 DOM：span 容器（inline 级，可入提交段落）+ 双 token 写域 +
 *     双态按钮（unverified/verified）+ 成功态 span（hidden）+ 状态字 + noscript + 密钥零泄漏。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\captcha\TencentCaptchaDriver;
use WinkGatekeeper\Gates\SubmitRow;
use WinkGatekeeper\Http\RemoteClient;

final class SubmitRowTest extends TestCase {

	/* ---------- SubmitRow：并排注入（纯逻辑） ---------- */

	public function test_injects_gate_before_submit_input_inside_same_paragraph(): void {
		$submit = '<p class="form-submit"><input name="submit" type="submit" id="submit" class="submit" value="发表评论" /> <input type="hidden" name="comment_post_ID" value="4" id="comment_post_ID" /></p>';
		$gate   = '<span class="wk-gate" data-gate="comment">GATE</span>';

		$out = SubmitRow::inject( $submit, $gate );

		$this->assertStringContainsString( '<p class="form-submit">', $out );
		$this->assertLessThan(
			strpos( $out, 'type="submit"' ),
			strpos( $out, 'wk-gate' ),
			'验证按钮容器必须在提交控件之前、同一提交段落之内（并排 flex 行）'
		);
		$this->assertGreaterThan(
			strpos( $out, '<p class="form-submit">' ),
			strpos( $out, 'wk-gate' ),
			'容器在段落开标签之后（内部注入，非前置段落）'
		);
		$this->assertStringContainsString( 'comment_post_ID', $out, '主题隐藏域原样保留' );
	}

	public function test_injects_before_button_type_submit(): void {
		$submit = '<p class="form-submit"><button type="submit" id="submit" class="btn">发表评论</button></p>';
		$out    = SubmitRow::inject( $submit, '<span class="wk-gate">GATE</span>' );
		$this->assertLessThan( strpos( $out, 'type="submit"' ), strpos( $out, 'wk-gate' ) );
	}

	public function test_falls_back_to_prepend_without_submit_control(): void {
		$submit = '<p class="nobody-here">no submit control</p>';
		$gate   = '<span class="wk-gate">GATE</span>';
		$this->assertSame(
			$gate . "\n" . $submit,
			SubmitRow::inject( $submit, $gate ),
			'无提交控件（主题自定义形态）→ 回退 v1.2 前置形态'
		);
	}

	public function test_submit_control_after_other_attributes_is_found(): void {
		$submit = '<p class="form-submit"><input name="submit" id="submit" class="submit" type="submit" value="Post" /></p>';
		$out    = SubmitRow::inject( $submit, '<span class="wk-gate">G</span>' );
		$this->assertLessThan( strpos( $out, 'type="submit"' ), strpos( $out, 'wk-gate' ) );
	}

	/* ---------- 驱动 render()：v1.3 目标 DOM ---------- */

	private static function driver(): TencentCaptchaDriver {
		$settings = array(
			'captcha' => array( 'tencent' => array(
				'app_id' => '199999164', 'secret_id' => 'AKIDx', 'secret_key' => 'sk-never-leak', 'app_secret_key' => 'ASK-never-leak',
			) ),
			'copy'    => array( 'messages' => array() ),
			'brand'   => array( 'contact_email' => '' ),
		);
		return new TencentCaptchaDriver(
			$settings,
			new RemoteClient( static function (): array {
				return array( 'response' => array( 'code' => 500 ), 'body' => '' );
			}, null, static function (): void {} )
		);
	}

	public function test_render_uses_inline_span_container(): void {
		// span（phrasing content）才可合法注入主题提交段落 <p> 内（并排布局前提）。
		$html = self::driver()->render( array( 'gate' => 'comment', 'context_id' => '4', 'stub' => 'STUB' ) );
		$this->assertStringStartsWith( '<span class="wk-gate"', $html );
	}

	public function test_render_contains_stub_and_both_token_write_fields(): void {
		$html = self::driver()->render( array( 'gate' => 'comment', 'context_id' => '4', 'stub' => 'STUB123' ) );
		$this->assertStringContainsString( 'name="wk_stub" value="STUB123"', $html );
		$this->assertStringContainsString( 'name="wk_captcha_ticket"', $html, 'ticket 写域在 DOM（适配器填充）' );
		$this->assertStringContainsString( 'name="wk_captcha_randstr"', $html );
	}

	/* ---------- M2-FIX2 D1 v1.4：三态单按钮 + 嵌入式挂载点 ---------- */

	public function test_render_three_state_single_button_no_status_or_badge(): void {
		$html = self::driver()->render( array( 'gate' => 'comment', 'context_id' => '4', 'stub' => 'S' ) );

		// 三态单按钮：验证前态文案、挂主题通用 button 类 + 自有类、data-wk-state、aria-live 上按钮。
		$this->assertStringContainsString( 'type="button"', $html );
		$this->assertStringContainsString( 'wk-verify-btn', $html );
		$this->assertMatchesRegularExpression( '/class="[^"]*\bbutton\b[^"]*"/', $html, '挂主题通用 .button 类（样式向提交按钮看齐）' );
		$this->assertStringContainsString( 'data-wk-state="unverified"', $html );
		$this->assertStringContainsString( '完成人机验证', $html );
		$this->assertMatchesRegularExpression(
			'/<button[^>]*aria-live="polite"/',
			$html,
			'aria-live 播报位在按钮上（三态文案即播报内容，M2-FIX2 用户反馈②）'
		);

		// 不再渲染独立状态字与成功态徽标（全部反馈由单按钮承载）。
		$this->assertStringNotContainsString( 'wk-captcha-status', $html, '独立状态字已移除（v1.4）' );
		$this->assertStringNotContainsString( 'wk-verify-done', $html, '成功态徽标已移除（v1.4）' );
		$this->assertStringNotContainsString( '✓ 已通过验证', $html );
		$this->assertStringContainsString( '<noscript>', $html, 'noscript 无 JS 提示保留' );
	}

	public function test_render_comment_gate_appends_embed_mount_below_button(): void {
		$html = self::driver()->render( array( 'gate' => 'comment', 'context_id' => '4', 'stub' => 'S' ) );

		$this->assertStringContainsString( 'data-mode="embed"', $html, '评论域声明嵌入形态（适配器分派依据）' );
		$this->assertMatchesRegularExpression(
			'/<div class="wk-gate-embed"[^>]*\shidden[^>]*><\/div>/',
			$html,
			'嵌入式挂载点 div 存在且初始隐藏（挑战面板渲染位，M2-FIX2 用户反馈①）'
		);
		$this->assertGreaterThan(
			strpos( $html, '</span>' ),
			strpos( $html, 'wk-gate-embed' ),
			'挂载点是 span 容器的块级兄弟段（SubmitRow 据此放到提交按钮下方）'
		);
	}

	public function test_render_login_gate_now_uses_embed_with_mount(): void {
		$html = self::driver()->render( array( 'gate' => 'login', 'context_id' => 'login', 'stub' => 'S' ) );

		/* 【v1.4·R3/05 §1.1】embed gate 白名单扩展：login → embed + 尾随挂载点（登录页
		 * 嵌入式 03 §2.2；「隐藏包裹+提交时唤起弹层」旧形态作废）。 */
		$this->assertStringContainsString( 'data-mode="embed"', $html, '登录域改嵌入式（gate 白名单）' );
		$this->assertStringContainsString( 'wk-gate-embed', $html, '登录域尾随嵌入挂载点' );
	}

	public function test_inject_places_embed_mount_after_submit_control(): void {
		$submit = '<p class="form-submit"><input name="submit" type="submit" id="submit" class="submit" value="发表评论" /> <input type="hidden" name="comment_post_ID" value="4" /></p>';
		$driver = self::driver();
		$gate   = $driver->render( array( 'gate' => 'comment', 'context_id' => '4', 'stub' => 'S' ) );

		$out = SubmitRow::inject( $submit, $gate );

		$this->assertLessThan(
			strpos( $out, 'type="submit"' ),
			strpos( $out, 'wk-gate"' ),
			'行内 span（验证按钮）仍在提交控件之前（并排 flex 行）'
		);
		$this->assertGreaterThan(
			strpos( $out, 'type="submit"' ),
			strpos( $out, 'wk-gate-embed' ),
			'嵌入式挂载点必须在提交控件之后（按钮下方，M2-FIX2 用户反馈①）'
		);
		$this->assertStringContainsString( 'comment_post_ID', $out, '主题隐藏域原样保留' );
	}

	public function test_render_inline_wrapper_groups_button(): void {
		$html = self::driver()->render( array( 'gate' => 'comment', 'context_id' => '4', 'stub' => 'S' ) );
		$this->assertStringContainsString( 'wk-gate-inline', $html, '并排行内包裹（flex 容器：验证按钮）' );
	}

	public function test_render_never_leaks_secrets(): void {
		$html = self::driver()->render( array( 'gate' => 'comment', 'context_id' => '4', 'stub' => 'S' ) );
		$this->assertStringNotContainsString( 'sk-never-leak', $html );
		$this->assertStringNotContainsString( 'ASK-never-leak', $html );
		$this->assertStringNotContainsString( 'AKIDx', $html, 'CAM SecretId 同属服务端密钥（02 §7.4 红线）' );
	}
}
