<?php
/**
 * IpReputationService 装配器单元测试（02 §2.3b/05 §3.0——【v1.7·M3-IP·定稿 §2】I2 三态装配）。
 *
 * 权威三态（澄清 A）：
 *   null           = 未启用（skip——iprep.enabled=false → Null 服务）；
 *   Verdict::PASS  = 已启用且正常且低于阈值；
 *   review/reject/error = 已启用但超阈值/故障/防呆/软顶（fail——处置=强制验证码，出码过码放行）。
 *
 * 装配约定（定稿 §2）：!enabled → Null；enabled → AbuseIpdbService（api_key 空的防呆从严
 * 在服务内承载——probe 恒 fail reason=not_configured，白名单/管理员前置豁免）；
 * remote 形参可省（服务内缺省 WP 边界薄包装——单测只断言形态，行为在 AbuseIpdbServiceTest）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Ip\AbuseIpdbService;
use WinkGatekeeper\Ip\IpReputationProbe;
use WinkGatekeeper\Ip\IpReputationService;
use WinkGatekeeper\Ip\NullIpReputationService;
use WinkGatekeeper\Ip\SupportsReportingInterface;

final class IpReputationServiceTest extends TestCase {

	/* ---------- Null 实现：恒 skip = 现行行为 ---------- */

	public function test_null_service_probes_null_for_any_ip(): void {
		$service = new NullIpReputationService();
		$this->assertNull( $service->probe( '203.0.113.7' ) );
		$this->assertNull( $service->probe( '' ) );
		$this->assertNull( $service->probe( '2001:db8::1' ) );
		// 【v1.7·M3-IP】cache_only 上下文同 null（Null 服务零行为差异——向后兼容签名）。
		$this->assertNull( $service->probe( '203.0.113.7', array( 'cache_only' => true ) ) );
	}

	public function test_null_service_implements_probe_interface(): void {
		$this->assertInstanceOf( IpReputationProbe::class, new NullIpReputationService() );
	}

	/* ---------- 装配器（Bootstrap 单一接线点；定稿 §2 两处装配变更之一） ---------- */

	public function test_assemble_disabled_returns_null_service(): void {
		// iprep.enabled=false（默认）→ Null 实现 → probe()===null → 三处消费方见 skip。
		// 现行站点（iprep.enabled=false）升级后行为零变化。
		$probe = IpReputationService::assemble( self::settings( array( 'enabled' => false ) ) );
		$this->assertInstanceOf( NullIpReputationService::class, $probe );
		$this->assertNull( $probe->probe( '198.51.100.9' ) );
	}

	public function test_assemble_enabled_returns_abuseipdb_service(): void {
		// 【v1.7·M3-IP】enabled=true → AbuseIpdbService（check 七步流水线全量）。
		$probe = IpReputationService::assemble( self::settings( array( 'enabled' => true ) ) );
		$this->assertInstanceOf( AbuseIpdbService::class, $probe );
		$this->assertInstanceOf( IpReputationProbe::class, $probe );
	}

	public function test_assemble_enabled_without_key_still_service_not_null(): void {
		// 防呆分支（从严例外之二）：enabled && api_key 空 → 仍装配 AbuseIpdbService——
		// 不静默 skip；防呆 fail 行为（probe 恒 fail）在 AbuseIpdbServiceTest 钉
		// （本测试环境无 WP 边界闭包，不可直接调 probe——形态断言在此）。
		$probe = IpReputationService::assemble( self::settings( array( 'enabled' => true ) ) );
		$this->assertInstanceOf( AbuseIpdbService::class, $probe );
	}

	/* ---------- 【I3·定稿 §4】report-only 装配（report 独立于 iprep.enabled——04 §2） ---------- */

	public function test_assemble_report_only_returns_service_when_check_disabled(): void {
		// iprep.enabled=false 但 report.enabled && api_key 在 → 仍装配 AbuseIpdbService
		//（check 面由服务内 iprep.enabled 开关承载=probe 恒 null 与 Null 服务语义等价；
		// report_ip 经 instanceof 能力探测可达——触发点消费形态 O2-P2-7）。
		$probe = IpReputationService::assemble( self::settings( array(
			'enabled'   => false,
			'abuseipdb' => array( 'api_key' => 'k' ),
			'report'    => array( 'enabled' => true ),
		) ) );
		$this->assertInstanceOf( AbuseIpdbService::class, $probe );
		$this->assertInstanceOf( SupportsReportingInterface::class, $probe );
		$this->assertNull( $probe->probe( '198.51.100.9' ), 'check 面关=probe 恒 null skip' );
	}

	public function test_assemble_report_without_key_returns_null_service(): void {
		// report 开但密钥缺 → 上报面无从发送 → Null（装配形态静默——防呆只属 check 面）。
		$probe = IpReputationService::assemble( self::settings( array(
			'enabled' => false,
			'report'  => array( 'enabled' => true ),
		) ) );
		$this->assertInstanceOf( NullIpReputationService::class, $probe );
	}

	public function test_report_only_probe_cache_only_also_skips(): void {
		// report-only 服务 probe cache_only 同 null（report_ip 行为在 AbuseIpdbServiceTest 钉）。
		$probe = IpReputationService::assemble( self::settings( array(
			'enabled'   => false,
			'abuseipdb' => array( 'api_key' => 'k' ),
			'report'    => array( 'enabled' => true ),
		) ) );
		$this->assertNull( $probe->probe( '198.51.100.9', array( 'cache_only' => true ) ) );
	}

	/* ---------- 健康面（Tab④ 分区二健康行消费——【v1.7·M3-IP】第三态落地） ---------- */

	public function test_state_label_disabled_is_not_enabled(): void {
		$this->assertSame( '未启用', IpReputationService::state_label( self::settings( array( 'enabled' => false ) ) ) );
	}

	public function test_state_label_enabled_is_enabled_abuseipdb(): void {
		// enabled 即装配（M2.5 的「未装配（M3）」占位态随 I2 退役——防呆态由健康灯呈现）。
		$this->assertSame( '已启用（AbuseIPDB）', IpReputationService::state_label( self::settings( array( 'enabled' => true ) ) ) );
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
