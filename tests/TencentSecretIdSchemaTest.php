<?php
/**
 * 天御 captcha.tencent.secret_id 字段新增的 schema/Sanitize 联动测试
 * （05 §1.1 实现注意④：TC3 签名需 CAM 密钥对——SecretId 字段随 schema 扩展）。
 *
 * 规格：schema_version 仍为 1（不加版本——已有站点靠 merge_defaults 补键，05 §1.1④）；
 * 默认树含 captcha.tencent.secret_id=''；Sanitize 规则双向一致。
 *
 * 【M2 debug FIX-α 2026-11-10】secret_id 定型为 password 型（03 §1 v1.3 敏感字段契约：
 * 「不回显渲染」的字段空串提交须保留已存值——M1 曾钉死 text 型导致 Tab② 二次保存
 * 即丢 SecretId（DEFECT-ROOTCAUSE P0-α），本测试反向锁定了该缺陷，现按设计翻转）。
 * 与 review.cloud.tencent_tms.secret_id 同口径。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Settings\Sanitize;
use WinkGatekeeper\Settings\SettingsSchema;

final class TencentSecretIdSchemaTest extends TestCase {

	public function test_schema_version_stays_one(): void {
		// 【v1.4·R4-G 修订】secret_id 落键当年不动版本（05 §1.1④），但 v1.4 迁移段已整体
		// bump 至 2（threshold 平移/弃键丢弃/新键整树——04 §2 v1.4）；本断言随之对齐当前常量。
		$this->assertSame( 2, SettingsSchema::SCHEMA_VERSION );
	}

	public function test_default_tree_contains_empty_tencent_secret_id(): void {
		$defaults = SettingsSchema::defaults();
		$this->assertArrayHasKey( 'secret_id', $defaults['captcha']['tencent'] );
		$this->assertSame( '', $defaults['captcha']['tencent']['secret_id'] );
		// 原有二键不动。
		$this->assertSame( '', $defaults['captcha']['tencent']['app_id'] );
		$this->assertSame( '', $defaults['captcha']['tencent']['secret_key'] );
	}

	public function test_merge_defaults_backfills_secret_id_for_existing_sites(): void {
		// M0 激活站点的 option 无 secret_id：merge 后自动补默认空键（schema_version 收口不变）。
		$merged = SettingsSchema::merge_defaults( array(
			'schema_version'  => 1,
			'captcha'         => array(
				'driver'  => 'tencent',
				'tencent' => array( 'app_id' => 199999164, 'secret_key' => 'existing' ),
			),
		) );

		$this->assertSame( '', $merged['captcha']['tencent']['secret_id'] );
		$this->assertSame( 199999164, $merged['captcha']['tencent']['app_id'] );
		$this->assertSame( 'existing', $merged['captcha']['tencent']['secret_key'] );
		$this->assertSame( 'tencent', $merged['captcha']['driver'] );
	}

	public function test_sanitize_rule_exists_for_secret_id(): void {
		$rules = Sanitize::rules();
		// FIX-α：password 型（不回显渲染 ⇒ 空串保留旧值的写侧语义，04 §2 v1.3 敏感字段清单）。
		$this->assertSame( 'password', $rules['captcha.tencent.secret_id'] ?? null );
	}

	public function test_tencent_tms_secret_id_is_password_typed_too(): void {
		// 同病潜伏字段同口径（根因报告 P0-α 锚点 Sanitize.php:101）：TMS 驱动 M2 实装前先钉正类型，
		// 防止届时重蹈「password 渲染 + text 类型 = 空串必丢」。
		$rules = Sanitize::rules();
		$this->assertSame( 'password', $rules['review.cloud.tencent_tms.secret_id'] ?? null );
	}

	public function test_secret_id_survives_clean_round_trip(): void {
		list( $clean, $rejected ) = Sanitize::clean( array(
			'captcha' => array( 'tencent' => array(
				'secret_id' => ' AKIDz8krvexample ',
				'app_id'    => '199999164',
			) ),
		) );
		$this->assertSame( 'AKIDz8krvexample', $clean['captcha']['tencent']['secret_id'] );
		$this->assertSame( 199999164, $clean['captcha']['tencent']['app_id'] );
		$this->assertSame( array(), $rejected );
	}
}
