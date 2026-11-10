<?php
/**
 * 【入库脱敏版（B7 收线 2026-11-11）】占位符对照表：<TEST_HOST>=测试机 IP / <SSH_PORT>=SSH 真口 / <SSH_KEY_PATH>=本机私钥路径 / <WP_ADMIN_USER>=后台用户名。
 * 本机使用法：sed -i 's/<TEST_HOST>/真实IP/g; s/<SSH_PORT>/真实端口/g' login.php（或 cp 改名填值，勿提交）。详见 README-SANITIZED.md。
 *
 * login.php — 测试站 wp-login 登录尝试器（06 §5 工具箱；沉淀：C3 agent）。
 *
 * 用法（本机 PHP 8.3，需 curl 扩展）：
 *   GET  页面核验： php login.php --page [--out=<证据HTML落盘路径>] [--cookie-jar=<文件>]
 *   POST 登录尝试： php login.php --try --user=<用户名> [--pass=<口令>] [--out=...] [--cookie-jar=...]
 *
 * 端点：https://test.paddysun.top/wp-login.php，curl --resolve 指到 <TEST_HOST>。
 *
 * 判定口径：
 *   GATE_CONTAINER — 页面 HTML 含 wk-gate[data-gate="login"] 容器（adaptive 超阈/常显出码）
 *   ERROR_TEXT     — wp-login 原生错误框（#login_error）文本（超阈拦截的 WP_Error 文案落点）
 *   FORM_LOGIN     — 登录表单在场标志
 * POST 成功登录（口令正确）= 3xx 跳转；失败 = 200 错误页（WP 原生形态，无跳转）。
 *
 * 输出 KEY=VALUE 行（供 assert.php / 报告引用），正文 HTML 落 --out 文件。
 */

declare( strict_types=1 );

$args = array();
foreach ( array_slice( $argv, 1 ) as $a ) {
	if ( preg_match( '/^--([a-z_-]+)=(.*)$/s', $a, $m ) ) {
		$args[ $m[1] ] = $m[2];
	} elseif ( '--page' === $a ) {
		$args['page'] = '1';
	} elseif ( '--try' === $a ) {
		$args['try'] = '1';
	}
}

if ( empty( $args['page'] ) === empty( $args['try'] ) ) {
	fwrite( STDERR, "usage: php login.php --page | --try --user=<用户名> [--pass=<口令>] [--out=<文件>] [--cookie-jar=<文件>]\n" );
	exit( 2 );
}

$out   = $args['out'] ?? '';
$jar   = $args['jar'] ?? '';
$user  = $args['user'] ?? '';
$pass  = $args['pass'] ?? 'wrong-password-wink-test';

$ch = curl_init();
curl_setopt( $ch, CURLOPT_RESOLVE, array( 'test.paddysun.top:443:<TEST_HOST>' ) );
curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
$opts = array(
	CURLOPT_URL            => 'https://test.paddysun.top/wp-login.php',
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_HEADER         => true,
	CURLOPT_TIMEOUT        => 30,
	CURLOPT_CONNECTTIMEOUT => 12,
	CURLOPT_USERAGENT      => 'WINK-C3-testkit/1.0',
);
if ( '' !== $jar ) {
	$opts[ CURLOPT_COOKIEFILE ] = $jar; // 复用会话（测试者自建 cookie 场景）。
	$opts[ CURLOPT_COOKIEJAR ]  = $jar;
}
if ( ! empty( $args['try'] ) ) {
	$opts[ CURLOPT_POST ]       = true;
	$opts[ CURLOPT_POSTFIELDS ] = http_build_query( array(
		'log'        => $user,
		'pwd'        => $pass,
		'rememberme' => 'forever',
		// 不带 wk_stub/wk_captcha_*：模拟「超阈后无票直提」形态（服务端应拦 captcha_missing/bad-signature）。
	) );
}
curl_setopt_array( $ch, $opts );

$raw = curl_exec( $ch );
if ( false === $raw ) {
	fwrite( STDERR, 'CURL_ERROR ' . curl_errno( $ch ) . ': ' . curl_error( $ch ) . "\n" );
	exit( 3 );
}
$code  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
$hsize = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
curl_close( $ch );

$headers = substr( $raw, 0, $hsize );
$body    = substr( $raw, $hsize );
if ( '' !== $out ) {
	file_put_contents( $out, $body );
}

$location = '';
if ( preg_match( '/^Location:\s*(.+)$/mi', $headers, $m ) ) {
	$location = trim( $m[1] );
}

echo 'HTTP=', $code, "\n";
if ( '' !== $location ) {
	echo 'LOCATION=', $location, "\n";
}
echo 'GATE_CONTAINER=', ( false !== strpos( $body, 'data-gate="login"' ) ) ? 'yes' : 'no', "\n";
echo 'LOGIN_ASSET=', ( false !== strpos( $body, 'tencent-login.js' ) ) ? 'yes' : 'no', "\n";
echo 'STUB_FIELD=', ( false !== strpos( $body, 'name="wk_stub"' ) ) ? 'yes' : 'no', "\n";
echo 'FORM_LOGIN=', ( false !== strpos( $body, 'name="loginform"' ) || false !== strpos( $body, 'id="loginform"' ) ) ? 'yes' : 'no', "\n";

if ( preg_match( '#<div id="login_error"[^>]*>(.*?)</div>#s', $body, $m ) ) {
	$text = trim( html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
	echo 'ERROR_TEXT=', $text, "\n";
}
if ( preg_match( '#<p class="message[^"]*"[^>]*>(.*?)</p>#s', $body, $m ) ) {
	$text = trim( html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
	echo 'INFO_TEXT=', $text, "\n";
}
if ( '' !== $out ) {
	echo 'BODY_FILE=', $out, "\n";
}
