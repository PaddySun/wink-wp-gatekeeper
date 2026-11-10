<?php
/**
 * 信任代理网段匹配（02 §2.4：预置段 + 自定义 CIDR）。
 *
 * - 预置段为静态快照（R14 网段时效风险：随插件版本维护，设置页显示最后核对日期）；
 * - Cloudflare 段源出官方公布页 https://www.cloudflare.com/ips/（快照核对日期见 PRESET_VERIFIED）；
 * - EdgeOne 无官方静态列表——回源网段约 3-6 个月轮换、经 API 获取
 *   （https://cloud.tencent.com/document/product/1552/76086、https://api.edgeone.ai/ips），
 *   静态快照必然过期，故 M1 预置空数组 + 站长自定义 CIDR 过渡（静态结论，未运行验证）；
 * - CIDR 匹配在归一化后按 inet_pton 字节序数值比较（v1.2 归一化条款：规避文本变体绕过）。
 *
 * 纯逻辑类：$_SERVER 注入由边界层负责，本类零 WP 依赖。
 *
 * @package WinkGatekeeper\Ip
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Ip;

final class TrustedProxies {

	/**
	 * 预置段快照的最后核对日期（YYYY-MM-DD，R14：设置页展示、版本发布时复核）。
	 */
	public const PRESET_VERIFIED = '2026-11-09';

	/**
	 * Cloudflare 官方 IPv4 段快照（https://www.cloudflare.com/ips-v4）。
	 */
	public const CLOUDFLARE_V4 = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
	);

	/**
	 * Cloudflare 官方 IPv6 段快照（https://www.cloudflare.com/ips-v6）。
	 */
	public const CLOUDFLARE_V6 = array(
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * EdgeOne 段快照：官方无静态列表（回源网段轮换，经 API 获取——见类注释），
	 * M1 以空数组交付（站长自定义 CIDR 过渡），快照自动化留后续版本。
	 */
	public const EDGEONE = array();

	/**
	 * 已解析的 CIDR 条目。
	 *
	 * @var array<int, array{packed: string, mask: string, prefix: int, family: int}>
	 */
	private $entries = array();

	/**
	 * 被跳过的非法自定义行（原始文本，供后台提示）。
	 *
	 * @var string[]
	 */
	private $invalid = array();

	/**
	 * @param string[] $cidrs CIDR/单 IP 行列表。
	 */
	public function __construct( array $cidrs ) {
		foreach ( $cidrs as $cidr ) {
			if ( ! is_string( $cidr ) ) {
				continue;
			}
			$parsed = self::parse_cidr( trim( $cidr ) );
			if ( null === $parsed ) {
				if ( '' !== trim( $cidr ) ) {
					$this->invalid[] = trim( $cidr );
				}
				continue;
			}
			$this->entries[] = $parsed;
		}
	}

	/**
	 * 从 network.trusted_proxies 设置子树装配（04 §2：cloudflare/edgeone 开关 + custom 每行一条）。
	 *
	 * @param array<string, mixed> $settings
	 * @return self
	 */
	public static function from_settings( array $settings ): self {
		$cidrs = array();

		if ( ! empty( $settings['cloudflare'] ) ) {
			$cidrs = array_merge( $cidrs, self::CLOUDFLARE_V4, self::CLOUDFLARE_V6 );
		}
		if ( ! empty( $settings['edgeone'] ) ) {
			$cidrs = array_merge( $cidrs, self::EDGEONE );
		}

		$custom = isset( $settings['custom'] ) && is_string( $settings['custom'] ) ? $settings['custom'] : '';
		if ( '' !== $custom ) {
			$cidrs = array_merge( $cidrs, preg_split( '/\r\n|\r|\n/', $custom ) );
		}

		return new self( $cidrs );
	}

	/**
	 * 解析单条 CIDR（或裸 IP——按主机掩码 /32 或 /128）。
	 *
	 * @param string $cidr
	 * @return array{packed: string, mask: string, prefix: int, family: int}|null
	 */
	public static function parse_cidr( string $cidr ): ?array {
		$cidr = trim( $cidr );
		if ( '' === $cidr ) {
			return null;
		}

		if ( false !== strpos( $cidr, '/' ) ) {
			list( $ip, $prefix_raw ) = explode( '/', $cidr, 2 );
			if ( ! is_numeric( $prefix_raw ) || '' === $prefix_raw ) {
				return null;
			}
			$prefix = (int) $prefix_raw;
			if ( $prefix < 0 || (string) $prefix !== $prefix_raw ) {
				return null; // 拒绝非规范形态（'08'、'-1'、'8.5'）。
			}
		} else {
			$ip     = $cidr;
			$prefix = -1; // 依 family 后定。
		}

		$packed = @inet_pton( $ip );
		if ( false === $packed ) {
			return null;
		}

		$family = strlen( $packed ); // 4 = v4，16 = v6.
		if ( -1 === $prefix ) {
			$prefix = ( 4 === $family ) ? 32 : 128;
		}
		if ( $prefix > $family * 8 ) {
			return null;
		}

		return array(
			'packed' => $packed,
			'mask'   => self::pack_mask( $prefix, $family ),
			'prefix' => $prefix,
			'family' => $family,
		);
	}

	/**
	 * IP 是否命中任一信任网段（先归一化——含 ::ffff: 反映射，再字节序数值比较）。
	 *
	 * @param string $ip
	 * @return bool
	 */
	public function matches( string $ip ): bool {
		$normalized = IpResolver::normalize( $ip );
		if ( null === $normalized ) {
			return false;
		}
		$packed = @inet_pton( $normalized );
		if ( false === $packed ) {
			return false;
		}

		foreach ( $this->entries as $entry ) {
			if ( strlen( $packed ) !== $entry['family'] ) {
				continue;
			}
			if ( ( $packed & $entry['mask'] ) === ( $entry['packed'] & $entry['mask'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 被跳过的非法行（自定义 CIDR 输入反馈）。
	 *
	 * @return string[]
	 */
	public function invalid_lines(): array {
		return $this->invalid;
	}

	/**
	 * 按前缀长度打包网络掩码（v4 32bit / v6 128bit 全 1 前缀 + 全 0 后缀）。
	 *
	 * @param int $prefix
	 * @param int $family 字节数（4/16）。
	 * @return string
	 */
	private static function pack_mask( int $prefix, int $family ): string {
		$mask = str_repeat( "\xff", intdiv( $prefix, 8 ) );
		if ( 0 !== $prefix % 8 ) {
			$mask .= chr( ( 0xff << ( 8 - $prefix % 8 ) ) & 0xff );
		}
		return str_pad( $mask, $family, "\x00", STR_PAD_RIGHT );
	}
}
