<?php
/**
 * 历史信用判定 CommentTrust（02 §3.3a 历史信用判定/R2 §2.4——T2）。
 *
 * 判定语义（反馈 6.3）：
 *   游客   = comment_author=昵称 AND comment_author_email=邮箱 AND comment_approved='1' 条数 ≥ N；
 *   登录者 = 信用键 user_id（缓存/TrustStub 身份绑定维度——用户字面），计数默认走**邮箱索引
 *            等价路径**（comment_author_email=账户邮箱：core 无 user_id 索引，字面路径零命中=
 *            全表扫；WP 账户邮箱唯一故语义等价）；保守站长 filter
 *            wink_gatekeeper_trust_count_path='user_id' 切回字面路径。
 * N = captcha.trust.min_approved（默认 3——WP core「先前已批评论自动放行」先例=1，验证码
 * 豁免强于自动放行故默认收紧）。SQL 一律 LIMIT N 短路（email 前缀索引路径万级表 ≈0.1–2ms）。
 *
 * 缓存（R2 §2.4）：transient wk_tc_<HMAC16(身份)>，值=布尔（≥N 与否，不存计数——防邮箱枚举
 * 信息泄漏），TTL=captcha.trust.credit_cache_minutes（默认 5min，0=每点必查）。
 * 失效钩子（与 TTL 双保险，Bootstrap 挂 transition_comment_status + wp_insert_comment）：
 * 站长刚批完评论，访客下一次预检立即可信（无需等 TTL）。
 * 提交期终判不重跑 COUNT（trust stub 即凭证——信任凭证化的性能意义）。
 *
 * 分层：纯逻辑类——计数/缓存经注入回调（生产 = $wpdb LIMIT-N 子查询 / get_transient /
 * set_transient / delete_transient，Bootstrap 注入；单测注入假件）；默认身份 HMAC 为
 * WP 边界（wp_salt('auth') 派生——与 VerifyCache/事件 ip_hash 同源），测试注入固定函数。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

final class CommentTrust {

	/**
	 * transient 键前缀（04 §1/§7：信用 COUNT 缓存）。
	 */
	public const PREFIX = 'wk_tc_';

	/**
	 * 默认阈值 N（04 §2 captcha.trust.min_approved 单一来源镜像；读侧仍以设置树为准）。
	 */
	public const DEFAULT_MIN_APPROVED = 3;

	/**
	 * 默认缓存 TTL（分钟；04 §2 captcha.trust.credit_cache_minutes）。
	 */
	public const DEFAULT_CACHE_MINUTES = 5;

	/**
	 * @var int 阈值 N。
	 */
	private $min_approved;

	/**
	 * @var int 缓存分钟数（0 = 每点必查）。
	 */
	private $cache_minutes;

	/**
	 * @var callable 计数器 fn(string $mode, array $args, int $limit): int——mode:
	 *               'guest'（args: email+author）| 'user_email'（args: email）| 'user_id'（args: user_id）。
	 */
	private $counter;

	/**
	 * @var callable 缓存读取 fn(string $key): mixed。
	 */
	private $store_get;

	/**
	 * @var callable 缓存写入 fn(string $key, mixed $value, int $ttl_seconds): void。
	 */
	private $store_put;

	/**
	 * @var callable 缓存删除 fn(string $key): void（失效钩子消费）。
	 */
	private $store_delete;

	/**
	 * @var callable 身份 HMAC fn(string $data): string（生产 = wp_salt('auth') 派生 SHA256[:16]）。
	 */
	private $mac;

	/**
	 * @var callable|null apply_filters 假件/桥 fn(string $tag, mixed $value): mixed
	 *                    （生产 = apply_filters；计数路径 filter 消费）。
	 */
	private $apply_filters;

	/**
	 * @param array<string, mixed> $trust         captcha.trust 子树（min_approved/credit_cache_minutes）。
	 * @param callable             $counter       计数器回调。
	 * @param callable             $store_get     缓存读取。
	 * @param callable             $store_put     缓存写入。
	 * @param callable             $store_delete  缓存删除。
	 * @param callable|null        $mac           身份 HMAC（null = wp_salt('auth') WP 边界默认）。
	 * @param callable|null        $now           时钟（null = time()；预留，当前无时间敏感分支）。
	 * @param callable|null        $apply_filters filter 桥（null = 不走 filter，默认路径）。
	 */
	public function __construct(
		array $trust,
		callable $counter,
		callable $store_get,
		callable $store_put,
		callable $store_delete,
		?callable $mac = null,
		?callable $now = null,
		?callable $apply_filters = null
	) {
		unset( $now ); // 时钟注入位保留（TTL 由 transient 承载，本类不自管时间）。

		$this->min_approved  = max( 1, (int) ( $trust['min_approved'] ?? self::DEFAULT_MIN_APPROVED ) );
		$this->cache_minutes = max( 0, (int) ( $trust['credit_cache_minutes'] ?? self::DEFAULT_CACHE_MINUTES ) );
		$this->counter       = $counter;
		$this->store_get     = $store_get;
		$this->store_put     = $store_put;
		$this->store_delete  = $store_delete;
		$this->mac           = $mac
			?? static function ( string $data ): string {
				return hash_hmac( 'sha256', $data, wp_salt( 'auth' ) ); // WP 边界。
			};
		$this->apply_filters = $apply_filters;
	}

	/**
	 * 缓存键：前缀 + HMAC(身份) 前 16 位（R2 §2.4：'g:'+lower(email) / 'u:'+user_id）。
	 *
	 * @param string   $kind 'g'（游客）| 'u'（登录用户）。
	 * @param string   $value 身份值（email 小写 / user_id 字面）。
	 * @param callable $mac  身份 HMAC 回调。
	 * @return string
	 */
	public static function cache_key( string $kind, string $value, callable $mac ): string {
		return self::PREFIX . substr( (string) call_user_func( $mac, $kind . ':' . $value ), 0, 16 );
	}

	/**
	 * 历史信用判定（≥N 已批评论？）。
	 *
	 * @param array<string, mixed> $identity ['user_id'=>int>0, 'email'=>string]（登录）或
	 *                        ['email'=>string, 'author'=>string]（游客）。
	 * @return bool
	 */
	public function history_pass( array $identity ): bool {
		$user_id = isset( $identity['user_id'] ) && is_numeric( $identity['user_id'] ) ? (int) $identity['user_id'] : 0;
		$email   = isset( $identity['email'] ) && is_string( $identity['email'] ) ? strtolower( trim( $identity['email'] ) ) : '';
		$author  = isset( $identity['author'] ) && is_string( $identity['author'] ) ? $identity['author'] : '';

		if ( $user_id > 0 && '' !== $email ) {
			return $this->judge(
				self::cache_key( 'u', (string) $user_id, $this->mac ),
				array( $this, 'count_for_user' ),
				array( $user_id, $email )
			);
		}

		if ( '' !== $email && '' !== $author ) {
			return $this->judge(
				self::cache_key( 'g', $email, $this->mac ),
				array( $this, 'count_for_guest' ),
				array( $email, $author )
			);
		}

		return false; // 无可判身份（无邮箱/无昵称）=无信用，零查询直接 false。
	}

	/**
	 * 失效钩子面（Bootstrap 挂 transition_comment_status/wp_insert_comment 后调用）：
	 * 清除该 email 命中的游客键与（评论带 user_id 时）登录用户键。
	 *
	 * @param string $email   评论作者邮箱。
	 * @param int    $user_id 评论作者用户 ID（游客 0）。
	 * @return void
	 */
	public function invalidate( string $email, int $user_id = 0 ): void {
		$email = strtolower( trim( $email ) );
		if ( '' !== $email ) {
			call_user_func( $this->store_delete, self::cache_key( 'g', $email, $this->mac ) );
		}
		if ( $user_id > 0 ) {
			call_user_func( $this->store_delete, self::cache_key( 'u', (string) $user_id, $this->mac ) );
		}
	}

	/**
	 * 判定 + 布尔缓存（两向都缓存——值=≥N 与否，不存计数）。
	 *
	 * 存储形态='1'/'0' 字符串（布尔语义的线形态）：WP get_transient 未命中恒返 false（布尔），
	 * 存裸 false 会与未命中混淆；'1'/'0' 与未命中（false/null/''）天然可区分（与 VerifyCache
	 * 对 redis '1' 形态漂移的容忍注记同思路）。真布尔 true（对象缓存后端还原形态）也按命中。
	 *
	 * @param string   $key       缓存键。
	 * @param callable $count_fn  fn(array $args): int 计数闭包。
	 * @param array    $args      计数参数。
	 * @return bool
	 */
	private function judge( string $key, callable $count_fn, array $args ): bool {
		if ( $this->cache_minutes > 0 ) {
			$cached = call_user_func( $this->store_get, $key );
			if ( true === $cached ) {
				return true; // 布尔缓存命中（正缓存）。
			}
			if ( is_string( $cached ) && '' !== $cached ) {
				return '1' === $cached; // '1'/'0' 规范形态（含负缓存——两向都缓存防高频空查）。
			}
		}

		$count = (int) call_user_func( $count_fn, $args );
		$pass  = $count >= $this->min_approved;

		if ( $this->cache_minutes > 0 ) {
			call_user_func( $this->store_put, $key, $pass ? '1' : '0', $this->cache_minutes * 60 );
		}
		return $pass;
	}

	/**
	 * 游客计数（昵称+邮箱双匹配——用户规则字面执行；昵称改一个字=新身份）。
	 *
	 * @param array $args [email, author]。
	 * @return int
	 */
	private function count_for_guest( array $args ): int {
		return (int) call_user_func( $this->counter, 'guest', array(
			'email'  => $args[0],
			'author' => $args[1],
		), $this->min_approved );
	}

	/**
	 * 登录用户计数（路径分派：默认邮箱索引等价路径；filter 可切 user_id 字面路径）。
	 *
	 * @param array $args [user_id, email]。
	 * @return int
	 */
	private function count_for_user( array $args ): int {
		$path = 'email';
		if ( null !== $this->apply_filters ) {
			$filtered = call_user_func( $this->apply_filters, 'wink_gatekeeper_trust_count_path', 'email' );
			if ( is_string( $filtered ) && '' !== $filtered ) {
				$path = $filtered;
			}
		}

		if ( 'user_id' === $path ) {
			return (int) call_user_func( $this->counter, 'user_id', array( 'user_id' => $args[0] ), $this->min_approved );
		}
		return (int) call_user_func( $this->counter, 'user_email', array( 'email' => $args[1] ), $this->min_approved );
	}
}
