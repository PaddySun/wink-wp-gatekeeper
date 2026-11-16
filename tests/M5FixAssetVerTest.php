<?php
/**
 * M5 修复 followup · admin 资产 CDN 击穿版本键回归测试（问题2）。
 *
 * 根因：admin-brand.js 的 enqueue 版本键 = WINK_GATEKEEPER_VERSION（0.6.2）不随
 * 修复批变化——EdgeOne CDN 按 URL 缓存旧 JS，实站改动击不穿缓存。
 * 修复：仅 admin-brand.js 注册行版本参数追加击穿后缀（内容变化必须伴随
 * 版本参数变化——Bootstrap 注册行 docblock【M5FIX】注记该纪律）。
 *
 * 契约形态：内联后缀（非全局常量）——与既有逐注册行 WINK_GATEKEEPER_VERSION
 * 风格一致；front 资产零内容变化不加缀（击穿面最小化）。
 *
 * 【M6·批B 后缀翻转注记】本批 admin-brand.js 内容变化（网址核查独立凭据
 * 「测试凭据」按钮绑定）→ 后缀 -m5fix1 → **-m6b1**（断言翻转非语义变化——
 * 「本文件内容变化必须伴随版本参数变化」纪律本体不变）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;

final class M5FixAssetVerTest extends TestCase {

	public function test_admin_brand_enqueue_carries_bust_suffix(): void {
		$src = file_get_contents( __DIR__ . '/../includes/Bootstrap.php' );
		self::assertIsString( $src );

		// admin-brand.js 注册行：版本参数 = WINK_GATEKEEPER_VERSION . '-m6b1'。
		self::assertMatchesRegularExpression(
			"/wink-gatekeeper-admin-brand[\s\S]{0,700}WINK_GATEKEEPER_VERSION \. '-m6b1'/",
			$src,
			'admin-brand.js 注册行版本参数必须带 -m6b1 击穿后缀（【M6·批B 后缀翻转】-m5fix1→-m6b1）'
		);
		// 【M5FIX】CDN 击穿语义注记在场（纪律传承——下次改 JS 必须再动版本参数）。
		self::assertStringContainsString( 'M5FIX', $src, 'CDN 击穿语义注记' );
	}

	public function test_front_assets_version_untouched(): void {
		// 击穿面最小化：front 注册行（gatekeeper.js / tencent.js）不带后缀——
		// front 资产零内容变化（改了反而全量击穿缓存徒增流量）。
		$src = file_get_contents( __DIR__ . '/../includes/Bootstrap.php' );
		self::assertIsString( $src );
		self::assertSame(
			1,
			preg_match_all( "/WINK_GATEKEEPER_VERSION \. '-m6b1'/", $src ),
			'-m6b1 全文恰出现一次（仅 admin-brand 注册行）'
		);
	}
}
