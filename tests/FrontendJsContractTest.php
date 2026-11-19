<?php
/**
 * 前台交互 JS 契约测试（03 §2.1【v1.5·F4-1/F4-2】）。
 *
 * 本仓库单测层为纯 PHP、零 WP 依赖（G5），无 JS 测试运行器——前端交互行为以
 * 「源码契约测试」（钉死文案键/三清除时机的落位与常量）+ 真机自测（行为）双层承载。
 * 契约面：
 *   F4-1 免验轻提示：trust_pass 分支按钮文案用 wk_trust_hint（「老朋友，免验放行」，
 *        区别于真验通过的「已通过验证」）；Bootstrap wkGateL10n 键集补 wk_trust_hint；
 *        按钮 aria-live="polite" 承载播报（渲染层既有）。
 *   F4-2 内联错误条三清除时机（互不替代、全部实施）：
 *     ① 重新预检通过时清除（trust_pass 与 captcha_needed 两成功分支）；
 *     ② 评论框/昵称/邮箱 input 事件防抖（300ms）清除；
 *     ③ TrustStub 前端 TTL 过期回退同拍清除。
 *   清除调度集中为 scheduleMsgClear/cancelMsgClear（防抖纯逻辑），showMsgBar 取消
 *   未决清除（新错误不被旧计时器吃掉）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;

final class FrontendJsContractTest extends TestCase {

	private static function js(): string {
		$src = file_get_contents( __DIR__ . '/../assets/js/gatekeeper.js' );
		self::assertIsString( $src, 'gatekeeper.js 必须可读' );
		return (string) $src;
	}

	private static function bootstrap(): string {
		$src = file_get_contents( __DIR__ . '/../includes/Bootstrap.php' );
		self::assertIsString( $src, 'Bootstrap.php 必须可读' );
		return (string) $src;
	}

	/**
	 * 提取具名函数体（花括号配平）——契约断言按函数作用域收窄，防全局误命中。
	 */
	private static function functionBody( string $src, string $name ): string {
		$start = strpos( $src, 'function ' . $name );
		self::assertNotFalse( $start, "函数 {$name} 必须存在" );
		$open = strpos( $src, '{', $start );
		$depth = 0;
		$len = strlen( $src );
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

	/* ---------- F4-1 免验轻提示 ---------- */

	public function test_trust_pass_branch_uses_trust_hint_copy(): void {
		$body = self::functionBody( self::js(), 'onTrustPass' );
		self::assertStringContainsString(
			"l10n( 'wk_trust_hint', 'Old friend, no verification needed' )",
			$body,
			'trust_pass 分支按钮文案必须用免验轻提示（区别于 done=已通过验证）'
		);
		self::assertStringNotContainsString(
			"l10n( 'done', 'Verification passed' )",
			$body,
			'trust_pass 不再复用真验通过文案——免验发生了要前台可感知'
		);
	}

	public function test_bootstrap_l10n_keyset_contains_trust_hint(): void {
		$src = self::bootstrap();
		self::assertStringContainsString( "'wk_trust_hint'", $src, 'wkGateL10n 键集须补 wk_trust_hint' );
		self::assertStringContainsString( 'Old friend, no verification needed', $src );
	}

	/* ---------- F4-2 三清除时机 ---------- */

	/**
	 * ① 重新预检通过时清除：trust_pass 与 captcha_needed 两个成功分支都清错误条。
	 */
	public function test_timing1_precheck_success_branches_clear_msg_bar(): void {
		$js = self::js();
		self::assertStringContainsString(
			'hideMsgBar( container )',
			self::functionBody( $js, 'onTrustPass' ),
			'trust_pass 分支须清错误条'
		);
		self::assertStringContainsString(
			'hideMsgBar( container )',
			self::functionBody( $js, 'releaseToAdapter' ),
			'captcha_needed 放行分支（预检成功转验证码链）须清错误条'
		);
	}

	/**
	 * ② input 事件防抖清除：armPrecheckGate 的 input 监听改走 scheduleMsgClear（300ms），
	 * 不再即时清除；清除调度集中且 showMsgBar 取消未决清除。
	 */
	public function test_timing2_input_debounce_clear(): void {
		$js  = self::js();
		$arm = self::functionBody( $js, 'armPrecheckGate' );
		self::assertStringContainsString( 'scheduleMsgClear( container )', $arm, 'input 事件须走防抖清除' );
		self::assertStringNotContainsString( 'hideMsgBar( container )', $arm, 'input 事件不再即时清除（旧错误对新草稿无意义但防抖归口一处）' );

		self::assertMatchesRegularExpression( '/MSG_CLEAR_MS\s*=\s*300\b/', $js, '防抖时长常量=300ms（03 §2.1 v1.5 定稿）' );
		$schedule = self::functionBody( $js, 'scheduleMsgClear' );
		self::assertStringContainsString( 'clearTimeout', $schedule, '防抖：未决计时器须先取消' );
		self::assertStringContainsString( 'hideMsgBar( container )', $schedule, '到点清除错误条' );
		self::assertStringContainsString( 'MSG_CLEAR_MS', $schedule );
	}

	public function test_show_msg_bar_cancels_pending_clear(): void {
		self::assertStringContainsString(
			'cancelMsgClear( container )',
			self::functionBody( self::js(), 'showMsgBar' ),
			'新错误展示时须取消未决防抖清除（不被旧计时器吃掉）'
		);
	}

	/**
	 * ③ TrustStub 前端 TTL 过期回退同拍清除：onTrustPass 的 TTL 计时器回调内清错误条。
	 */
	public function test_timing3_trust_ttl_expire_clears_msg_bar(): void {
		$body = self::functionBody( self::js(), 'onTrustPass' );
		self::assertGreaterThanOrEqual(
			2,
			substr_count( $body, 'hideMsgBar( container )' ),
			'①trust_pass 与 ③TTL 过期回退两拍各清一次错误条'
		);
		self::assertStringContainsString( 'wk-gate-expire', $body, 'TTL 过期回退须派发 expire 事件（既有契约不回归）' );
	}

	/* ---------- 【W2·无按钮模式】submit-gate 模块（0.8.4，§2.1 统一拦截时序①-⑦） ---------- */

	/**
	 * 三防之一（带分号调用形态+函数体收窄）：submit-gate 拦截监听=捕获阶段（先于 H5-a
	 * 与第三方 bubble 监听——Enter 隐式提交/密码管理器 requestSubmit/点击三路径全覆盖）。
	 */
	public function test_submit_gate_capture_phase_listener(): void {
		$arm = self::functionBody( self::js(), 'armSubmitGate' );
		self::assertStringContainsString( "addEventListener( 'submit'", $arm, 'submit-gate 拦截监听在场' );
		self::assertStringContainsString( ', true );', $arm, '捕获阶段注册（capture=true）' );
	}

	/**
	 * 三防之二（函数体收窄）：续提机制 = requestSubmit + form 级一次性 bypass 标志
	 * （拦截链①防递归门），老环境 fallback form.submit()（丢监听链的如实降级）。
	 */
	public function test_submit_gate_resubmit_uses_request_submit_with_bypass(): void {
		$body = self::functionBody( self::js(), 'resubmitForm' );
		self::assertStringContainsString( 'data-wk-resubmit', $body, '一次性 bypass 标志（程序化续提放行——防递归）' );
		self::assertStringContainsString( 'requestSubmit', $body, 'requestSubmit 续提（submit 事件重入、监听链完整——AJAX 评论主题兼容）' );
		self::assertStringContainsString( 'form.submit()', $body, '老环境 fallback（无 requestSubmit——如实降级）' );
	}

	/**
	 * 三防之三（条件-锚位序）：拦截链首段次序语义不变（R5 分层重排）——
	 * G1 原生校验 → 预检门 → 人机验证。
	 */
	public function test_submit_gate_guard_order_g1_precheck_verify(): void {
		$arm    = self::functionBody( self::js(), 'armSubmitGate' );
		$g1     = strpos( $arm, 'nativeValidityGuard( form )' );
		$pre    = strpos( $arm, 'runPrecheck(' );
		$verify = strpos( $arm, 'startVerification(' );
		self::assertGreaterThan( 0, $g1, 'G1 原生校验在拦截链首段' );
		self::assertGreaterThan( $g1, $pre, '次序：G1 → 预检门（原生管完整性、四闸管内容）' );
		self::assertGreaterThan( $pre, $verify, '次序：预检门 → 人机验证（captcha_needed 续接 requestVerify）' );
	}

	/**
	 * R4：gating 期抑制 H5-a「正在接收检查」Toast（语义错位）——H5-a 监听内首行标志检查，
	 * 条件锚先于 Toast 调用锚（bootContainer 函数体内锚位序）。
	 */
	public function test_submit_gate_gating_suppresses_h5a_toast(): void {
		$js    = self::js();
		$boot  = self::functionBody( $js, 'bootContainer' );
		$flag  = strpos( $boot, 'data-wk-gating' );
		$toast = strpos( $boot, 'showToast(' );
		self::assertGreaterThan( 0, $flag, 'H5-a 监听内 gating 抑制分支在场' );
		self::assertLessThan( $toast, $flag, '抑制分支先于 Toast 调用（条件-锚位序）' );
		self::assertStringContainsString( 'data-wk-gating', self::functionBody( $js, 'startVerification' ), 'gating 标志由拦截链设置（⑤ 人机验证期）' );
	}

	/**
	 * R10：状态外显=Toast 单例（按钮 hidden 后的主播报面）+ 契约缺失/抛错回退经典形态
	 * （降级矩阵：适配器故障=无拦截+服务端兜底）。requestVerify 消费走注册表元数据
	 * （driver-agnostic 不回归——test_gatekeeper_dispatcher_stays_driver_id_agnostic 照钉）。
	 */
	public function test_submit_gate_toast_surface_and_classic_fallback(): void {
		$body = self::functionBody( self::js(), 'startVerification' );
		self::assertStringContainsString( 'requestVerify', $body, '适配器契约消费（byId 注册表查找——不认驱动 id）' );
		self::assertStringContainsString( 'showToast', $body, 'Toast 状态播报（引导文案——验证期反馈主通道）' );
		self::assertStringContainsString( 'restoreClassicButton', $body, '契约缺失/抛错回退经典形态（按钮 hidden 还原）' );
	}

	/**
	 * R3：armContainer 按 data-submit-gate 分流——无按钮分支 C1 禁用四件停用（refreshLocks/
	 * guardEnterKey 跳过）；markToken 对无按钮容器跳过 refreshLocks（票据过期回退不得禁用
	 * 提交控件——恒可点是本模式存在前提）。
	 */
	public function test_arm_container_and_mark_token_branch_by_submit_gate(): void {
		$js = self::js();
		self::assertStringContainsString( 'isSubmitGate( container )', self::functionBody( $js, 'armContainer' ), 'armContainer 分流（无按钮分支装配 submit-gate）' );
		self::assertStringContainsString( 'armSubmitGate( container, form )', self::functionBody( $js, 'armContainer' ), '无按钮分支装配 submit-gate 模块' );
		self::assertStringContainsString( 'isSubmitGate( container )', self::functionBody( $js, 'markToken' ), 'markToken 无按钮容器跳过 refreshLocks（禁用四件停用——R3）' );
	}
}
