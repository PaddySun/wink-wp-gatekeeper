<?php
/**
 * 同 URL 判定缓存（nw §4.3，2026-11-14 U5 批）。
 *
 * 键=`wk_ua_` + sha256(规范化 URL)（transient 前缀——uninstall.php 清单同步面）；
 * 值=verdict/confidence/hits/reason(截断)/fetch 类/final_url/跳链/judged_at/
 * prompt_version/model。TTL 按 verdict 分档（UrlVerdicts::cache_ttl——pass 14d/reject
 * 90d/inconclusive 24h/NXDOMAIN 7d）。prompt_version 变更=旧缓存惰性失效（键语义）。
 *
 * 失效面：手动清空按钮（Tab③ 高级折叠）/host 级清（站长举报误判——前缀扫描）。
 *
 * @package WinkGatekeeper\UrlAudit
 */

declare( strict_types=1 );

namespace WinkGatekeeper\UrlAudit;

final class UrlAuditCache {

	/**
	 * transient 前缀（uninstall.php 8 前缀清单同步项）。
	 */
	public const PREFIX = 'wk_ua_';

	/**
	 * @param string $url 原始 URL。
	 * @return array<string, mixed>|null
	 */
	public static function get( string $url ): ?array {
		$key = UrlNormalizer::cache_key( $url );
		if ( null === $key ) {
			return null;
		}
		$value = get_transient( self::PREFIX . $key );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * @param string               $url   原始 URL。
	 * @param array<string, mixed> $value 判定值（verdict/confidence/hits/reason/…）。
	 * @return void
	 */
	public static function put( string $url, array $value ): void {
		$key = UrlNormalizer::cache_key( $url );
		if ( null === $key ) {
			return;
		}
		$verdict = (string) ( $value['verdict'] ?? 'inconclusive' );
		set_transient( self::PREFIX . $key, $value, UrlVerdicts::cache_ttl( $verdict ) );
	}

	/**
	 * 清空全部判定缓存（Tab③ 清缓存按钮——transient 名逐键精确删除）。
	 *
	 * @return int 清除条数。
	 */
	public static function purge_all(): int {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_' . self::PREFIX . '%' )
		);
		$count = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $option_name ) {
			$name = substr( (string) $option_name, strlen( '_transient_' ) );
			if ( delete_transient( $name ) ) {
				++$count;
			}
		}
		// 【B 机实测抓出·2026-11-14】Redis Object Cache 下 transient 存 object cache 不在
		// options 表——LIKE 枚举清不到；卸载尾部 wp_cache_flush 先例（F3-1）同款收口：
		// 清空全部按钮语义=站长显式动作+UI 二次确认，全 flush 副作用可接受。
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		return $count;
	}

	/**
	 * host 级清缓存（站长举报误判——PHP 端比对缓存行 final_url host，防 SQL 模糊宽匹配误伤）。
	 *
	 * @param string $host 域名。
	 * @return int 清除条数（object cache 环境下 transient 不可枚举=0——limitation 注记
	 *              IMPL-M5-U5；站长侧可用「清空全部」按钮兜底）。
	 */
	public static function purge_host( string $host ): int {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				'_transient_' . self::PREFIX . '%'
			),
			ARRAY_A
		);
		$count = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$value = maybe_unserialize( (string) ( $row['option_value'] ?? '' ) );
			$final = is_array( $value ) ? (string) ( $value['final_url'] ?? '' ) : '';
			$row_host = '' === $final ? '' : (string) ( parse_url( $final, PHP_URL_HOST ) ?? '' );
			if ( '' === $row_host || strtolower( $row_host ) !== strtolower( $host ) ) {
				continue;
			}
			$name = substr( (string) ( $row['option_name'] ?? '' ), strlen( '_transient_' ) );
			if ( '' !== $name && delete_transient( $name ) ) {
				++$count;
			}
		}
		return $count;
	}
}
