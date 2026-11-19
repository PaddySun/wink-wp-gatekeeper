<?php
/**
 * M6 批 B · B-4（U-1）网址核查未配置 notice + B-5（U-3）url_audit 错误/暂停事件
 * 发射进报错历史（2026-11-16）。
 *
 * 施工依据（M6 定稿 §3 B-4/B-5 + M6-A-MANUAL-FEEDBACK-1 §1）：
 *   B-4  render_url_audit_card 内条件显隐：url_audit.enabled 且 AI 判定凭证链为空
 *        （凭证链语义=UrlAuditWorker::provider_config 同源：custom 且独立 key 非空=
 *        独立凭证；否则回落云审 DeepSeek key——空=链空）→ notice-warning inline 指引
 *        （上方 DeepSeek 配置区/下方高级独立配置+深链）；配置齐不渲染；DOM 契约两态。
 *   B-5  仅运行性错误发事件（gate=comment、stage=url_audit、driver=deepseek、
 *        verdict=error、meta 最小化 reason/lane/http_code——零 URL 零正文零 IP；
 *        每 tick 轮至多 1 条+暂停边沿去重）：①LLM error（transport/semantic/contract/
 *        parse——非业务 inconclusive）③配额暂停边沿（incr/batch，同日同车道去重）。
 *        恒不发射：业务性 inconclusive（目标站 fetch 失败族——§0-7 fetch 失败≠违规）。
 *        N2 fault_predicate_sql 覆盖核验（verdict=error 谓词无 gate/stage 过滤）+
 *        fault_timeline_rows 含之（报错历史可见契约）+Labels 人话补录。
 *
 * 桩注记：WinkGatekeeper\Settings 命名空间桩（渲染面 WP 函数——本测试文件首立，
 * function_exists 守卫同进程共存）；UrlAudit/Stats 桩与 M6AUrlAuditGateTest 同款。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Settings {

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
		 * @param string $text     文本。
		 * @param string $domain   文本域（忽略）。
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
		 * @param mixed $checked   现值。
		 * @param mixed $current   期望值。
		 * @param bool  $display   输出（忽略）。
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
		 * @param string $url   URL。
		 * @param string $action nonce action（忽略）。
		 * @return string
		 */
		function wp_nonce_url( string $url, string $action = '' ): string {
			return $url . '&_wpnonce=stub';
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\wp_create_nonce' ) ) {
		/**
		 * wp_create_nonce 桩（独立凭据测试按钮 data-nonce 渲染面）。
		 *
		 * @param string $action action（忽略）。
		 * @return string
		 */
		function wp_create_nonce( string $action = '' ): string {
			return 'stub-nonce';
		}
	}
}

namespace WinkGatekeeper\UrlAudit {

	$GLOBALS['wk_ua_option_store']    = $GLOBALS['wk_ua_option_store'] ?? array();
	$GLOBALS['wk_ua_transient_store'] = $GLOBALS['wk_ua_transient_store'] ?? array();

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
		 * @param string $key  键。
		 * @param mixed  $value 值。
		 * @param int    $ttl  TTL（忽略）。
		 * @return bool
		 */
		function set_transient( string $key, $value, int $ttl = 0 ): bool {
			$GLOBALS['wk_ua_transient_store'][ $key ] = $value;
			return true;
		}
	}
}

namespace WinkGatekeeper\Stats {

	$GLOBALS['wk_stats_option_store'] = $GLOBALS['wk_stats_option_store'] ?? array();
	$GLOBALS['wk_stats_calls']        = $GLOBALS['wk_stats_calls'] ?? array( 'update' => array(), 'error_log' => array(), 'get' => array() );

	if ( ! function_exists( 'WinkGatekeeper\Stats\get_option' ) ) {
		/**
		 * get_option 桩。
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
		 * update_option 桩。
		 *
		 * @param string $name    option 名。
		 * @param mixed  $value   值。
		 * @param mixed  $autoload 自动加载（忽略）。
		 * @return bool
		 */
		function update_option( string $name, $value, $autoload = null ): bool {
			$GLOBALS['wk_stats_calls']['update'][] = $name;
			$GLOBALS['wk_stats_option_store'][ $name ] = $value;
			return true;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\current_time' ) ) {
		/**
		 * current_time 桩。
		 *
		 * @param string $type 类型。
		 * @return string
		 */
		function current_time( string $type ): string {
			if ( 'Ymd' === $type ) {
				return '20261116';
			}
			if ( 'Y-m-d' === $type ) {
				return '2026-11-16';
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
	use WinkGatekeeper\Http\RemoteClient;
	use WinkGatekeeper\Settings\SettingsSchema;
	use WinkGatekeeper\Stats\EventsTable;
	use WinkGatekeeper\Stats\InterceptDigest;
	use WinkGatekeeper\Stats\Labels;
	use WinkGatekeeper\UrlAudit\UrlAuditWorker;
	use WinkGatekeeper\UrlAudit\UrlFetcher;
	use WinkGatekeeper\UrlAudit\UrlJudgeEngine;

	final class M6BNoticeAndEventsTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['wk_ua_option_store']    = array();
			$GLOBALS['wk_ua_transient_store'] = array();
			$GLOBALS['wk_stats_option_store'] = array();
		}

		private static function source( string $rel ): string {
			$src = file_get_contents( dirname( __DIR__ ) . '/' . $rel );
			self::assertIsString( $src, "{$rel} 必须可读" );
			return (string) $src;
		}

		/**
		 * 渲染 Tab③ 网址核查卡（Reflection 直调私有渲染——DOM 契约两态测试面）。
		 *
		 * @param array<string, mixed> $settings 设置树。
		 * @return string 渲染输出。
		 */
		private static function renderCard( array $settings ): string {
			$method = new \ReflectionMethod( \WinkGatekeeper\Settings\SettingsPage::class, 'render_url_audit_card' );
			$method->setAccessible( true );
			ob_start();
			$method->invoke( null, $settings );
			return (string) ob_get_clean();
		}

		/**
		 * 基础设置树（url_audit enabled + 云审 DeepSeek 可控）。
		 *
		 * @param string $ds_key 云审 DeepSeek api_key。
		 * @param bool   $on     url_audit.enabled。
		 * @return array<string, mixed>
		 */
		private static function baseSettings( string $ds_key = '', bool $on = true ): array {
			$settings = SettingsSchema::defaults();
			$settings['url_audit']['enabled'] = $on;
			$settings['review']['cloud']['deepseek']['api_key'] = $ds_key;
			return $settings;
		}

		/* ===============================================================
		 * B-4 未配置 notice（两态 DOM 契约）
		 * =============================================================== */

		public function test_notice_renders_when_enabled_and_chain_empty(): void {
			$html = self::renderCard( self::baseSettings( '' ) );
			self::assertStringContainsString( 'id="wk-url-audit-unconfigured-notice"', $html, '无钥态：notice 渲染' );
			self::assertStringContainsString( 'wk-notice--warn', $html, '插件级黄条形态（【W3·批B 翻转】原 WP 原生 notice-warning inline 字面——批 B 黄条 helper 化+四级映射翻插件级类）' );
			self::assertStringContainsString( 'The URL check is enabled but the AI verdict (DeepSeek) is not configured yet — checks cannot reach a verdict.', $html, '人话指引文案' );
			self::assertStringContainsString( '#wk-review-deepseek-card', $html, '深链：上方 DeepSeek 配置区' );
			self::assertStringContainsString( '#wk-url-audit-advanced', $html, '深链：下方高级独立配置' );
		}

		public function test_notice_hidden_when_cloud_key_present(): void {
			$html = self::renderCard( self::baseSettings( 'sk-present' ) );
			self::assertStringNotContainsString( 'wk-url-audit-unconfigured-notice', $html, '有钥态（inherit+云审 key）：notice 不渲染（条件显隐纪律）' );
		}

		public function test_notice_hidden_when_feature_disabled(): void {
			$html = self::renderCard( self::baseSettings( '', false ) );
			self::assertStringNotContainsString( 'wk-url-audit-unconfigured-notice', $html, '功能关：notice 不渲染' );
		}

		public function test_notice_hidden_when_custom_own_key_present(): void {
			$settings = self::baseSettings( '' );
			$settings['url_audit']['provider'] = array( 'mode' => 'custom', 'api_key' => 'sk-own', 'base_url' => '', 'model' => '' );
			$html = self::renderCard( $settings );
			self::assertStringNotContainsString( 'wk-url-audit-unconfigured-notice', $html, '独立凭证非空=链不空：notice 不渲染' );
		}

		public function test_notice_hidden_when_custom_key_empty_falls_back_to_cloud_key(): void {
			// 凭证链语义（provider_config 同源）：custom 且 key 空 → 回落云审——云审有钥=链不空。
			$settings = self::baseSettings( 'sk-cloud-fallback' );
			$settings['url_audit']['provider'] = array( 'mode' => 'custom', 'api_key' => '', 'base_url' => '', 'model' => '' );
			$html = self::renderCard( $settings );
			self::assertStringNotContainsString( 'wk-url-audit-unconfigured-notice', $html, 'custom 空 key 回落云审有钥：不误报' );
		}

		/* ===============================================================
		 * B-5 ① LLM 运行性错误发射（每轮至多 1 条+meta 最小化）
		 * =============================================================== */

		/**
		 * 构造判定引擎（判定响应可指定）。
		 *
		 * @param array<string, mixed>|null $judge_response 判定响应（null=传输失败形态）。
		 * @return UrlJudgeEngine
		 */
		private static function makeEngine( ?array $judge_response ): UrlJudgeEngine {
			$transport = static function ( string $url, array $args ) use ( $judge_response ) {
				if ( null === $judge_response ) {
					return false; // WP_Error/非数组=传输失败（RemoteResult classify → transport）。
				}
				return $judge_response;
			};
			return new UrlJudgeEngine(
				array( 'api_key' => 'sk-test', 'base_url' => 'https://api.deepseek.com', 'model' => 'deepseek-flash', 'timeout' => 8, 'min_confidence' => 0.8 ),
				new RemoteClient( $transport, null, static function ( int $ms ): void {} ),
				'deepseek-flash',
				null
			);
		}

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

		public function test_engine_marks_llm_runtime_error_on_transport_failure(): void {
			$engine = self::makeEngine( null );
			$out    = $engine->judge( array( 'title' => 'T', 'meta_description' => '', 'text' => '正文' ), 'https://example.com/', '作者\n正文', 'url_audit_incr' );
			self::assertSame( 'inconclusive', $out['verdict'], '传输失败=业务 inconclusive 保持待审（原语义不变）' );
			$meta = $engine->last_meta();
			self::assertArrayHasKey( 'llm_error', $meta, '运行性错误标记（B-5 事件源）' );
			self::assertSame( 'transport', $meta['llm_error']['category'] );
			self::assertSame( 0, $meta['llm_error']['http_code'], '传输失败未抵达=0' );
		}

		public function test_engine_marks_llm_runtime_error_on_parse_failure(): void {
			// 200 但模型输出非 JSON=contract/parse 类运行性错误（非业务 inconclusive）。
			$engine = self::makeEngine( array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => (string) json_encode( array(
					'choices' => array( array(
						'message'       => array( 'role' => 'assistant', 'content' => '抱歉我不能这样回答' ),
						'finish_reason' => 'stop',
					) ),
				) ),
			) );
			$out  = $engine->judge( array( 'title' => 'T', 'meta_description' => '', 'text' => '正文' ), 'https://example.com/', '作者\n正文', 'url_audit_batch' );
			$meta = $engine->last_meta();
			self::assertSame( 'inconclusive', $out['verdict'] );
			self::assertArrayHasKey( 'llm_error', $meta, '解析失败=模型契约异常（发射面）' );
		}

		public function test_engine_does_not_mark_error_on_business_verdict(): void {
			$engine = self::makeEngine( self::judgeOkResponse() );
			$engine->judge( array( 'title' => 'T', 'meta_description' => '', 'text' => '正文' ), 'https://example.com/', '作者\n正文', 'url_audit_incr' );
			self::assertArrayNotHasKey( 'llm_error', $engine->last_meta(), '业务判定（pass/reject/review）非运行性错误' );
		}

		/**
		 * 构造 worker（LLM 判定响应/事件 sink 可注入；评论/元读写通道默认注入桩——
		 * worker 不触默认 WP 通道，桩面最小化）。
		 *
		 * @param array<string, callable> $hooks 钩子覆盖（调用方优先）。
		 * @param array<string, mixed>    $ua    url_audit 子树覆盖。
		 * @param array<int, array<string, mixed>> $judge_responses 依次判定响应（null=传输失败）。
		 * @return UrlAuditWorker
		 */
		private function makeWorker( array $hooks = array(), array $ua = array(), array $judge_responses = array() ): UrlAuditWorker {
			$settings = SettingsSchema::defaults();
			$settings['url_audit']['enabled'] = true;
			// 【M6·批H·H-1】抓取管线测试夹具显式 self 档（inherit 现解析 unconfigured 不自动自抓——本批语义翻转，管线行为测试须显式选择抓取档）。
			$settings['url_audit']['fetcher']['mode'] = 'self';
			foreach ( $ua as $key => $value ) {
				$settings['url_audit'][ $key ] = $value;
			}
			$responses = $judge_responses;
			$hooks     = $hooks + array(
				'comment_reader' => static function ( int $id ): array {
					return array( 'author' => '甲', 'body' => '带网址的评论正文', 'url' => 'https://queued.example.com/', 'post_status' => 'publish' );
				},
				'meta_reader'    => static function ( int $id ): ?array {
					return null;
				},
				'meta_writer'    => static function ( int $id, ?array $meta ): void {},
				'disposal_executor' => static function ( int $id, string $action ): bool {
					return true;
				},
				// 默认注入「fetch ok」桩（UrlFetcher 产物同形键——LLM 链路测试不触真 fetcher；
				// fetch 失败类场景由调用方显式覆盖为失败 fetcher）。
				'fetcher_factory' => static function (): object {
					return new class() {
						/**
						 * @return array<string, mixed> UrlFetcher ok 产物同形。
						 */
						public function fetch( string $url, bool $robots_comply = false ): array {
							return array(
								'fetch_class'      => 'ok',
								'final_url'        => $url,
								'hop_hosts'        => array(),
								'status'           => 200,
								'content_type'     => 'text/html',
								'title'            => '示例标题',
								'og_title'         => '',
								'meta_description' => '示例描述',
								'meta_refresh'     => '',
								'js_redirect'      => '',
								'text'             => '这是足够长度的示例正文内容，喂判定引擎。',
								'robots_state'     => 'no_signal',
								'redirects'        => 0,
							);
						}
					};
				},
			);
			$hooks['judge_factory'] = static function () use ( &$responses ) {
				$resp = array_shift( $responses );
				$transport = static function ( string $url, array $args ) use ( $resp ) {
					return $resp; // null=传输失败形态。
				};
				$client = new RemoteClient( $transport, null, static function ( int $ms ): void {} );
				$engine = new UrlJudgeEngine(
					array( 'api_key' => 'sk-test', 'base_url' => 'https://api.deepseek.com', 'model' => 'deepseek-flash', 'timeout' => 8, 'min_confidence' => 0.8 ),
					$client,
					'deepseek-flash',
					null
				);
				return array( 'judge' => $engine, 'model' => 'deepseek-flash', 'bocha' => null );
			};
			return new UrlAuditWorker( $settings, $hooks );
		}

		/**
		 * 捕获事件 sink。
		 *
		 * @return array{events: array<int, array<string, mixed>>, sink: callable}
		 */
		private static function captureEvents(): array {
			$events = array();
			$sink   = static function ( string $reason, array $hits, array $meta ) use ( &$events ): void {
				$events[] = array( 'reason' => $reason, 'hits' => $hits, 'meta' => $meta );
			};
			return array( 'events' => &$events, 'sink' => $sink );
		}

		private static function failFetchWorkerHooks(): array {
			// fetch 失败类（业务 inconclusive）注入：500 响应的 UrlFetcher。
			$fail_client = new RemoteClient(
				static function (): array {
					return array( 'response' => array( 'code' => 500 ), 'headers' => array(), 'body' => 'boom' );
				},
				null,
				static function ( int $ms ): void {}
			);
			return array(
				'fetcher_factory' => static function () use ( $fail_client ): UrlFetcher {
					return new UrlFetcher( $fail_client );
				},
			);
		}

		public function test_llm_transport_error_emits_one_event_per_round(): void {
			$cap = self::captureEvents();
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_INCR_QUEUE ] = array( 11 );
			$worker = $this->makeWorker( array( 'event_recorder' => $cap['sink'] ), array(), array( null ) );
			$out    = $worker->run_round();

			self::assertSame( 1, $out['incr_processed'] );
			self::assertCount( 1, $cap['events'], 'LLM 传输失败 → 发 1 条运行性错误事件' );
		}

		public function test_event_shape_is_minimal_no_url_no_body_no_ip(): void {
			$cap    = self::captureEvents();
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_INCR_QUEUE ] = array( 12 );
			$worker = $this->makeWorker( array( 'event_recorder' => $cap['sink'] ), array(), array( null ) );
			$worker->run_round();

			self::assertCount( 1, $cap['events'] );
			$event = $cap['events'][0];
			self::assertSame( array( 'reason', 'lane', 'http_code' ), array_keys( $event['meta'] ), 'meta 最小化闭集（reason/lane/http_code）' );
			self::assertSame( 'incr', $event['meta']['lane'] );
			self::assertSame( 0, $event['meta']['http_code'], '传输失败 http_code=0' );
			$serialized = (string) json_encode( $event, JSON_UNESCAPED_UNICODE );
			self::assertStringNotContainsString( 'https://', $serialized, '零 URL' );
			self::assertStringNotContainsString( '“Comment must be manually approved”: %1$s; “Comment author must have a previously approved comment”: %2$s.', $serialized, '零评论正文' );
			self::assertStringNotContainsString( '117.', $serialized, '零 IP' );
			self::assertSame( array( 'remote_transport' ), $event['hits'], 'rule_hit=remote_<category>（故障谓词+人话映射复用）' );
		}

		public function test_business_inconclusive_never_emits(): void {
			// §0-7：fetch 失败≠违规——目标站业务类失败（500→conn_fail 族）恒不发事件。
			$cap = self::captureEvents();
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_INCR_QUEUE ] = array( 13 );
			$worker = $this->makeWorker( array( 'event_recorder' => $cap['sink'] ) + self::failFetchWorkerHooks(), array(), array( null ) );
			$out = $worker->run_round();
			self::assertSame( 1, $out['incr_processed'], 'fetch 失败照常消费（inconclusive 保持待审）' );
			self::assertSame( array(), $cap['events'], '业务性 inconclusive 恒不发（处置纪律——违者=施工偏差）' );
		}

		public function test_successful_judgement_emits_nothing(): void {
			$cap = self::captureEvents();
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_INCR_QUEUE ] = array( 14 );
			$worker = $this->makeWorker( array( 'event_recorder' => $cap['sink'] ), array(), array( self::judgeOkResponse() ) );
			$worker->run_round();
			self::assertSame( array(), $cap['events'], '正常判定零事件' );
		}

		public function test_disabled_worker_emits_nothing(): void {
			$cap = self::captureEvents();
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_INCR_QUEUE ] = array( 15 );
			$worker = $this->makeWorker( array( 'event_recorder' => $cap['sink'] ), array( 'enabled' => false ), array( null ) );
			$out = $worker->run_round();
			self::assertSame( 'disabled', $out['stopped'] );
			self::assertSame( array(), $cap['events'] );
		}

		public function test_default_channel_writes_events_table_with_lane_shape(): void {
			// 默认通道源码契约：gate=comment/stage=url_audit/driver=deepseek/verdict=error 直插
			// （DriverHealth health 行同款——verdict=error 被故障谓词覆盖，不经 should_record）。
			$src = self::source( 'includes/urlaudit/UrlAuditWorker.php' );
			self::assertStringContainsString( "EventsTable::insert( 'comment', 'url_audit', 'deepseek'", $src, '默认 WP 通道事件形态（施工图 B-5）' );
			self::assertStringContainsString( 'Verdict::error', $src, 'verdict=error' );
		}

		/* ===============================================================
		 * B-5 ③ 配额暂停边沿（同日同车道去重）
		 * =============================================================== */

		public function test_quota_pause_emits_edge_event_once(): void {
			$cap = self::captureEvents();
			\WinkGatekeeper\Stats\Usage::bump_option( 'url_audit_incr' ); // 播种=配额 1。
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_INCR_QUEUE ] = array( 21, 22 );
			$worker = $this->makeWorker(
				array( 'event_recorder' => $cap['sink'], 'incr_take' => static function ( int $limit ): array {
					return array( 21 ); // 恒有队头（模拟持续积压——每 tick 都会撞闸）。
				} ),
				array( 'incr_quota' => 1, 'batch_quota' => 0 ),
				array( self::judgeOkResponse() )
			);

			$out = $worker->run_round();

			self::assertSame( 'incr_quota_exhausted', $out['stopped'], '播种配额 → 增量停转（M6A 回归语义）' );
			self::assertCount( 1, $cap['events'], '暂停边沿发 1 条' );
			$event = $cap['events'][0];
			self::assertSame( array( 'quota_paused' ), $event['hits'], 'rule_hit=quota_paused' );
			self::assertSame( 'incr', $event['meta']['lane'] );
		}

		public function test_quota_pause_edge_deduped_within_same_day(): void {
			$cap = self::captureEvents();
			\WinkGatekeeper\Stats\Usage::bump_option( 'url_audit_incr' );
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_INCR_QUEUE ] = array( 21 );
			$hooks = array(
				'event_recorder' => $cap['sink'],
				'incr_take'      => static function ( int $limit ): array {
					return array( 21 );
				},
			);
			$worker = $this->makeWorker( $hooks, array( 'incr_quota' => 1, 'batch_quota' => 0 ), array( self::judgeOkResponse() ) );
			$worker->run_round();
			$count_after_first = count( $cap['events'] );
			self::assertGreaterThanOrEqual( 1, $count_after_first );

			$worker->run_round(); // 同日再撞闸。
			self::assertCount( $count_after_first, $cap['events'], '同日同车道暂停边沿去重（不逐 tick 重复发射）' );
		}

		public function test_pause_edge_option_declared_and_persisted(): void {
			$cap = self::captureEvents();
			\WinkGatekeeper\Stats\Usage::bump_option( 'url_audit_incr' );
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_INCR_QUEUE ] = array( 21 );
			$worker = $this->makeWorker(
				array( 'event_recorder' => $cap['sink'], 'incr_take' => static function ( int $limit ): array {
					return array( 21 );
				} ),
				array( 'incr_quota' => 1, 'batch_quota' => 0 ),
				array( self::judgeOkResponse() )
			);
			$worker->run_round();
			self::assertArrayHasKey(
				UrlAuditWorker::OPTION_PAUSE_EDGE,
				$GLOBALS['wk_ua_option_store'],
				'暂停边沿标记持久化（option 通道——重启后仍去重）'
			);
		}

		/* ===============================================================
		 * B-5 报错历史可见契约（fault 谓词核验+timeline+Labels 人话）
		 * =============================================================== */

		public function test_fault_predicate_covers_url_audit_error_rows(): void {
			// N2 fault_predicate_sql 按 verdict 判（无 gate/stage 枚举过滤）——stage=url_audit
			// 的 verdict=error 行天然覆盖（施工图「以 fault 谓词覆盖为准」核验点）。
			$predicate = EventsTable::fault_predicate_sql();
			self::assertStringContainsString( "verdict = 'error'", $predicate );
			self::assertStringNotContainsString( 'stage', $predicate, '谓词不按 stage 过滤（url_audit 行可见）' );

			$template = EventsTable::recent_faults_sql( 'wp_wk_events' );
			self::assertStringContainsString( $predicate, $template, '全闸故障时间线复用同源谓词' );
			self::assertStringNotContainsString( "gate IN", $template, '时间线不按 gate 收窄' );

			self::assertTrue( EventsTable::is_fault( 'error', '' ), 'verdict=error 恒故障（纯逻辑镜像）' );
		}

		public function test_fault_timeline_includes_url_audit_error_row(): void {
			// 造 1 条 url_audit error 事件行 → fault_timeline_rows 含之（「报错历史可见」契约）。
			$row = array(
				'id'         => 9,
				'event_no'   => 'WK-20261116-0009',
				'created_at' => '2026-11-16 10:00:00',
				'gate'       => 'comment',
				'stage'      => 'url_audit',
				'driver'     => 'deepseek',
				'verdict'    => 'error',
				'rule_hit'   => 'remote_transport',
				'meta'       => array( 'reason' => 'llm transport', 'lane' => 'incr', 'http_code' => 0 ),
			);
			$rows = InterceptDigest::fault_timeline_rows( array( $row ), array(), array( 'deepseek' => 'DeepSeek' ) );

			self::assertCount( 1, $rows, '报错历史可见' );
			self::assertSame( 'url_audit', $rows[0]['stage'] );
			self::assertSame( 'error', $rows[0]['verdict'] );
			self::assertSame( 'DeepSeek', $rows[0]['driver_label'] );
			self::assertStringNotContainsString( 'https://', (string) json_encode( $rows[0] ), '行数据零 URL（PIPL 最小化延续）' );
		}

		public function test_labels_human_words_for_new_stage_and_pause(): void {
			self::assertSame( '网址核查', Labels::stage_word( 'url_audit' ), 'stage 人话补录（时间线哪道防线列）' );
			self::assertSame( '评论门', Labels::gate_word( 'comment' ) );
			self::assertSame( '今日额度用完（网址核查暂停）', Labels::rule_hit_word( 'quota_paused' ), '暂停边沿 rule_hit 人话' );
			self::assertSame( '网络连不上', Labels::rule_hit_word( 'remote_transport' ) );
		}
	}
}
