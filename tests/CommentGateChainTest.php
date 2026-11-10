<?php
/**
 * CommentGate 云端链编排单元测试（02 §2.2 v1.2 串联语义 / 02 §3.1 步骤 4——C4 实装）。
 *
 * 覆盖：
 *   - LocalRules 恒首 + reject 短路（云端驱动零调用）；
 *   - 云端 reject → 拦截（文案 banned_word 档、rule=Label,Score、meta 带 Label/Score）；
 *   - 云端 review → 不拦截、comment_approved='0'（D8）+ 事件 verdict=review；
 *   - error 不终止（failover 链）+ 云端全故障收口（忽略 LocalRules pass、fail_mode 最严者）；
 *   - fail_mode 两态（review 入待审 / open 放行）；
 *   - 停转消费：halt_check 命中 → 零调用 + error 事件；semantic 终态 → halt_mark 回调。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\ReviewDriverInterface;
use WinkGatekeeper\Gates\CommentGate;
use WinkGatekeeper\Intercept\Stub;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Verdict;

final class CommentGateChainTest extends TestCase {

	/** @var callable 固定存根哈希（生产 = wp_hash 封装）。 */
	private static $stub_hash;

	public static function setUpBeforeClass(): void {
		self::$stub_hash = static function ( string $data ): string {
			return 'h_' . hash_hmac( 'sha256', $data, 'chain-test-key' );
		};
	}

	/**
	 * 可编程假云端驱动（check 返回预设 Verdict；可挂 last_check_meta；计调用次数）。
	 */
	private function fake_cloud( Verdict $result, int &$calls, ?array $meta = null ): ReviewDriverInterface {
		return new class( $result, $calls, $meta ) implements ReviewDriverInterface {
			public $calls_ref;
			private $result;
			private $meta;
			public function __construct( Verdict $result, int &$calls, ?array $meta ) {
				$this->result   = $result;
				$this->meta     = $meta;
				$this->calls_ref = &$calls;
			}
			public function check( string $content, array $meta ): Verdict {
				$this->calls_ref++;
				return $this->result;
			}
			public function last_check_meta(): array {
				return $this->meta ?? array();
			}
		};
	}

	private static function settings( array $overrides = array() ): array {
		return array_merge( array(
			'gates'  => array( 'comment' => array( 'enabled' => true, 'render_mode' => 'always' ) ),
			'review' => array(
				'local' => array(
					'min_length' => 0, 'max_length' => 1800, 'require_chinese' => 1,
					'custom_banned_words' => '', 'use_wp_keywords' => 1,
				),
				'cloud' => array(
					'enabled' => true,
					'chain'   => array( 'fake_cloud' ),
					'fake_cloud' => array( 'fail_mode' => 'review' ),
				),
			),
			'copy'   => array( 'messages' => array(
				'banned_word' => '这串字符我们眼熟——换句人话再试试？',
			) ),
			'stats'  => array( 'enabled' => true, 'pass_sampling' => false ),
		), $overrides );
	}

	private function recorder( array &$log ): callable {
		return static function ( string $stage, Verdict $verdict, array $meta ) use ( &$log ): string {
			$log[] = array( 'stage' => $stage, 'verdict' => $verdict->verdict(), 'driver' => $verdict->driver_id(), 'rule' => $verdict->reason(), 'meta' => $meta );
			return 'WK-20261110-0001';
		};
	}

	private function gate( array $settings, array $cloud_chain, array &$log, ?callable $halt_check = null, ?callable $halt_mark = null ): CommentGate {
		return new CommentGate(
			$settings,
			null, // captcha 未装配（评论测试闸内 captcha 阶段整体跳过——C2 同形态）。
			null,
			$this->recorder( $log ),
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return 1731168000;
			},
			null,
			null,
			$cloud_chain,
			$halt_check,
			$halt_mark
		);
	}

	private static function commentdata(): array {
		return array( 'comment_post_ID' => '42', 'comment_content' => '这是一条正常的中文评论', 'comment_author' => '张三', 'comment_author_email' => 'z@example.com' );
	}

	private static function request(): array {
		return array( 'comment_post_ID' => '42', 'comment_content' => '这是一条正常的中文评论' );
	}

	private static function server(): array {
		return array( 'REMOTE_ADDR' => '203.0.113.7' );
	}

	/* ---------- LocalRules 恒首 + 短路 ---------- */

	public function test_local_rules_reject_short_circuits_cloud(): void {
		$log = array();
		$calls = 0;
		$settings = self::settings( array( 'review' => array(
			'local' => array( 'min_length' => 0, 'max_length' => 1800, 'require_chinese' => 1, 'custom_banned_words' => "私活\n", 'use_wp_keywords' => 0 ),
			'cloud' => array( 'enabled' => true, 'chain' => array( 'fake_cloud' ), 'fake_cloud' => array( 'fail_mode' => 'review' ) ),
		) ) );
		$gate = $this->gate( $settings, array( 'fake_cloud' => $this->fake_cloud( Verdict::pass( 'fake_cloud' ), $calls ) ), $log );

		// 正文藏禁词（匹配对象 = 拼接串）：LocalRules 恒首命中 → 云端链整体短路。
		$commentdata = array_merge( self::commentdata(), array( 'comment_content' => '这条评论里藏着私活两个字' ) );
		$result = $gate->orchestrate( $commentdata, array_merge( self::request(), array( 'comment_content' => '这条评论里藏着私活两个字' ) ), self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'banned_word', $log[0]['rule'] );
		$this->assertSame( 'local_rules', $log[0]['driver'] );
		$this->assertSame( 0, $calls, 'LocalRules 命中禁词时云端驱动不得被调用（省调用）' );
	}

	/* ---------- 云端 reject ---------- */

	public function test_cloud_reject_intercepts_with_banned_word_copy(): void {
		$log = array();
		$calls = 0;
		$cloud = $this->fake_cloud( Verdict::reject( 'tencent_tms', 'Ad,99', array( 'Ad' ) ), $calls, array( 'Label' => 'Ad', 'Score' => 99, 'Suggestion' => 'Block' ) );
		$settings = self::settings( array( 'review' => array(
			'local' => self::settings()['review']['local'],
			'cloud' => array( 'enabled' => true, 'chain' => array( 'tencent_tms' ), 'tencent_tms' => array( 'fail_mode' => 'review' ) ),
		) ) );
		$gate = $this->gate( $settings, array( 'tencent_tms' => $cloud ), $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'review', $log[0]['stage'] );
		$this->assertSame( 'reject', $log[0]['verdict'] );
		$this->assertSame( 'tencent_tms', $log[0]['driver'] );
		$this->assertSame( 'Ad,99', $log[0]['rule'] );
		$this->assertSame( 99, $log[0]['meta']['Score'] );
		// 证据行：事件编号/规则名/时间（规则名 = Label,Score；不暴露 IP/原文/reason 细节）。
		$this->assertSame( 'Ad,99', $result['evidence']['命中规则'] );
		$this->assertStringContainsString( '换句人话', (string) $result['message'] );
		$this->assertSame( 1, $calls );
	}

	/* ---------- 云端 review → 入待审 ---------- */

	public function test_cloud_review_sets_comment_approved_zero_and_records_event(): void {
		$log = array();
		$calls = 0;
		$cloud = $this->fake_cloud( Verdict::review( 'tencent_tms', 'Ad,60', array( 'Ad' ) ), $calls );
		$settings = self::settings( array( 'review' => array(
			'local' => self::settings()['review']['local'],
			'cloud' => array( 'enabled' => true, 'chain' => array( 'tencent_tms' ), 'tencent_tms' => array( 'fail_mode' => 'review' ) ),
		) ) );
		$gate = $this->gate( $settings, array( 'tencent_tms' => $cloud ), $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( 'allow', $result['decision'], 'review 不拦截（D8）' );
		$this->assertSame( '0', $result['commentdata']['comment_approved'] );
		$this->assertSame( 'review', $log[0]['verdict'] );
		$this->assertSame( 'tencent_tms', $log[0]['driver'] );
		$this->assertSame( 'Ad,60', $log[0]['rule'] );

		/* C4 真机发现配套：WP 7.x 在 preprocess_comment 后重算 comment_approved——
		 * pre_comment_approved filter（Akismet 同款通道）消费 hold 标志强制 '0'。
		 * 只降不升：核心已批(1) → '0'；他方 spam 保留；非 review 编排不干预。 */
		$this->assertSame( '0', $gate->filter_pre_comment_approved( 1, array() ), '核心已批 + 我方 review → 待审' );
		$this->assertSame( '0', $gate->filter_pre_comment_approved( '0', array() ), '核心原生待审保持' );
		$this->assertSame( 'spam', $gate->filter_pre_comment_approved( 'spam', array() ), '他方 spam 判定保留（只降不升）' );
	}

	public function test_pass_verdict_does_not_touch_pre_comment_approved(): void {
		$log = array();
		$calls = 0;
		$gate = $this->gate( self::settings(), array( 'fake_cloud' => $this->fake_cloud( Verdict::pass( 'fake_cloud' ), $calls ) ), $log );

		$gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( 1, $gate->filter_pre_comment_approved( 1, array() ), '非 review 编排不干预核心判定' );
	}

	/* ---------- 云端 pass → 放行 ---------- */

	public function test_cloud_pass_allows_comment(): void {
		$log = array();
		$calls = 0;
		$gate = $this->gate( self::settings(), array( 'fake_cloud' => $this->fake_cloud( Verdict::pass( 'fake_cloud', 'Normal,0' ), $calls ) ), $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertArrayNotHasKey( 'comment_approved', $result['commentdata'] );
		$this->assertSame( array(), $log, 'pass 默认不落库（04 §3 写入矩阵）' );
	}

	/* ---------- error 不终止（failover） ---------- */

	public function test_cloud_error_does_not_terminate_chain(): void {
		$log = array();
		$calls_a = 0;
		$calls_b = 0;
		$cloud_a = $this->fake_cloud( Verdict::error( 'cloud_a', 'remote_transport' ), $calls_a, array( 'category' => 'transport' ) );
		$cloud_b = $this->fake_cloud( Verdict::reject( 'cloud_b', 'Ad,99', array( 'Ad' ) ), $calls_b );
		$settings = self::settings( array( 'review' => array(
			'local' => self::settings()['review']['local'],
			'cloud' => array( 'enabled' => true, 'chain' => array( 'cloud_a', 'cloud_b' ),
				'cloud_a' => array( 'fail_mode' => 'review' ), 'cloud_b' => array( 'fail_mode' => 'review' ) ),
		) ) );
		$gate = $this->gate( $settings, array( 'cloud_a' => $cloud_a, 'cloud_b' => $cloud_b ), $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( 'reject', $result['decision'], 'error 后链上 reject 仍生效（failover 链）' );
		$this->assertSame( 1, $calls_a );
		$this->assertSame( 1, $calls_b );
		$this->assertSame( 'error', $log[0]['verdict'] );
		$this->assertSame( 'cloud_a', $log[0]['driver'] );
		$this->assertSame( 'reject', $log[1]['verdict'] );
		$this->assertSame( 'cloud_b', $log[1]['driver'] );
	}

	/* ---------- 云端全故障收口 × fail_mode 两态 ---------- */

	public function test_all_cloud_error_fail_mode_review_sends_to_moderation(): void {
		$log = array();
		$calls = 0;
		$cloud = $this->fake_cloud( Verdict::error( 'tencent_tms', 'remote_semantic' ), $calls, array( 'category' => 'semantic' ) );
		$settings = self::settings( array( 'review' => array(
			'local' => self::settings()['review']['local'],
			'cloud' => array( 'enabled' => true, 'chain' => array( 'tencent_tms' ), 'tencent_tms' => array( 'fail_mode' => 'review' ) ),
		) ) );
		$gate = $this->gate( $settings, array( 'tencent_tms' => $cloud ), $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		// 忽略 LocalRules 的 pass、按云端降级档处理（02 §2.2 云端全故障收口——否则云端全故障退化为事实放行）。
		$this->assertSame( 'allow', $result['decision'] );
		$this->assertSame( '0', $result['commentdata']['comment_approved'], 'fail_mode=review → 入待审' );
		$this->assertSame( 'error', $log[0]['verdict'] );
		$this->assertSame( 'tencent_tms', $log[0]['driver'] );
	}

	public function test_all_cloud_error_fail_mode_open_allows(): void {
		$log = array();
		$calls = 0;
		$cloud = $this->fake_cloud( Verdict::error( 'tencent_tms', 'remote_transport' ), $calls );
		$settings = self::settings( array( 'review' => array(
			'local' => self::settings()['review']['local'],
			'cloud' => array( 'enabled' => true, 'chain' => array( 'tencent_tms' ), 'tencent_tms' => array( 'fail_mode' => 'open' ) ),
		) ) );
		$gate = $this->gate( $settings, array( 'tencent_tms' => $cloud ), $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertArrayNotHasKey( 'comment_approved', $result['commentdata'], 'fail_mode=open → 放行不入待审' );
		$this->assertSame( 'error', $log[0]['verdict'], 'error 事件照记（fail-open 不等于无痕）' );
	}

	public function test_mixed_fail_modes_take_strictest_review(): void {
		$log = array();
		$calls_a = 0;
		$calls_b = 0;
		$cloud_a = $this->fake_cloud( Verdict::error( 'cloud_a', 'remote_transport' ), $calls_a );
		$cloud_b = $this->fake_cloud( Verdict::error( 'cloud_b', 'remote_transport' ), $calls_b );
		$settings = self::settings( array( 'review' => array(
			'local' => self::settings()['review']['local'],
			'cloud' => array( 'enabled' => true, 'chain' => array( 'cloud_a', 'cloud_b' ),
				'cloud_a' => array( 'fail_mode' => 'open' ), 'cloud_b' => array( 'fail_mode' => 'review' ) ),
		) ) );
		$gate = $this->gate( $settings, array( 'cloud_a' => $cloud_a, 'cloud_b' => $cloud_b ), $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( '0', $result['commentdata']['comment_approved'], '多驱动 fail_mode 不一致取最严者（fail-review 优先）' );
	}

	/* ---------- 停转消费（halt_check / halt_mark） ---------- */

	public function test_halted_driver_is_not_called_and_records_error_event(): void {
		$log = array();
		$calls = 0;
		$cloud = $this->fake_cloud( Verdict::pass( 'tencent_tms' ), $calls );
		$settings = self::settings( array( 'review' => array(
			'local' => self::settings()['review']['local'],
			'cloud' => array( 'enabled' => true, 'chain' => array( 'tencent_tms' ), 'tencent_tms' => array( 'fail_mode' => 'review' ) ),
		) ) );
		$halt_check = static function ( string $driver_id ): ?string {
			return 'tencent_tms' === $driver_id ? 'config_error' : null;
		};
		$gate = $this->gate( $settings, array( 'tencent_tms' => $cloud ), $log, $halt_check );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( 0, $calls, '停转驱动零调用（防空转——02 §5 快速失败）' );
		$this->assertSame( '0', $result['commentdata']['comment_approved'], '停转 = 云端无有效判定 → 全故障收口 fail_mode=review' );
		$this->assertSame( 'error', $log[0]['verdict'] );
		$this->assertStringContainsString( 'driver_halted:config_error', $log[0]['rule'] );
	}

	public function test_semantic_error_triggers_halt_mark(): void {
		$log = array();
		$calls = 0;
		$marked = array();
		$cloud = $this->fake_cloud( Verdict::error( 'tencent_tms', 'remote_semantic' ), $calls, array( 'category' => 'semantic' ) );
		$settings = self::settings( array( 'review' => array(
			'local' => self::settings()['review']['local'],
			'cloud' => array( 'enabled' => true, 'chain' => array( 'tencent_tms' ), 'tencent_tms' => array( 'fail_mode' => 'review' ) ),
		) ) );
		$halt_mark = static function ( string $driver_id ) use ( &$marked ): void {
			$marked[] = $driver_id;
		};
		$gate = $this->gate( $settings, array( 'tencent_tms' => $cloud ), $log, null, $halt_mark );

		$gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( array( 'tencent_tms' ), $marked, 'semantic 终态 → 当日停转标记' );
	}

	public function test_transport_error_does_not_trigger_halt_mark(): void {
		$log = array();
		$calls = 0;
		$marked = array();
		$cloud = $this->fake_cloud( Verdict::error( 'tencent_tms', 'remote_transport' ), $calls, array( 'category' => 'transport' ) );
		$gate = $this->gate( self::settings(), array( 'fake_cloud' => $cloud ), $log, null, static function ( string $driver_id ) use ( &$marked ): void {
			$marked[] = $driver_id;
		} );

		$gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( array(), $marked, '传输终态不停转（重试已穷尽，次日/网络恢复自动可用）' );
	}

	/* ---------- 开关与链筛选 ---------- */

	public function test_cloud_disabled_skips_chain_entirely(): void {
		$log = array();
		$calls = 0;
		$settings = self::settings( array( 'review' => array(
			'local' => self::settings()['review']['local'],
			'cloud' => array( 'enabled' => false, 'chain' => array( 'fake_cloud' ), 'fake_cloud' => array( 'fail_mode' => 'review' ) ),
		) ) );
		$gate = $this->gate( $settings, array( 'fake_cloud' => $this->fake_cloud( Verdict::reject( 'fake_cloud', 'Ad,99' ), $calls ) ), $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertSame( 0, $calls );
	}

	public function test_chain_id_without_constructed_driver_is_ignored(): void {
		// 默认 chain=['deepseek']（M3 未实装）：不构成「云端全故障」，LocalRules 照常独跑。
		$log = array();
		$gate = $this->gate( self::settings(), array(), $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertArrayNotHasKey( 'comment_approved', $result['commentdata'] );
		$this->assertSame( array(), $log );
	}

	public function test_local_rules_reject_still_wins_when_cloud_disabled(): void {
		$log = array();
		$settings = self::settings( array( 'review' => array(
			'local' => array( 'min_length' => 100, 'max_length' => 1800, 'require_chinese' => 1, 'custom_banned_words' => '', 'use_wp_keywords' => 0 ),
			'cloud' => array( 'enabled' => false, 'chain' => array(), 'fake_cloud' => array( 'fail_mode' => 'review' ) ),
		) ) );
		$gate = $this->gate( $settings, array(), $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'too_short', $log[0]['rule'] );
	}
}
