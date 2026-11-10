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
			"l10n( 'wk_trust_hint', '老朋友，免验放行' )",
			$body,
			'trust_pass 分支按钮文案必须用免验轻提示（区别于 done=已通过验证）'
		);
		self::assertStringNotContainsString(
			"l10n( 'done', '已通过验证' )",
			$body,
			'trust_pass 不再复用真验通过文案——免验发生了要前台可感知'
		);
	}

	public function test_bootstrap_l10n_keyset_contains_trust_hint(): void {
		$src = self::bootstrap();
		self::assertStringContainsString( "'wk_trust_hint'", $src, 'wkGateL10n 键集须补 wk_trust_hint' );
		self::assertStringContainsString( '老朋友，免验放行', $src );
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
}
