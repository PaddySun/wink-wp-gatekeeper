<?php
/**
 * F4-3 拦截页证据行本地化单元测试（03 §3【v1.5·F4-3】）。
 *
 * 规格：
 *   - 映射表落点=Copywork 键值表（键 wk-label-<Label>），TMS 实测定稿集 + 阿里摘录族；
 *   - 形态=驱动人类名前缀 + 标签中文 + 置信——「云端文本审核（腾讯 TMS）：辱骂违规 · 置信 100」式；
 *   - 驱动人类名由 registry type+label 派生（review「云端文本审核（{label}）」/
 *     captcha「人机验证（{label}）」/ ip「IP 信誉（{label}）」）；
 *   - 兜底：未收录标签原样显示（宁可英文不可错误中文）；nonLabel 伪标签不映射；
 *   - 边界：LocalRules 键/核心拒绝键/remote_* 等非 Label,Score 形态一律原样（不放宽）；
 *   - filter 覆盖：wink_gatekeeper_error_messages（WP 边界桥）→ label_overrides 同键覆盖文案、
 *     映射键名本身不可改。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Intercept\Copywork;

final class CopyworkLabelTest extends TestCase {

	/* ---------- 映射表（键 wk-label-<Label>） ---------- */

	public function test_cloud_labels_use_wk_label_key_prefix(): void {
		foreach ( Copywork::cloud_labels() as $key => $zh ) {
			$this->assertStringStartsWith( 'wk-label-', $key, "映射键须带 wk-label- 前缀：{$key}" );
			$this->assertIsString( $zh );
			$this->assertNotSame( '', $zh, "映射值非空：{$key}" );
		}
	}

	/**
	 * TMS 实测定稿 Label 集（03 §3 v1.5；L0-ali/L0-tms 实测 Label,Score 形态）。
	 */
	public function test_tms_labels_are_mapped(): void {
		$labels = Copywork::cloud_labels();
		$this->assertSame( '辱骂违规', $labels['wk-label-Abuse'] );
		$this->assertSame( '广告', $labels['wk-label-Ad'] );
		$this->assertSame( '色情低俗', $labels['wk-label-Porn'] );
		$this->assertSame( '涉政', $labels['wk-label-Politics'] );
		$this->assertSame( '暴恐', $labels['wk-label-Terrorism'] );
		$this->assertSame( '违禁', $labels['wk-label-Contraband'] );
		$this->assertSame( '性感的', $labels['wk-label-Sexy'] );
	}

	/**
	 * 阿里文本审核增强版摘录族（05 §2.5；驱动 M3' 批次 3 接入，映射先落位）。
	 */
	public function test_aliyun_labels_excerpt_is_mapped(): void {
		$labels = Copywork::cloud_labels();
		$this->assertSame( '疑似站外引流', $labels['wk-label-pt_to_sites'] );
		$this->assertSame( '疑似引流广告号', $labels['wk-label-pt_to_contact'] );
		$this->assertSame( '疑似网赚兼职广告', $labels['wk-label-pt_by_recruitment'] );
		$this->assertSame( '违反广告法内容', $labels['wk-label-ad_compliance'] );
		$this->assertSame( '疑似攻击辱骂', $labels['wk-label-inappropriate_profanity'] );
		$this->assertSame( '疑似色情', $labels['wk-label-pornographic_adult'] );
		$this->assertSame( '命中自定义词库', $labels['wk-label-customized'] );
		$this->assertSame( '文本疑似 AI 生成', $labels['wk-label-aigc'] );
	}

	/* ---------- 驱动人类名前缀（registry type+label 派生） ---------- */

	public function test_driver_prefix_review_type(): void {
		$this->assertSame(
			'云端文本审核（腾讯云 TMS 文本审核）',
			Copywork::driver_prefix( 'review', '腾讯云 TMS 文本审核' )
		);
	}

	public function test_driver_prefix_captcha_and_ip_types(): void {
		$this->assertSame( '人机验证（腾讯天御验证码 2.0）', Copywork::driver_prefix( 'captcha', '腾讯天御验证码 2.0' ) );
		$this->assertSame( 'IP 信誉（AbuseIPDB）', Copywork::driver_prefix( 'ip', 'AbuseIPDB' ) );
	}

	public function test_driver_prefix_unknown_type_falls_back_to_label(): void {
		$this->assertSame( '某驱动', Copywork::driver_prefix( 'unknown_type', '某驱动' ) );
	}

	/* ---------- humanize_rule：映射/兜底/边界 ---------- */

	/**
	 * 主形态：驱动人类名前缀 + 标签中文 + 置信（03 §3 v1.5 示例句式）。
	 */
	public function test_humanize_rule_maps_known_label_with_score(): void {
		$prefix = Copywork::driver_prefix( 'review', '腾讯云 TMS 文本审核' );
		$this->assertSame(
			'云端文本审核（腾讯云 TMS 文本审核）：辱骂违规 · 置信 100',
			Copywork::humanize_rule( $prefix, 'Abuse,100' )
		);
		$this->assertSame(
			'云端文本审核（腾讯云 TMS 文本审核）：广告 · 置信 99',
			Copywork::humanize_rule( $prefix, 'Ad,99' )
		);
		$this->assertSame(
			'云端文本审核（阿里文本审核增强版 PLUS）：疑似站外引流 · 置信 100',
			Copywork::humanize_rule( Copywork::driver_prefix( 'review', '阿里文本审核增强版 PLUS' ), 'pt_to_sites,100' )
		);
	}

	/**
	 * 兜底：未收录标签原样显示（esc_html 在 DiePage 边界承担——此处只保证不造错误中文）。
	 */
	public function test_humanize_rule_unknown_label_shows_label_verbatim(): void {
		$prefix = Copywork::driver_prefix( 'review', '腾讯云 TMS 文本审核' );
		$this->assertSame(
			'云端文本审核（腾讯云 TMS 文本审核）：FooBar · 置信 88',
			Copywork::humanize_rule( $prefix, 'FooBar,88' )
		);
	}

	/**
	 * nonLabel 伪标签不映射（05 §2.5：未检测出风险，不出现在拒绝理由，防御性直通）。
	 */
	public function test_humanize_rule_nonlabel_is_not_mapped(): void {
		$prefix = Copywork::driver_prefix( 'review', '腾讯云 TMS 文本审核' );
		$this->assertSame( 'nonLabel,100', Copywork::humanize_rule( $prefix, 'nonLabel,100' ) );
	}

	/**
	 * 边界（不放宽）：LocalRules 键 / 核心拒绝键 / remote_* / unknown_suggestion 等
	 * 非 Label,Score 形态一律原样返回。
	 */
	public function test_humanize_rule_passes_through_non_label_score_forms(): void {
		$prefix = Copywork::driver_prefix( 'review', '本地规则四闸（上游迁移）' );
		$this->assertSame( 'banned_word', Copywork::humanize_rule( $prefix, 'banned_word' ) );
		$this->assertSame( 'duplicate_comment', Copywork::humanize_rule( $prefix, 'duplicate_comment' ) );
		$this->assertSame( 'remote_http_error', Copywork::humanize_rule( $prefix, 'remote_http_error' ) );
		$this->assertSame( 'unknown_suggestion:Something', Copywork::humanize_rule( $prefix, 'unknown_suggestion:Something' ) );
		$this->assertSame( '', Copywork::humanize_rule( $prefix, '' ) );
	}

	/**
	 * filter 覆盖：label_overrides 同键（wk-label-<Label>）覆盖中文文案，键名集不扩不缩。
	 */
	public function test_humanize_rule_accepts_label_overrides(): void {
		$prefix = Copywork::driver_prefix( 'review', '腾讯云 TMS 文本审核' );
		$this->assertSame(
			'云端文本审核（腾讯云 TMS 文本审核）：站长定制的广告文案 · 置信 99',
			Copywork::humanize_rule( $prefix, 'Ad,99', array( 'wk-label-Ad' => '站长定制的广告文案' ) )
		);
		// override 不含该键时仍走内建映射（合并语义）。
		$this->assertSame(
			'云端文本审核（腾讯云 TMS 文本审核）：辱骂违规 · 置信 99',
			Copywork::humanize_rule( $prefix, 'Abuse,99', array( 'wk-label-Ad' => '站长定制的广告文案' ) )
		);
	}
}
