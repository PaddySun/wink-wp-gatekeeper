<?php
/**
 * URL 判定执行器（chat/completions 调用+UrlJudge prompt 组装+K2 双车道记账——
 * U5 批；传输脊 RemoteClient 唯一出口；宽容解析器复用 U4 基类静态件）。
 *
 * @package WinkGatekeeper\UrlAudit
 */

declare( strict_types=1 );

namespace WinkGatekeeper\UrlAudit;

use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Stats\LlmUsage;
use WinkGatekeeper\Stats\Usage;

/**
 * URL 判定执行器（chat/completions 调用+UrlJudge prompt 组装+K2 双车道记账——
 * 独立小类复用 RemoteClient 传输脊与宽容解析器）。
 */
final class UrlJudgeEngine {

	private $config;
	private $client;
	private $model;

	/**
	 * @var UrlSearchProvider|null 搜索源（【M6·批B·B-2】接口化——bocha(默认)|deepseek
	 *                            按 url_audit.search_source 在 judge 工厂分派装配）。
	 */
	private $search;

	private $last_meta = array();

	public function __construct( array $config, RemoteClient $client, string $model, ?UrlSearchProvider $search = null ) {
		$this->config = $config;
		$this->client = $client;
		$this->model  = $model;
		$this->search = $search;
	}

	/**
	 * 页面正文模式判定（fetch ok 路径——nw §2.2 全量证据）。
	 *
	 * @param array<string, mixed> $fetched   fetcher 结果。
	 * @param string               $url       规范化 URL。
	 * @param string               $comment_ctx 评论上下文（昵称+正文）。
	 * @param string               $caller    url_audit_batch|url_audit_incr（K2）。
	 * @return array{verdict:string, confidence:float, reason:string, hits:array<string>, injection_attempt: bool}
	 */
	public function judge( array $fetched, string $url, string $comment_ctx, string $caller ): array {
		$this->last_meta = array(); // 单次判定语境（B-5 llm_error 标记不跨判定残留）。

		// 搜索源交叉信号（fetch 侧不决定——并列喂 LLM；调用失败零信号不阻塞不劣化：
		// 「搜索失败≠违规」§0-7 同族语义；【M6·批B·B-2】接口化任意 UrlSearchProvider）。
		$bocha_signal = array();
		if ( null !== $this->search && $this->search->is_configured() ) {
			$signal = $this->search->search_site( (string) ( parse_url( $url, PHP_URL_HOST ) ?? '' ) );
			if ( null !== $signal ) {
				$bocha_signal = $signal;
			}
		}

		$messages = UrlJudge::build_messages( $url, $fetched, $comment_ctx, $bocha_signal );
		return $this->send_and_parse( $messages, UrlJudge::SYSTEM_PROMPT, $caller );
	}

	/**
	 * 【M6·批F-a·§4-add2】搜索证据模式判定（fetch 失败族兜底路径——无页面正文，证据=
	 * 第三方检索线索）。施工图纪律：仅基于搜索证据；仅当搜索结果明确将域名本身与违规
	 * 类目直接关联才可 reject，否则 review/inconclusive——引擎侧机械收口 pass→review
	 * （搜索线索不足以证明站点正常；prompt 同款纪律的兜底执行，「只放大严」）。输出
	 * 契约与页面正文模式同形（九键闭集/解析器/min_confidence/注入检测/计量全共用）。
	 *
	 * @param array<string, mixed> $search_signal 搜索信号 {snippets: string[], siteName: string}（调用方已保证可用非空）。
	 * @param string               $url           规范化 URL。
	 * @param string               $comment_ctx   评论上下文（昵称+正文）。
	 * @param string               $caller        url_audit_batch|url_audit_incr（K2）。
	 * @return array{verdict:string, confidence:float, reason:string, hits:array<string>, injection_attempt: bool}
	 */
	public function judge_on_search_evidence( array $search_signal, string $url, string $comment_ctx, string $caller ): array {
		$this->last_meta = array(); // 单次判定语境（B-5 llm_error 标记不跨判定残留）。

		$messages  = UrlJudge::build_search_evidence_messages( $url, $search_signal, $comment_ctx );
		$judgement = $this->send_and_parse( $messages, UrlJudge::SEARCH_SYSTEM_PROMPT, $caller );

		// 搜索线索不定罪也不洗白（§4-add2「否则 review/inconclusive」机械收口）：
		// pass 在搜索证据模式降级 review（错杀面=进待审可恢复——批 E 已接受；漏杀面零增）。
		if ( 'pass' === $judgement['verdict'] ) {
			$judgement['verdict'] = 'review';
		}
		return $judgement;
	}

	/**
	 * 判定共用尾段（payload 组装→chat/completions→宽容解析→K2 记账→min_confidence
	 * 收口→注入检测——页面正文/搜索证据两模式全共用，行为与批 B 前判定路径逐行同形）。
	 *
	 * @param array<int, array{role: string, content: string}> $messages user 消息（不含 system）。
	 * @param string                                           $system_prompt 系统提示词（模式分族唯一位）。
	 * @param string                                           $caller url_audit_batch|url_audit_incr。
	 * @return array{verdict:string, confidence:float, reason:string, hits:array<string>, injection_attempt: bool}
	 */
	private function send_and_parse( array $messages, string $system_prompt, string $caller ): array {
		$payload  = array(
			'model'           => $this->model,
			'temperature'     => 0,
			'response_format' => array( 'type' => 'json_object' ),
			'messages'        => array_merge(
				array( array( 'role' => 'system', 'content' => $system_prompt ) ),
				$messages
			),
		);

		$base   = rtrim( (string) ( $this->config['base_url'] ?? '' ), '/' );
		$result = $this->client->post( $base . '/chat/completions', array(
			'headers'   => array(
				'Authorization' => 'Bearer ' . (string) ( $this->config['api_key'] ?? '' ),
				'Content-Type'  => 'application/json',
			),
			'body'      => (string) json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			'timeout'   => (int) ( $this->config['timeout'] ?? 8 ),
			// 【M6·P1-3】K1 计量键按车道上报（§0-3）：预算闸（UrlAuditWorker::quota_reached）
			// 查 url_audit_incr/url_audit_batch 键——引擎写侧自此对齐闸读侧；「deepseek」
			// 键保留给评论云审主链（K1 语义不变）。非法 caller fail-safe 归增量键（只放大严）。
			'usage_key' => $this->usage_key_for( $caller ),
		) );

		if ( ! $result->is_ok() ) {
			// 【M6·批B·B-5①】运行性错误标记（transport/semantic/contract/parse——
			// 服务商/网络侧故障，非业务 inconclusive；worker 据此发事件进报错历史）。
			$this->last_meta['llm_error'] = array(
				'category'  => $result->category(),
				'http_code' => $result->status(),
			);
			return array( 'verdict' => 'inconclusive', 'confidence' => 0.0, 'reason' => 'llm: ' . $result->category(), 'hits' => array(), 'injection_attempt' => false );
		}

		$data   = $result->data();
		$choice = $data['choices'][0] ?? null;
		$content = is_array( $choice ) ? (string) ( $choice['message']['content'] ?? '' ) : '';

		// K2 回写（解析成功后——caller 分车道）。
		$parsed = UrlJudge::parse_judgement( $content );
		$usage  = $data['usage'] ?? null;
		if ( null !== $parsed && is_array( $usage ) ) {
			$this->record_usage( $caller, (int) ( $usage['prompt_tokens'] ?? 0 ), (int) ( $usage['completion_tokens'] ?? 0 ) );
		}

		if ( null === $parsed ) {
			// ParseError 穷尽=inconclusive 保持待审——绝不因我方解析失败定罪网站（nw §2.3）。
			// 【M6·批B·B-5①】模型输出契约异常=运行性错误（非业务 inconclusive——事件面）。
			$this->last_meta['llm_error'] = array( 'category' => 'contract', 'http_code' => 200 );
			return array( 'verdict' => 'inconclusive', 'confidence' => 0.0, 'reason' => 'llm parse failed', 'hits' => array(), 'injection_attempt' => false );
		}

		// min_confidence 收口（URL 判定同契约：conf<0.8 → review）。
		$min = max( 0.0, min( 1.0, (float) ( $this->config['min_confidence'] ?? 0.8 ) ) );
		if ( $parsed['confidence'] < $min && 'reject' === $parsed['verdict'] ) {
			$parsed['verdict'] = 'review';
		}

		// 注入检测面（nw §3.4——廉价模式探测；命中不改变 verdict 只做审计+保守化）。
		$injection = false;
		if ( preg_match( '/ignore (all|previous|above)|忽略(以上|所有|之前)|你现在是|system prompt/i', (string) $parsed['reason'] . ' ' . implode( ' ', $parsed['hits'] ) ) ) {
			$injection = true;
		}
		$this->last_meta['injection_attempt'] = $injection;

		return array(
			'verdict'           => $parsed['verdict'],
			'confidence'        => $parsed['confidence'],
			'reason'            => $parsed['reason'],
			'hits'              => $parsed['hits'],
			'injection_attempt' => $injection,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function last_meta(): array {
		return $this->last_meta;
	}

	/**
	 * lane→K1 usage_key 映射（【M6·P1-3】§0-3 闸键对齐——写侧唯一落点）。
	 *
	 * 白名单映射：url_audit_incr|url_audit_batch 原样透传（=预算闸所查键）；
	 * 其它值 fail-safe 归增量键（消耗必须可被某道闸拦住——只放大严）。
	 *
	 * @param string $caller 车道标识（K2 caller 同源）。
	 * @return string Usage 计量键。
	 */
	private function usage_key_for( string $caller ): string {
		if ( LlmUsage::CALLER_URL_AUDIT_BATCH === $caller ) {
			return Usage::KEY_URL_AUDIT_BATCH;
		}
		return Usage::KEY_URL_AUDIT_INCR;
	}

	/**
	 * K2 双车道记账（WP option 通道；caller∈{url_audit_batch,url_audit_incr}）。
	 *
	 * @param string $caller            caller。
	 * @param int    $prompt_tokens     输入 token。
	 * @param int    $completion_tokens 输出 token。
	 * @return void
	 */
	private function record_usage( string $caller, int $prompt_tokens, int $completion_tokens ): void {
		$usage = get_option( LlmUsage::OPTION_NAME, array() );
		$usage = is_array( $usage ) ? $usage : array();
		$now   = time();
		$usage = LlmUsage::record( $usage, $caller, $prompt_tokens, $completion_tokens, $now );
		update_option( LlmUsage::OPTION_NAME, LlmUsage::prune( $usage, null, $now ), false );
	}
}

/**
 * Verdict 兼容引用占位（避免顶部 unused import——error verdict 形态在增量处置不入闸）。
 */
