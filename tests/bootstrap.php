<?php
/**
 * wink-wp-gatekeeper 单元测试引导。
 *
 * 复用插件自身的 autoload（includes/Autoloader.php），刻意不加载插件入口文件——
 * 入口含 WP 边界代码（register_activation_hook 等），纯 PHP 单测环境不可用（G5 零依赖）。
 *
 * 【W3·批C】WP 边界函数桩（WinkGatekeeper\Settings 命名空间内先解析）：N1 probe log
 * 与向导状态 option 的读写走 SettingsPage 私有方法（R4 不建新类——单文件口径），
 * 行为级测试须有 get_option/update_option/date_i18n 的命名空间桩（W3HelpersTest
 * esc_* 桩同款机制，先于全部测试文件载入=单进程全局缝）。桩为「可编程 store」形态：
 * 测试用例经 WpOptionStub 静态面播种/清空，逐测自清理（setUp/tearDown 纪律）。
 */

declare( strict_types=1 );

namespace /* 全局：autoload 装配 */ {

	require_once dirname( __DIR__ ) . '/includes/Autoloader.php';

	\WinkGatekeeper\Autoloader::register( dirname( __DIR__ ) . '/includes' );
}

namespace WinkGatekeeper\Settings {

	if ( ! function_exists( 'WinkGatekeeper\Settings\get_option' ) ) {
		/**
		 * get_option 命名空间桩（可编程 store 读取）。与既有 Settings 域桩族
		 * （SettingsPageSanitizeBridgeTest / M6HUiSemanticsTest）同约定：WpOptionStub
		 * 自有键优先，缺键回落 $GLOBALS['wk_bridge_option_store'] 同键 store（同进程
		 * 跨文件互操作——行为逐字一致，先载者生效语义不破坏既有测试）。
		 *
		 * @param string $name    option 名。
		 * @param mixed  $default 缺省值。
		 * @return mixed
		 */
		function get_option( string $name, $default = false ) {
			if ( array_key_exists( $name, \WinkGatekeeper\Tests\WpOptionStub::$options ) ) {
				return \WinkGatekeeper\Tests\WpOptionStub::$options[ $name ];
			}
			$bridge = $GLOBALS['wk_bridge_option_store'] ?? array();
			return is_array( $bridge ) && array_key_exists( $name, $bridge ) ? $bridge[ $name ] : $default;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\update_option' ) ) {
		/**
		 * update_option 命名空间桩（可编程 store 写入）。
		 *
		 * @param string $name  option 名。
		 * @param mixed  $value 值。
		 * @return bool
		 */
		function update_option( string $name, $value ): bool {
			return \WinkGatekeeper\Tests\WpOptionStub::set( $name, $value );
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\delete_transient' ) ) {
		/**
		 * delete_transient 命名空间桩（可编程记录删除——AUDIT-FIX 2026-11-20 S4-F6 行为级
		 * 测试承载面：百度凭据清除/换钥联动删旧 token transient 的删除记录入
		 * WpOptionStub::$deleted_transients，逐测自清理；记录语义与既有 update_option
		 * 桩同约定，不引入全局 WP 依赖）。
		 *
		 * @param string $key transient 键。
		 * @return bool
		 */
		function delete_transient( string $key ): bool {
			\WinkGatekeeper\Tests\WpOptionStub::$deleted_transients[] = $key;
			return true;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\date_i18n' ) ) {
		/**
		 * date_i18n 命名空间桩（N1「上次测试」小字时间格式化——纯 PHP date 等价形态）。
		 *
		 * @param string   $format    日期格式。
		 * @param int|null $timestamp 时间戳（null=当前时刻）。
		 * @return string
		 */
		function date_i18n( string $format, $timestamp = null ): string {
			return \WinkGatekeeper\Tests\WpOptionStub::date_i18n( $format, $timestamp );
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\esc_attr' ) ) {
		/**
		 * esc_attr 命名空间桩（identity——W3HelpersTest esc_* 同款；反射直调
		 * test_credential_btn/wizard_url 需要转义件在场）。
		 *
		 * @param string $text 原文。
		 * @return string
		 */
		function esc_attr( string $text ): string {
			return $text;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\esc_html' ) ) {
		/**
		 * esc_html 命名空间桩（identity）。
		 *
		 * @param string $text 原文。
		 * @return string
		 */
		function esc_html( string $text ): string {
			return $text;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\esc_url' ) ) {
		/**
		 * esc_url 命名空间桩（identity）。
		 *
		 * @param string $url 原文。
		 * @return string
		 */
		function esc_url( string $url ): string {
			return $url;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\__' ) ) {
		/**
		 * __ 命名空间桩（identity——probe_state_badge 上次测试小字格式化等）。
		 *
		 * @param string $text   原文（译文基线即默认译文）。
		 * @param string $domain 文本域（桩忽略）。
		 * @return string
		 */
		function __( string $text, string $domain = 'wink-wp-gatekeeper' ): string {
			unset( $domain );
			return $text;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\esc_html__' ) ) {
		/**
		 * esc_html__ 命名空间桩（identity）。
		 *
		 * @param string $text   原文。
		 * @param string $domain 文本域（桩忽略）。
		 * @return string
		 */
		function esc_html__( string $text, string $domain = 'wink-wp-gatekeeper' ): string {
			unset( $domain );
			return $text;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\esc_html_e' ) ) {
		/**
		 * esc_html_e 命名空间桩（identity echo）。
		 *
		 * @param string $text   原文。
		 * @param string $domain 文本域（桩忽略）。
		 * @return void
		 */
		function esc_html_e( string $text, string $domain = 'wink-wp-gatekeeper' ): void {
			unset( $domain );
			echo $text;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\_x' ) ) {
		/**
		 * _x 命名空间桩（identity——批 I-2b _x 拆分位配套：带上下文透传原文）。
		 *
		 * @param string $text    原文（译文基线即默认译文）。
		 * @param string $context 上下文（桩忽略）。
		 * @param string $domain  文本域（桩忽略）。
		 * @return string
		 */
		function _x( string $text, string $context, string $domain = 'wink-wp-gatekeeper' ): string {
			unset( $context, $domain );
			return $text;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\esc_html_x' ) ) {
		/**
		 * esc_html_x 命名空间桩（identity）。
		 *
		 * @param string $text    原文。
		 * @param string $context 上下文（桩忽略）。
		 * @param string $domain  文本域（桩忽略）。
		 * @return string
		 */
		function esc_html_x( string $text, string $context, string $domain = 'wink-wp-gatekeeper' ): string {
			unset( $context, $domain );
			return $text;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\add_query_arg' ) ) {
		/**
		 * add_query_arg 命名空间桩（wizard_url 行为级测试——query 追加最小形态）。
		 *
		 * @param array<string, string> $args  追加参数。
		 * @param string                $url 基 URL。
		 * @return string
		 */
		function add_query_arg( array $args, string $url ): string {
			$glue = str_contains( $url, '?' ) ? '&' : '?';
			return $url . $glue . implode( '&', array_map(
				static function ( string $k, string $v ): string {
					return $k . '=' . $v;
				},
				array_keys( $args ),
				array_values( $args )
			) );
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\admin_url' ) ) {
		/**
		 * admin_url 命名空间桩（wizard_url 行为级测试——固定前缀形态）。
		 *
		 * @param string $path 相对路径。
		 * @return string
		 */
		function admin_url( string $path = '' ): string {
			return 'https://example.com/wp-admin/' . $path;
		}
	}
}

namespace WinkGatekeeper\Tests {

	/**
	 * 单测 WP option 可编程 store（W3·批C——probe log / 向导状态行为级测试承载面）。
	 * 静态面：get/set/date_i18n；测试用例自行 reset（避免跨测污染）。
	 */
	final class WpOptionStub {

		/**
		 * @var array<string, mixed> 播种的 option 值。
		 */
		public static array $options = array();

	/**
	 * @var int date_i18n 桩的固定时刻（0=真实 time()）。
	 */
	public static int $now = 0;

	/**
	 * @var string[] delete_transient 桩记录的删除键（AUDIT-FIX S4-F6 行为级断言面）。
	 */
	public static array $deleted_transients = array();

		/**
		 * @param string $name    option 名。
		 * @param mixed  $default 缺省值。
		 * @return mixed
		 */
		public static function get( string $name, $default = false ) {
			return array_key_exists( $name, self::$options ) ? self::$options[ $name ] : $default;
		}

		/**
		 * @param string $name  option 名。
		 * @param mixed  $value 值。
		 * @return bool
		 */
		public static function set( string $name, $value ): bool {
			self::$options[ $name ] = $value;
			return true;
		}

		/**
		 * @param string   $format    日期格式。
		 * @param int|null $timestamp 时间戳。
		 * @return string
		 */
		public static function date_i18n( string $format, $timestamp = null ): string {
			$ts = null !== $timestamp ? (int) $timestamp : ( 0 !== self::$now ? self::$now : time() );
			return date( $format, $ts ); // 格式词直出（桩无本地化语义）。
		}

		/**
		 * 逐测自清理。
		 *
		 * @return void
		 */
	public static function reset(): void {
		self::$options            = array();
		self::$now                = 0;
		self::$deleted_transients = array();
	}
	}
}
