<?php
/**
 * CommentGate 信用免验编排单元测试（02 §3.1 v1.4/R2 §2.6 §2.7 §2.8——T5）。
 *
 * 编排修订三面：
 *   ① 步骤 2.5：LocalRules 四闸前移到 captcha 之前（四闸不通过=与验证码无关直接拦；
 *      零验票调用——事件口径 stage=review/driver=local_rules 不变，只动次序）；
 *   ② 步骤 3 三来源免验：TrustStub → VerifyCache → 真验（pass_source 口径；
 *      trust_pass 事件 driver=gate、meta.trust_pass=true）；
 *   ③ IP 信誉消费（IpReputationProbe）：null=skip；pass=继续；review/reject/error=
 *      强制验证码标志（出码过码放行——不再 wp_die；新鲜判定压过 trust 凭证）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\CommentGate;
use WinkGatekeeper\Gates\TrustStub;
use WinkGatekeeper\Gates\VerifyCache;
use WinkGatekeeper\Intercept\Stub;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Verdict;

final class CommentGateTrustTest extends TestCase {

	private const NOW = 1731168000;

	private static $stub_hash;
	private static $trust_hash;
	private static $mac;

	public static function setUpBeforeClass(): void {
		self::$stub_hash = static function ( string $data ): string {
			return 's_' . hash_hmac( 'sha256', $data, 'gate-trust-stub-key' );
		};
		self::$trust_hash = static function ( string $data ): string {
			return 't_' . hash_hmac( 'sha256', $data, 'gate-trust-trust-key' );
		};
		self::$mac = static function ( string $data ): string {
			return substr( hash_hmac( 'sha256', $data, 'gate-trust-mac-key' ), 0, 16 );
		};
	}

	/**
	 * 可编程假验证码驱动（记录 verify 调用次数）。
	 */
	private function fake_captcha( ?string $result, ?int &$verify_calls = null ): object {
		return new class( $result, $verify_calls ) implements \WinkGatekeeper\Drivers\CaptchaDriverInterface {
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
			/* 【v1.5·C1】接口 v1.2 能力面：假驱动沿 legacy 语义折算（verify_tri 委托 verify）。 */
			public function verify_tri( array $context ): array {
				return \WinkGatekeeper\Gates\CaptchaTriInterpreter::legacy_to_tri( $this->verify( $context ), method_exists( $this, 'last_verify_meta' ) ? $this->last_verify_meta() : array() );
			}
			public function errorPolicy(): string {
				return 'block';
			}
		};
	}

	private function fake_probe( ?Verdict $verdict, array &$seen_ctx = array() ): \WinkGatekeeper\Ip\IpReputationProbe {
		return new class( $verdict, $seen_ctx ) implements \WinkGatekeeper\Ip\IpReputationProbe {
			private $verdict;
			private $seen_ctx;
			public function __construct( ?Verdict $verdict, array &$seen_ctx ) {
				$this->verdict  = $verdict;
				$this->seen_ctx = &$seen_ctx;
			}
			public function probe( string $ip, array $ctx = array() ): ?Verdict {
				$this->seen_ctx[] = $ctx;
				return $this->verdict;
			}
		};
	}

	private static function settings( array $overrides = array() ): array {
		return array_merge( array(
			'gates'  => array( 'comment' => array( 'enabled' => true, 'render_mode' => 'always' ) ),
			'review' => array(
				'include_author_fields' => true,
				'local' => array( 'min_length' => 5, 'max_length' => 1800, 'require_chinese' => 1, 'custom_banned_words' => '', 'use_wp_keywords' => 0 ),
			),
			'captcha' => array( 'trust' => array( 'enabled' => true, 'min_approved' => 3, 'ttl_minutes' => 10 ) ),
			'copy'    => array( 'messages' => array( 'too_short' => '写到 {min_length} 字再试试' ) ),
			'stats'   => array( 'enabled' => true, 'pass_sampling' => false ),
		), $overrides );
	}

	private function gate( array $settings, ?object $captcha, array &$log, $probe = null, ?VerifyCache $verify_cache = null ): CommentGate {
		return new CommentGate(
			$settings,
			$captcha,
			null,
			static function ( string $stage, Verdict $verdict, array $meta ) use ( &$log ): string {
				$log[] = array( 'stage' => $stage, 'verdict' => $verdict->verdict(), 'rule' => $verdict->reason(), 'driver' => $verdict->driver_id(), 'meta' => $meta );
				return 'WK-20261110-0099';
			},
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return self::NOW;
			},
			static function (): string {
				return '42'; // 渲染期 context_id（生产 = get_the_ID()；纯逻辑测试固定值）。
			},
			null,
			array(),
			null,
			null,
			$verify_cache,
			$probe,
			self::$mac
		);
	}

	private static function request( array $overrides = array() ): array {
		return array_merge( array(
			'comment_post_ID' => '42',
			'comment_content' => '这是一条足够长的正常中文评论',
			'comment_author'  => '老读者',
			'comment_author_email' => 'reader@example.com',
		), $overrides );
	}

	private static function server(): array {
		return array( 'REMOTE_ADDR' => '203.0.113.7' );
	}

	private static function valid_stub(): string {
		$hash    = self::$stub_hash;
		$payload = Stub::encode_payload( 'comment', '42', true, self::NOW - 100 );
		return $payload . '.' . $hash( $payload );
	}

	private static function valid_trust( string $email = 'reader@example.com', string $author = '老读者', string $ip = '203.0.113.7', int $iat = self::NOW - 60 ): string {
		// 闸侧 TrustStub::verify 复用 stub_hash 摘要通道（02 §3.3a「复用 wp_hash('nonce')」）。
		$mac = self::$mac;
		return TrustStub::issue( '42', $mac( TrustStub::guest_identity_input( $email, $author ) ), $mac( $ip ), $iat, self::$stub_hash );
	}

	/* ---------- ① 步骤 2.5：四闸前移到 captcha 之前 ---------- */

	public function test_four_gate_reject_precedes_captcha_missing(): void {
		// min_length=5 且正文只有 1 字 + 无票：旧次序=先 captcha_missing；v1.4 前移后=too_short
		// 先出（更符合预期，属修复性变化——R2 §2.7）。
		$verify_calls = 0;
		$log          = array();
		$gate         = $this->gate( self::settings(), $this->fake_captcha( null, $verify_calls ), $log );

		$result = $gate->orchestrate(
			self::request( array( 'comment_content' => '短', 'wk_stub' => self::valid_stub() ) ),
			self::request( array( 'comment_content' => '短', 'wk_stub' => self::valid_stub() ) ),
			self::server()
		);

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( '写到 5 字再试试', $result['message'], 'message=工坊文案（too_short 键已格式化占位符）' );
		$this->assertSame( 0, $verify_calls, '四闸 reject 零验票调用（票据未被消费）' );
		$this->assertSame( 'review', $log[0]['stage'], 'stage 口径不变（driver=local_rules）' );
		$this->assertSame( 'too_short', $log[0]['rule'] );
		$this->assertSame( 'local_rules', $log[0]['driver'] );
	}

	public function test_four_gate_still_runs_when_captcha_unconfigured(): void {
		// 驱动未配置 = captcha 阶段整体跳过；四闸照拦（02 §2.1）。
		$log  = array();
		$gate = $this->gate( self::settings(), null, $log );

		$result = $gate->orchestrate( self::request( array( 'comment_content' => 'pure english no captcha driver' ) ), self::request(), self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'review', $log[0]['stage'] );
		$this->assertSame( 'no_chinese', $log[0]['rule'] );
	}

	/* ---------- ② 步骤 3 三来源免验 ---------- */

	public function test_trust_stub_hit_skips_ticket_and_records_trust_pass(): void {
		$verify_calls = 0;
		$log          = array();
		$gate         = $this->gate( self::settings(), $this->fake_captcha( '本不应被调用', $verify_calls ), $log );

		$request = self::request( array(
			'wk_stub'  => self::valid_stub(),
			'wk_trust' => self::valid_trust(),
		) );

		$result = $gate->orchestrate( $request, $request, self::server() );

		$this->assertSame( 'allow', $result['decision'], 'trust 命中=免真验票（IP+表单+身份三重绑定凭证）' );
		$this->assertSame( 0, $verify_calls, '零驱动调用（usage 不计数）' );
		$this->assertCount( 1, $log, '仅 trust_pass 一条 pass 审计足迹' );
		$this->assertSame( 'captcha', $log[0]['stage'] );
		$this->assertSame( 'pass', $log[0]['verdict'] );
		$this->assertSame( 'trust_pass', $log[0]['rule'] );
		$this->assertSame( 'gate', $log[0]['driver'], '信用免验不是驱动产物（driver=gate）' );
		$this->assertTrue( $log[0]['meta']['trust_pass'] );
		$this->assertSame( 'trust', $log[0]['meta']['pass_source'] );
	}

	public function test_trust_stub_wrong_ip_falls_back_to_ticket_required(): void {
		// 预检后换网络/换出口 → 凭证失效 → 视同无凭证（不报错不拦，走验票/VerifyCache）。
		$verify_calls = 0;
		$log          = array();
		$gate         = $this->gate( self::settings(), $this->fake_captcha( null, $verify_calls ), $log );

		$request = self::request( array(
			'wk_stub'  => self::valid_stub(),
			'wk_trust' => self::valid_trust( 'reader@example.com', '老读者', '198.51.100.9' ),
		) );

		$result = $gate->orchestrate( $request, $request, self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'captcha', $log[0]['stage'] );
		$this->assertSame( 'captcha_missing', $log[0]['rule'] );
	}

	public function test_trust_stub_identity_swap_fails(): void {
		// 堵「预检用可信邮箱、提交换新邮箱」的偷梁换柱。
		$verify_calls = 0;
		$log          = array();
		$gate         = $this->gate( self::settings(), $this->fake_captcha( null, $verify_calls ), $log );

		$request = self::request( array(
			'wk_stub'  => self::valid_stub(),
			'wk_trust' => self::valid_trust( 'other@example.com', '老读者' ),
		) );
		$request['comment_author_email'] = 'reader@example.com';

		$result = $gate->orchestrate( $request, $request, self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'captcha_missing', $log[0]['rule'] );
	}

	public function test_trust_stub_expired_falls_back(): void {
		$verify_calls = 0;
		$log          = array();
		$gate         = $this->gate( self::settings(), $this->fake_captcha( null, $verify_calls ), $log );

		$request = self::request( array(
			'wk_stub'  => self::valid_stub(),
			'wk_trust' => self::valid_trust( 'reader@example.com', '老读者', '203.0.113.7', self::NOW - 601 ),
		) );

		$gate->orchestrate( $request, $request, self::server() );

		$this->assertSame( 'captcha_missing', $log[0]['rule'], 'TTL（默认 10min，与 verify_cache 窗口同宽）过期即失效' );
	}

	public function test_trust_disabled_ignores_wk_trust_field(): void {
		// captcha.trust.enabled=false：一键回到「恒真验」现行形态。
		$settings     = self::settings( array( 'captcha' => array( 'trust' => array( 'enabled' => false, 'min_approved' => 3, 'ttl_minutes' => 10 ) ) ) );
		$verify_calls = 0;
		$log          = array();
		$gate         = $this->gate( $settings, $this->fake_captcha( null, $verify_calls ), $log );

		$request = self::request( array(
			'wk_stub'  => self::valid_stub(),
			'wk_trust' => self::valid_trust(),
		) );

		$result = $gate->orchestrate( $request, $request, self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'captcha_missing', $log[0]['rule'], '功能关=wk_trust 不被消费' );
	}

	public function test_verify_cache_hit_records_pass_source(): void {
		// 现行 verify_cache_hit 事件补 pass_source=verify_cache（三来源口径对齐）。
		$store        = array();
		$verify_cache = new VerifyCache(
			10,
			function ( string $key ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : false;
			},
			function ( string $key, $value, int $ttl ) use ( &$store ): void {
				$store[ $key ] = $value;
			},
			self::$mac
		);
		$verify_calls = 0;
		$log          = array();
		$gate         = $this->gate( self::settings(), $this->fake_captcha( '本不应被调用', $verify_calls ), $log, null, $verify_cache );

		$stub = self::valid_stub();
		$verify_cache->remember( $stub, '203.0.113.7' );

		$request = self::request( array( 'wk_stub' => $stub ) );

		$result = $gate->orchestrate( $request, $request, self::server() );

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertSame( 0, $verify_calls );
		$this->assertSame( 'verify_cache_hit', $log[0]['rule'] );
		$this->assertSame( 'verify_cache', $log[0]['meta']['pass_source'] );
	}

	public function test_trust_checked_before_verify_cache(): void {
		// 先查 trust（零 I/O 纯哈希）后查 VerifyCache（transient 读）——顺序 1→2。
		$store        = array();
		$verify_cache = new VerifyCache(
			10,
			function ( string $key ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : false;
			},
			function ( string $key, $value, int $ttl ) use ( &$store ): void {
				$store[ $key ] = $value;
			},
			self::$mac
		);
		$stub         = self::valid_stub();
		$verify_cache->remember( $stub, '203.0.113.7' );

		$verify_calls = 0;
		$log          = array();
		$gate         = $this->gate( self::settings(), $this->fake_captcha( null, $verify_calls ), $log, null, $verify_cache );

		$request = self::request( array(
			'wk_stub'  => $stub,
			'wk_trust' => self::valid_trust(),
		) );

		$gate->orchestrate( $request, $request, self::server() );

		$this->assertSame( 'trust_pass', $log[0]['rule'], '两者并存不合并——来源可区分（trust 优先）' );
	}

	/* ---------- ③ IP 信誉消费（三态；M2.5 Null=skip=现行行为） ---------- */

	public function test_ip_probe_null_behaves_as_before(): void {
		$verify_calls = 0;
		$log          = array();
		$gate         = $this->gate( self::settings(), $this->fake_captcha( null, $verify_calls ), $log, $this->fake_probe( null ) );

		$request = self::request( array( 'wk_stub' => self::valid_stub(), 'wk_trust' => self::valid_trust() ) );
		$result  = $gate->orchestrate( $request, $request, self::server() );

		$this->assertSame( 'allow', $result['decision'], 'Null 服务=skip：无动作无事件（M2.5 现行行为）' );
		$this->assertSame( 'trust_pass', $log[0]['rule'] );
	}

	public function test_ip_probe_pass_no_event_and_allows_trust(): void {
		$verify_calls = 0;
		$log          = array();
		$gate         = $this->gate( self::settings(), $this->fake_captcha( null, $verify_calls ), $log, $this->fake_probe( Verdict::pass( 'abuseipdb' ) ) );

		$request = self::request( array( 'wk_stub' => self::valid_stub(), 'wk_trust' => self::valid_trust() ) );
		$result  = $gate->orchestrate( $request, $request, self::server() );

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertCount( 1, $log, 'ip pass 不落库（04 §3）——仅 trust_pass 一条' );
	}

	public function test_ip_probe_fail_with_stub_true_forces_captcha(): void {
		// v1.2 条款（修订后 ip 消费面）：fail 档=强制验证码标志；存根 true（无票）→ captcha_missing
		// 拦（不再 wp_die ip_malicious——过码放行，反馈 6.5）。
		$verify_calls = 0;
		$log          = array();
		$gate         = $this->gate( self::settings(), $this->fake_captcha( null, $verify_calls ), $log, $this->fake_probe( Verdict::reject( 'abuseipdb', 'ip_score_92', array( 'ip_score_92' ) ) ) );

		$stub    = Stub::encode_payload( 'comment', '42', false, self::NOW - 100 );
		$hash    = self::$stub_hash;
		$request = self::request( array( 'wk_stub' => $stub . '.' . $hash( $stub ) ) );

		$result = $gate->orchestrate( $request, $request, self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'ip', $log[0]['stage'], 'ip 判定时落库保留阈值证据（04 §3 ip 行）' );
		$this->assertSame( 'reject', $log[0]['verdict'] );
		$this->assertTrue( $log[0]['meta']['ip_force_captcha'] );
		$this->assertSame( 'captcha', $log[1]['stage'] );
		$this->assertSame( 'captcha_missing', $log[1]['rule'] );
	}

	public function test_ip_probe_fail_with_ticket_passes_captcha_and_allows(): void {
		// 「IP 信誉不通过但人机验证通过的也放行」——过码进内容闸（脏 VPN IP 不误伤）。
		$verify_calls = 0;
		$log          = array();
		$gate         = $this->gate( self::settings(), $this->fake_captcha( null, $verify_calls ), $log, $this->fake_probe( Verdict::review( 'abuseipdb' ) ) );

		$request = self::request( array(
			'wk_stub'            => self::valid_stub(),
			'wk_captcha_ticket'  => 'ticket-1',
			'wk_captcha_randstr' => 'rand-1',
		) );

		$result = $gate->orchestrate( $request, $request, self::server() );

		$this->assertSame( 'allow', $result['decision'], '过码即放行进内容闸' );
		$this->assertSame( 1, $verify_calls );
	}

	public function test_ip_probe_fail_revokes_trust_stub(): void {
		// 校验表末行（M3 语义本批落代码）：新鲜判定压过凭证——强制真验。
		$verify_calls = 0;
		$log          = array();
		$gate         = $this->gate( self::settings(), $this->fake_captcha( null, $verify_calls ), $log, $this->fake_probe( Verdict::reject( 'abuseipdb' ) ) );

		$request = self::request( array(
			'wk_stub'  => self::valid_stub(),
			'wk_trust' => self::valid_trust(),
		) );

		$result = $gate->orchestrate( $request, $request, self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'captcha_missing', end( $log )['rule'], 'trust 凭证被撤销（罕见路径，从严）' );
	}

	public function test_ip_probe_error_state_forces_captcha_too(): void {
		// 已启用但故障/停转（error）→ 防呆从严=强制验证码（澄清 A）。
		$verify_calls = 0;
		$log          = array();
		$gate         = $this->gate( self::settings(), $this->fake_captcha( null, $verify_calls ), $log, $this->fake_probe( Verdict::error( 'abuseipdb', 'driver_halted:config_error', array( 'driver_halted:config_error' ) ) ) );

		$request = self::request( array( 'wk_stub' => self::valid_stub() ) );

		$result = $gate->orchestrate( $request, $request, self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'ip', $log[0]['stage'] );
		$this->assertSame( 'error', $log[0]['verdict'] );
		$this->assertSame( 'captcha_missing', $log[1]['rule'] );
	}

	/* ---------- 渲染面：data-wk-trust + wk_trust 隐藏域（闸级字段） ---------- */

	public function test_render_marks_trust_container_when_enabled(): void {
		$log   = array();
		$calls = 0;
		$gate  = $this->gate( self::settings(), $this->fake_captcha( null, $calls ), $log, null, null );

		$html = $gate->render_submit_field( '<p class="submit"><input type="submit" id="submit"></p>' );

		$this->assertStringContainsString( 'data-wk-trust="1"', $html, '信用免验启用时容器带 data-wk-trust（gatekeeper.js 预检门生效条件）' );
		$this->assertStringContainsString( 'name="wk_trust"', $html, 'wk_trust 隐藏域由闸渲染层注入（与 wk_stub 并列——闸级字段）' );
	}

	public function test_render_omits_trust_markup_when_disabled(): void {
		$settings = self::settings( array( 'captcha' => array( 'trust' => array( 'enabled' => false, 'min_approved' => 3, 'ttl_minutes' => 10 ) ) ) );
		$log      = array();
		$calls    = 0;
		$gate     = $this->gate( $settings, $this->fake_captcha( null, $calls ), $log );

		$html = $gate->render_submit_field( '<p class="submit"><input type="submit" id="submit"></p>' );

		$this->assertStringNotContainsString( 'data-wk-trust', $html );
		$this->assertStringNotContainsString( 'name="wk_trust"', $html );
	}

	/* ---------- 拼装函数复用（预检与提交期同口径） ---------- */

	public function test_assemble_review_content_is_public_and_reusable(): void {
		$log  = array();
		$gate = $this->gate( self::settings(), null, $log );

		$this->assertSame( "老读者\nreader@example.com\n正文", $gate->assemble_review_content( '老读者', 'reader@example.com', '正文' ) );
	}
}
