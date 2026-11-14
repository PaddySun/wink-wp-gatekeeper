<?php
/**
 * M5 U2 批 · 头像国内镜像纯逻辑测试（2026-11-14）。
 *
 * 设计权威：M5 定稿 §2（avatar.source 五枚举 + pre_get_avatar_data 改写 host 零侵入核心）
 * + UI 设计块 1 卡二（custom 条件显隐、三态测试按钮）。
 * 默认值裁决=定稿 §2「建议 Cravatar」+[待核验] 由 L0 实测（tools/api-smoke/avatar-sources.php）
 * 收口——本测试钉枚举合法性而非具体默认值（默认值断言单列，随 L0 结论修正）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Settings\Sanitize;
use WinkGatekeeper\Settings\SettingsSchema;
use WinkGatekeeper\Site\AvatarSources;

final class M5U2AvatarTest extends TestCase {

	/* ===================================================================
	 * schema 默认树 + sanitize 规则
	 * =================================================================== */

	public function test_schema_has_avatar_subtree(): void {
		$d = SettingsSchema::defaults();
		self::assertArrayHasKey( 'avatar', $d );
		self::assertArrayHasKey( 'source', $d['avatar'] );
		self::assertArrayHasKey( 'custom_base', $d['avatar'] );
	}

	public function test_avatar_source_default_is_in_enum(): void {
		$d = SettingsSchema::defaults();
		self::assertContains( $d['avatar']['source'], AvatarSources::ENUM, '默认值必须是合法枚举' );
	}

	public function test_avatar_source_default_is_cravatar_per_design(): void {
		// 定稿 §2 默认值建议 Cravatar（兼容性最强）；L0 实测为唯一允许的改判通道。
		self::assertSame( 'cravatar', SettingsSchema::defaults()['avatar']['source'] );
	}

	public function test_custom_base_default_empty(): void {
		self::assertSame( '', SettingsSchema::defaults()['avatar']['custom_base'] );
	}

	public function test_sanitize_rules_cover_avatar_keys(): void {
		$rules = Sanitize::rules();
		self::assertSame( 'avatar_source', $rules['avatar.source'] ?? null, 'avatar.source 用白名单枚举型' );
		self::assertSame( 'url', $rules['avatar.custom_base'] ?? null );
	}

	public function test_sanitize_avatar_source_illegal_falls_back_official(): void {
		self::assertSame( 'cravatar', Sanitize::clean_value( 'cravatar', 'avatar_source' ) );
		self::assertSame( 'weavatar', Sanitize::clean_value( 'weavatar', 'avatar_source' ) );
		self::assertSame( 'sepcc', Sanitize::clean_value( 'sepcc', 'avatar_source' ) );
		self::assertSame( 'custom', Sanitize::clean_value( 'custom', 'avatar_source' ) );
		self::assertSame( 'official', Sanitize::clean_value( 'official', 'avatar_source' ) );
		// 非法值归 official（fail-safe：改写面全关=现行 WP 行为零变化）。
		self::assertSame( 'official', Sanitize::clean_value( 'evil', 'avatar_source' ) );
		self::assertSame( 'official', Sanitize::clean_value( array(), 'avatar_source' ) );
	}

	/* ===================================================================
	 * AvatarSources 纯逻辑
	 * =================================================================== */

	public function test_enum_exactly_five_sources(): void {
		self::assertSame( array( 'official', 'cravatar', 'weavatar', 'sepcc', 'custom' ), AvatarSources::ENUM );
	}

	public function test_base_for_official_is_null(): void {
		self::assertNull( AvatarSources::base_for( 'official', '' ), 'official=不镜像（返回 null，调用方零改写）' );
	}

	public function test_base_for_three_builtin_mirrors(): void {
		self::assertSame( 'https://cravatar.com/avatar/', AvatarSources::base_for( 'cravatar', '' ) );
		self::assertSame( 'https://weavatar.com/avatar/', AvatarSources::base_for( 'weavatar', '' ) );
		self::assertSame( 'https://cdn.sep.cc/avatar/', AvatarSources::base_for( 'sepcc', '' ) );
	}

	public function test_base_for_custom_uses_custom_base_when_valid(): void {
		self::assertSame( 'https://mir.example.com/avatar/', AvatarSources::base_for( 'custom', 'https://mir.example.com/avatar/' ) );
	}

	public function test_base_for_custom_invalid_base_returns_null(): void {
		// custom 选了但地址非法（http / 无 /avatar/ 尾）→ fail-safe 不镜像（official 行为）。
		self::assertNull( AvatarSources::base_for( 'custom', 'http://mir.example.com/avatar/' ), 'https 红线' );
		self::assertNull( AvatarSources::base_for( 'custom', 'https://mir.example.com/' ), '须以 /avatar/ 结尾' );
		self::assertNull( AvatarSources::base_for( 'custom', '' ), '空地址不镜像' );
	}

	public function test_custom_base_valid(): void {
		self::assertTrue( AvatarSources::custom_base_valid( 'https://mir.example.com/avatar/' ) );
		self::assertFalse( AvatarSources::custom_base_valid( 'http://mir.example.com/avatar/' ) );
		self::assertFalse( AvatarSources::custom_base_valid( 'https://mir.example.com/' ) );
		self::assertFalse( AvatarSources::custom_base_valid( 'https://mir.example.com/avatar' ), '须带尾斜杠' );
		self::assertFalse( AvatarSources::custom_base_valid( '' ) );
	}

	public function test_mirror_url_rewrites_host_keeps_path_and_query(): void {
		$src  = 'https://secure.gravatar.com/avatar/205e460b73a4ee1a4a9d1a67d5f4a1ab?s=96&d=mm&r=pg';
		$base = 'https://cravatar.com/avatar/';
		$mir  = AvatarSources::mirror_url( $src, $base );
		self::assertSame( 'https://cravatar.com/avatar/205e460b73a4ee1a4a9d1a67d5f4a1ab?s=96&d=mm&r=pg', $mir );
	}

	public function test_mirror_url_handles_numbered_gravatar_hosts(): void {
		$src = 'https://0.gravatar.com/avatar/abc?s=48';
		self::assertSame( 'https://weavatar.com/avatar/abc?s=48', AvatarSources::mirror_url( $src, 'https://weavatar.com/avatar/' ) );
	}

	public function test_mirror_url_without_avatar_path_returns_null(): void {
		self::assertNull( AvatarSources::mirror_url( 'https://secure.gravatar.com/other/x?s=96', 'https://cravatar.com/avatar/' ) );
		self::assertNull( AvatarSources::mirror_url( 'not a url', 'https://cravatar.com/avatar/' ) );
	}

	public function test_mirror_url_https_only(): void {
		self::assertNull( AvatarSources::mirror_url( 'http://secure.gravatar.com/avatar/abc', 'https://cravatar.com/avatar/' ), 'http 源不镜像（改写面 https 红线）' );
	}

	/* ===================================================================
	 * 三态测试判定（UI 卡二：绿=200+图片 / 黄=慢>3s / 红=连不上或非图片）
	 * =================================================================== */

	public function test_judge_three_states(): void {
		self::assertSame( 'ok', AvatarSources::judge( 200, 'image/png', 0.4 ) );
		self::assertSame( 'slow', AvatarSources::judge( 200, 'image/png', 3.5 ), '>3s=能用但慢（黄）' );
		self::assertSame( 'fail', AvatarSources::judge( 0, '', 1.0 ), '连接失败（红）' );
		self::assertSame( 'fail', AvatarSources::judge( 404, 'text/html', 0.5 ), '非 200（红）' );
		self::assertSame( 'fail', AvatarSources::judge( 200, 'text/html', 0.5 ), '非图片 Content-Type（红）' );
		self::assertSame( 'ok', AvatarSources::judge( 200, 'image/jpeg', 3.0 ), '阈值边界 3.0s=正常（UI 卡二：>3s 才黄）' );
	}

	public function test_judge_boundary_slow_threshold_is_three_seconds(): void {
		self::assertSame( 'ok', AvatarSources::judge( 200, 'image/png', 2.9 ) );
	}
}
