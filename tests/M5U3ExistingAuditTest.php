<?php
/**
 * M5 U3 批 · 存量/全局评论审核·基础段纯逻辑测试（2026-11-14）。
 *
 * 设计权威：M5 定稿 §3（基础段复用四云审驱动，不涉 LLM；「昵称+正文」送审串——邮箱剔除
 * 纪律沿用、网站 URL 不入串〔URL 是 U5 的事〕；每轮 ≤25 条或 ≤4 分钟墙钟；批次间随机抖动
 * 0.5–2s；仅标记为唯一默认；幂等按 comment_meta wink_audit_existing；总数=发起时快照〔K5〕）。
 * UI 权威：DESIGN-M5-UI 块 5.1（D6：Tab⑦ 发起/Tab⑥ 报告分工）+块 3.4 K5 口径。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Audit\ExistingAuditTask;

final class M5U3ExistingAuditTest extends TestCase {

	/* ===================================================================
	 * 送审串构造（定稿 §3：昵称+正文两行；邮箱剔除；URL 不入串）
	 * =================================================================== */

	public function test_build_content_is_author_plus_body_two_lines(): void {
		self::assertSame( "张三\n好文章！", ExistingAuditTask::build_content( '张三', '好文章！' ) );
	}

	public function test_build_content_excludes_email_entirely(): void {
		// 邮箱剔除纪律：构造函数根本不收 email 形参——签名级剔除（比过滤更硬）。
		$ref = new \ReflectionMethod( ExistingAuditTask::class, 'build_content' );
		self::assertSame( 2, $ref->getNumberOfParameters(), 'build_content 恰两参（author/body）——email 无入口' );
	}

	public function test_build_content_keeps_empty_author_line_shape(): void {
		// 单一确定性形态：空昵称保留空行（与在线链 assemble_review_content 同哲学）。
		self::assertSame( "\n正文", ExistingAuditTask::build_content( '', '正文' ) );
	}

	/* ===================================================================
	 * 进度结构（K5：总数=发起时快照；processed 单调）
	 * =================================================================== */

	public function test_new_progress_snapshot(): void {
		$p = ExistingAuditTask::new_progress( 1800, 'tencent_tms', 25, 1000 );
		self::assertSame( 'running', $p['status'] );
		self::assertSame( 1800, $p['total'] );
		self::assertSame( 0, $p['processed'] );
		self::assertSame( 'tencent_tms', $p['driver'] );
		self::assertSame( 25, $p['batch_size'] );
		self::assertSame( 1000, $p['started_at'] );
		self::assertSame( array( 'pass' => 0, 'reject' => 0, 'review' => 0, 'gone' => 0 ), $p['verdicts'] );
	}

	public function test_advance_counts_verdicts_and_processed(): void {
		$p = ExistingAuditTask::new_progress( 10, 'tencent_tms', 25, 0 );
		$p = ExistingAuditTask::advance( $p, 'reject' );
		$p = ExistingAuditTask::advance( $p, 'pass' );
		$p = ExistingAuditTask::advance( $p, 'gone' );
		self::assertSame( 3, $p['processed'] );
		self::assertSame( 1, $p['verdicts']['reject'] );
		self::assertSame( 1, $p['verdicts']['pass'] );
		self::assertSame( 1, $p['verdicts']['gone'] );
	}

	public function test_advance_ignores_unknown_verdict_but_counts_processed(): void {
		$p = ExistingAuditTask::new_progress( 5, 'baidu', 25, 0 );
		$p = ExistingAuditTask::advance( $p, 'error' );
		self::assertSame( 1, $p['processed'], 'error 也计入 processed（不重跑）' );
		self::assertSame( 0, $p['verdicts']['reject'] );
	}

	public function test_is_finished_when_processed_reaches_total(): void {
		$p = ExistingAuditTask::new_progress( 2, 'tencent_tms', 25, 0 );
		self::assertFalse( ExistingAuditTask::is_finished( $p ) );
		$p = ExistingAuditTask::advance( $p, 'pass' );
		$p = ExistingAuditTask::advance( $p, 'reject' );
		self::assertTrue( ExistingAuditTask::is_finished( $p ) );
	}

	/* ===================================================================
	 * 分批边界（≤25 条 或 ≤4 分钟墙钟——先到为准）
	 * =================================================================== */

	public function test_batch_limits(): void {
		self::assertSame( 25, ExistingAuditTask::BATCH_MAX_ITEMS );
		self::assertSame( 240, ExistingAuditTask::BATCH_MAX_SECONDS );
	}

	public function test_deadline_reached(): void {
		self::assertFalse( ExistingAuditTask::deadline_reached( 100, 100 + 239 ) );
		self::assertTrue( ExistingAuditTask::deadline_reached( 100, 100 + 240 ) );
		self::assertTrue( ExistingAuditTask::deadline_reached( 100, 100 + 241 ) );
	}

	public function test_jitter_bounds(): void {
		for ( $i = 0; $i < 50; $i++ ) {
			$j = ExistingAuditTask::jitter_seconds();
			self::assertGreaterThanOrEqual( 0.5, $j, '抖动下限 0.5s（定稿 §2.5-6/§3）' );
			self::assertLessThanOrEqual( 2.0, $j, '抖动上限 2s' );
		}
	}

	/* ===================================================================
	 * 幂等（meta 在=跳过；重新审核=清除）
	 * =================================================================== */

	public function test_should_process_skips_audited(): void {
		self::assertTrue( ExistingAuditTask::should_process( null ), '无 meta=待审' );
		self::assertFalse( ExistingAuditTask::should_process( array( 'verdict' => 'pass' ) ), '有 meta=幂等跳过' );
		self::assertFalse( ExistingAuditTask::should_process( array( 'verdict' => 'reject' ) ) );
	}

	public function test_meta_record_shape(): void {
		$m = ExistingAuditTask::meta_record( 'reject', '广告', array( '广告' ), 'tencent_tms', 1234 );
		self::assertSame( 'reject', $m['verdict'] );
		self::assertSame( '广告', $m['reason'] );
		self::assertSame( array( '广告' ), $m['hits'] );
		self::assertSame( 'tencent_tms', $m['driver'] );
		self::assertSame( 1234, $m['audited_at'] );
	}

	/* ===================================================================
	 * 状态机（running/paused/quota_paused/completed + 边沿通知一次）
	 * =================================================================== */

	public function test_pause_and_resume(): void {
		$p = ExistingAuditTask::new_progress( 10, 'tencent_tms', 25, 0 );
		$p = ExistingAuditTask::pause( $p, 'manual' );
		self::assertSame( 'paused', $p['status'] );
		$p = ExistingAuditTask::resume( $p );
		self::assertSame( 'running', $p['status'] );
	}

	public function test_quota_pause_edge_notify_once(): void {
		$p = ExistingAuditTask::new_progress( 10, 'tencent_tms', 25, 0 );
		$p = ExistingAuditTask::quota_pause( $p, 1000 );
		self::assertSame( 'quota_paused', $p['status'] );
		self::assertSame( 1000, $p['quota_paused_at'] );
		self::assertTrue( $p['quota_notified'], '黄条边沿通知标志' );
		$p2 = ExistingAuditTask::quota_pause( $p, 2000 );
		self::assertSame( 1000, $p2['quota_paused_at'], '重复触发不刷新时刻（边沿一次语义）' );
	}

	public function test_quota_auto_resume(): void {
		// nw §2.5-5：次日自动恢复——quota_paused 态配额恢复时回到 running。
		$p = ExistingAuditTask::new_progress( 10, 'tencent_tms', 25, 0 );
		$p = ExistingAuditTask::quota_pause( $p, 1000 );
		$p = ExistingAuditTask::quota_resume( $p );
		self::assertSame( 'running', $p['status'] );
		self::assertArrayNotHasKey( 'quota_notified', $p, '通知标志复位（下轮触发可再报边沿）' );
	}

	public function test_completed_sets_finished_at(): void {
		$p = ExistingAuditTask::new_progress( 1, 'tencent_tms', 25, 0 );
		$p = ExistingAuditTask::advance( $p, 'pass' );
		$p = ExistingAuditTask::mark_completed( $p, 2000 );
		self::assertSame( 'completed', $p['status'] );
		self::assertSame( 2000, $p['finished_at'] );
	}
}
