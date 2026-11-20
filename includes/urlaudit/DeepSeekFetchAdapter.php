<?php
/**
 * DeepSeek 网页抓取适配器（M6 批 D·D-3——N1 抓取服务商化，2026-11-16）。
 *
 * 官方契约（api/网页抓取.md + guides/网页搜索与抓取.md，LetMESee 采集 2026-11-14）：
 *   POST {base}/v1/tools/fetch，Bearer；body {url(≤8192，http/https，禁用户名密码)}；
 *   200 → {title, content(markdown)}。计费 ¥5/1000 成功次（200 且有正文才计费——
 *   计量 url_audit_fetch 在响应侧成功计数，非传输脊按尝试前置）。
 *   错误码：400 参数错 / 401 Key 无效 / 402 余额不足 / 403 安全策略拒 / 404 无法获取
 *   正文 / 408 读超时 / 413 超限 / 429 QPS（账号 25+目标域名独立限速）/ 5xx。
 *
 * key/base 链=AI 判定凭证链（§0-5）：UrlAuditWorker::fetcher_credentials 统一解析
 * （fetcher.api_key 仅 deepseek 模式且非空时覆盖；base_url 跟随凭证链缺省官方地址），
 * 本类只收最终 api_key/base_url。
 *
 * 预算墙（§4 C-3）：timeout=min(剩余预算,12)——15s job 级预算墙内（与 UrlFetcher
 * TOTAL_BUDGET_SECONDS 同墙）；预算耗尽 → timeout 类零外呼。
 *
 * 传输脊：RemoteClient 唯一出口（https 强制/私网拒/redirection=0 照管 api.deepseek.com
 * 这一条外呼）——deepseek 模式本站零直访陌生 URL（SSRF 面在该模式退役，§4 C-5 注）。
 *
 * 失败语义（§4 C-4 映射表逐行；「一切非 200 恒归 inconclusive 族不定罪」§0-7 跨实现
 * 不变——映射目标类均被 UrlFetchPolicy::fetch_class_to_state 归 inconclusive）：
 *   200 且 content 非空 → ok（fetch_provider=deepseek，html_analysis=unavailable——
 *                          cloaking/meta-refresh/JS 跳转类 HTML 侦测面该模式不可得，
 *                          如实记 meta 不冒充无信号）
 *   200 且 content 空   → thin_content
 *   404 → not_found     403 → blocked_safety    402 → quota_exhausted（账号级——
 *                          worker 车道暂停黄条+U-3 ② 事件）
 *   408/504 → timeout   429 → rate_limited      413 → too_large
 *   其余 4xx → fetch_failed（401 含在内且标账号级）    5xx/连不上 → transport
 * （408/429/5xx 的可重试语义由传输脊既有重试纪律承担——retryable 类退避重试，
 *   adapter 不再叠加第二层重试循环，R12 多层重试叠乘红线。）
 *
 * @package WinkGatekeeper\UrlAudit
 */

declare( strict_types=1 );

namespace WinkGatekeeper\UrlAudit;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（S2-F1 纵深加固——类文件直访零执行，守卫为纵深面）。
}

use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Stats\Usage;

final class DeepSeekFetchAdapter {

	/**
	 * USAGE_KEY 与 Usage::KEY_URL_AUDIT_FETCH 同源（键位常量单一来源防漂移）。
	 */
	public const USAGE_KEY = Usage::KEY_URL_AUDIT_FETCH;

	/**
	 * 单 URL 总预算墙秒（与 UrlFetcher::TOTAL_BUDGET_SECONDS 同墙——job 级）。
	 */
	public const TOTAL_BUDGET_SECONDS = 15;

	/**
	 * 单请求超时上限秒（§4 C-3：timeout=min(剩余预算,12)）。
	 */
	public const TIMEOUT_CAP = 12;

	/**
	 * @var string API Key（AI 判定凭证链解析值）。
	 */
	private $api_key;

	/**
	 * @var string 接入地址（跟随凭证链；空=官方 https://api.deepseek.com）。
	 */
	private $base_url;

	/**
	 * @var RemoteClient 传输脊。
	 */
	private $client;

	/**
	 * @var callable fn(): float 时钟（预算墙锚）。
	 */
	private $clock;

	/**
	 * @param string       $api_key  API Key。
	 * @param string       $base_url 接入地址（尾斜杠内部归一；空=官方地址）。
	 * @param RemoteClient $client   传输脊。
	 * @param callable|null $clock   时钟。
	 */
	public function __construct( string $api_key, string $base_url, RemoteClient $client, ?callable $clock = null ) {
		$this->api_key  = $api_key;
		$this->base_url = $base_url;
		$this->client   = $client;
		$this->clock    = $clock ?? static fn(): float => microtime( true );
	}

	/**
	 * 是否已配置（key 链非空——未配置=worker 侧按 fetch_failed 收口零外呼）。
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->api_key;
	}

	/**
	 * 抓取（fetch 失败≠违规——一切非 200 映射类恒归 inconclusive 族，§0-7）。
	 *
	 * 签名与 UrlFetcher::fetch 同形（$robots_comply 接受但忽略——DeepSeek 基建侧
	 * 抓取无本站 robots 取证面，robots_state 恒 no_signal 如实记录）。
	 *
	 * @param string $url          原始 URL（≤8192 由 UrlNormalizer 上游归一保证）。
	 * @param bool   $robots_comply robots 遵守开关（该模式不消费——见 docblock）。
	 * @return array{
	 *   fetch_class: string, final_url: string, hop_hosts: string[], status: int,
	 *   content_type: string, title: string, og_title: string, meta_description: string,
	 *   meta_refresh: string, js_redirect: string, text: string, robots_state: string,
	 *   redirects: int, fetch_provider: string, html_analysis?: string, account_error?: bool
	 * } 与 UrlFetcher 同构 FetchOutcome。
	 */
	public function fetch( string $url, bool $robots_comply = false ): array {
		$started  = ( $this->clock )();
		$deadline = $started + self::TOTAL_BUDGET_SECONDS;

		$result = array(
			'fetch_class'      => 'conn_fail',
			'final_url'        => $url,
			'hop_hosts'        => array(),
			'status'           => 0,
			'content_type'     => '',
			'title'            => '',
			'og_title'         => '',
			'meta_description' => '',
			'meta_refresh'     => '',
			'js_redirect'      => '',
			'text'             => '',
			'robots_state'     => '',
			'redirects'        => 0,
			'fetch_provider'   => 'deepseek',
		);

		$normalized = UrlNormalizer::normalize( $url );
		if ( null === $normalized ) {
			return $result; // 归一失败=conn_fail（与 UrlFetcher 同口径）。
		}

		// 预算墙：job 级 deadline 每步前查（nw §1.1——超墙零外呼）。
		$remaining = $deadline - ( $this->clock )();
		if ( $remaining <= 0.5 ) {
			$result['fetch_class'] = 'timeout';
			return $result;
		}

		$endpoint = rtrim( '' !== $this->base_url ? $this->base_url : 'https://api.deepseek.com', '/' ) . '/v1/tools/fetch';
		$resp     = $this->client->post( $endpoint, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => (string) json_encode( array( 'url' => $normalized ), JSON_UNESCAPED_UNICODE ),
			'timeout' => (int) min( self::TIMEOUT_CAP, max( 1.0, $remaining ) ),
			// 计量不挂传输脊 usage_key（前置=按尝试计）——成功次在响应侧 bump（见下，
			// 官方「200 且有正文才计费」口径）。
		) );

		$status = $resp->status();
		$result['status'] = $status;

		if ( ! $resp->is_ok() ) {
			// 408 读超时：官方「可重试」档——传输脊重试纪律只覆盖 retryable（transport）
			// 类（5xx/429/status 0 已在脊内退避重试），408 是 semantic 不在脊内重试——
			// 此处预算墙内补重试恰一次（C-4 表「408 → timeout（预算墙内重试 1 次）」；
			// 与 UrlFetcher 5xx 单次整体重试同形，不叠加循环——R12 红线）。
			if ( 408 === $status ) {
				$remaining = $deadline - ( $this->clock )();
				if ( $remaining > 2.0 ) {
					$resp = $this->client->post( $endpoint, array(
						'headers' => array(
							'Authorization' => 'Bearer ' . $this->api_key,
							'Content-Type'  => 'application/json',
						),
						'body'    => (string) json_encode( array( 'url' => $normalized ), JSON_UNESCAPED_UNICODE ),
						'timeout' => (int) min( self::TIMEOUT_CAP, max( 1.0, $remaining ) ),
					) );
					$status = $resp->status();
					$result['status'] = $status;
				}
			}
			$result['fetch_class'] = self::map_http_class( $status, $resp->category() );
			if ( 401 === $status || 402 === $status ) {
				$result['account_error'] = true; // U-3 ② 账号级失败（非目标站业务类）——worker 事件面消费。
			}
			return $result;
		}

		$data    = $resp->data();
		$title   = is_array( $data ) ? (string) ( $data['title'] ?? '' ) : '';
		$content = is_array( $data ) ? (string) ( $data['content'] ?? '' ) : '';

		if ( '' === trim( $content ) ) {
			$result['fetch_class'] = 'thin_content'; // 200 空正文（官方不计费——零计量）。
			return $result;
		}

		// 成功次计量（官方计费口径：200 且有正文；失败/空正文不计——与账单一致）。
		Usage::bump_option( self::USAGE_KEY );

		$result['fetch_class']    = 'ok';
		$result['final_url']      = $normalized;
		$result['content_type']   = 'text/markdown'; // DeepSeek Fetch 返回 markdown 正文——如实记录非 HTML。
		$result['title']          = mb_substr( $title, 0, UrlFetchPolicy::TITLE_MAX_CHARS );
		$result['text']           = mb_substr( $content, 0, UrlFetchPolicy::TEXT_MAX_CHARS );
		$result['html_analysis']  = 'unavailable'; // cloaking/meta-refresh/JS 跳转侦测面该模式不可得（C-4 如实记）。
		return $result;
	}

	/**
	 * C-4 映射表逐行（DeepSeek 响应 → 现有失败分类学键；全部落 inconclusive 族）。
	 *
	 * @param int    $status   HTTP 状态（0=传输失败）。
	 * @param string $category RemoteResult 分类（semantic/transport/…——status 0 时辅助归 transport）。
	 * @return string 失败类键。
	 */
	private static function map_http_class( int $status, string $category ): string {
		if ( 0 === $status ) {
			return 'transport'; // 连不上 api.deepseek.com/超时（WP_Error 形态）。
		}
		if ( 404 === $status ) {
			return 'not_found';
		}
		if ( 403 === $status ) {
			return 'blocked_safety';
		}
		if ( 402 === $status ) {
			return 'quota_exhausted';
		}
		if ( 408 === $status || 504 === $status ) {
			return 'timeout';
		}
		if ( 429 === $status ) {
			return 'rate_limited';
		}
		if ( 413 === $status ) {
			return 'too_large';
		}
		if ( $status >= 500 ) {
			return 'transport';
		}
		if ( $status >= 400 ) {
			return 'fetch_failed'; // 其余 4xx（401 Key 无效含在内——account_error 标记补账号级语义）。
		}
		return 'transport';
	}
}
