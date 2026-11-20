<?php
/**
 * 自定义 OpenAI 兼容端点 LLM 审核驱动（【M6·批E】2026-11-17 实装——05 §2.2 F32 预留件
 * 转正 + 02 §2.6 CustomOpenAIEndpointDriver 预留位落地）。
 *
 * 「厂商子类只剩配置」纪律的极简形态（E-1，5492 原则③/⑨）：站长全量配置——无
 * DEFAULT_BASE_URL/DEFAULT_MODEL 预置值（中转站/自建网关〔LiteLLM 类〕/任意 OpenAI
 * 兼容厂商皆可），四件套之外零协议逻辑新增（五件套/收口/计量全在
 * OpenAiCompatibleReviewDriver 基类）。
 *
 * 思考强度不透传（Q1 裁定）：不引入 reasoning_effort/thinking/enable_thinking 等
 * 非标参数键——「更强判定」用模型名表达（主模型手填+两段式 r2 手填 upgrade_model，
 * 留空=不升级——基类守卫天然短路；R1 裁定：upgrade_model 为 review.cloud 顶层共用
 * 键，残留值经 UI 手填框可见可清理，不隐式吞）。
 *
 * 测试凭据=GET {base_url}/models 三态探活（E-3：泛端点无标准余额 API，零 token 消耗；
 * 三态判定在 CredentialProbe::classify_openai_custom_models）。
 *
 * 计量/事件/配额全继承（E-5）：K1 usage_key=openai_custom 经基类构造器
 * static::DRIVER_ID 接线（M6·P1-2）；日配额闸读 review.cloud.openai_custom.daily_quota
 * 子树键（schema 既有 8 键——零新增键）。
 *
 * @package WinkGatekeeper\Drivers\Review
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Drivers\Review;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（S2-F1 纵深加固——类文件直访零执行，守卫为纵深面）。
}

use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Settings\DriverPrivacy;
use WinkGatekeeper\Settings\SettingsSchema;

final class OpenAiCustomReviewDriver extends OpenAiCompatibleReviewDriver {

	public const DRIVER_ID = 'openai_custom';
	// 无 DEFAULT_BASE_URL/DEFAULT_MODEL——站长全量配置（05 §2.2 openai_custom 列）。

	/**
	 * 从设置树派生配置（review.cloud.openai_custom 子树 8 键 + review.cloud 顶层
	 * two_stage/upgrade_model 透传——两段式是协议族基类行为，SettingsSchema :188 注释
	 * 明示 openai_custom 共用）。
	 *
	 * @param array<string, mixed> $settings merge 后整树。
	 * @return array<string, mixed>
	 */
	public static function config_from_settings( array $settings ): array {
		$sub = is_array( $settings['review']['cloud']['openai_custom'] ?? null ) ? $settings['review']['cloud']['openai_custom'] : array();
		return array(
			'api_key'        => (string) ( $sub['api_key'] ?? '' ),
			'base_url'       => (string) ( $sub['base_url'] ?? '' ),
			'model'          => (string) ( $sub['model'] ?? '' ),
			'timeout'        => (int) ( $sub['timeout'] ?? 8 ),
			'daily_quota'    => (int) ( $sub['daily_quota'] ?? 200 ),
			'fail_mode'      => (string) ( $sub['fail_mode'] ?? 'review' ),
			'system_prompt'  => (string) ( $sub['system_prompt'] ?? SettingsSchema::DEFAULT_SYSTEM_PROMPT ),
			'min_confidence' => (float) ( $sub['min_confidence'] ?? 0.8 ),
			'two_stage'      => ! empty( $settings['review']['cloud']['two_stage'] ),
			// R1 裁定：空串原样透传（基类 ''!==守卫短路两段式）；deepseek 残留值不吞不换。
			'upgrade_model'  => (string) ( $settings['review']['cloud']['upgrade_model'] ?? '' ),
		);
	}

	/**
	 * registry 六维登记（05 §4；Tab③ 下拉/点名册/统计卡/合规行走 registry 渲染）。
	 *
	 * @return array<string, mixed>
	 */
	public static function registry_entry(): array {
		return array(
			'id'           => self::DRIVER_ID,
			'type'         => 'review',
			'class'        => self::class,
			'label'        => __( 'Custom OpenAI-compatible endpoint', 'wink-wp-gatekeeper' ), // future 表既有字面迁移（单一来源，不新造名）。
			'reachability' => 'self-managed', // 自由字段：站长自负（DriverRegistry 不校验值域）.
			'billing'      => 'usage',        // 按量计费通用义（实际口径取决于端点）.
			'frontend'     => array(),        // review 类无前端形态.
			'error_map'    => array( 'http_status' => 'classify' ), // OpenAI 错误形态 4xx body，HTTP 级分类已够（deepseek 同）.
			'api_version'  => 'chat-completions',
			'settings'     => array(
				'api_key'        => array( 'type' => 'password', 'sanitize' => 'password', 'label' => 'API Key', 'required' => true ),
				'base_url'       => array( 'type' => 'url', 'sanitize' => 'url', 'label' => __( 'Endpoint URL', 'wink-wp-gatekeeper' ), 'required' => true ),
				'model'          => array( 'type' => 'text', 'sanitize' => 'text', 'label' => __( 'Verdict model', 'wink-wp-gatekeeper' ), 'required' => true ),
				'timeout'        => array( 'type' => 'number', 'sanitize' => 'absint', 'label' => __( 'Timeout (seconds)', 'wink-wp-gatekeeper' ) ),
				'daily_quota'    => array( 'type' => 'number', 'sanitize' => 'absint', 'label' => __( 'Daily quota', 'wink-wp-gatekeeper' ) ),
				'fail_mode'      => array( 'type' => 'text', 'sanitize' => 'text', 'label' => __( 'Failure fallback', 'wink-wp-gatekeeper' ) ),
				'system_prompt'  => array( 'type' => 'textarea', 'sanitize' => 'textarea', 'label' => __( 'System prompt', 'wink-wp-gatekeeper' ) ),
				'min_confidence' => array( 'type' => 'number', 'sanitize' => 'float', 'label' => __( 'Confidence threshold', 'wink-wp-gatekeeper' ) ),
			),
			'privacy'      => DriverPrivacy::entry( self::DRIVER_ID ), // 已备：cross_border=null→通用出境黄条.
			'guide'        => array(
				'steps' => array(
					__( '1. The endpoint must provide chat/completions (OpenAI-compatible) — relay sites, self-built gateways (LiteLLM and the like), or any compatible vendor all work.', 'wink-wp-gatekeeper' ),
					__( '2. Fill in the endpoint URL (mind the path prefix: the plugin appends /chat/completions to whatever you enter — for the official OpenAI family, fill in up to …/v1).', 'wink-wp-gatekeeper' ),
					__( '3. Fill in the API Key and model name → click “Test credentials” (model-list probe, zero token cost — generic endpoints have no standard balance API, so no balance is shown).', 'wink-wp-gatekeeper' ),
					__( '4. Reachability and compliance are on you: data goes to the jurisdiction of the endpoint you enter (intranet addresses are not supported — the transport spine rejects private networks first).', 'wink-wp-gatekeeper' ),
				),
			),
			'milestone'    => 'M6E',
		);
	}

	/**
	 * 已配置判定覆写（Q5 裁定采纳）：基类只查 api_key 非空——本驱动 base_url/model
	 * 也必填（future 表 required 三项口径），三键任一空=未配置（半配置更早短路、
	 * 日配额不空烧；UI 面由 registry settings required 声明承载黄条）。
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== (string) ( $this->config['api_key'] ?? '' )
			&& '' !== trim( (string) ( $this->config['base_url'] ?? '' ) )
			&& '' !== trim( (string) ( $this->config['model'] ?? '' ) );
	}

	/**
	 * 测试凭据探测·模型列表（E-3：GET {base_url}/models，Bearer，timeout 5；
	 * 不计 usage 不落库——DeepSeekReviewDriver::probe_models 同形态）。
	 *
	 * 三态判定在 CredentialProbe::classify_openai_custom_models（红=401/403/402 中性
	 * 文案；黄=模型不在列表〔黄不红〕；灰=契约不符/404 形态提示/transport）。
	 *
	 * @return \WinkGatekeeper\Http\RemoteResult
	 */
	public function probe_models(): \WinkGatekeeper\Http\RemoteResult {
		$base = rtrim( (string) ( $this->config['base_url'] ?? '' ), '/' );
		return $this->client->get( $base . '/models', array(
			'headers' => array(
				'Authorization' => 'Bearer ' . (string) ( $this->config['api_key'] ?? '' ),
				'Content-Type'  => 'application/json',
			),
			'timeout' => 5,
		) );
	}
}
