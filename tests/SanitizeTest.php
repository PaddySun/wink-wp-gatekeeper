<?php
/**
 * Sanitize 清洗单元测试（04 §2 安全规则、03 Tab⑤ 文案键白名单联动）。
 *
 * 规格：按 schema 类型分派清洗（absint/int/text/password/textarea/bool/float/url/email/text_list），
 * 文案键白名单从 Copywork::keys() 派生；未知键剥除并出现在 rejected 报告；
 * 返回 [clean, rejected]。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Settings\Sanitize;
use WinkGatekeeper\Settings\SettingsSchema;

final class SanitizeTest extends TestCase {

	/* ---------- 标量类型分派 ---------- */

	public function test_bool_accepts_truthy_and_falsy_strings(): void {
		$in = array(
			'gates' => array(
				'comment' => array( 'enabled' => '1' ),
				'login'   => array( 'enabled' => '0' ),
			),
			'stats' => array( 'enabled' => true ),
		);
		list( $clean, $rejected ) = Sanitize::clean( $in );
		$this->assertTrue( $clean['gates']['comment']['enabled'] );
		$this->assertFalse( $clean['gates']['login']['enabled'] );
		$this->assertTrue( $clean['stats']['enabled'] );
		$this->assertSame( array(), $rejected );
	}

	public function test_bool_garbage_string_becomes_false(): void {
		list( $clean, ) = Sanitize::clean( array( 'stats' => array( 'enabled' => 'yes please' ) ) );
		$this->assertFalse( $clean['stats']['enabled'] );
	}

	public function test_absint_strips_sign_and_fraction(): void {
		// 【v1.4·R4-G】threshold 已迁移为 user_threshold（04 §2 v1.4 迁移段）。
		$in = array( 'review' => array( 'local' => array( 'max_length' => '-7' ) ), 'captcha' => array( 'login_adaptive' => array( 'user_threshold' => '3.9' ) ) );
		list( $clean, ) = Sanitize::clean( $in );
		$this->assertSame( 7, $clean['review']['local']['max_length'] );
		$this->assertSame( 3, $clean['captcha']['login_adaptive']['user_threshold'] );
	}

	public function test_absint_non_numeric_becomes_zero(): void {
		list( $clean, ) = Sanitize::clean( array( 'review' => array( 'local' => array( 'max_length' => 'abc' ) ) ) );
		$this->assertSame( 0, $clean['review']['local']['max_length'] );
	}

	public function test_int_keeps_negative(): void {
		// schema 现无负值键；int 类型语义为有符号整数（回归保障）。
		$this->assertSame( -5, Sanitize::clean_value( '-5', 'int' ) );
	}

	public function test_text_strips_tags_and_collapses_whitespace(): void {
		$this->assertSame( 'hi x', Sanitize::clean_value( "  <b>hi</b>\n  x  ", 'text' ) );
	}

	public function test_password_is_trimmed_and_tag_stripped(): void {
		$this->assertSame( 'sk-abc123', Sanitize::clean_value( ' <i>sk-abc123</i> ', 'password' ) );
	}

	public function test_textarea_strips_tags_but_keeps_newlines(): void {
		$this->assertSame( "第一行\n第二行", Sanitize::clean_value( "第一行\n<p>第二行</p>", 'textarea' ) );
	}

	public function test_float_casts_numeric_string(): void {
		$this->assertSame( 0.75, Sanitize::clean_value( '0.75', 'float' ) );
	}

	public function test_email_valid_kept_invalid_emptied(): void {
		$this->assertSame( 'admin@example.com', Sanitize::clean_value( ' admin@example.com ', 'email' ) );
		$this->assertSame( '', Sanitize::clean_value( 'not-an-email', 'email' ) );
	}

	public function test_url_trims_and_strips_tags(): void {
		$this->assertSame( 'https://api.deepseek.com', Sanitize::clean_value( ' <a>https://api.deepseek.com</a> ', 'url' ) );
	}

	public function test_null_and_array_input_for_scalar_rule_fall_back_to_default(): void {
		$this->assertSame( '', Sanitize::clean_value( null, 'text' ) );
		$this->assertSame( 0, Sanitize::clean_value( array( 'x' ), 'absint' ) );
		$this->assertFalse( Sanitize::clean_value( null, 'bool' ) );
	}

	/* ---------- 列表与嵌套子树 ---------- */

	public function test_text_list_sanitizes_each_entry(): void {
		list( $clean, ) = Sanitize::clean( array(
			'review' => array( 'cloud' => array( 'chain' => array( ' deepseek ', '<b>moli</b>' ) ) ),
		) );
		$this->assertSame( array( 'deepseek', 'moli' ), $clean['review']['cloud']['chain'] );
	}

	public function test_text_list_non_array_becomes_empty_list(): void {
		list( $clean, ) = Sanitize::clean( array(
			'review' => array( 'cloud' => array( 'chain' => 'deepseek' ) ),
		) );
		$this->assertSame( array(), $clean['review']['cloud']['chain'] );
	}

	public function test_text_list_drops_empty_entries(): void {
		// C4：chain 复选框的 hidden 伴随域形态（未勾选提交 ['']、勾选提交 ['','tencent_tms']）——
		// 空串项剔除，链表只留有效驱动 id。
		list( $clean, ) = Sanitize::clean( array(
			'review' => array( 'cloud' => array( 'chain' => array( '', 'tencent_tms', ' ' ) ) ),
		) );
		$this->assertSame( array( 'tencent_tms' ), $clean['review']['cloud']['chain'] );
	}

	/* ---------- C4：review.cloud.tencent_tms 子树（biz_type / reject_score 新键） ---------- */

	public function test_full_tencent_tms_subtree_is_sanitized(): void {
		$in = array(
			'review' => array(
				'cloud' => array(
					'tencent_tms' => array(
						'secret_id'    => " AKID-1\r\n",
						'secret_key'   => '<b>sk-1</b>',
						'biz_type'     => " 200013 \r\n",
						'timeout'      => '6',
						'daily_quota'  => '500',
						'fail_mode'    => 'review',
						'reject_score' => '90',
					),
				),
			),
		);
		list( $clean, $rejected ) = Sanitize::clean( $in );

		$tms = $clean['review']['cloud']['tencent_tms'];
		$this->assertSame( 'AKID-1', $tms['secret_id'], 'password 型 trim 收口（env 通道 CRLF 残留防御，C1 同款）' );
		$this->assertSame( 'sk-1', $tms['secret_key'] );
		$this->assertSame( '200013', $tms['biz_type'] );
		$this->assertSame( 6, $tms['timeout'] );
		$this->assertSame( 500, $tms['daily_quota'] );
		$this->assertSame( 'review', $tms['fail_mode'] );
		$this->assertSame( 90, $tms['reject_score'] );
		$this->assertSame( array(), $rejected );
	}

	/* ---------- v1.7·M3-IP：gates.comment.submit_rate_limit（P0-A 提交限流，absint） ---------- */

	public function test_gates_comment_submit_rate_limit_absint_roundtrip(): void {
		list( $clean, $rejected ) = Sanitize::clean( array(
			'gates' => array( 'comment' => array( 'submit_rate_limit' => ' 30 ' ) ),
		) );
		$this->assertSame( 30, $clean['gates']['comment']['submit_rate_limit'] );
		$this->assertSame( array(), $rejected );

		// 0 = 不限流（显式关断）。
		list( $clean0, ) = Sanitize::clean( array(
			'gates' => array( 'comment' => array( 'submit_rate_limit' => '0' ) ),
		) );
		$this->assertSame( 0, $clean0['gates']['comment']['submit_rate_limit'] );

		// 非标量归类型默认（absint → 0）。
		list( $cleanN, ) = Sanitize::clean( array(
			'gates' => array( 'comment' => array( 'submit_rate_limit' => array( 'x' ) ) ),
		) );
		$this->assertSame( 0, $cleanN['gates']['comment']['submit_rate_limit'] );
	}

	/* ---------- v1.7·M3-IP：iprep.abuseipdb.soft_quota（软顶，absint——定稿 §3.1 步 4） ---------- */

	public function test_iprep_abuseipdb_soft_quota_absint_roundtrip(): void {
		list( $clean, $rejected ) = Sanitize::clean( array(
			'iprep' => array( 'abuseipdb' => array( 'soft_quota' => ' 3000 ' ) ),
		) );
		$this->assertSame( 3000, $clean['iprep']['abuseipdb']['soft_quota'] );
		$this->assertSame( array(), $rejected );

		// 负缓存 TTL 平移后的新默认值形态同验（iprep 顶层 absint 既有规则回归）。
		list( $clean2, ) = Sanitize::clean( array(
			'iprep' => array( 'negative_cache_minutes' => '60', 'abuseipdb' => array( 'cache_minutes' => '1440' ) ),
		) );
		$this->assertSame( 60, $clean2['iprep']['negative_cache_minutes'] );
		$this->assertSame( 1440, $clean2['iprep']['abuseipdb']['cache_minutes'] );
	}

	/* ---------- C3：gates.login.xmlrpc_login（一键加固开关，反选形态 0/1） ---------- */

	public function test_gates_login_xmlrpc_login_bool_roundtrip(): void {
		// 开关形态：hidden value=1 + 反选 checkbox value=0（勾选=关闭 XML-RPC 登录）。
		list( $off, $off_rejected ) = Sanitize::clean( array(
			'gates' => array( 'login' => array( 'xmlrpc_login' => '0' ) ),
		) );
		$this->assertFalse( $off['gates']['login']['xmlrpc_login'] );
		$this->assertSame( array(), $off_rejected );

		list( $on, ) = Sanitize::clean( array(
			'gates' => array( 'login' => array( 'xmlrpc_login' => '1' ) ),
		) );
		$this->assertTrue( $on['gates']['login']['xmlrpc_login'] );
	}

	public function test_full_captcha_subtree_is_sanitized(): void {
		// 【v1.4·R4-G】threshold→user_threshold、max_user_keys 旧键剥除上报（04 §2 v1.4 迁移段）。
		$in = array(
			'captcha' => array(
				'driver'         => ' <i>tencent</i> ',
				'tencent'        => array(
					'app_id'     => '42abc',
					'secret_key' => ' sec ',
					'extra'      => 'junk',
				),
				'login_adaptive' => array(
					'user_threshold' => '5',
					'window_minutes' => '15',
					'ip_threshold'   => '10',
					'iprep_gate'     => '1',
					'threshold'      => '9',
					'max_user_keys'  => '20',
				),
			),
		);
		list( $clean, $rejected ) = Sanitize::clean( $in );

		$this->assertSame( 'tencent', $clean['captcha']['driver'] );
		$this->assertSame( 42, $clean['captcha']['tencent']['app_id'] );
		$this->assertSame( 'sec', $clean['captcha']['tencent']['secret_key'] );
		$this->assertSame( 5, $clean['captcha']['login_adaptive']['user_threshold'] );
		$this->assertSame( 10, $clean['captcha']['login_adaptive']['ip_threshold'] );
		$this->assertTrue( $clean['captcha']['login_adaptive']['iprep_gate'] );
		// v1.4 弃用键与未知嵌套键同样剥除并上报。
		$this->assertNotContains( 'threshold', $clean['captcha']['login_adaptive'] );
		$this->assertNotContains( 'max_user_keys', $clean['captcha']['login_adaptive'] );
		$this->assertNotContains( 'extra', $clean['captcha']['tencent'] );
		$this->assertContains( 'captcha.tencent.extra', $rejected );
		$this->assertContains( 'captcha.login_adaptive.threshold', $rejected );
		$this->assertContains( 'captcha.login_adaptive.max_user_keys', $rejected );
	}

	/**
	 * M2 反馈2 T3：验证短缓存窗口（captcha.verify_cache_minutes）absint 双向一致。
	 */
	public function test_verify_cache_minutes_sanitized_as_absint(): void {
		list( $clean, ) = Sanitize::clean( array( 'captcha' => array( 'verify_cache_minutes' => '14' ) ) );
		$this->assertSame( 14, $clean['captcha']['verify_cache_minutes'] );

		list( $clean2, ) = Sanitize::clean( array( 'captcha' => array( 'verify_cache_minutes' => '-3' ) ) );
		$this->assertSame( 3, $clean2['captcha']['verify_cache_minutes'], '负值取绝对值（absint）' );

		list( $clean3, ) = Sanitize::clean( array( 'captcha' => array( 'verify_cache_minutes' => '0' ) ) );
		$this->assertSame( 0, $clean3['captcha']['verify_cache_minutes'], '0 = 关闭短缓存' );

		$this->assertSame( 'absint', Sanitize::rules()['captcha.verify_cache_minutes'] );
	}

	public function test_full_review_cloud_subtree_is_sanitized(): void {
		$in = array(
			'review' => array(
				'cloud' => array(
					'enabled'  => '1',
					'chain'    => array( 'deepseek', 'moli' ),
					'deepseek' => array(
						'api_key'   => ' sk-1 ',
						'base_url'  => ' https://api.deepseek.com ',
						'model'     => 'deepseek-chat',
						'timeout'   => '8',
						'fail_mode' => 'review',
					),
					'baidu'    => array(
						'secret_key' => '<x>bk</x>',
						'daily_quota' => '500',
					),
				),
			),
		);
		list( $clean, $rejected ) = Sanitize::clean( $in );

		$this->assertTrue( $clean['review']['cloud']['enabled'] );
		$this->assertSame( array( 'deepseek', 'moli' ), $clean['review']['cloud']['chain'] );
		$this->assertSame( 'sk-1', $clean['review']['cloud']['deepseek']['api_key'] );
		$this->assertSame( 'https://api.deepseek.com', $clean['review']['cloud']['deepseek']['base_url'] );
		$this->assertSame( 8, $clean['review']['cloud']['deepseek']['timeout'] );
		$this->assertSame( 'bk', $clean['review']['cloud']['baidu']['secret_key'] );
		$this->assertSame( 500, $clean['review']['cloud']['baidu']['daily_quota'] );
		$this->assertSame( array(), $rejected );
	}

	/* ---------- 未知键剥除与 rejected 报告 ---------- */

	public function test_unknown_top_level_key_is_stripped_and_reported(): void {
		list( $clean, $rejected ) = Sanitize::clean( array(
			'hax' => '<script>',
			'stats' => array( 'enabled' => '1' ),
		) );
		$this->assertArrayNotHasKey( 'hax', $clean );
		$this->assertTrue( $clean['stats']['enabled'] );
		$this->assertSame( array( 'hax' ), $rejected );
	}

	public function test_unknown_nested_keys_are_stripped_with_dotted_paths(): void {
		list( $clean, $rejected ) = Sanitize::clean( array(
			'network' => array(
				'ip_source' => 'direct',
				'evil'      => 'x',
				'trusted_proxies' => array( 'cloudflare' => '1', 'evil2' => 'y' ),
			),
		) );
		$this->assertSame( 'direct', $clean['network']['ip_source'] );
		$this->assertTrue( $clean['network']['trusted_proxies']['cloudflare'] );
		$this->assertArrayNotHasKey( 'evil', $clean['network'] );
		$this->assertArrayNotHasKey( 'evil2', $clean['network']['trusted_proxies'] );
		$this->assertSame( array( 'network.evil', 'network.trusted_proxies.evil2' ), $rejected );
	}

	public function test_non_array_input_yields_empty_clean_and_no_reports(): void {
		list( $clean, $rejected ) = Sanitize::clean( 'garbage' );
		$this->assertSame( array(), $clean );
		$this->assertSame( array(), $rejected );
	}

	public function test_empty_array_input_is_noop(): void {
		list( $clean, $rejected ) = Sanitize::clean( array() );
		$this->assertSame( array(), $clean );
		$this->assertSame( array(), $rejected );
	}

	/* ---------- 文案键白名单（Copywork 单一来源联动） ---------- */

	public function test_copy_messages_whitelisted_key_is_kept_and_sanitized(): void {
		list( $clean, $rejected ) = Sanitize::clean( array(
			'copy' => array( 'messages' => array( 'banned_word' => " <b>自定义</b>\n文案 " ) ),
		) );
		$this->assertSame( "自定义\n文案", $clean['copy']['messages']['banned_word'] );
		$this->assertSame( array(), $rejected );
	}

	public function test_copy_titles_whitelisted_key_is_kept_as_text(): void {
		list( $clean, ) = Sanitize::clean( array(
			'copy' => array( 'titles' => array( 'captcha_failed' => ' 眨眼没对上 ' ) ),
		) );
		$this->assertSame( '眨眼没对上', $clean['copy']['titles']['captcha_failed'] );
	}

	public function test_copy_unknown_key_is_stripped_and_reported(): void {
		list( $clean, $rejected ) = Sanitize::clean( array(
			'copy' => array(
				'messages' => array( 'not_a_rule' => 'x' ),
				'titles'   => array( 'also_fake' => 'y' ),
			),
		) );
		$this->assertSame( array(), $clean['copy']['messages'] );
		$this->assertSame( array(), $clean['copy']['titles'] );
		$this->assertSame( array( 'copy.messages.not_a_rule', 'copy.titles.also_fake' ), $rejected );
	}

	public function test_copy_whitelist_covers_exactly_copywork_keys(): void {
		$rules = Sanitize::rules();
		foreach ( Copywork::keys() as $key ) {
			$this->assertArrayHasKey( 'copy.messages.' . $key, $rules );
			$this->assertArrayHasKey( 'copy.titles.' . $key, $rules );
		}
		$this->assertSame( 'textarea', $rules['copy.messages.banned_word'] );
		$this->assertSame( 'text', $rules['copy.titles.banned_word'] );
	}

	/**
	 * 【v1.5·B1】die_page 站长自定义拦截页文案键入白名单（单一来源机制照常）。
	 */
	public function test_die_page_custom_keys_whitelisted(): void {
		$rules = Sanitize::rules();
		$this->assertSame( 'text', $rules['copy.die_page_title'] );
		$this->assertSame( 'textarea', $rules['copy.die_page_message'] );

		list( $clean, $rejected ) = Sanitize::clean( array(
			'copy' => array(
				'die_page_title'   => ' 你被拦下了 ',
				'die_page_message' => " 事件 {event_no}\n第二行 ",
			),
		) );
		$this->assertSame( array(), $rejected );
		$this->assertSame( '你被拦下了', $clean['copy']['die_page_title'] );
		$this->assertSame( "事件 {event_no}\n第二行", $clean['copy']['die_page_message'] );
	}

	/* ---------- schema 与 rules 的双向一致性 ---------- */

	/**
	 * 默认树每个叶子路径都有清洗规则（除 schema_version 非用户提交），
	 * 且 rules 中不存在默认树之外的路径——加键漏改规则的故障模式被测试封死。
	 */
	public function test_rules_cover_every_schema_leaf_and_vice_versa(): void {
		$leaves = array();
		$walk   = function ( array $node, string $prefix ) use ( &$leaves, &$walk ): void {
			foreach ( $node as $key => $value ) {
				$path = ( '' === $prefix ) ? (string) $key : $prefix . '.' . $key;
				// list（顺序数组，如 chain）整体视为一个叶子，不下钻元素。
				$is_list = is_array( $value ) && array() !== $value
					&& array_keys( $value ) === range( 0, count( $value ) - 1 );
				if ( is_array( $value ) && array() !== $value && ! $is_list ) {
					$walk( $value, $path );
				} else {
					$leaves[ $path ] = true;
				}
			}
		};
		$walk( SettingsSchema::defaults(), '' );

		$rules      = Sanitize::rules();
		$exceptions = array( 'schema_version' );

		foreach ( array_keys( $leaves ) as $path ) {
			if ( in_array( $path, $exceptions, true ) ) {
				continue;
			}
			$this->assertArrayHasKey( $path, $rules, '默认树叶子缺清洗规则：' . $path );
		}
		foreach ( array_keys( $rules ) as $path ) {
			$this->assertArrayHasKey( $path, $leaves, '规则指向默认树不存在的路径：' . $path );
		}
	}
}
