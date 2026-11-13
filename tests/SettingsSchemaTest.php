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
use WinkGatekeeper\Settings\Sanitize;
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
			// 【v1.7·M3-IP 定稿 §8.1】P0-A 评论提交限流器（wk_cm_ per-IP 小时桶默认 30/h；0=不限流）。
			array( array( 'gates', 'comment', 'submit_rate_limit' ), 30 ),
		array( array( 'gates', 'login', 'enabled' ), true ),
		array( array( 'gates', 'login', 'render_mode' ), 'adaptive' ),
		// C3：XML-RPC 登录开关（03 Tab②「高级」折叠区）。
		// 【v1.7·M3-IP 定稿 §8.2·breaking】默认 true→false——新装站点默认禁用 XML-RPC 登录；
		// 存量设置不迁移（migrate 不动已存 true），升级站点不受影响。
		array( array( 'gates', 'login', 'xmlrpc_login' ), false ),
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
			// 【v1.7·M3-IP 定稿 §3.3】两 TTL 默认平移（60→1440 / 10→60）+ soft_quota 新键 3000。
			array( array( 'iprep', 'negative_cache_minutes' ), 60 ),
			array( array( 'iprep', 'abuseipdb', 'suspect_score' ), 60 ),
			array( array( 'iprep', 'abuseipdb', 'malicious_score' ), 85 ),
			array( array( 'iprep', 'abuseipdb', 'cache_minutes' ), 1440 ),
			array( array( 'iprep', 'abuseipdb', 'soft_quota' ), 3000 ),
			array( array( 'iprep', 'abuseipdb', 'daily_quota' ), 4750 ),
			array( array( 'iprep', 'abuseipdb', 'timeout' ), 3 ), // 【P0-9·F1】默认 1→3（M4UX 定稿 §2——P95 1.99s 数据支撑；多跳链路建议 5s 注记 Changelog）。
			// 【I3·定稿 §4.3】总开关默认 true→**false**（拍板 0.4：涉数据出境与隐私，默认从关开始
			// ——T1 触发面默认开但子系统关=完全静默；存量不迁移 P0-B 先例——存储值原样保留）。
			array( array( 'iprep', 'report', 'enabled' ), false ),
			array( array( 'iprep', 'report', 'triggers', 'captcha_failed' ), true ),
			array( array( 'iprep', 'report', 'triggers', 'review_reject' ), false ),
			// 【I3·O5-P1-3】ip_reject 键废弃清除（自反馈回路——migrate_fields 幂等清除）；
			// 【定稿 §4.2】T3/T4 两新键 + 各阈值子键（T3 沿用 10 不单设）。
			array( array( 'iprep', 'report', 'triggers', 'login_attempts' ), false ),
			array( array( 'iprep', 'report', 'triggers', 'local_rules_fail' ), false ),
			array( array( 'iprep', 'report', 'triggers', 'review_reject_threshold' ), 5 ),
			array( array( 'iprep', 'report', 'triggers', 'local_rules_fail_threshold' ), 5 ),
			array( array( 'iprep', 'report', 'cooldown_minutes' ), 60 ),
			array( array( 'iprep', 'report', 'hourly_limit' ), 60 ),
			array( array( 'iprep', 'report', 'daily_cap' ), 500 ),
			array( array( 'iprep', 'report', 'category' ), 0 ),
			array( array( 'iprep', 'report', 'category_captcha_failed' ), 0 ),
			array( array( 'iprep', 'report', 'category_review_reject' ), 0 ),
			array( array( 'iprep', 'report', 'category_login_attempts' ), 0 ),
			array( array( 'iprep', 'report', 'category_local_rules_fail' ), 0 ),
			array( array( 'iprep', 'report', 'comment_captcha_failed' ), '' ),
			array( array( 'iprep', 'report', 'comment_review_reject' ), '' ),
			array( array( 'iprep', 'report', 'comment_login_attempts' ), '' ),
			array( array( 'iprep', 'report', 'comment_local_rules_fail' ), '' ),

			array( array( 'stats', 'enabled' ), true ),
			array( array( 'stats', 'pass_sampling' ), false ),
			array( array( 'stats', 'capacity_notice' ), true ),
			// 【v1.4·R4 §D.4】raw 快照开关与截断字节。
			array( array( 'stats', 'raw_capture' ), true ),
			array( array( 'stats', 'raw_max_bytes' ), 512 ),
			array( array( 'brand', 'contact_email' ), '' ),
			array( array( 'coexist', 'hcaptcha_yield_comment' ), true ),
			array( array( 'coexist', 'hcaptcha_yield_login' ), true ),
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
	 * copy 子树从 Copywork 单一来源派生（03 Tab⑤）；【v1.5·B1】追加 die_page 自定义文案键
	 * （copy.die_page_title/die_page_message，默认空串=回落场景默认文案）。
	 */
	public function test_copy_subtree_is_derived_from_copywork(): void {
		$this->assertSame(
			array_merge( Copywork::defaults(), Copywork::custom_defaults() ),
			SettingsSchema::defaults()['copy']
		);
		$this->assertSame( '', SettingsSchema::defaults()['copy']['die_page_title'] );
		$this->assertSame( '', SettingsSchema::defaults()['copy']['die_page_message'] );
	}

	/**
	 * 【v1.5·B1】v1.5 迁移：存量 option copy 子树缺 die_page 键时由 merge_defaults 补默认。
	 */
	public function test_merge_defaults_backfills_die_page_keys_for_legacy_options(): void {
		$merged = SettingsSchema::merge_defaults( array(
			'copy' => array( 'messages' => array( 'banned_word' => '自定义文案' ) ),
		) );
		$this->assertSame( '', $merged['copy']['die_page_title'] );
		$this->assertSame( '', $merged['copy']['die_page_message'] );
		$this->assertSame( '自定义文案', $merged['copy']['messages']['banned_word'] );
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
		// C2 及此前版本的 option 无此键：升级后 merge_defaults 补当前默认。
		// 【v1.7·M3-IP 定稿 §8.2·breaking】默认已翻转为 false——新装/从未保存该键的站点
		// 默认禁用 XML-RPC 登录（readme/changelog 已注明）。
		$merged = SettingsSchema::merge_defaults( array(
			'gates' => array( 'login' => array( 'enabled' => true, 'render_mode' => 'adaptive' ) ),
		) );
		$this->assertFalse( $merged['gates']['login']['xmlrpc_login'] );
	}

	public function test_merge_defaults_respects_explicit_xmlrpc_login_false(): void {
		// 站长已一键关闭：显式 false 不被默认覆盖。
		$merged = SettingsSchema::merge_defaults( array(
			'gates' => array( 'login' => array( 'enabled' => true, 'render_mode' => 'adaptive', 'xmlrpc_login' => false ) ),
		) );
		$this->assertFalse( $merged['gates']['login']['xmlrpc_login'] );
	}

	public function test_migrate_preserves_stored_xmlrpc_login_true(): void {
		// 【v1.7·M3-IP 定稿 §8.2】存量不迁移：升级前已开启（显式存 true）的站点不受影响
		// ——migrate/merge_defaults 均不动已存 true（breaking 仅对默认值）。
		$merged = SettingsSchema::merge_defaults( array(
			'gates' => array( 'login' => array( 'enabled' => true, 'render_mode' => 'adaptive', 'xmlrpc_login' => true ) ),
		) );
		$this->assertTrue( $merged['gates']['login']['xmlrpc_login'] );
	}

	/* ---------- v1.7·M3-IP：gates.comment.submit_rate_limit（P0-A 提交限流新键） ---------- */

	public function test_merge_defaults_backfills_submit_rate_limit_for_old_options(): void {
		// 旧站点 option 无此键：升级合并补默认 30（wk_cm_ 桶默认 30 次/h/IP）。
		$merged = SettingsSchema::merge_defaults( array(
			'gates' => array( 'comment' => array( 'enabled' => true, 'render_mode' => 'always' ) ),
		) );
		$this->assertSame( 30, $merged['gates']['comment']['submit_rate_limit'] );
	}

	public function test_merge_defaults_respects_explicit_submit_rate_limit(): void {
		// 站长显式配置不被默认覆盖（含 0=不限流）。
		$merged = SettingsSchema::merge_defaults( array(
			'gates' => array( 'comment' => array( 'enabled' => true, 'render_mode' => 'always', 'submit_rate_limit' => 0 ) ),
		) );
		$this->assertSame( 0, $merged['gates']['comment']['submit_rate_limit'] );
	}

	/* ---------- v1.7·M3-IP：iprep 双 TTL 平移 + soft_quota 新键（定稿 §3.3/§3.1 步 4） ---------- */

	public function test_migrate_shifts_default_cache_minutes_60_to_1440(): void {
		// 旧默认特征值 60（站长从未动过）→ 平移新默认 1440（24h——WF V5 变体省配额）。
		$merged = SettingsSchema::merge_defaults( array(
			'iprep' => array( 'enabled' => true, 'abuseipdb' => array( 'cache_minutes' => 60 ) ),
		) );
		$this->assertSame( 1440, $merged['iprep']['abuseipdb']['cache_minutes'] );
	}

	public function test_migrate_shifts_default_negative_cache_minutes_10_to_60(): void {
		// 旧默认特征值 10（iprep 顶层实况键名——O5-P1-2）→ 平移新默认 60（1h）。
		$merged = SettingsSchema::merge_defaults( array(
			'iprep' => array( 'enabled' => true, 'negative_cache_minutes' => 10 ),
		) );
		$this->assertSame( 60, $merged['iprep']['negative_cache_minutes'] );
	}

	public function test_migrate_preserves_custom_ttl_values(): void {
		// 站长自定义值原样保留（v1.4 chain 特征值先例——只平移未动过的旧默认）。
		$merged = SettingsSchema::merge_defaults( array(
			'iprep' => array(
				'enabled' => true,
				'negative_cache_minutes' => 30,
				'abuseipdb' => array( 'cache_minutes' => 720 ),
			),
		) );
		$this->assertSame( 30, $merged['iprep']['negative_cache_minutes'], '自定义负缓存 30 不被平移' );
		$this->assertSame( 720, $merged['iprep']['abuseipdb']['cache_minutes'], '自定义 pass 缓存 720 不被平移' );
	}

	/* ---------- 【P0-9·F1 批】check 超时默认 1s→3s + 存量特征值平移（M4UX 定稿 §2） ---------- */

	public function test_migrate_shifts_default_timeout_1_to_3(): void {
		// 1s 是出厂默认非站长显式选择（M3-IP cache_minutes 平移同法）——存储特征值 1
		// → 平移新默认 3s（B 机实测 P95 1.99s：1s 窗外幽灵到达；多跳链路建议 5s 注记 Changelog）。
		$merged = SettingsSchema::merge_defaults( array(
			'iprep' => array( 'enabled' => true, 'abuseipdb' => array( 'timeout' => 1 ) ),
		) );
		$this->assertSame( 3, $merged['iprep']['abuseipdb']['timeout'] );
	}

	public function test_migrate_preserves_custom_timeout_values(): void {
		// 站长自定义值（1-5s 防呆范围）原样保留——只平移未动过的旧默认特征值 1。
		$merged = SettingsSchema::merge_defaults( array(
			'iprep' => array( 'enabled' => true, 'abuseipdb' => array( 'timeout' => 5 ) ),
		) );
		$this->assertSame( 5, $merged['iprep']['abuseipdb']['timeout'], '自定义 5s 不被平移' );
	}

	public function test_merge_defaults_backfills_soft_quota_for_old_options(): void {
		// soft_quota 新键（定稿 §3.1 步 4——软顶 3000 先行于 daily_quota 4750）由整树合并补齐。
		$merged = SettingsSchema::merge_defaults( array(
			'iprep' => array( 'enabled' => true, 'abuseipdb' => array( 'api_key' => 'k' ) ),
		) );
		$this->assertSame( 3000, $merged['iprep']['abuseipdb']['soft_quota'] );
		$this->assertSame( 1440, $merged['iprep']['abuseipdb']['cache_minutes'] );
		$this->assertSame( 60, $merged['iprep']['negative_cache_minutes'] );
		$this->assertSame( 4750, $merged['iprep']['abuseipdb']['daily_quota'], '软顶 3000 < 硬停转 4750——两道额度闸' );
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

	/* ---------- 【I3·定稿 §4】report 子树键集（T3/T4 两新键+阈值子键+daily_cap+类目/Comment 覆盖键） ---------- */

	public function test_merge_defaults_backfills_report_new_keys_for_old_options(): void {
		// 旧站点 option（v0.4.6 存量：triggers 三键+ip_reject）：升级合并补 T3/T4 两键+阈值子键
		// +daily_cap+每面 category_/comment_ 覆盖键（定稿 §4.2/§4.5——merge_defaults 整树补齐）。
		$merged = SettingsSchema::merge_defaults( array(
			'iprep' => array( 'report' => array(
				'enabled'  => false,
				'triggers' => array( 'captcha_failed' => true, 'review_reject' => false ),
			) ),
		) );
		$triggers = $merged['iprep']['report']['triggers'];
		$this->assertSame( false, $triggers['login_attempts'], 'T3 新键默认关' );
		$this->assertSame( false, $triggers['local_rules_fail'], 'T4 新键默认关' );
		$this->assertSame( 5, $triggers['review_reject_threshold'] );
		$this->assertSame( 5, $triggers['local_rules_fail_threshold'] );
		$this->assertSame( 500, $merged['iprep']['report']['daily_cap'], 'report 独立日顶 500（分桶不占 check 4750 池）' );
		foreach ( array( 'captcha_failed', 'review_reject', 'login_attempts', 'local_rules_fail' ) as $face ) {
			$this->assertSame( 0, $merged['iprep'][ 'report' ][ 'category_' . $face ], "category_{$face} 覆盖键补默认（0=用该面默认类目）" );
			$this->assertSame( '', $merged['iprep'][ 'report' ][ 'comment_' . $face ], "comment_{$face} 覆盖键补默认（空=默认短语）" );
		}
	}

	public function test_migrate_purges_deprecated_ip_reject_trigger(): void {
		// 【O5-P1-3】ip_reject 键废弃清除（check 判 reject 再报同一 IP=自反馈回路无信息增量）
		// ——migrate_fields 幂等清除，同 v1.4 清 fail_mode 先例。
		$legacy  = array( 'iprep' => array( 'report' => array( 'triggers' => array(
			'captcha_failed' => true, 'review_reject' => false, 'ip_reject' => true,
		) ) ) );
		$migrated = SettingsSchema::migrate( $legacy );
		$this->assertArrayNotHasKey( 'ip_reject', $migrated['iprep']['report']['triggers'], '废弃键清除' );
		$this->assertTrue( $migrated['iprep']['report']['triggers']['captcha_failed'], '既有键原样保留' );

		$twice = SettingsSchema::migrate( $migrated );
		$this->assertSame( $migrated, $twice, '幂等' );
	}

	public function test_migrate_preserves_stored_report_enabled_true(): void {
		// 总开关默认 true→false（拍板 0.4：出境与隐私默认从关开始）——**存量不迁移**
		// （P0-B xmlrpc_login 先例：读取路径 migrate 每请求执行且不回写存储，特征值翻转
		// 会把站长此后显式开启的 true 永久打回 false=开关失灵；只改默认值，breaking 注明）。
		$merged = SettingsSchema::merge_defaults( array(
			'iprep' => array( 'report' => array( 'enabled' => true ) ),
		) );
		$this->assertTrue( $merged['iprep']['report']['enabled'], '存量存储值 true 原样保留（migrate 不动）' );

		// 新装站点（无存储值）→ merge 补新默认 false。
		$fresh = SettingsSchema::merge_defaults( array(
			'iprep' => array( 'report' => array( 'triggers' => array( 'captcha_failed' => true ) ) ),
		) );
		$this->assertFalse( $fresh['iprep']['report']['enabled'], '新装站点默认关（出境与隐私默认从关开始）' );
	}

	public function test_sanitize_rules_cover_report_new_keys_and_drop_ip_reject(): void {
		$rules = Sanitize::rules();
		$this->assertSame( 'bool', $rules['iprep.report.triggers.login_attempts'] );
		$this->assertSame( 'bool', $rules['iprep.report.triggers.local_rules_fail'] );
		$this->assertSame( 'absint', $rules['iprep.report.triggers.review_reject_threshold'] );
		$this->assertSame( 'absint', $rules['iprep.report.triggers.local_rules_fail_threshold'] );
		$this->assertSame( 'absint', $rules['iprep.report.daily_cap'] );
		foreach ( array( 'captcha_failed', 'review_reject', 'login_attempts', 'local_rules_fail' ) as $face ) {
			$this->assertSame( 'absint', $rules[ 'iprep.report.category_' . $face ] );
			$this->assertSame( 'text', $rules[ 'iprep.report.comment_' . $face ] );
		}
		$this->assertArrayNotHasKey( 'iprep.report.triggers.ip_reject', $rules, '废弃键不再白名单' );
	}
}
