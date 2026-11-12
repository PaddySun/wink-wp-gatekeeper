<?php
/**
 * AbuseIPDB check 路径服务（M3-IP 定稿 §2/§3——【v1.7·M3-IP】核心件，I2 批）。
 *
 * 实现 IpReputationProbe 三态语义（权威定义 02 §2.3b）；probe() 七步流水线（次序即设计；
 * 防呆闸为定稿 §2 assemble 防呆分支的服务内承载——排白名单与规范化之后，见步 1.5 注）：
 *   步 0 白名单   站长手动白名单 ∨ 已登录 manage_options ∨ 服务器自身 IP → null（skip）
 *   步 1 规范化   IpResolver::normalize 规范形进缓存键与 URL（防 ::ffff: 变体稀释缓存）
 *   步 1.5 防呆   enabled && api_key 空 → fail（reject/not_configured）——从严例外之二
 *                 （白名单/管理员前置豁免不受防呆覆盖；不可解析 IP 不因漏配钥被误伤）
 *   步 2 退避     wk_irb_<driver> transient 在位（300s 窗口）→ skip，零外呼
 *   步 3 停转     DriverHealth quota_exhausted/config_error 当日停转 → skip
 *   步 4 软顶     Usage 当日 abuseipdb 计数 ≥ soft_quota（默认 3000）→ fail 不外呼
 *                 （保守态：宁可从严不可静默放行——从严例外之一，meta 标 soft_quota）
 *   步 5 缓存     wk_ip_<HMAC16(ip)> 命中 → 返回缓存 verdict（meta 标 cached:true），零外呼
 *   步 6 外呼+映射 GET https://api.abuseipdb.com/api/v2/check?ipAddress=<URL编码 ip>&maxAgeInDays=90
 *                 头 Key + Accept: application/json（RemoteClient standard，timeout 默认 1s；
 *                 usage_key='abuseipdb'——RemoteClient 计量钩子→Usage::bump_option 外呼尝试即计）；
 *                 abuseConfidenceScore ≥ malicious_score(85)→reject / ≥ suspect_score(60)→review /
 *                 其余→pass；isPublic=false→skip（null，不缓存）
 *   步 7 落账     缓存写（只在外呼成功后）+ 失败分类处置（下表）
 *
 * fail-open 失败分类处置矩阵（定稿 §3.2——唯二从严例外=软顶/防呆，其余失败不改闸行为）：
 *   transport（超时/网络错）  → 落退避 300s + meta.raw 快照；不停转（可能是抖动）
 *   429                      → 退避 300s（Retry-After ≥300s 按其值）+ DriverHealth
 *                              quota_exhausted 当日停转（次日自愈）
 *   401/402（semantic）      → DriverHealth config_error 当日停转 + Tab① 红点语义
 *   contract/parse（契约不符）→ contract_mismatch 哨兵标签（rule_hit 列，连续 5 次邮件
 *                              告警=D13 既有机制）+ 退避
 *
 * cache_only 只读模式（定稿 §2【O5 回写·P1-1】/§3.4——预检与登录渲染期零外呼承载）：
 *   步 0 白名单 → null skip 照旧；缓存读 → 命中返回该 verdict（证据优先于退避/停转）；
 *   未命中 ∨ 退避中 ∨ 停转中 → fail Verdict（reason=cache_miss_strict/backoff/halted）不返 null；
 *   软顶不评估、全程零外呼零计量零写。
 *
 * 缓存归一（定稿 §2 拍板）：缓存内化本服务（wk_ip_ 键读写+回填）；LoginIpRep 退役缓存职能
 * 改纯映射——无双写双读。损坏缓存值按 fail（fail-safe 从严，02 §2.3b 旧约沿袭）。
 *
 * 分层：缓存/退避/停转/用量/外呼/时钟/HMAC/白名单全注入——纯逻辑零 WP 依赖（生产装配
 * IpReputationService::assemble()，WP 边界闭包缺省；测试注入 fake——外呼次数计数器断言）。
 *
 * @package WinkGatekeeper\Ip
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Ip;

use WinkGatekeeper\Drivers\DriverRegistry;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Stats\DriverHealth;
use WinkGatekeeper\Stats\Usage;
use WinkGatekeeper\Verdict;

final class AbuseIpdbService implements IpReputationProbe {

	/**
	 * 驱动 id（缓存/退避/停转/计量键成分——iprep.driver 默认 abuseipdb）。
	 */
	public const DRIVER_ID = 'abuseipdb';

	/**
	 * 缓存键前缀（02 §2.3b：wk_ip_<driver>_<HMAC16(ip)>——与退役前的 LoginIpRep 同形同盐）。
	 */
	public const CACHE_PREFIX = 'wk_ip_';

	/**
	 * 全局退避键前缀（定稿 §3.1 步 2【O5 回写·P2-1】wk_ 前缀惯例；uninstall 清单已补录）。
	 */
	public const BACKOFF_PREFIX = 'wk_irb_';

	/**
	 * 全局退避窗口秒（定稿 §3.3：WF A2 的 60s×5 放宽）。
	 */
	public const BACKOFF_TTL = 300;

	/**
	 * check 端点（定稿 §3.1 步 6：maxAgeInDays=90）。
	 */
	public const CHECK_ENDPOINT = 'https://api.abuseipdb.com/api/v2/check';

	/**
	 * 默认负缓存 TTL 分钟（iprep.negative_cache_minutes 平移后默认 60）。
	 */
	private const DEFAULT_NEGATIVE_TTL = 60;

	/**
	 * 默认 pass 缓存 TTL 分钟（iprep.abuseipdb.cache_minutes 平移后默认 1440）。
	 */
	private const DEFAULT_PASS_TTL = 1440;

	/**
	 * @var array<string, mixed> 完整设置树（iprep/stats 子树消费）。
	 */
	private $settings;

	/**
	 * @var RemoteClient 传输脊（生产=Bootstrap 共享实例——usage 计量走其钩子）。
	 */
	private $remote;

	/**
	 * @var callable fn(string): mixed 缓存读（wk_ip_ verdict 值）。
	 */
	private $cache_get;

	/**
	 * @var callable fn(string, mixed, int): void 缓存写（只在外呼成功后）。
	 */
	private $cache_set;

	/**
	 * @var callable fn(string): mixed 退避读（wk_irb_ 键）。
	 */
	private $backoff_get;

	/**
	 * @var callable fn(string, mixed, int): void 退避写。
	 */
	private $backoff_set;

	/**
	 * @var callable fn(string): ?string 停转状态查询（DriverHealth::state_today 形态）。
	 */
	private $health_state;

	/**
	 * @var callable fn(string, string): void 停转标记（DriverHealth::mark_option 形态）。
	 */
	private $health_mark;

	/**
	 * @var callable fn(): array 用量树读（Usage::get 形态——软顶评估输入）。
	 */
	private $usage_get;

	/**
	 * @var callable fn(string): string 定键 HMAC（16 hex；生产 wp_salt('auth') 派生）。
	 */
	private $hmac;

	/**
	 * @var callable fn(): bool 已登录 manage_options 判定（行为层白名单源 ②）。
	 */
	private $is_admin_user;

	/**
	 * @var callable fn(): string[] 服务器自身 IP 集（行为层白名单源 ③）。
	 */
	private $server_ips;

	/**
	 * @var callable fn(string): bool 站长手动白名单匹配（判定层白名单源 ①——I4 UI 接线；
	 *                                I2 注入位预留默认空集恒 false）。
	 */
	private $whitelist_match;

	/**
	 * @var callable fn(): int 时钟。
	 */
	private $now;

	/**
	 * @param array<string, mixed> $settings      完整设置树（SettingsSchema::defaults() 形态）。
	 * @param RemoteClient|null    $remote        传输脊（null=缺省 WP 边界薄包装；生产传 Bootstrap 共享实例——计量钩子在脊上）。
	 * @param callable|null        $cache_get     缓存读（null = get_transient，WP 边界）。
	 * @param callable|null        $cache_set     缓存写。
	 * @param callable|null        $backoff_get   退避读。
	 * @param callable|null        $backoff_set   退避写。
	 * @param callable|null        $health_state  停转查询（null = DriverHealth::state_today）。
	 * @param callable|null        $health_mark   停转标记（null = DriverHealth::mark_option）。
	 * @param callable|null        $usage_get     用量树读（null = Usage::get）。
	 * @param callable|null        $hmac          定键 HMAC（null = wp_salt('auth') 派生，WP 边界）。
	 * @param callable|null        $is_admin_user manage_options 判定（null = current_user_can，WP 边界）。
	 * @param callable|null        $server_ips    服务器 IP 集（null = $_SERVER['SERVER_ADDR']，WP 边界）。
	 * @param callable|null        $whitelist_match 站长手动白名单（null = 空集——I4 UI 接线前无白名单存储）。
	 * @param callable|null        $now           时钟（null = time()）。
	 */
	public function __construct(
		array $settings,
		?RemoteClient $remote = null,
		?callable $cache_get = null,
		?callable $cache_set = null,
		?callable $backoff_get = null,
		?callable $backoff_set = null,
		?callable $health_state = null,
		?callable $health_mark = null,
		?callable $usage_get = null,
		?callable $hmac = null,
		?callable $is_admin_user = null,
		?callable $server_ips = null,
		?callable $whitelist_match = null,
		?callable $now = null
	) {
		$this->settings        = $settings;
		$this->remote          = $remote ?? new RemoteClient(); // WP 边界（生产由 Bootstrap 传共享实例）。
		$this->cache_get       = $cache_get ?? static function ( string $key ) {
			return get_transient( $key ); // WP 边界。
		};
		$this->cache_set       = $cache_set ?? static function ( string $key, $value, int $ttl ): void {
			set_transient( $key, $value, $ttl ); // WP 边界。
		};
		$this->backoff_get     = $backoff_get ?? static function ( string $key ) {
			return get_transient( $key ); // WP 边界。
		};
		$this->backoff_set     = $backoff_set ?? static function ( string $key, $value, int $ttl ): void {
			set_transient( $key, $value, $ttl ); // WP 边界。
		};
		$this->health_state    = $health_state ?? static function ( string $driver_id ): ?string {
			return DriverHealth::state_today( $driver_id ); // WP 边界。
		};
		$this->health_mark     = $health_mark ?? static function ( string $driver_id, string $state ): void {
			DriverHealth::mark_option( $driver_id, $state ); // WP 边界（状态迁移自动落 system/health 事件行）。
		};
		$this->usage_get       = $usage_get ?? static function (): array {
			return Usage::get(); // WP 边界。
		};
		$this->hmac            = $hmac ?? static function ( string $data ): string {
			return substr( hash_hmac( 'sha256', $data, wp_salt( 'auth' ) ), 0, 16 ); // WP 边界（与事件 ip_hash 同盐同源）。
		};
		$this->is_admin_user   = $is_admin_user ?? static function (): bool {
			return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ); // WP 边界。
		};
		$this->server_ips      = $server_ips ?? static function (): array {
			return array( (string) ( $_SERVER['SERVER_ADDR'] ?? '' ) ); // WP 边界。
		};
		$this->whitelist_match = $whitelist_match ?? static function ( string $ip ): bool {
			unset( $ip );
			return false; // 站长手动白名单存储随 Tab④ UI（I4）落位；I2 注入位默认空集。
		};
		$this->now             = $now ?? static function (): int {
			return time(); // WP 边界。
		};
	}

	/**
	 * 驱动注册元数据（05 §3.1/§4——type='ip'/billing='free_quota'/frontend=[]/
	 * supports_report=true【O5-P3-3 三字段占位】；error_map 四类由传输脊 HTTP 分类承载
	 * （02 §2.5 优先级：传输脊 HTTP 分类 > body 映射——AbuseIPDB 错误面全部经 HTTP 状态码
	 * 表达 429/401/402/契约缺字段，无 body 级错误码需 probe）。privacy 维=05 §4 合规表
	 * abuseipdb 行（cross_border=true 出境）。
	 *
	 * @return array<string, mixed>
	 */
	public static function registry_entry(): array {
		return array(
			'id'              => self::DRIVER_ID,
			'type'            => DriverRegistry::TYPE_IP,
			'class'           => self::class,
			'label'           => 'AbuseIPDB（IP 信誉查询）',
			'billing'         => 'free_quota', // 免费配额档（贡献者 5000/天——BILLINGS 枚举扩展值）。
			'frontend'        => array(), // IP 驱动无前端资产（LocalRules 先例）。
			'error_map'       => array(), // 四类失败由传输脊 HTTP 分类承载（见方法注释）。
			'api_version'     => 'abuseipdb-v2',
			'settings'        => array(
				'api_key'          => array( 'type' => 'password', 'sanitize' => 'password', 'label' => 'API Key', 'required' => true ),
				'suspect_score'    => array( 'type' => 'number', 'sanitize' => 'absint', 'label' => '疑似阈值（review）' ),
				'malicious_score'  => array( 'type' => 'number', 'sanitize' => 'absint', 'label' => '恶意阈值（reject）' ),
				'cache_minutes'    => array( 'type' => 'number', 'sanitize' => 'absint', 'label' => 'pass 缓存（分钟）' ),
				'soft_quota'       => array( 'type' => 'number', 'sanitize' => 'absint', 'label' => '软顶（次/日）' ),
				'daily_quota'      => array( 'type' => 'number', 'sanitize' => 'absint', 'label' => '日配额硬顶' ),
				'timeout'          => array( 'type' => 'number', 'sanitize' => 'absint', 'label' => '超时（秒）' ),
			),
			'supports_report' => true, // I3 report 路径能力声明（Tab④ 上报分区显隐依据）。
			'privacy'         => array(
				'cross_border' => true,
				'privacy_url'  => 'https://www.abuseipdb.com/privacy',
			),
			'milestone'       => 'M3',
		);
	}

	/**
	 * 三态探测（七步流水线；cache_only 只读模式见类注释）。
	 *
	 * @param string               $ip  访客 IP（IpResolver 解析产物；归一化在步 1）。
	 * @param array<string, mixed> $ctx cache_only=true 只读模式（预检/登录渲染期必传）。
	 * @return Verdict|null null=skip（未启用面/白名单/退避/停转/私网——闸行为零变化）；
	 *                      reject/review/error=fail 档（软顶/防呆/超阈值/外呼失败——从严）。
	 */
	public function probe( string $ip, array $ctx = array() ): ?Verdict {
		$cache_only = (bool) ( $ctx['cache_only'] ?? false );

		/* 步 0：白名单前置（最先查——行为层「永不查、永不 fail」，防呆与停转不得覆盖）。 */
		if ( $this->is_whitelisted( $ip ) ) {
			return null;
		}

		/* 步 1：规范化（防 ::ffff: 变体稀释缓存；非法形态 null skip——宁不判定不误伤）。 */
		$normalized = IpResolver::normalize( $ip );
		if ( null === $normalized || '' === $normalized ) {
			return null;
		}

		/* 步 1.5：防呆（enabled && api_key 空 → 恒 fail 从严——assemble 装配分支的服务内承载；
		 * 排白名单/规范化之后：站长本人/白名单 IP/不可解析 IP 不因漏配钥被误伤）。
		 * cache_only 同判（防呆从严——恒 fail 不因 cache_only 降级为 skip）。 */
		if ( '' === $this->api_key() ) {
			return $this->strict_fail( 'not_configured' );
		}

		if ( $cache_only ) {
			/* cache_only：缓存读 → 命中返回该 verdict（证据优先）；未命中再按退避/停转定
			 * fail 理由；软顶不评估（无外呼即无配额消耗）、全程零外呼零计量零写。 */
			$cached = $this->read_cache( $normalized );
			if ( null !== $cached ) {
				return $cached;
			}
			if ( $this->in_backoff() ) {
				return $this->strict_fail( 'backoff' );
			}
			if ( null !== $this->halt_state() ) {
				return $this->strict_fail( 'halted' );
			}
			return $this->strict_fail( 'cache_miss_strict' );
		}

		/* 完整模式：七步流水线照旧。 */

		/* 步 2：退避在位 → skip（零外呼）。 */
		if ( $this->in_backoff() ) {
			return null;
		}

		/* 步 3：当日停转（config_error/quota_exhausted）→ skip（停转语义在服务内部）。 */
		if ( null !== $this->halt_state() ) {
			return null;
		}

		/* 步 4：软顶 → fail 不外呼（从严例外——把最后配额留给缓冲，防 CC fresh-IP 洪水
		 * 打光配额后「信誉层对攻击者静默」；Usage 跨日翻转天然次日自愈）。 */
		if ( $this->soft_quota_reached() ) {
			return Verdict::reject( self::DRIVER_ID, 'soft_quota', array( 'soft_quota' ) )
				->with_meta( array( 'soft_quota' => true ) );
		}

		/* 步 5：缓存命中 → 返回缓存 verdict（meta 标 cached:true），零外呼。 */
		$cached = $this->read_cache( $normalized );
		if ( null !== $cached ) {
			return $cached;
		}

		/* 步 6：外呼 + 映射。 */
		$result = $this->remote->get( $this->check_url( $normalized ), array(
			'headers'   => array(
				'Key'    => $this->api_key(),
				'Accept' => 'application/json', // 官方要求——否则 429 可能回 HTML。
			),
			'timeout'   => $this->timeout(),
			'mode'      => 'standard',
			'usage_key' => $this->driver_id(), // RemoteClient 计量钩子 → Usage::bump_option('abuseipdb')——外呼尝试即计。
			'required'  => array( 'data.abuseConfidenceScore' ), // 缺失 → contract 哨兵（厂商改版自动可见）。
		) );

		/* 步 7：落账（映射/缓存写/失败分类处置）。 */
		return $this->settle( $result, $normalized );
	}

	/* -------------------------------------------------------------------
	 * 流水线闸件
	 * ------------------------------------------------------------------ */

	/**
	 * 步 0 白名单三源（站长手动 ∨ manage_options ∨ 服务器自身 IP）。
	 *
	 * @param string $ip 原始 IP（各源自行归一比较）。
	 * @return bool
	 */
	private function is_whitelisted( string $ip ): bool {
		if ( (bool) call_user_func( $this->is_admin_user ) ) {
			return true; // 行为层：已登录 manage_options 永不查、永不 fail。
		}

		$normalized = IpResolver::normalize( $ip );
		if ( null !== $normalized && (bool) call_user_func( $this->whitelist_match, $normalized ) ) {
			return true; // 判定层：站长手动白名单前置。
		}

		foreach ( (array) call_user_func( $this->server_ips ) as $server_ip ) {
			$server_normalized = IpResolver::normalize( is_string( $server_ip ) ? $server_ip : '' );
			if ( null !== $normalized && null !== $server_normalized && $server_normalized === $normalized ) {
				return true; // 行为层：服务器自身 IP 永不查。
			}
		}
		return false;
	}

	/**
	 * 步 2 退避读（wk_irb_<driver> transient 在位=窗口内）。
	 *
	 * @return bool
	 */
	private function in_backoff(): bool {
		$value = call_user_func( $this->backoff_get, self::BACKOFF_PREFIX . $this->driver_id() );
		return null !== $value && false !== $value;
	}

	/**
	 * 步 2 退避写（ttl 秒；Retry-After ≥300s 按其值）。
	 *
	 * @param int $ttl
	 * @return void
	 */
	private function set_backoff( int $ttl ): void {
		call_user_func(
			$this->backoff_set,
			self::BACKOFF_PREFIX . $this->driver_id(),
			call_user_func( $this->now ) + $ttl, // 值=到期时间戳（观测面）；窗口由 transient TTL 承载。
			$ttl
		);
	}

	/**
	 * 步 3 停转态（DriverHealth quota_exhausted/config_error 当日）。
	 *
	 * @return string|null
	 */
	private function halt_state(): ?string {
		$state = call_user_func( $this->health_state, $this->driver_id() );
		return is_string( $state ) && '' !== $state ? $state : null;
	}

	/**
	 * 步 4 软顶（Usage 当日 abuseipdb 计数 ≥ soft_quota）。
	 *
	 * @return bool
	 */
	private function soft_quota_reached(): bool {
		$soft_quota = (int) ( $this->abuse_settings()['soft_quota'] ?? 3000 );
		if ( $soft_quota <= 0 ) {
			return false; // 0=不限（防呆范围外容错）。
		}
		$usage = call_user_func( $this->usage_get );
		return Usage::daily_total_for( is_array( $usage ) ? $usage : array(), $this->driver_id() ) >= $soft_quota;
	}

	/* -------------------------------------------------------------------
	 * 步 5/7 缓存件（归一：缓存内化本服务）
	 * ------------------------------------------------------------------ */

	/**
	 * 缓存键（wk_ip_<driver>_<HMAC16(ip)>——与退役缓存职能前的 LoginIpRep 同形同盐同 store）。
	 *
	 * @param string $normalized 规范形 IP。
	 * @return string
	 */
	private function cache_key( string $normalized ): string {
		return self::CACHE_PREFIX . $this->driver_id() . '_' . (string) call_user_func( $this->hmac, $normalized );
	}

	/**
	 * 缓存读（命中返回重建 verdict+cached meta；损坏值 fail 从严；未命中 null）。
	 *
	 * @param string $normalized 规范形 IP。
	 * @return Verdict|null
	 */
	private function read_cache( string $normalized ): ?Verdict {
		$raw = call_user_func( $this->cache_get, $this->cache_key( $normalized ) );
		if ( null === $raw || false === $raw ) {
			return null;
		}
		if ( is_array( $raw ) && isset( $raw['v'] ) && is_string( $raw['v'] ) && in_array( $raw['v'], array( Verdict::PASS, Verdict::REVIEW, Verdict::REJECT ), true ) ) {
			$score = isset( $raw['s'] ) && is_numeric( $raw['s'] ) ? (int) $raw['s'] : null;
			$meta  = array( 'cached' => true );
			$reason = null !== $score ? 'score ' . $score . ' (cached)' : 'cached';
			if ( null !== $score ) {
				$meta['score'] = $score; // score 整数直存随缓存同出（§9）。
			}
			return $this->verdict_by_state( $raw['v'], $reason )->with_meta( $meta );
		}
		// 损坏/未知形态 → fail（fail-safe 从严——02 §2.3b 沿袭）。
		return $this->strict_fail( 'corrupt_cache' );
	}

	/**
	 * 缓存写（只在外呼成功后；pass TTL=cache_minutes、fail TTL=negative_cache_minutes）。
	 *
	 * @param string    $normalized 规范形 IP。
	 * @param Verdict   $verdict    外呼映射产物。
	 * @param int|null  $score      abuseConfidenceScore。
	 * @return void
	 */
	private function write_cache( string $normalized, Verdict $verdict, ?int $score ): void {
		$is_pass = Verdict::PASS === $verdict->verdict();
		$minutes = $is_pass
			? max( 0, (int) ( $this->abuse_settings()['cache_minutes'] ?? self::DEFAULT_PASS_TTL ) )
			: max( 0, (int) ( $this->iprep_settings()['negative_cache_minutes'] ?? self::DEFAULT_NEGATIVE_TTL ) );
		call_user_func(
			$this->cache_set,
			$this->cache_key( $normalized ),
			array( 'v' => $verdict->verdict(), 's' => $score ),
			$minutes * 60
		);
	}

	/* -------------------------------------------------------------------
	 * 步 6/7 外呼与落账
	 * ------------------------------------------------------------------ */

	/**
	 * check URL（IP rawurlencode——IPv6 冒号；定稿 §3.1 步 6 契约）。
	 *
	 * @param string $normalized 规范形 IP。
	 * @return string
	 */
	private function check_url( string $normalized ): string {
		return self::CHECK_ENDPOINT . '?ipAddress=' . rawurlencode( $normalized ) . '&maxAgeInDays=90';
	}

	/**
	 * 外呼结果落账：成功映射（isPublic=false→skip 不缓存）+ 失败分类处置（fail-open 矩阵）。
	 *
	 * @param RemoteResult $result     传输脊终态。
	 * @param string       $normalized 步 1 规范形 IP（缓存键基准——以请求 IP 为准，不信响应回显）。
	 * @return Verdict|null
	 */
	private function settle( RemoteResult $result, string $normalized ): ?Verdict {
		if ( $result->is_ok() ) {
			$data = $result->data();
			$data = is_array( $data ) && is_array( $data['data'] ?? null ) ? $data['data'] : array();

			// isPublic=false → skip：私网/内部地址不判定（不缓存不外呼重试——05 §3.1）。
			if ( array_key_exists( 'isPublic', $data ) && false === (bool) $data['isPublic'] ) {
				return null;
			}

			$score = isset( $data['abuseConfidenceScore'] ) && is_numeric( $data['abuseConfidenceScore'] )
				? max( 0, min( 100, (int) $data['abuseConfidenceScore'] ) )
				: 0;

			/* 映射（O5-P1-2 键名对齐 schema 实况）：≥ malicious → reject / ≥ suspect → review / 其余 → pass。 */
			$malicious = max( 1, (int) ( $this->abuse_settings()['malicious_score'] ?? 85 ) );
			$suspect   = max( 1, (int) ( $this->abuse_settings()['suspect_score'] ?? 60 ) );
			$verdict   = $score >= $malicious
				? Verdict::reject( self::DRIVER_ID, 'score ' . $score )
				: ( $score >= $suspect
					? Verdict::review( self::DRIVER_ID, 'score ' . $score )
					: Verdict::pass( self::DRIVER_ID, 'score ' . $score ) );

			$meta = array( 'score' => $score ); // 0–100 整数直存（O5-P3-2）。
			if ( true === ( $data['isTor'] ?? false ) ) {
				$meta['tor'] = true; // isTor 存 meta 不单独决策（05 §3.1）。
			}
			$verdict = $verdict->with_meta( $meta );

			$this->write_cache( $normalized, $verdict, $score );
			return $verdict;
		}

		/* ---- 失败分类处置（定稿 §3.2 矩阵；均不写缓存）。 ---- */
		$category = $result->category();

		if ( 429 === $result->status() ) {
			// 429：退避（Retry-After ≥300s 按其值）+ quota_exhausted 当日停转（次日自愈）。
			$retry_after = $result->retry_after();
			$ttl         = ( null !== $retry_after && $retry_after >= self::BACKOFF_TTL ) ? $retry_after : self::BACKOFF_TTL;
			$this->set_backoff( $ttl );
			call_user_func( $this->health_mark, $this->driver_id(), DriverHealth::STATE_QUOTA_EXHAUSTED );
			return $this->failure_verdict( $result, 'remote_transport', array( 'remote_transport' ) );
		}

		if ( RemoteResult::SEMANTIC === $category ) {
			// 401/402（及其它 4xx 语义错）：config_error 当日停转 + Tab① 红点语义（不落退避）。
			call_user_func( $this->health_mark, $this->driver_id(), DriverHealth::STATE_CONFIG_ERROR );
			return $this->failure_verdict( $result, 'remote_semantic', array( 'remote_semantic' ) );
		}

		if ( RemoteResult::CONTRACT === $category || RemoteResult::PARSE === $category ) {
			// 契约不符（200 但结构非预期/坏 JSON）：contract_mismatch 哨兵 + 退避（不停转）。
			$this->set_backoff( self::BACKOFF_TTL );
			return $this->failure_verdict( $result, 'contract_mismatch', array( 'contract_mismatch' ) );
		}

		// transport（超时/网络错/5xx——429 已分流）：落退避 300s + 不停转（可能是抖动）。
		$this->set_backoff( self::BACKOFF_TTL );
		return $this->failure_verdict( $result, 'remote_transport', array( 'remote_transport' ) );
	}

	/**
	 * 失败 verdict（error 档——02 §2.3b fail ③「报错」；meta 携 raw 脱敏快照）。
	 *
	 * @param RemoteResult  $result 传输脊终态。
	 * @param string        $reason remote_transport/remote_semantic/contract_mismatch。
	 * @param string[]      $hits   rule_hit 标签。
	 * @return Verdict
	 */
	private function failure_verdict( RemoteResult $result, string $reason, array $hits ): Verdict {
		$meta = array();
		$raw  = RemoteResult::raw_snapshot_from_settings( $result, $this->settings ); // stats.raw_capture/raw_max_bytes 桥。
		if ( null !== $raw ) {
			$meta['raw'] = $raw; // 失败 meta.raw 脱敏快照（512B 红线，§9/04 §3）。
		}
		return Verdict::error( self::DRIVER_ID, $reason, $hits )->with_meta( $meta );
	}

	/**
	 * cache_only/防呆/损坏缓存的 fail verdict（reject 档——fail 走消费方既有从严路径零改动）。
	 *
	 * @param string $reason cache_miss_strict|backoff|halted|corrupt_cache|not_configured。
	 * @return Verdict
	 */
	private function strict_fail( string $reason ): Verdict {
		return Verdict::reject( self::DRIVER_ID, $reason, array( $reason ) );
	}

	/**
	 * 缓存态 → verdict 重建（损坏已在上游拦；此处只接三有效态）。
	 *
	 * @param string $state  pass|review|reject。
	 * @param string $reason 理由。
	 * @return Verdict
	 */
	private function verdict_by_state( string $state, string $reason ): Verdict {
		if ( Verdict::PASS === $state ) {
			return Verdict::pass( self::DRIVER_ID, $reason );
		}
		if ( Verdict::REVIEW === $state ) {
			return Verdict::review( self::DRIVER_ID, $reason );
		}
		return Verdict::reject( self::DRIVER_ID, $reason );
	}

	/* -------------------------------------------------------------------
	 * 设置件
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string, mixed> iprep 子树。
	 */
	private function iprep_settings(): array {
		$iprep = $this->settings['iprep'] ?? null;
		return is_array( $iprep ) ? $iprep : array();
	}

	/**
	 * @return array<string, mixed> iprep.abuseipdb 子树。
	 */
	private function abuse_settings(): array {
		$abuse = $this->iprep_settings()['abuseipdb'] ?? null;
		return is_array( $abuse ) ? $abuse : array();
	}

	/**
	 * @return string 驱动 id（iprep.driver 默认 abuseipdb——缓存/退避/停转键成分）。
	 */
	private function driver_id(): string {
		$driver = $this->iprep_settings()['driver'] ?? null;
		return is_string( $driver ) && '' !== $driver ? $driver : self::DRIVER_ID;
	}

	/**
	 * @return string API Key（iprep.abuseipdb.api_key）。
	 */
	private function api_key(): string {
		$key = $this->abuse_settings()['api_key'] ?? '';
		return is_string( $key ) ? $key : '';
	}

	/**
	 * @return int 外呼超时秒（iprep.abuseipdb.timeout 默认 1——无重试短超时档由脊承载）。
	 */
	private function timeout(): int {
		$timeout = (int) ( $this->abuse_settings()['timeout'] ?? 1 );
		return $timeout > 0 ? $timeout : 1;
	}
}
