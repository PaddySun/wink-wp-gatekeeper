<?php
/**
 * M6 批 B · B-3（N4）DeepSeek 凭据测试改余额端点（2026-11-16）。
 *
 * 施工依据（M6 定稿 §3 B-3 + M5-MANUAL-FEEDBACK-1 §4 N4）：
 *   ① DeepSeekReviewDriver::probe_balance()：GET {base}/user/balance（Bearer，零费用；
 *      官方契约 api/查询余额.md——200 → {is_available, balance_infos[{currency,
 *      total_balance, granted_balance, topped_up_balance}]}）；
 *   ② CredentialProbe::classify_deepseek_balance() 三态：绿=200 且 is_available=true
 *      （响应附 balance 对象 currency/total_balance/granted_balance）；黄=200 且
 *      is_available=false（欠费/不可用）；红=401/402/403/transport；
 *   ③ Tab③ DeepSeek「测试凭据」AJAX 处理器切到余额探测，绿态人话行
 *      「余额 110.00 CNY（含赠金 10.00）」；响应闭集（不含提交值——凭据零回显契约）；
 *   ④ 范围边界：openai_custom 泛端点无标准余额 API→保留 /models 探活（probe_models
 *      不删，UI 注说明）；网址核查独立凭证（provider custom，DeepSeek 协议族）同用
 *      余额探测（base_url 跟随其配置）——新增独立凭据测试 AJAX 面。
 *
 * 官方响应夹具取自查询余额.md 文档示例值（110.00/10.00/100.00 CNY）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Settings\CredentialProbe;
use WinkGatekeeper\Settings\SettingsPage;

final class M6BBalanceProbeTest extends TestCase {

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

	/**
	 * 构造带捕获传输脊的 RemoteClient。
	 *
	 * @param array<int, array<string, mixed>> $responses 响应队列。
	 * @return array{client: RemoteClient, requests: array<int, array<string, mixed>>}
	 */
	private static function makeClient( array $responses ): array {
		$requests  = array();
		$transport = static function ( string $url, array $args ) use ( &$responses, &$requests ) {
			$requests[] = array( 'url' => $url, 'args' => $args );
			return array_shift( $responses );
		};
		$client = new RemoteClient(
			$transport,
			null,
			static function ( int $ms ): void {}
		);
		return array( 'client' => $client, 'requests' => &$requests );
	}

	private static function blockBetween( string $src, string $startMarker, string $endMarker ): string {
		$start = strpos( $src, $startMarker );
		self::assertNotFalse( $start, "HTML 须含 {$startMarker}" );
		$end = strpos( $src, $endMarker, $start + strlen( $startMarker ) );
		self::assertNotFalse( $end, "须在 {$startMarker} 之后含 {$endMarker}" );
		return substr( $src, $start, $end - $start );
	}

	/** @return array<string, mixed> WP HTTP 形态 200 响应壳。 */
	private static function okResponse( string $body ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => $body,
		);
	}

	/** @return array<string, mixed> 官方文档绿态夹具（is_available=true + balance_infos，查询余额.md 示例值）。 */
	private static function balanceOkBody(): string {
		return (string) json_encode( array(
			'is_available'   => true,
			'balance_infos'  => array(
				array(
					'currency'          => 'CNY',
					'total_balance'     => '110.00',
					'granted_balance'   => '10.00',
					'topped_up_balance' => '100.00',
				),
			),
		) );
	}

	/* ===============================================================
	 * ① probe_balance：GET {base}/user/balance（Bearer 零费用）
	 * =============================================================== */

	public function test_probe_balance_hits_user_balance_endpoint(): void {
		$env    = self::makeClient( array( self::okResponse( self::balanceOkBody() ) ) );
		$driver = new \WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver(
			array( 'api_key' => 'sk-live', 'base_url' => 'https://api.deepseek.com' ),
			$env['client']
		);

		$result = $driver->probe_balance();

		self::assertCount( 1, $env['requests'], '恰一次探测' );
		self::assertSame( 'https://api.deepseek.com/user/balance', $env['requests'][0]['url'], 'GET {base}/user/balance（官方零费用端点）' );
		self::assertSame( 'Bearer sk-live', $env['requests'][0]['args']['headers']['Authorization'], 'Bearer 鉴权' );
		self::assertTrue( $result->is_ok() );
	}

	public function test_probe_balance_follows_custom_base_url(): void {
		// 网址核查独立凭证 base_url 跟随其配置（B-3 范围边界）。
		$env    = self::makeClient( array( self::okResponse( self::balanceOkBody() ) ) );
		$driver = new \WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver(
			array( 'api_key' => 'sk-own', 'base_url' => 'https://dsproxy.example.com/' ),
			$env['client']
		);
		$driver->probe_balance();
		self::assertSame( 'https://dsproxy.example.com/user/balance', $env['requests'][0]['url'], 'base_url 跟随配置（尾斜杠归一）' );
	}

	public function test_probe_models_retained_for_generic_endpoints(): void {
		// openai_custom 泛端点无标准余额 API→保留 /models 探活（probe_models 不删）。
		$src = self::source( 'includes/drivers/review/DeepSeekReviewDriver.php' );
		self::assertStringContainsString( 'function probe_models', $src, 'probe_models 保留（泛兼容端点探活）' );
		self::assertStringContainsString( '/models', $src );
		self::assertStringContainsString( 'function probe_balance', $src, 'probe_balance 新增（主凭证测试）' );
	}

	/* ===============================================================
	 * ② classify_deepseek_balance 三态
	 * =============================================================== */

	public function test_classify_green_with_balance_object(): void {
		$state = CredentialProbe::classify_deepseek_balance( RemoteResult::classify( self::okResponse( self::balanceOkBody() ) ) );

		self::assertSame( 'green', $state['state'], '绿=200 且 is_available=true' );
		self::assertSame( 'CNY', $state['balance']['currency'], '响应附 balance 对象' );
		self::assertSame( '110.00', $state['balance']['total_balance'] );
		self::assertSame( '10.00', $state['balance']['granted_balance'] );
		self::assertStringContainsString( '余额 110.00 CNY（含赠金 10.00）', $state['message'], '绿态人话行（施工图 B-3 逐字）' );
	}

	public function test_classify_green_without_grant_omits_grant_segment(): void {
		$body  = (string) json_encode( array(
			'is_available'  => true,
			'balance_infos' => array(
				array( 'currency' => 'CNY', 'total_balance' => '100.00', 'granted_balance' => '0.00', 'topped_up_balance' => '100.00' ),
			),
		) );
		$state = CredentialProbe::classify_deepseek_balance( RemoteResult::classify( self::okResponse( $body ) ) );

		self::assertSame( 'green', $state['state'] );
		self::assertStringContainsString( '余额 100.00 CNY', $state['message'] );
		self::assertStringNotContainsString( '含赠金', $state['message'], '零赠金不虚构赠金段' );
	}

	public function test_classify_yellow_when_unavailable(): void {
		$body  = (string) json_encode( array( 'is_available' => false, 'balance_infos' => array() ) );
		$state = CredentialProbe::classify_deepseek_balance( RemoteResult::classify( self::okResponse( $body ) ) );

		self::assertSame( 'yellow', $state['state'], '黄=200 且 is_available=false（欠费/不可用）' );
		self::assertSame( 'balance_unavailable', $state['code'] );
	}

	public function test_classify_red_on_auth_rejection(): void {
		foreach ( array( 401, 402, 403 ) as $status ) {
			$state = CredentialProbe::classify_deepseek_balance( RemoteResult::classify( array(
				'response' => array( 'code' => $status ),
				'headers'  => array(),
				'body'     => '{"error":{"message":"auth"}}',
			) ) );
			self::assertSame( 'red', $state['state'], "HTTP {$status} → 红（密钥被拒/欠费）" );
		}
	}

	public function test_classify_red_on_402_mentions_recharge(): void {
		$state = CredentialProbe::classify_deepseek_balance( RemoteResult::classify( array(
			'response' => array( 'code' => 402 ),
			'headers'  => array(),
			'body'     => '{"error":{"message":"insufficient balance"}}',
		) ) );
		self::assertSame( 'red', $state['state'] );
		self::assertStringContainsString( '充值', $state['message'], '402 提示充值' );
	}

	public function test_classify_red_on_transport_failure(): void {
		// 红=401/transport（施工图三态口径——传输失败不落灰）。RemoteResult::error 直接构造。
		$result = RemoteResult::error( RemoteResult::TRANSPORT, 'transport_failed: connection/timeout' );
		$state  = CredentialProbe::classify_deepseek_balance( $result );
		self::assertSame( 'red', $state['state'], 'transport → 红' );
	}

	public function test_classify_gray_on_contract_anomaly(): void {
		// 200 但 JSON 不含 is_available=契约异常（gray 防御态——三态主判之外的诚实兜底，
		// 同 classify_deepseek 形态；非 JSON 200 由传输脊归 parse→红，不走本分支）。
		$state = CredentialProbe::classify_deepseek_balance( RemoteResult::classify( self::okResponse( '{"unexpected":true}' ) ) );
		self::assertSame( 'gray', $state['state'], '契约不符 → 灰（不虚构三态）' );
		self::assertSame( 'contract', $state['code'] );
	}

	public function test_classify_response_closed_set_never_echoes_credentials(): void {
		// AJAX 响应闭集（不含提交值——凭据零回显契约照查）。
		$env    = self::makeClient( array( self::okResponse( self::balanceOkBody() ) ) );
		$driver = new \WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver(
			array( 'api_key' => 'sk-secret-value', 'base_url' => 'https://api.deepseek.com' ),
			$env['client']
		);
		$state  = CredentialProbe::classify_deepseek_balance( $driver->probe_balance() );

		self::assertSame( array( 'state', 'message', 'missing', 'code', 'balance' ), array_keys( $state ), '响应键闭集' );
		self::assertStringNotContainsString( 'sk-secret-value', (string) json_encode( $state, JSON_UNESCAPED_UNICODE ), '零密钥回显' );
	}

	/* ===============================================================
	 * ③ Tab③ DeepSeek AJAX 处理器切换 + UI 文案
	 * =============================================================== */

	public function test_deepseek_ajax_handler_switched_to_balance_probe(): void {
		$sp     = self::source( 'includes/settings/SettingsPage.php' );
		$handler = self::functionBody( $sp, 'ajax_test_credentials_deepseek' );
		self::assertStringContainsString( 'check_ajax_referer', $handler, 'nonce 双闸' );
		self::assertStringContainsString( 'manage_options', $handler, 'manage_options 双闸' );
		self::assertStringContainsString( 'probe_balance', $handler, '探活改走余额端点（N4）' );
		self::assertStringContainsString( 'classify_deepseek_balance', $handler, '三态判定走余额分类' );
		self::assertStringNotContainsString( 'probe_models', $handler, '处理器不再用模型列表探活（【M6·批B·N4 契约翻转】——probe_models 保留给泛端点）' );
		self::assertStringContainsString( 'local_precheck_deepseek', $handler, '本地预检（key 空=黄态零外呼）保留' );
	}

	public function test_ui_copy_mentions_balance_probe(): void {
		$sp = self::source( 'includes/settings/SettingsPage.php' );
		self::assertStringContainsString( '余额探测', $sp, 'UI 说明改写为余额探测语义' );
		self::assertStringContainsString( '独立端点用模型列表探活', $sp, 'openai_custom 泛端点差异注（B-3 范围边界）' );
	}

	/* ===============================================================
	 * ④ 网址核查独立凭据测试面（provider custom——余额探测同用）
	 * =============================================================== */

	public function test_url_audit_provider_test_endpoint_exists(): void {
		$sp = self::source( 'includes/settings/SettingsPage.php' );
		self::assertStringContainsString( "AJAX_TEST_CREDENTIALS_URL_AUDIT = 'wink_gatekeeper_test_credentials_url_audit'", $sp, '独立凭据 AJAX action 常量' );
		$handler = self::functionBody( $sp, 'ajax_test_credentials_url_audit' );
		self::assertStringContainsString( 'check_ajax_referer', $handler, 'nonce 双闸' );
		self::assertStringContainsString( 'manage_options', $handler, 'manage_options 双闸' );
		self::assertStringContainsString( 'probe_balance', $handler, 'DeepSeek 协议族同用余额探测' );
		self::assertStringContainsString( 'classify_deepseek_balance', $handler );
		self::assertStringContainsString( 'url_audit_provider_api_key', $handler, '表单覆盖（password 空串回落已存值语义）' );
		self::assertStringContainsString( 'url_audit_provider_base_url', $handler, 'base_url 跟随其配置' );
	}

	public function test_bootstrap_registers_url_audit_provider_probe_ajax(): void {
		$src = self::source( 'includes/Bootstrap.php' );
		self::assertStringContainsString( 'AJAX_TEST_CREDENTIALS_URL_AUDIT', $src, 'Bootstrap 注册独立凭据探活端点' );
	}

	public function test_provider_test_button_rendered_in_provider_rows(): void {
		$sp   = self::source( 'includes/settings/SettingsPage.php' );
		$rows = self::blockBetween( $sp, 'id="wk-url-audit-provider-rows"', '日额度' );
		self::assertStringContainsString( 'wk-url-audit-provider-test-btn', $rows, '独立凭据测试按钮在 provider rows 内' );
		self::assertStringContainsString( 'wk-url-audit-provider-test-result', $rows, '结果展示位' );
	}

	public function test_js_binds_provider_test_button(): void {
		$js = self::source( 'assets/js/admin-brand.js' );
		self::assertStringContainsString( 'wk-url-audit-provider-test-btn', $js, 'JS 绑定独立凭据测试按钮' );
		self::assertStringContainsString( 'wink_gatekeeper_test_credentials_url_audit', $js, 'JS 走新 AJAX 端点' );
	}
}
