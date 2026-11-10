<?php
/**
 * B2 契约测试：CaptchaDriverInterface v1.2 升格 + 天御 verify_tri 三态回归（02 §2.1【v1.5】终形清单、
 * 05 §1.1【v1.5】天御 verify_tri 回归注记、DESIGN-C1 §5）。
 *
 * 覆盖：
 *   - 接口终形方法清单（render/verify_tri/is_configured/enqueue_assets/id/label/credentialFields/
 *     failurePolicy/disasterPolicy/errorPolicy）；verify() 自接口删除；
 *   - 天御 errorPolicy()='block'（拍板：零行为变化）+ registry scoring=false（Tab② min_score 隐藏）；
 *   - verify_tri：pass（score=1.0 + meta）/ reject（业务码文案）/ error（transport/semantic/contract，
 *     meta.rule=remote_<category>|contract_mismatch + raw 快照）/ 缺票 reject captcha_missing；
 *   - verify() legacy 垫片保持：null↔pass、错误文案↔reject、last_verify_meta 平价。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\CaptchaDriverInterface;
use WinkGatekeeper\Drivers\captcha\TencentCaptchaDriver;
use WinkGatekeeper\Http\RemoteClient;

final class B2CaptchaTriContractTest extends TestCase {

	/* ---------- 接口 v1.2 终形 ---------- */

	public function test_interface_v12_method_surface(): void {
		$methods = array( 'render', 'verify_tri', 'is_configured', 'enqueue_assets', 'id', 'label', 'credentialFields', 'failurePolicy', 'disasterPolicy', 'errorPolicy' );
		foreach ( $methods as $method ) {
			$this->assertTrue( method_exists( CaptchaDriverInterface::class, $method ), "接口缺 {$method}" );
		}
		$this->assertFalse( method_exists( CaptchaDriverInterface::class, 'verify' ), 'verify() 已自接口删除（主通道位由 verify_tri 取代）' );
	}

	public function test_interface_error_policy_default_doc_shape(): void {
		// errorPolicy 返回 string（驱动侧声明位；站长覆盖在 captcha.error_policy 键——闸侧消费）。
		$ref = new \ReflectionMethod( CaptchaDriverInterface::class, 'errorPolicy' );
		$this->assertSame( 'string', (string) $ref->getReturnType() );
	}

	/* ---------- 工具 ---------- */

	private static function client_returning( $raw ): RemoteClient {
		return new RemoteClient( static function () use ( $raw ) {
			return $raw;
		}, null, static function (): void {} );
	}

	private static function ok_body( array $response ): string {
		return (string) json_encode( array( 'Response' => $response ) );
	}

	private static function driver( ?RemoteClient $client = null ): TencentCaptchaDriver {
		$settings = array(
			'captcha' => array( 'tencent' => array( 'app_id' => 199999164, 'secret_id' => 'AKIDx', 'secret_key' => 'sk', 'app_secret_key' => 'ASK' ) ),
			'copy'    => array( 'messages' => array( 'captcha_missing' => '还差一个眨眼——完成上方的验证再点提交。' ) ),
			'brand'   => array( 'contact_email' => 'admin@example.com' ),
		);
		return new TencentCaptchaDriver(
			$settings,
			$client ?? self::client_returning( array( 'response' => array( 'code' => 500 ), 'body' => '' ) )
		);
	}

	/* ---------- 天御 errorPolicy / registry scoring ---------- */

	public function test_tencent_error_policy_is_block(): void {
		// 拍板（D6）：默认 block=故障即拦，天御零行为变化；pass_limited 留阿里按 SLA 评估。
		$this->assertSame( 'block', self::driver()->errorPolicy() );
	}

	public function test_registry_declares_scoring_false(): void {
		// 05 §4【v1.5】scoring 键：现役驱动全部二态 → Tab② min_score 控件隐藏。
		$entry = TencentCaptchaDriver::registry_entry();
		$this->assertArrayHasKey( 'scoring', $entry );
		$this->assertFalse( $entry['scoring'] );
	}

	/* ---------- verify_tri：pass ---------- */

	public function test_verify_tri_pass_shape(): void {
		$driver = self::driver( self::client_returning( array(
			'response' => array( 'code' => 200 ),
			'body'     => self::ok_body( array( 'CaptchaCode' => 1, 'CaptchaMsg' => 'OK', 'Score' => 10 ) ),
		) ) );

		$tri = $driver->verify_tri( array( 'ticket' => 'tok', 'randstr' => 'r', 'user_ip' => '1.2.3.4' ) );

		$this->assertSame( 'pass', $tri['state'] );
		$this->assertSame( 1.0, $tri['score'], '二态驱动恒 score=1.0（score 归一化契约）' );
		$this->assertSame( '', $tri['message'] );
		$this->assertSame( 1, $tri['meta']['CaptchaCode'] );
		$this->assertSame( 'CaptchaCode=1', $tri['meta']['rule'] );
		$this->assertSame( 10, $tri['meta']['Score'], '附加信号 Score 存 meta' );
	}

	/* ---------- verify_tri：reject（业务拒绝） ---------- */

	public function test_verify_tri_reject_shape(): void {
		$driver = self::driver( self::client_returning( array(
			'response' => array( 'code' => 200 ),
			'body'     => self::ok_body( array( 'CaptchaCode' => 8, 'CaptchaMsg' => 'ticket expired' ) ),
		) ) );

		$tri = $driver->verify_tri( array( 'ticket' => 'tok', 'randstr' => 'r', 'user_ip' => '1.2.3.4' ) );

		$this->assertSame( 'reject', $tri['state'] );
		$this->assertNull( $tri['score'], 'reject 态不携带分数' );
		$this->assertStringContainsString( '刷新', $tri['message'] );
		$this->assertSame( 'CaptchaCode=8', $tri['meta']['rule'] );
	}

	public function test_verify_tri_missing_ticket_rejects_as_captcha_missing(): void {
		$driver = self::driver();
		$tri    = $driver->verify_tri( array( 'randstr' => 'r', 'user_ip' => '1.2.3.4' ) );
		$this->assertSame( 'reject', $tri['state'] );
		$this->assertSame( 'captcha_missing', $tri['meta']['rule'] );
		$this->assertStringContainsString( '眨眼', $tri['message'] );
	}

	/* ---------- verify_tri：error（远端四类终态）——行为变化点 1 ---------- */

	public function test_verify_tri_transport_terminal_is_error_state(): void {
		$driver = self::driver( self::client_returning( array( 'response' => array( 'code' => 503 ), 'body' => '' ) ) );
		$tri    = $driver->verify_tri( array( 'ticket' => 'tok', 'randstr' => 'r', 'user_ip' => '1.2.3.4' ) );

		$this->assertSame( 'error', $tri['state'] );
		$this->assertSame( 'remote_transport', $tri['meta']['rule'] );
		$this->assertStringContainsString( '稍后再试', $tri['message'], 'MSG_TRANSPORT 降级文案' );
		$this->assertArrayHasKey( 'raw', $tri['meta'], 'meta.raw 必带（F44 原样）' );
		$this->assertArrayHasKey( 'category', $tri['meta'] );
	}

	public function test_verify_tri_semantic_terminal_is_error_state(): void {
		$driver = self::driver( self::client_returning( array( 'response' => array( 'code' => 401 ), 'body' => '' ) ) );
		$tri    = $driver->verify_tri( array( 'ticket' => 'tok', 'randstr' => 'r', 'user_ip' => '1.2.3.4' ) );

		$this->assertSame( 'error', $tri['state'] );
		$this->assertSame( 'remote_semantic', $tri['meta']['rule'] );
		$this->assertStringContainsString( '配置', $tri['message'], 'MSG_CONFIG 文案' );
	}

	public function test_verify_tri_contract_mismatch_is_error_state(): void {
		$driver = self::driver( self::client_returning( array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"Response":{"RequestId":"r1"}}',
		) ) );
		$tri = $driver->verify_tri( array( 'ticket' => 'tok', 'randstr' => 'r', 'user_ip' => '1.2.3.4' ) );

		$this->assertSame( 'error', $tri['state'] );
		$this->assertSame( 'contract_mismatch', $tri['meta']['rule'] );
	}

	/* ---------- verify() legacy 垫片（接口外保留；05 §1.1 v1.5） ---------- */

	public function test_legacy_verify_shim_maps_pass_to_null(): void {
		$driver = self::driver( self::client_returning( array(
			'response' => array( 'code' => 200 ),
			'body'     => self::ok_body( array( 'CaptchaCode' => 1 ) ),
		) ) );
		$this->assertNull( $driver->verify( array( 'ticket' => 'tok', 'randstr' => 'r', 'user_ip' => '1.2.3.4' ) ) );
		$this->assertSame( 'CaptchaCode=1', $driver->last_verify_meta()['rule'], '垫片保持 last_verify_meta 平价' );
	}

	public function test_legacy_verify_shim_maps_error_to_message(): void {
		$driver = self::driver( self::client_returning( array( 'response' => array( 'code' => 503 ), 'body' => '' ) ) );
		$this->assertIsString( $driver->verify( array( 'ticket' => 'tok', 'randstr' => 'r', 'user_ip' => '1.2.3.4' ) ) );
	}
}
