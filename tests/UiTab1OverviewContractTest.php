<?php
/**
 * UI 批 3·Tab① 总览页点名册形态契约测试（2026-11-12，DESIGN-UI-tab1 B 提案五块 + 统筹裁决）。
 *
 * 骨架契约面（源码契约测试，纯 PHP 零 WP 依赖——方法论与 UiTab6StatsContractTest 同一形态；
 * 行为面由真机 DOM 断言承载）：
 *   ① 站务提醒通栏 → ② 今日战报 → ③ 服务商点名册 → ④ 最近岔子 → ⑤ 站点底账
 *     ——五块各带独立渲染函数与 DOM 锚 id（wk-overview-alerts/report/roster/incidents/footer）；
 *   ② 分层纪律：Tab① 只看今天、每数字一句人话、无表格化明细（明细归 Tab⑥）；
 *   ③ 术语纪律：点名册直白命名（不用保安比喻）、系统层叫「服务商」、全走 Labels 映射；
 *   ④ 点名册=渲染侧推导（OverviewRoster 纯函数——registry×配置树×DriverHealth×Usage，零存储变更）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;

final class UiTab1OverviewContractTest extends TestCase {

	private static function source( string $rel ): string {
		$src = file_get_contents( __DIR__ . '/../' . $rel );
		self::assertIsString( $src, "{$rel} 必须可读" );
		return (string) $src;
	}

	private static function settings_src(): string {
		return self::source( 'includes/settings/SettingsPage.php' );
	}

	/**
	 * 提取具名函数/方法体（花括号配平）——契约断言按作用域收窄，防全局误命中。
	 */
	private static function functionBody( string $src, string $name ): string {
		$start = strpos( $src, 'function ' . $name );
		self::assertNotFalse( $start, "函数 {$name} 必须存在" );
		$open  = strpos( $src, '{', $start );
		$depth = 0;
		$len   = strlen( $src );
		for ( $i = $open; $i < $len; $i++ ) {
			if ( '{' === $src[ $i ] ) {
				$depth++;
			} elseif ( '}' === $src[ $i ] ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $src, $open, $i - $open + 1 );
				}
			}
		}
		self::fail( "函数 {$name} 花括号不配平" );
		return '';
	}

	private static function body( string $name ): string {
		return self::functionBody( self::settings_src(), $name );
	}

	/* ---------- ① 五块骨架：编排序 + DOM 锚 ---------- */

	public function test_five_blocks_rendered_in_order_with_anchors(): void {
		$main = self::body( 'render_tab_overview' );

		$markers = array(
			'render_overview_alerts',
			'render_overview_report',
			'render_overview_roster',
			'render_overview_incidents',
			'render_overview_footer',
		);
		$last = -1;
		foreach ( $markers as $marker ) {
			$this->assertStringContainsString( 'self::' . $marker . '(', $main, "五块编排须调用 {$marker}" );
			$pos = strpos( $main, 'self::' . $marker . '(' );
			$this->assertGreaterThan( $last, $pos, "{$marker} 必须按 B 提案顺序出现" );
			$last = $pos;
		}
	}

	public function test_block_anchor_ids_present(): void {
		$this->assertStringContainsString( 'id="wk-overview-alerts"', self::body( 'render_overview_alerts' ) );
		$this->assertStringContainsString( 'id="wk-overview-report"', self::body( 'render_overview_report' ) );
		$this->assertStringContainsString( 'id="wk-overview-roster"', self::body( 'render_overview_roster' ) );
		$this->assertStringContainsString( 'id="wk-overview-incidents"', self::body( 'render_overview_incidents' ) );
		$this->assertStringContainsString( 'id="wk-overview-footer"', self::body( 'render_overview_footer' ) );
	}

	/* ---------- 块 A：站务提醒通栏（原卡内黄条迁出） ---------- */

	public function test_alerts_strip_contents(): void {
		$body = self::body( 'render_overview_alerts' );
		$this->assertStringContainsString( 'TestMode::wp_active', $body, '演练模式通栏判定（wp_active 含 24h 懒过期）' );
		$this->assertStringContainsString( 'Labels::test_mode_word', $body, '演练模式词走映射表' );
		$this->assertStringContainsString( '现在去关闭', $body, '演练通栏带关闭动作链接' );
		$this->assertStringContainsString( "tab_url( 'captcha' )", $body, '关闭/验证码兜底跳 Tab②' );
		$this->assertStringContainsString( '统计计数已停用', $body, '统计停用提示从卡内迁出为通栏（FIX-δ）' );
		$this->assertStringContainsString( '人机验证还没选服务商', $body, '验证码域兜底新黄条（B-1——对齐审核域兜底）' );
		$this->assertStringContainsString( '还没配好任何一家审核服务商', $body, '审核域兜底黄条迁位保留' );
		$this->assertStringContainsString( "tab_url( 'review' )", $body, '审核兜底跳 Tab③' );
	}

	public function test_review_fallback_counts_usable_drivers(): void {
		$this->assertStringContainsString( 'chain_usable_count', self::body( 'render_overview_alerts' ), '审核兜底判定走链上可用计数' );
		$helper = self::body( 'chain_usable_count' );
		$this->assertStringContainsString( 'missing_fields', $helper, '必填缺失判定单一来源（DriverConfigSpec）' );
		$this->assertStringContainsString( "by_type( 'review' )", $helper, 'registry 为元数据来源' );
	}

	/* ---------- 块 B：今日战报（三闸小票 + 行动行） ---------- */

	public function test_report_block_skeleton(): void {
		$body = self::body( 'render_overview_report' );
		$this->assertStringContainsString( '今日战报', $body );
		$this->assertStringContainsString( '评论闸', $body, '三闸小票：闸字保留为分组名（裁决 1）' );
		$this->assertStringContainsString( '登录闸', $body );
		$this->assertStringContainsString( '注册·找回闸', $body );
		$this->assertStringContainsString( 'ticket_main', $body, '小票主行走纯函数（0=平静的一天显式空态——OverviewRosterTest 钉）' );
		$this->assertStringContainsString( '密码输错', $body, '登录小票副行（对照表 6）' );
		$this->assertStringContainsString( '统计已停用，今日没有记账', $body, '统计停用三票换灰字（B 提案块 B）' );
		$this->assertStringContainsString( 'gate_counts', $body, '三闸计数走 InterceptDigest 读取面' );
	}

	public function test_report_subline_wired_to_n4(): void {
		$body = self::body( 'render_overview_report' );
		$this->assertStringContainsString( 'today_reject_distribution', $body, 'N4 战报副行数据源（批 2 喂数）' );
		$this->assertStringContainsString( 'ticket_subline', $body, '副行组装走纯函数（人话族映射）' );
		$this->assertStringContainsString( "today_reject_distribution( 'comment' )", $body, '副行口径=评论门 reject 分布' );
	}

	public function test_report_action_rows(): void {
		$body = self::body( 'render_overview_report' );
		$this->assertStringContainsString( 'wp_count_comments', $body, '待审实数=W3/N8 口径（WP 待审队列全量）' );
		$this->assertStringContainsString( 'Labels::pending_word', $body, '待你过目走映射表（对照表 B-10）' );
		$this->assertStringContainsString( 'comment_status=moderated', $body, '去处理直达待审队列（docs 03 原承诺）' );
		$this->assertStringContainsString( 'review_split', $body, 'IP 信誉强制验证码单列口径（裁决 2——不入待审数字）' );
		$this->assertStringContainsString( 'archived_spam_count', $body, '垃圾箱留档量（F14）' );
		$this->assertStringContainsString( 'spam_warn', $body, '≥500 黄条升级（阈值沿用）' );
		$this->assertStringContainsString( 'Labels::spam_word', $body, '垃圾箱存档走映射表（对照表 B-16）' );
		$this->assertStringContainsString( 'comment_status=spam', $body, '去看看直达垃圾评论分类' );
	}

	/* ---------- 块 C：服务商点名册（渲染侧推导核心） ---------- */

	public function test_roster_block_skeleton(): void {
		$body = self::body( 'render_overview_roster' );
		$this->assertStringContainsString( '服务商点名册', $body, '直白命名——不用保安比喻（裁决 1）' );
		$this->assertStringContainsString( 'overview_roster_cards', $body, '点名册数据走边界收集器' );
		$this->assertStringContainsString( 'render_roster_card', $body, '单卡渲染函数' );
		$this->assertStringContainsString( 'Labels::local_rules_footnote', $body, '本地规则不入册给脚注（对照表 B-7）' );
		$this->assertStringContainsString( 'Labels::pass_uncounted_note', $body, '推算口径注（对照表 B-9）' );
	}

	/**
	 * UI-FIX A（2026-11-12 用户实测反馈折中方案）：点名册默认只展开已配置卡
	 * （当班+待命，含其上异常卡）；未配置服务商收拢一行——「未配置的服务商：
	 * 人话名 · 人话名 [去配置 →]」，单入口替代每卡一入口。
	 */
	public function test_roster_unconfigured_collapsed_row(): void {
		$roster = self::body( 'render_overview_roster' );
		$this->assertStringContainsString( '未配置的服务商', $roster, '收拢行文案（反馈 1 拍板形态）' );
		$this->assertStringContainsString( 'wk-roster-unconfigured', $roster, '收拢行样式类（CSS 承载——不内联）' );
		$this->assertStringContainsString( '去配置 →', $roster, '收拢行单入口去处（保留可发现性）' );
		$this->assertStringContainsString( "'unconfigured' === ", $roster, '展开/收拢分流按排班态（OverviewRoster::cards 的 duty）' );
		$this->assertStringContainsString( 'array_unique', $roster, '单入口跳转目标=未配置卡 config_tab 去重（全同域链对应 Tab，混合回落 Tab② 三步引导）' );
		$this->assertStringContainsString( 'implode', $roster, '收拢行服务商名册组装（人话 label 名单——Labels 体系）' );

		$card = self::body( 'render_roster_card' );
		$this->assertStringNotContainsString( '去配置', $card, '卡内去配置入口退役（单入口取代——反馈 1「不再每卡一入口」）' );
		$this->assertStringNotContainsString( 'wk-roster-card--disabled', $roster . $card, '未配置灰卡不再单独渲染（收拢行取代）' );
	}

	public function test_roster_cards_collector_derives_from_registry(): void {
		$collector = self::body( 'overview_roster_cards' );
		$this->assertStringContainsString( "array( 'captcha', 'review' )", $collector, 'registry 两域全集为骨架（A1 根因消除点——验证码+云审）' );
		$this->assertStringContainsString( 'by_type( $driver_type )', $collector, '遍历源=registry 全集（原 chain 单驱动遍历退役）' );
		$this->assertStringContainsString( '\'local_rules\' === $driver_id', $collector, '本地驱动不入册（脚注交代）' );
		$this->assertStringContainsString( 'DriverHealth::state_today', $collector, '健康态只读消费（不改存储——N9 不采纳）' );
		$this->assertStringContainsString( 'Usage::daily_total_for', $collector, '今日调用数（F15）' );
		$this->assertStringContainsString( 'OverviewRoster::cards', $collector, '推导收口在纯函数（单测钉矩阵）' );

		$render = self::body( 'render_roster_card' );
		$this->assertStringContainsString( 'duty_word', $render, '排班态词（OverviewRoster 组装时走 Labels，OverviewRosterTest 钉）' );
		$this->assertStringContainsString( 'health_word', $render, '健康态词（正常/故障/额度用完）' );
		$this->assertStringContainsString( 'halt_note', $render, '停转原因括号注' );
		$this->assertStringContainsString( 'wk-dot', $render, '健康状态点（绿/黄/红）' );
	}

	/* ---------- 块 D：最近岔子（N2∪N3 批 2 谓词复用） ---------- */

	public function test_incidents_block_wired_to_batch2_queries(): void {
		$body = self::body( 'render_overview_incidents' );
		$this->assertStringContainsString( 'recent_faults', $body, 'N2 全闸故障时间线（批 2 查询复用）' );
		$this->assertStringContainsString( 'recent_health_timeline', $body, 'N3 停转进入·解除时间线（批 2 查询复用）' );
		$this->assertStringContainsString( 'fault_timeline_rows', $body, '批 2 行组装复用（id 倒序归并）' );
		$this->assertStringContainsString( 'incident_lines', $body, 'Tab① 紧凑行组装（OverviewRoster 纯函数）' );
		$this->assertStringContainsString( '风平浪静', $body, '空态显式人话（B-5）' );
		$this->assertStringContainsString( "tab_url( 'stats' )", $body, '完整记录入口指向 Tab⑥' );
		$this->assertStringContainsString( '最近岔子', $body );
	}

	/* ---------- 块 E：站点底账（一行灰字） ---------- */

	public function test_footer_block_contents(): void {
		$body = self::body( 'render_overview_footer' );
		$this->assertStringContainsString( '统计计数', $body );
		$this->assertStringContainsString( 'Labels::test_mode_word', $body, '演练模式态走映射表词' );
		$this->assertStringContainsString( 'EventsTable::row_count', $body, '事件表容量读数（N11——C 建议替代无指标项「事件记录正常」）' );
		$this->assertStringContainsString( 'MAX_ROWS', $body, '容量上限读数' );
		$this->assertStringContainsString( '三步配好', $body, '首次使用引导挂底账行尾（docs 03 原承诺）' );
		$this->assertStringNotContainsString( '事件记录正常', $body, '无指标项退役（agent C B-6 建议）' );
	}

	public function test_row_count_boundary_method_exists(): void {
		$src = self::source( 'includes/stats/EventsTable.php' );
		$this->assertStringContainsString( 'public static function row_count()', $src, 'N11 容量读数边界方法（只读 COUNT）' );
		$body = self::functionBody( $src, 'row_count' );
		$this->assertStringContainsString( 'SELECT COUNT(*)', $body );
		$this->assertDoesNotMatchRegularExpression( '/INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE/i', $body, '只读契约' );
	}

	/* ---------- 分层与术语纪律（反钉） ---------- */

	public function test_layering_no_detail_table_in_overview(): void {
		$main = self::body( 'render_tab_overview' )
			. self::body( 'render_overview_report' )
			. self::body( 'render_overview_roster' )
			. self::body( 'render_overview_incidents' );
		$this->assertStringNotContainsString( '<table', $main, 'Tab① 无表格化明细（明细归 Tab⑥——分层定界）' );
		$this->assertStringNotContainsString( 'wk-table', $main );
	}

	public function test_legacy_placeholder_cards_retired(): void {
		$main = self::body( 'render_tab_overview' );
		$this->assertStringNotContainsString( '待审队列', $main, '占位卡退役（块 B 行动行取代）' );
		$this->assertStringNotContainsString( '事件流预览', $main, '占位卡退役（块 D 取代）' );
		$this->assertStringNotContainsString( '驱动健康', $main, '旧健康表退役（点名册取代）' );
		$this->assertStringNotContainsString( 'render_driver_health_rows', self::settings_src(), '旧健康行渲染函数退役（registry 全集推导取代）' );
		$this->assertStringNotContainsString( '暂无数据（pass 默认不计数）', $main, '黑话空态退役（对照表 B-9）' );
	}

	public function test_no_guard_metaphor_in_overview(): void {
		$bodies = self::body( 'render_tab_overview' )
			. self::body( 'render_overview_alerts' )
			. self::body( 'render_overview_report' )
			. self::body( 'render_overview_roster' )
			. self::body( 'render_overview_incidents' )
			. self::body( 'render_overview_footer' );
		$this->assertStringNotContainsString( '保安', $bodies, '点名册不用保安比喻（统筹裁决 1）' );
		$this->assertStringNotContainsString( '当日停转', $bodies, '停转黑话退役（对照表 B-4）' );
		$this->assertStringNotContainsString( '未装配', $bodies, '未装配黑话退役（对照表 B-5）' );
		$this->assertStringNotContainsString( '本地规则无外部故障面', $bodies, '恒正常黑话退役（对照表 B-7）' );
	}

	/* ---------- 版本 0.4.5（随批次推进同步——I1 CC 防线批 M3-IP 定稿 §8） ---------- */

	public function test_version_bumped_to_0_4_2_in_four_places(): void {
		$entry  = self::source( 'wink-wp-gatekeeper.php' );
		$readme = self::source( 'readme.txt' );
		$this->assertMatchesRegularExpression( '/^ \* Version:\s+0\.4\.5$/m', $entry, '插件头 Version 0.4.5' );
		$this->assertStringContainsString( "define( 'WINK_GATEKEEPER_VERSION', '0.4.5' )", $entry, '版本常量 0.4.5' );
		$this->assertMatchesRegularExpression( '/^Stable tag: 0\.4\.5$/m', $readme, 'readme Stable tag 0.4.5' );
		$this->assertStringContainsString( '= 0.4.5 =', $readme, 'Changelog 0.4.5 条目' );
	}
}
