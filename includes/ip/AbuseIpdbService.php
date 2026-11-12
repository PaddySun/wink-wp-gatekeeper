<?php
/**
 * AbuseIPDB check+report 双路径服务（M3-IP 定稿 §2/§3【v1.7·M3-IP】I2 批 + §4/§7/§9 I3 批）。
 *
 * 实现 IpReputationProbe 三态语义（权威定义 02 §2.3b）；probe() 七步流水线（次序即设计；
 * 防呆闸为定稿 §2 assemble 防呆分支的服务内承载——排白名单与规范化之后，见步 1.5 注）：
 *   步 0 前       check 面总开关 iprep.enabled → null skip【I3：report-only 装配形态
 *                 （enabled=false 且 report 子树开——04 §2「report 独立于 iprep.enabled」）
 *                 时本服务仍可达（report_ip 经 instanceof 能力探测），probe 恒 null 与
 *                 Null 服务语义等价——check 面零外呼零计量】
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
 * ---------------------------------------------------------------------------
 * report 路径（【I3·定稿 §4 全节】report_ip——SupportsReportingInterface 实现，
 * 触发点消费=instanceof 能力探测 O2-P2-7）流水线（次序即设计）：
 *   白名单前置（§7 判定层——与 probe 步 0 同一判定函数复用：白名单/管理员/自身 IP
 *     永不报不计数，先于一切计数与告警）
 *   → 闸① 总开关 iprep.report.enabled（默认关——出境与隐私拍板 0.4；关=全静默零计数）
 *   → 闸④ 启用门禁：真实 IP 自检未过（IpResolver/IpDiagnostic scope 判定=Docker NAT
 *     共显类环境）→ 上报强制失效（无开关）
 *   → 规范化（不可解析 IP 静默）
 *   → 触发面开关（iprep.report.triggers.<face>——面关=完全静默不进突增计数）
 *   → 突增告警原始计数（wk_rp_spike 10min 窗；计数点=四道闸之前，含被冷却/限速丢弃者
 *     O5-P3-1；跨 20 阈值 → usage_spike 事件一次同窗去重——gate=system/stage=ip）
 *   → 触发面阈值（T2/T4：wk_rp_cnt_<face>_<ip_hash16> 24h 滚动窗达 N 才触发；T1/T3
 *     无阈值直通——T3 沿用 LoginCounters ip_threshold=10 零新增计数器）
 *   → 闸② 每 IP 冷却 wk_rp_cool_<ip_hash16> 60min（命中视为成功免重报返回 true）
 *   → 闸③ 全局小时桶 wk_rp_global 60/h（I1 TransientRateLimiter 参数化共享件）+
 *     report 独立日顶 iprep.report.daily_cap 500/日（分桶独立不占 check 4750 池——
 *     配额尽停 report 不停 check）
 *   → 发送 POST api/v2/report（RemoteClient mode='report' 2s 零重试永不阻塞；
 *     usage_key='abuseipdb_report' 分桶计量走传输脊钩子）负载=ip+categories（默认类目
 *     保底恒有一个 ID）+comment（硬前缀 [wink-wp-gatekeeper] +默认短语/自定义覆盖，
 *     占位符白名单 {event_no}/{category}/{gate} 白名单外原样保留，渲染后 255 截断）
 *   → 冷却落位（发送成败都落——丢报无损：冷却后下触发点自然重试）+ last_report_at
 *     落 usage option（§9 注释位兑现）+ 事件行（成功 pass/report_sent+meta.score；
 *     失败静默 error+分类+meta.raw——不落退避不停转，report 面失败不影响 check 面）。
 *
 * 分层：缓存/退避/停转/用量/外呼/时钟/HMAC/白名单/自检/事件记录全注入——纯逻辑零 WP
 * 依赖（生产装配 IpReputationService::assemble()，WP 边界闭包缺省；测试注入 fake——
 * 外呼次数计数器断言）。
 *
 * @package WinkGatekeeper\Ip
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Ip;

use WinkGatekeeper\Drivers\DriverRegistry;
use WinkGatekeeper\Gates\TransientRateLimiter;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Stats\DriverHealth;
use WinkGatekeeper\Stats\EventsTable;
use WinkGatekeeper\Stats\Usage;
use WinkGatekeeper\Verdict;

final class AbuseIpdbService implements IpReputationProbe, SupportsReportingInterface {

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
	 * report 端点（【I3·定稿 §4.1/§4.5】POST——负载 ip+categories+comment）。
	 */
	public const REPORT_ENDPOINT = 'https://api.abuseipdb.com/api/v2/report';

	/**
	 * report 分桶计量键（【I3·定稿 §4.3/04 §6】与 check 池完全独立——配额尽停 report 不停 check）。
	 */
	public const USAGE_REPORT_KEY = 'abuseipdb_report';

	/**
	 * 每 IP 冷却键前缀（【I3·定稿 §4.3 闸②】wk_rp_cool_<ip_hash16>，值=到期时间戳；
	 * uninstall wk_rp_ 前缀覆盖）。
	 */
	public const COOL_PREFIX = 'wk_rp_cool_';

	/**
	 * 触发面计数键前缀（【I3·定稿 §4.2】wk_rp_cnt_<触发面>_<ip_hash16> 24h 滚动窗——
	 * T2/T4 达 N 才触发；T1/T3 无阈值不落此键）。
	 */
	public const CNT_PREFIX = 'wk_rp_cnt_';

	/**
	 * 触发面计数窗口秒（24h 滚动窗 transient——每次计数续期）。
	 */
	public const CNT_WINDOW = 86400;

	/**
	 * 全局小时桶键前缀（【I3·定稿 §4.3 闸③】TransientRateLimiter 参数化实例前缀——
	 * 全站单桶 wk_rp_global_<hmac16('global')>；uninstall wk_rp_ 前缀覆盖）。
	 */
	public const GLOBAL_PREFIX = 'wk_rp_global';

	/**
	 * 突增告警计数键（【I3·定稿 §4.5/§9】wk_rp_spike——10min 窗原始触发计数；全局单键）。
	 */
	public const SPIKE_KEY = 'wk_rp_spike';

	/**
	 * 突增告警窗口秒（10min）。
	 */
	public const SPIKE_WINDOW = 600;

	/**
	 * 突增告警阈值（10min 内原始触发 >20 → usage_spike 事件——第 21 起跨阈）。
	 */
	public const SPIKE_THRESHOLD = 20;

	/**
	 * report Comment 硬前缀（【定稿 §4.5 拍板 0.8】发送前拼接不存设置——工具识别与宣传）。
	 */
	public const COMMENT_PREFIX = '[wink-wp-gatekeeper] ';

	/**
	 * report Comment 渲染后截断长度（定稿 §4.5）。
	 */
	public const COMMENT_MAX = 255;

	/**
	 * usage_spike 事件规则键（【定稿 §9】report 突增共用告警事件——gate=system/stage=ip）。
	 */
	public const RULE_USAGE_SPIKE = 'usage_spike';

	/**
	 * 默认负缓存 TTL 分钟（iprep.negative_cache_minutes 平移后默认 60）。
	 */
	private const DEFAULT_NEGATIVE_TTL = 60;

	/**
	 * 默认 pass 缓存 TTL 分钟（iprep.abuseipdb.cache_minutes 平移后默认 1440）。
	 */
	private const DEFAULT_PASS_TTL = 1440;

	/**
	 * 每触发面默认 Comment（【I3·定稿 §4.5 拍板 0.8】英文短句只述攻击形态——不含站点域名/
	 * 事件编号/命中规则词表/评论正文片段；自定义覆盖键 iprep.report.comment_<trigger> 空=用此）。
	 *
	 * @var array<string, string>
	 */
	private const DEFAULT_COMMENTS = array(
		'captcha_failed'    => 'CAPTCHA verification failed (forged or replayed ticket)',
		'review_reject'     => 'Repeated spam content submissions blocked',
		'login_attempts'    => 'Credential brute-force on WordPress login, blocked',
		'local_rules_fail'  => 'Repeated spam/blocked content submissions',
	);

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
	 * @var callable fn(int $ts): void 上报时刻落账（【I3】last_report_at 持久兜底——
	 *         生产 = Usage::mark_report_at_option，WP 边界）。
	 */
	private $usage_mark;

	/**
	 * @var callable fn(string $gate, string $stage, Verdict $verdict, array $meta): ?string
	 *         report 面事件记录（【I3】发送成败/突增告警事件——生产 = WP 边界闭包
	 *         should_record+EventsTable::insert；测试注入捕获）。
	 */
	private $report_recorder;

	/**
	 * @var callable fn(): bool 闸④ 真实 IP 自检（【I3·定稿 §4.3】Docker NAT 共显类环境
	 *         未过 → 上报强制失效无开关；生产 = real_ip_self_check($_SERVER, settings)）。
	 */
	private $real_ip_ok;

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
	 * @param callable|null        $usage_mark    上报时刻落账（【I3】null = Usage::mark_report_at_option，WP 边界）。
	 * @param callable|null        $report_recorder report 面事件记录（【I3】null = WP 边界默认闭包）。
	 * @param callable|null        $real_ip_ok    闸④ 真实 IP 自检（【I3】null = real_ip_self_check，WP 边界）。
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
		?callable $now = null,
		?callable $usage_mark = null,
		?callable $report_recorder = null,
		?callable $real_ip_ok = null
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
		/* 【I3】report 面三依赖（WP 边界缺省；测试注入）。 */
		$this->usage_mark      = $usage_mark ?? static function ( int $ts ): void {
			Usage::mark_report_at_option( $ts ); // WP 边界（last_report_at 持久兜底）。
		};
		$this->report_recorder = $report_recorder ?? static function ( string $gate, string $stage, Verdict $verdict, array $meta ) use ( $settings ): ?string {
			// WP 边界默认：写入矩阵（pass/report_sent 特例同 trust_pass 先例）+ 事件表直插；
			// ip_hash 与事件表同盐同源（meta.client_ip 经 encode_meta 落库前剥除）。
			if ( ! EventsTable::should_record( $stage, $verdict, is_array( $settings ) ? $settings : array() ) ) {
				return null;
			}
			$client_ip = isset( $meta['client_ip'] ) && is_scalar( $meta['client_ip'] ) ? (string) $meta['client_ip'] : '';
			$ip_hash   = ( '' !== $client_ip && function_exists( 'wp_salt' ) )
				? substr( hash_hmac( 'sha256', $client_ip, wp_salt( 'auth' ) ), 0, 16 )
				: '';
			$driver    = ( '' !== $verdict->driver_id() ) ? $verdict->driver_id() : 'abuseipdb';
			return EventsTable::insert( $gate, $stage, $driver, $verdict, $meta, $ip_hash );
		};
		$this->real_ip_ok      = $real_ip_ok ?? static function () use ( $settings ): bool {
			$server = isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array(); // WP 边界。
			return self::real_ip_self_check( $server, is_array( $settings ) ? $settings : array() );
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

		/* 步 0 前：check 面总开关（iprep.enabled）——【I3】report-only 装配形态（enabled=false
		 * 且 report 子树开，04 §2「report 独立于 iprep.enabled」）时本服务可达（report_ip 经
		 * instanceof 能力探测），probe 恒 null skip 与 Null 服务语义等价（check 面零外呼零计量；
		 * cache_only 同 skip——防呆在面关时不评估，无「开而不配好」面）。 */
		if ( empty( $this->iprep_settings()['enabled'] ) ) {
			return null;
		}

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
	 * 【I3·定稿 §4】report 路径（SupportsReportingInterface——触发点 instanceof 能力探测消费）
	 * ------------------------------------------------------------------ */

	/**
	 * 上报一次恶意行为（fire-and-forget——调用方不等待业务结果、失败不影响拦截流程）。
	 *
	 * 流水线次序见类头注 report 段：白名单前置 → 闸①总开关 → 闸④自检 → 规范化 →
	 * 触发面开关 → 突增原始计数 → 触发面阈值 → 闸②冷却 → 闸③全局桶+日顶 → 发送 → 落账。
	 *
	 * @param string               $ip      访客 IP（IpResolver 解析产物；服务内归一定键）。
	 * @param array<string, mixed> $context trigger/gate/event_no（渲染所需）；其余键忽略
	 *                                      （负载键白名单自证——正文/命中词绝不出站）。
	 * @return bool true=已受理（含冷却去重命中视为成功免重报）；false=未受理（静默）。
	 */
	public function report_ip( string $ip, array $context = array() ): bool {
		/* 白名单前置（§7 判定层——与 probe 步 0 同一判定函数复用：白名单/管理员/自身 IP
		 * 永不报不计数——先于一切计数与告警，误伤防护第一层）。 */
		if ( $this->is_whitelisted( $ip ) ) {
			return false;
		}

		/* 闸①：总开关 iprep.report.enabled（默认关——出境与隐私拍板 0.4；关=全静默零计数）。
		 * 密钥缺同静默（report 子系统未武装——防呆只属 check 面）。 */
		if ( ! $this->report_enabled() || '' === $this->api_key() ) {
			return false;
		}

		/* 闸④：启用门禁——真实 IP 自检未过（Docker NAT 共显类环境）→ 上报强制失效（无开关）。 */
		if ( ! (bool) call_user_func( $this->real_ip_ok ) ) {
			return false;
		}

		/* 规范化（与 probe 步 1 同零件——变体不稀释计数/冷却键；不可解析静默）。 */
		$normalized = IpResolver::normalize( $ip );
		if ( null === $normalized || '' === $normalized ) {
			return false;
		}

		/* 触发面开关（iprep.report.triggers.<face>——面关=完全静默，不进突增计数）。 */
		$trigger = isset( $context['trigger'] ) && is_string( $context['trigger'] ) ? $context['trigger'] : '';
		if ( '' === $trigger || ! $this->trigger_enabled( $trigger ) ) {
			return false;
		}

		/* 突增告警原始计数（定稿 §4.5/O5-P3-1：计数点=四道闸之前——含被冷却/限速丢弃者，
		 * 告警感知攻击面不是发送量）；跨 20 阈值 → usage_spike 事件一次（同窗闩锁去重）。 */
		$this->bump_spike();

		/* 触发面阈值（T2/T4：wk_rp_cnt_<face>_<ip_hash16> 24h 滚动窗达 N 才触发；T1/T3
		 * 无阈值直通——T3 沿用 LoginCounters ip_threshold=10 饱和点零新增计数器）。 */
		if ( ! $this->trigger_threshold_reached( $trigger, $normalized ) ) {
			return false;
		}

		/* 闸②：每 IP 冷却（wk_rp_cool_<ip_hash16>，默认 60min）——命中视为成功免重报。 */
		if ( $this->in_cooldown( $normalized ) ) {
			return true;
		}

		/* 闸③前半：全局小时桶（wk_rp_global，默认 60/h——I1 TransientRateLimiter 参数化共享件）。 */
		if ( ! $this->global_bucket_allow() ) {
			return false;
		}

		/* 闸③后半：report 独立日顶（iprep.report.daily_cap 默认 500/日——分桶独立不占
		 * check 4750 池；配额尽=停 report 不停 check）。 */
		if ( $this->report_daily_cap_reached() ) {
			return false;
		}

		/* 发送：mode='report'（2s 超时零重试永不阻塞提交——通道已建待消费）；
		 * usage_key='abuseipdb_report'（分桶计量走传输脊钩子——服务不二次 bump）。 */
		$gate     = isset( $context['gate'] ) && is_string( $context['gate'] ) && '' !== $context['gate'] ? $context['gate'] : 'comment';
		$category = $this->resolve_category( $trigger );
		$comment  = $this->render_comment( $trigger, $context, $category );

		$result = $this->remote->post(
			self::REPORT_ENDPOINT,
			array(
				'headers'   => array(
					'Key'    => $this->api_key(),
					'Accept' => 'application/json', // 官方要求（check 同款）。
				),
				'body'      => array(
					'ip'         => $normalized,
					// categories=逗号分隔整数 ID 形态（单 ID）——至少一个恒成立（每面默认类目保底）。
					'categories' => (string) $category,
					'comment'    => $comment,
				),
				'mode'      => 'report',
				'usage_key' => self::USAGE_REPORT_KEY,
				'required'  => array( 'data.ipAddress' ), // 缺失 → contract 失败分类（厂商改版自动可见）。
			)
		);

		/* 冷却落位（发送成败都落——丢报无损：冷却后下触发点自然重试，不烧配额不重复外呼）。 */
		$this->set_cooldown( $normalized );

		/* last_report_at 持久兜底（§9 Usage.php:37 注释位——按尝试落，成功失败都标）。 */
		call_user_func( $this->usage_mark, (int) call_user_func( $this->now ) );

		/* 落账事件行（成功 pass/report_sent+meta.score；失败静默 error+分类+meta.raw）。 */
		$this->record_report_event( $gate, $trigger, $category, $normalized, $result, $context );

		return $result->is_ok();
	}

	/**
	 * 闸④ 真实 IP 自检（纯函数；WP 边界缺省闭包消费）：解析当前请求 REMOTE_ADDR 的作用域，
	 * private/invalid（Docker NAT 共显类——172.18.0.1 等）=未过 → 上报强制失效；
	 * trusted（受信反代已配）与 public=过。
	 *
	 * @param array<string, mixed> $server   $_SERVER 形态。
	 * @param array<string, mixed> $settings 完整设置树（network 子树消费）。
	 * @return bool
	 */
	public static function real_ip_self_check( array $server, array $settings ): bool {
		$network = isset( $settings['network'] ) && is_array( $settings['network'] ) ? $settings['network'] : array();
		$trusted = TrustedProxies::from_settings(
			isset( $network['trusted_proxies'] ) && is_array( $network['trusted_proxies'] ) ? $network['trusted_proxies'] : array()
		);
		$remote_raw = isset( $server['REMOTE_ADDR'] ) && is_scalar( $server['REMOTE_ADDR'] ) ? trim( (string) $server['REMOTE_ADDR'] ) : '';
		if ( '' === $remote_raw ) {
			return false; // 无法判定（CLI 等）→ 门禁未过（宁可少报不误报——共享出口会污染库）。
		}
		$scope = IpDiagnostic::scope_of( $remote_raw, $trusted );
		return 'private' !== $scope && 'invalid' !== $scope;
	}

	/**
	 * 突增告警原始计数（wk_rp_spike 10min 固定窗；跨 20 阈值 → usage_spike 事件一次，
	 * gate=system/stage=ip/verdict=review——告警非故障不入故障口径；同窗闩锁去重）。
	 *
	 * @return void
	 */
	private function bump_spike(): void {
		$now    = (int) call_user_func( $this->now );
		$bucket = call_user_func( $this->cache_get, self::SPIKE_KEY );
		if ( ! is_array( $bucket ) || ! isset( $bucket['start'], $bucket['count'] )
			|| ! is_int( $bucket['start'] ) || ! is_int( $bucket['count'] )
			|| $now >= $bucket['start'] + self::SPIKE_WINDOW ) {
			$bucket = array( 'start' => $now, 'count' => 0, 'alarmed' => false ); // 窗过/损坏 → 新窗。
		}
		$bucket['count'] = $bucket['count'] + 1;

		if ( $bucket['count'] > self::SPIKE_THRESHOLD && empty( $bucket['alarmed'] ) ) {
			$bucket['alarmed'] = true; // 同窗只告警一次（去重——事件面防洪水）。
			call_user_func(
				$this->report_recorder,
				'system',
				'ip',
				Verdict::review( $this->driver_id(), self::RULE_USAGE_SPIKE, array( self::RULE_USAGE_SPIKE ) ),
				array(
					'window_start' => $bucket['start'],
					'count'        => $bucket['count'],
					'threshold'    => self::SPIKE_THRESHOLD,
					'window_sec'   => self::SPIKE_WINDOW,
				)
			);
		}

		call_user_func( $this->cache_set, self::SPIKE_KEY, $bucket, self::SPIKE_WINDOW + 60 );
	}

	/**
	 * 触发面阈值判定（T2/T4 计数：wk_rp_cnt_<face>_<ip_hash16> 24h 滚动窗，每次计数续期；
	 * 计数动作幂等=调用一次计 1——两处消费点（预检/提交）各请求恰一次经此键累计）。
	 *
	 * @param string $trigger     触发面键。
	 * @param string $normalized  规范形 IP。
	 * @return bool true=无阈值面直通，或计数已达 N。
	 */
	private function trigger_threshold_reached( string $trigger, string $normalized ): bool {
		$threshold = $this->trigger_threshold( $trigger );
		if ( $threshold <= 0 ) {
			return true; // T1/T3 无阈值——直通（T3 阈值在 LoginCounters 饱和点承载）。
		}
		$key   = self::CNT_PREFIX . $trigger . '_' . (string) call_user_func( $this->hmac, $normalized );
		$raw   = call_user_func( $this->cache_get, $key );
		$count = ( is_int( $raw ) || is_numeric( $raw ) ) ? (int) $raw : 0;
		$count++;
		call_user_func( $this->cache_set, $key, $count, self::CNT_WINDOW );
		return $count >= $threshold;
	}

	/**
	 * 触发面阈值读数（triggers.<face>_threshold 子键默认 5；无子键面=0 直通）。
	 *
	 * @param string $trigger 触发面键。
	 * @return int 0=无阈值面。
	 */
	private function trigger_threshold( string $trigger ): int {
		$triggers = $this->report_settings()['triggers'] ?? null;
		if ( ! is_array( $triggers ) ) {
			return 0;
		}
		$key = $trigger . '_threshold';
		if ( array_key_exists( $key, $triggers ) && is_numeric( $triggers[ $key ] ) ) {
			return max( 1, (int) $triggers[ $key ] );
		}
		return 0;
	}

	/**
	 * 触发面开关读数（iprep.report.triggers.<face>）。
	 *
	 * @param string $trigger 触发面键。
	 * @return bool
	 */
	private function trigger_enabled( string $trigger ): bool {
		$triggers = $this->report_settings()['triggers'] ?? null;
		return is_array( $triggers ) && ! empty( $triggers[ $trigger ] );
	}

	/**
	 * 每 IP 冷却读（wk_rp_cool_<ip_hash16> 值=到期时间戳；未过期=冷却中）。
	 *
	 * @param string $normalized 规范形 IP。
	 * @return bool
	 */
	private function in_cooldown( string $normalized ): bool {
		$raw = call_user_func( $this->cache_get, self::COOL_PREFIX . (string) call_user_func( $this->hmac, $normalized ) );
		if ( ! is_int( $raw ) && ! is_numeric( $raw ) ) {
			return false;
		}
		return (int) $raw > (int) call_user_func( $this->now );
	}

	/**
	 * 每 IP 冷却写（发送成败都落——丢报无损；值=到期时间戳，TTL=窗口承载）。
	 *
	 * @param string $normalized 规范形 IP。
	 * @return void
	 */
	private function set_cooldown( string $normalized ): void {
		$minutes = max( 1, (int) ( $this->report_settings()['cooldown_minutes'] ?? 60 ) );
		call_user_func(
			$this->cache_set,
			self::COOL_PREFIX . (string) call_user_func( $this->hmac, $normalized ),
			(int) call_user_func( $this->now ) + $minutes * 60,
			$minutes * 60
		);
	}

	/**
	 * 闸③前半：全局小时桶（TransientRateLimiter 参数化实例——I1 共享件；常量键材 'global'
	 * =全站单桶 wk_rp_global_<hmac16('global')>）。
	 *
	 * @return bool
	 */
	private function global_bucket_allow(): bool {
		$limit = (int) ( $this->report_settings()['hourly_limit'] ?? 60 );
		if ( $limit <= 0 ) {
			return true; // 0=不限（容错档）。
		}
		$limiter = new TransientRateLimiter(
			$limit,
			TransientRateLimiter::DEFAULT_WINDOW,
			$this->cache_get,
			$this->cache_set,
			$this->hmac,
			self::GLOBAL_PREFIX
		);
		return $limiter->allow( 'global' );
	}

	/**
	 * 闸③后半：report 独立日顶（Usage 当日 abuseipdb_report 计数 ≥ daily_cap——分桶独立，
	 * 配额尽停 report 不停 check）。
	 *
	 * @return bool
	 */
	private function report_daily_cap_reached(): bool {
		$cap = (int) ( $this->report_settings()['daily_cap'] ?? 500 );
		if ( $cap <= 0 ) {
			return false; // 0=不限（容错档）。
		}
		$usage = call_user_func( $this->usage_get );
		return Usage::daily_total_for( is_array( $usage ) ? $usage : array(), self::USAGE_REPORT_KEY ) >= $cap;
	}

	/**
	 * 类目解析（每面覆盖键 iprep.report.category_<trigger>：0=该面默认类目；非法码回落默认
	 * ——categories 必填至少一个 ID 恒成立保底）。
	 *
	 * @param string $trigger 触发面键。
	 * @return int 类目 ID（1–23）。
	 */
	private function resolve_category( string $trigger ): int {
		$override = $this->report_settings()[ 'category_' . $trigger ] ?? 0;
		$override = is_numeric( $override ) ? (int) $override : 0;
		if ( $override > 0 && AbuseIpdbCategories::is_valid( $override ) ) {
			return $override;
		}
		return AbuseIpdbCategories::default_for( $trigger );
	}

	/**
	 * Comment 渲染（定稿 §4.5 拍板 0.8）：自定义覆盖键 iprep.report.comment_<trigger>
	 * （空=该面默认短语）→ 占位符白名单 {event_no}/{category}/{gate} 渲染（白名单外原样
	 * 保留不解析——strtr 白名单三键外零触碰）→ 硬前缀发送前拼接（不存设置）→ 255 截断。
	 *
	 * 红线自证：本方法只消费 trigger/context 三标量与设置树——评论正文片段/LocalRules 命中
	 * 词/词表内容/站点域名无进入路径（默认短语已保证；自定义内容站长自负——Tab④ I4 提示）。
	 *
	 * @param string               $trigger  触发面键。
	 * @param array<string, mixed> $context  触发上下文（event_no/gate）。
	 * @param int                  $category 已解析类目 ID（{category} 占位符值）。
	 * @return string
	 */
	private function render_comment( string $trigger, array $context, int $category ): string {
		$custom   = $this->report_settings()[ 'comment_' . $trigger ] ?? '';
		$template = is_string( $custom ) && '' !== trim( $custom )
			? $custom
			: ( self::DEFAULT_COMMENTS[ $trigger ] ?? '' );

		$rendered = strtr(
			$template,
			array(
				'{event_no}' => isset( $context['event_no'] ) && is_scalar( $context['event_no'] ) ? (string) $context['event_no'] : '',
				'{category}' => (string) $category,
				'{gate}'     => isset( $context['gate'] ) && is_scalar( $context['gate'] ) ? (string) $context['gate'] : '',
			)
		);

		return mb_substr( self::COMMENT_PREFIX . $rendered, 0, self::COMMENT_MAX );
	}

	/**
	 * report 事件落账（gate=触发域 comment|login / stage='report' / driver=iprep.driver）：
	 * 成功=pass + hits=report_sent（写入矩阵 pass 特例——审计足迹）+ meta.score（上报后新
	 * 分值 0–100 整数直存 O5-P3-2）；失败=error + 分类标签 + meta.raw 脱敏快照（静默——
	 * 不落退避不停转：report 面失败不影响 check 面判定件，冷却已承载重试节奏）。
	 *
	 * @param string       $gate       触发域（comment|login）。
	 * @param string       $trigger    触发面键。
	 * @param int          $category   已发送类目 ID。
	 * @param string       $normalized 规范形 IP（meta.client_ip 经 encode_meta 落库前剥除）。
	 * @param RemoteResult $result     传输脊终态。
	 * @param array<string, mixed> $context 触发上下文（event_no 对账位）。
	 * @return void
	 */
	private function record_report_event( string $gate, string $trigger, int $category, string $normalized, RemoteResult $result, array $context ): void {
		$base_meta = array(
			'trigger'   => $trigger,
			'category'  => $category,
			'client_ip' => $normalized,
		);
		$source_event_no = isset( $context['event_no'] ) && is_string( $context['event_no'] ) && '' !== $context['event_no']
			? $context['event_no'] : null;
		if ( null !== $source_event_no ) {
			$base_meta['source_event_no'] = $source_event_no; // 触发事件编号（对账位——默认短语不夹带）。
		}

		if ( $result->is_ok() ) {
			$data = $result->data();
			$data = is_array( $data ) && is_array( $data['data'] ?? null ) ? $data['data'] : array();
			$score = isset( $data['abuseConfidenceScore'] ) && is_numeric( $data['abuseConfidenceScore'] )
				? max( 0, min( 100, (int) $data['abuseConfidenceScore'] ) )
				: 0;
			call_user_func(
				$this->report_recorder,
				$gate,
				'report',
				Verdict::pass( $this->driver_id(), 'report_sent', array( 'report_sent' ) ),
				$base_meta + array( 'score' => $score )
			);
			return;
		}

		/* 失败分类（定稿 §3.2 同名四类；report 面 429 归 transport——不停转不退避）。 */
		$reason = 'remote_transport';
		$hits   = array( 'remote_transport' );
		if ( RemoteResult::SEMANTIC === $result->category() ) {
			$reason = 'remote_semantic';
			$hits   = array( 'remote_semantic' );
		} elseif ( RemoteResult::CONTRACT === $result->category() || RemoteResult::PARSE === $result->category() ) {
			$reason = 'contract_mismatch';
			$hits   = array( 'contract_mismatch' );
		}

		$meta = $base_meta;
		$raw  = RemoteResult::raw_snapshot_from_settings( $result, $this->settings ); // stats.raw_capture/raw_max_bytes 桥。
		if ( null !== $raw ) {
			$meta['raw'] = $raw;
		}
		call_user_func( $this->report_recorder, $gate, 'report', Verdict::error( $this->driver_id(), $reason, $hits ), $meta );
	}

	/* -------------------------------------------------------------------
	 * report 设置件
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string, mixed> iprep.report 子树。
	 */
	private function report_settings(): array {
		$report = $this->iprep_settings()['report'] ?? null;
		return is_array( $report ) ? $report : array();
	}

	/**
	 * 闸① 总开关读数（iprep.report.enabled——默认关）。
	 *
	 * @return bool
	 */
	private function report_enabled(): bool {
		return (bool) ( $this->report_settings()['enabled'] ?? false );
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
