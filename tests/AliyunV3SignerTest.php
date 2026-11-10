<?php
/**
 * AliyunV3Signer 单元测试（B3：ACS3-HMAC-SHA256 签名自持——tools/api-smoke/aliyun-sign.php
 * 蓝本升格入插件，与 Tc3Signer 同层并列）。
 *
 * 金测试 = 阿里云《V3 请求结构》官方文档测试向量（RunInstances 示例）——
 * L0-ali-captcha-2026-11-09 与 L0-ali-green-pro-2026-11-11 两次实调已用同一向量
 * 钉过本算法（tools/api-smoke/smoke-ali-captcha.php 用例 2），此处为插件内实现的
 * 离线金锚（零网络）：
 *   - HashedCanonicalRequest 期望 7ea06492da5221eba5297e897ce16e55f964061054b7695beedaac1145b1e259；
 *   - 最终签名期望 06563a9e1b43f5dfe96b81484da74bceab24a1d853912eee15083a6f0f3283c0
 *     （Secret 取文档占位串 YourAccessKeySecret 派生）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Http\AliyunV3Signer;

final class AliyunV3SignerTest extends TestCase {

	/** 官方文档测试向量常量（help.aliyun.com V3 请求结构 · RunInstances 示例）。 */
	private const DOC_HOST   = 'ecs.cn-shanghai.aliyuncs.com';
	private const DOC_QUERY  = array(
		'ImageId'  => 'win2019_1809_x64_dtc_zh-cn_40G_alibase_20230811.vhd',
		'RegionId' => 'cn-shanghai',
	);
	private const DOC_AK_ID  = 'YourAccessKeyId';
	private const DOC_AK_SEC = 'YourAccessKeySecret';
	private const DOC_ACTION = 'RunInstances';
	private const DOC_VER    = '2014-05-26';
	private const DOC_NONCE  = '3156853299f313e23d1673dc12e1703d';
	private const DOC_DATE   = '2023-10-26T10:22:32Z';

	/** 官方向量输入下的完整签名产出（注入 nonce/date 消随机性）。 */
	private function doc_vector(): array {
		return AliyunV3Signer::sign(
			'POST',
			self::DOC_HOST,
			'/',
			self::DOC_QUERY,
			array(),
			'',
			self::DOC_AK_ID,
			self::DOC_AK_SEC,
			self::DOC_ACTION,
			self::DOC_VER,
			self::DOC_NONCE,
			self::DOC_DATE
		);
	}

	public function test_golden_hashed_canonical_request(): void {
		$result = $this->doc_vector();
		$this->assertSame(
			'7ea06492da5221eba5297e897ce16e55f964061054b7695beedaac1145b1e259',
			$result['hashed_canonical_request'],
			'金测试：官方向量 HashedCanonicalRequest 必须逐字节一致'
		);
	}

	public function test_golden_signature(): void {
		$result = $this->doc_vector();
		$this->assertSame(
			'06563a9e1b43f5dfe96b81484da74bceab24a1d853912eee15083a6f0f3283c0',
			$result['signature'],
			'金测试：官方向量最终签名必须逐字节一致'
		);
	}

	public function test_authorization_header_shape(): void {
		$result = $this->doc_vector();
		$this->assertSame(
			'ACS3-HMAC-SHA256 Credential=YourAccessKeyId,'
			. 'SignedHeaders=host;x-acs-action;x-acs-content-sha256;x-acs-date;x-acs-signature-nonce;x-acs-version,'
			. 'Signature=06563a9e1b43f5dfe96b81484da74bceab24a1d853912eee15083a6f0f3283c0',
			$result['authorization']
		);
	}

	public function test_headers_contain_signed_set_and_send_headers(): void {
		$result = $this->doc_vector();
		foreach ( array( 'host', 'x-acs-action', 'x-acs-version', 'x-acs-date', 'x-acs-signature-nonce', 'x-acs-content-sha256', 'authorization', 'accept' ) as $key ) {
			$this->assertArrayHasKey( $key, $result['headers'], "请求头缺 {$key}" );
		}
		$this->assertSame( self::DOC_ACTION, $result['headers']['x-acs-action'] );
		$this->assertSame( self::DOC_VER, $result['headers']['x-acs-version'] );
		$this->assertSame( self::DOC_DATE, $result['headers']['x-acs-date'] );
		$this->assertSame( self::DOC_NONCE, $result['headers']['x-acs-signature-nonce'] );
		// 空 payload 的 content-sha256 = SHA256("") 固定值。
		$this->assertSame( hash( 'sha256', '' ), $result['headers']['x-acs-content-sha256'] );
	}

	public function test_url_built_with_sorted_encoded_query(): void {
		$result = $this->doc_vector();
		// 键名升序 + RFC3986 编码（. 与 - 保留）。
		$this->assertSame(
			'https://ecs.cn-shanghai.aliyuncs.com/?ImageId=win2019_1809_x64_dtc_zh-cn_40G_alibase_20230811.vhd&RegionId=cn-shanghai',
			$result['url']
		);
	}

	public function test_form_body_content_type_participates_in_signature(): void {
		// 验证码驱动形态：业务参数走 form body（官方不建议 JSON）——content-type 参与签名。
		$body   = http_build_query( array( 'CaptchaVerifyParam' => 'abc', 'SceneId' => 'scene-1' ) );
		$signed = AliyunV3Signer::sign(
			'POST',
			'captcha.cn-shanghai.aliyuncs.com',
			'/',
			array(),
			array( 'content-type' => 'application/x-www-form-urlencoded' ),
			$body,
			'ak',
			'sk',
			'VerifyIntelligentCaptcha',
			'captcha-2023-03-05',
			'nonce-nonce',
			'2026-11-11T00:00:00Z'
		);
		$this->assertStringContainsString( 'SignedHeaders=content-type;host;x-acs-action;', $signed['authorization'] );
		$this->assertSame( 'application/x-www-form-urlencoded', $signed['headers']['content-type'] );
		// CanonicalHeaders 首行（content-type 键序最前）必须进入规范请求串。
		$this->assertStringStartsWith( "POST\n/\n\ncontent-type:application/x-www-form-urlencoded\n", $signed['canonical_request'] );
		// payload 哈希 = body 摘要（form 体参与 x-acs-content-sha256）。
		$this->assertSame( hash( 'sha256', $body ), $signed['headers']['x-acs-content-sha256'] );
	}

	public function test_rfc3986_encoding_semantics(): void {
		// rawurlencode 口径：空格→%20、*→%2A、~ 保留（与阿里 SDK 一致，非 + 号空格）。
		$result = AliyunV3Signer::sign(
			'POST', 'captcha.cn-shanghai.aliyuncs.com', '/',
			array( 'a' => 'x y', 'b' => 'a*b', 'c' => 'd~e' ),
			array(), '', 'ak', 'sk', 'VerifyIntelligentCaptcha', 'captcha-2023-03-05', 'n', '2026-11-11T00:00:00Z'
		);
		$this->assertStringContainsString( 'a=x%20y&', $result['url'] );
		$this->assertStringContainsString( 'b=a%2Ab&', $result['url'] );
		$this->assertStringContainsString( 'c=d~e', $result['url'] );
	}

	public function test_deterministic_and_nonce_defaults_differ(): void {
		$a = AliyunV3Signer::sign( 'POST', 'h1', '/', array(), array(), '', 'ak', 'sk', 'A', 'v' );
		$b = AliyunV3Signer::sign( 'POST', 'h1', '/', array(), array(), '', 'ak', 'sk', 'A', 'v' );
		$this->assertNotSame( $a['headers']['x-acs-signature-nonce'], $b['headers']['x-acs-signature-nonce'], '缺省 nonce 须随机' );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $a['headers']['x-acs-date'] );
	}
}
