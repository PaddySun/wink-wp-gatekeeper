<?php
/**
 * M5 修复批 A · chain 特征值撞车守卫回归测试（缺陷1）。
 *
 * 根因：migrate_fields ④ 的「v1.3 前默认链 ['deepseek'] → []」特征值规则与 U4 实装后
 * 站长的显式选择 chain=['deepseek'] 特征值相同——每次读取路径 migrate 都把配好 DeepSeek
 * key 的站的 chain 清空（下拉回弹「未配置」）。修复：④ 追加 api_key 空串判据——
 * 配好 key 的站（主动配置铁证）保留；真存量（从未动过→无 key）维持清空语义。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Settings\SettingsSchema;

final class M5FixChainFeatureValueTest extends TestCase {

	/* ===================================================================
	 * ① chain=['deepseek'] + api_key 非空 → migrate 后 chain 保留
	 * =================================================================== */

	public function test_configured_deepseek_chain_survives_migrate(): void {
		$stored = SettingsSchema::defaults();
		$stored['review']['cloud']['chain']           = array( 'deepseek' );
		$stored['review']['cloud']['deepseek']['api_key'] = 'sk-fixed-guard-sample';

		$migrated = SettingsSchema::migrate( $stored );

		$this->assertSame( array( 'deepseek' ), $migrated['review']['cloud']['chain'], '配好 key 的站：显式选择不再被特征值规则清空' );
	}

	public function test_configured_deepseek_chain_survives_merge_defaults_read_path(): void {
		// 读取路径 merge_defaults（含 migrate_fields）同样受守卫——每请求执行不回写存储，
		// 守卫必须让消费方见到的树保住 chain。
		$stored = SettingsSchema::defaults();
		$stored['review']['cloud']['chain']           = array( 'deepseek' );
		$stored['review']['cloud']['deepseek']['api_key'] = 'sk-fixed-guard-sample';

		$merged = SettingsSchema::merge_defaults( $stored );

		$this->assertSame( array( 'deepseek' ), $merged['review']['cloud']['chain'] );
	}

	public function test_deepseek_chain_with_whitespace_only_key_is_still_cleared(): void {
		// key 仅空白字符=未实际配置（copy-paste 残渣）——维持原清空语义。
		$stored = SettingsSchema::defaults();
		$stored['review']['cloud']['chain']           = array( 'deepseek' );
		$stored['review']['cloud']['deepseek']['api_key'] = '   ';

		$migrated = SettingsSchema::migrate( $stored );

		$this->assertSame( array(), $migrated['review']['cloud']['chain'] );
	}

	/* ===================================================================
	 * ② chain=['deepseek'] + 无 key → 仍清空（真存量语义不变）
	 * =================================================================== */

	public function test_unconfigured_legacy_deepseek_chain_still_cleared(): void {
		$stored = SettingsSchema::defaults();
		$stored['schema_version']                     = 1;
		$stored['review']['cloud']['chain']           = array( 'deepseek' ); // v1.3 前默认特征值.
		// api_key 保持默认空串——站长从未动过云审核。

		$migrated = SettingsSchema::migrate( $stored );

		$this->assertSame( array(), $migrated['review']['cloud']['chain'], '真存量（无 key）维持原清空语义' );
	}

	public function test_other_drivers_chain_untouched_by_guard(): void {
		// 非 ['deepseek'] 已存值原样保留（守卫不得改变 ④ 原有作用域）。
		$stored = SettingsSchema::defaults();
		$stored['review']['cloud']['chain'] = array( 'tencent_tms' );

		$migrated = SettingsSchema::migrate( $stored );

		$this->assertSame( array( 'tencent_tms' ), $migrated['review']['cloud']['chain'] );
	}

	/* ===================================================================
	 * ③ migrate 幂等性
	 * =================================================================== */

	public function test_migrate_is_idempotent_with_configured_deepseek_chain(): void {
		$stored = SettingsSchema::defaults();
		$stored['review']['cloud']['chain']           = array( 'deepseek' );
		$stored['review']['cloud']['deepseek']['api_key'] = 'sk-fixed-guard-sample';

		$once  = SettingsSchema::migrate( $stored );
		$twice = SettingsSchema::migrate( $once );

		$this->assertSame( $once, $twice, '守卫不破坏 migrate 幂等约定' );
		$this->assertSame( array( 'deepseek' ), $twice['review']['cloud']['chain'] );
	}

	public function test_migrate_is_idempotent_with_legacy_empty_chain(): void {
		$stored = SettingsSchema::defaults();
		$stored['schema_version']           = 1;
		$stored['review']['cloud']['chain'] = array( 'deepseek' );

		$once  = SettingsSchema::migrate( $stored );
		$twice = SettingsSchema::migrate( $once );

		$this->assertSame( $once, $twice );
		$this->assertSame( array(), $twice['review']['cloud']['chain'] );
	}
}
