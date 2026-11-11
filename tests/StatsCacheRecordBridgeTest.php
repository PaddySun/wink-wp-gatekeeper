<?php
/**
 * StatsCache::record() 写入桥行为测试（M2 debug FIX-δ，2026-11-10）。
 *
 * 复现 P1-δ（DEFECT-ROOTCAUSE-2026-11-10）：record() 裸读
 * wink_gatekeeper_settings（无 merge_defaults 兜底）——存储树缺 stats 子树时
 * empty($settings['stats']['enabled']) 为真 → 静默跳过计数（U2 实测「事件 10 条 vs
 * 计数 5 条」口径分裂）。修复：经 SettingsSchema::merge_defaults 兜底（缺子树=默认
 * enabled=true，04 §2 默认树），update_option 失败记 error_log 一次。
 *
 * 注入方式：在被测类同命名空间（WinkGatekeeper\Stats）定义 get_option/update_option/
 * current_time/error_log 桩（PHP 函数解析先命中命名空间函数）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Stats {

	/** 桩 option 存储。 */
	$GLOBALS['wk_stats_option_store'] = array();
	/** 桩调用记录。 */
	$GLOBALS['wk_stats_calls'] = array( 'update' => array(), 'error_log' => array() );
	/** update_option 返回值开关（默认 true）。 */
	$GLOBALS['wk_stats_update_result'] = true;

	if ( ! function_exists( 'WinkGatekeeper\Stats\get_option' ) ) {
		/**
		 * get_option 桩。
		 *
		 * @param string $name    option 名。
		 * @param mixed  $default 缺省值。
		 * @return mixed
		 */
		function get_option( string $name, $default = false ) {
			return array_key_exists( $name, $GLOBALS['wk_stats_option_store'] )
				? $GLOBALS['wk_stats_option_store'][ $name ]
				: $default;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\update_option' ) ) {
		/**
		 * update_option 桩：按 $GLOBALS['wk_stats_update_result'] 返回，真值才写桩存储。
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
		 * current_time 桩：固定测试日（可按测试覆写）。
		 *
		 * @param string $type 类型（只支持 Ymd 形态断言用）。
		 * @return string
		 */
		function current_time( string $type ): string {
			return 'Ymd' === $type ? '20261110' : '';
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\error_log' ) ) {
		/**
		 * error_log 桩：捕获调用（不落盘）。
		 *
		 * @param string $message 消息。
		 * @return bool
		 */
		function error_log( string $message ): bool {
			$GLOBALS['wk_stats_calls']['error_log'][] = $message;
			return true;
		}
	}
}

namespace WinkGatekeeper\Tests {

	use PHPUnit\Framework\TestCase;
	use WinkGatekeeper\Stats\StatsCache;

	final class StatsCacheRecordBridgeTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['wk_stats_option_store'] = array();
			$GLOBALS['wk_stats_calls']        = array( 'update' => array(), 'error_log' => array() );
			$GLOBALS['wk_stats_update_result'] = true;
		}

		public function test_record_counts_when_settings_option_lacks_stats_key(): void {
			// P1-δ 复现用例：非完整设置树（无 stats 子树——部署工具/缓存链/旧态残留形态）
			// 下 record 不得跳过（修复前：裸读 empty() 为真 → 静默 return → 断言失败）。
			$GLOBALS['wk_stats_option_store']['wink_gatekeeper_settings'] = array(
				'schema_version' => 1,
				'gates'          => array( 'comment' => array( 'enabled' => true, 'render_mode' => 'always' ) ),
			);

			StatsCache::record( 'comment', 'local_rules', 'reject' );

			$this->assertCount( 1, $GLOBALS['wk_stats_calls']['update'], '缺 stats 键的 option 下计数不得跳过（P1-δ）' );
			$cache = $GLOBALS['wk_stats_option_store'][ StatsCache::OPTION_NAME ];
			$this->assertSame( 1, $cache['by_gate']['comment']['reject'] );
			$this->assertSame( '20261110', $cache['today'] );
		}

		public function test_record_counts_when_settings_option_missing_entirely(): void {
			// option 整体缺失（新装/被清）= 默认树 enabled=true → 计数。
			StatsCache::record( 'comment', 'tencent', 'reject' );

			$this->assertCount( 1, $GLOBALS['wk_stats_calls']['update'] );
			$this->assertSame( 1, $GLOBALS['wk_stats_option_store'][ StatsCache::OPTION_NAME ]['by_driver']['tencent']['reject'] );
		}

		public function test_record_still_skips_when_stats_explicitly_disabled(): void {
			// 既有语义回归：显式 stats.enabled=false 仍跳过（面板应出「计数已停用」标记）。
			$GLOBALS['wk_stats_option_store']['wink_gatekeeper_settings'] = array(
				'stats' => array( 'enabled' => false ),
			);

			StatsCache::record( 'comment', 'tencent', 'reject' );

			$this->assertCount( 0, $GLOBALS['wk_stats_calls']['update'], '显式关闭统计开关：仍应跳过计数' );
			$this->assertArrayNotHasKey( StatsCache::OPTION_NAME, $GLOBALS['wk_stats_option_store'] );
		}

		public function test_record_logs_error_once_when_update_option_fails(): void {
			// FIX-δ：update_option 返回 false → error_log 记一次（04 §4 v1.3 统计 best-effort 例外要留痕）。
			$GLOBALS['wk_stats_update_result'] = false;

			StatsCache::record( 'comment', 'tencent', 'reject' );

			$this->assertCount( 1, $GLOBALS['wk_stats_calls']['error_log'], '写入失败应恰好记一次 error_log' );
			$this->assertStringContainsString( StatsCache::OPTION_NAME, $GLOBALS['wk_stats_calls']['error_log'][0] );
		}

		public function test_record_no_error_log_when_update_succeeds(): void {
			StatsCache::record( 'comment', 'tencent', 'reject' );
			$this->assertCount( 0, $GLOBALS['wk_stats_calls']['error_log'] );
		}

		/* ---------- B1b·F4-6：attempts 维边界桥（wp_login_failed 直记路径） ---------- */

		public function test_record_attempts_bumps_login_attempts_dimension(): void {
			StatsCache::record_attempts( 'login' );
			StatsCache::record_attempts( 'login' );
			StatsCache::record_attempts( 'login' );

			$cache = $GLOBALS['wk_stats_option_store'][ StatsCache::OPTION_NAME ];
			$this->assertSame( 3, $cache['by_gate']['login']['attempts'], 'attempts 今日+累计双记（04 §4 v1.5）' );
			$this->assertSame( 3, $cache['total']['by_gate']['login']['attempts'] );
			$this->assertSame( 0, $cache['total']['reject'], '失败尝试非判定事件——不推高拦截徽标' );
		}

		public function test_record_attempts_skips_when_stats_explicitly_disabled(): void {
			$GLOBALS['wk_stats_option_store']['wink_gatekeeper_settings'] = array(
				'stats' => array( 'enabled' => false ),
			);

			StatsCache::record_attempts( 'login' );

			$this->assertCount( 0, $GLOBALS['wk_stats_calls']['update'], '统计开关关：attempts 同样不计数' );
		}

		public function test_record_attempts_counts_when_settings_lacks_stats_key(): void {
			// P1-δ 同款防御：merge_defaults 兜底缺子树（enabled=true 默认）。
			$GLOBALS['wk_stats_option_store']['wink_gatekeeper_settings'] = array(
				'schema_version' => 2,
				'gates'          => array( 'login' => array( 'enabled' => true, 'render_mode' => 'adaptive' ) ),
			);

			StatsCache::record_attempts( 'login' );

			$this->assertCount( 1, $GLOBALS['wk_stats_calls']['update'] );
			$cache = $GLOBALS['wk_stats_option_store'][ StatsCache::OPTION_NAME ];
			$this->assertSame( 1, $cache['by_gate']['login']['attempts'] );
		}

		/* ---------- 【O3 §三卡 2】disaster_pass 计数特例键（仪表盘卡片读 option 非 COUNT 明细） ---------- */

		public function test_record_disaster_pass_bumps_dedicated_key(): void {
			// CommentGate 默认 recorder 在 meta.disaster_pass 语境调 record('comment', driver,
			// 'disaster_pass')——独立计数键落 by_gate/by_driver 双树（今日+累计），pass 维不动
			// （由 pass 判定行照常 bump——两键并行不互斥）。
			StatsCache::record( 'comment', 'tencent', 'disaster_pass' );
			StatsCache::record( 'comment', 'tencent', 'disaster_pass' );

			$cache = $GLOBALS['wk_stats_option_store'][ StatsCache::OPTION_NAME ];
			$this->assertSame( 2, $cache['by_gate']['comment']['disaster_pass'], '今日树独立键' );
			$this->assertSame( 2, $cache['by_driver']['tencent']['disaster_pass'] );
			$this->assertSame( 2, $cache['total']['by_gate']['comment']['disaster_pass'], '累计树同步滚动' );
			$this->assertArrayNotHasKey( 'pass', $cache['by_gate']['comment'], 'disaster_pass 键独立——不混入 pass 维' );
		}

		public function test_default_recorder_bumps_disaster_pass_key(): void {
			// 源码契约：CommentGate 默认 recorder 含 disaster_pass 语境的计数特例（meta 标注
			// → record 独立键）；无此行则仪表盘容灾放行读数恒 0（O3 卡 2 数据源断供）。
			$src = file_get_contents( __DIR__ . '/../includes/gates/CommentGate.php' );
			self::assertIsString( $src, 'CommentGate.php 必须可读' );
			$this->assertStringContainsString( "StatsCache::record( 'comment', \$driver_id, 'disaster_pass' )", $src, '默认 recorder 须 bump disaster_pass 独立键（O3 卡 2 计数源）' );
			$this->assertStringContainsString( "! empty( \$meta['disaster_pass'] )", $src, '计数语境判定 = meta.disaster_pass' );
		}
	}
}
