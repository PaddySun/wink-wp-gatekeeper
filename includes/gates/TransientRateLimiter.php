<?php
/**
 * TransientRateLimiter——参数化共享限流器（P0-A 评论提交限流件，M3-IP 定稿 §8.1）。
 *
 * DegradedPassLimiter 同形态泛化（新类新文件——同库串行纪律：不改 DegradedPassLimiter
 * 本体及其消费方）：transient 计数桶 {reset,count}，每 IP（HMAC 定键）窗口内 limit 次
 * 内放行（并计数），超额 false。桶名（前缀）/窗口/阈值/键 HMAC 全部参数可配——
 * 首消费方=评论提交限流（wk_cm_ per-IP 小时桶，gates.comment.submit_rate_limit 默认
 * 30 次/h/IP，挂 wp-comments-post / admin-ajax / REST 三通道提交入口，AUDIT-CC §2.1
 * P0-A 修复）；后续消费方（设计预留）：IP 信誉 check 面（定稿 §3）、report 面（§4.3
 * 桶③）、P1-C 云审送审帽（Backlog 就绪件）。
 *
 * 计数语义（§8.1）：限的是「提交动作」不是「过码动作」——被四闸/验证码拒绝的提交
 * 同样计数（限的是洪水不是结果）；同请求幂等计 1（消费方单点调用）。
 *
 * 分层：纯逻辑——存取经注入回调（生产 = get_transient/set_transient WP 边界，Bootstrap
 * 装配；单测注入内存假件）；默认 HMAC 回调 wp_salt('auth') 为 WP 边界。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

final class TransientRateLimiter {

	/**
	 * 默认限额（M3-IP 定稿 §8.1：评论提交 30 次/h/IP——schema gates.comment.submit_rate_limit）。
	 */
	public const DEFAULT_LIMIT  = 30;

	/**
	 * 默认窗口（秒；1 小时——per-IP 小时桶）。
	 */
	public const DEFAULT_WINDOW = 3600;

	/**
	 * 评论提交限流 transient 键前缀（M3-IP 定稿 §8.1/§9——uninstall.php 清理清单已补录）。
	 */
	public const PREFIX = 'wk_cm_';

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
	 * @param int           $limit     限额（0 = 桶语义恒拒——「不限流」开关语义由消费方
	 *                                 承载：Bootstrap 对 ≤0 不装配本件，闸侧 null=跳过）。
	 * @param int           $window    窗口秒。
	 * @param callable      $store_get 读取回调。
	 * @param callable      $store_put 写入回调。
	 * @param callable|null $mac       键 HMAC（null = wp_salt('auth') WP 边界默认）。
	 * @param string        $prefix    键前缀（消费方自定义桶名；评论提交=wk_cm_）。
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
	 * 是否允许本次提交（额度内并计数；超额/桶损坏防御归拒绝）。
	 *
	 * 计数语义：本次调用即一次「提交动作」计数——不管提交后续被四闸/验证码拒绝还是放行
	 * （限的是洪水不是结果，§8.1）；消费方每请求只调一次（同请求幂等计 1）。
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
		call_user_func( $this->store_put, $key, $bucket, $this->window + 60 ); // TTL = window + 60 兜底回收（DegradedPassLimiter 同款）。
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
