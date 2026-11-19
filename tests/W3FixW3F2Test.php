<?php
/**
 * W3-FIX 批 · W3F2 续测实锤两缺陷回归门（0.8.6；手测档案
 * docs/reports/MANUAL-CARD-W3F-卸载重装全流程结果-2026-11-19.md 续测节）。
 *
 * 两缺陷契约（TDD 先红后绿）：
 *   缺陷② 向导出口白屏（状态机不闭合）——步四「进入总览」/「去文案工坊」+ 跳过两链
 *      曾生成 admin.php?action=wink_gatekeeper_wizard_* 形态 URL；admin_post_* 钩子
 *      只由 admin-post.php 派发（admin.php 派发 admin_action_*）——200/414B 空
 *      admin shell、wizard_state 不翻转。本文件以 **render 级行为测**（反射直调
 *      render_wizard 捕获输出——批 C「actions registered」注册钉只测挂钩不测 URL
 *      生成面的教训入契约）钉死改后形态：admin-post.php?action=wink_gatekeeper_
 *      wizard_{done|skip}&_wpnonce=… 恰四链 + admin.php 死链零残留。
 *   缺陷① 测试凭据徽标 chip 不更新——五态徽标（probe_state_badge）此前只有刷新后
 *      的服务端真值（probe_log N1），AJAX 探活当场零回写通道（admin-brand.js 十个
 *      探活 handler 只写消息行 span，全文件零 .test-state/.badge/.last-test 回写）；
 *      且向导页同屏存在同驱动徽标多副本（步二凭据面 + 步四上岗引导复用同一按钮/
 *      结果 id 口径——手测 8 个 .test-state 实证）。修复契约：徽标 span 带
 *      data-wk-driver-key（19 个 test_credential_btn 消费位全覆盖）+ admin-brand.js
 *      wkProbeWriteBadges 按 data-wk-driver-key 属性选择器 forEach 全量回写（不得
 *      用 getElementById 单 id 窄选择器——同屏多副本场景全量正确）+ 九个 probe log
 *      闭集驱动全接线（avatar 结果不落库语境不入回写——N1 红线）。
 *
 * 版本面：0.8.5→0.8.6 四点 + tests 全目录零 0.8.5 当前版本钉残留（批 D
 * test_zero_084_version_pins_anywhere_in_tests 同款防漏翻扫描）；
 * 【AUDIT-FIX 2026-11-20 批】四点断言翻转 0.8.6→0.8.7（断言值翻转非语义变化——
 * 0.8.6/0.8.5 历史条目断言原样保留+0.8.7 条目位次与要点断言新增）。
 *
 * 桩注记：WinkGatekeeper\Settings 命名空间桩（render 级渲染面 WP 函数——本文件
 * 首立六件，function_exists 守卫同进程共存；checked/wp_nonce_url/wp_create_nonce/
 * esc_attr_e 语义与 M6B/M6H2/M6H3/M6E 既有桩逐字同形——先载者生效纪律不破坏）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Settings {

	if ( ! function_exists( 'WinkGatekeeper\Settings\absint' ) ) {
		/**
		 * absint 桩（WP 同形：非负整数化）。
		 *
		 * @param mixed $value 原值。
		 * @return int
		 */
		function absint( $value ): int {
			return abs( (int) $value );
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\checked' ) ) {
		/**
		 * checked 桩（M6B/M6H2/M6H3 同形——返回串不回显）。
		 *
		 * @param mixed $checked 现值。
		 * @param mixed $current 期望值。
		 * @param bool  $display 输出（忽略）。
		 * @return string
		 */
		function checked( $checked, $current = true, bool $display = true ): string {
			return ( (string) $checked === (string) $current ) ? ' checked="checked"' : '';
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\esc_attr_e' ) ) {
		/**
		 * esc_attr_e 桩（M6E 同形——转义输出）。
		 *
		 * @param string $text   文本。
		 * @param string $domain 文本域（忽略）。
		 * @return void
		 */
		function esc_attr_e( string $text, string $domain = 'wink-wp-gatekeeper' ): void {
			unset( $domain );
			echo htmlspecialchars( $text, ENT_QUOTES );
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\settings_fields' ) ) {
		/**
		 * settings_fields 桩（最小 hidden 形态——render_wizard 表单头渲染面）。
		 *
		 * @param string $option_group 设置组名。
		 * @return void
		 */
		function settings_fields( string $option_group ): void {
			echo '<input type="hidden" name="option_page" value="' . $option_group . '" />';
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\wp_create_nonce' ) ) {
		/**
		 * wp_create_nonce 桩（M6B/M6H2/M6H3 同形——定值串）。
		 *
		 * @param string $action action（忽略）。
		 * @return string
		 */
		function wp_create_nonce( string $action = '' ): string {
			return 'stub-nonce';
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Settings\wp_nonce_url' ) ) {
		/**
		 * wp_nonce_url 桩（M6B/M6H2/M6H3 同形——URL 追加 &_wpnonce=stub；
		 * 渲染级 URL 形态钉依赖此定值形态）。
		 *
		 * @param string $url    URL。
		 * @param string $action action（忽略）。
		 * @return string
		 */
		function wp_nonce_url( string $url, string $action = '' ): string {
			return $url . '&_wpnonce=stub';
		}
	}
}

namespace WinkGatekeeper\Tests {

	use PHPUnit\Framework\TestCase;

	final class W3FixW3F2Test extends TestCase {

		protected function setUp(): void {
			WpOptionStub::reset();
			unset( $_GET['step'] );
		}

		protected function tearDown(): void {
			WpOptionStub::reset();
			unset( $_GET['step'] );
		}

		/* -----------------------------------------------------------------
		 * 源码提取件（花括号配平，W3BatchCTest 同形态）
		 * ----------------------------------------------------------------- */

		private static function src( string $rel ): string {
			$src = file_get_contents( __DIR__ . '/../' . $rel );
			self::assertIsString( $src, "{$rel} 必须可读" );
			return (string) $src;
		}

		private static function sp(): string {
			return self::src( 'includes/settings/SettingsPage.php' );
		}

		private static function js(): string {
			return self::src( 'assets/js/admin-brand.js' );
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
						return substr( $src, (int) $open, $i - (int) $open + 1 );
					}
				}
			}
			self::fail( "函数 {$name} 花括号不配平" );
			return '';
		}

		/**
		 * 区块截取件（首个 needle 起到次个 needle 前止——avatar 探活段落位用）。
		 *
		 * @param string $src  源码。
		 * @param string $from 首 needle。
		 * @param string $to   次 needle。
		 * @return string
		 */
		private static function block_between( string $src, string $from, string $to ): string {
			$start = strpos( $src, $from );
			self::assertNotFalse( $start, '首锚必须在场：' . $from );
			$end = strpos( $src, $to, $start );
			self::assertNotFalse( $end, '次锚必须在场：' . $to );
			return (string) substr( $src, $start, $end - $start );
		}

		/**
		 * 反射直调 render_wizard（echo 型 ob 捕获——W3BatchCTest invoke 同形态）。
		 *
		 * @return string 渲染 HTML。
		 */
		private static function render_wizard_html(): string {
			$ref = new \ReflectionMethod( \WinkGatekeeper\Settings\SettingsPage::class, 'render_wizard' );
			$ref->setAccessible( true );
			ob_start();
			$ref->invoke( null );
			return (string) ob_get_clean();
		}

		/* ================================================================
		 * 缺陷② · 向导出口链 URL 生成面（render 级行为测——W3F2 白屏实锤）
		 * ================================================================ */

		/**
		 * render_wizard 实渲染形态钉：出口/跳过四链字面=admin-post.php?action=
		 * wink_gatekeeper_wizard_{done|skip}&_wpnonce=…（stub 桩定值形态）；
		 * admin.php 死链形态零残留。改后形态即 W3F2 手测档案要求字形。
		 */
		public function test_wizard_exit_urls_render_admin_post_shape(): void {
			$html = self::render_wizard_html();

			self::assertSame(
				2,
				substr_count( $html, 'admin-post.php?action=wink_gatekeeper_wizard_done' ),
				'完成出口两链（进入总览主出口 + 去文案工坊彩蛋出口）rendered URL=admin-post.php 生成面'
			);
			self::assertSame(
				2,
				substr_count( $html, 'admin-post.php?action=wink_gatekeeper_wizard_skip' ),
				'跳过出口两链（步一 wizard-foot + 页脚 wizard-exit）rendered URL=admin-post.php 生成面'
			);
			self::assertSame(
				4,
				substr_count( $html, 'admin-post.php?action=wink_gatekeeper_wizard_' ),
				'出口/跳过四链合计恰四条 admin-post.php 形态'
			);
			self::assertStringContainsString(
				'admin-post.php?action=wink_gatekeeper_wizard_done&_wpnonce=stub',
				$html,
				'主出口带 nonce 定值形态（wp_nonce_url 配对）'
			);
			self::assertStringContainsString(
				'admin-post.php?action=wink_gatekeeper_wizard_done&to=copy&_wpnonce=stub',
				$html,
				'去文案工坊彩蛋出口 to=copy 白名单参数随链'
			);
			self::assertStringNotContainsString(
				'admin.php?action=wink_gatekeeper_wizard',
				$html,
				'admin.php 向导出口死链零残留（W3F2 实测 200/414B 空 admin shell 根因）'
			);
		}

		/* ================================================================
		 * 缺陷① · 徽标回写（PHP 属性面 + render 级多副本实况 + JS 全量回写面）
		 * ================================================================ */

		/**
		 * render 级多副本实况钉：默认树（captcha.driver=aliyun 出厂默认）下向导页
		 * 恰渲染 8 个带键徽标——步二 aliyun + abuseipdb 折叠卡 + 步三五驱动 +
		 * 步四上岗引导（同驱动第二副本=手测「8 个 .test-state 实例（含隐藏副本）」
		 * 实证面）。回写面必须按 driver-key 全量命中才对此场景正确。
		 */
		public function test_wizard_page_renders_eight_driver_key_badges(): void {
			$html = self::render_wizard_html();

			self::assertSame(
				8,
				substr_count( $html, 'data-wk-driver-key=' ),
				'向导页 8 个 test_credential_btn 徽标全带 driver-key（19 消费位的向导 8 渲染位）'
			);
			self::assertSame(
				2,
				substr_count( $html, 'data-wk-driver-key="aliyun"' ),
				'同屏多副本实况：步二凭据面 + 步四上岗引导两份 aliyun 徽标（重复 id 场景——回写必须全量）'
			);
			foreach ( array( 'abuseipdb', 'baidu', 'deepseek', 'aliyun_green', 'tencent_tms', 'openai_custom' ) as $key ) {
				self::assertSame(
					1,
					substr_count( $html, 'data-wk-driver-key="' . $key . '"' ),
					"驱动 {$key} 徽标带键恰一份"
				);
			}
			self::assertMatchesRegularExpression(
				'/<span class="test-state" data-wk-driver-key="aliyun">/',
				$html,
				'属性钉在 .test-state span 本体（回写定位面）'
			);
		}

		/**
		 * PHP 属性面 source-scan：probe_state_badge 接 driver_key 形参发射
		 * data-wk-driver-key；test_credential_btn 把调用方 driver_key 透传徽标。
		 */
		public function test_probe_badge_carries_driver_key_attr(): void {
			$badge = self::function_body( self::sp(), 'probe_state_badge' );
			self::assertStringContainsString( 'data-wk-driver-key', $badge, '五态徽标 span 发射 data-wk-driver-key（回写定位面）' );
			self::assertStringContainsString( "esc_attr( \$driver_key )", $badge, 'driver_key 经 esc_attr 转义发射' );

			$btn = self::function_body( self::sp(), 'test_credential_btn' );
			self::assertStringContainsString(
				'probe_state_badge( $state, $last_tested, $driver_key )',
				$btn,
				'test_credential_btn 透传 driver_key 给徽标（19 消费位全覆盖）'
			);
		}

		/**
		 * JS 全量回写面 source-scan：wkProbeWriteBadges 按 data-wk-driver-key 属性
		 * 选择器 forEach 批量命中——禁 getElementById 单 id 窄选择器（缺陷②根因面
		 * 反钉）；五态词/徽标类与 PHP probe_state_badge 闭集双写同源。
		 */
		public function test_admin_brand_js_badge_writeback_bulk_by_driver_key(): void {
			$js   = self::js();
			$body = self::function_body( $js, 'wkProbeWriteBadges' );

			self::assertStringContainsString(
				"querySelectorAll( '[data-wk-driver-key=\"' + driverKey + '\"]' )",
				$body,
				'回写按 data-wk-driver-key 属性选择器批量命中（同屏多副本全量正确）'
			);
			self::assertStringContainsString( 'forEach', $body, 'forEach 全量回写（不做 first-hit 即返）' );
			self::assertStringContainsString( "querySelector( '.badge' )", $body, '回写命中徽标 chip 本体' );
			self::assertStringContainsString( "querySelector( '.last-test' )", $body, '回写命中「上次测试」小字位' );
			self::assertStringNotContainsString(
				'getElementById',
				$body,
				'回写零 getElementById 窄选择器（缺陷①「命中不可见副本」根因反钉）'
			);

			// 五态词/徽标类双写同源（PHP probe_state_badge 闭集——两处改动须同批同步）。
			foreach ( array( 'Connected · credentials work', 'Incomplete · required fields missing', 'Rejected · key error', 'Contract mismatch · status unknown' ) as $word ) {
				self::assertStringContainsString( $word, $js, "五态词 {$word} 与服务端同源" );
			}
			foreach ( array( 'b-ok', 'b-warn', 'b-err', 'b-dim' ) as $cls ) {
				self::assertStringContainsString( "'" . $cls . "'", $js, "徽标类 {$cls} 闭集在场" );
			}
			self::assertStringContainsString( 'Last test: ', $js, '「上次测试」小字位回写文案' );
		}

		/**
		 * JS 接线闭集钉：九个 probe log 闭集驱动各恰一次回写调用（driver_key 字面
		 * 与 PROBE_LOG_DRIVERS 闭集一致）；avatar 探活段零回写调用（N1「结果不落库」
		 * 语境——ok/slow/fail 态在五态闭集外，徽标保持服务端「未测试」真值）。
		 */
		public function test_admin_brand_js_wires_nine_probe_log_drivers(): void {
			$js = self::js();
			foreach ( array(
				'tencent',
				'aliyun',
				'tencent_tms',
				'aliyun_green',
				'baidu',
				'deepseek',
				'openai_custom',
				'url_audit_provider',
				'abuseipdb',
			) as $key ) {
				self::assertStringContainsString(
					"wkProbeWriteBadges( '" . $key . "'",
					$js,
					"闭集驱动 {$key} 探活响应接线徽标回写"
				);
			}
			self::assertSame(
				9,
				substr_count( $js, "wkProbeWriteBadges( '" ),
				'回写调用恰九次（PROBE_LOG_DRIVERS 闭集——多即越界少即漏接）'
			);

			$avatar = self::block_between(
				$js,
				"var avatarProbeButton = document.getElementById( 'wk-avatar-test-btn' )",
				'// 字段有输入即摘除标红'
			);
			self::assertStringNotContainsString(
				'wkProbeWriteBadges',
				$avatar,
				'avatar 探活不入徽标回写（结果不落库语境——ok/slow/fail 五态闭集外，N1 红线）'
			);
		}

		/* ================================================================
		 * 版本面 · 0.8.6 进位（四点 + 零 0.8.5 当前版本钉残留扫描）
		 * ================================================================ */

		public function test_version_four_points_0_8_6(): void {
			$entry  = self::src( 'wink-wp-gatekeeper.php' );
			$readme = self::src( 'readme.txt' );

			// 【AUDIT-FIX 2026-11-20 批】四点随批进位 0.8.6→0.8.7（十件轻量修复批——0.8.6 收口在前；
			// 本测试名保留 0_8_6 历史锚——断言值翻转非语义变化，M5FixAssetVerTest -w3fix1 翻转先例）。
			// 【i18n 批 I-4a】四点随批进位 0.8.7→0.9.0（milestone 位：i18n 全量英文化收口——0.8.7 收口在前）。
			self::assertMatchesRegularExpression( '/^ \* Version:\s+0\.9\.0$/m', $entry, '四点①插件头 Version 0.9.0（i18n 批 I-4a 版本进位）' );
			self::assertStringContainsString( "define( 'WINK_GATEKEEPER_VERSION', '0.9.0' )", $entry, '四点②版本常量 0.9.0（i18n 批 I-4a 版本进位）' );
			self::assertMatchesRegularExpression( '/^Stable tag: 0\.9\.0$/m', $readme, '四点③readme Stable tag 0.9.0（i18n 批 I-4a 版本进位）' );
			self::assertStringContainsString( '= 0.9.0 =', $readme, '四点④changelog 当前条目 0.9.0（i18n 批 I-4a 版本进位——AUDIT-FIX 十件收口在前）' );

			// 0.8.5/0.8.6 条目还原保留（历史条目不删——readme changelog 只增不删纪律）。
			self::assertStringContainsString( '= 0.8.5 =', $readme, '0.8.5 历史条目保留' );
			self::assertStringContainsString( '= 0.8.6 =', $readme, '0.8.6 历史条目保留（W3-FIX 两缺陷）' );
			self::assertLessThan(
				strpos( $readme, '= 0.8.5 =' ),
				(int) strpos( $readme, '= 0.8.6 =' ),
				'0.8.6 条目位次在 0.8.5 之前（Changelog 倒序纪律）'
			);
			self::assertLessThan(
				strpos( $readme, '= 0.8.6 =' ),
				(int) strpos( $readme, '= 0.8.7 =' ),
				'0.8.7 条目位次在 0.8.6 之前（AUDIT-FIX 批新条目——倒序纪律）'
			);

			// 0.8.6 条目两缺陷要点在场（人话——手测档案缺陷①②；历史条目内容不随进位变化）。
			$entry086 = self::block_between( $readme, '= 0.8.6 =', '= 0.8.5 =' );
			self::assertStringContainsString( '向导', $entry086, '0.8.6 条目要点：向导出口链修复' );
			self::assertStringContainsString( '测试凭据', $entry086, '0.8.6 条目要点：测试凭据徽标回写修复（readme zh 锚）' );

			// 0.8.7 条目十件要点在场（人话——独立审计轻量批口径）。
			$entry087 = self::block_between( $readme, '= 0.8.7 =', '= 0.8.6 =' );
			self::assertStringContainsString( '审计', $entry087, '0.8.7 条目要点：独立审计修复批' );
			self::assertStringContainsString( '黄条', $entry087, '0.8.7 条目要点：CDN 代理头告警' );
			self::assertStringContainsString( '无 breaking', $entry087, '0.8.7 条目要点：breaking 注（纵深加固+行为收紧两处如实注明）' );
		}

		/**
		 * tests 全目录零 0.8.5「当前版本钉」残留（批 D 防漏翻扫描同款——四类检索串
		 * 拼接成型防本文件自匹配；历史性提及〔= 0.8.5 = 条目/block_between/armed
		 * breaking 注〕不在此四类字形内=不受影响）。
		 */
		public function test_zero_085_current_version_pins_anywhere_in_tests(): void {
			$n_const  = 'WINK_GATEKEEPER_VERSION' . "', '0.8" . ".5'";
			$n_head   = 'Version:\s+0' . '\.8' . '\.5';
			$n_stable = 'Stable tag: 0' . '\.8' . '\.5';
			$n_plain  = 'assertStringContainsString( ' . "'0.8" . ".5'";
			$n_eq     = 'assertStringContainsString( ' . "'= 0.8" . ".5 ='";
			$dir      = dirname( __DIR__ ) . '/tests';
			$files    = glob( $dir . '/*.php' );
			$residue  = array();
			foreach ( $files as $file ) {
				if ( 'W3FixW3F2Test.php' === basename( (string) $file ) ) {
					continue; // 本文件自带反钉字面量（needle 定义面）——非翻转残留面。
				}
				$src = (string) file_get_contents( $file );
				if ( str_contains( $src, $n_const )
					|| str_contains( $src, $n_head )
					|| str_contains( $src, $n_stable )
					|| str_contains( $src, $n_plain )
					|| str_contains( $src, $n_eq ) ) {
					$residue[] = basename( (string) $file );
				}
			}
			self::assertSame( array(), $residue, 'tests 全目录零 0.8.5 当前版本钉残留。残留：' . implode( ', ', $residue ) );
		}
	}
}
