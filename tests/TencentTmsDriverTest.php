<?php
/**
 * TencentTmsDriver 单元测试（05 §2.4 腾讯 TMS 行实测定稿 + 02 §2.2/§2.5 契约）。
 *
 * 覆盖：
 *   - 判定映射全矩阵：Suggestion Pass/Review/Block × Score 阈值两侧（reject_score 默认 80，
 *     依据 L0 实测 Block=Ad,99；低于阈值/缺分降 review）；
 *   - 送审长度截断（Content 以 Unicode 计量 ≤10,000 字，官方 API 文档 51860）；
 *   - 请求形态（smoke-tencent.php 实测形态：Content=Base64 / BizType / X-TC-Region 公共头不参与签名）；
 *   - error_map：body 错误码（HTTP 200 + Response.Error.Code）归四类（AuthFailure 系→semantic、
 *     限流/内部错误→transport 等），经 RemoteResult::classify probe 通道；
 *   - 传输脊四类终态 → Verdict::error + last_check_meta（闸侧据此停转）；
 *   - registry 六维注册；驱动纯逻辑零 WP 依赖（构造/映射/截断全静态或注入假件）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\DriverRegistry;
use WinkGatekeeper\Drivers\Review\TencentTmsDriver;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Verdict;

final class TencentTmsDriverTest extends TestCase {

	/* ---------- 工具 ---------- */

	private static function client_returning( $raw ): RemoteClient {
		return new RemoteClient( static function () use ( $raw ) {
			return $raw;
		}, null, static function (): void {} );
	}

	private static function ok_body( array $response ): string {
		return (string) json_encode( array( 'Response' => $response ) );
	}

	private static function error_body( string $code, string $message = 'msg' ): string {
		return (string) json_encode( array( 'Response' => array( 'Error' => array( 'Code' => $code, 'Message' => $message ), 'RequestId' => 'req-1' ) ) );
	}

	private static function driver( ?array $tms = null, ?RemoteClient $client = null ): TencentTmsDriver {
		$tms = $tms ?? array( 'secret_id' => 'AKIDx', 'secret_key' => 'sk', 'biz_type' => 'BIZ01' );
		$settings = array( 'review' => array( 'cloud' => array( 'tencent_tms' => $tms ) ) );
		return new TencentTmsDriver(
			$settings,
			$client ?? self::client_returning( array( 'response' => array( 'code' => 500 ), 'body' => '' ) )
		);
	}

	/* ---------- is_configured / 凭据形态 ---------- */

	public function test_is_configured_requires_secret_pair_biz_type_optional(): void {
		$this->assertTrue( self::driver()->is_configured() );
		// BizType 官方可选（不传走默认策略）——不作为已配置门槛；真机建议填写。
		$this->assertTrue( self::driver( array( 'secret_id' => 'AKIDx', 'secret_key' => 'sk', 'biz_type' => '' ) )->is_configured() );
		$this->assertFalse( self::driver( array( 'secret_id' => '', 'secret_key' => 'sk', 'biz_type' => 'B' ) )->is_configured() );
		$this->assertFalse( self::driver( array( 'secret_id' => 'AKIDx', 'secret_key' => '', 'biz_type' => 'B' ) )->is_configured() );
	}

	public function test_credentials_trimmed_like_captcha_driver(): void {
		// C1 真机踩坑（env 通道 CRLF 残留 → AuthFailure.SignatureFailure）同款防御。
		$driver = self::driver( array( 'secret_id' => "AKIDx\r\n", 'secret_key' => " sk \r", 'biz_type' => " BIZ01 \n" ) );
		$this->assertTrue( $driver->is_configured() );
	}

	/* ---------- 纯逻辑：截断（Content ≤10,000 Unicode 字符） ---------- */

	public function test_truncate_under_limit_is_noop(): void {
		$out = TencentTmsDriver::truncate_content( str_repeat( '好', 9999 ) );
		$this->assertFalse( $out['truncated'] );
		$this->assertSame( 9999, $out['original_chars'] );
		$this->assertSame( 9999, mb_strlen( $out['content'], 'UTF-8' ) );
	}

	public function test_truncate_over_limit_cuts_to_10000_chars(): void {
		$out = TencentTmsDriver::truncate_content( str_repeat( '好', 10001 ) );
		$this->assertTrue( $out['truncated'] );
		$this->assertSame( 10001, $out['original_chars'] );
		$this->assertSame( 10000, mb_strlen( $out['content'], 'UTF-8' ) );
	}

	/* ---------- 纯逻辑：payload 形态 ---------- */

	public function test_build_payload_base64_content_with_biz_type(): void {
		$payload = TencentTmsDriver::build_payload( '你好', 'BIZ01' );
		$decoded = json_decode( $payload, true );
		$this->assertSame( base64_encode( '你好' ), $decoded['Content'] );
		$this->assertSame( 'BIZ01', $decoded['BizType'] );
	}

	public function test_build_payload_omits_empty_biz_type(): void {
		$decoded = json_decode( TencentTmsDriver::build_payload( 'hi', '' ), true );
		$this->assertArrayNotHasKey( 'BizType', $decoded );
		$this->assertSame( base64_encode( 'hi' ), $decoded['Content'] );
	}

	/* ---------- check()：请求形态（smoke-tencent.php 实测形态） ---------- */

	public function test_check_builds_text_moderation_request_with_region_header(): void {
		$captured = null;
		$client  = new RemoteClient( static function ( string $url, array $args ) use ( &$captured ) {
			$captured = array( $url, $args );
			return array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'Suggestion' => 'Pass', 'Label' => 'Normal', 'Score' => 0, 'RequestId' => 'r1' ) ) );
		}, null, static function (): void {} );

		$verdict = self::driver( null, $client )->check( "昵称\n邮箱\n正文", array( 'author' => '昵称' ) );

		$this->assertSame( Verdict::PASS, $verdict->verdict() );
		list( $url, $args ) = $captured;
		$this->assertSame( 'https://tms.tencentcloudapi.com/', $url );
		$this->assertSame( 'TextModeration', $args['headers']['X-TC-Action'] );
		$this->assertSame( '2020-12-29', $args['headers']['X-TC-Version'] );
		$this->assertSame( 'tms.tencentcloudapi.com', $args['headers']['Host'] );
		// TextModeration 为地域型服务：X-TC-Region 公共头必填（L0 实测 ap-guangzhou 可用；公共参数不参与 TC3 签名）。
		$this->assertSame( 'ap-guangzhou', $args['headers']['X-TC-Region'] );
		$this->assertStringContainsString( 'TC3-HMAC-SHA256 ', $args['headers']['Authorization'] );

		$body = json_decode( $args['body'], true );
		$this->assertSame( base64_encode( "昵称\n邮箱\n正文" ), $body['Content'] );
		$this->assertSame( 'BIZ01', $body['BizType'] );
	}

	public function test_check_unconfigured_returns_error_without_remote_call(): void {
		$called = false;
		$client = new RemoteClient( static function () use ( &$called ) {
			$called = true;
			return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
		}, null, static function (): void {} );

		$verdict = self::driver( array( 'secret_id' => '', 'secret_key' => '', 'biz_type' => '' ), $client )->check( 'x', array() );

		$this->assertSame( Verdict::ERROR, $verdict->verdict() );
		$this->assertSame( 'not_configured', $verdict->reason() );
		$this->assertFalse( $called );
	}

	/* ---------- 判定映射全矩阵（Suggestion × Score 阈值两侧） ---------- */

	public function test_pass_maps_to_pass_with_label_hits(): void {
		$driver  = self::driver( null, self::client_returning( array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'Suggestion' => 'Pass', 'Label' => 'Normal', 'Score' => 0 ) ) ) ) );
		$verdict = $driver->check( '这篇文章写得真好', array() );
		$this->assertSame( Verdict::PASS, $verdict->verdict() );
		$this->assertSame( 'Normal,0', $verdict->reason() ); // reason = Label,Score（L0 实测格式）。
		$this->assertSame( array( 'Normal' ), $verdict->hits() );
	}

	public function test_review_maps_to_review(): void {
		$driver  = self::driver( null, self::client_returning( array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'Suggestion' => 'Review', 'Label' => 'Ad', 'Score' => 60 ) ) ) ) );
		$verdict = $driver->check( '灰区文本', array() );
		$this->assertSame( Verdict::REVIEW, $verdict->verdict() );
		$this->assertSame( 'Ad,60', $verdict->reason() );
		$this->assertSame( array( 'Ad' ), $verdict->hits() );
	}

	public function test_block_high_score_rejects(): void {
		// L0 实测：广告词 → Block + Ad + 99 分——高于默认阈值 80 → 直拒。
		$driver  = self::driver( null, self::client_returning( array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'Suggestion' => 'Block', 'Label' => 'Ad', 'Score' => 99 ) ) ) ) );
		$verdict = $driver->check( '广告文本', array() );
		$this->assertSame( Verdict::REJECT, $verdict->verdict() );
		$this->assertSame( 'Ad,99', $verdict->reason() );
	}

	public function test_block_at_threshold_boundary_rejects(): void {
		// 阈值语义 = Score >= reject_score 直拒（80 恰好命中）。
		$driver  = self::driver( null, self::client_returning( array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'Suggestion' => 'Block', 'Label' => 'Ad', 'Score' => 80 ) ) ) ) );
		$this->assertSame( Verdict::REJECT, $driver->check( 'x', array() )->verdict() );
	}

	public function test_block_below_threshold_downgrades_to_review(): void {
		$driver  = self::driver( null, self::client_returning( array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'Suggestion' => 'Block', 'Label' => 'Ad', 'Score' => 79 ) ) ) ) );
		$verdict = $driver->check( 'x', array() );
		$this->assertSame( Verdict::REVIEW, $verdict->verdict() );
		$this->assertSame( 'Ad,79', $verdict->reason() );
		$this->assertTrue( $driver->last_check_meta()['below_reject_score'] );
	}

	public function test_block_missing_score_downgrades_to_review(): void {
		// Score 缺失（接口形态漂移）→ 无法证高置信 → 降 review（人工兜底），meta 标注。
		$driver  = self::driver( null, self::client_returning( array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'Suggestion' => 'Block', 'Label' => 'Ad' ) ) ) ) );
		$verdict = $driver->check( 'x', array() );
		$this->assertSame( Verdict::REVIEW, $verdict->verdict() );
		$this->assertSame( 'Ad', $verdict->reason() );
	}

	public function test_reject_score_threshold_is_configurable(): void {
		$tms     = array( 'secret_id' => 'AKIDx', 'secret_key' => 'sk', 'biz_type' => 'B', 'reject_score' => 95 );
		$driver  = self::driver( $tms, self::client_returning( array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'Suggestion' => 'Block', 'Label' => 'Ad', 'Score' => 90 ) ) ) ) );
		$this->assertSame( Verdict::REVIEW, $driver->check( 'x', array() )->verdict() );
	}

	public function test_unknown_suggestion_fails_safe_to_review(): void {
		$driver  = self::driver( null, self::client_returning( array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'Suggestion' => 'Foo', 'Label' => 'Normal', 'Score' => 0 ) ) ) ) );
		$verdict = $driver->check( 'x', array() );
		$this->assertSame( Verdict::REVIEW, $verdict->verdict() );
		$this->assertStringContainsString( 'unknown_suggestion', $verdict->reason() );
	}

	public function test_truncation_recorded_into_meta(): void {
		$driver  = self::driver( null, self::client_returning( array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'Suggestion' => 'Pass', 'Label' => 'Normal', 'Score' => 0 ) ) ) ) );
		$driver->check( str_repeat( '字', 10050 ), array() );
		$meta = $driver->last_check_meta();
		$this->assertTrue( $meta['truncated'] );
		$this->assertSame( 10050, $meta['original_chars'] );
	}

	public function test_meta_carries_label_score_and_threshold(): void {
		$driver = self::driver( null, self::client_returning( array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'Suggestion' => 'Block', 'Label' => 'Ad', 'Score' => 99 ) ) ) ) );
		$driver->check( 'x', array() );
		$meta = $driver->last_check_meta();
		$this->assertSame( 'Block', $meta['Suggestion'] );
		$this->assertSame( 'Ad', $meta['Label'] );
		$this->assertSame( 99, $meta['Score'] );
		$this->assertSame( 80, $meta['reject_score'] );
	}

	/* ---------- error_map：body 错误码归四类（probe 纯函数矩阵） ---------- */

	public function test_inspect_body_auth_failure_maps_semantic(): void {
		foreach ( array( 'AuthFailure.SignatureFailure', 'AuthFailure.SecretIdNotFound', 'AuthFailure.InvalidSecretId' ) as $code ) {
			$decoded = json_decode( self::error_body( $code ), true );
			$this->assertSame( RemoteResult::SEMANTIC, TencentTmsDriver::inspect_body( $decoded, '' ), "code {$code} 应归 semantic（配置错误，当日停转）" );
		}
	}

	public function test_inspect_body_unauthorized_and_parameter_map_semantic(): void {
		foreach ( array( 'UnauthorizedOperation.Unauthorized', 'MissingParameter', 'InvalidParameterValue.ErrTextContentLen', 'InvalidParameter.ErrTextContentType' ) as $code ) {
			$decoded = json_decode( self::error_body( $code ), true );
			$this->assertSame( RemoteResult::SEMANTIC, TencentTmsDriver::inspect_body( $decoded, '' ), "code {$code} 应归 semantic" );
		}
	}

	public function test_inspect_body_rate_limit_and_internal_map_transport(): void {
		foreach ( array( 'RequestLimitExceeded', 'RequestLimitExceeded.UinLimitExceeded', 'LimitExceeded', 'InternalError.ErrTextTimeOut', 'ServiceUnavailable' ) as $code ) {
			$decoded = json_decode( self::error_body( $code ), true );
			$this->assertSame( RemoteResult::TRANSPORT, TencentTmsDriver::inspect_body( $decoded, '' ), "code {$code} 应归 transport（可重试）" );
		}
	}

	public function test_inspect_body_unknown_or_clean_returns_null(): void {
		$this->assertNull( TencentTmsDriver::inspect_body( json_decode( self::error_body( 'BrandNewCode' ), true ), '' ) );
		$this->assertNull( TencentTmsDriver::inspect_body( json_decode( self::ok_body( array( 'Suggestion' => 'Pass' ) ), true ), '' ) );
		$this->assertNull( TencentTmsDriver::inspect_body( null, 'not json' ) );
		$this->assertNull( TencentTmsDriver::inspect_body( array( 'nope' => 1 ), '' ) );
	}

	/* ---------- 传输脊四类终态 → Verdict::error（闸侧 failover/停转消费） ---------- */

	public function test_body_error_code_flows_through_classify_as_semantic(): void {
		// HTTP 200 + Response.Error.Code=AuthFailure.* → probe 归 semantic（TMS 业务码在 2xx body 中，02 §2.5）。
		$driver  = self::driver( null, self::client_returning( array( 'response' => array( 'code' => 200 ), 'body' => self::error_body( 'AuthFailure.SignatureFailure', 'signature mismatch' ) ) ) );
		$verdict = $driver->check( 'x', array() );
		$this->assertSame( Verdict::ERROR, $verdict->verdict() );
		$this->assertSame( 'remote_semantic', $verdict->reason() );
		$this->assertSame( RemoteResult::SEMANTIC, $driver->last_check_meta()['category'] );
	}

	public function test_transport_terminal_maps_error(): void {
		$driver  = self::driver( null, self::client_returning( array( 'response' => array( 'code' => 503 ), 'body' => '' ) ) );
		$verdict = $driver->check( 'x', array() );
		$this->assertSame( Verdict::ERROR, $verdict->verdict() );
		$this->assertSame( 'remote_transport', $verdict->reason() );
	}

	public function test_missing_suggestion_maps_contract(): void {
		// 2xx 无 Error 也无 Suggestion → required 契约哨兵（contract_mismatch，R6/R9 厂商改版）。
		$driver  = self::driver( null, self::client_returning( array( 'response' => array( 'code' => 200 ), 'body' => '{"Response":{"RequestId":"r1"}}' ) ) );
		$verdict = $driver->check( 'x', array() );
		$this->assertSame( Verdict::ERROR, $verdict->verdict() );
		$this->assertSame( 'remote_contract', $verdict->reason() );
	}

	public function test_bad_json_maps_parse(): void {
		$driver  = self::driver( null, self::client_returning( array( 'response' => array( 'code' => 200 ), 'body' => 'not-json' ) ) );
		$verdict = $driver->check( 'x', array() );
		$this->assertSame( Verdict::ERROR, $verdict->verdict() );
		$this->assertSame( 'remote_parse', $verdict->reason() );
	}

	/* ---------- registry 六维（05 §4） ---------- */

	public function test_registry_entry_registers_with_six_dimensions(): void {
		$registry = new DriverRegistry();
		$registry->register( TencentTmsDriver::registry_entry() );

		$entry = $registry->get( 'tencent_tms' );
		$this->assertNotNull( $entry );
		$this->assertSame( 'review', $entry['type'] );
		$this->assertSame( TencentTmsDriver::class, $entry['class'] );
		$this->assertSame( 'usage', $entry['billing'] ); // 套餐包按量（05 §5 矩阵口径归 usage）。
		$this->assertSame( array(), $entry['frontend'] ); // review 类无前端形态。
		$this->assertSame( 'tms-2020-12-29', $entry['api_version'] ); // 实测端点版本（L0 冒烟）。
		$this->assertSame( 'inspect_body', $entry['error_map']['body_probe'] );
		$this->assertArrayHasKey( 'secret_id', $entry['settings'] );
		$this->assertArrayHasKey( 'secret_key', $entry['settings'] );
		$this->assertArrayHasKey( 'biz_type', $entry['settings'] );
	}

	/* ---------- 凭据探测（Tab③ 测试凭据：正常文本真调用） ---------- */

	public function test_probe_credentials_sends_benign_text_without_usage_key(): void {
		$captured = null;
		$client   = new RemoteClient( static function ( string $url, array $args ) use ( &$captured ) {
			$captured = array( $url, $args );
			return array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'Suggestion' => 'Pass', 'Label' => 'Normal', 'Score' => 0 ) ) );
		}, null, static function (): void {} );

		$result = self::driver( null, $client )->probe_credentials();

		$this->assertTrue( $result->is_ok() );
		list( , $args ) = $captured;
		$body = json_decode( $args['body'], true );
		$this->assertSame( base64_encode( '你好，这篇文章写得真好' ), $body['Content'] );
	}
}
