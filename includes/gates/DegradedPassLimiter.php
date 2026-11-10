<?php
/**
 * DegradedPassLimiter——降级放行通道 TokenBucket 限流器（02 §2.1【v1.5·C1】§2.5）。
 *
 * 形态复用天御容灾限速器（TencentCaptchaDriver::wp_disaster_limiter）：transient 计数桶
 * {reset, count}，每 IP（HMAC 定键）窗口内 limit 次内放行（并计数），超额 false（按 block）。
 * 参数化 limit/window/prefix——天御 disaster 与 captcha pass_limited 两个消费方共用本件
 * （disaster 沿 wk_dsr_ 前缀键形态不变；pass_limited 用 wk_dgd_ 前缀）。
 *
 * 分层：纯逻辑——存取经注入回调（生产 = get_transient/set_transient WP 边界，Bootstrap
 * 装配；单测注入内存假件）；默认 HMAC 回调 wp_salt('auth') 为 WP 边界。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

final class DegradedPassLimiter {

	/**
	 * 默认限额（02 §2.1 errorPolicy pass_limited：每 IP 每小时 5 次）。
	 */
	public const DEFAULT_LIMIT  = 5;

	/**
	 * 默认窗口（秒；1 小时）。
	 */
	public const DEFAULT_WINDOW = 3600;

	/**
	 * pass_limited 降级放行 transient 键前缀。
	 */
	public const PREFIX = 'wk_dgd_';

	/**
	 * 天御容灾限速键前缀（wp_disaster_limiter 委托共用本件时沿用——存量桶不失效）。
	 */
	public const DISASTER_PREFIX = 'wk_dsr_';

	/**
	 * @var int 窗口内放行次数上限。
	 */
	private $limit;

	/**
	 * @var int 窗口秒数。
	 */
	private $window;

	/**
	 * @var callable 读取 fn(string $key): mixed。
	 */
	private $store_get;

	/**
	 * @var callable 写入 fn(string $key, mixed $value, int $ttl_seconds): void。
	 */
	private $store_put;

	/**
	 * @var callable 键 HMAC fn(string $data): string。
	 */
	private $mac;

	/**
	 * @var string transient 键前缀。
	 */
	private $prefix;

	/**
	 * @param int           $limit     限额（≤0 视为 0=恒拒？不——0 语义交消费方；本类按字面计数桶）。
	 * @param int           $window    窗口秒。
	 * @param callable      $store_get 读取回调。
	 * @param callable      $store_put 写入回调。
	 * @param callable|null $mac       键 HMAC（null = wp_salt('auth') WP 边界默认）。
	 * @param string        $prefix    键前缀。
	 */
	public function __construct( int $limit, int $window, callable $store_get, callable $store_put, ?callable $mac = null, string $prefix = self::PREFIX ) {
		$this->limit     = max( 0, $limit );
		$this->window    = max( 1, $window );
		$this->store_get = $store_get;
		$this->store_put = $store_put;
		$this->mac       = $mac
			?? static function ( string $data ): string {
				return hash_hmac( 'sha256', $data, wp_salt( 'auth' ) ); // WP 边界。
			};
		$this->prefix    = $prefix;
	}

	/**
	 * 是否允许本次放行（额度内并计数；超额/桶损坏防御归 block）。
	 *
	 * @param string $ip 提交者真实 IP（配额归因键）。
	 * @return bool true=额度内放行（本次已计数）；false=超额。
	 */
	public function allow( string $ip ): bool {
		$key = self::key( $ip, $this->mac, $this->prefix );
		$now = time();

		$bucket = call_user_func( $this->store_get, $key );
		if ( ! is_array( $bucket ) || ! isset( $bucket['reset'], $bucket['count'] ) || $now >= (int) $bucket['reset'] ) {
			$bucket = array( 'reset' => $now + $this->window, 'count' => 0 );
		}
		if ( (int) $bucket['count'] >= $this->limit ) {
			return false;
		}
		$bucket['count'] = (int) $bucket['count'] + 1;
		call_user_func( $this->store_put, $key, $bucket, $this->window + 60 ); // TTL = window + 60 兜底回收（天御先例）。
		return true;
	}

	/**
	 * 桶键：前缀 + HMAC16(ip)（与 VerifyCache/事件 ip_hash 同派生形态）。
	 *
	 * @param string   $ip     IP。
	 * @param callable $mac    HMAC 回调。
	 * @param string   $prefix 前缀。
	 * @return string
	 */
	public static function key( string $ip, callable $mac, string $prefix = self::PREFIX ): string {
		return $prefix . substr( (string) call_user_func( $mac, $ip ), 0, 16 );
	}
}
