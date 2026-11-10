<?php
/**
 * 【入库脱敏版（B7 收线 2026-11-11）】占位符对照表：<TEST_HOST>=测试机 IP / <SSH_PORT>=SSH 真口 / <SSH_KEY_PATH>=本机私钥路径 / <WP_ADMIN_USER>=后台用户名。
 * 本机使用法：sed -i 's/<TEST_HOST>/真实IP/g; s/<SSH_PORT>/真实端口/g' precheck.php（或 cp 改名填值，勿提交）。详见 README-SANITIZED.md。
 *
 * precheck.php — 信用免验预检端点测试器（06 §5 工具箱；沉淀：M2 增补轮批次 2/R2）。
 *
 * 用法（本机 PHP 8.3 需 curl 扩展，与 comment.php 同装载形态）：
 *   php precheck.php --post=<靶文ID> --author=昵称 --email=a@b.c --text=<正文草稿>
 *                    [--nonce=<覆盖>] [--stub=<覆盖>] [--repeat=N] [--out=<证据JSON落盘>]
 *
 * 流程：GET 靶文页 → 采集 wk_stub / wkGatePrecheck 内联配置（nonce/action/url）与容器
 * data-wk-trust 标记 → POST admin-ajax?action=wk_gate_precheck（nonce+存根+实时草稿）→
 * 解析 JSON 三分支（trust_pass+trust / four_gate_fail+rule+message / captcha_needed）。
 * --repeat=N 连打 N 次输出每次 result（限流验证）；时延（curl time_total）随行输出。
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

$post_id = (int) ( $args['post'] ?? 0 );
$author  = (string) ( $args['author'] ?? 'WINK测试访客' );
$email   = (string) ( $args['email'] ?? 'wink-u2-test@example.com' );
$text    = (string) ( $args['text'] ?? '这是一条正常的中文评论内容' );
$repeat  = max( 1, min( 60, (int) ( $args['repeat'] ?? 1 ) ) );
$out     = $args['out'] ?? '';

if ( $post_id < 1 ) {
	fwrite( STDERR, "usage: php precheck.php --post=<ID> --author --email --text [--nonce --stub --repeat --out]\n" );
	exit( 2 );
}

function wk_http( string $url, array $opts = array() ) {
	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_RESOLVE, array( 'test.paddysun.top:443:<TEST_HOST>' ) );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
	curl_setopt_array( $ch, array(
		CURLOPT_URL            => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 20,
		CURLOPT_CONNECTTIMEOUT => 12,
		CURLOPT_USERAGENT      => 'WINK-B2-precheck/1.0',
	) + $opts );
	$raw = curl_exec( $ch );
	$t   = (float) curl_getinfo( $ch, CURLINFO_TOTAL_TIME );
	$err = curl_errno( $ch );
	curl_close( $ch );
	return array( $raw, $t, $err );
}

/* ---------- ① 采集页面（存根 + 内联配置 + 容器标记） ---------- */

list( $page, $t_page, $e1 ) = wk_http( 'https://test.paddysun.top/archives/' . $post_id );
if ( false === $page || 0 !== $e1 ) {
	fwrite( STDERR, "PAGE_FETCH_ERROR {$e1}\n" );
	exit( 3 );
}

$stub = isset( $args['stub'] ) ? (string) $args['stub'] : '';
if ( '' === $stub && preg_match( '/name="wk_stub"\s+value="([^"]*)"/', $page, $m ) ) {
	$stub = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
}
$nonce = isset( $args['nonce'] ) ? (string) $args['nonce'] : '';
if ( '' === $nonce && preg_match( '/"nonce":"([0-9a-f]+)"/', $page, $m ) ) {
	$nonce = $m[1];
}
$ajax_action = 'wk_gate_precheck';
if ( preg_match( '/"action":"([a-z_]+)"/', $page, $m ) ) {
	$ajax_action = $m[1];
}

echo 'STUB_LEN=', strlen( $stub ), "\n";
echo 'NONCE_LEN=', strlen( $nonce ), "\n";
echo 'TRUST_MARKER=', ( false !== strpos( $page, 'data-wk-trust="1"' ) ) ? 'present' : 'absent', "\n";
echo 'TRUST_FIELD=', ( false !== strpos( $page, 'name="wk_trust"' ) ) ? 'present' : 'absent', "\n";
echo 'PAGE_TIME_MS=', (int) round( $t_page * 1000 ), "\n";

if ( '' === $stub || '' === $nonce ) {
	fwrite( STDERR, "HARVEST_FAIL（存根/nonce 未采集——容器未渲染或内联配置缺失）\n" );
	exit( 4 );
}

/* ---------- ② 预检端点（可连打） ---------- */

$results   = array();
$latencies = array();
for ( $i = 1; $i <= $repeat; $i++ ) {
	$body = http_build_query( array(
		'action'  => $ajax_action,
		'nonce'   => $nonce,
		'wk_stub' => $stub,
		'post_id' => (string) $post_id,
		'author'  => $author,
		'email'   => $email,
		'content' => $text,
	) );

	list( $raw, $t, $e2 ) = wk_http( 'https://test.paddysun.top/wp-admin/admin-ajax.php', array(
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => $body,
		CURLOPT_HTTPHEADER     => array( 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8' ),
	) );

	$latencies[] = $t;
	$entry       = array( 'n' => $i, 'time_ms' => (int) round( $t * 1000 ) );
	if ( false === $raw || 0 !== $e2 ) {
		$entry['result'] = 'curl_error_' . $e2;
	} else {
		$json = json_decode( $raw, true );
		if ( ! is_array( $json ) ) {
			$entry['result'] = 'non_json';
			$entry['raw']    = substr( (string) $raw, 0, 120 );
		} else {
			$entry['result'] = (string) ( $json['result'] ?? 'unknown' );
			if ( isset( $json['rule'] ) ) {
				$entry['rule'] = (string) $json['rule'];
			}
			if ( isset( $json['message'] ) ) {
				$entry['message'] = (string) $json['message'];
			}
			if ( isset( $json['trust'] ) ) {
				$entry['trust_len'] = strlen( (string) $json['trust'] );
				$entry['trust']     = (string) $json['trust']; // 落盘证据用（凭证非密钥）。
			}
		}
	}
	$results[] = $entry;
}

foreach ( $results as $entry ) {
	$line = 'RESULT=' . $entry['result'] . ' N=' . $entry['n'] . ' TIME_MS=' . $entry['time_ms'];
	if ( isset( $entry['rule'] ) ) {
		$line .= ' RULE=' . $entry['rule'];
	}
	if ( isset( $entry['trust_len'] ) ) {
		$line .= ' TRUST_LEN=' . $entry['trust_len'];
	}
	if ( isset( $entry['message'] ) ) {
		$line .= ' MESSAGE=' . $entry['message'];
	}
	echo $line, "\n";
}

if ( '' !== $out ) {
	file_put_contents( $out, json_encode( $results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
	echo 'OUT_FILE=', $out, "\n";
	// 末次 trust 凭证单独吐出（供 ② 全链提交用例消费）。
	foreach ( array_reverse( $results ) as $entry ) {
		if ( isset( $entry['trust'] ) ) {
			echo 'TRUST_VALUE_BEGIN', "\n", $entry['trust'], "\n", 'TRUST_VALUE_END', "\n";
			break;
		}
	}
}
