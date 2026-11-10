<?php
/**
 * 闸公共骨架（02 §1.1：AbstractGate ← 签名存根、事件落库、拦截页调用、降级分派）。
 *
 * M1 状态：CommentGate 以注入协作者（驱动/记录器/解析器/哈希/时钟）自持编排
 * （orchestrate() 纯逻辑 + hook_* WP 边界）；下列 M0 占位助手保留为 LoginGate（M2）
 * 的装配锚点——签名存根/事件落库/拦截调用的实现分别位于
 * Intercept\Stub / Stats\EventsTable + Stats\StatsCache / Intercept\DiePage。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

use WinkGatekeeper\Drivers\CaptchaDriverInterface;

abstract class AbstractGate implements GateInterface {

	/**
	 * 闸 id（GateRegistry 注册 id）。
	 *
	 * @var string
	 */
	protected $id = '';

	public function id(): string {
		return $this->id;
	}

	public function order(): array {
		// M1：按 GateRegistry 注册的 stages 顺序编排（ip → captcha → review）。
		return array();
	}

	/**
	 * 表单凭据域 → 驱动 context 规范键映射（【v1.5·B3】M2-FIX2 D2 教训制度化第三域——
	 * CaptchaDriverInterface docblock「闸把表单域映射为规范键」的单点实现）：
	 *   wk_captcha_ticket → ticket（天御）
	 *   wk_captcha_randstr → randstr（天御）
	 *   wk_captcha_verify_param → captcha_verify_param（阿里 2.0 单票据，05 §1.2 registry 行）
	 * 开放键集：未知字段按表单原名透传（未来驱动自定义凭据域不受限）。
	 *
	 * @param string $field 表单凭据域名。
	 * @return string
	 */
	public static function canonical_token_key( string $field ): string {
		$map = array(
			'wk_captcha_ticket'       => 'ticket',
			'wk_captcha_randstr'      => 'randstr',
			'wk_captcha_verify_param' => 'captcha_verify_param',
		);
		return $map[ $field ] ?? $field;
	}

	/**
	 * 从请求面提取全部凭据域 → context（按 credentialFields() 声明序 + canonical_token_key
	 * 映射）与齐全性。三闸共用（CommentGate captcha 段 / AccountFormGate::build_verify_context），
	 * 杜绝新驱动接入时的逐闸漏抄（C3 登录闸 D2 教训）。
	 *
	 * @param CaptchaDriverInterface $captcha  当前驱动（credentialFields() 声明来源）。
	 * @param array<string, mixed>   $request  提交请求面（$_POST / REST body）。
	 * @return array{context: array<string, mixed>, has_ticket: bool}
	 */
	public static function extract_token_context( CaptchaDriverInterface $captcha, array $request ): array {
		$context    = array();
		$has_ticket = true;

		foreach ( array_values( $captcha->credentialFields() ) as $field ) {
			$value = isset( $request[ $field ] ) && is_scalar( $request[ $field ] ) ? trim( (string) $request[ $field ] ) : '';
			if ( '' === $value ) {
				$has_ticket = false;
			}
			$key = self::canonical_token_key( $field );
			if ( ! isset( $context[ $key ] ) ) {
				$context[ $key ] = $value;
			}
		}
		return array( 'context' => $context, 'has_ticket' => $has_ticket );
	}

	/**
	 * 签名存根签发（M1 实装，02 §3.3）。
	 *
	 * 存根 = base64( json{gate, context_id, captcha_shown, ts} ) + '.' + wp_hash(前者, 'nonce')。
	 *
	 * @param string $context_id 上下文身份（评论闸 = comment_post_ID；登录闸 = 'login'）。
	 * @return string
	 */
	protected function issue_stub( string $context_id ): string {
		unset( $context_id ); // M1 实装。
		return '';
	}

	/**
	 * 事件落库（M1 实装，04 §3 写入矩阵：非 pass 结果直插，pass 默认不落库）。
	 *
	 * @param string               $stage   阶段（ip|stub|captcha|review，开放枚举）。
	 * @param \WinkGatekeeper\Verdict $verdict 判定。
	 * @param array<string, mixed> $meta    事件 meta。
	 * @return void
	 */
	protected function record_event( string $stage, \WinkGatekeeper\Verdict $verdict, array $meta = array() ): void {
		unset( $stage, $verdict, $meta ); // M1 实装。
	}

	/**
	 * 拦截落地（M1 实装，D4：wp_die 品牌整页 + back_link；登录闸为 WP_Error）。
	 *
	 * @param string $copy_key 文案键（Copywork::keys() 之一）。
	 * @param array  $evidence 证据行（event_no/rule/time 等，等宽呈证）。
	 * @return void
	 */
	protected function intercept( string $copy_key, array $evidence = array() ): void {
		unset( $copy_key, $evidence ); // M1 实装。
	}
}
