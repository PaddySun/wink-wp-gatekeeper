<?php
/**
 * 百度智能云文本审核驱动（05 §2.4 百度行——L0-baidu-2026-11-09 实测定稿；02 §2.2/§2.5 契约）。
 *
 * 服务端（L0 实测两段式）：
 *   ① token：POST https://aip.baidubce.com/oauth/2.0/token
 *      ?grant_type=client_credentials&client_id={API Key}&client_secret={Secret Key}
 *      → access_token + expires_in=2592000 秒（≈30 天）——**有效期 30 天必须缓存复用**
 *      （05 §2.4 备注列；token 走 transient，键含凭据指纹防换 AK 串用旧 token）。
 *   ② 审核：POST https://aip.baidubce.com/rest/2.0/solution/v1/text_censor/v2/user_defined
 *      ?access_token={token}（URL 参数）· body `text={urlencode}`（form；
 *      ≤20000 字节——超长字节安全截断 + meta 标注）。v2 即现行版（2020 前老接口已下线）。
 *   AppID 不参与任何请求参数（L0 实测——仅控制台标识，05 §2.4）。
 *   全部经传输脊 RemoteClient 发出（02 §2.5 唯一出口；无签名件——百度鉴权为
 *   client_credentials token 流，不需要 TC3/ACS3 类签名器）。
 *
 * 判定映射（05 §2.4 判定映射列，L0 逐项实测吻合；conclusionType 为唯一判据，
 * conclusion 原值仅作 reason 展示）：
 *   conclusionType 1（合规）→ pass
 *   conclusionType 2（不合规）→ reject（reason=conclusion+首条命中；hits=data[].type/subType
 *     人类可读格式「12-文本反作弊(subType=4)」——L0 广告文本命中项逐项，TYPE_NAMES 表内置）
 *   conclusionType 3（疑似）→ review
 *   conclusionType 4（审核失败）→ error（链上 failover + 闸按 fail_mode 收口——05 §2.4
 *     「4 失败→fail_mode」映射的 verdict 落点：error 不参与定级、云端全故障时按降级档）
 *   枚举漂移 → review fail-safe + meta 标注（TMS unknown_suggestion 先例）。
 *
 * 送审串口径（02 §2.2 v1.5 阿里例外条款的百度推广——IMPL-B6 真机实锤偏差 1）：
 * **邮箱不并入送审串**。设计原口径「拼接串含邮箱——百度无 pt_to_contact 误杀问题」真机
 * 反驳：百度对任何邮箱地址本身判 36-广告(subType=360104)（隔离实验 2026-11-11：
 * email_only→reject、body_only→pass、昵称+正文→pass；与阿里 pt_to_contact 误杀同类，
 * IMPL-B5 §3 偏差 3 先例）。邮箱防线收回到 LocalRules 拼接串（闸级 F36 拼接仍含邮箱——
 * U2 #6 教训不变）。驱动内消费：闸仍传「昵称\n邮箱\n正文」拼接串 + meta 原值（author/
 * email/content），送审串 =「昵称\n正文」两行（review.include_author_fields 关 = 仅正文，
 * 与阿里 F36 回退同口径）；meta.content 缺失时回退拼接串原样（单测/兼容调用——生产闸恒带
 * content，CommentGate::review_meta）。昵称保留在送审串（广告昵称应被云审捕获——B5 真机
 * 昵称通道命中实锤），TMS/LLM 驱动拼接串口径零改动。
 *
 * error_map（02 §2.5 body 错误映射；百度 2xx body 顶层 error_code/error_msg 形态）：
 *   inspect_body 纯函数归四类——
 *   semantic —— 110/111（access_token 无效/过期：清缓存自愈 + 闸侧当日停转）、
 *               100（无效参数）、6（无权限调用——未开通文本审核）、17/19（配额超限）；
 *   transport —— 2/3（服务暂不可用/内部错误）、4/5/18（QPS/流量限流）：传输脊退避重试后终态；
 *   null —— 无 error_code（成功体）/ 未知码：交由 required 契约哨兵 / parse 兜底。
 *   HTTP 层分类已在 RemoteResult::classify 完成。
 *
 * token 缓存（WP 边界注入）：token_get/token_set 闭包由 Bootstrap 注入 get_transient/
 * set_transient（空串 token = 清缓存，110/111 自愈通道）；单测注入内存闭包零 WP 依赖。
 * TTL = expires_in - 300s 安全边际（服务端时钟与本地时差防边界 401）。
 * token 获取不计审核 usage（04 §6 审核口径——usage 只对审核端点计量；token 缓存命中
 * 与否落 last_check_meta.token_cache=hit/miss 供真机侧证）。
 *
 * 分层：truncate/payload/token 键/inspect_body/map_conclusion 为纯逻辑（零 WP 依赖）；
 * probe_credentials 供 Tab③「测试凭据」AJAX 边界消费（CredentialProbe::classify_baidu）。
 *
 * @package WinkGatekeeper\Drivers\Review
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Drivers\Review;

use WinkGatekeeper\Drivers\ReviewDriverInterface;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Settings\DriverPrivacy;
use WinkGatekeeper\Verdict;

final class BaiduTextModerationDriver implements ReviewDriverInterface {

	public const DRIVER_ID      = 'baidu';
	public const TOKEN_ENDPOINT = 'https://aip.baidubce.com/oauth/2.0/token';
	public const ENDPOINT       = 'https://aip.baidubce.com/rest/2.0/solution/v1/text_censor/v2/user_defined';

	/**
	 * text 字段字节上限（官方 API 文档 Rk3h6xb3i：≤20000 字节；超长字节安全截断 + meta 标注）。
	 */
	public const MAX_CONTENT_BYTES = 20000;

	/**
	 * token 缓存 TTL 安全边际（秒）：expires_in - 300 写 transient（时钟偏差防边界）。
	 */
	public const TOKEN_TTL_MARGIN = 300;

	/** 凭据探测用良性样本文本（Tab③ 三态判定：conclusionType=1 → 绿；L0 用例②原文）。 */
	public const PROBE_TEXT = '你好，这篇文章写得真好';

	/**
	 * 百度官方审核主类型映射（在线文档「审核主类型」表——hits 人类可读化；L0 脚本 TYPE_NAMES 迁入）。
	 *
	 * @var array<int, string>
	 */
	private const TYPE_NAMES = array(
		11 => '百度官方违禁词库',
		12 => '文本反作弊',
		13 => '自定义黑名单',
		14 => '自定义白名单',
		30 => '涉政',
		31 => '暴恐',
		32 => '违禁',
		33 => '涉黄',
		34 => '辱骂',
		35 => '涉价值观',
		36 => '广告',
		37 => '广告法',
		38 => '隐私信息',
		39 => '低质灌水',
	);

	/**
	 * @var array{api_key: string, secret_key: string, timeout: int}
	 */
	private $config;

	/**
	 * @var array<string, mixed> stats 子树（raw 快照开关——error 分支一行合并消费）。
	 */
	private $stats_settings = array();

	/**
	 * @var bool review.include_author_fields（F36 拼接开关——关=仅正文，02 §2.2；
	 *      邮箱恒不并入送审串——IMPL-B6 真机实锤偏差 1）。
	 */
	private $include_author_fields = true;

	/**
	 * @var RemoteClient 传输脊（全库唯一 HTTP 出口）。
	 */
	private $client;

	/**
	 * @var callable fn(string $key): ?string token 缓存读（WP 边界注入 get_transient）。
	 */
	private $token_get;

	/**
	 * @var callable fn(string $key, string $token, int $ttl): void token 缓存写
	 *      （WP 边界注入 set_transient；$token='' = 清缓存——110/111 自愈通道）。
	 */
	private $token_set;

	/**
	 * @var array<string, mixed> 最近一次 check() 的事件 meta（token_cache/conclusionType/
	 *      hits/截断标注/错误类别）。
	 */
	private $last_meta = array();

	/**
	 * @param array<string, mixed> $settings  完整设置树（读 review.cloud.baidu 子树）。
	 * @param RemoteClient         $client    传输脊实例。
	 * @param callable|null        $token_get token 缓存读闭包；缺省 = get_transient（WP 边界）。
	 * @param callable|null        $token_set token 缓存写闭包；缺省 = set_transient / 清空删 transient。
	 */
	public function __construct( array $settings, RemoteClient $client, ?callable $token_get = null, ?callable $token_set = null ) {
		$baidu = isset( $settings['review']['cloud']['baidu'] ) && is_array( $settings['review']['cloud']['baidu'] )
			? $settings['review']['cloud']['baidu']
			: array();

		$this->config = array(
			// trim 收口（C1 真机踩坑）：凭据尾随 \r/空格（env 通道 CRLF 残留）会混入
			// token 请求参数（client_id/client_secret）→ 鉴权失败。
			'api_key'    => trim( is_string( $baidu['api_key'] ?? null ) ? $baidu['api_key'] : '' ),
			'secret_key' => trim( is_string( $baidu['secret_key'] ?? null ) ? $baidu['secret_key'] : '' ),
			'timeout'    => max( 1, (int) ( $baidu['timeout'] ?? 8 ) ),
		);
		$this->stats_settings = isset( $settings['stats'] ) && is_array( $settings['stats'] ) ? array( 'stats' => $settings['stats'] ) : array();
		$this->include_author_fields = ! isset( $settings['review']['include_author_fields'] )
			|| (bool) $settings['review']['include_author_fields'];
		$this->client    = $client;
		$this->token_get = $token_get ?: static function ( string $key ) {
			$cached = get_transient( $key ); // WP 边界。
			return is_string( $cached ) && '' !== $cached ? $cached : null;
		};
		$this->token_set = $token_set ?: static function ( string $key, string $token, int $ttl ): void {
			if ( '' === $token ) {
				delete_transient( $key ); // WP 边界（110/111 自愈清缓存）。
				return;
			}
			set_transient( $key, $token, $ttl ); // WP 边界。
		};
	}

	/**
	 * 驱动注册元数据（05 §4 七维声明；05 §2.4 registry 建议行——M4 计费防呆重点提前到
	 * M3' 轻量批实装，token 缓存复用即计费防呆第一道：30 天窗口内每条评论只发一审核请求）。
	 *
	 * @return array<string, mixed>
	 */
	public static function registry_entry(): array {
		return array(
			'id'           => self::DRIVER_ID,
			'type'         => 'review',
			'class'        => self::class,
			'label'        => '百度文本审核',
			'reachability' => 'cn-excellent', // 境内 aip.baidubce.com（05 §2.4 可达性行）.
			'billing'      => 'usage',        // 按量计费（05 §5 矩阵「按量」档）.
			'frontend'     => array(),        // review 类无前端形态.
			'error_map'    => array( 'body_probe' => 'inspect_body' ), // body error_code→四类（2xx 错误体形态）.
			'api_version'  => 'text_censor-v2', // L0 定论：v2 即现行版（2020 前老接口已下线，未见 v3）.
			'settings'     => array(
				'api_key'     => array( 'type' => 'password', 'sanitize' => 'password', 'label' => 'API Key', 'required' => true ),
				'secret_key'  => array( 'type' => 'password', 'sanitize' => 'password', 'label' => 'Secret Key', 'required' => true ),
				'timeout'     => array( 'type' => 'number', 'sanitize' => 'absint', 'label' => '超时（秒）' ),
				'daily_quota' => array( 'type' => 'number', 'sanitize' => 'absint', 'label' => '日配额' ),
				'fail_mode'   => array( 'type' => 'text', 'sanitize' => 'text', 'label' => '故障降级' ),
			),
			'privacy'      => DriverPrivacy::entry( self::DRIVER_ID ), // 第七维（内容表单一来源引用，禁双源漂移）.
			'guide'        => array( // 【v1.4·R4 §A.5】接入指引下放 registry（guide 维渲染）。
				'steps' => array(
					'1. 百度智能云控制台开通「内容审核 · 文本审核」（现行 v2 端点，文本审核按量计费）。',
					'2. 控制台「应用列表」创建应用获取 API Key 与 Secret Key（AppID 仅控制台标识，不参与请求）。',
					'3. 粘贴至下方 → 点「测试凭据」（正常文本实测应返回合规）。',
					'4. 判定映射（站长不可调）：conclusionType 1 合规→放行、2 不合规→直接拦截、3 疑似→入待审；access_token 由插件缓存复用（30 天有效期，无需手动管理）。',
				),
				'doc_url' => 'https://cloud.baidu.com/doc/ANTIPORN/s/Rk3h6xb3i',
			),
			'doc_url'      => 'https://cloud.baidu.com/doc/ANTIPORN/s/Rk3h6xb3i',
			'milestone'    => 'M3',
		);
	}

	/**
	 * API Key + Secret Key 齐全才算已配置（AppID 不参与请求不作门槛——05 §2.4）。
	 */
	public function is_configured(): bool {
		return '' !== $this->config['api_key'] && '' !== $this->config['secret_key'];
	}

	/**
	 * 审核一次（02 §2.2 契约：$content = 闸拼装的送审串，驱动不自拼；送审串消费是驱动内
	 * 策略——**邮箱不并入**（IMPL-B6 真机实锤偏差 1，见类头注））。
	 *
	 * @param string               $content 送审串（「昵称 \n 邮箱 \n 正文」拼接或正文）。
	 * @param array<string, mixed> $meta    开放键集（author/email/content/ip_hash/…按需消费）。
	 * @return Verdict pass/reject/review；远端终态故障 = error（链上 failover，闸侧收口）。
	 */
	public function check( string $content, array $meta ): Verdict {
		$this->last_meta = array();

		if ( ! $this->is_configured() ) {
			// 未配置不应入链（Bootstrap 构造期过滤）；防御兜底 = error，闸按云端全故障收口。
			return Verdict::error( self::DRIVER_ID, 'not_configured' );
		}

		/* 送审串构造（邮箱恒不并入——真机实锤：任何邮箱地址本身判 36-广告 360104 误杀）：
		 * meta.content（正文原值）在场 = 生产闸路径 →「昵称\n正文」两行（include_author_fields
		 * 关 = 仅正文）；缺失 = 兼容回退 → 闸拼装串原样（单测/无 meta 调用——生产闸恒带 content）。 */
		$body = isset( $meta['content'] ) && is_string( $meta['content'] ) ? $meta['content'] : '';
		if ( '' !== $body ) {
			$author = isset( $meta['author'] ) && is_string( $meta['author'] ) ? $meta['author'] : '';
			$send = ( $this->include_author_fields && '' !== trim( $author ) ) ? $author . "\n" . $body : $body;
			$this->last_meta['send_fields'] = ( $this->include_author_fields && '' !== trim( $author ) ) ? 'author+body' : 'body_only';
		} else {
			$send = $content; // 兼容回退（拼接串原样——TMS/LLM 同口径）。
		}

		if ( '' === trim( $send ) ) {
			// 全空提交本地防呆：零外发，放行 + meta 标注（阿里 skipped 先例同款）。
			$this->last_meta['skipped'] = 'empty_content';
			return Verdict::pass( self::DRIVER_ID, '', array() );
		}

		$token = $this->obtain_token();
		if ( null !== $token['error'] ) {
			return $this->remote_error( $token['error'] );
		}

		$truncated = self::truncate_content( $send );
		if ( $truncated['truncated'] ) {
			$this->last_meta['truncated']     = true;
			$this->last_meta['original_bytes'] = $truncated['original_bytes'];
		}

		$result = $this->client->post( self::ENDPOINT . '?access_token=' . rawurlencode( $token['token'] ), array(
			'headers'   => array( 'content-type' => 'application/x-www-form-urlencoded' ),
			'body'      => self::build_censor_payload( $truncated['content'] ),
			'timeout'   => $this->config['timeout'],
			'usage_key' => self::DRIVER_ID, // 计量：审核调用计入 usage（04 §6；token 获取不计）。
			'required'  => array( 'conclusion', 'conclusionType' ), // 缺失 → contract_mismatch 哨兵。
			'probe'     => array( self::class, 'inspect_body' ),   // body error_code 归四类（error_map）。
		) );

		if ( ! $result->is_ok() ) {
			// 110/111（token 无效/过期）→ 清缓存自愈：下次调用重取 token（本次仍 error 链上 failover）。
			$decoded = $result->data();
			$code    = is_array( $decoded ) ? ( $decoded['error_code'] ?? null ) : null;
			if ( 110 === (int) $code || 111 === (int) $code ) {
				call_user_func( $this->token_set, self::token_cache_key( $this->config['api_key'] ), '', 0 );
				$this->last_meta['token_invalid_cleared'] = true;
			}
			return $this->remote_error( $result );
		}

		$mapped = self::map_conclusion( is_array( $result->data() ) ? $result->data() : array() );
		$this->last_meta = array_merge( $this->last_meta, array(
			'conclusionType' => $mapped['conclusion_type'],
			'conclusion'     => $mapped['conclusion'],
			'log_id'         => $mapped['log_id'],
			'hits'           => $mapped['hits'],
		) ); // merge：保留 token_cache/截断标注。

		switch ( $mapped['verdict'] ) {
			case Verdict::PASS:
				return Verdict::pass( self::DRIVER_ID, '', array() );
			case Verdict::REJECT:
				return Verdict::reject( self::DRIVER_ID, $mapped['reason'], $mapped['hits'] );
			case Verdict::ERROR:
				// conclusionType=4（审核失败）→ error（链上 failover；云端全故障时闸按 fail_mode 收口）。
				return Verdict::error( self::DRIVER_ID, 'remote_conclusion_failed', array( 'remote_conclusion_failed' ) );
			default:
				return Verdict::review( self::DRIVER_ID, $mapped['reason'], $mapped['hits'] );
		}
	}

	/**
	 * 最近一次 check() 的事件 meta（token_cache 命中态/conclusionType/conclusion/log_id/
	 * hits/截断标注/错误类别/raw 脱敏快照）。
	 *
	 * @return array<string, mixed>
	 */
	public function last_check_meta(): array {
		return $this->last_meta;
	}

	/**
	 * 凭据探测（Tab③「测试凭据」）：良性样本文本真调用一次（token 流含在内——探测即全链路
	 * 体检），返回审核端点传输脊结果——三态判定（绿 conclusionType=1 / 红 error_code
	 * 110/111/6 / 黄键缺 + error_code 100）在 Settings\CredentialProbe::classify_baidu。
	 *
	 * 与 check() 的差异：不计 usage（现场诊断，非业务审核）、不带 required 契约哨兵与
	 * probe（错误形态原样透出给三态判定层）、不消费 last_meta。
	 *
	 * @return RemoteResult
	 */
	public function probe_credentials(): RemoteResult {
		$token = $this->obtain_token();
		if ( null !== $token['error'] ) {
			return $token['error']; // token 流失败原样透出（semantic/transport 类别 = 三态判定输入）。
		}

		$truncated = self::truncate_content( self::PROBE_TEXT );
		return $this->client->post( self::ENDPOINT . '?access_token=' . rawurlencode( $token['token'] ), array(
			'headers'   => array( 'content-type' => 'application/x-www-form-urlencoded' ),
			'body'      => self::build_censor_payload( $truncated['content'] ),
			'timeout'   => $this->config['timeout'],
			'usage_key' => '', // 探测调用不计 usage（Tab③ 现场诊断）。
		) );
	}

	/* -------------------------------------------------------------------
	 * 纯逻辑（零 WP 依赖；单测直测）
	 * ------------------------------------------------------------------ */

	/**
	 * 送审长度截断（text ≤20000 字节，官方 API 文档 Rk3h6xb3i；**字节计量**非字符——
	 * 百度按 UTF-8 字节限长，超长按字节安全截断不产生坏 UTF-8 尾巴）。
	 *
	 * @param string $content 送审串。
	 * @return array{content: string, truncated: bool, original_bytes: int}
	 */
	public static function truncate_content( string $content ): array {
		$bytes = strlen( $content );
		if ( $bytes <= self::MAX_CONTENT_BYTES ) {
			return array( 'content' => $content, 'truncated' => false, 'original_bytes' => $bytes );
		}
		// 字节安全截断：先硬截再退尾（剥掉可能被腰斩的 UTF-8 多字节尾巴）。
		$cut = substr( $content, 0, self::MAX_CONTENT_BYTES );
		while ( '' !== $cut && ! mb_check_encoding( $cut, 'UTF-8' ) ) {
			$cut = substr( $cut, 0, -1 );
		}
		return array( 'content' => $cut, 'truncated' => true, 'original_bytes' => $bytes );
	}

	/**
	 * 请求体构造（L0 实调形态：form body text={urlencode}）。
	 *
	 * @param string $content 已截断的送审文本。
	 * @return string form body。
	 */
	public static function build_censor_payload( string $content ): string {
		return 'text=' . rawurlencode( $content );
	}

	/**
	 * token transient 缓存键（含凭据指纹——换 AK 旧 token 不串用；无 PII/密钥明文）。
	 *
	 * @param string $api_key 百度 API Key。
	 * @return string
	 */
	public static function token_cache_key( string $api_key ): string {
		return 'wk_baidu_token_' . substr( hash( 'sha256', $api_key ), 0, 12 );
	}

	/**
	 * body 错误探测（02 §2.5 error_map；registry error_map.body_probe 声明的回调）。
	 *
	 * 百度业务错误码在 2xx body 顶层（error_code/error_msg 形态——L0 实测与官方错误码表）。
	 * 归四类：
	 *   semantic —— 110/111（access_token 无效/过期——密钥被吊销/重置）、100（无效参数）、
	 *               6（无权限调用——未开通文本审核服务）、17/19（日/总配额超限）：
	 *               不重试，闸侧当日停转（02 §5 快速失败）；
	 *   transport —— 2（服务暂不可用）/ 3（内部错误）/ 4（请求量超限）/ 5（QPS 限制）/
	 *               18（QPS 超限额）：可重试（传输脊退避后终态）；
	 *   null —— 无 error_code（成功体）/ 未知码：交由 required 契约哨兵 / parse 兜底。
	 *
	 * @param mixed  $decoded json_decode 后的响应体（可能 null）。
	 * @param string $body    原始响应体。
	 * @return string|null 四类之一或 null。
	 */
	public static function inspect_body( $decoded, string $body ): ?string {
		unset( $body );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		$code = $decoded['error_code'] ?? null;
		if ( is_int( $code ) || ( is_string( $code ) && preg_match( '/^\d+$/', $code ) ) ) {
			$code = (int) $code;
		}
		if ( ! is_int( $code ) || 0 === $code ) {
			return null; // 无 error_code（成功体）/ 未知形态.
		}

		if ( in_array( $code, array( 110, 111, 100, 6, 17, 19 ), true ) ) {
			return RemoteResult::SEMANTIC; // token 鉴权/参数/权限/配额——不重试.
		}
		if ( in_array( $code, array( 2, 3, 4, 5, 18 ), true ) ) {
			return RemoteResult::TRANSPORT; // 服务不可用/内部错误/QPS 限流——退避重试.
		}

		return null; // 未知码：落 required（contract）/parse 兜底（契约哨兵）.
	}

	/**
	 * 判定映射（05 §2.4 判定映射列·规范性；纯函数）。
	 *
	 * conclusionType 为唯一判据（int）；conclusion 原值（合规/不合规/疑似/审核失败）仅作
	 * reason 展示与 meta。hits = data[].type/subType 人类可读格式（TYPE_NAMES 表内置——
	 * L0 广告文本「12-文本反作弊(subType=4)」逐项吻合）。
	 *
	 * @param array<string, mixed> $decoded json_decode 后的响应体。
	 * @return array{verdict: string, reason: string, hits: string[], conclusion_type: int,
	 *               conclusion: string, log_id: string}
	 */
	public static function map_conclusion( array $decoded ): array {
		$type       = isset( $decoded['conclusionType'] ) && is_numeric( $decoded['conclusionType'] )
			? (int) $decoded['conclusionType']
			: 0;
		$conclusion = is_string( $decoded['conclusion'] ?? null ) ? $decoded['conclusion'] : '';
		$log_id     = isset( $decoded['log_id'] ) && is_scalar( $decoded['log_id'] ) ? (string) $decoded['log_id'] : '';

		$hits   = array();
		$labels = array();
		$data   = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array();
		foreach ( $data as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['type'] ) || ! is_numeric( $entry['type'] ) ) {
				continue;
			}
			$hit      = self::format_hit( (int) $entry['type'], isset( $entry['subType'] ) && is_numeric( $entry['subType'] ) ? (int) $entry['subType'] : -1 );
			$labels[] = $hit;
			$hits[]   = $hit;
		}

		$out = array(
			'verdict'         => Verdict::REVIEW,
			'reason'          => 'unknown_conclusion_type:' . $type,
			'hits'            => $hits,
			'conclusion_type' => $type,
			'conclusion'      => $conclusion,
			'log_id'          => $log_id,
		);

		switch ( $type ) {
			case 1:
				$out['verdict'] = Verdict::PASS;
				$out['reason']  = '';
				$out['hits']    = array();
				return $out;
			case 2:
				$out['verdict'] = Verdict::REJECT;
				break;
			case 3:
				$out['verdict'] = Verdict::REVIEW;
				break;
			case 4:
				$out['verdict'] = Verdict::ERROR;
				return $out;
			default:
				return $out; // 枚举漂移（含 conclusionType 缺失）→ review fail-safe + reason 原值.
		}

		// reject/review 共用 reason 组装：conclusion + 首条命中（如「不合规:12-文本反作弊(subType=4)」）。
		$out['reason'] = '' !== $conclusion
			? $conclusion . ( array() !== $hits ? ':' . $hits[0] : '' )
			: ( array() !== $hits ? $hits[0] : 'unknown_conclusion_type:' . $type );
		return $out;
	}

	/**
	 * 单条命中格式化（L0 脚本 labelSummary 同款：`12-文本反作弊(subType=4)`；未知 type 落
	 * `typeN` 兜底，不丢命中信息）。
	 *
	 * @param int $type     审核主类型（如 12 文本反作弊）。
	 * @param int $sub_type 子类型（如 4 恶意推广；缺失 -1）。
	 * @return string
	 */
	public static function format_hit( int $type, int $sub_type ): string {
		$name = self::TYPE_NAMES[ $type ] ?? ( 'type' . $type );
		return $type . '-' . $name . '(subType=' . $sub_type . ')';
	}

	/* -------------------------------------------------------------------
	 * 内部
	 * ------------------------------------------------------------------ */

	/**
	 * token 获取（30 天 transient 缓存复用；过期/缺失重取）。
	 *
	 * @return array{token: string, error: RemoteResult|null} error 非 null = token 流终态
	 *         （semantic/transport 类别已由传输脊分类——调用方按 remote_error 收口）。
	 */
	private function obtain_token(): array {
		$key    = self::token_cache_key( $this->config['api_key'] );
		$cached = call_user_func( $this->token_get, $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			$this->last_meta['token_cache'] = 'hit';
			return array( 'token' => $cached, 'error' => null );
		}
		$this->last_meta['token_cache'] = 'miss';

		// L0 实调形态：POST + query 参数（grant_type/client_id/client_secret），body 空。
		$url = self::TOKEN_ENDPOINT . '?' . http_build_query( array(
			'grant_type'    => 'client_credentials',
			'client_id'     => $this->config['api_key'],
			'client_secret' => $this->config['secret_key'],
		) );
		$result = $this->client->post( $url, array(
			'headers'   => array( 'content-type' => 'application/x-www-form-urlencoded' ),
			'body'      => '',
			'timeout'   => $this->config['timeout'],
			'usage_key' => '', // token 获取不计审核 usage（04 §6 审核口径）。
			'required'  => array( 'access_token', 'expires_in' ), // 缺失 → contract 哨兵。
		) );

		if ( ! $result->is_ok() ) {
			return array( 'token' => '', 'error' => $result );
		}

		$data      = is_array( $result->data() ) ? $result->data() : array();
		$token     = is_string( $data['access_token'] ?? null ) ? $data['access_token'] : '';
		$expires_in = isset( $data['expires_in'] ) && is_numeric( $data['expires_in'] ) ? (int) $data['expires_in'] : 0;

		$ttl = max( 60, $expires_in - self::TOKEN_TTL_MARGIN );
		call_user_func( $this->token_set, $key, $token, $ttl ); // 空串 token 由 set 闭包清缓存。
		if ( '' === $token ) {
			// 防御：2xx + required 已过但 token 空串（理论不可达）——不落缓存，返回契约类错误。
			return array( 'token' => '', 'error' => RemoteResult::error( RemoteResult::CONTRACT, 'contract: access_token empty' ) );
		}

		return array( 'token' => $token, 'error' => null );
	}

	/**
	 * 远端终态 → error verdict + last_meta（raw 脱敏快照一行合并——TMS consume 同构）。
	 *
	 * @param RemoteResult $result 传输脊终态结果。
	 * @return Verdict
	 */
	private function remote_error( RemoteResult $result ): Verdict {
		$this->last_meta = array_merge( $this->last_meta, array(
			'category' => $result->category(),
			'status'   => $result->status(),
		) );
		$raw = RemoteResult::raw_snapshot_from_settings( $result, $this->stats_settings );
		if ( null !== $raw ) {
			$this->last_meta['raw'] = $raw;
		}
		return Verdict::error( self::DRIVER_ID, 'remote_' . $result->category(), array( 'remote_' . $result->category() ) );
	}
}
