<?php
/**
 * Verdict 值对象单元测试（02 §2.2）。
 *
 * 规格：三态判定 pass|reject|review（+ error 故障态，「不参与定级」）、
 * reason、hits、driver_id、confidence（0–1 可选）；不可变；工厂方法收口。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Verdict;

final class VerdictTest extends TestCase {

	public function test_pass_factory_sets_state(): void {
		$v = Verdict::pass( 'local_rules', '真人短评', array( '灌水' ) );
		$this->assertSame( 'pass', $v->verdict() );
		$this->assertSame( 'local_rules', $v->driver_id() );
		$this->assertSame( '真人短评', $v->reason() );
		$this->assertSame( array( '灌水' ), $v->hits() );
		$this->assertNull( $v->confidence() );
	}

	public function test_reject_factory_sets_state(): void {
		$v = Verdict::reject( 'local_rules', '关键词命中', array( '广告', '灌水' ) );
		$this->assertSame( 'reject', $v->verdict() );
		$this->assertSame( array( '广告', '灌水' ), $v->hits() );
	}

	public function test_review_factory_sets_state(): void {
		$v = Verdict::review( 'deepseek', '低置信判定', array( '广告' ), 0.65 );
		$this->assertSame( 'review', $v->verdict() );
		$this->assertSame( 0.65, $v->confidence() );
	}

	public function test_error_factory_sets_state(): void {
		$v = Verdict::error( 'deepseek', 'TransportError 重试穷尽' );
		$this->assertSame( 'error', $v->verdict() );
		$this->assertSame( 'TransportError 重试穷尽', $v->reason() );
		$this->assertSame( array(), $v->hits() );
		$this->assertNull( $v->confidence() );
	}

	public function test_error_is_not_rankable_others_are(): void {
		// 02 §2.2：最终取「最严有效判定」reject > review > pass（error 不参与定级）。
		$this->assertFalse( Verdict::error( 'x' )->is_rankable() );
		$this->assertTrue( Verdict::pass( 'x' )->is_rankable() );
		$this->assertTrue( Verdict::review( 'x' )->is_rankable() );
		$this->assertTrue( Verdict::reject( 'x' )->is_rankable() );
	}

	public function test_factory_defaults_are_empty(): void {
		$v = Verdict::pass();
		$this->assertSame( '', $v->driver_id() );
		$this->assertSame( '', $v->reason() );
		$this->assertSame( array(), $v->hits() );
	}

	/**
	 * 非法 confidence（非数值或越出 0–1）归 null，不放行越界值。
	 */
	public function test_confidence_out_of_range_becomes_null(): void {
		$this->assertNull( Verdict::review( 'd', '', array(), 1.5 )->confidence() );
		$this->assertNull( Verdict::review( 'd', '', array(), -0.1 )->confidence() );
		$this->assertNull( Verdict::review( 'd', '', array(), 'abc' )->confidence() );
	}

	public function test_confidence_numeric_string_is_coerced(): void {
		$this->assertSame( 0.8, Verdict::review( 'd', '', array(), '0.8' )->confidence() );
	}

	public function test_direct_instantiation_is_forbidden(): void {
		$this->expectException( \Error::class );
		new Verdict( 'pass', '', array(), '', null );
	}

	public function test_properties_cannot_be_written_from_outside(): void {
		$v = Verdict::reject( 'local_rules', '关键词命中', array( '广告' ) );
		$this->expectException( \Error::class );
		$v->reason = 'tampered';
	}

	public function test_hits_getter_returns_defensive_copy(): void {
		$v = Verdict::reject( 'local_rules', 'r', array( '广告' ) );
		$hits     = $v->hits();
		$hits['x'] = 'inject';
		$this->assertSame( array( '广告' ), $v->hits() );
	}

	public function test_most_severe_orders_reject_over_review_over_pass(): void {
		$reject = Verdict::reject( 'a' );
		$review = Verdict::review( 'b' );
		$pass   = Verdict::pass( 'c' );

		$this->assertSame( $reject, Verdict::most_severe( $review, $pass, $reject ) );
		$this->assertSame( $review, Verdict::most_severe( $pass, $review ) );
		$this->assertSame( $pass, Verdict::most_severe( $pass ) );
	}

	public function test_most_severe_skips_error_verdicts(): void {
		// error 不参与定级：error 不能压过有效判定，也不能成为最严者。
		$review = Verdict::review( 'b' );
		$this->assertSame( $review, Verdict::most_severe( Verdict::error( 'x' ), $review, Verdict::error( 'y' ) ) );
	}

	public function test_most_severe_all_error_returns_null(): void {
		$this->assertNull( Verdict::most_severe( Verdict::error( 'x' ), Verdict::error( 'y' ) ) );
	}

	public function test_most_severe_empty_returns_null(): void {
		$this->assertNull( Verdict::most_severe() );
	}
}
