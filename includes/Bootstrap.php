<?php
/**
 * 主控启动器（WP 边界层，02 §4 启动时序）。
 *
 * M1 范围：设置页挂载（admin_menu/admin_init）、品牌资产、文本域、每日维护 cron 之外，
 * 增加闸/驱动装配：驱动注册表注册（tencent + local_rules）→ 传输脊与驱动实例构造 →
 * CommentGate 装配（评论验证钩子 + REST 封堵 + 前台资产）→ 统计重置 AJAX。
 *
 * 启动时序（02 §4）：普通时序即可——唯一必须早的是验证钩子本身
 * （preprocess_comment @ -PHP_INT_MAX，由闸的 verify_hooks 声明、此处按声明装配）。
 *
 * @package WinkGatekeeper
 */

declare( strict_types=1 );

namespace WinkGatekeeper;

use WinkGatekeeper\Drivers\captcha\AliyunCaptchaDriver;
use WinkGatekeeper\Drivers\captcha\TencentCaptchaDriver;
use WinkGatekeeper\Drivers\DriverRegistry;
use WinkGatekeeper\Drivers\Review\AliyunGreenPlusDriver;
use WinkGatekeeper\Drivers\Review\BaiduTextModerationDriver;
use WinkGatekeeper\Drivers\Review\LocalRulesDriver;
use WinkGatekeeper\Drivers\Review\TencentTmsDriver;
use WinkGatekeeper\Gates\CommentGate;
use WinkGatekeeper\Gates\CommentTrust;
use WinkGatekeeper\Gates\DegradedPassLimiter;
use WinkGatekeeper\Gates\LoginGate;
use WinkGatekeeper\Gates\LoginIpRep;
use WinkGatekeeper\Gates\LostPasswordGate;
use WinkGatekeeper\Gates\Precheck;
use WinkGatekeeper\Gates\RegisterGate;
use WinkGatekeeper\Gates\TestMode;
use WinkGatekeeper\Gates\VerifyCache;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Ip\IpReputationService;
use WinkGatekeeper\Settings\SettingsPage;
use WinkGatekeeper\Settings\SettingsSchema;
use WinkGatekeeper\Stats\DriverHealth;
use WinkGatekeeper\Stats\EventsTable;
use WinkGatekeeper\Stats\StatsCache;
use WinkGatekeeper\Stats\Usage;
use WinkGatekeeper\Verdict;

final class Bootstrap {

	/**
	 * @var Bootstrap|null
	 */
	private static $instance = null;

	/**
	 * 插件根目录（含尾部分隔符）。
	 *
	 * @var string
	 */
	private $base_dir;

	/**
	 * 插件根 URL（含尾部斜杠）。
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * 设置页钩子后缀（add_options_page 返回值），入队判定用。
	 *
	 * @var string|null
	 */
	private $settings_page_hook;

	/**
	 * 评论闸实例（M1 装配产物）。
	 *
	 * @var CommentGate|null
	 */
	private $comment_gate;

	/**
	 * 登录闸实例（C3 装配产物；与评论闸独立启停）。
	 *
	 * @var LoginGate|null
	 */
	private $login_gate;

	/**
	 * 注册闸实例（【v1.4·R3/F41】常显；wp-login 注册流）。
	 *
	 * @var RegisterGate|null
	 */
	private $register_gate;

	/**
	 * 找回密码闸实例（【v1.4·R3/F41】常显；防枚举）。
	 *
	 * @var LostPasswordGate|null
	 */
	private $lostpassword_gate;

	/**
	 * 预检端点纯逻辑实例（【v1.4·R2】T4——评论闸+验证码驱动+trust 三条件齐备时装配并
	 * 注册 admin-ajax 端点；null=端点不注册，03 §2.1「端点启用条件」）。
	 *
	 * @var Precheck|null
	 */
	private $precheck;

	/**
	 * 预检前端内联配置（enqueue 期注入 wkGatePrecheck：url/nonce/超时/TTL 毫秒；
	 * null=不注入——此时容器也不带 data-wk-trust，前端零行为变化）。
	 *
	 * @var array<string, mixed>|null
	 */
	private $precheck_config;

	private function __construct( string $base_dir, string $base_url ) {
		$this->base_dir = $base_dir;
		$this->base_url = $base_url;
	}

	/**
	 * @param string $base_dir 插件根目录。
	 * @param string $base_url 插件根 URL。
	 * @return Bootstrap
	 */
	public static function instance( string $base_dir = '', string $base_url = '' ): Bootstrap {
		if ( null === self::$instance ) {
			self::$instance = new self( $base_dir, $base_url );
		}
		return self::$instance;
	}

	/**
	 * 启动（02 §4）。
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( SettingsPage::class, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// 每日维护 cron（04 §3：90 天清理 + 滚动上限）。
		add_action( EventsTable::HOOK_DAILY, array( EventsTable::class, 'daily_maintenance' ) );

		// 【v1.5·C1】test_mode 管理员提醒双位之二：admin-bar 黄条（02 §2.8 安全门 #3——
		// 仅 manage_options 用户可见，前台+后台常显防遗忘；Tab① 红点行在 SettingsPage）。
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_test_mode' ), 100 );

		// M1：闸/驱动装配 + 前台资产 + AJAX（03 §6 后台操作安全约定）。
		add_action( 'init', array( $this, 'assemble_gates' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_assets' ) );
		add_action( 'wp_ajax_' . SettingsPage::AJAX_RESET_STATS, array( SettingsPage::class, 'ajax_reset_stats' ) );
		// Tab②「测试凭据」（C1：nonce + manage_options 双闸，结果不落库）。
		add_action( 'wp_ajax_' . SettingsPage::AJAX_TEST_CREDENTIALS, array( SettingsPage::class, 'ajax_test_credentials' ) );
		// Tab③ TMS「测试凭据」（C4：同双闸；绿态解除 tencent_tms 当日停转）。
		add_action( 'wp_ajax_' . SettingsPage::AJAX_TEST_CREDENTIALS_TMS, array( SettingsPage::class, 'ajax_test_credentials_tms' ) );
		// Tab② 阿里「测试凭据」（B3：同双闸；绿态判定 = 假参实调 VerifyCode=F002/F003，05 §1.2）。
		add_action( 'wp_ajax_' . SettingsPage::AJAX_TEST_CREDENTIALS_ALIYUN, array( SettingsPage::class, 'ajax_test_credentials_aliyun' ) );
		// Tab③ 阿里 PLUS「测试凭据」（B5：同双闸；绿态 = 探测 Code=200，顺带解除 aliyun_green 停转——05 §2.5）。
		add_action( 'wp_ajax_' . SettingsPage::AJAX_TEST_CREDENTIALS_ALIYUN_GREEN, array( SettingsPage::class, 'ajax_test_credentials_aliyun_green' ) );
		// Tab③ 百度「测试凭据」（B6：同双闸；绿态 = 探测 conclusionType=1，顺带解除 baidu 停转——05 §2.4）。
		add_action( 'wp_ajax_' . SettingsPage::AJAX_TEST_CREDENTIALS_BAIDU, array( SettingsPage::class, 'ajax_test_credentials_baidu' ) );
	}

	/**
	 * 闸/驱动装配（init 早段：验证钩子在首个提交触发前就绪即可）。
	 *
	 * C3 重构：驱动/传输脊构造上移到评论闸启停分支之前——评论/登录两闸独立启停、
	 * 共享同一验证码驱动实例（凭据/容灾限速器/传输脊计量一致）。
	 *
	 * @return void
	 */
	public function assemble_gates(): void {
		$stored   = get_option( SettingsPage::OPTION_NAME, array() );
		$settings = SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() );

		/* 【v1.5·C1】test_mode 懒过期收口（02 §2.8 门 #4——无 cron，消费点读时校验）：
		 * 开关 on 且超 24h → 清键 + 落 test_mode_expired 事件 + 写回 option；返回修正树供
		 * 全部闸/预检/提醒位消费（单消费点收口，闸内读时判定只作双保险）。 */
		$settings = TestMode::enforce( $settings );

		/* 【v1.5·C1】pass_limited 降级放行限流器（02 §2.1 DegradedPassLimiter 共享件——
		 * 默认 5 次/IP/h；天御 disaster 与 captcha errorPolicy pass_limited 两个消费方共用
		 * 同一 TokenBucket 形态；errorPolicy 默认 block 时零消费零 transient 写）。 */
		$degraded_allow = new DegradedPassLimiter(
			DegradedPassLimiter::DEFAULT_LIMIT,
			DegradedPassLimiter::DEFAULT_WINDOW,
			'get_transient',
			static function ( string $key, $value, int $ttl ): void {
				set_transient( $key, $value, $ttl ); // WP 边界。
			},
			static function ( string $data ): string {
				return hash_hmac( 'sha256', $data, wp_salt( 'auth' ) ); // 与 VerifyCache/事件 ip_hash 同盐同源。
			}
		);
		$degraded_allow = array( $degraded_allow, 'allow' );

		// 驱动注册表（05 §4 六维元数据；新驱动 = 新文件 + 注册一行）。
		$registry = new DriverRegistry();
		$registry->register( TencentCaptchaDriver::registry_entry() );
		$registry->register( AliyunCaptchaDriver::registry_entry() ); // B3：阿里云验证码 2.0（05 §1.2）。
		$registry->register( LocalRulesDriver::registry_entry() );
		$registry->register( TencentTmsDriver::registry_entry() ); // C4：腾讯 TMS 文本审核。
		$registry->register( AliyunGreenPlusDriver::registry_entry() ); // B5：阿里文本审核增强版 PLUS（05 §2.5）。
		$registry->register( BaiduTextModerationDriver::registry_entry() ); // B6：百度文本审核（05 §2.4——token 30 天缓存复用）。
		SettingsPage::set_driver_registry( $registry );

		// 传输脊（全库唯一 HTTP 出口；计量钩子接用量计数——04 §6）。
		$client = new RemoteClient( null, array( Usage::class, 'bump_option' ) );

		/* 云端审核链装配（C4，02 §2.2 串联语义）：启用 + chain 内已配置的驱动才构造；
		 * LocalRules 恒首不入此链（评论闸内自建）。未配置/未实装的 chain 条目静默剔除
		 * （默认 deepseek 为 M3 驱动——不构成「云端全故障」收口判定面）。 */
		$cloud_chain = array();
		if ( ! empty( $settings['review']['cloud']['enabled'] ) ) {
			$chain = is_array( $settings['review']['cloud']['chain'] ?? null ) ? $settings['review']['cloud']['chain'] : array();
			foreach ( $chain as $chain_id ) {
				if ( 'tencent_tms' === $chain_id ) {
					$tms_driver = new TencentTmsDriver( $settings, $client );
					if ( $tms_driver->is_configured() ) {
						$cloud_chain['tencent_tms'] = $tms_driver;
					}
				} elseif ( 'aliyun_green' === $chain_id ) {
					// B5：阿里文本审核增强版 PLUS（05 §2.5）——双 Service 分送在驱动内消费
					// （02 §2.2 例外条款：闸仍传拼接串+meta 原值，分送是驱动内策略）。
					$green_driver = new AliyunGreenPlusDriver( $settings, $client );
					if ( $green_driver->is_configured() ) {
						$cloud_chain['aliyun_green'] = $green_driver;
					}
				} elseif ( 'baidu' === $chain_id ) {
					// B6：百度文本审核（05 §2.4）——拼接串口径含邮箱（百度无阿里 pt_to_contact
					// 邮箱误杀问题，与 TMS/LLM 驱动同口径）；token 30 天 transient 缓存复用
					// （WP 边界闭包注入，键含凭据指纹）。
					$baidu_driver = new BaiduTextModerationDriver( $settings, $client );
					if ( $baidu_driver->is_configured() ) {
						$cloud_chain['baidu'] = $baidu_driver;
					}
				}
			}
		}

		/* 停转闭包（C4，02 §5 快速失败）：
		 *   halt_check——DriverHealth 当日停转（semantic/配额）+ 配额达限即时判定（达限即停转持久化，
		 *               当日后续提交零调用）；
		 *   halt_mark ——云端驱动 semantic 终态 → 当日停转（防每次提交空转注定失败的调用）。 */
		$halt_check = static function ( string $driver_id ) use ( $settings ): ?string {
			$state = DriverHealth::state_today( $driver_id );
			if ( null !== $state ) {
				return $state;
			}
			$quota = (int) ( $settings['review']['cloud'][ $driver_id ]['daily_quota'] ?? 0 );
			if ( $quota > 0 && Usage::quota_reached( Usage::get(), $driver_id, $quota ) ) {
				DriverHealth::mark_option( $driver_id, DriverHealth::STATE_QUOTA_EXHAUSTED );
				return DriverHealth::STATE_QUOTA_EXHAUSTED;
			}
			return null;
		};
		$halt_mark = static function ( string $driver_id ): void {
			DriverHealth::mark_option( $driver_id, DriverHealth::STATE_CONFIG_ERROR );
		};

		// 验证码驱动：选中且配置齐全才装配（02 §2.1 未配置行为：闸整体跳过 captcha 阶段）。
		$captcha = null;
		if ( 'tencent' === ( $settings['captcha']['driver'] ?? null ) ) {
			// 容灾限速器注入（C1：每 IP 每小时 N=5 次容灾放行，transient 计数桶）。
			$tencent = new TencentCaptchaDriver( $settings, $client, array( TencentCaptchaDriver::class, 'wp_disaster_limiter' ) );
			if ( $tencent->is_configured() ) {
				$captcha = $tencent;
			}
		} elseif ( 'aliyun' === ( $settings['captcha']['driver'] ?? null ) ) {
			// B3：阿里云验证码 2.0（05 §1.2）——无容灾限速面（disasterPolicy 恒 none）。
			$aliyun = new AliyunCaptchaDriver( $settings, $client );
			if ( $aliyun->is_configured() ) {
				$captcha = $aliyun;
			}
		}

		/* 验证短缓存（M2 反馈2 T3，D+A-lite）：transient 存取闭包注入（键 wk_cv_*，TTL 由
		 * captcha.verify_cache_minutes 决定，0=停用）；评论/登录两闸共享同一实例。 */
		$verify_cache = new VerifyCache(
			(int) ( $settings['captcha']['verify_cache_minutes'] ?? VerifyCache::DEFAULT_TTL_MINUTES ),
			'get_transient',
			static function ( string $key, $value, int $ttl ): void {
				set_transient( $key, $value, $ttl ); // WP 边界。
			}
		);

		/* IP 信誉三态服务（02 §2.3b/05 §3.0——【v1.4·R2】M2.5：IpReputationService::assemble
		 * 默认 Null 实现=恒 skip=现行行为；M3 AbuseIPDB 驱动接入同一接口后三处消费方零改动）。 */
		$iprep = IpReputationService::assemble( $settings );

		/* ---------- 评论闸（M1；仅启用时挂钩，关闭 = 零 DOM 变化零验证） ---------- */
		if ( ! empty( $settings['gates']['comment']['enabled'] ) ) {
			// 审核驱动：LocalRules 四闸 + WP disallowed_keys 联动（取词回调注入）。
			$review = new LocalRulesDriver(
				isset( $settings['review']['local'] ) && is_array( $settings['review']['local'] ) ? $settings['review']['local'] : array(),
				static function (): array {
					$keys = get_option( 'disallowed_keys', '' ); // WP 讨论设置关键词（每行一条，可含正则）。
					return is_string( $keys ) && '' !== $keys ? preg_split( '/\r\n|\r|\n/', $keys ) : array();
				}
			);

			$this->comment_gate = new CommentGate(
				$settings,
				$captcha,
				$review,
				null,  // recorder：WP 边界默认（EventsTable + StatsCache）。
				null,  // resolver：按 network 设置直建。
				null,  // stub_hash：wp_hash 封装。
				null,  // now：time()。
				null,  // context_provider：get_the_ID()。
				// 拦截留档器（02 §3.1 v1.3，F37/D15）：wp_insert_comment 直插 spam——
				// 不经 wp_new_comment（后者重入 preprocess_comment 链 = 递归再拦/双份事件）；
				// 直插不跑过滤器/动作（含 Akismet、洪水检查）、不发通知（spam 语义已定）。
				// commentmeta 标记 wink_gatekeeper_archive 供 Tab⑥ 黄条计数（本插件留档 vs 其它来源 spam）。
				static function ( array $comment ): ?int {
					$id = wp_insert_comment( $comment ); // WP 边界。
					if ( is_numeric( $id ) && (int) $id > 0 ) {
						add_comment_meta( (int) $id, 'wink_gatekeeper_archive', '1' );
						return (int) $id;
					}
					return null;
				},
				$cloud_chain,  // C4：云端 failover 链（已配置驱动；LocalRules 恒首在闸内）。
				$halt_check,   // C4：停转检查（DriverHealth + 配额）。
				$halt_mark,    // C4：semantic 终态 → 当日停转。
				$verify_cache, // T3：验证短缓存（核验成功窗口内免重复验票）。
				$iprep,        // 【v1.4·R2】IP 信誉三态（步骤 2 消费；Null=skip=现行行为）。
				null,          // trust_mac：wp_salt('auth') 默认（WP 边界）。
					// 【v1.5·F4-3】驱动元数据提供器：证据行「命中规则」人类可读化的驱动人类名来源
					// （registry type+label 派生）；label_overrides 经 wink_gatekeeper_error_messages
					// filter 覆盖文案（映射键名 wk-label-<Label> 本身不可改——03 §3 v1.5）。
					static function ( string $driver_id ) use ( $registry ): ?array {
						$driver = $registry->get( $driver_id );
						if ( null === $driver || ! is_string( $driver['label'] ?? null ) || '' === $driver['label'] ) {
							return null;
						}
						return array(
							'type'            => (string) $driver['type'],
							'label'           => (string) $driver['label'],
							'label_overrides' => apply_filters( 'wink_gatekeeper_error_messages', array() ), // WP 边界。
						);
					},
					// 【v1.5·C1】pass_limited 降级放行限流器（errorPolicy 默认 block=零消费）。
					$degraded_allow
				);

			// 验证钩子按闸声明装配（preprocess_comment @ -PHP_INT_MAX——A3：比一切评论插件早）。
			foreach ( $this->comment_gate->verify_hooks() as $hook => $priority ) {
				add_filter( $hook, array( $this->comment_gate, 'hook_preprocess_comment' ), $priority, 1 );
			}

			/* M2 反馈2 T2：WP 核心评论拒绝品牌化接管（重复评论品牌页 + 过快文案）——
			 * 挂载于我方验证/渲染链之后的核心流程（wp_allow_comment / wp_check_comment_flood，
			 * 即 preprocess 通过后的 wp_new_comment 内部），不改变核心判定只换呈现与留事件。 */
			add_action( 'comment_duplicate_trigger', array( $this->comment_gate, 'hook_comment_duplicate_trigger' ), 10, 1 );
			add_filter( 'comment_duplicate_message', array( $this->comment_gate, 'filter_comment_duplicate_message' ) );
			add_action( 'comment_flood_trigger', array( $this->comment_gate, 'hook_comment_flood_trigger' ), 10, 2 );
			add_filter( 'comment_flood_message', array( $this->comment_gate, 'filter_comment_flood_message' ) );
			// review 判定 → 入待审（C4，02 §3.1 D8）：WP 7.x 在 preprocess_comment 后经
			// wp_allow_comment 重算 comment_approved——pre_comment_approved 是 canonical 通道
			// （Akismet 同款；真机发现 2026-11-10，CommentGate::filter_pre_comment_approved 注释）。
			add_filter( 'pre_comment_approved', array( $this->comment_gate, 'filter_pre_comment_approved' ), PHP_INT_MAX, 2 );
			// REST 评论端同步封堵（02 §3.1：同编排 + $_POST 隔离；拦截走 WP_Error JSON）。
			add_filter( 'rest_preprocess_comment', array( $this->comment_gate, 'hook_rest_preprocess_comment' ), 10, 1 );
			// 渲染钩子（comment_form_submit_field @ 10）。
			foreach ( $this->comment_gate->render_hooks() as $hook => $priority ) {
				add_filter( $hook, array( $this->comment_gate, 'render_submit_field' ), $priority, 1 );
			}

			/* ---------- 信用免验预检端点（【v1.4·R2】T4——03 §2.1 端点启用条件：
			 * gates.comment.enabled（本分支）∧ 验证码驱动已配置 ∧ captcha.trust.enabled——
			 * 任一不满足则端点不注册，容器也不带 data-wk-trust=零行为变化） ---------- */
			$trust_settings = isset( $settings['captcha']['trust'] ) && is_array( $settings['captcha']['trust'] )
				? $settings['captcha']['trust']
				: array();
			$trust_enabled  = ! array_key_exists( 'enabled', $trust_settings ) || (bool) $trust_settings['enabled'];

			if ( null !== $captcha && $trust_enabled ) {
				$mac = static function ( string $data ): string {
					return hash_hmac( 'sha256', $data, wp_salt( 'auth' ) ); // 与 VerifyCache/事件 ip_hash 同盐同源。
				};

				/* 历史信用（T2）：计数器=$wpdb LIMIT-N 子查询；缓存=transient wk_tc_*（布尔线形态）；
				 * filter 桥=wink_gatekeeper_trust_count_path（默认邮箱索引等价路径）。 */
				$comment_trust = new CommentTrust(
					$trust_settings,
					self::trust_counter(),
					'get_transient',
					static function ( string $key, $value, int $ttl ): void {
						set_transient( $key, $value, $ttl ); // WP 边界。
					},
					'delete_transient',
					$mac,
					null,
					'apply_filters'
				);

				/* 限流桶（wk_pk_<HMAC16(ip)> 小时桶：桶值 {h:小时序号, n:计数}，跨小时自然翻转；
				 * TTL 2h 兜底回收；0=不限流——R2 §7 默认 30/h 封脚本探测）。 */
				$hourly_limit = max( 0, (int) ( $trust_settings['precheck_hourly_limit'] ?? 30 ) );
				$rate_allow   = static function ( string $client_ip ) use ( $hourly_limit, $mac ): bool {
					if ( $hourly_limit <= 0 ) {
						return true;
					}
					$key   = Precheck::rate_key( $client_ip, $mac );
					$hour  = (int) floor( time() / 3600 );
					$bucket = get_transient( $key );
					if ( ! is_array( $bucket ) || (int) ( $bucket['h'] ?? -1 ) !== $hour ) {
						$bucket = array( 'h' => $hour, 'n' => 0 );
					}
					$bucket['n'] = (int) $bucket['n'] + 1;
					set_transient( $key, $bucket, 7200 ); // TTL 2h 兜底回收（桶值自带小时翻转）。
					return $bucket['n'] <= $hourly_limit;
				};

				/* 预检事件记录器（与评论闸默认记录器同形态：写入矩阵 should_record 收口 + 统计计数）。 */
				$precheck_recorder = static function ( string $stage, Verdict $verdict, array $meta ) use ( $settings ): ?string {
					$driver_id = ( '' !== $verdict->driver_id() ) ? $verdict->driver_id() : 'local_rules';
					$event_no  = null;
					if ( EventsTable::should_record( $stage, $verdict, $settings ) ) {
						$event_no = EventsTable::insert( 'comment', $stage, $driver_id, $verdict, $meta, CommentGate::ip_hash( (string) ( $meta['client_ip'] ?? '' ) ) );
					}
					StatsCache::record( 'comment', $driver_id, $verdict->verdict() );
					return $event_no;
				};

				$this->precheck = new Precheck(
					$settings,
					$review,                                            // 与提交期同一 LocalRules 实例（02 §3.1 步骤 2.5）。
					array( $this->comment_gate, 'assemble_review_content' ), // 同一拼装函数——两时点纯逻辑字面一致。
					$comment_trust,
					$iprep,
					$rate_allow,
					$precheck_recorder,
					static function ( string $data ): string {
						return wp_hash( $data, 'nonce' ); // 存根哈希（Stub/TrustStub 共用摘要通道）。
					},
					static function ( string $data ): string {
						return wp_hash( $data, 'nonce' ); // 信任凭证哈希（02 §3.3a「复用 wp_hash('nonce')」）。
					},
					$mac
				);

				$this->precheck_config = array(
					'url'       => admin_url( 'admin-ajax.php' ),
					'action'    => Precheck::ACTION,
					'nonce'     => wp_create_nonce( Precheck::NONCE_ACTION ),
					'timeoutMs' => max( 500, (int) ( $trust_settings['precheck_timeout_ms'] ?? 3000 ) ),
					'ttlMs'     => max( 1, (int) ( $trust_settings['ttl_minutes'] ?? 10 ) ) * 60000,
				);

				add_action( 'wp_ajax_' . Precheck::ACTION, array( $this, 'ajax_precheck' ) );
				add_action( 'wp_ajax_nopriv_' . Precheck::ACTION, array( $this, 'ajax_precheck' ) );

				/* 信用缓存失效钩子（02 §3.3a/R2 §2.4：transition_comment_status（涉已批双向——
				 * 超集实现=任意状态翻转都清，代价仅两次 transient 删）+ wp_insert_comment（直接已批）——
				 * 站长刚批完评论，访客下一次预检立即可信（无需等 TTL）。 */
				add_action( 'transition_comment_status', static function ( string $new_status, string $old_status, $comment ) use ( $comment_trust ): void {
					unset( $new_status, $old_status ); // 超集清键：状态翻转即身份信用可能变化。
					if ( $comment && property_exists( $comment, 'comment_author_email' ) ) {
						$comment_trust->invalidate( (string) $comment->comment_author_email, (int) ( $comment->user_id ?? 0 ) );
					}
				}, 10, 3 );
				add_action( 'wp_insert_comment', static function ( int $comment_id, $comment ) use ( $comment_trust ): void {
					unset( $comment_id );
					if ( $comment && property_exists( $comment, 'comment_author_email' )
						&& '1' === (string) $comment->comment_approved ) { // 直接已批路径（wp_insert_comment 直插/导入）。
						$comment_trust->invalidate( (string) $comment->comment_author_email, (int) ( $comment->user_id ?? 0 ) );
					}
				}, 10, 2 );
			}
		}

		/* ---------- 登录族三闸（【v1.4·R3】C3 登录闸 + register/lostpassword 两新闸；独立启停） ---------- */

		/* LoginIpRep 生产装配（02 §3.2 联动；M2.5=Null 恒 skip=现行行为——M3 AbuseIPDB
		 * 驱动接入后 IpReputationService::assemble 返回真实现，本接线零改动自动生效）。 */
		$login_iprep = new LoginIpRep(
			$settings,
			$iprep,
			'get_transient',
			static function ( string $key, $value, int $ttl ): void {
				set_transient( $key, $value, $ttl ); // WP 边界。
			},
			static function ( string $data ): string {
				return substr( hash_hmac( 'sha256', $data, wp_salt( 'auth' ) ), 0, 16 ); // WP 边界（与事件 ip_hash 同盐同源）。
			}
		);

		$login_mode = is_string( $settings['gates']['login']['render_mode'] ?? null ) ? $settings['gates']['login']['render_mode'] : 'adaptive';
		if ( ! empty( $settings['gates']['login']['enabled'] ) && 'off' !== $login_mode ) {
			$this->login_gate = new LoginGate( $settings, $captcha, null, null, null, null, null, $verify_cache, $login_iprep, null, $degraded_allow );

			// 【v1.4·R3 双信号主信号】wp_authenticate_user @ 1：只记录不动值——$user 为
			// WP_User（=用户存在）时捕获规范 user_login 供同请求稍后的 wp_login_failed 消费；
			// 优先级 1=在其他插件改写 $user 之前看到原初 WP_User（02 §3.2）。
			add_filter( 'wp_authenticate_user', array( $this->login_gate, 'hook_capture_wp_authenticate_user' ), 1, 2 );
			// 验证：wp_authenticate_user @ PHP_INT_MAX（压轴聚合错误，A4；渲染⇔验证不变式）。
			add_filter( 'wp_authenticate_user', array( $this->login_gate, 'hook_wp_authenticate_user' ), PHP_INT_MAX, 2 );
			// XML-RPC 超阈/iprep fail 结构化拒绝：authenticate @ PHP_INT_MAX（链尾聚合点——
			// 早期优先级返回的 WP_Error 会被默认认证回调覆盖，压轴返回即最终值）。
			add_filter( 'authenticate', array( $this->login_gate, 'hook_authenticate' ), PHP_INT_MAX, 3 );
			// XML-RPC faultString 文案面（wk_captcha_unavailable → 本插件文案）。
			add_filter( 'xmlrpc_login_error', array( $this->login_gate, 'hook_xmlrpc_login_error' ), 10, 2 );
			// 【B2】登录错误掩码（Wordfence maskLoginErrors 语义——只动呈现层文案，不动内部
			// 错误码分类 F39）：登录页 wp_login_errors + 找回页 lostpassword_errors 聚合输出处。
			add_filter( 'wp_login_errors', array( $this->login_gate, 'filter_wp_login_errors' ), 10, 2 );
			add_filter( 'lostpassword_errors', array( $this->login_gate, 'filter_lostpassword_errors' ), 10, 2 );
			// 计数：失败自增（双信号错误码分类；XML-RPC 认证失败经同一动作照常计数）/成功清零。
			add_action( 'wp_login_failed', array( $this->login_gate, 'hook_wp_login_failed' ), 10, 2 );
			add_action( 'wp_login', array( $this->login_gate, 'hook_wp_login' ), 10, 1 );
			// 渲染：login_form 动作（仅 login 表单内触发——v1.4 勘误后判定法）+ login_form_middle filter。
			add_action( 'login_form', array( $this->login_gate, 'render_login_form' ), 10, 0 );
			add_filter( 'login_form_middle', array( $this->login_gate, 'filter_login_form_middle' ), 10, 1 );
		}

		/* 注册闸（【v1.4·R3/F41】registration_errors filter 返回型——02 §3.2a 钩子挂点表）。 */
		if ( ! empty( $settings['gates']['register']['enabled'] ) ) {
			$this->register_gate = new RegisterGate( $settings, $captcha, null, null, null, null, $verify_cache, null, $degraded_allow );
			add_action( 'register_form', array( $this->register_gate, 'render_register_form' ), 10, 0 ); // wp-login.php:1173。
			add_filter( 'registration_errors', array( $this->register_gate, 'filter_registration_errors' ), 10, 3 ); // user.php:3655（用户创建前）。
		}

		/* 找回密码闸（action 突变型——与注册域 filter 返回型相反，两闸各自对齐 core）。 */
		if ( ! empty( $settings['gates']['lostpassword']['enabled'] ) ) {
			$this->lostpassword_gate = new LostPasswordGate( $settings, $captcha, null, null, null, null, $verify_cache, null, $degraded_allow );
			add_action( 'lostpassword_form', array( $this->lostpassword_gate, 'render_lostpassword_form' ), 10, 0 ); // wp-login.php:899。
			add_action( 'lostpassword_post', array( $this->lostpassword_gate, 'action_lostpassword_post' ), 10, 2 ); // user.php:3344（发信前；先于 invalidcombo）。
		}

		/* 登录页资产（【v1.4·R3】三闸任一出码即入队——与评论页同一套 gatekeeper.js +
		 * tencent.js + front-gate.css；tencent-login.js 退役删除，03 §2.2/05 §1.1⑤）。 */
		if ( null !== $this->login_gate || null !== $this->register_gate || null !== $this->lostpassword_gate ) {
			add_action( 'login_enqueue_scripts', array( $this, 'enqueue_login_assets' ) );
		}

		/* ---------- XML-RPC 登录一键关闭（03 Tab②「高级」；独立加固开关，闸关不剥夺） ---------- */
		add_filter(
			'xmlrpc_enabled',
			static function ( $enabled ) use ( $settings ) {
				if ( empty( $enabled ) ) {
					return $enabled; // 他方已关：不越权重开。
				}
				// gates.login.xmlrpc_login=false → 登录类 XML-RPC 方法整体 405
				// （system.listMethods 等只读方法不经 login()，不受影响）。
				return empty( $settings['gates']['login']['xmlrpc_login'] ) ? false : $enabled;
			}
		);
	}

	/**
	 * 前台资产（03 §2.1：调度器 + 驱动适配器 + 组件样式；驱动 JS 动态加载官方脚本）。
	 *
	 * @return void
	 */
	public function enqueue_front_assets(): void {
		wp_register_style(
			'wink-gatekeeper-front',
			$this->base_url . 'assets/css/front-gate.css',
			array(),
			WINK_GATEKEEPER_VERSION
		);
		wp_register_script(
			'wink-gatekeeper-front',
			$this->base_url . 'assets/js/gatekeeper.js',
			array(),
			WINK_GATEKEEPER_VERSION,
			true
		);
		wp_register_script(
			'wink-gatekeeper-driver-tencent',
			$this->base_url . 'assets/js/drivers/tencent.js',
			array( 'wink-gatekeeper-front' ), // 适配器依赖调度器先就位（注册表事件竞态收口）。
			WINK_GATEKEEPER_VERSION,
			true
		);
		wp_register_script(
			'wink-gatekeeper-driver-aliyun',
			$this->base_url . 'assets/js/drivers/aliyun.js',
			array( 'wink-gatekeeper-front' ),
			WINK_GATEKEEPER_VERSION,
			true
		);

		wp_localize_script( 'wink-gatekeeper-front', 'wkGateL10n', self::front_l10n() );

		if ( null === $this->comment_gate ) {
			return; // 闸未启用：注册不入队（零前台足迹）。
		}

		/* 预检门内联配置（【v1.4·R2】03 §2.1：nonce+端点+前端超时随页面注入；不含任何密钥——
		 * nonce 由 wp_create_nonce 生成、整页缓存 >12h 陈旧时预检恒降级出码（fail-safe 不伤提交））。 */
		if ( null !== $this->precheck_config ) {
			wp_add_inline_script(
				'wink-gatekeeper-front',
				'window.wkGatePrecheck = ' . wp_json_encode( $this->precheck_config ) . ';',
				'before'
			);
		}

		wp_enqueue_style( 'wink-gatekeeper-front' );
		wp_enqueue_script( 'wink-gatekeeper-front' );

		// 驱动适配器经接口方法入队（TencentCaptchaDriver::enqueue_assets → tencent.js）。
		$driver = $this->comment_gate->captcha_driver();
		if ( null !== $driver ) {
			$driver->enqueue_assets();
		}
	}

	/**
	 * 预检端点 admin-ajax 处理器（03 §2.1 端点契约：恒 HTTP 200——门禁/限流/降级一律
	 * captcha_needed，不区分报错原因不给探测面；草稿即判即弃零外呼）。
	 *
	 * @return void
	 */
	public function ajax_precheck(): void {
		if ( null === $this->precheck ) {
			wp_send_json( array( 'result' => Precheck::RESULT_CAPTCHA ) );
		}

		// 门禁① nonce（check_ajax_referer 的 die 形态不适用——预检失败不报错只降级）。
		$nonce_ok = isset( $_POST['nonce'] ) && is_scalar( $_POST['nonce'] )
			&& (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['nonce'] ) ), Precheck::NONCE_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce 已上行校验（$nonce_ok）。
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$resolver = new IpResolver( $this->precheck_settings_network() );
		$resolved = $resolver->resolve( isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array() );

		$user_id = get_current_user_id();
		$email   = isset( $post['email'] ) && is_scalar( $post['email'] ) ? (string) $post['email'] : '';
		if ( $user_id > 0 && '' === $email ) {
			// 登录评论者表单常无邮箱字段（服务端代填）——邮箱索引等价路径补账户邮箱。
			$user = get_userdata( $user_id );
			if ( $user && ! empty( $user->user_email ) ) {
				$email = $user->user_email;
			}
		}

		$response = $this->precheck->handle(
			array(
				'wk_stub' => isset( $post['wk_stub'] ) && is_scalar( $post['wk_stub'] ) ? (string) $post['wk_stub'] : '',
				'post_id' => isset( $post['post_id'] ) && is_scalar( $post['post_id'] ) ? (string) $post['post_id'] : '',
				'author'  => isset( $post['author'] ) && is_scalar( $post['author'] ) ? (string) $post['author'] : '',
				'email'   => $email,
				'content' => isset( $post['content'] ) && is_scalar( $post['content'] ) ? (string) $post['content'] : '',
			),
			$nonce_ok,
			is_string( $resolved['ip'] ) ? $resolved['ip'] : '',
			$user_id
		);

		wp_send_json( $response );
	}

	/**
	 * 预检处理器用的 network 设置（与 assemble_gates 同源读取）。
	 *
	 * @return array<string, mixed>
	 */
	private function precheck_settings_network(): array {
		$stored = get_option( SettingsPage::OPTION_NAME, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$merged = SettingsSchema::merge_defaults( $stored );
		return is_array( $merged['network'] ?? null ) ? $merged['network'] : array();
	}

	/**
	 * 历史信用计数器生产闭包（R2 §2.4 SQL 形态：COUNT 外包 LIMIT N 子查询——email 前缀
	 * 索引路径万级表 ≈0.1–2ms；登录用户默认邮箱索引等价路径，filter 可切 user_id 字面路径）。
	 *
	 * @return callable fn(string $mode, array $args, int $limit): int
	 */
	private static function trust_counter(): callable {
		return static function ( string $mode, array $args, int $limit ): int {
			global $wpdb;
			$limit = max( 1, $limit );

			if ( 'guest' === $mode ) {
				// 游客：同昵称+同邮箱已批（用户规则字面执行——昵称改一个字=新身份）。
				return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名受控常量拼接。
					$wpdb->prepare(
						"SELECT COUNT(*) FROM ( SELECT 1 FROM {$wpdb->comments} WHERE comment_author_email = %s AND comment_author = %s AND comment_approved = '1' LIMIT %d ) AS t",
						$args['email'],
						$args['author'],
						$limit
					)
				);
			}

			if ( 'user_id' === $mode ) {
				// 保守站长 filter 的字面路径（user_id 无索引——零命中=全表扫，R2 §2.4 已量化取舍）。
				return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名受控常量拼接。
					$wpdb->prepare(
						"SELECT COUNT(*) FROM ( SELECT 1 FROM {$wpdb->comments} WHERE user_id = %d AND comment_approved = '1' LIMIT %d ) AS t",
						$args['user_id'],
						$limit
					)
				);
			}

			// 登录用户默认：邮箱索引等价路径（comment_author_email=账户邮箱——WP 账户邮箱唯一故语义等价）。
			return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名受控常量拼接。
				$wpdb->prepare(
					"SELECT COUNT(*) FROM ( SELECT 1 FROM {$wpdb->comments} WHERE comment_author_email = %s AND comment_approved = '1' LIMIT %d ) AS t",
					$args['email'],
					$limit
				)
			);
		};
	}

	/**
	 * 登录页资产（【v1.4·R3/03 §2.2/05 §1.1⑤】）：三闸（login/register/lostpassword）
	 * 任一出码才入队——与评论页**同一套** gatekeeper.js + tencent.js + front-gate.css
	 * （tencent-login.js「提交时唤起」链退役删除——嵌入式后两大前提失效；C3 偏差 1
	 * 预留的「M4 抽公共 loader」提前兑现为「直接复用」）。should_render 门控照旧=
	 * 未触发零脚本（零打扰基线不变）。
	 *
	 * @return void
	 */
	public function enqueue_login_assets(): void {
		$driver = null;
		$should = false;
		foreach ( array( $this->login_gate, $this->register_gate, $this->lostpassword_gate ) as $gate ) {
			if ( null === $gate ) {
				continue;
			}
			if ( null === $driver ) {
				$driver = $gate->captcha_driver(); // 三闸共享同一验证码驱动实例（Bootstrap 装配）。
			}
			if ( $gate->should_render() ) {
				$should = true;
			}
		}
		if ( ! $should || null === $driver ) {
			return;
		}

		// 与 enqueue_front_assets 相同 handle 集注册（login_enqueue_scripts 不经 wp_enqueue_scripts，
		// 需在此注册后入队；wp_register_* 幂等）。
		wp_register_style(
			'wink-gatekeeper-front',
			$this->base_url . 'assets/css/front-gate.css',
			array(),
			WINK_GATEKEEPER_VERSION
		);
		wp_register_script(
			'wink-gatekeeper-front',
			$this->base_url . 'assets/js/gatekeeper.js',
			array(),
			WINK_GATEKEEPER_VERSION,
			true
		);
		wp_register_script(
			'wink-gatekeeper-driver-tencent',
			$this->base_url . 'assets/js/drivers/tencent.js',
			array( 'wink-gatekeeper-front' ),
			WINK_GATEKEEPER_VERSION,
			true
		);
		wp_register_script(
			'wink-gatekeeper-driver-aliyun',
			$this->base_url . 'assets/js/drivers/aliyun.js',
			array( 'wink-gatekeeper-front' ),
			WINK_GATEKEEPER_VERSION,
			true
		);

		wp_localize_script( 'wink-gatekeeper-front', 'wkGateL10n', self::front_l10n() );
		wp_enqueue_style( 'wink-gatekeeper-front' );
		wp_enqueue_script( 'wink-gatekeeper-front' );
		$driver->enqueue_assets(); // → wink-gatekeeper-driver-tencent（gatekeeper.js 承载提交禁用+票据过期回退）。
	}

	/**
	 * 前台 L10n 键集（评论页与登录页共用一套——03 §2.1/§2.2/§2.3 同一前端）。
	 *
	 * @return array<string, string>
	 */
	private static function front_l10n(): array {
		return array(
			// M2-FIX2 D1 v1.4（2026-11-10 用户反馈②）：三态单按钮文案（按钮 aria-live 承载）。
			'idle'      => __( '完成人机验证', 'wink-gatekeeper' ),
			'loading'   => __( '验证码加载中……', 'wink-gatekeeper' ),
			'pending'   => __( '验证中……', 'wink-gatekeeper' ),
			'done'      => __( '已通过验证', 'wink-gatekeeper' ),
			'retry'     => __( '验证未完成，点击重试', 'wink-gatekeeper' ),
			'loadFail'  => __( '验证码加载失败，点击重试', 'wink-gatekeeper' ),
			'mismatch'  => __( '验证组件加载异常，请刷新页面重试。', 'wink-gatekeeper' ),
			'error'     => __( '验证组件初始化失败，请刷新重试', 'wink-gatekeeper' ),
			// FIX-γ 2026-11-10（03 §2.1 v1.3）：8s 看门狗超时文案 + appId 缺失配置错误文案。
			'loadTimeout' => __( '网络不佳，请稍后重试或联系站长', 'wink-gatekeeper' ),
			'configError' => __( '验证码配置缺失，请联系站长。', 'wink-gatekeeper' ),
			// C1 2026-11-11（03 §2.1 v1.3）：票据 5 分钟过期回退文案（onExpire 适配器消费）。
			'expired'   => __( '验证已过期，请重新验证', 'wink-gatekeeper' ),
			// M2-FIX2 D3（2026-11-10 用户反馈③）：未验证点提交的提示文案（按钮临时切换 + title）。
			'hint'      => __( '请先完成人机验证', 'wink-gatekeeper' ), // 【v1.4·反馈5 改词】删除「上方」字样（嵌入式后验证码不一定在上方）。
			// 【v1.4·R2/03 §2.1】预检门 checking 态文案（按钮状态机增量：unverified → checking → …）。
			'checking'  => __( '检查中…', 'wink-gatekeeper' ),
			// 【v1.5·F4-1】免验轻提示（03 §2.1 v1.5：trust_pass 分支差异化文案——让「免验发生了」
			// 前台可感知；aria-live 随按钮播报）。
			'wk_trust_hint' => __( '老朋友，免验放行', 'wink-gatekeeper' ),
		);
	}

	/**
	 * 挂载设置页：设置 → Wink 门口保安（03 §1，权限 manage_options）。
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$this->settings_page_hook = add_options_page(
			__( 'Wink 门口保安', 'wink-gatekeeper' ),
			__( 'Wink 门口保安', 'wink-gatekeeper' ),
			'manage_options',
			SettingsPage::PAGE_SLUG,
			array( SettingsPage::class, 'render' )
		);
	}

	/**
	 * 设置页品牌资产入队（仅本插件页面）。
	 *
	 * @param string $hook_suffix 当前 admin 页面钩子。
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( null === $this->settings_page_hook || $hook_suffix !== $this->settings_page_hook ) {
			return;
		}

		wp_enqueue_style(
			'wink-gatekeeper-admin',
			$this->base_url . 'assets/css/admin-settings.css',
			array(),
			WINK_GATEKEEPER_VERSION
		);

		wp_enqueue_script(
			'wink-gatekeeper-admin-brand',
			$this->base_url . 'assets/js/admin-brand.js',
			array(),
			WINK_GATEKEEPER_VERSION,
			true
		);
	}

	/**
	 * 加载文本域（WP 6.7 起可省略 WP 截至 4.6 的手动目录约定，仍显式声明）。
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'wink-gatekeeper',
			false,
			dirname( plugin_basename( WINK_GATEKEEPER_FILE ) ) . '/languages'
		);
	}

	/**
	 * admin-bar 演练模式提醒（【v1.5·C1】02 §2.8 安全门 #3 双位之二——防遗忘主门；
	 * Wordfence 无此门判为缺陷不照抄）：仅 manage_options 用户可见，黄条节点点击直达
	 * Tab② 演练开关。wp_active() 内含 24h 懒过期判定（过期视同关不显示）。
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar 管理栏实例。
	 * @return void
	 */
	public function admin_bar_test_mode( $wp_admin_bar ): void {
		if ( ! ( $wp_admin_bar instanceof \WP_Admin_Bar ) ) {
			return;
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! TestMode::wp_active() ) {
			return;
		}
		$wp_admin_bar->add_node( array(
			'id'     => 'wk-gatekeeper-test-mode',
			'parent' => 'top-secondary',
			'title'  => __( 'wink 验证码演练模式启用中——全站人机验证旁路（24h 自动过期）', 'wink-gatekeeper' ),
			'href'   => admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG . '&tab=captcha' ),
			'meta'   => array( 'class' => 'wk-admin-bar-test-mode' ),
		) );
	}
}
