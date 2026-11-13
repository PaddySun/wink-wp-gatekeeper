<?php
/**
 * Tab④ 诊断面板骨架契约测试（M3-IP 定稿 §5.1，I0 批——方法论与 UiTab1/UiTab6 契约测试同形态）。
 *
 * 骨架契约面（源码契约测试，纯 PHP 零 WP 依赖；行为面由真机 DOM 断言承载）：
 *   ① Tab④ 顶卡=诊断面板两区（本地解析区 + 手动聚合查询区）——插入于现占位三卡顶部，
 *     占位三卡其余部分不动（全量四分区重写是 I4 的事，定稿 §6）；
 *   ② 手动聚合查询 AJAX（wink_gatekeeper_ip_diagnose）：nonce + manage_options 双闸；
 *     零落库（函数体禁 set_transient/update_option/计量——定稿设计红线）；
 *   ③ 四源 URL 常量集中一处（IpDiagnostic::REMOTE_SOURCES）+ JS 接线；
 *   ④ EdgeOne 空预设注记（定稿 §5.3——常量保留占位+UI 隐藏决策入注记）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;

final class UiTab4IpDiagContractTest extends TestCase {

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

	/* ---------- ① Tab④ 顶卡：诊断面板嵌入分区①（I4 四分区重写后——I0 时为占位三卡前顶卡） ---------- */

	public function test_diagnostic_panel_embedded_in_source_zone(): void {
		// 【I4 翻转】占位三卡退役，四分区重写（M3-IP 定稿 §6）——诊断面板原样挪入分区①
		//（行为零改动只挪渲染位置）；契约由 UiTab4ZonesContractTest 承接四分区面。
		$zone = self::functionBody( self::settings_src(), 'render_iprep_zone_source' );

		$this->assertStringContainsString( 'render_ip_diagnostic_panel(', $zone, '诊断面板嵌入分区①（原顶卡位）' );
	}

	public function test_diagnostic_panel_two_zones_with_anchors(): void {
		$body = self::functionBody( self::settings_src(), 'render_ip_diagnostic_panel' );

		foreach ( array(
			'IP 检测诊断面板',
			'本地解析区',
			'手动聚合查询区',
			'发往',                          // 出境说明一行（手动查询会把该 IP 发往以上服务商）.
			'AbuseIPDB',
			'StopForumSpam',
			'ip.nc.gy',
			'ip.sb',
			'id="wk-iprep-diag"',
			'id="wk-ip-diag-ip"',
			'id="wk-ip-diag-query"',
			'data-nonce',
			'id="wk-ip-diag-result"',
			'IpDiagnostic::analyze',
			'$_SERVER',
		) as $marker ) {
			$this->assertStringContainsString( $marker, $body, "诊断面板渲染体须含「{$marker}」" );
		}
		$this->assertStringNotContainsString( 'style="', $body, '样式全走 admin-settings.css 类（FIX-B 纪律：禁内联）' );
	}

	public function test_diagnostic_panel_analyze_receives_server_and_network_tree(): void {
		$body = self::functionBody( self::settings_src(), 'render_ip_diagnostic_panel' );
		$this->assertStringContainsString( "settings['network']", $body, '纯函数第二参=network 设置子树' );
	}

	/* ---------- ② AJAX：双闸 + 零落库红线 ---------- */

	public function test_ajax_action_constant_registered_in_bootstrap(): void {
		$this->assertStringContainsString(
			"const AJAX_IP_DIAGNOSE = 'wink_gatekeeper_ip_diagnose'",
			self::settings_src(),
			'AJAX action 常量'
		);
		$bootstrap = self::source( 'includes/Bootstrap.php' );
		$this->assertStringContainsString( 'SettingsPage::AJAX_IP_DIAGNOSE', $bootstrap, 'Bootstrap 须注册 wp_ajax 钩子' );
	}

	public function test_ajax_handler_double_gate_and_transport(): void {
		$body = self::functionBody( self::settings_src(), 'ajax_ip_diagnose' );

		$this->assertStringContainsString( 'check_ajax_referer( self::AJAX_IP_DIAGNOSE', $body, 'nonce 闸' );
		$this->assertStringContainsString( "current_user_can( 'manage_options' )", $body, '能力闸' );
		$this->assertStringContainsString( 'IpResolver::normalize', $body, 'IP 归一化入参' );
		$this->assertStringContainsString( 'IpDiagnostic::source_urls', $body, '四源 URL 常量集中一处（§12-2）' );
		$this->assertStringContainsString( '->get(', $body, '四源走 RemoteClient GET' );
		$this->assertStringContainsString( "'timeout'           => 3", $body, 'standard 档 timeout 3s（定稿 §5.1）' );
		$this->assertStringContainsString( 'IpDiagnostic::parse_source', $body, '单源 JSON → 卡片视图模型' );
		$this->assertStringContainsString( 'IpDiagnostic::card_error', $body, '任一源失败仅该卡片失败' );
	}

	public function test_ajax_handler_zero_persistence_and_zero_usage(): void {
		$body = self::functionBody( self::settings_src(), 'ajax_ip_diagnose' );

		foreach ( array( 'set_transient', 'update_option', 'add_option', 'bump_option', 'wp_insert_comment' ) as $bad ) {
			$this->assertStringNotContainsString( $bad, $body, "零落库红线：函数体禁 {$bad}" );
		}
		$this->assertStringNotContainsString( 'usage_key', $body, '零统计红线：四源调用不带 usage_key' );
	}

	/* ---------- ③ JS 接线 ---------- */

	public function test_admin_brand_js_binds_diagnose_query(): void {
		$js = self::source( 'assets/js/admin-brand.js' );

		$this->assertStringContainsString( 'wk-ip-diag-query', $js, '查询按钮绑定' );
		$this->assertStringContainsString( 'wink_gatekeeper_ip_diagnose', $js, 'AJAX action 名' );
		$this->assertStringContainsString( 'wk-ip-diag-ip', $js, 'IP 输入框取值' );
		$this->assertStringContainsString( 'wk-ip-diag-result', $js, '结果容器渲染' );
	}

	/* ---------- 【P0-1·F1 批】诊断卡片渲染净化（admin-brand.js innerHTML 拼接收口） ---------- */

	public function test_admin_brand_js_diag_card_renders_without_innerhtml(): void {
		// M4UX 定稿 §2 P0-1：ipDiagRenderCard 把远端 API 任意串（ISP/ASN/地区/错误文案）
		// 经 innerHTML 拼接注入管理员 DOM——收口为 DOM API + textContent（渲染为文本）。
		$js   = self::source( 'assets/js/admin-brand.js' );
		$body = self::functionBody( $js, 'ipDiagRenderCard' );

		$this->assertStringNotContainsString( 'innerHTML', $body, '远端串渲染禁 innerHTML 拼接（管理态反射面）' );
		$this->assertStringContainsString( 'createElement', $body, 'DOM API 组装卡片结构' );
		$this->assertStringContainsString( 'textContent', $body, '动态串一律 textContent 注入（XSS 载荷渲染为文本）' );
	}

	/* ---------- ④ EdgeOne 空预设注记（定稿 §5.3） ---------- */

	public function test_trusted_proxies_edgeone_note_documents_ui_hidden_decision(): void {
		$src = self::source( 'includes/ip/TrustedProxies.php' );

		$this->assertStringContainsString( 'EDGEONE', $src, '常量保留占位' );
		$this->assertStringContainsString( 'UI 隐藏', $src, '定稿 §5.3 决策入注记：空预设入选=静默无保护' );
		$this->assertStringContainsString( '校准脚本', $src, '注记：校准脚本支持补段后再放开' );
	}

	/* ---------- CSS ---------- */

	public function test_diagnostic_grid_css_classes_present(): void {
		$css = self::source( 'assets/css/admin-settings.css' );

		$this->assertStringContainsString( '.wk-ip-diag-grid', $css, '四源卡片网格类' );
		$this->assertStringContainsString( '.wk-ip-diag-card', $css, '单源卡片类' );
	}
}
