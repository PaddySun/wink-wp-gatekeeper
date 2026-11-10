<?php
/**
 * CommentGate 编排单元测试（02 §3.1：编排精确落地的纯逻辑面）。
 *
 * 覆盖：步骤 0 管理员豁免 → 1 存根三态（stage=stub；强制验证码标志优先于存根 true 的
 * 条款——M1 标志恒 false、逻辑写好待 M3）→ 2（M1 无 IP 阶段，编排位留注释）→
 * 3 captcha verify（未配置整体跳过=02 §2.1；缺票 captcha_missing；有票才报的上报触发点
 * ——M1 留桩不实现）→ 4 review=LocalRules（M1 单驱动）→ 5 放行。
 * WP 函数（is_admin/wp_die/$_POST 隔离）留边界钩子方法；编排经注入假件全量可测。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\CommentGate;
use WinkGatekeeper\Intercept\Stub;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Verdict;

final class CommentGateTest extends TestCase {

	/**
	 * 固定存根哈希（生产 = wp_hash 封装，Bootstrap 注入）。
	 *
	 * @var callable
	 */
	private static $stub_hash;

	public static function setUpBeforeClass(): void {
		self::$stub_hash = static function ( string $data ): string {
			return 'h_' . hash_hmac( 'sha256', $data, 'gate-test-key' );
		};
	}

	/**
	 * 可编程假验证码驱动（verify 结果可设定）。
	 */
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
			'gates'        => array( 'comment' => array( 'enabled' => true, 'render_mode' => 'always' ) ),
			'review'       => array( 'local' => array(
				'min_length' => 0, 'max_length' => 1800, 'require_chinese' => 1,
				'custom_banned_words' => '', 'use_wp_keywords' => 1,
			) ),
			'copy'         => array( 'messages' => array(
				'captcha_missing' => '还差一个眨眼——完成上方的验证再点提交。',
			) ),
			'stats'        => array( 'enabled' => true, 'pass_sampling' => false ),
		), $overrides );
	}

	/**
	 * 事件记录器假件：捕获 (stage, verdict, meta) 并回固定事件编号。
	 */
	private function recorder( array &$log ): callable {
		return static function ( string $stage, Verdict $verdict, array $meta ) use ( &$log ): string {
			$log[] = array( 'stage' => $stage, 'verdict' => $verdict->verdict(), 'rule' => $verdict->reason(), 'meta' => $meta );
			return 'WK-20261109-0042';
		};
	}

	private function gate( ?object $captcha, array &$log, array $settings_overrides = array(), ?callable $local_overrides = null ): CommentGate {
		return new CommentGate(
			self::settings( $settings_overrides ),
			$captcha,
			null, // review 驱动缺省 → 闸内自建 LocalRules（下面用 local 设置构造）。
			$this->recorder( $log ),
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return 1731168000; // 固定时钟。
			}
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

	private static function valid_stub( string $shown = 'true' ): string {
		// 供测试签发合法存根（与 gate 的固定哈希一致）。
		$hash = self::$stub_hash;
		return Stub::encode_payload( 'comment', '42', 'true' === $shown, 1731167000 ) . '.' . $hash( Stub::encode_payload( 'comment', '42', 'true' === $shown, 1731167000 ) );
	}

	/* ---------- 步骤 0：管理员/后台豁免 ---------- */

	public function test_exempt_context_bypasses_everything(): void {
		$log = array();
		$gate = $this->gate( $this->fake_captcha( '错误' ), $log );

		$result = $gate->orchestrate( array( 'comment_content' => 'x' ), self::request(), self::server(), true );

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertSame( array(), $log, '豁免路径不产生事件' );
	}

	/* ---------- 步骤 1：存根三态 ---------- */

	public function test_missing_stub_rejects_as_bad_signature(): void {
		$log  = array();
		$gate = $this->gate( $this->fake_captcha( null ), $log );

		$result = $gate->orchestrate( array( 'comment_content' => '内容' ), self::request( array( 'wk_stub' => '' ) ), self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'stub', $log[0]['stage'] );
		$this->assertSame( 'reject', $log[0]['verdict'] );
		$this->assertSame( 'bad-signature', $log[0]['rule'] );
		// 证据行只含事件编号/规则/时间（不暴露 IP/原文/reason）。
		$this->assertSame( 'WK-20261109-0042', $result['evidence']['事件编号'] );
		$this->assertArrayNotHasKey( 'ip', $result['evidence'] );
	}

	public function test_tampered_stub_rejects(): void {
		$log  = array();
		$gate = $this->gate( $this->fake_captcha( null ), $log );

		$result = $gate->orchestrate( array( 'comment_content' => '内容' ), self::request( array( 'wk_stub' => 'aGVsbG8.forged' ) ), self::server() );

		$this->assertSame( 'reject', $result['decision'] );
	}

	public function test_stub_true_skips_captcha_but_review_still_runs(): void {
		$log  = array();
		$gate = $this->gate( $this->fake_captcha( '本不应被调用' ), $log );

		// 未展示挑战（stub true）免验证；但 review 阶段照常执行——中文闸仍拦。
		$result = $gate->orchestrate(
			array( 'comment_content' => 'pure english spam content' ),
			self::request( array(
				'wk_stub'  => self::valid_stub( 'false' ),
				'wk_captcha_ticket' => '', // 无票也不拦（免验证通道）。
			) ),
			self::server()
		);

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'review', $log[0]['stage'] );
		$this->assertSame( 'no_chinese', $log[0]['rule'] );
	}

	public function test_force_captcha_flag_overrides_stub_true(): void {
		// v1.2 安全审核 #2 条款：stage:ip 的强制验证码标志优先于存根 true——
		// 存根 true 且强制标志置位 → 按 captcha_missing 拦截（访客手里没有 ticket，
		// 放行等于给干净存根持有者开跳过通道）。M1 标志恒 false，逻辑写好待 M3。
		$log  = array();
		$gate = $this->gate( $this->fake_captcha( null ), $log );

		$result = $gate->orchestrate(
			array( 'comment_content' => '内容' ),
			self::request( array( 'wk_stub' => self::valid_stub( 'false' ) ) ),
			self::server(),
			false,
			true // 强制验证码标志（M3 由 IPDriver review 判定置位）。
		);

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'captcha_missing', $log[0]['rule'] );
		$this->assertSame( 'captcha', $log[0]['stage'] );
	}

	/* ---------- 步骤 2：IP 阶段（M1 留注释编排位） ---------- */

	public function test_ip_stage_slot_is_documented_not_executed(): void {
		// M1 无 IPDriver：编排位见 CommentGate::orchestrate 步骤 2 注释（M3 接入）。
		$log  = array();
		$gate = $this->gate( $this->fake_captcha( null ), $log );

		$result = $gate->orchestrate(
			array( 'comment_content' => '内容' ),
			self::request( array(
				'wk_stub'            => self::valid_stub(),
				'wk_captcha_ticket'  => 'tok',
				'wk_captcha_randstr' => 'r',
			) ),
			self::server()
		);

		$this->assertSame( 'allow', $result['decision'] );
		foreach ( $log as $entry ) {
			$this->assertNotSame( 'ip', $entry['stage'] );
		}
	}

	/* ---------- 步骤 3：captcha verify ---------- */

	public function test_stub_null_without_ticket_rejects_captcha_missing(): void {
		$log  = array();
		$gate = $this->gate( $this->fake_captcha( null ), $log );

		$result = $gate->orchestrate(
			array( 'comment_content' => '内容' ),
			self::request( array( 'wk_stub' => self::valid_stub() ) ), // 展示过但无票。
			self::server()
		);

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'captcha', $log[0]['stage'] );
		$this->assertSame( 'captcha_missing', $log[0]['rule'] );
		$this->assertStringContainsString( '眨眼', $result['message'] );
	}

	public function test_stub_null_with_ticket_verify_error_rejects(): void {
		$log  = array();
		$gate = $this->gate( $this->fake_captcha( '眨眼没对上——刷新页面再试一次？' ), $log );

		$result = $gate->orchestrate(
			array( 'comment_content' => '内容' ),
			self::request( array(
				'wk_stub'            => self::valid_stub(),
				'wk_captcha_ticket'  => 'tok',
				'wk_captcha_randstr' => 'r',
			) ),
			self::server()
		);

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'captcha', $log[0]['stage'] );
		$this->assertSame( '眨眼没对上——刷新页面再试一次？', $result['message'] );
	}

	public function test_verify_pass_continues_to_review(): void {
		$log  = array();
		$gate = $this->gate( $this->fake_captcha( null ), $log );

		$result = $gate->orchestrate(
			array( 'comment_content' => '这是正常的中文内容' ),
			self::request( array(
				'wk_stub'            => self::valid_stub(),
				'wk_captcha_ticket'  => 'tok',
				'wk_captcha_randstr' => 'r',
			) ),
			self::server()
		);

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertSame( array(), $log, '全 pass 默认不落库（04 §3 写入矩阵）' );
	}

	public function test_unconfigured_driver_skips_captcha_stage_entirely(): void {
		// 02 §2.1 未配置行为：等同存根 true 语义——不渲染组件不验证（连存根都不要求）。
		$log  = array();
		$gate = $this->gate( null, $log );

		$result = $gate->orchestrate(
			array( 'comment_content' => '这是正常的中文内容' ),
			self::request( array( 'wk_stub' => '' ) ), // 未渲染 → 无存根，不拦。
			self::server()
		);

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertSame( array(), $log );
	}

	/* ---------- 步骤 4：review（LocalRules 四闸） ---------- */

	public function test_review_reject_intercepts_with_copy_key(): void {
		$log  = array();
		$gate = $this->gate( $this->fake_captcha( null ), $log );

		$result = $gate->orchestrate(
			array( 'comment_content' => 'english only comment' ), // 中文闸。
			self::request( array(
				'wk_stub'            => self::valid_stub(),
				'wk_captcha_ticket'  => 'tok',
				'wk_captcha_randstr' => 'r',
			) ),
			self::server()
		);

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'review', $log[0]['stage'] );
		$this->assertSame( 'local_rules', $log[0]['meta']['driver'] );
		$this->assertSame( 'no_chinese', $log[0]['rule'] );
	}

	/* ---------- 步骤 5：放行与事件编号 ---------- */

	public function test_allow_returns_commentdata_unchanged(): void {
		$log  = array();
		$gate = $this->gate( $this->fake_captcha( null ), $log );
		$commentdata = array( 'comment_content' => '这是正常的中文内容', 'comment_approved' => '1', 'comment_post_ID' => 42 );

		$result = $gate->orchestrate( $commentdata, self::request( array(
			'wk_stub'            => self::valid_stub(),
			'wk_captcha_ticket'  => 'tok',
			'wk_captcha_randstr' => 'r',
		) ), self::server() );

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertSame( '1', $result['commentdata']['comment_approved'] );
	}

	public function test_meta_carries_ip_src_for_audit(): void {
		// 解析路径（ip_src）记入事件 meta 供站长核对（02 §2.4）；存根失败路径亦然。
		$log  = array();
		$gate = $this->gate( $this->fake_captcha( null ), $log );

		$gate->orchestrate( array( 'comment_content' => '内容' ), self::request( array( 'wk_stub' => 'x' ) ), self::server() );

		$this->assertSame( 'direct', $log[0]['meta']['ip_src'] );
	}

	/* ---------- 渲染（comment_form_submit_field 结构位） ---------- */

	public function test_render_returns_empty_when_driver_absent(): void {
		$log  = array();
		$gate = $this->gate( null, $log );
		$this->assertSame( '<submit/>', $gate->render_submit_field( '<submit/>' ) );
	}

	public function test_render_appends_gate_container_before_submit(): void {
		$log   = array();
		$captcha = $this->fake_captcha( null );
		$gate  = new CommentGate(
			self::settings(),
			$captcha,
			null,
			$this->recorder( $log ),
			new IpResolver(),
			self::$stub_hash,
			static function (): int {
				return 1731168000;
			},
			static function (): string {
				return '42'; // 渲染期 context_id（生产 = get_the_ID()）。
			}
		);

		$html = $gate->render_submit_field( '<p class="submit"><button>发表</button></p>' );

		$this->assertStringContainsString( 'wk-gate', $html );
		$this->assertStringContainsString( '<p class="submit">', $html );
		$this->assertLessThan( strpos( $html, '<p class="submit">' ), strpos( $html, 'wk-gate' ) ); // 容器在提交按钮前。
		$this->assertStringContainsString( 'name="wk_stub"', $html );
	}

	public function test_render_empty_when_gate_disabled(): void {
		$log = array();
		$gate = new CommentGate(
			self::settings( array( 'gates' => array( 'comment' => array( 'enabled' => false, 'render_mode' => 'always' ) ) ) ),
			$this->fake_captcha( null ),
			null,
			$this->recorder( $log ),
			new IpResolver(),
			self::$stub_hash,
			static function (): int {
				return 1731168000;
			},
			static function (): string {
				return '42';
			}
		);
		$this->assertSame( '<submit/>', $gate->render_submit_field( '<submit/>' ) );
	}

	/* ---------- M2 反馈2 T2：WP 核心拒绝品牌化（纯逻辑面） ---------- */

	/**
	 * 计数探针驱动（verify 调用计数——缓存命中断言「未被调用」）。
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

	/**
	 * 内存验证短缓存假件（与生产 VerifyCache 同契约）。
	 */
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
				return hash_hmac( 'sha256', $data, 'comment-gate-vc-key' );
			}
		);
	}

	private function gate_with_cache( ?object $captcha, array &$log, \WinkGatekeeper\Gates\VerifyCache $cache ): CommentGate {
		return new CommentGate(
			self::settings(),
			$captcha,
			null,
			$this->recorder( $log ),
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return 1731168000;
			},
			null,
			null,
			array(),
			null,
			null,
			$cache
		);
	}

	public function test_core_duplicate_rejection_records_event_and_returns_brand_page_payload(): void {
		$log  = array();
		$gate = $this->gate( $this->fake_captcha( null ), $log );

		$out = $gate->core_rejection( 'duplicate_comment', self::server(), array( 'post_id' => 42 ) );

		// 事件：gate=comment（记录器闭包固定）、stage=core、verdict=reject、rule_hit=duplicate_comment、driver=wp_core。
		$this->assertCount( 1, $log );
		$this->assertSame( 'core', $log[0]['stage'] );
		$this->assertSame( 'reject', $log[0]['verdict'] );
		$this->assertSame( 'duplicate_comment', $log[0]['rule'] );
		$this->assertSame( 'wp_core', $log[0]['meta']['driver'] );
		$this->assertSame( 'direct', $log[0]['meta']['ip_src'] );
		$this->assertSame( 42, $log[0]['meta']['post_id'] );

		// 品牌页面载荷：文案键命中文案工坊 + 证据行三件套。
		$this->assertStringContainsString( '刚刚已经发过一遍', $out['message'] );
		$this->assertSame( 'WK-20261109-0042', $out['evidence']['事件编号'] );
		$this->assertSame( 'duplicate_comment', $out['evidence']['命中规则'] );
		$this->assertArrayHasKey( '时间', $out['evidence'] );
	}

	public function test_core_flood_rejection_records_event(): void {
		$log  = array();
		$gate = $this->gate( $this->fake_captcha( null ), $log );

		$out = $gate->core_rejection( 'comment_flood', self::server() );

		$this->assertSame( 'core', $log[0]['stage'] );
		$this->assertSame( 'comment_flood', $log[0]['rule'] );
		$this->assertStringContainsString( '稍等几秒', $out['message'] );
	}

	public function test_core_rejection_unknown_rule_falls_back_to_key_as_message(): void {
		$log  = array();
		$gate = $this->gate( $this->fake_captcha( null ), $log );

		$out = $gate->core_rejection( 'unknown_rule_xyz', self::server() );

		$this->assertSame( 'unknown_rule_xyz', $out['message'], '未注册键回退键名（不空白）' );
	}

	public function test_filter_comment_duplicate_message_returns_wink_copy(): void {
		$log  = array();
		$gate = $this->gate( $this->fake_captcha( null ), $log );

		$this->assertStringContainsString(
			'刚刚已经发过一遍',
			$gate->filter_comment_duplicate_message( 'Duplicate comment detected; it looks as though you&#8217;ve already said that!' )
		);
	}

	public function test_filter_comment_flood_message_returns_wink_copy(): void {
		$log  = array();
		$gate = $this->gate( $this->fake_captcha( null ), $log );

		$this->assertStringContainsString(
			'稍等几秒',
			$gate->filter_comment_flood_message( 'You are posting comments too quickly. Slow down.' )
		);
	}

	/* ---------- M2 反馈2 T3：验证短缓存（D+A-lite） ---------- */

	public function test_verify_cache_hit_skips_driver_and_records_pass_event(): void {
		$log    = array();
		$calls  = 0;
		$store  = array();
		$cache  = self::memory_cache( 10, $store );
		$gate   = $this->gate_with_cache( $this->counting_captcha( '不应被调用', $calls ), $log, $cache );

		// 预置缓存命中（模拟先前的成功核验写入）。
		$cache->remember( self::valid_stub(), '203.0.113.7' );

		$result = $gate->orchestrate(
			array( 'comment_content' => '这是正常的中文内容' ),
			self::request( array(
				'wk_stub'            => self::valid_stub(), // 同存根（同表单会话返回重提形态）。
				'wk_captcha_ticket'  => '已用过的票',
				'wk_captcha_randstr' => 'r',
			) ),
			self::server()
		);

		$this->assertSame( 'allow', $result['decision'], '缓存命中：免验票直接进内容闸并放行' );
		$this->assertSame( 0, $calls, '驱动 verify 零调用（探针断言）——不计数 usage' );
		$this->assertCount( 1, $log );
		$this->assertSame( 'captcha', $log[0]['stage'] );
		$this->assertSame( 'pass', $log[0]['verdict'] );
		$this->assertSame( 'verify_cache_hit', $log[0]['rule'] );
		$this->assertTrue( $log[0]['meta']['verify_cache_hit'] );
	}

	public function test_verify_cache_hit_still_runs_review_stage(): void {
		$log   = array();
		$calls = 0;
		$store = array();
		$cache = self::memory_cache( 10, $store );
		$gate  = $this->gate_with_cache( $this->counting_captcha( null, $calls ), $log, $cache );
		$cache->remember( self::valid_stub(), '203.0.113.7' );

		$result = $gate->orchestrate(
			array( 'comment_content' => 'english only' ), // 中文闸必拒：内容闸照常把关。
			self::request( array( 'wk_stub' => self::valid_stub(), 'wk_captcha_ticket' => 't', 'wk_captcha_randstr' => 'r' ) ),
			self::server()
		);

		/* 【v1.4·R2 §2.7 次序修订】四闸前移到 captcha 之前：中文闸 reject 现为唯一事件
		 * （verify_cache_hit pass 事件不再先落——too_short/no_chinese 先于 captcha_missing
		 * 出现，属修复性变化）；「缓存免验票不免内容闸」语义不变。 */
		$this->assertSame( 'reject', $result['decision'], '缓存免验票不免内容闸' );
		$this->assertSame( 'review', $log[0]['stage'] );
		$this->assertSame( 'no_chinese', $log[0]['rule'] );
		$this->assertCount( 1, $log );
	}

	public function test_verify_success_writes_verify_cache(): void {
		$log   = array();
		$calls = 0;
		$store = array();
		$puts  = array();
		$cache = self::memory_cache( 10, $store, $puts );
		$gate  = $this->gate_with_cache( $this->counting_captcha( null, $calls ), $log, $cache );

		$gate->orchestrate(
			array( 'comment_content' => '这是正常的中文内容' ),
			self::request( array( 'wk_stub' => self::valid_stub(), 'wk_captcha_ticket' => 'tok', 'wk_captcha_randstr' => 'r' ) ),
			self::server()
		);

		$this->assertSame( 1, $calls, '未命中 → 正常验票一次' );
		$this->assertCount( 1, $puts, '核验成功（verify 返回 null）→ 写缓存一次' );
		$this->assertSame( 600, $puts[0]['ttl'] );
		// 键绑定本存根+本 IP。
		$mac = static function ( string $data ): string {
			return hash_hmac( 'sha256', $data, 'comment-gate-vc-key' );
		};
		$this->assertSame( 'wk_cv_' . substr( $mac( self::valid_stub() . '|203.0.113.7' ), 0, 16 ), $puts[0]['key'] );
	}

	public function test_verify_failure_does_not_write_verify_cache(): void {
		$log   = array();
		$calls = 0;
		$store = array();
		$puts  = array();
		$cache = self::memory_cache( 10, $store, $puts );
		$gate  = $this->gate_with_cache( $this->counting_captcha( '眨眼没对上', $calls ), $log, $cache );

		$result = $gate->orchestrate(
			array( 'comment_content' => '这是正常的中文内容' ),
			self::request( array( 'wk_stub' => self::valid_stub(), 'wk_captcha_ticket' => 'tok', 'wk_captcha_randstr' => 'r' ) ),
			self::server()
		);

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( array(), $puts, '仅成功写：失败不缓存' );
	}

	public function test_verify_cache_stub_change_does_not_hit(): void {
		$log   = array();
		$calls = 0;
		$store = array();
		$cache = self::memory_cache( 10, $store );
		$gate  = $this->gate_with_cache( $this->counting_captcha( null, $calls ), $log, $cache );

		// 换一张新表单（新存根）：旧缓存键不含新存根 → 未命中。
		$hash = self::$stub_hash;
		$p    = \WinkGatekeeper\Intercept\Stub::encode_payload( 'comment', '42', true, 1731167100 );
		$fresh_stub = $p . '.' . $hash( $p );
		$cache->remember( self::valid_stub(), '203.0.113.7' );

		$gate->orchestrate(
			array( 'comment_content' => '这是正常的中文内容' ),
			self::request( array( 'wk_stub' => $fresh_stub, 'wk_captcha_ticket' => 'tok', 'wk_captcha_randstr' => 'r' ) ),
			self::server()
		);

		$this->assertSame( 1, $calls, '换表单不白嫖：新存根未命中 → 正常验票' );
	}
}
