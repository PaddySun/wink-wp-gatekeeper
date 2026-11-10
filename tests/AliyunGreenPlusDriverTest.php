<?php
/**
 * AliyunGreenPlusDriver 单元测试（05 §2.5 文本审核增强版 PLUS【v1.5】+ 02 §2.2 双 Service 分送例外）。
 *
 * 金测试 = L0-ali-green-pro 实测返回形态（smoke-ali-green-pro.php 7/7 用例响应夹具脱敏后入测：
 * nonLabel 伪标签 / pt_* 标签 + Confidence 100 + RiskWords / RiskLevel 四档 / Code=400 空参拒）。
 *
 * 覆盖：
 *   - registry 七维注册（api_version=green-2022-03-02 / privacy 维同源 / settings required 四字段）；
 *   - is_configured（AK 对 + 双 Service 名——黄态「Service 名配置为空」的配置门槛）；
 *   - 截断（content ≤600 字）+ payload 形态（Service/ServiceParameters form body，L0 实调形态）；
 *   - error_map（body Code 级：400/408→semantic、500/581/588→transport、未知 Code→契约哨兵）；
 *   - 双 Service 分送（昵称→nickname_detection_pro 先行快短路；邮箱+正文→comment_detection_pro；
 *     OR 取严；单通道故障不吞另一通道；双通道全故障→error）；
 *   - 双轨判定映射（RiskLevel none/low→pass / medium→review / high→reject；nonLabel 剔除；
 *     兜底 RiskLevel 空 + 有标签→review；unknown RiskLevel→review fail-safe）；
 *   - F36 兼容回退（dual_dispatch=false 单调拼接串；include_author_fields=false 单调仅正文）；
 *   - probe_credentials（不计 usage、无 required 哨兵、良性短文本）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\DriverRegistry;
use WinkGatekeeper\Drivers\Review\AliyunGreenPlusDriver;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Verdict;

final class AliyunGreenPlusDriverTest extends TestCase {

	/* ---------- 工具 ---------- */

	/**
	 * 假传输器客户端：按调用序返回预设原始响应（并捕获每次调用形态）。
	 *
	 * @param array<int, array> $raws 调用序响应列表（耗尽后重复末项）。
	 * @param array<int, array> $log  捕获出参（引用）。
	 */
	private static function client_sequence( array $raws, array &$log = array() ): RemoteClient {
		return new RemoteClient(
			static function ( string $url, array $args ) use ( &$log, &$raws ) {
				$log[] = array( $url, $args );
				$raw   = count( $raws ) > 1 ? array_shift( $raws ) : $raws[0];
				return $raw;
			},
			null,
			static function (): void {}
		);
	}

	private static function raw_ok( array $data, int $code = 200 ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode(
				array(
					'RequestId' => 'REQ-TEST-1',
					'Message'   => 'OK',
					'Data'      => $data,
					'Code'      => $code,
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			),
		);
	}

	private static function clean_data( string $data_id = 'wk-comment-abc' ): array {
		// L0 用例①/③a 实测形态：干净文本 Result=单条 nonLabel 伪标签。
		return array(
			'Result'   => array(
				array( 'Description' => '未检测出风险', 'Label' => 'nonLabel' ),
			),
			'DataId'   => $data_id,
			'RiskLevel' => 'none',
		);
	}

	private static function risky_data( string $risk = 'high' ): array {
		// L0 用例②/③b 实测形态：广告导流样文 pt_* 命中（Confidence 100 + RiskWords）。
		return array(
			'Result'   => array(
				array(
					'RiskWords'     => '加微信,加&微信',
					'Description'   => '疑似站外引流',
					'Confidence'    => 100,
					'Label'         => 'pt_to_sites',
					'RiskPositions' => array(),
				),
				array(
					'RiskWords'     => '加微,福利,abc123',
					'Description'   => '疑似引流广告号',
					'Confidence'    => 100,
					'Label'         => 'pt_to_contact',
					'RiskPositions' => array(),
				),
			),
			'DataId'   => 'wk-comment-abc',
			'RiskLevel' => $risk,
		);
	}

	private static function driver( ?array $green = null, ?RemoteClient $client = null, array $settings_extra = array() ): AliyunGreenPlusDriver {
		$green = $green ?? array(
			'access_key_id'     => 'LTAItest',
			'access_key_secret' => 'secret',
		);
		$settings = array_merge( array(
			'review' => array(
				'cloud' => array( 'aliyun_green' => $green ),
			),
		), $settings_extra );
		return new AliyunGreenPlusDriver(
			$settings,
			$client ?? self::client_sequence( array(
				array( 'response' => array( 'code' => 500 ), 'body' => '' ),
			) )
		);
	}

	/* ---------- registry / is_configured ---------- */

	public function test_registry_entry_shape(): void {
		$entry = AliyunGreenPlusDriver::registry_entry();
		$this->assertSame( 'aliyun_green', $entry['id'] );
		$this->assertSame( 'review', $entry['type'] );
		$this->assertSame( AliyunGreenPlusDriver::class, $entry['class'] );
		// api_version 与 x-acs-version 同源单点（B3 InvalidVersion 真机教训收口口径：
		// 05 §2.5 原文 'green-2022-03-02' 为产品线命名混写——实际版本串 '2022-03-02'）。
		$this->assertSame( '2022-03-02', $entry['api_version'] );
		$this->assertSame( $entry['api_version'], AliyunGreenPlusDriver::VERSION );
		$this->assertSame( 'usage', $entry['billing'] );
		// privacy 第七维 = DriverPrivacy::entry 同源引用（禁双源漂移）。
		$this->assertSame( \WinkGatekeeper\Settings\DriverPrivacy::entry( 'aliyun_green' ), $entry['privacy'] );
		// required 四字段（05 §2.5 黄态：AK 两键缺失或 Service 名配置为空）。
		$required = \WinkGatekeeper\Settings\DriverConfigSpec::required_fields( $entry );
		$this->assertSame( array( 'access_key_id', 'access_key_secret', 'service_nickname', 'service_comment' ), $required );
		// 无 reject_score（RiskLevel 双轨制，不引入阈值语义——05 §2.5）。
		$this->assertArrayNotHasKey( 'reject_score', $entry['settings'] );
	}

	public function test_registry_registration_accepted(): void {
		$registry = new DriverRegistry();
		$registry->register( AliyunGreenPlusDriver::registry_entry() );
		$this->assertNotNull( $registry->get( 'aliyun_green' ) );
		$this->assertArrayHasKey( 'aliyun_green', $registry->by_type( 'review' ) );
	}

	public function test_is_configured_requires_ak_pair_and_both_services(): void {
		$this->assertTrue( self::driver()->is_configured() );
		$this->assertFalse( self::driver( array( 'access_key_id' => '', 'access_key_secret' => 's', 'service_nickname' => 'n', 'service_comment' => 'c' ) )->is_configured() );
		$this->assertFalse( self::driver( array( 'access_key_id' => 'id', 'access_key_secret' => '', 'service_nickname' => 'n', 'service_comment' => 'c' ) )->is_configured() );
		// Service 名显式清空 = 未配置（黄态列缺，不静默回落默认）。
		$this->assertFalse( self::driver( array( 'access_key_id' => 'id', 'access_key_secret' => 's', 'service_nickname' => '', 'service_comment' => 'c' ) )->is_configured() );
		$this->assertFalse( self::driver( array( 'access_key_id' => 'id', 'access_key_secret' => 's', 'service_nickname' => 'n', 'service_comment' => '' ) )->is_configured() );
	}

	public function test_credentials_trimmed(): void {
		// C1 真机踩坑（env CRLF 残留 → 签名错）同款防御。
		$driver = self::driver( array(
			'access_key_id'     => " LTAItest\r\n",
			'access_key_secret' => " secret \r",
			'service_nickname'  => " nickname_detection_pro \n",
			'service_comment'   => 'comment_detection_pro',
		) );
		$this->assertTrue( $driver->is_configured() );
	}

	public function test_service_names_default_to_pro_when_key_absent(): void {
		// schema merge_defaults 语义：键缺失（存量树未迁移）回落 pro 默认；显式空串不回落。
		$driver = self::driver( array( 'access_key_id' => 'id', 'access_key_secret' => 's' ) );
		$this->assertTrue( $driver->is_configured() );
	}

	/* ---------- 纯逻辑：截断 / payload / error_map ---------- */

	public function test_truncate_under_600_is_noop(): void {
		$out = AliyunGreenPlusDriver::truncate_content( str_repeat( '好', 600 ) );
		$this->assertFalse( $out['truncated'] );
		$this->assertSame( 600, $out['original_chars'] );
	}

	public function test_truncate_over_600_cuts_and_reports(): void {
		$out = AliyunGreenPlusDriver::truncate_content( str_repeat( '好', 601 ) );
		$this->assertTrue( $out['truncated'] );
		$this->assertSame( 601, $out['original_chars'] );
		$this->assertSame( 600, mb_strlen( $out['content'], 'UTF-8' ) );
	}

	public function test_build_payload_form_shape_like_l0_smoke(): void {
		$payload = AliyunGreenPlusDriver::build_payload( 'comment_detection_pro', '正文', 'wk-comment-abc' );
		// form body 形态（L0 实调 + SDK reqBodyType=formData）：Service=..&ServiceParameters=<json>。
		$this->assertMatchesRegularExpression( '/^Service=comment_detection_pro&ServiceParameters=/', $payload );
		parse_str( $payload, $parsed );
		$this->assertSame( 'comment_detection_pro', $parsed['Service'] );
		$sp = json_decode( $parsed['ServiceParameters'], true );
		$this->assertSame( '正文', $sp['content'] );
		$this->assertSame( 'wk-comment-abc', $sp['dataId'] );
		$this->assertArrayNotHasKey( 'accountId', $sp, 'PIPL 最小化：accountId 不送（05 §2.5）' );
	}

	public function test_inspect_body_error_map(): void {
		// body Code 级 error_map（05 §2.5；HTTP 恒 200 为主，错误包在 body）。
		$probe = array( AliyunGreenPlusDriver::class, 'inspect_body' );
		$this->assertSame( RemoteResult::SEMANTIC, call_user_func( $probe, array( 'Code' => 400, 'Message' => 'content is blank' ), '{}' ), '400 参数错→semantic' );
		$this->assertSame( RemoteResult::SEMANTIC, call_user_func( $probe, array( 'Code' => '408', 'Message' => 'PERMISSION_DENY' ), '{}' ), '408 未授权/欠费/未开通→semantic（当日停转）' );
		$this->assertSame( RemoteResult::TRANSPORT, call_user_func( $probe, array( 'Code' => 500 ), '{}' ), '500 服务端→transport' );
		$this->assertSame( RemoteResult::TRANSPORT, call_user_func( $probe, array( 'Code' => '581' ), '{}' ), '581 超时→transport' );
		$this->assertSame( RemoteResult::TRANSPORT, call_user_func( $probe, array( 'Code' => 588 ), '{}' ), '588 超配额→transport 变体' );
		$this->assertNull( call_user_func( $probe, array( 'Code' => 200 ), '{}' ), '200 成功→交 required 收口' );
		$this->assertNull( call_user_func( $probe, array( 'Code' => 999 ), '{}' ), '未知 Code→契约哨兵' );
		$this->assertNull( call_user_func( $probe, null, '' ) );
		$this->assertNull( call_user_func( $probe, array(), '{}' ), '无 Code 字段→兜底' );
	}

	/* ---------- check()：双 Service 分送请求形态 ---------- */

	public function test_dual_dispatch_sends_nickname_then_comment(): void {
		$log = array();
		$client = self::client_sequence( array(
			self::raw_ok( self::clean_data( 'wk-nickname-x' ) ),
			self::raw_ok( self::clean_data( 'wk-comment-x' ) ),
		), $log );

		$meta    = array( 'author' => '山间清风', 'email' => 'a@b.c', 'content' => '这篇文章写得真好' );
		$verdict = self::driver( null, $client )->check( "山间清风\na@b.c\n这篇文章写得真好", $meta );

		$this->assertSame( Verdict::PASS, $verdict->verdict() );
		$this->assertCount( 2, $log, '双通道分送=两调（先昵称后正文）' );

		// 第一调：昵称→nickname_detection_pro。
		list( $url1, $args1 ) = $log[0];
		$this->assertSame( 'https://green-cip.cn-shanghai.aliyuncs.com/', $url1 );
		$this->assertSame( 'TextModerationPlus', $args1['headers']['x-acs-action'] );
		$this->assertSame( '2022-03-02', $args1['headers']['x-acs-version'] );
		$this->assertSame( 'green-cip.cn-shanghai.aliyuncs.com', $args1['headers']['host'] );
		$this->assertSame( 'application/x-www-form-urlencoded', $args1['headers']['content-type'], 'content-type 参与签名（formData 形态）' );
		$this->assertMatchesRegularExpression( '/^ACS3-HMAC-SHA256 Credential=LTAItest,/', $args1['headers']['authorization'] );
		parse_str( (string) $args1['body'], $body1 );
		$this->assertSame( 'nickname_detection_pro', $body1['Service'] );
		$this->assertSame( '山间清风', json_decode( $body1['ServiceParameters'], true )['content'], '昵称单独一调' );

		// 第二调：正文→comment_detection_pro（【真机修正】邮箱不并入——pt_to_contact 对邮箱
		// 地址本身误杀，IMPL-B5 §3 偏差 3；邮箱防线在 LocalRules 拼接串）。
		list( , $args2 ) = $log[1];
		parse_str( (string) $args2['body'], $body2 );
		$this->assertSame( 'comment_detection_pro', $body2['Service'] );
		$this->assertSame( '这篇文章写得真好', json_decode( $body2['ServiceParameters'], true )['content'] );
		$this->assertStringNotContainsString( 'a@b.c', (string) $args2['body'], '邮箱地址绝不进阿里送审串（真机误杀回归钉）' );
	}

	public function test_dual_dispatch_data_id_per_channel(): void {
		$log = array();
		$client = self::client_sequence( array(
			self::raw_ok( self::clean_data() ),
			self::raw_ok( self::clean_data() ),
		), $log );
		self::driver( null, $client )->check( "昵称\na@b.c\n正文", array( 'author' => '昵称', 'email' => 'a@b.c', 'content' => '正文' ) );

		foreach ( $log as $i => $call ) {
			parse_str( (string) $call[1]['body'], $body );
			$data_id = json_decode( $body['ServiceParameters'], true )['dataId'];
			$this->assertLessThanOrEqual( 64, strlen( $data_id ), 'dataId ≤64 字符（05 §2.5）' );
			$this->assertMatchesRegularExpression( '/^wk-(nickname|comment)-[0-9a-f]{12}$/', $data_id );
		}
	}

	public function test_nickname_reject_short_circuits_before_comment_call(): void {
		$log = array();
		$client = self::client_sequence( array(
			self::raw_ok( self::risky_data( 'high' ) ), // 昵称通道 high → reject。
			self::raw_ok( self::clean_data() ),         // 不应被消费。
		), $log );

		$meta    = array( 'author' => '日结兼职加V:abc888', 'email' => 'a@b.c', 'content' => '正常正文' );
		$verdict = self::driver( null, $client )->check( "日结兼职加V:abc888\na@b.c\n正常正文", $meta );

		$this->assertSame( Verdict::REJECT, $verdict->verdict() );
		$this->assertCount( 1, $log, '先昵称后正文（快短路）——昵称 reject 即终止，不烧第二调' );
		$this->assertSame( array( 'pt_to_sites', 'pt_to_contact' ), $verdict->hits(), 'hits=全部 Label' );
		$this->assertSame( 'pt_to_sites,疑似站外引流', $verdict->reason(), 'reason=首条 Label,Description（05 §2.5）' );
	}

	public function test_comment_channel_reject_when_nickname_clean(): void {
		$log = array();
		$client = self::client_sequence( array(
			self::raw_ok( self::clean_data() ),
			self::raw_ok( self::risky_data( 'high' ) ),
		), $log );

		$meta    = array( 'author' => '山间清风', 'email' => 'a@b.c', 'content' => '限时福利！加微信 abc123 领取兼职' );
		$verdict = self::driver( null, $client )->check( "山间清风\na@b.c\n限时福利！加微信 abc123 领取兼职", $meta );

		$this->assertSame( Verdict::REJECT, $verdict->verdict() );
		$this->assertSame( 'pt_to_sites,疑似站外引流', $verdict->reason() );
	}

	public function test_medium_review_or_semantics(): void {
		// 昵称 medium（review 不短路）+ 正文 pass → 整体 review（最严有效判定）。
		$log = array();
		$client = self::client_sequence( array(
			self::raw_ok( self::risky_data( 'medium' ) ),
			self::raw_ok( self::clean_data() ),
		), $log );

		$verdict = self::driver( null, $client )->check( "昵称\na@b.c\n正文", array( 'author' => '昵称', 'email' => 'a@b.c', 'content' => '正文' ) );

		$this->assertSame( Verdict::REVIEW, $verdict->verdict() );
		$this->assertSame( array( 'pt_to_sites', 'pt_to_contact' ), $verdict->hits() );
	}

	public function test_clean_response_strips_nonlabel_pseudo_label(): void {
		// L0 实测修正：干净文本 Result 返回单条 nonLabel 伪标签——判定收口必须先剔除。
		$log = array();
		$client = self::client_sequence( array(
			self::raw_ok( self::clean_data() ),
			self::raw_ok( self::clean_data() ),
		), $log );

		$verdict = self::driver( null, $client )->check( "昵称\na@b.c\n正文", array( 'author' => '昵称', 'email' => 'a@b.c', 'content' => '正文' ) );

		$this->assertSame( Verdict::PASS, $verdict->verdict() );
		$this->assertSame( array(), $verdict->hits(), 'nonLabel 伪标签剔除后零标签' );
	}

	public function test_fallback_empty_risk_level_with_labels_reviews(): void {
		// 兜底：RiskLevel 缺失（空）但存在非 nonLabel 标签 → review（从严不误杀不直接拒）。
		$data            = self::risky_data( '' );
		$data['RiskLevel'] = '';
		$log    = array();
		$client = self::client_sequence( array(
			self::raw_ok( $data ),
			self::raw_ok( self::clean_data() ),
		), $log );

		$verdict = self::driver( null, $client )->check( "昵称\na@b.c\n正文", array( 'author' => '昵称', 'email' => 'a@b.c', 'content' => '正文' ) );
		$this->assertSame( Verdict::REVIEW, $verdict->verdict(), '兜底 review（从严）' );
	}

	public function test_unknown_risk_level_failsafe_review(): void {
		// RiskLevel 枚举漂移（厂商改版形态）→ fail-safe review + meta 标注（TMS unknown_suggestion 先例）。
		$data = self::clean_data();
		$data['RiskLevel'] = 'mystery';
		$log    = array();
		$client = self::client_sequence( array(
			self::raw_ok( $data ),
			self::raw_ok( self::clean_data() ),
		), $log );

		$verdict = self::driver( null, $client )->check( "昵称\na@b.c\n正文", array( 'author' => '昵称', 'email' => 'a@b.c', 'content' => '正文' ) );
		$this->assertSame( Verdict::REVIEW, $verdict->verdict() );
		$this->assertSame( 'mystery', $verdict->reason() );
	}

	/* ---------- check()：单通道故障不吞另一通道 ---------- */

	public function test_single_channel_error_does_not_swallow_other_channel(): void {
		// 昵称通道 500（transport 终态）+ 正文通道 pass → 有效判定照常产出，error 落 meta。
		$log = array();
		$client = self::client_sequence( array(
			array( 'response' => array( 'code' => 500 ), 'body' => '' ),
			self::raw_ok( self::clean_data() ),
		), $log );

		$verdict = self::driver( null, $client )->check( "昵称\na@b.c\n正文", array( 'author' => '昵称', 'email' => 'a@b.c', 'content' => '正文' ) );

		$this->assertSame( Verdict::PASS, $verdict->verdict(), '一通道故障不吞另一通道的有效判定' );
	}

	public function test_single_channel_semantic_error_other_reject_still_rejects(): void {
		// 昵称通道 408 PERMISSION_DENY（semantic 终态）+ 正文通道 high → reject 仍成立。
		$log = array();
		$client = self::client_sequence( array(
			array( 'response' => array( 'code' => 200 ), 'body' => (string) json_encode( array( 'Code' => 408, 'Message' => 'PERMISSION_DENY' ) ) ),
			self::raw_ok( self::risky_data( 'high' ) ),
		), $log );

		$verdict = self::driver( null, $client )->check( "昵称\na@b.c\n正文", array( 'author' => '昵称', 'email' => 'a@b.c', 'content' => '正文' ) );
		$this->assertSame( Verdict::REJECT, $verdict->verdict() );
	}

	public function test_both_channels_error_returns_error_verdict(): void {
		// 双通道全故障 → error（链上 failover，闸按云端全故障收口）。
		$log = array();
		$client = self::client_sequence( array(
			array( 'response' => array( 'code' => 200 ), 'body' => (string) json_encode( array( 'Code' => 408, 'Message' => 'PERMISSION_DENY' ) ) ),
			array( 'response' => array( 'code' => 200 ), 'body' => (string) json_encode( array( 'Code' => 408, 'Message' => 'PERMISSION_DENY' ) ) ),
		), $log );

		$driver  = self::driver( null, $client );
		$verdict = $driver->check( "昵称\na@b.c\n正文", array( 'author' => '昵称', 'email' => 'a@b.c', 'content' => '正文' ) );

		$this->assertSame( Verdict::ERROR, $verdict->verdict() );
		$this->assertSame( 'remote_semantic', $verdict->reason(), '闸侧当日停转按 meta.category=semantic 判定' );
		$meta = $driver->last_check_meta();
		$this->assertSame( 'semantic', $meta['category'] );
		$this->assertSame( 200, $meta['status'] );
		$this->assertArrayHasKey( 'raw', $meta, '错误可观测性：meta.raw 脱敏快照（R4 §D.1）' );
	}

	/* ---------- check()：last_check_meta 形态 ---------- */

	public function test_last_meta_records_risk_levels_and_labels(): void {
		$log = array();
		$client = self::client_sequence( array(
			self::raw_ok( self::risky_data( 'high' ) ),
			self::raw_ok( self::clean_data() ),
		), $log );

		$driver  = self::driver( null, $client );
		$verdict = $driver->check( "日结兼职加V\na@b.c\n正文", array( 'author' => '日结兼职加V', 'email' => 'a@b.c', 'content' => '正文' ) );

		$this->assertSame( Verdict::REJECT, $verdict->verdict() );
		$meta = $driver->last_check_meta();
		$this->assertSame( 'dual', $meta['dispatch'] );
		$this->assertSame( 'high', $meta['nickname']['risk_level'] );
		$this->assertSame( 'pt_to_sites', $meta['nickname']['labels'][0]['label'] );
		$this->assertSame( 100, $meta['nickname']['labels'][0]['confidence'], 'meta 记 Confidence（展示用，不参与收口）' );
		$this->assertSame( '加微信,加&微信', $meta['nickname']['labels'][0]['risk_words'], 'meta 记 RiskWords 摘要' );
		$this->assertSame( 'REQ-TEST-1', $meta['nickname']['request_id'] );
	}

	public function test_truncation_marked_in_meta(): void {
		$log = array();
		$client = self::client_sequence( array(
			self::raw_ok( self::clean_data() ),
			self::raw_ok( self::clean_data() ),
		), $log );

		$long   = str_repeat( '好', 700 );
		$driver = self::driver( null, $client );
		$driver->check( "昵称\na@b.c\n" . $long, array( 'author' => '昵称', 'email' => 'a@b.c', 'content' => $long ) );

		$meta = $driver->last_check_meta();
		$this->assertTrue( $meta['truncated'] );
		// comment 通道送审串 = 正文原值（邮箱不并入——真机修正）= 700 字（截断按通道串计量）。
		$this->assertSame( 700, $meta['original_chars'] );
		// 实际发送体已截断到 600 字。
		parse_str( (string) $log[1][1]['body'], $body );
		$this->assertSame( 600, mb_strlen( json_decode( $body['ServiceParameters'], true )['content'], 'UTF-8' ) );
	}

	public function test_usage_counted_per_call(): void {
		$usage = array();
		$client = new RemoteClient(
			static function () {
				return array( 'response' => array( 'code' => 200 ), 'body' => (string) json_encode( array( 'Code' => 408, 'Message' => 'PERMISSION_DENY' ) ) );
			},
			static function ( string $key ) use ( &$usage ): void {
				$usage[] = $key;
			},
			static function (): void {}
		);
		$driver  = self::driver( null, $client );
		$verdict = $driver->check( "昵称\na@b.c\n正文", array( 'author' => '昵称', 'email' => 'a@b.c', 'content' => '正文' ) );

		$this->assertSame( Verdict::ERROR, $verdict->verdict() );
		$this->assertSame( array( 'aliyun_green', 'aliyun_green' ), $usage, '双通道分送两调均计入 usage（成本 ×2 如实计量）' );
	}

	public function test_empty_content_defense_skips_remote(): void {
		$log = array();
		$client = self::client_sequence( array( self::raw_ok( self::clean_data() ) ), $log );

		$driver  = self::driver( null, $client );
		$verdict = $driver->check( "\n\n", array( 'author' => '', 'email' => '', 'content' => '' ) );

		$this->assertSame( Verdict::PASS, $verdict->verdict(), '本地防呆：全空内容零外发（400 语义模糊不触达）' );
		$this->assertCount( 0, $log );
		$this->assertTrue( ! empty( $driver->last_check_meta()['skipped'] ) );
	}

	/* ---------- F36 兼容回退 ---------- */

	public function test_dual_dispatch_off_falls_back_to_single_concat_call(): void {
		$log = array();
		$client = self::client_sequence( array( self::raw_ok( self::clean_data() ) ), $log );

		$green  = array( 'access_key_id' => 'id', 'access_key_secret' => 's', 'dual_dispatch' => false );
		$concat = "昵称\na@b.c\n正文";
		$driver = self::driver( $green, $client );
		$driver->check( $concat, array( 'author' => '昵称', 'email' => 'a@b.c', 'content' => '正文' ) );

		$this->assertCount( 1, $log, 'F36 兼容回退：拼接串单调一调' );
		parse_str( (string) $log[0][1]['body'], $body );
		$this->assertSame( 'comment_detection_pro', $body['Service'] );
		$this->assertSame( $concat, json_decode( $body['ServiceParameters'], true )['content'], '拼接串原样送审（闸统一拼装口径）' );
		$this->assertSame( 'single', $driver->last_check_meta()['dispatch'] );
	}

	public function test_include_author_fields_off_single_body_call(): void {
		// 02 §2.2：include_author_fields 关时单调仅正文（回退到通用拼接口径）。
		$log = array();
		$client = self::client_sequence( array( self::raw_ok( self::clean_data() ) ), $log );

		$settings_extra = array( 'review' => array(
			'include_author_fields' => false,
			'cloud'                 => array( 'aliyun_green' => array( 'access_key_id' => 'id', 'access_key_secret' => 's' ) ),
		) );
		$driver = self::driver( null, $client, $settings_extra );
		$driver->check( '仅正文', array( 'author' => '昵称', 'email' => 'a@b.c', 'content' => '仅正文' ) );

		$this->assertCount( 1, $log );
		parse_str( (string) $log[0][1]['body'], $body );
		$this->assertSame( 'comment_detection_pro', $body['Service'] );
		$this->assertSame( '仅正文', json_decode( $body['ServiceParameters'], true )['content'] );
	}

	public function test_dual_dispatch_without_author_skips_nickname_channel(): void {
		// 昵称为空（匿名/程序化提交）→ 昵称通道零内容不发（空串防呆），单调 comment 通道。
		$log = array();
		$client = self::client_sequence( array( self::raw_ok( self::clean_data() ) ), $log );

		$driver = self::driver( null, $client );
		$driver->check( "a@b.c\n正文", array( 'author' => '', 'email' => 'a@b.c', 'content' => '正文' ) );

		$this->assertCount( 1, $log );
		parse_str( (string) $log[0][1]['body'], $body );
		$this->assertSame( 'comment_detection_pro', $body['Service'] );
	}

	/* ---------- probe_credentials ---------- */

	public function test_probe_credentials_benign_text_not_counted(): void {
		$log = array();
		$usage = array();
		$client = new RemoteClient(
			static function ( string $url, array $args ) use ( &$log ) {
				$log[] = array( $url, $args );
				return self::raw_ok( self::clean_data( 'probe' ) );
			},
			static function ( string $key ) use ( &$usage ): void {
				$usage[] = $key;
			},
			static function (): void {}
		);

		$result = self::driver( null, $client )->probe_credentials();

		$this->assertTrue( $result->is_ok() );
		$this->assertCount( 1, $log );
		parse_str( (string) $log[0][1]['body'], $body );
		$this->assertSame( 'comment_detection_pro', $body['Service'], '探测走 comment 通道（05 §2.5 凭据自检）' );
		$this->assertSame( '凭据自检', json_decode( $body['ServiceParameters'], true )['content'], '正常短文本探测（不用空 content——400 语义模糊）' );
		$this->assertSame( array(), $usage, '探测调用不计 usage（Tab③ 现场诊断）' );
	}

	public function test_probe_not_blocked_by_missing_service_contract(): void {
		// 探测不带 required 契约哨兵（API 级错误形态原样透出给三态判定层）。
		$client = new RemoteClient(
			static function () {
				return array( 'response' => array( 'code' => 400 ), 'body' => (string) json_encode( array( 'Code' => 'SignatureDoesNotMatch', 'Message' => 'mismatch' ) ) );
			},
			null,
			static function (): void {}
		);
		$result = self::driver( null, $client )->probe_credentials();
		$this->assertFalse( $result->is_ok() );
		$this->assertSame( 'semantic', $result->category() );
		$this->assertStringContainsString( 'SignatureDoesNotMatch', $result->body() );
	}
}
