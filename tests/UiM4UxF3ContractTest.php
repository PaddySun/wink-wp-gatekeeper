<?php
/**
 * M4UX F3 批 · 卸载残留诊断修复 + UI 增量 4 条落位契约测试（2026-11-14）。
 *
 * 骨架契约面（源码契约测试，纯 PHP 零 WP 依赖；行为面由真机 DOM 断言承载）：
 *   F3-1  卸载残留：Delete=全清卸载（gate 退役）+ 对象缓存清库（Redis 复活防御）+
 *         卸载谨慎性加固（精确匹配/失败安全/不碰非本插件数据——含行为级卸载测试）+
 *         升级兼容预位注记（uninstall 头 + SettingsSchema migrate）；
 *   F3-2  前台验证按钮盒模型跟随适配主题（继承链零写死像素——用户拍板「绝不 hard-code」；
 *         net_changed 提示退役的三个翻转断言在 UiM4UxF2ContractTest）；
 *   F3-3  Tab④ 折叠（IP 信誉查询高级设置 + 恶意行为上报触发面整组折叠，警示类常显）；
 *   F3-4  占位符结构化排版（wk-placeholder-list dt/dd + 示例块独立样式行）；
 *   F3-5  WP 原生两开关行条件展示（否否=零渲染 / 任一为是=warning notice）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Settings\SettingsSchema;

final class UiM4UxF3ContractTest extends TestCase {

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

	/**
	 * 提取 HTML 片段（marker 起到首个 end 标签止）——折叠块内部/外部断言用。
	 */
	private static function htmlBlock( string $src, string $marker, string $end ): string {
		$start  = strpos( $src, $marker );
		self::assertNotFalse( $start, "HTML 须含 {$marker}" );
		$endPos = strpos( $src, $end, $start );
		self::assertNotFalse( $endPos, "HTML 须在 {$marker} 之后含 {$end}" );
		return substr( $src, $start, $endPos - $start + strlen( $end ) );
	}

	/* ===================================================================
	 * F3-1 卸载残留诊断修复 + 谨慎性加固
	 * =================================================================== */

	public function test_uninstall_delete_means_full_clear_no_gate(): void {
		$src = self::source( 'uninstall.php' );

		$this->assertStringNotContainsString( 'cleanup_on_uninstall', $src, 'gate 退役——Delete 即触发全清卸载（用户拍板 2026-11-14：无需新增按钮/开关）' );
		$this->assertStringNotContainsString( '保留全部数据', $src, '「保留数据卸载」分支退役——保留数据路径=不卸载直接上传 zip 升级' );
		$this->assertStringContainsString( '全清', $src, '语义注记：Delete=全清卸载' );
	}

	public function test_uninstall_flushes_object_cache_guarded(): void {
		$src = self::source( 'uninstall.php' );

		$this->assertStringContainsString( "function_exists( 'wp_cache_flush' )", $src, '对象缓存清库须带可用性守卫（uninstall 直载场景 wp_object_cache 可用性防御）' );
		$this->assertStringContainsString( 'wp_cache_flush()', $src, '卸载尾部补对象缓存清库——Redis Object Cache 持久缓存复活防御（F3-1 头号嫌疑根因）' );
	}

	public function test_uninstall_cleans_own_comment_meta_key_only(): void {
		$src = self::source( 'uninstall.php' );

		$this->assertStringContainsString( "delete_metadata( 'comment', 0, 'wink_gatekeeper_archive'", $src, 'wink 自有 comment meta 键逐键清理（F1 装配 archive_spam 落的键）' );
		$this->assertStringNotContainsString( 'wp_commentmeta', $src, '红线：不写 comment meta 表级 SQL——只走 delete_metadata 逐键（不清整行评论）' );
		$this->assertStringNotContainsString( 'TRUNCATE', $src, '红线：禁 TRUNCATE（只 DROP wink 自建表）' );
	}

	public function test_uninstall_table_drop_strict_exact_match(): void {
		$src = self::source( 'uninstall.php' );

		$this->assertStringContainsString( 'SHOW TABLES LIKE', $src, 'DROP 前先 SHOW TABLES 探测' );
		$this->assertStringContainsString( "in_array( \$wink_events_table, \$wink_tables, true )", $src, '表名 PHP 侧严格全等比对（LIKE 下划线通配的相似表名不得误伤）' );
		$this->assertStringContainsString( 'wink_gatekeeper_events', $src, '目标=wink 自建事件表' );
	}

	public function test_uninstall_failure_safety_guards(): void {
		$src = self::source( 'uninstall.php' );

		$this->assertStringContainsString( 'catch ( Throwable', $src, '失败安全：清理步骤异常不中断整个卸载（宁可留残留不可误删）' );
		$this->assertStringContainsString( '宁可残留', $src, '失败安全语义注记' );
	}

	/**
	 * 行为级卸载测试（用户澄清②：无残留也不能删坏——夹具预埋第三方数据跑 uninstall，
	 * 断言第三方 option/表/transient/comment meta 原样存在、wink 自有数据全清）。
	 */
	public function test_uninstall_is_surgical_only_own_data(): void {
		require_once __DIR__ . '/FixturesUninstallStubs.php';

		UninstallFixture::reset();
		UninstallFakeWpdb::reset();
		$GLOBALS['wpdb'] = new UninstallFakeWpdb(); // uninstall.php `global $wpdb` 消费。

		/* 夹具：wink 自有数据 + 第三方（假想）数据并存。 */
		UninstallFixture::$options = array(
			'wink_gatekeeper_settings' => array( 'gates' => array() ),
			'wink_gatekeeper_stats'    => array(),
			'wink_gatekeeper_usage'    => array(),
			'wink_gatekeeper_health'   => array(),
			'seo_plugin_options'       => array( 'rating' => 5 ),           // 第三方 option.
			'wink_gatekeeper_settings_backup' => array( 'x' => 1 ),         // 相似前缀但非卸载清单键——必须原样。
		);
		UninstallFakeWpdb::$tables = array(
			'wp_wink_gatekeeper_events'    => true,                       // wink 自建表（须 DROP）。
			'wp_wink_gatekeeper_events_v2' => true,                       // 相似表名（全等比对不得误伤）。
			'wp_posts'                     => true,                       // WP 核心表。
			'wp_seo_plugin_log'            => true,                       // 第三方表。
		);
		UninstallFixture::$options['_transient_wk_ip_1.2.3.4']        = 'cached';
		UninstallFixture::$options['_transient_timeout_wk_ip_1.2.3.4'] = 100;
		UninstallFixture::$options['_transient_seo_plugin_cache']     = 'third-party'; // 第三方 transient.

		$uninstall = static function (): void {
			include __DIR__ . '/../uninstall.php';
		};
		$uninstall();

		/* wink 自有数据全清。 */
		$this->assertArrayNotHasKey( 'wink_gatekeeper_settings', UninstallFixture::$options, 'settings 已删' );
		$this->assertArrayNotHasKey( 'wink_gatekeeper_stats', UninstallFixture::$options, 'stats 已删' );
		$this->assertArrayNotHasKey( 'wink_gatekeeper_usage', UninstallFixture::$options, 'usage 已删' );
		$this->assertArrayNotHasKey( 'wink_gatekeeper_health', UninstallFixture::$options, 'health 已删' );
		$this->assertArrayNotHasKey( 'wp_wink_gatekeeper_events', UninstallFakeWpdb::$tables, 'events 表已 DROP' );
		$this->assertArrayHasKey( 'wp_wink_gatekeeper_events_v2', UninstallFakeWpdb::$tables, '相似表名不得误伤（严格全等比对）' );
		$this->assertArrayNotHasKey( '_transient_wk_ip_1.2.3.4', UninstallFixture::$options, 'wk_ 前缀 transient 已清' );
		$this->assertArrayNotHasKey( '_transient_timeout_wk_ip_1.2.3.4', UninstallFixture::$options, 'timeout 键已清' );
		$this->assertTrue( UninstallFixture::$cache_flushed, '卸载尾部对象缓存清库（Redis 复活防御）' );

		/* 第三方数据原样存在（无残留也不能删坏）。 */
		$this->assertArrayHasKey( 'seo_plugin_options', UninstallFixture::$options, '第三方 option 原样' );
		$this->assertArrayHasKey( 'wink_gatekeeper_settings_backup', UninstallFixture::$options, '非卸载清单的相似键名 option 原样（精确全等，不宽匹配）' );
		$this->assertArrayHasKey( '_transient_seo_plugin_cache', UninstallFixture::$options, '第三方 transient 原样' );
		$this->assertArrayHasKey( 'wp_posts', UninstallFakeWpdb::$tables, 'WP 核心表原样' );
		$this->assertArrayHasKey( 'wp_seo_plugin_log', UninstallFakeWpdb::$tables, '第三方表原样' );
		$this->assertSame(
			array( array( 'comment', 0, 'wink_gatekeeper_archive', '', true ) ),
			UninstallFixture::$meta_deletes,
			'comment meta 只逐键清 wink 自有键（第三方 meta 键零触碰、不清整行）'
		);
		$this->assertStringNotContainsString( 'seo_plugin', (string) json_encode( UninstallFixture::$meta_deletes ), '第三方 meta 键零触碰' );
	}

	public function test_uninstall_upgrade_compatibility_note_prepositioned(): void {
		$src = self::source( 'uninstall.php' );

		$this->assertStringContainsString( '升级路径', $src, '升级兼容预位注记在位（不卸载直接上传 zip）' );
		$this->assertStringContainsString( '预位', $src, '跨大版本兼容策略=预位（本批只落注记不实现）' );
	}

	public function test_schema_upgrade_compatibility_note_prepositioned(): void {
		$src = self::source( 'includes/settings/SettingsSchema.php' );

		$this->assertStringContainsString( '升级路径', $src, 'SettingsSchema 升级兼容预位注记在位' );
		$this->assertStringContainsString( '跨大版本', $src, '跨大版本兼容策略留后续里程碑（预位注记）' );
		$this->assertStringContainsString( 'SCHEMA_VERSION', $src, '沿用既有 schema version 键（最小形态）' );
	}

	public function test_cleanup_on_uninstall_key_retired_everywhere(): void {
		$defaults = SettingsSchema::defaults();
		$this->assertArrayNotHasKey( 'cleanup_on_uninstall', $defaults, 'schema 默认树退役该键' );

		$schema_src = self::source( 'includes/settings/SettingsSchema.php' );
		$this->assertStringNotContainsString( "'cleanup_on_uninstall'   => false", $schema_src, '默认值行退役' );

		/* 存量树残留键：migrate_fields 幂等清除（fail_mode 先例）。 */
		$merged = SettingsSchema::merge_defaults( array( 'cleanup_on_uninstall' => true ) );
		$this->assertArrayNotHasKey( 'cleanup_on_uninstall', $merged, '存量残留键 migrate 幂等清除' );

		$sanitize_src = self::source( 'includes/settings/Sanitize.php' );
		$this->assertStringNotContainsString( 'cleanup_on_uninstall', $sanitize_src, 'Sanitize 白名单同步退役' );
	}

	/* ===================================================================
	 * F3-2 前台验证按钮盒模型跟随适配主题（用户澄清①：动态继承，绝不 hard-code）
	 * =================================================================== */

	public function test_verify_button_box_model_follows_theme(): void {
		$css   = self::source( 'assets/css/front-gate.css' );
		$block = self::cssBlock( $css, '.wk-captcha-btn,' );

		$this->assertStringContainsString( 'font-size: inherit', $block, '字号继承主题（F2 已落，随批保持）' );
		$this->assertStringContainsString( 'line-height: inherit', $block, '行高继承主题——盒模型跟随适配' );
		$this->assertStringNotContainsString( 'padding:', $block, 'padding 不在本块写死——交给主题 .button 映射（用户拍板：绝不 hard-code）' );
		$this->assertStringNotContainsString( 'min-height', $block, 'min-height 不在本块写死——跟随主题按钮盒模型' );
		$this->assertStringNotContainsString( '8px 18px', $css, '旧硬编码 padding 退役（132×40 的成因）' );
		$this->assertStringNotContainsString( '--wk-gate-btn-h', $css, '旧高度变量体系退役' );
	}

	public function test_verify_button_fallback_uses_relative_units(): void {
		$css    = self::source( 'assets/css/front-gate.css' );
		$block  = self::cssBlock( $css, ':where(.wk-captcha-btn' );

		$this->assertStringContainsString( ':where(.wk-captcha-btn, .wk-verify-btn)', $css, '盒模型兜底用零特异性 :where（主题 .button 规则恒压过兜底）' );
		$this->assertStringContainsString( 'padding: 0.6em 1.2em', $block, '兜底 padding 用 em 相对主题字号（无像素硬编码）' );
		$this->assertStringNotContainsString( 'px', $block, '兜底块零像素值' );
	}

	public function test_button_inheritance_chain_has_no_pixel_hardcode(): void {
		$css  = self::source( 'assets/css/front-gate.css' );
		$gate = self::cssBlock( $css, '.wk-gate {' );

		$this->assertStringContainsString( 'font-size: inherit', $gate, '.wk-gate 容器字号继承——按钮 font-size:inherit 的继承链上不得有像素断点（16px 硬编码会重新截断主题字号）' );
		$this->assertStringNotContainsString( 'font-size: 16px', $gate, '容器 16px 硬编码退役（F3-2 继承链自检）' );
	}

	/* ===================================================================
	 * F3-3 Tab④ 折叠
	 * =================================================================== */

	public function test_tab4_check_zone_advanced_collapsed(): void {
		$body    = self::functionBody( self::settings_src(), 'render_iprep_zone_check' );
		$details = self::htmlBlock( $body, 'id="wk-iprep-advanced"', '</details>' );

		$this->assertStringContainsString( '<details class="wk-guide"', $body, '折叠形态=既有 details/wk-guide（03 Tab② 同款）' );
		$this->assertStringContainsString( '高级设置', $details, '折叠区标题' );
		foreach ( array( 'malicious_score', 'suspect_score', 'soft_quota', 'daily_quota', 'cache_minutes', 'negative_cache_minutes', "'timeout'" ) as $key ) {
			$this->assertStringContainsString( $key, $details, "阈值/软顶/日配额/缓存双键/timeout 收进折叠（{$key}）" );
		}
	}

	public function test_tab4_check_zone_core_visible(): void {
		$body   = self::functionBody( self::settings_src(), 'render_iprep_zone_check' );
		$before = substr( $body, 0, (int) strpos( $body, 'id="wk-iprep-advanced"' ) );

		foreach ( array( "'iprep', 'enabled'", 'wk-iprep-driver', 'abuseipdb', 'api_key', '测试凭据' ) as $key ) {
			$this->assertStringContainsString( $key, $before, "默认展开仅 启用开关/服务商/凭据（{$key} 在折叠区之前）" );
		}
		$this->assertStringNotContainsString( 'malicious_score', $before, '阈值默认收进折叠' );
		$this->assertStringContainsString( '软顶保护', $body, '软顶黄条不折叠（警示常显——本批红线）' );
	}

	public function test_tab4_report_zone_triggers_collapsed(): void {
		$body    = self::functionBody( self::settings_src(), 'render_iprep_zone_report' );
		$details = self::htmlBlock( $body, 'id="wk-iprep-report-advanced"', '</details>' );

		/* T1–T4 四面逐行（foreach 迭代 $faces——四面定义在折叠区之前）+ 限流参数收进折叠。 */
		foreach ( array( 'foreach ( $faces', 'wk-iprep-threshold-', 'wk-iprep-category-', 'wk-iprep-comment-', '限流参数' ) as $key ) {
			$this->assertStringContainsString( $key, $details, "T1–T4 勾选组+限流参数默认整组折叠（{$key}）" );
		}
		foreach ( array( 'captcha_failed', 'review_reject', 'login_attempts', 'local_rules_fail' ) as $face ) {
			$this->assertStringContainsString( $face, $body, "触发面 {$face} 定义在位（整组进折叠区）" );
		}
		$this->assertStringNotContainsString( "'iprep', 'report', 'enabled'", $details, '上报总开关不折叠' );
	}

	public function test_tab4_report_zone_warnings_always_visible(): void {
		$body    = self::functionBody( self::settings_src(), 'render_iprep_zone_report' );
		$details = self::htmlBlock( $body, 'id="wk-iprep-report-advanced"', '</details>' );
		$before  = substr( $body, 0, (int) strpos( $body, 'id="wk-iprep-report-advanced"' ) );

		$this->assertStringContainsString( 'notice notice-warning', $before, '出境 PIPL 黄条不折叠常显' );
		$this->assertStringContainsString( '启用门禁', $before, '门禁状态行不折叠常显' );
		$this->assertStringContainsString( "'iprep', 'report', 'enabled'", $before, '总开关不折叠常显' );
		$this->assertStringNotContainsString( 'notice notice-warning', $details, '警示黄条不得落进折叠区' );
	}

	/* ===================================================================
	 * F3-4 占位符结构化排版
	 * =================================================================== */

	public function test_placeholder_list_structured_css(): void {
		$css = self::source( 'assets/css/admin-settings.css' );

		$this->assertStringContainsString( '.wk-placeholder-list dt', $css, '术语行（dt）独立规则——结构化排版' );
		$this->assertStringContainsString( '.wk-placeholder-list dd', $css, '定义行（dd）独立规则——消扁平混杂' );
		$this->assertStringContainsString( '.wk-example-line', $css, '示例块独立样式行' );
	}

	public function test_tab4_shared_note_example_own_block_line(): void {
		$body = self::functionBody( self::settings_src(), 'render_iprep_zone_report' );

		$this->assertStringContainsString( 'wk-example-line', $body, 'Tab④ 共用说明使用示例=独立样式行（结构化排版）' );
		$this->assertStringContainsString( 'Forged captcha ticket #{event_no} (category {category}, gate {gate})', $body, '使用示例内容保持（F2 UI-2-1 既有契约）' );
	}

	public function test_tab5_rule_example_own_block_line(): void {
		$body = self::functionBody( self::settings_src(), 'render_tab_copy' );

		$this->assertStringContainsString( 'wk-example-line', $body, 'Tab⑤ {rule} 映射示例=独立样式行' );
		$this->assertStringContainsString( 'remote_semantic', $body, '映射示例键名保持（F2 UI-3-1 既有契约）' );
		$this->assertStringContainsString( '服务商拒绝了', $body, '映射示例人话名保持' );
	}

	/* ===================================================================
	 * F3-5 WP 开关行条件展示（三态：否否=零渲染 / 是否·否是·是是=渲染）
	 * =================================================================== */

	public function test_wp_switches_row_conditional_three_states(): void {
		$body = self::functionBody( self::settings_src(), 'render_tab_review' );

		$guard = strpos( $body, 'if ( $wp_moderation || $wp_whitelist )' );
		$this->assertNotFalse( $guard, '三态判定守卫在位：任一为是才渲染（是否/否是/是是），都为否整行不渲染' );
		$row = strpos( $body, 'id="wk-review-wp-switches"' );
		$this->assertNotFalse( $row, '状态行 DOM 锚保持' );
		$this->assertGreaterThan( $guard, $row, '状态行在守卫块内——否否态零渲染' );
		$endif = strpos( $body, 'endif;', $row );
		$this->assertNotFalse( $endif, '守卫块收口（endif）' );

		$row_block = self::htmlBlock( $body, 'id="wk-review-wp-switches"', '</tr>' );
		$this->assertStringContainsString( 'notice notice-warning', $row_block, '渲染态=warning notice（两开关会阻碍插件自动审核）' );
		$this->assertStringNotContainsString( "field_name( array( 'comment_moderation'", $row_block, '红线：不进表单字段（只读不改行为）' );
	}

	/* ===================================================================
	 * 版本 0.5.5（四处钉 + Changelog）
	 * =================================================================== */

	public function test_version_bumped_to_0_5_4_in_four_places(): void {
		$entry  = self::source( 'wink-wp-gatekeeper.php' );
		$readme = self::source( 'readme.txt' );

		$this->assertMatchesRegularExpression( '/^ \* Version:\s+0.5.5$/m', $entry, '插件头 Version 0.5.5' );
		$this->assertStringContainsString( "define( 'WINK_GATEKEEPER_VERSION', '0.5.5' )", $entry, '版本常量 0.5.5' );
		$this->assertMatchesRegularExpression( '/^Stable tag: 0.5.5$/m', $readme, 'readme Stable tag 0.5.5' );
		$this->assertStringContainsString( '= 0.5.5 =', $readme, 'Changelog 0.5.5 条目' );
	}
}
