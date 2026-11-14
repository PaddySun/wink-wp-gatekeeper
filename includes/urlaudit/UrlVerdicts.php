<?php
/**
 * URL 判定缓存语义与处置矩阵（nw §4.3/§5.1/§5.2/§5.3，2026-11-14 U5 批）。
 *
 * 处置红线（三原则之「只放大严」）：URL pass 不单独产生任何自动化动作；inconclusive
 * 权重恒为零；自动定罪门 0.95 独立于 min_confidence 0.8（两档阈值语义分开别共享旋钮）；
 * injection_attempt 命中时自动放行保险丝失效（检测面保守化——不改变 reject 处置）。
 *
 * @package WinkGatekeeper\UrlAudit
 */

declare( strict_types=1 );

namespace WinkGatekeeper\UrlAudit;

final class UrlVerdicts {

	/**
	 * 自动定罪门（nw §5.2：不可逆动作显著高于人审 queue 阈值 0.8；D8 裁决不露旋钮）。
	 */
	public const AUTO_CONVICT_CONFIDENCE = 0.95;

	/**
	 * 缓存 TTL 秒（nw §4.3：pass 14d / reject 90d / review·inconclusive 24h / NXDOMAIN 7d）。
	 *
	 * @param string $verdict 判定键。
	 * @return int
	 */
	public static function cache_ttl( string $verdict ): int {
		return match ( $verdict ) {
			'pass'          => 14 * 86400,
			'reject'        => 90 * 86400,
			'dns_nxdomain'  => 7 * 86400, // 死域名短期复活概率低但不永久（nw §1.8-7）.
			default         => 86400,     // review/inconclusive/其余失败类=24h（站点会恢复）.
		};
	}

	/**
	 * 增量处置矩阵（评论已在待审；nw §5.1）。
	 *
	 * @param string $verdict           pass|reject|review|inconclusive。
	 * @param float  $confidence        置信度（inconclusive 传 0）。
	 * @param string $profile           conservative|balanced|aggressive。
	 * @param bool   $injection_attempt 注入尝试命中（自动化保守化保险丝）。
	 * @return array{action: string, auto_url_reject: bool, note: string}
	 */
	public static function disposal( string $verdict, float $confidence, string $profile, bool $injection_attempt ): array {
		$note = '';

		// 保守档：只出报告与标记，一切处置人工（nw §5.2 默认）。
		if ( 'conservative' === $profile ) {
			return array( 'action' => 'keep', 'auto_url_reject' => false, 'note' => '保守档：仅标记' );
		}

		// URL pass 恒不自动放行（三原则③；injection_attempt 保险丝——nw §3.4）。
		if ( 'pass' === $verdict ) {
			if ( $injection_attempt ) {
				return array( 'action' => 'keep', 'auto_url_reject' => false, 'note' => '注入尝试命中——自动放行保险丝失效' );
			}
			return array( 'action' => 'keep', 'auto_url_reject' => false, 'note' => 'URL 已核无问题（不改变评论状态）' );
		}

		// inconclusive 权重恒为零（nw §1.7 配套）。
		if ( 'inconclusive' === $verdict || 'review' === $verdict ) {
			return array( 'action' => 'keep', 'auto_url_reject' => false, 'note' => '未核实——保持待审附原因' );
		}

		// reject：自动定罪门（均衡=0.95 / 激进=0.8）。
		$gate = 'balanced' === $profile ? self::AUTO_CONVICT_CONFIDENCE : 0.8;
		if ( $confidence >= $gate ) {
			return array( 'action' => 'spam', 'auto_url_reject' => true, 'note' => '自动标垃圾（可一键撤销）' );
		}
		return array( 'action' => 'keep', 'auto_url_reject' => false, 'note' => 'reject 中置信——保持待审附证据' );
	}

	/**
	 * 存量批量处置（已发布评论——nw §5.3：默认仅标记，机器处置信任代价高）。
	 *
	 * @param string $verdict   判定键。
	 * @param float  $confidence 置信度。
	 * @return array{action: string, note: string}
	 */
	public static function disposal_existing( string $verdict, float $confidence ): array {
		if ( 'reject' === $verdict ) {
			return array( 'action' => 'flag', 'note' => '仅标记+建议处理（可批量操作）' );
		}
		if ( 'inconclusive' === $verdict ) {
			return array( 'action' => 'none', 'note' => '计入「未能核实」批量汇总' );
		}
		return array( 'action' => 'none', 'note' => '无动作（可选已核无问题计数）' );
	}
}
