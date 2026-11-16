<?php
/**
 * M6 批 A · P1-3 url_audit 闸键对齐+批量回捞显式化回归测试（2026-11-15）。
 *
 * 根因（审计 P1-3，P3-F6/F8 实测定谳）：预算闸查 `Usage.counters[url_audit_incr|
 * url_audit_batch]`，而全库 LLM 调用经 UrlJudgeEngine `usage_key='deepseek'` 计入
 * deepseek 键——**无任何代码路径向两闸键计数**（闸逻辑正常、燃料表接错油箱）；叠加
 * 批量车道开启即对全部历史 URL 评论自动体检（回捞无有效上限）。
 *
 * 修复契约（M6 定稿 §0-3/§2 A-3）：
 *   ① UrlJudgeEngine 判定调用增 lane 维度 → usage_key 按 lane 传
 *      url_audit_incr/url_audit_batch；闸读侧零改动；deepseek 键保留给评论云审主链；
 *   ② 批量回捞移除「开启即自动回捞」——批量车道消费**显式队列**
 *      （wink_gatekeeper_url_audit_batch_queue，Tab⑦ 显式按钮 admin_post 动作入队；
 *      配额 50/日+暂停黄条语义照旧；D6 分工=操作卡 Tab⑦/报告卡 Tab⑥）；
 *   ③ Usage 键位常量预备：url_audit_fetch/deepseek_search 本批先立（批 B/C 消费，
 *      本批不接线）。
 *
 * 桩注记：WinkGatekeeper\UrlAudit 命名空间桩（get_option/update_option/get_transient/
 * set_transient——engine K2 记账+worker 显式队列+判定缓存的 WP 边界）；Stats 命名空间
 * 桩与 StatsCacheRecordBridgeTest 同款同 $GLOBALS 键（Usage::bump_option 播种配额面）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\UrlAudit {

	$GLOBALS['wk_ua_option_store']    = array();
	$GLOBALS['wk_ua_transient_store'] = array();

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
		 * get_transient 桩（判定缓存读——缺键 null=缓存 miss）。
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
		 * @param string $key       键。
		 * @param mixed  $value     值。
		 * @param int    $ttl       TTL（忽略）。
		 * @return bool
		 */
		function set_transient( string $key, $value, int $ttl = 0 ): bool {
			$GLOBALS['wk_ua_transient_store'][ $key ] = $value;
			return true;
		}
	}
}

namespace WinkGatekeeper\Stats {

	$GLOBALS['wk_stats_option_store']  = array();
	$GLOBALS['wk_stats_calls']         = array( 'update' => array(), 'error_log' => array(), 'get' => array() );
	$GLOBALS['wk_stats_update_result'] = true;

	if ( ! function_exists( 'WinkGatekeeper\Stats\get_option' ) ) {
		/**
		 * get_option 桩（StatsCacheRecordBridgeTest 同款）。
		 *
		 * @param string $name    option 名。
		 * @param mixed  $default 缺省值。
		 * @return mixed
		 */
		function get_option( string $name, $default = false ) {
			$GLOBALS['wk_stats_calls']['get'][] = $name;
			return array_key_exists( $name, $GLOBALS['wk_stats_option_store'] )
				? $GLOBALS['wk_stats_option_store'][ $name ]
				: $default;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\update_option' ) ) {
		/**
		 * update_option 桩（StatsCacheRecordBridgeTest 同款）。
		 *
		 * @param string $name     option 名。
		 * @param mixed  $value    值。
		 * @param mixed  $autoload 自动加载（忽略）。
		 * @return bool
		 */
		function update_option( string $name, $value, $autoload = null ): bool {
			$GLOBALS['wk_stats_calls']['update'][] = $name;
			if ( $GLOBALS['wk_stats_update_result'] ) {
				$GLOBALS['wk_stats_option_store'][ $name ] = $value;
			}
			return $GLOBALS['wk_stats_update_result'];
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\current_time' ) ) {
		/**
		 * current_time 桩（StatsCacheRecordBridgeTest 同款 + Y-m-d 档）。
		 *
		 * @param string $type 类型。
		 * @return string
		 */
		function current_time( string $type ): string {
			if ( 'Ymd' === $type ) {
				return '20261110';
			}
			if ( 'Y-m-d' === $type ) {
				return '2026-11-10';
			}
			return '';
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\error_log' ) ) {
		/**
		 * error_log 桩。
		 *
		 * @param string $message 消息。
		 * @return bool
		 */
		function error_log( string $message ): bool {
			$GLOBALS['wk_stats_calls']['error_log'][] = $message;
			return true;
		}
	}
}

namespace WinkGatekeeper\Tests {

	use PHPUnit\Framework\TestCase;
	use WinkGatekeeper\Http\RemoteClient;
	use WinkGatekeeper\Settings\SettingsSchema;
	use WinkGatekeeper\Stats\Usage;
	use WinkGatekeeper\UrlAudit\UrlAuditWorker;
	use WinkGatekeeper\UrlAudit\UrlFetcher;
	use WinkGatekeeper\UrlAudit\UrlJudgeEngine;

	final class M6AUrlAuditGateTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['wk_ua_option_store']    = array();
			$GLOBALS['wk_ua_transient_store'] = array();
			$GLOBALS['wk_stats_option_store'] = array();
		}

		/**
		 * 构造判定引擎（fake transport + 计量捕获）。
		 *
		 * @param array<int, array<string, mixed>> $responses 依次返回的 WP HTTP 形态响应。
		 * @return array{engine: UrlJudgeEngine, usage_keys: array<int, string>}
		 */
		private static function makeEngine( array $responses ): array {
			$usage_keys = array();
			$last       = null;
			$transport  = static function ( string $url, array $args ) use ( &$responses, &$last ) {
				$resp = array_shift( $responses );
				if ( null !== $resp ) {
					$last = $resp;
				} else {
					$resp = $last;
				}
				return $resp;
			};
			$client = new RemoteClient(
				$transport,
				static function ( string $key ) use ( &$usage_keys ): void {
					$usage_keys[] = $key;
				},
				static function ( int $ms ): void {}
			);
			$engine = new UrlJudgeEngine(
				array( 'api_key' => 'sk-test', 'base_url' => 'https://api.deepseek.com', 'model' => 'deepseek-flash', 'timeout' => 8, 'min_confidence' => 0.8 ),
				$client,
				'deepseek-flash',
				null
			);
			return array( 'engine' => $engine, 'usage_keys' => &$usage_keys );
		}

		/**
		 * chat/completions 200 响应（judge pass JSON+usage 字段）。
		 *
		 * @return array<string, mixed>
		 */
		private static function judgeResponse(): array {
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => (string) json_encode( array(
					'choices' => array( array(
						'message'       => array( 'role' => 'assistant', 'content' => (string) json_encode( array( 'verdict' => 'pass', 'confidence' => 0.9, 'reason' => '正常站点', 'hits' => array( 'benign' ) ) ) ),
						'finish_reason' => 'stop',
					) ),
					'usage'   => array( 'prompt_tokens' => 200, 'completion_tokens' => 30 ),
				) ),
			);
		}

		/**
		 * 构造 worker（默认队列通道+可注入覆盖）。
		 *
		 * @param array<string, callable> $hook_overrides 钩子覆盖。
		 * @param array<string, mixed>    $ua_overrides   url_audit 子树覆盖。
		 * @return UrlAuditWorker
		 */
		private function makeWorker( array $hook_overrides = array(), array $ua_overrides = array() ): UrlAuditWorker {
			$settings                       = SettingsSchema::defaults();
			$settings['url_audit']['enabled'] = true;
			foreach ( $ua_overrides as $key => $value ) {
				$settings['url_audit'][ $key ] = $value;
			}
			return new UrlAuditWorker( $settings, $hook_overrides );
		}

		/* ===============================================================
		 * ① lane→usage_key 映射钉死（P1-3 主修面）
		 * =============================================================== */

		public function test_engine_usage_key_follows_incr_lane(): void {
			$env = self::makeEngine( array( self::judgeResponse() ) );

			$env['engine']->judge( array( 'title' => 'T', 'meta_description' => 'D', 'text' => '正文' ), 'https://example.com/', '作者\n正文', 'url_audit_incr' );

			self::assertSame( array( 'url_audit_incr' ), $env['usage_keys'], '增量车道 usage_key=url_audit_incr（闸读侧所查键——修复前恒 deepseek）' );
		}

		public function test_engine_usage_key_follows_batch_lane(): void {
			$env = self::makeEngine( array( self::judgeResponse() ) );

			$env['engine']->judge( array( 'title' => 'T', 'meta_description' => 'D', 'text' => '正文' ), 'https://example.com/', '作者\n正文', 'url_audit_batch' );

			self::assertSame( array( 'url_audit_batch' ), $env['usage_keys'], '批量车道 usage_key=url_audit_batch' );
		}

		public function test_engine_never_counts_url_audit_under_deepseek_key(): void {
			// deepseek 键保留给评论云审主链（K1 语义不变——url_audit 消耗不得再混入）。
			$env = self::makeEngine( array( self::judgeResponse() ) );
			$env['engine']->judge( array( 'title' => 'T', 'meta_description' => 'D', 'text' => '正文' ), 'https://example.com/', '作者\n正文', 'url_audit_incr' );

			self::assertNotContains( 'deepseek', $env['usage_keys'], 'url_audit LLM 消耗不得计入 deepseek 键' );
		}

		public function test_engine_unknown_caller_failsafe_to_incr_key(): void {
			// 非法 caller fail-safe 归增量闸键（只放大严——消耗必须可被某道闸拦住）。
			$env = self::makeEngine( array( self::judgeResponse() ) );
			$env['engine']->judge( array( 'title' => 'T', 'meta_description' => 'D', 'text' => '正文' ), 'https://example.com/', '作者\n正文', 'bogus_lane' );

			self::assertSame( array( 'url_audit_incr' ), $env['usage_keys'] );
		}

		/* ===============================================================
		 * ③ Usage 键位常量预备（本批先立——批 B/C 消费）
		 * =============================================================== */

		public function test_usage_key_constants_prepared(): void {
			self::assertSame( 'url_audit_incr', Usage::KEY_URL_AUDIT_INCR );
			self::assertSame( 'url_audit_batch', Usage::KEY_URL_AUDIT_BATCH );
			self::assertSame( 'url_audit_fetch', Usage::KEY_URL_AUDIT_FETCH, '批 C 消费（本批不接线）' );
			self::assertSame( 'deepseek_search', Usage::KEY_DEEPSEEK_SEARCH, '批 B 消费（本批不接线）' );
		}

		public function test_engine_lane_keys_reference_usage_constants(): void {
			// lane→usage_key 映射与常量同源（防再漂移——闸读侧零改动的镜像约束）。
			$src = file_get_contents( dirname( __DIR__ ) . '/includes/urlaudit/UrlJudgeEngine.php' );
			self::assertIsString( $src );
			self::assertStringNotContainsString( "'usage_key' => 'deepseek'", $src, 'P1-3 根因字面退役' );
		}

		/* ===============================================================
		 * ② 批量车道显式队列：默认不回捞 + 显式动作入队
		 * =============================================================== */

		public function test_default_batch_ids_consumes_explicit_queue(): void {
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BATCH_QUEUE ] = array( 201, 202 );

			$worker = $this->makeWorker();
			$take   = $worker->default_batch_ids( 1 );

			self::assertSame( array( 201 ), $take, '批量车道=显式队列 FIFO 头取' );
			self::assertSame( array( 202 ), $GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BATCH_QUEUE ], '取出的 id 离队' );
		}

		public function test_enabled_feature_does_not_auto_backfill(): void {
			// P1-3 叠加缺陷主钉：开启 url_audit **不**自动回捞——批量队列零新增、零处理。
			$worker = $this->makeWorker();

			$out = $worker->run_round();

			self::assertSame( 0, $out['batch_processed'], '开启即自动回捞已废弃——无显式入队零批量处理' );
			self::assertSame( '', $out['stopped'], '空队列=正常空转（非停转）' );
			self::assertArrayNotHasKey(
				UrlAuditWorker::OPTION_BATCH_QUEUE,
				$GLOBALS['wk_ua_option_store'],
				'队列零新增（run_round 只消费不回捞）'
			);
		}

		public function test_explicit_backlog_enqueue_merges_deduped(): void {
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BATCH_QUEUE ] = array( 202 );
			$worker = $this->makeWorker( array(
				'backlog_finder' => static function (): array {
					return array( 201, 202, 203 );
				},
			) );

			$added = $worker->enqueue_batch_backlog();

			self::assertSame( 2, $added, '只计新增（202 已在队列不重复）' );
			self::assertSame( array( 202, 201, 203 ), $GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BATCH_QUEUE ] );
		}

		public function test_backlog_universe_ids_is_meta_gap_scan(): void {
			// 【M6·批D·D-6】翻转注记（原钉=「无判定 meta」排除/meta gap 扫描；翻钉原因=
			// 用户 2026-11-16 拍板「用户点击开始存量网址核查就全部查一遍」——施工图
			// §3b-add 全量重查语义：宇宙移除「无判定 meta」排除，全部 approved+有 URL
			// 评论都重查，新判定覆盖旧 meta；approved-only C-1 红线保持）。新钉=
			// 全量宇宙（approved+URL），详 M6DRecheckSemanticsTest::test_universe_drops_meta_exclusion。
			$src = file_get_contents( dirname( __DIR__ ) . '/includes/urlaudit/UrlAuditWorker.php' );
			self::assertIsString( $src );
			self::assertStringContainsString( 'comment_author_url', $src, '扫描宇宙含 URL 条件' );
			self::assertStringNotContainsString( 'm.meta_id IS NULL', $src, '「无判定 meta」排除已移除（全部重查一遍——§3b-add）' );
		}

		public function test_batch_lane_processes_queued_comment_with_batch_lane(): void {
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_BATCH_QUEUE ] = array( 301 );

			$metas    = array();
			$executor = array();
			$fetch_fail_client = new RemoteClient(
				static function (): array {
					return array( 'response' => array( 'code' => 500 ), 'headers' => array(), 'body' => 'boom' );
				},
				null,
				static function ( int $ms ): void {}
			);
			$worker = $this->makeWorker( array(
				'incr_take'        => static function ( int $limit ): array {
					return array();
				},
				'comment_reader'   => static function ( int $id ): array {
					return array( 'author' => '甲', 'body' => '带网址评论', 'url' => 'https://queued.example.com/', 'post_status' => 'publish' );
				},
				'meta_reader'      => static function ( int $id ): ?array {
					return null;
				},
				'meta_writer'      => static function ( int $id, ?array $meta ) use ( &$metas ): void {
					$metas[ $id ] = $meta;
				},
				'disposal_executor' => static function ( int $id, string $action ) use ( &$executor ): bool {
					$executor[] = array( $id, $action );
					return true;
				},
				'fetcher_factory'  => static function () use ( $fetch_fail_client ): UrlFetcher {
					return new UrlFetcher( $fetch_fail_client );
				},
			) );

			$out = $worker->run_round();

			self::assertSame( 1, $out['batch_processed'], '显式队列条目经批量车道消费' );
			self::assertSame( 'batch', $metas[301]['lane'] ?? '', 'meta lane=batch（存量处置语义面）' );
			// 【断言翻转·M6 批C·C-2】原钉=「存量车道零自动处置（仅标记哲学）」。翻钉原因=
			// 用户 2026-11-16 拍板（施工图 §3b C-2）：存量 reject→spam、review/inconclusive
			// →hold（wp_set_comment_status 可逆终态面）——本用例 fetch 失败族=inconclusive
			// →hold（没看清进待审）。
			self::assertSame( array( array( 301, 'hold' ) ), $executor, '存量 inconclusive（没看清）→待审（可手动恢复）' );
			self::assertSame( 'hold', $metas[301]['disposed_to'] ?? '', 'meta disposed_to=hold（处置溯源新字段）' );
		}

		/* ===============================================================
		 * 闸读侧零改动（回归钉）：播种两闸键→对应车道停转
		 * 【M6·批B·B-5 扩例注记】暂停边沿事件发射为批 B 新行为（quota 停转即发
		 * quota_paused 事件一次）——本组测试注入事件 sink 并加钉暂停事件形态；
		 * sink 注入非断言翻转（停转语义断言原样保留）。
		 * =============================================================== */

		public function test_seeding_incr_key_stops_incremental_lane(): void {
			$events = array();
			for ( $i = 0; $i < 2; $i++ ) {
				Usage::bump_option( 'url_audit_incr' );
			}
			$worker = $this->makeWorker( array(
				'incr_take' => static function ( int $limit ): array {
					return array( 401 );
				},
				'event_recorder' => static function ( string $reason, array $hits, array $meta ) use ( &$events ): void {
					$events[] = array( 'reason' => $reason, 'hits' => $hits, 'meta' => $meta );
				},
			), array( 'incr_quota' => 2, 'batch_quota' => 50 ) );

			$out = $worker->run_round();

			self::assertSame( 'incr_quota_exhausted', $out['stopped'], '播种 url_audit_incr=配额 → 增量车道停转（审计 P3 手法回归）' );
			self::assertSame( 0, $out['incr_processed'] );
			self::assertSame( array( 'quota_paused' ), $events[0]['hits'] ?? array(), '【M6·批B·B-5】暂停边沿事件=quota_paused' );
			self::assertSame( 'incr', $events[0]['meta']['lane'] ?? '' );
		}

		public function test_seeding_batch_key_stops_batch_lane(): void {
			$events = array();
			Usage::bump_option( 'url_audit_batch' );
			$worker = $this->makeWorker( array(
				'event_recorder' => static function ( string $reason, array $hits, array $meta ) use ( &$events ): void {
					$events[] = array( 'reason' => $reason, 'hits' => $hits, 'meta' => $meta );
				},
			), array( 'incr_quota' => 0, 'batch_quota' => 1 ) );

			$out = $worker->run_round();

			self::assertSame( 'batch_quota_exhausted', $out['stopped'], '批量先停增量继续（降级秩序语义不变）' );
			self::assertSame( 0, $out['batch_processed'] );
			self::assertSame( 'batch', $events[0]['meta']['lane'] ?? '', '【M6·批B·B-5】批量车道暂停边沿事件 lane=batch' );
		}

		/* ===============================================================
		 * 显式动作面：admin_post+nonce+manage_options（Tab⑦ 操作卡/D6 分工）
		 * =============================================================== */

		public function test_worker_declares_backlog_queue_and_action(): void {
			self::assertSame( 'wink_gatekeeper_url_audit_batch_queue', UrlAuditWorker::OPTION_BATCH_QUEUE );
			self::assertSame( 'wink_gatekeeper_url_audit_backlog', UrlAuditWorker::ACTION_BACKLOG );
		}

		public function test_settings_page_renders_explicit_backlog_button_in_tools_tab(): void {
			$src = file_get_contents( dirname( __DIR__ ) . '/includes/settings/SettingsPage.php' );
			self::assertIsString( $src );
			foreach ( array( 'wk-tools-url-audit-card', 'wk-tools-url-audit-state-line', 'wk-tools-url-audit-start-btn', '开始存量网址核查' ) as $anchor ) {
				self::assertStringContainsString( $anchor, $src, "Tab⑦ 操作卡锚缺失：{$anchor}" );
			}
			self::assertStringContainsString( 'handle_url_audit_backlog', $src, '处理器在场' );
		}

		public function test_backlog_handler_has_double_gate_and_redirects_to_tools_tab(): void {
			$src = file_get_contents( dirname( __DIR__ ) . '/includes/settings/SettingsPage.php' );
			self::assertIsString( $src );
			$start = strpos( (string) $src, 'public static function handle_url_audit_backlog' );
			self::assertNotFalse( $start, 'handle_url_audit_backlog 在场' );
			$body = substr( (string) $src, $start, 1600 );
			self::assertStringContainsString( "current_user_can( 'manage_options' )", $body, '能力闸' );
			self::assertStringContainsString( 'check_admin_referer', $body, 'nonce 闸' );
			self::assertStringContainsString( "self::tab_url( 'tools' )", $body, '回跳 Tab⑦（D6 分工：操作卡在 Tab⑦）' );
			self::assertStringContainsString( 'enqueue_batch_backlog', $body, '入队动作接线' );
		}

		public function test_bootstrap_registers_backlog_admin_post_action(): void {
			$src = file_get_contents( dirname( __DIR__ ) . '/includes/Bootstrap.php' );
			self::assertIsString( $src );
			self::assertStringContainsString(
				"admin_post_' . UrlAuditWorker::ACTION_BACKLOG",
				$src,
				'Bootstrap 注册 admin_post 显式回捞动作'
			);
		}

		public function test_tab3_url_audit_card_has_no_backfill_button(): void {
			// D6 分工反钉：操作按钮不得漂移进 Tab③ 配置卡（render_url_audit_card 函数体内）。
			$src = file_get_contents( dirname( __DIR__ ) . '/includes/settings/SettingsPage.php' );
			self::assertIsString( $src );
			$start = strpos( (string) $src, 'private static function render_url_audit_card' );
			$end   = strpos( (string) $src, 'private static function audit_schedule_tick' );
			self::assertNotFalse( $start );
			self::assertNotFalse( $end );
			$card = substr( (string) $src, $start, $end - $start );
			self::assertStringNotContainsString( 'ACTION_BACKLOG', $card, 'Tab③ 卡不含回捞动作（操作卡在 Tab⑦）' );
			self::assertStringNotContainsString( '开始存量网址核查', $card, 'Tab③ 卡不含回捞按钮' );
		}
	}
}
