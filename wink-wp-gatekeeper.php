<?php
/**
 * Wink 门口保安（wink-wp-gatekeeper）
 *
 * 对真人眨眨眼，对机器人睁着眼。WordPress 评论验证 × 登录验证 × 内容过滤三闸合一，
 * 验证码与云端审核能力驱动化可插拔（G5：零 composer 依赖发行）。
 *
 * 当前里程碑 M1（评论验证 MVP）：天御验证码驱动 + LocalRules 四闸 + CommentGate 编排 +
 * 传输脊 RemoteClient + IpResolver + 事件写入/统计 v1 + wp_die 品牌拦截页 + 文案工坊实装。
 * M0 交付：骨架/激活/建表/卸载 + 7 Tab 设置框架。登录闸 M2、云审核与 IP 信誉 M3。
 *
 * @package WinkGatekeeper
 *
 * @wordpress-plugin
 * Plugin Name:       Wink 门口保安
 * Plugin URI:        https://github.com/wink/wink-wp-gatekeeper
 * Description:       对真人眨眨眼，对机器人睁着眼——评论验证 + 登录验证 + 内容过滤三闸合一，验证码与云端审核驱动化可插拔。
 * Version:           0.4.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Wink
 * Text Domain:       wink-gatekeeper
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问。
}

/* ----------------------------------------------------------------------
 * 常量
 * -------------------------------------------------------------------- */

define( 'WINK_GATEKEEPER_VERSION', '0.4.1' ); // O3·仪表盘统计卡片三卡（O3-统计卡片设计建议 §七 步骤 1-5：DashboardDigest 视图①纯逻辑类零明细查询不变式/Bootstrap wp_dashboard_setup 注册 manage_options 门禁+开关键/三卡 HTML+CSS+行动出口深链/cron 存活检测+重新激活通道/StatsCache disaster_pass 计数特例+写入矩阵 pass 特例——DashboardDigestTest+O3DashboardWidgetContractTest 回归门；小版本号进位 0.4.0→0.4.1）+ UI 批 3·Tab① 总览页点名册形态重构（DESIGN-UI-tab1 B 提案五块，0.3.9→0.4.0）+ UI 批 2·事件查询面×5 + UI 批 1·Tab⑥ 五段重构 + FIX 轮 5·flood 品牌页 + FIX 轮 4·登录错误掩码分域 + FIX 轮 3·Tab③ 下拉联动——wp plugin list 可辨轮次。
define( 'WINK_GATEKEEPER_FILE', __FILE__ );
define( 'WINK_GATEKEEPER_DIR', plugin_dir_path( __FILE__ ) );
define( 'WINK_GATEKEEPER_URL', plugin_dir_url( __FILE__ ) );

/* ----------------------------------------------------------------------
 * Autoload（G5 零依赖：WinkGatekeeper\ → includes/；核心逻辑类零 WP 依赖）
 * -------------------------------------------------------------------- */

require_once WINK_GATEKEEPER_DIR . 'includes/Autoloader.php';
\WinkGatekeeper\Autoloader::register( WINK_GATEKEEPER_DIR . 'includes' );

/* ----------------------------------------------------------------------
 * 激活：建 events 表（dbDelta）+ 写默认 option（含 schema_version:1）+ 注册每日维护 cron
 * -------------------------------------------------------------------- */

register_activation_hook(
	__FILE__,
	static function (): void {
		// 建表（04 §3 DDL，dbDelta 幂等）。
		\WinkGatekeeper\Stats\EventsTable::install();

		// 默认设置树整包写入（add_option：已存在时不覆盖，升级走 migrate_* 约定）。
		// 第 4 参 false = 不 autoload（WP 6.6+ 推荐显式布尔，替代 legacy 字符串 'no'）。
		add_option(
			\WinkGatekeeper\Settings\SettingsPage::OPTION_NAME,
			\WinkGatekeeper\Settings\SettingsSchema::defaults(),
			'',
			false
		);

		// 每日维护（90 天清理 + 1 万行滚动上限，04 §3）。
		\WinkGatekeeper\Stats\EventsTable::register_cron();
	}
);

/* ----------------------------------------------------------------------
 * 停用：仅清 cron（沿「停用不清设置」习惯；数据清理按开关走卸载）
 * -------------------------------------------------------------------- */

register_deactivation_hook(
	__FILE__,
	static function (): void {
		\WinkGatekeeper\Stats\EventsTable::clear_cron();
	}
);

/* ----------------------------------------------------------------------
 * 启动（02 §4：普通时序；M0 只装配设置页。顶层共存检测 M4 接入，02 §3.4）
 * -------------------------------------------------------------------- */

\WinkGatekeeper\Bootstrap::instance( WINK_GATEKEEPER_DIR, WINK_GATEKEEPER_URL )->boot();
