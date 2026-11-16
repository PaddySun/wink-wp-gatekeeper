<?php
/**
 * M6·批F-b P1-4 事件写放大封顶单元测试（施工图 §5 D-1——审计 REPORT §4 P1-4）。
 *
 * 覆盖：
 *   - EventSampling 采样硬顶：stage∈{stub,rate} 事件按 ip_hash 小时桶封顶——
 *     键 wk_es_<substr(md5(ip_hash|YmdH),0,12)> 计数 transient（TTL 2h）；
 *     第 10 行放行 / 第 11 行拒绝（封顶）；跨 IP 互不影响；跨小时自然翻转；
 *     非采样 stage（ip/captcha/review/core/precheck/url_audit…）恒放行（真实闸链路径
 *     不受采样限制——有票拒、云端拒等 stage 不受采样限制）；
 *     计数桶损坏防御归 0。
 *   - EventsTable 写路径容量检查纯决策：超容量阈值拒写（rows > threshold）、
 *     threshold ≤ 0 = 闸关、静态缓存 60s 失效判定。
 *   - 源码契约钉（WP 边界静态自查清单——单测不可达路径的形态钉）：
 *     CommentGate / AccountFormGate 两默认记录器闭包在 EventsTable::insert 与
 *     StatsCache::record 之前经 EventSampling::allow 收口（封顶→丢事件行且跳过
 *     stats option UPDATE——写放大双源同封）；EventsTable::insert 在写循环之前做
 *     容量检查；uninstall.php 补 wk_es_/wk_ec_log_ transient 前缀。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Stats\EventSampling;
use WinkGatekeeper\Stats\EventsTable;

final class M6F2EventSamplingTest extends TestCase {

	/**
	 * 内存桶假件（生产 = get_transient/set_transient WP 边界，Bootstrap/类内默认装配）。
	 *
	 * @var array<string, mixed>
	 */
	private array $store = array();

	/** @var int 注入时钟（生产 = time()）。 */
	private int $now = 1731168000;

	protected function setUp(): void {
		$this->store = array();
		$this->now   = 1731168000;
		$store       = &$this->store;
		$now         = &$this->now;
		EventSampling::use_store(
			static function ( string $key ) use ( &$store ) {
				return $store[ $key ] ?? false;
			},
			static function ( string $key, $value, int $ttl ) use ( &$store ) {
				$store[ $key ]    = $value;
				$store[ '_t_' . $key ] = $ttl;
			},
			static function () use ( &$now ) {
				return $now;
			}
		);
	}

	protected function tearDown(): void {
		EventSampling::use_store( null, null, null ); // 还原 WP 边界默认（测试隔离）。
		parent::tearDown();
	}

	/** @return array<int, mixed> [transient 值, TTL]。 */
	private function bucket( string $key ): array {
		return array( $this->store[ $key ] ?? null, $this->store[ '_t_' . $key ] ?? null );
	}

	/* ---------- 纯决策：stage 采样域 ---------- */

	public function test_sampled_stage_covers_stub_and_rate_only(): void {
		$this->assertTrue( EventSampling::sampled_stage( 'stub' ), 'stub 事件在采样封顶域' );
		$this->assertTrue( EventSampling::sampled_stage( 'rate' ), 'rate 事件在采样封顶域' );
		// 真实闸链路径不受影响（有票拒、云端拒等 stage 不受采样限制）。
		$this->assertFalse( EventSampling::sampled_stage( 'ip' ), 'ip 阶段不在采样域' );
		$this->assertFalse( EventSampling::sampled_stage( 'captcha' ), 'captcha 阶段不在采样域' );
		$this->assertFalse( EventSampling::sampled_stage( 'review' ), 'review 阶段（云端拒/待审收口）不在采样域' );
		$this->assertFalse( EventSampling::sampled_stage( 'core' ), 'core 阶段不在采样域' );
		$this->assertFalse( EventSampling::sampled_stage( 'precheck' ), 'precheck 阶段不在采样域' );
		$this->assertFalse( EventSampling::sampled_stage( 'url_audit' ), 'url_audit 阶段不在采样域' );
		$this->assertFalse( EventSampling::sampled_stage( '' ), '未知/空 stage 不在采样域' );
	}

	/* ---------- 纯决策：桶键派生（施工图 §5 D-1 字面键形） ---------- */

	public function test_bucket_key_is_wk_es_prefix_plus_md5_12(): void {
		$ip_hash = 'abc123def4567890';
		$ymdh    = '2026111614';
		$expect  = 'wk_es_' . substr( md5( $ip_hash . '|' . $ymdh ), 0, 12 );
		$this->assertSame( $expect, EventSampling::bucket_key( $ip_hash, $ymdh ), '桶键=wk_es_<substr(md5(ip_hash|YmdH),0,12)>' );
		$this->assertSame( 18, strlen( EventSampling::bucket_key( $ip_hash, $ymdh ) ), '键长=6 前缀+12 hex' );
		$this->assertNotSame(
			EventSampling::bucket_key( $ip_hash, '2026111614' ),
			EventSampling::bucket_key( $ip_hash, '2026111615' ),
			'跨小时键翻转'
		);
		$this->assertNotSame(
			EventSampling::bucket_key( $ip_hash, '2026111614' ),
			EventSampling::bucket_key( 'zzz8901234567890', '2026111614' ),
			'跨 IP 键翻转'
		);
	}

	/* ---------- 采样边界：第 10 行写 / 第 11 行丢 ---------- */

	public function test_allow_passes_first_ten_and_blocks_eleventh_per_ip_hash(): void {
		$ip_hash = str_repeat( 'a', 16 );
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->assertTrue( EventSampling::allow( 'stub', $ip_hash ), "第 {$i} 行放行（额度内）" );
		}
		$key = EventSampling::bucket_key( $ip_hash, gmdate( 'YmdH', $this->now ) );
		$this->assertSame( array( 10, EventSampling::TTL_SECONDS ), $this->bucket( $key ), '桶计数=10，TTL=2h' );
		$this->assertFalse( EventSampling::allow( 'stub', $ip_hash ), '第 11 行拒绝（封顶丢事件行）' );
		$this->assertFalse( EventSampling::allow( 'rate', $ip_hash ), 'rate 段同桶同封（stage∈{stub,rate} 共享 ip_hash 小时桶）' );
		$this->assertSame( 10, $this->store[ $key ], '封顶后计数不再增长（不再写 transient）' );
	}

	public function test_cap_is_per_ip_hash_independent(): void {
		$ip_a = str_repeat( 'a', 16 );
		$ip_b = str_repeat( 'b', 16 );
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertTrue( EventSampling::allow( 'stub', $ip_a ) );
		}
		$this->assertFalse( EventSampling::allow( 'stub', $ip_a ), 'IP A 封顶' );
		$this->assertTrue( EventSampling::allow( 'rate', $ip_b ), 'IP B 不受 IP A 桶影响' );
	}

	public function test_hour_rollover_reopens_bucket(): void {
		$ip_hash = str_repeat( 'c', 16 );
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertTrue( EventSampling::allow( 'stub', $ip_hash ) );
		}
		$this->assertFalse( EventSampling::allow( 'stub', $ip_hash ), '本小时封顶' );
		$this->now += 3600; // 跨小时。
		$this->assertTrue( EventSampling::allow( 'stub', $ip_hash ), '新小时新桶——重新放行（旧桶 TTL 2h 自然回收）' );
	}

	public function test_corrupted_bucket_value_defends_to_zero(): void {
		$ip_hash = str_repeat( 'd', 16 );
		$key     = EventSampling::bucket_key( $ip_hash, gmdate( 'YmdH', $this->now ) );
		$this->store[ $key ] = 'garbage'; // 桶损坏（非数值）。
		$this->assertTrue( EventSampling::allow( 'stub', $ip_hash ), '损坏桶防御归 0——放行并重写' );
		$this->assertSame( 1, $this->store[ $key ], '损坏桶重写为 1' );
	}

	/**
	 * 【B 机实测定谳 2026-11-16】Redis 对象缓存跨进程把 int 标量以 string 形态回读
	 * （同进程 int(7)、跨进程 string(1) "7"——write/read 两进程探针）。is_int 守卫会把
	 * 数值串当损坏归 0 → 桶恒 1 永不封顶（首轮压测 16/16 全落行的根因）。计数守卫须
	 * 接受 is_numeric（int/string 数值串/float 全覆盖；布尔/数组/垃圾仍归 0）。
	 */
	public function test_numeric_string_bucket_value_round_trips_and_caps(): void {
		$ip_hash = str_repeat( 'f', 16 );
		$key     = EventSampling::bucket_key( $ip_hash, gmdate( 'YmdH', $this->now ) );
		$this->store[ $key ] = '9'; // string 形态（Redis 跨进程回读形态）。
		$this->assertTrue( EventSampling::allow( 'stub', $ip_hash ), 'string "9"=第 10 行放行' );
		$this->assertSame( 10, $this->store[ $key ], '计数续接（写回 int 形态）' );
		$this->assertFalse( EventSampling::allow( 'stub', $ip_hash ), '第 11 行拒绝（数值串计数生效）' );
	}

	public function test_negative_sampled_stage_check_is_cheapest_short_circuit(): void {
		// 非采样 stage：零 store 读——恒放行（真实闸链路径零额外 I/O）。
		$this->assertTrue( EventSampling::allow( 'review', str_repeat( 'e', 16 ) ) );
		$this->assertTrue( EventSampling::allow( 'captcha', str_repeat( 'e', 16 ) ) );
		$this->assertSame( array(), $this->store, '非采样 stage 不产生任何桶键写入' );
	}

	/* ---------- EventsTable 容量检查纯决策 ---------- */

	public function test_capacity_exceeded_is_strictly_over_threshold(): void {
		// 「超容量阈值拒写」：rows > threshold 才拒（恰等于阈值放行——与每日维护
		// trim_overflow 的 > 语义同构，避免「恰满即永久冻写」死锁）。
		$this->assertFalse( EventsTable::capacity_exceeded( 9999, 10000 ), '9999 行未超 10000——放行' );
		$this->assertFalse( EventsTable::capacity_exceeded( 10000, 10000 ), '恰等于阈值放行（每日维护可回到阈值内）' );
		$this->assertTrue( EventsTable::capacity_exceeded( 10001, 10000 ), '超阈值拒写' );
		$this->assertFalse( EventsTable::capacity_exceeded( 5, 0 ), '阈值 0=闸关（filter 显式归零豁免）' );
		$this->assertFalse( EventsTable::capacity_exceeded( 5, -1 ), '负阈值=闸关' );
	}

	public function test_capacity_threshold_clamps_filter_value(): void {
		$this->assertSame( 10000, EventsTable::capacity_threshold_clamped( 10000 ) );
		$this->assertSame( 0, EventsTable::capacity_threshold_clamped( 0 ), 'filter 归零=闸关' );
		$this->assertSame( 0, EventsTable::capacity_threshold_clamped( -5 ), '负值钳 0（闸关——不虚构负容量）' );
		$this->assertSame( 3, EventsTable::capacity_threshold_clamped( 3 ), '正值透传' );
	}

	public function test_capacity_cache_staleness_window_is_60s(): void {
		$this->assertTrue( EventsTable::capacity_cache_stale( null, 1731168000, 60 ), '无缓存=失效（须 COUNT）' );
		$this->assertFalse( EventsTable::capacity_cache_stale( array( 1731167990, 5 ), 1731168000, 60 ), '50s 内复用' );
		$this->assertTrue( EventsTable::capacity_cache_stale( array( 1731167940, 5 ), 1731168000, 60 ), '恰 60s=失效重查' );
		$this->assertTrue( EventsTable::capacity_cache_stale( array( 1731167800, 5 ), 1731168000, 60 ), '超 60s=失效重查' );
	}

	/* ---------- 源码契约钉（WP 边界静态自查——默认记录器闭包单测不可达） ---------- */

	private static function source( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}

	public function test_comment_gate_default_recorder_gates_sampling_before_insert_and_stats(): void {
		$src = self::source( 'includes/gates/CommentGate.php' );
		// 默认记录器闭包内：EventSampling::allow 先于 EventsTable::insert 与 StatsCache::record——
		// 封顶 → 丢事件行 **且** 跳过 stats option UPDATE（写放大双源同封）。
		$closure_start = (int) strpos( $src, '$this->recorder = $recorder' );
		$closure_end   = (int) strpos( (string) substr( $src, $closure_start ), '};' );
		$closure       = (string) substr( $src, $closure_start, $closure_end );
		$this->assertNotFalse( $closure_start, 'CommentGate 默认记录器闭包在场' );
		$this->assertStringContainsString( 'EventSampling::allow', $closure, '默认记录器经 EventSampling::allow 收口' );
		$pos_allow  = (int) strpos( $closure, 'EventSampling::allow' );
		$pos_insert = (int) strpos( $closure, 'EventsTable::insert' );
		$pos_stats  = (int) strpos( $closure, 'StatsCache::record' );
		$this->assertTrue( $pos_allow < $pos_insert, '采样闸先于事件行写入' );
		$this->assertTrue( $pos_allow < $pos_stats, '采样闸先于 stats UPDATE（封顶跳过统计计数）' );
	}

	public function test_account_form_gate_default_recorder_gates_sampling_before_insert_and_stats(): void {
		$src = self::source( 'includes/gates/AccountFormGate.php' );
		$closure_start = (int) strpos( $src, '$this->recorder     = $recorder' );
		$this->assertNotFalse( $closure_start, 'AccountFormGate 默认记录器闭包在场' );
		$closure_end   = (int) strpos( (string) substr( $src, $closure_start ), '};' );
		$closure       = (string) substr( $src, $closure_start, $closure_end );
		$this->assertStringContainsString( 'EventSampling::allow', $closure, '账户三闸默认记录器经 EventSampling::allow 收口（login stub 拒同面封顶）' );
		$pos_allow  = (int) strpos( $closure, 'EventSampling::allow' );
		$pos_insert = (int) strpos( $closure, 'EventsTable::insert' );
		$pos_stats  = (int) strpos( $closure, 'StatsCache::record' );
		$this->assertTrue( $pos_allow < $pos_insert, '采样闸先于事件行写入' );
		$this->assertTrue( $pos_allow < $pos_stats, '采样闸先于 stats UPDATE' );
	}

	public function test_events_table_insert_checks_capacity_before_write_loop(): void {
		$src = self::source( 'includes/stats/EventsTable.php' );
		$insert_start = (int) strpos( $src, 'public static function insert(' );
		$this->assertNotFalse( $insert_start, 'EventsTable::insert 在场' );
		$body = (string) substr( $src, $insert_start, (int) strpos( $src, 'recent_comment_rejects' ) - $insert_start );
		$this->assertStringContainsString( 'capacity_full', $body, '写路径容量检查恢复（轻量缓存版）' );
		$pos_cap    = (int) strpos( $body, 'capacity_full' );
		$pos_loop   = (int) strpos( $body, 'for ( $attempt' );
		$this->assertTrue( $pos_cap < $pos_loop, '容量检查先于写循环（拒写=零 MAX/INSERT 查询）' );
		$this->assertStringContainsString( "apply_filters( 'wk_events_capacity'", $src, '阈值经 wk_events_capacity filter 可调（默认 MAX_ROWS=10000）' );
	}

	public function test_uninstall_covers_new_transient_prefixes(): void {
		$src = self::source( 'uninstall.php' );
		$this->assertStringContainsString( "'wk_es_'", $src, 'uninstall 清单补 wk_es_（事件采样桶，TTL 2h）' );
		$this->assertStringContainsString( "'wk_ec_log_'", $src, 'uninstall 清单补 wk_ec_log_（容量闸小时日志去重键）' );
	}
}
