<?php
/**
 * M6 批 E · 处置统一「没看清→人工判定」（0.6.7，2026-11-16——施工图 §4-add）。
 *
 * 用户原话（2026-11-16 第四轮反馈）：「我测试了用 DeepSeek 网页抓取几个违规站点，
 * 都返回 404。因此决策，『没看清』→ 统一人工判定。」（背景案例=missav.ws，
 * docs/reports/M6-MANUAL-FEEDBACK-3-2026-11-16.md——违规站对一切抓取端普遍
 * 「无正文可给」，inconclusive 是违规站的常态结局，放行不可接受。）
 *
 * 变更契约（施工图 §4-add，统筹定稿——inconclusive 恒不定罪 §0-7 不变，
 * 本次变更是「不定罪但悬置待审」非「定罪」）：
 *   ① UrlVerdicts::disposal()（增量车道矩阵）：inconclusive 与 review 一律→hold
 *      （待审=统一人工判定入口），三档 profile 皆然；pass→不动；reject 分支不动
 *      （conservative 只标记/balanced≥0.95 spam/aggressive≥0.8 spam）；
 *   ② disposal_existing（存量车道）零改动（批 C 已系 hold）；
 *   ③ hold 执行走既有 disposal_executor 注入面（wp_set_comment_status hold——
 *      可逆终态，可手动批准恢复）零新增执行件；meta.disposed_to=hold 落位；
 *   ④ Tab③ 处置档位三档 radio 描述与新矩阵语义一致；
 *   ⑤ 旧钉翻转：M5U5 test_inconclusive_never_has_reject_weight（「inconclusive
 *      零处置恒 keep」钉）——翻钉原因=用户 2026-11-16 拍板（§4-add）。
 *
 * 桩注记：WinkGatekeeper\UrlAudit 命名空间桩与 M6AUrlAuditGateTest 同款同 $GLOBALS 键
 * （function_exists 守卫同进程共存）。
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

	if ( ! function_exists( 'WinkGatekeeper\Stats\get_option' ) ) {
		/**
		 * get_option 桩（M6AUrlAuditGateTest 同款——Usage 配额面）。
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

	if ( ! function_exists( 'WinkGatekeeper\Stats\update_option' ) ) {
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

	if ( ! function_exists( 'WinkGatekeeper\Stats\current_time' ) ) {
		/**
		 * current_time 桩。
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

	if ( ! function_exists( 'WinkGatekeeper\Stats\error_log' ) ) {
		/**
		 * error_log 桩。
		 *
		 * @param string $message 消息。
		 * @return bool
		 */
		function error_log( string $message ): bool {
			return true;
		}
	}
}

namespace WinkGatekeeper\Tests {

	use PHPUnit\Framework\TestCase;
	use WinkGatekeeper\Http\RemoteClient;
	use WinkGatekeeper\Settings\SettingsSchema;
	use WinkGatekeeper\UrlAudit\UrlAuditWorker;
	use WinkGatekeeper\UrlAudit\UrlVerdicts;

	final class M6EDisposalMatrixTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['wk_ua_option_store']    = array();
			$GLOBALS['wk_ua_transient_store'] = array();
		}

		/* ===============================================================
		 * ① 处置矩阵 12 例全组合钉（三档 profile × 四判定——§4-add 主钉）
		 * =============================================================== */

		public function test_disposal_matrix_12_cells_full_combination(): void {
			foreach ( array( 'conservative', 'balanced', 'aggressive' ) as $profile ) {
				// pass→不动（三档一致——URL pass 仍零动作，不救内容待审不放行）。
				$p = UrlVerdicts::disposal( 'pass', 0.95, $profile, false );
				self::assertSame( 'keep', $p['action'], "{$profile}×pass→keep（不动）" );
				self::assertFalse( $p['auto_url_reject'], "{$profile}×pass 零自动标垃圾" );

				// inconclusive→hold（【M6·批E】三档统一——没看清=统一人工判定入口；
				// 恒不定罪 §0-7 不变：hold=悬置待审非定罪）。
				$i = UrlVerdicts::disposal( 'inconclusive', 0.0, $profile, false );
				self::assertSame( 'hold', $i['action'], "{$profile}×inconclusive→hold（没看清→统一人工判定）" );
				self::assertFalse( $i['auto_url_reject'], "{$profile}×inconclusive 零自动标垃圾（不定罪）" );

				// review→hold（三档统一——存疑同样进人工判定入口）。
				$r = UrlVerdicts::disposal( 'review', 0.5, $profile, false );
				self::assertSame( 'hold', $r['action'], "{$profile}×review→hold" );
				self::assertFalse( $r['auto_url_reject'], "{$profile}×review 零自动标垃圾" );
			}

			// reject（conf=0.99 高置信）——分支不动（三档形态与批 E 前一致）。
			$rc = UrlVerdicts::disposal( 'reject', 0.99, 'conservative', false );
			self::assertSame( 'keep', $rc['action'], 'conservative×reject→keep（只标记）' );
			self::assertFalse( $rc['auto_url_reject'] );
			$rb = UrlVerdicts::disposal( 'reject', 0.99, 'balanced', false );
			self::assertSame( 'spam', $rb['action'], 'balanced×reject@0.99≥0.95→spam（可撤销）' );
			self::assertTrue( $rb['auto_url_reject'], 'balanced 自动定罪带 auto_url_reject（一键撤销面）' );
			$ra = UrlVerdicts::disposal( 'reject', 0.99, 'aggressive', false );
			self::assertSame( 'spam', $ra['action'], 'aggressive×reject@0.99≥0.8→spam（可撤销）' );
			self::assertTrue( $ra['auto_url_reject'] );
		}

		public function test_reject_branch_boundaries_unchanged(): void {
			// 分支红线：自动定罪门阈值与批 E 前一致（0.95 独立旋钮不共享）。
			self::assertSame( 'spam', UrlVerdicts::disposal( 'reject', 0.95, 'balanced', false )['action'], 'balanced 门=0.95（含）' );
			self::assertSame( 'keep', UrlVerdicts::disposal( 'reject', 0.949, 'balanced', false )['action'], 'balanced 门下=保持待审附证据' );
			self::assertSame( 'spam', UrlVerdicts::disposal( 'reject', 0.8, 'aggressive', false )['action'], 'aggressive 门=0.8（含）' );
			self::assertSame( 'keep', UrlVerdicts::disposal( 'reject', 0.799, 'aggressive', false )['action'] );
			self::assertSame( 0.95, UrlVerdicts::AUTO_CONVICT_CONFIDENCE, '自动定罪门 0.95 常量不动' );
		}

		public function test_injection_fuse_unchanged(): void {
			// 注入保险丝（nw §3.4）：只封 pass 自动化、不改 reject 处置——批 E 前原样。
			$a = UrlVerdicts::disposal( 'pass', 0.99, 'aggressive', true );
			self::assertSame( 'keep', $a['action'], '注入命中时 pass 自动放行保险丝失效（本就零动作）' );
			$b = UrlVerdicts::disposal( 'reject', 0.99, 'balanced', true );
			self::assertSame( 'spam', $b['action'], '注入命中不改变 reject 处置' );
		}

		public function test_disposal_existing_zero_change(): void {
			// §4-add 红线：存量车道矩阵（批 C C-2 形态）零改动——已是 hold。
			self::assertSame( 'spam', UrlVerdicts::disposal_existing( 'reject', 0.99 )['action'] );
			self::assertSame( 'hold', UrlVerdicts::disposal_existing( 'review', 0.5 )['action'] );
			self::assertSame( 'hold', UrlVerdicts::disposal_existing( 'inconclusive', 0.0 )['action'] );
			self::assertSame( 'none', UrlVerdicts::disposal_existing( 'pass', 0.95 )['action'] );
		}

		/* ===============================================================
		 * ② 增量车道 worker 执行面（hold 走既有 executor 注入面——零新增执行件）
		 * =============================================================== */

		public function test_incr_lane_inconclusive_executes_hold(): void {
			// missav.ws 形态（fetch 404→not_found→inconclusive）：approved 评论被收回待审
			// （统一人工判定入口）；meta disposed_to=hold 落位；零 auto_url_reject。
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_INCR_QUEUE ] = array( 901 );
			$metas     = array();
			$disposals = array();
			$worker    = new UrlAuditWorker( self::settings(), array(
				'comment_reader' => static fn( int $id ): array => array( 'author' => '甲', 'body' => '带网址评论', 'url' => 'https://missav.ws/example/', 'post_status' => 'publish' ),
				'meta_reader'    => static fn( int $id ): ?array => null,
				'meta_writer'    => static function ( int $id, ?array $meta ) use ( &$metas ): void {
					$metas[ $id ] = $meta;
				},
				'disposal_executor' => static function ( int $id, string $action ) use ( &$disposals ): bool {
					$disposals[] = array( $id, $action );
					return true;
				},
				'fetcher_factory' => static fn(): object => new class() {
					/**
					 * @return array<string, mixed> 404 形态（not_found 失败类）。
					 */
					public function fetch( string $url, bool $robots_comply = false ): array {
						return array(
							'fetch_class' => 'not_found', 'final_url' => $url, 'hop_hosts' => array(), 'status' => 404,
							'content_type' => '', 'title' => '', 'og_title' => '', 'meta_description' => '',
							'meta_refresh' => '', 'js_redirect' => '', 'text' => '', 'robots_state' => '',
							'redirects' => 0, 'fetch_provider' => 'self',
						);
					}
				},
				'clock' => static fn(): float => 1700000000.0,
			) );

			$out = $worker->run_round();

			self::assertSame( 1, $out['incr_processed'], 'fetch 失败照常消费' );
			self::assertSame( 'hold', $metas[901]['action'], 'meta action=hold（没看清→待审）' );
			self::assertSame( 'hold', $metas[901]['disposed_to'], 'meta disposed_to=hold 落位（处置溯源）' );
			self::assertArrayNotHasKey( 'auto_url_reject', $metas[901], 'inconclusive 恒不定罪（无自动标垃圾标记）' );
			self::assertSame( array( array( 901, 'hold' ) ), $disposals, 'hold 执行走 disposal_executor 注入面（wp_set_comment_status hold——可逆）' );
		}

		public function test_incr_lane_review_executes_hold(): void {
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_INCR_QUEUE ] = array( 902 );
			$metas     = array();
			$disposals = array();
			$worker    = new UrlAuditWorker( self::settings(), array(
				'comment_reader' => static fn( int $id ): array => array( 'author' => '乙', 'body' => '带网址评论', 'url' => 'https://ambiguous.example.com/', 'post_status' => 'publish' ),
				'meta_reader'    => static fn( int $id ): ?array => null,
				'meta_writer'    => static function ( int $id, ?array $meta ) use ( &$metas ): void {
					$metas[ $id ] = $meta;
				},
				'disposal_executor' => static function ( int $id, string $action ) use ( &$disposals ): bool {
					$disposals[] = array( $id, $action );
					return true;
				},
				'fetcher_factory' => self::okFetcher(),
				'judge_factory'   => self::scriptedJudge( array( self::llmResponse( 'review', 0.85 ) ) ),
				'clock'           => static fn(): float => 1700000000.0,
			) );

			$out = $worker->run_round();

			self::assertSame( 1, $out['incr_processed'] );
			self::assertSame( 'review', $metas[902]['verdict'] ?? '' );
			self::assertSame( 'hold', $metas[902]['action'], 'review→hold（存疑进待审——三档统一）' );
			self::assertSame( 'hold', $metas[902]['disposed_to'] );
			self::assertSame( array( array( 902, 'hold' ) ), $disposals );
		}

		public function test_incr_lane_pass_untouched(): void {
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_INCR_QUEUE ] = array( 903 );
			$metas     = array();
			$disposals = array();
			$worker    = new UrlAuditWorker( self::settings(), array(
				'comment_reader' => static fn( int $id ): array => array( 'author' => '丙', 'body' => '正常站评论', 'url' => 'https://fine.example.com/', 'post_status' => 'publish' ),
				'meta_reader'    => static fn( int $id ): ?array => null,
				'meta_writer'    => static function ( int $id, ?array $meta ) use ( &$metas ): void {
					$metas[ $id ] = $meta;
				},
				'disposal_executor' => static function ( int $id, string $action ) use ( &$disposals ): bool {
					$disposals[] = array( $id, $action );
					return true;
				},
				'fetcher_factory' => self::okFetcher(),
				'judge_factory'   => self::scriptedJudge( array( self::llmResponse( 'pass', 0.9 ) ) ),
				'clock'           => static fn(): float => 1700000000.0,
			) );

			$out = $worker->run_round();

			self::assertSame( 1, $out['incr_processed'] );
			self::assertSame( 'keep', $metas[903]['action'], 'pass→不动（零动作）' );
			self::assertSame( 'none', $metas[903]['disposed_to'], 'pass 零处置去向' );
			self::assertSame( array(), $disposals, '正常站零执行器调用（B 机对照面——正常站不动）' );
		}

		public function test_incr_lane_reject_balanced_spam_unchanged(): void {
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_INCR_QUEUE ] = array( 904 );
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_PAUSE_EDGE ] = array();
			$metas     = array();
			$disposals = array();
			$worker    = new UrlAuditWorker( self::settings( array( 'profile' => 'balanced' ) ), array(
				'comment_reader' => static fn( int $id ): array => array( 'author' => '丁', 'body' => '违规站评论', 'url' => 'https://bad.example.com/', 'post_status' => 'publish' ),
				'meta_reader'    => static fn( int $id ): ?array => null,
				'meta_writer'    => static function ( int $id, ?array $meta ) use ( &$metas ): void {
					$metas[ $id ] = $meta;
				},
				'disposal_executor' => static function ( int $id, string $action ) use ( &$disposals ): bool {
					$disposals[] = array( $id, $action );
					return true;
				},
				'fetcher_factory' => self::okFetcher(),
				'judge_factory'   => self::scriptedJudge( array( self::llmResponse( 'reject', 0.97 ) ) ),
				'clock'           => static fn(): float => 1700000000.0,
			) );

			$out = $worker->run_round();

			self::assertSame( 1, $out['incr_processed'] );
			self::assertSame( 'spam', $metas[904]['action'], 'reject@0.97≥0.95 自动标垃圾（分支不动）' );
			self::assertSame( 'spam', $metas[904]['disposed_to'] );
			self::assertTrue( ! empty( $metas[904]['auto_url_reject'] ), 'auto_url_reject 撤销面保留' );
			self::assertSame( array( array( 904, 'spam' ) ), $disposals );
		}

		public function test_incr_lane_reject_conservative_mark_only(): void {
			$GLOBALS['wk_ua_option_store'][ UrlAuditWorker::OPTION_INCR_QUEUE ] = array( 905 );
			$metas     = array();
			$disposals = array();
			$worker    = new UrlAuditWorker( self::settings( array( 'profile' => 'conservative' ) ), array(
				'comment_reader' => static fn( int $id ): array => array( 'author' => '戊', 'body' => '违规站评论', 'url' => 'https://bad.example.com/', 'post_status' => 'publish' ),
				'meta_reader'    => static fn( int $id ): ?array => null,
				'meta_writer'    => static function ( int $id, ?array $meta ) use ( &$metas ): void {
					$metas[ $id ] = $meta;
				},
				'disposal_executor' => static function ( int $id, string $action ) use ( &$disposals ): bool {
					$disposals[] = array( $id, $action );
					return true;
				},
				'fetcher_factory' => self::okFetcher(),
				'judge_factory'   => self::scriptedJudge( array( self::llmResponse( 'reject', 0.99 ) ) ),
				'clock'           => static fn(): float => 1700000000.0,
			) );

			$out = $worker->run_round();

			self::assertSame( 1, $out['incr_processed'] );
			self::assertSame( 'keep', $metas[905]['action'], '保守档 reject 仍只标记（分支不动）' );
			self::assertSame( 'none', $metas[905]['disposed_to'] );
			self::assertSame( array(), $disposals, '保守档 reject 零执行器调用' );
		}

		/* ===============================================================
		 * ③ 文案契约（UrlVerdicts 顶注 + Tab③ 三档 radio 描述——与新矩阵一致）
		 * =============================================================== */

		public function test_urlverdicts_topnote_updated(): void {
			$src = file_get_contents( dirname( __DIR__ ) . '/includes/urlaudit/UrlVerdicts.php' );
			self::assertIsString( $src );
			self::assertStringContainsString( 'inconclusive→待审（统一人工判定', $src, '顶注=「inconclusive→待审（统一人工判定，2026-11-16 用户拍板）」' );
			self::assertStringContainsString( 'pass 仍零动作', $src, '顶注含 pass 仍零动作' );
			self::assertStringNotContainsString( '权重恒为零', $src, '旧「inconclusive 权重恒为零」表述退役（§4-add 翻转）' );
		}

		public function test_tab3_radio_copy_matches_matrix(): void {
			$src   = file_get_contents( dirname( __DIR__ ) . '/includes/settings/SettingsPage.php' );
			self::assertIsString( $src );
			$card = self::functionBody( $src, 'render_url_audit_card' );
			// conservative：违规不自动进垃圾箱；没看清先进待审等你判定。
			self::assertStringContainsString( '违规不自动进垃圾箱', $card, '保守档描述含新矩阵语义' );
			self::assertStringContainsString( '网址没看清的会先进待审等你判定', $card, '保守档 inconclusive→待审人话' );
			// balanced：确凿自动进垃圾箱（可撤销），其余进待审等你复核。
			self::assertStringContainsString( '证据确凿的自动进垃圾箱（可一键撤销），其余进待审等你复核', $card, '均衡档描述含新矩阵语义' );
			// aggressive：拿不太准的也自动进垃圾箱；没看清的进待审。
			self::assertStringContainsString( '没看清的进待审', $card, '激进档 inconclusive→待审人话' );
			// 旧文案退役（与旧矩阵绑定的表述不再出现）。
			self::assertStringNotContainsString( '其余附上证据等你复核', $card, '旧均衡档描述退役（「其余」现进待审非只附证据）' );
		}

		/* ===============================================================
		 * 桩工厂
		 * =============================================================== */

		/**
		 * 基础设置树（url_audit enabled；profile 可覆盖——默认 balanced 贴 B 机现态）。
		 *
		 * @param array<string, mixed> $ua_overrides url_audit 子树覆盖。
		 * @return array<string, mixed>
		 */
		private static function settings( array $ua_overrides = array() ): array {
			$settings                         = SettingsSchema::defaults();
			$settings['url_audit']['enabled'] = true;
			// 【M6·批H·H-1】抓取管线测试夹具显式 self 档（inherit 现解析 unconfigured 不自动自抓——本批语义翻转，管线行为测试须显式选择抓取档）。
			$settings['url_audit']['fetcher']['mode'] = 'self';
			$settings['url_audit']['profile'] = 'balanced';
			foreach ( $ua_overrides as $key => $value ) {
				$settings['url_audit'][ $key ] = $value;
			}
			return $settings;
		}

		/**
		 * fetch ok 桩工厂（UrlFetcher 产物同形键——LLM 链路测试不触真 fetcher）。
		 *
		 * @return callable
		 */
		private static function okFetcher(): callable {
			return static function (): object {
				return new class() {
					/**
					 * @return array<string, mixed>
					 */
					public function fetch( string $url, bool $robots_comply = false ): array {
						return array(
							'fetch_class'      => 'ok',
							'final_url'        => $url,
							'hop_hosts'        => array(),
							'status'           => 200,
							'content_type'     => 'text/html',
							'title'            => '示例标题',
							'og_title'         => '',
							'meta_description' => '示例描述',
							'meta_refresh'     => '',
							'js_redirect'      => '',
							'text'             => '这是足够长度的示例正文内容，喂判定引擎。',
							'robots_state'     => 'no_signal',
							'redirects'        => 0,
							'fetch_provider'   => 'self',
						);
					}
				};
			};
		}

		/**
		 * 判定工厂（脚本化 LLM 响应依次消费；计量回调零注入——K2 记账走 UrlAudit 桩通道）。
		 *
		 * @param array<int, array<string, mixed>> $responses 依次返回的 WP HTTP 形态响应。
		 * @return callable
		 */
		private static function scriptedJudge( array $responses ): callable {
			return static function () use ( &$responses ) {
				$resp      = array_shift( $responses );
				$transport = static function ( string $url, array $args ) use ( &$resp ) {
					return $resp;
				};
				$client = new RemoteClient( $transport, null, static function ( int $ms ): void {} );
				$engine = new \WinkGatekeeper\UrlAudit\UrlJudgeEngine(
					array( 'api_key' => 'sk-test', 'base_url' => 'https://api.deepseek.com', 'model' => 'deepseek-flash', 'timeout' => 8, 'min_confidence' => 0.8 ),
					$client,
					'deepseek-flash',
					null
				);
				return array( 'judge' => $engine, 'model' => 'deepseek-flash', 'bocha' => null );
			};
		}

		/**
		 * chat/completions 200 响应（verdict/confidence 可指定）。
		 *
		 * @param string $verdict    判定键。
		 * @param float  $confidence 置信度。
		 * @return array<string, mixed>
		 */
		private static function llmResponse( string $verdict, float $confidence ): array {
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => (string) json_encode( array(
					'choices' => array( array(
						'message'       => array( 'role' => 'assistant', 'content' => (string) json_encode( array( 'verdict' => $verdict, 'confidence' => $confidence, 'reason' => '示例理由', 'hits' => array() ) ) ),
						'finish_reason' => 'stop',
					) ),
					'usage'   => array( 'prompt_tokens' => 200, 'completion_tokens' => 30 ),
				) ),
			);
		}

		/**
		 * 提取函数体（UiM5Tab3UrlAuditContractTest 同款手法——源码契约面）。
		 *
		 * @param string $src       源码。
		 * @param string $function  函数名。
		 * @return string
		 */
		private static function functionBody( string $src, string $function ): string {
			$start = strpos( $src, "function {$function}" );
			self::assertNotFalse( $start, "function {$function} 必须存在" );
			$open  = strpos( $src, '{', $start );
			$depth = 0;
			$len   = strlen( $src );
			for ( $i = $open; $i < $len; $i++ ) {
				if ( '{' === $src[ $i ] ) {
					++$depth;
				} elseif ( '}' === $src[ $i ] ) {
					--$depth;
					if ( 0 === $depth ) {
						return substr( $src, $start, $i - $start + 1 );
					}
				}
			}
			return substr( $src, $start );
		}
	}
}
