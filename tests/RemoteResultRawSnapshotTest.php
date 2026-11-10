<?php
/**
 * RemoteResult::raw_snapshot 单元测试（02 §2.5 v1.4 传输脊第 5 件；04 §3 meta.raw 脱敏规则表）。
 *
 * 覆盖：
 *   - 字段集：category/http/code/message/body/attempts/retry_after/request_id/latency_ms；
 *   - 响应侧 only（结构性：请求体/请求头物理上不可达——本测试断言字段集内不含任何请求侧键）；
 *   - redact 复用：body/message 中 Authorization/TC3/secret 系键值 → [REDACTED]（红线 4 延伸，
 *     CI 扫描面=events.meta——密钥形态不得出现在 raw）；
 *   - 字节截断：body/message 各截 max_bytes，超长追加 …[truncated]；钳 128–2048；
 *   - 四类终态全覆盖：transport（http=0 连接失败 body 空）/ semantic（401）/ contract / parse；
 *   - code 启发式提取：Response.Error.Code / error.code / error 常见位；
 *   - request_id 提取（腾讯系 Response.RequestId）；
 *   - raw_snapshot_from_settings：stats.raw_capture=false → null；max_bytes 传递。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Http\RemoteResult;

final class RemoteResultRawSnapshotTest extends TestCase {

	private static function http_result( int $code, string $body, array $headers = array() ): RemoteResult {
		return RemoteResult::classify( array(
			'response' => array( 'code' => $code ),
			'body'     => $body,
			'headers'  => $headers,
		) );
	}

	/* ---------- 字段集（04 §3 meta.raw 脱敏规则表第一行） ---------- */

	public function test_snapshot_field_set_on_semantic_401(): void {
		$body = (string) json_encode( array( 'Response' => array(
			'Error'     => array( 'Code' => 'AuthFailure.SignatureFailure', 'Message' => 'signature verify failed' ),
			'RequestId' => 'req-abc-123',
		) ) );
		$result   = self::http_result( 401, $body )->with_attempts( 1 )->with_latency_ms( 312 );
		$snapshot = $result->raw_snapshot( 512 );

		$this->assertSame( 'semantic', $snapshot['category'] );
		$this->assertSame( 401, $snapshot['http'] );
		$this->assertSame( 'AuthFailure.SignatureFailure', $snapshot['code'], '厂商错误码原文可读（R1 §6-3 测试要点）' );
		$this->assertStringContainsString( 'signature verify failed', $snapshot['message'] );
		$this->assertSame( 1, $snapshot['attempts'] );
		$this->assertSame( 'req-abc-123', $snapshot['request_id'], '腾讯系 RequestId 便于向厂商报障' );
		$this->assertSame( 312, $snapshot['latency_ms'], 'R1 §6.3 增补：latency_ms 并入单一来源快照' );
		$this->assertStringContainsString( 'AuthFailure.SignatureFailure', $snapshot['body'] );
		// retry_after：semantic 类无意义——null（encode_meta 落库时剔除 null 键）。
		$this->assertNull( $snapshot['retry_after'] );
	}

	/* ---------- 响应侧 only（结构性保证） ---------- */

	public function test_snapshot_contains_only_response_side_fields(): void {
		$result   = self::http_result( 500, 'oops' )->with_attempts( 3 );
		$snapshot = $result->raw_snapshot( 512 );

		// 字段集封闭：请求体/请求头/Authorization 等请求侧键不得出现（快照函数物理上拿不到请求）。
		$this->assertSame(
			array( 'category', 'http', 'code', 'message', 'body', 'attempts', 'retry_after', 'request_id', 'latency_ms' ),
			array_keys( $snapshot ),
			'字段集必须封闭为 04 §3 表定义的响应侧九键'
		);
	}

	/* ---------- transport：连接失败 http=0 仍带最小面 ---------- */

	public function test_transport_connection_failure_still_carries_minimal_snapshot(): void {
		$result   = RemoteResult::classify( false )->with_attempts( 3 )->with_latency_ms( 24000 );
		$snapshot = $result->raw_snapshot( 512 );

		$this->assertSame( 'transport', $snapshot['category'] );
		$this->assertSame( 0, $snapshot['http'], '连接失败未抵达服务器=0' );
		$this->assertSame( 3, $snapshot['attempts'], '重试穷尽 attempts 本身即「网络问题」证据（G4）' );
		$this->assertSame( '', $snapshot['body'] );
		$this->assertSame( 24000, $snapshot['latency_ms'] );
		$this->assertSame( '', $snapshot['code'], '无 body → code 空' );
	}

	/* ---------- redact 复用（红线 4 延伸：CI 扫描面=events.meta） ---------- */

	public function test_redact_strips_secret_like_values_from_body_and_message(): void {
		$body = '{"Response":{"Error":{"Code":"AuthFailure","Message":"denied Authorization: Bearer sk-live-9f8e7d6c5b4a"},"secret_key":"ABCD1234EFGH5678","RequestId":"r9"}}';
		$snapshot = self::http_result( 401, $body )->raw_snapshot( 512 );

		foreach ( array( $snapshot['body'], $snapshot['message'] ) as $text ) {
			$this->assertStringNotContainsString( 'sk-live-9f8e7d6c5b4a', $text, 'Authorization 值不得出现在 raw（密钥红线）' );
			$this->assertStringNotContainsString( 'ABCD1234EFGH5678', $text, 'secret_key 值不得出现在 raw（密钥红线）' );
		}
		$this->assertStringContainsString( '[REDACTED]', $snapshot['body'] );
	}

	public function test_redact_strips_tc3_credential_and_signature_values(): void {
		$body = '{"error":"Authorization=TC3-HMAC-SHA256 Credential=AKIDzzy9x/2026/tx/service, SignedHeaders=x, Signature=0123456789abcdef0123"}';
		$snapshot = self::http_result( 400, $body )->raw_snapshot( 512 );

		$this->assertStringNotContainsString( 'AKIDzzy9x', $snapshot['body'] );
		$this->assertStringNotContainsString( '0123456789abcdef0123', $snapshot['body'] );
	}

	/* ---------- 截断 ---------- */

	public function test_body_and_message_truncated_with_marker(): void {
		$long = str_repeat( 'A', 600 );
		$snapshot = self::http_result( 500, $long )->raw_snapshot( 128 );

		$this->assertLessThanOrEqual( 128 + strlen( '…[truncated]' ), strlen( $snapshot['body'] ) );
		$this->assertStringEndsWith( '…[truncated]', $snapshot['body'] );
	}

	public function test_max_bytes_clamped_to_128_2048(): void {
		$snapshot = self::http_result( 500, str_repeat( 'A', 5000 ) )->raw_snapshot( 8 );
		$this->assertSame( 128, strlen( $snapshot['body'] ) - strlen( '…[truncated]' ), '下限 128（04 §3 截断/体量行）' );

		$snapshot = self::http_result( 500, str_repeat( 'A', 5000 ) )->raw_snapshot( 99999 );
		$this->assertSame( 2048, strlen( $snapshot['body'] ) - strlen( '…[truncated]' ), '上限 2048' );
	}

	public function test_short_body_untruncated(): void {
		$snapshot = self::http_result( 500, 'short' )->raw_snapshot( 512 );
		$this->assertSame( 'short', $snapshot['body'] );
		$this->assertStringNotContainsString( 'truncated', $snapshot['body'] );
	}

	/* ---------- 四类终态全覆盖 ---------- */

	public function test_all_four_categories_carry_snapshot(): void {
		// transport（HTTP 层）。
		$this->assertSame( 'transport', self::http_result( 503, 'x' )->raw_snapshot( 128 )['category'] );
		// semantic。
		$this->assertSame( 'semantic', self::http_result( 402, 'x' )->raw_snapshot( 128 )['category'] );
		// contract：2xx 缺关键字段（required 路径）。
		$contract = RemoteResult::classify(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"Response":{}}' ),
			array( 'required' => array( 'Response.Suggestion' ) )
		);
		$this->assertSame( 'contract', $contract->raw_snapshot( 128 )['category'] );
		// parse：2xx 坏 JSON。
		$this->assertSame( 'parse', self::http_result( 200, 'not json' )->raw_snapshot( 128 )['category'] );
	}

	/* ---------- code 启发式提取 ---------- */

	public function test_code_extracted_from_common_error_positions(): void {
		// 形态 1：Response.Error.Code（腾讯系）。
		$s = RemoteResult::classify( array( 'response' => array( 'code' => 200 ), 'body' => '{"Response":{"Error":{"Code":"InternalError"}}}' ),
			array( 'probe' => static function () { return RemoteResult::TRANSPORT; } ) )->raw_snapshot( 512 );
		$this->assertSame( 'InternalError', $s['code'] );

		// 形态 2：error.code（OpenAI 系）。
		$s = self::http_result( 401, '{"error":{"code":"invalid_api_key","message":"bad key"}}' )->raw_snapshot( 512 );
		$this->assertSame( 'invalid_api_key', $s['code'] );

		// 形态 3：error 为字符串。
		$s = self::http_result( 400, '{"error":"bad_request_x"}' )->raw_snapshot( 512 );
		$this->assertSame( 'bad_request_x', $s['code'] );

		// 无 error 结构 → 空串。
		$s = self::http_result( 500, 'plain text' )->raw_snapshot( 512 );
		$this->assertSame( '', $s['code'] );
	}

	public function test_request_id_extracted_from_response(): void {
		$s = self::http_result( 500, '{"Response":{"Error":{"Code":"X"},"RequestId":"rid-77"}}' )->raw_snapshot( 512 );
		$this->assertSame( 'rid-77', $s['request_id'] );
	}

	public function test_retry_after_recorded_for_transport(): void {
		$result = RemoteResult::classify( array(
			'response' => array( 'code' => 429 ),
			'body'     => '',
			'headers'  => array( 'retry-after' => '7' ),
		) );
		$this->assertSame( 7, $result->raw_snapshot( 512 )['retry_after'] );
	}

	/* ---------- settings 桥（stats.raw_capture / raw_max_bytes） ---------- */

	public function test_from_settings_returns_null_when_capture_disabled(): void {
		$result = self::http_result( 500, 'x' );
		$this->assertNull( RemoteResult::raw_snapshot_from_settings( $result, array( 'stats' => array( 'raw_capture' => false ) ) ), '开关关=raw 字段省略、错误事件其余字段照记' );
	}

	public function test_from_settings_defaults_to_capture_on_with_512(): void {
		$result = self::http_result( 500, str_repeat( 'A', 600 ) );
		$snapshot = RemoteResult::raw_snapshot_from_settings( $result, array() );
		$this->assertNotNull( $snapshot );
		$this->assertSame( 512, strlen( $snapshot['body'] ) - strlen( '…[truncated]' ), '默认 512B' );
	}

	public function test_from_settings_honors_max_bytes(): void {
		$result = self::http_result( 500, str_repeat( 'A', 600 ) );
		$snapshot = RemoteResult::raw_snapshot_from_settings( $result, array( 'stats' => array( 'raw_capture' => true, 'raw_max_bytes' => 200 ) ) );
		$this->assertSame( 200, strlen( $snapshot['body'] ) - strlen( '…[truncated]' ) );
	}
}
