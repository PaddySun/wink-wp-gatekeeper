<?php
/**
 * DriverConfigSpec 通用件单元测试（03 Tab③ v1.4·R4 §A.4：CaptchaTabSpec 范式泛化）。
 *
 * 覆盖：下拉选项集/状态点（registry settings.required 派生）/配置区显隐/缺失清单/
 * 服务端黄条（文案参数化）/chain_summary 链路汇总行/privacy_notice 合规行组装/
 * 必填清单从 registry settings 声明派生（Tab① 健康行同源）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\Review\TencentTmsDriver;
use WinkGatekeeper\Settings\DriverConfigSpec;

final class DriverConfigSpecTest extends TestCase {

	private static function tms_meta(): array {
		return TencentTmsDriver::registry_entry();
	}

	/* ---------- 必填清单（registry settings.required 派生） ---------- */

	public function test_required_fields_derived_from_registry_settings(): void {
		$this->assertSame( array( 'secret_id', 'secret_key' ), DriverConfigSpec::required_fields( self::tms_meta() ) );
	}

	public function test_required_fields_empty_when_none_declared(): void {
		$this->assertSame( array(), DriverConfigSpec::required_fields( array( 'settings' => array( 'biz_type' => array( 'type' => 'text' ) ) ) ) );
	}

	public function test_field_labels_derived_from_registry_settings(): void {
		$labels = DriverConfigSpec::field_labels( self::tms_meta() );
		$this->assertSame( 'SecretId（CAM）', $labels['secret_id'] );
		$this->assertSame( 'SecretKey（CAM）', $labels['secret_key'] );
	}

	/* ---------- 缺失清单 ---------- */

	public function test_missing_fields_lists_labels_for_empty_required_values(): void {
		$config = array( 'secret_id' => 'AKIDx', 'secret_key' => '', 'biz_type' => 'B' );
		$this->assertSame( array( 'SecretKey（CAM）' ), DriverConfigSpec::missing_fields( self::tms_meta(), $config ) );

		$this->assertSame( array(), DriverConfigSpec::missing_fields( self::tms_meta(), array( 'secret_id' => 'x', 'secret_key' => 'y' ) ), '齐全=空清单' );
	}

	/* ---------- 下拉选项集（Tab③ 消费） ---------- */

	public function test_review_driver_options_carry_dots_and_milestone_suffix(): void {
		$registered = array( 'tencent_tms' => self::tms_meta() );
		$future     = array( 'deepseek' => array( 'label' => 'DeepSeek', 'milestone' => 'M3', 'required' => array( 'api_key' ) ) );

		$options = DriverConfigSpec::driver_options( $registered, array( 'tencent_tms' => array( 'secret_id' => 'x', 'secret_key' => 'y' ) ), $future, array() );

		$this->assertSame( '', $options[0]['value'], '首项恒为「未配置」' );
		$this->assertSame( '未配置（跳过云端审核）', $options[0]['label'] );

		$tms = $options[1];
		$this->assertSame( 'tencent_tms', $tms['value'] );
		$this->assertSame( 'configured', $tms['dot'], 'required 声明字段全非空=已配置' );

		$deepseek = $options[2];
		$this->assertSame( 'deepseek', $deepseek['value'] );
		$this->assertSame( 'unconfigured', $deepseek['dot'] );
		$this->assertStringContainsString( 'M3', $deepseek['label'], '未实装驱动带里程碑后缀' );
	}

	public function test_review_driver_options_dot_unconfigured_when_credentials_missing(): void {
		$options = DriverConfigSpec::driver_options(
			array( 'tencent_tms' => self::tms_meta() ),
			array( 'tencent_tms' => array( 'secret_id' => '', 'secret_key' => '' ) ),
			array(),
			array()
		);
		$this->assertSame( 'unconfigured', $options[1]['dot'] );
	}

	public function test_review_driver_options_config_error_dot_from_halted_map(): void {
		$options = DriverConfigSpec::driver_options(
			array( 'tencent_tms' => self::tms_meta() ),
			array( 'tencent_tms' => array( 'secret_id' => 'x', 'secret_key' => 'y' ) ),
			array(),
			array( 'tencent_tms' => 'config_error' )
		);
		$this->assertSame( 'error', $options[1]['dot'], '运行时「配置错误」点接 DriverHealth 状态机' );
	}

	/* ---------- 配置区显隐（文案参数化） ---------- */

	public function test_config_area_hidden_note_parameterized_for_review(): void {
		$state = DriverConfigSpec::config_area_state( '', '未配置 = 云端审核阶段跳过（enabled 开 + 未配置 = 云端阶段跳过，与 Tab② 语义对齐）' );
		$this->assertFalse( $state['visible'] );
		$this->assertStringContainsString( '云端审核阶段跳过', $state['hidden_note'] );

		$state = DriverConfigSpec::config_area_state( 'tencent_tms', '未配置 = 云端审核阶段跳过' );
		$this->assertTrue( $state['visible'] );
		$this->assertSame( 'tencent_tms', $state['vendor'] );
	}

	/* ---------- 服务端黄条（双层校验第二层） ---------- */

	public function test_server_notice_lists_missing_fields_for_selected_driver(): void {
		$notice = DriverConfigSpec::server_notice(
			'tencent_tms',
			self::tms_meta(),
			array( 'secret_id' => '', 'secret_key' => '' ),
			'云端审核'
		);
		$this->assertStringContainsString( '腾讯云 TMS 文本审核', $notice );
		$this->assertStringContainsString( 'SecretId（CAM）', $notice );
		$this->assertStringContainsString( 'SecretKey（CAM）', $notice );
	}

	public function test_server_notice_null_when_nothing_missing_or_unselected(): void {
		$this->assertNull( DriverConfigSpec::server_notice( '', self::tms_meta(), array(), '云端审核' ) );
		$this->assertNull( DriverConfigSpec::server_notice( 'tencent_tms', self::tms_meta(), array( 'secret_id' => 'x', 'secret_key' => 'y' ), '云端审核' ) );
	}

	/* ---------- 链路汇总行（Tab③ 专属件，纯函数可单测） ---------- */

	public function test_chain_summary_lists_local_rules_then_driver_with_privacy_digest(): void {
		$summary = DriverConfigSpec::chain_summary(
			array( 'tencent_tms' ),
			array( 'tencent_tms' => self::tms_meta() ),
			array( 'tencent_tms' => array( 'secret_id' => 'x', 'secret_key' => 'y' ) )
		);
		$this->assertNotNull( $summary );
		$this->assertStringContainsString( '本地规则', $summary );
		$this->assertStringContainsString( '腾讯云 TMS 文本审核', $summary );
		$this->assertStringContainsString( '境内', $summary, '合规摘要=region_note 短语' );
	}

	public function test_chain_summary_null_when_chain_effectively_empty(): void {
		$this->assertNull( DriverConfigSpec::chain_summary( array(), array(), array() ) );
	}

	/* ---------- 合规行（R4 §C.3 逐驱动） ---------- */

	public function test_privacy_notice_assembles_per_driver_row(): void {
		$row = DriverConfigSpec::privacy_notice( self::tms_meta(), '腾讯云 TMS 文本审核' );
		$this->assertSame( 'normal', $row['level'], 'cross_border=false → 浅色 notice（非黄条）' );
		$this->assertStringContainsString( '启用 腾讯云 TMS 文本审核 即向第三方', $row['text'] );
		$this->assertStringContainsString( '提供访客信息', $row['text'] );
		$this->assertStringContainsString( '昵称、邮箱、评论正文', $row['text'], 'data_fields 人话描述' );
		$this->assertStringContainsString( '数据不出境', $row['text'], 'region_note 并入' );
		$this->assertStringContainsString( 'https://cloud.tencent.com/document/product/301/1967', $row['text'] );
	}

	public function test_privacy_notice_upgrades_to_warn_when_cross_border(): void {
		$meta = self::tms_meta();
		$meta['privacy'] = array_merge( $meta['privacy'], array( 'cross_border' => true, 'region_note' => '境外服务' ) );
		$row = DriverConfigSpec::privacy_notice( $meta, 'hCaptcha' );
		$this->assertSame( 'warn', $row['level'], '出境=黄条（PIPL 最重档）' );
	}

	public function test_privacy_notice_warn_and_unknown_region_when_cross_border_null(): void {
		$meta = self::tms_meta();
		$meta['privacy'] = array_merge( $meta['privacy'], array( 'cross_border' => null, 'privacy_url' => '' ) );
		$row = DriverConfigSpec::privacy_notice( $meta, '自定义 OpenAI 兼容端点' );
		$this->assertSame( 'warn', $row['level'] );
		$this->assertStringContainsString( '辖区未知', $row['text'], 'null=黄条+辖区未知提示（通用出境提示原文迁入）' );
	}

	public function test_privacy_notice_null_when_driver_has_no_privacy(): void {
		$this->assertNull( DriverConfigSpec::privacy_notice( array( 'label' => '本地规则' ), '本地规则' ), '本地驱动无第三方外送，无合规行' );
	}

	/* ---------- chain 单元素收口（下拉 hidden 伴随域） ---------- */

	public function test_chain_value_from_select_submission_filters_empty_companion(): void {
		// select chain[]=tencent_tms + hidden chain[]='' → 单元素数组（hidden 伴随域过滤为 Sanitize text_list 语义）。
		$raw    = array( '', 'tencent_tms' );
		$clean  = array_values( array_filter( array_map( 'strval', $raw ), static function ( string $v ): bool { return '' !== $v; } ) );
		$this->assertSame( array( 'tencent_tms' ), $clean );
	}
}
