<?php
/**
 * CommentGate 评论提交限流集成测试（P0-A——M3-IP 定稿 §8.1，AUDIT-CC §2.1/§2.6 P0-A 修复）。
 *
 * 挂点语义（§8.1）：orchestrate() 是原生 wp-comments-post / admin-ajax 主题 AJAX /
 * REST wp/v2/comments 三通道的统一插入面（hook_preprocess_comment / hook_rest_preprocess_comment
 * 两个边界钩子都汇入同一编排）——插入位置=存根校验后、四闸之前。
 *
 * 覆盖：
 *   - 31 次翻转（默认 30 次/h/IP，真 TransientRateLimiter 内存桶全链）；
 *   - 超额响应=统一 captcha_missing 文案（零新文案）+ 事件 stage=rate / meta.rate_limited；
 *   - 不留档（stage=rate 不入垃圾库——stub 同款洪水防膨胀条款，AUDIT P1-B 不放大）；
 *   - 限的是「提交动作」：被四闸拒绝的提交同样计数；
 *   - 同请求幂等计 1（单次编排恰计 1）；
 *   - bad-signature（存根校验失败）与豁免上下文不计数（挂点在存根校验之后）；
 *   - 未装配限流器（null）= 现行行为零变化；
 *   - 限流拒绝先于四闸（超额的脏内容提交也按 rate 拦，不再进 LocalRules）。
 *
 * 三通道协议：AJAX 头协议 / REST WP_Error / 原生品牌页三分支由既有 dispatch_reject 承接
 * （hook_* 为 WP 边界，单测不可达——静态自查清单覆盖；真机走原生通道实测）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\CommentGate;
use WinkGatekeeper\Gates\TransientRateLimiter;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Intercept\Stub;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Verdict;

final class CommentGateRateLimitTest extends TestCase {

	private static $stub_hash;

	public static function setUpBeforeClass(): void {
		self::$stub_hash = static function ( string $data ): string {
			return 'h_' . hash_hmac( 'sha256', $data, 'rate-gate-test-key' );
		};
	}

	private function fake_captcha( ?string $result ): object {
		return new class( $result ) implements \WinkGatekeeper\Drivers\CaptchaDriverInterface {
			private $result;
			public function __construct( ?string $result ) {
				$this->result = $result;
			}
			public function render( array $args ): string {
				$stub = isset( $args['stub'] ) ? (string) $args['stub'] : '';
				return '<p class="wk-gate" data-gate="comment" data-driver="fake"><input type="hidden" name="wk_stub" value="' . $stub . '"><button class="wk-captcha-btn">完成人机验证</button></p>';
			}
			public function verify( array $context ): ?string {
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
				return \WinkGatekeeper\Gates\CaptchaTriInterpreter::legacy_to_tri( $this->verify( $context ), array() );
			}
			public function errorPolicy(): string {
				return 'block';
			}
		};
	}

	private static function settings( array $overrides = array() ): array {
		return array_merge( array(
			'gates'        => array( 'comment' => array(
				'enabled'           => true,
				'render_mode'       => 'always',
				'submit_rate_limit' => 30,
			) ),
			'review'       => array( 'local' => array(
				'min_length' => 0, 'max_length' => 1800, 'require_chinese' => 1,
				'custom_banned_words' => '', 'use_wp_keywords' => 1,
			) ),
			'copy'         => Copywork::defaults(),
			'stats'        => array( 'enabled' => true, 'pass_sampling' => false ),
			'intercept'    => array( 'archive_spam' => true ),
		), $overrides );
	}

	/**
	 * 闸工厂：注入捕获记录器 + 留档器假件 + 可选提交限流器（生产形态 = fn(ip): bool）。
	 *
	 * @param object|null          $captcha 假驱动。
	 * @param array                &$log    事件捕获。
	 * @param array                &$archive 留档捕获。
	 * @param callable|null        $submit_limiter 提交限流器（null = 未装配）。
	 * @param array                $overrides 设置覆盖。
	 * @return CommentGate
	 */
	private function gate( ?object $captcha, array &$log, array &$archive, ?callable $submit_limiter = null, array $overrides = array() ): CommentGate {
		return new CommentGate(
			self::settings( $overrides ),
			$captcha,
			null, // review 缺省 → 闸内自建 LocalRules。
			static function ( string $stage, Verdict $verdict, array $meta ) use ( &$log ): string {
				$log[] = array( 'stage' => $stage, 'verdict' => $verdict->verdict(), 'rule' => $verdict->reason(), 'meta' => $meta );
				return 'WK-20261112-0001';
			},
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return 1731168000; // 固定时钟。
			},
			null,
			static function ( array $comment ) use ( &$archive ): ?int {
				$archive[] = $comment;
				return 9000 + count( $archive );
			},
			array(),
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			$submit_limiter
		);
	}

	private static function request( array $overrides = array() ): array {
		return array_merge( array(
			'comment_post_ID' => '42',
			'comment_content' => '这是一条正常的中文评论',
		), $overrides );
	}

	private static function server(): array {
		return array( 'REMOTE_ADDR' => '203.0.113.7' );
	}

	/**
	 * 免验证存根（captcha_shown=false → verify 返回 true——四闸直通）。
	 */
	private static function stub_pass(): string {
		$hash = self::$stub_hash;
		$p    = Stub::encode_payload( 'comment', '42', false, 1731167000 );
		return $p . '.' . $hash( $p );
	}

	/**
	 * 展示过存根（captcha_shown=true → verify 返回 null——必须验票；配无票请求走 captcha_missing）。
	 */
	private static function stub_shown(): string {
		$hash = self::$stub_hash;
		$p    = Stub::encode_payload( 'comment', '42', true, 1731167000 );
		return $p . '.' . $hash( $p );
	}

	/** 真 TransientRateLimiter（内存桶，生产形态 array(limiter,'allow')）。 */
	private function memory_limiter( int $limit = 30 ): callable {
		$store = array();
		$mac   = static function ( string $data ): string {
			return hash_hmac( 'sha256', $data, 'rate-gate-test-key' );
		};
		$limiter = new TransientRateLimiter(
			$limit,
			3600,
			static function ( string $key ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$store ): void {
				$store[ $key ] = $value;
			},
			$mac
		);
		return array( $limiter, 'allow' );
	}

	/* ---------- 31 次翻转（真限流器全链） ---------- */

	public function test_31st_submission_flips_to_rate_reject(): void {
		$log     = array();
		$archive = array();
		$gate    = $this->gate( $this->fake_captcha( '本不应被调用' ), $log, $archive, $this->memory_limiter( 30 ) );
		$req     = self::request( array( 'wk_stub' => self::stub_pass() ) );

		for ( $i = 0; $i < 30; $i++ ) {
			$result = $gate->orchestrate( array( 'comment_content' => '这是一条正常的中文评论 ' . $i ), $req, self::server() );
			$this->assertSame( 'allow', $result['decision'], "第 " . ( $i + 1 ) . " 次应放行" );
		}

		$result = $gate->orchestrate( array( 'comment_content' => '这是一条正常的中文评论 31' ), $req, self::server() );

		$this->assertSame( 'reject', $result['decision'], '第 31 次应被限流拒绝' );
		$this->assertSame( 1, count( $log ) );
		$this->assertSame( 'rate', $log[0]['stage'], '事件 stage=rate（开放枚举）' );
		$this->assertSame( 'reject', $log[0]['verdict'] );
		$this->assertSame( 'captcha_missing', $log[0]['rule'], '零新文案：复用 captcha_missing 文案键' );
		$this->assertTrue( ! empty( $log[0]['meta']['rate_limited'] ), 'meta.rate_limited 标注可区分' );
		$this->assertSame( '还差一个眨眼——完成人机验证再点提交。', $result['message'], '统一 captcha_missing 文案' );
		$this->assertSame( array(), $archive, '限流拒绝不留档（洪水防膨胀——stub 同款条款）' );
	}

	/* ---------- 超额先于四闸 ---------- */

	public function test_over_limit_rejects_before_local_rules(): void {
		$log     = array();
		$archive = array();
		$gate    = $this->gate( $this->fake_captcha( '本不应被调用' ), $log, $archive, $this->memory_limiter( 0 ) );
		// limit=0（桶语义恒拒）+ 脏内容：限流拒绝必须先于 LocalRules 四闸。
		$result = $gate->orchestrate(
			array( 'comment_content' => 'pure english spam' ),
			self::request( array( 'wk_stub' => self::stub_pass() ) ),
			self::server()
		);

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'rate', $log[0]['stage'], '超额先于四闸——不产生 review 事件' );
		$this->assertSame( 'captcha_missing', $log[0]['rule'] );
	}

	/* ---------- 限的是提交动作：被四闸拒绝的提交也计数 ---------- */

	public function test_four_gate_rejected_submission_still_counts(): void {
		$calls   = 0;
		$store   = array();
		$mac     = static function ( string $data ): string {
			return hash_hmac( 'sha256', $data, 'rate-gate-test-key' );
		};
		$limiter = new TransientRateLimiter(
			1,
			3600,
			static function ( string $key ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$store ): void {
				$store[ $key ] = $value;
			},
			$mac
		);
		$allow   = array( $limiter, 'allow' );

		$log     = array();
		$archive = array();
		$gate    = $this->gate( $this->fake_captcha( '本不应被调用' ), $log, $archive, $allow );
		$req     = self::request( array( 'wk_stub' => self::stub_pass() ) );

		// 第 1 次：脏内容被四闸拒绝——但提交动作已计数（limit=1 桶已满）。
		$r1 = $gate->orchestrate( array( 'comment_content' => 'pure english spam' ), $req, self::server() );
		$this->assertSame( 'reject', $r1['decision'] );
		$this->assertSame( 'review', $log[0]['stage'], '第一次仍走四闸（未超额）' );
		$this->assertSame( 1, count( $store ), '被拒提交已写桶（限的是洪水不是结果）' );

		// 第 2 次：干净内容——配额已被第 1 次的「被拒提交」耗尽 → 限流拒绝。
		$log = array();
		$r2  = $gate->orchestrate( array( 'comment_content' => '这是一条干净的中文评论' ), $req, self::server() );
		$this->assertSame( 'reject', $r2['decision'] );
		$this->assertSame( 'rate', $log[0]['stage'], '被四闸拒绝的提交也计数——第 2 次按限流拦' );
	}

	/* ---------- 同请求幂等计 1 ---------- */

	public function test_single_orchestrate_counts_exactly_once(): void {
		$calls = 0;
		$probe = static function ( string $ip ) use ( &$calls ): bool {
			$calls++;
			return true; // 恒放行（只探测调用次数）。
		};
		$log     = array();
		$archive = array();
		$gate    = $this->gate( $this->fake_captcha( null ), $log, $archive, $probe );
		$req     = self::request( array( 'wk_stub' => self::stub_shown() ) );

		// 一次编排（展示过存根+无票 → captcha_missing 拒绝）只应计 1——单点计数，无第二计数面。
		$result = $gate->orchestrate( array( 'comment_content' => '这是一条正常的中文评论' ), $req, self::server() );
		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 1, $calls, '同请求幂等计 1（单次编排恰一次计数）' );
	}

	/* ---------- 挂点=存根校验后：bad-signature 不计数 ---------- */

	public function test_bad_signature_does_not_count(): void {
		$calls   = 0;
		$probe   = static function ( string $ip ) use ( &$calls ): bool {
			$calls++;
			return true;
		};
		$log     = array();
		$archive = array();
		$gate    = $this->gate( $this->fake_captcha( null ), $log, $archive, $probe );

		$result = $gate->orchestrate( array( 'comment_content' => '内容' ), self::request( array( 'wk_stub' => 'forged.x' ) ), self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'stub', $log[0]['stage'] );
		$this->assertSame( 0, $calls, '挂点在存根校验之后——bad-signature 不计数' );
	}

	/* ---------- 豁免上下文不计数 ---------- */

	public function test_exempt_context_does_not_count(): void {
		$calls   = 0;
		$probe   = static function ( string $ip ) use ( &$calls ): bool {
			$calls++;
			return true;
		};
		$log     = array();
		$archive = array();
		$gate    = $this->gate( $this->fake_captcha( null ), $log, $archive, $probe );

		$result = $gate->orchestrate( array( 'comment_content' => 'x' ), self::request(), self::server(), true );

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertSame( 0, $calls, '管理员/后台豁免不进限流（步骤 0 先于挂点）' );
	}

	/* ---------- 未装配（null）= 现行行为零变化 ---------- */

	public function test_null_limiter_keeps_existing_behavior(): void {
		$log     = array();
		$archive = array();
		$gate    = $this->gate( $this->fake_captcha( '本不应被调用' ), $log, $archive, null );
		$req     = self::request( array( 'wk_stub' => self::stub_pass() ) );

		for ( $i = 0; $i < 40; $i++ ) {
			$result = $gate->orchestrate( array( 'comment_content' => '这是一条正常的中文评论 ' . $i ), $req, self::server() );
			$this->assertSame( 'allow', $result['decision'], '未装配限流器：40 连发零限流（现行行为）' );
		}
		$this->assertSame( array(), $log, '零 rate 事件' );
	}

	/* ----------captcha 未配置（无存根面）时限流仍生效 ---------- */

	public function test_rate_limit_applies_without_captcha_driver(): void {
		$log     = array();
		$archive = array();
		$gate    = $this->gate( null, $log, $archive, $this->memory_limiter( 1 ) );

		$r1 = $gate->orchestrate( array( 'comment_content' => '这是一条正常的中文评论' ), self::request(), self::server() );
		$this->assertSame( 'allow', $r1['decision'], '未配验证码驱动：第 1 次放行（四闸过）' );

		$log = array();
		$r2  = $gate->orchestrate( array( 'comment_content' => '这是另一条正常的中文评论' ), self::request(), self::server() );
		$this->assertSame( 'reject', $r2['decision'], 'captcha 未配置时限流仍生效（无存根面=无存根校验段，挂点仍在四闸之前）' );
		$this->assertSame( 'rate', $log[0]['stage'] );
	}
}
