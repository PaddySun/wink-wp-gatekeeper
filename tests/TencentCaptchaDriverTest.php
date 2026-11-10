<?php
/**
 * TencentCaptchaDriver 单元测试（05 §1.1 天御全规格 + 02 §2.5 传输脊接入）。
 *
 * 覆盖：
 *   - TC3-HMAC-SHA256 签名金测试（腾讯云官方文档示例值逐段复现——手档《签名方法 v3》
 *     的 payload 哈希/规范请求哈希/派生密钥/最终签名全部可由字面密钥复算，做金测试）；
 *   - CaptchaCode 错误映射矩阵（1 过/7/8/9/15 刷新重试/16/100 配置错误/trerror 容灾票按通过/兜底拦截）；
 *   - verify() 编排：缺票/传输脊四类错误/成功/评分入 meta；
 *   - render()：容器结构 + frontend 元数据 + 密钥不出前端红线；
 *   - registry_entry() 六维元数据。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\captcha\TencentCaptchaDriver;
use WinkGatekeeper\Drivers\DriverRegistry;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Http\RemoteResult;

final class TencentCaptchaDriverTest extends TestCase {

	/* ---------- TC3-HMAC-SHA256 金测试（官方文档示例值） ---------- */

	/**
	 * 官方《签名方法 v3》示例（https://cloud.tencent.com/document/product/213/30654）：
	 * SecretId/SecretKey 以文档字面占位串（AKID+32 星/32 星）代入——文档中间值即由此串算出。
	 */
	private const DOC_SECRET_ID  = 'AKID********************************';
	private const DOC_SECRET_KEY = '********************************';

	private const DOC_PAYLOAD  = '{"Limit": 1, "Filters": [{"Values": ["\\u672a\\u547d\\u540d"], "Name": "instance-name"}]}';
	private const DOC_HOST     = 'cvm.tencentcloudapi.com';
	private const DOC_ACTION   = 'DescribeInstances';
	private const DOC_VERSION  = '2017-03-12';
	private const DOC_TS       = 1551113065;

	public function test_golden_payload_hash(): void {
		$this->assertSame(
			'35e9c5b0e3ae67532d3c9f17ead6c90222632e5b1ff7f6e89887f1398934f064',
			hash( 'sha256', self::DOC_PAYLOAD )
		);
	}

	public function test_golden_canonical_request_hash(): void {
		$canonical = TencentCaptchaDriver::tc3_canonical_request(
			self::DOC_HOST, self::DOC_ACTION, self::DOC_PAYLOAD
		);
		$this->assertSame(
			'7019a55be8395899b900fb5564e4200d984910f34794a27cb3fb7d10ff6a1e84',
			hash( 'sha256', $canonical )
		);
	}

	public function test_golden_canonical_request_string_shape(): void {
		$canonical = TencentCaptchaDriver::tc3_canonical_request(
			self::DOC_HOST, self::DOC_ACTION, self::DOC_PAYLOAD
		);
		$this->assertSame(
			"POST\n/\n\n"
			. "content-type:application/json; charset=utf-8\n"
			. 'host:' . self::DOC_HOST . "\n"
			. 'x-tc-action:' . strtolower( self::DOC_ACTION ) . "\n"
			. "\n"
			. "content-type;host;x-tc-action\n"
			. hash( 'sha256', self::DOC_PAYLOAD ),
			$canonical
		);
	}

	public function test_golden_authorization_full_string(): void {
		$result = TencentCaptchaDriver::tc3_authorization(
			self::DOC_SECRET_ID,
			self::DOC_SECRET_KEY,
			self::DOC_HOST,
			self::DOC_ACTION,
			self::DOC_VERSION,
			self::DOC_TS,
			self::DOC_PAYLOAD
		);

		$this->assertSame(
			'TC3-HMAC-SHA256 Credential=AKID********************************/2019-02-25/cvm/tc3_request, SignedHeaders=content-type;host;x-tc-action, Signature=10b1a37a7301a02ca19a647ad722d5e43b4b3cff309d421d85b46093f6ab6c4f',
			$result['Authorization']
		);
	}

	public function test_golden_headers_set_shape(): void {
		$result = TencentCaptchaDriver::tc3_authorization(
			self::DOC_SECRET_ID,
			self::DOC_SECRET_KEY,
			self::DOC_HOST,
			self::DOC_ACTION,
			self::DOC_VERSION,
			self::DOC_TS,
			self::DOC_PAYLOAD
		);

		$this->assertSame( 'application/json; charset=utf-8', $result['headers']['Content-Type'] );
		$this->assertSame( self::DOC_HOST, $result['headers']['Host'] );
		$this->assertSame( self::DOC_ACTION, $result['headers']['X-TC-Action'] );
		$this->assertSame( (string) self::DOC_TS, $result['headers']['X-TC-Timestamp'] );
		$this->assertSame( self::DOC_VERSION, $result['headers']['X-TC-Version'] );
	}

	/* ---------- 工具 ---------- */

	private static function client_returning( $raw ): RemoteClient {
		return new RemoteClient( static function () use ( $raw ) {
			return $raw;
		}, null, static function (): void {} );
	}

	private static function ok_body( array $response ): string {
		return (string) json_encode( array( 'Response' => $response ) );
	}

	private static function driver( ?array $tencent = null, ?RemoteClient $client = null ): TencentCaptchaDriver {
		$tencent  = $tencent ?? array( 'app_id' => 199999164, 'secret_id' => 'AKIDx', 'secret_key' => 'sk', 'app_secret_key' => 'ASK' );
		$settings = array(
			'captcha' => array( 'tencent' => $tencent ),
			'copy'    => array( 'messages' => array( 'captcha_missing' => '还差一个眨眼——完成上方的验证再点提交。' ) ),
			'brand'   => array( 'contact_email' => 'admin@example.com' ),
		);
		return new TencentCaptchaDriver(
			$settings,
			$client ?? self::client_returning( array( 'response' => array( 'code' => 500 ), 'body' => '' ) )
		);
	}

	/* ---------- is_configured ---------- */

	public function test_is_configured_requires_all_four_fields(): void {
		$this->assertTrue( self::driver()->is_configured() );

		$this->assertFalse( self::driver( array( 'app_id' => 0, 'secret_id' => 'AKIDx', 'secret_key' => 'sk', 'app_secret_key' => 'ASK' ) )->is_configured() );
		$this->assertFalse( self::driver( array( 'app_id' => 1, 'secret_id' => '', 'secret_key' => 'sk', 'app_secret_key' => 'ASK' ) )->is_configured() );
		$this->assertFalse( self::driver( array( 'app_id' => 1, 'secret_id' => 'AKIDx', 'secret_key' => '', 'app_secret_key' => 'ASK' ) )->is_configured() );
		// L0 冒烟在线定论：body 必填 AppSecretKey（05 §1.1 实现注意④），缺第四字段 = 未配置。
		$this->assertFalse( self::driver( array( 'app_id' => 1, 'secret_id' => 'AKIDx', 'secret_key' => 'sk', 'app_secret_key' => '' ) )->is_configured() );
	}

	/* ---------- CaptchaCode 映射矩阵（纯函数） ---------- */

	public function test_code_1_maps_pass(): void {
		$mapped = TencentCaptchaDriver::verdict_for( 1, 'tok_normal' );
		$this->assertTrue( $mapped['pass'] );
	}

	public function test_codes_7_8_9_15_map_refresh_retry_failure(): void {
		foreach ( array( 7, 8, 9, 15 ) as $code ) {
			$mapped = TencentCaptchaDriver::verdict_for( $code, 'tok_normal' );
			$this->assertFalse( $mapped['pass'], "code {$code} 应拦截" );
			$this->assertSame( 'captcha_failed', $mapped['copy_key'], "code {$code}" );
			$this->assertNotSame( '', $mapped['hint'] );
		}
	}

	public function test_codes_16_100_map_config_error(): void {
		foreach ( array( 16, 100 ) as $code ) {
			$mapped = TencentCaptchaDriver::verdict_for( $code, 'tok_normal' );
			$this->assertFalse( $mapped['pass'] );
			$this->assertSame( 'config', $mapped['hint_kind'] );
		}
	}

	public function test_code_21_with_trerror_prefix_ticket_passes(): void {
		// 官方容灾语义：弱网用户自动容灾票（trerror 前缀）按通过处理——拦了就是误杀真人。
		$mapped = TencentCaptchaDriver::verdict_for( 21, 'trerror03XaCUZPAlPdIMqv17yvcf' );
		$this->assertTrue( $mapped['pass'] );
	}

	public function test_code_21_without_trerror_prefix_fails(): void {
		$mapped = TencentCaptchaDriver::verdict_for( 21, 'tok_risky' );
		$this->assertFalse( $mapped['pass'] );
		$this->assertSame( 'captcha_failed', $mapped['copy_key'] );
	}

	public function test_unknown_or_zero_code_fails_closed(): void {
		// 手档示例响应出现 CaptchaCode=0（not valid）——未知码兜底拦截（fail-closed）。
		foreach ( array( 0, 3, 99, -1 ) as $code ) {
			$this->assertFalse( TencentCaptchaDriver::verdict_for( $code, 'tok' )['pass'], "code {$code} 应兜底拦截" );
		}
	}

	public function test_rule_hit_carries_captcha_code(): void {
		$this->assertSame( 'CaptchaCode=8', TencentCaptchaDriver::verdict_for( 8, 'tok' )['rule'] );
	}

	/**
	 * M2 反馈2 T3 A-lite：8（票过期 5min）/9（票已用）提示改为「返回重提免重验」引导
	 * （验证短缓存窗口内返回重提不再走到本错误页；走到即超窗/换表单，附 10 分钟语义）。
	 */
	public function test_codes_8_9_hint_guides_back_and_resubmit(): void {
		foreach ( array( 8, 9 ) as $code ) {
			$hint = TencentCaptchaDriver::verdict_for( $code, 'tok' )['hint'];
			$this->assertStringContainsString( '验证已生效过一次', $hint, "code {$code} A-lite 返回重提引导" );
			$this->assertStringContainsString( '无需刷新', $hint );
			$this->assertStringContainsString( '10 分钟', $hint );
			$this->assertStringContainsString( '重新点击验证按钮', $hint, "code {$code} 超窗分支引导" );
		}
		// 其他重试码（7/15）保持刷新重试口径不变。
		$this->assertStringContainsString( '刷新页面再试一次', TencentCaptchaDriver::verdict_for( 7, 'tok' )['hint'] );
	}

	/* ---------- verify()：缺票 ---------- */

	public function test_verify_missing_ticket_returns_missing_copy(): void {
		$driver = self::driver();
		$this->assertSame( '还差一个眨眼——完成上方的验证再点提交。', $driver->verify( array( 'randstr' => '@Vki', 'user_ip' => '1.2.3.4' ) ) );
	}

	public function test_verify_missing_randstr_returns_missing_copy(): void {
		$driver = self::driver();
		$this->assertSame( '还差一个眨眼——完成上方的验证再点提交。', $driver->verify( array( 'ticket' => 'tok', 'user_ip' => '1.2.3.4' ) ) );
	}

	/* ---------- verify()：请求形态（隔离 $_POST 语义：上下文注入，不读全局） ---------- */

	public function test_verify_builds_describe_captcha_result_request(): void {
		$captured = null;
		$client  = new RemoteClient( static function ( string $url, array $args ) use ( &$captured ) {
			$captured = array( $url, $args );
			return array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'CaptchaCode' => 1, 'CaptchaMsg' => 'OK' ) ) );
		}, null, static function (): void {} );

		self::driver( null, $client )->verify( array(
			'ticket'  => 'tok123',
			'randstr' => '@Vki',
			'user_ip' => '203.0.113.7',
		) );

		list( $url, $args ) = $captured;
		$this->assertSame( 'https://captcha.tencentcloudapi.com/', $url );
		$this->assertSame( 'DescribeCaptchaResult', $args['headers']['X-TC-Action'] );
		$this->assertSame( '2019-07-22', $args['headers']['X-TC-Version'] );
		$this->assertSame( 'captcha.tencentcloudapi.com', $args['headers']['Host'] );
		$this->assertStringContainsString( 'TC3-HMAC-SHA256 ', $args['headers']['Authorization'] );

		$body = json_decode( $args['body'], true );
		$this->assertSame( 9, $body['CaptchaType'] );
		$this->assertSame( 'tok123', $body['Ticket'] );
		$this->assertSame( '@Vki', $body['Randstr'] );
		$this->assertSame( '203.0.113.7', $body['UserIp'] );
		$this->assertSame( 199999164, $body['CaptchaAppId'] );
		$this->assertSame( 1, $body['NeedGetCaptchaTime'] );
		// L0 冒烟在线定论：body 必填 AppSecretKey（MissingParameter:'AppSecretKey' 实测）。
		$this->assertSame( 'ASK', $body['AppSecretKey'] );
	}

	/* ---------- verify()：传输脊四类错误 → 一律拦截（02 §5 降级矩阵 captcha 行） ---------- */

	public function test_verify_transport_terminal_error_intercepts(): void {
		$driver = self::driver( null, self::client_returning( array( 'response' => array( 'code' => 503 ), 'body' => '' ) ) );
		$message = $driver->verify( array( 'ticket' => 'tok', 'randstr' => 'r', 'user_ip' => '1.2.3.4' ) );
		$this->assertIsString( $message );
		$this->assertStringContainsString( '稍后再试', $message );
	}

	public function test_verify_semantic_error_intercepts_with_config_copy(): void {
		$driver = self::driver( null, self::client_returning( array( 'response' => array( 'code' => 401 ), 'body' => '' ) ) );
		$message = $driver->verify( array( 'ticket' => 'tok', 'randstr' => 'r', 'user_ip' => '1.2.3.4' ) );
		$this->assertIsString( $message );
		$this->assertStringContainsString( '配置', $message );
	}

	public function test_verify_contract_error_intercepts_and_records_mismatch(): void {
		// 200 + 缺 Response.CaptchaCode → contract_mismatch（R6/R9 厂商改版哨兵）。
		$driver  = self::driver( null, self::client_returning( array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"Response":{"RequestId":"r1"}}',
		) ) );
		$message = $driver->verify( array( 'ticket' => 'tok', 'randstr' => 'r', 'user_ip' => '1.2.3.4' ) );

		$this->assertIsString( $message );
		$this->assertSame( 'contract_mismatch', $driver->last_verify_meta()['rule'] );
	}

	/* ---------- verify()：成功与失败判定 ---------- */

	public function test_verify_pass_returns_null_and_collects_meta(): void {
		$driver = self::driver( null, self::client_returning( array(
			'response' => array( 'code' => 200 ),
			'body'     => self::ok_body( array(
				'CaptchaCode'     => 1,
				'CaptchaMsg'      => 'OK',
				'Score'           => 10,
				'GetCaptchaTime'  => 1731000000,
			) ),
		) ) );

		$this->assertNull( $driver->verify( array( 'ticket' => 'tok', 'randstr' => 'r', 'user_ip' => '1.2.3.4' ) ) );

		$meta = $driver->last_verify_meta();
		$this->assertSame( 1, $meta['CaptchaCode'] );
		$this->assertSame( 10, $meta['Score'] );           // 附加信号 Score 存 meta（05 §1.1）。
		$this->assertSame( 1731000000, $meta['GetCaptchaTime'] );
	}

	public function test_verify_trerror_fallback_ticket_passes(): void {
		$driver = self::driver( null, self::client_returning( array(
			'response' => array( 'code' => 200 ),
			'body'     => self::ok_body( array( 'CaptchaCode' => 21, 'CaptchaMsg' => 'diff' ) ),
		) ) );

		$this->assertNull( $driver->verify( array( 'ticket' => 'trerror03Xa', 'randstr' => 'r', 'user_ip' => '1.2.3.4' ) ) );
	}

	public function test_verify_code_8_returns_refresh_hint_message(): void {
		$driver = self::driver( null, self::client_returning( array(
			'response' => array( 'code' => 200 ),
			'body'     => self::ok_body( array( 'CaptchaCode' => 8, 'CaptchaMsg' => 'ticket expired' ) ),
		) ) );
		$message = $driver->verify( array( 'ticket' => 'tok', 'randstr' => 'r', 'user_ip' => '1.2.3.4' ) );
		$this->assertIsString( $message );
		$this->assertStringContainsString( '刷新', $message );
		$this->assertSame( 'CaptchaCode=8', $driver->last_verify_meta()['rule'] );
	}

	public function test_verify_unconfigured_short_circuits_to_missing(): void {
		// 未配置驱动：不发起远端调用（02 §2.1 未配置行为由闸整体跳过；驱动层兜底为 captcha_missing）。
		$called   = false;
		$client   = new RemoteClient( static function () use ( &$called ) {
			$called = true;
			return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
		}, null, static function (): void {} );
		$driver   = self::driver( array( 'app_id' => 0, 'secret_id' => '', 'secret_key' => '' ), $client );

		$this->assertSame( '还差一个眨眼——完成上方的验证再点提交。', $driver->verify( array( 'ticket' => 'tok', 'randstr' => 'r', 'user_ip' => '1.2.3.4' ) ) );
		$this->assertFalse( $called );
	}

	/* ---------- render()：结构与红线 ---------- */

	public function test_render_outputs_container_with_frontend_metadata(): void {
		$html = self::driver()->render( array(
			'gate'       => 'comment',
			'context_id' => '42',
			'stub'       => 'c3R1Yg.aGFzaA',
		) );

		$this->assertStringContainsString( 'class="wk-gate"', $html );
		$this->assertStringContainsString( 'data-gate="comment"', $html );
		$this->assertStringContainsString( 'data-driver="tencent"', $html );
		$this->assertStringContainsString( 'data-interaction="click"', $html );
		$this->assertStringContainsString( 'data-token-fields="wk_captcha_ticket,wk_captcha_randstr"', $html );
		$this->assertStringContainsString( 'name="wk_stub" value="c3R1Yg.aGFzaA"', $html );
		$this->assertStringContainsString( 'wk-captcha-btn', $html );
		$this->assertStringContainsString( 'aria-live="polite"', $html );
		$this->assertStringContainsString( '<noscript>', $html );
		// CaptchaAppId 公开级：渲染输出允许携带。
		$this->assertStringContainsString( '199999164', $html );
	}

	/**
	 * 【v1.4·R3/05 §1.1】embed gate 白名单：comment|login|register|lostpassword → embed
	 * 并尾随 .wk-gate-embed 挂载点；其余 gate 维持 popup（无挂载点 div）。
	 */
	public function test_render_gate_whitelist_maps_embed_with_mount_point(): void {
		$driver = self::driver();

		foreach ( array( 'comment', 'login', 'register', 'lostpassword' ) as $gate ) {
			$html = $driver->render( array( 'gate' => $gate, 'context_id' => $gate, 'stub' => 's.h' ) );
			$this->assertStringContainsString( 'data-mode="embed"', $html, "gate={$gate} → embed" );
			$this->assertStringContainsString( '<div class="wk-gate-embed" hidden></div>', $html, "gate={$gate} 尾随嵌入挂载点" );
		}

		$popup = $driver->render( array( 'gate' => 'signup', 'context_id' => 'signup', 'stub' => 's.h' ) );
		$this->assertStringContainsString( 'data-mode="popup"', $popup, '白名单外 gate 维持 popup' );
		$this->assertStringNotContainsString( 'wk-gate-embed', $popup, 'popup 形态无挂载点 div' );
	}

	public function test_render_never_leaks_secrets(): void {
		// 红线：密钥不出前端——render 输出只含 CaptchaAppId（公开级）。
		$html = self::driver( array( 'app_id' => 199999164, 'secret_id' => 'AKID_SUPER_SECRET_ID', 'secret_key' => 'APP_SECRET_KEY_VALUE', 'app_secret_key' => 'BODY_APP_SECRET_VALUE' ) )
			->render( array( 'gate' => 'comment', 'context_id' => '42', 'stub' => 'x.y' ) );

		$this->assertStringNotContainsString( 'AKID_SUPER_SECRET_ID', $html );
		$this->assertStringNotContainsString( 'APP_SECRET_KEY_VALUE', $html );
		$this->assertStringNotContainsString( 'BODY_APP_SECRET_VALUE', $html );
	}

	/* ---------- 注册表元数据（05 §4） ---------- */

	public function test_registry_entry_passes_validation_and_declares_frontend(): void {
		$registry = new DriverRegistry();
		$registry->register( TencentCaptchaDriver::registry_entry() );

		$entry = $registry->get( 'tencent' );
		$this->assertNotNull( $entry );
		$this->assertSame( 'captcha', $entry['type'] );
		$this->assertSame( 'click', $entry['frontend']['interaction'] );
		$this->assertSame( array( 'wk_captcha_ticket', 'wk_captcha_randstr' ), $entry['frontend']['token_fields'] );
		$this->assertSame( 'captcha-2019-07-22', $entry['api_version'] );
		$this->assertSame( 'usage', $entry['billing'] );
		$this->assertArrayHasKey( 'secret_id', $entry['settings'] );
		$this->assertArrayHasKey( 'app_id', $entry['settings'] );
		$this->assertArrayHasKey( 'secret_key', $entry['settings'] );
		$this->assertArrayHasKey( 'app_secret_key', $entry['settings'] );
	}
}
