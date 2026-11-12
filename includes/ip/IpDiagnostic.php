<?php
/**
 * IP 检测诊断面板纯函数层（M3-IP 定稿 §5.1，I0 批）。
 *
 * 输入 $_SERVER + network 设置树 → 输出行矩阵；WP 边界渲染在 SettingsPage::render_ip_diagnostic_panel()。
 * 两区：
 *   本地解析区——REMOTE_ADDR（标注是否命中受信网段，复用 TrustedProxies::matches 口径）/
 *   CF-Connecting-IP / X-Real-IP / X-Forwarded-For（逗号链逐项标 受信/公网/私网，复用 normalize）；
 *   当前生效行带五态 ip_src（direct/xff/header/unresolved/ignored_untrusted，IpResolver 既有五态）；
 *   配置一致性红标（配 custom_header 但本请求无该头——WF「Configured but not valid」同款）；
 *   受信网段行显手填 CIDR+预设名+快照校准日期（PRESET_VERIFIED >180 天 → 陈旧黄条）。
 *
 * 手动聚合查询区（拍板 0.1）——四源卡片：
 *   **设计红线：四源仅此手动区出现——零闸路径/零缓存/零统计/零自动调用；任一源失败仅该卡片失败**。
 *   服务端代理走 RemoteClient standard（timeout 3s），结果不落库不缓存（纯请求态渲染）。
 *
 * 四源 URL 常量集中一处（定稿 §12-2）：
 *   **https 硬约束**——RemoteClient 出站红线②强制 https、红线③拒私网/环回（02 §2.5），
 *   本表所有端点必须 https（check_url 在传输脊再验一道，双层防线）。
 *   响应结构 2026-11-12 curl 冒烟实证（三免 Key 源 200 JSON；AbuseIPDB 用站长已配 key）：
 *     abuseipdb     GET …/check?ipAddress=&maxAgeInDays=90&verbose → data.abuseConfidenceScore/…
 *     stopforumspam GET …/api?ip=&json → success + ip.appears/frequency
 *     ipncgy        GET https://ip.nc.gy/json?ip= → country.iso_code / asn.* / proxy.* 六旗
 *     ipsb          GET https://api.ip.sb/geoip/<ip> → country/region/city/isp/asn/organization
 *
 * 纯逻辑类：零 WP 依赖（时钟由 $now 注入，仅 TrustedProxies::PRESET_VERIFIED 陈旧判定消费）。
 *
 * @package WinkGatekeeper\Ip
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Ip;

final class IpDiagnostic {

	/**
	 * 预设快照陈旧阈值（天）——>180 天渲染黄条「网段可能过期，请校准」（定稿 §5.1）。
	 */
	public const PRESET_STALE_DAYS = 180;

	/**
	 * 四源端点常量（集中一处，定稿 §12-2；https 硬约束——见类头注）。
	 *
	 * @var array<string, string> source_id => endpoint 基址（查询串由 source_urls() 组装）。
	 */
	public const REMOTE_SOURCES = array(
		'abuseipdb'     => 'https://api.abuseipdb.com/api/v2/check',
		'stopforumspam' => 'https://api.stopforumspam.org/api',
		'ipncgy'        => 'https://ip.nc.gy/json',
		'ipsb'          => 'https://api.ip.sb/geoip',
	);

	/**
	 * 本地解析区固定三头（设计稿 §5.1 名单；custom_header 另行由红标行呈现）。
	 */
	private const HEADER_ROWS = array( 'CF-Connecting-IP', 'X-Real-IP', 'X-Forwarded-For' );

	/**
	 * 组装四源请求 URL（IPv6 rawurlencode——冒号会破坏 URL；RemoteClient::check_url 再验红线）。
	 *
	 * @param string $ip 已归一化 IP。
	 * @return array<string, string> source_id => 完整 URL。
	 */
	public static function source_urls( string $ip ): array {
		$ip = rawurlencode( $ip );
		return array(
			'abuseipdb'     => self::REMOTE_SOURCES['abuseipdb'] . '?ipAddress=' . $ip . '&maxAgeInDays=90&verbose',
			'stopforumspam' => self::REMOTE_SOURCES['stopforumspam'] . '?ip=' . $ip . '&json',
			'ipncgy'        => self::REMOTE_SOURCES['ipncgy'] . '?ip=' . $ip,
			'ipsb'          => self::REMOTE_SOURCES['ipsb'] . '/' . $ip,
		);
	}

	/**
	 * 本地解析区行矩阵（纯函数；诊断面板渲染单一数据源）。
	 *
	 * @param array<string, mixed> $server           $_SERVER（边界层注入）。
	 * @param array<string, mixed> $network_settings network 设置子树（04 §2）。
	 * @param int|null             $now              当前时钟（注入以便单测；null=time() 仅 WP 边界用）。
	 * @return array{
	 *   mode: string,
	 *   remote: array{raw: string, ip: ?string, trusted: bool, scope: string},
	 *   headers: array<int, array{name: string, raw: ?string, items: array<int, array{raw: string, ip: ?string, scope: string}>}>,
	 *   effective: array{ip: string, ip_src: string},
	 *   flag: bool,
	 *   custom_header: string,
	 *   trusted: array{rows: array<int, array{label: string, count: int, kind: string}>, verified: ?string, stale: bool, age_days: ?int, invalid: string[]}
	 * }
	 */
	public static function analyze( array $server, array $network_settings, ?int $now = null ): array {
		if ( null === $now ) {
			$now = time(); // WP 边界缺省；单测一律显式注入（时钟显式化纪律）。
		}

		$trusted_set = TrustedProxies::from_settings(
			isset( $network_settings['trusted_proxies'] ) && is_array( $network_settings['trusted_proxies'] )
				? $network_settings['trusted_proxies']
				: array()
		);

		// REMOTE_ADDR 行。
		$remote_raw = isset( $server['REMOTE_ADDR'] ) && is_scalar( $server['REMOTE_ADDR'] ) ? (string) $server['REMOTE_ADDR'] : '';
		$remote_ip  = IpResolver::normalize( $remote_raw );
		$remote     = array(
			'raw'     => $remote_raw,
			'ip'      => $remote_ip,
			'trusted' => ( null !== $remote_ip ) && $trusted_set->matches( $remote_ip ),
			'scope'   => self::scope_of( $remote_raw, $trusted_set ),
		);

		// 三固定头行（XFF 逗号链逐项标注）。
		$headers = array();
		foreach ( self::HEADER_ROWS as $name ) {
			$raw   = IpResolver::header_value( $server, $name );
			$items = array();
			if ( null !== $raw ) {
				foreach ( explode( ',', $raw ) as $piece ) {
					$candidate = trim( $piece );
					if ( '' === $candidate ) {
						continue;
					}
					$items[] = array(
						'raw'   => $candidate,
						'ip'    => IpResolver::normalize( $candidate ),
						'scope' => self::scope_of( $candidate, $trusted_set ),
					);
				}
			}
			$headers[] = array( 'name' => $name, 'raw' => $raw, 'items' => $items );
		}

		// 当前生效行（五态 ip_src——IpResolver::resolve 单一出口）。
		$resolver  = new IpResolver( $network_settings );
		$effective = $resolver->resolve( $server );

		// 配置一致性红标：配 custom_header 但本请求无该头（WF「Configured but not valid」同款）。
		$header_name = isset( $network_settings['custom_header'] ) && is_string( $network_settings['custom_header'] )
			? $network_settings['custom_header'] : '';
		// mode 展示口径与 IpResolver 构造器白名单一致（未知值回落 direct——面板不撒谎）。
		$raw_mode    = isset( $network_settings['ip_source'] ) && is_string( $network_settings['ip_source'] )
			? $network_settings['ip_source'] : IpResolver::MODE_DIRECT;
		$mode        = in_array( $raw_mode, array( IpResolver::MODE_DIRECT, IpResolver::MODE_XFF, IpResolver::MODE_CUSTOM_HEADER, IpResolver::MODE_TRAVERSE ), true )
			? $raw_mode : IpResolver::MODE_DIRECT;
		$has_flag    = false;
		if ( IpResolver::MODE_CUSTOM_HEADER === $mode && '' !== $header_name ) {
			$has_flag = ( null === IpResolver::header_value( $server, $header_name ) );
		}

		return array(
			'mode'          => $mode,
			'remote'        => $remote,
			'headers'       => $headers,
			'effective'     => $effective,
			'flag'          => $has_flag,
			'custom_header' => $header_name,
			'trusted'       => self::trusted_rows( $network_settings, $trusted_set, $now ),
		);
	}

	/**
	 * 受信网段行（预设名+条数+快照日期；EdgeOne 空预设不入行——定稿 §5.3 UI 隐藏，
	 * 空预设入选=静默无保护，必须消灭；常量保留占位，校准脚本支持补段后再放开）。
	 *
	 * @param array<string, mixed> $network_settings network 设置子树。
	 * @param TrustedProxies       $trusted_set       已装配信任网段集（取非法行）。
	 * @param int                  $now               当前时钟。
	 * @return array{rows: array<int, array{label: string, count: int, kind: string}>, verified: ?string, stale: bool, age_days: ?int, invalid: string[]}
	 */
	private static function trusted_rows( array $network_settings, TrustedProxies $trusted_set, int $now ): array {
		$proxies = isset( $network_settings['trusted_proxies'] ) && is_array( $network_settings['trusted_proxies'] )
			? $network_settings['trusted_proxies'] : array();

		$rows     = array();
		$verified = null;

		if ( ! empty( $proxies['cloudflare'] ) ) {
			$rows[] = array(
				'label' => 'Cloudflare 官方段',
				'count' => count( TrustedProxies::CLOUDFLARE_V4 ) + count( TrustedProxies::CLOUDFLARE_V6 ),
				'kind'  => 'preset',
			);
			$verified = TrustedProxies::PRESET_VERIFIED;
		}
		// EdgeOne 空预设：刻意无行（UI 隐藏决策——见方法头注）。

		$custom_lines = array();
		$custom_raw   = isset( $proxies['custom'] ) && is_string( $proxies['custom'] ) ? $proxies['custom'] : '';
		if ( '' !== $custom_raw ) {
			foreach ( preg_split( '/\r\n|\r|\n/', $custom_raw ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$custom_lines[] = $line;
				}
			}
		}
		$valid_custom = array_filter( $custom_lines, static fn( string $line ) => null !== TrustedProxies::parse_cidr( $line ) );
		if ( array() !== $valid_custom ) {
			$rows[] = array(
				'label' => '自定义 CIDR',
				'count' => count( $valid_custom ),
				'kind'  => 'custom',
			);
		}

		$age_days = null;
		$stale    = false;
		if ( null !== $verified ) {
			$ts       = strtotime( $verified );
			$age_days = ( false === $ts ) ? null : (int) floor( max( 0, $now - $ts ) / 86400 );
			$stale    = ( null !== $age_days ) && ( $age_days > self::PRESET_STALE_DAYS );
		}

		return array(
			'rows'     => $rows,
			'verified' => $verified,
			'stale'    => $stale,
			'age_days' => $age_days,
			'invalid'  => $trusted_set->invalid_lines(),
		);
	}

	/**
	 * IP 作用域标注（受信 > 私网 > 公网；非法值=invalid）。
	 *
	 * 受信优先（受信网段常为公网 CDN 段）；私网判定与 RemoteClient::check_ip_literal 同口径
	 * （v4 RFC1918/环回/链路本地/CGNAT/未指定；v6 ::1/::/fc00::/7/fe80::/10）。
	 *
	 * @param string         $raw         原始值。
	 * @param TrustedProxies $trusted_set 信任网段集。
	 * @return string trusted|public|private|invalid
	 */
	public static function scope_of( string $raw, TrustedProxies $trusted_set ): string {
		$ip = IpResolver::normalize( $raw );
		if ( null === $ip ) {
			return 'invalid';
		}
		if ( $trusted_set->matches( $ip ) ) {
			return 'trusted';
		}
		$packed = @inet_pton( $ip );
		if ( false === $packed ) {
			return 'invalid';
		}

		if ( 4 === strlen( $packed ) ) {
			$first = ord( $packed[0] );
			$sec   = ord( $packed[1] );
			$private = ( 10 === $first )
				|| ( 172 === $first && $sec >= 16 && $sec <= 31 )
				|| ( 192 === $first && 168 === $sec )
				|| ( 127 === $first )
				|| ( 169 === $first && 254 === $sec )
				|| ( 0 === $first )
				|| ( 100 === $first && $sec >= 64 && $sec <= 127 );
			return $private ? 'private' : 'public';
		}

		$first16 = unpack( 'n', substr( $packed, 0, 2 ) )[1];
		$all_zero_prefix = ( 0 === strncmp( $packed, str_repeat( "\x00", 15 ), 15 ) );
		$private = ( $all_zero_prefix && "\x01" === $packed[15] )
			|| ( $all_zero_prefix && "\x00" === $packed[15] )
			|| ( ( $first16 & 0xfe00 ) === 0xfc00 )
			|| ( ( $first16 & 0xffc0 ) === 0xfe80 );
		return $private ? 'private' : 'public';
	}

	/**
	 * 单源 JSON → 卡片视图模型（手动聚合区；任一源失败仅该卡片失败）。
	 *
	 * @param string            $source  source_id（REMOTE_SOURCES 键）。
	 * @param array<string,mixed> $decoded json_decode 后的响应体。
	 * @return array{ok: bool, rows: array<string, string>, error: string}
	 */
	public static function parse_source( string $source, array $decoded ): array {
		switch ( $source ) {
			case 'abuseipdb':
				$data = $decoded['data'] ?? null;
				if ( ! is_array( $data ) || ! array_key_exists( 'abuseConfidenceScore', $data ) ) {
					return self::fail_card( '响应结构不符（data 缺失）' );
				}
				return array(
					'ok'   => true,
					'rows' => array(
						'评分'   => self::scalar_str( $data['abuseConfidenceScore'] ?? '' ),
						'国家'   => self::scalar_str( $data['countryCode'] ?? '' ),
						'用途'   => self::scalar_str( $data['usageType'] ?? '' ),
						'ISP'   => self::scalar_str( $data['isp'] ?? '' ),
						'Tor'   => self::bool_zh( $data['isTor'] ?? null ),
						'报告数' => self::scalar_str( $data['totalReports'] ?? '' ),
					),
					'error' => '',
				);

			case 'stopforumspam':
				$ip = $decoded['ip'] ?? null;
				if ( empty( $decoded['success'] ) || ! is_array( $ip ) ) {
					return self::fail_card( '响应 success=0 或结构不符' );
				}
				return array(
					'ok'   => true,
					'rows' => array(
						'黑名单出现' => self::scalar_str( $ip['appears'] ?? '' ),
						'报告频次'  => self::scalar_str( $ip['frequency'] ?? '' ),
					),
					'error' => '',
				);

			case 'ipncgy':
				$proxy = $decoded['proxy'] ?? null;
				$asn   = $decoded['asn'] ?? null;
				if ( ! is_array( $proxy ) ) {
					return self::fail_card( '响应结构不符（proxy 缺失）' );
				}
				$country = is_array( $decoded['country'] ?? null ) ? $decoded['country'] : array();
				$asn_row = '';
				if ( is_array( $asn ) ) {
					$number = self::scalar_str( $asn['autonomous_system_number'] ?? '' );
					$org    = self::scalar_str( $asn['autonomous_system_organization'] ?? '' );
					$asn_row = trim( $number . ( '' !== $org ? ' ' . $org : '' ) );
				}
				return array(
					'ok'   => true,
					'rows' => array(
						'代理'     => self::bool_zh( $proxy['is_proxy'] ?? null ),
						'VPN'    => self::bool_zh( $proxy['is_vpn'] ?? null ),
						'Tor'    => self::bool_zh( $proxy['is_tor'] ?? null ),
						'主机托管'  => self::bool_zh( $proxy['is_hosting'] ?? null ),
						'CDN'    => self::bool_zh( $proxy['is_cdn'] ?? null ),
						'匿名网络'  => self::bool_zh( $proxy['is_anonymous'] ?? null ),
						'ASN'    => $asn_row,
						'国家'    => self::scalar_str( $country['iso_code'] ?? '' ),
					),
					'error' => '',
				);

			case 'ipsb':
				if ( ! isset( $decoded['ip'] ) ) {
					return self::fail_card( '响应结构不符（ip 缺失）' );
				}
				$geo = trim( implode( ' ', array_filter( array(
					self::scalar_str( $decoded['country'] ?? '' ),
					self::scalar_str( $decoded['region'] ?? '' ),
					self::scalar_str( $decoded['city'] ?? '' ),
				), static fn( string $part ) => '' !== $part ) ) );
				$asn_row = trim( self::scalar_str( $decoded['asn'] ?? '' ) . ( isset( $decoded['organization'] ) && '' !== (string) $decoded['organization']
					? ' ' . self::scalar_str( $decoded['organization'] ) : '' ) );
				return array(
					'ok'   => true,
					'rows' => array(
						'归属地' => $geo,
						'ISP'  => self::scalar_str( $decoded['isp'] ?? '' ),
						'ASN'  => $asn_row,
					),
					'error' => '',
				);
		}

		return self::fail_card( '未知来源' );
	}

	/**
	 * 失败卡片人话（按传输脊四类；任一源失败仅该卡片失败——渲染层据此单卡标红）。
	 *
	 * @param string $category RemoteResult 四类之一（transport/semantic/contract/parse）。
	 * @param int    $status   HTTP 状态码（传输失败未抵达=0）。
	 * @return string
	 */
	public static function card_error( string $category, int $status ): string {
		switch ( $category ) {
			case 'transport':
				return 0 === $status ? '网络错误/超时' : 'HTTP ' . $status . ' 服务端错误';
			case 'semantic':
				if ( 401 === $status ) {
					return '鉴权失败（API Key 无效？）';
				}
				return '请求被拒（HTTP ' . $status . '）';
			case 'contract':
				return '响应结构不符（服务端改版？）';
			case 'parse':
				return '响应非 JSON';
		}
		return '查询失败';
	}

	/* -----------------------------------------------------------------
	 * 私有件
	 * ---------------------------------------------------------------- */

	/**
	 * @param string $error
	 * @return array{ok: bool, rows: array<string, string>, error: string}
	 */
	private static function fail_card( string $error ): array {
		return array( 'ok' => false, 'rows' => array(), 'error' => $error );
	}

	/**
	 * 标量 → 展示串（数组/ null 一律空串，防止结构漂移把原始结构打进 DOM）。
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function scalar_str( $value ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		return '';
	}

	/**
	 * 布尔旗标 → 是/否（null=「—」无数据）。
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function bool_zh( $value ): string {
		if ( null === $value ) {
			return '—';
		}
		return $value ? '是' : '否';
	}
}
