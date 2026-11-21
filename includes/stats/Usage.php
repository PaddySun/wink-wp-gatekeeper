<?php
/**
 * 用量计数（04 §6：option wink_gatekeeper_usage；传输脊统一计量挂钩的消费端）。
 *
 * 结构：{date: 'YYYY-MM-DD', counters: {driver_id: N}}。
 * 口径：每次云端调用发起前自增（含失败调用——计费按请求计）；跨日翻转清零；
 * 配额与当日停用仅对配置了 daily_quota 的驱动生效（验证码核验计量仅供统计展示）。
 * 计量只在传输脊一处（RemoteClient usage 回调 → 本类 ::bump_option）——驱动不各自计数。
 *
 * 【W6-C·C-9】per-request 一次落账（R-AK §C C-9 + 审计 A2-F4 写放大）：bump 累积
 * 静态数组（同请求合并），落账时机=读前 flush（get() 是全仓唯一读口——halt_check/
 * 配额判定/软顶评估/面板读数全经此，读前 flush 保读数逐位不变）+ shutdown action
 * 兜底（首次 bump 懒挂；WP wp-settings.php:166 register_shutdown_function
 * ('shutdown_action_hook') → die()/wp_die 后仍触发，拦截分支 reject 出口不丢计量）。
 *
 * 纯逻辑（单测覆盖）：seed()/bump()/daily_total()/quota_reached()。
 * WP 边界：bump_option()/flush()（option 读写，由 RemoteClient 装配时注入）。
 *
 * @package WinkGatekeeper\Stats
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（S2-F1 纵深加固——类文件直访零执行，守卫为纵深面）。
}

final class Usage {

	public const OPTION_NAME = 'wink_gatekeeper_usage';

	/**
	 * 【M6·P1-3】url_audit 双车道 K1 计量键（批 A 起消费——引擎按车道上报，
	 * 预算闸 UrlAuditWorker::quota_reached 所查键即此二者；闸读侧零改动）。
	 */
	public const KEY_URL_AUDIT_INCR  = 'url_audit_incr';
	public const KEY_URL_AUDIT_BATCH = 'url_audit_batch';

	/**
	 * 【M6·批 A 预备】后续批消费的键位常量（本批只立不接线——消费落点：
	 * url_audit_fetch=网页抓取成功次/日配额（批 C）；deepseek_search=DeepSeek
	 * 搜索源成功次（批 B）。键位先定防后续批字面漂移）。
	 */
	public const KEY_URL_AUDIT_FETCH  = 'url_audit_fetch';
	public const KEY_DEEPSEEK_SEARCH  = 'deepseek_search';

	/**
	 * @var array<string, int> 同请求内累积的计量（键→次数；请求尾 flush 一次落账
	 *                          ——【W6-C·C-9】旧形态=每外呼 get+update 2 查询）。
	 */
	private static array $pending = array();

	/**
	 * @var bool shutdown flush 是否已挂（首次 bump 懒挂——零计量请求零钩子）。
	 */
	private static bool $shutdown_registered = false;

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
	 * 计量回调（传输脊每次调用发起前触发）。
	 *
	 * 【W6-C·C-9】改为累积不落盘：同请求 N 次 bump 合并为请求尾一次写；
	 * shutdown action 首次 bump 懒挂兜底（die()/wp_die 后仍触发——reject 出口不丢）。
	 *
	 * @param string $key 计量键（驱动 id）。
	 * @return void
	 */
	public static function bump_option( string $key ): void {
		self::$pending[ $key ] = ( self::$pending[ $key ] ?? 0 ) + 1;
		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			add_action( 'shutdown', array( self::class, 'flush' ) ); // WP 边界：请求尾兜底落账。
		}
	}

	/**
	 * 累积态落账（WP 边界）：pending 逐次纯 bump 合并进 option 恰一次写——与旧
	 * 「每外呼即落账」语义逐位同形（跨日翻转/缺键回种同源；pending 次数=驱动外呼
	 * 次数，逐次回放而非批量加法，语义无第二解释面）。
	 *
	 * 消费位：shutdown action 兜底（bump 懒挂）+ Usage::get 读前 flush。
	 *
	 * @return void
	 */
	public static function flush(): void {
		if ( array() === self::$pending ) {
			return; // 空累积零读写（零外呼请求零计量查询）。
		}
		$pending           = self::$pending;
		self::$pending     = array();
		$usage             = get_option( self::OPTION_NAME, null );
		foreach ( $pending as $key => $count ) {
			for ( $i = 0; $i < $count; $i++ ) {
				$usage = self::bump( is_array( $usage ) ? $usage : self::seed(), current_time( 'Y-m-d' ), $key );
			}
		}
		update_option( self::OPTION_NAME, $usage, false );
	}

	/**
	 * 读用量树（缺省种子）。
	 *
	 * 【W6-C·C-9】读前 flush：get() 是全仓唯一读口——bump 后立即读数的调用位
	 * （Bootstrap halt_check 配额判定 / AbuseIpdbService 软顶与上报帽评估 /
	 * UrlAuditWorker 配额闸 / Tab 面板读数）读数逐位不变（零行为变化红线）。
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		self::flush();
		$usage = get_option( self::OPTION_NAME, null );
		return is_array( $usage ) ? $usage : self::seed();
	}

	/**
	 * 同请求累积态（测试缝；生产调用面为零）。
	 *
	 * @return array<string, int>
	 */
	public static function pending(): array {
		return self::$pending;
	}

	/**
	 * 复位累积态与挂载旗（测试缝；生产调用面为零——测试隔离桥）。
	 *
	 * @return void
	 */
	public static function reset_request_pending(): void {
		self::$pending              = array();
		self::$shutdown_registered  = false;
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
