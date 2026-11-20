<?php
/**
 * LLM 消耗统计面（K2 口径——DESIGN-M5-UI §3.4，2026-11-14）。
 *
 * 与 Usage（按次单值，K1）互补：本面记录 token 原值双列（prompt/completion），per-day
 * per-caller 分桶；**解析成功后回写**（响应 usage 字段；解析失败不回写——诚实：失败调用
 * 只进 K1 不进 K2）。tokens 存原值、渲染层才折算（「读入约 X 万字 · 写出约 Y 万字」
 * ——口径可回溯不撒谎；折算系数 1 token≈1.6 汉字〔待核验 DeepSeek 官方说明〕）。
 *
 * caller 枚举（M5 定稿 §5.2-1/§2.5 双车道计量）：review（在线评论审核）/ url_audit_batch
 * （存量网址核查车道）/ url_audit_incr（新评论车道）——U4 建 review，U5 消费双车道。
 *
 * 滚动窗口：prune 保留最近 N 天（默认 7）——option 防膨胀（daily cron 顺带）。
 *
 * @package WinkGatekeeper\Stats
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（S2-F1 纵深加固——类文件直访零执行，守卫为纵深面）。
}

final class LlmUsage {

	/**
	 * option 名（K2 数据源；非 settings 树——运行态统计）。
	 */
	public const OPTION_NAME = 'wink_gatekeeper_llm_usage';

	/**
	 * caller 枚举（开放于语义面；闭合于消费方——新 caller 走 schema 文档分列，04 回写）。
	 */
	public const CALLER_REVIEW         = 'review';
	public const CALLER_URL_AUDIT_BATCH = 'url_audit_batch';
	public const CALLER_URL_AUDIT_INCR  = 'url_audit_incr';
	public const CALLERS               = array( self::CALLER_REVIEW, self::CALLER_URL_AUDIT_BATCH, self::CALLER_URL_AUDIT_INCR );

	/**
	 * token→汉字折算系数（K2 口径小字「估算值」；待核验 DeepSeek 官方说明）。
	 */
	public const TOKENS_TO_CHARS = 1.6;

	/**
	 * 滚动保留天数（prune 默认）。
	 */
	public const KEEP_DAYS = 7;

	/**
	 * @return array<string, array<string, array{calls: int, prompt_tokens: int, completion_tokens: int}>>
	 */
	public static function empty(): array {
		return array();
	}

	/**
	 * 当日键（gmdate UTC 纯函数——日界与站点时区的偏差对本统计面可接受：滚动窗口
	 * 语义下近似日界足够；K2 是估算展示面非计费真相）。
	 *
	 * @param int $ts 时间戳。
	 * @return string
	 */
	public static function day_key( int $ts ): string {
		return gmdate( 'Y-m-d', $ts );
	}

	/**
	 * 记一笔（caller 分桶累加；未知 caller 归入原样键——不撒谎纪律，渲染层跳过未定义键）。
	 *
	 * @param array<string, mixed> $usage             当前统计树。
	 * @param string               $caller            caller id。
	 * @param int                  $prompt_tokens     输入 token。
	 * @param int                  $completion_tokens 输出 token。
	 * @param int                  $ts                时间戳。
	 * @return array<string, mixed> 新树。
	 */
	public static function record( array $usage, string $caller, int $prompt_tokens, int $completion_tokens, int $ts ): array {
		$day   = self::day_key( $ts );
		$slot  = $usage[ $day ][ $caller ] ?? array( 'calls' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0 );
		$slot['calls']            = ( $slot['calls'] ?? 0 ) + 1;
		$slot['prompt_tokens']    = ( $slot['prompt_tokens'] ?? 0 ) + max( 0, $prompt_tokens );
		$slot['completion_tokens'] = ( $slot['completion_tokens'] ?? 0 ) + max( 0, $completion_tokens );
		$usage[ $day ][ $caller ] = $slot;
		return $usage;
	}

	/**
	 * 滚动窗口修剪（只留最近 $keep_days 个日键；以 $now 为锚）。
	 *
	 * @param array<string, mixed> $usage      当前树。
	 * @param int|null             $keep_days  保留天数（null=KEEP_DAYS）。
	 * @param int|null             $now        当前时间戳（null=now）。
	 * @return array<string, mixed>
	 */
	public static function prune( array $usage, ?int $keep_days = null, ?int $now = null ): array {
		$keep_days = $keep_days ?? self::KEEP_DAYS;
		$now       = $now ?? time();
		$cutoff    = self::day_key( $now - max( 1, $keep_days - 1 ) * 86400 ); // 含当日共 keep_days 天.
		$keys      = array_keys( $usage );
		sort( $keys );
		foreach ( $keys as $day ) {
			if ( $day < $cutoff ) {
				unset( $usage[ $day ] );
			}
		}
		return $usage;
	}

	/**
	 * token→汉字折算（渲染层专用——存储恒原值）。
	 *
	 * @param int $tokens token 数。
	 * @return int
	 */
	public static function to_chars( int $tokens ): int {
		return (int) round( $tokens * self::TOKENS_TO_CHARS );
	}
}
