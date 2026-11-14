<?php
/**
 * M5 修复批 D · url_audit 独立凭证+回落云审 DeepSeek 回归测试。
 *
 * 覆盖：provider_config 纯函数四路径（inherit 回落 / custom 独立 / custom 空 key
 * fail-safe 回落 / sanitize 枚举非法归 inherit）+ schema↔rules 配对 + UI/JS 契约锚。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Settings\Sanitize;
use WinkGatekeeper\Settings\SettingsSchema;
use WinkGatekeeper\UrlAudit\UrlAuditWorker;

final class M5FixUrlAuditProviderTest extends TestCase {

	/**
	 * 构造带云审 DeepSeek 凭证的设置树（回落路径的判定基准）。
	 *
	 * @return array<string, mixed>
	 */
	private function settingsWithCloudKey(): array {
		$settings = SettingsSchema::defaults();
		$settings['review']['cloud']['deepseek']['api_key']  = 'sk-cloud-review-sample';
		$settings['review']['cloud']['deepseek']['base_url'] = 'https://cloud-review.example.com';
		$settings['review']['cloud']['deepseek']['model']    = 'deepseek-reasoner';
		return $settings;
	}

	/* ===================================================================
	 * provider_config 纯函数
	 * =================================================================== */

	public function test_inherit_mode_falls_back_to_cloud_review_credentials(): void {
		$settings = $this->settingsWithCloudKey();

		$config = UrlAuditWorker::provider_config( $settings );

		self::assertSame( 'sk-cloud-review-sample', $config['api_key'], 'inherit=回落云审 DeepSeek key' );
		self::assertSame( 'https://cloud-review.example.com', $config['base_url'] );
		self::assertSame( 'deepseek-reasoner', $config['model'] );
	}

	public function test_inherit_is_default_when_provider_subtree_missing(): void {
		// 存量树无 provider 子树（merge_defaults 补齐前的防御面）→ 回落云审。
		$settings = $this->settingsWithCloudKey();
		unset( $settings['url_audit']['provider'] );

		$config = UrlAuditWorker::provider_config( $settings );

		self::assertSame( 'sk-cloud-review-sample', $config['api_key'] );
	}

	public function test_custom_mode_with_own_key_uses_independent_credentials(): void {
		$settings                     = $this->settingsWithCloudKey();
		$settings['url_audit']['provider'] = array(
			'mode'     => 'custom',
			'api_key'  => 'sk-url-audit-sample',
			'base_url' => 'https://url-judge.example.com',
			'model'    => 'deepseek-flash',
		);

		$config = UrlAuditWorker::provider_config( $settings );

		self::assertSame( 'sk-url-audit-sample', $config['api_key'], 'custom=独立凭证只用于网址核查' );
		self::assertSame( 'https://url-judge.example.com', $config['base_url'] );
		self::assertSame( 'deepseek-flash', $config['model'] );
		self::assertSame( 8, $config['timeout'], '独立凭证 timeout 固定 8' );
		self::assertSame( 0.8, $config['min_confidence'], '独立凭证 min_confidence 固定 0.8' );
		self::assertFalse( $config['two_stage'], '独立凭证不继承云审两段式' );
	}

	public function test_custom_mode_empty_base_url_and_model_fall_back_to_official_defaults(): void {
		$settings                     = $this->settingsWithCloudKey();
		$settings['url_audit']['provider'] = array(
			'mode'     => 'custom',
			'api_key'  => 'sk-url-audit-sample',
			'base_url' => '',
			'model'    => '',
		);

		$config = UrlAuditWorker::provider_config( $settings );

		self::assertSame( 'https://api.deepseek.com', $config['base_url'], 'base_url 空回落官方地址（非云审自定义地址）' );
		self::assertSame( 'deepseek-flash', $config['model'], 'model 空回落 deepseek-flash' );
	}

	public function test_custom_mode_with_blank_key_fails_safe_to_cloud_credentials(): void {
		// fail-safe：custom 但 key 空（站长选了独立却没填）→ 回落云审，判定链不断。
		$settings                     = $this->settingsWithCloudKey();
		$settings['url_audit']['provider'] = array(
			'mode'     => 'custom',
			'api_key'  => '',
			'base_url' => 'https://url-judge.example.com',
			'model'    => 'deepseek-flash',
		);

		$config = UrlAuditWorker::provider_config( $settings );

		self::assertSame( 'sk-cloud-review-sample', $config['api_key'], 'custom+空key → 回落云审（fail-safe）' );
	}

	/* ===================================================================
	 * sanitize 枚举 + schema↔rules 配对
	 * =================================================================== */

	public function test_provider_mode_enum_normalizes_invalid_to_inherit(): void {
		self::assertSame( 'inherit', Sanitize::clean_value( 'inherit', 'url_audit_provider_mode' ) );
		self::assertSame( 'custom', Sanitize::clean_value( 'custom', 'url_audit_provider_mode' ) );
		self::assertSame( 'inherit', Sanitize::clean_value( 'bogus', 'url_audit_provider_mode' ), '非法枚举归 inherit' );
		self::assertSame( 'inherit', Sanitize::clean_value( '', 'url_audit_provider_mode' ) );
		self::assertSame( 'inherit', Sanitize::clean_value( null, 'url_audit_provider_mode' ) );
	}

	public function test_provider_subtree_rules_pair_with_schema(): void {
		$rules = Sanitize::rules();
		self::assertSame( 'url_audit_provider_mode', $rules['url_audit.provider.mode'] );
		self::assertSame( 'password', $rules['url_audit.provider.api_key'] );
		self::assertSame( 'url', $rules['url_audit.provider.base_url'] );
		self::assertSame( 'text', $rules['url_audit.provider.model'] );
	}

	public function test_clean_tree_passes_provider_subtree_through(): void {
		list( $clean, $rejected ) = Sanitize::clean(
			array( 'url_audit' => array( 'provider' => array(
				'mode'     => 'custom',
				'api_key'  => 'sk-url-audit-sample',
				'base_url' => 'https://url-judge.example.com',
				'model'    => 'deepseek-flash',
			) ) )
		);
		self::assertSame( array(), $rejected );
		self::assertSame( 'custom', $clean['url_audit']['provider']['mode'] );
		self::assertSame( 'sk-url-audit-sample', $clean['url_audit']['provider']['api_key'] );
		self::assertSame( 'https://url-judge.example.com', $clean['url_audit']['provider']['base_url'] );
		self::assertSame( 'deepseek-flash', $clean['url_audit']['provider']['model'] );
	}

	public function test_defaults_carry_provider_subtree(): void {
		$provider = SettingsSchema::defaults()['url_audit']['provider'];
		self::assertSame( array( 'mode' => 'inherit', 'api_key' => '', 'base_url' => '', 'model' => '' ), $provider );
	}

	/* ===================================================================
	 * UI/JS 契约锚（源码契约——DOM 锚 + 联动范式）
	 * =================================================================== */

	public function test_url_audit_card_has_provider_mode_and_rows_anchors(): void {
		$src = file_get_contents( __DIR__ . '/../includes/settings/SettingsPage.php' );
		self::assertIsString( $src );
		self::assertStringContainsString( 'wk-url-audit-provider-mode', $src, 'mode 下拉 DOM 锚' );
		self::assertStringContainsString( 'wk-url-audit-provider-rows', $src, '三行独立配置容器 DOM 锚' );
		self::assertStringContainsString( "array( 'url_audit', 'provider', 'mode' )", $src, 'mode 键绑定' );
		self::assertStringContainsString( "array( 'url_audit', 'provider', 'api_key' )", $src, '独立 key password_field' );
		self::assertStringContainsString( "array( 'url_audit', 'provider', 'base_url' )", $src, '独立接入地址' );
		self::assertStringContainsString( "array( 'url_audit', 'provider', 'model' )", $src, '独立判定模型' );
	}

	public function test_admin_brand_js_binds_provider_mode_visibility(): void {
		$src = file_get_contents( __DIR__ . '/../assets/js/admin-brand.js' );
		self::assertIsString( $src );
		self::assertStringContainsString( "getElementById( 'wk-url-audit-provider-mode' )", $src, 'JS 绑定 mode 下拉' );
		self::assertStringContainsString( "getElementById( 'wk-url-audit-provider-rows' )", $src, 'JS 绑定三行容器' );
		self::assertMatchesRegularExpression(
			'/providerRows\.hidden = providerMode\.value !== \'custom\'/',
			$src,
			'仅 custom 显示三行（custom_header 联动范式）'
		);
	}
}
