<?php
/**
 * 全站唯一真实 IP 解析出口（02 §2.4，D11/F29）。
 *
 * 天御核验 UserIp、登录失败计数、IP 信誉查询、上报四条链路一律取
 * IpResolver::resolve() 的结果——解析策略集中一处，防止各链路各自踩坑互相矛盾。
 *
 * 三模式（04 §2 network.ip_source）：
 *   direct（默认）→ REMOTE_ADDR；
 *   xff   → REMOTE_ADDR ∈ 信任段才取 X-Forwarded-For 最右条目（受信边缘写入、不可预填），
 *           否则忽略头（信任链不变式：防直连伪造，R11）；
 *   custom_header → REMOTE_ADDR ∈ 信任段才读自定义头（CF-Connecting-IP 类 CDN 权威覆写头，
 *           CDN 站点推荐模式）。
 *   traverse【M3-IP 定稿 §5.2，I0 批】→ REMOTE_ADDR ∈ 信任段才对 XFF 做链式回溯
 *           （traverse_xff：从右往左跳过受信代理取第一个非受信 IP，多级反代专用）；
 *           REMOTE_ADDR 不受信时恒退化=照 xff 档处理（转发头一律忽略）。不作默认。
 *
 * 归一化条款（v1.2）：去端口/方括号、::ffff: 反映射、IPv6 压缩小写；CIDR 匹配数值比较
 * （TrustedProxies 消费本类 normalize()）。任一步失败按非法值跳过：头值非法/缺失回退
 * REMOTE_ADDR 并记 ip_src=unresolved。
 *
 * 链式回溯（高级选项，多级代理专用）：traverse_xff() 从右往左跳过信任段取第一个非信任 IP，
 * 全链信任 → null（不可解析，调用方回退 REMOTE_ADDR）。可被「可信网段内客户端出口」型
 * 伪造（Workers/WARP 预填 XFF，v1.2 安全审核 P0）——默认不开，UI 明示风险（M3 随 Tab④
 * 高级折叠区落地；编排位见 CommentGate 步骤 2 注释）。
 *
 * 解析结果 ip_src 枚举：direct | xff | header | unresolved | ignored_untrusted（落事件 meta）。
 *
 * 纯逻辑类：$_SERVER 由 Bootstrap/闸边界层注入，本类零 WP 依赖。
 *
 * @package WinkGatekeeper\Ip
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Ip;

final class IpResolver {

	public const SRC_DIRECT           = 'direct';
	public const SRC_XFF              = 'xff';
	public const SRC_HEADER           = 'header';
	public const SRC_UNRESOLVED       = 'unresolved';
	public const SRC_IGNORED_UNTRUSTED = 'ignored_untrusted';

	public const MODE_DIRECT        = 'direct';
	public const MODE_XFF           = 'xff';
	public const MODE_CUSTOM_HEADER = 'custom_header';
	/**
	 * traverse 档（M3-IP 定稿 §5.2，I0 批）：REMOTE_ADDR 必须命中受信网段 + XFF 从右往左
	 * 跳过受信代理取第一个非受信 IP（traverse_xff() 零件纯接线）。不作默认——可直连源站
	 * 部署下回溯会把伪造链当真（v1.2 安全审核 P0 警示保留）；REMOTE_ADDR 不受信时恒退化
	 * =照 rightmost 档（xff）处理：转发头一律忽略（信任链不变式 R11 不破）。
	 */
	public const MODE_TRAVERSE      = 'traverse';

	/**
	 * @var string 当前解析模式。
	 */
	private $mode;

	/**
	 * @var TrustedProxies 信任网段集。
	 */
	private $trusted;

	/**
	 * @var string custom_header 模式的头名。
	 */
	private $header_name;

	/**
	 * @param array<string, mixed> $network_settings network 子树（04 §2）。
	 */
	public function __construct( array $network_settings = array() ) {
		$mode         = $network_settings['ip_source'] ?? self::MODE_DIRECT;
		$this->mode   = in_array( $mode, array( self::MODE_DIRECT, self::MODE_XFF, self::MODE_CUSTOM_HEADER, self::MODE_TRAVERSE ), true )
			? $mode
			: self::MODE_DIRECT; // 未知取值回落直连（保守：不读任何转发头）。

		$proxies       = isset( $network_settings['trusted_proxies'] ) && is_array( $network_settings['trusted_proxies'] )
			? $network_settings['trusted_proxies']
			: array();
		$this->trusted = TrustedProxies::from_settings( $proxies );

		$header        = $network_settings['custom_header'] ?? '';
		$this->header_name = is_string( $header ) ? $header : '';
	}

	/**
	 * 解析当前请求的真实 IP。
	 *
	 * @param array<string, mixed> $server $_SERVER（边界层注入）。
	 * @return array{ip: string, ip_src: string}
	 */
	public function resolve( array $server ): array {
		$remote_raw = isset( $server['REMOTE_ADDR'] ) && is_scalar( $server['REMOTE_ADDR'] ) ? (string) $server['REMOTE_ADDR'] : '';
		$remote     = self::normalize( $remote_raw );
		if ( null === $remote ) {
			// REMOTE_ADDR 缺失/非法：无任何可信来源。
			return array( 'ip' => '', 'ip_src' => self::SRC_UNRESOLVED );
		}

		if ( self::MODE_TRAVERSE === $this->mode ) {
			// traverse 档（M3-IP 定稿 §5.2）：REMOTE_ADDR 不受信时恒退化=照 rightmost 档处理
			// （转发头一律忽略，信任链不变式 R11 不破——伪造链任意变化不得生效）。
			if ( ! $this->trusted->matches( $remote ) ) {
				return array( 'ip' => $remote, 'ip_src' => self::SRC_IGNORED_UNTRUSTED );
			}
			$xff = self::header_value( $server, 'X-Forwarded-For' );
			if ( null === $xff ) {
				return array( 'ip' => $remote, 'ip_src' => self::SRC_UNRESOLVED );
			}
			// 链式回溯：从右往左跳过受信代理取第一个非受信 IP；全链信任 → null 回退 REMOTE_ADDR。
			$ip = self::traverse_xff( $xff, $this->trusted );
			if ( null === $ip ) {
				return array( 'ip' => $remote, 'ip_src' => self::SRC_UNRESOLVED );
			}
			// ip_src 五态枚举不扩：IP 取自 XFF 链 → xff（落事件 meta 口径不变）。
			return array( 'ip' => $ip, 'ip_src' => self::SRC_XFF );
		}

		if ( self::MODE_XFF === $this->mode ) {
			if ( ! $this->trusted->matches( $remote ) ) {
				// 信任链不变式：连接来源不可信 → 转发头一律忽略（防伪造）。
				return array( 'ip' => $remote, 'ip_src' => self::SRC_IGNORED_UNTRUSTED );
			}
			$xff = self::header_value( $server, 'X-Forwarded-For' );
			if ( null === $xff ) {
				return array( 'ip' => $remote, 'ip_src' => self::SRC_UNRESOLVED );
			}
			// v1.2 收紧：仅取最右一个条目（受信 CDN 边缘写入，不可预填）。
			$parts = explode( ',', $xff );
			$rightmost = self::normalize( trim( (string) end( $parts ) ) );
			if ( null === $rightmost ) {
				return array( 'ip' => $remote, 'ip_src' => self::SRC_UNRESOLVED );
			}
			return array( 'ip' => $rightmost, 'ip_src' => self::SRC_XFF );
		}

		if ( self::MODE_CUSTOM_HEADER === $this->mode ) {
			if ( ! $this->trusted->matches( $remote ) ) {
				return array( 'ip' => $remote, 'ip_src' => self::SRC_IGNORED_UNTRUSTED );
			}
			$value = ( '' !== $this->header_name ) ? self::header_value( $server, $this->header_name ) : null;
			if ( null === $value ) {
				return array( 'ip' => $remote, 'ip_src' => self::SRC_UNRESOLVED );
			}
			$ip = self::normalize( $value );
			if ( null === $ip ) {
				// 值非法则回退 REMOTE_ADDR（02 §2.4）。
				return array( 'ip' => $remote, 'ip_src' => self::SRC_UNRESOLVED );
			}
			return array( 'ip' => $ip, 'ip_src' => self::SRC_HEADER );
		}

		return array( 'ip' => $remote, 'ip_src' => self::SRC_DIRECT );
	}

	/**
	 * 链式回溯（高级选项，默认不开——见类注释的伪造面警示）。
	 *
	 * @param string          $xff X-Forwarded-For 原始值（逗号分隔链，左起最旧）。
	 * @param TrustedProxies  $trusted 信任网段集。
	 * @return string|null 第一个非信任 IP；全链信任/全非法 → null。
	 */
	public static function traverse_xff( string $xff, TrustedProxies $trusted ): ?string {
		$parts = explode( ',', $xff );
		for ( $i = count( $parts ) - 1; $i >= 0; $i-- ) {
			$candidate = trim( $parts[ $i ] );
			if ( '' === $candidate ) {
				continue;
			}
			$ip = self::normalize( $candidate );
			if ( null === $ip ) {
				continue; // 非法值跳过（归一化条款）。
			}
			if ( ! $trusted->matches( $ip ) ) {
				return $ip;
			}
		}
		return null;
	}

	/**
	 * IP 归一化（v1.2 归一化条款，纯函数）：
	 * 去首尾空白 → 去端口/方括号 → ::ffff: 反映射 → inet_pton/inet_ntop 规范形（压缩小写）。
	 * 任一步失败返回 null。
	 *
	 * @param string $ip
	 * @return string|null
	 */
	public static function normalize( string $ip ): ?string {
		$ip = trim( $ip );
		if ( '' === $ip ) {
			return null;
		}

		// [v6]:port 形态。
		if ( '[' === $ip[0] ) {
			$close = strpos( $ip, ']' );
			if ( false === $close ) {
				return null;
			}
			$ip = substr( $ip, 1, $close - 1 );
		} elseif ( false !== strpos( $ip, ':' ) && substr_count( $ip, ':' ) >= 2 && false === strpos( $ip, '.' ) ) {
			// 裸 v6:port（如 ::1:443 不可区分——只处理 ≥2 冒号且含端口尾缀的明确形态：
			// 最后一组恰为 1-5 位数字且地址本身非合法 v6 时尝试剥端口）。
			if ( ! ( false !== @inet_pton( $ip ) ) ) {
				$last_colon = strrpos( $ip, ':' );
				$port       = substr( $ip, $last_colon + 1 );
				if ( false !== $last_colon && '' !== $port && ctype_digit( $port ) && strlen( $port ) <= 5 ) {
					$stripped = substr( $ip, 0, $last_colon );
					$ip       = ( false !== @inet_pton( $stripped ) ) ? $stripped : $ip;
				}
			}
		} elseif ( false !== strpos( $ip, ':' ) ) {
			// v4:port。
			$last_colon = strrpos( $ip, ':' );
			$port       = substr( $ip, $last_colon + 1 );
			if ( false !== $last_colon && '' !== $port && ctype_digit( $port ) && strlen( $port ) <= 5 ) {
				$stripped = substr( $ip, 0, $last_colon );
				$ip       = ( false !== @inet_pton( $stripped ) ) ? $stripped : $ip;
			}
		}

		$packed = @inet_pton( $ip );
		if ( false === $packed ) {
			return null;
		}

		// ::ffff:a.b.c.d 反映射为 v4（冷却/计数键防文本变体稀释）。
		if ( 16 === strlen( $packed )
			&& 0 === strncmp( $packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff", 12 ) ) {
			$packed = substr( $packed, 12 );
		}

		$canonical = @inet_ntop( $packed );
		return ( false === $canonical ) ? null : $canonical;
	}

	/**
	 * 头名 → $_SERVER 键查找（X-Forwarded-For → HTTP_X_FORWARDED_FOR 之类的 PHP 归一化）。
	 *
	 * @param array<string, mixed> $server
	 * @param string               $name 头名（任意大小写/连字符形态）。
	 * @return string|null
	 */
	public static function header_value( array $server, string $name ): ?string {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		if ( isset( $server[ $key ] ) && is_scalar( $server[ $key ] ) ) {
			$value = (string) $server[ $key ];
			return ( '' !== $value ) ? $value : null;
		}
		return null;
	}
}
