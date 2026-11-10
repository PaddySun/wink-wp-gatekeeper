<?php
/**
 * TC3-HMAC-SHA256 签名共用工具（腾讯云《签名方法 v3》，C4 从 TencentCaptchaDriver 抽取）。
 *
 * 抽取理由（02 §2.6 协议族思想在传输层的落点）：天御核验（captcha-2019-07-22）与
 * TMS 文本审核（tms-2020-12-29）同栈同算法——签名代码单源化，M4 腾讯系新驱动
 * （CVM 式产品族）零复制接入。既有金测试（腾讯云官方文档示例值）与 L0 冒烟互验
 * 用例继续锚定 TencentCaptchaDriver::tc3_authorization 薄委托，行为逐字节不变。
 *
 * 纯函数、零 WP 依赖。公共参数（X-TC-Region 等）不参与签名——地域型服务由调用方
 * 在签名产出后并入 headers（TMS TextModeration 必填 Region，L0 实测定论）。
 *
 * @package WinkGatekeeper\Http
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Http;

final class Tc3Signer {

	/**
	 * 规范请求串（CanonicalRequest，签名 v3 步骤 1）。
	 *
	 * @param string $host    请求主机。
	 * @param string $action  接口名（X-TC-Action；签名时取小写）。
	 * @param string $payload 请求正文。
	 * @return string
	 */
	public static function canonical_request( string $host, string $action, string $payload ): string {
		return "POST\n"
			. "/\n"
			. "\n" // CanonicalQueryString：POST JSON 固定空串。
			. "content-type:application/json; charset=utf-8\n"
			. 'host:' . $host . "\n"
			. 'x-tc-action:' . strtolower( $action ) . "\n"
			. "\n"
			. "content-type;host;x-tc-action\n"
			. hash( 'sha256', $payload );
	}

	/**
	 * TC3 签名与请求头（签名 v3 步骤 1-4；UTC 日期从时间戳换算——东八区凌晨签名失败的经典坑）。
	 *
	 * @param string $secret_id  CAM SecretId。
	 * @param string $secret_key CAM SecretKey。
	 * @param string $host       请求主机（service 取主机首段：captcha / tms / …）。
	 * @param string $action     接口名。
	 * @param string $version    API 版本（X-TC-Version）。
	 * @param int    $timestamp  请求时间戳（秒）。
	 * @param string $payload    请求正文。
	 * @return array{Authorization: string, headers: array<string, string>}
	 */
	public static function authorization( string $secret_id, string $secret_key, string $host, string $action, string $version, int $timestamp, string $payload ): array {
		$date             = gmdate( 'Y-m-d', $timestamp ); // UTC+0 日期。
		$service          = explode( '.', $host )[0];

		$canonical        = self::canonical_request( $host, $action, $payload );
		$credential_scope = $date . '/' . $service . '/tc3_request';
		$string_to_sign   = "TC3-HMAC-SHA256\n{$timestamp}\n{$credential_scope}\n" . hash( 'sha256', $canonical );

		$secret_date    = hash_hmac( 'SHA256', $date, 'TC3' . $secret_key, true );
		$secret_service = hash_hmac( 'SHA256', $service, $secret_date, true );
		$secret_signing = hash_hmac( 'SHA256', 'tc3_request', $secret_service, true );
		$signature      = hash_hmac( 'SHA256', $string_to_sign, $secret_signing );

		$authorization = 'TC3-HMAC-SHA256'
			. ' Credential=' . $secret_id . '/' . $credential_scope
			. ', SignedHeaders=content-type;host;x-tc-action'
			. ', Signature=' . $signature;

		return array(
			'Authorization' => $authorization,
			'headers'       => array(
				'Authorization'  => $authorization,
				'Content-Type'   => 'application/json; charset=utf-8',
				'Host'           => $host,
				'X-TC-Action'    => $action,
				'X-TC-Timestamp' => (string) $timestamp,
				'X-TC-Version'   => $version,
				// Region 等公共参数不参与 TC3 签名——地域型服务（如 TMS）由调用方
				// 把 X-TC-Region 并入 headers（见 TencentTmsDriver::check）。
			),
		);
	}
}
