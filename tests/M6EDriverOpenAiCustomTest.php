<?php
/**
 * M6·批 E · openai_custom 自定义 OpenAI 兼容端点驱动实装测试（2026-11-17，TDD 先红后绿）。
 *
 * 设计权威：M6E-openaicustom-实装-设计草案-2026-11-17（已拍板升定稿——开放问题裁定
 * R1 升级模型=UI 手填+留空短路 / Q1 思考强度不透传 / Q2 response_format 兼容阀不入本批 /
 * Q3 url_audit inherit 跟随仅注 Backlog / Q4 datalist 不入 / Q5 is_configured 覆写三键 /
 * Q6 版本 0.7.4→0.8.0）：
 *   E-1 驱动=纯配置子类（四件套：DRIVER_ID/config_from_settings 8 键/registry_entry 六维/
 *       probe_models——零协议逻辑新增，五件套/收口/计量全在基类）；
 *   E-3 测试凭据=/models 三态探活（CredentialProbe::classify_openai_custom_models——红=
 *       401/403/402 中性文案；黄=模型不在列表〔黄不红〕；灰=契约不符/404 形态/transport）；
 *   E-4 url_audit 独立凭据测试按 base_url 分流（官方域/空→余额探测；其他→/models 探活）
 *       +custom 档判定模型改手填 text；
 *   E-5 计量/事件/配额全继承（K1 usage_key='openai_custom' 经基类构造器接线）。
 *
 * 桩注记：WinkGatekeeper\Settings/UrlAudit 命名空间桩与 M6BNoticeAndEventsTest/
 * M6H2PolishTest 先行桩同语义同 store 键（function_exists 守卫同进程共存——本文件字母序
 * 后于 M6B*、先于 M6H*，全套跑时复用 M6B 先行桩；单独跑本文件时自给自足）。
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

	if ( ! function_exists( 'WinkGatekeeper\Settings\esc_attr_e' ) ) {
		/**
		 * esc_attr_e 桩（转义输出——本批新增 placeholder 行消费；字母序先于 M6H2 装载
		 * 时本桩即先行桩，其后文件的 renderUrlCard 反射渲染同受覆盖）。
		 *
		 * @param string $text   文本。
		 * @param string $domain 文本域（忽略）。
		 * @return void
		 */
		function esc_attr_e( string $text, string $domain = 'wink-gatekeeper' ): void {
			echo htmlspecialchars( $text, ENT_QUOTES );
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
		 * @param mixed $current 期望值。
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
		 * update_option 桩（与先行桩同键存储）。
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

namespace WinkGatekeeper\Tests {

	use PHPUnit\Framework\TestCase;
	use WinkGatekeeper\Drivers\DriverRegistry;
	use WinkGatekeeper\Drivers\Review\OpenAiCustomReviewDriver;
	use WinkGatekeeper\Http\RemoteClient;
	use WinkGatekeeper\Http\RemoteResult;
	use WinkGatekeeper\Settings\CredentialProbe;
	use WinkGatekeeper\Settings\DriverPrivacy;
	use WinkGatekeeper\Settings\Sanitize;
	use WinkGatekeeper\Settings\SettingsPage;
	use WinkGatekeeper\Audit\ExistingAuditWorker;
	use WinkGatekeeper\Settings\SettingsSchema;
	use WinkGatekeeper\UrlAudit\UrlAuditWorker;

	final class M6EDriverOpenAiCustomTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['wk_bridge_option_store']   = array();
			$GLOBALS['wk_ua_option_store']       = array();
			$GLOBALS['wk_ua_transient_store']    = array();
			$GLOBALS['wk_ua_comment_meta_store'] = array();
		}

		/* ---------------------------------------------------------------
		 * 共用件：源码函数体提取（M6H2 同款）+ fake transport 驱动件
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

		private static function blockBetween( string $src, string $startMarker, string $endMarker ): string {
			$start = strpos( $src, $startMarker );
			self::assertNotFalse( $start, "HTML 须含 {$startMarker}" );
			$end = strpos( $src, $endMarker, $start + strlen( $startMarker ) );
			self::assertNotFalse( $end, "须在 {$startMarker} 之后含 {$endMarker}" );
			return substr( $src, $start, $end - $start );
		}

		/**
		 * 构造 openai_custom 驱动（fake transport + K1/K2 捕获——M6ADriverMeteringTest 同形态）。
		 *
		 * @param array<int, array<string, mixed>> $responses 依次返回的 WP HTTP 形态响应（耗尽重复末值）。
		 * @param array<string, mixed>             $overrides 配置覆盖。
		 * @return array{driver: OpenAiCustomReviewDriver, requests: array<int, array>, usage_keys: array<int, string>, k2: array<int, array>}
		 */
		private static function makeDriver( array $responses, array $overrides = array() ): array {
			$request_log = array();
			$usage_keys  = array();
			$k2          = array();
			$last        = null;
			$transport   = static function ( string $url, array $args ) use ( &$request_log, &$responses, &$last ) {
				$request_log[] = array( 'url' => $url, 'args' => $args );
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
				static function ( string $key ) use ( &$usage_keys ): void {
					$usage_keys[] = $key;
				},
				static function ( int $ms ): void {} // 零等待。
			);
			$config = array_merge( array(
				'api_key'        => 'sk-relay-test',
				'base_url'       => 'https://relay.example.com/v1',
				'model'          => 'my-model',
				'timeout'        => 8,
				'daily_quota'    => 200,
				'fail_mode'      => 'review',
				'system_prompt'  => 'system',
				'min_confidence' => 0.8,
				'two_stage'      => false,
				'upgrade_model'  => '',
			), $overrides );
			return array(
				'driver'     => new OpenAiCustomReviewDriver( $config, $client, static function ( array $rec ) use ( &$k2 ): void {
					$k2[] = $rec;
				} ),
				'requests'   => &$request_log,
				'usage_keys' => &$usage_keys,
				'k2'         => &$k2,
			);
		}

		/**
		 * chat/completions 200 响应（WP HTTP 形态）。
		 *
		 * @param string $verdict 判定值。
		 * @param float   $confidence 置信度。
		 * @param array<string, int>|null $usage usage 块（null=缺失——K2 不记口径）。
		 * @return array<string, mixed>
		 */
		private static function chatResponse( string $verdict = 'pass', float $confidence = 0.95, ?array $usage = array( 'prompt_tokens' => 100, 'completion_tokens' => 20 ) ): array {
			$body = array(
				'choices' => array( array(
					'message'       => array( 'role' => 'assistant', 'content' => (string) json_encode( array( 'verdict' => $verdict, 'confidence' => $confidence, 'reason' => '正常', 'hits' => array() ) ) ),
					'finish_reason' => 'stop',
				) ),
			);
			if ( null !== $usage ) {
				$body['usage'] = $usage;
			}
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => (string) json_encode( $body ),
			);
		}

		/**
		 * /models 200 响应（OpenAI 契约 data[].id[] 形态）。
		 *
		 * @param array<int, string> $ids 模型 id 列表。
		 * @return array<string, mixed>
		 */
		private static function modelsResponse( array $ids ): array {
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => (string) json_encode( array( 'object' => 'list', 'data' => array_map( static fn( string $id ): array => array( 'id' => $id, 'object' => 'model' ), $ids ) ) ),
			);
		}

		/**
		 * HTTP 错误响应（WP HTTP 形态）。
		 *
		 * @param int $status HTTP 状态码。
		 * @return array<string, mixed>
		 */
		private static function httpErrorResponse( int $status ): array {
			return array(
				'response' => array( 'code' => $status ),
				'headers'  => array(),
				'body'     => (string) json_encode( array( 'error' => array( 'message' => 'err' ) ) ),
			);
		}

		/* ===============================================================
		 * ① 驱动四件套（E-1：纯配置子类——零协议逻辑新增）
		 * =============================================================== */

		public function test_driver_id_and_subclass_shape(): void {
			self::assertSame( 'openai_custom', OpenAiCustomReviewDriver::DRIVER_ID, '【M6E】驱动 id' );
			$ref = new \ReflectionClass( OpenAiCustomReviewDriver::class );
			self::assertTrue( $ref->isSubclassOf( \WinkGatekeeper\Drivers\Review\OpenAiCompatibleReviewDriver::class ), 'OpenAI 兼容协议族基类继承（五件套/收口/计量全在基类）' );
			self::assertTrue( $ref->isFinal(), 'final（配置子类不可再继承）' );
			// 无预置值常量（站长全量配置——05 §2.2 openai_custom 列）。
			self::assertFalse( $ref->hasConstant( 'DEFAULT_BASE_URL' ), '无 DEFAULT_BASE_URL（无官方预置）' );
			self::assertFalse( $ref->hasConstant( 'DEFAULT_MODEL' ), '无 DEFAULT_MODEL（模型名手填）' );
		}

		public function test_config_from_settings_eight_keys_and_top_passthrough(): void {
			// 出厂树派生：8 键与子树出厂值一致 + 顶层 two_stage/upgrade_model 透传。
			$config = OpenAiCustomReviewDriver::config_from_settings( SettingsSchema::defaults() );
			self::assertSame( '', $config['api_key'], 'api_key 出厂空' );
			self::assertSame( '', $config['base_url'], 'base_url 出厂空（无默认——站长全量配置）' );
			self::assertSame( '', $config['model'], 'model 出厂空（手填）' );
			self::assertSame( 8, $config['timeout'] );
			self::assertSame( 200, $config['daily_quota'] );
			self::assertSame( 'review', $config['fail_mode'] );
			self::assertSame( SettingsSchema::DEFAULT_SYSTEM_PROMPT, $config['system_prompt'], '系统提示词单一来源（schema 出厂值）' );
			self::assertSame( 0.8, $config['min_confidence'] );
			self::assertTrue( $config['two_stage'], '两段式=协议族基类行为（review.cloud 顶层共用）' );
			self::assertSame( 'deepseek-reasoner', $config['upgrade_model'], '顶层 upgrade_model 透传（残留值不吞不换——UI 手填可见可清理，R1 裁定）' );

			// 覆盖派生：子树值 + two_stage 关 + upgrade_model 空（留空=不升级短路）。
			$settings = SettingsSchema::defaults();
			$settings['review']['cloud']['openai_custom'] = array(
				'api_key' => 'sk-relay', 'base_url' => 'https://relay.example.com/v1', 'model' => 'gpt-x',
				'timeout' => 12, 'daily_quota' => 50, 'fail_mode' => 'open',
				'system_prompt' => '自定义提示词', 'min_confidence' => 0.9,
			);
			$settings['review']['cloud']['two_stage']     = false;
			$settings['review']['cloud']['upgrade_model'] = '';
			$config = OpenAiCustomReviewDriver::config_from_settings( $settings );
			self::assertSame( 'sk-relay', $config['api_key'] );
			self::assertSame( 'https://relay.example.com/v1', $config['base_url'] );
			self::assertSame( 'gpt-x', $config['model'] );
			self::assertSame( 12, $config['timeout'] );
			self::assertSame( 50, $config['daily_quota'] );
			self::assertSame( 'open', $config['fail_mode'] );
			self::assertSame( '自定义提示词', $config['system_prompt'] );
			self::assertSame( 0.9, $config['min_confidence'] );
			self::assertFalse( $config['two_stage'] );
			self::assertSame( '', $config['upgrade_model'], '空串原样透传（基类守卫短路两段式）' );
		}

		public function test_registry_entry_six_dims(): void {
			$entry = OpenAiCustomReviewDriver::registry_entry();
			self::assertSame( 'openai_custom', $entry['id'] );
			self::assertSame( 'review', $entry['type'] );
			self::assertSame( OpenAiCustomReviewDriver::class, $entry['class'] );
			self::assertSame( '自定义 OpenAI 兼容端点', $entry['label'], '显示名单一来源迁移（future 表既有字面，不新造名）' );
			self::assertSame( 'self-managed', $entry['reachability'], '可达性自由字段（站长自负——DriverRegistry 不校验值域）' );
			self::assertSame( 'usage', $entry['billing'], 'billing 闭集四选一（实际口径取决于端点——取按量通用义）' );
			self::assertSame( array(), $entry['frontend'], 'review 类无前端形态' );
			self::assertSame( array( 'http_status' => 'classify' ), $entry['error_map'] );
			self::assertSame( 'chat-completions', $entry['api_version'] );

			// settings 8 键声明（required 三项=future 表既有口径）。
			self::assertSame(
				array( 'api_key', 'base_url', 'model', 'timeout', 'daily_quota', 'fail_mode', 'system_prompt', 'min_confidence' ),
				array_keys( $entry['settings'] ),
				'8 键声明与 schema 子树键集一致（零新增键）'
			);
			self::assertSame( 'password', $entry['settings']['api_key']['type'] );
			self::assertTrue( $entry['settings']['api_key']['required'] );
			self::assertSame( 'url', $entry['settings']['base_url']['type'] );
			self::assertTrue( $entry['settings']['base_url']['required'] );
			self::assertSame( 'text', $entry['settings']['model']['type'] );
			self::assertTrue( $entry['settings']['model']['required'] );
			self::assertSame( 'textarea', $entry['settings']['system_prompt']['type'] );
			self::assertArrayNotHasKey( 'required', $entry['settings']['system_prompt'], 'system_prompt 不 required' );

			self::assertSame( DriverPrivacy::entry( 'openai_custom' ), $entry['privacy'], 'privacy 第七维已备（cross_border=null→通用出境黄条）' );
			self::assertCount( 4, $entry['guide']['steps'], '接入指引四步' );
			self::assertStringContainsString( '/chat/completions', implode( ' ', $entry['guide']['steps'] ), '指引注明端点协议要求' );
			self::assertStringContainsString( '/v1', implode( ' ', $entry['guide']['steps'] ), '指引注明 base_url 路径前缀陷阱（OpenAI 官方族填到 /v1）' );
			self::assertArrayNotHasKey( 'doc_url', $entry, '泛端点无统一文档——doc_url 省略' );
			self::assertSame( 'M6E', $entry['milestone'] );

			// DriverRegistry 登记可通过（六维结构校验面）。
			$registry = new DriverRegistry();
			$registry->register( $entry );
			self::assertNotNull( $registry->get( 'openai_custom' ) );
			self::assertArrayHasKey( 'openai_custom', $registry->by_type( 'review' ), 'Tab③ 下拉/点名册/统计卡 registry 渲染面自动长出' );
		}

		public function test_is_configured_override_requires_three_keys(): void {
			// Q5 裁定采纳：覆写加查 base_url+model 非空（半配置更早短路、日配额不空烧）。
			$key = array( 'api_key' => 'sk-relay', 'base_url' => '', 'model' => '' );
			self::assertFalse( ( new OpenAiCustomReviewDriver( $key, new RemoteClient( static fn() => array() ) ) )->is_configured(), '仅 api_key=未配置' );
			$key['base_url'] = 'https://relay.example.com/v1';
			self::assertFalse( ( new OpenAiCustomReviewDriver( $key, new RemoteClient( static fn() => array() ) ) )->is_configured(), '缺 model=未配置' );
			$key['model'] = 'my-model';
			self::assertTrue( ( new OpenAiCustomReviewDriver( $key, new RemoteClient( static fn() => array() ) ) )->is_configured(), '三键齐=已配置' );
		}

		public function test_probe_models_request_shape(): void {
			$env = self::makeDriver( array( self::modelsResponse( array( 'my-model' ) ) ), array( 'base_url' => 'https://relay.example.com/v1/' ) );
			$result = $env['driver']->probe_models();

			self::assertCount( 1, $env['requests'], '探测一次' );
			$req = $env['requests'][0];
			self::assertSame( 'https://relay.example.com/v1/models', $req['url'], 'GET {base_url}/models（尾斜杠 rtrim）' );
			self::assertSame( 'Bearer sk-relay-test', $req['args']['headers']['Authorization'], 'Bearer 鉴权' );
			self::assertSame( 5, $req['args']['timeout'], 'timeout 5（探测短超时）' );
			self::assertArrayNotHasKey( 'usage_key', $req['args'], '不计 usage 不落库（零计量探测）' );
			self::assertSame( array(), $env['usage_keys'], 'K1 零计量' );
			self::assertTrue( $result->is_ok() );
		}

		/* ===============================================================
		 * ② check() 全链 + 两段式（R1 手填升级模型/留空短路）+ 计量继承（E-5）
		 * =============================================================== */

		public function test_check_full_chain_and_k1_metering(): void {
			$env = self::makeDriver( array( self::chatResponse() ) );

			$verdict = $env['driver']->check( '张三\n好评论', array( 'author' => '张三' ) );

			self::assertSame( 'pass', $verdict->verdict() );
			self::assertSame( 'openai_custom', $verdict->driver_id(), '事件/统计归桶 driver=openai_custom（基类 static::DRIVER_ID 接线）' );
			self::assertSame( array( 'openai_custom' ), $env['usage_keys'], 'K1 usage_key=驱动 id（M6ADriverMeteringTest 同款断言）' );
			self::assertCount( 1, $env['k2'], 'K2 usage 在场=解析成功回写' );
			self::assertSame( 'https://relay.example.com/v1/chat/completions', $env['requests'][0]['url'], '端点拼接（base_url 后拼 /chat/completions）' );
			$body = json_decode( $env['requests'][0]['args']['body'], true );
			self::assertSame( 'my-model', $body['model'], 'r1 模型=手填判定模型' );
			self::assertArrayNotHasKey( 'reasoning_effort', $body, 'Q1 裁定：思考强度参数不透传（模型名表达档位）' );
			self::assertArrayNotHasKey( 'thinking', $body, '同上（零非标参数键）' );
		}

		public function test_two_stage_upgrade_with_manual_model(): void {
			// r1 低置信 → r2 换手填 upgrade_model 恰一次（附加升级 hint）。
			$env = self::makeDriver(
				array( self::chatResponse( 'review', 0.5 ), self::chatResponse( 'pass', 0.95 ) ),
				array( 'two_stage' => true, 'upgrade_model' => 'strong-model-x' )
			);
			$verdict = $env['driver']->check( '内容', array() );

			self::assertSame( 'pass', $verdict->verdict(), 'r2 高置信直取' );
			self::assertCount( 2, $env['requests'], '两段各一次' );
			$body2 = json_decode( $env['requests'][1]['args']['body'], true );
			self::assertSame( 'strong-model-x', $body2['model'], 'r2 用手填升级模型（R1：模型名表达更强判定）' );
			self::assertCount( 3, $body2['messages'], '升级 hint=独立 user 消息（不进 content_json）' );
			self::assertSame( array( 'openai_custom', 'openai_custom' ), $env['usage_keys'], 'K1 双计=真实调用次数（与账单一致）' );
		}

		public function test_two_stage_empty_upgrade_model_short_circuits(): void {
			// upgrade_model 空（留空=不升级）→ 低置信不发起 r2（基类守卫天然短路）。
			$env = self::makeDriver( array( self::chatResponse( 'review', 0.5 ) ), array( 'two_stage' => true, 'upgrade_model' => '' ) );
			$verdict = $env['driver']->check( '内容', array() );

			self::assertSame( 'review', $verdict->verdict(), '低置信收口=一律降 review（05 §2.2）' );
			self::assertCount( 1, $env['requests'], '留空短路：零 r2 调用' );
			self::assertSame( array( 'openai_custom' ), $env['usage_keys'] );
		}

		public function test_k2_usage_missing_not_recorded(): void {
			// 泛端点 usage 字段缺失=不记只进 K1（基类 is_array 守卫——诚实口径）。
			$env = self::makeDriver( array( self::chatResponse( 'pass', 0.95, null ) ) );
			$env['driver']->check( '内容', array() );

			self::assertSame( array(), $env['k2'], 'K2 缺失不记（token 面板该驱动如实为 0）' );
			self::assertSame( array( 'openai_custom' ), $env['usage_keys'], 'K1 照计（按次口径）' );
		}

		/* ===============================================================
		 * ③ schema/Sanitize 8 键已备（零新增键——验证而非新增）
		 * =============================================================== */

		public function test_schema_sanitize_keys_prepared_no_new_keys(): void {
			$defaults = SettingsSchema::defaults();
			$sub = $defaults['review']['cloud']['openai_custom'];
			self::assertSame(
				array( 'api_key', 'base_url', 'model', 'timeout', 'daily_quota', 'fail_mode', 'system_prompt', 'min_confidence' ),
				array_keys( $sub ),
				'schema 8 键子树（SettingsSchema 既有——本批零新增）'
			);
			$rules = Sanitize::rules();
			foreach ( array(
				'review.cloud.openai_custom.api_key'        => 'password',
				'review.cloud.openai_custom.base_url'       => 'url',
				'review.cloud.openai_custom.model'          => 'text',
				'review.cloud.openai_custom.timeout'        => 'absint',
				'review.cloud.openai_custom.daily_quota'    => 'absint',
				'review.cloud.openai_custom.fail_mode'      => 'text',
				'review.cloud.openai_custom.system_prompt'  => 'textarea',
				'review.cloud.openai_custom.min_confidence' => 'float',
			) as $key => $type ) {
				self::assertSame( $type, $rules[ $key ] ?? null, "Sanitize 映射 {$key} 已备（本批零新增键）" );
			}
		}

		/* ===============================================================
		 * ④ Bootstrap 装配（registry 注册+chain 分支+$driver_labels 补行）
		 * =============================================================== */

		public function test_bootstrap_registers_and_assembles_openai_custom(): void {
			$body = self::functionBody( self::source( 'includes/Bootstrap.php' ), 'assemble_gates' );
			self::assertStringContainsString( 'OpenAiCustomReviewDriver::registry_entry', $body, 'registry 注册一行（registry 第 10 条目）' );
			self::assertStringContainsString( "'openai_custom' === \$chain_id", $body, '云审链 openai_custom 分支' );
			self::assertStringContainsString( 'OpenAiCustomReviewDriver::config_from_settings', $body, '配置派生 from_settings（含顶层 two_stage/upgrade_model）' );
			self::assertStringContainsString( "cloud_chain['openai_custom']", $body, 'is_configured 守卫入链（未配置静默剔除）' );
		}

		public function test_bootstrap_widget_labels_static_line(): void {
			// source-scan 收窄到 render_dashboard_widget 函数体（I-1 坑注：注入面钉必须收窄）。
			$body = self::functionBody( self::source( 'includes/Bootstrap.php' ), 'render_dashboard_widget' );
			self::assertStringContainsString( "'openai_custom'", $body, '$driver_labels 补 registry label 静态行（future 循环注入随退役消失——deepseek I-1 先例同款）' );
			self::assertStringContainsString( 'OpenAiCustomReviewDriver::registry_entry', $body, 'label 单一来源=registry_entry（不硬抄字面）' );
		}

		public function test_bootstrap_registers_openai_custom_probe_ajax(): void {
			self::assertStringContainsString( 'AJAX_TEST_CREDENTIALS_OPENAI_CUSTOM', self::source( 'includes/Bootstrap.php' ), 'Bootstrap 注册 openai_custom 探活 AJAX 端点' );
		}

		/* ===============================================================
		 * ⑤ future 段退役钉（openai_custom 退役、moli 留存）
		 * =============================================================== */

		public function test_openai_custom_leaves_future_drivers(): void {
			$body   = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'future_review_drivers' );
			self::assertStringNotContainsString( "'openai_custom'", $body, 'openai_custom 已于 M6E 实装——登记入 registry，脱离 future 段（下拉已实装驱动）' );
			self::assertStringContainsString( "'moli'", $body, 'moli（M4 里程碑）仍留 future 段' );

			$future = SettingsPage::future_review_drivers();
			self::assertArrayNotHasKey( 'openai_custom', $future );
			self::assertArrayHasKey( 'moli', $future );
		}

		/* ===============================================================
		 * ⑥ CredentialProbe：classify_openai_custom_models 三态 + 本地预检
		 * =============================================================== */

		public function test_classify_green_with_model_in_list(): void {
			$state = CredentialProbe::classify_openai_custom_models( RemoteResult::classify( self::modelsResponse( array( 'my-model', 'other' ) ) ), 'my-model' );
			self::assertSame( 'green', $state['state'] );
			self::assertSame( 'models_ok', $state['code'] );
			self::assertStringContainsString( 'my-model', $state['message'], '绿态升级为「列表含该模型」' );
			self::assertStringContainsString( '零 token', $state['message'] );
		}

		public function test_classify_green_without_model(): void {
			$state = CredentialProbe::classify_openai_custom_models( RemoteResult::classify( self::modelsResponse( array( 'a', 'b' ) ) ) );
			self::assertSame( 'green', $state['state'], '未传模型=纯探活绿' );
			self::assertStringNotContainsString( '在可用列表', $state['message'], '无模型校验语义不出现' );
		}

		public function test_classify_red_401_403(): void {
			foreach ( array( 401, 403 ) as $status ) {
				$state = CredentialProbe::classify_openai_custom_models( RemoteResult::classify( self::httpErrorResponse( $status ) ) );
				self::assertSame( 'red', $state['state'], "HTTP {$status} 红" );
				self::assertStringContainsString( '密钥被端点拒绝', $state['message'], '不点名厂商（文案中性化）' );
				self::assertStringNotContainsString( 'DeepSeek', $state['message'], '泛端点文案不点名厂商' );
				self::assertSame( 'http=' . $status, $state['code'] );
			}
		}

		public function test_classify_red_402_neutral_copy(): void {
			$state = CredentialProbe::classify_openai_custom_models( RemoteResult::classify( self::httpErrorResponse( 402 ) ) );
			self::assertSame( 'red', $state['state'], '402 归红（端点拒绝面）' );
			self::assertStringContainsString( '402', $state['message'] );
			self::assertStringContainsString( '计费口径', $state['message'], '计费口径由服务方定义（中性化——泛端点无统一计费语义）' );
			self::assertStringNotContainsString( 'DeepSeek', $state['message'] );
			self::assertStringNotContainsString( '充值', $state['message'], '不指引特定厂商控制台' );
		}

		public function test_classify_yellow_model_missing_not_red(): void {
			$ids = array( 'm1', 'm2', 'm3', 'm4', 'm5', 'm6', 'm7' );
			$state = CredentialProbe::classify_openai_custom_models( RemoteResult::classify( self::modelsResponse( $ids ) ), 'not-listed' );
			self::assertSame( 'yellow', $state['state'], '黄不红（泛端点列表可能不全——列表缺失不等于不可用）' );
			self::assertSame( 'model_missing', $state['code'] );
			self::assertStringContainsString( 'not-listed', $state['message'] );
			self::assertStringContainsString( 'm1 / m2', $state['message'], '前 6 个列表提示（可照抄）' );
			self::assertStringContainsString( '部分端点列表不全', $state['message'], '列表缺失不等于不可用注记' );
		}

		public function test_classify_gray_contract_mismatch(): void {
			$raw = array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '{"unexpected":true}' );
			$state = CredentialProbe::classify_openai_custom_models( RemoteResult::classify( $raw ) );
			self::assertSame( 'gray', $state['state'] );
			self::assertSame( 'contract', $state['code'] );
			self::assertStringContainsString( '契约不符', $state['message'] );
		}

		public function test_classify_gray_404_models_not_provided_hint(): void {
			$state = CredentialProbe::classify_openai_custom_models( RemoteResult::classify( self::httpErrorResponse( 404 ) ) );
			self::assertSame( 'gray', $state['state'], '404 不误报红（部分网关不提供 /models——凭据状态未知）' );
			self::assertStringContainsString( '/models', $state['message'] );
			self::assertStringContainsString( '不提供', $state['message'] );
			self::assertStringContainsString( '凭据状态未知', $state['message'], '观察首判指引' );
		}

		public function test_classify_transport_gray(): void {
			$result = RemoteResult::error( RemoteResult::TRANSPORT, 'transport_failed: connection/timeout' );
			$state  = CredentialProbe::classify_openai_custom_models( $result );
			self::assertSame( 'gray', $state['state'], '传输终态=灰（给排查方向不误报三态）' );
			self::assertStringContainsString( 'transport', $state['message'] );
		}

		public function test_local_precheck_three_keys(): void {
			// 全缺 → 黄态逐字段列缺（DriverConfigSpec::missing_fields 派生）。
			$pre = CredentialProbe::local_precheck_openai_custom( array( 'api_key' => '', 'base_url' => '', 'model' => '' ) );
			self::assertNotNull( $pre );
			self::assertSame( 'yellow', $pre['state'] );
			self::assertStringContainsString( 'API Key', $pre['message'] );
			self::assertStringContainsString( '接入地址', $pre['message'] );
			self::assertStringContainsString( '判定模型', $pre['message'] );

			// 任一缺 → 黄（逐字段）。
			$pre = CredentialProbe::local_precheck_openai_custom( array( 'api_key' => 'sk', 'base_url' => '', 'model' => 'm' ) );
			self::assertNotNull( $pre );
			self::assertSame( array( '接入地址' ), $pre['missing'] );

			// 三键齐 → null 放行走远端探测。
			self::assertNull( CredentialProbe::local_precheck_openai_custom( array( 'api_key' => 'sk', 'base_url' => 'https://relay.example.com/v1', 'model' => 'm' ) ) );
		}

		/* ===============================================================
		 * ⑦ url_audit 独立凭据测试分流（E-4：官方域=余额 / 其他=模型列表）
		 * =============================================================== */

		public function test_provider_probe_is_deepseek_matrix(): void {
			// 官方域 / 空（回落官方）/ 带路径 / 大小写 → true（维持余额探测）。
			self::assertTrue( UrlAuditWorker::provider_probe_is_deepseek( 'https://api.deepseek.com' ) );
			self::assertTrue( UrlAuditWorker::provider_probe_is_deepseek( '' ) );
			self::assertTrue( UrlAuditWorker::provider_probe_is_deepseek( 'https://api.deepseek.com/v1' ) );
			self::assertTrue( UrlAuditWorker::provider_probe_is_deepseek( 'HTTPS://API.DEEPSEEK.COM/' ) );

			// 其他 host → false（切 /models 探活）。
			self::assertFalse( UrlAuditWorker::provider_probe_is_deepseek( 'https://relay.example.com/v1' ) );
			self::assertFalse( UrlAuditWorker::provider_probe_is_deepseek( 'https://api.deepseek.com.evil.example.com' ), 'host 精确比对（前缀伪装不认）' );

			// 无 host 形态（裸词/相对路径）→ 回落官方（fail-safe=分流前既有行为面）。
			self::assertTrue( UrlAuditWorker::provider_probe_is_deepseek( 'not-a-url' ) );
		}

		public function test_url_audit_ajax_probe_branches(): void {
			$handler = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'ajax_test_credentials_url_audit' );
			self::assertStringContainsString( 'provider_probe_is_deepseek', $handler, '分流点收敛一处（UrlAuditWorker 纯函数）' );
			self::assertStringContainsString( 'probe_balance', $handler, '官方域分支：维持 DeepSeek 余额探测（信息量优先）' );
			self::assertStringContainsString( 'classify_deepseek_balance', $handler );
			self::assertStringContainsString( 'OpenAiCustomReviewDriver', $handler, '非官方域分支：openai_custom 驱动 /models 探活' );
			self::assertStringContainsString( 'probe_models', $handler );
			self::assertStringContainsString( 'classify_openai_custom_models', $handler );
		}

		public function test_url_audit_custom_model_manual_text_input(): void {
			$settings = SettingsSchema::defaults();
			$settings['url_audit']['provider'] = array(
				'mode' => 'custom', 'api_key' => 'sk-relay', 'base_url' => 'https://relay.example.com/v1', 'model' => 'my-model',
			);
			$html = self::renderUrlCard( $settings );
			self::assertStringContainsString( 'id="wk-url-audit-provider-model"', $html, 'custom 档判定模型锚点保持' );
			self::assertStringContainsString( '<input type="text" id="wk-url-audit-provider-model"', $html, '改手填 text（原静态两选项 select 退役）' );
			self::assertStringNotContainsString( '<select id="wk-url-audit-provider-model"', $html, 'select 形态退役' );
			self::assertStringContainsString( '留空=标准档', $html, '空=标准档回落语义保留（provider_config 回落同源）' );
			self::assertStringContainsString( '模型列表探活', $html, '测试按钮 description 注明分流口径（官方=余额/其他=模型列表）' );
		}

		private static function renderUrlCard( array $settings ): string {
			$method = new \ReflectionMethod( SettingsPage::class, 'render_url_audit_card' );
			$method->setAccessible( true );
			ob_start();
			$method->invoke( null, $settings );
			return (string) ob_get_clean();
		}

		/* ===============================================================
		 * ⑧ UI source-scan：Tab③ openai_custom 配置卡 + AJAX + JS 绑定
		 * =============================================================== */

		public function test_tab3_openai_custom_card_anchors(): void {
			$src = self::source( 'includes/settings/SettingsPage.php' );
			self::assertStringContainsString( 'wk-review-openai-custom-card', $src, '配置区壳' );
			self::assertStringContainsString( 'data-wk-vendor="openai_custom"', $src, 'vendor 切换形态（bindVendorSwitcher 既有件消费——零 JS 改动）' );
			foreach ( array(
				'wk-openai-custom-base-url',
				'wk-openai-custom-model',
				'wk-openai-custom-two-stage',
				'wk-openai-custom-upgrade-model',
				'wk-openai-custom-test-btn',
				'wk-openai-custom-test-result',
			) as $anchor ) {
				self::assertStringContainsString( $anchor, $src, "锚 {$anchor}" );
			}
		}

		public function test_tab3_card_manual_inputs_and_quota_line(): void {
			$src  = self::source( 'includes/settings/SettingsPage.php' );
			$card = self::blockBetween( $src, 'wk-review-openai-custom-card', 'wk-review-unconfigured-note' );
			self::assertStringContainsString( '<input type="url" id="wk-openai-custom-base-url"', $card, '接入地址=url 手填（空占位+路径前缀提示）' );
			self::assertStringContainsString( '/chat/completions', $card, '拼接规则提示（newswright 路径前缀陷阱实证）' );
			self::assertStringContainsString( '<input type="text" id="wk-openai-custom-model"', $card, '判定模型=手填 text（无静态下拉）' );
			self::assertStringNotContainsString( '<select id="wk-openai-custom-model"', $card, '无静态模型下拉' );
			self::assertStringContainsString( '<input type="text" id="wk-openai-custom-upgrade-model"', $card, '升级用模型=手填 text（R1 裁定）' );
			self::assertStringContainsString( '留空=不升级', $card, '留空短路占位说明' );
			self::assertStringContainsString( 'daily_total_for', $card, '今日已判计数（Usage 键换 openai_custom）' );
			self::assertStringContainsString( "'openai_custom'", $card, '计量键=openai_custom' );
		}

		public function test_ajax_test_credentials_openai_custom_handler_shape(): void {
			$src = self::source( 'includes/settings/SettingsPage.php' );
			self::assertStringContainsString( "AJAX_TEST_CREDENTIALS_OPENAI_CUSTOM = 'wink_gatekeeper_test_credentials_openai_custom'", $src, 'AJAX action 常量' );
			$handler = self::functionBody( $src, 'ajax_test_credentials_openai_custom' );
			self::assertStringContainsString( 'check_ajax_referer', $handler, 'nonce 双闸' );
			self::assertStringContainsString( 'manage_options', $handler, 'manage_options 双闸' );
			self::assertStringContainsString( 'probe_field', $handler, '表单覆盖语义（password 空串保留已存值）' );
			self::assertStringContainsString( 'local_precheck_openai_custom', $handler, '本地预检（三键缺=黄态零外呼）' );
			self::assertStringContainsString( 'probe_models', $handler, '/models 探活（零 token）' );
			self::assertStringContainsString( 'classify_openai_custom_models', $handler, '三态判定' );
			self::assertStringContainsString( 'RemoteClient', $handler, 'HTTP 走传输脊' );
			self::assertStringNotContainsString( 'echo', $handler, '响应闭集经 wp_send_json_success（凭据零回显）' );
		}

		public function test_admin_js_openai_custom_probe_and_two_stage_binding(): void {
			$js = self::source( 'assets/js/admin-brand.js' );
			self::assertStringContainsString( 'wk-openai-custom-test-btn', $js, '探测按钮绑定' );
			self::assertStringContainsString( 'wink_gatekeeper_test_credentials_openai_custom', $js, 'action 名' );
			self::assertStringContainsString( 'openai_custom_api_key', $js, 'api_key 字段' );
			self::assertStringContainsString( 'openai_custom_base_url', $js, 'base_url 字段' );
			self::assertStringContainsString( 'openai_custom_model', $js, 'model 字段（绿态列表校验素材）' );
			self::assertStringContainsString( 'wk-openai-custom-two-stage', $js, 'two_stage 显隐联动（wk-deepseek-two-stage 范式）' );
			self::assertStringContainsString( 'wk-openai-custom-upgrade-model-row', $js, '升级模型行显隐联动' );
		}

		/* ===============================================================
		 * ⑨ 版本钉（Q6 裁定：0.7.4→0.8.0——功能面扩容按里程碑位进位）
		 * =============================================================== */

		/* 【M6·批H4·补钉】体检工厂 openai_custom 解析钉（E 批漏配面——手测二轮实测抓获：
		 * Tab⑦ 误报「云端审核还没配置」+无开始钮；同型二犯对照 M5·C2 deepseek 漏登记）。 */
		public function test_h4_existing_audit_factory_resolves_openai_custom(): void {
			$settings = SettingsSchema::defaults();
			$settings['review']['cloud']['enabled'] = true;
			$settings['review']['cloud']['chain']   = array( 'openai_custom' );
			$settings['review']['cloud']['openai_custom'] = array_merge(
				is_array( $settings['review']['cloud']['openai_custom'] ?? null ) ? $settings['review']['cloud']['openai_custom'] : array(),
				array( 'api_key' => 'sk-x', 'base_url' => 'https://ep.example.com/v1', 'model' => 'gpt-6' )
			);

			$resolved = ( new ExistingAuditWorker( $settings ) )->default_driver_factory();
			$this->assertNotNull( $resolved, '【H4 补钉】chain=openai_custom 解析出驱动（原恒 null 误报未配置）' );
			$this->assertSame( 'openai_custom', $resolved['id'] );
			$this->assertInstanceOf( OpenAiCustomReviewDriver::class, $resolved['driver'] );
			$this->assertTrue( ExistingAuditWorker::has_driver( $settings ), 'has_driver=真（开始体检钮前置闸开）' );
		}

		public function test_version_four_spot_sync(): void {
			$entry = self::source( 'wink-wp-gatekeeper.php' );
			self::assertMatchesRegularExpression( '/^ \* Version:\s+0\.8\.3$/m', $entry, '插件头 Version 须为 0.8.3' );
			self::assertStringContainsString( "define( 'WINK_GATEKEEPER_VERSION', '0.8.3' )", $entry, '版本常量须为 0.8.3' );
			$readme = self::source( 'readme.txt' );
			self::assertMatchesRegularExpression( '/^Stable tag: 0\.8\.3$/m', $readme, 'readme Stable tag 须为 0.8.3' );
			self::assertStringContainsString( '= 0.8.0 =', $readme, 'Changelog 须有 0.8.0 条目' );
		}
	}
}
