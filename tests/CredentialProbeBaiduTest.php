<?php
/**
 * 百度文本审核「测试凭据」三态单元测试（B6；05 §2.4 凭据自检三态 + 03 Tab③）。
 *
 * 判定法 = 任务口径：绿=真调用合规文本（conclusionType=1）；黄=键缺（本地预检不发调用）
 * 与参数错（error_code=100）；红=鉴权失败（error_code=110/111 token 无效、6 无权限未开通）；
 * 灰=传输终态/配额超限/非预期形态——给原文与排查方向，不误报三态。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Settings\CredentialProbe;

final class CredentialProbeBaiduTest extends TestCase {

	/* ---------- 本地预检（黄态） ---------- */

	public function test_local_precheck_yellow_when_credentials_missing(): void {
		$out = CredentialProbe::local_precheck_baidu( array(
			'api_key'    => '',
			'secret_key' => '',
		) );
		$this->assertNotNull( $out );
		$this->assertSame( 'yellow', $out['state'] );
		$this->assertSame( array( 'API Key', 'Secret Key' ), $out['missing'], 'registry required 声明派生（label 显示名）' );
		$this->assertStringContainsString( 'API Key', $out['message'] );
	}

	public function test_local_precheck_passes_when_credentials_present(): void {
		$this->assertNull( CredentialProbe::local_precheck_baidu( array(
			'api_key'    => 'AK',
			'secret_key' => 'SK',
		) ) );
	}

	/* ---------- 三态分类 ---------- */

	private static function ok_result( string $body, int $status = 200 ): RemoteResult {
		return RemoteResult::classify( array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
		) );
	}

	private static function body( array $data ): string {
		return (string) json_encode( $data, JSON_UNESCAPED_UNICODE );
	}

	public function test_green_on_compliant_probe_text(): void {
		$out = CredentialProbe::classify_baidu( self::ok_result( self::body( array(
			'log_id'         => 17942280572500285,
			'conclusion'     => '合规',
			'conclusionType' => 1,
			'data'           => array(),
		) ) ) );
		$this->assertSame( 'green', $out['state'] );
		$this->assertStringContainsString( 'conclusionType=1', $out['code'] );
		$this->assertStringContainsString( '凭据可用', $out['message'] );
	}

	public function test_red_on_token_auth_failure(): void {
		// error_code=110（access_token 无效）——2xx 错误体形态（百度惯例）。
		$out = CredentialProbe::classify_baidu( self::ok_result( self::body( array(
			'error_code' => 110,
			'error_msg'  => 'access_token invalid',
		) ) ) );
		$this->assertSame( 'red', $out['state'], 'token 鉴权失败 = 红' );
		$this->assertStringContainsString( '110', $out['code'] );
		$this->assertStringContainsString( 'API Key', $out['message'], '指引核对凭据' );

		// HTTP 401 形态（token 端点鉴权失败透传——invalid_client）同归红（semantic 终态=凭据被拒直接证据）。
		$out2 = CredentialProbe::classify_baidu( self::ok_result( self::body( array( 'error' => 'invalid_client' ) ), 401 ) );
		$this->assertSame( 'red', $out2['state'], 'token 端点 semantic 终态 = 红（凭据被拒直接证据）' );
		$this->assertStringContainsString( '鉴权失败', $out2['message'] );
	}

	public function test_red_on_permission_denied(): void {
		$out = CredentialProbe::classify_baidu( self::ok_result( self::body( array(
			'error_code' => 6,
			'error_msg'  => 'no permission to call this api',
		) ) ) );
		$this->assertSame( 'red', $out['state'], '无权限（未开通文本审核）= 红' );
		$this->assertStringContainsString( '文本审核', $out['message'], '指明开通入口' );
	}

	public function test_yellow_on_invalid_parameter(): void {
		$out = CredentialProbe::classify_baidu( self::ok_result( self::body( array(
			'error_code' => 100,
			'error_msg'  => 'invalid parameter',
		) ) ) );
		$this->assertSame( 'yellow', $out['state'] );
		$this->assertStringContainsString( '参数', $out['message'] );
	}

	public function test_gray_on_quota_exceeded(): void {
		$out = CredentialProbe::classify_baidu( self::ok_result( self::body( array(
			'error_code' => 19,
			'error_msg'  => 'request total limit exceeded',
		) ) ) );
		$this->assertSame( 'gray', $out['state'], '配额超限不误报三态（给排查方向）' );
		$this->assertStringContainsString( '19', $out['message'] );
	}

	public function test_gray_on_transport_failure(): void {
		$out = CredentialProbe::classify_baidu( RemoteResult::error( RemoteResult::TRANSPORT, 'transport_failed: timeout' ) );
		$this->assertSame( 'gray', $out['state'] );
		$this->assertStringContainsString( '网络不可达', $out['message'] );
	}

	public function test_gray_on_unexpected_conclusion_type(): void {
		$out = CredentialProbe::classify_baidu( self::ok_result( self::body( array(
			'conclusion'     => '不合规',
			'conclusionType' => 2,
			'data'           => array(),
		) ) ) );
		$this->assertSame( 'gray', $out['state'], '凭据链路已通但探测样本未获合规判定——灰态给原文' );
		$this->assertStringContainsString( 'conclusionType=2', $out['code'] );
	}
}
