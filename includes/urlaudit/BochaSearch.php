<?php
/**
 * 博查网页搜索交叉信号源（M5 定稿 §5.2-1——可选开关，按次计费独立计量 K3，2026-11-14 U5 批）。
 *
 * 价值：fetch 失败类（inconclusive）时博查搜索 snippet/siteName 是**第三方视角的站点
 * 性质证据**（博彩/诈骗类域名在搜索结果中特征显著），可提供判定依据降低 inconclusive
 * 占比；结果与 fetch 证据并列喂 LLM（UrlJudge::build_messages bocha_signal 段）。
 *
 * 端点：POST https://api.bocha.cn/v1/web-search（Bearer；兼容 Bing 格式
 * name/url/snippet/summary/siteName——API 材料账）。计量=Usage 新键 bocha_search
 * （按次含失败——K3 口径）。
 *
 * @package WinkGatekeeper\UrlAudit
 */

declare( strict_types=1 );

namespace WinkGatekeeper\UrlAudit;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（S2-F1 纵深加固——类文件直访零执行，守卫为纵深面）。
}

use WinkGatekeeper\Http\RemoteClient;

final class BochaSearch implements UrlSearchProvider {

	public const ENDPOINT  = 'https://api.bocha.cn/v1/web-search';
	public const USAGE_KEY = 'bocha_search'; // K3 独立计量键（M5 定稿 §5.2-1）.

	/**
	 * @var string API Key。
	 */
	private $api_key;

	/**
	 * @var RemoteClient 传输脊。
	 */
	private $client;

	/**
	 * @param string       $api_key 博查 API Key。
	 * @param RemoteClient $client  传输脊。
	 */
	public function __construct( string $api_key, RemoteClient $client ) {
		$this->api_key = $api_key;
		$this->client  = $client;
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
	 * 站点性质搜索（query=域名/site:URL）。
	 *
	 * @param string $host   域名。
	 * @param int    $timeout 超时秒。
	 * @return array{snippets: string[], siteName: string}|null null=调用失败/未配置（零信号——
	 *                                              博查是辅助信号源非依赖层）。
	 */
	public function search_site( string $host, int $timeout = 5 ): ?array {
		if ( ! $this->is_configured() || '' === $host ) {
			return null;
		}
		$payload = array(
			'query'  => $host,
			'summary' => true,
			'count'  => 5,
		);
		$result = $this->client->post( self::ENDPOINT, array(
			'headers'   => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
			'body'      => (string) json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			'timeout'   => $timeout,
			'usage_key' => self::USAGE_KEY, // K3：按次含失败.
		) );
		if ( ! $result->is_ok() ) {
			return null;
		}
		$data = $result->data();
		$web  = $data['data']['webPages']['value'] ?? ( $data['webPages']['value'] ?? null );
		if ( ! is_array( $web ) ) {
			return null;
		}
		$snippets = array();
		$site     = '';
		foreach ( array_slice( $web, 0, 5 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$snippet = (string) ( $item['snippet'] ?? $item['summary'] ?? '' );
			if ( '' !== $snippet ) {
				$snippets[] = mb_substr( $snippet, 0, 200 );
			}
			if ( '' === $site && isset( $item['siteName'] ) && is_string( $item['siteName'] ) ) {
				$site = mb_substr( $item['siteName'], 0, 100 );
			}
		}
		return array( 'snippets' => $snippets, 'siteName' => $site );
	}
}
