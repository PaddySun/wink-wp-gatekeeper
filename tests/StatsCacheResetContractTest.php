<?php
/**
 * 统计重置契约（批 FIX-W67，2026-11-21）。
 *
 * 背景：用户手测（FEEDBACK-W67-1）抓获「清空全部统计」按钮 **出生即残缺**——
 * StatsCache::truncate() 调 self::table_name()，而该方法只存在于 EventsTable
 * （跨类 self:: 误用）→ PHP Error → admin-ajax 500 fatal → 前端 catch 误报
 * 「重置失败（网络错误）」；update_option 先行=计数已清零、事件表 TRUNCATE 从未
 * 执行（w67 取证 4/4 复现：HTTP 500 text/html fatal 页+事件 19 条残留）。
 * 修=truncate() 委托 EventsTable::table_name()；本契约两钉：
 *   ① truncate() 经假 wpdb 产出正确 TRUNCATE SQL（行为承载）；
 *   ② 跨类形态防回归：table_name 只在 EventsTable、StatsCache 零自定义
 *      （归位单一来源——复制一份方法即钉转红）。
 * 家族扫描实证：StatsCache 全部 self:: 调用中 table_name 为唯一缺失方法。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Stats\EventsTable;
use WinkGatekeeper\Stats\StatsCache;

final class StatsCacheResetContractTest extends TestCase {

	/**
	 * 最小 wpdb 假件：query() 记录 SQL（无执行语义）。
	 */
	private object $fake;

	protected function setUp(): void {
		parent::setUp();
		$this->fake = new class() {

			/** @var string 站点表前缀。 */
			public string $prefix = 'wp_';

			/** @var string[] query() 收到的 SQL 序列。 */
			public array $queries = array();

			/**
			 * 记录 SQL（无执行）。
			 *
			 * @param string $sql SQL。
			 * @return mixed 恒 null。
			 */
			public function query( $sql ) {
				$this->queries[] = (string) $sql;
				return null;
			}
		};
		$GLOBALS['wpdb'] = $this->fake;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/** truncate() 委托 EventsTable::table_name 产出正确 TRUNCATE SQL（w67 修复承载钉）。 */
	public function test_truncate_delegates_to_events_table_name(): void {
		StatsCache::truncate();
		$this->assertCount( 1, $this->fake->queries, 'truncate 恰一条 SQL' );
		$this->assertStringContainsString( 'TRUNCATE TABLE wp_wink_gatekeeper_events', $this->fake->queries[0], 'TRUNCATE 目标=事件表真名（prefix+后缀——w67 前此调用 fatal）' );
	}

	/** 跨类形态防回归：table_name 归位 EventsTable 单一来源，StatsCache 零复制。 */
	public function test_table_name_single_source(): void {
		$this->assertTrue( method_exists( EventsTable::class, 'table_name' ), 'EventsTable::table_name 在场（单一来源）' );
		$this->assertFalse( method_exists( StatsCache::class, 'table_name' ), 'StatsCache 不得自定义 table_name（复制=钉转红——委托勿复制）' );
	}
}
