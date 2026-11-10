<?php
/**
 * CommentGate 送审拼接拼装测试（F36，02 §2.2 v1.3：闸统一拼装，驱动不各自拼）。
 *
 * 覆盖：
 *   - include_author_fields=true（默认）：送审串 =「昵称 \n 邮箱 \n 正文」三行拼接——
 *     昵称/邮箱藏禁词、正文干净 → 拦；$meta 携带 author/email/content 原值；
 *   - include_author_fields=false：退回仅正文（昵称藏词放行）；
 *   - 字数闸按正文计，不受昵称影响（02 §2.2 例外条款）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\CommentGate;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Verdict;

final class CommentGateConcatTest extends TestCase {

	private static $stub_hash;

	public static function setUpBeforeClass(): void {
		self::$stub_hash = static function ( string $data ): string {
			return 'h_' . hash_hmac( 'sha256', $data, 'gate-test-key' );
		};
	}

	private static function settings( array $overrides = array() ): array {
		return array_merge( array(
			'gates'  => array( 'comment' => array( 'enabled' => true, 'render_mode' => 'always' ) ),
			'review' => array(
				'local' => array(
					'min_length' => 0, 'max_length' => 1800, 'require_chinese' => 1,
					'custom_banned_words' => '私活', 'use_wp_keywords' => 0,
				),
			),
			'stats'  => array( 'enabled' => true, 'pass_sampling' => false ),
		), $overrides );
	}

	/** 无验证码驱动（captcha 未配置 → captcha 阶段整体跳过，直达 review）。 */
	private function gate( ?callable $review_spy = null, array $settings_overrides = array() ): CommentGate {
		$review = $review_spy ?? static function ( string $content, array $meta ): Verdict {
			// 透传假件：按 LocalRules 语义只做 banned_word 子串判断（拼装行为断言在 spy 用例）。
			return ( false !== mb_strpos( $content, '私活', 0, 'UTF-8' ) )
				? Verdict::reject( 'local_rules', 'banned_word', array( '私活' ) )
				: Verdict::pass( 'local_rules' );
		};
		return new CommentGate(
			self::settings( $settings_overrides ),
			null,
			new class( $review ) implements \WinkGatekeeper\Drivers\ReviewDriverInterface {
				private $fn;
				public function __construct( callable $fn ) {
					$this->fn = $fn;
				}
				public function check( string $content, array $meta ): Verdict {
					return call_user_func( $this->fn, $content, $meta );
				}
			},
			static function ( string $stage, Verdict $v, array $meta ): string {
				return 'WK-20261110-0001';
			},
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return 1731168000;
			}
		);
	}

	private static function commentdata( array $overrides = array() ): array {
		return array_merge( array(
			'comment_post_ID'      => 42,
			'comment_author'       => '张三',
			'comment_author_email' => 'zhang@example.com',
			'comment_content'      => '这是一条正文干净的正常中文评论。',
		), $overrides );
	}

	private static function request( array $overrides = array() ): array {
		return array_merge( array( 'comment_post_ID' => '42' ), $overrides );
	}

	private static function server(): array {
		return array( 'REMOTE_ADDR' => '203.0.113.7' );
	}

	/** 拼装形态断言：驱动收到的 $content 恰为「昵称\n邮箱\n正文」三行，meta 携原值。 */
	public function test_gate_assembles_author_email_body_string(): void {
		$captured = array();
		$gate     = $this->gate( static function ( string $content, array $meta ) use ( &$captured ): Verdict {
			$captured['content'] = $content;
			$captured['meta']    = $meta;
			return Verdict::pass( 'local_rules' );
		} );

		$gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( "张三\nzhang@example.com\n这是一条正文干净的正常中文评论。", $captured['content'] );
		$this->assertSame( '张三', $captured['meta']['author'] );
		$this->assertSame( 'zhang@example.com', $captured['meta']['email'] );
		$this->assertSame( '这是一条正文干净的正常中文评论。', $captured['meta']['content'] );
	}

	/** 昵称藏禁词、正文干净 → 拦（闸内自建 LocalRules 走完整四闸）。 */
	public function test_nickname_hiding_banned_word_is_rejected(): void {
		$gate = $this->gate( null ); // review 缺省假件按子串判「私活」。
		$result = $gate->orchestrate(
			self::commentdata( array( 'comment_author' => '承接私活的张三' ) ),
			self::request(),
			self::server()
		);
		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'banned_word', $result['evidence']['命中规则'] );
	}

	/** 邮箱藏禁词、正文干净 → 拦。 */
	public function test_email_hiding_banned_word_is_rejected(): void {
		$gate = $this->gate( null );
		$result = $gate->orchestrate(
			self::commentdata( array( 'comment_author_email' => 'ad-私活@example.com' ) ),
			self::request(),
			self::server()
		);
		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'banned_word', $result['evidence']['命中规则'] );
	}

	/** include_author_fields=false：退回仅正文判定——昵称藏词放行。 */
	public function test_switch_off_falls_back_to_body_only(): void {
		$overrides = array(
			'review' => array(
				'local'                 => array(
					'min_length' => 0, 'max_length' => 1800, 'require_chinese' => 1,
					'custom_banned_words' => '私活', 'use_wp_keywords' => 0,
				),
				'include_author_fields' => false,
			),
		);
		$captured = array();
		$gate     = $this->gate( static function ( string $content, array $meta ) use ( &$captured ): Verdict {
			$captured['content'] = $content;
			return ( false !== mb_strpos( $content, '私活', 0, 'UTF-8' ) )
				? Verdict::reject( 'local_rules', 'banned_word', array( '私活' ) )
				: Verdict::pass( 'local_rules' );
		}, $overrides );

		$result = $gate->orchestrate(
			self::commentdata( array( 'comment_author' => '承接私活的张三' ) ),
			self::request(),
			self::server()
		);

		$this->assertSame( 'allow', $result['decision'], '开关关闭 → 仅正文判定，昵称藏词不再命中' );
		$this->assertSame( '这是一条正文干净的正常中文评论。', $captured['content'] );
	}

	/** 字数闸按正文计：长昵称不救短正文（LocalRules ①②③ 消费 meta.content）。 */
	public function test_length_gate_counts_body_despite_long_nickname(): void {
		$overrides = array(
			'review' => array(
				'local' => array(
					'min_length' => 10, 'max_length' => 1800, 'require_chinese' => 1,
					'custom_banned_words' => '', 'use_wp_keywords' => 0,
				),
			),
		);

		// 真驱动（闸内自建路径）：构造 gate 时 review 传 null → 自建 LocalRules。
		$gate = new CommentGate(
			self::settings( $overrides ),
			null,
			null,
			static function ( string $stage, Verdict $v, array $meta ): string {
				return 'WK-20261110-0002';
			},
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return 1731168000;
			}
		);

		$result = $gate->orchestrate(
			self::commentdata( array(
				'comment_author' => str_repeat( '长', 2000 ),
				'comment_content' => '短',
			) ),
			self::request(),
			self::server()
		);
		$this->assertSame( 'reject', $result['decision'] );
		$this->assertSame( 'too_short', $result['evidence']['命中规则'], '正文 1 字 < 10——长昵称不得稀释字数闸' );
	}
}
