<?php
/**
 * W3 批 B · 八 Tab 渲染面重组 + 改名「Wink WP 保安」显示层契约测试（施工图
 * docs/W3-重设计施工图-2026-11-18.md §2 批 B——TDD 红先行回归门）。
 *
 * 五层断言面（施工图 §2 批 B TDD 计划）：
 *   ① 改名 8 处（显示层）+ 全库 grep '门口保安' 显示层零命中（注释层豁免白名单）
 *      + DiePage 署名行/alt（R3·Q6 代裁：前台拦截页补署名行=落地改名面）；
 *   ② 技术层不动反钉（红线表 §1.6/README §3.2）：slug / option 前缀 / 事件编号
 *      WK- / text domain / PAGE_SLUG / AJAX action 名 / 资源句柄——全部原样在场；
 *   ③ 字段 name 零减员（防重组丢字段=保存静默丢键）：全文件 field_name 点路径
 *      闭集与批 B 基线（c974289 提取）逐一比对——基线全集必须原样在场，新增仅限
 *      显式登记的两新键（coexist.hcaptcha_yield_comment/login——Tab⑧ coexist 卡
 *      实键新接线，schema SettingsSchema.php:433–436 + Sanitize.php:242–243 既有）；
 *   ④ 八 Tab 新 IA 锚集（原型 proto-tab*.html 信息架构）：Tab① 入口卡合并 / Tab②
 *      四闸 switch-row + 登录保护升独立卡 + 阿里 ⚙ 折叠（error_policy 实键）/
 *      Tab③ 五厂商 ⚙ 折叠 + privacy 黄条 helper 化 / Tab④ 三折叠迁 advanced_fold /
 *      Tab⑤ 文案折叠 + 拦截页署名预览注记 / Tab⑥ reason-tag 着色 + 登录流水现数据
 *      列面（Q3 统筹代裁）/ Tab⑦ 两 ⚙ 折叠 + 占位卡虚线壳 / Tab⑧ 四规划卡虚线壳 +
 *      coexist 新接线 + XML-RPC 搬位（Q4 代裁：双入口同键）；
 *   ⑤ 文案锚：改名新键在场 + 等级词黑话不进用户可见文案（esc 族字面量无英文
 *      driver/verdict/rule_hit 裸词）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;

final class W3TabRemodelTest extends TestCase {

	/* ================================================================
	 * 源读取 / 函数体提取 / 区块提取（既有契约测试同款零件）
	 * ================================================================ */

	private static function src( string $rel ): string {
		$src = file_get_contents( dirname( __DIR__ ) . '/' . $rel );
		self::assertIsString( $src, "{$rel} 必须可读" );
		return (string) $src;
	}

	private static function sp(): string {
		return self::src( 'includes/settings/SettingsPage.php' );
	}

	private static function functionBody( string $src, string $name ): string {
		$start = strpos( $src, 'function ' . $name );
		self::assertNotFalse( $start, "函数 {$name} 必须存在" );
		$open  = strpos( $src, '{', (int) $start );
		$depth = 0;
		$len   = strlen( $src );
		for ( $i = (int) $open; $i < $len; $i++ ) {
			if ( '{' === $src[ $i ] ) {
				$depth++;
			} elseif ( '}' === $src[ $i ] ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $src, (int) $open, $i - $open + 1 );
				}
			}
		}
		self::fail( "函数 {$name} 花括号不配平" );
		return '';
	}

	private static function body( string $name ): string {
		return self::functionBody( self::sp(), $name );
	}

	private static function block_between( string $src, string $start_marker, string $end_marker ): string {
		$start = strpos( $src, $start_marker );
		self::assertNotFalse( $start, "须含 {$start_marker}" );
		$end = strpos( $src, $end_marker, $start + strlen( $start_marker ) );
		self::assertNotFalse( $end, "须在 {$start_marker} 之后含 {$end_marker}" );
		return substr( $src, $start, $end - $start );
	}

	/* ================================================================
	 * ① 改名「Wink WP 保安」显示层 8 处 + 注释层豁免白名单
	 * ================================================================ */

	/**
	 * 显示层 8 处逐处断言（施工图 §1.5 R3 grep 全集清单）：
	 *   1 Plugin Name / 2–3 菜单双题 / 4 Widget 标题 / 5 logo alt / 6 wordmark /
	 *   7 nav aria-label / 8 DiePage alt+署名行。
	 */
	public function test_rename_eight_display_spots(): void {
		$plugin = self::src( 'wink-wp-gatekeeper.php' );
		self::assertStringContainsString( 'Plugin Name:       Wink WP Guard', $plugin, '改名①：插件头 Plugin Name（i18n 批 I-3 头英文化，zh 走 po msgstr）' );

		$bootstrap = self::src( 'includes/Bootstrap.php' );
		self::assertStringContainsString( "__( 'Wink WP Guard', 'wink-wp-gatekeeper' )", $bootstrap, '改名②③：菜单 page_title/menu_title 双题' );
		self::assertSame( 2, substr_count( $bootstrap, "__( 'Wink WP Guard', 'wink-wp-gatekeeper' )," ), '菜单双题恰两处' );
		self::assertStringContainsString( "__( 'Wink WP Guard · Today\'s status', 'wink-wp-gatekeeper' )", $bootstrap, '改名④：Widget 标题' );

		$sp = self::sp();
		self::assertStringContainsString( "esc_attr_e( 'Wink WP Guard', 'wink-wp-gatekeeper' )", $sp, '改名⑤：页头 logo alt' );
self::assertStringContainsString( '<span class="wk-wordmark">Wink <em>WP</em> <?php esc_html_e( \'Guard\', \'wink-wp-gatekeeper\' ); ?></span>', $sp, '改名⑥：wordmark（Q1=WP 金高亮 <em> 形态；i18n 批 I-3 尾词键化，zh msgstr=保安）' );
		self::assertStringContainsString( "esc_attr_e( 'Wink WP Guard settings sections', 'wink-wp-gatekeeper' )", $sp, '改名⑦：nav aria-label' );

		$die = self::src( 'includes/intercept/DiePage.php' );
		self::assertStringContainsString( "alt=\"' . esc_attr__( 'Wink WP Guard', 'wink-wp-gatekeeper' ) . '\"", $die, '改名⑧a：拦截页 logo alt 改名（i18n 批 I-3 esc_attr__ 键化）' );
		self::assertStringContainsString( 'wk-sign', $die, '改名⑧b：正文末常驻品牌署名行（R3·Q6——前台页补署名）' );
		self::assertStringContainsString( 'Wink WP Guard', $die, '署名行文案=新名（i18n 批 I-3 键化渲染）' );
	}

	/**
	 * 全库 grep '门口保安' 显示层零命中；注释层豁免白名单恰六处（施工图 §1.5
	 * 「注释/文件头层不动」清单——不承载用户可见文案，减 diff 噪音）。
	 */
	public function test_legacy_name_zero_hits_in_display_layer(): void {
		// 显示层=用户可达面：includes/ + assets/ + 插件主文件 + readme（tests/e2e 真机
		// 证据快照为历史留档非显示面，不入扫描——证据文件零渲染零送达）。
		$roots = array( 'includes', 'assets' );
		$hits  = array();
		foreach ( $roots as $root ) {
			$dir = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__ ) . '/' . $root ) );
			foreach ( $dir as $file ) {
				if ( ! $file->isFile() || ! preg_match( '/\.(php|js|css|txt|html)$/', (string) $file ) ) {
					continue;
				}
				$code = (string) file_get_contents( (string) $file );
				if ( false !== strpos( $code, '门口保安' ) ) {
					$hits[] = str_replace( chr(92), '/', (string) $file );
				}
			}
		}
		if ( false !== strpos( self::src( 'wink-wp-gatekeeper.php' ), '门口保安' ) ) {
			$hits[] = str_replace( chr(92), '/', dirname( __DIR__ ) ) . '/wink-wp-gatekeeper.php';
		}
		// readme.txt 显示层必改（readme 品牌名 6 处替换为「Wink WP 保安」）——品牌键面零残留。
		// 注：readme 描述行「面向中文独立博客的 WordPress 门口保安」为品类泛称非品牌名，
		// 不属改名 6 处（§5 风险 10：文案改动只允许改名替换——顺手润色禁改），白名单保留。
		// 【W3·批D 翻转】白名单 1→2：breaking 注（0.8.5 起）④ 改名条目如实提及旧名
		// 「门口保安」（任务口径 breaking 注汇总含改名显示层——历史性提及非显示层
		// 品牌键使用；「Wink 门口保安」完整品牌串仍零残留，:138 反钉不放宽）。
		$readme = self::src( 'readme.txt' );
		self::assertStringNotContainsString( 'Wink 门口保安', $readme, 'readme 品牌名「Wink 门口保安」零残留（6 处全改）' );
		self::assertStringNotContainsString( 'wink 门口保安', $readme, 'readme 品牌名「wink 门口保安」零残留' );
		self::assertSame( 0, substr_count( $readme, '门口保安' ), 'readme 门口保安 零残留（i18n 批 I-5b 翻转：readme 整篇英文化——zh 旧名白名单 2 处随英文化退役，用户拍板 2026-11-20 项③）' );
		self::assertStringContainsString( 'Wink WP Guard', $readme, 'readme 新名在场（i18n 批 I-5b 翻转：readme 全英文，新名即英文品牌名）' );

		// 白名单恰六处：五个 CSS/JS/主文件文件头注释 + Bootstrap docblock（注释层）。
		sort( $hits );
		self::assertSame(
			array(
				str_replace( chr(92), '/', dirname( __DIR__ ) ) . '/assets/css/admin-settings.css',
				str_replace( chr(92), '/', dirname( __DIR__ ) ) . '/assets/css/admin-widget.css',
				str_replace( chr(92), '/', dirname( __DIR__ ) ) . '/assets/css/front-gate.css',
				str_replace( chr(92), '/', dirname( __DIR__ ) ) . '/assets/js/admin-brand.js',
				str_replace( chr(92), '/', dirname( __DIR__ ) ) . '/includes/Bootstrap.php',
				str_replace( chr(92), '/', dirname( __DIR__ ) ) . '/wink-wp-gatekeeper.php',
			),
			$hits,
			"门口保安 残留仅限注释层豁免白名单六处（实测：" . implode( ' | ', $hits ) . "）"
		);
	}

	/* ================================================================
	 * ② 技术层不动反钉（红线表）
	 * ================================================================ */

	public function test_tech_layer_untouched_anti_pins(): void {
		$plugin = self::src( 'wink-wp-gatekeeper.php' );
		self::assertMatchesRegularExpression( "/Version:\s+[0-9]+\.[0-9]+\.[0-9]+/", $plugin, '插件头 Version 在场（0.9.1 在位——W3BatchDTest 钉四点 + W3FixW3F2Test 批内进位钉）' );
		self::assertStringContainsString( '0.9.1', $plugin, '版本四点已 i18n 批 I-4c 进位 0.9.1（milestone：i18n 全量英文化收口；0.8.7 收口在前）' );

		$sp = self::sp();
		// slug / option 前缀 / 事件编号 / text domain / action 常量面原样。
		// 【i18n 批 I-4c】PAGE_SLUG 常量值回 'wink-gatekeeper'（I-2b 域翻转误伤 URL slug 面，
		// 旧设置页 URL ?page=wink-gatekeeper 复通——B 机 403 实锤修复；text domain 仍 wink-wp-gatekeeper）。
		self::assertStringContainsString( "const PAGE_SLUG    = 'wink-gatekeeper';", $sp, 'slug 红线' );
		self::assertStringContainsString( "const OPTION_NAME  = 'wink_gatekeeper_settings';", $sp, 'option 名红线' );
		self::assertStringContainsString( 'WK-', $sp, '事件编号 WK- 前缀面在场' );
		self::assertStringContainsString( "'wink-wp-gatekeeper'", $sp, 'text domain 字面原样' );
		self::assertStringContainsString( "const AJAX_RESET_STATS = 'wink_gatekeeper_reset_stats';", $sp, 'AJAX action 常量面原样（reset）' );
		self::assertStringContainsString( "const AJAX_TEST_IPREP = 'wink_gatekeeper_test_iprep';", $sp, 'AJAX action 常量面原样（iprep）' );
		self::assertStringContainsString( "const ACTION_DIE_PREVIEW = 'wink_gatekeeper_die_preview';", $sp, 'admin action 常量面原样（die_preview）' );

		$bootstrap = self::src( 'includes/Bootstrap.php' );
		self::assertStringContainsString( 'wink-gatekeeper-admin-brand', $bootstrap, '资源句柄红线（admin-brand）' );

		// 「门口保安」不出现在任何 WP 边界显示函数（__() / esc_html_e / esc_attr_e）字面量中——
		// 反钉：显示层翻译键面已全部改名（注释层豁免）。
		foreach ( array( self::sp(), $bootstrap, $plugin ) as $code ) {
			self::assertSame(
				0,
				preg_match_all( "/(?:__|esc_html__|esc_attr_e|esc_html_e)\(\s*'[^']*门口保安/", $code ),
				'显示层翻译键面零「门口保安」残留'
			);
		}
	}

	/* ================================================================
	 * ③ 字段 name 零减员（批 B 基线 = c974289 提取 92 路径 + coexist 两新键）
	 * ================================================================ */

	/**
	 * 基线闭集（批 B 开工前从 c974289 提取——静态点路径；动态段保留 PHP 变量形态
	 * 原文比对）。防「重组丢字段=保存静默丢键」回归。
	 *
	 * @return string[] 排序后的点路径闭集。
	 */
	private static function name_baseline(): array {
		$static = array(
			'brand.contact_email', 'captcha.aliyun.mode', 'captcha.aliyun.prefix', 'captcha.aliyun.scene_id',
			'captcha.driver', 'captcha.login_adaptive.ip_threshold', 'captcha.login_adaptive.iprep_gate',
			'captcha.login_adaptive.user_threshold', 'captcha.login_adaptive.window_minutes', 'captcha.min_score',
			'captcha.submit_flow', 'captcha.tencent.app_id', 'captcha.tencent.disaster_pass', 'captcha.test_mode',
			'captcha.trust.credit_cache_minutes', 'captcha.trust.enabled', 'captcha.trust.min_approved',
			'captcha.trust.precheck_hourly_limit', 'captcha.trust.ttl_minutes', 'copy.die_page_message',
			'copy.die_page_title', 'captcha.error_policy',
			'gates.comment.enabled', 'gates.login.enabled', 'gates.login.render_mode', 'gates.login.xmlrpc_login',
			'iprep.abuseipdb.cache_minutes', 'iprep.abuseipdb.daily_quota', 'iprep.abuseipdb.malicious_score',
			'iprep.abuseipdb.soft_quota', 'iprep.abuseipdb.suspect_score', 'iprep.abuseipdb.timeout',
			'iprep.driver', 'iprep.enabled', 'iprep.negative_cache_minutes', 'iprep.report.enabled', 'iprep.whitelist',
			'network.custom_header', 'network.ip_source', 'network.trusted_proxies.cloudflare', 'network.trusted_proxies.custom',
			'review.cloud.aliyun_green.daily_quota', 'review.cloud.aliyun_green.dual_dispatch', 'review.cloud.aliyun_green.fail_mode',
			'review.cloud.aliyun_green.service_comment', 'review.cloud.aliyun_green.service_nickname', 'review.cloud.aliyun_green.timeout',
			'review.cloud.baidu.daily_quota', 'review.cloud.baidu.fail_mode', 'review.cloud.baidu.timeout',
			'review.cloud.chain', 'review.cloud.deepseek.base_url', 'review.cloud.deepseek.daily_quota',
			'review.cloud.deepseek.fail_mode', 'review.cloud.deepseek.model', 'review.cloud.enabled',
			'review.cloud.openai_custom.base_url', 'review.cloud.openai_custom.daily_quota', 'review.cloud.openai_custom.fail_mode',
			'review.cloud.openai_custom.model', 'review.cloud.tencent_tms.biz_type', 'review.cloud.tencent_tms.daily_quota',
			'review.cloud.tencent_tms.fail_mode', 'review.cloud.tencent_tms.reject_score', 'review.cloud.tencent_tms.timeout',
			'review.cloud.two_stage', 'review.cloud.upgrade_model', 'review.local.custom_banned_words',
			'review.local.max_length', 'review.local.min_length', 'review.local.require_chinese', 'review.local.use_wp_keywords',
			'stats.enabled', 'stats.raw_capture', 'stats.raw_max_bytes',
			'url_audit.batch_quota', 'url_audit.bocha.enabled', 'url_audit.enabled', 'url_audit.fetcher.mode',
			'url_audit.incr_quota', 'url_audit.profile', 'url_audit.provider.base_url', 'url_audit.provider.mode',
			'url_audit.provider.model', 'url_audit.robots_comply', 'url_audit.search_source',
			// 【批 B 新接线】coexist 两实键（schema 既有、渲染面补显隐口径——Tab⑧ 共存卡）。
			'coexist.hcaptcha_yield_comment', 'coexist.hcaptcha_yield_login',
		);
		$dynamic = array(
			"DYNAMIC:'copy', 'messages', \$key",
			"DYNAMIC:'copy', 'titles', \$key",
			"DYNAMIC:'gates', \$account_key, 'enabled'",
		);
		$raw = array(
			'RAW:avatar.custom_base', 'RAW:avatar.source', 'RAW:comments.site_field.enabled', 'RAW:comments.site_link.enabled',
		);
		$all = array_merge( $static, $dynamic, $raw );
		sort( $all );
		return $all;
	}

	/**
	 * 从源码提取当前全量 name 路径（静态点路径 + 动态段原文 + Tab⑧ RAW 名）。
	 *
	 * @return string[] 排序后的提取集。
	 */
	private static function extract_names(): array {
		$src = self::sp();
		preg_match_all( '/self::field_name\( array\(([^)]*)\) \)/', $src, $m );
		$set = array();
		foreach ( $m[1] as $args ) {
			$args = trim( (string) $args );
			if ( (bool) preg_match( "/^'([a-z0-9_]+)'(, ?'([a-z0-9_]+)')*$/", $args ) ) {
				preg_match_all( "/'([a-z0-9_]+)'/", $args, $segs );
				$set[] = implode( '.', $segs[1] );
			} else {
				$set[] = 'DYNAMIC:' . $args;
			}
		}
		preg_match_all( '/wink_gatekeeper_settings\[(comments|avatar)\]\[[a-z_]+\](\[[a-z_]+\])?/', $src, $m2, PREG_SET_ORDER );
		foreach ( $m2 as $raw ) {
			$set[] = 'RAW:' . ltrim( str_replace( array( '[', ']' ), array( '.', '' ), substr( (string) $raw[0], strlen( 'wink_gatekeeper_settings' ) ) ), '.' );
		}
		$set = array_values( array_unique( $set ) );
		sort( $set );
		return $set;
	}

	public function test_field_names_zero_attrition(): void {
		$baseline = self::name_baseline();
		$current  = self::extract_names();
		$missing  = array_diff( $baseline, $current );
		self::assertSame( array(), array_values( $missing ), '字段 name 零减员（重组不得丢字段=保存静默丢键）。缺失：' . implode( ', ', $missing ) );
		// 逆向也断言：不得出现基线与显式登记两新键之外的新 name（防顺手加未登记键）。
		// 【W3·批C】+ captcha.aliyun.region（N2 实键 radio——schema v3 新键登记入列）。
		$allowed_new = array( 'coexist.hcaptcha_yield_comment', 'coexist.hcaptcha_yield_login', 'captcha.error_policy', 'captcha.aliyun.region' );
		$unexpected  = array_diff( $current, $baseline, $allowed_new );
		self::assertSame( array(), array_values( $unexpected ), '未登记新 name（须走 schema 评审）：' . implode( ', ', $unexpected ) );
	}

	/* ================================================================
	 * ④ 八 Tab 新 IA 锚集
	 * ================================================================ */

	/** Tab①：入口卡合并（onboarding 与「继续配置」入口同判定源不双显）。 */
	public function test_tab1_entry_card_merged(): void {
		$entry = self::body( 'render_overview_onboarding' );
		self::assertStringContainsString( 'id="wk-overview-entry-card"', $entry, 'Tab① 入口卡锚（onboarding+入口合并为一张卡）' );
		self::assertSame( 1, substr_count( $entry, 'overview_configured_count(' ), '同判定源 overview_configured_count（不双显）' );
		self::assertStringContainsString( 'wk-card--ghost', $entry, '已配置态=ghost 弱化形态常驻（P6·R5）' );
		self::assertStringContainsString( 'Continue setup (wizard) →', $entry, '常驻入口文案（批 C 改指向 wizard）' );
		self::assertStringContainsString( 'Start protection in three steps', $entry, '未配置态=三步引导文案（合并保留）' );

		$main = self::body( 'render_tab_overview' );
		$pos_entry  = strpos( $main, 'render_overview_onboarding()' );
		$pos_alerts = strpos( $main, 'render_overview_alerts()' );
		$pos_usage  = strpos( $main, 'render_overview_usage()' );
		self::assertLessThan( $pos_alerts, $pos_entry, '入口卡在站务通栏之前（页顶首位）' );
		self::assertLessThan( $pos_usage, $pos_alerts, '调用量卡仍在站务通栏之后（批 A 六块序保持）' );
	}

	/** Tab②：四闸 switch-row 卡 + 登录保护升独立卡 + 阿里 ⚙ 折叠 + 演练黄条常显位。 */
	public function test_tab2_four_gate_switch_rows_and_login_guard_card(): void {
		$captcha = self::body( 'render_tab_captcha' );
		self::assertStringContainsString( 'id="wk-captcha-gates-card"', $captcha, '闸开关卡锚' );
		self::assertSame( 3, substr_count( $captcha, 'class="switch-row"' ), '四闸 switch-row 形态（评论+登录+注册/找回 foreach 模板=3 处字面）' );
		self::assertStringContainsString( 'id="wk-login-guard-card"', $captcha, '登录保护升独立卡锚' );
		self::assertStringContainsString( "'captcha', 'login_adaptive', 'user_threshold'", $captcha, '阈值键迁入登录保护卡' );
		self::assertStringContainsString( "'captcha', 'login_adaptive', 'iprep_gate'", $captcha, 'IP 信誉联动键迁入登录保护卡' );
		// 登录保护卡与闸开关卡分离（登录高级区折叠退役=升卡）。
		$pos_gates = strpos( $captcha, 'id="wk-captcha-gates-card"' );
		$pos_guard = strpos( $captcha, 'id="wk-login-guard-card"' );
		self::assertGreaterThan( $pos_gates, $pos_guard, '登录保护卡在闸开关卡之后（原型分区四位次）' );
		// 折叠壳退役：原「高级：阈值/窗口/IP 信誉联动/XML-RPC」summary 文案退役。
		self::assertStringNotContainsString( '高级：阈值 / 窗口 / IP 信誉联动 / XML-RPC', $captcha, '登录高级折叠 summary 退役（升独立卡）' );

		// 阿里卡 ⚙ 折叠（error_policy 实键新接线 + region 实键 radio【W3·批C·N2 落位】）。
		self::assertStringContainsString( 'id="wk-aliyun-advanced"', $captcha, '阿里卡 ⚙ 高级配置折叠锚' );
		self::assertStringContainsString( "'captcha', 'error_policy'", $captcha, 'error_policy 实键渲染（schema+sanitize 既有通道——coexist 同类新接线）' );
		// 【W3·批C·N2 翻转】region 现状口径行 → 实键 radio（cn 默认/sgp 海外，ENDPOINT_MAP 双端联动）。
		self::assertStringContainsString( "'captcha', 'aliyun', 'region'", $captcha, 'region 实键 radio（cn|sgp 默认 cn——规划位退役）' );
		self::assertStringContainsString( 'region must match the server side — the front-end SDK and the server endpoint are linked, and a mismatch errors out; after switching, run “Test credentials” once. The sgp endpoint is a static inference (not verified on a live site; the level will be disabled if live re-verification fails).', $captcha, 'region 变更提示（须与服务端一致）' );

		// 演练模式黄条+二次确认迁驱动卡区顶部常显位（原在天御卡内=选中才显）。
		$pos_tm   = strpos( $captcha, 'id="wk-test-mode"' );
		$pos_tenc = strpos( $captcha, 'wk-captcha-config-area' );
		self::assertGreaterThan( 0, $pos_tm, '演练模式开关在场' );
		self::assertLessThan( $pos_tenc, $pos_tm, '演练模式黄条在天御配置卡之前（驱动卡区顶部常显位——不再随选中才显）' );
		self::assertStringContainsString( 'wk-notice--warn', $captcha, '演练黄条=插件级黄条类（:1403 复合表单黄条批 B 收口）' );

		// 天御卡头「退役 · 存量可用」注记进卡头（原型措辞）。
		self::assertStringContainsString( 'Retired · usable if configured', $captcha, '天御卡头退役注记（原型「退役 · 存量可用」）' );

		// cred-test 新形态五处（tencent/aliyun 双驱动位——Tab② 内两处）。
		self::assertSame( 2, substr_count( $captcha, 'self::test_credential_btn(' ), 'Tab② 两驱动测试凭据迁 test_credential_btn（五态徽标初态统一）' );
	}

	/** Tab③：五厂商 ⚙ 折叠 + privacy 黄条 helper 化 + cred-test 迁段。 */
	public function test_tab3_vendor_advanced_folds_and_privacy_bars(): void {
		$review = self::body( 'render_tab_review' );
		foreach ( array( 'wk-tms-advanced', 'wk-green-advanced', 'wk-baidu-advanced', 'wk-deepseek-advanced', 'wk-openai-custom-advanced' ) as $fold_id ) {
			self::assertStringContainsString( 'id="' . $fold_id . '"', $review, "厂商 ⚙ 折叠锚 {$fold_id}" );
		}
		// 直拒阈值/超时/日配额/故障降级收折叠（tms 样张）。
		$tms_fold = self::block_between( $review, 'id="wk-tms-advanced"', 'self::test_credential_btn(' );
		foreach ( array( "'review', 'cloud', 'tencent_tms', 'reject_score'", "'review', 'cloud', 'tencent_tms', 'timeout'", "'review', 'cloud', 'tencent_tms', 'daily_quota'", "'review', 'cloud', 'tencent_tms', 'fail_mode'" ) as $path ) {
			self::assertStringContainsString( $path, $tms_fold, "TMS 高级参数收折叠（{$path}）" );
		}
		// privacy 行黄条 helper 化（DriverConfigSpec::privacy_notice 判定保持）。
		self::assertSame( 5, substr_count( $review, 'DriverConfigSpec::privacy_notice(' ), 'privacy 判定五处（Tab③ 五厂商——判定/文案留编排位；Tab② 两处在 render_tab_captcha 另计）' );
		self::assertStringNotContainsString( "notice-info inline", $review, 'Tab③ privacy 原生 notice class 字面退役（helper 化）' );
		// 五接入指引折叠壳保持 guide 形态（非 ⚙ 高级配置）。
		self::assertSame( 5, substr_count( $review, '<details class="wk-guide">' ), '五厂商接入指引 details/wk-guide 形态保持（guide≠adv）' );
		// cred-test 迁段：tms/green/baidu/deepseek/openai_custom 五处。
		self::assertSame( 5, substr_count( $review, 'self::test_credential_btn(' ), 'Tab③ 五厂商测试凭据迁 helper（url_audit 位在 render_url_audit_card 另计）' );
	}

	/** Tab④：三折叠迁 advanced_fold（:2987 UiM4UxF3 htmlBlock 锁死处=批 B 翻转解锁）。 */
	public function test_tab4_folds_via_helper(): void {
		foreach ( array( 'render_iprep_zone_source', 'render_iprep_zone_check', 'render_iprep_zone_report' ) as $fn ) {
			$b = self::body( $fn );
			self::assertStringNotContainsString( '<details class="wk-guide" id=', $b, "{$fn} 折叠壳迁 helper（原 details/wk-guide 内联壳退役）" );
			self::assertStringNotContainsString( '</details>', $b, "{$fn} 收口标记不在编排体（advanced_fold 闭包承载——htmlBlock 源抽取锁死解除）" );
		}
		$check = self::body( 'render_iprep_zone_check' );
		$call_pos  = strpos( $check, 'self::advanced_fold(' );
		$attrs_pos = strpos( $check, 'id="wk-iprep-advanced"' );
		self::assertNotFalse( $call_pos, 'check 区折叠经 advanced_fold（helper 化）' );
		self::assertGreaterThan( $call_pos, $attrs_pos, 'attrs 锚字面量留编排位（id= 起标记在调用实参内）' );
	}

	/** Tab⑤：文案折叠迁 helper + 拦截页署名行预览对应注记。 */
	public function test_tab5_copy_fold_and_sign_note(): void {
		$copy = self::body( 'render_tab_copy' );
		self::assertStringNotContainsString( '<details class="wk-guide" id="wk-copy-advanced">', $copy, '文案折叠壳迁 advanced_fold（批 B 目标形态）' );
		self::assertStringContainsString( 'wk-copy-advanced', $copy, '文案折叠 id 锚原位保留' );
		self::assertStringContainsString( 'A permanent brand signature line “Wink WP Guard” at the end of the body — click “Preview” to see it in the live-render preview (landed in W3 batch B).', $copy, '拦截页卡加「正文末品牌署名行」预览对应注记（R3）' );

		// DiePage::build 署名行实装（前台单页——本批落位）。
		$die = self::src( 'includes/intercept/DiePage.php' );
		$sign_pos  = strpos( $die, 'wk-sign' );
		$contact_pos = strpos( $die, 'wk-contact' );
		self::assertGreaterThan( 0, $sign_pos, 'DiePage 署名行 class 在场' );
	}

	/** Tab⑥：reason-tag 着色纪律 + 登录流水列面（Q3=现数据面）+ CSV 钮不渲染。 */
	public function test_tab6_reason_tag_and_login_table_columns(): void {
		// ① reason-tag：severity 映射纯函数件（闭集）+ 报错历史着色消费。
		$labels = self::src( 'includes/stats/Labels.php' );
		self::assertStringContainsString( 'function fault_reason_severity', $labels, 'severity 映射纯函数（Labels 承载——可测）' );
		$sev_body = self::functionBody( $labels, 'fault_reason_severity' );
		foreach ( array( "'red'", "'amber'", "'green'", "'dim'" ) as $level ) {
			self::assertStringContainsString( $level, $sev_body, "severity 四态闭集（{$level}）" );
		}
		$sev = \WinkGatekeeper\Stats\Labels::fault_reason_severity( 'driver_resumed' );
		self::assertSame( 'green', $sev, '恢复正常=绿' );
		self::assertSame( 'amber', \WinkGatekeeper\Stats\Labels::fault_reason_severity( 'driver_halted:quota_exhausted' ), '额度用完=黄' );
		self::assertSame( 'amber', \WinkGatekeeper\Stats\Labels::fault_reason_severity( 'remote_transport' ), '网络传输类=黄（可自愈）' );
		self::assertSame( 'red', \WinkGatekeeper\Stats\Labels::fault_reason_severity( 'driver_halted:config_error' ), '配置被拒=红' );
		self::assertSame( 'dim', \WinkGatekeeper\Stats\Labels::fault_reason_severity( 'bogus_unknown_key' ), '未收录键=中性 dim（不虚构严重度）' );
		$fault = self::body( 'render_stats_fault_section' );
		self::assertStringContainsString( 'reason-tag reason-tag--', $fault, '报错历史原因 reason-tag 着色消费' );
		self::assertStringContainsString( 'fault_reason_severity', $fault, 'severity 走映射纯函数' );

		// ③ 登录流水列面=现数据面（Q3 统筹代裁：时间/结果三态/哪张门/为什么拦/WK-——
		// 不显示账号与 IP，原型「用户名/IP 打码」措辞回写译文基线）。
		$table = self::body( 'render_account_recent_table' );
		foreach ( array( 'Time', 'Result', 'Gate', 'Why blocked', 'Event No.' ) as $col ) {
			self::assertStringContainsString( $col, $table, "登录流水列头（{$col}）" );
		}
		self::assertStringNotContainsString( 'Per-username failure threshold', $table, '原型「用户名」列不落（现数据面无此列——PIPL 红线）' );
		self::assertStringNotContainsString( 'IP 打码', $table, '原型「IP 打码」列不落（现数据面无此列——PIPL 红线）' );
		self::assertStringContainsString( 'Only characteristics and outcomes are shown, never accounts or IPs; outcome = stopped-by-captcha / stopped-without-captcha (passes never enter this table); why blocked = trigger attribution (too many consecutive failures for one account, etc.) or the captcha result.', $table, 'PIPL 最小化注保留' );
		self::assertStringContainsString( 'function account_result_word', self::src( 'includes/stats/Labels.php' ), '结果三态映射纯函数（出码·拦下/未过码·拦下——rejects 无放行行不虚构）' );
		// ④ CSV 导出钮本批不渲染（钮+端点同批 C 落地=无空接线）。
		self::assertStringNotContainsString( '导出 CSV', self::body( 'render_stats_records_card' ), 'CSV 钮不渲染（批 C/D 同 commit 接线）' );
	}

	/** Tab⑦：两 ⚙ 联动展示折叠 + 占位卡三件虚线壳。 */
	public function test_tab7_ghost_cards_and_liaison_folds(): void {
		$tools = self::body( 'render_tab_tools' );
		self::assertSame( 3, substr_count( $tools, 'wk-card--ghost' ), '占位卡三件虚线壳（导入导出/自检演练/系统信息）' );
		self::assertSame( 3, substr_count( $tools, 'wk-plan-tag' ), '规划徽标三件（特性徽标形态）' );
		self::assertSame( 2, substr_count( $tools, 'self::advanced_fold(' ), '体检卡+存量网址核查卡 ⚙ 联动展示折叠（额度与 robots 真实键在 Tab③——不另造键）' );
		// 联动展示折叠不新增字段（零新键——折叠内为说明文本）。
		$ghost_block = self::block_between( $tools, 'id="wk-tools-existing-audit-card"', 'id="wk-tools-url-audit-card"' );
		self::assertStringContainsString( 'Advanced notes (submission scope / handling)', $ghost_block, '送审范围说明收折叠（联动展示）' );
	}

	/** Tab⑧：四规划卡虚线壳 + coexist 新接线 + XML-RPC 搬位（Q4=双入口同键）。 */
	public function test_tab8_ghost_cards_coexist_and_xmlrpc_relocation(): void {
		$enhance = self::body( 'render_tab_enhance' );
		self::assertSame( 3, substr_count( $enhance, 'wk-card--ghost' ), 'RSS/REST/UA 三规划卡虚线壳（微拍板 c）' );
		self::assertStringContainsString( 'RSS protection', $enhance, 'RSS 卡（规划壳）' );
		self::assertStringContainsString( 'REST API controls', $enhance, 'REST 卡（规划壳）' );
		self::assertStringContainsString( 'UA / HTTP header rules', $enhance, 'UA 卡（规划壳）' );
		self::assertSame( 7, substr_count( $enhance, 'wk-plan-tag' ), '规划徽标七件（三卡头 + xmlrpc 两扩展行 + coexist 两检测行——闭集核对）' );

		// coexist 共存卡新接线（实键非规划——schema/sanitize 既有通道）。
		self::assertStringContainsString( 'id="wk-coexist-card"', $enhance, '共存卡锚' );
		self::assertStringContainsString( "'coexist', 'hcaptcha_yield_comment'", $enhance, 'coexist 实键一（评论让位）' );
		self::assertStringContainsString( "'coexist', 'hcaptcha_yield_login'", $enhance, 'coexist 实键二（登录让位）' );
		// hidden-0 伴随域（hidden 恒在 checkbox 前且紧邻——M5Fix 契约族同款）。
		foreach ( array( 'hcaptcha_yield_comment', 'hcaptcha_yield_login' ) as $leaf ) {
			$pair = self::block_between( $enhance, "array( 'coexist', '{$leaf}' )", 'class="sw-label"' );
			self::assertStringContainsString( 'value="0"', $pair, "coexist {$leaf} hidden-0 伴随域在 checkbox 之前（M5Fix 契约族同款紧邻形态）" );
		}
		self::assertStringContainsString( 'Akismet detected', $enhance, 'Akismet 规划检测行（灰字不冒充实装）' );
		self::assertStringContainsString( 'Wordfence detected', $enhance, 'Wordfence 规划检测行' );

		// XML-RPC 管控卡=真实控件搬位（Q4 代裁：Tab② 登录保护卡保留原行 + Tab⑧ 同键副本）。
		self::assertStringContainsString( 'id="wk-xmlrpc-control-card"', $enhance, 'XML-RPC 管控卡锚（搬位卡）' );
		self::assertSame( 4, substr_count( self::sp(), "'gates', 'login', 'xmlrpc_login'" ), 'xmlrpc_login 同键双入口（Tab② 登录保护卡 + Tab⑧ 管控卡——各带 hidden+checkbox 两处渲染，保存通道天然合并）' );
		$pos_coexist = strpos( $enhance, 'id="wk-coexist-card"' );
		$pos_site    = strpos( $enhance, 'id="wk-site-fields-card"' );
		self::assertGreaterThan( 0, $pos_coexist, '共存卡在场' );
		self::assertGreaterThan( $pos_coexist, $pos_site, '原型位次：coexist 卡在网站字段卡之前（卡5→卡6）' );
	}

	/** advanced_fold 壳翻转批 B 目标形态（details.adv + ⚙ CSS 前缀）+ 默认收起态。 */
	public function test_advanced_fold_flips_to_adv_shell(): void {
		$sp = self::sp();
		self::assertStringContainsString( "echo '<details class=\"adv\" '", $sp, 'advanced_fold 壳翻转 details.adv（批 B 重组目标形态——docblock 预告）' );
		self::assertStringNotContainsString( "echo '<details class=\"wk-guide\" '", $sp, '旧壳字面退役（helper 层）' );
		// 折叠默认收起态：helper 输出不含 open 属性。
		$fold = self::functionBody( $sp, 'advanced_fold' );
		self::assertStringNotContainsString( ' open', $fold, '折叠默认收起（不渲染 open 属性）' );
	}

	/** notice_yellow_bar 四级映射翻转插件级黄条类（批 A classes 兼容位退役）。 */
	public function test_notice_levels_flip_to_plugin_classes(): void {
		$sp = self::sp();
		foreach ( array(
			array( 'warn', 'wk-notice wk-notice--warn' ),
			array( 'danger', 'wk-notice wk-notice--danger' ),
			array( 'ok', 'wk-notice wk-notice--ok' ),
			array( 'info', 'wk-notice wk-notice--info' ),
		) as $pair ) {
			self::assertMatchesRegularExpression(
				"/'" . $pair[0] . "'\s+=>\s+'" . preg_quote( $pair[1], '/' ) . "'/",
				$sp,
				"{$pair[0]}→{$pair[1]}（插件级四级闭集——批 A WP 原生等价形态退役）"
			);
		}
		// 编排位显式 classes 字面量退役（批 A 迁段兼容位——本批翻转后回落 level 映射）。
		foreach ( array( 'render_overview_onboarding', 'render_tab_captcha', 'render_tab_review' ) as $fn ) {
			self::assertStringNotContainsString( "'notice notice-warning inline'", self::body( $fn ), "{$fn} 旧 WP 原生 class 字面退役" );
		}
	}

	/** 黄条迁段收口：全文件 WP 原生 notice class 清零（八 Tab 渲染面全量插件级类）。 */
	public function test_legacy_wp_notice_classes_retired(): void {
		$sp = self::sp();
		self::assertStringNotContainsString( 'notice notice-warning', $sp, 'WP 原生 notice-warning 清零（批 B 23 处迁段收口）' );
		self::assertStringNotContainsString( 'notice notice-info', $sp, 'WP 原生 notice-info 清零' );
		self::assertStringNotContainsString( 'notice-error inline', $sp, 'WP 原生 notice-error 清零' );
	}

	/* ================================================================
	 * ⑤ 文案锚：等级词黑话不进用户可见文案
	 * ================================================================ */

	public function test_jargon_words_absent_from_display_literals(): void {
		$sp = self::sp();
		// esc 族显示字面量内不得出现英文黑话裸词（driver/verdict/rule_hit/stage——
		// 对照表三分法：服务商/保安（点名册语境）/闸（分组名））。
		preg_match_all( "/(?:esc_html_e|esc_html__|esc_attr_e|__)\(\s*'([^']*)'/", $sp, $m );
		foreach ( $m[1] as $literal ) {
			self::assertSame(
				0,
				preg_match( '/\brule_hit\b/', (string) $literal ),
				"黑话裸词不得进用户可见文案：「{$literal}」"
			);
		}
	}
}
