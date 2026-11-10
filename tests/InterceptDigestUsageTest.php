<?php
/**
 * Tab⑥ 驱动行并入 usage 口径纯逻辑测试（03 Tab⑥ v1.4·R1 §6-1；F44）。
 *
 * 「全 pass 正常」与「从未调用」在 Tab⑥ 不可区分的可观测性缺口修复：
 * 驱动行今日列追加「调用 N · 全通过」；停转/配额尽时显示「0 调用 + 状态」（与 Tab① 一致）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Stats\InterceptDigest;

final class InterceptDigestUsageTest extends TestCase {

	public function test_all_pass_usage_note(): void {
		// 今日无拦截/待审/故障计数 + 调用 3 → 「调用 3 · 全通过」（R1 真实场景复现）。
		$this->assertSame( '调用 3 · 全通过', InterceptDigest::driver_usage_note( array(), 3, null ) );
	}

	public function test_zero_usage_note(): void {
		$this->assertSame( '0 调用', InterceptDigest::driver_usage_note( array(), 0, null ) );
	}

	public function test_zero_usage_with_halt_state(): void {
		// 停转/配额尽：0 调用 + 状态（与 Tab① 一致）。
		$this->assertSame( '0 调用 · 当日停转（config_error）', InterceptDigest::driver_usage_note( array(), 0, 'config_error' ) );
		$this->assertSame( '0 调用 · 当日停转（quota_exhausted）', InterceptDigest::driver_usage_note( array(), 0, 'quota_exhausted' ) );
	}

	public function test_usage_note_appended_to_counts(): void {
		// 有计数时：计数 + 调用数并存（双口径）。
		$this->assertSame( '拦截 2 · 调用 5', InterceptDigest::driver_usage_note( array( 'reject' => 2 ), 5, null ) );
		$this->assertSame( '拦截 1 · 待审 2 · 故障 1 · 调用 9', InterceptDigest::driver_usage_note( array( 'reject' => 1, 'review' => 2, 'error' => 1 ), 9, null ) );
	}

	public function test_usage_note_ignores_pass_counts(): void {
		// pass 计数不进摘要（verify_cache_hit 特例 pass 落库时 by_driver 会带 pass 键）。
		$this->assertSame( '调用 4 · 全通过', InterceptDigest::driver_usage_note( array( 'pass' => 4 ), 4, null ) );
	}

	/* ---------- partitions 驱动行附带 usage/halt 数据 ---------- */

	public function test_partitions_driver_rows_carry_usage_today_and_halt_state(): void {
		$cache = array(
			'today'     => '20261110',
			'by_gate'   => array( 'comment' => array( 'reject' => 1 ) ),
			'by_driver' => array( 'tencent_tms' => array( 'reject' => 1 ) ),
			'total'     => array( 'by_gate' => array( 'comment' => array( 'reject' => 1 ) ), 'by_driver' => array( 'tencent_tms' => array( 'reject' => 1 ) ), 'reject' => 1 ),
		);
		$partitions = InterceptDigest::partitions(
			$cache,
			array( 'tencent_tms' => '驱动：腾讯云 TMS 文本审核' ),
			array(
				'usage_today' => array( 'tencent_tms' => 3 ),
				'halted'      => array(),
			)
		);

		$rows = $partitions['comment']['rows'];
		$tms  = null;
		foreach ( $rows as $row ) {
			if ( str_contains( (string) $row['label'], 'TMS' ) ) {
				$tms = $row;
			}
		}
		$this->assertNotNull( $tms, '驱动行存在' );
		$this->assertSame( 3, $tms['usage_today'] );
		$this->assertNull( $tms['halt_state'] );
		$this->assertSame( '拦截 1 · 调用 3', $tms['usage_note'] );
	}

	public function test_partitions_login_label_renamed_to_account_protection(): void {
		// 【v1.4·R3】原「登录拦截」更名「账户保护」——login/register/lostpassword 三分闸小字标注。
		$partitions = InterceptDigest::partitions( array( 'by_gate' => array(), 'by_driver' => array(), 'total' => array( 'by_gate' => array(), 'by_driver' => array(), 'reject' => 0 ) ), array() );
		$this->assertSame( '账户保护', $partitions['login']['label'] );
	}
}
