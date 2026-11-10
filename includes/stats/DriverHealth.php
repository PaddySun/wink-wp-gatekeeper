<?php
/**
 * 驱动当日停转状态（02 §5「驱动健康灯状态机」的停转持久面；C4 实装）。
 *
 * 存储（option wink_gatekeeper_health，结构与 Usage 同款日翻转）：
 *   { date: 'YYYY-MM-DD', drivers: { driver_id: 'config_error'|'quota_exhausted' } }
 *
 * 语义（02 §5 日配额联动/快速失败）：
 *   - semantic 终态（AuthFailure/欠费/参数错）→ config_error：当日停转，防每次提交空转
 *     一次注定失败的调用；闸编排 halt_check 命中即跳过调用（云端无有效判定）。
 *   - 日配额达限 → quota_exhausted：当日停用（配额耗尽走该驱动 fail_mode）。
 *   - 解除条件：次日自动恢复（日翻转）或站长自检通过（clear——Tab③ 测试凭据绿态触发）。
 *   - 状态不降级：config_error 不被后到的 quota_exhausted 覆盖（更严者留存）。
 *
 * 纯逻辑（seed/mark/state/clear）+ WP 边界（*_option）分层，沿 Usage 同款惯例；
 * Tab①「驱动健康」灯与 Bootstrap halt_check 闭包消费本类。
 *
 * @package WinkGatekeeper\Stats
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Stats;

use WinkGatekeeper\Verdict;

final class DriverHealth {

	public const OPTION_NAME = 'wink_gatekeeper_health';

	public const STATE_CONFIG_ERROR    = 'config_error';
	public const STATE_QUOTA_EXHAUSTED = 'quota_exhausted';

	/**
	 * 种子结构。
	 *
	 * @return array<string, mixed>
	 */
	public static function seed(): array {
		return array(
			'date'    => '',
			'drivers' => array(),
		);
	}

	/**
	 * 标记停转（纯函数；跨日翻转清空；config_error 不降级）。
	 *
	 * @param array<string, mixed> $health    当前健康树。
	 * @param string               $date      当前日期 YYYY-MM-DD。
	 * @param string               $driver_id 驱动 id。
	 * @param string               $state     config_error | quota_exhausted。
	 * @return array<string, mixed>
	 */
	public static function mark( array $health, string $date, string $driver_id, string $state ): array {
		if ( ! isset( $health['date'], $health['drivers'] ) || ! is_array( $health['drivers'] ) || $health['date'] !== $date ) {
			// 形态漂移/跨日：按种子重开（fail-safe，不抛异常）。
			$health = self::seed();
		}
		$health['date'] = $date;

		$existing = $health['drivers'][ $driver_id ] ?? '';
		if ( self::STATE_CONFIG_ERROR !== $existing ) {
			// 不降级：config_error 留存（比 quota_exhausted 更需要站长介入）。
			$health['drivers'][ $driver_id ] = $state;
		}
		return $health;
	}

	/**
	 * 查询停转状态（纯函数；跨日 = 无停转——次日自动恢复语义）。
	 *
	 * @param array<string, mixed> $health    当前健康树。
	 * @param string               $date      当前日期 YYYY-MM-DD。
	 * @param string               $driver_id 驱动 id。
	 * @return string|null 停转状态；null = 正常（无停转）。
	 */
	public static function state( array $health, string $date, string $driver_id ): ?string {
		if ( ! isset( $health['date'], $health['drivers'] ) || ! is_array( $health['drivers'] ) || $health['date'] !== $date ) {
			return null;
		}
		$state = $health['drivers'][ $driver_id ] ?? '';
		return is_string( $state ) && '' !== $state ? $state : null;
	}

	/**
	 * 清除停转（站长自检通过——02 §5 解除条件）。
	 *
	 * @param array<string, mixed> $health    当前健康树。
	 * @param string               $driver_id 驱动 id。
	 * @return array<string, mixed>
	 */
	public static function clear( array $health, string $driver_id ): array {
		if ( isset( $health['drivers'] ) && is_array( $health['drivers'] ) ) {
			unset( $health['drivers'][ $driver_id ] );
		}
		return $health;
	}

	/* -------------------------------------------------------------------
	 * WP 边界（option 读写；Bootstrap halt 闭包 / Tab③ 自检绿态 / Tab① 健康灯消费）
	 * ------------------------------------------------------------------ */

	/**
	 * 标记停转（option 读写；date 站时区）。
	 *
	 * 【v1.4·R1 G5 停转留痕】状态实际迁移（null→state 或 state 变更）时落一行事件
	 * （gate=system、stage=health、verdict=error、rule_hit=driver_halted:<state>——04 §3
	 * 写入矩阵 system 行；原仅写 option，跨日自动翻转丢历史）。同态重复标记不重复落。
	 *
	 * @param string $driver_id 驱动 id。
	 * @param string $state     停转状态。
	 * @return void
	 */
	public static function mark_option( string $driver_id, string $state ): void {
		$today  = current_time( 'Y-m-d' );
		$health = get_option( self::OPTION_NAME, null );
		$health = is_array( $health ) ? $health : self::seed();
		$before = self::state( $health, $today, $driver_id );

		$health = self::mark( $health, $today, $driver_id, $state );
		update_option( self::OPTION_NAME, $health, false );

		if ( $before !== $state ) {
			self::record_health_event(
				$driver_id,
				Verdict::error( $driver_id, 'driver_halted:' . $state, array( 'driver_halted:' . $state ) )
			);
		}
	}

	/**
	 * 查询当日停转状态（null = 正常）。
	 *
	 * @param string $driver_id 驱动 id。
	 * @return string|null
	 */
	public static function state_today( string $driver_id ): ?string {
		$health = get_option( self::OPTION_NAME, null );
		return self::state( is_array( $health ) ? $health : self::seed(), current_time( 'Y-m-d' ), $driver_id );
	}

	/**
	 * 清除停转（自检绿态触发）。
	 *
	 * 【v1.4·R1 G5 停转留痕】此前确为停转态 → 解除时落一行事件（gate=system、stage=health、
	 * verdict=pass 特例、rule_hit=driver_resumed——04 §3 矩阵 system 行「解除」为 pass 特例第二处）。
	 *
	 * @param string $driver_id 驱动 id。
	 * @return void
	 */
	public static function clear_option( string $driver_id ): void {
		$health = get_option( self::OPTION_NAME, null );
		if ( ! is_array( $health ) ) {
			return;
		}
		$before = self::state( $health, current_time( 'Y-m-d' ), $driver_id );

		update_option( self::OPTION_NAME, self::clear( $health, $driver_id ), false );

		if ( null !== $before ) {
			self::record_health_event(
				$driver_id,
				Verdict::pass( $driver_id, 'driver_resumed', array( 'driver_resumed' ) )
			);
		}
	}

	/**
	 * 停转进入/解除留痕事件（04 §3 写入矩阵 system 行；不进 StatsCache——健康域非闸判定，
	 * Tab① 健康行读 option + events 最近错误摘要，计数卡不消费）。
	 *
	 * @param string                                     $driver_id 驱动 id。
	 * @param \WinkGatekeeper\Verdict                    $verdict   error（进入）/ pass（解除）。
	 * @return void
	 */
	private static function record_health_event( string $driver_id, \WinkGatekeeper\Verdict $verdict ): void {
		EventsTable::insert( 'system', 'health', $driver_id, $verdict, array() );
	}
}
