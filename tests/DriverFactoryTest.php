<?php
/**
 * DriverFactory 驱动工厂契约测试（【P1-6·A1-F3】四散手写 if/elseif 工厂收敛单一来源）。
 *
 * 审计背景（AUDIT-INDEP-2026-11 §4 P1-6）：驱动构造存在 4 处独立手写工厂
 * （Bootstrap 云审链 5 分支 / Bootstrap 验证码 2 分支 / Bootstrap 仪表盘体检 2 分支 /
 * ExistingAuditWorker 体检工厂 5 分支），历史两犯静默失效（M5·C2 deepseek、M6·H4
 * openai_custom 漏体检工厂）。本批收敛为单一 DriverFactory（registry class 名派生
 * 构造形态）——契约钉 = 全集矩阵：registry 每个 driver_id 在每个工厂位 build 出
 * 正确实例类，矩阵钉永绝（新增驱动漏登记工厂位 = 本文件红）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Audit\ExistingAuditWorker;
use WinkGatekeeper\Drivers\Captcha\AliyunCaptchaDriver;
use WinkGatekeeper\Drivers\Captcha\TencentCaptchaDriver;
use WinkGatekeeper\Drivers\CaptchaDriverInterface;
use WinkGatekeeper\Drivers\DriverFactory;
use WinkGatekeeper\Drivers\DriverRegistry;
use WinkGatekeeper\Drivers\Review\AliyunGreenPlusDriver;
use WinkGatekeeper\Drivers\Review\BaiduTextModerationDriver;
use WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver;
use WinkGatekeeper\Drivers\Review\LocalRulesDriver;
use WinkGatekeeper\Drivers\Review\OpenAiCustomReviewDriver;
use WinkGatekeeper\Drivers\Review\TencentTmsDriver;
use WinkGatekeeper\Drivers\ReviewDriverInterface;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Settings\SettingsSchema;

final class DriverFactoryTest extends TestCase {

	/**
	 * registry 全集闭集（id → 期望实例类）——注册顺序 = Bootstrap 装配顺序
	 * （Tab① 点名册渲染序同源；本地驱动与 IP 信誉源不在云审/验证码工厂位）。
	 *
	 * @return array<string, string>
	 */
	private static function expected_class_map(): array {
		return array(
			'tencent'       => TencentCaptchaDriver::class,
			'aliyun'        => AliyunCaptchaDriver::class,
			'local_rules'   => LocalRulesDriver::class,
			'tencent_tms'   => TencentTmsDriver::class,
			'aliyun_green'  => AliyunGreenPlusDriver::class,
			'baidu'         => BaiduTextModerationDriver::class,
			'deepseek'      => DeepSeekReviewDriver::class,
			'openai_custom' => OpenAiCustomReviewDriver::class,
			'abuseipdb'     => \WinkGatekeeper\Ip\AbuseIpdbService::class,
		);
	}

	/**
	 * ① 注册清单单一来源：default_registry() 全集闭集 + 顺序钉。
	 */
	public function test_default_registry_full_set_closed_matrix(): void {
		$registry = DriverFactory::default_registry();
		$this->assertInstanceOf( DriverRegistry::class, $registry );
		$this->assertSame(
			array_keys( self::expected_class_map() ),
			array_keys( $registry->all() ),
			'default_registry 注册 id 闭集+顺序漂移（新驱动漏登记/误删=红）'
		);
		foreach ( self::expected_class_map() as $id => $class ) {
			$entry = $registry->get( $id );
			$this->assertNotNull( $entry, "registry 条目 {$id} 在场" );
			$this->assertSame( $class, $entry['class'], "registry 条目 {$id} class 字段全等" );
		}
	}

	/**
	 * ② 验证码工厂位全集矩阵：每个 captcha 型 registry driver_id build 出正确实例类。
	 */
	public function test_build_captcha_matrix_every_registered_captcha_driver(): void {
		$registry = DriverFactory::default_registry();
		$settings = SettingsSchema::defaults();
		$client   = new RemoteClient();

		$built = array();
		foreach ( $registry->by_type( 'captcha' ) as $id => $meta ) {
			$driver = DriverFactory::build_captcha( $registry, (string) $id, $settings, $client );
			$this->assertInstanceOf(
				CaptchaDriverInterface::class,
				$driver,
				"captcha 全集矩阵：{$id} 必须由工厂构造出验证码驱动（漏登记=红——历史两犯同型）"
			);
			$this->assertInstanceOf( $meta['class'], $driver, "captcha 全集矩阵：{$id} 实例类=registry class 字段" );
			$built[] = $id;
		}
		$this->assertSame( array( 'tencent', 'aliyun' ), $built, 'captcha 型驱动全集闭集（新增驱动本断言随批补位）' );
	}

	/**
	 * ③ 云审工厂位全集矩阵：每个云审型 registry driver_id build 出正确实例类；
	 * local_rules（review 型）恒首不入云审链 → null（现状语义钉）。
	 */
	public function test_build_review_matrix_every_registered_review_driver(): void {
		$registry = DriverFactory::default_registry();
		$settings = SettingsSchema::defaults();
		$client   = new RemoteClient();

		foreach ( self::expected_class_map() as $id => $class ) {
			if ( in_array( $id, array( 'local_rules', 'abuseipdb', 'tencent', 'aliyun' ), true ) ) {
				continue; // 非云审工厂位驱动（本地恒首 / IP 信誉源 / 验证码两驱动）。
			}
			$driver = DriverFactory::build_review( $registry, $id, $settings, $client, true );
			$this->assertInstanceOf(
				ReviewDriverInterface::class,
				$driver,
				"云审全集矩阵：{$id} 必须由工厂构造出云审驱动（漏登记=红——M5·C2/M6·H4 历史两犯同型）"
			);
			$this->assertInstanceOf( $class, $driver, "云审全集矩阵：{$id} 实例类=registry class 字段" );
		}
		$this->assertNull(
			DriverFactory::build_review( $registry, 'local_rules', $settings, $client, false ),
			'local_rules 恒首不入云审链（工厂返回 null=现「无分支静默剔除」语义）'
		);
		$this->assertNull(
			DriverFactory::build_review( $registry, 'abuseipdb', $settings, $client, false ),
			'ip 型驱动不在云审工厂位（工厂返回 null）'
		);
	}

	/**
	 * ④ 【S4-F3】stats 桥形参面：stats_bridge=true → settings 子树入桥（Bootstrap 云审链位
	 * 现分支形态）；false → 空数组（存量体检位现分支形态——raw 快照桥按默认 512=批前行为）。
	 */
	public function test_build_review_stats_bridge_flag_both_states(): void {
		$registry = DriverFactory::default_registry();
		$settings = SettingsSchema::defaults();
		$client   = new RemoteClient();

		$with_bridge = DriverFactory::build_review( $registry, 'deepseek', $settings, $client, true );
		$this->assertInstanceOf( DeepSeekReviewDriver::class, $with_bridge );

		$prop = new \ReflectionProperty( DeepSeekReviewDriver::class, 'stats_settings' );
		$prop->setAccessible( true );

		$expected = isset( $settings['stats'] ) && is_array( $settings['stats'] )
			? array( 'stats' => $settings['stats'] )
			: array();
		$this->assertSame( $expected, $prop->getValue( $with_bridge ), 'stats_bridge=true：settings 子树包装入桥（现分支逐位照抄）' );

		$without_bridge = DriverFactory::build_review( $registry, 'deepseek', $settings, $client, false );
		$this->assertInstanceOf( DeepSeekReviewDriver::class, $without_bridge );
		$this->assertSame( array(), $prop->getValue( $without_bridge ), 'stats_bridge=false：空桥=存量体检位批前行为' );
	}

	/**
	 * ⑤ 容灾限速器形参面：形参存在的驱动（tencent）透传、形参不存在的驱动（aliyun）忽略。
	 */
	public function test_build_captcha_disaster_limiter_passthrough_by_constructor_shape(): void {
		$registry = DriverFactory::default_registry();
		$settings = SettingsSchema::defaults();
		$client   = new RemoteClient();
		$limiter  = static function ( string $ip ): bool {
			unset( $ip );
			return true;
		};

		$tencent = DriverFactory::build_captcha( $registry, 'tencent', $settings, $client, $limiter );
		$this->assertInstanceOf( TencentCaptchaDriver::class, $tencent );
		$prop = new \ReflectionProperty( TencentCaptchaDriver::class, 'disaster_limiter' );
		$prop->setAccessible( true );
		$this->assertSame( $limiter, $prop->getValue( $tencent ), 'tencent：容灾限速器透传（Bootstrap 装配位现分支形态）' );
		$this->assertNull( $prop->getValue( DriverFactory::build_captcha( $registry, 'tencent', $settings, $client ) ), 'tencent：未传限速器=null（仪表盘体检位现分支形态）' );

		$aliyun = DriverFactory::build_captcha( $registry, 'aliyun', $settings, $client, $limiter );
		$this->assertInstanceOf( AliyunCaptchaDriver::class, $aliyun, 'aliyun：无该形参——限速器忽略，构造成功（B3 无容灾面）' );
	}

	/**
	 * ⑥ 未知 id → null（registry miss 静默剔除=现「无分支」行为）。
	 */
	public function test_unknown_driver_id_builds_null(): void {
		$registry = DriverFactory::default_registry();
		$settings = SettingsSchema::defaults();
		$client   = new RemoteClient();
		$this->assertNull( DriverFactory::build_captcha( $registry, 'moli', $settings, $client ), '未知验证码 id → null' );
		$this->assertNull( DriverFactory::build_review( $registry, 'moli', $settings, $client, false ), '未知云审 id → null（幽灵键幽灵链条目静默剔除）' );
	}

	/**
	 * ⑦ 【M5·C2/M6·H4 永绝钉】ExistingAuditWorker::default_driver_factory 全集矩阵：
	 * 每个云审驱动逐一配置齐 → 体检工厂解析出正确实例类+id。
	 */
	public function test_existing_audit_worker_factory_matrix_all_cloud_drivers(): void {
		$fixtures = array(
			'tencent_tms'   => array( 'secret_id' => 'id-x', 'secret_key' => 'key-x' ),
			'aliyun_green'  => array( 'access_key_id' => 'id-x', 'access_key_secret' => 'key-x' ),
			'baidu'         => array( 'api_key' => 'key-x', 'secret_key' => 'key-x' ),
			'deepseek'      => array( 'api_key' => 'sk-guard-probe-sample' ),
			'openai_custom' => array( 'api_key' => 'key-x', 'base_url' => 'https://api.example.com/v1', 'model' => 'm-x' ),
		);
		$expected_classes = array(
			'tencent_tms'   => TencentTmsDriver::class,
			'aliyun_green'  => AliyunGreenPlusDriver::class,
			'baidu'         => BaiduTextModerationDriver::class,
			'deepseek'      => DeepSeekReviewDriver::class,
			'openai_custom' => OpenAiCustomReviewDriver::class,
		);

		foreach ( $fixtures as $id => $creds ) {
			$settings = SettingsSchema::defaults();
			$settings['review']['cloud']['enabled'] = true;
			$settings['review']['cloud']['chain']   = array( $id );
			foreach ( $creds as $k => $v ) {
				$settings['review']['cloud'][ $id ][ $k ] = $v;
			}
			// aliyun_green is_configured 还要求 service 双名（默认值在场——补齐以防 defaults 变更）。
			if ( 'aliyun_green' === $id ) {
				$settings['review']['cloud']['aliyun_green']['service_nickname'] = 'svc';
				$settings['review']['cloud']['aliyun_green']['service_comment']  = 'svc';
			}

			$worker  = new ExistingAuditWorker( $settings );
			$resolved = $worker->default_driver_factory();
			$this->assertNotNull( $resolved, "体检工厂矩阵：{$id} 配置齐必须解析出驱动（漏登记=红——M5·C2/M6·H4 历史两犯位）" );
			$this->assertSame( $id, $resolved['id'], "体检工厂矩阵：{$id} 解析 id 全等" );
			$this->assertInstanceOf( $expected_classes[ $id ], $resolved['driver'], "体检工厂矩阵：{$id} 实例类=registry class 字段" );
		}
	}

	/**
	 * ⑧ 消费位单点化：Bootstrap.php 零手写驱动构造（云审链/验证码/仪表盘三工厂位全改走
	 * DriverFactory——手写分支死亡钉）。
	 */
	public function test_bootstrap_has_no_handwritten_driver_construction(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Bootstrap.php' );
		foreach ( array(
			'TencentCaptchaDriver(',
			'AliyunCaptchaDriver(',
			'TencentTmsDriver(',
			'AliyunGreenPlusDriver(',
			'BaiduTextModerationDriver(',
			'DeepSeekReviewDriver(',
			'OpenAiCustomReviewDriver(',
		) as $needle ) {
			$this->assertStringNotContainsString( 'new ' . $needle, $src, "Bootstrap 手写驱动构造退役：new {$needle}" );
		}
		$this->assertStringContainsString( 'DriverFactory::build_review', $src, '云审链工厂位走 DriverFactory' );
		$this->assertStringContainsString( 'DriverFactory::build_captcha', $src, '验证码工厂位走 DriverFactory' );
		$this->assertStringContainsString( 'DriverFactory::default_registry', $src, '注册清单单一来源接线' );
	}

	/**
	 * ⑨ 消费位单点化：ExistingAuditWorker.php 零手写驱动构造（体检工厂第二份退役）。
	 */
	public function test_existing_audit_worker_has_no_handwritten_driver_construction(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/audit/ExistingAuditWorker.php' );
		$this->assertStringNotContainsString( 'new \\WinkGatekeeper\\Drivers\\Review\\', $src, '体检工厂手写构造退役' );
		$this->assertStringContainsString( 'DriverFactory::build_review', $src, '体检工厂走 DriverFactory' );
	}
}
