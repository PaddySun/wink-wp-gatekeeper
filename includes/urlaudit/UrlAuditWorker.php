<?php
/**
 * URL 审核 Worker——增量/批量双车道（nw §1.1/§4.2/§4.4，2026-11-14 U5 批）。
 *
 * 三原则（nw §0.2）：① 异步-first 同步链零接触（preprocess_comment 内禁新增慢外呼——
 * 历史事故 27~75s worker 灭绝）；② fetch 失败≠违规——只有「内容正面证据」驱动 reject，
 * 自动处置仅发生在「fetch 成功+LLM reject+高置信」窄门；③ 只放大严不默认放宽。
 *
 * 增量链路（Akismet 式）：comment_post → URL 非空且功能开 → 写 job（瞬时返回）→
 * cron/CLI worker 异步 fetch→judge→处置。处置延迟期间评论本来就在待审（fail-review
 * 默认）——零额外暴露窗口（先入待审范式的核心安全性质）。
 *
 * 双车道（nw §4.4）：lane_incr 优先（低延迟）/lane_batch 让位（大吞吐）；同 host 串行+
 * 全局每轮限额；日预算闸 url_audit.incr_quota/batch_quota（Usage caller=url_audit_incr/
 * url_audit_batch——K2 计量）；批量车道先停增量车道继续（降级秩序，nw §2.5-5）。
 *
 * 仅标记哲学：默认 conservative profile 零处置（评论状态不动）；均衡/激进档按
 * UrlVerdicts::disposal 执行（auto_url_reject meta 可一键批量撤销）；存量已发布评论
 * 恒 flag（nw §5.3——零自动处置，UrlVerdicts::disposal_existing）。
 *
 * @package WinkGatekeeper\UrlAudit
 */

declare( strict_types=1 );

namespace WinkGatekeeper\UrlAudit;

use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Stats\LlmUsage;
use WinkGatekeeper\Stats\Usage;
use WinkGatekeeper\Verdict;

final class UrlAuditWorker {

	public const META_KEY = 'url_audit';

	/**
	 * 增量 job 队列 option（非 settings 树）：int[] 评论 id 升序（FIFO）。
	 */
	public const OPTION_INCR_QUEUE = 'wink_gatekeeper_url_audit_incr_queue';

	/**
	 * 双车道 cron tick 钩子名。
	 */
	public const HOOK_TICK = 'wink_gatekeeper_url_audit_tick';

	/**
	 * 清缓存 admin action 名。
	 */
	public const ACTION_PURGE = 'wink_gatekeeper_url_audit_purge';

	/**
	 * 单轮处理上限（nw §2.5-6：每轮 ≤25 URL 或 ≤4 分钟——与存量审核同闸）。
	 */
	public const BATCH_MAX = 25;

	/**
	 * @var array<string, mixed> 设置树。
	 */
	private $settings;

	/** @var callable fn(int $limit, array $exclude_ids): int[] 批量车道：有 URL 且未标记的评论 id。 */
	private $batch_ids;

	/** @var callable fn(int $limit): int[] 增量车道：队列取队头。 */
	private $incr_take;

	/** @var callable fn(int $id): void 增量队列入队（comment_post 侧）。 */
	private $incr_push;

	/** @var callable fn(int $id): array{author:string, body:string, url:string, post_status:string}|null 评论读取。 */
	private $comment_reader;

	/** @var callable fn(int $id): array|null 评论 url_audit meta 读取。 */
	private $meta_reader;

	/** @var callable fn(int $id, array|null $meta): void meta 写/清。 */
	private $meta_writer;

	/** @var callable fn(int $id, string $action): bool 处置执行（wp_set_comment_status——仅均衡/激进档消费）。 */
	private $disposal_executor;

	/** @var callable fn(): UrlFetcher fetcher 工厂。 */
	private $fetcher_factory;

	/** @var callable fn(): array{judge: UrlJudgeEngine, bocha: BochaSearch|null} 判定工厂。 */
	private $judge_factory;

	/** @var callable fn(): float 时钟。 */
	private $clock;

	/**
	 * @param array<string, mixed> $settings 设置树。
	 * @param array<string, callable> $hooks 全部可注入（默认 WP 通道——见 defaults）。
	 */
	public function __construct( array $settings, array $hooks = array() ) {
		$this->settings = $settings;
		$this->batch_ids          = $hooks['batch_ids'] ?? null;
		$this->incr_take          = $hooks['incr_take'] ?? null;
		$this->incr_push          = $hooks['incr_push'] ?? null;
		$this->comment_reader     = $hooks['comment_reader'] ?? null;
		$this->meta_reader        = $hooks['meta_reader'] ?? null;
		$this->meta_writer        = $hooks['meta_writer'] ?? null;
		$this->disposal_executor  = $hooks['disposal_executor'] ?? null;
		$this->fetcher_factory    = $hooks['fetcher_factory'] ?? null;
		$this->judge_factory      = $hooks['judge_factory'] ?? null;
		$this->clock              = $hooks['clock'] ?? static fn(): float => microtime( true );
	}

	/**
	 * 增量入队（comment_post 边界——瞬时返回零外呼；功能关/URL 空/重复入队幂等）。
	 *
	 * @param int $comment_id 评论 id。
	 * @return bool 是否入队。
	 */
	public function enqueue_url_audit_job( int $comment_id ): bool {
		if ( empty( $this->settings['url_audit']['enabled'] ) ) {
			return false;
		}
		$reader = $this->comment_reader ?? array( $this, 'default_comment_reader' );
		$comment = $reader( $comment_id );
		if ( null === $comment || '' === trim( (string) ( $comment['url'] ?? '' ) ) ) {
			return false; // 无网站字段=无对象（开关 A 关闭收集形态）。
		}
		$push = $this->incr_push ?? array( $this, 'default_incr_push' );
		$push( $comment_id );
		return true;
	}

	/**
	 * 跑一轮双车道（cron tick/CLI；单轮 ≤25——增量优先消费）。
	 *
	 * @return array{incr_processed: int, batch_processed: int, stopped: string}
	 */
	public function run_round(): array {
		$out = array( 'incr_processed' => 0, 'batch_processed' => 0, 'stopped' => '' );

		if ( empty( $this->settings['url_audit']['enabled'] ) ) {
			$out['stopped'] = 'disabled';
			return $out;
		}

		$profile  = (string) ( $this->settings['url_audit']['profile'] ?? 'conservative' );
		$take     = $this->incr_take ?? array( $this, 'default_incr_take' );
		$batch    = $this->batch_ids ?? array( $this, 'default_batch_ids' );
		$started  = ( $this->clock )();
		$deadline = $started + 240; // ≤4 分钟墙钟（nw §2.5-6）.

		while ( ( $out['incr_processed'] + $out['batch_processed'] ) < self::BATCH_MAX && ( $this->clock )() < $deadline ) {
			// 增量优先（nw §4.4：低延迟车道优先消费）。
			$incr_ids = $take( 1 );
			$comment_id = 0;
			$lane       = '';
			if ( array() !== $incr_ids ) {
				$comment_id = (int) $incr_ids[0];
				$lane       = 'incr';
				// 增量日预算闸。
				if ( $this->quota_reached( 'incr_quota', 'url_audit_incr' ) ) {
					$out['stopped'] = 'incr_quota_exhausted'; // 增量停转=落 fail-review 现状语义（零新失败模式）。
					return $out;
				}
			} else {
				// 批量车道（增量队列空时消费；批量预算闸独立——批量先停增量继续）。
				if ( $this->quota_reached( 'batch_quota', 'url_audit_batch' ) ) {
					$out['stopped'] = 'batch_quota_exhausted';
					return $out;
				}
				$batch_ids = $batch( 1, array() );
				if ( array() === $batch_ids ) {
					break; // 双车道全空。
				}
				$comment_id = (int) $batch_ids[0];
				$lane       = 'batch';
			}

			$this->process_comment( $comment_id, $lane, $profile, $out );
			++$out[ 'incr' === $lane ? 'incr_processed' : 'batch_processed' ];
		}

		return $out;
	}

	/**
	 * 单条处理：缓存→fetch→（博查）→judge→处置（状态机终态；fetch/LLM 穷尽失败=
	 * inconclusive 保持待审——永不静默放行）。
	 *
	 * @param int    $comment_id 评论 id。
	 * @param string $lane       incr|batch。
	 * @param string $profile    处置档。
	 * @param array  $out        结果累计。
	 * @return void
	 */
	private function process_comment( int $comment_id, string $lane, string $profile, array &$out ): void {
		$reader = $this->comment_reader ?? array( $this, 'default_comment_reader' );
		$meta_reader = $this->meta_reader ?? array( $this, 'default_meta_reader' );
		$meta_writer = $this->meta_writer ?? array( $this, 'default_meta_writer' );
		$executor    = $this->disposal_executor ?? array( $this, 'default_disposal_executor' );
		$fetcher_f   = $this->fetcher_factory ?? array( $this, 'default_fetcher_factory' );
		$judge_f     = $this->judge_factory ?? array( $this, 'default_judge_factory' );

		$comment = $reader( $comment_id );
		if ( null === $comment ) {
			// 已删评论：job 空转完结（nw §4.2——不报错）。
			$meta_writer( $comment_id, null );
			return;
		}
		$url = trim( (string) ( $comment['url'] ?? '' ) );
		if ( '' === $url ) {
			$meta_writer( $comment_id, null );
			return;
		}

		// 幂等：已标记跳过（编辑重提/重复扫描）。
		$existing = $meta_reader( $comment_id );
		if ( is_array( $existing ) && isset( $existing['verdict'] ) ) {
			return;
		}

		// ① 缓存命中（同 URL 只烧一次——nw §2.5-2）。
		$cached = UrlAuditCache::get( $url );
		$judgement = null;
		$fetch_meta = array();

		if ( is_array( $cached ) && isset( $cached['verdict'] ) ) {
			// 命中 reject（高置信）走矩阵；命中 pass 只记录不改变状态；inconclusive 保持待审。
			$judgement = array(
				'verdict'    => (string) $cached['verdict'],
				'confidence' => (float) ( $cached['confidence'] ?? 0 ),
				'reason'     => (string) ( $cached['reason'] ?? '' ),
				'hits'       => is_array( $cached['hits'] ?? null ) ? $cached['hits'] : array(),
			);
			$fetch_meta = array( 'cache' => 'hit' );
		} else {
			// ② fetch（缓存 miss）。
			$fetcher = $fetcher_f();
			$fetched = $fetcher->fetch( $url, ! empty( $this->settings['url_audit']['robots_comply'] ) );
			$fetch_meta = array(
				'cache'       => 'miss',
				'fetch_class' => $fetched['fetch_class'],
				'final_url'   => $fetched['final_url'],
				'hops'        => $fetched['hop_hosts'],
				'redirects'   => $fetched['redirects'],
			);

			if ( 'ok' !== $fetched['fetch_class'] ) {
				// 失败类→inconclusive（cloaking_suspect→review 倾向）——永不静默放行永不静默定罪。
				$state = UrlFetchPolicy::fetch_class_to_state( $fetched['fetch_class'] );
				$judgement = array(
					'verdict'    => $state['state'],
					'confidence' => 0.0,
					'reason'     => 'fetch: ' . $fetched['fetch_class'],
					'hits'       => array(),
				);
				// 博查交叉信号（可选启用且 fetch 失败类时——降低 inconclusive 占比）。
				if ( 'inconclusive' === $judgement['verdict'] && ! empty( $this->settings['url_audit']['bocha']['enabled'] ) ) {
					$judgement['bocha'] = 'available_not_used'; // 信号记录位——首版博查仅 LLM 判定路径消费。
				}
				UrlAuditCache::put( $url, array(
					'verdict'       => $judgement['verdict'],
					'confidence'    => 0.0,
					'reason'        => $judgement['reason'],
					'hits'          => array(),
					'fetch_class'   => $fetched['fetch_class'],
					'final_url'     => $fetched['final_url'],
					'judged_at'     => (int) ( $this->clock )(),
					'prompt_version' => UrlJudge::PROMPT_VERSION,
				) );
			} else {
				// ③ LLM 判定（URL 审核专用 prompt——nw §2.2；caller 分车道计量）。
				$engine = $judge_f();
				$judgement = $engine['judge']->judge( $fetched, $url, (string) $comment['author'] . "\n" . (string) $comment['body'], 'incr' === $lane ? 'url_audit_incr' : 'url_audit_batch' );
				$fetch_meta['llm'] = true;
				// ④ 缓存写入（TTL 按 verdict 分档）。
				UrlAuditCache::put( $url, array_merge( $judgement, array(
					'final_url'     => $fetched['final_url'],
					'judged_at'     => (int) ( $this->clock )(),
					'prompt_version' => UrlJudge::PROMPT_VERSION,
					'model'         => $engine['model'],
				) ) );
			}
		}

		// ⑤ 处置（增量矩阵/存量 flag——UrlVerdicts 收口）。
		$meta = array(
			'verdict'    => $judgement['verdict'],
			'confidence' => $judgement['confidence'],
			'reason'     => mb_substr( (string) $judgement['reason'], 0, 200 ),
			'hits'       => $judgement['hits'],
			'lane'       => $lane,
			'fetch'      => $fetch_meta,
			'judged_at'  => (int) ( $this->clock )(),
			'profile'    => $profile,
		);

		if ( 'batch' === $lane ) {
			$existing_disposal = UrlVerdicts::disposal_existing( $judgement['verdict'], (float) $judgement['confidence'] );
			$meta['action']    = $existing_disposal['action'];
			$meta['note']      = $existing_disposal['note'];
		} else {
			$disposal = UrlVerdicts::disposal(
				$judgement['verdict'],
				(float) $judgement['confidence'],
				$profile,
				! empty( $judgement['injection_attempt'] )
			);
			$meta['action'] = $disposal['action'];
			$meta['note']   = $disposal['note'];
			if ( $disposal['auto_url_reject'] ) {
				$meta['auto_url_reject'] = true;
				$meta['undo']            = true; // 可一键批量撤销（审计 trail 撤销落事件）。
			}
		}

		$meta_writer( $comment_id, $meta );

		// 自动标垃圾执行（均衡/激进档高置信——wp_set_comment_status 'spam' 可逆终态）。
		if ( 'incr' === $lane && ( $meta['action'] ?? '' ) === 'spam' && ! empty( $meta['auto_url_reject'] ) ) {
			$executor( $comment_id, 'spam' );
		}
	}

	/**
	 * 撤销自动标垃圾（K9——审计 trail：撤销=恢复待审+meta 清 auto_url_reject）。
	 *
	 * @param int[] $comment_ids 待撤销评论 id。
	 * @return int 撤销数。
	 */
	public function undo_auto_rejects( array $comment_ids ): int {
		$reader = $this->comment_reader ?? array( $this, 'default_comment_reader' );
		$meta_reader = $this->meta_reader ?? array( $this, 'default_meta_reader' );
		$meta_writer = $this->meta_writer ?? array( $this, 'default_meta_writer' );
		$executor    = $this->disposal_executor ?? array( $this, 'default_disposal_executor' );
		$count = 0;
		foreach ( $comment_ids as $id ) {
			$meta = $meta_reader( (int) $id );
			if ( ! is_array( $meta ) || empty( $meta['auto_url_reject'] ) ) {
				continue;
			}
			$executor( (int) $id, 'hold' ); // 恢复待审（可逆终态回退）。
			unset( $meta['auto_url_reject'], $meta['undo'] );
			$meta['undone_at'] = (int) ( $this->clock )();
			$meta_writer( (int) $id, $meta );
			++$count;
		}
		return $count;
	}

	/**
	 * 配额判定（url_audit.incr_quota/batch_quota 0=不限量）。
	 *
	 * @param string $quota_key 设置键。
	 * @param string $usage_key Usage 键。
	 * @return bool
	 */
	private function quota_reached( string $quota_key, string $usage_key ): bool {
		$quota = (int) ( $this->settings['url_audit'][ $quota_key ] ?? 0 );
		if ( $quota <= 0 ) {
			return false;
		}
		return Usage::quota_reached( Usage::get(), $usage_key, $quota );
	}

	/* -------------------------------------------------------------------
	 * 默认 WP 通道（生产装配；单测全注入）
	 * ------------------------------------------------------------------ */

	/**
	 * @param int $id 评论 id。
	 * @return array{author: string, body: string, url: string, post_status: string}|null
	 */
	public function default_comment_reader( int $id ): ?array {
		$comment = get_comment( $id );
		if ( ! $comment ) {
			return null;
		}
		return array(
			'author'      => (string) $comment->comment_author,
			'body'        => (string) $comment->comment_content,
			'url'         => (string) $comment->comment_author_url,
			'post_status' => (string) get_post_status( (int) $comment->comment_post_ID ),
		);
	}

	/**
	 * @param int $id 评论 id。
	 * @return array<string, mixed>|null
	 */
	public function default_meta_reader( int $id ): ?array {
		$meta = get_comment_meta( $id, self::META_KEY, true );
		return is_array( $meta ) && array() !== $meta ? $meta : null;
	}

	/**
	 * @param int                      $id   评论 id。
	 * @param array<string, mixed>|null $meta null=清。
	 * @return void
	 */
	public function default_meta_writer( int $id, ?array $meta ): void {
		if ( null === $meta ) {
			delete_comment_meta( $id, self::META_KEY );
			return;
		}
		update_comment_meta( $id, self::META_KEY, $meta );
	}

	/**
	 * @param int    $id     评论 id。
	 * @param string $action spam|hold（wp_set_comment_status——可逆终态面）。
	 * @return bool
	 */
	public function default_disposal_executor( int $id, string $action ): bool {
		return (bool) wp_set_comment_status( $id, $action );
	}

	/**
	 * @param int $id 评论 id。
	 * @return void
	 */
	public function default_incr_push( int $id ): void {
		$queue = get_option( self::OPTION_INCR_QUEUE, array() );
		$queue = is_array( $queue ) ? $queue : array();
		if ( ! in_array( $id, $queue, true ) ) {
			$queue[] = $id;
			update_option( self::OPTION_INCR_QUEUE, $queue, false );
		}
	}

	/**
	 * @param int $limit 队头取出数。
	 * @return int[]
	 */
	public function default_incr_take( int $limit ): array {
		$queue = get_option( self::OPTION_INCR_QUEUE, array() );
		$queue = is_array( $queue ) ? array_map( 'intval', $queue ) : array();
		$take  = array_slice( $queue, 0, $limit );
		if ( array() !== $take ) {
			update_option( self::OPTION_INCR_QUEUE, array_slice( $queue, count( $take ) ), false );
		}
		return $take;
	}

	/**
	 * 批量车道：有 URL 且未标记的评论（升序稳定序——LEFT JOIN meta NOT EXISTS）。
	 *
	 * @param int   $limit 探测数。
	 * @param array $exclude_ids 排除（未用——查询本身按 meta 排除）。
	 * @return int[]
	 */
	public function default_batch_ids( int $limit, array $exclude_ids = array() ): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT c.comment_ID FROM {$wpdb->comments} c
				 LEFT JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = %s
				 WHERE m.meta_id IS NULL AND c.comment_approved IN ('0','1')
				 AND c.comment_author_url <> ''
				 ORDER BY c.comment_ID ASC LIMIT %d",
				self::META_KEY,
				$limit
			)
		);
		return array_map( 'intval', is_array( $rows ) ? $rows : array() );
	}

	/* -------------------------------------------------------------------
	 * 统计面（K8/K9/K10 口径——DESIGN-M5-UI §3.4；读路径零新增存储）
	 * ------------------------------------------------------------------ */

	/**
	 * 网址核查统计行三数（K8 今日核查=meta judged_at 今日条数〔缓存命中不算——今天真的
	 * 打开/判定过的〕；K9 自动标垃圾=meta auto_url_reject；K10 待复核=URL 相关待审）。
	 *
	 * @return array{today_checked: int, auto_rejected: int, pending_review: int}
	 */
	public function stats_line(): array {
		global $wpdb;
		$meta_table = $wpdb->commentmeta;
		$today_key  = gmdate( 'Y-m-d' );
		$today_checked = 0;
		$auto_rejected = 0;
		$pending_review = 0;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT meta_value FROM {$meta_table} WHERE meta_key = %s", self::META_KEY ),
			ARRAY_A
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$meta = maybe_unserialize( (string) ( $row['meta_value'] ?? '' ) );
			if ( ! is_array( $meta ) || ! isset( $meta['verdict'] ) ) {
				continue;
			}
			$judged_at = (int) ( $meta['judged_at'] ?? 0 );
			$fetch     = is_array( $meta['fetch'] ?? null ) ? $meta['fetch'] : array();
			// K8：今天真的打开/判定过的（缓存命中不算——fetch.cache=miss 且 judged_at 今日）。
			if ( 'miss' === ( $fetch['cache'] ?? '' ) && gmdate( 'Y-m-d', $judged_at ) === $today_key ) {
				++$today_checked;
			}
			if ( ! empty( $meta['auto_url_reject'] ) ) {
				++$auto_rejected; // K9.
			}
			if ( empty( $meta['auto_url_reject'] ) && in_array( (string) ( $meta['verdict'] ?? '' ), array( 'reject', 'review', 'inconclusive' ), true ) ) {
				++$pending_review; // K10：URL 相关待审（非终态）。
			}
		}
		return array( 'today_checked' => $today_checked, 'auto_rejected' => $auto_rejected, 'pending_review' => $pending_review );
	}

	/**
	 * 按 host 聚合（K6 视角——nw §5.3「同一垃圾站命中的 50 条一起看」；只含判违规，
	 * 未核实永不进此表）。
	 *
	 * @param int $limit 最多 host 数。
	 * @return array<int, array{host: string, hits: int, rejects: int}>
	 */
	public function host_aggregates( int $limit = 10 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT meta_value FROM {$wpdb->commentmeta} WHERE meta_key = %s", self::META_KEY ),
			ARRAY_A
		);
		$hosts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$meta = maybe_unserialize( (string) ( $row['meta_value'] ?? '' ) );
			if ( ! is_array( $meta ) || 'reject' !== ( $meta['verdict'] ?? '' ) ) {
				continue; // 只含「看清楚了且有问题」——未核实与 pass 不进聚合.
			}
			$fetch = is_array( $meta['fetch'] ?? null ) ? $meta['fetch'] : array();
			$host  = (string) ( parse_url( (string) ( $fetch['final_url'] ?? '' ), PHP_URL_HOST ) ?? '' );
			if ( '' === $host ) {
				continue;
			}
			$hosts[ $host ] ??= array( 'host' => $host, 'hits' => 0, 'rejects' => 0 );
			++$hosts[ $host ]['hits'];
			if ( (float) ( $meta['confidence'] ?? 0 ) >= 0.8 ) {
				++$hosts[ $host ]['rejects'];
			}
		}
		usort( $hosts, static fn( $a, $b ) => $b['hits'] <=> $a['hits'] );
		return array_slice( $hosts, 0, $limit );
	}

	/**
	 * fetcher 工厂（RemoteClient 传输脊——计量 usage_key 由 judge 传）。
	 *
	 * @return UrlFetcher
	 */
	public function default_fetcher_factory(): UrlFetcher {
		return new UrlFetcher( $this->default_client() );
	}

	/**
	 * 判定工厂（DeepSeek via 协议族配置+URL 审核 prompt；博查信号源可选装配）。
	 *
	 * @return array{judge: UrlJudgeEngine, model: string, bocha: BochaSearch|null}
	 */
	public function default_judge_factory(): array {
		$deepseek = is_array( $this->settings['review']['cloud']['deepseek'] ?? null ) ? $this->settings['review']['cloud']['deepseek'] : array();
		$config   = \WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver::config_from_settings( $this->settings );
		$client   = $this->default_client();
		$bocha    = null;
		if ( ! empty( $this->settings['url_audit']['bocha']['enabled'] ) && '' !== (string) ( $this->settings['url_audit']['bocha']['api_key'] ?? '' ) ) {
			$bocha = new BochaSearch( (string) $this->settings['url_audit']['bocha']['api_key'], $client );
		}
		$engine = new UrlJudgeEngine(
			$config,
			$client,
			(string) ( $deepseek['model'] ?? 'deepseek-flash' ),
			$bocha
		);
		return array( 'judge' => $engine, 'model' => (string) ( $deepseek['model'] ?? 'deepseek-flash' ), 'bocha' => $bocha );
	}

	/**
	 * @return RemoteClient
	 */
	private function default_client(): RemoteClient {
		return new RemoteClient( null, array( Usage::class, 'bump_option' ) );
	}
}
