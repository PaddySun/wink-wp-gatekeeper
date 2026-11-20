<?php
/**
 * DeepSeek LLM 审核驱动（M5 U4 首发——05 §2.2 协议族「差异仅配置」落地，2026-11-14）。
 *
 * 预置配置：base_url=https://api.deepseek.com、model=deepseek-flash（04 §2 v1.2 L0 定论：
 * deepseek-chat 已是旧别名，服务端映射 flash）、system_prompt=SettingsSchema::DEFAULT_SYSTEM_PROMPT
 * 单一来源、two_stage=true（M5 定稿 §4 两段式默认开）、upgrade_model=deepseek-reasoner。
 *
 * 端点：POST {base_url}/chat/completions（Authorization: Bearer）；测试凭据=GET /user/balance
 * 余额探测（【M6·批B·N4】零费用端点——绿=200 且 is_available=true 附余额数字；
 * probe_models GET /models 保留给 openai_custom 泛兼容端点）。
 *
 * 05 §2.2 规格行为全部在 OpenAiCompatibleReviewDriver 基类（五件套/收口/计量）——
 * 本类只做配置派生+registry 登记（「厂商子类只剩配置」分层纪律，D13 博客精神）。
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

final class DeepSeekReviewDriver extends OpenAiCompatibleReviewDriver {

	public const DRIVER_ID = 'deepseek';
	public const DEFAULT_BASE_URL = 'https://api.deepseek.com';
	public const DEFAULT_MODEL    = 'deepseek-flash'; // L0 定论：chat 为旧别名，服务端映射 flash.
	public const UPGRADE_MODEL    = 'deepseek-reasoner';

	/**
	 * 从设置树派生配置（review.cloud.deepseek 子树 + review.cloud 顶层 two_stage/upgrade_model）。
	 *
	 * @param array<string, mixed> $settings merge 后整树。
	 * @return array<string, mixed>
	 */
	public static function config_from_settings( array $settings ): array {
		$sub  = is_array( $settings['review']['cloud']['deepseek'] ?? null ) ? $settings['review']['cloud']['deepseek'] : array();
		return array(
			'api_key'        => (string) ( $sub['api_key'] ?? '' ),
			'base_url'       => (string) ( $sub['base_url'] ?? self::DEFAULT_BASE_URL ),
			'model'          => (string) ( $sub['model'] ?? self::DEFAULT_MODEL ),
			'timeout'        => (int) ( $sub['timeout'] ?? 8 ),
			'daily_quota'    => (int) ( $sub['daily_quota'] ?? 200 ),
			'fail_mode'      => (string) ( $sub['fail_mode'] ?? 'review' ),
			'system_prompt'  => (string) ( $sub['system_prompt'] ?? SettingsSchema::DEFAULT_SYSTEM_PROMPT ),
			'min_confidence' => (float) ( $sub['min_confidence'] ?? 0.8 ),
			'two_stage'      => ! empty( $settings['review']['cloud']['two_stage'] ),
			'upgrade_model'  => (string) ( $settings['review']['cloud']['upgrade_model'] ?? self::UPGRADE_MODEL ),
		);
	}

	/**
	 * registry 六维登记（05 §4；tab③ 下拉/点名册/统计卡/合规行走 registry 渲染）。
	 *
	 * @return array<string, mixed>
	 */
	public static function registry_entry(): array {
		return array(
			'id'           => self::DRIVER_ID,
			'type'         => 'review',
			'class'        => self::class,
			'label'        => 'DeepSeek',
			'reachability' => 'cn-excellent', // 境内 api.deepseek.com（05 §2.2 可达性）.
			'billing'      => 'usage',        // 按量计费（充值余额，05 §5 矩阵）.
			'frontend'     => array(),        // review 类无前端形态.
			'error_map'    => array( 'http_status' => 'classify' ), // 四类错误分类学（HTTP 级——OpenAI 错误形态为 4xx body）.
			'api_version'  => 'chat-completions',
			'settings'     => array(
				'api_key'     => array( 'type' => 'password', 'sanitize' => 'password', 'label' => 'API Key', 'required' => true ),
				'base_url'    => array( 'type' => 'text', 'sanitize' => 'url', 'label' => __( 'Endpoint URL', 'wink-wp-gatekeeper' ) ),
				'model'       => array( 'type' => 'text', 'sanitize' => 'text', 'label' => __( 'Verdict model', 'wink-wp-gatekeeper' ) ),
				'timeout'     => array( 'type' => 'number', 'sanitize' => 'absint', 'label' => __( 'Timeout (seconds)', 'wink-wp-gatekeeper' ) ),
				'daily_quota' => array( 'type' => 'number', 'sanitize' => 'absint', 'label' => __( 'Daily quota', 'wink-wp-gatekeeper' ) ),
				'fail_mode'   => array( 'type' => 'text', 'sanitize' => 'text', 'label' => __( 'Failure fallback', 'wink-wp-gatekeeper' ) ),
				'min_confidence' => array( 'type' => 'number', 'sanitize' => 'float', 'label' => __( 'Confidence threshold', 'wink-wp-gatekeeper' ) ),
			),
			'privacy'      => DriverPrivacy::entry( self::DRIVER_ID ), // 第七维（deepseek 内容表已备——M5 U5 批补 data_fields 网址核查面）.
			'guide'        => array(
				'steps' => array(
					__( '1. Create an API Key on the DeepSeek open platform (platform.deepseek.com) and top up (pay-as-you-go; one verdict costs roughly a few hundred tokens).', 'wink-wp-gatekeeper' ),
					__( '2. Paste the API Key below → click “Test credentials” (balance probe, zero cost — it also verifies the key works and the account is active, showing the balance).', 'wink-wp-gatekeeper' ),
					__( '3. “Low-confidence auto-upgrade” is on by default: comments the AI is unsure about are re-judged once with the “reasoner” model, at most two judgments per comment.', 'wink-wp-gatekeeper' ),
					__( '4. Verdict mapping (not admin-adjustable): confidence ≥0.8 is taken as high confidence, <0.8 always goes to pending review; an AI pass never approves a comment by itself (it only tightens).', 'wink-wp-gatekeeper' ),
				),
				'doc_url' => 'https://api-docs.deepseek.com/',
			),
			'doc_url'      => 'https://api-docs.deepseek.com/',
			'milestone'    => 'M5',
		);
	}

	/**
	 * 测试凭据探测·模型列表（GET /models 探活，D5 裁决：零 token 成本；不计 usage）。
	 *
	 * 【M6·批B·N4】Tab③ 主凭证测试已切换到 probe_balance（信息量升级+零费用）——
	 * 本方法**保留**：openai_custom 等泛 OpenAI 兼容端点无标准余额 API，届时仍以
	 * /models 探活（UI 注明差异）。
	 *
	 * @return \WinkGatekeeper\Http\RemoteResult
	 */
	public function probe_models(): \WinkGatekeeper\Http\RemoteResult {
		$base = rtrim( (string) ( $this->config['base_url'] ?? self::DEFAULT_BASE_URL ), '/' );
		return $this->client->get( $base . '/models', array(
			'headers' => array(
				'Authorization' => 'Bearer ' . (string) ( $this->config['api_key'] ?? '' ),
				'Content-Type'  => 'application/json',
			),
			'timeout' => 5,
		) );
	}

	/**
	 * 测试凭据探测·余额（【M6·批B·N4】GET /user/balance——Bearer，零费用端点；
	 * 不计 usage 不落库）。
	 *
	 * 官方契约（api/查询余额.md，LetMESee 采集 2026-11-14）：200 → {is_available,
	 * balance_infos[{currency(CNY|USD), total_balance, granted_balance,
	 * topped_up_balance}]}。三态判定在 CredentialProbe::classify_deepseek_balance。
	 *
	 * @return \WinkGatekeeper\Http\RemoteResult
	 */
	public function probe_balance(): \WinkGatekeeper\Http\RemoteResult {
		$base = rtrim( (string) ( $this->config['base_url'] ?? self::DEFAULT_BASE_URL ), '/' );
		return $this->client->get( $base . '/user/balance', array(
			'headers' => array(
				'Authorization' => 'Bearer ' . (string) ( $this->config['api_key'] ?? '' ),
				'Content-Type'  => 'application/json',
			),
			'timeout' => 5,
		) );
	}
}
