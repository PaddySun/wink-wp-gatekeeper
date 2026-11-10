<?php
/**
 * CommentTrust 历史信用单元测试（02 §3.3a 历史信用判定/R2 §2.4——T2）。
 *
 * 判定语义（反馈 6.3）：
 *   游客   = 同昵称+同邮箱已批评论条数 ≥ N（min_approved，默认 3）；
 *   登录者 = 信用键 user_id（缓存/stub 绑定维度），计数默认走**邮箱索引等价路径**
 *            （comment_author_email=账户邮箱——core 无 user_id 索引，字面路径零命中=全表扫；
 *             filter wink_gatekeeper_trust_count_path 可切 'user_id' 字面路径）。
 * SQL 一律 LIMIT N 短路；缓存 transient wk_tc_<HMAC16(身份)> 值=布尔（不存计数——防邮箱枚举），
 * TTL=credit_cache_minutes（默认 5，0=每点必查）；失效钩子=transition_comment_status+wp_insert_comment。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\CommentTrust;

final class CommentTrustTest extends TestCase {

	/** @var callable 固定身份 HMAC（生产 = wp_salt('auth') HMAC-SHA256[:16]） */
	private static $mac;

	public static function setUpBeforeClass(): void {
		self::$mac = static function ( string $data ): string {
			return substr( hash_hmac( 'sha256', $data, 'trust-mac-key' ), 0, 16 );
		};
	}

	/** @var array<string, mixed> 内存 transient 假件 */
	private $store = array();

	private function store_get( string $key ) {
		return array_key_exists( $key, $this->store ) ? $this->store[ $key ] : false;
	}

	private function store_put( string $key, $value, int $ttl ): void {
		$this->store[ $key ] = $value;
	}

	private function store_delete( string $key ): void {
		unset( $this->store[ $key ] );
	}

	/**
	 * @param array<string, mixed>    $trust     captcha.trust 子树。
	 * @param callable                $counter   fn(mode,args,limit):int 假件。
	 * @param callable|null           $filter    apply_filters 假件。
	 */
	private function trust( array $trust, callable $counter, ?callable $filter = null ): CommentTrust {
		return new CommentTrust(
			$trust,
			$counter,
			function ( string $key ) {
				return $this->store_get( $key );
			},
			function ( string $key, $value, int $ttl ) {
				$this->store_put( $key, $value, $ttl );
			},
			function ( string $key ) {
				$this->store_delete( $key );
			},
			self::$mac,
			null,
			$filter
		);
	}

	/* ---------- 游客路径：昵称+邮箱双匹配 ---------- */

	public function test_guest_meets_threshold_passes(): void {
		$calls = array();
		$trust = $this->trust(
			array( 'min_approved' => 3, 'credit_cache_minutes' => 5 ),
			function ( string $mode, array $args, int $limit ) use ( &$calls ): int {
				$calls[] = array( $mode, $args, $limit );
				return 3;
			}
		);

		$this->assertTrue( $trust->history_pass( array( 'email' => 'reader@example.com', 'author' => '老读者' ) ) );
		$this->assertSame( array( array( 'guest', array( 'email' => 'reader@example.com', 'author' => '老读者' ), 3 ) ), $calls, '游客=guest 模式（昵称+邮箱双匹配），LIMIT N 短路' );
	}

	public function test_guest_below_threshold_fails(): void {
		$trust = $this->trust(
			array( 'min_approved' => 3, 'credit_cache_minutes' => 5 ),
			function (): int {
				return 2;
			}
		);
		$this->assertFalse( $trust->history_pass( array( 'email' => 'new@example.com', 'author' => '新人' ) ) );
	}

	public function test_guest_empty_email_fails_without_query(): void {
		$queried = false;
		$trust   = $this->trust(
			array( 'min_approved' => 3, 'credit_cache_minutes' => 5 ),
			function () use ( &$queried ): int {
				$queried = true;
				return 99;
			}
		);
		$this->assertFalse( $trust->history_pass( array( 'email' => '', 'author' => '匿名' ) ) );
		$this->assertFalse( $queried, '无邮箱=无信用身份，零查询直接 false' );
	}

	public function test_guest_email_is_lowercased_for_matching(): void {
		$calls = array();
		$trust = $this->trust(
			array( 'min_approved' => 1, 'credit_cache_minutes' => 0 ),
			function ( string $mode, array $args, int $limit ) use ( &$calls ): int {
				$calls[] = $args;
				return 1;
			}
		);
		$trust->history_pass( array( 'email' => 'Reader@Example.COM', 'author' => '老读者' ) );
		$this->assertSame( 'reader@example.com', $calls[0]['email'], '邮箱归一化为小写（与 WP 写入语义一致）' );
	}

	/* ---------- 登录用户：user_id 信用键 + 邮箱索引等价路径 ---------- */

	public function test_logged_in_defaults_to_email_equivalent_path(): void {
		$calls = array();
		$trust = $this->trust(
			array( 'min_approved' => 3, 'credit_cache_minutes' => 5 ),
			function ( string $mode, array $args, int $limit ) use ( &$calls ): int {
				$calls[] = array( $mode, $args );
				return 5;
			}
		);

		$this->assertTrue( $trust->history_pass( array( 'user_id' => 7, 'email' => 'author@site.com' ) ) );
		$this->assertSame( 'user_email', $calls[0][0], '默认走邮箱索引等价路径（email≈账户唯一身份）' );
		$this->assertSame( 'author@site.com', $calls[0][1]['email'] );
	}

	public function test_logged_in_filter_switches_to_literal_user_id_path(): void {
		$calls = array();
		$trust = $this->trust(
			array( 'min_approved' => 3, 'credit_cache_minutes' => 5 ),
			function ( string $mode, array $args, int $limit ) use ( &$calls ): int {
				$calls[] = array( $mode, $args );
				return 3;
			},
			static function ( string $tag, $value ) {
				return 'wink_gatekeeper_trust_count_path' === $tag ? 'user_id' : $value; // 保守站长 filter 切回字面路径。
			}
		);

		$this->assertTrue( $trust->history_pass( array( 'user_id' => 7, 'email' => 'author@site.com' ) ) );
		$this->assertSame( 'user_id', $calls[0][0] );
		$this->assertSame( 7, $calls[0][1]['user_id'] );
	}

	/* ---------- 布尔缓存 ---------- */

	public function test_cache_hit_avoids_second_query(): void {
		$queries = 0;
		$trust   = $this->trust(
			array( 'min_approved' => 3, 'credit_cache_minutes' => 5 ),
			function () use ( &$queries ): int {
				$queries++;
				return 3;
			}
		);

		$this->assertTrue( $trust->history_pass( array( 'email' => 'reader@example.com', 'author' => '老读者' ) ) );
		$this->assertTrue( $trust->history_pass( array( 'email' => 'reader@example.com', 'author' => '老读者' ) ) );
		$this->assertSame( 1, $queries, '窗口内同身份第二次点击走缓存（<1ms 零 SQL）' );
	}

	public function test_negative_outcome_is_cached_too(): void {
		$queries = 0;
		$trust   = $this->trust(
			array( 'min_approved' => 3, 'credit_cache_minutes' => 5 ),
			function () use ( &$queries ): int {
				$queries++;
				return 0;
			}
		);

		$this->assertFalse( $trust->history_pass( array( 'email' => 'nobody@example.com', 'author' => '路人' ) ) );
		$this->assertFalse( $trust->history_pass( array( 'email' => 'nobody@example.com', 'author' => '路人' ) ) );
		$this->assertSame( 1, $queries, '布尔缓存两向都缓存（值=≥N 与否，不存计数——防邮箱枚举）' );
	}

	public function test_cache_zero_minutes_means_always_query(): void {
		$queries = 0;
		$trust   = $this->trust(
			array( 'min_approved' => 3, 'credit_cache_minutes' => 0 ),
			function () use ( &$queries ): int {
				$queries++;
				return 3;
			}
		);

		$trust->history_pass( array( 'email' => 'reader@example.com', 'author' => '老读者' ) );
		$trust->history_pass( array( 'email' => 'reader@example.com', 'author' => '老读者' ) );
		$this->assertSame( 2, $queries, '0=每点必查' );
		$this->assertSame( array(), $this->store, '0 分钟不产生任何缓存写入' );
	}

	public function test_cache_ttl_is_five_minutes_by_default_setting(): void {
		$written = array();
		$trust   = new CommentTrust(
			array( 'min_approved' => 3, 'credit_cache_minutes' => 5 ),
			static function (): int {
				return 3;
			},
			static function () {
				return false;
			},
			function ( string $key, $value, int $ttl ) use ( &$written ) {
				$written[] = array( 'key' => $key, 'value' => $value, 'ttl' => $ttl );
			},
			static function (): void {
			},
			self::$mac
		);

		$trust->history_pass( array( 'email' => 'reader@example.com', 'author' => '老读者' ) );
		$this->assertCount( 1, $written );
		$this->assertSame( '1', $written[0]['value'], "值=布尔线形态（'1'/'0'——与 WP transient 未命中 false 可区分）" );
		$this->assertSame( 300, $written[0]['ttl'], '5min TTL' );
		$this->assertSame( 0, strpos( $written[0]['key'], 'wk_tc_' ), '缓存前缀 wk_tc_（04 §1/§7）' );
	}

	/* ---------- 缓存键形态（R2 §2.4：g:+email / u:+user_id） ---------- */

	public function test_cache_key_format(): void {
		$mac  = self::$mac;
		$this->assertSame( 'wk_tc_' . $mac( 'g:reader@example.com' ), CommentTrust::cache_key( 'g', 'reader@example.com', self::$mac ) );
		$this->assertSame( 'wk_tc_' . $mac( 'u:7' ), CommentTrust::cache_key( 'u', '7', self::$mac ) );
	}

	/* ---------- 失效钩子面 ---------- */

	public function test_invalidate_clears_guest_and_user_keys(): void {
		$queries = 0;
		$trust   = $this->trust(
			array( 'min_approved' => 1, 'credit_cache_minutes' => 5 ),
			function () use ( &$queries ): int {
				$queries++;
				return 1;
			}
		);

		// 预热两类缓存。
		$trust->history_pass( array( 'email' => 'reader@example.com', 'author' => '老读者' ) );
		$trust->history_pass( array( 'user_id' => 7, 'email' => 'author@site.com' ) );

		// 站长刚批完评论（transition_comment_status → 已批）：email 命中的游客键 + user_id 键即时清除。
		$trust->invalidate( 'reader@example.com', 7 );

		$trust->history_pass( array( 'email' => 'reader@example.com', 'author' => '老读者' ) );
		$trust->history_pass( array( 'user_id' => 7, 'email' => 'author@site.com' ) );
		$this->assertSame( 4, $queries, '失效后两类身份都重查（站长刚批完评论，访客下一次预检立即可信）' );
	}

	public function test_invalidate_without_user_only_clears_guest_key(): void {
		$queries = 0;
		$trust   = $this->trust(
			array( 'min_approved' => 1, 'credit_cache_minutes' => 5 ),
			function () use ( &$queries ): int {
				$queries++;
				return 1;
			}
		);
		$trust->history_pass( array( 'email' => 'guest@example.com', 'author' => '游客' ) );
		$trust->invalidate( 'guest@example.com' );
		$trust->history_pass( array( 'email' => 'guest@example.com', 'author' => '游客' ) );
		$this->assertSame( 2, $queries );
	}
}
