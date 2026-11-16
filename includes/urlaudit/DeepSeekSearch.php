<?php
/**
 * DeepSeek 网页搜索源（M6 批 B·N2——B-2 新实现，2026-11-16）。
 *
 * 官方契约（api/网页搜索.md + guides/网页搜索与抓取.md，LetMESee 采集 2026-11-14）：
 *   POST {base}/v1/tools/search，Bearer；body {query(1-128 字), count(1-50),
 *   need_content(默认 false)}；200 → {results[{rerank_score,title,url,snippet,summary,
 *   content,site_name,site_icon,date,authority}]}。snippet=短摘要/summary=长摘要——
 *   need_content=false 时 content 恒空，用 snippet+summary 足够（响应体小）。
 *
 * key 链=AI 判定凭证链（§0-5）：provider custom→其 api_key/base_url；inherit→Tab③
 * DeepSeek 主凭证（分派点 UrlAuditWorker::default_judge_factory 经 provider_config
 * 统一解析，本类只收最终 api_key/base_url）。
 *
 * 计量：deepseek_search=Usage::KEY_DEEPSEEK_SEARCH（成功次）——官方计费口径
 * 「200 且 results 非空才计费」，故计量在响应侧（成功且产出信号时 Usage::bump_option）
 * 而非传输脊前置（按尝试计≠账单口径）；bocha_search K3（按次含失败）不动。
 *
 * 失败语义：非 200/JSON 异常/空 results → null（信号缺失降级——搜索失败≠违规，
 * §0-7 同族；引擎照常判定）。
 *
 * @package WinkGatekeeper\UrlAudit
 */

declare( strict_types=1 );

namespace WinkGatekeeper\UrlAudit;

use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Stats\Usage;

final class DeepSeekSearch implements UrlSearchProvider {

	/**
	 * @var string USAGE_KEY 与 Usage::KEY_DEEPSEEK_SEARCH 同源（键位常量单一来源防漂移）。
	 */
	public const USAGE_KEY = Usage::KEY_DEEPSEEK_SEARCH;

	/**
	 * @var string API Key。
	 */
	private $api_key;

	/**
	 * @var string 接入地址（跟随凭证链，缺省官方 https://api.deepseek.com）。
	 */
	private $base_url;

	/**
	 * @var RemoteClient 传输脊。
	 */
	private $client;

	/**
	 * @param string       $api_key  API Key（AI 判定凭证链解析值）。
	 * @param string       $base_url 接入地址（同上；尾斜杠内部归一）。
	 * @param RemoteClient $client   传输脊。
	 */
	public function __construct( string $api_key, string $base_url, RemoteClient $client ) {
		$this->api_key  = $api_key;
		$this->base_url = $base_url;
		$this->client   = $client;
	}

	/**
	 * 是否已配置。
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->api_key;
	}

	/**
	 * 站点性质搜索（query=域名/host）。
	 *
	 * @param string $host    域名。
	 * @param int    $timeout 超时秒。
	 * @return array{snippets: string[], siteName: string}|null null=失败/未配置/无结果。
	 */
	public function search_site( string $host, int $timeout = 5 ): ?array {
		if ( ! $this->is_configured() || '' === $host ) {
			return null;
		}
		$endpoint = rtrim( '' !== $this->base_url ? $this->base_url : 'https://api.deepseek.com', '/' ) . '/v1/tools/search';
		$result   = $this->client->post( $endpoint, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
			'body'      => (string) json_encode( array(
				'query'        => mb_substr( $host, 0, 128 ),
				'count'        => 5,
				'need_content' => false,
			), JSON_UNESCAPED_UNICODE ),
			'timeout'   => $timeout,
			// 计量不挂传输脊 usage_key（前置=按尝试计）——成功次在响应侧 bump（见下）。
		) );
		if ( ! $result->is_ok() ) {
			return null;
		}
		$data    = $result->data();
		$results = is_array( $data ) ? ( $data['results'] ?? null ) : null;
		if ( ! is_array( $results ) || array() === $results ) {
			return null; // 无匹配结果（官方不计费）——零信号零计量。
		}
		$snippets = array();
		$site     = '';
		foreach ( array_slice( $results, 0, 5 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			// snippet 短摘要优先（前端展现形态），回落 summary 长摘要——与博查
			// snippet→summary 回落同构（消费侧 UrlJudge::build_messages 无感）。
			$snippet = (string) ( $item['snippet'] ?? $item['summary'] ?? '' );
			if ( '' !== $snippet ) {
				$snippets[] = mb_substr( $snippet, 0, 200 );
			}
			if ( '' === $site && isset( $item['site_name'] ) && is_string( $item['site_name'] ) ) {
				$site = mb_substr( $item['site_name'], 0, 100 );
			}
		}
		if ( array() === $snippets && '' === $site ) {
			return null;
		}
		// 成功次计量（官方计费口径：200 且 results 非空才计费——响应侧计数与账单一致）。
		Usage::bump_option( self::USAGE_KEY );
		return array( 'snippets' => $snippets, 'siteName' => $site );
	}
}
