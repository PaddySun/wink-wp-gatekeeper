<?php
/**
 * M5 定稿 §2 · 头像镜像源 L0 实测 CLI（开发期工具，U2 批交付）。
 *
 * 对四源（官方 Gravatar / Cravatar / WeAvatar / sep.cc）各发**一个**固定 email hash
 * 请求，输出 HTTP 状态/字节数/Content-Type/耗时表（定稿 §2 L0 先行纪律——默认值
 * 改判的唯一通道；运行时零外网依赖守 G5：本脚本**不在插件运行时加载**）。
 *
 * 用法（仓库根目录）：
 *   php tools/api-smoke/avatar-sources.php
 *   php -d extension=curl -d extension_dir=<php>/ext tools/api-smoke/avatar-sources.php
 *   php tools/api-smoke/avatar-sources.php --hash=<32hex>   # 自定探测 hash（默认固定探测邮箱）
 *
 * 退出码：0 = 至少一镜像源可达；1 = 全部不可达（默认值应回落 official 并人工排查网络）。
 *
 * @package WinkGatekeeper\Tools
 */

declare( strict_types=1 );

if ( PHP_SAPI !== 'cli' ) {
	exit( "仅限 CLI 运行。\n" );
}

const PROBE_EMAIL = 'wink-gatekeeper-avatar-probe@example.com';

$sources = array(
	'official' => 'https://secure.gravatar.com/avatar/',
	'cravatar' => 'https://cravatar.com/avatar/',
	'weavatar' => 'https://weavatar.com/avatar/',
	'sepcc'    => 'https://cdn.sep.cc/avatar/',
);

$hash = md5( PROBE_EMAIL );
$args = $_SERVER['argv'] ?? array();
foreach ( $args as $arg ) {
	if ( 0 === strpos( (string) $arg, '--hash=' ) ) {
		$cand = substr( (string) $arg, 7 );
		if ( 1 === preg_match( '/^[0-9a-f]{32}$/', $cand ) ) {
			$hash = $cand;
		}
	}
}

echo "头像镜像源 L0 实测（探测 hash={$hash}，每源 1 请求，timeout 5s）\n";
printf( "%-10s %-6s %-9s %-24s %-8s %s\n", 'source', 'status', 'bytes', 'content-type', 'seconds', '判定' );

$reachable = 0;
foreach ( $sources as $name => $base ) {
	$url  = $base . $hash . '?s=80&d=mm';
	$t0   = microtime( true );
	$body = false;
	$type = '';
	if ( function_exists( 'curl_init' ) ) {
		$ch = curl_init( $url );
		curl_setopt_array( $ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 5,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_HEADEROPTIONS  => array(),
		) );
		curl_setopt( $ch, CURLOPT_HEADERFUNCTION, static function ( $ch, $line ) use ( &$type ): int {
			if ( 0 === stripos( $line, 'content-type:' ) ) {
				$type = trim( substr( $line, 13 ) );
			}
			return strlen( $line );
		} );
		$body   = curl_exec( $ch );
		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );
	} else {
		$ctx   = stream_context_create( array( 'http' => array( 'timeout' => 5, 'follow_location' => 0, 'ignore_errors' => true ) ) );
		$body  = @file_get_contents( $url, false, $ctx );
		$type  = '';
		$status = 0;
		foreach ( $http_response_header ?? array() as $line ) {
			if ( 0 === stripos( $line, 'content-type:' ) ) {
				$type = trim( substr( $line, 13 ) );
			}
			if ( 0 === strpos( $line, 'HTTP/' ) ) {
				$parts = explode( ' ', $line );
				$status = (int) ( $parts[1] ?? 0 );
			}
		}
	}
	$elapsed = microtime( true ) - $t0;
	$bytes   = is_string( $body ) ? strlen( $body ) : 0;
	$verdict = ( 200 === $status && str_starts_with( strtolower( $type ), 'image/' ) )
		? ( $elapsed > 3.0 ? 'slow(能用但慢)' : 'ok' )
		: 'fail';
	if ( 'ok' === $verdict || 'slow(能用但慢)' === $verdict ) {
		$reachable++;
	}
	printf( "%-10s %-6d %-9d %-24s %-8.2f %s\n", $name, $status, $bytes, $type, $elapsed, $verdict );
}

echo "\n结论参考：默认值=首个 ok 镜像（当前定稿建议 Cravatar，兼容性最强）；全部 fail → 网络受限，改在 B 机/有出口环境复跑。\n";
exit( $reachable > 0 ? 0 : 1 );
