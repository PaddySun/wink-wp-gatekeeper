<?php
/**
 * UI 批 1·Tab⑥ 统计页五段重构契约测试（2026-11-12，DESIGN-UI-tab6 A 提案 + 统筹裁决）。
 *
 * 骨架契约面（源码契约测试，纯 PHP 零 WP 依赖——方法论与 Fix3Tab3ToggleContractTest
 * 同一形态；行为面由真机 DOM 断言承载）：
 *   ① 速览条 → 一、评论防护 → 二、登录防护 → 三、报错历史 → 四、拦截记录流水 → 五、统计设置·清零
 *     ——五段各带独立渲染函数与 DOM 锚 id（wk-stats-overview/comment/login/faults/records/settings）；
 *   ② 驱动统一卡模板复用（评论段与登录段共用 render_driver_card）；
 *   ③ 待审排除 IP 风控口径（review_split 消费 + IP 信誉强制验证码单列行）；
 *   ④ 术语黑话清理（P4-P12：开发者备忘删除/roadmap 去/开关归位/故障卡归属/重置文案/明细占位）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;

final class UiTab6StatsContractTest extends TestCase {

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

	/* ---------- ① 五段骨架：编排序 + DOM 锚 ---------- */

	public function test_five_sections_rendered_in_order_with_anchors(): void {
		$main = self::body( 'render_tab_stats' );

		$markers = array(
			'render_stats_overview_strip',
			'render_stats_comment_section',
			'render_stats_login_section',
			'render_stats_fault_section',
			'render_stats_records_card',
			'render_stats_settings_card',
		);
		$last = -1;
		foreach ( $markers as $marker ) {
			$this->assertStringContainsString( 'self::' . $marker . '(', $main, "五段编排须调用 {$marker}" );
			$pos = strpos( $main, 'self::' . $marker . '(' );
			$this->assertGreaterThan( $last, $pos, "{$marker} 必须按 A 提案顺序出现" );
			$last = $pos;
		}
	}

	public function test_section_anchor_ids_present(): void {
		$this->assertStringContainsString( 'id="wk-stats-overview"', self::body( 'render_stats_overview_strip' ) );
		$this->assertStringContainsString( 'id="wk-stats-comment"', self::body( 'render_stats_comment_section' ) );
		$this->assertStringContainsString( 'id="wk-stats-login"', self::body( 'render_stats_login_section' ) );
		$this->assertStringContainsString( 'id="wk-stats-faults"', self::body( 'render_stats_fault_section' ) );
		$this->assertStringContainsString( 'id="wk-stats-records"', self::body( 'render_stats_records_card' ) );
		$this->assertStringContainsString( 'id="wk-stats-settings"', self::body( 'render_stats_settings_card' ) );
	}

	/* ---------- ② 评论防护段（口径一：概览→谁拦的→最近明细） ---------- */

	public function test_comment_section_skeleton(): void {
		$body = self::body( 'render_stats_comment_section' );
		$this->assertStringContainsString( '一、评论防护', $body );
		$this->assertStringContainsString( '转人工复核', $body, '待审→转人工复核（对照表 4）' );
		$this->assertStringContainsString( '进了待审区，等你处理', $body, '首次出现处带括号注（A §4 对照表备注）' );
		$this->assertStringContainsString( 'IP 信誉强制验证码', $body, 'IP 风控单列一行（统筹裁决 2，不入待审数字）' );
		// ② 谁拦的：本地规则卡 + 两道防线分组（按拦截链排序）。
		$this->assertStringContainsString( '谁拦的', $body );
		$this->assertStringContainsString( '中文检查，不花钱', $body, '本地规则卡描述（标签本体来自 local_rules_card 纯函数）' );
		$this->assertStringContainsString( '人机验证防线', $body );
		$this->assertStringContainsString( '云端审核防线', $body );
		// ③ 最近拦下的评论：明细表迁位本段（P3），列头人话化在表渲染函数内（P4）。
		$this->assertStringContainsString( '最近拦下的评论', $body );
		$this->assertStringContainsString( 'render_comment_recent_table', $body );
		$table = self::body( 'render_comment_recent_table' );
		$this->assertStringContainsString( '哪道防线拦的', $table, '「阶段」列头人话化（对照表 8/12）' );
		$this->assertStringContainsString( '说了什么', $table, '「正文摘要」列头人话化' );
		$this->assertStringContainsString( '原因', $table, '「命中规则」列头人话化' );
		$this->assertStringContainsString( '机器人试探', $table, 'stub 注人话（对照表 12）' );
		$this->assertStringContainsString( 'Labels::stage_word', $table, '防线名走映射表' );
		$this->assertStringContainsString( 'Labels::rule_hit_word', $table, '原因走映射表' );
	}

	public function test_pending_review_exclusion_wired_in_orchestrator(): void {
		$main = self::body( 'render_tab_stats' );
		$this->assertStringContainsString( 'review_split', $main, '待审数字走排除口径纯函数（统筹裁决 2）' );
		$this->assertStringContainsString( "by_type( 'ip' )", $main, 'IP 类服务商 id 来自 registry（现有统计树可推导）' );
		$this->assertStringContainsString( 'last_reset', $main, '累计窗口标注数据源（P9）' );
	}

	public function test_comment_section_renders_local_rules_card_and_driver_cards(): void {
		$body = self::body( 'render_stats_comment_section' );
		$this->assertStringContainsString( 'local_rules_card', $body, '本地规则三数式卡（A-3：无「检查 N 条」）' );
		$this->assertStringContainsString( 'render_driver_card', $body, '驱动统一卡模板复用' );
		$this->assertStringNotContainsString( '今日检查', $body, '「今日检查 N 条」无数据源，砍成三数式（agent C A-3）' );
	}

	/* ---------- ③ 登录防护段（口径二：概览→三张门→服务商口径→明细占位） ---------- */

	public function test_login_section_skeleton(): void {
		$body = self::body( 'render_stats_login_section' );
		$this->assertStringContainsString( '二、登录防护', $body );
		$this->assertStringContainsString( '三张门', $body, '副标题保留三张门语境（D5）' );
		$this->assertStringContainsString( '三张门分账', $body );
		$this->assertStringContainsString( 'account_gate_rows', $body, '三张门分账数据源（门名走 Labels::gate_word）' );
		$this->assertStringContainsString( 'Labels::gate_word', $body, '门名映射资产消费（对照表 23）' );
		$this->assertStringContainsString( '密码输错', $body, '登录失败尝试→密码输错（对照表 6）' );
		$this->assertStringContainsString( '验证放行', $body, '通过→验证放行（对照表 2）' );
		$this->assertStringContainsString( 'window_minutes', $body, '窗口标注随配置动态（D3）' );
		$this->assertStringContainsString( 'stats_driver_cards', $body, '登录段服务商口径卡' );
		$this->assertStringContainsString( 'render_driver_card', $body, '登录段复用统一卡模板' );
		$this->assertStringContainsString( 'render_account_recent_table', $body, '④明细表接线（批 2 N1——占位卡退役）' );
		$this->assertStringNotContainsString( '明细接入后启用', $body, '批 1 占位文案随接线移除' );
	}

	/**
	 * UI 批 2·接线一：登录④「最近拦下的登录尝试」明细表（N1 数据面）。
	 */
	public function test_login_detail_table_wired_to_n1_query(): void {
		$table = self::body( 'render_account_recent_table' );
		$this->assertStringContainsString( 'recent_account_rejects', $table, 'N1 查询消费' );
		$this->assertStringContainsString( 'account_reject_rows', $table, '行组装纯函数消费（门名/防线/原因/归因映射）' );
		$this->assertStringContainsString( '哪张门', $table, '列头：门名' );
		$this->assertStringContainsString( '为什么拦', $table, '列头：原因人话' );
		$this->assertStringContainsString( '不显示账号与 IP', $table, 'PIPL 最小化注（无明文账号/IP 哈希省略）' );
		$this->assertStringContainsString( '最近没拦过任何登录尝试', $table, '空态人话' );
		$this->assertStringNotContainsString( 'ip_hash', $table, 'IP 哈希不进明细卡渲染' );
	}

	public function test_login_section_wording_cleanup(): void {
		$body = self::body( 'render_stats_login_section' );
		$this->assertStringNotContainsString( '调用', $body, '「调用」黑话退役（对照表 1）' );
		$this->assertStringNotContainsString( '登录失败尝试', $body );
		$this->assertStringNotContainsString( '账户保护', $body, '更名登录防护（D5）' );
	}

	/**
	 * UI 批 2·接线三：登录卡「出错」格（N7a 分域——事件表三闸过滤聚合）+ 评论卡出错格
	 * 补 captcha 远端故障（N6——fault 口径按驱动聚合取代 by_driver.error 单键）。
	 */
	public function test_driver_cards_error_cells_wired_to_fault_by_driver(): void {
		$cards = self::body( 'stats_driver_cards' );
		$this->assertStringContainsString( 'today_fault_by_driver', $cards, 'N6/N7a 查询消费（出错格数据源）' );
		$this->assertStringContainsString( 'ACCOUNT_GATES', $cards, '登录卡分域过滤（三闸）' );
		$this->assertStringNotContainsString( '批 2 接线——格隐藏', $cards, '批 1 待接线注记移除' );
		$this->assertStringNotContainsString( "'errors'     => null,", $cards, '登录卡出错格不再以 null 隐藏' );
	}

	/* ---------- ④ 报错历史段（口径三：今日出错 + 人话分布 + 报错表迁入） ---------- */

	public function test_fault_section_skeleton(): void {
		$body = self::body( 'render_stats_fault_section' );
		$this->assertStringContainsString( '三、报错历史', $body );
		$this->assertStringContainsString( '今日出错', $body, '今日故障→今日出错（对照表 5）' );
		$this->assertStringContainsString( 'Labels::rule_hit_word', $body, '分布键名人话映射（N5 消费）' );
		$this->assertStringContainsString( '服务商原始回复', $body, 'meta.raw 展开行人话（对照表 18）' );
		$this->assertStringContainsString( '哪个服务商', $body );
		$this->assertStringNotContainsString( '今日故障', $body, '故障卡归属明确为报错历史（P8）' );
	}

	/**
	 * UI 批 2·接线二：全闸报错时间线（N2 故障 + N3 停转进入·解除——批 1 注记移除）。
	 */
	public function test_fault_section_full_gate_timeline_wired(): void {
		$body = self::body( 'render_stats_fault_section' );
		$this->assertStringContainsString( 'recent_faults', $body, 'N2 全闸故障时间线查询消费' );
		$this->assertStringContainsString( 'recent_health_timeline', $body, 'N3 停转进入·解除时间线查询消费' );
		$this->assertStringContainsString( 'fault_timeline_rows', $body, '行组装纯函数消费（id 倒序归并+处理列映射）' );
		$this->assertStringContainsString( '当时怎么处理', $body, '「怎么处理」列全闸化（原「评论当时怎么处理」）' );
		$this->assertStringContainsString( '哪个门', $body, '全闸时间线新增门名列' );
		$this->assertStringNotContainsString( '当前显示评论门报错', $body, '批 1 注记随接线移除' );
		$this->assertStringNotContainsString( 'recent_comment_faults', $body, '评论门单源查询退役（N2 超集取代）' );
		$this->assertStringNotContainsString( '先收进待审区，等你复核</td>', $body, '处理列不再硬编码——走 handling_word 组合映射' );
	}

	/* ---------- ⑤ 尾两卡：流水 + 设置清零 ---------- */

	public function test_records_card_copy(): void {
		$body = self::body( 'render_stats_records_card' );
		$this->assertStringContainsString( '四、拦截记录流水', $body );
		$this->assertStringContainsString( 'WK-', $body, '事件编号示例在文案中' );
		$this->assertStringNotContainsString( '事件流列表 / 导出', $body, 'roadmap 文案退役（P6）' );
		$this->assertStringNotContainsString( '统计面板 v2', $body );
	}

	public function test_settings_card_holds_switches_and_reset(): void {
		$body = self::body( 'render_stats_settings_card' );
		$this->assertStringContainsString( '五、统计设置', $body );
		// 统计开关三项迁入本卡（P7——单一大 form 迁位零风险，agent C F18）。
		$this->assertStringContainsString( "array( 'stats', 'enabled' )", $body );
		$this->assertStringContainsString( "array( 'stats', 'raw_capture' )", $body );
		$this->assertStringContainsString( "array( 'stats', 'raw_max_bytes' )", $body );
		$this->assertStringContainsString( '服务商原始回复', $body, 'raw_capture 开关人话（对照表 18）' );
		$this->assertStringContainsString( '保存长度上限', $body, '快照截断字节→保存长度上限（对照表 19）' );
		$this->assertStringContainsString( '清空全部统计', $body );
		$this->assertStringContainsString( '所有统计数字和拦截记录', $body, '重置文案去「对账口径」（P10/对照表 22）' );
		$this->assertStringContainsString( 'id="wk-stats-reset"', $body, 'AJAX 锚保留（admin-brand.js 绑定点）' );
		$this->assertStringNotContainsString( '对账口径', $body );
		$this->assertStringNotContainsString( '快照截断字节', $body );
		$this->assertStringNotContainsString( 'meta.raw', $body );
	}

	/* ---------- ⑥ 全局黑话清理（P5/P9/P12） ---------- */

	public function test_developer_memo_removed_from_tab6(): void {
		$main = self::body( 'render_tab_stats' )
			. self::body( 'render_stats_comment_section' )
			. self::body( 'render_stats_login_section' );
		$this->assertStringNotContainsString( 'pass 默认不记（采样为高级开关）', $main, '开发者备忘不上站长 UI（P5）' );
		$this->assertStringNotContainsString( '维度', $main, '表头黑话「维度」退役（对照表 8）' );
		$this->assertStringNotContainsString( 'render_partition_table', $main, '旧分区表退役（驱动卡替代）' );
	}

	public function test_cumulative_window_note_present(): void {
		$body = self::body( 'render_stats_overview_strip' );
		$this->assertStringContainsString( 'Labels::cumulative_line', $body, '总览口径补全走映射表（P9/对照表 24）' );
		$this->assertStringContainsString( '转人工复核', $body, '速览条含待审口径' );
		$this->assertStringContainsString( '留档', $body, 'spam 黄条保留原位（F14）' );
		$this->assertStringContainsString( '计数已停用', $body, '统计停用黄字注保留（FIX-δ）' );
	}

	/* ---------- ⑦ 版本 0.4.1（随批次推进同步——O3 仪表盘卡片批次） ---------- */

	public function test_version_bumped_to_0_4_0_in_four_places(): void {
		$entry  = self::source( 'wink-wp-gatekeeper.php' );
		$readme = self::source( 'readme.txt' );
		$this->assertMatchesRegularExpression( '/^ \* Version:\s+0\.4\.1$/m', $entry, '插件头 Version 0.4.1' );
		$this->assertStringContainsString( "define( 'WINK_GATEKEEPER_VERSION', '0.4.1' )", $entry, '版本常量 0.4.1' );
		$this->assertMatchesRegularExpression( '/^Stable tag: 0\.4\.1$/m', $readme, 'readme Stable tag 0.4.1' );
		$this->assertStringContainsString( '= 0.4.1 =', $readme, 'Changelog 0.4.1 条目' );
	}
}
