<?php
/**
 * RemoteClient 传输脊纯逻辑单元测试（02 §2.5：出站安全红线 / 重试纪律 / 脱敏 / 计量）。
 *
 * WP 薄壳（wp_remote_post 包装）不可单测，此处覆盖其全部纯决策函数：
 *   - check_url：scheme 白名单 / https 强制 / 私有网段·环回·链路本地拒绝（SSRF）；
 *   - redact：Authorization / Key / secret / AppSecretKey 模式脱敏；
 *   - retry_delay_ms：300/900ms 指数退避 ±20% 抖动，尊重 Retry-After；
 *   - should_retry：仅 transport 类、上限 2 次、report 模式（M3 上报）不重试；
 *   - post()：注入假 transport 的重试编排 + 计量钩子（调用发起前计数）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Http\RemoteResult;

final class RemoteClientTest extends TestCase {

	/* ---------- 出站红线：check_url ---------- */

	public function test_https_public_host_passes(): void {
		$this->assertNull( RemoteClient::check_url( 'https://captcha.tencentcloudapi.com' ) );
		$this->assertNull( RemoteClient::check_url( 'https://api.deepseek.com/v1/chat' ) );
	}

	public function test_https_is_mandatory(): void {
		// 红线 1：携带密钥的调用强制 https——http 一律拒绝（M1 全部调用均携带密钥）。
		$this->assertNotNull( RemoteClient::check_url( 'http://captcha.tencentcloudapi.com' ) );
	}

	public function test_scheme_whitelist_rejects_ftp_and_file(): void {
		$this->assertNotNull( RemoteClient::check_url( 'ftp://example.com' ) );
		$this->assertNotNull( RemoteClient::check_url( 'file:///etc/passwd' ) );
		$this->assertNotNull( RemoteClient::check_url( 'gopher://example.com' ) );
	}

	public function test_missing_host_is_rejected(): void {
		$this->assertNotNull( RemoteClient::check_url( 'https://' ) );
		$this->assertNotNull( RemoteClient::check_url( 'not a url' ) );
	}

	public function test_localhost_and_internal_hostnames_are_rejected(): void {
		$this->assertNotNull( RemoteClient::check_url( 'https://localhost' ) );
		$this->assertNotNull( RemoteClient::check_url( 'https://localhost:8080' ) );
		$this->assertNotNull( RemoteClient::check_url( 'https://db.internal' ) );
		$this->assertNotNull( RemoteClient::check_url( 'https://gateway.lan.local' ) );
	}

	public function test_private_ipv4_literals_are_rejected(): void {
		foreach ( array(
			'https://10.0.0.5',           // 10/8.
			'https://172.16.1.1',         // 172.16/12.
			'https://192.168.1.1',        // 192.168/16.
			'https://127.0.0.1',          // 环回.
			'https://169.254.169.254',    // 链路本地（云元数据端点）.
			'https://0.0.0.0',            // 未指定地址.
			'https://100.64.0.1',         // CGNAT.
		) as $url ) {
			$this->assertNotNull( RemoteClient::check_url( $url ), "应拒绝：{$url}" );
		}
	}

	public function test_public_ipv4_literal_passes(): void {
		$this->assertNull( RemoteClient::check_url( 'https://8.8.8.8' ) );
	}

	public function test_ipv6_private_literals_are_rejected(): void {
		$this->assertNotNull( RemoteClient::check_url( 'https://[::1]' ) );            // 环回.
		$this->assertNotNull( RemoteClient::check_url( 'https://[fe80::1]' ) );        // 链路本地.
		$this->assertNotNull( RemoteClient::check_url( 'https://[fc00::1]' ) );        // ULA.
		$this->assertNotNull( RemoteClient::check_url( 'https://[::ffff:10.0.0.1]' ) ); // v4 映射反查.
	}

	public function test_ipv6_public_literal_passes(): void {
		$this->assertNull( RemoteClient::check_url( 'https://[2606:4700::6810:84e5]' ) );
	}

	/* ---------- 脱敏：redact ---------- */

	public function test_redact_authorization_header_value(): void {
		$dirty = 'Authorization: Bearer sk-abcdef123456';
		$this->assertSame( 'Authorization: [REDACTED]', RemoteClient::redact( $dirty ) );
	}

	public function test_redact_preserves_authorization_scheme_prefix(): void {
		// TC3 形态：算法名保留，Credential/Signature 值脱敏。
		$dirty = 'Authorization: TC3-HMAC-SHA256 Credential=AKID1234567890/2026-11-09/captcha/tc3_request, SignedHeaders=content-type;host, Signature=10b1a37a7301a02ca19a647ad722d5e4';
		$clean = RemoteClient::redact( $dirty );
		$this->assertStringNotContainsString( 'AKID1234567890', $clean );
		$this->assertStringNotContainsString( '10b1a37a7301a02ca19a647ad722d5e4', $clean );
		$this->assertStringContainsString( 'TC3-HMAC-SHA256', $clean );
	}

	public function test_redact_key_and_secret_assignments(): void {
		$dirty = 'api_key=sk-abc123 secret_key=TopSecret AppSecretKey=E4kwK9AcXQMH';
		$clean = RemoteClient::redact( $dirty );
		$this->assertStringNotContainsString( 'sk-abc123', $clean );
		$this->assertStringNotContainsString( 'TopSecret', $clean );
		$this->assertStringNotContainsString( 'E4kwK9AcXQMH', $clean );
	}

	public function test_redact_json_body_secret_fields(): void {
		$dirty = '{"AppSecretKey":"E4kwK9AcXQMHkktiItyMEyQPn","CaptchaAppId":199999164}';
		$clean = RemoteClient::redact( $dirty );
		$this->assertStringNotContainsString( 'E4kwK9AcXQMHkktiItyMEyQPn', $clean );
		$this->assertStringContainsString( '199999164', $clean ); // 非密钥字段不动。
	}

	public function test_redact_plain_text_without_secrets_untouched(): void {
		$this->assertSame( 'CaptchaCode=1 CaptchaMsg=OK', RemoteClient::redact( 'CaptchaCode=1 CaptchaMsg=OK' ) );
	}

	/* ---------- 重试纪律 ---------- */

	public function test_retry_delay_base_with_fixed_jitter_ratio(): void {
		// 退避基线 300/900ms；jitter_ratio 注入固定值便于确定性断言（±20% 区间线性映射）。
		$this->assertSame( 240, RemoteClient::retry_delay_ms( 1, null, -0.2 ) );
		$this->assertSame( 360, RemoteClient::retry_delay_ms( 1, null, 0.2 ) );
		$this->assertSame( 300, RemoteClient::retry_delay_ms( 1, null, 0.0 ) );
		$this->assertSame( 720, RemoteClient::retry_delay_ms( 2, null, -0.2 ) );
		$this->assertSame( 1080, RemoteClient::retry_delay_ms( 2, null, 0.2 ) );
	}

	public function test_retry_delay_random_jitter_stays_in_bounds(): void {
		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$base = ( 1 === $attempt ) ? 300 : 900;
			for ( $i = 0; $i < 60; $i++ ) {
				$delay = RemoteClient::retry_delay_ms( $attempt, null );
				$this->assertGreaterThanOrEqual( (int) floor( $base * 0.8 ), $delay );
				$this->assertLessThanOrEqual( (int) ceil( $base * 1.2 ), $delay );
			}
		}
	}

	public function test_retry_after_takes_precedence_over_backoff(): void {
		// 尊重 Retry-After（HTTP 语义为秒）：服务端明示 5 秒 → 等待 5000ms。
		$this->assertSame( 5000, RemoteClient::retry_delay_ms( 1, 5 ) );
	}

	public function test_retry_after_is_capped_to_safety_ceiling(): void {
		// 恶意/异常超长 Retry-After（如 3600s）封顶 30s，防拖垮评论提交线程。
		$this->assertSame( 30000, RemoteClient::retry_delay_ms( 1, 3600 ) );
	}

	public function test_should_retry_only_transport_and_within_cap(): void {
		$transport = RemoteResult::classify( array( 'response' => array( 'code' => 503 ), 'body' => '' ) );
		$semantic  = RemoteResult::classify( array( 'response' => array( 'code' => 401 ), 'body' => '' ) );

		$this->assertTrue( RemoteClient::should_retry( $transport, 1 ) );  // 首败可重试.
		$this->assertTrue( RemoteClient::should_retry( $transport, 2 ) );  // 第二次重试仍可.
		$this->assertFalse( RemoteClient::should_retry( $transport, 3 ) ); // 上限 2 次重试（共 3 次尝试）.
		$this->assertFalse( RemoteClient::should_retry( $semantic, 1 ) );  // semantic 不重试.
	}

	public function test_should_retry_never_in_report_mode(): void {
		// report 无重试短超时档（02 §2.3a：上报永不阻塞闸流程；M3 消费）。
		$transport = RemoteResult::classify( array( 'response' => array( 'code' => 500 ), 'body' => '' ) );
		$this->assertFalse( RemoteClient::should_retry( $transport, 1, 'report' ) );
	}

	/* ---------- post()：注入假 transport 的编排 ---------- */

	public function test_post_retries_transport_until_success(): void {
		$calls = array();
		$transport = function ( string $url, array $args ) use ( &$calls ) {
			$calls[] = $args['timeout'];
			$count   = count( $calls );
			if ( $count < 3 ) {
				return array( 'response' => array( 'code' => 503 ), 'body' => '' ); // 前两次 5xx.
			}
			return array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' );
		};
		$sleeps = array();
		$client = new RemoteClient( $transport, null, function ( int $ms ) use ( &$sleeps ): void {
			$sleeps[] = $ms;
		} );

		$result = $client->post( 'https://api.example.com/v1', array( 'body' => '{}' ) );

		$this->assertTrue( $result->is_ok() );
		$this->assertSame( 3, $result->attempts() );
		$this->assertCount( 3, $calls );
		$this->assertCount( 2, $sleeps ); // 两次重试各睡一次：300/900 档.
		$this->assertGreaterThanOrEqual( 240, $sleeps[0] );
		$this->assertLessThanOrEqual( 1080, $sleeps[1] );
	}

	public function test_post_gives_up_after_retry_cap(): void {
		$transport = function () {
			return array( 'response' => array( 'code' => 500 ), 'body' => '' );
		};
		$client = new RemoteClient( $transport, null, function (): void {} );

		$result = $client->post( 'https://api.example.com/v1', array() );

		$this->assertSame( RemoteResult::TRANSPORT, $result->category() );
		$this->assertSame( 3, $result->attempts() ); // 1 + 上限 2 重试.
	}

	public function test_post_does_not_retry_semantic(): void {
		$calls     = 0;
		$transport = function () use ( &$calls ) {
			$calls++;
			return array( 'response' => array( 'code' => 401 ), 'body' => 'unauthorized' );
		};
		$client    = new RemoteClient( $transport, null, function (): void {} );

		$result = $client->post( 'https://api.example.com/v1', array() );

		$this->assertSame( RemoteResult::SEMANTIC, $result->category() );
		$this->assertSame( 1, $result->attempts() );
		$this->assertSame( 1, $calls );
	}

	public function test_post_usage_counter_fires_before_every_attempt(): void {
		// 04 §6：每次调用发起前自增（含失败调用——计费口径按请求计）。
		$usage     = array();
		$transport = function () use ( &$usage ) {
			// 断言计量发生在 transport 调用之前：此刻 usage 必已计入本次。
			return array( 'response' => array( 'code' => 503 ), 'body' => '' );
		};
		$client = new RemoteClient( $transport, function ( string $key ) use ( &$usage ): void {
			$usage[] = $key;
		}, function (): void {} );

		$client->post( 'https://api.example.com/v1', array( 'usage_key' => 'tencent' ) );

		$this->assertSame( array( 'tencent', 'tencent', 'tencent' ), $usage );
	}

	public function test_post_rejects_outbound_red_line_url_without_transporting(): void {
		$called    = false;
		$transport = function () use ( &$called ) {
			$called = true;
			return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
		};
		$client = new RemoteClient( $transport, null, function (): void {} );

		$result = $client->post( 'http://insecure.example.com', array() );          // 非 https.
		$this->assertFalse( $result->is_ok() );
		$this->assertSame( RemoteResult::SEMANTIC, $result->category() ); // 本地快速失败，不重试.
		$this->assertFalse( $called );

		$result = $client->post( 'https://192.168.1.10/api', array() );             // 私有网段.
		$this->assertFalse( $result->is_ok() );
		$this->assertFalse( $called );
	}

	public function test_post_passes_hardened_wp_args_to_transport(): void {
		$captured  = null;
		$transport = function ( string $url, array $args ) use ( &$captured ) {
			$captured = $args;
			return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
		};
		$client = new RemoteClient( $transport, null, function (): void {} );

		$client->post( 'https://api.example.com/v1', array(
			'headers' => array( 'X-TC-Action' => 'DescribeCaptchaResult' ),
			'body'    => '{"a":1}',
			'timeout' => 6,
		) );

		// 红线落地：sslverify 强制 true（不可关）、redirection=0（不跟随重定向）。
		$this->assertTrue( $captured['sslverify'] );
		$this->assertSame( 0, $captured['redirection'] );
		$this->assertSame( 6, $captured['timeout'] );
		$this->assertSame( 'DescribeCaptchaResult', $captured['headers']['X-TC-Action'] );
		$this->assertSame( '{"a":1}', $captured['body'] );
	}

	public function test_post_report_mode_uses_short_timeout_and_no_retry(): void {
		$captured  = array();
		$transport = function ( string $url, array $args ) use ( &$captured ) {
			$captured[] = $args['timeout'];
			return array( 'response' => array( 'code' => 500 ), 'body' => '' );
		};
		$client = new RemoteClient( $transport, null, function (): void {} );

		$result = $client->post( 'https://api.example.com/report', array( 'mode' => 'report' ) );

		$this->assertSame( RemoteResult::TRANSPORT, $result->category() );
		$this->assertSame( array( 3 ), $captured ); // 【P0-9/P0-10·F1】report 超时 2s→3s（幽灵到达实证 2s 窗外仍有受理）；fire-and-forget 零重试语义不变.
	}

	public function test_post_classifies_with_required_and_probe_context(): void {
		$transport = function () {
			// 200 但缺 Response.CaptchaCode → contract。
			return array( 'response' => array( 'code' => 200 ), 'body' => '{"Response":{"RequestId":"r1"}}' );
		};
		$client = new RemoteClient( $transport, null, function (): void {} );

		$result = $client->post( 'https://captcha.tencentcloudapi.com', array(
			'required' => array( 'Response.CaptchaCode' ),
		) );

		$this->assertSame( RemoteResult::CONTRACT, $result->category() );
	}
}
