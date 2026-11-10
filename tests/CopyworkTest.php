<?php
/**
 * Copywork 文案单一来源单元测试（03 §Tab⑤、04 §2 copy 子树）。
 *
 * 规格：keys() 为八键 × messages/titles 两组的唯一权威来源；
 * 占位符体系 {min_length}/{max_length}/{event_no}/{rule}/{time}/{contact_email}；
 * format() 只替换提供了的占位符，缺失的保留原文。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Intercept\Copywork;

final class CopyworkTest extends TestCase {

	/**
	 * 03 Tab⑤ 规则键清单：上游 4 条迁移 + 本项目新增 4 条 + M2 反馈2 T2 核心拒绝 2 条。
	 */
	private const EXPECTED_KEYS = array(
		'too_short',
		'too_long',
		'no_chinese',
		'banned_word',
		'captcha_failed',
		'captcha_missing',
		'ip_malicious',
		'cloud_error',
		'duplicate_comment',
		'comment_flood',
	);

	public function test_keys_is_the_canonical_eight_key_list(): void {
		$this->assertSame( self::EXPECTED_KEYS, Copywork::keys() );
	}

	public function test_keys_returns_fresh_copy(): void {
		$keys      = Copywork::keys();
		$keys['x'] = 'tamper';
		$this->assertSame( self::EXPECTED_KEYS, Copywork::keys() );
	}

	public function test_placeholders_lists_all_six(): void {
		$this->assertSame(
			array( 'min_length', 'max_length', 'event_no', 'rule', 'time', 'contact_email' ),
			Copywork::placeholders()
		);
	}

	/**
	 * 默认文案集：messages / titles 两组，键集与 keys() 完全一致，且全部非空。
	 */
	public function test_defaults_cover_both_groups_with_exact_key_set(): void {
		$defaults = Copywork::defaults();

		$this->assertSame( array( 'messages', 'titles' ), array_keys( $defaults ) );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $defaults['messages'] ) );
		$this->assertSame( self::EXPECTED_KEYS, array_keys( $defaults['titles'] ) );

		foreach ( $defaults['messages'] as $key => $text ) {
			$this->assertIsString( $text, "messages.{$key} 应为字符串" );
			$this->assertNotSame( '', $text, "messages.{$key} 不应为空" );
		}
		foreach ( $defaults['titles'] as $key => $text ) {
			$this->assertNotSame( '', $text, "titles.{$key} 不应为空" );
		}
	}

	/**
	 * 上游迁移键保留 {min_length}/{max_length} 占位符（05 §2.1）。
	 */
	public function test_length_rules_keep_length_placeholders(): void {
		$messages = Copywork::defaults()['messages'];
		$this->assertStringContainsString( '{min_length}', $messages['too_short'] );
		$this->assertStringContainsString( '{max_length}', $messages['too_short'] );
		$this->assertStringContainsString( '{min_length}', $messages['too_long'] );
		$this->assertStringContainsString( '{max_length}', $messages['too_long'] );
	}

	/**
	 * 03 Tab⑤：{contact_email} 出现在 captcha_failed / ip_malicious 默认文案的「联系站长」处。
	 */
	public function test_contact_email_placeholder_used_where_expected(): void {
		$messages = Copywork::defaults()['messages'];
		$this->assertStringContainsString( '{contact_email}', $messages['captcha_failed'] );
		$this->assertStringContainsString( '{contact_email}', $messages['ip_malicious'] );
	}

	/**
	 * M2 反馈2 T2：核心拒绝两键默认文案（台阶语气：重复评论=手滑提示，过快=调侃+下一步）。
	 */
	public function test_core_rejection_defaults_carry_steps_copy(): void {
		$messages = Copywork::defaults()['messages'];
		$this->assertStringContainsString( '刚刚已经发过一遍', $messages['duplicate_comment'] );
		$this->assertStringContainsString( '返回', $messages['duplicate_comment'] );
		$this->assertStringContainsString( '稍等几秒', $messages['comment_flood'] );

		$titles = Copywork::defaults()['titles'];
		$this->assertNotSame( '', $titles['duplicate_comment'] );
		$this->assertNotSame( '', $titles['comment_flood'] );
	}

	public function test_format_replaces_provided_placeholders(): void {
		$this->assertSame(
			'控制在 2–1800 字之间',
			Copywork::format( '控制在 {min_length}–{max_length} 字之间', array( 'min_length' => 2, 'max_length' => 1800 ) )
		);
	}

	public function test_format_replaces_all_six_placeholders(): void {
		$result = Copywork::format(
			'{event_no} {rule} {time} {contact_email} {min_length} {max_length}',
			array(
				'event_no'      => 'WK-20261109-0042',
				'rule'          => 'banned_word',
				'time'          => '2026-11-09 21:33:05',
				'contact_email' => 'admin@example.com',
				'min_length'    => 2,
				'max_length'    => 1800,
			)
		);
		$this->assertSame( 'WK-20261109-0042 banned_word 2026-11-09 21:33:05 admin@example.com 2 1800', $result );
	}

	/**
	 * 缺失占位符（vars 未提供）保留原文——编辑框预览/漏传变量时不产生空洞。
	 */
	public function test_format_keeps_missing_placeholders_literal(): void {
		$this->assertSame(
			'命中 {rule}，时间 {time}',
			Copywork::format( '命中 {rule}，时间 {time}', array() )
		);
	}

	public function test_format_only_replaces_given_vars(): void {
		$this->assertSame( '1 {b}', Copywork::format( '{a} {b}', array( 'a' => '1' ) ) );
	}

	/**
	 * 非标量变量值不参与替换（占位符保留原文）。
	 */
	public function test_format_ignores_non_scalar_vars(): void {
		$this->assertSame( '{x} ok', Copywork::format( '{x} ok', array( 'x' => array( 'nested' ) ) ) );
	}

	public function test_format_without_any_placeholder_is_untouched(): void {
		$this->assertSame( '还差一个眨眼。', Copywork::format( '还差一个眨眼。', array( 'min_length' => 2 ) ) );
	}
}
