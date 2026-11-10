<?php
/**
 * 阿里云 V3 请求签名（ACS3-HMAC-SHA256）——B3 从 tools/api-smoke/aliyun-sign.php 蓝本升格。
 *
 * 依据（在线核对 2026-11-09，L0-ali-captcha；官方文档自带测试向量，金测试离线锚定）：
 *   - help.aliyun.com/zh/sdk/product-overview/v3-request-structure-and-signature
 *   - RPC 风格 API：CanonicalURI 固定 "/"；业务参数放 query（或 form body，
 *     content-type: application/x-www-form-urlencoded；官方不建议 application/json）
 *   - 签名头最小集：host / x-acs-action / x-acs-content-sha256 / x-acs-date /
 *     x-acs-signature-nonce / x-acs-version（+ content-type / x-acs-security-token 如有）
 *   - Authorization: ACS3-HMAC-SHA256 Credential=<AKId>,SignedHeaders=<...>,Signature=<hex小写>
 *   - HMAC 密钥 = AccessKeySecret 原文（无派生——区别于腾讯 TC3 三段派生）
 *
 * 与 Tc3Signer 同层并列（02 §2.6 协议族思想：阿里系驱动共用一套签名器，不拖全量 SDK
 * vendor——05 §1.2 G5 零依赖惯例）。纯函数、零 WP 依赖。
 *
 * @package WinkGatekeeper\Http
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Http;

final class AliyunV3Signer {

	/**
	 * 构造 V3 签名请求（返回请求 URL 与全部请求头，调用方经传输脊 RemoteClient 发出）。
	 *
	 * @param string               $method               HTTP 方法（大写，如 POST）。
	 * @param string               $host                 主机名（不含 scheme，如 captcha.cn-shanghai.aliyuncs.com）。
	 * @param string               $uri                  规范化 URI（RPC 风格固定 '/'）。
	 * @param array<string, string> $query               业务参数（原始键值；按 RFC3986 编码并按键名升序拼 query）。
	 * @param array<string, string> $extra_signed_headers 额外参与签名的头（如 content-type）；键转小写。
	 * @param string               $payload              请求体（query 传参时为空串；form 体传编码后正文）。
	 * @param string               $access_key_id        RAM AccessKeyId。
	 * @param string               $access_key_secret    RAM AccessKeySecret（HMAC 密钥原文）。
	 * @param string               $action               API 名称（x-acs-action，如 VerifyIntelligentCaptcha）。
	 * @param string               $version              API 版本（x-acs-version，如 captcha-2023-03-05）。
	 * @param string|null          $nonce                防重放随机数（金测试注入用；默认随机 32 位 hex）。
	 * @param string|null          $date                 ISO8601 UTC（金测试注入用；默认当前时间）。
	 * @return array{url: string, headers: array<string, string>, canonical_request: string,
	 *               hashed_canonical_request: string, string_to_sign: string, signature: string,
	 *               authorization: string}
	 */
	public static function sign(
		string $method,
		string $host,
		string $uri,
		array $query,
		array $extra_signed_headers,
		string $payload,
		string $access_key_id,
		string $access_key_secret,
		string $action,
		string $version,
		?string $nonce = null,
		?string $date = null
	): array {
		$nonce = $nonce ?? bin2hex( random_bytes( 16 ) );
		$date  = $date ?? gmdate( 'Y-m-d\TH:i:s\Z' );

		// 步骤一：规范化请求。
		// CanonicalQueryString：RFC3986 编码（PHP rawurlencode 语义一致：保留 A-Za-z0-9-._~，
		// 空格→%20、*→%2A），键名升序，name=value 以 & 拼接。
		$pairs = array();
		foreach ( $query as $k => $v ) {
			$pairs[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
		}
		sort( $pairs, SORT_STRING );
		$canonical_query = implode( '&', $pairs );

		$payload_hash = hash( 'sha256', $payload );

		// CanonicalHeaders：host / x-acs-* / content-type（及额外指定头）；键小写升序，
		// 值去首尾空格，每条尾部 \n。
		$headers = array(
			'host'                   => $host,
			'x-acs-action'           => $action,
			'x-acs-content-sha256'   => $payload_hash,
			'x-acs-date'             => $date,
			'x-acs-signature-nonce'  => $nonce,
			'x-acs-version'          => $version,
		);
		foreach ( $extra_signed_headers as $k => $v ) {
			$headers[ strtolower( $k ) ] = $v;
		}
		ksort( $headers, SORT_STRING );
		$canonical_headers = '';
		foreach ( $headers as $k => $v ) {
			$canonical_headers .= $k . ':' . trim( (string) $v ) . "\n";
		}
		$signed_headers = implode( ';', array_keys( $headers ) );

		$canonical_request = strtoupper( $method ) . "\n"
			. $uri . "\n"
			. $canonical_query . "\n"
			. $canonical_headers . "\n"
			. $signed_headers . "\n"
			. $payload_hash;

		// 步骤二：待签名字符串。
		$hashed_canonical_request = hash( 'sha256', $canonical_request );
		$string_to_sign           = "ACS3-HMAC-SHA256\n" . $hashed_canonical_request;

		// 步骤三：HMAC-SHA256（密钥 = AccessKeySecret 原文，小写 hex）。
		$signature = hash_hmac( 'sha256', $string_to_sign, $access_key_secret );

		// 步骤四：Authorization 头。
		$authorization = sprintf(
			'ACS3-HMAC-SHA256 Credential=%s,SignedHeaders=%s,Signature=%s',
			$access_key_id,
			$signed_headers,
			$signature
		);

		$send_headers                 = $headers; // 已含 host + 5 个 x-acs-* 签名头。
		$send_headers['authorization'] = $authorization;
		$send_headers['accept']        = 'application/json';

		$url = 'https://' . $host . $uri . ( '' !== $canonical_query ? '?' . $canonical_query : '' );

		return array(
			'url'                      => $url,
			'headers'                  => $send_headers,
			'canonical_request'        => $canonical_request,
			'hashed_canonical_request' => $hashed_canonical_request,
			'string_to_sign'           => $string_to_sign,
			'signature'                => $signature,
			'authorization'            => $authorization,
		);
	}
}
