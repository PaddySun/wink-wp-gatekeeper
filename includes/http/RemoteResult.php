<?php
/**
 * 远端调用结果对象（02 §2.5 传输脊错误分类学；04 §6 计量口径）。
 *
 * 四类错误类别（驱动只做策略分派，不做分类判断）：
 *   transport —— 5xx、429、超时、连接失败（唯一可重试类别）；
 *   semantic  —— 401 密钥无效、402 余额尽、400 参数错及其它 4xx（不重试，驱动当日停转）；
 *   contract  —— 2xx 但响应缺关键字段/结构不符（厂商改版哨兵，rule_hit=contract_mismatch）；
 *   parse     —— 2xx 但业务解析失败（坏 JSON 等，兜底；LLM 场景由驱动纠错重试 1 次）。
 *
 * classify() 为纯函数：输入 WP HTTP API 返回形态（数组）或失败指示（非数组=传输失败，
 * 真实环境中即 WP_Error/timeout false）；判定优先级：HTTP 分类 > 驱动 body 映射（probe）> 兜底 parse。
 *
 * 不可变值对象：构造器私有，经 classify()/error() 工厂创建。
 *
 * @package WinkGatekeeper\Http
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Http;

final class RemoteResult {

	public const TRANSPORT = 'transport';
	public const SEMANTIC  = 'semantic';
	public const CONTRACT  = 'contract';
	public const PARSE     = 'parse';

	private const CATEGORIES = array( self::TRANSPORT, self::SEMANTIC, self::CONTRACT, self::PARSE );

	/**
	 * @var bool HTTP 层是否成功（2xx 且通过结构检查）。
	 */
	private $ok;

	/**
	 * @var string 错误类别（ok 时为空串）。
	 */
	private $category;

	/**
	 * @var int HTTP 状态码（传输失败未抵达服务器时为 0）。
	 */
	private $status;

	/**
	 * @var string 原始响应体。
	 */
	private $body;

	/**
	 * @var array|null json_decode 后的响应体（无法解析为 null）。
	 */
	private $data;

	/**
	 * @var string 人类可读错误信息（脱敏由 RemoteClient::redact 负责，本类不做网络操作）。
	 */
	private $message;

	/**
	 * @var array 响应头（原样透传，键大小写由调用方归一）。
	 */
	private $headers;

	/**
	 * @var int|null Retry-After 秒数（仅 transport 类有意义）。
	 */
	private $retry_after;

	/**
	 * @var int 本次结果耗费的尝试次数（含传输脊重试；classify 产出恒 1，由 RemoteClient 累计）。
	 */
	private $attempts;

	/**
	 * @var int|null 本次调用总耗时毫秒（首发+重试全程；R1 §6.3 增补——remote 终态耗时入 raw）。
	 *               classify 产出恒 null，由 RemoteClient 在返回前 with_latency_ms 标注。
	 */
	private $latency_ms;

	/**
	 * @param bool      $ok
	 * @param string    $category
	 * @param int       $status
	 * @param string    $body
	 * @param array|null $data
	 * @param string    $message
	 * @param array     $headers
	 * @param int|null  $retry_after
	 */
	private function __construct( bool $ok, string $category, int $status, string $body, $data, string $message, array $headers, $retry_after, int $attempts = 1 ) {
		$this->ok          = $ok;
		$this->category    = $category;
		$this->status      = $status;
		$this->body        = $body;
		$this->data        = $data;
		$this->message     = $message;
		$this->headers     = $headers;
		$this->retry_after = $retry_after;
		$this->attempts    = $attempts;
		$this->latency_ms  = null;
	}

	/**
	 * 纯分类函数：对 WP HTTP API 的原始返回做四类判定。
	 *
	 * @param mixed $raw  wp_remote_* 返回（数组）或失败指示（WP_Error/false/其它非数组）。
	 * @param array $opts {
	 *     @var string[] $required 2xx 响应必须存在的点分字段路径（如 Response.CaptchaCode）——缺失即 contract。
	 *     @var callable|null $probe 驱动 body 探测回调 fn(?array $decoded, string $body): ?string——
	 *                               仅在 HTTP 层为 2xx 时 consulted，返回四类之一或 null（落兜底）。
	 * }
	 * @return self
	 */
	public static function classify( $raw, array $opts = array() ): self {
		if ( ! is_array( $raw ) ) {
			// 连接失败/超时/WP_Error：非数组即传输失败（WP_Error 为对象，同样落此分支）。
			return new self( false, self::TRANSPORT, 0, '', null, 'transport_failed: connection/timeout', array(), null );
		}

		if ( ! isset( $raw['response']['code'] ) || ! is_numeric( $raw['response']['code'] ) ) {
			// 数组结构本身缺失 response.code——契约不符（疑似中间层改写）。
			return new self( false, self::CONTRACT, 0, (string) ( $raw['body'] ?? '' ), null, 'contract: response structure missing', array(), null );
		}

		$status = (int) $raw['response']['code'];
		$body   = is_string( $raw['body'] ?? null ) ? $raw['body'] : '';
		$headers = is_array( $raw['headers'] ?? null ) ? $raw['headers'] : array();

		// 错误路径同样保留解码 body（raw_snapshot 的 code/request_id 启发式提取依赖 $data——
		// 厂商错误码恰在 4xx/5xx 响应体内；data() 消费方以 is_ok() 门控，不受影响）。
		$decoded = self::decode( $body );

		if ( $status >= 500 || 429 === $status ) {
			// 5xx/429：可重试传输类（02 §2.5 表：TransportError）。
			return new self( false, self::TRANSPORT, $status, $body, $decoded, "transport: HTTP {$status}", $headers, self::parse_retry_after( $headers ) );
		}

		if ( $status >= 400 ) {
			// 4xx：语义错误（401/402/400 为设计文档明列；其余 4xx 同为客户端侧不可重试）。
			return new self( false, self::SEMANTIC, $status, $body, $decoded, "semantic: HTTP {$status}", $headers, null );
		}

		// 2xx：HTTP 分类让位于 body 级判定——驱动 probe > 坏 JSON（parse）> 缺字段（contract）> ok。

		if ( isset( $opts['probe'] ) && is_callable( $opts['probe'] ) ) {
			$probed = call_user_func( $opts['probe'], $decoded, $body );
			if ( is_string( $probed ) && in_array( $probed, self::CATEGORIES, true ) ) {
				return new self( false, $probed, $status, $body, $decoded, "body probe: {$probed}", $headers, null );
			}
		}

		if ( null === $decoded ) {
			return new self( false, self::PARSE, $status, $body, null, 'parse: body is not valid JSON', $headers, null );
		}

		$missing = self::missing_required( $decoded, is_array( $opts['required'] ?? null ) ? $opts['required'] : array() );
		if ( '' !== $missing ) {
			return new self( false, self::CONTRACT, $status, $body, $decoded, "contract: field missing {$missing}", $headers, null );
		}

		return new self( true, '', $status, $body, $decoded, 'ok', $headers, null );
	}

	/**
	 * 直造错误结果（供传输脊对本地拦截型失败使用，如出站红线拒绝）。
	 *
	 * @param string $category 四类之一。
	 * @param string $message  错误信息。
	 * @return self
	 */
	public static function error( string $category, string $message ): self {
		if ( ! in_array( $category, self::CATEGORIES, true ) ) {
			throw new \InvalidArgumentException( '未知错误类别：' . $category );
		}
		return new self( false, $category, 0, '', null, $message, array(), null );
	}

	public function is_ok(): bool {
		return $this->ok;
	}

	/**
	 * 错误类别（ok 时为空串）。
	 */
	public function category(): string {
		return $this->category;
	}

	public function retryable(): bool {
		return self::TRANSPORT === $this->category;
	}

	public function status(): int {
		return $this->status;
	}

	public function body(): string {
		return $this->body;
	}

	/**
	 * @return array|null
	 */
	public function data() {
		return $this->data;
	}

	public function message(): string {
		return $this->message;
	}

	public function headers(): array {
		return $this->headers;
	}

	/**
	 * @return int|null
	 */
	public function retry_after() {
		return $this->retry_after;
	}

	public function attempts(): int {
		return $this->attempts;
	}

	/**
	 * 带尝试次数的副本（传输脊重试后标注总尝试数；不可变语义）。
	 *
	 * @param int $attempts
	 * @return self
	 */
	public function with_attempts( int $attempts ): self {
		$clone           = clone $this;
		$clone->attempts = $attempts;
		return $clone;
	}

	/**
	 * @return int|null 本次调用总耗时毫秒（未标注 = null）。
	 */
	public function latency_ms() {
		return $this->latency_ms;
	}

	/**
	 * 带耗时的副本（传输脊在返回前标注；不可变语义——与 with_attempts 同模式）。
	 *
	 * @param int $latency_ms
	 * @return self
	 */
	public function with_latency_ms( int $latency_ms ): self {
		$clone            = clone $this;
		$clone->latency_ms = $latency_ms;
		return $clone;
	}

	/* -------------------------------------------------------------------
	 * raw 快照——错误可观测性的机械保障层（02 §2.5 v1.4 传输脊职责第 5 件；
	 * 04 §3「meta.raw 脱敏规则表」；R1 §6.3 / R4 §D.1）
	 * ------------------------------------------------------------------ */

	/**
	 * 响应侧脱敏快照（单一来源：驱动 error 分支一行合并进 last_meta['raw']，闸侧透传落库）。
	 *
	 * 结构性保证（不靠纪律）：本方法只读自身响应侧字段——请求体/请求头物理上拿不到，
	 * 永不入 raw（请求侧含送审内容、票据、密钥；红线 4 / 04 §3 表「响应侧 only」行）。
	 *
	 * 字段集（04 §3 表第一行，九键封闭）：category / http（连接失败未抵达=0）/ code（厂商
	 * 错误码启发式提取，驱动可覆写）/ message / body / attempts / retry_after / request_id /
	 * latency_ms（R1 §6.3 增补）。body/message 先经 RemoteClient::redact() 脱敏再字节截断
	 * （先脱敏后截断：截断产生的半截键值不再有脱敏机会）；超长追加 …[truncated]。
	 *
	 * CI 密钥扫描红线（02 §7.4）延伸到 events.meta——密钥、Authorization、票据值不得出现
	 * 在 raw：验收口径=EventsTable/RemoteResult 单测断言（红线 4 落地配套）。
	 *
	 * @param int $max_bytes body/message 各自的截断字节（钳 128–2048，04 §3「截断/体量」行）。
	 * @return array<string, mixed>
	 */
	public function raw_snapshot( int $max_bytes = 512 ): array {
		$max = max( 128, min( 2048, $max_bytes ) );

		$decoded = is_array( $this->data ) ? $this->data : null;
		$code    = self::extract_vendor_code( $decoded );

		// message：厂商 message 优先（Response.Error.Message / error.message——站长排障要读人话），
		// 无厂商结构回退传输脊分类信息。
		$vendor_message = self::extract_vendor_message( $decoded );
		$message        = '' !== $vendor_message ? $vendor_message : $this->message;

		return array(
			'category'    => $this->category,
			'http'        => $this->status,
			'code'        => $code,
			'message'     => $this->truncate( RemoteClient::redact( $message ), $max ),
			'body'        => $this->truncate( RemoteClient::redact( $this->body ), $max ),
			'attempts'    => $this->attempts,
			'retry_after' => $this->retry_after,
			'request_id'  => self::extract_request_id( $decoded ),
			'latency_ms'  => $this->latency_ms,
		);
	}

	/**
	 * settings 桥：stats.raw_capture 开关 + stats.raw_max_bytes 截断（04 §3 表「开关」行）。
	 *
	 * 关 = raw 字段省略（返回 null）、错误事件其余字段照记；开 = raw_snapshot(钳后字节)。
	 * 驱动 error 分支经此桥一行合并，避免各驱动各自读键漂移。
	 *
	 * @param RemoteResult       $result   传输脊终态结果。
	 * @param array<string, mixed> $settings 设置树（SettingsSchema::defaults() 形态）。
	 * @return array<string, mixed>|null
	 */
	public static function raw_snapshot_from_settings( RemoteResult $result, array $settings ): ?array {
		if ( isset( $settings['stats']['raw_capture'] ) && ! $settings['stats']['raw_capture'] ) {
			return null;
		}
		$max = isset( $settings['stats']['raw_max_bytes'] ) && is_numeric( $settings['stats']['raw_max_bytes'] )
			? (int) $settings['stats']['raw_max_bytes']
			: 512;
		return $result->raw_snapshot( $max );
	}

	/**
	 * 字节截断（超长追加 …[truncated] 标记；ASCII 安全按字节截）。
	 *
	 * @param string $text
	 * @param int    $max_bytes
	 * @return string
	 */
	private static function truncate( string $text, int $max_bytes ): string {
		if ( '' === $text || strlen( $text ) <= $max_bytes ) {
			return $text;
		}
		return substr( $text, 0, $max_bytes ) . '…[truncated]';
	}

	/**
	 * 厂商错误码启发式提取（raw_snapshot 兜底；驱动最懂自家 body、可在合并时覆写 code）。
	 *
	 * 常见位：Response.Error.Code（腾讯系）/ error.code（OpenAI 系）/ error（字符串形态）。
	 *
	 * @param array|null $decoded
	 * @return string
	 */
	private static function extract_vendor_code( $decoded ): string {
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		foreach ( array(
			array( $decoded['Response']['Error']['Code'] ?? null ),
			array( $decoded['error']['code'] ?? null ),
			array( $decoded['error'] ?? null ),
		) as $candidate ) {
			$value = $candidate[0];
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * 厂商 message 提取（raw_snapshot 的 message 键优先取厂商人话错误信息）。
	 *
	 * 常见位：Response.Error.Message（腾讯系）/ error.message（OpenAI 系）。
	 *
	 * @param array|null $decoded
	 * @return string
	 */
	private static function extract_vendor_message( $decoded ): string {
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		foreach ( array(
			$decoded['Response']['Error']['Message'] ?? null,
			$decoded['error']['message'] ?? null,
		) as $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * RequestId 提取（腾讯系 Response.RequestId，便于向厂商报障；常见位兜底）。
	 *
	 * @param array|null $decoded
	 * @return string
	 */
	private static function extract_request_id( $decoded ): string {
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		foreach ( array( $decoded['Response']['RequestId'] ?? null, $decoded['request_id'] ?? null, $decoded['requestId'] ?? null, $decoded['id'] ?? null ) as $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * @param string $body
	 * @return array|null
	 */
	private static function decode( string $body ) {
		if ( '' === $body ) {
			return null; // 空响应体视为业务解析失败（兜底 parse）。
		}
		$decoded = json_decode( $body, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * 点分路径缺失检查：返回第一个缺失的路径（全部存在返回空串）。
	 *
	 * @param array    $decoded
	 * @param string[] $required
	 * @return string
	 */
	private static function missing_required( array $decoded, array $required ): string {
		foreach ( $required as $path ) {
			$node  = $decoded;
			$parts = explode( '.', (string) $path );
			foreach ( $parts as $part ) {
				if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
					return (string) $path;
				}
				$node = $node[ $part ];
			}
		}
		return '';
	}

	/**
	 * Retry-After 头解析：仅接受整秒值（HTTP-date 形态返回 null，由退避基线接管）。
	 *
	 * @param array $headers
	 * @return int|null
	 */
	private static function parse_retry_after( array $headers ) {
		foreach ( $headers as $name => $value ) {
			if ( is_string( $name ) && 'retry-after' === strtolower( $name ) ) {
				$seconds = is_numeric( $value ) ? (int) $value : (int) filter_var( $value, FILTER_VALIDATE_INT );
				return ( $seconds > 0 ) ? $seconds : null;
			}
		}
		return null;
	}
}
