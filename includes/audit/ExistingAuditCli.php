<?php
/**
 * U3 存量审核 wp-cli 命令（M5 定稿 §3 双入口之二，2026-11-14）。
 *
 * 用法（bwp-wp 容器内 www-data 身份）：
 *   wp wink-gatekeeper audit-existing start   # 建任务（快照 total=approve+hold 全量）
 *   wp wink-gatekeeper audit-existing run     # 跑一轮分批（≤25 条或 ≤4 分钟）
 *   wp wink-gatekeeper audit-existing status  # 进度查看
 *   wp wink-gatekeeper audit-existing pause   # 手动暂停
 *   wp wink-gatekeeper audit-existing resume  # 继续（重排 cron tick）
 *   wp wink-gatekeeper audit-existing reset   # 重新体检（清全部标记+进度作废——二次确认由调用者负责）
 *
 * CLI 通道不依赖 WP-Cron 可靠性（B 机 cron 形态未知——B 机交付报告 wp-cron 语义
 * 未定论；CLI 是真机验收与无人值守的主通道，cron 是站长侧补充）。
 *
 * @package WinkGatekeeper\Audit
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（S2-F1 纵深加固——类文件直访零执行，守卫为纵深面）。
}

final class ExistingAuditCli {

	/**
	 * 注册命令（Bootstrap::boot 内 WP_CLI 守卫调用）。
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}
		\WP_CLI::add_command( 'wink-gatekeeper', self::class );
	}

	/**
	 * wp wink-gatekeeper audit-existing <verb>
	 *
	 * @subcommand audit-existing
	 *
	 * @param mixed[]              $args       位置参数（[verb]）。
	 * @param array<string, mixed> $assoc_args 关联参数。
	 * @return void
	 */
	public function audit_existing( $args, $assoc_args = array() ): void {
		$verb   = is_array( $args ) && isset( $args[0] ) ? (string) $args[0] : 'status';
		$worker = self::worker();

		switch ( $verb ) {
			case 'start':
				try {
					// 【M6·批C·C-1】宇宙快照化：发起时固化 ID 清单（F1「23/22」修复）。
					$universe = ExistingAuditWorker::unaudited_universe_ids();
					$p        = $worker->start( count( $universe ), $universe );
					self::schedule_tick();
					\WP_CLI::success( __( 'Task created: ', 'wink-wp-gatekeeper' ) . $p['total'] . __( ' comments in total, provider ', 'wink-wp-gatekeeper' ) . $p['driver'] . __( ', at most ', 'wink-wp-gatekeeper' ) . $p['batch_size'] . __( ' per round. Run `wp wink-gatekeeper audit-existing run` or wait for the next cron tick.', 'wink-wp-gatekeeper' ) );
				} catch ( \RuntimeException $e ) {
					// 【M5 修复批·C5】无已配置云审驱动（C1 start 守卫）→ 显式人话报错，
					// 区别于「任务已在运行」竞态——两者处置路径不同（先配置 vs 先 pause）。
					if ( 'no configured cloud review driver' === $e->getMessage() ) {
						\WP_CLI::error( __( 'No cloud review driver configured — configure and enable a provider on Tab 3 first.', 'wink-wp-gatekeeper' ) );
					}
					\WP_CLI::error( __( 'A task is already running (', 'wink-wp-gatekeeper' ) . $e->getMessage() . __( ') — check status first, or pause/reset before starting again.', 'wink-wp-gatekeeper' ) );
				}
				break;
			case 'run':
				self::unschedule_tick();
				$r = $worker->run_batch();
				self::report_round( $r );
				break;
			case 'status':
				self::report_status( $worker->status() );
				break;
			case 'pause':
				$p = $worker->pause_task();
				self::unschedule_tick();
				\WP_CLI::success( null === $p ? __( 'No task.', 'wink-wp-gatekeeper' ) : __( 'Paused (', 'wink-wp-gatekeeper' ) . ( $p['processed'] ?? 0 ) . '/' . ( $p['total'] ?? 0 ) . __( '). Run resume to continue.', 'wink-wp-gatekeeper' ) );
				break;
			case 'resume':
				$p = $worker->resume_task();
				if ( null !== $p ) {
					self::schedule_tick();
				}
				\WP_CLI::success( null === $p ? __( 'No task.', 'wink-wp-gatekeeper' ) : __( 'Resumed — the cron tick is re-scheduled, or run manually.', 'wink-wp-gatekeeper' ) );
				break;
			case 'reset':
				$count = $worker->reset_task( self::universe_ids() );
				self::unschedule_tick();
				\WP_CLI::success( __( 'Marks and progress discarded (had a task=', 'wink-wp-gatekeeper' ) . var_export( $count, true ) . __( ') — you can start again.', 'wink-wp-gatekeeper' ) );
				break;
			default:
				\WP_CLI::error( __( 'Unknown verb: ', 'wink-wp-gatekeeper' ) . $verb . '（start|run|status|pause|resume|reset）' );
		}
	}

	/**
	 * 单轮结果汇报。
	 *
	 * @param array<string, mixed> $r run_batch 结果。
	 * @return void
	 */
	private static function report_round( array $r ): void {
		$p = $r['progress'];
		if ( ( $p['status'] ?? '' ) === 'idle' ) {
			\WP_CLI::error( __( 'No task — run start first.', 'wink-wp-gatekeeper' ) );
		}
		$verdicts = $p['verdicts'] ?? array();
		\WP_CLI::log( sprintf(
			/* translators: 1: 本轮处理数 2: 已处理 3: 总数 4: 状态 5-8: 判定计数 */ _n( 'Processed %1$d comment this round · cumulative %2$d/%3$d · %4$s · pass=%5$d reject=%6$d review=%7$d gone=%8$d', 'Processed %1$d comments this round · cumulative %2$d/%3$d · %4$s · pass=%5$d reject=%6$d review=%7$d gone=%8$d', ( $r['processed_this_round'] ?? 0 ), 'wink-wp-gatekeeper' ),
			$r['processed_this_round'] ?? 0,
			$p['processed'] ?? 0,
			$p['total'] ?? 0,
			$p['status'] ?? '?',
			$verdicts['pass'] ?? 0,
			$verdicts['reject'] ?? 0,
			$verdicts['review'] ?? 0,
			$verdicts['gone'] ?? 0
		) );
		if ( 'quota_paused' === ( $p['status'] ?? '' ) ) {
			\WP_CLI::warning( __( 'Task paused at the quota limit — it resumes automatically tomorrow (or run again after the quota resets).', 'wink-wp-gatekeeper' ) );
		}
		if ( isset( $r['pause_reason'] ) && 'no_driver' === $r['pause_reason'] ) {
			\WP_CLI::warning( __( 'No cloud review driver configured — configure and enable one on Tab 3, then resume.', 'wink-wp-gatekeeper' ) );
		}
	}

	/**
	 * @param array<string, mixed> $p 进度。
	 * @return void
	 */
	private static function report_status( array $p ): void {
		\WP_CLI::log( 'status=' . ( $p['status'] ?? 'idle' ) );
		if ( 'idle' !== ( $p['status'] ?? 'idle' ) ) {
			// 【M5 修复批·C5】driver 空串（半残任务）→ 人话「未配置」，不裸出 '-' 占位。
			$driver = (string) ( $p['driver'] ?? '' );
			if ( '' === $driver ) {
				$driver = __( 'Not configured', 'wink-wp-gatekeeper' );
			}
			\WP_CLI::log( sprintf(
				/* translators: 1: 已处理 2: 总数 3: 服务商 4-7: 判定计数 */ __( 'Progress %1$d/%2$d · provider %3$s · pass=%4$d reject=%5$d review=%6$d gone=%7$d', 'wink-wp-gatekeeper' ),
				$p['processed'] ?? 0,
				$p['total'] ?? 0,
				$driver,
				$p['verdicts']['pass'] ?? 0,
				$p['verdicts']['reject'] ?? 0,
				$p['verdicts']['review'] ?? 0,
				$p['verdicts']['gone'] ?? 0
			) );
		}
	}

	/**
	 * 生产 worker（全默认 WP 通道）。
	 *
	 * @return ExistingAuditWorker
	 */
	private static function worker(): ExistingAuditWorker {
		$stored  = get_option( \WinkGatekeeper\Settings\SettingsPage::OPTION_NAME, array() );
		$merged  = \WinkGatekeeper\Settings\SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() );
		return new ExistingAuditWorker( $merged );
	}

	/**
	 * universe 全量 id（reset 清标记用）。
	 *
	 * @return int[]
	 */
	private static function universe_ids(): array {
		return ExistingAuditWorker::universe_ids();
	}

	/**
	 * 排 cron tick（start/resume 侧；幂等——先清后排）。
	 *
	 * @return void
	 */
	private static function schedule_tick(): void {
		if ( ! wp_next_scheduled( ExistingAuditTask::HOOK_TICK ) ) {
			wp_schedule_event( time() + 60, ExistingAuditTask::CRON_INTERVAL, ExistingAuditTask::HOOK_TICK );
		}
	}

	/**
	 * 清 cron tick（run/pause/reset 侧——CLI 通道自跑，cron 补充面）。
	 *
	 * @return void
	 */
	private static function unschedule_tick(): void {
		$ts = wp_next_scheduled( ExistingAuditTask::HOOK_TICK );
		if ( $ts ) {
			wp_unschedule_event( $ts, ExistingAuditTask::HOOK_TICK );
		}
	}
}
