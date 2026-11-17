<?php
/**
 * M6·批 H · UI 与语义小批九件（2026-11-16，施工图 §0 九条——TDD 先红后绿回归门）。
 *
 * 九件清单（施工图 §0 统筹定稿，用户已拍板语义变更）：
 *   H-1 本站直抓永不自动启用——fetcher.mode=inherit 解析改「云端审核=DeepSeek→DS 抓取；
 *       其他（含未配置）→抓取未配置」：不自动自抓，judge 以 fetch:unconfigured→
 *       inconclusive→hold（批 E 语义）+U-1 式黄条「本站直抓因暴露源站 IP 默认不自动启用」；
 *       self 仅显式选择生效。
 *   H-2 登录闸描述动态化（按当前配置值渲染，不写死「10 次」）+ IP 聚合默认 10→3
 *       （特征值平移：出厂 10 平移新默认 3；显式其他值保留——P2-4 语义照旧）。
 *   H-3 默认验证码驱动 tencent→aliyun（schema 出厂默认平移+撞车守卫：天御凭据已配=
 *       站长显式选择原样保留；registry/UI 排列顺序不动）。
 *   H-4 仪表盘调用量卡五域固定顺序（人机验证→评论审核→〔内容审核本地不计数不显〕→
 *       网页抓取→网页搜索）+每域实际启用驱动行如实显示+本站直抓「不计费」行+零数据空态照旧。
 *   H-5 「AI 判定凭证」→「LLM提供商」+provider/fetcher 两处 inherit 选项文案统一
 *       （跟随云端审核语义，各自末句保留自身动作说明）。
 *   H-6 显式清除凭据控件——password 字段「清除此凭据」checkbox（hidden 0 伴随域+
 *       独立 <leaf>_clear 键名）；保存置空串；渲染层+sanitize 层双向契约钉；
 *       清除后 U-1 黄条联动照常。
 *   H-7 URL 核查运行中黄条——backlog 运行中→Tab⑦ 卡内 notice-warning + Tab① 站务
 *       通栏增条（对齐内容体检 A-U3 先例）；完成/空队列不显。
 *   H-8 报告截断省略号——截断函数单点改「…」，原因明细两处消费面生效。
 *   H-9 Tab⑥「最近拦下的评论」区头部网址核查处置汇总行（零新事件零写放大——meta 反查）。
 *
 * 桩注记：WinkGatekeeper\Settings/UrlAudit 命名空间桩（function_exists 守卫同进程
 * 共存——M6BNoticeAndEventsTest 先行桩共用同一 $GLOBALS 存储）；render_url_audit_card
 * 经 Reflection 直调（M6B 同款 DOM 契约两态测试面）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Settings {

	$GLOBALS['wk_bridge_option_store'] = $GLOBALS['wk_bridge_option_store'] ?? array();

	if ( ! function_exists( 'WinkGatekeeper\Settings\get_option' ) ) {
		/**
		 * get_option 桩（SettingsPageSanitizeBridgeTest 先行桩同款语义+同键存储——
		 * 本文件字母序先行装载，守卫使 bridge 测试定义被跳过，故必须共用同一 store 键
		 * 防同进程跨文件串扰）。
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
		 * do_action 桩（rejected 报告捕获面——保存桥 rejected 钩子观测用）。
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
		 * get_option 桩（与 M6BNoticeAndEventsTest 先行桩同键存储——同进程共存）。
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
		 * get_comment_meta 桩（url_audit meta 反查面）。
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
		 * get_comment 桩（backlog gone 收口判定——桩世界评论恒存在，gone 收口不触发）。
		 *
		 * @param int $comment_id 评论 id。
		 * @return object|null
		 */
		function get_comment( int $comment_id ): ?object {
			$comment       = new \stdClass();
			$comment->comment_ID = $comment_id;
			return $comment;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\UrlAudit\maybe_unserialize' ) ) {
		/**
		 * maybe_unserialize 桩（stats_line/host_aggregates meta 反查消费——桩面仅标量反序列化）。
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

	// wpdb 结果行模式常量桩（stats_line/host_aggregates/today_disposal_line 消费——
	// 生产 WP 全局常量；纯 PHP 桥测试面按命名空间解析解析桩值）。
	if ( ! defined( 'WinkGatekeeper\UrlAudit\ARRAY_A' ) ) {
		define( 'WinkGatekeeper\UrlAudit\ARRAY_A', 'ARRAY_A' );
	}
}

namespace WinkGatekeeper\Tests {

	use PHPUnit\Framework\TestCase;
	use WinkGatekeeper\Http\RemoteClient;
	use WinkGatekeeper\Settings\Sanitize;
	use WinkGatekeeper\Settings\SettingsPage;
	use WinkGatekeeper\Settings\SettingsSchema;
	use WinkGatekeeper\Stats\DashboardDigest;
	use WinkGatekeeper\Stats\Usage;
	use WinkGatekeeper\UrlAudit\UrlAuditWorker;
	use WinkGatekeeper\UrlAudit\UrlFetcher;
	use WinkGatekeeper\UrlAudit\UrlJudgeEngine;

	final class M6HUiSemanticsTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['wk_bridge_option_store']   = array();
			$GLOBALS['wk_ua_option_store']       = array();
			$GLOBALS['wk_ua_transient_store']    = array();
			$GLOBALS['wk_ua_comment_meta_store'] = array();
			$GLOBALS['wk_h_rejected_report']     = null;
		}

		/* ---------------------------------------------------------------
		 * 共用件：源码函数体提取（M6CReportsContractTest 同款）+ 反射渲染
		 * -------------------------------------------------------------- */

		private static function source( string $rel ): string {
			$src = file_get_contents( dirname( __DIR__ ) . '/' . $rel );
			self::assertIsString( $src, "{$rel} 必须可读" );
			return (string) $src;
		}

		/**
		 * 提取具名函数/方法体（花括号配平）——契约断言按作用域收窄，防全局误命中。
		 *
		 * @param string $src  源码全文。
		 * @param string $name 函数/方法名。
		 * @return string
		 */
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

		/**
		 * 渲染 Tab③ 网址核查卡（Reflection 直调私有渲染——M6B 同款）。
		 *
		 * @param array<string, mixed> $settings 设置树。
		 * @return string 渲染输出。
		 */
		private static function renderUrlCard( array $settings ): string {
			$method = new \ReflectionMethod( SettingsPage::class, 'render_url_audit_card' );
			$method->setAccessible( true );
			ob_start();
			$method->invoke( null, $settings );
			return (string) ob_get_clean();
		}

		/**
		 * 基础设置树（url_audit enabled + 云审 DeepSeek key 可控 + fetcher 可控）。
		 *
		 * @param string $ds_key     云审 DeepSeek api_key。
		 * @param bool   $on         url_audit.enabled。
		 * @param string $fetcher    fetcher.mode 覆盖（''=不动默认 inherit）。
		 * @param array<int, string> $chain 云审链覆盖（null=不动默认空链）。
		 * @return array<string, mixed>
		 */
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
		 * H-1 本站直抓永不自动启用（§0-1）
		 * =============================================================== */

		/**
		 * 构造判定引擎（M6B 同款——判定响应可指定；worker 工厂桩消费）。
		 *
		 * @param array<string, mixed>|null $judge_response 判定响应（null=传输失败形态）。
		 * @return UrlJudgeEngine
		 */
		private static function makeEngine( ?array $judge_response ): UrlJudgeEngine {
			$transport = static function ( string $url, array $args ) use ( $judge_response ) {
				return $judge_response; // null=传输失败形态。
			};
			return new UrlJudgeEngine(
				array( 'api_key' => 'sk-test', 'base_url' => 'https://api.deepseek.com', 'model' => 'deepseek-flash', 'timeout' => 8, 'min_confidence' => 0.8 ),
				new RemoteClient( $transport, null, static function ( int $ms ): void {} ),
				'deepseek-flash',
				null
			);
		}

		/**
		 * fetcher_mode 解析矩阵（inherit 跟随切换语义翻转：其他驱动/未配置=unconfigured）。
		 */
		public function test_h1_fetcher_mode_inherit_matrix(): void {
			$mk = static function ( array $ua_overrides = array(), array $chain = array() ): array {
				$settings = SettingsSchema::defaults();
				foreach ( $ua_overrides as $key => $value ) {
					$settings['url_audit'][ $key ] = $value;
				}
				$settings['review']['cloud']['chain'] = $chain;
				return $settings;
			};

			self::assertSame( 'deepseek', UrlAuditWorker::fetcher_mode( $mk( array(), array( 'deepseek' ) ) ), 'inherit+云审 DeepSeek → DS 抓取（语义不变）' );
			self::assertSame( 'self', UrlAuditWorker::fetcher_mode( $mk( array( 'fetcher' => array( 'mode' => 'self', 'api_key' => '' ) ) ) ), 'self 仅显式选择生效（显式档原样）' );
			self::assertSame( 'deepseek', UrlAuditWorker::fetcher_mode( $mk( array( 'fetcher' => array( 'mode' => 'deepseek', 'api_key' => '' ) ) ) ), 'deepseek 显式档原样' );
			self::assertSame( 'unconfigured', UrlAuditWorker::fetcher_mode( $mk( array(), array( 'tencent_tms' ) ) ), '【H-1 翻转】inherit+其他驱动 → 抓取未配置（不再自动本站直抓）' );
			self::assertSame( 'unconfigured', UrlAuditWorker::fetcher_mode( $mk() ), '【H-1 翻转】inherit+云审未配置（空链） → 抓取未配置' );
		}

		/**
		 * 抓取未配置档 worker 行为：零 fetch 零 judge 零缓存，judge 走
		 * fetch:unconfigured → inconclusive → hold（批 E 语义）。
		 */
		public function test_h1_worker_unconfigured_no_fetch_judge_via_hold(): void {
			$settings = SettingsSchema::defaults();
			$settings['url_audit']['enabled'] = true;
			$settings['review']['cloud']['chain'] = array( 'tencent_tms' ); // inherit（默认）+非 DeepSeek → unconfigured。

			$metas    = array();
			$holds    = array();
			$fetch_calls = 0;
			$judge_calls = 0;

			$taken = false; // 队头只出一次（防空转循环——桩世界队列容量 1）。
			$hooks = array(
				'incr_take'       => static function ( int $limit ) use ( &$taken ): array {
					if ( $taken ) {
						return array();
					}
					$taken = true;
					return array( 501 );
				},
				'comment_reader'  => static fn( int $id ): array => array( 'author' => '甲', 'body' => '带网址的评论', 'url' => 'https://queued.example.com/', 'post_status' => 'publish' ),
				'meta_reader'     => static fn( int $id ): ?array => null,
				'meta_writer'     => static function ( int $id, ?array $meta ) use ( &$metas ): void {
					$metas[ $id ] = $meta;
				},
				'disposal_executor' => static function ( int $id, string $action ) use ( &$holds ): bool {
					$holds[] = array( $id, $action );
					return true;
				},
				'fetcher_factory' => static function () use ( &$fetch_calls ): object {
					++$fetch_calls;
					return new UrlFetcher( new RemoteClient( static fn( string $url, array $args ): bool => false, null, static function ( int $ms ): void {} ) );
				},
				'judge_factory'   => static function () use ( &$judge_calls ): array {
					++$judge_calls;
					return array( 'judge' => self::makeEngine( null ), 'model' => 'deepseek-flash', 'bocha' => null );
				},
				'batch_ids'       => static fn( int $limit ): array => array(),
			);

			$out = ( new UrlAuditWorker( $settings, $hooks ) )->run_round();

			self::assertSame( 0, $fetch_calls, '抓取未配置：本站直抓不自动发生（fetcher 工厂零调用）' );
			self::assertSame( 0, $judge_calls, '抓取未配置：零外呼零判定调用' );
			self::assertSame( 'inconclusive', $metas[501]['verdict'] ?? '', 'judge 语义=fetch:unconfigured → inconclusive' );
			self::assertSame( 'fetch: unconfigured', $metas[501]['reason'] ?? '', '原因串照 fetch 失败族口径' );
			self::assertSame( 'hold', $metas[501]['disposed_to'] ?? '', '批 E 语义：inconclusive→hold（待审=统一人工判定入口）' );
			self::assertSame( 'unconfigured', $metas[501]['fetch']['fetch_provider'] ?? '', 'meta 溯源 fetch_provider=unconfigured' );
			self::assertSame( array( array( 501, 'hold' ) ), $holds, '处置执行=进待审' );
			self::assertSame( array(), $GLOBALS['wk_ua_transient_store'], '配置态不写 URL 判定缓存（缓存是 URL 语义非配置语义——配置后同 URL 应真判）' );
			self::assertSame( 1, $out['incr_processed'], '条目正常收口不空转' );
		}

		/**
		 * 显式 self 档照常抓取（回归钉——「self 仅显式选择生效」的另一半）。
		 */
		public function test_h1_explicit_self_still_fetches(): void {
			$settings = SettingsSchema::defaults();
			$settings['url_audit']['enabled'] = true;
			$settings['url_audit']['fetcher']['mode'] = 'self';
			$settings['review']['cloud']['chain'] = array( 'tencent_tms' );

			$metas = array();
			$taken = false;
			$hooks = array(
				'incr_take'       => static function ( int $limit ) use ( &$taken ): array {
					if ( $taken ) {
						return array();
					}
					$taken = true;
					return array( 502 );
				},
				'comment_reader'  => static fn( int $id ): array => array( 'author' => '乙', 'body' => '带网址的评论', 'url' => 'https://self.example.com/', 'post_status' => 'publish' ),
				'meta_reader'     => static fn( int $id ): ?array => null,
				'meta_writer'     => static function ( int $id, ?array $meta ) use ( &$metas ): void {
					$metas[ $id ] = $meta;
				},
				'disposal_executor' => static fn( int $id, string $action ): bool => true,
				'fetcher_factory' => static function (): object {
					return new class() {
						/**
						 * fetch ok 产物同形（M6B 桩同款）。
						 *
						 * @return array<string, mixed>
						 */
						public function fetch( string $url, bool $robots_comply = false ): array {
							return array(
								'fetch_class'      => 'ok',
								'final_url'        => $url,
								'hop_hosts'        => array(),
								'status'           => 200,
								'content_type'     => 'text/html',
								'title'            => '标题',
								'og_title'         => '',
								'meta_description' => '描述',
								'meta_refresh'     => '',
								'js_redirect'      => '',
								'text'             => '足够长度的正文内容，进入判定。',
								'robots_state'     => 'no_signal',
								'redirects'        => 0,
							);
						}
					};
				},
				'judge_factory'   => static function (): array {
					return array( 'judge' => self::makeEngine( self::judgeOkResponse() ), 'model' => 'deepseek-flash', 'bocha' => null );
				},
				'event_recorder'  => static function ( string $reason, array $hits, array $meta ): void {}, // 桩世界零事件落库。
				'batch_ids'       => static fn( int $limit ): array => array(),
			);

			( new UrlAuditWorker( $settings, $hooks ) )->run_round();

			self::assertSame( 'self', $metas[502]['fetch']['fetch_provider'] ?? '', '显式 self 档照常本站直抓（语义保持）' );
		}

		/**
		 * 判定 ok 响应体（M6B 同款——200+pass JSON）。
		 *
		 * @return array<string, mixed>
		 */
		private static function judgeOkResponse(): array {
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => (string) json_encode( array(
					'choices' => array( array(
						'message'       => array( 'role' => 'assistant', 'content' => (string) json_encode( array( 'verdict' => 'pass', 'confidence' => 0.9, 'reason' => '正常站点', 'hits' => array() ) ) ),
						'finish_reason' => 'stop',
					) ),
					'usage'   => array( 'prompt_tokens' => 10, 'completion_tokens' => 5 ),
				) ),
			);
		}

		/**
		 * U-1 式黄条：enabled 且解析=unconfigured 才渲染；显式 self/inherit+DS/功能关不渲染。
		 */
		public function test_h1_unconfigured_fetcher_notice_states(): void {
			// 运行中态：inherit + 云审未配置。
			$html = self::renderUrlCard( self::urlCardSettings() );
			self::assertStringContainsString( 'id="wk-url-audit-fetcher-unconfigured-notice"', $html, '抓取未配置态：黄条渲染' );
			self::assertStringContainsString( 'notice notice-warning inline', $html, 'U-1 式=warning inline（范式对齐）' );
			self::assertStringContainsString( '本站直抓因暴露源站 IP 默认不自动启用', $html, '人话文案（不自动自抓原因）' );
			self::assertStringContainsString( '#wk-url-audit-advanced', $html, '深链高级区（手动选择入口）' );

			// 显式 self：已手动选择 → 不渲染。
			$html = self::renderUrlCard( self::urlCardSettings( '', true, 'self' ) );
			self::assertStringNotContainsString( 'wk-url-audit-fetcher-unconfigured-notice', $html, '显式 self=已选择：不渲染' );

			// inherit + 云审 DeepSeek（key 在）：抓取落 DS → 不渲染。
			$html = self::renderUrlCard( self::urlCardSettings( 'sk-present', true, '', array( 'deepseek' ) ) );
			self::assertStringNotContainsString( 'wk-url-audit-fetcher-unconfigured-notice', $html, 'inherit+DS：不渲染' );

			// 功能关：不渲染。
			$html = self::renderUrlCard( self::urlCardSettings( '', false ) );
			self::assertStringNotContainsString( 'wk-url-audit-fetcher-unconfigured-notice', $html, '功能关：不渲染' );
		}

		/* ===============================================================
		 * H-2 登录闸描述动态化 + IP 聚合默认 10→3（§0-2）
		 * =============================================================== */

		public function test_h2_ip_threshold_factory_default_shifted_to_3(): void {
			self::assertSame( 3, SettingsSchema::defaults()['captcha']['login_adaptive']['ip_threshold'], '【H-2 翻转】IP 聚合出厂默认 10→3' );
		}

		public function test_h2_ip_threshold_feature_value_migration(): void {
			// 旧出厂默认 10（站长从未动过）→ 平移新默认 3。
			$stored = array( 'captcha' => array( 'login_adaptive' => array( 'user_threshold' => 3, 'window_minutes' => 15, 'ip_threshold' => 10, 'iprep_gate' => true ) ) );
			$merged = SettingsSchema::merge_defaults( $stored );
			self::assertSame( 3, $merged['captcha']['login_adaptive']['ip_threshold'], '特征值 10 平移新默认 3' );

			// 显式其他值保留（P2-4 语义照旧）。
			$stored = array( 'captcha' => array( 'login_adaptive' => array( 'user_threshold' => 3, 'window_minutes' => 15, 'ip_threshold' => 5, 'iprep_gate' => true ) ) );
			$merged = SettingsSchema::merge_defaults( $stored );
			self::assertSame( 5, $merged['captcha']['login_adaptive']['ip_threshold'], '站长显式值（5）原样保留' );
		}

		public function test_h2_login_gate_description_renders_current_values(): void {
			$body = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_tab_captcha' );
			self::assertStringNotContainsString( '或同 IP 聚合 10 次', $body, '【H-2 翻转】登录闸描述不再写死「10 次」' );
			self::assertStringContainsString( '%2$d', $body, '描述按当前配置值渲染（占位符注入）' );
			self::assertStringContainsString( "'ip_threshold'] ?? 3", $body, 'IP 阈值输入框回落值随新默认 3' );
			self::assertStringContainsString( "'user_threshold'] ?? 3", $body, '用户阈值回落值 3（回归）' );
			self::assertStringContainsString( 'wk-login-adaptive-desc', $body, 'DOM 锚（描述行可断言）' );
		}

		public function test_h2_login_counters_fallback_follows_new_default(): void {
			$body = self::functionBody( self::source( 'includes/gates/LoginCounters.php' ), '__construct' );
			self::assertStringContainsString( "'ip_threshold'] ?? 3", $body, '【H-2 翻转】LoginCounters 防御回落随新默认 3' );
		}

		/* ===============================================================
		 * H-3 默认验证码驱动 tencent→aliyun（§0-3）
		 * =============================================================== */

		public function test_h3_captcha_driver_factory_default_shifted_to_aliyun(): void {
			self::assertSame( 'aliyun', SettingsSchema::defaults()['captcha']['driver'], '【H-3 翻转】出厂默认驱动 tencent→aliyun（用户拍板——天御免费额度门槛）' );
		}

		public function test_h3_captcha_driver_feature_value_migration(): void {
			// 未动过的出厂 tencent（无凭据）→ 平移 aliyun。
			$stored = array( 'captcha' => array( 'driver' => 'tencent', 'tencent' => array( 'app_id' => '', 'secret_id' => '', 'secret_key' => '', 'app_secret_key' => '' ) ) );
			self::assertSame( 'aliyun', SettingsSchema::merge_defaults( $stored )['captcha']['driver'], '出厂特征值 tencent（零凭据）平移 aliyun' );

			// 撞车守卫：天御凭据已配=站长显式选择 → 保留 tencent。
			$stored = array( 'captcha' => array( 'driver' => 'tencent', 'tencent' => array( 'app_id' => '199999164', 'secret_id' => 'AKID', 'secret_key' => 'sk', 'app_secret_key' => 'ask' ) ) );
			self::assertSame( 'tencent', SettingsSchema::merge_defaults( $stored )['captcha']['driver'], '天御凭据已配=显式选择：保留（B 机现役形态）' );

			// 显式其他值原样。
			$stored = array( 'captcha' => array( 'driver' => 'turnstile', 'turnstile' => array( 'site_key' => 'k', 'secret_key' => 's' ) ) );
			self::assertSame( 'turnstile', SettingsSchema::merge_defaults( $stored )['captcha']['driver'], '显式 turnstile 原样保留' );
		}

		public function test_h3_defensive_fallbacks_follow_new_default(): void {
			$digest = self::source( 'includes/stats/DashboardDigest.php' );
			self::assertStringNotContainsString( ": 'tencent';", $digest, '【H-3 翻转】DashboardDigest 驱动回落随新默认' );
			self::assertStringContainsString( ": 'aliyun';", $digest, '回落值=aliyun' );

			$widget = self::functionBody( self::source( 'includes/Bootstrap.php' ), 'render_dashboard_widget' );
			self::assertStringNotContainsString( ": 'tencent';", $widget, '【H-3 翻转】仪表盘健康卡驱动回落随新默认' );
			self::assertStringContainsString( ": 'aliyun';", $widget, '回落值=aliyun' );
		}

		public function test_h3_driver_select_order_unchanged(): void {
			// registry/UI 驱动排列顺序不动（D14 腾讯系优先只退位默认值——Tab② 下拉首项仍 tencent）。
			$spec = self::source( 'includes/settings/CaptchaTabSpec.php' );
			$tencent_pos = strpos( $spec, 'tencent' );
			$aliyun_pos  = strpos( $spec, 'aliyun' );
			self::assertNotFalse( $tencent_pos, '驱动规格表含 tencent' );
			self::assertNotFalse( $aliyun_pos, '驱动规格表含 aliyun' );
			self::assertLessThan( $aliyun_pos, $tencent_pos, '排列顺序不动：tencent 仍在 aliyun 之前（D14 顺序退位仅默认值）' );
		}

		/* ===============================================================
		 * H-4 仪表盘调用量卡五域固定顺序（§0-4）
		 * =============================================================== */

		/**
		 * 组装 Digest 输入（DashboardDigestTest 同款基线）。
		 *
		 * @param array<string, mixed> $overrides 覆盖键。
		 * @return array<string, mixed>
		 */
		private function digestArgs( array $overrides = array() ): array {
			$base = array(
				'settings'             => SettingsSchema::defaults(),
				'stats'                => array( 'today' => '19700101', 'by_gate' => array() ),
				'usage'                => Usage::seed(),
				'health'               => array(),
				'today_ymd'            => '20261116',
				'today_dash'           => '2026-11-16',
				'captcha_configured'   => true,
				'captcha_driver'       => 'aliyun',
				'captcha_driver_label' => '阿里云验证码 2.0',
				'driver_labels'        => array(),
				'cron_alive'           => true,
				'debug'                => false,
			);
			return array_merge( $base, $overrides );
		}

		public function test_h4_usage_domains_fixed_order(): void {
			$usage = Usage::seed();
			foreach ( array( 'aliyun', 'deepseek', 'url_audit_fetch', 'url_audit_batch', 'url_audit_incr', 'bocha_search', 'abuseipdb' ) as $key ) {
				$usage = Usage::bump( $usage, '2026-11-16', $key );
			}
			$settings = SettingsSchema::defaults();
			$settings['review']['cloud']['chain'] = array( 'deepseek' );
			$settings['url_audit']['enabled']     = true;
			$settings['url_audit']['fetcher']['mode'] = 'deepseek';

			$out = DashboardDigest::widget_overview( $this->digestArgs( array( 'usage' => $usage, 'settings' => $settings ) ) );

			$ids = array_column( $out['usage']['domains'], 'id' );
			self::assertSame( array( 'captcha', 'review', 'fetch', 'search', 'iprep' ), $ids, '【H-4】五域固定顺序（内容审核域=本地四闸不计数恒不渲染）' );
			self::assertStringContainsString( '人机验证', (string) json_encode( $out['usage']['domains'], JSON_UNESCAPED_UNICODE ), '域头人话' );
		}

		public function test_h4_review_domain_shows_enabled_driver_row(): void {
			// 百度审核 → 百度行；LLM 自定义 → 自定义行（混合搭配各域各行如实显示）。
			$usage = Usage::seed();
			$usage = Usage::bump( $usage, '2026-11-16', 'baidu' );

			$settings = SettingsSchema::defaults();
			$settings['review']['cloud']['chain'] = array( 'baidu' );

			$out = DashboardDigest::widget_overview( $this->digestArgs( array(
				'usage'         => $usage,
				'settings'      => $settings,
				'driver_labels' => array( 'baidu' => '百度文本审核' ),
			) ) );

			$review = null;
			foreach ( $out['usage']['domains'] as $domain ) {
				if ( 'review' === $domain['id'] ) {
					$review = $domain;
				}
			}
			self::assertNotNull( $review, '百度在链 → 评论审核域渲染' );
			self::assertCount( 1, $review['rows'] );
			self::assertSame( 'baidu', $review['rows'][0]['driver_id'], '百度审核显示百度行' );
			self::assertSame( '百度文本审核', $review['rows'][0]['label'] );

			// LLM 自定义行。
			$usage2 = Usage::seed();
			$usage2 = Usage::bump( $usage2, '2026-11-16', 'openai_custom' );
			$settings2 = SettingsSchema::defaults();
			$settings2['review']['cloud']['chain'] = array( 'openai_custom' );
			$out2 = DashboardDigest::widget_overview( $this->digestArgs( array( 'usage' => $usage2, 'settings' => $settings2 ) ) );
			$review2 = null;
			foreach ( $out2['usage']['domains'] as $domain ) {
				if ( 'review' === $domain['id'] ) {
					$review2 = $domain;
				}
			}
			self::assertNotNull( $review2, '自定义端点在链 → 评论审核域渲染' );
			self::assertSame( 'openai_custom', $review2[ 'rows'][0]['driver_id'], 'LLM 自定义显示自定义行' );
		}

		public function test_h4_self_fetch_shows_bill_free_row_without_counts(): void {
			$settings = SettingsSchema::defaults();
			$settings['url_audit']['enabled'] = true;
			$settings['url_audit']['fetcher']['mode'] = 'self'; // 显式本站直抓。

			$out = DashboardDigest::widget_overview( $this->digestArgs( array( 'settings' => $settings ) ) );

			$fetch = null;
			foreach ( $out['usage']['domains'] as $domain ) {
				if ( 'fetch' === $domain['id'] ) {
					$fetch = $domain;
				}
			}
			self::assertNotNull( $fetch, '本站直抓=抓取域渲染（不计费行声明）' );
			self::assertCount( 1, $fetch['rows'], '无计次行（本站直抓零计量——批 D 语义）' );
			self::assertTrue( (bool) $fetch['rows'][0]['bill_free'], '不计费行标记' );
			self::assertSame( 0, (int) $fetch['rows'][0]['used'], '无次数' );
			self::assertStringContainsString( '本站直接打开', (string) $fetch['rows'][0]['label'], '行文案=本站直接打开——不计费' );
		}

		public function test_h4_unconfigured_fetcher_no_bill_free_row(): void {
			// 抓取未配置（H-1 新态）：什么都不发生 → 不显示不计费行（不撒谎）。
			$settings = SettingsSchema::defaults();
			$settings['url_audit']['enabled'] = true; // inherit + 空链 → unconfigured。

			$out = DashboardDigest::widget_overview( $this->digestArgs( array( 'settings' => $settings ) ) );
			self::assertSame( array(), $out['usage']['domains'], '抓取未配置=零外呼零行（各域空态照旧）' );
		}

		public function test_h4_url_audit_disabled_drops_fetch_domain(): void {
			$usage = Usage::seed();
			$usage = Usage::bump( $usage, '2026-11-16', 'url_audit_fetch' );

			$settings = SettingsSchema::defaults();
			$settings['url_audit']['enabled'] = false; // 功能关。

			$out = DashboardDigest::widget_overview( $this->digestArgs( array( 'usage' => $usage, 'settings' => $settings ) ) );
			self::assertNotContains( 'fetch', array_column( $out['usage']['domains'], 'id' ), '网址核查关 → 抓取域整域不渲染' );
		}

		public function test_h4_empty_usage_keeps_zero_state(): void {
			$out = DashboardDigest::widget_overview( $this->digestArgs() );
			self::assertSame( array(), $out['usage']['domains'], '【H-4 翻转】零数据空态=零域（现款空态语义）' );
			self::assertSame( 0, $out['usage']['site_total'] );
		}

		public function test_h4_dashboard_widget_renders_domain_sections(): void {
			$widget = self::functionBody( self::source( 'includes/Bootstrap.php' ), 'render_dashboard_widget' );
			self::assertStringContainsString( "\$view['usage']['domains']", $widget, '仪表盘渲染消费五域视图模型' );
			self::assertStringContainsString( 'wk-widget-usage-domain', $widget, '域头 DOM 类锚' );
		}

		/* ===============================================================
		 * H-5 「AI 判定凭证」→「LLM提供商」+ inherit 文案统一（§0-5）
		 * =============================================================== */

		public function test_h5_provider_label_renamed_and_inherit_copy_unified(): void {
			$html = self::renderUrlCard( self::urlCardSettings() );
			self::assertStringContainsString( 'LLM提供商', $html, '【H-5 翻转】行标签改名' );
			self::assertStringNotContainsString( 'AI 判定凭证', $html, '旧术语全卡退役' );
			self::assertStringContainsString( '跟随云端审核（默认——云端审核为 DeepSeek 时使用与云端审核相同的配置', $html, '两处 inherit 选项文案统一（跟随语义）' );
			self::assertStringNotContainsString( '回落云端审核 DeepSeek 凭证（默认——零新增配置）', $html, 'provider 旧选项文案退役' );
			self::assertStringContainsString( '进行判定', $html, 'provider 选项末句保留自身动作说明' );
			self::assertStringContainsString( '进行抓取', $html, 'fetcher 选项末句保留自身动作说明' );
			self::assertStringContainsString( '抓取未配置', $html, 'fetcher inherit 末句说明新语义（其他驱动时抓取未配置）' );
		}

		/* ===============================================================
		 * H-6 显式清除凭据控件（渲染层+sanitize 层双向契约）（§0-6）
		 * =============================================================== */

		public function test_h6_password_field_renders_clear_control_when_configured(): void {
			$settings = self::urlCardSettings();
			$settings['url_audit']['provider']['mode']    = 'custom';
			$settings['url_audit']['provider']['api_key'] = 'sk-live';

			$html = self::renderUrlCard( $settings );
			self::assertStringContainsString( '清除此凭据', $html, '已配置凭据 → 清除控件渲染' );
			self::assertStringContainsString( '[api_key_clear]', $html, '独立清空键名（<leaf>_clear）' );
			self::assertMatchesRegularExpression( '/name="[^"]*\[api_key_clear\]"\s+value="0"/', $html, 'hidden 0 伴随域（未勾选显式提交 0）' );

			// 未配置凭据 → 无可清除，控件不渲染。
			$html = self::renderUrlCard( self::urlCardSettings() );
			self::assertStringNotContainsString( '清除凭据-stub-marker', $html );
			self::assertStringNotContainsString( '清除此凭据', $html, '未配置凭据 → 控件不渲染（无物可清）' );
		}

		public function test_h6_sanitize_clear_flag_empties_stored_credential(): void {
			$stored = SettingsSchema::defaults();
			$stored['url_audit']['provider']['api_key'] = 'sk-live';

			$input = array(
				'url_audit' => array(
					'enabled'   => '1',
					'provider'  => array(
						'mode'         => 'custom',
						'api_key'      => '',           // password 框恒空提交。
						'api_key_clear' => '1',         // 勾选清除。
						'base_url'     => '',
						'model'        => '',
					),
				),
			);

			$GLOBALS['wk_bridge_option_store'][ SettingsPage::OPTION_NAME ] = $stored;
			$saved = SettingsPage::sanitize( $input );

			self::assertSame( '', $saved['url_audit']['provider']['api_key'], '【H-6】清除旗标=1 → 凭据置空串（覆盖空串保留语义）' );
			self::assertArrayNotHasKey( 'api_key_clear', $saved['url_audit']['provider'], '清空旗标键不进设置树' );
			self::assertNotContains( 'url_audit.provider.api_key_clear', (array) $GLOBALS['wk_h_rejected_report'], '旗标键清洗前剥除——不进 rejected 报告' );
		}

		public function test_h6_sanitize_without_flag_preserves_stored_value(): void {
			// 回归钉：未勾选（hidden 0 / 键缺席）→ 空串保留旧值语义原样。
			$stored = SettingsSchema::defaults();
			$stored['url_audit']['provider']['api_key'] = 'sk-live';

			$input = array(
				'url_audit' => array(
					'enabled'  => '1',
					'provider' => array(
						'mode'        => 'custom',
						'api_key'     => '',
						'api_key_clear' => '0', // hidden 0 伴随域（未勾选）。
						'base_url'    => '',
						'model'       => '',
					),
				),
			);

			$GLOBALS['wk_bridge_option_store'][ SettingsPage::OPTION_NAME ] = $stored;
			$saved = SettingsPage::sanitize( $input );

			self::assertSame( 'sk-live', $saved['url_audit']['provider']['api_key'], '未勾选清除 → 空串保留旧值（既有语义回归）' );
		}

		public function test_h6_sanitize_new_value_wins_over_clear_flag(): void {
			$stored = SettingsSchema::defaults();
			$stored['url_audit']['provider']['api_key'] = 'sk-live';

			$input = array(
				'url_audit' => array(
					'enabled'  => '1',
					'provider' => array(
						'mode'          => 'custom',
						'api_key'       => 'sk-replacement',
						'api_key_clear' => '1',
						'base_url'      => '',
						'model'         => '',
					),
				),
			);

			$GLOBALS['wk_bridge_option_store'][ SettingsPage::OPTION_NAME ] = $stored;
			$saved = SettingsPage::sanitize( $input );

			self::assertSame( 'sk-replacement', $saved['url_audit']['provider']['api_key'], '清除+新值并存 → 新值优先（显式替换语义）' );
		}

		public function test_h6_clear_then_unconfigured_notice_linkage(): void {
			// 清除后 U-1 黄条联动照常：provider key 清空 → 凭证链空 → B-4 notice 渲染。
			$stored = SettingsSchema::defaults();
			$stored['url_audit']['enabled'] = true;
			$stored['url_audit']['provider']['mode']    = 'custom';
			$stored['url_audit']['provider']['api_key'] = 'sk-live';

			$input = array(
				'url_audit' => array(
					'enabled'  => '1',
					'provider' => array(
						'mode'          => 'custom',
						'api_key'       => '',
						'api_key_clear' => '1',
						'base_url'      => '',
						'model'         => '',
					),
				),
			);

			$GLOBALS['wk_bridge_option_store'][ SettingsPage::OPTION_NAME ] = $stored;
			$saved = SettingsPage::sanitize( $input );

			$html = self::renderUrlCard( $saved );
			self::assertStringContainsString( 'id="wk-url-audit-unconfigured-notice"', $html, '清除后 U-1 黄条联动（B-4 既有语义照常）' );
		}

		/* ===============================================================
		 * H-7 URL 核查运行中黄条（Tab⑦ 卡内 + Tab① 通栏）（§0-7）
		 * =============================================================== */

		public function test_h7_running_bar_in_tools_url_audit_card(): void {
			$tools = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_tab_tools' );
			self::assertStringContainsString( 'wk-tools-url-audit-running-bar', $tools, 'Tab⑦ 卡内运行中黄条 DOM 锚' );
			self::assertStringContainsString( '存量网址核查进行中', $tools, '人话文案' );
			self::assertStringContainsString( "'running' === \$url_view['state']", $tools, '条件=运行记录未完成或队列非空（backlog 报告三态 running）' );
		}

		public function test_h7_running_bar_in_overview_alerts(): void {
			$alerts = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_overview_alerts' );
			self::assertStringContainsString( 'wk-overview-url-backlog-bar', $alerts, 'Tab① 站务通栏增条 DOM 锚（对齐 A-U3 先例）' );
			self::assertStringContainsString( '存量网址核查进行中', $alerts, '人话文案' );
		}

		public function test_h7_backlog_running_state_semantics(): void {
			// 运行记录未完成（有 id 未落 meta）→ running；全收口 → completed；无记录 → empty。
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BACKLOG_RECORD ] = array(
				'started_at' => 1000,
				'ids'        => array( 601, 602 ),
				'enqueued'   => 2,
			);
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BATCH_QUEUE ] = array();
			$GLOBALS['wk_ua_comment_meta_store'][601][ UrlAuditWorker::META_KEY ] = array( 'verdict' => 'pass', 'judged_at' => 2000 );

			$worker = new UrlAuditWorker( SettingsSchema::defaults() );
			self::assertSame( 'running', $worker->backlog_report_data()['state'], '一条未落 meta=运行中' );

			$GLOBALS['wk_ua_comment_meta_store'][602][ UrlAuditWorker::META_KEY ] = array( 'verdict' => 'pass', 'judged_at' => 2001 );
			self::assertSame( 'completed', $worker->backlog_report_data()['state'], '全收口=完成（黄条熄灭）' );

			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BACKLOG_RECORD ] = array();
			self::assertSame( 'empty', $worker->backlog_report_data()['state'], '空记录=空态（黄条不显）' );
		}

		/* ===============================================================
		 * H-8 报告截断省略号（§0-8）
		 * =============================================================== */

		public function test_h8_truncate_reason_appends_ellipsis(): void {
			$method = new \ReflectionMethod( SettingsPage::class, 'truncate_reason' );
			$method->setAccessible( true );

			self::assertSame( '短原因', $method->invoke( null, '短原因' ), '未达限原样返回' );
			$long = str_repeat( '默', 50 );
			$out  = (string) $method->invoke( null, $long );
			self::assertSame( 41, mb_strlen( $out, 'UTF-8' ), '截断 40 字+省略号' );
			self::assertTrue( '…' === mb_substr( $out, 40, 1, 'UTF-8' ), '末字符=省略号' );

			$exact = str_repeat( '恰', 40 );
			self::assertSame( $exact, $method->invoke( null, $exact ), '恰 40 字不截断不加号' );
		}

		public function test_h8_url_report_aggregation_truncates_with_ellipsis(): void {
			// 【H-8 补钉】URL 报告原因归堆侧（backlog_report_view）同样 >40 字补「…」——
			// 归堆先于渲染截断，聚合不补则消费面永远只见裸 40 字（B 机手测样本复现形态）。
			$long  = str_repeat( '默', 50 );
			$metas = array(
				801 => array( 'verdict' => 'reject', 'reason' => $long, 'judged_at' => 2000 ),
			);
			$view = UrlAuditWorker::backlog_report_view(
				array( 'started_at' => 1000, 'ids' => array( 801 ), 'enqueued' => 1 ),
				$metas,
				0,
				array()
			);
			self::assertSame( 1, $view['caliber']['spam'], '口径守卫（垃圾箱 1 条）' );
			$reason = (string) $view['reasons'][0]['reason'];
			self::assertSame( 41, mb_strlen( $reason, 'UTF-8' ), '归堆原因=40 字+省略号' );
			self::assertSame( '…', mb_substr( $reason, 40, 1, 'UTF-8' ), '末字符=省略号（全消费面）' );

			$short_view = UrlAuditWorker::backlog_report_view(
				array( 'started_at' => 1000, 'ids' => array( 802 ), 'enqueued' => 1 ),
				array( 802 => array( 'verdict' => 'reject', 'reason' => '短原因', 'judged_at' => 2000 ) ),
				0,
				array()
			);
			self::assertSame( '短原因', (string) $short_view['reasons'][0]['reason'], '短原因原样不补号' );
		}

		public function test_h8_both_reason_surfaces_consume_single_truncate(): void {
			$tools = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_tab_tools' );
			$hits  = substr_count( $tools, 'self::truncate_reason(' );
			self::assertGreaterThanOrEqual( 2, $hits, '原因明细两处消费面（存量体检+网址核查）共用单点截断件' );
			self::assertStringNotContainsString( "mb_substr( (string) \$row['reason'], 0, 40 )", $tools, '【H-8 翻转】存量体检原因旧截断退役' );
		}

		/* ===============================================================
		 * H-9 Tab⑥ 网址核查今日处置汇总行（§0-9）
		 * =============================================================== */

		public function test_h9_today_disposal_line_counts_spam_and_hold(): void {
			// 今日/昨日按当前日期动态构造（批 H2 施工时〔2026-11-17〕发现原写死 2026-11-16
			// 的日期敏感形态——跨日跑「今日口径」恒空 total=0 假红；语义不变）。
			$today = strtotime( gmdate( 'Y-m-d' ) . ' 12:00:00' );
			$GLOBALS['wk_ua_comment_meta_store'] = array(
				701 => array( UrlAuditWorker::META_KEY => array( 'verdict' => 'reject', 'disposed_to' => 'spam', 'judged_at' => $today ) ),
				702 => array( UrlAuditWorker::META_KEY => array( 'verdict' => 'inconclusive', 'disposed_to' => 'hold', 'judged_at' => $today ) ),
				703 => array( UrlAuditWorker::META_KEY => array( 'verdict' => 'inconclusive', 'disposed_to' => 'hold', 'judged_at' => $today ) ),
				704 => array( UrlAuditWorker::META_KEY => array( 'verdict' => 'pass', 'disposed_to' => 'none', 'judged_at' => $today ) ),
				705 => array( UrlAuditWorker::META_KEY => array( 'verdict' => 'reject', 'disposed_to' => 'spam', 'judged_at' => $today - 86400 ) ), // 昨日不计数。
			);

			$GLOBALS['wpdb'] = new class() {
				/** @var string 前缀。 */
				public $prefix = 'wp_';

				/** @var string commentmeta 表名（worker 内插值消费）。 */
				public $commentmeta = 'wp_commentmeta';

				/**
				 * prepare 桩（恒原样）。
				 *
				 * @param string $sql  SQL。
				 * @param mixed  ...$args 参数。
				 * @return string
				 */
				public function prepare( string $sql, ...$args ): string {
					return $sql;
				}

				/**
				 * get_results 桩（commentmeta 全行——meta_value 列反序列化形态）。
				 *
				 * @param string $sql SQL（忽略）。
				 * @param string $mode 输出模式。
				 * @return array<int, array<string, string>>
				 */
				public function get_results( string $sql, string $mode = 'ARRAY_A' ): array {
					$rows = array();
					foreach ( $GLOBALS['wk_ua_comment_meta_store'] as $cid => $metas ) {
						foreach ( $metas as $key => $value ) {
							$rows[] = array( 'meta_value' => (string) serialize( $value ) );
						}
					}
					return $rows;
				}

				/**
				 * get_var 桩（未消费）。
				 *
				 * @param string $sql SQL。
				 * @return string
				 */
				public function get_var( string $sql ): string {
					return '0';
				}
			};

			$line = ( new UrlAuditWorker( SettingsSchema::defaults() ) )->today_disposal_line();
			self::assertSame( 3, $line['total'], '今日处置总数（垃圾箱+待审；pass/昨日不计数——零新事件零写放大，meta 反查口径）' );
			self::assertSame( 1, $line['spam'], '垃圾箱 X' );
			self::assertSame( 2, $line['hold'], '待审 Y' );
		}

		public function test_h9_summary_line_in_stats_comment_section(): void {
			$body = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'render_stats_comment_section' );
			self::assertStringContainsString( 'wk-stats-url-disposal-line', $body, 'Tab⑥ 拦截区头部汇总行 DOM 锚' );
			self::assertStringContainsString( '网址核查今日处置', $body, '人话文案' );
			self::assertStringContainsString( '#wk-tools-url-audit-report', $body, '深链 Tab⑦ 报告' );
			self::assertStringContainsString( 'today_disposal_line', $body, '数据源=meta 反查（零新事件零写放大）' );
		}
	}
}