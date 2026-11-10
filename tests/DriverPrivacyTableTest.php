<?php
/**
 * 逐驱动合规内容表 + registry privacy 第七维单元测试（05 §4 v1.4·R4 §C；F43）。
 *
 * 覆盖：
 *   - 八驱动内容表落码（tencent_tms/deepseek/openai_custom/moli/baidu/aliyun_green/tencent/
 *     aliyun/geetest/hcaptcha/turnstile/abuseipdb——05 §4 表全量）；
 *   - 已注册驱动（tencent/tencent_tms）registry_entry 内嵌 privacy 并与内容表同源；
 *   - DriverRegistry::register 校验扩展：cross_border ∈ {true,false,null}、privacy_url url 或空串。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\captcha\TencentCaptchaDriver;
use WinkGatekeeper\Drivers\DriverRegistry;
use WinkGatekeeper\Drivers\Review\LocalRulesDriver;
use WinkGatekeeper\Drivers\Review\TencentTmsDriver;
use WinkGatekeeper\Settings\DriverPrivacy;

final class DriverPrivacyTableTest extends TestCase {

	public function test_table_covers_all_documented_drivers(): void {
		// 05 §4 逐驱动合规内容表（八行归并为驱动 id 键集；M3/M4 驱动占位行随注册消费）。
		$expected = array(
			'tencent_tms', 'deepseek', 'openai_custom', 'moli', 'baidu', 'aliyun_green',
			'tencent', 'aliyun', 'geetest', 'hcaptcha', 'turnstile', 'abuseipdb',
		);
		foreach ( $expected as $driver_id ) {
			$this->assertNotNull( DriverPrivacy::entry( $driver_id ), "内容表缺驱动：{$driver_id}" );
		}
	}

	public function test_table_values_match_docs_05_section4(): void {
		$tms = DriverPrivacy::entry( 'tencent_tms' );
		$this->assertFalse( $tms['cross_border'] );
		$this->assertSame( 'https://cloud.tencent.com/document/product/301/1967', $tms['privacy_url'] );
		$this->assertStringContainsString( '昵称', $tms['data_fields'] );
		$this->assertStringContainsString( '正文', $tms['data_fields'] );
		$this->assertStringContainsString( '境内', $tms['region_note'] );

		// 出境两档：hCaptcha/Turnstile/AbuseIPDB=true；自定义端点=null（辖区未知）。
		$this->assertTrue( DriverPrivacy::entry( 'hcaptcha' )['cross_border'] );
		$this->assertTrue( DriverPrivacy::entry( 'turnstile' )['cross_border'] );
		$this->assertTrue( DriverPrivacy::entry( 'abuseipdb' )['cross_border'] );
		$this->assertNull( DriverPrivacy::entry( 'openai_custom' )['cross_border'] );
		$this->assertStringContainsString( '辖区未知', DriverPrivacy::entry( 'openai_custom' )['region_note'], 'v1.2 通用出境提示原文迁入' );

		// 天御：访客 IP + 票据（DescribeCaptchaResult UserIp 参数）。
		$tencent = DriverPrivacy::entry( 'tencent' );
		$this->assertFalse( $tencent['cross_border'] );
		$this->assertStringContainsString( 'IP', $tencent['data_fields'] );
		$this->assertStringContainsString( 'Ticket', $tencent['data_fields'] );

		$this->assertNull( DriverPrivacy::entry( 'local_rules' ), '本地驱动无第三方外送，不入表' );
		$this->assertNull( DriverPrivacy::entry( 'no_such_driver' ) );
	}

	public function test_registered_drivers_embed_privacy_in_registry_entry(): void {
		foreach ( array( TencentCaptchaDriver::registry_entry(), TencentTmsDriver::registry_entry() ) as $entry ) {
			$this->assertArrayHasKey( 'privacy', $entry, "registry 第七维：{$entry['id']}" );
			$this->assertSame( DriverPrivacy::entry( $entry['id'] ), $entry['privacy'], 'registry 内嵌值与内容表同源（引用单一来源，禁双源漂移）' );
			$this->assertArrayNotHasKey( 'cross_border', $entry, '顶层散件 cross_border 已并入 privacy 维删除' );
			$this->assertArrayNotHasKey( 'privacy_url', $entry, '顶层散件 privacy_url 已并入 privacy 维删除' );
		}
	}

	public function test_local_rules_registry_entry_has_no_privacy_dimension(): void {
		$entry = LocalRulesDriver::registry_entry();
		$this->assertArrayNotHasKey( 'privacy', $entry, '本地驱动（无第三方外送）不声明 privacy' );
	}

	/* ---------- DriverRegistry 校验扩展（05 §4：privacy 存在时） ---------- */

	private static function base_entry( array $overrides = array() ): array {
		return array_merge( array(
			'id'          => 'privacy_probe',
			'type'        => 'review',
			'class'       => 'Some\\Driver',
			'label'       => '探针驱动',
			'billing'     => 'free',
			'frontend'    => array(),
			'error_map'   => array(),
			'api_version' => 'v1',
			'settings'    => array(),
		), $overrides );
	}

	public function test_register_accepts_valid_privacy(): void {
		$registry = new DriverRegistry();
		foreach ( array( true, false, null ) as $border ) {
			$registry->register( self::base_entry( array( 'id' => 'p_' . var_export( $border, true ), 'privacy' => array(
				'cross_border' => $border,
				'privacy_url'  => 'https://example.com/privacy',
				'data_fields'  => '正文',
				'region_note'  => '测试',
			) ) ) );
		}
		$this->assertCount( 3, $registry->all() );
	}

	public function test_register_rejects_invalid_cross_border_type(): void {
		$registry = new DriverRegistry();
		$this->expectException( \InvalidArgumentException::class );
		$registry->register( self::base_entry( array( 'privacy' => array( 'cross_border' => 'maybe' ) ) ) );
	}

	public function test_register_rejects_non_url_privacy_url(): void {
		$registry = new DriverRegistry();
		$this->expectException( \InvalidArgumentException::class );
		$registry->register( self::base_entry( array( 'privacy' => array( 'cross_border' => false, 'privacy_url' => 'not a url' ) ) ) );
	}

	public function test_register_accepts_empty_string_privacy_url(): void {
		$registry = new DriverRegistry();
		$registry->register( self::base_entry( array( 'privacy' => array( 'cross_border' => null, 'privacy_url' => '' ) ) ) );
		$this->assertNotNull( $registry->get( 'privacy_probe' ) );
	}

	/* ---------- settings 字段声明扩展：label / required ---------- */

	public function test_register_validates_settings_label_and_required_types(): void {
		$registry = new DriverRegistry();

		// 合法：label 非空串 + required 布尔。
		$registry->register( self::base_entry( array( 'id' => 'spec_ok', 'settings' => array(
			'api_key' => array( 'type' => 'password', 'label' => 'API Key', 'required' => true ),
		) ) ) );
		$this->assertNotNull( $registry->get( 'spec_ok' ) );

		// 非法：label 非字符串 / required 非布尔。
		try {
			$registry->register( self::base_entry( array( 'id' => 'spec_bad_label', 'settings' => array(
				'api_key' => array( 'type' => 'password', 'label' => 123 ),
			) ) ) );
			$this->fail( 'label 非字符串应抛 InvalidArgumentException' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'label', $e->getMessage() );
		}

		try {
			$registry->register( self::base_entry( array( 'id' => 'spec_bad_required', 'settings' => array(
				'api_key' => array( 'type' => 'password', 'required' => 'yes' ),
			) ) ) );
			$this->fail( 'required 非布尔应抛 InvalidArgumentException' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'required', $e->getMessage() );
		}
	}
}
