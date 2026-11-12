<?php
/**
 * schema v1.4 迁移单元测试（04 §2 v1.4 迁移段；R4-G）。
 *
 * 覆盖：
 *   - schema_version 递增（1 → 2）；
 *   - 旧 captcha.login_adaptive.threshold 值平移至 user_threshold（老值不丢）；
 *   - max_user_keys 丢弃（洪泛面消失，02 §3.2 v1.4）；
 *   - iprep.abuseipdb.fail_mode 丢弃（澄清 A：三态语义定死强制验证码，04 §2）；
 *   - review.cloud.chain 默认值 ["deepseek"] → []（未实装驱动不入默认链）；
 *   - 新键整树补齐：gates.register/lostpassword、captcha.trust 六键、
 *     iprep.negative_cache_minutes、iprep.abuseipdb.timeout、stats.raw_capture/raw_max_bytes；
 *   - 老站已存 chain 值不被 merge_defaults 覆盖；
 *   - Sanitize 白名单同步（新键可清洗、旧键剥除）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Settings\Sanitize;
use WinkGatekeeper\Settings\SettingsSchema;

final class SettingsSchemaV14Test extends TestCase {

	public function test_schema_version_bumped_to_2(): void {
		$this->assertSame( 2, SettingsSchema::SCHEMA_VERSION );
		$this->assertSame( 2, SettingsSchema::defaults()['schema_version'] );
	}

	public function test_defaults_chain_is_empty_array(): void {
		// 原默认 ["deepseek"] 作废（deepseek 未实装，避免下拉默认选中未实装驱动）。
		$this->assertSame( array(), SettingsSchema::defaults()['review']['cloud']['chain'] );
	}

	public function test_defaults_carry_v14_new_keys(): void {
		$defaults = SettingsSchema::defaults();

		// gates.register / lostpassword（R3 批次消费的闸键随本迁移整树落位）。
		$this->assertSame( array( 'enabled' => true, 'render_mode' => 'always' ), $defaults['gates']['register'] );
		$this->assertSame( array( 'enabled' => true, 'render_mode' => 'always' ), $defaults['gates']['lostpassword'] );

		// captcha.trust 六键（R2 批次消费；04 §2 v1.4）。
		$this->assertSame(
			array( 'enabled' => true, 'min_approved' => 3, 'ttl_minutes' => 10, 'credit_cache_minutes' => 5, 'precheck_hourly_limit' => 30, 'precheck_timeout_ms' => 3000 ),
			$defaults['captcha']['trust']
		);

		// 登录自适应重构：user_threshold 迁移目标键 + iprep_gate；threshold/max_user_keys 不再出现。
		$this->assertSame( 3, $defaults['captcha']['login_adaptive']['user_threshold'] );
		$this->assertSame( 10, $defaults['captcha']['login_adaptive']['ip_threshold'] );
		$this->assertSame( true, $defaults['captcha']['login_adaptive']['iprep_gate'] );
		$this->assertArrayNotHasKey( 'threshold', $defaults['captcha']['login_adaptive'] );
		$this->assertArrayNotHasKey( 'max_user_keys', $defaults['captcha']['login_adaptive'] );

		// iprep：负缓存 + 驱动级超时（R2/R3 超时键合并裁决——一键三消费）；fail_mode 不再出现。
		// 【v1.7·M3-IP 定稿 §3.3】负缓存默认 10→60 平移（v1.4 原默认 10 的键位保留）。
		$this->assertSame( 60, $defaults['iprep']['negative_cache_minutes'] );
		$this->assertSame( 1, $defaults['iprep']['abuseipdb']['timeout'] );
		$this->assertArrayNotHasKey( 'fail_mode', $defaults['iprep']['abuseipdb'] );

		// stats.raw_*（R4 §D.4 日志级别开关）。
		$this->assertSame( true, $defaults['stats']['raw_capture'] );
		$this->assertSame( 512, $defaults['stats']['raw_max_bytes'] );
	}

	public function test_migrate_moves_threshold_value_to_user_threshold(): void {
		$legacy = SettingsSchema::defaults();
		$legacy['schema_version']                     = 1;
		$legacy['captcha']['login_adaptive']['threshold'] = 5;
		unset( $legacy['captcha']['login_adaptive']['user_threshold'] );

		$migrated = SettingsSchema::migrate( $legacy );

		$this->assertSame( 5, $migrated['captcha']['login_adaptive']['user_threshold'], '老站自定义阈值平移不丢' );
		$this->assertArrayNotHasKey( 'threshold', $migrated['captcha']['login_adaptive'] );
	}

	public function test_migrate_drops_max_user_keys_and_iprep_fail_mode(): void {
		$legacy = SettingsSchema::defaults();
		$legacy['schema_version'] = 1;
		$legacy['captcha']['login_adaptive']['max_user_keys'] = 20;
		$legacy['iprep']['abuseipdb']['fail_mode']            = 'force-captcha';

		$migrated = SettingsSchema::migrate( $legacy );

		$this->assertArrayNotHasKey( 'max_user_keys', $migrated['captcha']['login_adaptive'], '洪泛键整体删除（02 §3.2 v1.4）' );
		$this->assertArrayNotHasKey( 'fail_mode', $migrated['iprep']['abuseipdb'], '三态语义作废该键，迁移时旧值忽略' );
	}

	public function test_migrate_replaces_default_deepseek_chain_with_empty(): void {
		// 老站仍是默认链 ["deepseek"]（从未动过云审核）→ 迁移为 []（未实装驱动不构成链）。
		$legacy = SettingsSchema::defaults();
		$legacy['schema_version'] = 1;
		$legacy['review']['cloud']['chain'] = array( 'deepseek' );

		$migrated = SettingsSchema::migrate( $legacy );

		$this->assertSame( array(), $migrated['review']['cloud']['chain'] );
	}

	public function test_migrate_preserves_explicitly_saved_chain(): void {
		// 老站已存 chain=['tencent_tms']：不被默认值覆盖（merge_defaults 语义保持）。
		$legacy = SettingsSchema::defaults();
		$legacy['schema_version'] = 1;
		$legacy['review']['cloud']['chain'] = array( 'tencent_tms' );

		$migrated = SettingsSchema::migrate( $legacy );

		$this->assertSame( array( 'tencent_tms' ), $migrated['review']['cloud']['chain'] );
	}

	public function test_migrate_backfills_new_keys_on_legacy_tree(): void {
		$legacy = SettingsSchema::defaults();
		$legacy['schema_version'] = 1;
		unset(
			$legacy['gates']['register'], $legacy['gates']['lostpassword'],
			$legacy['captcha']['trust'], $legacy['iprep']['negative_cache_minutes'],
			$legacy['stats']['raw_capture'], $legacy['stats']['raw_max_bytes']
		);

		$migrated = SettingsSchema::migrate( $legacy );

		$this->assertSame( array( 'enabled' => true, 'render_mode' => 'always' ), $migrated['gates']['register'] );
		$this->assertSame( array( 'enabled' => true, 'render_mode' => 'always' ), $migrated['gates']['lostpassword'] );
		$this->assertSame( true, $migrated['stats']['raw_capture'] );
		$this->assertSame( 512, $migrated['stats']['raw_max_bytes'] );
		// 【v1.7·M3-IP】旧默认特征值 10 → 平移新默认 60（cache_minutes 60→1440 同批）。
		$this->assertSame( 60, $migrated['iprep']['negative_cache_minutes'] );
	}

	public function test_migrate_is_idempotent(): void {
		$legacy = SettingsSchema::defaults();
		$legacy['schema_version']                     = 1;
		$legacy['captcha']['login_adaptive']['threshold'] = 7;

		$once  = SettingsSchema::migrate( $legacy );
		$twice = SettingsSchema::migrate( $once );

		$this->assertSame( $once, $twice );
	}

	public function test_sanitize_rules_cover_new_keys_and_drop_legacy_ones(): void {
		$rules = Sanitize::rules();

		// 新键白名单。
		$this->assertSame( 'bool', $rules['gates.register.enabled'] );
		$this->assertSame( 'text', $rules['gates.register.render_mode'] );
		$this->assertSame( 'bool', $rules['gates.lostpassword.enabled'] );
		$this->assertSame( 'text', $rules['gates.lostpassword.render_mode'] );
		$this->assertSame( 'absint', $rules['captcha.login_adaptive.user_threshold'] );
		$this->assertSame( 'bool', $rules['captcha.login_adaptive.iprep_gate'] );
		$this->assertSame( 'absint', $rules['iprep.negative_cache_minutes'] );
		$this->assertSame( 'absint', $rules['iprep.abuseipdb.timeout'] );
		$this->assertSame( 'bool', $rules['stats.raw_capture'] );
		// raw_max_bytes 走专用 raw_bytes 型（absint + 钳 128–2048，04 §2/§3「截断/体量」行；
		// 下方 test_sanitize_clamps_raw_max_bytes_to_128_2048 封口钳位语义）。
		$this->assertSame( 'raw_bytes', $rules['stats.raw_max_bytes'] );
		foreach ( array( 'enabled', 'min_approved', 'ttl_minutes', 'credit_cache_minutes', 'precheck_hourly_limit', 'precheck_timeout_ms' ) as $key ) {
			$this->assertArrayHasKey( 'captcha.trust.' . $key, $rules );
		}

		// 旧键不再白名单（保存即剥除——旧数据经 migrate 一次清理）。
		$this->assertArrayNotHasKey( 'captcha.login_adaptive.threshold', $rules );
		$this->assertArrayNotHasKey( 'captcha.login_adaptive.max_user_keys', $rules );
		$this->assertArrayNotHasKey( 'iprep.abuseipdb.fail_mode', $rules );
	}

	public function test_sanitize_clamps_raw_max_bytes_to_128_2048(): void {
		$this->assertSame( 128, Sanitize::clean_value( 1, 'raw_bytes' ) );
		$this->assertSame( 512, Sanitize::clean_value( 512, 'raw_bytes' ) );
		$this->assertSame( 2048, Sanitize::clean_value( 999999, 'raw_bytes' ) );
	}
}
