<?php
/**
 * W6-C 批 · 事件表每日维护两件回归测试。
 *
 * 件1（C-7 拦截留档 30 天过期清理，R-AK §C C-7）：daily cron 追加留档清理段——
 * comment_approved='spam' 且 commentmeta wink_gatekeeper_archive 在场且超 N 天
 * （N=30 常量+filter 可调，0=关闭豁免；用户 2026-11-21 拍板 N=30>15 对齐 Akismet
 * 建议下限）→ wp_delete_comment 彻底删（不进回收站——回收站会二次堆积）+ 归档
 * meta 先行摘除（对齐 uninstall 面 meta 键逐键清理纪律）。
 *
 * 件2（C-5 daily 维护执行时限护栏，R-AK §C C-5）：批量清理循环加执行时间检查
 * （超过 PHP max_execution_time−5 秒即停本轮，下轮 cron 续清——Akismet 先例
 * akismet/class.akismet.php:947-984；时钟注入沿 EventSampling use_store 惯例）。
 *
 * 桩注记：WinkGatekeeper\Stats 命名空间桩（apply_filters/delete_comment_meta/
 * wp_delete_comment/time/ini_get）——M6ADriverMeteringTest 桩族同款机制
 * （function_exists 守卫，先载者生效；apply_filters 未登记 hook=透传默认值，
 * capacity_threshold 的 function_exists 守卫语义不破坏）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Stats {

	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 ); // 纯单测语境无 WP 常量——cutoff 纯函数消费位。
	}

	$GLOBALS['wk_w6c_calls']         = array( 'seq' => array(), 'filters' => array() );
	$GLOBALS['wk_w6c_filter_values'] = array(); // filter 名 → 返回值（未登记=透传默认）。
	$GLOBALS['wk_w6c_time']          = 0;      // 0 = 真实 time()。
	$GLOBALS['wk_w6c_ini']           = null;   // null = 真实 ini_get()。

	if ( ! function_exists( 'WinkGatekeeper\Stats\apply_filters' ) ) {
		/**
		 * apply_filters 命名空间桩（未登记 hook 透传默认——wk_events_capacity 既有
		 * 守卫语义零破坏；登记 hook 返回可编程值）。
		 *
		 * @param string $hook  filter 名。
		 * @param mixed  $value 默认值。
		 * @return mixed
		 */
		function apply_filters( string $hook, $value ) {
			$GLOBALS['wk_w6c_calls']['filters'][] = $hook;
			if ( array_key_exists( $hook, $GLOBALS['wk_w6c_filter_values'] ) ) {
				return $GLOBALS['wk_w6c_filter_values'][ $hook ];
			}
			return $value;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\delete_comment_meta' ) ) {
		/**
		 * delete_comment_meta 命名空间桩（记录调用序——meta 摘除先于评论删除钉面）。
		 *
		 * @param int    $comment_id 评论 id。
		 * @param string $meta_key   meta 键。
		 * @param mixed  $meta_value 值（WP 语义可省）。
		 * @return bool
		 */
		function delete_comment_meta( int $comment_id, string $meta_key, $meta_value = '' ): bool {
			unset( $meta_value );
			$GLOBALS['wk_w6c_calls']['seq'][] = array( 'meta', $comment_id, $meta_key );
			return true;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\wp_delete_comment' ) ) {
		/**
		 * wp_delete_comment 命名空间桩（记录调用与 force 标志——彻底删不进回收站钉面）。
		 *
		 * @param int  $comment_id   评论 id。
		 * @param bool $force_delete 是否强制（true=跳过回收站）。
		 * @return bool
		 */
		function wp_delete_comment( int $comment_id, bool $force_delete = false ): bool {
			$GLOBALS['wk_w6c_calls']['seq'][] = array( 'comment', $comment_id, $force_delete );
			return true;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\time' ) ) {
		/**
		 * time 命名空间桩（可编程固定时刻；0 = 真实 time() 透传——EventSampling
		 * 时钟注入惯例的单测承载面）。
		 *
		 * @return int
		 */
		function time(): int {
			return 0 !== $GLOBALS['wk_w6c_time'] ? $GLOBALS['wk_w6c_time'] : \time();
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\ini_get' ) ) {
		/**
		 * ini_get 命名空间桩（可编程 max_execution_time 读数；null = 真实 ini_get 透传）。
		 *
		 * @param string $option 配置名。
		 * @return string|false
		 */
		function ini_get( string $option ) {
			unset( $option );
			return null !== $GLOBALS['wk_w6c_ini'] ? $GLOBALS['wk_w6c_ini'] : \ini_get( 'max_execution_time' );
		}
	}
}

namespace WinkGatekeeper\Tests {

	use PHPUnit\Framework\TestCase;
	use WinkGatekeeper\Stats\EventsTable;

	/**
	 * 最小 wpdb 假件（get_col 批次队列 + prepare 记录；purge 循环行为承载面）。
	 */
	final class W6CFakeWpdb {

		/**
		 * @var string 站点表前缀。
		 */
		public string $prefix = 'wp_';

		/**
		 * @var array<int, array<int, string|int>> get_col 逐批返回队列。
		 */
		public array $batches;

		/**
		 * @var array<int, array{0: string, 1: array<int, mixed>}> prepare 调用记录。
		 */
		public array $prepared = array();

		/**
		 * @var int get_col 消费批游标。
		 */
		public int $batch_index = 0;

		/**
		 * @param array<int, array<int, string|int>> $batches 逐批返回队列。
		 */
		public function __construct( array $batches ) {
			$this->batches = $batches;
		}

		/**
		 * prepare 记录透传（假件无需真实转义）。
		 *
		 * @param string $sql  SQL 模板。
		 * @param mixed  ...$args 占位实参。
		 * @return string
		 */
		public function prepare( $sql, ...$args ) {
			$this->prepared[] = array( (string) $sql, $args );
			return $sql;
		}

		/**
		 * query 记录（daily 大 DELETE/滚动 DELETE 面——假件无执行语义）。
		 *
		 * @param string $sql 查询。
		 * @return int|false
		 */
		public function query( $sql ) {
			$this->prepared[] = array( (string) $sql, array() );
			return 1;
		}

		/**
		 * get_var（trim_overflow COUNT 面——0 行=零溢出删除，滚动段零 DELETE）。
		 *
		 * @param string $sql 查询。
		 * @return int
		 */
		public function get_var( $sql ) {
			unset( $sql );
			return 0;
		}

		/**
		 * get_col 按批出队（空批=循环收敛面）。
		 *
		 * @param string $sql 查询（记录用）。
		 * @return array<int, string|int>
		 */
		public function get_col( $sql ) {
			unset( $sql );
			$batch = $this->batches[ $this->batch_index ] ?? array();
			$this->batch_index++;
			return $batch;
		}
	}

	final class W6CEventsMaintenanceTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['wk_w6c_calls']         = array( 'seq' => array(), 'filters' => array() );
			$GLOBALS['wk_w6c_filter_values'] = array();
			$GLOBALS['wk_w6c_time']          = 0;
			$GLOBALS['wk_w6c_ini']           = null;
		}

		protected function tearDown(): void {
			unset( $GLOBALS['wpdb'] );
		}

		/**
		 * 提取具名方法体（花括号配平——EventsTableQueriesTest methodBody 同款）。
		 *
		 * @param string $src  源码全文。
		 * @param string $name 方法名。
		 * @return string
		 */
		private static function methodBody( string $src, string $name ): string {
			$start = strpos( $src, 'function ' . $name );
			self::assertNotFalse( $start, "方法 {$name} 必须存在" );
			$open  = strpos( $src, '{', $start );
			$depth = 0;
			$len   = strlen( $src );
			for ( $i = $open; $i < $len; $i++ ) {
				if ( '{' === $src[ $i ] ) {
					$depth++;
				} elseif ( '}' === $src[ $i ] ) {
					$depth--;
					if ( 0 === $depth ) {
						return substr( $src, $open, $i - $open + 1 );
					}
				}
			}
			self::fail( "方法 {$name} 花括号不配平" );
			return '';
		}

		private static function src( string $rel ): string {
			return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
		}

		/* ===============================================================
		 * 件1 · C-7 拦截留档过期清理
		 * =============================================================== */

		public function test_c7_constants_and_default_retention_30(): void {
			$this->assertSame( 'wink_gatekeeper_archive', EventsTable::ARCHIVE_META_KEY, '留档 meta 键=写入位/uninstall 清理清单同键（单点常量）' );
			$this->assertSame( 30, EventsTable::ARCHIVE_RETENTION_DAYS, '默认留存 N=30（用户 2026-11-21 拍板，>15 对齐 Akismet 建议下限）' );
			$this->assertSame( 30, EventsTable::archive_retention_days(), '无 filter 干预=默认 30（桩透传默认值）' );
		}

		public function test_c7_retention_filter_override_and_zero_disable(): void {
			$GLOBALS['wk_w6c_filter_values']['wk_archive_retention_days'] = 7;
			$this->assertSame( 7, EventsTable::archive_retention_days(), 'filter 可调留存窗' );
			$this->assertSame( 7, EventsTable::archive_retention_days_clamped( 7 ), '纯钳制件：正值透传' );

			$GLOBALS['wk_w6c_filter_values']['wk_archive_retention_days'] = 0;
			$this->assertSame( 0, EventsTable::archive_retention_days(), 'filter 归零=关闭豁免' );

			$GLOBALS['wk_w6c_filter_values']['wk_archive_retention_days'] = -5;
			$this->assertSame( 0, EventsTable::archive_retention_days(), '负值钳 0（同 capacity_threshold_clamped 惯例）' );
			$this->assertSame( 0, EventsTable::archive_retention_days_clamped( -5 ) );
		}

		public function test_c7_cutoff_math_is_pure(): void {
			$now = 1800000000;
			$this->assertSame(
				gmdate( 'Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS ),
				EventsTable::expired_archive_cutoff( 30, $now ),
				'分界=now − N×DAY_IN_SECONDS 的 GMT 串'
			);
			$this->assertSame( '1970-01-01 00:00:00', EventsTable::expired_archive_cutoff( 1, DAY_IN_SECONDS ), '恰一天边界' );
		}

		public function test_c7_ids_sql_shape(): void {
			$sql = EventsTable::expired_archive_ids_sql( 'wp_comments', 'wp_commentmeta' );

			$this->assertStringContainsString( 'SELECT c.comment_ID FROM wp_comments AS c INNER JOIN wp_commentmeta AS m ON m.comment_id = c.comment_ID', $sql, 'JOIN 形态（StatsCache::archived_spam_count 同源口径）' );
			$this->assertStringContainsString( "WHERE c.comment_approved = 'spam'", $sql, '只清 spam 分队' );
			$this->assertStringContainsString( "m.meta_key = 'wink_gatekeeper_archive'", $sql, '只清本插件留档（meta 键=单点常量）' );
			$this->assertStringContainsString( 'AND c.comment_date_gmt < %s LIMIT %d', $sql, '分界/批量走 prepare 占位（值不入 SQL 构建器）' );
		}

		public function test_c7_purge_deletes_batch_meta_first_force_true(): void {
			$GLOBALS['wpdb'] = new W6CFakeWpdb( array( array( '11', '22', '33' ) ) );

			$deleted = EventsTable::purge_expired_archives();

			$this->assertSame( 3, $deleted, '返回本轮删除条数' );
			$this->assertSame(
				array(
					array( 'meta', 11, 'wink_gatekeeper_archive' ),
					array( 'comment', 11, true ),
					array( 'meta', 22, 'wink_gatekeeper_archive' ),
					array( 'comment', 22, true ),
					array( 'meta', 33, 'wink_gatekeeper_archive' ),
					array( 'comment', 33, true ),
				),
				$GLOBALS['wk_w6c_calls']['seq'],
				'逐条：meta 先摘（对齐 uninstall 逐键纪律）→ wp_delete_comment 彻底删（force=true 不进回收站）'
			);
			$this->assertSame( 1, $GLOBALS['wpdb']->batch_index, '尾批不满 LIMIT=收敛停（无空跑一轮）' );
		}

		public function test_c7_purge_loops_until_exhausted(): void {
			$first  = range( 1, 100 );
			$second = range( 101, 110 );
			$GLOBALS['wpdb'] = new W6CFakeWpdb( array(
				array_map( 'strval', $first ),
				array_map( 'strval', $second ),
				array(),
			) );

			$deleted = EventsTable::purge_expired_archives();

			$this->assertSame( 110, $deleted, '满批续扫至收敛（存量积压一次清完——护栏面归件2谓词）' );
			$this->assertSame( 2, $GLOBALS['wpdb']->batch_index, '100/110 两批：尾批不满 LIMIT=扫描面已尽（免一次空跑查询）' );
		}

		public function test_c7_purge_zero_retention_is_noop(): void {
			$GLOBALS['wk_w6c_filter_values']['wk_archive_retention_days'] = 0;
			$GLOBALS['wpdb'] = new W6CFakeWpdb( array() );

			$this->assertSame( 0, EventsTable::purge_expired_archives(), '0=关闭豁免' );
			$this->assertSame( array(), $GLOBALS['wk_w6c_calls']['seq'], '豁免态零删除' );
			$this->assertSame( 0, $GLOBALS['wpdb']->batch_index, '豁免态零查询（短路口在时间窗计算前）' );
		}

		public function test_c7_daily_maintenance_appends_purge_segment(): void {
			$src = self::src( 'includes/stats/EventsTable.php' );

			$daily = self::methodBody( $src, 'daily_maintenance' );
			$this->assertStringContainsString( 'trim_overflow', $daily, '既有容量兜底保留（P0-4 钉面不破）' );
			$this->assertStringContainsString( 'purge_expired_archives', $daily, 'daily cron 追加留档清理段（C-7 主钉）' );

			$purge = self::methodBody( $src, 'purge_expired_archives' );
			$this->assertStringContainsString( 'delete_comment_meta(', $purge, 'meta 摘除在场（uninstall 面 meta 键对齐）' );
			$this->assertStringContainsString( 'wp_delete_comment( (int) $id, true )', $purge, '彻底删（true=跳过回收站——回收站会二次堆积）' );
		}

		public function test_c7_uninstall_and_writer_share_the_meta_key(): void {
			$this->assertStringContainsString( "'wink_gatekeeper_archive'", self::src( 'uninstall.php' ), 'uninstall 清理清单同键（对齐事实钉）' );
			$this->assertStringContainsString( 'wink_gatekeeper_archive', self::src( 'includes/Bootstrap.php' ), '留档写入位同键（对齐事实钉）' );
		}

		/* ===============================================================
		 * 件2 · C-5 daily 维护执行时限护栏
		 * =============================================================== */

		public function test_c5_budget_seconds_threshold_semantics(): void {
			$this->assertSame( 25, EventsTable::maintenance_time_budget_seconds( 30 ), 'max_execution_time − 5（Akismet 947-984 同式）' );
			$this->assertSame( 1, EventsTable::maintenance_time_budget_seconds( 6 ), '小 ini 下限 1（预算不归零——归零即护栏永不放行一轮）' );
			$this->assertSame( 1, EventsTable::maintenance_time_budget_seconds( 5 ), '≤5 的 ini 同样钳 1' );
			$this->assertSame( PHP_INT_MAX, EventsTable::maintenance_time_budget_seconds( 0 ), '0=无时限（CLI）→护栏不触发' );
			$this->assertSame( PHP_INT_MAX, EventsTable::maintenance_time_budget_seconds( -1 ), '负读数同无时限' );
		}

		public function test_c5_budget_exhausted_semantics(): void {
			$this->assertFalse( EventsTable::maintenance_time_budget_exhausted( 100, 124, 30 ), 'elapsed 24 < 25 未耗尽' );
			$this->assertTrue( EventsTable::maintenance_time_budget_exhausted( 100, 125, 30 ), 'elapsed 25 ≥ 25 = 耗尽（≥ 语义：预算用满即停本轮）' );
			$this->assertTrue( EventsTable::maintenance_time_budget_exhausted( 100, 200, 30 ), '超预算必停' );
			$this->assertFalse( EventsTable::maintenance_time_budget_exhausted( 0, 99999999, 0 ), '无时限（ini 0）永不耗尽' );
		}

		public function test_c5_clock_seam_drives_purge_cutoff(): void {
			$clock_hits = 0;
			EventsTable::use_clock( static function () use ( &$clock_hits ): int {
				$clock_hits++;
				return 1800000000;
			} );
			$GLOBALS['wpdb'] = new W6CFakeWpdb( array( array( '9' ) ) );

			EventsTable::purge_expired_archives();

			$this->assertSame( 1, $clock_hits, 'purge 内部时间读数走注入时钟（EventSampling use_store 惯例同款）' );
			$this->assertSame(
				array( gmdate( 'Y-m-d H:i:s', 1800000000 - 30 * DAY_IN_SECONDS ), EventsTable::ARCHIVE_PURGE_BATCH ),
				$GLOBALS['wpdb']->prepared[0][1],
				'cutoff（GMT 串）/批量以注入时刻计算（prepare 实参钉）'
			);

			EventsTable::use_clock( null ); // 还原默认（time() 透传——测试隔离桥）。
		}

		public function test_c5_daily_maintenance_stops_purge_when_budget_exhausted(): void {
			// 时钟队列：start 读数 0 → 首个 loop-top 护栏读数已超预算 → 零批次（下轮 cron 续清）。
			$times  = array( 0, 1000 );
			$cursor = 0;
			EventsTable::use_clock( static function () use ( &$times, &$cursor ): int {
				$t = $times[ $cursor ] ?? $times[ count( $times ) - 1 ];
				$cursor++;
				return $t;
			} );
			$GLOBALS['wk_w6c_ini'] = 30; // 预算 25s。
			$GLOBALS['wpdb'] = new W6CFakeWpdb( array( array( '1', '2', '3' ) ) );

			EventsTable::daily_maintenance();

			$this->assertSame( 0, $GLOBALS['wpdb']->batch_index, '预算已尽=本轮零扫描（90 天删+滚动已完成，留档段整段让位）' );
			$this->assertSame( array(), $GLOBALS['wk_w6c_calls']['seq'], '零删除' );

			EventsTable::use_clock( null );
		}

		public function test_c5_daily_maintenance_purges_within_budget(): void {
			// 时钟读序：start=0 → purge 内 cutoff=0 → 护栏读 10（未尽）→ 满批1 → 护栏读 20
			// （未尽）→ 满批2 → 护栏读 30（尽）=停（elapsed 与 start=0 同基线）。
			$times  = array( 0, 0, 10, 20, 30 );
			$cursor = 0;
			EventsTable::use_clock( static function () use ( &$times, &$cursor ): int {
				$t = $times[ $cursor ] ?? $times[ count( $times ) - 1 ];
				$cursor++;
				return $t;
			} );
			$GLOBALS['wk_w6c_ini'] = 30; // 预算 25s。
			$GLOBALS['wpdb'] = new W6CFakeWpdb( array(
				array_map( 'strval', range( 1, 100 ) ),
				array_map( 'strval', range( 101, 200 ) ),
				array(),
			) );

			EventsTable::daily_maintenance();

			$this->assertSame( 2, $GLOBALS['wpdb']->batch_index, '两满批后 loop-top 读数超预算=停（200 条本轮已删，余量下轮续清）' );
			$comment_deletes = array_filter(
				$GLOBALS['wk_w6c_calls']['seq'],
				static function ( array $entry ): bool {
					return 'comment' === $entry[0];
				}
			);
			$this->assertCount( 200, $comment_deletes, '200 条删除（seq 含 meta+comment 双记录）' );

			EventsTable::use_clock( null );
		}

		public function test_c5_daily_maintenance_source_pins(): void {
			$src = self::src( 'includes/stats/EventsTable.php' );

			$daily = self::methodBody( $src, 'daily_maintenance' );
			$this->assertStringContainsString( '$start', $daily, 'start 时刻捕获在场' );
			$this->assertStringContainsString( 'maintenance_time_budget_exhausted', $daily, '护栏谓词在场（C-5 契约钉）' );
			$this->assertStringContainsString( 'purge_expired_archives', $daily, '留档清理段消费护栏（C-7 段带护栏续清）' );

			$purge = self::methodBody( $src, 'purge_expired_archives' );
			$this->assertStringContainsString( 'null !== $stop && $stop()', $purge, 'loop-top 中止谓词消费位（件1 seam 接线兑现）' );

			$now = self::methodBody( $src, 'now' );
			$this->assertStringContainsString( 'call_user_func( self::$clock )', $now, '时钟注入消费（EventSampling 同惯例）' );
			$this->assertStringContainsString( 'time()', $now, '默认回落 time()' );
		}
	}
}
