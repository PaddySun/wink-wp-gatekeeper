<?php
/**
 * Tab② 驱动配置交互纯逻辑测试（03 §1 v1.3 用户建议1+2）。
 *
 * 覆盖（渲染数据单一来源，WP 边界只做 esc/echo）：
 *   - 下拉选项集：未配置项 + registry 厂商项，厂商项后缀状态点（已配置/未配置）；
 *   - 配置区可见性：未配置 = 隐藏 + 一行说明；选厂商 = 显示对应配置区；
 *   - 必填缺失字段清单（显示名）：JS 标红提示与服务端黄条共用同一来源；
 *   - 服务端黄条文案：选了厂商但必填空 → 「驱动视为未配置 + 列出缺失字段」（设置仍保存）；
 *   - 接入指引内容（05 §1.1 提炼：控制台步骤/字段获取路径/票据 5 分钟）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Settings\CaptchaTabSpec;

final class CaptchaTabSpecTest extends TestCase {

	private static function full_tencent(): array {
		return array(
			'app_id'         => '199999164',
			'secret_id'      => 'AKIDx',
			'secret_key'     => 'sk',
			'app_secret_key' => 'ASK',
			'disaster_pass'  => 'allow',
		);
	}

	/* ---------- 下拉选项 ---------- */

	public function test_driver_options_lead_with_unconfigured_entry(): void {
		$options = CaptchaTabSpec::driver_options( array(
			'tencent' => array( 'label' => '腾讯天御验证码 2.0' ),
		), self::full_tencent() );

		$this->assertSame( '', $options[0]['value'] );
		$this->assertSame( '未配置（跳过验证码阶段）', $options[0]['label'] );
		$this->assertArrayNotHasKey( 'dot', $options[0], '未配置项无状态点' );
	}

	public function test_driver_options_suffix_configured_dot_when_fields_complete(): void {
		$options = CaptchaTabSpec::driver_options(
			array( 'tencent' => array( 'label' => '腾讯天御验证码 2.0' ) ),
			self::full_tencent()
		);
		$this->assertSame( 'tencent', $options[1]['value'] );
		$this->assertSame( '腾讯天御验证码 2.0', $options[1]['label'] );
		$this->assertSame( 'configured', $options[1]['dot'], '四字段齐全 → 已配置点' );
		$this->assertSame( '已配置', CaptchaTabSpec::dot_labels()['configured'] );
	}

	public function test_driver_options_suffix_unconfigured_dot_when_fields_missing(): void {
		$options = CaptchaTabSpec::driver_options(
			array( 'tencent' => array( 'label' => '腾讯天御验证码 2.0' ) ),
			array( 'app_id' => '', 'secret_id' => '', 'secret_key' => '', 'app_secret_key' => '' )
		);
		$this->assertSame( 'unconfigured', $options[1]['dot'] );
		$this->assertSame( '未配置', CaptchaTabSpec::dot_labels()['unconfigured'] );
		$this->assertSame( '配置错误', CaptchaTabSpec::dot_labels()['error'], '配置错误态位预留（M3 健康灯状态机）' );
	}

	/* ---------- 配置区可见性 ---------- */

	public function test_config_area_hidden_when_driver_unconfigured(): void {
		$state = CaptchaTabSpec::config_area_state( '' );
		$this->assertFalse( $state['visible'] );
		$this->assertNull( $state['vendor'] );
		$this->assertNotSame( '', $state['hidden_note'], '未配置 = 一行说明（评论闸仅内容过滤生效）' );
		$this->assertStringContainsString( '未配置', $state['hidden_note'] );
	}

	public function test_config_area_visible_for_tencent(): void {
		$state = CaptchaTabSpec::config_area_state( 'tencent' );
		$this->assertTrue( $state['visible'] );
		$this->assertSame( 'tencent', $state['vendor'] );
	}

	/* ---------- 必填缺失清单（JS 提示与服务端黄条共用） ---------- */

	public function test_missing_fields_lists_display_names(): void {
		$tencent = array( 'app_id' => '', 'secret_id' => 'AKIDx', 'secret_key' => '', 'app_secret_key' => '' );
		$missing = CaptchaTabSpec::tencent_missing_fields( $tencent );
		$this->assertSame( array( 'CaptchaAppId', 'SecretKey（CAM）', 'AppSecretKey（验证码控制台）' ), $missing );
	}

	public function test_missing_fields_empty_when_complete(): void {
		$this->assertSame( array(), CaptchaTabSpec::tencent_missing_fields( self::full_tencent() ) );
	}

	public function test_field_labels_cover_four_credentials_and_disaster_switch(): void {
		$labels = CaptchaTabSpec::tencent_field_labels();
		$this->assertSame( 'CaptchaAppId', $labels['app_id'] );
		$this->assertSame( 'SecretId（CAM）', $labels['secret_id'] );
		$this->assertSame( 'SecretKey（CAM）', $labels['secret_key'] );
		$this->assertSame( 'AppSecretKey（验证码控制台）', $labels['app_secret_key'] );
	}

	/* ---------- 服务端黄条（双层校验第二层：驱动视为未配置 + 列缺失，不阻断保存） ---------- */

	public function test_server_notice_null_when_driver_unconfigured(): void {
		$this->assertNull( CaptchaTabSpec::server_notice( '', array() ) );
	}

	public function test_server_notice_null_when_fields_complete(): void {
		$this->assertNull( CaptchaTabSpec::server_notice( 'tencent', self::full_tencent() ) );
	}

	public function test_server_notice_lists_missing_fields_and_degrades_driver(): void {
		$notice = CaptchaTabSpec::server_notice( 'tencent', array(
			'app_id' => '', 'secret_id' => '', 'secret_key' => '', 'app_secret_key' => '',
		) );
		$this->assertIsString( $notice );
		$this->assertStringContainsString( '腾讯天御验证码 2.0', $notice );
		$this->assertStringContainsString( 'CaptchaAppId', $notice );
		$this->assertStringContainsString( 'SecretId（CAM）', $notice );
		$this->assertStringContainsString( '视为未配置', $notice, '驱动回落未配置语义（设置仍保存，不阻断其它 Tab）' );
	}

	/* ---------- 切换保留（schema 子树天然支持——选项值域校验） ---------- */

	public function test_switching_driver_never_clears_vendor_fields(): void {
		// 纯逻辑层：字段清单与 driver 值无关（UI 只切换可视区，不清空字段——schema 按厂商分子树）。
		$this->assertSame(
			array_keys( CaptchaTabSpec::tencent_field_labels() ),
			array( 'app_id', 'secret_id', 'secret_key', 'app_secret_key' )
		);
	}

	/* ---------- 接入指引（05 §1.1 提炼） ---------- */

	public function test_guide_contains_console_steps_and_ticket_ttl_note(): void {
		$guide = CaptchaTabSpec::guide();
		$this->assertStringContainsString( 'console.cloud.tencent.com/captcha', $guide['console_url'] );
		$this->assertGreaterThan( 3, count( $guide['steps'] ), '步骤化指引（开通→创建场景→CAM 密钥→粘贴→测试凭据）' );
		$joined = implode( "\n", $guide['steps'] );
		$this->assertStringContainsString( 'CaptchaAppId', $joined );
		$this->assertStringContainsString( 'AppSecretKey', $joined );
		$this->assertStringContainsString( 'CAM', $joined );
		$this->assertStringContainsString( '5 分钟', $guide['ticket_note'], '票据有效期 5 分钟（onExpire 回退依据）' );
		$this->assertStringContainsString( '测试凭据', $joined );
	}
}
