<?php
/**
 * 注册闸（02 §3.2a——【v1.4·R3/F41/D17③】常显无自适应；反馈 4「始终出现」）。
 *
 * 渲染挂点：register_form @ 10（wp-login.php:1173——registerform 内、Email 字段后、
 * 提交钮前）。验证挂点：registration_errors filter @ 10, 3 参（wp-includes/user.php:3655
 * ——register_new_user() 内、**用户创建前**；$errors 非空即中止注册 :3657-3659——
 * 用户不创建、邮件不发）——**filter 返回型**：$errors->add 后 return $errors（与找回
 * 密码域的 action 突变型相反，两闸各自对齐 core；hCaptcha 先例 WP/Register.php:85-119）。
 *
 * 语义：常显（render_mode 恒 always）无计数器、无 iprep 参与判定；stub 三态
 * （false=bad-signature / true=captcha_missing（恒渲染闸的 true 态=缓存页/预收割，仍拦）/
 * null=取票验票）；失败一律 WP_Error 注入进 wp-login 原生错误框，**不 wp_die**。
 *
 * 边界（02 §3.2a）：users_can_register=off → core 渲染前已 redirect（wp-login.php:1104-1106），
 * 钩子自然不触发（空转无害）；multisite → redirect wp-signup.php（:1092,1100——本闸不
 * 覆盖，适配列 Backlog）；第三方注册表单（WooCommerce 等不走 registration_errors）列
 * Backlog。
 *
 * 事件：gate=register、stage=stub|captcha、verdict=reject 落库（04 §3 写入矩阵新闸行）；
 * pass 不落库；不 spam 留档（登录族原则）。豁免 filter：wk_register_protection_enabled。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

use WinkGatekeeper\Drivers\CaptchaDriverInterface;
use WinkGatekeeper\Ip\IpResolver;

final class RegisterGate extends AccountFormGate {

	/**
	 * 闸 id（GateRegistry 注册 id；事件表 gate 列/统计键/存根 gate 字段单一来源）。
	 *
	 * @var string
	 */
	protected $id = 'register';

	public function render_hooks(): array {
		return array( 'register_form' => 10 ); // wp-login.php:1173。
	}

	public function verify_hooks(): array {
		// filter 返回型（3 参：$errors, $sanitized_user_login, $user_email——user.php:3655）。
		return array( 'registration_errors' => 10 );
	}

	/**
	 * @param array<string, mixed>        $settings 设置树。
	 * @param CaptchaDriverInterface|null $captcha  验证码驱动（与评论/登录闸共享同一实例）。
	 * @param callable|null               $recorder 事件记录器（null = WP 边界默认）。
	 * @param IpResolver|null             $resolver IP 解析器。
	 * @param callable|null               $stub_hash 存根哈希。
	 * @param callable|null               $now      时钟。
	 * @param VerifyCache|null            $verify_cache 验证短缓存（Bootstrap 单例复用）。
	 */
	public function __construct(
		array $settings,
		?CaptchaDriverInterface $captcha = null,
		?callable $recorder = null,
		?IpResolver $resolver = null,
		?callable $stub_hash = null,
		?callable $now = null,
		?VerifyCache $verify_cache = null
	) {
		parent::__construct( $settings, $captcha, $recorder, $resolver, $stub_hash, $now, $verify_cache );
	}

	/**
	 * 闸是否启用（gates.register.enabled——常显无 off 档语义：关=整体关）。
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return $this->gate_enabled();
	}

	protected function settings_key(): string {
		return 'register';
	}

	protected function context_id(): string {
		return 'register';
	}

	/* -------------------------------------------------------------------
	 * 纯编排（单测覆盖）
	 * ------------------------------------------------------------------ */

	/**
	 * 渲染编排（register_form 的纯逻辑面；常显——无阈值判定）。
	 *
	 * @param bool $protection_enabled 豁免 filter 结果。
	 * @return string 空 = 零 DOM。
	 */
	public function render_orchestrate( bool $protection_enabled ): string {
		return $this->render_embed( $protection_enabled );
	}

	/**
	 * 验证编排（registration_errors 的纯逻辑面）。
	 *
	 * @param array<string, mixed> $request            提交请求面（$_POST）。
	 * @param bool                 $protection_enabled wk_register_protection_enabled filter 结果。
	 * @param array<string, mixed>|null $server        $_SERVER（null = WP 边界自取）。
	 * @return array{decision: string, error_code?: string, message?: string, evidence?: array<string, string>}
	 */
	public function verify_orchestrate( array $request, bool $protection_enabled, ?array $server = null ): array {
		return $this->verify_always_orchestrate( $request, $protection_enabled, array(), $server );
	}

	/* -------------------------------------------------------------------
	 * WP 边界钩子
	 * ------------------------------------------------------------------ */

	/**
	 * 渲染期决策（Bootstrap login_enqueue_scripts 消费；常显=启用 ∧ 驱动就绪即真）。
	 *
	 * 【B3 真机缺陷修复 2026-11-10】原缺此法——enqueue_login_assets 多态调用即 Fatal。
	 *
	 * @return bool
	 */
	public function should_render(): bool {
		return '' !== $this->render_orchestrate( (bool) apply_filters( 'wk_register_protection_enabled', true ) );
	}

	/**
	 * register_form 动作 @ 10：常显注入嵌入式组件（03 §2.3）。
	 *
	 * @return void
	 */
	public function render_register_form(): void {
		echo $this->render_orchestrate( (bool) apply_filters( 'wk_register_protection_enabled', true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 驱动 render 已逐属性转义；包裹层为静态标记。
	}

	/**
	 * registration_errors filter @ 10, 3 参（filter 返回型——$errors 非空即中止注册，
	 * 用户不创建、邮件不发；user.php:3655/3657-3659）。
	 *
	 * @param \WP_Error $errors               核心错误对象（追加验证码错误）。
	 * @param string    $sanitized_user_login 净化后用户名（未消费——事件侧不需要）。
	 * @param string    $user_email           邮箱（未消费）。
	 * @return \WP_Error 恒返回 $errors（filter 契约）。
	 */
	public function filter_registration_errors( $errors, string $sanitized_user_login = '', string $user_email = '' ) {
		unset( $sanitized_user_login, $user_email );

		$request = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- wp-login 原生注册表单无 nonce（存根+票据承担）。
		$result  = $this->verify_orchestrate( $request, (bool) apply_filters( 'wk_register_protection_enabled', true ) );

		$this->unset_post_fields(); // token 单次消费（A6）。

		if ( 'reject' === $result['decision'] ) {
			$errors->add( (string) $result['error_code'], (string) $result['message'] ); // WP_Error 注入（不 wp_die）。
		}
		return $errors;
	}
}
