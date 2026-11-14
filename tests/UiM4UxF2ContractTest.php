<?php
/**
 * M4UX F2 批 · UI 12+1 条落位契约测试（定稿 §3 十三行——方法论与 UiTab4IpDiag/UiTab1 同形态）。
 *
 * 骨架契约面（源码契约测试，纯 PHP 零 WP 依赖；行为面由真机 DOM 断言承载）：
 *   UI-1-1 Tab① 页顶三步引导通栏（overview_configured_count 判定）+ 页脚旧引导退役；
 *   UI-1-2 Tab③ 页顶「云端审核未配置未开启」notice；
 *   UI-1-3 Tab③ WP 原生两开关（comment_moderation/comment_whitelist）只读状态行；
 *   UI-2-1 Tab④ 分区③ Comment 说明收敛（foreach 外共享一条+官方类目外链+占位符统一说明示例）；
 *   UI-2-2 Comment placeholder 回显默认短语（AbuseIpdbService::default_comment）；
 *   UI-2-3 wk-ip-diag-result 固定 2×2 grid（≤782px 降 1 列）；
 *   UI-2-4 「获取模式」→「真实 IP 获取方式」；
 *   UI-2-5 仅 ip_source=custom_header 时显示自定义头字段（条件显隐）；
 *   UI-3-1 Tab⑤ 占位符定义列表（{rule} 附 Labels 映射示例）；
 *   UI-3-2 cloud_labels 统一表说明行；
 *   UI-3-3 Labels::rule_hit_word 补 duplicate_comment/comment_flood 人话名（行为断言）；
 *   UI-4  front-gate.css 验证按钮去 16px 硬编码→继承主题 .button 字号；
 *   附    过码后切网络提示（front_l10n 通道 net_changed + gatekeeper.js 注入 + CSS）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Ip\AbuseIpdbService;
use WinkGatekeeper\Stats\Labels;

final class UiM4UxF2ContractTest extends TestCase {

	private static function source( string $rel ): string {
		$src = file_get_contents( __DIR__ . '/../' . $rel );
		self::assertIsString( $src, "{$rel} 必须可读" );
		return (string) $src;
	}

	private static function settings_src(): string {
		return self::source( 'includes/settings/SettingsPage.php' );
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
		self::fail( "函数 {$name} 花括号不配平" );
		return '';
	}

	/**
	 * 提取 CSS 选择器规则块（首个配平花括号）。
	 */
	private static function cssBlock( string $css, string $selector ): string {
		$start = strpos( $css, $selector );
		self::assertNotFalse( $start, "CSS 须含选择器 {$selector}" );
		$open  = strpos( $css, '{', $start );
		$depth = 0;
		$len   = strlen( $css );
		for ( $i = $open; $i < $len; $i++ ) {
			if ( '{' === $css[ $i ] ) {
				$depth++;
			} elseif ( '}' === $css[ $i ] ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $css, $open, $i - $open + 1 );
				}
			}
		}
		self::fail( "CSS 块 {$selector} 花括号不配平" );
		return '';
	}

	/* ---------- UI-1-1 Tab① 三步引导通栏 + 页脚退役 ---------- */

	public function test_ui_1_1_onboarding_banner_first_in_overview(): void {
		$body = self::functionBody( self::settings_src(), 'render_tab_overview' );
		$this->assertStringContainsString( 'render_overview_onboarding()', $body, 'Tab① 须先渲染引导通栏' );
		$this->assertLessThan(
			strpos( $body, 'render_overview_alerts()' ),
			strpos( $body, 'render_overview_onboarding()' ),
			'引导通栏须在块 A（站务提醒）之前——页顶首位'
		);
	}

	public function test_ui_1_1_onboarding_banner_shape(): void {
		$body = self::functionBody( self::settings_src(), 'render_overview_onboarding' );

		$this->assertStringContainsString( 'notice notice-warning inline', $body, '原生 notice-warning inline 形态' );
		$this->assertStringContainsString( 'overview_configured_count', $body, '显隐判定复用 overview_configured_count' );
		foreach ( array( '选验证码', '云审', 'IP 信誉', '测试凭据' ) as $step ) {
			$this->assertStringContainsString( $step, $body, "三步引导须含「{$step}」" );
		}
		$this->assertStringContainsString( "tab_url( 'captcha' )", $body, '行动链接=从第一步（Tab② 验证码）开始' );
		$this->assertStringContainsString( '自动消失', $body, '配好即消失承诺写进文案' );
		$this->assertStringNotContainsString( 'style="', $body, '样式全走类（FIX-B 纪律：禁内联）' );
	}

	public function test_ui_1_1_footer_legacy_onboarding_retired(): void {
		$body = self::functionBody( self::settings_src(), 'render_overview_footer' );

		$this->assertStringNotContainsString( '首次使用', $body, '页脚旧引导退役（UI-1-1 落位后引导移页顶通栏）' );
		$this->assertStringNotContainsString( '三步配好', $body, '页脚旧链接文案退役' );
		$this->assertStringNotContainsString( 'overview_configured_count', $body, '页脚不再做首次使用判定' );
	}

	/* ---------- UI-1-2 Tab③ 云审未配置 notice ---------- */

	public function test_ui_1_2_review_cloud_unconfigured_notice(): void {
		$body = self::functionBody( self::settings_src(), 'render_tab_review' );

		$this->assertStringContainsString( 'notice notice-warning inline', $body, '原生 notice 形态' );
		$this->assertStringContainsString( '云端审核还没配置，也没开启', $body, '页顶提示文案' );
		$this->assertStringContainsString( 'empty( $cloud', $body, '判定：云端审核未开启' );
		$this->assertStringContainsString( 'array() === $chain', $body, '判定：链上无任何驱动（未配置）' );
		$this->assertStringContainsString( 'wk-review-cloud-card', $body, '行动锚点=云端审核卡' );
	}

	/* ---------- UI-1-3 Tab③ WP 原生两开关只读状态行 ---------- */

	public function test_ui_1_3_review_wp_native_switches_readonly_row(): void {
		$body = self::functionBody( self::settings_src(), 'render_tab_review' );

		$this->assertStringContainsString( "get_option( 'comment_moderation'", $body, '查询 WP 原生「评论必须手动批准」' );
		$this->assertStringContainsString( "get_option( 'comment_whitelist'", $body, '查询 WP 原生「作者须有已批准评论」' );
		$this->assertStringContainsString( 'id="wk-review-wp-switches"', $body, '只读状态行 DOM 锚' );
		$this->assertStringContainsString( '即使插件审核通过', $body, '开启时交互影响提示语' );
		$this->assertStringContainsString( 'options-discussion.php', $body, '管理入口=设置 → 讨论' );
		$this->assertStringNotContainsString( "field_name( array( 'comment_moderation'", $body, '红线：不改行为——开关不得进表单字段' );
		$this->assertStringNotContainsString( "field_name( array( 'comment_whitelist'", $body, '红线：不改行为——开关不得进表单字段' );
	}

	/* ---------- UI-2-1 Tab④ 分区③ 说明收敛 ---------- */

	public function test_ui_2_1_report_comment_description_converged(): void {
		$body = self::functionBody( self::settings_src(), 'render_iprep_zone_report' );

		$this->assertSame( 1, substr_count( $body, 'AbuseIPDB 报告详情公开可见' ), '公开可见警示 foreach 外共享一条（不得逐面重复 ×4）' );
		$this->assertSame( 1, substr_count( $body, '不可去除' ), '前缀不可去说明只出现一次' );
		$this->assertSame( 1, substr_count( $body, '超长自动截断' ), '255 截断说明只出现一次' );
		$this->assertStringContainsString( 'https://www.abuseipdb.com/categories', $body, '官方类目表外链' );
		$this->assertStringContainsString( '{event_no}', $body, '占位符统一说明' );
		$this->assertStringContainsString( '{category}', $body, '占位符统一说明' );
		$this->assertStringContainsString( '{gate}', $body, '占位符统一说明' );
		$this->assertStringContainsString( '使用示例', $body, '占位符须带使用示例' );
		$this->assertStringNotContainsString( '可留空用默认；支持占位符', $body, '旧逐面 placeholder 提示文案退役（说明收敛进共享 description）' );
	}

	/* ---------- UI-2-2 Comment placeholder 回显默认短语 ---------- */

	public function test_ui_2_2_comment_placeholder_echoes_default_phrase(): void {
		$body = self::functionBody( self::settings_src(), 'render_iprep_zone_report' );

		$this->assertStringContainsString( 'AbuseIpdbService::default_comment( $face_key )', $body, 'placeholder 由服务端渲染各触发面默认短语' );
	}

	public function test_ui_2_2_default_comment_accessor_behavior(): void {
		$this->assertSame( 'CAPTCHA verification failed (forged or replayed ticket)', AbuseIpdbService::default_comment( 'captcha_failed' ) );
		$this->assertSame( 'Repeated spam content submissions blocked', AbuseIpdbService::default_comment( 'review_reject' ) );
		$this->assertSame( 'Credential brute-force on WordPress login, blocked', AbuseIpdbService::default_comment( 'login_attempts' ) );
		$this->assertSame( 'Repeated spam/blocked content submissions', AbuseIpdbService::default_comment( 'local_rules_fail' ) );
		$this->assertSame( '', AbuseIpdbService::default_comment( 'unknown_face' ), '未收录触发面返回空串（不撒谎）' );
	}

	/* ---------- UI-2-3 diag-result 固定 2×2 grid ---------- */

	public function test_ui_2_3_diag_grid_fixed_two_by_two(): void {
		$css   = self::source( 'assets/css/admin-settings.css' );
		$block = self::cssBlock( $css, '.wk-ip-diag-grid' );

		$this->assertStringContainsString( 'repeat(2, 1fr)', $block, '固定 2 列（auto-fit 参差退役）' );
		$this->assertStringNotContainsString( 'auto-fit', $block, 'auto-fit minmax 退役——四源卡片 2×2 对齐' );

		// ≤782px 降 1 列（wp-admin 窄屏断点）。
		$media_pos = strpos( $css, '@media (max-width: 782px)' );
		$this->assertNotFalse( $media_pos, '窄屏媒体查询在位' );
		$media_block = substr( $css, $media_pos, 300 );
		$this->assertStringContainsString( '.wk-ip-diag-grid', $media_block, '窄屏降 1 列' );
		$this->assertStringContainsString( 'grid-template-columns: 1fr', $media_block, '窄屏单列' );
	}

	/* ---------- UI-2-4 label 改名 ---------- */

	public function test_ui_2_4_ip_source_label_renamed(): void {
		$body = self::functionBody( self::settings_src(), 'render_iprep_zone_source' );

		$this->assertStringContainsString( '真实 IP 获取方式', $body, 'label 改名（用户 UI-2-4 定值）' );
		$this->assertStringNotContainsString( "esc_html_e( '获取模式'", $body, '旧 label「获取模式」退役' );
	}

	/* ---------- UI-2-5 custom_header 条件显隐 ---------- */

	public function test_ui_2_5_custom_header_row_conditional(): void {
		$body = self::functionBody( self::settings_src(), 'render_iprep_zone_source' );

		$this->assertStringContainsString( 'id="wk-iprep-custom-header-row"', $body, '自定义头行 DOM 锚（条件显隐目标）' );
		$this->assertStringContainsString( 'IpResolver::MODE_CUSTOM_HEADER !== $mode', $body, 'PHP 初始态：非 custom_header 时行 hidden' );
	}

	public function test_ui_2_5_admin_js_binds_mode_select(): void {
		$js = self::source( 'assets/js/admin-brand.js' );

		$this->assertStringContainsString( "'wk-iprep-mode'", $js, 'admin-brand.js 须绑定真实 IP 获取方式下拉' );
		$this->assertStringContainsString( "'wk-iprep-custom-header-row'", $js, 'admin-brand.js 须联动自定义头行显隐' );
		$this->assertStringContainsString( "'custom_header'", $js, '显隐判定值=custom_header（bindVendorSwitcher 同款显隐模式）' );
	}

	/* ---------- UI-3-1 Tab⑤ 占位符定义列表 ---------- */

	public function test_ui_3_1_copywork_placeholder_definition_list(): void {
		$body = self::functionBody( self::settings_src(), 'render_tab_copy' );

		$this->assertStringContainsString( 'wk-placeholder-list', $body, '占位符定义列表 DOM 锚' );
		foreach ( array( '{min_length}', '{max_length}', '{event_no}', '{rule}', '{time}', '{contact_email}' ) as $ph ) {
			$this->assertStringContainsString( $ph, $body, "占位符逐个说明须含 {$ph}" );
		}
		$this->assertStringContainsString( 'remote_semantic', $body, '{rule} 须附 Labels 映射示例（键名）' );
		$this->assertStringContainsString( '服务商拒绝了', $body, '{rule} 须附 Labels 映射示例（人话名）' );
		$this->assertStringContainsString( '<dl', $body, '定义列表形态（dl/dt/dd）' );
	}

	/* ---------- UI-3-2 cloud_labels 统一表说明行 ---------- */

	public function test_ui_3_2_cloud_labels_shared_table_note(): void {
		$body = self::functionBody( self::settings_src(), 'render_tab_copy' );

		$this->assertStringContainsString( '全部云端驱动共用', $body, '统一表说明行（一张映射表服务全部云端驱动）' );
		$this->assertStringContainsString( 'Label,Score', $body, '说明行注明 reason 统一形态' );
	}

	/* ---------- UI-3-3 Labels 补两键人话名（行为断言） ---------- */

	public function test_ui_3_3_labels_duplicate_comment_and_flood(): void {
		$this->assertSame( '与已有评论重复', Labels::rule_hit_word( 'duplicate_comment' ) );
		$this->assertSame( '发得太快（洪泛保护）', Labels::rule_hit_word( 'comment_flood' ) );
	}

	/* ---------- UI-4 front-gate.css 验证按钮继承主题字号 ---------- */

	public function test_ui_4_verify_button_no_hardcoded_font_size(): void {
		$css   = self::source( 'assets/css/front-gate.css' );
		$block = self::cssBlock( $css, '.wk-captcha-btn,' );

		$this->assertStringNotContainsString( 'font-size: 16px', $block, '16px 硬编码退役——继承主题 .button 字号体系（通用优先）' );
		$this->assertStringContainsString( 'font-size: inherit', $block, '显式 inherit（通用优先不绑主题）' );
	}

	/* ---------- 附：过码后切网络提示【F3 批·用户明示退役】（天御票据网络绑定知识改入人工卡
	 * 备注，不做前台提示——「插在这里很丑，删掉」M4UX-MANUAL-FEEDBACK-2 §一④；旧 Contains
	 * 断言有意翻转为 NotContains，承接注记=F3-2 前台按钮返工批） ---------- */

	public function test_net_changed_hint_retired_from_l10n(): void {
		$bootstrap = self::source( 'includes/Bootstrap.php' );

		$this->assertStringNotContainsString( "'net_changed'", $bootstrap, 'front_l10n net_changed 键退役（F3-2 用户明示删除）' );
		$this->assertStringNotContainsString( '更换网络后请刷新页面重新完成验证', $bootstrap, '提示文案退役' );
	}

	public function test_net_changed_hint_retired_from_gatekeeper_js(): void {
		$js = self::source( 'assets/js/gatekeeper.js' );

		$this->assertStringNotContainsString( 'injectNetHint', $js, '注入函数退役' );
		$this->assertStringNotContainsString( 'wk-gate-net-hint', $js, '提示注入面退役' );
	}

	public function test_net_changed_hint_retired_from_css(): void {
		$css = self::source( 'assets/css/front-gate.css' );

		$this->assertStringNotContainsString( '.wk-gate-net-hint', $css, '提示行样式退役' );
	}

	/* ---------- 版本 0.5.5（四处钉 + Changelog——F3 批推进同步） ---------- */

	public function test_version_bumped_to_0_5_4_in_four_places(): void {
		$entry    = self::source( 'wink-wp-gatekeeper.php' );
		$readme   = self::source( 'readme.txt' );

		$this->assertMatchesRegularExpression( '/^ \* Version:\s+0.5.5$/m', $entry, '插件头 Version 0.5.5' );
		$this->assertStringContainsString( "define( 'WINK_GATEKEEPER_VERSION', '0.5.5' )", $entry, '版本常量 0.5.5' );
		$this->assertMatchesRegularExpression( '/^Stable tag: 0.5.5$/m', $readme, 'readme Stable tag 0.5.5' );
		$this->assertStringContainsString( '= 0.5.5 =', $readme, 'Changelog 0.5.5 条目' );
	}
}
