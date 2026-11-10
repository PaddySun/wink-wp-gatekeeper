<?php
/**
 * B1 站长自定义拦截页文案单元测试（03 Tab⑤【v1.5·WORDFENCE-ADOPTION B1】）。
 *
 * 规格：
 *   - 新键 copy.die_page_title（input）/ copy.die_page_message（textarea），默认空串；
 *   - 空值=回落对应场景默认文案（既有键与默认集零改动）；
 *   - 占位符体系同享（{event_no}/{rule}/{time}/{contact_email}）；
 *   - 作用于品牌整页拦截页标题与正文首段（全部场景共用同一覆盖位）；
 *   - 非字符串/纯空白输入 = 视同空值（回落默认）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Intercept\DiePage;

final class DiePageCustomCopyTest extends TestCase {

	private const VARS = array(
		'event_no' => 'WK-20261111-0001',
		'rule'     => 'banned_word',
		'time'     => '2026-11-11 10:00:00',
	);

	/* ---------- Copywork::custom_defaults（schema 单一来源） ---------- */

	public function test_custom_defaults_are_empty_strings(): void {
		$this->assertSame(
			array( 'die_page_title' => '', 'die_page_message' => '' ),
			Copywork::custom_defaults()
		);
	}

	public function test_rule_defaults_are_untouched(): void {
		// 既有键与默认集零改动（B1 条款）。
		$defaults = Copywork::defaults();
		$this->assertArrayNotHasKey( 'die_page_title', $defaults['messages'] );
		$this->assertArrayNotHasKey( 'die_page_message', $defaults['titles'] );
		$this->assertNotContains( 'die_page_title', Copywork::keys() );
	}

	/* ---------- DiePage::custom_copy（归一） ---------- */

	public function test_custom_copy_empty_tree_yields_empty_strings(): void {
		$this->assertSame(
			array( 'die_page_title' => '', 'die_page_message' => '' ),
			DiePage::custom_copy( array() )
		);
	}

	public function test_custom_copy_rejects_non_string_and_whitespace_only(): void {
		$this->assertSame(
			array( 'die_page_title' => '', 'die_page_message' => '' ),
			DiePage::custom_copy( array( 'die_page_title' => 123, 'die_page_message' => "  \n\t " ) )
		);
	}

	public function test_custom_copy_keeps_trimmed_strings(): void {
		$this->assertSame(
			array( 'die_page_title' => '自定义标题', 'die_page_message' => '自定义正文' ),
			DiePage::custom_copy( array( 'die_page_title' => ' 自定义标题 ', 'die_page_message' => '自定义正文' ) )
		);
	}

	/* ---------- DiePage::resolve_page_text（默认解析 + 自定义覆盖） ---------- */

	public function test_resolve_page_text_without_custom_uses_scene_defaults(): void {
		$page = DiePage::resolve_page_text( 'banned_word', array(), self::VARS );
		$this->assertSame( Copywork::defaults()['titles']['banned_word'], $page['title'] );
		$this->assertSame( Copywork::defaults()['messages']['banned_word'], $page['message'] );
	}

	/**
	 * 存量 option 防呆（resolve_copy 语义沿袭）：缺键回默认、非字符串剔除。
	 */
	public function test_resolve_page_text_backfills_missing_scene_keys(): void {
		$page = DiePage::resolve_page_text( 'duplicate_comment', array(
			'messages' => array( 'too_short' => '站长自定义' ),
			'titles'   => array( 'too_short' => 123 ),
		), self::VARS );
		$this->assertSame( Copywork::defaults()['titles']['duplicate_comment'], $page['title'] );
		$this->assertSame( Copywork::defaults()['messages']['duplicate_comment'], $page['message'] );
	}

	public function test_resolve_page_text_custom_overrides_title_and_message(): void {
		$copy = array(
			'die_page_title'   => '你被拦下了',
			'die_page_message' => '事件 {event_no}：规则 {rule}，时间 {time}。有疑问联系 {contact_email}',
		);
		$page = DiePage::resolve_page_text( 'banned_word', $copy, array_merge( self::VARS, array(
			'contact_email' => 'webmaster@example.com',
		) ) );

		$this->assertSame( '你被拦下了', $page['title'] );
		$this->assertSame(
			'事件 WK-20261111-0001：规则 banned_word，时间 2026-11-11 10:00:00。有疑问联系 webmaster@example.com',
			$page['message']
		);
	}

	/**
	 * 覆盖位全场景共用：同一自定义文案对任何 copy_key 生效。
	 */
	public function test_resolve_page_text_custom_applies_across_scenes(): void {
		$copy = array( 'die_page_title' => '统一标题' );
		foreach ( Copywork::keys() as $key ) {
			$page = DiePage::resolve_page_text( $key, $copy, self::VARS );
			$this->assertSame( '统一标题', $page['title'], "场景 {$key} 应被自定义标题覆盖" );
		}
	}

	/**
	 * 空值/非字符串/纯空白 = 回落场景默认（B1 条款的核心面）。
	 */
	public function test_resolve_page_text_blank_custom_falls_back_to_scene_defaults(): void {
		$copy = array( 'die_page_title' => '   ', 'die_page_message' => 0 );
		$page = DiePage::resolve_page_text( 'captcha_failed', $copy, array_merge( self::VARS, array(
			'contact_email' => 'webmaster@example.com',
		) ) );
		$this->assertSame( Copywork::defaults()['titles']['captcha_failed'], $page['title'] );
		$this->assertSame(
			Copywork::format( Copywork::defaults()['messages']['captcha_failed'], array_merge( self::VARS, array(
				'contact_email' => 'webmaster@example.com',
			) ) ),
			$page['message']
		);
	}

	/**
	 * 缺失占位符保留原文（format 语义沿袭——预览/漏传变量不产生空洞）。
	 */
	public function test_resolve_page_text_custom_keeps_missing_placeholders_literal(): void {
		$page = DiePage::resolve_page_text( 'banned_word', array(
			'die_page_message' => '事件 {event_no} 联系 {contact_email}',
		), self::VARS );
		$this->assertSame( '事件 WK-20261111-0001 联系 {contact_email}', $page['message'] );
	}
}
