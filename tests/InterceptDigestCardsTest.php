<?php
/**
 * InterceptDigest 驱动统一卡/待审拆分/登录分账纯逻辑测试（UI 批 1）。
 *
 * 覆盖三块新纯函数（DESIGN-UI-tab6 §3 + 统筹裁决 2）：
 *   1. driver_card()——评论/登录两段复用的统一卡模板（四档健康徽标/箭头行/额度行/未启用占位）；
 *   2. review_split()——「转人工复核」排除 IP 信誉强制验证码（单列行数据）；
 *   3. account_overview()/account_gate_rows()/gate_note()/local_rules_card()——登录防护与本地规则卡。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Stats\InterceptDigest;

final class InterceptDigestCardsTest extends TestCase {

	/* ---------- driver_card：四档健康徽标 ---------- */

	public function test_card_normal_enabled_comment(): void {
		$card = InterceptDigest::driver_card( array(
			'label'      => '腾讯天御验证码 2.0',
			'type'       => 'captcha',
			'context'    => 'comment',
			'enabled'    => true,
			'halt_state' => null,
			'used'       => 30,
			'passed'     => null, // 减法推导（D4）。
			'rejected'   => 1,
			'review'     => 0,
			'errors'     => 1,
			'quota'      => 500,
			'total'      => array( 'passed' => null, 'rejected' => 18, 'review' => 0, 'errors' => 1 ),
			'config_tab' => 'captcha',
		) );

		$this->assertSame( '●', $card['badge'] );
		$this->assertSame( '正常', $card['health'] );
		$this->assertTrue( $card['enabled'] );
		$this->assertSame( 'captcha', $card['type'], '输出回带输入类型（渲染层按防线分组筛选——真机 render 通道钉）' );
		$this->assertSame( '人机验证', $card['action'], '动作名按类型翻译（对照表 1）' );
		$this->assertSame( array( '今日人机验证 30 次 → 放行 28 · 拦下 1 · 转人工 0 · 出错 1' ), $card['lines'], '放行=减法推导 30−1−0−1' );
		$this->assertSame( '今日额度 30 / 500 次', $card['quota_line'] );
		$this->assertSame( array( '累计：拦下 18 · 转人工 0 · 出错 1' ), $card['total_lines'], '累计放行格仅在可直读时出现（累计动作数 Usage 不留存——不虚构）' );
		$this->assertNull( $card['placeholder_note'] );
	}

	public function test_card_pass_derivation_floors_at_zero(): void {
		$card = InterceptDigest::driver_card( array(
			'label'      => '腾讯云 TMS 文本审核',
			'type'       => 'review',
			'context'    => 'comment',
			'enabled'    => true,
			'halt_state' => null,
			'used'       => 3,
			'passed'     => null,
			'rejected'   => 5, // 计数大于动作数（stub 拦截未发起调用的形态）→ 推导不许为负。
			'review'     => 0,
			'errors'     => 0,
			'quota'      => 0,
			'total'      => null,
			'config_tab' => 'review',
		) );

		$this->assertStringContainsString( '放行 0', $card['lines'][0], '减法推导下限 0（stub 拦截未发起调用的形态不透支）' );
		$this->assertSame( '送审', $card['action'], '审核类动作名=送审' );
		$this->assertNull( $card['quota_line'], '不限配额 → 无额度行' );
	}

	public function test_card_zero_usage_single_line(): void {
		$card = InterceptDigest::driver_card( array(
			'label'      => '阿里云验证码 2.0',
			'type'       => 'captcha',
			'context'    => 'comment',
			'enabled'    => true,
			'halt_state' => null,
			'used'       => 0,
			'passed'     => null,
			'rejected'   => 0,
			'review'     => 0,
			'errors'     => 0,
			'quota'      => 0,
			'total'      => null,
			'config_tab' => 'captcha',
		) );

		$this->assertSame( '●', $card['badge'] );
		$this->assertSame( array( '今日没用到（0 次）' ), $card['lines'], '零值纪律：整卡单行「今日没用到」（P12 乱码感消除）' );
	}

	public function test_card_quota_halted_badge_and_note(): void {
		$card = InterceptDigest::driver_card( array(
			'label'      => '腾讯云 TMS 文本审核',
			'type'       => 'review',
			'context'    => 'comment',
			'enabled'    => true,
			'halt_state' => 'quota_exhausted',
			'used'       => 0,
			'passed'     => null,
			'rejected'   => 0,
			'review'     => 0,
			'errors'     => 0,
			'quota'      => 500,
			'total'      => null,
			'config_tab' => 'review',
		) );

		$this->assertSame( '◐', $card['badge'] );
		$this->assertSame( '今日暂停', $card['health'] );
		$this->assertStringContainsString( '额度用完', $card['health_note'], '停转有主语（状态码不出现在站长文案）' );
		$this->assertStringNotContainsString( 'quota_exhausted', $card['health_note'] );
	}

	public function test_card_config_error_badge_and_note(): void {
		$card = InterceptDigest::driver_card( array(
			'label'      => '腾讯天御验证码 2.0',
			'type'       => 'captcha',
			'context'    => 'comment',
			'enabled'    => true,
			'halt_state' => 'config_error',
			'used'       => 0,
			'passed'     => null,
			'rejected'   => 0,
			'review'     => 0,
			'errors'     => 0,
			'quota'      => 0,
			'total'      => null,
			'config_tab' => 'captcha',
		) );

		$this->assertSame( '✕', $card['badge'] );
		$this->assertSame( '配置问题', $card['health'] );
		$this->assertStringContainsString( '拒绝', $card['health_note'] );
		$this->assertStringNotContainsString( 'config_error', $card['health_note'] );
	}

	public function test_card_disabled_placeholder_grey_card(): void {
		$card = InterceptDigest::driver_card( array(
			'label'      => '百度文本审核',
			'type'       => 'review',
			'context'    => 'comment',
			'enabled'    => false,
			'halt_state' => 'config_error', // 残留停转态不得盖过未启用（不当班为准）。
			'used'       => 0,
			'passed'     => null,
			'rejected'   => 0,
			'review'     => 0,
			'errors'     => 0,
			'quota'      => 0,
			'total'      => null,
			'config_tab' => 'review',
		) );

		$this->assertSame( '○', $card['badge'] );
		$this->assertSame( '未启用', $card['health'] );
		$this->assertFalse( $card['enabled'] );
		$this->assertStringContainsString( '未启用', (string) $card['placeholder_note'] );
		$this->assertSame( 'review', $card['config_tab'], '占位卡带跳转目标（D6）' );
		$this->assertSame( array(), $card['lines'], '未启用卡无数字行' );
	}

	public function test_card_login_context_hides_review_and_uses_direct_pass(): void {
		$card = InterceptDigest::driver_card( array(
			'label'      => '腾讯天御验证码 2.0',
			'type'       => 'captcha',
			'context'    => 'login',
			'enabled'    => true,
			'halt_state' => null,
			'used'       => 5,   // captcha_pass+captcha_reject 净口径。
			'passed'     => 3,   // 登录域直读（E-c 净口径）。
			'rejected'   => 2,
			'review'     => null, // 登录族无待审——此格隐藏。
			'errors'     => null, // 分域出错数批 2 接线——格省略。
			'quota'      => 0,
			'total'      => array( 'passed' => 10, 'rejected' => 4, 'review' => null, 'errors' => null ),
			'config_tab' => 'captcha',
		) );

		$this->assertSame( '人机验证', $card['action'] );
		$this->assertSame( array( '今日人机验证 5 次 → 放行 3 · 拦下 2' ), $card['lines'], '登录卡无转人工/出错格' );
		$this->assertSame( array( '累计：放行 10 · 拦下 4' ), $card['total_lines'] );
	}

	/* ---------- review_split：待审排除 IP 信誉强制验证码（统筹裁决 2） ---------- */

	private static function cache_with_ip_review(): array {
		return array(
			'today'     => '20261112',
			'by_gate'   => array( 'comment' => array( 'review' => 5 ) ),
			'by_driver' => array(
				'tencent_tms' => array( 'review' => 5 ),
				'abuseipdb'   => array( 'review' => 2, 'reject' => 1 ),
			),
			'total'     => array(
				'by_gate'   => array( 'comment' => array( 'review' => 50 ) ),
				'by_driver' => array(
					'tencent_tms' => array( 'review' => 50 ),
					'abuseipdb'   => array( 'review' => 20, 'reject' => 3 ),
				),
				'reject'    => 0,
			),
			'last_reset' => 0,
		);
	}

	public function test_review_split_excludes_ip_forced_from_review(): void {
		$split = InterceptDigest::review_split( self::cache_with_ip_review(), array( 'abuseipdb' ), false );

		$this->assertSame( 3, $split['review'], '转人工复核=review 全量−IP 风控 review（只计云审/规则待审）' );
		$this->assertSame( 2, $split['ip_review'], '排除量单列' );
		$this->assertSame( 3, $split['ip_captcha'], '单列行=IP 风控 review+reject（两者都是强制验证码信号）' );
	}

	public function test_review_split_total_tree_and_no_ip_drivers(): void {
		$total = InterceptDigest::review_split( self::cache_with_ip_review(), array( 'abuseipdb' ), true );
		$this->assertSame( 30, $total['review'] );
		$this->assertSame( 20, $total['ip_review'] );
		$this->assertSame( 23, $total['ip_captcha'] );

		$none = InterceptDigest::review_split( self::cache_with_ip_review(), array(), false );
		$this->assertSame( 5, $none['review'], '无 IP 服务商 → 待审不折减（现状口径）' );
		$this->assertSame( 0, $none['ip_captcha'] );
	}

	/* ---------- 登录防护：概览合计/三张门分账/说明列 ---------- */

	private static function login_cache(): array {
		$cache = array(
			'today'     => '20261112',
			'by_gate'   => array(
				'login'    => array( 'reject' => 1, 'captcha_reject' => 1, 'captcha_pass' => 2, 'attempts' => 37 ),
				'register' => array( 'reject' => 0, 'captcha_pass' => 1 ),
			),
			'by_driver' => array(),
			'total'     => array(
				'by_gate'   => array(
					'login'    => array( 'reject' => 5, 'captcha_reject' => 3, 'captcha_pass' => 10, 'attempts' => 412 ),
					'lostpassword' => array( 'reject' => 0 ),
				),
				'by_driver' => array(),
				'reject'    => 5,
			),
			'last_reset' => 0,
		);
		return $cache;
	}

	public function test_account_overview_sums_three_gates(): void {
		$today = InterceptDigest::account_overview( self::login_cache(), false );
		$this->assertSame( 1, $today['reject'], '三闸拦下合计' );
		$this->assertSame( 3, $today['passed'], '验证放行=captcha_pass 合计（净口径）' );
		$this->assertSame( 37, $today['attempts'], '密码输错=attempts 合计（仅 login 有值）' );

		$total = InterceptDigest::account_overview( self::login_cache(), true );
		$this->assertSame( 5, $total['reject'] );
		$this->assertSame( 10, $total['passed'] );
		$this->assertSame( 412, $total['attempts'] );
	}

	public function test_account_gate_rows_three_doors_with_notes(): void {
		$rows = InterceptDigest::account_gate_rows( self::login_cache() );

		$this->assertSame( array( 'login', 'register', 'lostpassword' ), array_column( $rows, 'gate' ) );
		$this->assertSame( array( '登录门', '注册门', '找回密码门' ), array_column( $rows, 'label' ), '门名（闸字退到括号层）' );

		$login = $rows[0];
		$this->assertSame( 1, $login['today_reject'] );
		$this->assertSame( 5, $login['total_reject'] );
		$this->assertStringContainsString( '密码输错 37 次', $login['note'] );

		$register = $rows[1];
		$this->assertSame( '注册时一律要过人机验证', $register['note'] );

		$lost = $rows[2];
		$this->assertSame( '找回密码时一律要过人机验证', $lost['note'] );
	}

	public function test_gate_note_login_carries_dynamic_window(): void {
		$note = InterceptDigest::gate_note( 'login', array( 'attempts' => 37, 'captcha_reject' => 1 ), 15 );
		$this->assertStringContainsString( '密码输错 37 次', $note );
		$this->assertStringContainsString( '15 分钟', $note, '窗口标注随配置动态（D3）' );

		$custom = InterceptDigest::gate_note( 'login', array( 'attempts' => 5 ), 30 );
		$this->assertStringContainsString( '30 分钟', $custom );

		$zero = InterceptDigest::gate_note( 'login', array(), 15 );
		$this->assertStringContainsString( '密码输错 0 次', $zero, '零值显式（恒 0 不可区分教训）' );
	}

	/* ---------- 本地规则卡：三数式（A-3 微调——砍「检查 N 条」） ---------- */

	public function test_local_rules_card_three_numbers(): void {
		$cache = array(
			'today'     => '20261112',
			'by_gate'   => array(),
			'by_driver' => array( 'local_rules' => array( 'reject' => 2, 'review' => 1 ) ),
			'total'     => array( 'by_gate' => array(), 'by_driver' => array( 'local_rules' => array( 'reject' => 20, 'review' => 5, 'error' => 1 ) ), 'reject' => 20 ),
			'last_reset' => 0,
		);

		$card = InterceptDigest::local_rules_card( $cache );

		$this->assertSame( '本地规则', $card['label'] );
		$this->assertSame( '今日拦下 2 · 转人工复核 1 · 出错 0', $card['line'], '三数式（无「检查 N 条」——无数据源砍除，agent C A-3）' );
		$this->assertSame( '累计拦下 20 · 转人工复核 5 · 出错 1', $card['total_line'] );
	}
}
