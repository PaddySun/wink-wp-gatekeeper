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

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（S2-F1 纵深加固——类文件直访零执行，守卫为纵深面）。
}

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
	 * 拦截留档 meta 键（【W6-C·C-7】）：Bootstrap 留档器写入位与 uninstall.php 清理
	 * 清单同键——本类单点常量防漂移（uninstall 在 WP 卸载语境无 autoloader，保持字面量）。
	 */
	public const ARCHIVE_META_KEY = 'wink_gatekeeper_archive';

	/**
	 * 拦截留档默认留存天数（【W6-C·C-7】用户 2026-11-21 拍板 N=30——对齐 Akismet
	 * 建议下限 15 并留翻案余量；filter wk_archive_retention_days 可调，0=关闭豁免）。
	 */
	public const ARCHIVE_RETENTION_DAYS = 30;

	/**
	 * 留档清理每批扫描条数（LIMIT 口径——存量积压分批收敛，Akismet 批量删先例）。
	 */
	public const ARCHIVE_PURGE_BATCH = 100;

	/**
	 * 事件编号并发冲突自增重试次数（04 §3：UNIQUE KEY event_no 兜底）。
	 */
	public const INSERT_RETRIES = 3;

	/**
	 * rule_hit 列宽（DDL 同步：VARCHAR(120)）。
	 */
	public const RULE_HIT_MAX = 120;

	/**
	 * 写路径容量检查静态缓存复用窗（秒；【M6·批F-b P1-4】60s 内 COUNT 复用一次）。
	 */
	public const CAPACITY_CACHE_SECONDS = 60;

	/**
	 * 容量闸小时日志去重 transient 键前缀（uninstall.php 清理清单已补录）。
	 */
	public const CAPACITY_LOG_PREFIX = 'wk_ec_log_';

	/**
	 * @var array{0: int, 1: int}|null 容量 COUNT 静态缓存 [checked_at, rows]
	 *                                 （进程内 60s 复用——长驻进程/CLI 循环受益）。
	 */
	private static $capacity_cache = null;

	/**
	 * @var string|null 进程内已记容量日志的小时桶（跨请求去重靠 wk_ec_log_ transient）。
	 */
	private static $capacity_log_hour = null;

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
	 *   captcha + pass（disaster_pass 容灾放行） → 落库【O3 §三卡 2 审计足迹】
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
			// 【O3 §三卡 2】容灾放行审计足迹（disaster_pass——CaptchaCode=21/trerror 票据
			// allow 档放行，CommentGate meta.disaster_pass 语境；hits 携带标签同法放行）落库；
			// 【I3·定稿 §4】上报送达审计足迹（report_sent——stage=report、meta.score=上报后
			// 新分值）落库；
			// 【W6-A3】存根降级旁证足迹（compat_stub_degraded——compat 层声明面命中、挑战段
			// 降级放行，CommentGate meta.compat_stub_degraded=<模块 key> 语境；hits 携带标签
			// 同法放行——定稿 §16；stage=stub 行在 EventSampling 采样域内写放大封顶同族）
			// 落库；其余 pass 默认不记，pass_sampling 开启才采样。
			foreach ( $verdict->hits() as $hit ) {
				if ( is_string( $hit ) && ( 'verify_cache_hit' === $hit || 'trust_pass' === $hit || 'test_mode_pass' === $hit || 'disaster_pass' === $hit || 'report_sent' === $hit || 'compat_stub_degraded' === $hit ) ) {
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

	/* -------------------------------------------------------------------
	 * 【M6·批F-b P1-4】写路径容量检查（轻量恢复版；审计 REPORT §4 P1-4）——
	 * P0-4 曾移除每行尾随 COUNT（3→2 查询）换洪水期减负；本版以「小时级 COUNT
	 * 静态缓存 60s 复用」恢复容量兜底：超 wk_events_capacity（默认 MAX_ROWS）
	 * 拒写（返回 null）+ 每小时至多一条 error_log（transient 去重 best-effort）。
	 * 拒写语义 = rows **严格大于**阈值才拒（与 trim_overflow 的 > 语义同构——
	 * 恰等于阈值仍放行一行，每日维护可回到阈值内，避免「恰满即永久冻写」死锁）；
	 * filter 显式归零/负值 = 闸关（豁免口）。
	 * ------------------------------------------------------------------ */

	/**
	 * 是否超容量阈值（纯决策）：rows 严格大于 threshold 才拒；threshold ≤ 0 = 闸关。
	 *
	 * @param int $rows      当前行数。
	 * @param int $threshold 容量阈值（wk_events_capacity，默认 MAX_ROWS）。
	 * @return bool true=拒写。
	 */
	public static function capacity_exceeded( int $rows, int $threshold ): bool {
		if ( $threshold <= 0 ) {
			return false; // 闸关。
		}
		return $rows > $threshold;
	}

	/**
	 * filter 值钳制（纯决策）：负值钳 0（闸关——不虚构负容量），其余透传。
	 *
	 * @param int $filtered wk_events_capacity filter 结果。
	 * @return int
	 */
	public static function capacity_threshold_clamped( int $filtered ): int {
		return max( 0, $filtered );
	}

	/**
	 * 容量 COUNT 静态缓存是否失效（纯决策）：无缓存或距今 ≥ $ttl 秒 = 失效须重查。
	 *
	 * @param array{0: int, 1: int}|null $cache [checked_at, rows]。
	 * @param int                        $now   当前时间戳。
	 * @param int                        $ttl   复用窗秒数（CAPACITY_CACHE_SECONDS）。
	 * @return bool
	 */
	public static function capacity_cache_stale( ?array $cache, int $now, int $ttl ): bool {
		if ( null === $cache ) {
			return true;
		}
		return ( $now - (int) $cache[0] ) >= $ttl;
	}

	/**
	 * 容量阈值读取（WP 边界）：wk_events_capacity filter（默认 MAX_ROWS）+ 钳制。
	 *
	 * @return int
	 */
	private static function capacity_threshold(): int {
		$filtered = self::MAX_ROWS;
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = (int) apply_filters( 'wk_events_capacity', self::MAX_ROWS ); // WP 边界。
		}
		return self::capacity_threshold_clamped( $filtered );
	}

	/**
	 * 写路径容量闸（WP 边界；insert() 顶部消费——拒写时零 MAX/INSERT 查询）。
	 *
	 * COUNT 静态缓存 60s 复用（长驻进程受益；HTTP 单请求进程每请求至多一查）；
	 * 超阈值拒写 + error_log 每小时至多一条（进程内小时桶 + wk_ec_log_ transient
	 * 跨请求去重——transient 被对象缓存逐出时最坏重复记一条，best-effort）。
	 *
	 * @param \wpdb  $wpdb  数据库句柄。
	 * @param string $table 完整表名。
	 * @return bool true=已满拒写（insert 应返回 null）。
	 */
	/** @var bool 最近一次 insert 是否容量拒写（【S3-F7】记录器消费位；请求内单调）。 */
	private static bool $capacity_refused_last = false;

	/**
	 * 最近一次 insert 是否被容量闸拒写（【S3-F7】读面——记录器据此跳过 stats；
	 * should_record=false 的常规不落库路径不置位，stats 照计=现行语义）。
	 *
	 * @return bool
	 */
	public static function capacity_refused_last(): bool {
		return self::$capacity_refused_last;
	}

	private static function capacity_full( $wpdb, string $table ): bool {
		$threshold = self::capacity_threshold();
		if ( $threshold <= 0 ) {
			return false; // 闸关：零 COUNT（filter 归零豁免口）。
		}

		$now = time();
		if ( self::capacity_cache_stale( self::$capacity_cache, $now, self::CAPACITY_CACHE_SECONDS ) ) {
			$rows = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::$capacity_cache = array( $now, $rows );
		}

		if ( ! self::capacity_exceeded( (int) self::$capacity_cache[1], $threshold ) ) {
			return false;
		}

		self::log_capacity_once( $now, $threshold );
		return true;
	}

	/**
	 * 容量拒写日志（每小时至多一条；WP 边界 error_log + transient 去重）。
	 *
	 * @param int $now       当前时间戳。
	 * @param int $threshold 容量阈值（日志文案可诊断）。
	 * @return void
	 */
	private static function log_capacity_once( int $now, int $threshold ): void {
		$hour = gmdate( 'YmdH', $now );
		if ( self::$capacity_log_hour === $hour ) {
			return; // 进程内本小时已记。
		}
		$key = self::CAPACITY_LOG_PREFIX . $hour;
		if ( function_exists( 'get_transient' ) && get_transient( $key ) ) {
			self::$capacity_log_hour = $hour;
			return; // 跨请求本小时已记。
		}
		self::$capacity_log_hour = $hour;
		error_log( '[wink-wp-gatekeeper] 事件表容量已满（>' . $threshold . ' 行）——拒写新事件，等待每日维护滚动清理（wk_events_capacity filter 可调/归零豁免）' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, 1, 2 * HOUR_IN_SECONDS ); // 本小时去重（TTL 2h 兜底回收——wk_es_ 同款）。
		}
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
	 * 当日指定规则键事件计数（【I3·定稿 §4.5/§9】Tab① 站务提醒通栏消费 usage_spike 当日
	 * 事件——rule_hit 精确匹配；只读 COUNT，非数值/查询失败回落 0）。
	 *
	 * @param string $rule 规则键（usage_spike）。
	 * @return int 当日行数（站点时区当日）。
	 */
	public static function today_rule_count( string $rule ): int {
		global $wpdb;
		if ( '' === $rule ) {
			return 0;
		}
		$table = self::table_name( $wpdb->prefix );
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE rule_hit = %s AND created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rule,
				current_time( 'Y-m-d' ) . ' 00:00:00'
			)
		);
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

		self::$capacity_refused_last = false; // 【S3-F7】拒写信号位复位（每 insert 单调语义）。

		$table = self::table_name( $wpdb->prefix );

		/* 【M6·批F-b P1-4】写路径容量检查恢复（轻量缓存版，见 capacity_full 头注）：
		 * 超 wk_events_capacity（默认 10000）拒写——返回 null（与「彻底失败」同形态，
		 * 调用方 event_no 为空不引用）；每小时至多一条 error_log。拒绝洪水期表膨胀
		 * 的第二道闸（第一道=stub/rate 事件采样封顶 EventSampling）。 */
		if ( self::capacity_full( $wpdb, $table ) ) {
			self::$capacity_refused_last = true; // 【S3-F7】容量拒写信号（记录器据此跳过 stats——统计与明细可对账）。
			return null;
		}

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
				// 【P0-4·F1 批→M6·批F-b 演进】P0-4 移除的是「每行尾随溢出清理调用」
				// （万行表 COUNT(*)，3→2 查询）；批 F-b 依审计 P1-4 以轻量形态恢复容量
				// 兜底——COUNT 前置到入口且静态缓存 60s 复用（capacity_full），不再是
				// 每行尾随 COUNT。daily_maintenance 滚动清理职责不变（拒写面由写闸承担）。
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
	 * @return array<string, int> rule_hit => 计数（空 rule_hit 归 __( '(no category)', 'wink-wp-gatekeeper' ) 键）。
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
			$key          = is_string( $row['rule_hit'] ) && '' !== $row['rule_hit'] ? $row['rule_hit'] : __( '(no category)', 'wink-wp-gatekeeper' );
			$dist[ $key ] = (int) $row['n'];
		}
		return $dist;
	}

	/* -------------------------------------------------------------------
	 * 【W3·批D·N8】CSV 导出数据源（施工图 R6：当日拦截流水导出——admin_post GET
	 * 双闸端点 SettingsPage::handle_export_csv 专用只读查询；recent_* 同族当日窗体）。
	 * ------------------------------------------------------------------ */

	/**
	 * 导出单次行数上限（日流水面——明细卡 50 档不适用；公开供单测钉界）。
	 */
	public const EXPORT_MAX_ROWS = 1000;

	/**
	 * LIMIT 钳制（1–EXPORT_MAX_ROWS；公开供单测钉界）。
	 *
	 * @param int $limit 请求行数。
	 * @return int
	 */
	public static function export_limit_clamped( int $limit ): int {
		return max( 1, min( self::EXPORT_MAX_ROWS, $limit ) );
	}

	/**
	 * N8 纯 SQL 模板：当日全闸拦截流水（CSV 导出数据源——R6）。
	 *
	 * 列闭集六列（meta 仅为 trigger_source 归因读件）——零账号/零邮箱/零评论正文/
	 * 零 IP 列（PIPL 最小化红线：导出文件离开 DB 的字段面与登录流水表 Q3 同口径）；
	 * 全闸不滤门（「哪张门」列数据面）；verdict='reject' 拒绝流水（放行/报错不入）。
	 *
	 * @param string $table 完整表名。
	 * @return string 含 %s（当日零点）+ LIMIT %d 占位符的只读 SELECT（prepare 由边界方法执行）。
	 */
	public static function today_intercept_rows_sql( string $table ): string {
		return 'SELECT event_no, created_at, gate, stage, rule_hit, meta FROM ' . $table . ' '
			. "WHERE verdict = 'reject' AND created_at >= %s "
			. 'ORDER BY id DESC LIMIT %d';
	}

	/**
	 * N8 边界查询：当日拦截记录（Tab⑥「导出 CSV」数据源——03 Tab⑥ 段六；
	 * recent_comment_rejects 同族当日窗体——窗体口径=created_at >= 当日零点
	 * （站点时区 current_time 产物），today_fault_distribution 同款）。
	 *
	 * @param string $today 当日日期串（current_time( 'Y-m-d' )——调用方传参，本层零 WP 依赖）。
	 * @param int    $limit 行数上限（默认 EXPORT_MAX_ROWS；钳 1–1000）。
	 * @return array<int, array<string, mixed>> 事件行（meta 解码为数组）。
	 */
	public static function today_intercept_rows( string $today, int $limit = self::EXPORT_MAX_ROWS ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				self::today_intercept_rows_sql( self::table_name( $wpdb->prefix ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$today,
				self::export_limit_clamped( $limit )
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
	 * N4 边界查询：今日按规则拦下分布（rule_hit => 计数；空 rule_hit 归 __( '(no category)', 'wink-wp-gatekeeper' ) 键——
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
	 * 分布行（rule_hit/n）→ 计数 map（空/缺 rule_hit 归 __( '(no category)', 'wink-wp-gatekeeper' )）——行侧口径归
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
	 * 每日维护任务（04 §3 清理策略）：删 RETENTION_DAYS 天前记录 → 超过 MAX_ROWS 行按 id
	 * 溢出删除 →【W6-C·C-7】拦截留档过期清理段（spam + wink_gatekeeper_archive meta
	 * 超 N 天逐条彻底删；执行时限护栏见 purge_expired_archives 内注释）。
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

		self::purge_expired_archives();
	}

	/* -------------------------------------------------------------------
	 * 【W6-C·C-7】拦截留档过期清理（R-AK §C C-7）：留档 spam 直插（02 §3.1 F37/D15）
	 * 此前只进不出——0.9.2 批 1 P1-4 容量闸只封事件表写入面。daily cron 追加一段：
	 * comment_approved='spam' + commentmeta wink_gatekeeper_archive 在场 + 超 N 天
	 * （N=30 常量+filter 可调，0=关闭豁免）→ 逐条 meta 摘除 + wp_delete_comment 彻底删
	 * （不进回收站——回收站会二次堆积）。翻案窗口缩短已由用户拍板接受（W6 草案 §4）。
	 * Tab⑥ 黄条口径（StatsCache::archived_spam_count 只读黄条）自然随事件流演进：
	 * 计数源是活查询（spam JOIN meta），留档删除即出数，零额外同步面。
	 * ------------------------------------------------------------------ */

	/**
	 * 留存窗口钳制（纯决策）：正值透传，0/负值钳 0（关闭豁免——capacity_threshold_clamped
	 * 同款惯例：不虚构负窗口）。
	 *
	 * @param int $filtered wk_archive_retention_days filter 结果。
	 * @return int
	 */
	public static function archive_retention_days_clamped( int $filtered ): int {
		return $filtered > 0 ? $filtered : 0;
	}

	/**
	 * 留存窗口读取（WP 边界）：wk_archive_retention_days filter（默认
	 * ARCHIVE_RETENTION_DAYS=30）+ 钳制；0=关闭豁免。
	 *
	 * @return int
	 */
	public static function archive_retention_days(): int {
		$filtered = self::ARCHIVE_RETENTION_DAYS;
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = (int) apply_filters( 'wk_archive_retention_days', self::ARCHIVE_RETENTION_DAYS ); // WP 边界。
		}
		return self::archive_retention_days_clamped( $filtered );
	}

	/**
	 * 过期分界时刻（纯决策）：now − N 天的 GMT 串（wp_comments.comment_date_gmt 比对面——
	 * 站时区无关，DST 安全；now 参数注入供单测）。
	 *
	 * @param int $days 留存天数（≤0 时调用方应在更早处短路豁免）。
	 * @param int $now  当前 Unix 秒。
	 * @return string 'Y-m-d H:i:s' GMT。
	 */
	public static function expired_archive_cutoff( int $days, int $now ): string {
		// 86400=DAY_IN_SECONDS 字面量（wp-stubs 无此常量声明——phpstan 93E 零新增纪律；
		// HOUR_IN_SECONDS 同族 not-found 为基线既存误报，此处不再扩面）。
		return gmdate( 'Y-m-d H:i:s', $now - $days * 86400 );
	}

	/**
	 * 过期留档 id 扫描 SQL（纯决策，表名插值同今日查询惯例；值走 %s/%d 占位由调用方
	 * prepare——cutoff/批量不入本构建器）。索引面：comment_approved_date_gmt 最左前缀
	 * （approved='spam' 等值 + date_gmt 范围）命中后 JOIN meta 键过滤。
	 *
	 * @param string $comments wp_comments 完整表名。
	 * @param string $meta     wp_commentmeta 完整表名。
	 * @return string
	 */
	public static function expired_archive_ids_sql( string $comments, string $meta ): string {
		return 'SELECT c.comment_ID FROM ' . $comments . ' AS c INNER JOIN ' . $meta . ' AS m ON m.comment_id = c.comment_ID'
			. " WHERE c.comment_approved = 'spam' AND m.meta_key = '" . self::ARCHIVE_META_KEY . "'"
			. ' AND c.comment_date_gmt < %s LIMIT %d';
	}

	/**
	 * 留档过期清理（$wpdb + WP 边界；daily_maintenance 消费——增量收敛：每轮清到
	 * 收敛或中止谓词命中，下轮 cron 续清）。
	 *
	 * 批内逐条：delete_comment_meta 先摘（uninstall.php 清理清单同键逐键纪律——
	 * 不依赖核心 wp_delete_comment 的 meta 语义面）→ wp_delete_comment($id, true)
	 * 彻底删（true=跳过回收站，回收站会二次堆积）。
	 *
	 * @param callable|null $stop 中止谓词 fn(): bool（返回 true 停本轮——C-5 执行时限
	 *                            护栏注入点；null=清到收敛）。
	 * @return int 本轮删除条数。
	 */
	public static function purge_expired_archives( ?callable $stop = null ): int {
		global $wpdb;

		$days = self::archive_retention_days();
		if ( $days <= 0 ) {
			return 0; // 0=关闭豁免（时间窗计算/扫描零发生）。
		}

		$comments = $wpdb->prefix . 'comments';
		$meta     = $wpdb->prefix . 'commentmeta';
		$cutoff   = self::expired_archive_cutoff( $days, time() );
		$sql      = self::expired_archive_ids_sql( $comments, $meta );

		$deleted = 0;
		while ( true ) {
			if ( null !== $stop && $stop() ) {
				break; // 本轮到此——下轮 cron 续清（增量收敛）。
			}
			$ids = $wpdb->get_col( $wpdb->prepare( $sql, $cutoff, self::ARCHIVE_PURGE_BATCH ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! is_array( $ids ) || array() === $ids ) {
				break;
			}
			foreach ( $ids as $id ) {
				delete_comment_meta( (int) $id, self::ARCHIVE_META_KEY ); // WP 边界。
				wp_delete_comment( (int) $id, true ); // WP 边界：彻底删不进回收站。
				$deleted++;
			}
			if ( count( $ids ) < self::ARCHIVE_PURGE_BATCH ) {
				break; // 尾批不满=扫描面已尽。
			}
		}
		return $deleted;
	}

	/**
	 * 容量检查 + 溢出滚动删除（【P0-4·F1】调用方=每日维护 cron 兜底——写入路径不再调用；按 id 最旧优先）。
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
