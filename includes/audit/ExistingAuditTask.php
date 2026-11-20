<?php
/**
 * U3 存量/全局评论审核·任务纯逻辑层（M5 定稿 §3，2026-11-14）。
 *
 * 定位铁律（newswright §1.1）：审核在后台 worker 跑，**不在管理员请求线程**——Tab⑦ 发起
 * 只建任务（进度快照），抓取/送审全部在 cron/CLI worker 分批执行。
 *
 * 处置策略（【M6·批C·C-2】2026-11-16 用户拍板——「仅标记唯一默认」在存量审计两系统
 * 退役）：reject→垃圾箱（spam）、review→待审（hold）、pass→不动、gone→仅记 meta；
 * 全部走 wp_set_comment_status 可逆终态面（垃圾箱可一键恢复、待审可批准），处置结果
 * 落 meta 新字段 disposed_to（spam|hold|none）可溯源。原定稿 §3 拍板 0.5「仅标记」
 * 自此退役（增量车道处置矩阵不动）。
 *
 * 送审串（定稿 §3）：单条「昵称+正文」——邮箱剔除纪律沿用（签名级：build_content
 * 不收 email 形参）；网站 URL 不入内容送审串（URL 是 U5 的事）。
 *
 * 分批（定稿 §3）：每轮 worker ≤25 条或 ≤4 分钟墙钟（先到为准）；批次间随机抖动
 * 0.5–2s；幂等按 comment_meta wink_audit_existing（重新审核=清 meta 重跑）。
 *
 * @package WinkGatekeeper\Audit
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（S2-F1 纵深加固——类文件直访零执行，守卫为纵深面）。
}

final class ExistingAuditTask {

	/**
	 * 进度 option 名（非 settings 树——运行态不进设置）。
	 */
	public const OPTION_PROGRESS = 'wink_gatekeeper_audit_existing_progress';

	/**
	 * 幂等 meta 键（comment_meta：verdict/reason/hits/driver/audited_at）。
	 */
	public const META_KEY = 'wink_audit_existing';

	/**
	 * cron tick 钩子名（Bootstrap 注册；worker 每 tick 跑一轮分批）。
	 */
	public const HOOK_TICK = 'wink_gatekeeper_audit_tick';

	/**
	 * cron interval 名（300s——单轮 ≤4 分钟内、批间礼貌间隔）。
	 */
	public const CRON_INTERVAL = 'wk_audit_5min';

	/**
	 * 单轮条数上限（定稿 §3：每轮 ≤25 条）。
	 */
	public const BATCH_MAX_ITEMS = 25;

	/**
	 * 单轮墙钟上限秒（定稿 §3：或 ≤4 分钟——先到为准）。
	 */
	public const BATCH_MAX_SECONDS = 240;

	/**
	 * 批次间随机抖动区间（秒；nw §1.2 礼貌面）。
	 */
	public const JITTER_MIN = 0.5;
	public const JITTER_MAX = 2.0;

	/**
	 * verdict 计数面（gone=评论已删——定稿 §3「只审评论仍存在的行」的收口口径）。
	 */
	public const VERDICT_KEYS = array( 'pass', 'reject', 'review', 'gone' );

	/**
	 * 处置可逆终态闭集（【M6·批C·C-2】——wp_set_comment_status 可逆面：垃圾箱可一键
	 * 恢复、待审可批准；none=零处置）。
	 */
	public const DISPOSAL_KEYS = array( 'spam', 'hold', 'none' );

	/**
	 * verdict→存量处置映射（【M6·批C·C-2】用户 2026-11-16 拍板）：判违规进垃圾箱、
	 * 没看清进待审（都能手动恢复）；pass/gone/error/未知→不动（fail-safe 零处置）。
	 *
	 * @param string $verdict pass|reject|review|gone|error|其余未知值。
	 * @return string spam|hold|none（DISPOSAL_KEYS 闭集内）。
	 */
	public static function disposal_for_verdict( string $verdict ): string {
		return match ( $verdict ) {
			'reject' => 'spam',
			'review' => 'hold',
			default  => 'none', // pass/gone/error/未知——零处置（只放大严）。
		};
	}

	/**
	 * 发起时建进度快照（K5 口径：总数=发起时快照，新增评论不追进本轮）。
	 *
	 * @param int    $total     发起时全量快照数。
	 * @param string $driver_id 送审驱动 id（首个已配置云审驱动）。
	 * @param int    $batch_size 单轮条数上限。
	 * @param int    $started_at 发起时刻（注入时钟）。
	 * @return array<string, mixed>
	 */
	public static function new_progress( int $total, string $driver_id, int $batch_size, int $started_at ): array {
		$counts = array();
		foreach ( self::VERDICT_KEYS as $key ) {
			$counts[ $key ] = 0;
		}
		return array(
			'status'      => 'running',
			'total'       => $total,
			'processed'   => 0,
			'driver'      => $driver_id,
			'batch_size'  => $batch_size,
			'started_at'  => $started_at,
			'finished_at' => null,
			'verdicts'    => $counts,
		);
	}

	/**
	 * 送审串构造：「昵称 \n 正文」两行（空昵称保留空行——单一确定性形态）。
	 *
	 * **签名级邮箱剔除**：本方法不设 email 形参——存量审核送审面 email 无入口
	 * （M5 定稿 §3 邮箱剔除纪律沿用；阿里/百度云审「送审串邮箱剔除」实证先例）。
	 *
	 * @param string $author 昵称（comment_author）。
	 * @param string $body   正文（comment_content）。
	 * @return string
	 */
	public static function build_content( string $author, string $body ): string {
		return $author . "\n" . $body;
	}

	/**
	 * 幂等判定：meta 在=已审核（跳过），无=待审。
	 *
	 * @param array<string, mixed>|null $meta comment_meta 读值。
	 * @return bool true=需处理。
	 */
	public static function should_process( ?array $meta ): bool {
		return null === $meta || ! isset( $meta['verdict'] );
	}

	/**
	 * meta 记录形态（verdict/reason/hits/driver/audited_at——定稿 §3 幂等键内容；
	 * 【M6·批C·C-2】+ disposed_to 处置溯源）。
	 *
	 * @param string   $verdict    pass|reject|review|error。
	 * @param string   $reason     驱动 reason（展示面按不可信数据转义——渲染层职责）。
	 * @param string[] $hits       命中键（驱动原值）。
	 * @param string   $driver_id  驱动 id。
	 * @param int      $audited_at 审核时刻。
	 * @param string   $disposed_to 处置结果 spam|hold|none（缺省 none——gone 仅记 meta）。
	 * @return array<string, mixed>
	 */
	public static function meta_record( string $verdict, string $reason, array $hits, string $driver_id, int $audited_at, string $disposed_to = 'none' ): array {
		return array(
			'verdict'     => $verdict,
			'reason'      => $reason,
			'hits'        => $hits,
			'driver'      => $driver_id,
			'audited_at'  => $audited_at,
			'disposed_to' => in_array( $disposed_to, self::DISPOSAL_KEYS, true ) ? $disposed_to : 'none',
		);
	}

	/**
	 * 推进计数（verdict 计入计数面；未知值计入 processed 但不进四键——不重跑语义）。
	 *
	 * @param array<string, mixed> $progress 当前进度。
	 * @param string               $verdict  本条收口值。
	 * @return array<string, mixed>
	 */
	public static function advance( array $progress, string $verdict ): array {
		$progress['processed'] = ( $progress['processed'] ?? 0 ) + 1;
		if ( in_array( $verdict, self::VERDICT_KEYS, true ) ) {
			$progress['verdicts'][ $verdict ] = ( $progress['verdicts'][ $verdict ] ?? 0 ) + 1;
		}
		return $progress;
	}

	/**
	 * 是否跑完（processed ≥ total——幂等跳过不占 processed，gone 占）。
	 *
	 * @param array<string, mixed> $progress 当前进度。
	 * @return bool
	 */
	public static function is_finished( array $progress ): bool {
		return ( $progress['processed'] ?? 0 ) >= ( $progress['total'] ?? 0 );
	}

	/**
	 * 墙钟预算判断（先到为准：clock ≥ start + 240s 即收轮）。
	 *
	 * @param float|int $started_at 本轮起点。
	 * @param float|int $now       当前时刻。
	 * @return bool
	 */
	public static function deadline_reached( float|int $started_at, float|int $now ): bool {
		return $now >= ( $started_at + self::BATCH_MAX_SECONDS );
	}

	/**
	 * 批间抖动秒数（0.5–2.0 均匀随机）。
	 *
	 * @return float
	 */
	public static function jitter_seconds(): float {
		return self::JITTER_MIN + ( mt_rand() / mt_getrandmax() ) * ( self::JITTER_MAX - self::JITTER_MIN );
	}

	/**
	 * 手动暂停（Tab⑦ 暂停钮；原因落 progress 供状态行展示）。
	 *
	 * @param array<string, mixed> $progress 当前进度。
	 * @param string               $reason   pause|manual|no_driver|health_halted。
	 * @return array<string, mixed>
	 */
	public static function pause( array $progress, string $reason = 'manual' ): array {
		$progress['status']       = 'paused';
		$progress['pause_reason'] = $reason;
		return $progress;
	}

	/**
	 * 手动继续（Tab⑦ 继续钮）。
	 *
	 * @param array<string, mixed> $progress 当前进度。
	 * @return array<string, mixed>
	 */
	public static function resume( array $progress ): array {
		$progress['status'] = 'running';
		unset( $progress['pause_reason'] );
		return $progress;
	}

	/**
	 * 配额暂停（Usage daily_quota 达限/驱动健康停转——nw §2.5-5 批量车道先停）。
	 * 边沿通知一次：quota_notified 标志首次置位，重复触发不刷新时刻。
	 *
	 * @param array<string, mixed> $progress 当前进度。
	 * @param int                  $at       暂停时刻。
	 * @return array<string, mixed>
	 */
	public static function quota_pause( array $progress, int $at ): array {
		if ( ( $progress['status'] ?? '' ) === 'quota_paused' ) {
			return $progress; // 边沿一次：已暂停态不刷新。
		}
		$progress['status']          = 'quota_paused';
		$progress['quota_paused_at'] = $at;
		$progress['quota_notified']  = true;
		return $progress;
	}

	/**
	 * 配额恢复自动续跑（nw §2.5-5 次日自动恢复）；通知标志复位（下次触发再报边沿）。
	 *
	 * @param array<string, mixed> $progress 当前进度。
	 * @return array<string, mixed>
	 */
	public static function quota_resume( array $progress ): array {
		$progress['status'] = 'running';
		unset( $progress['quota_paused_at'], $progress['quota_notified'] );
		return $progress;
	}

	/**
	 * 收官（Tab⑥ 报告卡「上次完成于」）。
	 *
	 * @param array<string, mixed> $progress 当前进度。
	 * @param int                  $at       完成时刻。
	 * @return array<string, mixed>
	 */
	public static function mark_completed( array $progress, int $at ): array {
		$progress['status']       = 'completed';
		$progress['finished_at']  = $at;
		unset( $progress['pause_reason'], $progress['quota_paused_at'], $progress['quota_notified'] );
		return $progress;
	}

	/**
	 * 报告视图模型（【M6·批C·C-3】Tab⑦ 存量审核报告——wk-overview-report 同款形态的
	 * 纯函数面：三态（empty/running/completed）+ 处置口径三行统计对账）。
	 *
	 * 口径=处理口径（用户 2026-11-16 反馈原话）：没问题（pass）/ 待审核（review——未
	 * 核实不下结论）/ 垃圾箱（reject——已按 C-2 处置进垃圾箱，可手动恢复）；gone 与
	 * error 作灰字注脚不入三行。
	 *
	 * @param array<string, mixed>        $progress 进度 option（空数组=idle）。
	 * @param array<string, int>|null     $counts   verdict 计数（report_data()['counts'] 形态）。
	 * @return array{state: string, driver: string, started_at: int, finished_at: int, processed: int, total: int, caliber: array{pass: int, pending: int, spam: int, gone: int, error: int}}
	 */
	public static function report_view( array $progress, ?array $counts = null ): array {
		$counts  = is_array( $counts ) ? $counts : array();
		$status  = (string) ( $progress['status'] ?? 'idle' );
		$empty_p = array() === $progress || ( 'idle' === $status && 0 === (int) ( $progress['processed'] ?? 0 ) && 0 === (int) ( $progress['total'] ?? 0 ) );
		$has_any = 0 < array_sum( array_map( 'intval', array( $counts['pass'] ?? 0, $counts['reject'] ?? 0, $counts['review'] ?? 0, $counts['gone'] ?? 0, $counts['error'] ?? 0 ) ) );

		if ( $empty_p && ! $has_any ) {
			return array(
				'state'       => 'empty',
				'driver'      => '',
				'started_at'  => 0,
				'finished_at' => 0,
				'processed'   => 0,
				'total'       => 0,
				'caliber'     => array( 'pass' => 0, 'pending' => 0, 'spam' => 0, 'gone' => 0, 'error' => 0 ),
			);
		}

		$state = 'completed' === $status || ( 0 < (int) ( $progress['finished_at'] ?? 0 ) ) ? 'completed' : 'running';
		return array(
			'state'       => $state,
			'driver'      => (string) ( $progress['driver'] ?? '' ),
			'started_at'  => (int) ( $progress['started_at'] ?? 0 ),
			'finished_at' => (int) ( $progress['finished_at'] ?? 0 ),
			'processed'   => (int) ( $progress['processed'] ?? 0 ),
			'total'       => (int) ( $progress['total'] ?? 0 ),
			'caliber'     => array(
				'pass'    => (int) ( $counts['pass'] ?? 0 ),
				'pending' => (int) ( $counts['review'] ?? 0 ),
				'spam'    => (int) ( $counts['reject'] ?? 0 ),
				'gone'    => (int) ( $counts['gone'] ?? 0 ),
				'error'   => (int) ( $counts['error'] ?? 0 ),
			),
		);
	}
}
