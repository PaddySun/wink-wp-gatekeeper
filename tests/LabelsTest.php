<?php
/**
 * Labels 术语映射表测试（UI 批 1·N5——DESIGN-UI-tab6 §4 对照表 24 条落纯函数）。
 *
 * 「站长一眼看懂」词汇资产：Tab⑥（批 1）/批 2 事件行组装/批 3 Tab① 点名册共用
 * 同一张映射表（agent C §G 批间共享件）。全部纯函数零 WP 依赖。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Stats\Labels;

final class LabelsTest extends TestCase {

	/* ---------- 对照表 1：调用 N → 按服务商类型翻译（「调用」永不再单独出现） ---------- */

	public function test_action_word_by_driver_type(): void {
		$this->assertSame( 'Human verification', Labels::action_word( 'captcha' ) );
		$this->assertSame( 'Sent for review', Labels::action_word( 'review' ) );
		$this->assertSame( 'Reputation lookup', Labels::action_word( 'ip' ) );
		$this->assertNotSame( '调用', Labels::action_word( 'unknown' ), '未知类型不得回落「调用」黑话' );
	}

	/* ---------- 对照表 2：通过 M → 验证放行 ---------- */

	public function test_pass_word(): void {
		$this->assertSame( 'Verification pass', Labels::pass_word() );
	}

	/* ---------- 对照表 3：拦截 K → 拦下（条=评论、次=登录） ---------- */

	public function test_reject_word_and_count_unit(): void {
		$this->assertSame( 'Blocked', Labels::reject_word() );
		$this->assertSame( 'comments', Labels::count_unit( 'comment' ), '评论语境量词=条' );
		$this->assertSame( 'times', Labels::count_unit( 'login' ), '登录语境量词=次' );
		$this->assertSame( 'times', Labels::count_unit( 'register' ) );
	}

	/* ---------- 对照表 4：待审 → 转人工复核 ---------- */

	public function test_review_word(): void {
		$this->assertSame( 'Manual review', Labels::review_word() );
	}

	/* ---------- 对照表 5：故障/error → 出错 ---------- */

	public function test_error_word(): void {
		$this->assertSame( 'Errors', Labels::error_word() );
	}

	/* ---------- 对照表 6：登录失败尝试 T → 密码输错 ---------- */

	public function test_attempts_word(): void {
		$this->assertSame( 'Wrong passwords', Labels::attempts_word() );
	}

	/* ---------- 对照表 7：驱动 → 服务商 ---------- */

	public function test_driver_word(): void {
		$this->assertSame( 'Provider', Labels::driver_word() );
	}

	/* ---------- 对照表 8：维度（表头）→ 防线 ---------- */

	public function test_defense_word(): void {
		$this->assertSame( 'Defense line', Labels::defense_word() );
	}

	/* ---------- 对照表 9：判定（表头）→ 结果 ---------- */

	public function test_verdict_word(): void {
		$this->assertSame( 'Result', Labels::verdict_word() );
	}

	/* ---------- 对照表 10：当日停转（state）→ 今日暂停 ---------- */

	public function test_halt_word(): void {
		$this->assertSame( 'Quota used up', Labels::halt_word( 'quota_exhausted' ) );
		$this->assertSame( 'Configuration problem', Labels::halt_word( 'config_error' ) );
		$this->assertSame( '', Labels::halt_word( null ), '无停转=空词（正常态不标注）' );
	}

	/* ---------- 对照表 11：0 调用 → 今日没用到 ---------- */

	public function test_zero_call_word(): void {
		$this->assertSame( 'Not used today', Labels::zero_call_word() );
	}

	/* ---------- 对照表 12：stub/签名存根 → 机器人试探（+ 阶段词全集） ---------- */

	public function test_stage_word(): void {
		$this->assertSame( 'Bot probing', Labels::stage_word( 'stub' ) );
		$this->assertSame( 'IP reputation', Labels::stage_word( 'ip' ) );
		$this->assertSame( 'Human verification', Labels::stage_word( 'captcha' ) );
		$this->assertSame( 'Content moderation', Labels::stage_word( 'review' ) );
	}

	/* ---------- 对照表 13-17：rule_hit 键族 → 人话 ---------- */

	public function test_rule_hit_fault_family(): void {
		$this->assertSame( 'Network unreachable', Labels::rule_hit_word( 'remote_transport' ) );
		$this->assertSame( 'Provider refused the request (check configuration or billing)', Labels::rule_hit_word( 'remote_semantic' ) );
		$this->assertSame( 'Provider returned an unreadable result', Labels::rule_hit_word( 'remote_contract' ) );
		$this->assertSame( 'Provider returned an unreadable result', Labels::rule_hit_word( 'contract_mismatch' ) );
		$this->assertSame( 'Daily quota used up', Labels::rule_hit_word( 'driver_halted:quota_exhausted' ) );
		$this->assertSame( 'Configuration problem (the provider refused the request)', Labels::rule_hit_word( 'driver_halted:config_error' ) );
		$this->assertSame( 'Provider recovered', Labels::rule_hit_word( 'driver_resumed' ) );
	}

	public function test_rule_hit_comment_rule_family(): void {
		$this->assertSame( 'Too short', Labels::rule_hit_word( 'too_short' ) );
		$this->assertSame( 'Over the length limit', Labels::rule_hit_word( 'too_long' ) );
		$this->assertSame( 'No Chinese content', Labels::rule_hit_word( 'no_chinese' ) );
		$this->assertSame( 'Keyword blacklist hit', Labels::rule_hit_word( 'banned_word' ) );
		$this->assertSame( 'Pended for the site owner to review', Labels::rule_hit_word( 'hold_moderation' ) );
		$this->assertSame( 'No captcha ticket', Labels::rule_hit_word( 'captcha_missing' ) );
		$this->assertSame( 'Bad ticket signature', Labels::rule_hit_word( 'bad-signature' ) );
	}

	public function test_rule_hit_captcha_code_and_unknown_passthrough(): void {
		$this->assertSame( 'Human verification failed (return code 8）', Labels::rule_hit_word( 'CaptchaCode=8' ) );
		// 未收录键不吞不译（原样返回，转义后直出——不撒谎原则）。
		$this->assertSame( 'Ad,99', Labels::rule_hit_word( 'Ad,99' ) );
		$this->assertSame( '', Labels::rule_hit_word( '' ) );
	}

	/* ---------- 对照表 18：meta.raw/脱敏快照 → 服务商原始回复 ---------- */

	public function test_snapshot_word(): void {
		$this->assertSame( 'Raw provider response', Labels::snapshot_word() );
	}

	/* ---------- 对照表 19：快照截断字节 → 保存长度上限（字） ---------- */

	public function test_snapshot_limit_word(): void {
		$this->assertSame( 'Storage length cap (characters)', Labels::snapshot_limit_word() );
	}

	/* ---------- 对照表 21：事件流 → 拦截记录流水 ---------- */

	public function test_event_stream_word(): void {
		$this->assertSame( 'Intercept log', Labels::event_stream_word() );
	}

	/* ---------- 对照表 22：计数缓存/事件表/对账口径 → 所有统计数字和拦截记录 ---------- */

	public function test_reset_scope_word(): void {
		$this->assertSame( 'All statistics and intercept records', Labels::reset_scope_word() );
	}

	/* ---------- 对照表 23：闸 → 段名/门名（闸字退到括号隐喻层） ---------- */

	public function test_section_and_gate_words(): void {
		$this->assertSame( 'Comment protection', Labels::section_word( 'comment' ) );
		$this->assertSame( 'Sign-in protection', Labels::section_word( 'login' ) );
		$this->assertSame( 'Login', Labels::gate_word( 'login' ) );
		$this->assertSame( 'Registration', Labels::gate_word( 'register' ) );
		$this->assertSame( 'Password recovery', Labels::gate_word( 'lostpassword' ) );
		$this->assertSame( 'Comments', Labels::gate_word( 'comment' ) );
	}

	/* ---------- 对照表 24：累计拦截 N 次 → 累计拦下 N 条（自上次清零起） ---------- */

	public function test_cumulative_word_and_line(): void {
		$this->assertSame( 'Blocked cumulatively', Labels::cumulative_word() );
		$this->assertSame(
			'Blocked cumulatively 1,101 comments(since 2026-11-01, last reset)',
			Labels::cumulative_line( '1,101', 'comments', '2026-11-01' ),
			'有清零时间戳 → 带窗口标注（P9 修复）'
		);
		$this->assertSame(
			'Blocked cumulatively 55 times',
			Labels::cumulative_line( '55', 'times', '' ),
			'从未清零 → 无窗口段（不虚构起点）'
		);
	}

	/* ---------- 登录 trigger_source 全集（愿望 4/N5——批 2 登录明细「为什么拦」列素材） ---------- */

	public function test_trigger_words_full_set(): void {
		$this->assertSame( 'Too many consecutive failures for this account', Labels::trigger_word( 'user_threshold' ) );
		$this->assertSame( 'This IP tried too many times', Labels::trigger_word( 'ip_threshold' ) );
		$this->assertSame( 'IP has a bad reputation', Labels::trigger_word( 'iprep' ) );
		$this->assertSame( 'Site alarm period (many recent failures)', Labels::trigger_word( 'alarm' ) );
		$this->assertSame( 'Form submitted after sitting too long', Labels::trigger_word( 'stale_form' ) );
		$this->assertSame( 'Configured to always require human verification', Labels::trigger_word( 'always' ) );
		$this->assertSame( 'Attempt via XML-RPC', Labels::trigger_word( 'xmlrpc' ) );
		$this->assertSame( 'mystery', Labels::trigger_word( 'mystery' ), '未知归因键原样返回' );
	}

	/* ================================================================
	 * UI 批 3·Tab① 点名册（DESIGN-UI-tab1 §3 对照表 17 条并入——批 1 已收录键
	 * （驱动→服务商/额度用完/今日没用到/人机验证·内容审核/转人工复核）不重复落表，
	 * 本段只钉总览新增面；统筹裁决：点名册直白命名、系统层叫「服务商」。
	 * ================================================================ */

	/* ---------- 对照表 B-5：排班态词（当班/待命/未配置） ---------- */

	public function test_duty_words(): void {
		$this->assertSame( 'On duty', Labels::duty_word( 'active' ) );
		$this->assertSame( 'Standby', Labels::duty_word( 'standby' ) );
		$this->assertSame( 'Not configured', Labels::duty_word( 'unconfigured' ) );
		$this->assertSame( 'other', Labels::duty_word( 'other' ), '未知排班键原样' );
	}

	/* ---------- 对照表 B-2/B-3：健康态词（正常/故障/额度用完）+ 原因括号注 ---------- */

	public function test_health_words_and_halt_notes(): void {
		$this->assertSame( 'Normal', Labels::health_word( null ) );
		$this->assertSame( 'Normal', Labels::health_word( '' ) );
		$this->assertSame( 'Fault', Labels::health_word( 'config_error' ) );
		$this->assertSame( 'Quota used up', Labels::health_word( 'quota_exhausted' ) );
		$this->assertSame( 'weird', Labels::health_word( 'weird' ), '未知停转态原样（不吞键）' );

		$this->assertSame( '', Labels::halt_note( null ) );
		$this->assertSame( '', Labels::halt_note( '' ) );
		$this->assertStringContainsString( 'key rejected', Labels::halt_note( 'config_error' ) );
		$this->assertStringContainsString( 'resumes automatically tomorrow', Labels::halt_note( 'quota_exhausted' ) );
		// 状态码不出站长文案。
		$this->assertStringNotContainsString( 'config_error', Labels::halt_note( 'config_error' ) );
		$this->assertStringNotContainsString( 'quota_exhausted', Labels::halt_note( 'quota_exhausted' ) );
	}

	/* ---------- 对照表 B-10/11/13/14/16：待你过目/备胎方案/每日额度/演练模式/垃圾箱存档 ---------- */

	public function test_overview_term_words(): void {
		$this->assertSame( 'Awaiting your review', Labels::pending_word() );
		$this->assertSame( 'Fallback plan', Labels::failover_word() );
		$this->assertSame( 'Daily limit', Labels::quota_word() );
		$this->assertSame( 'Drill mode', Labels::test_mode_word() );
		$this->assertSame( 'Spam archive', Labels::spam_word() );
	}

	/* ---------- 对照表 B-7/8/9：本地规则脚注/当前审核服务/放行不记数注 ---------- */

	public function test_overview_note_sentences(): void {
		$this->assertSame( 'The built-in rules (local rules) are always on duty, depend on no external service, and need no keys', Labels::local_rules_footnote() );
		$this->assertSame( 'Current moderation service', Labels::review_service_word() );
		$this->assertSame( '“All passed” is an estimate — normal approvals are not counted (only blocked and pended ones are)', Labels::pass_uncounted_note() );
	}

	/* ---------- 对照表 B-12：按岗位动词（今日验 N 次/今日审 N 次） ---------- */

	public function test_action_verbs(): void {
		$this->assertSame( 'Verify', Labels::action_verb( 'captcha' ) );
		$this->assertSame( 'Review', Labels::action_verb( 'review' ) );
		$this->assertSame( 'Check', Labels::action_verb( 'ip' ) );
		$this->assertSame( 'Action', Labels::action_verb( 'unknown' ), '未知类型不回落「调用」黑话' );
	}

	/* ---------- 对照表 B-15：战报副行人话族（规则键→短词；未收录回落 rule_hit_word 同资产） ---------- */

	public function test_rule_family_words(): void {
		$this->assertSame( 'Keyword blacklist', Labels::rule_family_word( 'banned_word' ) );
		$this->assertSame( 'Non-Chinese only', Labels::rule_family_word( 'no_chinese' ) );
		$this->assertSame( 'Too short', Labels::rule_family_word( 'too_short' ) );
		$this->assertSame( 'Over length limit', Labels::rule_family_word( 'too_long' ) );
		$this->assertSame( 'Manual', Labels::rule_family_word( 'hold_moderation' ) );
		$this->assertSame( 'No ticket', Labels::rule_family_word( 'captcha_missing' ) );
		$this->assertSame( 'Bad ticket signature', Labels::rule_family_word( 'bad-signature' ) );
		$this->assertSame( 'Human verification failed', Labels::rule_family_word( 'CaptchaCode=8' ) );
		$this->assertSame( 'Provider communication failure', Labels::rule_family_word( 'remote_transport' ) );
		$this->assertSame( 'Provider failure', Labels::rule_family_word( 'contract_mismatch' ) );
		// 未收录键回落 rule_hit_word 同一资产（对照表 13-17——两页共用一张映射表）。
		$this->assertSame( 'Trusted-visitor waiver passed', Labels::rule_family_word( 'trust_pass' ) );
	}
}
