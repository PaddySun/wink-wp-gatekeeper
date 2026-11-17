<?php
/**
 * U3 存量审核 Worker（分批执行编排，2026-11-14；【M6·批C】2026-11-16 重构）。
 *
 * 全部 WP 交互经构造器可注入闭包（单测零 WP 依赖；生产装配走 defaults 工厂——
 * Bootstrap 的 HOOK_TICK 闭包/SettingsPage::audit_worker）。
 *
 * 【M6·批C·C-2】处置语义（用户 2026-11-16 拍板——原「仅标记不变式」退役）：本类
 * 在判定落 meta 后按 ExistingAuditTask::disposal_for_verdict 执行处置——reject→垃圾箱
 * （wp_set_comment_status 'spam'）、review→待审（'hold'）、pass→不动、gone→仅记 meta；
 * 处置结果落 meta disposed_to（spam|hold|none 可逆终态闭集）；处置执行器可注入
 * （default=wp_set_comment_status）。增量车道处置矩阵零改动（C-2 红线）。
 *
 * 【M6·批C·C-1】宇宙快照语义（F1「23/22」修复）：发起时固化宇宙 ID 清单进 progress
 * option（start 第二参）；worker 只处理清单内 ID；processed 恒 ≤ total；完成判据=
 * 清单全部落 meta（含 gone）。旧 progress（无 ids 键）不迁移——动态探测路径原样保留，
 * 随下次运行自然归位。宇宙查询 approved-only（comment_approved='1'）：垃圾与待审
 * 不翻出来重查。
 *
 * 分批语义（定稿 §3 + nw §2.5/§4.4）：单轮 ≤25 条或 ≤4 分钟墙钟先到为准；批间抖动
 * 0.5–2s；配额达限=暂停+边沿通知一次（Tab① 黄条+Tab① 通栏消费 quota_notified），
 * 配额恢复自动续跑（nw 次日自动恢复语义）；幂等按 comment_meta 跳过——断点续跑天然成立。
 *
 * @package WinkGatekeeper\Audit
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Audit;

use WinkGatekeeper\Drivers\ReviewDriverInterface;

final class ExistingAuditWorker {

	/**
	 * @var array<string, mixed> 设置树（装配期快照）。
	 */
	private $settings;

	/** @var callable fn(): array|false 进度读取。 */
	private $storage_get;

	/** @var callable fn(array): void 进度写入。 */
	private $storage_put;

	/** @var callable fn(int $limit): int[] 未审核评论 id 探测（meta NOT EXISTS）。 */
	private $unaudited_ids;

	/** @var callable fn(int $id): array{author:string,body:string}|null 评论读取（null=已删）。 */
	private $comment_reader;

	/** @var callable fn(int $id): array|null meta 读取。 */
	private $meta_reader;

	/** @var callable fn(int $id, array $meta): void meta 写入。 */
	private $meta_writer;

	/** @var callable fn(): array{driver: ReviewDriverInterface, id: string}|null 驱动工厂（null=无已配置云审驱动）。 */
	private $driver_factory;

	/** @var callable fn(): float 时钟。 */
	private $clock;

	/** @var callable fn(float $s): void 休眠（抖动）。 */
	private $sleeper;

	/** @var callable fn(string $driver_id): bool 配额达限判定。 */
	private $quota_checker;

	/** @var callable fn(string $driver_id): string|null 健康停转态（null=正常）。 */
	private $health_checker;

	/** @var callable fn(int $id, string $action): bool 处置执行（【M6·批C·C-2】wp_set_comment_status 可逆终态面）。 */
	private $disposal_executor;

	/**
	 * @param array<string, mixed> $settings 设置树。
	 * @param callable|null        $storage_get     进度读取（默认 get_option）。
	 * @param callable|null        $storage_put     进度写入（默认 update_option）。
	 * @param callable|null        $unaudited_ids   未审核 id 探测（默认 $wpdb meta NOT EXISTS）。
	 * @param callable|null        $comment_reader  评论读取（默认 get_comment）。
	 * @param callable|null        $meta_reader     meta 读取（默认 get_comment_meta）。
	 * @param callable|null        $meta_writer     meta 写入（默认 update_comment_meta）。
	 * @param callable|null        $driver_factory  驱动工厂（默认首个已配置云审驱动）。
	 * @param callable|null        $clock           时钟（默认 microtime）。
	 * @param callable|null        $sleeper         休眠（默认 usleep 秒转微秒）。
	 * @param callable|null        $quota_checker   配额判定（默认 Usage::quota_reached）。
	 * @param callable|null        $health_checker  健康态（默认 DriverHealth::state_today）。
	 * @param callable|null        $disposal_executor 处置执行（【M6·批C·C-2】默认 wp_set_comment_status；spam|hold 可逆终态面）。
	 */
	public function __construct(
		array $settings,
		?callable $storage_get = null,
		?callable $storage_put = null,
		?callable $unaudited_ids = null,
		?callable $comment_reader = null,
		?callable $meta_reader = null,
		?callable $meta_writer = null,
		?callable $driver_factory = null,
		?callable $clock = null,
		?callable $sleeper = null,
		?callable $quota_checker = null,
		?callable $health_checker = null,
		?callable $disposal_executor = null
	) {
		$this->settings       = $settings;
		$this->storage_get    = $storage_get ?? static fn() => get_option( ExistingAuditTask::OPTION_PROGRESS, false );
		$this->storage_put    = $storage_put ?? static function ( array $p ): void {
			update_option( ExistingAuditTask::OPTION_PROGRESS, $p, false );
		};
		$this->unaudited_ids  = $unaudited_ids ?? null; // 默认工厂见 self::default_unaudited_ids()（需 $wpdb，惰性）。
		$this->comment_reader = $comment_reader ?? null;
		$this->meta_reader    = $meta_reader ?? null;
		$this->meta_writer    = $meta_writer ?? null;
		$this->driver_factory = $driver_factory ?? null;
		$this->clock          = $clock ?? static fn(): float => microtime( true );
		$this->sleeper        = $sleeper ?? static function ( float $s ): void {
			usleep( (int) ( $s * 1000000 ) );
		};
		$this->quota_checker  = $quota_checker ?? static function ( string $driver_id ) use ( $settings ): bool {
			$quota = (int) ( $settings['review']['cloud'][ $driver_id ]['daily_quota'] ?? 0 );
			return $quota > 0 && \WinkGatekeeper\Stats\Usage::quota_reached( \WinkGatekeeper\Stats\Usage::get(), $driver_id, $quota );
		};
		$this->health_checker = $health_checker ?? static fn( string $driver_id ): ?string => \WinkGatekeeper\Stats\DriverHealth::state_today( $driver_id );
		// 【M6·批C·C-2】处置可逆终态面（与 UrlAuditWorker::default_disposal_executor 同形态）。
		$this->disposal_executor = $disposal_executor ?? static function ( int $id, string $action ): bool {
			return (bool) wp_set_comment_status( $id, $action );
		};
	}

	/**
	 * 发起任务（Tab⑦「开始体检」/CLI start——只建任务快照不送审）。
	 *
	 * 【M6·批C·C-1】宇宙快照化（F1「23/22」修复）：$universe_ids 非空时固化 ID 清单
	 * 进 progress option（K5 快照意图的真实现）——worker 只处理清单内 ID，processed
	 * 恒 ≤ total（total=清单数，快照权威）；null 时沿用旧形态（单测桩/历史兼容），
	 * 生产调用方（SettingsPage/CLI）必须传快照清单。
	 *
	 * @param int     $total        发起时全量快照数（K5；$universe_ids 非空时以 count 清单为准）。
	 * @param int[]|null $universe_ids 发起时宇宙 ID 快照（unaudited_universe_ids——升序）。
	 * @return array<string, mixed> 进度。
	 * @throws \RuntimeException 任务已在运行。
	 */
	public function start( int $total, ?array $universe_ids = null ): array {
		$progress = ( $this->storage_get )();
		if ( is_array( $progress ) && 'running' === ( $progress['status'] ?? '' ) ) {
			throw new \RuntimeException( 'audit task already running' );
		}

		$factory = $this->driver_factory ?? array( $this, 'default_driver_factory' );
		$driver  = $factory();
		// 【M5 修复批·C1】无已配置云审驱动 → 任务不落盘直接抛（置于 new_progress 之前）。
		// 病株：默认工厂解析 null 时照样落盘 running 任务——站长看到假「进行中」，下一轮
		// tick 才走 no_driver 暂停（半残态）。守卫收口：默认工厂路径（生产装配）start 即抛；
		// 注入工厂（单测桩/自定义装配）不受此守卫约束——桩返回 null 仍走 run_batch 的
		// no_driver 暂停路径（M5U3WorkerTest::test_missing_driver_pauses_task 语义不变）。
		if ( ! is_array( $driver ) && null === $this->driver_factory ) {
			throw new \RuntimeException( 'no configured cloud review driver' );
		}
		$driver_id = is_array( $driver ) ? (string) $driver['id'] : '';

		$progress = ExistingAuditTask::new_progress( $total, $driver_id, ExistingAuditTask::BATCH_MAX_ITEMS, (int) ( $this->clock )() );
		if ( null !== $universe_ids ) {
			$ids               = array_values( array_unique( array_filter( array_map( 'intval', $universe_ids ), static fn( $v ): bool => (int) $v > 0 ) ) );
			$progress['ids']   = $ids;
			$progress['total'] = count( $ids ); // 快照权威——processed 恒 ≤ total。
		}
		( $this->storage_put )( $progress );
		return $progress;
	}

	/**
	 * 跑一轮分批（cron tick/CLI run——单轮 ≤25 条或 ≤4 分钟先到为准）。
	 *
	 * @return array{progress: array<string, mixed>, pause_reason?: string, processed_this_round: int}
	 */
	public function run_batch(): array {
		$progress = ( $this->storage_get )();
		if ( ! is_array( $progress ) ) {
			return array( 'progress' => array( 'status' => 'idle' ), 'processed_this_round' => 0 );
		}
		$status = $progress['status'] ?? 'idle';

		if ( 'running' !== $status ) {
			if ( 'quota_paused' === $status ) {
				// nw §2.5-5 次日自动恢复：tick 时配额已恢复 → 续跑。
				$driver = $this->resolve_driver();
				if ( null !== $driver && ! ( $this->quota_checker )( $driver['id'] ) ) {
					$progress = ExistingAuditTask::quota_resume( $progress );
					( $this->storage_put )( $progress );
					return $this->run_batch(); // 续跑一轮。
				}
				return array( 'progress' => $progress, 'processed_this_round' => 0 );
			}
			return array( 'progress' => $progress, 'processed_this_round' => 0 );
		}

		$driver = $this->resolve_driver();
		if ( null === $driver ) {
			$progress = ExistingAuditTask::pause( $progress, 'no_driver' );
			( $this->storage_put )( $progress );
			return array( 'progress' => $progress, 'pause_reason' => 'no_driver', 'processed_this_round' => 0 );
		}

		// 健康停转（02 §5 快速失败语义——停转态不空转烧配额）。
		$halt = ( $this->health_checker )( $driver['id'] );
		if ( null !== $halt ) {
			$progress = ExistingAuditTask::pause( $progress, 'health_halted' );
			( $this->storage_put )( $progress );
			return array( 'progress' => $progress, 'pause_reason' => 'health_halted', 'processed_this_round' => 0 );
		}

		// 配额达限：暂停+边沿通知（恢复靠下次 tick 自动续跑）。
		if ( ( $this->quota_checker )( $driver['id'] ) ) {
			$progress = ExistingAuditTask::quota_pause( $progress, (int) ( $this->clock )() );
			( $this->storage_put )( $progress );
			return array( 'progress' => $progress, 'pause_reason' => 'quota_exhausted', 'processed_this_round' => 0 );
		}

		$round_start = ( $this->clock )();
		$processed   = 0;
		$exhausted   = false; // 探测面/快照清单跑空=完成判据之一。
		$comment_reader = $this->comment_reader ?? array( $this, 'default_comment_reader' );
		$meta_reader    = $this->meta_reader ?? array( $this, 'default_meta_reader' );
		$meta_writer    = $this->meta_writer ?? array( $this, 'default_meta_writer' );
		$ids_provider   = $this->unaudited_ids ?? array( $this, 'default_unaudited_ids' );
		$executor       = $this->disposal_executor;

		// 【M6·批C·C-1】宇宙快照（发起时固化清单）优先；旧 progress（无 ids 键）不迁移
		// ——动态探测路径原样保留（自然归位）。
		$snapshot_ids = is_array( $progress['ids'] ?? null ) ? array_map( 'intval', $progress['ids'] ) : null;
		$cursor       = 0;

		while ( $processed < ExistingAuditTask::BATCH_MAX_ITEMS
			&& ! ExistingAuditTask::deadline_reached( $round_start, ( $this->clock )() ) ) {

			if ( null !== $snapshot_ids ) {
				// 快照语义：只处理清单内 ID；已落 meta 的（幂等）跳过。
				$id = 0;
				while ( $cursor < count( $snapshot_ids ) ) {
					$candidate = (int) $snapshot_ids[ $cursor ];
					++$cursor;
					if ( ExistingAuditTask::should_process( $meta_reader( $candidate ) ) ) {
						$id = $candidate;
						break;
					}
				}
				if ( 0 === $id ) {
					$exhausted = true;
					break; // 清单全落 meta（含 gone）——权威完成态。
				}
			} else {
				// 旧形态（无快照清单）：动态探测（每轮重选未标记——历史行为）。
				$ids = $ids_provider( 1 );
				if ( array() === $ids ) {
					$exhausted = true;
					break; // 全部审完。
				}
				$id = (int) $ids[0];

				// 幂等（查询面已排除 meta 在者——双保险读时再判）。
				$meta = $meta_reader( $id );
				if ( ! ExistingAuditTask::should_process( $meta ) ) {
					continue;
				}
			}

			$comment = $comment_reader( $id );
			if ( null === $comment ) {
				// 已删评论：gone 收口**写 meta**（防死循环重选——探测面排除依据就是 meta）。
				$meta_writer( $id, ExistingAuditTask::meta_record( 'gone', '', array(), $driver['id'], (int) ( $this->clock )() ) );
				$progress = ExistingAuditTask::advance( $progress, 'gone' );
				++$processed;
				continue;
			}

			$verdict = $driver['driver']->check(
				ExistingAuditTask::build_content( (string) $comment['author'], (string) $comment['body'] ),
				array(
					// 邮箱剔除纪律：meta 不带 email（签名级剔除——阿里双分送 email leg 空转）；
					// URL 不入（U5 的事）。
					'author'  => (string) $comment['author'],
					'post_id' => (int) ( $comment['post_id'] ?? 0 ),
				)
			);

			// 【M6·批C·C-2】处置（可逆终态面——用户 2026-11-16 拍板）：reject→垃圾箱、
			// review→待审、pass/gone→不动；disposed_to 落 meta 溯源。
			$disposed_to = ExistingAuditTask::disposal_for_verdict( $verdict->verdict() );
			if ( 'none' !== $disposed_to ) {
				$executor( $id, $disposed_to ); // spam|hold——wp_set_comment_status 可逆面。
			}

			$meta_writer(
				$id,
				ExistingAuditTask::meta_record(
					$verdict->verdict(),
					$verdict->reason(),
					$verdict->hits(),
					$driver['id'],
					(int) ( $this->clock )(),
					$disposed_to
				)
			);

			$progress = ExistingAuditTask::advance( $progress, $verdict->verdict() );
			++$processed;

			// 配额边沿（轮中检查——大批次烧穿防线）。
			if ( ( $this->quota_checker )( $driver['id'] ) ) {
				$progress = ExistingAuditTask::quota_pause( $progress, (int) ( $this->clock )() );
				( $this->storage_put )( $progress );
				return array( 'progress' => $progress, 'pause_reason' => 'quota_exhausted', 'processed_this_round' => $processed );
			}

			// 批间抖动（最后一条不休）。
			if ( $processed < ExistingAuditTask::BATCH_MAX_ITEMS
				&& ! ExistingAuditTask::deadline_reached( $round_start, ( $this->clock )() ) ) {
				( $this->sleeper )( ExistingAuditTask::jitter_seconds() );
			}
		}

		// 完成判据：探测面/快照清单跑空（exhausted）或计数达标（幂等预存行使 processed
		// 恒小于 total 快照——探测面空是权威完成态）。
		if ( $exhausted || ExistingAuditTask::is_finished( $progress ) ) {
			$progress = ExistingAuditTask::mark_completed( $progress, (int) ( $this->clock )() );
		}
		( $this->storage_put )( $progress );

		return array( 'progress' => $progress, 'processed_this_round' => $processed );
	}

	/**
	 * 手动暂停（Tab⑦ 暂停钮/CLI pause）。
	 *
	 * @return array<string, mixed>|null 进度（idle=null）。
	 */
	public function pause_task(): ?array {
		$progress = ( $this->storage_get )();
		if ( ! is_array( $progress ) ) {
			return null;
		}
		$progress = ExistingAuditTask::pause( $progress, 'manual' );
		( $this->storage_put )( $progress );
		return $progress;
	}

	/**
	 * 手动继续（Tab⑦ 继续钮/CLI resume；恢复 cron tick 由 start/resume 侧重排）。
	 *
	 * @return array<string, mixed>|null
	 */
	public function resume_task(): ?array {
		$progress = ( $this->storage_get )();
		if ( ! is_array( $progress ) ) {
			return null;
		}
		$progress = ExistingAuditTask::resume( $progress );
		( $this->storage_put )( $progress );
		return $progress;
	}

	/**
	 * 重新体检（Tab⑦ 重新体检钮/CLI reset——清全部标记+进度作废）。
	 *
	 * @param int[] $ids_to_clear 要清 meta 的评论 id 全集（universe 快照）。
	 * @return bool 是否有进度被清。
	 */
	public function reset_task( array $ids_to_clear ): bool {
		$meta_writer = $this->meta_writer ?? array( $this, 'default_meta_deleter' );
		foreach ( $ids_to_clear as $id ) {
			$meta_writer( (int) $id, null ); // null=清除。
		}
		$progress = ( $this->storage_get )();
		( $this->storage_put )( array() );
		return is_array( $progress ) && array() !== $progress;
	}

	/**
	 * 状态读取（Tab⑦ 状态行/CLI status）。
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$progress = ( $this->storage_get )();
		return is_array( $progress ) && array() !== $progress ? $progress : array( 'status' => 'idle' );
	}

	/* -------------------------------------------------------------------
	 * 默认工厂（WP 边界——生产装配用；单测全注入不触达）
	 * ------------------------------------------------------------------ */

	/**
	 * 【M5 修复批·C4】驱动可用性收口判定（复用 default_driver_factory 工厂逻辑）。
	 *
	 * Tab⑦ idle 态「开始体检」钮渲染前置闸：无已配置云审驱动时不渲染发起钮
	 * （替换为黄条引导去 Tab③ 配置）——防发起即抛/发起即瘫（与 C1 start 守卫同一
	 * 判定源，两处不各写一套口径）。
	 *
	 * @param array<string, mixed> $settings merge 后整树。
	 * @return bool 是否解析得出已配置云审驱动。
	 */
	public static function has_driver( array $settings ): bool {
		return null !== ( new self( $settings ) )->default_driver_factory();
	}

	/**
	 * 驱动解析（注入工厂优先；缺省走 default_driver_factory）。
	 *
	 * @return array{driver: ReviewDriverInterface, id: string}|null
	 */
	private function resolve_driver(): ?array {
		$factory = $this->driver_factory ?? array( $this, 'default_driver_factory' );
		$driver  = $factory();
		return ( is_array( $driver ) && isset( $driver['driver'], $driver['id'] ) ) ? $driver : null;
	}

	/**
	 * 默认驱动工厂：首个已配置云审驱动（chain 顺序；定稿 §3「按当前启用的服务商送审」）。
	 *
	 * @return array{driver: ReviewDriverInterface, id: string}|null
	 */
	public function default_driver_factory(): ?array {
		if ( empty( $this->settings['review']['cloud']['enabled'] ) ) {
			return null;
		}
		$chain = is_array( $this->settings['review']['cloud']['chain'] ?? null ) ? $this->settings['review']['cloud']['chain'] : array();
		$client = null;
			foreach ( $chain as $chain_id ) {
				$driver = null;
				if ( 'tencent_tms' === $chain_id ) {
					$driver = new \WinkGatekeeper\Drivers\Review\TencentTmsDriver( $this->settings, $this->default_client() );
				} elseif ( 'aliyun_green' === $chain_id ) {
					$driver = new \WinkGatekeeper\Drivers\Review\AliyunGreenPlusDriver( $this->settings, $this->default_client() );
				} elseif ( 'baidu' === $chain_id ) {
					$driver = new \WinkGatekeeper\Drivers\Review\BaiduTextModerationDriver( $this->settings, $this->default_client() );
				} elseif ( 'deepseek' === $chain_id ) {
					// 【M5 修复批·C2】DeepSeek LLM 驱动补口（M5·U4 实装后 U3 工厂漏登记——
					// chain=['deepseek'] 时恒解析 null，start/run 全瘫）。形态对照
					// Bootstrap.php 同名分支：config_from_settings 派生（含 review.cloud
					// 顶层 two_stage/upgrade_model）+ default_client 传输脊。
					$driver = new \WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver(
						\WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver::config_from_settings( $this->settings ),
						$this->default_client()
					);
				}
			if ( null !== $driver && $driver->is_configured() ) {
				return array( 'driver' => $driver, 'id' => (string) $chain_id );
			}
		}
		return null;
	}

	/**
	 * 传输脊（RemoteClient 唯一出口——计量钩子同生产装配）。
	 *
	 * @return \WinkGatekeeper\Http\RemoteClient
	 */
	private function default_client(): \WinkGatekeeper\Http\RemoteClient {
		return new \WinkGatekeeper\Http\RemoteClient( null, array( \WinkGatekeeper\Stats\Usage::class, 'bump_option' ) );
	}

	/**
	 * @param int $id 评论 id。
	 * @return array{author: string, body: string, post_id: int}|null
	 */
	public function default_comment_reader( int $id ): ?array {
		$comment = get_comment( $id );
		if ( ! $comment ) {
			return null;
		}
		return array(
			'author'  => (string) $comment->comment_author,
			'body'    => (string) $comment->comment_content,
			'post_id' => (int) $comment->comment_post_ID,
		);
	}

	/**
	 * @param int $id 评论 id。
	 * @return array<string, mixed>|null
	 */
	public function default_meta_reader( int $id ): ?array {
		$meta = get_comment_meta( $id, ExistingAuditTask::META_KEY, true );
		return is_array( $meta ) && array() !== $meta ? $meta : null;
	}

	/**
	 * @param int                      $id   评论 id。
	 * @param array<string, mixed>|null $meta null=删除（重新体检）。
	 * @return void
	 */
	public function default_meta_writer( int $id, ?array $meta ): void {
		if ( null === $meta ) {
			delete_comment_meta( $id, ExistingAuditTask::META_KEY );
			return;
		}
		update_comment_meta( $id, ExistingAuditTask::META_KEY, $meta );
	}

	/**
	 * reset 通道别名（语义等同 meta_writer null 清除）。
	 *
	 * @param int   $id 评论 id。
	 * @param mixed $meta 弃用。
	 * @return void
	 */
	public function default_meta_deleter( int $id, $meta = null ): void {
		delete_comment_meta( $id, ExistingAuditTask::META_KEY );
	}

	/**
	 * 未审核 id 探测（$wpdb：已批准存量 + meta NOT EXISTS；id 升序=稳定分批序）。
	 *
	 * 【M6·批C·C-1】宇宙 approved-only（用户 2026-11-16：不把已经判定为垃圾的翻出来
	 * 鞭尸）——垃圾与待审不进探测面；仅旧形态 progress（无快照清单）仍消费本通道。
	 *
	 * @param int $limit 探测条数。
	 * @return int[]
	 */
	public function default_unaudited_ids( int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT c.comment_ID FROM {$wpdb->comments} c
				 LEFT JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = %s
				 WHERE m.meta_id IS NULL AND c.comment_approved = '1'
				 ORDER BY c.comment_ID ASC LIMIT %d",
				ExistingAuditTask::META_KEY,
				$limit
			)
		);
		return array_map( 'intval', is_array( $rows ) ? $rows : array() );
	}

	/**
	 * 【M6·批C·C-1】发起时宇宙 ID 快照（start 第二参数据源——SettingsPage/CLI start 消费）：
	 * 已批准存量 + 无判定 meta，升序。发起后新增的评论不进本轮（快照权威——F1「23/22」修复）。
	 *
	 * @return int[]
	 */
	public static function unaudited_universe_ids(): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT c.comment_ID FROM {$wpdb->comments} c
				 LEFT JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = %s
				 WHERE m.meta_id IS NULL AND c.comment_approved = '1'
				 ORDER BY c.comment_ID ASC",
				ExistingAuditTask::META_KEY
			)
		);
		return array_map( 'intval', is_array( $rows ) ? $rows : array() );
	}

	/**
	 * 【M6·批C·C-2】处置执行（默认 WP 通道——wp_set_comment_status 可逆终态面：
	 * spam=垃圾箱可一键恢复、hold=待审可批准；与 UrlAuditWorker::default_disposal_executor
	 * 同形态。单测注入捕获）。
	 *
	 * @param int    $id     评论 id。
	 * @param string $action spam|hold。
	 * @return bool
	 */
	public function default_disposal_executor( int $id, string $action ): bool {
		return (bool) wp_set_comment_status( $id, $action );
	}

	/* -------------------------------------------------------------------
	 * 统计面（WP 边界——Tab⑥ 报告卡/CLI start 快照消费；K5/K6/K7 口径）
	 * ------------------------------------------------------------------ */

	/**
	 * 待审快照计数（K5「总数=发起时快照」的快照对象=发起时未标记行——进度条恰好走满；
	 * 已标记行不进本轮 processed 口径。【M6·批C·C-1】approved-only：垃圾与待审不入计数）。
	 *
	 * @return int
	 */
	public static function count_unaudited(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->comments} c
				 LEFT JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = %s
				 WHERE m.meta_id IS NULL AND c.comment_approved = '1'",
				ExistingAuditTask::META_KEY
			)
		);
	}

	/**
	 * universe 全量 id（重新体检清标记面。【M6·批C·C-1】approved-only——垃圾/待审的
	 * meta 不清（不翻出来重查的同族语义：处置进垃圾箱/待审的结果不被 reset 无效化）。
	 *
	 * @return int[]
	 */
	public static function universe_ids(): array {
		global $wpdb;
		$rows = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = '1'" );
		return array_map( 'intval', is_array( $rows ) ? $rows : array() );
	}

	/**
	 * 报告聚合（K6/K7——按 meta 全量 PHP 端聚合；comment_meta 序列化存储不支持 SQL 端
	 * GROUP BY verdict；报告页一次性读，量大可接受）。
	 *
	 * @return array{counts: array<string, int>, reasons: array<int, array{reason: string, n: int}>}
	 */
	public function report_data(): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare( "SELECT meta_value FROM {$wpdb->commentmeta} WHERE meta_key = %s", ExistingAuditTask::META_KEY )
		);
		$counts  = array();
		foreach ( ExistingAuditTask::VERDICT_KEYS as $key ) {
			$counts[ $key ] = 0;
		}
		$counts['error'] = 0;
		$reasons         = array();
		foreach ( is_array( $rows ) ? $rows : array() as $serialized ) {
			$meta = maybe_unserialize( (string) $serialized );
			if ( ! is_array( $meta ) || ! isset( $meta['verdict'] ) ) {
				continue;
			}
			$verdict = (string) $meta['verdict'];
			if ( isset( $counts[ $verdict ] ) ) {
				++$counts[ $verdict ];
			} else {
				++$counts['error'];
			}
			if ( 'reject' === $verdict || 'review' === $verdict ) {
				$reason = trim( (string) ( $meta['reason'] ?? '' ) );
				if ( '' === $reason ) {
					$reason = '（无原因说明）';
				}
				/* 【M6·批H3·H3-C】归堆键扩处置去向维度（手测反馈 C：行尾「→ 垃圾箱/
				 * → 待审核」）：同原因不同去向拆行——去向如实（reject→spam/review→hold
				 * 与 disposal_for_verdict 矩阵一致）。 */
				$disposal = 'reject' === $verdict ? 'spam' : 'hold';
				$key      = $reason . '|' . $disposal;
				if ( isset( $reasons[ $key ] ) ) {
					++$reasons[ $key ]['n'];
				} else {
					$reasons[ $key ] = array( 'reason' => $reason, 'n' => 1, 'disposal' => $disposal );
				}
			}
		}
		uasort( $reasons, static fn( $a, $b ) => $b['n'] <=> $a['n'] );
		$out = array();
		foreach ( array_slice( $reasons, 0, 10, true ) as $row ) {
			$out[] = $row;
		}
		return array( 'counts' => $counts, 'reasons' => $out );
	}
}
