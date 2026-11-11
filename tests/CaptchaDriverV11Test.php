<?php
/**
 * CaptchaDriver 接口 v1.1 五方法契约测试（02 §2.1【v1.3】M2 开工前冻结）。
 *
 * 覆盖：
 *   - 接口面：id()/label()/credentialFields()/failurePolicy()/disasterPolicy() 五方法声明；
 *   - 天御实现：id/label 恒值、credentialFields 与 registry frontend.token_fields 派生一致
 *     （05 §4 v1.3：token_fields 由 credentialFields() 派生、registry 为默认快照）；
 *   - failurePolicy 分档：user_retry=[7,8,9,15]、config_error=[16,100]、pass=[21]（trerror 容灾档）；
 *   - disasterPolicy 消费 captcha.tencent.disaster_pass（缺省 allow / block / 非法值归 allow）；
 *   - 容灾限速（任务口径：每 IP 每小时 N=5 次容灾放行，超出按 block 拦）：
 *     allow+限速内=通过（meta 标注 disaster_pass）、allow+超额=拦、block=直拦；
 *   - 闸消费 id() 作统计键（Verdict.driver_id → 事件表 driver 列）；
 *   - 闸容灾放行事件（rule_hit 标注 trerror，05 §1.1「统计可查容灾放行量」）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\CaptchaDriverInterface;
use WinkGatekeeper\Drivers\captcha\TencentCaptchaDriver;
use WinkGatekeeper\Gates\CommentGate;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Verdict;

final class CaptchaDriverV11Test extends TestCase {

	/* ---------- 工具 ---------- */

	private static function client_returning( $raw ): RemoteClient {
		return new RemoteClient( static function () use ( $raw ) {
			return $raw;
		}, null, static function (): void {} );
	}

	private static function ok_body( array $response ): string {
		return (string) json_encode( array( 'Response' => $response ) );
	}

	private static function settings( array $tencent ): array {
		return array(
			'captcha' => array( 'tencent' => $tencent ),
			'copy'    => array( 'messages' => array(
				'captcha_missing' => '还差一个眨眼——完成上方的验证再点提交。',
				'captcha_failed'  => '眨眼没对上——刷新页面再试一次？',
			) ),
			'brand'   => array( 'contact_email' => 'admin@example.com' ),
		);
	}

	private static function driver( array $tencent_extra = array(), ?callable $limiter = null, $client_raw = null ): TencentCaptchaDriver {
		$tencent = array_merge(
			array( 'app_id' => 199999164, 'secret_id' => 'AKIDx', 'secret_key' => 'sk', 'app_secret_key' => 'ASK' ),
			$tencent_extra
		);
		return new TencentCaptchaDriver(
			self::settings( $tencent ),
			self::client_returning( $client_raw ?? array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'CaptchaCode' => 1 ) ) ) ),
			$limiter
		);
	}

	/* ---------- 接口面：五方法声明 ---------- */

	public function test_interface_declares_five_v11_methods(): void {
		$reflection = new \ReflectionClass( CaptchaDriverInterface::class );
		foreach ( array( 'id', 'label', 'credentialFields', 'failurePolicy', 'disasterPolicy' ) as $method ) {
			$this->assertTrue(
				$reflection->hasMethod( $method ),
				"CaptchaDriverInterface v1.1 必须声明 {$method}()"
			);
		}
	}

	/* ---------- 天御实现：恒值与派生 ---------- */

	public function test_tencent_id_and_label(): void {
		$driver = self::driver();
		$this->assertSame( 'tencent', $driver->id() );
		$this->assertSame( '腾讯天御验证码 2.0', $driver->label() );
	}

	public function test_credential_fields_derive_registry_token_fields(): void {
		// 05 §4 v1.3：registry frontend.token_fields 是 credentialFields() 的默认快照——
		// 运行时权威在驱动方法，两源漂移即红灯。
		$entry = TencentCaptchaDriver::registry_entry();
		$this->assertSame(
			$entry['frontend']['token_fields'],
			self::driver()->credentialFields()
		);
		$this->assertSame(
			array( 'wk_captcha_ticket', 'wk_captcha_randstr' ),
			self::driver()->credentialFields()
		);
	}

	public function test_failure_policy_buckets(): void {
		$policy = self::driver()->failurePolicy();
		$this->assertIsArray( $policy );

		$user_retry = $policy['user_retry'];
		$this->assertContains( 7, $user_retry );
		$this->assertContains( 8, $user_retry );
		$this->assertContains( 9, $user_retry );
		$this->assertContains( 15, $user_retry );

		$config_error = $policy['config_error'];
		$this->assertContains( 16, $config_error );
		$this->assertContains( 100, $config_error );

		$this->assertContains( 21, $policy['pass'] ); // trerror 容灾档（前缀条件在 verify 内判）。
	}

	/* ---------- disasterPolicy：站长开关消费 ---------- */

	public function test_disaster_policy_defaults_to_allow(): void {
		$this->assertSame( 'allow', self::driver()->disasterPolicy() );
	}

	public function test_credentials_are_trimmed_at_construction(): void {
		// 真机踩坑（C1 2026-11-10）：C2 env-sync 存入的 secret_id/secret_key 带尾随 \r
		// → TC3 HMAC 密钥含不可见字符 → AuthFailure.SignatureFailure。构造期 trim 收口。
		$driver = self::driver( array(
			'app_id'         => "199999164\r",
			'secret_id'      => "AKIDx \r\n",
			'secret_key'     => "sk\n",
			'app_secret_key' => "ASK ",
		) );
		$this->assertTrue( $driver->is_configured() );

		$captured = null;
		$client   = new RemoteClient( static function ( string $url, array $args ) use ( &$captured ) {
			$captured = $args;
			return array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'CaptchaCode' => 1 ) ) );
		} );
		$driver2  = new TencentCaptchaDriver(
			self::settings( array( 'app_id' => " 199999164\t", 'secret_id' => " AKIDx\r", 'secret_key' => "sk\n", 'app_secret_key' => " ASK " ) ),
			$client
		);
		$driver2->verify( array( 'ticket' => 't', 'randstr' => 'r', 'user_ip' => '1.2.3.4' ) );
		$body = json_decode( $captured['body'], true );
		$this->assertSame( 199999164, $body['CaptchaAppId'] );
		$this->assertSame( 'AKIDx', $captured['headers']['Authorization'] ? 'AKIDx' : 'AKIDx' ); // Authorization 含 SecretId（trim 后）。
		$this->assertStringContainsString( 'Credential=AKIDx/', (string) $captured['headers']['Authorization'] );
		$this->assertSame( 'ASK', $body['AppSecretKey'] );
	}

	public function test_disaster_policy_block_consumed_from_settings(): void {
		$this->assertSame( 'block', self::driver( array( 'disaster_pass' => 'block' ) )->disasterPolicy() );
	}

	public function test_disaster_policy_invalid_value_normalizes_to_allow(): void {
		$this->assertSame( 'allow', self::driver( array( 'disaster_pass' => 'yes' ) )->disasterPolicy() );
	}

	/* ---------- 容灾两态 + 限速（任务口径：N=5 次/小时/IP，超出按 block） ---------- */

	public function test_disaster_ticket_passes_within_rate_limit_and_marks_meta(): void {
		$called_ip = null;
		$driver    = self::driver(
			array(),
			static function ( string $ip ) use ( &$called_ip ): bool {
				$called_ip = $ip;
				return true;
			},
			array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'CaptchaCode' => 21, 'CaptchaMsg' => 'diff' ) ) )
		);

		$this->assertNull( $driver->verify( array( 'ticket' => 'trerror1006_199999164_1700000000', 'randstr' => 'r', 'user_ip' => '203.0.113.7' ) ) );
		$this->assertSame( '203.0.113.7', $called_ip, '限速器必须收到真实 IP' );

		$meta = $driver->last_verify_meta();
		$this->assertSame( 'CaptchaCode=21/trerror', $meta['rule'] );
		$this->assertTrue( $meta['disaster_pass'], '容灾放行须在 meta 标注（闸侧落事件 rule_hit=trerror）' );
	}

	public function test_disaster_ticket_rejected_when_rate_limit_exhausted(): void {
		$driver = self::driver(
			array(),
			static function (): bool {
				return false; // 额度耗尽。
			},
			array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'CaptchaCode' => 21 ) ) )
		);

		$message = $driver->verify( array( 'ticket' => 'trerror_x', 'randstr' => 'r', 'user_ip' => '1.2.3.4' ) );
		$this->assertIsString( $message, '超额容灾票按 block 拦截' );
		$this->assertSame( 'CaptchaCode=21/trerror-limited', $driver->last_verify_meta()['rule'] );
	}

	public function test_disaster_ticket_rejected_in_block_mode_without_consulting_limiter(): void {
		$consulted = false;
		$driver    = self::driver(
			array( 'disaster_pass' => 'block' ),
			static function () use ( &$consulted ): bool {
				$consulted = true;
				return true;
			},
			array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'CaptchaCode' => 21 ) ) )
		);

		$this->assertIsString( $driver->verify( array( 'ticket' => 'trerror_x', 'randstr' => 'r', 'user_ip' => '1.2.3.4' ) ) );
		$this->assertFalse( $consulted, 'block 档直拦，不消耗限速额度' );
		$this->assertSame( 'CaptchaCode=21/trerror-blocked', $driver->last_verify_meta()['rule'] );
	}

	public function test_rate_limit_boundary_allows_five_blocks_sixth(): void {
		$raw       = array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'CaptchaCode' => 21 ) ) );
		$remaining = TencentCaptchaDriver::DISASTER_LIMIT; // 内存限速器（生产 = transient 计数器）。
		$limiter   = static function () use ( &$remaining ): bool {
			if ( $remaining <= 0 ) {
				return false;
			}
			$remaining--;
			return true;
		};

		for ( $i = 1; $i <= 5; $i++ ) {
			$driver = self::driver( array(), $limiter, $raw );
			$this->assertNull( $driver->verify( array( 'ticket' => 'trerror_' . $i, 'randstr' => 'r', 'user_ip' => '9.9.9.9' ) ), "第 {$i} 次容灾放行应在额度内" );
		}

		$sixth = self::driver( array(), $limiter, $raw );
		$this->assertIsString( $sixth->verify( array( 'ticket' => 'trerror_6', 'randstr' => 'r', 'user_ip' => '9.9.9.9' ) ), '第 6 次按 block 拦截' );
		$this->assertSame( 5, TencentCaptchaDriver::DISASTER_LIMIT, '任务口径：每 IP 每小时 N=5' );
	}

	public function test_rate_limit_counter_is_per_ip(): void {
		$seen = array();
		$raw  = array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'CaptchaCode' => 21 ) ) );
		// 每日 IP 独立计数的限速器（transient 键含 IP 派生哈希的语义等价物）。
		$counts  = array();
		$limiter = static function ( string $ip ) use ( &$counts, &$seen ): bool {
			$seen[] = $ip;
			$count  = $counts[ $ip ] ?? 0;
			if ( $count >= 5 ) {
				return false;
			}
			$counts[ $ip ] = $count + 1;
			return true;
		};

		$this->assertNull( self::driver( array(), $limiter, $raw )->verify( array( 'ticket' => 'trerror_a', 'randstr' => 'r', 'user_ip' => '10.0.0.1' ) ) );
		$this->assertNull( self::driver( array(), $limiter, $raw )->verify( array( 'ticket' => 'trerror_b', 'randstr' => 'r', 'user_ip' => '10.0.0.2' ) ) );
		$this->assertSame( array( '10.0.0.1', '10.0.0.2' ), $seen );
	}

	/* ---------- 闸消费：id() 统计键 + 容灾放行事件 ---------- */

	private static function stub_hash(): callable {
		return static function ( string $data ): string {
			return 'h_' . hash_hmac( 'sha256', $data, 'gate-test-key' );
		};
	}

	private function stubbed_request( string $context_id ): array {
		$stub = \WinkGatekeeper\Intercept\Stub::issue( 'comment', $context_id, true, 1700000000, self::stub_hash() );
		return array( 'comment_post_ID' => $context_id, 'wk_stub' => $stub );
	}

	public function test_gate_uses_driver_id_as_stats_key(): void {
		// 接口 v1.1：Verdict.driver_id ← 驱动 id()（事件表 driver 列/分驱动统计键单一来源）。
		$captured = array();
		$captcha  = new class( 'zzz-captcha' ) implements CaptchaDriverInterface {
			private $id;
			public function __construct( string $id ) {
				$this->id = $id;
			}
			public function render( array $args ): string {
				return '';
			}
			public function verify( array $context ): ?string {
				return '眨眼没对上';
			}
			public function is_configured(): bool {
				return true;
			}
			public function enqueue_assets(): void {
			}
			public function id(): string {
				return $this->id;
			}
			public function label(): string {
				return '假驱动';
			}
			public function credentialFields(): array {
				return array( 'wk_captcha_ticket' );
			}
			public function failurePolicy(): array {
				return array();
			}
			public function disasterPolicy(): string {
				return 'none';
			}
			/* 【v1.5·C1】接口 v1.2 能力面：假驱动沿 legacy 语义折算（verify_tri 委托 verify）。 */
			public function verify_tri( array $context ): array {
				return \WinkGatekeeper\Gates\CaptchaTriInterpreter::legacy_to_tri( $this->verify( $context ), method_exists( $this, 'last_verify_meta' ) ? $this->last_verify_meta() : array() );
			}
			public function errorPolicy(): string {
				return 'block';
			}
		};

		$gate = new CommentGate(
			array(),
			$captcha,
			null,
			static function ( string $stage, Verdict $verdict, array $meta ) use ( &$captured ): string {
				$captured[] = array( $stage, $verdict );
				return 'WK-TEST-0001';
			},
			new IpResolver( array() ),
			self::stub_hash(),
			static function (): int {
				return 1700000000;
			}
		);

		$request = $this->stubbed_request( '4' );
		$request['wk_captcha_ticket'] = 'tok';
		$request['wk_captcha_randstr'] = 'r';
		$result = $gate->orchestrate(
			array( 'comment_post_ID' => 4, 'comment_content' => '正常正文' ),
			$request,
			array( 'REMOTE_ADDR' => '203.0.113.9' )
		);

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'zzz-captcha', $captured[0][1]->driver_id(), '统计键须取驱动 id()（非 registry_id/硬编码 tencent）' );
	}

	public function test_gate_records_disaster_pass_event_with_trerror_rule(): void {
		$captured = array();
		$recorder = static function ( string $stage, Verdict $verdict, array $meta ) use ( &$captured ): string {
			$captured[] = array( $stage, $verdict, $meta );
			return 'WK-TEST-DISASTER';
		};

		$driver = self::driver(
			array(),
			static function (): bool {
				return true; // 限速内。
			},
			array( 'response' => array( 'code' => 200 ), 'body' => self::ok_body( array( 'CaptchaCode' => 21, 'CaptchaMsg' => 'diff' ) ) )
		);

		$gate = new CommentGate(
			array(),
			$driver,
			null,
			$recorder,
			new IpResolver( array() ),
			self::stub_hash(),
			static function (): int {
				return 1700000000;
			}
		);

		$request = $this->stubbed_request( '4' );
		$request['wk_captcha_ticket'] = 'trerror1006_199999164_1700000000';
		$request['wk_captcha_randstr'] = 'r';

		$result = $gate->orchestrate(
			array( 'comment_post_ID' => 4, 'comment_content' => '网络差真人的正常评论' ),
			$request,
			array( 'REMOTE_ADDR' => '203.0.113.10' )
		);

		$this->assertSame( 'allow', $result['decision'], '容灾票在 allow 档放行' );

		$disaster_events = array();
		foreach ( $captured as $entry ) {
			if ( Verdict::PASS === $entry[1]->verdict() ) {
				$disaster_events[] = $entry;
			}
		}
		$this->assertCount( 1, $disaster_events, '容灾放行恰落一条 pass 事件（统计可查容灾放行量）' );
		$this->assertSame( 'captcha', $disaster_events[0][0] );
		$this->assertSame( 'tencent', $disaster_events[0][1]->driver_id() );
		$this->assertSame( 'CaptchaCode=21/trerror', $disaster_events[0][1]->reason() );
		$this->assertTrue( $disaster_events[0][2]['disaster_pass'] ?? false );
		// 【O3 §三卡 2】hits 携带 disaster_pass 审计标签（should_record pass 特例通道——
		// 与 verify_cache_hit/trust_pass 同法；写入矩阵据此放行容灾放行事件落库）。
		$this->assertContains( 'disaster_pass', $disaster_events[0][1]->hits(), 'verdict hits 须含 disaster_pass 审计标签' );
		$this->assertContains( 'CaptchaCode=21/trerror', $disaster_events[0][1]->hits() );
	}
}
