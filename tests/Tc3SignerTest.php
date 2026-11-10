<?php
/**
 * Tc3Signer 单元测试（C4：TC3 签名从 TencentCaptchaDriver 抽取为共用工具）。
 *
 * 覆盖：
 *   - 金测试：腾讯云《签名方法 v3》官方文档示例值逐段复现（与 TencentCaptchaDriverTest 同源锚点）；
 *   - 互验：Tc3Signer 与 TencentCaptchaDriver::tc3_authorization 对同一输入逐字节一致
 *     （抽取不改变行为——既有金测试与 L0 冒烟互验用例的连续性保障）；
 *   - service 派生：host 首段即服务名（captcha / tms 两域共享同一实现）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\captcha\TencentCaptchaDriver;
use WinkGatekeeper\Http\Tc3Signer;

final class Tc3SignerTest extends TestCase {

	/** 官方《签名方法 v3》示例常量（与 TencentCaptchaDriverTest 同一组文档字面值）。 */
	private const DOC_SECRET_ID  = 'AKID********************************';
	private const DOC_SECRET_KEY = '********************************';
	private const DOC_PAYLOAD    = '{"Limit": 1, "Filters": [{"Values": ["\\u672a\\u547d\\u540d"], "Name": "instance-name"}]}';
	private const DOC_HOST       = 'cvm.tencentcloudapi.com';
	private const DOC_ACTION     = 'DescribeInstances';
	private const DOC_VERSION    = '2017-03-12';
	private const DOC_TS         = 1551113065;

	public function test_golden_canonical_request(): void {
		$canonical = Tc3Signer::canonical_request( self::DOC_HOST, self::DOC_ACTION, self::DOC_PAYLOAD );
		$this->assertSame(
			'7019a55be8395899b900fb5564e4200d984910f34794a27cb3fb7d10ff6a1e84',
			hash( 'sha256', $canonical )
		);
	}

	public function test_golden_authorization(): void {
		$result = Tc3Signer::authorization(
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

	public function test_parity_with_captcha_driver_authorization(): void {
		// 互验（L0 冒烟用例族 3 的本地化延续）：抽取后两实现对同一输入必须逐字节一致。
		$signer   = Tc3Signer::authorization( 'AKIDx', 'sk', 'tms.tencentcloudapi.com', 'TextModeration', '2020-12-29', 1731168000, '{"Content":"eC4="}' );
		$driver   = TencentCaptchaDriver::tc3_authorization( 'AKIDx', 'sk', 'tms.tencentcloudapi.com', 'TextModeration', '2020-12-29', 1731168000, '{"Content":"eC4="}' );
		$this->assertSame( $driver['Authorization'], $signer['Authorization'] );
		$this->assertSame( $driver['headers'], $signer['headers'] );
	}

	public function test_parity_canonical_request(): void {
		$this->assertSame(
			TencentCaptchaDriver::tc3_canonical_request( self::DOC_HOST, self::DOC_ACTION, self::DOC_PAYLOAD ),
			Tc3Signer::canonical_request( self::DOC_HOST, self::DOC_ACTION, self::DOC_PAYLOAD )
		);
	}

	public function test_service_derived_from_host_first_segment(): void {
		// tms.tencentcloudapi.com → service=tms（credential scope 内可见）。
		$result = Tc3Signer::authorization( 'AKIDx', 'sk', 'tms.tencentcloudapi.com', 'TextModeration', '2020-12-29', self::DOC_TS, '{}' );
		$this->assertStringContainsString( '/2019-02-25/tms/tc3_request', $result['Authorization'] );
	}

	public function test_headers_shape(): void {
		$result = Tc3Signer::authorization( 'AKIDx', 'sk', 'captcha.tencentcloudapi.com', 'DescribeCaptchaResult', '2019-07-22', 1731168000, '{}' );
		$this->assertSame( 'application/json; charset=utf-8', $result['headers']['Content-Type'] );
		$this->assertSame( 'captcha.tencentcloudapi.com', $result['headers']['Host'] );
		$this->assertSame( 'DescribeCaptchaResult', $result['headers']['X-TC-Action'] );
		$this->assertSame( '2019-07-22', $result['headers']['X-TC-Version'] );
		$this->assertSame( '1731168000', $result['headers']['X-TC-Timestamp'] );
	}
}
