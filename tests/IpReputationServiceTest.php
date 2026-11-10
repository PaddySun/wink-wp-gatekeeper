<?php
/**
 * IpReputationService 骨架单元测试（02 §2.3b/05 §3.0，M2.5——F38/D17 三态语义载体）。
 *
 * 权威三态（澄清 A）：
 *   null           = 未启用（skip——iprep.enabled=false 或 M2.5 未装配 Null 服务）；
 *   Verdict::PASS  = 已启用且正常且低于阈值；
 *   review/reject/error = 已启用但超阈值/故障/防呆（fail——处置=强制验证码，出码过码放行）。
 *
 * 装配约定（02 §2.3b 实现载体节）：Bootstrap `iprep.enabled && 驱动已配置 ? AbuseIPDB(M3) : Null`；
 * 健康面：未启用 = 「未启用」态（Tab④ M3 消费，本批为纯函数占位）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Ip\IpReputationProbe;
use WinkGatekeeper\Ip\IpReputationService;
use WinkGatekeeper\Ip\NullIpReputationService;

final class IpReputationServiceTest extends TestCase {

	/* ---------- Null 实现：恒 skip = 现行行为 ---------- */

	public function test_null_service_probes_null_for_any_ip(): void {
		$service = new NullIpReputationService();
		$this->assertNull( $service->probe( '203.0.113.7' ) );
		$this->assertNull( $service->probe( '' ) );
		$this->assertNull( $service->probe( '2001:db8::1' ) );
	}

	public function test_null_service_implements_probe_interface(): void {
		$this->assertInstanceOf( IpReputationProbe::class, new NullIpReputationService() );
	}

	/* ---------- 装配器（Bootstrap 单一接线点） ---------- */

	public function test_assemble_disabled_returns_null_service(): void {
		// iprep.enabled=false（默认）→ Null 实现 → probe()===null → 三处消费方见 skip。
		$probe = IpReputationService::assemble( self::settings( array( 'enabled' => false ) ) );
		$this->assertInstanceOf( NullIpReputationService::class, $probe );
		$this->assertNull( $probe->probe( '198.51.100.9' ) );
	}

	public function test_assemble_enabled_without_driver_still_null_service(): void {
		// M2.5：开关开了但 AbuseIPDB 驱动未实装（M3）→ 仍 Null（防呆语义由 M3 驱动接入后生效）。
		$probe = IpReputationService::assemble( self::settings( array( 'enabled' => true ) ) );
		$this->assertInstanceOf( NullIpReputationService::class, $probe );
	}

	/* ---------- 健康面（纯函数占位：未启用态可见化） ---------- */

	public function test_state_label_disabled_is_not_enabled(): void {
		$this->assertSame( '未启用', IpReputationService::state_label( self::settings( array( 'enabled' => false ) ) ) );
	}

	public function test_state_label_enabled_without_driver_is_not_assembled(): void {
		// 开关开 + 驱动未实装（M2.5 唯一可达形态）→ 「未装配（M3）」——防呆可见化。
		$this->assertSame( '未装配（M3）', IpReputationService::state_label( self::settings( array( 'enabled' => true ) ) ) );
	}

	/**
	 * @param array<string, mixed> $iprep_overrides
	 * @return array<string, mixed>
	 */
	private static function settings( array $iprep_overrides ): array {
		return array(
			'iprep' => array_merge(
				array(
					'enabled' => false,
					'driver'  => 'abuseipdb',
					'abuseipdb' => array( 'api_key' => '' ),
				),
				$iprep_overrides
			),
		);
	}
}
