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
	 * @param int      $minutes    窗口分钟数（≤0 = 停用）。
	 * @param callable $store_get  读取回调。
	 * @param callable $store_put  写入回调（含 TTL 秒）。
	 * @param callable|null $mac   键 HMAC 回调（null = wp_salt('auth') WP 边界默认）。
	 */
	public function __construct( int $minutes, callable $store_get, callable $store_put, ?callable $mac = null ) {
		$this->minutes    = max( 0, $minutes );
		$this->store_get = $store_get;
		$this->store_put = $store_put;
		$this->mac       = $mac
			?? static function ( string $data ): string {
				return hash_hmac( 'sha256', $data, wp_salt( 'auth' ) ); // WP 边界。
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
}
