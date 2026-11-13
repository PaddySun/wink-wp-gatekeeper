<?php
/**
 * AbuseIpdbService report 路径单元测试（M3-IP 定稿 §4 全节 + §7 判定层白名单 + §9 usage_spike/
 * last_report_at + §10-3 测试清单——I3 批）。
 *
 * 覆盖（定稿 §4.1-4.5 / §10-3）：
 *   - 接口面：SupportsReportingInterface 实现与签名（report_ip(ip, context): bool）；
 *   - 触发集：T1（无阈值直触发）/T2/T4（wk_rp_cnt_ 24h 窗口达 N 才触发）/T3（登录饱和点由
 *     LoginGate 测试钉——本文件钉服务侧 login_attempts 无阈值直通）；触发面关=静默；
 *   - 四道闸：① 总开关关全静默 ② 每 IP 冷却 60min（冷却命中视为成功免重报返回 true；
 *     发送失败冷却照落——丢报无损）③ 全局 wk_rp_global 60/h（TransientRateLimiter 参数化）+
 *     daily_cap 500/日 ④ 真实 IP 自检未过强制失效（无开关）；
 *   - 负载断言：ip+categories（至少一个恒成立）+comment 硬前缀恒在/默认短语逐面/自定义覆盖/
 *     占位符白名单渲染（未知占位符原样保留）/255 截断/类目覆盖与非法回落；负载绝不含正文
 *     片段与命中词（context 最小面自证——body 键全集断言）；
 *   - 分桶计量：report=abuseipdb_report 独立键、日顶尽停 report 不停 check（双向）；
 *   - 突增告警：10min 原始触发>20（四道闸之前计数——含被冷却丢弃者）→ 第 21 触发
 *     usage_spike 事件一次（同窗去重）；
 *   - 白名单前置：白名单/管理员/自身 IP 永不报不计数（§7 判定层与 check 步 0 同一判定）；
 *   - 发送失败静默：false + 事件 verdict=error meta 分类（transport/429/401）；成功事件
 *     verdict=pass hits=report_sent meta.score；last_report_at 落 usage option（§9）。
 *
 * 传输脊/缓存/计数/停转/用量/时钟/白名单/自检/事件记录全注入——纯逻辑零 WP 依赖。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Ip\AbuseIpdbCategories;
use WinkGatekeeper\Ip\AbuseIpdbService;
use WinkGatekeeper\Ip\SupportsReportingInterface;
use WinkGatekeeper\Verdict;

final class AbuseIpdbReportTest extends TestCase {

	private const IP       = '203.0.113.7';
	private const HMAC_SALT = 'i3-report-test';
	private const NOW      = 1731417600; // 2026-11-12 16:00 UTC（固定时钟）。

	/* -------------------------------------------------------------------
	 * 接口面与类目常量（§4.1/§4.5）
	 * ------------------------------------------------------------------ */

	public function test_interface_contract(): void {
		$method = new \ReflectionMethod( SupportsReportingInterface::class, 'report_ip' );
		$this->assertSame( [ 'string', 'array' ], array_map(
			static fn( \ReflectionParameter $p ): string => (string) $p->getType(),
			$method->getParameters()
		) );
		$this->assertTrue( $method->hasReturnType() && 'bool' === (string) $method->getReturnType() );
		$env  = $this->quick( $this->ok_response() );
		$this->assertInstanceOf( SupportsReportingInterface::class, $env['service'] );
	}

	public function test_categories_table_complete_1_to_23(): void {
		for ( $id = 1; $id <= 23; $id++ ) {
			$this->assertTrue( AbuseIpdbCategories::is_valid( $id ), "类目 {$id} 必须在官方码表内" );
			$this->assertNotSame( '', AbuseIpdbCategories::label( $id ), "类目 {$id} 必须有人话名" );
		}
		$this->assertFalse( AbuseIpdbCategories::is_valid( 0 ) );
		$this->assertFalse( AbuseIpdbCategories::is_valid( 24 ) );
		$this->assertSame( 18, AbuseIpdbCategories::BRUTE_FORCE );
		$this->assertSame( 10, AbuseIpdbCategories::WEB_SPAM );
	}

	public function test_default_category_per_trigger(): void {
		$this->assertSame( 18, AbuseIpdbCategories::default_for( 'captcha_failed' ), 'T1 默认 18 Brute-Force' );
		$this->assertSame( 10, AbuseIpdbCategories::default_for( 'review_reject' ), 'T2 默认 10 Web Spam' );
		$this->assertSame( 18, AbuseIpdbCategories::default_for( 'login_attempts' ), 'T3 默认 18 Brute-Force' );
		$this->assertSame( 10, AbuseIpdbCategories::default_for( 'local_rules_fail' ), 'T4 默认 10 Web Spam' );
	}

	/* -------------------------------------------------------------------
	 * T1 触发与负载契约（§4.1/§4.5）
	 * ------------------------------------------------------------------ */

	public function test_t1_send_builds_payload_with_prefix_and_default_phrase(): void {
		$env = $this->quick( $this->ok_response() );
		$ok  = $env['service']->report_ip( self::IP, array(
			'trigger'  => 'captcha_failed',
			'gate'     => 'comment',
			'event_no' => 'WK-20261112-0001',
		) );
		$this->assertTrue( $ok, '发送成功=true' );

		$this->assertCount( 1, $env['calls'], 'report 模式一次即走' );
		$call = $env['calls'][0];
		$this->assertSame( 'https://api.abuseipdb.com/api/v2/report', $call['url'] );
		$this->assertSame( 3, $call['args']['timeout'], 'report 模式 3s 短超时（P0-10·F1 上调——幽灵到达实证）' );
		$this->assertSame( 'test-key-123', $call['args']['headers']['Key'] );
		$this->assertSame( 'application/json', $call['args']['headers']['Accept'] );

		$body = $call['args']['body'];
		$this->assertSame( self::IP, $body['ip'] );
		$this->assertSame( '18', (string) $body['categories'], 'T1 默认类目 18（恒有一个 ID）' );
		$this->assertSame(
			'[wink-wp-gatekeeper] CAPTCHA verification failed (forged or replayed ticket)',
			$body['comment'],
			'硬前缀+T1 默认短语'
		);
		$this->assertStringNotContainsString( 'WK-20261112', $body['comment'], '默认短语不夹带事件编号' );

		// 计量：usage_key=abuseipdb_report（分桶独立——§4.3）。
		$this->assertSame( array( 'abuseipdb_report' ), $env['usage_keys'] );
	}

	public function test_default_phrases_per_trigger(): void {
		$expected = array(
			'captcha_failed'    => '[wink-wp-gatekeeper] CAPTCHA verification failed (forged or replayed ticket)',
			'review_reject'     => '[wink-wp-gatekeeper] Repeated spam content submissions blocked',
			'login_attempts'    => '[wink-wp-gatekeeper] Credential brute-force on WordPress login, blocked',
			'local_rules_fail'  => '[wink-wp-gatekeeper] Repeated spam/blocked content submissions',
		);
		foreach ( $expected as $trigger => $comment ) {
			$env      = $this->quick( $this->ok_response() );
			$attempts = in_array( $trigger, array( 'review_reject', 'local_rules_fail' ), true ) ? 5 : 1; // T2/T4 达 N=5。
			for ( $i = 0; $i < $attempts; $i++ ) {
				$env['service']->report_ip( self::IP, array( 'trigger' => $trigger, 'gate' => 'comment' ) );
			}
			$this->assertCount( 1, $env['calls'], "触发面 {$trigger} 达阈值发送一次" );
			$this->assertSame( $comment, $env['calls'][0]['args']['body']['comment'], "触发面 {$trigger} 默认短语" );
		}
	}

	public function test_custom_comment_override_and_placeholder_whitelist(): void {
		$env = $this->quick( $this->ok_response(), self::settings( array(
			'comment_captcha_failed' => 'Gate {gate} hit #{event_no} cat {category} keep {unknown_stay}',
		) ) );
		$env['service']->report_ip( self::IP, array(
			'trigger'  => 'captcha_failed',
			'gate'     => 'comment',
			'event_no' => 'WK-20261112-0007',
		) );
		$this->assertSame(
			'[wink-wp-gatekeeper] Gate comment hit #WK-20261112-0007 cat 18 keep {unknown_stay}',
			$env['calls'][0]['args']['body']['comment'],
			'白名单三占位符渲染；白名单外 {unknown_stay} 原样保留不解析'
		);
	}

	public function test_custom_comment_truncated_to_255_with_prefix(): void {
		$long = str_repeat( 'x', 300 );
		$env  = $this->quick( $this->ok_response(), self::settings( array(
			'comment_captcha_failed' => $long,
		) ) );
		$env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed', 'gate' => 'comment' ) );
		$comment = $env['calls'][0]['args']['body']['comment'];
		$this->assertLessThanOrEqual( 255, strlen( $comment ), '渲染后 255 截断' );
		$this->assertSame( '[wink-wp-gatekeeper] ', substr( $comment, 0, 21 ), '截断后硬前缀仍恒在' );
	}

	public function test_category_override_and_invalid_falls_back_to_default(): void {
		$env = $this->quick( $this->ok_response(), self::settings( array(
			'category_captcha_failed' => 12,
		) ) );
		$env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed', 'gate' => 'comment' ) );
		$this->assertSame( '12', (string) $env['calls'][0]['args']['body']['categories'], '每面类目覆盖键生效' );

		$env2 = $this->quick( $this->ok_response(), self::settings( array(
			'category_captcha_failed' => 99, // 非法码（1–23 之外）→ 回落默认 18。
		) ) );
		$env2['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed', 'gate' => 'comment' ) );
		$this->assertSame( '18', (string) $env2['calls'][0]['args']['body']['categories'], '非法类目回落默认（至少一个恒成立保底）' );
	}

	public function test_payload_never_carries_content_fragments_or_rule_terms(): void {
		// 红线断言：负载键全集=ip/categories/comment——绝无正文/命中词/词表/域名字段。
		$env = $this->quick( $this->ok_response() );
		$env['service']->report_ip( self::IP, array(
			'trigger'  => 'captcha_failed',
			'gate'     => 'comment',
			'event_no' => 'WK-20261112-0009',
			'content'  => '垃圾评论正文片段', // 触发面恶意夹带——服务必须忽略。
			'notes'    => array( 'banned_word', 'no_chinese' ),
		) );
		$body = $env['calls'][0]['args']['body'];
		$this->assertSame( array( 'ip', 'categories', 'comment' ), array_keys( $body ), '负载键白名单' );
		$json = (string) json_encode( $body, JSON_UNESCAPED_UNICODE );
		$this->assertStringNotContainsString( '垃圾评论正文片段', $json );
		$this->assertStringNotContainsString( 'no_chinese', $json );
		$this->assertStringNotContainsString( 'banned_word', $json );
	}

	public function test_unresolvable_ip_is_silent_false(): void {
		$env = $this->quick( $this->ok_response() );
		$this->assertFalse( $env['service']->report_ip( 'not-an-ip', array( 'trigger' => 'captcha_failed' ) ) );
		$this->assertCount( 0, $env['calls'] );
	}

	/* -------------------------------------------------------------------
	 * 四道闸（§4.3）
	 * ------------------------------------------------------------------ */

	public function test_gate1_total_switch_off_silent(): void {
		$env = $this->quick( $this->ok_response(), self::settings( array(), array( 'report' => array( 'enabled' => false ) ) ) );
		$this->assertFalse( $env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) ) );
		$this->assertCount( 0, $env['calls'], '总开关关=零外呼' );
		$this->assertCount( 0, $env['events'], '零事件' );
		$this->assertArrayNotHasKey( 'wk_rp_spike', $env['cache'], '总开关关=突增计数不启动' );
	}

	public function test_gate1_missing_api_key_silent(): void {
		$env = $this->quick( $this->ok_response(), self::settings( array(), array( 'abuseipdb' => array( 'api_key' => '' ) ) ) );
		$this->assertFalse( $env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) ) );
		$this->assertCount( 0, $env['calls'] );
	}

	public function test_trigger_face_off_silent(): void {
		$env = $this->quick( $this->ok_response(), self::settings( array(), array(
			'report' => array( 'enabled' => true, 'triggers' => array( 'captcha_failed' => false ) ),
		) ) );
		$this->assertFalse( $env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) ) );
		$this->assertCount( 0, $env['calls'], '触发面关=该面静默' );
		$this->assertArrayNotHasKey( 'wk_rp_spike', $env['cache'], '触发面关=不进突增计数' );
	}

	public function test_gate4_real_ip_selfcheck_fail_forces_off(): void {
		$env = $this->quick( $this->ok_response(), null, array( 'real_ip_ok' => false ) );
		$this->assertFalse( $env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) ) );
		$this->assertCount( 0, $env['calls'], '自检未过=Docker NAT 共显类→上报强制失效（无开关）' );
		$this->assertArrayNotHasKey( 'wk_rp_spike', $env['cache'] );
	}

	public function test_gate2_cooldown_dedupes_and_returns_true(): void {
		$env = $this->quick( $this->ok_response() );
		$this->assertTrue( $env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) ) );
		$this->assertTrue( $env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) ), '冷却命中视为成功免重报' );
		$this->assertCount( 1, $env['calls'], '60min 冷却内第二次触发零外呼' );

		$cool_key = 'wk_rp_cool_' . $this->hmac16( self::IP );
		$this->assertArrayHasKey( $cool_key, $env['cache'], 'wk_rp_cool_ 落位' );
		$this->assertSame( self::NOW + 3600, $env['cache'][ $cool_key ], '冷却值=到期时间戳（60min）' );
	}

	public function test_gate2_cooldown_set_even_on_failed_send_loss_is_harmless(): void {
		$env = $this->quick( false ); // transport 失败（真机无出口形态）。
		$this->assertFalse( $env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) ) );
		$cool_key = 'wk_rp_cool_' . $this->hmac16( self::IP );
		$this->assertArrayHasKey( $cool_key, $env['cache'], '发送失败冷却照落——冷却后下触发点自然重试' );
		$this->assertTrue( $env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) ), '失败后冷却命中=true 免重报' );
		$this->assertCount( 1, $env['calls'], '丢报无损：冷却窗内不重复外呼' );
	}

	public function test_gate3_global_hourly_bucket_61st_rejected(): void {
		$env       = $this->quick( $this->ok_response() );
		$global_key = 'wk_rp_global' . $this->hmac16( 'global' );
		// 桶已满 60/h（reset 取远期——TransientRateLimiter 读真实时钟，种子只需未过期）。
		$env['cache'][ $global_key ] = array( 'reset' => PHP_INT_MAX, 'count' => 60 );
		$this->assertFalse( $env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) ) );
		$this->assertCount( 0, $env['calls'], '全局小时桶 61 次拒' );
	}

	public function test_gate3_daily_cap_500_stops_report(): void {
		$env = $this->quick( $this->ok_response(), null, array( 'report_used_today' => 500 ) );
		$this->assertFalse( $env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) ) );
		$this->assertCount( 0, $env['calls'], 'report 日顶 500/日——分桶独立' );
	}

	public function test_bucket_independence_report_full_does_not_stop_check(): void {
		// report 配额尽（abuseipdb_report=500 ≥ daily_cap）→ report 停；check 池（abuseipdb）
		// 未触软顶 → probe 照常外呼——两桶互不挤兑（§4.3/04 §6）。
		$env = $this->quick( $this->ok_response(), null, array( 'report_used_today' => 500 ) );
		$this->assertFalse( $env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) ) );

		$check_calls_before = count( $env['calls'] );
		$verdict = $env['service']->probe( self::IP );
		$this->assertNotNull( $verdict );
		$this->assertGreaterThan( $check_calls_before, count( $env['calls'] ), 'report 满不停 check' );
	}

	public function test_bucket_independence_check_soft_quota_does_not_stop_report(): void {
		// check 软顶（abuseipdb=3000 ≥ soft_quota）→ probe fail 不外呼；report 面照常发送。
		$env = $this->quick( $this->ok_response(), null, array( 'check_used_today' => 3000 ) );
		$verdict = $env['service']->probe( self::IP );
		$this->assertSame( Verdict::REJECT, $verdict->verdict(), '软顶 fail' );
		$check_calls = count( $env['calls'] );

		$this->assertTrue( $env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) ), 'check 软顶不停 report' );
		$this->assertGreaterThan( $check_calls, count( $env['calls'] ) );
	}

	/* -------------------------------------------------------------------
	 * 触发面阈值（T2/T4 计数键 wk_rp_cnt_ 24h 窗口）
	 * ------------------------------------------------------------------ */

	public function test_t2_threshold_not_reached_no_send_then_send_at_n(): void {
		$env = $this->quick( $this->ok_response() );
		$cnt_key = 'wk_rp_cnt_review_reject_' . $this->hmac16( self::IP );

		for ( $i = 1; $i <= 4; $i++ ) {
			$this->assertFalse( $env['service']->report_ip( self::IP, array( 'trigger' => 'review_reject' ) ), "第 {$i} 次（<5）不触发" );
		}
		$this->assertArrayHasKey( $cnt_key, $env['cache'], '计数键 wk_rp_cnt_ 落位' );
		$this->assertSame( 4, $env['cache'][ $cnt_key ] );
		$this->assertCount( 0, $env['calls'] );

		$this->assertTrue( $env['service']->report_ip( self::IP, array( 'trigger' => 'review_reject' ) ), '第 5 次达 N 触发' );
		$this->assertCount( 1, $env['calls'] );
		$this->assertSame( '10', (string) $env['calls'][0]['args']['body']['categories'], 'T2 默认类目 10' );
		$this->assertSame( '[wink-wp-gatekeeper] Repeated spam content submissions blocked', $env['calls'][0]['args']['body']['comment'] );
	}

	public function test_t4_threshold_window_counts_and_trigger(): void {
		$env = $this->quick( $this->ok_response() );
		$cnt_key = 'wk_rp_cnt_local_rules_fail_' . $this->hmac16( self::IP );

		for ( $i = 0; $i < 5; $i++ ) {
			$env['service']->report_ip( self::IP, array( 'trigger' => 'local_rules_fail' ) );
		}
		$this->assertSame( 5, $env['cache'][ $cnt_key ], '24h 窗口累计' );
		$this->assertCount( 1, $env['calls'], '达 N=5 触发一次' );
		$this->assertSame( '10', (string) $env['calls'][0]['args']['body']['categories'] );
		$this->assertSame( '[wink-wp-gatekeeper] Repeated spam/blocked content submissions', $env['calls'][0]['args']['body']['comment'] );

		// 阈值后再触发：冷却去重（不再外呼），计数继续累计。
		$env['service']->report_ip( self::IP, array( 'trigger' => 'local_rules_fail' ) );
		$this->assertSame( 6, $env['cache'][ $cnt_key ] );
		$this->assertCount( 1, $env['calls'] );
	}

	public function test_t1_t3_are_threshold_free_direct_triggers_without_cnt_key(): void {
		// T1/T3 无阈值（T3 沿用 LoginCounters 10 不单设）——直进四道闸；无 wk_rp_cnt_ 键。
		foreach ( array( 'captcha_failed', 'login_attempts' ) as $trigger ) {
			$env = $this->quick( $this->ok_response() );
			$this->assertTrue( $env['service']->report_ip( self::IP, array( 'trigger' => $trigger ) ), "{$trigger} 直触发" );
			$this->assertCount( 1, $env['calls'] );
			$cnt_prefix = 'wk_rp_cnt_' . $trigger . '_';
			foreach ( array_keys( $env['cache'] ) as $key ) {
				$this->assertStringStartsNotWith( $cnt_prefix, $key, "{$trigger} 零新增计数键" );
			}
		}
	}

	public function test_t3_default_category_18_brute_force(): void {
		$env = $this->quick( $this->ok_response() );
		$env['service']->report_ip( self::IP, array( 'trigger' => 'login_attempts', 'gate' => 'login' ) );
		$this->assertSame( '18', (string) $env['calls'][0]['args']['body']['categories'] );
		$this->assertSame( '[wink-wp-gatekeeper] Credential brute-force on WordPress login, blocked', $env['calls'][0]['args']['body']['comment'] );
	}

	public function test_counter_keys_normalize_ip_variants(): void {
		// ::ffff: 变体不稀释计数/冷却键（与 check 缓存同口径——IpResolver::normalize）。
		$env = $this->quick( $this->ok_response() );
		$env['service']->report_ip( '::ffff:' . self::IP, array( 'trigger' => 'captcha_failed' ) );
		$cool_key = 'wk_rp_cool_' . $this->hmac16( self::IP );
		$this->assertArrayHasKey( $cool_key, $env['cache'], '变体归一到规范形定键' );
		$this->assertTrue( $env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) ), '规范形命中同一冷却键' );
		$this->assertCount( 1, $env['calls'] );
	}

	/* -------------------------------------------------------------------
	 * 白名单前置（§7 判定层——与 check 步 0 同一判定函数）
	 * ------------------------------------------------------------------ */

	public function test_whitelisted_ip_never_reports_and_never_counts(): void {
		$env = $this->quick( $this->ok_response(), null, array( 'whitelist' => static function ( string $ip ): bool {
			return '203.0.113.7' === $ip;
		} ) );
		$this->assertFalse( $env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) ) );
		$this->assertCount( 0, $env['calls'], '白名单永不报' );
		$this->assertArrayNotHasKey( 'wk_rp_spike', $env['cache'], '白名单不计数（含突增原始计数）' );
		$this->assertArrayNotHasKey( 'wk_rp_cool_' . $this->hmac16( self::IP ), $env['cache'], '零冷却落位' );
		$this->assertCount( 0, $env['events'], '零事件' );
	}

	public function test_admin_user_never_reports(): void {
		$env = $this->quick( $this->ok_response(), null, array( 'is_admin' => static function (): bool {
			return true;
		} ) );
		$this->assertFalse( $env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) ) );
		$this->assertCount( 0, $env['calls'] );
	}

	/* -------------------------------------------------------------------
	 * 突增告警（§4.5/§9 usage_spike——计数点=四道闸之前）
	 * ------------------------------------------------------------------ */

	public function test_spike_below_threshold_no_event(): void {
		$env = $this->quick( $this->ok_response() );
		for ( $i = 0; $i < 20; $i++ ) {
			$env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) );
		}
		$this->assertCount( 0, $this->spike_events( $env['events'] ), '20 阈值（含）前不出 usage_spike 事件' );
	}

	public function test_spike_21st_trigger_records_usage_spike_once(): void {
		$env = $this->quick( $this->ok_response() );
		for ( $i = 0; $i < 21; $i++ ) {
			$env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) );
		}
		$spikes = $this->spike_events( $env['events'] );
		$this->assertCount( 1, $spikes, '第 21 触发出 usage_spike 事件（同窗去重一次）' );
		$spike = $spikes[0];
		$this->assertSame( 'system', $spike['gate'], '站点级告警 gate=system' );
		$this->assertSame( 'ip', $spike['stage'], 'stage=ip（§9——usage_spike 事件键走 rule_hit 列）' );
		$this->assertSame( Verdict::REVIEW, $spike['verdict'] );
		$this->assertStringContainsString( 'usage_spike', $spike['rule_hit'] );

		// 第 22 触发：同窗不重复告警。
		$env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) );
		$this->assertCount( 1, $this->spike_events( $env['events'] ), '同 10min 窗去重' );
	}

	public function test_spike_counts_pre_gate_discards(): void {
		// 冷却持有中（发送全被闸②丢弃）——原始触发仍计突增（O5-P3-1：感知攻击面非发送量）。
		$env = $this->quick( $this->ok_response() );
		$env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) ); // 首发+落冷却。
		$sent = count( $env['calls'] );
		for ( $i = 0; $i < 25; $i++ ) {
			$env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) ); // 冷却丢弃。
		}
		$this->assertSame( $sent, count( $env['calls'] ), '冷却窗内零新增外呼' );
		$this->assertCount( 1, $this->spike_events( $env['events'] ), '被冷却丢弃的原始触发照计——26 次跨 20 阈值告警' );
	}

	/**
	 * @param array<int, array<string, mixed>> $events
	 * @return array<int, array<string, mixed>>
	 */
	private function spike_events( array $events ): array {
		return array_values( array_filter( $events, static fn( array $e ): bool => false !== strpos( (string) $e['rule_hit'], 'usage_spike' ) ) );
	}

	/* -------------------------------------------------------------------
	 * 发送失败静默与落账（§4.1/§9）
	 * ------------------------------------------------------------------ */

	public function test_send_failure_transport_silent_false_with_error_event(): void {
		$env = $this->quick( false ); // 传输层超时/网络错（真机无国际出口形态）。
		$this->assertFalse( $env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed', 'gate' => 'comment' ) ) );
		$this->assertCount( 1, $env['calls'], 'report 模式零重试' );
		$this->assertCount( 1, $env['events'], '失败静默但落事件' );
		$event = $env['events'][0];
		$this->assertSame( 'comment', $event['gate'] );
		$this->assertSame( 'report', $event['stage'] );
		$this->assertSame( Verdict::ERROR, $event['verdict'] );
		$this->assertStringContainsString( 'remote_transport', $event['rule_hit'] );
		$this->assertArrayHasKey( 'raw', $event['meta'], 'meta.raw 快照' );
		$this->assertStringNotContainsString( 'test-key-123', json_encode( $event['meta'] ), '密钥零泄漏' );
	}

	public function test_send_failure_429_and_401_classified(): void {
		$env429 = $this->quick( array( 'response' => array( 'code' => 429 ), 'body' => 'rate limited', 'headers' => array() ) );
		$this->assertFalse( $env429['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) ) );
		$this->assertSame( Verdict::ERROR, $env429['events'][0]['verdict'] );
		$this->assertStringContainsString( 'remote_transport', $env429['events'][0]['rule_hit'] );

		$env401 = $this->quick( array( 'response' => array( 'code' => 401 ), 'body' => '{"data":{}}', 'headers' => array() ) );
		$this->assertFalse( $env401['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) ) );
		$this->assertSame( Verdict::ERROR, $env401['events'][0]['verdict'] );
		$this->assertStringContainsString( 'remote_semantic', $env401['events'][0]['rule_hit'] );
	}

	public function test_send_success_event_with_score_and_last_report_at(): void {
		$env = $this->quick( $this->ok_response() );
		$this->assertTrue( $env['service']->report_ip( self::IP, array(
			'trigger'  => 'captcha_failed',
			'gate'     => 'comment',
			'event_no' => 'WK-20261112-0042',
		) ) );
		$this->assertCount( 1, $env['events'] );
		$event = $env['events'][0];
		$this->assertSame( Verdict::PASS, $event['verdict'], '成功=pass 审计足迹' );
		$this->assertStringContainsString( 'report_sent', $event['rule_hit'], 'pass 特例标签（写入矩阵放行）' );
		$this->assertSame( 'comment', $event['gate'] );
		$this->assertSame( 'report', $event['stage'] );
		$this->assertSame( 42, $event['meta']['score'], '上报后新分值 0–100 整数直存（meta.score）' );
		$this->assertSame( 'captcha_failed', $event['meta']['trigger'] );
		$this->assertSame( 18, $event['meta']['category'] );

		$this->assertSame( array( self::NOW ), $env['marks'], 'last_report_at 落 usage option（§9 Usage.php:37 注释位）' );
	}

	public function test_last_report_at_marked_even_on_failure(): void {
		$env = $this->quick( false );
		$env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) );
		$this->assertCount( 1, $env['marks'], '持久兜底按尝试落（成功失败都标）' );
	}

	/* -------------------------------------------------------------------
	 * check 面总开关（report-only 装配形态——probe 全 skip）
	 * ------------------------------------------------------------------ */

	public function test_probe_check_disabled_returns_null_skip(): void {
		// iprep.enabled=false（report-only 装配）→ probe 恒 null（与 Null 服务语义等价）。
		$env = $this->quick( $this->ok_response(), self::settings( array(), array( 'enabled' => false ) ) );
		$this->assertNull( $env['service']->probe( self::IP ), 'check 面总开关关→probe skip' );
		$this->assertNull( $env['service']->probe( self::IP, array( 'cache_only' => true ) ), 'cache_only 同 skip' );
		$this->assertCount( 0, $env['calls'], '零外呼' );

		// report 面照常可达（report 独立于 iprep.enabled——04 §2）。
		$this->assertTrue( $env['service']->report_ip( self::IP, array( 'trigger' => 'captcha_failed' ) ) );
		$this->assertCount( 1, $env['calls'] );
	}

	/* -------------------------------------------------------------------
	 * 夹具
	 * ------------------------------------------------------------------ */

	/**
	 * 设置树（iprep 子树全量形——report 子树键集对齐 SettingsSchema v1.7+I3）。
	 *
	 * @param array<string, mixed> $report_overrides report 子树覆盖。
	 * @param array<string, mixed> $iprep_overrides  iprep 顶层覆盖。
	 * @return array<string, mixed>
	 */
	private static function settings( array $report_overrides = array(), array $iprep_overrides = array() ): array {
		$report = array_merge( array(
			'enabled'          => true,
			'triggers'         => array(
				'captcha_failed'            => true,
				'review_reject'             => true,
				'login_attempts'            => true,
				'local_rules_fail'          => true,
				'review_reject_threshold'    => 5,
				'local_rules_fail_threshold' => 5,
			),
			'cooldown_minutes' => 60,
			'hourly_limit'     => 60,
			'daily_cap'        => 500,
			'category'         => 0,
			'category_captcha_failed'   => 0,
			'category_review_reject'    => 0,
			'category_login_attempts'   => 0,
			'category_local_rules_fail' => 0,
			'comment_captcha_failed'    => '',
			'comment_review_reject'     => '',
			'comment_login_attempts'    => '',
			'comment_local_rules_fail'  => '',
		), $report_overrides );

		$iprep = array_merge( array(
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
			'report'                 => $report,
		), $iprep_overrides );

		return array(
			'iprep' => $iprep,
			'stats' => array( 'raw_capture' => true, 'raw_max_bytes' => 512 ),
			'network' => array( 'ip_source' => 'direct' ),
		);
	}

	/**
	 * 构造被测服务（全依赖注入；store/计数器经引用回传断言面）。
	 *
	 * @param mixed                $response     fake transport 返回（wp_remote_post 形态/false）。
	 * @param array<string, mixed>|null         $settings      设置树。
	 * @param array<string, mixed> $deps         deps：real_ip_ok/is_admin/whitelist/report_used_today/check_used_today。
	 * @return array<string, mixed>
	 */
	private function quick( $response, ?array $settings = null, array $deps = array() ): array {
		$cache = array(); $calls = array(); $events = array(); $marks = array(); $usage_keys = array();
		$report_used = $deps['report_used_today'] ?? 0;
		$check_used  = $deps['check_used_today'] ?? 0;

		$transport = function ( string $url, array $args ) use ( &$calls, $response ) {
			$calls[] = array( 'url' => $url, 'args' => $args );
			return $response;
		};
		$remote = new RemoteClient(
			$transport,
			function ( string $key ) use ( &$usage_keys ): void {
				$usage_keys[] = $key; // RemoteClient 计量钩子捕获（键断言=分桶证据）。
			},
			static function ( int $ms ): void {
				unset( $ms );
			}
		);

		$service = new AbuseIpdbService(
			$settings ?? self::settings(),
			$remote,
			static function ( string $key ) use ( &$cache ) {
				return array_key_exists( $key, $cache ) ? $cache[ $key ] : null; // 缓存读。
			},
			static function ( string $key, $value, int $ttl ) use ( &$cache ): void {
				$cache[ $key ] = $value; // 缓存写（check verdict / report 计数桶同 store——生产同为 transient）。
			},
			static function ( string $key ) use ( &$cache ) {
				return array_key_exists( $key, $cache ) ? $cache[ $key ] : null; // 退避读。
			},
			static function ( string $key, $value, int $ttl ) use ( &$cache ): void {
				$cache[ $key ] = $value; // 退避写。
			},
			static function ( string $driver_id ): ?string {
				unset( $driver_id );
				return null; // DriverHealth::state_today 注入位（正常态）。
			},
			static function ( string $driver_id, string $state ): void {
				unset( $driver_id, $state );
			},
			static function () use ( $report_used, $check_used ): array {
				return array( 'date' => '2026-11-12', 'counters' => array( 'abuseipdb_report' => $report_used, 'abuseipdb' => $check_used ) ); // Usage::get 注入位。
			},
			static function ( string $data ): string {
				return substr( hash_hmac( 'sha256', $data, self::HMAC_SALT ), 0, 16 ); // 与生产 wp_salt('auth') 同形。
			},
			$deps['is_admin'] ?? static function (): bool {
				return false;
			},
			static function (): array {
				return array(); // 服务器自身 IP 集（默认空）。
			},
			$deps['whitelist'] ?? static function ( string $ip ): bool {
				unset( $ip );
				return false;
			},
			static function (): int {
				return self::NOW; // 固定时钟。
			},
			function ( int $ts ) use ( &$marks ): void {
				$marks[] = $ts; // Usage::mark_report_at_option 注入位（last_report_at）。
			},
			function ( string $gate, string $stage, Verdict $verdict, array $meta ) use ( &$events ): ?string {
				$events[] = array( 'gate' => $gate, 'stage' => $stage, 'verdict' => $verdict->verdict(), 'rule_hit' => implode( ',', $verdict->hits() ), 'meta' => $meta ); // 事件记录捕获。
				return 'WK-20261112-0999';
			},
			static function () use ( $deps ): bool {
				return (bool) ( $deps['real_ip_ok'] ?? true ); // 闸④ 真实 IP 自检（默认过——公网形态）。
			}
		);

		return array(
			'service'    => $service,
			'cache'      => &$cache,
			'calls'      => &$calls,
			'events'     => &$events,
			'marks'      => &$marks,
			'usage_keys' => &$usage_keys,
		);
	}

	/**
	 * AbuseIPDB report 成功响应夹具（data.ipAddress + 上报后新分值）。
	 *
	 * @return array<string, mixed>
	 */
	private static function ok_response(): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( array(
				'data' => array(
					'ipAddress'            => self::IP,
					'abuseConfidenceScore' => 42,
				),
			) ),
			'headers'  => array(),
		);
	}

	private function hmac16( string $data ): string {
		return substr( hash_hmac( 'sha256', $data, self::HMAC_SALT ), 0, 16 );
	}
}
