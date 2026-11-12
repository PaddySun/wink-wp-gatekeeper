<?php
/**
 * IpResolver traverse 档测试（M3-IP 定稿 §5.2 / §10-6，I0 批）。
 *
 * 规格（定稿 §5.2）：
 *   network.ip_source 值域新增 traverse：REMOTE_ADDR 必须命中受信网段 +
 *   XFF 从右往左跳过受信代理取第一个非受信 IP（IpResolver::traverse_xff() 零件纯接线）；
 *   REMOTE_ADDR 不受信时恒退化 = 照 rightmost 档（xff）处理——转发头一律忽略
 *   （ip_src=ignored_untrusted，信任链不变式不破，02 §2.4 R11）；
 *   全链信任 → 不可解析回退 REMOTE_ADDR（ip_src=unresolved）；
 *   ip_src 五态枚举不扩（traverse 成功取自 XFF 链 → ip_src=xff）；
 *   不作默认（可直连源站部署下回溯会把伪造链当真——O4 头注警示）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Ip\IpResolver;

final class IpResolverTraverseTest extends TestCase {

	private const NETWORK = array(
		'ip_source'       => 'traverse',
		'trusted_proxies' => array( 'cloudflare' => false, 'edgeone' => false, 'custom' => "203.0.113.0/24\n198.51.100.0/24" ),
		'custom_header'   => '',
	);

	/* ---------- 模式接线 ---------- */

	public function test_traverse_mode_is_accepted_not_fallback(): void {
		// 受信 REMOTE_ADDR + 链尾为受信代理、首个非受信=真客户端。
		$resolver = new IpResolver( self::NETWORK );
		$result   = $resolver->resolve( array(
			'REMOTE_ADDR'          => '203.0.113.9',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.2, 203.0.113.5, 192.0.2.77',
		) );
		$this->assertSame( '192.0.2.77', $result['ip'], '取从右往左第一个非受信 IP' );
		$this->assertSame( 'xff', $result['ip_src'], 'ip_src 五态不扩——traverse 成功=xff' );
	}

	public function test_traverse_mode_in_constructor_whitelist(): void {
		// 模式枚举含 traverse：未知值回落 direct 的守卫不应把 traverse 当未知。
		$this->assertSame( 'traverse', IpResolver::MODE_TRAVERSE );
	}

	/* ---------- REMOTE_ADDR 不受信 → 恒退化（照 rightmost 档） ---------- */

	public function test_traverse_untrusted_remote_degrades_to_ignored_untrusted(): void {
		$resolver = new IpResolver( self::NETWORK );
		$result   = $resolver->resolve( array(
			'REMOTE_ADDR'          => '192.0.2.99',        // 不在两信任段内.
			'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
		) );
		$this->assertSame( '192.0.2.99', $result['ip'], '退化=直连取 REMOTE_ADDR' );
		$this->assertSame( 'ignored_untrusted', $result['ip_src'], '信任链不变式：不受信来源转发头一律忽略' );
	}

	public function test_traverse_untrusted_remote_ignores_spoofed_chain(): void {
		// 伪造「全受信链」也不能骗过：REMOTE_ADDR 不受信时头内容任意变化恒 ignored_untrusted。
		$resolver = new IpResolver( self::NETWORK );
		foreach ( array(
			'203.0.113.1, 203.0.113.2',
			'8.8.8.8',
			'garbage-chain',
		) as $xff ) {
			$result = $resolver->resolve( array(
				'REMOTE_ADDR'          => '192.0.2.99',
				'HTTP_X_FORWARDED_FOR' => $xff,
			) );
			$this->assertSame( 'ignored_untrusted', $result['ip_src'], "伪造链「{$xff}」不得生效" );
		}
	}

	public function test_traverse_untrusted_remote_degrades_even_without_header(): void {
		$resolver = new IpResolver( self::NETWORK );
		$result   = $resolver->resolve( array( 'REMOTE_ADDR' => '192.0.2.99' ) );
		$this->assertSame( '192.0.2.99', $result['ip'] );
		$this->assertSame( 'ignored_untrusted', $result['ip_src'] );
	}

	/* ---------- REMOTE_ADDR 受信 → 链式回溯 ---------- */

	public function test_traverse_multi_level_proxy_chain(): void {
		// 多级反代形态：client ← proxyB(受信) ← proxyA(受信) ← 源站；XFF 左起最旧。
		$resolver = new IpResolver( self::NETWORK );
		$result   = $resolver->resolve( array(
			'REMOTE_ADDR'          => '203.0.113.1',
			'HTTP_X_FORWARDED_FOR' => '192.0.2.7, 198.51.100.3',
		) );
		$this->assertSame( '192.0.2.7', $result['ip'] );
		$this->assertSame( 'xff', $result['ip_src'] );
	}

	public function test_traverse_skips_invalid_entries(): void {
		$resolver = new IpResolver( self::NETWORK );
		$result   = $resolver->resolve( array(
			'REMOTE_ADDR'          => '203.0.113.1',
			'HTTP_X_FORWARDED_FOR' => 'bogus, 198.51.100.4, 192.0.2.8',
		) );
		$this->assertSame( '192.0.2.8', $result['ip'], '非法值跳过（归一化条款）' );
	}

	public function test_traverse_all_trusted_chain_falls_back_to_remote_unresolved(): void {
		$resolver = new IpResolver( self::NETWORK );
		$result   = $resolver->resolve( array(
			'REMOTE_ADDR'          => '203.0.113.1',
			'HTTP_X_FORWARDED_FOR' => '203.0.113.5, 198.51.100.6',
		) );
		$this->assertSame( '203.0.113.1', $result['ip'], '全链信任 → 回退 REMOTE_ADDR' );
		$this->assertSame( 'unresolved', $result['ip_src'] );
	}

	public function test_traverse_trusted_remote_without_header_falls_back_unresolved(): void {
		$resolver = new IpResolver( self::NETWORK );
		$result   = $resolver->resolve( array( 'REMOTE_ADDR' => '203.0.113.1' ) );
		$this->assertSame( '203.0.113.1', $result['ip'] );
		$this->assertSame( 'unresolved', $result['ip_src'] );
	}

	public function test_traverse_chain_entries_normalized(): void {
		// 链条目为 [v6]:port / ::ffff: 变体时按归一化条款取正规形。
		$resolver = new IpResolver( self::NETWORK );
		$result   = $resolver->resolve( array(
			'REMOTE_ADDR'          => '203.0.113.1',
			'HTTP_X_FORWARDED_FOR' => '203.0.113.5, [2001:DB8::AB]:443',
		) );
		$this->assertSame( '2001:db8::ab', $result['ip'] );
	}

	/* ---------- 默认与未知值不变式 ---------- */

	public function test_traverse_is_not_the_default_mode(): void {
		// 不作默认（O4 警示：可直连源站部署下回溯会把伪造链当真）。
		$resolver = new IpResolver( array() );
		$result   = $resolver->resolve( array(
			'REMOTE_ADDR'          => '203.0.113.1',
			'HTTP_X_FORWARDED_FOR' => '192.0.2.7',
		) );
		$this->assertSame( 'direct', $result['ip_src'], '空设置默认仍为 direct' );
	}

	public function test_unknown_ip_source_still_falls_back_to_direct(): void {
		$resolver = new IpResolver( array( 'ip_source' => 'carrier-pigeon' ) );
		$result   = $resolver->resolve( array(
			'REMOTE_ADDR'          => '203.0.113.1',
			'HTTP_X_FORWARDED_FOR' => '192.0.2.7',
		) );
		$this->assertSame( 'direct', $result['ip_src'] );
	}
}
