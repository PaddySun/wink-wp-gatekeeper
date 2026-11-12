<?php
/**
 * M3-IP 定稿 §5.3 · 受信网段预设校准 CLI（开发期工具，I0 批交付）。
 *
 * 拉 Cloudflare 官方 ips-v4 / ips-v6 文本 → 与 TrustedProxies 常量 diff → 输出差异报告。
 * **人工确认后手改常量**（includes/ip/TrustedProxies.php 的 CLOUDFLARE_V4 / CLOUDFLARE_V6 /
 * PRESET_VERIFIED 三处）——本脚本不自动改源码。
 *
 * 运行时零外网依赖守 G5：本脚本**不在插件运行时加载**，外呼只发生在开发者手动运行本工具时。
 *
 * 用法（仓库根目录）：
 *   php tools/api-smoke/refresh-presets.php
 *   php -d extension=curl -d extension_dir=<php>/ext tools/api-smoke/refresh-presets.php   # PHP 未装 curl 扩展时
 *   php tools/api-smoke/refresh-presets.php --v4-file=v4.txt --v6-file=v6.txt             # 已手动 curl 下载到本地文件
 *
 * 退出码：0 = 无差异；1 = 有差异（人工确认后手改常量）；2 = 拉取失败。
 *
 * EdgeOne 注记（定稿 §5.3）：官方无静态网段列表（api.edgeone.ai/ips 动态发布、约 3–6 个月
 * 轮换）——本脚本不支持校准 EdgeOne（常量保留空数组占位、UI 隐藏）；未来支持补段后再放开。
 *
 * @package WinkGatekeeper\Tools
 */

declare( strict_types=1 );

if ( PHP_SAPI !== 'cli' ) {
	exit( "仅限 CLI 运行。\n" );
}

require_once dirname( __DIR__, 2 ) . '/includes/Autoloader.php';
\WinkGatekeeper\Autoloader::register( dirname( __DIR__, 2 ) . '/includes' );

use WinkGatekeeper\Ip\TrustedProxies;

const CF_V4_URL = 'https://www.cloudflare.com/ips-v4';
const CF_V6_URL = 'https://www.cloudflare.com/ips-v6';

/**
 * 拉取远端文本：ext-curl 优先，缺扩展回落 file_get_contents（allow_url_fopen + openssl），
 * 失败则提示手动 curl + --v4-file/--v6-file 通道。
 *
 * @param string      $url   官方文本 URL。
 * @param string|null $file  本地文件通道（手动下载）。
 * @return string|null 文本；失败 null。
 */
function fetch_text( string $url, ?string $file ): ?string {
	if ( null !== $file ) {
		$text = @file_get_contents( $file );
		return ( false !== $text ) ? $text : null;
	}

	if ( function_exists( 'curl_init' ) ) {
		$base_opts = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_SSL_VERIFYPEER => true,
		);
		$ch = curl_init( $url );
		curl_setopt_array( $ch, $base_opts );
		$text = curl_exec( $ch );
		$err  = curl_errno( $ch );
		// Windows 常见坑：PHP curl 无 CA bundle（error 60）——依次尝试 Git for Windows 自带证书链。
		if ( 60 === $err || 77 === $err ) {
			foreach ( array(
				ini_get( 'curl.cainfo' ) ?: null,
				'C:\\Program Files\\Git\\mingw64\\etc\\ssl\\certs\\ca-bundle.crt',
				'C:\\Program Files\\Git\\usr\\ssl\\certs\\ca-bundle.crt',
				'C:\\Program Files\\Git\\mingw64\\usr\\ssl\\certs\\ca-bundle.crt',
			) as $ca ) {
				if ( is_string( $ca ) && '' !== $ca && file_exists( $ca ) ) {
					curl_setopt_array( $ch, $base_opts + array( CURLOPT_CAINFO => $ca ) );
					$text = curl_exec( $ch );
					$err  = curl_errno( $ch );
					if ( 0 === $err ) {
						break;
					}
				}
			}
		}
		$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );
		return ( 0 === $err && 200 === $code && is_string( $text ) && '' !== $text ) ? $text : null;
	}

	$text = @file_get_contents( $url, false, stream_context_create( array(
		'http' => array( 'timeout' => 15, 'follow_location' => 0 ),
		'ssl'  => array( 'verify_peer' => true ),
	) ) );
	return ( false !== $text && is_string( $text ) && '' !== $text ) ? $text : null;
}

/**
 * 文本 → 行数组（trim + 去空行 + 注释行跳过）。
 *
 * @param string $text
 * @return string[]
 */
function to_lines( string $text ): array {
	$lines = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
		$line = trim( $line );
		if ( '' !== $line && 0 !== strpos( $line, '#' ) ) {
			$lines[] = $line;
		}
	}
	return $lines;
}

/* ---------------- 主流程 ---------------- */

$v4_file = null;
$v6_file = null;
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strncmp( $arg, '--v4-file=', 10 ) ) {
		$v4_file = substr( $arg, 10 );
	}
	if ( 0 === strncmp( $arg, '--v6-file=', 10 ) ) {
		$v6_file = substr( $arg, 10 );
	}
}

echo "== wink-wp-gatekeeper 受信网段预设校准（Cloudflare 官方段 diff）==\n";
echo "常量快照核对日期（TrustedProxies::PRESET_VERIFIED）：" . TrustedProxies::PRESET_VERIFIED . "\n\n";

$raw_v4 = fetch_text( CF_V4_URL, $v4_file );
if ( null === $raw_v4 ) {
	echo "[FAIL] 无法拉取 " . CF_V4_URL . "\n";
	echo "兜底：手动 curl -o v4.txt " . CF_V4_URL . " 后重跑 --v4-file=v4.txt\n";
	exit( 2 );
}
$raw_v6 = fetch_text( CF_V6_URL, $v6_file );
if ( null === $raw_v6 ) {
	echo "[FAIL] 无法拉取 " . CF_V6_URL . "\n";
	echo "兜底：手动 curl -o v6.txt " . CF_V6_URL . " 后重跑 --v6-file=v6.txt\n";
	exit( 2 );
}

$diff = TrustedProxies::diff_cloudflare( to_lines( $raw_v4 ), to_lines( $raw_v6 ) );

echo "-- IPv4（官方 " . count( to_lines( $raw_v4 ) ) . " 条 vs 常量 " . count( TrustedProxies::CLOUDFLARE_V4 ) . " 条）--\n";
foreach ( array(
	'v4_added'   => '新增（官方有、常量无）',
	'v4_removed' => '过期（常量有、官方无）',
	'v4_kept'    => '不变',
) as $key => $label ) {
	echo $label . "：" . count( $diff[ $key ] ) . " 条\n";
	foreach ( $diff[ $key ] as $line ) {
		echo "  " . $line . "\n";
	}
}

echo "\n-- IPv6（官方 " . count( to_lines( $raw_v6 ) ) . " 条 vs 常量 " . count( TrustedProxies::CLOUDFLARE_V6 ) . " 条）--\n";
foreach ( array(
	'v6_added'   => '新增（官方有、常量无）',
	'v6_removed' => '过期（常量有、官方无）',
	'v6_kept'    => '不变',
) as $key => $label ) {
	echo $label . "：" . count( $diff[ $key ] ) . " 条\n";
	foreach ( $diff[ $key ] as $line ) {
		echo "  " . $line . "\n";
	}
}

$total_changes = count( $diff['v4_added'] ) + count( $diff['v4_removed'] ) + count( $diff['v6_added'] ) + count( $diff['v6_removed'] );

echo "\n-- EdgeOne 注记 --\n";
echo "官方无静态网段列表（api.edgeone.ai/ips 动态发布、约 3–6 个月轮换）——本脚本不支持校准；\n";
echo "常量保留空数组占位、UI 隐藏（定稿 §5.3）；未来支持补段后再放开。\n";

if ( 0 === $total_changes ) {
	echo "\n[OK] 快照与官方一致，无需处理。\n";
	exit( 0 );
}

echo "\n[DIFF] 共 {$total_changes} 处差异——人工确认后手改 includes/ip/TrustedProxies.php：\n";
echo "  1. CLOUDFLARE_V4 / CLOUDFLARE_V6 常量按上方报告增删；\n";
echo "  2. PRESET_VERIFIED 改为本次核对日期（" . gmdate( 'Y-m-d' ) . "）；\n";
echo "  3. 跑全量单测（tests/ 预置段计数断言随常量同步）。\n";
exit( 1 );
