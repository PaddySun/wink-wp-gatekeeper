<?php
/**
 * CommentGate 证据行人类可读化集成测试（03 §3【v1.5·F4-3】）。
 *
 * 规格：拦截出口 reject_with_message 的证据行「命中规则」对云端驱动 rule_hit
 * （Label,Score 形态）做人类可读映射；驱动人类名经注入的 $driver_meta 闭包从
 * DriverRegistry 派生；未注入（缺省 null）= 原样透传（零回归）；LocalRules 键
 * 原样（不放宽）；{rule} 占位符仍替换原始规则键（键名语义，防探测 oracle 条款不变）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\ReviewDriverInterface;
use WinkGatekeeper\Gates\CommentGate;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Verdict;

final class CommentGateEvidenceTest extends TestCase {

	/** @var callable 固定存根哈希（生产 = wp_hash 封装）。 */
	private static $stub_hash;

	public static function setUpBeforeClass(): void {
		self::$stub_hash = static function ( string $data ): string {
			return 'h_' . hash_hmac( 'sha256', $data, 'evidence-test-key' );
		};
	}

	private function fake_cloud( Verdict $result ): ReviewDriverInterface {
		return new class( $result ) implements ReviewDriverInterface {
			private $result;
			public function __construct( Verdict $result ) {
				$this->result = $result;
			}
			public function check( string $content, array $meta ): Verdict {
				return $this->result;
			}
			public function last_check_meta(): array {
				return array();
			}
		};
	}

	private static function settings( array $overrides = array() ): array {
		return array_merge( array(
			'gates'  => array( 'comment' => array( 'enabled' => true, 'render_mode' => 'always' ) ),
			'review' => array(
				'local' => array(
					'min_length' => 0, 'max_length' => 1800, 'require_chinese' => 0,
					'custom_banned_words' => '', 'use_wp_keywords' => 0,
				),
				'cloud' => array(
					'enabled' => true,
					'chain'   => array( 'tencent_tms' ),
					'tencent_tms' => array( 'fail_mode' => 'review' ),
				),
			),
			'copy'   => array( 'messages' => array(
				'banned_word' => '这串字符我们眼熟——换句人话再试试？',
			) ),
			'stats'  => array( 'enabled' => true, 'pass_sampling' => false ),
		), $overrides );
	}

	private function gate( array &$log, ?callable $driver_meta, ?ReviewDriverInterface $cloud = null, array $settings_overrides = array() ): CommentGate {
		$cloud = $cloud ?? $this->fake_cloud( Verdict::reject( 'tencent_tms', 'Abuse,100', array( 'Abuse' ) ) );
		return new CommentGate(
			self::settings( $settings_overrides ),
			null,
			null,
			static function ( string $stage, Verdict $verdict, array $meta ) use ( &$log ): string {
				$log[] = array( 'stage' => $stage, 'rule' => $verdict->reason() );
				return 'WK-20261111-0001';
			},
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return 1731168000;
			},
			null,
			null,
			array( 'tencent_tms' => $cloud ),
			null,
			null,
			null,
			null,
			null,
			$driver_meta
		);
	}

	private static function commentdata(): array {
		return array( 'comment_post_ID' => '42', 'comment_content' => '这是一条正常的中文评论', 'comment_author' => '张三', 'comment_author_email' => 'z@example.com' );
	}

	private static function request(): array {
		return array( 'comment_post_ID' => '42', 'comment_content' => '这是一条正常的中文评论' );
	}

	private static function server(): array {
		return array( 'REMOTE_ADDR' => '203.0.113.7' );
	}

	public function test_cloud_reject_evidence_is_humanized_with_driver_meta(): void {
		$log  = array();
		$gate = $gate = $this->gate( $log, static function ( string $driver_id ): ?array {
			return array( 'type' => 'review', 'label' => '腾讯云 TMS 文本审核', 'label_overrides' => array() );
		} );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame(
			'云端文本审核（腾讯云 TMS 文本审核）：辱骂违规 · 置信 100',
			$result['evidence']['命中规则']
		);
	}

	public function test_cloud_reject_evidence_stays_raw_without_driver_meta(): void {
		$log  = array();
		$gate = $this->gate( $log, null );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'Abuse,100', $result['evidence']['命中规则'] );
	}

	/**
	 * LocalRules 键不放宽：本地四闸 reject 的证据行仍为规则键名。
	 */
	public function test_local_rules_reject_evidence_stays_rule_key(): void {
		$log = array();
		$gate = $this->gate( $log, static function ( string $driver_id ): ?array {
			return array( 'type' => 'review', 'label' => '腾讯云 TMS 文本审核', 'label_overrides' => array() );
		}, null, array( 'review' => array(
			'local' => array(
				'min_length' => 10, 'max_length' => 1800, 'require_chinese' => 0,
				'custom_banned_words' => '', 'use_wp_keywords' => 0,
			),
			'cloud' => array(
				'enabled' => true,
				'chain'   => array( 'tencent_tms' ),
				'tencent_tms' => array( 'fail_mode' => 'review' ),
			),
		) ) );

		$result = $gate->orchestrate(
			array( 'comment_post_ID' => '42', 'comment_content' => 'ab' ),
			array( 'comment_post_ID' => '42', 'comment_content' => 'ab' ),
			self::server()
		);

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'too_short', $result['evidence']['命中规则'] );
	}

	/**
	 * {rule} 占位符仍替换原始规则键（键名语义——防探测 oracle 条款不放宽）。
	 */
	public function test_rule_placeholder_keeps_raw_key(): void {
		$log = array();
		$settings = self::settings( array( 'copy' => array( 'messages' => array( 'banned_word' => '命中 {rule}，事件 {event_no}' ) ) ) );
		$cloud = $this->fake_cloud( Verdict::reject( 'tencent_tms', 'Abuse,100', array( 'Abuse' ) ) );
		$gate = new CommentGate(
			$settings,
			null,
			null,
			static function ( string $stage, Verdict $verdict, array $meta ): string {
				return 'WK-20261111-0002';
			},
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return 1731168000;
			},
			null,
			null,
			array( 'tencent_tms' => $cloud ),
			null,
			null,
			null,
			null,
			null,
			static function ( string $driver_id ): ?array {
				return array( 'type' => 'review', 'label' => '腾讯云 TMS 文本审核', 'label_overrides' => array() );
			}
		);

		$result = $gate->orchestrate(
			array( 'comment_post_ID' => '42', 'comment_content' => '这是一条正常的中文评论。违禁词测试 banned' ),
			array( 'comment_post_ID' => '42', 'comment_content' => '这是一条正常的中文评论。违禁词测试 banned' ),
			self::server()
		);

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertStringContainsString( 'Abuse,100', $result['message'] );
		$this->assertStringNotContainsString( '辱骂违规', $result['message'] );
	}
}
