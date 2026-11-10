<?php
/**
 * wink-wp-gatekeeper 类自动加载器（G5 零依赖：不依赖 composer）。
 *
 * 命名空间 WinkGatekeeper\ 映射到 includes/ 目录；子命名空间映射到同名小写目录，
 * 类名保留原大小写。例：
 *   WinkGatekeeper\Verdict                → includes/Verdict.php
 *   WinkGatekeeper\Gates\GateRegistry     → includes/gates/GateRegistry.php
 *   WinkGatekeeper\Stats\EventsTable      → includes/stats/EventsTable.php
 *
 * WordPress 边界层（插件入口、Bootstrap）与核心逻辑类共用此加载器。
 *
 * @package WinkGatekeeper
 */

declare( strict_types=1 );

namespace WinkGatekeeper;

final class Autoloader {

	/**
	 * includes/ 目录绝对路径（含尾部分隔符）。
	 *
	 * @var string
	 */
	private static $base_dir = '';

	/**
	 * 注册 SPL 自动加载。
	 *
	 * @param string $base_dir includes/ 目录路径。
	 * @return void
	 */
	public static function register( string $base_dir ): void {
		self::$base_dir = rtrim( $base_dir, '/\\' ) . '/';
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * 尝试加载 WinkGatekeeper\ 命名空间下的类。
	 *
	 * @param string $class 完整类名。
	 * @return bool 是否已加载。
	 */
	public static function load( string $class ): bool {
		if ( '' === self::$base_dir || 0 !== strpos( $class, 'WinkGatekeeper\\' ) ) {
			return false;
		}

		$parts = explode( '\\', substr( $class, strlen( 'WinkGatekeeper\\' ) ) );
		if ( array() === $parts ) {
			return false;
		}

		$class_name = array_pop( $parts );
		if ( '' === $class_name ) {
			return false;
		}

		$file = self::$base_dir;
		if ( array() !== $parts ) {
			$file .= strtolower( implode( '/', $parts ) ) . '/';
		}
		$file .= $class_name . '.php';

		if ( ! is_file( $file ) ) {
			return false;
		}

		require_once $file;
		return true;
	}
}
