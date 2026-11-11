<?php
/**
 * 阿里验证码 2.0 前端 JS 契约测试（B3：05 §1.2 V3 三步形态——与 FrontendJsContractTest
 * 同一「源码契约测试」方法论，行为面由真机自测承载）。
 *
 * 契约面（05 §1.2 红线）：
 *   - 适配器注册：window.wkGateDrivers.aliyun = {interaction:'click', tokenFields:[
 *     'wk_captcha_verify_param'], init}（gatekeeper.js frontend 元数据分派——不认驱动 id）；
 *   - 动态引入 JS 红线：createElement('script') + 官方 CDN URL（静态/本地部署致对抗失效）；
 *   - 全局配置先行：window.AliyunCaptchaConfig = {region:'cn', prefix}（须在引入 JS 之前）；
 *   - initAliyunCaptcha 形态：SceneId/mode/element/button/success/fail/getInstance/onError；
 *   - captchaVerifyParam 禁止任何修改——success 回调原文写隐藏域 + 派发 wk-gate-token；
 *   - CaptchaVerifyParam 90 秒时效（F019）——到期作废回退（wk-gate-expire）；
 *   - Bootstrap handle 注册：wink-gatekeeper-driver-aliyun（deps wink-gatekeeper-front）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;

final class AliyunFrontendJsContractTest extends TestCase {

	private static function adapter(): string {
		$src = file_get_contents( __DIR__ . '/../assets/js/drivers/aliyun.js' );
		self::assertIsString( $src, 'assets/js/drivers/aliyun.js 必须可读' );
		return (string) $src;
	}

	private static function bootstrap(): string {
		$src = file_get_contents( __DIR__ . '/../includes/Bootstrap.php' );
		self::assertIsString( $src, 'Bootstrap.php 必须可读' );
		return (string) $src;
	}

	private static function gatekeeper(): string {
		$src = file_get_contents( __DIR__ . '/../assets/js/gatekeeper.js' );
		self::assertIsString( $src, 'gatekeeper.js 必须可读' );
		return (string) $src;
	}

	public function test_adapter_registers_frontend_metadata(): void {
		$src = self::adapter();
		$this->assertStringContainsString( "registry.aliyun", $src );
		$this->assertStringContainsString( "interaction: 'click'", $src );
		$this->assertStringContainsString( "'wk_captcha_verify_param'", $src, 'tokenFields 单票据声明' );
		$this->assertStringContainsString( 'wk-gate-driver-registered', $src, '注册事件收口竞态（调度器契约）' );
	}

	public function test_adapter_dynamic_script_redline(): void {
		$src = self::adapter();
		$this->assertStringContainsString(
			'https://o.alicdn.com/captcha-frontend/aliyunCaptcha/AliyunCaptcha.js',
			$src,
			'官方 CDN URL（05 §1.2 红线：禁止本地部署/静态拉取）'
		);
		$this->assertStringContainsString( "createElement( 'script' )", $src, '动态创建 script 节点' );
	}

	public function test_adapter_reveals_hidden_embed_mount(): void {
		// 【v1.5·FIX】真机 v7 测试页实锤：SubmitRow 的 .wk-gate-embed 初始 hidden，B3 漏抄
		// tencent.js:287「挂载点展开」课程——SDK 往 hidden 容器照常渲染但用户不可见
		// （视角=「验证码加载中」永恒卡住）。initCaptcha 必须 mount.hidden = false。
		$src = self::adapter();
		$this->assertStringContainsString( "mount.hidden = false", $src, 'embed 挂载点展开（hidden 容器内渲染不可见——加载中永恒卡住回归门）' );
	}

	public function test_adapter_global_config_before_script(): void {
		$src = self::adapter();
		$this->assertStringContainsString( 'AliyunCaptchaConfig', $src, '三步①全局配置' );
		$this->assertMatchesRegularExpression( "/region:\s*'cn'/", $src, 'region 恒 cn（sgp 变体不启用）' );
		$this->assertStringContainsString( 'prefix', $src, '身份标入全局配置' );
	}

	public function test_adapter_init_aliyun_captcha_params(): void {
		$src = self::adapter();
		foreach ( array( 'initAliyunCaptcha', 'SceneId', 'mode', 'element:', 'button:', 'getInstance', 'onError', 'slideStyle' ) as $needle ) {
			$this->assertStringContainsString( $needle, $src, "initAliyunCaptcha 形态须含 {$needle}" );
		}
		// 滑块宽度最小 320px（小于按 320 处理——05 §1.2；F4-5 容器条款 03 §2.2）。
		$this->assertMatchesRegularExpression( '/slideStyle[^}]*width:\s*320/', $src );
	}

	public function test_adapter_success_writes_token_unmodified(): void {
		$src = self::adapter();
		$this->assertStringContainsString( 'captchaVerifyParam', $src, 'success 回调形参名' );
		$this->assertStringContainsString( 'wk-gate-token', $src, 'token 到域派发（调度器解锁提交）' );
		$this->assertStringContainsString( 'wk-gate-expire', $src, '90s 时效作废派发' );
		// F019：行为验证→业务验签间隔 ≤90 秒。
		$this->assertMatchesRegularExpression( '/PARAM_TTL_MS\s*=\s*90\s*\*\s*1000/', $src );
	}

	public function test_gatekeeper_dispatcher_stays_driver_id_agnostic(): void {
		// 调度器不认驱动 id（03 §2.1 契约）：aliyun.js 落地后 gatekeeper.js 零改动——
		// 本断言钉死调度器源码不含 'aliyun' 字面量。
		$this->assertStringNotContainsString( 'aliyun', self::gatekeeper(), '调度器契约：frontend 元数据分派，不认驱动 id' );
	}

	public function test_bootstrap_registers_adapter_handle(): void {
		$src = self::bootstrap();
		$this->assertStringContainsString( 'wink-gatekeeper-driver-aliyun', $src, '适配器 handle 注册' );
		$this->assertStringContainsString( 'assets/js/drivers/aliyun.js', $src, '适配器脚本路径' );
	}

	public function test_driver_php_enqueue_matches_handle(): void {
		$src = file_get_contents( __DIR__ . '/../includes/drivers/captcha/AliyunCaptchaDriver.php' );
		self::assertIsString( $src );
		$this->assertStringContainsString( "wp_enqueue_script( 'wink-gatekeeper-driver-aliyun' )", $src );
	}
}
