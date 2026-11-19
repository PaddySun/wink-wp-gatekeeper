<?php
/**
 * M6 批 D · 网址核查抓取服务商化（0.6.6，2026-11-16——施工图 §4 C-1~C-5 + §0-5/§0-7）。
 *
 * 覆盖：
 *   D-1  schema url_audit.fetcher 子树 {mode: inherit(默认)|self|deepseek, api_key} +
 *        url_audit.fetch_daily_cap=200（0=不限）——Sanitize 枚举+merge_defaults+
 *        .env.example 模板跟随（WINK_BOCHA_API_KEY / WINK_URL_AUDIT_DEEPSEEK_API_KEY）。
 *   D-2  UI 高级折叠三态下拉+抓取专用 Key（mode=deepseek 显隐联动）+AI 判定凭证
 *        描述按 N3 改写（inherit=云端审核为 DeepSeek 时判定与抓取都用 DeepSeek）。
 *   D-3  DeepSeekFetchAdapter：POST {base}/v1/tools/fetch（base 跟随凭证链缺省官方）、
 *        Bearer、timeout=min(剩余预算,12)（15s job 级预算墙内）、走 RemoteClient 传输脊、
 *        返回与 UrlFetcher 同构 FetchOutcome。
 *   D-4  分类学映射表逐行（§4 C-4）+meta fetch_provider/http_code+一切非 200 恒归
 *        inconclusive 不定罪（§0-7）+fetch 账号级错误（401/402）接 U-3 事件面，
 *        402 车道暂停黄条。
 *   D-5  url_audit_fetch 成功次计量（200 且有正文=官方计费口径）+fetch_daily_cap 闸
 *        （worker 出队后 fetch 前）+Tab⑥ 消耗段「网页抓取 N 次」行。
 *
 * 桩注记：WinkGatekeeper\UrlAudit / WinkGatekeeper\Stats 命名空间 WP 边界桩与
 * M6BSearchSourceTest / M6CUrlBacklogTest 同款同键（function_exists 守卫——同进程共存）。
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
}

namespace WinkGatekeeper\Stats {

	$GLOBALS['wk_stats_option_store'] = $GLOBALS['wk_stats_option_store'] ?? array();

	if ( ! function_exists( 'WinkGatekeeper\Stats\get_option' ) ) {
		/**
		 * get_option 桩（M6BSearchSourceTest 同款）。
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
		 * update_option 桩。
		 *
		 * @param string $name    option 名。
		 * @param mixed  $value   值。
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
}

namespace WinkGatekeeper\Tests {

	use PHPUnit\Framework\TestCase;
	use WinkGatekeeper\Http\RemoteClient;
	use WinkGatekeeper\Settings\Sanitize;
	use WinkGatekeeper\Settings\SettingsSchema;
	use WinkGatekeeper\Stats\Usage;
	use WinkGatekeeper\UrlAudit\DeepSeekFetchAdapter;
	use WinkGatekeeper\UrlAudit\UrlAuditWorker;
	use WinkGatekeeper\UrlAudit\UrlFetchPolicy;
	use WinkGatekeeper\UrlAudit\UrlFetcher;

	final class M6DFetchProviderTest extends TestCase {

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
		 * 构造带捕获传输脊的 RemoteClient（响应队列——耗尽后重复最后一条，
		 * 供传输脊 5xx/429 重试语义得到终态 status）。
		 *
		 * @param array<int, array<string, mixed>> $responses 依次返回的 WP HTTP 形态响应。
		 * @return array{client: RemoteClient, requests: array<int, array<string, mixed>>}
		 */
		private static function makeClient( array $responses ): array {
			$requests  = array();
			$last      = null;
			$transport = static function ( string $url, array $args ) use ( &$responses, &$requests, &$last ) {
				$requests[] = array( 'url' => $url, 'args' => $args );
				$resp = array_shift( $responses );
				if ( null !== $resp ) {
					$last = $resp;
				} else {
					$resp = $last;
				}
				return $resp;
			};
			$client = new RemoteClient(
				$transport,
				null,
				static function ( int $ms ): void {}
			);
			return array( 'client' => $client, 'requests' => &$requests );
		}

		/** @return array<string, mixed> DeepSeek Fetch 官方文档示例响应（api/网页抓取.md 2026-11-14 采集）。 */
		private static function fetchResponse( string $title, string $content ): array {
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => (string) json_encode( array( 'title' => $title, 'content' => $content ), JSON_UNESCAPED_UNICODE ),
			);
		}

		/** @return array<string, mixed> 非 200 错误响应（OpenAI 兼容错误体）。 */
		private static function errorResponse( int $code, string $message = 'err' ): array {
			return array(
				'response' => array( 'code' => $code ),
				'headers'  => array(),
				'body'     => (string) json_encode( array( 'error' => array( 'message' => $message, 'type' => 'invalid_request_error', 'param' => null, 'code' => 'err' ) ), JSON_UNESCAPED_UNICODE ),
			);
		}

		private function makeSettings( array $ua_overrides = array() ): array {
			$settings                          = SettingsSchema::defaults();
			$settings['url_audit']['enabled']  = true;
			// 【M6·批H·H-1】抓取管线测试夹具显式 self 档（inherit 现解析 unconfigured 不自动自抓——本批语义翻转，管线行为测试须显式选择抓取档）。
			$settings['url_audit']['fetcher']['mode'] = 'self';
			$settings['review']['cloud']['deepseek']['api_key'] = 'sk-chain-key';
			foreach ( $ua_overrides as $key => $value ) {
				$settings['url_audit'][ $key ] = $value;
			}
			return $settings;
		}

		/* ===============================================================
		 * D-1 schema：url_audit.fetcher 子树 + fetch_daily_cap
		 * =============================================================== */

		public function test_schema_fetcher_subtree_defaults(): void {
			$defaults = SettingsSchema::defaults();
			$fetcher  = $defaults['url_audit']['fetcher'] ?? null;
			self::assertIsArray( $fetcher, 'url_audit.fetcher 子树在场（C-1）' );
			self::assertSame( 'inherit', $fetcher['mode'], '默认 inherit（跟随云端审核）' );
			self::assertSame( '', $fetcher['api_key'], '抓取专用 Key 默认空（空=跟随 AI 凭证链 §0-5）' );
			self::assertSame( 200, $defaults['url_audit']['fetch_daily_cap'], 'fetch_daily_cap 默认 200/日（§0-6 新增防护默认值）' );
		}

		public function test_merge_defaults_backfills_fetcher_subtree(): void {
			$merged = SettingsSchema::merge_defaults( array(
				'url_audit' => array( 'enabled' => true ),
			) );
			self::assertSame( 'inherit', $merged['url_audit']['fetcher']['mode'], '存量树 merge 补 fetcher 子树默认' );
			self::assertSame( '', $merged['url_audit']['fetcher']['api_key'] );
			self::assertSame( 200, $merged['url_audit']['fetch_daily_cap'], '存量树 merge 补 fetch_daily_cap 默认' );
		}

		public function test_sanitize_fetcher_mode_enum(): void {
			$rules = Sanitize::rules();
			self::assertSame( 'url_audit_fetcher_mode', $rules['url_audit.fetcher.mode'] ?? null, 'Sanitize 映射表须收录 fetcher.mode' );
			self::assertSame( 'password', $rules['url_audit.fetcher.api_key'] ?? null, 'fetcher.api_key=password 型（不回显契约）' );
			self::assertSame( 'inherit', Sanitize::clean_value( 'inherit', 'url_audit_fetcher_mode' ) );
			self::assertSame( 'self', Sanitize::clean_value( 'self', 'url_audit_fetcher_mode' ) );
			self::assertSame( 'deepseek', Sanitize::clean_value( 'deepseek', 'url_audit_fetcher_mode' ) );
			self::assertSame( 'inherit', Sanitize::clean_value( 'bogus', 'url_audit_fetcher_mode' ), '非法值归默认 inherit（fail-safe——免费路径保底）' );
			self::assertSame( 'inherit', Sanitize::clean_value( '', 'url_audit_fetcher_mode' ) );
			self::assertSame( 'absint', $rules['url_audit.fetch_daily_cap'] ?? null, 'fetch_daily_cap=absint（0=不限）' );
		}

		public function test_env_example_has_bocha_and_fetch_key_placeholders(): void {
			$env = self::source( '.env.example' );
			self::assertStringContainsString( 'WINK_BOCHA_API_KEY=', $env, '模板跟随：博查 Key 占位行' );
			self::assertStringContainsString( 'WINK_URL_AUDIT_DEEPSEEK_API_KEY=', $env, '模板跟随：抓取专用 DeepSeek Key 占位行' );
		}

		/* ===============================================================
		 * D-2 UI：高级折叠三态下拉+抓取专用 Key+AI 判定凭证文案（N3）
		 * =============================================================== */

		public function test_fetcher_mode_select_in_advanced_fold(): void {
			$sp   = self::source( 'includes/settings/SettingsPage.php' );
			$fold = self::blockBetween( $sp, 'id="wk-url-audit-advanced"', 'wk-url-audit-inert-notice' );
			self::assertStringContainsString( 'id="wk-url-audit-fetcher-mode"', $fold, '网页抓取服务商下拉在高级折叠内（C-2）' );
			self::assertStringContainsString( "array( 'url_audit', 'fetcher', 'mode' )", $fold, '字段名 url_audit.fetcher.mode' );
			self::assertStringContainsString( 'Follow cloud moderation (default — when cloud moderation is DeepSeek, verdicts use the same configuration)', $fold, '三态文案①：跟随云端审核（默认）' );
			self::assertStringContainsString( 'Open directly from this site (free)', $fold, '三态文案②：本站直接打开（免费）' );
			self::assertStringContainsString( 'DeepSeek page fetching (pay-per-use ¥5/1,000)', $fold, '三态文案③：DeepSeek 网页抓取（按次计费）' );
		}

		public function test_fetcher_key_rows_conditional_on_deepseek(): void {
			$sp   = self::source( 'includes/settings/SettingsPage.php' );
			$fold = self::blockBetween( $sp, 'id="wk-url-audit-advanced"', 'wk-url-audit-inert-notice' );
			self::assertStringContainsString( 'id="wk-url-audit-fetcher-rows"', $fold, '抓取专用 Key 行组容器（显隐联动面）' );
			self::assertStringContainsString( "array( 'url_audit', 'fetcher', 'api_key' )", $fold, '字段名 url_audit.fetcher.api_key' );
			self::assertStringContainsString( "'deepseek' === \$fetcher_mode ? '' : ' hidden'", $fold, 'PHP 初始态：mode=deepseek 才显示（provider rows 联动范式同款）' );
			$js = self::source( 'assets/js/admin-brand.js' );
			self::assertStringContainsString( 'wk-url-audit-fetcher-mode', $js, 'admin-brand.js change 绑定接管切换显隐' );
			self::assertStringContainsString( 'wk-url-audit-fetcher-rows', $js );
		}

		public function test_provider_credential_description_n3_rewrite(): void {
			// N3 语义逐字落地（§0-5/§4 C-2）：inherit=云端审核为 DeepSeek 时 AI 判定与
			// 网页抓取都用 DeepSeek；其他驱动时抓取走本站。
			$sp   = self::source( 'includes/settings/SettingsPage.php' );
			$fold = self::blockBetween( $sp, 'id="wk-url-audit-advanced"', 'wk-url-audit-inert-notice' );
			self::assertStringContainsString( 'Follow cloud moderation (default — when cloud moderation is DeepSeek, verdicts use the same configuration)', $fold, 'N3：inherit 语义——云端审核为 DeepSeek 时' );
			self::assertStringContainsString( 'Follow (default): when cloud moderation is DeepSeek, both verdicts and page fetching use DeepSeek (one set of credentials); with another driver, verdicts use its configuration and page fetching does not auto-enable (unconfigured — pick one manually under “Fetch provider” below). Separate: the URL check uses its own DeepSeek credentials (independent of comment cloud moderation; only needed for a different account/quota).', $fold, 'N3：描述须提及网页抓取跟随面' );
			self::assertStringContainsString( 'Open directly from this site (free)', $fold, 'N3：其他驱动时抓取走本站' );
		}

		/* ===============================================================
		 * D-3 DeepSeekFetchAdapter：官方契约+同构 FetchOutcome+预算墙
		 * =============================================================== */

		public function test_adapter_request_shape_official_contract(): void {
			// 官方契约钉死：POST {base}/v1/tools/fetch；Bearer；body {url}；timeout=min(剩余预算,12)。
			$env   = self::makeClient( array( self::fetchResponse( 'T', '正文内容' ) ) );
			$clock = static fn(): float => 1000.0;
			$ad    = new DeepSeekFetchAdapter( 'sk-live-key', 'https://api.deepseek.com/', $env['client'], $clock );
			$ad->fetch( 'https://example.com/page' );

			self::assertCount( 1, $env['requests'], '恰一次外呼（重试语义在传输脊/映射层）' );
			$req = $env['requests'][0];
			self::assertSame( 'https://api.deepseek.com/v1/tools/fetch', $req['url'], '端点=POST {base}/v1/tools/fetch（尾斜杠归一）' );
			self::assertSame( 'Bearer sk-live-key', $req['args']['headers']['Authorization'], 'Bearer 鉴权' );
			$body = (array) json_decode( (string) $req['args']['body'], true );
			self::assertSame( 'https://example.com/page', $body['url'], 'body {url}' );
			self::assertSame( 12, $req['args']['timeout'], 'timeout=min(剩余预算,12)——满预算时 12s' );
		}

		public function test_adapter_default_base_official_when_empty(): void {
			$env = self::makeClient( array( self::fetchResponse( 'T', 'body' ) ) );
			$ad  = new DeepSeekFetchAdapter( 'sk-test', '', $env['client'] );
			$ad->fetch( 'https://example.com/' );
			self::assertSame( 'https://api.deepseek.com/v1/tools/fetch', $env['requests'][0]['url'], 'base 空=官方地址缺省（§4 C-3）' );
		}

		public function test_adapter_success_outcome_isomorphic(): void {
			// 与 UrlFetcher 同构 FetchOutcome（worker 消费侧零改动）：ok 类+markdown 正文。
			$env = self::makeClient( array( self::fetchResponse( '示例标题', '# 正文第一行

正文第二行。' ) ) );
			$ad  = new DeepSeekFetchAdapter( 'sk-test', 'https://api.deepseek.com', $env['client'] );
			$out = $ad->fetch( 'https://example.com/page' );

			self::assertSame( 'ok', $out['fetch_class'] );
			self::assertSame( 200, $out['status'], 'http_code 归真（meta 记账用）' );
			self::assertSame( '示例标题', $out['title'] );
			self::assertStringContainsString( '正文第一行', $out['text'], 'content（markdown）→ text' );
			self::assertSame( 'deepseek', $out['fetch_provider'], 'meta fetch_provider=deepseek 溯源' );
			self::assertSame( 'unavailable', $out['html_analysis'], 'HTML 级侦测面不可得——如实记 unavailable（C-4）' );
			self::assertSame( '', $out['meta_refresh'], 'cloaking/meta-refresh 类信号该模式不可得（空=无信号非误报）' );
			self::assertSame( 'https://example.com/page', $out['final_url'] );
			self::assertSame( array(), $out['hop_hosts'], '同构键齐备（hop_hosts）' );
			self::assertSame( 0, $out['redirects'], '同构键齐备（redirects）' );
		}

		public function test_adapter_empty_content_thin(): void {
			$env = self::makeClient( array( self::fetchResponse( 'T', '' ) ) );
			$ad  = new DeepSeekFetchAdapter( 'sk-test', '', $env['client'] );
			$out = $ad->fetch( 'https://example.com/' );
			self::assertSame( 'thin_content', $out['fetch_class'], '200 且 content 空 → thin_content（C-4 表行 2）' );
			self::assertSame( 200, $out['status'] );
		}

		public function test_adapter_http_mapping_table(): void {
			// C-4 映射表逐行：404→not_found / 403→blocked_safety / 402→quota_exhausted /
			// 408·504→timeout / 429→rate_limited / 413→too_large / 其余 4xx→fetch_failed /
			// 5xx→transport；传输失败（status 0）→ transport。
			$cases = array(
				404 => 'not_found',
				403 => 'blocked_safety',
				402 => 'quota_exhausted',
				408 => 'timeout',
				504 => 'timeout',
				429 => 'rate_limited',
				413 => 'too_large',
				400 => 'fetch_failed',
				405 => 'fetch_failed',
				500 => 'transport',
				503 => 'transport',
			);
			foreach ( $cases as $code => $expect ) {
				$env = self::makeClient( array( self::errorResponse( $code ) ) );
				$ad  = new DeepSeekFetchAdapter( 'sk-test', '', $env['client'] );
				$out = $ad->fetch( 'https://example.com/' );
				self::assertSame( $expect, $out['fetch_class'], "HTTP {$code} → {$expect}（C-4 表逐行）" );
				self::assertSame( $code, $out['status'], "HTTP {$code} 归真入 meta" );
			}

			// 传输失败（WP_Error 形态非数组——classify 落 transport status 0）。
			$env   = self::makeClient( array() );
			$ad    = new DeepSeekFetchAdapter( 'sk-test', '', $env['client'] );
			$out   = $ad->fetch( 'https://example.com/' );
			self::assertSame( 'transport', $out['fetch_class'], '连不上 api.deepseek.com → transport' );
			self::assertSame( 0, $out['status'] );
		}

		public function test_adapter_non200_never_convicts(): void {
			// §0-7 跨实现不变：DeepSeek Fetch 一切非 200 恒归 inconclusive 族不定罪。
			$classes = array( 'not_found', 'blocked_safety', 'quota_exhausted', 'timeout', 'rate_limited', 'too_large', 'fetch_failed', 'transport', 'thin_content' );
			foreach ( $classes as $class ) {
				$state = UrlFetchPolicy::fetch_class_to_state( $class );
				self::assertSame( 'inconclusive', $state['state'], "{$class} → inconclusive（fetch 失败≠违规）" );
				self::assertSame( 'keep', $state['action'], "{$class} 恒保持零处置权重" );
			}
		}

		public function test_adapter_account_error_flag_on_401_402(): void {
			// 账号级失败（401 凭据无效/402 余额不足）——U-3 ② 事件面消费标记。
			foreach ( array( 401, 402 ) as $code ) {
				$env = self::makeClient( array( self::errorResponse( $code ) ) );
				$ad  = new DeepSeekFetchAdapter( 'sk-test', '', $env['client'] );
				$out = $ad->fetch( 'https://example.com/' );
				self::assertTrue( ! empty( $out['account_error'] ), "HTTP {$code} 为账号级失败（非目标站业务类）" );
			}
			foreach ( array( 404, 403, 429, 500 ) as $code ) {
				$env = self::makeClient( array( self::errorResponse( $code ) ) );
				$ad  = new DeepSeekFetchAdapter( 'sk-test', '', $env['client'] );
				$out = $ad->fetch( 'https://example.com/' );
				self::assertEmpty( $out['account_error'] ?? null, "HTTP {$code} 非账号级（业务/传输类——不发事件）" );
			}
		}

		public function test_adapter_budget_wall_timeout_without_outbound(): void {
			// 15s job 级预算墙：预算耗尽 → timeout 类零外呼（nw §1.1 同纪律）。
			$env = self::makeClient( array( self::fetchResponse( 'T', 'body' ) ) );
			// 先取 started（clock=1000），再让 remaining 计算（clock=1020）超墙。
			$clock = function () {
				static $calls = 0;
				++$calls;
				return 1000.0 + ( $calls >= 2 ? 20.0 : 0.0 );
			};
			$ad  = new DeepSeekFetchAdapter( 'sk-test', '', $env['client'], $clock );
			$out = $ad->fetch( 'https://example.com/' );
			self::assertSame( 'timeout', $out['fetch_class'], '预算墙耗尽 → timeout' );
			self::assertCount( 0, $env['requests'], '超墙零外呼（不烧不必要预算）' );
		}

		public function test_adapter_timeout_respects_remaining_budget(): void {
			// 预算剩 4s → timeout=min(4,12)=4（不超剩余预算）。
			$env = self::makeClient( array( self::fetchResponse( 'T', 'body' ) ) );
			$clock = function () {
				static $calls = 0;
				++$calls;
				return 1000.0 + ( $calls >= 2 ? 11.0 : 0.0 ); // 第二次读钟=剩余 4s。
			};
			$ad = new DeepSeekFetchAdapter( 'sk-test', '', $env['client'], $clock );
			$ad->fetch( 'https://example.com/' );
			self::assertSame( 4, $env['requests'][0]['args']['timeout'], 'timeout=min(剩余预算,12)' );
		}

		/* ===============================================================
		 * D-4 worker 接线：fetcher 分派+meta 溯源+账号级事件/暂停
		 * =============================================================== */

		public function test_fetcher_factory_dispatch_deepseek_mode(): void {
			$worker = new UrlAuditWorker( $this->makeSettings( array(
				'fetcher' => array( 'mode' => 'deepseek', 'api_key' => '' ),
			) ) );
			self::assertInstanceOf( DeepSeekFetchAdapter::class, $worker->default_fetcher_factory(), 'mode=deepseek → DeepSeekFetchAdapter' );
		}

		public function test_fetcher_factory_dispatch_self_mode(): void {
			$worker = new UrlAuditWorker( $this->makeSettings( array(
				'fetcher' => array( 'mode' => 'self', 'api_key' => '' ),
			) ) );
			self::assertInstanceOf( UrlFetcher::class, $worker->default_fetcher_factory(), 'mode=self → 现款 UrlFetcher（免费路径+SSRF 防线原样）' );
		}

		public function test_fetcher_factory_inherit_follows_cloud_chain(): void {
			// inherit 跟随切换（C-6）：云端审核=DeepSeek → 抓取 DeepSeek；改 TMS → 抓取落本站。
			$ds_settings = $this->makeSettings( array( 'fetcher' => array( 'mode' => 'inherit' ) ) );
			$ds_settings['review']['cloud']['chain'] = array( 'deepseek' );
			self::assertInstanceOf( DeepSeekFetchAdapter::class, ( new UrlAuditWorker( $ds_settings ) )->default_fetcher_factory(), 'inherit+云审 DeepSeek → 抓取 DeepSeek' );

			$tms_settings = $this->makeSettings( array( 'fetcher' => array( 'mode' => 'inherit' ) ) );
			$tms_settings['review']['cloud']['chain'] = array( 'tencent_tms' );
			self::assertInstanceOf( UrlFetcher::class, ( new UrlAuditWorker( $tms_settings ) )->default_fetcher_factory(), 'inherit+云审 TMS → 抓取落本站（C-6 真机核对语义）' );

			$empty_settings = $this->makeSettings( array( 'fetcher' => array( 'mode' => 'inherit' ) ) );
			$empty_settings['review']['cloud']['chain'] = array();
			self::assertInstanceOf( UrlFetcher::class, ( new UrlAuditWorker( $empty_settings ) )->default_fetcher_factory(), 'inherit+云审关 → 本站直开兜底（免费路径保底）' );
		}

		public function test_fetcher_credentials_own_key_overrides_chain(): void {
			// §0-5/C-1：fetcher.api_key 仅 deepseek 模式消费——非空=专用 Key；空=跟随 AI 凭证链。
			$own = $this->makeSettings( array( 'fetcher' => array( 'mode' => 'deepseek', 'api_key' => 'sk-fetch-own' ) ) );
			$cred = UrlAuditWorker::fetcher_credentials( $own );
			self::assertSame( 'sk-fetch-own', $cred['api_key'], '专用 Key 非空 → 覆盖凭证链' );

			$chain = $this->makeSettings( array( 'fetcher' => array( 'mode' => 'deepseek', 'api_key' => '' ) ) );
			$cred  = UrlAuditWorker::fetcher_credentials( $chain );
			self::assertSame( 'sk-chain-key', $cred['api_key'], '专用 Key 空 → 跟随 AI 判定凭证链（inherit→Tab③ 主凭证）' );

			$custom = $this->makeSettings( array(
				'fetcher' => array( 'mode' => 'deepseek', 'api_key' => '' ),
				'provider' => array( 'mode' => 'custom', 'api_key' => 'sk-own-provider', 'base_url' => 'https://dsproxy.example.com', 'model' => '' ),
			) );
			$cred = UrlAuditWorker::fetcher_credentials( $custom );
			self::assertSame( 'sk-own-provider', $cred['api_key'], 'provider custom → 用其独立 key（§0-5）' );
			self::assertSame( 'https://dsproxy.example.com', $cred['base_url'], 'base_url 跟随凭证链（缺省官方由 adapter 收口）' );
		}

		public function test_worker_meta_records_fetch_provider_and_http_code(): void {
			// meta 溯源（C-4 注）：fetch_provider=deepseek + http_code 归真。
			$metas = array();
			$stub  = new class() {
				/**
				 * @return array<string, mixed>
				 */
				public function fetch( string $url, bool $robots_comply = false ): array {
					return array(
						'fetch_class' => 'ok', 'final_url' => $url, 'hop_hosts' => array(), 'status' => 200,
						'content_type' => 'text/markdown', 'title' => 'T', 'og_title' => '', 'meta_description' => '',
						'meta_refresh' => '', 'js_redirect' => '', 'text' => '足够长的正文内容。', 'robots_state' => '',
						'redirects' => 0, 'fetch_provider' => 'deepseek', 'html_analysis' => 'unavailable',
					);
				}
			};
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BATCH_QUEUE ] = array( 501 );
			$worker = new UrlAuditWorker( $this->makeSettings(), array(
				'incr_take'       => static fn( int $limit ): array => array(),
				'comment_reader'  => static fn( int $id ): array => array( 'author' => '甲', 'body' => 'body', 'url' => 'https://prov.example.com/', 'post_status' => 'publish' ),
				'meta_reader'     => static fn( int $id ): ?array => null,
				'meta_writer'     => static function ( int $id, ?array $meta ) use ( &$metas ): void {
					$metas[ $id ] = $meta;
				},
				'fetcher_factory' => static fn(): object => $stub,
				'judge_factory'   => static fn(): array => array( 'judge' => new \WinkGatekeeper\UrlAudit\UrlJudgeEngine(
					array( 'api_key' => 'sk-test', 'base_url' => 'https://api.deepseek.com', 'model' => 'deepseek-flash', 'timeout' => 8, 'min_confidence' => 0.8 ),
					self::makeClient( array( self::judgeOk() ) )['client'],
					'deepseek-flash',
					null
				), 'model' => 'deepseek-flash', 'bocha' => null ),
				'clock' => static fn(): float => 1700000000.0,
			) );
			$worker->run_round();

			self::assertSame( 'deepseek', $metas[501]['fetch']['fetch_provider'] ?? '', 'meta 记 fetch_provider=deepseek' );
			self::assertSame( 200, $metas[501]['fetch']['http_code'] ?? 0, 'meta 记 http_code 归真' );
		}

		public function test_worker_self_outcome_records_provider_self(): void {
			// self 模式（现款 UrlFetcher 产物无 fetch_provider 键）→ meta 记 fetch_provider=self。
			$metas = array();
			$stub  = new class() {
				/**
				 * @return array<string, mixed>
				 */
				public function fetch( string $url, bool $robots_comply = false ): array {
					return array(
						'fetch_class' => 'ok', 'final_url' => $url, 'hop_hosts' => array(), 'status' => 200,
						'content_type' => 'text/html', 'title' => 'T', 'og_title' => '', 'meta_description' => '',
						'meta_refresh' => '', 'js_redirect' => '', 'text' => '足够长的正文内容。', 'robots_state' => '',
						'redirects' => 0,
					);
				}
			};
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BATCH_QUEUE ] = array( 502 );
			$worker = new UrlAuditWorker( $this->makeSettings(), array(
				'incr_take'       => static fn( int $limit ): array => array(),
				'comment_reader'  => static fn( int $id ): array => array( 'author' => '乙', 'body' => 'body', 'url' => 'https://selfp.example.com/', 'post_status' => 'publish' ),
				'meta_reader'     => static fn( int $id ): ?array => null,
				'meta_writer'     => static function ( int $id, ?array $meta ) use ( &$metas ): void {
					$metas[ $id ] = $meta;
				},
				'fetcher_factory' => static fn(): object => $stub,
				'judge_factory'   => static fn(): array => array( 'judge' => new \WinkGatekeeper\UrlAudit\UrlJudgeEngine(
					array( 'api_key' => 'sk-test', 'base_url' => 'https://api.deepseek.com', 'model' => 'deepseek-flash', 'timeout' => 8, 'min_confidence' => 0.8 ),
					self::makeClient( array( self::judgeOk() ) )['client'],
					'deepseek-flash',
					null
				), 'model' => 'deepseek-flash', 'bocha' => null ),
				'clock' => static fn(): float => 1700000000.0,
			) );
			$worker->run_round();

			self::assertSame( 'self', $metas[502]['fetch']['fetch_provider'] ?? '', 'self 模式 meta 记 fetch_provider=self（存量判定可溯源）' );
		}

		public function test_worker_402_pauses_lane_and_emits_account_events(): void {
			// 402 余额不足：quota_exhausted → 车道暂停黄条 + U-3 ② 账号级事件（运行性错误
			// +暂停边沿两条面；一切非 200 恒归 inconclusive 不定罪）。
			$metas    = array();
			$events   = array();
			$disposals = array();
			$stub  = new class() {
				/**
				 * @return array<string, mixed>
				 */
				public function fetch( string $url, bool $robots_comply = false ): array {
					return array(
						'fetch_class' => 'quota_exhausted', 'final_url' => $url, 'hop_hosts' => array(), 'status' => 402,
						'content_type' => '', 'title' => '', 'og_title' => '', 'meta_description' => '',
						'meta_refresh' => '', 'js_redirect' => '', 'text' => '', 'robots_state' => '',
						'redirects' => 0, 'fetch_provider' => 'deepseek', 'account_error' => true,
					);
				}
			};
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BATCH_QUEUE ] = array( 503, 504 );
			$worker = new UrlAuditWorker( $this->makeSettings(), array(
				'incr_take'       => static fn( int $limit ): array => array(),
				'comment_reader'  => static fn( int $id ): array => array( 'author' => '丙', 'body' => 'body', 'url' => 'https://pause.example.com/', 'post_status' => 'publish' ),
				'meta_reader'     => static fn( int $id ): ?array => null,
				'meta_writer'     => static function ( int $id, ?array $meta ) use ( &$metas ): void {
					$metas[ $id ] = $meta;
				},
				'disposal_executor' => static function ( int $id, string $action ) use ( &$disposals ): bool {
					$disposals[] = array( $id, $action );
					return true;
				},
				'fetcher_factory' => static fn(): object => $stub,
				'event_recorder'  => static function ( string $reason, array $hits, array $meta ) use ( &$events ): void {
					$events[] = array( 'reason' => $reason, 'hits' => $hits, 'meta' => $meta );
				},
				'clock' => static fn(): float => 1700000000.0,
			) );
			$out = $worker->run_round();

			self::assertSame( 'fetch_account_exhausted', $out['stopped'], '402 → 车道暂停（黄条语义）' );
			self::assertSame( 1, $out['batch_processed'], '暂停前当条照常收口（inconclusive 不定罪）' );
			self::assertSame( 'inconclusive', $metas[503]['verdict'] ?? '', '402 恒归 inconclusive（§0-7）' );
			self::assertSame( array( array( 503, 'hold' ) ), $disposals, '没看清 → 待审（可逆）' );

			self::assertCount( 2, $events, '账号级运行性错误+暂停边沿各一条' );
			self::assertSame( 'fetch quota_exhausted', $events[0]['reason'], 'U-3 ② fetch 账号级错误事件' );
			self::assertSame( array( 'remote_quota_exhausted' ), $events[0]['hits'] );
			self::assertSame( 402, $events[0]['meta']['http_code'] );
			self::assertSame( 'batch', $events[0]['meta']['lane'] );
			self::assertSame( 'url_audit fetch account exhausted', $events[1]['reason'], '暂停边沿事件（fetch 面）' );
			self::assertSame( 'fetch', $events[1]['meta']['lane'], '暂停边沿 lane=fetch（与 incr/batch 闸边沿同面）' );
		}

		public function test_worker_401_emits_account_event_no_pause(): void {
			// 401 凭据无效：账号级事件照发（U-3 ②），但无车道暂停语义（仅 402 暂停）。
			$events = array();
			$stub  = new class() {
				/**
				 * @return array<string, mixed>
				 */
				public function fetch( string $url, bool $robots_comply = false ): array {
					return array(
						'fetch_class' => 'fetch_failed', 'final_url' => $url, 'hop_hosts' => array(), 'status' => 401,
						'content_type' => '', 'title' => '', 'og_title' => '', 'meta_description' => '',
						'meta_refresh' => '', 'js_redirect' => '', 'text' => '', 'robots_state' => '',
						'redirects' => 0, 'fetch_provider' => 'deepseek', 'account_error' => true,
					);
				}
			};
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BATCH_QUEUE ] = array( 505 );
			$worker = new UrlAuditWorker( $this->makeSettings(), array(
				'incr_take'       => static fn( int $limit ): array => array(),
				'comment_reader'  => static fn( int $id ): array => array( 'author' => '丁', 'body' => 'body', 'url' => 'https://unauth.example.com/', 'post_status' => 'publish' ),
				'meta_reader'     => static fn( int $id ): ?array => null,
				'meta_writer'     => static function ( int $id, ?array $meta ): void {},
				'disposal_executor' => static function ( int $id, string $action ): bool {
					return true;
				},
				'fetcher_factory' => static fn(): object => $stub,
				'event_recorder'  => static function ( string $reason, array $hits, array $meta ) use ( &$events ): void {
					$events[] = array( 'reason' => $reason, 'hits' => $hits, 'meta' => $meta );
				},
				'clock' => static fn(): float => 1700000000.0,
			) );
			$out = $worker->run_round();

			self::assertSame( '', $out['stopped'], '401 不触发车道暂停（无黄条语义）' );
			self::assertCount( 1, $events, '仅账号级运行性错误一条（无暂停边沿）' );
			self::assertSame( 'fetch fetch_failed', $events[0]['reason'] );
			self::assertSame( 401, $events[0]['meta']['http_code'] );
		}

		/* ===============================================================
		 * D-5 计量与配额：url_audit_fetch 成功次+fetch_daily_cap 闸+Tab⑥ 行
		 * =============================================================== */

		public function test_adapter_meters_success_only(): void {
			// 成功次计量（官方计费口径=200 且有正文；失败不计——响应侧计数非传输脊前置）。
			Usage::bump_option( 'preexisting_key' );
			$before = Usage::daily_total_for( Usage::get(), Usage::KEY_URL_AUDIT_FETCH );

			$ok = self::makeClient( array( self::fetchResponse( 'T', '正文内容' ) ) );
			( new DeepSeekFetchAdapter( 'sk-test', '', $ok['client'] ) )->fetch( 'https://example.com/' );
			self::assertSame( $before + 1, Usage::daily_total_for( Usage::get(), Usage::KEY_URL_AUDIT_FETCH ), '成功一次 → url_audit_fetch +1' );

			foreach ( array( 404, 402, 429 ) as $code ) {
				$fail = self::makeClient( array( self::errorResponse( $code ) ) );
				( new DeepSeekFetchAdapter( 'sk-test', '', $fail['client'] ) )->fetch( 'https://example.com/' );
				self::assertSame( $before + 1, Usage::daily_total_for( Usage::get(), Usage::KEY_URL_AUDIT_FETCH ), "失败（{$code}）不计——账单口径一致" );
			}

			$thin = self::makeClient( array( self::fetchResponse( 'T', '' ) ) );
			( new DeepSeekFetchAdapter( 'sk-test', '', $thin['client'] ) )->fetch( 'https://example.com/' );
			self::assertSame( $before + 1, Usage::daily_total_for( Usage::get(), Usage::KEY_URL_AUDIT_FETCH ), '200 空正文不计费（官方口径）' );
		}

		public function test_fetch_cap_gate_stops_round_before_fetch(): void {
			// fetch_daily_cap 闸（出队后 fetch 前）：成功次达配额 → 停转+暂停边沿事件+零 fetch。
			Usage::bump_option( Usage::KEY_URL_AUDIT_FETCH ); // 今日已用 1（=cap）。
			$events  = array();
			$fetches = 0;
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BATCH_QUEUE ] = array( 601, 602 );
			$worker = new UrlAuditWorker( $this->makeSettings( array( 'fetch_daily_cap' => 1 ) ), array(
				'incr_take'       => static fn( int $limit ): array => array(),
				'comment_reader'  => static fn( int $id ): array => array( 'author' => '戊', 'body' => 'body', 'url' => 'https://cap.example.com/', 'post_status' => 'publish' ),
				'meta_reader'     => static fn( int $id ): ?array => null,
				'meta_writer'     => static function ( int $id, ?array $meta ): void {},
				'fetcher_factory' => static function () use ( &$fetches ): object {
					++$fetches;
					throw new \RuntimeException( '闸后不得触 fetch' );
				},
				'event_recorder'  => static function ( string $reason, array $hits, array $meta ) use ( &$events ): void {
					$events[] = array( 'reason' => $reason, 'hits' => $hits, 'meta' => $meta );
				},
				'clock' => static fn(): float => 1700000000.0,
			) );
			$out = $worker->run_round();

			self::assertSame( 'fetch_cap_exhausted', $out['stopped'], 'fetch 日配额闸触发停转' );
			self::assertSame( 0, $fetches, '闸在 fetch 前——配额尽零外呼' );
			self::assertSame( 0, $out['batch_processed'], '闸拦截当轮零处理' );
			self::assertCount( 1, $events, 'fetch 暂停边沿事件一条' );
			self::assertSame( 'url_audit fetch quota exhausted', $events[0]['reason'] );
			self::assertSame( 'fetch', $events[0]['meta']['lane'], '暂停边沿 lane=fetch（与 incr/batch 闸边沿同面）' );
		}

		public function test_fetch_cap_zero_disables_gate(): void {
			// 0=不限（§4 C-1）：cap=0 → 闸永不触发。
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BATCH_QUEUE ] = array( 603 );
			$worker = new UrlAuditWorker( $this->makeSettings( array( 'fetch_daily_cap' => 0, 'batch_quota' => 0, 'incr_quota' => 0 ) ), array(
				'incr_take'       => static fn( int $limit ): array => array(),
				'comment_reader'  => static fn( int $id ): array => array( 'author' => '己', 'body' => 'body', 'url' => 'https://nocap.example.com/', 'post_status' => 'publish' ),
				'meta_reader'     => static fn( int $id ): ?array => null,
				'meta_writer'     => static function ( int $id, ?array $meta ): void {},
				'disposal_executor' => static function ( int $id, string $action ): bool {
					return true;
				},
				'fetcher_factory' => static fn(): object => new class() {
					/**
					 * @return array<string, mixed>
					 */
					public function fetch( string $url, bool $robots_comply = false ): array {
						return array(
							'fetch_class' => 'conn_fail', 'final_url' => $url, 'hop_hosts' => array(), 'status' => 0,
							'content_type' => '', 'title' => '', 'og_title' => '', 'meta_description' => '',
							'meta_refresh' => '', 'js_redirect' => '', 'text' => '', 'robots_state' => '', 'redirects' => 0,
						);
					}
				},
				'clock' => static fn(): float => 1700000000.0,
			) );
			$out = $worker->run_round();
			self::assertSame( 1, $out['batch_processed'], 'cap=0 不限——闸不拦' );
			self::assertSame( '', $out['stopped'] );
		}

		public function test_tab6_fetch_consumption_row(): void {
			$stats = self::source( 'includes/settings/SettingsPage.php' );
			self::assertStringContainsString( 'wk-stats-url-fetch-line', $stats, 'Tab⑥ 消耗段「网页抓取 N 次」行锚' );
			self::assertStringContainsString( 'KEY_URL_AUDIT_FETCH', $stats, '消耗行读 Usage 键位常量（K 系口径单一来源）' );
		}

		/**
		 * chat/completions 判定 ok 响应（judge 工厂桩用）。
		 *
		 * @return array<string, mixed>
		 */
		private static function judgeOk(): array {
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => (string) json_encode( array(
					'choices' => array( array(
						'message'       => array( 'role' => 'assistant', 'content' => (string) json_encode( array( 'verdict' => 'pass', 'confidence' => 0.9, 'reason' => 'ok', 'hits' => array() ) ) ),
						'finish_reason' => 'stop',
					) ),
					'usage'   => array( 'prompt_tokens' => 10, 'completion_tokens' => 5 ),
				) ),
			);
		}
	}
}
