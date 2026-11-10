<?php
/**
 * Tab②「测试凭据」三态判定纯逻辑测试（03 §1 v1.3 用户建议2；判定法=L0-tencent 冒烟定论）。
 *
 * 三态：
 *   - 红：TC3 层 AuthFailure* = SecretId/SecretKey（CAM 密钥）错；
 *   - 黄：MissingParameter = 必填参数缺（逐字段列出）；
 *   - 绿：全参假票核验返回 CaptchaCode 7/8/15 = 凭据/签名链路全通（15=假票的正常业务拒绝）。
 * 灰=传输终态/未知形态（网络不可达等，提示稍后再试）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Settings\CredentialProbe;

final class CredentialProbeTest extends TestCase {

	public function test_green_on_captcha_code_7_8_15(): void {
		foreach ( array( 7, 8, 15 ) as $code ) {
			$result = RemoteResult::classify( array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode( array( 'Response' => array(
					'CaptchaCode' => $code,
					'CaptchaMsg'  => 'decrypt fail',
				) ) ),
			) );
			$state = CredentialProbe::classify( $result );
			$this->assertSame( 'green', $state['state'], "CaptchaCode={$code} 应为绿态" );
			$this->assertStringContainsString( '凭据可用', $state['message'] );
		}
	}

	public function test_red_on_auth_failure(): void {
		// 腾讯云 TC3 层签名失败：HTTP 401 + Response.Error.Code=AuthFailure.*（L0 冒烟形态）。
		$result = RemoteResult::classify( array(
			'response' => array( 'code' => 401 ),
			'body'     => (string) json_encode( array( 'Response' => array( 'Error' => array(
				'Code'    => 'AuthFailure.SignatureFailure',
				'Message' => 'The provided signature was not valid.',
			) ) ) ),
		) );
		$state = CredentialProbe::classify( $result );
		$this->assertSame( 'red', $state['state'] );
		$this->assertStringContainsString( 'SecretId/SecretKey', $state['message'], '红态须指明 CAM 密钥错' );
		$this->assertSame( 'AuthFailure.SignatureFailure', $state['code'] );
	}

	public function test_red_on_auth_failure_inside_http_200_body(): void {
		// 部分网关/ older 形态：HTTP 200 + Response.Error（classify 视为 ok——body 级判定归本探测层）。
		$result = RemoteResult::classify( array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( array( 'Response' => array( 'Error' => array(
				'Code'    => 'AuthFailure.InvalidSecretId',
				'Message' => 'The SecretId is invalid.',
			) ) ) ),
		) );
		$state = CredentialProbe::classify( $result );
		$this->assertSame( 'red', $state['state'] );
	}

	public function test_yellow_on_missing_parameter_lists_fields(): void {
		$result = RemoteResult::classify( array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( array( 'Response' => array( 'Error' => array(
				'Code'    => 'MissingParameter',
				'Message' => 'The request is missing the required parameter `AppSecretKey`.',
			) ) ) ),
		) );
		$state = CredentialProbe::classify( $result );
		$this->assertSame( 'yellow', $state['state'] );
		$this->assertContains( 'AppSecretKey', $state['missing'], '黄态逐字段列出缺失项' );
		$this->assertStringContainsString( 'AppSecretKey', $state['message'] );
	}

	public function test_yellow_local_precheck_without_api_call_when_fields_blank(): void {
		// 本地预检：四字段有空 → 直接黄态列缺（不发远端调用——空 CAM 密钥签名必失败，无诊断价值）。
		$state = CredentialProbe::local_precheck( array(
			'app_id' => '199999164', 'secret_id' => '', 'secret_key' => 'sk', 'app_secret_key' => '',
		) );
		$this->assertSame( 'yellow', $state['state'] );
		$this->assertSame( array( 'SecretId（CAM）', 'AppSecretKey（验证码控制台）' ), $state['missing'] );
	}

	public function test_local_precheck_passes_when_complete(): void {
		$this->assertNull( CredentialProbe::local_precheck( array(
			'app_id' => '199999164', 'secret_id' => 'AKIDx', 'secret_key' => 'sk', 'app_secret_key' => 'ASK',
		) ) );
	}

	public function test_gray_on_transport_failure(): void {
		$result = RemoteResult::classify( null ); // 传输终态（连接失败/超时）。
		$state  = CredentialProbe::classify( $result );
		$this->assertSame( 'gray', $state['state'] );
		$this->assertStringContainsString( '网络', $state['message'] );
	}

	public function test_gray_on_unexpected_captcha_code(): void {
		$result = RemoteResult::classify( array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( array( 'Response' => array(
				'CaptchaCode' => 100,
				'CaptchaMsg'  => 'appid-secretkey-ticket mismatch',
			) ) ),
		) );
		$state = CredentialProbe::classify( $result );
		$this->assertSame( 'gray', $state['state'], '16/100（appid 不匹配/参数校验错）非三态绿——灰态给原文与排查方向' );
		$this->assertStringContainsString( 'CaptchaAppId', $state['message'] );
	}
}
