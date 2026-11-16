<?php
/**
 * M6 批 D · 存量网址核查「全部重查一遍」语义（0.6.6，2026-11-16——施工图 §3b-add，
 * 用户 2026-11-16 拍板；M6-MANUAL-FEEDBACK-3 §0 原话「用户点击 开始存量网址核查 就全部查一遍」）。
 *
 * 覆盖：
 *   ① backlog_universe_ids 移除「无判定 meta」排除=全部 approved+有 URL（approved-only
 *      C-1 红线保持——垃圾/待审仍不翻出来鞭尸）；
 *   ② 重查绕过 UrlAuditCache（force refetch——pass 14d 缓存不适用于显式重查，
 *      「之前正常现在不正常」正是重查的意义）；
 *   ③ 已判定评论在批量车道不再幂等跳过——新判定覆盖旧 meta+disposed_to 按 §3b C-2
 *      矩阵重算；增量车道语义零改动（幂等跳过+缓存照旧）；
 *   ④ 报告口径=本轮（judged_at >= started_at——旧 meta 不计入本轮已核查数，覆盖后
 *      落新 judged_at 才计数——「覆盖计数正确」回归钉）；
 *   ⑤ UI：Tab⑦ 按钮与 confirm 文案改「全部重查一遍——忽略历史结果，网站内容可能已变化」；
 *   ⑥ 成本护栏照旧：batch_quota 50/日配额闸在重查语义下仍生效；
 *   ⑦ 回归钉：第二次重查仍真实外呼（缓存绕过——以 fetch 计量 delta 证）。
 *
 * 桩注记：WinkGatekeeper\UrlAudit / WinkGatekeeper\Stats 命名空间 WP 边界桩与
 * M6DFetchProviderTest 同款同键（function_exists 守卫——同进程共存）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\UrlAudit {

	$GLOBALS['wk_ua_option_store']    = $GLOBALS['wk_ua_option_store'] ?? array();
	$GLOBALS['wk_ua_transient_store'] = $GLOBALS['wk_ua_transient_store'] ?? array();

	if ( ! function_exists( 'WinkGatekeeper\UrlAudit\get_option' ) ) {
		/**
		 * get_option 桩。
		 *
		 * @param string $name    option 名。
		 * @param mixed  $default 缺省值。
		 * @return mixed
		 */
		function get_option( string $name, $default = false ) {
			return array_key_exists( $name, $GLOBALS['wk_ua_option_store'] )
				? $GLOBALS['wk_ua_option_store'][ $name ]
				: $default;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\UrlAudit\update_option' ) ) {
		/**
		 * update_option 桩。
		 *
		 * @param string $name     option 名。
		 * @param mixed  $value    值。
		 * @param mixed  $autoload 自动加载（忽略）。
		 * @return bool
		 */
		function update_option( string $name, $value, $autoload = null ): bool {
			$GLOBALS['wk_ua_option_store'][ $name ] = $value;
			return true;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\UrlAudit\get_transient' ) ) {
		/**
		 * get_transient 桩。
		 *
		 * @param string $key 键。
		 * @return mixed
		 */
		function get_transient( string $key ) {
			return $GLOBALS['wk_ua_transient_store'][ $key ] ?? null;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\UrlAudit\set_transient' ) ) {
		/**
		 * set_transient 桩。
		 *
		 * @param string $key   键。
		 * @param mixed  $value 值。
		 * @param int    $ttl   TTL（忽略）。
		 * @return bool
		 */
		function set_transient( string $key, $value, int $ttl = 0 ): bool {
			$GLOBALS['wk_ua_transient_store'][ $key ] = $value;
			return true;
		}
	}
}

namespace WinkGatekeeper\Stats {

	$GLOBALS['wk_stats_option_store'] = $GLOBALS['wk_stats_option_store'] ?? array();

	if ( ! function_exists( 'WinkGatekeeper\Stats\get_option' ) ) {
		/**
		 * get_option 桩。
		 *
		 * @param string $name    option 名。
		 * @param mixed  $default 缺省值。
		 * @return mixed
		 */
		function get_option( string $name, $default = false ) {
			return array_key_exists( $name, $GLOBALS['wk_stats_option_store'] )
				? $GLOBALS['wk_stats_option_store'][ $name ]
				: $default;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\update_option' ) ) {
		/**
		 * update_option 桩。
		 *
		 * @param string $name    option 名。
		 * @param mixed  $value   值。
		 * @param mixed  $autoload 自动加载（忽略）。
		 * @return bool
		 */
		function update_option( string $name, $value, $autoload = null ): bool {
			$GLOBALS['wk_stats_option_store'][ $name ] = $value;
			return true;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\current_time' ) ) {
		/**
		 * current_time 桩（固定日——计量窗口判定面）。
		 *
		 * @param string $type 类型。
		 * @return string
		 */
		function current_time( string $type ): string {
			if ( 'Ymd' === $type ) {
				return '20261116';
			}
			if ( 'Y-m-d' === $type ) {
				return '2026-11-16';
			}
			return '';
		}
	}
}

namespace WinkGatekeeper\Tests {

	use PHPUnit\Framework\TestCase;
	use WinkGatekeeper\Http\RemoteClient;
	use WinkGatekeeper\Settings\SettingsSchema;
	use WinkGatekeeper\Stats\Usage;
	use WinkGatekeeper\UrlAudit\UrlAuditCache;
	use WinkGatekeeper\UrlAudit\UrlAuditWorker;
	use WinkGatekeeper\UrlAudit\UrlJudgeEngine;
	use WinkGatekeeper\UrlAudit\UrlVerdicts;

	final class M6DRecheckSemanticsTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['wk_ua_option_store']    = array();
			$GLOBALS['wk_ua_transient_store'] = array();
			$GLOBALS['wk_stats_option_store'] = array();
		}

		private static function source( string $rel ): string {
			$src = file_get_contents( dirname( __DIR__ ) . '/' . $rel );
			self::assertIsString( $src, "{$rel} 必须可读" );
			return (string) $src;
		}

		private static function blockBetween( string $src, string $startMarker, string $endMarker ): string {
			$start = strpos( $src, $startMarker );
			self::assertNotFalse( $start, "HTML 须含 {$startMarker}" );
			$end = strpos( $src, $endMarker, $start + strlen( $startMarker ) );
			self::assertNotFalse( $end, "须在 {$startMarker} 之后含 {$endMarker}" );
			return substr( $src, $start, $end - $start );
		}

		/**
		 * 构造 worker（默认注入：处置捕获+固定时钟；fetcher/judge 由调用方覆盖）。
		 *
		 * @param array<string, callable> $overrides 钩子覆盖。
		 * @param array<string, mixed>    $ua       url_audit 子树覆盖。
		 * @param array<string, mixed>    $refs     引用回传（disposals）。
		 * @return UrlAuditWorker
		 */
		private function makeWorker( array $overrides = array(), array $ua = array(), array &$refs = array() ): UrlAuditWorker {
			$settings                         = SettingsSchema::defaults();
			$settings['url_audit']['enabled'] = true;
			// 【M6·批H·H-1】抓取管线测试夹具显式 self 档（inherit 现解析 unconfigured 不自动自抓——本批语义翻转，管线行为测试须显式选择抓取档）。
			$settings['url_audit']['fetcher']['mode'] = 'self';
			foreach ( $ua as $key => $value ) {
				$settings['url_audit'][ $key ] = $value;
			}
			if ( ! isset( $refs['disposals'] ) ) {
				$refs['disposals'] = array();
			}
			$disposals =& $refs['disposals'];
			$hooks     = array(
				'disposal_executor' => static function ( int $id, string $action ) use ( &$disposals ): bool {
					$disposals[] = array( $id, $action );
					return true;
				},
				'clock' => static fn(): float => 1700000000.0,
			);
			return new UrlAuditWorker( $settings, array_merge( $hooks, $overrides ) );
		}

		/**
		 * 判定引擎桩（固定响应队列——耗尽后重复最后一条）。
		 *
		 * @param string $verdict    verdict 键。
		 * @param float  $confidence 置信度。
		 * @return UrlJudgeEngine
		 */
		private static function judgeEngine( string $verdict, float $confidence ): UrlJudgeEngine {
			$responses = array(
				array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => (string) json_encode( array(
						'choices' => array( array(
							'message'       => array( 'role' => 'assistant', 'content' => (string) json_encode( array( 'verdict' => $verdict, 'confidence' => $confidence, 'reason' => '重查测试原因', 'hits' => array() ) ) ),
							'finish_reason' => 'stop',
						) ),
						'usage'   => array( 'prompt_tokens' => 10, 'completion_tokens' => 5 ),
					) ),
				),
			);
			$last = null;
			return new UrlJudgeEngine(
				array( 'api_key' => 'sk-test', 'base_url' => 'https://api.deepseek.com', 'model' => 'deepseek-flash', 'timeout' => 8, 'min_confidence' => 0.8 ),
				new RemoteClient(
					static function ( string $url, array $args ) use ( &$responses, &$last ) {
						$resp = array_shift( $responses );
						if ( null !== $resp ) {
							$last = $resp;
						} else {
							$resp = $last;
						}
						return $resp;
					},
					null,
					static function ( int $ms ): void {}
				),
				'deepseek-flash',
				null
			);
		}

		/**
		 * fetch 桩（可计数+结果可指定）。
		 *
		 * @param string $fetch_class fetch 类。
		 * @param int    $counter_key 计数引用键（$GLOBALS['wk_d_fetch_calls']）。
		 * @return object
		 */
		private static function fetchStub( string $fetch_class = 'ok', string $counter_key = 'wk_d_fetch_calls' ): object {
			return new class( $fetch_class, $counter_key ) {
				private $fetch_class;
				private $counter_key;

				public function __construct( string $fetch_class, string $counter_key ) {
					$this->fetch_class = $fetch_class;
					$this->counter_key = $counter_key;
				}

				/**
				 * @return array<string, mixed>
				 */
				public function fetch( string $url, bool $robots_comply = false ): array {
					$GLOBALS[ $this->counter_key ] = ( $GLOBALS[ $this->counter_key ] ?? 0 ) + 1;
					$ok                            = 'ok' === $this->fetch_class;
					return array(
						'fetch_class' => $this->fetch_class, 'final_url' => $url, 'hop_hosts' => array(),
						'status'      => $ok ? 200 : 404, 'content_type' => $ok ? 'text/html' : '',
						'title'       => $ok ? 'T' : '', 'og_title' => '', 'meta_description' => '',
						'meta_refresh' => '', 'js_redirect' => '', 'text' => $ok ? '足够长的正文内容喂判定引擎。' : '',
						'robots_state' => '', 'redirects' => 0,
					);
				}
			};
		}

		private static function readerWithOldMeta( int $id, string $verdict, int $judged_at ): ?array {
			return $id === 777 ? array(
				'verdict'    => $verdict,
				'confidence' => 0.9,
				'reason'     => '旧判定',
				'hits'       => array(),
				'lane'       => 'batch',
				'fetch'      => array( 'cache' => 'miss' ),
				'judged_at'  => $judged_at,
				'disposed_to' => 'none',
			) : null;
		}

		/* ===============================================================
		 * ① 宇宙：移除「无判定 meta」排除（approved+URL 全量）
		 * =============================================================== */

		public function test_universe_drops_meta_exclusion(): void {
			$src = self::source( 'includes/urlaudit/UrlAuditWorker.php' );
			$body = self::blockBetween( $src, 'public function backlog_universe_ids', '/**' );
			self::assertStringNotContainsString( 'meta_id IS NULL', $body, '§3b-add：宇宙不再排除「已判定」（全部重查一遍）' );
			self::assertStringNotContainsString( 'LEFT JOIN', $body, 'meta 反连接退场（无排除面）' );
		}

		public function test_universe_keeps_approved_and_url_filters(): void {
			// C-1 红线保持：垃圾/待审不翻出来鞭尸（approved-only）；有 URL 才入队。
			$src = self::source( 'includes/urlaudit/UrlAuditWorker.php' );
			$body = self::blockBetween( $src, 'public function backlog_universe_ids', '/**' );
			self::assertStringContainsString( "c.comment_approved = '1'", $body, 'approved-only 红线保持' );
			self::assertStringContainsString( "c.comment_author_url <> ''", $body, '有 URL 才入队' );
		}

		/* ===============================================================
		 * ②③ 批量车道=重查：已判定重处理+缓存绕过+覆盖 meta+矩阵重算
		 * =============================================================== */

		public function test_batch_lane_reprocesses_marked_comment_and_overwrites(): void {
			// 已判定（旧 meta pass）的评论在批量车道重查：新判定 reject 0.97 → 覆盖 meta +
			// disposed_to 按矩阵重算（reject→spam，可逆终态面执行）。
			$metas = array();
			$refs  = array();
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BATCH_QUEUE ] = array( 777 );
			$worker = $this->makeWorker( array(
				'incr_take'       => static fn( int $limit ): array => array(),
				'comment_reader'  => static fn( int $id ): array => array( 'author' => '甲', 'body' => '带网址评论', 'url' => 'https://recheck.example.com/', 'post_status' => 'publish' ),
				'meta_reader'     => static fn( int $id ): ?array => self::readerWithOldMeta( $id, 'pass', 1699000000 ),
				'meta_writer'     => static function ( int $id, ?array $meta ) use ( &$metas ): void {
					$metas[ $id ] = $meta;
				},
				'fetcher_factory' => static fn(): object => self::fetchStub( 'ok' ),
				'judge_factory'   => static fn(): array => array( 'judge' => self::judgeEngine( 'reject', 0.97 ), 'model' => 'deepseek-flash', 'bocha' => null ),
			), array(), $refs );

			$out = $worker->run_round();

			self::assertSame( 1, $out['batch_processed'], '已判定评论在重查语义下照常处理（不再幂等跳过）' );
			self::assertSame( 'reject', $metas[777]['verdict'] ?? '', '新判定覆盖旧 meta（pass → reject）' );
			self::assertSame( 1700000000, $metas[777]['judged_at'] ?? 0, 'judged_at 更新为本轮（覆盖）' );
			self::assertSame( 'spam', $metas[777]['disposed_to'] ?? '', 'disposed_to 按 C-2 矩阵重算（reject→垃圾箱）' );
			self::assertSame( array( array( 777, 'spam' ) ), $refs['disposals'], '重查处置真实执行（wp_set_comment_status spam 可逆）' );
		}

		public function test_batch_lane_bypasses_cache(): void {
			// 缓存播种 reject（pass 14d/reject 90d 窗内）——重查仍真实 fetch+判定走本轮
			// （「之前正常现在不正常」正是重查的意义——force refetch）。
			$GLOBALS['wk_d_fetch_calls'] = 0;
			UrlAuditCache::put( 'https://cached.example.com/', array(
				'verdict'    => 'reject',
				'confidence' => 0.99,
				'reason'     => '旧缓存判定',
				'hits'       => array(),
			) );
			$metas = array();
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BATCH_QUEUE ] = array( 778 );
			$worker = $this->makeWorker( array(
				'incr_take'       => static fn( int $limit ): array => array(),
				'comment_reader'  => static fn( int $id ): array => array( 'author' => '乙', 'body' => '带网址评论', 'url' => 'https://cached.example.com/', 'post_status' => 'publish' ),
				'meta_reader'     => static fn( int $id ): ?array => null,
				'meta_writer'     => static function ( int $id, ?array $meta ) use ( &$metas ): void {
					$metas[ $id ] = $meta;
				},
				'fetcher_factory' => static fn(): object => self::fetchStub( 'ok' ),
				'judge_factory'   => static fn(): array => array( 'judge' => self::judgeEngine( 'pass', 0.9 ), 'model' => 'deepseek-flash', 'bocha' => null ),
			) );

			$out = $worker->run_round();

			self::assertSame( 1, $GLOBALS['wk_d_fetch_calls'], '缓存命中不短路——重查强制真实外呼' );
			self::assertSame( 'pass', $metas[778]['verdict'] ?? '', '判定取本轮 fetch+judge 结果（非缓存 reject）' );
			self::assertSame( 'miss', $metas[778]['fetch']['cache'] ?? '', 'meta cache=miss（真实外呼溯源）' );
			self::assertSame( 1, $out['batch_processed'] );
		}

		public function test_second_recheck_still_fetches_via_metering_delta(): void {
			// 回归钉：第二次重查仍真实外呼——第一轮正常写缓存，第二轮绕过缓存再 fetch，
			// 以 url_audit_fetch 计量 delta 证（真 adapter 在环——成功次口径 200 且有正文）。
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BATCH_QUEUE ] = array( 779 );
			$fetch_transport = static function ( string $url, array $args ) {
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => (string) json_encode( array( 'title' => '示例站', 'content' => '# 正文

足够长的重查测试正文内容。' ), JSON_UNESCAPED_UNICODE ),
				);
			};
			$hooks = array(
				'incr_take'       => static fn( int $limit ): array => array(),
				'comment_reader'  => static fn( int $id ): array => array( 'author' => '丙', 'body' => '带网址评论', 'url' => 'https://twice.example.com/', 'post_status' => 'publish' ),
				'meta_reader'     => static fn( int $id ): ?array => null,
				'meta_writer'     => static function ( int $id, ?array $meta ): void {},
				'disposal_executor' => static function ( int $id, string $action ): bool {
					return true;
				},
				'fetcher_factory' => static fn(): object => new \WinkGatekeeper\UrlAudit\DeepSeekFetchAdapter( 'sk-test', '', new RemoteClient( $fetch_transport, null, static function ( int $ms ): void {} ) ),
				'judge_factory'   => static fn(): array => array( 'judge' => self::judgeEngine( 'pass', 0.9 ), 'model' => 'deepseek-flash', 'bocha' => null ),
			);
			Usage::bump_option( 'preexisting_key' );
			$before = Usage::daily_total_for( Usage::get(), Usage::KEY_URL_AUDIT_FETCH );

			$this->makeWorker( $hooks )->run_round();
			$after_first = Usage::daily_total_for( Usage::get(), Usage::KEY_URL_AUDIT_FETCH );
			self::assertSame( $before + 1, $after_first, '第一轮真实外呼 → 计量 +1' );

			// 第二轮：同一 URL（此刻缓存已在第一轮写入）→ 仍真实外呼。
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BATCH_QUEUE ] = array( 779 );
			$this->makeWorker( $hooks )->run_round();

			self::assertSame( $before + 2, Usage::daily_total_for( Usage::get(), Usage::KEY_URL_AUDIT_FETCH ), '计量 delta +2=两次真实外呼（缓存绕过——fetch 计量口径归真）' );
		}

		public function test_incr_lane_semantics_untouched(): void {
			// 增量车道零改动（重查语义只属批量车道）：已判定跳过+缓存命中短路照旧。
			$GLOBALS['wk_d_fetch_calls'] = 0;
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_INCR_QUEUE ] = array( 777 );
			$taken = 0;
			$worker = $this->makeWorker( array(
				'incr_take'       => static function ( int $limit ) use ( &$taken ): array {
					++$taken;
					return $taken > 1 ? array() : array( 777 ); // 真实队列弹出资态（取一次后空）。
				},
				'comment_reader'  => static fn( int $id ): array => array( 'author' => '丁', 'body' => '带网址评论', 'url' => 'https://incr.example.com/', 'post_status' => 'publish' ),
				'meta_reader'     => static fn( int $id ): ?array => self::readerWithOldMeta( $id, 'pass', 1699000000 ),
				'meta_writer'     => static function ( int $id, ?array $meta ): void {},
				'fetcher_factory' => static fn(): object => self::fetchStub( 'ok' ),
			) );
			$out = $worker->run_round();
			self::assertSame( 0, $GLOBALS['wk_d_fetch_calls'], '增量车道已判定评论幂等跳过——零外呼（语义不变）' );

			// 缓存命中路径照旧（增量车道无 meta 时）。
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_INCR_QUEUE ] = array( 888 );
			UrlAuditCache::put( 'https://incrhit.example.com/', array(
				'verdict'    => 'reject',
				'confidence' => 0.99,
				'reason'     => '缓存判定',
				'hits'       => array(),
			) );
			$metas = array();
			$taken2 = 0;
			$worker2 = $this->makeWorker( array(
				'incr_take'       => static function ( int $limit ) use ( &$taken2 ): array {
					++$taken2;
					return $taken2 > 1 ? array() : array( 888 );
				},
				'comment_reader'  => static fn( int $id ): array => array( 'author' => '戊', 'body' => '带网址评论', 'url' => 'https://incrhit.example.com/', 'post_status' => 'publish' ),
				'meta_reader'     => static fn( int $id ): ?array => null,
				'meta_writer'     => static function ( int $id, ?array $meta ) use ( &$metas ): void {
					$metas[ $id ] = $meta;
				},
				'fetcher_factory' => static fn(): object => self::fetchStub( 'ok' ),
			) );
			$out2 = $worker2->run_round();
			self::assertSame( 1, $out2['incr_processed'], '缓存命中正常消费' );
			self::assertSame( 0, $GLOBALS['wk_d_fetch_calls'], '增量车道缓存命中零外呼（同 URL 只烧一次）' );
			self::assertSame( 'hit', $metas[888]['fetch']['cache'] ?? '', 'meta cache=hit 溯源' );
			self::assertSame( 'reject', $metas[888]['verdict'] ?? '', '缓存 verdict 照常落 meta' );
		}

		/* ===============================================================
		 * ⑥ 成本护栏：batch_quota 50/日配额闸在重查语义下仍生效
		 * =============================================================== */

		public function test_batch_quota_gate_still_applies_on_recheck(): void {
			// 全量重查宇宙可能数百条——50/日配额护栏照旧（大站分多日跑完）。
			$GLOBALS['wk_d_fetch_calls'] = 0;
			Usage::bump_option( Usage::KEY_URL_AUDIT_BATCH );
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BATCH_QUEUE ] = array( 777, 778, 779 );
			$worker = $this->makeWorker( array(
				'incr_take'       => static fn( int $limit ): array => array(),
				'comment_reader'  => static fn( int $id ): array => array( 'author' => '己', 'body' => '带网址评论', 'url' => 'https://quota.example.com/', 'post_status' => 'publish' ),
				'meta_reader'     => static fn( int $id ): ?array => self::readerWithOldMeta( $id, 'pass', 1699000000 ),
				'meta_writer'     => static function ( int $id, ?array $meta ): void {},
				'fetcher_factory' => static fn(): object => self::fetchStub( 'ok' ),
				'event_recorder'  => static function ( string $reason, array $hits, array $meta ): void {}, // 暂停边沿 sink（缺省通道依赖 WP 事件表——单测注入）。
			), array( 'batch_quota' => 1 ) );
			$out = $worker->run_round();

			self::assertSame( 'batch_quota_exhausted', $out['stopped'], '批量配额闸在重查语义下仍生效' );
			self::assertSame( 0, $out['batch_processed'], '配额尽零处理（大站分多日跑完）' );
			self::assertSame( 0, $GLOBALS['wk_d_fetch_calls'], '配额闸在出队后即拦——零真实外呼' );
		}

		/* ===============================================================
		 * ④ 报告口径=本轮（覆盖计数正确）
		 * =============================================================== */

		public function test_report_counts_this_run_judgements_only(): void {
			// 重查语义下记录清单内旧 meta 不计入本轮已核查数（judged_at < started_at）；
			// 覆盖后（judged_at >= started_at）才计入——「已核查 N/M」如实反映本轮进度。
			$record = array( 'started_at' => 1700000000, 'ids' => array( 1, 2 ), 'enqueued' => 2 );
			$stale  = array(
				1 => array( 'verdict' => 'pass', 'judged_at' => 1699000000 ), // 上一轮旧 meta。
			);
			$view = UrlAuditWorker::backlog_report_view( $record, $stale, 2 );
			self::assertSame( 'running', $view['state'], '旧 meta 不算本轮已核查 → 运行中' );
			self::assertSame( 0, $view['judged'], '本轮已核查 0（覆盖前）' );
			self::assertSame( 2, $view['remaining'], '残留 2（两条都要重查）' );

			$fresh = array(
				1 => array( 'verdict' => 'pass', 'judged_at' => 1700000500 ), // 本轮覆盖后。
				2 => array( 'verdict' => 'reject', 'judged_at' => 1700000600 ),
			);
			$view2 = UrlAuditWorker::backlog_report_view( $record, $fresh, 0 );
			self::assertSame( 'completed', $view2['state'] );
			self::assertSame( 2, $view2['judged'], '本轮覆盖后计数归真' );
			self::assertSame( 1, $view2['caliber']['pass'] );
			self::assertSame( 1, $view2['caliber']['spam'] );
		}

		/* ===============================================================
		 * ⑤ UI：Tab⑦ 按钮与 confirm 文案（全部重查一遍语义）
		 * =============================================================== */

		public function test_tab7_button_and_confirm_full_recheck_copy(): void {
			$sp  = self::source( 'includes/settings/SettingsPage.php' );
			$card = self::blockBetween( $sp, 'id="wk-tools-url-audit-card"', 'id="wk-tools-url-audit-report"' );
			self::assertStringContainsString( '全部重查一遍——忽略历史结果，网站内容可能已变化', $card, '按钮文案改「全部重查一遍」语义（§3b-add 逐字）' );
			self::assertStringContainsString( '忽略历史结果', $card, 'confirm 同步：忽略历史结果' );
			self::assertStringContainsString( '网站内容可能已变化', $card, 'confirm 同步：网站内容可能已变化' );
		}

		public function test_tab7_state_line_drops_unchecked_wording(): void {
			// 反钉：旧文案「只入队带网址且未核查过的评论」与全量重查语义矛盾——退场。
			$sp   = self::source( 'includes/settings/SettingsPage.php' );
			$card = self::blockBetween( $sp, 'id="wk-tools-url-audit-card"', 'id="wk-tools-url-audit-report"' );
			self::assertStringNotContainsString( '未核查过', $card, '旧「未核查过」措辞退场（重查=忽略历史结果）' );
		}

		public function test_disposal_matrix_unchanged_for_recheck(): void {
			// 重查覆盖用同一 C-2 矩阵（§3b-add「disposed_to 按 §3b C-2 矩阵重算」）。
			self::assertSame( 'spam', UrlVerdicts::disposal_existing( 'reject', 0.97 )['action'] );
			self::assertSame( 'hold', UrlVerdicts::disposal_existing( 'inconclusive', 0.0 )['action'] );
			self::assertSame( 'hold', UrlVerdicts::disposal_existing( 'review', 0.5 )['action'] );
			self::assertSame( 'none', UrlVerdicts::disposal_existing( 'pass', 0.95 )['action'] );
		}
	}
}
