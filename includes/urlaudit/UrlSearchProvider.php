<?php
/**
 * 网址核查搜索源接口（M6 批 B·N2——B-2：与 BochaSearch 现签名同构的抽象面，
 * 2026-11-16）。
 *
 * 消费方：UrlJudgeEngine（判定前「搜一搜」信号获取，fetch 失败类时降低 inconclusive
 * 占比——第三方视角站点性质证据并列喂 LLM）；分派点在 UrlAuditWorker::default_judge_factory
 * （按 url_audit.search_source ∈ bocha(默认)|deepseek 装配）。
 *
 * 契约：search_site 返回 {snippets, siteName}（与博查消费侧 UrlJudge::build_messages
 * bocha_signal 段对齐）；null=调用失败/未配置/无结果（零信号——搜索源是辅助信号源
 * 非依赖层，「搜索失败≠违规」§0-7 同族语义，引擎照常判定不阻塞不劣化）。
 *
 * @package WinkGatekeeper\UrlAudit
 */

declare( strict_types=1 );

namespace WinkGatekeeper\UrlAudit;

interface UrlSearchProvider {

	/**
	 * 是否已配置（key 链非空——未配置=引擎零信号直通）。
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * 站点性质搜索（query=域名/host）。
	 *
	 * @param string $host    域名。
	 * @param int    $timeout 超时秒。
	 * @return array{snippets: string[], siteName: string}|null null=失败/未配置/无结果。
	 */
	public function search_site( string $host, int $timeout = 5 ): ?array;
}
