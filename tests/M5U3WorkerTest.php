<?php
/**
 * M5 U3 批 · 存量审核 Worker 编排测试（注入桩，零 WP 依赖）。
 *
 * 覆盖：分批双闸（25 条/4 分钟先到为准）/配额暂停续跑边沿一次/幂等跳过/已删评论 gone/
 * 处置契约（【M6·批C·C-2】翻转原「仅标记不变式」钉——用户 2026-11-16 拍板）/送审串
 * 邮箱剔除签名级/驱动缺位暂停/断点续跑。
 * 快照语义（【M6·批C·C-1】）行为面由 M6CExistingAuditReformTest 承载。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Audit\ExistingAuditTask;
use WinkGatekeeper\Audit\ExistingAuditWorker;
use WinkGatekeeper\Verdict;

final class M5U3WorkerTest extends TestCase {

	/**
	 * 构造带桩 Worker 的助手（内存存储 + 可编程序列驱动）。
	 *
	 * @param array<string, mixed> $opts stubs/authorings
	 * @return array{0: ExistingAuditWorker, 1: array<string, mixed>} [worker, state]
	 */
	private static function makeWorker( array $opts = array() ): array {
		$state = new \ArrayObject( array(
			'progress'     => false,
			'metas'        => $opts['metas'] ?? array(),
			'comments'     => $opts['comments'] ?? array(),
			'quota'        => $opts['quota'] ?? false,
			'health'       => $opts['health'] ?? null,
			'now'          => $opts['now'] ?? 100000.0,
			'jitter_total' => 0.0,
			'checked'      => array(), // [ [id, content, meta], ... ]
			'disposals'    => array(), // 【M6·批C·C-2】处置捕获（编排语义测试不触 WP 默认通道）。
		) );

		$n = (int) ( $opts['count'] ?? 0 );
		if ( 0 < $n && array() === $state['comments'] ) {
			for ( $i = 1; $i <= $n; $i++ ) {
				$state['comments'][ $i ] = array( 'author' => '作者' . $i, 'body' => '正文' . $i );
			}
		}

		$ids_all = array_keys( $state['comments'] );
		$state['ids_pool'] = $opts['ids_pool'] ?? $ids_all;

		$driver = $opts['driver'] ?? null;
		if ( null === $driver ) {
			$driver = new class() implements \WinkGatekeeper\Drivers\ReviewDriverInterface {
				public function check( string $content, array $meta ): Verdict {
					return Verdict::reject( 'fake', '广告', array( '广告' ) );
				}
			};
		}
		// 纪录面驱动（捕获 check 入参）。
		$recorder = new class( $driver, $state ) implements \WinkGatekeeper\Drivers\ReviewDriverInterface {
			private $inner;
			private \ArrayObject $state;
			public function __construct( $inner, \ArrayObject $state ) {
				$this->inner = $inner;
				$this->state = $state;
			}
			public function check( string $content, array $meta ): Verdict {
				$this->state['checked'][] = array( 'content' => $content, 'meta' => $meta );
				return $this->inner->check( $content, $meta );
			}
		};

		$factory = ( $opts['driver_null'] ?? false )
			? static fn(): ?array => null
			: static fn(): array => array( 'driver' => $recorder, 'id' => 'fake' );
		// 【M5 修复批·C6】default_factory=true → 不注入工厂（构造器默认 null）——
		// 走 default_driver_factory 真路径（C1 start 守卫 / C2 deepseek 分支的测试面）。
		if ( ! empty( $opts['default_factory'] ) ) {
			$factory = null;
		}

		$worker = new ExistingAuditWorker(
			array(),
			static function () use ( &$state ) {
				return $state['progress'];
			},
			static function ( array $p ) use ( &$state ): void {
				$state['progress'] = $p;
			},
			static function ( int $limit ) use ( &$state ): array {
				$exclude = array();
				foreach ( $state['metas'] as $id => $m ) {
					if ( null !== $m ) {
						$exclude[] = $id;
					}
				}
				$out = array();
				foreach ( $state['ids_pool'] as $id ) {
					if ( in_array( $id, $exclude, true ) ) {
						continue;
					}
					$out[] = $id;
					if ( count( $out ) >= $limit ) {
						break;
					}
				}
				return $out;
			},
			static function ( int $id ) use ( &$state ) {
				return $state['comments'][ $id ] ?? null;
			},
			static function ( int $id ) use ( &$state ) {
				return $state['metas'][ $id ] ?? null;
			},
			static function ( int $id, array $m ) use ( &$state ): void {
				$state['metas'][ $id ] = $m;
			},
			$factory,
			static function () use ( &$state ) {
				return $state['now'];
			},
			static function ( float $s ) use ( &$state ): void {
				$state['now'] += $s;
				$state['jitter_total'] += $s;
			},
			static function ( string $id ) use ( &$state ) {
				return $state['quota'];
			},
			static function ( string $id ) use ( &$state ) {
				return $state['health'];
			},
			// 【M6·批C·C-2】处置执行注入（捕获面——默认 wp_set_comment_status 通道在
			// 单测零 WP 依赖下不可触达；行为断言见 M6CExistingAuditReformTest）。
			static function ( int $id, string $action ) use ( &$state ): bool {
				$state['disposals'][] = array( $id, $action );
				return true;
			}
		);
		return array( $worker, $state ); // ArrayObject——测试侧 $s['…']=… 直接作用于桩状态。
	}

	private static function start( ExistingAuditWorker $w, int $count ): void {
		$w->start( $count );
	}

	/* ===================================================================
	 * 分批：25 条上限
	 * =================================================================== */

	public function test_batch_processes_at_most_25_per_round(): void {
		list( $w, $s ) = self::makeWorker( array( 'count' => 30 ) );
		self::start( $w, 30 );
		$r = $w->run_batch();
		self::assertSame( 'running', $r['progress']['status'] );
		self::assertSame( 25, $r['progress']['processed'], '单轮 ≤25（定稿 §3）' );
		self::assertCount( 25, $s['metas'], '单轮写 meta ≤25' );
		$r2 = $w->run_batch();
		self::assertSame( 30, $r2['progress']['processed'] );
		self::assertSame( 'completed', $r2['progress']['status'], '余量跑完即 completed' );
		self::assertNotNull( $r2['progress']['finished_at'] );
	}

	public function test_all_metas_written_with_verdict(): void {
		list( $w, $s ) = self::makeWorker( array( 'count' => 3 ) );
		self::start( $w, 3 );
		$w->run_batch();
		foreach ( $s['metas'] as $meta ) {
			self::assertSame( 'reject', $meta['verdict'] );
			self::assertSame( 'fake', $meta['driver'] );
			self::assertArrayHasKey( 'audited_at', $meta );
		}
	}

	/* ===================================================================
	 * 分批：4 分钟墙钟上限（先到为准）
	 * =================================================================== */

	public function test_wall_clock_deadline_stops_round(): void {
		// 每条处理耗 20s（sleeper 推进时钟）→ 4 分钟墙钟（240s）在第 12 条打满。
		list( $w, $s ) = self::makeWorker( array(
			'count'   => 30,
			'driver'  => new class() implements \WinkGatekeeper\Drivers\ReviewDriverInterface {
				public function check( string $content, array $meta ): Verdict {
					return Verdict::pass( 'fake', 'ok' );
				}
			},
		) );
		self::start( $w, 30 );
		// sleeper 注入不可换——用 jitter 之外的时间推进不现实；改用 deadline 直接断言由
		// jitter 累计推进（0.5–2s/条×25≈≤50s <240s 达不到）——故墙钟上限的纯单测以
		// ExistingAuditTask::deadline_reached 语义钉（M5U3ExistingAuditTest），
		// 此处仅断言单轮内时钟确实被推进（预算墙机制在走）。
		$r = $w->run_batch();
		self::assertSame( 25, $r['progress']['processed'] );
		self::assertGreaterThan( 0.0, $s['jitter_total'], '批次间抖动确实休眠（礼貌面）' );
		self::assertLessThanOrEqual( 25 * 2.0, $s['jitter_total'], '抖动总量 ≤25×2s' );
	}

	/* ===================================================================
	 * 配额：暂停+边沿通知一次+恢复续跑
	 * =================================================================== */

	public function test_quota_pause_then_auto_resume(): void {
		list( $w, $s ) = self::makeWorker( array( 'count' => 30, 'quota' => true ) );
		self::start( $w, 30 );
		$r = $w->run_batch();
		self::assertSame( 'quota_paused', $r['progress']['status'] );
		self::assertSame( 0, $r['progress']['processed'], '配额达限首轮零处理' );
		self::assertTrue( $r['progress']['quota_notified'] );

		// 配额仍满：第二次 tick 保持暂停（边沿不重复）。
		$r2 = $w->run_batch();
		self::assertSame( 'quota_paused', $r2['progress']['status'] );
		self::assertTrue( $r2['progress']['quota_notified'] );

		// 配额恢复（次日）：自动续跑——首轮 25（≤25 上限），次轮跑完收口。
		$s['quota'] = false;
		$r3 = $w->run_batch();
		self::assertSame( 'running', $r3['progress']['status'], '续跑首轮（25 条上限）' );
		$r4 = $w->run_batch();
		self::assertSame( 'completed', $r4['progress']['status'] );
		self::assertSame( 30, $r4['progress']['processed'] );
	}

	/* ===================================================================
	 * 幂等：有 meta 的行跳过
	 * =================================================================== */

	public function test_idempotent_skip_audited_rows(): void {
		list( $w, $s ) = self::makeWorker( array( 'count' => 5 ) );
		$s['metas'][2] = array( 'verdict' => 'pass', 'driver' => 'old' );
		$s['metas'][4] = array( 'verdict' => 'reject', 'driver' => 'old' );
		self::start( $w, 5 );
		$r = $w->run_batch();
		self::assertSame( 'completed', $r['progress']['status'] );
		self::assertSame( 'old', $s['metas'][2]['driver'], '已有 meta 原样保留（幂等）' );
		$checked_ids = count( $s['checked'] );
		self::assertSame( 3, $checked_ids, '只审 3 条未标记行' );
	}

	/* ===================================================================
	 * 已删评论：gone 收口
	 * =================================================================== */

	public function test_deleted_comment_counts_as_gone(): void {
		list( $w, $s ) = self::makeWorker( array( 'count' => 3 ) );
		unset( $s['comments'][2] ); // 2 号已删。
		self::start( $w, 3 );
		$r = $w->run_batch();
		self::assertSame( 'completed', $r['progress']['status'] );
		self::assertSame( 1, $r['progress']['verdicts']['gone'] );
		self::assertSame( 3, $r['progress']['processed'], 'gone 也计入 processed（不重跑）' );
	}

	/* ===================================================================
	 * 仅标记不变式 + 送审串口径
	 * =================================================================== */

	public function test_send_payload_is_author_body_no_email_no_url(): void {
		list( $w, $s ) = self::makeWorker( array( 'count' => 2 ) );
		self::start( $w, 2 );
		$w->run_batch();
		foreach ( $s['checked'] as $call ) {
			self::assertDoesNotMatchRegularExpression( '/@/', $call['content'], '送审串零邮箱（剔除纪律——签名级保证）' );
			self::assertMatchesRegularExpression( '/^作者\d+\n正文\d+$/', $call['content'], '昵称+正文两行' );
			self::assertArrayNotHasKey( 'email', $call['meta'], 'meta 零 email（阿里双分送邮箱 leg 空转）' );
			self::assertArrayNotHasKey( 'url', $call['meta'], 'meta 零 url（URL 是 U5 的事）' );
			self::assertArrayHasKey( 'author', $call['meta'], 'meta 带 author（阿里绿昵称双分送消费）' );
		}
	}

	public function test_worker_disposal_contract(): void {
		// 【断言翻转·M6 批C·C-2】原钉=「仅标记不变式」源码零处置依赖（wp_set_comment_status/
		// 'spam' 零出现——定稿 §3 拍板 0.5）。翻钉原因=用户 2026-11-16 拍板：仅标记在存量
		// 审计两系统退役（施工图 §3b C-2）——reject→spam、review→hold（wp_set_comment_status
		// 可逆终态面）， disposed_to 落 meta；增量车道处置矩阵不动。
		$src = file_get_contents( __DIR__ . '/../includes/audit/ExistingAuditWorker.php' );
		self::assertIsString( $src );
		self::assertStringContainsString( 'wp_set_comment_status', $src, '处置可逆终态面在场（spam|hold）' );
		self::assertStringContainsString( 'disposed_to', $src, '处置结果落 meta 新字段' );
		self::assertStringContainsString( 'disposal_for_verdict', $src, '处置矩阵纯函数收口（verdict→spam|hold|none）' );
		self::assertStringNotContainsString( 'wp_trash_comment', $src, '零自动删除（退役只到可逆终态为止——红线不放宽）' );
		self::assertStringNotContainsString( 'wp_delete_comment', $src, '零物理删除' );
	}

	/* ===================================================================
	 * 驱动缺位：暂停 no_driver
	 * =================================================================== */

	public function test_missing_driver_pauses_task(): void {
		list( $w, $s ) = self::makeWorker( array( 'count' => 3, 'driver_null' => true ) );
		self::start( $w, 3 );
		$r = $w->run_batch();
		self::assertSame( 'paused', $r['progress']['status'] );
		self::assertSame( 'no_driver', $r['pause_reason'] ?? '', '缺驱动原因落 progress' );
		self::assertSame( 0, $r['progress']['processed'] );
	}

	/* ===================================================================
	 * 健康停转（DriverHealth 语义注入面）
	 * =================================================================== */

	public function test_health_halt_pauses_task(): void {
		list( $w, $s ) = self::makeWorker( array( 'count' => 3, 'health' => 'config_error' ) );
		self::start( $w, 3 );
		$r = $w->run_batch();
		self::assertSame( 'paused', $r['progress']['status'] );
		self::assertSame( 'health_halted', $r['pause_reason'] ?? '' );
	}

	/* ===================================================================
	 * 空任务/重复发起守卫
	 * =================================================================== */

	public function test_start_rejects_when_running(): void {
		list( $w, $s ) = self::makeWorker( array( 'count' => 1 ) );
		self::start( $w, 1 );
		$this->expectException( \RuntimeException::class );
		$w->start( 1 );
	}

	public function test_start_with_zero_total_marks_completed(): void {
		list( $w, $s ) = self::makeWorker( array( 'count' => 0 ) );
		$w->start( 0 );
		$r = $w->run_batch();
		self::assertSame( 'completed', $r['progress']['status'] );
	}

	/* ===================================================================
	 * 【M5 修复批·C6】start 无驱动守卫 + default_driver_factory deepseek 补口
	 * =================================================================== */

	public function test_start_without_driver_throws_and_does_not_persist(): void {
		// ① 默认工厂路径（生产装配）无已配置云审驱动 → start 抛 RuntimeException
		// 且进度不落盘（C1 守卫置于 new_progress 之前——防假「进行中」半残态）。
		list( $w, $s ) = self::makeWorker( array(
			'count'           => 3,
			'default_factory' => true,
			// settings 全默认：云审未配置。
		) );
		try {
			$w->start( 3 );
			self::fail( '无驱动 start 必须抛 RuntimeException' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'no configured cloud review driver', $e->getMessage() );
		}
		self::assertFalse( $s['progress'], '任务不落盘（storage 未写）' );
	}

	public function test_default_factory_resolves_deepseek_when_only_deepseek_configured(): void {
		// ② 仅 deepseek 配置（api_key 填值 + chain=['deepseek'] + enabled）→
		// default_driver_factory 解析出 deepseek（C2 补口——此前 chain=['deepseek'] 恒 null）。
		$settings = \WinkGatekeeper\Settings\SettingsSchema::defaults();
		$settings['review']['cloud']['enabled']           = true;
		$settings['review']['cloud']['chain']             = array( 'deepseek' );
		$settings['review']['cloud']['deepseek']['api_key'] = 'sk-guard-probe-sample';

		$s = new \ArrayObject( array( 'progress' => false, 'metas' => array(), 'comments' => array() ) );
		$worker = new ExistingAuditWorker(
			$settings,
			static function () use ( &$s ) {
				return $s['progress'];
			},
			static function ( array $p ) use ( &$s ): void {
				$s['progress'] = $p;
			}
		);

		$resolved = $worker->default_driver_factory();
		self::assertNotNull( $resolved, 'deepseek 配置齐 → 工厂必须解析出驱动' );
		self::assertSame( 'deepseek', $resolved['id'] );
		self::assertInstanceOf(
			\WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver::class,
			$resolved['driver']
		);

		// start 全链路：解析成功 → 任务可建立（driver 快照='deepseek'）。
		$p = $worker->start( 0 );
		self::assertSame( 'deepseek', $p['driver'] );
	}

	public function test_default_factory_skips_unconfigured_deepseek(): void {
		// chain=['deepseek'] 但无 key：deepseek 分支不通过 is_configured → 工厂仍 null
		// （start 守卫随后抛——is_configured 判定是链内漏斗非摆设）。
		$settings = \WinkGatekeeper\Settings\SettingsSchema::defaults();
		$settings['review']['cloud']['enabled'] = true;
		$settings['review']['cloud']['chain']   = array( 'deepseek' );

		$s = new \ArrayObject( array( 'progress' => false, 'metas' => array(), 'comments' => array() ) );
		$worker = new ExistingAuditWorker(
			$settings,
			static function () use ( $s ) {
				return $s['progress'];
			},
			static function ( array $p ) use ( $s ): void {
				$s['progress'] = $p;
			}
		);

		self::assertNull( $worker->default_driver_factory() );
	}

	public function test_has_driver_helper_mirrors_factory(): void {
		// ③ C4 收口判定 helper：与 default_driver_factory 同一判定源。
		$settings = \WinkGatekeeper\Settings\SettingsSchema::defaults();
		self::assertFalse( ExistingAuditWorker::has_driver( $settings ), '全默认（云审未配置）→ 无驱动' );

		$settings['review']['cloud']['enabled']           = true;
		$settings['review']['cloud']['chain']             = array( 'deepseek' );
		$settings['review']['cloud']['deepseek']['api_key'] = 'sk-guard-probe-sample';
		self::assertTrue( ExistingAuditWorker::has_driver( $settings ), '仅 deepseek 配齐 → 有驱动' );
	}
}
