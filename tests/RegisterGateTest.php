<?php
/**
 * RegisterGate 单元测试（02 §3.2a——【v1.4·R3/F41/D17③】注册闸：常显无自适应；
 * registration_errors filter 返回型；WP_Error 注入不 wp_die）。
 *
 * 覆盖：常显渲染（enabled+驱动已配置 → gate=register 存根 + embed 组件 DOM；关/inert
 * 零 DOM）/ 存根三态（false=bad-signature、true=captcha_missing（本闸恒渲染——
 * true 态=缓存页/预收割仍拦）、null=取票验票）/ 验证短缓存复用 / 驱动验票失败文案
 * 直通 / token 规范键映射（credentialFields→{ticket,randstr}，D2 基类单点）/
 * 事件面（gate=register、stage=stub|captcha、verdict=reject、meta.trigger_source=always）/
 * 豁免 filter（wk_register_protection_enabled）。
 *
 * WP 边界（registration_errors 的 $errors->add/return、$_POST unset）不在纯逻辑单测环境执行。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\RegisterGate;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Intercept\Stub;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Verdict;

	final class RegisterGateTest extends TestCase {

		private static $stub_hash;

		protected function setUp(): void {
			$_SERVER['REMOTE_ADDR'] = '203.0.113.7'; // 纯逻辑面经 $_SERVER 缺省通道取 IP（WP 边界同源）。
		}

	public static function setUpBeforeClass(): void {
		self::$stub_hash = static function ( string $data ): string {
			return 'h_' . hash_hmac( 'sha256', $data, 'register-gate-test-key' );
		};
	}

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
				return '<span class="wk-gate" data-gate="' . (string) $args['gate'] . '" data-driver="fake" data-mode="embed" data-app-id="190000000">'
					. '<input type="hidden" name="wk_stub" value="' . $stub . '">'
					. '<input type="hidden" name="wk_captcha_ticket" value=""><input type="hidden" name="wk_captcha_randstr" value="">'
					. '<button type="button" class="wk-captcha-btn">完成人机验证</button></span>'
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
			'gates' => array( 'register' => array( 'enabled' => true, 'render_mode' => 'always' ) ),
			'copy'  => Copywork::defaults(),
		), $overrides );
	}

	/**
	 * @param object|null $captcha 假驱动。
	 * @param array       &$log    事件捕获。
	 * @param array       $overrides 设置覆盖。
	 * @return RegisterGate
	 */
	private function gate( ?object $captcha, array &$log, array $overrides = array() ): RegisterGate {
		$clock = 1731168000;
		return new RegisterGate(
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
			}
		);
	}

	private static function shown_stub(): string {
		$hash = self::$stub_hash;
		$p    = Stub::encode_payload( 'register', 'register', true, 1731167000 );
		return $p . '.' . $hash( $p );
	}

	private static function server(): array {
		return array( 'REMOTE_ADDR' => '203.0.113.7' );
	}

	private static function unshown_stub(): string {
		$hash = self::$stub_hash;
		$p    = Stub::encode_payload( 'register', 'register', false, 1731167000 );
		return $p . '.' . $hash( $p );
	}

	/* ---------- 常显渲染（render_mode 恒 always；未配驱动 inert） ---------- */

	public function test_render_always_when_enabled_and_configured(): void {
		$log = array();
		$g   = $this->gate( $this->fake_captcha( null ), $log );

		$html = $g->render_orchestrate( true );

		$this->assertStringContainsString( 'data-gate="register"', $html, '复用驱动 render（gate=register）' );
		$this->assertStringContainsString( 'name="wk_stub"', $html, '签名存根隐藏域（gate=register, context_id=register, shown=true）' );
		$this->assertStringContainsString( 'wk_captcha_ticket', $html, 'token 写域在 DOM' );
		$this->assertStringContainsString( 'wk-gate-login-status', $html, 'aria-live 播报位（03 §2.3 与登录页同形态）' );
	}

	public function test_render_empty_when_disabled_unconfigured_or_exempted(): void {
		// 闸开关关。
		$log = array();
		$g   = $this->gate( $this->fake_captcha( null ), $log, array( 'gates' => array( 'register' => array( 'enabled' => false, 'render_mode' => 'always' ) ) ) );
		$this->assertSame( '', $g->render_orchestrate( true ), '闸关=零 DOM' );

		// 未配验证码驱动自动 inert（03 Tab②：不渲染不拦截）。
		$log2 = array();
		$g2   = $this->gate( null, $log2 );
		$this->assertSame( '', $g2->render_orchestrate( true ), '未配驱动 inert' );

		$log3 = array();
		$g3   = $this->gate( $this->fake_captcha( null ), $log3 );
		$this->assertSame( '', $g3->render_orchestrate( false ), '豁免 filter（wk_register_protection_enabled=false）' );
	}

	public function test_render_stub_is_shown_state_for_register_context(): void {
		$log = array();
		$g   = $this->gate( $this->fake_captcha( null ), $log );

		$html  = $g->render_orchestrate( true );
		$start = strpos( $html, 'name="wk_stub" value="' );
		$this->assertNotFalse( $start );
		$value = substr( $html, $start + 22, strpos( $html, '"', $start + 22 ) - ( $start + 22 ) );

		$state = Stub::verify( htmlspecialchars_decode( $value, ENT_QUOTES ), 'register', 'register', 1731168000, self::$stub_hash );
		$this->assertNull( $state, '注册存根 captcha_shown=true → null（恒验票）' );
	}

	/* ---------- 验证（registration_errors 纯逻辑面） ---------- */

	public function test_verify_missing_stub_rejects_bad_signature(): void {
		$log = array();
		$g   = $this->gate( $this->fake_captcha( null ), $log );

		$result = $g->verify_orchestrate( array(), true );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'stub', $log[0]['stage'], '缺存根归 stub 阶段' );
		$this->assertSame( 'bad-signature', $log[0]['rule'] );
		$this->assertSame( 'wk_bad_signature', $result['error_code'], '错误注入码（WP_Error add 用）' );
		$this->assertNotSame( '', (string) $result['message'] );
	}

	public function test_verify_cross_gate_stub_rejected(): void {
		$log = array();
		$g   = $this->gate( $this->fake_captcha( null ), $log );

		$hash = self::$stub_hash;
		$p    = Stub::encode_payload( 'login', 'login', true, 1731167000 ); // 登录闸存根混用。
		$result = $g->verify_orchestrate( array( 'wk_stub' => $p . '.' . $hash( $p ) ), true );

		$this->assertSame( 'reject', $result['decision'], '跨闸存根混用必拦（02 §3.3）' );
		$this->assertSame( 'bad-signature', $log[0]['rule'] );
	}

	public function test_verify_stale_unshown_stub_rejects_captcha_missing(): void {
		$log = array();
		$g   = $this->gate( $this->fake_captcha( null ), $log );

		// 存根 true（签发时未展示挑战）——本闸恒渲染：true 态=缓存页/预收割，仍拦（02 §3.2a）。
		$result = $g->verify_orchestrate( array( 'wk_stub' => self::unshown_stub() ), true );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'captcha', $log[0]['stage'] );
		$this->assertSame( 'captcha_missing', $log[0]['rule'] );
		$this->assertSame( 'wk_captcha_missing', $result['error_code'] );
	}

	public function test_verify_shown_stub_without_ticket_rejects_captcha_missing(): void {
		$log = array();
		$g   = $this->gate( $this->fake_captcha( null ), $log );

		$result = $g->verify_orchestrate( array( 'wk_stub' => self::shown_stub() ), true );

		$this->assertSame( 'reject', $result['decision'], '展示过（null）+ 票据缺 = captcha_missing' );
		$this->assertSame( 'captcha_missing', $log[0]['rule'] );
	}

	public function test_verify_failed_ticket_rejects_with_driver_message(): void {
		$log = array();
		$g   = $this->gate( $this->fake_captcha( '眨眼没对上——刷新页面再试一次？' ), $log );

		$result = $g->verify_orchestrate( array(
			'wk_stub'            => self::shown_stub(),
			'wk_captcha_ticket'  => 'bad-ticket',
			'wk_captcha_randstr' => 'rand',
		), true );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'captcha', $log[0]['stage'] );
		$this->assertSame( 'captcha_failed', $log[0]['rule'], '无 last_verify_meta 驱动回落 captcha_failed 档' );
		$this->assertStringContainsString( '眨眼没对上', (string) $result['message'], '驱动文案直通 WP_Error 注入' );
	}

	public function test_verify_valid_ticket_allows_without_events(): void {
		$log = array();
		$g   = $this->gate( $this->fake_captcha( null ), $log );

		$result = $g->verify_orchestrate( array(
			'wk_stub'            => self::shown_stub(),
			'wk_captcha_ticket'  => 'ticket-ok',
			'wk_captcha_randstr' => 'rand-ok',
		), true );

		$this->assertSame( 'allow', $result['decision'], '有效票放行（注册流程继续）' );
		$this->assertSame( array(), $log, 'pass 不落库（04 §3 写入矩阵 register/lostpassword 行：pass ✗）' );
	}

	public function test_verify_passes_canonical_ticket_keys_to_driver(): void {
		$captcha = new class implements \WinkGatekeeper\Drivers\CaptchaDriverInterface {
			public $received = null;
			public function render( array $args ): string {
				return '';
			}
			public function verify( array $context ): ?string {
				$this->received = $context;
				return null;
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
		$g   = $this->gate( $captcha, $log );

		$g->verify_orchestrate( array(
			'wk_stub'            => self::shown_stub(),
			'wk_captcha_ticket'  => 'tk',
			'wk_captcha_randstr' => 'rs',
		), true );

		$this->assertSame( 'tk', $captcha->received['ticket'] ?? null, 'context 携带规范键 ticket（AccountFormGate 基类单点，D2）' );
		$this->assertSame( 'rs', $captcha->received['randstr'] ?? null, 'context 携带规范键 randstr' );
		$this->assertSame( '203.0.113.7', $captcha->received['user_ip'] ?? null, 'user_ip 注入（$_SERVER 固定 REMOTE_ADDR）' );
	}

	public function test_verify_disabled_gate_or_filter_or_no_driver_allows_silently(): void {
		$log = array();
		$g   = $this->gate( $this->fake_captcha( '本不应被调用' ), $log, array( 'gates' => array( 'register' => array( 'enabled' => false, 'render_mode' => 'always' ) ) ) );
		$this->assertSame( 'allow', $g->verify_orchestrate( array(), true )['decision'], '闸关：跳过（用户创建照常）' );
		$this->assertSame( array(), $log );

		$log2 = array();
		$g2   = $this->gate( $this->fake_captcha( '本不应被调用' ), $log2 );
		$this->assertSame( 'allow', $g2->verify_orchestrate( array(), false )['decision'], '豁免 filter：跳过' );
		$this->assertSame( array(), $log2 );

		$log3 = array();
		$g3   = $this->gate( null, $log3 );
		$this->assertSame( 'allow', $g3->verify_orchestrate( array(), true )['decision'], '未配驱动 inert（不拦截注册）' );
		$this->assertSame( array(), $log3 );
	}

	/* ---------- 事件面（gate=register / trigger_source=always） ---------- */

	public function test_reject_events_carry_trigger_source_always(): void {
		$log = array();
		$g   = $this->gate( $this->fake_captcha( null ), $log );

		$result = $g->verify_orchestrate( array(), true );

		$this->assertSame( 'always', $log[0]['meta']['trigger_source'] ?? null, '常显闸归因=always（02 §3.2 事件可观测性）' );
		$this->assertSame( '203.0.113.7', $log[0]['meta']['client_ip'] ?? '', 'meta.client_ip 供默认记录器算 ip_hash' );
		$this->assertSame( 'WK-20261110-0001', $result['evidence']['事件编号'] ?? '', '证据行携带事件编号' );
	}

	/* ---------- 验证短缓存复用（Bootstrap 单例装配同一实例） ---------- */

	public function test_verify_cache_hit_skips_driver(): void {
		$clock = 1731168000;
		$store = array();
		$cache = new \WinkGatekeeper\Gates\VerifyCache(
			10,
			static function ( string $key ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$store ): void {
				$store[ $key ] = $value;
			},
			static function ( string $data ): string {
				return hash_hmac( 'sha256', $data, 'register-gate-vc-key' );
			}
		);
		$calls = 0;
		$captcha = new class( $calls ) implements \WinkGatekeeper\Drivers\CaptchaDriverInterface {
			private $calls;
			public function __construct( int &$calls ) {
				$this->calls = &$calls;
			}
			public function render( array $args ): string {
				return '';
			}
			public function verify( array $context ): ?string {
				$this->calls++;
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
		$log  = array();
		$g    = new RegisterGate(
			self::settings(),
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
			$cache
		);

		$cache->remember( self::shown_stub(), '203.0.113.7' ); // 同存根+IP 已成功核验过。

		$result = $g->verify_orchestrate( array(
			'wk_stub'            => self::shown_stub(),
			'wk_captcha_ticket'  => '已用过的票',
			'wk_captcha_randstr' => 'r',
		), true );

		$this->assertSame( 'allow', $result['decision'], '短缓存命中：免验票放行' );
		$this->assertSame( 0, $calls, '驱动 verify 零调用' );
		$this->assertCount( 1, $log );
		$this->assertSame( 'verify_cache_hit', $log[0]['rule'] );
	}

	/**
	 * 【B3 真机缺陷回归 2026-11-10】Bootstrap::enqueue_login_assets 对三闸多态调用
	 * should_render()/captcha_driver()——0.2.2 首部署 wp-login 渲染期 PHP Fatal
	 * （Call to undefined method RegisterGate::should_render()，单测纯逻辑面不可达）。
	 * 契约锁定：方法在场 + 基类抽象强制 + captcha_driver() 返回注入实例。
	 */
	public function test_bootstrap_enqueue_contract_methods_present(): void {
		$this->assertTrue( method_exists( RegisterGate::class, 'should_render' ), 'should_render：enqueue_login_assets 多态调用面' );
		$this->assertTrue( method_exists( RegisterGate::class, 'captcha_driver' ), 'captcha_driver：enqueue_login_assets 多态调用面' );

		$abstract = new \ReflectionMethod( \WinkGatekeeper\Gates\AccountFormGate::class, 'should_render' );
		$this->assertTrue( $abstract->isAbstract(), 'AccountFormGate::should_render 抽象声明在位——未来闸（M4）不可漏' );

		$log    = array();
		$driver = $this->fake_captcha( null );
		$g      = $this->gate( $driver, $log );
		$this->assertSame( $driver, $g->captcha_driver(), 'captcha_driver() 返回注入驱动（基类单点）' );
	}
}
