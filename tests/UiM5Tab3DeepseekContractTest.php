<?php
/**
 * M5 UI-B 批 · Tab③ DeepSeek 配置区骨架契约测试（2026-11-14，U4）。
 *
 * 骨架契约面（源码契约测试；行为面由 B 机真机承载）：
 *   ① DeepSeek 脱离 future_review_drivers 占位段（登记 registry=已实装驱动）；
 *   ② 配置区 DOM 锚（wk-review-deepseek-card/two-stage-row/upgrade-model-row/quota-line/
 *      test-btn）+upgrade_model 条件显隐（PHP 初始 hidden+JS change 绑定）；
 *   ③ 测试凭据=GET /models 探活（D5：零 token 成本）——AJAX 端点+RemoteClient+三态；
 *   ④ Bootstrap 云审链装配 deepseek 分支+registry 注册；
 *   ⑤ LlmUsage 统计面接线（option 名/record）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;

final class UiM5Tab3DeepseekContractTest extends TestCase {

	private static function source( string $rel ): string {
		$src = file_get_contents( __DIR__ . '/../' . $rel );
		self::assertIsString( $src, "{$rel} 必须可读" );
		return (string) $src;
	}

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
	 * ① 占位段退役
	 * =================================================================== */

	public function test_deepseek_leaves_future_review_drivers(): void {
		$body = self::functionBody( self::source( 'includes/settings/SettingsPage.php' ), 'future_review_drivers' );
		self::assertStringNotContainsString( "'deepseek'", $body, 'DeepSeek 已实装——脱离 future 占位段（UI 块 2-A）' );
		self::assertStringContainsString( 'openai_custom', $body, 'openai_custom 仍为占位（F32 未实装）' );
	}

	public function test_deepseek_config_card_renders(): void {
		$sp = self::source( 'includes/settings/SettingsPage.php' );
		self::assertStringContainsString( 'wk-review-deepseek-card', $sp, 'DeepSeek 配置区壳' );
		self::assertStringContainsString( 'data-wk-vendor="deepseek"', $sp, 'vendor 切换形态（bindVendorSwitcher 消费）' );
	}

	/* ===================================================================
	 * ② 配置区锚+条件显隐
	 * =================================================================== */

	public function test_card_anchors_present(): void {
		$src = self::source( 'includes/settings/SettingsPage.php' );
		foreach ( array( 'wk-review-deepseek-card', 'wk-deepseek-two-stage-row', 'wk-deepseek-upgrade-model-row', 'wk-deepseek-quota-line', 'wk-deepseek-test-btn' ) as $anchor ) {
			self::assertStringContainsString( $anchor, $src, "锚 {$anchor}" );
		}
	}

	public function test_upgrade_model_row_conditional_hidden(): void {
		$src   = self::source( 'includes/settings/SettingsPage.php' );
		$block = self::blockBetween( $src, 'wk-deepseek-upgrade-model-row', 'wk-deepseek-quota-line' );
		self::assertStringContainsString( 'hidden', $block, 'PHP 初始 hidden（two_stage 开才显示）' );
		self::assertStringContainsString( 'two_stage', $block, 'hidden 判定跟随 two_stage' );
	}

	public function test_js_upgrade_row_binding(): void {
		$js = self::source( 'assets/js/admin-brand.js' );
		self::assertStringContainsString( 'wk-deepseek-two-stage', $js, 'JS 绑定 two_stage 开关' );
		self::assertStringContainsString( 'wk-deepseek-upgrade-model-row', $js, 'JS 同步升级模型行显隐' );
	}

	public function test_two_stage_note_once(): void {
		// 说明段级一次：two_stage 说明只写开关行；升级模型行不重复解释。
		$src = self::source( 'includes/settings/SettingsPage.php' );
		self::assertSame( 1, substr_count( $src, '每条评论最多判两次' ), 'two_stage 说明段级一次' );
	}

	/* ===================================================================
	 * ③ 测试凭据=models 探活（D5）
	 * =================================================================== */

	public function test_credentials_probe_models_endpoint(): void {
		$sp = self::source( 'includes/settings/SettingsPage.php' );
		self::assertStringContainsString( "AJAX_TEST_CREDENTIALS_DEEPSEEK = 'wink_gatekeeper_test_credentials_deepseek'", $sp, 'AJAX action 常量' );
		$handler = self::functionBody( $sp, 'ajax_test_credentials_deepseek' );
		self::assertStringContainsString( 'check_ajax_referer', $handler, 'nonce 双闸' );
		self::assertStringContainsString( 'manage_options', $handler, 'manage_options 双闸' );
		self::assertStringContainsString( 'RemoteClient', $handler, 'HTTP 走传输脊' );
		self::assertStringContainsString( 'probe_models', $handler, '探活走驱动 probe_models（传输脊）' );
		self::assertStringContainsString( '/models', self::source( 'includes/drivers/review/DeepSeekReviewDriver.php' ), 'GET {base_url}/models 探活（D5：零 token 成本）' );
		self::assertStringContainsString( 'CredentialProbe', $handler, '三态判定走 CredentialProbe' );
	}

	public function test_bootstrap_registers_deepseek_probe_ajax(): void {
		$src = self::source( 'includes/Bootstrap.php' );
		self::assertStringContainsString( 'AJAX_TEST_CREDENTIALS_DEEPSEEK', $src, 'Bootstrap 注册 DeepSeek 探活端点' );
	}

	/* ===================================================================
	 * ④ Bootstrap 装配（云审链 deepseek 分支+registry）
	 * =================================================================== */

	public function test_bootstrap_assembles_deepseek_chain_branch(): void {
		$body = self::functionBody( self::source( 'includes/Bootstrap.php' ), 'assemble_gates' );
		self::assertStringContainsString( "'deepseek' === \$chain_id", $body, '云审链 deepseek 分支' );
		self::assertStringContainsString( 'DeepSeekReviewDriver', $body, '驱动构造' );
		self::assertStringContainsString( 'DeepSeekReviewDriver::registry_entry', $body, 'registry 登记（脱离占位段的对偶面）' );
	}

	/* ===================================================================
	 * ⑤ LlmUsage 统计面
	 * =================================================================== */

	public function test_llm_usage_option_and_bootstrap_wiring(): void {
		$src = self::source( 'includes/stats/LlmUsage.php' );
		self::assertStringContainsString( "wink_gatekeeper_llm_usage", $src, 'K2 option 名（UI 设计 §3.4）' );
		self::assertStringContainsString( "url_audit_batch", $src, 'caller 枚举预留双车道（U5 消费）' );
		self::assertStringContainsString( "url_audit_incr", $src );
	}

	public function test_deepseek_driver_records_llm_usage(): void {
		$src = self::source( 'includes/drivers/review/OpenAiCompatibleReviewDriver.php' );
		self::assertStringContainsString( 'LlmUsage::record', $src, '解析成功回写 K2' );
	}


	private static function blockBetween( string $src, string $startMarker, string $endMarker ): string {
		$start = strpos( $src, $startMarker );
		self::assertNotFalse( $start, "HTML 须含 {$startMarker}" );
		$end = strpos( $src, $endMarker, $start + strlen( $startMarker ) );
		self::assertNotFalse( $end, "须在 {$startMarker} 之后含 {$endMarker}" );
		return substr( $src, $start, $end - $start );
	}
}
