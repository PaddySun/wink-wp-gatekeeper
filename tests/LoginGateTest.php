<?php
/**
 * LoginGate 编排单元测试（02 §3.2 + 03 §2.2 v1.3）。
 *
 * 覆盖：模式矩阵（off/adaptive/always × 未超/超阈 × 驱动配置态）→ WP_Error 形态判定；
 * $user 已 WP_Error 透传（纯逻辑面 = user_is_error 参数）；存根三态消费（false=bad-signature、
 * true=超阈下按 captcha_missing 拦——对齐 CommentGate 强制验证码优先条款、null=必须验票）；
 * 豁免 filter 参数（wk_login_protection_enabled）；未配置驱动超阈不拦+记事件 meta；
 * XML-RPC 超阈结构化拒绝决策；渲染（零打扰基线/超阈出码/常显/未配置零 DOM）；
 * 事件写入 gate=login（stage=captcha/stub、verdict=reject、meta.client_ip→ip_hash 链路输入）。
 *
 * 边界方法（WP_Error 构造/apply_filters/$_POST unset）为 WP 边界，不在纯逻辑单测环境执行。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\LoginCounters;
use WinkGatekeeper\Gates\LoginGate;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Intercept\Stub;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Verdict;

final class LoginGateTest extends TestCase {

	private static $stub_hash;

	public static function setUpBeforeClass(): void {
		self::$stub_hash = static function ( string $data ): string {
			return 'h_' . hash_hmac( 'sha256', $data, 'login-gate-test-key' );
		};
	}

	/**
	 * 可编程假验证码驱动（verify 结果/配置态可设定；render 输出登录域消费的最小 DOM）。
	 */
	private function fake_captcha( ?string $verify_result, bool $configured = true ): object {
		return new class( $verify_result, $configured ) implements \WinkGatekeeper\Drivers\CaptchaDriverInterface {
			private $result;
			private $configured;
			public function __construct( ?string $result, bool $configured ) {
				$this->result    = $result;
				$this->configured = $configured;
			}
			public function render( array $args ): string {
				$stub = isset( $args['stub'] ) ? (string) $args['stub'] : '';
				return '<span class="wk-gate" data-gate="' . ( isset( $args['gate'] ) ? (string) $args['gate'] : '' ) . '" data-driver="fake" data-mode="embed" data-app-id="190000000">'
					. '<input type="hidden" name="wk_stub" value="' . $stub . '">'
					. '<input type="hidden" name="wk_captcha_ticket" value=""><input type="hidden" name="wk_captcha_randstr" value="">'
					. '<button type="button" class="wk-captcha-btn wk-verify-btn button">完成人机验证</button></span>'
					. '<div class="wk-gate-embed" hidden></div>';
			}
			public function verify( array $context ): ?string {
				return $this->result;
			}
			public function is_configured(): bool {
				return $this->configured;
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
			/* 【v1.5·C1】接口 v1.2 能力面：假驱动沿 legacy 语义折算（verify_tri 委托 verify）。 */
			public function verify_tri( array $context ): array {
				return \WinkGatekeeper\Gates\CaptchaTriInterpreter::legacy_to_tri( $this->verify( $context ), method_exists( $this, 'last_verify_meta' ) ? $this->last_verify_meta() : array() );
			}
			public function errorPolicy(): string {
				return 'block';
			}
		};
	}

	private static function settings( array $overrides = array() ): array {
		return array_merge( array(
			'gates'   => array( 'login' => array(
				'enabled'      => true,
				'render_mode'  => 'adaptive',
				'xmlrpc_login' => true,
			) ),
			'captcha' => array( 'login_adaptive' => array(
				'user_threshold' => 3,
				'window_minutes' => 15,
				'ip_threshold'   => 10,
				'iprep_gate'     => true,
			) ),
			'iprep'   => array(
				'enabled'                => true,
				'driver'                 => 'abuseipdb',
				'negative_cache_minutes' => 10,
				'abuseipdb'              => array( 'cache_minutes' => 60 ),
			),
			'copy'    => Copywork::defaults(),
		), $overrides );
	}

	/**
	 * 登录闸工厂：注入内存计数器（可预置失败）+ 捕获记录器 + 固定时钟 + 可选假 iprep。
	 *
	 * @param object|null       $captcha 假驱动。
	 * @param array             &$log    事件捕获。
	 * @param array             $overrides 设置覆盖。
	 * @param LoginCounters|null $counters 注入计数器（null = 新建内存版）。
	 * @param \WinkGatekeeper\Gates\LoginIpRep|null $iprep 注入 iprep（null = Null 恒 skip）。
	 * @return array{gate: LoginGate, counters: LoginCounters}
	 */
	private function gate( ?object $captcha, array &$log, array $overrides = array(), ?LoginCounters $counters = null, ?\WinkGatekeeper\Gates\LoginIpRep $iprep = null ): array {
		$clock = 1731168000;
		$store = array();

		if ( null === $counters ) {
			$counters = new LoginCounters(
				self::settings( $overrides )['captcha']['login_adaptive'],
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
					return substr( hash_hmac( 'sha256', $data, 'login-gate-counters' ), 0, 16 );
				},
				static function () use ( &$clock ): int {
					return $clock;
				}
			);
		}

		$gate = new LoginGate(
			self::settings( $overrides ),
			$captcha,
			static function ( string $stage, Verdict $verdict, array $meta ) use ( &$log ): string {
				$log[] = array( 'stage' => $stage, 'verdict' => $verdict->verdict(), 'rule' => $verdict->reason(), 'meta' => $meta );
				return 'WK-20261110-0001';
			},
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function () use ( &$clock ): int {
				return $clock;
			},
			$counters,
			null,
			$iprep
		);

		return array( 'gate' => $gate, 'counters' => $counters );
	}

	/**
	 * 可编程假 iprep（state 可钉死；调用计数；settings 可覆盖 iprep_gate 等）。
	 */
	private function iprep( string $state, int &$calls = 0, array $overrides = array() ): \WinkGatekeeper\Gates\LoginIpRep {
		$store = array();
		return new \WinkGatekeeper\Gates\LoginIpRep(
			self::settings( $overrides ),
			new class( $state, $calls ) implements \WinkGatekeeper\Ip\IpReputationProbe {
				private $state;
				private $calls;
				public function __construct( string $state, int &$calls ) {
					$this->state = $state;
					$this->calls = &$calls;
				}
				public function probe( string $ip ): ?Verdict {
					$this->calls++;
					if ( 'skip' === $this->state ) {
						return null;
					}
					return 'pass' === $this->state
						? Verdict::pass( 'abuseipdb', 'fake' )
						: Verdict::reject( 'abuseipdb', 'fake-fail' );
				}
			},
			static function ( string $key ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$store ): void {
				$store[ $key ] = $value;
			},
			static function ( string $data ): string {
				return substr( hash_hmac( 'sha256', $data, 'login-gate-iprep' ), 0, 16 );
			}
		);
	}

	private static function server(): array {
		return array( 'REMOTE_ADDR' => '203.0.113.7' );
	}

	private static function shown_stub(): string {
		$hash = self::$stub_hash;
		$p    = Stub::encode_payload( 'login', 'login', true, 1731167000 );
		return $p . '.' . $hash( $p );
	}

	private static function unshown_stub(): string {
		$hash = self::$stub_hash;
		$p    = Stub::encode_payload( 'login', 'login', false, 1731167000 );
		return $p . '.' . $hash( $p );
	}

	private function saturate( LoginCounters $counters ): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$counters->record_failure( '203.0.113.7', 'admin' );
		}
	}

	/* ---------- 模式矩阵：验证 ---------- */

	public function test_mode_off_allows_even_over_threshold(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( '本不应被调用' ), $log, array(
			'gates' => array( 'login' => array( 'enabled' => true, 'render_mode' => 'off', 'xmlrpc_login' => true ) ),
		) );
		$this->saturate( $mk['counters'] );

		$result = $mk['gate']->verify_orchestrate( 'admin', array(), self::server(), false, true );

		$this->assertSame( 'allow', $result['decision'], 'off 模式超阈也放行' );
		$this->assertSame( array(), $log, '零事件' );
	}

	public function test_gate_disabled_allows_even_over_threshold(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( '本不应被调用' ), $log, array(
			'gates' => array( 'login' => array( 'enabled' => false, 'render_mode' => 'adaptive', 'xmlrpc_login' => true ) ),
		) );
		$this->saturate( $mk['counters'] );

		$result = $mk['gate']->verify_orchestrate( 'admin', array(), self::server(), false, true );

		$this->assertSame( 'allow', $result['decision'], '闸关闭 = 零验证零渲染' );
	}

	public function test_adaptive_under_threshold_allows_zero_disturbance(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( '本不应被调用' ), $log );
		// 不预置失败：计数 0。

		$result = $mk['gate']->verify_orchestrate( 'admin', array(), self::server(), false, true );

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertSame( array(), $log, '未超阈零打扰：无事件无验证' );
	}

	public function test_adaptive_over_threshold_with_valid_ticket_allows(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log );
		$this->saturate( $mk['counters'] );

		$result = $mk['gate']->verify_orchestrate( 'admin', array(
			'wk_stub'            => self::shown_stub(),
			'wk_captcha_ticket'  => 'ticket-abc',
			'wk_captcha_randstr' => 'rand-xyz',
		), self::server(), false, true );

		$this->assertSame( 'allow', $result['decision'], '超阈+有效票=放行' );
		$this->assertSame( array(), $log, '通过不落事件（04 §3 写入矩阵）' );
	}

	/**
	 * 【M2-FIX2 D2 红→绿】闸传给驱动 verify() 的 context 必须用接口契约规范键
	 * （CaptchaDriverInterface::verify docblock：{ticket, randstr, user_ip, …开放键集}），
	 * 不得透传表单原名——真驱动 TencentCaptchaDriver 读 $context['ticket']/
	 * $context['randstr']，透传 wk_captcha_* 键 = 票永远空 = 登录死锁（P0）。
	 */
	public function test_verify_passes_canonical_ticket_keys_to_driver(): void {
		$captcha = new class implements \WinkGatekeeper\Drivers\CaptchaDriverInterface {
			public $received = null;
			public function render( array $args ): string {
				return '';
			}
			public function verify( array $context ): ?string {
				$this->received = $context;
				return null; // 放行：只断言收到的 context。
			}
			public function is_configured(): bool {
				return true;
			}
			public function enqueue_assets(): void {
			}
			public function id(): string {
				return 'spy-captcha';
			}
			public function label(): string {
				return '探针驱动';
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
			/* 【v1.5·C1】接口 v1.2 能力面：假驱动沿 legacy 语义折算（verify_tri 委托 verify）。 */
			public function verify_tri( array $context ): array {
				return \WinkGatekeeper\Gates\CaptchaTriInterpreter::legacy_to_tri( $this->verify( $context ), method_exists( $this, 'last_verify_meta' ) ? $this->last_verify_meta() : array() );
			}
			public function errorPolicy(): string {
				return 'block';
			}
		};
		$log = array();
		$mk  = $this->gate( $captcha, $log );
		$this->saturate( $mk['counters'] );

		$mk['gate']->verify_orchestrate( 'admin', array(
			'wk_stub'            => self::shown_stub(),
			'wk_captcha_ticket'  => 'ticket-abc',
			'wk_captcha_randstr' => 'rand-xyz',
		), self::server(), false, true );

		$this->assertNotNull( $captcha->received, '超阈+展示过存根+有票 → 驱动 verify 必须被调用' );
		$this->assertSame( 'ticket-abc', $captcha->received['ticket'] ?? null, 'context 携带规范键 ticket（接口契约）' );
		$this->assertSame( 'rand-xyz', $captcha->received['randstr'] ?? null, 'context 携带规范键 randstr（接口契约）' );
		$this->assertSame( '203.0.113.7', $captcha->received['user_ip'] ?? null, 'user_ip 照常注入' );
	}

	public function test_adaptive_over_threshold_missing_stub_rejects_bad_signature(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log );
		$this->saturate( $mk['counters'] );

		$result = $mk['gate']->verify_orchestrate( 'admin', array(), self::server(), false, true );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'stub', $log[0]['stage'], '缺存根归 stub 阶段' );
		$this->assertSame( 'bad-signature', $log[0]['rule'] );
		$this->assertSame( 'wk_captcha_failed', $result['error_code'] );
		$this->assertNotSame( '', (string) $result['message'], 'WP_Error 文案非空（captcha_missing 档）' );
	}

	public function test_cross_gate_stub_is_rejected(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log );
		$this->saturate( $mk['counters'] );

		$hash = self::$stub_hash;
		$p    = Stub::encode_payload( 'comment', '42', true, 1731167000 ); // 评论闸存根混用。
		$result = $mk['gate']->verify_orchestrate( 'admin', array( 'wk_stub' => $p . '.' . $hash( $p ) ), self::server(), false, true );

		$this->assertSame( 'reject', $result['decision'], '跨表单 token 混用必拦（02 §3.3）' );
		$this->assertSame( 'bad-signature', $log[0]['rule'] );
	}

	public function test_stale_unshown_stub_over_threshold_rejects_captcha_missing(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log );
		$this->saturate( $mk['counters'] );

		// 存根 true（签发时未展示挑战）+ 现已超阈：对齐 CommentGate「强制验证码标志优先于存根 true」
		// 条款与 hCaptcha「签名不携带阈值豁免」语义——放行=给预收割干净存根开跳过通道。
		$result = $mk['gate']->verify_orchestrate( 'admin', array( 'wk_stub' => self::unshown_stub() ), self::server(), false, true );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'captcha', $log[0]['stage'] );
		$this->assertSame( 'captcha_missing', $log[0]['rule'] );
		$this->assertTrue( ! empty( $log[0]['meta']['stale_form'] ), 'meta 标注陈旧表单形态' );
	}

	public function test_over_threshold_missing_ticket_rejects_captcha_missing(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log );
		$this->saturate( $mk['counters'] );

		$result = $mk['gate']->verify_orchestrate( 'admin', array(
			'wk_stub' => self::shown_stub(),
		), self::server(), false, true );

		$this->assertSame( 'reject', $result['decision'], '展示过（存根 null）+ 票据缺 = captcha_missing' );
		$this->assertSame( 'captcha_missing', $log[0]['rule'] );
		$this->assertSame( 'fake-captcha', $log[0]['meta']['driver'] ?? '', 'meta 携带驱动 id（事件表 driver 列来源）' );
	}

	public function test_over_threshold_failed_verify_rejects_with_driver_message(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( '眨眼没对上——刷新页面再试一次？' ), $log );
		$this->saturate( $mk['counters'] );

		$result = $mk['gate']->verify_orchestrate( 'admin', array(
			'wk_stub'            => self::shown_stub(),
			'wk_captcha_ticket'  => 'bad-ticket',
			'wk_captcha_randstr' => 'rand',
		), self::server(), false, true );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'captcha', $log[0]['stage'] );
		$this->assertSame( 'captcha_failed', $log[0]['rule'], '无 last_verify_meta 的驱动回落 captcha_failed 档' );
		$this->assertStringContainsString( '眨眼没对上', (string) $result['message'], '驱动文案直通 WP_Error' );
		$this->assertSame( 'fake-captcha', $log[0]['meta']['driver'] ?? '' );
	}

	public function test_always_mode_verifies_without_counter_prerequisite(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log, array(
			'gates' => array( 'login' => array( 'enabled' => true, 'render_mode' => 'always', 'xmlrpc_login' => true ) ),
		) );
		// 不预置失败：always 无计数前置。

		$result = $mk['gate']->verify_orchestrate( 'admin', array(), self::server(), false, true );
		$this->assertSame( 'reject', $result['decision'], '常显：无存根同样拦（bad-signature）' );

		$log2 = array();
		$mk2  = $this->gate( $this->fake_captcha( null ), $log2, array(
			'gates' => array( 'login' => array( 'enabled' => true, 'render_mode' => 'always', 'xmlrpc_login' => true ) ),
		) );
		$pass = $mk2['gate']->verify_orchestrate( 'admin', array(
			'wk_stub'            => self::shown_stub(),
			'wk_captcha_ticket'  => 't',
			'wk_captcha_randstr' => 'r',
		), self::server(), false, true );
		$this->assertSame( 'allow', $pass['decision'], '常显：有效票放行' );
	}

	public function test_ip_aggregate_saturation_enforces_verify(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log );
		// 键 B 饱和：同 IP 10 个用户名各 1 次（键 A 每键 1 次、键 B 聚合 10 次达阈）。
		for ( $i = 1; $i <= 10; $i++ ) {
			$mk['counters']->record_failure( '203.0.113.7', 'spray_' . $i );
		}

		$result = $mk['gate']->verify_orchestrate( 'brand_new_user', array(), self::server(), false, true );

		$this->assertSame( 'reject', $result['decision'], 'IP 聚合饱和：新用户名同样强制验证（防 spray）' );
		$this->assertSame( 'bad-signature', $log[0]['rule'] );
	}

	/* ---------- $user 已 WP_Error 透传 / 豁免 ---------- */

	public function test_user_error_passes_through(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( '本不应被调用' ), $log );
		$this->saturate( $mk['counters'] );

		$result = $mk['gate']->verify_orchestrate( 'admin', array(), self::server(), true, true );

		$this->assertSame( 'allow', $result['decision'], '透传语义：不覆盖别人的错误（边界层原样返回）' );
		$this->assertSame( array(), $log );
	}

	public function test_protection_disabled_via_filter_allows_everything(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( '本不应被调用' ), $log );
		$this->saturate( $mk['counters'] );

		$result = $mk['gate']->verify_orchestrate( 'admin', array(), self::server(), false, false );

		$this->assertSame( 'allow', $result['decision'], 'wk_login_protection_enabled=false → 整体验证关闭' );
		$this->assertSame( array(), $log );
	}

	/* ---------- 未配置驱动：超阈也不拦（02 §2.1），记事件 meta ---------- */

	public function test_no_driver_over_threshold_allows_and_records_meta(): void {
		$log = array();
		$mk  = $this->gate( null, $log );
		$this->saturate( $mk['counters'] );

		$result = $mk['gate']->verify_orchestrate( 'admin', array(), self::server(), false, true );

		$this->assertSame( 'allow', $result['decision'], '未配置驱动 = captcha 阶段整体跳过（超阈不拦）' );
		$this->assertCount( 1, $log );
		$this->assertSame( 'captcha', $log[0]['stage'] );
		$this->assertSame( 'pass', $log[0]['verdict'] );
		$this->assertSame( 'no_driver', $log[0]['rule'] );
		$this->assertTrue( ! empty( $log[0]['meta']['skip_no_driver'] ), 'meta 标注跳过原因' );
	}

	public function test_unconfigured_driver_over_threshold_allows(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( '本不应被调用', false ), $log );
		$this->saturate( $mk['counters'] );

		$result = $mk['gate']->verify_orchestrate( 'admin', array(), self::server(), false, true );

		$this->assertSame( 'allow', $result['decision'], 'is_configured=false 等同未配置' );
		$this->assertSame( 'no_driver', $log[0]['rule'] );
	}

	/* ---------- 事件面：gate=login（记录器载荷） ---------- */

	public function test_reject_event_carries_client_ip_for_ip_hash(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log );
		$this->saturate( $mk['counters'] );

		$mk['gate']->verify_orchestrate( 'admin', array(), self::server(), false, true );

		$this->assertSame( '203.0.113.7', $log[0]['meta']['client_ip'], 'meta.client_ip 供默认记录器算 ip_hash（C1 移交缺口②补）' );
		$this->assertSame( 'direct', $log[0]['meta']['ip_src'] );
	}

	/* ---------- XML-RPC：超阈结构化拒绝决策 ---------- */

	public function test_xmlrpc_block_decision_matrix(): void {
		// adaptive 超阈（键 A）→ 拒。
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log );
		$this->saturate( $mk['counters'] );
		$this->assertTrue( $mk['gate']->xmlrpc_block_decision( 'admin', self::server(), true ) );

		// 未超阈 → 不拒。
		$log2 = array();
		$mk2  = $this->gate( $this->fake_captcha( null ), $log2 );
		$this->assertFalse( $mk2['gate']->xmlrpc_block_decision( 'admin', self::server(), true ) );

		// always 模式 → 恒拒（无计数前置）。
		$log3 = array();
		$mk3  = $this->gate( $this->fake_captcha( null ), $log3, array(
			'gates' => array( 'login' => array( 'enabled' => true, 'render_mode' => 'always', 'xmlrpc_login' => true ) ),
		) );
		$this->assertTrue( $mk3['gate']->xmlrpc_block_decision( 'admin', self::server(), true ) );

		// off / 未配置驱动 / 豁免 → 不拒。
		$log4 = array();
		$mk4  = $this->gate( null, $log4 );
		$this->saturate( $mk4['counters'] );
		$this->assertFalse( $mk4['gate']->xmlrpc_block_decision( 'admin', self::server(), true ), '未配置驱动：captcha 阶段跳过（含 XML-RPC 拒绝路径）' );

		$log5 = array();
		$mk5  = $this->gate( $this->fake_captcha( null ), $log5 );
		$this->saturate( $mk5['counters'] );
		$this->assertFalse( $mk5['gate']->xmlrpc_block_decision( 'admin', self::server(), false ), '豁免 filter 关闭 → 不拒' );
	}

	/* ---------- 渲染（03 §2.2「提交时唤起」隐藏态） ---------- */

	public function test_render_under_threshold_is_empty_zero_dom(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log );

		$this->assertSame( '', $mk['gate']->render_orchestrate( self::server(), true ), 'D9 零打扰：未超阈零 DOM' );
	}

	public function test_render_over_threshold_outputs_embed_login_gate(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log );
		$this->saturate( $mk['counters'] );

		$html = $mk['gate']->render_orchestrate( self::server(), true );

		$this->assertStringContainsString( 'data-gate="login"', $html, '复用驱动 render（gate=login）' );
		$this->assertStringContainsString( 'name="wk_stub"', $html, '签名存根隐藏域（gate=login, context_id=login）' );
		$this->assertStringContainsString( 'wk_captcha_ticket', $html, 'token 写域在 DOM' );
		$this->assertStringContainsString( 'data-app-id="190000000"', $html, 'appId 内联（公开级）' );
		$this->assertStringContainsString( 'aria-live="polite"', $html, '可访问性播报位（03 §2.2）' );
		// 【v1.4·R3/03 §2.2】嵌入式：wk-gate-login-status 承载组件 + .wk-gate-embed 挂载点
		// 直出（「隐藏包裹+提交时唤起」旧形态退役）。
		$this->assertStringContainsString( 'wk-gate-login-status', $html );
		$this->assertStringContainsString( '<div class="wk-gate-embed" hidden></div>', $html, '嵌入挂载点尾随（05 §1.1 gate 白名单）' );
		$this->assertStringContainsString( 'wk-captcha-btn', $html, '三态按钮可见（嵌入式形态）' );
		$this->assertStringNotContainsString( 'class="wk-gate-login" hidden', $html, '旧隐藏包裹形态已退役' );
	}

	public function test_render_always_mode_outputs_without_counter_prerequisite(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log, array(
			'gates' => array( 'login' => array( 'enabled' => true, 'render_mode' => 'always', 'xmlrpc_login' => true ) ),
		) );

		$this->assertStringContainsString( 'data-gate="login"', $mk['gate']->render_orchestrate( self::server(), true ) );
	}

	public function test_render_suppressed_for_off_unconfigured_or_exempted(): void {
		// off 模式。
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log, array(
			'gates' => array( 'login' => array( 'enabled' => true, 'render_mode' => 'off', 'xmlrpc_login' => true ) ),
		) );
		$this->saturate( $mk['counters'] );
		$this->assertSame( '', $mk['gate']->render_orchestrate( self::server(), true ) );

		// 未配置驱动（always 也不出码——渲染与验证同口径）。
		$log2 = array();
		$mk2  = $this->gate( null, $log2, array(
			'gates' => array( 'login' => array( 'enabled' => true, 'render_mode' => 'always', 'xmlrpc_login' => true ) ),
		) );
		$this->assertSame( '', $mk2['gate']->render_orchestrate( self::server(), true ) );

		// 豁免 filter。
		$log3 = array();
		$mk3  = $this->gate( $this->fake_captcha( null ), $log3 );
		$this->saturate( $mk3['counters'] );
		$this->assertSame( '', $mk3['gate']->render_orchestrate( self::server(), false ) );
	}

	public function test_render_stub_is_shown_state_for_login_context(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log );
		$this->saturate( $mk['counters'] );

		$html  = $mk['gate']->render_orchestrate( self::server(), true );
		$start = strpos( $html, 'name="wk_stub" value="' );
		$this->assertNotFalse( $start );
		$value = substr( $html, $start + 22, strpos( $html, '"', $start + 22 ) - ( $start + 22 ) );

		$state = Stub::verify( htmlspecialchars_decode( $value, ENT_QUOTES ), 'login', 'login', 1731168000, self::$stub_hash );
		$this->assertNull( $state, '登录存根 captcha_shown=true → null（必须验票）' );
	}

	/* ---------- 证据行 ---------- */

	public function test_reject_result_carries_evidence_and_error_code(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log );
		$this->saturate( $mk['counters'] );

		$result = $mk['gate']->verify_orchestrate( 'admin', array(), self::server(), false, true );

		$this->assertSame( 'wk_captcha_failed', $result['error_code'], '02 §3.2：new WP_Error( wk_captcha_failed, msg ) 的码' );
		$this->assertSame( 'WK-20261110-0001', $result['evidence']['事件编号'] );
		$this->assertArrayNotHasKey( 'ip', $result['evidence'], '证据行不暴露 IP' );
	}

	/* ---------- 【v1.4·R3/F39】渲染⇔验证不变式 + 站点警报 ---------- */

	/**
	 * 不变式核心：存根 null（本表单渲染过验证码）的提交**一律验票**——不再同时要求
	 * 计数超阈（站点警报下验证码已渲染、用户已完成挑战，跳过核验=装饰性验证码；02 §3.2）。
	 */
	public function test_invariant_shown_stub_verifies_even_under_threshold(): void {
		// 站点警报点亮（跨 IP 爆破 admin 饱和）——验证用户名 editor 非被攻击账户（两维度计数干净）。
		$alarm_counters = $this->alarm_lit_counters();

		$log  = array();
		$mk   = $this->gate( $this->fake_captcha( null ), $log, array(), $alarm_counters );

		// 缺票 → 拒（不变式：渲染过就验）。
		$missing = $mk['gate']->verify_orchestrate( 'editor', array(
			'wk_stub' => self::shown_stub(),
		), self::server(), false, true );
		$this->assertSame( 'reject', $missing['decision'], '警报下渲染过（null）+缺票 → 拦（不变式）' );
		$this->assertSame( 'captcha_missing', $log[0]['rule'] );
		$this->assertSame( 'alarm', $log[0]['meta']['trigger_source'], '触发归因=alarm（本 IP/本用户名计数未超）' );

		// 有效票 → 验票放行（不是恒拦——验票通过即放行）。
		$log2 = array();
		$mk2  = $this->gate( $this->fake_captcha( null ), $log2, array(), $this->alarm_lit_counters() );
		$pass = $mk2['gate']->verify_orchestrate( 'editor', array(
			'wk_stub'            => self::shown_stub(),
			'wk_captcha_ticket'  => 't',
			'wk_captcha_randstr' => 'r',
		), self::server(), false, true );
		$this->assertSame( 'allow', $pass['decision'], '不变式=验票（非恒拦）：有效票放行' );
		$this->assertSame( array(), $log2 );
	}

	public function test_stale_unshown_stub_under_threshold_allows_zero_disturbance(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( '本不应被调用' ), $log );
		// 不预置失败：未渲染且未超阈——stub true/false 均放行（预收割干净存根在未出码期无害）。

		$result = $mk['gate']->verify_orchestrate( 'admin', array( 'wk_stub' => self::unshown_stub() ), self::server(), false, true );

		$this->assertSame( 'allow', $result['decision'], '零打扰基线：未出码期陈旧干净存单放行' );
		$this->assertSame( array(), $log );
	}

	public function test_site_alarm_drives_render_on_clean_ip(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log, array(), $this->alarm_lit_counters() );

		$this->assertStringContainsString( 'data-gate="login"', $mk['gate']->render_orchestrate( self::server(), true ), '站点警报：干净 IP 也渲染（渲染期用户名维度唯一表达形态）' );
	}

	/* ---------- 【v1.4·R3/F39】验证期 flag 点亮闭环 ---------- */

	public function test_verify_rejection_lights_ip_flag_for_same_request_rerender(): void {
		// always 模式 + 干净计数：拒绝前 flag 未亮（饱和路径本身会亮 flag，此处隔离闭环入口）。
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log, array(
			'gates' => array( 'login' => array( 'enabled' => true, 'render_mode' => 'always', 'xmlrpc_login' => true ) ),
		) );
		$this->assertFalse( $mk['counters']->render_flag( '203.0.113.7' ), '拒绝前 flag 未亮' );

		$mk['gate']->verify_orchestrate( 'admin', array(), self::server(), false, true ); // always 拒绝（bad-signature）。

		$this->assertTrue( $mk['counters']->render_flag( '203.0.113.7' ), '拒绝后 flag 点亮——POST 失败同请求重渲染面板随错误框出现（一次循环收敛）' );
	}

	public function test_invariant_missing_ticket_rejection_also_lights_flag(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log, array(), $this->alarm_lit_counters() );

		$mk['gate']->verify_orchestrate( 'admin', array( 'wk_stub' => self::shown_stub() ), self::server(), false, true );

		$this->assertTrue( $mk['counters']->render_flag( '203.0.113.7' ), '不变式路径拒绝同样点亮 flag（闭环覆盖一切拒绝面）' );
	}

	/* ---------- 【v1.4·澄清A/F39】IP 信誉三态联动 ---------- */

	public function test_iprep_fail_drives_render_verify_and_xmlrpc_block(): void {
		$calls = 0;
		$rep   = $this->iprep( 'fail', $calls );

		// 渲染期：iprep fail → 出码（iprep_gate ∧ iprep_fail 进渲染条件）。
		$log  = array();
		$mk   = $this->gate( $this->fake_captcha( null ), $log, array(), null, $rep );
		$this->assertStringContainsString( 'data-gate="login"', $mk['gate']->render_orchestrate( self::server(), true ), 'iprep fail → 渲染期直接出码' );

		// 验证期：干净存根（true）+ iprep fail → 拒（require 集），meta.iprep_state=fail。
		$log2 = array();
		$mk2  = $this->gate( $this->fake_captcha( null ), $log2, array(), null, $rep );
		$result = $mk2['gate']->verify_orchestrate( 'admin', array( 'wk_stub' => self::unshown_stub() ), self::server(), false, true );
		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'iprep', $log2[0]['meta']['trigger_source'] );
		$this->assertSame( 'fail', $log2[0]['meta']['iprep_state'] );

		// XML-RPC：触发集扩展项。
		$this->assertTrue( $mk2['gate']->xmlrpc_block_decision( 'admin', self::server(), true ), 'iprep fail → XML-RPC 结构化拒绝（触发集扩展）' );
	}

	public function test_iprep_pass_follows_counter_rules(): void {
		$calls = 0;
		$rep   = $this->iprep( 'pass', $calls );

		$log = array();
		$mk  = $this->gate( $this->fake_captcha( '本不应被调用' ), $log, array(), null, $rep );

		$this->assertSame( '', $mk['gate']->render_orchestrate( self::server(), true ), 'iprep pass → 按计数（未超阈零 DOM）' );
		$this->assertSame( 'allow', $mk['gate']->verify_orchestrate( 'admin', array(), self::server(), false, true )['decision'], 'iprep pass → 未超阈放行' );
		$this->assertSame( array(), $log, 'iprep pass 不落库（04 §3 pass 默认不记）' );
	}

	public function test_iprep_gate_off_restores_pure_counter_behavior(): void {
		$calls    = 0;
		$no_gate  = array( 'captcha' => array( 'login_adaptive' => array(
			'user_threshold' => 3, 'window_minutes' => 15, 'ip_threshold' => 10, 'iprep_gate' => false,
		) ) );
		$rep      = $this->iprep( 'fail', $calls, $no_gate ); // probe 即便会 fail——联动关=skip。

		$log  = array();
		$mk   = $this->gate( $this->fake_captcha( '本不应被调用' ), $log, $no_gate, null, $rep );

		$this->assertSame( '', $mk['gate']->render_orchestrate( self::server(), true ), 'iprep_gate=false → 纯计数（现行行为完全恢复）' );
		$this->assertSame( 'allow', $mk['gate']->verify_orchestrate( 'admin', array(), self::server(), false, true )['decision'] );
		$this->assertFalse( $mk['gate']->xmlrpc_block_decision( 'admin', self::server(), true ) );
	}

	public function test_always_mode_skips_iprep_query(): void {
		$calls = 0;
		$rep   = $this->iprep( 'fail', $calls );

		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log, array(
			'gates' => array( 'login' => array( 'enabled' => true, 'render_mode' => 'always', 'xmlrpc_login' => true ) ),
		), null, $rep );

		$mk['gate']->render_orchestrate( self::server(), true );
		$mk['gate']->verify_orchestrate( 'admin', array(), self::server(), false, true );

		$this->assertSame( 0, $calls, 'always 恒出码使 IP 判定失去分支意义——零 iprep 查询（03 §2.2 渲染矩阵）' );
	}

	public function test_no_driver_iprep_fail_records_iprep_fail_no_driver_event(): void {
		$calls = 0;
		$rep   = $this->iprep( 'fail', $calls );

		$log = array();
		$mk  = $this->gate( null, $log, array(), null, $rep );

		$result = $mk['gate']->verify_orchestrate( 'admin', array(), self::server(), false, true );

		$this->assertSame( 'allow', $result['decision'], '未配置驱动：fail 不渲染不拦截（02 §2.1）' );
		$this->assertCount( 1, $log );
		$this->assertSame( 'iprep_fail_no_driver', $log[0]['rule'], 'fail 仅记事件留痕（02 §3.2）' );
		$this->assertSame( 'fail', $log[0]['meta']['iprep_state'] );
	}

	/* ---------- 【v1.4·R3】trigger_source 归因面 ---------- */

	public function test_trigger_source_attributions(): void {
		// user_threshold + username_dimension。
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log );
		$this->saturate( $mk['counters'] ); // 用户名维度饱和（纯用户名键）。
		$mk['gate']->verify_orchestrate( 'admin', array(), self::server(), false, true );
		$this->assertSame( 'user_threshold', $log[0]['meta']['trigger_source'] );
		$this->assertTrue( $log[0]['meta']['username_dimension'], '用户名维度计数路径标注' );

		// ip_threshold（IP 聚合饱和，验证用户名未累计）。
		$log2 = array();
		$c2   = $this->fresh_counters();
		for ( $i = 1; $i <= 10; $i++ ) {
			$c2->record_failure( '203.0.113.7', 'u' . $i );
		}
		$mk2 = $this->gate( $this->fake_captcha( null ), $log2, array(), $c2 );
		$mk2['gate']->verify_orchestrate( 'brand_new', array(), self::server(), false, true );
		$this->assertSame( 'ip_threshold', $log2[0]['meta']['trigger_source'] );

		// always。
		$log3 = array();
		$mk3  = $this->gate( $this->fake_captcha( null ), $log3, array(
			'gates' => array( 'login' => array( 'enabled' => true, 'render_mode' => 'always', 'xmlrpc_login' => true ) ),
		) );
		$mk3['gate']->verify_orchestrate( 'admin', array(), self::server(), false, true );
		$this->assertSame( 'always', $log3[0]['meta']['trigger_source'] );

		// stale_form（超阈下的陈旧干净存根）。
		$log4 = array();
		$mk4  = $this->gate( $this->fake_captcha( null ), $log4 );
		$this->saturate( $mk4['counters'] );
		$mk4['gate']->verify_orchestrate( 'admin', array( 'wk_stub' => self::unshown_stub() ), self::server(), false, true );
		$this->assertSame( 'user_threshold', $log4[0]['meta']['trigger_source'] );
		$this->assertTrue( $log4[0]['meta']['stale_form'], '陈旧表单形态标注' );
	}

	/* ---------- 【v1.4·R3】双信号计数编排（错误码分类消费） ---------- */

	public function test_record_auth_failure_dual_signal_composition(): void {
		$log = array();
		$mk  = $this->gate( $this->fake_captcha( null ), $log );
		$c   = $mk['counters'];

		// incorrect_password + 主信号（规范 user_login）：邮箱形态提交归并到登录名键。
		$mk['gate']->record_auth_failure( '203.0.113.7', 'admin@site.com', 'admin', 'incorrect_password' );
		$mk['gate']->record_auth_failure( '203.0.113.7', 'admin@site.com', 'admin', 'incorrect_password' );
		$this->assertFalse( $c->is_saturated( '198.51.100.9', 'admin' ), '2 次未超阈' );
		$mk['gate']->record_auth_failure( '198.51.100.9', 'admin', 'admin', 'incorrect_password' ); // 换 IP 第 3 次（跨 IP 累计）。
		$this->assertTrue( $c->is_saturated( '203.0.113.7', 'admin' ), '主信号规范键：邮箱提交与登录名提交归并同键' );

		// 主信号丢失回落提交串。
		$log2 = array();
		$mk2  = $this->gate( $this->fake_captcha( null ), $log2 );
		for ( $i = 0; $i < 3; $i++ ) {
			$mk2['gate']->record_auth_failure( '203.0.113.7', 'editor', null, 'incorrect_password' );
		}
		$this->assertTrue( $mk2['counters']->is_saturated( '198.51.100.9', 'editor' ), '副信号回落：sanitize_user 提交串计数' );

		// invalid_username：只计 IP（用户名键不建）。
		$log3 = array();
		$mk3  = $this->gate( $this->fake_captcha( null ), $log3 );
		for ( $i = 0; $i < 10; $i++ ) {
			$mk3['gate']->record_auth_failure( '203.0.113.7', 'nobody_' . $i, null, 'invalid_username' );
		}
		$this->assertTrue( $mk3['counters']->is_saturated( '203.0.113.7', 'somebody' ), 'unknown 只计 IP：IP 维度 10 次饱和' );
		$this->assertFalse( $mk3['counters']->is_saturated( '198.51.100.9', 'nobody_0' ), '用户名维度零累计' );

		// empty_*：不计数。
		$log4 = array();
		$mk4  = $this->gate( $this->fake_captcha( null ), $log4 );
		$mk4['gate']->record_auth_failure( '203.0.113.7', 'admin', 'admin', 'empty_password' );
		$this->assertFalse( $mk4['counters']->is_saturated( '203.0.113.7', 'admin' ), 'core 忽略清单：空提交天然不计数' );
	}

	/**
	 * 站点警报点亮的独立计数器（跨 IP 饱和 admin——当前验证 IP 203.0.113.7 计数干净）。
	 */
	private function alarm_lit_counters(): LoginCounters {
		$counters = $this->fresh_counters();
		for ( $i = 0; $i < 3; $i++ ) {
			$counters->record_failure( '198.51.100.' . ( $i + 1 ), 'admin' ); // 异 IP 饱和（用户名维度）。
		}
		return $counters;
	}

	/**
	 * 独立内存计数器（固定时钟）。
	 */
	private function fresh_counters(): LoginCounters {
		$clock = 1731168000;
		$store = array();
		return new LoginCounters(
			self::settings()['captcha']['login_adaptive'],
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
				return substr( hash_hmac( 'sha256', $data, 'login-gate-counters' ), 0, 16 );
			},
			static function () use ( &$clock ): int {
				return $clock;
			}
		);
	}

	/* ---------- M2 反馈2 T3：验证短缓存（登录闸消费） ---------- */

	/**
	 * 计数探针驱动（缓存命中断言 verify 未被调用）。
	 */
	private function counting_captcha( ?string $result, int &$calls ): object {
		return new class( $result, $calls ) implements \WinkGatekeeper\Drivers\CaptchaDriverInterface {
			private $result;
			private $calls;
			public function __construct( ?string $result, int &$calls ) {
				$this->result = $result;
				$this->calls  = &$calls;
			}
			public function render( array $args ): string {
				return '';
			}
			public function verify( array $context ): ?string {
				$this->calls++;
				return $this->result;
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
				return '探针驱动';
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
			/* 【v1.5·C1】接口 v1.2 能力面：假驱动沿 legacy 语义折算（verify_tri 委托 verify）。 */
			public function verify_tri( array $context ): array {
				return \WinkGatekeeper\Gates\CaptchaTriInterpreter::legacy_to_tri( $this->verify( $context ), method_exists( $this, 'last_verify_meta' ) ? $this->last_verify_meta() : array() );
			}
			public function errorPolicy(): string {
				return 'block';
			}
		};
	}

	private function gate_with_cache( ?object $captcha, array &$log, \WinkGatekeeper\Gates\VerifyCache $cache, LoginCounters $counters ): LoginGate {
		$clock = 1731168000;
		return new LoginGate(
			self::settings(),
			$captcha,
			static function ( string $stage, Verdict $verdict, array $meta ) use ( &$log ): string {
				$log[] = array( 'stage' => $stage, 'verdict' => $verdict->verdict(), 'rule' => $verdict->reason(), 'meta' => $meta );
				return 'WK-20261110-0001';
			},
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function () use ( $clock ): int {
				return $clock;
			},
			$counters,
			$cache
		);
	}

	private static function memory_cache( int $minutes, array &$store, array &$puts = array() ): \WinkGatekeeper\Gates\VerifyCache {
		return new \WinkGatekeeper\Gates\VerifyCache(
			$minutes,
			static function ( string $key ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$store, &$puts ): void {
				$store[ $key ] = $value;
				$puts[]        = array( 'key' => $key, 'ttl' => $ttl );
			},
			static function ( string $data ): string {
				return hash_hmac( 'sha256', $data, 'login-gate-vc-key' );
			}
		);
	}

	public function test_verify_cache_hit_skips_driver_and_records_pass_event(): void {
		$log   = array();
		$calls = 0;
		$store = array();
		$cache = self::memory_cache( 10, $store );
		$gate  = $this->gate_with_cache( $this->counting_captcha( '不应被调用', $calls ), $log, $cache, $this->saturated_counters() );

		// 预置：同存根+IP 已成功核验过（10 分钟窗口内）。
		$cache->remember( self::shown_stub(), '203.0.113.7' );

		$result = $gate->verify_orchestrate( 'admin', array(
			'wk_stub'            => self::shown_stub(),
			'wk_captcha_ticket'  => '已用过的票',
			'wk_captcha_randstr' => 'r',
		), self::server(), false, true );

		$this->assertSame( 'allow', $result['decision'], '登录闸缓存命中：免验票放行' );
		$this->assertSame( 0, $calls, '驱动 verify 零调用' );
		$this->assertCount( 1, $log );
		$this->assertSame( 'captcha', $log[0]['stage'] );
		$this->assertSame( 'pass', $log[0]['verdict'] );
		$this->assertSame( 'verify_cache_hit', $log[0]['rule'] );
		$this->assertTrue( $log[0]['meta']['verify_cache_hit'] );
	}

	public function test_verify_success_writes_verify_cache(): void {
		$log   = array();
		$calls = 0;
		$store = array();
		$puts  = array();
		$cache = self::memory_cache( 10, $store, $puts );
		$gate  = $this->gate_with_cache( $this->counting_captcha( null, $calls ), $log, $cache, $this->saturated_counters() );

		$gate->verify_orchestrate( 'admin', array(
			'wk_stub'            => self::shown_stub(),
			'wk_captcha_ticket'  => 'tok',
			'wk_captcha_randstr' => 'r',
		), self::server(), false, true );

		$this->assertSame( 1, $calls );
		$this->assertCount( 1, $puts, '登录核验成功 → 写缓存' );
		$this->assertSame( 600, $puts[0]['ttl'] );
		$mac = static function ( string $data ): string {
			return hash_hmac( 'sha256', $data, 'login-gate-vc-key' );
		};
		$this->assertSame( 'wk_cv_' . substr( $mac( self::shown_stub() . '|203.0.113.7' ), 0, 16 ), $puts[0]['key'] );
	}

	public function test_verify_cache_miss_proceeds_to_normal_verify(): void {
		$log   = array();
		$calls = 0;
		$store = array();
		$cache = self::memory_cache( 10, $store );
		$gate  = $this->gate_with_cache( $this->counting_captcha( null, $calls ), $log, $cache, $this->saturated_counters() );

		$result = $gate->verify_orchestrate( 'admin', array(
			'wk_stub'            => self::shown_stub(),
			'wk_captcha_ticket'  => 'tok',
			'wk_captcha_randstr' => 'r',
		), self::server(), false, true );

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertSame( 1, $calls, '未命中 → 正常验票' );
		$this->assertSame( array(), $log, '正常通过不落事件' );
	}

	/**
	 * 独立内存计数器（gate_with_cache 工厂不回传 counters，此处自建并预饱和）。
	 */
	private function saturated_counters(): LoginCounters {
		$clock = 1731168000;
		$store = array();
		$counters = new LoginCounters(
			self::settings()['captcha']['login_adaptive'],
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
				return substr( hash_hmac( 'sha256', $data, 'login-gate-counters' ), 0, 16 );
			},
			static function () use ( $clock ): int {
				return $clock;
			}
		);
		for ( $i = 0; $i < 3; $i++ ) {
			$counters->record_failure( '203.0.113.7', 'admin' );
		}
		return $counters;
	}
}
