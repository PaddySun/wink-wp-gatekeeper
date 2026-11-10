<?php
/**
 * TrustedProxies 单元测试（02 §2.4 信任网段：CIDR 解析与匹配）。
 *
 * 规格：预置 Cloudflare 官方段（v4+v6 快照，R14 时效标注）+ EdgeOne（无静态官方列表，
 * 快照为空、注释标 API 获取路径）+ 自定义 CIDR（每行一条，v4/v6，非法行跳过并上报）；
 * CIDR 匹配在归一化后按 inet_pton 字节序数值比较（规避文本变体绕过）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Ip\TrustedProxies;

final class TrustedProxiesTest extends TestCase {

	/* ---------- CIDR 解析 ---------- */

	public function test_parse_cidr_ipv4(): void {
		$this->assertNotNull( TrustedProxies::parse_cidr( '173.245.48.0/20' ) );
		$this->assertNotNull( TrustedProxies::parse_cidr( '10.0.0.0/8' ) );
	}

	public function test_parse_cidr_ipv6(): void {
		$this->assertNotNull( TrustedProxies::parse_cidr( '2400:cb00::/32' ) );
		$this->assertNotNull( TrustedProxies::parse_cidr( '2a06:98c0::/29' ) );
	}

	public function test_parse_cidr_bare_ip_defaults_to_host_mask(): void {
		// 单 IP 无掩码 → /32（v4）/128（v6），对站长更友好。
		$cidr = TrustedProxies::parse_cidr( '203.0.113.7' );
		$this->assertNotNull( $cidr );
		$this->assertSame( 32, $cidr['prefix'] );

		$cidr6 = TrustedProxies::parse_cidr( '2001:db8::1' );
		$this->assertNotNull( $cidr6 );
		$this->assertSame( 128, $cidr6['prefix'] );
	}

	public function test_parse_cidr_rejects_garbage(): void {
		foreach ( array( 'not-a-cidr', '10.0.0.0/33', '2400:cb00::/129', '10.0.0.0/', '/24', '', '10.0.0.0/8/9' ) as $bad ) {
			$this->assertNull( TrustedProxies::parse_cidr( $bad ), "应拒绝：{$bad}" );
		}
	}

	/* ---------- 匹配（数值比较，归一化输入） ---------- */

	public function test_matches_within_and_outside_ipv4_range(): void {
		$proxies = new TrustedProxies( array( '173.245.48.0/20' ) );
		$this->assertTrue( $proxies->matches( '173.245.48.5' ) );    // 网段起点.
		$this->assertTrue( $proxies->matches( '173.245.63.255' ) );  // 网段终点.
		$this->assertFalse( $proxies->matches( '173.246.0.1' ) );    // 网段外.
		$this->assertFalse( $proxies->matches( '173.245.47.255' ) ); // 紧邻网段前.
	}

	public function test_matches_ipv6(): void {
		$proxies = new TrustedProxies( array( '2400:cb00::/32' ) );
		$this->assertTrue( $proxies->matches( '2400:cb00:1234::abcd' ) );
		$this->assertFalse( $proxies->matches( '2400:cb01::1' ) ); // /32 之外.
	}

	public function test_matches_v4_mapped_v6_variant_via_normalization(): void {
		// 文本变体绕过防御：::ffff: 映射形态应命中 v4 网段（归一化条款）。
		$proxies = new TrustedProxies( array( '173.245.48.0/20' ) );
		$this->assertTrue( $proxies->matches( '::ffff:173.245.48.5' ) );
	}

	public function test_matches_bracketed_port_form_via_normalization(): void {
		$proxies = new TrustedProxies( array( '2400:cb00::/32' ) );
		$this->assertTrue( $proxies->matches( '[2400:cb00::1]:443' ) );
	}

	public function test_matches_invalid_ip_is_false(): void {
		$proxies = new TrustedProxies( array( '173.245.48.0/20' ) );
		$this->assertFalse( $proxies->matches( 'garbage' ) );
		$this->assertFalse( $proxies->matches( '' ) );
	}

	public function test_invalid_custom_lines_are_skipped_and_reported(): void {
		$proxies = new TrustedProxies( array( '203.0.113.0/24', 'bogus line', '10.0.0.0/8' ) );
		$this->assertTrue( $proxies->matches( '10.0.0.9' ) );
		$this->assertTrue( $proxies->matches( '203.0.113.9' ) );
		$this->assertSame( array( 'bogus line' ), $proxies->invalid_lines() );
	}

	/* ---------- 预置段 ---------- */

	public function test_cloudflare_preset_covers_official_ranges(): void {
		$proxies = new TrustedProxies( array_merge( TrustedProxies::CLOUDFLARE_V4, TrustedProxies::CLOUDFLARE_V6 ) );
		// 官方段抽查（https://www.cloudflare.com/ips/，快照见类内 R14 注释）。
		$this->assertTrue( $proxies->matches( '104.16.132.229' ) );   // 104.16.0.0/13.
		$this->assertTrue( $proxies->matches( '172.64.36.1' ) );      // 172.64.0.0/13.
		$this->assertTrue( $proxies->matches( '2606:4700:4700::1111' ) ); // 2606:4700::/32.
		$this->assertFalse( $proxies->matches( '8.8.8.8' ) );
	}

	public function test_edgeone_preset_is_empty_by_static_decision(): void {
		// 静态结论：EdgeOne 无官方静态网段列表（回源网段约 3-6 个月轮换，经 API 获取）——
		// M1 预置空数组，站长用自定义 CIDR；快照自动化为后续项（类内注释含 API 入口）。
		$this->assertSame( array(), TrustedProxies::EDGEONE );
	}

	/* ---------- from_settings 装配 ---------- */

	public function test_from_settings_combines_presets_and_custom(): void {
		$proxies = TrustedProxies::from_settings( array(
			'cloudflare' => true,
			'edgeone'    => false,
			'custom'     => "198.51.100.0/24\n2400:cb00::/32\nbad-line",
		) );

		$this->assertTrue( $proxies->matches( '104.16.132.229' ) );   // Cloudflare 段.
		$this->assertTrue( $proxies->matches( '198.51.100.7' ) );     // 自定义 v4.
		$this->assertTrue( $proxies->matches( '2400:cb00::9' ) );     // 自定义 v6.
		$this->assertFalse( $proxies->matches( '203.0.113.1' ) );     // 无关网段.
		$this->assertSame( array( 'bad-line' ), $proxies->invalid_lines() );
	}

	public function test_from_settings_with_all_disabled_matches_nothing(): void {
		$proxies = TrustedProxies::from_settings( array(
			'cloudflare' => false,
			'edgeone'    => false,
			'custom'     => '',
		) );
		$this->assertFalse( $proxies->matches( '104.16.132.229' ) );
		$this->assertSame( array(), $proxies->invalid_lines() );
	}

	public function test_from_settings_tolerates_missing_keys(): void {
		$proxies = TrustedProxies::from_settings( array() );
		$this->assertFalse( $proxies->matches( '10.0.0.1' ) );
	}
}
