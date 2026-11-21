<?php
/**
 * 休眠驱动键面行为测试（【S4-F7+A1-F8】幽灵驱动凭据面收口——AUDIT-INDEP-2026-11）。
 *
 * 审计实锤：hcaptcha/turnstile/geetest/moli 四驱动未实装（registry 无条目/无驱动类/
 * 无 UI 字段），但 schema+sanitize 规则在册——凭据可经 POST 提交通过 sanitize 并持久化
 * （密钥落库零消费=误导面 + 未来同 id 驱动实装时读到陈旧值）；url_audit.provider 三键
 * 无 .env.example 键名（部署脚本无法注入）。
 *
 * 修法（本批裁定——任务书候选①「sanitize 层增量拒收」）：WRITE 路径休眠键增量拒收
 * （clean 剥除入 rejected 报告——与未知键同通道；清除旗标面一并冻结）；READ 路径
 * （merge_defaults/渲染）不动——键仍在 schema 键集（schema↔rules 双向一致性钉不破）；
 * 存量值保留不删（merge_submitted stored-first——零数据损失红线）。实装批随驱动落地
 * 时从休眠清单摘除即恢复常态写入。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Settings\Sanitize;
use WinkGatekeeper\Settings\SettingsPage;

final class DormantDriverKeysTest extends TestCase {

	/**
	 * 休眠键面闭集（审计 S4-F7 §3.1 表+Sanitize rules 在册幽灵键——实装批摘除后本钉随批翻转）。
	 *
	 * @return string[]
	 */
	private static function dormant_paths(): array {
		return array(
			'captcha.hcaptcha.site_key',
			'captcha.hcaptcha.secret_key',
			'captcha.turnstile.site_key',
			'captcha.turnstile.secret_key',
			'captcha.geetest.captcha_id',
			'captcha.geetest.captcha_key',
			'review.cloud.moli.api_key',
			'review.cloud.moli.base_url',
			'review.cloud.moli.model',
			'review.cloud.moli.timeout',
			'review.cloud.moli.daily_quota',
			'review.cloud.moli.fail_mode',
		);
	}

	/**
	 * ① 休眠键增量拒收：clean 剥除 + rejected 报告（与未知键同通道——可观测）。
	 */
	public function test_clean_strips_dormant_submissions_and_reports_them(): void {
		$input = array(
			'captcha' => array(
				'driver'    => 'aliyun',
				'aliyun'    => array( 'access_key_id' => 'live-key', 'access_key_secret' => 'live-secret' ),
				'hcaptcha'  => array( 'site_key' => 'crafted', 'secret_key' => 'crafted-secret' ),
				'turnstile' => array( 'site_key' => 'crafted', 'secret_key' => 'crafted-secret' ),
				'geetest'   => array( 'captcha_id' => 'crafted', 'captcha_key' => 'crafted-secret' ),
			),
			'review'  => array(
				'cloud' => array(
					'enabled' => '1',
					'moli'    => array( 'api_key' => 'crafted-moli', 'base_url' => 'https://crafted.example.com' ),
				),
			),
		);

		list( $clean, $rejected ) = Sanitize::clean( $input );

		// 休眠键不进 clean 树（逐叶剥除——中间节点归空数组，merge 后无观察差）。
		$this->assertSame( array(), $clean['captcha']['hcaptcha'] ?? null, 'hcaptcha 提交剥除（空子树）' );
		$this->assertSame( array(), $clean['captcha']['turnstile'] ?? null, 'turnstile 提交剥除（空子树）' );
		$this->assertSame( array(), $clean['captcha']['geetest'] ?? null, 'geetest 提交剥除（空子树）' );
		$this->assertSame( array(), $clean['review']['cloud']['moli'] ?? null, 'moli 提交剥除（空子树）' );

		// rejected 报告收录全部「本次提交」的休眠路径（与未知键同通道）。
		$submitted_dormant = array(
			'captcha.hcaptcha.site_key',
			'captcha.hcaptcha.secret_key',
			'captcha.turnstile.site_key',
			'captcha.turnstile.secret_key',
			'captcha.geetest.captcha_id',
			'captcha.geetest.captcha_key',
			'review.cloud.moli.api_key',
			'review.cloud.moli.base_url',
		);
		foreach ( $submitted_dormant as $path ) {
			$this->assertContains( $path, $rejected, "休眠键 {$path} 进 rejected 报告（增量拒收可观测）" );
		}
		$this->assertCount( 8, array_intersect( self::dormant_paths(), $rejected ), 'rejected 中休眠路径计数=提交面闭集' );

		// 活键照常清洗（契约不破坏）。
		$this->assertSame( 'aliyun', $clean['captcha']['driver'] );
		$this->assertSame( 'live-key', $clean['captcha']['aliyun']['access_key_id'] );
		$this->assertSame( true, $clean['review']['cloud']['enabled'] );
	}

	/**
	 * ② 存量值零丢失：已存休眠凭据在再保存后原样保留（拒收=增量，不删存量）。
	 */
	public function test_bridge_preserves_stored_dormant_values_and_rejects_new_ones(): void {
		$stored = array(
			'schema_version' => 3,
			'captcha'        => array(
				'driver'   => 'aliyun',
				'hcaptcha' => array( 'site_key' => 'legacy-site', 'secret_key' => 'legacy-secret' ),
			),
			'review'         => array(
				'cloud' => array(
					'moli' => array( 'api_key' => 'legacy-moli-key' ),
				),
			),
		);
		// 伪造 POST：休眠键携新值 + 活键正常提交（幽灵凭据投毒形态——管理面可达）。
		$input = array(
			'captcha' => array(
				'driver'   => 'aliyun',
				'hcaptcha' => array( 'site_key' => 'crafted-new', 'secret_key' => 'crafted-new-secret' ),
			),
			'review'  => array(
				'cloud' => array(
					'moli' => array( 'api_key' => 'crafted-moli' ),
				),
			),
		);

		$GLOBALS['wk_bridge_option_store'][ SettingsPage::OPTION_NAME ] = $stored;
		$saved = SettingsPage::sanitize( $input );

		$this->assertSame( 'legacy-site', $saved['captcha']['hcaptcha']['site_key'], '存量休眠 site_key 原样保留（零数据损失）' );
		$this->assertSame( 'legacy-secret', $saved['captcha']['hcaptcha']['secret_key'], '存量休眠 secret_key 原样保留——伪造新值不得覆盖' );
		$this->assertSame( 'legacy-moli-key', $saved['review']['cloud']['moli']['api_key'], '存量休眠 moli key 原样保留' );
	}

	/**
	 * ③ 清除旗标面一并冻结：伪造休眠键 <leaf>_clear 旗标不得清掉存量休眠凭据。
	 */
	public function test_bridge_ignores_dormant_clear_flags(): void {
		$stored = array(
			'schema_version' => 3,
			'captcha'        => array(
				'driver'   => 'aliyun',
				'hcaptcha' => array( 'site_key' => 'legacy-site', 'secret_key' => 'legacy-secret' ),
			),
		);
		// 伪造 POST：休眠清除旗标（勾选=1 + 值空串）——活键旗标语义会置空存量，休眠键必须不收。
		$input = array(
			'captcha' => array(
				'driver'   => 'aliyun',
				'hcaptcha' => array(
					'site_key'       => '',
					'secret_key'     => '',
					'site_key_clear' => '1',
					'secret_key_clear' => '1',
				),
			),
		);

		$GLOBALS['wk_bridge_option_store'][ SettingsPage::OPTION_NAME ] = $stored;
		$saved = SettingsPage::sanitize( $input );

		$this->assertSame( 'legacy-site', $saved['captcha']['hcaptcha']['site_key'], '休眠清除旗标不消费——存量 site_key 保留' );
		$this->assertSame( 'legacy-secret', $saved['captcha']['hcaptcha']['secret_key'], '休眠清除旗标不消费——存量 secret_key 保留' );
	}

	/**
	 * ④ 休眠清单闭集钉（is_dormant 判定面——实装批摘除时本钉随批翻转）。
	 */
	public function test_is_dormant_closed_set(): void {
		foreach ( self::dormant_paths() as $path ) {
			$this->assertTrue( Sanitize::is_dormant( $path ), "休眠闭集：{$path} 判定为休眠" );
		}
		foreach ( array(
			'captcha.aliyun.access_key_id',        // 在役驱动。
			'captcha.tencent.secret_key',          // 在役驱动。
			'review.cloud.baidu.api_key',          // 在役驱动。
			'review.cloud.deepseek.api_key',       // 在役驱动。
			'review.cloud.openai_custom.api_key',  // 在役驱动。
			'review.cloud.chain',                  // 云审链活键（moli 字符串可入链=工厂位静默剔除）。
			'coexist.hcaptcha_yield_comment',      // 共存行为键（非休眠驱动凭据面）。
			'review.local.min_length',             // 本地四闸。
		) as $live_path ) {
			$this->assertFalse( Sanitize::is_dormant( $live_path ), "在役键 {$live_path} 不得误判休眠" );
		}
	}

	/**
	 * ⑤ 【S4-F7 后半】url_audit.provider 三键 .env.example 键名补齐（部署脚本注入面）。
	 */
	public function test_env_example_documents_provider_keys(): void {
		$env = (string) file_get_contents( dirname( __DIR__ ) . '/.env.example' );
		$this->assertStringContainsString( 'WINK_URL_AUDIT_PROVIDER_API_KEY=', $env, 'provider api_key 键名在册' );
		$this->assertStringContainsString( 'WINK_URL_AUDIT_PROVIDER_BASE_URL=', $env, 'provider base_url 键名在册' );
		$this->assertStringContainsString( 'WINK_URL_AUDIT_PROVIDER_MODEL=', $env, 'provider model 键名在册' );
	}
}
