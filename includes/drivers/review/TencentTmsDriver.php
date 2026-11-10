<?php
/**
 * 腾讯云 TMS 文本审核驱动（05 §2.4 腾讯 TMS 行——C4 实测定稿；02 §2.2/§2.5 契约）。
 *
 * 服务端：POST https://tms.tencentcloudapi.com/ · X-TC-Action=TextModeration ·
 * X-TC-Version=2020-12-29 · X-TC-Region=ap-guangzhou（地域型服务公共头必填，
 * L0 实测可用；公共参数不参与 TC3 签名）。全部经传输脊 RemoteClient 发出
 * （02 §2.5 唯一出口；TC3 签名复用共用工具 Tc3Signer——C4 自天御驱动抽取）。
 *
 * 请求形态（smoke-tencent.php L0 实测 > 一切文档）：
 *   Content = base64( UTF-8 送审串 )，以 Unicode 计量 ≤10,000 字符（官方 API 文档
 *   51860；超长由 truncate_content 截断并记 meta）；BizType = 控制台策略编号
 *   （官方可选——不传走默认策略；建议填写绑定套餐包的策略，Tab③ 帮助文案引导）。
 *
 * 判定映射（L0 实测：你好→Pass/Normal/0；广告词→Block/Ad/99）：
 *   Suggestion Pass → pass / Review → review / Block → Score ≥ reject_score（默认 80）
 *   才 reject、低于阈值或缺分降 review（误杀防线——本地规则高置信直拒、云端高置信才直拒，
 *   02 §2.2 「Reject 只用于高置信」在分数域的落地；默认 80 依据：L0 实测恶意样本 99 分
 *   与灰区样本低分的间隔中位，留出 20 分缓冲）。
 *   reason =「Label,Score」（如 Ad,99——L0 实测格式）；hits = [Label]（05 §2.4 口径，
 *   Keywords 不外送存储）。
 *
 * error_map（02 §2.5 body 错误映射）：TMS 业务错误码在 2xx body 中
 * （Response.Error.Code）——inspect_body 纯函数归四类：AuthFailure 系/
 * UnauthorizedOperation（未开通套餐、欠费）/参数错系 → semantic（不重试，闸侧当日停转）；
 * 限流系/内部错误/暂不可用 → transport（传输脊退避重试后终态）；未知码 → 兜底
 * （required 契约哨兵 / parse）。HTTP 层分类已在 RemoteResult::classify 完成。
 *
 * 用量/配额：计量经传输脊 usage_key=tencent_tms（04 §6）；配额停转判定在闸编排
 * （Bootstrap halt_check 闭包——驱动不各自计数）。
 *
 * 分层：truncate/payload/inspect_body/verdict 映射为纯逻辑（零 WP 依赖）；
 * probe_credentials 供 Tab③「测试凭据」AJAX 边界消费。
 *
 * @package WinkGatekeeper\Drivers\Review
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Drivers\Review;

use WinkGatekeeper\Drivers\ReviewDriverInterface;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Http\Tc3Signer;
use WinkGatekeeper\Settings\DriverPrivacy;
use WinkGatekeeper\Verdict;

final class TencentTmsDriver implements ReviewDriverInterface {

	public const DRIVER_ID = 'tencent_tms';
	public const ENDPOINT  = 'https://tms.tencentcloudapi.com/';
	public const HOST      = 'tms.tencentcloudapi.com';
	public const ACTION    = 'TextModeration';
	public const VERSION   = '2020-12-29';
	public const REGION    = 'ap-guangzhou'; // L0 实测可用；TextModeration 地域型服务必填公共头。

	/**
	 * Content 以 Unicode 编码计量 ≤10,000 字符（官方 API 文档 51860）。
	 */
	public const MAX_CONTENT_CHARS = 10000;

	/**
	 * Block 直拒的 Score 阈值默认值（04 §2 review.cloud.tencent_tms.reject_score；
	 * 站长可调 0-100，0 = 一切 Block 直拒）。
	 */
	public const DEFAULT_REJECT_SCORE = 80;

	/** 凭据探测用良性样本文本（Tab③ 三态判定：正常文本 → Pass 绿）。 */
	public const PROBE_TEXT = '你好，这篇文章写得真好';

	/**
	 * @var array{secret_id: string, secret_key: string, biz_type: string, timeout: int, reject_score: int}
	 */
	private $config;

	/**
	 * @var array<string, mixed> stats 子树（raw 快照开关/截断——error 分支一行合并消费）。
	 */
	private $stats_settings = array();

	/**
	 * @var RemoteClient 传输脊（全库唯一 HTTP 出口）。
	 */
	private $client;

	/**
	 * @var array<string, mixed> 最近一次 check() 的事件 meta（Suggestion/Label/Score/截断标注/错误类别）。
	 */
	private $last_meta = array();

	/**
	 * @param array<string, mixed> $settings 完整设置树（读 review.cloud.tencent_tms 子树）。
	 * @param RemoteClient         $client    传输脊实例。
	 */
	public function __construct( array $settings, RemoteClient $client ) {
		$tms = isset( $settings['review']['cloud']['tencent_tms'] ) && is_array( $settings['review']['cloud']['tencent_tms'] )
			? $settings['review']['cloud']['tencent_tms']
			: array();

		$reject_score = isset( $tms['reject_score'] ) && is_numeric( $tms['reject_score'] )
			? (int) $tms['reject_score']
			: self::DEFAULT_REJECT_SCORE;

		$this->config = array(
			// trim 收口（C1 真机踩坑）：凭据尾随 \r/空格（env 通道 CRLF 残留）会使 TC3 HMAC
			// 密钥/SecretId 含不可见字符 → AuthFailure.SignatureFailure。
			'secret_id'    => trim( is_string( $tms['secret_id'] ?? null ) ? $tms['secret_id'] : '' ),
			'secret_key'   => trim( is_string( $tms['secret_key'] ?? null ) ? $tms['secret_key'] : '' ),
			'biz_type'     => trim( is_string( $tms['biz_type'] ?? null ) ? $tms['biz_type'] : '' ),
			'timeout'      => max( 1, (int) ( $tms['timeout'] ?? 8 ) ),
			'reject_score' => max( 0, min( 100, $reject_score ) ),
		);
		$this->stats_settings = isset( $settings['stats'] ) && is_array( $settings['stats'] ) ? array( 'stats' => $settings['stats'] ) : array();
		$this->client = $client;
	}

	/**
	 * 驱动注册元数据（05 §4 七维声明；v1.4·R4：+privacy 第七维/guide 可选维/settings 扩展
	 * label+required——顶层 privacy_url/cross_border 散件并入 privacy 维删除）。
	 *
	 * @return array<string, mixed>
	 */
	public static function registry_entry(): array {
		return array(
			'id'          => self::DRIVER_ID,
			'type'        => 'review',
			'class'       => self::class,
			'label'       => '腾讯云 TMS 文本审核',
			'reachability' => 'cn-excellent',
			'billing'     => 'usage', // 套餐包按量（05 §5 矩阵「套餐包」归 usage 档）。
			'frontend'    => array(), // review 类无前端形态.
			'error_map'   => array( 'body_probe' => 'inspect_body' ), // 02 §2.5 body 错误码→四类.
			'api_version' => 'tms-2020-12-29', // 实测端点版本（L0 冒烟定论）.
			'settings'    => array(
				'secret_id'    => array( 'type' => 'password', 'sanitize' => 'password', 'label' => 'SecretId（CAM）', 'required' => true ),
				'secret_key'   => array( 'type' => 'password', 'sanitize' => 'password', 'label' => 'SecretKey（CAM）', 'required' => true ),
				'biz_type'     => array( 'type' => 'text', 'sanitize' => 'text', 'label' => 'BizType（策略编号）' ),
				'timeout'      => array( 'type' => 'number', 'sanitize' => 'absint', 'label' => '超时（秒）' ),
				'daily_quota'  => array( 'type' => 'number', 'sanitize' => 'absint', 'label' => '日配额' ),
				'fail_mode'    => array( 'type' => 'text', 'sanitize' => 'text', 'label' => '故障降级' ),
				'reject_score' => array( 'type' => 'number', 'sanitize' => 'absint', 'label' => '直拒分数阈值' ),
			),
			'privacy'     => DriverPrivacy::entry( self::DRIVER_ID ), // 第七维（内容表单一来源引用，禁双源漂移）.
			'guide'       => array( // 【v1.4·R4 §A.5】接入指引下放 registry（原 SettingsPage 五步指引迁入）。
				'steps' => array(
					'1. 腾讯云控制台开通「内容安全 · 文本审核」并购买/领取套餐包（TextModeration 按策略计费）。',
					'2. 内容安全控制台获取策略编号（BizType，如 TencentCloudDefault 或自定义策略）——建议填写绑定套餐包的策略。',
					'3. 准备 CAM 密钥对（访问管理 → API 密钥管理，与验证码的 CAM 密钥同体系，可复用同对）。',
					'4. 粘贴至下方 → 点「测试凭据」（正常文本实测应返回 Pass）。',
				),
				'doc_url' => 'https://cloud.tencent.com/document/api/1124/51860',
			),
			'doc_url'     => 'https://cloud.tencent.com/document/api/1124/51860',
			'milestone'   => 'M2',
		);
	}

	/**
	 * CAM 密钥对齐全才算已配置（BizType 官方可选——不传走默认策略，不作门槛；
	 * 真机建议填写绑定套餐包的策略编号）。
	 */
	public function is_configured(): bool {
		return '' !== $this->config['secret_id'] && '' !== $this->config['secret_key'];
	}

	/**
	 * 审核一次（02 §2.2 契约：$content = 闸拼装的送审串，驱动不自拼）。
	 *
	 * @param string               $content 送审串（「昵称 \n 邮箱 \n 正文」拼接或正文）。
	 * @param array<string, mixed> $meta    开放键集（author/email/ip_hash/…按需消费）。
	 * @return Verdict pass/reject/review；远端终态故障 = error（链上 failover，闸侧收口）。
	 */
	public function check( string $content, array $meta ): Verdict {
		$this->last_meta = array();

		if ( ! $this->is_configured() ) {
			// 未配置不应入链（Bootstrap 构造期过滤）；防御兜底 = error，闸按云端全故障收口。
			return Verdict::error( self::DRIVER_ID, 'not_configured' );
		}

		$truncated = self::truncate_content( $content );
		if ( $truncated['truncated'] ) {
			$this->last_meta['truncated']      = true;
			$this->last_meta['original_chars'] = $truncated['original_chars'];
		}

		$payload = self::build_payload( $truncated['content'], $this->config['biz_type'] );

		$signed = Tc3Signer::authorization(
			$this->config['secret_id'],
			$this->config['secret_key'],
			self::HOST,
			self::ACTION,
			self::VERSION,
			time(),
			$payload
		);
		$headers          = $signed['headers'];
		$headers['X-TC-Region'] = self::REGION; // 公共参数不参与签名，签名后并入。

		$result = $this->client->post( self::ENDPOINT, array(
			'headers'   => $headers,
			'body'      => $payload,
			'timeout'   => $this->config['timeout'],
			'usage_key' => self::DRIVER_ID, // 计量：审核调用计入 usage（04 §6；配额停转在闸编排判定）。
			'required'  => array( 'Response.Suggestion', 'Response.Label' ), // 缺失 → contract_mismatch 哨兵。
			'probe'     => array( self::class, 'inspect_body' ), // body 错误码归四类（error_map）。
		) );

		return $this->consume( $result );
	}

	/**
	 * 最近一次 check() 的事件 meta（Suggestion/Label/Score/reject_score/截断标注/错误类别）。
	 *
	 * @return array<string, mixed>
	 */
	public function last_check_meta(): array {
		return $this->last_meta;
	}

	/**
	 * 凭据探测（Tab③「测试凭据」）：良性样本文本真调用一次，返回传输脊结果——
	 * 三态判定（绿 Pass / 红 AuthFailure、UnauthorizedOperation / 黄 MissingParameter、
	 * InvalidParameter 系）在 Settings\CredentialProbe::classify_tms。
	 *
	 * 与 check() 的差异：不计 usage（现场诊断，非业务审核）、不消费 last_meta。
	 *
	 * @return RemoteResult
	 */
	public function probe_credentials(): RemoteResult {
		$payload = self::build_payload( self::PROBE_TEXT, $this->config['biz_type'] );

		$signed = Tc3Signer::authorization(
			$this->config['secret_id'],
			$this->config['secret_key'],
			self::HOST,
			self::ACTION,
			self::VERSION,
			time(),
			$payload
		);
		$headers                = $signed['headers'];
		$headers['X-TC-Region'] = self::REGION;

		return $this->client->post( self::ENDPOINT, array(
			'headers'   => $headers,
			'body'      => $payload,
			'timeout'   => $this->config['timeout'],
			'usage_key' => '', // 探测调用不计 usage（Tab③ 现场诊断）。
		) );
	}

	/* -------------------------------------------------------------------
	 * 纯逻辑（零 WP 依赖；单测直测）
	 * ------------------------------------------------------------------ */

	/**
	 * 送审长度截断（Content 以 Unicode 计量 ≤10,000 字符，官方 API 文档 51860）。
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
			'content'       => mb_substr( $content, 0, self::MAX_CONTENT_CHARS, 'UTF-8' ),
			'truncated'     => true,
			'original_chars' => $length,
		);
	}

	/**
	 * 请求体构造（smoke-tencent.php 实测形态：Content=Base64 + BizType 非空才带）。
	 *
	 * @param string $content  送审串（已截断）。
	 * @param string $biz_type 策略编号（空串省略——官方默认策略）。
	 * @return string JSON。
	 */
	public static function build_payload( string $content, string $biz_type ): string {
		$body = array( 'Content' => base64_encode( $content ) );
		if ( '' !== $biz_type ) {
			$body['BizType'] = $biz_type;
		}
		return (string) json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * body 错误探测（02 §2.5 error_map；registry error_map.body_probe 声明的回调）。
	 *
	 * TMS 业务错误码在 2xx body 中（Response.Error.Code）——归四类：
	 *   semantic —— AuthFailure 系（密钥/签名错）、UnauthorizedOperation（未开通套餐/
	 *               欠费）、MissingParameter / InvalidParameter 系（参数错）：不重试，
	 *               闸侧当日停转（02 §5 快速失败）；
	 *   transport —— RequestLimitExceeded 系 / LimitExceeded（限频 1000 次/秒）、
	 *               InternalError 系 / ServiceUnavailable：可重试（传输脊退避后终态）；
	 *   null —— 无 Error 结构或未知码：交由 required 契约哨兵 / parse 兜底。
	 *
	 * @param mixed  $decoded json_decode 后的响应体（可能 null）。
	 * @param string $body    原始响应体。
	 * @return string|null 四类之一或 null。
	 */
	public static function inspect_body( $decoded, string $body ): ?string {
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		$response = $decoded['Response'] ?? null;
		if ( ! is_array( $response ) ) {
			return null;
		}
		$code = $response['Error']['Code'] ?? null;
		if ( ! is_string( $code ) || '' === $code ) {
			return null;
		}

		if ( 0 === strpos( $code, 'AuthFailure' ) ) {
			return RemoteResult::SEMANTIC; // CAM 密钥错/签名错/密钥禁用.
		}
		if ( 0 === strpos( $code, 'UnauthorizedOperation' ) ) {
			return RemoteResult::SEMANTIC; // 未开通 TMS/无有效套餐包/账号欠费（402 语义）.
		}
		if ( 0 === strpos( $code, 'InvalidParameter' ) || 0 === strpos( $code, 'InvalidParameterValue' ) ) {
			return RemoteResult::SEMANTIC; // 参数错（含 ErrTextContentLen/ErrTextContentType）.
		}
		if ( 'MissingParameter' === $code ) {
			return RemoteResult::SEMANTIC;
		}
		if ( 0 === strpos( $code, 'RequestLimitExceeded' ) || 0 === strpos( $code, 'LimitExceeded' ) ) {
			return RemoteResult::TRANSPORT; // 限频（官方 1000 次/秒）——退避重试.
		}
		if ( 0 === strpos( $code, 'InternalError' ) || 'ServiceUnavailable' === $code ) {
			return RemoteResult::TRANSPORT; // 内部错误/暂不可用——退避重试.
		}

		return null; // 未知码：落 required（contract）/parse 兜底.
	}

	/**
	 * 传输脊结果消费 → Verdict + last_meta。
	 *
	 * @param RemoteResult $result 传输脊结果。
	 * @return Verdict
	 */
	private function consume( RemoteResult $result ): Verdict {
		if ( ! $result->is_ok() ) {
			// 四类终态（transport 重试穷尽 / semantic / contract / parse）→ error（链上 failover，
			// 闸按云端全故障收口；semantic 类别供闸侧当日停转）。
			// 【v1.4·R4 §D.1】错误可观测性：meta.raw 脱敏快照一行合并（单一来源 raw_snapshot——
			// stats.raw_capture 关=省略；不在闸/驱动手拼字段）；hits 补 rule_hit=remote_<category>。
			$this->last_meta = array(
				'category' => $result->category(),
				'status'   => $result->status(),
			);
			$raw = RemoteResult::raw_snapshot_from_settings( $result, $this->stats_settings );
			if ( null !== $raw ) {
				$this->last_meta['raw'] = $raw;
			}
			return Verdict::error( self::DRIVER_ID, 'remote_' . $result->category(), array( 'remote_' . $result->category() ) );
		}

		$response  = $result->data()['Response'];
		$suggestion = is_string( $response['Suggestion'] ?? null ) ? $response['Suggestion'] : '';
		$label      = is_string( $response['Label'] ?? null ) ? $response['Label'] : '';
		$score      = isset( $response['Score'] ) && is_numeric( $response['Score'] ) ? (int) $response['Score'] : null;

		$this->last_meta = array_merge( $this->last_meta, array(
			'Suggestion'   => $suggestion,
			'Label'        => $label,
			'Score'        => $score,
			'reject_score' => $this->config['reject_score'],
		) ); // merge：保留 check() 期写入的截断标注（truncated/original_chars）。

		$reason = null !== $score ? $label . ',' . $score : $label;
		$hits   = array( $label );

		switch ( $suggestion ) {
			case 'Pass':
				return Verdict::pass( self::DRIVER_ID, $reason, $hits );

			case 'Review':
				return Verdict::review( self::DRIVER_ID, $reason, $hits );

			case 'Block':
				if ( null !== $score && $score >= $this->config['reject_score'] ) {
					return Verdict::reject( self::DRIVER_ID, $reason, $hits );
				}
				// 低置信 Block / 缺分 → 降 review（人工兜底，02 §2.2「reject 高置信才直拒」）。
				$this->last_meta['below_reject_score'] = true;
				return Verdict::review( self::DRIVER_ID, $reason, $hits );

			default:
				// 枚举漂移（厂商改版形态）→ fail-safe 到 review + meta 标注（人工兜底）。
				$this->last_meta['unknown_suggestion'] = $suggestion;
				return Verdict::review( self::DRIVER_ID, 'unknown_suggestion:' . $suggestion, $hits );
		}
	}
}
