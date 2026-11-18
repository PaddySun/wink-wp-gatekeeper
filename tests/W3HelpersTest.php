<?php
/**
 * W3 批 A · 五件 render helper 契约测试（施工图 §2 批 A R2 盘点——同文件私有方法，
 * 单文件口径；迁段样板=helper 建件必配现渲染真实消费，无空接线）。
 *
 * 三层断言面：
 *   ① 五 helper 输出锚（Reflection 直调 + ob 捕获——DOM 产物锚断言）：
 *     notice_yellow_bar（四级参数→WP 原生 class 映射 + id 锚 + 多 <p> 形态）/
 *     advanced_fold（details.wk-guide + summary 锚）/ test_credential_btn（按钮+结果位+
 *     五态徽标闭集）/ usage_card_html（usage-card 族五域形态）/ report_card（三票面+原因折叠）；
 *   ② 迁段消费 source-scan 三防：防悬空（helper 定义必有编排位调用）/ 防丢锚（迁段后
 *     DOM/字段 id 锚仍在编排函数体内）/ 防丢字段（url_audit 折叠内字段 name 路径零变化）；
 *   ③ 单文件口径：五 helper 全部声明于 SettingsPage.php 类内（functionBody 可提取）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

/* -----------------------------------------------------------------
 * WP 函数桩（WinkGatekeeper\Settings 命名空间内先解析——identity 形态，
 * 与 M6BNoticeAndEventsTest 同款；function_exists 守卫防重复定义）。
 * ----------------------------------------------------------------- */
namespace WinkGatekeeper\Settings {

	if ( ! function_exists( 'WinkGatekeeper\Settings\esc_html' ) ) {
		function esc_html( string $text ): string {
			return $text;
		}
	}
	if ( ! function_exists( 'WinkGatekeeper\Settings\esc_html_e' ) ) {
		function esc_html_e( string $text ): void {
			echo $text;
		}
	}
	if ( ! function_exists( 'WinkGatekeeper\Settings\esc_html__' ) ) {
		function esc_html__( string $text, string $domain = 'wink-gatekeeper' ): string {
			return $text;
		}
	}
	if ( ! function_exists( 'WinkGatekeeper\Settings\__' ) ) {
		function __( string $text, string $domain = 'wink-gatekeeper' ): string {
			return $text;
		}
	}
	if ( ! function_exists( 'WinkGatekeeper\Settings\esc_attr' ) ) {
		function esc_attr( string $text ): string {
			return $text;
		}
	}
	if ( ! function_exists( 'WinkGatekeeper\Settings\esc_url' ) ) {
		function esc_url( string $url ): string {
			return $url;
		}
	}
}

namespace WinkGatekeeper\Tests {

	use PHPUnit\Framework\TestCase;
	use WinkGatekeeper\Settings\SettingsPage;

	final class W3HelpersTest extends TestCase {

		private static function settings_src(): string {
			$src = file_get_contents( __DIR__ . '/../includes/settings/SettingsPage.php' );
			self::assertIsString( $src, 'SettingsPage.php 必须可读' );
			return (string) $src;
		}

		private static function function_body( string $src, string $name ): string {
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
			return self::function_body( self::settings_src(), $name );
		}

		/**
		 * Reflection 直调私有静态渲染方法（echo 型 ob 捕获；return 型取返回值）。
		 *
		 * @param string $method 方法名。
		 * @param mixed  ...$args 参数。
		 */
		private static function invoke_render( string $method, ...$args ): string {
			$ref = new \ReflectionMethod( SettingsPage::class, $method );
			$ref->setAccessible( true );
			ob_start();
			$out = $ref->invoke( null, ...$args );
			$buf = (string) ob_get_clean();
			return null !== $out ? (string) $out : $buf;
		}

		/* ===================================================================
		 * ③ 单文件口径
		 * =================================================================== */

		public function test_five_helpers_declared_inside_settingspage_single_file(): void {
			$src = self::settings_src();
			foreach ( array( 'notice_yellow_bar', 'advanced_fold', 'test_credential_btn', 'usage_card_html', 'report_card' ) as $helper ) {
				self::assertStringContainsString( 'private static function ' . $helper . '(', $src, "helper {$helper} 以同文件私有方法形态声明（单文件口径）" );
				// functionBody 可提取=声明于 SettingsPage.php 类内（跨文件即提取失败）。
				self::assertStringContainsString( 'self::' . $helper . '(', self::settings_src(), "helper {$helper} 在文件内有真实消费（无空接线）" );
			}
		}

		/* ===================================================================
		 * ①-notice_yellow_bar：四级参数→class 映射 + 输出锚
		 * =================================================================== */

		public function test_notice_level_map_closed_set(): void {
			$src = self::settings_src();
			// 四级→WP 原生 class 映射闭集（NOTICE_LEVEL_CLASSES 类常量——helper 消费位）。
			// 【W3·批B 翻转】四级映射翻插件级类（批 A WP 原生等价形态退役——W3TabRemodelTest 同步钉）。
			foreach ( array(
				array( 'warn', 'wk-notice wk-notice--warn' ),
				array( 'danger', 'wk-notice wk-notice--danger' ),
				array( 'ok', 'wk-notice wk-notice--ok' ),
				array( 'info', 'wk-notice wk-notice--info' ),
			) as $pair ) {
				self::assertMatchesRegularExpression(
					"/'" . $pair[0] . "'\s+=>\s+'" . preg_quote( $pair[1], '/' ) . "'/",
					$src,
					"{$pair[0]}→{$pair[1]}（四级闭集映射）"
				);
			}
		}

		public function test_notice_yellow_bar_output_anchors(): void {
			$html = self::invoke_render( 'notice_yellow_bar', 'danger', '<strong>被拒</strong>', array( '第一段', '第二段' ), ' <a href="#">去修</a>', '', 'wk-test-bar' );
			self::assertStringContainsString( '<div class="wk-notice wk-notice--danger" id="wk-test-bar">', $html, '四级映射到插件级黄条类 + id 锚（【W3·批B 翻转】）' );
			self::assertStringContainsString( '<p><strong>被拒</strong>第一段</p>', $html, 'hd 拼首段' );
			self::assertStringContainsString( '<p>第二段 <a href="#">去修</a></p>', $html, 'act 拼末段（多 <p> 形态）' );
			self::assertSame( 2, substr_count( $html, '<p>' ), '数组参数=逐段一 <p>' );
		}

		public function test_notice_yellow_bar_classes_override_keeps_legacy_literal(): void {
			// 迁段兼容位：编排位显式传 class 字面量（source-scan 契约钉——批 B 翻转后可去）。
			$html = self::invoke_render( 'notice_yellow_bar', 'warn', '', '正文', '', 'notice notice-warning inline', '' );
			self::assertStringContainsString( '<div class="notice notice-warning inline">', $html, '显式 classes 覆盖位生效（迁段等价）' );
		}

		/* ===================================================================
		 * ①-advanced_fold：details.wk-guide 折叠锚
		 * =================================================================== */

		public function test_advanced_fold_output_anchor(): void {
			$html = self::invoke_render( 'advanced_fold', 'id="wk-fold-x"', '高级配置', static function (): void {
				echo '<p>inner</p>';
			} );
			self::assertStringContainsString( '<details class="adv" id="wk-fold-x"><summary>高级配置</summary>', $html, '折叠锚：details.adv + id + summary（【W3·批B 翻转】⚙ 前缀由 CSS ::before 承载）' );
			self::assertStringContainsString( '<p>inner</p></details>', $html, 'inner 原样 + 收口 </details>' );
		}

		/* ===================================================================
		 * ①-test_credential_btn：按钮 + 结果位 + 五态徽标闭集
		 * =================================================================== */

		public function test_credential_btn_output_anchors(): void {
			$html = self::invoke_render( 'test_credential_btn', 'id="wk-x-test-credentials"', 'id="wk-x-test-result"', 'nonce123', '测试凭据', 'abuseipdb' );
			self::assertStringContainsString( '<button type="button" class="button" id="wk-x-test-credentials" data-nonce="nonce123">', $html, '按钮锚 + nonce 注入' );
			self::assertStringContainsString( '<span id="wk-x-test-result" aria-live="polite"></span>', $html, '三态结果位锚（aria-live）' );
			self::assertStringContainsString( 'class="cred-test"', $html, 'cred-test 组件类（原型新形态容器）' );
			self::assertStringContainsString( 'badge b-dim', $html, '未测试=中性灰初始态' );
			self::assertStringContainsString( '上次测试：--', $html, '上次测试等宽小字位（批 C 接线持久化读数）' );
		}

		public function test_probe_state_badge_five_states_closed_set(): void {
			$map = array(
				''       => array( 'b-dim', '未测试' ),
				'green'  => array( 'b-ok', '贯通 · 凭据可用' ),
				'yellow' => array( 'b-warn', '缺字 · 必填缺失' ),
				'red'    => array( 'b-err', '被拒 · 密钥错' ),
				'gray'   => array( 'b-dim', '契约不符 · 状态未知' ),
			);
			foreach ( $map as $state => $conf ) {
				$badge = self::invoke_render( 'probe_state_badge', $state, '11-18 14:30' );
				self::assertStringContainsString( 'badge ' . $conf[0], $badge, "state「{$state}」→ {$conf[0]}" );
				self::assertStringContainsString( '>' . $conf[1] . '</span>', $badge, "state「{$state}」→ 状态词「{$conf[1]}」" );
				self::assertStringContainsString( '上次测试：11-18 14:30', $badge, '上次测试小字位' );
			}
			// 非法态=闭集外回落未测试（五态闭集纪律）。
			$bad = self::invoke_render( 'probe_state_badge', 'bogus', '' );
			self::assertStringContainsString( 'badge b-dim', $bad, '闭集外回落中性灰' );
		}

		/* ===================================================================
		 * ①-usage_card_html：usage-card 族形态（视图模型=DashboardDigest::usage_card 键面）
		 * =================================================================== */

		public function test_usage_card_html_output_anchors(): void {
			$view = array(
				'domains'       => array(
					array(
						'id'    => 'captcha',
						'label' => '人机验证',
						'rows'  => array(
							array( 'driver_id' => 'aliyun', 'label' => '阿里云验证码 2.0', 'used' => 86, 'quota' => 300, 'quota_reached' => false, 'bill_free' => false ),
						),
					),
					array(
						'id'    => 'fetch',
						'label' => '网页抓取',
						'rows'  => array(
							array( 'driver_id' => 'self_fetch', 'label' => '本站直接打开——不计费', 'used' => 0, 'quota' => 0, 'quota_reached' => false, 'bill_free' => true ),
						),
					),
				),
				'site_total'    => 214,
				'disaster_pass' => 3,
			);
			$html = self::invoke_render( 'usage_card_html', $view );
			self::assertStringContainsString( 'class="usage-card"', $html, 'usage-card 壳' );
			self::assertStringContainsString( 'class="usage-hd"', $html, '卡头' );
			self::assertStringContainsString( '站点总外呼 214 次', $html, '卡头合计（等宽 sum 位）' );
			self::assertStringContainsString( 'class="usage-domain"', $html, '域分隔行' );
			self::assertStringContainsString( 'class="usage-row"', $html, '域内行' );
			self::assertStringContainsString( '86 / 300', $html, '配额读数 N / M' );
			self::assertStringContainsString( 'class="prog"', $html, '进度条' );
			self::assertStringContainsString( 'class="usage-row free"', $html, '不计费声明行（free 形态）' );
			self::assertStringContainsString( '本站直接打开——不计费', $html, 'free 行无次数渲染（M6·H-4 口径）' );
			self::assertStringContainsString( '今日容灾放行', $html, '容灾放行行' );
			// 空态：零数据域不渲染=显式空态行（「真安静/没记数」纪律）。
			$empty = self::invoke_render( 'usage_card_html', array( 'domains' => array(), 'site_total' => 0, 'disaster_pass' => 0 ) );
			self::assertStringContainsString( '今日无外呼', $empty, '空态显式声明' );
			self::assertStringNotContainsString( 'usage-domain', $empty, '零数据域不渲染' );
		}

		/* ===================================================================
		 * ①-report_card：三票面 + 原因折叠（Tab⑦ 报告卡等价收口）
		 * =================================================================== */

		public function test_report_card_output_anchors(): void {
			$view = array(
				'state'           => 'completed',
				'section_id'      => 'wk-tools-existing-audit-report',
				'title'           => '存量审核报告（历史评论体检）',
				'meta_id'         => 'wk-tools-existing-report-meta',
				'meta_text'       => '服务商：X · 发起：2026-11-18 09:00 · 已完成',
				'tickets_class'   => 'wk-report-tickets',
				'caliber_id'      => 'wk-tools-existing-audit-caliber',
				'tickets'         => array(
					array( 'name' => '没问题（pass）', 'main' => '10 条', 'sub' => '已核无问题——评论状态不动' ),
				),
				'actions_html'    => '<a href="#">去待审队列</a>',
				'recovery_text'   => '判违规进垃圾箱、没看清进待审，都能手动恢复。',
				'reasons_id'      => 'wk-tools-existing-audit-reasons',
				'reasons_summary' => '原因明细（2 类）',
				'reasons_html'    => '<p>「垃圾广告」2 条 → 垃圾箱</p>',
			);
			$html = self::invoke_render( 'report_card', $view );
			self::assertStringContainsString( 'id="wk-tools-existing-audit-report"', $html, '报告卡锚' );
			self::assertStringContainsString( 'id="wk-tools-existing-report-meta"', $html, '报告 meta 锚' );
			self::assertStringContainsString( 'class="wk-report-tickets"', $html, '三票面容器' );
			self::assertStringContainsString( 'wk-report-ticket__main', $html, '票面主行' );
			self::assertStringContainsString( 'id="wk-tools-existing-audit-reasons"', $html, '原因明细折叠锚' );
			self::assertStringContainsString( '<summary>原因明细（2 类）</summary>', $html, '折叠 summary' );

			$empty = self::invoke_render( 'report_card', array_merge( $view, array( 'state' => 'empty', 'empty_id' => 'wk-tools-existing-report-empty', 'empty_text' => '还没体检过历史评论' ) ) );
			self::assertStringContainsString( 'id="wk-tools-existing-report-empty"', $empty, '空态锚（空态视图）' );
			self::assertStringContainsString( '还没体检过历史评论', $empty, '空态文案位' );
			self::assertStringNotContainsString( 'wk-report-tickets', $empty, '空态不渲染票面' );
		}

		/* ===================================================================
		 * ② 迁段消费 source-scan 三防
		 * =================================================================== */

		public function test_guards_consumption_sites_wired(): void {
			$src = self::settings_src();
			// 防悬空：每件 helper 都有编排位真实消费（迁段样板）。
			self::assertSame( 7, substr_count( self::body( 'render_overview_alerts' ), 'self::notice_yellow_bar(' ), 'Tab① 通栏七条迁 notice_yellow_bar' );
			self::assertSame( 1, substr_count( self::body( 'render_overview_onboarding' ), 'self::notice_yellow_bar(' ), 'Tab① 引导通栏迁 1 条' );
			self::assertSame( 6, substr_count( self::body( 'render_tab_captcha' ), 'self::notice_yellow_bar(' ), 'Tab② 六条迁（服务端/H3-A/两处 IP 信誉三态/两驱动 privacy 行——【W3·批B】privacy 黄条 helper 化）' );
			self::assertStringContainsString( 'self::advanced_fold(', self::body( 'render_url_audit_card' ), 'Tab③ url_audit 高级折叠迁 advanced_fold' );
			self::assertStringContainsString( 'self::test_credential_btn(', self::body( 'render_iprep_zone_check' ), 'Tab④ iprep 测试凭据迁 test_credential_btn' );
			self::assertStringContainsString( 'self::usage_card_html(', self::body( 'render_overview_usage' ), 'Tab① 调用量卡迁 usage_card_html' );
			self::assertStringContainsString( 'self::report_card(', self::body( 'render_tab_tools' ), 'Tab⑦ 存量审核报告卡迁 report_card' );
			self::assertStringContainsString( 'self::render_overview_usage(', self::body( 'render_tab_overview' ), 'Tab① 编排挂新块（调用量卡落位）' );
		}

		public function test_guards_anchor_literals_stay_at_call_sites(): void {
			// 防丢锚：既有契约 DOM/字段锚在编排函数体内原位保留（等价迁移验收线）。
			$alerts = self::body( 'render_overview_alerts' );
			self::assertStringContainsString( 'wk-overview-existing-bar', $alerts, '体检中黄条锚（id 参数字面量）' );
			self::assertStringContainsString( 'wk-overview-url-backlog-bar', $alerts, '网址核查运行黄条锚（id 参数字面量）' );
			self::assertStringContainsString( "tab_url( 'tools' )", $alerts, '看报告链接改指 Tab⑦' );
			self::assertStringContainsString( 'Labels::test_mode_word', $alerts, '演练模式词走映射表' );
			self::assertStringContainsString( '现在去关闭', $alerts, '演练通栏动作出口' );

			$captcha = self::body( 'render_tab_captcha' );
			self::assertStringContainsString( 'wk-captcha-unconfigured-notice', $captcha, 'H3-A notice 锚（id 参数字面量）' );
			self::assertMatchesRegularExpression( "/self::notice_yellow_bar[(]\s*'warn'/", $captcha, 'warn 级承载插件级类（【W3·批B 翻转】M6H3 U-1 classes 字面量位退役）' );
			self::assertSame( 6, substr_count( $captcha, 'self::notice_yellow_bar(' ), 'Tab② 黄条六条迁段（warn×2+info×2+privacy×2）' );
			self::assertSame( 4, substr_count( $captcha, "'info'," ), 'info 级四处（IP 信誉三态双位 + 两驱动 privacy 条件位——【W3·批B】privacy helper 化）' );

			$iprep = self::body( 'render_iprep_zone_check' );
			self::assertStringContainsString( 'id="wk-iprep-test-credentials"', $iprep, '测试凭据按钮锚' );
			self::assertStringContainsString( 'wp_create_nonce( self::AJAX_TEST_IPREP )', $iprep, 'nonce 随按钮注入（字面量在编排位）' );
			self::assertStringContainsString( 'id="wk-iprep-test-result"', $iprep, '三态结果位锚' );

			self::assertStringContainsString( 'id="wk-url-audit-advanced"', self::body( 'render_url_audit_card' ), '高级折叠 id 锚（M6B blockBetween 起标记）' );

			$tools = self::body( 'render_tab_tools' );
			self::assertStringContainsString( 'wk-tools-existing-audit-report', $tools, '报告卡锚' );
			self::assertStringContainsString( 'wk-report-tickets', $tools, '三票面形态字面量' );
			self::assertStringContainsString( 'wk-tools-existing-audit-reasons', $tools, '原因明细折叠锚' );
			self::assertStringContainsString( '→ 垃圾箱', $tools, 'H3-C 去向词（垃圾箱）' );
			self::assertStringContainsString( '→ 待审核', $tools, 'H3-C 去向词（待审核）' );
			self::assertStringContainsString( 'comment_status=moderated', $tools, '待审队列深链' );

			self::assertStringContainsString( 'id="wk-overview-usage"', self::body( 'usage_card_html' ), 'Tab① 调用量卡新锚（helper 壳位）' );
		}

		public function test_guards_field_names_unchanged_in_migrated_fold(): void {
			// 防丢字段：url_audit 折叠迁 advanced_fold 后字段 name 路径零变化（保存链不动）。
			$src  = self::settings_src();
			$fold = self::block_between( $src, 'id="wk-url-audit-advanced"', 'wk-url-audit-inert-notice' );
			foreach ( array(
				"array( 'url_audit', 'bocha', 'enabled' )",
				"array( 'url_audit', 'bocha', 'api_key' )",
				"array( 'url_audit', 'search_source' )",
				"array( 'url_audit', 'provider', 'mode' )",
				"array( 'url_audit', 'provider', 'api_key' )",
				"array( 'url_audit', 'fetcher', 'mode' )",
				"array( 'url_audit', 'batch_quota' )",
				"array( 'url_audit', 'incr_quota' )",
				"array( 'url_audit', 'robots_comply' )",
			) as $path ) {
				self::assertStringContainsString( $path, $fold, "字段路径零变化：{$path}" );
			}
		}

		/**
		 * 文件级两标记间提取（M6BSearchSourceTest 同款）。
		 */
		private static function block_between( string $src, string $start_marker, string $end_marker ): string {
			$start = strpos( $src, $start_marker );
			self::assertNotFalse( $start, "须含 {$start_marker}" );
			$end = strpos( $src, $end_marker, $start + strlen( $start_marker ) );
			self::assertNotFalse( $end, "须在 {$start_marker} 之后含 {$end_marker}" );
			return substr( $src, $start, $end - $start );
		}
	}
}
