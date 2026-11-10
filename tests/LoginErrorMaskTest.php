<?php
/**
 * LoginErrorMask 单元测试（WORDFENCE-ADOPTION B2——登录错误掩码防用户名枚举）。
 *
 * 语义对齐 Wordfence loginSec_maskLoginErrors：**只动呈现层文案**——WP 原生
 * 泄露存在性的错误码（invalid_username/incorrect_password/invalid_email/invalidcombo）
 * 在聚合输出处替换为统一文案；内部错误码不动（F39 双信号分类表依赖内部码）。
 *
 * 复现样本：真机第 4 轮实测「Error: The username wkprobe_nouser is not registered...」
 * 与「Unknown email address. Check again or try the guide.」明文透传（2026-11-10）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\LoginErrorMask;

final class LoginErrorMaskTest extends TestCase {

	/* ---------- 泄露存在性错误码清单 ---------- */

	public function test_masked_codes_cover_enumeration_surface(): void {
		foreach ( array( 'invalid_username', 'incorrect_password', 'invalid_email', 'invalidcombo' ) as $code ) {
			$this->assertTrue( LoginErrorMask::is_masked_code( $code ), '泄露存在性错误码须在掩码清单：' . $code );
		}
	}

	public function test_gate_and_third_party_codes_not_masked(): void {
		// 本闸注入码（F39 语义）与第三方自定义码不在掩码清单——证据行文案不被动。
		$this->assertFalse( LoginErrorMask::is_masked_code( 'wk_captcha_failed' ) );
		$this->assertFalse( LoginErrorMask::is_masked_code( 'wk_captcha_missing' ) );
		$this->assertFalse( LoginErrorMask::is_masked_code( 'wk_bad_signature' ) );
		$this->assertFalse( LoginErrorMask::is_masked_code( '' ) );
		$this->assertFalse( LoginErrorMask::is_masked_code( 'some_third_party_code' ) );
	}

	/* ---------- 掩码替换（只动文案，不产生可区分性） ---------- */

	public function test_login_codes_mask_to_single_uniform_message(): void {
		// 同一统一文案 = 响应不可区分（防以错误文本差异探测用户名存在性）。
		$masked_user    = LoginErrorMask::mask_message( 'invalid_username', 'Error: The username <strong>admin</strong> is not registered on this site. If you are unsure of your username, try your email address instead.' );
		$masked_pass    = LoginErrorMask::mask_message( 'incorrect_password', 'Error: The password you entered for the username <strong>admin</strong> is incorrect.' );
		$this->assertSame( LoginErrorMask::MESSAGE_LOGIN_FAILED, $masked_user );
		$this->assertSame( LoginErrorMask::MESSAGE_LOGIN_FAILED, $masked_pass, 'invalid_username 与 incorrect_password 须掩码为同一文案（不可区分性）' );
		$this->assertStringNotContainsString( 'admin', $masked_user, '掩码文案不得回带用户名' );
	}

	public function test_lostpassword_codes_mask_to_uniform_message(): void {
		// 复现样本：WP 7.x 找回页「Unknown email address. Check again or try the guide.」。
		$masked_email  = LoginErrorMask::mask_message( 'invalid_email', 'Unknown email address. Check again or try the guide.' );
		$masked_combo  = LoginErrorMask::mask_message( 'invalidcombo', '<strong>Error:</strong> There is no account with that username or email address.' );
		$this->assertSame( LoginErrorMask::MESSAGE_RESET_UNAVAILABLE, $masked_email );
		$this->assertSame( LoginErrorMask::MESSAGE_RESET_UNAVAILABLE, $masked_combo );
		$this->assertStringNotContainsStringIgnoringCase( 'unknown', $masked_email, '掩码文案不得保留「Unknown email address」明文' );
	}

	public function test_non_masked_codes_pass_through_verbatim(): void {
		$native = 'Error: The password you entered for the username admin is incorrect.';
		$this->assertSame( $native, LoginErrorMask::mask_message( 'not_a_masked_code', $native ) );
		$gate_msg = '还差一个眨眼——完成人机验证再点提交。';
		$this->assertSame( $gate_msg, LoginErrorMask::mask_message( 'wk_captcha_missing', $gate_msg ) );
		$this->assertSame( '', LoginErrorMask::mask_message( 'not_a_masked_code', '' ), '非码表码空消息原样透传' );
	}

	/* ---------- 开关默认态 ---------- */

	public function test_mask_enabled_filter_name_is_declared(): void {
		// 保守默认开+可关（docs 未定开关键——任务书授权：报告留档）。WP 边界消费此 filter 名。
		$this->assertSame( 'wink_gatekeeper_login_error_mask_enabled', LoginErrorMask::FILTER_ENABLED );
	}
}
