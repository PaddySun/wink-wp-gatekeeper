<?php
/**
 * M6 批 C · 存量网址核查处置与运行记录（0.6.5，2026-11-16——施工图 §3b C-1/C-2/C-3）。
 *
 * 覆盖：
 *   C-1 backlog_universe_ids approved-only（垃圾/待审不翻出来重查）；
 *   C-2 存量车道处置语义（用户 2026-11-16 拍板——「仅标记」退役）：reject→spam /
 *     review·inconclusive→hold / pass→不动；meta disposed_to（spam|hold|none）；
 *   C-3 backlog 运行记录 option（入队时建 record：started_at+ids+counts；完成后
 *     meta 反查统计——最小方案）+报告视图三态纯函数。
 *
 * 桩注记：WinkGatekeeper\UrlAudit 命名空间 WP 边界桩与 M6AUrlAuditGateTest 同款同键
 * （function_exists 守卫——文件加载序无关，任一先到即定义）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\UrlAudit {

	$GLOBALS['wk_ua_option_store']    = array();
	$GLOBALS['wk_ua_transient_store'] = array();

	if ( ! function_exists( 'WinkGatekeeper\UrlAudit\get_option' ) ) {
		function get_option( string $name, $default = false ) {
			return array_key_exists( $name, $GLOBALS['wk_ua_option_store'] )
				? $GLOBALS['wk_ua_option_store'][ $name ]
				: $default;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\UrlAudit\update_option' ) ) {
		function update_option( string $name, $value, $autoload = null ): bool {
			$GLOBALS['wk_ua_option_store'][ $name ] = $value;
			return true;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\UrlAudit\get_transient' ) ) {
		function get_transient( string $key ) {
			return $GLOBALS['wk_ua_transient_store'][ $key ] ?? null;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\UrlAudit\set_transient' ) ) {
		function set_transient( string $key, $value, int $ttl = 0 ): bool {
			$GLOBALS['wk_ua_transient_store'][ $key ] = $value;
			return true;
		}
	}
}

namespace WinkGatekeeper\Tests {

	use PHPUnit\Framework\TestCase;
	use WinkGatekeeper\Http\RemoteClient;
	use WinkGatekeeper\Settings\SettingsSchema;
	use WinkGatekeeper\UrlAudit\UrlAuditWorker;
	use WinkGatekeeper\UrlAudit\UrlFetcher;
	use WinkGatekeeper\UrlAudit\UrlJudgeEngine;
	use WinkGatekeeper\UrlAudit\UrlVerdicts;

	final class M6CUrlBacklogTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['wk_ua_option_store']    = array();
			$GLOBALS['wk_ua_transient_store'] = array();
		}

		/**
		 * 构造 worker（队列/处置/时钟全可注入；默认注入处置捕获）。
		 *
		 * @param array<string, callable> $overrides 钩子覆盖。
		 * @param array<string, mixed>    $ua       url_audit 子树覆盖。
		 * @param array<int, array>       $refs     引用回传（disposals）。
		 * @return UrlAuditWorker
		 */
		private function makeWorker( array $overrides = array(), array $ua = array(), array &$refs = array() ): UrlAuditWorker {
			$settings                         = SettingsSchema::defaults();
			$settings['url_audit']['enabled'] = true;
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
		 * chat/completions 响应体（判定 JSON 可换 verdict/confidence）。
		 *
		 * @param string $verdict    verdict 键。
		 * @param float  $confidence 置信度。
		 * @return array<string, mixed>
		 */
		private static function judgeResponse( string $verdict, float $confidence ): array {
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => (string) json_encode( array(
					'choices' => array( array(
						'message'       => array( 'role' => 'assistant', 'content' => (string) json_encode( array( 'verdict' => $verdict, 'confidence' => $confidence, 'reason' => '测试原因', 'hits' => array( 'benign' ) ) ) ),
						'finish_reason' => 'stop',
					) ),
					'usage'   => array( 'prompt_tokens' => 200, 'completion_tokens' => 30 ),
				) ),
			);
		}

		/* ===============================================================
		 * C-1 backlog 宇宙 approved-only
		 * =============================================================== */

		public function test_backlog_universe_is_approved_only(): void {
			$src = file_get_contents( dirname( __DIR__ ) . '/includes/urlaudit/UrlAuditWorker.php' );
			self::assertIsString( $src );
			self::assertStringNotContainsString( "comment_approved IN ('0','1')", (string) $src, 'C-1：存量网址核查宇宙不含待审（不翻垃圾鞭尸）' );
		}

		/* ===============================================================
		 * C-2 存量车道处置语义（「仅标记」退役）
		 * =============================================================== */

		public function test_disposal_existing_new_matrix(): void {
			// 翻转注记（原钉=M5U5UrlAuditTest「存量仅标记 nw §5.3」；翻钉原因=用户
			// 2026-11-16 拍板——reject 进垃圾箱、没看清进待审，均可手动恢复）。
			$reject = UrlVerdicts::disposal_existing( 'reject', 0.99 );
			self::assertSame( 'spam', $reject['action'], '存量 reject→垃圾箱（置信门已含于 U4 判定管线）' );

			$review = UrlVerdicts::disposal_existing( 'review', 0.5 );
			self::assertSame( 'hold', $review['action'], '存量 review→待审' );

			$inconclusive = UrlVerdicts::disposal_existing( 'inconclusive', 0.0 );
			self::assertSame( 'hold', $inconclusive['action'], '存量 inconclusive（没看清）→待审' );

			$pass = UrlVerdicts::disposal_existing( 'pass', 0.95 );
			self::assertSame( 'none', $pass['action'], 'pass→不动' );
		}

		public function test_batch_lane_fetch_fail_disposes_hold(): void {
			// fetch 失败→inconclusive→hold（可逆终态——批 A 时代此路径零处置）。
			$metas    = array();
			$refs     = array();
			$fail_client = new RemoteClient(
				static function (): array {
					return array( 'response' => array( 'code' => 500 ), 'headers' => array(), 'body' => 'boom' );
				},
				null,
				static function ( int $ms ): void {}
			);
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BATCH_QUEUE ] = array( 301 );
			$worker = $this->makeWorker( array(
				'incr_take'       => static fn( int $limit ): array => array(),
				'comment_reader'  => static function ( int $id ): array {
					return array( 'author' => '甲', 'body' => '带网址评论', 'url' => 'https://queued.example.com/', 'post_status' => 'publish' );
				},
				'meta_reader'     => static fn( int $id ): ?array => null,
				'meta_writer'     => static function ( int $id, ?array $meta ) use ( &$metas ): void {
					$metas[ $id ] = $meta;
				},
				'fetcher_factory' => static function () use ( $fail_client ): UrlFetcher {
					return new UrlFetcher( $fail_client );
				},
			), array(), $refs );

			$out = $worker->run_round();

			self::assertSame( 1, $out['batch_processed'] );
			self::assertSame( array( array( 301, 'hold' ) ), $refs['disposals'], 'inconclusive（没看清）→wp_set_comment_status hold（待审）' );
			self::assertSame( 'hold', $metas[301]['disposed_to'] ?? '', 'meta disposed_to=hold' );
		}

		public function test_batch_lane_reject_disposes_spam(): void {
			// 判定 reject（conf≥min_confidence——U4 置信门已含）→spam（垃圾箱）。
			$metas = array();
			$refs  = array();
			$responses = array( self::judgeResponse( 'reject', 0.97 ) );
			$last      = null;
			$engine = new UrlJudgeEngine(
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
			// fetch ok 形态桩（fetch 成功→进判定引擎——与 M6A fetch-fail 路径互补）。
			$ok_fetcher = new class() {
				public function fetch( string $url, bool $robots_comply = false ): array {
					return array(
						'fetch_class'      => 'ok',
						'final_url'        => $url,
						'hop_hosts'        => array(),
						'redirects'        => 0,
						'status'           => 200,
						'title'            => '示例站',
						'meta_description' => '描述',
						'text'             => '正文内容',
					);
				}
			};
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BATCH_QUEUE ] = array( 401 );
			$worker = $this->makeWorker( array(
				'incr_take'       => static fn( int $limit ): array => array(),
				'comment_reader'  => static function ( int $id ): array {
					return array( 'author' => '乙', 'body' => '带网址评论', 'url' => 'https://reject.example.com/', 'post_status' => 'publish' );
				},
				'meta_reader'     => static fn( int $id ): ?array => null,
				'meta_writer'     => static function ( int $id, ?array $meta ) use ( &$metas ): void {
					$metas[ $id ] = $meta;
				},
				'fetcher_factory' => static fn(): object => $ok_fetcher,
				'judge_factory'   => static fn(): array => array( 'judge' => $engine, 'model' => 'deepseek-flash', 'bocha' => null ),
			), array(), $refs );

			$out = $worker->run_round();

			self::assertSame( 1, $out['batch_processed'] );
			self::assertSame( 'reject', $metas[401]['verdict'] ?? '' );
			self::assertSame( array( array( 401, 'spam' ) ), $refs['disposals'], '存量 reject→垃圾箱' );
			self::assertSame( 'spam', $metas[401]['disposed_to'] ?? '' );
		}

		public function test_incr_lane_matrix_untouched(): void {
			// 增量车道处置矩阵零改动（C-2 红线）：conservative 档恒 keep 零处置。
			$a = UrlVerdicts::disposal( 'reject', 0.99, 'conservative', false );
			self::assertSame( 'keep', $a['action'], '增量保守档：仅标记（不动）' );
		}

		/* ===============================================================
		 * C-3 backlog 运行记录 option + 报告视图三态
		 * =============================================================== */

		public function test_enqueue_creates_backlog_record(): void {
			$worker = $this->makeWorker( array(
				'backlog_finder' => static fn(): array => array( 201, 202 ),
			) );

			$added  = $worker->enqueue_batch_backlog();
			$record = $GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BACKLOG_RECORD ];

			self::assertSame( 2, $added );
			self::assertSame( array( 201, 202 ), $record['ids'], '运行记录固化清单（报告口径数据源）' );
			self::assertSame( 2, $record['enqueued'], 'counts=入队数' );
			self::assertGreaterThan( 0, $record['started_at'], 'started_at 在场（起）' );
		}

		public function test_enqueue_merges_into_unfinished_record(): void {
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BATCH_QUEUE ]   = array( 201 );
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BACKLOG_RECORD ] = array(
				'started_at' => 111,
				'ids'        => array( 201 ),
				'enqueued'   => 1,
			);
			$worker = $this->makeWorker( array(
				'backlog_finder' => static fn(): array => array( 201, 202 ),
				'meta_reader'    => static fn( int $id ): ?array => null, // 201 未落判定=上轮未完成。
			) );

			$added  = $worker->enqueue_batch_backlog();
			$record = $GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BACKLOG_RECORD ];

			self::assertSame( 1, $added, '只计新增（201 已在队列）' );
			self::assertSame( array( 201, 202 ), $record['ids'], '未完成运行→归并续跑' );
			self::assertSame( 111, $record['started_at'], '未完成运行 started_at 保留' );
		}

		public function test_enqueue_replaces_finished_record(): void {
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BACKLOG_RECORD ] = array(
				'started_at' => 111,
				'ids'        => array( 201 ),
				'enqueued'   => 1,
			);
			$worker = $this->makeWorker( array(
				'backlog_finder' => static fn(): array => array( 202 ),
				'meta_reader'    => static fn( int $id ): ?array => array( 'verdict' => 'pass', 'judged_at' => 222 ), // 201 已收口=上轮完成。
			) );

			$added  = $worker->enqueue_batch_backlog();
			$record = $GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BACKLOG_RECORD ];

			self::assertSame( 1, $added );
			self::assertSame( array( 202 ), $record['ids'], '上轮已完成→新一轮记录覆盖（报告展示上一次运行结果）' );
			self::assertSame( 1700000000, $record['started_at'], '新一轮 started_at=本次入队时刻' );
		}

		public function test_backlog_report_view_empty(): void {
			$view = UrlAuditWorker::backlog_report_view( array(), array(), 0 );
			self::assertSame( 'empty', $view['state'], '空态：从未运行' );
		}

		public function test_backlog_report_view_running(): void {
			$record = array( 'started_at' => 100, 'ids' => array( 1, 2, 3 ), 'enqueued' => 3 );
			$metas  = array( 1 => array( 'verdict' => 'pass', 'judged_at' => 150 ) );
			$view   = UrlAuditWorker::backlog_report_view( $record, $metas, 2 );

			self::assertSame( 'running', $view['state'], '运行中：有 id 未落判定 meta' );
			self::assertSame( 100, $view['started_at'] );
			self::assertSame( 3, $view['enqueued'] );
			self::assertSame( 2, $view['remaining'], '残留=未落 meta 数' );
			self::assertSame( 2, $view['queue_residual'], '队列残留数（Tab⑦ 展示）' );
			self::assertSame( 1, $view['caliber']['pass'] );
		}

		public function test_backlog_report_view_completed_caliber(): void {
			$record = array( 'started_at' => 100, 'ids' => array( 1, 2, 3, 4, 5 ), 'enqueued' => 5 );
			$metas  = array(
				1 => array( 'verdict' => 'pass', 'judged_at' => 150 ),
				2 => array( 'verdict' => 'reject', 'judged_at' => 160 ),
				3 => array( 'verdict' => 'review', 'judged_at' => 170 ),
				4 => array( 'verdict' => 'inconclusive', 'judged_at' => 180 ),
				// 5 号：meta 未落且评论本体已删（backlog_report_data 计 gone——收口）。
			);
			$view = UrlAuditWorker::backlog_report_view( $record, $metas, 0, array( 5 ) );

			self::assertSame( 'completed', $view['state'], '完成：全部收口（含 gone）' );
			self::assertSame( 180, $view['finished_at'], '完成时间=meta 反查 max(judged_at)' );
			self::assertSame( 1, $view['caliber']['pass'], '没问题（pass）' );
			self::assertSame( 2, $view['caliber']['pending'], '待审核（review+inconclusive——未核实不下结论）' );
			self::assertSame( 1, $view['caliber']['spam'], '垃圾箱（reject）' );
			self::assertSame( 1, $view['gone'], 'gone=评论本体已删（收口不计残留）' );
			self::assertSame( 0, $view['remaining'], 'gone 收口后残留归零' );
		}
	}
}
