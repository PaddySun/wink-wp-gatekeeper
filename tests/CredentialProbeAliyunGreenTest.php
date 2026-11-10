<?php
/**
 * 阿里文本审核增强版 PLUS「测试凭据」三态分类测试（05 §2.5 凭据自检三态 / DESIGN-D2 §2.6）。
 *
 * 判定法（05 §2.5 定稿）：绿=探测调用（content=凭据自检，comment_detection_pro）返回
 * body Code=200（顺带解除停转）；黄=AK 两键缺失或 Service 名配置为空（本地预检逐字段列缺，
 * 不发起远端调用）；红=Code=408 PERMISSION_DENY（文案指明 AliyunYundunGreenWebFullAccess
 * 或开通内容安全增强版）或签名类错（InvalidAccessKeyId 前缀 / SignatureDoesNotMatch）；
 * 灰=传输终态/非预期形态。不用空 content 探测（400 语义模糊）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Settings\CredentialProbe;

final class CredentialProbeAliyunGreenTest extends TestCase {

	/* ---------- 本地预检（黄态） ---------- */

	public function test_local_precheck_lists_missing_fields(): void {
		$out = CredentialProbe::local_precheck_aliyun_green( array(
			'access_key_id'     => '',
			'access_key_secret' => '',
			'service_nickname'  => '',
			'service_comment'   => '',
		) );
		$this->assertNotNull( $out );
		$this->assertSame( 'yellow', $out['state'] );
		$this->assertSame( array( 'AccessKeyId', 'AccessKeySecret', '昵称 Service 名', '正文 Service 名' ), $out['missing'] );
	}

	public function test_local_precheck_service_name_empty_is_missing(): void {
		// 05 §2.5 黄态条款：Service 名配置为空同属黄（AK 齐也不放行远端）。
		$out = CredentialProbe::local_precheck_aliyun_green( array(
			'access_key_id'     => 'id',
			'access_key_secret' => 's',
			'service_nickname'  => '',
			'service_comment'   => 'comment_detection_pro',
		) );
		$this->assertNotNull( $out );
		$this->assertSame( array( '昵称 Service 名' ), $out['missing'] );
	}

	public function test_local_precheck_passes_when_complete(): void {
		$this->assertNull( CredentialProbe::local_precheck_aliyun_green( array(
			'access_key_id'     => 'id',
			'access_key_secret' => 's',
			'service_nickname'  => 'nickname_detection_pro',
			'service_comment'   => 'comment_detection_pro',
		) ) );
	}

	/* ---------- 远端三态分类 ---------- */

	private static function green_ok(): RemoteResult {
		// L0 用例①形态：Code=200 + RiskLevel=none + nonLabel 伪标签。
		$body = (string) json_encode( array(
			'RequestId' => 'R1',
			'Message'   => 'OK',
			'Data'      => array(
				'Result'    => array( array( 'Description' => '未检测出风险', 'Label' => 'nonLabel' ) ),
				'DataId'    => 'wk-comment-probe',
				'RiskLevel' => 'none',
			),
			'Code'      => 200,
		) );
		return RemoteResult::classify( array( 'response' => array( 'code' => 200 ), 'body' => $body ) );
	}

	public function test_green_when_code_200_and_risk_none(): void {
		$out = CredentialProbe::classify_aliyun_green( self::green_ok() );
		$this->assertSame( 'green', $out['state'] );
		$this->assertSame( 'Code=200', $out['code'] );
	}

	public function test_red_when_body_code_408_permission_deny(): void {
		$body   = (string) json_encode( array( 'RequestId' => 'R2', 'Code' => 408, 'Message' => 'PERMISSION_DENY' ) );
		$result = RemoteResult::classify( array( 'response' => array( 'code' => 200 ), 'body' => $body ) );
		$out    = CredentialProbe::classify_aliyun_green( $result );
		$this->assertSame( 'red', $out['state'] );
		$this->assertSame( '408', $out['code'] );
		$this->assertStringContainsString( 'AliyunYundunGreenWebFullAccess', $out['message'], '文案指明需补授的 RAM 权限' );
		$this->assertStringContainsString( '内容安全', $out['message'], '文案指明开通内容安全增强版' );
	}

	public function test_red_when_signature_mismatch_http_400(): void {
		// ACS3 签名错形态：HTTP 400 + body Code=SignatureDoesNotMatch（字符串码）。
		$body   = (string) json_encode( array( 'RequestId' => 'R3', 'Code' => 'SignatureDoesNotMatch', 'Message' => 'mismatch' ) );
		$result = RemoteResult::classify( array( 'response' => array( 'code' => 400 ), 'body' => $body ) );
		$out    = CredentialProbe::classify_aliyun_green( $result );
		$this->assertSame( 'red', $out['state'] );
		$this->assertSame( 'SignatureDoesNotMatch', $out['code'] );
	}

	public function test_red_when_invalid_access_key_id(): void {
		$body   = (string) json_encode( array( 'Code' => 'InvalidAccessKeyId.NotFound', 'Message' => 'no key' ) );
		$result = RemoteResult::classify( array( 'response' => array( 'code' => 400 ), 'body' => $body ) );
		$out    = CredentialProbe::classify_aliyun_green( $result );
		$this->assertSame( 'red', $out['state'] );
	}

	public function test_yellow_when_body_code_400_param_error(): void {
		// L0 用例④形态：Code=400 content is blank——参数类黄态（核对配置区取值）。
		$body   = (string) json_encode( array( 'RequestId' => 'R4', 'Code' => 400, 'Message' => 'content is blank' ) );
		$result = RemoteResult::classify( array( 'response' => array( 'code' => 200 ), 'body' => $body ) );
		$out    = CredentialProbe::classify_aliyun_green( $result );
		$this->assertSame( 'yellow', $out['state'] );
		$this->assertSame( '400', $out['code'] );
	}

	public function test_gray_when_transport_unreachable(): void {
		$result = RemoteResult::error( RemoteResult::TRANSPORT, 'transport_failed: connection/timeout' );
		$out    = CredentialProbe::classify_aliyun_green( $result );
		$this->assertSame( 'gray', $out['state'] );
		$this->assertStringContainsString( '网络不可达', $out['message'] );
	}

	public function test_gray_when_unexpected_risk_level(): void {
		// Code=200 但 RiskLevel 非 none/low（探测样本不应被判风险——形态异常给排查方向）。
		$body = (string) json_encode( array(
			'Code' => 200,
			'Data' => array( 'Result' => array(), 'RiskLevel' => 'high' ),
		) );
		$result = RemoteResult::classify( array( 'response' => array( 'code' => 200 ), 'body' => $body ) );
		$out    = CredentialProbe::classify_aliyun_green( $result );
		$this->assertSame( 'gray', $out['state'] );
		$this->assertStringContainsString( 'RiskLevel', $out['message'] );
	}

	public function test_green_unblocks_halting_is_ajax_boundary_concern(): void {
		// 本类保持纯逻辑：绿态解除停转由 SettingsPage::ajax_test_credentials_aliyun_green 执行
		// （TMS 先例同款——DriverHealth::clear_option 在 AJAX 边界）。此处只钉分类器不含副作用。
		$out = CredentialProbe::classify_aliyun_green( self::green_ok() );
		$this->assertSame( 'green', $out['state'] );
	}
}
