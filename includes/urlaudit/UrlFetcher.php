<?php
/**
 * URL fetcher L2 能力域（nw §1——RemoteClient 之上不新开出口，2026-11-14 U5 批）。
 *
 * 总预算墙（nw §1.1 最重要一节）：单 URL 全程（含全部重定向跳+重试）≤15s **job 级**——
 * deadline 墙每步操作前查剩余预算；重定向手动逐跳循环（RemoteClient 红线④
 * redirection=0 天然不跟随）≤5 跳，**每跳重复完整校验**（SSRF 防线：RemoteClient::check_url
 * 对每次 get() 独立校验 scheme/私网/元数据——逐跳循环即等价物）；重定向不带认证头
 * （本 fetcher 恒无凭据）。跨协议降级记录；meta refresh 空壳页不 fetch 目标（防烧预算）
 * 记 cloaking_suspect。
 *
 * DNS rebinding TOCTOU 窗口显式接受（nw §1.8-4 论证：GET 无凭据无渲染、响应只做文本
 * 抽取——残余风险上限=攻击者间接探测内网 HTTP 端口可达性；本 fetcher 恒无内网例外）。
 *
 * robots：默认不遵守但状态记入元数据（nw §1.5）；站长开启 robots_comply 时 Disallow
 * 即 robots_disallow 类（404/NXDOMAIN 放行、5xx 不 fail-closed）。
 *
 * @package WinkGatekeeper\UrlAudit
 */

declare( strict_types=1 );

namespace WinkGatekeeper\UrlAudit;

use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Http\RemoteResult;

final class UrlFetcher {

	/**
	 * 单 URL 总预算墙秒（nw §1.2 建议表——覆盖 P95 慢站；垃圾站不值得等）。
	 */
	public const TOTAL_BUDGET_SECONDS = 15;

	/**
	 * 重定向最大跳数（行业惯例；跳数本身是信号——≥3 跳记入元数据）。
	 */
	public const MAX_HOPS = 5;

	/**
	 * 每跳请求超时秒（connect 超时 WP_Http 不分离 [待核验]——取 min(剩余预算,5) 兜底）。
	 */
	public const HOP_TIMEOUT_CAP = 5;

	/**
	 * 原始响应体上限字节（解压后 3MB——防解压炸弹；RemoteClient decompress=true）。
	 */
	public const BODY_MAX_BYTES = 3145728;

	/**
	 * @var RemoteClient 传输脊。
	 */
	private $client;

	/**
	 * @var callable fn(): float 时钟（预算墙锚）。
	 */
	private $clock;

	/**
	 * @param RemoteClient  $client 传输脊。
	 * @param callable|null $clock  时钟。
	 */
	public function __construct( RemoteClient $client, ?callable $clock = null ) {
		$this->client = $client;
		$this->clock  = $clock ?? static fn(): float => microtime( true );
	}

	/**
	 * 抓取+抽取（fetch 失败≠违规——所有失败类落 fetch_class 交 UrlFetchPolicy 映射）。
	 *
	 * @param string $url          原始 URL。
	 * @param bool   $robots_comply robots 遵守开关（默认关）。
	 * @return array{
	 *   fetch_class: string, final_url: string, hop_hosts: string[], status: int,
	 *   content_type: string, title: string, og_title: string, meta_description: string,
	 *   meta_refresh: string, text: string, robots_state: string, redirects: int
	 * }
	 */
	public function fetch( string $url, bool $robots_comply = false ): array {
		$started = ( $this->clock )();
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
		);

		$current = UrlNormalizer::normalize( $url );
		if ( null === $current ) {
			$result['fetch_class'] = 'conn_fail';
			return $result;
		}

		for ( $hop = 0; $hop <= self::MAX_HOPS; $hop++ ) {
			// 预算墙：job 级 deadline 每步前查（nw §1.1——每跳各自计时=事故形态）。
			$remaining = $deadline - ( $this->clock )();
			if ( $remaining <= 0.5 ) {
				$result['fetch_class'] = 'timeout';
				return $result;
			}

			$host = (string) ( parse_url( $current, PHP_URL_HOST ) ?? '' );
			$result['hop_hosts'][] = $host;

			// robots 取证（非遵守模式仅记录状态为元数据弱特征）。
			if ( 0 === $hop && $robots_comply ) {
				$robots = $this->fetch_robots_disallow( $current, $deadline );
				$result['robots_state'] = $robots;
				if ( 'disallow' === $robots ) {
					$result['fetch_class'] = 'robots_disallow';
					return $result;
				}
			}

			$resp = $this->client->get(
				$current,
				array(
					'timeout' => (int) min( self::HOP_TIMEOUT_CAP, max( 1.0, $remaining ) ),
					'headers' => array(
						'Accept'          => 'text/html,application/xhtml+xml',
						'Accept-Encoding' => 'gzip, deflate',
						// 诚实 UA 不伪装浏览器（nw §1.5）；**纯 ASCII**——HTTP header 值禁非
						// ASCII（B 机实测抓出：中文 UA→WP_Error→恒 conn_fail）。
						'User-Agent'      => 'WinkGatekeeperAudit/1.0 (site-audit; +https://wordpress.org/plugins/wink-wp-gatekeeper)',
					),
				)
			);

			/* 【B 机实测抓出·2026-11-14】成功判定语义：RemoteClient classify 按 API 端点设计
			 * （2xx 非 JSON body=parse 类）——HTML 页面 200 落 parse/contract 是**预期形态**
			 * 非失败。fetcher 成功=2xx 且 category ∉ {transport, semantic}；4xx=semantic
			 * （404/403 分类学细分类目）；5xx/429/连不上=transport。 */
			$category = $resp->category();
			if ( 'transport' === $category || 'semantic' === $category ) {
				$status_code = $resp->status();
				if ( 'transport' === $category && $status_code >= 500 ) {
					// 5xx：重试恰一次（retryable 类；退避由传输脊已做，此处仅一次整体重试）。
					$remaining = $deadline - ( $this->clock )();
					if ( $remaining > 2.0 ) {
						$resp = $this->client->get( $current, array(
							'timeout' => (int) min( self::HOP_TIMEOUT_CAP, max( 1.0, $remaining ) ),
						) );
						$category   = $resp->category();
						$status_code = $resp->status();
					}
				}
				if ( 'transport' === $category ) {
					$result['status'] = $status_code;
					$result['fetch_class'] = 'timeout';
					return $result;
				}
				// semantic（4xx）——NXDOMAIN 细分（nw §1.8-7：缓存 TTL 7d/24h 分开）。
				$message = strtolower( $resp->message() );
				if ( false !== strpos( $message, 'resolve' ) || false !== strpos( $message, 'getaddrinfo' )
					|| false !== strpos( $message, 'name or service not known' ) || false !== strpos( $message, 'nxdomain' ) ) {
					$result['status'] = $status_code;
					$result['fetch_class'] = 'dns_nxdomain';
					return $result;
				}
				$result['status'] = $status_code;
				$result['fetch_class'] = UrlFetchPolicy::classify_http( $status_code, '', 'semantic' );
				return $result;
			}

			$status = $resp->status();
			$result['status'] = $status;
			$headers          = self::normalize_headers( $resp->headers() );
			$content_type     = '';
			foreach ( array( 'content-type', 'Content-Type' ) as $hk ) {
				if ( isset( $headers[ $hk ] ) && is_string( $headers[ $hk ] ) ) {
					$content_type = $headers[ $hk ];
					break;
				}
			}
			$result['content_type'] = $content_type;

			// 重定向逐跳（redirection=0 底层不跟随——Location 手动循环）。
			if ( $status >= 300 && $status < 400 ) {
				$location = '';
				foreach ( array( 'location', 'Location' ) as $hk ) {
					if ( isset( $headers[ $hk ] ) && is_string( $headers[ $hk ] ) ) {
						$location = trim( $headers[ $hk ] );
						break;
					}
				}
				if ( '' === $location ) {
					$result['fetch_class'] = 'conn_fail';
					return $result;
				}
				$next = self::absolutize( $location, $current );
				if ( null === $next ) {
					// 非 http/https scheme（gopher/file/data/javascript）立即拒绝（nw §1.8-5）。
					$result['fetch_class'] = 'cloaking_suspect';
					$result['meta_refresh'] = $location;
					return $result;
				}
				$result['final_url'] = $next;
				++$result['redirects'];
				$current = $next;
				continue;
			}

			// 响应体上限（解压后）。
			$body = $resp->body();
			if ( strlen( $body ) > self::BODY_MAX_BYTES ) {
				$result['fetch_class'] = 'oversized';
				return $result;
			}

			// 非 HTML。
			if ( '' !== $content_type && 0 !== strpos( strtolower( $content_type ), 'text/html' ) && 0 !== strpos( strtolower( $content_type ), 'application/xhtml' ) ) {
				$result['fetch_class'] = 'non_html';
				return $result;
			}

			// 抽取（元信号+剥离名单+正文 ≤4000）。
			$extracted = UrlFetchPolicy::extract( $body );
			$result['title'] = $extracted['title'];
			$result['og_title'] = $extracted['og_title'];
			$result['meta_description'] = $extracted['meta_description'];
			$result['meta_refresh'] = $extracted['meta_refresh'];
			$result['js_redirect'] = $extracted['js_redirect']; // 空壳跳转证据（喂 LLM 元数据）.
			$result['text'] = $extracted['text'];
			$result['final_url'] = $current;

			// 空壳跳转页（meta refresh + JS location.replace/href——HTTP 3xx 盲区，nw §1.3；
			// 不 fetch 目标防当跳板烧预算；B 机实测百度 https→http 降级跳转页实证）。
			if ( '' !== $extracted['meta_refresh'] || '' !== $extracted['js_redirect'] ) {
				$result['fetch_class'] = 'cloaking_suspect';
				return $result;
			}

			// 乱码检查（LLM 之前——白烧防线；空文本走 thin_content 不归乱码）。
			if ( UrlFetchPolicy::is_decode_garbage( $extracted['text'] ) ) {
				$result['fetch_class'] = 'decode_garbage';
				return $result;
			}

			// thin_content（空壳/挑战页高频形态——review 倾向类）。
			if ( UrlFetchPolicy::is_thin_content( $extracted['text'], strlen( $body ) ) ) {
				$result['fetch_class'] = 'thin_content';
				return $result;
			}

			// 成功可判定。
			$result['fetch_class'] = 'ok';
			return $result;
		}

		// 跳数耗尽（≥5 跳跳转链=垃圾/门禁页强特征）。
		$result['fetch_class'] = 'cloaking_suspect';
		return $result;
	}

	/**
	 * headers 归一（wp_remote 形态二象性：数组或 WpOrg\Requests\Utility\CaseInsensitiveDictionary
	 * 对象——B 机实测 ctype 取空导致 image 落 thin_content 的教训）。
	 *
	 * @param mixed $headers 原始 headers。
	 * @return array<string, string>
	 */
	private static function normalize_headers( $headers ): array {
		if ( is_array( $headers ) ) {
			return $headers;
		}
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$all = $headers->getAll();
			$out = array();
			foreach ( is_array( $all ) ? $all : array() as $hkey => $hval ) {
				if ( is_int( $hkey ) && is_array( $hval ) && isset( $hval['name'], $hval['value'] ) ) {
					$out[ strtolower( (string) $hval['name'] ) ] = (string) $hval['value']; // name/value 条目形态.
				} elseif ( is_string( $hkey ) ) {
					$out[ strtolower( $hkey ) ] = is_scalar( $hval ) ? (string) $hval : ''; // 平表形态（WP 7.1 实测）.
				}
			}
			return $out;
		}
		return array();
	}

	/**
	 * robots.txt Disallow 探测（遵守模式；404/连接失败=无信号放行——索引爬虫 fail-closed
	 * 口径在此反取，nw §1.5）。
	 *
	 * @param string $url      目标 URL。
	 * @param float  $deadline 预算墙。
	 * @return string ok|disallow|no_signal
	 */
	private function fetch_robots_disallow( string $url, float $deadline ): string {
		$parsed = parse_url( $url );
		if ( ! is_array( $parsed ) || ! isset( $parsed['scheme'], $parsed['host'] ) ) {
			return 'no_signal';
		}
		$remaining = $deadline - ( $this->clock )();
		if ( $remaining <= 2.0 ) {
			return 'no_signal';
		}
		$robots_url = strtolower( (string) $parsed['scheme'] ) . '://' . strtolower( (string) $parsed['host'] ) . '/robots.txt';
		$resp       = $this->client->get( $robots_url, array( 'timeout' => (int) min( 3, max( 1.0, $remaining ) ) ) );
		if ( ! $resp->is_ok() || 200 !== $resp->status() ) {
			return 'no_signal'; // 404/5xx/连接失败=无信号（不 fail-closed）。
		}
		$body = strtolower( $resp->body() );
		if ( '' !== $body && false !== strpos( $body, 'user-agent' ) ) {
			// 粗粒度判定：全局 Disallow: /（全站禁爬）——分组语义细化留 L 级（首版够用）。
			if ( preg_match( '/user-agent:\s*\*\s*(?:[\r\n]+[^\w])*[\r\n]+disallow:\s*\/\s*(?:[\r\n]|$)/', $body ) ) {
				return 'disallow';
			}
		}
		return 'ok';
	}

	/**
	 * Location 相对地址绝对化 + scheme 白名单（非法 scheme=null=拒绝）。
	 *
	 * @param string $location Location 头值。
	 * @param string $base     当前 URL。
	 * @return string|null
	 */
	public static function absolutize( string $location, string $base ): ?string {
		$location = trim( $location );
		if ( '' === $location ) {
			return null;
		}
		// 相对路径解析（容错式——判定目标不需要 RFC 全量）。
		$parts = parse_url( $base );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return null;
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

		if ( preg_match( '#^(https?)://#i', $location ) ) {
			$p = parse_url( $location );
			if ( ! is_array( $p ) || ! isset( $p['scheme'], $p['host'] ) || ! in_array( strtolower( (string) $p['scheme'] ), array( 'http', 'https' ), true ) ) {
				return null; // 非 http/https scheme 立即拒绝.
			}
			return UrlNormalizer::normalize( $location );
		}
		if ( 0 === strpos( $location, '//' ) ) {
			return UrlNormalizer::normalize( $scheme . ':' . $location );
		}
		if ( 0 === strpos( $location, '/' ) ) {
			return UrlNormalizer::normalize( $scheme . '://' . $host . $port . $location );
		}
		// 同目录相对（首版：拼路径段——绝对化容错）。
		$base_path = (string) ( $parts['path'] ?? '/' );
		$dir       = substr( $base_path, 0, (int) strrpos( $base_path, '/' ) + 1 );
		return UrlNormalizer::normalize( $scheme . '://' . $host . $port . $dir . $location );
	}
}
