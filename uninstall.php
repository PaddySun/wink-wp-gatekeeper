<?php
/**
 * 卸载清理（04 §7）·【F3-1·M4UX 0.5.4 修订】Delete=全清卸载 + 谨慎性加固。
 *
 * 语义（用户拍板 2026-11-14，覆盖 M4UX-MANUAL-FEEDBACK-2 §二 F3-1 的「Tab⑦ 卸载按钮」提案）：
 *   - **Delete（WP 卸载）即触发全清卸载**——不再有「卸载清理」开关（本文件旧 gate
 *     退役；该键原默认 false 且从未有 UI 入口——开关恒关=uninstall 直接触发 return，
 *     即「卸载重装后历史还在」的根因；B 机存量值待真机复核，静态推断+本地行为级验证）；
 *   - **保留数据路径 = 不卸载、直接上传新版 zip「升级」**——WP 升级不触发本文件，语义天然成立。
 *
 * 【P1-6·M6 批G·CLI 卸载警示（2026-11-16——独立审计 AUDIT-INDEP-2026-11 REPORT §4 P1-6
 *   销账，代码零改动纯头注）】**「Delete=全清」仅后台 UI 路径成立**——wp-cli 的
 *   `wp plugin delete wink-wp-gatekeeper` 实现即 rm -rf，**不执行本文件**（wp-cli 2.12
 *   Plugin_Command::delete_plugin() 不调核心 delete_plugins()）——CLI delete 后本清单
 *   逐项不兑现：settings option（含明文 API 凭据）、events 表、全部 comment meta、
 *   cron 排期与 wk_* transient 全残留，站长会误以为已全清。
 *   **CLI 卸载标准流程**（二选一）：
 *   ① `wp plugin uninstall wink-wp-gatekeeper`——wp-cli 会先执行本文件（WP_UNINSTALL_PLUGIN
 *      通道）再删文件，数据全清；
 *   ② 后台「插件 → 删除」——WP 核心走 delete_plugins() → 触发本文件，数据全清。
 *   同族指引：docs 06 真机计划顶部接续横幅「勘误」行已按此口径（`wp plugin uninstall`）。
 *
 * 数据红线（只碰 wink 自有数据，绝不删坏）：
 *   - 删 10 个 option：wink_gatekeeper_settings / stats / usage / health
 *     【v1.9·M5 回写·审计 C2】+ wink_gatekeeper_llm_usage（LLM 消耗统计，
 *     LlmUsage::OPTION_NAME）+ wink_gatekeeper_url_audit_incr_queue（网址核查增量
 *     队列，UrlAuditWorker::OPTION_INCR_QUEUE）+ wink_gatekeeper_url_audit_batch_queue
 *     （网址核查批量回捞队列【M6·批A·P1-3】，UrlAuditWorker::OPTION_BATCH_QUEUE）
 *     + wink_gatekeeper_url_audit_pause_edge（配额暂停边沿标记【M6·批B·B-5】，
 *     UrlAuditWorker::OPTION_PAUSE_EDGE）+ wink_gatekeeper_url_audit_backlog_record
 *     （存量网址核查运行记录【M6·批C·C-3】，UrlAuditWorker::OPTION_BACKLOG_RECORD）
 *     + wink_gatekeeper_audit_existing_progress
 *     （存量审核进度，ExistingAuditTask::OPTION_PROGRESS）（键名全等，逐个精确）；
 *   - DROP 仅 wink 自建事件表（{prefix}wink_gatekeeper_events——先 SHOW TABLES 探测后
 *     PHP 侧严格全等比对，防 LIKE 下划线通配的相似表名误伤）；
 *   - 清 wink 自有 transient 前缀（wk_lf_ / wk_ip_ / wk_rp_ / wk_cv_ / wk_pk_ / wk_tc_ /
 *     wk_cm_ / wk_irb_ / wk_ua_ / 【M6·批F-b】wk_es_ / wk_ec_log_——逐前缀精确，
 *     禁止 LIKE %wink% 类宽匹配）；
 *   - 清 wink 自有 comment meta 键 wink_gatekeeper_archive（逐键 delete_metadata，不清整行评论）
 *     【v1.9·M5 回写·审计 C2】+ wink_audit_existing（存量审核判定标记，
 *     ExistingAuditTask::META_KEY）+ url_audit（网址核查判定 meta，UrlAuditWorker::META_KEY）
 *     ——两 M5 meta 键同款逐键纪律；
 *   - 绝不碰 WP 核心表 / 其他插件数据 / wp_comments 评论行 / wp_options 非本插件键。
 *
 * 失败安全：每个清理步骤独立 try/catch（Throwable）——单步异常不中断整个卸载，
 * 宁可留残留，不可误删、不可半途而废。
 *
 * 对象缓存清库（F3-1 根因防御）：B 机 Redis Object Cache 激活场景，delete_option 只删
 * DB 行与 alloptions 在册缓存键，wk_* 缓存型 transient 与持久化对象缓存里的残留值靠
 * 卸载尾部 wp_cache_flush() 兜底（守卫可用性——uninstall 直载场景 wp_object_cache
 * 若不可用则静默跳过，读取路径 SchemaCache/StatsCache 既有缓存 TTL 自行过期）。
 *
 * 【升级兼容预位】升级路径（不卸载直接上传 zip）数据兼容检测与处理 = **预位**——本文件
 * 不参与升级路径（WP 升级不触发 uninstall.php）；升级期数据兼容由
 * SettingsSchema::SCHEMA_VERSION + migrate/merge_defaults 机制承载 schema 内平移，
 * 跨大版本兼容策略留后续里程碑（不建空架子——F33「不用的代码路径=不工作的代码路径」）。
 *
 * 【P0-2·F1 批】history：补删 wink_gatekeeper_health（DriverHealth::OPTION_NAME），
 * 键集一致性由 OptionHygieneTest 钉（uninstall delete_option 键集 === 全插件 OPTION_NAME
 * 常量集）。
 *
 * @package WinkGatekeeper
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; // 禁止直接访问。
}

global $wpdb;

/* 失败安全包装：单步异常只吞不抛——卸载步骤彼此独立（宁可残留，不可误删/中断）。 */
$wink_guarded = static function ( callable $wink_step ): void {
	try {
		$wink_step();
	} catch ( Throwable $wink_step_failure ) { // phpcs:ignore SlevomatCodingStandard.Exceptions.Reference.ThrownExceptions -- 失败安全兜底。
		unset( $wink_step_failure ); // 宁可残留：异常不上抛、不中断后续步骤。
	}
};

/* 1. 删 10 个 option（键名全等逐个精确——非卸载清单的相似键名原样保留；
 * 【v1.9·M5 回写·审计 C2】M5 三 option 补录：LlmUsage::OPTION_NAME /
 * UrlAuditWorker::OPTION_INCR_QUEUE / ExistingAuditTask::OPTION_PROGRESS；
 * 【M6·批A·P1-3】批量回捞显式队列 UrlAuditWorker::OPTION_BATCH_QUEUE 补录；
 * 【M6·批B·B-5】配额暂停边沿标记 UrlAuditWorker::OPTION_PAUSE_EDGE 补录；
 * 【M6·批C·C-3】存量网址核查运行记录 UrlAuditWorker::OPTION_BACKLOG_RECORD 补录）。 */
$wink_guarded( static function (): void {
	delete_option( 'wink_gatekeeper_settings' );
} );
$wink_guarded( static function (): void {
	delete_option( 'wink_gatekeeper_stats' );
} );
$wink_guarded( static function (): void {
	delete_option( 'wink_gatekeeper_usage' );
} );
$wink_guarded( static function (): void {
	delete_option( 'wink_gatekeeper_health' );
} );
$wink_guarded( static function (): void {
	delete_option( 'wink_gatekeeper_llm_usage' );
} );
$wink_guarded( static function (): void {
	delete_option( 'wink_gatekeeper_url_audit_incr_queue' );
} );
$wink_guarded( static function (): void {
	delete_option( 'wink_gatekeeper_url_audit_batch_queue' ); // 【M6·P1-3】批量回捞显式队列（UrlAuditWorker::OPTION_BATCH_QUEUE）。
} );
$wink_guarded( static function (): void {
	delete_option( 'wink_gatekeeper_url_audit_pause_edge' ); // 【M6·批B·B-5】配额暂停边沿标记（UrlAuditWorker::OPTION_PAUSE_EDGE）。
} );
$wink_guarded( static function (): void {
	delete_option( 'wink_gatekeeper_url_audit_backlog_record' ); // 【M6·批C·C-3】存量网址核查运行记录（UrlAuditWorker::OPTION_BACKLOG_RECORD）。
} );
$wink_guarded( static function (): void {
	delete_option( 'wink_gatekeeper_url_audit_fetch_streak' ); // 【M6·批H2·H2-2】DeepSeek 抓取连续未成功计数（UrlAuditWorker::OPTION_FETCH_STREAK）。
} );
$wink_guarded( static function (): void {
	delete_option( 'wink_gatekeeper_audit_existing_progress' );
} );

/* 2. DROP events 表（仅 wink 自建表——SHOW TABLES 探测 + PHP 严格全等比对：
 * LIKE 的 `_` 是单字符通配，「wpXwink_gatekeeper_events」类相似表名不得误伤）。 */
$wink_guarded( static function () use ( $wpdb ): void {
	$wink_events_table = $wpdb->prefix . 'wink_gatekeeper_events';
	$wink_tables       = $wpdb->get_col(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $wink_events_table ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepare 即在位。
	);
	if ( is_array( $wink_tables ) && in_array( $wink_events_table, $wink_tables, true ) ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wink_events_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名=受控前缀+常量后缀，且已经严格全等比对。
	}
} );

/* 3. 清每日维护 cron。 */
$wink_guarded( static function (): void {
	wp_clear_scheduled_hook( 'wink_gatekeeper_daily_maintenance' );
} );

/* 4. 删 transient 前缀（wk_lf_ 登录计数 / wk_ip_ IP 查询缓存 / wk_rp_ 上报冷却 /
 * 【v1.4】wk_cv_ 验证短缓存 / wk_pk_ 预检限流桶 / wk_tc_ 信用 COUNT 缓存——04 §7 补录；
 * 【v1.7·M3-IP 定稿 §9】wk_cm_ 评论提交限流桶（P0-A 配套补录）/ wk_irb_ IP 信誉退避键
 * （I2 批补录——AbuseIpdbService 步 2 全局退避 transient）；
 * 【M6·批F-b】wk_es_ stub/rate 事件采样小时桶（P1-4 配套补录，TTL 2h）/
 * wk_ec_log_ 事件表容量闸小时日志去重键（P1-4 配套补录，TTL 2h）；
 * trust stub 无状态纯签名、无存储无清理（02 §3.3a））。
 * transients 可能落数据库 options 表或对象缓存——这里按 option 存的键索引清理
 * （04 §7 口径）；对象缓存型 transients 由下方步骤 6 的 flush 兜底。
 * 每前缀匹配两种键：主键 _transient_<prefix>_* 与过期时间键 _transient_timeout_<prefix>_*。
 * 【F3-1 谨慎性】逐前缀精确匹配（wink 自有前缀），禁止 %wink% 类宽匹配误伤同前缀他人数据。 */
$wink_guarded( static function () use ( $wpdb ): void {
	foreach ( array( 'wk_lf_', 'wk_ip_', 'wk_rp_', 'wk_cv_', 'wk_pk_', 'wk_tc_', 'wk_cm_', 'wk_irb_', 'wk_ua_', 'wk_es_', 'wk_ec_log_' ) as $wink_transient_prefix ) { // 【M5·U5】wk_ua_ 网址核查判定缓存.【M6·F2】wk_es_/wk_ec_log_ 事件写放大封顶键.
		$wink_transient_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE option_name LIKE %s OR option_name LIKE %s",
				'\_transient\_' . $wink_transient_prefix . '%',
				'\_transient\_timeout\_' . $wink_transient_prefix . '%'
			)
		);

		foreach ( (array) $wink_transient_keys as $wink_option_name ) {
			delete_option( $wink_option_name );
		}
	}
} );

/* 5. 清 wink 自有 comment meta 键（Bootstrap::record_archive 落的 wink_gatekeeper_archive——
 * 逐键 delete_metadata，绝不清整行评论；第三方 comment meta 零触碰）。
 * 【v1.9·M5 回写·审计 C2】M5 两 meta 键同款补录：wink_audit_existing（存量审核判定
 * 标记·ExistingAuditTask::META_KEY——不清则重装后旧标记致存量审核「已处理」假象）+
 * url_audit（网址核查判定·UrlAuditWorker::META_KEY）。 */
$wink_guarded( static function (): void {
	delete_metadata( 'comment', 0, 'wink_gatekeeper_archive', '', true );
} );
$wink_guarded( static function (): void {
	delete_metadata( 'comment', 0, 'wink_audit_existing', '', true );
} );
$wink_guarded( static function (): void {
	delete_metadata( 'comment', 0, 'url_audit', '', true );
} );

/* 6. 对象缓存清库（Redis 复活防御——F3-1 头号嫌疑根因）：持久化对象缓存（Redis）里
 * 的 alloptions 快照 / wk_* 缓存型 transient / 停转态缓存若不 flush，重装激活后
 * get_option/读表会从缓存回填旧值（「历史和评论都在」形态）。守卫可用性：uninstall
 * 直载场景 wp_object_cache 若不可用则静默跳过（fallback=读取路径既有 TTL 自愈）。 */
$wink_guarded( static function (): void {
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
} );

unset( $wink_guarded );
