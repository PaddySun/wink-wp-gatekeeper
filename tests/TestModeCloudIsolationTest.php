<?php
/**
 * TestMode 云审隔离行为级测试（【S6-F4】安全门五件套「零厂商计费」承诺面补全）。
 *
 * 审计实锤（AUDIT-INDEP-2026-11 S6 §2 F4 + S6-BYPASS §3 drill 实测）：test_mode 旁路
 * 此前只覆盖验证码链 3c 真验票位（零厂商计费承诺只覆盖验证码链），步骤 4 云审链照常
 * 外呼烧配额——drill 实测 baidu usage +1。修法（任务书 2026-11-21）：test_mode 安全门
 * 生效期间云审链强制零外呼——每驱动调用前置短路 → verdict=error 零外呼语义（对齐
 * halt_check「跳过调用计为 error」先例；收口走既有云端全故障 fail 口径）。
 *
 * 契约钉：
 *   ① test_mode 激活态：评论链云审零外呼（桩计数=0）+ 逐驱动 error 事件
 *      rule=test_mode_isolated + meta.drill/test_mode 标注（drill 行不进统计）；
 *   ② 收口形态随 fail_mode（review→待审兜底 / open→放行）——与传输故障同形零特判；
 *   ③ 关态行为不变：云审链照常调用、零 isolated 事件；
 *   ④ 验证码链五件套语义不动（3b' 旁路 pass 行仍由 B2GateTriTest 钉——本文件不触碰）。
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

final class TestModeCloudIsolationTest extends TestCase {

	/** @var callable 固定存根哈希（生产 = wp_hash 封装）。 */
	private static $stub_hash;

	public static function setUpBeforeClass(): void {
		self::$stub_hash = static function ( string $data ): string {
			return 'h_' . hash_hmac( 'sha256', $data, 'tm-iso-test-key' );
		};
	}

	/**
	 * 可编程假云端驱动（check 计调用次数；云审隔离态下必须恒 0）。
	 */
	private function fake_cloud( Verdict $result, int &$calls ): ReviewDriverInterface {
		return new class( $result, $calls ) implements ReviewDriverInterface {
			public $calls_ref;
			private $result;
			public function __construct( Verdict $result, int &$calls ) {
				$this->result    = $result;
				$this->calls_ref = &$calls;
			}
			public function check( string $content, array $meta ): Verdict {
				$this->calls_ref++;
				return $this->result;
			}
		};
	}

	/**
	 * 设置树（chain 两驱动形态；test_mode 开关由 overrides 注入）。
	 *
	 * @return array<string, mixed>
	 */
	private static function settings( array $overrides = array() ): array {
		return array_merge( array(
			'gates'  => array( 'comment' => array( 'enabled' => true, 'render_mode' => 'always' ) ),
			'review' => array(
				'local' => array(
					'min_length' => 0, 'max_length' => 1800, 'require_chinese' => 1,
					'custom_banned_words' => '', 'use_wp_keywords' => 1,
				),
				'cloud' => array(
					'enabled'   => true,
					'chain'     => array( 'fake_a', 'fake_b' ),
					'fake_a'    => array( 'fail_mode' => 'review' ),
					'fake_b'    => array( 'fail_mode' => 'review' ),
				),
			),
			'copy'   => array( 'messages' => array() ),
			'stats'  => array( 'enabled' => true, 'pass_sampling' => false ),
		), $overrides );
	}

	/**
	 * test_mode 激活设置注入（since=now-1000——24h 窗内；CommentGate 读时判定消费）。
	 *
	 * @return array<string, mixed>
	 */
	private static function with_test_mode_on( array $settings ): array {
		$settings['captcha']                     = isset( $settings['captcha'] ) && is_array( $settings['captcha'] ) ? $settings['captcha'] : array();
		$settings['captcha']['test_mode']        = true;
		$settings['captcha']['test_mode_since']  = 1731168000 - 1000;
		return $settings;
	}

	private function recorder( array &$log ): callable {
		return static function ( string $stage, Verdict $verdict, array $meta ) use ( &$log ): string {
			$log[] = array( 'stage' => $stage, 'verdict' => $verdict->verdict(), 'driver' => $verdict->driver_id(), 'rule' => $verdict->reason(), 'meta' => $meta );
			return 'WK-20261121-0001';
		};
	}

	/**
	 * 闸装配（captcha=null——评论编排测试形态同 CommentGateChainTest；now 固定=开关 since 对齐）。
	 */
	private function gate( array $settings, array $cloud_chain, array &$log ): CommentGate {
		return new CommentGate(
			$settings,
			null,
			null,
			$this->recorder( $log ),
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return 1731168000;
			},
			null,
			null,
			$cloud_chain
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

	/**
	 * ① test_mode 激活态：云审链零外呼 + 逐驱动 test_mode_isolated error 事件带 drill 标注。
	 */
	public function test_test_mode_active_cloud_chain_zero_outbound_with_isolated_events(): void {
		$log   = array();
		$calls = 0;
		$chain = array(
			'fake_a' => $this->fake_cloud( Verdict::pass( 'fake_a' ), $calls ),
			'fake_b' => $this->fake_cloud( Verdict::pass( 'fake_b' ), $calls ),
		);
		$gate = $this->gate( self::with_test_mode_on( self::settings() ), $chain, $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server(), false );

		$this->assertSame( 0, $calls, '【S6-F4】test_mode 激活态云审链零外呼（桩计数=0——drill 实测 baidu +1 的计费面闭合）' );

		$isolated = array_values( array_filter( $log, static function ( array $row ): bool {
			return 'test_mode_isolated' === $row['rule'];
		} ) );
		$this->assertCount( 2, $isolated, '逐驱动 error 事件（对齐 halt_check 零外呼语义——两驱动各一条）' );
		foreach ( $isolated as $row ) {
			$this->assertSame( 'error', $row['verdict'], 'verdict=error 零外呼语义（error 不参与定级）' );
			$this->assertSame( 'review', $row['stage'], 'stage=review 云审段' );
			$this->assertTrue( ! empty( $row['meta']['drill'] ), 'meta.drill 标注（drill 行不进统计——StatsCache::is_drill 单点豁免）' );
			$this->assertTrue( ! empty( $row['meta']['test_mode'] ), 'meta.test_mode 标注（与 3b\' 旁路行同族标注）' );
		}
		$this->assertSame( 'fake_a', $isolated[0]['driver'], '逐驱动归因（链序第一驱动）' );
		$this->assertSame( 'fake_b', $isolated[1]['driver'], '逐驱动归因（链序第二驱动）' );
	}

	/**
	 * ② 收口形态随 fail_mode：fail-review → 待审兜底（与云端全故障传输形态同形零特判）。
	 */
	public function test_test_mode_active_fail_review_fallback_holds_moderation(): void {
		$log   = array();
		$calls = 0;
		$chain = array( 'fake_a' => $this->fake_cloud( Verdict::pass( 'fake_a' ), $calls ) );
		$settings = self::settings();
		$settings['review']['cloud']['chain']  = array( 'fake_a' );
		$settings['review']['cloud']['fake_b'] = array( 'fail_mode' => 'review' );
		$gate = $this->gate( self::with_test_mode_on( $settings ), $chain, $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server(), false );

		$this->assertSame( 'allow', $result['decision'], 'fail-review 收口：放行决策（评论进 WP 流程）' );
		$this->assertSame( '0', (string) ( $result['commentdata']['comment_approved'] ?? '1' ), 'fail-review 兜底：入待审（comment_approved=0——与云端全故障同形）' );
		$this->assertSame( 'cloud_all_failed', $this->hold_source( $gate ), 'hold 来源=既有云端全故障收口（零新增特判面）' );
	}

	/**
	 * ②b fail-open → 放行（待审兜底不触发）。
	 */
	public function test_test_mode_active_fail_open_allows_without_hold(): void {
		$log   = array();
		$calls = 0;
		$chain = array( 'fake_a' => $this->fake_cloud( Verdict::pass( 'fake_a' ), $calls ) );
		$settings = self::settings();
		$settings['review']['cloud']['chain']  = array( 'fake_a' );
		$settings['review']['cloud']['fake_a'] = array( 'fail_mode' => 'open' );
		$gate = $this->gate( self::with_test_mode_on( $settings ), $chain, $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server(), false );

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertSame( '1', (string) ( $result['commentdata']['comment_approved'] ?? '1' ), 'fail-open：不放待审' );
		$this->assertNull( $this->hold_source( $gate ), '零 hold' );
	}

	/**
	 * ③ 关态行为不变：云审链照常调用（桩计数=1）+ 零 isolated 事件。
	 */
	public function test_test_mode_off_cloud_chain_runs_normally(): void {
		$log   = array();
		$calls = 0;
		$chain = array( 'fake_a' => $this->fake_cloud( Verdict::pass( 'fake_a' ), $calls ) );
		$settings = self::settings();
		$settings['review']['cloud']['chain']  = array( 'fake_a' );
		$settings['review']['cloud']['fake_a'] = array( 'fail_mode' => 'review' );
		$gate = $this->gate( $settings, $chain, $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server(), false );

		$this->assertSame( 1, $calls, '关态：云审链照常外呼（行为不变）' );
		foreach ( $log as $row ) {
			$this->assertNotSame( 'test_mode_isolated', $row['rule'], '关态零 isolated 事件' );
		}
		$this->assertSame( 'allow', $result['decision'] );
		$this->assertNull( $this->hold_source( $gate ), '关态 pass 收口照常放行' );
	}

	/**
	 * hold 来源读取（CommentGate 私有属性——反射断言收口形态）。
	 */
	private function hold_source( CommentGate $gate ): ?string {
		$prop = new \ReflectionProperty( CommentGate::class, 'hold_source' );
		$prop->setAccessible( true );
		$value = $prop->getValue( $gate );
		return ( is_string( $value ) && '' !== $value ) ? $value : null;
	}
}
