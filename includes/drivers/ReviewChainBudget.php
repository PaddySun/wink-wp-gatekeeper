<?php
/**
 * ReviewChainBudget——云审链预算墙帮助件（【P1-7·A2-F1】独立审计修复批 2 性能批）。
 *
 * 病灶：评论 POST 最坏同步延迟 ≈55.2s——chain_budget（默认 30s）是「外呼开始时刻」墙
 * （每驱动调用前查剩余）而非总时长墙：慢驱动/网络黑洞下单次外呼可远超剩余预算，占满
 * PHP-FPM worker、超 FPM max_execution_time、诱发用户重复提交（A2-F1）。
 *
 * 修复（audit 修复方向原文「剩余预算作下次调用超时上限，DeepSeekFetchAdapter 先例同法」）：
 * CommentGate 云审循环把墙边界以 meta 便车（META_KEY='chain_deadline'）随 check() 下发
 * （ReviewDriverInterface meta=开放键集按需消费——非厂商线材）；四云审驱动在 check() 入口
 * 提取并（a）耗尽=error 零外呼（与闸侧墙语义一致）；（b）未耗尽=本次传输超时钳到剩余
 * 预算。禁预算（chain_budget=0）不注入=现行行为零变化；无 deadline 上下文的调用（探针/
 * 诊断/直调）钳制恒等=现行行为零变化。
 *
 * 残余登记：RemoteClient 脊内重试（3 尝试×退避）沿用钳后超时——单驱动最坏 ≈(尝试数-1)×
 * 钳值的越墙过冲，如实登记（总时长墙的完全闭合需传输脊 deadline 语义，触及九红线面，
 * 本批不做、登记候选）。
 *
 * @package WinkGatekeeper\Drivers
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Drivers;

final class ReviewChainBudget {

	/**
	 * meta 便车键（ReviewDriverInterface 开放键集扩展——各驱动按需消费，非厂商线材）。
	 */
	public const META_KEY = 'chain_deadline';

	/**
	 * 从 check meta 提取墙边界（缺席/非数值形态=null=无预算上下文）。
	 *
	 * @param array<string, mixed> $meta 开放键集。
	 * @return int|null 墙边界 unix 秒或 null。
	 */
	public static function from_meta( array $meta ): ?int {
		if ( isset( $meta[ self::META_KEY ] ) && is_numeric( $meta[ self::META_KEY ] ) ) {
			return (int) $meta[ self::META_KEY ];
		}
		return null;
	}

	/**
	 * 预算耗尽判定（null=无上下文恒未耗尽——现行行为零变化锚）。
	 *
	 * @param int|null $deadline 墙边界 unix 秒。
	 * @return bool
	 */
	public static function expired( ?int $deadline ): bool {
		return null !== $deadline && time() >= $deadline;
	}

	/**
	 * 传输超时钳制：剩余预算作上限（下限 1s）；null=无上下文恒等返回（max(1,t) 幂等——
	 * 配置构造面已保证 ≥1）。
	 *
	 * @param int      $timeout  配置超时秒。
	 * @param int|null $deadline 墙边界 unix 秒。
	 * @return int
	 */
	public static function clamp( int $timeout, ?int $deadline ): int {
		if ( null === $deadline ) {
			return max( 1, $timeout );
		}
		$remaining = $deadline - time();
		if ( $remaining < 1 ) {
			return 1; // 已过期形态钳到下限（外呼由 expired 门先行拒绝——本值仅兜底）。
		}
		return (int) min( max( 1, $timeout ), $remaining );
	}
}
