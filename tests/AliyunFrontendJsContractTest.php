<?php
/**
 * 阿里验证码 2.0 前端 JS 契约测试（B3：05 §1.2 V3 三步形态——与 FrontendJsContractTest
 * 同一「源码契约测试」方法论，行为面由真机自测承载）。
 *
 * 契约面（05 §1.2 红线）：
 *   - 适配器注册：window.wkGateDrivers.aliyun = {interaction:'click', tokenFields:[
 *     'wk_captcha_verify_param'], init}（gatekeeper.js frontend 元数据分派——不认驱动 id）；
 *   - 动态引入 JS 红线：createElement('script') + 官方 CDN URL（静态/本地部署致对抗失效）；
	 *   - 全局配置先行：window.AliyunCaptchaConfig = {region, prefix}（须在引入 JS 之前
	 *     ——【W3·批C·N2】region 从容器 data-wk-region 读实键值，恒 cn 硬编码退役）；
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
		// 【W3·批C·N2 翻转】region 实键值：容器 data-wk-region 读取（闭集归一非 sgp 一律
		// cn）——恒 cn 硬编码退役（sgp 档静态推断 R7 未实机验证，与 ENDPOINT_MAP 双端联动）。
		$this->assertStringContainsString( 'data-wk-region', $src, 'region 从容器 data 属性读实键值' );
		$this->assertDoesNotMatchRegularExpression( "/region:\s*'cn'/", $src, '恒 cn 硬编码退役（data-wk-region 实键）' );
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
		$this->assertStringContainsString( 'assets/js/drivers/aliyun$suffix.js', $src, '适配器脚本路径（【批2·P1-8】suffix 变量化——非 SCRIPT_DEBUG 入队 .min）' );
	}

	public function test_driver_php_enqueue_matches_handle(): void {
		$src = file_get_contents( __DIR__ . '/../includes/drivers/captcha/AliyunCaptchaDriver.php' );
		self::assertIsString( $src );
		$this->assertStringContainsString( "wp_enqueue_script( 'wink-gatekeeper-driver-aliyun' )", $src );
	}

	/* ---------- 【W2·无按钮模式】requestVerify 契约 + embed boot 分支（0.8.4） ---------- */

	public function test_adapter_request_verify_contract(): void {
		$src = self::adapter();
		$this->assertStringContainsString(
			'requestVerify: function',
			$src,
			'【W2·W-3】适配器契约单方法（调度器 submit-gate 闸级拦截 + 适配器拉起——注册表元数据分派，不认驱动 id）'
		);
		$this->assertStringContainsString(
			'wkCaptchaRequestVerify',
			$src,
			'容器级验证入口存根（initContainer 装配时暴露——per-container 闭包，wkMsgClearTimer 同款 DOM 属性形态）'
		);
		$this->assertStringContainsString(
			'throw new Error',
			$src,
			'容器未装配抛错（调度器 startVerification catch → 经典形态回退——降级矩阵）'
		);
	}

	public function test_request_verify_reuses_h41_autoshow_not_a_second_instance_show(): void {
		$src = self::adapter();
		$this->assertSame(
			1,
			substr_count( $src, 'instance.show();' ),
			'popup 一步开层复用 H4-1 唯一面（getInstance 守卫内）——requestVerify 委托 show()，不另开 instance.show 调用点'
		);
	}

	public function test_adapter_embed_boot_renders_for_submit_gate(): void {
		// 【W2·Q1=A 页面加载即渲染】embed+data-submit-gate 容器 boot 期即拉起（触发条常驻
		// 表单内，token 在场=提交直通；mount.hidden=false 回归门照用）。
		$src = self::adapter();
		$this->assertStringContainsString( 'data-submit-gate', $src, '无按钮容器锚读取（boot 分支条件）' );
		$this->assertStringContainsString( "&& 'embed' === mode", $src, 'boot 分支 embed 限定（popup 不 boot 预载——提交拦截时一步开层）' );
		// 条件序：boot 分支在 initContainer 体内、requestVerify 调用锚随 embed 条件在场。
		$init = self::functionBody( $src, 'initContainer' );
		$this->assertStringContainsString( 'requestVerify()', $init, 'boot 期调用 requestVerify（embed+submit-gate）' );
	}

	/**
	 * 提取具名函数体（花括号配平）——契约断言按函数作用域收窄（FrontendJsContractTest 同法）。
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
		$this->fail( "函数 {$name} 花括号不配平" );
		return '';
	}
}
