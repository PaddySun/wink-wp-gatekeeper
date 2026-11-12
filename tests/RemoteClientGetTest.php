<?php
/**
 * RemoteClient::get() 单元测试（I0 批新增 GET 动词——M3-IP 定稿 §5.1/§12-2：
 * 诊断面板四源查询走 RemoteClient standard timeout 3s；check 端点（AbuseIPDB §3.1）
 * 与 StopForumSpam/ip.nc.gy/ip.sb 均为 GET——传输脊此前只有 post()）。
 *
 * 红线不变（与 post 同脊）：https 强制 / 私网拒绝 / redirection=0 / sslverify=true /
 * 重试纪律同模式 / 计量钩子按 usage_key 显式传入才计量（诊断面板调用不带 usage_key → 零统计，定稿红线）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Http\RemoteResult;

final class RemoteClientGetTest extends TestCase {

	public function test_get_rejects_http_without_transport_call(): void {
		$calls     = 0;
		$transport = function () use ( &$calls ) {
			$calls++;
			return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
		};
		$client = new RemoteClient( $transport );

		$result = $client->get( 'http://api.example.com/json' );

		$this->assertSame( RemoteResult::SEMANTIC, $result->category(), '出站红线②：http 一律拒绝' );
		$this->assertStringContainsString( 'https', $result->message() );
		$this->assertSame( 0, $calls, '红线拒绝=本地快速失败，零传输调用' );
	}

	public function test_get_rejects_private_ip_literal(): void {
		$transport = function () {
			return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
		};
		$client = new RemoteClient( $transport );

		$result = $client->get( 'https://192.168.1.1/json' );
		$this->assertSame( RemoteResult::SEMANTIC, $result->category() );
		$this->assertStringContainsString( 'private', $result->message(), 'SSRF 红线③与 post 同脊' );
	}

	public function test_get_passes_url_and_wp_args_to_transport(): void {
		$captured = null;
		$transport = function ( string $url, array $args ) use ( &$captured ) {
			$captured = array( 'url' => $url, 'args' => $args );
			return array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' );
		};
		$client = new RemoteClient( $transport );

		$result = $client->get( 'https://api.ip.sb/geoip/8.8.8.8', array( 'timeout' => 3 ) );

		$this->assertTrue( $result->is_ok() );
		$this->assertSame( 'https://api.ip.sb/geoip/8.8.8.8', $captured['url'] );
		$this->assertSame( 3, $captured['args']['timeout'], '诊断面板 standard 档 timeout 3s 须透传' );
		$this->assertSame( 0, $captured['args']['redirection'], '红线④不跟随重定向' );
		$this->assertTrue( $captured['args']['sslverify'], '红线①强制校验不可关' );
	}

	public function test_get_uses_wp_remote_get_as_default_transport(): void {
		// 默认传输必须是 wp_remote_get（非 post 薄壳）——源码契约（WP 边界不可单测，方法论同 Ui 契约测试）。
		$src = file_get_contents( __DIR__ . '/../includes/http/RemoteClient.php' );
		$this->assertIsString( $src );
		$this->assertStringContainsString( 'wp_remote_get', (string) $src, 'get() 默认传输须走 wp_remote_get' );
	}

	public function test_get_retries_transport_until_success_like_post(): void {
		$calls = array();
		$transport = function ( string $url, array $args ) use ( &$calls ) {
			$calls[] = 1;
			if ( count( $calls ) < 3 ) {
				return array( 'response' => array( 'code' => 503 ), 'body' => '' );
			}
			return array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' );
		};
		$client = new RemoteClient( $transport, null, function (): void {} );

		$result = $client->get( 'https://api.example.com/json', array( 'timeout' => 3 ) );

		$this->assertTrue( $result->is_ok() );
		$this->assertSame( 3, $result->attempts(), 'standard 模式重试纪律与 post 同脊（1+2）' );
	}

	public function test_get_never_retries_in_report_mode(): void {
		$calls = 0;
		$transport = function () use ( &$calls ) {
			$calls++;
			return array( 'response' => array( 'code' => 500 ), 'body' => '' );
		};
		$client = new RemoteClient( $transport, null, function (): void {} );

		$result = $client->get( 'https://api.example.com/json', array( 'mode' => 'report' ) );

		$this->assertSame( 1, $result->attempts(), 'report 模式一次即走' );
		$this->assertSame( RemoteResult::TRANSPORT, $result->category() );
	}

	public function test_get_latency_annotated(): void {
		$transport = static fn( string $url, array $args ) => array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
		$client = new RemoteClient( $transport );

		$result = $client->get( 'https://api.example.com/json' );
		$this->assertNotNull( $result->latency_ms(), '耗时标注与 post 同脊（R1 §6.3）' );
		$this->assertGreaterThanOrEqual( 0, $result->latency_ms() );
	}

	public function test_get_without_usage_key_invokes_no_usage_callback(): void {
		// 定稿红线「零统计」的传输脊落点：诊断面板四源调用不带 usage_key → 计量钩子零回调。
		$bumps = 0;
		$usage = function ( string $key ) use ( &$bumps ): void {
			$bumps++;
		};
		$transport = static fn( string $url, array $args ) => array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
		$client = new RemoteClient( $transport, $usage );

		$client->get( 'https://api.example.com/json' );
		$this->assertSame( 0, $bumps, '不带 usage_key = 零计量' );

		$client->get( 'https://api.example.com/json', array( 'usage_key' => 'abuseipdb' ) );
		$this->assertSame( 1, $bumps, '显式 usage_key 照常计量（含失败调用的口径不变）' );
	}
}
