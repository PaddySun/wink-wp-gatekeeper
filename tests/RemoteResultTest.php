<?php
/**
 * RemoteResult 错误分类单元测试（02 §2.5 四类错误分类学）。
 *
 * 规格：传输脊错误分类为纯函数 classify($raw)——
 *   transport（5xx/429/超时/连接失败，可重试）| semantic（401/402/400 及其它 4xx，不重试）
 *   | contract（2xx 但结构缺失，疑似厂商改版）| parse（2xx 但业务解析失败，兜底）。
 * 判定优先级：传输脊 HTTP 分类 > 驱动 body 映射（probe）> 兜底 ParseError。
 * $raw 为 WP HTTP API 返回形态（数组）或失败指示（WP_Error 对象/标量→非数组即传输失败）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Http\RemoteResult;

final class RemoteResultTest extends TestCase {

	/* ---------- 工厂 ---------- */

	private static function response( int $code, string $body = '', array $headers = array() ): array {
		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array( 'code' => $code, 'message' => 'status' ),
		);
	}

	/* ---------- transport 类（可重试） ---------- */

	public function test_wp_error_like_object_is_transport(): void {
		$result = RemoteResult::classify( new \stdClass() ); // 非 WP 环境以 stdClass 模拟 WP_Error 形态（非数组=传输失败）。
		$this->assertFalse( $result->is_ok() );
		$this->assertSame( RemoteResult::TRANSPORT, $result->category() );
		$this->assertTrue( $result->retryable() );
		$this->assertSame( 0, $result->status() );
	}

	public function test_false_and_string_raw_are_transport(): void {
		foreach ( array( false, 'http_request_timeout' ) as $raw ) {
			$result = RemoteResult::classify( $raw );
			$this->assertSame( RemoteResult::TRANSPORT, $result->category(), '应为 transport：' . var_export( $raw, true ) );
		}
	}

	public function test_5xx_is_transport_retryable(): void {
		foreach ( array( 500, 502, 503, 504 ) as $code ) {
			$result = RemoteResult::classify( self::response( $code, 'boom' ) );
			$this->assertSame( RemoteResult::TRANSPORT, $result->category(), "code {$code}" );
			$this->assertTrue( $result->retryable() );
			$this->assertSame( $code, $result->status() );
		}
	}

	public function test_429_is_transport_retryable(): void {
		$result = RemoteResult::classify( self::response( 429, 'limit', array( 'Retry-After' => '2' ) ) );
		$this->assertSame( RemoteResult::TRANSPORT, $result->category() );
		$this->assertTrue( $result->retryable() );
		$this->assertSame( 2, $result->retry_after() );
	}

	public function test_retry_after_header_lookup_is_case_insensitive(): void {
		$result = RemoteResult::classify( self::response( 429, '', array( 'retry-after' => '5' ) ) );
		$this->assertSame( 5, $result->retry_after() );
	}

	public function test_non_numeric_retry_after_is_null(): void {
		$result = RemoteResult::classify( self::response( 429, '', array( 'Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT' ) ) );
		$this->assertNull( $result->retry_after() );
	}

	/* ---------- semantic 类（不重试） ---------- */

	public function test_401_402_400_are_semantic(): void {
		foreach ( array( 401, 402, 400 ) as $code ) {
			$result = RemoteResult::classify( self::response( $code, '{"err":1}' ) );
			$this->assertSame( RemoteResult::SEMANTIC, $result->category(), "code {$code}" );
			$this->assertFalse( $result->retryable() );
		}
	}

	public function test_other_4xx_are_semantic_not_retryable(): void {
		foreach ( array( 403, 404, 405, 422 ) as $code ) {
			$result = RemoteResult::classify( self::response( $code, 'nope' ) );
			$this->assertSame( RemoteResult::SEMANTIC, $result->category(), "code {$code}" );
			$this->assertFalse( $result->retryable() );
		}
	}

	/* ---------- contract / parse（2xx 细分） ---------- */

	public function test_2xx_with_valid_json_is_ok_with_decoded_data(): void {
		$body    = '{"Response":{"CaptchaCode":1,"CaptchaMsg":"OK"}}';
		$result  = RemoteResult::classify( self::response( 200, $body ) );
		$this->assertTrue( $result->is_ok() );
		$this->assertSame( '', $result->category() );
		$this->assertSame( 200, $result->status() );
		$this->assertSame( $body, $result->body() );
		$this->assertSame( 1, $result->data()['Response']['CaptchaCode'] );
	}

	public function test_2xx_with_missing_required_field_is_contract(): void {
		// 结构缺失：JSON 合法但缺关键字段（天御：Response.CaptchaCode）——厂商改版哨兵。
		$result = RemoteResult::classify(
			self::response( 200, '{"Response":{"RequestId":"x"}}' ),
			array( 'required' => array( 'Response.CaptchaCode' ) )
		);
		$this->assertSame( RemoteResult::CONTRACT, $result->category() );
		$this->assertFalse( $result->retryable() );
	}

	public function test_2xx_with_required_field_present_is_ok(): void {
		$result = RemoteResult::classify(
			self::response( 200, '{"Response":{"CaptchaCode":1}}' ),
			array( 'required' => array( 'Response.CaptchaCode' ) )
		);
		$this->assertTrue( $result->is_ok() );
	}

	public function test_array_without_response_key_is_contract(): void {
		// wp_remote_* 数组结构本身缺失（无 response.code）——结构不符。
		$result = RemoteResult::classify( array( 'body' => '{}' ) );
		$this->assertSame( RemoteResult::CONTRACT, $result->category() );
	}

	public function test_2xx_with_garbage_json_is_parse_fallback(): void {
		// 坏 JSON：HTTP 层成功、body 无法解析——兜底 ParseError（LLM 输出漂移场景）。
		$result = RemoteResult::classify( self::response( 200, '{"verdict": pass}' ) );
		$this->assertSame( RemoteResult::PARSE, $result->category() );
		$this->assertFalse( $result->retryable() );
		$this->assertNull( $result->data() );
	}

	public function test_2xx_empty_body_without_requirements_is_parse(): void {
		// 空 body 视为业务解析失败（预期有响应体的接口收到空串）。
		$result = RemoteResult::classify( self::response( 200, '' ) );
		$this->assertSame( RemoteResult::PARSE, $result->category() );
	}

	/* ---------- 驱动 body 探测（优先级：HTTP 分类 > probe > 兜底 parse） ---------- */

	public function test_probe_maps_body_error_on_2xx(): void {
		// hCaptcha 形态：200 + error-codes:["invalid-input-secret"] → 驱动映射 SemanticError。
		$probe  = static function ( $decoded, $body ): ?string {
			return isset( $decoded['error-codes'] ) ? RemoteResult::SEMANTIC : null;
		};
		$result = RemoteResult::classify(
			self::response( 200, '{"success":false,"error-codes":["invalid-input-secret"]}' ),
			array( 'probe' => $probe )
		);
		$this->assertSame( RemoteResult::SEMANTIC, $result->category() );
	}

	public function test_probe_does_not_override_http_error_classification(): void {
		// HTTP 分类优先：401 就是 semantic，即使 probe 声称 transport。
		$probe  = static function (): string {
			return RemoteResult::TRANSPORT;
		};
		$result = RemoteResult::classify(
			self::response( 401, '{"error-codes":["x"]}' ),
			array( 'probe' => $probe )
		);
		$this->assertSame( RemoteResult::SEMANTIC, $result->category() );
	}

	public function test_probe_returning_null_falls_through_to_parse(): void {
		$probe  = static function (): ?string {
			return null;
		};
		$result = RemoteResult::classify( self::response( 200, 'not json' ), array( 'probe' => $probe ) );
		$this->assertSame( RemoteResult::PARSE, $result->category() );
	}

	/* ---------- 附带状态 ---------- */

	public function test_classify_sets_attempts_to_one(): void {
		$this->assertSame( 1, RemoteResult::classify( self::response( 200, '{}' ) )->attempts() );
	}

	public function test_with_attempts_returns_new_instance(): void {
		$first  = RemoteResult::classify( self::response( 500, '' ) );
		$second = $first->with_attempts( 3 );
		$this->assertSame( 1, $first->attempts() );
		$this->assertSame( 3, $second->attempts() );
		$this->assertSame( RemoteResult::TRANSPORT, $second->category() );
	}

	public function test_message_carries_status_and_body_excerpt(): void {
		$result = RemoteResult::classify( self::response( 503, 'service unavailable' ) );
		$this->assertStringContainsString( '503', $result->message() );
	}
}
