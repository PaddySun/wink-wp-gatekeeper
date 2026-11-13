<?php
/**
 * 卸载行为测试 · WP 桩环境（仅 UiM4UxF3ContractTest::test_uninstall_is_surgical_only_own_data
 * 消费——uninstall.php 在 WP 环境外直载运行所需的最小 WP 函数/wpdb 桩）。
 *
 * 桩只「记账」不模拟多余语义：option 内存存储 / DROP 表名册 / SHOW TABLES LIKE 下划线
 * 通配仿真 / meta 删除调用记录 / cache flush 标志——第三方数据存活断言的判定源。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', true );
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) {
			return \WinkGatekeeper\Tests\UninstallFixture::get_option( $name, $default );
		}
	}

	if ( ! function_exists( 'delete_option' ) ) {
		function delete_option( $name ) {
			return \WinkGatekeeper\Tests\UninstallFixture::delete_option( $name );
		}
	}

	if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
		function wp_clear_scheduled_hook( $hook ) {
			\WinkGatekeeper\Tests\UninstallFixture::$cleared_hooks[] = $hook;
			return true;
		}
	}

	if ( ! function_exists( 'wp_cache_flush' ) ) {
		function wp_cache_flush() {
			\WinkGatekeeper\Tests\UninstallFixture::$cache_flushed = true;
			return true;
		}
	}

	if ( ! function_exists( 'delete_metadata' ) ) {
		function delete_metadata( $meta_type, $object_id, $meta_key, $meta_value = '', $delete_all = false ) {
			\WinkGatekeeper\Tests\UninstallFixture::$meta_deletes[] = array( $meta_type, $object_id, $meta_key, $meta_value, $delete_all );
			return true;
		}
	}
}

namespace WinkGatekeeper\Tests {

	final class UninstallFixture {

		/** @var array<string, mixed> */
		public static array $options = array();

		/** @var string[] */
		public static array $cleared_hooks = array();

		public static bool $cache_flushed = false;

		/** @var array<int, array<int, mixed>> */
		public static array $meta_deletes = array();

		public static function reset(): void {
			self::$options       = array();
			self::$cleared_hooks = array();
			self::$cache_flushed = false;
			self::$meta_deletes  = array();
		}

		/**
		 * @param string $name
		 * @param mixed  $default
		 * @return mixed
		 */
		public static function get_option( $name, $default = false ) {
			return self::$options[ $name ] ?? $default;
		}

		/**
		 * @param string $name
		 * @return bool
		 */
		public static function delete_option( $name ) {
			unset( self::$options[ $name ] );
			return true;
		}
	}

	final class UninstallFakeWpdb {

		public string $prefix  = 'wp_';
		public string $options = 'wp_options';

		/** @var array<string, bool> */
		public static array $tables = array();

		/** @var string[] */
		public static array $queries = array();

		public static function reset(): void {
			self::$tables  = array();
			self::$queries = array();
		}

		/**
		 * 记录查询；DROP TABLE 按严格全等表名摘除（与真实 wpdb 语义一致）。
		 *
		 * @param string $sql
		 */
		public function query( $sql ) {
			self::$queries[] = (string) $sql;
			if ( preg_match( '/DROP TABLE IF EXISTS\s+(\S+)/', (string) $sql, $m ) ) {
				unset( self::$tables[ $m[1] ] );
				return 1;
			}
			return 0;
		}

		/**
		 * 最小 prepare：首个 %s 替换为引号转义后的字面量（卸载路径只用 SHOW TABLES LIKE；
		 * 真实 wpdb prepare 不转义下划线——LIKE 语义下 `_` 即单字符通配，正是卸载谨慎性
		 * 加固所要抵御的形态）。
		 *
		 * @param string    $sql
		 * @param mixed ...$args
		 * @return string
		 */
		public function prepare( $sql, ...$args ) {
			foreach ( $args as $arg ) {
				$sql = (string) preg_replace( '/%s/', "'" . addcslashes( (string) $arg, "'" ) . "'", (string) $sql, 1 );
			}
			return $sql;
		}

		/**
		 * SHOW TABLES LIKE / SELECT option_name ... LIKE 桩：SQL LIKE 语义仿真
		 * （下划线=单字符通配）——正是「相似表名/相似键误伤」防御所要抵御的现实形态。
		 *
		 * @param string $sql
		 * @return string[]
		 */
		public function get_col( $sql ) {
			$sql = (string) $sql;
			if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) && preg_match( "/LIKE '(.+)'/", $sql, $m ) ) {
				$found = array();
				foreach ( array_keys( self::$tables ) as $table ) {
					if ( self::like_match( $m[1], $table ) ) {
						$found[] = $table;
					}
				}
				return $found;
			}
			if ( false !== strpos( $sql, 'SELECT option_name' ) ) {
				$found = array();
				if ( preg_match_all( "/LIKE '(.+?)'/", $sql, $patterns ) ) {
					foreach ( array_keys( UninstallFixture::$options ) as $name ) {
						foreach ( $patterns[1] as $pattern ) {
							if ( self::like_match( $pattern, $name ) ) {
								$found[] = $name;
								break;
							}
						}
					}
				}
				return $found;
			}
			return array();
		}

		private static function like_match( string $pattern, string $subject ): bool {
			$regex = '';
			$len   = strlen( $pattern );
			for ( $i = 0; $i < $len; $i++ ) {
				$ch = $pattern[ $i ];
				if ( '\\' === $ch && $i + 1 < $len ) {
					$i++;
					$regex .= preg_quote( $pattern[ $i ], '/' );
				} elseif ( '%' === $ch ) {
					$regex .= '.*';
				} elseif ( '_' === $ch ) {
					$regex .= '.'; // SQL LIKE 单字符通配——卸载侧以 PHP 严格全等比对兜底。
				} else {
					$regex .= preg_quote( $ch, '/' );
				}
			}
			return (bool) preg_match( '/^' . $regex . '$/', $subject );
		}
	}
}
