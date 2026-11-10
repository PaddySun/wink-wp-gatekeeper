<?php
/**
 * 腾讯天御验证码 2.0 驱动（05 §1.1【手档】；F04，M1 首发）。
 *
 * 前端（03 §2.1，click 形态）：gatekeeper.js 调度器按 frontend 元数据分派到
 * assets/js/drivers/tencent.js 适配器——动态加载 TJCaptcha.js（官方硬性要求：静态引入
 * 致对抗失效）→ new TencentCaptcha(appId, callback) → 回调写 wk_captcha_ticket /
 * wk_captcha_randstr 隐藏域。render() 只输出容器占位 + 签名存根 + 按钮 + 状态字，
 * 仅含 CaptchaAppId（公开级）——secret 系字段绝不出前端（02 §7.4 红线）。
 *
 * 服务端：POST https://captcha.tencentcloudapi.com/ · X-TC-Action=DescribeCaptchaResult ·
 * X-TC-Version=2019-07-22 · TC3-HMAC-SHA256 签名（《签名方法 v3》手档齐全，金测试覆盖），
 * 全部经传输脊 RemoteClient 发出（重试/分类/计量/脱敏统一，F31）。
 *
 * 凭据形态静态结论（05 §1.1 实现注意④，未运行验证）：
 *   - 手档《核查验证码票据结果》把 AppSecretKey 列为请求参数（旧版直连核验形态）；
 *   - TC3 签名要求 CAM 密钥对（SecretId/SecretKey，云 API 密钥页）——两套凭据体系；
 *   - M1 按三字段实现：app_id=CaptchaAppId（body 参数）、secret_id=CAM SecretId（TC3
 *     Credential）、secret_key=CAM SecretKey（TC3 HMAC 密钥），body 不再携带 AppSecretKey。
 *   真机核对清单：① 若 API 返回 MissingParameter.AppSecretKey → body 需补 AppSecretKey
 *   （取值 = 验证码控制台密钥，届时需第 4 配置字段）；② 若 TC3 签名被拒（AuthFailure.*）
 *   → 核对 CAM 密钥与时间同步；③ CaptchaCode=100 提示 appid-secretkey-ticket mismatch
 *   → 检查 CaptchaAppId 与票据同源。
 *
 * CaptchaCode 映射（手档码表 + 05 §1.1）：
 *   1=过；7/8/9/15 → captcha_failed 刷新重试；16/100 → 配置错误文案；
 *   21 且 Ticket 含 trerror 前缀 = 官方容灾票按通过（弱网真人误杀防线）；其余码兜底拦截。
 *
 * 分层：tc3_*()/verdict_for()/verify_tri() 计算路径为纯逻辑（RemoteClient 注入）；enqueue_assets()
 * 为 WP 边界方法（沿 EventsTable 同一文件混合分层的 M0 惯例，文件头标注）。
 *
 * @package WinkGatekeeper\Drivers\Captcha
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Drivers\Captcha;

use WinkGatekeeper\Drivers\CaptchaDriverInterface;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Http\Tc3Signer;
use WinkGatekeeper\Intercept\Copywork;

final class TencentCaptchaDriver implements CaptchaDriverInterface {

	public const DRIVER_ID  = 'tencent';
	public const ENDPOINT   = 'https://captcha.tencentcloudapi.com/';
	public const SERVICE    = 'captcha';
	public const HOST       = 'captcha.tencentcloudapi.com';
	public const ACTION     = 'DescribeCaptchaResult';
	public const VERSION    = '2019-07-22';
	public const JS_URL     = 'https://turing.captcha.qcloud.com/TJCaptcha.js';
	public const TOKEN_FIELD_TICKET  = 'wk_captcha_ticket';
	public const TOKEN_FIELD_RANDSTR = 'wk_captcha_randstr';

	/**
	 * 容灾放行限速（C1 任务口径：每 IP 每小时 N=5 次容灾放行，超出按 block 拦）。
	 * 05 §1.1 原文「默认 10 次/15min」由施工任务口径覆盖（报告偏差表记录）。
	 */
	public const DISASTER_LIMIT        = 5;
	public const DISASTER_WINDOW       = 3600; // 秒（1 小时窗口）。
	public const DISASTER_POLICY_ALLOW = 'allow';
	public const DISASTER_POLICY_BLOCK = 'block';

	/**
	 * 传输终态拦截文案（02 §5 降级矩阵 captcha 行——不设开关；M4 文案键扩展时迁 Copywork）。
	 */
	private const MSG_TRANSPORT = '验证服务暂时打不开，请稍后再试；也可以刷新页面重试。';

	/**
	 * 配置错误/契约不符文案（SemanticError 停转 + ContractError 哨兵共用展示层文案）。
	 */
	private const MSG_CONFIG = '验证码服务配置有误（请联系站长处理）。';

	/**
	 * @var array{app_id: int|string, secret_id: string, secret_key: string}
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
	 * @var array<string, mixed> 最近一次 verify() 的事件 meta（Score/GetCaptchaTime/rule 等）。
	 */
	private $last_meta = array();

	/**
	 * @var string 容灾票据策略（04 §2 captcha.tencent.disaster_pass：allow 默认 / block）。
	 */
	private $disaster_policy = self::DISASTER_POLICY_ALLOW;

	/**
	 * @var callable|null 容灾放行限速器 fn(ip): bool——true=额度内放行（并计数），
	 *                   false=超额（按 block 拦）。null=未装配（fail-open，与 v1.2 行为一致；
	 *                   生产由 Bootstrap 注入 transient 计数器 wp_disaster_limiter）。
	 */
	private $disaster_limiter;

	/**
	 * @var array<string, mixed> stats 子树（raw 快照开关/截断——error 分支一行合并消费；R4 §D.1）。
	 */
	private $stats_settings = array();

	/**
	 * @param array<string, mixed> $settings 完整设置树（读 captcha.tencent / copy.messages / brand）。
	 * @param RemoteClient         $client    传输脊实例。
	 * @param callable|null        $disaster_limiter 容灾限速器（null = fail-open 不限）。
	 */
	public function __construct( array $settings, RemoteClient $client, ?callable $disaster_limiter = null ) {
		$tencent = isset( $settings['captcha']['tencent'] ) && is_array( $settings['captcha']['tencent'] )
			? $settings['captcha']['tencent']
			: array();

		$this->credentials = array(
			// trim 收口（C1 真机踩坑 2026-11-10）：凭据尾随 \r/空格（env 通道 CRLF 残留）会使
			// TC3 HMAC 密钥/SecretId 含不可见字符 → AuthFailure.SignatureFailure（测试凭据红态当场定位）。
			'app_id'         => trim( (string) ( is_scalar( $tencent['app_id'] ?? null ) ? $tencent['app_id'] : '' ) ),
			'secret_id'      => trim( is_string( $tencent['secret_id'] ?? null ) ? $tencent['secret_id'] : '' ),
			'secret_key'     => trim( is_string( $tencent['secret_key'] ?? null ) ? $tencent['secret_key'] : '' ),
			'app_secret_key' => trim( is_string( $tencent['app_secret_key'] ?? null ) ? $tencent['app_secret_key'] : '' ),
		);

		// 容灾开关（04 §2 captcha.tencent.disaster_pass）：非法/缺失值归 allow（默认档）。
		$this->disaster_policy = self::DISASTER_POLICY_BLOCK === ( $tencent['disaster_pass'] ?? '' )
			? self::DISASTER_POLICY_BLOCK
			: self::DISASTER_POLICY_ALLOW;
		$this->disaster_limiter = $disaster_limiter;

		$messages = isset( $settings['copy']['messages'] ) && is_array( $settings['copy']['messages'] )
			? $settings['copy']['messages']
			: array();
		$defaults = Copywork::defaults()['messages'];
		$this->messages = array_merge( $defaults, array_filter( $messages, 'is_string' ) );

		$this->contact_email = isset( $settings['brand']['contact_email'] ) && is_string( $settings['brand']['contact_email'] )
			? $settings['brand']['contact_email']
			: '';

		// 【v1.4·R4】raw 快照开关/截断（error 分支一行合并消费；stats.raw_capture 默认开）。
		$this->stats_settings = isset( $settings['stats'] ) && is_array( $settings['stats'] )
			? array( 'stats' => $settings['stats'] )
			: array();

		$this->client = $client;
	}

	/**
	 * 驱动注册元数据（05 §4 六维声明；数据照示例）。
	 *
	 * @return array<string, mixed>
	 */
	public static function registry_entry(): array {
		return array(
			'id'          => self::DRIVER_ID,
			'type'        => 'captcha',
			'class'       => self::class,
			'label'       => '腾讯天御验证码 2.0',
			'reachability' => 'cn-excellent',
			'billing'     => 'usage',
			'frontend'    => array(
				'interaction'   => 'click',
				'token_fields'  => array( self::TOKEN_FIELD_TICKET, self::TOKEN_FIELD_RANDSTR ),
			),
			'error_map'   => array(),
			// 【v1.5·DESIGN-C1 §4.2/05 §4】scoring 维（captcha 类专属可选键）：天御二态制——
			// Tab② min_score 控件隐藏（评分制驱动接入时 true 自动出现滑杆）。
			'scoring'     => false,
			'api_version' => 'captcha-2019-07-22',
		'settings'    => array(
			'app_id'         => array( 'type' => 'number', 'sanitize' => 'absint', 'label' => 'CaptchaAppId', 'required' => true ),
			'secret_id'      => array( 'type' => 'password', 'sanitize' => 'password', 'label' => 'SecretId（CAM）', 'required' => true ), // FIX-α 2026-11-10：入敏感字段清单（03 §1 v1.3 契约：registry type=password 自动派生）。
			'secret_key'     => array( 'type' => 'password', 'sanitize' => 'password', 'label' => 'SecretKey（CAM）', 'required' => true ),
			'app_secret_key' => array( 'type' => 'password', 'sanitize' => 'password', 'label' => 'AppSecretKey（验证码控制台）', 'required' => true ),
		),
		'privacy'     => \WinkGatekeeper\Settings\DriverPrivacy::entry( self::DRIVER_ID ), // 【v1.4·R4】第七维（访客 IP+票据 / 境内）——顶层散件并入删除。
		'doc_url'     => 'https://cloud.tencent.com/document/product/1110/36926',
		'milestone'   => 'M1',
	);
	}

	/**
	 * 四字段齐全才算已配置（未配置 = 闸整体跳过 captcha 阶段，02 §2.1）。
	 * app_secret_key 为 body 必填参数（L0 冒烟在线定论：MissingParameter:'AppSecretKey'，05 §1.1 实现注意④）。
	 */
	public function is_configured(): bool {
		return ! empty( $this->credentials['app_id'] )
			&& '' !== $this->credentials['secret_id']
			&& '' !== $this->credentials['secret_key']
			&& '' !== $this->credentials['app_secret_key'];
	}

	/* -------------------------------------------------------------------
	 * 接口 v1.1 五方法（02 §2.1 冻结签名）
	 * ------------------------------------------------------------------ */

	/** 驱动唯一 id（registry 键/事件表 driver 列/统计键单一来源）。 */
	public function id(): string {
		return self::DRIVER_ID;
	}

	/** 驱动显示名（设置页下拉项/配置区标题——运行时权威）。 */
	public function label(): string {
		return '腾讯天御验证码 2.0';
	}

	/**
	 * 凭据（token）POST 域名全集——registry frontend.token_fields 的派生源（05 §4 v1.3）。
	 *
	 * @return string[]
	 */
	public function credentialFields(): array {
		return array( self::TOKEN_FIELD_TICKET, self::TOKEN_FIELD_RANDSTR );
	}

	/**
	 * CaptchaCode 语义分档（05 §1.1 错误映射 → 02 §2.1 failurePolicy 声明）。
	 *
	 * @return array<string, int[]>
	 */
	public function failurePolicy(): array {
		return array(
			'user_retry'   => array( 7, 8, 9, 15 ), // Randstr 不合法/票过期(5min)/票重用/票不合法——刷新重试档。
			'config_error' => array( 16, 100 ),     // appid 不匹配/参数校验错——站点配置档（当日停转评估）。
			'pass'         => array( 21 ),          // 票据异常：trerror 前缀容灾票按通过档（前缀条件在 verify 内判）。
		);
	}

	/**
	 * 容灾票据策略（站长开关 captcha.tencent.disaster_pass，构造器已归一）。
	 */
	public function disasterPolicy(): string {
		return $this->disaster_policy;
	}

	/**
	 * error 态处置策略（【v1.5·C1】接口 v1.2 冻结位；05 §1.1 v1.5 回归注记）：
	 * 恒 'block'——拍板（D6）：天御零行为变化，故障即拦（现行降级矩阵）；
	 * pass_limited 留阿里驱动实施时按 SLA 评估启用（01 D18）。与容灾 disasterPolicy 正交。
	 */
	public function errorPolicy(): string {
		return 'block';
	}

	/**
	 * 服务端核验主通道（【v1.5·C1】接口 v1.2 三态；绝不信前端回调结果——只认 DescribeCaptchaResult）。
	 *
	 * 三态语义（02 §2.1 v1.2）：
	 *   pass   = CaptchaCode 判定通过（含 trerror 容灾票 allow 档放行）——score 恒 1.0（二态驱动）；
	 *   reject = 业务不通过（票无效/过期/重用/风控）+ 缺票 captcha_missing——message=驱动终态文案；
	 *   error  = 传输/语义/契约终态——meta.rule=remote_<category>|contract_mismatch + raw 快照必带。
	 *
	 * 行为变化点 1（02 §5 captcha 行补注）：远端故障自本方法起以 error 态表达——事件 verdict
	 * 由 reject 改 error（统计纠偏），由闸经 CaptchaTriInterpreter 统一翻译。
	 *
	 * @param array<string, mixed> $context {ticket, randstr, user_ip}。
	 * @return array{state: string, score: float|null, message: string, meta: array<string, mixed>}
	 */
	public function verify_tri( array $context ): array {
		$this->last_meta = array();

		$ticket  = isset( $context['ticket'] ) && is_scalar( $context['ticket'] ) ? trim( (string) $context['ticket'] ) : '';
		$randstr = isset( $context['randstr'] ) && is_scalar( $context['randstr'] ) ? trim( (string) $context['randstr'] ) : '';
		$user_ip = isset( $context['user_ip'] ) && is_scalar( $context['user_ip'] ) ? (string) $context['user_ip'] : '';

		if ( ! $this->is_configured() || '' === $ticket || '' === $randstr ) {
			// 缺票 = captcha_missing（真人漏点按钮/JS 未加载）；未配置兜底同文案（闸层已整体跳过）。
			$this->last_meta = array( 'rule' => 'captcha_missing' );
			return array(
				'state'   => 'reject',
				'score'   => null,
				'message' => $this->copy( 'captcha_missing' ),
				'meta'    => $this->last_meta,
			);
		}

		$payload = (string) json_encode( array(
			'CaptchaType'        => 9,
			'Ticket'             => $ticket,
			'Randstr'            => $randstr,
			'UserIp'             => $user_ip,
			'CaptchaAppId'       => (int) $this->credentials['app_id'],
			'AppSecretKey'       => $this->credentials['app_secret_key'], // body 必填（L0 在线定论 2026-11-09）。
			'NeedGetCaptchaTime' => 1,
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$timestamp = time();
		$signed    = self::tc3_authorization(
			$this->credentials['secret_id'],
			$this->credentials['secret_key'],
			self::HOST,
			self::ACTION,
			self::VERSION,
			$timestamp,
			$payload
		);

		$result = $this->client->post( self::ENDPOINT, array(
			'headers'    => $signed['headers'],
			'body'       => $payload,
			'timeout'    => RemoteClient::DEFAULT_TIMEOUT,
			'usage_key'  => self::DRIVER_ID, // 计量：核验调用计入 usage（04 §6，统计展示口径）。
			'required'   => array( 'Response.CaptchaCode' ), // 缺失 → contract_mismatch 哨兵。
		) );

		return $this->consume_result_tri( $result, $ticket, $user_ip );
	}

	/**
	 * 旧契约 legacy 垫片（【v1.5·C1】接口外保留——05 §1.1 v1.5「verify()/last_verify_meta()
	 * 保留为垫片适配」；仅闸层能力探测失败路径与存量测试消费，M4 收线评估删除）。
	 *
	 * @param array<string, mixed> $context {ticket, randstr, user_ip}。
	 * @return string|null null = 通过；字符串 = 错误文案（输出层转义）。
	 */
	public function verify( array $context ): ?string {
		$tri = $this->verify_tri( $context );
		$this->last_meta = is_array( $tri['meta'] ?? null ) ? $tri['meta'] : array(); // 垫片平价（last_verify_meta）。
		if ( 'pass' === $tri['state'] ) {
			return null;
		}
		return (string) $tri['message'];
	}

	/**
	 * 最近一次 verify() 的事件 meta（Score/GetCaptchaTime/RequestCategory 等，闸读取落库）。
	 *
	 * @return array<string, mixed>
	 */
	public function last_verify_meta(): array {
		return $this->last_meta;
	}

	/**
	 * 凭据探测（Tab②「测试凭据」）：全参假票核验一次，返回传输脊结果——
	 * 三态判定（绿 7/8/15 / 红 AuthFailure / 黄 MissingParameter）在
	 * Settings\CredentialProbe::classify（L0-tencent 冒烟判定法，05 §1.1 凭据自检）。
	 *
	 * 与 verify() 的差异：不带 required 契约哨兵（API 级错误形态要原样透出给探测层）、
	 * 不消费 last_meta、不计入容灾限速；票据为固定假票（服务端 CaptchaCode=15 预期态）。
	 *
	 * @return RemoteResult
	 */
	public function probe_credentials(): RemoteResult {
		$payload = (string) json_encode( array(
			'CaptchaType'        => 9,
			'Ticket'             => 'wink-probe-fake-ticket',
			'Randstr'            => '@winkprobe',
			'UserIp'             => '127.0.0.1',
			'CaptchaAppId'       => (int) $this->credentials['app_id'],
			'AppSecretKey'       => $this->credentials['app_secret_key'],
			'NeedGetCaptchaTime' => 1,
		), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$timestamp = time();
		$signed    = self::tc3_authorization(
			$this->credentials['secret_id'],
			$this->credentials['secret_key'],
			self::HOST,
			self::ACTION,
			self::VERSION,
			$timestamp,
			$payload
		);

		return $this->client->post( self::ENDPOINT, array(
			'headers'   => $signed['headers'],
			'body'      => $payload,
			'timeout'   => RemoteClient::DEFAULT_TIMEOUT,
			'usage_key' => '', // 探测调用不计 usage（Tab② 现场诊断，非业务核验）。
		) );
	}

	/**
	 * 渲染容器（03 §2.1 v1.4 结构；frontend 元数据内联 data-* 供 gatekeeper.js 分派）。
	 *
	 * 【M2-FIX2 D1 v1.4 形态度（2026-11-10 用户反馈①）】弹层改嵌入式 + 三态单按钮：
	 *   - 评论域（gate=comment）：天御 2.0 嵌入式验证码（官方 Web 客户端接入「type:'embed'」
	 *     形态，尺寸 300×230）——挑战面板直接渲染在提交按钮下方的 .wk-gate-embed 挂载点内，
	 *     不再弹 fixed 层（旧形态 fixed 页顶，用户在页底评论表单处点击后看不到弹层——
	 *     D1 真相）。挂载点随本 HTML 尾随输出（span + div 兄弟段），SubmitRow 把 span 注入
	 *     提交控件之前、div 追加提交段落尾部（「容器放提交按钮下方」）。
	 *   - 登录域（gate=login）：维持弹层形态（wp-login 页面短、弹层可见；03 §2.2「提交时
	 *     唤起」链路用户实测可用）——无挂载点 div，data-mode="popup"。
	 *   - 三态单按钮（用户反馈②）：unverified「完成人机验证」→ pending「验证中…」→
	 *     verified「已通过验证」（disabled 绿态）；全部反馈由 wk-captcha-btn 单元素承载
	 *     （aria-live="polite" 上按钮），不再渲染独立状态字 .wk-captcha-status 与成功态
	 *     徽标 .wk-verify-done。
	 *
	 * @param array<string, mixed> $args {gate, action, context_id, stub}。
	 * @return string
	 */
	public function render( array $args ): string {
		$gate   = isset( $args['gate'] ) && is_scalar( $args['gate'] ) ? (string) $args['gate'] : 'comment';
		$stub   = isset( $args['stub'] ) && is_scalar( $args['stub'] ) ? (string) $args['stub'] : '';
		$app_id = (int) $this->credentials['app_id'];

		$esc = static function ( string $value ): string {
			return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
		};

		/* 【v1.4·R3/05 §1.1】embed gate 白名单：comment|login|register|lostpassword → embed
		 * （登录页嵌入式 03 §2.2 与注册/找回闸 03 §2.3 复用评论页 FIX2/FIX3 通道）；其余
		 * gate 维持 popup。SDK 嵌入组件实测固定宽 302px < wp-login 表单内容宽 320px——无裁切。 */
		$is_embed = in_array( $gate, array( 'comment', 'login', 'register', 'lostpassword' ), true );

		// 结构照 03 §2.1 v1.4：span 容器（存根 + 双 token 写域 + 三态按钮 + noscript）
		// ＋ embed 域尾随嵌入式挂载点（hidden 初始，适配器 show 时展开）。
		$html = '<span class="wk-gate" data-gate="' . $esc( $gate ) . '" data-driver="tencent"'
			. ' data-interaction="click"'
			. ' data-mode="' . ( $is_embed ? 'embed' : 'popup' ) . '"'
			. ' data-token-fields="' . self::TOKEN_FIELD_TICKET . ',' . self::TOKEN_FIELD_RANDSTR . '"'
			. ' data-app-id="' . $app_id . '">'
			. '<input type="hidden" name="wk_stub" value="' . $esc( $stub ) . '">'
			. '<input type="hidden" name="' . self::TOKEN_FIELD_TICKET . '" value="">'
			. '<input type="hidden" name="' . self::TOKEN_FIELD_RANDSTR . '" value="">'
			. '<span class="wk-gate-inline">'
			. '<button type="button" class="wk-captcha-btn wk-verify-btn button" data-wk-state="unverified"'
			. ' aria-live="polite">完成人机验证</button>'
			. '</span>'
			. '<noscript>需要启用 JavaScript 完成人机验证。</noscript>'
			. '</span>';

		if ( $is_embed ) {
			// 嵌入式挂载点：块级兄弟段（评论域=SubmitRow 追加到提交段落尾部；wp-login 三表单
			// =直接随表单流输出——.login 作用域样式 front-gate.css 一套复用，03 §2.2）。
			$html .= '<div class="wk-gate-embed" hidden></div>';
		}

		return $html;
	}

	/**
	 * 前端资产入队（WP 边界方法）：适配器脚本由 Bootstrap 注册 handle，此处仅入队。
	 */
	public function enqueue_assets(): void {
		wp_enqueue_script( 'wink-gatekeeper-driver-tencent' );
	}

	/* -------------------------------------------------------------------
	 * CaptchaCode 映射（纯函数）
	 * ------------------------------------------------------------------ */

	/**
	 * CaptchaCode + Ticket → 判定映射（05 §1.1 错误映射矩阵）。
	 *
	 * @param int    $code   CaptchaCode。
	 * @param string $ticket 本次票据（trerror 前缀探测）。
	 * @param string $policy 容灾策略（allow 默认/block——02 §2.1 disasterPolicy 消费方传入）。
	 * @return array{pass: bool, copy_key: string, hint: string, hint_kind: string, rule: string, disaster: bool}
	 */
	public static function verdict_for( int $code, string $ticket, string $policy = self::DISASTER_POLICY_ALLOW ): array {
		// 官方容灾语义：Code 21 + trerror 前缀票据 = 前端自动容灾生成的容灾票。
		// allow（默认）= 按通过放行（限速在 consume_result 内判——需要 IP）；block = 安全优先直拦。
		if ( 21 === $code && 0 === strpos( $ticket, 'trerror' ) ) {
			if ( self::DISASTER_POLICY_BLOCK === $policy ) {
				return array(
					'pass'      => false,
					'copy_key'  => 'captcha_failed',
					'hint'      => '刷新页面再试一次。',
					'hint_kind' => 'refresh',
					'rule'      => 'CaptchaCode=21/trerror-blocked',
					'disaster'  => true,
				);
			}
			return array(
				'pass'      => true,
				'copy_key'  => '',
				'hint'      => '',
				'hint_kind' => '',
				'rule'      => 'CaptchaCode=21/trerror',
				'disaster'  => true,
			);
		}

		if ( 1 === $code ) {
			return array(
				'pass'      => true,
				'copy_key'  => '',
				'hint'      => '',
				'hint_kind' => '',
				'rule'      => 'CaptchaCode=1',
				'disaster'  => false,
			);
		}

		$config_codes = array( 16, 100 );
		if ( in_array( $code, $config_codes, true ) ) {
			return array(
				'pass'      => false,
				'copy_key'  => 'captcha_failed',
				'hint'      => '站点验证码配置有误（请联系站长）。',
				'hint_kind' => 'config',
				'rule'      => 'CaptchaCode=' . $code,
				'disaster'  => false,
			);
		}

		// 7 Randstr 不合法 / 8 票据过期(5min) / 9 票据重复使用 / 15 票据不合法 / 21 风控异常 / 兜底。
		// 8/9 A-lite（M2 反馈2 T3）：验票结果短缓存窗口内「返回重提」不再走到本错误页——走到即
		// 超窗（>10min）或换了表单，文案两分支都给：窗口内直接返回重提（无需重验）、超窗重新点验证。
		$refresh_hint = in_array( $code, array( 8, 9 ), true )
			? '验证已生效过一次——10 分钟内：点浏览器返回修改评论再提交即可，无需刷新、无需重新验证；已超 10 分钟：返回后重新点击验证按钮。'
			: '刷新页面再试一次。';

		return array(
			'pass'      => false,
			'copy_key'  => 'captcha_failed',
			'hint'      => $refresh_hint,
			'hint_kind' => 'refresh',
			'rule'      => 'CaptchaCode=' . $code,
			'disaster'  => false,
		);
	}

	/* -------------------------------------------------------------------
	 * TC3-HMAC-SHA256 签名（C4 起委托共用工具 includes/http/Tc3Signer.php；
	 * 金测试 = 腾讯云《签名方法 v3》官方示例值，TMS 驱动与 L0 冒烟互验共用同一实现）
	 * ------------------------------------------------------------------ */

	/**
	 * 规范请求串（CanonicalRequest，签名 v3 步骤 1）——薄委托 Tc3Signer。
	 *
	 * @param string $host    请求主机。
	 * @param string $action  接口名（X-TC-Action）。
	 * @param string $payload 请求正文。
	 * @return string
	 */
	public static function tc3_canonical_request( string $host, string $action, string $payload ): string {
		return Tc3Signer::canonical_request( $host, $action, $payload );
	}

	/**
	 * TC3 签名与请求头（签名 v3 步骤 1-4）——薄委托 Tc3Signer。
	 *
	 * DescribeCaptchaResult 不需要 Region（手档：本接口不需要传递此参数）。
	 *
	 * @param string $secret_id  CAM SecretId。
	 * @param string $secret_key CAM SecretKey。
	 * @param string $host       请求主机。
	 * @param string $action     接口名。
	 * @param string $version    API 版本（X-TC-Version）。
	 * @param int    $timestamp  请求时间戳（秒）。
	 * @param string $payload    请求正文。
	 * @return array{Authorization: string, headers: array<string, string>}
	 */
	public static function tc3_authorization( string $secret_id, string $secret_key, string $host, string $action, string $version, int $timestamp, string $payload ): array {
		return Tc3Signer::authorization( $secret_id, $secret_key, $host, $action, $version, $timestamp, $payload );
	}

	/* -------------------------------------------------------------------
	 * 内部
	 * ------------------------------------------------------------------ */

	/**
	 * 消费传输脊结果 → verify_tri 三态 + 事件 meta（【v1.5·C1】DESIGN-C1 §5：error 分支返
	 * {state:'error',…}，meta/raw 组装逻辑原样搬；verdict_for() 纯函数与码表零改动）。
	 *
	 * @param RemoteResult $result  传输脊结果。
	 * @param string       $ticket  本次票据。
	 * @param string       $user_ip 本次提交者 IP（容灾限速键）。
	 * @return array{state: string, score: float|null, message: string, meta: array<string, mixed>}
	 */
	private function consume_result_tri( RemoteResult $result, string $ticket, string $user_ip ): array {
		if ( ! $result->is_ok() ) {
			// 【v1.4·R4 §D.1/D.3 → v1.5·C1 升格】远端终态以 error 态表达——meta.raw 必带
			// （单一来源 raw_snapshot；stats.raw_capture 关=省略）；rule_hit=remote_<category>
			// 既有形态保持；message=降级文案（02 §5 captcha 行）。
			$this->last_meta = array(
				'category' => $result->category(),
				'status'   => $result->status(),
				'rule'     => 'remote_' . $result->category(),
			);
			$message = self::MSG_TRANSPORT;
			if ( RemoteResult::CONTRACT === $result->category() ) {
				$this->last_meta['rule'] = 'contract_mismatch'; // R6/R9 厂商改版哨兵。
				$message                 = self::MSG_CONFIG;
			} elseif ( RemoteResult::TRANSPORT !== $result->category() ) {
				$message = self::MSG_CONFIG;
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

		$response = $result->data()['Response'];
		$code     = (int) ( $response['CaptchaCode'] ?? 0 );
		$mapped   = self::verdict_for( $code, $ticket, $this->disaster_policy );

		/* 容灾放行限速（C1：allow 档每 IP 每小时 DISASTER_LIMIT 次，超出按 block 拦）——
		 * 限速器未装配（单测默认/fail-open）时不设限，与 v1.2「恒放行」行为一致。 */
		if ( $mapped['disaster'] && $mapped['pass'] && null !== $this->disaster_limiter
			&& ! call_user_func( $this->disaster_limiter, $user_ip ) ) {
			$mapped = array(
				'pass'      => false,
				'copy_key'  => 'captcha_failed',
				'hint'      => '刷新页面再试一次。',
				'hint_kind' => 'refresh',
				'rule'      => 'CaptchaCode=21/trerror-limited',
				'disaster'  => true,
			);
		}

		// 事件 meta：CaptchaCode/CaptchaMsg/Score/GetCaptchaTime（附加信号，05 §1.1）。
		$meta = array( 'CaptchaCode' => $code );
		foreach ( array( 'CaptchaMsg', 'Score', 'GetCaptchaTime', 'RequestId' ) as $field ) {
			if ( isset( $response[ $field ] ) && is_scalar( $response[ $field ] ) ) {
				$meta[ $field ] = $response[ $field ];
			}
		}
		$meta['rule'] = $mapped['rule'];
		if ( $mapped['disaster'] && $mapped['pass'] ) {
			$meta['disaster_pass'] = true; // 闸侧凭此标注落 pass 事件（rule_hit=trerror，统计可查容灾放行量）。
		}
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
	 * 容灾放行限速器（WP 边界，Bootstrap 注入）——【v1.5·C1】委托共享件 DegradedPassLimiter
	 * （02 §2.1：天御 disaster 与 captcha pass_limited 两个消费方共用同一 TokenBucket 形态；
	 * wk_dsr_ 前缀 + {reset,count} 桶形态不变——存量 transient 桶不失效）。
	 *
	 * @param string $ip 提交者真实 IP。
	 * @return bool
	 */
	public static function wp_disaster_limiter( string $ip ): bool {
		$limiter = new \WinkGatekeeper\Gates\DegradedPassLimiter(
			self::DISASTER_LIMIT,
			self::DISASTER_WINDOW,
			'get_transient',
			static function ( string $key, $value, int $ttl ): void {
				set_transient( $key, $value, $ttl ); // WP 边界。
			},
			null, // wp_salt('auth') 默认（WP 边界——与旧 wk_dsr_ 键派生同源）。
			\WinkGatekeeper\Gates\DegradedPassLimiter::DISASTER_PREFIX
		);
		return $limiter->allow( $ip );
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
