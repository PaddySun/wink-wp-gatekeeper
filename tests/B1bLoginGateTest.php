<?php
/**
 * B1b 登录域单元测试（B3 认证失败随机 sleep + attempts 维计数门控）。
 *
 * B3 语义（02 §3.2 v1.5·WORDFENCE-ADOPTION）：认证失败分支随机延迟 0–2000ms
 * （时序混淆防用户名枚举侧信道）；量级注记已入 02——sleep 占用 PHP worker 0–2s，
 * 低并发可接受，高流量/共享主机经 filter `wink_gatekeeper_login_fail_sleep_enabled`
 * 关闭（默认 true）。纯逻辑可测面 = 时长区间生成函数；usleep 消费为 WP 边界。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\LoginGate;

final class B1bLoginGateTest extends TestCase {

	/* ================= B3：失败 sleep 时长生成（纯逻辑） ================= */

	public function test_fail_sleep_max_constant_matches_design(): void {
		$this->assertSame( 2000, LoginGate::FAIL_SLEEP_MAX_MS, '02 §3.2 量级口径：0–2000ms' );
	}

	public function test_fail_sleep_delay_stays_in_range(): void {
		for ( $i = 0; $i < 400; $i++ ) {
			$delay = LoginGate::fail_sleep_delay_ms();
			$this->assertIsInt( $delay );
			$this->assertGreaterThanOrEqual( 0, $delay );
			$this->assertLessThanOrEqual( 2000, $delay );
		}
	}

	public function test_fail_sleep_delay_respects_custom_bound(): void {
		for ( $i = 0; $i < 200; $i++ ) {
			$delay = LoginGate::fail_sleep_delay_ms( 5 );
			$this->assertGreaterThanOrEqual( 0, $delay );
			$this->assertLessThanOrEqual( 5, $delay );
		}
	}

	public function test_fail_sleep_delay_zero_bound_returns_zero(): void {
		$this->assertSame( 0, LoginGate::fail_sleep_delay_ms( 0 ), 'max=0（filter 关闭语义的边界）恒 0——零 sleep 回现行形态' );
		$this->assertSame( 0, LoginGate::fail_sleep_delay_ms( -1 ), '负界钳 0（防御）' );
	}

	public function test_fail_sleep_delay_generates_variety(): void {
		// 均匀分布抽样不复现同一值（防实现退化成常量——时序混淆须真随机）。
		$values = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$values[ LoginGate::fail_sleep_delay_ms() ] = true;
		}
		$this->assertGreaterThan( 10, count( $values ), '4000 取值空间抽样 100 次应产生 >10 个不同值' );
	}

	/* ================= F4-6：attempts 维计数门控（双信号分类同源口径） ================= */

	public function test_attempts_countable_for_real_auth_failures(): void {
		$this->assertTrue( LoginGate::attempts_countable( 'incorrect_password' ) );
		$this->assertTrue( LoginGate::attempts_countable( 'invalid_username' ) );
		$this->assertTrue( LoginGate::attempts_countable( 'invalid_email' ) );
		$this->assertTrue( LoginGate::attempts_countable( 'wk_captcha_failed' ), '验证码失败也是一次失败尝试（IP 维）' );
		$this->assertTrue( LoginGate::attempts_countable( 'third_party_code' ), '第三方自定义码保守计 IP（分类表 default 分支）' );
	}

	public function test_attempts_not_countable_for_core_ignored_codes(): void {
		// empty_* = core 忽略清单（未构成真实认证尝试）——不计入 attempts 维。
		$this->assertFalse( LoginGate::attempts_countable( 'empty_username' ) );
		$this->assertFalse( LoginGate::attempts_countable( 'empty_password' ) );
	}

	public function test_attempts_empty_error_code_counts_conservative(): void {
		// 空码走分类表 default 分支（保守计 IP）——与 record_auth_failure 现行行为同源。
		$this->assertTrue( LoginGate::attempts_countable( '' ) );
	}
}
