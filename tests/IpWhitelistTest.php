<?php
/**
 * 站长白名单 UI 与判定接线测试（M3-IP 定稿 §7 误伤防护三层 / 【实施注记·I5】，I5 批）。
 *
 * 规格：
 *   判定函数纯逻辑——AbuseIpdbService::whitelist_hits(ip, lines): bool（CIDR 语义复用
 *   TrustedProxies::parse_cidr/matches 既有零件；非法行忽略——宁不豁免不误伤反向放宽）；
 *   白名单行拆分——AbuseIpdbService::whitelist_lines_from(raw)（每行一条 IP/CIDR，容忍
 *   \r\n 与空白行）；
 *   存储键——iprep.whitelist（textarea 型，默认 ''=无白名单；SettingsSchema/Sanitize 各一钉）；
 *   步 0 消费接线——默认 whitelist_match 闭包消费 iprep.whitelist（生产 assemble 传设置树
 *   即生效；测试不注入 whitelist_match 走默认闭包——与生产装配同形）；
 *   report 路径白名单前置同源（与 probe 步 0 同一判定函数——命中者永不报不计数）；
 *   诊断面板豁免徽标——IpDiagnostic::analyze 扩一列 effective['whitelisted']（命中白名单
 *   →true，渲染层标「白名单豁免」徽标）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Ip\AbuseIpdbService;
use WinkGatekeeper\Ip\IpDiagnostic;

final class IpWhitelistTest extends TestCase {

	private const HMAC_SALT = 'wk-test-whitelist-salt';

	/**
	 * network 子树（analyze 徽标测试消费——IpDiagnosticTest 同形态）。
	 *
	 * @var array<string, mixed>
	 */
	private const NETWORK = array(
		'ip_source'       => 'direct',
		'trusted_proxies' => array( 'cloudflare' => false, 'edgeone' => false, 'custom' => '203.0.113.0/24' ),
		'custom_header'   => 'CF-Connecting-IP',
	);

	/* ---------- 判定函数纯逻辑（输入 IP+白名单数组 → bool） ---------- */

	public function test_whitelist_hits_exact_ip(): void {
		$this->assertTrue( AbuseIpdbService::whitelist_hits( '8.8.8.8', array( '8.8.8.8' ) ) );
		$this->assertFalse( AbuseIpdbService::whitelist_hits( '8.8.8.9', array( '8.8.8.8' ) ), '精确 IP 不做前缀外推' );
	}

	public function test_whitelist_hits_cidr_contains_and_excludes(): void {
		$this->assertTrue( AbuseIpdbService::whitelist_hits( '8.8.8.9', array( '8.8.8.0/24' ) ), 'CIDR 命中' );
		$this->assertFalse( AbuseIpdbService::whitelist_hits( '8.8.9.1', array( '8.8.8.0/24' ) ), 'CIDR 外不命中' );
	}

	public function test_whitelist_hits_ipv6(): void {
		$this->assertTrue( AbuseIpdbService::whitelist_hits( '2001:db8::1', array( '2001:db8::/32' ) ) );
		$this->assertFalse( AbuseIpdbService::whitelist_hits( '2001:db9::1', array( '2001:db8::/32' ) ) );
		$this->assertTrue( AbuseIpdbService::whitelist_hits( '::ffff:8.8.8.8', array( '8.8.8.0/24' ) ), '::ffff: 变体归一后命中（v1.2 归一化条款同口径）' );
	}

	public function test_whitelist_hits_invalid_lines_ignored(): void {
		$lines = array( 'not-an-ip', '300.1.1.1/33', '8.8.8.0/33', '' );
		$this->assertTrue( AbuseIpdbService::whitelist_hits( '8.8.8.8', array_merge( $lines, array( '8.8.8.8' ) ) ), '非法行忽略不阻断合法行' );
		$this->assertFalse( AbuseIpdbService::whitelist_hits( '9.9.9.9', $lines ), '纯非法行=不豁免任何人' );
	}

	public function test_whitelist_hits_empty_set_never_matches(): void {
		$this->assertFalse( AbuseIpdbService::whitelist_hits( '8.8.8.8', array() ) );
		$this->assertFalse( AbuseIpdbService::whitelist_hits( '', array( '8.8.8.0/24' ) ), '空 IP 恒不豁免' );
	}

	public function test_whitelist_lines_from_splits_and_trims(): void {
		$this->assertSame(
			array( '8.8.8.8', '203.0.113.0/24', '2001:db8::/32' ),
			AbuseIpdbService::whitelist_lines_from( " 8.8.8.8 \r\n203.0.113.0/24\n\n  \n2001:db8::/32" ),
			'容忍 \r\n/空白行，trim 每行'
		);
		$this->assertSame( array(), AbuseIpdbService::whitelist_lines_from( '' ) );
		$this->assertSame( array(), AbuseIpdbService::whitelist_lines_from( null ), '非标量容错（schema merge 前空树）' );
	}

	/* ---------- 步 0 消费接线（默认闭包消费 iprep.whitelist——与生产 assemble 同形） ---------- */

	/**
	 * 最小装配（与生产 IpReputationService::assemble 同形：只传设置树+传输脊，
	 * 白名单不注入——默认闭包消费 iprep.whitelist）。
	 *
	 * @param array<string, mixed> $settings 设置树。
	 * @param array<int, array>    &$calls   外呼捕获。
	 * @param array<string, mixed> &$cache   transient store。
	 * @param int                  &$usage   计量计数。
	 * @param array<int, array>    &$posts   report 事件捕获。
	 * @return AbuseIpdbService
	 */
	private function production_shaped_service( array $settings, array &$calls, array &$cache, int &$usage, array &$posts = array() ): AbuseIpdbService {
		$transport = function ( string $url, array $args ) use ( &$calls ) {
			$calls[] = array( 'url' => $url, 'args' => $args );
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) json_encode( array( 'data' => array( 'ipAddress' => '8.8.8.8', 'abuseConfidenceScore' => 42 ) ) ),
				'headers'  => array(),
			);
		};
		$remote = new RemoteClient(
			$transport,
			function ( string $key ) use ( &$usage ): void {
				$usage++;
			},
			static function ( int $ms ): void {
				unset( $ms );
			}
		);
		return new AbuseIpdbService(
			$settings,
			$remote,
			static function ( string $key ) use ( &$cache ) {
				return array_key_exists( $key, $cache ) ? $cache[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$cache ): void {
				$cache[ $key ] = $value;
			},
			static function ( string $key ) use ( &$cache ) {
				return array_key_exists( $key, $cache ) ? $cache[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$cache ): void {
				$cache[ $key ] = $value;
			},
			static function ( string $driver_id ): ?string {
				unset( $driver_id );
				return null; // 无停转。
			},
			static function ( string $driver_id, string $state ): void {
				unset( $driver_id, $state );
			},
			static function (): array {
				return array( 'date' => '2026-11-13', 'counters' => array() );
			},
			static function ( string $data ): string {
				return substr( hash_hmac( 'sha256', $data, self::HMAC_SALT ), 0, 16 );
			},
			null, // is_admin_user：缺省 WP 边界（测试环境无 current_user_can → false）。
			null, // server_ips：缺省 WP 边界（CLI $_SERVER 无 SERVER_ADDR → 空集）。
			null, // whitelist_match：**不注入**——默认闭包消费 iprep.whitelist（本批接线面）。
			static function (): int {
				return 1731475200; // 固定时钟。
			},
			static function ( int $ts ): void {
				unset( $ts ); // usage_mark 注入 no-op（缺省 WP 边界会触 get_option——测试环境无 WP）。
			},
			function ( string $gate, string $stage, $verdict, array $meta ) use ( &$posts ): ?string {
				$posts[] = array( 'gate' => $gate, 'stage' => $stage, 'meta' => $meta );
				return 'WK-TEST';
			},
			static function (): bool {
				return true; // 闸④自检恒过（隔离白名单变量——CLI $_SERVER 无 REMOTE_ADDR 缺省会拦在闸④）。
			}
		);
	}

	/**
	 * @param string $whitelist iprep.whitelist 存量文本。
	 * @return array<string, mixed>
	 */
	private static function settings( string $whitelist = '' ): array {
		return array(
			'iprep' => array(
				'enabled'   => true,
				'driver'    => 'abuseipdb',
				'whitelist' => $whitelist,
				'abuseipdb' => array(
					'api_key'         => 'wk-test-key',
					'suspect_score'   => 60,
					'malicious_score' => 85,
					'cache_minutes'   => 1440,
					'soft_quota'      => 3000,
					'daily_quota'     => 4750,
					'timeout'         => 1,
				),
				'report'    => array(
					'enabled'          => true,
					'triggers'         => array( 'captcha_failed' => true ),
					'cooldown_minutes' => 60,
					'hourly_limit'     => 60,
					'daily_cap'        => 500,
				),
			),
		);
	}

	public function test_probe_step0_whitelisted_ip_skips_with_zero_outbound(): void {
		$calls = array(); $cache = array(); $usage = 0; $posts = array();
		$service = $this->production_shaped_service( self::settings( "8.8.8.0/24\n#注释行" ), $calls, $cache, $usage, $posts );

		$this->assertNull( $service->probe( '8.8.8.8' ), '白名单命中 → null（skip）' );
		$this->assertSame( array(), $calls, '步 0 豁免=零外呼' );
		$this->assertSame( 0, $usage, '步 0 豁免=零计量' );
		$this->assertSame( array(), $cache, '步 0 豁免=零写（缓存/退避/计数全不动）' );
	}

	public function test_probe_step0_whitelist_precedes_fault_guard_in_cache_only(): void {
		$calls = array(); $cache = array(); $usage = 0; $posts = array();
		// 未配钥（防呆应 fail）但 IP 在白名单——白名单前置优先于防呆（I2 注记 2 行为层语义）。
		$settings            = self::settings( '8.8.8.8' );
		$settings['iprep']['abuseipdb']['api_key'] = '';
		$service = $this->production_shaped_service( $settings, $calls, $cache, $usage, $posts );

		$this->assertNull( $service->probe( '8.8.8.8', array( 'cache_only' => true ) ), '白名单命中 cache_only 同 skip' );
		$this->assertSame( array(), $calls );
	}

	public function test_probe_non_whitelisted_ip_still_judged(): void {
		$calls = array(); $cache = array(); $usage = 0; $posts = array();
		$service = $this->production_shaped_service( self::settings( '8.8.8.0/24' ), $calls, $cache, $usage, $posts );

		$verdict = $service->probe( '198.51.100.7' );
		$this->assertNotNull( $verdict, '白名单外 IP 照常判定（score 42 → review）' );
		$this->assertCount( 1, $calls, '白名单外照常外呼' );
	}

	public function test_report_path_whitelist_pregate_same_source(): void {
		$calls = array(); $cache = array(); $usage = 0; $posts = array();
		$service = $this->production_shaped_service( self::settings( '8.8.8.8' ), $calls, $cache, $usage, $posts );

		$this->assertFalse(
			$service->report_ip( '8.8.8.8', array( 'trigger' => 'captcha_failed', 'gate' => 'comment' ) ),
			'白名单命中 → 永不报（false）'
		);
		$this->assertSame( array(), $calls, '白名单前置=零发送' );
		$this->assertArrayNotHasKey( 'wk_rp_spike', $cache, '白名单前置先于突增计数（O5-P3-1 计数点之外的豁免面）' );
	}

	public function test_report_path_non_whitelisted_proceeds_to_spike_count(): void {
		$calls = array(); $cache = array(); $usage = 0; $posts = array();
		$service = $this->production_shaped_service( self::settings( '9.9.9.9' ), $calls, $cache, $usage, $posts );

		$service->report_ip( '8.8.8.8', array( 'trigger' => 'captcha_failed', 'gate' => 'comment' ) );
		$this->assertArrayHasKey( 'wk_rp_spike', $cache, '白名单外 IP 照常进突增原始计数（对照）' );
		$this->assertCount( 1, $calls, '白名单外照常发送（对照）' );
	}

	/* ---------- 诊断面板豁免徽标（analyze 扩一列） ---------- */

	public function test_analyze_effective_row_gains_whitelist_badge_column(): void {
		$server = array( 'REMOTE_ADDR' => '8.8.8.8' );
		$panel  = IpDiagnostic::analyze( $server, self::NETWORK, null, array( '8.8.8.0/24' ) );
		$this->assertArrayHasKey( 'whitelisted', $panel['effective'], 'effective 行扩 whitelisted 列' );
		$this->assertTrue( $panel['effective']['whitelisted'], '命中白名单 → 豁免徽标' );

		$panel_miss = IpDiagnostic::analyze( array( 'REMOTE_ADDR' => '8.8.9.1' ), self::NETWORK, null, array( '8.8.8.0/24' ) );
		$this->assertFalse( $panel_miss['effective']['whitelisted'], 'CIDR 外不标' );

		$panel_none = IpDiagnostic::analyze( $server, self::NETWORK, null, array() );
		$this->assertFalse( $panel_none['effective']['whitelisted'], '无白名单=恒不标' );
	}

	/* ---------- schema/Sanitize 键面 ---------- */

	public function test_schema_holds_iprep_whitelist_default_empty(): void {
		$defaults = \WinkGatekeeper\Settings\SettingsSchema::defaults();
		$this->assertArrayHasKey( 'whitelist', $defaults['iprep'], 'iprep 子树含 whitelist 键' );
		$this->assertSame( '', $defaults['iprep']['whitelist'], '默认空=无白名单' );
	}

	public function test_sanitize_rule_maps_iprep_whitelist_to_textarea(): void {
		$rules = \WinkGatekeeper\Settings\Sanitize::rules();
		$this->assertSame( 'textarea', $rules['iprep.whitelist'], '白名单 textarea 类型（换行保留/去标签）' );
		$this->assertSame(
			"8.8.8.8\n203.0.113.0/24",
			\WinkGatekeeper\Settings\Sanitize::clean_value( "8.8.8.8\n<b>203.0.113.0/24</b>", 'textarea' ),
			'多行值清洗保形'
		);
	}

	/* ---------- UI 契约（分区① 白名单区——DOM 锚+文案三行+JS 接线） ---------- */

	public function test_zone_source_renders_whitelist_block(): void {
		$src = (string) file_get_contents( __DIR__ . '/../includes/settings/SettingsPage.php' );
		$this->assertStringContainsString( 'id="wk-iprep-whitelist"', $src, '白名单 textarea DOM 锚' );
		$this->assertStringContainsString( "array( 'iprep', 'whitelist' )", $src, '绑定 iprep.whitelist 实况键' );
		$this->assertStringContainsString( 'id="wk-iprep-whitelist-check"', $src, '行格式校验按钮（复用 CIDR 校验先例）' );
		$this->assertStringContainsString( 'id="wk-iprep-whitelist-result"', $src, '校验结果位' );
	}

	public function test_zone_source_whitelist_copy_three_lines(): void {
		$src = (string) file_get_contents( __DIR__ . '/../includes/settings/SettingsPage.php' );
		$this->assertStringContainsString( '永不查信誉、永不上报', $src, '文案①双通道豁免语义' );
		$this->assertStringContainsString( '受信网段管解析信任链', $src, '文案②与受信网段的区别' );
		$this->assertStringContainsString( '白名单管信誉豁免', $src, '文案②续' );
		$this->assertStringContainsString( '误伤', $src, '文案③误伤防护语义' );
	}

	public function test_admin_brand_js_binds_whitelist_check(): void {
		$js = (string) file_get_contents( __DIR__ . '/../assets/js/admin-brand.js' );
		$this->assertStringContainsString( 'wk-iprep-whitelist-check', $js, 'JS 绑定白名单校验按钮' );
		$this->assertStringContainsString( 'wk-iprep-whitelist', $js, 'JS 读取白名单 textarea' );
		$this->assertStringContainsString( 'wk-iprep-whitelist-result', $js, 'JS 写校验结果位' );
	}
}
