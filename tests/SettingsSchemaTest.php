<?php
/**
 * SettingsSchema 默认树单元测试（04 §2 全量）。
 *
 * 默认值唯一来源为 04-数据设计.md §2 的 jsonc schema；
 * merge_defaults 深度合并语义：已有配置不丢、缺失键补默认、schema_version 常量收口。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Settings\SettingsSchema;

final class SettingsSchemaTest extends TestCase {

	/**
	 * 默认值抽查 ≥15 项（对照 04 §2）。
	 */
	public function test_default_tree_spot_checks(): void {
		$d = SettingsSchema::defaults();

		$checks = array(
			array( array( 'schema_version' ), 2 ),

			array( array( 'gates', 'comment', 'enabled' ), true ),
			array( array( 'gates', 'comment', 'render_mode' ), 'always' ),
		array( array( 'gates', 'login', 'enabled' ), true ),
		array( array( 'gates', 'login', 'render_mode' ), 'adaptive' ),
		// C3：XML-RPC 登录开关（03 Tab②「高级」折叠区；true=允许 XML-RPC 登录，一键加固存 false）。
		array( array( 'gates', 'login', 'xmlrpc_login' ), true ),
		// 【v1.4·R3/R4-G】注册/找回密码两闸键（常显）。
		array( array( 'gates', 'register', 'enabled' ), true ),
		array( array( 'gates', 'register', 'render_mode' ), 'always' ),
		array( array( 'gates', 'lostpassword', 'enabled' ), true ),

			array( array( 'captcha', 'driver' ), 'tencent' ),
			array( array( 'captcha', 'tencent', 'app_id' ), '' ),
			array( array( 'captcha', 'tencent', 'secret_key' ), '' ),
			array( array( 'captcha', 'tencent', 'app_secret_key' ), '' ),
			array( array( 'captcha', 'aliyun', 'scene_id' ), '' ),
			// M2 反馈2 T3：验证短缓存窗口（分钟，0=关）。
			array( array( 'captcha', 'verify_cache_minutes' ), 10 ),
			// 【v1.4·R4-G】threshold→user_threshold 迁移；max_user_keys 不再出现（04 §2 v1.4）。
			array( array( 'captcha', 'login_adaptive', 'user_threshold' ), 3 ),
			array( array( 'captcha', 'login_adaptive', 'window_minutes' ), 15 ),
			array( array( 'captcha', 'login_adaptive', 'ip_threshold' ), 10 ),
			array( array( 'captcha', 'login_adaptive', 'iprep_gate' ), true ),

			array( array( 'review', 'local', 'min_length' ), 0 ),
			array( array( 'review', 'local', 'max_length' ), 1800 ),
			array( array( 'review', 'local', 'require_chinese' ), 1 ),
			array( array( 'review', 'local', 'custom_banned_words' ), '' ),
			array( array( 'review', 'local', 'use_wp_keywords' ), 1 ),

			array( array( 'review', 'cloud', 'enabled' ), false ),
			// 【v1.4·R4】默认空数组（原 ['deepseek'] 作废——未实装驱动不入默认链）。
			array( array( 'review', 'cloud', 'chain' ), array() ),
			array( array( 'review', 'cloud', 'deepseek', 'base_url' ), 'https://api.deepseek.com' ),
			// 【04 §2 v1.2(L0) 注】deepseek-chat 已是旧别名（服务端映射 flash），默认 flash。
			array( array( 'review', 'cloud', 'deepseek', 'model' ), 'deepseek-flash' ),
			array( array( 'review', 'cloud', 'deepseek', 'timeout' ), 8 ),
			array( array( 'review', 'cloud', 'deepseek', 'daily_quota' ), 200 ),
			array( array( 'review', 'cloud', 'deepseek', 'fail_mode' ), 'review' ),
			array( array( 'review', 'cloud', 'deepseek', 'min_confidence' ), 0.8 ),
			array( array( 'review', 'cloud', 'openai_custom', 'base_url' ), '' ),
			array( array( 'review', 'cloud', 'moli', 'base_url' ), 'https://api.moark.com' ),
			array( array( 'review', 'cloud', 'moli', 'model' ), 'NeoHorse-Jev-4B' ),
			array( array( 'review', 'cloud', 'baidu', 'daily_quota' ), 500 ),
			array( array( 'review', 'cloud', 'tencent_tms', 'secret_id' ), '' ),

			array( array( 'network', 'ip_source' ), 'direct' ),
			array( array( 'network', 'trusted_proxies', 'cloudflare' ), false ),
			array( array( 'network', 'trusted_proxies', 'edgeone' ), false ),
			array( array( 'network', 'trusted_proxies', 'custom' ), '' ),
			array( array( 'network', 'custom_header' ), '' ),

			array( array( 'iprep', 'enabled' ), false ),
			array( array( 'iprep', 'driver' ), 'abuseipdb' ),
			// 【v1.4·R3】负缓存；【澄清A】驱动级超时键；fail_mode 作废删除。
			array( array( 'iprep', 'negative_cache_minutes' ), 10 ),
			array( array( 'iprep', 'abuseipdb', 'suspect_score' ), 60 ),
			array( array( 'iprep', 'abuseipdb', 'malicious_score' ), 85 ),
			array( array( 'iprep', 'abuseipdb', 'cache_minutes' ), 60 ),
			array( array( 'iprep', 'abuseipdb', 'daily_quota' ), 4750 ),
			array( array( 'iprep', 'abuseipdb', 'timeout' ), 1 ),
			array( array( 'iprep', 'report', 'enabled' ), true ),
			array( array( 'iprep', 'report', 'triggers', 'captcha_failed' ), true ),
			array( array( 'iprep', 'report', 'triggers', 'review_reject' ), false ),
			array( array( 'iprep', 'report', 'triggers', 'ip_reject' ), false ),
			array( array( 'iprep', 'report', 'cooldown_minutes' ), 60 ),
			array( array( 'iprep', 'report', 'hourly_limit' ), 60 ),
			array( array( 'iprep', 'report', 'category' ), 0 ),

			array( array( 'stats', 'enabled' ), true ),
			array( array( 'stats', 'pass_sampling' ), false ),
			array( array( 'stats', 'capacity_notice' ), true ),
			// 【v1.4·R4 §D.4】raw 快照开关与截断字节。
			array( array( 'stats', 'raw_capture' ), true ),
			array( array( 'stats', 'raw_max_bytes' ), 512 ),
			array( array( 'brand', 'contact_email' ), '' ),
			array( array( 'coexist', 'hcaptcha_yield_comment' ), true ),
			array( array( 'coexist', 'hcaptcha_yield_login' ), true ),
			array( array( 'cleanup_on_uninstall' ), false ),
		);

		foreach ( $checks as $case ) {
			list( $path, $expected ) = $case;
			$actual = $d;
			foreach ( $path as $key ) {
				$this->assertIsArray( $actual, '路径中断：' . implode( '.', $path ) );
				$this->assertArrayHasKey( $key, $actual, '缺键：' . implode( '.', $path ) );
				$actual = $actual[ $key ];
			}
			$this->assertSame(
				$expected,
				$actual,
				'默认值不符：' . implode( '.', $path )
			);
		}
	}

	public function test_schema_version_constant_is_two(): void {
		// 【v1.4·R4-G】v2 = 04 §2 v1.4 迁移段（threshold 平移/max_user_keys 与 fail_mode 丢弃/chain 默认空/新键整树）。
		$this->assertSame( 2, SettingsSchema::SCHEMA_VERSION );
	}

	public function test_top_level_keys_match_design(): void {
		$this->assertSame(
			array(
				'schema_version',
				'gates',
				'captcha',
				'review',
				'intercept', // v1.3（04 §2 用户建议4）：拦截留档开关子树（C2/F37）。
				'network',
				'iprep',
				'copy',
				'stats',
				'brand',
				'coexist',
				'cleanup_on_uninstall',
			),
			array_keys( SettingsSchema::defaults() )
		);
	}

	/** v1.3 新增默认键（F36/F37）：送审拼接开、留档开关开；旧树缺键补默认。 */
	public function test_v13_concat_and_archive_defaults(): void {
		$defaults = SettingsSchema::defaults();
		$this->assertTrue( $defaults['review']['include_author_fields'] );
		$this->assertTrue( $defaults['intercept']['archive_spam'] );
		// 旧站点形态：缺键经 merge_defaults 补默认（升级补新默认键语义）。
		$merged = SettingsSchema::merge_defaults( array(
			'review'    => array( 'local' => array( 'min_length' => 5 ) ),
			'intercept' => array(),
		) );
		$this->assertTrue( $merged['review']['include_author_fields'] );
		$this->assertTrue( $merged['intercept']['archive_spam'] );
	}

	/**
	 * copy 子树从 Copywork 单一来源派生（03 Tab⑤）。
	 */
	public function test_copy_subtree_is_derived_from_copywork(): void {
		$this->assertSame( Copywork::defaults(), SettingsSchema::defaults()['copy'] );
	}

	public function test_deepseek_system_prompt_default_is_complete_prompt(): void {
		$prompt = SettingsSchema::defaults()['review']['cloud']['deepseek']['system_prompt'];
		$this->assertIsString( $prompt );
		$this->assertStringContainsString( '审核员', $prompt );
		$this->assertStringContainsString( 'JSON', $prompt );
	}

	public function test_defaults_returns_independent_tree_each_call(): void {
		$d                 = SettingsSchema::defaults();
		$d['gates']['comment']['enabled'] = false;
		$this->assertTrue( SettingsSchema::defaults()['gates']['comment']['enabled'] );
	}

	public function test_merge_defaults_empty_yields_full_defaults(): void {
		$this->assertSame( SettingsSchema::defaults(), SettingsSchema::merge_defaults( array() ) );
	}

	public function test_merge_defaults_keeps_existing_values(): void {
		$merged = SettingsSchema::merge_defaults( array(
			'captcha' => array( 'driver' => 'hcaptcha' ),
		) );
		$this->assertSame( 'hcaptcha', $merged['captcha']['driver'] );
		// 未覆盖的兄弟键补默认。
		$this->assertSame( '', $merged['captcha']['tencent']['app_id'] );
		$this->assertSame( 3, $merged['captcha']['login_adaptive']['user_threshold'] );
	}

	public function test_merge_defaults_deep_nested_override_keeps_siblings(): void {
		$merged = SettingsSchema::merge_defaults( array(
			'review' => array( 'cloud' => array( 'deepseek' => array( 'timeout' => 30 ) ) ),
		) );
		$this->assertSame( 30, $merged['review']['cloud']['deepseek']['timeout'] );
		$this->assertSame( 'deepseek-flash', $merged['review']['cloud']['deepseek']['model'] );
		// 【v1.4·R4】chain 默认空数组（未提交时）。
		$this->assertSame( array(), $merged['review']['cloud']['chain'] );
	}

	public function test_merge_defaults_restores_missing_subtrees(): void {
		// 模拟旧版本 option 缺 stats 子树：合并后补齐完整默认。
		$merged = SettingsSchema::merge_defaults( array( 'brand' => array() ) );
		$this->assertSame( '', $merged['brand']['contact_email'] );
		$this->assertTrue( $merged['stats']['enabled'] );
		$this->assertTrue( $merged['stats']['capacity_notice'] );
	}

	public function test_merge_defaults_replaces_lists_whole(): void {
		// 列表（如 chain）整体替换，不做逐元素合并。
		$merged = SettingsSchema::merge_defaults( array(
			'review' => array( 'cloud' => array( 'chain' => array( 'deepseek', 'moli' ) ) ),
		) );
		$this->assertSame( array( 'deepseek', 'moli' ), $merged['review']['cloud']['chain'] );
	}

	public function test_merge_defaults_merges_copy_map_recursively(): void {
		// 用户只改了一条文案：其余文案键保默认（copy.messages 是 map，深度合并）。
		$merged = SettingsSchema::merge_defaults( array(
			'copy' => array( 'messages' => array( 'banned_word' => '自定义文案' ) ),
		) );
		$this->assertSame( '自定义文案', $merged['copy']['messages']['banned_word'] );
		$this->assertSame(
			Copywork::defaults()['messages']['too_short'],
			$merged['copy']['messages']['too_short']
		);
	}

	public function test_merge_defaults_forces_schema_version(): void {
		// schema_version 非用户可改字段：无论传入什么，收口为当前常量。
		$merged = SettingsSchema::merge_defaults( array( 'schema_version' => 99 ) );
		$this->assertSame( SettingsSchema::SCHEMA_VERSION, $merged['schema_version'] );
	}

	public function test_merge_defaults_preserves_unknown_existing_keys(): void {
		// 深度合并只做加法；已有 option 中的多余键原样保留（剥除职责在 Sanitize）。
		$merged = SettingsSchema::merge_defaults( array( 'legacy_junk' => 'keep-me' ) );
		$this->assertSame( 'keep-me', $merged['legacy_junk'] );
	}

	/* ---------- C3：gates.login.xmlrpc_login（XML-RPC 登录一键加固开关） ---------- */

	public function test_merge_defaults_backfills_xmlrpc_login_for_old_options(): void {
		// C2 及此前版本的 option 无此键：升级后 merge_defaults 补默认 true（不改变站点行为）。
		$merged = SettingsSchema::merge_defaults( array(
			'gates' => array( 'login' => array( 'enabled' => true, 'render_mode' => 'adaptive' ) ),
		) );
		$this->assertTrue( $merged['gates']['login']['xmlrpc_login'] );
	}

	public function test_merge_defaults_respects_explicit_xmlrpc_login_false(): void {
		// 站长已一键关闭：显式 false 不被默认覆盖。
		$merged = SettingsSchema::merge_defaults( array(
			'gates' => array( 'login' => array( 'enabled' => true, 'render_mode' => 'adaptive', 'xmlrpc_login' => false ) ),
		) );
		$this->assertFalse( $merged['gates']['login']['xmlrpc_login'] );
	}

	/* ---------- C4：review.cloud.tencent_tms 新键（biz_type / reject_score） ---------- */

	public function test_tms_defaults_biz_type_empty_and_reject_score_80(): void {
		// biz_type：TMS 控制台策略编号（L0 冒烟 .env WINK_TENCENT_TMS_bizType）；
		// reject_score：Block 直拒的 Score 阈值，默认 80——依据 L0 实测 Block=Ad,99（高置信远超 80）
		// 与误杀防线（灰区 Block 低分降 review）折中取整。
		$defaults = SettingsSchema::defaults();
		$this->assertSame( '', $defaults['review']['cloud']['tencent_tms']['biz_type'] );
		$this->assertSame( 80, $defaults['review']['cloud']['tencent_tms']['reject_score'] );
		$this->assertSame( 500, $defaults['review']['cloud']['tencent_tms']['daily_quota'] );
		$this->assertSame( 'review', $defaults['review']['cloud']['tencent_tms']['fail_mode'] );
	}

	public function test_merge_defaults_backfills_tms_new_keys_for_old_options(): void {
		// 旧站点 option（C3 及此前版本无此两键）：升级合并补默认，不改变站点行为。
		$merged = SettingsSchema::merge_defaults( array(
			'review' => array( 'cloud' => array( 'tencent_tms' => array( 'secret_id' => 'AKIDx', 'secret_key' => 'sk' ) ) ),
		) );
		$this->assertSame( '', $merged['review']['cloud']['tencent_tms']['biz_type'] );
		$this->assertSame( 80, $merged['review']['cloud']['tencent_tms']['reject_score'] );
		$this->assertSame( 'AKIDx', $merged['review']['cloud']['tencent_tms']['secret_id'] );
	}

	public function test_merge_defaults_respects_explicit_tms_values(): void {
		$merged = SettingsSchema::merge_defaults( array(
			'review' => array( 'cloud' => array( 'tencent_tms' => array( 'biz_type' => '200013', 'reject_score' => 95 ) ) ),
		) );
		$this->assertSame( '200013', $merged['review']['cloud']['tencent_tms']['biz_type'] );
		$this->assertSame( 95, $merged['review']['cloud']['tencent_tms']['reject_score'] );
	}
}
