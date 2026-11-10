<?php
/**
 * B6 设置面测试（05 §2.4 百度行 / 03 Tab③ 百度配置区 + 04 §2 schema——轻量批，无新 schema 键）。
 *
 * 覆盖：
 *   - schema：review.cloud.baidu 子树默认值已落位（M0）——驱动消费面核对；
 *   - Sanitize：api_key/secret_key password 型（P0-α 教训：不回显+空串保留旧值）；
 *   - DriverPrivacy：baidu 行合规口径（境内不出境 + 拼接串送审字段）；
 *   - DriverConfigSpec：registry 驱动下拉含 baidu（已实装，不再是 future 里程碑项）+
 *     链路汇总行 + 服务端黄条；
 *   - SettingsPage AJAX 端点存在性（bootstrap 注册面在契约测试钉）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\Review\BaiduTextModerationDriver;
use WinkGatekeeper\Settings\DriverConfigSpec;
use WinkGatekeeper\Settings\DriverPrivacy;
use WinkGatekeeper\Settings\Sanitize;
use WinkGatekeeper\Settings\SettingsPage;
use WinkGatekeeper\Settings\SettingsSchema;

final class B6SettingsTest extends TestCase {

	/* ---------- schema（04 §2：review.cloud.baidu 子树——M0 落位，B6 消费核对） ---------- */

	public function test_schema_defaults_baidu_subtree(): void {
		$baidu = SettingsSchema::defaults()['review']['cloud']['baidu'];
		$this->assertSame( '', $baidu['api_key'] );
		$this->assertSame( '', $baidu['secret_key'] );
		$this->assertSame( 8, $baidu['timeout'] );
		$this->assertSame( 500, $baidu['daily_quota'], '04 §2 默认 500 次/天' );
		$this->assertSame( 'review', $baidu['fail_mode'] );
	}

	public function test_merge_defaults_backfills_baidu_keys_on_old_tree(): void {
		$merged = SettingsSchema::merge_defaults( array(
			'review' => array( 'cloud' => array( 'baidu' => array( 'api_key' => 'AK-old' ) ) ),
		) );
		$baidu  = $merged['review']['cloud']['baidu'];
		$this->assertSame( 'AK-old', $baidu['api_key'], '已有值不被覆盖' );
		$this->assertSame( '', $baidu['secret_key'] );
		$this->assertSame( 500, $baidu['daily_quota'] );
	}

	/* ---------- Sanitize ---------- */

	public function test_sanitize_baidu_rules_types(): void {
		$rules = Sanitize::rules();
		$this->assertSame( 'password', $rules['review.cloud.baidu.api_key'], 'password 型（P0-α 教训：不回显+空串保留旧值）' );
		$this->assertSame( 'password', $rules['review.cloud.baidu.secret_key'] );
		$this->assertSame( 'absint', $rules['review.cloud.baidu.timeout'] );
		$this->assertSame( 'absint', $rules['review.cloud.baidu.daily_quota'] );
	}

	/* ---------- DriverPrivacy（05 §4 合规行口径——百度境内） ---------- */

	public function test_driver_privacy_baidu_row_matches_spec(): void {
		$entry = DriverPrivacy::entry( 'baidu' );
		$this->assertNotNull( $entry );
		$this->assertFalse( $entry['cross_border'], '百度境内不出境' );
		$this->assertStringContainsString( '昵称', $entry['data_fields'] );
		$this->assertStringContainsString( '邮箱不送审', $entry['data_fields'], '真机实锤：邮箱地址本身判 36-广告 360104 误杀（IMPL-B6 偏差 1）——邮箱不送审' );
		$this->assertStringContainsString( '境内', $entry['region_note'] );
		$this->assertStringContainsString( 'aip.baidubce.com', $entry['region_note'], 'B6 起注记境内端点' );
	}

	/* ---------- DriverConfigSpec：下拉登记（已实装，脱离 future 里程碑项） ---------- */

	private static function baidu_config( string $api_key = 'AK', string $secret_key = 'SK' ): array {
		return array( 'api_key' => $api_key, 'secret_key' => $secret_key, 'timeout' => 8, 'daily_quota' => 500, 'fail_mode' => 'review' );
	}

	public function test_driver_options_include_baidu_as_registered(): void {
		$registered = array( 'baidu' => BaiduTextModerationDriver::registry_entry() );
		$options    = DriverConfigSpec::driver_options( $registered, array( 'baidu' => self::baidu_config() ) );
		$this->assertSame( 'baidu', $options[1]['value'], '已实装驱动出现在下拉已实装段' );
		$this->assertSame( 'configured', $options[1]['dot'] );
		$this->assertSame( '百度文本审核', $options[1]['label'] );
	}

	public function test_driver_options_baidu_unconfigured_dot(): void {
		$registered = array( 'baidu' => BaiduTextModerationDriver::registry_entry() );
		$options    = DriverConfigSpec::driver_options( $registered, array( 'baidu' => self::baidu_config( '', '' ) ) );
		$this->assertSame( 'unconfigured', $options[1]['dot'], '双键缺失 = 未配置点（黄态派生）' );
	}

	public function test_server_notice_lists_missing_fields_for_baidu(): void {
		$notice = DriverConfigSpec::server_notice( 'baidu', BaiduTextModerationDriver::registry_entry(), self::baidu_config( 'AK', '' ), '云端审核' );
		$this->assertNotNull( $notice );
		$this->assertStringContainsString( 'Secret Key', $notice, '双层校验第二层黄条列出缺失字段' );
	}

	public function test_chain_summary_includes_baidu_region_note(): void {
		$registered = array( 'baidu' => BaiduTextModerationDriver::registry_entry() );
		$summary    = DriverConfigSpec::chain_summary( array( 'baidu' ), $registered, array( 'baidu' => self::baidu_config() ) );
		$this->assertNotNull( $summary );
		$this->assertStringContainsString( '百度文本审核', $summary );
		$this->assertStringContainsString( '数据不出境', $summary );
	}

	public function test_privacy_notice_normal_level_for_domestic_baidu(): void {
		$notice = DriverConfigSpec::privacy_notice( BaiduTextModerationDriver::registry_entry(), '百度文本审核' );
		$this->assertNotNull( $notice );
		$this->assertSame( 'normal', $notice['level'], '境内驱动合规行为常规档（非黄条）' );
		$this->assertStringContainsString( '百度', $notice['text'] );
	}

	/* ---------- SettingsPage AJAX 端点存在性 ---------- */

	public function test_ajax_endpoint_constant_and_handler_exist(): void {
		$this->assertSame( 'wink_gatekeeper_test_credentials_baidu', SettingsPage::AJAX_TEST_CREDENTIALS_BAIDU );
		$this->assertTrue( method_exists( SettingsPage::class, 'ajax_test_credentials_baidu' ) );
	}

	public function test_baidu_not_listed_as_future_driver(): void {
		// 已实装驱动不得再出现在 future 里程碑段（反射读私有方法输出核验）。
		$method = new \ReflectionMethod( SettingsPage::class, 'future_review_drivers' );
		$method->setAccessible( true );
		$future = $method->invoke( null );
		$this->assertArrayNotHasKey( 'baidu', $future, 'B6 已实装——脱离 future 下拉段' );
		$this->assertArrayHasKey( 'deepseek', $future, '其余 M3/M4 驱动保留 future 段' );
	}
}
