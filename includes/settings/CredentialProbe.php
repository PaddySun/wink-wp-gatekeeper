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
			'message' => '必填字段缺少：' . implode( '、', $missing ) . '——补齐后重新测试。',
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
				$payload['message'] = '密钥错误（SecretId/SecretKey）：CAM 密钥对被腾讯云拒绝——请到「访问管理 → API 密钥管理」核对后重试。';
				return $payload;
			}
			if ( 'MissingParameter' === $error['Code'] ) {
				$missing           = self::parse_missing_params( isset( $error['Message'] ) && is_string( $error['Message'] ) ? $error['Message'] : '' );
				$payload['state']  = 'yellow';
				$payload['missing'] = $missing;
				$payload['message'] = '必填参数缺失：' . ( array() !== $missing ? implode( '、', $missing ) : (string) ( $error['Message'] ?? '' ) ) . '——请补齐上方对应字段。';
				return $payload;
			}
			$payload['message'] = '服务端返回错误：' . $error['Code'] . '——' . (string) ( $error['Message'] ?? '' );
			return $payload;
		}

		if ( ! $result->is_ok() ) {
			// 非 API 错误的传输终态（连接失败/超时等）。
			$payload['message'] = '网络不可达或服务暂不可用（' . $result->category() . ')——请稍后再试。';
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
			$payload['message'] = '凭据可用（CaptchaCode=' . $code . '，全参假票的正常业务拒绝）——前端验证码应可拉起；若评论区仍显示加载中，请查前端网络或主题脚本。';
			return $payload;
		}

		// 非预期码（16 appid 不匹配/100 参数校验错/21 票据异常等）：灰态给排查方向。
		$hint = in_array( $code, array( 16, 100 ), true )
			? '（大概率 CaptchaAppId 与 AppSecretKey 不匹配——请到验证码控制台【验证管理 → 基础配置】核对同页两值）'
			: '';
		$payload['code']    = 'CaptchaCode=' . $code;
		$payload['message'] = '未预期的核验返回（CaptchaCode=' . $code . ( '' !== $msg ? '：' . $msg : '' ) . '）' . $hint . '——请截图此信息联系排查。';
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
				$payload['message'] = '密钥错误（SecretId/SecretKey）：CAM 密钥对被腾讯云拒绝——请到「访问管理 → API 密钥管理」核对后重试。';
				return $payload;
			}
			if ( 0 === strpos( $error['Code'], 'UnauthorizedOperation' ) ) {
				$payload['state']   = 'red';
				$payload['message'] = '未获接口授权：TMS 未开通/无有效套餐包/账号已欠费——请到内容安全控制台开通文本审核套餐包后重试。';
				return $payload;
			}
			if ( 'MissingParameter' === $error['Code'] ) {
				$missing            = self::parse_missing_params( isset( $error['Message'] ) && is_string( $error['Message'] ) ? $error['Message'] : '' );
				$payload['state']   = 'yellow';
				$payload['missing'] = $missing;
				$payload['message'] = '必填参数缺失：' . ( array() !== $missing ? implode( '、', $missing ) : (string) ( $error['Message'] ?? '' ) ) . '——请检查上方配置。';
				return $payload;
			}
			if ( 0 === strpos( $error['Code'], 'InvalidParameter' ) ) {
				$payload['state']   = 'yellow';
				$payload['message'] = '参数错误（' . $error['Code'] . '）——请核对上方参数取值（如 BizType 策略编号）后重试。';
				return $payload;
			}
			$payload['message'] = '服务端返回错误：' . $error['Code'] . '——' . (string) ( $error['Message'] ?? '' );
			return $payload;
		}

		if ( ! $result->is_ok() ) {
			$payload['message'] = '网络不可达或服务暂不可用（' . $result->category() . '）——请稍后再试。';
			return $payload;
		}

		$response = is_array( $result->data() ) && isset( $result->data()['Response'] ) && is_array( $result->data()['Response'] )
			? $result->data()['Response']
			: array();
		$suggestion = is_string( $response['Suggestion'] ?? null ) ? $response['Suggestion'] : '';

		if ( 'Pass' === $suggestion ) {
			$payload['state']   = 'green';
			$payload['code']    = 'Suggestion=Pass';
			$payload['message'] = '凭据可用（Suggestion=Pass，正常文本实测通过）——审核链路已通；启用后评论将按「本地规则 → TMS」串联送审。';
			return $payload;
		}

		$payload['code']    = 'Suggestion=' . ( '' !== $suggestion ? $suggestion : '?' );
		$payload['message'] = '未预期的判定返回（Suggestion=' . $suggestion . '）——凭据与签名链路已通，但样本文本未获 Pass；可重试或截图此信息联系排查。';
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
				$payload['message'] = '密钥/签名错误：AccessKey 对被阿里云拒绝（' . $code . '）——请到「访问管理 → RAM AccessKey 管理」核对后重试。';
				return $payload;
			}

			// 红 2：403 Forbidden.NoPermission（RAM 未授权——L0 实测 AuthAction=yundun-afs:VerifyCaptcha）。
			if ( 'Forbidden.NoPermission' === $code ) {
				$auth_action = is_array( $decoded['AccessDeniedDetail'] ?? null ) && isset( $decoded['AccessDeniedDetail']['AuthAction'] )
					? (string) $decoded['AccessDeniedDetail']['AuthAction']
					: '';
				$payload['state']   = 'red';
				$payload['message'] = '权限不足（RAM 未授权' . ( '' !== $auth_action ? '：' . $auth_action : '' ) . '）——请为该 RAM 用户补授 AliyunYundunAFSFullAccess 后重试。';
				return $payload;
			}

			// 红 3：403 Forbidden.AccountAccessDenied（未开通/欠费）。
			if ( 'Forbidden.AccountAccessDenied' === $code ) {
				$payload['state']   = 'red';
				$payload['message'] = '服务未开通或账号欠费——请到阿里云验证码 2.0 控制台开通服务/核对账户余额后重试。';
				return $payload;
			}

			// 黄：HTTP 级参数类（本地防呆提示——核对配置区取值）。
			if ( 0 === strpos( $code, 'MissingParameter' ) || 0 === strpos( $code, 'InvalidParameter' ) ) {
				$payload['state']   = 'yellow';
				$payload['message'] = '参数错误（' . $code . '）——请核对上方配置区取值后重试。';
				return $payload;
			}

			$payload['message'] = '服务端返回错误：' . $code . '——' . (string) ( $decoded['Message'] ?? '' );
			return $payload;
		}

		if ( ! $result->is_ok() ) {
			// 非 API 错误的传输终态（连接失败/超时/5xx 等）。
			$payload['message'] = '网络不可达或服务暂不可用（' . $result->category() . '）——请稍后再试。';
			return $payload;
		}

		// HTTP 200：读 Result.VerifyCode——假参探测绿态 = F002/F003（业务层拒绝假参数）。
		$res        = is_array( $decoded ) && isset( $decoded['Result'] ) && is_array( $decoded['Result'] ) ? $decoded['Result'] : array();
		$verify_code = is_string( $res['VerifyCode'] ?? null ) ? (string) $res['VerifyCode'] : '';

		if ( in_array( $verify_code, array( 'F002', 'F003' ), true ) ) {
			$payload['state']   = 'green';
			$payload['code']    = 'VerifyCode=' . $verify_code;
			$payload['message'] = '凭据可用（VerifyCode=' . $verify_code . '，假参被业务层正常拒绝——网关鉴权+AccessKey+RAM 全通）——前端验证码应可拉起；若评论区仍显示加载中，请查前端网络或主题脚本。';
			return $payload;
		}

		$payload['code']    = 'VerifyCode=' . ( '' !== $verify_code ? $verify_code : '?' );
		$payload['message'] = '未预期的核验返回（VerifyCode=' . $verify_code . '）——凭据与签名链路已通，但假参未被业务层按预期拒绝；可重试或截图此信息联系排查。';
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
			'message' => '必填字段缺少：' . implode( '、', $missing ) . '——补齐后重新测试。',
			'missing' => $missing,
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
		return array_values( array_unique( $matches[1] ) );
	}
}
