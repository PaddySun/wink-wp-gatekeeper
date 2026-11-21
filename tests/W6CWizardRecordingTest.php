<?php
/**
 * W6-C 批 · 向导步一提交触发形态「真实录屏」接入回归测试（用户 2026-11-21 拍板项）。
 *
 * 素材出处：用户提供的 v0.9.1 实录（C:/Users/paddy/Desktop/dev/wink-wp-gatekeeper/
 * src/wk-demo-v091-extract/ 解包）——无按钮模式-弹层验证码.gif（407,670B）+
 * 有按钮模式-嵌入式验证码.gif（98,846B）；MP4（3.18MB）不入库（任务书红线）。
 *
 * 改造契约：
 *   ① 合成 CSS/JS 四步文字帧循环退役（连同其内联 script 与 wk-flow-step 帧结构）；
 *   ② wk-flow-demo 区=双实录卡（两 figure：标题 + loading=lazy img + alt；四步文字
 *      保留为无按钮卡下说明列表——信息密度裁决：③自动续提语义与④链路顺序为 GIF
 *      画面不承载的独立信息，退役=信息损失）；
 *   ③ 说明行改「真实录屏（v0.9.1 实录）」语义；引言行同步退役「illustrative」措辞；
 *   ④ radio（captcha.submit_flow）与两卡联动显隐=轻量 JS class 切换（零状态语义，
 *      初始 .on 由服务端按 $submit_flow 渲染）；
 *   ⑤ prefers-reduced-motion：img 非 CSS 动画件天然可看（登记不设守卫——旧内联
 *      matchMedia 面退役）；GIF 原生循环属浏览器行为非 CSS 动画面。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Settings\SettingsPage;

final class W6CWizardRecordingTest extends TestCase {

	/* -----------------------------------------------------------------
	 * 源码提取件（W3BatchCTest function_body 同形态）
	 * ----------------------------------------------------------------- */

	private static function src( string $rel ): string {
		$src = file_get_contents( __DIR__ . '/../' . $rel );
		self::assertIsString( $src, "{$rel} 必须可读" );
		return (string) $src;
	}

	private static function function_body( string $src, string $name ): string {
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
					return substr( $src, (int) $open, $i - (int) $open + 1 );
				}
			}
		}
		self::fail( "函数 {$name} 花括号不配平" );
		return '';
	}

	private static function body( string $name ): string {
		return self::function_body( self::src( 'includes/settings/SettingsPage.php' ), $name );
	}

	/* ===============================================================
	 * 资产存在钉（MP4 防线：尺寸上限钉）
	 * =============================================================== */

	public function test_demo_gif_assets_present_and_size_bounded(): void {
		foreach ( array( 'assets/img/demo-trigger-nobutton.gif', 'assets/img/demo-trigger-classic.gif' ) as $rel ) {
			$path = __DIR__ . '/../' . $rel;
			$this->assertFileExists( $path, "实录素材入库 {$rel}（用户 2026-11-21 提供 v0.9.1 实录）" );
			$size = (int) filesize( $path );
			$this->assertGreaterThan( 0, $size, "{$rel} 非空" );
			$this->assertLessThan( 600 * 1024, $size, "{$rel} 尺寸上限钉（防未来塞 MP4/大件——现值 407,670B 与 98,846B）" );
			$handle = fopen( $path, 'rb' );
			$magic  = (string) fread( $handle, 4 );
			fclose( $handle );
			$this->assertSame( 'GIF8', $magic, "{$rel} 为 GIF 格式（MP4 红线）" );
		}
	}

	/* ===============================================================
	 * 双实录卡渲染面
	 * =============================================================== */

	public function test_wizard_renders_both_recording_cards(): void {
		$wiz = self::body( 'render_wizard' );

		$this->assertStringContainsString( "WINK_GATEKEEPER_URL . 'assets/img/demo-trigger-nobutton.gif'", $wiz, '无按钮实录 GIF 引用（插件 URL 拼接与 logo 同式）' );
		$this->assertStringContainsString( "WINK_GATEKEEPER_URL . 'assets/img/demo-trigger-classic.gif'", $wiz, '经典按钮实录 GIF 引用' );
		$this->assertStringContainsString( 'loading="lazy"', $wiz, '懒加载' );
		$this->assertStringContainsString( 'data-wk-flow-demo="on_submit"', $wiz, '无按钮卡联动锚' );
		$this->assertStringContainsString( 'data-wk-flow-demo="button"', $wiz, '经典卡联动锚' );
		$this->assertStringContainsString( 'No-button mode (current default)', $wiz, '无按钮卡标题（新键）' );
		$this->assertStringContainsString( 'Classic button mode', $wiz, '经典卡标题（新键）' );
		$this->assertGreaterThanOrEqual( 2, substr_count( $wiz, 'wk-flow-card' ), '两 figure 卡并存' );
	}

	public function test_wizard_real_recording_note_and_intro(): void {
		$wiz = self::body( 'render_wizard' );

		// 【0.9.6 批 N1】说明行整体退役（用户 2026-11-21：「不用文字描述了，gif 已经够
		// 说明问题了」）——引言行（两句导览）保留，仅去 wk-flow-note 说明行。
		$this->assertStringNotContainsString( 'Real screen recordings (captured on v0.9.1)', $wiz, '说明行退役零残留（批 N1 用户拍板 GIF 自明）' );
		$this->assertStringContainsString( 'How visitors get challenged — two flows, compared in the two recordings below (real captures from v0.9.1).', $wiz, '引言行=实录措辞新键（保留）' );

		$this->assertStringNotContainsString( 'illustrative, not a live recording', $wiz, '旧「示意图非实录」措辞退役' );
		$this->assertStringNotContainsString( 'Looped demo:', $wiz, '旧循环演示说明行退役' );
	}

	public function test_wizard_four_step_list_retired(): void {
		// 【0.9.6 批 N1】四步文字列表整体退役（用户拍板：GIF 自明无需文字描述）——
		// 五键（四步+说明行）catalog 退役形态，i18n 计数随批 -5。
		$wiz = self::body( 'render_wizard' );

		$this->assertStringNotContainsString( '① Submit — the visitor clicks “Post Comment”', $wiz, '步① 退役零残留' );
		$this->assertStringNotContainsString( '② Verify — the slider / smart check completes', $wiz, '步② 退役零残留' );
		$this->assertStringNotContainsString( '③ Auto-resubmit — no second click needed', $wiz, '步③ 退役零残留' );
		$this->assertStringNotContainsString( '④ Landing — the comment enters the built-in rules', $wiz, '步④ 退役零残留' );
		$this->assertStringNotContainsString( 'wk-flow-list', $wiz, '说明列表容器退役零残留' );
	}

	/* ===============================================================
	 * 合成帧退役零残留
	 * =============================================================== */

	public function test_wizard_synthetic_frames_zero_residue(): void {
		$wiz = self::body( 'render_wizard' );

		$this->assertStringContainsString( 'wk-flow-demo', $wiz, 'wk-flow-demo 类族保留（W3BatchCTest 在场钉语义不变）' );
		$this->assertStringNotContainsString( 'wk-flow-step', $wiz, '合成四步帧结构退役零残留' );
		$this->assertStringNotContainsString( 'setInterval', $wiz, '合成循环 JS 退役零残留' );
		$this->assertStringNotContainsString( 'matchMedia', $wiz, 'reduced-motion JS 守卫随合成循环退役（img 非 CSS 动画件天然可看——登记面）' );
		$this->assertStringNotContainsString( 'wk-flow-frame', $wiz, '合成帧容器退役' );
	}

	/* ===============================================================
	 * radio ↔ 两卡联动（轻量 JS class 切换，零状态语义）
	 * =============================================================== */

	public function test_wizard_radio_linkage_binding(): void {
		$wiz = self::body( 'render_wizard' );

		$this->assertStringContainsString( 'data-wk-radio-group="submit-flow"', $wiz, 'radio 组锚（既有）' );
		$this->assertStringContainsString( "querySelectorAll( '[data-wk-flow-demo]' )", $wiz, '联动 JS 消费两卡锚' );
		$this->assertStringContainsString( 'data-wk-radio-group="submit-flow"', $wiz );
		$this->assertStringContainsString( "querySelector( '[data-wk-radio-group=\"submit-flow\"]' )", $wiz, '联动 JS 监听 radio 组 change' );
		$this->assertStringContainsString( "classList.toggle( 'on'", $wiz, '选中形态高亮/未选去高亮（一行式 class 切换）' );

		$card = self::function_body( self::src( 'includes/settings/SettingsPage.php' ), 'render_wizard' );
		$this->assertStringContainsString( "'on_submit' === \$submit_flow ? ' on' : ''", $card, '初始 .on 服务端按当前形态渲染（零状态语义——刷新即复位）' );
	}

	/* ===============================================================
	 * W3BatchCTest 旧钉翻转核验（防回归读）
	 * =============================================================== */

	public function test_w3batchc_demo_pin_flipped_not_reverted(): void {
		$src = self::src( 'tests/W3BatchCTest.php' );

		$this->assertStringContainsString( "assertStringNotContainsString( 'wk-flow-step'", $src, '旧帧结构钉已翻转为零残留断言' );
		$this->assertStringNotContainsString( "assertStringContainsString( 'wk-flow-step'", $src, '旧在场断言零残留（翻转=改断言非删测试）' );
	}
}
