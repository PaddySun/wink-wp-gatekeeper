<?php
/**
 * M5 U1 批 · 「网站」字段两独立开关纯逻辑测试（2026-11-14）。
 *
 * 设计权威：M5 定稿 §1/§0.1——A（收集开关）与 B（前台去链接开关）**完全独立、互不关联**；
 * 数据库 comment_author_url 永不删除；后台评论区始终显示网址（站长识别邻居）。
 * 键语义（定稿 §1 表格唯一权威）：
 *   comments.site_field.enabled  true=收集（默认 false=关闭收集，防 spam 采集面）；
 *   comments.site_link.enabled   true=前台保留链接（默认 false=前台去链接）。
 * 双保险（定稿 §1 A 行）：前端隐藏表单字段 ≠ 提交面无字段——preprocess_comment 层丢弃
 * comment_author_url 入参（curl 可伪造面收口）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Settings\Sanitize;
use WinkGatekeeper\Settings\SettingsSchema;
use WinkGatekeeper\Site\SiteFieldPolicy;

final class M5U1SiteFieldTest extends TestCase {

	private function defaults(): array {
		return SettingsSchema::defaults();
	}

	/* ===================================================================
	 * schema 默认树（04 §2 新子树）
	 * =================================================================== */

	public function test_schema_has_comments_subtree_with_both_switches_default_false(): void {
		$d = $this->defaults();
		self::assertArrayHasKey( 'comments', $d );
		self::assertSame( array( 'enabled' => false ), $d['comments']['site_field'], 'A 收集开关默认 false=关闭收集（定稿 §1）' );
		self::assertSame( array( 'enabled' => false ), $d['comments']['site_link'], 'B 链接开关默认 false=前台去链接（定稿 §1）' );
	}

	public function test_schema_two_switches_are_independent_subtrees(): void {
		// 定稿 §0.1：两开关完全独立——不同子树、无联动键（默认值恒等 false 是键语义
		// 使然，独立性由四象限行为测试钉住——test_two_switches_do_not_affect_each_other）。
		$d = $this->defaults();
		self::assertArrayHasKey( 'site_field', $d['comments'] );
		self::assertArrayHasKey( 'site_link', $d['comments'] );
		self::assertCount( 2, $d['comments'], 'comments 子树恰两键（site_field/site_link）——无联动键' );
	}

	public function test_sanitize_rules_cover_four_m5_keys(): void {
		$rules = Sanitize::rules();
		self::assertSame( 'bool', $rules['comments.site_field.enabled'] ?? null );
		self::assertSame( 'bool', $rules['comments.site_link.enabled'] ?? null );
	}

	public function test_sanitize_bool_cleans_site_field_enabled(): void {
		self::assertFalse( Sanitize::clean_value( '0', 'bool' ) );
		self::assertTrue( Sanitize::clean_value( '1', 'bool' ) );
	}

	/* ===================================================================
	 * SiteFieldPolicy 纯逻辑
	 * =================================================================== */

	public function test_wants_collection_false_by_default(): void {
		$settings = $this->defaults();
		self::assertFalse( SiteFieldPolicy::wants_collection( $settings ) );
	}

	public function test_wants_collection_true_when_enabled(): void {
		$settings                       = $this->defaults();
		$settings['comments']['site_field']['enabled'] = true;
		self::assertTrue( SiteFieldPolicy::wants_collection( $settings ) );
	}

	public function test_wants_frontend_link_false_by_default(): void {
		self::assertFalse( SiteFieldPolicy::wants_frontend_link( $this->defaults() ) );
	}

	public function test_two_switches_do_not_affect_each_other(): void {
		// 四象限独立性：改 A 不动 B，改 B 不动 A（定稿 §0.1 语义纠正）。
		$settings = $this->defaults();
		$settings['comments']['site_field']['enabled'] = true;
		self::assertFalse( SiteFieldPolicy::wants_frontend_link( $settings ), 'A 开不得联动 B' );

		$settings2 = $this->defaults();
		$settings2['comments']['site_link']['enabled'] = true;
		self::assertFalse( SiteFieldPolicy::wants_collection( $settings2 ), 'B 开不得联动 A' );
	}

	/* ===================================================================
	 * strip_url（双保险：preprocess_comment 层丢弃入参）
	 * =================================================================== */

	public function test_strip_url_removes_author_url_key(): void {
		$data = array(
			'comment_author'       => '路人甲',
			'comment_author_email' => 'a@b.c',
			'comment_author_url'   => 'https://spam.example.com/',
			'comment_content'      => '好文章！',
		);
		$stripped = SiteFieldPolicy::strip_url( $data );
		self::assertArrayNotHasKey( 'comment_author_url', $stripped );
		self::assertSame( '路人甲', $stripped['comment_author'], '昵称不动' );
		self::assertSame( 'a@b.c', $stripped['comment_author_email'], '邮箱不动' );
		self::assertSame( '好文章！', $stripped['comment_content'], '正文不动' );
	}

	public function test_strip_url_keeps_other_keys_intact_and_is_total(): void {
		// 无 url 键入参（登录用户代填形态）：原样返回，不新增键。
		$data = array( 'comment_author' => '乙', 'comment_content' => '沙发' );
		self::assertSame( $data, SiteFieldPolicy::strip_url( $data ) );
	}

	public function test_strip_url_does_not_touch_url_of_existing_comments(): void {
		// 存量不动=strip_url 只用于提交面入参；policy 不提供任何删除/改写存量函数。
		self::assertEmpty(
			array_filter( get_class_methods( SiteFieldPolicy::class ), static function ( string $m ): bool {
				return 1 === preg_match( '/(delete|remove|purge|clean_existing)/', $m );
			} ),
			'SiteFieldPolicy 不得提供存量删除面（comment_author_url 永不删除）'
		);
	}

	/* ===================================================================
	 * sanitize 全树保存回归（comments 子树不抹他 Tab）
	 * =================================================================== */

	public function test_sanitize_tree_passes_comments_subtree_through(): void {
		$input = array(
			'comments' => array(
				'site_field' => array( 'enabled' => '1' ),
				'site_link'  => array( 'enabled' => '1' ),
			),
		);
		list( $clean, $rejected ) = Sanitize::clean( $input );
		self::assertSame( array(), $rejected );
		self::assertTrue( $clean['comments']['site_field']['enabled'] );
		self::assertTrue( $clean['comments']['site_link']['enabled'] );
	}
}
