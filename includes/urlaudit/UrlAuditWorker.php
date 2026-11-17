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
 * 全局每轮限额；日预算闸 url_audit.incr_quota/batch_quota（K1 计量键 url_audit_incr/
 * url_audit_batch——【M6·P1-3】引擎按车道上报自此闸表对齐；K2 token 记账 caller 同键）；
 * 批量车道先停增量车道继续（降级秩序，nw §2.5-5）。
 * 【M6·P1-3】批量回捞显式化：原「批量车道开启即对全部历史 URL 评论自动体检」废弃——
 * 改 Tab⑦ 显式动作「开始存量网址核查」一次性入队（OPTION_BATCH_QUEUE FIFO），配额
 * 50/日+暂停黄条语义照旧（D6 分工：操作卡 Tab⑦/报告卡 Tab⑥）。
 *
 * 处置哲学：增量车道【M6·批E·§4-add】（2026-11-16 用户拍板——「没看清」统一人工判定）：
 * inconclusive 与 review 一律→hold（待审=统一人工判定入口，三档 profile 皆然——恒不定罪
 * §0-7 不变，hold 可手动批准恢复，误伤面用户明示接受）；pass 零动作；reject 按 profile
 * （conservative 只标记/balanced≥0.95/aggressive≥0.8 自动标垃圾，auto_url_reject meta
 * 可一键批量撤销）。存量车道【M6·批C·C-2】（用户 2026-11-16 拍板——原 nw §5.3「仅标记」
 * 退役）：reject→垃圾箱（spam）、review 与 inconclusive（没看清）→待审（hold）、pass→
 * 不动；全部走 wp_set_comment_status 可逆终态面（垃圾箱可一键恢复、待审可批准），处置
 * 结果落 meta disposed_to（spam|hold|none）溯源。宇宙 approved-only（C-1）天然规避触碰
 * 垃圾/待审。
 *
 * 搜索兜底【M6·批F-a·§4-add2】（0.6.8，2026-11-16 第五轮反馈——「打不开时真用搜索兜底」）：
 * fetch 失败族（outcome 非 ok 全族）且搜索源可用 → 先搜索后判定（search_evidence 块送
 * 判定引擎——仅当搜索结果明确将域名与违规类目直接关联才可 reject，否则 review/inconclusive；
 * 九键闭集/处置矩阵/profile 定罪门全不动）；搜索不可用/失败/空 → 维持 inconclusive 现行为；
 * 搜索兜底产出的判定 meta 记 evidence=search（缓存行同标——报告与人工复核可溯源）。
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
	 * 【M6·P1-3】批量车道显式回捞 admin action 名（Tab⑦ 操作卡「开始存量网址核查」）。
	 */
	public const ACTION_BACKLOG = 'wink_gatekeeper_url_audit_backlog';

	/**
	 * 【M6·P1-3】批量车道显式回捞队列 option（非 settings 树）：int[] 评论 id 升序（FIFO）。
	 *
	 * 「开启即自动回捞」已废弃（审计 P1-3：回捞无有效上限=LLM 花费敞口）——批量车道
	 * 只消费本队列；入队唯一入口=Tab⑦ 显式动作（ACTION_BACKLOG，manage_options+nonce
	 * 双闸）； pacing 走 batch_quota 50/日（暂停黄条语义照旧）。
	 */
	public const OPTION_BATCH_QUEUE = 'wink_gatekeeper_url_audit_batch_queue';

	/**
	 * 【M6·批C·C-3】存量网址核查运行记录 option（非 settings 树）：{started_at:int,
	 * ids:int[], enqueued:int}——入队时建/归并（上轮已完成则覆盖）；报告渲染时由
	 * 清单 meta 反查统计（起止时间+口径三行+残留），tick 零写（最小方案——施工图
	 * §3b C-3「tick 消费更新或完成后一次性由 meta 反查」取后者）。
	 */
	public const OPTION_BACKLOG_RECORD = 'wink_gatekeeper_url_audit_backlog_record';

	/**
	 * 单轮处理上限（nw §2.5-6：每轮 ≤25 URL 或 ≤4 分钟——与存量审核同闸）。
	 */
	public const BATCH_MAX = 25;

	/**
	 * 【M6·批B·B-5】配额暂停边沿标记 option（非 settings 树）：{incr: Y-m-d, batch: Y-m-d}
	 * ——同日同车道只发一次暂停事件（边沿去重）；跨日自动开新边沿（配额次日凌晨重置，
	 * 再撞闸=新暂停片段）。
	 */
	public const OPTION_PAUSE_EDGE = 'wink_gatekeeper_url_audit_pause_edge';

	/**
	 * 【M6·批H2·H2-2】DeepSeek 抓取连续未成功计数 option（非 settings 树）：
	 * {n: int, last_class: string, last_at: int, alerted: bool}——fetch_provider=deepseek
	 * 且 fetch_class≠ok 计数+1、ok 清零；正常混队个案失败被成功自愈，只有系统性故障
	 * （端点故障/网络断/key 失效）攒得满阈值（低误报口径）；N 达阈落边沿事件+Tab③ 黄条
	 * （48h 新鲜窗——修复后无新流量不永久挂条）。
	 */
	public const OPTION_FETCH_STREAK = 'wink_gatekeeper_url_audit_fetch_streak';

	/**
	 * 【M6·批H2·H2-2】起报阈值（连续未成功次数；达到即一条事件+黄条——alerted 边沿去重）。
	 */
	public const FETCH_STREAK_ALERT_AT = 5;

	/**
	 * 【M6·批H2·H2-2】黄条新鲜窗（秒；48h——陈旧静默）。
	 */
	public const FETCH_STREAK_FRESH_SECONDS = 172800;

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

	/** @var callable fn(int $id, string $action): bool 处置执行（wp_set_comment_status——spam 自动定罪/hold 没看清进待审两可逆终态消费）。 */
	private $disposal_executor;

	/** @var callable fn(): UrlFetcher fetcher 工厂。 */
	private $fetcher_factory;

	/** @var callable fn(): array{judge: UrlJudgeEngine, bocha: BochaSearch|null} 判定工厂。 */
	private $judge_factory;

	/** @var callable fn(): int[] 批量回捞扫描宇宙（有 URL 且无判定 meta——显式动作消费）。 */
	private $backlog_finder;

	/** @var callable fn(): int[] 批量回捞队列读取。 */
	private $batch_queue_get;

	/** @var callable fn(int[]): void 批量回捞队列写回。 */
	private $batch_queue_set;

	/** @var callable fn(): float 时钟。 */
	private $clock;

	/**
	 * 【M6·批B·B-5】运行性错误事件 sink（fn(string $reason, array $hits, array $meta): void）。
	 *
	 * 缺省 WP 通道=EventsTable 直插（gate=comment、stage=url_audit、driver=deepseek、
	 * verdict=error——DriverHealth health 行同款不经 should_record；verdict=error 被
	 * fault_predicate_sql 覆盖→报错历史/今日故障卡可见）。单测全注入。
	 *
	 * @var callable|null
	 */
	private $event_recorder;

	/**
	 * 【M6·批H2·H2-2】streak 计数读取闭包（fn(): array——缺省 WP option 通道）。
	 *
	 * @var callable|null
	 */
	private $streak_get;

	/**
	 * 【M6·批H2·H2-2】streak 计数写回闭包（fn(array $value): void——缺省 WP option 通道）。
	 *
	 * @var callable|null
	 */
	private $streak_set;

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
		$this->backlog_finder     = $hooks['backlog_finder'] ?? null;
		$this->batch_queue_get    = $hooks['batch_queue_get'] ?? null;
		$this->batch_queue_set    = $hooks['batch_queue_set'] ?? null;
		$this->clock              = $hooks['clock'] ?? static fn(): float => microtime( true );
		$this->event_recorder     = $hooks['event_recorder'] ?? null; // 【M6·批B·B-5】缺省=EventsTable 直插（见属性注）。
		// 【M6·批H2·H2-2】缺省 WP option 通道（autoload=false）；单测注入闭包对（EventSampling 注入范式同款）。
		$this->streak_get         = $hooks['streak_get'] ?? static fn(): array => (array) get_option( self::OPTION_FETCH_STREAK, array() );
		$this->streak_set         = $hooks['streak_set'] ?? static function ( array $value ): void {
			update_option( self::OPTION_FETCH_STREAK, $value, false );
		};
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
	 * 【M6·批B·B-5】轮末事件发射面：运行性错误（LLM transport/contract 类）每轮至多
	 * 1 条（多评论同轮失败只记首条——防 P1-4 写放大）；配额暂停边沿同日同车道去重。
	 * 恒不发射：业务性 inconclusive（目标站 fetch 失败族——§0-7 fetch 失败≠违规）。
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
					break; // 【M6·批B·B-5】单出口——轮末统一走事件发射面。
				}
			} else {
				// 批量车道（增量队列空时消费；批量预算闸独立——批量先停增量继续）。
				if ( $this->quota_reached( 'batch_quota', 'url_audit_batch' ) ) {
					$out['stopped'] = 'batch_quota_exhausted';
					break;
				}
				$batch_ids = $batch( 1, array() );
				if ( array() === $batch_ids ) {
					break; // 双车道全空。
				}
				$comment_id = (int) $batch_ids[0];
				$lane       = 'batch';
			}

			// 【M6·批D·D-5】fetch 成功次日配额闸（§4 C-5：出队后 fetch 前；0=不限）——
			// url_audit_fetch 计 200 且有正文的成功次（官方计费口径），闸拦的是真烧钱动作。
			if ( $this->quota_reached( 'fetch_daily_cap', Usage::KEY_URL_AUDIT_FETCH ) ) {
				$out['stopped'] = 'fetch_cap_exhausted';
				break;
			}

			$this->process_comment( $comment_id, $lane, $profile, $out );
			++$out[ 'incr' === $lane ? 'incr_processed' : 'batch_processed' ];

			// 【M6·批D·D-4】fetch 账号级暂停（402 余额不足——process_comment 置 stopped）：
			// 当条已按 inconclusive 收口，本轮到此为止（黄条语义，次日/充值后自动恢复）。
			if ( '' !== (string) ( $out['stopped'] ?? '' ) ) {
				break;
			}
		}

		// 【M6·批B·B-5】轮末事件发射面（disabled 早退不经过——功能关=零事件零外呼）。
		if ( isset( $out['runtime_error'] ) && is_array( $out['runtime_error'] ) ) {
			$this->emit_runtime_error( $out['runtime_error'] );
			unset( $out['runtime_error'] );
		}
		$this->maybe_emit_pause_edge( (string) $out['stopped'] );

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

		// 【M6·批D·D-6】全量重查语义（用户 2026-11-16 拍板——§3b-add「全部重查一遍——
		// 忽略历史结果，网站内容可能已变化」）：批量车道=重查——已判定也重查（不再幂等
		// 跳过），新判定覆盖旧 meta、disposed_to 按 §3b C-2 矩阵重算。增量车道语义零改动。
		$recheck = 'batch' === $lane;

		// 幂等：已标记跳过（编辑重提/重复扫描）——仅增量车道。
		$existing = $meta_reader( $comment_id );
		if ( ! $recheck && is_array( $existing ) && isset( $existing['verdict'] ) ) {
			return;
		}

		// ① 缓存命中（同 URL 只烧一次——nw §2.5-2）。【D-6】重查绕过（force refetch——
		// pass 14d/reject 90d 缓存不适用于显式重查；「之前正常现在不正常」正是重查的意义）。
		$cached = $recheck ? null : UrlAuditCache::get( $url );
		$judgement = null;
		$fetch_meta = array();
		$evidence   = ''; // 【M6·批F-a】判定证据形态（''=页面正文|'search'=搜索线索兜底）。

		if ( is_array( $cached ) && isset( $cached['verdict'] ) ) {
			// 命中 reject（高置信）走矩阵；命中 pass 只记录不改变状态；inconclusive 保持待审。
			$judgement = array(
				'verdict'    => (string) $cached['verdict'],
				'confidence' => (float) ( $cached['confidence'] ?? 0 ),
				'reason'     => (string) ( $cached['reason'] ?? '' ),
				'hits'       => is_array( $cached['hits'] ?? null ) ? $cached['hits'] : array(),
			);
			$fetch_meta = array( 'cache' => 'hit' );
			// 【M6·批F-a】缓存行 evidence 传播：命中行若系搜索证据判定产出，meta 如实
			// 沿用（报告/人工复核「此判定基于搜索线索而非页面正文」对缓存命中同样成立）。
			if ( 'search' === (string) ( $cached['evidence'] ?? '' ) ) {
				$evidence = 'search';
			}
		} else {
			// ② fetch（缓存 miss / 重查强制）。
			// 【M6·批H·H-1】抓取未配置档（§0-1 用户拍板——本站直抓永不自动启用）：inherit
			// 解析=unconfigured（云端审核非 DeepSeek/未配置）→ 零 fetch 零 judge 零外呼零缓存，
			// judge 以 fetch:unconfigured → inconclusive → hold 收口（批 E 语义——待审=统一
			// 人工判定入口）。配置态非 URL 语义：不写 UrlAuditCache（配置修正后同 URL 应真判，
			// 24h inconclusive 缓存不得遮蔽）——meta 照写（防每 tick 重处理空转）。
			if ( 'unconfigured' === self::fetcher_mode( $this->settings ) ) {
				$judgement = array(
					'verdict'    => 'inconclusive',
					'confidence' => 0.0,
					'reason'     => 'fetch: unconfigured',
					'hits'       => array(),
				);
				$fetch_meta = array(
					'cache'          => 'miss',
					'fetch_class'    => 'unconfigured',
					'final_url'      => $url,
					'hops'           => array(),
					'redirects'      => 0,
					'fetch_provider' => 'unconfigured',
					'http_code'      => 0,
				);
				$this->finish_and_dispose( $comment_id, $lane, $profile, $judgement, $fetch_meta, $evidence );
				return;
			}

			$fetcher = $fetcher_f();
			$fetched = $fetcher->fetch( $url, ! empty( $this->settings['url_audit']['robots_comply'] ) );
			$this->record_fetch_streak( $fetched, $lane ); // 【M6·批H2·H2-2】连续未成功计数（deepseek provider 限定——self 业务常态豁免；缓存命中分支不经过=无新尝试不计不清）。
			$fetch_meta = array(
				'cache'       => 'miss',
				'fetch_class' => $fetched['fetch_class'],
				'final_url'   => $fetched['final_url'],
				'hops'        => $fetched['hop_hosts'],
				'redirects'   => $fetched['redirects'],
				// 【M6·批D·D-4】抓取服务商溯源（C-4 注：deepseek 模式记 deepseek、self 模式
				// 记 self——存量判定可查「当年是谁打开的网页」）+http_code 归真。
				'fetch_provider' => (string) ( $fetched['fetch_provider'] ?? 'self' ),
				'http_code'      => (int) ( $fetched['status'] ?? 0 ),
			);

			// 【M6·批D·D-4】fetch 账号级失败捕获（DeepSeek Fetch 401 凭据无效/402 余额不足
			// ——U-3 ② 运行性错误事件面；轮末发射每轮至多 1 条。目标站业务类 404/403/超时
			// 恒不标记——§0-7 fetch 失败≠违规）。
			if ( ! isset( $out['runtime_error'] ) && ! empty( $fetched['account_error'] ) ) {
				$out['runtime_error'] = array(
					'source'    => 'fetch',
					'lane'      => $lane,
					'category'  => (string) $fetched['fetch_class'],
					'http_code' => (int) ( $fetched['status'] ?? 0 ),
				);
			}

			if ( 'ok' !== $fetched['fetch_class'] ) {
				// 【M6·批F-a·§4-add2】fetch 失败族（outcome 非 ok 全族）+搜索源可用 → 先搜索
				// 后判定（搜索证据兜底——违规站对一切抓取端普遍无正文可给〔批 E 背景实证〕，
				// 搜索线索是唯一剩余证据面）。搜索不可用/调用失败/结果空 → 维持 inconclusive
				// （现行为回归，恒不出错 §0-7）。
				$engine = $judge_f();
				$search_provider = is_array( $engine ) ? ( $engine['bocha'] ?? null ) : null;
				$host = (string) ( parse_url( $url, PHP_URL_HOST ) ?? '' );
				$signal = null;
				if ( $search_provider instanceof UrlSearchProvider && $search_provider->is_configured() && '' !== $host ) {
					// query=域名本体（搜索源语法择优：DeepSeek tools/search 契约 query 为
					// 纯文本 1-128 字、site: 算子无文档背书；域名本体在两源皆可用——报告落档）。
					$signal = $search_provider->search_site( $host );
				}
				$signal_usable = is_array( $signal )
					&& ( array() !== ( $signal['snippets'] ?? array() ) || '' !== (string) ( $signal['siteName'] ?? '' ) );

				if ( $signal_usable ) {
					// ②' 搜索证据判定（无页面正文；prompt：仅当搜索结果明确将域名与违规类目
					// 直接关联才可 reject，否则 review/inconclusive；九键闭集/解析器/min_confidence/
					// 注入检测/计量与页面正文模式全共用——send_and_parse 同脊）。
					$judgement = $engine['judge']->judge_on_search_evidence(
						$signal,
						$url,
						(string) $comment['author'] . "\n" . (string) $comment['body'],
						'incr' === $lane ? 'url_audit_incr' : 'url_audit_batch'
					);
					$fetch_meta['llm'] = true;
					$evidence          = 'search';
					// 【M6·批B·B-5①】LLM 运行性错误捕获同款（搜索证据判定路径——transport/
					// contract 类照进报错历史；业务 inconclusive 恒不标记）。
					$llm_meta = $engine['judge']->last_meta();
					if ( ! isset( $out['runtime_error'] ) && isset( $llm_meta['llm_error'] ) && is_array( $llm_meta['llm_error'] ) ) {
						$out['runtime_error'] = array(
							'lane'      => $lane,
							'category'  => (string) ( $llm_meta['llm_error']['category'] ?? '' ),
							'http_code' => (int) ( $llm_meta['llm_error']['http_code'] ?? 0 ),
						);
					}
					// ④' 缓存写入（搜索证据判定照常入缓存——TTL 按 verdict 分档；evidence/
					// fetch_class/prompt_version 溯源行——「此判定基于搜索线索」可查）。
					UrlAuditCache::put( $url, array_merge( $judgement, array(
						'final_url'      => $fetched['final_url'],
						'judged_at'      => (int) ( $this->clock )(),
						'prompt_version' => UrlJudge::SEARCH_PROMPT_VERSION,
						'model'          => (string) ( $engine['model'] ?? '' ),
						'evidence'       => 'search',
						'fetch_class'    => $fetched['fetch_class'],
					) ) );
				} else {
					// 搜索不可用/失败/空 → 失败类→inconclusive（cloaking_suspect→review 倾向）
					// ——永不静默放行永不静默定罪（现行为回归）。
					$state = UrlFetchPolicy::fetch_class_to_state( $fetched['fetch_class'] );
					$judgement = array(
						'verdict'    => $state['state'],
						'confidence' => 0.0,
						'reason'     => 'fetch: ' . $fetched['fetch_class'],
						'hits'       => array(),
					);
					// 博查交叉信号（可选启用且 fetch 失败类时——降低 inconclusive 占比）。
					if ( 'inconclusive' === $judgement['verdict'] && ! empty( $this->settings['url_audit']['bocha']['enabled'] ) ) {
						$judgement['bocha'] = 'available_not_used'; // 信号记录位——搜索兜底未产出可用证据。
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
				}
				// 【M6·批D·D-4】402 余额不足 → 车道暂停（黄条语义——额度/余额类账号侧
				// 停转，次日或充值后自动恢复）；当条照常收口后停轮（搜索兜底不改暂停语义）。
				if ( 'quota_exhausted' === $fetched['fetch_class'] ) {
					$out['stopped'] = 'fetch_account_exhausted';
				}
			} else {
				// ③ LLM 判定（URL 审核专用 prompt——nw §2.2；caller 分车道计量）。
				$engine = $judge_f();
				$judgement = $engine['judge']->judge( $fetched, $url, (string) $comment['author'] . "\n" . (string) $comment['body'], 'incr' === $lane ? 'url_audit_incr' : 'url_audit_batch' );
				$fetch_meta['llm'] = true;
				// 【M6·批B·B-5①】LLM 运行性错误捕获（transport/semantic/contract/parse——
				// 非业务 inconclusive；轮末发射每轮至多 1 条。业务判定/fetch 失败族恒不标记）。
				$llm_meta = $engine['judge']->last_meta();
				if ( ! isset( $out['runtime_error'] ) && isset( $llm_meta['llm_error'] ) && is_array( $llm_meta['llm_error'] ) ) {
					$out['runtime_error'] = array(
						'lane'      => $lane,
						'category'  => (string) ( $llm_meta['llm_error']['category'] ?? '' ),
						'http_code' => (int) ( $llm_meta['llm_error']['http_code'] ?? 0 ),
					);
				}
				// ④ 缓存写入（TTL 按 verdict 分档）。
				UrlAuditCache::put( $url, array_merge( $judgement, array(
					'final_url'     => $fetched['final_url'],
					'judged_at'     => (int) ( $this->clock )(),
					'prompt_version' => UrlJudge::PROMPT_VERSION,
					'model'         => $engine['model'],
				) ) );
			}
		}

		// ⑤ 处置（增量矩阵/存量 flag——UrlVerdicts 收口）。【M6·批H·H-1】收口块提为共用件
		// finish_and_dispose（unconfigured 档同脊收口——meta 写+处置执行语义逐字一致）。
		$this->finish_and_dispose( $comment_id, $lane, $profile, $judgement, $fetch_meta, $evidence );
	}

	/**
	 * 【M6·批H·H-1】判定收口共用件（自 process_comment ⑤ 块原样上提——零行为漂移）：
	 * meta 组装（verdict/reason 截 200/fetch 溯源/evidence）+ 双车道处置矩阵
	 * （incr=UrlVerdicts::disposal / batch=disposal_existing）+ meta 写 + disposed_to∈
	 * {spam,hold} 的处置执行（disposal_executor 注入面）。
	 *
	 * @param int                   $comment_id 评论 id。
	 * @param string                $lane       incr|batch。
	 * @param string                $profile    处置档。
	 * @param array<string, mixed>  $judgement  判定（verdict/confidence/reason/hits）。
	 * @param array<string, mixed>  $fetch_meta fetch 溯源块。
	 * @param string                $evidence   证据形态（''=页面正文|'search'=搜索线索）。
	 * @return void
	 */
	private function finish_and_dispose( int $comment_id, string $lane, string $profile, array $judgement, array $fetch_meta, string $evidence ): void {
		$meta_writer = $this->meta_writer ?? array( $this, 'default_meta_writer' );
		$executor    = $this->disposal_executor ?? array( $this, 'default_disposal_executor' );

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
		// 【M6·批F-a·§4-add2】判定证据形态溯源：evidence=search=「此判定基于搜索线索而非
		// 页面正文」（fetch 失败族兜底路径/缓存命中传播）；缺省键=页面正文取证（零键不撒谎）。
		if ( '' !== $evidence ) {
			$meta['evidence'] = $evidence;
		}

		if ( 'batch' === $lane ) {
			// 【M6·批C·C-2】存量处置（用户 2026-11-16 拍板——「仅标记」退役）：reject→
			// 垃圾箱、review/inconclusive→待审、pass→不动；可逆终态面+disposed_to 溯源。
			$existing_disposal   = UrlVerdicts::disposal_existing( $judgement['verdict'], (float) $judgement['confidence'] );
			$meta['action']      = $existing_disposal['action'];
			$meta['note']        = $existing_disposal['note'];
			$meta['disposed_to'] = in_array( $existing_disposal['action'], array( 'spam', 'hold' ), true ) ? $existing_disposal['action'] : 'none';
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
			// 【M6·批E·§4-add】disposed_to 记实际处置去向：spam（自动定罪）/hold
			// （没看清/存疑→待审=统一人工判定入口，三档 profile 皆然）/none（pass 等）。
			$meta['disposed_to'] = ! empty( $disposal['auto_url_reject'] )
				? 'spam'
				: ( 'hold' === $disposal['action'] ? 'hold' : 'none' );
		}

		$meta_writer( $comment_id, $meta );

		// 处置执行（双车道统一走 disposal_executor 注入面——wp_set_comment_status
		// 可逆终态：垃圾箱可一键恢复、待审可批准，零新增执行件）：
		//  incr 车道【M6·批E·§4-add】spam=自动定罪（均衡/激进档高置信）/hold=没看清进待审；
		//  batch 车道【M6·批C·C-2】存量矩阵 disposed_to∈{spam,hold}（宇宙 approved-only
		//  天然规避触碰垃圾/待审）。
		if ( in_array( (string) ( $meta['disposed_to'] ?? 'none' ), array( 'spam', 'hold' ), true ) ) {
			$executor( $comment_id, (string) $meta['disposed_to'] );
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
	 * 【M6·批B·B-5】运行性错误/暂停边沿事件发射（U-3 缺口补齐——报错历史可见）
	 * ------------------------------------------------------------------ */

	/**
	 * 运行性错误事件发射（【M6·批B·B-5】① LLM transport/semantic/contract 类；
	 * 【M6·批D·D-4】② fetch 账号级失败（DeepSeek Fetch 401/402）——均非业务 inconclusive）。
	 *
	 * 事件形态：gate=comment、stage=url_audit、driver=deepseek、verdict=error、
	 * rule_hit=remote_<category>（故障谓词+Labels 人话复用）、meta 最小化
	 * {reason, lane, http_code}——零 URL 零正文零 IP（PIPL）。每 tick 轮至多
	 * 1 条（process_comment 只记首条——防写放大 P1-4 同族纪律）。
	 *
	 * @param array{source?: string, lane: string, category: string, http_code: int} $err 错误信息（source=llm(缺省)|fetch）。
	 * @return void
	 */
	private function emit_runtime_error( array $err ): void {
		$category = '' !== (string) ( $err['category'] ?? '' ) ? (string) $err['category'] : 'error';
		$prefix   = 'fetch' === (string) ( $err['source'] ?? 'llm' ) ? 'fetch ' : 'llm ';
		$reason   = $prefix . $category;
		$this->record_event( $reason, array( 'remote_' . $category ), array(
			'reason'    => $reason,
			'lane'      => (string) ( $err['lane'] ?? '' ),
			'http_code' => (int) ( $err['http_code'] ?? 0 ),
		) );
	}

	/**
	 * 配额暂停边沿事件（③ incr/batch/fetch 任一闸或账号级余额暂停触发时一次；同日
	 * 同车道去重——OPTION_PAUSE_EDGE 标记持久化，跨日自动开新边沿）。
	 *
	 * 业务性 inconclusive（目标站 404/403/超时）恒不进本发射面（§0-7 fetch 失败≠违规）；
	 * 【M6·批D·D-4】fetch 账号级失败本体走 ② 运行性错误面（emit_runtime_error），
	 * 本面只记「暂停」这一状态变化。
	 *
	 * @param string $stopped run_round 停转原因（incr_quota_exhausted|batch_quota_exhausted|
	 *                        fetch_cap_exhausted|fetch_account_exhausted|…）。
	 * @return void
	 */
	private function maybe_emit_pause_edge( string $stopped ): void {
		$lane = '';
		$reason = '';
		if ( 'incr_quota_exhausted' === $stopped ) {
			$lane   = 'incr';
			$reason = 'url_audit incr quota exhausted';
		} elseif ( 'batch_quota_exhausted' === $stopped ) {
			$lane   = 'batch';
			$reason = 'url_audit batch quota exhausted';
		} elseif ( 'fetch_cap_exhausted' === $stopped ) {
			$lane   = 'fetch';
			$reason = 'url_audit fetch quota exhausted';
		} elseif ( 'fetch_account_exhausted' === $stopped ) {
			$lane   = 'fetch';
			$reason = 'url_audit fetch account exhausted';
		}
		if ( '' === $lane ) {
			return;
		}
		$today  = gmdate( 'Y-m-d', (int) ( $this->clock )() );
		$marker = get_option( self::OPTION_PAUSE_EDGE, array() );
		$marker = is_array( $marker ) ? $marker : array();
		if ( ( (string) ( $marker[ $lane ] ?? '' ) ) === $today ) {
			return; // 同日同车道已发——边沿去重（不逐 tick 重复）。
		}
		$marker[ $lane ] = $today;
		update_option( self::OPTION_PAUSE_EDGE, $marker, false );
		$this->record_event( $reason, array( 'quota_paused' ), array(
			'reason'    => $reason,
			'lane'      => $lane,
			'http_code' => 0,
		) );
	}

	/**
	 * 【M6·批H2·H2-2】抓取连续未成功计数（风险标记本体—— HANDOVER §七.1 抛光件）。
	 *
	 * 口径（不猜类别，连续未成功）：fetch_provider=deepseek 且 fetch_class≠ok → n+1；
	 * =ok → 清零（n=0+alerted 复位——正常混队个案失败被成功自愈，只有系统性故障攒得
	 * 满阈值）。self/unconfigured 不计数（self 失败=目标站业务常态 §0-7；unconfigured
	 * 短路分支不经过本点）。达阈（FETCH_STREAK_ALERT_AT）落恰一条边沿事件（alerted
	 * 去重；record_event 脊→报错历史/今日故障卡自动可见）；计数与去重独立（计数如实，
	 * 事件每连续段一条——防写放大 P1-4 同族纪律）。
	 *
	 * @param array<string, mixed> $fetched fetcher 返回（fetch_class/fetch_provider）。
	 * @param string               $lane    车道（incr|batch——事件 meta 溯源）。
	 * @return void
	 */
	private function record_fetch_streak( array $fetched, string $lane ): void {
		if ( 'deepseek' !== (string) ( $fetched['fetch_provider'] ?? 'self' ) ) {
			return; // 本站直抓/未配置不计数（provider 限定——DeepSeek 付费外呼持续零产出才是风险面）。
		}
		$class = (string) ( $fetched['fetch_class'] ?? '' );
		if ( 'ok' === $class ) {
			( $this->streak_set )( self::normalize_streak( array() ) ); // 清零+alerted 复位。
			return;
		}
		$st = self::normalize_streak( ( $this->streak_get )() );
		$st['n']++;
		$st['last_class'] = $class;
		$st['last_at']    = (int) ( $this->clock )();
		if ( $st['n'] >= self::FETCH_STREAK_ALERT_AT && ! $st['alerted'] ) {
			$st['alerted'] = true;
			$this->record_event( 'url_audit fetch streak ' . (string) $st['n'] . ' consecutive failures', array( 'fetch_streak_alert' ), array(
				'n'          => $st['n'],
				'last_class' => $st['last_class'],
				'lane'       => $lane,
			) );
		}
		( $this->streak_set )( $st );
	}

	/**
	 * 【M6·批H2·H2-2】streak option 归一（脏数据/Redis 对象缓存跨进程 int→string 同型
	 * ——I-2 纪律推广：is_numeric+(int)，非数值视为 0）。
	 *
	 * @param mixed $raw 原值。
	 * @return array{n: int, last_class: string, last_at: int, alerted: bool}
	 */
	public static function normalize_streak( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'n'          => isset( $raw['n'] ) && is_numeric( $raw['n'] ) ? (int) $raw['n'] : 0,
			'last_class' => isset( $raw['last_class'] ) && is_string( $raw['last_class'] ) ? $raw['last_class'] : '',
			'last_at'    => isset( $raw['last_at'] ) && is_numeric( $raw['last_at'] ) ? (int) $raw['last_at'] : 0,
			'alerted'    => ! empty( $raw['alerted'] ),
		);
	}

	/**
	 * 【M6·批H2·H2-2】Tab③ 黄条数据读取器（render_url_audit_card 消费）：达阈+新鲜
	 * 才返回（null=不渲染）；48h 新鲜窗=陈旧静默（修复后无新流量不永久挂条——如实：
	 * 零新成功也不谎报恢复）。
	 *
	 * @param array<string, mixed> $settings 设置树。
	 * @param int|null             $now      当前时刻（null=time()——测试注入）。
	 * @return array{n: int}|null
	 */
	public static function fetch_streak_notice_data( array $settings, ?int $now = null ): ?array {
		if ( empty( $settings['url_audit']['enabled'] ) ) {
			return null;
		}
		$st = self::normalize_streak( get_option( self::OPTION_FETCH_STREAK, array() ) );
		if ( $st['n'] < self::FETCH_STREAK_ALERT_AT ) {
			return null;
		}
		$now = $now ?? time();
		if ( $st['last_at'] <= 0 || $st['last_at'] < $now - self::FETCH_STREAK_FRESH_SECONDS ) {
			return null; // 陈旧静默。
		}
		return array( 'n' => $st['n'] );
	}

	/**
	 * 事件落库（sink 注入优先；缺省 WP 通道=EventsTable 直插——verdict=error 被
	 * fault_predicate_sql 覆盖，报错历史/今日故障卡/Tab① 最近错误摘要自动可见）。
	 *
	 * @param string          $reason 理由（verdict reason 列）。
	 * @param array<string>   $hits   命中标签（rule_hit 列）。
	 * @param array<string, mixed> $meta 事件 meta（最小化闭集）。
	 * @return void
	 */
	private function record_event( string $reason, array $hits, array $meta ): void {
		$recorder = $this->event_recorder;
		if ( null === $recorder ) {
			$recorder = static function ( string $reason, array $hits, array $meta ): void {
				\WinkGatekeeper\Stats\EventsTable::insert( 'comment', 'url_audit', 'deepseek', Verdict::error( 'deepseek', $reason, $hits ), $meta );
			};
		}
		$recorder( $reason, $hits, $meta );
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
	 * @param string $action spam|hold（wp_set_comment_status——可逆终态面；垃圾箱可一键
	 *                       恢复、待审可批准）。
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
	 * 批量车道：消费显式回捞队列（FIFO 头取——【M6·P1-3】「开启即自动回捞」废弃，
	 * 本通道不再做任何 live 扫描；扫描入队唯一入口=enqueue_batch_backlog，由 Tab⑦
	 * 显式动作触发）。
	 *
	 * @param int   $limit 探测数。
	 * @param array $exclude_ids 排除（未用——队列消费语义无排除面）。
	 * @return int[]
	 */
	public function default_batch_ids( int $limit, array $exclude_ids = array() ): array {
		$queue = $this->batch_queue_read();
		$take  = array_slice( $queue, 0, max( 0, $limit ) );
		if ( array() !== $take ) {
			$this->batch_queue_write( array_slice( $queue, count( $take ) ) );
		}
		return array_map( 'intval', $take );
	}

	/**
	 * 批量回捞队列读取（构造器注入优先；缺省 WP option 通道）。
	 *
	 * @return int[]
	 */
	private function batch_queue_read(): array {
		$get   = $this->batch_queue_get ?? static fn(): array => get_option( self::OPTION_BATCH_QUEUE, array() );
		$queue = $get();
		return is_array( $queue ) ? array_map( 'intval', $queue ) : array();
	}

	/**
	 * 批量回捞队列写回（构造器注入优先；缺省 WP option 通道，非 autoload）。
	 *
	 * @param int[] $queue 队列。
	 * @return void
	 */
	private function batch_queue_write( array $queue ): void {
		$set = $this->batch_queue_set ?? static function ( array $q ): void {
			update_option( self::OPTION_BATCH_QUEUE, array_map( 'intval', $q ), false );
		};
		$set( $queue );
	}

	/**
	 * 批量回捞队列计数（Tab⑦ 状态行展示；零 WP 外显依赖）。
	 *
	 * @return int
	 */
	public function batch_queue_count(): int {
		return count( $this->batch_queue_read() );
	}

	/**
	 * 【M6·P1-3】显式存量回捞入队（Tab⑦「开始存量网址核查」动作落点）：把「有 URL
	 * 且无判定 meta」的存量评论一次性并入批量队列（去重保序；既有队列条目在前）。
	 * 返回本次**新增**条数（0=无增量）。消费 pacing 走批量车道 batch_quota 50/日
	 * （配额闸/暂停语义照旧——本函数零外呼零判定）。
	 *
	 * 【M6·批C·C-3】入队即建运行记录 option（started_at+ids+enqueued）：上轮未完成→
	 * 归并续跑（started_at 保留）；上轮已完成（清单全落判定 meta）→新一轮覆盖。
	 * 报告渲染由 meta 反查统计（backlog_report_data——tick 零写，最小方案）。
	 *
	 * @return int 新增入队数。
	 */
	public function enqueue_batch_backlog(): int {
		$finder = $this->backlog_finder ?? array( $this, 'backlog_universe_ids' );
		$ids    = (array) $finder();
		$queue  = $this->batch_queue_read();
		$merged = array_map( 'intval', $queue );
		$added  = 0;
		$new_ids = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			if ( $id > 0 && ! in_array( $id, $merged, true ) ) {
				$merged[] = $id;
				$new_ids[] = $id;
				++$added;
			}
		}
		if ( $added > 0 ) {
			$this->batch_queue_write( $merged );
			$this->record_backlog_run( $new_ids );
		}
		return $added;
	}

	/**
	 * 【M6·批C·C-3】运行记录落盘（入队侧——上轮未完成归并/已完成覆盖）。
	 *
	 * @param int[] $added_ids 本次新增清单 id。
	 * @return void
	 */
	private function record_backlog_run( array $added_ids ): void {
		$record   = get_option( self::OPTION_BACKLOG_RECORD, array() );
		$record   = is_array( $record ) ? $record : array();
		$prev_ids = is_array( $record['ids'] ?? null ) ? array_map( 'intval', $record['ids'] ) : array();

		// 上轮已完成（清单全部落判定 meta——meta 反查口径）→新一轮覆盖；否则归并续跑。
		if ( array() !== $prev_ids && $this->record_run_finished( $prev_ids ) ) {
			$prev_ids = array();
			$record   = array();
		}

		$ids = array_values( array_unique( array_merge( $prev_ids, array_map( 'intval', $added_ids ) ) ) );
		update_option( self::OPTION_BACKLOG_RECORD, array(
			'started_at' => array() !== $prev_ids ? (int) ( $record['started_at'] ?? 0 ) : (int) ( $this->clock )(),
			'ids'        => $ids,
			'enqueued'   => count( $ids ),
		), false );
	}

	/**
	 * 【M6·批C·C-3】运行记录完成判定（清单全落判定 meta=完成；空清单视同完成）。
	 *
	 * @param int[] $ids 记录清单。
	 * @return bool
	 */
	private function record_run_finished( array $ids ): bool {
		if ( array() === $ids ) {
			return true;
		}
		$reader = $this->meta_reader ?? array( $this, 'default_meta_reader' );
		foreach ( $ids as $id ) {
			$meta = $reader( (int) $id );
			if ( ! is_array( $meta ) || ! isset( $meta['verdict'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 【M6·批C·C-3】报告视图模型（纯函数——三态 empty/running/completed+处置口径
	 * 三行统计+起止时间+残留；Tab⑦ 渲染消费，单测面）。
	 *
	 * 口径=处理口径（用户 2026-11-16 反馈原话：没问题/待审核/垃圾箱）：pass→没问题、
	 * review+inconclusive→待审核（未核实不下结论）、reject→垃圾箱（已按 C-2 处置，
	 * 可手动恢复）。完成时间=meta 反查 max(judged_at)；残留=清单内未落判定 meta 数
	 * （评论本体已删的 gone 计收口不计残留）。
	 * 【M6·批D·D-6】已核查计数改本轮口径：只数 judged_at >= started_at 的 meta——
	 * 全量重查语义下清单内旧 meta（上轮判定）不算本轮已核查，覆盖后落新 judged_at
	 * 才计数（「已核查 N/M」如实反映本轮进度——覆盖计数正确回归钉）。
	 * 【M6·批F-a·§4-add2】新增 reasons 原因归堆（本轮口径内 reject/review/inconclusive
	 * 按「原因文本+证据形态」归堆，pass 不归因；evidence=search 行由渲染面补人话标记
	 * 「网址打不开，依据搜索线索判定」）。
	 *
	 * @param array<string, mixed>    $record         运行记录（started_at/ids/enqueued）。
	 * @param array<int, array>       $metas          清单内已落判定 meta（id=>meta）。
	 * @param int                     $queue_residual 批量队列残留数（batch_queue_count）。
	 * @param int[]                   $gone_ids       清单内评论本体已删的 id（收口）。
	 * @return array{state: string, started_at: int, finished_at: int, enqueued: int, judged: int, remaining: int, queue_residual: int, caliber: array{pass: int, pending: int, spam: int}, gone: int, reasons: array<int, array{reason: string, n: int, evidence: string}>}|array{state: string}
	 */
	public static function backlog_report_view( array $record, array $metas, int $queue_residual, array $gone_ids = array() ): array {
		$ids = is_array( $record['ids'] ?? null ) ? array_map( 'intval', $record['ids'] ) : array();
		if ( array() === $ids ) {
			return array( 'state' => 'empty' );
		}
		$gone        = array_flip( array_map( 'intval', $gone_ids ) );
		$caliber     = array( 'pass' => 0, 'pending' => 0, 'spam' => 0 );
		$reasons     = array(); // 【M6·批F-a】原因归堆（reason+evidence 键——Tab⑦ 人话标记消费）。
		$judged      = 0;
		$finished_at = 0;
		$started_at  = (int) ( $record['started_at'] ?? 0 );
		foreach ( $ids as $id ) {
			if ( isset( $gone[ $id ] ) ) {
				continue; // 本体已删=收口（不计残留不进口径）。
			}
			$meta = $metas[ $id ] ?? null;
			if ( ! is_array( $meta ) || ! isset( $meta['verdict'] ) ) {
				continue; // 未落判定=残留。
			}
			// 【M6·批D·D-6】本轮口径（覆盖计数正确）：重查语义下清单内旧 meta（上轮
			// 判定 judged_at < started_at）不计入本轮已核查数——覆盖后落新 judged_at 才算。
			if ( (int) ( $meta['judged_at'] ?? 0 ) < $started_at ) {
				continue;
			}
			++$judged;
			$finished_at = max( $finished_at, (int) ( $meta['judged_at'] ?? 0 ) );
			$verdict     = (string) $meta['verdict'];
			if ( 'pass' === $verdict ) {
				++$caliber['pass'];
				continue; // pass 不进原因堆（与存量报告口径一致——非终态/判违规才归因）。
			}
			if ( 'reject' === $verdict ) {
				++$caliber['spam'];
			} elseif ( in_array( $verdict, array( 'review', 'inconclusive' ), true ) ) {
				++$caliber['pending'];
			}
			// 【M6·批F-a·§4-add2】原因归堆（本轮口径内）：键=原因文本+证据形态——
			// evidence=search 行渲染时补人话标记「网址打不开，依据搜索线索判定」。
			$reason = trim( (string) ( $meta['reason'] ?? '' ) );
			if ( '' === $reason ) {
				$reason = '（无原因说明）';
			}
			// 【M6·批H·H-8】归堆截断补「…」（单点件 InterceptDigest::truncate_reason——
			// 归堆先于渲染截断，聚合侧同规约全消费面生效）。
			$reason = \WinkGatekeeper\Stats\InterceptDigest::truncate_reason( $reason );
			$ev     = 'search' === (string) ( $meta['evidence'] ?? '' ) ? 'search' : '';
			/* 【M6·批H3·H3-C】归堆键扩处置去向维度（行尾「→ 垃圾箱/→ 待审核」）：reject→
			 * spam、review+inconclusive→hold（disposal_existing 矩阵一致）；同原因不同
			 * 去向拆行。 */
			$disposal = 'reject' === $verdict ? 'spam' : 'hold';
			$key    = $reason . '|' . $ev . '|' . $disposal;
			if ( isset( $reasons[ $key ] ) ) {
				++$reasons[ $key ]['n'];
			} else {
				$reasons[ $key ] = array( 'reason' => $reason, 'n' => 1, 'evidence' => $ev, 'disposal' => $disposal );
			}
		}
		uasort( $reasons, static fn( $a, $b ) => $b['n'] <=> $a['n'] );
		$remaining = count( $ids ) - $judged - count( $gone_ids );
		return array(
			'state'          => ( $remaining > 0 || $queue_residual > 0 ) ? 'running' : 'completed',
			'started_at'     => (int) ( $record['started_at'] ?? 0 ),
			'finished_at'    => $finished_at,
			'enqueued'       => count( $ids ),
			'judged'         => $judged,
			'remaining'      => max( 0, $remaining ),
			'queue_residual' => max( 0, $queue_residual ),
			'caliber'        => $caliber,
			'gone'           => count( $gone_ids ),
			'reasons'        => array_values( $reasons ),
		);
	}

	/**
	 * 【M6·批C·C-3】报告数据反查（WP 边界——Tab⑦ 渲染消费；meta 反查最小方案，
	 * tick 零写）：读运行记录 option→逐 id 读 url_audit meta→纯函数视图；未落判定
	 * 且评论本体已删的 id 计 gone（收口防「永不完成」）。
	 *
	 * @return array<string, mixed> backlog_report_view 返回形态。
	 */
	public function backlog_report_data(): array {
		$record = get_option( self::OPTION_BACKLOG_RECORD, array() );
		$record = is_array( $record ) ? $record : array();
		$ids    = is_array( $record['ids'] ?? null ) ? array_map( 'intval', $record['ids'] ) : array();
		$reader = $this->meta_reader ?? array( $this, 'default_meta_reader' );
		$metas  = array();
		$gone   = array();
		foreach ( $ids as $id ) {
			$meta = $reader( $id );
			if ( is_array( $meta ) && isset( $meta['verdict'] ) ) {
				$metas[ $id ] = $meta;
				continue;
			}
			if ( ! get_comment( $id ) ) {
				$gone[] = $id; // 本体已删=收口。
			}
		}
		return self::backlog_report_view( $record, $metas, $this->batch_queue_count(), $gone );
	}

	/**
	 * 回捞扫描宇宙（升序稳定序——显式动作一次性入队，闸与配额约束整个回捞量）。
	 *
	 * 【M6·批C·C-1】approved-only（用户 2026-11-16：不把已经判定为垃圾的翻出来鞭尸）：
	 * 垃圾与待审不进扫描宇宙。
	 * 【M6·批D·D-6】全量重查语义（用户 2026-11-16 拍板——§3b-add「用户点击开始存量
	 * 网址核查就全部查一遍」）：移除「无判定 meta」排除条件——宇宙=全部 approved+
	 * 有 URL 评论（网站内容可能已变化，重查覆盖旧判定）；成本护栏照旧=50/日配额+
	 * 暂停黄条（大站分多日跑完）。
	 *
	 * @return int[]
	 */
	public function backlog_universe_ids(): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			"SELECT c.comment_ID FROM {$wpdb->comments} c
			 WHERE c.comment_approved = '1'
			 AND c.comment_author_url <> ''
			 ORDER BY c.comment_ID ASC"
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
			// 【M6·批H·H-1】抓取未配置档行排除（零打开零判定——不得虚报「今日核查」）。
			if ( 'miss' === ( $fetch['cache'] ?? '' )
				&& 'unconfigured' !== (string) ( $fetch['fetch_provider'] ?? '' )
				&& gmdate( 'Y-m-d', $judged_at ) === $today_key ) {
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
	 * 【M6·批H·H-9】网址核查今日处置三数（Tab⑥「最近拦下的评论」区头部汇总行——
	 * 「网址核查今日处置 N 条（垃圾箱 X·待审 Y）→ 查看详情」）。口径=meta 反查（读路径
	 * 零新增存储零新事件零写放大——P1-4 事件表拦截口径保持纯净不稀释）：judged_at 今日
	 * 且 disposed_to∈{spam,hold}（抓取未配置档 disposed_to=hold 也计——「今日进待审」
	 * 是真实发生过的处置事实）。
	 *
	 * @return array{total: int, spam: int, hold: int}
	 */
	public function today_disposal_line(): array {
		global $wpdb;
		$meta_table = $wpdb->commentmeta;
		$today_key  = gmdate( 'Y-m-d' );
		$total      = 0;
		$spam       = 0;
		$hold       = 0;
		$rows       = $wpdb->get_results(
			$wpdb->prepare( "SELECT meta_value FROM {$meta_table} WHERE meta_key = %s", self::META_KEY ),
			ARRAY_A
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$meta = maybe_unserialize( (string) ( $row['meta_value'] ?? '' ) );
			if ( ! is_array( $meta ) || ! isset( $meta['verdict'] ) ) {
				continue;
			}
			$disposed_to = (string) ( $meta['disposed_to'] ?? 'none' );
			if ( ! in_array( $disposed_to, array( 'spam', 'hold' ), true ) ) {
				continue; // 只数「处置了」的（垃圾箱/待审两去向——none=零处置不进汇总）。
			}
			if ( gmdate( 'Y-m-d', (int) ( $meta['judged_at'] ?? 0 ) ) !== $today_key ) {
				continue; // 今日口径（judged_at 日历日——与 stats_line K8 同款）。
			}
			++$total;
			if ( 'spam' === $disposed_to ) {
				++$spam;
			} else {
				++$hold;
			}
		}
		return array( 'total' => $total, 'spam' => $spam, 'hold' => $hold );
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
	 * fetcher 工厂（【M6·批D·D-3】按 url_audit.fetcher.mode 分派——deepseek 档返回
	 * DeepSeekFetchAdapter（本站零直访陌生 URL，传输脊只对 api.deepseek.com 一条外呼）；
	 * 其余档返回现款 UrlFetcher（免费路径+15s/逐跳 SSRF 防线原样）。RemoteClient 传输脊；
	 * fetch 计量在 adapter 响应侧（成功次），usage_key 由 judge 传）。
	 * 【M6·批H·H-1】unconfigured 档消费面在 process_comment 先行短路（零 fetch）——
	 * 工厂本档永不投产（防御形态仍返回 UrlFetcher，与显式 self 同构）。
	 *
	 * @return UrlFetcher|DeepSeekFetchAdapter fetch($url,$robots_comply) 同构面。
	 */
	public function default_fetcher_factory(): object {
		if ( 'deepseek' === self::fetcher_mode( $this->settings ) ) {
			$cred = self::fetcher_credentials( $this->settings );
			return new DeepSeekFetchAdapter( $cred['api_key'], $cred['base_url'], $this->default_client() );
		}
		return new UrlFetcher( $this->default_client() );
	}

	/**
	 * 【M6·批D·D-3】抓取模式解析（纯函数，单测面）；【M6·批H·H-1】inherit 跟随语义翻转
	 * （§0-1 用户 2026-11-16 拍板——**本站直抓永不自动启用**）：
	 * self/deepseek 显式档原样（self 仅显式选择时生效）；inherit（默认）=跟随云端审核
	 * 驱动——review.cloud.chain 首位=deepseek → 抓取落 DeepSeek；**其他驱动（TMS/百度/
	 * green…）或云审关（含未配置）→ 'unconfigured'（抓取未配置）**：不自动自抓（旧
	 * 「本站直开兜底」退役——暴露源站 IP 不可作默认行为），judge 以 fetch:unconfigured
	 * →inconclusive→hold（批 E 语义，process_comment 消费）。
	 *
	 * @param array<string, mixed> $settings merge 后整树。
	 * @return string 解析后 ∈ self|deepseek|unconfigured（inherit 档返回解析结果）。
	 */
	public static function fetcher_mode( array $settings ): string {
		$mode = (string) ( $settings['url_audit']['fetcher']['mode'] ?? 'inherit' );
		if ( in_array( $mode, array( 'self', 'deepseek' ), true ) ) {
			return $mode;
		}
		$chain  = is_array( $settings['review']['cloud']['chain'] ?? null ) ? $settings['review']['cloud']['chain'] : array();
		$active = (string) ( $chain[0] ?? '' ); // UI 单选下拉即 chain[0] 可视面（04 §2）。
		return 'deepseek' === $active ? 'deepseek' : 'unconfigured';
	}

	/**
	 * 【M6·批D·D-1】抓取凭证链解析（纯函数，单测面——§0-5 key 链统一）：
	 * fetcher.api_key 非空=专用 Key 覆盖（仅 deepseek 档消费——本函数只在该档被调）；
	 * 空=跟随 AI 判定凭证链（provider custom→其 api_key/base_url；inherit→Tab③ 主凭证
	 * ——provider_config 统一解析源）。base_url 恒跟随凭证链（空串由 adapter 缺省官方）。
	 *
	 * @param array<string, mixed> $settings merge 后整树。
	 * @return array{api_key: string, base_url: string}
	 */
	public static function fetcher_credentials( array $settings ): array {
		$config   = self::provider_config( $settings );
		$own_key  = trim( (string) ( $settings['url_audit']['fetcher']['api_key'] ?? '' ) );
		return array(
			'api_key'  => '' !== $own_key ? $own_key : (string) ( $config['api_key'] ?? '' ),
			'base_url' => (string) ( $config['base_url'] ?? '' ),
		);
	}

	/**
	 * 判定工厂（DeepSeek via 协议族配置+URL 审核 prompt；搜索源按 search_source 分派）。
	 *
	 * 凭证来源走 provider_config（【M5 修复批·D3】url_audit.provider 子树——inherit
	 * 回落云审 DeepSeek / custom 独立凭证）。
	 *
	 * 【M6·批B·B-2】搜索源分派（worker 消费点唯一装配位）：url_audit.search_source=
	 * deepseek → DeepSeekSearch（key 链=§0-5 凭证链：provider custom→其 api_key/
	 * base_url；inherit→Tab③ 主凭证——provider_config 已统一解析）；bocha（默认）→
	 * 博查现款（K3 计量口径不动）。凭证链空=is_configured false=零信号降级不阻塞。
	 *
	 * @return array{judge: UrlJudgeEngine, model: string, bocha: UrlSearchProvider|null}
	 */
	public function default_judge_factory(): array {
		$config = self::provider_config( $this->settings );
		$client = $this->default_client();
		$source = (string) ( $this->settings['url_audit']['search_source'] ?? 'bocha' );
		$search = null;
		if ( 'deepseek' === $source ) {
			$search = new DeepSeekSearch( (string) ( $config['api_key'] ?? '' ), (string) ( $config['base_url'] ?? '' ), $client );
		} elseif ( ! empty( $this->settings['url_audit']['bocha']['enabled'] ) && '' !== (string) ( $this->settings['url_audit']['bocha']['api_key'] ?? '' ) ) {
			$search = new BochaSearch( (string) $this->settings['url_audit']['bocha']['api_key'], $client );
		}
		$model  = (string) ( $config['model'] ?? 'deepseek-flash' );
		$engine = new UrlJudgeEngine(
			$config,
			$client,
			$model,
			$search
		);
		return array( 'judge' => $engine, 'model' => $model, 'bocha' => $search );
	}

	/**
	 * 【M5 修复批·D3】网址核查判定凭证来源（纯函数，单测面——default_judge_factory
	 * 与本函数同一判定源）。
	 *
	 * - provider.mode=custom 且 api_key 非空 → 独立凭证构造 config：base_url 空回落
	 *   DeepSeek DEFAULT_BASE_URL、model 空回落 deepseek-flash、timeout 固定 8、
	 *   min_confidence 固定 0.8、two_stage 不继承（独立凭证=独立语境，两段式升级不
	 *   跟随云审配置）；其余键（daily_quota/fail_mode/system_prompt）沿用云审 deepseek
	 *   子树派生值（独立凭证只换鉴权与端点，不改配额/降级/提示词口径）。
	 * - 否则（inherit，或 custom 但 key 空的 fail-safe 回落）→ 现行 config_from_settings
	 *   回落路径（云审 review.cloud.deepseek 凭证）。
	 *
	 * @param array<string, mixed> $settings merge 后整树。
	 * @return array<string, mixed> 协议族配置（DeepSeekReviewDriver::config_from_settings 同形）。
	 */
	public static function provider_config( array $settings ): array {
		$provider = is_array( $settings['url_audit']['provider'] ?? null ) ? $settings['url_audit']['provider'] : array();
		$mode     = (string) ( $provider['mode'] ?? 'inherit' );
		$own_key  = trim( (string) ( $provider['api_key'] ?? '' ) );

		if ( 'custom' !== $mode || '' === $own_key ) {
			return \WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver::config_from_settings( $settings );
		}

		$config                 = \WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver::config_from_settings( $settings );
		$config['api_key']      = $own_key;
		$own_base               = trim( (string) ( $provider['base_url'] ?? '' ) );
		$config['base_url']     = '' !== $own_base ? $own_base : \WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver::DEFAULT_BASE_URL;
		$own_model              = trim( (string) ( $provider['model'] ?? '' ) );
		$config['model']        = '' !== $own_model ? $own_model : \WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver::DEFAULT_MODEL;
		$config['timeout']      = 8;
		$config['min_confidence'] = 0.8;
		$config['two_stage']    = false; // 独立凭证不继承两段式（升级模型不消费）。
		return $config;
	}

	/**
	 * @return RemoteClient
	 */
	private function default_client(): RemoteClient {
		return new RemoteClient( null, array( Usage::class, 'bump_option' ) );
	}
}
