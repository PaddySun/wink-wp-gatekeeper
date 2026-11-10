<?php
/**
 * 登录闸（02 §3.2 v1.4 重构 + 03 §2.2 v1.4 嵌入式；C3 原实装 → R3 批次重构）。
 *
 * 【v1.4·D17①/F39】计数重构：双计数器 = 键 A′ 纯用户名键 wk_lf_u_（阈 user_threshold
 * 默认 3，不绑 IP 跨 IP 同账号累计——堵分布式爆破盲区）+ 键 B IP 聚合键（阈 10）；
 * **双信号真实用户过滤**（wp_authenticate_user@1 捕获钩子=主信号 + wp_login_failed
 * 错误码分类=副信号——错误码表见 LoginCounters::classify_error_code）——用户名维度只对
 * 真实存在用户的口令错误计数，unknown 只计 IP（洪泛面消失，max_user_keys/索引已删）；
 * **站点级账号警报** wk_lf_alarm（任一用户名维度饱和点亮——期间全站登录页出码）。
 *
 * 【v1.4·D17②/F40】登录页嵌入式：wk-gate-login-status 播报位 + 驱动 render() 产物
 * （gate=login 白名单 → embed + .wk-gate-embed 挂载点，05 §1.1）直接注入表单——
 * 「隐藏包裹 + 提交时唤起」旧形态与 tencent-login.js 退役删除（03 §2.2）；登录页资产
 * =与评论页同一套 gatekeeper.js + tencent.js（Bootstrap enqueue_login_assets）——提交
 * 按钮禁用（三态）/票据 5min 过期回退由该套前端承载，无 JS 服务端 WP_Error 兜底。
 *
 * 渲染条件（渲染期未知将提交的用户名，02 §3.2）：
 *   render_captcha := mode=always ∨ flag(ip) ∨ alarm(site) ∨ (iprep_gate ∧ iprep_fail(ip))
 * 验证期触发集：
 *   require_captcha := mode=always ∨ over(ip,user) ∨ (iprep_gate ∧ iprep_fail(ip))
 * **渲染⇔验证不变式**：凡存根 captcha_shown=true（null 态）的提交一律验票——不再同时
 * 要求计数超阈（站点警报下验证码已渲染、用户已完成挑战，再跳过核验=装饰性验证码；
 * 反之「渲染了却不验」给攻击者留免验窗口）。
 *
 * 验证：wp_authenticate_user @ PHP_INT_MAX（压轴聚合错误，A4）——$user 已 WP_Error
 * 透传；stub null → 恒验票；stub true/false → 仅 require_captcha 时拦（预收割干净存根
 * 不能开跳过通道条款继续成立）；验票失败 new WP_Error('wk_captcha_failed') + **点亮
 * flag(ip)**（闭环：POST 失败同请求落回表单重渲染——wp-login.php default case :1481
 * 起——渲染钩子读到的 flag 已亮 → 嵌入面板随错误框一次提交循环收敛）。
 *
 * IP 信誉三态联动（02 §2.3b 权威；载体 LoginIpRep）：skip（未启用/未装配/iprep_gate
 * 关）=完全按计数（现行行为）；fail=渲染期出码+验证期强制验票；pass=按计数。always
 * 模式跳过 iprep 查询（恒出码使判定失去分支意义，省调用）。
 *
 * XML-RPC（v1.4 触发集扩展）：失败照常计数（双信号对 XML-RPC 失败照常可用）；
 * over(ip,username) ∨ (iprep_gate ∧ iprep_fail(ip)) 命中 → authenticate @ PHP_INT_MAX
 * 压轴结构化拒绝 WP_Error('wk_captcha_unavailable')（协议内无法呈现验证码，拒绝是
 * 唯一可执行动作；system.multicall 每尝试一次断一次）；「关闭 XML-RPC 登录」一键
 * 开关保留（gates.login.xmlrpc_login → Bootstrap xmlrpc_enabled filter）。
 *
 * 事件/统计：gate=login，reject 落事件（stage=stub|captcha）+ StatsCache by_gate.login；
 * meta 增 trigger_source ∈ {user_threshold, ip_threshold, iprep, alarm, stale_form,
 * always, xmlrpc}（Tab⑥「账户保护」可归因「验证码被谁触发」）+ username_dimension /
 * iprep_state（02 §3.2 事件可观测性）。登录拒绝不 spam 留档（登录族原则）。
 *
 * 勘误（R3 §5-12/F39 随批修正）：login_form 动作**仅 login 表单内触发**
 * （wp-login.php:1532）；全 action 触发的是 login_form_{$action} 动态钩子（:561）——
 * 注册/找回各有专属钩子（RegisterGate/LostPasswordGate，02 §3.2a）。
 *
 * 分层：verify_orchestrate/render_orchestrate/xmlrpc_block_decision/record_auth_failure
 * 为纯编排（协作者全注入，单测覆盖）；hook_ 系列与 render_login_form/filter_login_form_middle
 * 为 WP 边界（$_POST/WP_Error/apply_filters）。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

use WinkGatekeeper\Drivers\CaptchaDriverInterface;
use WinkGatekeeper\Intercept\Stub;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Stats\StatsCache;
use WinkGatekeeper\Verdict;

final class LoginGate extends AccountFormGate {

	/**
	 * 闸 id（GateRegistry 注册 id；事件表 gate 列/统计键/存根 gate 字段单一来源）。
	 *
	 * @var string
	 */
	protected $id = 'login';

	/**
	 * XML-RPC 结构化拒绝错误码（02 §3.2 v1.2 安全审核 #3）。
	 */
	public const ERROR_CODE_XMLRPC = 'wk_captcha_unavailable';

	/**
	 * 认证失败随机 sleep 上限（【v1.5·B3】02 §3.2 量级口径：0–2000ms——时序混淆，
	 * 防以响应时序侧信道探测用户名存在性；Wordfence wordfencels.php:1473-1474 先例）。
	 *
	 * 量级注记（02 §3.2 原文引用）：sleep 占用 PHP worker 0–2s——独立博客低并发可接受；
	 * 高流量站/共享主机场景应关闭（filter wink_gatekeeper_login_fail_sleep_enabled，
	 * 默认 true，关=零 sleep 回现行形态）。
	 */
	public const FAIL_SLEEP_MAX_MS = 2000;

	/**
	 * XML-RPC 拒绝文案（IXR faultString 同文）。
	 */
	private const MSG_XMLRPC = '登录失败次数过多，XML-RPC 登录已暂时停用（验证码无法在 XML-RPC 协议内呈现）——请稍后再试，或在网页端完成人机验证。';

	/**
	 * 主信号请求内捕获的规范 user_login（wp_authenticate_user@1 捕获钩子写入；
	 * 供同一 PHP 请求随后触发的 wp_login_failed 读取——用户存在性钩子时序证据
	 * wp-includes/user.php:183/203：filter 仅 get_user_by 命中后触发）。
	 *
	 * @var string|null
	 */
	private static $captured_user_login = null;

	/**
	 * @var LoginCounters 双计数器（v1.4 重构版：纯用户名键 + IP 键 + 站点警报）。
	 */
	private $counters;

	/**
	 * @var LoginIpRep|null IP 信誉三态判定（02 §3.2 联动；null = 未装配=恒 skip=现行行为）。
	 */
	private $iprep;

	/**
	 * @param array<string, mixed>        $settings 设置树（SettingsSchema::defaults() 形态）。
	 * @param CaptchaDriverInterface|null $captcha  验证码驱动（与评论闸共享同一实例——凭据/限速器/传输脊一致）。
	 * @param callable|null               $recorder 事件记录器（null = WP 边界默认：EventsTable + StatsCache）。
	 * @param IpResolver|null             $resolver IP 解析器（null = direct 模式）。
	 * @param callable|null               $stub_hash 存根哈希（null = wp_hash 封装）。
	 * @param callable|null               $now      时钟（null = time()）。
	 * @param LoginCounters|null          $counters 双计数器（null = WP transient 边界装配）。
	 * @param VerifyCache|null            $verify_cache 验证短缓存（null = 未装配；T3）。
	 * @param LoginIpRep|null             $iprep    IP 信誉三态（null = Null probe 恒 skip）。
	 */
	public function __construct(
		array $settings,
		?CaptchaDriverInterface $captcha = null,
		?callable $recorder = null,
		?IpResolver $resolver = null,
		?callable $stub_hash = null,
		?callable $now = null,
		?LoginCounters $counters = null,
		?VerifyCache $verify_cache = null,
		?LoginIpRep $iprep = null
	) {
		parent::__construct( $settings, $captcha, $recorder, $resolver, $stub_hash, $now, $verify_cache );
		$this->counters = $counters ?? new LoginCounters(
			is_array( $settings['captcha']['login_adaptive'] ?? null ) ? $settings['captcha']['login_adaptive'] : array()
		);
		$this->iprep = $iprep; // null = 未装配（单测缺省）——恒 skip=现行行为；生产装配在 Bootstrap 注入 assemble() 产物。
	}

	/**
	 * IP 信誉三态（null 未装配=skip 短路，零 I/O）。
	 *
	 * @param string $ip 真实 IP。
	 * @return string 'skip'|'pass'|'fail'
	 */
	private function iprep_state( string $ip ): string {
		return null === $this->iprep ? 'skip' : $this->iprep->state( $ip );
	}

	public function render_hooks(): array {
		return array(
			'login_form'         => 10, // 动作钩子（wp-login.php:1532——仅 login 表单内触发，勘误见类头注）。
			'login_form_middle'  => 10, // filter（wp_login_form() 程序化表单）。
		);
	}

	public function verify_hooks(): array {
		return array( 'wp_authenticate_user' => PHP_INT_MAX ); // 压轴聚合错误（A4 依据）。
	}

	protected function settings_key(): string {
		return 'login';
	}

	protected function context_id(): string {
		return 'login';
	}

	/**
	 * 闸是否启用（gates.login.enabled 且 render_mode ≠ off）。
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		if ( ! $this->gate_enabled() ) {
			return false;
		}
		return 'off' !== $this->mode();
	}

	/**
	 * 当前模式（off|adaptive|always；缺省 adaptive）。
	 *
	 * @return string
	 */
	public function mode(): string {
		$login = is_array( $this->settings['gates']['login'] ?? null ) ? $this->settings['gates']['login'] : array();
		$mode  = is_string( $login['render_mode'] ?? null ) ? $login['render_mode'] : 'adaptive';
		return in_array( $mode, array( 'off', 'adaptive', 'always' ), true ) ? $mode : 'adaptive';
	}

	/**
	 * 计数器（Bootstrap 计数钩子与测试共用）。
	 *
	 * @return LoginCounters
	 */
	public function counters(): LoginCounters {
		return $this->counters;
	}

	/* -------------------------------------------------------------------
	 * 纯编排（单测覆盖）
	 * ------------------------------------------------------------------ */

	/**
	 * 认证失败随机 sleep 时长生成（【v1.5·B3】纯逻辑可测面——均匀分布 [0, max_ms] 毫秒）。
	 *
	 * WP 边界消费（maybe_fail_sleep：filter 开 + usleep(ms*1000)）；边界层 WP 函数
	 * wp_rand 与本函数 mt_rand 同均匀分布语义（wp_rand 为其种子封装）——为保持纯逻辑
	 * 零 WP 依赖（G5），生成面直用 PHP 原生 mt_rand。
	 *
	 * @param int $max_ms 上限毫秒（默认 2000；≤0 恒 0——filter 关闭语义边界）。
	 * @return int
	 */
	public static function fail_sleep_delay_ms( int $max_ms = self::FAIL_SLEEP_MAX_MS ): int {
		$max = max( 0, $max_ms );
		return ( 0 === $max ) ? 0 : mt_rand( 0, $max );
	}

	/**
	 * at计数门控（【v1.5·F4-6】attempts 维——04 §4：wp_login_failed 路径直 bump
	 * by_gate.login.attempts，不经事件表）。empty_* core 忽略码不计（与计数器
	 * 分类同一口径——LoginCounters::classify_error_code）。
	 *
	 * @param string $error_code WP_Error::get_error_code()。
	 * @return bool
	 */
	public static function attempts_countable( string $error_code ): bool {
		$dims = LoginCounters::classify_error_code( $error_code );
		return $dims['ip'] || $dims['user'];
	}

	/**
	 * 验证编排（wp_authenticate_user 的纯逻辑面——v1.4 渲染⇔验证不变式次序）。
	 *
	 * @param string               $username          提交用户名（计数键取规范 user_login——由调用方归并）。
	 * @param array<string, mixed> $request           提交请求面（$_POST / XML body）。
	 * @param array<string, mixed> $server            $_SERVER（IP 解析注入）。
	 * @param bool                 $user_is_error     前置链已产出 WP_Error（透传语义）。
	 * @param bool                 $protection_enabled wk_login_protection_enabled filter 结果。
	 * @return array{decision: string, error_code?: string, message?: string, evidence?: array<string, string>}
	 */
	public function verify_orchestrate( string $username, array $request, array $server, bool $user_is_error, bool $protection_enabled ): array {
		if ( $user_is_error ) {
			return array( 'decision' => 'allow' ); // 透传：不覆盖别人的错误（边界层原样返回 $user）。
		}
		if ( ! $protection_enabled || ! $this->is_active() ) {
			return array( 'decision' => 'allow' ); // 豁免 filter / 闸关：整体跳过。
		}

		$resolved = $this->resolver->resolve( $server );
		$ip       = is_string( $resolved['ip'] ) ? $resolved['ip'] : '';
		$always   = 'always' === $this->mode();

		/* IP 信誉三态（验证期；always 跳过查询——恒出码使 IP 判定失去分支意义，03 §2.2 渲染矩阵）。 */
		$iprep_state = $always ? 'skip' : $this->iprep_state( $ip );

		/* 出码条件归因（trigger_source，02 §3.2 事件可观测性）。 */
		$trigger = null;
		if ( $always ) {
			$trigger = 'always';
		} elseif ( null !== ( $source = $this->counters->saturation_source( $ip, $username ) ) ) {
			$trigger = $source; // user_threshold | ip_threshold。
		} elseif ( 'fail' === $iprep_state ) {
			$trigger = 'iprep';
		}

		if ( ! $this->has_captcha() ) {
			// 未配置驱动：出码条件即便命中也不渲染不拦截（02 §2.1 captcha 阶段整体跳过）。
			if ( 'iprep' === $trigger ) {
				// iprep fail 但无验证码驱动可出：仅记事件留痕（02 §3.2 iprep_fail_no_driver）。
				call_user_func( $this->recorder, 'captcha', Verdict::pass( '', 'iprep_fail_no_driver', array( 'iprep_fail_no_driver' ) ), array(
					'ip_src'      => $resolved['ip_src'],
					'client_ip'   => $ip,
					'iprep_state' => $iprep_state,
				) );
			} elseif ( null !== $trigger ) {
				call_user_func( $this->recorder, 'captcha', Verdict::pass( '', 'no_driver', array( 'no_driver' ) ), array(
					'ip_src'         => $resolved['ip_src'],
					'client_ip'      => $ip,
					'skip_no_driver' => true,
					'mode'           => $this->mode(),
				) );
			}
			return array( 'decision' => 'allow' );
		}

		$meta = array(
			'ip_src'    => $resolved['ip_src'],
			'client_ip' => $ip,
		);
		if ( null !== $trigger ) {
			$meta['trigger_source'] = $trigger;
		}
		if ( 'user_threshold' === $trigger ) {
			$meta['username_dimension'] = true; // 用户名维度计数路径标注（02 §3.2）。
		}
		if ( 'skip' !== $iprep_state ) {
			$meta['iprep_state'] = $iprep_state;
		}

		/* 存根三态（gate=login, context_id='login'）。 */
		$stub_raw = isset( $request['wk_stub'] ) && is_scalar( $request['wk_stub'] ) ? (string) $request['wk_stub'] : '';
		$state    = Stub::verify( $stub_raw, $this->id, 'login', (int) call_user_func( $this->now ), $this->stub_hash );

		if ( null === $state ) {
			/* 渲染⇔验证不变式：展示过（null）→ 恒验票（02 §3.2 v1.4 关键修正）——渲染期出码
			 * 条件（flag/alarm/iprep/always）与验证期计数可漂移，验票不依赖验证期再判定。 */
			if ( ! isset( $meta['trigger_source'] ) && $this->counters->site_alarm() ) {
				$meta['trigger_source'] = 'alarm'; // 站点警报下的验证码渲染（本 IP 计数未超）。
			}
			return $this->verify_ticket( $request, $stub_raw, $meta );
		}

		if ( null === $trigger ) {
			return array( 'decision' => 'allow' ); // 未渲染且未超阈：零打扰放行进正常认证。
		}

		/* 拒绝（require_captcha 命中下的坏签名/陈旧表单）→ 点亮 flag 闭环（02 §3.2）。 */
		$this->counters->light_flag( $ip );

		if ( false === $state ) {
			return $this->reject( 'stub', Verdict::reject( '', 'bad-signature', array( 'bad-signature' ) ), 'captcha_missing', $meta, array() );
		}

		// 陈旧表单（签发于未出码期，未展示挑战）：出码条件下放行 = 给预收割干净存根开跳过通道。
		$meta['stale_form'] = true;
		return $this->reject( 'captcha', Verdict::reject( $this->captcha_driver_id(), 'captcha_missing', array( 'captcha_missing' ) ), 'captcha_missing', $meta, array() );
	}

	/**
	 * XML-RPC 拒绝决策（authenticate 压轴消费）——v1.4 触发集扩展：
	 * over(ip,username) ∨ (iprep_gate ∧ iprep_fail(ip)) 且驱动已配置 → 拒。
	 *
	 * 未配置驱动不拒（02 §2.1：captcha 阶段整体跳过——「验证码不可用」的拒绝以存在
	 * 验证码编排为前提；multicall 洪水下逐尝试记事件会淹事件表，静默放行交由键 B 计数）。
	 *
	 * @param string               $username          提交用户名。
	 * @param array<string, mixed> $server            $_SERVER。
	 * @param bool                 $protection_enabled 豁免 filter 结果。
	 * @return bool
	 */
	public function xmlrpc_block_decision( string $username, array $server, bool $protection_enabled = true ): bool {
		if ( ! $protection_enabled || ! $this->is_active() ) {
			return false;
		}
		if ( ! $this->has_captcha() ) {
			return false;
		}
		if ( 'always' === $this->mode() ) {
			return true; // 常显模式无计数前置：XML-RPC 无法呈现验证码 → 恒拒。
		}
		$resolved = $this->resolver->resolve( $server );
		$ip       = is_string( $resolved['ip'] ) ? $resolved['ip'] : '';
		if ( null !== $this->counters->saturation_source( $ip, $username ) ) {
			return true; // over(ip,username)——两维度任一饱和。
		}
		return 'fail' === $this->iprep_state( $ip ); // (iprep_gate ∧ iprep_fail)——触发集扩展项。
	}

	/**
	 * 渲染编排（login_form / login_form_middle 的纯逻辑面——v1.4 嵌入式）。
	 *
	 * 渲染条件（渲染期未知将提交的用户名）：mode=always ∨ flag(ip) ∨ alarm(site) ∨
	 * (iprep_gate ∧ iprep_fail(ip))——判定次序固定本地在前（flag→alarm→iprep 缓存→
	 * 远程，渲染期预算，03 §2.2）。输出 = 播报位 + 驱动 render()（embed 白名单 +
	 * .wk-gate-embed 挂载点）——「隐藏包裹 + 提交时唤起」旧形态退役（03 §2.2 v1.4）。
	 *
	 * @param array<string, mixed> $server            $_SERVER。
	 * @param bool                 $protection_enabled 豁免 filter 结果。
	 * @return string 空 = 零 DOM（零打扰基线）。
	 */
	public function render_orchestrate( array $server, bool $protection_enabled = true ): string {
		if ( ! $protection_enabled || ! $this->is_active() ) {
			return '';
		}
		if ( ! $this->has_captcha() ) {
			return ''; // 未配置驱动：渲染与验证同口径跳过。
		}

		if ( 'always' !== $this->mode() ) {
			$resolved = $this->resolver->resolve( $server );
			$ip       = is_string( $resolved['ip'] ) ? $resolved['ip'] : '';
			if ( '' === $ip ) {
				return '';
			}
			// 本地短路在前：flag → alarm → iprep（缓存优先，未命中才远程——LoginIpRep 内建）。
			if ( ! $this->counters->render_flag( $ip )
				&& ! $this->counters->site_alarm()
				&& 'fail' !== $this->iprep_state( $ip ) ) {
				return ''; // adaptive 未触发：零 DOM（D9 零打扰基线不变）。
			}
		}

		return $this->render_embed( $protection_enabled );
	}

	/**
	 * 双信号计数编排（wp_login_failed 的纯逻辑面——02 §3.2 错误码分类表消费）。
	 *
	 * 主信号优先（捕获的规范 WP_User->user_login——邮箱登录 admin@site.com 与登录名
	 * admin 归并为同一键）；主信号丢失回落净化提交串；分类见
	 * LoginCounters::classify_error_code。
	 *
	 * @param string      $ip                  提交者真实 IP（IpResolver 解析值）。
	 * @param string      $submitted_username  提交用户名（边界层已 sanitize_user）。
	 * @param string|null $captured_user_login 主信号捕获的规范 user_login（null=丢失）。
	 * @param string      $error_code          WP_Error::get_error_code()。
	 * @return void
	 */
	public function record_auth_failure( string $ip, string $submitted_username, ?string $captured_user_login, string $error_code ): void {
		$dims = LoginCounters::classify_error_code( $error_code );
		if ( ! $dims['ip'] && ! $dims['user'] ) {
			return; // empty_*（core 忽略清单——防御性归零）。
		}

		$user_dim = '';
		if ( $dims['user'] ) {
			// 主信号有则用规范 user_login；丢失回落 sanitize_user($username) 提交串（02 §3.2 表）。
			$user_dim = ( null !== $captured_user_login && '' !== $captured_user_login )
				? $captured_user_login
				: $submitted_username;
		}
		$this->counters->record_failure( $ip, $user_dim );
	}

	/* -------------------------------------------------------------------
	 * WP 边界钩子（单测环境不可达；静态自查清单覆盖）
	 * ------------------------------------------------------------------ */

	/**
	 * wp_authenticate_user @ 1（主信号捕获钩子——v1.4 双信号之一）。
	 *
	 * 只记录不动值：$user instanceof WP_User（= 用户存在，filter 仅 get_user_by 命中后
	 * 触发——wp-includes/user.php:183/203）时把规范 user_login 存请求内静态成员，供同一
	 * PHP 请求随后触发的 wp_login_failed 读取。优先级 1=在其他插件改写 $user 之前看到
	 * 原初 WP_User（XML-RPC 同链生效）。验证逻辑不在此钩子（压轴在 hook_wp_authenticate_user）。
	 *
	 * @param \WP_User|\WP_Error $user     用户（或前置链错误）。
	 * @param string             $password 密码（未消费）。
	 * @return \WP_User|\WP_Error 原样透传（只记录不动值）。
	 */
	public function hook_capture_wp_authenticate_user( $user, string $password ) {
		unset( $password );
		if ( $user instanceof \WP_User ) {
			self::$captured_user_login = (string) $user->user_login;
		}
		return $user;
	}

	/**
	 * wp_authenticate_user @ PHP_INT_MAX（压轴聚合——验证编排）。
	 *
	 * @param \WP_User|\WP_Error $user     用户（或前置链错误）。
	 * @param string             $password 密码（未消费——验证码核验先于口令语义）。
	 * @return \WP_User|\WP_Error
	 */
	public function hook_wp_authenticate_user( $user, string $password ) {
		unset( $password );

		if ( is_wp_error( $user ) ) {
			return $user; // 透传：不覆盖别人的错误（02 §3.2）。
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return $user; // XML-RPC：验证码不可呈现——由 authenticate 压轴统一处理。
		}

		// 用户名取提交串（计数键的验证期归并：优先主信号捕获的规范 user_login）。
		$username = isset( $_POST['log'] ) && is_scalar( $_POST['log'] ) ? sanitize_user( (string) wp_unslash( $_POST['log'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- wp-login 原生表单无 nonce，验证由存根+票据承担。
		if ( '' === $username && null !== self::$captured_user_login ) {
			$username = self::$captured_user_login;
		}
		if ( '' === $username && $user instanceof \WP_User ) {
			$username = (string) $user->user_login;
		}

		$request = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 同上。
		$result  = $this->verify_orchestrate(
			$username,
			$request,
			isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array(),
			false,
			/** 此 filter 供第三方登录插件协调（02 §3.2 自适应豁免；借鉴 hcap 命名习惯）。 */
			(bool) apply_filters( 'wk_login_protection_enabled', true )
		);

		$this->unset_post_fields(); // token 单次消费（A6）。

		if ( 'reject' === $result['decision'] ) {
			$this->maybe_fail_sleep(); // 【v1.5·B3】失败分支随机 sleep（WP_Error 组装前）。
			return new \WP_Error( self::ERROR_CODE_VERIFY, isset( $result['message'] ) ? $result['message'] : '' );
		}
		return $user;
	}

	/**
	 * authenticate @ PHP_INT_MAX（链尾聚合点）：XML-RPC 超阈/iprep fail 结构化拒绝。
	 *
	 * 位置依据（「WP filter 链上最稳位置」）：authenticate 链上默认认证回调
	 * （wp_authenticate_username_password / _email_password @ 20）不以 WP_Error 短路
	 * ——早期优先级返回的错误会被后续默认回调的认证结果覆盖；PHP_INT_MAX 压轴返回值即
	 * 最终值，对「正确口令也无法绕过」「不存在用户名的喷洒」两种形态都成立，且
	 * system.multicall 每个内嵌登录尝试各自走一遍链（逐尝试断流）。
	 *
	 * @param \WP_User|\WP_Error|null $user      前置链结果。
	 * @param string                  $username  提交用户名。
	 * @param string                  $password  口令（未消费）。
	 * @return \WP_User|\WP_Error|null
	 */
	public function hook_authenticate( $user, string $username, string $password = '' ) {
		unset( $password );

		if ( ! defined( 'XMLRPC_REQUEST' ) || ! XMLRPC_REQUEST ) {
			return $user; // 仅 XML-RPC 上下文（wp-login 走 wp_authenticate_user 通道）。
		}
		if ( ! $this->xmlrpc_block_decision(
			$username,
			isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array(),
			(bool) apply_filters( 'wk_login_protection_enabled', true )
		) ) {
			return $user;
		}

		$resolved = $this->resolver->resolve( isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array() );
		call_user_func( $this->recorder, 'captcha', Verdict::reject( '', self::ERROR_CODE_XMLRPC, array( self::ERROR_CODE_XMLRPC ) ), array(
			'ip_src'         => $resolved['ip_src'],
			'client_ip'      => is_string( $resolved['ip'] ) ? $resolved['ip'] : '',
			'xmlrpc'         => true,
			'trigger_source' => 'xmlrpc', // 02 §3.2 事件可观测性。
		) );

		$this->maybe_fail_sleep(); // 【v1.5·B3】XML-RPC 认证失败同分支（02 §3.2 量级注记）。
		return new \WP_Error( self::ERROR_CODE_XMLRPC, self::MSG_XMLRPC );
	}

	/**
	 * wp_login_failed @ 10, 2 参：双信号计数（XML-RPC 认证失败经同一动作照常计数）。
	 *
	 * @param string     $username 提交用户名。
	 * @param \WP_Error|null $error 认证错误（@since 5.4 第二参）。
	 * @return void
	 */
	public function hook_wp_login_failed( string $username, $error = null ): void {
		if ( ! $this->is_active() ) {
			return;
		}
		/* 【v1.5·F4-6】attempts 维：失败尝试直 bump（04 §4——不经事件表；empty_* 不计）。 */
		if ( self::attempts_countable( is_wp_error( $error ) ? (string) $error->get_error_code() : '' ) ) {
			StatsCache::record_attempts( 'login' );
		}
		$resolved = $this->resolver->resolve( isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array() );
		$this->record_auth_failure(
			is_string( $resolved['ip'] ) ? $resolved['ip'] : '',
			sanitize_user( $username ),
			self::$captured_user_login,
			is_wp_error( $error ) ? (string) $error->get_error_code() : ''
		);
		self::$captured_user_login = null; // 请求内一次性消费。
	}

	/**
	 * wp_login @ 10：成功清键（v1.3 吸收③：直接删键；站点警报不随单次成功清除）。
	 *
	 * @param string $user_login 登录名（core 传入规范 user_login）。
	 * @return void
	 */
	public function hook_wp_login( string $user_login ): void {
		$resolved = $this->resolver->resolve( isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array() );
		$this->counters->clear( is_string( $resolved['ip'] ) ? $resolved['ip'] : '', $user_login );
	}

	/**
	 * xmlrpc_login_error @ 10：wk_captcha_unavailable 的 IXR faultString 文案面。
	 *
	 * @param \IXR_Error    $error 默认 IXR 错误。
	 * @param \WP_Error     $user  认证链错误。
	 * @return \IXR_Error
	 */
	public function hook_xmlrpc_login_error( $error, $user ) {
		if ( is_wp_error( $user ) && self::ERROR_CODE_XMLRPC === $user->get_error_code() ) {
			return new \IXR_Error( 403, $user->get_error_message() );
		}
		return $error;
	}

	/* -------------------------------------------------------------------
	 * B2 登录错误掩码（WORDFENCE-ADOPTION——呈现层；Bootstrap 注册 filter）
	 * ------------------------------------------------------------------ */

	/**
	 * wp_login_errors filter（wp-login.php 登录页错误聚合输出处——呈现层掩码）。
	 *
	 * 【B2】只动文案不动错误码：掩码发生在聚合输出，内部 WP_Error 码原样传给
	 * wp_login_failed 消费链（F39 双信号分类依赖内部码——分类零影响）。
	 *
	 * @param \WP_Error $errors      聚合错误框。
	 * @param string    $redirect_to 重定向目标（未消费）。
	 * @return \WP_Error
	 */
	public function filter_wp_login_errors( $errors, string $redirect_to = '' ) {
		unset( $redirect_to );
		return $this->mask_wp_errors( $errors );
	}

	/**
	 * lostpassword_errors filter（retrieve_password 错误聚合输出处——B2 复现样本
	 * 「Unknown email address. Check again or try the guide.」的掩码点）。
	 *
	 * @param \WP_Error                        $errors    聚合错误框。
	 * @param \WP_User|false|null              $user_data 用户查找结果（未消费）。
	 * @return \WP_Error
	 */
	public function filter_lostpassword_errors( $errors, $user_data = null ) {
		unset( $user_data );
		return $this->mask_wp_errors( $errors );
	}

	/**
	 * 掩码执行体（WP 边界）：开关 filter（LoginErrorMask::FILTER_ENABLED，默认 true）
	 * → 码表内错误逐条替换统一文案；本闸/第三方码原样透传。
	 *
	 * @param \WP_Error $errors 聚合错误框。
	 * @return \WP_Error
	 */
	private function mask_wp_errors( $errors ) {
		if ( ! is_wp_error( $errors ) ) {
			return $errors;
		}
		if ( ! (bool) apply_filters( LoginErrorMask::FILTER_ENABLED, true ) ) {
			return $errors;
		}
		foreach ( (array) $errors->get_error_codes() as $code ) {
			if ( ! LoginErrorMask::is_masked_code( (string) $code ) ) {
				continue;
			}
			foreach ( (array) $errors->get_error_messages( $code ) as $index => $message ) {
				$masked = LoginErrorMask::mask_message( (string) $code, (string) $message );
				if ( $masked !== $message ) {
					$errors->errors[ $code ][ $index ] = $masked;
				}
			}
		}
		return $errors;
	}

	/**
	 * login_form 动作 @ 10：仅登录表单上下文注入（勘误：login_form 仅 login 表单内触发
	 * ——wp-login.php:1532；全 action 触发的是 login_form_{$action} 动态钩子 :561，
	 * 注册/找回各有专属钩子由 RegisterGate/LostPasswordGate 承载，02 §3.2a）。
	 *
	 * @return void
	 */
	public function render_login_form(): void {
		if ( ! $this->is_login_action_context() ) {
			return;
		}
		echo $this->render_orchestrate( isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array(), (bool) apply_filters( 'wk_login_protection_enabled', true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 驱动 render 已逐属性转义；包裹层为静态标记。
	}

	/**
	 * login_form_middle filter @ 10（wp_login_form() 程序化表单——登录表单语义内建）。
	 *
	 * @param string $content 中段内容。
	 * @return string
	 */
	public function filter_login_form_middle( string $content ): string {
		return $content . $this->render_orchestrate( isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array(), (bool) apply_filters( 'wk_login_protection_enabled', true ) );
	}

	/**
	 * 渲染期决策（Bootstrap login_enqueue_scripts 消费：出码才入队登录页脚本——三闸
	 * 任一出码即入队同一套前端，03 §2.2/§2.3）。
	 *
	 * @return bool
	 */
	public function should_render(): bool {
		return '' !== $this->render_orchestrate( isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array(), (bool) apply_filters( 'wk_login_protection_enabled', true ) );
	}

	/* -------------------------------------------------------------------
	 * 内部
	 * ------------------------------------------------------------------ */

	/**
	 * 认证失败随机 sleep（【v1.5·B3】02 §3.2 量级注记：sleep 占用 PHP worker 0–2s——
	 * 独立博客低并发可接受；高流量站/共享主机场景应关闭（filter
	 * wink_gatekeeper_login_fail_sleep_enabled，默认 true，关=零 sleep 回现行形态）。
	 * Wordfence wordfencels.php:1473-1474 先例——usleep( mt_rand(0,2000) * 1000 )）。
	 *
	 * @return void
	 */
	private function maybe_fail_sleep(): void {
		if ( (bool) apply_filters( 'wink_gatekeeper_login_fail_sleep_enabled', true ) ) {
			usleep( self::fail_sleep_delay_ms() * 1000 );
		}
	}

	/**
	 * 验票编排（存根 null 态——渲染⇔验证不变式路径；VerifyCache → 真验票）。
	 *
	 * @param array<string, mixed> $request  提交请求面。
	 * @param string               $stub_raw 存根原文。
	 * @param array<string, mixed> $meta     事件 meta（已含 trigger_source 等）。
	 * @return array{decision: string, error_code?: string, message?: string, evidence?: array<string, string>}
	 */
	private function verify_ticket( array $request, string $stub_raw, array $meta ): array {
		$ip = is_string( $meta['client_ip'] ?? null ) ? (string) $meta['client_ip'] : '';

		/* T3 验证短缓存：同存根+同 IP 窗口内已核验成功过 → 跳过验票放行
		 * （真票单次消费的窗口化：验证后账密错→返回重提不再被 CaptchaCode=9 卡死）。 */
		if ( null !== $this->verify_cache && $this->verify_cache->hit( $stub_raw, $ip ) ) {
			$pass_meta = array_merge( $meta, array( 'verify_cache_hit' => true ) );
			call_user_func( $this->recorder, 'captcha', Verdict::pass( $this->captcha_driver_id(), 'verify_cache_hit', array( 'verify_cache_hit' ) ), $pass_meta );
			return array( 'decision' => 'allow' );
		}

		$built = $this->build_verify_context( $request );
		if ( ! $built['has_ticket'] ) {
			$this->counters->light_flag( $ip ); // 拒绝 → flag 闭环（02 §3.2）。
			$meta['driver'] = $this->captcha_driver_id();
			return $this->reject( 'captcha', Verdict::reject( $this->captcha_driver_id(), 'captcha_missing', array( 'captcha_missing' ) ), 'captcha_missing', $meta, array(), self::ERROR_CODE_MISSING );
		}

		$error = $this->captcha->verify( array_merge( $built['context'], array( 'user_ip' => $ip ) ) );
		if ( null !== $error ) {
			$this->counters->light_flag( $ip ); // 拒绝 → flag 闭环。
			if ( method_exists( $this->captcha, 'last_verify_meta' ) ) {
				$meta = array_merge( $this->captcha->last_verify_meta(), $meta ); // CaptchaCode/Score 等入 meta。
			}
			$meta['driver'] = $this->captcha_driver_id();
			$rule = isset( $meta['rule'] ) && is_string( $meta['rule'] ) ? $meta['rule'] : 'captcha_failed';
			return $this->reject_with_message( 'captcha', Verdict::reject( $this->captcha_driver_id(), $rule, array( $rule ) ), $error, $meta, array() );
		}

		/* 核验成功（verify 返回 null）→ 写验证短缓存（仅成功写；键=存根原文|IP 双绑定）。 */
		if ( null !== $this->verify_cache ) {
			$this->verify_cache->remember( $stub_raw, $ip );
		}

		return array( 'decision' => 'allow' ); // 通过不落事件（04 §3 写入矩阵：pass 默认不记）。
	}

	/**
	 * 登录表单上下文判定（login_form 动作仅 login 表单内触发——v1.4 勘误后的判定法
	 * 沿 hCaptcha is_wp_login_form 的 did_action 形态；兜底 REQUEST action 缺省/即 login）。
	 *
	 * @return bool
	 */
	private function is_login_action_context(): bool {
		if ( did_action( 'login_form_login' ) > 0 ) {
			return true;
		}
		$action = isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ? (string) wp_unslash( $_REQUEST['action'] ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读判定渲染上下文，不做写操作。
		return '' === $action || 'login' === $action;
	}
}
