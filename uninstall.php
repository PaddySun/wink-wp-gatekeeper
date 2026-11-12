<?php
/**
 * 卸载清理（04 §7）。
 *
 * 仅当设置中 cleanup_on_uninstall = true 时执行：
 *   1. 删 3 个 option：settings / stats / usage；
 *   2. DROP events 表；
 *   3. 清 daily_maintenance cron；
 *   4. 删 transient 前缀 wk_lf_ / wk_ip_ / wk_rp_（以 option 表存的键索引清理）。
 * 默认 false（沿上游「停用不清设置」习惯）——什么都不做。
 *
 * @package WinkGatekeeper
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; // 禁止直接访问。
}

$wink_settings = get_option( 'wink_gatekeeper_settings' );

if ( ! is_array( $wink_settings ) || empty( $wink_settings['cleanup_on_uninstall'] ) ) {
	return; // 清理开关关闭：保留全部数据。
}

/* 1. 删 3 个 option。 */
delete_option( 'wink_gatekeeper_settings' );
delete_option( 'wink_gatekeeper_stats' );
delete_option( 'wink_gatekeeper_usage' );

/* 2. DROP events 表（表名 = 受控前缀 + 常量后缀，无用户输入拼接面）。 */
global $wpdb;
$wink_events_table = $wpdb->prefix . 'wink_gatekeeper_events';
$wpdb->query( "DROP TABLE IF EXISTS {$wink_events_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/* 3. 清每日维护 cron。 */
wp_clear_scheduled_hook( 'wink_gatekeeper_daily_maintenance' );

/* 4. 删 transient 前缀（wk_lf_ 登录计数 / wk_ip_ IP 查询缓存 / wk_rp_ 上报冷却 /
 * 【v1.4】wk_cv_ 验证短缓存 / wk_pk_ 预检限流桶 / wk_tc_ 信用 COUNT 缓存——04 §7 补录；
 * 【v1.7·M3-IP 定稿 §9】wk_cm_ 评论提交限流桶（P0-A 配套补录；wk_irb_ 退避键属 I2 批勿提前）；
 * trust stub 无状态纯签名、无存储无清理（02 §3.3a））。
 * transients 可能落数据库 options 表或对象缓存——这里按 option 存的键索引清理
 * （04 §7 口径）；对象缓存型 transients 随缓存过期自行消失。
 * 每前缀匹配两种键：主键 _transient_<prefix>_* 与过期时间键 _transient_timeout_<prefix>_*。 */
foreach ( array( 'wk_lf_', 'wk_ip_', 'wk_rp_', 'wk_cv_', 'wk_pk_', 'wk_tc_', 'wk_cm_' ) as $wink_transient_prefix ) {
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

unset( $wink_settings, $wink_events_table, $wink_transient_prefix, $wink_transient_keys, $wink_option_name );
