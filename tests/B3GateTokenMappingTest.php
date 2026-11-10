<?php
/**
 * B3 闸 token 域映射测试（05 §1.2 registry 行「闸映射 wk_captcha_verify_param→规范键」；
 * M2-FIX2 D2 教训的第三域扩展——单票据驱动接入三闸零漏抄）。
 *
 * 覆盖：
 *   - AbstractGate::canonical_token_key 命名映射（ticket/randstr/verify_param 三域 + 开放键）；
 *   - CommentGate captcha 段：单票据驱动（credentialFields=['wk_captcha_verify_param']）
 *     的表单域 → context['captcha_verify_param'] 映射 + 缺票 captcha_missing；
 *   - AccountFormGate::build_verify_context：同一映射在账户族闸单点生效。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\CaptchaDriverInterface;
use WinkGatekeeper\Gates\AbstractGate;
use WinkGatekeeper\Gates\AccountFormGate;
use WinkGatekeeper\Gates\CommentGate;
use WinkGatekeeper\Gates\VerifyCache;
use WinkGatekeeper\Intercept\Stub;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Verdict;

final class B3GateTokenMappingTest extends TestCase {

	/* ---------- 规范键映射（纯函数） ---------- */

	public function test_canonical_token_key_named_map(): void {
		$this->assertSame( 'ticket', AbstractGate::canonical_token_key( 'wk_captcha_ticket' ) );
		$this->assertSame( 'randstr', AbstractGate::canonical_token_key( 'wk_captcha_randstr' ) );
		$this->assertSame( 'captcha_verify_param', AbstractGate::canonical_token_key( 'wk_captcha_verify_param' ) );
		// 开放键集：未知字段按原名透传（未来驱动自定义凭据域不受限）。
		$this->assertSame( 'wk_custom_field', AbstractGate::canonical_token_key( 'wk_custom_field' ) );
	}

	/* ---------- 测试驱动（单票据 + 记录收到的 context） ---------- */

	private function single_field_captcha( array $tri, ?\stdClass $sink = null, ?array $fields = null ): object {
		return new class( $tri, $sink, $fields ?? array( 'wk_captcha_verify_param' ) ) implements CaptchaDriverInterface {
			public $calls = 0;
			private $tri;
			private $sink;
			private $fields;
			public function __construct( array $tri, ?\stdClass $sink, array $fields ) {
				$this->tri    = $tri;
				$this->sink   = $sink;
				$this->fields = $fields;
			}
			public function render( array $args ): string {
				return '<span class="wk-gate"></span>';
			}
			public function verify_tri( array $context ): array {
				$this->calls++;
				if ( null !== $this->sink ) {
					$this->sink->context = $context;
				}
				return $this->tri;
			}
			public function is_configured(): bool {
				return true;
			}
			public function enqueue_assets(): void {}
			public function id(): string {
				return 'fake-aliyun';
			}
			public function label(): string {
				return '假阿里驱动';
			}
			public function credentialFields(): array {
				return $this->fields;
			}
			public function failurePolicy(): array {
				return array();
			}
			public function disasterPolicy(): string {
				return 'none';
			}
			public function errorPolicy(): string {
				return 'block';
			}
		};
	}

	/* ---------- CommentGate：单票据驱动 context 映射 ---------- */

	private static $stub_hash;

	public static function setUpBeforeClass(): void {
		self::$stub_hash = static function ( string $data ): string {
			return 'h_' . hash_hmac( 'sha256', $data, 'b3-mapping-key' );
		};
	}

	private static function now(): int {
		return 1731168000;
	}

	private static function valid_stub(): string {
		$hash    = self::$stub_hash;
		$payload = Stub::encode_payload( 'comment', '42', true, self::now() - 1000 );
		return $payload . '.' . $hash( $payload );
	}

	private function comment_gate( object $captcha, array &$log ): CommentGate {
		return new CommentGate(
			array(
				'gates'   => array( 'comment' => array( 'enabled' => true, 'render_mode' => 'always' ) ),
				'captcha' => array( 'min_score' => 0.5 ),
				'review'  => array( 'local' => array(
					'min_length' => 0, 'max_length' => 1800, 'require_chinese' => 1,
					'custom_banned_words' => '', 'use_wp_keywords' => 1,
				) ),
				'copy'    => array( 'messages' => array(
					'captcha_missing' => '还差一个眨眼——完成上方的验证再点提交。',
					'captcha_failed'  => '人机验证没有通过。',
				) ),
				'stats'   => array( 'enabled' => true, 'pass_sampling' => false ),
			),
			$captcha,
			null,
			static function ( string $stage, Verdict $verdict, array $meta ) use ( &$log ): string {
				$log[] = array( 'stage' => $stage, 'verdict' => $verdict->verdict(), 'rule' => $verdict->reason() );
				return 'WK-20261111-0002';
			},
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return self::now();
			},
			null,
			null,
			array(),
			null,
			null,
			null,
			null,
			null,
			null
		);
	}

	public function test_comment_gate_maps_verify_param_to_canonical_key(): void {
		$seen    = new \stdClass();
		$captcha = $this->single_field_captcha( array(
			'state' => 'pass', 'score' => 1.0, 'message' => '', 'meta' => array( 'rule' => 'VerifyCode=T001' ),
		), $seen );
		$log = array();
		$gate = $this->comment_gate( $captcha, $log );

		$request = array(
			'comment_post_ID'         => '42',
			'comment_content'         => '这是一条正常的中文评论',
			'wk_stub'                 => self::valid_stub(),
			'wk_captcha_verify_param' => 'b64TOKEN',
		);
		$result = $gate->orchestrate( array( 'comment_content' => '这是一条正常的中文评论' ), $request, array( 'REMOTE_ADDR' => '203.0.113.7' ) );

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertSame( 1, $captcha->calls, 'verify_tri 收到规范键映射后的 context' );
		$this->assertSame( 'b64TOKEN', $seen->context['captcha_verify_param'] ?? null, '闸映射 wk_captcha_verify_param→captcha_verify_param' );
		$this->assertSame( '203.0.113.7', $seen->context['user_ip'] ?? null );
	}

	public function test_comment_gate_missing_single_token_rejects_captcha_missing(): void {
		$seen    = new \stdClass();
		$captcha = $this->single_field_captcha( array( 'state' => 'pass', 'score' => 1.0, 'message' => '', 'meta' => array() ), $seen );
		$log = array();
		$gate = $this->comment_gate( $captcha, $log );

		$request = array(
			'comment_post_ID' => '42',
			'comment_content' => '这是一条正常的中文评论',
			'wk_stub'         => self::valid_stub(),
		);
		$result = $gate->orchestrate( array( 'comment_content' => '这是一条正常的中文评论' ), $request, array( 'REMOTE_ADDR' => '203.0.113.7' ) );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'captcha_missing', $log[0]['rule'] ?? '' );
		$this->assertNull( $seen->context ?? null, '缺票不进 verify_tri' );
	}

	/* ---------- AccountFormGate：同一映射单点生效 ---------- */

	public function test_account_form_gate_build_verify_context_maps_named_fields(): void {
		$seen    = new \stdClass();
		// 三域全声明（假想混合驱动）：命名映射逐域生效 + 开放域透传 + 天御双域零回归。
		$captcha = $this->single_field_captcha(
			array( 'state' => 'pass', 'score' => 1.0, 'message' => '', 'meta' => array() ),
			$seen,
			array( 'wk_captcha_ticket', 'wk_captcha_randstr', 'wk_captcha_verify_param' )
		);

		$gate = new class( $captcha ) extends AccountFormGate {
			public function __construct( ?CaptchaDriverInterface $captcha ) {
				parent::__construct(
					array( 'gates' => array( 'register' => array( 'enabled' => true ) ) ),
					$captcha,
					static function ( string $stage, Verdict $verdict, array $meta ): ?string {
						unset( $stage, $verdict, $meta );
						return null;
					}
				);
			}
			public function settings_key(): string {
				return 'register';
			}
			public function context_id(): string {
				return 'register';
			}
			public function should_render(): bool {
				return true;
			}
			public function render_hooks(): array {
				return array();
			}
			public function verify_hooks(): array {
				return array();
			}
			public function build( array $request ): array {
				return $this->build_verify_context( $request );
			}
		};

		$built = $gate->build( array( 'wk_captcha_verify_param' => 'b64X', 'wk_captcha_ticket' => 'tk', 'wk_captcha_randstr' => 'rs' ) );
		$this->assertSame( 'b64X', $built['context']['captcha_verify_param'] ?? null );
		$this->assertSame( 'tk', $built['context']['ticket'] ?? null, '天御双域映射零回归' );
		$this->assertSame( 'rs', $built['context']['randstr'] ?? null );
		$this->assertTrue( $built['has_ticket'] );

		$missing = $gate->build( array( 'wk_captcha_ticket' => 'tk', 'wk_captcha_randstr' => '' ) );
		$this->assertFalse( $missing['has_ticket'], '任一凭据域空 = 缺票（既有语义不变）' );
	}
}
