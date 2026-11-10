<?php
/**
 * wink-wp-gatekeeper 单元测试引导。
 *
 * 复用插件自身的 autoload（includes/Autoloader.php），刻意不加载插件入口文件——
 * 入口含 WP 边界代码（register_activation_hook 等），纯 PHP 单测环境不可用（G5 零依赖）。
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/includes/Autoloader.php';

\WinkGatekeeper\Autoloader::register( dirname( __DIR__ ) . '/includes' );
