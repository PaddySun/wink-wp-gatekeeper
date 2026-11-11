<?php
/**
 * UI 批 2·事件行组装纯逻辑测试（DESIGN-UI-tech-feasibility §G 批 2：
 * 「InterceptDigest 行组装扩展单测（门名/映射/打码）」+「插入夹具行→查询→断言」形态——
 * 夹具行 = EventsTable 边界查回的行形态（含 meta 解码），纯函数消费零 WP 依赖）。
 *
 * 覆盖三块新纯函数：
 *   1. account_reject_rows()——登录域拦截明细行（N1：门名/防线/原因人话/触发归因；
 *      IP 哈希省略——PIPL 最小化，无明文账号可显 agent C E-a）；
 *   2. fault_timeline_rows()——全闸故障+停转解除合并时间线（N2+N3：id 倒序归并、
 *      服务商名解析、当时怎么处理映射、meta.raw 透传）；
 *   3. handling_word()——verdict/stage/gate 组合的「当时怎么处理」人话映射（A-12）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Stats\InterceptDigest;

final class InterceptDigestRowsTest extends TestCase {

	/* ---------- 夹具：EventsTable 行形态（meta 已解码） ---------- */

	private static function account_events(): array {
		return array(
			array(
				'event_no'   => 'WK-20261112-0003',
				'created_at' => '2026-11-12 08:03:00',
				'gate'       => 'login',
				'stage'      => 'captcha',
				'driver'     => 'tencent_captcha',
				'rule_hit'   => 'captcha_missing',
				'meta'       => array( 'trigger_source' => 'user_threshold', 'ip_src' => 'direct' ),
			),
			array(
				'event_no'   => 'WK-20261112-0002',
				'created_at' => '2026-11-12 08:02:00',
				'gate'       => 'register',
				'stage'      => 'stub',
				'driver'     => 'gate',
				'rule_hit'   => 'bad-signature',
				'meta'       => array( 'ip_src' => 'direct' ),
			),
			array(
				'event_no'   => 'WK-20261112-0001',
				'created_at' => '2026-11-12 08:01:00',
				'gate'       => 'lostpassword',
				'stage'      => 'captcha',
				'driver'     => 'aliyun_captcha',
				'rule_hit'   => 'CaptchaCode=8',
				'meta'       => array( 'trigger_source' => 'always' ),
			),
		);
	}

	private static function fault_events(): array {
		// id 倒序形态（EventsTable 查回序）：id 10 > 8 > 6 > 4。
		return array(
			array(
				'id'       => 10,
				'event_no'   => 'WK-20261112-0010',
				'created_at' => '2026-11-12 08:10:00',
				'gate'       => 'comment',
				'stage'      => 'review',
				'driver'     => 'tencent_tms',
				'verdict'    => 'error',
				'rule_hit'   => 'remote_transport',
				'meta'       => array( 'raw' => array( 'code' => 'FailedOperation', 'message' => 'timeout' ), 'comment_id' => 48 ),
			),
			array(
				'id'       => 8,
				'event_no'   => 'WK-20261112-0008',
				'created_at' => '2026-11-12 08:08:00',
				'gate'       => 'login',
				'stage'      => 'captcha',
				'driver'     => 'tencent_captcha',
				'verdict'    => 'error',
				'rule_hit'   => 'remote_transport',
				'meta'       => array(),
			),
			array(
				'id'       => 6,
				'event_no'   => 'WK-20261112-0006',
				'created_at' => '2026-11-12 08:06:00',
				'gate'       => 'comment',
				'stage'      => 'captcha',
				'driver'     => 'tencent_captcha',
				'verdict'    => 'reject',
				'rule_hit'   => 'remote_semantic',
				'meta'       => array(),
			),
			array(
				'id'       => 4,
				'event_no'   => 'WK-20261112-0004',
				'created_at' => '2026-11-12 08:04:00',
				'gate'       => 'register',
				'stage'      => 'captcha',
				'driver'     => 'aliyun_captcha',
				'verdict'    => 'error',
				'rule_hit'   => 'remote_transport_degraded',
				'meta'       => array(),
			),
		);
	}

	private static function health_events(): array {
		// id 12（停转进入）> 2（解除）——夹在故障行之间验证归并。
		return array(
			array(
				'id'       => 12,
				'event_no'   => 'WK-20261112-0012',
				'created_at' => '2026-11-12 08:12:00',
				'gate'       => 'system',
				'stage'      => 'health',
				'driver'     => 'tencent_tms',
				'verdict'    => 'error',
				'rule_hit'   => 'driver_halted:quota_exhausted',
			),
			array(
				'id'       => 2,
				'event_no'   => 'WK-20261112-0002',
				'created_at' => '2026-11-12 08:02:00',
				'gate'       => 'system',
				'stage'      => 'health',
				'driver'     => 'tencent_tms',
				'verdict'    => 'pass',
				'rule_hit'   => 'driver_resumed',
			),
		);
	}

	private static function driver_labels(): array {
		return array(
			'tencent_tms'     => '腾讯云 TMS 文本审核',
			'tencent_captcha' => '腾讯天御验证码 2.0',
			'aliyun_captcha'  => '阿里云验证码 2.0',
		);
	}

	/* ---------- N1：登录域拦截明细行 ---------- */

	public function test_account_reject_rows_maps_gate_stage_reason_words(): void {
		$rows = InterceptDigest::account_reject_rows( self::account_events() );

		$this->assertCount( 3, $rows );

		$this->assertSame( '登录门', $rows[0]['gate_word'], '门名（对照表 23）' );
		$this->assertSame( '人机验证', $rows[0]['stage_word'], '防线（对照表 12）' );
		$this->assertSame( '没带验证码票据', $rows[0]['reason'], '原因人话（rule_hit 映射）' );
		$this->assertSame( '同一账号连错太多次', $rows[0]['trigger'], '为什么拦=触发归因人话（trigger_source 全集——愿望 4/F13）' );
		$this->assertSame( 'WK-20261112-0003', $rows[0]['event_no'] );
		$this->assertSame( '2026-11-12 08:03:00', $rows[0]['created_at'] );

		$this->assertSame( '注册门', $rows[1]['gate_word'] );
		$this->assertSame( '机器人试探', $rows[1]['stage_word'], 'stub→机器人试探（对照表 12）' );
		$this->assertSame( '票据签名不对', $rows[1]['reason'] );
		$this->assertSame( '', $rows[1]['trigger'], '无 trigger_source 归因=空串（渲染层省略括号注）' );

		$this->assertSame( '找回密码门', $rows[2]['gate_word'] );
		$this->assertSame( '人机验证没通过（返回码 8）', $rows[2]['reason'], 'CaptchaCode=N 族映射（对照表 13-17）' );
		$this->assertSame( '配置为一律要过人机验证', $rows[2]['trigger'] );
	}

	public function test_account_reject_rows_omit_ip_hash_and_account_names(): void {
		$rows = InterceptDigest::account_reject_rows( self::account_events() );

		foreach ( $rows as $row ) {
			$this->assertArrayNotHasKey( 'ip_hash', $row, 'IP 哈希省略（任务口径——PIPL 最小化）' );
			$this->assertArrayNotHasKey( 'username', $row, '无明文账号列（事件表本就不存——agent C E-a）' );
			$this->assertArrayNotHasKey( 'client_ip', $row );
		}
	}

	public function test_account_reject_rows_safe_on_malformed_rows(): void {
		$rows = InterceptDigest::account_reject_rows( array(
			array(), // 全空行。
			array( 'event_no' => 'WK-1', 'created_at' => 't', 'gate' => 'login', 'stage' => 'captcha', 'rule_hit' => '', 'meta' => 'not-an-array' ),
		) );

		$this->assertCount( 2, $rows );
		$this->assertSame( '', $rows[0]['gate_word'], '缺 gate → 空门名（不虚构）' );
		$this->assertSame( '', $rows[0]['reason'] );
		$this->assertSame( '', $rows[1]['trigger'], 'meta 非数组 → 无归因（防御性回落）' );
		$this->assertSame( '登录门', $rows[1]['gate_word'] );
	}

	/* ---------- N2+N3：全闸故障+停转解除合并时间线 ---------- */

	public function test_fault_timeline_rows_merge_two_sources_id_desc(): void {
		$rows = InterceptDigest::fault_timeline_rows( self::fault_events(), self::health_events(), self::driver_labels() );

		$this->assertCount( 6, $rows );
		$this->assertSame(
			array( 12, 10, 8, 6, 4, 2 ),
			array_column( $rows, 'id' ),
			'两源按 id 倒序归并（同一张表的自增主键全局可比）'
		);
	}

	/**
	 * UI 批 3·真机缺陷修正钉：停转行（verdict=error + driver_halted 前缀）同时命中
	 * N2 故障谓词与 N3 健康谓词——两源重复入列须按 id 去重（保首见=最大 id）。
	 * 批 2 SQL 漏选 id 列时归并退化且无法去重（真机 DOM 断言实锤——notes #10 形态）。
	 */
	public function test_fault_timeline_rows_dedupes_halt_rows_matched_by_both_predicates(): void {
		$halt_row = array(
			'id'         => 20,
			'event_no'   => 'WK-20261112-0020',
			'created_at' => '2026-11-12 08:20:00',
			'gate'       => 'system',
			'stage'      => 'health',
			'driver'     => 'tencent_tms',
			'verdict'    => 'error',
			'rule_hit'   => 'driver_halted:quota_exhausted',
		);
		$health_copy          = $halt_row; // 同一行（同 id）在 N3 健康时间线重复出现。
		$health_copy['meta']  = array();

		$faults = array( $halt_row, array(
			'id'       => 10,
			'event_no'   => 'WK-20261112-0010',
			'created_at' => '2026-11-12 08:10:00',
			'gate'       => 'comment',
			'stage'      => 'review',
			'driver'     => 'tencent_tms',
			'verdict'    => 'error',
			'rule_hit'   => 'remote_transport',
			'meta'       => array(),
		) );

		$rows = InterceptDigest::fault_timeline_rows( $faults, array( $health_copy ), self::driver_labels() );

		$this->assertCount( 2, $rows, '同 id 双命中只渲染一次（去重保首见=最大 id）' );
		$this->assertSame( array( 20, 10 ), array_column( $rows, 'id' ) );

		// id 缺位（防御形态）不去重不虚构。
		$no_id = array( 'event_no' => 'WK-X', 'created_at' => 't', 'gate' => 'system', 'stage' => 'health', 'driver' => 'x', 'verdict' => 'pass', 'rule_hit' => 'driver_resumed' );
		$rows  = InterceptDigest::fault_timeline_rows( array( $no_id, $no_id ), array(), self::driver_labels() );
		$this->assertCount( 2, $rows, 'id=0 防御形态不去重（不虚构序键）' );
	}

	public function test_fault_timeline_rows_health_words_and_driver_labels(): void {
		$rows = InterceptDigest::fault_timeline_rows( self::fault_events(), self::health_events(), self::driver_labels() );

		$halt = $rows[0];
		$this->assertSame( 12, $halt['id'] );
		$this->assertSame( '服务商健康', $halt['gate_word'], 'system 门名（ Labels::gate_word 新收录键）' );
		$this->assertSame( '腾讯云 TMS 文本审核', $halt['driver_label'], '驱动 id → registry 人话名' );
		$this->assertSame( '当日额度用完', $halt['rule_word'], 'rule_hit 人话（对照表）' );
		$this->assertSame( '今日暂停，防线跳过它继续', $halt['handling'] );
		$this->assertSame( array(), $halt['raw'], '健康行无 meta.raw' );

		$resume = $rows[5];
		$this->assertSame( 2, $resume['id'] );
		$this->assertSame( '服务商恢复正常', $resume['handling'], '解除留痕人话（对照表 driver_resumed）' );
		$this->assertSame( '服务商恢复正常', $resume['rule_word'] );
	}

	public function test_fault_timeline_rows_handling_per_shape(): void {
		$rows = InterceptDigest::fault_timeline_rows( self::fault_events(), array(), self::driver_labels() );

		$review_error = $rows[0]; // id 10：评论门 review 阶段 error。
		$this->assertSame( '评论门', $review_error['gate_word'] );
		$this->assertSame( '先收进待审区，等你复核', $review_error['handling'], '云端故障收口待审（v1.4 口径延续）' );
		$this->assertSame( array( 'code' => 'FailedOperation', 'message' => 'timeout' ), $review_error['raw'], 'meta.raw 透传（展开行数据源）' );

		$login_error = $rows[1]; // id 8：登录门 captcha 阶段 error（block 拦下）。
		$this->assertSame( '登录门', $login_error['gate_word'] );
		$this->assertSame( '拦下该次尝试（故障兜底）', $login_error['handling'], 'fail-closed 主语化' );

		$captcha_reject_fault = $rows[2]; // id 6：评论门 captcha 阶段以 reject 落库的远端故障。
		$this->assertSame( '当场拦下（故障兜底）', $captcha_reject_fault['handling'] );
		$this->assertSame( '服务商拒绝了（查配置或欠费）', $captcha_reject_fault['rule_word'] );

		$degraded = $rows[3]; // id 4：pass_limited 降级放行。
		$this->assertSame( '注册门', $degraded['gate_word'] );
		$this->assertSame( '故障降级放行（真访客不受影响）', $degraded['handling'] );
	}

	public function test_fault_timeline_rows_unknown_driver_falls_back_to_id(): void {
		$rows = InterceptDigest::fault_timeline_rows( array( array(
			'id' => 99, 'event_no' => 'WK-20261112-0099', 'created_at' => 't',
			'gate' => 'comment', 'stage' => 'review', 'driver' => 'ghost_driver',
			'verdict' => 'error', 'rule_hit' => 'remote_transport', 'meta' => array(),
		) ), array(), self::driver_labels() );

		$this->assertSame( 'ghost_driver', $rows[0]['driver_label'], '未注册驱动 id 原样显示（不虚构人话名）' );
	}

	/* ---------- handling_word：组合映射逐例 ---------- */

	public function test_handling_word_full_matrix(): void {
		$this->assertSame( '今日暂停，防线跳过它继续', InterceptDigest::handling_word( 'system', 'health', 'error', 'driver_halted:quota_exhausted' ) );
		$this->assertSame( '今日暂停，防线跳过它继续', InterceptDigest::handling_word( 'system', 'health', 'error', 'driver_halted:config_error' ) );
		$this->assertSame( '服务商恢复正常', InterceptDigest::handling_word( 'system', 'health', 'pass', 'driver_resumed' ) );
		$this->assertSame( '先收进待审区，等你复核', InterceptDigest::handling_word( 'comment', 'review', 'error', 'remote_transport' ) );
		$this->assertSame( '拦下该次尝试（故障兜底）', InterceptDigest::handling_word( 'login', 'captcha', 'error', 'remote_transport' ) );
		$this->assertSame( '拦下该次尝试（故障兜底）', InterceptDigest::handling_word( 'lostpassword', 'captcha', 'error', 'contract_mismatch' ) );
		$this->assertSame( '当场拦下（故障兜底）', InterceptDigest::handling_word( 'comment', 'captcha', 'reject', 'remote_semantic' ) );
		$this->assertSame( '当场拦下（故障兜底）', InterceptDigest::handling_word( 'comment', 'captcha', 'reject', 'remote_parse' ) );
		$this->assertSame( '故障降级放行（真访客不受影响）', InterceptDigest::handling_word( 'comment', 'captcha', 'error', 'remote_transport_degraded' ) );
		$this->assertSame( '故障降级放行（真访客不受影响）', InterceptDigest::handling_word( 'login', 'captcha', 'error', 'remote_transport_degraded' ) );
		$this->assertSame( '已按兜底策略处理', InterceptDigest::handling_word( 'comment', 'ip', 'error', '' ), '缺省兜底（状态码不出站长文案）' );
	}

	/* ---------- 今日分布行组装（N4 行侧——人话映射由 Labels 承担，此处钉空类目归并） ---------- */

	public function test_reject_distribution_keys_map_empty_rule_hit(): void {
		// 与 today_fault_distribution 同款空键归并（'(无类别)'）——纯行侧。
		$rows = array(
			array( 'rule_hit' => 'banned_word', 'n' => '2' ),
			array( 'rule_hit' => '', 'n' => 1 ),
			array( 'rule_hit' => null, 'n' => '3' ),
		);
		$dist = InterceptDigest::distribution_from_rows( $rows );
		$this->assertSame( array( 'banned_word' => 2, '(无类别)' => 4 ), $dist, '空/缺 rule_hit 归并单键（计数强转 int）' );
	}
}
