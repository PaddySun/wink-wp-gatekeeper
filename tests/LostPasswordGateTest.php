<?php
/**
 * LostPasswordGate 单元测试（02 §3.2a——【v1.4·R3/F41/D17③】找回密码闸：常显无自适应；
 * lostpassword_post action 突变型；防枚举——错误文案恒同一条不区分用户有无）。
 *
 * 覆盖：常显渲染 / 存根三态（同 RegisterGate；恒渲染 true 态仍拦）/ 守卫（user_login
 * 在场 ∧ action ∈ {lostpassword, retrievepassword}——hCaptcha 同款）/ 防枚举（文案与
 * $user_data 存在性无关；user_present 只入事件 meta）/ 事件面（gate=lostpassword、
 * trigger_source=always）/ 豁免 filter（wk_lostpassword_protection_enabled）。
 *
 * WP 边界（$errors 突变 add、$_POST unset）不在纯逻辑单测环境执行。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\LostPasswordGate;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Intercept\Stub;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Verdict;

	final class LostPasswordGateTest extends TestCase {

		private static $stub_hash;

		protected function setUp(): void {
			$_SERVER['REMOTE_ADDR'] = '203.0.113.7'; // 纯逻辑面经 $_SERVER 缺省通道取 IP（WP 边界同源）。
		}

	public static function setUpBeforeClass(): void {
		self::$stub_hash = static function ( string $data ): string {
			return 'h_' . hash_hmac( 'sha256', $data, 'lostpassword-gate-test-key' );
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
				return '<span class="wk-gate" data-gate="' . (string) $args['gate'] . '" data-driver="fake" data-mode="embed">'
					. '<input type="hidden" name="wk_stub" value="' . $stub . '">'
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
		};
	}

	private static function settings( array $overrides = array() ): array {
		return array_merge( array(
			'gates' => array( 'lostpassword' => array( 'enabled' => true, 'render_mode' => 'always' ) ),
			'copy'  => Copywork::defaults(),
		), $overrides );
	}

	private function gate( ?object $captcha, array &$log, array $overrides = array() ): LostPasswordGate {
		$clock = 1731168000;
		return new LostPasswordGate(
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
		$p    = Stub::encode_payload( 'lostpassword', 'lostpassword', true, 1731167000 );
		return $p . '.' . $hash( $p );
	}

	/* ---------- 常显渲染 ---------- */

	public function test_render_always_when_enabled_and_configured(): void {
		$log = array();
		$g   = $this->gate( $this->fake_captcha( null ), $log );

		$html = $g->render_orchestrate( true );

		$this->assertStringContainsString( 'data-gate="lostpassword"', $html );
		$this->assertStringContainsString( 'name="wk_stub"', $html, '存根 gate=lostpassword、context_id=lostpassword' );
	}

	public function test_render_empty_when_disabled_or_unconfigured(): void {
		$log = array();
		$g   = $this->gate( $this->fake_captcha( null ), $log, array( 'gates' => array( 'lostpassword' => array( 'enabled' => false, 'render_mode' => 'always' ) ) ) );
		$this->assertSame( '', $g->render_orchestrate( true ) );

		$log2 = array();
		$g2   = $this->gate( null, $log2 );
		$this->assertSame( '', $g2->render_orchestrate( true ), '未配驱动 inert' );
	}

	/* ---------- 验证（lostpassword_post 纯逻辑面） ---------- */

	public function test_verify_missing_stub_rejects_bad_signature(): void {
		$log = array();
		$g   = $this->gate( $this->fake_captcha( null ), $log );

		$result = $g->verify_orchestrate( array( 'user_login' => 'someone' ), 'lostpassword', true );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'stub', $log[0]['stage'] );
		$this->assertSame( 'bad-signature', $log[0]['rule'] );
		$this->assertSame( 'wk_bad_signature', $result['error_code'] );
	}

	public function test_verify_shown_stub_without_ticket_rejects_captcha_missing(): void {
		$log = array();
		$g   = $this->gate( $this->fake_captcha( null ), $log );

		$result = $g->verify_orchestrate( array( 'user_login' => 'someone', 'wk_stub' => self::shown_stub() ), 'lostpassword', true );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'captcha_missing', $log[0]['rule'] );
	}

	public function test_verify_valid_ticket_allows(): void {
		$log = array();
		$g   = $this->gate( $this->fake_captcha( null ), $log );

		$result = $g->verify_orchestrate( array(
			'user_login'         => 'someone',
			'wk_stub'            => self::shown_stub(),
			'wk_captcha_ticket'  => 'ticket-ok',
			'wk_captcha_randstr' => 'rand-ok',
		), 'lostpassword', true );

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertSame( array(), $log );
	}

	/* ---------- 守卫（hCaptcha 对齐：user_login 在场 ∧ action 双值） ---------- */

	public function test_guards_skip_when_user_login_absent(): void {
		$log = array();
		$g   = $this->gate( $this->fake_captcha( null ), $log );

		$result = $g->verify_orchestrate( array(), 'lostpassword', true );

		$this->assertSame( 'allow', $result['decision'], 'user_login 缺席=非找回提交（守卫跳过）' );
		$this->assertSame( array(), $log, '守卫跳过零事件' );
	}

	public function test_guards_accept_both_actions_but_reject_others(): void {
		foreach ( array( 'lostpassword', 'retrievepassword' ) as $action ) {
			$log = array();
			$g   = $this->gate( $this->fake_captcha( null ), $log );
			$this->assertSame( 'reject', $g->verify_orchestrate( array( 'user_login' => 'someone' ), $action, true )['decision'], "action={$action} 两别名都覆盖（wp-login.php:826-827）" );
		}

		$log2 = array();
		$g2   = $this->gate( $this->fake_captcha( null ), $log2 );
		$this->assertSame( 'allow', $g2->verify_orchestrate( array( 'user_login' => 'someone' ), 'login', true )['decision'], '非找回 action（login）不设闸——本闸不覆盖 resetpass/rp' );
		$this->assertSame( array(), $log2 );
	}

	/* ---------- 防枚举（错误文案恒同一条，不区分用户有无） ---------- */

	public function test_error_message_identical_whether_user_exists_or_not(): void {
		$log1 = array();
		$g1   = $this->gate( $this->fake_captcha( null ), $log1 );
		$r1   = $g1->verify_orchestrate( array( 'user_login' => 'someone' ), 'lostpassword', true, true );  // 用户存在。

		$log2 = array();
		$g2   = $this->gate( $this->fake_captcha( null ), $log2 );
		$r2   = $g2->verify_orchestrate( array( 'user_login' => 'someone' ), 'lostpassword', true, false ); // 用户不存在。

		$this->assertSame( 'reject', $r1['decision'] );
		$this->assertSame( 'reject', $r2['decision'], '不存在账号也必须过码（lostpassword_post 先于 core invalidcombo 判定触发）' );
		$this->assertSame( $r1['message'], $r2['message'], '防枚举：错误文案恒同一条（不以错误响应探测用户名存在性）' );
		$this->assertSame( $r1['error_code'], $r2['error_code'] );

		// user_data 存在性只入事件 meta（站长侧可观测，访客侧无差）。
		$this->assertTrue( $log1[0]['meta']['user_present'] );
		$this->assertFalse( $log2[0]['meta']['user_present'] );
	}

	/* ---------- 事件面 ---------- */

	public function test_reject_events_carry_gate_meta(): void {
		$log = array();
		$g   = $this->gate( $this->fake_captcha( null ), $log );

		$g->verify_orchestrate( array( 'user_login' => 'someone' ), 'lostpassword', true, true );

		$this->assertSame( 'always', $log[0]['meta']['trigger_source'] ?? null, '常显闸归因=always' );
		$this->assertSame( '203.0.113.7', $log[0]['meta']['client_ip'] ?? '' );
	}

	/**
	 * 【B3 真机缺陷回归 2026-11-10】Bootstrap::enqueue_login_assets 多态调用面契约——
	 * 0.2.2 首部署 wp-login 渲染期 PHP Fatal（should_render 缺法），锁定不可再漏。
	 */
	public function test_bootstrap_enqueue_contract_methods_present(): void {
		$this->assertTrue( method_exists( LostPasswordGate::class, 'should_render' ), 'should_render：enqueue_login_assets 多态调用面' );
		$this->assertTrue( method_exists( LostPasswordGate::class, 'captcha_driver' ), 'captcha_driver：enqueue_login_assets 多态调用面' );

		$log    = array();
		$driver = $this->fake_captcha( null );
		$g      = $this->gate( $driver, $log );
		$this->assertSame( $driver, $g->captcha_driver(), 'captcha_driver() 返回注入驱动（基类单点）' );
	}

	public function test_verify_disabled_gate_or_filter_allows_silently(): void {
		$log = array();
		$g   = $this->gate( $this->fake_captcha( '本不应被调用' ), $log, array( 'gates' => array( 'lostpassword' => array( 'enabled' => false, 'render_mode' => 'always' ) ) ) );
		$this->assertSame( 'allow', $g->verify_orchestrate( array( 'user_login' => 'someone' ), 'lostpassword', true )['decision'] );
		$this->assertSame( array(), $log );

		$log2 = array();
		$g2   = $this->gate( $this->fake_captcha( '本不应被调用' ), $log2 );
		$this->assertSame( 'allow', $g2->verify_orchestrate( array( 'user_login' => 'someone' ), 'lostpassword', false )['decision'], '豁免 filter（wk_lostpassword_protection_enabled）' );
		$this->assertSame( array(), $log2 );
	}
}
