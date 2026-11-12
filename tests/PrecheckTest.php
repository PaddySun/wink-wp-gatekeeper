<?php
/**
 * Precheck 预检端点纯逻辑单元测试（03 §2.1 信用免验预检门/R2 §3——T4）。
 *
 * 判定链（02 §3.3a/R2 §2.1）：
 *   0 门禁三道：nonce → 存根校验（Stub::verify 必须 true/null）→ 每 IP 限流 wk_pk_*（30/h）
 *     ——任一失败 → captcha_needed（HTTP 200，不区分报错原因，不给探测面）；
 *   ① 本地四闸（草稿值；与提交期同一 LocalRules 实例、同一拼装函数）→ four_gate_fail + 规则键 +
 *     工坊文案（占位符已格式化）+ 事件 stage=precheck（不留档、meta 无正文）；
 *   ② IP 信誉三态（probe：null=跳过 / pass=通过 / review|reject|error=不通过——点击期零外呼）；
 *   ③ 历史信用（CommentTrust::history_pass）；
 *   全过 → trust_pass + TrustStub 凭证；任一不过（非四闸）→ captcha_needed。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\Review\LocalRulesDriver;
use WinkGatekeeper\Gates\CommentTrust;
use WinkGatekeeper\Gates\Precheck;
use WinkGatekeeper\Gates\TrustStub;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Intercept\Stub;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Verdict;

final class PrecheckTest extends TestCase {

	private const NOW = 1731168000;

	private static $stub_hash;
	private static $trust_hash;
	private static $mac;

	/** @var array<string, mixed> 内存 transient */
	private $store = array();

	public static function setUpBeforeClass(): void {
		self::$stub_hash = static function ( string $data ): string {
			return 's_' . hash_hmac( 'sha256', $data, 'precheck-stub-key' );
		};
		self::$trust_hash = static function ( string $data ): string {
			return 't_' . hash_hmac( 'sha256', $data, 'precheck-trust-key' );
		};
		self::$mac = static function ( string $data ): string {
			return substr( hash_hmac( 'sha256', $data, 'precheck-mac-key' ), 0, 16 );
		};
	}

	/* ---------- 假件 ---------- */

	private function trust_service( int $approved_count ): CommentTrust {
		$mac = self::$mac;
		return new CommentTrust(
			array( 'min_approved' => 3, 'credit_cache_minutes' => 0 ),
			static function () use ( $approved_count ): int {
				return $approved_count;
			},
			function ( string $key ) {
				return array_key_exists( $key, $this->store ) ? $this->store[ $key ] : false;
			},
			function ( string $key, $value, int $ttl ): void {
				$this->store[ $key ] = $value;
			},
			function ( string $key ): void {
				unset( $this->store[ $key ] );
			},
			$mac
		);
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
			'gates'   => array( 'comment' => array( 'enabled' => true ) ),
			'captcha' => array(
				'driver' => 'tencent',
				'trust'  => array( 'enabled' => true, 'min_approved' => 3, 'ttl_minutes' => 10, 'credit_cache_minutes' => 0, 'precheck_hourly_limit' => 30 ),
			),
			'review' => array(
				'include_author_fields' => true,
				'local' => array( 'min_length' => 5, 'max_length' => 1800, 'require_chinese' => 1, 'custom_banned_words' => '', 'use_wp_keywords' => 0 ),
			),
			'copy'   => Copywork::defaults(),
			'stats'  => array( 'enabled' => true, 'pass_sampling' => false ),
		), $overrides );
	}

	private function precheck(
		array $settings,
		?CommentTrust $trust = null,
		$probe = null,
		bool $rate_ok = true,
		?callable $recorder = null,
		?callable $assemble = null
	): Precheck {
		return new Precheck(
			$settings,
			new LocalRulesDriver( $settings['review']['local'] ),
			$assemble ?? static function ( string $author, string $email, string $body ): string {
				return $author . "\n" . $email . "\n" . $body;
			},
			$trust ?? $this->trust_service( 3 ),
			$probe,
			static function ( string $ip ) use ( $rate_ok ): bool {
				return $rate_ok;
			},
			$recorder ?? static function (): string {
				return '';
			},
			self::$stub_hash,
			self::$trust_hash,
			self::$mac,
			static function (): int {
				return self::NOW;
			}
		);
	}

	private static function input( array $overrides = array() ): array {
		return array_merge( array(
			'wk_stub' => self::valid_stub(),
			'post_id' => '42',
			'author'  => '老读者',
			'email'   => 'reader@example.com',
			'content' => '这是一条正常的中文评论内容',
		), $overrides );
	}

	private static function valid_stub(): string {
		$hash    = self::$stub_hash;
		$payload = Stub::encode_payload( 'comment', '42', true, self::NOW - 100 );
		return $payload . '.' . $hash( $payload );
	}

	/* ---------- 三门禁：任一失败 → captcha_needed（不区分原因） ---------- */

	public function test_nonce_failure_returns_captcha_needed(): void {
		$result = $this->precheck( self::settings() )->handle( self::input(), false, '203.0.113.7', 0 );
		$this->assertSame( array( 'result' => 'captcha_needed' ), $result );
	}

	public function test_bad_stub_returns_captcha_needed(): void {
		$result = $this->precheck( self::settings() )->handle( self::input( array( 'wk_stub' => 'forged.stuff' ) ), true, '203.0.113.7', 0 );
		$this->assertSame( array( 'result' => 'captcha_needed' ), $result );
	}

	public function test_rate_limit_exceeded_returns_captcha_needed(): void {
		$result = $this->precheck( self::settings(), null, null, false )->handle( self::input(), true, '203.0.113.7', 0 );
		$this->assertSame( array( 'result' => 'captcha_needed' ), $result );
	}

	public function test_gate_failures_do_not_record_events(): void {
		// 门禁/限流失败不落库（04 §3 写入矩阵——防端点刷库）。
		$log = array();
		$rec = static function ( string $stage, Verdict $v, array $meta ) use ( &$log ): string {
			$log[] = array( $stage, $v, $meta );
			return '';
		};
		$this->precheck( self::settings(), null, null, false, $rec )->handle( self::input(), true, '203.0.113.7', 0 );
		$this->assertSame( array(), $log );
	}

	/* ---------- ① 四闸（草稿值）：four_gate_fail + 规则键 + 已格式化文案 + precheck 事件 ---------- */

	public function test_four_gate_reject_returns_rule_and_formatted_message(): void {
		$log  = array();
		$rec  = static function ( string $stage, Verdict $v, array $meta ) use ( &$log ): string {
			$log[] = array( 'stage' => $stage, 'verdict' => $v->verdict(), 'rule' => $v->reason(), 'meta' => $meta );
			return 'WK-1';
		};
		$settings = self::settings();
		$settings['review']['local']['min_length'] = 5;

		$result = $this->precheck( $settings, null, null, true, $rec )->handle(
			self::input( array( 'content' => '短' ) ), true, '203.0.113.7', 0
		);

		$this->assertSame( 'four_gate_fail', $result['result'] );
		$this->assertSame( 'too_short', $result['rule'] );
		$this->assertSame(
			Copywork::format( Copywork::defaults()['messages']['too_short'], array( 'min_length' => 5, 'max_length' => 1800 ) ),
			$result['message'],
			'文案 = 工坊文案已格式化占位符（min/max）'
		);

		$this->assertCount( 1, $log, '四闸不过落一条 precheck 事件' );
		$this->assertSame( 'precheck', $log[0]['stage'] );
		$this->assertSame( 'reject', $log[0]['verdict'] );
		$this->assertSame( 'too_short', $log[0]['rule'] );
		$this->assertSame( 'local_rules', $log[0]['meta']['driver'] ?? 'local_rules' );
	}

	public function test_precheck_event_meta_has_no_content(): void {
		// 04 §3 红线：事件 meta 不含正文（草稿即判即弃）。
		$log = array();
		$rec = static function ( string $stage, Verdict $v, array $meta ) use ( &$log ): string {
			$log[] = $meta;
			return '';
		};
		$settings = self::settings();
		$settings['review']['local']['custom_banned_words'] = "发票";

		$this->precheck( $settings, null, null, true, $rec )->handle(
			self::input( array( 'content' => '这是一条提到发票的正常中文内容' ) ), true, '203.0.113.7', 0
		);

		$this->assertCount( 1, $log );
		$this->assertArrayNotHasKey( 'content', $log[0], '草稿正文不进事件 meta（即判即弃）' );
		$this->assertArrayNotHasKey( 'draft_probe', $log[0] );
		$this->assertSame( 42, $log[0]['post_id'], 'meta 携带 post_id（定位线索，不含正文）' );
	}

	public function test_four_gate_uses_same_assemble_convention_as_submission(): void {
		// 拼装约定一致性：昵称+邮箱+正文拼接串进四闸（昵称藏私活也在预检期拦下）。
		$assembled = null;
		$assemble  = static function ( string $author, string $email, string $body ) use ( &$assembled ): string {
			$assembled = $author . "\n" . $email . "\n" . $body;
			return $assembled;
		};
		$settings = self::settings();
		$settings['review']['local']['custom_banned_words'] = '私活';

		$result = $this->precheck( $settings, null, null, true, null, $assemble )->handle(
			self::input( array( 'author' => '承接私活' ) ), true, '203.0.113.7', 0
		);

		$this->assertSame( 'four_gate_fail', $result['result'] );
		$this->assertSame( 'banned_word', $result['rule'] );
		$this->assertSame( "承接私活\nreader@example.com\n这是一条正常的中文评论内容", $assembled );
	}

	/* ---------- ② IP 信誉三态 ---------- */

	public function test_ip_probe_null_skips_and_allows_trust(): void {
		// M2.5 Null 服务：probe()=null → 跳过判定项，不影响免验资格（澄清 A）。
		$result = $this->precheck( self::settings(), null, $this->fake_probe( null ) )->handle( self::input(), true, '203.0.113.7', 0 );
		$this->assertSame( 'trust_pass', $result['result'] );
	}

	public function test_ip_probe_pass_allows_trust(): void {
		$result = $this->precheck( self::settings(), null, $this->fake_probe( Verdict::pass( 'abuseipdb', '', array() ) ) )
			->handle( self::input(), true, '203.0.113.7', 0 );
		$this->assertSame( 'trust_pass', $result['result'] );
	}

	public function test_ip_probe_fail_states_force_captcha(): void {
		foreach ( array( Verdict::review( 'abuseipdb' ), Verdict::reject( 'abuseipdb' ), Verdict::error( 'abuseipdb' ) ) as $verdict ) {
			$result = $this->precheck( self::settings(), null, $this->fake_probe( $verdict ) )
				->handle( self::input(), true, '203.0.113.7', 0 );
			$this->assertSame( array( 'result' => 'captcha_needed' ), $result, 'IP 三态 fail（review/reject/error）→ 出码（过码即放行——反馈 6.5）' );
		}
	}

	public function test_ip_probe_receives_cache_only_context(): void {
		// 【v1.7·M3-IP·定稿 §3.4】预检调用 probe 必传 cache_only=true（点击期永远零外呼——
		// 只读缓存；未命中从严 fail 走既有 captcha_needed 路径零改动承接）。
		$seen_ctx = array();
		$result = $this->precheck( self::settings(), null, $this->fake_probe( Verdict::pass( 'abuseipdb' ), $seen_ctx ) )
			->handle( self::input(), true, '203.0.113.7', 0 );
		$this->assertSame( 'trust_pass', $result['result'] );
		$this->assertSame( array( array( 'cache_only' => true ) ), $seen_ctx, '预检 probe ctx=cache_only:true（零外呼只读模式）' );
	}

	/* ---------- ③ 历史信用 ---------- */

	public function test_insufficient_history_forces_captcha(): void {
		$result = $this->precheck( self::settings(), $this->trust_service( 2 ) )->handle( self::input(), true, '203.0.113.7', 0 );
		$this->assertSame( array( 'result' => 'captcha_needed' ), $result );
	}

	public function test_logged_in_user_identity_uses_user_dimension(): void {
		$calls   = array();
		$trust   = new CommentTrust(
			array( 'min_approved' => 3, 'credit_cache_minutes' => 0 ),
			function ( string $mode, array $args, int $limit ) use ( &$calls ): int {
				$calls[] = array( $mode, $args );
				return 4;
			},
			static function () {
				return false;
			},
			static function (): void {
			},
			static function (): void {
			},
			self::$mac
		);

		$result = $this->precheck( self::settings(), $trust )->handle( self::input(), true, '203.0.113.7', 7 );

		$this->assertSame( 'trust_pass', $result['result'] );
		$this->assertSame( 'user_email', $calls[0][0], '登录者按 user_id 信用键（邮箱索引等价路径计数）' );
	}

	/* ---------- 全过：trust_pass + 凭证 ---------- */

	public function test_all_pass_issues_verifiable_trust_stub(): void {
		$result = $this->precheck( self::settings() )->handle( self::input(), true, '203.0.113.7', 0 );

		$this->assertSame( 'trust_pass', $result['result'] );
		$this->assertArrayHasKey( 'trust', $result );

		$mac = self::$mac;
		$this->assertTrue( TrustStub::verify(
			$result['trust'], '42',
			$mac( TrustStub::guest_identity_input( 'reader@example.com', '老读者' ) ),
			$mac( '203.0.113.7' ),
			10, self::NOW, self::$trust_hash
		), '凭证与本次（IP+表单+身份）三重绑定且 TTL 内有效' );
	}

	public function test_trust_pass_records_no_event(): void {
		// 点击期通过不落库（提交期落 trust_pass 一条足矣——04 §3 precheck pass 行）。
		$log = array();
		$rec = static function ( string $stage, Verdict $v, array $meta ) use ( &$log ): string {
			$log[] = $stage;
			return '';
		};
		$this->precheck( self::settings(), null, null, true, $rec )->handle( self::input(), true, '203.0.113.7', 0 );
		$this->assertSame( array(), $log );
	}

	/* ---------- 端点启用条件（03 §2.1 契约表） ---------- */

	public function test_disabled_trust_feature_degrades_to_captcha_needed(): void {
		// 端点启用条件：gates.comment.enabled ∧ 驱动已配置 ∧ captcha.trust.enabled——
		// 功能关时 handle 直接降级（容器不带 data-wk-trust，端点也不注册；此为纵深防御）。
		$settings = self::settings( array( 'captcha' => array( 'driver' => 'tencent', 'trust' => array( 'enabled' => false ) ) ) );
		$result   = $this->precheck( $settings )->handle( self::input(), true, '203.0.113.7', 0 );
		$this->assertSame( array( 'result' => 'captcha_needed' ), $result );
	}
}
