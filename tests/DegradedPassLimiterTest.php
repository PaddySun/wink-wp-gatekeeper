<?php
/**
 * DegradedPassLimiter 单元测试（02 §2.1【v1.5·C1】pass_limited 降级通道限流器——
 * 复用天御 TokenBucket transient 形态 {reset,count}，默认 5 次/IP/h，参数化 limit/window）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\DegradedPassLimiter;

final class DegradedPassLimiterTest extends TestCase {

	/** @var array<string, mixed> 内存 transient 桶。 */
	private $store;

	/** @var callable */
	private $mac;

	public function setUp(): void {
		$this->store = array();
		$this->mac   = static function ( string $data ): string {
			return hash_hmac( 'sha256', $data, 'limiter-test-key' );
		};
	}

	private function limiter( int $limit = 2, int $window = 3600, string $prefix = 'wk_test_' ): DegradedPassLimiter {
		$store = &$this->store;
		$mac   = $this->mac;
		return new DegradedPassLimiter(
			$limit,
			$window,
			static function ( string $key ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$store, $window ): void {
				$store[ $key ]    = $value;
				$store['ttl_seen'] = $ttl;
			},
			$mac,
			$prefix
		);
	}

	public function test_allows_up_to_limit_then_blocks(): void {
		$limiter = $this->limiter( 2 );
		$this->assertTrue( $limiter->allow( '1.2.3.4' ) );
		$this->assertTrue( $limiter->allow( '1.2.3.4' ) );
		$this->assertFalse( $limiter->allow( '1.2.3.4' ), '超额按 block' );
	}

	public function test_quota_is_per_ip(): void {
		$limiter = $this->limiter( 1 );
		$this->assertTrue( $limiter->allow( '1.1.1.1' ) );
		$this->assertTrue( $limiter->allow( '2.2.2.2' ), '另一 IP 独立配额' );
		$this->assertFalse( $limiter->allow( '1.1.1.1' ) );
	}

	public function test_bucket_resets_after_window(): void {
		$limiter = $this->limiter( 1, 3600 );
		$this->assertTrue( $limiter->allow( '1.2.3.4' ) );
		$this->assertFalse( $limiter->allow( '1.2.3.4' ) );

		// 推进过窗口：reset 时间戳已过 → 新桶。
		$key = DegradedPassLimiter::key( '1.2.3.4', $this->mac, 'wk_test_' );
		$this->store[ $key ]['reset'] = time() - 1;
		$this->assertTrue( $limiter->allow( '1.2.3.4' ), '窗口翻转后配额恢复' );
	}

	public function test_transient_ttl_gets_window_headroom(): void {
		$limiter = $this->limiter( 5, 3600 );
		$limiter->allow( '1.2.3.4' );
		// 天御先例：TTL = window + 60 兜底回收。
		$this->assertSame( 3600 + 60, $this->store['ttl_seen'] );
	}

	public function test_key_shape_is_prefix_plus_hmac16(): void {
		$key = DegradedPassLimiter::key( '1.2.3.4', $this->mac, 'wk_test_' );
		$this->assertStringStartsWith( 'wk_test_', $key );
		$this->assertSame( strlen( 'wk_test_' ) + 16, strlen( $key ) );
	}

	public function test_corrupted_bucket_resets(): void {
		$limiter = $this->limiter( 1 );
		$key     = DegradedPassLimiter::key( '1.2.3.4', $this->mac, 'wk_test_' );
		$this->store[ $key ] = 'garbage'; // redis 形态漂移防御。
		$this->assertTrue( $limiter->allow( '1.2.3.4' ) );
	}
}
