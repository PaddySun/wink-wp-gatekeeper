<?php
/**
 * B1b 统计域单元测试（F4-4/F4-6——recorder 口径统一 + by_gate 三维 + Tab⑥ 账户四维；
 * UI 批 1 起展示词人话化——三张门分账/概览合计走 InterceptDigest 新纯函数）。
 *
 * 设计锚点：02 §3.2a【v1.5】（captcha 判定收口按 verdict 三维 bump，计数与落库解耦；
 * drill 不 bump）、04 §4【v1.5】（attempts/captcha_pass/captcha_reject 三维；调用=
 * captcha_pass+captcha_reject+captcha 阶段 error；拦截=既有 reject 维全阶段）、
 * 03 Tab⑥【v1.5】（今日/累计 × 人机验证/验证放行/拦下 + 密码输错数——UI 批 1 译词）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Stats\InterceptDigest;
use WinkGatekeeper\Stats\StatsCache;
use WinkGatekeeper\Verdict;

final class B1bStatsTest extends TestCase {

	private const DATE = '20261111';

	/* ================= StatsCache::stat_keys_for（recorder 三维口径映射） ================= */

	public function test_captcha_stage_pass_bumps_captcha_pass(): void {
		$this->assertSame(
			array( 'captcha_pass' ),
			StatsCache::stat_keys_for( 'captcha', Verdict::pass( 'tencent', 'verify_cache_hit', array( 'verify_cache_hit' ) ) )
		);
	}

	public function test_captcha_stage_reject_bumps_reject_and_captcha_reject(): void {
		// captcha 阶段 reject 双记：既有 reject 维（拦截=全阶段口径）+ captcha_reject（调用数组成）。
		$this->assertSame(
			array( 'reject', 'captcha_reject' ),
			StatsCache::stat_keys_for( 'captcha', Verdict::reject( 'tencent', 'captcha_missing', array( 'captcha_missing' ) ) )
		);
	}

	public function test_captcha_stage_error_bumps_error_dimension(): void {
		$this->assertSame(
			array( 'error' ),
			StatsCache::stat_keys_for( 'captcha', Verdict::error( 'tencent', 'remote_timeout', array( 'remote_timeout' ) ) )
		);
	}

	public function test_captcha_stage_no_driver_pass_skips_stats(): void {
		// no_driver / iprep_fail_no_driver = 「无驱动跳过」留痕，非 captcha 判定收口——不 bump
		//（否则调用数被未配置驱动的空转记录撑大，与 04 §4「调用=判定收口数」口径相悖）。
		$this->assertSame( array(), StatsCache::stat_keys_for( 'captcha', Verdict::pass( '', 'no_driver', array( 'no_driver' ) ) ) );
		$this->assertSame( array(), StatsCache::stat_keys_for( 'captcha', Verdict::pass( '', 'iprep_fail_no_driver', array( 'iprep_fail_no_driver' ) ) ) );
	}

	public function test_stub_stage_reject_bumps_reject_only(): void {
		// stub 阶段（bad-signature）维持既有 reject 维——不加 captcha_reject（非 captcha 验票收口）。
		$this->assertSame(
			array( 'reject' ),
			StatsCache::stat_keys_for( 'stub', Verdict::reject( '', 'bad-signature', array( 'bad-signature' ) ) )
		);
	}

	public function test_stub_stage_pass_and_review_map_generic(): void {
		$this->assertSame( array(), StatsCache::stat_keys_for( 'stub', Verdict::pass( '', 'x', array( 'x' ) ) ) );
		$this->assertSame( array( 'review' ), StatsCache::stat_keys_for( 'review', Verdict::review( 'tms', 'r', array( 'r' ) ) ) );
	}

	public function test_drill_meta_detection(): void {
		// drill（test_mode/演练）行：recorder 不 bump 不计数（02 §3.2a v1.5）。
		$this->assertTrue( StatsCache::is_drill( array( 'drill' => true ) ) );
		$this->assertTrue( StatsCache::is_drill( array( 'drill' => 1 ) ) );
		$this->assertFalse( StatsCache::is_drill( array( 'drill' => false ) ) );
		$this->assertFalse( StatsCache::is_drill( array() ) );
		$this->assertFalse( StatsCache::is_drill( array( 'trigger_source' => 'always' ) ) );
	}

	/* ================= StatsCache::bump_attempts（attempts 维度纯逻辑） ================= */

	public function test_bump_attempts_adds_today_and_total_dimension(): void {
		$cache = StatsCache::seed();
		$cache = StatsCache::bump_attempts( $cache, self::DATE, 'login' );
		$cache = StatsCache::bump_attempts( $cache, self::DATE, 'login' );

		$this->assertSame( 2, $cache['by_gate']['login']['attempts'], '今日树 attempts 直记' );
		$this->assertSame( 2, $cache['total']['by_gate']['login']['attempts'], '累计树 attempts 同步' );
		// attempts 不经 by_driver / total.reject（04 §4：失败尝试非判定事件）。
		$this->assertArrayNotHasKey( 'attempts', $cache['by_driver'], 'attempts 不进 by_driver' );
		$this->assertSame( 0, $cache['total']['reject'], 'attempts 不推高 total.reject 徽标' );
	}

	public function test_bump_attempts_day_flip_clears_today_keeps_total(): void {
		$cache = StatsCache::seed();
		$cache = StatsCache::incr( $cache, self::DATE, 'login', 'tencent', 'reject' );
		$cache = StatsCache::bump_attempts( $cache, self::DATE, 'login' );

		$cache = StatsCache::bump_attempts( $cache, '20261112', 'login' );

		$this->assertSame( 1, $cache['by_gate']['login']['attempts'], '跨日翻转：今日树清零重建，仅新日一笔' );
		$this->assertSame( 2, $cache['total']['by_gate']['login']['attempts'], '累计持续滚动' );
		$this->assertSame( 1, $cache['total']['by_gate']['login']['reject'], '翻转不动累计判定计数' );
	}

	public function test_bump_attempts_normalizes_malformed_cache(): void {
		// 异形输入（option 残缺）回落种子不 Fatal（normalize 防御同源）。
		$cache = StatsCache::bump_attempts( array( 'broken' => true ), self::DATE, 'login' );
		$this->assertSame( 1, $cache['by_gate']['login']['attempts'] );
	}

	/* ================= InterceptDigest 账户四维人话展示（03 Tab⑥ v1.5 口径 + UI 批 1 术语） ================= */

	public function test_account_usage_note_combines_four_dimensions(): void {
		$note = InterceptDigest::account_usage_note( array(
			'captcha_pass'   => 3,
			'captcha_reject' => 2,
			'error'          => 1,
			'reject'         => 4, // 全阶段拦下（captcha 2 + stub 2 的形态）。
			'attempts'       => 7,
		) );
		$this->assertMatchesRegularExpression( '/人机验证 6/u', $note, '人机验证 N = captcha_pass+captcha_reject+captcha 阶段 error（04 §4 口径）' );
		$this->assertMatchesRegularExpression( '/验证放行 3/u', $note );
		$this->assertMatchesRegularExpression( '/拦下 4/u', $note, '拦下 K = 既有 reject 维（全阶段）' );
		$this->assertMatchesRegularExpression( '/密码输错 7/u', $note );
	}

	public function test_account_usage_note_zero_state_is_explicit(): void {
		// 恒 0 也可读（Tab⑥「恒 0 不可区分」教训——零值显式展示）。
		$note = InterceptDigest::account_usage_note( array() );
		$this->assertMatchesRegularExpression( '/人机验证 0/u', $note );
		$this->assertMatchesRegularExpression( '/验证放行 0/u', $note );
		$this->assertMatchesRegularExpression( '/拦下 0/u', $note );
		$this->assertMatchesRegularExpression( '/密码输错 0/u', $note );
	}

	public function test_account_gate_rows_carry_today_and_total_rejects(): void {
		$cache                        = StatsCache::seed();
		$cache['today']               = self::DATE;
		$cache['by_gate']['login']    = array( 'reject' => 1, 'captcha_reject' => 1, 'captcha_pass' => 2, 'attempts' => 5 );
		$cache['total']['by_gate']['login'] = array( 'reject' => 9, 'captcha_reject' => 3, 'captcha_pass' => 10, 'attempts' => 20 );

		$rows = InterceptDigest::account_gate_rows( $cache );

		$login_row = null;
		foreach ( $rows as $row ) {
			if ( 'login' === $row['gate'] ) {
				$login_row = $row;
			}
		}
		$this->assertNotNull( $login_row, '三张门分账须有登录门行' );
		$this->assertSame( 1, $login_row['today_reject'], '今日拦下（recorder 直记值）' );
		$this->assertSame( 9, $login_row['total_reject'], '累计拦下' );
		$this->assertMatchesRegularExpression( '/密码输错 5/u', (string) $login_row['note'], '说明列带今日 attempts' );

		$note_total = InterceptDigest::account_usage_note( $login_row['total'] );
		$this->assertMatchesRegularExpression( '/密码输错 20/u', $note_total, '累计行 attempts 可读' );
		$this->assertMatchesRegularExpression( '/验证放行 10/u', $note_total );
	}

	public function test_account_gate_rows_register_lostpassword_show_zero_attempts(): void {
		$cache                          = StatsCache::seed();
		$cache['today']                 = self::DATE;
		$cache['by_gate']['lostpassword'] = array( 'reject' => 2, 'captcha_reject' => 2 );
		$cache['total']['by_gate']['register'] = array( 'reject' => 1, 'captcha_reject' => 1 );

		$rows  = InterceptDigest::account_gate_rows( $cache );
		$found = array();
		foreach ( $rows as $row ) {
			$found[ $row['gate'] ] = $row;
		}
		$this->assertArrayHasKey( 'register', $found );
		$this->assertArrayHasKey( 'lostpassword', $found );
		$this->assertSame( '注册时一律要过人机验证', $found['register']['note'], 'register 无 attempts 维——静态说明列（03 Tab⑥）' );
		$this->assertSame( '找回密码时一律要过人机验证', $found['lostpassword']['note'] );
		$this->assertSame( 2, $found['lostpassword']['today_reject'] );
	}

	public function test_account_overview_zero_attempts_on_register_lostpassword(): void {
		// attempts 维仅 login 闸（agent C F5）——register/lostpassword 计 0 不干扰合计。
		$cache                     = StatsCache::seed();
		$cache['today']            = self::DATE;
		$cache['by_gate']['login'] = array( 'attempts' => 3 );

		$overview = InterceptDigest::account_overview( $cache, false );
		$this->assertSame( 3, $overview['attempts'] );
		$this->assertSame( 0, $overview['reject'] );
	}
}
