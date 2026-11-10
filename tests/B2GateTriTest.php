<?php
/**
 * B2 三闸三态消费测试（02 §2.1【v1.5·C1】映射表落地面 + §2.8 test_mode 旁路 + 豁免过滤器）。
 *
 * 覆盖：
 *   - CommentGate/LoginGate/RegisterGate captcha 段统一经 CaptchaTriInterpreter 消费 verify_tri：
 *     error 态 → 事件 verdict=error（行为变化点 1：统计纠偏）、reject/pass 分流不变、
 *     pass 写 VerifyCache、error+pass_limited 降级放行不写缓存；
 *   - test_mode 旁路（五件套 #2/#5）：三闸真验票位直通 pass、drill 事件（driver=gate、
 *     meta.drill+test_mode）、不发远端调用、不写 VerifyCache、24h 懒过期后旁路失效；
 *   - 豁免过滤器 wink_gatekeeper_require_gate（#6）：豁免=等同未配置驱动语义
 *     （整体跳过 captcha 阶段、不渲染不验证）；XML-RPC 恒结构化拒绝不受豁免影响。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\CaptchaDriverInterface;
use WinkGatekeeper\Gates\CommentGate;
use WinkGatekeeper\Gates\LoginCounters;
use WinkGatekeeper\Gates\LoginGate;
use WinkGatekeeper\Gates\RegisterGate;
use WinkGatekeeper\Gates\VerifyCache;
use WinkGatekeeper\Intercept\Stub;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Verdict;

final class B2GateTriTest extends TestCase {

	private static $stub_hash;

	public static function setUpBeforeClass(): void {
		self::$stub_hash = static function ( string $data ): string {
			return 'h_' . hash_hmac( 'sha256', $data, 'b2-tri-test-key' );
		};
	}

	/**
	 * 三态可编程假驱动（verify_tri 返回可设定；调用计数可观测）。
	 */
	private function tri_captcha( array $tri, int $min_score_unused = 0 ): object {
		return new class( $tri ) implements CaptchaDriverInterface {
			public $calls = 0;
			private $tri;
			public function __construct( array $tri ) {
				$this->tri = $tri;
			}
			public function render( array $args ): string {
				return '<span class="wk-gate" data-gate="' . ( isset( $args['gate'] ) ? (string) $args['gate'] : '' ) . '"></span>';
			}
			public function verify_tri( array $context ): array {
				$this->calls++;
				return $this->tri;
			}
			public function is_configured(): bool {
				return true;
			}
			public function enqueue_assets(): void {}
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
			public function errorPolicy(): string {
				return 'block';
			}
		};
	}

	private static function memory_cache( array &$sink ): VerifyCache {
		return new VerifyCache(
			10,
			static function ( string $key ) use ( &$sink ) {
				return array_key_exists( $key, $sink ) ? $sink[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$sink ): void {
				$sink[ $key ] = $value;
			},
			static function ( string $data ): string {
				return hash_hmac( 'sha256', $data, 'b2-cache-key' );
			}
		);
	}

	private function recorder( array &$log ): callable {
		return static function ( string $stage, Verdict $verdict, array $meta ) use ( &$log ): string {
			$log[] = array( 'stage' => $stage, 'verdict' => $verdict->verdict(), 'rule' => $verdict->reason(), 'driver' => $verdict->driver_id(), 'meta' => $meta );
			return 'WK-20261111-0001';
		};
	}

	private static function now(): int {
		return 1731168000;
	}

	private static function valid_stub( string $gate, string $context_id ): string {
		$hash = self::$stub_hash;
		$payload = Stub::encode_payload( $gate, $context_id, true, self::now() - 1000 );
		return $payload . '.' . $hash( $payload );
	}

	private static function comment_settings( array $overrides = array() ): array {
		return array_merge( array(
			'gates'   => array( 'comment' => array( 'enabled' => true, 'render_mode' => 'always' ) ),
			'captcha' => array( 'min_score' => 0.5 ),
			'review'  => array( 'local' => array(
				'min_length' => 0, 'max_length' => 1800, 'require_chinese' => 1,
				'custom_banned_words' => '', 'use_wp_keywords' => 1,
			) ),
			'copy'    => array( 'messages' => array(
				'captcha_missing' => '还差一个眨眼——完成上方的验证再点提交。',
				'captcha_failed'  => '人机验证没有通过——{rule}。请刷新页面再试。',
			) ),
			'stats'   => array( 'enabled' => true, 'pass_sampling' => false ),
		), $overrides );
	}

	private function comment_gate( object $captcha, array &$log, array $overrides = array(), ?callable $require_gate = null, ?callable $degraded_limiter = null, ?VerifyCache $cache = null ): CommentGate {
		return new CommentGate(
			self::comment_settings( $overrides ),
			$captcha,
			null,
			$this->recorder( $log ),
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
			$cache,
			null,
			null,
			null,
			$require_gate,
			$degraded_limiter
		);
	}

	private static function comment_request( array $overrides = array() ): array {
		return array_merge( array(
			'comment_post_ID'     => '42',
			'comment_content'     => '这是一条正常的中文评论',
			'wk_stub'             => self::valid_stub( 'comment', '42' ),
			'wk_captcha_ticket'   => 'tok',
			'wk_captcha_randstr'  => 'r',
		), $overrides );
	}

	private static function server(): array {
		return array( 'REMOTE_ADDR' => '203.0.113.7' );
	}

	/* ---------- CommentGate：error 态（行为变化点 1——统计纠偏） ---------- */

	public function test_comment_remote_error_records_error_verdict_and_blocks(): void {
		$log    = array();
		$captcha = $this->tri_captcha( array(
			'state'   => 'error',
			'score'   => null,
			'message' => '验证服务暂时打不开，请稍后再试。',
			'meta'    => array( 'category' => 'transport', 'rule' => 'remote_transport', 'raw' => array( 'http' => 0 ) ),
		) );
		$gate = $this->comment_gate( $captcha, $log );

		$result = $gate->orchestrate( array( 'comment_content' => '这是一条正常的中文评论' ), self::comment_request(), self::server() );

		$this->assertSame( 'reject', $result['decision'], 'error 态落地仍拦截（默认 block）' );
		$this->assertSame( 'captcha', $log[0]['stage'] );
		$this->assertSame( Verdict::ERROR, $log[0]['verdict'], '行为变化点 1：故障事件 verdict 由 reject 改 error' );
		$this->assertSame( 'remote_transport', $log[0]['rule'] );
		$this->assertSame( 'fake-captcha', $log[0]['driver'] );
		$this->assertStringContainsString( '稍后再试', $result['message'] );
		$this->assertSame( 1, $captcha->calls );
	}

	/* ---------- CommentGate：reject 态（业务拒绝语义不变） ---------- */

	public function test_comment_driver_reject_keeps_reject_verdict(): void {
		$log    = array();
		$captcha = $this->tri_captcha( array(
			'state'   => 'reject',
			'score'   => null,
			'message' => '票据过期——刷新页面再试一次。',
			'meta'    => array( 'rule' => 'CaptchaCode=8', 'CaptchaCode' => 8 ),
		) );
		$gate = $this->comment_gate( $captcha, $log );

		$result = $gate->orchestrate( array( 'comment_content' => '这是一条正常的中文评论' ), self::comment_request(), self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( Verdict::REJECT, $log[0]['verdict'] );
		$this->assertSame( 'CaptchaCode=8', $log[0]['rule'] );
		$this->assertStringContainsString( '票据过期', $result['message'], '驱动终态文案直通' );
	}

	/* ---------- CommentGate：评分拒（score<阈值 → 闸侧 captcha_failed 文案链） ---------- */

	public function test_comment_score_below_threshold_rejects_with_gate_copy(): void {
		$log    = array();
		$captcha = $this->tri_captcha( array(
			'state'   => 'pass',
			'score'   => 0.2,
			'message' => '',
			'meta'    => array( 'rule' => 'RiskScore' ),
		) );
		$gate = $this->comment_gate( $captcha, $log, array( 'captcha' => array( 'min_score' => 0.5 ) ) );

		$result = $gate->orchestrate( array( 'comment_content' => '这是一条正常的中文评论' ), self::comment_request(), self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( Verdict::REJECT, $log[0]['verdict'] );
		$this->assertSame( 'captcha_failed', $log[0]['rule'] );
		$this->assertSame( 0.2, $log[0]['meta']['score'], 'reject meta 记 score' );
		$this->assertSame( 0.5, $log[0]['meta']['threshold'], 'reject meta 记 threshold' );
		$this->assertStringContainsString( '人机验证没有通过', $result['message'], 'user_retry 档回落闸 captcha_failed 文案链' );
	}

	/* ---------- CommentGate：pass 写缓存 / 降级放行不写缓存 ---------- */

	public function test_comment_pass_writes_verify_cache(): void {
		$log             = array();
		$store = array();
		$cache = self::memory_cache( $store );
		$captcha = $this->tri_captcha( array( 'state' => 'pass', 'score' => 1.0, 'message' => '', 'meta' => array( 'rule' => 'CaptchaCode=1' ) ) );
		$gate    = $this->comment_gate( $captcha, $log, array(), null, null, $cache );

		$result = $gate->orchestrate( array( 'comment_content' => '这是一条正常的中文评论' ), self::comment_request(), self::server() );

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertNotSame( array(), $store, 'pass 态写 VerifyCache（只存事实）' );
		$this->assertSame( 0, count( $log ), '真验 pass 不落事件（现行口径）' );
	}

	public function test_comment_pass_limited_degraded_allows_without_cache_write(): void {
		$log    = array();
		$store = array();
		$cache = self::memory_cache( $store );
		$captcha = $this->tri_captcha( array(
			'state'   => 'error',
			'score'   => null,
			'message' => '验证服务暂时打不开。',
			'meta'    => array( 'category' => 'transport', 'rule' => 'remote_transport' ),
		) );
		$gate = $this->comment_gate(
			$captcha,
			$log,
			array( 'captcha' => array( 'min_score' => 0.5, 'error_policy' => 'pass_limited' ) ),
			null,
			static function ( string $ip ): bool {
				return true; // 限流内。
			},
			$cache
		);

		$result = $gate->orchestrate( array( 'comment_content' => '这是一条正常的中文评论' ), self::comment_request(), self::server() );

		$this->assertSame( 'allow', $result['decision'], '降级放行（落地）' );
		$this->assertSame( Verdict::ERROR, $log[0]['verdict'], '事件 verdict=error 照落' );
		$this->assertSame( 'remote_transport_degraded', $log[0]['rule'] );
		$this->assertSame( array(), $store, '降级放行不写 VerifyCache' );
	}

	/* ---------- CommentGate：test_mode 旁路（五件套 #2/#5） ---------- */

	public function test_comment_test_mode_bypasses_without_driver_call_or_cache_write(): void {
		$log    = array();
		$store = array();
		$cache = self::memory_cache( $store );
		$captcha = $this->tri_captcha( array( 'state' => 'reject', 'score' => null, 'message' => '不应被调用', 'meta' => array() ) );
		$gate    = $this->comment_gate(
			$captcha,
			$log,
			array( 'captcha' => array( 'min_score' => 0.5, 'test_mode' => true, 'test_mode_since' => self::now() - 100 ) ),
			null,
			null,
			$cache
		);

		// 无票提交（真人漏点按钮形态）——旁路下仍放行。
		$request = self::comment_request( array( 'wk_captcha_ticket' => '', 'wk_captcha_randstr' => '' ) );
		$result  = $gate->orchestrate( array( 'comment_content' => '这是一条正常的中文评论' ), $request, self::server() );

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertSame( 0, $captcha->calls, '旁路不发远端调用（drill 期零厂商计费）' );
		$this->assertSame( array(), $store, '旁路期不写 VerifyCache（五件套之五）' );
		$this->assertCount( 1, $log );
		$this->assertSame( 'captcha', $log[0]['stage'] );
		$this->assertSame( Verdict::PASS, $log[0]['verdict'] );
		$this->assertSame( 'test_mode_pass', $log[0]['rule'] );
		$this->assertSame( 'gate', $log[0]['driver'], '旁路不是驱动产物（trust_pass 先例）' );
		$this->assertTrue( ! empty( $log[0]['meta']['drill'] ), 'meta.drill 标注' );
		$this->assertTrue( ! empty( $log[0]['meta']['test_mode'] ) );
	}

	public function test_comment_test_mode_off_still_requires_ticket(): void {
		$log    = array();
		$captcha = $this->tri_captcha( array( 'state' => 'pass', 'score' => 1.0, 'message' => '', 'meta' => array() ) );
		$gate    = $this->comment_gate( $captcha, $log );

		$request = self::comment_request( array( 'wk_captcha_ticket' => '', 'wk_captcha_randstr' => '' ) );
		$result  = $gate->orchestrate( array( 'comment_content' => '这是一条正常的中文评论' ), $request, self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'captcha_missing', $log[0]['rule'] );
		$this->assertSame( 0, $captcha->calls );
	}

	public function test_comment_test_mode_lazy_expired_stops_bypassing(): void {
		$log    = array();
		$captcha = $this->tri_captcha( array( 'state' => 'pass', 'score' => 1.0, 'message' => '', 'meta' => array() ) );
		$gate    = $this->comment_gate(
			$captcha,
			$log,
			array( 'captcha' => array( 'min_score' => 0.5, 'test_mode' => true, 'test_mode_since' => self::now() - 86401 ) )
		);

		$request = self::comment_request( array( 'wk_captcha_ticket' => '', 'wk_captcha_randstr' => '' ) );
		$result  = $gate->orchestrate( array( 'comment_content' => '这是一条正常的中文评论' ), $request, self::server() );

		$this->assertSame( 'reject', $result['decision'], '24h 懒过期后旁路失效（安全门 #4）' );
		$this->assertSame( 'captcha_missing', $log[0]['rule'] );
	}

	/* ---------- CommentGate：豁免过滤器（#6） ---------- */

	public function test_comment_gate_exemption_skips_captcha_stage_entirely(): void {
		$log    = array();
		$captcha = $this->tri_captcha( array( 'state' => 'reject', 'score' => null, 'message' => '不应被调用', 'meta' => array() ) );
		$gate    = $this->comment_gate( $captcha, $log, array(), static function ( bool $required, string $gate, array $context ): bool {
			return false; // 第三方插件豁免评论闸。
		} );

		// 有存根有票的完整提交——豁免下 captcha 阶段整体跳过（等同未配置驱动语义）。
		$result = $gate->orchestrate( array( 'comment_content' => '这是一条正常的中文评论' ), self::comment_request(), self::server() );

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertSame( 0, $captcha->calls, '豁免不验证' );
		$this->assertSame( array(), $log, '豁免路径 captcha 零事件；LocalRules/云审照跑（本例 pass 不落库）' );
	}

	public function test_comment_gate_exemption_still_runs_local_rules(): void {
		$log    = array();
		$captcha = $this->tri_captcha( array( 'state' => 'pass', 'score' => 1.0, 'message' => '', 'meta' => array() ) );
		$gate    = $this->comment_gate( $captcha, $log, array(), static function (): bool {
			return false;
		} );

		// 豁免的是「要求验证码」不是「放弃防护」——LocalRules 照拦。
		$result = $gate->orchestrate( array( 'comment_content' => 'x' ), self::comment_request(), self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'review', $log[0]['stage'], '内容闸阶段照常' );
	}

	public function test_comment_gate_no_exemption_by_default_in_pure_env(): void {
		// 默认 callable：纯单测环境（无 apply_filters）= 不豁免（现行行为零回归）。
		$log    = array();
		$captcha = $this->tri_captcha( array( 'state' => 'pass', 'score' => 1.0, 'message' => '', 'meta' => array() ) );
		$gate    = $this->comment_gate( $captcha, $log );

		$request = self::comment_request( array( 'wk_captcha_ticket' => '', 'wk_captcha_randstr' => '' ) );
		$result  = $gate->orchestrate( array( 'comment_content' => '这是一条正常的中文评论' ), $request, self::server() );

		$this->assertSame( 'reject', $result['decision'] );
	}

	/* ---------- LoginGate：test_mode 旁路 + 豁免 + XML-RPC 恒拒 ---------- */

	private function login_settings( array $overrides = array() ): array {
		return array_merge( array(
			'gates'   => array( 'login' => array( 'enabled' => true, 'render_mode' => 'always', 'xmlrpc_login' => true ) ),
			'captcha' => array( 'min_score' => 0.5, 'login_adaptive' => array(
				'user_threshold' => 3, 'window_minutes' => 15, 'ip_threshold' => 10, 'iprep_gate' => true,
			) ),
			'copy'    => \WinkGatekeeper\Intercept\Copywork::defaults(),
		), $overrides );
	}

	private function login_gate( object $captcha, array &$log, array $overrides = array(), ?callable $require_gate = null ): LoginGate {
		return new LoginGate(
			$this->login_settings( $overrides ),
			$captcha,
			$this->recorder( $log ),
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return self::now();
			},
			new LoginCounters(
				$this->login_settings( $overrides )['captcha']['login_adaptive'],
				static function ( string $key ) {
					return null;
				},
				static function ( string $key, $value, int $ttl ): void {},
				static function ( string $key ): void {},
				static function ( string $data ): string {
					return substr( hash_hmac( 'sha256', $data, 'b2-login' ), 0, 16 );
				},
				static function (): int {
					return self::now();
				}
			),
			null,
			null,
			$require_gate
		);
	}

	private function login_request( array $overrides = array() ): array {
		return array_merge( array(
			'wk_stub'            => self::valid_stub( 'login', 'login' ),
			'wk_captcha_ticket'  => 'tok',
			'wk_captcha_randstr' => 'r',
			'log'                => '',
			'pwd'                => 'x',
		), $overrides );
	}

	public function test_login_test_mode_bypasses_ticket_verification(): void {
		$log    = array();
		$captcha = $this->tri_captcha( array( 'state' => 'reject', 'score' => null, 'message' => '不应被调用', 'meta' => array() ) );
		$gate    = $this->login_gate(
			$captcha,
			$log,
			array( 'captcha' => array( 'min_score' => 0.5, 'login_adaptive' => array( 'user_threshold' => 3, 'window_minutes' => 15, 'ip_threshold' => 10, 'iprep_gate' => true ), 'test_mode' => true, 'test_mode_since' => self::now() - 100 ) )
		);

		$request = $this->login_request( array( 'wk_captcha_ticket' => '', 'wk_captcha_randstr' => '' ) );
		$result  = $gate->verify_orchestrate( 'admin', $request, self::server(), false, true );

		$this->assertSame( 'allow', $result['decision'], '常显模式 + 旁路 → 直通（不进正常认证拒绝链）' );
		$this->assertSame( 0, $captcha->calls );
		$this->assertSame( 'test_mode_pass', $log[0]['rule'] );
		$this->assertTrue( ! empty( $log[0]['meta']['drill'] ) );
	}

	public function test_login_error_state_records_error_verdict(): void {
		$log    = array();
		$captcha = $this->tri_captcha( array(
			'state'   => 'error',
			'score'   => null,
			'message' => '验证服务暂时打不开。',
			'meta'    => array( 'category' => 'transport', 'rule' => 'remote_transport' ),
		) );
		$gate = $this->login_gate( $captcha, $log );

		$result = $gate->verify_orchestrate( 'admin', $this->login_request(), self::server(), false, true );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( Verdict::ERROR, $log[0]['verdict'], '登录闸同构：故障事件 verdict=error' );
		$this->assertSame( 'remote_transport', $log[0]['rule'] );
	}

	public function test_login_exemption_skips_captcha_but_xmlrpc_still_blocked(): void {
		$log    = array();
		$captcha = $this->tri_captcha( array( 'state' => 'reject', 'score' => null, 'message' => '不应被调用', 'meta' => array() ) );
		$gate    = $this->login_gate( $captcha, $log, array(), static function (): bool {
			return false; // 豁免。
		} );

		$result = $gate->verify_orchestrate( 'admin', $this->login_request(), self::server(), false, true );
		$this->assertSame( 'allow', $result['decision'], '豁免=等同未配置驱动（验证期不拦截）' );
		$this->assertSame( 0, $captcha->calls );

		// XML-RPC 恒结构化拒绝不受豁免影响（02 §2.1 v1.5 豁免条款）——over 阈值仍拒。
		$this->assertTrue(
			$gate->xmlrpc_block_decision( 'admin', self::server(), true ),
			'常显模式下 XML-RPC 不因豁免 filter 放开'
		);
	}

	/* ---------- RegisterGate：test_mode 旁路 ---------- */

	public function test_register_test_mode_bypasses_missing_ticket(): void {
		$log    = array();
		$captcha = $this->tri_captcha( array( 'state' => 'pass', 'score' => 1.0, 'message' => '', 'meta' => array() ) );
		$gate    = new RegisterGate(
			array_merge( self::comment_settings(), array(
				'gates'   => array( 'register' => array( 'enabled' => true, 'render_mode' => 'always' ) ),
				'captcha' => array( 'min_score' => 0.5, 'test_mode' => true, 'test_mode_since' => self::now() - 100 ),
			) ),
			$captcha,
			$this->recorder( $log ),
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return self::now();
			}
		);

		$request = array(
			'wk_stub'            => self::valid_stub( 'register', 'register' ),
			'wk_captcha_ticket'  => '',
			'wk_captcha_randstr' => '',
		);
		$result = $gate->verify_orchestrate( $request, true );

		$this->assertSame( 'allow', $result['decision'], '常显闸旁路直通（无票也放行）' );
		$this->assertSame( 0, $captcha->calls );
		$this->assertSame( 'test_mode_pass', $log[0]['rule'] );
		$this->assertTrue( ! empty( $log[0]['meta']['drill'] ) );
	}

	public function test_register_exemption_skips_captcha_stage(): void {
		$log    = array();
		$captcha = $this->tri_captcha( array( 'state' => 'reject', 'score' => null, 'message' => '不应被调用', 'meta' => array() ) );
		$gate    = new RegisterGate(
			array_merge( self::comment_settings(), array(
				'gates' => array( 'register' => array( 'enabled' => true, 'render_mode' => 'always' ) ),
			) ),
			$captcha,
			$this->recorder( $log ),
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return self::now();
			},
			null,
			static function (): bool {
				return false; // 豁免。
			}
		);

		$result = $gate->verify_orchestrate( array( 'wk_stub' => self::valid_stub( 'register', 'register' ) ), true );
		$this->assertSame( 'allow', $result['decision'] );
		$this->assertSame( 0, $captcha->calls );
		$this->assertSame( array(), $log );
	}
}
