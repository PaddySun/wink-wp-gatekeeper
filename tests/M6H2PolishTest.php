<?php
/**
 * M6·批 H2 · 抛光小批两件（2026-11-17，施工图 M6-H2-抛光-设计定稿 §0——TDD 先红后绿回归门）。
 *
 * 两件清单（§0 统筹定稿；用户指令 2026-11-17「先完成批 H2 抛光收尾」授权）：
 *   H2-1 未来驱动显示名映射源统一——SettingsPage::future_review_drivers() 提公开件 +
 *       Bootstrap $driver_labels 同源注入（openai_custom/moli 行名不裸键——当前零可见
 *       效果的一致性/防御面，IMPL-M6-I §六③ Backlog / 审计 BC-8 销账）。
 *   H2-2 DeepSeek 网页抓取连续未成功风险标记——fetch_provider=deepseek 且 fetch_class≠ok
 *       计数、ok 清零；N=5 边沿落一条事件（fetch_streak_alert，alerted 去重）+ Tab③ 黄条
 *       （n≥5 且 last_at<48h 新鲜窗；陈旧静默）。self/unconfigured 不计数（业务常态）。
 *
 * 桩注记：WinkGatekeeper\Settings/UrlAudit 命名空间桩与 M6HUiSemanticsTest/M6BNotice
 * AndEventsTest 先行桩同语义同 store 键（function_exists 守卫同进程共存——本文件字母序
 * 先于 M6HU* 装载、后于 M6B*，全套跑时复用 M6B 先行桩；单独跑本文件时自给自足）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Settings {

	$GLOBALS['wk_bridge_option_store'] = $GLOBALS['wk_bridge_option_store'] ?? array();

	if ( ! function_exists( 'WinkGatekeeper\Settings\get_option' ) ) {
		/**
		 * get_option 桩（与先行桩共用同一 store 键——同进程跨文件串扰防线）。
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
		function esc_html__( string $text, string $domain = 'wink-gatekeeper' ): string {
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
		function __( string $text, string $domain = 'wink-gatekeeper' ): string {
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
}

namespace WinkGatekeeper\UrlAudit {

	$GLOBALS['wk_ua_option_store']       = $GLOBALS['wk_ua_option_store'] ?? array();
	$GLOBALS['wk_ua_transient_store']    = $GLOBALS['wk_ua_transient_store'] ?? array();
	$GLOBALS['wk_ua_comment_meta_store'] = $GLOBALS['wk_ua_comment_meta_store'] ?? array();

	if ( ! function_exists( 'WinkGatekeeper\UrlAudit\get_option' ) ) {
		/**
		 * get_option 桩（与先行桩同键存储——同进程共存）。
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
		 * @param bool   $single     单值（忽略恒单值语义）。
		 * @return mixed
		 */
		function get_comment_meta( int $comment_id, string $key, bool $single = true ) {
			return $GLOBALS['wk_ua_comment_meta_store'][ $comment_id ][ $key ] ?? '';
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\UrlAudit\get_comment' ) ) {
		/**
		 * get_comment 桩（桩世界评论恒存在）。
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
		 * maybe_unserialize 桩（仅标量反序列化）。
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

namespace WinkGatekeeper\Stats {

	$GLOBALS['wk_stats_option_store']  = $GLOBALS['wk_stats_option_store'] ?? array();
	$GLOBALS['wk_stats_calls']         = $GLOBALS['wk_stats_calls'] ?? array( 'update' => array(), 'error_log' => array(), 'get' => array() );
	$GLOBALS['wk_stats_update_result'] = $GLOBALS['wk_stats_update_result'] ?? true;

	if ( ! function_exists( 'WinkGatekeeper\Stats\get_option' ) ) {
		/**
		 * get_option 桩（M6A 同款——Usage 计量路径消费）。
		 *
		 * @param string $name    option 名。
		 * @param mixed  $default 缺省值。
		 * @return mixed
		 */
		function get_option( string $name, $default = false ) {
			$GLOBALS['wk_stats_calls']['get'][] = $name;
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
			$GLOBALS['wk_stats_calls']['update'][] = $name;
			if ( $GLOBALS['wk_stats_update_result'] ) {
				$GLOBALS['wk_stats_option_store'][ $name ] = $value;
			}
			return $GLOBALS['wk_stats_update_result'];
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
			$GLOBALS['wk_stats_calls']['error_log'][] = $message;
			return true;
		}
	}
}

namespace WinkGatekeeper\Tests {

	use PHPUnit\Framework\TestCase;
	use WinkGatekeeper\Settings\SettingsPage;
	use WinkGatekeeper\Settings\SettingsSchema;
	use WinkGatekeeper\Stats\DashboardDigest;
	use WinkGatekeeper\Stats\Usage;
	use WinkGatekeeper\UrlAudit\UrlAuditWorker;

	final class M6H2PolishTest extends TestCase {

		private const YMD_DASH = '2026-11-17';

		protected function setUp(): void {
			$GLOBALS['wk_bridge_option_store']   = array();
			$GLOBALS['wk_ua_option_store']       = array();
			$GLOBALS['wk_ua_transient_store']    = array();
			$GLOBALS['wk_ua_comment_meta_store'] = array();
			$GLOBALS['wk_h_rejected_report']     = null;
			$GLOBALS['wk_stats_option_store']    = array();
			$GLOBALS['wk_stats_calls']           = array( 'update' => array(), 'error_log' => array(), 'get' => array() );
			$GLOBALS['wk_stats_update_result']   = true;
		}

		/* ---------------------------------------------------------------
		 * 共用件：源码函数体提取（M6HU 同款）+ 反射渲染 + worker 桩件
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

		private static function renderUrlCard( array $settings ): string {
			$method = new \ReflectionMethod( SettingsPage::class, 'render_url_audit_card' );
			$method->setAccessible( true );
			ob_start();
			$method->invoke( null, $settings );
			return (string) ob_get_clean();
		}

		private static function urlCardSettings( string $ds_key = '', bool $on = true, string $fetcher = '', ?array $chain = null ): array {
			$settings = SettingsSchema::defaults();
			$settings['url_audit']['enabled'] = $on;
			$settings['review']['cloud']['deepseek']['api_key'] = $ds_key;
			if ( '' !== $fetcher ) {
				$settings['url_audit']['fetcher']['mode'] = $fetcher;
			}
			if ( null !== $chain ) {
				$settings['review']['cloud']['chain'] = $chain;
			}
			return $settings;
		}

		/* ===============================================================
		 * H2-1 未来驱动显示名映射源统一（§0-1）
		 * =============================================================== */

		public function test_h21_future_review_drivers_public_static(): void {
			$method = new \ReflectionMethod( SettingsPage::class, 'future_review_drivers' );
			self::assertTrue( $method->isPublic(), '【H2-1】future_review_drivers 提为公开件（Bootstrap 注入同源——private 时代显示名唯一来源锁死在渲染面）' );
			self::assertTrue( $method->isStatic(), '静态规格表保持' );

			$future = $method->invoke( null );
			// 【M6·批E 契约翻转】openai_custom 已实装——脱离 future 表（显示名单一来源
			// 迁 registry_entry；本钉原断言 openai_custom label 在表，随退役翻转）。
			self::assertArrayNotHasKey( 'openai_custom', $future, 'openai_custom 已于 M6E 实装——脱离 future 表' );
			self::assertSame( '模力 System One', $future['moli']['label'] ?? '', 'moli 同表' );
		}

		public function test_h21_bootstrap_widget_labels_inject_future_source(): void {
			// source-scan 收窄到 render_dashboard_widget 函数体（I-1 坑注 2：注入面钉必须
			// 收窄——Bootstrap 全文件本就含 future_review_drivers 调用点〔Tab③ 下拉〕，
			// 不收窄则改动前也绿）。
			$body = self::functionBody( self::source( 'includes/Bootstrap.php' ), 'render_dashboard_widget' );
			self::assertStringContainsString( 'future_review_drivers', $body, '【H2-1】$driver_labels 构造经 future_review_drivers 同源注入（不硬抄字面）' );
		}

		public function test_h21_usage_card_review_row_future_label(): void {
			// 行为面：生产注入形态（含 future 两键——H2-1 后 Bootstrap 同构）→ 调用量卡
			// 评论审核域 openai_custom 行显人话名非裸键。
			$usage = Usage::seed();
			$usage = Usage::bump( $usage, self::YMD_DASH, 'openai_custom' );

			$settings                             = SettingsSchema::defaults();
			$settings['review']['cloud']['chain'] = array( 'openai_custom' );

			$labels = array(
				'deepseek'      => 'DeepSeek',
				'openai_custom' => '自定义 OpenAI 兼容端点',
				'moli'          => '模力 System One',
			);

			$out    = DashboardDigest::widget_overview( array(
				'settings'             => $settings,
				'stats'                => array( 'today' => '19700101', 'by_gate' => array() ),
				'usage'                => $usage,
				'health'               => array(),
				'today_ymd'            => '20261117',
				'today_dash'           => self::YMD_DASH,
				'captcha_configured'   => true,
				'captcha_driver'       => 'aliyun',
				'captcha_driver_label' => '阿里云验证码 2.0',
				'driver_labels'        => $labels,
				'cron_alive'           => true,
				'debug'                => false,
			) );
			$review = null;
			foreach ( $out['usage']['domains'] as $domain ) {
				if ( 'review' === $domain['id'] ) {
					$review = $domain;
				}
			}
			self::assertNotNull( $review, '评论审核域渲染（chain[0]=openai_custom+有计量）' );
			self::assertSame( '自定义 OpenAI 兼容端点', $review['rows'][0]['label'], '【H2-1】注入后 openai_custom 行显人话名（原裸 id 退役——未来实装批防御面）' );
			self::assertSame( 'openai_custom', $review['rows'][0]['driver_id'], 'driver_id 机器可读面不变' );
		}

		/* ===============================================================
		 * H2-2 DeepSeek 抓取连续未成功风险标记（§0-2）
		 * =============================================================== */

		/**
		 * worker 端到端桩件（一轮多任务、URL 随评论 id 变化——同 URL 缓存命中不 fetch 的
		 * 幂等面绕开；fetch_class/provider 恒定形态可控——H2-2 计数面）。
		 *
		 * @param array{fetch_class: string, fetch_provider: string} $item fetch 恒定返回形态。
		 * @param array<int, int>                                    $ids  一轮处理的评论 id 列表（每条一个不同 URL）。
		 * @param array<string, mixed>                               $extra_hooks 附加注入。
		 * @return array{hooks: array<string, mixed>, streak: array<string, mixed>|null, events: array<int, array{reason: string, hits: array<int, string>, meta: array<string, mixed>}>}
		 */
		private static function workerHarness( array $item, array $ids = array( 601 ), array $extra_hooks = array() ): array {
			$streak_store = array( 'value' => null );
			$events       = array();
			$queue        = $ids; // take(1) 弹队语义（worker 循环每次取一条——桩自管队列）。
			$hooks        = array(
				'incr_take'         => static function ( int $limit ) use ( &$queue ): array {
					return array() === $queue ? array() : array( array_shift( $queue ) );
				},
				'comment_reader'    => static fn( int $id ): array => array( 'author' => '甲', 'body' => '带网址的评论', 'url' => 'https://streak' . $id . '.example.com/', 'post_status' => 'publish' ),
				'meta_reader'       => static fn( int $id ): ?array => null,
				'meta_writer'       => static function ( int $id, ?array $meta ): void {},
				'disposal_executor' => static fn( int $id, string $action ): bool => true,
				'fetcher_factory'   => static fn(): object => new class( $item['fetch_class'], $item['fetch_provider'] ) {
						public function __construct( private string $class, private string $provider ) {}
						/**
						 * @return array<string, mixed>
						 */
						public function fetch( string $url, bool $robots_comply = false ): array {
							return array(
								'fetch_class'    => $this->class,
								'final_url'      => $url,
								'hop_hosts'      => array(),
								'status'         => 'ok' === $this->class ? 200 : 500,
								'content_type'   => 'text/html',
								'title'          => '',
								'og_title'       => '',
								'meta_description' => '',
								'meta_refresh'   => '',
								'js_redirect'    => '',
								'text'           => 'ok' === $this->class ? '足够长度的正文内容，进入判定。' : '',
								'robots_state'   => 'no_signal',
								'redirects'      => 0,
								'fetch_provider' => $this->provider,
							);
						}
					},
				'judge_factory'     => static function (): array {
					return array(
						'judge' => new class() {
							/**
							 * ok 路径判定（pass 形态——本测只关心 fetch 侧计数）。
							 *
							 * @return array<string, mixed>
							 */
							public function judge( array $fetched, string $url, string $context, string $caller ): array {
								return array( 'verdict' => 'pass', 'confidence' => 0.9, 'reason' => '正常站点', 'hits' => array() );
							}
							/**
							 * @return array<string, mixed>
							 */
							public function last_meta(): array {
								return array();
							}
						},
						'model' => 'deepseek-flash',
						'bocha' => null,
					);
				},
				'event_recorder'    => static function ( string $reason, array $hits, array $meta ) use ( &$events ): void {
					$events[] = array( 'reason' => $reason, 'hits' => $hits, 'meta' => $meta );
				},
				'batch_ids'         => static fn( int $limit ): array => array(),
				'streak_get'        => static function () use ( &$streak_store ) {
					return $streak_store['value'] ?? array();
				},
				'streak_set'        => static function ( array $value ) use ( &$streak_store ): void {
					$streak_store['value'] = $value;
				},
			);
			return array(
				'hooks'  => array_merge( $hooks, $extra_hooks ),
				'streak' => &$streak_store,
				'events' => &$events,
			);
		}

		private static function deepseekSettings(): array {
			$settings = SettingsSchema::defaults();
			$settings['url_audit']['enabled'] = true;
			$settings['url_audit']['fetcher']['mode'] = 'deepseek';
			$settings['url_audit']['fetcher']['api_key'] = 'sk-fetch-present';
			$settings['review']['cloud']['chain'] = array( 'deepseek' );
			$settings['review']['cloud']['deepseek']['api_key'] = 'sk-present';
			return $settings;
		}

		public function test_h22_streak_counts_failures_and_resets_on_ok(): void {
			// 一轮两连败（deepseek provider 非 ok）→ n=2。
			$h = self::workerHarness(
				array( 'fetch_class' => 'transport', 'fetch_provider' => 'deepseek' ),
				array( 601, 602 )
			);
			( new UrlAuditWorker( self::deepseekSettings(), $h['hooks'] ) )->run_round();
			self::assertSame( 2, $h['streak']['value']['n'] ?? null, '【H2-2】deepseek 非 ok 连续计数' );
			self::assertSame( 'transport', $h['streak']['value']['last_class'], '最近失败类记录' );

			// 一次 ok（新 URL 不命中缓存）→ 清零+alerted 复位。
			$h2 = self::workerHarness(
				array( 'fetch_class' => 'ok', 'fetch_provider' => 'deepseek' ),
				array( 701 )
			);
			$h2['streak']['value'] = $h['streak']['value'];
			( new UrlAuditWorker( self::deepseekSettings(), $h2['hooks'] ) )->run_round();
			self::assertSame( 0, $h2['streak']['value']['n'], 'ok 一次即清零（正常混队自愈）' );
			self::assertFalse( $h2['streak']['value']['alerted'], 'alerted 边沿随清零复位（下段可再报）' );
		}

		public function test_h22_streak_ignores_self_provider(): void {
			// 本站直抓失败=目标站业务常态（§0-7）——不计数。
			$h = self::workerHarness(
				array( 'fetch_class' => 'not_found', 'fetch_provider' => 'self' ),
				array( 601, 602, 603 )
			);
			( new UrlAuditWorker( self::deepseekSettings(), $h['hooks'] ) )->run_round();
			self::assertSame( 0, $h['streak']['value']['n'] ?? 0, 'self 失败不计数（fetch_provider 限定 deepseek——目标站业务常态）' );
		}

		public function test_h22_alert_event_edge_once_per_streak(): void {
			// 一轮 6 连败：第 5 次落恰 1 条事件；第 6 次不再落（alerted 去重）。
			$h = self::workerHarness(
				array( 'fetch_class' => 'transport', 'fetch_provider' => 'deepseek' ),
				array( 601, 602, 603, 604, 605, 606 )
			);
			( new UrlAuditWorker( self::deepseekSettings(), $h['hooks'] ) )->run_round();
			self::assertSame( 6, $h['streak']['value']['n'], '6 连败计数如实（事件不拦计数）' );
			self::assertCount( 1, $h['events'], '边沿语义：恰第 5 次一条事件' );
			self::assertContains( 'fetch_streak_alert', $h['events'][0]['hits'], 'rule_hit=fetch_streak_alert（报错历史可筛）' );
			self::assertSame( 5, $h['events'][0]['meta']['n'], '事件 meta 记阈值时刻 n=5（非后续 6）' );
			self::assertSame( 'transport', $h['events'][0]['meta']['last_class'], 'meta.last_class 溯源' );
		}

		public function test_h22_new_streak_after_reset_realerts(): void {
			// 5 败(1 事件) → ok 清零（新 URL 段）→ 再 5 败（再新 URL 段）→ 第二条事件（新边沿）。
			$h = self::workerHarness(
				array( 'fetch_class' => 'rate_limited', 'fetch_provider' => 'deepseek' ),
				array( 601, 602, 603, 604, 605 )
			);
			( new UrlAuditWorker( self::deepseekSettings(), $h['hooks'] ) )->run_round();
			self::assertCount( 1, $h['events'], '第一段边沿一条' );

			$h2 = self::workerHarness(
				array( 'fetch_class' => 'ok', 'fetch_provider' => 'deepseek' ),
				array( 701 )
			);
			$h2['streak']['value'] = $h['streak']['value'];
			( new UrlAuditWorker( self::deepseekSettings(), $h2['hooks'] ) )->run_round();

			$h3 = self::workerHarness(
				array( 'fetch_class' => 'transport', 'fetch_provider' => 'deepseek' ),
				array( 801, 802, 803, 804, 805 )
			);
			$h3['streak']['value'] = $h2['streak']['value'];
			( new UrlAuditWorker( self::deepseekSettings(), $h3['hooks'] ) )->run_round();
			self::assertCount( 1, $h3['events'], '第二段恰一条（新边沿可再报——清零复位生效）' );
			self::assertSame( 5, $h3['streak']['value']['n'], '第二段计数 5' );
		}

		public function test_h22_notice_renders_at_threshold_within_freshness(): void {
			$settings = self::urlCardSettings( 'sk-present', true, 'deepseek' );

			// 达阈+新鲜（now 传入读取器窗口内）→ 渲染+动态 N。
			$GLOBALS['wk_ua_option_store']['wink_gatekeeper_url_audit_fetch_streak'] = array(
				'n' => 7, 'last_class' => 'transport', 'last_at' => time() - 3600, 'alerted' => true,
			);
			$html = self::renderUrlCard( $settings );
			self::assertStringContainsString( 'id="wk-url-audit-fetch-streak-notice"', $html, '【H2-2】达阈+新鲜：黄条渲染' );
			self::assertStringContainsString( '连续 7 次未成功', $html, '动态 N 注入（H-2 printf 先例）' );
			self::assertStringContainsString( '请检查凭据、余额与端点状态', $html, '人话指引文案' );

			// 未达阈（n=4）→ 不渲染。
			$GLOBALS['wk_ua_option_store']['wink_gatekeeper_url_audit_fetch_streak'] = array(
				'n' => 4, 'last_class' => 'transport', 'last_at' => time() - 60, 'alerted' => false,
			);
			self::assertStringNotContainsString(
				'wk-url-audit-fetch-streak-notice',
				self::renderUrlCard( $settings ),
				'未达阈（个案失败常态）：不渲染'
			);

			// 陈旧（49h 前）→ 静默不渲染（修复后无新流量不永久挂条）。
			$GLOBALS['wk_ua_option_store']['wink_gatekeeper_url_audit_fetch_streak'] = array(
				'n' => 9, 'last_class' => 'transport', 'last_at' => time() - 49 * 3600, 'alerted' => true,
			);
			self::assertStringNotContainsString(
				'wk-url-audit-fetch-streak-notice',
				self::renderUrlCard( $settings ),
				'48h 新鲜窗外：陈旧静默'
			);

			// 功能关 → 不渲染。
			$GLOBALS['wk_ua_option_store']['wink_gatekeeper_url_audit_fetch_streak'] = array(
				'n' => 9, 'last_class' => 'transport', 'last_at' => time() - 60, 'alerted' => true,
			);
			self::assertStringNotContainsString(
				'wk-url-audit-fetch-streak-notice',
				self::renderUrlCard( self::urlCardSettings( 'sk-present', false, 'deepseek' ) ),
				'网址核查功能关：不渲染'
			);
		}

		public function test_h22_notice_data_reader_normalized(): void {
			$settings = self::urlCardSettings( 'sk-present', true, 'deepseek' );
			// Redis 对象缓存跨进程 int→string 同型（I-2 纪律推广）：数值串归一。
			$GLOBALS['wk_ua_option_store']['wink_gatekeeper_url_audit_fetch_streak'] = array(
				'n' => '5', 'last_class' => 'transport', 'last_at' => (string) ( time() - 60 ), 'alerted' => true,
			);
			$data = UrlAuditWorker::fetch_streak_notice_data( $settings );
			self::assertNotNull( $data, '数值串形态照常命中（is_numeric 归一——非裸 is_int）' );
			self::assertSame( 5, $data['n'], 'n 归一 int' );

			// 脏数据（n 非数值）→ 视为 0 不渲染。
			$GLOBALS['wk_ua_option_store']['wink_gatekeeper_url_audit_fetch_streak'] = array(
				'n' => 'abc', 'last_class' => 'x', 'last_at' => time() - 60, 'alerted' => false,
			);
			self::assertNull( UrlAuditWorker::fetch_streak_notice_data( $settings ), '脏数据 fail-safe：不报不炸' );
		}

		public function test_h22_default_wp_channel_roundtrip(): void {
			// 不注入 streak 闭包 → 缺省 WP option 通道（wk_ua_option_store 桩可观测）。
			$h = self::workerHarness( array( 'fetch_class' => 'transport', 'fetch_provider' => 'deepseek' ) );
			unset( $h['hooks']['streak_get'], $h['hooks']['streak_set'] );
			( new UrlAuditWorker( self::deepseekSettings(), $h['hooks'] ) )->run_round();
			self::assertSame( 1, $GLOBALS['wk_ua_option_store']['wink_gatekeeper_url_audit_fetch_streak']['n'] ?? null, '缺省通道写 option（autoload=false）' );
		}

		public function test_h22_uninstall_covers_streak_option(): void {
			$src = self::source( 'uninstall.php' );
			self::assertStringContainsString( "'wink_gatekeeper_url_audit_fetch_streak'", $src, '【H2-2】卸载清单补 streak option（10→11）' );
		}
	}
}
