<?php
/**
 * W6-C 批 · Usage 计量每请求一次落账回归测试（C-9，R-AK §C C-9 + 审计 A2-F4 写放大）。
 *
 * 根因（审计实测）：Usage::bump_option 每次外呼 get_option+update_option 2 查询——
 * 单请求最多 3-4 驱动调用（评论闸云审/IP 信誉/URL 核查各驱动），满配请求 6-10 次
 * 计量查询。修复：bump 累积在静态数组（同请求合并），落账时机=读前 flush
 * （Usage::get 是全仓唯一读口——halt_check/配额判定/软顶评估/面板读数全经此）
 * + shutdown action 兜底（首次 bump 懒挂；WP 核心 wp-settings.php:166
 * register_shutdown_function('shutdown_action_hook') → die()/wp_die 后仍触发，
 * 拦截分支 reject 出口不丢计量）。
 *
 * 零行为变化红线（任务书原文）：计量数值语义逐位不变——bump 后立即读数的调用位
 * 必须读到同请求累积值（读前 flush 保真）；跨日翻转/缺键回种沿用纯 bump() 同源。
 *
 * 桩注记：WinkGatekeeper\Stats 命名空间桩（get_option/update_option/current_time/
 * error_log）与 M6ADriverMeteringTest 逐字同款（$GLOBALS['wk_stats_option_store']/
 * ['wk_stats_calls'] 同键互操作——function_exists 守卫先载者生效，跨文件先后加载
 * 均等价）；add_action 为本批新增桩（挂载记录面）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Stats {

	$GLOBALS['wk_stats_option_store']  = $GLOBALS['wk_stats_option_store'] ?? array();
	$GLOBALS['wk_stats_calls']         = $GLOBALS['wk_stats_calls'] ?? array( 'update' => array(), 'error_log' => array(), 'get' => array() );
	$GLOBALS['wk_stats_update_result'] = $GLOBALS['wk_stats_update_result'] ?? true;
	$GLOBALS['wk_w6c_usage_actions']   = array();

	if ( ! function_exists( 'WinkGatekeeper\Stats\get_option' ) ) {
		/**
		 * get_option 桩（M6ADriverMeteringTest 同款——store 读取 + get 调用记录）。
		 *
		 * @param string $name    option 名。
		 * @param mixed  $default 缺省值。
		 * @return mixed
		 */
		function get_option( string $name, $default = false ) {
			$GLOBALS['wk_stats_calls']['get'][] = $name;
			return array_key_exists( $name, $GLOBALS['wk_stats_option_store'] )
				? $GLOBALS['wk_stats_option_store'][ $name ]
				: $default;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\update_option' ) ) {
		/**
		 * update_option 桩（M6ADriverMeteringTest 同款——update 调用记录 + store 写入）。
		 *
		 * @param string $name     option 名。
		 * @param mixed  $value    值。
		 * @param mixed  $autoload 自动加载（忽略）。
		 * @return bool
		 */
		function update_option( string $name, $value, $autoload = null ): bool {
			$GLOBALS['wk_stats_calls']['update'][] = $name;
			if ( $GLOBALS['wk_stats_update_result'] ) {
				$GLOBALS['wk_stats_option_store'][ $name ] = $value;
			}
			return $GLOBALS['wk_stats_update_result'];
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\current_time' ) ) {
		/**
		 * current_time 桩（M6ADriverMeteringTest 同款——Y-m-d 档固定 2026-11-10）。
		 *
		 * @param string $type 类型。
		 * @return string
		 */
		function current_time( string $type ): string {
			if ( 'Ymd' === $type ) {
				return '20261110';
			}
			if ( 'Y-m-d' === $type ) {
				return '2026-11-10';
			}
			return '';
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\error_log' ) ) {
		/**
		 * error_log 桩（捕获不落盘）。
		 *
		 * @param string $message 消息。
		 * @return bool
		 */
		function error_log( string $message ): bool {
			$GLOBALS['wk_stats_calls']['error_log'][] = $message;
			return true;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\add_action' ) ) {
		/**
		 * add_action 命名空间桩（shutdown flush 挂载记录面——CompatProbeStub::$actions
		 * 同款机制：记录 hook/callback 规格）。
		 *
		 * @param string   $hook_name    钩子名。
		 * @param callable $callback     回调。
		 * @param int      $priority     优先级。
		 * @param int      $accepted_args 参数数。
		 * @return bool
		 */
		function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
			$GLOBALS['wk_w6c_usage_actions'][] = array(
				'hook'     => $hook_name,
				'callback' => $callback,
			);
			return true;
		}
	}
}

namespace WinkGatekeeper\Tests {

	use PHPUnit\Framework\TestCase;
	use WinkGatekeeper\Stats\Usage;

	final class W6CUsagePerRequestTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['wk_stats_option_store'] = array();
			$GLOBALS['wk_stats_calls']        = array( 'update' => array(), 'error_log' => array(), 'get' => array() );
			$GLOBALS['wk_stats_update_result'] = true;
			$GLOBALS['wk_w6c_usage_actions']  = array();
			Usage::reset_request_pending(); // 测试缝：pending/挂载旗复位（生产调用面为零）。
		}

		/* ===============================================================
		 * 钉① 同请求 N 次 bump = 0 次中间写 + flush 一次落账
		 * =============================================================== */

		public function test_c9_bumps_defer_writes_until_flush(): void {
			$GLOBALS['wk_stats_option_store']['wink_gatekeeper_usage'] = array(
				'date'     => '2026-11-10',
				'counters' => array( 'captcha' => 5 ),
			);

			Usage::bump_option( 'captcha' );
			Usage::bump_option( 'captcha' );
			Usage::bump_option( 'captcha' );

			$this->assertSame( array(), $GLOBALS['wk_stats_calls']['update'], '累积期零 option 写（A2-F4 写放大主钉）' );
			$this->assertSame( array(), $GLOBALS['wk_stats_calls']['get'], '累积期零 option 读（读放大同消）' );

			Usage::flush();

			$this->assertCount( 1, $GLOBALS['wk_stats_calls']['update'], 'flush 一次落账' );
			$this->assertSame(
				array( 'date' => '2026-11-10', 'counters' => array( 'captcha' => 8 ) ),
				$GLOBALS['wk_stats_option_store']['wink_gatekeeper_usage'],
				'同请求 3 次 bump 合并=1 次写且计数逐位正确（5+3）'
			);

			Usage::flush();
			$this->assertCount( 1, $GLOBALS['wk_stats_calls']['update'], 'flush 幂等：pending 空再 flush 零写' );
		}

		public function test_c9_mixed_keys_converge_to_single_write(): void {
			Usage::bump_option( 'captcha' );
			Usage::bump_option( 'abuseipdb' );
			Usage::bump_option( 'captcha' );
			Usage::bump_option( 'url_audit_fetch' );

			Usage::flush();

			$this->assertCount( 1, $GLOBALS['wk_stats_calls']['update'], '多键混合同样一次落账' );
			$usage = $GLOBALS['wk_stats_option_store']['wink_gatekeeper_usage'];
			$this->assertSame( 2, $usage['counters']['captcha'], '键内合并计数' );
			$this->assertSame( 1, $usage['counters']['abuseipdb'], '键间独立计数' );
			$this->assertSame( 1, $usage['counters']['url_audit_fetch'], 'url_audit 键位同源' );
			$this->assertSame( '2026-11-10', $usage['date'], 'date=flush 时站时区当日（current_time 桩固定值）' );
		}

		/* ===============================================================
		 * 钉② 读前 flush——bump 后立即读数逐位不变（零行为变化红线）
		 * =============================================================== */

		public function test_c9_read_flushes_pending_first(): void {
			Usage::bump_option( 'captcha' );
			Usage::bump_option( 'deepseek' );

			$usage = Usage::get();

			$this->assertSame( 1, $usage['counters']['captcha'], 'bump 后立即读数=读前 flush 保真（halt_check/配额判定消费面）' );
			$this->assertSame( 1, $usage['counters']['deepseek'], '多键读数保真' );
			$this->assertCount( 1, $GLOBALS['wk_stats_calls']['update'], '读触发恰一次落账' );
			$this->assertSame( array(), Usage::pending(), 'pending 读后清空（幂等可见性钉——pending() 测试缝）' );

			Usage::flush();
			$this->assertCount( 1, $GLOBALS['wk_stats_calls']['update'], '读后再显式 flush 零二次写' );
		}

		public function test_c9_read_after_bump_sees_prior_request_state_plus_pending(): void {
			$GLOBALS['wk_stats_option_store']['wink_gatekeeper_usage'] = array(
				'date'     => '2026-11-10',
				'counters' => array( 'captcha' => 9 ),
			);

			Usage::bump_option( 'captcha' );

			$this->assertSame( 10, Usage::daily_total_for( Usage::get(), 'captcha' ), '存量 9 + 本请求 1 = 10（配额判定读数逐位不变——Bootstrap halt_check 同式消费）' );
		}

		public function test_c9_cross_day_flip_uses_pure_bump_same_source(): void {
			$GLOBALS['wk_stats_option_store']['wink_gatekeeper_usage'] = array(
				'date'     => '2026-11-09',
				'counters' => array( 'captcha' => 7 ),
			);

			Usage::bump_option( 'captcha' );
			Usage::flush();

			$usage = $GLOBALS['wk_stats_option_store']['wink_gatekeeper_usage'];
			$this->assertSame( '2026-11-10', $usage['date'], '跨日翻转沿用纯 bump 同源（flush 时站时区当日）' );
			$this->assertSame( array( 'captcha' => 1 ), $usage['counters'], '翻转清零后本请求计数入新日（与旧每外呼落账语义逐位同形）' );
		}

		/* ===============================================================
		 * 钉③ shutdown flush 在场（首次 bump 懒挂 + 幂等）
		 * =============================================================== */

		public function test_c9_shutdown_flush_registered_lazily_once(): void {
			$this->assertSame( array(), $GLOBALS['wk_w6c_usage_actions'], '零 bump 请求零钩子（懒挂面）' );

			Usage::bump_option( 'captcha' );
			Usage::bump_option( 'abuseipdb' );

			$this->assertCount( 1, $GLOBALS['wk_w6c_usage_actions'], '首次 bump 恰挂一次（幂等）' );
			$this->assertSame( 'shutdown', $GLOBALS['wk_w6c_usage_actions'][0]['hook'], '挂 shutdown action（WP wp-settings.php:166 register_shutdown_function → die()/wp_die 后仍触发——reject 出口兜底）' );
			$this->assertSame(
				array( Usage::class, 'flush' ),
				$GLOBALS['wk_w6c_usage_actions'][0]['callback'],
				'回调=Usage::flush'
			);
		}

		public function test_c9_flush_without_pending_is_noop(): void {
			Usage::flush();
			$this->assertSame( array(), $GLOBALS['wk_stats_calls']['update'], '空 pending flush 零写' );
			$this->assertSame( array(), $GLOBALS['wk_stats_calls']['get'], '空 pending flush 零读（零外呼请求零计量查询）' );

			$usage = Usage::get();
			$this->assertSame( Usage::seed(), $usage, '无计量读数=种子（既有语义）' );
			$this->assertCount( 1, $GLOBALS['wk_stats_calls']['get'], '读口一次 get_option' );
			$this->assertSame( array(), $GLOBALS['wk_stats_calls']['update'], '读路径零写' );
		}

		/* ===============================================================
		 * 钉④ 测试缝 + pending 可见性
		 * =============================================================== */

		public function test_c9_pending_visibility_and_reset_seam(): void {
			$this->assertSame( array(), Usage::pending(), '初始空' );

			Usage::bump_option( 'captcha' );
			Usage::bump_option( 'captcha' );
			$this->assertSame( array( 'captcha' => 2 ), Usage::pending(), '累积态可见（测试缝）' );

			Usage::reset_request_pending();
			$this->assertSame( array(), Usage::pending(), '复位清空' );

			Usage::flush();
			$this->assertSame( array(), $GLOBALS['wk_stats_calls']['update'], '复位后 flush 零写（丢弃语义——仅测试缝）' );

			Usage::bump_option( 'captcha' );
			$this->assertCount( 2, $GLOBALS['wk_w6c_usage_actions'], '复位连带挂载旗：再 bump 重新挂（幂等旗复位语义钉）' );
		}
	}
}
