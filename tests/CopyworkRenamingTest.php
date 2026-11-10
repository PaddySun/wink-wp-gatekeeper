<?php
/**
 * F46 文案改词与改名测试（03 Tab⑤ v1.4·反馈5/8；04 §2 copy.captcha_missing）。
 *
 *   - 「完成上方的验证」→「完成人机验证」（captcha_missing 键+JS hint；键名不变）；
 *   - 「纯外文评论拦截」→「无中文评论拦截」（全库唯一处 SettingsPage UI 标签——本测试以
 *     源码静态断言封口，防回退）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Intercept\Copywork;

final class CopyworkRenamingTest extends TestCase {

	public function test_captcha_missing_copy_renamed(): void {
		$defaults = Copywork::defaults();
		$this->assertSame( '还差一个眨眼——完成人机验证再点提交。', $defaults['messages']['captcha_missing'] );
		$this->assertStringNotContainsString( '上方', $defaults['messages']['captcha_missing'], '嵌入式后验证码不一定在「上方」（反馈 5）' );
		// 键名不变：sanitize 白名单/Tab⑤ 渲染零改动。
		$this->assertContains( 'captcha_missing', Copywork::keys() );
	}

	public function test_js_hint_l10n_renamed_in_bootstrap(): void {
		$bootstrap = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Bootstrap.php' );
		$this->assertStringContainsString( "'hint'      => __( '请先完成人机验证'", $bootstrap, 'Bootstrap L10n hint 改词' );
		$this->assertStringNotContainsString( '请先完成上方的人机验证', $bootstrap );
	}

	public function test_js_fallback_hint_renamed(): void {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/gatekeeper.js' );
		$this->assertStringContainsString( "'请先完成人机验证'", $js, 'gatekeeper.js 无 L10n 回退文案改词' );
		$this->assertStringNotContainsString( '请先完成上方的人机验证', $js );
	}

	public function test_local_rules_ui_label_renamed_single_occurrence(): void {
		$page = (string) file_get_contents( dirname( __DIR__ ) . '/includes/settings/SettingsPage.php' );
		$this->assertStringContainsString( '无中文评论拦截', $page, 'Tab③ UI 标签改名（反馈 8）' );
		$this->assertStringNotContainsString( '纯外文评论拦截', $page, '全库唯一处改名，旧词不得残留' );
	}
}
