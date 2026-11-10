<?php
/**
 * 事件表（04 §3：DDL、激活建表、写入矩阵、事件写入、daily_maintenance）。
 *
 * 表 {$wpdb->prefix}wink_gatekeeper_events：事件明细（拦截/待审/错误），
 * 上限 10,000 行滚动清理 + 保留 90 天，每日 WP-Cron。
 *
 * 纯逻辑（单测覆盖）：ddl()/table_name()/should_record()/hits_to_rule_hit()/
 * overflow_delete_count()/encode_meta()。
 * WP 边界（静态自查清单覆盖）：install()/register_cron()/clear_cron()/insert()/
 * daily_maintenance()（$wpdb 方法）。
 *
 * @package WinkGatekeeper\Stats
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Stats;

use WinkGatekeeper\EventId;
use WinkGatekeeper\Verdict;

final class EventsTable {

	/**
	 * 表名后缀（不含 $wpdb->prefix）。
	 */
	public const TABLE_SUFFIX = 'wink_gatekeeper_events';

	/**
	 * 每日维护 cron 钩子（90 天清理 + 滚动上限 + 容量提示数据）。
	 */
	public const HOOK_DAILY = 'wink_gatekeeper_daily_maintenance';

	/**
	 * 行数滚动上限（04 §3）。
	 */
	public const MAX_ROWS = 10000;

	/**
	 * 记录保留天数（04 §3）。
	 */
	public const RETENTION_DAYS = 90;

	/**
	 * 事件编号并发冲突自增重试次数（04 §3：UNIQUE KEY event_no 兜底）。
	 */
	public const INSERT_RETRIES = 3;

	/**
	 * rule_hit 列宽（DDL 同步：VARCHAR(120)）。
	 */
	public const RULE_HIT_MAX = 120;

	/**
	 * 完整表名（拼接站点前缀）。
	 *
	 * @param string $prefix $wpdb->prefix。
	 * @return string
	 */
	public static function table_name( string $prefix ): string {
		return $prefix . self::TABLE_SUFFIX;
	}

	/**
	 * 建表 DDL（04 §3 精确照抄；dbDelta 格式：每字段独立行、KEY 语法、行尾无逗号）。
	 *
	 * gate/stage/driver 为开放枚举（取值 = 注册 id / 阶段名），实现不加 CHECK/PHP 白名单封口。
	 *
	 * @param string $prefix          $wpdb->prefix。
	 * @param string $charset_collate $wpdb->get_charset_collate() 结果。
	 * @return string
	 */
	public static function ddl( string $prefix, string $charset_collate ): string {
		$table = self::table_name( $prefix );

		// dbDelta 格式：每字段独立行、行间逗号（末行无）、PRIMARY KEY 双空格。
		// dbDelta 首次建表时原样执行本语句，逗号缺失即语法错误。
		return <<<DDL
CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_no VARCHAR(20) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  gate VARCHAR(20) NOT NULL,
  stage VARCHAR(20) NOT NULL,
  driver VARCHAR(40) NOT NULL,
  verdict VARCHAR(10) NOT NULL,
  rule_hit VARCHAR(120) NOT NULL DEFAULT '',
  reason TEXT NULL,
  ip_hash CHAR(16) NOT NULL DEFAULT '',
  meta LONGTEXT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY event_no (event_no),
  KEY idx_created (created_at),
  KEY idx_gate_driver (gate, driver, verdict)
) {$charset_collate};
DDL;
	}

	/* -------------------------------------------------------------------
	 * 纯逻辑：写入矩阵 + rule_hit + 容量 + meta（单测覆盖）
	 * ------------------------------------------------------------------ */

	/**
	 * 写入矩阵纯决策（04 §3 唯一落库口径；02 §3.1 编排各步骤引用此表）：
	 *
	 *   stub  + reject（bad-signature）          → 落库
	 *   ip    + reject | review | error          → 落库（review 为「疑似→强制验证码」语义）
	 *   captcha + reject（含 drill 演练事件）    → 落库
	 *   review + reject | review | error         → 落库
	 *   全阶段 + pass                            → 默认不落库；pass_sampling 开启才采样
	 *
	 * 注：stats.enabled 为统计面板开关（计数卡计数），不改变事件落库口径（审计链路）。
	 *
	 * @param string               $stage    阶段（ip|stub|captcha|review|开放枚举）。
	 * @param \WinkGatekeeper\Verdict $verdict 判定。
	 * @param array<string, mixed> $settings 设置树（SettingsSchema::defaults() 形态）。
	 * @return bool
	 */
	public static function should_record( string $stage, Verdict $verdict, array $settings ): bool {
		unset( $stage ); // 矩阵按 verdict 主判：非 pass 恒落库、pass 依采样（开放枚举保守口径）。

		if ( Verdict::PASS === $verdict->verdict() ) {
			// 【v1.4·04 §3 矩阵 pass 特例】免验票审计足迹（verify_cache_hit / trust_pass）
			// 与停转解除（driver_resumed——DriverHealth 留痕行经 insert 直入不经本判定）落库；
			// 其余 pass 默认不记，pass_sampling 开启才采样。
			foreach ( $verdict->hits() as $hit ) {
				if ( is_string( $hit ) && ( 'verify_cache_hit' === $hit || 'trust_pass' === $hit ) ) {
					return true;
				}
			}
			$sampling = isset( $settings['stats']['pass_sampling'] ) && (bool) $settings['stats']['pass_sampling'];
			return $sampling;
		}
		return true;
	}

	/**
	 * hits[] 多标签拼接为 rule_hit 列值（v1.2：逗号连接 + 截断保序去重，120 字符截断）。
	 *
	 * @param array $hits 命中标签数组（元素可为任意标量，非字符串剔除）。
	 * @return string
	 */
	public static function hits_to_rule_hit( array $hits ): string {
		$clean = array();
		foreach ( $hits as $hit ) {
			if ( ! is_string( $hit ) || '' === $hit ) {
				continue;
			}
			if ( ! in_array( $hit, $clean, true ) ) { // 保序去重。
				$clean[] = $hit;
			}
		}
		$joined = implode( ',', $clean );
		return ( strlen( $joined ) > self::RULE_HIT_MAX ) ? substr( $joined, 0, self::RULE_HIT_MAX ) : $joined;
	}

	/**
	 * 「故障」观测归类口径（04 §3 v1.4·R4 §D.1——唯一权威定义，三层消费）：
	 *
	 *   verdict = error ∪ rule_hit ∈ {remote_*, contract_mismatch, driver_halted*}
	 *
	 * ——含 captcha 阶段以 reject 落库的远端故障（rule_hit=remote_<category>）；
	 * 消费方：Tab① 最近错误摘要 / Tab⑥ 今日故障卡 / 事件流「判定=故障」筛选预设。
	 * driver_resumed（停转解除，verdict=pass 特例）非故障。
	 *
	 * @param string $verdict  判定列。
	 * @param string $rule_hit 命中规则列（可能为逗号拼接多标签——按整串前缀判）。
	 * @return bool
	 */
	public static function is_fault( string $verdict, string $rule_hit ): bool {
		if ( 'error' === $verdict ) {
			return true;
		}
		if ( '' === $rule_hit ) {
			return false;
		}
		return ( 0 === strpos( $rule_hit, 'remote_' ) )
			|| 'contract_mismatch' === $rule_hit
			|| ( 0 === strpos( $rule_hit, 'driver_halted' ) );
	}

	/**
	 * 故障口径的 SQL 谓词片段（边界查询与 is_fault 同源——今日故障卡 GROUP BY /
	 * 最近错误摘要 LIMIT 1 / 事件流「判定=故障」筛选共用；LIKE 下划线已转义）。
	 *
	 * @return string
	 */
	public static function fault_predicate_sql(): string {
		return "(verdict = 'error' OR rule_hit LIKE 'remote\\_%' OR rule_hit = 'contract_mismatch' OR rule_hit LIKE 'driver\\_halted%')";
	}

	/**
	 * 溢出删除行数（容量检查纯决策：行数 ≤ 上限时 0）。
	 *
	 * @param int $current_rows 当前行数。
	 * @param int $max_rows     上限。
	 * @return int
	 */
	public static function overflow_delete_count( int $current_rows, int $max_rows ): int {
		return max( 0, $current_rows - $max_rows );
	}

	/**
	 * meta JSON 编码（LONGTEXT 列；NULL 值键剔除）。
	 *
	 * 【CI 扫描红线（02 §7.4 v1.4·红线 4 延伸到 events.meta——代码注释落点）】本列携带的
	 * meta.raw（错误事件脱敏快照，R4）**只存响应侧**且必经 RemoteClient::redact()——密钥、
	 * Authorization、票据值不得出现在 raw。CI 静态扫描面（render() 输出 + 本列 JSON）扩展
	 * 要求：scan_secrets 流程将 events 表 meta 列纳入扫描范围；验收口径 =
	 * RemoteResultRawSnapshotTest 脱敏断言（[REDACTED] 覆盖 secret 形态）。
	 *
	 * @param array<string, mixed> $meta
	 * @return string
	 */
	public static function encode_meta( array $meta ): string {
		// PIPL 最小化（04 §3「不存明文 IP」）：client_ip 仅为记录器闭包算 ip_hash 的经手值，
		// 落库前剥除（C4 补——对评论/登录两闸统一生效；明文 IP 有 WP 评论表 comment_author_IP 可查）。
		unset( $meta['client_ip'], $meta['ip'] );

		$clean = array();
		foreach ( $meta as $key => $value ) {
			if ( null === $value ) {
				continue;
			}
			$clean[ $key ] = $value;
		}
		return (string) json_encode( $clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/* -------------------------------------------------------------------
	 * WP 边界（激活/卸载/写入/维护路径；单测环境不可达，静态自查清单覆盖）
	 * ------------------------------------------------------------------ */

	/**
	 * 激活建表（dbDelta）。幂等：dbDelta 自行比对差异。
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::ddl( $wpdb->prefix, $wpdb->get_charset_collate() ) );
	}

	/**
	 * 注册每日维护 cron（已存在则不重复注册）。
	 *
	 * @return void
	 */
	public static function register_cron(): void {
		if ( ! wp_next_scheduled( self::HOOK_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_DAILY );
		}
	}

	/**
	 * 清理每日维护 cron（停用/卸载共用）。
	 *
	 * @return void
	 */
	public static function clear_cron(): void {
		wp_clear_scheduled_hook( self::HOOK_DAILY );
	}

	/**
	 * 事件写入（$wpdb 边界；04 §3 写入口径 = 闸内产生非 pass 结果时直插）。
	 *
	 * 事件编号与插入同事务意识：先取当日 MAX 生成编号，UNIQUE KEY 冲突时自增重试
	 * （INSERT_RETRIES 次）；行满后溢出滚动删除（容量检查）。ip_hash 由调用方算好传入。
	 *
	 * @param string               $gate    闸 id（GateRegistry 注册 id）。
	 * @param string               $stage   阶段（stub|ip|captcha|review）。
	 * @param string               $driver  驱动 id（DriverRegistry 注册 id）。
	 * @param \WinkGatekeeper\Verdict $verdict 判定。
	 * @param array                $meta    事件 meta（post_id/ip_src/api_version/drill…）。
	 * @param string               $ip_hash HMAC-SHA256(ip) 前 16 位（调用方以 wp_salt 派生）。
	 * @return string|null 落库的事件编号；彻底失败 null。
	 */
	public static function insert( string $gate, string $stage, string $driver, Verdict $verdict, array $meta = array(), string $ip_hash = '' ): ?string {
		global $wpdb;

		$table = self::table_name( $wpdb->prefix );
		$today = current_time( 'Ymd' );

		$row = array(
			'created_at' => current_time( 'mysql' ),
			'gate'       => $gate,
			'stage'      => $stage,
			'driver'     => $driver,
			'verdict'    => $verdict->verdict(),
			'rule_hit'   => self::hits_to_rule_hit( $verdict->hits() ),
			'reason'     => $verdict->reason(),
			'ip_hash'    => $ip_hash,
			'meta'       => self::encode_meta( $meta ),
		);

		for ( $attempt = 0; $attempt <= self::INSERT_RETRIES; $attempt++ ) {
			$max = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(event_no) FROM {$table} WHERE event_no LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'WK-' . $today . '-%'
				)
			);

			$event_no = EventId::next_from_max( is_string( $max ) ? $max : null, $today );

			$inserted = $wpdb->insert(
				$table,
				array_merge( array( 'event_no' => $event_no ), $row ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( false !== $inserted ) {
				self::trim_overflow();
				return $event_no;
			}
		}

		return null;
	}

	/**
	 * Tab⑥「最近拦截评论」明细卡数据源（03 Tab⑥ v1.3，C2）。
	 *
	 * 最近 $limit 条评论闸 reject 事件（stage ∈ {ip, captcha, review}——stub 无评论数据
	 * 不进明细卡）；meta 解码为数组。行组装在 InterceptDigest::rows（纯逻辑层）。
	 *
	 * @param int $limit 行数（默认 InterceptDigest::RECENT_LIMIT；钳 1–50）。
	 * @return array<int, array<string, mixed>> 事件行（event_no/created_at/stage/rule_hit/meta）。
	 */
	public static function recent_comment_rejects( int $limit = InterceptDigest::RECENT_LIMIT ): array {
		global $wpdb;

		$table = self::table_name( $wpdb->prefix );
		$n     = max( 1, min( 50, $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_no, created_at, stage, driver, rule_hit, meta FROM {$table} " // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. "WHERE gate = %s AND verdict = %s AND stage IN ('ip','captcha','review') ORDER BY id DESC LIMIT %d",
				'comment',
				'reject',
				$n
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['meta'] = isset( $row['meta'] ) && is_string( $row['meta'] )
				? json_decode( $row['meta'], true )
				: array();
		}
		return $rows;
	}

	/**
	 * Tab⑥「今日故障」卡数据源（03 Tab⑥ v1.4·R4 §D.2）：今日故障行按 rule_hit 分组计数
	 * （归类口径=EventsTable::is_fault——verdict=error ∪ rule_hit 前缀族 remote_ 与
	 * contract_mismatch、driver_halted 前缀，含 captcha 阶段以 reject 落库的远端故障）。
	 *
	 * @return array<string, int> rule_hit => 计数（空 rule_hit 归 '(无类别)' 键）。
	 */
	public static function today_fault_distribution(): array {
		global $wpdb;

		$table  = self::table_name( $wpdb->prefix );
		$today  = current_time( 'Y-m-d' );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT rule_hit, COUNT(*) AS n FROM {$table} " // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. 'WHERE ' . self::fault_predicate_sql() . ' AND created_at >= %s GROUP BY rule_hit ORDER BY n DESC',
				$today
			),
			ARRAY_A
		);

		$dist = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$key          = is_string( $row['rule_hit'] ) && '' !== $row['rule_hit'] ? $row['rule_hit'] : '(无类别)';
			$dist[ $key ] = (int) $row['n'];
		}
		return $dist;
	}

	/**
	 * Tab⑥「最近拦截评论」错误信息列数据源（03 Tab⑥ v1.4·R4 §D.2）：review 阶段 verdict=error
	 * 事件并入明细卡（并行查询与 reject 查询合并按 id 倒序——行形态「入待审（云端故障）」）。
	 *
	 * @param int $limit 行数（与 recent_comment_rejects 同限）。
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent_comment_faults( int $limit = InterceptDigest::RECENT_LIMIT ): array {
		global $wpdb;

		$table = self::table_name( $wpdb->prefix );
		$n     = max( 1, min( 50, $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_no, created_at, stage, driver, rule_hit, meta FROM {$table} " // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. "WHERE gate = %s AND verdict = 'error' AND stage = 'review' ORDER BY id DESC LIMIT %d",
				'comment',
				$n
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['meta'] = isset( $row['meta'] ) && is_string( $row['meta'] )
				? json_decode( $row['meta'], true )
				: array();
		}
		return $rows;
	}

	/**
	 * Tab① 驱动健康行「最近错误摘要」数据源（02 §5 v1.4·R4 §D.2——读模型，不新增健康计数器）：
	 * 该驱动最新一条故障行（is_fault 口径）。
	 *
	 * @param string $driver_id 驱动 id。
	 * @return array{rule_hit: string, time_hm: string}|null 无故障行 = null。
	 */
	public static function latest_driver_fault( string $driver_id ): ?array {
		global $wpdb;

		$table = self::table_name( $wpdb->prefix );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT rule_hit, created_at FROM {$table} " // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				. 'WHERE driver = %s AND ' . self::fault_predicate_sql() . ' ORDER BY id DESC LIMIT 1',
				$driver_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'rule_hit' => is_string( $row['rule_hit'] ) ? $row['rule_hit'] : '',
			'time_hm'  => is_string( $row['created_at'] ) ? substr( $row['created_at'], 11, 5 ) : '',
		);
	}

	/**
	 * 每日维护任务（04 §3 清理策略）：删 RETENTION_DAYS 天前记录 → 超过 MAX_ROWS 行按 id 溢出删除。
	 *
	 * @return void
	 */
	public static function daily_maintenance(): void {
		global $wpdb;

		$table = self::table_name( $wpdb->prefix );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql' ),
				self::RETENTION_DAYS
			)
		);

		self::trim_overflow();
	}

	/**
	 * 容量检查 + 溢出滚动删除（写入路径与每日维护共用；按 id 最旧优先）。
	 *
	 * @return void
	 */
	private static function trim_overflow(): void {
		global $wpdb;

		$table  = self::table_name( $wpdb->prefix );
		$rows   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$delete = self::overflow_delete_count( $rows, self::MAX_ROWS );

		if ( $delete > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE id IN ( SELECT id FROM ( SELECT id FROM {$table} ORDER BY id ASC LIMIT %d ) AS overflow_ids )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$delete
				)
			);
		}
	}
}
