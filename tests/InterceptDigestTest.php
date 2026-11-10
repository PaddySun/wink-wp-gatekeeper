<?php
/**
 * InterceptDigest 纯逻辑测试（Tab⑥ v1.3：分区数据结构 + 最近拦截明细 + 黄条阈值）。
 *
 * InterceptDigest 为 Tab⑥ 统计分区的纯逻辑层（零 WP 依赖）：分区渲染数据结构、
 * 明细卡行组装（打码/摘要/留档链接）、spam 留档量黄条阈值决策。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Stats\InterceptDigest;

final class InterceptDigestTest extends TestCase {

	/* ---------- 打码 ---------- */

	public function test_mask_author_keeps_first_char_only(): void {
		$this->assertSame( '张***', InterceptDigest::mask_author( '张三丰' ) );
		$this->assertSame( 'W***', InterceptDigest::mask_author( 'WINK测试访客' ) );
		$this->assertSame( '—', InterceptDigest::mask_author( '' ) );
	}

	public function test_mask_email_keeps_local_first_char_and_domain(): void {
		$this->assertSame( 'z***@example.com', InterceptDigest::mask_email( 'zhangsan@example.com' ) );
		$this->assertSame( 'a***', InterceptDigest::mask_email( 'abc' ) );
		$this->assertSame( '—', InterceptDigest::mask_email( '' ) );
	}

	/* ---------- 摘要 60 字 ---------- */

	public function test_excerpt_truncates_to_sixty_chars_with_ellipsis(): void {
		$long = str_repeat( '字', 100 );
		$out  = InterceptDigest::excerpt( $long );
		$this->assertSame( 61, mb_strlen( $out, 'UTF-8' ) ); // 60 字 + 省略号。
		$this->assertSame( mb_substr( $long, 0, 60, 'UTF-8' ) . '…', $out );
	}

	public function test_excerpt_short_content_untouched_and_tags_stripped(): void {
		$this->assertSame( '短评', InterceptDigest::excerpt( '短评' ) );
		$this->assertSame( '带标签', InterceptDigest::excerpt( '<b>带标签</b>' ) );
		$this->assertSame( '换行折叠', InterceptDigest::excerpt( "换\n行\n折叠" ) );
	}

	/* ---------- 明细行组装 ---------- */

	public function test_rows_assemble_masked_fields_and_edit_link(): void {
		$events = array(
			array(
				'event_no'   => 'WK-20261110-0001',
				'created_at' => '2026-11-10 09:00:00',
				'stage'      => 'review',
				'rule_hit'   => '私活',
				'meta'       => array( 'comment_id' => 12 ),
			),
			array(
				'event_no'   => 'WK-20261110-0002',
				'created_at' => '2026-11-10 09:05:00',
				'stage'      => 'captcha',
				'rule_hit'   => 'bad-signature',
				'meta'       => array(), // 未留档（stub/archive_spam=false）。
			),
		);
		$comments = array(
			12 => array( 'comment_author' => '承接私活的张三', 'comment_author_email' => 'zhang@example.com', 'comment_content' => '正文摘要样本' ),
		);

		$rows = InterceptDigest::rows( $events, $comments );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'WK-20261110-0001', $rows[0]['event_no'] );
		$this->assertSame( '承***', $rows[0]['author'] );
		$this->assertSame( 'z***@example.com', $rows[0]['email'] );
		$this->assertSame( '正文摘要样本', $rows[0]['excerpt'] );
		$this->assertSame( 12, $rows[0]['comment_id'] );
		$this->assertSame( 'comment.php?action=editcomment&c=12', $rows[0]['edit_url'] );

		$this->assertNull( $rows[1]['comment_id'], '未留档 → 只读展示（无链接）' );
		$this->assertNull( $rows[1]['edit_url'] );
		$this->assertSame( '—', $rows[1]['author'], '无留档评论可查 → 空态占位' );
		$this->assertSame( '—', $rows[1]['excerpt'] );
	}

	/** comment_id 指向的评论已被站长删除 → 行降级为未留档形态（不留死链）。 */
	public function test_rows_degrade_when_archived_comment_deleted(): void {
		$events   = array(
			array( 'event_no' => 'WK-20261110-0003', 'created_at' => '2026-11-10 10:00:00', 'stage' => 'review', 'rule_hit' => 'too_short', 'meta' => array( 'comment_id' => 99 ) ),
		);
		$rows = InterceptDigest::rows( $events, array() ); // 99 不在 comments 映射（已删）。
		$this->assertSame( 99, $rows[0]['comment_id'], '事件侧 comment_id 原样展示' );
		$this->assertNull( $rows[0]['edit_url'], '评论已删 → 不出编辑链接' );
		$this->assertSame( '—', $rows[0]['excerpt'] );
	}

	public function test_rows_empty_events_yield_empty_array(): void {
		$this->assertSame( array(), InterceptDigest::rows( array(), array() ) );
	}

	/* ---------- spam 黄条阈值（硬编码 500） ---------- */

	public function test_spam_warn_threshold_is_500_and_exclusive(): void {
		$this->assertSame( 500, InterceptDigest::SPAM_WARN_THRESHOLD );
		$this->assertFalse( InterceptDigest::spam_warn( 0 ) );
		$this->assertFalse( InterceptDigest::spam_warn( 500 ), '阈值本身不告警（>阈值才提示）' );
		$this->assertTrue( InterceptDigest::spam_warn( 501 ) );
		$this->assertTrue( InterceptDigest::spam_warn( 800 ) );
	}

	/* ---------- 分区渲染数据结构 ---------- */

	private static function cache_fixture(): array {
		return array(
			'today'     => '20261110',
			'by_gate'   => array(
				'comment' => array( 'reject' => 3, 'review' => 1 ),
				'login'   => array( 'reject' => 2 ),
			),
			'by_driver' => array(
				'local_rules' => array( 'reject' => 2 ),
				'gate'        => array( 'reject' => 1 ),
			),
			'total'     => array(
				'by_gate'   => array(
					'comment' => array( 'reject' => 30, 'review' => 5 ),
					'login'   => array( 'reject' => 20 ),
				),
				'by_driver' => array(
					'local_rules' => array( 'reject' => 20 ),
					'gate'        => array( 'reject' => 9, 'review' => 5 ),
				),
				'reject'    => 55,
			),
			'last_reset' => 0,
		);
	}

	public function test_partitions_split_comment_and_login_sections(): void {
		$driver_labels = array( 'local_rules' => '本地规则四闸（上游迁移）' );
		$p = InterceptDigest::partitions( self::cache_fixture(), $driver_labels );

		$this->assertSame( array( 'comment', 'login' ), array_keys( $p ), '两独立分区（03 Tab⑥ v1.3）' );

		// 评论分区：闸行 + 评论域驱动行，today/total 两列均为判定计数 map。
		$this->assertSame( 3, $p['comment']['today_reject'] );
		$labels = array();
		foreach ( $p['comment']['rows'] as $row ) {
			$this->assertArrayHasKey( 'label', $row );
			$this->assertArrayHasKey( 'today', $row );
			$this->assertArrayHasKey( 'total', $row );
			$labels[] = $row['label'];
		}
		$this->assertContains( '评论闸', $labels );
		$this->assertContains( '本地规则四闸（上游迁移）', $labels );
		$gate_row = $p['comment']['rows'][0];
		$this->assertSame( array( 'reject' => 3, 'review' => 1 ), $gate_row['today'] );
		$this->assertSame( array( 'reject' => 30, 'review' => 5 ), $gate_row['total'] );

		// 账户保护分区（v1.4·R3）：三分闸行 + today_reject 三闸合计，空态由数据决定。
		$this->assertSame( '账户保护', $p['login']['label'] );
		$this->assertSame( 2, $p['login']['today_reject'] );
		$this->assertFalse( $p['login']['empty'] );
		$labels = array();
		foreach ( $p['login']['rows'] as $row ) {
			$labels[] = $row['label'];
		}
		$this->assertSame( array( '登录闸', '注册闸', '找回密码闸' ), $labels, 'login/register/lostpassword 三分闸行（by_gate 键随 GateRegistry 自动扩展）' );
	}

	public function test_account_partition_aggregates_register_and_lostpassword(): void {
		$cache = self::cache_fixture();
		$cache['by_gate']['register']     = array( 'reject' => 1 );
		$cache['by_gate']['lostpassword'] = array( 'reject' => 1 );

		$p = InterceptDigest::partitions( $cache, array() );

		$this->assertSame( 4, $p['login']['today_reject'], '三闸 reject 合计（2+1+1）' );
		$register_row = $p['login']['rows'][1];
		$this->assertSame( array( 'reject' => 1 ), $register_row['today'], '注册闸行读 by_gate.register' );
		$lost_row = $p['login']['rows'][2];
		$this->assertSame( array( 'reject' => 1 ), $lost_row['today'], '找回密码闸行读 by_gate.lostpassword' );
	}

	public function test_account_partition_empty_state_without_any_data(): void {
		$cache = self::cache_fixture();
		unset( $cache['by_gate']['login'], $cache['total']['by_gate']['login'] );

		$p = InterceptDigest::partitions( $cache, array() );

		$this->assertTrue( $p['login']['empty'], '三闸计数全空 → 空态（零打扰正常态）' );
		$this->assertNotSame( '', $p['login']['empty_note'], '空态文案非空' );
		$this->assertSame( 0, $p['login']['today_reject'] );
	}

	/** 常量契约：明细 N=10、摘要 60 字、黄条阈值 500（任务口径硬编码）。 */
	public function test_constants_contract(): void {
		$this->assertSame( 10, InterceptDigest::RECENT_LIMIT );
		$this->assertSame( 60, InterceptDigest::EXCERPT_MAX );
		$this->assertSame( 500, InterceptDigest::SPAM_WARN_THRESHOLD );
	}
}
