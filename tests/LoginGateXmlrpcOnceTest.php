<?php
/**
 * LoginGate XML-RPC per-request 一次化测试（P0-B——M3-IP 定稿 §8.2，
 * AUDIT-CC §2.5「multicall 单请求钉死 worker ~100s」灭绝攻击修复）。
 *
 * 覆盖（§10-5）：
 *   - multicall 首拒后零 sleep 零事件：xmlrpc_attempt_plan 状态机——首个被拒尝试
 *     {fault,record,sleep}={true,true,true}，后续被拒尝试={true,false,false}（静默 fault）；
 *   - 每请求 auth 尝试上限 20：超限直接 fault（无 sleep 无事件）——未饱和的正常尝试
 *     第 21 次起也 fault；饱和+超限叠加时 record/sleep 恒 false；
 *   - 首拒前的未饱和尝试透传（合法单方法 XML-RPC 调用零变化）；
 *   - 请求内静态标志按类作用域跨实例生效（单请求=单进程语义），reset 显式复位。
 *
 * hook_authenticate 消费契约（WP 边界，单测不可达——静态自查清单覆盖）：仅 plan.record
 * 时落事件、仅 plan.sleep 时 sleep、plan.fault 时返回 WP_Error(wk_captcha_unavailable)——
 * 「零 sleep 零事件」由 plan 布尔面直接钉死；真机验证走 curl 计时+事件行计数。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\LoginCounters;
use WinkGatekeeper\Gates\LoginGate;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Verdict;

final class LoginGateXmlrpcOnceTest extends TestCase {

	private static $stub_hash;

	public static function setUpBeforeClass(): void {
		self::$stub_hash = static function ( string $data ): string {
			return 'h_' . hash_hmac( 'sha256', $data, 'xmlrpc-once-test-key' );
		};
	}

	protected function setUp(): void {
		LoginGate::reset_xmlrpc_request_state(); // 请求内静态标志复位（单测进程共享）。
	}

	private function gate( string $render_mode = 'adaptive' ): LoginGate {
		$settings = array(
			'gates'   => array( 'login' => array(
				'enabled'      => true,
				'render_mode'  => $render_mode,
				'xmlrpc_login' => true,
			) ),
			'captcha' => array( 'login_adaptive' => array(
				'user_threshold' => 3,
				'window_minutes' => 15,
				'ip_threshold'   => 10,
				'iprep_gate'     => true,
			) ),
			'copy'    => Copywork::defaults(),
		);

		$clock   = 1731168000;
		$store   = array();
		$counters = new LoginCounters(
			$settings['captcha']['login_adaptive'],
			static function ( string $key ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$store ): void {
				$store[ $key ] = $value;
			},
			static function ( string $key ) use ( &$store ): void {
				unset( $store[ $key ] );
			},
			static function ( string $data ): string {
				return substr( hash_hmac( 'sha256', $data, 'xmlrpc-once-counters' ), 0, 16 );
			},
			static function () use ( &$clock ): int {
				return $clock;
			}
		);

		return new LoginGate(
			$settings,
			$this->fake_captcha(),
			static function ( string $stage, Verdict $verdict, array $meta ): string {
				return 'WK-20261112-0001'; // 边界不落事件（plan.record 布尔面承载）。
			},
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function () use ( &$clock ): int {
				return $clock;
			},
			$counters
		);
	}

	private function fake_captcha(): object {
		return new class() implements \WinkGatekeeper\Drivers\CaptchaDriverInterface {
			public function render( array $args ): string {
				return '<span class="wk-gate" data-gate="login"></span>';
			}
			public function verify( array $context ): ?string {
				return null;
			}
			public function is_configured(): bool {
				return true;
			}
			public function enqueue_assets(): void {
			}
			public function id(): string {
				return 'fake-captcha';
			}
			public function label(): string {
				return '假验证码';
			}
			public function credentialFields(): array {
				return array( 'wk_captcha_ticket', 'wk_captcha_randstr' );
			}
			public function failurePolicy(): array {
				return array();
			}
			public function disasterPolicy(): string {
				return 'none';
			}
			public function verify_tri( array $context ): array {
				return \WinkGatekeeper\Gates\CaptchaTriInterpreter::legacy_to_tri( $this->verify( $context ), array() );
			}
			public function errorPolicy(): string {
				return 'block';
			}
		};
	}

	private static function server(): array {
		return array( 'REMOTE_ADDR' => '203.0.113.7' );
	}

	/* ---------- multicall 首拒后零 sleep 零事件 ---------- */

	public function test_first_reject_records_then_silent_faults(): void {
		$gate = $this->gate( 'always' ); // always 模式：XML-RPC 恒拒（无需计数器预置）。

		$p1 = $gate->xmlrpc_attempt_plan( 'admin', self::server(), true );
		$this->assertTrue( $p1['fault'] );
		$this->assertTrue( $p1['record'], '首个被拒尝试落事件行（一次化前的原行为保留一次）' );
		$this->assertTrue( $p1['sleep'], '首个被拒尝试 sleep（时序混淆保留一次）' );

		for ( $i = 2; $i <= 20; $i++ ) {
			$p = $gate->xmlrpc_attempt_plan( 'admin' . $i, self::server(), true );
			$this->assertTrue( $p['fault'], "第 {$i} 次尝试仍 fault" );
			$this->assertFalse( $p['record'], "第 {$i} 次尝试零事件行（multicall 一次化）" );
			$this->assertFalse( $p['sleep'], "第 {$i} 次尝试零 sleep（multicall 一次化）" );
		}
	}

	/* ---------- 每请求 auth 尝试上限 20 ---------- */

	public function test_attempt_cap_at_20_faults_without_record_or_sleep(): void {
		$gate = $this->gate( 'adaptive' ); // 未饱和：正常尝试应透传。

		for ( $i = 1; $i <= 20; $i++ ) {
			$p = $gate->xmlrpc_attempt_plan( 'admin', self::server(), true );
			$this->assertFalse( $p['fault'], "未饱和第 {$i} 次（≤上限）透传进正常认证" );
			$this->assertFalse( $p['record'] );
			$this->assertFalse( $p['sleep'] );
		}

		$p21 = $gate->xmlrpc_attempt_plan( 'admin', self::server(), true );
		$this->assertTrue( $p21['fault'], '第 21 次超上限直接 fault' );
		$this->assertFalse( $p21['record'], '超限 fault 无事件' );
		$this->assertFalse( $p21['sleep'], '超限 fault 无 sleep' );

		$p30 = $gate->xmlrpc_attempt_plan( 'admin', self::server(), true );
		$this->assertTrue( $p30['fault'] );
		$this->assertFalse( $p30['record'] );
		$this->assertFalse( $p30['sleep'] );
	}

	public function test_cap_overrides_record_even_when_blocked(): void {
		$gate = $this->gate( 'always' ); // 恒拒 + 20 上限叠加。

		$p1 = $gate->xmlrpc_attempt_plan( 'admin', self::server(), true );
		$this->assertTrue( $p1['record'], '首拒仍落一次事件' );

		// 推进到超上限（首拒已计 1 次，再打 19 次未饱和口径？——always 恒拒但计数按尝试）。
		for ( $i = 2; $i <= 20; $i++ ) {
			$p = $gate->xmlrpc_attempt_plan( 'admin', self::server(), true );
			$this->assertTrue( $p['fault'] );
			$this->assertFalse( $p['record'] );
		}
		$p21 = $gate->xmlrpc_attempt_plan( 'admin', self::server(), true );
		$this->assertTrue( $p21['fault'], '恒拒+超限：fault' );
		$this->assertFalse( $p21['record'], '超限优先：无事件' );
		$this->assertFalse( $p21['sleep'], '超限优先：无 sleep' );
	}

	/* ---------- 未饱和尝试透传（合法调用零变化） ---------- */

	public function test_unsaturated_attempt_passes_through(): void {
		$gate = $this->gate( 'adaptive' );

		$p = $gate->xmlrpc_attempt_plan( 'admin', self::server(), true );
		$this->assertFalse( $p['fault'], '合法单方法 XML-RPC 认证零变化（透传）' );
		$this->assertFalse( $p['record'] );
		$this->assertFalse( $p['sleep'] );

		// 豁免 filter 关闭 → 同样透传。
		$p2 = $gate->xmlrpc_attempt_plan( 'admin', self::server(), false );
		$this->assertFalse( $p2['fault'] );
	}

	/* ---------- 常量钉 ---------- */

	public function test_attempt_limit_constant(): void {
		$this->assertSame( 20, LoginGate::XMLRPC_AUTH_ATTEMPT_LIMIT, '每请求 auth 尝试上限 20（定稿 §8.2）' );
	}

	/* ---------- 【P0-8·F1 批】访客侧文案 __() 包装（M4UX 定稿 §2） ---------- */

	public function test_xmlrpc_fault_message_wrapped_with_translation_call(): void {
		// MSG_XMLRPC 是全库唯一绕过 __()/Copywork 双通道的访客侧明文串（R-UI §E P0-5）。
		// 最小修：包 __()（不引 languages/ 目录——空转直出=行为零变化，i18n-ready 铺路）。
		$src = file_get_contents( __DIR__ . '/../includes/gates/LoginGate.php' );
		self::assertIsString( $src, 'LoginGate.php 必须可读' );

		$this->assertStringContainsString(
			"new \\WP_Error( self::ERROR_CODE_XMLRPC, __( self::MSG_XMLRPC, 'wink-gatekeeper' ) )",
			$src,
			'XML-RPC fault 文案须走 __() 包装（i18n 纪律一致性）'
		);
	}

	/* ---------- reset 复位（单请求=单进程语义的测试桥） ---------- */

	public function test_reset_restores_fresh_request_state(): void {
		$gate = $this->gate( 'always' );

		$p1 = $gate->xmlrpc_attempt_plan( 'admin', self::server(), true );
		$this->assertTrue( $p1['record'] );

		LoginGate::reset_xmlrpc_request_state();

		$p2 = $gate->xmlrpc_attempt_plan( 'admin', self::server(), true );
		$this->assertTrue( $p2['record'], 'reset 后重新按新请求处理——首拒恢复 record' );
	}
}
