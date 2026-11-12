<?php
/**
 * 用量计数（04 §6：option wink_gatekeeper_usage；传输脊统一计量挂钩的消费端）。
 *
 * 结构：{date: 'YYYY-MM-DD', counters: {driver_id: N}}。
 * 口径：每次云端调用发起前自增（含失败调用——计费按请求计）；跨日翻转清零；
 * 配额与当日停用仅对配置了 daily_quota 的驱动生效（验证码核验计量仅供统计展示）。
 * 计量只在传输脊一处（RemoteClient usage 回调 → 本类 ::bump_option）——驱动不各自计数。
 *
 * 纯逻辑（单测覆盖）：seed()/bump()/daily_total()/quota_reached()。
 * WP 边界：bump_option()（option 读写，由 RemoteClient 装配时注入）。
 *
 * @package WinkGatekeeper\Stats
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Stats;

final class Usage {

	public const OPTION_NAME = 'wink_gatekeeper_usage';

	/**
	 * 种子结构。
	 *
	 * @return array<string, mixed>
	 */
	public static function seed(): array {
		return array(
			'date'     => '',
			'counters' => array(),
		);
	}

	/**
	 * 计数递增（纯函数；跨日翻转清零）。last_report_at 持久兜底字段见 mark_report_at()
	 * （【I3】上报能力随本批落此 option——Usage.php:37 注释位兑现）。
	 *
	 * @param array<string, mixed> $usage 当前用量树。
	 * @param string               $date  当前日期 YYYY-MM-DD。
	 * @param string               $key   计量键（驱动 id；AbuseIPDB 上报为 abuseipdb_report 独立键）。
	 * @return array<string, mixed>
	 */
	public static function bump( array $usage, string $date, string $key ): array {
		if ( ! is_array( $usage ) || ! isset( $usage['date'], $usage['counters'] ) || ! is_array( $usage['counters'] ) ) {
			$usage = self::seed();
		}
		if ( $usage['date'] !== $date ) {
			$usage['date']     = $date;
			$usage['counters'] = array();
		}
		$usage['counters'][ $key ] = ( $usage['counters'][ $key ] ?? 0 ) + 1;
		return $usage;
	}

	/**
	 * 当日总调用数（用量面板汇总）。
	 *
	 * @param array<string, mixed> $usage
	 * @return int
	 */
	public static function daily_total( array $usage ): int {
		$counters = $usage['counters'] ?? array();
		return is_array( $counters ) ? array_sum( array_map( 'intval', $counters ) ) : 0;
	}

	/**
	 * 某驱动当日是否已达配额（当日停转判定；quota <= 0 视为不限量）。
	 *
	 * @param array<string, mixed> $usage
	 * @param string               $key   计量键。
	 * @param int                  $quota 日配额。
	 * @return bool
	 */
	public static function quota_reached( array $usage, string $key, int $quota ): bool {
		if ( $quota <= 0 ) {
			return false;
		}
		$used = $usage['counters'][ $key ] ?? 0;
		return $used >= $quota;
	}

	/**
	 * 某驱动当日调用数（Tab① 健康灯用量展示；缺键 0）。
	 *
	 * @param array<string, mixed> $usage
	 * @param string               $key   计量键。
	 * @return int
	 */
	public static function daily_total_for( array $usage, string $key ): int {
		$counters = $usage['counters'] ?? array();
		return is_array( $counters ) && isset( $counters[ $key ] ) ? (int) $counters[ $key ] : 0;
	}

	/**
	 * 上报时刻标记（纯函数；【I3·定稿 §4.3/02 §2.3a】last_report_at 持久兜底——transient
	 * 重启丢失场景；Usage.php:37 注释位兑现。report 发送**按尝试落**（成功失败都标——
	 * 每次外呼即一次上报动作）。
	 *
	 * @param array<string, mixed> $usage 当前用量树。
	 * @param int                  $ts    上报尝试时刻（Unix 秒）。
	 * @return array<string, mixed>
	 */
	public static function mark_report_at( array $usage, int $ts ): array {
		if ( ! is_array( $usage ) || ! isset( $usage['date'], $usage['counters'] ) || ! is_array( $usage['counters'] ) ) {
			$usage = self::seed();
		}
		$usage['last_report_at'] = $ts;
		return $usage;
	}

	/* -------------------------------------------------------------------
	 * WP 边界（option 读写；RemoteClient 计量回调注入用）
	 * ------------------------------------------------------------------ */

	/**
	 * 计量回调（传输脊每次调用发起前触发；date 站时区）。
	 *
	 * @param string $key 计量键（驱动 id）。
	 * @return void
	 */
	public static function bump_option( string $key ): void {
		$usage = get_option( self::OPTION_NAME, null );
		$usage = self::bump( is_array( $usage ) ? $usage : self::seed(), current_time( 'Y-m-d' ), $key );
		update_option( self::OPTION_NAME, $usage, false );
	}

	/**
	 * 读用量树（缺省种子）。
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$usage = get_option( self::OPTION_NAME, null );
		return is_array( $usage ) ? $usage : self::seed();
	}

	/**
	 * 上报时刻标记落 option（WP 边界；AbuseIpdbService report 流装配缺省消费——
	 * last_report_at 持久兜底，定稿 §4.3/02 §2.3a）。
	 *
	 * @param int $ts 上报尝试时刻（Unix 秒）。
	 * @return void
	 */
	public static function mark_report_at_option( int $ts ): void {
		$usage = get_option( self::OPTION_NAME, null );
		$usage = self::mark_report_at( is_array( $usage ) ? $usage : self::seed(), $ts );
		update_option( self::OPTION_NAME, $usage, false );
	}
}
