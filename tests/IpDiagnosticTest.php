<?php
/**
 * IpDiagnostic 诊断面板纯函数测试（M3-IP 定稿 §5.1 / §10-6，I0 批）。
 *
 * 规格：
 *   纯函数（输入 $_SERVER+设置树 → 输出行矩阵），零 WP 依赖——WP 边界渲染在 SettingsPage；
 *   本地解析区：REMOTE_ADDR（标受信命中）/ CF-Connecting-IP / X-Real-IP / X-Forwarded-For
 *   （逗号链逐项标 受信/公网/私网）；当前生效行带五态 ip_src；配置一致性红标
 *   （配 custom_header 但本请求无该头）；受信网段行显手填 CIDR+预设名+快照校准日期
 *   （PRESET_VERIFIED >180 天 → 陈旧黄条）；EdgeOne 空预设不出现（定稿 §5.3 UI 隐藏）；
 *   四源 URL 常量集中一处 + https 硬约束（定稿 §12-2）；单源响应 → 卡片视图模型
 *   （响应结构 2026-11-12 curl 冒烟实证）；任一源失败仅该卡片失败。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Ip\IpDiagnostic;
use WinkGatekeeper\Ip\TrustedProxies;

final class IpDiagnosticTest extends TestCase {

	private const NETWORK = array(
		'ip_source'       => 'direct',
		'trusted_proxies' => array( 'cloudflare' => false, 'edgeone' => false, 'custom' => '203.0.113.0/24' ),
		'custom_header'   => 'CF-Connecting-IP',
	);

	/* ---------- 四源 URL 常量集中一处 + https 硬约束（§12-2） ---------- */

	public function test_remote_sources_constant_holds_exactly_four_https_endpoints(): void {
		$this->assertSame(
			array( 'abuseipdb', 'stopforumspam', 'ipncgy', 'ipsb' ),
			array_keys( IpDiagnostic::REMOTE_SOURCES ),
			'四源键集封闭（威胁组 3 + 地理组 1）'
		);
		foreach ( IpDiagnostic::REMOTE_SOURCES as $id => $url ) {
			$this->assertStringStartsWith( 'https://', $url, "源 {$id} 必须 https（RemoteClient 出站红线②同口径，硬约束）" );
		}
		$this->assertSame( 'https://api.abuseipdb.com/api/v2/check', IpDiagnostic::REMOTE_SOURCES['abuseipdb'] );
		$this->assertSame( 'https://api.stopforumspam.org/api', IpDiagnostic::REMOTE_SOURCES['stopforumspam'] );
		$this->assertSame( 'https://ip.nc.gy/json', IpDiagnostic::REMOTE_SOURCES['ipncgy'] );
		$this->assertSame( 'https://api.ip.sb/geoip', IpDiagnostic::REMOTE_SOURCES['ipsb'] );
	}

	public function test_source_urls_build_query_variants(): void {
		$urls = IpDiagnostic::source_urls( '8.8.8.8' );
		$this->assertSame( 'https://api.abuseipdb.com/api/v2/check?ipAddress=8.8.8.8&maxAgeInDays=90&verbose', $urls['abuseipdb'] );
		$this->assertSame( 'https://api.stopforumspam.org/api?ip=8.8.8.8&json', $urls['stopforumspam'] );
		$this->assertSame( 'https://ip.nc.gy/json?ip=8.8.8.8', $urls['ipncgy'] );
		$this->assertSame( 'https://api.ip.sb/geoip/8.8.8.8', $urls['ipsb'] );
	}

	public function test_source_urls_urlencode_ipv6(): void {
		$urls = IpDiagnostic::source_urls( '2001:db8::ab' );
		foreach ( $urls as $id => $url ) {
			$this->assertStringNotContainsString( ':', substr( $url, strpos( $url, '//' ) + 2 ), "源 {$id} 的 IPv6 须编码（冒号会破坏 URL）" );
		}
		$this->assertStringContainsString( rawurlencode( '2001:db8::ab' ), $urls['ipsb'] );
	}

	/* ---------- 本地解析区：行矩阵 ---------- */

	public function test_analyze_direct_mode_remote_row_public(): void {
		$panel = IpDiagnostic::analyze( array( 'REMOTE_ADDR' => '192.0.2.99' ), self::NETWORK );
		$this->assertSame( 'direct', $panel['mode'] );
		$this->assertSame( '192.0.2.99', $panel['remote']['ip'] );
		$this->assertFalse( $panel['remote']['trusted'] );
		$this->assertSame( 'public', $panel['remote']['scope'] );
		$this->assertSame( 'direct', $panel['effective']['ip_src'] );
		$this->assertSame( '192.0.2.99', $panel['effective']['ip'] );
	}

	public function test_analyze_remote_trusted_hit_marks_row(): void {
		$panel = IpDiagnostic::analyze( array( 'REMOTE_ADDR' => '203.0.113.9' ), self::NETWORK );
		$this->assertTrue( $panel['remote']['trusted'], 'REMOTE_ADDR 命中受信网段须标注（复用 TrustedProxies::matches 口径）' );
		$this->assertSame( 'trusted', $panel['remote']['scope'] );
	}

	public function test_analyze_remote_private_scope(): void {
		$panel = IpDiagnostic::analyze( array( 'REMOTE_ADDR' => '192.168.1.10' ), self::NETWORK );
		$this->assertFalse( $panel['remote']['trusted'] );
		$this->assertSame( 'private', $panel['remote']['scope'] );
	}

	public function test_analyze_remote_invalid_scope(): void {
		$panel = IpDiagnostic::analyze( array( 'REMOTE_ADDR' => 'not-an-ip' ), self::NETWORK );
		$this->assertSame( 'invalid', $panel['remote']['scope'] );
		$this->assertSame( 'unresolved', $panel['effective']['ip_src'] );
	}

	public function test_analyze_missing_remote_addr(): void {
		$panel = IpDiagnostic::analyze( array(), self::NETWORK );
		$this->assertSame( '', $panel['remote']['raw'] );
		$this->assertSame( 'invalid', $panel['remote']['scope'] );
		$this->assertSame( 'unresolved', $panel['effective']['ip_src'] );
	}

	public function test_analyze_header_rows_fixed_three_names(): void {
		$server = array(
			'REMOTE_ADDR'            => '203.0.113.9',
			'HTTP_CF_CONNECTING_IP'  => '192.0.2.55',
			'HTTP_X_REAL_IP'         => '192.0.2.66',
		);
		$panel  = IpDiagnostic::analyze( $server, self::NETWORK );
		$this->assertSame( array( 'CF-Connecting-IP', 'X-Real-IP', 'X-Forwarded-For' ), array_column( $panel['headers'], 'name' ) );
		$this->assertSame( '192.0.2.55', $panel['headers'][0]['raw'] );
		$this->assertSame( 'public', $panel['headers'][0]['items'][0]['scope'] );
		$this->assertSame( '192.0.2.66', $panel['headers'][1]['raw'] );
		$this->assertNull( $panel['headers'][2]['raw'], 'XFF 缺失行仍须在场（raw=null）' );
	}

	public function test_analyze_xff_chain_items_labeled_individually(): void {
		$server = array(
			'REMOTE_ADDR'          => '203.0.113.9',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.1, 203.0.113.5, 10.0.0.7',
		);
		$panel  = IpDiagnostic::analyze( $server, self::NETWORK );
		$items  = $panel['headers'][2]['items'];
		$this->assertCount( 3, $items );
		$this->assertSame( array( 'public', 'trusted', 'private' ), array_column( $items, 'scope' ), '逗号链逐项标 受信/公网/私网' );
		$this->assertSame( '198.51.100.1', $items[0]['ip'] );
		$this->assertSame( '203.0.113.5', $items[1]['ip'] );
		$this->assertSame( '10.0.0.7', $items[2]['ip'] );
	}

	public function test_analyze_xff_chain_invalid_item_marked(): void {
		$server = array(
			'REMOTE_ADDR'          => '203.0.113.9',
			'HTTP_X_FORWARDED_FOR' => 'bogus-entry, 198.51.100.1',
		);
		$panel  = IpDiagnostic::analyze( $server, self::NETWORK );
		$items  = $panel['headers'][2]['items'];
		$this->assertSame( 'invalid', $items[0]['scope'] );
		$this->assertNull( $items[0]['ip'] );
		$this->assertSame( 'public', $items[1]['scope'] );
	}

	public function test_analyze_effective_row_reflects_five_states(): void {
		// ignored_untrusted 可视化：xff 模式 + REMOTE_ADDR 不受信。
		$network = self::NETWORK;
		$network['ip_source'] = 'xff';
		$panel = IpDiagnostic::analyze( array(
			'REMOTE_ADDR'          => '192.0.2.99',
			'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
		), $network );
		$this->assertSame( 'xff', $panel['mode'] );
		$this->assertSame( '192.0.2.99', $panel['effective']['ip'] );
		$this->assertSame( 'ignored_untrusted', $panel['effective']['ip_src'], '五态列须呈现 wink 信任链不变式' );

		// traverse 成功 → ip_src=xff（五态不扩）。
		$network['ip_source'] = 'traverse';
		$network['trusted_proxies']['custom'] = "203.0.113.0/24\n198.51.100.0/24";
		$panel = IpDiagnostic::analyze( array(
			'REMOTE_ADDR'          => '203.0.113.9',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.2, 192.0.2.77',
		), $network );
		$this->assertSame( 'traverse', $panel['mode'] );
		$this->assertSame( '192.0.2.77', $panel['effective']['ip'] );
		$this->assertSame( 'xff', $panel['effective']['ip_src'] );
	}

	/* ---------- 配置一致性红标 ---------- */

	public function test_analyze_flags_custom_header_configured_but_absent(): void {
		// WF「Configured but not valid」同款：custom_header 模式下配置的头在本请求缺席 → 红标。
		$network = self::NETWORK;
		$network['ip_source'] = 'custom_header';
		$panel = IpDiagnostic::analyze( array( 'REMOTE_ADDR' => '203.0.113.9' ), $network );
		$this->assertTrue( $panel['flag'], '配了 custom_header 但本请求无该头 → 红标' );
		$this->assertSame( 'CF-Connecting-IP', $panel['custom_header'] );

		$panel = IpDiagnostic::analyze( array(
			'REMOTE_ADDR'           => '203.0.113.9',
			'HTTP_CF_CONNECTING_IP' => '192.0.2.55',
		), $network );
		$this->assertFalse( $panel['flag'], '头在场 → 无红标' );
	}

	public function test_analyze_no_flag_for_other_modes(): void {
		$network = self::NETWORK;
		$network['ip_source'] = 'direct';
		$network['custom_header'] = '';
		$panel = IpDiagnostic::analyze( array( 'REMOTE_ADDR' => '192.0.2.99' ), $network );
		$this->assertFalse( $panel['flag'], '非 custom_header 模式不触发红标' );
	}

	/* ---------- 受信网段行：手填 CIDR + 预设名 + 快照日期 ---------- */

	public function test_analyze_custom_cidr_row_and_invalid_lines(): void {
		$network = self::NETWORK;
		$network['trusted_proxies']['custom'] = "203.0.113.0/24\nbad-line\n198.51.100.0/24";
		$panel = IpDiagnostic::analyze( array( 'REMOTE_ADDR' => '192.0.2.99' ), $network );

		$custom_rows = array_values( array_filter( $panel['trusted']['rows'], static fn( $r ) => 'custom' === $r['kind'] ) );
		$this->assertCount( 1, $custom_rows );
		$this->assertSame( 2, $custom_rows[0]['count'], '手填 CIDR 行须显条数（合法行）' );
		$this->assertSame( array( 'bad-line' ), $panel['trusted']['invalid'], '非法行须单独暴露（保存反馈同源）' );
	}

	public function test_analyze_preset_cloudflare_row_with_snapshot_date(): void {
		$network = self::NETWORK;
		$network['trusted_proxies']['cloudflare'] = true;
		$panel = IpDiagnostic::analyze( array( 'REMOTE_ADDR' => '192.0.2.99' ), $network, strtotime( '2026-11-12' ) );

		$preset_rows = array_values( array_filter( $panel['trusted']['rows'], static fn( $r ) => 'preset' === $r['kind'] ) );
		$this->assertCount( 1, $preset_rows );
		$this->assertStringContainsString( 'Cloudflare', $preset_rows[0]['label'], '预设名须出现在受信网段行' );
		$this->assertSame( 22, $preset_rows[0]['count'], 'CF v4 15 + v6 7 = 22 段' );
		$this->assertSame( TrustedProxies::PRESET_VERIFIED, $panel['trusted']['verified'] );
		$this->assertSame( 3, $panel['trusted']['age_days'] );
		$this->assertFalse( $panel['trusted']['stale'] );
	}

	public function test_analyze_preset_stale_beyond_180_days(): void {
		$network = self::NETWORK;
		$network['trusted_proxies']['cloudflare'] = true;
		$now = strtotime( TrustedProxies::PRESET_VERIFIED . ' +181 days' );
		$panel = IpDiagnostic::analyze( array( 'REMOTE_ADDR' => '192.0.2.99' ), $network, $now );
		$this->assertTrue( $panel['trusted']['stale'], '>180 天 → 黄条「网段可能过期，请校准」' );
		$this->assertSame( 181, $panel['trusted']['age_days'] );
	}

	public function test_analyze_edgeone_empty_preset_never_listed(): void {
		// 定稿 §5.3：EdgeOne 空预设 UI 隐藏——预设名不得出现在受信网段行（常量保留占位）。
		$network = self::NETWORK;
		$network['trusted_proxies']['edgeone'] = true;
		$network['trusted_proxies']['cloudflare'] = true;
		$panel = IpDiagnostic::analyze( array( 'REMOTE_ADDR' => '192.0.2.99' ), $network );

		foreach ( $panel['trusted']['rows'] as $row ) {
			$this->assertStringNotContainsString( 'EdgeOne', $row['label'], 'EdgeOne 空预设不得入行（空预设入选=静默无保护）' );
		}
	}

	public function test_analyze_no_preset_rows_verified_null(): void {
		$panel = IpDiagnostic::analyze( array( 'REMOTE_ADDR' => '192.0.2.99' ), self::NETWORK );
		$this->assertNull( $panel['trusted']['verified'], '无预设行 → 快照日期为 null' );
		$this->assertFalse( $panel['trusted']['stale'] );
	}

	/* ---------- 单源卡片视图模型（响应结构 2026-11-12 冒烟实证） ---------- */

	public function test_parse_source_abuseipdb_verbose(): void {
		// 冒烟实录字段（verbose=1）：data.abuseConfidenceScore/countryCode/usageType/isp/isTor/totalReports。
		$card = IpDiagnostic::parse_source( 'abuseipdb', array(
			'data' => array(
				'ipAddress'             => '8.8.8.8',
				'abuseConfidenceScore'  => 0,
				'countryCode'           => 'US',
				'usageType'             => 'Content Delivery Network',
				'isp'                   => 'Google LLC',
				'isTor'                 => false,
				'totalReports'          => 189,
			),
		) );
		$this->assertTrue( $card['ok'] );
		$this->assertSame( '0', $card['rows']['评分'] );
		$this->assertSame( 'US', $card['rows']['国家'] );
		$this->assertSame( 'Content Delivery Network', $card['rows']['用途'] );
		$this->assertSame( 'Google LLC', $card['rows']['ISP'] );
		$this->assertSame( '否', $card['rows']['Tor'] );
		$this->assertSame( '189', $card['rows']['报告数'] );
	}

	public function test_parse_source_abuseipdb_contract_missing(): void {
		$card = IpDiagnostic::parse_source( 'abuseipdb', array( 'foo' => 'bar' ) );
		$this->assertFalse( $card['ok'], 'data 缺失=结构不符 → 该卡片失败' );
	}

	public function test_parse_source_stopforumspam(): void {
		$card = IpDiagnostic::parse_source( 'stopforumspam', array(
			'success' => 1,
			'ip'      => array( 'value' => '8.8.8.8', 'frequency' => 0, 'appears' => 0, 'asn' => 15169 ),
		) );
		$this->assertTrue( $card['ok'] );
		$this->assertSame( '0', $card['rows']['黑名单出现'] );
		$this->assertSame( '0', $card['rows']['报告频次'] );
	}

	public function test_parse_source_stopforumspam_failure(): void {
		$card = IpDiagnostic::parse_source( 'stopforumspam', array( 'success' => 0 ) );
		$this->assertFalse( $card['ok'] );
	}

	public function test_parse_source_ipncgy_six_flags_plus_asn(): void {
		// 冒烟实录：https://ip.nc.gy/json?ip=8.8.8.8 → country.iso_code / asn.autonomous_system_number
		// / proxy.{is_proxy,is_vpn,is_tor,is_hosting,is_cdn,is_anonymous}。
		$card = IpDiagnostic::parse_source( 'ipncgy', array(
			'ip'      => '8.8.8.8',
			'country' => array( 'iso_code' => 'US', 'names' => array( 'en' => 'United States', 'zh-CN' => '美国' ) ),
			'asn'     => array( 'autonomous_system_number' => 15169, 'autonomous_system_organization' => 'Google LLC' ),
			'proxy'   => array(
				'is_proxy'      => false,
				'is_vpn'        => false,
				'is_tor'        => false,
				'is_hosting'    => true,
				'is_cdn'        => false,
				'is_anonymous'  => false,
			),
		) );
		$this->assertTrue( $card['ok'] );
		$this->assertSame( '否', $card['rows']['代理'] );
		$this->assertSame( '否', $card['rows']['VPN'] );
		$this->assertSame( '否', $card['rows']['Tor'] );
		$this->assertSame( '是', $card['rows']['主机托管'] );
		$this->assertSame( '否', $card['rows']['CDN'] );
		$this->assertSame( '否', $card['rows']['匿名网络'] );
		$this->assertSame( '15169 Google LLC', $card['rows']['ASN'] );
		$this->assertSame( 'US', $card['rows']['国家'] );
	}

	public function test_parse_source_ipncgy_missing_proxy_is_contract_fail(): void {
		$card = IpDiagnostic::parse_source( 'ipncgy', array( 'ip' => '8.8.8.8' ) );
		$this->assertFalse( $card['ok'] );
	}

	public function test_parse_source_ipsb_geo(): void {
		// 冒烟实录：https://api.ip.sb/geoip/8.8.8.8 → country/region/city/isp/asn/organization。
		$card = IpDiagnostic::parse_source( 'ipsb', array(
			'ip'           => '8.8.8.8',
			'country'      => 'United States',
			'region'       => 'California',
			'city'         => 'Mountain View',
			'isp'          => 'Google',
			'organization' => 'Google',
			'asn'          => 15169,
			'country_code' => 'US',
		) );
		$this->assertTrue( $card['ok'] );
		$this->assertSame( 'United States California Mountain View', $card['rows']['归属地'] );
		$this->assertSame( 'Google', $card['rows']['ISP'] );
		$this->assertSame( '15169 Google', $card['rows']['ASN'] );
	}

	public function test_parse_source_unknown_source_fails(): void {
		$card = IpDiagnostic::parse_source( 'nope', array() );
		$this->assertFalse( $card['ok'] );
	}

	/* ---------- 失败卡人话（任一源失败仅该卡片失败） ---------- */

	public function test_card_error_messages_by_category(): void {
		$this->assertSame( '网络错误/超时', IpDiagnostic::card_error( 'transport', 0 ) );
		$this->assertStringContainsString( '503', IpDiagnostic::card_error( 'transport', 503 ) );
		$this->assertStringContainsString( '鉴权', IpDiagnostic::card_error( 'semantic', 401 ) );
		$this->assertStringContainsString( '403', IpDiagnostic::card_error( 'semantic', 403 ) );
		$this->assertStringContainsString( '结构', IpDiagnostic::card_error( 'contract', 200 ) );
		$this->assertStringContainsString( 'JSON', IpDiagnostic::card_error( 'parse', 200 ) );
	}
}
