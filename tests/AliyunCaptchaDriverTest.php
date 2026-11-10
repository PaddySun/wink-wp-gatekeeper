<?php
/**
 * AliyunCaptchaDriver 单元测试（B3：05 §1.2 阿里云验证码 2.0 全规格）。
 *
 * 覆盖：
 *   - registry 注册行（七维：frontend token_fields/api_version/scoring/privacy）；
 *   - is_configured 四字段（AccessKey 对 + SceneId【v1.5 必填化】+ prefix 前端身份标）；
 *   - verify_tri 三态（二态制无评分：VerifyResult bool 唯一判据，恒 score=1.0）；
 *   - VerifyCode 码表分档（user_retry/config_error/blocked/pass + 兜底拦截）；
 *   - 传输脊四类错误 → error 态（meta.rule=remote_<category>|contract_mismatch + raw 快照）；
 *   - 请求形态：ACS3 签名头 + form body（官方不建议 JSON）+ SceneId 服务端补传（F012 防线）；
 *   - render()：.wk-gate-embed 挂载点 + element 容器 + 密钥不出前端红线；
 *   - probe_credentials：假参实调形态（不落 required 哨兵、不计 usage）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\captcha\AliyunCaptchaDriver;
use WinkGatekeeper\Drivers\CaptchaDriverInterface;
use WinkGatekeeper\Drivers\DriverRegistry;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Http\RemoteResult;

final class AliyunCaptchaDriverTest extends TestCase {

	/* ---------- 工具 ---------- */

	private static function client_returning( $raw ): RemoteClient {
		return new RemoteClient( static function () use ( $raw ) {
			return $raw;
		}, null, static function (): void {} );
	}

	/** 阿里 OK 响应体（HTTP 200 + Success + Result 子对象）。 */
	private static function ok_body( array $result, bool $success = true ): string {
		return (string) json_encode( array_merge( array(
			'RequestId' => 'req-1',
			'Success'   => $success,
		), array( 'Result' => $result ) ) );
	}

	private static function driver( ?array $aliyun = null, ?RemoteClient $client = null ): AliyunCaptchaDriver {
		$aliyun   = $aliyun ?? array( 'access_key_id' => 'LTAIkeksi', 'access_key_secret' => 'sekreto', 'scene_id' => 'scene-9', 'prefix' => 'wkpre', 'mode' => 'embed' );
		$settings = array(
			'captcha' => array( 'aliyun' => $aliyun ),
			'copy'    => array( 'messages' => array( 'captcha_missing' => '还差一个眨眼——完成上方的验证再点提交。', 'captcha_failed' => '人机验证没有通过。' ) ),
			'brand'   => array( 'contact_email' => 'admin@example.com' ),
		);
		return new AliyunCaptchaDriver(
			$settings,
			$client ?? self::client_returning( array( 'response' => array( 'code' => 500 ), 'body' => '' ) )
		);
	}

	/* ---------- registry 注册行（05 §1.2 建议行） ---------- */

	public function test_registry_entry_shape(): void {
		$entry = AliyunCaptchaDriver::registry_entry();

		$this->assertSame( 'aliyun', $entry['id'] );
		$this->assertSame( 'captcha', $entry['type'] );
		$this->assertSame( 'usage', $entry['billing'] );
		$this->assertSame( 'click', $entry['frontend']['interaction'] );
		$this->assertSame( array( 'wk_captcha_verify_param' ), $entry['frontend']['token_fields'] );
		$this->assertFalse( $entry['scoring'], '二态制无评分——scoring=false（captcha.min_score 对本驱动隐藏）' );
		$this->assertSame( '2023-03-05', $entry['api_version'] );
		$this->assertArrayHasKey( 'privacy', $entry );
		$this->assertFalse( $entry['privacy']['cross_border'] );
		// registry 自校验：登记进 DriverRegistry 不抛（七维结构合法）。
		$registry = new DriverRegistry();
		$registry->register( $entry );
		$this->assertSame( 'aliyun', $registry->get( 'aliyun' )['id'] );
	}

	public function test_interface_surface(): void {
		$driver = self::driver();
		$this->assertInstanceOf( CaptchaDriverInterface::class, $driver );
		$this->assertSame( 'aliyun', $driver->id() );
		$this->assertSame( '阿里云验证码 2.0', $driver->label() );
		$this->assertSame( array( 'wk_captcha_verify_param' ), $driver->credentialFields() );
		$this->assertSame( 'none', $driver->disasterPolicy(), '阿里无容灾票概念' );
		$this->assertSame( 'block', $driver->errorPolicy(), '拍板：默认 block（pass_limited 留 SLA 评估）' );
	}

	public function test_failure_policy_buckets(): void {
		$policy = self::driver()->failurePolicy();
		$this->assertSame( array( 'F008', 'F015', 'F019', 'F018', 'F020' ), $policy['user_retry'] );
		$this->assertSame( array( 'F005', 'F006', 'F012', 'F022' ), $policy['config_error'] );
		$this->assertSame( array( 'F001', 'F009', 'F010', 'F011', 'F014', 'F016', 'F017', 'F024', 'F025' ), $policy['blocked'] );
		$this->assertSame( array( 'T001', 'T002', 'T005', 'T006' ), $policy['pass'] );
	}

	/* ---------- is_configured ---------- */

	public function test_is_configured_requires_all_four_fields(): void {
		$this->assertTrue( self::driver()->is_configured() );
		$this->assertFalse( self::driver( array( 'access_key_id' => '', 'access_key_secret' => 's', 'scene_id' => 'x', 'prefix' => 'p' ) )->is_configured() );
		$this->assertFalse( self::driver( array( 'access_key_id' => 'a', 'access_key_secret' => '', 'scene_id' => 'x', 'prefix' => 'p' ) )->is_configured() );
		// SceneId v1.5 必填化（服务端补传防前端篡改场景，F012 防线）。
		$this->assertFalse( self::driver( array( 'access_key_id' => 'a', 'access_key_secret' => 's', 'scene_id' => '', 'prefix' => 'p' ) )->is_configured() );
		// prefix 为前端身份标（AliyunCaptchaConfig）——缺了前端拉不起组件，同视为未配置。
		$this->assertFalse( self::driver( array( 'access_key_id' => 'a', 'access_key_secret' => 's', 'scene_id' => 'x', 'prefix' => '' ) )->is_configured() );
	}

	/* ---------- verify_tri：缺票 ---------- */

	public function test_verify_tri_missing_param_rejects_without_remote_call(): void {
		$called = false;
		$client = new RemoteClient( static function () use ( &$called ) {
			$called = true;
			return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
		}, null, static function (): void {} );

		$tri = self::driver( null, $client )->verify_tri( array( 'user_ip' => '1.2.3.4' ) );

		$this->assertFalse( $called, '缺票不发起远端调用' );
		$this->assertSame( 'reject', $tri['state'] );
		$this->assertNull( $tri['score'] );
		$this->assertSame( 'captcha_missing', $tri['meta']['rule'] );
	}

	public function test_verify_tri_unconfigured_rejects(): void {
		$tri = self::driver( array( 'access_key_id' => '', 'access_key_secret' => '', 'scene_id' => '', 'prefix' => '' ) )
			->verify_tri( array( 'captcha_verify_param' => 'abc', 'user_ip' => '1.2.3.4' ) );
		$this->assertSame( 'reject', $tri['state'] );
		$this->assertSame( 'captcha_missing', $tri['meta']['rule'] );
	}

	/* ---------- verify_tri：pass（二态恒 score=1.0） ---------- */

	public function test_verify_tri_verify_result_true_passes_with_score_one(): void {
		$driver = self::driver( null, self::client_returning( array(
			'response' => array( 'code' => 200 ),
			'body'     => self::ok_body( array( 'VerifyResult' => true, 'VerifyCode' => 'T001', 'CertifyId' => 'cert-7' ) ),
		) ) );

		$tri = $driver->verify_tri( array( 'captcha_verify_param' => 'b64param', 'user_ip' => '1.2.3.4' ) );

		$this->assertSame( 'pass', $tri['state'] );
		$this->assertSame( 1.0, $tri['score'], '二态驱动恒 score=1.0（02 §2.1 契约）' );
		$this->assertSame( 'VerifyCode=T001', $tri['meta']['rule'] );
		$this->assertSame( 'T001', $tri['meta']['VerifyCode'] );
		$this->assertSame( 'cert-7', $tri['meta']['CertifyId'] );
		$this->assertSame( 'req-1', $tri['meta']['RequestId'] );
	}

	public function test_verify_tri_t002_disaster_pass_code_still_pass(): void {
		// T002 QPS 限流容灾自动放行——VerifyResult bool 唯一判据（二态制，无天御 trerror 前缀逻辑）。
		$tri = self::driver( null, self::client_returning( array(
			'response' => array( 'code' => 200 ),
			'body'     => self::ok_body( array( 'VerifyResult' => true, 'VerifyCode' => 'T002', 'CertifyId' => '' ) ),
		) ) )->verify_tri( array( 'captcha_verify_param' => 'b64', 'user_ip' => '1.2.3.4' ) );
		$this->assertSame( 'pass', $tri['state'] );
	}

	/* ---------- verify_tri：reject 分档 ---------- */

	public function test_verify_tri_false_maps_user_retry_hint(): void {
		foreach ( array( 'F008', 'F015', 'F019', 'F018', 'F020' ) as $code ) {
			$tri = self::driver( null, self::client_returning( array(
				'response' => array( 'code' => 200 ),
				'body'     => self::ok_body( array( 'VerifyResult' => false, 'VerifyCode' => $code, 'CertifyId' => 'c' ) ),
			) ) )->verify_tri( array( 'captcha_verify_param' => 'b64', 'user_ip' => '1.2.3.4' ) );
			$this->assertSame( 'reject', $tri['state'], "code {$code}" );
			$this->assertNull( $tri['score'] );
			$this->assertStringContainsString( '刷新', $tri['message'], "code {$code} user_retry 档引导刷新" );
			$this->assertSame( 'VerifyCode=' . $code, $tri['meta']['rule'] );
		}
	}

	public function test_verify_tri_false_maps_config_error_hint(): void {
		foreach ( array( 'F005', 'F006', 'F012', 'F022' ) as $code ) {
			$tri = self::driver( null, self::client_returning( array(
				'response' => array( 'code' => 200 ),
				'body'     => self::ok_body( array( 'VerifyResult' => false, 'VerifyCode' => $code, 'CertifyId' => 'c' ) ),
			) ) )->verify_tri( array( 'captcha_verify_param' => 'b64', 'user_ip' => '1.2.3.4' ) );
			$this->assertSame( 'reject', $tri['state'], "code {$code}" );
			$this->assertStringContainsString( '配置', $tri['message'], "code {$code} config_error 档文案" );
		}
	}

	public function test_verify_tri_false_maps_blocked_hint(): void {
		foreach ( array( 'F001', 'F009', 'F010', 'F011', 'F014', 'F016', 'F017', 'F024', 'F025', 'F004' ) as $code ) {
			$tri = self::driver( null, self::client_returning( array(
				'response' => array( 'code' => 200 ),
				'body'     => self::ok_body( array( 'VerifyResult' => false, 'VerifyCode' => $code, 'CertifyId' => 'c' ) ),
			) ) )->verify_tri( array( 'captcha_verify_param' => 'b64', 'user_ip' => '1.2.3.4' ) );
			$this->assertSame( 'reject', $tri['state'], "code {$code}" );
			$this->assertStringContainsString( '拦截', $tri['message'], "code {$code} blocked 档文案" );
		}
	}

	public function test_verify_tri_unknown_code_fails_closed(): void {
		// 未知码/参数类（F002/F003/F013 未入分档）兜底拦截（fail-closed）。
		foreach ( array( 'F002', 'F003', 'F013', 'F023', 'X999' ) as $code ) {
			$tri = self::driver( null, self::client_returning( array(
				'response' => array( 'code' => 200 ),
				'body'     => self::ok_body( array( 'VerifyResult' => false, 'VerifyCode' => $code, 'CertifyId' => '' ) ),
			) ) )->verify_tri( array( 'captcha_verify_param' => 'b64', 'user_ip' => '1.2.3.4' ) );
			$this->assertSame( 'reject', $tri['state'], "code {$code} 兜底拦截" );
		}
	}

	/* ---------- verify_tri：传输脊四类错误 → error 态 ---------- */

	public function test_verify_tri_transport_error_returns_error_state(): void {
		$driver = self::driver( null, self::client_returning( array( 'response' => array( 'code' => 503 ), 'body' => '' ) ) );
		$tri    = $driver->verify_tri( array( 'captcha_verify_param' => 'b64', 'user_ip' => '1.2.3.4' ) );

		$this->assertSame( 'error', $tri['state'] );
		$this->assertNull( $tri['score'] );
		$this->assertSame( 'remote_transport', $tri['meta']['rule'] );
		$this->assertSame( 'transport', $tri['meta']['category'] );
		$this->assertArrayHasKey( 'raw', $tri['meta'], 'raw 快照必带（F44）' );
		$this->assertStringContainsString( '稍后再试', $tri['message'] );
	}

	public function test_verify_tri_semantic_error_returns_error_state(): void {
		// HTTP 403 Forbidden.NoPermission（RAM 未授权——L0 实测形态）。
		$driver = self::driver( null, self::client_returning( array(
			'response' => array( 'code' => 403 ),
			'body'     => (string) json_encode( array(
				'RequestId'          => 'req-2',
				'Code'               => 'Forbidden.NoPermission',
				'Message'            => 'The specified resource is forbidden.',
				'AccessDeniedDetail' => array( 'AuthAction' => 'yundun-afs:VerifyCaptcha' ),
			) ),
		) ) );
		$tri = $driver->verify_tri( array( 'captcha_verify_param' => 'b64', 'user_ip' => '1.2.3.4' ) );

		$this->assertSame( 'error', $tri['state'] );
		$this->assertSame( 'remote_semantic', $tri['meta']['rule'] );
		$this->assertStringContainsString( '配置', $tri['message'] );
		$this->assertArrayHasKey( 'raw', $tri['meta'] );
	}

	public function test_verify_tri_contract_error_records_mismatch(): void {
		// 200 + 缺 Result.VerifyResult → contract_mismatch（R6/R9 厂商改版哨兵）。
		$driver = self::driver( null, self::client_returning( array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( array( 'RequestId' => 'r', 'Success' => true ) ),
		) ) );
		$tri = $driver->verify_tri( array( 'captcha_verify_param' => 'b64', 'user_ip' => '1.2.3.4' ) );

		$this->assertSame( 'error', $tri['state'] );
		$this->assertSame( 'contract_mismatch', $tri['meta']['rule'] );
	}

	/* ---------- 请求形态：ACS3 签名 + form body + SceneId 服务端补传 ---------- */

	public function test_verify_tri_builds_acs3_form_post(): void {
		$captured    = null;
		$usage_calls = array();
		$client      = new RemoteClient( static function ( string $url, array $args ) use ( &$captured ) {
			$captured = array( $url, $args );
			return array( 'response' => array( 'code' => 200 ), 'body' => '{"Result":{"VerifyResult":true,"VerifyCode":"T001","CertifyId":"c"}}' );
		}, static function ( string $usage_key ) use ( &$usage_calls ): void {
			$usage_calls[] = $usage_key;
		}, static function (): void {} );

		self::driver( null, $client )->verify_tri( array( 'captcha_verify_param' => 'b64TOKEN', 'user_ip' => '203.0.113.9' ) );

		list( $url, $args ) = $captured;
		$this->assertSame( 'https://captcha.cn-shanghai.aliyuncs.com/', $url, 'region=cn 恒上海端点（sgp 变体不启用）' );
		$this->assertSame( 'VerifyIntelligentCaptcha', $args['headers']['x-acs-action'] );
		$this->assertSame( '2023-03-05', $args['headers']['x-acs-version'] );
		$this->assertSame( 'captcha.cn-shanghai.aliyuncs.com', $args['headers']['host'] );
		$this->assertStringStartsWith( 'ACS3-HMAC-SHA256 Credential=LTAIkeksi,', $args['headers']['authorization'] );
		$this->assertSame( 'application/x-www-form-urlencoded', $args['headers']['content-type'] );

		// form body（官方不建议 JSON）；参数原文透传禁改 + SceneId 服务端补传（F012 防线）。
		parse_str( (string) $args['body'], $form );
		$this->assertSame( 'b64TOKEN', $form['CaptchaVerifyParam'] );
		$this->assertSame( 'scene-9', $form['SceneId'] );

		// 计量：核验调用计入 usage（04 §6）；契约哨兵字段由 contract 测试钉（缺失→contract_mismatch）。
		$this->assertSame( array( 'aliyun' ), $usage_calls );
	}

	public function test_verify_tri_trims_and_rejects_whitespace_only_param(): void {
		$tri = self::driver()->verify_tri( array( 'captcha_verify_param' => '   ', 'user_ip' => '1.2.3.4' ) );
		$this->assertSame( 'reject', $tri['state'] );
		$this->assertSame( 'captcha_missing', $tri['meta']['rule'] );
	}

	/* ---------- probe_credentials（Tab② 测试凭据） ---------- */

	public function test_probe_credentials_sends_fake_param_without_usage(): void {
		$captured    = null;
		$usage_calls = array();
		$client      = new RemoteClient( static function ( string $url, array $args ) use ( &$captured ) {
			$captured = array( $url, $args );
			return array( 'response' => array( 'code' => 200 ), 'body' => '{"Result":{"VerifyResult":false,"VerifyCode":"F003","CertifyId":""}}' );
		}, static function ( string $usage_key ) use ( &$usage_calls ): void {
			$usage_calls[] = $usage_key;
		}, static function (): void {} );

		$result = self::driver( null, $client )->probe_credentials();

		list( $url, $args ) = $captured;
		$this->assertSame( 'https://captcha.cn-shanghai.aliyuncs.com/', $url );
		parse_str( (string) $args['body'], $form );
		$this->assertSame( AliyunCaptchaDriver::PROBE_PARAM, $form['CaptchaVerifyParam'], '假参实调（L0「全参假票」手法）' );
		$this->assertSame( 'scene-9', $form['SceneId'] );
		$this->assertSame( array(), $usage_calls, '探测调用不计 usage（Tab② 现场诊断，非业务核验）' );

		$this->assertInstanceOf( RemoteResult::class, $result );
		$this->assertTrue( $result->is_ok() );
	}

	/* ---------- render（embed 挂载点 + 密钥红线） ---------- */

	public function test_render_embed_outputs_mount_and_element_container(): void {
		$html = self::driver()->render( array( 'gate' => 'comment', 'stub' => 'STUBSIG' ) );

		$this->assertStringContainsString( 'data-driver="aliyun"', $html );
		$this->assertStringContainsString( 'data-interaction="click"', $html );
		$this->assertStringContainsString( 'data-mode="embed"', $html );
		$this->assertStringContainsString( 'data-token-fields="wk_captcha_verify_param"', $html );
		$this->assertStringContainsString( 'data-scene-id="scene-9"', $html );
		$this->assertStringContainsString( 'data-prefix="wkpre"', $html );
		$this->assertStringContainsString( 'name="wk_stub" value="STUBSIG"', $html );
		$this->assertStringContainsString( 'name="wk_captcha_verify_param"', $html );
		$this->assertStringContainsString( 'class="wk-gate-embed"', $html );
		$this->assertStringContainsString( 'id="wk-captcha-element-comment"', $html );
		$this->assertStringContainsString( 'wk-captcha-btn', $html );
	}

	public function test_render_popup_mode_has_no_mount(): void {
		$html = self::driver( array( 'access_key_id' => 'a', 'access_key_secret' => 's', 'scene_id' => 'x', 'prefix' => 'p', 'mode' => 'popup' ) )
			->render( array( 'gate' => 'login', 'stub' => 'S' ) );
		$this->assertStringContainsString( 'data-mode="popup"', $html );
		$this->assertStringNotContainsString( 'wk-gate-embed', $html );
		$this->assertStringNotContainsString( 'wk-captcha-element', $html );
	}

	public function test_render_never_leaks_secrets(): void {
		$html = self::driver()->render( array( 'gate' => 'comment', 'stub' => 'S' ) );
		$this->assertStringNotContainsString( 'LTAIkeksi', $html, 'AccessKeyId 绝不出前端' );
		$this->assertStringNotContainsString( 'sekreto', $html, 'AccessKeySecret 绝不出前端' );
	}
}
