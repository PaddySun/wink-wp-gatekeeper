<?php
/**
 * DriverHealth 单元测试（02 §5 驱动健康灯状态机的停转持久面——C4 实装）。
 *
 * 「驱动当日停转」状态存储（option wink_gatekeeper_health，结构与 Usage 同款日翻转）：
 *   - semantic 终态（AuthFailure/欠费/参数错）→ config_error（当日停转，防空转）；
 *   - 日配额达限 → quota_exhausted；
 *   - 解除条件：次日自动恢复（日翻转）或站长自检通过（clear）；
 *   - 状态不降级：config_error 不被后到的 quota_exhausted 覆盖（更严者留存）。
 *
 * 纯逻辑（seed/mark/state/clear）+ WP 边界（*_option）分层，沿 Usage 同款惯例。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Stats\DriverHealth;

final class DriverHealthTest extends TestCase {

	public function test_seed_shape(): void {
		$this->assertSame(
			array( 'date' => '', 'drivers' => array() ),
			DriverHealth::seed()
		);
	}

	public function test_mark_sets_state_with_date(): void {
		$health = DriverHealth::mark( DriverHealth::seed(), '2026-11-10', 'tencent_tms', DriverHealth::STATE_CONFIG_ERROR );
		$this->assertSame( 'config_error', DriverHealth::state( $health, '2026-11-10', 'tencent_tms' ) );
		$this->assertNull( DriverHealth::state( $health, '2026-11-10', 'other_driver' ) );
	}

	public function test_state_on_unknown_date_is_null(): void {
		// 次日自动恢复：日期不匹配（跨日）视为无停转。
		$health = DriverHealth::mark( DriverHealth::seed(), '2026-11-10', 'tencent_tms', DriverHealth::STATE_CONFIG_ERROR );
		$this->assertNull( DriverHealth::state( $health, '2026-11-11', 'tencent_tms' ) );
	}

	public function test_mark_on_new_date_rolls_over(): void {
		$health = DriverHealth::mark( DriverHealth::seed(), '2026-11-10', 'tencent_tms', DriverHealth::STATE_CONFIG_ERROR );
		$health = DriverHealth::mark( $health, '2026-11-11', 'tencent_tms', DriverHealth::STATE_QUOTA_EXHAUSTED );
		$this->assertSame( 'quota_exhausted', DriverHealth::state( $health, '2026-11-11', 'tencent_tms' ) );
		// 旧日停转已随翻转清空。
		$this->assertNull( DriverHealth::state( $health, '2026-11-10', 'tencent_tms' ) );
	}

	public function test_config_error_not_downgraded_by_quota(): void {
		$health = DriverHealth::mark( DriverHealth::seed(), '2026-11-10', 'tencent_tms', DriverHealth::STATE_CONFIG_ERROR );
		$health = DriverHealth::mark( $health, '2026-11-10', 'tencent_tms', DriverHealth::STATE_QUOTA_EXHAUSTED );
		$this->assertSame( 'config_error', DriverHealth::state( $health, '2026-11-10', 'tencent_tms' ) );
	}

	public function test_clear_removes_state(): void {
		$health = DriverHealth::mark( DriverHealth::seed(), '2026-11-10', 'tencent_tms', DriverHealth::STATE_CONFIG_ERROR );
		$health = DriverHealth::clear( $health, 'tencent_tms' );
		$this->assertNull( DriverHealth::state( $health, '2026-11-10', 'tencent_tms' ) );
	}

	public function test_mark_tolerates_corrupted_tree(): void {
		// fail-safe：损坏 option（形态漂移）按种子重开，不抛异常。
		$health = DriverHealth::mark( array( 'junk' => 1 ), '2026-11-10', 'tencent_tms', DriverHealth::STATE_QUOTA_EXHAUSTED );
		$this->assertSame( 'quota_exhausted', DriverHealth::state( $health, '2026-11-10', 'tencent_tms' ) );
	}

	public function test_state_tolerates_corrupted_tree(): void {
		$this->assertNull( DriverHealth::state( array( 'junk' => 1 ), '2026-11-10', 'tencent_tms' ) );
	}
}
