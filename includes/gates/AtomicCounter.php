<?php
/**
 * AtomicCounter——原子自增件（【P1-3·S3-F1】独立审计修复批 1 防滥用批）。
 *
 * 病灶：全部 transient 限流桶 check-then-inc（get→判→put）在并发下有效上限 ≈limit×并发
 * 波数（AUDIT-INDEP-2026-11 S3-F1）。本件以 wp_cache_incr 原子自增承载计数（Redis INCR /
 * APCu inc 对象缓存原子性），首击定 TTL（固定窗口起点——并发下恰一胜者执行，±1 计数
 * 竞窗为亚毫秒级、对限流帽语义无害，如实登记）。
 *
 * 降级语义：无持久对象缓存（wp_using_ext_object_cache()=false，WP 默认 options 桶形态）
 * 或后端拒绝/值损坏（wp_cache_incr 对非整型报错返 false）→ 恒 false——调用方落回各自
 * legacy transient 桶路（现行语义零变化）。专用缓存组 wink_gatekeeper——与 transient
 * 组键空间隔离（类型不混装：本组恒整型计数）。
 *
 * 消费方：TransientRateLimiter（wk_cm_ 提交限流+wk_cai_/wk_cag_ 留档容量闸）/ LoginCounters
 * （wk_lf_*）/ EventSampling（wk_es_）。
 *
 * 卫生注记：本组键只在持久对象缓存形态存在（纯缓存键，非 transient/options）——卸载无需
 * options 清扫；对象缓存本质易失（Redis flush/到期自然回收）。首击 TTL 写失败遗留无 TTL
 * 键的形态由易失性兜底，如实登记（写放大帽另由 TTL_MARGIN 窗口约束）。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（S2-F1 纵深加固——类文件直访零执行，守卫为纵深面）。
}

final class AtomicCounter {

	/**
	 * 专用缓存组（与 transient 组隔离——本组恒整型计数，类型不混装）。
	 */
	public const GROUP = 'wink_gatekeeper';

	/**
	 * 原子自增（可用时）：返回自增后计数值（含本次）；不可用/后端拒绝返回 false（调用方
	 * 落回 legacy 桶路）。
	 *
	 * 首击定 TTL：计数值恰为 1 的胜者以 set 写入同值+TTL（固定窗口起点）。竞窗：并发首击
	 * 下 set 可能覆盖一次并发自增（±1 计数，亚毫秒窗，限流帽语义无害——如实登记）。
	 *
	 * @param string $key 计数键（消费方自带前缀+HMAC 定键）。
	 * @param int    $ttl 窗口 TTL 秒（首击写入；窗口语义由调用方承载）。
	 * @return int|false 自增后计数（int）或不可用（false）。PHP 7.4 兼容——union 仅 docblock。
	 */
	public static function incr( string $key, int $ttl ) {
		if ( ! function_exists( 'wp_cache_incr' ) || ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) {
			return false; // 无持久对象缓存 → 原子路不可用（消费方 legacy 桶路零变化）。
		}
		$v = wp_cache_incr( $key, 1, self::GROUP );
		if ( false === $v ) {
			// 键缺席（首击）或后端异常：add 播种 0 后重试一次；add+get 双失败=后端异常。
			if ( ! wp_cache_add( $key, 0, self::GROUP, max( 1, $ttl ) ) ) {
				$probe = wp_cache_get( $key, self::GROUP );
				if ( null === $probe || false === $probe ) {
					return false;
				}
			}
			$v = wp_cache_incr( $key, 1, self::GROUP );
			if ( false === $v ) {
				return false;
			}
		}
		if ( ! is_numeric( $v ) ) {
			return false; // 数值形态守卫（Redis 跨进程标量回读形态同判——is_numeric 纪律）。
		}
		$n = (int) $v;
		if ( 1 === $n ) {
			wp_cache_set( $key, 1, self::GROUP, max( 1, $ttl ) ); // 首击定 TTL（值不变，无损竞窗见类注）。
		}
		return $n;
	}

	/**
	 * 原子层纯读（零副作用——读路径消费方如 LoginCounters::bucket_saturated 用；键缺席/
	 * 无持久对象缓存/非数值形态恒 null=调用方落回 legacy 读）。键在场即窗口内（TTL 到期
	 * 整键回收——legacy 桶内 exp 字段语义由缓存 TTL 同构承载）。
	 *
	 * @param string $key 计数键。
	 * @return int|null
	 */
	public static function read( string $key ): ?int {
		if ( ! function_exists( 'wp_cache_get' ) || ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) {
			return null;
		}
		$v = wp_cache_get( $key, self::GROUP );
		return is_numeric( $v ) ? (int) $v : null;
	}
}
