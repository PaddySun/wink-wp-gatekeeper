<?php
/**
 * CaptchaTriInterpreter 单元测试（02 §2.1【v1.5·C1】verify_tri → Verdict 映射表 + 阈值钳制 + legacy 垫片）。
 *
 * 覆盖：
 *   - clamp_threshold：默认 0.5 / 下限 0.1 / 上限 1.0 钳制（Wordfence captcha.php:58-69 先例）；
 *   - legacy_to_tri：null→pass/1.0；rule 含 remote_/contract_mismatch → error；其余 string→reject；
 *   - interpret 映射表全行（02 §2.1 v1.5 规范性表）；
 *   - pass_limited 降级通道：仅 transport 类 + 限流内放行（verdict=error 落事件、落地放行、
 *     不写 VerifyCache→write_cache=false、rule_hit=remote_transport_degraded）；限流超额按 block；
 *     limiter 未装配（fail-closed）与 semantic/contract 终态恒 block。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\CaptchaTriInterpreter;
use WinkGatekeeper\Verdict;

final class CaptchaTriInterpreterTest extends TestCase {

	/* ---------- clamp_threshold（04 §2 captcha.min_score；02 §2.1 钳制条款） ---------- */

	public function test_clamp_default_when_not_configured(): void {
		$this->assertSame( 0.5, CaptchaTriInterpreter::clamp_threshold( null ) );
		$this->assertSame( 0.5, CaptchaTriInterpreter::clamp_threshold( '' ) );
		$this->assertSame( 0.5, CaptchaTriInterpreter::clamp_threshold( 'abc' ) );
	}

	public function test_clamp_lower_bound_01(): void {
		// 防站长配 0 验证码形同虚设。
		$this->assertSame( 0.1, CaptchaTriInterpreter::clamp_threshold( 0 ) );
		$this->assertSame( 0.1, CaptchaTriInterpreter::clamp_threshold( 0.05 ) );
		$this->assertSame( 0.1, CaptchaTriInterpreter::clamp_threshold( -0.3 ) );
	}

	public function test_clamp_upper_bound_10(): void {
		// 防站长配 1.5 恒拒。
		$this->assertSame( 1.0, CaptchaTriInterpreter::clamp_threshold( 1.5 ) );
		$this->assertSame( 1.0, CaptchaTriInterpreter::clamp_threshold( 99 ) );
	}

	public function test_clamp_passthrough_in_range(): void {
		$this->assertSame( 0.7, CaptchaTriInterpreter::clamp_threshold( 0.7 ) );
		$this->assertSame( 0.1, CaptchaTriInterpreter::clamp_threshold( 0.1 ) );
		$this->assertSame( 1.0, CaptchaTriInterpreter::clamp_threshold( 1.0 ) );
	}

	/* ---------- legacy_to_tri（02 §2.1 v1.5 垫片纯函数） ---------- */

	public function test_legacy_null_maps_to_pass_score_one(): void {
		$tri = CaptchaTriInterpreter::legacy_to_tri( null, array( 'CaptchaCode' => 1 ) );
		$this->assertSame( 'pass', $tri['state'] );
		$this->assertSame( 1.0, $tri['score'] );
		$this->assertSame( '', $tri['message'] );
		$this->assertSame( array( 'CaptchaCode' => 1 ), $tri['meta'] );
	}

	public function test_legacy_remote_rule_string_maps_to_error(): void {
		foreach ( array( 'remote_transport', 'remote_semantic', 'contract_mismatch' ) as $rule ) {
			$tri = CaptchaTriInterpreter::legacy_to_tri( '文案', array( 'rule' => $rule ) );
			$this->assertSame( 'error', $tri['state'], "rule={$rule} 应为 error 态" );
			$this->assertNull( $tri['score'] );
			$this->assertSame( '文案', $tri['message'] );
		}
	}

	public function test_legacy_plain_string_maps_to_reject(): void {
		$tri = CaptchaTriInterpreter::legacy_to_tri( '票据过期', array( 'rule' => 'CaptchaCode=8' ) );
		$this->assertSame( 'reject', $tri['state'] );
		$this->assertNull( $tri['score'] );
		$this->assertSame( '票据过期', $tri['message'] );
	}

	/* ---------- interpret：pass + score≥钳后阈值 ---------- */

	public function test_pass_with_score_at_or_above_threshold(): void {
		$interpreter = new CaptchaTriInterpreter( 0.5 );
		foreach ( array( 0.5, 0.6, 1.0 ) as $score ) {
			$outcome = $interpreter->interpret( array(
				'state'   => 'pass',
				'score'   => $score,
				'message' => '',
				'meta'    => array( 'rule' => 'CaptchaCode=1' ),
			) );
			$this->assertSame( Verdict::PASS, $outcome['verdict'], "score={$score}" );
			$this->assertTrue( $outcome['allow'] );
			$this->assertTrue( $outcome['write_cache'], 'pass 态写 VerifyCache（只存事实不存分数）' );
			$this->assertSame( 'CaptchaCode=1', $outcome['rule'] );
			$this->assertSame( $score, $outcome['meta']['score'], 'pass meta 记 score（排障可查）' );
			$this->assertSame( 0.5, $outcome['meta']['threshold'], 'pass meta 记 threshold' );
		}
	}

	public function test_binary_driver_score_one_passes_any_threshold(): void {
		// 二态驱动恒 score=1.0 → 任何钳后阈值下自动 pass（闸对二态/评分制无感知分流）。
		$interpreter = new CaptchaTriInterpreter( 1.0 );
		$outcome     = $interpreter->interpret( array(
			'state'   => 'pass',
			'score'   => 1.0,
			'message' => '',
			'meta'    => array(),
		) );
		$this->assertSame( Verdict::PASS, $outcome['verdict'] );
	}

	/* ---------- interpret：pass + score<阈值（评分拒） ---------- */

	public function test_pass_below_threshold_maps_to_reject_with_user_retry_tier(): void {
		$interpreter = new CaptchaTriInterpreter( 0.7 );
		$outcome     = $interpreter->interpret( array(
			'state'   => 'pass',
			'score'   => 0.3,
			'message' => '不应使用驱动消息',
			'meta'    => array( 'rule' => 'RiskScore' ),
		) );
		$this->assertSame( Verdict::REJECT, $outcome['verdict'] );
		$this->assertFalse( $outcome['allow'] );
		$this->assertFalse( $outcome['write_cache'] );
		// rule_hit=captcha_failed + 驱动 rule（02 §2.1 映射表第 2 行）。
		$this->assertSame( 'captcha_failed', $outcome['rule'] );
		$this->assertContains( 'captcha_failed', $outcome['hits'] );
		$this->assertContains( 'RiskScore', $outcome['hits'] );
		// user_retry 档：message 留空——闸回落自家 captcha_failed 文案链（给刷新重试台阶）。
		$this->assertSame( '', $outcome['message'] );
		$this->assertSame( 0.3, $outcome['meta']['score'] );
		$this->assertSame( 0.7, $outcome['meta']['threshold'] );
	}

	public function test_contract_zero_score_defensively_maps_to_reject(): void {
		// 契约：0 分/解析不出分按 reject 处理，0.0 永不出现在本契约——防御性兜底。
		$interpreter = new CaptchaTriInterpreter( 0.5 );
		foreach ( array( 0.0, null, 'nan' ) as $bad_score ) {
			$outcome = $interpreter->interpret( array(
				'state'   => 'pass',
				'score'   => $bad_score,
				'message' => '',
				'meta'    => array(),
			) );
			$this->assertSame( Verdict::REJECT, $outcome['verdict'], 'score=' . var_export( $bad_score, true ) );
		}
	}

	/* ---------- interpret：reject（业务拒绝） ---------- */

	public function test_reject_state_maps_to_reject_with_driver_rule(): void {
		$interpreter = new CaptchaTriInterpreter( 0.5 );
		$outcome     = $interpreter->interpret( array(
			'state'   => 'reject',
			'score'   => null,
			'message' => '刷新页面再试一次。',
			'meta'    => array( 'rule' => 'CaptchaCode=8' ),
		) );
		$this->assertSame( Verdict::REJECT, $outcome['verdict'] );
		$this->assertFalse( $outcome['allow'] );
		$this->assertFalse( $outcome['write_cache'] );
		$this->assertSame( 'CaptchaCode=8', $outcome['rule'] );
		$this->assertSame( array( 'CaptchaCode=8' ), $outcome['hits'] );
		$this->assertSame( '刷新页面再试一次。', $outcome['message'], '驱动终态文案直通' );
	}

	/* ---------- interpret：error（四类终态）——C1 统计纠偏点 ---------- */

	public function test_error_state_maps_to_error_verdict_not_reject(): void {
		$interpreter = new CaptchaTriInterpreter( 0.5 );
		$outcome     = $interpreter->interpret( array(
			'state'   => 'error',
			'score'   => null,
			'message' => '验证服务暂时打不开。',
			'meta'    => array( 'category' => 'transport', 'rule' => 'remote_transport' ),
		) );
		// 行为变化点 1：error 态不再映射 reject（captcha_reject 不混入故障量）。
		$this->assertSame( Verdict::ERROR, $outcome['verdict'] );
		$this->assertFalse( $outcome['allow'] );
		$this->assertSame( 'remote_transport', $outcome['rule'] );
		$this->assertSame( '验证服务暂时打不开。', $outcome['message'] );
	}

	public function test_error_contract_mismatch_maps_to_error(): void {
		$interpreter = new CaptchaTriInterpreter( 0.5 );
		$outcome     = $interpreter->interpret( array(
			'state'   => 'error',
			'score'   => null,
			'message' => '配置有误。',
			'meta'    => array( 'category' => 'contract', 'rule' => 'contract_mismatch' ),
		) );
		$this->assertSame( Verdict::ERROR, $outcome['verdict'] );
		$this->assertSame( 'contract_mismatch', $outcome['rule'] );
	}

	public function test_error_semantic_terminal_never_degrades_even_with_pass_limited(): void {
		// semantic/contract/parse 终态恒 block（配置错放行=自废武功）——errorPolicy 只豁免 transport。
		$limiter_calls = 0;
		$interpreter   = new CaptchaTriInterpreter( 0.5, 'pass_limited', static function ( string $ip ) use ( &$limiter_calls ): bool {
			$limiter_calls++;
			return true;
		} );
		foreach ( array( 'semantic', 'contract', 'parse' ) as $category ) {
			$outcome = $interpreter->interpret( array(
				'state'   => 'error',
				'score'   => null,
				'message' => '配置有误。',
				'meta'    => array( 'category' => $category, 'rule' => 'remote_' . $category ),
			), '1.2.3.4' );
			$this->assertFalse( $outcome['allow'], "category={$category} 恒 block" );
			$this->assertSame( 0, $limiter_calls, "category={$category} 不进限流器" );
		}
	}

	public function test_unknown_state_defensively_maps_to_error(): void {
		$interpreter = new CaptchaTriInterpreter( 0.5 );
		$outcome     = $interpreter->interpret( array(
			'state'   => 'weird',
			'score'   => null,
			'message' => '',
			'meta'    => array(),
		) );
		$this->assertSame( Verdict::ERROR, $outcome['verdict'] );
		$this->assertSame( 'contract_mismatch', $outcome['rule'] );
	}

	/* ---------- interpret：error + pass_limited 降级放行通道 ---------- */

	public function test_pass_limited_transport_within_quota_degrades_open(): void {
		$limited_ips = array();
		$interpreter = new CaptchaTriInterpreter( 0.5, 'pass_limited', static function ( string $ip ) use ( &$limited_ips ): bool {
			$limited_ips[] = $ip;
			return true;
		} );

		$outcome = $interpreter->interpret( array(
			'state'   => 'error',
			'score'   => null,
			'message' => '验证服务暂时打不开。',
			'meta'    => array( 'category' => 'transport', 'rule' => 'remote_transport', 'raw' => array( 'http' => 0 ) ),
		), '203.0.113.9' );

		// 事件 verdict=error + 落地放行（02 §2.1 映射表末行）。
		$this->assertSame( Verdict::ERROR, $outcome['verdict'], '事件口径仍为 error' );
		$this->assertTrue( $outcome['allow'], '落地放行' );
		$this->assertFalse( $outcome['write_cache'], '降级放行不写 VerifyCache（02 §2.8 五件套之五同源）' );
		$this->assertSame( 'remote_transport_degraded', $outcome['rule'], 'rule_hit=remote_transport_degraded' );
		$this->assertSame( array( '203.0.113.9' ), $limited_ips, '限流器按 IP 消费' );
		$this->assertSame( 'remote_transport', $outcome['meta']['rule'], '原 rule 保留在 meta 供排障' );
	}

	public function test_pass_limited_over_quota_blocks(): void {
		$interpreter = new CaptchaTriInterpreter( 0.5, 'pass_limited', static function ( string $ip ): bool {
			return false; // 限流超额按 block（容灾限速超额先例）。
		} );
		$outcome = $interpreter->interpret( array(
			'state'   => 'error',
			'score'   => null,
			'message' => '验证服务暂时打不开。',
			'meta'    => array( 'category' => 'transport', 'rule' => 'remote_transport' ),
		), '1.2.3.4' );
		$this->assertSame( Verdict::ERROR, $outcome['verdict'] );
		$this->assertFalse( $outcome['allow'] );
		$this->assertSame( 'remote_transport', $outcome['rule'] );
	}

	public function test_pass_limited_without_limiter_fails_closed(): void {
		$interpreter = new CaptchaTriInterpreter( 0.5, 'pass_limited', null );
		$outcome     = $interpreter->interpret( array(
			'state'   => 'error',
			'score'   => null,
			'message' => '',
			'meta'    => array( 'category' => 'transport', 'rule' => 'remote_transport' ),
		), '1.2.3.4' );
		$this->assertFalse( $outcome['allow'], '限流器未装配=不可放行（fail-closed）' );
	}

	public function test_pass_limited_without_ip_fails_closed(): void {
		$called = false;
		$interpreter = new CaptchaTriInterpreter( 0.5, 'pass_limited', static function () use ( &$called ): bool {
			$called = true;
			return true;
		} );
		$outcome = $interpreter->interpret( array(
			'state'   => 'error',
			'score'   => null,
			'message' => '',
			'meta'    => array( 'category' => 'transport', 'rule' => 'remote_transport' ),
		), '' );
		$this->assertFalse( $outcome['allow'], '无 IP 可归因限额=不放行' );
		$this->assertFalse( $called );
	}

	public function test_block_policy_default_ignores_limiter(): void {
		$called = false;
		$interpreter = new CaptchaTriInterpreter( 0.5, 'block', static function () use ( &$called ): bool {
			$called = true;
			return true;
		} );
		$outcome = $interpreter->interpret( array(
			'state'   => 'error',
			'score'   => null,
			'message' => '拦。',
			'meta'    => array( 'category' => 'transport', 'rule' => 'remote_transport' ),
		), '1.2.3.4' );
		$this->assertFalse( $outcome['allow'], '默认 block（拍板：天御零行为变化）' );
		$this->assertFalse( $called );
	}

	public function test_error_policy_from_driver_via_site_override_shape(): void {
		// 站长覆盖键 captcha.error_policy 消费形态：非法值归 block（04 §2 枚举校验同语义）。
		$this->assertSame( Verdict::ERROR, ( new CaptchaTriInterpreter( 0.5, 'nonsense', null ) )->interpret( array(
			'state' => 'error', 'score' => null, 'message' => '', 'meta' => array( 'category' => 'transport', 'rule' => 'remote_transport' ),
		) )['verdict'] );
	}
}
