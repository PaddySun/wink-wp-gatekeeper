<?php
/**
 * O3 仪表盘统计卡片·接线契约测试（2026-11-12，O3-统计卡片设计建议.md §五/§七 步骤 2-5）。
 *
 * 骨架契约面（源码契约测试，纯 PHP 零 WP 依赖——方法论与 UiTab6StatsContractTest 同一
 * 形态；行为面由真机 DOM 断言承载）：
 *   ① Bootstrap 注册形态（Wordfence wordfenceClass.php:7410-7429 同款骨架 + 两处有意改造）：
 *     wp_dashboard_setup hook + manage_options 能力门禁（改造①——Wordfence 只判 isAdmin）
 *     + stats.dashboard_widget_enabled 开关键 + versioned CSS 入队 + wp_add_dashboard_widget；
 *   ② echo 在 WP 边界（DashboardDigest 纯逻辑类零 echo——渲染在 Bootstrap 方法）；
 *   ③ 转义纪律（Wordfence views/reports/activity-report.php:37 同款——esc_html/esc_attr/
 *     (int) 强转全覆盖于渲染回调作用域）；
 *   ④ 每卡一条行动出口深链（Wordfence activity-report.php:51/96/131 button 同款——
 *     健康卡→Tab① / 调用量卡→Tab⑥ / 概览条→Tab⑥）；
 *   ⑤ cron 存活检测（wp_next_scheduled(EventsTable::HOOK_DAILY)）+ 重新激活
 *     admin_action 通道（nonce + manage_options 双闸 + register_cron 重排）；
 *   ⑥ 三卡 DOM 锚（wk-widget-health / wk-widget-usage / wk-widget-overview）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;

final class O3DashboardWidgetContractTest extends TestCase {

	private static function source( string $rel ): string {
		$src = file_get_contents( __DIR__ . '/../' . $rel );
		self::assertIsString( $src, "{$rel} 必须可读" );
		return (string) $src;
	}

	private static function bootstrap_src(): string {
		return self::source( 'includes/Bootstrap.php' );
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

	private static function body( string $name ): string {
		return self::functionBody( self::bootstrap_src(), $name );
	}

	/* ---------- ① 注册形态（Wordfence 骨架 + 两处有意改造） ---------- */

	public function test_boot_hooks_wp_dashboard_setup(): void {
		$boot = self::body( 'boot' );
		$this->assertStringContainsString( "add_action( 'wp_dashboard_setup'", $boot, 'boot() 须挂 wp_dashboard_setup' );
		$this->assertStringContainsString( 'add_dashboard_widget', $boot, '回调方法名 add_dashboard_widget' );
	}

	public function test_registration_capability_gate_manage_options(): void {
		$fn = self::body( 'add_dashboard_widget' );
		$this->assertStringContainsString( "current_user_can( 'manage_options' )", $fn, '改造①：能力门禁 manage_options（Wordfence 只判 isAdmin 的加强）' );
	}

	public function test_registration_toggle_stats_dashboard_widget_enabled(): void {
		$fn = self::body( 'add_dashboard_widget' );
		$this->assertStringContainsString( "stats']['dashboard_widget_enabled'", $fn, '开关键 stats.dashboard_widget_enabled（仿 email_summary_dashboard_widget_enabled，wfConfig.php:124）' );
	}

	public function test_registration_enqueues_versioned_widget_css(): void {
		$fn = self::body( 'add_dashboard_widget' );
		$this->assertStringContainsString( 'wp_enqueue_style', $fn, '卡片 CSS 版本化入队（wordfenceClass.php:7419-7420 同款时机）' );
		$this->assertStringContainsString( 'admin-widget.css', $fn, '样式文件 assets/css/admin-widget.css' );
		$this->assertStringContainsString( 'WINK_GATEKEEPER_VERSION', $fn, '版本号作 CSS 版本戳' );
	}

	public function test_registration_adds_dashboard_widget(): void {
		$fn = self::body( 'add_dashboard_widget' );
		$this->assertStringContainsString( 'wp_add_dashboard_widget', $fn, '仪表盘 widget 注册' );
		$this->assertStringContainsString( 'wink_gatekeeper_dashboard_widget', $fn, 'widget id' );
		$this->assertStringContainsString( 'render_dashboard_widget', $fn, '渲染回调（echo 在边界）' );
	}

	public function test_no_network_dashboard_registration(): void {
		$this->assertDoesNotMatchRegularExpression( "/add_action\( 'wp_network_dashboard_setup'/", self::bootstrap_src(), '改造②：暂不挂网络仪表盘（per-site 口径——O3 §五）' );
	}

	/* ---------- ② 分层纪律：纯逻辑类零 echo，渲染在边界 ---------- */

	public function test_dashboard_digest_has_no_echo(): void {
		$src = self::source( 'includes/stats/DashboardDigest.php' );
		$this->assertDoesNotMatchRegularExpression( '/^\s*echo\b/m', $src, 'DashboardDigest 纯逻辑类不得 echo（渲染归边界层；注释提及不计数）' );
		$this->assertStringNotContainsString( 'wp_dashboard_setup', $src, '纯逻辑类不挂钩子' );
	}

	/* ---------- ③ 渲染边界转义纪律 ---------- */

	public function test_render_escapes_all_output(): void {
		$fn = self::body( 'render_dashboard_widget' );
		$this->assertStringContainsString( 'esc_html', $fn, '文本 esc_html（activity-report.php:37 同款）' );
		$this->assertStringContainsString( 'esc_attr', $fn, '属性 esc_attr' );
		$this->assertStringContainsString( 'esc_url', $fn, '链接 esc_url' );
		$this->assertStringContainsString( '(int)', $fn, '计数 (int) 强转' );
	}

	public function test_render_reads_options_and_computes_injected_booleans(): void {
		$fn = self::body( 'render_dashboard_widget' );
		$this->assertStringContainsString( 'StatsCache::get', $fn, '统计树 option 读取' );
		$this->assertStringContainsString( 'Usage::get', $fn, '用量树 option 读取' );
		$this->assertStringContainsString( 'is_configured', $fn, '验证码配置态经真实驱动判定（is_configured）' );
		$this->assertStringContainsString( 'widget_overview', $fn, '视图模型出自 DashboardDigest::widget_overview' );
	}

	/* ---------- ④ 每卡一条行动出口深链 ---------- */

	public function test_action_links_to_tab1_and_tab6(): void {
		$fn = self::body( 'render_dashboard_widget' );
		$this->assertStringContainsString( 'tab=overview', $fn, '健康卡行动出口 → 设置页 Tab① 锚点' );
		$this->assertStringContainsString( 'tab=stats', $fn, '调用量卡/概览条行动出口 → Tab⑥ 锚点' );
		$this->assertStringContainsString( 'button-primary', $fn, '行动出口 button-primary 形态（Wordfence activity-report.php:51 同款）' );
	}

	/* ---------- ⑤ cron 存活 + 重新激活通道 ---------- */

	public function test_render_checks_cron_alive(): void {
		$fn = self::body( 'render_dashboard_widget' );
		$this->assertStringContainsString( 'wp_next_scheduled', $fn, 'cron 存活检测' );
		$this->assertStringContainsString( 'EventsTable::HOOK_DAILY', $fn, '对比钩子 = 每日维护 cron' );
	}

	public function test_reactivate_cron_channel_guarded(): void {
		$src   = self::bootstrap_src();
		$fn    = self::body( 'handle_reactivate_cron' );
		$this->assertStringContainsString( "admin_action_wink_gatekeeper_reactivate_cron", $src, 'admin_action 通道挂载' );
		$this->assertStringContainsString( "current_user_can( 'manage_options' )", $fn, '重排通道 manage_options 闸' );
		$this->assertStringContainsString( 'check_admin_referer', $fn, '重排通道 nonce 闸' );
		$this->assertStringContainsString( 'EventsTable::register_cron', $fn, '重排逻辑 = EventsTable::register_cron（幂等——已排程不重复注册）' );
	}

	/* ---------- ⑥ 三卡 DOM 锚 ---------- */

	public function test_three_card_dom_anchors(): void {
		$fn = self::body( 'render_dashboard_widget' );
		$this->assertStringContainsString( 'wk-widget-health', $fn, '卡 1 系统健康 DOM 锚' );
		$this->assertStringContainsString( 'wk-widget-usage', $fn, '卡 2 调用量 DOM 锚' );
		$this->assertStringContainsString( 'wk-widget-overview', $fn, '卡 3 概览迷你条 DOM 锚' );
	}

	/* ---------- ⑦ 设置键落位（schema 默认 + 清洗规则） ---------- */

	public function test_settings_schema_stats_key_defaults_true(): void {
		$src = self::source( 'includes/settings/SettingsSchema.php' );
		$this->assertStringContainsString( "'dashboard_widget_enabled' => true", $src, 'stats.dashboard_widget_enabled 默认 true' );
	}

	public function test_sanitize_rule_bool(): void {
		$src = self::source( 'includes/settings/Sanitize.php' );
		$this->assertStringContainsString( "'stats.dashboard_widget_enabled'                  => 'bool'", $src, '清洗规则 bool 型（schema↔rules 双向一致性测试自动封口）' );
	}

	/* ---------- ⑧ 卡片文案走 __() 不混 Copywork（O3 §六） ---------- */

	public function test_widget_title_translated_with_text_domain(): void {
		$fn = self::body( 'add_dashboard_widget' );
		$this->assertStringContainsString( "__(", $fn, 'widget 标题走 __() 管理面文案' );
		$this->assertStringContainsString( "'wink-gatekeeper'", $fn, 'text domain' );
	}
}
