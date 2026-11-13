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
 * 数据红线（只碰 wink 自有数据，绝不删坏）：
 *   - 删 4 个 option：wink_gatekeeper_settings / stats / usage / health（键名全等，逐个精确）；
 *   - DROP 仅 wink 自建事件表（{prefix}wink_gatekeeper_events——先 SHOW TABLES 探测后
 *     PHP 侧严格全等比对，防 LIKE 下划线通配的相似表名误伤）；
 *   - 清 wink 自有 transient 前缀（wk_lf_ / wk_ip_ / wk_rp_ / wk_cv_ / wk_pk_ / wk_tc_ /
 *     wk_cm_ / wk_irb_——逐前缀精确，禁止 LIKE %wink% 类宽匹配）；
 *   - 清 wink 自有 comment meta 键 wink_gatekeeper_archive（逐键 delete_metadata，不清整行评论）；
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

/* 1. 删 4 个 option（键名全等逐个精确——非卸载清单的相似键名原样保留）。 */
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
 * trust stub 无状态纯签名、无存储无清理（02 §3.3a））。
 * transients 可能落数据库 options 表或对象缓存——这里按 option 存的键索引清理
 * （04 §7 口径）；对象缓存型 transients 由下方步骤 6 的 flush 兜底。
 * 每前缀匹配两种键：主键 _transient_<prefix>_* 与过期时间键 _transient_timeout_<prefix>_*。
 * 【F3-1 谨慎性】逐前缀精确匹配（wink 自有前缀），禁止 %wink% 类宽匹配误伤同前缀他人数据。 */
$wink_guarded( static function () use ( $wpdb ): void {
	foreach ( array( 'wk_lf_', 'wk_ip_', 'wk_rp_', 'wk_cv_', 'wk_pk_', 'wk_tc_', 'wk_cm_', 'wk_irb_' ) as $wink_transient_prefix ) {
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
 * 逐键 delete_metadata，绝不清整行评论；第三方 comment meta 零触碰）。 */
$wink_guarded( static function (): void {
	delete_metadata( 'comment', 0, 'wink_gatekeeper_archive', '', true );
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
