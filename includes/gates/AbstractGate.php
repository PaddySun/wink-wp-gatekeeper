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
