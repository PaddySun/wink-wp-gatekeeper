<?php
/**
 * B3 设置面测试（05 §1.2 / 03 Tab②【v1.5】阿里验证码 2.0 配置区 + 04 §2 v1.5 schema 键）。
 *
 * 覆盖：
 *   - schema：captcha.aliyun.mode 默认 embed + merge_defaults 老树回填；
 *   - Sanitize：mode 枚举（embed|popup，非法值归 embed）、access_key_id/access_key_secret
 *     password 型（P0-α 教训：password 渲染（不回显）⇒ password 型空串保留旧值）；
 *   - CaptchaTabSpec：阿里字段显示名/缺失清单/驱动下拉状态点/服务端黄条/接入指引；
 *   - DriverPrivacy：aliyun 行 data_fields 与 05 §1.2 registry 行一致；
 *   - SettingsPage AJAX handler 存在性（bootstrap 注册面在 FrontendJs/B2 契约测试钉）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Settings\CaptchaTabSpec;
use WinkGatekeeper\Settings\DriverPrivacy;
use WinkGatekeeper\Settings\Sanitize;
use WinkGatekeeper\Settings\SettingsSchema;

final class B3SettingsTest extends TestCase {

	/* ---------- schema（04 §2 v1.5：captcha.aliyun.mode） ---------- */

	public function test_schema_defaults_include_aliyun_mode(): void {
		$defaults = SettingsSchema::defaults();
		$this->assertArrayHasKey( 'mode', $defaults['captcha']['aliyun'] );
		$this->assertSame( 'embed', $defaults['captcha']['aliyun']['mode'], 'embed 默认（页内触发条+SDK 自管弹层）' );
		$this->assertSame( '', $defaults['captcha']['aliyun']['access_key_id'] );
		$this->assertSame( '', $defaults['captcha']['aliyun']['access_key_secret'] );
		$this->assertSame( '', $defaults['captcha']['aliyun']['scene_id'] );
		$this->assertSame( '', $defaults['captcha']['aliyun']['prefix'] );
	}

	public function test_merge_defaults_backfills_mode_on_old_tree(): void {
		$old = array(
			'captcha' => array(
				'aliyun' => array( 'access_key_id' => 'LTAIold', 'access_key_secret' => 'sk-old', 'scene_id' => 's', 'prefix' => 'p' ),
			),
		);
		$merged = SettingsSchema::merge_defaults( $old );
		$this->assertSame( 'embed', $merged['captcha']['aliyun']['mode'], '存量站点 merge_defaults 补齐 v1.5 新键' );
		$this->assertSame( 'LTAIold', $merged['captcha']['aliyun']['access_key_id'], '已有值不被覆盖' );
	}

	/* ---------- Sanitize（schema↔rules 双向一致性由既有 SanitizeTest 全量封口） ---------- */

	public function test_sanitize_mode_enum_falls_back_to_embed(): void {
		$this->assertSame( 'embed', Sanitize::clean_value( 'embed', 'aliyun_mode' ) );
		$this->assertSame( 'popup', Sanitize::clean_value( 'popup', 'aliyun_mode' ) );
		$this->assertSame( 'embed', Sanitize::clean_value( 'floating', 'aliyun_mode' ), '非法值归 embed（fail-safe）' );
		$this->assertSame( 'embed', Sanitize::clean_value( array(), 'aliyun_mode' ) );
	}

	public function test_sanitize_aliyun_rules_types(): void {
		$rules = Sanitize::rules();
		$this->assertSame( 'password', $rules['captcha.aliyun.access_key_id'], 'P0-α 教训：password 渲染 ⇒ password 型（空串保留旧值）' );
		$this->assertSame( 'password', $rules['captcha.aliyun.access_key_secret'] );
		$this->assertSame( 'text', $rules['captcha.aliyun.scene_id'] );
		$this->assertSame( 'text', $rules['captcha.aliyun.prefix'] );
		$this->assertSame( 'aliyun_mode', $rules['captcha.aliyun.mode'] );
	}

	public function test_sanitize_clean_tree_carries_aliyun_mode(): void {
		list( $clean, $rejected ) = Sanitize::clean( array(
			'captcha' => array( 'aliyun' => array( 'mode' => 'popup', 'scene_id' => 's1' ) ),
		) );
		$this->assertSame( 'popup', $clean['captcha']['aliyun']['mode'] );
		$this->assertSame( 's1', $clean['captcha']['aliyun']['scene_id'] );
		$this->assertSame( array(), $rejected );
	}

	/* ---------- CaptchaTabSpec：阿里配置区纯逻辑 ---------- */

	public function test_aliyun_field_labels_and_missing_fields(): void {
		$labels = CaptchaTabSpec::aliyun_field_labels();
		$this->assertSame( array( 'access_key_id', 'access_key_secret', 'scene_id', 'prefix' ), array_keys( $labels ) );

		$this->assertSame( array(), CaptchaTabSpec::aliyun_missing_fields( array(
			'access_key_id' => 'a', 'access_key_secret' => 's', 'scene_id' => 'x', 'prefix' => 'p',
		) ) );
		$missing = CaptchaTabSpec::aliyun_missing_fields( array(
			'access_key_id' => '', 'access_key_secret' => ' ', 'scene_id' => 'x', 'prefix' => '',
		) );
		$this->assertSame( array( 'AccessKeyId', 'AccessKeySecret', 'prefix（身份标）' ), $missing, '逐字段列出（黄态不发起调用）' );
	}

	public function test_driver_options_aliyun_dot_status(): void {
		$drivers = array(
			'aliyun'  => array( 'label' => '阿里云验证码 2.0' ),
			'tencent' => array( 'label' => '腾讯天御验证码 2.0' ),
		);
		$options = CaptchaTabSpec::driver_options( $drivers, array(
			'app_id' => 1, 'secret_id' => 'a', 'secret_key' => 'b', 'app_secret_key' => 'c',
		), array(
			'access_key_id' => 'a', 'access_key_secret' => 's', 'scene_id' => 'x', 'prefix' => 'p',
		) );
		$by_id = array();
		foreach ( $options as $option ) {
			$by_id[ $option['value'] ] = $option;
		}
		$this->assertSame( 'configured', $by_id['aliyun']['dot'] ?? '' );
		$this->assertSame( 'configured', $by_id['tencent']['dot'] ?? '' );

		$unconfigured = CaptchaTabSpec::driver_options( $drivers, array(), array() );
		foreach ( $unconfigured as $option ) {
			if ( 'aliyun' === $option['value'] || 'tencent' === $option['value'] ) {
				$this->assertSame( 'unconfigured', $option['dot'] ?? '' );
			}
		}
	}

	public function test_server_notice_for_aliyun_missing_fields(): void {
		$this->assertNull( CaptchaTabSpec::server_notice( 'aliyun', array(), array(
			'access_key_id' => 'a', 'access_key_secret' => 's', 'scene_id' => 'x', 'prefix' => 'p',
		) ) );
		$notice = CaptchaTabSpec::server_notice( 'aliyun', array(), array(
			'access_key_id' => 'a', 'access_key_secret' => '', 'scene_id' => '', 'prefix' => 'p',
		) );
		$this->assertIsString( $notice );
		$this->assertStringContainsString( '阿里云验证码 2.0', $notice );
		$this->assertStringContainsString( 'AccessKeySecret', $notice );
		$this->assertStringContainsString( 'SceneId', $notice );
	}

	public function test_aliyun_guide_shape(): void {
		$guide = CaptchaTabSpec::aliyun_guide();
		$this->assertNotEmpty( $guide['steps'] );
		$this->assertStringContainsString( 'SceneId', implode( '', $guide['steps'] ) );
		$this->assertStringContainsString( 'prefix', implode( '', $guide['steps'] ) );
		$this->assertStringContainsString( 'captcha2.console.aliyun.com', $guide['console_url'] );
		$this->assertStringContainsString( '动态引入', implode( '', $guide['steps'] ) . $guide['ticket_note'], 'JS 动态引入红线进指引' );
	}

	/* ---------- DriverPrivacy（05 §1.2 registry 行 privacy 维） ---------- */

	public function test_driver_privacy_aliyun_row_matches_spec(): void {
		$row = DriverPrivacy::entry( 'aliyun' );
		$this->assertNotNull( $row );
		$this->assertFalse( $row['cross_border'], '境内，数据不出境' );
		$this->assertSame( '访客 IP（行为采集，经阿里客户端 SDK）、CaptchaVerifyParam', $row['data_fields'] );
		$this->assertSame( '境内，数据不出境', $row['region_note'] );
	}
}
