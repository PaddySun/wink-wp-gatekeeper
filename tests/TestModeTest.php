<?php
/**
 * TestMode（test_mode 演练旁路）纯逻辑单元测试（02 §2.8【v1.5】安全门五件套之 #4 懒过期）。
 *
 * 覆盖：
 *   - active：开关 on + since 有效 + 24h 内；开关 off / since 缺失 / 超 24h 一律视同关；
 *   - is_expired：开关 on 且（since 缺失或超 24h）——懒过期触发条件；
 *   - lifecycle_rule：on/off/expired 三态留痕的事件 rule 派生（纯函数）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\TestMode;

final class TestModeTest extends TestCase {

	private function settings( bool $on, int $since ): array {
		return array( 'captcha' => array( 'test_mode' => $on, 'test_mode_since' => $since ) );
	}

	/* ---------- active（任何消费点的读时判定） ---------- */

	public function test_off_by_default(): void {
		$this->assertFalse( TestMode::active( array(), 1000 ) );
		$this->assertFalse( TestMode::active( array( 'captcha' => array() ), 1000 ) );
		$this->assertFalse( TestMode::active( $this->settings( false, 900 ), 1000 ) );
	}

	public function test_active_within_24h(): void {
		$this->assertTrue( TestMode::active( $this->settings( true, 1000 ), 1000 ) );
		$this->assertTrue( TestMode::active( $this->settings( true, 1000 ), 1000 + 86399 ) );
	}

	public function test_inactive_after_24h(): void {
		// 最坏暴露面收敛到 24h（懒过期无 cron）。
		$this->assertFalse( TestMode::active( $this->settings( true, 1000 ), 1000 + 86400 ) );
		$this->assertFalse( TestMode::active( $this->settings( true, 1000 ), 1000 + 200000 ) );
	}

	public function test_inactive_when_since_missing_or_zero(): void {
		// since 缺失（开关被旁路写入）→ 视同关（fail-safe：无开启时刻=无法证明在窗口内）。
		$this->assertFalse( TestMode::active( array( 'captcha' => array( 'test_mode' => true ) ), 1000 ) );
		$this->assertFalse( TestMode::active( $this->settings( true, 0 ), 1000 ) );
	}

	/* ---------- is_expired（懒过期触发条件） ---------- */

	public function test_expired_after_24h(): void {
		$this->assertTrue( TestMode::is_expired( $this->settings( true, 1000 ), 1000 + 86400 ) );
		$this->assertFalse( TestMode::is_expired( $this->settings( true, 1000 ), 1000 + 86399 ) );
	}

	public function test_not_expired_when_off(): void {
		$this->assertFalse( TestMode::is_expired( $this->settings( false, 0 ), 999999 ) );
		$this->assertFalse( TestMode::is_expired( array(), 999999 ) );
	}

	/* ---------- lifecycle_rule（on/off/expired 事件留痕派生） ---------- */

	public function test_lifecycle_rule_transitions(): void {
		$this->assertSame( TestMode::RULE_ON, TestMode::lifecycle_rule( false, true ) );
		$this->assertSame( TestMode::RULE_OFF, TestMode::lifecycle_rule( true, false ) );
		$this->assertNull( TestMode::lifecycle_rule( true, true ), '无切换零事件' );
		$this->assertNull( TestMode::lifecycle_rule( false, false ), '无切换零事件' );
	}

	/* ---------- 旁路事件 rule 常量与统计隔离键 ---------- */

	public function test_rule_constants(): void {
		$this->assertSame( 'test_mode_pass', TestMode::RULE_PASS );
		$this->assertSame( 'test_mode_on', TestMode::RULE_ON );
		$this->assertSame( 'test_mode_off', TestMode::RULE_OFF );
		$this->assertSame( 'test_mode_expired', TestMode::RULE_EXPIRED );
		$this->assertSame( 86400, TestMode::TTL_SECONDS );
	}
}
