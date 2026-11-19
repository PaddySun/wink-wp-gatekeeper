<?php
/**
 * 阿里云验证码 2.0 驱动（05 §1.2【手档+L0 实测+DESIGN-D2 定稿】；B3，M3' 批次 2）。
 *
 * 前端（05 §1.2 V3 三步形态）：gatekeeper.js 按 frontend 元数据分派到
 * assets/js/drivers/aliyun.js 适配器——① window.AliyunCaptchaConfig = {region:'cn', prefix}
 * 全局配置（引入 JS 之前）→ ② 动态引入 AliyunCaptcha.js（官方硬性红线：静态部署/
 * 本地部署致对抗失效误拦）→ ③ initAliyunCaptcha({SceneId, mode, element, button,
 * success(captchaVerifyParam)})——captchaVerifyParam base64 串即服务端验签参数，
 * 禁止任何修改，写隐藏域 wk_captcha_verify_param。render() 只输出容器占位 + 签名存根
 * + 三态按钮 + element 挂载点（embed 模式 .wk-gate-embed 内 #wk-captcha-element-<gate>，
 * 与天御同一锚点机制），仅含 SceneId/prefix（前端公开级参数）——AccessKey 系字段
 * 绝不出前端（02 §7.4 红线）。
 *
 * 服务端：POST captcha.<region>.aliyuncs.com/（【W3·批C·N2】region→endpoint 映射
 * ENDPOINT_MAP——cn=captcha.cn-shanghai〔现行/默认〕、sgp=captcha.ap-southeast-1
 * 〔静态推断 R7 未实机验证〕；缺键/非法值回落 cn）·
 * Action=VerifyIntelligentCaptcha · Version=2023-03-05（真机定论，见 VERSION 注）· ACS3-HMAC-SHA256 V3
 * 签名（includes/http/AliyunV3Signer.php，金测试=官方测试向量）· 业务参数走 form body
 * （官方不建议 JSON）——全部经传输脊 RemoteClient 发出（重试/分类/计量/脱敏统一）。
 *
 * 判定语义（05 §1.2 定论）：二态制，无评分——Result.VerifyResult Boolean 唯一判据
 * （没有 Score 字段，天御 Score 在阿里不存在）；附加信号 VerifyCode/CertifyId 存事件
 * meta。VerifyResult=false 时按 VerifyCode 码表分档（user_retry/config_error/blocked）。
 *
 * 容灾（05 §1.2 拍板）：阿里无容灾票概念——disasterPolicy() 恒 'none'；调用层异常
 * 「官方建议放行」与现行「故障即拦」的张力以 errorPolicy()='block' 收口（本驱动默认；
 * pass_limited 留 SLA 评估启用——01 D18）。时序约束：行为验证→业务验签间隔 ≤90 秒
 * （F019）、CaptchaVerifyParam 单次有效（F008/F018）——VerifyCache 10 分钟窗命中即
 * 不再触达阿里，无冲突。
 *
 * 分层：verdict_for()/verify_tri() 计算路径为纯逻辑（RemoteClient 注入）；enqueue_assets()
 * 为 WP 边界方法（沿 EventsTable/TencentCaptchaDriver 同一文件混合分层的 M0 惯例）。
 *
 * @package WinkGatekeeper\Drivers\Captcha
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Drivers\Captcha;

use WinkGatekeeper\Drivers\CaptchaDriverInterface;
use WinkGatekeeper\Http\AliyunV3Signer;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Settings\DriverPrivacy;

final class AliyunCaptchaDriver implements CaptchaDriverInterface {

	public const DRIVER_ID  = 'aliyun';
	// 【W3·批C·N2】region→endpoint 映射（ENDPOINT 单常量组化——双端联动：服务端核验
	// 与前端 SDK region 参数同键，错配即报错）。cn=captcha.cn-shanghai.aliyuncs.com
	// （现行端点，L0/真机定论）；sgp=captcha.ap-southeast-1.aliyuncs.com——**静态推断，
	// 未实机验证**（R7：阿里云新加坡 region slug ap-southeast-1 × 验证码 2.0 endpoint
	// 命名式 captcha.<region>.aliyuncs.com，cn 形态为命名式唯一实证）。真机复验挂手测
	// 卡 MANUAL-CARD-W3-C：官方对照表核对 sgp 值 + sgp 场景出码一次；复验不过则 sgp 档
	// disabled 不发布（批 D 前回退决策点）。缺键/非法值经 endpoint_for_region 回落 cn
	// =现行行为保底。
	public const ENDPOINT_MAP = array(
		'cn'  => 'https://captcha.cn-shanghai.aliyuncs.com/',
		'sgp' => 'https://captcha.ap-southeast-1.aliyuncs.com/', // 静态推断（R7）。
	);
	public const HOST_MAP     = array(
		'cn'  => 'captcha.cn-shanghai.aliyuncs.com',
		'sgp' => 'captcha.ap-southeast-1.aliyuncs.com', // 静态推断（R7）。
	);
	/** cn 档现值别名（现行常量名退役前兼容——缺省行为单一来源仍走 ENDPOINT_MAP）。 */
	public const ENDPOINT   = 'https://captcha.cn-shanghai.aliyuncs.com/';
	public const HOST       = 'captcha.cn-shanghai.aliyuncs.com';
	public const ACTION     = 'VerifyIntelligentCaptcha';
	// 真机定论（2026-11-11 wpclean 实调）：x-acs-version 必须 '2023-03-05'——05 §1.2 原文
	// 「Version=captcha-2023-03-05」为产品线命名混写，实调返回 400 InvalidVersion
	// （"Specified parameter Version is not valid."）；L0-ali-captcha 冒烟实调同用 '2023-03-05'。
	public const VERSION    = '2023-03-05';
	public const JS_URL     = 'https://o.alicdn.com/captcha-frontend/aliyunCaptcha/AliyunCaptcha.js';
	public const TOKEN_FIELD = 'wk_captcha_verify_param';
	public const PROBE_PARAM = 'wink-probe-invalid-captchaVerifyParam';

	/**
	 * 传输终态拦截文案（02 §5 降级矩阵 captcha 行——与天御同一文案口径）。
	 */
	private const MSG_TRANSPORT = 'The verification service is temporarily unavailable — please try again later, or refresh the page and retry.'; // i18n 批 I-3：值同步 en（消费位字面量 __()，zh 走 po）。

	/**
	 * 配置错误/契约不符文案（SemanticError 停转 + ContractError 哨兵共用展示层文案）。
	 */
	private const MSG_CONFIG = 'The captcha service is misconfigured (please contact the site owner).'; // i18n 批 I-3 同上。

	/**
	 * @var array{access_key_id: string, access_key_secret: string, scene_id: string, prefix: string, mode: string, region: string, submit_flow: string}
	 */
	private $credentials;

	/**
	 * @var array<string, string> copy.messages 子树（文案工坊自定义）。
	 */
	private $messages;

	/**
	 * @var string 联系邮箱（{contact_email} 占位符）。
	 */
	private $contact_email;

	/**
	 * @var RemoteClient 传输脊（全库唯一 HTTP 出口）。
	 */
	private $client;

	/**
	 * @var array<string, mixed> stats 子树（raw 快照开关/截断——error 分支一行合并消费）。
	 */
	private $stats_settings = array();

	/**
	 * @param array<string, mixed> $settings 完整设置树（读 captcha.aliyun / copy.messages / brand）。
	 * @param RemoteClient         $client   传输脊实例。
	 */
	public function __construct( array $settings, RemoteClient $client ) {
		$aliyun = isset( $settings['captcha']['aliyun'] ) && is_array( $settings['captcha']['aliyun'] )
			? $settings['captcha']['aliyun']
			: array();

		$this->credentials = array(
			// trim 收口（C1 真机踩坑 2026-11-10）：凭据尾随 \r/空格（env 通道 CRLF 残留）会使
			// HMAC 密钥含不可见字符 → SignatureDoesNotMatch（测试凭据红态当场定位）。
			'access_key_id'     => trim( is_string( $aliyun['access_key_id'] ?? null ) ? $aliyun['access_key_id'] : '' ),
			'access_key_secret' => trim( is_string( $aliyun['access_key_secret'] ?? null ) ? $aliyun['access_key_secret'] : '' ),
			'scene_id'          => trim( is_string( $aliyun['scene_id'] ?? null ) ? $aliyun['scene_id'] : '' ),
			'prefix'            => trim( is_string( $aliyun['prefix'] ?? null ) ? $aliyun['prefix'] : '' ),
			// 前端形态（04 §2 captcha.aliyun.mode）：embed | popup——非法值归 embed
			// 【W3·批C·N3】出厂默认平移 popup（migrate 撞车守卫在 schema 层）。
			'mode'              => 'popup' === ( $aliyun['mode'] ?? '' ) ? 'popup' : 'embed',
			// 【W3·批C·N2】服务区域（captcha.aliyun.region）：cn（默认）| sgp——非法值归 cn
			// （endpoint_for_region 缺键/非法值同此回落=现行行为保底）。
			'region'            => self::normalize_region( $aliyun['region'] ?? null ),
			// 【W2·无按钮模式】提交触发形态（captcha.submit_flow）：on_submit（默认，Q2=①
			// 一刀切）| button（经典）——非法值归 on_submit；render() 按此分支（W-7）。
			'submit_flow'       => 'button' === ( $settings['captcha']['submit_flow'] ?? '' ) ? 'button' : 'on_submit',
		);

		$messages = isset( $settings['copy']['messages'] ) && is_array( $settings['copy']['messages'] )
			? $settings['copy']['messages']
			: array();
		$defaults = Copywork::defaults()['messages'];
		$this->messages = array_merge( $defaults, array_filter( $messages, 'is_string' ) );

		$this->contact_email = isset( $settings['brand']['contact_email'] ) && is_string( $settings['brand']['contact_email'] )
			? $settings['brand']['contact_email']
			: '';

		$this->stats_settings = isset( $settings['stats'] ) && is_array( $settings['stats'] )
			? array( 'stats' => $settings['stats'] )
			: array();

		$this->client = $client;
	}

	/**
	 * 驱动注册元数据（05 §4 七维 + scoring 可选维；05 §1.2 registry 建议行）。
	 *
	 * @return array<string, mixed>
	 */
	public static function registry_entry(): array {
		return array(
			'id'           => self::DRIVER_ID,
			'type'         => 'captcha',
			'class'        => self::class,
			'label'        => __( 'Aliyun Captcha 2.0', 'wink-wp-gatekeeper' ),
			'reachability' => 'cn-excellent', // L0 实测 781ms（05 §1.2 可达性行）。
			'billing'      => 'usage',
			'frontend'     => array(
				'interaction'  => 'click',
				'token_fields' => array( self::TOKEN_FIELD ),
			),
			'error_map'    => array(),
			// 二态制无评分（05 §1.2 定论）——Tab② min_score 控件对本驱动隐藏。
			'scoring'      => false,
			// api_version 落事件 meta——与 x-acs-version 同源单点（真机 InvalidVersion 定论后
			// 收口为 '2023-03-05'；05 §1.2 原文 'captcha-2023-03-05' 为产品线命名混写，回写项）。
			'api_version'  => self::VERSION,
			'settings'     => array(
				'access_key_id'     => array( 'type' => 'password', 'sanitize' => 'password', 'label' => 'AccessKeyId', 'required' => true ),
				'access_key_secret' => array( 'type' => 'password', 'sanitize' => 'password', 'label' => 'AccessKeySecret', 'required' => true ),
				'scene_id'          => array( 'type' => 'text', 'sanitize' => 'text', 'label' => 'SceneId', 'required' => true ),
				'prefix'            => array( 'type' => 'text', 'sanitize' => 'text', 'label' => __( 'prefix (identity tag)', 'wink-wp-gatekeeper' ), 'required' => true ),
			),
			'privacy'      => DriverPrivacy::entry( self::DRIVER_ID ), // 第七维（访客 IP+CaptchaVerifyParam / 境内）。
			'doc_url'      => 'https://help.aliyun.com/zh/captcha/user-guide/verify-intelligent-captcha',
			'milestone'    => 'M3',
		);
	}

	/**
	 * 四字段齐全才算已配置（AccessKey 对 + SceneId【v1.5 必填化】+ prefix 前端身份标；
	 * 未配置 = 闸整体跳过 captcha 阶段，02 §2.1）。
	 */
	public function is_configured(): bool {
		return '' !== $this->credentials['access_key_id']
			&& '' !== $this->credentials['access_key_secret']
			&& '' !== $this->credentials['scene_id']
			&& '' !== $this->credentials['prefix'];
	}

	/* -------------------------------------------------------------------
	 * 接口 v1.2 方法
	 * ------------------------------------------------------------------ */

	/** 驱动唯一 id（registry 键/事件表 driver 列/统计键单一来源）。 */
	public function id(): string {
		return self::DRIVER_ID;
	}

	/** 驱动显示名（设置页下拉项/配置区标题——运行时权威）。 */
	public function label(): string {
		return __( 'Aliyun Captcha 2.0', 'wink-wp-gatekeeper' );
	}

	/**
	 * 凭据（token）POST 域名全集——单票据（无天御 ticket/randstr 双域）。
	 * 闸把表单域映射为规范键 captcha_verify_param 后传入 verify_tri（05 §1.2 registry 行）。
	 *
	 * @return string[]
	 */
	public function credentialFields(): array {
		return array( self::TOKEN_FIELD );
	}

	/**
	 * VerifyCode 语义分档（05 §1.2 码表 → 02 §2.1 failurePolicy 声明）。
	 *
	 * @return array<string, string[]>
	 */
	public function failurePolicy(): array {
		return array(
			'user_retry'   => array( 'F008', 'F015', 'F019', 'F018', 'F020' ), // 刷新重试/重复提交/超时档。
			'config_error' => array( 'F005', 'F006', 'F012', 'F022' ),          // 场景/加密配置错档（当日停转评估）。
			'blocked'      => array( 'F001', 'F009', 'F010', 'F011', 'F014', 'F016', 'F017', 'F024', 'F025' ), // 策略拦截档。
			'pass'         => array( 'T001', 'T002', 'T005', 'T006' ),
		);
	}

	/**
	 * 容灾票据策略：恒 'none'——阿里无容灾票概念（05 §1.2）；
	 * 调用层异常处置以 errorPolicy() 收口。
	 */
	public function disasterPolicy(): string {
		return 'none';
	}

	/**
	 * error 态处置策略（05 §1.2 容灾拍板）：默认 'block'——02 §5 captcha 行零行为变化；
	 * pass_limited（仅 transport 类终态 + 限流放行）留 SLA 评估启用（01 D18）。
	 */
	public function errorPolicy(): string {
		return 'block';
	}

	/**
	 * 服务端核验主通道（接口 v1.2 三态；绝不信前端回调结果——只认 VerifyIntelligentCaptcha）。
	 *
	 * 三态语义（02 §2.1 v1.2）：
	 *   pass   = Result.VerifyResult===true（Boolean 唯一判据，二态制无评分）——score 恒 1.0；
	 *   reject = VerifyResult=false（VerifyCode 码表分档）+ 缺票 captcha_missing；
	 *   error  = 传输/语义/契约终态——meta.rule=remote_<category>|contract_mismatch + raw 快照必带。
	 *
	 * @param array<string, mixed> $context {captcha_verify_param, user_ip}（闸映射规范键）。
	 * @return array{state: string, score: float|null, message: string, meta: array<string, mixed>}
	 */
	public function verify_tri( array $context ): array {
		$this->last_meta = array();

		$param = isset( $context['captcha_verify_param'] ) && is_scalar( $context['captcha_verify_param'] )
			? trim( (string) $context['captcha_verify_param'] )
			: '';

		if ( ! $this->is_configured() || '' === $param ) {
			// 缺票 = captcha_missing（真人漏点按钮/JS 未加载）；未配置兜底同文案（闸层已整体跳过）。
			$this->last_meta = array( 'rule' => 'captcha_missing' );
			return array(
				'state'   => 'reject',
				'score'   => null,
				'message' => $this->copy( 'captcha_missing' ),
				'meta'    => $this->last_meta,
			);
		}

		// 业务参数走 form body（官方不建议 JSON）；CaptchaVerifyParam 透传禁改 + SceneId 服务端
		// 补传（多场景防前端被篡改，错配返回 F012——05 §1.2 v1.5 必填化）。
		// 【W3·批C·N2】endpoint/host 走 region→endpoint 映射（双端联动——前端 SDK region
		// 同键 data-wk-region，错配即报错）。
		$target = self::endpoint_for_region( array( 'captcha' => array( 'aliyun' => array( 'region' => $this->credentials['region'] ) ) ) );
		$body   = http_build_query( array(
			'CaptchaVerifyParam' => $param,
			'SceneId'            => $this->credentials['scene_id'],
		) );
		$signed = AliyunV3Signer::sign(
			'POST',
			$target['host'],
			'/',
			array(),
			array( 'content-type' => 'application/x-www-form-urlencoded' ),
			$body,
			$this->credentials['access_key_id'],
			$this->credentials['access_key_secret'],
			self::ACTION,
			self::VERSION
		);

		$result = $this->client->post( $target['endpoint'], array(
			'headers'    => $signed['headers'],
			'body'       => $body,
			'timeout'    => RemoteClient::DEFAULT_TIMEOUT,
			'usage_key'  => self::DRIVER_ID, // 计量：核验调用计入 usage（04 §6）。
			'required'   => array( 'Result.VerifyResult', 'Result.VerifyCode' ), // 缺失 → contract_mismatch 哨兵。
		) );

		return $this->consume_result_tri( $result );
	}

	/**
	 * 凭据探测（Tab②「测试凭据」）：全参假票实调一次，返回传输脊结果——三态判定
	 * （绿 HTTP 200+VerifyCode=F002/F003 / 红 InvalidAccessKeyId*·SignatureDoesNotMatch·
	 * Forbidden.NoPermission·AccountAccessDenied / 黄本地必填缺）在
	 * Settings\CredentialProbe::classify_aliyun（DESIGN-D2 §1.4，05 §1.2 凭据自检）。
	 *
	 * 与 verify_tri 的差异：不带 required 契约哨兵（API 级错误形态要原样透出给探测层）、
	 * 不消费 last_meta、不计入 usage（Tab② 现场诊断，非业务核验）。
	 *
	 * @return RemoteResult
	 */
	public function probe_credentials(): RemoteResult {
		// 【W3·批C·N2】探测同走 region→endpoint 映射（与 verify 同源——Tab② 测的就是
		// 实际生效端点的凭据链）。
		$target = self::endpoint_for_region( array( 'captcha' => array( 'aliyun' => array( 'region' => $this->credentials['region'] ) ) ) );
		$body   = http_build_query( array(
			'CaptchaVerifyParam' => self::PROBE_PARAM,
			'SceneId'            => $this->credentials['scene_id'],
		) );
		$signed = AliyunV3Signer::sign(
			'POST',
			$target['host'],
			'/',
			array(),
			array( 'content-type' => 'application/x-www-form-urlencoded' ),
			$body,
			$this->credentials['access_key_id'],
			$this->credentials['access_key_secret'],
			self::ACTION,
			self::VERSION
		);

		return $this->client->post( $target['endpoint'], array(
			'headers'   => $signed['headers'],
			'body'      => $body,
			'timeout'   => RemoteClient::DEFAULT_TIMEOUT,
			'usage_key' => '', // 探测调用不计 usage（Tab② 现场诊断，非业务核验）。
		) );
	}

	/**
	 * 渲染容器（03 §2.1 v1.4 结构同构天御；frontend 元数据内联 data-* 供 gatekeeper.js 分派）。
	 *
	 *   - span 容器（存根 + token 写域 + 三态按钮 + noscript）；
	 *   - embed 模式（04 §2 captcha.aliyun.mode 默认）尾随 .wk-gate-embed 挂载点，内含
	 *     #wk-captcha-element-<gate>（SDK 触发条渲染位，提交按钮正下方——与天御同一锚点
	 *     机制 03 §2.1/§2.2）；popup 模式零占位（无挂载点，SDK 直接弹层）。
	 *   - data-scene-id/data-prefix 为前端公开级参数（05 §1.2：prefix/ekey 是前端参数，
	 *     非服务端 API 凭据）；AccessKey 系字段绝不出前端（02 §7.4 红线）。
	 *
	 * @param array<string, mixed> $args {gate, action, context_id, stub}。
	 * @return string
	 */
	public function render( array $args ): string {
		$gate = isset( $args['gate'] ) && is_scalar( $args['gate'] ) ? (string) $args['gate'] : 'comment';
		$stub = isset( $args['stub'] ) && is_scalar( $args['stub'] ) ? (string) $args['stub'] : '';

		$esc = static function ( string $value ): string {
			return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
		};

		/* 【W2·无按钮模式】submit_flow 分支（W-7/R6）：on_submit（默认）= 容器加
		 * data-submit-gate="1"（gatekeeper.js submit-gate 拦截链装配锚——R3 禁用四件对此
		 * 容器停用）+ 按钮加 hidden 属性（SDK 的 button 参数仍需真实元素绑定——静态推断，
		 * Q3 真机复验；CSS 补 .wk-captcha-btn[hidden]{display:none}——display:inline-block
		 * 会压过 hidden 属性，mount.hidden 课程同族坑）；button = 经典形态，产物与 0.8.3
		 * 逐字节同构（经典回归锚）。 */
		$submit_gate = 'on_submit' === $this->credentials['submit_flow'];

		$html = '<span class="wk-gate" data-gate="' . $esc( $gate ) . '" data-driver="aliyun"'
			. ' data-interaction="click"'
			. ( $submit_gate ? ' data-submit-gate="1"' : '' )
			. ' data-mode="' . $esc( $this->credentials['mode'] ) . '"'
			. ' data-wk-region="' . $esc( $this->credentials['region'] ) . '"' // 【W3·批C·N2】前端 SDK region 参数（aliyun.js 读——与 ENDPOINT_MAP 双端联动）.
			. ' data-token-fields="' . self::TOKEN_FIELD . '"'
			. ' data-scene-id="' . $esc( $this->credentials['scene_id'] ) . '"'
			. ' data-prefix="' . $esc( $this->credentials['prefix'] ) . '">'
			. '<input type="hidden" name="wk_stub" value="' . $esc( $stub ) . '">'
			. '<input type="hidden" name="' . self::TOKEN_FIELD . '" value="">'
			. '<span class="wk-gate-inline">'
			. '<button type="button" class="wk-captcha-btn wk-verify-btn button" data-wk-state="unverified"'
			. ( $submit_gate ? ' hidden' : '' )
			. ' aria-live="polite">' . __( 'Complete human verification', 'wink-wp-gatekeeper' ) . '</button>'
			. '</span>'
			. '<noscript>' . __( 'JavaScript is required to complete human verification.', 'wink-wp-gatekeeper' ) . '</noscript>'
			. '</span>';

		if ( 'embed' === $this->credentials['mode'] ) {
			// 嵌入式挂载点：块级兄弟段 + element 容器（SDK 触发条渲染位）。
			$html .= '<div class="wk-gate-embed" hidden><div id="wk-captcha-element-' . $esc( $gate ) . '"></div></div>';
		}

		return $html;
	}

	/**
	 * 前端资产入队（WP 边界方法）：适配器脚本由 Bootstrap 注册 handle，此处仅入队。
	 */
	public function enqueue_assets(): void {
		wp_enqueue_script( 'wink-gatekeeper-driver-aliyun' );
	}

	/* -------------------------------------------------------------------
	 * region→endpoint 双端联动（纯函数，【W3·批C·N2】）
	 * ------------------------------------------------------------------ */

	/**
	 * region 归一（纯函数）：cn（默认）| sgp 闭集，缺键/非法值回落 cn=现行行为保底。
	 *
	 * @param mixed $region captcha.aliyun.region 原始值。
	 * @return string 'cn'|'sgp'。
	 */
	public static function normalize_region( $region ): string {
		return 'sgp' === ( is_scalar( $region ) ? (string) $region : '' ) ? 'sgp' : 'cn';
	}

	/**
	 * 服务端 endpoint/host 取件（纯函数；verify_tri 与 probe_credentials 双调用点共用——
	 * 双端联动单一来源。$settings 为完整设置树，region 键缺/非法回落 cn 现值）。
	 *
	 * @param array<string, mixed> $settings 完整设置树（读 captcha.aliyun.region）。
	 * @return array{endpoint: string, host: string}
	 */
	public static function endpoint_for_region( array $settings ): array {
		$region  = self::normalize_region( $settings['captcha']['aliyun']['region'] ?? null );
		$region  = isset( self::ENDPOINT_MAP[ $region ] ) ? $region : 'cn';
		return array(
			'endpoint' => self::ENDPOINT_MAP[ $region ],
			'host'     => self::HOST_MAP[ $region ],
		);
	}

	/* -------------------------------------------------------------------
	 * VerifyCode 映射（纯函数）
	 * ------------------------------------------------------------------ */

	/**
	 * VerifyResult + VerifyCode → 判定映射（05 §1.2 码表分档）。
	 *
	 * @param bool   $verify_result Result.VerifyResult（Boolean 唯一判据）。
	 * @param string $code          Result.VerifyCode（T/F 码表；未知码兜底拦截 fail-closed）。
	 * @return array{pass: bool, hint: string, hint_kind: string, rule: string}
	 */
	public static function verdict_for( bool $verify_result, string $code ): array {
		$rule = 'VerifyCode=' . ( '' !== $code ? $code : '?' );

		if ( $verify_result ) {
			// 二态制：Boolean 唯一判据——VerifyResult=true 即通过（T002 容灾/QPS 放行同此通道）。
			return array( 'pass' => true, 'hint' => '', 'hint_kind' => '', 'rule' => $rule );
		}

		$policy = array(
			'user_retry'   => array( 'F008', 'F015', 'F019', 'F018', 'F020' ),
			'config_error' => array( 'F005', 'F006', 'F012', 'F022' ),
			'blocked'      => array( 'F001', 'F009', 'F010', 'F011', 'F014', 'F016', 'F017', 'F024', 'F025', 'F004' ),
		);

		if ( in_array( $code, $policy['config_error'], true ) ) {
			return array(
				'pass'      => false,
				'hint'      => __( 'The site captcha is misconfigured (please contact the site owner).', 'wink-wp-gatekeeper' ),
				'hint_kind' => 'config',
				'rule'      => $rule,
			);
		}

		if ( in_array( $code, $policy['blocked'], true ) ) {
			return array(
				'pass'      => false,
				'hint'      => __( 'Human verification did not pass (this submission was blocked).', 'wink-wp-gatekeeper' ),
				'hint_kind' => 'blocked',
				'rule'      => $rule,
			);
		}

		// user_retry 档 + 未收录码（F002/F003/F013 参数类、F023 容灾、未来新码）——兜底拦截，
		// 文案按刷新重试（fail-closed：未知码不当通过）。
		return array(
			'pass'      => false,
			'hint'      => __( 'Refresh the page and try again.', 'wink-wp-gatekeeper' ),
			'hint_kind' => 'refresh',
			'rule'      => $rule,
		);
	}

	/* -------------------------------------------------------------------
	 * 内部
	 * ------------------------------------------------------------------ */

	/**
	 * @var array<string, mixed> 最近一次 verify_tri 的事件 meta。
	 */
	private $last_meta = array();

	/**
	 * 最近一次 verify_tri 的事件 meta（VerifyCode/CertifyId/RequestId/rule，闸读取落库）。
	 *
	 * @return array<string, mixed>
	 */
	public function last_verify_meta(): array {
		return $this->last_meta;
	}

	/**
	 * 消费传输脊结果 → verify_tri 三态 + 事件 meta（对齐 TencentCaptchaDriver::consume_result_tri
	 * 的【v1.5·C1】形态：error 分支返 {state:'error',…}，meta/raw 组装逻辑同构）。
	 *
	 * @param RemoteResult $result 传输脊结果。
	 * @return array{state: string, score: float|null, message: string, meta: array<string, mixed>}
	 */
	private function consume_result_tri( RemoteResult $result ): array {
		if ( ! $result->is_ok() ) {
			// 远端终态以 error 态表达——meta.raw 必带（raw_snapshot 单一来源；raw_capture 关=省略）。
			$this->last_meta = array(
				'category' => $result->category(),
				'status'   => $result->status(),
				'rule'     => 'remote_' . $result->category(),
			);
						$message = __( 'The verification service is temporarily unavailable — please try again later, or refresh the page and retry.', 'wink-wp-gatekeeper' ); // i18n 批 I-3：MSG_TRANSPORT 字面量拍平（常量保留供契约引用）。
			if ( RemoteResult::CONTRACT === $result->category() ) {
				$this->last_meta['rule'] = 'contract_mismatch'; // R6/R9 厂商改版哨兵。
				$message                 = __( 'The captcha service is misconfigured (please contact the site owner).', 'wink-wp-gatekeeper' );
			} elseif ( RemoteResult::TRANSPORT !== $result->category() ) {
				$message = __( 'The captcha service is misconfigured (please contact the site owner).', 'wink-wp-gatekeeper' );
			}
			$raw = RemoteResult::raw_snapshot_from_settings( $result, $this->stats_settings );
			if ( null !== $raw ) {
				$this->last_meta['raw'] = $raw;
			}
			return array(
				'state'   => 'error',
				'score'   => null,
				'message' => $message,
				'meta'    => $this->last_meta,
			);
		}

		$data    = $result->data();
		$res     = is_array( $data ) && isset( $data['Result'] ) && is_array( $data['Result'] ) ? $data['Result'] : array();
		$pass    = true === ( $res['VerifyResult'] ?? null );
		$code    = is_string( $res['VerifyCode'] ?? null ) ? (string) $res['VerifyCode'] : '';
		$mapped  = self::verdict_for( $pass, $code );

		// 事件 meta：VerifyCode/CertifyId（附加信号，05 §1.2）+ RequestId。
		$meta = array( 'VerifyCode' => $code );
		if ( isset( $res['CertifyId'] ) && is_scalar( $res['CertifyId'] ) && '' !== (string) $res['CertifyId'] ) {
			$meta['CertifyId'] = (string) $res['CertifyId'];
		}
		if ( is_array( $data ) && isset( $data['RequestId'] ) && is_scalar( $data['RequestId'] ) ) {
			$meta['RequestId'] = (string) $data['RequestId'];
		}
		$meta['rule'] = $mapped['rule'];
		$this->last_meta = $meta;

		if ( $mapped['pass'] ) {
			// 二态驱动恒 score=1.0（02 §2.1 score 归一化契约——闸对二态/评分制无感知分流）。
			return array( 'state' => 'pass', 'score' => 1.0, 'message' => '', 'meta' => $meta );
		}

		return array(
			'state'   => 'reject',
			'score'   => null,
			'message' => $this->copy( 'captcha_failed' ) . $mapped['hint'],
			'meta'    => $meta,
		);
	}

	/**
	 * 文案工坊文案 + 占位符替换。
	 *
	 * @param string $key 文案键。
	 * @return string
	 */
	private function copy( string $key ): string {
		$text = $this->messages[ $key ] ?? '';
		if ( '' === $text ) {
			return '';
		}
		return Copywork::format( $text, array( 'contact_email' => $this->contact_email ) );
	}
}
