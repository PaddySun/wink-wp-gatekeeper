<?php
/**
 * 验证短缓存（M2 反馈2 T3，D+A-lite 用户选型；02 §2.1 verify 单次消费的窗口化补充）。
 *
 * 痛点：票据单次消费（A6/CaptchaCode=9）+ 票据 5 分钟过期（CaptchaCode=8）使
 * 「验证成功 → 内容被拦 → 返回改一字重提」的真人被迫重新过验证码——验票结果
 * 本来就是「这个人刚证明过自己是真人」，与内容判定无关。
 *
 * 契约：票据核验**成功**后写 transient `wk_cv_<HMAC16(存根原文|IP)>` = true，
 * TTL = captcha.verify_cache_minutes 分钟（默认 10，0 = 停用）。评论/登录两闸在
 * 存根校验通过后、调用驱动 verify 前先查缓存：命中 → 跳过验票直接进下一阶段
 * （事件 verdict=pass + meta.verify_cache_hit=true；零驱动调用 = usage 不计数）。
 *
 * 键双绑定：存根原文（绑表单会话——刷新页面换存根即不命中，缓存不跨表单白嫖）
 * + 提交 IP（IpResolver 解析值）。HMAC 密钥 = wp_salt('auth')（与 wk_dsr_/ip_hash 同源）。
 *
 * 窗口安全面（报告安全注记同文）：进缓存必须先持真票核验成功（窗口不凭空产生）；
 * 窗口内提交仍走全量内容闸（LocalRules + 云审）——缓存只豁免验证码阶段；
 * 先例：hCaptcha「同上下文结果复用」；verify_cache_minutes=0 一键关闭。
 *
 * 分层：纯逻辑类——存取经注入回调（生产 = get_transient/set_transient，Bootstrap 注入；
 * 单测注入内存假件）；默认 HMAC 回调为 WP 边界（wp_salt），测试注入固定函数。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

final class VerifyCache {

	/**
	 * transient 键前缀（reset 工具按此前缀清理）。
	 */
	public const PREFIX = 'wk_cv_';

	/**
	 * 窗内口令错误计次键前缀（【AUDIT-FIX 2026-11-20·P1-2/S6-F1】）：wk_cv_f_<HMAC16(存根原文|IP)>。
	 * 前缀仍在 wk_cv_ 族内——uninstall 步骤 4 前缀清单天然覆盖，不新增卸载面。
	 */
	public const FAIL_PREFIX = 'wk_cv_f_';

	/**
	 * 窗内口令错误吊销阈（P1-2：错 ≥5 次吊销该 wk_cv_ 缓存键——审计修复口径原值）。
	 */
	public const FAIL_LIMIT = 5;

	/**
	 * 默认窗口（分钟；04 §2 captcha.verify_cache_minutes 默认值的单一来源）。
	 */
	public const DEFAULT_TTL_MINUTES = 10;

	/**
	 * @var int 窗口分钟数（0 = 停用）。
	 */
	private $minutes;

	/**
	 * @var callable 缓存读取 fn(string $key): mixed。
	 */
	private $store_get;

	/**
	 * @var callable 缓存写入 fn(string $key, mixed $value, int $ttl_seconds): void。
	 */
	private $store_put;

	/**
	 * @var callable 键 HMAC fn(string $data): string（生产 = wp_salt('auth') 派生）。
	 */
	private $mac;

	/**
	 * @var callable|null 缓存删除 fn(string $key): void（P1-2 吊销面；null = delete_transient WP 边界默认）。
	 */
	private $store_del;

	/**
	 * @param int      $minutes    窗口分钟数（≤0 = 停用）。
	 * @param callable $store_get  读取回调。
	 * @param callable $store_put  写入回调（含 TTL 秒）。
	 * @param callable|null $mac      键 HMAC 回调（null = wp_salt('auth') WP 边界默认）。
	 * @param callable|null $store_del 删除回调（【AUDIT-FIX 2026-11-20·P1-2】null = delete_transient WP 边界默认；
	 *                                  单测注入内存假件——Bootstrap 生产装配传 'delete_transient'）。
	 */
	public function __construct( int $minutes, callable $store_get, callable $store_put, ?callable $mac = null, ?callable $store_del = null ) {
		$this->minutes    = max( 0, $minutes );
		$this->store_get = $store_get;
		$this->store_put = $store_put;
		$this->mac       = $mac
			?? static function ( string $data ): string {
				return hash_hmac( 'sha256', $data, wp_salt( 'auth' ) ); // WP 边界。
			};
		$this->store_del = $store_del
			?? static function ( string $key ): void {
				delete_transient( $key ); // WP 边界。
			};
	}

	/**
	 * 缓存键：前缀 + HMAC(存根原文|IP) 前 16 位（双绑定）。
	 *
	 * @param string   $stub_raw 存根原文（表单会话身份）。
	 * @param string   $ip       提交者真实 IP（IpResolver）。
	 * @param callable $mac      键 HMAC 回调。
	 * @return string
	 */
	public static function key( string $stub_raw, string $ip, callable $mac ): string {
		return self::PREFIX . substr( (string) call_user_func( $mac, $stub_raw . '|' . $ip ), 0, 16 );
	}

	/**
	 * 是否启用（verify_cache_minutes > 0）。
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return $this->minutes > 0;
	}

	/**
	 * 查缓存：命中 = 同存根+同 IP 在窗口内已核验成功过。
	 *
	 * WP transient 存储层对布尔值有形态漂移：redis object-cache 后端把 true 落成
	 * 字符串 '1'（wpclean 真机实测 2026-11-10），options 表后端经 maybe_serialize
	 * 可还原 true——命中判定取「标量且真值」（'1'/1/true 皆命中；false/'0'/0/null/
	 * ''/数组不命中），写侧统一存 true 不变。
	 *
	 * @param string $stub_raw 存根原文。
	 * @param string $ip       提交者 IP。
	 * @return bool
	 */
	public function hit( string $stub_raw, string $ip ): bool {
		if ( ! $this->enabled() ) {
			return false;
		}
		$value = call_user_func( $this->store_get, self::key( $stub_raw, $ip, $this->mac ) );
		return is_scalar( $value ) && (bool) $value;
	}

	/**
	 * 写缓存（仅核验成功后由闸调用——本类不判定成败，只管窗口）。
	 *
	 * @param string $stub_raw 存根原文。
	 * @param string $ip       提交者 IP。
	 * @return void
	 */
	public function remember( string $stub_raw, string $ip ): void {
		if ( ! $this->enabled() ) {
			return;
		}
		call_user_func( $this->store_put, self::key( $stub_raw, $ip, $this->mac ), true, $this->minutes * 60 );
	}

	/* ---------------------------------------------------------------
	 * 窗内口令错误计次（【AUDIT-FIX 2026-11-20·P1-2/S6-F1】——S6 报告 §2 F1 修复面）
	 *
	 * 缺口：verify_ticket 首查 VerifyCache 命中即放行（先于一切计数）——过一次码得
	 * 10min 同存根同 IP 无限次口令重试窗（WP 核心无锁定兜底）。修法：窗内口令错误
	 * 计次（键=同存根|IP 双绑定），错 ≥FAIL_LIMIT 吊销该 wk_cv_ 键（delete）→ 后续
	 * 提交须重新过码；成功登录清计数（真人口令手滑→成功的正常路径零残余）。
	 *
	 * 计次 TTL=窗口分钟×60（与缓存同窗）：计数窗起点=首次错误，缓存窗起点=过码时刻
	 * ——计数到期时缓存必已到期（T1≥T0 ⇒ T1+W ≥ T0+W），无「计数清零而窗仍开」的
	 * 绕行面。键前缀 wk_cv_f_（wk_cv_ 族——uninstall 天然覆盖）。
	 * --------------------------------------------------------------- */

	/**
	 * 窗内口令错误计次（LoginGate::hook_wp_login_failed 边界消费——仅 incorrect_password）。
	 *
	 * 达阈动作：删该 wk_cv_ 缓存键（吊销）+ 删计次键（重新过码后重新起算）。
	 *
	 * @param string $stub_raw 存根原文（与缓存键同 HMAC 键材）。
	 * @param string $ip       提交者 IP。
	 * @param int    $limit    吊销阈（缺省 FAIL_LIMIT=5）。
	 * @return bool true = 本次计次已触发吊销（缓存键已删）。
	 */
	public function record_password_failure( string $stub_raw, string $ip, int $limit = self::FAIL_LIMIT ): bool {
		if ( ! $this->enabled() || '' === $stub_raw || '' === $ip ) {
			return false; // 窗停用/身份不齐：零计次零吊销（宁不误伤）。
		}
		$limit = max( 1, $limit );
		$key   = self::fail_key( $stub_raw, $ip );
		$raw   = call_user_func( $this->store_get, $key );
		$n     = is_numeric( $raw ) ? (int) $raw : 0; // is_numeric 归一（M6·批I·I-2 数值串回读同型雷）。
		++$n;

		if ( $n >= $limit ) {
			// 吊销：缓存键 + 计次键同删（重新过码后重新起算）。
			call_user_func( $this->store_del, self::key( $stub_raw, $ip, $this->mac ) );
			call_user_func( $this->store_del, $key );
			return true;
		}
		call_user_func( $this->store_put, $key, $n, $this->minutes * 60 );
		return false;
	}

	/**
	 * 成功登录清计数（LoginGate::hook_wp_login 边界消费——真人口令手滑→成功零残余）。
	 *
	 * @param string $stub_raw 存根原文。
	 * @param string $ip       提交者 IP。
	 * @return void
	 */
	public function clear_password_failures( string $stub_raw, string $ip ): void {
		if ( '' === $stub_raw || '' === $ip ) {
			return;
		}
		call_user_func( $this->store_del, self::fail_key( $stub_raw, $ip ) );
	}

	/**
	 * 窗内口令错误计次键：FAIL_PREFIX + HMAC(存根原文|IP) 前 16 位（与缓存键同键材——双绑定）。
	 *
	 * @param string $stub_raw 存根原文。
	 * @param string $ip       提交者 IP。
	 * @return string
	 */
	private function fail_key( string $stub_raw, string $ip ): string {
		return self::FAIL_PREFIX . substr( (string) call_user_func( $this->mac, $stub_raw . '|' . $ip ), 0, 16 );
	}
}
