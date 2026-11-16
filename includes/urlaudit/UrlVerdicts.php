<?php
/**
 * URL 判定缓存语义与处置矩阵（nw §4.3/§5.1/§5.2/§5.3，2026-11-14 U5 批）。
 *
 * 处置红线（三原则之「只放大严」）：pass 仍零动作（不自动放行不自动定罪）；
 * inconclusive→待审（统一人工判定，2026-11-16 用户拍板——原「零自动化动作」随
 * 【M6·批E·§4-add】翻转为「不定罪但悬置待审」：违规站对一切抓取端普遍无正文可给，
 * inconclusive 是违规站常态结局，放行不可接受；恒不定罪 §0-7 不变，hold 可手动批准
 * 恢复）；自动定罪门 0.95 独立于 min_confidence 0.8（两档阈值语义分开别共享旋钮）；
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
	 * 增量处置矩阵（nw §5.1；【M6·批E·§4-add】2026-11-16 用户拍板——「没看清」统一
	 * 人工判定：inconclusive 与 review 一律→hold（待审=统一人工判定入口，三档 profile
	 * 皆然，wp_set_comment_status 可逆终态可手动批准恢复）；pass 零动作、reject 分支
	 * 形态不动（conservative 只标记/balanced≥0.95 spam/aggressive≥0.8 spam）。
	 *
	 * @param string $verdict           pass|reject|review|inconclusive。
	 * @param float  $confidence        置信度（inconclusive 传 0）。
	 * @param string $profile           conservative|balanced|aggressive。
	 * @param bool   $injection_attempt 注入尝试命中（自动化保守化保险丝）。
	 * @return array{action: string, auto_url_reject: bool, note: string}
	 */
	public static function disposal( string $verdict, float $confidence, string $profile, bool $injection_attempt ): array {
		$note = '';

		// 【M6·批E·§4-add】没看清/存疑→hold（三档统一；恒不定罪 §0-7 不变——
		// hold=悬置待审人工判定，可手动批准恢复；误伤面（正常站抓取失败）用户明示接受，
		// inconclusive 缓存 TTL 24h+「重新核查此站」可复验）。
		if ( 'inconclusive' === $verdict || 'review' === $verdict ) {
			return array( 'action' => 'hold', 'auto_url_reject' => false, 'note' => '没看清/存疑——进待审等你判定（可手动恢复）' );
		}

		// 保守档：reject 不自动定罪（nw §5.2 默认——只出报告与标记，处置人工）。
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

		// reject：自动定罪门（均衡=0.95 / 激进=0.8）。
		$gate = 'balanced' === $profile ? self::AUTO_CONVICT_CONFIDENCE : 0.8;
		if ( $confidence >= $gate ) {
			return array( 'action' => 'spam', 'auto_url_reject' => true, 'note' => '自动标垃圾（可一键撤销）' );
		}
		return array( 'action' => 'keep', 'auto_url_reject' => false, 'note' => 'reject 中置信——保持待审附证据' );
	}

	/**
	 * 存量批量处置（【M6·批C·C-2】用户 2026-11-16 拍板——原 nw §5.3「仅标记」退役）：
	 * reject→垃圾箱（spam；U4 判定管线 conf≥min_confidence 才出 reject=已含置信门）、
	 * review 与 inconclusive（没看清）→待审（hold）、pass→不动；全部 wp_set_comment_status
	 * 可逆终态面（垃圾箱可一键恢复、待审可批准），处置结果落 meta disposed_to 溯源。
	 *
	 * @param string $verdict    判定键。
	 * @param float  $confidence 置信度。
	 * @return array{action: string, note: string}
	 */
	public static function disposal_existing( string $verdict, float $confidence ): array {
		if ( 'reject' === $verdict ) {
			return array( 'action' => 'spam', 'note' => '判违规——进垃圾箱（可手动恢复）' );
		}
		if ( 'inconclusive' === $verdict || 'review' === $verdict ) {
			return array( 'action' => 'hold', 'note' => '没看清/存疑——进待审（可手动恢复）' );
		}
		return array( 'action' => 'none', 'note' => '没问题——不动（可选已核无问题计数）' );
	}
}
