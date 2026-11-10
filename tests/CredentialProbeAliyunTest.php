<?php
/**
 * CredentialProbe 阿里分支测试（B3：DESIGN-D2 §1.4 / 05 §1.2 凭据自检三态）。
 *
 * 判定法（L0「全参假票」手法——假 CaptchaVerifyParam 实调）：
 *   - 绿：HTTP 200 + VerifyCode=F002/F003（网关鉴权+AK+RAM 全通，业务层拒绝假参数）；
 *   - 黄：credentialFields 必填缺（AccessKey 对/SceneId/prefix）——不发起调用；
 *   - 红：InvalidAccessKeyId* 与 SignatureDoesNotMatch（AK/签名错）或 403 Forbidden.NoPermission
 *     （RAM 未授权——文案指明补授 AliyunYundunAFSFullAccess + AuthAction）或 403
 *     Forbidden.AccountAccessDenied（未开通/欠费）；
 *   - 灰：传输终态/非预期形态——给原文与排查方向，不误报三态。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Settings\CredentialProbe;

final class CredentialProbeAliyunTest extends TestCase {

	private static function ok_result( string $body ): RemoteResult {
		// 模拟 HTTP 200 传输脊结果（classify 对 2xx body 判定）。
		return RemoteResult::classify( array(
			'response' => array( 'code' => 200 ),
			'body'     => $body,
		) );
	}

	private static function http_error_result( int $status, string $body ): RemoteResult {
		return RemoteResult::classify( array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
		) );
	}

	/* ---------- 绿（假参实调业务层拒绝） ---------- */

	public function test_green_http_200_with_f003(): void {
		$state = CredentialProbe::classify_aliyun( self::ok_result(
			'{"RequestId":"r1","Success":true,"Result":{"VerifyResult":false,"VerifyCode":"F003","CertifyId":""}}'
		) );
		$this->assertSame( 'green', $state['state'] );
		$this->assertSame( 'VerifyCode=F003', $state['code'] );
		$this->assertStringContainsString( '凭据可用', $state['message'] );
	}

	public function test_green_http_200_with_f002(): void {
		$state = CredentialProbe::classify_aliyun( self::ok_result(
			'{"Result":{"VerifyResult":false,"VerifyCode":"F002","CertifyId":""}}'
		) );
		$this->assertSame( 'green', $state['state'] );
	}

	public function test_green_with_top_level_code_success_200(): void {
		// 真机定论（2026-11-11 wpclean 实调）：HTTP 200 OK body 顶层带 Code='Success'——
		// 绿态判定不得被顶层 Code 误导向灰态（Code 仅在非 2xx 终态 consulted）。
		$state = CredentialProbe::classify_aliyun( self::ok_result(
			'{"RequestId":"r1","Message":"success","Code":"Success","Success":true,'
			. '"Result":{"VerifyCode":"F003","VerifyResult":false}}'
		) );
		$this->assertSame( 'green', $state['state'] );
		$this->assertSame( 'VerifyCode=F003', $state['code'] );
	}

	public function test_http_200_t_code_is_not_green(): void {
		// VerifyCode=T 系（真通过）在假参探测中属非预期形态——灰态给排查方向。
		$state = CredentialProbe::classify_aliyun( self::ok_result(
			'{"Result":{"VerifyResult":true,"VerifyCode":"T001","CertifyId":"c"}}'
		) );
		$this->assertSame( 'gray', $state['state'] );
		$this->assertStringContainsString( 'T001', $state['message'] );
	}

	/* ---------- 红（AK/签名/RAM/开通态） ---------- */

	public function test_red_invalid_access_key_id(): void {
		$state = CredentialProbe::classify_aliyun( self::http_error_result( 400,
			'{"RequestId":"r","Code":"InvalidAccessKeyId.NotFound","Message":"The AK id does not exist."}'
		) );
		$this->assertSame( 'red', $state['state'] );
		$this->assertSame( 'InvalidAccessKeyId.NotFound', $state['code'] );
		$this->assertStringContainsString( 'AccessKey', $state['message'] );
	}

	public function test_red_signature_does_not_match(): void {
		$state = CredentialProbe::classify_aliyun( self::http_error_result( 400,
			'{"Code":"SignatureDoesNotMatch","Message":"signature mismatch"}'
		) );
		$this->assertSame( 'red', $state['state'] );
	}

	public function test_red_no_permission_mentions_auth_action(): void {
		// 403 Forbidden.NoPermission（L0 实测：RAM 未授权，AccessDeniedDetail.AuthAction=yundun-afs:VerifyCaptcha）。
		$state = CredentialProbe::classify_aliyun( self::http_error_result( 403,
			'{"RequestId":"r","Code":"Forbidden.NoPermission","Message":"forbidden",'
			. '"AccessDeniedDetail":{"AuthAction":"yundun-afs:VerifyCaptcha"}}'
		) );
		$this->assertSame( 'red', $state['state'] );
		$this->assertStringContainsString( 'AliyunYundunAFSFullAccess', $state['message'], '红态文案指明需补授的权限策略' );
		$this->assertStringContainsString( 'yundun-afs:VerifyCaptcha', $state['message'], 'AuthAction 一并展示' );
	}

	public function test_red_account_access_denied(): void {
		// 403 Forbidden.AccountAccessDenied（未开通/欠费）。
		$state = CredentialProbe::classify_aliyun( self::http_error_result( 403,
			'{"Code":"Forbidden.AccountAccessDenied","Message":"not activated or in arrears"}'
		) );
		$this->assertSame( 'red', $state['state'] );
		$this->assertStringContainsString( '开通', $state['message'] );
	}

	public function test_red_prefix_class_match(): void {
		// InvalidAccessKeyId* 前缀通配（实测变体后缀不一）。
		$state = CredentialProbe::classify_aliyun( self::http_error_result( 400,
			'{"Code":"InvalidAccessKeyId.InSomeRegion","Message":"x"}'
		) );
		$this->assertSame( 'red', $state['state'] );
	}

	/* ---------- 黄（HTTP 级参数错）/ 灰（传输终态） ---------- */

	public function test_yellow_missing_parameter(): void {
		$state = CredentialProbe::classify_aliyun( self::http_error_result( 400,
			'{"Code":"MissingParameter","Message":"CaptchaVerifyParam is mandatory"}'
		) );
		$this->assertSame( 'yellow', $state['state'] );
	}

	public function test_gray_transport_terminal(): void {
		$state = CredentialProbe::classify_aliyun( RemoteResult::error( RemoteResult::TRANSPORT, 'transport_failed: connection/timeout' ) );
		$this->assertSame( 'gray', $state['state'] );
		$this->assertStringContainsString( '网络', $state['message'] );
	}

	public function test_gray_500(): void {
		$state = CredentialProbe::classify_aliyun( self::http_error_result( 500, '' ) );
		$this->assertSame( 'gray', $state['state'] );
	}

	/* ---------- 黄（本地必填缺——不发起调用） ---------- */

	public function test_local_precheck_aliyun_missing_fields(): void {
		$result = CredentialProbe::local_precheck_aliyun( array(
			'access_key_id' => 'a', 'access_key_secret' => '', 'scene_id' => '', 'prefix' => 'p',
		) );
		$this->assertNotNull( $result );
		$this->assertSame( 'yellow', $result['state'] );
		$this->assertSame( array( 'AccessKeySecret', 'SceneId' ), $result['missing'] );
		$this->assertStringContainsString( '必填字段缺少', $result['message'] );
	}

	public function test_local_precheck_aliyun_complete_returns_null(): void {
		$this->assertNull( CredentialProbe::local_precheck_aliyun( array(
			'access_key_id' => 'a', 'access_key_secret' => 's', 'scene_id' => 'x', 'prefix' => 'p',
		) ) );
	}
}
