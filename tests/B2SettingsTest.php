<?php
/**
 * B2 设置层测试：schema v1.5 captcha 新键（test_mode/test_mode_since/min_score/error_policy）、
 * sanitize 钳制与枚举、写入矩阵 test_mode_pass 特例（04 §2【v1.5】/04 §3【v1.5·C1】）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Settings\Sanitize;
use WinkGatekeeper\Settings\SettingsSchema;
use WinkGatekeeper\Stats\EventsTable;
use WinkGatekeeper\Verdict;

final class B2SettingsTest extends TestCase {

	/* ---------- schema 默认树 ---------- */

	public function test_schema_defaults_carry_v15_keys(): void {
		$defaults = SettingsSchema::defaults();
		$this->assertArrayHasKey( 'test_mode', $defaults['captcha'] );
		$this->assertFalse( $defaults['captcha']['test_mode'], '安全门 #1：默认关' );
		$this->assertSame( 0, $defaults['captcha']['test_mode_since'], '内部键非 UI 字段' );
		$this->assertSame( 0.5, $defaults['captcha']['min_score'] );
		$this->assertSame( 'block', $defaults['captcha']['error_policy'], '拍板：默认 block' );
	}

	public function test_merge_defaults_backfills_v15_keys_for_old_trees(): void {
		// v1.5 迁移：老站 option 无新键 → merge_defaults 补齐整树（04 §2 v1.5 迁移段）。
		$old = array( 'captcha' => array( 'driver' => 'tencent' ) );
		$merged = SettingsSchema::merge_defaults( $old );
		$this->assertFalse( $merged['captcha']['test_mode'] );
		$this->assertSame( 0, $merged['captcha']['test_mode_since'] );
		$this->assertSame( 0.5, $merged['captcha']['min_score'] );
		$this->assertSame( 'block', $merged['captcha']['error_policy'] );
	}

	/* ---------- sanitize 规则表 ---------- */

	public function test_sanitize_rules_declare_v15_paths(): void {
		$rules = Sanitize::rules();
		$this->assertSame( 'bool', $rules['captcha.test_mode'] );
		$this->assertSame( 'int', $rules['captcha.test_mode_since'] );
		$this->assertSame( 'min_score', $rules['captcha.min_score'] );
		$this->assertSame( 'error_policy', $rules['captcha.error_policy'] );
	}

	public function test_min_score_clamped(): void {
		$this->assertSame( 0.1, Sanitize::clean_value( 0, 'min_score' ) );
		$this->assertSame( 1.0, Sanitize::clean_value( 1.5, 'min_score' ) );
		$this->assertSame( 0.7, Sanitize::clean_value( '0.7', 'min_score' ) );
		$this->assertSame( 0.5, Sanitize::clean_value( null, 'min_score' ), '非标量归默认' );
	}

	public function test_error_policy_enum(): void {
		$this->assertSame( 'block', Sanitize::clean_value( 'block', 'error_policy' ) );
		$this->assertSame( 'pass_limited', Sanitize::clean_value( 'pass_limited', 'error_policy' ) );
		$this->assertSame( 'block', Sanitize::clean_value( 'open', 'error_policy' ), '非法值回落 block' );
		$this->assertSame( 'block', Sanitize::clean_value( null, 'error_policy' ) );
	}

	public function test_clean_tree_carries_v15_keys(): void {
		list( $clean, $rejected ) = Sanitize::clean( array(
			'captcha' => array(
				'test_mode'       => '1',
				'test_mode_since' => '1731200000',
				'min_score'       => '0.9',
				'error_policy'    => 'pass_limited',
			),
		) );
		$this->assertTrue( $clean['captcha']['test_mode'] );
		$this->assertSame( 1731200000, $clean['captcha']['test_mode_since'] );
		$this->assertSame( 0.9, $clean['captcha']['min_score'] );
		$this->assertSame( 'pass_limited', $clean['captcha']['error_policy'] );
		$this->assertNotContains( 'captcha.test_mode', $rejected );
		$this->assertNotContains( 'captcha.min_score', $rejected );
	}

	/* ---------- 写入矩阵：test_mode_pass 旁路恒落库（04 §3 v1.5·C1 特例行） ---------- */

	public function test_should_record_test_mode_pass_bypass_row(): void {
		$verdict = Verdict::pass( 'gate', 'test_mode_pass', array( 'test_mode_pass' ) );
		$this->assertTrue(
			EventsTable::should_record( 'captcha', $verdict, SettingsSchema::defaults() ),
			'test_mode 旁路恒落库（审计足迹）——pass 特例清单扩 test_mode_pass'
		);
	}

	public function test_should_record_plain_pass_still_default_off(): void {
		$verdict = Verdict::pass( 'tencent', 'CaptchaCode=1', array( 'CaptchaCode=1' ) );
		$this->assertFalse( EventsTable::should_record( 'captcha', $verdict, SettingsSchema::defaults() ) );
	}

	public function test_fault_classification_covers_error_verdict(): void {
		// 行为变化点 1 的统计面：captcha 远端故障 verdict=error → is_fault=true（错误归类口径不变）。
		$this->assertTrue( EventsTable::is_fault( 'error', '' ) );
		$this->assertTrue( EventsTable::is_fault( 'error', 'remote_transport_degraded' ) );
	}
}
