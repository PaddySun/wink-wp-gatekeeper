<?php
/**
 * AbuseIpdbService 单元测试（M3-IP 定稿 §3 check 七步流水线 / §2 cache_only / §9 事件 meta；
 * 02 §2.3b【v1.7·M3-IP】承载机制；§10-1/2 测试清单）。
 *
 * 覆盖（§10-1/2）：
 *   - 三态边界（59/60/84/85 两侧）×阈值可配、isPublic=false→skip；
 *   - 超时→退避 300s 落位+不停转；429→停转+退避+Retry-After 尊重；401→config_error；
 *   - 软顶→fail 不外呼（计数器断言）；缓存命中零外呼；缓存只在外呼成功后写；
 *   - 契约不符→contract_mismatch 哨兵+退避；退避窗口内全 null、过期恢复外呼；
 *   - cache_only：命中返 verdict/未命中∨退避∨停转返 fail/全程零外呼（计数器断言）；
 *   - 白名单/管理员/自身 IP→null（零外呼）；事件 meta（score 直存/cached/soft_quota/raw）；
 *   - registry_entry 合法（DriverRegistry 校验 + by_type('ip')）。
 *
 * 传输脊/缓存/退避/停转/用量/时钟/白名单全注入——纯逻辑零 WP 依赖；
 * 外呼次数断言=注入 fake transport 计数器。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Ip\AbuseIpdbService;
use WinkGatekeeper\Verdict;

final class AbuseIpdbServiceTest extends TestCase {

	private const IP      = '203.0.113.7';
	private const HMAC_salt = 'i2-abuseipdb-test';

	/* -------------------------------------------------------------------
	 * 夹具
	 * ------------------------------------------------------------------ */

	/**
	 * 设置树（最小 iprep/stats 形态，defaults 键集对齐 SettingsSchema）。
	 *
	 * @param array<string, mixed> $abuse_overrides abuseipdb 子树覆盖。
	 * @param array<string, mixed> $top_overrides   iprep 顶层覆盖。
	 * @return array<string, mixed>
	 */
	private static function settings( array $abuse_overrides = array(), array $top_overrides = array() ): array {
		return array(
			'iprep' => array_merge( array(
				'enabled'                => true,
				'driver'                 => 'abuseipdb',
				'negative_cache_minutes' => 60,
				'abuseipdb'              => array_merge( array(
					'api_key'         => 'test-key-123',
					'suspect_score'   => 60,
					'malicious_score' => 85,
					'cache_minutes'   => 1440,
					'soft_quota'      => 3000,
					'daily_quota'     => 4750,
					'timeout'         => 3,
				), $abuse_overrides ),
			), $top_overrides ),
			'stats' => array( 'raw_capture' => true, 'raw_max_bytes' => 512 ),
		);
	}

	/**
	 * 构造被测服务（全依赖注入；store/计数器经引用回传断言面）。
	 *
	 * @param array<string, mixed>     $settings      设置树。
	 * @param mixed                    $response      fake transport 每次尝试的返回（wp_remote_get 形态/false）。
	 * @param array<string, mixed>     &$cache        transient store（缓存+退避共用 store，生产同脊）。
	 * @param array<int, array>        &$cache_puts   缓存写捕获。
	 * @param array<int, array>        &$backoff_puts 退避写捕获。
	 * @param array<int, array>        &$health_marks 停转标记捕获。
	 * @param array<int, array>        &$calls        外呼捕获（url/args）。
	 * @param int                      &$usage        RemoteClient 计量钩子计数（外呼尝试即计）。
	 * @param string|null              $health_state  DriverHealth 当日态（null=正常）。
	 * @param int                      $usage_today   Usage 当日 abuseipdb 计数（软顶评估输入）。
	 * @param callable|null            $is_admin      管理员判定（默认恒 false）。
	 * @param array<int, string>       $server_ips    服务器自身 IP 集（默认空）。
	 * @param callable|null            $whitelist     站长白名单匹配（默认恒 false）。
	 * @return AbuseIpdbService
	 */
	private function service(
		array $settings,
		$response,
		array &$cache,
		array &$cache_puts,
		array &$backoff_puts,
		array &$health_marks,
		array &$calls,
		int &$usage,
		?string $health_state = null,
		int $usage_today = 0,
		?callable $is_admin = null,
		array $server_ips = array(),
		?callable $whitelist = null
	): AbuseIpdbService {
		$transport = function ( string $url, array $args ) use ( &$calls, $response ) {
			$calls[] = array( 'url' => $url, 'args' => $args );
			return $response;
		};
		$remote = new RemoteClient(
			$transport,
			function ( string $key ) use ( &$usage ): void {
				$usage++; // 与生产 [Usage::class,'bump_option'] 同形——外呼尝试即计。
			},
			static function ( int $ms ): void {
				unset( $ms ); // 重试纪律 sleep 零等待。
			}
		);

		return new AbuseIpdbService(
			$settings,
			$remote,
			static function ( string $key ) use ( &$cache ) {
				return array_key_exists( $key, $cache ) ? $cache[ $key ] : null; // 缓存读。
			},
			static function ( string $key, $value, int $ttl ) use ( &$cache, &$cache_puts ): void {
				$cache[ $key ]      = $value;
				$cache_puts[]       = array( 'key' => $key, 'value' => $value, 'ttl' => $ttl );
			},
			static function ( string $key ) use ( &$cache ) {
				return array_key_exists( $key, $cache ) ? $cache[ $key ] : null; // 退避读（同 store）。
			},
			static function ( string $key, $value, int $ttl ) use ( &$cache, &$backoff_puts ): void {
				$cache[ $key ]       = $value;
				$backoff_puts[]      = array( 'key' => $key, 'value' => $value, 'ttl' => $ttl );
			},
			static function ( string $driver_id ) use ( $health_state ): ?string {
				unset( $driver_id );
				return $health_state; // DriverHealth::state_today 注入位。
			},
			static function ( string $driver_id, string $state ) use ( &$health_marks ): void {
				$health_marks[] = array( 'driver' => $driver_id, 'state' => $state ); // DriverHealth::mark_option 注入位。
			},
			static function () use ( $usage_today ): array {
				return array( 'date' => '2026-11-12', 'counters' => array( 'abuseipdb' => $usage_today ) ); // Usage::get 注入位。
			},
			static function ( string $data ): string {
				return substr( hash_hmac( 'sha256', $data, self::HMAC_salt ), 0, 16 ); // 与生产 wp_salt('auth') 同形。
			},
			$is_admin ?? static function (): bool {
				return false;
			},
			static function () use ( $server_ips ): array {
				return $server_ips; // $_SERVER['SERVER_ADDR'] 注入位。
			},
			$whitelist ?? static function ( string $ip ): bool {
				unset( $ip );
				return false; // 站长手动白名单（I4 接线；默认空集）。
			},
			static function (): int {
				return 1731321600; // 固定时钟（2026-11-11 16:00 UTC——退避过期断言用）。
			}
		);
	}

	/**
	 * 简写：默认 store/计数器 + 指定响应，一键构造。
	 *
	 * @return array<string, mixed>
	 */
	private function quick( $response, array $settings = null, array $deps = array() ): array {
		$cache = array(); $cache_puts = array(); $backoff_puts = array();
		$health_marks = array(); $calls = array(); $usage = 0;
		$service = $this->service(
			$settings ?? self::settings(),
			$response,
			$cache,
			$cache_puts,
			$backoff_puts,
			$health_marks,
			$calls,
			$usage,
			$deps['health_state'] ?? null,
			$deps['usage_today'] ?? 0,
			$deps['is_admin'] ?? null,
			$deps['server_ips'] ?? array(),
			$deps['whitelist'] ?? null
		);
		return array(
			'service'      => $service,
			'cache'        => &$cache,
			'cache_puts'   => &$cache_puts,
			'backoff_puts' => &$backoff_puts,
			'health_marks' => &$health_marks,
			'calls'        => &$calls,
			'usage'        => &$usage,
		);
	}

	/**
	 * AbuseIPDB check 成功响应夹具。
	 *
	 * @param int  $score    abuseConfidenceScore。
	 * @param bool $isPublic isPublic 标志。
	 * @return array<string, mixed>
	 */
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

	private function cache_key( string $ip = self::IP ): string {
		return 'wk_ip_abuseipdb_' . substr( hash_hmac( 'sha256', $ip, self::HMAC_salt ), 0, 16 );
	}

	/* -------------------------------------------------------------------
	 * §10-1 三态边界 ×阈值可配
	 * ------------------------------------------------------------------ */

	public function test_score_boundaries_59_60_84_85(): void {
		// 59 → pass；60 → review（suspect 默认 60）；84 → review；85 → reject（malicious 默认 85）。
		$cases = array(
			59 => Verdict::PASS,
			60 => Verdict::REVIEW,
			84 => Verdict::REVIEW,
			85 => Verdict::REJECT,
		);
		foreach ( $cases as $score => $expected ) {
			$env = $this->quick( self::ok_response( $score ) );
			$verdict = $env['service']->probe( self::IP );
			$this->assertNotNull( $verdict, "score={$score} 必须产出判定" );
			$this->assertSame( $expected, $verdict->verdict(), "score={$score} 映射错位（O5-P1-2：≥85 reject / ≥60 review / 其余 pass）" );
			$this->assertSame( $score, $env['cache_puts'][0]['value']['s'] ?? null, "score={$score} 整数直存缓存（meta.score 同源）" );
		}
	}

	public function test_thresholds_configurable(): void {
		// 阈值可配：suspect=70 → score 65 = pass；malicious=90 → score 85 = review。
		$env = $this->quick( self::ok_response( 65 ), self::settings( array( 'suspect_score' => 70 ) ) );
		$this->assertSame( Verdict::PASS, $env['service']->probe( self::IP )->verdict(), 'suspect=70 时 score 65 < 70 → pass' );

		$env2 = $this->quick( self::ok_response( 85 ), self::settings( array( 'malicious_score' => 90 ) ) );
		$this->assertSame( Verdict::REVIEW, $env2['service']->probe( self::IP )->verdict(), 'malicious=90 时 score 85 ∈ [70,90) → review' );
	}

	public function test_mapping_pass_verdict_carries_score_meta(): void {
		// §9：meta.score 0–100 整数直存（O5-P3-2 删「截断两位」拟稿）。
		$env = $this->quick( self::ok_response( 12 ) );
		$verdict = $env['service']->probe( self::IP );
		$this->assertSame( Verdict::PASS, $verdict->verdict() );
		$this->assertSame( array( 'score' => 12 ), $verdict->event_meta(), 'pass verdict 携 score meta（消费方原样落 meta）' );
	}

	public function test_is_public_false_skips_without_cache(): void {
		// isPublic=false → skip（null），不缓存不外呼重试（05 §3.1 判定行）。
		$env = $this->quick( self::ok_response( 100, false ) );
		$this->assertNull( $env['service']->probe( self::IP ), '私网/内部地址（isPublic=false）→ null skip' );
		$this->assertCount( 1, $env['calls'], '单次外呼（无重试面）' );
		$this->assertSame( array(), $env['cache_puts'], 'skip 不写缓存' );
	}

	/* -------------------------------------------------------------------
	 * §10-1 外呼契约（URL/头/超时/计量）
	 * ------------------------------------------------------------------ */

	public function test_outbound_request_contract(): void {
		$env = $this->quick( self::ok_response( 0 ) );
		$env['service']->probe( self::IP );

		$this->assertCount( 1, $env['calls'] );
		$call = $env['calls'][0];
		$this->assertSame( 'https://api.abuseipdb.com/api/v2/check?ipAddress=' . self::IP . '&maxAgeInDays=90', $call['url'], '定稿 §3.1 步 6 契约（maxAgeInDays=90）' );
		$this->assertSame( 'test-key-123', $call['args']['headers']['Key'], '头 Key: {api_key}' );
		$this->assertSame( 'application/json', $call['args']['headers']['Accept'], '头 Accept: application/json（官方要求——否则 429 可能回 HTML）' );
		$this->assertSame( 3, $call['args']['timeout'], 'timeout=iprep.abuseipdb.timeout 默认 3s（P0-9 上调——B 机实测 P95 1.99s）' );
		// usage_key/required 由 RemoteClient 消费不入 wp_args——计量经钩子计数实证（本行），
		// required 判据由 test_contract_mismatch_sets_backoff_and_sentinel_hits 行为实证。
		$this->assertSame( 1, $env['usage'], '外呼尝试即计（计量键=abuseipdb 经 RemoteClient 钩子→Usage::bump_option，§9/04 §6）' );
	}

	public function test_ipv6_url_encoded_and_normalized(): void {
		// IPv6 冒号走 rawurlencode；::ffff: 变体归一（定稿 §3.1 步 1/步 6）。
		$env = $this->quick( self::ok_response( 0 ) );
		$env['service']->probe( '2001:db8::1' );
		$this->assertSame( 'https://api.abuseipdb.com/api/v2/check?ipAddress=2001%3Adb8%3A%3A1&maxAgeInDays=90', $env['calls'][0]['url'], 'IPv6 URL 编码（冒号）' );
	}

	/* -------------------------------------------------------------------
	 * §10-1 fail-open 矩阵
	 * ------------------------------------------------------------------ */

	public function test_transport_timeout_sets_backoff_300_without_halt(): void {
		// 超时（transport）→ 落退避 300s + meta.raw 快照 + 不停转（可能是抖动）。
		$env = $this->quick( false ); // 非数组=传输失败（超时/连接失败同分类）。
		$verdict = $env['service']->probe( self::IP );

		$this->assertNotNull( $verdict );
		$this->assertSame( Verdict::ERROR, $verdict->verdict(), '超时=fail 档（02 §2.3b fail ②）' );
		$this->assertSame( 'remote_transport', $verdict->reason() );
		$this->assertCount( 1, $env['backoff_puts'], '落退避' );
		$this->assertSame( 'wk_irb_abuseipdb', $env['backoff_puts'][0]['key'], '退避键 wk_irb_<driver>（O5-P2-1 前缀惯例）' );
		$this->assertSame( 300, $env['backoff_puts'][0]['ttl'], '全局退避 300s' );
		$this->assertSame( array(), $env['health_marks'], '不停转（transport 可能是抖动）' );
		$this->assertSame( array(), $env['cache_puts'], '失败不写缓存（只在外呼成功后）' );
		$this->assertArrayHasKey( 'raw', $verdict->event_meta(), 'meta.raw 脱敏快照（512B 红线）' );
		$this->assertSame( 'transport', $verdict->event_meta()['raw']['category'], 'raw.category=transport' );
		$this->assertStringNotContainsString( 'test-key', (string) json_encode( $verdict->event_meta()['raw'] ), 'raw 不含密钥（redact 红线）' );
	}

	public function test_429_halts_quota_and_backs_off(): void {
		// 429 → 退避 300s + DriverHealth quota_exhausted 当日停转（次日自愈）。
		$response = array(
			'response' => array( 'code' => 429 ),
			'body'     => '{"errors":[{"title":"rate limited"}]}',
			'headers'  => array(),
		);
		$env = $this->quick( $response );
		$verdict = $env['service']->probe( self::IP );

		$this->assertSame( Verdict::ERROR, $verdict->verdict() );
		$this->assertCount( 1, $env['backoff_puts'], '429 落退避' );
		$this->assertSame( 300, $env['backoff_puts'][0]['ttl'] );
		$this->assertSame( array( array( 'driver' => 'abuseipdb', 'state' => 'quota_exhausted' ) ), $env['health_marks'], 'DriverHealth quota_exhausted 当日停转' );
		$this->assertSame( array(), $env['cache_puts'] );
	}

	public function test_429_respects_retry_after_when_at_least_300(): void {
		// Retry-After 尊重：响应头 600s ≥300s → 退避按其值 600s。
		$response = array(
			'response' => array( 'code' => 429 ),
			'body'     => '{}',
			'headers'  => array( 'Retry-After' => '600' ),
		);
		$env = $this->quick( $response );
		$env['service']->probe( self::IP );
		$this->assertSame( 600, $env['backoff_puts'][0]['ttl'], 'Retry-After=600 ≥300 → 按其值' );

		// Retry-After=120 <300 → 仍按基线 300s。
		$response2 = array(
			'response' => array( 'code' => 429 ),
			'body'     => '{}',
			'headers'  => array( 'Retry-After' => '120' ),
		);
		$env2 = $this->quick( $response2 );
		$env2['service']->probe( self::IP );
		$this->assertSame( 300, $env2['backoff_puts'][0]['ttl'], 'Retry-After=120 <300 → 基线 300s' );
	}

	public function test_401_marks_config_error_without_backoff(): void {
		// 401（semantic）→ DriverHealth config_error 当日停转 + Tab① 红点语义；不落退避。
		$response = array(
			'response' => array( 'code' => 401 ),
			'body'     => '{"errors":[{"title":"unauthorized"}]}',
			'headers'  => array(),
		);
		$env = $this->quick( $response );
		$verdict = $env['service']->probe( self::IP );

		$this->assertSame( Verdict::ERROR, $verdict->verdict() );
		$this->assertSame( 'remote_semantic', $verdict->reason() );
		$this->assertSame( array( array( 'driver' => 'abuseipdb', 'state' => 'config_error' ) ), $env['health_marks'], '401 → config_error 停转' );
		$this->assertSame( array(), $env['backoff_puts'], 'semantic 不落退避（停转已承载快速失败）' );
		$this->assertArrayHasKey( 'raw', $verdict->event_meta() );
	}

	public function test_contract_mismatch_sets_backoff_and_sentinel_hits(): void {
		// 200 但 body 缺 data.abuseConfidenceScore → contract_mismatch 哨兵 + 退避。
		$response = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"data":{"unexpected":"shape"}}',
			'headers'  => array(),
		);
		$env = $this->quick( $response );
		$verdict = $env['service']->probe( self::IP );

		$this->assertSame( Verdict::ERROR, $verdict->verdict() );
		$this->assertContains( 'contract_mismatch', $verdict->hits(), '哨兵标签落 rule_hit（D13 既有机制消费）' );
		$this->assertCount( 1, $env['backoff_puts'], '契约不符落退避' );
		$this->assertSame( array(), $env['health_marks'], '契约不符不停转（哨兵+退避）' );
	}

	/* -------------------------------------------------------------------
	 * §10-1 软顶（从严例外之一）
	 * ------------------------------------------------------------------ */

	public function test_soft_quota_fail_without_outbound(): void {
		// Usage 当日 abuseipdb ≥ soft_quota → fail 不外呼（计数器断言零外呼）。
		$env = $this->quick( self::ok_response( 0 ), null, array( 'usage_today' => 3000 ) );
		$verdict = $env['service']->probe( self::IP );

		$this->assertSame( Verdict::REJECT, $verdict->verdict(), '软顶按 fail 处理（保守态——宁可从严不可静默放行）' );
		$this->assertSame( 'soft_quota', $verdict->reason() );
		$this->assertSame( array( 'soft_quota' => true ), $verdict->event_meta(), 'meta.soft_quota 命中标志（§9）' );
		$this->assertCount( 0, $env['calls'], '软顶零外呼（计数器断言）' );
		$this->assertSame( 0, $env['usage'], '零计量' );
		$this->assertSame( array(), $env['cache_puts'], '软顶不产生缓存写' );
		$this->assertSame( array(), $env['health_marks'], '软顶非停转（次日 Usage 翻转自愈）' );
	}

	public function test_soft_quota_below_threshold_proceeds(): void {
		$env = $this->quick( self::ok_response( 0 ), null, array( 'usage_today' => 2999 ) );
		$verdict = $env['service']->probe( self::IP );
		$this->assertSame( Verdict::PASS, $verdict->verdict(), '2999 < 3000 → 照常外呼' );
		$this->assertCount( 1, $env['calls'] );
	}

	/* -------------------------------------------------------------------
	 * §10-1 缓存（归一后内化服务）
	 * ------------------------------------------------------------------ */

	public function test_cache_hit_skips_outbound_and_marks_cached_meta(): void {
		$env = $this->quick( self::ok_response( 99 ) );
		$env['service']->probe( self::IP ); // 首次外呼回填。
		$this->assertCount( 1, $env['calls'] );

		$env2 = $this->quick( self::ok_response( 99 ) );
		$env2['cache'][ $this->cache_key() ] = array( 'v' => 'pass', 's' => 12 );
		$verdict = $env2['service']->probe( self::IP );
		$this->assertSame( Verdict::PASS, $verdict->verdict(), '缓存命中返回缓存 verdict' );
		$this->assertSame( array( 'cached' => true, 'score' => 12 ), $verdict->event_meta(), 'meta.cached=true + score 同出（§9）' );
		$this->assertCount( 0, $env2['calls'], '缓存命中零外呼（计数器断言）' );
		$this->assertSame( 0, $env2['usage'], '命中零计量' );
	}

	public function test_cache_ttl_by_verdict_class(): void {
		// pass TTL=cache_minutes(1440)；fail/review 负缓存 TTL=negative_cache_minutes(60)。
		$env = $this->quick( self::ok_response( 10 ) );
		$env['service']->probe( self::IP );
		$this->assertSame( 1440 * 60, $env['cache_puts'][0]['ttl'], 'pass TTL=cache_minutes×60（默认平移后 24h）' );

		$env2 = $this->quick( self::ok_response( 90 ) );
		$env2['service']->probe( self::IP );
		$this->assertSame( 60 * 60, $env2['cache_puts'][0]['ttl'], 'fail 负缓存 TTL=negative_cache_minutes×60（默认平移后 1h）' );
	}

	public function test_cache_written_only_after_successful_outbound(): void {
		// 失败（transport/429/401/contract）一律不写缓存——只在外呼成功后。
		foreach ( array( false, 429, 401 ) as $scenario ) {
			$response = true === $scenario
				? false
				: array( 'response' => array( 'code' => $scenario ), 'body' => '{}', 'headers' => array() );
			$env = $this->quick( $response );
			$env['service']->probe( self::IP );
			$this->assertSame( array(), $env['cache_puts'], '失败场景（' . $scenario . '）不写缓存' );
		}
	}

	public function test_corrupted_cache_value_reads_fail_without_outbound(): void {
		// 损坏缓存值按 fail（fail-safe 从严——02 §2.3b 沿 LoginIpRep 旧约）。
		$env = $this->quick( self::ok_response( 0 ) );
		$env['cache'][ $this->cache_key() ] = array( 'garbage' => true );
		$verdict = $env['service']->probe( self::IP );
		$this->assertSame( Verdict::REJECT, $verdict->verdict(), '损坏缓存值 → fail（从严）' );
		$this->assertCount( 0, $env['calls'], '损坏缓存不触发外呼（TTL 内自愈）' );
	}

	public function test_cache_key_normalizes_variant_notation(): void {
		// ::ffff: 变体归一进缓存键（防变体稀释缓存——定稿 §3.1 步 1）。
		$env = $this->quick( self::ok_response( 5 ) );
		$env['service']->probe( self::IP );
		$this->assertArrayHasKey( $this->cache_key(), $env['cache'], '规范形键回填' );

		$env2 = $this->quick( self::ok_response( 5 ) );
		$env2['cache'][ $this->cache_key() ] = array( 'v' => 'pass', 's' => 5 );
		$verdict = $env2['service']->probe( '::ffff:203.0.113.7' );
		$this->assertSame( Verdict::PASS, $verdict->verdict(), '变体形态命中同一缓存键' );
		$this->assertCount( 0, $env2['calls'] );
	}

	/* -------------------------------------------------------------------
	 * §10-2 退避窗口
	 * ------------------------------------------------------------------ */

	public function test_backoff_window_skips_outbound(): void {
		$env2 = $this->quick( self::ok_response( 0 ) );
		$env2['cache'][ 'wk_irb_abuseipdb' ] = 1;
		$this->assertNull( $env2['service']->probe( self::IP ), '退避在位 → skip（零外呼闸行为零变化）' );
		$this->assertCount( 0, $env2['calls'], '退避窗口内全 null 零外呼' );
		$this->assertSame( 0, $env2['usage'] );
		$this->assertSame( array(), $env2['cache_puts'], '退避窗口内零缓存写' );
	}

	public function test_backoff_expiry_resumes_outbound(): void {
		// §10-2：过期恢复外呼——transient TTL 语义以时钟检查的 fake store 模拟。
		$clock = 1731321600;
		$store = array(); $cache_puts = array(); $backoff_puts = array();
		$health_marks = array(); $calls = array(); $usage = 0;

		$transport = function ( string $url, array $args ) use ( &$calls, &$clock ) {
			$calls[] = array( 'url' => $url, 'args' => $args );
			return 1731321600 === $clock ? false : self::ok_response( 4 ); // 窗口期超时；过期后成功。
		};
		$remote = new RemoteClient(
			$transport,
			function ( string $key ) use ( &$usage ): void { $usage++; },
			static function ( int $ms ): void { unset( $ms ); }
		);

		$service = new AbuseIpdbService(
			self::settings(),
			$remote,
			static function ( string $key ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$store, &$cache_puts ): void {
				$store[ $key ] = $value;
				$cache_puts[] = array( 'key' => $key, 'ttl' => $ttl );
			},
			static function ( string $key ) use ( &$store, &$clock ) {
				// transient TTL 语义：until 过去即视为过期（get_transient 返回 null 同形）。
				return ( array_key_exists( $key, $store ) && $store[ $key ] > $clock ) ? 1 : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$store, &$backoff_puts, &$clock ): void {
				$store[ $key ] = $clock + $ttl; // 服务写值=until 时间戳。
				$backoff_puts[] = array( 'key' => $key, 'ttl' => $ttl );
			},
			static function ( string $driver_id ): ?string { unset( $driver_id ); return null; },
			static function ( string $driver_id, string $state ) use ( &$health_marks ): void {
				$health_marks[] = array( 'driver' => $driver_id, 'state' => $state );
			},
			static function (): array { return array( 'date' => '2026-11-12', 'counters' => array() ); },
			static function ( string $data ): string {
				return substr( hash_hmac( 'sha256', $data, self::HMAC_salt ), 0, 16 );
			},
			null,
			null,
			null,
			static function () use ( &$clock ): int { return $clock; }
		);

		// ① 窗口期：外呼超时 → 退避落位（300s）→ 后续调用全 null 零外呼。
		$this->assertSame( Verdict::ERROR, $service->probe( self::IP )->verdict() );
		$this->assertCount( 1, $backoff_puts );
		$this->assertSame( 300, $backoff_puts[0]['ttl'] );
		$calls_in_window = count( $calls );
		$this->assertNull( $service->probe( self::IP ), '窗口内全 null' );
		$this->assertSame( $calls_in_window, count( $calls ), '窗口内零外呼' );

		// ② 过期（clock +301s > until）：恢复外呼 → 成功 → pass verdict + 缓存回填。
		$clock = 1731321600 + 301;
		$verdict = $service->probe( self::IP );
		$this->assertSame( Verdict::PASS, $verdict->verdict(), '退避过期恢复外呼' );
		$this->assertGreaterThan( $calls_in_window, count( $calls ), '过期后外呼恢复' );
		$this->assertCount( 1, $cache_puts, '成功后缓存回填（只在外呼成功后）' );
	}

	public function test_halted_state_skips_outbound(): void {
		// DriverHealth 当日停转（config_error/quota_exhausted）→ skip 零外呼。
		$env = $this->quick( self::ok_response( 0 ), null, array( 'health_state' => 'quota_exhausted' ) );
		$this->assertNull( $env['service']->probe( self::IP ), '停转态 skip（停转语义在服务内部）' );
		$this->assertCount( 0, $env['calls'] );

		$env2 = $this->quick( self::ok_response( 0 ), null, array( 'health_state' => 'config_error' ) );
		$this->assertNull( $env2['service']->probe( self::IP ) );
		$this->assertCount( 0, $env2['calls'] );
	}

	/* -------------------------------------------------------------------
	 * §2【O5 回写·P1-1】cache_only 只读模式
	 * ------------------------------------------------------------------ */

	public function test_cache_only_hit_returns_verdict_zero_outbound(): void {
		$env = $this->quick( self::ok_response( 3 ) );
		$env['cache'][ $this->cache_key() ] = array( 'v' => 'pass', 's' => 3 );
		$verdict = $env['service']->probe( self::IP, array( 'cache_only' => true ) );

		$this->assertSame( Verdict::PASS, $verdict->verdict(), 'cache_only 命中返回缓存 verdict' );
		$this->assertSame( array( 'cached' => true, 'score' => 3 ), $verdict->event_meta() );
		$this->assertCount( 0, $env['calls'], 'cache_only 全程零外呼（计数器断言）' );
		$this->assertSame( 0, $env['usage'], 'cache_only 零计量' );
	}

	public function test_cache_only_miss_returns_fail_not_null(): void {
		$env = $this->quick( self::ok_response( 3 ) );
		$verdict = $env['service']->probe( self::IP, array( 'cache_only' => true ) );

		$this->assertNotNull( $verdict, '未命中不返回 null（A 方案修正版——无证据即 fail）' );
		$this->assertSame( Verdict::REJECT, $verdict->verdict() );
		$this->assertSame( 'cache_miss_strict', $verdict->reason() );
		$this->assertCount( 0, $env['calls'], 'cache_only 零外呼' );
		$this->assertSame( 0, $env['usage'], 'cache_only 零计量（软顶不评估——无外呼即无配额消耗）' );
		$this->assertSame( array(), $env['cache_puts'], 'cache_only 零写' );
	}

	public function test_cache_only_backoff_returns_fail_backoff(): void {
		$env = $this->quick( self::ok_response( 3 ) );
		$env['cache'][ 'wk_irb_abuseipdb' ] = 1;
		$verdict = $env['service']->probe( self::IP, array( 'cache_only' => true ) );
		$this->assertSame( Verdict::REJECT, $verdict->verdict() );
		$this->assertSame( 'backoff', $verdict->reason(), '退避中 → fail（reason=backoff）' );
		$this->assertCount( 0, $env['calls'] );
	}

	public function test_cache_only_halted_returns_fail_halted(): void {
		$env = $this->quick( self::ok_response( 3 ), null, array( 'health_state' => 'config_error' ) );
		$verdict = $env['service']->probe( self::IP, array( 'cache_only' => true ) );
		$this->assertSame( Verdict::REJECT, $verdict->verdict() );
		$this->assertSame( 'halted', $verdict->reason(), '停转中 → fail（reason=halted）' );
		$this->assertCount( 0, $env['calls'] );
	}

	public function test_cache_only_cache_hit_wins_over_backoff_and_halt(): void {
		// 命中返回该 verdict（退避/停转中缓存证据仍有效——定稿 §2【O5 回写·P1-1】「命中返回该 verdict」）。
		$env = $this->quick( self::ok_response( 3 ), null, array( 'health_state' => 'quota_exhausted' ) );
		$env['cache'][ 'wk_irb_abuseipdb' ] = 1;
		$env['cache'][ $this->cache_key() ] = array( 'v' => 'reject', 's' => 90 );
		$verdict = $env['service']->probe( self::IP, array( 'cache_only' => true ) );
		$this->assertSame( Verdict::REJECT, $verdict->verdict() );
		$this->assertSame( 'score 90 (cached)', $verdict->reason(), '缓存命中优先于退避/停转检查' );
		$this->assertCount( 0, $env['calls'] );
	}

	public function test_cache_only_corrupt_cache_reads_fail(): void {
		$env = $this->quick( self::ok_response( 3 ) );
		$env['cache'][ $this->cache_key() ] = 'garbage-string';
		$verdict = $env['service']->probe( self::IP, array( 'cache_only' => true ) );
		$this->assertSame( Verdict::REJECT, $verdict->verdict(), '损坏值 fail（从严）' );
		$this->assertSame( 'corrupt_cache', $verdict->reason() );
		$this->assertCount( 0, $env['calls'] );
	}

	/* -------------------------------------------------------------------
	 * §7 步 0 白名单（行为层：永不查、永不 fail）
	 * ------------------------------------------------------------------ */

	public function test_whitelist_admin_and_self_ip_skip_to_null(): void {
		// 站长白名单 ∨ manage_options ∨ 服务器自身 IP → null skip（含防呆+cache_only 双模式验证）。
		$env = $this->quick( self::ok_response( 0 ), null, array( 'whitelist' => static function ( string $ip ): bool { return '198.51.100.1' === $ip; } ) );
		$this->assertNull( $env['service']->probe( '198.51.100.1' ), '站长手动白名单 → null' );
		$this->assertCount( 0, $env['calls'] );

		$env2 = $this->quick( self::ok_response( 0 ), null, array( 'is_admin' => static function (): bool { return true; } ) );
		$this->assertNull( $env2['service']->probe( self::IP ), '已登录 manage_options → null（行为层永不查永不 fail）' );
		$this->assertCount( 0, $env2['calls'] );

		$env3 = $this->quick( self::ok_response( 0 ), null, array( 'server_ips' => array( '10.0.0.2' ) ) );
		$this->assertNull( $env3['service']->probe( '10.0.0.2' ), '服务器自身 IP → null' );
		$this->assertCount( 0, $env3['calls'] );

		// 白名单在 cache_only 下同样 skip（步 0 照旧）。
		$env4 = $this->quick( self::ok_response( 0 ), null, array( 'is_admin' => static function (): bool { return true; } ) );
		$this->assertNull( $env4['service']->probe( self::IP, array( 'cache_only' => true ) ), 'cache_only 白名单步 0 照旧 null' );
	}

	/* -------------------------------------------------------------------
	 * §2 assemble 防呆（从严例外之二）
	 * ------------------------------------------------------------------ */

	public function test_misconfigured_enabled_without_key_fails_strict(): void {
		// enabled && api_key 空 → fail 语义防呆（恒 fail 不静默 skip）；白名单仍前置豁免。
		$env = $this->quick( self::ok_response( 0 ), self::settings( array( 'api_key' => '' ) ) );
		$verdict = $env['service']->probe( self::IP );
		$this->assertSame( Verdict::REJECT, $verdict->verdict(), '防呆：开而不配好=fail（可见、可自愈）' );
		$this->assertSame( 'not_configured', $verdict->reason() );
		$this->assertCount( 0, $env['calls'], '未配钥零外呼（无 Key 可带）' );

		// cache_only 下防呆同样 fail。
		$verdict2 = $env['service']->probe( self::IP, array( 'cache_only' => true ) );
		$this->assertSame( Verdict::REJECT, $verdict2->verdict() );
		$this->assertSame( 'not_configured', $verdict2->reason() );
	}

	public function test_invalid_ip_normalizes_to_skip(): void {
		// 归一化失败（非 IP 形态）→ null skip（宁不判定不误伤，与计数器同口径）。
		$env = $this->quick( self::ok_response( 0 ) );
		$this->assertNull( $env['service']->probe( 'not-an-ip' ) );
		$this->assertNull( $env['service']->probe( '' ) );
		$this->assertCount( 0, $env['calls'] );
	}

	/* -------------------------------------------------------------------
	 * 本地真钥冒烟夹具钉映射（I2 三角验证之②——fixtures-i2/ 真实响应 JSON）
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $name 夹具文件名。
	 * @return array<string, mixed>
	 */
	private static function fixture( string $name ): array {
		$raw = (string) file_get_contents( dirname( __DIR__ ) . '/tests/fixtures-i2/' . $name );
		$decoded = json_decode( $raw, true );
		self::assertTrue( is_array( $decoded ), '夹具 JSON 可解析：' . $name );
		return $decoded;
	}

	public function test_real_smoke_fixture_clean_ip_maps_pass(): void {
		// 本地真钥冒烟实录（2026-11-12，.env 真钥查 8.8.8.8）：HTTP 200 score=0 isPublic=true
		// → pass（真实响应形态钉映射——非手造夹具）。
		$payload = self::fixture( 'abuseipdb-check-8888.json' );
		$response = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( $payload ),
			'headers'  => array(),
		);
		$env = $this->quick( $response );
		$verdict = $env['service']->probe( '8.8.8.8' );
		$this->assertSame( Verdict::PASS, $verdict->verdict(), '真实夹具（8.8.8.8 score 0）→ pass' );
		$this->assertSame( array( 'score' => 0 ), $verdict->event_meta(), '真实 score 整数直存 meta' );
	}

	public function test_real_smoke_fixture_reserved_ip_maps_skip(): void {
		// 本地真钥冒烟实录：127.0.0.2 → score=0 / isPublic=false / usageType=Reserved
		// → skip（真实响应形态钉 isPublic=false 分支——不缓存不外呼重试）。
		$payload = self::fixture( 'abuseipdb-check-127002.json' );
		$response = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( $payload ),
			'headers'  => array(),
		);
		$env = $this->quick( $response );
		$this->assertNull( $env['service']->probe( '127.0.0.2' ), '真实夹具（Reserved isPublic=false）→ skip' );
		$this->assertSame( array(), $env['cache_puts'], 'skip 不缓存' );
	}

	/* -------------------------------------------------------------------
	 * registry 条目（05 §3.1/§4——type='ip'/billing='free_quota'/supports_report=true）
	 * ------------------------------------------------------------------ */

	public function test_registry_entry_validates_and_registers(): void {
		$registry = new \WinkGatekeeper\Drivers\DriverRegistry();
		$registry->register( AbuseIpdbService::registry_entry() );

		$entry = $registry->get( 'abuseipdb' );
		$this->assertNotNull( $entry );
		$this->assertSame( 'ip', $entry['type'] );
		$this->assertSame( 'free_quota', $entry['billing'], 'O5-P3-3 三字段占位：billing=free_quota' );
		$this->assertSame( array(), $entry['frontend'], 'IP 驱动无前端资产（LocalRules 先例）' );
		$this->assertTrue( $entry['supports_report'], 'Tab④ 上报分区显隐依据（I3 消费）' );
		$this->assertSame( array( 'abuseipdb' ), array_keys( $registry->by_type( 'ip' ) ), 'by_type(ip) 非空——Tab①⑥ 名册卡/折减自动生效' );
	}
}
