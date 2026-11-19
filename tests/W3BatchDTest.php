<?php
/**
 * W3 批 D · 收官契约测试（施工图 docs/W3-重设计施工图-2026-11-18.md §2 批 D——TDD 红先行）。
 *
 * 四块断言面（施工图 §2 批 D + R6 口径）：
 *   ① N8 CSV 导出端点（全图唯一实锤空接线收口——钮与端点同 commit）：
 *      admin_post GET 形态（die_preview 先例）+ manage_options/check_admin_referer 双闸 +
 *      EventsTable::today_intercept_rows 当日窗体（recent_* 同族 SQL builder 逐字钉）+
 *      InterceptDigest::export_csv_rows 行组装五列闭集（时间/结果/哪张门/原因/事件编号——
 *      零账号/零邮箱/零评论正文/零 IP 明文，与登录流水表 Q3 同口径 PIPL 红线）+
 *      fputcsv 流式 text/csv attachment（filename=wink-intercepts-YYYY-MM-DD.csv）+
 *      当日零记录=黄条提示不输出文件（wp_safe_redirect 回 Tab⑥ 带 wk-export-empty 标志）；
 *   ② N4 判定=规划壳：Tab② 阿里卡 ⚙ 折叠内 .wk-card--ghost 规划行「每日验证额度
 *      （规划 · 待施工）」+ 口径注「额度用完当日停转、次日自愈」——零新键零控件
 *      （SettingsSchema captcha 子树行为级走树扫描 + SettingsPage 全文件零 day_quota 字面）；
 *   ③ 0.8.5 版本四点：插件头 Version / WINK_GATEKEEPER_VERSION / readme Stable tag /
 *      changelog '= 0.8.5 =' 当前条目（0.8.4 条目还原保留）；
 *   ④ pin 全量翻转核对：十文件版本断言零 0.8.4 残留（施工图 §1.6 清单逐一）+
 *      W3TabRemodelTest 批 C no-bump 钉翻转。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

/* ====================================================================
 * WP 边界函数桩（WinkGatekeeper\Settings 命名空间内先解析——tests/bootstrap.php
 * 桩族同约定，function_exists 守卫防跨文件重定义；本批 render_stats_records_card
 * 行为级渲染需要 wp_nonce_url/sanitize_key/wp_unslash 三件新增桩）。
 * ==================================================================== */

namespace WinkGatekeeper\Settings {

	if ( ! function_exists( 'WinkGatekeeper\Settings\wp_nonce_url' ) ) {
		/**
		 * wp_nonce_url 命名空间桩（最小形态——URL 追加 &_wpnonce= 摘要）。
		 *
		 * @param string $action_url 基 URL。
		 * @param string $action     nonce action。
		 * @return string
		 */
		function wp_nonce_url( string $action_url, string $action = '-1' ): string {
			return $action_url . '&_wpnonce=' . md5( $action );
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\sanitize_key' ) ) {
		/**
		 * sanitize_key 命名空间桩（WP 同款小写+白名单字符）。
		 *
		 * @param string $key 原始键。
		 * @return string
		 */
		function sanitize_key( string $key ): string {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\wp_unslash' ) ) {
		/**
		 * wp_unslash 命名空间桩（identity——单测环境无 superglobal slash 注入）。
		 *
		 * @param mixed $value 原值。
		 * @return mixed
		 */
		function wp_unslash( $value ) {
			return $value;
		}
	}
}

namespace WinkGatekeeper\Tests {

	use PHPUnit\Framework\TestCase;
	use WinkGatekeeper\Settings\SettingsPage;
	use WinkGatekeeper\Settings\SettingsSchema;
	use WinkGatekeeper\Stats\EventsTable;
	use WinkGatekeeper\Stats\InterceptDigest;
	use WinkGatekeeper\Stats\Labels;

	final class W3BatchDTest extends TestCase {

		/* ================================================================
		 * 零件（既有契约测试同款：源读取 / 函数体提取 / 区块提取 / 反射渲染）
		 * ================================================================ */

		private static function src( string $rel ): string {
			$src = file_get_contents( dirname( __DIR__ ) . '/' . $rel );
			self::assertIsString( $src, "{$rel} 必须可读" );
			return (string) $src;
		}

		private static function sp(): string {
			return self::src( 'includes/settings/SettingsPage.php' );
		}

		private static function functionBody( string $src, string $name ): string {
			$start = strpos( $src, 'function ' . $name );
			self::assertNotFalse( $start, "函数 {$name} 必须存在" );
			$open  = strpos( $src, '{', (int) $start );
			$depth = 0;
			$len   = strlen( $src );
			for ( $i = (int) $open; $i < $len; $i++ ) {
				if ( '{' === $src[ $i ] ) {
					$depth++;
				} elseif ( '}' === $src[ $i ] ) {
					$depth--;
					if ( 0 === $depth ) {
						return substr( $src, (int) $open, $i - $open + 1 );
					}
				}
			}
			self::fail( "函数 {$name} 花括号不配平" );
			return '';
		}

		private static function body( string $name ): string {
			return self::functionBody( self::sp(), $name );
		}

		private static function block_between( string $src, string $start_marker, string $end_marker ): string {
			$start = strpos( $src, $start_marker );
			self::assertNotFalse( $start, "须含 {$start_marker}" );
			$end = strpos( $src, $end_marker, $start + strlen( $start_marker ) );
			self::assertNotFalse( $end, "须在 {$start_marker} 之后含 {$end_marker}" );
			return substr( $src, $start, $end - $start );
		}

		/**
		 * 私有静态无参渲染件反射渲染（W3BatchCTest 同款——ob 捕获输出）。
		 *
		 * @param string $method 方法名。
		 * @return string 渲染产物。
		 */
		private static function renderPrivate( string $method ): string {
			$ref = new \ReflectionMethod( SettingsPage::class, $method );
			$ref->setAccessible( true );
			ob_start();
			try {
				$ref->invoke( null );
			} finally {
				$out = (string) ob_get_clean();
			}
			return $out;
		}

		protected function setUp(): void {
			WpOptionStub::reset();
			unset( $_GET['wk-export-empty'], $_GET['tab'], $_GET['page'] );
		}

		protected function tearDown(): void {
			WpOptionStub::reset();
			unset( $_GET['wk-export-empty'], $_GET['tab'], $_GET['page'] );
		}

		/* ================================================================
		 * ① N8 CSV 导出端点（R6：GET + nonce + manage_options 双闸；钮与端点同 commit）
		 * ================================================================ */

		public function test_export_action_constant_and_bootstrap_registration(): void {
			self::assertSame( 'wink_gatekeeper_export_csv', SettingsPage::ACTION_EXPORT_CSV, 'action 常量字面（蓝图 R6——wink_gatekeeper_export_csv）' );

			$bootstrap = self::src( 'includes/Bootstrap.php' );
			self::assertStringContainsString(
				"add_action( 'admin_post_' . SettingsPage::ACTION_EXPORT_CSV, array( SettingsPage::class, 'handle_export_csv' ) );",
				$bootstrap,
				'Bootstrap 注册 admin_post_{ACTION_EXPORT_CSV} → handle_export_csv（die_preview/wizard 出口同族 GET 形态）'
			);
		}

		public function test_handler_double_gate_source_scan(): void {
			$body = self::body( 'handle_export_csv' );
			self::assertStringContainsString( "current_user_can( 'manage_options' )", $body, '能力闸一：manage_options' );
			self::assertStringContainsString( 'wp_die', $body, '能力闸失败 wp_die（die_preview 同款 403）' );
			self::assertStringContainsString( 'check_admin_referer( self::ACTION_EXPORT_CSV', $body, 'nonce 闸二：check_admin_referer（GET 形态——施工图 §6 批 D 行）' );
			self::assertStringContainsString( 'exit', $body, '输出后 exit（零后续渲染）' );
		}

		public function test_today_intercept_rows_sql_closed_columns(): void {
			$sql = EventsTable::today_intercept_rows_sql( 'wp_wink_gatekeeper_events' );

			self::assertStringStartsWith(
				'SELECT event_no, created_at, gate, stage, rule_hit, meta FROM wp_wink_gatekeeper_events',
				$sql,
				'列闭集六列（meta 仅为 trigger_source 归因读件）——零 author/email/content/ip_hash 明文列（PIPL 最小化）'
			);
			self::assertStringContainsString( "verdict = 'reject'", $sql, '仅拦下行（拒绝流水口径）' );
			self::assertStringContainsString( 'AND created_at >= %s', $sql, '当日窗体（created_at >= 当日零点——today_fault_distribution 同族）' );
			self::assertStringContainsString( 'ORDER BY id DESC LIMIT %d', $sql, '自增主键倒序 + prepare 上限占位（只读 SELECT 全件）' );
			self::assertStringNotContainsString( 'ip_hash', $sql, 'IP 哈希省略（导出列无 IP——任务口径）' );
			self::assertStringNotContainsString( 'gate = %s', $sql, '全闸流水（不滤门——「哪张门」列数据面）' );
		}

		public function test_today_intercept_rows_boundary_method_shape(): void {
			$method  = new \ReflectionMethod( EventsTable::class, 'today_intercept_rows' );
			$params  = $method->getParameters();
			self::assertSame( 'today', $params[0]->getName(), '参数一=当日日期串（WP 边界 current_time 产物由调用方传入——本层零 WP 依赖）' );
			self::assertSame( EventsTable::EXPORT_MAX_ROWS, $params[1]->getDefaultValue(), '默认上限=导出日流水面常量' );
			self::assertSame( 1, EventsTable::export_limit_clamped( 0 ), '钳制下界 1' );
			self::assertSame( 1, EventsTable::export_limit_clamped( -5 ), '钳制负值 1' );
			self::assertSame( 1000, EventsTable::export_limit_clamped( 1001 ), '钳制上界 1000' );
			self::assertSame( 500, EventsTable::export_limit_clamped( 500 ), '界内原样' );
		}

		/**
		 * 夹具事件行（EventsTable 查回形态——meta 已解码）。
		 *
		 * @return array<int, array<string, mixed>>
		 */
		private static function fixture_events(): array {
			return array(
				array(
					'event_no'   => 'WK-20261118-0007',
					'created_at' => '2026-11-18 09:30:00',
					'gate'       => 'comment',
					'stage'      => 'captcha',
					'rule_hit'   => 'bad-signature',
					'meta'       => array( 'comment_id' => 42, 'author' => '张三', 'email' => 'zhang@example.com', 'content' => '一 条需保密的评论正文' ),
				),
				array(
					'event_no'   => 'WK-20261118-0012',
					'created_at' => '2026-11-18 10:05:00',
					'gate'       => 'login',
					'stage'      => 'stub',
					'rule_hit'   => '',
					'meta'       => array( 'trigger_source' => 'user_threshold' ),
				),
				array(
					'event_no'   => 'WK-20261118-0015',
					'created_at' => '2026-11-18 11:44:00',
					'gate'       => 'comment',
					'stage'      => 'review',
					'rule_hit'   => 'banned_word',
					'meta'       => array(),
				),
			);
		}

		public function test_export_csv_rows_field_closed_set_zero_pipl(): void {
			$rows = InterceptDigest::export_csv_rows( self::fixture_events() );

			self::assertCount( 3, $rows, '行数=事件行数' );
			$flat = '';
			foreach ( $rows as $row ) {
				self::assertCount( 5, $row, '行宽闭集=五列（时间/结果/哪张门/原因/事件编号）' );
				self::assertIsList( $row, '列表形态（fputcsv 直喂）' );
				foreach ( $row as $cell ) {
					self::assertIsString( $cell, '单元格全字符串（CSV 纯文本）' );
				}
				$flat .= implode( "\n", $row ) . "\n";
			}

			// 结果三态映射（Q3 同源——出码·拦下｜未过码·拦下；放行不在拒绝数据面不虚构）。
			self::assertSame( '出码 · 拦下', $rows[0][1], 'captcha 阶段=出码 · 拦下' );
			self::assertSame( '未过码 · 拦下', $rows[1][1], 'stub 预检阶段=未过码 · 拦下' );
			self::assertSame( '拦下', $rows[2][1], 'review 码后阶段=如实「拦下」（免验/已过码两实态不可分——码语汇不冒充）' );

			// 门名/原因（trigger 归因优先，缺省回落 rule_hit 人话）。
			self::assertSame( '评论门', $rows[0][2], '门名走 Labels::gate_word' );
			self::assertSame( '登录门', $rows[1][2], '登录门名' );
			self::assertSame( '票据签名不对', $rows[0][3], '无 trigger 回落 rule_hit 人话' );
			self::assertSame( '同一账号连错太多次', $rows[1][3], 'trigger_source 归因优先（account_reject_rows 同口径）' );

			// 事件编号原样（WK- 前缀保真——访客报障对账面）。
			self::assertSame( 'WK-20261118-0007', $rows[0][4], '事件编号列保真' );
			self::assertStringStartsWith( '2026-11-18 09:30:00', $rows[0][0], '时间列原样' );

			// PIPL 红线：夹具植入的明文账号/邮箱/正文零出现（meta 只取 trigger_source）。
			self::assertStringNotContainsString( '张三', $flat, '零明文账号' );
			self::assertStringNotContainsString( 'zhang@example.com', $flat, '零明文邮箱' );
			self::assertStringNotContainsString( '需保密的评论正文', $flat, '零评论正文' );

			// PIPL 复核注记（导出面同一口径的说明文案在按钮侧注记——本断言钉行组装键面）。
			$json = (string) json_encode( $rows );
			self::assertStringNotContainsString( 'author', $json, '零 author 键' );
			self::assertStringNotContainsString( 'email', $json, '零 email 键' );
			self::assertStringNotContainsString( 'content', $json, '零 content 键' );
			self::assertStringNotContainsString( 'ip', $json, '零 IP 键（含 ip_hash）' );
		}

		public function test_export_result_word_matrix(): void {
			self::assertSame( '出码 · 拦下', Labels::export_result_word( 'captcha' ), '出码阶段（两域同词——Labels::account_result_word 同源）' );
			self::assertSame( '未过码 · 拦下', Labels::export_result_word( 'stub' ), '登录预检=未过码 · 拦下' );
			self::assertSame( '未过码 · 拦下', Labels::export_result_word( 'ip' ), '评论 IP 信誉阶段=码前拦（未过码 · 拦下如实）' );
			self::assertSame( '拦下', Labels::export_result_word( 'review' ), '码后复核阶段不冒充码语汇' );
		}

		public function test_export_csv_columns_header_const(): void {
			self::assertSame(
				array( '时间', '结果', '哪张门', '原因', '事件编号' ),
				InterceptDigest::EXPORT_CSV_COLUMNS,
				'列头闭集（任务口径五列——与登录流水表列面同词汇族）'
			);
		}

		public function test_export_csv_filename_and_streaming_headers(): void {
			self::assertSame( 'wink-intercepts-2026-11-18.csv', InterceptDigest::export_csv_filename( '2026-11-18' ), '文件名口径 wink-intercepts-YYYY-MM-DD.csv（任务 R6 定稿）' );

			$body = self::body( 'handle_export_csv' );
			self::assertStringContainsString( 'Content-Type: text/csv', $body, 'text/csv 头' );
			self::assertStringContainsString( 'Content-Disposition: attachment', $body, 'attachment 头（下载不内嵌）' );
			self::assertStringContainsString( 'export_csv_filename', $body, '文件名走纯函数（可测件）' );
			self::assertStringContainsString( 'fputcsv', $body, 'fputcsv 流式输出' );
			self::assertStringContainsString( 'php://output', $body, '流式目标 php://output（逐行写、零全量驻留）' );
			self::assertStringContainsString( 'current_time( ', $body, '当日口径走 WP current_time（站点时区当日）' );
			self::assertStringContainsString( 'today_intercept_rows', $body, '数据源=EventsTable::today_intercept_rows' );
		}

		public function test_handler_empty_day_redirects_no_file(): void {
			$body = self::body( 'handle_export_csv' );
			self::assertStringContainsString( 'wp_safe_redirect', $body, '空数据=回设置页（不输出空文件）' );
			self::assertStringContainsString( 'wk-export-empty', $body, '回跳带黄条标志' );

			$card = self::body( 'render_stats_records_card' );
			self::assertStringContainsString( 'wk-export-empty', $card, 'Tab⑥ 卡消费标志渲染黄条' );
			self::assertStringContainsString( 'notice_yellow_bar', $card, '黄条走 notice_yellow_bar helper（迁段口径照守）' );
		}

		public function test_records_card_render_button_and_notice(): void {
			// 空数据回跳态：黄条在场。
			$_GET['wk-export-empty'] = '1';
			$out                     = self::renderPrivate( 'render_stats_records_card' );
			self::assertStringContainsString( '今天还没有拦截记录', $out, '空数据黄条文案（不输出文件提示）' );
			self::assertStringContainsString( 'admin-post.php?action=wink_gatekeeper_export_csv', $out, '导出钮=GET 链接（die_preview/url_audit purge 同形态）' );
			self::assertStringContainsString( '_wpnonce=', $out, 'wp_nonce_url 在场（nonce 挂链）' );
			self::assertStringContainsString( '导出当日拦截流水', $out, '按钮文案（原型 tab6 段六口径）' );
			self::assertStringContainsString( '不含账号、邮箱、评论正文与 IP', $out, 'PIPL 复核注记（最小化口径如实注）' );
			self::assertStringContainsString( 'id="wk-stats-records"', $out, 'DOM 锚保留（UiTab6StatsContractTest 同锚）' );

			// 常态：无标志零黄条（有话才出现）。
			unset( $_GET['wk-export-empty'] );
			$out2 = self::renderPrivate( 'render_stats_records_card' );
			self::assertStringNotContainsString( '今天还没有拦截记录', $out2, '无标志零黄条' );
			self::assertStringNotContainsString( '接入后启用', $out2, '空接线悬空文案退役（N8 收口）' );
		}

		/* ================================================================
		 * ② N4 判定=规划壳（Tab② 阿里卡 ⚙ 折叠——零新键零控件）
		 * ================================================================ */

		public function test_n4_day_quota_ghost_shell_in_aliyun_fold(): void {
			$fold = self::block_between( self::sp(), 'id="wk-aliyun-advanced"', 'wk-aliyun-test-credentials' );
			self::assertStringContainsString( '每日验证额度', $fold, 'N4 规划行=每日验证额度' );
			self::assertStringContainsString( 'wk-card--ghost', $fold, '规划行=虚线壳（批 A CSS 件消费——design-system §04）' );
			self::assertStringContainsString( '规划 · 待施工', $fold, '规划徽标（wk-plan-tag 同词）' );
			self::assertStringContainsString( '额度用完当日停转、次日自愈', $fold, '口径注（原型文案照抄）' );
			self::assertStringContainsString( '当前验证码服务未设每日上限', $fold, '如实注：当前零控件零行为（不冒充实装）' );

			// 零控件：ghost 块内零表单控件（零 field_name/零 input name）。
			$ghost_start = strpos( $fold, '每日验证额度' );
			self::assertNotFalse( $ghost_start );
			$ghost = substr( (string) $fold, (int) $ghost_start );
			self::assertStringNotContainsString( 'field_name', $ghost, '零新键（无表单字段）' );
			self::assertStringNotContainsString( '<input', $ghost, '零控件（规划壳红线）' );
			self::assertStringNotContainsString( '<select', $ghost, '零控件（规划壳红线）' );
		}

		public function test_n4_captcha_subtree_has_no_day_quota_key(): void {
			// 行为级走树：captcha 子树任意深度零 day_quota/daily_quota 键（SettingsSchema::defaults 纯函数）。
			$tree    = SettingsSchema::defaults();
			$captcha = $tree['captcha'] ?? null;
			self::assertIsArray( $captcha, 'captcha 子树在场' );
			$hits = array();
			array_walk_recursive(
				$captcha,
				static function ( $v, $k ) use ( &$hits ): void {
					if ( is_string( $k ) && ( 'day_quota' === $k || 'daily_quota' === $k ) ) {
						$hits[] = $k;
					}
				}
			);
			self::assertSame( array(), $hits, 'captcha 子树零日额度键（N4 判定=规划位的实证面）' );

			// 对照组：iprep.abuseipdb.daily_quota 在场（证明走树扫描灵敏度——扫得到才叫零）。
			$tree  = SettingsSchema::defaults();
			$iprep = $tree['iprep'] ?? array();
			$found = false;
			array_walk_recursive(
				$iprep,
				static function ( $v, $k ) use ( &$found ): void {
					if ( 'daily_quota' === $k ) {
						$found = true;
					}
				}
			);
			self::assertTrue( $found, '对照组：iprep 子树日额度键在场（扫描灵敏度自证）' );

			// 渲染层零 day_quota 字面（SettingsPage 全文件——usage 计数在 Stats 面，无额度闸语义）。
			self::assertStringNotContainsString( 'day_quota', self::sp(), 'SettingsPage 零 day_quota 字面' );
		}

		/* ================================================================
		 * ③ 0.8.5 版本四点（批 D 进位——R9）
		 * ================================================================ */

		public function test_version_four_points_0_8_5(): void {
			$entry  = self::src( 'wink-wp-gatekeeper.php' );
			$readme = self::src( 'readme.txt' );

			self::assertMatchesRegularExpression( '/^ \* Version:\s+0\.8\.7$/m', $entry, '四点①插件头 Version 0.8.7（AUDIT-FIX 批进位——0.8.6 收口在前）' );
			self::assertStringContainsString( "define( 'WINK_GATEKEEPER_VERSION', '0.8.7' )", $entry, '四点②版本常量 0.8.7' );
			self::assertMatchesRegularExpression( '/^Stable tag: 0\.8\.7$/m', $readme, '四点③readme Stable tag 0.8.7' );
			self::assertStringContainsString( '= 0.8.7 =', $readme, '四点④changelog 当前条目 0.8.7' );

			// 0.8.4 条目还原保留（历史条目不删——readme changelog 只增不删纪律）。
			self::assertStringContainsString( '= 0.8.4 =', $readme, '0.8.4 历史条目保留' );

			// 当前条目位次：0.8.5 在 0.8.4 之前（Changelog 倒序纪律）。
			self::assertLessThan(
				strpos( $readme, '= 0.8.4 =' ),
				(int) strpos( $readme, '= 0.8.5 =' ),
				'0.8.5 条目位次在 0.8.4 之前（倒序）'
			);

			// breaking 注收口：batch C 占位的「开发中」措辞随发版退役。
			self::assertStringNotContainsString( '0.8.5 起·开发中，随下一版本发布', $readme, 'breaking 注「开发中」占位措辞退役（0.8.5 已发版）' );
			self::assertStringContainsString( 'breaking 注（0.8.5 起）', $readme, 'breaking 注 0.8.5 正式落位' );

			// 0.8.5 changelog 正文四批要点在场（人话总结——任务口径）。
			$entry085 = self::block_between( $readme, '= 0.8.5 =', '= 0.8.4 =' );
			self::assertStringContainsString( '后台', $entry085, '批 A/B 后台重设计要点' );
			self::assertStringContainsString( '配色', $entry085, '批 A token 双组跟随 WP 配色要点' );
			self::assertStringContainsString( 'Wink WP 保安', $entry085, '批 B 改名要点' );
			self::assertStringContainsString( '向导', $entry085, '批 C 首启向导要点' );
			self::assertStringContainsString( '导出', $entry085, '批 D CSV 导出要点' );
			self::assertStringContainsString( '测试 2139→', $entry085, '四批测试数轨迹（2139 起点如实）' );
		}

		/* ================================================================
		 * ④ pin 全量翻转核对（施工图 §1.6 十文件逐一 + 零 0.8.4 版本断言残留）
		 * ================================================================ */

		/**
		 * 施工图 §1.6 pin 十文件清单。
		 *
		 * @return string[] 文件名闭集。
		 */
		private static function pin_files(): array {
			return array(
				'B1aContractTest.php',
				'B1bContractTest.php',
				'Fix3Tab3ToggleContractTest.php',
				'M6EDriverOpenAiCustomTest.php',
				'UiM4UxF2ContractTest.php',
				'UiM4UxF3ContractTest.php',
				'UiM4UxG2ContractTest.php',
				'UiM4UxGContractTest.php',
				'UiTab1OverviewContractTest.php',
				'UiTab6StatsContractTest.php',
			);
		}

		public function test_pin_files_all_flipped_to_0_8_5(): void {
			foreach ( self::pin_files() as $file ) {
				$src = self::src( 'tests/' . $file );
				self::assertStringContainsString(
					"define( 'WINK_GATEKEEPER_VERSION', '0.8.7' )",
					$src,
					"{$file} 版本常量断言已翻转 0.8.7（AUDIT-FIX 批递进——0.8.6 收口在前）"
				);
				self::assertStringNotContainsString(
					"define( 'WINK_GATEKEEPER_VERSION', '0.8.4' )",
					$src,
					"{$file} 零 0.8.4 版本常量断言残留"
				);
				self::assertStringNotContainsString(
					'Version:\s+0\.8\.4',
					$src,
					"{$file} 零 0.8.4 头注 Version 正则残留（escaped-dot 保形——git log 8d19d9f 教训）"
				);
				self::assertStringNotContainsString(
					'Stable tag: 0\.8\.4',
					$src,
					"{$file} 零 0.8.4 Stable tag 正则残留"
				);
			}
		}

		public function test_zero_084_version_pins_anywhere_in_tests(): void {
			// 全 tests 目录扫描：任何文件零四类 0.8.4 版本断言残留（含 W3TabRemodelTest 批 C no-bump 钉翻转面）。
			// 检索串拼接成型（防本文件自匹配——needle 字面不在本文件源码中出现）。
			$n_const  = 'WINK_GATEKEEPER_VERSION' . "', '0.8" . ".4'";
			$n_head   = 'Version:\s+0' . '\.8' . '\.4';
			$n_stable = 'Stable tag: 0' . '\.8' . '\.4';
			$n_plain  = 'assertStringContainsString( ' . "'0.8" . ".4'";
			$dir      = dirname( __DIR__ ) . '/tests';
			$files    = glob( $dir . '/*.php' );
			$residue  = array();
			foreach ( $files as $file ) {
				if ( 'W3BatchDTest.php' === basename( (string) $file ) ) {
					continue; // 本文件自带四类 0.8.4 反钉字面量（负断言 needle 定义面）——非翻转残留面。
				}
				$src = (string) file_get_contents( $file );
				if ( str_contains( $src, $n_const )
					|| str_contains( $src, $n_head )
					|| str_contains( $src, $n_stable )
					|| str_contains( $src, $n_plain ) ) {
					$residue[] = basename( (string) $file );
				}
			}
			self::assertSame( array(), $residue, 'tests 全目录零 0.8.4 版本断言残留。残留：' . implode( ', ', $residue ) );
		}

		public function test_w3_tab_remodel_no_bump_pin_flipped(): void {
			$src = self::src( 'tests/W3TabRemodelTest.php' );
			self::assertStringContainsString( "'0.8.7'", $src, '批内 no-bump 钉已随 AUDIT-FIX 批翻转为 0.8.7（0.8.6 收口在前）' );
			self::assertStringNotContainsString( "assertStringContainsString( '0.8.4', \$plugin", $src, '批 C no-bump 钉零 0.8.4 残留' );
		}

		/* ================================================================
		 * ⑤ N8 配套契约面（无空接线/钩子族红线不破）
		 * ================================================================ */

		public function test_export_action_not_in_ajax_family_red_line(): void {
			// 红线反钉：导出走 admin_post GET（下载语义），不入 wp_ajax 族（零 admin-ajax 注册）。
			$bootstrap = self::src( 'includes/Bootstrap.php' );
			self::assertStringNotContainsString( "wp_ajax_' . SettingsPage::ACTION_EXPORT_CSV", $bootstrap, '导出端点不入 wp_ajax 族（admin_post GET 形态定稿）' );
		}
	}
}
