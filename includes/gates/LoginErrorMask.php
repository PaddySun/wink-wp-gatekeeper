<?php
/**
 * 登录错误掩码（WORDFENCE-ADOPTION B2——防用户名/邮箱枚举，2026-11-11）。
 *
 * **只动呈现层**（Wordfence loginSec_maskLoginErrors 语义，wordfenceClass.php:3530 先例）：
 * WP 原生聚合输出处（wp_login_errors / lostpassword_errors filter）把「泄露账户存在性」
 * 的错误码文案替换为统一文案——**内部错误码不动**（F39 双信号分类表在 wp_login_failed
 * 消费内部码，掩码发生在呈现层，分类链路零影响）。
 *
 * 复现样本（真机 2026-11-10 实测）：登录页「Error: The username <b>wkprobe_nouser</b>
 * is not registered on this site…」与找回页「Unknown email address. Check again or try
 * the guide.」明文透传——前者=invalid_username、后者=invalid_email。
 *
 * 掩码码表（存在性泄露面）：
 *   - invalid_username   登录页「用户名未注册」明文（含用户名回显）。
 *   - incorrect_password 「该用户名的口令错误」——反向确认用户名存在（Wordfence 同掩）。
 *   - invalid_email      找回页「Unknown email address…」（B2 复现样本）。
 *   - invalidcombo       找回页「no account with that username or email address」。
 *
 * 开关：filter `wink_gatekeeper_login_error_mask_enabled`（WP 边界 LoginGate 消费），
 * **保守默认开**+可关（docs 未定 UI 键——按任务书授权保守默认开实现，留档本报告；
 * 后续如需 Tab② 开关再升格 schema 键）。掩码文案默认集恒定不可配（文案工坊
 * messages 键集是评论域语义，不混入——防「可配文案」破坏不可区分性）。
 *
 * 分层：纯逻辑类（零 WP 依赖，单测覆盖）；filter 消费在 LoginGate hook_ 系列 WP 边界。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

final class LoginErrorMask {

	/**
	 * 掩码开关 filter 名（默认 true——保守默认开；关=WP 原生明文回现行形态）。
	 */
	public const FILTER_ENABLED = 'wink_gatekeeper_login_error_mask_enabled';

	/**
	 * 登录域统一掩码文案（invalid_username/incorrect_password 共用——响应不可区分）。
	 */
	public const MESSAGE_LOGIN_FAILED = '错误：用户名或口令不正确。';

	/**
	 * 找回域统一掩码文案（invalid_email/invalidcombo 共用；不带「Unknown email address」
	 * 明文与存在性线索）。
	 */
	public const MESSAGE_RESET_UNAVAILABLE = '错误：暂时无法发送重置邮件——请确认输入后重试。';

	/**
	 * 泄露存在性的 WP 原生错误码表（登录域 + 找回域）。
	 *
	 * @var string[]
	 */
	private const MASKED_CODES = array( 'invalid_username', 'incorrect_password', 'invalid_email', 'invalidcombo' );

	/**
	 * 错误码是否在掩码码表内。
	 *
	 * @param string $code WP_Error::get_error_code()。
	 * @return bool
	 */
	public static function is_masked_code( string $code ): bool {
		return in_array( $code, self::MASKED_CODES, true );
	}

	/**
	 * 掩码场景（【v1.5·FIX】按消费 filter 场景分域——原按码分域的缺陷：登录框支持邮箱
	 * 登录，不存在的邮箱形态输入同样报 invalid_email，按码映射会把找回域文案
	 * 「暂时无法发送重置邮件」打到登录页（真机 2026-11-12 用户复现实锤）。
	 * 同一码在两场景语义不同，场景由消费方（wp_login_errors vs lostpassword_errors）决定。
	 */
	public const SCENE_LOGIN = 'login';
	public const SCENE_RESET = 'reset';

	/**
	 * 掩码替换（纯函数）：码表内错误码 → **本场景**统一文案；其余码原样透传
	 * （本闸 wk_captcha_* 文案、第三方错误、空消息不动）。
	 *
	 * @param string $scene   消费场景（SCENE_LOGIN 登录表单 | SCENE_RESET 找回表单）。
	 * @param string $code    WP_Error 错误码。
	 * @param string $message 原始错误文案（单条）。
	 * @return string 掩码后文案（非码表码 = 原文）。
	 */
	public static function mask_message( string $scene, string $code, string $message ): string {
		if ( ! self::is_masked_code( $code ) ) {
			return $message;
		}
		return self::SCENE_RESET === $scene ? self::MESSAGE_RESET_UNAVAILABLE : self::MESSAGE_LOGIN_FAILED;
	}
}
