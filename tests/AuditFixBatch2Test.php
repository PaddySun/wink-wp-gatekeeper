<?php
/**
 * AuditFixBatch2Test——独立审计 deferred 修复批 1（防滥用批）契约测试。
 *
 * 三发现（AUDIT-INDEP-2026-11 REPORT §10 优先序 3）：
 *   - P1-3（S3-F1）：transient 限流桶 check-then-inc 非原子 → AtomicCounter 原子自增件
 *     （wp_cache_incr + 首击定 TTL；无持久对象缓存/后端拒绝 = false 落回 legacy 桶路），
 *     TransientRateLimiter / LoginCounters / EventSampling 三消费方接入。
 *   - P1-4（S3-F2）：拦截留档无容量闸 → CommentGate archive_quota 判定位（Bootstrap 装
 *     配 per-IP 日桶 wk_cai_ + 全局日桶 wk_cag_，schema intercept.archive_daily_*_cap）。
 *   - P1-5（S3-F3）：AbuseIPDB 负缓存 60min 过短 + 边缘轮换代烧配额 → 负缓存默认 360min
 *     （特征值平移 60→360）+ /24 聚合缓存（仅负向 verdict；exact 优先，/24 命中带
 *     aggregated meta 证据标注；损坏 = miss 不从严）。
 *
 * 红先：本文件 22 例先红后绿；increase-only（零既有断言删除）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\CommentGate;
use WinkGatekeeper\Gates\LoginCounters;
use WinkGatekeeper\Gates\TransientRateLimiter;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Intercept\Stub;
use WinkGatekeeper\Ip\AbuseIpdbService;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Settings\SettingsSchema;
use WinkGatekeeper\Stats\EventSampling;
use WinkGatekeeper\Verdict;

final class AuditFixBatch2Test extends TestCase {

	private const IP       = '203.0.113.7';
	private const HMACsalt = 'i2-abuseipdb-test';

	/** @var callable */
	private static $stub_hash;

	public static function setUpBeforeClass(): void {
		self::$stub_hash = static function ( string $data ): string {
			return 'h_' . hash_hmac( 'sha256', $data, 'gate-test-key' );
		};
	}

	public function tearDown(): void {
		EventSampling::use_store_incr( null ); // 静态态复位（跨文件隔离——M6F2 同进程随其后跑）。
	}

	/* ===================================================================
	 * P1-3 · 原子自增（AtomicCounter + 三消费方）
	 * =================================================================== */

	public function test_atomic_counter_unavailable_backend_returns_false(): void {
		// 纯单测环境无 wp_cache_incr → 恒 false（调用方落回 legacy 桶路——7.4 主机语义不变）。
		$this->assertFalse( \WinkGatekeeper\Gates\AtomicCounter::incr( 'wk_cm_abc', 3600 ) );
	}

	public function test_rate_limiter_atomic_path_counts_without_store_roundtrip(): void {
		$get_calls = 0;
		$put_calls = 0;
		$seq       = 0;
		$limiter   = new TransientRateLimiter(
			3,
			3600,
			static function ( string $key ) use ( &$get_calls ) {
				unset( $key );
				$get_calls++;
				return null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$put_calls ) {
				unset( $key, $value, $ttl );
				$put_calls++;
			},
			static function ( string $data ): string {
				return hash_hmac( 'sha256', $data, 'p13-test' );
			},
			'wk_cm_',
			static function ( string $key, int $ttl ) use ( &$seq ) {
				unset( $key, $ttl );
				$seq++;
				return $seq; // 1,2,3,4…
			}
		);

		$this->assertTrue( $limiter->allow( '1.2.3.4' ), '原子计数 1 ≤ 3 放行' );
		$this->assertTrue( $limiter->allow( '1.2.3.4' ), '原子计数 2 ≤ 3 放行' );
		$this->assertTrue( $limiter->allow( '1.2.3.4' ), '原子计数 3 ≤ 3 放行（含本次恰满）' );
		$this->assertFalse( $limiter->allow( '1.2.3.4' ), '原子计数 4 > 3 拒绝' );
		$this->assertSame( 0, $get_calls, '原子路零 get 往返（check-then-inc 竞态窗消除）' );
		$this->assertSame( 0, $put_calls, '原子路零 put 覆写（并发丢计数面消除）' );
	}

	public function test_rate_limiter_atomic_false_falls_back_to_legacy_bucket(): void {
		$store      = array();
		$get_calls  = 0;
		$put_calls  = 0;
		$incr_calls = 0;
		$limiter    = new TransientRateLimiter(
			2,
			3600,
			static function ( string $key ) use ( &$store, &$get_calls ) {
				$get_calls++;
				return $store[ $key ] ?? null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$store, &$put_calls ) {
				unset( $ttl );
				$put_calls++;
				$store[ $key ] = $value;
			},
			static function ( string $data ): string {
				return hash_hmac( 'sha256', $data, 'p13-test' );
			},
			'wk_cm_',
			static function ( string $key, int $ttl ) use ( &$incr_calls ) {
				unset( $key, $ttl );
				$incr_calls++;
				return false; // 后端拒绝/不可用。
			}
		);

		$this->assertTrue( $limiter->allow( '9.9.9.9' ), 'incr=false → legacy 桶路放行' );
		$this->assertTrue( $limiter->allow( '9.9.9.9' ), 'legacy 第 2 次放行' );
		$this->assertFalse( $limiter->allow( '9.9.9.9' ), 'legacy 第 3 次超限拒绝（语义与批前一致）' );
		$this->assertSame( 3, $get_calls, 'legacy 路照常 get' );
		$this->assertSame( 2, $put_calls, 'legacy 路照常 put（饱和第 3 次 check 后拒绝不写）' );
		$this->assertSame( 3, $incr_calls, '每次先尝试原子路' );
	}

	public function test_rate_limiter_atomic_numeric_string_normalized(): void {
		// 【B 机实锤同族】Redis 跨进程标量可回读数值串——is_numeric 归一（LoginCounters/EventSampling 同纪律）。
		$limiter = new TransientRateLimiter(
			3,
			3600,
			static function ( string $key ) {
				unset( $key );
				return null;
			},
			static function ( string $key, $value, int $ttl ) {
				unset( $key, $value, $ttl );
			},
			static function ( string $data ): string {
				return hash_hmac( 'sha256', $data, 'p13-test' );
			},
			'wk_cm_',
			static function ( string $key, int $ttl ) {
				unset( $key, $ttl );
				return '3'; // 数值串形态。
			}
		);
		$this->assertTrue( $limiter->allow( '5.6.7.8' ), '数值串 3 ≤ 3 放行（is_numeric 归一）' );
	}

	public function test_event_sampling_atomic_path_caps_at_hourly_cap(): void {
		$get_calls = 0;
		$seq       = 0;
		EventSampling::use_store(
			static function ( string $key ) use ( &$get_calls ) {
				unset( $key );
				$get_calls++;
				return null;
			},
			static function ( string $key, $value, int $ttl ): void {
				unset( $key, $value, $ttl );
			}
		);
		EventSampling::use_store_incr( static function ( string $key, int $ttl ) use ( &$seq ) {
			unset( $key, $ttl );
			$seq++;
			return $seq;
		} );

		$ip_hash = '0123456789abcdef';
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->assertTrue( EventSampling::allow( 'stub', $ip_hash ), "原子路第 {$i} 行放行" );
		}
		$this->assertFalse( EventSampling::allow( 'stub', $ip_hash ), '原子路第 11 行封顶拒绝（HOURLY_CAP=10 语义不变）' );
		$this->assertSame( 0, $get_calls, '原子路零 get 往返' );
	}

	public function test_event_sampling_atomic_null_resets_to_legacy(): void {
		EventSampling::use_store_incr( static function ( string $key, int $ttl ) {
			unset( $key, $ttl );
			return 99; // 恒封顶形态——reset 后必须不再被消费。
		} );
		EventSampling::use_store_incr( null );

		$store = array();
		EventSampling::use_store(
			static function ( string $key ) use ( &$store ) {
				return $store[ $key ] ?? null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$store ): void {
				unset( $ttl );
				$store[ $key ] = $value;
			}
		);
		$this->assertTrue( EventSampling::allow( 'stub', 'fedcba9876543210' ), 'reset 后走 legacy 桶（首行放行）' );
	}

	public function test_login_counters_atomic_path_saturates_without_store_io(): void {
		$get_calls = 0;
		$set_calls = 0;
		$seq       = 0;
		$counters  = new LoginCounters(
			array( 'ip_threshold' => 3, 'user_threshold' => 3, 'window_minutes' => 15 ),
			static function ( string $key ) use ( &$get_calls ) {
				unset( $key );
				$get_calls++;
				return null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$set_calls ) {
				unset( $key, $value, $ttl );
				$set_calls++;
			},
			null,
			static function ( string $data ): string {
				return substr( hash_hmac( 'sha256', $data, 'p13-login' ), 0, 16 );
			},
			static function (): int {
				return 1731168000;
			},
			static function ( string $key, int $ttl ) use ( &$seq ) {
				unset( $key, $ttl );
				$seq++;
				return $seq; // 1,2,3…
			},
			static function ( string $key ) use ( &$seq ) {
				unset( $key );
				return $seq; // 原子层纯读假件（与写假件同计数源——生产=AtomicCounter::read）。
			}
		);

		$counters->record_failure( '198.51.100.9' );
		$counters->record_failure( '198.51.100.9' );
		$counters->record_failure( '198.51.100.9' );
		$this->assertTrue( $counters->is_saturated( '198.51.100.9', '' ), '原子计数 3 ≥ 阈 3 饱和' );
		$this->assertSame( 0, $get_calls, '原子路零 get 往返' );
		$this->assertSame( 1, $set_calls, 'set 恰一次=light_flag 渲染标志写（独立状态面，非计数桶）——计数桶零 set 覆写' );
	}

	public function test_login_counters_atomic_false_falls_back_to_legacy_bucket(): void {
		$store = array();
		$counters = new LoginCounters(
			array( 'ip_threshold' => 2, 'user_threshold' => 2, 'window_minutes' => 15 ),
			static function ( string $key ) use ( &$store ) {
				return $store[ $key ] ?? null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$store ): void {
				unset( $ttl );
				$store[ $key ] = $value;
			},
			null,
			static function ( string $data ): string {
				return substr( hash_hmac( 'sha256', $data, 'p13-login' ), 0, 16 );
			},
			static function (): int {
				return 1731168000;
			},
			static function ( string $key, int $ttl ) {
				unset( $key, $ttl );
				return false; // 后端拒绝 → legacy。
			}
		);

		$counters->record_failure( '198.51.100.8' );
		$this->assertFalse( $counters->is_saturated( '198.51.100.8', '' ), 'legacy 第 1 次未饱和' );
		$counters->record_failure( '198.51.100.8' );
		$this->assertTrue( $counters->is_saturated( '198.51.100.8', '' ), 'legacy 第 2 次饱和（批前语义不变）' );
	}

	/* ===================================================================
	 * P1-4 · 拦截留档容量闸（CommentGate archive_quota + Bootstrap 装配）
	 * =================================================================== */

	/** 复刻 CommentGateArchiveTest 夹具（gate 工厂 + 驱动 orchestrate）。 */
	private function gate( ?callable $captcha, array &$archive_log, array &$event_log, ?callable $archive_quota = null ): CommentGate {
		return new CommentGate(
			array(
				'gates'     => array( 'comment' => array( 'enabled' => true, 'render_mode' => 'always' ) ),
				'review'    => array( 'local' => array(
					'min_length' => 0, 'max_length' => 1800, 'require_chinese' => 1,
					'custom_banned_words' => '私活', 'use_wp_keywords' => 0,
				) ),
				'intercept' => array( 'archive_spam' => true ),
				'stats'     => array( 'enabled' => true, 'pass_sampling' => false ),
			),
			$captcha,
			null, // 自建 LocalRules（词表「私活」驱动 review 拒）。
			static function ( string $stage, Verdict $verdict, array $meta ) use ( &$event_log ): string {
				$event_log[] = array( 'stage' => $stage, 'meta' => $meta, 'verdict' => $verdict->verdict() );
				return 'WK-20261120-0001';
			},
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return 1731168000;
			},
			null,
			static function ( array $comment ) use ( &$archive_log ): ?int {
				$archive_log[] = $comment;
				return 777;
			},
			array(), // cloud_chain。
			null,    // halt_check。
			null,    // halt_mark。
			null,    // verify_cache。
			null,    // iprep。
			null,    // trust_mac。
			null,    // driver_meta。
			null,    // require_gate。
			null,    // degraded_limiter。
			null,    // submit_limiter。
			$archive_quota // 【P1-4】留档容量判定位（末位追加）。
		);
	}

	private static function commentdata(): array {
		return array(
			'comment_post_ID'      => 42,
			'comment_author'       => '张三',
			'comment_author_email' => 'zhang@example.com',
			'comment_content'      => '这是藏了私活的中文评论。',
		);
	}

	private static function request(): array {
		return array( 'comment_post_ID' => '42' );
	}

	private static function server(): array {
		return array(
			'REMOTE_ADDR'     => '203.0.113.7',
			'HTTP_USER_AGENT' => 'WINK-Batch2Test/1.0',
		);
	}

	public function test_archive_quota_refuse_blocks_archive_but_not_rejection(): void {
		$archive_log = array();
		$event_log   = array();
		$seen_ips    = array();
		$gate        = $this->gate(
			null,
			$archive_log,
			$event_log,
			static function ( string $ip ) use ( &$seen_ips ): bool {
				$seen_ips[] = $ip;
				return false; // 容量闸拒。
			}
		);

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( 'reject', $result['decision'], '拦截行为本身不变（P1-4 只封落库不封拦截）' );
		$this->assertCount( 0, $archive_log, '容量闸拒 → 零留档（wp_comments 不再无界膨胀）' );
		$this->assertCount( 1, $event_log, '事件照常落（事件面已有 P1-4 采样/容量闸独立收口）' );
		$this->assertSame( array( '203.0.113.7' ), $seen_ips, '判定位拿到的是解析后真实 IP' );
		$this->assertArrayNotHasKey( 'comment_id', $event_log[0]['meta'], '无留档 → 事件 meta 无 comment_id 回填' );
	}

	public function test_archive_quota_allow_archives_normally(): void {
		$archive_log = array();
		$event_log   = array();
		$gate        = $this->gate( null, $archive_log, $event_log, static function ( string $ip ): bool {
			unset( $ip );
			return true;
		} );

		$gate->orchestrate( self::commentdata(), self::request(), self::server() );
		$this->assertCount( 1, $archive_log, '额度内照常留档' );
		$this->assertSame( 777, $event_log[0]['meta']['comment_id'], 'comment_id 回填照常' );
	}

	public function test_archive_quota_absent_keeps_batch_prior_behavior(): void {
		// archive_quota=null（未装配形态）→ 批前行为：留档照旧（向后兼容零回归锚）。
		$archive_log = array();
		$event_log   = array();
		$gate        = $this->gate( null, $archive_log, $event_log, null );

		$gate->orchestrate( self::commentdata(), self::request(), self::server() );
		$this->assertCount( 1, $archive_log, '未装配容量闸 = 批前留档语义' );
	}

	public function test_archive_cap_defaults_and_consts(): void {
		$defaults = SettingsSchema::defaults();
		$this->assertSame( 10, $defaults['intercept']['archive_daily_ip_cap'] ?? null, 'schema 新键：per-IP 日留档帽默认 10' );
		$this->assertSame( 200, $defaults['intercept']['archive_daily_total_cap'] ?? null, 'schema 新键：全局日留档帽默认 200' );
		$this->assertSame( 10, constant( 'WinkGatekeeper\\Gates\\CommentGate::ARCHIVE_IP_CAP_DEFAULT' ), '装配回退常量与 schema 默认一致' );
		$this->assertSame( 200, constant( 'WinkGatekeeper\\Gates\\CommentGate::ARCHIVE_TOTAL_CAP_DEFAULT' ), '装配回退常量与 schema 默认一致' );
		$this->assertSame( 86400, constant( 'WinkGatekeeper\\Gates\\CommentGate::ARCHIVE_DAY_WINDOW' ), '日桶窗口 86400' );
	}

	public function test_bootstrap_wires_archive_quota_and_atomic_counter(): void {
		$bootstrap = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Bootstrap.php' );
		$this->assertStringContainsString( 'archive_quota', $bootstrap, 'Bootstrap 装配 archive_quota 判定位（P1-4）' );
		$this->assertStringContainsString( 'wk_cai_', $bootstrap, 'per-IP 日桶前缀 wk_cai_' );
		$this->assertStringContainsString( 'wk_cag_', $bootstrap, '全局日桶前缀 wk_cag_' );
		$this->assertStringContainsString( 'AtomicCounter::incr', $bootstrap, '限流装配接入原子自增件（P1-3）' );

		$uninstall = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
		$this->assertStringContainsString( 'wk_cai_', $uninstall, 'uninstall 前缀清扫补录 wk_cai_' );
		$this->assertStringContainsString( 'wk_cag_', $uninstall, 'uninstall 前缀清扫补录 wk_cag_' );

		$sanitize = (string) file_get_contents( dirname( __DIR__ ) . '/includes/settings/Sanitize.php' );
		$this->assertStringContainsString( "'intercept.archive_daily_ip_cap'", $sanitize, 'sanitize 类型面补录 per-IP 帽' );
		$this->assertStringContainsString( "'intercept.archive_daily_total_cap'", $sanitize, 'sanitize 类型面补录全局帽' );
	}

	/* ===================================================================
	 * P1-5 · AbuseIPDB 负缓存延长 + /24 聚合
	 * =================================================================== */

	/** 复刻 AbuseIpdbServiceTest 夹具（全依赖注入 + 引用回传断言面）。 */
	private function svc(
		$response,
		array &$cache,
		array &$cache_puts,
		array &$calls,
		array $top_overrides = array()
	): AbuseIpdbService {
		$transport = static function ( string $url, array $args ) use ( &$calls, $response ) {
			$calls[] = array( 'url' => $url, 'args' => $args );
			return $response;
		};
		$remote    = new RemoteClient(
			$transport,
			static function ( string $key ): void {
				unset( $key ); // 计量不考察。
			},
			static function ( int $ms ): void {
				unset( $ms );
			}
		);

		$settings = array(
			'iprep' => array_merge( array(
				'enabled'   => true,
				'driver'    => 'abuseipdb',
				'abuseipdb' => array(
					'api_key'         => 'test-key-123',
					'suspect_score'   => 60,
					'malicious_score' => 85,
					'cache_minutes'   => 1440,
					'timeout'         => 3,
				),
			), $top_overrides ),
		);

		return new AbuseIpdbService(
			$settings,
			$remote,
			static function ( string $key ) use ( &$cache ) {
				return array_key_exists( $key, $cache ) ? $cache[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$cache, &$cache_puts ): void {
				$cache[ $key ] = $value;
				$cache_puts[]  = array( 'key' => $key, 'value' => $value, 'ttl' => $ttl );
			},
			static function ( string $key ) use ( &$cache ) {
				return array_key_exists( $key, $cache ) ? $cache[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$cache ): void {
				unset( $ttl );
				$cache[ $key ] = $value; // 退避写同 store。
			},
			static function ( string $driver_id ) {
				unset( $driver_id );
				return null; // DriverHealth 正常。
			},
			static function ( string $driver_id, string $state ): void {
				unset( $driver_id, $state );
			},
			static function (): array {
				return array( 'date' => '2026-11-20', 'counters' => array( 'abuseipdb' => 0 ) );
			},
			static function ( string $data ): string {
				return substr( hash_hmac( 'sha256', $data, self::HMACsalt ), 0, 16 );
			},
			static function (): bool {
				return false;
			},
			static function (): array {
				return array();
			},
			static function ( string $ip ): bool {
				unset( $ip );
				return false;
			},
			static function (): int {
				return 1731321600;
			}
		);
	}

	private static function ok_response( int $score, bool $isPublic = true ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( array(
				'data' => array(
					'ipAddress'            => self::IP,
					'abuseConfidenceScore' => $score,
					'isPublic'             => $isPublic,
					'isTor'                => false,
					'countryCode'          => 'US',
				),
			) ),
			'headers'  => array(),
		);
	}

	private static function exact_key(): string {
		return 'wk_ip_abuseipdb_' . substr( hash_hmac( 'sha256', self::IP, self::HMACsalt ), 0, 16 );
	}

	private static function net24_key(): string {
		return 'wk_ip24_abuseipdb_' . substr( hash_hmac( 'sha256', '203.0.113', self::HMACsalt ), 0, 16 );
	}

	public function test_negative_cache_default_extended_to_360(): void {
		$cache = array(); $cache_puts = array(); $calls = array();
		// negative_cache_minutes 键缺席 → 服务内默认承载（P1-5：60 → 360）。
		$service = $this->svc( self::ok_response( 99 ), $cache, $cache_puts, $calls );
		$verdict = $service->probe( self::IP );
		$this->assertSame( Verdict::REJECT, $verdict->verdict() );
		$this->assertNotEmpty( $cache_puts );
		$exact_put = null;
		foreach ( $cache_puts as $put ) {
			if ( 0 === strpos( $put['key'], 'wk_ip_' ) ) {
				$exact_put = $put;
				break;
			}
		}
		$this->assertNotNull( $exact_put, 'exact 缓存写在场' );
		$this->assertSame( 21600, $exact_put['ttl'], '负缓存默认 360min=21600s（批前 60min=3600s——REPORT §10-3 延长）' );
	}

	public function test_negative_write_also_fills_24_cache_pass_does_not(): void {
		$cache = array(); $cache_puts = array(); $calls = array();
		$service = $this->svc( self::ok_response( 99 ), $cache, $cache_puts, $calls );
		$service->probe( self::IP );

		$keys = array_column( $cache_puts, 'key' );
		$this->assertContains( self::exact_key(), $keys, 'exact 键写在场' );
		$this->assertContains( self::net24_key(), $keys, '/24 聚合键写在场（负向 verdict 才聚合）' );
		$put24 = $cache_puts[ array_search( self::net24_key(), $keys, true ) ];
		$this->assertSame( 'reject', $put24['value']['v'], '/24 键承载同判定' );

		// pass verdict 不进 /24 键（防邻居 trust_pass 放大——聚合只做负向 fail-safe）。
		$cache = array(); $cache_puts = array(); $calls = array();
		$service2 = $this->svc( self::ok_response( 12 ), $cache, $cache_puts, $calls );
		$service2->probe( self::IP );
		$keys2 = array_column( $cache_puts, 'key' );
		$this->assertContains( self::exact_key(), $keys2, 'pass 仍写 exact 键' );
		$this->assertNotContains( self::net24_key(), $keys2, 'pass 不写 /24 键（不放大信任面）' );
	}

	public function test_24_hit_returns_negative_verdict_without_outbound(): void {
		$cache = array(
			self::net24_key() => array( 'v' => 'reject', 's' => 99 ), // 预置 /24 聚合键（邻居曾外呼实锤）。
		);
		$cache_puts = array(); $calls = array();
		$service = $this->svc( self::ok_response( 0 ), $cache, $cache_puts, $calls );
		$verdict = $service->probe( '203.0.113.200' ); // 同 /24 邻居（exact 无缓存）。
		$this->assertSame( Verdict::REJECT, $verdict->verdict(), '/24 命中 → 负判定直出' );
		$this->assertCount( 0, $calls, '零外呼（轮换边缘配额代烧面收敛——S3-F3 实测 18 发 10 外呼病灶）' );
		$meta = $verdict->event_meta();
		$this->assertTrue( (bool) ( $meta['cached'] ?? false ), 'cached 标注在场' );
		$this->assertTrue( (bool) ( $meta['aggregated'] ?? false ), 'aggregated 标注在场（证据面标注聚合来源）' );
	}

	public function test_24_corrupt_value_treated_as_miss_not_corrupt_fail(): void {
		$cache = array(
			self::net24_key() => 'garbage-string', // 聚合层损坏。
		);
		$cache_puts = array(); $calls = array();
		$service = $this->svc( self::ok_response( 5 ), $cache, $cache_puts, $calls );
		$verdict = $service->probe( self::IP );
		$this->assertNotNull( $verdict, '聚合层损坏 = miss 落外呼（不从严 corrupt_fail——聚合是配额保护优化层）' );
		$this->assertSame( Verdict::PASS, $verdict->verdict(), '外呼真实结果照常映射' );
		$this->assertCount( 1, $calls, '外呼恰一次' );
	}

	public function test_schema_negative_default_360_and_feature_shift(): void {
		$defaults = SettingsSchema::defaults();
		$this->assertSame( 360, $defaults['iprep']['negative_cache_minutes'] ?? null, 'schema 默认负缓存 360min（P1-5 上移）' );

		// 特征值平移：站长从未动过的 60（批前默认）→ 移除重填 360；显式 45 原样保留。
		$shifted = SettingsSchema::migrate( array( 'iprep' => array( 'negative_cache_minutes' => 60 ) ) );
		$merged  = SettingsSchema::merge_defaults( $shifted );
		$this->assertSame( 360, $merged['iprep']['negative_cache_minutes'], '特征值 60 平移到新默认 360' );

		$explicit = SettingsSchema::merge_defaults( SettingsSchema::migrate( array( 'iprep' => array( 'negative_cache_minutes' => 45 ) ) ) );
		$this->assertSame( 45, $explicit['iprep']['negative_cache_minutes'], '站长显式值原样保留' );
	}
}
