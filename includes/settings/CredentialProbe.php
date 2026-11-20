<?php
/**
 * Tab②「测试凭据」三态判定（03 §1 v1.3 用户建议2；判定法 = L0-tencent 冒烟 2026-11-09 定论）。
 *
 * 发一次全参假票核验（DescribeCaptchaResult），按响应形态分派：
 *   - 绿：CaptchaCode 7/8/15 = 凭据/TC3 签名链路全通（15 = 全参假票的正常业务拒绝）；
 *   - 红：TC3 层 AuthFailure* = SecretId/SecretKey（CAM 密钥）错；
 *   - 黄：MissingParameter = 必填参数缺（逐字段列出，消息内反引号参数名解析）；
 *   - 灰：传输终态/非预期码（16/100 等）——给原文与排查方向，不误报三态。
 *
 * 纯 PHP 零 WP 依赖（单测覆盖）；AJAX 边界在 SettingsPage::ajax_test_credentials。
 *
 * @package WinkGatekeeper\Settings
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Settings;

use WinkGatekeeper\Http\RemoteClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（S2-F1 纵深加固——类文件直访零执行，守卫为纵深面）。
}

use WinkGatekeeper\Http\RemoteResult;

final class CredentialProbe {

	/**
	 * 三态绿档：全参假票核验返回这些码 = 凭据全通（05 §1.1 凭据自检判定法）。
	 */
	private const GREEN_CODES = array( 7, 8, 15 );

	/**
	 * 本地预检：四凭据字段有空 → 黄态逐字段列缺（不发远端调用——空 CAM 密钥的 TC3 签名
	 * 必失败且无诊断价值）；齐全 → null（放行走远端假票核验）。
	 *
	 * @param array<string, mixed> $tencent captcha.tencent 子树。
	 * @return array{state: string, message: string, missing: string[]}|null
	 */
	public static function local_precheck( array $tencent ): ?array {
		$missing = CaptchaTabSpec::tencent_missing_fields( $tencent );
		if ( array() === $missing ) {
			return null;
		}
		return array(
			'state'   => 'yellow',
			'message' => __( 'Required fields missing: ', 'wink-wp-gatekeeper' ) . implode( '、', $missing ) . __( ' — fill them in and test again.', 'wink-wp-gatekeeper' ),
			'missing' => $missing,
		);
	}

	/**
	 * 三态分类。
	 *
	 * @param RemoteResult $result 全参假票核验的传输脊结果。
	 * @return array{state: string, message: string, missing: string[], code: string}
	 */
	public static function classify( RemoteResult $result ): array {
		$payload = array( 'state' => 'gray', 'message' => '', 'missing' => array(), 'code' => '' );

		// 传输终态：网络不可达（RemoteResult 4xx/5xx/连接失败已分类，body 可能携带 API 错误详情）。
		$decoded = self::decoded( $result );

		// TC3 层错误（Response.Error.Code）：HTTP 401 形态与 HTTP 200+Error 形态都覆盖。
		$error = null;
		if ( is_array( $decoded ) && isset( $decoded['Response']['Error'] ) && is_array( $decoded['Response']['Error'] ) ) {
			$error = $decoded['Response']['Error'];
		}
		if ( null !== $error && isset( $error['Code'] ) && is_string( $error['Code'] ) ) {
			$payload['code'] = $error['Code'];
			if ( 0 === strpos( $error['Code'], 'AuthFailure' ) ) {
				$payload['state']   = 'red';
				$payload['message'] = __( 'Key error (SecretId/SecretKey): the CAM key pair was rejected by Tencent Cloud — check it under “Access Management → API Key Management” and retry.', 'wink-wp-gatekeeper' );
				return $payload;
			}
			if ( 'MissingParameter' === $error['Code'] ) {
				$missing           = self::parse_missing_params( isset( $error['Message'] ) && is_string( $error['Message'] ) ? $error['Message'] : '' );
				$payload['state']  = 'yellow';
				$payload['missing'] = $missing;
				$payload['message'] = __( 'Required parameters missing: ', 'wink-wp-gatekeeper' ) . ( array() !== $missing ? implode( '、', $missing ) : RemoteClient::redact( (string) ( $error['Message'] ?? '' ) ) ) . __( ' — please fill in the corresponding fields above.', 'wink-wp-gatekeeper' ); // 【S4-F5】厂商原文过 redact.
				return $payload;
			}
			$payload['message'] = __( 'Server returned an error: ', 'wink-wp-gatekeeper' ) . $error['Code'] . '——' . RemoteClient::redact( (string) ( $error['Message'] ?? '' ) ); // 【S4-F5】厂商原文过 redact（灰态/未知码分支——第三方网关错误体回显密钥形态防截图外流）。
			return $payload;
		}

		if ( ! $result->is_ok() ) {
			// 非 API 错误的传输终态（连接失败/超时等）。
			$payload['message'] = __( 'Network unreachable or service temporarily unavailable (', 'wink-wp-gatekeeper' ) . $result->category() . __( ')— please try again later.', 'wink-wp-gatekeeper' );
			return $payload;
		}

		$response = is_array( $result->data() ) && isset( $result->data()['Response'] ) && is_array( $result->data()['Response'] )
			? $result->data()['Response']
			: array();
		$code = (int) ( $response['CaptchaCode'] ?? 0 );
		$msg  = isset( $response['CaptchaMsg'] ) && is_string( $response['CaptchaMsg'] ) ? $response['CaptchaMsg'] : '';

		if ( in_array( $code, self::GREEN_CODES, true ) ) {
			$payload['state']   = 'green';
			$payload['code']    = 'CaptchaCode=' . $code;
			$payload['message'] = __( 'Credentials work (CaptchaCode=', 'wink-wp-gatekeeper' ) . $code . __( ', a normal business rejection of the all-parameter fake ticket) — the front-end captcha should come up; if the comment area still shows loading, check the front-end network or theme scripts.', 'wink-wp-gatekeeper' );
			return $payload;
		}

		// 非预期码（16 appid 不匹配/100 参数校验错/21 票据异常等）：灰态给排查方向。
		$hint = in_array( $code, array( 16, 100 ), true )
			? __( '(most likely CaptchaAppId does not match AppSecretKey — check both values from the same page in the captcha console under “Verification Management → Basic Configuration”)', 'wink-wp-gatekeeper' )
			: '';
		$payload['code']    = 'CaptchaCode=' . $code;
		$payload['message'] = __( 'Unexpected verification return (CaptchaCode=', 'wink-wp-gatekeeper' ) . $code . ( '' !== $msg ? '：' . RemoteClient::redact( $msg ) : '' ) . '）' . $hint . __( ' — please screenshot this message for troubleshooting.', 'wink-wp-gatekeeper' ); // 【S4-F5】CaptchaMsg 原文过 redact。
		return $payload;
	}

	/**
	 * TMS「测试凭据」三态分类（03 Tab③，C4 实装；判定法 = 任务口径 + L0-tencent 冒烟）。
	 *
	 * 发一次良性样本文本真调用（TextModeration）：
	 *   - 绿：Suggestion=Pass = 凭据/TC3 签名/审核链路全通（真调用真判定，非假票形态）；
	 *   - 红：AuthFailure* = SecretId/SecretKey（CAM 密钥）错；UnauthorizedOperation* =
	 *     未开通 TMS 套餐包/账号欠费（同属站长侧配置问题，给指引）；
	 *   - 黄：MissingParameter / InvalidParameter 系 = 参数形态错（逐字段列出/给码）；
	 *   - 灰：传输终态/非 Pass 的业务判定（Review/Block）——给原文与排查方向。
	 *
	 * 绿态解除停转（02 §5「重存密钥并点自检通过」）由 AJAX 边界执行 clear——本类保持纯逻辑。
	 *
	 * @param RemoteResult $result 良性样本文本审核的传输脊结果。
	 * @return array{state: string, message: string, missing: string[], code: string}
	 */
	public static function classify_tms( RemoteResult $result ): array {
		$payload = array( 'state' => 'gray', 'message' => '', 'missing' => array(), 'code' => '' );

		$decoded = self::decoded( $result );

		// TMS 业务错误码在 2xx body 中（Response.Error.Code）。
		$error = null;
		if ( is_array( $decoded ) && isset( $decoded['Response']['Error'] ) && is_array( $decoded['Response']['Error'] ) ) {
			$error = $decoded['Response']['Error'];
		}
		if ( null !== $error && isset( $error['Code'] ) && is_string( $error['Code'] ) ) {
			$payload['code'] = $error['Code'];
			if ( 0 === strpos( $error['Code'], 'AuthFailure' ) ) {
				$payload['state']   = 'red';
				$payload['message'] = __( 'Key error (SecretId/SecretKey): the CAM key pair was rejected by Tencent Cloud — check it under “Access Management → API Key Management” and retry.', 'wink-wp-gatekeeper' );
				return $payload;
			}
			if ( 0 === strpos( $error['Code'], 'UnauthorizedOperation' ) ) {
				$payload['state']   = 'red';
				$payload['message'] = __( 'API authorization missing: TMS is not enabled / no valid package / the account is in arrears — enable a text moderation package in the Content Security console and retry.', 'wink-wp-gatekeeper' );
				return $payload;
			}
			if ( 'MissingParameter' === $error['Code'] ) {
				$missing            = self::parse_missing_params( isset( $error['Message'] ) && is_string( $error['Message'] ) ? $error['Message'] : '' );
				$payload['state']   = 'yellow';
				$payload['missing'] = $missing;
				$payload['message'] = __( 'Required parameters missing: ', 'wink-wp-gatekeeper' ) . ( array() !== $missing ? implode( '、', $missing ) : RemoteClient::redact( (string) ( $error['Message'] ?? '' ) ) ) . __( ' — please check the configuration above.', 'wink-wp-gatekeeper' ); // 【S4-F5】厂商原文过 redact.
				return $payload;
			}
			if ( 0 === strpos( $error['Code'], 'InvalidParameter' ) ) {
				$payload['state']   = 'yellow';
				$payload['message'] = __( 'Parameter error (', 'wink-wp-gatekeeper' ) . $error['Code'] . __( ') — please check the parameter values above (e.g. the BizType policy ID) and retry.', 'wink-wp-gatekeeper' );
				return $payload;
			}
			$payload['message'] = __( 'Server returned an error: ', 'wink-wp-gatekeeper' ) . $error['Code'] . '——' . RemoteClient::redact( (string) ( $error['Message'] ?? '' ) ); // 【S4-F5】厂商原文过 redact（灰态/未知码分支——第三方网关错误体回显密钥形态防截图外流）。
			return $payload;
		}

		if ( ! $result->is_ok() ) {
			$payload['message'] = __( 'Network unreachable or service temporarily unavailable (', 'wink-wp-gatekeeper' ) . $result->category() . __( ') — please try again later.', 'wink-wp-gatekeeper' );
			return $payload;
		}

		$response = is_array( $result->data() ) && isset( $result->data()['Response'] ) && is_array( $result->data()['Response'] )
			? $result->data()['Response']
			: array();
		$suggestion = is_string( $response['Suggestion'] ?? null ) ? $response['Suggestion'] : '';

		if ( 'Pass' === $suggestion ) {
			$payload['state']   = 'green';
			$payload['code']    = 'Suggestion=Pass';
			$payload['message'] = __( 'Credentials work (Suggestion=Pass, a normal text passed the live test) — the moderation chain is connected; once enabled, comments will be submitted through “local rules → TMS” in series.', 'wink-wp-gatekeeper' );
			return $payload;
		}

		$payload['code']    = 'Suggestion=' . ( '' !== $suggestion ? $suggestion : '?' );
		$payload['message'] = __( 'Unexpected verdict return (Suggestion=', 'wink-wp-gatekeeper' ) . $suggestion . __( ') — the credential and signing chain works, but the sample text did not get Pass; retry or screenshot this message for troubleshooting.', 'wink-wp-gatekeeper' );
		return $payload;
	}

	/**
	 * 阿里验证码 2.0「测试凭据」三态分类（B3；03 Tab②【v1.5】/ 05 §1.2 凭据自检；
	 * 判定法 = DESIGN-D2 §1.4 + L0「全参假票」手法）。
	 *
	 * 发一次假 CaptchaVerifyParam 实调（VerifyIntelligentCaptcha）：
	 *   - 绿：HTTP 200 + VerifyCode=F002/F003——网关鉴权+AK+RAM 全通（业务层拒绝假参数）；
	 *   - 红：InvalidAccessKeyId* 与 SignatureDoesNotMatch（AK/签名错）或 403
	 *     Forbidden.NoPermission（RAM 未授权——文案指明补授 AliyunYundunAFSFullAccess，
	 *     AuthAction 一并展示）或 403 Forbidden.AccountAccessDenied（未开通/欠费）；
	 *   - 黄：HTTP 4xx 参数类（MissingParameter/InvalidParameter*）；
	 *   - 灰：传输终态/非预期形态——给原文与排查方向，不误报三态。
	 *
	 * 纯逻辑；AJAX 边界在 SettingsPage::ajax_test_credentials_aliyun。
	 *
	 * @param RemoteResult $result 假参实调的传输脊结果。
	 * @return array{state: string, message: string, missing: string[], code: string}
	 */
	public static function classify_aliyun( RemoteResult $result ): array {
		$payload = array( 'state' => 'gray', 'message' => '', 'missing' => array(), 'code' => '' );

		$decoded = self::decoded( $result );

		// 阿里 API 错误形态：HTTP 4xx body {RequestId, Code, Message, AccessDeniedDetail?}。
		// 【真机定论 2026-11-11】HTTP 200 OK body 顶层同样带 Code='Success'——Code 判定仅
		// 在非 2xx 终态 consulted（2xx 直读 Result.VerifyCode，防绿态被误判灰态）。
		$code = ( ! $result->is_ok() && is_array( $decoded ) && isset( $decoded['Code'] ) && is_string( $decoded['Code'] ) )
			? $decoded['Code']
			: '';

		if ( '' !== $code ) {
			$payload['code'] = $code;

			// 红 1：AK/签名错（前缀通配——实测变体后缀不一）。
			if ( 0 === strpos( $code, 'InvalidAccessKeyId' ) || 0 === strpos( $code, 'SignatureDoesNotMatch' ) ) {
				$payload['state']   = 'red';
				$payload['message'] = __( 'Key/signature error: the AccessKey pair was rejected by Aliyun (', 'wink-wp-gatekeeper' ) . $code . __( ') — check it under “Access Management → RAM AccessKey Management” and retry.', 'wink-wp-gatekeeper' );
				return $payload;
			}

			// 红 2：403 Forbidden.NoPermission（RAM 未授权——L0 实测 AuthAction=yundun-afs:VerifyCaptcha）。
			if ( 'Forbidden.NoPermission' === $code ) {
				$auth_action = is_array( $decoded['AccessDeniedDetail'] ?? null ) && isset( $decoded['AccessDeniedDetail']['AuthAction'] )
					? (string) $decoded['AccessDeniedDetail']['AuthAction']
					: '';
				$payload['state']   = 'red';
				$payload['message'] = __( 'Insufficient permissions (RAM not authorized', 'wink-wp-gatekeeper' ) . ( '' !== $auth_action ? '：' . $auth_action : '' ) . __( ') — grant AliyunYundunAFSFullAccess to this RAM user and retry.', 'wink-wp-gatekeeper' );
				return $payload;
			}

			// 红 3：403 Forbidden.AccountAccessDenied（未开通/欠费）。
			if ( 'Forbidden.AccountAccessDenied' === $code ) {
				$payload['state']   = 'red';
				$payload['message'] = __( 'Service not enabled or account in arrears — enable the service / check the account balance in the Aliyun Captcha 2.0 console and retry.', 'wink-wp-gatekeeper' );
				return $payload;
			}

			// 黄：HTTP 级参数类（本地防呆提示——核对配置区取值）。
			if ( 0 === strpos( $code, 'MissingParameter' ) || 0 === strpos( $code, 'InvalidParameter' ) ) {
				$payload['state']   = 'yellow';
				$payload['message'] = __( 'Parameter error (', 'wink-wp-gatekeeper' ) . $code . __( ') — please check the values in the configuration area above and retry.', 'wink-wp-gatekeeper' );
				return $payload;
			}

			$payload['message'] = __( 'Server returned an error: ', 'wink-wp-gatekeeper' ) . $code . '——' . RemoteClient::redact( (string) ( $decoded['Message'] ?? '' ) ); // 【S4-F5】厂商原文过 redact。
			return $payload;
		}

		if ( ! $result->is_ok() ) {
			// 非 API 错误的传输终态（连接失败/超时/5xx 等）。
			$payload['message'] = __( 'Network unreachable or service temporarily unavailable (', 'wink-wp-gatekeeper' ) . $result->category() . __( ') — please try again later.', 'wink-wp-gatekeeper' );
			return $payload;
		}

		// HTTP 200：读 Result.VerifyCode——假参探测绿态 = F002/F003（业务层拒绝假参数）。
		$res        = is_array( $decoded ) && isset( $decoded['Result'] ) && is_array( $decoded['Result'] ) ? $decoded['Result'] : array();
		$verify_code = is_string( $res['VerifyCode'] ?? null ) ? (string) $res['VerifyCode'] : '';

		if ( in_array( $verify_code, array( 'F002', 'F003' ), true ) ) {
			$payload['state']   = 'green';
			$payload['code']    = 'VerifyCode=' . $verify_code;
			$payload['message'] = __( 'Credentials work (VerifyCode=', 'wink-wp-gatekeeper' ) . $verify_code . __( ', fake parameters normally rejected at the business layer — gateway auth + AccessKey + RAM all pass) — the front-end captcha should come up; if the comment area still shows loading, check the front-end network or theme scripts.', 'wink-wp-gatekeeper' );
			return $payload;
		}

		$payload['code']    = 'VerifyCode=' . ( '' !== $verify_code ? $verify_code : '?' );
		$payload['message'] = __( 'Unexpected verification return (VerifyCode=', 'wink-wp-gatekeeper' ) . $verify_code . __( ') — the credential and signing chain works, but the fake parameters were not rejected as expected; retry or screenshot this message for troubleshooting.', 'wink-wp-gatekeeper' );
		return $payload;
	}

	/**
	 * 阿里本地预检：四凭据字段有空 → 黄态逐字段列缺（不发远端调用——空 AccessKey 的
	 * ACS3 签名必失败且无诊断价值）；齐全 → null（放行走远端假参核验）。
	 *
	 * @param array<string, mixed> $aliyun captcha.aliyun 子树。
	 * @return array{state: string, message: string, missing: string[]}|null
	 */
	public static function local_precheck_aliyun( array $aliyun ): ?array {
		$missing = CaptchaTabSpec::aliyun_missing_fields( $aliyun );
		if ( array() === $missing ) {
			return null;
		}
		return array(
			'state'   => 'yellow',
			'message' => __( 'Required fields missing: ', 'wink-wp-gatekeeper' ) . implode( '、', $missing ) . __( ' — fill them in and test again.', 'wink-wp-gatekeeper' ),
			'missing' => $missing,
		);
	}

	/**
	 * 阿里文本审核增强版 PLUS「测试凭据」三态分类（B5；05 §2.5 凭据自检三态/03 Tab③【v1.5】；
	 * 判定法 = L0-ali-green-pro 实测定稿）。
	 *
	 * 发一次良性短文本（content=凭据自检，comment_detection_pro）真调用：
	 *   - 绿：body Code=200（RiskLevel none/low）——ACS3 签名+AK+RAM+服务链路全通（真调用，
	 *     不用空 content 探测——Code=400 语义模糊无法区分「参数防呆」与「链路通」）；
	 *   - 红：body Code=408（PERMISSION_DENY 未授权/欠费/未开通——文案指明需
	 *     AliyunYundunGreenWebFullAccess 或开通内容安全增强版）或 HTTP 4xx 签名类错
	 *     （InvalidAccessKeyId 前缀 / SignatureDoesNotMatch——AK/签名错）；
	 *   - 黄：body Code=400 参数类（核对配置区取值）；本地必填缺在 local_precheck_aliyun_green；
	 *   - 灰：传输终态 / Code=200 但 RiskLevel 非 none/low 的形态异常——给原文与排查方向。
	 *
	 * 绿态解除停转（02 §5「自检通过」）由 AJAX 边界执行 clear——本类保持纯逻辑。
	 *
	 * @param RemoteResult $result 良性短文本审核的传输脊结果。
	 * @return array{state: string, message: string, missing: string[], code: string}
	 */
	public static function classify_aliyun_green( RemoteResult $result ): array {
		$payload = array( 'state' => 'gray', 'message' => '', 'missing' => array(), 'code' => '' );

		$decoded = self::decoded( $result );

		// Green 增强版 body 顶层 Code：成功为整型 200（L0 实测）；HTTP 4xx 时为字符串码
		// （SignatureDoesNotMatch 等）。Code 判定在非 200 时消费（与 TMS body 级惯例一致）。
		$code = '';
		if ( is_array( $decoded ) ) {
			$raw_code = $decoded['Code'] ?? null;
			if ( is_int( $raw_code ) ) {
				$raw_code = (string) $raw_code;
			}
			if ( is_string( $raw_code ) && '200' !== $raw_code ) {
				$code = $raw_code;
			}
		}

		if ( '' !== $code ) {
			$payload['code'] = $code;

			// 红 1：AK/签名错（前缀通配——实测变体后缀不一，如 InvalidAccessKeyId.NotFound）。
			if ( 0 === strpos( $code, 'InvalidAccessKeyId' ) || 0 === strpos( $code, 'SignatureDoesNotMatch' ) ) {
				$payload['state']   = 'red';
				$payload['message'] = __( 'Key/signature error: the AccessKey pair was rejected by Aliyun (', 'wink-wp-gatekeeper' ) . $code . __( ') — check it under “Access Management → RAM AccessKey Management” and retry.', 'wink-wp-gatekeeper' );
				return $payload;
			}

			// 红 2：408 PERMISSION_DENY（未授权/欠费/未开通——05 §2.5 error_map 语义档）。
			if ( '408' === $code ) {
				$payload['state']   = 'red';
				$payload['message'] = __( 'Insufficient permissions (PERMISSION_DENY): Text Moderation Plus is not authorized / in arrears / not enabled — grant AliyunYundunGreenWebFullAccess to this RAM user, or enable “Text Moderation Plus” in the Aliyun Content Security console and retry.', 'wink-wp-gatekeeper' );
				return $payload;
			}

			// 黄：body 400 参数类（本地防呆提示——核对配置区取值；不用于绿态判定）。
			if ( '400' === $code ) {
				$payload['state']   = 'yellow';
				$payload['message'] = __( 'Parameter error (Code=400', 'wink-wp-gatekeeper' ) . ( isset( $decoded['Message'] ) && is_string( $decoded['Message'] ) && '' !== $decoded['Message'] ? '：' . RemoteClient::redact( $decoded['Message'] ) : '' ) . __( ') — please check the values in the configuration area above and retry.', 'wink-wp-gatekeeper' ); // 【S4-F5】厂商原文过 redact。
				return $payload;
			}

			$payload['message'] = __( 'Server returned an error: ', 'wink-wp-gatekeeper' ) . $code . '——' . RemoteClient::redact( (string) ( $decoded['Message'] ?? '' ) ); // 【S4-F5】厂商原文过 redact。
			return $payload;
		}

		if ( ! $result->is_ok() ) {
			// 非 API 错误的传输终态（连接失败/超时/5xx 等）。
			$payload['message'] = __( 'Network unreachable or service temporarily unavailable (', 'wink-wp-gatekeeper' ) . $result->category() . __( ') — please try again later.', 'wink-wp-gatekeeper' );
			return $payload;
		}

		// body Code=200：读 Data.RiskLevel——绿态判据（探测样本应为干净文本 none/low）。
		$data = is_array( $decoded ) && is_array( $decoded['Data'] ?? null ) ? $decoded['Data'] : array();
		$risk = is_string( $data['RiskLevel'] ?? null ) ? $data['RiskLevel'] : '';

		if ( in_array( $risk, array( 'none', 'low' ), true ) ) {
			$payload['state']   = 'green';
			$payload['code']    = 'Code=200';
			$payload['message'] = __( 'Credentials work (Code=200, probe text RiskLevel=', 'wink-wp-gatekeeper' ) . $risk . __( ') — the moderation chain is connected; once enabled, comments will be submitted through “local rules → Aliyun Text Moderation Plus” in series.', 'wink-wp-gatekeeper' );
			return $payload;
		}

		$payload['code']    = 'RiskLevel=' . ( '' !== $risk ? $risk : '?' );
		$payload['message'] = __( 'Unexpected verdict return (RiskLevel=', 'wink-wp-gatekeeper' ) . $risk . __( ') — the credential and signing chain works, but the probe text did not get a clean verdict; retry or screenshot this message for troubleshooting.', 'wink-wp-gatekeeper' );
		return $payload;
	}

	/**
	 * 阿里 PLUS 本地预检：AK 两键或双 Service 名有空 → 黄态逐字段列缺（不发远端调用——
	 * 空 AccessKey 的 ACS3 签名必失败且无诊断价值；05 §2.5 黄态「Service 名配置为空」同档）；
	 * 齐全 → null（放行走远端探测）。
	 *
	 * @param array<string, mixed> $green review.cloud.aliyun_green 子树。
	 * @return array{state: string, message: string, missing: string[]}|null
	 */
	public static function local_precheck_aliyun_green( array $green ): ?array {
		$meta    = \WinkGatekeeper\Drivers\Review\AliyunGreenPlusDriver::registry_entry();
		$missing = DriverConfigSpec::missing_fields( $meta, $green );
		if ( array() === $missing ) {
			return null;
		}
		return array(
			'state'   => 'yellow',
			'message' => __( 'Required fields missing: ', 'wink-wp-gatekeeper' ) . implode( '、', $missing ) . __( ' — fill them in and test again.', 'wink-wp-gatekeeper' ),
			'missing' => $missing,
		);
	}

	/**
	 * 百度文本审核「测试凭据」三态分类（B6；05 §2.4 凭据自检三态 + 03 Tab③）。
	 *
	 * 发一次良性样本文本真调用（token 流含在内——探测即全链路体检）：
	 *   - 绿：conclusionType=1（合规）——client_credentials token 流 + 审核端点链路全通；
	 *   - 红：error_code 110/111（access_token 无效/过期——API Key/Secret Key 错或被重置，
	 *     文案指明控制台应用列表核对）、6（无权限——未开通文本审核服务）；
	 *   - 黄：error_code 100（无效参数）；本地必填缺在 local_precheck_baidu；
	 *   - 灰：传输终态 / error_code 17/19（配额超限）/ 其它未知码 / 非 1 的 conclusionType
	 *     ——给原文与排查方向，不误报三态。
	 *
	 * 绿态解除停转（02 §5「自检通过」）由 AJAX 边界执行 clear——本类保持纯逻辑。
	 *
	 * @param RemoteResult $result 良性样本文本审核的传输脊结果。
	 * @return array{state: string, message: string, missing: string[], code: string}
	 */
	public static function classify_baidu( RemoteResult $result ): array {
		$payload = array( 'state' => 'gray', 'message' => '', 'missing' => array(), 'code' => '' );

		$decoded = self::decoded( $result );

		// 百度业务错误码在 2xx body 顶层（error_code/error_msg 形态）。
		if ( is_array( $decoded ) && isset( $decoded['error_code'] ) && is_numeric( $decoded['error_code'] ) ) {
			$code            = (int) $decoded['error_code'];
			$error_msg       = is_string( $decoded['error_msg'] ?? null ) ? $decoded['error_msg'] : '';
			$payload['code'] = 'error_code=' . $code;

			// 红 1：token 鉴权失败（AK/SK 错或被重置——110 无效/111 过期）。
			if ( 110 === $code || 111 === $code ) {
				$payload['state']   = 'red';
				$payload['message'] = __( 'Authentication failed (', 'wink-wp-gatekeeper' ) . $code . '：access_token ' . ( 110 === $code ? __( 'invalid', 'wink-wp-gatekeeper' ) : __( 'Expired', 'wink-wp-gatekeeper' ) ) . __( '): the API Key/Secret Key was rejected by Baidu Cloud — check the API Key and Secret Key under “Baidu AI Cloud Console → App List” and retry.', 'wink-wp-gatekeeper' );
				return $payload;
			}

			// 红 2：无权限调用（未开通文本审核服务）。
			if ( 6 === $code ) {
				$payload['state']   = 'red';
				$payload['message'] = __( 'Not authorized to call (error_code=6): the text moderation service is not enabled — enable “Content Moderation · Text Moderation” in the Baidu AI Cloud console and retry.', 'wink-wp-gatekeeper' );
				return $payload;
			}

			// 黄：参数错（本地防呆提示——核对配置区取值）。
			if ( 100 === $code ) {
				$payload['state']   = 'yellow';
				$payload['message'] = __( 'Parameter error (error_code=100', 'wink-wp-gatekeeper' ) . ( '' !== $error_msg ? '：' . RemoteClient::redact( $error_msg ) : '' ) . __( ') — please check the values in the configuration area above and retry.', 'wink-wp-gatekeeper' ); // 【S4-F5】厂商原文过 redact。
				return $payload;
			}

			// 灰：配额超限/其它未知码——给原文与排查方向。
			$hint = ( 17 === $code || 19 === $code ) ? __( '(quota exceeded — check the app’s quota / payment status in the Baidu AI Cloud console)', 'wink-wp-gatekeeper' ) : '';
			$payload['message'] = __( 'Server returned an error (error_code=', 'wink-wp-gatekeeper' ) . $code . '——' . RemoteClient::redact( $error_msg ) . '）' . $hint . __( ' — please screenshot this message for troubleshooting.', 'wink-wp-gatekeeper' ); // 【S4-F5】厂商原文过 redact。
			return $payload;
		}

		if ( ! $result->is_ok() ) {
			// 传输/语义终态：semantic（token 端点 400/401——invalid_client 等）= 鉴权失败红态
			// （凭据被拒的直接证据；百度 oauth 错误码不一定走审核端点 error_code 通道）；
			// transport = 网络不可达灰态。
			if ( RemoteResult::SEMANTIC === $result->category() ) {
				$payload['state']   = 'red';
				$payload['code']    = 'HTTP ' . $result->status();
				$payload['message'] = __( 'Authentication failed (HTTP ', 'wink-wp-gatekeeper' ) . $result->status() . __( '): the API Key/Secret Key was rejected by Baidu Cloud — check the API Key and Secret Key under “Baidu AI Cloud Console → App List” and retry.', 'wink-wp-gatekeeper' );
				return $payload;
			}
			$payload['message'] = __( 'Network unreachable or service temporarily unavailable (', 'wink-wp-gatekeeper' ) . $result->category() . __( ') — please try again later.', 'wink-wp-gatekeeper' );
			return $payload;
		}

		// HTTP 200：读 conclusionType——绿态判据（探测样本应为干净文本=1 合规）。
		$type = isset( $decoded['conclusionType'] ) && is_numeric( $decoded['conclusionType'] )
			? (int) $decoded['conclusionType']
			: 0;

		if ( 1 === $type ) {
			$payload['state']   = 'green';
			$payload['code']    = 'conclusionType=1';
			$payload['message'] = __( 'Credentials work (conclusionType=1, probe text compliant) — the moderation chain is connected; once enabled, comments will be submitted through “local rules → Baidu Text Moderation” in series.', 'wink-wp-gatekeeper' );
			return $payload;
		}

		$payload['code']    = 'conclusionType=' . ( $type > 0 ? (string) $type : '?' );
		$payload['message'] = __( 'Unexpected verdict return (conclusionType=', 'wink-wp-gatekeeper' ) . $type . __( ') — the credential and token chain works, but the probe text was not judged compliant; retry or screenshot this message for troubleshooting.', 'wink-wp-gatekeeper' );
		return $payload;
	}

	/**
	 * 百度本地预检：API Key / Secret Key 有空 → 黄态逐字段列缺（不发远端调用——空凭据的
	 * token 流必失败且无诊断价值）；齐全 → null（放行走远端探测）。
	 *
	 * @param array<string, mixed> $baidu review.cloud.baidu 子树。
	 * @return array{state: string, message: string, missing: string[]}|null
	 */
	public static function local_precheck_baidu( array $baidu ): ?array {
		$meta    = \WinkGatekeeper\Drivers\Review\BaiduTextModerationDriver::registry_entry();
		$missing = DriverConfigSpec::missing_fields( $meta, $baidu );
		if ( array() === $missing ) {
			return null;
		}
		return array(
			'state'   => 'yellow',
			'message' => __( 'Required fields missing: ', 'wink-wp-gatekeeper' ) . implode( '、', $missing ) . __( ' — fill them in and test again.', 'wink-wp-gatekeeper' ),
			'missing' => $missing,
		);
	}

	/**
	 * Tab④ 分区②「测试凭据」三态分类（M3-IP 定稿 §6【O5 回写·P2-6】+ 03 Tab④【v1.7·M3-IP】，
	 * I4 收官批；判定法=O5-P2-6 拍板）。
	 *
	 * 发一次最小 check 查询（AbuseIpdbService::probe_credentials——保留地址 127.0.0.2 +
	 * maxAgeInDays=1 省配额）：
	 *   - 绿：HTTP 200 且 body JSON 可解析——**isPublic:false 亦绿**（验证的是凭据不是 IP，
	 *     保留地址必回 isPublic:false 是正常业务响应）；
	 *   - 红：HTTP 401/402（AbuseIPDB 错误面全部经 HTTP 状态码表达——密钥被拒/订阅或配额不可用）；
	 *   - 灰：HTTP 429（限流——凭据有效性未知不误报红档）/ 传输终态（超时/网络不可达）/
	 *     HTTP 200 但 body 非 JSON（契约不符）——给原文与排查方向。
	 *
	 * 纯逻辑零 WP 依赖；AJAX 边界在 SettingsPage::ajax_test_iprep。
	 *
	 * @param RemoteResult $result 最小 check 查询的传输脊结果。
	 * @return array{state: string, message: string, missing: string[], code: string}
	 */
	public static function classify_abuseipdb( RemoteResult $result ): array {
		$payload = array( 'state' => 'gray', 'message' => '', 'missing' => array(), 'code' => '' );
		$status  = $result->status();

		// 红：401（密钥无效）/402（订阅或每日配额不可用）——密钥面红档。
		if ( 401 === $status || 402 === $status ) {
			$payload['code']    = 'HTTP ' . $status;
			$payload['state']   = 'red';
			$payload['message'] = __( 'Key rejected (HTTP ', 'wink-wp-gatekeeper' ) . $status . ( 401 === $status ? __( ': API Key invalid', 'wink-wp-gatekeeper' ) : __( ': subscription or daily quota unavailable', 'wink-wp-gatekeeper' ) ) . __( ') — check the API Key and subscription status in the AbuseIPDB console and retry.', 'wink-wp-gatekeeper' );
			return $payload;
		}

		// 灰：429 限流——凭据可能有效，不误报红档（给排查方向）。
		if ( 429 === $status ) {
			$payload['code']    = 'HTTP 429';
			$payload['message'] = __( 'HTTP 429 (rate limited — free-tier rate / daily quota constraints). Credential validity could not be confirmed this time — try again later, or check the quota in the AbuseIPDB console.', 'wink-wp-gatekeeper' );
			return $payload;
		}

		// 绿态判据：HTTP 200 且 body JSON 可解析（isPublic:false 亦绿——O5-P2-6）。
		// 契约判定先于传输兜底——200 但不可解析须报「契约不符」而非「网络不可达」（防误诊）。
		$body    = $result->body();
		$decoded = ( $result->is_ok() || RemoteResult::PARSE === $result->category() || RemoteResult::CONTRACT === $result->category() )
			? ( '' !== $body ? json_decode( $body, true ) : null ) : null;
		if ( is_array( $decoded ) && $result->is_ok() ) {
			$is_public = is_array( $decoded['data'] ?? null ) && array_key_exists( 'isPublic', $decoded['data'] )
				? (bool) $decoded['data']['isPublic'] : null;
			$payload['state']   = 'green';
			$payload['code']    = 'HTTP 200 + JSON';
			$payload['message'] = __( 'Credentials work (HTTP 200, response JSON parsed) — the lookup chain is connected.', 'wink-wp-gatekeeper' )
				. ( false === $is_public ? __( 'This lookup used a reserved address (isPublic:false is also a green state — it validates the credentials, not the IP).', 'wink-wp-gatekeeper' ) : '' );
			return $payload;
		}
		if ( null !== $decoded || RemoteResult::PARSE === $result->category() || RemoteResult::CONTRACT === $result->category() ) {
			// 2xx 但坏 JSON（传输脊归 PARSE）或其它契约形态——给排查方向不误报三态。
			$payload['message'] = 'HTTP ' . ( $status > 0 ? $status : '200' ) . __( ' but the response is not valid JSON (contract mismatch — possibly hijacked by a middle layer / returned HTML) — please screenshot this message for troubleshooting.', 'wink-wp-gatekeeper' );
			return $payload;
		}

		if ( ! $result->is_ok() ) {
			// 非 API 错误的传输终态（连接失败/超时/5xx 等）。
			$payload['message'] = __( 'Network unreachable or service temporarily unavailable (', 'wink-wp-gatekeeper' ) . $result->category() . __( ') — please try again later (networks without an international exit will keep seeing this state).', 'wink-wp-gatekeeper' );
			return $payload;
		}

		// 200 且 body 非空但解析为空串等剩余形态——契约兜底。
		$payload['message'] = __( 'HTTP 200 but the response is not valid JSON (contract mismatch — possibly hijacked by a middle layer / returned HTML) — please screenshot this message for troubleshooting.', 'wink-wp-gatekeeper' );
		return $payload;
	}

	/**
	 * Tab④ 分区② 本地预检：API Key 空（含纯空白）→ 黄态（不发远端调用——空 Key 的请求
	 * 必 401 且无诊断价值）；非空 → null（放行走远端最小查询）。
	 *
	 * @param string $api_key 已并入表单覆盖语义的 API Key。
	 * @return array{state: string, message: string, missing: string[]}|null
	 */
	public static function local_precheck_abuseipdb( string $api_key ): ?array {
		if ( '' !== trim( $api_key ) ) {
			return null;
		}
		return array(
			'state'   => 'yellow',
			'message' => __( 'Required field missing: API Key — fill it in above and save first (or just retest after filling), then click “Test credentials”.', 'wink-wp-gatekeeper' ),
			'missing' => array( 'API Key' ),
		);
	}

	/**
	 * 解析 body JSON（4xx 语义分类不 decode data，此处自行解码读 Response.Error）。
	 *
	 * @param RemoteResult $result
	 * @return array|null
	 */
	private static function decoded( RemoteResult $result ): ?array {
		$body = $result->body();
		if ( '' === $body ) {
			return null;
		}
		$decoded = json_decode( $body, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * 从 MissingParameter 消息中解析参数名（腾讯云形态：反引号包裹，如 `AppSecretKey`）。
	 *
	 * @param string $message
	 * @return string[]
	 */
	private static function parse_missing_params( string $message ): array {
		$matches = array();
		if ( ! preg_match_all( '/`([^`]+)`/', $message, $matches ) ) {
			return array();
		}
		return array_values( array_unique( $matches[1] ) );	}

	/**
	 * DeepSeek /models 探活三态判定（【M5·U4】D5 裁决：GET /models 零 token 成本证
	 * Bearer 鉴权通——绿=HTTP 200 且返回列表含判定模型；黄=本地预检缺参（另法）；红=
	 * 401/402 密钥被拒或账户欠费；灰=传输/其它 HTTP 异常）。
	 *
	 * @param RemoteResult $result GET {base_url}/models 结果。
	 * @param string       $model  判定模型名（绿态含此模型校验）。
	 * @return array{state: string, message: string, missing: array<string>, code: string}
	 */
	public static function classify_deepseek( RemoteResult $result, string $model = '' ): array {
		$payload = array( 'state' => 'gray', 'message' => '', 'missing' => array(), 'code' => '' );

		// 语义类（RemoteClient classify：4xx=semantic；401/402 明列设计档）。
		if ( ! $result->is_ok() ) {
			$status          = $result->status();
			$payload['code'] = 'http=' . $status;
			if ( 401 === $status || 402 === $status || 403 === $status ) {
				$payload['state']   = 'red';
				$payload['message'] = 402 === $status
					? __( 'Key rejected (402, account in arrears): top up on the DeepSeek open platform and retry.', 'wink-wp-gatekeeper' )
					: __( 'Key rejected (HTTP ', 'wink-wp-gatekeeper' ) . $status . __( '): API Key invalid — check it on the DeepSeek open platform and retry.', 'wink-wp-gatekeeper' );
				return $payload;
			}
			$payload['message'] = __( 'Probe unsuccessful (HTTP ', 'wink-wp-gatekeeper' ) . $status . '，' . $result->category() . __( ') — retry later; if it keeps failing, check the endpoint URL.', 'wink-wp-gatekeeper' );
			return $payload;
		}

		// 2xx：绿态=JSON 可解析且模型列表含判定模型。
		$decoded = self::decoded( $result );
		$ids     = array();
		if ( is_array( $decoded ) && isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
			foreach ( $decoded['data'] as $item ) {
				if ( is_array( $item ) && isset( $item['id'] ) && is_string( $item['id'] ) ) {
					$ids[] = $item['id'];
				}
			}
		}
		if ( array() === $ids ) {
			$payload['state']   = 'gray';
			$payload['code']    = 'contract';
			$payload['message'] = __( 'Response contains no model list (contract mismatch) — the endpoint may not be the official DeepSeek form; check the endpoint URL.', 'wink-wp-gatekeeper' );
			return $payload;
		}
		if ( '' !== $model && ! in_array( $model, $ids, true ) ) {
			$payload['state']   = 'yellow';
			$payload['code']    = 'model_missing';
			$payload['message'] = __( 'Credentials work, but the verdict model “', 'wink-wp-gatekeeper' ) . $model . __( '” is not in the available list (', 'wink-wp-gatekeeper' ) . implode( ' / ', array_slice( $ids, 0, 6 ) ) . __( ') — pick a different one from the “Verdict model” dropdown.', 'wink-wp-gatekeeper' );
			return $payload;
		}
		$payload['state']   = 'green';
		$payload['code']    = 'models_ok';
		$payload['message'] = __( 'Credentials work (model-list probe passed, zero token cost).', 'wink-wp-gatekeeper' );
		return $payload;
	}

	/**
	 * DeepSeek 本地预检（黄态：API Key 空——零外呼）。
	 *
	 * @param string $api_key API Key。
	 * @return array{state: string, message: string, missing: array<string>, code: string}|null null=预检通过。
	 */
	public static function local_precheck_deepseek( string $api_key ): ?array {
		if ( '' === trim( $api_key ) ) {
			return array(
				'state'   => 'yellow',
				'message' => __( 'Missing parameter: API Key is not filled in — fill it in and retry.', 'wink-wp-gatekeeper' ),
				'missing' => array( 'api_key' ),
				'code'    => '',
			);
		}
		return null;
	}

	/**
	 * DeepSeek 余额探测三态判定（【M6·批B·N4】GET /user/balance 零费用端点——信息量
	 * 升级：Key 有效性+账户可用性+可见余额一次拿全；D5「零 token 成本」裁决精神不变）。
	 *
	 * 官方契约（api/查询余额.md）：200 → {is_available, balance_infos[{currency(CNY|USD),
	 * total_balance, granted_balance, topped_up_balance}]}。
	 *
	 * 三态：绿=200 且 is_available=true（响应附 balance 对象 currency/total_balance/
	 * granted_balance；人话行「余额 110.00 CNY（含赠金 10.00）」——零赠金省略赠金段）；
	 * 黄=200 且 is_available=false（欠费/不可用）；红=401/402/403（密钥被拒/欠费）与
	 * transport（连接不上）。灰=200 但响应不含余额结构（契约异常——诚实兜底，
	 * 不虚构三态）。
	 *
	 * 响应闭集纪律：{state, message, missing, code, balance}——不含提交值（凭据零回显
	 * 契约照查，03 §1 敏感字段契约）。
	 *
	 * @param RemoteResult $result GET {base_url}/user/balance 结果。
	 * @return array{state: string, message: string, missing: array<string>, code: string, balance: array{currency: string, total_balance: string, granted_balance: string}|null}
	 */
	public static function classify_deepseek_balance( RemoteResult $result ): array {
		$payload = array( 'state' => 'gray', 'message' => '', 'missing' => array(), 'code' => '', 'balance' => null );

		// 语义/传输类（RemoteClient classify：4xx=semantic；401/402 明列设计档）。
		if ( ! $result->is_ok() ) {
			$status          = $result->status();
			$payload['code'] = 'http=' . $status;
			if ( 401 === $status || 402 === $status || 403 === $status ) {
				$payload['state']   = 'red';
				$payload['message'] = 402 === $status
					? __( 'Key valid but the account is in arrears (402): top up on the DeepSeek open platform and retry.', 'wink-wp-gatekeeper' )
					: __( 'Key rejected (HTTP ', 'wink-wp-gatekeeper' ) . $status . __( '): API Key invalid — check it on the DeepSeek open platform and retry.', 'wink-wp-gatekeeper' );
				return $payload;
			}
			if ( RemoteResult::TRANSPORT === $result->category() ) {
				$payload['state']   = 'red';
				$payload['message'] = __( 'Cannot reach DeepSeek (network issue) — retry later; if it keeps failing, check the server’s outbound network.', 'wink-wp-gatekeeper' );
				return $payload;
			}
			$payload['state']   = 'red';
			$payload['message'] = __( 'Detection unsuccessful (HTTP ', 'wink-wp-gatekeeper' ) . $status . '，' . $result->category() . __( ') — retry later; if it keeps failing, check the endpoint URL.', 'wink-wp-gatekeeper' );
			return $payload;
		}

		// 2xx：绿/黄态=JSON 可解析且 is_available 键在场。
		$decoded = self::decoded( $result );
		if ( ! is_array( $decoded ) || ! array_key_exists( 'is_available', $decoded ) ) {
			$payload['state']   = 'gray';
			$payload['code']    = 'contract';
			$payload['message'] = __( 'Response contains no balance structure (contract mismatch) — the endpoint may not be the official DeepSeek form; check the endpoint URL (for standalone endpoints without a standard balance API, use the model-list probe).', 'wink-wp-gatekeeper' );
			return $payload;
		}

		$balance = self::balance_object( $decoded );
		if ( true === $decoded['is_available'] ) {
			$payload['state']   = 'green';
			$payload['code']    = 'balance_ok';
			$payload['balance'] = $balance;
			$payload['message'] = self::balance_message( $balance );
			return $payload;
		}

		$payload['state']   = 'yellow';
		$payload['code']    = 'balance_unavailable';
		$payload['balance'] = $balance;
		$payload['message'] = __( 'The account cannot be called right now (balance unavailable, possibly in arrears) — top up on the DeepSeek open platform and retry.', 'wink-wp-gatekeeper' );
		return $payload;
	}

	/**
	 * balance_infos[0] → balance 对象（缺失键归空串——不虚构数字）。
	 *
	 * @param array<string, mixed> $decoded 已解码响应。
	 * @return array{currency: string, total_balance: string, granted_balance: string}|null
	 */
	private static function balance_object( array $decoded ): ?array {
		$infos = $decoded['balance_infos'] ?? null;
		$first = is_array( $infos ) && isset( $infos[0] ) && is_array( $infos[0] ) ? $infos[0] : array();
		if ( array() === $first ) {
			return null;
		}
		return array(
			'currency'        => is_string( $first['currency'] ?? null ) ? (string) $first['currency'] : '',
			'total_balance'   => is_string( $first['total_balance'] ?? null ) ? (string) $first['total_balance'] : '',
			'granted_balance' => is_string( $first['granted_balance'] ?? null ) ? (string) $first['granted_balance'] : '',
		);
	}

	/**
	 * 绿态人话行（「余额 110.00 CNY（含赠金 10.00）」——零赠金省略赠金段不虚构）。
	 *
	 * @param array{currency: string, total_balance: string, granted_balance: string}|null $balance 余额对象。
	 * @return string
	 */
	private static function balance_message( ?array $balance ): string {
		$currency = is_array( $balance ) ? (string) ( $balance['currency'] ?? '' ) : '';
		$total    = is_array( $balance ) ? (string) ( $balance['total_balance'] ?? '' ) : '';
		$granted  = is_array( $balance ) ? (string) ( $balance['granted_balance'] ?? '' ) : '';
		if ( '' === $total ) {
			return __( 'Credentials work (balance probe passed, zero cost) — the account works; no balance information was returned.', 'wink-wp-gatekeeper' );
		}
		$line = __( 'Credentials work (balance probe passed, zero cost). Balance ', 'wink-wp-gatekeeper' ) . $total . ( '' !== $currency ? ' ' . $currency : '' );
		if ( '' !== $granted && 0.0 < (float) $granted ) {
			$line .= __( ' (including grants ', 'wink-wp-gatekeeper' ) . $granted . '）';
		}
		return $line . '。';
	}

	/**
	 * openai_custom /models 探活三态判定（【M6·批E·E-3】泛端点无标准余额 API——
	 * GET {base_url}/models 零 token 消耗证 Bearer 鉴权通；classify_deepseek 同构
	 * 泛化，文案中性化不点名厂商）。
	 *
	 * 三态口径（设计 §3.1）：
	 *   - 红=401/403（密钥被端点拒绝）/402（归红但计费口径由服务方定义——中性文案）；
	 *   - 绿=200 且 data[].id[] 可解析（OpenAI /models 契约形态）；含手填模型则绿态
	 *     升级为「列表含该模型」；
	 *   - 黄=列表不含手填模型（泛端点列表可能不全——列表缺失不等于不可用，黄不红）；
	 *   - 灰=契约不符 / 404 形态（部分网关不提供 /models——凭据状态未知，可跳过测试
	 *     直接启用观察首判）/ transport 终态。
	 *
	 * 响应闭集纪律：{state, message, missing, code}——不含提交值（凭据零回显）。
	 *
	 * @param RemoteResult $result GET {base_url}/models 结果。
	 * @param string       $model  手填判定模型名（空=纯探活不校验列表）。
	 * @return array{state: string, message: string, missing: array<string>, code: string}
	 */
	public static function classify_openai_custom_models( RemoteResult $result, string $model = '' ): array {
		$payload = array( 'state' => 'gray', 'message' => '', 'missing' => array(), 'code' => '' );

		// 语义/传输类（RemoteClient classify：4xx=semantic）。
		if ( ! $result->is_ok() ) {
			$status          = $result->status();
			$payload['code'] = 'http=' . $status;
			if ( 401 === $status || 403 === $status ) {
				$payload['state']   = 'red';
				$payload['message'] = __( 'Key rejected by the endpoint (HTTP ', 'wink-wp-gatekeeper' ) . $status . __( ') — check the API Key and your access to this endpoint and retry.', 'wink-wp-gatekeeper' );
				return $payload;
			}
			if ( 402 === $status ) {
				$payload['state']   = 'red';
				$payload['message'] = __( 'Endpoint rejected (402) — check the key / account status (billing semantics for this endpoint are defined by its operator).', 'wink-wp-gatekeeper' );
				return $payload;
			}
			if ( 404 === $status ) {
				$payload['message'] = __( 'The endpoint may not provide a /models list (some gateways do not) — credential status unknown; you may skip the test, enable it, and watch the first verdict.', 'wink-wp-gatekeeper' );
				return $payload;
			}
			$payload['message'] = __( 'Probe unsuccessful (HTTP ', 'wink-wp-gatekeeper' ) . $status . '，' . $result->category() . __( ') — retry later; if it keeps failing, check the endpoint URL (mind the path prefix: the plugin appends /chat/completions and /models to whatever you enter).', 'wink-wp-gatekeeper' );
			return $payload;
		}

		// 2xx：绿态=JSON 可解析且 data[].id[] 模型列表在场（OpenAI /models 契约形态）。
		$decoded = self::decoded( $result );
		$ids     = array();
		if ( is_array( $decoded ) && isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
			foreach ( $decoded['data'] as $item ) {
				if ( is_array( $item ) && isset( $item['id'] ) && is_string( $item['id'] ) ) {
					$ids[] = $item['id'];
				}
			}
		}
		if ( array() === $ids ) {
			$payload['state']   = 'gray';
			$payload['code']    = 'contract';
			$payload['message'] = __( 'Response contains no model list (contract mismatch) — check whether the endpoint URL is OpenAI-compatible (mind the path prefix).', 'wink-wp-gatekeeper' );
			return $payload;
		}
		if ( '' !== $model && ! in_array( $model, $ids, true ) ) {
			$payload['state']   = 'yellow';
			$payload['code']    = 'model_missing';
			$payload['message'] = __( 'Credentials work, but the model “', 'wink-wp-gatekeeper' ) . $model . __( '” is not in the available list (', 'wink-wp-gatekeeper' ) . implode( ' / ', array_slice( $ids, 0, 6 ) ) . __( ') — check the model name you entered (some endpoint lists are incomplete; a missing entry does not mean unusable).', 'wink-wp-gatekeeper' );
			return $payload;
		}
		$payload['state']   = 'green';
		$payload['code']    = 'models_ok';
		$payload['message'] = '' !== $model
			? __( 'Credentials work (model-list probe passed, “', 'wink-wp-gatekeeper' ) . $model . __( '” is in the available list, zero token cost).', 'wink-wp-gatekeeper' )
			: __( 'Credentials work (model-list probe passed, zero token cost).', 'wink-wp-gatekeeper' );
		return $payload;
	}

	/**
	 * openai_custom 本地预检（【M6·批E】api_key/base_url/model 三键任一空=黄态逐字段
	 * 列缺——DriverConfigSpec::missing_fields 派生，aliyun_green 先例同款；零外呼）。
	 *
	 * @param array<string, mixed> $sub review.cloud.openai_custom 子树（表单覆盖后）。
	 * @return array{state: string, message: string, missing: string[]}|null null=预检通过。
	 */
	public static function local_precheck_openai_custom( array $sub ): ?array {
		$meta    = \WinkGatekeeper\Drivers\Review\OpenAiCustomReviewDriver::registry_entry();
		$missing = DriverConfigSpec::missing_fields( $meta, $sub );
		if ( array() === $missing ) {
			return null;
		}
		return array(
			'state'   => 'yellow',
			'message' => __( 'Required fields missing: ', 'wink-wp-gatekeeper' ) . implode( '、', $missing ) . __( ' — fill them in and test again.', 'wink-wp-gatekeeper' ),
			'missing' => $missing,
		);
	}
}
