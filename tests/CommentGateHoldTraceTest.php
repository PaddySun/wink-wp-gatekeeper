<?php
/**
 * 待审收口落事件单元测试（02 §3.1 v1.4·R1 G1；F45；04 §3 写入矩阵 review 行）。
 *
 * 三种待审可区分：核心首评待审（不产生插件事件）/ 云端 review 判定（meta.source='cloud'）/
 * 云端全故障兜底（meta.source='cloud_all_failed'，verdict=error）。
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

final class CommentGateHoldTraceTest extends TestCase {

	private static $stub_hash;

	public static function setUpBeforeClass(): void {
		self::$stub_hash = static function ( string $data ): string {
			return 'h_' . hash_hmac( 'sha256', $data, 'hold-trace-key' );
		};
	}

	private function fake_cloud( Verdict $result, ?array $meta = null ): ReviewDriverInterface {
		return new class( $result, $meta ) implements ReviewDriverInterface {
			private $result;
			private $meta;
			public function __construct( Verdict $result, ?array $meta ) {
				$this->result = $result;
				$this->meta   = $meta;
			}
			public function check( string $content, array $meta ): Verdict {
				return $this->result;
			}
			public function last_check_meta(): array {
				return $this->meta ?? array();
			}
		};
	}

	private static function settings( string $fail_mode = 'review' ): array {
		return array(
			'gates'  => array( 'comment' => array( 'enabled' => true, 'render_mode' => 'always' ) ),
			'review' => array(
				'local' => array( 'min_length' => 0, 'max_length' => 1800, 'require_chinese' => 1, 'custom_banned_words' => '', 'use_wp_keywords' => 0 ),
				'cloud' => array( 'enabled' => true, 'chain' => array( 'tencent_tms' ), 'tencent_tms' => array( 'fail_mode' => $fail_mode ) ),
			),
			'copy'   => array( 'messages' => array() ),
			'stats'  => array( 'enabled' => true, 'pass_sampling' => false ),
		);
	}

	private function gate( array $settings, array $cloud_chain, array &$log ): CommentGate {
		return new CommentGate(
			$settings,
			null,
			null,
			static function ( string $stage, Verdict $verdict, array $meta ) use ( &$log ): string {
				$log[] = array( 'stage' => $stage, 'verdict' => $verdict->verdict(), 'driver' => $verdict->driver_id(), 'rule' => $verdict->reason(), 'hits' => $verdict->hits(), 'meta' => $meta );
				return 'WK-20261110-0099';
			},
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return 1731168000;
			},
			null,          // context_provider.
			null,          // archiver（收口测试不留档）.
			$cloud_chain   // 云端链注入（C4 构造参数——active_cloud_chain_ids 消费）.
		);
	}

	private static function commentdata(): array {
		return array( 'comment_post_ID' => '42', 'comment_content' => '正常中文评论内容', 'comment_author' => '张三', 'comment_author_email' => 'z@example.com' );
	}

	private static function request(): array {
		return array( 'comment_post_ID' => '42' );
	}

	private static function server(): array {
		return array( 'REMOTE_ADDR' => '203.0.113.9' );
	}

	public function test_cloud_review_hold_records_source_cloud_event(): void {
		$log  = array();
		$gate = $this->gate( self::settings(), array( 'tencent_tms' => $this->fake_cloud( Verdict::review( 'tencent_tms', 'Ad,60', array( 'Ad' ) ) ) ), $log );

		$gate->orchestrate( self::commentdata(), self::request(), self::server() );

		// 收口事件：hold_moderation 置位消费（filter_pre_comment_approved 降级）时落一行 meta.source='cloud'。
		$approved = $gate->filter_pre_comment_approved( 1, self::commentdata() );
		$this->assertSame( '0', $approved );

		$hold_events = array_values( array_filter( $log, static function ( array $row ): bool {
			return isset( $row['meta']['source'] );
		} ) );
		$this->assertCount( 1, $hold_events, '收口事件恰好一行（review 判定事件本身不带 source）' );
		$this->assertSame( 'cloud', $hold_events[0]['meta']['source'] );
		$this->assertSame( 'review', $hold_events[0]['stage'] );
		$this->assertSame( 'review', $hold_events[0]['verdict'] );
		$this->assertSame( 'tencent_tms', $hold_events[0]['driver'], '归因到产出 review 判定的驱动' );
		$this->assertSame( 42, $hold_events[0]['meta']['post_id'], '对账字段：post_id' );
	}

	public function test_all_failed_hold_records_source_cloud_all_failed_event(): void {
		$log  = array();
		$gate = $this->gate( self::settings( 'review' ), array( 'tencent_tms' => $this->fake_cloud( Verdict::error( 'tencent_tms', 'remote_semantic', array( 'remote_semantic' ) ) ) ), $log );

		$gate->orchestrate( self::commentdata(), self::request(), self::server() );
		$approved = $gate->filter_pre_comment_approved( 1, self::commentdata() );
		$this->assertSame( '0', $approved );

		$hold_events = array_values( array_filter( $log, static function ( array $row ): bool {
			return isset( $row['meta']['source'] );
		} ) );
		$this->assertCount( 1, $hold_events );
		$this->assertSame( 'cloud_all_failed', $hold_events[0]['meta']['source'] );
		$this->assertSame( 'error', $hold_events[0]['verdict'], '云端全故障收口事件 verdict=error（04 §3）' );
	}

	public function test_fail_open_does_not_record_hold_event(): void {
		$log  = array();
		$gate = $this->gate( self::settings( 'open' ), array( 'tencent_tms' => $this->fake_cloud( Verdict::error( 'tencent_tms', 'remote_transport' ) ) ), $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertArrayNotHasKey( 'comment_approved', $result['commentdata'] );
		$sourced = array_filter( $log, static function ( array $row ): bool {
			return isset( $row['meta']['source'] );
		} );
		$this->assertSame( array(), $sourced, 'fail-open 不入待审 → 无收口事件（error 事件照记）' );
		$this->assertNotEmpty( $log, 'error 事件照记（fail-open 不等于无痕）' );
	}

	public function test_hold_event_recorded_once_even_when_core_already_holds(): void {
		// 核心原生待审（approved=0）：filter 只降不升 → 不降级，但 hold_moderation 置位即消费，
		// 收口事件照落一行（云端 review 判定确实发生，来源可查——R1 G1「置位时落事件」字面语义）。
		$log  = array();
		$gate = $this->gate( self::settings(), array( 'tencent_tms' => $this->fake_cloud( Verdict::review( 'tencent_tms', 'Ad,60' ) ) ), $log );

		$gate->orchestrate( self::commentdata(), self::request(), self::server() );
		$this->assertSame( '0', $gate->filter_pre_comment_approved( '0', self::commentdata() ), '核心原生待审保持（只降不升）' );

		$sourced = array_filter( $log, static function ( array $row ): bool {
			return isset( $row['meta']['source'] );
		} );
		$this->assertCount( 1, $sourced, 'hold 消费一次即记一行（单请求单评论语义）' );

		// 再消费一次（异常双触发场景）：hold 标志已自清 → 原样透传核心判定，不重复落事件。
		$this->assertSame( '0', $gate->filter_pre_comment_approved( '0', self::commentdata() ) );
		$sourced = array_filter( $log, static function ( array $row ): bool {
			return isset( $row['meta']['source'] );
		} );
		$this->assertCount( 1, $sourced, 'hold 标志消费后自清，不重复落事件' );
	}

	public function test_wp7_double_filter_invocation_keeps_downgrade_and_single_event(): void {
		// 【真机补注 2026-11-10（IMPL-B1）】WP 7.x wp_new_comment 对 pre_comment_approved
		// 两度回调：wp_allow_comment 首算（本闸降级 '0'）→ wp_check_comment_data 重算
		// （comment_whitelist 命中回 1——hold 已消费时降级被覆盖=真机漏待审根因）。
		// 收口语义：hold 标志持续压低两次回调（approved=1 恒降 '0'）、收口事件仍只落一行。
		$log  = array();
		$gate = $this->gate( self::settings(), array( 'tencent_tms' => $this->fake_cloud( Verdict::review( 'tencent_tms', 'Ad,60' ) ) ), $log );

		$gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( '0', $gate->filter_pre_comment_approved( 1, self::commentdata() ), '首算（wp_allow_comment）：降级入待审' );
		$this->assertSame( '0', $gate->filter_pre_comment_approved( 1, self::commentdata() ), '重算（wp_check_comment_data，whitelist 回 1）：持续压低' );

		$sourced = array_filter( $log, static function ( array $row ): bool {
			return isset( $row['meta']['source'] );
		} );
		$this->assertCount( 1, $sourced, '双回调只落一行收口事件（闩锁）' );
	}

	public function test_local_only_pass_records_no_hold_event(): void {
		// 无云端链（或全部未装配）：纯本地 pass → 无 hold、无收口事件（核心首评待审不产生插件事件）。
		$log  = array();
		$gate = $this->gate( self::settings(), array(), $log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertSame( array(), $log, '核心首评待审可区分：插件零事件' );
	}
}
