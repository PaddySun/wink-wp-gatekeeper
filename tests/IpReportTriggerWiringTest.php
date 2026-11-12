<?php
/**
 * report 触发点接线单元测试（M3-IP 定稿 §4.2 触发集 T1–T4 精确接线——I3 批）。
 *
 * 四触发点消费形态=instanceof 能力探测（O2-P2-7 范式——闸持有的 IpReputationProbe
 * 兼实现 SupportsReportingInterface 时方可上报，Null/未装配=零上报面零行为变化）：
 *   T1 captcha_failed   CommentGate captcha 阶段有票核验失败（无票 captcha_missing 不触发；
 *                       error 态不触发——「有票才报」纵深只认伪造/重放票的 reject）；
 *   T2 review_reject    CommentGate 云端审核 reject（driver≠local_rules）；
 *   T4 local_rules_fail CommentGate 步骤 2.5 本地四闸 reject + Precheck 预检四闸 reject
 *                       （两处消费点幂等计数——同请求各恰一次）；
 *   T3 login_attempts   LoginGate 双信号计数 IP 聚合维度饱和判定处（复用 LoginCounters
 *                       ip_threshold=10 零新增计数器；用户名维度饱和不触发）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\CaptchaDriverInterface;
use WinkGatekeeper\Drivers\Review\LocalRulesDriver;
use WinkGatekeeper\Drivers\ReviewDriverInterface;
use WinkGatekeeper\Gates\CaptchaTriInterpreter;
use WinkGatekeeper\Gates\CommentTrust;
use WinkGatekeeper\Gates\LoginCounters;
use WinkGatekeeper\Gates\LoginGate;
use WinkGatekeeper\Gates\LoginIpRep;
use WinkGatekeeper\Gates\Precheck;
use WinkGatekeeper\Gates\TrustStub;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Intercept\Stub;
use WinkGatekeeper\Ip\IpReputationProbe;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Ip\SupportsReportingInterface;
use WinkGatekeeper\Verdict;

final class IpReportTriggerWiringTest extends TestCase {

	private const NOW = 1731417600;
	private const IP  = '203.0.113.7';

	private static $stub_hash;
	private static $trust_hash;
	private static $mac;

	public static function setUpBeforeClass(): void {
		self::$stub_hash = static function ( string $data ): string {
			return 's_' . hash_hmac( 'sha256', $data, 'i3-wiring-stub-key' );
		};
		self::$trust_hash = static function ( string $data ): string {
			return 't_' . hash_hmac( 'sha256', $data, 'i3-wiring-trust-key' );
		};
		self::$mac = static function ( string $data ): string {
			return substr( hash_hmac( 'sha256', $data, 'i3-wiring-mac-key' ), 0, 16 );
		};
	}

	/* -------------------------------------------------------------------
	 * 假件：可上报探测服务（instanceof 能力探测的靶件）
	 * ------------------------------------------------------------------ */

	/**
	 * 双接口假件（IpReputationProbe + SupportsReportingInterface——O2-P2-7 探测靶）。
	 *
	 * @param ?Verdict                       $probe_verdict probe 返回值（null=skip）。
	 * @param array<int, array<string,mixed>> &$reports     report_ip 调用捕获。
	 */
	private function fake_reporter( ?Verdict $probe_verdict, array &$reports ): object {
		return new class( $probe_verdict, $reports ) implements IpReputationProbe, SupportsReportingInterface {
			private $verdict;
			private $reports_ref;
			public function __construct( ?Verdict $verdict, array &$reports ) {
				$this->verdict     = $verdict;
				$this->reports_ref = &$reports;
			}
			public function probe( string $ip, array $ctx = array() ): ?Verdict {
				return $this->verdict;
			}
			public function report_ip( string $ip, array $context ): bool {
				$this->reports_ref[] = array( 'ip' => $ip, 'context' => $context );
				return true;
			}
		};
	}

	/** 纯 probe 假件（不带上报能力——instanceof 探测必须为 false）。 */
	private function plain_probe( ?Verdict $verdict ): IpReputationProbe {
		return new class( $verdict ) implements IpReputationProbe {
			private $verdict;
			public function __construct( ?Verdict $verdict ) {
				$this->verdict = $verdict;
			}
			public function probe( string $ip, array $ctx = array() ): ?Verdict {
				return $this->verdict;
			}
		};
	}

	/* -------------------------------------------------------------------
	 * T1/T2/T4：CommentGate 接线
	 * ------------------------------------------------------------------ */

	private function fake_captcha( ?string $result, ?int &$verify_calls = null ): CaptchaDriverInterface {
		return new class( $result, $verify_calls ) implements CaptchaDriverInterface {
			private $result;
			private $calls;
			public function __construct( ?string $result, ?int &$verify_calls ) {
				$this->result = $result;
				$this->calls  = &$verify_calls;
			}
			public function render( array $args ): string {
				$stub = isset( $args['stub'] ) ? (string) $args['stub'] : '';
				return '<span class="wk-gate" data-gate="comment" data-driver="fake" data-interaction="click" data-mode="embed">'
					. '<input type="hidden" name="wk_stub" value="' . $stub . '">'
					. '<button class="wk-captcha-btn">完成人机验证</button></span>';
			}
			public function verify( array $context ): ?string {
				$this->calls = ( null === $this->calls ? 0 : $this->calls ) + 1;
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
				return CaptchaTriInterpreter::legacy_to_tri( $this->verify( $context ), array() );
			}
			public function errorPolicy(): string {
				return 'block';
			}
		};
	}

	private function gate( array $settings, ?CaptchaDriverInterface $captcha, array &$log, $iprep = null, array $cloud_chain = array() ): \WinkGatekeeper\Gates\CommentGate {
		return new \WinkGatekeeper\Gates\CommentGate(
			$settings,
			$captcha,
			null,
			static function ( string $stage, Verdict $verdict, array $meta ) use ( &$log ): string {
				$log[] = array( 'stage' => $stage, 'verdict' => $verdict->verdict(), 'rule' => $verdict->reason(), 'driver' => $verdict->driver_id(), 'meta' => $meta );
				return 'WK-20261112-0100';
			},
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return self::NOW;
			},
			static function (): string {
				return '42';
			},
			null,
			$cloud_chain,
			null,
			null,
			null,
			$iprep,
			self::$mac
		);
	}

	private static function settings( array $overrides = array() ): array {
		return array_merge( array(
			'gates'  => array( 'comment' => array( 'enabled' => true, 'render_mode' => 'always' ) ),
			'review' => array(
				'include_author_fields' => true,
				'local' => array( 'min_length' => 5, 'max_length' => 1800, 'require_chinese' => 1, 'custom_banned_words' => '', 'use_wp_keywords' => 0 ),
				'cloud' => array( 'enabled' => true, 'chain' => array( 'fake_cloud' ) ),
			),
			'captcha' => array( 'trust' => array( 'enabled' => true, 'min_approved' => 3, 'ttl_minutes' => 10 ) ),
			'copy'    => array( 'messages' => array( 'too_short' => '写到 {min_length} 字再试试' ) ),
			'stats'   => array( 'enabled' => true, 'pass_sampling' => false ),
		), $overrides );
	}

	private static function request( array $overrides = array() ): array {
		return array_merge( array(
			'comment_post_ID'      => '42',
			'comment_content'      => '这是一条足够长的正常中文评论',
			'comment_author'       => '老读者',
			'comment_author_email' => 'reader@example.com',
		), $overrides );
	}

	private static function server(): array {
		return array( 'REMOTE_ADDR' => self::IP );
	}

	/** 展示过存根（captcha_shown=true → verify 态 null=必须验票路径）。 */
	private static function shown_stub(): string {
		$hash    = self::$stub_hash;
		$payload = Stub::encode_payload( 'comment', '42', true, self::NOW - 100 );
		return $payload . '.' . $hash( $payload );
	}

	public function test_t1_forged_ticket_reject_fires_report_with_event_no(): void {
		$reports   = array();
		$log       = array();
		$verify    = 0;
		$reporter  = $this->fake_reporter( null, $reports ); // probe=null（未启用）——只验上报面。
		$gate      = $this->gate( self::settings(), $this->fake_captcha( 'ticket rejected', $verify ), $log, $reporter );

		$request = self::request( array(
			'wk_stub'             => self::shown_stub(),
			'wk_captcha_ticket'   => 'forged-ticket',
			'wk_captcha_randstr'  => 'x',
		) );
		$result = $gate->orchestrate( $request, $request, self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertCount( 1, $reports, '有票核验失败（reject）→ T1 恰一次' );
		$this->assertSame( self::IP, $reports[0]['ip'] );
		$this->assertSame( 'captcha_failed', $reports[0]['context']['trigger'] );
		$this->assertSame( 'comment', $reports[0]['context']['gate'] );
		$this->assertSame( 'WK-20261112-0100', $reports[0]['context']['event_no'], 'context 携事件编号（{event_no} 渲染所需）' );
	}

	public function test_t1_missing_ticket_captcha_missing_does_not_fire(): void {
		$reports  = array();
		$log      = array();
		$verify   = 0;
		$reporter = $this->fake_reporter( null, $reports );
		$gate     = $this->gate( self::settings(), $this->fake_captcha( null, $verify ), $log, $reporter );

		// 展示过存根但无票 → captcha_missing（有票才报口径）。
		$request = self::request( array( 'wk_stub' => self::shown_stub() ) );
		$result  = $gate->orchestrate( $request, $request, self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'captcha_missing', $log[0]['rule'] );
		$this->assertCount( 0, $reports, '无票 captcha_missing 不触发' );
	}

	public function test_t1_ip_forced_captcha_missing_does_not_fire(): void {
		// ip fail 强制标志 + 无票 → captcha_missing 拦（「过码才放行」拦点）——无票不触发。
		$reports  = array();
		$log      = array();
		$reporter = $this->fake_reporter( Verdict::reject( 'abuseipdb', 'score 90' ), $reports );
		$gate     = $this->gate( self::settings(), $this->fake_captcha( null, $v_unused ), $log, $reporter );

		$request = self::request( array( 'wk_stub' => self::shown_stub() ) );
		$result  = $gate->orchestrate( $request, $request, self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertCount( 0, $reports, 'ip 强制标志下的无票拦截不触发' );
	}

	public function test_t1_error_transport_does_not_fire(): void {
		// 验票 transport error（block）→ 事件 verdict=error——非伪造票不触发。
		$reports  = array();
		$log      = array();
		$reporter = $this->fake_reporter( null, $reports );

		$captcha = new class( ) implements CaptchaDriverInterface {
			public function render( array $args ): string {
				return '<span class="wk-gate" data-gate="comment" data-driver="fake"></span>';
			}
			public function verify( array $context ): ?string {
				return 'upstream timeout';
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
				return array( 'wk_captcha_ticket' );
			}
			public function failurePolicy(): array {
				return array();
			}
			public function disasterPolicy(): string {
				return 'none';
			}
			public function verify_tri( array $context ): array {
				return CaptchaTriInterpreter::legacy_to_tri( $this->verify( $context ), array( 'rule' => 'remote_transport' ) );
			}
			public function errorPolicy(): string {
				return 'block';
			}
		};

		$gate = $this->gate( self::settings(), $captcha, $log, $reporter );
		$request = self::request( array(
			'wk_stub'            => self::shown_stub(),
			'wk_captcha_ticket'  => 't',
			'wk_captcha_randstr' => 'x',
		) );
		$result = $gate->orchestrate( $request, $request, self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertCount( 0, $reports, 'error 态（transport 故障）不触发' );
	}

	public function test_t4_local_rules_reject_fires_local_rules_fail_once(): void {
		$reports  = array();
		$log      = array();
		$reporter = $this->fake_reporter( null, $reports );
		$gate     = $this->gate( self::settings(), $this->fake_captcha( null, $v_unused ), $log, $reporter );

		$request = self::request( array( 'comment_content' => '短', 'wk_stub' => self::shown_stub() ) );
		$result  = $gate->orchestrate( $request, $request, self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertCount( 1, $reports, '本地四闸 reject 恰计一次（同请求幂等计 1）' );
		$this->assertSame( 'local_rules_fail', $reports[0]['context']['trigger'] );
		$this->assertSame( 'comment', $reports[0]['context']['gate'] );
	}

	public function test_t2_cloud_reject_fires_review_reject(): void {
		$reports  = array();
		$log      = array();
		$reporter = $this->fake_reporter( null, $reports );

		$cloud = new class() implements ReviewDriverInterface {
			public function check( string $content, array $meta ): Verdict {
				return Verdict::reject( 'fake_cloud', 'Ad,99', array( 'Ad' ) );
			}
		};

		$gate = $this->gate( self::settings(), $this->fake_captcha( null, $v_unused ), $log, $reporter, array( 'fake_cloud' => $cloud ) );

		$request = self::request( array( 'wk_stub' => self::shown_stub(), 'wk_captcha_ticket' => 't', 'wk_captcha_randstr' => 'x' ) );
		$result  = $gate->orchestrate( $request, $request, self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertCount( 1, $reports, '云端 reject 恰计一次' );
		$this->assertSame( 'review_reject', $reports[0]['context']['trigger'] );
	}

	public function test_plain_probe_without_capability_never_fires(): void {
		// instanceof 能力探测（O2-P2-7）——纯 probe（Null 服务同形态）零上报面零行为变化。
		$log     = array();
		$probe   = $this->plain_probe( null );
		$gate    = $this->gate( self::settings(), $this->fake_captcha( 'rejected', $v_unused ), $log, $probe );
		$request = self::request( array( 'wk_stub' => self::shown_stub(), 'wk_captcha_ticket' => 't', 'wk_captcha_randstr' => 'x' ) );
		$result  = $gate->orchestrate( $request, $request, self::server() );
		$this->assertSame( 'reject', $result['decision'] ); // 行为照旧（上报面缺失=现行行为）。
	}

	/* -------------------------------------------------------------------
	 * T4：Precheck 预检四闸接线
	 * ------------------------------------------------------------------ */

	public function test_precheck_four_gate_reject_fires_local_rules_fail(): void {
		$reports = array();
		$log     = array();
		$reporter = $this->fake_reporter( null, $reports );

		$settings = array(
			'gates'   => array( 'comment' => array( 'enabled' => true ) ),
			'captcha' => array( 'trust' => array( 'enabled' => true, 'min_approved' => 3, 'ttl_minutes' => 10, 'credit_cache_minutes' => 0, 'precheck_hourly_limit' => 30 ) ),
			'review'  => array(
				'include_author_fields' => true,
				'local' => array( 'min_length' => 5, 'max_length' => 1800, 'require_chinese' => 1, 'custom_banned_words' => '', 'use_wp_keywords' => 0 ),
			),
			'copy'    => Copywork::defaults(),
			'stats'   => array( 'enabled' => true, 'pass_sampling' => false ),
		);

		$precheck = new Precheck(
			$settings,
			new LocalRulesDriver( $settings['review']['local'] ),
			static function ( string $author, string $email, string $body ): string {
				return $author . "\n" . $email . "\n" . $body;
			},
			new CommentTrust(
				array( 'min_approved' => 3, 'credit_cache_minutes' => 0 ),
				static function (): int {
					return 0;
				},
				static function ( string $key ) {
					unset( $key );
					return false;
				},
				static function ( string $key, $value, int $ttl ): void {
					unset( $key, $value, $ttl );
				},
				static function ( string $key ): void {
					unset( $key );
				},
				self::$mac
			),
			$reporter,
			static function ( string $client_ip ): bool {
				unset( $client_ip );
				return true; // 预检限流放行。
			},
			static function ( string $stage, Verdict $verdict, array $meta ) use ( &$log ): string {
				$log[] = array( 'stage' => $stage, 'rule' => $verdict->reason() );
				return 'WK-20261112-0200';
			},
			self::$stub_hash,
			self::$trust_hash,
			self::$mac,
			static function (): int {
				return self::NOW;
			}
		);

		$stub_hash = self::$stub_hash;
		$payload   = Stub::encode_payload( 'comment', '42', true, self::NOW - 100 );
		$stub      = $payload . '.' . $stub_hash( $payload );

		$result = $precheck->handle( array(
			'wk_stub' => $stub,
			'post_id' => '42',
			'author'  => '老读者',
			'email'   => 'reader@example.com',
			'content' => '短', // 四闸 too_short reject。
		), true, self::IP, 0 );

		$this->assertSame( Precheck::RESULT_FOUR_GATE, $result['result'] );
		$this->assertCount( 1, $reports, '预检四闸 reject 恰计一次（同请求幂等计 1）' );
		$this->assertSame( 'local_rules_fail', $reports[0]['context']['trigger'] );
		$this->assertSame( 'comment', $reports[0]['context']['gate'] );
		$this->assertSame( 'WK-20261112-0200', $reports[0]['context']['event_no'] );
		$this->assertSame( self::IP, $reports[0]['ip'] );
	}

	/* -------------------------------------------------------------------
	 * T3：LoginGate IP 聚合维度饱和接线（零新增计数器）
	 * ------------------------------------------------------------------ */

	/** @var array<string, mixed> */
	private $counter_store = array();

	private function counters_with_store(): LoginCounters {
		$store = &$this->counter_store;
		$store = array();
		return new LoginCounters(
			array( 'user_threshold' => 3, 'ip_threshold' => 10, 'window_minutes' => 15 ),
			static function ( string $key ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$store ): void {
				$store[ $key ] = $value;
			},
			static function ( string $key ) use ( &$store ): void {
				unset( $store[ $key ] );
			},
			self::$mac,
			static function (): int {
				return self::NOW;
			}
		);
	}

	private function login_gate( LoginCounters $counters, $iprep ): LoginGate {
		return new LoginGate(
			array( 'gates' => array( 'login' => array( 'enabled' => true, 'render_mode' => 'adaptive' ) ) ),
			null,
			null,
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return self::NOW;
			},
			$counters,
			null,
			$iprep
		);
	}

	public function test_t3_fires_at_ip_dimension_saturation(): void {
		$reports  = array();
		$reporter = $this->fake_reporter( null, $reports );
		$iprep    = new LoginIpRep( array(), $reporter );

		// 前 9 次失败：未饱和 → 不触发。
		$gate = $this->login_gate( $this->counters_with_store(), $iprep );
		for ( $i = 1; $i <= 9; $i++ ) {
			$gate->record_auth_failure( self::IP, 'spray', null, 'invalid_username' );
		}
		$this->assertCount( 0, $reports, '未饱和（<10）不触发' );

		// 第 10 次失败：IP 聚合维度饱和判定处 → 触发。
		$gate->record_auth_failure( self::IP, 'spray', null, 'invalid_username' );
		$this->assertCount( 1, $reports, 'ip_threshold=10 饱和即触发（复用既有计数器零新增）' );
		$this->assertSame( self::IP, $reports[0]['ip'] );
		$this->assertSame( 'login_attempts', $reports[0]['context']['trigger'] );
		$this->assertSame( 'login', $reports[0]['context']['gate'] );

		// 饱和后（短路区间）再失败：仍处于饱和判定处（冷却去重交给服务面闸②）。
		$gate->record_auth_failure( self::IP, 'spray', null, 'invalid_username' );
		$this->assertCount( 2, $reports, '饱和态持续可触发（去重由 report 面四道闸承载）' );

		// 零新增计数器断言：LoginGate 侧无 wk_rp_cnt_login_attempts 键（T3 不单设计数）。
		foreach ( array_keys( $this->counter_store ) as $key ) {
			$this->assertStringStartsNotWith( 'wk_rp_cnt_', (string) $key, 'T3 零新增计数键' );
		}
	}

	public function test_t3_user_dimension_saturation_alone_does_not_fire(): void {
		// 用户名维度饱和（分布式爆破单账号、每 IP 只 1 次）≠ IP 聚合爆破——T3 只认 IP 维度。
		$reports  = array();
		$reporter = $this->fake_reporter( null, $reports );
		$iprep    = new LoginIpRep( array(), $reporter );
		$gate     = $this->login_gate( $this->counters_with_store(), $iprep );

		for ( $i = 0; $i < 5; $i++ ) {
			$gate->record_auth_failure( '198.51.100.' . $i, 'admin', 'admin', 'incorrect_password' );
		}
		$this->assertCount( 0, $reports, '仅用户名维度饱和（各 IP 1 次）不触发 T3' );
	}

	public function test_no_reporter_capability_login_gate_unchanged(): void {
		// 纯 probe（无上报能力）登录闸行为零变化（instanceof 探测 false）。
		$counters = $this->counters_with_store();
		$gate     = $this->login_gate( $counters, new LoginIpRep( array(), $this->plain_probe( null ) ) );
		for ( $i = 0; $i < 12; $i++ ) {
			$gate->record_auth_failure( self::IP, 'spray', null, 'invalid_username' );
		}
		$this->assertTrue( $counters->is_saturated( self::IP, 'spray' ), '计数行为照旧' );
	}
}
