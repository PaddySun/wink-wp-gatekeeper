<?php
/**
 * 【入库脱敏版（B7 收线 2026-11-11）】占位符对照表：<TEST_HOST>=测试机 IP / <SSH_PORT>=SSH 真口 / <SSH_KEY_PATH>=本机私钥路径 / <WP_ADMIN_USER>=后台用户名。
 * 本机使用法：sed -i 's/<TEST_HOST>/真实IP/g; s/<SSH_PORT>/真实端口/g' xmlrpc.php（或 cp 改名填值，勿提交）。详见 README-SANITIZED.md。
 *
 * xmlrpc.php — 测试站 XML-RPC 调用器（06 §5 工具箱；沉淀：C3 agent）。
 *
 * 用法（本机 PHP 8.3，需 curl 扩展）：
 *   单方法：  php xmlrpc.php --method=wp.getUsersBlogs --user=<用户名> --pass=<口令>
 *   multicall：php xmlrpc.php --multicall=3 --user=<用户名> --pass=<口令>   （同凭据 3 组内嵌认证）
 *   探活：    php xmlrpc.php --method=system.listMethods
 *
 * 端点：https://test.paddysun.top/xmlrpc.php，curl --resolve 指到 <TEST_HOST>。
 *
 * 判定口径：
 *   FAULT_CODE/FAULT_STRING — XML-RPC fault 结构（IXR_Error 403 + faultString 文案；
 *             wk_captcha_unavailable 结构化拒绝文案 = 超阈计数闸断的识别特征）
 *   RESULT    — 非 fault 响应的原文截断（listMethods 数量/登录成功返回等）
 *
 * 输出 KEY=VALUE 行（供 assert.php / 报告引用）。
 */

declare( strict_types=1 );

$args = array();
foreach ( array_slice( $argv, 1 ) as $a ) {
	if ( preg_match( '/^--([a-z_-]+)=(.*)$/s', $a, $m ) ) {
		$args[ $m[1] ] = $m[2];
	}
}

$method = $args['method'] ?? '';
$multi  = (int) ( $args['multicall'] ?? 0 );
$user   = $args['user'] ?? '';
$pass   = $args['pass'] ?? 'wrong-password-wink-test';

$xml = '';
if ( $multi > 0 ) {
	$calls = '';
	for ( $i = 0; $i < $multi; $i++ ) {
		$calls .= '<value><struct>'
			. '<member><name>methodName</name><value><string>wp.getUsersBlogs</string></value></member>'
			. '<member><name>params</name><value><array><data>'
			. '<value><string>' . htmlspecialchars( $user, ENT_XML1 ) . '</string></value>'
			. '<value><string>' . htmlspecialchars( $pass, ENT_XML1 ) . '</string></value>'
			. '</data></array></value></member>'
			. '</struct></value>';
	}
	$xml = '<?xml version="1.0"?><methodCall><methodName>system.multicall</methodName>'
		. '<params><param><value><array><data>' . $calls . '</data></array></value></param></params></methodCall>';
} elseif ( '' !== $method ) {
	$params = '';
	if ( '' !== $user ) {
		$params = '<param><value><string>' . htmlspecialchars( $user, ENT_XML1 ) . '</string></value></param>'
			. '<param><value><string>' . htmlspecialchars( $pass, ENT_XML1 ) . '</string></value></param>';
	}
	$xml = '<?xml version="1.0"?><methodCall><methodName>' . htmlspecialchars( $method, ENT_XML1 ) . '</methodName>'
		. '<params>' . $params . '</params></methodCall>';
} else {
	fwrite( STDERR, "usage: php xmlrpc.php --method=<方法> [--user --pass] | --multicall=<N> --user --pass\n" );
	exit( 2 );
}

$ch = curl_init();
curl_setopt( $ch, CURLOPT_RESOLVE, array( 'test.paddysun.top:443:<TEST_HOST>' ) );
curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
curl_setopt_array( $ch, array(
	CURLOPT_URL            => 'https://test.paddysun.top/xmlrpc.php',
	CURLOPT_POST           => true,
	CURLOPT_POSTFIELDS     => $xml,
	CURLOPT_HTTPHEADER     => array( 'Content-Type: text/xml' ),
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_HEADER         => true,
	CURLOPT_TIMEOUT        => 30,
	CURLOPT_CONNECTTIMEOUT => 12,
	CURLOPT_USERAGENT      => 'WINK-C3-testkit/1.0',
) );

$raw = curl_exec( $ch );
if ( false === $raw ) {
	fwrite( STDERR, 'CURL_ERROR ' . curl_errno( $ch ) . ': ' . curl_error( $ch ) . "\n" );
	exit( 3 );
}
$code  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
$hsize = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
curl_close( $ch );

$body = substr( $raw, $hsize );
echo 'HTTP=', $code, "\n";

if ( preg_match( '#<name>faultCode</name>\s*<value>\s*<int>(-?\d+)</int>#', $body, $m )
	|| preg_match( '#<name>faultCode</name>\s*<value><int>(-?\d+)</int>#', $body, $m ) ) {
	echo 'FAULT_CODE=', $m[1], "\n";
}
if ( preg_match( '#<name>faultString</name>\s*<value>(.*?)</value>#s', $body, $m ) ) {
	$text = $m[1];
	if ( preg_match( '#<string>(.*?)</string>#s', $text, $m2 ) ) {
		$text = $m2[1];
	}
	echo 'FAULT_STRING=', trim( html_entity_decode( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) ), "\n";
}

// multicall 逐内嵌结果摘录（每个 <struct> fault 或 data 值）。
if ( preg_match_all( '#<name>faultString</name>\s*<value>(?:<string>)?(.*?)(?:</string>)?</value>#s', $body, $all ) ) {
	$i = 0;
	foreach ( $all[1] as $fault ) {
		$i++;
		echo 'CALL_', $i, '_FAULT=', mb_substr( trim( html_entity_decode( $fault, ENT_QUOTES, 'UTF-8' ) ), 0, 120 ), "\n";
	}
}

if ( false === strpos( $body, 'faultCode' ) ) {
	echo 'RESULT=', mb_substr( trim( preg_replace( '/\s+/', ' ', $body ) ), 0, 200 ), "\n";
	if ( preg_match_all( '#<string>([^<]{0,80})</string>#', $body, $names ) ) {
		// system.listMethods：数一数返回的方法名条目（识别「listMethods 仍通」）。
		echo 'METHOD_COUNT=', (string) count( $names[1] ), "\n";
	}
}
