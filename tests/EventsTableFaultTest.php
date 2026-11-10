<?php
/**
 * 事件「故障」归类口径纯逻辑测试（04 §3 v1.4·R4 §D.1：verdict=error ∪ rule_hit 前缀族）。
 *
 * 口径 = verdict=error ∪ rule_hit ∈ {remote_*, contract_mismatch, driver_halted*}
 * ——含 captcha 阶段以 reject 落库的远端故障（rule_hit=remote_* 的 reject 行）。
 * Tab① 最近错误摘要 / Tab⑥ 今日故障卡 / 事件流「判定=故障」预设统一消费。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Stats\EventsTable;

final class EventsTableFaultTest extends TestCase {

	public function test_verdict_error_is_always_fault(): void {
		$this->assertTrue( EventsTable::is_fault( 'error', '' ) );
		$this->assertTrue( EventsTable::is_fault( 'error', 'remote_transport' ) );
		$this->assertTrue( EventsTable::is_fault( 'error', 'anything' ) );
	}

	public function test_remote_rule_hit_reject_is_fault_even_on_captcha_stage(): void {
		// captcha 阶段远端终态以 reject 落库（rule_hit=remote_<category>）——归类为故障。
		$this->assertTrue( EventsTable::is_fault( 'reject', 'remote_transport' ), '含 captcha 阶段以 reject 落库的远端故障' );
		$this->assertTrue( EventsTable::is_fault( 'reject', 'remote_semantic' ) );
		$this->assertTrue( EventsTable::is_fault( 'reject', 'remote_contract' ) );
		$this->assertTrue( EventsTable::is_fault( 'reject', 'remote_parse' ) );
	}

	public function test_contract_mismatch_and_driver_halted_are_faults(): void {
		$this->assertTrue( EventsTable::is_fault( 'reject', 'contract_mismatch' ), 'captcha 契约哨兵（R6/R9）' );
		$this->assertTrue( EventsTable::is_fault( 'error', 'driver_halted:config_error' ) );
		$this->assertTrue( EventsTable::is_fault( 'error', 'driver_halted:quota_exhausted' ) );
	}

	public function test_business_verdicts_are_not_faults(): void {
		// 业务判定（LocalRules reject / 云审 review / captcha 业务码 / 待审收口）非故障。
		$this->assertFalse( EventsTable::is_fault( 'reject', 'too_short' ) );
		$this->assertFalse( EventsTable::is_fault( 'reject', 'no_chinese' ) );
		$this->assertFalse( EventsTable::is_fault( 'reject', 'banned_word' ) );
		$this->assertFalse( EventsTable::is_fault( 'reject', 'captcha_missing' ) );
		$this->assertFalse( EventsTable::is_fault( 'reject', 'CaptchaCode=8' ) );
		$this->assertFalse( EventsTable::is_fault( 'reject', 'Ad,99' ) );
		$this->assertFalse( EventsTable::is_fault( 'review', 'Ad,60' ) );
		$this->assertFalse( EventsTable::is_fault( 'review', 'hold_moderation' ) );
		$this->assertFalse( EventsTable::is_fault( 'pass', 'verify_cache_hit' ) );
		// 前缀必须锚定开头：remote_ 出现在中段不误判（rule_hit 为逗号拼接多标签时按整串前缀判）。
		$this->assertFalse( EventsTable::is_fault( 'reject', 'x_remote_transport' ) );
	}

	public function test_fault_sql_predicate_fragments_match_pure_logic(): void {
		// 边界查询（今日故障卡/最近错误摘要）与纯口径同源：SQL 片段常量供 WP 边界拼接。
		$this->assertSame( "(verdict = 'error' OR rule_hit LIKE 'remote\\_%' OR rule_hit = 'contract_mismatch' OR rule_hit LIKE 'driver\\_halted%')", EventsTable::fault_predicate_sql() );
	}
}
