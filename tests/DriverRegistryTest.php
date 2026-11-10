<?php
/**
 * DriverRegistry 驱动注册表单元测试（05 §4 六维元数据）。
 *
 * 规格：六维（label/billing/frontend/error_map/settings/api_version）为必填，
 * 结构校验完整；register()/get()/all()/by_type()；M0 注册表为空（驱动 M1 起）。
 * 测试夹具 = 05 §4 的 tencent 示例元数据。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\DriverRegistry;

final class DriverRegistryTest extends TestCase {

	/**
	 * 05 §4 示例（腾讯天御）作为标准夹具。
	 *
	 * @return array<string, mixed>
	 */
	private function tencent_meta(): array {
		return array(
			'id'            => 'tencent',
			'type'          => 'captcha',
			'class'         => 'WinkGatekeeper\Drivers\Captcha\TencentCaptchaDriver',
			'label'         => '腾讯天御验证码 2.0',
			'reachability'  => 'cn-excellent', // cn-excellent|cn-good|cn-env.
			'billing'       => 'usage',        // free|usage|token.
			'supports_report' => false,
			'frontend'      => array(
				'interaction'  => 'click',
				'token_fields' => array( 'wk_captcha_ticket', 'wk_captcha_randstr' ),
			),
			'error_map'     => array( 'body_probe' => 'inspect_body' ),
			'api_version'   => 'captcha-2019-07-22',
			'settings'      => array(
				'app_id'     => array( 'type' => 'number', 'sanitize' => 'absint' ),
				'secret_key' => array( 'type' => 'password', 'sanitize' => 'text' ),
			),
			'doc_url'       => 'https://cloud.tencent.com/document/product/1110',
			'privacy_url'   => 'https://cloud.tencent.com/service/privacy',
			'cross_border'  => false,
			'milestone'     => 'M1',
		);
	}

	public function test_m0_registry_starts_empty(): void {
		$registry = new DriverRegistry();
		$this->assertSame( array(), $registry->all() );
		$this->assertSame( array(), $registry->by_type( 'captcha' ) );
		$this->assertNull( $registry->get( 'tencent' ) );
	}

	public function test_register_and_get_keeps_metadata_intact(): void {
		$meta     = $this->tencent_meta();
		$registry = new DriverRegistry();
		$registry->register( $meta );

		$stored = $registry->get( 'tencent' );
		$this->assertIsArray( $stored );
		foreach ( $meta as $key => $value ) {
			$this->assertSame( $value, $stored[ $key ], "元数据 {$key} 应原样保留" );
		}
		$this->assertCount( 1, $registry->all() );
	}

	public function test_by_type_filters(): void {
		$registry = new DriverRegistry();
		$registry->register( $this->tencent_meta() );

		$local = array(
			'id'          => 'local_rules',
			'type'        => 'review',
			'class'       => 'WinkGatekeeper\Drivers\Review\LocalRulesReviewDriver',
			'label'       => '本地规则四闸',
			'billing'     => 'free',
			'frontend'    => array(),
			'error_map'   => array(),
			'api_version' => 'builtin-1',
			'settings'    => array(),
		);
		$registry->register( $local );

		$this->assertSame( array( 'tencent' ), array_keys( $registry->by_type( 'captcha' ) ) );
		$this->assertSame( array( 'local_rules' ), array_keys( $registry->by_type( 'review' ) ) );
		$this->assertSame( array(), $registry->by_type( 'ip' ) );
		$this->assertSame( array( 'tencent', 'local_rules' ), array_keys( $registry->all() ) );
	}

	public function test_register_duplicate_id_throws(): void {
		$registry = new DriverRegistry();
		$registry->register( $this->tencent_meta() );
		$this->expectException( \InvalidArgumentException::class );
		$registry->register( $this->tencent_meta() );
	}

	/**
	 * 核心字段 + 六维元数据（label/billing/frontend/error_map/settings/api_version）缺一不可。
	 */
	public function test_register_missing_any_required_dimension_throws(): void {
		$required = array( 'id', 'type', 'class', 'label', 'billing', 'frontend', 'error_map', 'settings', 'api_version' );

		foreach ( $required as $missing ) {
			$broken = $this->tencent_meta();
			unset( $broken[ $missing ] );
			try {
				( new DriverRegistry() )->register( $broken );
				$this->fail( "缺少 {$missing} 应抛异常" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( $missing, $e->getMessage() );
			}
		}
	}

	public function test_register_invalid_type_throws(): void {
		$meta         = $this->tencent_meta();
		$meta['type'] = 'sms';
		$this->expectException( \InvalidArgumentException::class );
		( new DriverRegistry() )->register( $meta );
	}

	public function test_register_invalid_billing_throws(): void {
		foreach ( array( 'per-use', '', 'FREE' ) as $bad ) {
			$meta           = $this->tencent_meta();
			$meta['billing'] = $bad;
			try {
				( new DriverRegistry() )->register( $meta );
				$this->fail( "billing={$bad} 应抛异常" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'billing', $e->getMessage() );
			}
		}
	}

	public function test_register_captcha_requires_frontend_shape(): void {
		// interaction 非法。
		$meta = $this->tencent_meta();
		$meta['frontend']['interaction'] = 'tap';
		try {
			( new DriverRegistry() )->register( $meta );
			$this->fail( 'interaction=tap 应抛异常' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'frontend', $e->getMessage() );
		}

		// token_fields 非数组。
		$meta = $this->tencent_meta();
		$meta['frontend']['token_fields'] = 'wk_ticket';
		try {
			( new DriverRegistry() )->register( $meta );
			$this->fail( 'token_fields 字符串应抛异常' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'frontend', $e->getMessage() );
		}
	}

	public function test_register_settings_spec_without_type_throws(): void {
		$meta = $this->tencent_meta();
		$meta['settings']['app_id'] = array( 'sanitize' => 'absint' );
		$this->expectException( \InvalidArgumentException::class );
		( new DriverRegistry() )->register( $meta );
	}

	public function test_supports_report_defaults_false_and_ip_driver_true(): void {
		// 六维齐全但未声明 supports_report：注册后规范化为 false（IP 类驱动专属才显式声明 true）。
		$meta = $this->tencent_meta();
		unset( $meta['supports_report'] );
		$registry = new DriverRegistry();
		$registry->register( $meta );
		$this->assertFalse( $registry->get( 'tencent' )['supports_report'] );

		$abuseipdb = array(
			'id'            => 'abuseipdb',
			'type'          => 'ip',
			'class'         => 'WinkGatekeeper\Drivers\Ip\AbuseIpDbDriver',
			'label'         => 'AbuseIPDB',
			'billing'       => 'free',
			'supports_report' => true,
			'frontend'      => array(),
			'error_map'     => array(),
			'api_version'   => 'api-v2',
			'settings'      => array(
				'api_key' => array( 'type' => 'password', 'sanitize' => 'text' ),
			),
		);
		$registry->register( $abuseipdb );
		$this->assertTrue( $registry->get( 'abuseipdb' )['supports_report'] );
		$this->assertSame( array( 'abuseipdb' ), array_keys( $registry->by_type( 'ip' ) ) );
	}

	public function test_supports_report_non_bool_throws(): void {
		$meta = $this->tencent_meta();
		$meta['id'] = 't2';
		$meta['supports_report'] = 'yes';
		$this->expectException( \InvalidArgumentException::class );
		( new DriverRegistry() )->register( $meta );
	}
}
