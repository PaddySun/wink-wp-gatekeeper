<?php
/**
 * 事件编号（04 §5：WK-YYYYMMDD-NNNN，全局唯一，唯一权威格式）。
 *
 * 生成：事件表当日 MAX(event_no) 序号 + 1（一次 MAX 查询在 EventsTable 边界完成）；
 * 本类为纯逻辑：序号计算 + 格式化 + 格式校验。并发冲突靠 UNIQUE KEY event_no 兜底，
 * 失败自增重试（3 次）在 EventsTable::insert() 边界实现——职责分层（02 §1.1）。
 *
 * 展示位：拦截页证据行（等宽）、事件流、导出报告（「报上你的 WK 编号」）。
 *
 * @package WinkGatekeeper
 */

declare( strict_types=1 );

namespace WinkGatekeeper;

final class EventId {

	/**
	 * 日期段长度（YYYYMMDD）。
	 */
	private const DATE_LEN = 8;

	/**
	 * 格式化事件编号（序号等宽 4 位，溢出自然进位 5 位以上）。
	 *
	 * @param string $date YYYYMMDD（调用方以站时区取值——EventsTable 边界 current_time('Ymd')）。
	 * @param int    $seq  当日序号（≥1）。
	 * @return string
	 */
	public static function format( string $date, int $seq ): string {
		return 'WK-' . $date . '-' . sprintf( '%04d', max( 1, $seq ) );
	}

	/**
	 * 当日下一编号：当日最大编号序号 + 1；当日尚无事件（max null）从 0001 起。
	 *
	 * @param string|null $max_for_today 当日 MAX(event_no)（NULL = 无）。
	 * @param string      $date          YYYYMMDD。
	 * @return string
	 */
	public static function next_from_max( ?string $max_for_today, string $date ): string {
		$seq = 1;
		if ( null !== $max_for_today ) {
			$parsed = self::parse( $max_for_today );
			if ( null !== $parsed && $parsed['date'] === $date ) {
				$seq = $parsed['seq'] + 1;
			}
		}
		return self::format( $date, $seq );
	}

	/**
	 * 格式校验与解析。
	 *
	 * @param string $event_no
	 * @return array{date: string, seq: int}|null 非法格式返回 null。
	 */
	public static function parse( string $event_no ): ?array {
		if ( 0 !== strpos( $event_no, 'WK-' ) ) {
			return null;
		}
		$body = substr( $event_no, 3 );
		$dash = strrpos( $body, '-' );
		if ( false === $dash || self::DATE_LEN !== $dash ) {
			return null;
		}

		$date = substr( $body, 0, $dash );
		$seq  = substr( $body, $dash + 1 );

		if ( ! ctype_digit( $date ) || ! ctype_digit( $seq ) || '' === $seq ) {
			return null;
		}
		if ( ! checkdate( (int) substr( $date, 4, 2 ), (int) substr( $date, 6, 2 ), (int) substr( $date, 0, 4 ) ) ) {
			return null;
		}

		return array(
			'date' => $date,
			'seq'  => (int) $seq,
		);
	}
}
