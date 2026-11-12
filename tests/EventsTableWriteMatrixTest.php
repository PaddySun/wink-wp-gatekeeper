<?php
/**
 * EventsTable 扩展单元测试（04 §3 写入矩阵 + rule_hit 拼接 + 容量决策；纯逻辑部分）。
 *
 * 写入矩阵（v1.2 唯一落库口径）：
 *   stub+reject ✓ / ip 三态非 pass ✓ / captcha+reject ✓（含 drill）/ review 三态非 pass ✓；
 *   全阶段 pass ✗（默认），pass_sampling 开启才采样。
 * insert()/daily_maintenance() 属 $wpdb 边界（静态自查清单覆盖），此处测其纯决策函数。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Stats\EventsTable;
use WinkGatekeeper\Verdict;

final class EventsTableWriteMatrixTest extends TestCase {

	private static function settings( array $overrides = array() ): array {
		return array( 'stats' => array_merge( array( 'enabled' => true, 'pass_sampling' => false ), $overrides ) );
	}

	/* ---------- 写入矩阵全行 ---------- */

	public function test_stub_reject_is_recorded(): void {
		$this->assertTrue( EventsTable::should_record( 'stub', Verdict::reject(), self::settings() ) );
	}

	public function test_ip_reject_review_error_are_recorded(): void {
		$settings = self::settings();
		$this->assertTrue( EventsTable::should_record( 'ip', Verdict::reject(), $settings ) );
		$this->assertTrue( EventsTable::should_record( 'ip', Verdict::review(), $settings ) );
		$this->assertTrue( EventsTable::should_record( 'ip', Verdict::error(), $settings ) );
	}

	public function test_captcha_reject_is_recorded(): void {
		$this->assertTrue( EventsTable::should_record( 'captcha', Verdict::reject(), self::settings() ) );
	}

	public function test_review_reject_review_error_are_recorded(): void {
		$settings = self::settings();
		$this->assertTrue( EventsTable::should_record( 'review', Verdict::reject(), $settings ) );
		$this->assertTrue( EventsTable::should_record( 'review', Verdict::review(), $settings ) );
		$this->assertTrue( EventsTable::should_record( 'review', Verdict::error(), $settings ) );
	}

	public function test_pass_is_not_recorded_by_default_on_all_stages(): void {
		$settings = self::settings();
		foreach ( array( 'stub', 'ip', 'captcha', 'review' ) as $stage ) {
			$this->assertFalse( EventsTable::should_record( $stage, Verdict::pass(), $settings ), "pass 不落库：{$stage}" );
		}
	}

	public function test_pass_sampling_switch_enables_pass_recording(): void {
		$settings = self::settings( array( 'pass_sampling' => true ) );
		foreach ( array( 'ip', 'captcha', 'review' ) as $stage ) {
			$this->assertTrue( EventsTable::should_record( $stage, Verdict::pass(), $settings ), "采样开启应落库：{$stage}" );
		}
	}

	public function test_unknown_stage_non_pass_defaults_to_recorded(): void {
		// stage 为开放枚举（未来新阶段）：非 pass 一律落库、pass 依采样——保守口径。
		$this->assertTrue( EventsTable::should_record( 'future_stage', Verdict::reject(), self::settings() ) );
		$this->assertFalse( EventsTable::should_record( 'future_stage', Verdict::pass(), self::settings() ) );
	}

	/* ---------- 【v1.4·R2】precheck 行（04 §3 写入矩阵新增两行） ---------- */

	public function test_precheck_reject_is_recorded(): void {
		// precheck + reject（四闸不过）✓——stage=precheck、driver=local_rules、rule_hit=too_short 等。
		$this->assertTrue( EventsTable::should_record( 'precheck', Verdict::reject( 'local_rules', 'too_short', array( 'too_short' ) ), self::settings() ) );
	}

	public function test_precheck_pass_is_not_recorded(): void {
		// 点击期通过不落库（提交期落 trust_pass 一条足矣）；precheck「error ✗」行为产者侧
		// 收口——端点异常/门禁/限流失败不调记录器（防端点刷库，PrecheckTest::test_gate_failures_do_not_record_events）。
		$settings = self::settings();
		$this->assertFalse( EventsTable::should_record( 'precheck', Verdict::pass(), $settings ) );
	}

	public function test_captcha_pass_trust_hit_is_recorded(): void {
		// captcha + pass（trust_pass/verify_cache_hit）✓ 特例——免验票审计足迹。
		$this->assertTrue( EventsTable::should_record( 'captcha', Verdict::pass( 'gate', 'trust_pass', array( 'trust_pass' ) ), self::settings() ) );
		$this->assertTrue( EventsTable::should_record( 'captcha', Verdict::pass( 'tencent', 'verify_cache_hit', array( 'verify_cache_hit' ) ), self::settings() ) );
		// 其余 captcha pass 仍默认不记。
		$this->assertFalse( EventsTable::should_record( 'captcha', Verdict::pass(), self::settings() ) );
	}

	public function test_captcha_pass_disaster_pass_hit_is_recorded(): void {
		// 【O3 §三卡 2】captcha + pass（disaster_pass）✓ 特例——容灾放行审计足迹
		// （CaptchaCode=21/trerror 票据 allow 档放行；CommentGate meta.disaster_pass 语境，
		// hits 携带 disaster_pass 标签与 verify_cache_hit/trust_pass 同法）。
		$disaster = Verdict::pass( 'tencent', 'CaptchaCode=21/trerror', array( 'CaptchaCode=21/trerror', 'disaster_pass' ) );
		$this->assertTrue( EventsTable::should_record( 'captcha', $disaster, self::settings() ), '容灾放行 pass 事件须落库（审计足迹）' );
		// 采样关态下不带标签的 pass 仍不落库（对照行——rule 相同无标签不放行）。
		$this->assertFalse( EventsTable::should_record( 'captcha', Verdict::pass( 'tencent', 'CaptchaCode=21/trerror', array( 'CaptchaCode=21/trerror' ) ), self::settings() ) );
	}

	/* ---------- 【I3·定稿 §4/§9】report 行（stage=report 开放枚举） ---------- */

	public function test_report_send_pass_hit_is_recorded(): void {
		// report + pass（report_sent）✓ 特例——上报送达审计足迹（meta.score=上报后新分值）。
		$sent = Verdict::pass( 'abuseipdb', 'report_sent', array( 'report_sent' ) );
		$this->assertTrue( EventsTable::should_record( 'report', $sent, self::settings() ), '上报送达 pass 事件须落库' );
		// 采样关态下不带标签的 pass 仍不落库（对照行）。
		$this->assertFalse( EventsTable::should_record( 'report', Verdict::pass( 'abuseipdb', 'report_sent', array() ), self::settings() ) );
	}

	public function test_report_failure_and_spike_are_recorded(): void {
		// report 发送失败（verdict=error）✓ 与 usage_spike 突增告警（gate=system/stage=ip、
		// verdict=review）✓——非 pass 恒落库（开放枚举保守口径）。
		$this->assertTrue( EventsTable::should_record( 'report', Verdict::error( 'abuseipdb', 'remote_transport', array( 'remote_transport' ) ), self::settings() ) );
		$this->assertTrue( EventsTable::should_record( 'ip', Verdict::review( 'abuseipdb', 'usage_spike', array( 'usage_spike' ) ), self::settings() ) );
	}

	/* ---------- rule_hit 拼接（v1.2：逗号连接 + 120 字符截断 + 截断保序去重） ---------- */

	public function test_hits_to_rule_hit_joins_with_comma(): void {
		$this->assertSame( '广告,灌水', EventsTable::hits_to_rule_hit( array( '广告', '灌水' ) ) );
	}

	public function test_hits_to_rule_hit_preserve_order_dedupe(): void {
		$this->assertSame( '广告,灌水', EventsTable::hits_to_rule_hit( array( '广告', '灌水', '广告' ) ) );
	}

	public function test_hits_to_rule_hit_truncates_to_120_bytes(): void {
		// VARCHAR(120) 为字节口径：多字节标签截断在字节边界内进行（不保证整字符）。
		$long   = str_repeat( '长', 80 );
		$joined = EventsTable::hits_to_rule_hit( array( $long, $long . '2' ) );
		$this->assertSame( 120, strlen( $joined ) );

		// ASCII 用例验证保序：首个标签完整保留。
		$ascii  = str_repeat( 'a', 60 );
		$this->assertSame( $ascii . ',' . str_repeat( 'b', 59 ), EventsTable::hits_to_rule_hit( array( $ascii, str_repeat( 'b', 80 ) ) ) );
	}

	public function test_hits_to_rule_hit_empty_array_is_empty_string(): void {
		$this->assertSame( '', EventsTable::hits_to_rule_hit( array() ) );
	}

	public function test_hits_to_rule_hit_drops_non_string_entries(): void {
		$this->assertSame( '广告', EventsTable::hits_to_rule_hit( array( '广告', 42, null ) ) );
	}

	/* ---------- 容量决策 ---------- */

	public function test_overflow_delete_count_zero_within_capacity(): void {
		$this->assertSame( 0, EventsTable::overflow_delete_count( 100, 10000 ) );
		$this->assertSame( 0, EventsTable::overflow_delete_count( 10000, 10000 ) );
	}

	public function test_overflow_delete_count_excess_rows(): void {
		$this->assertSame( 42, EventsTable::overflow_delete_count( 10042, 10000 ) );
	}

	/* ---------- meta JSON ---------- */

	public function test_encode_meta_round_trip(): void {
		$meta = array( 'post_id' => 42, 'api_version' => 'captcha-2019-07-22', 'ip_src' => 'direct' );
		$this->assertSame( $meta, json_decode( EventsTable::encode_meta( $meta ), true ) );
	}

	public function test_encode_meta_non_scalar_values_survive(): void {
		$encoded = EventsTable::encode_meta( array( 'score' => 60, 'hits' => array( 'a', 'b' ) ) );
		$this->assertSame( array( 'score' => 60, 'hits' => array( 'a', 'b' ) ), json_decode( $encoded, true ) );
	}
}
