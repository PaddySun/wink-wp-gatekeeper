<?php
/**
 * EventsTable 建表 DDL 单元测试（04 §3）。
 *
 * 规格：DDL 常量精确照抄 04 §3 的列/索引（简单断言，不测 dbDelta 本身）；
 * 表名/cron 钩子常量供激活与卸载共用。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Stats\EventsTable;

final class EventsTableTest extends TestCase {

	/**
	 * C4：encode_meta 剥除 client_ip/ip（PIPL 最小化，04 §3「不存明文 IP」）——
	 * client_ip 仅为记录器闭包算 ip_hash 的经手值，永不入 meta 列（登录/评论两闸统一）。
	 */
	public function test_encode_meta_strips_raw_ip_keys(): void {
		$encoded = EventsTable::encode_meta( array(
			'client_ip' => '203.0.113.7',
			'ip'        => '198.51.100.9',
			'ip_src'    => 'direct',
			'Score'     => 99,
		) );
		$this->assertSame( '{"ip_src":"direct","Score":99}', $encoded );
	}

	public function test_encode_meta_drops_null_values(): void {
		$this->assertSame( '{"a":1}', EventsTable::encode_meta( array( 'a' => 1, 'b' => null ) ) );
	}

	public function test_table_suffix_constant(): void {
		$this->assertSame( 'wink_gatekeeper_events', EventsTable::TABLE_SUFFIX );
	}

	public function test_table_name_prepends_site_prefix(): void {
		$this->assertSame( 'wp_wink_gatekeeper_events', EventsTable::table_name( 'wp_' ) );
		$this->assertSame( 'blog1_wink_gatekeeper_events', EventsTable::table_name( 'blog1_' ) );
	}

	public function test_cron_hook_constant(): void {
		// 04 §3：每日 WP-Cron 事件 wink_gatekeeper_daily_maintenance（90 天清理 + 1 万行滚动）。
		$this->assertSame( 'wink_gatekeeper_daily_maintenance', EventsTable::HOOK_DAILY );
	}

	public function test_retention_constants_match_design(): void {
		$this->assertSame( 10000, EventsTable::MAX_ROWS );
		$this->assertSame( 90, EventsTable::RETENTION_DAYS );
	}

	public function test_ddl_starts_with_create_table_and_appends_charset(): void {
		$ddl = EventsTable::ddl( 'wp_', 'utf8mb4_unicode_520_ci' );
		$this->assertStringStartsWith( 'CREATE TABLE wp_wink_gatekeeper_events (', $ddl );
		$this->assertStringEndsWith( ') utf8mb4_unicode_520_ci;', $ddl );
	}

	/**
	 * 04 §3 全部 11 列逐一在场。
	 */
	public function test_ddl_contains_all_columns(): void {
		$ddl = EventsTable::ddl( 'wp_', 'charset' );
		foreach ( array(
			'id',
			'event_no',
			'created_at',
			'gate',
			'stage',
			'driver',
			'verdict',
			'rule_hit',
			'reason',
			'ip_hash',
			'meta',
		) as $column ) {
			$this->assertStringContainsString( $column, $ddl, "缺列：{$column}" );
		}
	}

	/**
	 * 列定义形态抽查（类型/长度/默认值与 04 §3 一致）。
	 */
	public function test_ddl_column_definitions_match_design(): void {
		$ddl = EventsTable::ddl( 'wp_', 'charset' );

		$this->assertStringContainsString( 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', $ddl );
		$this->assertStringContainsString( 'event_no VARCHAR(20) NOT NULL DEFAULT', $ddl );
		$this->assertStringContainsString( 'created_at DATETIME NOT NULL', $ddl );
		$this->assertStringContainsString( 'gate VARCHAR(20) NOT NULL', $ddl );
		$this->assertStringContainsString( 'stage VARCHAR(20) NOT NULL', $ddl );
		$this->assertStringContainsString( 'driver VARCHAR(40) NOT NULL', $ddl );
		$this->assertStringContainsString( 'verdict VARCHAR(10) NOT NULL', $ddl );
		$this->assertStringContainsString( 'rule_hit VARCHAR(120) NOT NULL DEFAULT', $ddl );
		$this->assertStringContainsString( 'reason TEXT NULL', $ddl );
		$this->assertStringContainsString( 'ip_hash CHAR(16) NOT NULL DEFAULT', $ddl );
		$this->assertStringContainsString( 'meta LONGTEXT NULL', $ddl );
	}

	/**
	 * 索引：PRIMARY KEY / UNIQUE KEY event_no / KEY idx_created / KEY idx_gate_driver（04 §3）。
	 */
	public function test_ddl_contains_all_indexes(): void {
		$ddl = EventsTable::ddl( 'wp_', 'charset' );

		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $ddl ); // dbDelta 惯例双空格。
		$this->assertStringContainsString( 'UNIQUE KEY event_no (event_no)', $ddl );
		$this->assertStringContainsString( 'KEY idx_created (created_at)', $ddl );
		$this->assertStringContainsString( 'KEY idx_gate_driver (gate, driver, verdict)', $ddl );
	}

	/**
	 * dbDelta 格式规范：每字段独立行；dbDelta 首次建表原样执行语句，
	 * 故除末行外每行必须以逗号收尾，末行不得带逗号。
	 */
	public function test_ddl_puts_each_field_on_its_own_line(): void {
		$ddl     = EventsTable::ddl( 'wp_', 'charset' );
		$lines   = explode( "\n", $ddl );
		$body    = array_slice( $lines, 1, -1 ); // 去掉 CREATE TABLE 首行与收尾行。
		$this->assertGreaterThan( 10, count( $body ) );

		$last_index = count( $body ) - 1;
		foreach ( $body as $index => $line ) {
			$trimmed = trim( $line );
			// 每行要么是字段定义（以列名开头、含类型），要么是 KEY 定义——不允许一行多定义。
			$is_field = (bool) preg_match( '/^[a-z_]+ [A-Z]/', $trimmed );
			$is_key   = ( 0 === strpos( $trimmed, 'PRIMARY KEY' ) )
				|| ( 0 === strpos( $trimmed, 'UNIQUE KEY' ) )
				|| ( 0 === strpos( $trimmed, 'KEY ' ) );
			$this->assertTrue( $is_field || $is_key, "非规范行：{$trimmed}" );

			if ( $index === $last_index ) {
				$this->assertStringEndsNotWith( ',', $trimmed, '末行不得带逗号' );
			} else {
				$this->assertStringEndsWith( ',', $trimmed, '非末行必须以逗号收尾（dbDelta 原样执行）' );
			}
		}
	}

	public function test_ddl_is_deterministic(): void {
		$this->assertSame(
			EventsTable::ddl( 'wp_', 'utf8mb4_unicode_ci' ),
			EventsTable::ddl( 'wp_', 'utf8mb4_unicode_ci' )
		);
	}
}
