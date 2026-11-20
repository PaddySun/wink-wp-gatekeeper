<?php
/**
 * URL fetch 失败分类学与 HTML 抽取纯逻辑（nw §1.4/§1.6/§1.7，2026-11-14 U5 批）。
 *
 * 总纲（nw §1.7）：fetch 侧所有失败类一律映射 inconclusive，唯一例外是它们本身作为
 * 特征喂 LLM；inconclusive 永不指向 spam 永不指向放行。唯 cloaking_suspect（空壳跳转页）
 * 落 review 倾向（不自动定罪，跳转目标域交 LLM）。
 *
 * @package WinkGatekeeper\UrlAudit
 */

declare( strict_types=1 );

namespace WinkGatekeeper\UrlAudit;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（S2-F1 纵深加固——类文件直访零执行，守卫为纵深面）。
}

final class UrlFetchPolicy {

	/**
	 * 正文抽取截断（nw §2.1 预算表——喂 LLM 的量）。
	 */
	public const TEXT_MAX_CHARS = 4000;
	public const TITLE_MAX_CHARS = 200;
	public const DESC_MAX_CHARS = 300;

	/**
	 * thin_content 判据：正文 <200 字符（nw §1.6——空壳/挑战页高频形态）。
	 */
	public const THIN_CONTENT_MIN = 200;

	/**
	 * decode_garbage 判据：替换符 U+FFFD 占比 >5%（nw §1.4）。
	 */
	public const GARBAGE_RATIO = 0.05;

	/**
	 * 失败类 → 判定语义（state + action；root_fallback=404 可选根域回退）。
	 *
	 * @param string $class 失败类（9 类键）。
	 * @return array{state: string, action: string, root_fallback: bool}
	 */
	public static function fetch_class_to_state( string $class ): array {
		return array(
			'state'         => 'cloaking_suspect' === $class ? 'review' : 'inconclusive',
			'action'        => 'keep', // 恒保持待审（零 reject 权重零放行权重）。
			'root_fallback' => 'not_found' === $class,
		);
	}

	/**
	 * 全部失败类键（nw §1.7 九类；not_found 含 root_fallback 语义）。
	 *
	 * @return string[]
	 */
	public static function fetch_classes(): array {
		return array( 'dns_nxdomain', 'conn_fail', 'timeout', 'anti_bot', 'non_html', 'oversized', 'decode_garbage', 'not_found', 'thin_content', 'cloaking_suspect', 'robots_disallow' );
	}

	/**
	 * thin_content 检查（LLM 之前的一切检查都是免费的——nw §六-抓取 6）。
	 *
	 * @param string $text       抽取文本。
	 * @param int    $html_bytes 原始 HTML 字节数。
	 * @return bool
	 */
	public static function is_thin_content( string $text, int $html_bytes ): bool {
		return mb_strlen( $text ) < self::THIN_CONTENT_MIN;
	}

	/**
	 * 乱码检查（替换符 U+FFFD 占比 >5% → 不喂 LLM，白烧一次调用）。
	 *
	 * @param string $text 抽取文本。
	 * @return bool
	 */
	public static function is_decode_garbage( string $text ): bool {
		$len = mb_strlen( $text );
		if ( 0 === $len ) {
			return false; // 空文本=thin_content 领地（B 机实测抓出：JS 跳转空壳页误归乱码）。
		}
		$bad = substr_count( $text, "\u{FFFD}" );
		return ( $bad / $len ) > self::GARBAGE_RATIO;
	}

	/**
	 * HTTP/传输形态 → 失败类（fetcher 编排消费）。
	 *
	 * @param int         $status       HTTP 状态（0=传输失败）。
	 * @param string      $content_type Content-Type。
	 * @param string|null $remote_category RemoteResult 分类（transport/semantic/contract/parse）。
	 * @return string 失败类键。
	 */
	public static function classify_http( int $status, string $content_type, ?string $remote_category = null ): string {
		if ( 0 === $status ) {
			return 'conn_fail';
		}
		if ( 404 === $status || 410 === $status ) {
			return 'not_found';
		}
		if ( 403 === $status || 503 === $status ) {
			return 'anti_bot';
		}
		if ( null !== $remote_category && 'transport' === $remote_category ) {
			return 'timeout';
		}
		if ( '' !== $content_type && 0 !== strpos( strtolower( $content_type ), 'text/html' ) ) {
			return 'non_html';
		}
		return 'conn_fail';
	}

	/**
	 * HTML→正文抽取（nw §1.6：元信号全保留独立于正文；剥离名单整块删除后才取文本；
	 * 不引入重依赖 DOM 解析器——容错式标签剥离对「判定是否垃圾站」足够）。
	 *
	 * @param string $html 原始 HTML。
	 * @return array{title: string, meta_description: string, og_title: string, meta_refresh: string, text: string}
	 */
	public static function extract( string $html ): array {
		$title   = '';
		$desc    = '';
		$og      = '';
		$refresh = '';

		// 元信号（在剥离前抓——title/meta 在 head 内）。
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $m ) ) {
			$title = self::to_text( $m[1] );
		}
		if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]*content=["\'](.*?)["\']/is', $html, $m ) ) {
			$desc = self::to_text( $m[1] );
		}
		if ( preg_match( '/<meta[^>]+property=["\']og:title["\'][^>]*content=["\'](.*?)["\']/is', $html, $m ) ) {
			$og = self::to_text( $m[1] );
		}
		if ( preg_match( '/<meta[^>]+http-equiv=["\']refresh["\'][^>]*content=["\'][^"\']*url=([^"\']+)["\']/is', $html, $m ) ) {
			$refresh = trim( $m[1] );
		}
		// JS 跳转目标（nw §1.3：location.replace/location.href=同属 HTTP 3xx 盲区——
		// 空壳跳转页形态；B 机实测百度 https→http 降级跳转页实证）。
		$js_redirect = '';
		if ( preg_match( '/location\.(?:replace|href)\s*=?/i', $html ) ) {
			// 字面 URL 形态取目标；计算型（location.href.replace(...)）记 detected 标记——
			// 两者都构成空壳跳转页信号（判定语义同 cloaking_suspect）。
			if ( preg_match( '/location\.(?:replace|href)\s*=?\s*\(?\s*[\'"](https?:\/\/[^\'"]+)/i', $html, $m ) ) {
				$js_redirect = trim( $m[1] );
			} else {
				$js_redirect = 'detected';
			}
		}

		// 剥离名单整块删除（注入载荷与关键词堆砌高发地——nw §1.6②/§3.1①）。
		foreach ( array( 'script', 'style', 'noscript', 'svg', 'iframe', 'template' ) as $tag ) {
			$html = preg_replace( '/<' . $tag . '[^>]*>.*?<\/' . $tag . '>/is', ' ', $html ) ?? $html;
		}
		$html = preg_replace( '/<!--.*?-->/s', ' ', $html ) ?? $html;

		// 剥标签→实体解码→空白折叠。
		$text = preg_replace( '/<[^>]+>/', ' ', $html ) ?? '';
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? '';
		$text = trim( $text );

		return array(
			'title'            => mb_substr( $title, 0, self::TITLE_MAX_CHARS ),
			'meta_description' => mb_substr( $desc, 0, self::DESC_MAX_CHARS ),
			'og_title'         => mb_substr( $og, 0, self::TITLE_MAX_CHARS ),
			'meta_refresh'     => $refresh,
			'js_redirect'      => $js_redirect,
			'text'             => mb_substr( $text, 0, self::TEXT_MAX_CHARS ),
		);
	}

	/**
	 * @param string $raw 原始片段。
	 * @return string
	 */
	private static function to_text( string $raw ): string {
		return trim( html_entity_decode( preg_replace( '/\s+/u', ' ', strip_tags( $raw ) ) ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
