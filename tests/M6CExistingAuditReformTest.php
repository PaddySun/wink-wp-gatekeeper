<?php
/**
 * M6 批 C · 存量审核处置与报告重构（0.6.5，2026-11-16——施工图 §3b C-1/C-2）。
 *
 * 覆盖：
 *   C-1 宇宙快照化——发起时固化宇宙 ID 清单进 progress option（F1「23/22」修复）：
 *     worker 只处理清单内 ID / processed 恒 ≤ total / 完成判据=清单全落 meta（含 gone）/
 *     旧 progress（无 ids 键）不迁移——动态探测自然归位 / 宇宙查询 approved-only
 *     （垃圾与待审不翻出来重查——用户原话「不把已经判定为垃圾的翻出来鞭尸」）。
 *   C-2 处置语义变更（用户 2026-11-16 拍板——「仅标记」在存量审计两系统退役）：
 *     内容体检 reject→spam / review→hold（待审）/ pass→不动 / gone→仅记 meta；
 *     meta 新字段 disposed_to（spam|hold|none）；wp_set_comment_status 可逆终态面。
 *
 * 断言翻转注记：M5U3WorkerTest 仅标记钉与本文件语义相反（原钉=定稿 §3 拍板 0.5
 * 「仅标记为唯一默认」；翻钉原因=用户 2026-11-16 拍板处置退役，见施工图 §3b C-2）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Audit\ExistingAuditTask;
use WinkGatekeeper\Audit\ExistingAuditWorker;
use WinkGatekeeper\Verdict;

final class M6CExistingAuditReformTest extends TestCase {

	/**
	 * 构造带桩 Worker 的助手（内存存储 + 可编程序列驱动 + 处置捕获）。
	 *
	 * @param array<string, mixed> $opts stubs/authorings
	 * @return array{0: ExistingAuditWorker, 1: array<string, mixed>} [worker, state]
	 */
	private static function makeWorker( array $opts = array() ): array {
		$state = new \ArrayObject( array(
			'progress'  => false,
			'metas'     => $opts['metas'] ?? array(),
			'comments'  => $opts['comments'] ?? array(),
			'quota'     => $opts['quota'] ?? false,
			'health'    => $opts['health'] ?? null,
			'now'       => $opts['now'] ?? 100000.0,
			'checked'   => array(),
			'disposals' => array(), // [ [id, action], ... ]
		) );

		$n = (int) ( $opts['count'] ?? 0 );
		if ( 0 < $n && array() === $state['comments'] ) {
			for ( $i = 1; $i <= $n; $i++ ) {
				$state['comments'][ $i ] = array( 'author' => '作者' . $i, 'body' => 'body' . $i );
			}
		}

		$driver = $opts['driver'] ?? null;
		if ( null === $driver ) {
			$driver = new class() implements \WinkGatekeeper\Drivers\ReviewDriverInterface {
				public function check( string $content, array $meta ): Verdict {
					return Verdict::reject( 'fake', 'Advertising', array( 'Advertising' ) );
				}
			};
		}
		$factory = ( $opts['driver_null'] ?? false )
			? static fn(): ?array => null
			: static fn(): array => array( 'driver' => $driver, 'id' => 'fake' );

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
				foreach ( array_keys( $state['comments'] ) as $id ) {
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
			static function ( int $id, ?array $m ) use ( &$state ): void {
				if ( null === $m ) {
					unset( $state['metas'][ $id ] );
					return;
				}
				$state['metas'][ $id ] = $m;
			},
			$factory,
			static function () use ( &$state ) {
				return $state['now'];
			},
			static function ( float $s ) use ( &$state ): void {
				$state['now'] += $s;
			},
			static function ( string $id ) use ( &$state ) {
				return $state['quota'];
			},
			static function ( string $id ) use ( &$state ) {
				return $state['health'];
			},
			// 【M6·批C·C-2】处置执行捕获（第 13 注入位）。
			static function ( int $id, string $action ) use ( &$state ): bool {
				$state['disposals'][] = array( $id, $action );
				return true;
			}
		);
		return array( $worker, $state );
	}

	/* ===================================================================
	 * C-1 宇宙快照化（F1「23/22」修复）
	 * =================================================================== */

	public function test_start_snapshots_universe_ids_into_progress(): void {
		list( $w, $s ) = self::makeWorker( array( 'count' => 3 ) );
		$p = $w->start( 3, array( 1, 2, 3 ) );
		self::assertSame( array( 1, 2, 3 ), $p['ids'], '发起时固化宇宙 ID 清单进 progress option（K5 快照意图的真实现）' );
		self::assertSame( 3, $p['total'], 'total=快照清单数（快照权威）' );
		self::assertSame( array( 1, 2, 3 ), $s['progress']['ids'], '快照落盘' );
	}

	public function test_f1_new_comment_after_start_not_swept_in(): void {
		// F1 场景复刻：发起时快照 [1,2,3]（total=22 的缩样），发起后新增评论 4
		// ——worker 只处理清单内 ID，processed 恒 ≤ total，不再出现 23/22。
		list( $w, $s ) = self::makeWorker( array( 'count' => 4 ) );
		$w->start( 3, array( 1, 2, 3 ) ); // 发起时 4 尚不在清单（模拟发起后入库）。
		$r = $w->run_batch();
		self::assertSame( 3, $r['progress']['processed'], '只处理清单内 3 条' );
		self::assertLessThanOrEqual( $r['progress']['total'], $r['progress']['processed'], 'processed 恒 ≤ total' );
		self::assertSame( 'completed', $r['progress']['status'] );
		self::assertArrayNotHasKey( 4, $s['metas'], '清单外新评论零触碰（23/22 根因消除）' );
	}

	public function test_snapshot_completion_requires_all_ids_have_meta_including_gone(): void {
		list( $w, $s ) = self::makeWorker( array( 'count' => 3 ) );
		unset( $s['comments'][2] ); // 2 号在发起后、处理前被删——快照内 gone 收口。
		$w->start( 3, array( 1, 2, 3 ) );
		$r = $w->run_batch();
		self::assertSame( 'completed', $r['progress']['status'], '完成判据=清单全落 meta（含 gone）' );
		self::assertSame( 'gone', $s['metas'][2]['verdict'] ?? '' );
		self::assertSame( 3, $r['progress']['processed'] );
	}

	public function test_snapshot_resume_continues_from_meta_gap(): void {
		// 断点续跑语义在快照面同样成立：清单 [1,2,3]，已落 meta 的 1 号跳过。
		list( $w, $s ) = self::makeWorker( array( 'count' => 3 ) );
		$s['metas'][1] = array( 'verdict' => 'pass', 'driver' => 'old' );
		$w->start( 3, array( 1, 2, 3 ) );
		$r = $w->run_batch();
		self::assertSame( 'completed', $r['progress']['status'] );
		self::assertSame( 'old', $s['metas'][1]['driver'], '已标记行幂等跳过' );
		self::assertSame( 2, $r['progress']['processed'], 'processed 只计本轮实处理（快照权威——旧 meta 不占数）' );
	}

	public function test_legacy_progress_without_ids_still_completes(): void {
		// 旧 progress（无 ids 键——如 B 机在场的 23/22 completed 态）不迁移：动态探测
		// 路径原样保留，随下次运行自然归位。
		list( $w, $s ) = self::makeWorker( array( 'count' => 2 ) );
		$s['progress'] = array(
			'status'     => 'running',
			'total'      => 2,
			'processed'  => 0,
			'driver'     => 'fake',
			'batch_size' => 25,
			'started_at' => 100000,
			'finished_at' => null,
			'verdicts'   => array( 'pass' => 0, 'reject' => 0, 'review' => 0, 'gone' => 0 ),
		);
		$r = $w->run_batch();
		self::assertSame( 'completed', $r['progress']['status'], '旧形态任务自然跑完（零迁移）' );
		self::assertSame( 2, $r['progress']['processed'] );
	}

	/* ===================================================================
	 * C-1 宇宙查询 approved-only（不翻垃圾/待审出来重查）
	 * =================================================================== */

	public function test_universe_queries_are_approved_only(): void {
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/audit/ExistingAuditWorker.php' );
		self::assertIsString( $src );
		self::assertStringNotContainsString( "comment_approved IN ('0','1')", $src, 'approved IN (0,1) 退役——待审不翻出来重查' );
		self::assertSame( 4, substr_count( (string) $src, "comment_approved = '1'" ), '四处宇宙查询（unaudited/unaudited_universe 快照/count/universe reset 面）全部 approved-only' );

		$ua = file_get_contents( dirname( __DIR__ ) . '/includes/urlaudit/UrlAuditWorker.php' );
		self::assertIsString( $ua );
		self::assertStringNotContainsString( "comment_approved IN ('0','1')", $ua, 'backlog_universe_ids 同改——存量网址核查不翻垃圾鞭尸' );
		self::assertStringContainsString( "comment_approved = '1'", $ua, 'backlog 宇宙 approved-only 在场' );
	}

	public function test_unaudited_universe_ids_static_exists(): void {
		// 发起快照数据源（SettingsPage/CLI start 消费）——SQL 形态与 default_unaudited_ids 同闸。
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/audit/ExistingAuditWorker.php' );
		self::assertStringContainsString( 'function unaudited_universe_ids', (string) $src, '发起侧快照方法在场' );
	}

	/* ===================================================================
	 * C-2 处置语义（「仅标记」退役——用户 2026-11-16 拍板）
	 * =================================================================== */

	public function test_disposal_for_verdict_matrix(): void {
		self::assertSame( 'spam', ExistingAuditTask::disposal_for_verdict( 'reject' ), '判违规→垃圾箱' );
		self::assertSame( 'hold', ExistingAuditTask::disposal_for_verdict( 'review' ), '没看清→待审' );
		self::assertSame( 'none', ExistingAuditTask::disposal_for_verdict( 'pass' ), '没问题→不动' );
		self::assertSame( 'none', ExistingAuditTask::disposal_for_verdict( 'gone' ), '已删→仅记 meta' );
		self::assertSame( 'none', ExistingAuditTask::disposal_for_verdict( 'error' ), '错误→零处置（只放大严）' );
		self::assertSame( 'none', ExistingAuditTask::disposal_for_verdict( 'unknown_key' ), '未知→零处置 fail-safe' );
	}

	public function test_disposal_actions_are_reversible_terminal_faces_only(): void {
		// 可逆红线：处置面只允许 spam|hold 两值（垃圾箱可一键恢复、待审可批准）。
		foreach ( array( 'reject', 'review', 'pass', 'gone', 'error' ) as $v ) {
			$d = ExistingAuditTask::disposal_for_verdict( $v );
			self::assertContains( $d, array( 'spam', 'hold', 'none' ), "verdict={$v} 处置值在可逆闭集内" );
		}
	}

	public function test_meta_record_carries_disposed_to(): void {
		$m = ExistingAuditTask::meta_record( 'reject', 'Advertising', array( 'Advertising' ), 'deepseek', 1234, 'spam' );
		self::assertSame( 'spam', $m['disposed_to'], 'meta 新字段 disposed_to（处置结果可溯源）' );
		$gone = ExistingAuditTask::meta_record( 'gone', '', array(), 'deepseek', 1234 );
		self::assertSame( 'none', $gone['disposed_to'], '缺省 none（gone 仅记 meta）' );
	}

	public function test_reject_verdict_disposes_spam(): void {
		list( $w, $s ) = self::makeWorker( array( 'count' => 2 ) ); // 默认驱动恒 reject。
		$w->start( 2, array( 1, 2 ) );
		$w->run_batch();
		self::assertSame( array( array( 1, 'spam' ), array( 2, 'spam' ) ), $s['disposals'], 'reject→wp_set_comment_status spam（垃圾箱）' );
		self::assertSame( 'spam', $s['metas'][1]['disposed_to'] ?? '' );
		self::assertSame( 'spam', $s['metas'][2]['disposed_to'] ?? '' );
	}

	public function test_review_verdict_disposes_hold(): void {
		list( $w, $s ) = self::makeWorker( array(
			'count'  => 1,
			'driver' => new class() implements \WinkGatekeeper\Drivers\ReviewDriverInterface {
				public function check( string $content, array $meta ): Verdict {
					return Verdict::review( 'fake', '拿不准', array() );
				}
			},
		) );
		$w->start( 1, array( 1 ) );
		$w->run_batch();
		self::assertSame( array( array( 1, 'hold' ) ), $s['disposals'], 'review→待审（moderated）' );
		self::assertSame( 'hold', $s['metas'][1]['disposed_to'] ?? '' );
	}

	public function test_pass_and_gone_never_dispose(): void {
		list( $w, $s ) = self::makeWorker( array(
			'count'  => 2,
			'driver' => new class() implements \WinkGatekeeper\Drivers\ReviewDriverInterface {
				public function check( string $content, array $meta ): Verdict {
					return Verdict::pass( 'fake', 'ok' );
				}
			},
		) );
		unset( $s['comments'][2] ); // 2 号 gone。
		$w->start( 2, array( 1, 2 ) );
		$w->run_batch();
		self::assertSame( array(), $s['disposals'], 'pass 与 gone 零处置' );
		self::assertSame( 'none', $s['metas'][1]['disposed_to'] ?? '' );
		self::assertSame( 'none', $s['metas'][2]['disposed_to'] ?? '' );
	}

	public function test_disposal_failure_does_not_block_progress(): void {
		// 处置执行失败（返回 false）不阻塞审计推进——meta 照落、disposed_to 照记
		// （处置为尽力面；下一轮「重新体检」可再收口）。
		$state = new \ArrayObject( array(
			'progress' => false,
			'metas'    => array(),
			'comments' => array( 1 => array( 'author' => '甲', 'body' => '乙' ) ),
			'now'      => 100000.0,
		) );
		$worker = new ExistingAuditWorker(
			array(),
			static fn(): array|false => $state['progress'],
			static function ( array $p ) use ( $state ): void {
				$state['progress'] = $p;
			},
			static fn( int $limit ): array => isset( $state['metas'][1] ) ? array() : array( 1 ), // meta 在=探测面排除（防死循环——同生产 SQL 语义）。
			static function ( int $id ) use ( $state ) {
				return $state['comments'][ $id ] ?? null;
			},
			static function ( int $id ) use ( $state ) {
				return $state['metas'][ $id ] ?? null;
			},
			static function ( int $id, ?array $m ) use ( $state ): void {
				if ( null === $m ) {
					unset( $state['metas'][ $id ] );
					return;
				}
				$state['metas'][ $id ] = $m;
			},
			static fn(): array => array( 'driver' => new class() implements \WinkGatekeeper\Drivers\ReviewDriverInterface {
				public function check( string $content, array $meta ): Verdict {
					return Verdict::reject( 'fake', 'Advertising', array() );
				}
			}, 'id' => 'fake' ),
			static fn(): float => $state['now'],
			static function ( float $s ): void {},
			static fn( string $id ): bool => false,
			static fn( string $id ): ?string => null,
			static fn( int $id, string $action ): bool => false // 处置恒失败。
		);
		$w = $worker;
		$w->start( 1, array( 1 ) );
		$r = $w->run_batch();
		self::assertSame( 'completed', $r['progress']['status'], '处置失败不阻塞推进' );
		self::assertSame( 'spam', $state['metas'][1]['disposed_to'] ?? '', 'disposed_to 照记' );
	}

	/* ===================================================================
	 * C-2 报告口径（处置后统计对账——纯函数面）
	 * =================================================================== */

	public function test_report_view_three_states(): void {
		$empty = ExistingAuditTask::report_view( array( 'status' => 'idle' ), array() );
		self::assertSame( 'empty', $empty['state'], '空态' );

		$running = ExistingAuditTask::report_view( array( 'status' => 'running', 'driver' => 'deepseek', 'started_at' => 100, 'processed' => 5, 'total' => 22 ), array() );
		self::assertSame( 'running', $running['state'], '运行中' );
		self::assertSame( 'deepseek', $running['driver'] );

		$done = ExistingAuditTask::report_view( array( 'status' => 'completed', 'driver' => 'deepseek', 'started_at' => 100, 'finished_at' => 200, 'processed' => 22, 'total' => 22 ), array() );
		self::assertSame( 'completed', $done['state'], 'Done' );
		self::assertSame( 200, $done['finished_at'] );

		$paused = ExistingAuditTask::report_view( array( 'status' => 'quota_paused', 'driver' => 'deepseek', 'started_at' => 100 ), array() );
		self::assertSame( 'running', $paused['state'], '配额暂停仍算运行中（报告非终态）' );
	}

	public function test_report_view_caliber_maps_disposal_semantics(): void {
		// 处置后统计对账：垃圾箱行=reject（已 disposed_to spam）、待审核行=review、
		// 没问题行=pass——三行与处置面一一对应（口径=处理口径而非裸判定堆）。
		$view = ExistingAuditTask::report_view( array( 'status' => 'completed', 'started_at' => 1, 'finished_at' => 2 ), array(
			'pass'   => 3,
			'reject' => 13,
			'review' => 7,
			'gone'   => 2,
			'error'  => 0,
		) );
		self::assertSame( 3, $view['caliber']['pass'], 'Clean (pass)' );
		self::assertSame( 7, $view['caliber']['pending'], '待审核（review——未核实不下结论）' );
		self::assertSame( 13, $view['caliber']['spam'], '垃圾箱（reject——已处置）' );
		self::assertSame( 2, $view['caliber']['gone'], '已删除灰字注脚' );
	}
}
