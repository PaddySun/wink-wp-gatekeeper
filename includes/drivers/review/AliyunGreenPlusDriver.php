<?php
/**
 * 阿里云内容安全·文本审核增强版 PLUS 驱动（05 §2.5【v1.5】——M3' 批次 3；02 §2.2 双 Service 分送例外）。
 *
 * 服务端：POST https://green-cip.cn-shanghai.aliyuncs.com/ · Action=TextModerationPlus ·
 * x-acs-version=2022-03-02（Green 2022-03-02——区别于旧版 Green TextModeration antispam）。
 * 请求形态（L0-ali-green-pro 实调 7/7 定稿）：RPC style · pathname=/ · form body
 * `Service=<服务名>&ServiceParameters=<JSON>`（content-type: application/x-www-form-urlencoded
 * 参与签名——AliyunV3Signer extra_signed_headers 通道，B3 已备零新增签名代码）。
 * ServiceParameters JSON = {content（≤600 字）, dataId（≤64 字符对账用）}——accountId 不送
 * （PIPL 最小化，05 §2.5）。HTTP 状态恒 200 为主（400 也包在 HTTP 200）——error_map 按
 * body `Code` 收口（inspect_body 纯函数，与 TMS body 级惯例一致）。
 *
 * 判定语义（L0 定稿·双轨制）：全局风险等级桶 RiskLevel（high/medium/low/none）+ 标签数组
 * 逐条 Confidence(0-100)。**无单一总分——不引入 reject_score 阈值语义**（与 TMS 不同构）。
 * 收口次序：先剔除 nonLabel 伪标签（L0 实测修正：干净文本 Result 返回单条 nonLabel 而非空数组），
 * 再映射：none/low→pass / medium→review（hits=非 nonLabel 标签集）/ high→reject
 * （reason=首条 `Label,Description`，hits=全部 Label，meta 记 Confidence+RiskWords 摘要）；
 * 兜底 RiskLevel 空/缺失但存在非 nonLabel 标签 → review（从严不误杀不直接拒）；
 * RiskLevel 枚举漂移 → fail-safe review + meta 标注（TMS unknown_suggestion 先例）。
 * Confidence 不参与判定收口（展示用；本站控制台未开细粒度检测时恒 100——05 §2.5 注记）。
 *
 * 双 Service 分送（02 §2.2 例外条款·DESIGN-D2 拍板 D20）：默认昵称→nickname_detection_pro
 * 单独一调（昵称特化模型——L0 实测 10 字广告昵称即命中 pt_* 三标签）、正文→
 * comment_detection_pro 一调（**邮箱不并入**——真机实锤 pt_to_contact 对邮箱地址本身误杀，
 * 见 IMPL-B5 §3 偏差 3；邮箱防线在 LocalRules 拼接串）；先昵称后正文（reject 快短路省第二调）；
 * 两调 OR 取严（任一 reject 即整体 reject）。**单通道故障不吞另一通道**：一通道 error 时
 * 另一通道判定仍有效（last_meta 记 `<channel>_error`）；双通道全故障才 verdict=error
 * （02 §2.2「error 不参与定级、取最严有效判定」的驱动内 OR 推广——IMPL-B5 §3 偏差 2 留档）。
 * F36 兼容回退：`review.cloud.aliyun_green.dual_dispatch=false` 时拼接串单调 comment 通道
 * （闸统一拼装口径原样送审）；`review.include_author_fields=false` 时单调仅正文（同口径）。
 *
 * 超长处理：content >600 字 → mb_substr 截断 + meta 标 truncated/original_chars（对齐 TMS
 * 10,000 字符截断先例；comment/nickname 族上限 600 字，05 §2.5）。空串本地防呆不发
 * （实测空 content → Code=400 "content is blank"，语义模糊不触达）。
 *
 * 用量/配额：双通道两调均计入 usage（usage_key=aliyun_green，成本 ×2 如实计量——04 §6；
 * 配额停转判定在闸编排，驱动不各自计数）。
 *
 * 分层：truncate/build_payload/inspect_body/映射与合并为纯逻辑（零 WP 依赖）；
 * probe_credentials 供 Tab③「测试凭据」三态判定消费（CredentialProbe::classify_aliyun_green）。
 *
 * @package WinkGatekeeper\Drivers\Review
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Drivers\Review;

use WinkGatekeeper\Drivers\ReviewDriverInterface;
use WinkGatekeeper\Http\AliyunV3Signer;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Settings\DriverPrivacy;
use WinkGatekeeper\Verdict;

final class AliyunGreenPlusDriver implements ReviewDriverInterface {

	public const DRIVER_ID  = 'aliyun_green';
	public const ENDPOINT   = 'https://green-cip.cn-shanghai.aliyuncs.com/';
	public const HOST       = 'green-cip.cn-shanghai.aliyuncs.com';
	public const ACTION     = 'TextModerationPlus';
	public const VERSION    = '2022-03-02';

	/**
	 * content 上限（comment/nickname 族 600 字——05 §2.5；超长 mb_substr 截断 + meta 标注）。
	 */
	public const MAX_CONTENT_CHARS = 600;

	/**
	 * 双 Service 默认名（04 §2 review.aliyun_green.service_nickname/service_comment；
	 * 可配——站长控制台开通的其它 comment/nickname 族服务名可替换）。
	 */
	public const DEFAULT_SERVICE_NICKNAME = 'nickname_detection_pro';
	public const DEFAULT_SERVICE_COMMENT  = 'comment_detection_pro';

	/** 凭据探测用良性短文本（Tab③ 三态：绿=Code 200；不用空 content——400 语义模糊）。 */
	public const PROBE_TEXT = '凭据自检';

	/** @var array{access_key_id: string, access_key_secret: string, service_nickname: string, service_comment: string, timeout: int, dual_dispatch: bool} */
	private $config;

	/**
	 * @var array<string, mixed> stats 子树（raw 快照开关——error 分支一行合并消费）。
	 */
	private $stats_settings = array();

	/**
	 * @var bool review.include_author_fields（F36 拼接开关——关=单调仅正文，02 §2.2）。
	 */
	private $include_author_fields = true;

	/**
	 * @var RemoteClient 传输脊（全库唯一 HTTP 出口）。
	 */
	private $client;

	/**
	 * @var array<string, mixed> 最近一次 check() 的事件 meta（双通道 RiskLevel/labels/截断/错误类别）。
	 */
	private $last_meta = array();

	/**
	 * @param array<string, mixed> $settings 完整设置树（读 review.cloud.aliyun_green 子树）。
	 * @param RemoteClient         $client   传输脊实例。
	 */
	public function __construct( array $settings, RemoteClient $client ) {
		$green = isset( $settings['review']['cloud']['aliyun_green'] ) && is_array( $settings['review']['cloud']['aliyun_green'] )
			? $settings['review']['cloud']['aliyun_green']
			: array();

		$this->config = array(
			// trim 收口（C1 真机踩坑）：凭据尾随 \r/空格（env 通道 CRLF 残留）会破坏 ACS3 HMAC。
			'access_key_id'     => trim( is_string( $green['access_key_id'] ?? null ) ? $green['access_key_id'] : '' ),
			'access_key_secret' => trim( is_string( $green['access_key_secret'] ?? null ) ? $green['access_key_secret'] : '' ),
			// Service 名：schema 缺键回落 pro 默认（存量树 merge_defaults 前的构造兜底）；
			// 显式空串不回落（黄态「Service 名配置为空」= is_configured false，05 §2.5）。
			'service_nickname'  => isset( $green['service_nickname'] ) && is_string( $green['service_nickname'] )
				? trim( $green['service_nickname'] )
				: self::DEFAULT_SERVICE_NICKNAME,
			'service_comment'   => isset( $green['service_comment'] ) && is_string( $green['service_comment'] )
				? trim( $green['service_comment'] )
				: self::DEFAULT_SERVICE_COMMENT,
			'timeout'           => max( 1, (int) ( $green['timeout'] ?? 8 ) ),
			'dual_dispatch'     => ! isset( $green['dual_dispatch'] ) || (bool) $green['dual_dispatch'],
		);
		$this->include_author_fields = ! isset( $settings['review']['include_author_fields'] )
			|| (bool) $settings['review']['include_author_fields'];
		$this->stats_settings = isset( $settings['stats'] ) && is_array( $settings['stats'] ) ? array( 'stats' => $settings['stats'] ) : array();
		$this->client = $client;
	}

	/**
	 * 驱动注册元数据（05 §4 七维；05 §2.5 registry 建议行）。
	 *
	 * @return array<string, mixed>
	 */
	public static function registry_entry(): array {
		return array(
			'id'           => self::DRIVER_ID,
			'type'         => 'review',
			'class'        => self::class,
			'label'        => '阿里云内容安全·文本审核增强版 PLUS',
			'reachability' => 'cn-excellent', // L0 实测 657–947ms（05 §2.5 可达性行）。
			'billing'      => 'usage',        // text_standard 档 7.5 元/万次按量（双送 ×2 如实计量）。
			'frontend'     => array(),        // review 类无前端形态.
			'error_map'    => array( 'body_probe' => 'inspect_body' ), // body Code 级→四类（HTTP 恒 200 为主）.
			'api_version'  => self::VERSION,
			'settings'     => array(
				'access_key_id'     => array( 'type' => 'password', 'sanitize' => 'password', 'label' => 'AccessKeyId', 'required' => true ),
				'access_key_secret' => array( 'type' => 'password', 'sanitize' => 'password', 'label' => 'AccessKeySecret', 'required' => true ),
				'service_nickname'  => array( 'type' => 'text', 'sanitize' => 'text', 'label' => '昵称 Service 名', 'required' => true ),
				'service_comment'   => array( 'type' => 'text', 'sanitize' => 'text', 'label' => '正文 Service 名', 'required' => true ),
				'timeout'           => array( 'type' => 'number', 'sanitize' => 'absint', 'label' => '超时（秒）' ),
				'daily_quota'       => array( 'type' => 'number', 'sanitize' => 'absint', 'label' => '日配额' ),
				'fail_mode'         => array( 'type' => 'text', 'sanitize' => 'text', 'label' => '故障降级' ),
				'dual_dispatch'     => array( 'type' => 'bool', 'sanitize' => 'bool', 'label' => '双 Service 分送（关=拼接串单调兼容回退）' ),
			),
			'privacy'      => DriverPrivacy::entry( self::DRIVER_ID ), // 第七维（内容表单一来源引用，禁双源漂移）.
			'guide'        => array( // 【v1.4·R4 §A.5】接入指引下放 registry（guide 维渲染）。
				'steps' => array(
					'1. 阿里云控制台开通「内容安全 · 文本审核增强版」（TextModerationPlus，Green 2022-03-02）。',
					'2. 准备 RAM AccessKey 对（可与验证码 2.0 共用同一 RAM 用户——同一账号双产品线，L0 实测注记），并授予 AliyunYundunGreenWebFullAccess 权限。',
					'3. 粘贴至下方（双 Service 名默认 nickname/comment_detection_pro，一般无需修改）→ 点「测试凭据」。',
					'4. 判定映射（站长不可调）：RiskLevel none/low→放行、medium→入待审、high→直接拦截；分档界线在阿里控制台规则配置。如需 medium/low 中间态与小数置信分，请在内容安全控制台规则配置中开启细粒度检测。',
				),
				'doc_url' => 'https://help.aliyun.com/zh/green/developer-reference/api-green-2022-03-02-textmoderationplus',
			),
			'doc_url'      => 'https://help.aliyun.com/zh/green/developer-reference/api-green-2022-03-02-textmoderationplus',
			'milestone'    => 'M3',
		);
	}

	/**
	 * AccessKey 对 + 双 Service 名齐全才算已配置（Service 名显式清空 = 未配置——黄态列缺，
	 * 05 §2.5 凭据自检黄态条款；未配置不应入链，Bootstrap 构造期过滤）。
	 */
	public function is_configured(): bool {
		return '' !== $this->config['access_key_id']
			&& '' !== $this->config['access_key_secret']
			&& '' !== $this->config['service_nickname']
			&& '' !== $this->config['service_comment'];
	}

	/**
	 * 审核一次（02 §2.2 契约：$content = 闸拼装的送审串，驱动不自拼；分送是驱动内消费策略——
	 * 闸仍传拼接串 + $meta 原值，02 §2.2 v1.5 阿里例外注）。
	 *
	 * @param string               $content 送审串（「昵称 \n 邮箱 \n 正文」拼接或正文）。
	 * @param array<string, mixed> $meta    开放键集（author/email/content/ip_hash/…按需消费）。
	 * @return Verdict pass/reject/review；双通道全故障 = error（链上 failover，闸侧收口）。
	 */
	public function check( string $content, array $meta ): Verdict {
		$this->last_meta = array(
			'dispatch' => $this->dispatch_mode() ? 'dual' : 'single',
		);

		if ( ! $this->is_configured() ) {
			// 未配置不应入链（Bootstrap 构造期过滤）；防御兜底 = error，闸按云端全故障收口。
			return Verdict::error( self::DRIVER_ID, 'not_configured' );
		}

		$body = isset( $meta['content'] ) && is_string( $meta['content'] ) ? $meta['content'] : $content;
		$dual = $this->dispatch_mode();

		if ( $dual ) {
			$author = isset( $meta['author'] ) && is_string( $meta['author'] ) ? $meta['author'] : '';
			$email  = isset( $meta['email'] ) && is_string( $meta['email'] ) ? $meta['email'] : '';

			/* 【真机修正 2026-11-11（IMPL-B5 §3 偏差 3）】邮箱**不并入**阿里送审串——设计原口径
			 * 「邮箱并入正文串」真机实锤误杀：comment_detection_pro 把任何邮箱地址本身判为
			 * pt_to_contact（引流广告号）RiskLevel=high（事件 WK-20261110-0001：正常评论
			 * RiskWords=b5-pass@example.com,example.com 被拒）。邮箱防线收回到 LocalRules
			 * 拼接串（闸级 F36 拼接仍含邮箱——U2 #6 教训不变）；TMS/LLM 驱动拼接串口径不变。 */
			$comment_content = $body;

			$valid = array(); // 非 error 的通道判定（OR 取严——02 §2.2 通道级推广）。
			$made  = false;   // 是否实际发起过任一调用（全空内容防呆判定）。

			// 先昵称后正文（快短路）：昵称 reject 即整体 reject，不烧第二调。
			if ( '' !== trim( $author ) ) {
				$outcome = $this->run_channel( 'nickname', $this->config['service_nickname'], $author, $meta );
				$made    = true;
				if ( null !== $outcome['verdict'] ) {
					if ( Verdict::REJECT === $outcome['verdict']->verdict() ) {
						return $outcome['verdict'];
					}
					$valid[] = $outcome['verdict'];
				}
			}

			if ( '' !== trim( $comment_content ) ) {
				$outcome = $this->run_channel( 'comment', $this->config['service_comment'], $comment_content, $meta );
				$made    = true;
				if ( null !== $outcome['verdict'] ) {
					if ( Verdict::REJECT === $outcome['verdict']->verdict() ) {
						return $outcome['verdict'];
					}
					$valid[] = $outcome['verdict'];
				}
			}

			// 两通道均零内容（全空提交）→ 本地防呆：零外发，放行 + meta 标注。
			if ( ! $made ) {
				$this->last_meta['skipped'] = 'empty_content';
				return Verdict::pass( self::DRIVER_ID, '', array() );
			}

			return $this->merge( $valid );
		}

		/* 单调（F36 兼容回退 / include_author_fields 关）：闸拼装串原样送 comment 通道。 */
		if ( '' === trim( $content ) ) {
			$this->last_meta['skipped'] = 'empty_content';
			return Verdict::pass( self::DRIVER_ID, '', array() );
		}
		$outcome = $this->run_channel( 'comment', $this->config['service_comment'], $content, $meta );
		if ( null === $outcome['verdict'] ) {
			return Verdict::error( self::DRIVER_ID, 'remote_' . $outcome['category'], array( 'remote_' . $outcome['category'] ) );
		}
		return $outcome['verdict'];
	}

	/**
	 * 最近一次 check() 的事件 meta（双通道 RiskLevel/labels/Confidence+RiskWords 摘要/
	 * 截断标注/单通道错误类别）。
	 *
	 * @return array<string, mixed>
	 */
	public function last_check_meta(): array {
		return $this->last_meta;
	}

	/**
	 * 凭据探测（Tab③「测试凭据」）：良性短文本（content=凭据自检）走 comment 通道真调用
	 * 一次，返回传输脊结果——三态判定（绿 Code=200 / 红 Code=408 PERMISSION_DENY 或
	 * InvalidAccessKeyId 与 SignatureDoesNotMatch 前缀 / 黄本地必填缺）在
	 * Settings\CredentialProbe::classify_aliyun_green（05 §2.5 凭据自检三态）。
	 *
	 * 与 check() 的差异：不计 usage（现场诊断）、不带 required 契约哨兵（API 级错误形态
	 * 原样透出给三态判定层）、不消费 last_meta。
	 *
	 * @return RemoteResult
	 */
	public function probe_credentials(): RemoteResult {
		return $this->call( $this->config['service_comment'], self::PROBE_TEXT, 'probe', '' );
	}

	/* -------------------------------------------------------------------
	 * 纯逻辑（零 WP 依赖；单测直测）
	 * ------------------------------------------------------------------ */

	/**
	 * 送审长度截断（content ≤600 字——comment/nickname 族上限，05 §2.5）。
	 *
	 * @param string $content 送审串。
	 * @return array{content: string, truncated: bool, original_chars: int}
	 */
	public static function truncate_content( string $content ): array {
		$length = mb_strlen( $content, 'UTF-8' );
		if ( $length <= self::MAX_CONTENT_CHARS ) {
			return array( 'content' => $content, 'truncated' => false, 'original_chars' => $length );
		}
		return array(
			'content'        => mb_substr( $content, 0, self::MAX_CONTENT_CHARS, 'UTF-8' ),
			'truncated'      => true,
			'original_chars' => $length,
		);
	}

	/**
	 * 请求体构造（L0 实调形态：Service=..&ServiceParameters=<JSON，rawurlencode；
	 * ServiceParameters JSON = {content, dataId}——accountId 不送，PIPL 最小化）。
	 *
	 * @param string $service 服务名。
	 * @param string $content 已截断的送审文本。
	 * @param string $data_id 对账用业务数据 ID（≤64 字符）。
	 * @return string form body。
	 */
	public static function build_payload( string $service, string $content, string $data_id ): string {
		$sp = (string) json_encode(
			array(
				'content' => $content,
				'dataId'  => $data_id,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		return 'Service=' . rawurlencode( $service ) . '&ServiceParameters=' . rawurlencode( $sp );
	}

	/**
	 * body 错误探测（05 §2.5 error_map；registry error_map.body_probe 声明的回调）。
	 *
	 * Green 增强版 HTTP 状态恒 200 为主（L0 实测 400 也包在 HTTP 200）——业务错误码在
	 * body 顶层 `Code`（整型 200/400/408/500/581/588，4xx 网关错时为字符串如
	 * SignatureDoesNotMatch）。归四类：
	 *   semantic —— 400（参数错）/ 408（PERMISSION_DENY 未授权/欠费/未开通）：不重试，闸侧当日停转；
	 *   transport —— 500（服务端）/ 581（超时）/ 588（超配额，TransportError 变体）：退避重试后终态；
	 *   null —— Code=200 成功（交 required 契约收口）/ 未知 Code（契约哨兵）/ 无 Code。
	 *
	 * @param mixed  $decoded json_decode 后的响应体（可能 null）。
	 * @param string $body    原始响应体。
	 * @return string|null 四类之一或 null。
	 */
	public static function inspect_body( $decoded, string $body ): ?string {
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		$code = $decoded['Code'] ?? null;
		if ( is_int( $code ) ) {
			$code = (string) $code;
		}
		if ( ! is_string( $code ) || '' === $code || '200' === $code ) {
			return null;
		}

		if ( '400' === $code || '408' === $code ) {
			return RemoteResult::SEMANTIC;
		}
		if ( '500' === $code || '581' === $code || '588' === $code ) {
			return RemoteResult::TRANSPORT;
		}
		return null; // 未知 Code：落 required（contract）/parse 兜底（契约哨兵）.
	}

	/* -------------------------------------------------------------------
	 * 内部
	 * ------------------------------------------------------------------ */

	/**
	 * 是否双通道分送（dual_dispatch 开 + include_author_fields 开——02 §2.2 例外条款；
	 * 任一关即回退单调拼接串口径）。
	 */
	private function dispatch_mode(): bool {
		return $this->config['dual_dispatch'] && $this->include_author_fields;
	}

	/**
	 * 单通道执行：截断 → 签名 → 传输脊 → 判定映射；error 时落通道错误 meta（不抛出）。
	 *
	 * @param string               $channel 通道名（nickname|comment——dataId 前缀与 meta 键）。
	 * @param string               $service 服务名。
	 * @param string               $content 送审文本（未截断）。
	 * @param array<string, mixed> $meta    check() 透传 meta（ip_hash 预留）。
	 * @return array{verdict: Verdict|null, category: string} verdict=null = 通道 error。
	 */
	private function run_channel( string $channel, string $service, string $content, array $meta ): array {
		// 超长标注（对齐 TMS 截断先例）：实际截断在 call() 内，meta 标注在此一次性登记。
		$pre = self::truncate_content( $content );
		if ( $pre['truncated'] ) {
			$this->last_meta['truncated']      = true;
			$this->last_meta['original_chars'] = max( (int) ( $this->last_meta['original_chars'] ?? 0 ), $pre['original_chars'] );
		}

		$result = $this->call( $service, $content, $channel, $this->usage_key() );

		if ( ! $result->is_ok() ) {
			// 单通道故障不吞另一通道（02 §2.2 例外条款）：verdict=null，错误落 meta，
			// 由 merge()/check() 决定整体判定；双通道全故障才 error。
			$this->last_meta[ $channel . '_error' ] = array(
				'category' => $result->category(),
				'status'   => $result->status(),
			);
			if ( ! isset( $this->last_meta['category'] ) ) {
				// 首个故障通道作为整体 error 收口的类别/raw 快照来源（双通道全故障时消费）。
				$this->last_meta['category'] = $result->category();
				$this->last_meta['status']   = $result->status();
				$raw = RemoteResult::raw_snapshot_from_settings( $result, $this->stats_settings );
				if ( null !== $raw ) {
					$this->last_meta['raw'] = $raw;
				}
			}
			return array( 'verdict' => null, 'category' => $result->category() );
		}

		$data   = is_array( $result->data()['Data'] ?? null ) ? $result->data()['Data'] : array();
		$mapped = self::map_risk_level( $data );

		$this->last_meta[ $channel ] = array(
			'service'    => $service,
			'risk_level' => isset( $data['RiskLevel'] ) && is_string( $data['RiskLevel'] ) ? $data['RiskLevel'] : '',
			'labels'     => $mapped['labels'],
			'data_id'    => isset( $data['DataId'] ) && is_string( $data['DataId'] ) ? $data['DataId'] : '',
			'request_id' => $this->request_id_of( $result ),
		);

		$verdict = Verdict::pass( self::DRIVER_ID, $mapped['reason'], $mapped['hits'] );
		if ( Verdict::REJECT === $mapped['verdict'] ) {
			$verdict = Verdict::reject( self::DRIVER_ID, $mapped['reason'], $mapped['hits'] );
		} elseif ( Verdict::REVIEW === $mapped['verdict'] ) {
			$verdict = Verdict::review( self::DRIVER_ID, $mapped['reason'], $mapped['hits'] );
		}

		return array(
			'verdict'  => $verdict,
			'category' => '',
		);
	}

	/**
	 * 传输脊调用（签名 → RemoteClient；调用方决定 usage_key）。
	 *
	 * @param string $service   服务名。
	 * @param string $content   送审文本（未截断——本方法内截断）。
	 * @param string $channel   通道名（dataId 前缀）。
	 * @param string $usage_key usage 计量键（'' = 不计量——probe）。
	 * @return RemoteResult
	 */
	private function call( string $service, string $content, string $channel, string $usage_key ): RemoteResult {
		$truncated = self::truncate_content( $content );
		$data_id   = 'wk-' . $channel . '-' . substr( hash( 'sha256', $content ), 0, 12 ); // ≤64 字符，无 PII。
		$payload   = self::build_payload( $service, $truncated['content'], $data_id );

		$signed = AliyunV3Signer::sign(
			'POST',
			self::HOST,
			'/',
			array(),
			array( 'content-type' => 'application/x-www-form-urlencoded' ), // formData 形态：content-type 参与签名。
			$payload,
			$this->config['access_key_id'],
			$this->config['access_key_secret'],
			self::ACTION,
			self::VERSION
		);

		return $this->client->post( self::ENDPOINT, array(
			'headers'   => $signed['headers'],
			'body'      => $payload,
			'timeout'   => $this->config['timeout'],
			'usage_key' => $usage_key, // 双通道两调均计量（成本 ×2 如实，04 §6）。
			'required'  => array( 'Data.RiskLevel', 'Data.Result' ), // 缺失 → contract_mismatch 哨兵。
			'probe'     => array( self::class, 'inspect_body' ),     // body Code 归四类（error_map）。
		) );
	}

	/**
	 * usage 计量键（probe_credentials 走 call('','',…,'') 不经此）。
	 */
	private function usage_key(): string {
		return self::DRIVER_ID;
	}

	/**
	 * 有效判定合并（OR 取严——02 §2.2 串联语义的通道级推广）：
	 * reject > review > pass；全空 = 双通道全故障 → error（last_meta.category 由
	 * run_channel 首故障通道预置，闸侧据此当日停转）。
	 *
	 * @param Verdict[] $valid 非 error 的通道判定。
	 * @return Verdict
	 */
	private function merge( array $valid ): Verdict {
		if ( array() === $valid ) {
			$category = is_string( $this->last_meta['category'] ?? null ) ? $this->last_meta['category'] : RemoteResult::TRANSPORT;
			return Verdict::error( self::DRIVER_ID, 'remote_' . $category, array( 'remote_' . $category ) );
		}
		$severe = Verdict::most_severe( ...$valid );
		return null !== $severe ? $severe : Verdict::pass( self::DRIVER_ID, '', array() );
	}

	/**
	 * RequestId 提取（响应侧对账字段）。
	 *
	 * @param RemoteResult $result
	 * @return string
	 */
	private function request_id_of( RemoteResult $result ): string {
		$data = $result->data();
		$id   = is_array( $data ) ? ( $data['RequestId'] ?? '' ) : '';
		return is_scalar( $id ) ? (string) $id : '';
	}

	/**
	 * 双轨判定映射（05 §2.5 三态映射·规范性；纯函数）。
	 *
	 * 收口次序：① 剔除 nonLabel 伪标签（L0 实测修正：干净文本返回单条 nonLabel 而非空数组，
	 * 直接判空会误判）→ ② RiskLevel 映射：none/low→pass / medium→review / high→reject；
	 * ③ 兜底：RiskLevel 空/缺失但存在非 nonLabel 标签 → review（从严不误杀不直接拒）；
	 * ④ RiskLevel 未知枚举 → review fail-safe + reason 原值（TMS unknown_suggestion 先例）。
	 * Confidence 不参与判定收口（展示用；meta 记 Confidence+RiskWords 摘要）。
	 *
	 * @param array<string, mixed> $data 响应 Data 子树（Result[]/RiskLevel）。
	 * @return array{verdict: string, reason: string, hits: string[],
	 *               labels: array<int, array{label: string, confidence: int, risk_words: string}>}
	 */
	public static function map_risk_level( array $data ): array {
		$result = is_array( $data['Result'] ?? null ) ? $data['Result'] : array();
		$risk   = isset( $data['RiskLevel'] ) && is_string( $data['RiskLevel'] ) ? $data['RiskLevel'] : '';

		$labels = array();
		$hits   = array();
		foreach ( $result as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$label = isset( $entry['Label'] ) && is_string( $entry['Label'] ) ? $entry['Label'] : '';
			if ( '' === $label || 'nonLabel' === $label ) {
				continue; // nonLabel 伪标签剔除（不映射不落 hits）。
			}
			$confidence = isset( $entry['Confidence'] ) && is_numeric( $entry['Confidence'] ) ? (int) $entry['Confidence'] : 0;
			$risk_words = isset( $entry['RiskWords'] ) && is_string( $entry['RiskWords'] ) ? $entry['RiskWords'] : '';
			$labels[]   = array(
				'label'      => $label,
				'confidence' => $confidence,
				'risk_words' => $risk_words,
			);
			$hits[]     = $label;
		}

		$out = array(
			'verdict' => Verdict::REVIEW,
			'reason'  => '',
			'hits'    => $hits,
			'labels'  => $labels,
		);

		if ( 'none' === $risk || 'low' === $risk ) {
			$out['verdict'] = Verdict::PASS;
			$out['reason']  = '';
			$out['hits']    = array();
			return $out;
		}

		if ( 'high' === $risk ) {
			$out['verdict'] = Verdict::REJECT;
		}
		// medium（与兜底/未知枚举）= REVIEW（初值）——high 有标签时也走下方 reason 组装。

		if ( array() === $labels ) {
			// 非 none/low 且零风险标签：RiskLevel 已知（medium/high 却无标签=厂商形态漂移）
			// 与未知枚举同归 review fail-safe（reason=风险档原值供排查）。
			$out['reason'] = '' !== $risk ? $risk : 'unknown_risk_level';
			return $out;
		}

		// reason = 首条 Label,Description（05 §2.5 reject 行；Description 官方中文描述——
		// 官方声明可能变，不当判据只作展示）。
		$first           = $labels[0];
		$description     = '';
		foreach ( $result as $entry ) {
			if ( is_array( $entry ) && isset( $entry['Label'], $entry['Description'] )
				&& is_string( $entry['Label'] ) && $entry['Label'] === $first['label']
				&& is_string( $entry['Description'] ) ) {
				$description = $entry['Description'];
				break;
			}
		}
		$out['reason'] = $first['label'] . ( '' !== $description ? ',' . $description : '' );
		return $out;
	}
}
