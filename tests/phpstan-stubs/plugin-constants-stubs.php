<?php
/**
 * PHPStan 静态分析桩：插件入口常量（批 W6-C，2026-11-21）。
 *
 * 为何手写：WINK_GATEKEEPER_URL/WINK_GATEKEEPER_DIR 由插件入口
 * wink-wp-gatekeeper.php:40-41 define()——phpstan 只扫 includes/ 两入口文件，
 * 入口内 define 对 includes/ 消费位不可见，每次引用即一条「Constant not found」
 * 误报（基线家族：CommentGate:1855 / DiePage:211 / SettingsPage:657+5104 四条；
 * W6-C 向导实录 GIF 引用再扩两条——家族误报性质同源，随本批以桩清偿）。
 *
 * 与 wp-cli-stubs.php（批 ST1 先例）同机制：经 phpstan.neon.dist scanFiles
 * 静态摄入——运行时零加载（phpunit 只扫 *Test.php，bootstrap 无 glob，本文件
 * 不进任何运行链；真实 define 在入口文件，运行时形态 plugin_dir_url/dir_path
 * 带尾斜杠）。
 *
 * @package WinkGatekeeper\Tests
 */

if ( ! defined( 'WINK_GATEKEEPER_DIR' ) ) {
	define( 'WINK_GATEKEEPER_DIR', '/wink-wp-gatekeeper/' );
}

if ( ! defined( 'WINK_GATEKEEPER_URL' ) ) {
	define( 'WINK_GATEKEEPER_URL', 'http://example.com/wp-content/plugins/wink-wp-gatekeeper/' );
}
