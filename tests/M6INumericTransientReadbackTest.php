<?php
/**
 * M6·批 I · I-2 数值 transient 计数器「string 回读」同型风险修复钉（2026-11-17）。
 *
 * 背景坑（F-b 真机实锤，IMPL-M6-F2 报告）：Redis 对象缓存跨进程把 int 标量以 string
 * 回读——int(7) 同进程 / string "7" 跨进程。is_int 裸判定把数值串当损坏，封顶/计数语义
 * 失效。EventSampling 已修（is_numeric 守卫）；本批排查全部「读回后做数值判定/比较」
 * 的 transient 计数器并修复其余同型雷。
 *
 * 本文件钉每处修复点的「string 回读→行为不变」回归（写 string 值→行为与 int 值一致）：
 *   - LoginCounters::bump（wk_lf_u_/wk_lf_ip_ 计数桶 {n,exp}）——is_int 裸判定曾把数值串
 *     桶当损坏走「饱和重写」分支：阈 3 时第 2 次失败即饱和（应第 3 次）。
 *   - LoginCounters::bucket_saturated（读路径 fail-safe）——数值串桶曾恒判饱和。
 *   - LoginCounters::render_flag / site_alarm（wk_lf_flag_/wk_lf_alarm {exp}）——过期判定
 *     曾因数值串恒「已点亮」。
 *   - AbuseIpdbService::bump_spike（wk_rp_spike {start,count,alarmed}）——数值串桶曾每请求
 *     开新窗、计数钉死 1，usage_spike 告警永不触发（F-b 同型：cap 失效）。
 *   - 垃圾串（真损坏）仍走 fail-safe——修复不得放宽损坏语义。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\LoginCounters;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Ip\AbuseIpdbService;

final class M6INumericTransientReadbackTest extends TestCase {

	/* ================= LoginCounters（wk_lf_u_/wk_lf_ip_ 计数桶） ================= */

	/**
	 * 内存 transient 后端（LoginCountersTest 同款形态——同盐同键派生）。
	 *
	 * @param int $clock 时钟（引用）。
	 * @return array{counters: LoginCounters, store: array<string, mixed>}
	 */
	private function makeCounters( int &$clock ): array {
		$store = array();

		$counters = new LoginCounters(
			array( 'user_threshold' => 3, 'window_minutes' => 15, 'ip_threshold' => 3 ),
			static function ( string $key ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$store ): void {
				unset( $ttl );
				$store[ $key ] = $value;
			},
			static function ( string $key ) use ( &$store ): void {
				unset( $store[ $key ] );
			},
			static function ( string $data ): string {
				return substr( hash_hmac( 'sha256', $data, 'i-numeric-readback-test' ), 0, 16 );
			},
			static function () use ( &$clock ): int {
				return $clock;
			}
		);

		return array( 'counters' => $counters, 'store' => &$store );
	}

	private function ipKey( string $ip ): string {
		return LoginCounters::KEY_IP . substr( hash_hmac( 'sha256', $ip, 'i-numeric-readback-test' ), 0, 16 );
	}

	private function userKey( string $username ): string {
		return LoginCounters::KEY_USER . substr( hash_hmac( 'sha256', 'u|' . $username, 'i-numeric-readback-test' ), 0, 16 );
	}

	public function test_i2_login_bump_continues_counting_on_string_readback(): void {
		$clock = 1000000;
		$env   = $this->makeCounters( $clock );

		// Redis 跨进程形态：桶 {n,exp} 全数值串。
		$env['store'][ $this->ipKey( '203.0.113.7' ) ] = array(
			'n'   => '1',                       // int 1 回读成 string。
			'exp' => (string) ( $clock + 900 ), // int 时间戳回读成 string。
		);

		$env['counters']->record_failure( '203.0.113.7' );

		$this->assertFalse( $env['counters']->is_saturated( '203.0.113.7', '' ), '数值串桶续数：2 次 < 阈 3 未饱和（雷：曾走损坏分支直接饱和重写）' );
		$bucket = $env['store'][ $this->ipKey( '203.0.113.7' ) ];
		$this->assertSame( 2, $bucket['n'], '计数续到 2 且写回 int（规范化）' );
		$this->assertSame( $clock + 900, $bucket['exp'], '固定窗口 exp 保留且写回 int（规范化）' );

		$env['counters']->record_failure( '203.0.113.7' );
		$this->assertTrue( $env['counters']->is_saturated( '203.0.113.7', '' ), '第 3 次达阈——计数语义与 int 桶一致' );
	}

	public function test_i2_login_bump_rotates_window_on_expired_string_bucket(): void {
		$clock = 1000000;
		$env   = $this->makeCounters( $clock );

		$env['store'][ $this->ipKey( '203.0.113.8' ) ] = array(
			'n'   => '5',                    // 上窗计数（数值串）。
			'exp' => (string) ( $clock - 10 ), // 已过期（数值串）。
		);

		$env['counters']->record_failure( '203.0.113.8' );
		$this->assertFalse( $env['counters']->is_saturated( '203.0.113.8', '' ), '窗过重开新桶：1 次未饱和' );
		$bucket = $env['store'][ $this->ipKey( '203.0.113.8' ) ];
		$this->assertSame( 1, $bucket['n'], '过期数值串桶=新窗（n 重置 1，非损坏分支饱和值）' );
		$this->assertSame( $clock + 900, $bucket['exp'], '新窗 exp' );
	}

	public function test_i2_login_bump_garbage_still_failsafe_saturates(): void {
		$clock = 1000000;
		$env   = $this->makeCounters( $clock );

		$env['store'][ $this->ipKey( '203.0.113.9' ) ] = array( 'n' => 'garbage', 'exp' => 'junk' );

		$env['counters']->record_failure( '203.0.113.9' );
		$this->assertTrue( $env['counters']->is_saturated( '203.0.113.9', '' ), '真损坏（非数值串）仍走 fail-safe 饱和重写' );
		$this->assertSame( 3, $env['store'][ $this->ipKey( '203.0.113.9' ) ]['n'], '损坏分支重写饱和值（阈 3）' );
	}

	public function test_i2_login_saturation_source_not_triggered_by_string_bucket(): void {
		$clock = 1000000;
		$env   = $this->makeCounters( $clock );

		$env['store'][ $this->ipKey( '203.0.113.10' ) ]  = array( 'n' => '1', 'exp' => (string) ( $clock + 900 ) );
		$env['store'][ $this->userKey( 'admin' ) ]       = array( 'n' => '2', 'exp' => (string) ( $clock + 900 ) );

		$this->assertNull(
			$env['counters']->saturation_source( '203.0.113.10', 'admin' ),
			'读路径 fail-safe：阈下数值串桶不误判饱和（雷：is_int 曾恒判饱和→验证期强制出码）'
		);
	}

	public function test_i2_login_render_flag_expires_on_string_exp(): void {
		$clock = 1000000;
		$env   = $this->makeCounters( $clock );

		$flag_key = LoginCounters::KEY_FLAG . substr( hash_hmac( 'sha256', '203.0.113.11', 'i-numeric-readback-test' ), 0, 16 );
		$env['store'][ $flag_key ] = array( 'exp' => (string) ( $clock - 10 ) );

		$this->assertFalse( $env['counters']->render_flag( '203.0.113.11' ), '过期数值串标志=熄灭（雷：is_int 曾恒判点亮）' );

		$env['store'][ $flag_key ] = array( 'exp' => (string) ( $clock + 100 ) );
		$this->assertTrue( $env['counters']->render_flag( '203.0.113.11' ), '未过期数值串标志=点亮（行为与 int 一致）' );
	}

	public function test_i2_login_site_alarm_expires_on_string_exp(): void {
		$clock = 1000000;
		$env   = $this->makeCounters( $clock );

		$env['store'][ LoginCounters::KEY_ALARM ] = array( 'exp' => (string) ( $clock - 10 ) );
		$this->assertFalse( $env['counters']->site_alarm(), '过期数值串警报=熄灭（雷：is_int 曾恒判点亮→全站登录页误出码）' );

		$env['store'][ LoginCounters::KEY_ALARM ] = array( 'exp' => (string) ( $clock + 100 ) );
		$this->assertTrue( $env['counters']->site_alarm(), '未过期数值串警报=点亮（行为与 int 一致）' );
	}

	/* ================= AbuseIpdbService（wk_rp_spike 突增告警桶） ================= */

	private const IP  = '203.0.113.77';
	private const NOW = 1731417600; // 2026-11-12 16:00 UTC（固定时钟）。

	/**
	 * AbuseIpdbService 测试台（AbuseIpdbReportTest::quick 同款注入形态——只留 report 路径所需面）。
	 *
	 * @param mixed $response fake transport 返回。
	 * @return array<string, mixed>
	 */
	private function makeService( $response ): array {
		$cache = array(); $calls = array(); $events = array();

		$transport = function ( string $url, array $args ) use ( &$calls, $response ) {
			unset( $url, $args );
			$calls[] = true;
			return $response;
		};
		$remote = new RemoteClient(
			$transport,
			static function ( string $key ): void {
				unset( $key ); // 计量钩子（本面不断言）。
			},
			static function ( int $ms ): void {
				unset( $ms );
			}
		);

		$settings = array(
			'iprep' => array(
				'enabled'                => true,
				'driver'                 => 'abuseipdb',
				'negative_cache_minutes' => 60,
				'abuseipdb'              => array(
					'api_key'         => 'test-key-123',
					'suspect_score'   => 60,
					'malicious_score' => 85,
					'cache_minutes'   => 1440,
					'soft_quota'      => 3000,
					'daily_quota'     => 4750,
					'timeout'         => 3,
				),
				'report'                 => array(
					'enabled'          => true,
					'triggers'         => array( 'captcha_failed' => true ),
					'cooldown_minutes' => 60,
					'hourly_limit'     => 60,
					'daily_cap'        => 500,
					'category'         => 0,
				),
			),
			'stats'   => array( 'raw_capture' => true, 'raw_max_bytes' => 512 ),
			'network' => array( 'ip_source' => 'direct' ),
		);

		$service = new AbuseIpdbService(
			$settings,
			$remote,
			static function ( string $key ) use ( &$cache ) {
				return array_key_exists( $key, $cache ) ? $cache[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$cache ): void {
				unset( $ttl );
				$cache[ $key ] = $value;
			},
			static function ( string $key ) use ( &$cache ) {
				return array_key_exists( $key, $cache ) ? $cache[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$cache ): void {
				unset( $ttl );
				$cache[ $key ] = $value;
			},
			static function ( string $driver_id ): ?string {
				unset( $driver_id );
				return null;
			},
			static function ( string $driver_id, string $state ): void {
				unset( $driver_id, $state );
			},
			static function (): array {
				return array( 'date' => '2026-11-12', 'counters' => array( 'abuseipdb_report' => 0, 'abuseipdb' => 0 ) );
			},
			static function ( string $data ): string {
				return substr( hash_hmac( 'sha256', $data, 'i-spike-test' ), 0, 16 );
			},
			static function (): bool {
				return false; // 非 admin。
			},
			static function (): array {
				return array();
			},
			static function ( string $ip ): bool {
				unset( $ip );
				return false; // 非白名单。
			},
			static function (): int {
				return self::NOW;
			},
			static function ( int $ts ): void {
				unset( $ts );
			},
			function ( string $gate, string $stage, $verdict, array $meta ) use ( &$events ): ?string {
				unset( $gate, $stage, $meta );
				$events[] = $verdict->hits();
				return 'WK-TEST';
			},
			static function (): bool {
				return true; // 真实 IP 自检过。
			}
		);

		return array( 'service' => $service, 'cache' => &$cache, 'calls' => &$calls, 'events' => &$events );
	}

	private function okResponse(): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( array(
				'data' => array( 'ipAddress' => self::IP, 'abuseConfidenceScore' => 42 ),
			) ),
			'headers'  => array(),
		);
	}

	public function test_i2_spike_alarms_on_string_readback_bucket(): void {
		$env = $this->makeService( $this->okResponse() );

		// Redis 跨进程形态：窗内桶计数已到 20（阈上沿），数值串回读。
		$env['cache']['wk_rp_spike'] = array(
			'start'   => (string) self::NOW,
			'count'   => '20',
			'alarmed' => false,
		);

		$env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) );

		$spikes = $this->spikeEvents( $env['events'] );
		$this->assertCount( 1, $spikes, '数值串桶续数：第 21 触发跨阈出 usage_spike（雷：曾每请求开新窗计数钉死 1，告警永不触发）' );
		$this->assertStringContainsString( 'usage_spike', (string) json_encode( $spikes[0] ), 'usage_spike 规则键' );

		$bucket = $env['cache']['wk_rp_spike'];
		$this->assertSame( 21, $bucket['count'], '计数 21 且写回 int（规范化）' );
		$this->assertSame( self::NOW, $bucket['start'], '窗口起点保留且写回 int（规范化）' );
		$this->assertTrue( (bool) $bucket['alarmed'], '同窗闩锁点亮' );
	}

	public function test_i2_spike_below_threshold_string_bucket_no_alarm(): void {
		$env = $this->makeService( $this->okResponse() );

		$env['cache']['wk_rp_spike'] = array(
			'start'   => (string) self::NOW,
			'count'   => '3',
			'alarmed' => false,
		);

		$env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) );

		$this->assertCount( 0, $this->spikeEvents( $env['events'] ), '阈下数值串桶续数不出告警（行为与 int 桶一致）' );
		$this->assertSame( 4, $env['cache']['wk_rp_spike']['count'], '计数 3→4 续数' );
	}

	/**
	 * 过滤 usage_spike 事件（AbuseIpdbReportTest::spike_events 同款——发送面 report_sent
	 * 事件不计入告警断言）。
	 *
	 * @param array<int, array<int, string>> $events 捕获事件 hits 集。
	 * @return array<int, array<int, string>>
	 */
	private function spikeEvents( array $events ): array {
		return array_values( array_filter( $events, static fn( array $hits ): bool => false !== strpos( (string) json_encode( $hits ), 'usage_spike' ) ) );
	}
}
