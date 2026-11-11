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
		$this->assertSame( '人机验证', Labels::action_word( 'captcha' ) );
		$this->assertSame( '送审', Labels::action_word( 'review' ) );
		$this->assertSame( '信誉查询', Labels::action_word( 'ip' ) );
		$this->assertNotSame( '调用', Labels::action_word( 'unknown' ), '未知类型不得回落「调用」黑话' );
	}

	/* ---------- 对照表 2：通过 M → 验证放行 ---------- */

	public function test_pass_word(): void {
		$this->assertSame( '验证放行', Labels::pass_word() );
	}

	/* ---------- 对照表 3：拦截 K → 拦下（条=评论、次=登录） ---------- */

	public function test_reject_word_and_count_unit(): void {
		$this->assertSame( '拦下', Labels::reject_word() );
		$this->assertSame( '条', Labels::count_unit( 'comment' ), '评论语境量词=条' );
		$this->assertSame( '次', Labels::count_unit( 'login' ), '登录语境量词=次' );
		$this->assertSame( '次', Labels::count_unit( 'register' ) );
	}

	/* ---------- 对照表 4：待审 → 转人工复核 ---------- */

	public function test_review_word(): void {
		$this->assertSame( '转人工复核', Labels::review_word() );
	}

	/* ---------- 对照表 5：故障/error → 出错 ---------- */

	public function test_error_word(): void {
		$this->assertSame( '出错', Labels::error_word() );
	}

	/* ---------- 对照表 6：登录失败尝试 T → 密码输错 ---------- */

	public function test_attempts_word(): void {
		$this->assertSame( '密码输错', Labels::attempts_word() );
	}

	/* ---------- 对照表 7：驱动 → 服务商 ---------- */

	public function test_driver_word(): void {
		$this->assertSame( '服务商', Labels::driver_word() );
	}

	/* ---------- 对照表 8：维度（表头）→ 防线 ---------- */

	public function test_defense_word(): void {
		$this->assertSame( '防线', Labels::defense_word() );
	}

	/* ---------- 对照表 9：判定（表头）→ 结果 ---------- */

	public function test_verdict_word(): void {
		$this->assertSame( '结果', Labels::verdict_word() );
	}

	/* ---------- 对照表 10：当日停转（state）→ 今日暂停 ---------- */

	public function test_halt_word(): void {
		$this->assertSame( '额度用完', Labels::halt_word( 'quota_exhausted' ) );
		$this->assertSame( '配置有问题', Labels::halt_word( 'config_error' ) );
		$this->assertSame( '', Labels::halt_word( null ), '无停转=空词（正常态不标注）' );
	}

	/* ---------- 对照表 11：0 调用 → 今日没用到 ---------- */

	public function test_zero_call_word(): void {
		$this->assertSame( '今日没用到', Labels::zero_call_word() );
	}

	/* ---------- 对照表 12：stub/签名存根 → 机器人试探（+ 阶段词全集） ---------- */

	public function test_stage_word(): void {
		$this->assertSame( '机器人试探', Labels::stage_word( 'stub' ) );
		$this->assertSame( 'IP 信誉', Labels::stage_word( 'ip' ) );
		$this->assertSame( '人机验证', Labels::stage_word( 'captcha' ) );
		$this->assertSame( '内容审核', Labels::stage_word( 'review' ) );
	}

	/* ---------- 对照表 13-17：rule_hit 键族 → 人话 ---------- */

	public function test_rule_hit_fault_family(): void {
		$this->assertSame( '网络连不上', Labels::rule_hit_word( 'remote_transport' ) );
		$this->assertSame( '服务商拒绝了（查配置或欠费）', Labels::rule_hit_word( 'remote_semantic' ) );
		$this->assertSame( '服务商返回了看不懂的结果', Labels::rule_hit_word( 'remote_contract' ) );
		$this->assertSame( '服务商返回了看不懂的结果', Labels::rule_hit_word( 'contract_mismatch' ) );
		$this->assertSame( '当日额度用完', Labels::rule_hit_word( 'driver_halted:quota_exhausted' ) );
		$this->assertSame( '配置有问题（服务商拒绝了请求）', Labels::rule_hit_word( 'driver_halted:config_error' ) );
		$this->assertSame( '服务商恢复正常', Labels::rule_hit_word( 'driver_resumed' ) );
	}

	public function test_rule_hit_comment_rule_family(): void {
		$this->assertSame( '字数太少', Labels::rule_hit_word( 'too_short' ) );
		$this->assertSame( '超出字数上限', Labels::rule_hit_word( 'too_long' ) );
		$this->assertSame( '没有中文内容', Labels::rule_hit_word( 'no_chinese' ) );
		$this->assertSame( '命中关键词黑名单', Labels::rule_hit_word( 'banned_word' ) );
		$this->assertSame( '收进待审区等站长复核', Labels::rule_hit_word( 'hold_moderation' ) );
		$this->assertSame( '没带验证码票据', Labels::rule_hit_word( 'captcha_missing' ) );
		$this->assertSame( '票据签名不对', Labels::rule_hit_word( 'bad-signature' ) );
	}

	public function test_rule_hit_captcha_code_and_unknown_passthrough(): void {
		$this->assertSame( '人机验证没通过（返回码 8）', Labels::rule_hit_word( 'CaptchaCode=8' ) );
		// 未收录键不吞不译（原样返回，转义后直出——不撒谎原则）。
		$this->assertSame( 'Ad,99', Labels::rule_hit_word( 'Ad,99' ) );
		$this->assertSame( '', Labels::rule_hit_word( '' ) );
	}

	/* ---------- 对照表 18：meta.raw/脱敏快照 → 服务商原始回复 ---------- */

	public function test_snapshot_word(): void {
		$this->assertSame( '服务商原始回复', Labels::snapshot_word() );
	}

	/* ---------- 对照表 19：快照截断字节 → 保存长度上限（字） ---------- */

	public function test_snapshot_limit_word(): void {
		$this->assertSame( '保存长度上限（字）', Labels::snapshot_limit_word() );
	}

	/* ---------- 对照表 21：事件流 → 拦截记录流水 ---------- */

	public function test_event_stream_word(): void {
		$this->assertSame( '拦截记录流水', Labels::event_stream_word() );
	}

	/* ---------- 对照表 22：计数缓存/事件表/对账口径 → 所有统计数字和拦截记录 ---------- */

	public function test_reset_scope_word(): void {
		$this->assertSame( '所有统计数字和拦截记录', Labels::reset_scope_word() );
	}

	/* ---------- 对照表 23：闸 → 段名/门名（闸字退到括号隐喻层） ---------- */

	public function test_section_and_gate_words(): void {
		$this->assertSame( '评论防护', Labels::section_word( 'comment' ) );
		$this->assertSame( '登录防护', Labels::section_word( 'login' ) );
		$this->assertSame( '登录门', Labels::gate_word( 'login' ) );
		$this->assertSame( '注册门', Labels::gate_word( 'register' ) );
		$this->assertSame( '找回密码门', Labels::gate_word( 'lostpassword' ) );
		$this->assertSame( '评论门', Labels::gate_word( 'comment' ) );
	}

	/* ---------- 对照表 24：累计拦截 N 次 → 累计拦下 N 条（自上次清零起） ---------- */

	public function test_cumulative_word_and_line(): void {
		$this->assertSame( '累计拦下', Labels::cumulative_word() );
		$this->assertSame(
			'累计拦下 1,101 条（自 2026-11-01 上次清零起）',
			Labels::cumulative_line( '1,101', '条', '2026-11-01' ),
			'有清零时间戳 → 带窗口标注（P9 修复）'
		);
		$this->assertSame(
			'累计拦下 55 次',
			Labels::cumulative_line( '55', '次', '' ),
			'从未清零 → 无窗口段（不虚构起点）'
		);
	}

	/* ---------- 登录 trigger_source 全集（愿望 4/N5——批 2 登录明细「为什么拦」列素材） ---------- */

	public function test_trigger_words_full_set(): void {
		$this->assertSame( '同一账号连错太多次', Labels::trigger_word( 'user_threshold' ) );
		$this->assertSame( '同一 IP 试了太多次', Labels::trigger_word( 'ip_threshold' ) );
		$this->assertSame( 'IP 声名狼藉', Labels::trigger_word( 'iprep' ) );
		$this->assertSame( '站点警报期（近期失败太多）', Labels::trigger_word( 'alarm' ) );
		$this->assertSame( '表单停留太久才提交', Labels::trigger_word( 'stale_form' ) );
		$this->assertSame( '配置为一律要过人机验证', Labels::trigger_word( 'always' ) );
		$this->assertSame( '经 XML-RPC 接口尝试', Labels::trigger_word( 'xmlrpc' ) );
		$this->assertSame( 'mystery', Labels::trigger_word( 'mystery' ), '未知归因键原样返回' );
	}
}
