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
 * 【W3·批A 后缀翻转注记】本批 admin-brand.js 内容变化（N7 scheme 前端兜底/校验
 * 小段——design-system §02·P4 前端侧）→ 后缀 -m6b1 → **-w3a1**；并新增 CSS
 * 注册行断言（admin-settings.css 版本参数=WINK_GATEKEEPER_VERSION 裸值形态钉——
 * CSS 内容变化不批内加缀，0.8.5 进位一次击穿〔施工图 §5 风险 3 口径〕）。
 *
	 * 【W3·批C 后缀翻转注记】本批 admin-brand.js 内容变化（首启向导交互模块——stepper
	 * 显隐/radio-card .on 同步/下一步 referer 改写/驱动·云审卡面显隐）→ 后缀 -w3b1 →
	 * **-w3c1**（断言翻转非语义变化——「本文件内容变化必须伴随版本参数变化」纪律本体
	 * 不变；0.8.5 进位一次击穿仍在批 D 收口）。
	 *
 * 【W3-FIX 后缀翻转注记】本批 admin-brand.js 内容变化（测试凭据徽标全量回写共享件
 * wkProbeWriteBadges + 九个 probe log 闭集驱动探活接线——W3F2 手测缺陷①）→ 后缀
 * -w3c1 → **-i3a1**（断言翻转非语义变化——纪律本体不变；0.8.6 进位随本批一次击穿）。
 *
 * 【i18n 批 I-4a 后缀退役注记】0.9.0 milestone 进位（0.8.7→0.9.0）——admin-brand.js
 * 注册行版本参数 **-i3a1 后缀退役，收敛为裸 WINK_GATEKEEPER_VERSION**（0.9.0 一次
 * 击穿 CDN 缓存；M5FIX 纪律本体不变：本文件内容再变化时须 bump 进位或重新加缀，
 * 届时翻转本钉——「bump 时统一」先例=0.8.5 批 D CSS 收口同款）。
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

		// admin-brand.js 注册行：版本参数 = 裸 WINK_GATEKEEPER_VERSION
		// （【i18n 批 I-4a 后缀退役】0.9.0 milestone 进位一次击穿——0.8.7-i3a1 → 0.9.0；
		// 窗幅 1600→2400：注册行注释段历史击穿语义注记+I-4a 退役注记累加后加长）。
		self::assertMatchesRegularExpression(
			"/wink-gatekeeper-admin-brand[\s\S]{0,2400}WINK_GATEKEEPER_VERSION,\s*true/s",
			$src,
			'admin-brand.js 注册行版本参数=裸 WINK_GATEKEEPER_VERSION（i18n 批 I-4a 版本进位 0.9.0——后缀退役一次击穿）'
		);
		// 【M5FIX】CDN 击穿语义注记在场（纪律传承——下次改 JS 必须再动版本参数）。
		self::assertStringContainsString( 'M5FIX', $src, 'CDN 击穿语义注记' );
	}

	public function test_front_assets_version_untouched(): void {
		// 【i18n 批 I-4a 后缀退役】击穿面收敛：全文零「版本常量拼接后缀」形态
		// （-i3a1 已随 0.9.0 进位退役；再需批内击穿时重新加缀并翻转本钉——
		// M5FIX 纪律本体不变）。front 注册行（gatekeeper.js 等）照旧裸版本值。
		$src = file_get_contents( __DIR__ . '/../includes/Bootstrap.php' );
		self::assertIsString( $src );
		self::assertSame(
			0,
			preg_match_all( "/WINK_GATEKEEPER_VERSION \\. '/", $src ),
			'全文零版本常量后缀拼接（i3a1 后缀已随 0.9.0 进位退役——admin-brand 与 front 资产同收敛为裸版本值）'
		);
	}

	/**
	 * 【W3·批A 新增】admin-settings.css 注册行版本参数形态钉：裸 WINK_GATEKEEPER_VERSION
	 * （无内联后缀）——批 A 改 CSS 内容（N7 token 双组化）但批内不 bump 不加缀，
	 * 缓存击穿随 0.8.5 进位一次收口（施工图 §5 风险 3 / R9 口径）；若中途热修发版
	 * 则该批须同步 bump 或加 CSS 内联后缀（checklist 强制核对项）。
	 */
	public function test_admin_css_enqueue_plain_version(): void {
		$src = file_get_contents( __DIR__ . '/../includes/Bootstrap.php' );
		self::assertIsString( $src );
		self::assertMatchesRegularExpression(
			"/'wink-gatekeeper-admin',\s*\\\$this->base_url \. 'assets\/css\/admin-settings\.css',\s*array\(\),\s*WINK_GATEKEEPER_VERSION\s*\)/",
			$src,
			'admin-settings.css 注册行版本参数=WINK_GATEKEEPER_VERSION 裸值（无内联后缀——0.8.5 进位一次击穿口径）'
		);
	}
}
