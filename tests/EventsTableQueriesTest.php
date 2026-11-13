<?php
/**
 * UI 批 2·五个新查询谓词契约测试（DESIGN-UI-tech-feasibility §D N1/N2/N3/N4/N6+N7a）。
 *
 * 形态（EventsTable 现有先例——fault_predicate_sql 纯逻辑测试同款，G5 零 WP 依赖）：
 *   纯 SQL 模板 builder（表名注入 + 动态值一律 prepare 占位符 %s/%d）逐字断言
 *   谓词/排序/上限/只读；行组装纯函数另见 InterceptDigestRowsTest；
 *   WP 边界执行由真机 DOM 断言承载（06 §3 通道——静态自查清单形态）。
 *
 * 五查询口径锚（agent C §D）：
 *   N1 recent_account_rejects   登录域最近拦截明细（三闸 + reject + stub/captcha）
 *   N2 recent_faults            全闸故障时间线（fault_predicate_sql 复用——F7）
 *   N3 recent_health_timeline   停转进入·解除时间线（system/health，driver_resumed 非 fault 口径）
 *   N4 today_reject_distribution 今日按规则分布（战报副行数据源——批 3 消费）
 *   N6 today_fault_by_driver    今日故障按驱动聚合（驱动卡出错格 N6/N7a 数据源）
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Stats\EventsTable;
use WinkGatekeeper\Stats\InterceptDigest;

final class EventsTableQueriesTest extends TestCase {

	private const TABLE = 'wp_wink_gatekeeper_events';

	/* ---------- N1：最近登录拦截明细 ---------- */

	public function test_n1_account_rejects_sql_predicate(): void {
		$sql = EventsTable::recent_account_rejects_sql( self::TABLE );

		$this->assertStringStartsWith( 'SELECT event_no, created_at, gate, stage, driver, rule_hit, meta FROM ' . self::TABLE, $sql, '列集不带 ip_hash/reason——PIPL 最小化（任务口径：IP 哈希省略）' );
		$this->assertStringContainsString( "gate IN ('login','register','lostpassword')", $sql, '账户族三闸（InterceptDigest::ACCOUNT_GATES 同集合）' );
		$this->assertStringContainsString( "verdict = 'reject'", $sql, '仅拦截行' );
		$this->assertStringContainsString( "stage IN ('stub','captcha')", $sql, '登录域拦点两阶段（agent C N1 谓词）' );
		$this->assertStringContainsString( 'ORDER BY id DESC LIMIT %d', $sql, '自增主键倒序 + prepare 上限占位' );
		$this->assertStringNotContainsString( 'ip_hash', $sql, 'IP 哈希省略（尝试特征不入明细卡）' );
	}

	public function test_n1_boundary_method_exists_with_default_limit(): void {
		// 边界方法签名存在且默认行数=明细卡常量（真机通道执行；此处钉装配形态）。
		$method = new \ReflectionMethod( EventsTable::class, 'recent_account_rejects' );
		$default = $method->getParameters()[0]->getDefaultValue();
		$this->assertSame( InterceptDigest::RECENT_LIMIT, $default );
	}

	/* ---------- N2：全闸故障时间线 ---------- */

	public function test_n2_faults_sql_reuses_fault_predicate(): void {
		$sql = EventsTable::recent_faults_sql( self::TABLE );

		$this->assertStringContainsString( 'SELECT id, event_no, created_at, gate, stage, driver, verdict, rule_hit, meta FROM ' . self::TABLE, $sql, '列集含 gate/verdict/meta——门名/处理列映射/meta.raw 展开行数据源；id=两源归并序键（UI 批 3 修正——批 2 文档声称在列而 SQL 漏选，真机 DOM 断言实锤归并退化+停转行双源重复）' );
		$this->assertStringContainsString( 'WHERE ' . EventsTable::fault_predicate_sql(), $sql, '故障口径谓词同源复用（F7——唯一权威定义）' );
		$this->assertStringContainsString( 'ORDER BY id DESC LIMIT %d', $sql );
	}

	/* ---------- N3：停转进入·解除时间线 ---------- */

	public function test_n3_health_predicate_exact(): void {
		$this->assertSame(
			"(rule_hit LIKE 'driver\\_halted:%' OR rule_hit = 'driver_resumed')",
			EventsTable::health_timeline_predicate_sql(),
			'停转进入（driver_halted:<state>）∪ 解除（driver_resumed——verdict=pass 特例，非 fault 口径 F7）'
		);
		// 与故障谓词互斥面：driver_resumed 不在故障谓词内（解除记录单独入列的理由）。
		$this->assertStringNotContainsString( 'driver_resumed', EventsTable::fault_predicate_sql() );
	}

	public function test_n3_health_timeline_sql_predicate(): void {
		$sql = EventsTable::recent_health_timeline_sql( self::TABLE );

		$this->assertStringContainsString( 'SELECT id, event_no, created_at, gate, stage, driver, verdict, rule_hit FROM ' . self::TABLE, $sql, 'id 列在列（两源归并序键——UI 批 3 修正）' );
		$this->assertStringContainsString( "WHERE gate = 'system' AND stage = 'health'", $sql, '健康留痕行域（04 §3 写入矩阵 system 行）' );
		$this->assertStringContainsString( 'AND ' . EventsTable::health_timeline_predicate_sql(), $sql );
		$this->assertStringContainsString( 'AND created_at >= %s', $sql, '查询窗口（days）走 prepare 占位' );
		$this->assertStringContainsString( 'ORDER BY id DESC LIMIT %d', $sql );
		$this->assertStringContainsString( 'driver_resumed', $sql, '解除留痕在列（B-W3：最近异常+恢复合并）' );
	}

	/* ---------- N4：今日按规则分布 ---------- */

	public function test_n4_today_reject_distribution_sql(): void {
		$sql = EventsTable::today_reject_distribution_sql( self::TABLE );

		$this->assertStringContainsString( 'SELECT rule_hit, COUNT(*) AS n FROM ' . self::TABLE, $sql );
		$this->assertStringContainsString( 'WHERE gate = %s', $sql, '闸 id 走 prepare（调用方传参——批 3 战报副行传 comment）' );
		$this->assertStringContainsString( "verdict = 'reject'", $sql, '仅拦下行' );
		$this->assertStringContainsString( 'AND created_at >= %s', $sql, '今日窗口' );
		$this->assertStringContainsString( 'GROUP BY rule_hit ORDER BY n DESC', $sql, '与 today_fault_distribution 同构（agent C N4）' );
	}

	/* ---------- N6+N7a：今日故障按驱动聚合 ---------- */

	public function test_n6_today_fault_by_driver_sql_full_gate(): void {
		$sql = EventsTable::today_fault_by_driver_sql( self::TABLE, array() );

		$this->assertStringContainsString( 'SELECT driver, COUNT(*) AS n FROM ' . self::TABLE, $sql );
		$this->assertStringContainsString( 'WHERE ' . EventsTable::fault_predicate_sql(), $sql, '故障口径同源（含 captcha 阶段以 reject 落库的远端故障——A-5 出错格修正）' );
		$this->assertStringContainsString( 'AND created_at >= %s', $sql );
		$this->assertStringContainsString( 'GROUP BY driver ORDER BY n DESC', $sql );
		$this->assertStringNotContainsString( 'gate IN', $sql, '无闸过滤=全闸口径（驱动卡出错格 driver-centric）' );
	}

	public function test_n7a_gate_filter_sql_with_account_gates(): void {
		$sql = EventsTable::today_fault_by_driver_sql( self::TABLE, array( 'login', 'register', 'lostpassword' ) );

		$this->assertStringContainsString( "AND gate IN ('login','register','lostpassword')", $sql, '分域过滤（N7a 登录卡出错格——idx_gate_driver 最左前缀命中）' );
	}

	public function test_gate_list_sql_sanitizes_identifiers(): void {
		// 防御性闸 id 白名单清洗（引号/分号不进 IN 列表——闸 id 虽来自内部常量，边界兜底）。
		$sql = EventsTable::today_fault_by_driver_sql( self::TABLE, array( "x'; DROP TABLE wp_wink_gatekeeper_events--", 'ok_id' ) );
		$this->assertStringNotContainsString( ';', $sql, '注入片段不得存活（分号清洗）' );
		$this->assertStringNotContainsString( 'DROP', $sql );
		$this->assertStringContainsString( "'ok_id'", $sql, '合法闸 id 原样入列' );
	}

	/* ---------- 只读契约 + LIMIT 钳制 ---------- */

	public function test_all_new_queries_are_read_only_select(): void {
		$sqls = array(
			EventsTable::recent_account_rejects_sql( self::TABLE ),
			EventsTable::recent_faults_sql( self::TABLE ),
			EventsTable::recent_health_timeline_sql( self::TABLE ),
			EventsTable::today_reject_distribution_sql( self::TABLE ),
			EventsTable::today_fault_by_driver_sql( self::TABLE, array() ),
			EventsTable::today_fault_by_driver_sql( self::TABLE, array( 'login' ) ),
		);
		foreach ( $sqls as $sql ) {
			$this->assertSame( 1, preg_match( '/^SELECT /', $sql ), '只读 SELECT 开头' );
			$this->assertSame( 0, preg_match( '/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|REPLACE|GRANT)\b/i', $sql ), '禁 DML/DDL 关键字' );
			$this->assertStringNotContainsString( 'FOR UPDATE', $sql );
		}
	}

	public function test_limit_clamped_to_documented_range(): void {
		$this->assertSame( 1, EventsTable::limit_clamped( 0 ), '下限 1' );
		$this->assertSame( 1, EventsTable::limit_clamped( -5 ) );
		$this->assertSame( 10, EventsTable::limit_clamped( 10 ) );
		$this->assertSame( 50, EventsTable::limit_clamped( 51 ), '上限 50（与 recent_comment_rejects 同界）' );
	}

	/* ---------- 【P0-4·F1 批】写入路径 COUNT(*) 冗余移除（R-AK §D P0-3） ---------- */

	/**
	 * 提取具名方法体（花括号配平）——写入路径契约按作用域收窄断言。
	 */
	private static function methodBody( string $src, string $name ): string {
		$start = strpos( $src, 'function ' . $name );
		self::assertNotFalse( $start, "方法 {$name} 必须存在" );
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
		self::fail( "方法 {$name} 花括号不配平" );
		return '';
	}

	public function test_insert_path_does_not_call_trim_overflow(): void {
		// M4UX 定稿 §2 P0-4：每行事件 insert 曾尾随 trim_overflow（万行表 COUNT(*)）——
		// 洪水期（恰是最不该加压的时刻）每行多付一查。容量兜底职责归 daily_maintenance
		// （既有独立调用）；写入路径从 3 查询（SELECT MAX + INSERT + COUNT）降 2 查询。
		$src = (string) file_get_contents( __DIR__ . '/../includes/stats/EventsTable.php' );

		$this->assertStringNotContainsString( 'trim_overflow', self::methodBody( $src, 'insert' ), '写入路径禁容量检查调用（COUNT(*) 冗余移除）' );

		$daily = self::methodBody( $src, 'daily_maintenance' );
		$this->assertStringContainsString( 'trim_overflow', $daily, '容量兜底保留在 daily cron（滚动语义仍在）' );

		$trim = self::methodBody( $src, 'trim_overflow' );
		$this->assertStringContainsString( 'SELECT COUNT(*)', $trim, 'trim 本体仍是 COUNT 口径（cron 兜底同一实现）' );
	}
}
