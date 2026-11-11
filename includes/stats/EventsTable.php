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
			// 【v1.4·04 §3 矩阵 pass 特例】免验票审计足迹（verify_cache_hit / trust_pass）、
			// 【v1.5·C1】test_mode 旁路（test_mode_pass——旁路恒落库，drill 标注不进统计）
			// 与停转解除（driver_resumed——DriverHealth 留痕行经 insert 直入不经本判定）落库；
			// 其余 pass 默认不记，pass_sampling 开启才采样。
			foreach ( $verdict->hits() as $hit ) {
				if ( is_string( $hit ) && ( 'verify_cache_hit' === $hit || 'trust_pass' === $hit || 'test_mode_pass' === $hit ) ) {
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
	 * 事件表容量读数（UI 批 3·N11——Tab① 块 E「站点底账」消费；agent C B-6 裁决：
	 * 「事件记录 正常」无站内健康指标砍除，换可行的事件表容量读数）。
	 *
	 * 只读 COUNT；非数值/查询失败回落 0（不虚构）。
	 *
	 * @return int 当前行数。
	 */
	public static function row_count(): int {
		global $wpdb;
		$count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_name( $wpdb->prefix ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_numeric( $count ) ? (int) $count : 0;
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

	/* -------------------------------------------------------------------
	 * UI 批 2·事件查询面×5（DESIGN-UI-tech-feasibility §D N1/N2/N3/N4/N6+N7a——
	 * 全部只读 SELECT + prepare + LIMIT；零 DDL，idx_gate_driver/idx_created 既有索引命中）。
	 *
	 * 可测形态：纯 SQL 模板 builder（表名注入 + 动态值一律 %s/%d 占位符，单测逐字断言
	 * 谓词/排序/只读——EventsTableQueriesTest）；WP 边界方法只做 clamp + prepare + 行 meta 解码。
	 * 行组装（人话映射/打码）在 InterceptDigest 纯逻辑层（批 2 扩展 account_reject_rows/
	 * fault_timeline_rows）。
	 * ------------------------------------------------------------------ */

	/**
	 * LIMIT 钳制（1–50——与 recent_comment_rejects 现行界一致；公开供单测钉界）。
	 *
	 * @param int $limit 请求行数。
	 * @return int
	 */
	public static function limit_clamped( int $limit ): int {
		return max( 1, min( 50, $limit ) );
	}

	/**
	 * N1 纯 SQL 模板：最近登录拦截明细（三闸 + reject + stub/captcha；idx_gate_driver
	 * 最左前缀 gate 定位）。列集不带 ip_hash/reason——PIPL 最小化（任务口径：IP 哈希省略，
	 * 无明文账号可显——agent C E-a；归因键 trigger_source 在 meta 列）。
	 *
	 * @param string $table 完整表名。
	 * @return string 含 LIMIT %d 占位符的只读 SELECT（prepare 由边界方法执行）。
	 */
	public static function recent_account_rejects_sql( string $table ): string {
		return 'SELECT event_no, created_at, gate, stage, driver, rule_hit, meta FROM ' . $table . ' '
			. "WHERE gate IN ('login','register','lostpassword') AND verdict = 'reject' AND stage IN ('stub','captcha') "
			. 'ORDER BY id DESC LIMIT %d';
	}

	/**
	 * N1 边界查询：Tab⑥「最近拦下的登录尝试」明细卡数据源（03 Tab⑥ 口径二④——批 2 接线）。
	 *
	 * @param int $limit 行数（默认 InterceptDigest::RECENT_LIMIT；钳 1–50）。
	 * @return array<int, array<string, mixed>> 事件行（meta 解码为数组）。
	 */
	public static function recent_account_rejects( int $limit = InterceptDigest::RECENT_LIMIT ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				self::recent_account_rejects_sql( self::table_name( $wpdb->prefix ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::limit_clamped( $limit )
			),
			ARRAY_A
		);

		return self::decode_meta_rows( $rows );
	}

	/**
	 * N2 纯 SQL 模板：全闸故障时间线（fault_predicate_sql 同源复用——F7 唯一权威口径）。
	 * 列集含 gate/verdict/meta：门名列、「当时怎么处理」组合映射、meta.raw 展开行数据源；
	 * **id 列=两源归并序键**（UI 批 3 修正——批 2 文档声称在列而 SQL 漏选，fault_timeline_rows
	 * 归并退化；id 缺位时同 id 行无法去重——见 fault_timeline_rows）。
	 *
	 * @param string $table 完整表名。
	 * @return string
	 */
	public static function recent_faults_sql( string $table ): string {
		return 'SELECT id, event_no, created_at, gate, stage, driver, verdict, rule_hit, meta FROM ' . $table . ' '
			. 'WHERE ' . self::fault_predicate_sql() . ' '
			. 'ORDER BY id DESC LIMIT %d';
	}

	/**
	 * N2 边界查询：Tab⑥「最近报错」全闸时间线数据源（含登录门与停转进入行；
	 * 停转解除行非故障口径——见 recent_health_timeline）。
	 *
	 * @param int $limit 行数（钳 1–50）。
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent_faults( int $limit = InterceptDigest::RECENT_LIMIT ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				self::recent_faults_sql( self::table_name( $wpdb->prefix ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::limit_clamped( $limit )
			),
			ARRAY_A
		);

		return self::decode_meta_rows( $rows );
	}

	/**
	 * N3 纯谓词片段：停转进入 ∪ 解除（driver_halted:<state> 前缀 ∪ driver_resumed——
	 * 解除为 verdict=pass 特例，不在故障谓词内（F7），独立谓词）。LIKE 下划线已转义。
	 *
	 * @return string
	 */
	public static function health_timeline_predicate_sql(): string {
		return "(rule_hit LIKE 'driver\\_halted:%' OR rule_hit = 'driver_resumed')";
	}

	/**
	 * N3 纯 SQL 模板：停转进入·解除时间线（04 §3 写入矩阵 system 行：gate=system、
	 * stage=health；events 表无健康快照行——driver_halted 驱动行（verdict=error、闸侧留痕）
	 * 归故障口径进 N2，本查询只取健康域留痕）。窗口/行数走 prepare 占位。
	 * **id 列=两源归并序键**（UI 批 3 修正——同 recent_faults_sql）。
	 *
	 * @param string $table 完整表名。
	 * @return string
	 */
	public static function recent_health_timeline_sql( string $table ): string {
		return 'SELECT id, event_no, created_at, gate, stage, driver, verdict, rule_hit FROM ' . $table . ' '
			. "WHERE gate = 'system' AND stage = 'health' AND " . self::health_timeline_predicate_sql() . ' '
			. 'AND created_at >= %s '
			. 'ORDER BY id DESC LIMIT %d';
	}

	/**
	 * N3 边界查询：停转进入·解除时间线（Tab⑥ 报错时间线并入 + Tab① 块 D 批 3 消费）。
	 *
	 * @param int $limit 行数（钳 1–50）。
	 * @param int $days  查询窗口天数（钳 1–RETENTION_DAYS；默认 7——B-5「7 天风平浪静」空态）。
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent_health_timeline( int $limit = InterceptDigest::RECENT_LIMIT, int $days = 7 ): array {
		global $wpdb;

		$clamped_days = max( 1, min( self::RETENTION_DAYS, $days ) );
		$since        = date( 'Y-m-d 00:00:00', (int) strtotime( current_time( 'mysql' ) . ' -' . $clamped_days . ' days' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				self::recent_health_timeline_sql( self::table_name( $wpdb->prefix ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since,
				self::limit_clamped( $limit )
			),
			ARRAY_A
		);

		return self::decode_meta_rows( $rows );
	}

	/**
	 * N4 纯 SQL 模板：今日按规则分布（与 today_fault_distribution 同构——agent C N4；
	 * 闸 id 调用方传参走 %s 占位）。批 3 Tab① 战报副行数据源。
	 *
	 * @param string $table 完整表名。
	 * @return string
	 */
	public static function today_reject_distribution_sql( string $table ): string {
		return 'SELECT rule_hit, COUNT(*) AS n FROM ' . $table . ' '
			. "WHERE gate = %s AND verdict = 'reject' AND created_at >= %s "
			. 'GROUP BY rule_hit ORDER BY n DESC';
	}

	/**
	 * N4 边界查询：今日按规则拦下分布（rule_hit => 计数；空 rule_hit 归 '(无类别)' 键——
	 * 行侧口径 InterceptDigest::distribution_from_rows 纯函数）。
	 *
	 * @param string $gate 闸 id（默认评论门——战报副行口径）。
	 * @return array<string, int>
	 */
	public static function today_reject_distribution( string $gate = 'comment' ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				self::today_reject_distribution_sql( self::table_name( $wpdb->prefix ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$gate,
				current_time( 'Y-m-d' )
			),
			ARRAY_A
		);

		return InterceptDigest::distribution_from_rows( $rows );
	}

	/**
	 * N6/N7a 纯 SQL 模板：今日故障按驱动聚合（fault_predicate_sql 复用 + GROUP BY driver——
	 * agent C N6；$gates 非空时追加分域过滤（N7a 登录卡=三闸，gate id 白名单清洗））。
	 *
	 * @param string   $table 完整表名。
	 * @param string[] $gates 闸 id 列表（空=全闸；id 只允许 [a-z0-9_]——清洗后 quoting）。
	 * @return string
	 */
	public static function today_fault_by_driver_sql( string $table, array $gates = array() ): string {
		$sql = 'SELECT driver, COUNT(*) AS n FROM ' . $table . ' WHERE ' . self::fault_predicate_sql();
		if ( array() !== $gates ) {
			$sql .= ' AND gate IN (' . self::gate_list_sql( $gates ) . ')';
		}
		return $sql . ' AND created_at >= %s GROUP BY driver ORDER BY n DESC';
	}

	/**
	 * N6/N7a 边界查询：今日故障按驱动聚合计数（驱动卡「出错」格数据源——补 captcha 阶段
	 * 以 reject 落库的远端故障（A-5）；登录卡传 ACCOUNT_GATES 得分域口径（N7a））。
	 *
	 * @param string[]|null $gates 闸 id 列表（null/空=全闸）。
	 * @return array<string, int> driver => 今日故障数。
	 */
	public static function today_fault_by_driver( ?array $gates = null ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				self::today_fault_by_driver_sql( self::table_name( $wpdb->prefix ), is_array( $gates ) ? $gates : array() ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'Y-m-d' )
			),
			ARRAY_A
		);

		$map = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( isset( $row['driver'] ) && is_string( $row['driver'] ) ) {
				$map[ $row['driver'] ] = isset( $row['n'] ) && is_numeric( $row['n'] ) ? (int) $row['n'] : 0;
			}
		}
		return $map;
	}

	/**
	 * 闸 id 列表 → 带引号 IN 片段（白名单清洗 [a-z0-9_]；全部无效时回落 ''——
	 * gate IN ('') 匹配零行，不放大查询面）。
	 *
	 * @param string[] $gates 闸 id 列表。
	 * @return string
	 */
	private static function gate_list_sql( array $gates ): string {
		$clean = array();
		foreach ( $gates as $gate ) {
			$id = preg_replace( '/[^a-z0-9_]/', '', (string) $gate );
			if ( is_string( $id ) && '' !== $id ) {
				$clean[] = "'" . $id . "'";
			}
		}
		return array() === $clean ? "''" : implode( ',', $clean );
	}

	/**
	 * 分布行（rule_hit/n）→ 计数 map（空/缺 rule_hit 归 '(无类别)'）——行侧口径归
	 * InterceptDigest 纯逻辑层（公共纯函数，单测直钉；本类只做查询）。
	 */

	/**
	 * 查询行 meta 列解码（JSON → 数组；非字符串/缺失回落空数组——新查询共用行尾处理）。
	 *
	 * @param array<int, array<string, mixed>>|mixed $rows 查询行。
	 * @return array<int, array<string, mixed>>
	 */
	private static function decode_meta_rows( $rows ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[ $index ]['meta'] = isset( $row['meta'] ) && is_string( $row['meta'] )
				? json_decode( $row['meta'], true )
				: array();
		}
		return $rows;
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
