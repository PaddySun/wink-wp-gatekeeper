<?php
/**
 * StatsCache 计数树单元测试（04 §4 统计口径；纯逻辑部分 = 计数树合并 incr）+ Usage 用量计数（04 §6）。
 *
 * 规格：
 *   - wink_gatekeeper_stats option 结构：today/by_gate/by_driver/total/last_reset；
 *   - 跨日自动翻转（today != 当前日 → 今日计数并入 total、清零）；
 *   - pass 默认不记（闸层只对落库事件调 record；本层不做 pass 过滤——口径归写入矩阵）；
 *   - Usage（wink_gatekeeper_usage）：date 键 + 每驱动计数，跨日翻转清零。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Stats\StatsCache;
use WinkGatekeeper\Stats\Usage;

final class StatsCacheTest extends TestCase {

	/* ---------- StatsCache：seed / incr ---------- */

	public function test_seed_shape_matches_design(): void {
		$seed = StatsCache::seed();
		$this->assertSame( '', $seed['today'] );
		$this->assertSame( array(), $seed['by_gate'] );
		$this->assertSame( array(), $seed['by_driver'] );
		$this->assertSame( array( 'by_gate' => array(), 'by_driver' => array(), 'reject' => 0 ), $seed['total'] );
		$this->assertSame( 0, $seed['last_reset'] );
	}

	public function test_incr_counts_by_gate_and_driver(): void {
		$cache = StatsCache::seed();
		$cache = StatsCache::incr( $cache, '20261109', 'comment', 'tencent', 'reject' );
		$cache = StatsCache::incr( $cache, '20261109', 'comment', 'tencent', 'reject' );
		$cache = StatsCache::incr( $cache, '20261109', 'comment', 'local_rules', 'reject' );

		$this->assertSame( '20261109', $cache['today'] );
		$this->assertSame( 3, $cache['by_gate']['comment']['reject'] ); // 3 次递增都在 comment 闸。
		$this->assertSame( 2, $cache['by_driver']['tencent']['reject'] );
		$this->assertSame( 1, $cache['by_driver']['local_rules']['reject'] );
		$this->assertSame( 3, $cache['total']['by_gate']['comment']['reject'] );
		$this->assertSame( 3, $cache['total']['reject'] );
	}

	public function test_incr_accumulates_total_alongside_today(): void {
		$cache = StatsCache::seed();
		$cache = StatsCache::incr( $cache, '20261109', 'comment', 'tencent', 'review' );

		$this->assertSame( 1, $cache['by_gate']['comment']['review'] );
		$this->assertSame( 1, $cache['total']['by_gate']['comment']['review'] );
	}

	/* ---------- 跨日翻转 ---------- */

	public function test_day_rollover_merges_today_into_total_and_resets(): void {
		$cache = StatsCache::seed();
		$cache = StatsCache::incr( $cache, '20261108', 'comment', 'tencent', 'reject' );

		// 次日第一条 incr：翻转（昨日并入 total 已在昨日 incr 时累计——今日树清零重建）。
		$cache = StatsCache::incr( $cache, '20261109', 'comment', 'tencent', 'reject' );

		$this->assertSame( '20261109', $cache['today'] );
		$this->assertSame( 1, $cache['by_gate']['comment']['reject'] );            // 今日重新计数。
		$this->assertSame( 2, $cache['total']['by_gate']['comment']['reject'] );   // 累计含昨日。
		$this->assertSame( 2, $cache['total']['reject'] );
	}

	public function test_rollover_keeps_multiple_verdicts_separate(): void {
		$cache = StatsCache::seed();
		$cache = StatsCache::incr( $cache, '20261108', 'comment', 'tencent', 'reject' );
		$cache = StatsCache::incr( $cache, '20261108', 'comment', 'local_rules', 'reject' );
		$cache = StatsCache::incr( $cache, '20261109', 'login', 'tencent', 'reject' );

		$this->assertSame( array( 'reject' => 1 ), $cache['by_gate']['login'] );
		$this->assertSame( 3, $cache['total']['reject'] );
		$this->assertSame( 2, $cache['total']['by_gate']['comment']['reject'] );
		$this->assertSame( 1, $cache['total']['by_gate']['login']['reject'] );
		$this->assertSame( 2, $cache['total']['by_driver']['tencent']['reject'] );
	}

	public function test_reset_seed(): void {
		$cache = StatsCache::seed();
		$cache = StatsCache::incr( $cache, '20261109', 'comment', 'tencent', 'reject' );

		$reset = StatsCache::reset( $cache, 1731100000 );
		$this->assertSame( '20261109', $reset['today'] );
		$this->assertSame( array(), $reset['by_gate'] );
		$this->assertSame( array(), $reset['by_driver'] );
		$this->assertSame( 0, $reset['total']['reject'] );
		$this->assertSame( 1731100000, $reset['last_reset'] );
	}

	/* ---------- Usage：用量计数 ---------- */

	public function test_usage_seed_shape(): void {
		$seed = Usage::seed();
		$this->assertSame( '', $seed['date'] );
		$this->assertSame( array(), $seed['counters'] );
	}

	public function test_usage_bump_increments_counter(): void {
		$usage = Usage::seed();
		$usage = Usage::bump( $usage, '20261109', 'tencent' );
		$usage = Usage::bump( $usage, '20261109', 'tencent' );
		$usage = Usage::bump( $usage, '20261109', 'local_rules' );

		$this->assertSame( '20261109', $usage['date'] );
		$this->assertSame( 2, $usage['counters']['tencent'] );
		$this->assertSame( 1, $usage['counters']['local_rules'] );
	}

	public function test_usage_day_rollover_resets_counters(): void {
		$usage = Usage::seed();
		$usage = Usage::bump( $usage, '20261108', 'tencent' );
		$usage = Usage::bump( $usage, '20261109', 'tencent' );

		$this->assertSame( '20261109', $usage['date'] );
		$this->assertSame( 1, $usage['counters']['tencent'] ); // 跨日清零后重新计。
		$this->assertArrayNotHasKey( 'local_rules', $usage['counters'] );
	}

	public function test_usage_daily_total_sums_counters(): void {
		$usage = Usage::seed();
		$usage = Usage::bump( $usage, '20261109', 'tencent' );
		$usage = Usage::bump( $usage, '20261109', 'abuseipdb_report' );

		$this->assertSame( 2, Usage::daily_total( $usage ) );
	}

	public function test_usage_quota_reached_check(): void {
		$usage = Usage::seed();
		$this->assertFalse( Usage::quota_reached( $usage, 'tencent', 100 ) );

		for ( $i = 0; $i < 3; $i++ ) {
			$usage = Usage::bump( $usage, '20261109', 'tencent' );
		}
		$this->assertTrue( Usage::quota_reached( $usage, 'tencent', 3 ) );
		$this->assertFalse( Usage::quota_reached( $usage, 'deepseek', 3 ) );
	}

	/* ---------- StatsCache option 名与 Usage option 名（供装配与卸载对账） ---------- */

	public function test_option_names_match_design(): void {
		$this->assertSame( 'wink_gatekeeper_stats', StatsCache::OPTION_NAME );
		$this->assertSame( 'wink_gatekeeper_usage', Usage::OPTION_NAME );
	}
}
