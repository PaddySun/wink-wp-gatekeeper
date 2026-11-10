<?php
/**
 * SettingsPage::sanitize() 保存桥行为测试（M2 debug FIX-α，2026-11-10）。
 *
 * 复现 P0-α（DEFECT-ROOTCAUSE-2026-11-10）：secret_id 由 password_field() 渲染为
 * 不回显密码框（value="" 永远空提交），若 Sanitize 类型表为 text，则 SettingsPage
 * 的「password 型空串保留已存值」写侧逻辑对其不生效 → Tab② 二次保存即丢 SecretId。
 *
 * 注入方式：在被测类同命名空间（WinkGatekeeper\Settings）定义 get_option 桩，
 * PHP 函数解析规则先命中命名空间函数——单测环境零 WP 依赖即可覆盖 WP 边界桥
 * （04 §2 v1.3 保存语义契约：password 型敏感字段空串保留旧值）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Settings {

	/** 桩 option 存储（键 => 值；测试内直接读写模拟 WP option 表）。 */
	$GLOBALS['wk_bridge_option_store'] = array();

	if ( ! function_exists( 'WinkGatekeeper\Settings\get_option' ) ) {
		/**
		 * get_option 桩：查桩存储，缺键返默认（模拟 WP get_option 语义的最小子集）。
		 *
		 * @param string $name    option 名。
		 * @param mixed  $default 缺省值。
		 * @return mixed
		 */
		function get_option( string $name, $default = false ) {
			return array_key_exists( $name, $GLOBALS['wk_bridge_option_store'] )
				? $GLOBALS['wk_bridge_option_store'][ $name ]
				: $default;
		}
	}
}

namespace WinkGatekeeper\Tests {

	use PHPUnit\Framework\TestCase;
	use WinkGatekeeper\Settings\SettingsPage;

	final class SettingsPageSanitizeBridgeTest extends TestCase {

		/**
		 * 模拟一次 WP 保存流程：sanitize 桥（register_setting 回调）→ 结果写回 option。
		 *
		 * @param array<string, mixed> $stored 已存储 option 树。
		 * @param array<string, mixed> $input  本次表单提交（仅活动 Tab 字段）。
		 * @return array<string, mixed> 保存后的 option 树。
		 */
		private static function save( array $stored, array $input ): array {
			$GLOBALS['wk_bridge_option_store'][ SettingsPage::OPTION_NAME ] = $stored;
			$saved = SettingsPage::sanitize( $input );
			$GLOBALS['wk_bridge_option_store'][ SettingsPage::OPTION_NAME ] = $saved; // update_option 语义。
			return $saved;
		}

		/** @return array<string, mixed> 已配置齐全的存储树（四字段全值，U2 #10 首存后形态）。 */
		private static function stored_with_tencent_credentials(): array {
			return array(
				'schema_version' => 1,
				'gates'          => array(
					'comment' => array( 'enabled' => true, 'render_mode' => 'always' ),
					'login'   => array( 'enabled' => true, 'render_mode' => 'adaptive' ),
				),
				'captcha'        => array(
					'driver'  => 'tencent',
					'tencent' => array(
						'app_id'         => 199999164,
						'secret_id'      => 'AKIDstoredSecretId000',
						'secret_key'     => 'stored-sk',
						'app_secret_key' => 'stored-ask',
					),
				),
			);
		}

		/**
		 * @return array<string, mixed> Tab② 再保存提交：app_id 回显随表单重交，
		 * 三个 password 框不回显恒空提交（password_field() value="" 的真实表单形态）。
		 */
		private static function tab_captcha_resubmit(): array {
			return array(
				'gates'   => array(
					'comment' => array( 'enabled' => '1', 'render_mode' => 'always' ),
					'login'   => array( 'enabled' => '0', 'render_mode' => 'adaptive' ),
				),
				'captcha' => array(
					'driver'  => 'tencent',
					'tencent' => array(
						'app_id'         => '199999164',
						'secret_id'      => '',
						'secret_key'     => '',
						'app_secret_key' => '',
					),
				),
			);
		}

		public function test_resubmit_with_empty_secret_id_preserves_stored_value(): void {
			// P0-α 复现用例：已存四字段全值 → 密码框空串再提交 → secret_id 应保留。
			// 修复前（text 型）：'' 直接覆盖 → 断言失败（红）。
			$saved = self::save( self::stored_with_tencent_credentials(), self::tab_captcha_resubmit() );

			$this->assertSame( 'AKIDstoredSecretId000', $saved['captcha']['tencent']['secret_id'], 'secret_id 不得被空串覆盖（P0-α）' );
			$this->assertSame( 'stored-sk', $saved['captcha']['tencent']['secret_key'], 'secret_key 既有保留行为回归' );
			$this->assertSame( 'stored-ask', $saved['captcha']['tencent']['app_secret_key'], 'app_secret_key 既有保留行为回归' );
			$this->assertSame( 199999164, $saved['captcha']['tencent']['app_id'] );
			$this->assertSame( 'tencent', $saved['captcha']['driver'] );
		}

		public function test_three_save_sequence_secret_id_never_lost(): void {
			// 根因报告测试要点：首存（手填真值）→ 再存（留空）→ 三存（留空），恒非空。
			$first = self::save(
				array( 'schema_version' => 1 ),
				array(
					'captcha' => array(
						'driver'  => 'tencent',
						'tencent' => array(
							'app_id'         => '199999164',
							'secret_id'      => 'AKIDfirstSaveSecret',
							'secret_key'     => 'sk-first',
							'app_secret_key' => 'ask-first',
						),
					),
				)
			);
			$this->assertSame( 'AKIDfirstSaveSecret', $first['captcha']['tencent']['secret_id'] );

			$second = self::save( $first, self::tab_captcha_resubmit() );
			$this->assertSame( 'AKIDfirstSaveSecret', $second['captcha']['tencent']['secret_id'], '再存不丢（U2 #10 场景）' );

			$third = self::save( $second, self::tab_captcha_resubmit() );
			$this->assertSame( 'AKIDfirstSaveSecret', $third['captcha']['tencent']['secret_id'], '三存仍不丢' );
			$this->assertSame( 'sk-first', $third['captcha']['tencent']['secret_key'] );
		}

		public function test_cross_tab_save_keeps_tencent_credentials(): void {
			// 根因报告补充回归：存 Tab⑤（文案/邮箱）不清 Tab② 密钥。
			$saved = self::save(
				self::stored_with_tencent_credentials(),
				array(
					'brand' => array( 'contact_email' => 'webmaster@example.com' ),
					'copy'  => array(
						'messages' => array( 'too_short' => '再长一点点嘛' ),
						'titles'   => array( 'too_short' => '有点短' ),
					),
				)
			);

			$this->assertSame( 'webmaster@example.com', $saved['brand']['contact_email'] );
			$this->assertSame( 'AKIDstoredSecretId000', $saved['captcha']['tencent']['secret_id'] );
			$this->assertSame( 'stored-sk', $saved['captcha']['tencent']['secret_key'] );
			$this->assertSame( 'stored-ask', $saved['captcha']['tencent']['app_secret_key'] );
			$this->assertSame( 199999164, $saved['captcha']['tencent']['app_id'] );
			$this->assertSame( 'tencent', $saved['captcha']['driver'] );
		}

		public function test_secret_id_can_be_replaced_by_explicit_new_value(): void {
			// 保留 ≠ 只读：显式提交新值仍可换钥（换密钥工作流）。
			$input = self::tab_captcha_resubmit();
			$input['captcha']['tencent']['secret_id'] = 'AKIDrotatedNewSecret';

			$saved = self::save( self::stored_with_tencent_credentials(), $input );
			$this->assertSame( 'AKIDrotatedNewSecret', $saved['captcha']['tencent']['secret_id'] );
		}

		/* ---------- C4：Tab③ 云审核 chain 复选框保存语义（列表整体替换） ---------- */

		public function test_chain_check_roundtrip_and_tms_password_preserve(): void {
			// 首存：勾选 TMS 入链 + 凭据（password 框不回显恒空提交的是 tms 密钥——此处显式提交）。
			$stored = array( 'schema_version' => 1 );

			$checked = self::save( $stored, array(
				'review' => array(
					'local' => array( 'min_length' => '0', 'max_length' => '1800', 'require_chinese' => '1', 'custom_banned_words' => '', 'use_wp_keywords' => '1' ),
					'cloud' => array(
						'enabled'     => '1',
						'chain'       => array( '', 'tencent_tms' ), // hidden 伴随域 '' + checkbox。
						'tencent_tms' => array(
							'secret_id'    => 'AKIDtmsFirst',
							'secret_key'   => 'tms-sk',
							'biz_type'     => '200013',
							'reject_score' => '80',
							'timeout'      => '8',
							'daily_quota'  => '500',
							'fail_mode'    => 'review',
						),
					),
				),
			) );

			$this->assertSame( array( 'tencent_tms' ), $checked['review']['cloud']['chain'], '空串伴随域剔除后入链' );
			$this->assertTrue( $checked['review']['cloud']['enabled'] );
			$this->assertSame( 'AKIDtmsFirst', $checked['review']['cloud']['tencent_tms']['secret_id'] );

			// 再存（password 空提交 = 保留）：本地规则改动不丢 TMS 凭据。
			$resaved = self::save( $checked, array(
				'review' => array(
					'local' => array( 'min_length' => '5', 'max_length' => '1800', 'require_chinese' => '1', 'custom_banned_words' => '', 'use_wp_keywords' => '1' ),
					'cloud' => array(
						'enabled'     => '1',
						'chain'       => array( '', 'tencent_tms' ),
						'tencent_tms' => array( 'secret_id' => '', 'secret_key' => '', 'biz_type' => '200013', 'reject_score' => '80', 'timeout' => '8', 'daily_quota' => '500', 'fail_mode' => 'review' ),
					),
				),
			) );

			$this->assertSame( 'AKIDtmsFirst', $resaved['review']['cloud']['tencent_tms']['secret_id'], 'TMS password 空提交保留已存值' );
			$this->assertSame( 5, $resaved['review']['local']['min_length'] );

			// 取消勾选：hidden 伴随域单提交 [''] → sanitize 后空数组 → 链表整体替换为空（深合并回填 bug 封口）。
			$unchecked = self::save( $resaved, array(
				'review' => array(
					'local' => array( 'min_length' => '5', 'max_length' => '1800', 'require_chinese' => '1', 'custom_banned_words' => '', 'use_wp_keywords' => '1' ),
					'cloud' => array(
						'enabled'     => '1',
						'chain'       => array( '' ),
						'tencent_tms' => array( 'secret_id' => '', 'secret_key' => '', 'biz_type' => '200013', 'reject_score' => '80', 'timeout' => '8', 'daily_quota' => '500', 'fail_mode' => 'review' ),
					),
				),
			) );

			$this->assertSame( array(), $unchecked['review']['cloud']['chain'], '取消勾选后链表清空（不被旧链表回填）' );
			$this->assertSame( 'AKIDtmsFirst', $unchecked['review']['cloud']['tencent_tms']['secret_id'], '取消入链不清凭据（切换保留）' );
		}
	}
}
