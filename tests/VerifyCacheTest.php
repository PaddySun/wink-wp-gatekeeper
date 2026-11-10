<?php
/**
 * VerifyCache 验证短缓存单元测试（M2 反馈2 T3，D+A-lite 用户选型）。
 *
 * 契约：票据核验成功后 10 分钟（verify_cache_minutes，可关）内，同存根+同 IP 的
 * 再次提交免重复验票——transient 键 = wk_cv_ + HMAC(存根原文|IP) 前 16 位（双绑定）；
 * 仅成功写（remember 显式调用）、TTL=分钟×60、0 分钟=停用。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\VerifyCache;

final class VerifyCacheTest extends TestCase {

	/**
	 * 固定 HMAC（生产 = wp_salt('auth') 派生，Bootstrap 注入）。
	 *
	 * @var callable
	 */
	private static $mac;

	public static function setUpBeforeClass(): void {
		self::$mac = static function ( string $data ): string {
			return hash_hmac( 'sha256', $data, 'verify-cache-test-key' );
		};
	}

	private static function cache( int $minutes, array &$store, array &$puts = null ): VerifyCache {
		$puts = $puts ?? array();
		return new VerifyCache(
			$minutes,
			static function ( string $key ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$store, &$puts ): void {
				$store[ $key ] = $value;
				$puts[]        = array( 'key' => $key, 'value' => $value, 'ttl' => $ttl );
			},
			self::$mac
		);
	}

	/* ---------- 键派生：双绑定（存根原文 + IP） ---------- */

	public function test_key_is_prefixed_16char_hmac_of_stub_and_ip(): void {
		$mac  = self::$mac;
		$key  = VerifyCache::key( 'STUB_A', '203.0.113.7', self::$mac );
		$this->assertSame( 'wk_cv_' . substr( $mac( 'STUB_A|203.0.113.7' ), 0, 16 ), $key );
		$this->assertSame( 6 + 16, strlen( $key ) );
	}

	public function test_key_changes_with_stub_or_ip(): void {
		$mac = self::$mac;
		$base = VerifyCache::key( 'STUB_A', '1.2.3.4', self::$mac );
		// 换表单（新存根）→ 新键：缓存不跨表单会话白嫖。
		$this->assertNotSame( $base, VerifyCache::key( 'STUB_B', '1.2.3.4', self::$mac ) );
		// 换 IP → 新键：双绑定。
		$this->assertNotSame( $base, VerifyCache::key( 'STUB_A', '5.6.7.8', self::$mac ) );
	}

	/* ---------- hit：store 读取 ---------- */

	public function test_hit_true_only_for_stored_true(): void {
		$store = array();
		$cache = self::cache( 10, $store );
		$this->assertFalse( $cache->hit( 'STUB_A', '1.2.3.4' ), '未写过 = 未命中' );

		$cache->remember( 'STUB_A', '1.2.3.4' );
		$this->assertTrue( $cache->hit( 'STUB_A', '1.2.3.4' ) );
	}

	public function test_hit_rejects_non_truthy_store_values(): void {
		$store = array();
		$cache = self::cache( 10, $store );
		/* 真机实测（wpclean 2026-11-10）：redis object-cache 后端把 set_transient(key, true)
		 * 落成 '1' 读回——命中判定按「标量且真值」；假值/非标量不命中。 */
		foreach ( array( false, '0', 0, '', null, array() ) as $value ) {
			$store[ VerifyCache::key( 'STUB_A', '1.2.3.4', self::$mac ) ] = $value;
			$this->assertFalse( $cache->hit( 'STUB_A', '1.2.3.4' ), '假值/非标量不算命中（值=' . var_export( $value, true ) . '）' );
		}
	}

	public function test_hit_accepts_backend_coerced_truthy_forms(): void {
		$store = array();
		$cache = self::cache( 10, $store );
		foreach ( array( true, '1', 1 ) as $value ) {
			$store[ VerifyCache::key( 'STUB_A', '1.2.3.4', self::$mac ) ] = $value;
			$this->assertTrue( $cache->hit( 'STUB_A', '1.2.3.4' ), '后端形态漂移后的真值应命中（值=' . var_export( $value, true ) . '）' );
		}
	}

	/* ---------- remember：仅显式成功调用 + TTL ---------- */

	public function test_remember_writes_true_with_minutes_times_sixty_ttl(): void {
		$store = array();
		$puts  = array();
		$cache = self::cache( 10, $store, $puts );

		$cache->remember( 'STUB_A', '1.2.3.4' );

		$this->assertCount( 1, $puts );
		$this->assertTrue( $puts[0]['value'] );
		$this->assertSame( 600, $puts[0]['ttl'], 'TTL = 10 分钟 × 60' );
		$this->assertSame( VerifyCache::key( 'STUB_A', '1.2.3.4', self::$mac ), $puts[0]['key'] );
	}

	public function test_remember_for_other_stub_does_not_hit_original(): void {
		$store = array();
		$cache = self::cache( 10, $store );

		$cache->remember( 'STUB_A', '1.2.3.4' );

		$this->assertFalse( $cache->hit( 'STUB_B', '1.2.3.4' ), '存根换了不白嫖（绑表单会话）' );
	}

	/* ---------- 停用（verify_cache_minutes=0） ---------- */

	public function test_zero_minutes_disables_hit_and_remember(): void {
		$store = array();
		$puts  = array();
		$cache = self::cache( 0, $store, $puts );

		$this->assertFalse( $cache->enabled() );
		$this->assertFalse( $cache->hit( 'STUB_A', '1.2.3.4' ) );

		$cache->remember( 'STUB_A', '1.2.3.4' );
		$this->assertSame( array(), $puts, '停用时不写缓存' );
		$this->assertSame( array(), $store );
	}

	public function test_default_minutes_is_ten(): void {
		$this->assertSame( 10, VerifyCache::DEFAULT_TTL_MINUTES );
	}
}
