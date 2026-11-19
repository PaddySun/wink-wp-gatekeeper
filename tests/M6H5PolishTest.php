<?php
/**
 * M6·批 H5 · 收尾小批两件（2026-11-17 定稿 2026-11-18 施工，施工图 M6-H5-收尾小批-设计定稿——
 * TDD source-scan 契约）。
 *
 * H5-a 前台「正在接收检查」提示（真实提交期间内联提示+防重复提交）。
 * H5-b 后台保存按钮悬浮右下角（纯 CSS）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests {

	use PHPUnit\Framework\TestCase;

	final class M6H5PolishTest extends TestCase {

		private static function source( string $rel ): string {
			$src = file_get_contents( dirname( __DIR__ ) . '/' . $rel );
			self::assertIsString( $src, "{$rel} 必须可读" );
			return (string) $src;
		}

		public function test_h5a_front_hint_on_real_submit(): void {
			$js = self::source( 'assets/js/gatekeeper.js' );
			self::assertStringContainsString( 'Your comment is being checked, please wait', $js, '【H5-a】提交等待期提示文案在场' );
			self::assertStringContainsString( "addEventListener( 'submit'", $js, 'submit 监听（真实提交触发）' );
			self::assertStringContainsString( 'data-wk-submitting', $js, 'form 级防重复标志（双容器幂等）' );
			// 【0.8.3 形态翻转】Toast 气泡（用户反馈弃评论区下方内联条）——showToast 单例
			// +textContent 注入（XSS 红线）；不再消费 showMsgBar 内联条。
			self::assertStringContainsString( 'function showToast', $js, 'Toast 函数（body 级单例）' );
			$hint_block = substr( $js, (int) strpos( $js, "addEventListener( 'submit'" ), 700 );
			self::assertStringContainsString( 'showToast(', $hint_block, '提交监听消费 Toast（非内联条）' );
			$css = self::source( 'assets/css/front-gate.css' );
			self::assertStringContainsString( '.wk-gate-toast', $css, 'Toast 样式（顶部居中浮层）' );
			self::assertStringContainsString( 'position: fixed', $css, '浮层定位' );
		}

		public function test_h5b_admin_submit_floats(): void {
			$css = self::source( 'assets/css/admin-settings.css' );
			self::assertStringContainsString( 'position: fixed', $css, '【H5-b】保存按钮悬浮定位' );
			self::assertStringContainsString( '.wk-form > p.submit', $css, '悬浮锚=设置表单提交段（不误伤他处 .submit）' );
			self::assertStringContainsString( 'padding-bottom', $css, '表单底部补偿（悬浮层不遮挡末行）' );
		}
	}
}
