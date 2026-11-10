<?php
/**
 * CredentialProbe::classify_tms 单元测试（03 Tab③ TMS「测试凭据」三态——C4 实装）。
 *
 * 判定法（任务口径）：AuthFailure 红（SecretId/SecretKey 错）/ MissingParameter 黄（必填参数缺）/
 * 正常文本 Pass 绿（凭据与审核链路全通）；UnauthorizedOperation（未开通套餐/欠费）归红给指引。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Settings\CredentialProbe;

final class CredentialProbeTmsTest extends TestCase {

	private static function make_result( int $status, string $body ): RemoteResult {
		return RemoteResult::classify( array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
			'headers'  => array(),
		) );
	}

	public function test_green_on_suggestion_pass(): void {
		$result = self::make_result( 200, (string) json_encode( array( 'Response' => array( 'Suggestion' => 'Pass', 'Label' => 'Normal', 'Score' => 0, 'RequestId' => 'r1' ) ) ) );
		$state  = CredentialProbe::classify_tms( $result );
		$this->assertSame( 'green', $state['state'] );
		$this->assertStringContainsString( '凭据可用', $state['message'] );
	}

	public function test_red_on_auth_failure_body_code(): void {
		// TMS 业务错误码在 2xx body 中（02 §2.5）：HTTP 200 + Response.Error.Code=AuthFailure.*。
		$result = self::make_result( 200, (string) json_encode( array( 'Response' => array( 'Error' => array( 'Code' => 'AuthFailure.SignatureFailure', 'Message' => 'sig' ) ) ) ) );
		$state  = CredentialProbe::classify_tms( $result );
		$this->assertSame( 'red', $state['state'] );
		$this->assertStringContainsString( 'SecretId/SecretKey', $state['message'] );
	}

	public function test_red_on_unauthorized_operation(): void {
		$result = self::make_result( 200, (string) json_encode( array( 'Response' => array( 'Error' => array( 'Code' => 'UnauthorizedOperation.Unauthorized', 'Message' => 'no package' ) ) ) ) );
		$state  = CredentialProbe::classify_tms( $result );
		$this->assertSame( 'red', $state['state'] );
		$this->assertStringContainsString( '套餐', $state['message'] );
	}

	public function test_yellow_on_missing_parameter(): void {
		$result = self::make_result( 200, (string) json_encode( array( 'Response' => array( 'Error' => array( 'Code' => 'MissingParameter', 'Message' => 'The request is missing the required parameter `Content`.' ) ) ) ) );
		$state  = CredentialProbe::classify_tms( $result );
		$this->assertSame( 'yellow', $state['state'] );
		$this->assertSame( array( 'Content' ), $state['missing'] );
	}

	public function test_yellow_on_invalid_parameter_value(): void {
		$result = self::make_result( 200, (string) json_encode( array( 'Response' => array( 'Error' => array( 'Code' => 'InvalidParameterValue.ErrTextContentLen', 'Message' => 'too long' ) ) ) ) );
		$state  = CredentialProbe::classify_tms( $result );
		$this->assertSame( 'yellow', $state['state'] );
	}

	public function test_gray_on_transport_terminal(): void {
		$state = CredentialProbe::classify_tms( RemoteResult::error( \WinkGatekeeper\Http\RemoteResult::TRANSPORT, 'timeout' ) );
		$this->assertSame( 'gray', $state['state'] );
		$this->assertNotSame( '', $state['message'] );
	}

	public function test_gray_on_unexpected_body(): void {
		$result = self::make_result( 200, (string) json_encode( array( 'Response' => array( 'Suggestion' => 'Review', 'Label' => 'Ad', 'Score' => 60 ) ) ) );
		$state  = CredentialProbe::classify_tms( $result );
		$this->assertSame( 'gray', $state['state'] );
		$this->assertStringContainsString( 'Review', $state['message'] );
	}
}
