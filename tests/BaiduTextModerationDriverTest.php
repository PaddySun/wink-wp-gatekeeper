<?php
/**
 * BaiduTextModerationDriver 单元测试（05 §2.4 百度行——L0-baidu-2026-11-09 实测定稿）。
 *
 * 金测试 = L0 实测响应形态（smoke-baidu.php 4/4 用例响应夹具脱敏后入测：
 * token 流 expires_in=2592000 / 正常文本 conclusionType=1 / 广告文本 conclusionType=2
 * + data[].type/subType labels / error_code 错误形态）。
 *
 * 覆盖：
 *   - registry 七维注册（privacy 维同源 / settings required api_key+secret_key）；
 *   - is_configured（API Key + Secret Key 双键）；
 *   - 截断（text ≤20000 字节、UTF-8 字节安全截断）+ payload 形态（text=urlencode form body）；
 *   - error_map（body error_code 级：110/111/100/6/17/19→semantic、2/3/4/5/18→transport、
 *     未知码/无 error_code→契约哨兵兜底）；
 *   - token 流（client_credentials 换 access_token → 30 天 transient 缓存复用——第二次调用
 *     零 token 请求；token 失败不缓存；token TTL = expires_in - 300s 安全边际；110/111 清缓存自愈）；
 *   - 判定映射（conclusionType 1→pass / 2→reject / 3→review / 4→error（fail_mode 闸侧收口）/
 *     未知枚举→review fail-safe；hits=data[].type/subType 人类可读格式）；
 *   - 空内容本地防呆（零外发 pass + meta 标注）；
 *   - probe_credentials（不计 usage、无 required 哨兵、良性样本文本）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\DriverRegistry;
use WinkGatekeeper\Drivers\Review\BaiduTextModerationDriver;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Settings\DriverPrivacy;
use WinkGatekeeper\Verdict;

final class BaiduTextModerationDriverTest extends TestCase {

	/* ---------- 工具 ---------- */

	/**
	 * 假传输器客户端：按调用序返回预设原始响应（并捕获每次调用形态）。
	 *
	 * @param array<int, array> $raws  调用序响应列表（耗尽后重复末项）。
	 * @param array<int, array> $log  捕获出参（引用）。
	 */
	private static function client_sequence( array $raws, array &$log = array(), array &$usage_log = array() ): RemoteClient {
		return new RemoteClient(
			static function ( string $url, array $args ) use ( &$log, &$raws ) {
				$log[] = array( $url, $args );
				$raw   = count( $raws ) > 1 ? array_shift( $raws ) : $raws[0];
				return $raw;
			},
			static function ( string $usage_key ) use ( &$usage_log ) {
				$usage_log[] = $usage_key;
			},
			static function (): void {}
		);
	}

	/**
	 * token 端点成功响应（L0 实测形态：access_token + expires_in=2592000；token 值脱敏占位）。
	 */
	private static function raw_token_ok(): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( array(
				'access_token' => 'test-access-token-1',
				'expires_in'   => 2592000,
			) ),
		);
	}

	/**
	 * 审核端点响应（L0 实测形态：conclusion/conclusionType/log_id/data）。
	 *
	 * @param int    $conclusion_type 1 合规 / 2 不合规 / 3 疑似 / 4 审核失败。
	 * @param string $conclusion      conclusion 原值。
	 */
	private static function raw_censor( int $conclusion_type, string $conclusion, array $data = array(), int $status = 200 ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => (string) json_encode( array(
				'log_id'         => 17942280572500285,
				'conclusion'     => $conclusion,
				'conclusionType' => $conclusion_type,
				'data'           => $data,
			), JSON_UNESCAPED_UNICODE ),
		);
	}

	/**
	 * L0 广告文本用例③响应夹具（脱敏）：conclusionType=2 + 三条 type/subType 标签。
	 */
	private static function ad_data(): array {
		return array(
			array(
				'type'       => 12,
				'subType'    => 1,
				'conclusion' => '不合规',
				'msg'        => '存在不友善内容',
				'hits'       => array(),
			),
			array(
				'type'       => 12,
				'subType'    => 4,
				'conclusion' => '不合规',
				'msg'        => '存在联系方式类推广',
				'hits'       => array( array( 'words' => array( '加微信' ), 'modelInfo' => array() ) ),
			),
			array(
				'type'       => 31,
				'subType'    => 310000,
				'conclusion' => '不合规',
				'msg'        => '暴恐违禁',
				'hits'       => array(),
			),
		);
	}

	/**
	 * 带内存 token 缓存的驱动（token_get/token_set 闭包数组捕获——单测零 WP 依赖）。
	 *
	 * @param array<string, string> $cache     模拟 transient 存储（引用）。
	 * @param array<int, array>     $raws      调用序响应列表。
	 * @param array<int, array>     $log       传输调用捕获（引用）。
	 * @param array<int, string>    $usage_log usage 计量捕获（引用）。
	 */
	private static function driver_with_cache( array &$cache, array $raws, array &$log = array(), array &$usage_log = array() ): BaiduTextModerationDriver {
		$settings = array(
			'review' => array(
				'cloud' => array( 'baidu' => array(
					'api_key'    => 'AK-test',
					'secret_key' => 'SK-test',
					'timeout'    => 8,
				) ),
			),
		);
		return new BaiduTextModerationDriver(
			$settings,
			self::client_sequence( $raws, $log, $usage_log ),
			static function ( string $key ) use ( &$cache ) {
				return $cache[ $key ] ?? null;
			},
			static function ( string $key, string $token, int $ttl ) use ( &$cache ) {
				if ( '' === $token ) {
					unset( $cache[ $key ] ); // 空串 = 清缓存（110/111 自愈）。
					return;
				}
				$cache[ $key ] = $token; // TTL 落值仅供断言（内存缓存不模拟过期）。
			}
		);
	}

	private static function driver( array $raws, array &$log, array &$usage_log, array &$cache ): BaiduTextModerationDriver {
		return self::driver_with_cache( $cache, $raws, $log, $usage_log );
	}

	/* ---------- registry 七维 ---------- */

	public function test_registry_entry_declares_seven_dimensions(): void {
		$entry = BaiduTextModerationDriver::registry_entry();
		$this->assertSame( 'baidu', $entry['id'] );
		$this->assertSame( 'review', $entry['type'] );
		$this->assertSame( BaiduTextModerationDriver::class, $entry['class'] );
		$this->assertSame( 'usage', $entry['billing'], '按量计费档（05 §5 矩阵）' );
		$this->assertSame( 'cn-excellent', $entry['reachability'] );
		$this->assertSame( array( 'body_probe' => 'inspect_body' ), $entry['error_map'] );
		$this->assertSame( 'text_censor-v2', $entry['api_version'], 'L0 定论：v2 即现行版' );
		$this->assertStringContainsString( 'Rk3h6xb3i', $entry['doc_url'], '05 §2.4 官方请求文档' );
		$this->assertSame( DriverPrivacy::entry( 'baidu' ), $entry['privacy'], 'privacy 维与内容表同源（禁双源漂移）' );
		$this->assertFalse( $entry['privacy']['cross_border'], '百度境内服务商不出境' );
	}

	public function test_registry_settings_required_credentials(): void {
		$settings = BaiduTextModerationDriver::registry_entry()['settings'];
		$this->assertSame( 'password', $settings['api_key']['type'], 'API Key password 型（P0-α 教训）' );
		$this->assertSame( 'password', $settings['secret_key']['type'], 'Secret Key password 型' );
		$this->assertTrue( $settings['api_key']['required'] );
		$this->assertTrue( $settings['secret_key']['required'] );
	}

	public function test_registry_registered_into_driver_registry(): void {
		$registry = new DriverRegistry();
		$registry->register( BaiduTextModerationDriver::registry_entry() );
		$this->assertCount( 1, $registry->all() );
		$this->assertSame( 'baidu', $registry->get( 'baidu' )['id'] );
	}

	/* ---------- is_configured ---------- */

	public function test_is_configured_requires_both_keys(): void {
		$cache = array();
		$full  = self::driver_with_cache( $cache, array( self::raw_token_ok() ) );
		$this->assertTrue( $full->is_configured() );

		$settings = array( 'review' => array( 'cloud' => array( 'baidu' => array( 'api_key' => 'AK', 'secret_key' => '' ) ) ) );
		$partial  = new BaiduTextModerationDriver( $settings, self::client_sequence( array() ) );
		$this->assertFalse( $partial->is_configured(), 'Secret Key 缺 = 未配置' );
	}

	/* ---------- 截断（text ≤20000 字节） ---------- */

	public function test_truncate_passthrough_within_limit(): void {
		$content = str_repeat( 'a', 20000 );
		$out     = BaiduTextModerationDriver::truncate_content( $content );
		$this->assertFalse( $out['truncated'] );
		$this->assertSame( 20000, $out['original_bytes'] );
		$this->assertSame( $content, $out['content'] );
	}

	public function test_truncate_byte_safe_multibyte(): void {
		// 7000 个「中」= 21000 字节 > 20000 字节上限——按字节截断且不破 UTF-8。
		$content = str_repeat( '中', 7000 );
		$out     = BaiduTextModerationDriver::truncate_content( $content );
		$this->assertTrue( $out['truncated'] );
		$this->assertSame( 21000, $out['original_bytes'] );
		$this->assertLessThanOrEqual( 20000, strlen( $out['content'] ) );
		$this->assertTrue( mb_check_encoding( $out['content'], 'UTF-8' ), '截断不得产生坏 UTF-8 尾巴' );
	}

	/* ---------- payload 形态（L0 实调：text=urlencode form body） ---------- */

	public function test_build_censor_payload_urlencodes_text(): void {
		$payload = BaiduTextModerationDriver::build_censor_payload( '你好 加微信' );
		$this->assertSame( 'text=' . rawurlencode( '你好 加微信' ), $payload );
	}

	/* ---------- error_map（body error_code 级） ---------- */

	public function test_inspect_body_maps_token_auth_codes_to_semantic(): void {
		foreach ( array( 110, 111 ) as $code ) {
			$body = (string) json_encode( array( 'error_code' => $code, 'error_msg' => 'token invalid' ) );
			$this->assertSame( RemoteResult::SEMANTIC, BaiduTextModerationDriver::inspect_body( json_decode( $body, true ), $body ), "error_code={$code}" );
		}
	}

	public function test_inspect_body_maps_param_and_quota_codes_to_semantic(): void {
		foreach ( array( 100, 6, 17, 19 ) as $code ) {
			$body = (string) json_encode( array( 'error_code' => $code, 'error_msg' => 'x' ) );
			$this->assertSame( RemoteResult::SEMANTIC, BaiduTextModerationDriver::inspect_body( json_decode( $body, true ), $body ), "error_code={$code}" );
		}
	}

	public function test_inspect_body_maps_rate_and_server_codes_to_transport(): void {
		foreach ( array( 2, 3, 4, 5, 18 ) as $code ) {
			$body = (string) json_encode( array( 'error_code' => $code, 'error_msg' => 'x' ) );
			$this->assertSame( RemoteResult::TRANSPORT, BaiduTextModerationDriver::inspect_body( json_decode( $body, true ), $body ), "error_code={$code}" );
		}
	}

	public function test_inspect_body_unknown_code_and_success_fall_through(): void {
		$unknown  = (string) json_encode( array( 'error_code' => 216201, 'error_msg' => 'image-related' ) );
		$this->assertNull( BaiduTextModerationDriver::inspect_body( json_decode( $unknown, true ), $unknown ), '未知码 → 契约哨兵兜底' );
		$ok = (string) json_encode( array( 'conclusion' => '合规', 'conclusionType' => 1 ) );
		$this->assertNull( BaiduTextModerationDriver::inspect_body( json_decode( $ok, true ), $ok ) );
		$this->assertNull( BaiduTextModerationDriver::inspect_body( null, '' ) );
		$this->assertNull( BaiduTextModerationDriver::inspect_body( 'not-array', 'x' ) );
	}

	/* ---------- 判定映射（05 §2.4：conclusionType 1/2/3/4） ---------- */

	public function test_map_conclusion_compliant_to_pass(): void {
		$decoded = json_decode( self::raw_censor( 1, '合规' )['body'], true );
		$mapped  = BaiduTextModerationDriver::map_conclusion( $decoded );
		$this->assertSame( Verdict::PASS, $mapped['verdict'] );
		$this->assertSame( array(), $mapped['hits'] );
		$this->assertSame( 1, $mapped['conclusion_type'] );
	}

	public function test_map_conclusion_non_compliant_to_reject_with_hits(): void {
		$decoded = json_decode( self::raw_censor( 2, '不合规', self::ad_data() )['body'], true );
		$mapped  = BaiduTextModerationDriver::map_conclusion( $decoded );
		$this->assertSame( Verdict::REJECT, $mapped['verdict'] );
		// hits = data[].type/subType 人类可读格式（L0 实测三条标签逐项）。
		$this->assertSame( array(
			'12-文本反作弊(subType=1)',
			'12-文本反作弊(subType=4)',
			'31-暴恐(subType=310000)',
		), $mapped['hits'] );
		$this->assertStringContainsString( '不合规:12-文本反作弊(subType=1)', $mapped['reason'], 'reason = conclusion+首条命中' );
	}

	public function test_map_conclusion_suspect_to_review(): void {
		$decoded = json_decode( self::raw_censor( 3, '疑似', array( array( 'type' => 36, 'subType' => 0 ) ) )['body'], true );
		$mapped  = BaiduTextModerationDriver::map_conclusion( $decoded );
		$this->assertSame( Verdict::REVIEW, $mapped['verdict'] );
	}

	public function test_map_conclusion_censor_failed_to_error(): void {
		$decoded = json_decode( self::raw_censor( 4, '审核失败' )['body'], true );
		$mapped  = BaiduTextModerationDriver::map_conclusion( $decoded );
		$this->assertSame( Verdict::ERROR, $mapped['verdict'], 'conclusionType=4 → error（fail_mode 闸侧收口，05 §2.4）' );
	}

	public function test_map_conclusion_unknown_type_failsafe_review(): void {
		$decoded = json_decode( self::raw_censor( 9, '未知' )['body'], true );
		$mapped  = BaiduTextModerationDriver::map_conclusion( $decoded );
		$this->assertSame( Verdict::REVIEW, $mapped['verdict'], '枚举漂移 → review fail-safe（TMS unknown_suggestion 先例）' );
		$this->assertStringContainsString( 'unknown_conclusion_type', $mapped['reason'] );
	}

	/* ---------- check() 编排：token 流 + 缓存复用 ---------- */

	public function test_check_first_call_fetches_token_then_censors(): void {
		$cache = array();
		$log   = array();
		$usage = array();
		$driver = self::driver( array( self::raw_token_ok(), self::raw_censor( 1, '合规' ) ), $log, $usage, $cache );

		$verdict = $driver->check( '你好，这篇文章写得真好', array() );
		$this->assertSame( Verdict::PASS, $verdict->verdict() );

		$this->assertCount( 2, $log, '首发 = token 请求 + 审核请求' );
		$token_url = $log[0][0];
		$this->assertStringStartsWith( BaiduTextModerationDriver::TOKEN_ENDPOINT . '?', $token_url );
		$this->assertStringContainsString( 'grant_type=client_credentials', $token_url );
		$this->assertStringContainsString( 'client_id=AK-test', $token_url );
		$this->assertStringContainsString( 'client_secret=SK-test', $token_url );

		$censor_url = $log[1][0];
		$this->assertStringStartsWith( BaiduTextModerationDriver::ENDPOINT . '?access_token=test-access-token-1', $censor_url, 'access_token 走 URL 参数（L0 实测形态）' );
		$this->assertSame( 'text=' . rawurlencode( '你好，这篇文章写得真好' ), $log[1][1]['body'], 'text=urlencode form body' );

		$this->assertSame( array( 'baidu' ), $usage, '审核调用计量 usage_key=baidu；token 获取不计审核 usage（04 §6 审核口径）' );
		$this->assertSame( 'miss', $driver->last_check_meta()['token_cache'] );
		$this->assertSame( 1, $driver->last_check_meta()['conclusionType'] );
		$this->assertSame( '合规', $driver->last_check_meta()['conclusion'] );
	}

	public function test_check_second_call_reuses_cached_token_zero_token_requests(): void {
		$cache  = array();
		$log    = array();
		$usage  = array();
		$driver = self::driver( array( self::raw_token_ok(), self::raw_censor( 1, '合规' ) ), $log, $usage, $cache );
		$driver->check( '第一条', array() );

		$before = count( $log );
		$driver->check( '第二条', array() );
		$this->assertCount( $before + 1, $log, '第二次调用零 token 请求（30 天缓存复用）——传输调用仅剩审核一调' );
		$this->assertStringStartsWith( BaiduTextModerationDriver::ENDPOINT, $log[ $before ][0] );
		$this->assertSame( 'hit', $driver->last_check_meta()['token_cache'], 'meta 侧证缓存命中' );
		$this->assertSame( 'test-access-token-1', $cache[ BaiduTextModerationDriver::token_cache_key( 'AK-test' ) ] ?? '', 'token 已落缓存' );
	}

	public function test_token_ttl_has_safety_margin(): void {
		$cache = array();
		$log   = array();
		$ttls  = array();
		$settings = array(
			'review' => array( 'cloud' => array( 'baidu' => array( 'api_key' => 'AK-test', 'secret_key' => 'SK-test' ) ) ),
		);
		$driver = new BaiduTextModerationDriver(
			$settings,
			self::client_sequence( array( self::raw_token_ok(), self::raw_censor( 1, '合规' ) ), $log ),
			static function ( string $key ) use ( &$cache ) {
				return $cache[ $key ] ?? null;
			},
			static function ( string $key, string $token, int $ttl ) use ( &$cache, &$ttls ) {
				$ttls[] = $ttl;
			}
		);
		$driver->check( 'x', array() );
		$this->assertSame( array( 2592000 - 300 ), $ttls, 'TTL = expires_in - 300s 安全边际（L0 实测 expires_in=2592000）' );
	}

	public function test_token_fetch_failure_returns_error_without_censor_call(): void {
		$cache = array();
		$log   = array();
		$usage = array();
		$driver = self::driver( array(
			array( // token 端点 401（鉴权失败）。
				'response' => array( 'code' => 401 ),
				'body'     => (string) json_encode( array( 'error' => 'invalid_client', 'error_description' => 'AK/SK mismatch' ) ),
			),
			self::raw_censor( 1, '合规' ), // 若被误调即败露（log 计数）。
		), $log, $usage, $cache );

		$verdict = $driver->check( 'x', array() );
		$this->assertSame( Verdict::ERROR, $verdict->verdict() );
		$this->assertSame( 'remote_semantic', $verdict->reason() );
		$this->assertCount( 1, $log, 'token 失败不再发审核调用' );
		$this->assertArrayNotHasKey( BaiduTextModerationDriver::token_cache_key( 'AK-test' ), $cache, '失败 token 不缓存' );
	}

	public function test_censor_token_invalid_code_clears_cache_and_self_heals(): void {
		$cache = array( BaiduTextModerationDriver::token_cache_key( 'AK-test' ) => 'stale-token' );
		$log   = array();
		$usage = array();
		$raws  = array(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode( array( 'error_code' => 110, 'error_msg' => 'access_token invalid' ) ),
			),
		);
		$driver = self::driver( $raws, $log, $usage, $cache );

		$verdict = $driver->check( 'x', array() );
		$this->assertSame( Verdict::ERROR, $verdict->verdict(), 'error_code=110 → semantic（闸侧当日停转）' );
		$this->assertSame( 'remote_semantic', $verdict->reason() );
		$this->assertArrayNotHasKey( BaiduTextModerationDriver::token_cache_key( 'AK-test' ), $cache, '110/111 → 清 token 缓存（下次自愈重取）' );
		$this->assertNotNull( $driver->last_check_meta()['raw'] ?? null, '错误 meta 带 raw 脱敏快照' );
	}

	/* ---------- 送审串口径（IMPL-B6 真机实锤偏差 1：邮箱绝不并入——36-广告 360104 误杀） ---------- */

	public function test_check_email_never_enters_payload(): void {
		$cache = array( BaiduTextModerationDriver::token_cache_key( 'AK-test' ) => 'tok' );
		$log   = array();
		$usage = array();
		$driver = self::driver( array( self::raw_censor( 1, '合规' ) ), $log, $usage, $cache );

		$driver->check( "B6测试访客\nb6-pass@example.com\n这篇博客写得真好", array(
			'author' => 'B6测试访客',
			'email'  => 'b6-pass@example.com',
			'content' => '这篇博客写得真好',
		) );

		parse_str( $log[0][1]['body'], $sent );
		$this->assertSame( "B6测试访客\n这篇博客写得真好", $sent['text'], '送审串=「昵称\\n正文」两行——邮箱绝不并入（真机隔离实验：email_only→reject）' );
		$this->assertStringNotContainsString( 'example.com', $log[0][1]['body'], '传输 body 无邮箱痕迹' );
		$this->assertSame( 'author+body', $driver->last_check_meta()['send_fields'] );
	}

	public function test_check_body_only_when_author_fields_off(): void {
		$cache = array( BaiduTextModerationDriver::token_cache_key( 'AK-test' ) => 'tok' );
		$log   = array();
		$usage = array();
		$settings = array(
			'review' => array(
				'include_author_fields' => false,
				'cloud' => array( 'baidu' => array( 'api_key' => 'AK-test', 'secret_key' => 'SK-test' ) ),
			),
		);
		$driver = new BaiduTextModerationDriver(
			$settings,
			self::client_sequence( array( self::raw_censor( 1, '合规' ) ), $log, $usage ),
			static function ( string $key ) use ( &$cache ) {
				return $cache[ $key ] ?? null;
			},
			static function ( string $key, string $token, int $ttl ) use ( &$cache ): void {
				$cache[ $key ] = $token;
			}
		);

		$driver->check( "B6测试访客\nb6-pass@example.com\n这篇博客写得真好", array(
			'author' => 'B6测试访客',
			'email'  => 'b6-pass@example.com',
			'content' => '这篇博客写得真好',
		) );

		parse_str( $log[0][1]['body'], $sent );
		$this->assertSame( '这篇博客写得真好', $sent['text'], 'include_author_fields 关 = 仅正文（F36 回退同口径）' );
		$this->assertSame( 'body_only', $driver->last_check_meta()['send_fields'] );
	}

	public function test_check_author_with_ad_text_still_sent_for_cloud_review(): void {
		// 昵称保留在送审串（广告昵称应被云审捕获——B5 真机昵称通道命中实锤）。
		$cache = array( BaiduTextModerationDriver::token_cache_key( 'AK-test' ) => 'tok' );
		$log   = array();
		$usage = array();
		$driver = self::driver( array( self::raw_censor( 2, '不合规', array( array( 'type' => 12, 'subType' => 4 ) ) ) ), $log, $usage, $cache );
		$driver->check( "日结兼职加V:abc888\nbody@example.com\n正常内容", array(
			'author' => '日结兼职加V:abc888',
			'email'  => 'body@example.com',
			'content' => '正常内容',
		) );
		parse_str( $log[0][1]['body'], $sent );
		$this->assertStringContainsString( '日结兼职加V:abc888', $sent['text'], '昵称随送审（云审面）' );
		$this->assertStringNotContainsString( 'body@example.com', $sent['text'] );
	}

	public function test_check_meta_missing_falls_back_to_concat(): void {
		// meta.content 缺失（单测/兼容调用）→ 闸拼装串原样回退（TMS/LLM 同口径）。
		$cache = array( BaiduTextModerationDriver::token_cache_key( 'AK-test' ) => 'tok' );
		$log   = array();
		$usage = array();
		$driver = self::driver( array( self::raw_censor( 1, '合规' ) ), $log, $usage, $cache );
		$driver->check( "昵称\nmail@example.com\n正文", array() );
		parse_str( $log[0][1]['body'], $sent );
		$this->assertSame( "昵称\nmail@example.com\n正文", $sent['text'], '无 meta 回退拼装串原样（生产闸恒带 content 不经此路径）' );
	}

	/* ---------- 判定落地：reject / review / error ---------- */

	public function test_check_reject_on_ad_text(): void {
		$cache = array( BaiduTextModerationDriver::token_cache_key( 'AK-test' ) => 'tok' );
		$log   = array();
		$usage = array();
		$driver = self::driver( array( self::raw_censor( 2, '不合规', self::ad_data() ) ), $log, $usage, $cache );

		$verdict = $driver->check( '加微信xxx低价代刷粉丝', array() );
		$this->assertSame( Verdict::REJECT, $verdict->verdict() );
		$this->assertSame( array( '12-文本反作弊(subType=1)', '12-文本反作弊(subType=4)', '31-暴恐(subType=310000)' ), $verdict->hits() );
		$this->assertCount( 1, $log, '缓存命中 → 仅审核一调' );
	}

	public function test_check_review_on_suspect(): void {
		$cache = array( BaiduTextModerationDriver::token_cache_key( 'AK-test' ) => 'tok' );
		$log   = array();
		$usage = array();
		$driver = self::driver( array( self::raw_censor( 3, '疑似', array( array( 'type' => 36, 'subType' => 0 ) ) ) ), $log, $usage, $cache );
		$verdict = $driver->check( '疑似样文', array() );
		$this->assertSame( Verdict::REVIEW, $verdict->verdict() );
	}

	public function test_check_conclusion_failed_maps_to_error(): void {
		$cache = array( BaiduTextModerationDriver::token_cache_key( 'AK-test' ) => 'tok' );
		$log   = array();
		$usage = array();
		$driver = self::driver( array( self::raw_censor( 4, '审核失败' ) ), $log, $usage, $cache );
		$verdict = $driver->check( 'x', array() );
		$this->assertSame( Verdict::ERROR, $verdict->verdict(), 'conclusionType=4 → error → 闸按 fail_mode 收口' );
		$this->assertSame( 'remote_conclusion_failed', $verdict->reason() );
	}

	public function test_check_not_configured_defensive_error(): void {
		$settings = array( 'review' => array( 'cloud' => array( 'baidu' => array( 'api_key' => '', 'secret_key' => '' ) ) ) );
		$driver   = new BaiduTextModerationDriver( $settings, self::client_sequence( array() ) );
		$verdict  = $driver->check( 'x', array() );
		$this->assertSame( Verdict::ERROR, $verdict->verdict() );
		$this->assertSame( 'not_configured', $verdict->reason() );
	}

	/* ---------- 空内容本地防呆 ---------- */

	public function test_check_empty_content_passes_without_remote_call(): void {
		$log = array();
		$usage = array();
		$cache = array();
		$driver = self::driver( array( self::raw_token_ok(), self::raw_censor( 1, '合规' ) ), $log, $usage, $cache );
		$verdict = $driver->check( '   ', array() );
		$this->assertSame( Verdict::PASS, $verdict->verdict() );
		$this->assertCount( 0, $log, '全空提交零外发（阿里 skipped 先例同款）' );
		$this->assertSame( 'empty_content', $driver->last_check_meta()['skipped'] ?? null );
	}

	/* ---------- 超长截断入 meta ---------- */

	public function test_check_truncation_marked_in_meta(): void {
		$cache = array( BaiduTextModerationDriver::token_cache_key( 'AK-test' ) => 'tok' );
		$log   = array();
		$usage = array();
		$driver = self::driver( array( self::raw_censor( 1, '合规' ) ), $log, $usage, $cache );
		$driver->check( str_repeat( '中', 7000 ), array() );
		$this->assertTrue( (bool) ( $driver->last_check_meta()['truncated'] ?? false ) );
		$this->assertSame( 21000, $driver->last_check_meta()['original_bytes'] );
	}

	/* ---------- probe_credentials（Tab③ 测试凭据） ---------- */

	public function test_probe_uses_benign_text_without_usage(): void {
		$cache  = array();
		$log    = array();
		$usage  = array();
		$driver = self::driver( array( self::raw_token_ok(), self::raw_censor( 1, '合规' ) ), $log, $usage, $cache );

		$result = $driver->probe_credentials();
		$this->assertTrue( $result->is_ok() );
		$this->assertSame( array(), $usage, '探测不计 usage（Tab③ 现场诊断）' );
		$this->assertSame( 'text=' . rawurlencode( BaiduTextModerationDriver::PROBE_TEXT ), $log[1][1]['body'], '探测用良性样本文本' );
	}

	public function test_probe_token_failure_passes_through_category(): void {
		$cache = array();
		$log   = array();
		$usage = array();
		$driver = self::driver( array(
			array(
				'response' => array( 'code' => 401 ),
				'body'     => (string) json_encode( array( 'error' => 'invalid_client' ) ),
			),
		), $log, $usage, $cache );
		$result = $driver->probe_credentials();
		$this->assertFalse( $result->is_ok() );
		$this->assertSame( RemoteResult::SEMANTIC, $result->category(), 'token 鉴权失败 → semantic（红态判定输入）' );
	}

	/* ---------- token 缓存键 ---------- */

	public function test_token_cache_key_deterministic_and_prefixed(): void {
		$key_a = BaiduTextModerationDriver::token_cache_key( 'AK-test' );
		$key_b = BaiduTextModerationDriver::token_cache_key( 'AK-other' );
		$this->assertStringStartsWith( 'wk_baidu_token_', $key_a );
		$this->assertSame( $key_a, BaiduTextModerationDriver::token_cache_key( 'AK-test' ), '同凭据同键' );
		$this->assertNotSame( $key_a, $key_b, '换凭据换键（旧 token 不串用）' );
	}
}
