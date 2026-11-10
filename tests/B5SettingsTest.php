<?php
/**
 * B5 设置面测试（05 §2.5【v1.5】/ 03 Tab③【v1.5】阿里文本审核增强版 PLUS 配置区 + 04 §2 schema 键）。
 *
 * 覆盖：
 *   - schema：review.cloud.aliyun_green 双 Service 键 + dual_dispatch 默认值 + merge_defaults 老树回填；
 *   - Sanitize：双 Service 名 text / dual_dispatch bool / AccessKey 两键 password 型（P0-α 教训）；
 *   - DriverPrivacy：aliyun_green 行 05 §2.5 口径（分通道送审/green-cip 境内不出境）；
 *   - Copywork::cloud_labels：05 §2.5 摘录族核对补缺（F4-3）；
 *   - DriverConfigSpec：registry 驱动下拉含 aliyun_green（已实装，不再是 future 里程碑项）；
 *   - SettingsPage AJAX handler 存在性（bootstrap 注册面在契约测试钉）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\DriverRegistry;
use WinkGatekeeper\Drivers\Review\AliyunGreenPlusDriver;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Settings\DriverConfigSpec;
use WinkGatekeeper\Settings\DriverPrivacy;
use WinkGatekeeper\Settings\Sanitize;
use WinkGatekeeper\Settings\SettingsSchema;
use WinkGatekeeper\Settings\SettingsPage;

final class B5SettingsTest extends TestCase {

	/* ---------- schema（04 §2 v1.5：review.aliyun_green 双 Service 键） ---------- */

	public function test_schema_defaults_include_green_service_keys(): void {
		$defaults = SettingsSchema::defaults();
		$green    = $defaults['review']['cloud']['aliyun_green'];
		$this->assertSame( 'nickname_detection_pro', $green['service_nickname'], '昵称 Service 默认 pro 值（04 §2 v1.5）' );
		$this->assertSame( 'comment_detection_pro', $green['service_comment'], '正文 Service 默认 pro 值' );
		$this->assertTrue( $green['dual_dispatch'], '双 Service 分送默认开（02 §2.2 例外条款）' );
		$this->assertSame( '', $green['access_key_id'] );
		$this->assertSame( '', $green['access_key_secret'] );
	}

	public function test_merge_defaults_backfills_green_keys_on_old_tree(): void {
		$old = array(
			'review' => array(
				'cloud' => array(
					'aliyun_green' => array( 'access_key_id' => 'LTAIold', 'access_key_secret' => 'sk-old' ),
				),
			),
		);
		$merged = SettingsSchema::merge_defaults( $old );
		$green  = $merged['review']['cloud']['aliyun_green'];
		$this->assertSame( 'LTAIold', $green['access_key_id'], '已有值不被覆盖' );
		$this->assertSame( 'nickname_detection_pro', $green['service_nickname'], '存量站点补齐 v1.5 新键' );
		$this->assertSame( 'comment_detection_pro', $green['service_comment'] );
		$this->assertTrue( $green['dual_dispatch'] );
	}

	/* ---------- Sanitize（schema↔rules 双向一致性由既有 SanitizeTest 全量封口） ---------- */

	public function test_sanitize_green_rules_types(): void {
		$rules = Sanitize::rules();
		// AccessKey 两键 password 型（P0-α 教训：password 渲染不回显 ⇒ password 型空串保留旧值）。
		$this->assertSame( 'password', $rules['review.cloud.aliyun_green.access_key_id'] );
		$this->assertSame( 'password', $rules['review.cloud.aliyun_green.access_key_secret'] );
		$this->assertSame( 'text', $rules['review.cloud.aliyun_green.service_nickname'] );
		$this->assertSame( 'text', $rules['review.cloud.aliyun_green.service_comment'] );
		$this->assertSame( 'bool', $rules['review.cloud.aliyun_green.dual_dispatch'] );
	}

	public function test_sanitize_dual_dispatch_bool(): void {
		$this->assertTrue( Sanitize::clean_value( '1', 'bool' ) );
		$this->assertFalse( Sanitize::clean_value( '0', 'bool' ) );
		$this->assertFalse( Sanitize::clean_value( '', 'bool' ) );
	}

	/* ---------- DriverPrivacy（05 §2.5 合规行口径） ---------- */

	public function test_driver_privacy_green_row_matches_spec(): void {
		$entry = DriverPrivacy::entry( 'aliyun_green' );
		$this->assertNotNull( $entry );
		$this->assertFalse( $entry['cross_border'], '境内 green-cip.cn-shanghai 不出境' );
		$this->assertSame( '昵称、邮箱、评论正文（分通道送审）', $entry['data_fields'], '05 §2.5 分通道送审口径' );
		$this->assertSame( '境内节点 green-cip.cn-shanghai，数据不出境', $entry['region_note'] );
	}

	/* ---------- F4-3：阿里标签映射核对补缺（05 §2.5 摘录族） ---------- */

	public function test_cloud_labels_cover_aliyun_green_excerpt_family(): void {
		$labels = Copywork::cloud_labels();
		// L0 实测命中的 pt_* 三标签（昵称分送真机证据锚）。
		$this->assertSame( '疑似站外引流', $labels['wk-label-pt_to_sites'] );
		$this->assertSame( '疑似引流广告号', $labels['wk-label-pt_to_contact'] );
		$this->assertSame( '疑似网赚兼职广告', $labels['wk-label-pt_by_recruitment'] );
		// 05 §2.5 摘录族核对补缺（B5 前缺）。
		$this->assertSame( '疑似不良价值观', $labels['wk-label-inappropriate_ethics'] );
		$this->assertSame( '疑似封建迷信', $labels['wk-label-inappropriate_superstition'] );
		$this->assertSame( '疑似性健康', $labels['wk-label-sexual_terms'] );
		$this->assertSame( '疑似政治人物', $labels['wk-label-political_figure'] );
		$this->assertSame( '疑似极端组织', $labels['wk-label-violent_extremist'] );
		$this->assertSame( '疑似武器弹药', $labels['wk-label-violent_weapons'] );
		$this->assertSame( '疑似毒品', $labels['wk-label-contraband_drug'] );
		$this->assertSame( '疑似赌博', $labels['wk-label-contraband_gambling'] );
		$this->assertSame( '涉佛教', $labels['wk-label-religion_b'] );
		$this->assertSame( '涉伊斯兰', $labels['wk-label-religion_i'] );
		$this->assertSame( '非 AI 生成', $labels['wk-label-ugc'] );
	}

	public function test_cloud_labels_all_use_wk_label_prefix(): void {
		foreach ( Copywork::cloud_labels() as $key => $zh ) {
			$this->assertStringStartsWith( 'wk-label-', $key );
			$this->assertNotSame( '', $zh );
		}
	}

	/* ---------- DriverConfigSpec：下拉登记（已实装，脱离 future 里程碑项） ---------- */

	public function test_driver_options_include_green_as_registered(): void {
		$registry = new DriverRegistry();
		$registry->register( AliyunGreenPlusDriver::registry_entry() );
		$options = DriverConfigSpec::driver_options(
			$registry->by_type( 'review' ),
			array( 'aliyun_green' => array( 'access_key_id' => 'id', 'access_key_secret' => 's', 'service_nickname' => 'n', 'service_comment' => 'c' ) )
		);
		$by_value = array();
		foreach ( $options as $option ) {
			$by_value[ $option['value'] ] = $option;
		}
		$this->assertArrayHasKey( 'aliyun_green', $by_value, '已实装驱动出现在下拉已实装段' );
		$this->assertSame( 'configured', $by_value['aliyun_green']['dot'] );
		$this->assertStringNotContainsString( 'M3', $by_value['aliyun_green']['label'], '已实装驱动不带里程碑后缀' );
	}

	public function test_driver_options_green_unconfigured_dot(): void {
		$options = DriverConfigSpec::driver_options(
			array( 'aliyun_green' => AliyunGreenPlusDriver::registry_entry() ),
			array( 'aliyun_green' => array( 'access_key_id' => '', 'access_key_secret' => '', 'service_nickname' => 'n', 'service_comment' => 'c' ) )
		);
		$this->assertSame( 'unconfigured', $options[1]['dot'], 'AK 两键缺失 = 未配置点（黄态派生）' );
	}

	public function test_chain_summary_includes_green_region_note(): void {
		$summary = DriverConfigSpec::chain_summary(
			array( 'aliyun_green' ),
			array( 'aliyun_green' => AliyunGreenPlusDriver::registry_entry() ),
			array( 'aliyun_green' => array( 'access_key_id' => 'id', 'access_key_secret' => 's', 'service_nickname' => 'n', 'service_comment' => 'c' ) )
		);
		$this->assertNotNull( $summary );
		$this->assertStringContainsString( '数据不出境', $summary );
	}

	/* ---------- SettingsPage AJAX 端点存在性 ---------- */

	public function test_ajax_endpoint_constant_and_handler_exist(): void {
		$this->assertSame( 'wink_gatekeeper_test_credentials_aliyun_green', SettingsPage::AJAX_TEST_CREDENTIALS_ALIYUN_GREEN );
		$this->assertTrue( method_exists( SettingsPage::class, 'ajax_test_credentials_aliyun_green' ) );
	}

	public function test_green_not_listed_as_future_driver(): void {
		// 已实装驱动不得再出现在 future 里程碑段（反射读私有方法输出核验）。
		$method = new \ReflectionMethod( SettingsPage::class, 'future_review_drivers' );
		$method->setAccessible( true );
		$future = $method->invoke( null );
		$this->assertArrayNotHasKey( 'aliyun_green', $future, 'M3 批次 3 已实装——脱离 future 下拉段' );
		$this->assertArrayHasKey( 'deepseek', $future );
	}
}
