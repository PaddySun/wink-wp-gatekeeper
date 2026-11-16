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
					\WP_CLI::success( "任务已建立：共 {$p['total']} 条，服务商 {$p['driver']}，单轮 ≤{$p['batch_size']} 条。运行 `wp wink-gatekeeper audit-existing run` 或等待 cron tick。" );
				} catch ( \RuntimeException $e ) {
					// 【M5 修复批·C5】无已配置云审驱动（C1 start 守卫）→ 显式人话报错，
					// 区别于「任务已在运行」竞态——两者处置路径不同（先配置 vs 先 pause）。
					if ( 'no configured cloud review driver' === $e->getMessage() ) {
						\WP_CLI::error( '无已配置云审驱动——先在 Tab③ 配置并启用服务商。' );
					}
					\WP_CLI::error( '任务已在运行（' . $e->getMessage() . '）——先 status 查看，或 pause/reset 后再发起。' );
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
				\WP_CLI::success( null === $p ? '无任务。' : '已暂停（' . ( $p['processed'] ?? 0 ) . '/' . ( $p['total'] ?? 0 ) . '）。resume 继续。' );
				break;
			case 'resume':
				$p = $worker->resume_task();
				if ( null !== $p ) {
					self::schedule_tick();
				}
				\WP_CLI::success( null === $p ? '无任务。' : '已继续——cron tick 已重排，或手动 run。' );
				break;
			case 'reset':
				$count = $worker->reset_task( self::universe_ids() );
				self::unschedule_tick();
				\WP_CLI::success( '标记与进度已作废（曾有任务=' . var_export( $count, true ) . '）——可重新 start。' );
				break;
			default:
				\WP_CLI::error( "未知动词：{$verb}（start|run|status|pause|resume|reset）" );
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
			\WP_CLI::error( '无任务——先 start。' );
		}
		$verdicts = $p['verdicts'] ?? array();
		\WP_CLI::log( sprintf(
			'本轮处理 %d 条 · 累计 %d/%d · %s · pass=%d reject=%d review=%d gone=%d',
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
			\WP_CLI::warning( '配额达限任务已暂停——次日自动恢复（或配额重置后 run 续跑）。' );
		}
		if ( isset( $r['pause_reason'] ) && 'no_driver' === $r['pause_reason'] ) {
			\WP_CLI::warning( '无已配置云审驱动——Tab③ 配置并启用后 resume。' );
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
				$driver = '未配置';
			}
			\WP_CLI::log( sprintf(
				'进度 %d/%d · 服务商 %s · pass=%d reject=%d review=%d gone=%d',
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
