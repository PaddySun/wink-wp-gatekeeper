<?php
/**
 * CommentGate 拦截留档（spam）测试（F37/D15，02 §3.1 v1.3「拦截留档」设计块）。
 *
 * 覆盖：
 *   - reject 落地顺序：先 wp_insert_comment 直插留档（archiver 注入假件承载）→
 *     meta.comment_id 回填 → 事件落库 → 响应（02 §3.5「先留档再响应」）；
 *   - stage ∈ {ip, captcha, review} 留档、stub 不留档（设计块 ②）；
 *   - intercept.archive_spam=false 跳过留档（设计块 ① 开关语义）；
 *   - 留档载荷归一化：comment_approved='spam'、comment_type=''、作者/邮箱/正文/
 *     IP（IpResolver 解析值）/UA 映射（REST 上下文字段差异在此层归一）；
 *   - 防递归：闸内唯一入库路径 = 注入的 archiver（wp_insert_comment 直插语义），
 *     不经 wp_new_comment/preprocess 链——单测环境未定义任何 WP 评论函数，
 *     若编排误入 preprocess 链将直接 Error，本用例即为守卫。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\CommentGate;
use WinkGatekeeper\Intercept\Stub;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Verdict;

final class CommentGateArchiveTest extends TestCase {

	private static $stub_hash;

	public static function setUpBeforeClass(): void {
		self::$stub_hash = static function ( string $data ): string {
			return 'h_' . hash_hmac( 'sha256', $data, 'gate-test-key' );
		};
	}

	private static function settings( array $overrides = array() ): array {
		return array_merge( array(
			'gates'     => array( 'comment' => array( 'enabled' => true, 'render_mode' => 'always' ) ),
			'review'    => array( 'local' => array(
				'min_length' => 0, 'max_length' => 1800, 'require_chinese' => 1,
				'custom_banned_words' => '私活', 'use_wp_keywords' => 0,
			) ),
			'intercept' => array( 'archive_spam' => true ),
			'stats'     => array( 'enabled' => true, 'pass_sampling' => false ),
		), $overrides );
	}

	/**
	 * 可编程假验证码驱动。
	 */
	private function fake_captcha( ?string $result ): object {
		return new class( $result ) implements \WinkGatekeeper\Drivers\CaptchaDriverInterface {
			private $result;
			public function __construct( ?string $result ) {
				$this->result = $result;
			}
			public function render( array $args ): string {
				return '';
			}
			public function verify( array $context ): ?string {
				return $this->result;
			}
			public function is_configured(): bool {
				return true;
			}
			public function enqueue_assets(): void {
			}
			public function id(): string {
				return 'fake-captcha';
			}
			public function label(): string {
				return '假验证码';
			}
			public function credentialFields(): array {
				return array( 'wk_captcha_ticket', 'wk_captcha_randstr' );
			}
			public function failurePolicy(): array {
				return array();
			}
			public function disasterPolicy(): string {
				return 'none';
			}
		};
	}

	private function gate( ?object $captcha, array &$archive_log, array &$event_log, array $settings_overrides = array() ): CommentGate {
		return new CommentGate(
			self::settings( $settings_overrides ),
			$captcha,
			null, // 自建 LocalRules（词表「私活」）。
			static function ( string $stage, Verdict $verdict, array $meta ) use ( &$event_log ): string {
				$event_log[] = array( 'stage' => $stage, 'meta' => $meta, 'verdict' => $verdict->verdict() );
				return 'WK-20261110-0099';
			},
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return 1731168000;
			},
			null,
			static function ( array $comment ) use ( &$archive_log ): ?int {
				$archive_log[] = $comment;
				return 777; // 假 comment_id。
			}
		);
	}

	private static function commentdata( array $overrides = array() ): array {
		return array_merge( array(
			'comment_post_ID'      => 42,
			'comment_author'       => '张三',
			'comment_author_email' => 'zhang@example.com',
			'comment_content'      => '这是藏了私活的中文评论。',
		), $overrides );
	}

	private static function request( array $overrides = array() ): array {
		return array_merge( array( 'comment_post_ID' => '42' ), $overrides );
	}

	private static function server(): array {
		return array(
			'REMOTE_ADDR'    => '203.0.113.7',
			'HTTP_USER_AGENT' => 'WINK-ArchiveTest/1.0',
		);
	}

	/**
	 * 合法存根（默认 captcha_shown=true → 验签后 state=null：展示过组件，必须验票）。
	 * 注：Stub 语义——shown=true → null（必须 verify）；shown=false → true（免验证放行）。
	 */
	private static function valid_stub( string $shown = 'true' ): string {
		$hash = self::$stub_hash;
		return Stub::encode_payload( 'comment', '42', 'true' === $shown, 1731167000 ) . '.' . $hash( Stub::encode_payload( 'comment', '42', 'true' === $shown, 1731167000 ) );
	}

	/* ---------- review 阶段留档 ---------- */

	public function test_review_reject_archives_spam_and_backfills_comment_id(): void {
		$archive_log = array();
		$event_log   = array();
		$gate        = $this->gate( null, $archive_log, $event_log );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertCount( 1, $archive_log, '留档恰好一次' );
		$payload = $archive_log[0];
		$this->assertSame( 'spam', $payload['comment_approved'], '直插 spam 分类（02 §3.1）' );
		$this->assertSame( '', $payload['comment_type'], 'comment_type 默认（02 §3.1）' );
		$this->assertSame( '张三', $payload['comment_author'] );
		$this->assertSame( 'zhang@example.com', $payload['comment_author_email'] );
		$this->assertSame( '这是藏了私活的中文评论。', $payload['comment_content'] );
		$this->assertSame( 42, $payload['comment_post_ID'] );
		$this->assertSame( '203.0.113.7', $payload['comment_author_IP'], 'IP = IpResolver 解析值' );
		$this->assertSame( 'WINK-ArchiveTest/1.0', $payload['comment_agent'], 'UA 随档' );

		$this->assertCount( 1, $event_log );
		$this->assertSame( 777, $event_log[0]['meta']['comment_id'], '事件 meta 回填 comment_id（04 §3 v1.3）' );
	}

	/** 归一化映射：REST 形态字段（author/email/parent/user_id 缺省）不炸、默认值正确。 */
	public function test_archive_payload_normalizes_rest_shaped_commentdata(): void {
		$archive_log = array();
		$event_log   = array();
		$gate        = $this->gate( null, $archive_log, $event_log );

		$gate->orchestrate(
			array( 'comment_post_ID' => 42, 'comment_content' => '这是藏了私活的中文评论。' ), // REST 只带 post_ID/content。
			self::request(),
			self::server()
		);

		$this->assertCount( 1, $archive_log );
		$this->assertSame( '', $archive_log[0]['comment_author'] );
		$this->assertSame( '', $archive_log[0]['comment_author_email'] );
		$this->assertSame( 0, $archive_log[0]['comment_parent'] );
		$this->assertSame( 0, $archive_log[0]['user_id'] );
	}

	/* ---------- captcha 阶段留档 ---------- */

	public function test_captcha_missing_reject_archives_spam(): void {
		$archive_log = array();
		$event_log   = array();
		$gate        = $this->gate( $this->fake_captcha( null ), $archive_log, $event_log );

		$gate->orchestrate(
			self::commentdata( array( 'comment_content' => '正常中文评论内容。' ) ),
			self::request( array( 'wk_stub' => self::valid_stub() ) ), // 存根 shown=true → state=null（展示过）→ 必须验票 → 无票拦。
			self::server()
		);

		$this->assertCount( 1, $archive_log );
		$this->assertSame( 'spam', $archive_log[0]['comment_approved'] );
		$this->assertSame( '正常中文评论内容。', $archive_log[0]['comment_content'] );
		$this->assertSame( 777, $event_log[0]['meta']['comment_id'] );
	}

	public function test_captcha_verify_error_reject_archives_spam(): void {
		$archive_log = array();
		$event_log   = array();
		$gate        = $this->gate( $this->fake_captcha( '票据过期，刷新重试' ), $archive_log, $event_log );

		$gate->orchestrate(
			self::commentdata( array( 'comment_content' => '正常中文评论内容。' ) ),
			self::request( array(
				'wk_stub' => self::valid_stub(),
				'wk_captcha_ticket' => 'T', 'wk_captcha_randstr' => 'R',
			) ),
			self::server()
		);

		$this->assertCount( 1, $archive_log );
		$this->assertSame( 777, $event_log[0]['meta']['comment_id'] );
	}

	/* ---------- stub 不留档（设计块 ②） ---------- */

	public function test_stub_reject_does_not_archive(): void {
		$archive_log = array();
		$event_log   = array();
		$gate        = $this->gate( $this->fake_captcha( null ), $archive_log, $event_log );

		$gate->orchestrate(
			self::commentdata( array( 'comment_content' => '正常中文评论内容。' ) ),
			self::request( array( 'wk_stub' => '' ) ), // 无存根 → bad-signature（stage=stub）。
			self::server()
		);

		$this->assertSame( array(), $archive_log, 'stub 拦截不留档（脚本探测流无归档价值）' );
		$this->assertCount( 1, $event_log, '事件照常落库' );
		$this->assertArrayNotHasKey( 'comment_id', $event_log[0]['meta'] );
	}

	/* ---------- 留档开关两态（设计块 ①） ---------- */

	public function test_archive_spam_switch_off_skips_archiving(): void {
		$archive_log = array();
		$event_log   = array();
		$gate        = $this->gate( null, $archive_log, $event_log, array(
			'intercept' => array( 'archive_spam' => false ),
		) );

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( 'reject', $result['decision'], '拦截判定不受留档开关影响' );
		$this->assertSame( array(), $archive_log, 'archive_spam=false → 跳过留档（直接拦不入库形态）' );
		$this->assertArrayNotHasKey( 'comment_id', $event_log[0]['meta'] );
	}

	/** 设置树缺 intercept 子树（旧站点形态）→ 默认开（merge_defaults 语义的闸内兜底）。 */
	public function test_missing_intercept_subtree_defaults_to_archive(): void {
		$archive_log = array();
		$event_log   = array();
		$settings    = self::settings();
		unset( $settings['intercept'] );

		$gate = new CommentGate(
			$settings,
			null,
			null,
			static function ( string $stage, Verdict $v, array $meta ) use ( &$event_log ): string {
				$event_log[] = array( 'stage' => $stage, 'meta' => $meta );
				return 'WK-20261110-0098';
			},
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return 1731168000;
			},
			null,
			static function ( array $comment ) use ( &$archive_log ): ?int {
				$archive_log[] = $comment;
				return 42;
			}
		);

		$gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertCount( 1, $archive_log, '缺省 = 默认开（04 §2 archive_spam 默认 true）' );
	}

	/* ---------- archiver 未装配（单元默认）与放行路径 ---------- */

	public function test_no_archiver_wired_still_rejects_without_comment_id(): void {
		$event_log = array();
		$gate      = new CommentGate(
			self::settings(),
			null,
			null,
			static function ( string $stage, Verdict $v, array $meta ) use ( &$event_log ): string {
				$event_log[] = array( 'stage' => $stage, 'meta' => $meta );
				return 'WK-20261110-0097';
			},
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			self::$stub_hash,
			static function (): int {
				return 1731168000;
			}
			// archiver 不注入：单测默认 = 不留档（WP 边界 archiver 由 Bootstrap 装配）。
		);

		$result = $gate->orchestrate( self::commentdata(), self::request(), self::server() );

		$this->assertSame( 'reject', $result['decision'] );
		$this->assertArrayNotHasKey( 'comment_id', $event_log[0]['meta'] );
	}

	public function test_allow_path_never_archives(): void {
		$archive_log = array();
		$event_log   = array();
		$gate        = $this->gate( null, $archive_log, $event_log );

		$result = $gate->orchestrate(
			self::commentdata( array( 'comment_content' => '这是一条完全正常的中文评论。' ) ),
			self::request(),
			self::server()
		);

		$this->assertSame( 'allow', $result['decision'] );
		$this->assertSame( array(), $archive_log );
		$this->assertSame( array(), $event_log, 'pass 默认不落库' );
	}
}
