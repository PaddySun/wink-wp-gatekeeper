<?php
/**
 * URL 规范化与缓存键（nw §4.3，2026-11-14 U5 批）。
 *
 * 规范化=去 fragment、host 小写、路径保留、query 按名排序保留（跟踪参数参与落点，
 * 排序消同义重复）。缓存键=sha256(规范化 URL)；prompt_version 入值语义（换词表=旧
 * 缓存惰性失效——nw §2.2 词表版本化）。
 *
 * @package WinkGatekeeper\UrlAudit
 */

declare( strict_types=1 );

namespace WinkGatekeeper\UrlAudit;

final class UrlNormalizer {

	/**
	 * 规范化（不可解析=null——调用方落 conn_fail 类）。
	 *
	 * @param string $url 原始 URL。
	 * @return string|null
	 */
	public static function normalize( string $url ): ?string {
		$parsed = parse_url( trim( $url ) );
		if ( ! is_array( $parsed ) || ! isset( $parsed['scheme'], $parsed['host'] ) ) {
			return null;
		}
		$scheme = strtolower( (string) $parsed['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}
		$host  = strtolower( (string) $parsed['host'] );
		$port  = isset( $parsed['port'] ) ? ':' . (int) $parsed['port'] : '';
		$path  = (string) ( $parsed['path'] ?? '/' );
		$query = '';
		if ( isset( $parsed['query'] ) && '' !== (string) $parsed['query'] ) {
			$pairs = array();
			foreach ( explode( '&', (string) $parsed['query'] ) as $pair ) {
				if ( '' !== $pair ) {
					$pairs[] = $pair;
				}
			}
			sort( $pairs, SORT_STRING );
			$query = '?' . implode( '&', $pairs );
		}
		return $scheme . '://' . $host . $port . $path . $query;
	}

	/**
	 * 缓存键（sha256(规范化 URL)）。
	 *
	 * @param string $url 原始 URL。
	 * @return string|null null=不可规范化（无缓存语义）。
	 */
	public static function cache_key( string $url ): ?string {
		$normalized = self::normalize( $url );
		return null === $normalized ? null : hash( 'sha256', $normalized );
	}
}
