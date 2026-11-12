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
				$payload['message'] = '密钥/签名错误：AccessKey 对被阿里云拒绝（' . $code . '）——请到「访问管理 → RAM AccessKey 管理」核对后重试。';
				return $payload;
			}

			// 红 2：408 PERMISSION_DENY（未授权/欠费/未开通——05 §2.5 error_map 语义档）。
			if ( '408' === $code ) {
				$payload['state']   = 'red';
				$payload['message'] = '权限不足（PERMISSION_DENY）：文本审核增强版未授权/欠费/未开通——请为该 RAM 用户补授 AliyunYundunGreenWebFullAccess，或到阿里云内容安全控制台开通「文本审核增强版」后重试。';
				return $payload;
			}

			// 黄：body 400 参数类（本地防呆提示——核对配置区取值；不用于绿态判定）。
			if ( '400' === $code ) {
				$payload['state']   = 'yellow';
				$payload['message'] = '参数错误（Code=400' . ( isset( $decoded['Message'] ) && is_string( $decoded['Message'] ) && '' !== $decoded['Message'] ? '：' . $decoded['Message'] : '' ) . '）——请核对上方配置区取值后重试。';
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

		// body Code=200：读 Data.RiskLevel——绿态判据（探测样本应为干净文本 none/low）。
		$data = is_array( $decoded ) && is_array( $decoded['Data'] ?? null ) ? $decoded['Data'] : array();
		$risk = is_string( $data['RiskLevel'] ?? null ) ? $data['RiskLevel'] : '';

		if ( in_array( $risk, array( 'none', 'low' ), true ) ) {
			$payload['state']   = 'green';
			$payload['code']    = 'Code=200';
			$payload['message'] = '凭据可用（Code=200，探测文本 RiskLevel=' . $risk . '）——审核链路已通；启用后评论将按「本地规则 → 阿里文本审核 PLUS」串联送审。';
			return $payload;
		}

		$payload['code']    = 'RiskLevel=' . ( '' !== $risk ? $risk : '?' );
		$payload['message'] = '未预期的判定返回（RiskLevel=' . $risk . '）——凭据与签名链路已通，但探测文本未获干净判定；可重试或截图此信息联系排查。';
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
			'message' => '必填字段缺少：' . implode( '、', $missing ) . '——补齐后重新测试。',
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
				$payload['message'] = '鉴权失败（' . $code . '：access_token ' . ( 110 === $code ? '无效' : '过期' ) . '）：API Key/Secret Key 被百度云拒绝——请到「百度智能云控制台 → 应用列表」核对 API Key 与 Secret Key 后重试。';
				return $payload;
			}

			// 红 2：无权限调用（未开通文本审核服务）。
			if ( 6 === $code ) {
				$payload['state']   = 'red';
				$payload['message'] = '无权限调用（error_code=6）：文本审核服务未开通——请到百度智能云控制台开通「内容审核 · 文本审核」后重试。';
				return $payload;
			}

			// 黄：参数错（本地防呆提示——核对配置区取值）。
			if ( 100 === $code ) {
				$payload['state']   = 'yellow';
				$payload['message'] = '参数错误（error_code=100' . ( '' !== $error_msg ? '：' . $error_msg : '' ) . '）——请核对上方配置区取值后重试。';
				return $payload;
			}

			// 灰：配额超限/其它未知码——给原文与排查方向。
			$hint = ( 17 === $code || 19 === $code ) ? '（配额超限——请到百度智能云控制台核对该应用的配额/付费状态）' : '';
			$payload['message'] = '服务端返回错误（error_code=' . $code . '——' . $error_msg . '）' . $hint . '——请截图此信息联系排查。';
			return $payload;
		}

		if ( ! $result->is_ok() ) {
			// 传输/语义终态：semantic（token 端点 400/401——invalid_client 等）= 鉴权失败红态
			// （凭据被拒的直接证据；百度 oauth 错误码不一定走审核端点 error_code 通道）；
			// transport = 网络不可达灰态。
			if ( RemoteResult::SEMANTIC === $result->category() ) {
				$payload['state']   = 'red';
				$payload['code']    = 'HTTP ' . $result->status();
				$payload['message'] = '鉴权失败（HTTP ' . $result->status() . '）：API Key/Secret Key 被百度云拒绝——请到「百度智能云控制台 → 应用列表」核对 API Key 与 Secret Key 后重试。';
				return $payload;
			}
			$payload['message'] = '网络不可达或服务暂不可用（' . $result->category() . '）——请稍后再试。';
			return $payload;
		}

		// HTTP 200：读 conclusionType——绿态判据（探测样本应为干净文本=1 合规）。
		$type = isset( $decoded['conclusionType'] ) && is_numeric( $decoded['conclusionType'] )
			? (int) $decoded['conclusionType']
			: 0;

		if ( 1 === $type ) {
			$payload['state']   = 'green';
			$payload['code']    = 'conclusionType=1';
			$payload['message'] = '凭据可用（conclusionType=1，探测文本合规）——审核链路已通；启用后评论将按「本地规则 → 百度文本审核」串联送审。';
			return $payload;
		}

		$payload['code']    = 'conclusionType=' . ( $type > 0 ? (string) $type : '?' );
		$payload['message'] = '未预期的判定返回（conclusionType=' . $type . '）——凭据与 token 链路已通，但探测文本未获合规判定；可重试或截图此信息联系排查。';
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
			'message' => '必填字段缺少：' . implode( '、', $missing ) . '——补齐后重新测试。',
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
			$payload['message'] = '密钥被拒（HTTP ' . $status . ( 401 === $status ? '：API Key 无效' : '：订阅或每日配额不可用' ) . '）——请到 AbuseIPDB 控制台核对 API Key 与订阅状态后重试。';
			return $payload;
		}

		// 灰：429 限流——凭据可能有效，不误报红档（给排查方向）。
		if ( 429 === $status ) {
			$payload['code']    = 'HTTP 429';
			$payload['message'] = 'HTTP 429（请求被限流——免费档速率/每日配额约束）。凭据有效性本次无法确认——请稍后再试，或在 AbuseIPDB 控制台核对配额。';
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
			$payload['message'] = '凭据可用（HTTP 200，响应 JSON 可解析）——查询链路已通。'
				. ( false === $is_public ? '本次查询的是保留地址（isPublic:false 亦属绿态——验证的是凭据不是 IP）。' : '' );
			return $payload;
		}
		if ( null !== $decoded || RemoteResult::PARSE === $result->category() || RemoteResult::CONTRACT === $result->category() ) {
			// 2xx 但坏 JSON（传输脊归 PARSE）或其它契约形态——给排查方向不误报三态。
			$payload['message'] = 'HTTP ' . ( $status > 0 ? $status : '200' ) . ' 但响应不是合法 JSON（契约不符——可能被中间层劫持/返回了 HTML）——请截图此信息联系排查。';
			return $payload;
		}

		if ( ! $result->is_ok() ) {
			// 非 API 错误的传输终态（连接失败/超时/5xx 等）。
			$payload['message'] = '网络不可达或服务暂不可用（' . $result->category() . '）——请稍后再试（无国际出口的网络环境会持续此态）。';
			return $payload;
		}

		// 200 且 body 非空但解析为空串等剩余形态——契约兜底。
		$payload['message'] = 'HTTP 200 但响应不是合法 JSON（契约不符——可能被中间层劫持/返回了 HTML）——请截图此信息联系排查。';
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
			'message' => '必填字段缺少：API Key——请先在上方填入并保存（或填入后直接重测），再点「测试凭据」。',
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
		return array_values( array_unique( $matches[1] ) );
	}
}
