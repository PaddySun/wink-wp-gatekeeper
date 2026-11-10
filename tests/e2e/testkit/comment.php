<?php
/**
 * 【入库脱敏版（B7 收线 2026-11-11）】占位符对照表：<TEST_HOST>=测试机 IP / <SSH_PORT>=SSH 真口 / <SSH_KEY_PATH>=本机私钥路径 / <WP_ADMIN_USER>=后台用户名。
 * 本机使用法：sed -i 's/<TEST_HOST>/真实IP/g; s/<SSH_PORT>/真实端口/g' comment.php（或 cp 改名填值，勿提交）。详见 README-SANITIZED.md。
 *
 * comment.php — 测试站评论提交器（06 §5 工具箱；沉淀：U2 agent）。
 *
 * 用法（本机 PHP 8.3，需 curl 扩展）：
 *   php comment.php --post=<靶文ID> --text="评论内容" [--author=访客] [--email=a@b.c]
 *                    [--url=http://x] [--mode=https|http] [--out=<证据HTML落盘路径>]
 *                    [--stub=<wk_stub 值>] [--trust=<wk_trust 值>] [--ticket=...] [--randstr=...]
 *                    [--stub-file=<路径>] [--trust-file=<路径>]（B2 增：信用免验全链用例；
 *                    *-file 形态规避 shell 编码/转义，文件原样读入）
 *
 * 端点：
 *   https（默认）: https://test.paddysun.top/wp-comments-post.php，curl --resolve 指到 <TEST_HOST>
 *   http        : http://<TEST_HOST>:18080/wp-comments-post.php，Host 头 test.paddysun.top
 *
 * 判定口径（读返回 HTML）：
 *   intercepted — 含 wink 拦截页特征（class="wk-card"）；解析 WK- 编号/规则/标题/等宽行/返回按钮
 *   allowed     — 3xx 跳转（评论表单 POST 成功后 WP 原生 redirect）
 *   error       — 其它（WP 原生报错页/表单缺字段等），摘录错误文本
 *
 * 输出 KEY=VALUE 行（供 assert.php / 报告引用），正文 HTML 落 --out 文件。
 */

declare( strict_types=1 );

$args = array();
foreach ( array_slice( $argv, 1 ) as $a ) {
	if ( preg_match( '/^--([a-z_-]+)=(.*)$/s', $a, $m ) ) {
		$args[ $m[1] ] = $m[2];
	} elseif ( '--https' === $a ) {
		$args['mode'] = 'https';
	} elseif ( '--http' === $a ) {
		$args['mode'] = 'http';
	}
}

$post  = (int) ( $args['post'] ?? 0 );
$text  = (string) ( $args['text'] ?? '' );
if ( $post < 1 || '' === $text ) {
	fwrite( STDERR, "usage: php comment.php --post=<ID> --text=<内容> [--author --email --url --mode --out]\n" );
	exit( 2 );
}
$author = $args['author'] ?? 'WINK测试访客';
$email  = $args['email'] ?? 'wink-u2-test@example.com';
$url    = $args['url'] ?? '';
$mode   = $args['mode'] ?? 'https';
$out    = $args['out'] ?? '';

$fields = array(
	'comment_post_ID' => (string) $post,
	'author'          => $author,
	'email'           => $email,
	'comment'         => $text,
	'comment_parent'  => '0',
	'url'             => $url,
);

/* B2 增：闸级/票据字段透传（信用免验全链用例——wk_stub/wk_trust/ticket/randstr；
 * *-file 形态优先（文件原样读入，规避 shell 编码与转义）。 */
foreach ( array( 'stub', 'trust', 'ticket', 'randstr', 'verifyparam' ) as $opt ) { // B3 增 verifyparam（wk_captcha_verify_param——阿里单票据）。
	$file = $args[ $opt . '-file' ] ?? '';
	$val  = $file;
	if ( '' === $val ) {
		$val = $args[ $opt ] ?? '';
	} else {
		$val = (string) file_get_contents( $val );
	}
	if ( '' !== $val ) {
		$field_name = 'ticket' === $opt ? 'wk_captcha_ticket' : ( 'randstr' === $opt ? 'wk_captcha_randstr' : ( 'verifyparam' === $opt ? 'wk_captcha_verify_param' : 'wk_' . $opt ) );
		$fields[ $field_name ] = trim( $val, "\r\n" );
	}
}

$ch = curl_init();
if ( 'https' === $mode ) {
	$url_ep = 'https://test.paddysun.top/wp-comments-post.php';
	curl_setopt( $ch, CURLOPT_RESOLVE, array( 'test.paddysun.top:443:<TEST_HOST>' ) );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
} else {
	$url_ep = 'http://<TEST_HOST>:18080/wp-comments-post.php';
	curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Host: test.paddysun.top' ) );
}
curl_setopt_array( $ch, array(
	CURLOPT_URL            => $url_ep,
	CURLOPT_POST           => true,
	CURLOPT_POSTFIELDS     => http_build_query( $fields ),
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_HEADER         => true,
	CURLOPT_TIMEOUT        => 30,
	CURLOPT_CONNECTTIMEOUT => 12,
	CURLOPT_USERAGENT      => 'WINK-U2-testkit/1.0',
) );

$raw  = curl_exec( $ch );
$errno = curl_errno( $ch );
if ( false === $raw ) {
	fwrite( STDERR, "CURL_ERROR {$errno}: " . curl_error( $ch ) . "\n" );
	exit( 3 );
}
$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
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

$decision = 'error';
$marks    = array();

if ( false !== strpos( $body, 'wk-card' ) ) {
	$decision = 'intercepted';
	if ( preg_match( '/WK-\d{8}-\d{4,5}/', $body, $m ) ) {
		$marks['WK_NO'] = $m[0];
	}
	if ( preg_match( '#<h1[^>]*>(.*?)</h1>#s', $body, $m ) ) {
		$marks['TITLE'] = trim( html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
	}
	if ( preg_match( '#<th scope="row">命中规则</th><td class="wk-ev">(.*?)</td>#s', $body, $m ) ) {
		$marks['RULE'] = trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
	}
	if ( preg_match( '#<th scope="row">时间</th><td class="wk-ev">(.*?)</td>#s', $body, $m ) ) {
		$marks['TIME'] = trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
	}
	$marks['HAS_WK_EV_CLASS']  = ( false !== strpos( $body, 'class="wk-ev"' ) ) ? 'yes' : 'no';
	$marks['HAS_BACK_BUTTON']  = ( false !== strpos( $body, 'class="wk-back"' ) ) ? 'yes' : 'no';
	$marks['HAS_LOGO']         = ( false !== strpos( $body, 'class="wk-logo"' ) ) ? 'yes' : 'no';
	if ( preg_match( '#<p class="wk-msg">(.*?)</p>#s', $body, $m ) ) {
		$marks['MESSAGE'] = trim( html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
	}
} elseif ( $code >= 300 && $code < 400 && '' !== $location ) {
	$decision = 'allowed';
}

echo 'HTTP=', $code, "\n";
echo 'DECISION=', $decision, "\n";
if ( '' !== $location ) {
	echo 'LOCATION=', $location, "\n";
}
foreach ( $marks as $k => $v ) {
	echo $k, '=', $v, "\n";
}
if ( '' !== $out ) {
	echo 'BODY_FILE=', $out, "\n";
}
if ( 'error' === $decision ) {
	$plain = trim( preg_replace( '/\s+/', ' ', strip_tags( $body ) ) );
	echo 'ERROR_EXCERPT=', mb_substr( $plain, 0, 300 ), "\n";
}
