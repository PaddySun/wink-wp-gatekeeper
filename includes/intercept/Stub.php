<?php
/**
 * 签名存根（02 §3.3：防 token 跨表单混用，借鉴 hCaptcha 签名身份系统的简化实现）。
 *
 * 存根 = base64( json{ gate, context_id, captcha_shown, ts } ) + '.' + hash( 前者 )
 *
 * 提交期三态：
 *   false —— 缺字段/格式错/哈希错/gate 错/context_id 错/超 TTL → 拦 bad-signature；
 *   true  —— 签名有效且本表单未展示挑战（captcha_shown=false）→ 免验证放行；
 *   null  —— 签名有效且展示过（captcha_shown=true）→ 必须走 captcha verify。
 *
 * 与 hCaptcha 原版差异：ts 参与 hash，签发后 24 小时过期（TTL）。
 *
 * 分层：payload 构造与哈希拼接为纯函数（本类）；wp_hash 属 WP 边界——经可注入回调
 * （默认由 AbstractGate/闸边界注入 wp_hash($data,'nonce')，测试注入固定函数）。
 *
 * @package WinkGatekeeper\Intercept
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Intercept;

final class Stub {

	/**
	 * 存根有效期（秒）：24 小时（评论页挂一晚上再提交的边角场景）。
	 */
	public const TTL = 86400;

	/**
	 * 签发存根。
	 *
	 * @param string   $gate           闸 id（comment|login）。
	 * @param string   $context_id     上下文身份（评论闸 = comment_post_ID；登录闸 = 'login'）。
	 * @param bool     $captcha_shown 渲染时是否展示了验证码挑战。
	 * @param int      $now            签发时间戳（注入以便测试）。
	 * @param callable $hash           哈希回调 fn(string $payload): string（生产 = wp_hash 封装）。
	 * @return string
	 */
	public static function issue( string $gate, string $context_id, bool $captcha_shown, int $now, callable $hash ): string {
		$payload = self::encode_payload( $gate, $context_id, $captcha_shown, $now );
		return $payload . '.' . call_user_func( $hash, $payload );
	}

	/**
	 * 三态校验。
	 *
	 * @param string   $stub        提交的存根字符串。
	 * @param string   $gate        期望闸 id。
	 * @param string   $context_id  期望上下文身份。
	 * @param int      $now         校验时间戳（注入以便 TTL 测试）。
	 * @param callable $hash        哈希回调（须与签发一致）。
	 * @return bool|null false=拦；true=免验证放行；null=必须 verify。
	 */
	public static function verify( string $stub, string $gate, string $context_id, int $now, callable $hash ) {
		$dot = strrpos( $stub, '.' );
		if ( false === $dot || 0 === $dot || strlen( $stub ) - 1 === $dot ) {
			return false; // 缺 payload 或缺哈希段。
		}

		$payload = substr( $stub, 0, $dot );
		$mac     = substr( $stub, $dot + 1 );

		$expected = call_user_func( $hash, $payload );
		if ( ! hash_equals( $expected, $mac ) ) {
			return false; // 哈希错/篡改（时序安全比较）。
		}

		$decoded = base64_decode( $payload, true );
		if ( false === $decoded ) {
			return false;
		}
		$data = json_decode( $decoded, true );
		if ( ! is_array( $data ) ) {
			return false;
		}

		foreach ( array( 'gate', 'context_id', 'captcha_shown', 'ts' ) as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				return false;
			}
		}

		// 严格比较：gate/context_id 字符串全等（防松散比较与跨表单混用），ts 整型。
		if ( ! is_string( $data['gate'] ) || ! is_string( $data['context_id'] ) ) {
			return false;
		}
		if ( $data['gate'] !== $gate || $data['context_id'] !== $context_id ) {
			return false;
		}
		if ( ! is_int( $data['ts'] ) || ! is_bool( $data['captcha_shown'] ) ) {
			return false;
		}

		// TTL：签发后 24h 过期（02 §3.3 本项目对 hCaptcha 原版的增项）。
		if ( $now - $data['ts'] > self::TTL ) {
			return false;
		}
		// 签发时间晚于当前（时钟倒挂容忍 0）：按过期处理。
		if ( $data['ts'] - $now > 0 ) {
			return false;
		}

		return $data['captcha_shown'] ? null : true;
	}

	/**
	 * payload 构造（纯函数，独立可见以便复用与测试）。
	 *
	 * @param string $gate
	 * @param string $context_id
	 * @param bool   $captcha_shown
	 * @param int    $now
	 * @return string base64 编码的 json payload。
	 */
	public static function encode_payload( string $gate, string $context_id, bool $captcha_shown, int $now ): string {
		return base64_encode(
			(string) json_encode(
				array(
					'gate'           => $gate,
					'context_id'     => $context_id,
					'captcha_shown'  => $captcha_shown,
					'ts'             => $now,
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
	}
}
