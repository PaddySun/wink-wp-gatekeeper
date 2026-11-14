<?php
/**
 * M5 修复批 B · hidden 0 伴随域补齐回归测试（缺陷2）。
 *
 * 根因：六处 checkbox 无 hidden 0 伴随域——取消勾选后提交树缺该键，保存桥
 * merge_defaults「已有值优先」把旧存储 true 原样保留=开关关不掉。
 * 修复：写法对齐 review.cloud.enabled 标准形态（SettingsPage checkbox 之前补
 * 同名 hidden value="0"），未勾选提交 '0' → bool 清洗 false 显式落盘。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Settings\Sanitize;

final class M5FixHiddenCompanionTest extends TestCase {

	/**
	 * 六处补齐落点：hidden 伴随域与 checkbox 的源码片段（SettingsPage 两种 name 形态——
	 * field_name() 动态生成 / 硬编码字面串——hidden 与 checkbox 同名照抄各自形态）。
	 *
	 * @return array<string, array{hidden: string, checkbox: string}>
	 */
	private static function companions(): array {
		$fn = static function ( string $php_path ): string {
			return 'name="<?php echo esc_attr( self::field_name( array( ' . $php_path . ' ) ) ); ?>"';
		};
		return array(
			'review.cloud.two_stage'      => array(
				'hidden'   => '<input type="hidden" ' . $fn( "'review', 'cloud', 'two_stage'" ) . ' value="0" />',
				'checkbox' => '<input type="checkbox" ' . $fn( "'review', 'cloud', 'two_stage'" ) . ' value="1"',
			),
			'url_audit.enabled'           => array(
				'hidden'   => '<input type="hidden" ' . $fn( "'url_audit', 'enabled'" ) . ' value="0" />',
				'checkbox' => '<input type="checkbox" ' . $fn( "'url_audit', 'enabled'" ) . ' value="1"',
			),
			'url_audit.bocha.enabled'     => array(
				'hidden'   => '<input type="hidden" ' . $fn( "'url_audit', 'bocha', 'enabled'" ) . ' value="0" />',
				'checkbox' => '<input type="checkbox" ' . $fn( "'url_audit', 'bocha', 'enabled'" ) . ' value="1"',
			),
			'url_audit.robots_comply'     => array(
				'hidden'   => '<input type="hidden" ' . $fn( "'url_audit', 'robots_comply'" ) . ' value="0" />',
				'checkbox' => '<input type="checkbox" ' . $fn( "'url_audit', 'robots_comply'" ) . ' value="1"',
			),
			'comments.site_field.enabled' => array(
				'hidden'   => '<input type="hidden" name="wink_gatekeeper_settings[comments][site_field][enabled]" value="0" />',
				'checkbox' => '<input type="checkbox" name="wink_gatekeeper_settings[comments][site_field][enabled]" value="1"',
			),
			'comments.site_link.enabled'  => array(
				'hidden'   => '<input type="hidden" name="wink_gatekeeper_settings[comments][site_link][enabled]" value="0" />',
				'checkbox' => '<input type="checkbox" name="wink_gatekeeper_settings[comments][site_link][enabled]" value="1"',
			),
		);
	}

	private static function pageSource(): string {
		$src = file_get_contents( __DIR__ . '/../includes/settings/SettingsPage.php' );
		self::assertIsString( $src );
		return (string) $src;
	}

	/* ===================================================================
	 * 源码契约：每处 checkbox 之前有同名 hidden 0 伴随域
	 * =================================================================== */

	public function test_all_six_checkboxes_have_hidden_zero_companion_beforehand(): void {
		$src = self::pageSource();
		foreach ( self::companions() as $path => $snippets ) {
			$hidden_pos = strpos( $src, $snippets['hidden'] );
			self::assertNotFalse( $hidden_pos, "{$path}: 同名 hidden 0 伴随域必须存在（checkbox 之前）" );

			$checkbox_pos = strpos( $src, $snippets['checkbox'], $hidden_pos );
			self::assertNotFalse( $checkbox_pos, "{$path}: hidden 之后必须紧跟同名 checkbox（value=1）" );

			// 伴随域紧邻性：hidden 与 checkbox 之间只允许空白（对齐 review.cloud.enabled
			// 标准形态——同一 label 内成对出现，防「hidden 补在别处」假阳性）。
			$between = substr( $src, $hidden_pos + strlen( $snippets['hidden'] ), $checkbox_pos - $hidden_pos - strlen( $snippets['hidden'] ) );
			self::assertSame( '', trim( $between ), "{$path}: hidden 伴随域与 checkbox 必须紧邻" );
		}
	}

	/* ===================================================================
	 * 行为面：显式 hidden 0 提交 → sanitize 后 false 持久化
	 * =================================================================== */

	public function test_explicit_hidden_zero_persists_false_for_site_field(): void {
		// 已存 true 的站取消勾选：hidden 0 伴随域使提交树含显式 '0'——bool 清洗归
		// false 落盘（修复前键缺位 → merge_defaults 旧 true 原样保留=开关失灵）。
		list( $clean, $rejected ) = Sanitize::clean(
			array( 'comments' => array( 'site_field' => array( 'enabled' => '0' ) ) )
		);
		self::assertSame( array(), $rejected );
		self::assertFalse( $clean['comments']['site_field']['enabled'], '显式 0 → false 持久化' );
	}

	public function test_explicit_hidden_zero_persists_false_for_url_audit(): void {
		list( $clean, $rejected ) = Sanitize::clean(
			array( 'url_audit' => array( 'enabled' => '0', 'robots_comply' => '0' ) )
		);
		self::assertSame( array(), $rejected );
		self::assertFalse( $clean['url_audit']['enabled'] );
		self::assertFalse( $clean['url_audit']['robots_comply'] );
	}

	public function test_explicit_hidden_zero_persists_false_for_two_stage(): void {
		list( $clean, $rejected ) = Sanitize::clean(
			array( 'review' => array( 'cloud' => array( 'two_stage' => '0' ) ) )
		);
		self::assertSame( array(), $rejected );
		self::assertFalse( $clean['review']['cloud']['two_stage'] );
	}
}
