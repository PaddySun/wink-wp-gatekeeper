<?php
/**
 * U2 头像国内镜像·纯判定层（M5 定稿 §2 + UI 设计块 1 卡二）。
 *
 * 机制：pre_get_avatar_data filter 改写 args['url'] host——零侵入核心（定稿 §2）。
 * 镜像源现状（定稿 §2 2026-11-14 联网核验）：Cravatar（API 100% 兼容）/ WeAvatar
 * （多级匹配+WebP/AVIF）/ sep.cc——默认值 Cravatar（定稿 §2 建议；L0 实测脚本
 * tools/api-smoke/avatar-sources.php 为唯一允许的改判通道）。
 *
 * fail-safe 纪律：枚举非法 / custom 地址非法（非 https 前缀或无 /avatar/ 尾）/
 * 源 URL 不含 /avatar/ 路径或非 https → 一律返回 null=不镜像（WP 官方源现行行为
 * 零变化——与「增强层非依赖层」哲学同调，M5 定稿 §5.2-4）。
 *
 * @package WinkGatekeeper\Site
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Site;

final class AvatarSources {

	public const SOURCE_OFFICIAL = 'official';
	public const SOURCE_CRAVATAR = 'cravatar';
	public const SOURCE_WEAVATAR = 'weavatar';
	public const SOURCE_SEPCC    = 'sepcc';
	public const SOURCE_CUSTOM   = 'custom';

	/**
	 * 五枚举（UI 卡二下拉顺序；official=「未配置（用 WordPress 官方源）」对齐 Tab②③
	 * 「未配置」语义范式，UI 设计块 1.3）。
	 */
	public const ENUM = array( 'official', 'cravatar', 'weavatar', 'sepcc', 'custom' );

	/**
	 * 内置镜像源 base（尾带 /avatar/；custom 走站长自填）。
	 *
	 * @var array<string, string>
	 */
	public const BASES = array(
		self::SOURCE_CRAVATAR => 'https://cravatar.com/avatar/',
		self::SOURCE_WEAVATAR => 'https://weavatar.com/avatar/',
		self::SOURCE_SEPCC    => 'https://cdn.sep.cc/avatar/',
	);

	/**
	 * 测试按钮「能用但慢」黄档阈值（秒；UI 卡二三态语义）。
	 */
	public const SLOW_SECONDS = 3.0;

	/**
	 * 枚举归一（非法值归 official=fail-safe 零改写）。
	 *
	 * @param mixed $source 原始值。
	 * @return string
	 */
	public static function normalize( $source ): string {
		return is_scalar( $source ) && in_array( (string) $source, self::ENUM, true ) ? (string) $source : self::SOURCE_OFFICIAL;
	}

	/**
	 * 源对应的镜像 base（含尾斜杠）。
	 *
	 * @param string $source      归一后枚举。
	 * @param string $custom_base custom 时站长自填地址。
	 * @return string|null null=不镜像（official 或 custom 地址非法）。
	 */
	public static function base_for( string $source, string $custom_base ): ?string {
		if ( self::SOURCE_OFFICIAL === $source ) {
			return null;
		}
		if ( self::SOURCE_CUSTOM === $source ) {
			return self::custom_base_valid( $custom_base ) ? $custom_base : null;
		}
		return self::BASES[ $source ] ?? null;
	}

	/**
	 * custom 地址合法性：https:// 开头 + /avatar/ 结尾（UI 卡二说明原文——按镜像站说明填写）。
	 *
	 * @param string $base 站长自填地址。
	 * @return bool
	 */
	public static function custom_base_valid( string $base ): bool {
		return '' !== $base
			&& str_starts_with( $base, 'https://' )
			&& str_ends_with( $base, '/avatar/' );
	}

	/**
	 * Gravatar URL → 镜像 URL 改写（保留 /avatar/ 后路径与 query——s/d/r 参数原样）。
	 *
	 * @param string $url  WP 生成的官方 URL（secure.gravatar.com / 0-3.gravatar.com 等）。
	 * @param string $base 镜像 base（base_for 结果）。
	 * @return string|null null=无法解析/非 https/无 /avatar/ 路径（不镜像，原样放行）。
	 */
	public static function mirror_url( string $url, string $base ): ?string {
		$parsed = parse_url( $url );
		if ( ! is_array( $parsed )
			|| ( $parsed['scheme'] ?? '' ) !== 'https'
			|| ! isset( $parsed['path'] )
			|| ! str_starts_with( $parsed['path'], '/avatar/' ) ) {
			return null;
		}
		// base 已含 /avatar/ 尾（BASES 与 custom_base_valid 共契约）——源路径剥去 /avatar
		// 前缀再拼接，防 /avatar/avatar/ 双段（mirror_url 直测钉住）。
		$tail     = substr( $parsed['path'], strlen( '/avatar' ) );
		$mirrored = rtrim( $base, '/' ) . $tail;
		if ( isset( $parsed['query'] ) && '' !== $parsed['query'] ) {
			$mirrored .= '?' . $parsed['query'];
		}
		return $mirrored;
	}

	/**
	 * 测试按钮三态判定（纯函数；UI 卡二：绿=HTTP 200 且 Content-Type 为图片 / 黄=响应
	 * 异常慢 >3s「能用但慢」/ 红=连不上或非图片；UI 设计块 1.3）。
	 *
	 * @param int    $status       HTTP 状态码（0=连接失败）。
	 * @param string $content_type 响应 Content-Type。
	 * @param float  $seconds      耗时秒。
	 * @return string ok|slow|fail
	 */
	public static function judge( int $status, string $content_type, float $seconds ): string {
		if ( 200 !== $status || ! str_starts_with( strtolower( $content_type ), 'image/' ) ) {
			return 'fail';
		}
		return $seconds > self::SLOW_SECONDS ? 'slow' : 'ok';
	}
}
