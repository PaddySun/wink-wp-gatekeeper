<?php
/**
 * LoginIpRep 单元测试（02 §3.2 IP 信誉三态联动 · §2.3b 权威三态语义；R3/T2 建，
 * 【v1.7·M3-IP·定稿 §2】I2 缓存归一后**退化纯映射**——本文件重写）。
 *
 * 覆盖：三态映射（skip=未启用/未装配/iprep_gate 关/空 IP；pass=probe pass；
 * fail=probe review/reject/error——疑似与恶意同档出码）/ **零缓存读写**（get/set/hmac
 * 注入 spy 断言零调用——缓存职能内化 AbuseIpdbService，无双写双读）/ cache_only 上下文
 * 透传（渲染路径 state(ip, true) → probe 收 cache_only=true；提交路径缺省完整模式）。
 *
 * probe 经 IpReputationProbe 假件注入（ctx 捕获）；纯逻辑零 WP 依赖。
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
	 * 可编程假 probe（返回序消费；null=skip；ctx 捕获断言 cache_only 透传）。
	 */
	private function probe( ?Verdict $result, int &$calls = 0, array &$seen_ctx = [] ): IpReputationProbe {
		return new class( $result, $calls, $seen_ctx ) implements IpReputationProbe {
			private $result;
			private $calls;
			private $seen_ctx;
			public function __construct( ?Verdict $result, int &$calls, array &$seen_ctx ) {
				$this->result   = $result;
				$this->calls    = &$calls;
				$this->seen_ctx = &$seen_ctx;
			}
			public function probe( string $ip, array $ctx = array() ): ?Verdict {
				$this->calls++;
				$this->seen_ctx[] = $ctx;
				return $this->result;
			}
		};
	}

	private static function settings( array $overrides = array() ): array {
		$base = array(
			'iprep'   => array(
				'enabled'                => true,
				'driver'                 => 'abuseipdb',
				'negative_cache_minutes' => 60,
				'abuseipdb'              => array( 'cache_minutes' => 1440 ),
			),
			'captcha' => array( 'login_adaptive' => array( 'iprep_gate' => true ) ),
		);
		foreach ( $overrides as $section => $sub ) {
			$base[ $section ] = array_merge( $base[ $section ], $sub );
		}
		return $base;
	}

	/**
	 * 构造退化后的纯映射 LoginIpRep（get/set/hmac 为退役占位——spy 捕获调用以证零缓存 I/O）。
	 *
	 * @param array<string, mixed> $settings
	 * @param IpReputationProbe    $probe
	 * @param array<int, array>    &$spy_calls 退役闭包调用捕获。
	 */
	private function make( array $settings, IpReputationProbe $probe, array &$spy_calls ): LoginIpRep {
		return new LoginIpRep(
			$settings,
			$probe,
			static function ( string $key ) use ( &$spy_calls ) {
				$spy_calls[] = array( 'op' => 'get', 'key' => $key );
				return null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$spy_calls ): void {
				$spy_calls[] = array( 'op' => 'set', 'key' => $key, 'value' => $value, 'ttl' => $ttl );
			},
			static function ( string $data ) use ( &$spy_calls ): string {
				$spy_calls[] = array( 'op' => 'hmac', 'data' => $data );
				return substr( hash_hmac( 'sha256', $data, 'login-iprep-test' ), 0, 16 );
			}
		);
	}

	/* ---------- 三态映射 ---------- */

	public function test_null_probe_returns_skip(): void {
		$spy = array();
		$calls = 0; $ctx = array();
		$rep = $this->make( self::settings(), $this->probe( null, $calls, $ctx ), $spy );

		$this->assertSame( 'skip', $rep->state( '203.0.113.7' ), 'Null 服务（未装配/未启用）恒 skip=现行行为' );
		$this->assertSame( 1, $calls );
	}

	public function test_iprep_gate_disabled_returns_skip_without_probe(): void {
		$spy = array();
		$calls = 0; $ctx = array();
		$rep = $this->make(
			self::settings( array( 'captcha' => array( 'login_adaptive' => array( 'iprep_gate' => false ) ) ) ),
			$this->probe( Verdict::reject(), $calls, $ctx ),
			$spy
		);

		$this->assertSame( 'skip', $rep->state( '203.0.113.7' ), 'iprep_gate=false → 纯计数（现行行为完全恢复）' );
		$this->assertSame( 0, $calls, '联动关闭：probe 零调用' );
	}

	public function test_probe_pass_maps_pass(): void {
		$spy = array();
		$calls = 0; $ctx = array();
		$rep = $this->make( self::settings(), $this->probe( Verdict::pass( 'abuseipdb', 'score 12' ), $calls, $ctx ), $spy );

		$this->assertSame( 'pass', $rep->state( '203.0.113.7' ) );
		$this->assertSame( 1, $calls );
	}

	/**
	 * 疑似（60–84）与恶意（≥85）同档出码——登录不存在「入待审」（02 §2.3b fail ④）；
	 * 配置错/超时/报错/软顶（reject/error 形态）同档。
	 */
	public function test_probe_review_and_reject_and_error_all_map_fail(): void {
		foreach ( array( Verdict::review( 'abuseipdb', 'score 70' ), Verdict::reject( 'abuseipdb', 'score 99' ), Verdict::error( 'abuseipdb', 'remote_transport' ) ) as $verdict ) {
			$spy = array();
			$calls = 0; $ctx = array();
			$rep = $this->make( self::settings(), $this->probe( $verdict, $calls, $ctx ), $spy );
			$this->assertSame( 'fail', $rep->state( '203.0.113.7' ), 'verdict=' . $verdict->verdict() . ' → fail（直接出码档）' );
		}
	}

	public function test_empty_ip_returns_skip(): void {
		$spy = array();
		$calls = 0; $ctx = array();
		$rep = $this->make( self::settings(), $this->probe( Verdict::reject(), $calls, $ctx ), $spy );

		$this->assertSame( 'skip', $rep->state( '' ), 'IpResolver unresolved 空 IP → skip（宁不判定不误伤，与计数器同口径）' );
		$this->assertSame( 0, $calls );
	}

	/* ---------- 缓存归一（定稿 §2）：零缓存读写 = 无双写 ---------- */

	public function test_degenerated_mapping_does_zero_cache_io(): void {
		// 缓存职能内化 AbuseIpdbService（定稿 §2）——get/set/hmac 退役闭包零调用（无双写双读）。
		$spy = array();
		$calls = 0; $ctx = array();
		$rep = $this->make( self::settings(), $this->probe( Verdict::pass( 'abuseipdb', 'score 1' ), $calls, $ctx ), $spy );

		$rep->state( '203.0.113.7' );
		$rep->state( '198.51.100.9' );
		$this->assertSame( array(), $spy, '退役占位闭包零调用（get/set/hmac 全退役——缓存内化服务）' );
	}

	public function test_render_path_forwards_cache_only_context(): void {
		// 渲染路径（render_orchestrate）→ state(ip, true) → probe 收 cache_only=true（零外呼承载）。
		$spy = array();
		$calls = 0; $ctx = array();
		$rep = $this->make( self::settings(), $this->probe( Verdict::reject( 'abuseipdb', 'cache_miss_strict' ), $calls, $ctx ), $spy );

		$this->assertSame( 'fail', $rep->state( '203.0.113.7', true ), '渲染期只读模式：未命中从严 fail' );
		$this->assertSame( array( array( 'cache_only' => true ) ), $ctx, 'cache_only 上下文透传 probe（定稿 §3.4）' );
	}

	public function test_submit_path_uses_full_mode_by_default(): void {
		// 提交路径（verify_orchestrate/XML-RPC）缺省完整模式——probe 收空 ctx（唯二外呼点之二）。
		$spy = array();
		$calls = 0; $ctx = array();
		$rep = $this->make( self::settings(), $this->probe( Verdict::pass(), $calls, $ctx ), $spy );

		$this->assertSame( 'pass', $rep->state( '203.0.113.7' ) );
		$this->assertSame( array( array() ), $ctx, '缺省完整模式（无 cache_only）——登录提交期外呼点' );
	}
}
