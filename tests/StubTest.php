<?php
/**
 * 签名存根单元测试（02 §3.3：签发 + 三态校验 + TTL）。
 *
 * 规格：存根 = base64(json{gate, context_id, captcha_shown, ts}) + '.' + hash(前者)；
 * 提交期三态：false（缺字段/哈希错/gate 错/context_id 错/过期）→ 拦 bad-signature；
 * true（签名有效但本表单未展示挑战）→ 免验证放行；null（展示过）→ 必须走 verify。
 * wp_hash 为 WP 边界——哈希经可注入回调（默认边界层注入 wp_hash，测试注入固定函数）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Intercept\Stub;

final class StubTest extends TestCase {

	/**
	 * 固定哈希回调（测试注入；生产为 wp_hash($data, 'nonce')）。
	 *
	 * @return callable
	 */
	private static function hash(): callable {
		return static function ( string $data ): string {
			return 'h_' . hash_hmac( 'sha256', $data, 'test-key' );
		};
	}

	public function test_issue_produces_base64_payload_dot_hash(): void {
		$stub  = Stub::issue( 'comment', '42', true, 1731000000, self::hash() );
		$parts = explode( '.', $stub );

		$this->assertCount( 2, $stub === '' ? array() : $parts );
		$this->assertNotFalse( base64_decode( $parts[0], true ), '前段应为合法 base64' );

		$payload = json_decode( (string) base64_decode( $parts[0], true ), true );
		$this->assertSame( 'comment', $payload['gate'] );
		$this->assertSame( '42', $payload['context_id'] );
		$this->assertTrue( $payload['captcha_shown'] );
		$this->assertSame( 1731000000, $payload['ts'] );
		$this->assertSame( self::hash()( $parts[0] ), $parts[1] );
	}

	/* ---------- 三态校验 ---------- */

	public function test_verify_shown_stub_returns_null_when_fresh(): void {
		$stub = Stub::issue( 'comment', '42', true, 1731000000, self::hash() );
		$this->assertNull( Stub::verify( $stub, 'comment', '42', 1731000000 + 60, self::hash() ) );
	}

	public function test_verify_unshown_stub_returns_true(): void {
		// captcha_shown=false：签名有效但未展示挑战 → 免验证放行（true）。
		$stub = Stub::issue( 'comment', '42', false, 1731000000, self::hash() );
		$this->assertTrue( Stub::verify( $stub, 'comment', '42', 1731000000 + 60, self::hash() ) );
	}

	public function test_verify_garbage_returns_false(): void {
		foreach ( array( '', 'not-a-stub', 'a.b.c', base64_encode( 'oops' ) ) as $bad ) {
			$this->assertFalse( Stub::verify( $bad, 'comment', '42', 1731000000, self::hash() ), "应 false：{$bad}" );
		}
	}

	public function test_verify_tampered_payload_returns_false(): void {
		$stub    = Stub::issue( 'comment', '42', true, 1731000000, self::hash() );
		$parts   = explode( '.', $stub );
		$forged  = base64_encode( json_encode( array(
			'gate'           => 'comment',
			'context_id'     => '42',
			'captcha_shown'  => false, // 篡改：展示过 → 未展示（试图骗免验证通道）。
			'ts'             => 1731000000,
		) ) );
		$this->assertFalse( Stub::verify( $forged . '.' . $parts[1], 'comment', '42', 1731000000, self::hash() ) );
	}

	public function test_verify_wrong_hash_returns_false(): void {
		$stub  = Stub::issue( 'comment', '42', true, 1731000000, self::hash() );
		$evil  = static function ( string $data ): string {
			return 'evil_' . md5( $data );
		};
		$this->assertFalse( Stub::verify( $stub, 'comment', '42', 1731000000 + 60, $evil ) );
	}

	public function test_verify_wrong_gate_returns_false(): void {
		// 防跨表单 token 混用：login 表单的存根不能过 comment 闸。
		$stub = Stub::issue( 'login', 'login', true, 1731000000, self::hash() );
		$this->assertFalse( Stub::verify( $stub, 'comment', '42', 1731000000 + 60, self::hash() ) );
	}

	public function test_verify_wrong_context_id_returns_false(): void {
		// 文章 42 页面的存根不能用于文章 43（防存根跨文章搬运）。
		$stub = Stub::issue( 'comment', '42', true, 1731000000, self::hash() );
		$this->assertFalse( Stub::verify( $stub, 'comment', '43', 1731000000 + 60, self::hash() ) );
	}

	/* ---------- TTL ---------- */

	public function test_verify_within_24h_returns_state(): void {
		$stub = Stub::issue( 'comment', '42', true, 1731000000, self::hash() );
		// 24h - 1s：仍有效。
		$this->assertNull( Stub::verify( $stub, 'comment', '42', 1731000000 + Stub::TTL - 1, self::hash() ) );
	}

	public function test_verify_expired_returns_false(): void {
		$stub = Stub::issue( 'comment', '42', true, 1731000000, self::hash() );
		// 超过 24h：过期（评论页挂一晚上再提交的边角，02 §3.3）。
		$this->assertFalse( Stub::verify( $stub, 'comment', '42', 1731000000 + Stub::TTL + 1, self::hash() ) );
	}

	public function test_ttl_constant_is_24_hours(): void {
		$this->assertSame( 86400, Stub::TTL );
	}

	/* ---------- payload 健壮性 ---------- */

	public function test_verify_payload_with_missing_keys_returns_false(): void {
		$hash    = self::hash();
		$partial = base64_encode( json_encode( array( 'gate' => 'comment' ) ) );
		$this->assertFalse( Stub::verify( $partial . '.' . $hash( $partial ), 'comment', '42', 1731000000, $hash ) );
	}

	public function test_verify_non_json_base64_returns_false(): void {
		$hash = self::hash();
		$raw  = base64_encode( '<<<not json>>>' );
		$this->assertFalse( Stub::verify( $raw . '.' . $hash( $raw ), 'comment', '42', 1731000000, $hash ) );
	}

	public function test_context_id_mismatch_type_is_false(): void {
		// context_id 严格字符串比较（'042' ≠ '42'，防松散比较绕过）。
		$stub = Stub::issue( 'comment', '42', true, 1731000000, self::hash() );
		$this->assertFalse( Stub::verify( $stub, 'comment', '042', 1731000000 + 60, self::hash() ) );
	}
}
