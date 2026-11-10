<?php
/**
 * 闸注册表（02 §2.7，v1.2 可扩展性审核 #5）。
 *
 * 每闸注册：id / class / settings_key / stages / verdict_actions / context_id 生成器。
 * 内置四闸：comment / login / register / lostpassword（【v1.4·R3】后两闸常显——02 §2.7 扩容）。
 * 消费方：Bootstrap 装配、Tab② 闸开关区渲染、Tab⑥ 事件流筛选器、签名存根 context_id
 * 解析、事件表 gate 字段取值（开放枚举 = 注册 id，新值零迁移）——全部读注册表。
 *
 * 新闸 = 新 Gate 文件 + 注册一行 + 文案键（如需）≈ 2–3 处。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

final class GateRegistry {

	/**
	 * 注册表：id => 注册元数据。
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $gates = array();

	public function __construct() {
		$this->register_builtins();
	}

	/**
	 * 注册一条闸。必填字段：id/class/settings_key/stages/verdict_actions/context_id。
	 *
	 * @param array<string, mixed> $gate 闸注册元数据。
	 * @return void
	 * @throws \InvalidArgumentException 元数据缺字段/重复 id/字段非法。
	 */
	public function register( array $gate ): void {
		$required = array( 'id', 'class', 'settings_key', 'stages', 'verdict_actions', 'context_id' );
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $gate ) ) {
				throw new \InvalidArgumentException( "闸注册缺少必填字段：{$field}" );
			}
		}

		$id = $gate['id'];
		if ( ! is_string( $id ) || '' === $id ) {
			throw new \InvalidArgumentException( '闸注册字段非法：id 必须为非空字符串' );
		}
		if ( array_key_exists( $id, $this->gates ) ) {
			throw new \InvalidArgumentException( "闸注册重复 id：{$id}" );
		}
		if ( ! is_string( $gate['class'] ) || '' === $gate['class'] ) {
			throw new \InvalidArgumentException( '闸注册字段非法：class 必须为非空类名' );
		}
		if ( ! is_string( $gate['settings_key'] ) || '' === $gate['settings_key'] ) {
			throw new \InvalidArgumentException( '闸注册字段非法：settings_key 必须为非空字符串' );
		}
		if ( ! is_array( $gate['stages'] ) || array() === $gate['stages'] ) {
			throw new \InvalidArgumentException( '闸注册字段非法：stages 必须为非空数组' );
		}
		foreach ( $gate['stages'] as $stage ) {
			if ( ! is_string( $stage ) || '' === $stage ) {
				throw new \InvalidArgumentException( '闸注册字段非法：stages 元素必须为非空字符串' );
			}
		}
		if ( ! is_array( $gate['verdict_actions'] ) ) {
			throw new \InvalidArgumentException( '闸注册字段非法：verdict_actions 必须为数组' );
		}
		if ( ! is_callable( $gate['context_id'] ) ) {
			throw new \InvalidArgumentException( '闸注册字段非法：context_id 必须为可调用生成器' );
		}

		$this->gates[ $id ] = $gate;
	}

	/**
	 * @param string $id 闸 id。
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		return $this->gates[ $id ] ?? null;
	}

	/**
	 * 全部注册闸（保持注册顺序）。
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		return $this->gates;
	}

	/**
	 * 闸是否启用：读 settings['gates'][settings_key]['enabled']。
	 *
	 * @param string              $id       闸 id。
	 * @param array<string, mixed> $settings 设置树（SettingsSchema::defaults() 形态）。
	 * @return bool
	 * @throws \InvalidArgumentException 未知闸 id。
	 */
	public function enabled( string $id, array $settings ): bool {
		$gate = $this->get( $id );
		if ( null === $gate ) {
			throw new \InvalidArgumentException( "未知闸 id：{$id}" );
		}
		return ! empty( $settings['gates'][ $gate['settings_key'] ]['enabled'] );
	}

	/**
	 * 内置注册数据（M0：class 指向占位类，闸验证编排 M1 实装）。
	 */
	private function register_builtins(): void {
		$this->register( array(
			'id'      => 'comment',
			'class'   => CommentGate::class,
			'settings_key' => 'comment',
			// 签名存根身份生成器：context_id = comment_post_ID（02 §3.3）。
			'context_id'   => static function ( array $request ): string {
				return isset( $request['comment_post_ID'] ) && is_scalar( $request['comment_post_ID'] )
					? (string) $request['comment_post_ID']
					: '';
			},
			'stages'  => array( 'ip', 'captcha', 'review' ),
			// 评论闸：review → 入待审（D8，pre_comment_approved=0）。
			'verdict_actions' => array( 'review' => 'comment_approved=0' ),
		) );

		$this->register( array(
			'id'      => 'login',
			'class'   => LoginGate::class,
			'settings_key' => 'login',
			// 登录闸 context_id 恒 'login'（02 §3.3）。
			'context_id'   => static function ( array $request ): string {
				return 'login';
			},
			'stages'  => array( 'ip', 'captcha' ), // 登录闸无 review 阶段（02 §2.7）。
			// 登录闸：验证失败返回 WP_Error 进登录页原生错误框，不 wp_die（02 §3.2）。
			'verdict_actions' => array( 'reject' => 'wp_error' ),
		) );

		/* 【v1.4·R3/02 §2.7——本契约的第一次真实扩容】register/lostpassword 两新闸：
		 * 常显无自适应（render_mode 恒 always）；Tab② 闸开关区/Tab⑥ 闸筛选器/by_gate
		 * 统计键随注册表自动扩展（消费方零改动）。 */
		$this->register( array(
			'id'      => 'register',
			'class'   => RegisterGate::class,
			'settings_key' => 'register',
			'context_id'   => static function ( array $request ): string {
				return 'register';
			},
			'stages'  => array( 'captcha' ),
			// 注册闸：WP_Error 注入（用户不创建——registration_errors 非空即中止，02 §3.2a）。
			'verdict_actions' => array( 'reject' => 'wp_error_registration' ),
		) );

		$this->register( array(
			'id'      => 'lostpassword',
			'class'   => LostPasswordGate::class,
			'settings_key' => 'lostpassword',
			'context_id'   => static function ( array $request ): string {
				return 'lostpassword';
			},
			'stages'  => array( 'captcha' ),
			// 找回密码闸：WP_Error 注入（重置邮件不发——lostpassword_post 突变中止，02 §3.2a）。
			'verdict_actions' => array( 'reject' => 'wp_error_lostpassword' ),
		) );
	}
}
