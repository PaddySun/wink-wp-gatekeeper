<?php
/**
 * M4UX G 批 · 人工反馈第 3 轮三条 UI 建议落位契约测试（2026-11-14）。
 *
 * 骨架契约面（源码契约测试，纯 PHP 零 WP 依赖；行为面由真机 DOM 断言承载）：
 *   G1  原生校验分层——浏览器原生 constraint validation 管「表单没填」（required 必填项），
 *       wink 四闸预检管「填了但不合规」：点「完成人机验证」先 form.reportValidity() 守卫
 *       （false=浏览器原生提示弹出、预检请求不发）；无票禁用提交逻辑零改动；
 *       wk-gate-msg 语义不变（仍只报内容不合规）。
 *   G2  Tab④ 分区① 折叠——默认展开仅「当前生效」行+「手动聚合查询工具」卡；
 *       模式选择/自定义头/受信网段/白名单/CIDR 校验收 details/wk-guide 折叠；
 *       提示行常显（用户原话整理）。
 *   G3  Tab⑤ 「拦截文案与页面标题」组默认折叠（wk-placeholder-list 随组）+
 *       拦截页自定义「查看效果」按钮——admin action wink_gatekeeper_die_preview
 *       （manage_options+nonce 双闸→DiePage 样例实渲染→exit；零落库零事件零统计）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Settings\SettingsPage;

final class UiM4UxGContractTest extends TestCase {

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
	 * G1 原生校验分层（gatekeeper.js reportValidity 守卫）
	 * =================================================================== */

	/**
	 * 点「完成人机验证」先过原生校验守卫：reportValidity()=false（有未填必填字段）
	 * → 原生提示框由浏览器弹出，预检请求不发（runPrecheck 不调用）。
	 */
	public function test_g1_report_validity_guard_precedes_precheck(): void {
		$body = self::functionBody( self::source( 'assets/js/gatekeeper.js' ), 'armPrecheckGate' );

		$guard = strpos( $body, 'nativeValidityGuard( form )' );
		$this->assertNotFalse( $guard, '验证按钮点击须先过原生校验守卫（reportValidity 分层）' );
		$precheck = strpos( $body, 'runPrecheck( container, form, button )' );
		$this->assertNotFalse( $precheck, '既有四闸预检调用保持' );
		$this->assertGreaterThan( $guard, $precheck, '原生守卫先于四闸预检——原生管「没填」、四闸管「不合规」两层先后分工' );
	}

	public function test_g1_guard_defensive_when_report_validity_missing(): void {
		$body = self::functionBody( self::source( 'assets/js/gatekeeper.js' ), 'nativeValidityGuard' );

		$this->assertStringContainsString( "typeof form.reportValidity !== 'function'", $body, '表单元素缺失/不支持 reportValidity：守卫放行（等价无原生校验环境，防御形态）' );
		$this->assertStringContainsString( 'form.reportValidity()', $body, '守卫核心=form.reportValidity()（浏览器自动弹原生提示+聚焦 invalid 字段）' );
	}

	/**
	 * wk-gate-msg 语义不变：原生守卫不碰内联错误条（它仍只报「填了但不合规」——
	 * 本条不做四闸失败→原生提示框的改造）；checking/已验证互斥态判定保持在守卫之前。
	 */
	public function test_g1_native_layering_keeps_msg_semantics_and_mutex_states(): void {
		$js   = self::source( 'assets/js/gatekeeper.js' );
		$body = self::functionBody( $js, 'armPrecheckGate' );
		$guard_fn = self::functionBody( $js, 'nativeValidityGuard' );

		$this->assertStringNotContainsString( 'showMsgBar', $guard_fn, '原生守卫不碰 wk-gate-msg（内联错误条仍只报内容不合规——语义分层不替换）' );
		$mutex = strpos( $body, "data-wk-checking" );
		$this->assertNotFalse( $mutex, 'checking/已验证互斥态判定保持' );
		$this->assertLessThan( (int) strpos( $body, 'nativeValidityGuard( form )' ), (int) $mutex, '互斥态判定在原生守卫之前（已验证/检查中不触发原生校验）' );
	}

	/**
	 * 无票禁用提交逻辑零改动（G1 边界红线）：refreshLocks 禁用/还原路径保持原形态。
	 */
	public function test_g1_disabled_submit_logic_untouched(): void {
		$body = self::functionBody( self::source( 'assets/js/gatekeeper.js' ), 'refreshLocks' );

		$this->assertStringContainsString( "data-wk-gate-disabled", $body, '无票禁用提交标记保持（渐进增强逻辑零改动）' );
		$this->assertStringContainsString( 'allReady', $body, '全容器持票才解禁的判定保持' );
	}

	/* ===================================================================
	 * G2 Tab④ 分区① 折叠（F3-3 同款 details/wk-guide 形态）
	 * =================================================================== */

	public function test_g2_source_zone_advanced_collapsed(): void {
		$body    = self::functionBody( self::settings_src(), 'render_iprep_zone_source' );
		$details = self::htmlBlock( $body, 'id="wk-iprep-source-advanced"', '</details>' );

		$this->assertStringContainsString( '<details class="wk-guide"', $body, '折叠形态=既有 details/wk-guide（F3-3 同款）' );
		$this->assertStringContainsString( '高级设置', $details, '折叠区标题' );
		foreach ( array( "'network', 'ip_source'", "'network', 'custom_header'", "'network', 'trusted_proxies', 'cloudflare'", "'network', 'trusted_proxies', 'custom'", "'iprep', 'whitelist'", 'wk-iprep-cidr-check' ) as $key ) {
			$this->assertStringContainsString( $key, $details, "模式选择/自定义头/预设选择器/受信网段/白名单/CIDR 校验收进折叠（{$key}）" );
		}
	}

	public function test_g2_source_zone_core_visible_before_details(): void {
		$body   = self::functionBody( self::settings_src(), 'render_iprep_zone_source' );
		$before = substr( $body, 0, (int) strpos( $body, 'id="wk-iprep-source-advanced"' ) );

		$this->assertStringContainsString( 'render_ip_diagnostic_panel(', $before, '诊断面板调用在折叠区之前（「当前生效」行+「手动聚合查询工具」卡默认展开）' );
		$this->assertStringContainsString( '请核对当前 IP 是否与您的真实出口一致', $before, '常显提示在折叠区之前' );

		$panel = self::functionBody( self::settings_src(), 'render_ip_diagnostic_panel' );
		$this->assertStringContainsString( '当前生效', $panel, '「当前生效」行在诊断面板（默认展开）' );
		$this->assertStringContainsString( '手动聚合查询区', $panel, '「手动聚合查询工具」卡在诊断面板（默认展开）' );
		$this->assertStringContainsString( 'wk-ip-diag-query', $panel, '手动聚合查询按钮默认展开' );
		$this->assertStringNotContainsString( '真实 IP 获取方式', $before, '模式选择默认收进折叠' );
		$this->assertStringNotContainsString( 'wk-iprep-custom-cidr', $before, '受信网段 textarea 默认收进折叠' );
	}

	public function test_g2_source_zone_hint_always_visible(): void {
		$body   = self::functionBody( self::settings_src(), 'render_iprep_zone_source' );
		$before = substr( $body, 0, (int) strpos( $body, 'id="wk-iprep-source-advanced"' ) );

		$this->assertStringContainsString( '请核对当前 IP 是否与您的真实出口一致，不一致请展开配置', $before, '折叠区旁常显提示一行（用户原话整理）' );
	}

	/* ===================================================================
	 * G3 Tab⑤ 折叠 + 拦截页预览
	 * =================================================================== */

	public function test_g3_tab5_copy_group_collapsed(): void {
		$body    = self::functionBody( self::settings_src(), 'render_tab_copy' );
		$details = self::htmlBlock( $body, 'id="wk-copy-advanced"', '</details>' );

		$this->assertStringContainsString( '<details class="wk-guide"', $body, '折叠形态=既有 details/wk-guide' );
		foreach ( array( 'wk-placeholder-list', "'copy', 'messages'", "'copy', 'titles'", 'wk-copy-restore-defaults' ) as $key ) {
			$this->assertStringContainsString( $key, $details, "拦截文案组+占位符说明区+恢复默认按钮随组折叠（{$key}）" );
		}
		$before = substr( $body, 0, (int) strpos( $body, 'id="wk-copy-advanced"' ) );
		$this->assertStringContainsString( '语气三原则', $before, '语气三原则小抄不折叠常显' );
		$this->assertStringNotContainsString( 'wk-placeholder-list', $before, '占位符说明区不常显（随组折叠）' );
	}

	public function test_g3_die_preview_sample_constants_no_hardcode(): void {
		$this->assertSame( 'WK-00000000', Copywork::PREVIEW_EVENT_NO, '预览样例事件编号常量（示例值入常量防硬编码）' );
		$this->assertSame( '示例规则', Copywork::PREVIEW_RULE_SAMPLE, '预览样例规则名常量' );
	}

	/**
	 * 预览载荷纯函数（单测覆盖）：当前自定义文案+占位符示例值填充。
	 */
	public function test_g3_die_preview_payload_fills_example_values(): void {
		$settings = array(
			'brand' => array( 'contact_email' => 'webmaster@example.com' ),
			'copy'  => array(
				'die_page_title'   => '预览标题 {event_no}',
				'die_page_message' => '规则 {rule}，时间 {time}，联系 {contact_email}',
			),
		);

		$payload = SettingsPage::die_preview_payload( $settings, '2026-11-14 12:00:00' );

		$this->assertSame( '预览标题 ' . Copywork::PREVIEW_EVENT_NO, $payload['title'], '{event_no}=样例事件编号填充' );
		$this->assertStringContainsString( Copywork::PREVIEW_RULE_SAMPLE, $payload['message'], '{rule}=样例规则名填充' );
		$this->assertStringContainsString( '2026-11-14 12:00:00', $payload['message'], '{time}=当前时间填充' );
		$this->assertStringContainsString( 'webmaster@example.com', $payload['message'], '{contact_email}=联系邮箱填充' );
		$this->assertSame( Copywork::PREVIEW_EVENT_NO, $payload['evidence']['事件编号'], '证据行 样例事件编号' );
		$this->assertSame( Copywork::PREVIEW_RULE_SAMPLE, $payload['evidence']['命中规则'], '证据行 样例规则名' );
		$this->assertSame( '2026-11-14 12:00:00', $payload['evidence']['时间'], '证据行 当前时间' );
	}

	/**
	 * 双闸（manage_options+nonce）+ 零落库零事件零统计 + DiePage 品牌页实渲染 → exit。
	 */
	public function test_g3_die_preview_handler_double_gate_and_zero_persistence(): void {
		$body = self::functionBody( self::settings_src(), 'handle_die_preview' );

		$caps = strpos( $body, "current_user_can( 'manage_options' )" );
		$this->assertNotFalse( $caps, '闸 1：manage_options 能力检查' );
		$this->assertNotFalse( strpos( $body, 'check_admin_referer' ), '闸 2：nonce 校验（check_admin_referer）' );

		foreach ( array( 'update_option', 'add_option', 'delete_option', 'EventsTable', 'StatsCache', 'Usage::' ) as $no_write ) {
			$this->assertStringNotContainsString( $no_write, $body, "零落库零事件零统计红线（禁 {$no_write}）" );
		}

		$this->assertStringContainsString( 'die_preview_payload', $body, '预览载荷走纯函数（示例值填充单一来源）' );
		$this->assertStringContainsString( 'DiePage::build', $body, '拦截页实渲染=DiePage::build 品牌页构造' );
		$this->assertStringContainsString( 'echo', $body, '完整 HTML 直出（不经 wp_die——预览即实渲染形态）' );
		$this->assertStringContainsString( 'exit', $body, '渲染后 exit（admin action 终止）' );
	}

	public function test_g3_die_preview_button_link_with_nonce_new_tab(): void {
		$body = self::functionBody( self::settings_src(), 'render_tab_copy' );

		$this->assertStringContainsString( 'ACTION_DIE_PREVIEW', $body, '预览链接走 ACTION 常量（零魔法串）' );
		$this->assertStringContainsString( 'wp_nonce_url', $body, '链接带 nonce（admin_url 形态）' );
		$this->assertStringContainsString( "admin_url( 'admin-post.php?action='", $body, 'admin-post.php action 链接形态' );
		$this->assertStringContainsString( 'target="_blank"', $body, '新标签页打开预览' );
		$this->assertStringContainsString( 'rel="noopener"', $body, 'noopener 防反向标签劫持' );
		$this->assertStringContainsString( '查看效果', $body, '按钮文案「查看效果」（定稿）' );
	}

	public function test_g3_die_preview_action_registered_in_bootstrap(): void {
		$bootstrap = self::source( 'includes/Bootstrap.php' );

		$this->assertStringContainsString( "admin_post_' . SettingsPage::ACTION_DIE_PREVIEW", $bootstrap, 'admin_post_wink_gatekeeper_die_preview 钩子注册（admin-post.php 登录态派发语境）' );
		$this->assertStringContainsString( 'handle_die_preview', $bootstrap, '处理器=SettingsPage::handle_die_preview' );
		$this->assertStringContainsString( "const ACTION_DIE_PREVIEW = 'wink_gatekeeper_die_preview'", self::settings_src(), 'ACTION 常量（定稿命名）' );
	}

	/* ===================================================================
	 * 版本 0.5.5（四处钉 + Changelog）
	 * =================================================================== */

	public function test_version_bumped_to_0_5_5_in_four_places(): void {
		$entry  = self::source( 'wink-wp-gatekeeper.php' );
		$readme = self::source( 'readme.txt' );

		$this->assertMatchesRegularExpression( '/^ \* Version:\s+0\.5\.5$/m', $entry, '插件头 Version 0.5.5' );
		$this->assertStringContainsString( "define( 'WINK_GATEKEEPER_VERSION', '0.5.5' )", $entry, '版本常量 0.5.5' );
		$this->assertMatchesRegularExpression( '/^Stable tag: 0\.5\.5$/m', $readme, 'readme Stable tag 0.5.5' );
		$this->assertStringContainsString( '= 0.5.5 =', $readme, 'Changelog 0.5.5 条目' );
	}
}
