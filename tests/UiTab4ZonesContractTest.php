<?php
/**
 * Tab④ 四分区全量 UI 契约测试（M3-IP 定稿 §6 + 03 Tab④【v1.7·M3-IP】回写版蓝本，I4 收官批；
 * 方法论与 UiTab4IpDiagContractTest / UiTab6StatsContractTest 同形态——源码契约测试，
 * 纯 PHP 零 WP 依赖；行为面由真机 DOM 断言承载）。
 *
 * 契约面：
 *   ① 分区一 真实 IP 来源——模式下拉（direct/xff/traverse/custom_header+拓扑注记三行）+
 *     自定义头名 + Cloudflare 预设选择器（EdgeOne 隐藏保持——I0 行为）+ 受信网段 textarea +
 *     CIDR 前端校验按钮 + 诊断面板嵌入位（I0 件原样挪入——行为零改动只挪渲染位置）；
 *   ② 分区二 IP 信誉查询——enabled + 驱动下拉 + api_key password 型（P0-α 教训）+ 阈值双滑杆
 *     （85/60）+ 软顶 3000 + 日配额 4750 + 缓存双键（cache_minutes 在 abuseipdb 子树 /
 *     negative_cache_minutes 在 iprep 顶层——实况键名，渲染读实况）+ timeout 1–5s +
 *     「测试凭据」按钮 + 健康行（state_label 三态）+ 软顶命中黄条；
 *   ③ 分区三 上报——report.enabled 总开关 + 出境黄条（PIPL）+ T1–T4 勾选组（每面开关+
 *     阈值框（T2/T4）+类目下拉（AbuseIpdbCategories 1–23）+Comment 自定义框（占位符提示+
 *     公开可见警示））+ 冷却/限速/日顶只读展示 + 启用门禁状态行（real_ip_self_check 消费）；
 *   ④ 分区四 说明卡——skip/pass/fail 三态人话 + 信用免验联动 + 软顶人话句；
 *   ⑤ 占位三卡退役（950/fail_mode/M3 实装文案漂移全修）；
 *   ⑥ 测试凭据 AJAX（wink_gatekeeper_test_iprep）——nonce+cap 双闸 + 判定法 O5-P2-6
 *     （绿=200+JSON 可解析 isPublic:false 亦绿）+ 零落库；
 *   ⑦ JS 接线（admin-brand.js）——测试凭据三态/CIDR 校验/滑杆联动。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;

final class UiTab4ZonesContractTest extends TestCase {

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

	private static function body( string $name ): string {
		return self::functionBody( self::settings_src(), $name );
	}

	/* ---------- 编排：四分区顺序 + 占位三卡退役 ---------- */

	public function test_tab_iprep_orchestrates_four_zones_in_order(): void {
		$main = self::body( 'render_tab_iprep' );

		$markers = array(
			'render_iprep_zone_source',
			'render_iprep_zone_check',
			'render_iprep_zone_report',
			'render_iprep_zone_notes',
		);
		$last = -1;
		foreach ( $markers as $marker ) {
			$this->assertStringContainsString( 'self::' . $marker . '(', $main, "四分区编排须调用 {$marker}" );
			$pos = strpos( $main, 'self::' . $marker . '(' );
			$this->assertGreaterThan( $last, $pos, "{$marker} 必须按分区顺序出现" );
			$last = $pos;
		}
	}

	/** 占位文案漂移全修（SURVEY §6：950/fail_mode/上报占位文案——占位三卡整体退役）。 */
	public function test_placeholder_drift_copy_retired(): void {
		$bodies = self::body( 'render_tab_iprep' )
			. self::body( 'render_iprep_zone_source' )
			. self::body( 'render_iprep_zone_check' )
			. self::body( 'render_iprep_zone_report' )
			. self::body( 'render_iprep_zone_notes' );
		$this->assertStringNotContainsString( '950', $bodies, '日配额 950 漂移文案退役（实况 4750）' );
		$this->assertStringNotContainsString( 'fail_mode', $bodies, 'fail_mode 降级开关文案退役（键已废弃）' );
		$this->assertStringNotContainsString( 'M3 实装', $bodies, '「M3 实装」占位句退役' );
		$this->assertStringNotContainsString( 'M3）：', $bodies, '「AbuseIPDB（M3）」占位句退役' );
		$this->assertStringNotContainsString( '有票才报」：仅验证码核验失败（有票）触发', $bodies, '旧上报占位文案退役（对齐 T1–T4）' );
	}

	/* ---------- 分区① 真实 IP 来源 ---------- */

	public function test_zone_source_controls_and_panel_embedding(): void {
		$body = self::body( 'render_iprep_zone_source' );

		$this->assertStringContainsString( 'id="wk-iprep-source"', $body, '分区① DOM 锚' );
		// 诊断面板嵌入位（I0 件原样挪入——行为零改动只挪渲染位置）。
		$this->assertStringContainsString( 'render_ip_diagnostic_panel(', $body, '诊断面板嵌入分区①' );

		// 模式下拉（direct/xff/traverse/custom_header 四档）。
		$this->assertStringContainsString( "array( 'network', 'ip_source' )", $body, '模式下拉绑定 network.ip_source' );
		foreach ( array( 'MODE_DIRECT', 'MODE_XFF', 'MODE_TRAVERSE', 'MODE_CUSTOM_HEADER' ) as $mode_const ) {
			$this->assertStringContainsString( "IpResolver::{$mode_const}", $body, "模式下拉含 {$mode_const} 档" );
		}
		// 拓扑注记三行（定稿 §5.2）。
		$this->assertStringContainsString( '直连', $body );
		$this->assertStringContainsString( 'CF', $body, 'CF 托管→custom_header 注记' );
		$this->assertStringContainsString( '自建反代', $body, '自建反代多级→traverse 注记' );
		$this->assertStringContainsString( '不作默认', $body, 'traverse 不作默认警示保留' );

		// 自定义头名输入。
		$this->assertStringContainsString( "array( 'network', 'custom_header' )", $body, '自定义头名输入' );

		// 预设选择器：Cloudflare 在场 + 快照核对日期；EdgeOne 隐藏（I0 行为保持）。
		$this->assertStringContainsString( "array( 'network', 'trusted_proxies', 'cloudflare' )", $body, 'Cloudflare 预设选择器' );
		$this->assertStringContainsString( 'TrustedProxies::PRESET_VERIFIED', $body, '预设快照核对日期展示' );
		$this->assertStringNotContainsString( "array( 'network', 'trusted_proxies', 'edgeone' )", $body, 'EdgeOne 空预设 UI 隐藏（定稿 §5.3——I0 行为保持）' );

		// 受信网段 textarea + CIDR 校验按钮（前端校验形态——零新端点纪律）。
		$this->assertStringContainsString( "array( 'network', 'trusted_proxies', 'custom' )", $body, '受信网段 textarea' );
		$this->assertStringContainsString( 'id="wk-iprep-cidr-check"', $body, 'CIDR 校验按钮' );
		$this->assertStringContainsString( 'id="wk-iprep-custom-cidr"', $body, 'CIDR textarea 锚（JS 消费）' );
		$this->assertStringContainsString( 'id="wk-iprep-cidr-result"', $body, 'CIDR 校验结果位' );
	}

	public function test_zone_source_no_inline_styles(): void {
		$this->assertStringNotContainsString( 'style="', self::body( 'render_iprep_zone_source' ), '样式全走 CSS 类（FIX-B 纪律）' );
	}

	/* ---------- 分区② IP 信誉查询 ---------- */

	public function test_zone_check_controls(): void {
		$body = self::body( 'render_iprep_zone_check' );

		$this->assertStringContainsString( 'id="wk-iprep-check"', $body, '分区② DOM 锚' );
		// enabled 开关（hidden 0 伴随域 + checkbox——仓库 checkbox 契约）。
		$this->assertStringContainsString( "array( 'iprep', 'enabled' )", $body, '启用开关' );
		// 驱动下拉（AbuseIPDB——03 蓝本「驱动下拉（AbuseIPDB）」）。
		$this->assertStringContainsString( "array( 'iprep', 'driver' )", $body, '驱动下拉' );
		// api_key password 型（P0-α 教训——password_field 单点渲染）。
		$this->assertStringContainsString( "array( 'iprep', 'abuseipdb', 'api_key' )", $body, 'api_key 字段路径' );
		$this->assertStringContainsString( 'self::password_field( array( \'iprep\', \'abuseipdb\', \'api_key\' )', $body, 'api_key 走 password_field（防 P0-α 丢键）' );
		// 阈值双滑杆（85/60）。
		$this->assertStringContainsString( "array( 'iprep', 'abuseipdb', 'malicious_score' )", $body, '恶意阈值控件' );
		$this->assertStringContainsString( "array( 'iprep', 'abuseipdb', 'suspect_score' )", $body, '疑似阈值控件' );
		$this->assertSame( 2, substr_count( $body, 'type="range"' ), '阈值双滑杆=range 控件×2' );
		// 软顶 + 日配额。
		$this->assertStringContainsString( "array( 'iprep', 'abuseipdb', 'soft_quota' )", $body, '软顶控件' );
		$this->assertStringContainsString( '3000', $body, '软顶默认 3000' );
		$this->assertStringContainsString( "array( 'iprep', 'abuseipdb', 'daily_quota' )", $body, '日配额控件' );
		$this->assertStringContainsString( '4750', $body, '日配额 4750（950 漂移修正后的实况值）' );
		// 缓存双键——cache_minutes 在 abuseipdb 子树 / negative_cache_minutes 在 iprep 顶层（实况键名）。
		$this->assertStringContainsString( "array( 'iprep', 'abuseipdb', 'cache_minutes' )", $body, 'pass 缓存键（abuseipdb 子树）' );
		$this->assertStringContainsString( "array( 'iprep', 'negative_cache_minutes' )", $body, '负缓存键（iprep 顶层——实况键名非 abuseipdb 子树）' );
		// timeout 1–5s 防呆范围。
		$this->assertStringContainsString( "array( 'iprep', 'abuseipdb', 'timeout' )", $body, '超时控件' );
		$this->assertStringContainsString( 'max="5"', $body, 'timeout 上限 5s' );
	}

	public function test_zone_check_test_credentials_button_and_health_line(): void {
		$body = self::body( 'render_iprep_zone_check' );

		$this->assertStringContainsString( 'id="wk-iprep-test-credentials"', $body, '「测试凭据」按钮锚' );
		$this->assertStringContainsString( 'wp_create_nonce( self::AJAX_TEST_IPREP )', $body, 'nonce 随按钮注入' );
		$this->assertStringContainsString( 'id="wk-iprep-test-result"', $body, '三态结果位（aria-live）' );
		$this->assertStringContainsString( 'isPublic', $body, '判定法注记：isPublic:false 亦绿（O5-P2-6）' );
		// 健康行：state_label 三态 + 停转态消费。
		$this->assertStringContainsString( 'IpReputationService::state_label', $body, '健康行走 state_label 三态' );
		$this->assertStringContainsString( 'DriverHealth::state_today', $body, '停转态消费（config_error/quota_exhausted）' );
	}

	public function test_zone_check_soft_quota_yellow_bar(): void {
		$body = self::body( 'render_iprep_zone_check' );

		$this->assertStringContainsString( 'daily_total_for', $body, '软顶命中判定消费 Usage 当日计数' );
		$this->assertStringContainsString(
			'当日查询配额已用尽——今日人机验证将临时对所有访客强制，次日自动恢复',
			$body,
			'软顶命中黄条文案【O5 回写·P2-4】'
		);
		$this->assertStringContainsString( 'notice-warning', $body, '黄条形态' );
	}

	/* ---------- 分区③ 上报 ---------- */

	public function test_zone_report_master_switch_and_outbound_bar(): void {
		$body = self::body( 'render_iprep_zone_report' );

		$this->assertStringContainsString( 'id="wk-iprep-report"', $body, '分区③ DOM 锚' );
		$this->assertStringContainsString( "array( 'iprep', 'report', 'enabled' )", $body, '上报总开关' );
		$this->assertStringContainsString( '启用后访客 IP 将提交至境外服务 AbuseIPDB', $body, '出境黄条（PIPL 提示）' );
		$this->assertStringContainsString( 'notice-warning', $body, '出境提示为黄条形态' );
	}

	public function test_zone_report_trigger_groups_t1_to_t4(): void {
		$body = self::body( 'render_iprep_zone_report' );

		// 每面开关（T1–T4）。
		foreach ( array( 'captcha_failed', 'review_reject', 'login_attempts', 'local_rules_fail' ) as $face ) {
			$this->assertStringContainsString( "array( 'iprep', 'report', 'triggers', '{$face}' )", $body, "触发面 {$face} 开关" );
			$this->assertStringContainsString( "array( 'iprep', 'report', 'comment_{$face}' )", $body, "触发面 {$face} Comment 自定义框" );
			$this->assertStringContainsString( "array( 'iprep', 'report', 'category_{$face}' )", $body, "触发面 {$face} 类目下拉" );
		}
		// 阈值框仅 T2/T4（T1/T3 无阈值框——T3 沿用 LoginCounters 10 不单设）。
		$this->assertStringContainsString( "array( 'iprep', 'report', 'triggers', 'review_reject_threshold' )", $body, 'T2 阈值框' );
		$this->assertStringContainsString( "array( 'iprep', 'report', 'triggers', 'local_rules_fail_threshold' )", $body, 'T4 阈值框' );
		$this->assertStringNotContainsString( 'login_attempts_threshold', $body, 'T3 无阈值框（沿用 10 次/IP 不单设）' );
		// 类目下拉数据源=AbuseIpdbCategories 全表 + 默认类目标注。
		$this->assertStringContainsString( 'AbuseIpdbCategories::MAP', $body, '类目下拉 1–23 全表' );
		$this->assertStringContainsString( 'AbuseIpdbCategories::default_for', $body, '每面默认类目标注' );
		// Comment 框提示：可留空用默认 + 占位符白名单 + 公开可见警示（定稿 §4.5）。
		$this->assertStringContainsString( '可留空用默认', $body, 'Comment 占位提示' );
		$this->assertStringContainsString( '{event_no}', $body, '占位符白名单提示' );
		$this->assertStringContainsString( 'AbuseIPDB 报告详情公开可见', $body, '公开可见警示' );
	}

	public function test_zone_report_readonly_gates_and_selfcheck_line(): void {
		$body = self::body( 'render_iprep_zone_report' );

		// 冷却/限速/日顶只读展示（无输入控件——assert 这三键不以 field_name 形态出现）。
		$this->assertStringContainsString( '每 IP 冷却', $body );
		$this->assertStringContainsString( '全局限速', $body );
		$this->assertStringContainsString( '日顶', $body );
		$this->assertStringNotContainsString( "array( 'iprep', 'report', 'cooldown_minutes' )", $body, '冷却只读展示（无输入控件）' );
		$this->assertStringNotContainsString( "array( 'iprep', 'report', 'hourly_limit' )", $body, '限速只读展示' );
		$this->assertStringNotContainsString( "array( 'iprep', 'report', 'daily_cap' )", $body, '日顶只读展示' );
		// 启用门禁状态行（real_ip_self_check 消费；未过=红字强制失效文案）。
		$this->assertStringContainsString( 'AbuseIpdbService::real_ip_self_check', $body, '启用门禁自检消费' );
		$this->assertStringContainsString( '上报已强制失效：真实 IP 解析异常（NAT/反代共显）', $body, '未过门禁红字文案' );
	}

	/* ---------- 分区④ 说明卡 ---------- */

	public function test_zone_notes_three_states_and_soft_quota_plain_line(): void {
		$body = self::body( 'render_iprep_zone_notes' );

		$this->assertStringContainsString( 'id="wk-iprep-notes"', $body, '分区④ DOM 锚' );
		$this->assertStringContainsString( '跳过（skip）', $body, 'skip 人话行' );
		$this->assertStringContainsString( '通过（pass）', $body, 'pass 人话行' );
		$this->assertStringContainsString( '不通过（fail）', $body, 'fail 人话行' );
		$this->assertStringContainsString( '老朋友', $body, '信用免验联动说明（iprep fail 老朋友通道也过码）' );
		$this->assertStringContainsString(
			'当日查询配额用尽后，验证码将临时对所有访客强制——次日自动恢复，非故障',
			$body,
			'软顶人话句【O5 回写·P2-4】'
		);
	}

	/* ---------- 测试凭据 AJAX 端点 ---------- */

	public function test_ajax_action_constant_registered_in_bootstrap(): void {
		$this->assertStringContainsString(
			"const AJAX_TEST_IPREP = 'wink_gatekeeper_test_iprep'",
			self::settings_src(),
			'AJAX action 常量'
		);
		$bootstrap = self::source( 'includes/Bootstrap.php' );
		$this->assertStringContainsString( 'SettingsPage::AJAX_TEST_IPREP', $bootstrap, 'Bootstrap 须注册 wp_ajax 钩子' );
	}

	public function test_ajax_handler_double_gate_and_judgement_chain(): void {
		$body = self::functionBody( self::settings_src(), 'ajax_test_iprep' );

		$this->assertStringContainsString( 'check_ajax_referer( self::AJAX_TEST_IPREP', $body, 'nonce 闸' );
		$this->assertStringContainsString( "current_user_can( 'manage_options' )", $body, '能力闸（B4 声明式纪律）' );
		$this->assertStringContainsString( 'local_precheck_abuseipdb', $body, '黄态本地预检（api_key 空不发远端）' );
		$this->assertStringContainsString( 'probe_credentials', $body, '最小查询走 AbuseIpdbService check 通道' );
		$this->assertStringContainsString( 'classify_abuseipdb', $body, '三态分类' );
		$this->assertStringContainsString( 'probe_field( $post, \'api_key\'', $body, '表单值覆盖（password 空串保留已存值——Tab② 先例语义）' );
	}

	public function test_ajax_handler_zero_persistence(): void {
		$body = self::functionBody( self::settings_src(), 'ajax_test_iprep' );

		foreach ( array( 'set_transient', 'update_option', 'add_option', 'bump_option', 'mark_report_at' ) as $bad ) {
			$this->assertStringNotContainsString( $bad, $body, "零落库红线：函数体禁 {$bad}" );
		}
		$this->assertStringNotContainsString( 'usage_key', $body, '零统计红线：探测调用不带 usage_key' );
	}

	/** 最小查询形态：127.0.0.2 保留地址 + maxAgeInDays=1 省配额（任务书 B 节）。 */
	public function test_probe_credentials_minimal_request_shape(): void {
		$body = self::functionBody( self::source( 'includes/ip/AbuseIpdbService.php' ), 'probe_credentials' );

		$this->assertStringContainsString( '127.0.0.2', $body, '查询保留地址省配额' );
		$this->assertStringContainsString( 'maxAgeInDays=1', $body, '最小窗口' );
		$this->assertStringContainsString( '\'Key\'    => $this->api_key()', $body, 'Key 头' );
		$this->assertStringNotContainsString( 'usage_key', $body, '探测零计量' );
		$this->assertStringNotContainsString( 'write_cache', $body, '探测零缓存写' );
		$this->assertStringNotContainsString( 'set_backoff', $body, '探测零退避写' );
		$this->assertStringNotContainsString( 'health_mark', $body, '探测零停转写' );
	}

	/* ---------- JS 接线 ---------- */

	public function test_admin_brand_js_bindings(): void {
		$js = self::source( 'assets/js/admin-brand.js' );

		$this->assertStringContainsString( 'wk-iprep-test-credentials', $js, '测试凭据按钮绑定' );
		$this->assertStringContainsString( 'wink_gatekeeper_test_iprep', $js, 'AJAX action 名' );
		$this->assertStringContainsString( 'wk-iprep-test-result', $js, '三态结果渲染位' );
		$this->assertStringContainsString( 'wk-probe--', $js, '三态样式类消费（Tab② 先例同款）' );
		$this->assertStringContainsString( 'wk-iprep-cidr-check', $js, 'CIDR 校验按钮绑定' );
		$this->assertStringContainsString( 'wk-iprep-custom-cidr', $js, 'CIDR textarea 取值' );
		$this->assertStringContainsString( 'wk-score-slider', $js, '阈值滑杆联动（output 数值随动）' );
	}
}
