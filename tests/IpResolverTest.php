<?php
/**
 * IpResolver 单元测试（02 §2.4：三模式解析 / 信任链不变式 / 归一化 / 链式回溯高级选项）。
 *
 * 规格（v1.2 修订）：
 *   direct（默认）→ REMOTE_ADDR；
 *   xff → REMOTE_ADDR ∈ 信任段才取 XFF 最右条目，否则忽略头（ignored_untrusted）；
 *   custom_header → REMOTE_ADDR ∈ 信任段才读自定义头（CF-Connecting-IP 类权威覆写头）；
 *   归一化：去端口/方括号、::ffff: 反映射、IPv6 压缩小写（inet_pton/inet_ntop 数值口径）；
 *   任一步失败按非法值跳过（回退 REMOTE_ADDR，ip_src=unresolved）；
 *   链式回溯（高级选项）：从右往左跳过信任段取第一个非信任 IP，全链信任 → null。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Ip\TrustedProxies;

final class IpResolverTest extends TestCase {

	private static function settings( array $overrides = array() ): array {
		return array_merge(
			array(
				'ip_source'       => 'direct',
				'trusted_proxies' => array( 'cloudflare' => false, 'edgeone' => false, 'custom' => '203.0.113.0/24' ),
				'custom_header'   => 'CF-Connecting-IP',
			),
			$overrides
		);
	}

	/* ---------- 归一化 ---------- */

	public function test_normalize_strips_ipv4_port(): void {
		$this->assertSame( '1.2.3.4', IpResolver::normalize( '1.2.3.4:8080' ) );
	}

	public function test_normalize_strips_ipv6_brackets_and_port(): void {
		$this->assertSame( '2001:db8::1', IpResolver::normalize( '[2001:db8::1]:443' ) );
	}

	public function test_normalize_unmaps_v4_mapped_v6(): void {
		$this->assertSame( '192.0.2.128', IpResolver::normalize( '::ffff:192.0.2.128' ) );
	}

	public function test_normalize_compresses_and_lowercases_ipv6(): void {
		$this->assertSame( '2001:db8::abc', IpResolver::normalize( '2001:DB8:0:0:0000:0:0:0ABC' ) );
		$this->assertSame( '2001:db8::2:1', IpResolver::normalize( '2001:db8:0:0:0:0:2:1' ) );
	}

	public function test_normalize_trims_whitespace(): void {
		$this->assertSame( '1.2.3.4', IpResolver::normalize( '  1.2.3.4 ' ) );
	}

	public function test_normalize_rejects_garbage(): void {
		foreach ( array( '', 'not-an-ip', '1.2.3', '1.2.3.4.5', '256.1.1.1', '::gg::1', '1.2.3.4:' ) as $bad ) {
			$this->assertNull( IpResolver::normalize( $bad ), "应拒绝：{$bad}" );
		}
	}

	/* ---------- direct 模式 ---------- */

	public function test_direct_mode_returns_remote_addr(): void {
		$resolver = new IpResolver( self::settings() );
		$result   = $resolver->resolve( array( 'REMOTE_ADDR' => '198.51.100.23' ) );
		$this->assertSame( '198.51.100.23', $result['ip'] );
		$this->assertSame( 'direct', $result['ip_src'] );
	}

	public function test_direct_mode_normalizes_remote_addr(): void {
		$resolver = new IpResolver( self::settings() );
		$result   = $resolver->resolve( array( 'REMOTE_ADDR' => '::ffff:198.51.100.23' ) );
		$this->assertSame( '198.51.100.23', $result['ip'] );
	}

	public function test_invalid_remote_addr_is_unresolved(): void {
		$resolver = new IpResolver( self::settings() );
		$result   = $resolver->resolve( array( 'REMOTE_ADDR' => 'garbage' ) );
		$this->assertSame( '', $result['ip'] );
		$this->assertSame( 'unresolved', $result['ip_src'] );
	}

	/* ---------- xff 模式（仅取最右 + 信任链不变式） ---------- */

	public function test_xff_mode_takes_rightmost_entry_when_remote_trusted(): void {
		$resolver = new IpResolver( self::settings( array( 'ip_source' => 'xff' ) ) );
		$result   = $resolver->resolve( array(
			'REMOTE_ADDR'          => '203.0.113.9',                                  // 自定义信任段内.
			'HTTP_X_FORWARDED_FOR' => '198.51.100.1, 198.51.100.7',                    // 最右 = 受信边缘写入的直连 IP.
		) );
		$this->assertSame( '198.51.100.7', $result['ip'] );
		$this->assertSame( 'xff', $result['ip_src'] );
	}

	public function test_xff_mode_ignores_header_when_remote_untrusted(): void {
		// 信任链不变式：不可信来源的 XFF 头不作数（防伪造，R11）。
		$resolver = new IpResolver( self::settings( array( 'ip_source' => 'xff' ) ) );
		$result   = $resolver->resolve( array(
			'REMOTE_ADDR'          => '198.51.100.99', // 不在信任段（203.0.113.0/24 之外）.
			'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
		) );
		$this->assertSame( '198.51.100.99', $result['ip'] );
		$this->assertSame( 'ignored_untrusted', $result['ip_src'] );
	}

	public function test_xff_mode_rightmost_invalid_falls_back_to_remote(): void {
		$resolver = new IpResolver( self::settings( array( 'ip_source' => 'xff' ) ) );
		$result   = $resolver->resolve( array(
			'REMOTE_ADDR'          => '203.0.113.9',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.1, bogus-entry',
		) );
		$this->assertSame( '203.0.113.9', $result['ip'] );
		$this->assertSame( 'unresolved', $result['ip_src'] );
	}

	public function test_xff_mode_missing_header_falls_back_to_remote(): void {
		$resolver = new IpResolver( self::settings( array( 'ip_source' => 'xff' ) ) );
		$result   = $resolver->resolve( array( 'REMOTE_ADDR' => '203.0.113.9' ) );
		$this->assertSame( '203.0.113.9', $result['ip'] );
		$this->assertSame( 'unresolved', $result['ip_src'] );
	}

	public function test_xff_mode_normalizes_rightmost_entry(): void {
		$resolver = new IpResolver( self::settings( array( 'ip_source' => 'xff' ) ) );
		$result   = $resolver->resolve( array(
			'REMOTE_ADDR'          => '203.0.113.9',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.1, [2001:DB8::ABCD]:443',
		) );
		$this->assertSame( '2001:db8::abcd', $result['ip'] );
	}

	/* ---------- custom_header 模式 ---------- */

	public function test_custom_header_mode_reads_configured_header(): void {
		$resolver = new IpResolver( self::settings( array( 'ip_source' => 'custom_header' ) ) );
		$result   = $resolver->resolve( array(
			'REMOTE_ADDR'            => '203.0.113.9',
			'HTTP_CF_CONNECTING_IP'  => '198.51.100.55',
		) );
		$this->assertSame( '198.51.100.55', $result['ip'] );
		$this->assertSame( 'header', $result['ip_src'] );
	}

	public function test_custom_header_invalid_value_falls_back_to_remote(): void {
		$resolver = new IpResolver( self::settings( array( 'ip_source' => 'custom_header' ) ) );
		$result   = $resolver->resolve( array(
			'REMOTE_ADDR'            => '203.0.113.9',
			'HTTP_CF_CONNECTING_IP'  => 'definitely not an ip',
		) );
		$this->assertSame( '203.0.113.9', $result['ip'] );
		$this->assertSame( 'unresolved', $result['ip_src'] );
	}

	public function test_custom_header_mode_ignores_header_when_untrusted(): void {
		$resolver = new IpResolver( self::settings( array( 'ip_source' => 'custom_header' ) ) );
		$result   = $resolver->resolve( array(
			'REMOTE_ADDR'            => '198.51.100.99',
			'HTTP_CF_CONNECTING_IP'  => '1.2.3.4',
		) );
		$this->assertSame( '198.51.100.99', $result['ip'] );
		$this->assertSame( 'ignored_untrusted', $result['ip_src'] );
	}

	public function test_custom_header_mode_missing_header_falls_back(): void {
		$resolver = new IpResolver( self::settings( array( 'ip_source' => 'custom_header' ) ) );
		$result   = $resolver->resolve( array( 'REMOTE_ADDR' => '203.0.113.9' ) );
		$this->assertSame( '203.0.113.9', $result['ip'] );
		$this->assertSame( 'unresolved', $result['ip_src'] );
	}

	/* ---------- 头名查找 ---------- */

	public function test_header_value_maps_name_to_server_key(): void {
		$server = array( 'HTTP_CF_CONNECTING_IP' => '1.2.3.4' );
		$this->assertSame( '1.2.3.4', IpResolver::header_value( $server, 'CF-Connecting-IP' ) );
		$this->assertSame( '1.2.3.4', IpResolver::header_value( $server, 'cf-connecting-ip' ) );
		$this->assertNull( IpResolver::header_value( $server, 'X-Forwarded-For' ) );
	}

	public function test_header_value_multi_value_returns_raw_string(): void {
		$server = array( 'HTTP_X_FORWARDED_FOR' => 'a, b, c' );
		$this->assertSame( 'a, b, c', IpResolver::header_value( $server, 'X-Forwarded-For' ) );
	}

	/* ---------- 链式回溯（高级选项） ---------- */

	public function test_traverse_xff_returns_first_untrusted_from_right(): void {
		$trusted  = new TrustedProxies( array( '203.0.113.0/24' ) );
		// 多级代理形态：client ← edgeA(信任) ← edgeB(信任) ← origin；XFF 左起为最旧。
		$xff      = '198.51.100.1, 203.0.113.5, 203.0.113.8';
		$this->assertSame( '198.51.100.1', IpResolver::traverse_xff( $xff, $trusted ) );
	}

	public function test_traverse_xff_skips_invalid_entries(): void {
		$trusted  = new TrustedProxies( array( '203.0.113.0/24' ) );
		$this->assertSame( '198.51.100.2', IpResolver::traverse_xff( 'bogus, 203.0.113.5, 198.51.100.2', $trusted ) );
	}

	public function test_traverse_xff_all_trusted_returns_null(): void {
		// 全链信任 → 不可解析（回退 REMOTE_ADDR 由调用方处理）。
		$trusted  = new TrustedProxies( array( '203.0.113.0/24' ) );
		$this->assertNull( IpResolver::traverse_xff( '203.0.113.1, 203.0.113.5', $trusted ) );
	}

	public function test_traverse_xff_empty_chain_returns_null(): void {
		$trusted  = new TrustedProxies( array( '203.0.113.0/24' ) );
		$this->assertNull( IpResolver::traverse_xff( '', $trusted ) );
	}

	/* ---------- 装配容错 ---------- */

	public function test_empty_settings_default_to_direct_mode(): void {
		$resolver = new IpResolver( array() );
		$result   = $resolver->resolve( array( 'REMOTE_ADDR' => '198.51.100.23' ) );
		$this->assertSame( '198.51.100.23', $result['ip'] );
		$this->assertSame( 'direct', $result['ip_src'] );
	}

	public function test_unknown_ip_source_falls_back_to_direct(): void {
		$resolver = new IpResolver( self::settings( array( 'ip_source' => 'carrier-pigeon' ) ) );
		$result   = $resolver->resolve( array(
			'REMOTE_ADDR'          => '198.51.100.23',
			'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
		) );
		$this->assertSame( 'direct', $result['ip_src'] );
	}
}
