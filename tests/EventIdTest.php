<?php
/**
 * EventId 事件编号单元测试（04 §5）。
 *
 * 规格：格式 WK-YYYYMMDD-NNNN（当日序号 0001 起，等宽展示；溢出 9999 自然进位 5 位）；
 * 生成 = 当日 MAX(event_no) 序号 +1（查询属 EventsTable 边界，本类只做序号计算与格式校验）；
 * 并发冲突靠 UNIQUE KEY 兜底、自增重试在 EventsTable 层（不在本类）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\EventId;

final class EventIdTest extends TestCase {

	public function test_format_pads_sequence_to_four_digits(): void {
		$this->assertSame( 'WK-20261109-0001', EventId::format( '20261109', 1 ) );
		$this->assertSame( 'WK-20261109-0042', EventId::format( '20261109', 42 ) );
		$this->assertSame( 'WK-20261109-9999', EventId::format( '20261109', 9999 ) );
	}

	public function test_format_lets_sequence_overflow_to_five_digits(): void {
		// 日序号溢出 9999 自然进位 5 位（04 §3/§5）。
		$this->assertSame( 'WK-20261109-10000', EventId::format( '20261109', 10000 ) );
	}

	public function test_next_from_max_increments_today_sequence(): void {
		$this->assertSame( 'WK-20261109-0043', EventId::next_from_max( 'WK-20261109-0042', '20261109' ) );
	}

	public function test_next_from_max_null_starts_at_one(): void {
		// 当日尚无事件（MAX 为 NULL）→ 0001 起。
		$this->assertSame( 'WK-20261109-0001', EventId::next_from_max( null, '20261109' ) );
	}

	public function test_next_from_max_overflow_carries(): void {
		$this->assertSame( 'WK-20261109-10000', EventId::next_from_max( 'WK-20261109-9999', '20261109' ) );
	}

	public function test_parse_valid_event_no(): void {
		$parsed = EventId::parse( 'WK-20261109-0042' );
		$this->assertNotNull( $parsed );
		$this->assertSame( '20261109', $parsed['date'] );
		$this->assertSame( 42, $parsed['seq'] );
	}

	public function test_parse_rejects_malformed(): void {
		foreach ( array(
			'',
			'WK-20261109',
			'WK-20261109-',
			'WK-20261109-abc',
			'WK-2026-1109-0042',
			'XX-20261109-0042',
			'WK-20261109-0042-extra',
			'WK-2026110-0042',
			'WK-20261309-0042', // 月 13 非法.
		) as $bad ) {
			$this->assertNull( EventId::parse( $bad ), "应拒绝：{$bad}" );
		}
	}

	public function test_parse_extracts_overflowed_sequence(): void {
		$parsed = EventId::parse( 'WK-20261109-10000' );
		$this->assertNotNull( $parsed );
		$this->assertSame( 10000, $parsed['seq'] );
	}
}
