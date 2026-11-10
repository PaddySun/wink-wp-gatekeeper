<?php
/**
 * LocalRulesDriver 单元测试（05 §2.1：四闸 + 正则防呆 + WP 关键词联动）。
 *
 * 规格：四闸依序短路——① 字数下限（min_length，0=不限）② 字数上限（max_length）
 * ③ 中文检测（[\x{4e00}-\x{9fa5}]，可关）④ 关键词+正则（逐行，/regex/flags 形态正则匹配，
 * 其余子串匹配）；判定恒二值 pass/reject（无 review 态）；非法正则前置校验跳过该行并记
 * 事件 reason「regex invalid: line N」；运行期 preg 失败（回溯限制等）同样跳过该行；
 * WP disallowed_keys 联动经注入回调取词（use_wp_keywords 开关）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\DriverRegistry;
use WinkGatekeeper\Drivers\Review\LocalRulesDriver;
use WinkGatekeeper\Verdict;

final class LocalRulesDriverTest extends TestCase {

	private static function driver( array $overrides = array(), ?callable $wp_keywords = null ): LocalRulesDriver {
		$settings = array_merge(
			array(
				'min_length'          => 0,
				'max_length'          => 1800,
				'require_chinese'     => 1,
				'custom_banned_words' => '',
				'use_wp_keywords'     => 1,
			),
			$overrides
		);
		return new LocalRulesDriver( $settings, $wp_keywords );
	}

	/* ---------- 闸①：字数下限 ---------- */

	public function test_min_length_rejects_short_comment(): void {
		$verdict = self::driver( array( 'min_length' => 5 ) )->check( '沙发', array() );
		$this->assertSame( Verdict::REJECT, $verdict->verdict() );
		$this->assertSame( 'too_short', $verdict->reason() );
	}

	public function test_min_length_zero_means_unlimited(): void {
		$verdict = self::driver( array( 'min_length' => 0 ) )->check( '嗯', array() );
		$this->assertSame( Verdict::PASS, $verdict->verdict() );
	}

	public function test_length_counts_multibyte_characters(): void {
		// mb 口径：'沙发' = 2 字；'abc' = 3 字。
		$this->assertSame( Verdict::REJECT, self::driver( array( 'min_length' => 3 ) )->check( '沙发', array() )->verdict() );
		$this->assertSame( Verdict::PASS, self::driver( array( 'min_length' => 2 ) )->check( '沙发', array() )->verdict() );
	}

	/* ---------- 闸②：字数上限 ---------- */

	public function test_max_length_rejects_long_comment(): void {
		$content = str_repeat( '好', 11 );
		$verdict = self::driver( array( 'max_length' => 10 ) )->check( $content, array() );
		$this->assertSame( Verdict::REJECT, $verdict->verdict() );
		$this->assertSame( 'too_long', $verdict->reason() );
	}

	public function test_min_before_max_gates_short_circuit(): void {
		// 四闸依序短路：先下限后上限。
		$verdict = self::driver( array( 'min_length' => 5, 'max_length' => 10 ) )->check( '短', array() );
		$this->assertSame( 'too_short', $verdict->reason() );
	}

	/* ---------- 闸③：中文检测 ---------- */

	public function test_no_chinese_rejects_pure_latin(): void {
		$verdict = self::driver()->check( 'nice article, very helpful', array() );
		$this->assertSame( Verdict::REJECT, $verdict->verdict() );
		$this->assertSame( 'no_chinese', $verdict->reason() );
	}

	public function test_require_chinese_off_passes_latin(): void {
		$verdict = self::driver( array( 'require_chinese' => 0 ) )->check( 'nice article', array() );
		$this->assertSame( Verdict::PASS, $verdict->verdict() );
	}

	public function test_content_with_one_chinese_char_passes_chinese_gate(): void {
		$verdict = self::driver()->check( 'good 好评 one', array() );
		$this->assertSame( Verdict::PASS, $verdict->verdict() );
	}

	/* ---------- 闸④：关键词 + 正则 ---------- */

	public function test_plain_keyword_hit_rejects(): void {
		$verdict = self::driver( array( 'custom_banned_words' => "viagra\n广告" ) )->check( '这个 viagrA 不错', array() );
		$this->assertSame( Verdict::REJECT, $verdict->verdict() );
		$this->assertSame( 'banned_word', $verdict->reason() );
		$this->assertContains( 'viagra', $verdict->hits() );
	}

	public function test_chinese_keyword_hit_rejects(): void {
		$verdict = self::driver( array( 'custom_banned_words' => "代开发票" ) )->check( '可代开发票 联系我', array() );
		$this->assertSame( Verdict::REJECT, $verdict->verdict() );
		$this->assertContains( '代开发票', $verdict->hits() );
	}

	public function test_regex_line_matching(): void {
		$verdict = self::driver( array( 'custom_banned_words' => '/(加油|加气)站代办/i' ) )->check( '办理 加油站代办 手续', array() );
		$this->assertSame( Verdict::REJECT, $verdict->verdict() );
		$this->assertContains( '/(加油|加气)站代办/i', $verdict->hits() );
	}

	public function test_regex_line_without_flags_supported(): void {
		$verdict = self::driver( array( 'custom_banned_words' => '/cargo\d{3,}/' ) )->check( 'cargo123 现货', array() );
		$this->assertSame( Verdict::REJECT, $verdict->verdict() );
	}

	public function test_invalid_regex_line_is_skipped_with_note_and_others_still_work(): void {
		$driver  = self::driver( array( 'custom_banned_words' => "/[unclosed\nviagra" ) );
		$verdict = $driver->check( '含中文的 viagra 评论', array() );

		// 非法正则行跳过（不 reject 全灭），词表其余行照常命中。
		$this->assertSame( Verdict::REJECT, $verdict->verdict() );
		$this->assertSame( array( 'regex invalid: line 1' ), $driver->notes() );
	}

	public function test_runtime_preg_failure_line_is_skipped_with_note(): void {
		// 运行期失败（非法 UTF-8 正文 × /u 修饰符 → PREG_BAD_UTF8_ERROR，确定性触发）：
		// 站长写错一行正则不应导致评论全灭——跳过该行并记备注（v1.2 口径）。
		$driver  = self::driver( array(
			'require_chinese'     => 0, // 越过中文闸，直达关键词闸。
			'custom_banned_words' => '/好+/u',
		) );
		$verdict = $driver->check( "\xB1\xB2\xB3", array() );

		$this->assertSame( Verdict::PASS, $verdict->verdict(), '运行期失败行应跳过而非 reject' );
		$this->assertSame( array( 'regex runtime fail: line 1' ), $driver->notes() );
	}

	/* ---------- WP disallowed_keys 联动 ---------- */

	public function test_wp_keywords_callback_is_merged_and_hits(): void {
		$wp_words = static function (): array {
			return array( 'casino', '比价网' );
		};
		$verdict  = self::driver( array(), $wp_words )->check( '最好 casino 在这里', array() );
		$this->assertSame( Verdict::REJECT, $verdict->verdict() );
		$this->assertContains( 'casino', $verdict->hits() );
	}

	public function test_wp_keywords_disabled_switch(): void {
		$wp_words = static function (): array {
			return array( 'casino' );
		};
		$verdict  = self::driver( array( 'use_wp_keywords' => 0 ), $wp_words )->check( '最好 casino 在这里', array() );
		$this->assertSame( Verdict::PASS, $verdict->verdict() );
	}

	public function test_wp_keywords_callback_absent_falls_back_to_none(): void {
		$verdict = self::driver()->check( 'casino 在这里', array() );
		$this->assertSame( Verdict::PASS, $verdict->verdict() );
	}

	/* ---------- 判定形态 ---------- */

	public function test_verdict_is_always_binary_no_review_state(): void {
		$cases = array(
			self::driver( array( 'min_length' => 5 ) )->check( '短', array() ),
			self::driver( array( 'max_length' => 4 ) )->check( '太长了太长了太长了', array() ),
			self::driver()->check( 'english only', array() ),
			self::driver( array( 'custom_banned_words' => 'viagra' ) )->check( 'viagra 好评', array() ),
			self::driver()->check( '这是一条正常的中文评论', array() ),
		);
		foreach ( $cases as $verdict ) {
			$this->assertContains( $verdict->verdict(), array( Verdict::PASS, Verdict::REJECT ), 'LocalRules 恒二值（无 review 态）' );
			$this->assertSame( 'local_rules', $verdict->driver_id() );
		}
	}

	public function test_pass_verdict_has_empty_hits_and_no_notes(): void {
		$driver  = self::driver();
		$verdict = $driver->check( '这是一条正常的中文评论', array() );
		$this->assertSame( Verdict::PASS, $verdict->verdict() );
		$this->assertSame( array(), $verdict->hits() );
		$this->assertSame( array(), $driver->notes() );
	}

	public function test_meta_is_open_key_set_and_not_required(): void {
		// $meta 为开放键集（02 §2.2）：空数组可用，LocalRules M1 不消费。
		$verdict = self::driver()->check( '这是一条正常的中文评论', array( 'author' => '访客' ) );
		$this->assertSame( Verdict::PASS, $verdict->verdict() );
	}

	public function test_empty_content_rejected_by_min_length_when_set(): void {
		$verdict = self::driver( array( 'min_length' => 1 ) )->check( '', array() );
		$this->assertSame( 'too_short', $verdict->reason() );
	}

	/* ---------- 注册表元数据（05 §4 六维声明） ---------- */

	public function test_registry_entry_passes_driver_registry_validation(): void {
		$registry = new DriverRegistry();
		$registry->register( LocalRulesDriver::registry_entry() );

		$entry = $registry->get( 'local_rules' );
		$this->assertNotNull( $entry );
		$this->assertSame( 'review', $entry['type'] );
		$this->assertSame( 'free', $entry['billing'] );
		$this->assertSame( array(), $entry['frontend'] ); // review 类无前端形态.
		$this->assertArrayHasKey( 'api_version', $entry );
		$this->assertNotSame( '', $entry['api_version'] );
	}
}
