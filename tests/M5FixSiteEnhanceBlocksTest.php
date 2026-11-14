<?php
/**
 * M5 修复 followup · 区块主题作者名去链接收口回归测试（问题1）。
 *
 * 根因：WP core 区块 wp-block-comment-author-name 直接用 get_comment_author_url()
 * 拼锚点，绕过 SiteEnhance 挂的 get_comment_author_link filter——区块主题
 * （如 twentytwentyfive）下存量带网址评论的作者名仍是链接。
 * 修复：SiteEnhance 补挂 get_comment_author_url filter——前台去链接激活时返回 ''
 * （区块在 url 空时渲染纯文本名字；经典主题消费 comment_author_url 的场景同样收口）。
 *
 * 影响面注记（comment_form 回填）：前台表单 url 字段 value 来自
 * wp_get_current_commenter() 的 cookie（comment_author_url_* cookie），不走
 * get_comment_author_url filter——去链接语义下表单回填不受本 filter 影响；
 * 用户重新提交的 url 是否收集由 A 开关（site_field.enabled）决定，A 开启时
 * 表单字段照常显示（A/B 完全独立语义不变）。
 *
 * is_admin() 分支纯单测环境不可模拟（WP 函数不存在）——该分支以契约断言收口
 * （Bootstrap 注册行 + SiteEnhance 方法存在，与 filter_comment_author_link 同法）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Settings\SettingsSchema;
use WinkGatekeeper\Site\SiteEnhance;

final class M5FixSiteEnhanceBlocksTest extends TestCase {

	/**
	 * @param bool $keep_link site_link.enabled（true=前台保留链接）。
	 * @param bool $collect    site_field.enabled（true=收集网址）。
	 * @return SiteEnhance
	 */
	private function enhance( bool $keep_link, bool $collect = false ): SiteEnhance {
		$settings                    = SettingsSchema::defaults();
		$settings['comments']['site_link']['enabled']  = $keep_link;
		$settings['comments']['site_field']['enabled'] = $collect;
		return new SiteEnhance( $settings );
	}

	/* ===================================================================
	 * filter_comment_author_url 行为面（纯逻辑；is_admin 分支见契约面）
	 * =================================================================== */

	public function test_delink_on_clears_author_url_for_block_themes(): void {
		// 去链接激活（site_link.enabled=false，默认）→ url 清空——区块主题
		// core/blocks comment-author-name 拿空 url 渲染纯文本名字。
		self::assertSame( '', $this->enhance( false )->filter_comment_author_url( 'https://spam.example.com/' ) );
	}

	public function test_delink_on_clears_url_regardless_of_collection_switch(): void {
		// A/B 完全独立：A 收集开启不得让 B 去链接失效。
		self::assertSame( '', $this->enhance( false, true )->filter_comment_author_url( 'https://neighbour.example.com/' ) );
	}

	public function test_link_kept_returns_original_url(): void {
		// B 开启（前台保留链接）→ 原样返回，零行为变化。
		$url = 'https://neighbour.example.com/';
		self::assertSame( $url, $this->enhance( true )->filter_comment_author_url( $url ) );
	}

	public function test_no_url_returns_empty_unchanged(): void {
		// 无网址评论：空进空出（区块本就渲染纯文本，零变化）。
		self::assertSame( '', $this->enhance( false )->filter_comment_author_url( '' ) );
	}

	/* ===================================================================
	 * 契约面：Bootstrap 注册行 + SiteEnhance 方法存在（is_admin 分支不可模拟）
	 * =================================================================== */

	public function test_bootstrap_registers_get_comment_author_url_filter(): void {
		$src = file_get_contents( __DIR__ . '/../includes/Bootstrap.php' );
		self::assertIsString( $src );
		self::assertStringContainsString(
			"add_filter( 'get_comment_author_url', array( \$site_enhance, 'filter_comment_author_url' )",
			$src,
			'区块主题收口：get_comment_author_url filter 必须注册'
		);
		// 与既有 SiteEnhance 装配段同源（同一 $site_enhance 实例，非散装闭包）。
		self::assertMatchesRegularExpression(
			'/new SiteEnhance[\s\S]{0,600}get_comment_author_url/',
			$src,
			'注册行必须处于 SiteEnhance 装配段'
		);
	}

	public function test_site_enhance_has_author_url_filter_method(): void {
		$src = file_get_contents( __DIR__ . '/../includes/site/SiteEnhance.php' );
		self::assertIsString( $src );
		self::assertStringContainsString( 'function filter_comment_author_url', $src, 'SiteEnhance 补挂方法存在' );
		self::assertStringContainsString( 'wp-block-comment-author-name', $src, '区块绕行根因注记在场' );
	}
}
