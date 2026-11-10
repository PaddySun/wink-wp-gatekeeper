<?php
/**
 * 账户族表单闸公共基类（02 §3.2a——【v1.4·R3/F41/D17③】）。
 *
 * D2 教训制度化（M2-FIX2 修复面升格为基类单点，防 M4 新驱动再踩）：credentialFields()
 * → 驱动 verify() 规范键 {ticket, randstr, …} 映射；存根三态消费骨架；VerifyCache 查询；
 * token 单次消费 unset；事件记录与拦截出口（WP_Error 注入形态——登录域失败不 wp_die
 * 原则延伸至账户族表单，02 §3.2）。
 *
 * 继承者：LoginGate（保留计数器/iprep/XML-RPC 特有面）、RegisterGate（filter 返回型）、
 * LostPasswordGate（action 突变型）——两新闸常显无自适应（02 §2.7 render_mode 恒 always）。
 *
 * 分层：verify_orchestrate/render_orchestrate 为纯编排（协作者全注入，单测覆盖）；
 * hook_/render_/filter_/action_ 系列为 WP 边界。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

use WinkGatekeeper\Drivers\CaptchaDriverInterface;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Intercept\Stub;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Stats\EventsTable;
use WinkGatekeeper\Stats\StatsCache;
use WinkGatekeeper\Verdict;

abstract class AccountFormGate extends AbstractGate {

	/**
	 * 验证失败错误码族（WP_Error 注入：wk_captcha_failed / wk_captcha_missing /
	 * wk_bad_signature——进 wp-login 原生错误框，02 §3.2a）。
	 */
	public const ERROR_CODE_VERIFY  = 'wk_captcha_failed';
	public const ERROR_CODE_MISSING = 'wk_captcha_missing';
	public const ERROR_CODE_STUB    = 'wk_bad_signature';

	/**
	 * @var array<string, mixed> 设置树。
	 */
	protected $settings;

	/**
	 * @var CaptchaDriverInterface|null 验证码驱动（null/未配置 = 闸 inert：不渲染不拦截）。
	 */
	protected $captcha;

	/**
	 * @var callable 事件记录器 fn(stage, Verdict, meta): ?string(event_no)。
	 */
	protected $recorder;

	/**
	 * @var IpResolver 真实 IP 解析（全站唯一出口）。
	 */
	protected $resolver;

	/**
	 * @var callable 存根哈希回调（生产 = wp_hash 封装）。
	 */
	protected $stub_hash;

	/**
	 * @var callable 时钟回调。
	 */
	protected $now;

	/**
	 * @var VerifyCache|null 验证短缓存（核验成功后窗口内免重复验票；null = 未装配）。
	 */
	protected $verify_cache;

	/**
	 * @param array<string, mixed>        $settings 设置树（SettingsSchema::defaults() 形态）。
	 * @param CaptchaDriverInterface|null $captcha  验证码驱动（与评论闸共享同一实例）。
	 * @param callable|null               $recorder 事件记录器（null = WP 边界默认）。
	 * @param IpResolver|null             $resolver IP 解析器（null = direct 模式）。
	 * @param callable|null               $stub_hash 存根哈希（null = wp_hash 封装）。
	 * @param callable|null               $now      时钟（null = time()）。
	 * @param VerifyCache|null            $verify_cache 验证短缓存（null = 未装配恒验票）。
	 */
	protected function __construct(
		array $settings,
		?CaptchaDriverInterface $captcha = null,
		?callable $recorder = null,
		?IpResolver $resolver = null,
		?callable $stub_hash = null,
		?callable $now = null,
		?VerifyCache $verify_cache = null
	) {
		$this->settings = $settings;
		$this->captcha  = $captcha;
		$this->resolver = $resolver ?? new IpResolver( is_array( $settings['network'] ?? null ) ? $settings['network'] : array() );
		$this->stub_hash = $stub_hash ?? static function ( string $data ): string {
			return wp_hash( $data, 'nonce' ); // WP 边界。
		};
		$this->now      = $now ?? static function (): int {
			return time();
		};
		$this->verify_cache = $verify_cache;
		$this->recorder     = $recorder ?? function ( string $stage, Verdict $verdict, array $meta ): ?string {
			// WP 边界默认：事件表（04 §3 写入矩阵——R1 G6 should_record 收口）+ 统计计数（仅 reject）。
			$driver_id = ( '' !== $verdict->driver_id() ) ? $verdict->driver_id() : 'gate';
			$event_no  = null;
			if ( EventsTable::should_record( $stage, $verdict, $this->settings ) ) {
				$event_no = EventsTable::insert( $this->id, $stage, $driver_id, $verdict, $meta, self::ip_hash( (string) ( $meta['client_ip'] ?? '' ) ) );
			}
			if ( Verdict::REJECT === $verdict->verdict() ) {
				StatsCache::record( $this->id, $driver_id, $verdict->verdict() );
			}
			return $event_no;
		};
	}

	/**
	 * 闸设置子键（gates.{settings_key}——子类返回 'login'|'register'|'lostpassword'）。
	 *
	 * @return string
	 */
	abstract protected function settings_key(): string;

	/**
	 * 签名存根上下文身份（本闸恒等值：'login'|'register'|'lostpassword'——02 §3.3）。
	 *
	 * @return string
	 */
	abstract protected function context_id(): string;

	/**
	 * 闸是否启用（gates.{key}.enabled；子类可扩判 render_mode）。
	 *
	 * @return bool
	 */
	protected function gate_enabled(): bool {
		return ! empty( $this->settings['gates'][ $this->settings_key() ]['enabled'] );
	}

	/**
	 * 验证码驱动是否就绪（null/未配置 = 闸 inert：不渲染不拦截，03 Tab②）。
	 *
	 * @return bool
	 */
	protected function has_captcha(): bool {
		return null !== $this->captcha && $this->captcha->is_configured();
	}

	/**
	 * 当前装配的验证码驱动（Bootstrap login_enqueue_scripts 资产入队用——登录族三闸
	 * 共享同一驱动实例；基类单点，子类不再各写一份）。
	 *
	 * 【B3 真机缺陷修复 2026-11-10】Bootstrap::enqueue_login_assets 对三闸多态调用
	 * captcha_driver()/should_render()——原两新闸缺法致 wp-login 渲染期 PHP Fatal
	 * （Call to undefined method RegisterGate::should_render()，单测纯逻辑面不可达，
	 * 真机部署后发现）：两方法收口到继承面，抽象声明强制未来闸（M4）不可漏。
	 *
	 * @return CaptchaDriverInterface|null
	 */
	public function captcha_driver(): ?CaptchaDriverInterface {
		return $this->captcha;
	}

	/**
	 * 渲染期决策（Bootstrap login_enqueue_scripts 消费：出码才入队登录页脚本——三闸
	 * 任一出码即入队同一套前端，03 §2.2/§2.3）。子类按各自渲染条件实现（LoginGate
	 * =adaptive 判定；register/lostpassword =常显）。
	 *
	 * @return bool
	 */
	abstract public function should_render(): bool;

	/**
	 * 渲染编排公共骨架：签发本闸存根（captcha_shown=true——渲染即出码）→ 驱动 render()
	 * （gate 白名单 embed，05 §1.1）+ aria-live 播报位（03 §2.2 模板）。
	 *
	 * @param bool $protection_enabled 豁免 filter 结果。
	 * @return string 空 = 零 DOM。
	 */
	protected function render_embed( bool $protection_enabled ): string {
		if ( ! $protection_enabled || ! $this->gate_enabled() || ! $this->has_captcha() ) {
			return '';
		}

		$stub = Stub::issue( $this->id, $this->context_id(), true, (int) call_user_func( $this->now ), $this->stub_hash );
		$html = $this->captcha->render( array(
			'gate'       => $this->id,
			'action'     => $this->context_id(),
			'context_id' => $this->context_id(),
			'stub'       => $stub,
		) );

		// 03 §2.2/§2.3：wk-gate-login-status 播报位保留（wp-login 三表单同 .login 作用域
		// 一套复用；嵌入挂载点 .wk-gate-embed 由驱动 render() 尾随——05 §1.1 gate 白名单）。
		return '<span class="wk-gate-login-status" aria-live="polite"></span>' . $html;
	}

	/**
	 * 构建驱动 verify() context：credentialFields() 表单域 → 接口契约规范键映射
	 * （{ticket, randstr, …开放键集}——CaptchaDriverInterface::verify docblock）。
	 *
	 * M2-FIX2 D2 修复面基类单点（C3 登录闸曾漏抄该映射致票据恒空——P0）；
	 * 超出规范键集的字段按表单原名透传（开放键集，未来驱动自定义凭据域不受限）。
	 *
	 * @param array<string, mixed> $request 提交请求面。
	 * @return array{context: array<string, mixed>, has_ticket: bool}
	 */
	protected function build_verify_context( array $request ): array {
		$context   = array();
		$canonical = array( 'ticket', 'randstr' );
		$has_ticket = true;

		foreach ( array_values( $this->captcha->credentialFields() ) as $index => $field ) {
			$value = isset( $request[ $field ] ) && is_scalar( $request[ $field ] ) ? trim( (string) $request[ $field ] ) : '';
			if ( '' === $value ) {
				$has_ticket = false;
			}
			$key = isset( $canonical[ $index ] ) ? $canonical[ $index ] : $field;
			if ( ! isset( $context[ $key ] ) ) {
				$context[ $key ] = $value;
			}
		}
		return array( 'context' => $context, 'has_ticket' => $has_ticket );
	}

	/**
	 * token 单次消费（A6）：核验后按 credentialFields() 清单统一清理 $_POST
	 * （驱动实现不得假设 token 在核验后仍可读——02 §2.1 契约条款）。
	 *
	 * @return void
	 */
	protected function unset_post_fields(): void {
		$unset_fields = array( 'wk_stub' );
		if ( null !== $this->captcha ) {
			foreach ( $this->captcha->credentialFields() as $token_field ) {
				$unset_fields[] = $token_field;
			}
		}
		foreach ( $unset_fields as $unset_field ) {
			unset( $_POST[ $unset_field ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- wp-login 原生表单无 nonce（存根+票据承担）。
		}
	}

	/* -------------------------------------------------------------------
	 * 拦截出口（WP_Error 注入形态；证据行仅事件编号/规则/时间——不暴露 IP/口令线索）
	 * ------------------------------------------------------------------ */

	/**
	 * 统一拦截出口（文案键版）：Copywork 文案 + 事件 + 证据行。
	 *
	 * @param string               $stage    阶段（stub|captcha）。
	 * @param Verdict              $verdict  判定。
	 * @param string               $copy_key 文案键。
	 * @param array<string, mixed> $meta     事件 meta。
	 * @param array<string, mixed> $vars     占位符变量。
	 * @param string               $error_code WP_Error 注入码（缺省 wk_captcha_failed）。
	 * @return array{decision: string, error_code: string, message: string, evidence: array<string, string>}
	 */
	protected function reject( string $stage, Verdict $verdict, string $copy_key, array $meta, array $vars, string $error_code = self::ERROR_CODE_VERIFY ): array {
		$messages = $this->messages();
		$template = isset( $messages[ $copy_key ] ) && is_string( $messages[ $copy_key ] ) ? $messages[ $copy_key ] : '';
		return $this->reject_with_message( $stage, $verdict, Copywork::format( $template, $vars ), $meta, $vars, $error_code );
	}

	/**
	 * 拦截出口（消息终态版：驱动文案直通）。失败形态 = WP_Error/错误注入进 wp-login
	 * 原生错误框（02 §3.2/§3.2a——不 wp_die；makotowu 找回 wp_die 形态不采）。
	 *
	 * @param string               $stage    阶段。
	 * @param Verdict              $verdict  判定。
	 * @param string               $message  终态消息。
	 * @param array<string, mixed> $meta     事件 meta。
	 * @param array<string, mixed> $vars     额外占位符。
	 * @param string               $error_code WP_Error 注入码（缺省 wk_captcha_failed）。
	 * @return array{decision: string, error_code: string, message: string, evidence: array<string, string>}
	 */
	protected function reject_with_message( string $stage, Verdict $verdict, string $message, array $meta, array $vars, string $error_code = self::ERROR_CODE_VERIFY ): array {
		$now      = (int) call_user_func( $this->now );
		$event_no = (string) call_user_func( $this->recorder, $stage, $verdict, $meta );

		$evidence = array();
		if ( '' !== $event_no ) {
			$evidence['事件编号'] = $event_no;
		}
		if ( '' !== $verdict->reason() ) {
			$evidence['命中规则'] = $verdict->reason();
		}
		$evidence['时间'] = date( 'Y-m-d H:i:s', $now );

		$message = Copywork::format( $message, array_merge( $vars, array(
			'event_no' => $event_no,
			'time'     => $evidence['时间'],
			'rule'     => $verdict->reason(),
		) ) );

		return array(
			'decision'   => 'reject',
			'error_code' => $error_code,
			'message'    => $message,
			'evidence'   => $evidence,
		);
	}

	/**
	 * @return array<string, string>
	 */
	protected function messages(): array {
		$messages = $this->settings['copy']['messages'] ?? array();
		return is_array( $messages ) ? array_merge( Copywork::defaults()['messages'], array_filter( $messages, 'is_string' ) ) : Copywork::defaults()['messages'];
	}

	/**
	 * 当前验证码驱动 id（接口 v1.1 id()：事件表 driver 列/统计键单一来源）。
	 *
	 * @return string
	 */
	protected function captcha_driver_id(): string {
		$captcha = $this->captcha;
		return ( null !== $captcha && method_exists( $captcha, 'id' ) ) ? $captcha->id() : '';
	}

	/**
	 * IP 哈希（wp_salt('auth') 派生 HMAC 前 16 位，04 §3——默认记录器闭包内使用）。
	 *
	 * @param string $ip
	 * @return string
	 */
	protected static function ip_hash( string $ip ): string {
		if ( '' === $ip ) {
			return '';
		}
		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 16 );
	}

	/**
	 * 常显闸验证编排公共骨架（register/lostpassword 共用；LoginGate 因计数/iprep 特有面自持）。
	 *
	 * 存根三态：false=bad-signature；true=captcha_missing（本闸恒渲染——true 态=缓存页/
	 * 预收割，仍拦）；null=取票验票（VerifyCache → 真验）。
	 *
	 * @param array<string, mixed> $request            提交请求面。
	 * @param bool                 $protection_enabled 豁免 filter 结果。
	 * @param array<string, mixed> $extra_meta         追加事件 meta（user_present 等）。
	 * @param array<string, mixed>|null $server        $_SERVER（null = WP 边界自取）。
	 * @return array{decision: string, error_code?: string, message?: string, evidence?: array<string, string>}
	 */
	protected function verify_always_orchestrate( array $request, bool $protection_enabled, array $extra_meta = array(), ?array $server = null ): array {
		if ( ! $protection_enabled || ! $this->gate_enabled() ) {
			return array( 'decision' => 'allow' );
		}
		if ( ! $this->has_captcha() ) {
			return array( 'decision' => 'allow' ); // 未配驱动 inert（03 Tab②：不渲染不拦截）。
		}

		$resolved = $this->resolver->resolve( $server ?? ( isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array() ) );
		$ip       = is_string( $resolved['ip'] ) ? $resolved['ip'] : '';
		$base     = array_merge( array(
			'ip_src'          => $resolved['ip_src'],
			'client_ip'       => $ip,
			'trigger_source'  => 'always', // 常显闸归因（02 §3.2 事件可观测性）。
		), $extra_meta );

		/* 存根三态（gate=<id>, context_id=<id>）。 */
		$stub_raw = isset( $request['wk_stub'] ) && is_scalar( $request['wk_stub'] ) ? (string) $request['wk_stub'] : '';
		$state    = Stub::verify( $stub_raw, $this->id, $this->context_id(), (int) call_user_func( $this->now ), $this->stub_hash );

		if ( false === $state ) {
			return $this->reject( 'stub', Verdict::reject( '', 'bad-signature', array( 'bad-signature' ) ), 'captcha_missing', $base, array(), self::ERROR_CODE_STUB );
		}
		if ( true === $state ) {
			// 陈旧表单（签发时未展示挑战）——本闸恒渲染：true 态=缓存页/预收割，仍拦（02 §3.2a）。
			$meta = array_merge( $base, array( 'stale_form' => true ) );
			return $this->reject_stub_as_missing( $meta );
		}

		/* null（展示过）→ 取票验票；VerifyCache 短缓存先行（同存根+同 IP 窗口内免重复验票）。 */
		if ( null !== $this->verify_cache && $this->verify_cache->hit( $stub_raw, $ip ) ) {
			call_user_func( $this->recorder, 'captcha', Verdict::pass( $this->captcha_driver_id(), 'verify_cache_hit', array( 'verify_cache_hit' ) ), array(
				'ip_src'           => $resolved['ip_src'],
				'client_ip'        => $ip,
				'verify_cache_hit' => true,
				'trigger_source'   => 'always',
			) );
			return array( 'decision' => 'allow' );
		}

		$built = $this->build_verify_context( $request );
		if ( ! $built['has_ticket'] ) {
			return $this->reject_stub_as_missing( array_merge( $base, array( 'driver' => $this->captcha_driver_id() ) ) );
		}

		$error = $this->captcha->verify( array_merge( $built['context'], array( 'user_ip' => $ip ) ) );
		if ( null !== $error ) {
			$meta = array_merge( $base, array( 'driver' => $this->captcha_driver_id() ) );
			if ( method_exists( $this->captcha, 'last_verify_meta' ) ) {
				$meta = array_merge( $this->captcha->last_verify_meta(), $meta ); // CaptchaCode/Score 等入 meta。
			}
			$rule = isset( $meta['rule'] ) && is_string( $meta['rule'] ) ? $meta['rule'] : 'captcha_failed';
			return $this->reject_with_message( 'captcha', Verdict::reject( $this->captcha_driver_id(), $rule, array( $rule ) ), $error, $meta, array() );
		}

		/* 核验成功（verify 返回 null）→ 写验证短缓存（仅成功写）。 */
		if ( null !== $this->verify_cache ) {
			$this->verify_cache->remember( $stub_raw, $ip );
		}
		return array( 'decision' => 'allow' ); // 通过不落事件（04 §3 写入矩阵）。
	}

	/**
	 * captcha_missing 出口（stub=true/缺票共用——错误注入码=wk_captcha_missing）。
	 *
	 * @param array<string, mixed> $meta
	 * @return array{decision: string, error_code: string, message: string, evidence: array<string, string>}
	 */
	private function reject_stub_as_missing( array $meta ): array {
		return $this->reject(
			'captcha',
			Verdict::reject( $this->captcha_driver_id(), 'captcha_missing', array( 'captcha_missing' ) ),
			'captcha_missing',
			$meta,
			array(),
			self::ERROR_CODE_MISSING
		);
	}
}
