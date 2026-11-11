<?php
/**
 * FIX-3 契约测试（2026-11-11 用户人工测试反馈：Tab③ tab=review 下拉选「阿里云内容安全」
 * 但下方配置区仍是「腾讯云 TMS 文本审核 · 配置」——根因=Tab③ 配置区按 chain[0] 互斥
 * if/elseif 单区渲染，前端切换无区可显）。
 *
 * 修复契约面（对齐 Tab② captcha 正确形态）：
 *   ① Tab③ 全区渲染：三厂商配置区（tencent_tms/aliyun_green/baidu）恒渲染 +
 *     data-wk-vendor 属性（与 Tab② 761/911 行同形态）+ PHP 初始 hidden 按
 *     $cloud_selected（chain[0]）；互斥 if/elseif 分支除名；
 *     $cloud_selected===''（未配置）三区全 hidden + 空态说明行
 *     （#wk-review-unconfigured-note，对齐 Tab② #wk-captcha-unconfigured-note 形态）。
 *   ② 保护现行为：三区 sanitize 保存链 / 「测试凭据」AJAX 按钮 / PIPL 合规行
 *     （registry privacy 维）/ 接入指引 details / 链路汇总行在各区内原样保留。
 *   ③ admin-brand.js 泛化：Tab② 专属 syncCaptchaArea 泛化公用 bindVendorSwitcher
 *     （select + area 容器作用域 [data-wk-vendor]），Tab②③ 各挂一处 change；
 *     unconfiguredNote 联动参数化；必填缺失弹窗（closest form 拦截）推广 Tab③
 *     ——必填面=TMS SecretId/SecretKey、阿里 AccessKey 对、百度 API Key/Secret Key
 *     （05 §1.2/§2.4/§2.5 registry settings.required 语义渲染面）；Tab② 现行为零变化。
 *   ④ 版本 0.3.7（FIX 轮 3）：插件头/常量/readme Stable tag/Changelog 四处。
 *
 * 方法论与 FrontendJsContractTest 同一「源码契约测试」（纯 PHP 零 WP 依赖），
 * 行为面由真机自测承载。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;

final class Fix3Tab3ToggleContractTest extends TestCase {

	private static function source( string $rel ): string {
		$src = file_get_contents( __DIR__ . '/../' . $rel );
		self::assertIsString( $src, "{$rel} 必须可读" );
		return (string) $src;
	}

	private static function settings_src(): string {
		return self::source( 'includes/settings/SettingsPage.php' );
	}

	private static function admin_js(): string {
		return self::source( 'assets/js/admin-brand.js' );
	}

	/**
	 * 提取具名函数/方法体（花括号配平）——契约断言按作用域收窄，防全局误命中。
	 */
	private static function functionBody( string $src, string $name ): string {
		$start = strpos( $src, 'function ' . $name );
		self::assertNotFalse( $start, "函数 {$name} 必须存在" );
		$open  = strpos( $src, '{', $start );
		$depth = 0;
		$len   = strlen( $src );
		for ( $i = $open; $i < $len; $i++ ) {
			if ( '{' === $src[ $i ] ) {
				$depth++;
			} elseif ( '}' === $src[ $i ] ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $src, $open, $i - $open + 1 );
				}
			}
		}
		$this->fail( "函数 {$name} 花括号不配平" );
		return '';
	}

	private static function reviewTabBody(): string {
		return self::functionBody( self::settings_src(), 'render_tab_review' );
	}

	/* ---------- ① Tab③ 全区渲染（对齐 Tab② data-wk-vendor 形态） ---------- */

	public function test_review_tab_renders_three_vendor_areas_with_vendor_attr(): void {
		$body = self::reviewTabBody();
		$this->assertStringContainsString( 'id="wk-tms-config-area" data-wk-vendor="tencent_tms"', $body, 'TMS 区带 vendor 属性（现 id 保留+加属性）' );
		$this->assertStringContainsString( 'data-wk-vendor="aliyun_green"', $body, '阿里 PLUS 区带 vendor 属性' );
		$this->assertStringContainsString( 'data-wk-vendor="baidu"', $body, '百度区带 vendor 属性' );
		// 初始 hidden 按 $cloud_selected（chain[0]）——三区各自 PHP 判定（Tab② 同款 echo 形态）。
		$this->assertStringContainsString( "( 'tencent_tms' === \$cloud_selected ) ? '' : ' hidden'", $body );
		$this->assertStringContainsString( "( 'aliyun_green' === \$cloud_selected ) ? '' : ' hidden'", $body );
		$this->assertStringContainsString( "( 'baidu' === \$cloud_selected ) ? '' : ' hidden'", $body );
	}

	public function test_review_tab_mutually_exclusive_branches_removed(): void {
		$body = self::reviewTabBody();
		$this->assertStringNotContainsString( 'if ( $tms_in_chain ) :', $body, '互斥 if 分支已除名（病株：只渲染 chain[0] 一个区）' );
		$this->assertStringNotContainsString( 'elseif ( $green_in_chain ) :', $body, '互斥 elseif 分支已除名' );
		$this->assertStringNotContainsString( 'elseif ( $baidu_in_chain ) :', $body, '互斥 elseif 分支已除名' );
		// 旧「else 空态整卡」一并除名（未配置语义由说明行承载）。
		$this->assertStringNotContainsString( '选择驱动并保存后，此处出现该驱动的配置区', $body );
	}

	public function test_review_tab_unconfigured_note_row_aligned_with_tab2(): void {
		$body = self::reviewTabBody();
		$this->assertStringContainsString( 'id="wk-review-unconfigured-note"', $body, '空态说明行 id（对齐 Tab② wk-captcha-unconfigured-note 命名形态）' );
		$this->assertStringContainsString( 'wk-dot wk-dot--alert', $body, '空态说明行带状态点（Tab② unconfiguredNote 同形态）' );
		// 未配置（$cloud_selected===''）说明行可见，已选时 hidden——JS 切换联动同款 class。
		$this->assertStringContainsString( "'' !== \$cloud_selected ? ' hidden' : ''", $body );
	}

	/* ---------- ② 保护现行为：三区内既有件原样保留 ---------- */

	public function test_review_tab_protected_behaviors_intact(): void {
		$body = self::reviewTabBody();
		// 三区「测试凭据」AJAX 按钮各一（nonce 属性在按钮上）。
		$this->assertSame( 1, substr_count( $body, 'id="wk-tms-test-credentials"' ) );
		$this->assertSame( 1, substr_count( $body, 'id="wk-green-test-credentials"' ) );
		$this->assertSame( 1, substr_count( $body, 'id="wk-baidu-test-credentials"' ) );
		// 逐驱动 PIPL 合规行（registry privacy 维渲染）三区各一。
		$this->assertSame( 3, substr_count( $body, 'DriverConfigSpec::privacy_notice(' ), '合规行三区各一（v1.4·反馈9 迁入各配置栏不回退）' );
		// 接入指引折叠三区各一。
		$this->assertSame( 3, substr_count( $body, '<details class="wk-guide">' ) );
		// 链路汇总行（v1.4 集中位降级形态）保留。
		$this->assertStringContainsString( 'DriverConfigSpec::chain_summary( $chain', $body );
		// 必填渲染面（registry settings.required 语义）：TMS CAM 对 / 阿里 AK 对 / 百度键对。
		$this->assertStringContainsString( "password_field( array( 'review', 'cloud', 'tencent_tms', 'secret_id' )", $body );
		$this->assertStringContainsString( "password_field( array( 'review', 'cloud', 'tencent_tms', 'secret_key' )", $body );
		$this->assertStringContainsString( "password_field( array( 'review', 'cloud', 'aliyun_green', 'access_key_id' )", $body );
		$this->assertStringContainsString( "password_field( array( 'review', 'cloud', 'aliyun_green', 'access_key_secret' )", $body );
		$this->assertStringContainsString( "password_field( array( 'review', 'cloud', 'baidu', 'api_key' )", $body );
		$this->assertStringContainsString( "password_field( array( 'review', 'cloud', 'baidu', 'secret_key' )", $body );
		// 三区字段 name 路径原样（sanitize 保存链不动）。
		$this->assertStringContainsString( "'review', 'cloud', 'tencent_tms', 'reject_score'", $body );
		$this->assertStringContainsString( "'review', 'cloud', 'aliyun_green', 'dual_dispatch'", $body );
		$this->assertStringContainsString( "'review', 'cloud', 'baidu', 'daily_quota'", $body );
	}

	/* ---------- ③ admin-brand.js 泛化 + Tab②③ 两处挂接 ---------- */

	public function test_admin_js_generalized_vendor_switcher_bound_to_both_tabs(): void {
		$js = self::admin_js();
		$this->assertStringContainsString( 'function bindVendorSwitcher( select, noteId )', $js, '公用函数（参数：select 元素+未配置说明行 id）' );
		$this->assertSame( 2, substr_count( $js, 'bindVendorSwitcher( document.getElementById' ), 'Tab②③ 各挂一处' );
		$this->assertStringContainsString( "'wk-captcha-driver'", $js, 'Tab② 挂接保持' );
		$this->assertStringContainsString( "'wk-cloud-driver'", $js, 'Tab③ 挂接（FIX-3 主修）' );
		$this->assertStringContainsString( "'wk-captcha-unconfigured-note'", $js, 'Tab② unconfiguredNote 联动保持（零变化）' );
		$this->assertStringContainsString( "'wk-review-unconfigured-note'", $js, 'Tab③ unconfiguredNote 联动（参数化）' );
		// change 监听 + 初始同步各在公用函数内。
		$body = self::functionBody( $js, 'bindVendorSwitcher' );
		$this->assertStringContainsString( "addEventListener( 'change'", $body );
		$this->assertStringContainsString( 'syncAreas();', $body, '挂载即按 PHP 初始态校准一次' );
		$this->assertStringContainsString( "classList.toggle( 'hidden', vendorSelected )", $body, 'unconfiguredNote 联动（Tab② 现形态泛化）' );
		$this->assertStringContainsString( "area.hidden = area.getAttribute( 'data-wk-vendor' ) !== select.value;", $body, '区域显隐=vendor 匹配选中值（Tab② 现形态泛化）' );
		// Tab② 专属旧函数名除名（泛化完成）。
		$this->assertStringNotContainsString( 'syncCaptchaArea', $js );
		$this->assertStringNotContainsString( 'captchaMissingFields', $js );
		$this->assertStringNotContainsString( "getElementById( 'wk-captcha-config-area' )", $js, '死变量清除（旧单区缓存）' );
	}

	public function test_admin_js_required_missing_alert_covers_review(): void {
		$js   = self::admin_js();
		$body = self::functionBody( $js, 'bindVendorSwitcher' );
		// 必填扫描走公用函数（可见区 [data-wk-required]；password 已配置留空不算缺）。
		$this->assertStringContainsString( '[data-wk-required="1"]', $body );
		$this->assertStringContainsString( 'data-wk-configured', $body );
		$this->assertStringContainsString( "'password' === field.type", $body, 'password 已配置且留空=不算缺（「留空保存则不修改」契约）' );
		// closest form 拦截同款（弹窗推广 Tab③）。
		$this->assertStringContainsString( "closest( 'form' )", $body );
		$this->assertStringContainsString( 'wk-field-missing', $body, '缺失字段标红（Tab② 现形态）' );
		$this->assertStringContainsString( '缺少：', $body, '必填缺失弹窗文案（Tab②③ 同款）' );
	}

	public function test_admin_js_area_scope_is_panel_scoped(): void {
		$js   = self::admin_js();
		$body = self::functionBody( $js, 'bindVendorSwitcher' );
		$this->assertStringContainsString( "closest( '.wk-panel' )", $body, 'area 容器作用域=select 所属面板（Tab 页隔离，防跨 Tab 误显隐）' );
		$this->assertStringContainsString( "querySelectorAll( '[data-wk-vendor]' )", $body, '作用域内取全部 vendor 区（M4 后续驱动自动入列不硬化）' );
	}

	/* ---------- ④ 版本 0.3.7（FIX 轮 3 四处同步） ---------- */

	public function test_version_bumped_to_0_3_5_in_four_places(): void {
		$entry  = self::source( 'wink-wp-gatekeeper.php' );
		$readme = self::source( 'readme.txt' );

		$this->assertMatchesRegularExpression( '/^ \* Version:\s+0\.3\.7$/m', $entry, '插件头 Version 须为 0.3.7' );
		$this->assertStringContainsString( "define( 'WINK_GATEKEEPER_VERSION', '0.3.7' )", $entry, '版本常量须为 0.3.7' );
		$this->assertMatchesRegularExpression( '/^Stable tag: 0\.3\.7$/m', $readme, 'readme Stable tag 须为 0.3.7' );
		$this->assertStringContainsString( '= 0.3.7 =', $readme, 'Changelog 须有 0.3.7 条目' );
		$this->assertStringContainsString( 'FIX-3', $readme, 'Changelog 须含 FIX-3 锚点' );
	}
}
