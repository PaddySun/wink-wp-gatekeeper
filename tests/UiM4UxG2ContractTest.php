<?php
/**
 * M4UX G2 批 · 本地内容四闸预检失败 → 浏览器原生气泡（后台自定义文案）契约测试（2026-11-14）。
 *
 * 用户需求原话：「评论全英文点完成人机验证后浏览器原生提示框弹出『这条评论看起来像
 * 模板群发的第 4,000 份…』——不写死，用同样的后台自定义文案」。
 *
 * 骨架契约面（源码契约测试，纯 PHP 零 WP 依赖；行为面由真机 DOM 断言承载）：
 *   失败源过滤——仅本地内容四闸 rule_hit 值域（too_short/too_long/no_chinese/banned_word，
 *   =LocalRulesDriver Verdict::reject reason=Precheck four_gate_fail 面唯一来源）挂原生；
 *   captcha_missing/IP 信誉等其余失败维持现形态（releaseToAdapter→适配器/wk-gate-msg），不挂原生。
 *   文案动态——setCustomValidity( message )，message=预检响应 message（工坊文案经
 *   Copywork/Labels 渲染值），零写死。
 *   fallback——评论框元素/setCustomValidity/reportValidity 缺失（老环境）→ 既有 wk-gate-msg
 *   通道（渐进增强，同 G1 守卫哲学；wk-gate-msg 保留不删）。
 *   清除时机三处（做漏=字段 invalid 挡住正常评论提交——灾难面）：
 *     ① 评论框 input 事件（用户改内容即解禁，先于 msg 条防抖——气泡即时消失）；
 *     ② 预检成功/信用免验签发路径（releaseToAdapter 转验证码链 / onTrustPass trust_pass）；
 *     ③ 验证通过态设置处（markToken present=true——适配器 wk-gate-token 统一入口）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;

final class UiM4UxG2ContractTest extends TestCase {

	private static function source( string $rel ): string {
		$src = file_get_contents( __DIR__ . '/../' . $rel );
		self::assertIsString( $src, "{$rel} 必须可读" );
		return (string) $src;
	}

	private static function js(): string {
		return self::source( 'assets/js/gatekeeper.js' );
	}

	/**
	 * 提取具名函数体（花括号配平）——契约断言按作用域收窄，防全局误命中。
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

	/* ===================================================================
	 * 失败源过滤矩阵（内容四闸 → 原生气泡；其余失败面维持现形态）
	 * =================================================================== */

	/**
	 * 本地内容四闸 rule_hit 值域钉死：与 LocalRulesDriver Verdict::reject reason 一字不差。
	 * hold_moderation 类（Verdict::hold）不属于预检失败面（Precheck 只在 REJECT 分支返回
	 * four_gate_fail）——勿扩。
	 */
	public function test_g2_local_content_rule_hit_set_pinned(): void {
		$js = self::js();

		$this->assertStringContainsString( 'LOCAL_CONTENT_RULES', $js, '失败源过滤常量（内容四闸 rule_hit 集合）' );
		foreach ( array( 'too_short', 'too_long', 'no_chinese', 'banned_word' ) as $rule ) {
			$this->assertStringContainsString( $rule, $js, "内容四闸 rule_hit 值域含 {$rule}" );
		}
		$this->assertStringNotContainsString( 'hold_moderation', $js, 'hold_moderation 不入预检失败源集合（非预检失败面勿扩）' );
	}

	/**
	 * four_gate_fail 分支：先过滤 rule ∈ 内容四闸值域，命中才调 showNativeGateHint——
	 * 文案实参=预检响应 message（后台自定义文案动态传入，零写死）。
	 */
	public function test_g2_content_gate_fail_routes_to_native_bubble(): void {
		$body = self::functionBody( self::js(), 'runPrecheck' );

		$filter = strpos( $body, 'LOCAL_CONTENT_RULES[ data.rule ]' );
		$this->assertNotFalse( $filter, 'four_gate_fail 分支先按内容四闸值域过滤失败源' );
		$native = strpos( $body, 'showNativeGateHint( form' );
		$this->assertNotFalse( $native, '内容四闸失败 → showNativeGateHint（评论框原生气泡）' );
		$this->assertLessThan( $native, $filter, '失败源过滤先于原生气泡调用（captcha_missing 等其余失败不挂原生）' );

		$call = substr( $body, (int) $native, (int) strpos( $body, ')', $native ) - (int) $native );
		$this->assertStringContainsString( 'data.message', $call, '气泡文案=预检响应 message（后台自定义文案经 Copywork/Labels 渲染值——动态不写死）' );
		$this->assertStringNotContainsString( '这条评论', $call, '文案零写死（用户示例文案不进代码）' );
	}

	/**
	 * 非内容闸失败面维持现形态：releaseToAdapter（captcha_needed/error/超时放行适配器）
	 * 不调 showNativeGateHint——IP 信誉/captcha_missing 等失败不挂原生气泡。
	 */
	public function test_g2_non_content_failures_keep_existing_channel(): void {
		$js = self::js();
		$release = self::functionBody( $js, 'releaseToAdapter' );

		$this->assertStringNotContainsString( 'showNativeGateHint', $release, 'captcha_needed 放行路径不挂原生气泡（维持按钮态/适配器现有形态）' );

		// 全文件 showNativeGateHint = 定义 1 处 + 调用 1 处（four_gate_fail 内容四闸分支）——其余失败面零误挂。
		$this->assertSame( 2, substr_count( $js, 'showNativeGateHint(' ), 'showNativeGateHint 调用面仅 four_gate_fail 分支 1 处（定义另计）' );
	}

	/* ===================================================================
	 * 原生气泡机制与 fallback
	 * =================================================================== */

	/**
	 * showNativeGateHint 核心：commentField（#comment/textarea[name=comment]）+
	 * setCustomValidity( message ) + reportValidity() → 浏览器原生气泡（箭头指评论框）。
	 */
	public function test_g2_native_hint_calls_set_and_report_validity(): void {
		$body = self::functionBody( self::js(), 'showNativeGateHint' );

		$this->assertStringContainsString( 'setCustomValidity( message )', $body, '原生气泡文案=setCustomValidity(message)——动态后台文案' );
		$this->assertStringContainsString( 'reportValidity()', $body, 'reportValidity() → 浏览器原生气泡弹出' );
		$this->assertStringContainsString( 'return true', $body, '成功挂气泡返回 true（调用侧据此免走 wk-gate-msg）' );

		$field = self::functionBody( self::js(), 'commentField' );
		$this->assertStringContainsString( "'#comment'", $field, '评论框定位=核心 #comment（WP comment_form 默认 id）' );
		$this->assertStringContainsString( 'textarea[name="comment"]', $field, '主题覆写 id 时回退 name 选择器' );
	}

	/**
	 * fallback：评论框元素缺失/setCustomValidity/reportValidity 不可用（老环境）→
	 * 返回 false → 调用侧走既有 wk-gate-msg 通道（渐进增强；wk-gate-msg 保留不删）。
	 */
	public function test_g2_native_hint_fallback_to_msg_bar(): void {
		$js   = self::js();
		$body = self::functionBody( $js, 'showNativeGateHint' );

		$this->assertStringContainsString( "typeof field.setCustomValidity !== 'function'", $body, '方法缺失探测（老环境防御形态）' );
		$this->assertStringContainsString( "typeof field.reportValidity !== 'function'", $body, 'reportValidity 缺失探测' );
		$this->assertStringContainsString( 'return false', $body, 'fallback 判定返回 false' );

		$run = self::functionBody( $js, 'runPrecheck' );
		$fallback = strpos( $run, 'showMsgBar( container' );
		$this->assertNotFalse( $fallback, 'fallback 走既有 wk-gate-msg 通道（保留不删）' );
		$this->assertStringContainsString( '! showNativeGateHint( form', $run, '调用侧按返回值分派 fallback' );
	}

	/* ===================================================================
	 * 清除时机三处（做漏=字段 invalid 挡住正常评论提交——灾难面）
	 * =================================================================== */

	/**
	 * 清除①：评论框/表单控件 input 事件即清原生气泡（用户改内容即解禁；先于 msg 条
	 * 防抖清除——气泡即时消失不等 300ms）。
	 */
	public function test_g2_clear_hook_input_event(): void {
		$body = self::functionBody( self::js(), 'armPrecheckGate' );

		$clear = strpos( $body, 'clearNativeGateHint( form )' );
		$this->assertNotFalse( $clear, 'input 事件清除①：内容变更即解禁原生气泡' );
		$this->assertLessThan( (int) strpos( $body, 'scheduleMsgClear( container )' ), $clear, '原生气泡清除先于 msg 条防抖清除（气泡即时消失）' );
	}

	/**
	 * 清除②：预检成功两路径（captcha_needed 放行 releaseToAdapter / trust_pass 签发
	 * onTrustPass）同拍清原生气泡——成功链路上残留 invalid 会挡住提交。
	 */
	public function test_g2_clear_hook_precheck_success_paths(): void {
		$js = self::js();

		$this->assertStringContainsString( 'clearNativeGateHint( form )', self::functionBody( $js, 'releaseToAdapter' ), '清除②a：预检放行转验证码链同拍清气泡' );
		$this->assertStringContainsString( 'clearNativeGateHint', self::functionBody( $js, 'onTrustPass' ), '清除②b：信用免验签发路径同拍清气泡' );
	}

	/**
	 * 清除③：验证通过态设置处（markToken present=true——适配器 wk-gate-token 统一入口）
	 * 清原生气泡。
	 */
	public function test_g2_clear_hook_verified_state(): void {
		$body = self::functionBody( self::js(), 'markToken' );

		$this->assertStringContainsString( 'clearNativeGateHint', $body, '清除③：验证通过态设置处清原生气泡' );
		$token = strpos( $body, "data-wk-token', '1'" );
		$this->assertNotFalse( $token, '通过态标记保持' );
		$this->assertLessThan( (int) strpos( $body, 'clearNativeGateHint' ), $token, '清除发生在通过态设置之后（present=true 分支内）' );
	}

	/**
	 * 清除件幂等防御：clearNativeGateHint 对缺失环境零抛错（老字段/方法不存在即跳过）。
	 */
	public function test_g2_clear_function_defensive(): void {
		$body = self::functionBody( self::js(), 'clearNativeGateHint' );

		$this->assertStringContainsString( "setCustomValidity( '' )", $body, '清除=setCustomValidity 空串（合法态恢复）' );
		$this->assertStringContainsString( "typeof field.setCustomValidity === 'function'", $body, '方法缺失零抛错（防御形态）' );
	}

	/* ===================================================================
	 * 既有形态回归门（零误伤）
	 * =================================================================== */

	/**
	 * wk-gate-msg 通道保留不删：showMsgBar/ensureMsgBar/scheduleMsgClear 既有件原样在位，
	 * G1 reportValidity 守卫零改动。
	 */
	public function test_g2_existing_channels_and_g1_guard_untouched(): void {
		$js = self::js();

		foreach ( array( 'function showMsgBar', 'function ensureMsgBar', 'function scheduleMsgClear', 'function nativeValidityGuard' ) as $keep ) {
			$this->assertStringContainsString( $keep, $js, "既有件保留：{$keep}" );
		}
		$run = self::functionBody( $js, 'runPrecheck' );
		$this->assertStringContainsString( "'trust_pass' === data.result", $run, 'trust_pass 分支保持' );
	}

	/* ===================================================================
	 * 版本 0.5.6（四处钉 + Changelog）
	 * =================================================================== */

	public function test_version_bumped_to_0_5_6_in_four_places(): void {
		$entry  = self::source( 'wink-wp-gatekeeper.php' );
		$readme = self::source( 'readme.txt' );

		$this->assertMatchesRegularExpression( '/^ \* Version:\s+0\.5\.6$/m', $entry, '插件头 Version 0.5.6' );
		$this->assertStringContainsString( "define( 'WINK_GATEKEEPER_VERSION', '0.5.6' )", $entry, '版本常量 0.5.6' );
		$this->assertMatchesRegularExpression( '/^Stable tag: 0\.5\.6$/m', $readme, 'readme Stable tag 0.5.6' );
		$this->assertStringContainsString( '= 0.5.6 =', $readme, 'Changelog 0.5.6 条目' );
	}
}
