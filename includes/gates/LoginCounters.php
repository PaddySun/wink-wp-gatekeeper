<?php
/**
 * 登录失败双计数器（02 §3.2【v1.4·R3/D17① 重构：纯用户名键 + 真实用户过滤 + 站点级警报】）。
 *
 *   键 A′（用户名键） wk_lf_u_<hmac16('u|'+user_login)>  阈 user_threshold（默认 3）
 *                     —— 纯用户名键不绑 IP：同一 user_login 的失败**跨 IP 累计**
 *                     （堵「僵尸网络分布式爆破单账号（每 IP 只试 1–2 次规避阈值）」的
 *                     结构性盲区——旧组合键每新 IP 各开新键、计数永不累加）。
 *   键 B（IP 聚合键） wk_lf_ip_<hmac16(ip)>              阈 ip_threshold（默认 10）
 *                     —— 防单 IP password spray（轮换用户名）。
 *   任一维度超阈 = 已饱和（出码 + 强制验票）。
 *
 * 真实用户过滤（洪泛防护核心，双信号在 LoginGate——本类只收「用户名维度参与与否」）：
 * 用户名维度只对真实存在用户的口令错误计数（键空间上界=站点真实用户数），unknown
 * username 只计 IP 维度。旧 v1.2 洪泛机制（max_user_keys 上限 20 + wk_lf_idx_* 索引）
 * **整体删除**——防护对象消失；旧组合键 `wk_lf_<hmac(ip|username)>` 零迁移（TTL 15min
 * 自然过期，新代码不再读写，04 §1/§2）。
 *
 * 站点级账号警报 wk_lf_alarm（新单键）：任一用户名维度饱和时点亮（TTL=window，随每次
 * 新的用户名维度饱和续期；**登录成功不清除**——聚合「该账号正被分布式攻击」的事实，
 * TTL 到期自然熄灭）。效果：账号被分布式爆破期间所有访客的登录页直接出码（渲染期
 * 用户名维度的唯一可表达形态）。
 *
 * 渲染标志 wk_lf_flag_<hmac16(ip)>：任一维度饱和点亮（TTL=window）+ **验证期点亮闭环**
 * （light_flag 公开入口——验证期拒绝无票/陈旧提交时点亮，POST 失败同请求重渲染 →
 * 面板随错误框一次循环收敛，02 §3.2）。损坏按已点亮（fail-safe①）。
 *
 * v1.3 对比取舍三点吸收（保持 transient 双键方案，不切 hCaptcha 1024 槽 option 表）：
 *   ① fail-safe 读：损坏/非桶形态按「已饱和」处理；损坏发生在计数路径时按饱和值重写
 *      一个完整窗口（确定性恢复）；② 原子性容忍：读-改-写误差 ≤1 次/窗口显式接受；
 *   ③ 清零竞态简化：成功登录直接删键（残余竞态写明不隐藏）。
 *
 * 窗口语义：固定窗口（自该键首次失败起 window 秒，后续失败不续期）；窗口过后首个新
 * 失败重开新桶。**饱和短路**（R3 §4.2 性能账）：桶已饱和跳过 bump 写（值不变写退化为
 * 覆盖同值——一行判断收口）。
 *
 * 分层：存储/HMAC/时钟全部注入（生产 = WP transient + wp_salt 派生 HMAC + time()；
 * 单测注入内存后端与固定时钟）——本类零 WP 依赖。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

final class LoginCounters {

	/** 用户名键前缀（02 §3.2 v1.4：纯用户名键，键材 'u|'+user_login）。 */
	public const KEY_USER = 'wk_lf_u_';
	/** IP 聚合键前缀（键 B，键名/定键与 v1.3 一致）。 */
	public const KEY_IP = 'wk_lf_ip_';
	/** 渲染标志前缀（IP 级；验证期点亮闭环共用）。 */
	public const KEY_FLAG = 'wk_lf_flag_';
	/** 站点级账号警报（单键，04 §1 v1.4 新键清单；卸载 wk_lf_ 前缀天然覆盖）。 */
	public const KEY_ALARM = 'wk_lf_alarm';

	/** 桶外 GC 余量（秒）：transient TTL = 到期剩余 + 余量，过期语义由桶内 exp 承担。 */
	private const TTL_MARGIN = 60;

	/**
	 * @var int 用户名维度阈值（默认 3；user_threshold——schema v2 迁移自 threshold）。
	 */
	private $user_threshold;

	/**
	 * @var int IP 维度阈值（默认 10）。
	 */
	private $ip_threshold;

	/**
	 * @var int 窗口（秒，默认 15min；警报 TTL 同窗）。
	 */
	private $window;

	/**
	 * @var callable fn(string): mixed 存储读（生产 get_transient）。
	 */
	private $get;

	/**
	 * @var callable fn(string, mixed, int): void 存储写（生产 set_transient，第三参 TTL）。
	 */
	private $set;

	/**
	 * @var callable fn(string): void 存储删（生产 delete_transient）。
	 */
	private $del;

	/**
	 * @var callable fn(string): string 定键 HMAC（16 hex；生产 wp_salt('auth') 派生）。
	 */
	private $hmac;

	/**
	 * @var callable fn(): int 时钟（生产 time()）。
	 */
	private $now;

	/**
	 * @param array<string, mixed> $settings captcha.login_adaptive 子树（缺键按默认；调用方
	 *                                        LoginGate 传 settings['captcha']['login_adaptive']）。
	 * @param callable|null        $get      存储读（null = WP transient 边界闭包）。
	 * @param callable|null        $set      存储写。
	 * @param callable|null        $del      存储删。
	 * @param callable|null        $hmac     定键 HMAC（16 hex）。
	 * @param callable|null        $now      时钟。
	 */
	public function __construct(
		array $settings = array(),
		?callable $get = null,
		?callable $set = null,
		?callable $del = null,
		?callable $hmac = null,
		?callable $now = null
	) {
		// 【v1.4·R4-G】threshold 已迁移为 user_threshold（读取兼容旧键——迁移前的 option 直读场景）。
		$this->user_threshold = max( 1, (int) ( $settings['user_threshold'] ?? $settings['threshold'] ?? 3 ) );
		$this->ip_threshold   = max( 1, (int) ( $settings['ip_threshold'] ?? 10 ) );
		$this->window         = max( 60, (int) ( $settings['window_minutes'] ?? 15 ) * 60 );

		$this->get  = $get ?? static function ( string $key ) {
			return get_transient( $key ); // WP 边界。
		};
		$this->set  = $set ?? static function ( string $key, $value, int $ttl ): void {
			set_transient( $key, $value, $ttl ); // WP 边界。
		};
		$this->del  = $del ?? static function ( string $key ): void {
			delete_transient( $key ); // WP 边界。
		};
		$this->hmac = $hmac ?? static function ( string $data ): string {
			return substr( hash_hmac( 'sha256', $data, wp_salt( 'auth' ) ), 0, 16 ); // WP 边界。
		};
		$this->now  = $now ?? static function (): int {
			return time(); // WP 边界。
		};
	}

	/* -------------------------------------------------------------------
	 * 公开操作
	 * ------------------------------------------------------------------ */

	/**
	 * 记一次失败（wp_login_failed 边界调用；XML-RPC 认证失败经同一动作照常计数）。
	 *
	 * 双信号分类在调用方（LoginGate::hook_wp_login_failed 按错误码表分派）：
	 *   - $user_login_for_user_dimension 非空 → 用户名维度参与计数（incorrect_password，
	 *     值=规范 WP_User->user_login，主信号丢失时回落 sanitize_user 提交串）；
	 *   - 空 → 只计 IP 维度（invalid_username/invalid_email/wk_captcha_failed/第三方码）。
	 *
	 * @param string $ip                            提交者真实 IP（IpResolver 解析值）。
	 * @param string $user_login_for_user_dimension 用户名维度计数的规范登录名（空=只计 IP）。
	 * @return void
	 */
	public function record_failure( string $ip, string $user_login_for_user_dimension = '' ): void {
		if ( '' === $ip ) {
			return; // 无 IP 无法归属计数（IpResolver unresolved 兜底——宁不计数不误伤全站）。
		}
		$now = (int) call_user_func( $this->now );

		$b_saturated = $this->bump( self::KEY_IP . $this->hash( $ip ), $this->ip_threshold, $now );

		$a_saturated = false;
		if ( '' !== $user_login_for_user_dimension ) {
			// 纯用户名键：键材 'u|'+user_login——不绑 IP，同账号跨 IP 累计（02 §3.2）。
			$a_saturated = $this->bump( self::KEY_USER . $this->hash( 'u|' . $user_login_for_user_dimension ), $this->user_threshold, $now );
		}

		if ( $a_saturated || $b_saturated ) {
			// IP 级渲染标志：该报告 IP 的出码判定源。
			$this->light_flag( $ip );
		}
		if ( $a_saturated ) {
			// 站点级账号警报：任一用户名维度饱和点亮（TTL=window；bump 饱和短路使续期只发生在
			// 新窗口的首次饱和——「随每次新的饱和续期」，02 §3.2）。
			call_user_func( $this->set, self::KEY_ALARM, array( 'exp' => $now + $this->window ), $this->window + self::TTL_MARGIN );
		}
	}

	/**
	 * 点亮 IP 级渲染标志（验证期闭环公开入口，02 §3.2——验证期因任一条件拒绝无票/陈旧
	 * 表单提交时调用；POST 失败同请求落回表单重渲染，渲染钩子读到的 flag 已亮 → 嵌入
	 * 面板随错误框一次提交循环收敛）。
	 *
	 * @param string $ip 真实 IP。
	 * @return void
	 */
	public function light_flag( string $ip ): void {
		if ( '' === $ip ) {
			return;
		}
		$now = (int) call_user_func( $this->now );
		call_user_func( $this->set, self::KEY_FLAG . $this->hash( $ip ), array( 'exp' => $now + $this->window ), $this->window + self::TTL_MARGIN );
	}

	/**
	 * 登录成功清零（wp_login 边界调用；v1.3 吸收③：直接删键，接受清零竞态）。
	 *
	 * 清 IP 键 + flag + 该 user_login 的用户名键；**站点级警报不随单次成功清零**（聚合
	 * 「该账号正被分布式攻击」的事实，TTL 到期自然熄灭——02 §3.2）。
	 *
	 * @param string $ip       真实 IP。
	 * @param string $username 本次成功登录的用户名（wp_login 传入规范 user_login）。
	 * @return void
	 */
	public function clear( string $ip, string $username ): void {
		if ( '' !== $username ) {
			call_user_func( $this->del, self::KEY_USER . $this->hash( 'u|' . $username ) );
		}
		if ( '' === $ip ) {
			return;
		}
		$ip_tag = $this->hash( $ip );
		call_user_func( $this->del, self::KEY_IP . $ip_tag );
		call_user_func( $this->del, self::KEY_FLAG . $ip_tag );
	}

	/**
	 * 是否已超阈（验证期判定：任一维度饱和即真；fail-safe 读——损坏按饱和）。
	 *
	 * 用户名维度与验证 IP 无关（纯用户名键）。
	 *
	 * @param string $ip       真实 IP。
	 * @param string $username 提交用户名（规范 user_login——由调用方归并邮箱/登录名形态）。
	 * @return bool
	 */
	public function is_saturated( string $ip, string $username ): bool {
		return null !== $this->saturation_source( $ip, $username );
	}

	/**
	 * 饱和归因（trigger_source 事件 meta 来源，02 §3.2 事件可观测性）。
	 *
	 * @param string $ip       真实 IP。
	 * @param string $username 提交用户名。
	 * @return string|null 'user_threshold' | 'ip_threshold' | null（未饱和）。
	 */
	public function saturation_source( string $ip, string $username ): ?string {
		$now = (int) call_user_func( $this->now );

		if ( '' !== $username
			&& $this->bucket_saturated( self::KEY_USER . $this->hash( 'u|' . $username ), $this->user_threshold, $now ) ) {
			return 'user_threshold'; // 用户名维度更具体，优先归因。
		}
		if ( '' !== $ip
			&& $this->bucket_saturated( self::KEY_IP . $this->hash( $ip ), $this->ip_threshold, $now ) ) {
			return 'ip_threshold';
		}
		return null;
	}

	/**
	 * IP 级渲染标志（渲染期判定源之一；损坏按已点亮——fail-safe）。
	 *
	 * @param string $ip 真实 IP。
	 * @return bool
	 */
	public function render_flag( string $ip ): bool {
		if ( '' === $ip ) {
			return false;
		}
		$now = (int) call_user_func( $this->now );
		$raw = call_user_func( $this->get, self::KEY_FLAG . $this->hash( $ip ) );

		if ( null === $raw || false === $raw ) {
			return false; // 无标志 = 未超阈。
		}
		if ( ! is_array( $raw ) || ! isset( $raw['exp'] ) || ! is_int( $raw['exp'] ) ) {
			return true; // 损坏按已点亮（吸收①）。
		}
		return $now < $raw['exp'];
	}

	/**
	 * 站点级账号警报（渲染期判定源之一：任一用户名维度饱和期间全站登录页出码）。
	 *
	 * @return bool
	 */
	public function site_alarm(): bool {
		$now = (int) call_user_func( $this->now );
		$raw = call_user_func( $this->get, self::KEY_ALARM );

		if ( null === $raw || false === $raw ) {
			return false;
		}
		if ( ! is_array( $raw ) || ! isset( $raw['exp'] ) || ! is_int( $raw['exp'] ) ) {
			return true; // 损坏按已点亮（fail-safe）。
		}
		return $now < $raw['exp'];
	}

	/**
	 * wp_login_failed 错误码 → 计数维度分类表（02 §3.2 副信号；纯函数）。
	 *
	 * @param string $code WP_Error::get_error_code()。
	 * @return array{ip: bool, user: bool}
	 */
	public static function classify_error_code( string $code ): array {
		switch ( $code ) {
			case 'incorrect_password':
				// 用户存在（wp_authenticate_user filter 已触发）+ 口令错——唯一双计形态。
				return array( 'ip' => true, 'user' => true );
			case 'invalid_username':
			case 'invalid_email':
				// 用户不存在（枚举探测/喷洒）——filter 未触发。
				return array( 'ip' => true, 'user' => false );
			case 'wk_captcha_failed':
			case 'wk_captcha_missing':
				// 本闸自身：口令正确只是验证码失败；计入用户名维度会惩罚已过码但手滑的真人。
				return array( 'ip' => true, 'user' => false );
			case 'empty_username':
			case 'empty_password':
				// core 忽略清单（pluggable.php:729-731 不触发动作）——防御性归零。
				return array( 'ip' => false, 'user' => false );
			default:
				// 第三方认证回调自定义码——存在性不可知，保守只计 IP。
				return array( 'ip' => true, 'user' => false );
		}
	}

	/* -------------------------------------------------------------------
	 * 内部
	 * ------------------------------------------------------------------ */

	/**
	 * 计数桶自增（固定窗口：exp 不随后续失败续期）；返回自增后是否已饱和。
	 *
	 * 饱和短路（R3 §4.2）：桶已饱和（未过期且 n≥阈）跳过写——持续爆破的写放大收敛。
	 *
	 * @param string $key       桶键。
	 * @param int    $threshold 该桶阈值。
	 * @param int    $now       当前时间。
	 * @return bool
	 */
	private function bump( string $key, int $threshold, int $now ): bool {
		$raw = call_user_func( $this->get, $key );

		if ( is_array( $raw ) && isset( $raw['n'], $raw['exp'] ) && is_int( $raw['n'] ) && is_int( $raw['exp'] ) ) {
			if ( $now >= $raw['exp'] ) {
				$bucket = array( 'n' => 1, 'exp' => $now + $this->window ); // 窗口过 → 重开新桶。
			} elseif ( $raw['n'] >= $threshold ) {
				return true; // 饱和短路：值不变写退化为覆盖同值——直接跳过。
			} else {
				$bucket = array( 'n' => $raw['n'] + 1, 'exp' => $raw['exp'] ); // 固定窗口：exp 不动。
			}
		} elseif ( null === $raw || false === $raw ) {
			$bucket = array( 'n' => 1, 'exp' => $now + $this->window ); // 新桶。
		} else {
			// 损坏值（吸收①）：按饱和重写完整窗口——确定性恢复，期间保持拦截侧。
			$bucket = array( 'n' => max( 1, $threshold ), 'exp' => $now + $this->window );
		}

		call_user_func( $this->set, $key, $bucket, max( self::TTL_MARGIN, $bucket['exp'] - $now ) );
		return $bucket['n'] >= $threshold;
	}

	/**
	 * 桶是否饱和（读路径 fail-safe：损坏 = 饱和）。
	 *
	 * @param string $key       桶键。
	 * @param int    $threshold 阈值。
	 * @param int    $now       当前时间。
	 * @return bool
	 */
	private function bucket_saturated( string $key, int $threshold, int $now ): bool {
		$raw = call_user_func( $this->get, $key );

		if ( null === $raw || false === $raw ) {
			return false; // 无记录。
		}
		if ( ! is_array( $raw ) || ! isset( $raw['n'], $raw['exp'] ) || ! is_int( $raw['n'] ) || ! is_int( $raw['exp'] ) ) {
			return true; // 损坏按饱和（吸收①：宁多渲染一次不漏放）。
		}
		return $now < $raw['exp'] && $raw['n'] >= $threshold;
	}

	/**
	 * @param string $data 键材料。
	 * @return string 16 hex。
	 */
	private function hash( string $data ): string {
		return (string) call_user_func( $this->hmac, $data );
	}
}
