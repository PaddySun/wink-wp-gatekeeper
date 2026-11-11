<?php
/**
 * Tab⑥ 账户四维人话小字测试（03 Tab⑥ v1.5 口径 + UI 批 1 术语翻译）。
 *
 * 04 §4 口径不变（调用 N=captcha_pass+captcha_reject+captcha 阶段 error；放行=
 * captcha_pass；拦下=reject 全阶段；密码输错=attempts），展示词走 Labels 人话映射
 * （UI 批 1·N5——「调用/通过/拦截/登录失败尝试」黑话退役）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Stats\InterceptDigest;
use WinkGatekeeper\Stats\Labels;

final class InterceptDigestUsageTest extends TestCase {

	public function test_account_usage_note_combines_four_dimensions(): void {
		$note = InterceptDigest::account_usage_note( array(
			'captcha_pass'   => 3,
			'captcha_reject' => 2,
			'error'          => 1,
			'reject'         => 4, // 全阶段拦下（captcha 2 + stub 2 的形态）。
			'attempts'       => 7,
		) );
		$this->assertMatchesRegularExpression( '/人机验证 6 次/u', $note, '人机验证 N = captcha_pass+captcha_reject+captcha 阶段 error（04 §4 口径）' );
		$this->assertMatchesRegularExpression( '/验证放行 3 人/u', $note );
		$this->assertMatchesRegularExpression( '/拦下 4 次/u', $note, '拦下 K = 既有 reject 维（全阶段）' );
		$this->assertMatchesRegularExpression( '/密码输错 7 次/u', $note );
		$this->assertStringNotContainsString( '调用', $note, '「调用」黑话退役（对照表 1）' );
		$this->assertStringNotContainsString( '登录失败尝试', $note, '「登录失败尝试」黑话退役（对照表 6）' );
	}

	public function test_account_usage_note_zero_state_is_explicit(): void {
		// 恒 0 也可读（Tab⑥「恒 0 不可区分」教训——零值显式展示）。
		$note = InterceptDigest::account_usage_note( array() );
		$this->assertMatchesRegularExpression( '/人机验证 0 次/u', $note );
		$this->assertMatchesRegularExpression( '/验证放行 0 人/u', $note );
		$this->assertMatchesRegularExpression( '/拦下 0 次/u', $note );
		$this->assertMatchesRegularExpression( '/密码输错 0 次/u', $note );
	}

	/* ---------- Labels 词表与四维小字的一致性（单一来源钉） ---------- */

	public function test_usage_note_words_come_from_labels_map(): void {
		$note = InterceptDigest::account_usage_note( array(
			'captcha_pass'   => 1,
			'captcha_reject' => 1,
			'error'          => 1,
			'reject'         => 5,
			'attempts'       => 2,
		) );
		$this->assertStringContainsString( Labels::action_word( 'captcha' ), $note );
		$this->assertStringContainsString( Labels::pass_word(), $note );
		$this->assertStringContainsString( Labels::reject_word(), $note );
		$this->assertStringContainsString( Labels::attempts_word(), $note );
	}
}
