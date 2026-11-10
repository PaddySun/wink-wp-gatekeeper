<?php
/**
 * TrustStub 信任存根单元测试（02 §3.3a/R2 §2.5——信用免验凭证）。
 *
 * 格式：trust = base64(json{gate:'comment', ctx, id_hash, ip_hash, iat}) . '.' . wp_hash(payload,'nonce')
 * 六项校验（全部通过才算命中；任一失败=视同无凭证，不报错不拦）：
 *   ① 签名 hash_equals + 结构完整；② gate/context_id 一致；③ now-iat ≤ ttl_minutes；
 *   ④ ip_hash 与本次解析一致；⑤ id_hash 与本次提交身份一致；⑥（M3）新鲜 ip 判定——闸内消费。
 *
 * 非一次性、不绑内容（TTL 内可重用——四闸提交时必跑语义已在闸内）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\TrustStub;

final class TrustStubTest extends TestCase {

	private const NOW = 1731168000;

	/** @var callable 固定哈希（生产 = wp_hash($data,'nonce') 封装） */
	private static $hash;

	/** @var callable 固定身份/IP 哈希（生产 = wp_salt('auth') HMAC-SHA256[:16]） */
	private static $mac;

	public static function setUpBeforeClass(): void {
		self::$hash = static function ( string $data ): string {
			return 't_' . hash_hmac( 'sha256', $data, 'trust-test-key' );
		};
		self::$mac = static function ( string $data ): string {
			return substr( hash_hmac( 'sha256', $data, 'trust-test-mac' ), 0, 16 );
		};
	}

	public function test_issue_and_verify_round_trip(): void {
		$trust = TrustStub::issue( '42', self::id_hash_guest( 'reader@example.com', '老读者' ), self::ip_hash( '203.0.113.7' ), self::NOW, self::$hash );

		$this->assertTrue( TrustStub::verify(
			$trust, '42',
			self::id_hash_guest( 'reader@example.com', '老读者' ),
			self::ip_hash( '203.0.113.7' ),
			10, self::NOW + 599, self::$hash
		) );
	}

	/* ---------- 校验①：签名/结构 ---------- */

	public function test_verify_rejects_tampered_payload(): void {
		$trust = TrustStub::issue( '42', 'id', 'ip', self::NOW, self::$hash );
		$trust[0] = 'X'; // 篡改 payload 首字符。
		$this->assertFalse( TrustStub::verify( $trust, '42', 'id', 'ip', 10, self::NOW, self::$hash ) );
	}

	public function test_verify_rejects_missing_hash_segment(): void {
		$this->assertFalse( TrustStub::verify( 'onlypayload', '42', 'id', 'ip', 10, self::NOW, self::$hash ) );
		$this->assertFalse( TrustStub::verify( '', '42', 'id', 'ip', 10, self::NOW, self::$hash ) );
	}

	public function test_verify_rejects_forged_foreign_hash(): void {
		// 换一台「盐」签的哈希（跨站/旧密钥形态）。
		$other = static function ( string $data ): string {
			return 'o_' . hash_hmac( 'sha256', $data, 'other-key' );
		};
		$trust = TrustStub::issue( '42', 'id', 'ip', self::NOW, $other );
		$this->assertFalse( TrustStub::verify( $trust, '42', 'id', 'ip', 10, self::NOW, self::$hash ) );
	}

	/* ---------- 校验②：context 绑定 ---------- */

	public function test_verify_rejects_wrong_context(): void {
		$trust = TrustStub::issue( '42', 'id', 'ip', self::NOW, self::$hash );
		$this->assertFalse( TrustStub::verify( $trust, '43', 'id', 'ip', 10, self::NOW, self::$hash ) );
	}

	/* ---------- 校验③：TTL（与 verify_cache_minutes 对齐） ---------- */

	public function test_verify_accepts_at_ttl_boundary(): void {
		// 「now - iat ≤ ttl_minutes」：边界值（恰好 10 分钟）通过。
		$trust = TrustStub::issue( '42', 'id', 'ip', self::NOW, self::$hash );
		$this->assertTrue( TrustStub::verify( $trust, '42', 'id', 'ip', 10, self::NOW + 600, self::$hash ) );
	}

	public function test_verify_rejects_expired(): void {
		$trust = TrustStub::issue( '42', 'id', 'ip', self::NOW, self::$hash );
		$this->assertFalse( TrustStub::verify( $trust, '42', 'id', 'ip', 10, self::NOW + 601, self::$hash ) );
	}

	public function test_verify_rejects_future_iat(): void {
		// 时钟倒挂（iat 晚于校验时刻）按未生效处理。
		$trust = TrustStub::issue( '42', 'id', 'ip', self::NOW + 30, self::$hash );
		$this->assertFalse( TrustStub::verify( $trust, '42', 'id', 'ip', 10, self::NOW, self::$hash ) );
	}

	/* ---------- 校验④：IP 绑定 ---------- */

	public function test_verify_rejects_ip_change(): void {
		// 预检与提交之间换网络/换出口 → 失效（重预检即可）。
		$trust = TrustStub::issue( '42', 'id', self::ip_hash( '203.0.113.7' ), self::NOW, self::$hash );
		$this->assertFalse( TrustStub::verify( $trust, '42', 'id', self::ip_hash( '198.51.100.9' ), 10, self::NOW, self::$hash ) );
	}

	/* ---------- 校验⑤：身份绑定（堵偷梁换柱） ---------- */

	public function test_verify_rejects_identity_change(): void {
		// 预检用可信邮箱、提交换新邮箱 → 失效。
		$trust = TrustStub::issue( '42', self::id_hash_guest( 'reader@example.com', '老读者' ), 'ip', self::NOW, self::$hash );
		$this->assertFalse( TrustStub::verify( $trust, '42', self::id_hash_guest( 'other@example.com', '老读者' ), 'ip', 10, self::NOW, self::$hash ) );
		// 改昵称错字同样失效（游客=lower(email)|昵称 双匹配——文案给台阶重预检）。
		$this->assertFalse( TrustStub::verify( $trust, '42', self::id_hash_guest( 'reader@example.com', '老读者.' ), 'ip', 10, self::NOW, self::$hash ) );
	}

	/* ---------- 身份哈希输入形态（02 §3.3a 格式注） ---------- */

	public function test_guest_identity_input_is_lowercased_email_then_author(): void {
		$this->assertSame( 'reader@example.com|老读者', TrustStub::guest_identity_input( 'Reader@Example.COM', '老读者' ) );
	}

	public function test_user_identity_input_is_u_prefixed(): void {
		$this->assertSame( 'u:7', TrustStub::user_identity_input( 7 ) );
	}

	/* ---------- 可重用（非一次性）：TTL 内多次校验皆真 ---------- */

	public function test_stub_is_reusable_within_ttl(): void {
		$trust = TrustStub::issue( '42', 'id', 'ip', self::NOW, self::$hash );
		$this->assertTrue( TrustStub::verify( $trust, '42', 'id', 'ip', 10, self::NOW + 60, self::$hash ) );
		$this->assertTrue( TrustStub::verify( $trust, '42', 'id', 'ip', 10, self::NOW + 120, self::$hash ), 'TTL 内可重用（无状态签名凭证，零存储）' );
	}

	private static function id_hash_guest( string $email, string $author ): string {
		$mac = self::$mac;
		return $mac( TrustStub::guest_identity_input( $email, $author ) );
	}

	private static function ip_hash( string $ip ): string {
		$mac = self::$mac;
		return $mac( $ip );
	}
}
