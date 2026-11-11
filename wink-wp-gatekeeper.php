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
 * Version:           0.3.8
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

define( 'WINK_GATEKEEPER_VERSION', '0.3.8' ); // UI 批 1·Tab⑥ 统计页五段重构（DESIGN-UI-tab6 A 提案+统筹裁决：速览条→评论防护→登录防护→报错历史→拦截记录流水→统计设置·清零；服务商统一卡模板+术语人话映射表 Labels N5+待审排除 IP 风控单列；UiTab6StatsContractTest 回归门）+ FIX 轮 5·flood 品牌页 + FIX 轮 4·登录错误掩码分域 + FIX 轮 3·Tab③ 下拉联动——wp plugin list 可辨轮次。
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
