<?php
/**
 * TransientRateLimiter 单元测试（P0-A 评论提交限流器共享件——M3-IP 定稿 §8.1，
 * DegradedPassLimiter 同形态泛化：transient 计数桶 {reset,count}，参数化
 * limit/window/prefix，默认 30 次/h/IP、wk_cm_ 前缀）。
 *
 * 后续消费方（设计预留）：check 面（§3）、report 面（§4.3 桶③）、P1-C 云审送审帽。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\TransientRateLimiter;

final class TransientRateLimiterTest extends TestCase {

	/** @var array<string, mixed> 内存 transient 桶。 */
	private $store;

	/** @var callable */
	private $mac;

	public function setUp(): void {
		$this->store = array();
		$this->mac   = static function ( string $data ): string {
			return hash_hmac( 'sha256', $data, 'rate-limiter-test-key' );
		};
	}

	private function limiter( int $limit = 30, int $window = 3600, string $prefix = 'wk_cm_' ): TransientRateLimiter {
		$store = &$this->store;
		$mac   = $this->mac;
		return new TransientRateLimiter(
			$limit,
			$window,
			static function ( string $key ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$store ): void {
				$store[ $key ]      = $value;
				$store['ttl_seen'] = $ttl;
			},
			$mac,
			$prefix
		);
	}

	/* ---------- §10-4：31 次翻转（默认 30） ---------- */

	public function test_default_30_flips_on_31st(): void {
		$limiter = $this->limiter(); // 默认 30。
		for ( $i = 0; $i < 30; $i++ ) {
			$this->assertTrue( $limiter->allow( '203.0.113.7' ), "第 " . ( $i + 1 ) . " 次应放行" );
		}
		$this->assertFalse( $limiter->allow( '203.0.113.7' ), '第 31 次应拒绝（默认 30 次/h/IP）' );
	}

	public function test_custom_limit_flips_accordingly(): void {
		$limiter = $this->limiter( 2 );
		$this->assertTrue( $limiter->allow( '1.2.3.4' ) );
		$this->assertTrue( $limiter->allow( '1.2.3.4' ) );
		$this->assertFalse( $limiter->allow( '1.2.3.4' ), '超额拒绝' );
	}

	public function test_quota_is_per_ip(): void {
		$limiter = $this->limiter( 1 );
		$this->assertTrue( $limiter->allow( '1.1.1.1' ) );
		$this->assertTrue( $limiter->allow( '2.2.2.2' ), '另一 IP 独立配额' );
		$this->assertFalse( $limiter->allow( '1.1.1.1' ) );
	}

	/* ---------- 窗口过期恢复 ---------- */

	public function test_bucket_resets_after_window(): void {
		$limiter = $this->limiter( 1, 3600 );
		$this->assertTrue( $limiter->allow( '1.2.3.4' ) );
		$this->assertFalse( $limiter->allow( '1.2.3.4' ) );

		// 推进过窗口：reset 时间戳已过 → 新桶。
		$key = TransientRateLimiter::key( '1.2.3.4', $this->mac, 'wk_cm_' );
		$this->store[ $key ]['reset'] = time() - 1;
		$this->assertTrue( $limiter->allow( '1.2.3.4' ), '窗口过期后配额恢复' );
	}

	public function test_transient_ttl_gets_window_headroom(): void {
		$limiter = $this->limiter( 5, 3600 );
		$limiter->allow( '1.2.3.4' );
		// DegradedPassLimiter 同款纪律：TTL = window + 60 兜底回收。
		$this->assertSame( 3600 + 60, $this->store['ttl_seen'] );
	}

	public function test_key_shape_is_prefix_plus_hmac16(): void {
		$key = TransientRateLimiter::key( '1.2.3.4', $this->mac, 'wk_cm_' );
		$this->assertStringStartsWith( 'wk_cm_', $key, '桶前缀恒 wk_cm_（uninstall 清理清单对齐）' );
		$this->assertSame( strlen( 'wk_cm_' ) + 16, strlen( $key ) );
	}

	public function test_default_prefix_constant_is_wk_cm(): void {
		$this->assertSame( 'wk_cm_', TransientRateLimiter::PREFIX );
		$this->assertSame( 30, TransientRateLimiter::DEFAULT_LIMIT );
		$this->assertSame( 3600, TransientRateLimiter::DEFAULT_WINDOW );
	}

	public function test_corrupted_bucket_resets(): void {
		$limiter = $this->limiter( 1 );
		$key     = TransientRateLimiter::key( '1.2.3.4', $this->mac, 'wk_cm_' );
		$this->store[ $key ] = 'garbage'; // Redis 形态漂移防御（DegradedPassLimiter 同款）。
		$this->assertTrue( $limiter->allow( '1.2.3.4' ) );
	}

	/* ---------- §9 前缀补录：uninstall.php 清理清单含 wk_cm_ ---------- */

	public function test_uninstall_cleanup_list_includes_wk_cm_prefix(): void {
		$src = file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
		$this->assertNotFalse( $src, 'uninstall.php 可读' );
		$this->assertStringContainsString( "'wk_cm_'", $src, 'uninstall.php transient 清理清单须含 wk_cm_ 前缀（M3-IP 定稿 §9 补录）' );
	}
}
