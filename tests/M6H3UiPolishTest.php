<?php
/**
 * M6·批 H3 · UI 小批四件（2026-11-17，施工图 M6-H3-UI小批-设计定稿 §0——TDD 先红后绿回归门）。
 *
 * 四件（手测第一轮反馈 M6H2-MANUAL-FEEDBACK-1 §三 A/B/C/D——用户拍板落地；D 口径
 * 「转待审的算拦」用户 2026-11-17 明示）：
 *   H3-A Tab② 验证码驱动未配置 notice 补位（wk-captcha-unconfigured-notice）。
 *   H3-B 历史体检运行中黄条统一（running/quota_paused 态升级 notice-warning）。
 *   H3-C 两报告原因明细行尾补处置去向（归堆键扩 verdict 维度——同因不同去向拆行）。
 *   H3-D 「谁拦的」纳入网址核查复核处置块（今日垃圾箱 X·转待审 Y——meta 反查复用 H-9）。
 *
 * 桩注记：与 M6H2PolishTest 同套命名空间桩（function_exists 守卫同进程共存——字母序
 * M6B* → M6H2* → M6H3* → M6HU*，全套跑复用先行桩；单独跑自给自足）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Settings {

	$GLOBALS['wk_bridge_option_store'] = $GLOBALS['wk_bridge_option_store'] ?? array();

	if ( ! function_exists( 'WinkGatekeeper\Settings\get_option' ) ) {
		/**
		 * get_option 桩。
		 *
		 * @param string $name    option 名。
		 * @param mixed  $default 缺省值。
		 * @return mixed
		 */
		function get_option( string $name, $default = false ) {
			return array_key_exists( $name, $GLOBALS['wk_bridge_option_store'] )
				? $GLOBALS['wk_bridge_option_store'][ $name ]
				: $default;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\do_action' ) ) {
		/**
		 * do_action 桩。
		 *
		 * @param string $tag      钩子名。
		 * @param mixed  ...$args  参数。
		 * @return void
		 */
		function do_action( string $tag, ...$args ): void {
			$GLOBALS['wk_h_rejected_report'] = $args[0] ?? null;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\esc_html_e' ) ) {
		/**
		 * esc_html_e 桩（输出原文）。
		 *
		 * @param string $text 文本。
		 * @return void
		 */
		function esc_html_e( string $text ): void {
			echo $text;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\esc_html__' ) ) {
		/**
		 * esc_html__ 桩。
		 *
		 * @param string $text   文本。
		 * @param string $domain 文本域（忽略）。
		 * @return string
		 */
		function esc_html__( string $text, string $domain = 'wink-wp-gatekeeper' ): string {
			return $text;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\__' ) ) {
		/**
		 * __ 桩。
		 *
		 * @param string $text   文本。
		 * @param string $domain 文本域（忽略）。
		 * @return string
		 */
		function __( string $text, string $domain = 'wink-wp-gatekeeper' ): string {
			return $text;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\esc_attr' ) ) {
		/**
		 * esc_attr 桩。
		 *
		 * @param string $text 文本。
		 * @return string
		 */
		function esc_attr( string $text ): string {
			return htmlspecialchars( $text, ENT_QUOTES );
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\esc_html' ) ) {
		/**
		 * esc_html 桩。
		 *
		 * @param string $text 文本。
		 * @return string
		 */
		function esc_html( string $text ): string {
			return htmlspecialchars( $text, ENT_QUOTES );
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\esc_url' ) ) {
		/**
		 * esc_url 桩。
		 *
		 * @param string $url URL。
		 * @return string
		 */
		function esc_url( string $url ): string {
			return $url;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\esc_js' ) ) {
		/**
		 * esc_js 桩。
		 *
		 * @param string $text 文本。
		 * @return string
		 */
		function esc_js( string $text ): string {
			return $text;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\checked' ) ) {
		/**
		 * checked 桩。
		 *
		 * @param mixed $checked 现值。
		 * @param mixed $current 期望值。
		 * @param bool  $display 输出（忽略）。
		 * @return string
		 */
		function checked( $checked, $current = true, bool $display = true ): string {
			return ( (string) $checked === (string) $current ) ? ' checked="checked"' : '';
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\selected' ) ) {
		/**
		 * selected 桩。
		 *
		 * @param mixed $selected 现值。
		 * @param mixed $current  期望值。
		 * @param bool  $display  输出（忽略）。
		 * @return string
		 */
		function selected( $selected, $current = true, bool $display = true ): string {
			return ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\admin_url' ) ) {
		/**
		 * admin_url 桩。
		 *
		 * @param string $path 路径。
		 * @return string
		 */
		function admin_url( string $path = '' ): string {
			return 'https://example.test/wp-admin/' . $path;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\wp_nonce_url' ) ) {
		/**
		 * wp_nonce_url 桩。
		 *
		 * @param string $url    URL。
		 * @param string $action action（忽略）。
		 * @return string
		 */
		function wp_nonce_url( string $url, string $action = '' ): string {
			return $url . '&_wpnonce=stub';
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\wp_create_nonce' ) ) {
		/**
		 * wp_create_nonce 桩。
		 *
		 * @param string $action action（忽略）。
		 * @return string
		 */
		function wp_create_nonce( string $action = '' ): string {
			return 'stub-nonce';
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\number_format_i18n' ) ) {
		/**
		 * number_format_i18n 桩。
		 *
		 * @param mixed $number 数字。
		 * @return string
		 */
		function number_format_i18n( $number ): string {
			return (string) $number;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\add_query_arg' ) ) {
		/**
		 * add_query_arg 桩（tab_url 链接构造消费）。
		 *
		 * @param mixed ...$args 参数（忽略）。
		 * @return string
		 */
		function add_query_arg( ...$args ): string {
			return '';
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\date_i18n' ) ) {
		/**
		 * date_i18n 桩。
		 *
		 * @param string $format 格式（忽略）。
		 * @param mixed  $stamp 时间戳。
		 * @return string
		 */
		function date_i18n( string $format, $stamp = null ): string {
			return gmdate( 'Y-m-d H:i', (int) $stamp );
		}
	}
}

namespace WinkGatekeeper\UrlAudit {

	$GLOBALS['wk_ua_option_store']       = $GLOBALS['wk_ua_option_store'] ?? array();
	$GLOBALS['wk_ua_transient_store']    = $GLOBALS['wk_ua_transient_store'] ?? array();
	$GLOBALS['wk_ua_comment_meta_store'] = $GLOBALS['wk_ua_comment_meta_store'] ?? array();

	if ( ! function_exists( 'WinkGatekeeper\UrlAudit\get_option' ) ) {
		/**
		 * get_option 桩。
		 *
		 * @param string $name    option 名。
		 * @param mixed  $default 缺省值。
		 * @return mixed
		 */
		function get_option( string $name, $default = false ) {
			return array_key_exists( $name, $GLOBALS['wk_ua_option_store'] )
				? $GLOBALS['wk_ua_option_store'][ $name ]
				: $default;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\UrlAudit\update_option' ) ) {
		/**
		 * update_option 桩。
		 *
		 * @param string $name     option 名。
		 * @param mixed  $value    值。
		 * @param mixed  $autoload 自动加载（忽略）。
		 * @return bool
		 */
		function update_option( string $name, $value, $autoload = null ): bool {
			$GLOBALS['wk_ua_option_store'][ $name ] = $value;
			return true;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\UrlAudit\get_comment_meta' ) ) {
		/**
		 * get_comment_meta 桩。
		 *
		 * @param int    $comment_id 评论 id。
		 * @param string $key        meta 键。
		 * @param bool   $single     单值（忽略）。
		 * @return mixed
		 */
		function get_comment_meta( int $comment_id, string $key, bool $single = true ) {
			return $GLOBALS['wk_ua_comment_meta_store'][ $comment_id ][ $key ] ?? '';
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\UrlAudit\get_comment' ) ) {
		/**
		 * get_comment 桩。
		 *
		 * @param int $comment_id 评论 id。
		 * @return object|null
		 */
		function get_comment( int $comment_id ): ?object {
			$comment             = new \stdClass();
			$comment->comment_ID = $comment_id;
			return $comment;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\UrlAudit\maybe_unserialize' ) ) {
		/**
		 * maybe_unserialize 桩。
		 *
		 * @param mixed $data 原值。
		 * @return mixed
		 */
		function maybe_unserialize( $data ) {
			if ( is_string( $data ) && '' !== $data ) {
				$unserialized = @unserialize( $data );
				return false === $unserialized ? $data : $unserialized;
			}
			return $data;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\UrlAudit\get_transient' ) ) {
		/**
		 * get_transient 桩。
		 *
		 * @param string $key 键。
		 * @return mixed
		 */
		function get_transient( string $key ) {
			return $GLOBALS['wk_ua_transient_store'][ $key ] ?? null;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\UrlAudit\set_transient' ) ) {
		/**
		 * set_transient 桩。
		 *
		 * @param string $key   键。
		 * @param mixed  $value 值。
		 * @param int    $ttl   TTL（忽略）。
		 * @return bool
		 */
		function set_transient( string $key, $value, int $ttl = 0 ): bool {
			$GLOBALS['wk_ua_transient_store'][ $key ] = $value;
			return true;
		}
	}

	if ( ! defined( 'WinkGatekeeper\UrlAudit\ARRAY_A' ) ) {
		define( 'WinkGatekeeper\UrlAudit\ARRAY_A', 'ARRAY_A' );
	}
}

namespace WinkGatekeeper\Audit {

	if ( ! function_exists( 'WinkGatekeeper\Audit\maybe_unserialize' ) ) {
		/**
		 * maybe_unserialize 桩（ExistingAuditWorker::report_data 消费）。
		 *
		 * @param mixed $data 原值。
		 * @return mixed
		 */
		function maybe_unserialize( $data ) {
			if ( is_string( $data ) && '' !== $data ) {
				$unserialized = @unserialize( $data );
				return false === $unserialized ? $data : $unserialized;
			}
			return $data;
		}
	}
}

namespace WinkGatekeeper\Stats {

	$GLOBALS['wk_stats_option_store']  = $GLOBALS['wk_stats_option_store'] ?? array();
	$GLOBALS['wk_stats_calls']         = $GLOBALS['wk_stats_calls'] ?? array( 'update' => array(), 'error_log' => array(), 'get' => array() );
	$GLOBALS['wk_stats_update_result'] = $GLOBALS['wk_stats_update_result'] ?? true;

	if ( ! function_exists( 'WinkGatekeeper\Stats\get_option' ) ) {
		/**
		 * get_option 桩（M6A 同款）。
		 *
		 * @param string $name    option 名。
		 * @param mixed  $default 缺省值。
		 * @return mixed
		 */
		function get_option( string $name, $default = false ) {
			return array_key_exists( $name, $GLOBALS['wk_stats_option_store'] )
				? $GLOBALS['wk_stats_option_store'][ $name ]
				: $default;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\update_option' ) ) {
		/**
		 * update_option 桩（M6A 同款）。
		 *
		 * @param string $name     option 名。
		 * @param mixed  $value    值。
		 * @param mixed  $autoload 自动加载（忽略）。
		 * @return bool
		 */
		function update_option( string $name, $value, $autoload = null ): bool {
			$GLOBALS['wk_stats_option_store'][ $name ] = $value;
			return true;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\current_time' ) ) {
		/**
		 * current_time 桩（M6A 同款）。
		 *
		 * @param string $type 类型。
		 * @return string
		 */
		function current_time( string $type ): string {
			if ( 'Ymd' === $type ) {
				return '20261117';
			}
			if ( 'Y-m-d' === $type ) {
				return '2026-11-17';
			}
			return '';
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\error_log' ) ) {
		/**
		 * error_log 桩。
		 *
		 * @param string $message 消息。
		 * @return bool
		 */
		function error_log( string $message ): bool {
			return true;
		}
	}
}

namespace WinkGatekeeper\Tests {

	use PHPUnit\Framework\TestCase;
	use WinkGatekeeper\Audit\ExistingAuditWorker;
	use WinkGatekeeper\Settings\SettingsPage;
	use WinkGatekeeper\Settings\SettingsSchema;
	use WinkGatekeeper\UrlAudit\UrlAuditWorker;

	final class M6H3UiPolishTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['wk_bridge_option_store']   = array();
			$GLOBALS['wk_ua_option_store']       = array();
			$GLOBALS['wk_ua_transient_store']    = array();
			$GLOBALS['wk_ua_comment_meta_store'] = array();
			$GLOBALS['wk_h_rejected_report']     = null;
			$GLOBALS['wk_stats_option_store']    = array();
			$GLOBALS['wpdb']                     = null;
		}

		/* ---------------------------------------------------------------
		 * 共用件（M6H2 同款）
		 * -------------------------------------------------------------- */

		private static function source( string $rel ): string {
			$src = file_get_contents( dirname( __DIR__ ) . '/' . $rel );
			self::assertIsString( $src, "{$rel} 必须可读" );
			return (string) $src;
		}

		private static function functionBody( string $src, string $name ): string {
			$start = strpos( $src, 'function ' . $name );
			self::assertNotFalse( $start, "函数 {$name} 必须存在" );
			$open  = strpos( $src, '{', $start );
			$depth = 0;
			$len   = strlen( $src );
			for ( $i = $open; $i < $len; $i++ ) {
				if ( '{' === $src[ $i ] ) {
					$depth++;
				} elseif ( '}' === $src[ $i ] ) {
					$depth--;
					if ( 0 === $depth ) {
						return substr( $src, $open, $i - $open + 1 );
					}
				}
			}
			self::fail( "函数 {$name} 花括号不配平" );
			return '';
		}

		/* ===============================================================
		 * H3-A Tab② 验证码未配置 notice（§0-1）——source-scan 面（整页直调桩洞深，
		 * DOM 级留真机/VERIFY 轮）
		 * =============================================================== */

		public function test_h3a_unconfigured_notice_renders_on_tab2(): void {
			$body = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_tab_captcha' );
			self::assertStringContainsString( "'' === \$active", $body, '未配置判定条件（驱动空串）' );
			self::assertStringContainsString( 'wk-captcha-unconfigured-notice', $body, '【H3-A】Tab② notice 锚' );
			self::assertStringContainsString( 'No captcha provider selected yet', $body, '文案与 Tab① 总览同义' );
			self::assertStringContainsString( 'wk-notice--warn', $body, '插件级黄条形态（【W3·批B 翻转】U-1 式——notice_yellow_bar 四级映射翻插件级类）' );
			// 条件块内才渲染：锚在 if 块之后（避免无条件渲染假绿——锚与条件同函数体+锚前有 if 判定）。
			$cond_pos = strpos( $body, "'' === \$active" );
			$anchor_pos = strpos( $body, 'wk-captcha-unconfigured-notice' );
			self::assertGreaterThan( $cond_pos, $anchor_pos, '锚在条件块内（非无条件渲染）' );
		}

		/* ===============================================================
		 * H3-B 历史体检运行中黄条（§0-2）
		 * =============================================================== */

		public function test_h3b_running_states_render_yellow_bar(): void {
			$body = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_tab_tools' );
			self::assertStringContainsString( 'wk-tools-audit-running-bar', $body, '【H3-B】体检运行中黄条锚' );
			// running/quota_paused 才黄条：渲染条件须含两态（source-scan 弱断言——行为面随下轮真机）。
			self::assertMatchesRegularExpression( "/self::notice_yellow_bar\(\s*'warn'/", $body, '插件级黄条 warn 级（【W3·批B 翻转】H3-B 运行中黄条迁 notice_yellow_bar——四级映射承载插件级类）' );
		}

		/* ===============================================================
		 * H3-C 原因明细补处置去向（§0-3）
		 * =============================================================== */

		public function test_h3c_existing_report_reasons_carry_disposal(): void {
			$GLOBALS['wpdb'] = new class() {
				/** @var string 表前缀。 */
				public $prefix = 'wp_';
				/** @var string commentmeta 表名。 */
				public $commentmeta = 'wp_commentmeta';
				/**
				 * prepare 桩。
				 *
				 * @param string $sql  SQL。
				 * @param mixed  ...$args 参数。
				 * @return string
				 */
				public function prepare( string $sql, ...$args ): string {
					return $sql;
				}
				/**
				 * get_col 桩（体检 meta 全量序列化行——reject×2+review×1+pass 不进堆）。
				 *
				 * @param string $sql SQL（忽略）。
				 * @return array<int, string>
				 */
				public function get_col( string $sql ): array {
					return array(
						(string) serialize( array( 'verdict' => 'reject', 'reason' => '垃圾广告', 'disposed_to' => 'spam' ) ),
						(string) serialize( array( 'verdict' => 'reject', 'reason' => '垃圾广告', 'disposed_to' => 'spam' ) ),
						(string) serialize( array( 'verdict' => 'review', 'reason' => '垃圾广告', 'disposed_to' => 'hold' ) ),
						(string) serialize( array( 'verdict' => 'pass', 'reason' => 'Normal', 'disposed_to' => 'none' ) ),
					);
				}
			};
			$rep = ( new ExistingAuditWorker( SettingsSchema::defaults() ) )->report_data();
			$rows = $rep['reasons'];
			self::assertCount( 2, $rows, '同原因不同去向拆两行（去向如实）' );
			$by_disposal = array();
			foreach ( $rows as $row ) {
				$by_disposal[ $row['disposal'] ] = $row;
			}
			self::assertSame( 2, $by_disposal['spam']['n'], 'reject 行 n=2 → 垃圾箱' );
			self::assertSame( 1, $by_disposal['hold']['n'], 'review 行 n=1 → 待审核' );
			self::assertSame( '垃圾广告', $by_disposal['spam']['reason'], '原因文本保留' );
		}

		public function test_h3c_url_report_reasons_carry_disposal(): void {
			$today = strtotime( gmdate( 'Y-m-d' ) . ' 12:00:00' );
			$metas = array(
				901 => array( 'verdict' => 'reject', 'reason' => 'fetch: blocked_safety', 'judged_at' => $today ),
				902 => array( 'verdict' => 'inconclusive', 'reason' => 'fetch: not_found', 'judged_at' => $today ),
				903 => array( 'verdict' => 'pass', 'reason' => '正常站', 'judged_at' => $today ),
			);
			$view = UrlAuditWorker::backlog_report_view(
				array( 'started_at' => $today - 60, 'ids' => array( 901, 902, 903 ), 'enqueued' => 3 ),
				$metas,
				0
			);
			$rows = $view['reasons'];
			$by_disposal = array();
			foreach ( $rows as $row ) {
				$by_disposal[ $row['disposal'] ] = $row;
			}
			self::assertSame( 1, $by_disposal['spam']['n'], '【H3-C】URL 报告 reject 行 → 垃圾箱' );
			self::assertSame( 1, $by_disposal['hold']['n'], 'inconclusive 行 → 待审核（转待审也是处置去向）' );
			self::assertSame( 'fetch: not_found', $by_disposal['hold']['reason'], '原因文本保留（用户例）' );
		}

		public function test_h3c_render_appends_disposal_words(): void {
			$body = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_tab_tools' );
			self::assertStringContainsString( '→ 垃圾箱', $body, '渲染行尾去向（垃圾箱）' );
			self::assertStringContainsString( '→ 待审核', $body, '渲染行尾去向（待审核）' );
		}

		/* ===============================================================
		 * H3-D 「谁拦的」纳入网址核查复核处置（§0-4——用户拍板转待审也算拦）
		 * =============================================================== */

		public function test_h3d_intercept_block_in_stats_section(): void {
			$body = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_stats_comment_section' );
			self::assertStringContainsString( 'wk-stats-url-intercept-block', $body, '【H3-D】谁拦的区新块锚' );
			self::assertStringContainsString( 'URL check (review handling)', $body, '块标题（区别于同步拦截链）' );
			self::assertStringContainsString( '%1$d sent to Spam today · %2$d to pending review', $body, '用户拍板口径：转待审也算拦——块文案含待审分量' );
			self::assertStringContainsString( "url_disposal['total']", $body, '数据源=H-9 today_disposal_line 复用（meta 反查零新事件）' );
		}

		public function test_h3d_block_zero_disposal_not_rendered(): void {
			$body = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_stats_comment_section' );
			// 零处置不虚设：块渲染须以 total>0 为门（source-scan 弱断言）。
			self::assertMatchesRegularExpression(
				'/url_disposal\[.total.\]\s*>\s*0/',
				$body,
				'今日零处置：块不渲染（不虚设行）'
			);
		}
	}
}
