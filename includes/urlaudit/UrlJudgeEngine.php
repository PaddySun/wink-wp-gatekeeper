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

/**
 * URL 判定执行器（chat/completions 调用+UrlJudge prompt 组装+K2 双车道记账——
 * 独立小类复用 RemoteClient 传输脊与宽容解析器）。
 */
final class UrlJudgeEngine {

	private $config;
	private $client;
	private $model;
	private $bocha;
	private $last_meta = array();

	public function __construct( array $config, RemoteClient $client, string $model, ?BochaSearch $bocha ) {
		$this->config = $config;
		$this->client = $client;
		$this->model  = $model;
		$this->bocha  = $bocha;
	}

	/**
	 * @param array<string, mixed> $fetched   fetcher 结果。
	 * @param string               $url       规范化 URL。
	 * @param string               $comment_ctx 评论上下文（昵称+正文）。
	 * @param string               $caller    url_audit_batch|url_audit_incr（K2）。
	 * @return array{verdict:string, confidence:float, reason:string, hits:array<string>, injection_attempt: bool}
	 */
	public function judge( array $fetched, string $url, string $comment_ctx, string $caller ): array {
		// 博查交叉信号（fetch 侧不决定——并列喂 LLM；调用失败零信号不阻塞）。
		$bocha_signal = array();
		if ( null !== $this->bocha && $this->bocha->is_configured() ) {
			$signal = $this->bocha->search_site( (string) ( parse_url( $url, PHP_URL_HOST ) ?? '' ) );
			if ( null !== $signal ) {
				$bocha_signal = $signal;
			}
		}

		$messages = UrlJudge::build_messages( $url, $fetched, $comment_ctx, $bocha_signal );
		$payload  = array(
			'model'           => $this->model,
			'temperature'     => 0,
			'response_format' => array( 'type' => 'json_object' ),
			'messages'        => array_merge(
				array( array( 'role' => 'system', 'content' => UrlJudge::SYSTEM_PROMPT ) ),
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
			'usage_key' => 'deepseek', // K1 按次（传输脊键=驱动 id）。
		) );

		if ( ! $result->is_ok() ) {
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
