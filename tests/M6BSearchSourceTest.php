<?php
/**
 * M6 批 B · B-1「核查前先搜一搜」移入高级折叠 + B-2 DeepSeek 搜索源（2026-11-16）。
 *
 * 施工依据（M6 定稿 §3 B-1/B-2 + §0-5 key 链统一 + §0-7 fetch 失败≠违规）：
 *   B-1  wk-url-audit-bocha-row 整行迁入 <details id="wk-url-audit-advanced">；
 *        字段 name 零变化（schema 零变化）；bocha.enabled hidden 0 伴随域照查。
 *   B-2  schema 新键 url_audit.search_source ∈ bocha(默认)|deepseek（Sanitize 枚举+
 *        merge_defaults）；新接口 UrlSearchProvider（与 BochaSearch 现签名同构）+
 *        新实现 DeepSeekSearch（POST {base}/v1/tools/search，Bearer，body
 *        {query,count:5,need_content:false}；key 链=AI 判定凭证链 §0-5；结果映射
 *        与 Bocha 消费侧对齐）；搜索失败→信号缺失降级（搜索失败≠违规）；计量键
 *        deepseek_search（成功次——官方计费=200 且 results 非空才计费，响应侧计数
 *        与账单口径一致；bocha_search K3 按次含失败不动）；judge 工厂按
 *        search_source 分派；Tab⑥ 消耗段加行。
 *
 * 桩注记：WinkGatekeeper\UrlAudit / WinkGatekeeper\Stats 命名空间桩与
 * M6AUrlAuditGateTest 同款同 $GLOBALS 键（function_exists 守卫同进程共存）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

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
		 * get_option 桩（StatsCacheRecordBridgeTest 同款）。
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
		 * current_time 桩（固定日——计量窗口判定面）。
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
	use WinkGatekeeper\Settings\Sanitize;
	use WinkGatekeeper\Settings\SettingsSchema;
	use WinkGatekeeper\Stats\Usage;
	use WinkGatekeeper\UrlAudit\BochaSearch;
	use WinkGatekeeper\UrlAudit\DeepSeekSearch;
	use WinkGatekeeper\UrlAudit\UrlJudgeEngine;
	use WinkGatekeeper\UrlAudit\UrlSearchProvider;

	final class M6BSearchSourceTest extends TestCase {

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

		private static function blockBetween( string $src, string $startMarker, string $endMarker ): string {
			$start = strpos( $src, $startMarker );
			self::assertNotFalse( $start, "HTML 须含 {$startMarker}" );
			$end = strpos( $src, $endMarker, $start + strlen( $startMarker ) );
			self::assertNotFalse( $end, "须在 {$startMarker} 之后含 {$endMarker}" );
			return substr( $src, $start, $end - $start );
		}

		/**
		 * 构造带捕获传输脊的 RemoteClient（响应队列+请求留痕）。
		 *
		 * @param array<int, array<string, mixed>> $responses 依次返回的 WP HTTP 形态响应。
		 * @return array{client: RemoteClient, requests: array<int, array<string, mixed>>, usage_keys: array<int, string>}
		 */
		private static function makeClient( array $responses ): array {
			$requests  = array();
			$usage_keys = array();
			$transport = static function ( string $url, array $args ) use ( &$responses, &$requests ) {
				$requests[] = array( 'url' => $url, 'args' => $args );
				return array_shift( $responses );
			};
			$client = new RemoteClient(
				$transport,
				static function ( string $key ) use ( &$usage_keys ): void {
					$usage_keys[] = $key;
				},
				static function ( int $ms ): void {}
			);
			return array( 'client' => $client, 'requests' => &$requests, 'usage_keys' => &$usage_keys );
		}

		/** @return array<string, mixed> DeepSeek Search 官方文档示例响应（api/网页搜索.md 2026-11-14 采集）。 */
		private static function deepseekSearchResponse(): array {
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => (string) json_encode( array(
					'results' => array(
						array(
							'rerank_score' => 2.4,
							'title'        => '示例页标题',
							'url'          => 'https://example.com/page',
							'snippet'      => '短摘要（前端展现用）',
							'summary'      => '长摘要（模型阅读用）',
							'content'      => '',
							'site_name'    => 'Example News',
							'site_icon'    => null,
							'date'         => null,
							'authority'    => 3,
						),
					),
				) ),
			);
		}

		/* ===============================================================
		 * B-1 搜一搜移入高级折叠（整行迁移+伴随域+字段名零变化）
		 * =============================================================== */

		public function test_bocha_row_lives_inside_advanced_details(): void {
			$sp     = self::source( 'includes/settings/SettingsPage.php' );
			$fold   = self::blockBetween( $sp, 'id="wk-url-audit-advanced"', 'wk-url-audit-inert-notice' );
			self::assertStringContainsString( 'id="wk-url-audit-bocha-row"', $fold, '博查行整行迁入高级折叠（N2：核查前先搜一搜属高级配置）' );
		}

		public function test_bocha_row_no_longer_outside_advanced(): void {
			$sp   = self::source( 'includes/settings/SettingsPage.php' );
			$head = substr( $sp, 0, (int) strpos( $sp, 'id="wk-url-audit-advanced"' ) );
			self::assertStringNotContainsString( 'wk-url-audit-bocha-row', $head, '折叠之前的主体 form-table 不得再含博查行（迁移而非复制）' );
		}

		public function test_bocha_hidden_zero_companion_survives_move(): void {
			// checkbox hidden 0 伴随域照查（迁移勿丢——关闭态提交语义不变）。
			$sp   = self::source( 'includes/settings/SettingsPage.php' );
			$fold = self::blockBetween( $sp, 'id="wk-url-audit-advanced"', 'wk-url-audit-inert-notice' );
			self::assertStringContainsString( "array( 'url_audit', 'bocha', 'enabled' )", $fold, 'bocha.enabled 字段名零变化' );
			self::assertStringContainsString( 'value="0"', $fold, 'hidden 0 伴随域在位' );
			self::assertStringContainsString( "array( 'url_audit', 'bocha', 'api_key' )", $fold, 'bocha.api_key 字段名零变化' );
		}

		public function test_bocha_schema_rules_unchanged(): void {
			// schema 零变化（迁移不改行为面）——B-2 只新增 search_source 一键。
			$rules = Sanitize::rules();
			self::assertSame( 'bool', $rules['url_audit.bocha.enabled'] );
			self::assertSame( 'password', $rules['url_audit.bocha.api_key'] );
		}

		/* ===============================================================
		 * B-2 schema：url_audit.search_source ∈ bocha(默认)|deepseek
		 * =============================================================== */

		public function test_schema_search_source_default_bocha(): void {
			$defaults = SettingsSchema::defaults();
			self::assertSame( 'bocha', $defaults['url_audit']['search_source'], '默认博查不变（N2 可选加做——不改变既有行为）' );
		}

		public function test_merge_defaults_backfills_search_source(): void {
			$merged = SettingsSchema::merge_defaults( array(
				'url_audit' => array( 'enabled' => true ),
			) );
			self::assertSame( 'bocha', $merged['url_audit']['search_source'], '存量树 merge 补默认' );
		}

		public function test_sanitize_search_source_enum(): void {
			$rules = Sanitize::rules();
			self::assertSame( 'url_audit_search_source', $rules['url_audit.search_source'] ?? null, 'Sanitize 映射表须收录新键' );
			self::assertSame( 'deepseek', Sanitize::clean_value( 'deepseek', 'url_audit_search_source' ) );
			self::assertSame( 'bocha', Sanitize::clean_value( 'bocha', 'url_audit_search_source' ) );
			self::assertSame( 'bocha', Sanitize::clean_value( 'bogus', 'url_audit_search_source' ), '非法值归默认博查（fail-safe——零成本路径）' );
			self::assertSame( 'bocha', Sanitize::clean_value( '', 'url_audit_search_source' ) );
		}

		/* ===============================================================
		 * B-2 接口与实现：UrlSearchProvider + DeepSeekSearch
		 * =============================================================== */

		public function test_bocha_search_implements_provider_interface(): void {
			$ref = new \ReflectionClass( BochaSearch::class );
			self::assertTrue( $ref->implementsInterface( UrlSearchProvider::class ), '博查现实现挂新接口（同构签名）' );
		}

		public function test_deepseek_search_implements_provider_interface(): void {
			$env  = self::makeClient( array() );
			$ds   = new DeepSeekSearch( 'sk-test', 'https://api.deepseek.com', $env['client'] );
			$ref  = new \ReflectionClass( $ds );
			self::assertTrue( $ref->implementsInterface( UrlSearchProvider::class ) );
			self::assertTrue( $ds->is_configured() );
			self::assertFalse( ( new DeepSeekSearch( '', 'https://api.deepseek.com', $env['client'] ) )->is_configured(), 'key 空=未配置（引擎零信号降级）' );
		}

		public function test_deepseek_search_maps_official_response(): void {
			// 响应映射与 Bocha 消费侧对齐：snippets[]（snippet/summary ≤200）+siteName（site_name ≤100）。
			$env = self::makeClient( array( self::deepseekSearchResponse() ) );
			$ds  = new DeepSeekSearch( 'sk-test', 'https://api.deepseek.com', $env['client'] );

			$signal = $ds->search_site( 'example.com' );

			self::assertNotNull( $signal, '200 且 results 非空=有信号' );
			self::assertSame( array( '短摘要（前端展现用）' ), $signal['snippets'], 'snippet 优先（与 Bocha snippet→summary 回落同构）' );
			self::assertSame( 'Example News', $signal['siteName'], 'site_name → siteName 键名与 BochaSearch 返回形态一致' );
		}

		public function test_deepseek_search_request_shape(): void {
			// 官方契约钉死：POST {base}/v1/tools/search；Bearer；body {query,count:5,need_content:false}。
			$env = self::makeClient( array( self::deepseekSearchResponse() ) );
			$ds  = new DeepSeekSearch( 'sk-live-key', 'https://api.deepseek.com/', $env['client'] );
			$ds->search_site( 'gamble.example' );

			self::assertCount( 1, $env['requests'], '恰一次外呼' );
			$req = $env['requests'][0];
			self::assertSame( 'https://api.deepseek.com/v1/tools/search', $req['url'], '端点=POST {base}/v1/tools/search（尾斜杠归一）' );
			self::assertSame( 'Bearer sk-live-key', $req['args']['headers']['Authorization'], 'Bearer 鉴权（与判定同链同 key）' );
			$body = (array) json_decode( (string) $req['args']['body'], true );
			self::assertSame( 'gamble.example', $body['query'] );
			self::assertSame( 5, $body['count'], 'count 固定 5（施工图 B-2）' );
			self::assertFalse( $body['need_content'], 'need_content=false——snippet+summary 足够，响应体小' );
		}

		public function test_deepseek_search_fails_closed_to_null(): void {
			// 搜索失败→null（信号缺失降级——搜索失败≠违规，§0-7 同族语义）。
			$env = self::makeClient( array(
				array( 'response' => array( 'code' => 500 ), 'headers' => array(), 'body' => 'boom' ),
			) );
			$ds = new DeepSeekSearch( 'sk-test', 'https://api.deepseek.com', $env['client'] );
			self::assertNull( $ds->search_site( 'example.com' ), 'HTTP 500 → null（传输脊重试穷尽后语义类失败）' );
			self::assertNull( $ds->search_site( '' ), 'host 空 → null（零外呼）' );
		}

		public function test_deepseek_search_empty_results_no_signal(): void {
			// 200 且 results=[]（官方：无匹配结果，不计费）→ 零信号零计量。
			$env = self::makeClient( array(
				array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => (string) json_encode( array( 'results' => array() ) ),
				),
			) );
			$ds = new DeepSeekSearch( 'sk-test', 'https://api.deepseek.com', $env['client'] );
			self::assertNull( $ds->search_site( 'example.com' ) );
		}

		public function test_deepseek_search_metering_success_only(): void {
			// 成功次计量（官方计费口径=200 且 results 非空；失败/空结果不计）。
			$usage = Usage::seed();
			Usage::bump_option( 'preexisting_key' ); // 既有真实计量形态（今日计数不归零——delta 断言）。
			$before = Usage::daily_total_for( Usage::get(), Usage::KEY_DEEPSEEK_SEARCH );

			$env = self::makeClient( array( self::deepseekSearchResponse() ) );
			$ds  = new DeepSeekSearch( 'sk-test', 'https://api.deepseek.com', $env['client'] );
			$ds->search_site( 'example.com' );
			self::assertSame( $before + 1, Usage::daily_total_for( Usage::get(), Usage::KEY_DEEPSEEK_SEARCH ), '成功一次 → deepseek_search +1' );

			$fail = self::makeClient( array(
				array( 'response' => array( 'code' => 402 ), 'headers' => array(), 'body' => '{"error":{"message":"insufficient balance"}}' ),
			) );
			( new DeepSeekSearch( 'sk-test', 'https://api.deepseek.com', $fail['client'] ) )->search_site( 'example.com' );
			self::assertSame( $before + 1, Usage::daily_total_for( Usage::get(), Usage::KEY_DEEPSEEK_SEARCH ), '失败不计（402 语义类）' );

			$empty = self::makeClient( array(
				array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => (string) json_encode( array( 'results' => array() ) ),
				),
			) );
			( new DeepSeekSearch( 'sk-test', 'https://api.deepseek.com', $empty['client'] ) )->search_site( 'example.com' );
			self::assertSame( $before + 1, Usage::daily_total_for( Usage::get(), Usage::KEY_DEEPSEEK_SEARCH ), '空结果不计（官方零计费）' );
		}

		public function test_deepseek_search_does_not_count_at_transport(): void {
			// 计量走响应侧成功次（Usage::bump_option），传输脊 usage_key 不挂（否则按尝试计≠成功次）。
			$env = self::makeClient( array( self::deepseekSearchResponse() ) );
			$ds  = new DeepSeekSearch( 'sk-test', 'https://api.deepseek.com', $env['client'] );
			$ds->search_site( 'example.com' );
			self::assertSame( array(), $env['usage_keys'], '传输脊前置计量零回调（成功次语义=响应侧计数）' );
		}

		public function test_usage_key_constant_is_wired(): void {
			$src = self::source( 'includes/urlaudit/DeepSeekSearch.php' );
			self::assertStringContainsString( 'KEY_DEEPSEEK_SEARCH', $src, '计量键与 Usage 常量同源（批 A 预备位兑现——防字面漂移）' );
		}

		/* ===============================================================
		 * B-2 分派：judge 工厂按 search_source 分派 + 引擎消费接口
		 * =============================================================== */

		private function makeSettings( array $ua_overrides = array() ): array {
			$settings = SettingsSchema::defaults();
			$settings['url_audit']['enabled'] = true;
			$settings['review']['cloud']['deepseek']['api_key'] = 'sk-chain-key';
			foreach ( $ua_overrides as $key => $value ) {
				$settings['url_audit'][ $key ] = $value;
			}
			return $settings;
		}

		public function test_judge_factory_dispatches_deepseek_source(): void {
			$worker = new \WinkGatekeeper\UrlAudit\UrlAuditWorker( $this->makeSettings( array( 'search_source' => 'deepseek' ) ) );
			$engine = $worker->default_judge_factory();
			self::assertInstanceOf( DeepSeekSearch::class, $engine['bocha'], 'search_source=deepseek → DeepSeekSearch' );
			$ds = $engine['bocha'];
			self::assertTrue( $ds->is_configured(), 'key 链=AI 判定凭证链（inherit → Tab③ DeepSeek 主凭证 §0-5）' );
		}

		public function test_judge_factory_dispatches_bocha_source(): void {
			$worker = new \WinkGatekeeper\UrlAudit\UrlAuditWorker( $this->makeSettings( array(
				'search_source' => 'bocha',
				'bocha'         => array( 'enabled' => true, 'api_key' => 'bocha-key' ),
			) ) );
			$engine = $worker->default_judge_factory();
			self::assertInstanceOf( BochaSearch::class, $engine['bocha'], 'search_source=bocha（默认）→ BochaSearch 现款' );
		}

		public function test_judge_factory_custom_provider_key_chains_into_search(): void {
			// §0-5：provider custom → 其 api_key/base_url（搜索源跟随同一凭证链）。
			$settings = $this->makeSettings( array(
				'search_source' => 'deepseek',
				'provider'      => array( 'mode' => 'custom', 'api_key' => 'sk-own', 'base_url' => 'https://dsproxy.example.com', 'model' => '' ),
			) );
			$worker = new \WinkGatekeeper\UrlAudit\UrlAuditWorker( $settings );
			$engine = $worker->default_judge_factory();
			self::assertInstanceOf( DeepSeekSearch::class, $engine['bocha'] );
			self::assertTrue( $engine['bocha']->is_configured(), '独立凭证非空 → 搜索源用独立 key' );
		}

		public function test_judge_factory_deepseek_source_without_chain_key_degrades(): void {
			$settings                     = $this->makeSettings( array( 'search_source' => 'deepseek' ) );
			$settings['review']['cloud']['deepseek']['api_key'] = '';
			$worker = new \WinkGatekeeper\UrlAudit\UrlAuditWorker( $settings );
			$engine = $worker->default_judge_factory();
			self::assertFalse( $engine['bocha']->is_configured(), '凭证链空 → 未配置（引擎零信号降级不阻塞判定）' );
		}

		public function test_engine_consumes_provider_signal(): void {
			// 引擎消费任意 UrlSearchProvider——信号进 LLM payload（bocha_signal 段复用）。
			$fake = new class() implements UrlSearchProvider {
				public function is_configured(): bool {
					return true;
				}
				/**
				 * @return array{snippets: string[], siteName: string}|null
				 */
				public function search_site( string $host, int $timeout = 5 ): ?array {
					return array( 'snippets' => array( '可疑站点特征片段' ), 'siteName' => 'FakeNews 集团' );
				}
			};
			$env    = self::makeClient( array(
				array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => (string) json_encode( array(
						'choices' => array( array(
							'message'       => array( 'role' => 'assistant', 'content' => (string) json_encode( array( 'verdict' => 'pass', 'confidence' => 0.9, 'reason' => 'ok', 'hits' => array() ) ) ),
							'finish_reason' => 'stop',
						) ),
						'usage'   => array( 'prompt_tokens' => 10, 'completion_tokens' => 5 ),
					) ),
				),
			) );
			$engine = new UrlJudgeEngine(
				array( 'api_key' => 'sk-test', 'base_url' => 'https://api.deepseek.com', 'model' => 'deepseek-flash', 'timeout' => 8, 'min_confidence' => 0.8 ),
				$env['client'],
				'deepseek-flash',
				$fake
			);
			$out = $engine->judge( array( 'title' => 'T', 'meta_description' => '', 'text' => 'body' ), 'https://example.com/', '作者\n正文', 'url_audit_incr' );
			self::assertSame( 'pass', $out['verdict'] );
			$body = (string) $env['requests'][0]['args']['body'];
			self::assertStringContainsString( 'FakeNews 集团', $body, 'provider 信号进 facts_json（第三方视角证据并列喂 LLM）' );
		}

		public function test_engine_degrades_when_provider_returns_null(): void {
			// 搜索失败→信号缺失降级：判定照常（搜索失败≠违规）。
			$fake = new class() implements UrlSearchProvider {
				public function is_configured(): bool {
					return true;
				}
				/**
				 * @return array{snippets: string[], siteName: string}|null
				 */
				public function search_site( string $host, int $timeout = 5 ): ?array {
					return null;
				}
			};
			$env    = self::makeClient( array(
				array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => (string) json_encode( array(
						'choices' => array( array(
							'message'       => array( 'role' => 'assistant', 'content' => (string) json_encode( array( 'verdict' => 'pass', 'confidence' => 0.9, 'reason' => 'ok', 'hits' => array() ) ) ),
							'finish_reason' => 'stop',
						) ),
						'usage'   => array( 'prompt_tokens' => 10, 'completion_tokens' => 5 ),
					) ),
				),
			) );
			$engine = new UrlJudgeEngine(
				array( 'api_key' => 'sk-test', 'base_url' => 'https://api.deepseek.com', 'model' => 'deepseek-flash', 'timeout' => 8, 'min_confidence' => 0.8 ),
				$env['client'],
				'deepseek-flash',
				$fake
			);
			$out = $engine->judge( array( 'title' => 'T', 'meta_description' => '', 'text' => 'body' ), 'https://example.com/', '作者\n正文', 'url_audit_incr' );
			self::assertSame( 'pass', $out['verdict'], '搜索失败不阻塞不劣化判定' );
			self::assertStringNotContainsString( 'FakeNews', (string) $env['requests'][0]['args']['body'], '失败信号不进 payload' );
		}

		/* ===============================================================
		 * B-2 UI：高级折叠内搜索源下拉 + Tab⑥ 消耗段加行
		 * =============================================================== */

		public function test_search_source_select_in_advanced_fold(): void {
			$sp   = self::source( 'includes/settings/SettingsPage.php' );
			$fold = self::blockBetween( $sp, 'id="wk-url-audit-advanced"', 'wk-url-audit-inert-notice' );
			self::assertStringContainsString( 'id="wk-url-audit-search-source"', $fold, '搜索源下拉在高级折叠内（N2 提案定位）' );
			self::assertStringContainsString( "array( 'url_audit', 'search_source' )", $fold, '字段名 url_audit.search_source' );
			self::assertStringContainsString( "'bocha'", $fold, '选项 博查（默认）' );
			self::assertStringContainsString( "'deepseek'", $fold, '选项 DeepSeek 搜索' );
		}

		public function test_tab6_consumption_row_for_deepseek_search(): void {
			$stats = self::source( 'includes/settings/SettingsPage.php' );
			self::assertStringContainsString( 'wk-stats-url-search-line', $stats, 'Tab⑥ 消耗段搜索行锚' );
			self::assertStringContainsString( 'KEY_DEEPSEEK_SEARCH', $stats, '消耗行读 Usage 键位常量（K 系口径单一来源）' );
		}
	}
}
