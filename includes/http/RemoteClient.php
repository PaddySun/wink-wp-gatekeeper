<?php
/**
 * 传输脊（02 §2.5，L1 全库唯一 HTTP 出口）。
 *
 * 所有远端调用（天御核验、后续 DeepSeek/AbuseIPDB 等）必须经本类发出；
 * 驱动代码里禁止出现裸 wp_remote_* 与任何重试循环（R12 多层重试叠乘）。
 *
 * 职责四件：
 *   1. 错误分类——委托纯函数 RemoteResult::classify()（HTTP 分类 > 驱动 body 探测 > 兜底 parse）；
 *   2. 重试纪律——仅 transport 类；指数退避 300/900ms ±20% 抖动；尊重 Retry-After；上限 2 次重试；
 *      report 模式（02 §2.3a 上报用，M3 消费）为无重试短超时档（2s）；
 *   3. 计量——每次调用发起前回调用量钩子（04 §6：含失败调用，按请求计费口径）；
 *   4. 脱敏——check_url/redact 为纯函数；日志与事件字符串出脊前统一脱敏。
 *
 * 出站安全红线（02 §2.5，v1.2 安全审核 #5）：
 *   ① sslverify=true 不可关闭（wp args 硬编码）；
 *   ② 携带密钥的调用强制 https；本类所有调用均携带密钥 → 一律拒绝 http://；
 *   ③ scheme 白名单 http/https + 私有网段/环回/链路本地/CGNAT/元数据地址拒绝（SSRF）；
 *   ④ redirection=0 不跟随（配合 ①② 堵死「重定向剥离 https + 认证头转发」面）。
 *
 * WP 边界：默认 transport 包装 wp_remote_post（含 wp_http_validate_url 双保险，DNS 级
 * SSRF 依赖其解析后校验——静态推断标注，真机清单核对）；transport/sleep 均可注入，纯单测覆盖编排。
 *
 * @package WinkGatekeeper\Http
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Http;

final class RemoteClient {

	/**
	 * 指数退避基线（毫秒）：第 1/2 次重试（02 §2.5：如 300ms/900ms±20%）。
	 */
	private const BACKOFF_BASE_MS = array( 300, 900 );

	/**
	 * 抖动幅度（基线 ±20%）。
	 */
	private const JITTER_RATIO = 0.2;

	/**
	 * 重试上限（不含首发尝试：首发 + 2 重试 = 最多 3 次尝试）。
	 */
	public const MAX_RETRIES = 2;

	/**
	 * report 模式短超时（秒）——上报 fire-and-forget，永不阻塞闸流程（02 §2.3a）。
	 * 【P0-10·F1 批】2→**3**（M4UX 定稿 §2——B 机实测幽灵到达：2s 窗外仍有受理；
	 * 超时上调不改变 fire-and-forget 零重试语义）。
	 */
	public const REPORT_TIMEOUT = 3;

	/**
	 * 标准模式默认超时（秒）。
	 */
	public const DEFAULT_TIMEOUT = 8;

	/**
	 * Retry-After 安全上限（毫秒）——防服务端恶意指定超长等待拖垮评论提交。
	 */
	private const RETRY_AFTER_CAP_MS = 30000;

	/**
	 * @var callable|null fn(string $url, array $wp_args): array|\WP_Error 实际传输器
	 *                    （null=按方法取默认：post→wp_remote_post / get→wp_remote_get 薄包装）。
	 */
	private $transport;

	/**
	 * @var callable|null fn(string $usage_key): void 用量计量钩子（每次调用发起前回调）。
	 */
	private $usage;

	/**
	 * @var callable fn(int $ms): void 睡眠器（默认 usleep；注入以便单测零等待）。
	 */
	private $sleeper;

	/**
	 * @param callable|null     $transport 传输器；缺省 = 按方法取 wp_remote_post/get 薄包装（WP 边界）。
	 * @param callable|null     $usage     计量钩子；缺省不计量（WP 边界由 Bootstrap 接 Usage::bump）。
	 * @param callable|null     $sleeper   睡眠器；缺省 usleep。
	 */
	public function __construct( ?callable $transport = null, ?callable $usage = null, ?callable $sleeper = null ) {
		$this->transport = $transport;
		$this->usage     = $usage;
		$this->sleeper   = $sleeper ?: static function ( int $ms ): void {
			usleep( $ms * 1000 );
		};
	}

	/**
	 * 发起远端 POST（全库唯一出站点）。
	 *
	 * @param string $url  目标 URL（https 强制）。
	 * @param array  $args {
	 *     @var array|string $body       请求体。
	 *     @var array        $headers    请求头。
	 *     @var int          $timeout    超时秒数（默认 8；report 模式恒 3【P0-10·F1】）。
	 *     @var string       $mode       standard（默认）| report（无重试短超时档）。
	 *     @var string       $usage_key  计量键（驱动 id；空则不计量的调用也不该存在，但容错）。
	 *     @var string[]     $required   传给 classify 的必填字段点分路径。
	 *     @var callable|null $probe     传给 classify 的驱动 body 探测回调。
	 * }
	 * @return RemoteResult
	 */
	public function post( string $url, array $args = array() ): RemoteResult {
		return $this->send( $url, $args, false );
	}

	/**
	 * 发起远端 GET（I0 批新增动词——M3-IP 定稿 §5.1/§12-2：诊断面板四源查询走
	 * RemoteClient standard timeout 3s；AbuseIPDB check（§3.1）/StopForumSpam/ip.nc.gy/ip.sb
	 * 均为 GET 端点。红线与 post 同脊：https 强制/拒私网/redirection=0/sslverify=true/
	 * 重试纪律同模式；计量按 usage_key 显式传入才计量——诊断面板调用不带 usage_key=零统计）。
	 *
	 * @param string $url  目标 URL（https 强制）。
	 * @param array  $args 同 post()（body 对 GET 无意义）。
	 * @return RemoteResult
	 */
	public function get( string $url, array $args = array() ): RemoteResult {
		return $this->send( $url, $args, true );
	}

	/**
	 * 发送编排（post/get 共脊）：出站红线 → wp_args 组装 → classify → 重试纪律 → 耗时标注。
	 *
	 * @param string $url    目标 URL。
	 * @param array  $args   调用参数（同 post() docblock）。
	 * @param bool   $is_get true=GET 动词（默认传输取 wp_remote_get）。
	 * @return RemoteResult
	 */
	private function send( string $url, array $args, bool $is_get ): RemoteResult {
		$mode = ( 'report' === ( $args['mode'] ?? 'standard' ) ) ? 'report' : 'standard';

		// 出站红线：URL 校验失败 = 本地快速失败（semantic：不重试、驱动当日停转口径一致）。
		$reject = self::check_url( $url );
		if ( null !== $reject ) {
			return RemoteResult::error( RemoteResult::SEMANTIC, 'outbound blocked: ' . $reject );
		}

		$wp_args = array(
			'headers'     => is_array( $args['headers'] ?? null ) ? $args['headers'] : array(),
			'body'        => $args['body'] ?? '',
			'timeout'     => 'report' === $mode
				? self::REPORT_TIMEOUT
				: ( is_numeric( $args['timeout'] ?? null ) ? (int) $args['timeout'] : self::DEFAULT_TIMEOUT ),
			'redirection' => 0,     // 红线④：不跟随重定向。
			'sslverify'   => true,  // 红线①：强制校验，不可关闭。
			'decompress'  => true,
		);

		// 传输器：注入优先；缺省按方法取 WP 薄包装（wp_http_validate_url 双保险同款）。
		$transport = $this->transport ?? ( $is_get
			? static function ( string $url, array $args ) {
				if ( false === wp_http_validate_url( $url ) ) {
					return new \WP_Error( 'http_request_url_failed', 'URL rejected by wp_http_validate_url' );
				}
				return wp_remote_get( $url, $args );
			}
			: static function ( string $url, array $args ) {
				if ( false === wp_http_validate_url( $url ) ) {
					return new \WP_Error( 'http_request_url_failed', 'URL rejected by wp_http_validate_url' );
				}
				return wp_remote_post( $url, $args );
			} );

		$result      = null;
		$attempts    = 0;
		$usage_key   = isset( $args['usage_key'] ) && is_string( $args['usage_key'] ) ? $args['usage_key'] : '';
		$started_at  = microtime( true );

		while ( true ) {
			$attempts++;
			// 计量：调用发起前回调（04 §6，含注定失败的尝试）。
			if ( '' !== $usage_key && null !== $this->usage ) {
				call_user_func( $this->usage, $usage_key );
			}

			$result = RemoteResult::classify(
				call_user_func( $transport, $url, $wp_args ),
				array(
					'required' => isset( $args['required'] ) && is_array( $args['required'] ) ? $args['required'] : array(),
					'probe'    => isset( $args['probe'] ) && is_callable( $args['probe'] ) ? $args['probe'] : null,
				)
			);

			if ( ! self::should_retry( $result, $attempts, $mode ) ) {
				break;
			}

			call_user_func( $this->sleeper, self::retry_delay_ms( $attempts, $result->retry_after() ) );
		}

		// 耗时标注（R1 §6.3：remote 终态耗时入 meta.raw.latency_ms——快照单一来源消费）。
		$result = $result->with_latency_ms( max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) ) );

		return ( 1 === $attempts ) ? $result : $result->with_attempts( $attempts );
	}

	/**
	 * 重试判定：仅 transport 类、未超上限、非 report 模式（02 §2.5 重试纪律）。
	 *
	 * @param RemoteResult $result          最近一次结果。
	 * @param int          $attempts_done   已执行的尝试次数。
	 * @param string       $mode            standard | report。
	 * @return bool
	 */
	public static function should_retry( RemoteResult $result, int $attempts_done, string $mode = 'standard' ): bool {
		if ( 'report' === $mode ) {
			return false; // 上报永不阻塞：一次即走。
		}
		return $result->retryable() && $attempts_done <= self::MAX_RETRIES;
	}

	/**
	 * 下一次重试的等待毫秒数：Retry-After 优先（秒转毫秒，封顶 30s），否则退避基线 × (1 ± 20% 抖动)。
	 *
	 * @param int      $attempts_done 已执行尝试次数（1 → 第一次重试用 300ms 档，2 → 900ms 档）。
	 * @param int|null $retry_after   服务端 Retry-After 秒数（null = 无）。
	 * @param float|null $jitter_ratio 抖动比例注入（-0.2..0.2）；缺省随机。
	 * @return int
	 */
	public static function retry_delay_ms( int $attempts_done, ?int $retry_after, ?float $jitter_ratio = null ): int {
		if ( null !== $retry_after && $retry_after > 0 ) {
			return min( $retry_after * 1000, self::RETRY_AFTER_CAP_MS );
		}

		$index = max( 0, min( count( self::BACKOFF_BASE_MS ) - 1, $attempts_done - 1 ) );
		$base  = self::BACKOFF_BASE_MS[ $index ];

		if ( null === $jitter_ratio ) {
			$jitter_ratio = ( mt_rand( - 100, 100 ) / 100 ) * self::JITTER_RATIO;
		}
		$jitter_ratio = max( - self::JITTER_RATIO, min( self::JITTER_RATIO, $jitter_ratio ) );

		return max( 0, (int) round( $base * ( 1 + $jitter_ratio ) ) );
	}

	/**
	 * 出站 URL 安检（纯函数）。返回 null = 放行；非 null = 拒绝原因（红线②③）。
	 *
	 * 覆盖面：scheme 白名单、https 强制、host 必在、本地主机名拒绝（localhost/*.local/*.internal）、
	 * IP 字面量（v4/v6）数值法私网/环回/链路本地/CGNAT/未指定地址拒绝（含 ::ffff: 反映射）。
	 * DNS 解析到私网的主机名在此层不可见——由 WP 边界 wp_http_validate_url 补位（静态推断标注）。
	 *
	 * @param string $url
	 * @return string|null
	 */
	public static function check_url( string $url ): ?string {
		$parts = parse_url( trim( $url ) );
		if ( ! is_array( $parts ) ) {
			return 'unparseable url';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return 'scheme not in whitelist (http/https)';
		}
		if ( 'https' !== $scheme ) {
			// 红线②：本脊所有调用均携带密钥 → 强制 https，http 一律拒绝（无开关）。
			return 'http refused: secrets-carrying calls must use https';
		}

		$host = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		if ( '' === $host ) {
			return 'missing host';
		}

		if ( self::is_local_hostname( $host ) ) {
			return "local hostname refused: {$host}";
		}

		$literal = trim( $host, '[]' ); // URL 形态的 v6 字面量带方括号。
		$packed  = @inet_pton( $literal );
		if ( false !== $packed ) {
			$reason = self::check_ip_literal( $literal, $packed );
			if ( null !== $reason ) {
				return $reason;
			}
		}

		return null;
	}

	/**
	 * 日志/事件字符串脱敏（纯函数）：Authorization 头、TC3 Credential/Signature、
	 * Key/secret 系字段赋值（api_key/secret_key/AppSecretKey/access_key_secret…）。
	 *
	 * @param string $text
	 * @return string
	 */
	public static function redact( string $text ): string {
		// 1) Authorization 头整体值 → [REDACTED]（TC3 形态除外——保留算法名，由规则 2 细粒度脱敏）。
		$text = preg_replace( '/(?i)(Authorization\s*[:=])\s+(?!TC3-HMAC-SHA256)[^\r\n,;]+/', '$1 [REDACTED]', $text );

		// 2) TC3 形态：算法名保留，Credential=… / Signature=… 的值脱敏。
		$text = preg_replace( '/(?i)(Credential=)[^,;\s]+/', '$1[REDACTED]', $text );
		$text = preg_replace( '/(?i)(Signature=)[0-9a-f]{16,}/', '$1[REDACTED]', $text );

		// 3) Key/secret 系字段赋值（query/json/日志面通用）：键名保留、值替换。
		// 分隔符两侧各允许一个引号（JSON 形态 "key":"value"）；字符类以 \x27/\x5D 表示单引号/闭括号，规避转义歧义。
		$text = preg_replace(
			'/(?i)((?:api[_-]?key|app[_-]?secret[_-]?key|access[_-]?key[_-]?secret|secret[_-]?id|secret[_-]?key|client[_-]?secret|token)\x22?\s*[=:]\s*\x22?)[^\s,;"\x27}\x5D]+/',
			'$1[REDACTED]',
			$text
		);

		return (string) $text;
	}

	/**
	 * 本地主机名判定（纯函数）。
	 *
	 * @param string $host 小写主机名。
	 * @return bool
	 */
	private static function is_local_hostname( string $host ): bool {
		if ( 'localhost' === $host ) {
			return true;
		}
		foreach ( array( '.localhost', '.local', '.internal', '.lan' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * IP 字面量私网判定（inet_pton 字节序数值比较，02 §2.4 归一化条款同法）。
	 *
	 * @param string $literal 去方括号后的字面量。
	 * @param string $packed  inet_pton 结果（4 或 16 字节二进制）。
	 * @return string|null 拒绝原因；null = 公网放行。
	 */
	private static function check_ip_literal( string $literal, string $packed ): ?string {
		$len = strlen( $packed );

		if ( 4 === $len ) {
			$first = ord( $packed[0] );
			$sec   = ord( $packed[1] );

			$private = ( 10 === $first )                                  // 10/8.
				|| ( 172 === $first && $sec >= 16 && $sec <= 31 )          // 172.16/12.
				|| ( 192 === $first && 168 === $sec )                      // 192.168/16.
				|| ( 127 === $first )                                      // 127/8 环回.
				|| ( 169 === $first && 254 === $sec )                      // 169.254/16 链路本地（含云元数据）.
				|| ( 0 === $first )                                        // 0/8 未指定.
				|| ( 100 === $first && $sec >= 64 && $sec <= 127 );        // 100.64/10 CGNAT.
			return $private ? "private ipv4 refused: {$literal}" : null;
		}

		if ( 16 === $len ) {
			// ::ffff:a.b.c.d 反映射：按内嵌 v4 判定。
			if ( 0 === strncmp( $packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff", 12 ) ) {
				return self::check_ip_literal( $literal, substr( $packed, 12 ) );
			}
			$first16 = unpack( 'n', substr( $packed, 0, 2 ) )[1];
			$all_zero_prefix = ( 0 === strncmp( $packed, str_repeat( "\x00", 15 ), 15 ) );
			$private = ( $all_zero_prefix && "\x01" === $packed[15] )       // ::1 环回.
				|| ( $all_zero_prefix && "\x00" === $packed[15] )           // :: 未指定.
				|| ( ( $first16 & 0xfe00 ) === 0xfc00 )                     // fc00::/7 ULA.
				|| ( ( $first16 & 0xffc0 ) === 0xfe80 );                    // fe80::/10 链路本地.
			return $private ? "private ipv6 refused: {$literal}" : null;
		}

		return null;
	}
}
