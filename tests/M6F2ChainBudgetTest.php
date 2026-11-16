<?php
/**
 * M6·批F-b P1-5 评论同步链预算墙单元测试（施工图 §5 D-2——审计 REPORT §4 P1-5）。
 *
 * 覆盖：
 *   - schema 新键 gates.comment.chain_budget=30（秒，0=关）：defaults 在位 /
 *     Sanitize absint 往返（'0'=关合法） / merge_defaults 老树回填；
 *   - orchestrate 起点记 t0（时钟注入可测）：云审串行循环每驱动调用前查剩余——
 *     超墙跳过剩余云审驱动（首个被挡驱动记 error 事件 meta.chain_budget_exceeded=true，
 *     其后驱动 break 零事件零调用——事件行不放大），按既有 fail 口径收口
 *     （fail_mode 语义不变：全故障+review→待审 cloud_all_failed；open→放行）；
 *   - 总耗时封顶断言：消耗的假时钟 = 墙内已发起的最后一次外呼时长（≤ 墙+单次）；
 *   - 0=关回归：预算 0 时多驱动全走零截断；
 *   - 多驱动次序：链中段截断——被挡驱动之前的有效判定照常收口；
 *   - 墙内路径零回归：快速驱动全走、无 chain_budget 事件；云端 reject 照常拦截。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\ReviewDriverInterface;
use WinkGatekeeper\Gates\CommentGate;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Settings\Sanitize;
use WinkGatekeeper\Settings\SettingsSchema;
use WinkGatekeeper\Verdict;

final class M6F2ChainBudgetTest extends TestCase {

	/** @var int 可推进假时钟（生产 = time()；慢桩驱动 check() 推进它模拟外呼耗时）。 */
	private int $clock = 1731168000;

	protected function setUp(): void {
		$this->clock = 1731168000;
	}

	/**
	 * 可编程云端驱动：check() 推进假时钟 $consume 秒（模拟慢外呼——B 机真机验证用
	 * 真 sleep 慢桩，单测零等待等价形态）并计调用次数。
	 */
	private function timed_cloud( string $id, Verdict $result, int $consume, array &$calls, array &$order_seq ): ReviewDriverInterface {
		$bump_clock = function ( int $delta ): void {
			$this->clock += $delta; // 闭包绑定测试用例实例——直接推进假时钟属性。
		};
		return new class( $id, $result, $consume, $calls, $order_seq, $bump_clock ) implements ReviewDriverInterface {
			public $calls_ref;
			public $order_ref;
			public $bump_clock;
			private $id;
			private $result;
			private $consume;
			private $seq = 0;
			public function __construct( string $id, Verdict $result, int $consume, array &$calls, array &$order_seq, callable $bump_clock ) {
				$this->id         = $id;
				$this->result     = $result;
				$this->consume    = $consume;
				$this->calls_ref  = &$calls;
				$this->order_ref  = &$order_seq;
				$this->bump_clock = $bump_clock;
			}
			public function check( string $content, array $meta ): Verdict {
				$this->calls_ref[ $this->id ] = ( $this->calls_ref[ $this->id ] ?? 0 ) + 1;
				$this->order_ref[]            = $this->id . '#' . ( ++$this->seq );
				call_user_func( $this->bump_clock, $this->consume ); // 模拟外呼耗时（真机=真实 sleep）。
				return $this->result;
			}
			public function last_check_meta(): array {
				return array();
			}
		};
	}

	private static function base_settings( array $overrides = array() ): array {
		return array_merge( array(
			'gates'  => array( 'comment' => array(
				'enabled'      => true,
				'render_mode'  => 'always',
				'chain_budget' => 30, // schema 默认（0=关）。
			) ),
			'review' => array(
				'local' => array( 'min_length' => 0, 'max_length' => 1800, 'require_chinese' => 1, 'custom_banned_words' => '', 'use_wp_keywords' => 0 ),
				'cloud' => array(
					'enabled' => true,
					'chain'   => array(),
					'drv_a'   => array( 'fail_mode' => 'review' ),
					'drv_b'   => array( 'fail_mode' => 'review' ),
					'drv_c'   => array( 'fail_mode' => 'review' ),
					'drv_d'   => array( 'fail_mode' => 'review' ),
				),
			),
			'copy'   => Copywork::defaults(),
			'stats'  => array( 'enabled' => true, 'pass_sampling' => false ),
		), $overrides );
	}

	private function gate( array $settings, array $cloud_chain, array &$log ): CommentGate {
		$clock = &$this->clock;
		return new CommentGate(
			$settings,
			null,
			null,
			static function ( string $stage, Verdict $verdict, array $meta ) use ( &$log ): string {
				$log[] = array( 'stage' => $stage, 'verdict' => $verdict->verdict(), 'driver' => $verdict->driver_id(), 'rule' => $verdict->reason(), 'meta' => $meta );
				return 'WK-20261116-0001';
			},
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			static function ( string $data ): string {
				return 'h_' . hash_hmac( 'sha256', $data, 'f2-budget-key' );
			},
			static function () use ( &$clock ): int {
				return $clock;
			},
			null,
			null,
			$cloud_chain
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

	/** @param array<string, ReviewDriverInterface> $drivers */
	private function chain_settings( array $ids, array $fail_modes = array() ): array {
		$cloud = array( 'enabled' => true, 'chain' => $ids );
		foreach ( $ids as $id ) {
			$cloud[ $id ] = array( 'fail_mode' => $fail_modes[ $id ] ?? 'review' );
		}
		return self::base_settings( array( 'review' => array(
			'local' => self::base_settings()['review']['local'],
			'cloud' => $cloud,
		) ) );
	}

	/* ---------- schema：gates.comment.chain_budget ---------- */

	public function test_schema_defaults_chain_budget_30(): void {
		$defaults = SettingsSchema::defaults();
		$this->assertArrayHasKey( 'chain_budget', $defaults['gates']['comment'], 'schema 新键在位' );
		$this->assertSame( 30, $defaults['gates']['comment']['chain_budget'], '默认 30 秒（§0-6 防护默认值）' );
	}

	public function test_sanitize_chain_budget_absint_roundtrip(): void {
		list( $clean, $rejected ) = Sanitize::clean( array(
			'gates' => array( 'comment' => array( 'chain_budget' => ' 45 ' ) ),
		) );
		$this->assertSame( 45, $clean['gates']['comment']['chain_budget'] );
		$this->assertSame( array(), $rejected );

		// 0=关（显式关断合法值）。
		list( $clean0, ) = Sanitize::clean( array(
			'gates' => array( 'comment' => array( 'chain_budget' => '0' ) ),
		) );
		$this->assertSame( 0, $clean0['gates']['comment']['chain_budget'] );

		// 非标量归类型默认（absint → 0）。
		list( $cleanN, ) = Sanitize::clean( array(
			'gates' => array( 'comment' => array( 'chain_budget' => array( 'x' ) ) ),
		) );
		$this->assertSame( 0, $cleanN['gates']['comment']['chain_budget'] );
	}

	public function test_merge_defaults_backfills_chain_budget_on_old_tree(): void {
		$old = array( 'gates' => array( 'comment' => array( 'enabled' => true, 'render_mode' => 'always' ) ) );
		$merged = SettingsSchema::merge_defaults( $old );
		$this->assertSame( 30, $merged['gates']['comment']['chain_budget'], '升级站点老树回填默认 30' );
	}

	/* ---------- 预算墙：超墙跳过剩余云审驱动 ---------- */

	public function test_budget_wall_skips_remaining_drivers_and_marks_event_meta(): void {
		$log = array();
		$calls = array();
		$order = array();
		// 链=[慢桩 40s, 快桩, 快桩]，预算=默认 30：慢桩在墙内发起（墙+单次语义——耗时
		// 40s 封顶而非 215s 叠加），其后两驱动超墙全跳过。
		$chain = array(
			'drv_a' => $this->timed_cloud( 'drv_a', Verdict::pass( 'drv_a' ), 40, $calls, $order ),
			'drv_b' => $this->timed_cloud( 'drv_b', Verdict::pass( 'drv_b' ), 2, $calls, $order ),
			'drv_c' => $this->timed_cloud( 'drv_c', Verdict::pass( 'drv_c' ), 2, $calls, $order ),
		);
		$gate = $this->gate( $this->chain_settings( array( 'drv_a', 'drv_b', 'drv_c' ) ), $chain, $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		// 次序与截断：仅 drv_a 真调用；drv_b/drv_c 零调用。
		$this->assertSame( array( 'drv_a#1' ), $order, '仅墙内首个驱动被调用' );
		$this->assertSame( 1, $calls['drv_a'] ?? 0 );
		$this->assertFalse( isset( $calls['drv_b'] ), '超墙驱动 drv_b 零调用' );
		$this->assertFalse( isset( $calls['drv_c'] ), '超墙驱动 drv_c 零调用' );

		// 总耗时封顶：消耗假时钟=40（单次墙内外呼）≤ 墙 30+单次 40——同步链不再无界挂起。
		$this->assertSame( 40, $this->clock - 1731168000, '总耗时=墙内已发起的单次外呼时长' );
		$this->assertLessThanOrEqual( 30 + 40, $this->clock - 1731168000, '总耗时 ≤ 墙+单次' );

		// 跳过面事件：恰一条 error（首个被挡驱动 drv_b）——其后续驱动零事件（行数不放大）。
		$budget_events = array_values( array_filter( $log, static function ( array $row ): bool {
			return 'chain_budget_exceeded' === $row['rule'];
		} ) );
		$this->assertCount( 1, $budget_events, '恰一条预算墙 error 事件（首个被挡驱动）' );
		$this->assertSame( 'review', $budget_events[0]['stage'] );
		$this->assertSame( 'error', $budget_events[0]['verdict'] );
		$this->assertSame( 'drv_b', $budget_events[0]['driver'] );
		$this->assertTrue( $budget_events[0]['meta']['chain_budget_exceeded'], '事件 meta.chain_budget_exceeded=true' );
		$this->assertSame( 30, $budget_events[0]['meta']['budget_seconds'], 'meta 记预算墙值（可诊断）' );

		// 按既有 fail 口径收口（fail_mode 语义不变）：无有效判定 + fail_mode 全 review
		// → cloud_all_failed 待审（comment_approved='0'）。
		$this->assertSame( 'allow', $result['decision'] );
		$this->assertSame( '0', $result['commentdata']['comment_approved'], 'fail-review 收口=待审' );

		// 待审收口事件（filter 消费位）meta 亦带预算墙标记。
		$this->assertSame( '0', $gate->filter_pre_comment_approved( 1, array() ) );
		$hold_events = array_values( array_filter( $log, static function ( array $row ): bool {
			return 'cloud_all_failed' === $row['rule'];
		} ) );
		$this->assertCount( 1, $hold_events, 'cloud_all_failed 收口事件恰一条' );
		$this->assertTrue( $hold_events[0]['meta']['chain_budget_exceeded'], '收口事件 meta 预算墙标记在位' );
	}

	public function test_fail_mode_open_truncated_with_pass_so_far_stays_open(): void {
		$log = array();
		$calls = array();
		$order = array();
		// 截断 + 墙内仅 pass 判定 + fail-open：放行（fail_mode 语义不变——open 档不吃待审兜底）。
		$chain = array(
			'drv_a' => $this->timed_cloud( 'drv_a', Verdict::pass( 'drv_a' ), 40, $calls, $order ),
			'drv_b' => $this->timed_cloud( 'drv_b', Verdict::pass( 'drv_b' ), 2, $calls, $order ),
		);
		$gate = $this->gate(
			$this->chain_settings( array( 'drv_a', 'drv_b' ), array( 'drv_a' => 'open', 'drv_b' => 'open' ) ),
			$chain,
			$log
		);

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( array( 'drv_a#1' ), $order, '截断照常发生' );
		$this->assertSame( 'allow', $result['decision'], 'fail-open 截断→放行（语义不变）' );
		$this->assertArrayNotHasKey( 'comment_approved', $result['commentdata'], 'fail-open 不动审批态' );
		$this->assertSame( 1, $gate->filter_pre_comment_approved( 1, array() ), '无 hold 标志' );
	}

	public function test_budget_wall_active_by_default_when_settings_lack_key(): void {
		$log = array();
		$calls = array();
		$order = array();
		$settings = $this->chain_settings( array( 'drv_a', 'drv_b' ) );
		unset( $settings['gates']['comment']['chain_budget'] ); // 老树形态（merge_defaults 前）。
		$chain = array(
			'drv_a' => $this->timed_cloud( 'drv_a', Verdict::pass( 'drv_a' ), 40, $calls, $order ),
			'drv_b' => $this->timed_cloud( 'drv_b', Verdict::pass( 'drv_b' ), 2, $calls, $order ),
		);
		$gate = $this->gate( $settings, $chain, $log );

		$gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( array( 'drv_a#1' ), $order, '缺键时默认 30s 墙生效（防护默认值不依赖存储树回填时点）' );
		$this->assertFalse( isset( $calls['drv_b'] ) );
	}

	public function test_budget_wall_truncates_mid_chain_preserving_earlier_verdicts(): void {
		$log = array();
		$calls = array();
		$order = array();
		// 链=[快 10s, 快 25s, 快 5s, 快 1s]，预算 30：drv_a/drv_b 墙内（10、35 发起时刻
		// 均未过墙——drv_b 发起时刻 t=10<30，完成后 t=35），drv_c 发起时刻 t=35≥30 被挡，
		// drv_c 之后 break（drv_d 零调用零事件）；前两驱动有效判定照常收口。
		$chain = array(
			'drv_a' => $this->timed_cloud( 'drv_a', Verdict::pass( 'drv_a' ), 10, $calls, $order ),
			'drv_b' => $this->timed_cloud( 'drv_b', Verdict::review( 'drv_b', 'Ad,60', array( 'Ad' ) ), 25, $calls, $order ),
			'drv_c' => $this->timed_cloud( 'drv_c', Verdict::pass( 'drv_c' ), 5, $calls, $order ),
			'drv_d' => $this->timed_cloud( 'drv_d', Verdict::pass( 'drv_d' ), 1, $calls, $order ),
		);
		$gate = $this->gate( $this->chain_settings( array( 'drv_a', 'drv_b', 'drv_c', 'drv_d' ) ), $chain, $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( array( 'drv_a#1', 'drv_b#1' ), $order, '墙内两驱动按链序全走' );
		$this->assertFalse( isset( $calls['drv_c'] ), '超墙驱动 drv_c 零调用' );
		$this->assertFalse( isset( $calls['drv_d'] ), 'break 后 drv_d 零调用零事件' );

		$budget_events = array_values( array_filter( $log, static function ( array $row ): bool {
			return 'chain_budget_exceeded' === $row['rule'];
		} ) );
		$this->assertCount( 1, $budget_events, '恰一条预算墙事件（drv_c）' );
		$this->assertSame( 'drv_c', $budget_events[0]['driver'] );

		// 墙内有效判定照常收口：drv_b review → 待审（来源=cloud，非 cloud_all_failed）。
		$this->assertSame( 'allow', $result['decision'] );
		$this->assertSame( '0', $result['commentdata']['comment_approved'] );
		$this->assertSame( '0', $gate->filter_pre_comment_approved( 1, array() ) );
		$hold = array_values( array_filter( $log, static function ( array $row ): bool {
			return 'hold_moderation' === $row['rule'];
		} ) );
		$this->assertCount( 1, $hold, 'review 收口事件恰一条' );
		$this->assertSame( 'cloud', $hold[0]['meta']['source'] );
		$this->assertTrue( $hold[0]['meta']['chain_budget_exceeded'], '收口事件亦标记预算墙（截断后收口可溯源）' );
	}

	public function test_fail_mode_open_stays_open_on_budget_exhaustion(): void {
		$log = array();
		$calls = array();
		$order = array();
		$chain = array(
			'drv_a' => $this->timed_cloud( 'drv_a', Verdict::error( 'drv_a', 'remote_transport', array( 'remote_transport' ) ), 40, $calls, $order ),
			'drv_b' => $this->timed_cloud( 'drv_b', Verdict::pass( 'drv_b' ), 2, $calls, $order ),
		);
		$gate = $this->gate(
			$this->chain_settings( array( 'drv_a', 'drv_b' ), array( 'drv_a' => 'open', 'drv_b' => 'open' ) ),
			$chain,
			$log
		);

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( array( 'drv_a#1' ), $order );
		$this->assertFalse( isset( $calls['drv_b'] ), '超墙跳过' );
		// fail-open 语义不变：放行（error 事件照记——fail-open 不等于无痕）。
		$this->assertSame( 'allow', $result['decision'] );
		$this->assertArrayNotHasKey( 'comment_approved', $result['commentdata'], 'fail-open 不动审批态' );
		$this->assertSame( 1, $gate->filter_pre_comment_approved( 1, array() ), '无 hold 标志——核心判定不被降级' );
	}

	/* ---------- 0=关回归 ---------- */

	public function test_budget_zero_disables_wall_all_drivers_run(): void {
		$log = array();
		$calls = array();
		$order = array();
		$settings = $this->chain_settings( array( 'drv_a', 'drv_b', 'drv_c' ) );
		$settings['gates']['comment']['chain_budget'] = 0;
		$chain = array(
			'drv_a' => $this->timed_cloud( 'drv_a', Verdict::pass( 'drv_a' ), 40, $calls, $order ),
			'drv_b' => $this->timed_cloud( 'drv_b', Verdict::pass( 'drv_b' ), 40, $calls, $order ),
			'drv_c' => $this->timed_cloud( 'drv_c', Verdict::pass( 'drv_c' ), 40, $calls, $order ),
		);
		$gate = $this->gate( $settings, $chain, $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( array( 'drv_a#1', 'drv_b#1', 'drv_c#1' ), $order, '0=关：三驱动全走零截断（累计 120s 现行为回归）' );
		$budget_events = array_values( array_filter( $log, static function ( array $row ): bool {
			return 'chain_budget_exceeded' === $row['rule'];
		} ) );
		$this->assertSame( array(), $budget_events, '0=关零预算墙事件' );
		$this->assertSame( 'allow', $result['decision'] );
	}

	/* ---------- 墙内路径零回归 ---------- */

	public function test_fast_chain_within_budget_untouched(): void {
		$log = array();
		$calls = array();
		$order = array();
		$chain = array(
			'drv_a' => $this->timed_cloud( 'drv_a', Verdict::pass( 'drv_a' ), 2, $calls, $order ),
			'drv_b' => $this->timed_cloud( 'drv_b', Verdict::review( 'drv_b', 'Ad,60', array( 'Ad' ) ), 2, $calls, $order ),
		);
		$gate = $this->gate( $this->chain_settings( array( 'drv_a', 'drv_b' ) ), $chain, $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( array( 'drv_a#1', 'drv_b#1' ), $order, '快速链两驱动全走' );
		$this->assertSame( '0', $result['commentdata']['comment_approved'], 'review 收口不变' );
		$this->assertSame( '0', $gate->filter_pre_comment_approved( 1, array() ), '收口事件经 filter 消费位落痕' );
		$budget_events = array_values( array_filter( $log, static function ( array $row ): bool {
			return 'chain_budget_exceeded' === $row['rule'];
		} ) );
		$this->assertSame( array(), $budget_events, '墙内零预算墙事件' );
		$hold = array_values( array_filter( $log, static function ( array $row ): bool {
			return 'hold_moderation' === $row['rule'];
		} ) );
		$this->assertCount( 1, $hold );
		$this->assertArrayNotHasKey( 'chain_budget_exceeded', $hold[0]['meta'], '未超墙收口事件不带预算墙标记' );
	}

	public function test_cloud_reject_within_budget_unaffected(): void {
		$log = array();
		$calls = array();
		$order = array();
		$chain = array(
			'drv_a' => $this->timed_cloud( 'drv_a', Verdict::reject( 'drv_a', 'Ad,99', array( 'Ad' ) ), 5, $calls, $order ),
			'drv_b' => $this->timed_cloud( 'drv_b', Verdict::pass( 'drv_b' ), 2, $calls, $order ),
		);
		$gate = $this->gate( $this->chain_settings( array( 'drv_a', 'drv_b' ) ), $chain, $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( 'reject', $result['decision'], '云端高置信直拒照常拦截' );
		$this->assertSame( array( 'drv_a#1' ), $order, 'reject 终止省调用语义不变' );
	}

	public function test_error_failover_chain_within_budget_untouched(): void {
		$log = array();
		$calls = array();
		$order = array();
		// error 不终止（failover 链）语义在墙内不变：drv_a error(2s) → drv_b 照调。
		$chain = array(
			'drv_a' => $this->timed_cloud( 'drv_a', Verdict::error( 'drv_a', 'remote_transport', array( 'remote_transport' ) ), 2, $calls, $order ),
			'drv_b' => $this->timed_cloud( 'drv_b', Verdict::pass( 'drv_b' ), 2, $calls, $order ),
		);
		$gate = $this->gate( $this->chain_settings( array( 'drv_a', 'drv_b' ) ), $chain, $log );

		$gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( array( 'drv_a#1', 'drv_b#1' ), $order, 'error 不终止语义不变（墙内）' );
	}
}
