<?php
/**
 * 错误可观测性：驱动 error 分支 meta.raw + rule_hit 补齐测试（04 §3 v1.4·R4 §D.1；F44）。
 *
 * 覆盖：
 *   - TMS check() error 四类终态：last_meta 携 raw 快照 + Verdict hits=['remote_<category>']
 *     （EventsTable::insert 按 hits 落 rule_hit——idx_gate_driver 可按类别索引筛选）；
 *   - 错 SecretKey 场景（AuthFailure.SignatureFailure 401）：raw.code 原文可读（R1 §6-3 测试要点）；
 *   - 天御 consume_result error 分支：last_meta 携 raw、rule=remote_<category>（reject 形态）；
 *   - stats.raw_capture=false：raw 省略、其余错误字段照记。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\captcha\TencentCaptchaDriver;
use WinkGatekeeper\Drivers\Review\TencentTmsDriver;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Verdict;

final class RemoteErrorObservabilityTest extends TestCase {

	/* ---------- TMS ---------- */

	private static function tms_with_raw( $raw, array $tms_overrides = array() ): TencentTmsDriver {
		$client = new RemoteClient( static function () use ( $raw ) {
			return $raw;
		}, null, static function (): void {} );

		$tms = array_merge( array( 'secret_id' => 'AKIDx', 'secret_key' => 'sk', 'biz_type' => 'BIZ' ), $tms_overrides );
		return new TencentTmsDriver( array( 'review' => array( 'cloud' => array( 'tencent_tms' => $tms ) ) ), $client );
	}

	public function test_tms_semantic_error_carries_raw_with_vendor_code_and_hits(): void {
		$body = (string) json_encode( array( 'Response' => array(
			'Error'     => array( 'Code' => 'AuthFailure.SignatureFailure', 'Message' => 'signature verify failed' ),
			'RequestId' => 'req-tms-1',
		) ) );
		$driver  = self::tms_with_raw( array( 'response' => array( 'code' => 401 ), 'body' => $body ) );
		$verdict = $driver->check( '正文', array() );
		$meta    = $driver->last_check_meta();

		$this->assertSame( Verdict::ERROR, $verdict->verdict() );
		$this->assertSame( array( 'remote_semantic' ), $verdict->hits(), '错误事件 rule_hit=remote_<category> 补齐（可索引筛选）' );
		$this->assertSame( 'remote_semantic', $verdict->reason() );
		$this->assertSame( 'semantic', $meta['category'] );
		$this->assertIsArray( $meta['raw'], 'error 分支一行合并进 last_meta[raw]（闸侧透传落库）' );
		$this->assertSame( 'AuthFailure.SignatureFailure', $meta['raw']['code'], '错 SecretKey → 事件含 AuthFailure.SignatureFailure 原文可读' );
		$this->assertSame( 401, $meta['raw']['http'] );
		$this->assertSame( 'req-tms-1', $meta['raw']['request_id'] );
	}

	public function test_tms_transport_error_carries_raw_with_attempts(): void {
		// 5xx 触发退避重试（sleeper 注入零等待）→ attempts=3。
		$driver  = self::tms_with_raw( array( 'response' => array( 'code' => 503 ), 'body' => '' ) );
		$verdict = $driver->check( '正文', array() );
		$meta    = $driver->last_check_meta();

		$this->assertSame( Verdict::ERROR, $verdict->verdict() );
		$this->assertSame( array( 'remote_transport' ), $verdict->hits() );
		$this->assertSame( 3, $meta['raw']['attempts'], '重试穷尽 attempts=3 可见（R1 G4 测试要点）' );
		$this->assertSame( 503, $meta['raw']['http'] );
	}

	public function test_tms_error_omits_raw_when_capture_disabled(): void {
		$body = (string) json_encode( array( 'Response' => array( 'Error' => array( 'Code' => 'AuthFailure' ) ) ) );
		$client = new RemoteClient( static function () use ( $body ) {
			return array( 'response' => array( 'code' => 401 ), 'body' => $body );
		}, null, static function (): void {} );
		$driver = new TencentTmsDriver( array(
			'review' => array( 'cloud' => array( 'tencent_tms' => array( 'secret_id' => 'AKIDx', 'secret_key' => 'sk' ) ) ),
			'stats'  => array( 'raw_capture' => false ),
		), $client );

		$verdict = $driver->check( '正文', array() );
		$meta    = $driver->last_check_meta();

		$this->assertSame( Verdict::ERROR, $verdict->verdict() );
		$this->assertSame( 'semantic', $meta['category'], '关=raw 字段省略、错误事件其余字段照记' );
		$this->assertArrayNotHasKey( 'raw', $meta );
		$this->assertSame( array( 'remote_semantic' ), $verdict->hits() );
	}

	/* ---------- 天御 captcha ---------- */

	public function test_captcha_transport_error_carries_raw_in_last_verify_meta(): void {
		$client = new RemoteClient( static function () {
			return array( 'response' => array( 'code' => 500 ), 'body' => '' );
		}, null, static function (): void {} );
		$settings = array(
			'captcha' => array( 'tencent' => array( 'app_id' => 190000000, 'secret_id' => 'AKIDx', 'secret_key' => 'sk', 'app_secret_key' => 'ask' ) ),
			'copy'    => array( 'messages' => array() ),
		);
		$driver = new TencentCaptchaDriver( $settings, $client );
		$error  = $driver->verify( array( 'ticket' => 't', 'randstr' => 'r', 'user_ip' => '1.2.3.4' ) );
		$meta   = $driver->last_verify_meta();

		$this->assertNotNull( $error, 'captcha 远端终态故障即拦（02 §5）' );
		$this->assertSame( 'remote_transport', $meta['rule'] );
		$this->assertIsArray( $meta['raw'] );
		$this->assertSame( 'transport', $meta['raw']['category'] );
		$this->assertGreaterThanOrEqual( 1, $meta['raw']['attempts'] );
	}

	public function test_captcha_semantic_error_rule_and_raw(): void {
		$body = (string) json_encode( array( 'Response' => array( 'Error' => array( 'Code' => 'AuthFailure.SignatureFailure' ), 'RequestId' => 'req-cap-2' ) ) );
		$client = new RemoteClient( static function () use ( $body ) {
			return array( 'response' => array( 'code' => 401 ), 'body' => $body );
		}, null, static function (): void {} );
		$settings = array(
			'captcha' => array( 'tencent' => array( 'app_id' => 190000000, 'secret_id' => 'AKIDx', 'secret_key' => 'sk', 'app_secret_key' => 'ask' ) ),
			'copy'    => array( 'messages' => array() ),
		);
		$driver = new TencentCaptchaDriver( $settings, $client );
		$driver->verify( array( 'ticket' => 't', 'randstr' => 'r', 'user_ip' => '1.2.3.4' ) );
		$meta = $driver->last_verify_meta();

		$this->assertSame( 'remote_semantic', $meta['rule'] );
		$this->assertSame( 'AuthFailure.SignatureFailure', $meta['raw']['code'] );
	}

	/* ---------- 停转留痕（gate=system / stage=health，纯数据面在 DriverHealth 留痕行） ---------- */

	public function test_fault_predicate_accepts_driver_halted_rows_and_rejects_resumed(): void {
		// 停转进入事件 rule_hit=driver_halted:<state>（02 §5 v1.4）——归入故障口径；
		// 解除事件 rule_hit=driver_resumed（verdict=pass 特例）——非故障。
		$this->assertTrue( \WinkGatekeeper\Stats\EventsTable::is_fault( 'error', 'driver_halted:config_error' ) );
		$this->assertFalse( \WinkGatekeeper\Stats\EventsTable::is_fault( 'pass', 'driver_resumed' ) );
	}
}
