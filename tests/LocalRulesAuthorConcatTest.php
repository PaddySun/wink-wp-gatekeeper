<?php
/**
 * LocalRules 匹配范围拼接串测试（F36，02 §2.2 / 05 §2.1 v1.3 消费约定）。
 *
 * 送审拼接消费约定：闸在调用 check() 前把 $content 统一拼装为「昵称 \n 邮箱 \n 正文」
 * 三行拼接串（review.include_author_fields 默认开；关 = 仅正文）——本文件在驱动层
 * 钉死消费语义：
 *   - 闸④ 关键词/正则（含 WP disallowed_keys 联动）匹配对象 = 拼接串 $content；
 *   - 闸①②③ 字数/中文仍只算正文（$meta['content'] 携带正文原值，缺省回落 $content）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\Review\LocalRulesDriver;

final class LocalRulesAuthorConcatTest extends TestCase {

	private static function local_settings( array $overrides = array() ): array {
		return array_merge( array(
			'min_length'          => 0,
			'max_length'          => 1800,
			'require_chinese'     => 1,
			'custom_banned_words' => "私活\n/代办/",
			'use_wp_keywords'     => 0,
		), $overrides );
	}

	/** 闸④：昵称行藏禁词（正文干净）→ reject banned_word。 */
	public function test_keyword_in_nickname_line_rejects(): void {
		$driver = new LocalRulesDriver( self::local_settings() );
		$verdict = $driver->check(
			"承接私活的张三\nzhang@example.com\n这是一条正文干净的正常中文评论。",
			array( 'content' => '这是一条正文干净的正常中文评论。', 'author' => '承接私活的张三', 'email' => 'zhang@example.com' )
		);
		$this->assertSame( 'reject', $verdict->verdict() );
		$this->assertSame( 'banned_word', $verdict->reason() );
		$this->assertContains( '私活', $verdict->hits() );
	}

	/** 闸④：邮箱行藏禁词 → reject。 */
	public function test_keyword_in_email_line_rejects(): void {
		$driver = new LocalRulesDriver( self::local_settings() );
		$verdict = $driver->check(
			"李四\nad-私活@example.com\n这是一条正文干净的正常中文评论。",
			array( 'content' => '这是一条正文干净的正常中文评论。', 'author' => '李四', 'email' => 'ad-私活@example.com' )
		);
		$this->assertSame( 'reject', $verdict->verdict() );
		$this->assertSame( 'banned_word', $verdict->reason() );
	}

	/** 闸④：正则行命中昵称段 → reject（拼接串整体为正则匹配对象）。 */
	public function test_regex_line_matches_nickname(): void {
		$driver = new LocalRulesDriver( self::local_settings() );
		$verdict = $driver->check(
			"代办驾照王五\nw@example.com\n正常中文正文内容。",
			array( 'content' => '正常中文正文内容。', 'author' => '代办驾照王五', 'email' => 'w@example.com' )
		);
		$this->assertSame( 'reject', $verdict->verdict() );
		$this->assertSame( 'banned_word', $verdict->reason() );
	}

	/** 闸④：WP disallowed_keys 联动词命中拼接串昵称行 → reject（联动同跑拼接串）。 */
	public function test_wp_disallowed_keys_run_against_concatenated_string(): void {
		$driver = new LocalRulesDriver(
			self::local_settings( array( 'custom_banned_words' => '', 'use_wp_keywords' => 1 ) ),
			static function (): array {
				return array( '刷单' );
			}
		);
		$verdict = $driver->check(
			"刷单团队\ns@example.com\n正常中文正文内容，不含词表词。",
			array( 'content' => '正常中文正文内容，不含词表词。', 'author' => '刷单团队', 'email' => 's@example.com' )
		);
		$this->assertSame( 'reject', $verdict->verdict() );
		$this->assertSame( 'banned_word', $verdict->reason() );
	}

	/** 闸①：字数下限只算正文——超长昵称不救短正文。 */
	public function test_min_length_counts_body_only(): void {
		$driver = new LocalRulesDriver( self::local_settings( array( 'min_length' => 10, 'custom_banned_words' => '' ) ) );
		$verdict = $driver->check(
			"这是一个非常非常长的昵称用于稀释字数语义的测试样本数据\nlong-nickname@example.com\n短",
			array( 'content' => '短', 'author' => '这是一个非常非常长的昵称用于稀释字数语义的测试样本数据', 'email' => 'long-nickname@example.com' )
		);
		$this->assertSame( 'reject', $verdict->verdict() );
		$this->assertSame( 'too_short', $verdict->reason() );
	}

	/** 闸②：字数上限只算正文——长昵称不把正文「撑爆」上限。 */
	public function test_max_length_counts_body_only(): void {
		$long_nickname = str_repeat( '长', 2000 );
		$driver = new LocalRulesDriver( self::local_settings( array( 'max_length' => 1800, 'custom_banned_words' => '' ) ) );
		$verdict = $driver->check(
			$long_nickname . "\nn@example.com\n正常短正文。",
			array( 'content' => '正常短正文。', 'author' => $long_nickname, 'email' => 'n@example.com' )
		);
		$this->assertSame( 'pass', $verdict->verdict(), '拼接串总长 >1800 但正文仅 6 字——不应 too_long' );
	}

	/** 闸③：中文检测只算正文——昵称恒含中文不得架空中文闸。 */
	public function test_chinese_gate_counts_body_only(): void {
		$driver = new LocalRulesDriver( self::local_settings( array( 'custom_banned_words' => '' ) ) );
		$verdict = $driver->check(
			"中文昵称\nc@example.com\npure ascii body without chinese",
			array( 'content' => 'pure ascii body without chinese', 'author' => '中文昵称', 'email' => 'c@example.com' )
		);
		$this->assertSame( 'reject', $verdict->verdict() );
		$this->assertSame( 'no_chinese', $verdict->reason() );
	}

	/** 旧形态兼容：meta 不带 content（闸未拼装/直调）时 ①②③ 回落 $content。 */
	public function test_body_falls_back_to_content_when_meta_missing(): void {
		$driver = new LocalRulesDriver( self::local_settings( array( 'custom_banned_words' => '', 'require_chinese' => 0 ) ) );
		$verdict = $driver->check( "纯正文直调形态", array() );
		$this->assertSame( 'pass', $verdict->verdict() );
	}

	/** 干净拼接串 → pass（正常评论不被拼接本身误伤）。 */
	public function test_clean_concatenated_string_passes(): void {
		$driver = new LocalRulesDriver( self::local_settings() );
		$verdict = $driver->check(
			"张三\nz@example.com\n这是一条完全正常的中文评论内容。",
			array( 'content' => '这是一条完全正常的中文评论内容。', 'author' => '张三', 'email' => 'z@example.com' )
		);
		$this->assertSame( 'pass', $verdict->verdict() );
	}
}
