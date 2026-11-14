<?php
/**
 * LLM 审核·OpenAI 兼容协议族基类（05 §2.2 v1.1 重构——chat/completions × JSON 输出约定
 * × 四类错误处理 × ParseError 纠错重试；2026-11-14 U4 批实装）。
 *
 * **newswright 实战五件套**（M5 定稿 §4 全采纳）：
 *   ① `response_format: json_object`（DeepSeek 已核验支持）——解析失败率当哨兵指标
 *     （突升=端点变化/中转吞参数→ContractError 语义，靠 K1/K2 剪刀差观察）；
 *   ② 宽容解析器：剥 ``` 围栏 → 首 `{` 到尾 `}` 切片 → JSON 解码 → 顶层对象 →
 *     字段完整性 → 枚举域校验 → 数值域校验——机械层不信任模型；
 *   ③ ParseError 纠错重试**恰一次**（坏输出+具体违规说明拼回 user 再请求）——穷尽后
 *     error verdict（fail_mode 闸侧消费；**不**直接降 review——05 §2.2 v1.2 统一口径）；
 *   ④ `finish_reason=length` 直接判废不重试（固定小 schema 不可能被截断，截断=跑题）；
 *     `content_filter` → 非重试落 review（inconclusive 语义——绝不因我方管线问题定罪）；
 *   ⑤ **两段式升级**：第一段 conf<min_confidence → 第二段换 upgrade_model 恰一次
 *     （附加「另一模型对同一页面的存疑判定（供参考，勿盲从）」上下文——仍 <0.8 按
 *     契约降 review；升级段解析失败=五件套穷尽→error）。
 *
 * 判定收口（05 §2.2）：confidence<min_confidence(0.8) → 一律降 review；reject 高置信
 * 才直拒；IP 不送（PIPL 最小化——meta ip_hash 零消费）；拼接串整体 JSON 编码为
 * content_json 纯数据字段（注入防护①，system 首行声明不变）。
 *
 * 计量：K1=Usage 按次（传输脊 usage_key=驱动 id，含失败）；K2=LlmUsage token 原值
 * （解析成功后回写——失败调用只进 K1 不进 K2，诚实口径）。K2 回写经 usage_sink 闭包
 * （默认 WP 通道 get_option/update_option；单测注入捕获闭包）。
 *
 * 分层：payload/parse/收口全纯逻辑（零 WP 依赖）；传输经 RemoteClient 唯一出口（R12：
 * 驱动零自建重试循环——transport 类退避在传输脊）。
 *
 * @package WinkGatekeeper\Drivers\Review
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Drivers\Review;

use WinkGatekeeper\Drivers\ReviewDriverInterface;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Stats\LlmUsage;
use WinkGatekeeper\Verdict;

class OpenAiCompatibleReviewDriver implements ReviewDriverInterface {

	/**
	 * 温度（05 §2.2 基类统一行为：temperature 0——判定任务确定性优先）。
	 */
	public const TEMPERATURE = 0;

	/**
	 * 附加 user 消息文案（hint）：两段式升级上下文 / ParseError 纠错说明——
	 * 都走独立 user 消息，**不进 content_json**（数据字段纯净性）。
	 */
	public const HINT_UPGRADE   = '另一模型对同一评论的判定存疑（置信度未达阈值，供参考勿盲从）——请独立重判并严格只输出约定 JSON。';
	public const HINT_PARSE_FIX = '你的上次输出无法解析为约定 JSON——严格只输出一个 JSON 对象，不要 markdown 围栏，不要解释文字。';

	/**
	 * verdict 合法枚举（D10 输出契约）。
	 */
	public const VERDICTS = array( 'pass', 'reject', 'review' );

	/**
	 * @var array<string, mixed> 配置（api_key/base_url/model/timeout/min_confidence/
	 *                            system_prompt/two_stage/upgrade_model/fail_mode）。
	 */
	protected $config;

	/**
	 * @var RemoteClient 传输脊。
	 */
	protected $client;

	/**
	 * @var string|null 驱动 id（计量键；子类定义）。
	 */
	protected $driver_id = '';

	/**
	 * K2 回写闭包 fn(array $record): void——单测捕获；默认 WP option 通道。
	 *
	 * @var callable|null
	 */
	private $usage_sink;

	/**
	 * 时钟 fn(): int（K2 日界锚）。
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * @var array<string, mixed> 最近一次 check 的事件 meta。
	 */
	protected $last_meta = array();

	/**
	 * @param array<string, mixed>    $config     配置（from_settings 派生）。
	 * @param RemoteClient            $client     传输脊。
	 * @param callable|null           $usage_sink K2 回写（null=默认 WP option 通道）。
	 * @param callable|null           $clock      时钟（null=time）。
	 */
	public function __construct( array $config, RemoteClient $client, ?callable $usage_sink = null, ?callable $clock = null ) {
		$this->config     = $config;
		$this->client     = $client;
		$this->usage_sink = $usage_sink;
		$this->clock      = $clock ?? static fn(): int => time();
	}

	/**
	 * 是否已配置（api_key 非空——子类可扩必填面）。
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== (string) ( $this->config['api_key'] ?? '' );
	}

	/**
	 * 最近一次 check() 的事件 meta。
	 *
	 * @return array<string, mixed>
	 */
	public function last_check_meta(): array {
		return $this->last_meta;
	}

	/**
	 * 审核（单输入三态判定契约）。
	 *
	 * @param string               $content 拼接串（闸统一拼装传入，02 §2.2）。
	 * @param array<string, mixed> $meta    开放键集（ip_hash 零消费——PIPL 最小化）。
	 * @return Verdict
	 */
	public function check( string $content, array $meta ): Verdict {
		$r1 = $this->call( (string) ( $this->config['model'] ?? '' ), $content );

		// ①②③④ 传输/截断/过滤/解析五件套（第一段）。
		$parsed = $this->consume_response( $r1, 'r1' );
		if ( null === $parsed ) {
			return $this->last_error_verdict ?? Verdict::error( $this->driver_id, 'r1 unavailable' );
		}
		if ( ! empty( $parsed['final'] ) ) {
			return $this->verdict_from( $parsed ); // content_filter 等终态收口（不走升级）。
		}

		// 收口：高置信直取。
		$min = max( 0.0, min( 1.0, (float) ( $this->config['min_confidence'] ?? 0.8 ) ) );
		if ( (float) $parsed['confidence'] >= $min ) {
			return $this->verdict_from( $parsed );
		}

		// ⑤ 两段式升级恰一次（two_stage 开才走）。
		if ( ! empty( $this->config['two_stage'] ) && '' !== (string) ( $this->config['upgrade_model'] ?? '' )
			&& (string) ( $this->config['upgrade_model'] ?? '' ) !== (string) ( $this->config['model'] ?? '' ) ) {
			$r2 = $this->call( (string) $this->config['upgrade_model'], $content, self::HINT_UPGRADE );
			$parsed2 = $this->consume_response( $r2, 'r2', false );
			if ( null === $parsed2 ) {
				return $this->last_error_verdict ?? Verdict::error( $this->driver_id, 'r2 unavailable' );
			}
			if ( ! empty( $parsed2['final'] ) ) {
				return $this->verdict_from( $parsed2 );
			}
			$parsed = $parsed2;
			// r2 收口：升级后高置信直取（仍 <min → 按契约降 review——nw §2.4）。
			if ( (float) $parsed['confidence'] >= $min ) {
				return $this->verdict_from( $parsed );
			}
		}

		// 低置信收口：一律降 review（无论 verdict 值——reject conf<min 也不直拒）。
		return $this->downgraded_review( $parsed );
	}

	/* -------------------------------------------------------------------
	 * 传输调用（RemoteClient 唯一出口；驱动零自建重试——R12）
	 * ------------------------------------------------------------------ */

	/**
	 * 发一次 chat/completions 调用。
	 *
	 * @param string      $model   模型名（第一段/升级段）。
	 * @param string      $content 拼接串。
	 * @param string|null $hint    附加 user 消息（升级上下文/纠错说明——独立消息不进 content_json）。
	 * @return RemoteResult
	 */
	protected function call( string $model, string $content, ?string $hint = null ): RemoteResult {
		$messages = array(
			array( 'role' => 'system', 'content' => (string) ( $this->config['system_prompt'] ?? '' ) ),
			array( 'role' => 'user', 'content' => (string) json_encode( array( 'content_json' => $content ), JSON_UNESCAPED_UNICODE ) ),
		);
		if ( null !== $hint && '' !== $hint ) {
			$messages[] = array( 'role' => 'user', 'content' => $hint );
		}

		$payload = array(
			'model'           => $model,
			'temperature'     => self::TEMPERATURE,
			'response_format' => array( 'type' => 'json_object' ), // 五件套①（端点不支持时宽容解析器兜底）。
			'messages'        => $messages,
		);

		$base = rtrim( (string) ( $this->config['base_url'] ?? '' ), '/' );
		return $this->client->post( $base . '/chat/completions', array(
			'headers'   => array(
				'Authorization' => 'Bearer ' . (string) ( $this->config['api_key'] ?? '' ),
				'Content-Type'  => 'application/json',
			),
			'body'      => (string) json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			'timeout'   => (int) ( $this->config['timeout'] ?? 8 ),
			'usage_key' => $this->driver_id, // K1 按次（含失败——传输脊口径）。
		) );
	}

	/* -------------------------------------------------------------------
	 * 响应消费与解析（纯逻辑）
	 * ------------------------------------------------------------------ */

	/**
	 * @var Verdict|null 消费失败时落定的 error verdict（check 收口取）。
	 */
	protected ?Verdict $last_error_verdict = null;

	/**
	 * 消费响应：五件套 ①-④ 编排。
	 *
	 * @param RemoteResult $result 传输脊终态。
	 * @param string       $stage  'r1'|'r2'（meta 标注）。
	 * @return array{verdict:string, confidence:float, reason:string, hits:array<string>}|null null=已落 error verdict。
	 */
	protected function consume_response( RemoteResult $result, string $stage, bool $allow_parse_retry = true ): ?array {
		$this->last_meta['stage'] = $stage;

		if ( ! $result->is_ok() ) {
			$category = $result->category();
			$this->last_meta['error_category'] = $category;
			$this->last_meta['http_status']    = $result->status();
			$this->last_meta['raw']            = $result->raw_snapshot( 512 );
			// transport=传输脊已退避穷尽；semantic=当日停转面（闸编排）；contract/parse=契约哨兵面。
			$this->last_error_verdict = Verdict::error( $this->driver_id, $result->message() );
			return null;
		}

		$data   = $result->data();
		$choice = $data['choices'][0] ?? null;
		if ( ! is_array( $choice ) ) {
			$this->last_error_verdict = Verdict::error( $this->driver_id, 'contract: choices[0] missing' );
			$this->last_meta['error_category'] = RemoteResult::CONTRACT;
			return null;
		}

		$finish = (string) ( $choice['finish_reason'] ?? '' );
		$content = (string) ( $choice['message']['content'] ?? '' );

		// K2 记账：usage 字段在（HTTP 2xx 成功响应）——tokens 原值；回写由解析成败决定。
		$usage = $data['usage'] ?? null;

		// ④ finish_reason 感知。
		if ( 'length' === $finish ) {
			$this->last_meta['finish_reason'] = 'length';
			$this->last_error_verdict = Verdict::error( $this->driver_id, 'finish_reason=length（截断判废不重试）' );
			return null;
		}
		if ( 'content_filter' === $finish ) {
			$this->last_meta['finish_reason'] = 'content_filter';
			return array( 'verdict' => 'review', 'confidence' => 0.0, 'reason' => '输出被内容过滤（非重试 inconclusive）', 'hits' => array(), 'final' => true );
		}

		// ② 宽容解析。
		$parsed = self::parse_review_json( $content );

		// ③ ParseError 纠错重试恰一次（$allow_parse_retry 门控——retry 段/升级段不再重试，
		// 无限递归防线）。
		if ( null === $parsed && $allow_parse_retry ) {
			$this->last_meta['parse_failed_first'] = true;
			$retry = $this->call( (string) ( $this->config['model'] ?? '' ), $content, self::HINT_PARSE_FIX );
			$parsed = $this->consume_response( $retry, $stage . '-retry', false );
			if ( null === $parsed ) {
				return null; // error verdict 已落。
			}
			// 纠错成功结果原样采用（收口继续）。
			return $parsed;
		}

		if ( null === $parsed ) {
			$this->last_meta['error_category'] = RemoteResult::PARSE;
			$this->last_error_verdict = Verdict::error( $this->driver_id, 'parse: output not parseable after retry budget' );
			return null;
		}

		// K2 回写（解析成功后——诚实口径）。
		if ( is_array( $usage ) ) {
			$this->record_usage( (int) ( $usage['prompt_tokens'] ?? 0 ), (int) ( $usage['completion_tokens'] ?? 0 ) );
		}
		$this->last_meta['finish_reason'] = $finish;
		$this->last_meta['parsed']        = true;
		return $parsed;
	}

	/**
	 * K2 回写（caller=review——U5 双车道 caller 由调用方驱动子类改）。
	 *
	 * @param int $prompt_tokens     输入 token。
	 * @param int $completion_tokens 输出 token。
	 * @return void
	 */
	protected function record_usage( int $prompt_tokens, int $completion_tokens ): void {
		if ( null !== $this->usage_sink ) {
			( $this->usage_sink )( array(
				'caller'            => LlmUsage::CALLER_REVIEW,
				'prompt_tokens'     => $prompt_tokens,
				'completion_tokens' => $completion_tokens,
			) );
			return;
		}
		// 默认 WP 通道：option 读改写 + 滚动修剪。
		$usage = get_option( LlmUsage::OPTION_NAME, array() );
		$usage = is_array( $usage ) ? $usage : array();
		$usage = LlmUsage::record( $usage, LlmUsage::CALLER_REVIEW, $prompt_tokens, $completion_tokens, ( $this->clock )() );
		update_option( LlmUsage::OPTION_NAME, LlmUsage::prune( $usage, null, ( $this->clock )() ), false );
	}

	/**
	 * 高置信 verdict 构造（meta 带原始 confidence 供事件展示）。
	 *
	 * @param array{verdict:string, confidence:float, reason:string, hits:array<string>} $parsed 解析结果。
	 * @return Verdict
	 */
	protected function verdict_from( array $parsed ): Verdict {
		$this->last_meta['confidence'] = $parsed['confidence'];
		return match ( $parsed['verdict'] ) {
			'pass'   => Verdict::pass( $this->driver_id, $parsed['reason'], $parsed['hits'] ),
			'reject' => Verdict::reject( $this->driver_id, $parsed['reason'], $parsed['hits'] ),
			default  => Verdict::review( $this->driver_id, $parsed['reason'], $parsed['hits'], $parsed['confidence'] ),
		};
	}

	/**
	 * 低置信降 review（收口统一——reason 保留原值+标注降级）。
	 *
	 * @param array{verdict:string, confidence:float, reason:string, hits:array<string>} $parsed 解析结果。
	 * @return Verdict
	 */
	protected function downgraded_review( array $parsed ): Verdict {
		$this->last_meta['confidence'] = $parsed['confidence'];
		return Verdict::review( $this->driver_id, $parsed['reason'], $parsed['hits'], $parsed['confidence'] );
	}

	/**
	 * 宽容解析器（五件套②——机械层，不信任模型；任何一步失败=null=ParseError）。
	 *
	 * @param string $content 模型输出。
	 * @return array{verdict:string, confidence:float, reason:string, hits:array<string>}|null
	 */
	public static function parse_review_json( string $content ): ?array {
		// 剥 markdown 围栏（```json ... ``` / ``` ... ```）。
		$stripped = trim( $content );
		if ( 0 === strpos( $stripped, '```' ) ) {
			$stripped = preg_replace( '/^```[a-zA-Z0-9]*\s*/', '', $stripped );
			$stripped = preg_replace( '/\s*```$/', '', $stripped );
			$stripped = trim( $stripped );
		}
		// 首 { 到尾 } 切片（前后杂文容忍）。
		$first = strpos( $stripped, '{' );
		$last  = strrpos( $stripped, '}' );
		if ( false === $first || false === $last || $last < $first ) {
			return null;
		}
		$slice = substr( $stripped, $first, $last - $first + 1 );

		$decoded = json_decode( $slice, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		// 字段完整性（四字段全在）。
		foreach ( array( 'verdict', 'confidence', 'reason', 'hits' ) as $field ) {
			if ( ! array_key_exists( $field, $decoded ) ) {
				return null;
			}
		}

		// 枚举域校验。
		$verdict = $decoded['verdict'];
		if ( ! is_string( $verdict ) || ! in_array( $verdict, self::VERDICTS, true ) ) {
			return null;
		}

		// 数值域校验。
		if ( ! is_numeric( $decoded['confidence'] ) ) {
			return null;
		}
		$confidence = max( 0.0, min( 1.0, (float) $decoded['confidence'] ) );

		// reason 字符串化（模型偶发非串——不强拒，宽容层转串）。
		$reason = is_scalar( $decoded['reason'] ) ? (string) $decoded['reason'] : '';

		// hits 数组化（字符串数组；非串项转串）。
		$hits = array();
		if ( is_array( $decoded['hits'] ) ) {
			foreach ( $decoded['hits'] as $hit ) {
				if ( is_scalar( $hit ) && '' !== (string) $hit ) {
					$hits[] = (string) $hit;
				}
			}
		}

		return array(
			'verdict'    => $verdict,
			'confidence' => $confidence,
			'reason'     => $reason,
			'hits'       => $hits,
		);
	}
}
