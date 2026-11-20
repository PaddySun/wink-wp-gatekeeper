<?php
/**
 * 事件采样封顶（M6·批F-b P1-4——施工图 §5 D-1；审计 REPORT §4 P1-4）。
 *
 * stage∈{stub,rate} 事件按 ip_hash 小时桶封顶：桶键
 * wk_es_<substr(md5(ip_hash|YmdH),0,12)> 计数 transient（TTL 2h），>10 行/IP/h
 * 拒绝（调用方丢事件行**且**跳过 stats option UPDATE——写放大双源同封）。
 * 拒绝行为本身不变、不留档语义不变（rate 段本就不留档）；真实闸链路径（有票拒、
 * 云端拒等 ip/captcha/review/core/precheck/url_audit 族 stage）不在采样域，恒放行。
 *
 * 消费点=两默认记录器闭包（CommentGate / AccountFormGate 构造器—— EventsTable::insert
 * + StatsCache::record 成对写入的唯一两处）；采样闸置于成对写入之前。Precheck 预检
 * 记录器只产 stage=precheck 行（不在采样域，不接线）；TestMode 演练行直插不经记录器
 * （人工低频面，不受采样限制）。
 *
 * 分层：纯逻辑（单测覆盖）——sampled_stage()/bucket_key()/over-cap 边界；存取经静态
 * 注入回调（生产 = get_transient/set_transient WP 边界，单测注入内存假件——
 * TransientRateLimiter 同形态）。计数为 best-effort（transient 非原子读改写——并发
 * 极端下可能略超帽；限流语义不变前提下可接受，TransientRateLimiter 同款取舍）。
 *
 * @package WinkGatekeeper\Stats
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（S2-F1 纵深加固——类文件直访零执行，守卫为纵深面）。
}

use WinkGatekeeper\Gates\AtomicCounter;

final class EventSampling {

	/**
	 * 桶键前缀（uninstall.php transient 清理清单已补录）。
	 */
	public const PREFIX = 'wk_es_';

	/**
	 * 小时桶硬顶（施工图 §5 D-1：≤10 行/IP/h——第 10 行放行、第 11 行拒绝）。
	 */
	public const HOURLY_CAP = 10;

	/**
	 * 桶 TTL 秒（2h——跨小时键自然翻转后旧桶兜底回收，Precheck wk_pk_ 同款）。
	 */
	public const TTL_SECONDS = 7200;

	/**
	 * 采样域 stage 闭集（开放枚举白名单——闸阶段名新增默认不进采样域，如实反映）。
	 */
	private const SAMPLED_STAGES = array( 'stub', 'rate', 'precheck' ); // 【S3-F4】precheck 入采样域（批前白名单遗漏——预检四闸拒逐发落库，B 机实测 5 发=5 行；10 行/IP/h 封顶同族）。

	/**
	 * @var callable|null 读取 fn(string $key): mixed（null = get_transient WP 边界默认）。
	 */
	private static $store_get;

	/**
	 * @var callable|null 写入 fn(string $key, mixed $value, int $ttl): void（null = set_transient WP 边界默认）。
	 */
	private static $store_put;

	/**
	 * @var callable|null 时钟 fn(): int（null = time()；单测注入固定/可推进时钟）。
	 */
	private static $clock;

	/** @var callable|null 原子自增路（【P1-3·S3-F1】null = WP 边界默认 AtomicCounter::incr——单测注入假件）。 */
	private static $store_incr;

	/**
	 * 注入存取回调（单测内存假件；传 null 还原 WP 边界默认——测试隔离桥）。
	 *
	 * @param callable|null      $store_get 读取回调。
	 * @param callable|null      $store_put 写入回调。
	 * @param callable|null      $clock     时钟回调。
	 * @return void
	 */
	public static function use_store( ?callable $store_get, ?callable $store_put, ?callable $clock = null ): void {
		self::$store_get = $store_get;
		self::$store_put = $store_put;
		self::$clock     = $clock;
	}

	/**
	 * stage 是否在采样封顶域（纯函数）。
	 *
	 * @param string $stage 阶段名。
	 * @return bool
	 */
	/**
	 * 注入原子自增路（【P1-3·S3-F1】；null = WP 边界默认 AtomicCounter::incr——无持久对象
	 * 缓存恒 false=legacy 桶路零变化）。
	 *
	 * @param callable|null $store_incr fn(string $key, int $ttl): int|false。
	 * @return void
	 */
	public static function use_store_incr( ?callable $store_incr ): void {
		self::$store_incr = $store_incr;
	}

	public static function sampled_stage( string $stage ): bool {
		return in_array( $stage, self::SAMPLED_STAGES, true );
	}

	/**
	 * 桶键（纯函数；施工图 §5 D-1 字面键形）：wk_es_ + substr(md5(ip_hash|YmdH), 0, 12)。
	 *
	 * @param string $ip_hash 事件归属 IP 哈希（HMAC16——与事件 ip_hash 列同值）。
	 * @param string $ymdh    小时桶标记（YmdH 形态）。
	 * @return string
	 */
	public static function bucket_key( string $ip_hash, string $ymdh ): string {
		return self::PREFIX . substr( md5( $ip_hash . '|' . $ymdh ), 0, 12 );
	}

	/**
	 * 本次事件是否放行（采样域内计数；封顶拒绝——调用方丢事件行且跳过 stats）。
	 *
	 * 非采样 stage 恒 true（零 store I/O 短路——真实闸链路径零额外开销）。
	 *
	 * @param string $stage   阶段名。
	 * @param string $ip_hash 事件归属 IP 哈希。
	 * @return bool true=放行（本次已计数）；false=封顶拒绝。
	 */
	public static function allow( string $stage, string $ip_hash ): bool {
		if ( ! self::sampled_stage( $stage ) ) {
			return true;
		}

		$ts  = null !== self::$clock ? (int) call_user_func( self::$clock ) : time();
		$key = self::bucket_key( $ip_hash, gmdate( 'YmdH', $ts ) );

		/* 【P1-3·S3-F1】原子路：INCR 计数已含本次；false/非数值（后端拒绝/无持久对象缓存）
		 * 落回 legacy get/put 桶路（现行语义零变化）。 */
		$incr = self::$store_incr ?? array( AtomicCounter::class, 'incr' );
		$n    = call_user_func( $incr, $key, self::TTL_SECONDS );
		if ( is_numeric( $n ) && (int) $n > 0 ) {
			return (int) $n <= self::HOURLY_CAP;
		}

		$get = self::$store_get
			?? static function ( string $k ) {
				return function_exists( 'get_transient' ) ? get_transient( $k ) : false; // WP 边界（纯单测环境守卫）。
			};
		$put = self::$store_put
			?? static function ( string $k, $v, int $ttl ): void {
				if ( function_exists( 'set_transient' ) ) {
					set_transient( $k, $v, $ttl ); // WP 边界。
				}
			};

		$raw   = call_user_func( $get, $key );
		/* 桶计数守卫（【B 机实测定谳 2026-11-16】Redis 对象缓存跨进程把 int 标量以 string
		 * 回读——同进程 int(7)/跨进程 string "7"；is_int 守卫会把数值串当损坏归 0 → 桶恒 1
		 * 永不封顶。is_numeric 接受 int/数值串/float；布尔/数组/垃圾串仍归 0）。 */
		$count = is_numeric( $raw ) && (int) $raw >= 0 ? (int) $raw : 0;
		if ( $count >= self::HOURLY_CAP ) {
			return false; // 封顶：>10 行/IP/h（第 11 行起拒绝）。
		}
		call_user_func( $put, $key, $count + 1, self::TTL_SECONDS );
		return true;
	}
}
