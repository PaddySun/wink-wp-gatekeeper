<?php
/**
 * Tab④ 分区②「测试凭据」三态判定纯逻辑测试（M3-IP 定稿 §6【O5 回写·P2-6】+ 03 Tab④【v1.7·M3-IP】；
 * 判定法=O5-P2-6 拍板：绿=HTTP 200 且 JSON 可解析——isPublic:false 亦绿（验证的是凭据不是 IP，
 * 最小查询走保留地址 127.0.0.2 + maxAgeInDays=1 省配额）；黄=缺参（API Key 空）；
 * 红=401/402 密钥被拒；灰=传输终态/契约不符/429——给原文与排查方向，不误报三态）。
 *
 * 方法论与 CredentialProbeTest（Tab② 先例）同形态：RemoteResult::classify 夹具直喂，
 * 纯 PHP 零 WP 依赖。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Settings\CredentialProbe;

final class CredentialProbeAbuseIpdbTest extends TestCase {

	/**
	 * 绿态：HTTP 200 + JSON 可解析（data.isPublic=false——保留地址 127.0.0.2 的真实响应形态
	 * 同款夹具，tests/fixtures-i2/abuseipdb-check-127002.json 实录）。
	 */
	public function test_green_on_200_json_with_is_public_false(): void {
		$result = RemoteResult::classify( array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( array(
				'data' => array(
					'ipAddress'            => '127.0.0.2',
					'isPublic'             => false,
					'abuseConfidenceScore' => 0,
					'usageType'            => 'Reserved',
				),
			) ),
		) );
		$state = CredentialProbe::classify_abuseipdb( $result );
		$this->assertSame( 'green', $state['state'], 'isPublic:false 亦绿——验证的是凭据不是 IP（O5-P2-6）' );
		$this->assertStringContainsString( '凭据可用', $state['message'] );
	}

	/** 绿态：HTTP 200 + JSON 可解析（公网地址形态 isPublic=true）。 */
	public function test_green_on_200_json_public_ip(): void {
		$result = RemoteResult::classify( array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( array(
				'data' => array(
					'ipAddress'            => '8.8.8.8',
					'isPublic'             => true,
					'abuseConfidenceScore' => 0,
				),
			) ),
		) );
		$state = CredentialProbe::classify_abuseipdb( $result );
		$this->assertSame( 'green', $state['state'] );
	}

	/** 红态：HTTP 401（密钥被拒——AbuseIPDB 错误面经 HTTP 状态码表达）。 */
	public function test_red_on_401(): void {
		$result = RemoteResult::classify( array(
			'response' => array( 'code' => 401 ),
			'body'     => (string) json_encode( array( 'data' => array( 'message' => 'Invalid key' ) ) ),
		) );
		$state = CredentialProbe::classify_abuseipdb( $result );
		$this->assertSame( 'red', $state['state'], '401 错钥=红态' );
		$this->assertStringContainsString( '401', $state['message'], '红态须指明 HTTP 码' );
		$this->assertStringContainsString( 'API Key', $state['message'], '红态须指明密钥面' );
		$this->assertSame( 'HTTP 401', $state['code'] );
	}

	/** 红态：HTTP 402（订阅/配额不可用——同属密钥面红档，O5 判定法）。 */
	public function test_red_on_402(): void {
		$result = RemoteResult::classify( array(
			'response' => array( 'code' => 402 ),
			'body'     => '',
		) );
		$state = CredentialProbe::classify_abuseipdb( $result );
		$this->assertSame( 'red', $state['state'], '402 同为红态' );
		$this->assertSame( 'HTTP 402', $state['code'] );
	}

	/** 灰态：HTTP 429（限流/配额——凭据可能有效，不误报红态，给排查方向）。 */
	public function test_gray_on_429(): void {
		$result = RemoteResult::classify( array(
			'response' => array( 'code' => 429 ),
			'body'     => '',
		) );
		$state = CredentialProbe::classify_abuseipdb( $result );
		$this->assertSame( 'gray', $state['state'], '429 不误报红态——凭据有效性未知' );
		$this->assertStringContainsString( '429', $state['message'] );
	}

	/** 灰态：传输终态（超时/网络不可达——真机无出口形态）。 */
	public function test_gray_on_transport_error(): void {
		$result = RemoteResult::error( RemoteResult::TRANSPORT, 'cURL error 28: Connection timed out' );
		$state  = CredentialProbe::classify_abuseipdb( $result );
		$this->assertSame( 'gray', $state['state'], '超时=灰态（网络不可达），不误报红/黄' );
		$this->assertStringContainsString( '网络不可达', $state['message'] );
	}

	/** 灰态：HTTP 200 但 body 非 JSON（契约不符——给排查方向）。 */
	public function test_gray_on_200_non_json_body(): void {
		$result = RemoteResult::classify( array(
			'response' => array( 'code' => 200 ),
			'body'     => '<html>rate limit page</html>',
		) );
		$state = CredentialProbe::classify_abuseipdb( $result );
		$this->assertSame( 'gray', $state['state'], '200 但不可解析=灰态（契约不符）' );
		$this->assertStringContainsString( 'JSON', $state['message'] );
	}

	/** 黄态：本地预检——API Key 空（不发远端调用）。 */
	public function test_local_precheck_yellow_on_empty_key(): void {
		$state = CredentialProbe::local_precheck_abuseipdb( '' );
		$this->assertNotNull( $state );
		$this->assertSame( 'yellow', $state['state'] );
		$this->assertStringContainsString( 'API Key', $state['message'] );
	}

	/** 黄态：本地预检——纯空白键同判空。 */
	public function test_local_precheck_yellow_on_whitespace_key(): void {
		$state = CredentialProbe::local_precheck_abuseipdb( "   \t " );
		$this->assertNotNull( $state );
		$this->assertSame( 'yellow', $state['state'] );
	}

	/** 齐全 → null（放行走远端最小查询）。 */
	public function test_local_precheck_null_when_key_present(): void {
		$this->assertNull( CredentialProbe::local_precheck_abuseipdb( 'a8cdb3f2c4d64e1f90ab7777c0ffee12' ) );
	}
}
