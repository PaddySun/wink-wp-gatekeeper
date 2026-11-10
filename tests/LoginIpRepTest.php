<?php
/**
 * LoginIpRep 单元测试（02 §3.2 IP 信誉三态联动 · §2.3b 权威三态语义；R3/T2）。
 *
 * 覆盖：三态（skip=未启用/未装配/iprep_gate 关；pass=probe pass；fail=probe
 * review/reject/error——疑似与恶意同档出码）/ 缓存命中免 probe / 未命中 probe 后回填
 * （pass TTL=cache_minutes 60min、fail 负缓存 TTL=negative_cache_minutes 10min）/
 * 损坏缓存值按 fail（fail-safe 从严）/ 空与未知 IP skip。
 *
 * probe 经 IpReputationProbe 假件注入（M2.5 生产装配=Null 恒 skip=现行行为）；
 * 缓存经注入闭包——纯逻辑零 WP 依赖。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\LoginIpRep;
use WinkGatekeeper\Ip\IpReputationProbe;
use WinkGatekeeper\Verdict;

final class LoginIpRepTest extends TestCase {

	/**
	 * 可编程假 probe（返回序消费；null=skip）。
	 */
	private function probe( ?Verdict $result, int &$calls = 0 ): IpReputationProbe {
		return new class( $result, $calls ) implements IpReputationProbe {
			private $result;
			private $calls;
			public function __construct( ?Verdict $result, int &$calls ) {
				$this->result = $result;
				$this->calls  = &$calls;
			}
			public function probe( string $ip ): ?Verdict {
				$this->calls++;
				return $this->result;
			}
		};
	}

	/**
	 * @param array<string, mixed> $overrides 设置覆盖（iprep / captcha.login_adaptive 子树）。
	 * @param array<string, mixed> &$store    缓存后端（引用）。
	 * @param array<int, array{key: string, value: mixed, ttl: int}> &$puts 写入捕获。
	 * @return array{0: callable, 1: callable} get/set 闭包。
	 */
	private function backend( array &$store, array &$puts ): array {
		return array(
			static function ( string $key ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$store, &$puts ): void {
				$store[ $key ] = $value;
				$puts[]        = array( 'key' => $key, 'value' => $value, 'ttl' => $ttl );
			},
		);
	}

	private static function settings( array $overrides = array() ): array {
		$base = array(
			'iprep'  => array(
				'enabled'                => true,
				'driver'                 => 'abuseipdb',
				'negative_cache_minutes' => 10,
				'abuseipdb'              => array( 'cache_minutes' => 60 ),
			),
			'captcha' => array( 'login_adaptive' => array( 'iprep_gate' => true ) ),
		);
		foreach ( $overrides as $section => $sub ) {
			$base[ $section ] = array_merge( $base[ $section ], $sub );
		}
		return $base;
	}

	private function make( array $settings, IpReputationProbe $probe, array &$store, array &$puts ): LoginIpRep {
		list( $get, $set ) = $this->backend( $store, $puts );
		return new LoginIpRep(
			$settings,
			$probe,
			$get,
			$set,
			static function ( string $data ): string {
				return substr( hash_hmac( 'sha256', $data, 'login-iprep-test' ), 0, 16 );
			}
		);
	}

	public function test_null_probe_returns_skip_without_cache_io(): void {
		$store = array();
		$puts  = array();
		$rep   = $this->make( self::settings(), new \WinkGatekeeper\Ip\NullIpReputationService(), $store, $puts );

		$this->assertSame( 'skip', $rep->state( '203.0.113.7' ), 'Null 服务（M2.5 装配）恒 skip=现行行为' );
		$this->assertSame( array(), $store, 'skip 路径零缓存读残留（无写入）' );
		$this->assertSame( array(), $puts );
	}

	public function test_iprep_gate_disabled_returns_skip(): void {
		$calls = 0;
		$store = array();
		$puts  = array();
		$rep   = $this->make(
			self::settings( array( 'captcha' => array( 'login_adaptive' => array( 'iprep_gate' => false ) ) ) ),
			$this->probe( Verdict::reject(), $calls ),
			$store,
			$puts
		);

		$this->assertSame( 'skip', $rep->state( '203.0.113.7' ), 'iprep_gate=false → 纯计数（现行行为完全恢复）' );
		$this->assertSame( 0, $calls, '联动关闭：probe 零调用' );
	}

	public function test_probe_pass_maps_pass_and_writes_cache_60min(): void {
		$calls = 0;
		$store = array();
		$puts  = array();
		$rep   = $this->make( self::settings(), $this->probe( Verdict::pass( 'abuseipdb', 'score 12' ), $calls ), $store, $puts );

		$this->assertSame( 'pass', $rep->state( '203.0.113.7' ) );
		$this->assertSame( 1, $calls, '冷缓存 probe 一次' );
		$this->assertCount( 1, $puts, 'pass 回填缓存' );
		$this->assertSame( 'pass', $puts[0]['value'] );
		$this->assertSame( 3600, $puts[0]['ttl'], 'pass TTL=cache_minutes(60)×60' );
		$this->assertSame( 'wk_ip_abuseipdb_' . substr( hash_hmac( 'sha256', '203.0.113.7', 'login-iprep-test' ), 0, 16 ), $puts[0]['key'], '缓存键 wk_ip_<driver>_<HMAC16(ip)>（02 §2.3b）' );
	}

	/**
	 * 疑似（60–84）与恶意（≥85）同档出码——登录不存在「入待审」（02 §2.3b fail ④）。
	 */
	public function test_probe_review_and_reject_and_error_all_map_fail(): void {
		foreach ( array( Verdict::review( 'abuseipdb', 'score 70' ), Verdict::reject( 'abuseipdb', 'score 99' ), Verdict::error( 'abuseipdb', 'transport' ) ) as $verdict ) {
			$store = array();
			$puts  = array();
			$calls = 0;
			$rep   = $this->make( self::settings(), $this->probe( $verdict, $calls ), $store, $puts );
			$this->assertSame( 'fail', $rep->state( '203.0.113.7' ), 'verdict=' . $verdict->verdict() . ' → fail（直接出码档）' );
		}
	}

	public function test_fail_writes_negative_cache_10min(): void {
		$calls = 0;
		$store = array();
		$puts  = array();
		$rep   = $this->make( self::settings(), $this->probe( Verdict::reject( 'abuseipdb', 'score 99' ), $calls ), $store, $puts );

		$this->assertSame( 'fail', $rep->state( '203.0.113.7' ) );
		$this->assertCount( 1, $puts );
		$this->assertSame( 'fail', $puts[0]['value'] );
		$this->assertSame( 600, $puts[0]['ttl'], 'fail 负缓存 TTL=negative_cache_minutes(10)×60——防故障/攻击期每次渲染都打远端' );
	}

	public function test_cache_hit_skips_probe(): void {
		$calls = 0;
		$store = array();
		$puts  = array();
		$key   = 'wk_ip_abuseipdb_' . substr( hash_hmac( 'sha256', '203.0.113.7', 'login-iprep-test' ), 0, 16 );
		$store[ $key ] = 'pass';
		$rep   = $this->make( self::settings(), $this->probe( Verdict::reject(), $calls ), $store, $puts );

		$this->assertSame( 'pass', $rep->state( '203.0.113.7' ), '缓存命中 → 缓存态（即使 probe 会 reject）' );
		$this->assertSame( 0, $calls, '命中零 probe' );
		$this->assertSame( array(), $puts, '命中零写' );

		$store[ $key ] = 'fail';
		$this->assertSame( 'fail', $rep->state( '203.0.113.7' ), 'fail 缓存命中同读' );
	}

	public function test_corrupted_cache_value_reads_fail(): void {
		$calls = 0;
		$store = array();
		$puts  = array();
		$key   = 'wk_ip_abuseipdb_' . substr( hash_hmac( 'sha256', '203.0.113.7', 'login-iprep-test' ), 0, 16 );
		$store[ $key ] = array( 'garbage' => true );
		$rep   = $this->make( self::settings(), $this->probe( Verdict::pass(), $calls ), $store, $puts );

		$this->assertSame( 'fail', $rep->state( '203.0.113.7' ), '损坏缓存值按 fail（fail-safe 从严，02 §2.3b）' );
	}

	public function test_empty_ip_returns_skip(): void {
		$calls = 0;
		$store = array();
		$puts  = array();
		$rep   = $this->make( self::settings(), $this->probe( Verdict::reject(), $calls ), $store, $puts );

		$this->assertSame( 'skip', $rep->state( '' ), 'IpResolver unresolved 空 IP → skip（宁不判定不误伤，与计数器同口径）' );
		$this->assertSame( 0, $calls );
	}

	public function test_default_settings_fallbacks_apply(): void {
		$calls = 0;
		$store = array();
		$puts  = array();
		// 空 iprep 子树（缺 negative_cache_minutes/cache_minutes/driver）→ 默认 abuseipdb/60/10。
		$rep   = $this->make(
			array( 'iprep' => array( 'enabled' => true ), 'captcha' => array( 'login_adaptive' => array( 'iprep_gate' => true ) ) ),
			$this->probe( Verdict::pass(), $calls ),
			$store,
			$puts
		);

		$this->assertSame( 'pass', $rep->state( '203.0.113.7' ) );
		$this->assertSame( 3600, $puts[0]['ttl'] );
		$this->assertStringStartsWith( 'wk_ip_abuseipdb_', $puts[0]['key'] );
	}
}
