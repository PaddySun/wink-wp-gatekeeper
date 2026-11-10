<?php
/**
 * 前台失败响应协议纯逻辑（02 §3.5 v1.3：判定与落地分离，AJAX/REST/原生三分支）。
 *
 * 「响应形态归闸、错误语义归驱动」——本类是闸侧统一分派的纯函数面：
 *   - 原生判定：SCRIPT_FILENAME realpath == ABSPATH/wp-comments-post.php
 *     （hCaptcha is_native_comment_submission 同型 realpath 比对法）；
 *   - 分支防呆次序：先原生 → 再 AJAX → 兜底品牌页。DOING_AJAX 不可单独判 AJAX 分支
 *     （WooCommerce 在仍由 wp-comments-post.php 处理的请求上也定义 DOING_AJAX）；
 *   - AJAX 头协议四头：X-Wink-Gatekeeper-Scene/-Error/-Code/-Msg（Msg rawurlencode，
 *     命名习惯对齐 makotowu X-TencentCloud-Captcha-*）；headers_sent 退化 = body 全量携带；
 *   - REST 分支补同一头协议（WP_Error JSON 之外的头侧信息）。
 *
 * 纯 PHP 零 WP 依赖（单测覆盖）；header()/echo/exit 的 WP 边界在 CommentGate 落地方法。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

final class ResponseDispatcher {

	/**
	 * 原生评论提交脚本名（WP 前台评论表单 action）。
	 */
	public const NATIVE_SCRIPT = 'wp-comments-post.php';

	/* ---------- 原生判定 ---------- */

	/**
	 * 是否原生表单提交（wp-comments-post.php）。
	 *
	 * @param string $script_filename $_SERVER['SCRIPT_FILENAME']。
	 * @param string $abspath         ABSPATH（带或不带尾部分隔符）。
	 * @return bool realpath 失败（文件不存在/权限）= 非原生。
	 */
	public static function is_native_submission( string $script_filename, string $abspath ): bool {
		$script = realpath( $script_filename );
		if ( false === $script || '' === $script ) {
			return false;
		}
		$native = realpath( rtrim( $abspath, '/\\' ) . DIRECTORY_SEPARATOR . self::NATIVE_SCRIPT );
		return false !== $native && $script === $native;
	}

	/* ---------- 分支判定防呆次序 ---------- */

	/**
	 * 分支判定：先原生（SCRIPT_FILENAME realpath）→ 再 AJAX（DOING_AJAX 等）→ 兜底品牌页。
	 *
	 * @param bool $is_native   原生判定结果。
	 * @param bool $doing_ajax  DOING_AJAX 常量态（不可单独判——native 优先级更高）。
	 * @return string 'native' | 'ajax' | 'page'
	 */
	public static function branch( bool $is_native, bool $doing_ajax ): string {
		if ( $is_native ) {
			return 'native';
		}
		if ( $doing_ajax ) {
			return 'ajax';
		}
		return 'page';
	}

	/* ---------- 错误语义映射（rule → -Error 头 token） ---------- */

	/**
	 * 规则键 → 头协议 -Error token（02 §3.5 头协议枚举）。
	 *
	 * @param string $rule evidence 命中规则（bad-signature/captcha_missing/CaptchaCode=N/文案键…）。
	 * @return string required|verify_failed|rejected|bad_signature|ip_blocked
	 */
	public static function error_token( string $rule ): string {
		if ( 'bad-signature' === $rule ) {
			return 'bad_signature';
		}
		if ( 'captcha_missing' === $rule ) {
			return 'required';
		}
		if ( 'captcha_failed' === $rule || 0 === strpos( $rule, 'CaptchaCode=' ) ) {
			return 'verify_failed';
		}
		if ( 0 === strpos( $rule, 'ip_' ) ) {
			return 'ip_blocked'; // ip_malicious/ip_blocked（M3 IPDriver）。
		}
		return 'rejected'; // too_short/too_long/no_chinese/banned_word/未知规则——安全默认。
	}

	/* ---------- AJAX 头协议 ---------- */

	/**
	 * 四头拼装（AJAX 分支；Msg rawurlencode——中文文案安全过 HTTP 头）。
	 *
	 * @param array<string, mixed> $result orchestrate 的 reject 结果（message/evidence）。
	 * @param string               $scene  场景（comment/login）。
	 * @return array<string, string> 头名 => 头值。
	 */
	public static function ajax_headers( array $result, string $scene ): array {
		$rule = self::rule_of( $result );
		return array(
			'X-Wink-Gatekeeper-Scene' => $scene,
			'X-Wink-Gatekeeper-Error' => self::error_token( $rule ),
			'X-Wink-Gatekeeper-Code'  => $rule,
			'X-Wink-Gatekeeper-Msg'   => rawurlencode( isset( $result['message'] ) && is_string( $result['message'] ) ? $result['message'] : '' ),
		);
	}

	/**
	 * AJAX body（WP AJAX 约定形态 {success:false,data:{…}}）。
	 *
	 * @param array<string, mixed> $result       reject 结果。
	 * @param string               $scene        场景。
	 * @param bool                 $headers_sent headers_sent() 结果——true = 退化全量携带
	 *                                           （scene/error 进 body，防御头已发不出去）。
	 * @return array<string, mixed>
	 */
	public static function ajax_body( array $result, string $scene, bool $headers_sent ): array {
		$rule = self::rule_of( $result );
		$data = array(
			'code'    => $rule,
			'message' => isset( $result['message'] ) && is_string( $result['message'] ) ? $result['message'] : '',
		);
		if ( $headers_sent ) {
			// 退化形态：头协议发不出去 → 全量进 body（02 §3.5 防御条款）。
			$data = array_merge( array(
				'scene' => $scene,
				'error' => self::error_token( $rule ),
			), $data );
		}
		return array(
			'success' => false,
			'data'    => $data,
		);
	}

	/* ---------- REST 分支补充头 ---------- */

	/**
	 * REST 分支头协议（WP_Error JSON 之外的头侧信息——Gutenberg/REST 客户端可读）。
	 *
	 * @param array<string, mixed> $result reject 结果。
	 * @param string               $scene  场景。
	 * @return array<string, string>
	 */
	public static function rest_error_headers( array $result, string $scene ): array {
		return self::ajax_headers( $result, $scene ); // 同一头协议（02 §3.5：全驱动共用 X-Wink-Gatekeeper-*）。
	}

	/**
	 * reject 结果中的规则键（evidence 命中规则；缺省空串——不杜撰）。
	 *
	 * @param array<string, mixed> $result
	 * @return string
	 */
	private static function rule_of( array $result ): string {
		return isset( $result['evidence']['命中规则'] ) && is_string( $result['evidence']['命中规则'] )
			? $result['evidence']['命中规则']
			: '';
	}
}
