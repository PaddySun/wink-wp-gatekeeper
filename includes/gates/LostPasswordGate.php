<?php
/**
 * 找回密码闸（02 §3.2a——【v1.4·R3/F41/D17③】常显无自适应）。
 *
 * 渲染挂点：lostpassword_form @ 10（wp-login.php:899——lostpassword/retrievepassword 两
 * action 共用表单）。验证挂点：lostpassword_post action @ 10, 2 参（wp-includes/user.php:3344
 * ——retrieve_password() 内、用户查找后、**发信前**；经 lostpassword_errors filter :3361 →
 * has_errors 即中止 :3363-3365，重置邮件不发）——**action 突变型**：$errors 对象引用直接
 * add、无返回（与注册域 filter 返回型相反；hCaptcha 先例 LostPasswordBase.php:81-85,99）。
 *
 * 防枚举（02 §3.2a）：lostpassword_post 在 core「用户不存在→invalidcombo」判定（:3367-3368）
 * **之前**触发且 $user_data=false 照常传入——本闸挂此点同时覆盖用户枚举探测（不存在账号
 * 也必须过码）；错误文案恒同一条，不区分用户有无（防以错误响应探测用户名存在性）；
 * $user_data（WP_User|false）只作事件 meta（user_present）。
 *
 * 守卫对齐 hCaptcha（WP/LostPassword.php:137-147）：$_POST['user_login'] 在场 ∧ REQUEST
 * action ∈ {lostpassword, retrievepassword}。resetpass/rp（设新密码表单）不设闸——由
 * reset key+cookie 保护，无枚举/喷洒面。
 *
 * 事件：gate=lostpassword、stage=stub|captcha、verdict=reject 落库；pass 不落库；不 spam
 * 留档。豁免 filter：wk_lostpassword_protection_enabled。XML-RPC N/A（无对应端点）。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

use WinkGatekeeper\Drivers\CaptchaDriverInterface;
use WinkGatekeeper\Ip\IpResolver;

final class LostPasswordGate extends AccountFormGate {

	/**
	 * 闸 id（GateRegistry 注册 id）。
	 *
	 * @var string
	 */
	protected $id = 'lostpassword';

	/** 覆盖的两 action 别名（wp-login.php:826-827）。 */
	public const ACTIONS = array( 'lostpassword', 'retrievepassword' );

	public function render_hooks(): array {
		return array( 'lostpassword_form' => 10 ); // wp-login.php:899。
	}

	public function verify_hooks(): array {
		// action 突变型（2 参：$errors, $user_data——user.php:3344）。
		return array( 'lostpassword_post' => 10 );
	}

	/**
	 * @param array<string, mixed>        $settings 设置树。
	 * @param CaptchaDriverInterface|null $captcha  验证码驱动。
	 * @param callable|null               $recorder 事件记录器（null = WP 边界默认）。
	 * @param IpResolver|null             $resolver IP 解析器。
	 * @param callable|null               $stub_hash 存根哈希。
	 * @param callable|null               $now      时钟。
	 * @param VerifyCache|null            $verify_cache 验证短缓存（Bootstrap 单例复用）。
	 * @param callable|null               $require_gate 豁免过滤器（【v1.5·C1/B5】null = WP 边界默认）。
	 * @param callable|null               $degraded_limiter pass_limited 降级限流器（null = fail-closed）。
	 */
	public function __construct(
		array $settings,
		?CaptchaDriverInterface $captcha = null,
		?callable $recorder = null,
		?IpResolver $resolver = null,
		?callable $stub_hash = null,
		?callable $now = null,
		?VerifyCache $verify_cache = null,
		?callable $require_gate = null,
		?callable $degraded_limiter = null
	) {
		parent::__construct( $settings, $captcha, $recorder, $resolver, $stub_hash, $now, $verify_cache, $require_gate, $degraded_limiter );
	}

	/**
	 * 闸是否启用（gates.lostpassword.enabled——常显无 off 档语义：关=整体关）。
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return $this->gate_enabled();
	}

	protected function settings_key(): string {
		return 'lostpassword';
	}

	protected function context_id(): string {
		return 'lostpassword';
	}

	/* -------------------------------------------------------------------
	 * 纯编排（单测覆盖）
	 * ------------------------------------------------------------------ */

	/**
	 * 渲染编排（lostpassword_form 的纯逻辑面；常显）。
	 *
	 * @param bool $protection_enabled 豁免 filter 结果。
	 * @return string 空 = 零 DOM。
	 */
	public function render_orchestrate( bool $protection_enabled ): string {
		return $this->render_embed( $protection_enabled );
	}

	/**
	 * 验证编排（lostpassword_post 的纯逻辑面）。
	 *
	 * @param array<string, mixed>  $request            提交请求面（$_POST）。
	 * @param string|null           $action             当前 REQUEST action（守卫用；null 缺省按 lostpassword）。
	 * @param bool                  $protection_enabled wk_lostpassword_protection_enabled filter 结果。
	 * @param bool                  $user_present       $user_data 是否 WP_User（只入事件 meta——防枚举：文案无差）。
	 * @param array<string, mixed>|null $server         $_SERVER（null = WP 边界自取）。
	 * @return array{decision: string, error_code?: string, message?: string, evidence?: array<string, string>}
	 */
	public function verify_orchestrate( array $request, ?string $action, bool $protection_enabled, bool $user_present = false, ?array $server = null ): array {
		// 守卫（hCaptcha 对齐）：user_login 在场 ∧ action ∈ 两别名——否则不是找回提交，跳过。
		if ( ! isset( $request['user_login'] ) || ! is_scalar( $request['user_login'] ) ) {
			return array( 'decision' => 'allow' );
		}
		$action = $action ?? 'lostpassword';
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return array( 'decision' => 'allow' );
		}

		return $this->verify_always_orchestrate( $request, $protection_enabled, array( 'user_present' => $user_present ), $server );
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
		return '' !== $this->render_orchestrate( (bool) apply_filters( 'wk_lostpassword_protection_enabled', true ) );
	}

	/**
	 * lostpassword_form 动作 @ 10：常显注入嵌入式组件（03 §2.3）。
	 *
	 * @return void
	 */
	public function render_lostpassword_form(): void {
		echo $this->render_orchestrate( (bool) apply_filters( 'wk_lostpassword_protection_enabled', true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 驱动 render 已逐属性转义。
	}

	/**
	 * lostpassword_post action @ 10, 2 参（action 突变型——$errors 对象引用直接 add、无返回；
	 * 错误经 lostpassword_errors filter → has_errors 即中止，重置邮件不发）。
	 *
	 * @param \WP_Error     $errors    核心错误对象（突变注入）。
	 * @param \WP_User|bool $user_data 用户（false=不存在——照常拦，防枚举）。
	 * @return void
	 */
	public function action_lostpassword_post( $errors, $user_data = false ): void {
		// 守卫（hCaptcha 对齐）：user_login 在场 ∧ REQUEST action ∈ 两别名。
		if ( ! isset( $_POST['user_login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- wp-login 原生表单无 nonce（存根+票据承担）。
			return;
		}
		$action = isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ? (string) wp_unslash( $_REQUEST['action'] ) : 'lostpassword'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读取表单上下文。
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return;
		}

		$request = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 同上。
		$result  = $this->verify_orchestrate(
			$request,
			$action,
			(bool) apply_filters( 'wk_lostpassword_protection_enabled', true ),
			$user_data instanceof \WP_User
		);

		$this->unset_post_fields(); // token 单次消费（A6）。

		if ( 'reject' === $result['decision'] ) {
			$errors->add( (string) $result['error_code'], (string) $result['message'] ); // 突变注入（无 return）；文案恒同一条不区分用户有无。
		}
	}
}
