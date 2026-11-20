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
use WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver;
use WinkGatekeeper\Drivers\Review\LocalRulesDriver;
use WinkGatekeeper\Drivers\Review\OpenAiCustomReviewDriver;
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
use WinkGatekeeper\Gates\TransientRateLimiter;
use WinkGatekeeper\Gates\VerifyCache;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Ip\AbuseIpdbService;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Ip\IpReputationService;
use WinkGatekeeper\Audit\ExistingAuditCli;
use WinkGatekeeper\Audit\ExistingAuditTask;
use WinkGatekeeper\Audit\ExistingAuditWorker;
use WinkGatekeeper\Settings\SettingsPage;
use WinkGatekeeper\Settings\SettingsSchema;
use WinkGatekeeper\Site\SiteEnhance;
use WinkGatekeeper\UrlAudit\UrlAuditWorker;
use WinkGatekeeper\Stats\DashboardDigest;
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

	/**
	 * 装配期缓存：merge 后整树（【P0-6·F1 批】R-AK §D P0-5——ajax_precheck 是访客高频
	 * 面，旧实现 precheck_settings_network() 每请求重读 option + 重 merge 全树；assemble_
	 * gates 在 init@5 已 merge 过同源同值树，预检改读本属性复用（纯读复用——预检与提交期
	 * 设置一致性本来就该一致）。每请求独立实例，无跨请求语义。
	 *
	 * @var array<string, mixed>|null
	 */
	private $assembled_settings = null;

	/**
	 * autoload 自愈同请求幂等标志（【AUDIT-FIX 2026-11-20·P1-9】heal_option_autoload 消费）。
	 *
	 * @var bool
	 */
	private $autoload_healed = false;

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
		// 【AUDIT-FIX 2026-11-20·P1-9/S4-F1】option autoload 漂移读路径一次性自愈（幂等——
		// 现值已 off 零写）。挂 admin_init 而非 init：漂移唯一通道=保存链在行缺失时按默认
		// autoload 重建（options.php 主保存通道），管理上下文即全部需 healing 的时机；
		// 前台高频面零新增查询（A2 逐请求画像面不回退）。
		add_action( 'admin_init', array( $this, 'heal_option_autoload' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// 每日维护 cron（04 §3：90 天清理 + 滚动上限）。
		add_action( EventsTable::HOOK_DAILY, array( EventsTable::class, 'daily_maintenance' ) );

		// 【O3 §五】仪表盘统计卡片（Wordfence wordfenceClass.php:7410-7429 同款骨架 + 两处
		// 有意改造——manage_options 能力门禁自拦 / 暂不挂 wp_network_dashboard_setup）。
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
		// cron 丢失时健康卡「重新激活」通道（nonce + manage_options 双闸，重排后回仪表盘）。
		add_action( 'admin_action_wink_gatekeeper_reactivate_cron', array( $this, 'handle_reactivate_cron' ) );
		// Tab⑤ 拦截页「查看效果」预览（【M4UX·G3】：manage_options+nonce 双闸 → DiePage
		// 样例实渲染 → exit；零落库零事件零统计）。链接=admin-post.php?action=… 形态，
		// admin-post.php 对登录态派发 admin_post_{$action}（admin_action_ 钩子属 admin.php
		// 语境——reactivate_cron 先例），未注册 nopriv 钩子=未登录直接 400。
		add_action( 'admin_post_' . SettingsPage::ACTION_DIE_PREVIEW, array( SettingsPage::class, 'handle_die_preview' ) );

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
		add_action( 'wp_ajax_' . SettingsPage::AJAX_TEST_CREDENTIALS_DEEPSEEK, array( SettingsPage::class, 'ajax_test_credentials_deepseek' ) ); // 【M6·批B·N4】余额端点探测（零费用——models 探活退役为泛端点备件）。
		// 【M6·批E】Tab③ openai_custom「测试凭据」（/models 探活三态——泛端点无标准余额 API，零 token 消耗）。
		add_action( 'wp_ajax_' . SettingsPage::AJAX_TEST_CREDENTIALS_OPENAI_CUSTOM, array( SettingsPage::class, 'ajax_test_credentials_openai_custom' ) );
		// 【M6·批B·B-3】网址核查独立凭据「测试凭据」（N4 范围边界：DeepSeek 协议族同用余额探测，base_url 跟随其配置）。
		add_action( 'wp_ajax_' . SettingsPage::AJAX_TEST_CREDENTIALS_URL_AUDIT, array( SettingsPage::class, 'ajax_test_credentials_url_audit' ) );
		// Tab④ 诊断面板「手动聚合查询」（M3-IP 定稿 §5.1，I0 批：同双闸；零落库零缓存零统计红线）。
		add_action( 'wp_ajax_' . SettingsPage::AJAX_IP_DIAGNOSE, array( SettingsPage::class, 'ajax_ip_diagnose' ) );
		// Tab④ 分区②「测试凭据」（M3-IP 定稿 §6【O5 回写·P2-6】，I4 收官批：同双闸；
		// 绿=HTTP 200 且 JSON 可解析（isPublic:false 亦绿）——零计量零写零事件）。
		add_action( 'wp_ajax_' . SettingsPage::AJAX_TEST_IPREP, array( SettingsPage::class, 'ajax_test_iprep' ) );
		add_action( 'wp_ajax_' . SettingsPage::AJAX_TEST_AVATAR, array( SettingsPage::class, 'ajax_test_avatar_source' ) ); // 【M5·U2】Tab⑧ 头像源测试（nonce+manage_options 双闸在 handler 内）。

		// 【M5·U3】存量审核四操作（Tab⑦ 发起/暂停/继续/重新体检——D6 分工）。
		add_action( 'admin_post_' . SettingsPage::ACTION_AUDIT_START, array( SettingsPage::class, 'handle_audit_action' ) );
		add_action( 'admin_post_' . SettingsPage::ACTION_AUDIT_PAUSE, array( SettingsPage::class, 'handle_audit_action' ) );
		add_action( 'admin_post_' . SettingsPage::ACTION_AUDIT_RESUME, array( SettingsPage::class, 'handle_audit_action' ) );
		add_action( 'admin_post_' . SettingsPage::ACTION_AUDIT_RESET, array( SettingsPage::class, 'handle_audit_action' ) );

		// 【W3·批C·R5】首启向导完成/跳过出口（GET 链接 wp_nonce_url——manage_options+nonce
		// 双闸在 handler；完成置 done / 跳过置 skipped，均不可逆——R5）。
		add_action( 'admin_post_' . SettingsPage::ACTION_WIZARD_DONE, array( SettingsPage::class, 'handle_wizard_finish' ) );
		add_action( 'admin_post_' . SettingsPage::ACTION_WIZARD_SKIP, array( SettingsPage::class, 'handle_wizard_skip' ) );

		// 【W3·批D·N8】Tab⑥ 拦截记录流水「导出 CSV」（GET 链接 wp_nonce_url——die_preview
		// 同形态；manage_options+check_admin_referer 双闸在 handler；导出当日拦截流水五列
		// 闭集零 PIPL 项，当日零记录回页黄条提示不输出空文件——R6）。
		add_action( 'admin_post_' . SettingsPage::ACTION_EXPORT_CSV, array( SettingsPage::class, 'handle_export_csv' ) );

		// 【M5·U3】存量审核 cron 面：自定义 interval + tick worker（发起/继续时由
		// SettingsPage::audit_schedule_tick 排期；CLI 通道为主、cron 为站长侧补充）。
		add_filter(
			'cron_schedules',
			static function ( $schedules ) {
				$schedules[ ExistingAuditTask::CRON_INTERVAL ] = array(
					'interval' => 300,
					'display'  => __( 'Every 5 minutes (wink existing-audit worker)', "wink-wp-gatekeeper" ),
				);
				return $schedules;
			}
		);
		add_action( ExistingAuditTask::HOOK_TICK, static function (): void {
			$stored = get_option( SettingsPage::OPTION_NAME, array() );
			$worker = new ExistingAuditWorker(
				SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() )
			);
			$worker->run_batch();
		} );

		// 【M5·U3】wp-cli 双入口之二：wp wink-gatekeeper audit-existing（CLI 通道不依赖
		// WP-Cron 可靠性——B 机 cron 形态未定论，CLI 是真机验收主通道）。
		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			ExistingAuditCli::register();
		}

		/* ---------- M5 U5 网址核查（nw 三原则①：异步-first 同步链零接触） ---------- */
		// 增量入队（comment_post 边界——瞬时返回零外呼；功能关/URL 空/重复幂等在 worker 内）。
		add_action(
			'comment_post',
			static function ( int $comment_id ): void {
				$worker = self::url_audit_worker();
				$worker->enqueue_url_audit_job( $comment_id );
			},
			20,
			1
		);
		// 双车道 cron tick（每 5 分钟——与存量审核同 interval 档）。
		add_action( UrlAuditWorker::HOOK_TICK, static function (): void {
			self::url_audit_worker()->run_round();
		} );
		// 清缓存 admin action（Tab③ 高级折叠——manage_options+nonce 双闸在 handler）。
		add_action( 'admin_post_' . UrlAuditWorker::ACTION_PURGE, array( SettingsPage::class, 'handle_url_audit_purge' ) );
		// 【M6·P1-3】批量回捞显式动作（Tab⑦ 操作卡「开始存量网址核查」——D6 分工：
		// 操作卡 Tab⑦/报告卡 Tab⑥；manage_options+nonce 双闸在 handler）。
		add_action( 'admin_post_' . UrlAuditWorker::ACTION_BACKLOG, array( SettingsPage::class, 'handle_url_audit_backlog' ) );
		// host 级清缓存（Tab⑥ 报告卡「重新核查此站」——nw §4.3 站长举报误判入口）。
		add_action( 'admin_post_wink_gatekeeper_url_host_purge', array( SettingsPage::class, 'handle_url_audit_host_purge' ) );
		// 双车道 tick 排期（常驻——功能关时 worker 首查 enabled 直接空转零外呼；免 start/resume 排期面）。
		if ( ! wp_next_scheduled( UrlAuditWorker::HOOK_TICK ) ) {
			wp_schedule_event( time() + 120, ExistingAuditTask::CRON_INTERVAL, UrlAuditWorker::HOOK_TICK );
		}
	}

	/**
	 * 网址核查 Worker（生产装配——全默认 WP 通道；设置树 merge 单源）。
	 *
	 * @return UrlAuditWorker
	 */
	private static function url_audit_worker(): UrlAuditWorker {
		$stored = get_option( SettingsPage::OPTION_NAME, array() );
		return new UrlAuditWorker( SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() ) );
	}

	/**
	 * option autoload 漂移读路径一次性自愈（【AUDIT-FIX 2026-11-20·P1-9/S4-F1】admin_init 消费）。
	 *
	 * 缺口：激活 add_option(…, false) 正确，但此后全库无 autoload 再断言——option 行一旦
	 * 删除（卸载/外部清理工具）后经保存链 update_option 重建即漂移 autoload=auto（WP 语义：
	 * 行存在保留既有 autoload、行缺失按默认开重建）。漂移态=涉密/大 option（8 明文凭据）
	 * 随每请求自动装载进内存+对象缓存（S4 批内实录 auto；stats 同族活体）。
	 *
	 * 幂等：现值 'off'（正常态）零写零 UPDATE——仅漂移态执行一次；行缺失（未安装/激活前）
	 * 跳过——activation add_option(…, false) 重建面天然正确。WP 6.4+ 走规范件
	 * wp_set_option_autoload；6.0-6.3 守卫回落等价直 UPDATE（wp_set_option_autoload 内部
	 * 同款 SQL 形态；'off' 全版本合法取值）。
	 *
	 * 覆盖面：wink_gatekeeper_settings（密钥主 option）+ wink_gatekeeper_stats（同族活体——
	 * P3 复位 eval-file 重建实录 autoload=auto）。其余插件 option 写点已全量带第三参 false
	 * （本批 grep 补口 probe_log——S4-F2 确定性漂移口），漂移路径不再开放。
	 *
	 * 代价：每 admin 请求一次 SELECT（get_var——admin 低频面；前台零新增查询）。
	 *
	 * @return void
	 */
	public function heal_option_autoload(): void {
		global $wpdb;
		if ( isset( $this->autoload_healed ) && $this->autoload_healed ) {
			return; // 同请求幂等（admin_init 重复触发防御）。
		}
		$this->autoload_healed = true;

		foreach ( array( SettingsPage::OPTION_NAME, StatsCache::OPTION_NAME ) as $wink_option_name ) {
			$current = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- autoload 列 get_option 不透出；低频管理面单行点查。
				$wpdb->prepare( 'SELECT autoload FROM ' . $wpdb->options . ' WHERE option_name = %s', $wink_option_name ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepare 即在位。
			);
			if ( null === $current || 'off' === $current ) {
				continue; // 行缺失（activation 重建面）或已关：零写。
			}
			if ( function_exists( 'wp_set_option_autoload' ) ) {
				wp_set_option_autoload( $wink_option_name, false ); // WP 6.4+ 规范件（幂等，含缓存失效）。
				continue;
			}
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- 6.0-6.3 回落：仅漂移态单行 UPDATE（'off' 全版本合法取值）。
				$wpdb->prepare(
					'UPDATE ' . $wpdb->options . ' SET autoload = %s WHERE option_name = %s AND autoload <> %s',
					'off',
					$wink_option_name,
					'off'
				) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepare 即在位。
			);
		}
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

		// 【P0-6·F1】装配期缓存 merge 后整树（ajax_precheck / precheck_settings_network
		// 复用——访客高频面免每请求重读 option + 重 merge 全树；R-AK §D P0-5）。
		$this->assembled_settings = $settings;

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

		/* 【P1-3·S3-F1】原子自增路（AtomicCounter——wp_cache_incr+首击定 TTL；无持久对象
		 * 缓存/后端拒绝恒 false=消费方落回 legacy transient 桶路，语义零变化）。 */
		$atomic_incr = 'WinkGatekeeper\Gates\AtomicCounter::incr';

		/* 评论提交限流器（P0-A，M3-IP 定稿 §8.1——AUDIT-CC §2.1 P0-A 修复）：wk_cm_ per-IP
		 * 小时桶（TransientRateLimiter 参数化共享件，后续消费方=check/report 面/P1-C 送审帽）。
		 * gates.comment.submit_rate_limit 默认 30 次/h/IP；0=不限流（不装配=闸侧 null 跳过=
		 * 现行行为零变化）。限的是「提交动作」不是「过码动作」——挂点在存根校验后、四闸前。 */
		$submit_limit = max( 0, (int) ( $settings['gates']['comment']['submit_rate_limit'] ?? TransientRateLimiter::DEFAULT_LIMIT ) );
		$submit_allow = null;
		if ( $submit_limit > 0 ) {
			$submit_limiter = new TransientRateLimiter(
				$submit_limit,
				TransientRateLimiter::DEFAULT_WINDOW,
				'get_transient',
				static function ( string $key, $value, int $ttl ): void {
					set_transient( $key, $value, $ttl ); // WP 边界。
				},
				static function ( string $data ): string {
					return hash_hmac( 'sha256', $data, wp_salt( 'auth' ) ); // 与 VerifyCache/事件 ip_hash 同盐同源。
				},
				$atomic_incr // 【P1-3·S3-F1】原子自增路（check-then-inc 竞态窗消除——并发上限不再稀释）。
			);
			$submit_allow = array( $submit_limiter, 'allow' );
		}

		/* 【P1-4·S3-F2】拦截留档容量闸：per-IP 日桶 wk_cai_（默认 10）+ 全局日桶 wk_cag_
		 * （默认 200），0=对应桶不装配（不限量现行语义）。只封「无界落库」——拦截行为/
		 * 事件面语义不变（事件面另有 EventSampling 采样+事件表容量闸独立收口）。 */
		$archive_ip_limit    = max( 0, (int) ( $settings['intercept']['archive_daily_ip_cap'] ?? CommentGate::ARCHIVE_IP_CAP_DEFAULT ) );
		$archive_total_limit = max( 0, (int) ( $settings['intercept']['archive_daily_total_cap'] ?? CommentGate::ARCHIVE_TOTAL_CAP_DEFAULT ) );
		$archive_quota = null;
		if ( $archive_ip_limit > 0 || $archive_total_limit > 0 ) {
			$archive_put = static function ( string $key, $value, int $ttl ): void {
				set_transient( $key, $value, $ttl ); // WP 边界。
			};
			$archive_mac = static function ( string $data ): string {
				return hash_hmac( 'sha256', $data, wp_salt( 'auth' ) ); // 与提交限流同盐同源。
			};
			$archive_ip_limiter    = $archive_ip_limit > 0 ? new TransientRateLimiter( $archive_ip_limit, CommentGate::ARCHIVE_DAY_WINDOW, 'get_transient', $archive_put, $archive_mac, 'wk_cai_', $atomic_incr ) : null;
			$archive_total_limiter = $archive_total_limit > 0 ? new TransientRateLimiter( $archive_total_limit, CommentGate::ARCHIVE_DAY_WINDOW, 'get_transient', $archive_put, $archive_mac, 'wk_cag_', $atomic_incr ) : null;
			$archive_quota = static function ( string $ip ) use ( $archive_ip_limiter, $archive_total_limiter ): bool {
				if ( '' !== $ip && null !== $archive_ip_limiter && ! $archive_ip_limiter->allow( $ip ) ) {
					return false; // per-IP 日帽拒（全局桶不计数——单 IP 洪水不烧全局额度）。
				}
				if ( null !== $archive_total_limiter && ! $archive_total_limiter->allow( 'global' ) ) {
					return false; // 全局日帽拒。
				}
				return true;
			};
		}

		// 驱动注册表（05 §4 六维元数据；新驱动 = 新文件 + 注册一行）。
		$registry = new DriverRegistry();
		$registry->register( TencentCaptchaDriver::registry_entry() );
		$registry->register( AliyunCaptchaDriver::registry_entry() ); // B3：阿里云验证码 2.0（05 §1.2）。
		$registry->register( LocalRulesDriver::registry_entry() );
		$registry->register( TencentTmsDriver::registry_entry() ); // C4：腾讯 TMS 文本审核。
		$registry->register( AliyunGreenPlusDriver::registry_entry() ); // B5：阿里文本审核增强版 PLUS（05 §2.5）。
		$registry->register( BaiduTextModerationDriver::registry_entry() ); // B6：百度文本审核（05 §2.4——token 30 天缓存复用）。
		$registry->register( DeepSeekReviewDriver::registry_entry() ); // 【M5·U4】DeepSeek LLM（05 §2.2 协议族+M5 定稿 §4 五件套）。
		$registry->register( OpenAiCustomReviewDriver::registry_entry() ); // 【M6·批E】自定义 OpenAI 兼容端点（registry 第 10 条目——纯配置子类，四件套全继承基类）。
		/* 【v1.7·M3-IP·定稿 §2 I2】abuseipdb 注册（type='ip'/billing='free_quota'/frontend=[]/
		 * supports_report=true——O5-P3-3 三字段占位）：by_type('ip') 非空后 Tab① 点名册卡/
		 * Tab⑥ IP 单列行/review_split 折减/Labels「信誉查询」自动生效（渲染侧既有逻辑零改动）。 */
		$registry->register( AbuseIpdbService::registry_entry() );
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
				} elseif ( 'deepseek' === $chain_id ) {
					// 【M5·U4】DeepSeek LLM 驱动（05 §2.2 协议族+M5 定稿 §4 五件套）——
					// 配置派生 from_settings（含 review.cloud 顶层 two_stage/upgrade_model）；
					// K2 LlmUsage 回写走默认 WP option 通道（驱动内）。
					$deepseek_driver = new DeepSeekReviewDriver(
						DeepSeekReviewDriver::config_from_settings( $settings ),
						$client
					);
					if ( $deepseek_driver->is_configured() ) {
						$cloud_chain['deepseek'] = $deepseek_driver;
					}
				} elseif ( 'openai_custom' === $chain_id ) {
					// 【M6·批E】自定义 OpenAI 兼容端点（deepseek 分支形态照抄）：配置派生
					// from_settings（8 键子树+顶层 two_stage/upgrade_model 透传——留空短路
					// 两段式）；is_configured 覆写查三键（api_key+base_url+model），半配置
					// 静默剔除不空烧日配额。
					$openai_custom_driver = new OpenAiCustomReviewDriver(
						OpenAiCustomReviewDriver::config_from_settings( $settings ),
						$client
					);
					if ( $openai_custom_driver->is_configured() ) {
						$cloud_chain['openai_custom'] = $openai_custom_driver;
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
		 * captcha.verify_cache_minutes 决定，0=停用）；评论/登录两闸共享同一实例。
		 * 【AUDIT-FIX 2026-11-20·P1-2】删除闭包注入（窗内口令错误计次吊销面——
		 * VerifyCache::record_password_failure 消费；缺省亦为 delete_transient，显式传与
		 * 'get_transient' 对称）。 */
		$verify_cache = new VerifyCache(
			(int) ( $settings['captcha']['verify_cache_minutes'] ?? VerifyCache::DEFAULT_TTL_MINUTES ),
			'get_transient',
			static function ( string $key, $value, int $ttl ): void {
				set_transient( $key, $value, $ttl ); // WP 边界。
			},
			null, // mac：wp_salt('auth') WP 边界默认。
			'delete_transient' // 【P1-2】吊销面（窗内口令错误计次达阈删 wk_cv_ 键）。
		);

		/* IP 信誉三态服务（02 §2.3b/05 §3.0——【v1.7·M3-IP·定稿 §2 I2】enabled=false → Null
		 * 恒 skip=现行行为；enabled=true → AbuseIpdbService（api_key 空的防呆从严在服务内
		 * 承载）。传输脊传共享实例——check 外呼计量走 RemoteClient 钩子（Usage::bump_option
		 * 'abuseipdb'，外呼尝试即计）。三处消费方零改动（预检 cache_only / 评论提交期完整模式 /
		 * 登录闸 LoginIpRep 渲染 cache_only+提交完整模式）。 */
		$iprep = IpReputationService::assemble( $settings, $client );

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
					// 【v1.5·FIX】require_gate：null = WP 边界默认闭包（02 §2.1 豁免过滤器）。
					// ⚠️ 此 null 占位不可省——B2 曾在此处少传一位，$degraded_allow 顶入 require_gate
					// 位（DegradedPassLimiter::allow 被当豁免判定调用→恒 false→评论闸渲染+验证
					// 双双静默跳过，真机「无按钮+无票直发」）。装配实参数由 BootstrapAssemblyContractTest 钉死。
					null,
					// 【v1.5·C1】pass_limited 降级放行限流器（errorPolicy 默认 block=零消费）。
					$degraded_allow,
					// 【P0-A】评论提交限流器（M3-IP 定稿 §8.1——wk_cm_ 桶；submit_rate_limit=0 时
					// null=不限流现行行为）。
					$submit_allow,
					// 【P1-4·S3-F2】拦截留档容量判定位（per-IP 日桶 wk_cai_ + 全局日桶 wk_cag_；
					// null=未装配=批前留档语义）。
					$archive_quota
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
						// 【AUDIT-FIX 2026-11-20·S6-F2】游客键并入昵称维——失效面按 (email,author) 精确清键。
						$comment_trust->invalidate( (string) $comment->comment_author_email, (int) ( $comment->user_id ?? 0 ), (string) ( $comment->comment_author ?? '' ) );
					}
				}, 10, 3 );
				add_action( 'wp_insert_comment', static function ( int $comment_id, $comment ) use ( $comment_trust ): void {
					unset( $comment_id );
					if ( $comment && property_exists( $comment, 'comment_author_email' )
						&& '1' === (string) $comment->comment_approved ) { // 直接已批路径（wp_insert_comment 直插/导入）。
						$comment_trust->invalidate( (string) $comment->comment_author_email, (int) ( $comment->user_id ?? 0 ), (string) ( $comment->comment_author ?? '' ) );
					}
				}, 10, 2 );
			}
		}

		/* ---------- 登录族三闸（【v1.4·R3】C3 登录闸 + register/lostpassword 两新闸；独立启停） ---------- */

		/* LoginIpRep 生产装配（02 §3.2 联动；【v1.7·M3-IP·定稿 §2 I2 缓存归一】缓存职能已
		 * 内化 AbuseIpdbService——本类退化纯映射。下方 get/set/hmac 三闭包为退役占位适配层
		 * （形参位保持装配形态稳定，闭包不再被调用；wk_ip_ 键读写/回填全部由服务承载，
		 * 消灭「服务缓存+登录域缓存」双写双读）。 */
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

		/* ---------- M5 U1+U2 站点增强（Tab⑧；**无条件装配**——站点内容设置面与闸启停
		 * 无关，闸关不剥夺；两开关自带 false 默认=关闭收集/前台去链接，行为面见
		 * includes/site/SiteEnhance.php docblock：U1-A 表单隐藏+提交面双保险丢弃 /
		 * U1-B 前台去链接（后台照常）/ U2 头像镜像（镜像不可解析=零改写 fail-safe）。） ---------- */
		$site_enhance = new SiteEnhance( $settings );
		add_filter( 'comment_form_defaults', array( $site_enhance, 'filter_comment_form_defaults' ) );
		add_filter( 'preprocess_comment', array( $site_enhance, 'filter_preprocess_comment' ) ); // 与 CommentGate @-PHP_INT_MAX 并存（strip 只删 url 键不碰判定面）。
		add_filter( 'get_comment_author_link', array( $site_enhance, 'filter_comment_author_link' ), 10, 3 );
		// 【M5FIX1】区块主题收口：core 区块 wp-block-comment-author-name 直接用
		// get_comment_author_url() 拼锚点（绕过上方 get_comment_author_link）——
		// 前台去链接激活时 URL 一并清空（区块 url 空=渲染纯文本名字；影响面取舍
		// 注记见 includes/site/SiteEnhance.php 类 docblock）。
		add_filter( 'get_comment_author_url', array( $site_enhance, 'filter_comment_author_url' ) );
		add_filter( 'pre_get_avatar_data', array( $site_enhance, 'filter_pre_get_avatar_data' ), 10, 2 );
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

		/* 【AUDIT-FIX 2026-11-20·P1-8/A2-F2】评论区资产条件入队：仅可评论单体页（is_singular）
		 * 且评论开放（comments_open）时入队——评论区不存在/评论关闭的页面零脚本零样式
		 * （评论闸开启后每页 63-66KB 未压缩足迹收窄）。wp_enqueue_scripts 于 wp_head 期
		 * 触发（主查询已就绪，条件判定可靠）；登录页三闸资产走 enqueue_login_assets
		 * 独立注册入队（不经 wp_enqueue_scripts——本条件零牵连）。 */
		if ( ! ( is_singular() && comments_open() ) ) {
			return;
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
	 * 预检处理器用的 network 设置（与 assemble_gates 同源同值——【P0-6·F1】改读装配期
	 * 缓存树复用：免每请求重读 option + 重 merge 全树；调用前提=assemble_gates 已跑
	 * （ajax_precheck 对 precheck=null 提前返回，而 precheck 仅在 assemble_gates 装配）。
	 *
	 * @return array<string, mixed>
	 */
	private function precheck_settings_network(): array {
		$merged = is_array( $this->assembled_settings ) ? $this->assembled_settings : array();
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
			'idle'      => __( 'Complete human verification', 'wink-wp-gatekeeper' ),
			'loading'   => __( 'Loading captcha…', 'wink-wp-gatekeeper' ),
			'pending'   => __( 'Verifying…', 'wink-wp-gatekeeper' ),
			'done'      => __( 'Verification passed', 'wink-wp-gatekeeper' ),
			'retry'     => __( 'Verification incomplete — click to retry', 'wink-wp-gatekeeper' ),
			'loadFail'  => __( 'Captcha failed to load — click to retry', 'wink-wp-gatekeeper' ),
			'mismatch'  => __( 'The verification widget failed to load. Please refresh the page and try again.', 'wink-wp-gatekeeper' ),
			'error'     => __( 'The verification widget failed to initialize — refresh to retry', 'wink-wp-gatekeeper' ),
			// FIX-γ 2026-11-10（03 §2.1 v1.3）：8s 看门狗超时文案 + appId 缺失配置错误文案。
			'loadTimeout' => __( 'Weak network — please retry later or contact the site owner', 'wink-wp-gatekeeper' ),
			'configError' => __( 'Captcha is not configured. Please contact the site owner.', 'wink-wp-gatekeeper' ),
			// C1 2026-11-11（03 §2.1 v1.3）：票据 5 分钟过期回退文案（onExpire 适配器消费）。
			'expired'   => __( 'Verification expired — please verify again', 'wink-wp-gatekeeper' ),
			// M2-FIX2 D3（2026-11-10 用户反馈③）：未验证点提交的提示文案（按钮临时切换 + title）。
			'hint'      => __( 'Please complete human verification first', 'wink-wp-gatekeeper' ), // 【v1.4·反馈5 改词】删除「上方」字样（嵌入式后验证码不一定在上方）。
			// 【v1.4·R2/03 §2.1】预检门 checking 态文案（按钮状态机增量：unverified → checking → …）。
			'checking'  => __( 'Checking…', 'wink-wp-gatekeeper' ),
			// 【v1.5·F4-1】免验轻提示（03 §2.1 v1.5：trust_pass 分支差异化文案——让「免验发生了」
			// 前台可感知；aria-live 随按钮播报）。
			'wk_trust_hint' => __( 'Old friend, no verification needed', 'wink-wp-gatekeeper' ),
			// 【i18n 批 I-3】checking_submit 补录（gatekeeper.js 真实提交等待期 Toast 此前读
			// wkGateL10n.checking_submit 恒缺键走 zh 兜底——本批扩键集，zh msgstr=原兜底原文）。
			'checking_submit' => __( 'Your comment is being checked, please wait…', 'wink-wp-gatekeeper' ),
			// 【i18n 批 I-3】checking_submit 补录（gatekeeper.js 真实提交等待期 Toast 此前读
			// wkGateL10n.checking_submit 恒缺键走 zh 兜底——本批扩键集，zh msgstr=原兜底原文）。
			'checking_submit' => __( 'Your comment is being checked, please wait…', 'wink-wp-gatekeeper' ),
			// 【i18n 批 I-3】checking_submit 补录（gatekeeper.js 真实提交等待期 Toast 此前读
			// wkGateL10n.checking_submit 恒缺键走 zh 兜底——本批扩键集，zh msgstr=原兜底原文）。
			'checking_submit' => __( 'Your comment is being checked, please wait…', 'wink-wp-gatekeeper' ),
			// 【i18n 批 I-3】checking_submit 补录（gatekeeper.js 真实提交等待期 Toast 此前读
			// wkGateL10n.checking_submit 恒缺键走 zh 兜底——本批扩键集，zh msgstr=原兜底原文）。
			'checking_submit' => __( 'Your comment is being checked, please wait…', 'wink-wp-gatekeeper' ),
			// 【W2·无按钮模式】submit-gate 模块文案（0.8.4，gatekeeper.js 消费——Toast 单例播报；
			// 无按钮语境的「重试」=再点一次提交）。
			'submit_gate_verify' => __( 'Please complete human verification before submitting', 'wink-wp-gatekeeper' ),
			'submit_gate_retry'  => __( 'Human verification incomplete — click submit once more to retry', 'wink-wp-gatekeeper' ),
			// 【F3-2·0.5.4】旧「过码后切网络提示」（net_changed）退役——用户明示删除
			// （「插在这里很丑，删掉」M4UX-MANUAL-FEEDBACK-2 §一④）；天御票据网络绑定知识
			// 改入人工卡备注行（MANUAL-CARD-M4UX ④），不做前台提示。
		);
	}

	/**
	 * admin 侧 L10N 键集（批 I-3——admin-brand.js 动态文案：测试凭据按钮态/诊断面板/
	 * CIDR 校验/重置反馈等；键名=JS 侧 l10n() 第一参，兜底=同值 en）。
	 *
	 * @return array<string, string>
	 */
	private static function admin_l10n(): array {
		return array(
			'resetDone'        => __( 'Reset done.', 'wink-wp-gatekeeper' ),
			'resetFail'        => __( 'Reset failed.', 'wink-wp-gatekeeper' ),
			'resetFailNet'     => __( 'Reset failed (network error).', 'wink-wp-gatekeeper' ),
			'drvMissingA'      => __( 'Driver “', 'wink-wp-gatekeeper' ),
			'drvMissingB'      => __( '” is missing: ', 'wink-wp-gatekeeper' ),
			'drvMissingC'      => __( ' — fill it in before saving (blocked this time; clicking save again force-submits and the server treats the driver as not configured).', 'wink-wp-gatekeeper' ),
			'badgeGreen'       => __( 'Connected · credentials work', 'wink-wp-gatekeeper' ),
			'badgeYellow'      => __( 'Incomplete · required fields missing', 'wink-wp-gatekeeper' ),
			'badgeRed'         => __( 'Rejected · key error', 'wink-wp-gatekeeper' ),
			'badgeGray'        => __( 'Contract mismatch · status unknown', 'wink-wp-gatekeeper' ),
			'shortGreen'       => __( 'Connected', 'wink-wp-gatekeeper' ),
			'shortYellow'      => __( 'Incomplete', 'wink-wp-gatekeeper' ),
			'shortRed'         => __( 'Rejected', 'wink-wp-gatekeeper' ),
			'shortGray'        => __( 'Contract mismatch', 'wink-wp-gatekeeper' ),
			'stampMonth'       => __( '-', 'wink-wp-gatekeeper' ),
			'stampDay'         => __( ' ', 'wink-wp-gatekeeper' ), // phpcs:ignore WordPress.WP.I18n -- 键值=单空格分隔位（zh msgstr=「日 」，日期词序不可整句化——⑤-补跨节点形态口径）。
			'lastTest'         => __( 'Last test: ', 'wink-wp-gatekeeper' ),
			'testing'          => __( 'Testing…', 'wink-wp-gatekeeper' ),
			'testFail'         => __( 'Test failed.', 'wink-wp-gatekeeper' ),
			'testFailNet'      => __( 'Test failed (network error).', 'wink-wp-gatekeeper' ),
			'srcAbuseipdb'     => __( 'AbuseIPDB (threat group)', 'wink-wp-gatekeeper' ),
			'srcStopforumspam' => __( 'StopForumSpam (threat group)', 'wink-wp-gatekeeper' ),
			'srcIpncgy'        => __( 'ip.nc.gy (threat group)', 'wink-wp-gatekeeper' ),
			'srcIpsb'          => __( 'ip.sb (geo group)', 'wink-wp-gatekeeper' ),
			'lookupFail'       => __( 'The lookup failed.', 'wink-wp-gatekeeper' ),
			'enterIp'          => __( 'Enter an IP to look up first.', 'wink-wp-gatekeeper' ),
			'querying'         => __( 'Looking up… (3s timeout per source, up to ~12s total)', 'wink-wp-gatekeeper' ),
			'donePrefix'       => __( 'Done: ', 'wink-wp-gatekeeper' ),
			'lookupFailNet'    => __( 'The lookup failed (network error).', 'wink-wp-gatekeeper' ),
			'cidrAll1'         => __( 'All ', 'wink-wp-gatekeeper' ),
			'cidrAll2'         => __( ' lines are valid.', 'wink-wp-gatekeeper' ),
			'cidrBad1'         => __( 'Found ', 'wink-wp-gatekeeper' ),
			'cidrBad2'         => __( ' lines are not valid CIDR/IP: ', 'wink-wp-gatekeeper' ),
			'etcSuffix'        => __( ' etc.', 'wink-wp-gatekeeper' ),
		);
	}




	/**
	 * 挂载设置页：设置 → Wink 门口保安（03 §1，权限 manage_options）。
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$this->settings_page_hook = add_options_page(
			__( 'Wink WP Gatekeeper', 'wink-wp-gatekeeper' ),
			__( 'Wink WP Gatekeeper', 'wink-wp-gatekeeper' ),
			'manage_options',
			SettingsPage::PAGE_SLUG,
			array( SettingsPage::class, 'render' )
		);
	}

	/* -------------------------------------------------------------------
	 * 【O3 §五/§七 步骤 2-5】仪表盘统计卡片（Wordfence wordfenceClass.php:7410-7429
	 * 同款注册骨架；渲染回调形态对齐 wfActivityReport::outputDashboardWidget 的静态
	 * 回调位——wink 按分层把 echo 放边界层方法，DashboardDigest 纯逻辑类零 echo）。
	 * ------------------------------------------------------------------ */

	/**
	 * 仪表盘 widget 注册（wp_dashboard_setup）。
	 *
	 * 两处有意改造（O3 §五）：
	 *   ① 能力门禁用 current_user_can('manage_options') 而非只判 is_admin——Wordfence 用
	 *     wfUtils::isAdmin()（wordfenceClass.php:7412），wink 的统计含拦截量与驱动健康，
	 *     默认只给管理员看；WP 仪表盘 widget 本身不提供能力过滤，必须在注册回调里自拦。
	 *   ② 暂不挂 wp_network_dashboard_setup——wink 的表/option 都是 per-site 口径
	 *     （O2 P2-1 多站点议题收口后再议，避免网络仪表盘显示某个子站的数字造成误读）。
	 */
	public function add_dashboard_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; // 改造①：能力门禁。
		}
		$stored   = get_option( SettingsPage::OPTION_NAME, array() );
		$settings = SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() );
		if ( empty( $settings['stats']['dashboard_widget_enabled'] ) ) {
			return; // 开关键（默认 true，仿 email_summary_dashboard_widget_enabled，wfConfig.php:124）。
		}
		wp_enqueue_style(
			'wink-gatekeeper-widget',
			$this->base_url . 'assets/css/admin-widget.css',
			array(),
			WINK_GATEKEEPER_VERSION
		);
		wp_add_dashboard_widget(
			'wink_gatekeeper_dashboard_widget',
			__( 'Wink WP Gatekeeper · Today\'s status', 'wink-wp-gatekeeper' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * 仪表盘卡片渲染（echo 边界；数据面 = DashboardDigest::widget_overview 视图模型）。
	 *
	 * 数据源纪律（DashboardDigest 类头注不变式）：视图①零 EventsTable 明细查询——
	 * 只读三个 option 计数器 + 设置树 + 两处真实态布尔（验证码 is_configured /
	 * wp_next_scheduled）。转义纪律（Wordfence views/reports/activity-report.php:37
	 * 同款）：文本 esc_html / 属性 esc_attr / 链接 esc_url / 计数 (int) 强转。
	 * 管理面文案一律 __()（text domain），不走访客侧文案工坊 Copywork（O3 §六）。
	 *
	 * @return void
	 */
	public function render_dashboard_widget(): void {
		$stored   = get_option( SettingsPage::OPTION_NAME, array() );
		$settings = SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() );

		// 验证码驱动配置态：真实驱动 is_configured 判定（纯逻辑层不复制凭据规则——单点真相；
		// 传输脊空壳构造不发起任何网络调用——is_configured 只读凭据字段）。
		// 【M6·批H·H-3】防御回落随新出厂默认 aliyun。
		$captcha_driver = is_string( $settings['captcha']['driver'] ?? null ) && '' !== $settings['captcha']['driver']
			? $settings['captcha']['driver'] : 'aliyun';
		$captcha_configured = false;
		$captcha_label      = $captcha_driver;
		if ( 'tencent' === $captcha_driver ) {
			$tencent = new TencentCaptchaDriver( $settings, new RemoteClient() );
			$captcha_configured = $tencent->is_configured();
			$captcha_label      = $tencent->label();
		} elseif ( 'aliyun' === $captcha_driver ) {
			$aliyun = new AliyunCaptchaDriver( $settings, new RemoteClient() );
			$captcha_configured = $aliyun->is_configured();
			$captcha_label      = $aliyun->label();
		}

		// cron 存活（每日维护——events 表 90 天清理/滚动上限依赖；false = 丢失）。
		// cron 存活（每日维护——events 表 90 天清理/滚动上限依赖；false = 丢失）。
		$cron_alive = false !== wp_next_scheduled( EventsTable::HOOK_DAILY );

		/* 【AUDIT-FIX 2026-11-20·P0-E1②】CDN 代理转发头 × ip_source=direct 冲突判定
		 * （与 Tab④ 页顶黄条同一纯函数单一来源——IpResolver::proxy_header_conflict；
		 * 判定结果供卡片顶部「站务提醒位」渲染，vendor 无关零网段知识）。 */
		$network_mode = isset( $settings['network']['ip_source'] ) && is_string( $settings['network']['ip_source'] )
			? $settings['network']['ip_source'] : IpResolver::MODE_DIRECT;
		$proxy_header_conflict = IpResolver::proxy_header_conflict(
			$network_mode,
			isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array()
		);

		// 用量行人话名（registry_entry label 单一来源；abuseipdb 两键 M3 前手名——
		// 未注册 id 经 DashboardDigest 回落原名，不虚构）。
		// 【M6·批I·I-1】deepseek 补录（P-1：评论审核域行原裸 id 退役——registry label 单一来源）。
		$driver_labels = array(
			'tencent'          => (string) TencentCaptchaDriver::registry_entry()['label'],
			'aliyun'           => (string) AliyunCaptchaDriver::registry_entry()['label'],
			'local_rules'      => (string) LocalRulesDriver::registry_entry()['label'],
			'tencent_tms'      => (string) TencentTmsDriver::registry_entry()['label'],
			'aliyun_green'     => (string) AliyunGreenPlusDriver::registry_entry()['label'],
			'baidu'            => (string) BaiduTextModerationDriver::registry_entry()['label'],
			'deepseek'         => (string) DeepSeekReviewDriver::registry_entry()['label'],
			// 【M6·批E】openai_custom 补 registry label 静态行（future 循环同源注入随退役
			// 消失——deepseek I-1 先例同款；单一来源=registry_entry 不硬抄字面）。
			'openai_custom'    => (string) OpenAiCustomReviewDriver::registry_entry()['label'],
			'abuseipdb'        => 'AbuseIPDB',
			'abuseipdb_report' => __( 'AbuseIPDB (reporting)', "wink-wp-gatekeeper" ),
		);
		// 【M6·批H2·H2-1】未来驱动显示名同源注入（future_review_drivers 公开件单一来源
		// ——不硬抄字面；openai_custom/moli 未实装无计量=当前零可见效果的一致性/防御面，
		// 未来实装批计量行自动人话——审计 BC-8 留白销账）。
		foreach ( SettingsPage::future_review_drivers() as $future_id => $future_meta ) {
			$driver_labels[ (string) $future_id ] = isset( $future_meta['label'] ) && is_string( $future_meta['label'] )
				? $future_meta['label'] : (string) $future_id;
		}

		$health_tree = get_option( DriverHealth::OPTION_NAME, array() );

		$view = DashboardDigest::widget_overview( array(
			'settings'             => $settings,
			'stats'                => StatsCache::get(),
			'usage'                => Usage::get(),
			'health'               => is_array( $health_tree ) ? $health_tree : array(),
			'today_ymd'            => current_time( 'Ymd' ),
			'today_dash'           => current_time( 'Y-m-d' ),
			'captcha_configured'   => $captcha_configured,
			'captcha_driver'       => $captcha_driver,
			'captcha_driver_label' => $captcha_label,
			'driver_labels'        => $driver_labels,
			'cron_alive'           => $cron_alive,
			'debug'                => defined( 'WP_DEBUG' ) && WP_DEBUG,
		) );

		$tab1_url = admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG . '&tab=overview' );
		$tab6_url = admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG . '&tab=stats' );
		$reactivate_url = wp_nonce_url(
			admin_url( 'admin.php?action=wink_gatekeeper_reactivate_cron' ),
			'wink_gatekeeper_reactivate_cron'
		);
		?>
		<div class="wk-widget">
			<?php /* 【AUDIT-FIX 2026-11-20·P0-E1②】站务提醒位：CDN 代理转发头 × ip_source=direct
			 * 冲突黄条（widget 族 is-alert 形态——ip_source 配置与请求形态不符=per-IP 防线可能
			 * 整体失效的部署级告警；Tab④ 页顶同款黄条深链）。 */ ?>
			<?php if ( $proxy_header_conflict ) : ?>
				<p class="wk-widget-line is-alert" id="wk-widget-proxy-header-alert">
					<span class="wk-widget-light is-warn" aria-hidden="true"></span>
					<?php echo esc_html( __( 'Proxy forwarding headers detected but the IP source is direct — per-IP defenses (rate limiting / login thresholds / reputation attribution) may fail entirely; choose an IP source that matches your deployment topology in the network settings.', 'wink-wp-gatekeeper' ) ); ?>
					<a class="wk-widget-fix" href="<?php echo esc_url( admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG . '&tab=iprep' ) ); ?>"><?php echo esc_html( __( 'Go check', 'wink-wp-gatekeeper' ) ); ?></a>
				</p>
			<?php endif; ?>
			<?php /* 卡 1：系统健康——四闸状态点 + 验证码配置态 + 停转红警报 + cron 存活。 */ ?>
			<div class="wk-widget-card" id="wk-widget-health">
				<p class="wk-widget-card-title"><?php echo esc_html( __( 'System health', 'wink-wp-gatekeeper' ) ); ?></p>
				<ul class="wk-widget-dots">
					<?php foreach ( $view['health']['gates'] as $gate ) : ?>
						<li class="wk-widget-dot<?php echo esc_attr( ! empty( $gate['enabled'] ) ? ' is-on' : ' is-off' ); ?>">
							<span class="wk-widget-light" aria-hidden="true"></span><?php echo esc_html( (string) $gate['label'] ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="wk-widget-line">
					<span class="wk-widget-light<?php echo esc_attr( $captcha_configured ? ' is-on' : ' is-warn' ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( (string) $view['health']['captcha']['label'] ); ?>：
					<?php echo esc_html( $captcha_configured ? __( 'Configured', 'wink-wp-gatekeeper' ) : __( 'Not configured — the captcha stage is skipped entirely', 'wink-wp-gatekeeper' ) ); ?>
				</p>
				<?php foreach ( $view['health']['halts'] as $halt ) : ?>
					<?php
					$halt_label = isset( $driver_labels[ (string) $halt['driver_id'] ] ) ? $driver_labels[ (string) $halt['driver_id'] ] : (string) $halt['driver_id'];
					?>
					<p class="wk-widget-line is-alert">
						<span class="wk-widget-light is-red" aria-hidden="true"></span>
						<?php echo esc_html( $halt_label ); ?>：<?php echo esc_html( (string) $halt['note'] ); ?><?php echo esc_html( (string) $halt['detail'] ); ?>
					</p>
				<?php endforeach; ?>
				<p class="wk-widget-line">
					<span class="wk-widget-light<?php echo esc_attr( $view['health']['cron_alive'] ? ' is-on' : ' is-red' ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( __( 'Daily maintenance task', 'wink-wp-gatekeeper' ) ); ?>：
					<?php echo esc_html( $view['health']['cron_alive'] ? __( 'Scheduled', 'wink-wp-gatekeeper' ) : __( 'Missing — event cleanup has stalled', 'wink-wp-gatekeeper' ) ); ?>
					<?php if ( ! $view['health']['cron_alive'] ) : ?>
						<a class="wk-widget-fix" href="<?php echo esc_url( $reactivate_url ); ?>"><?php echo esc_html( __( 'Reactivate', 'wink-wp-gatekeeper' ) ); ?></a>
					<?php endif; ?>
				</p>
				<p class="wk-widget-actions">
					<a class="button button-primary" href="<?php echo esc_url( $tab1_url ); ?>"><?php echo esc_html( __( 'Overview details', 'wink-wp-gatekeeper' ) ); ?></a>
				</p>
			</div>

			<?php /* 卡 2：第三方调用量——【M6·批H·H-4】五域固定顺序（人机验证→评论审核→
			 * 〔内容审核本地不计数不显〕→网页抓取→网页搜索；IP 信誉殿后）+各域实际启用驱动行
			 * +本站直抓不计费声明行 + 站点总外呼 + 容灾放行。 */ ?>
			<div class="wk-widget-card" id="wk-widget-usage">
				<p class="wk-widget-card-title"><?php echo esc_html( __( 'Third-party calls (today)', 'wink-wp-gatekeeper' ) ); ?></p>
				<?php if ( array() === $view['usage']['domains'] ) : ?>
					<p class="wk-widget-empty"><?php echo esc_html( __( 'No outbound calls today.', 'wink-wp-gatekeeper' ) ); ?></p>
				<?php else : ?>
					<?php foreach ( $view['usage']['domains'] as $domain ) : ?>
						<p class="wk-widget-usage-domain"><?php echo esc_html( (string) $domain['label'] ); ?></p>
						<ul class="wk-widget-usage">
							<?php foreach ( $domain['rows'] as $row ) : ?>
								<li class="wk-widget-usage-row<?php echo esc_attr( ! empty( $row['quota_reached'] ) ? ' is-red' : '' ); ?>">
									<?php echo esc_html( (string) $row['label'] ); ?>
									<?php if ( ! empty( $row['bill_free'] ) ) : ?>
										<?php /* 【M6·批H·H-4③】本站直抓不计费声明行——无次数渲染。 */ ?>
									<?php elseif ( (int) $row['quota'] > 0 ) : ?>
										<span class="wk-widget-num"><?php echo (int) $row['used']; ?> / <?php echo (int) $row['quota']; ?></span>
										<?php echo ! empty( $row['quota_reached'] ) ? esc_html( __( '— quota reached, paused for today', 'wink-wp-gatekeeper' ) ) : ''; ?>
									<?php else : ?>
										<span class="wk-widget-num"><?php echo (int) $row['used']; ?></span><?php echo esc_html( __( ' times', 'wink-wp-gatekeeper' ) ); ?>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endforeach; ?>
					<p class="wk-widget-line">
						<?php echo esc_html( __( 'Site outbound calls today', 'wink-wp-gatekeeper' ) ); ?>
						<span class="wk-widget-num"><?php echo (int) $view['usage']['site_total']; ?></span><?php echo esc_html( __( ' times', 'wink-wp-gatekeeper' ) ); ?>
					</p>
				<?php endif; ?>
				<p class="wk-widget-line">
					<?php echo esc_html( __( 'Failover passes today', 'wink-wp-gatekeeper' ) ); ?>
					<span class="wk-widget-num<?php echo esc_attr( (int) $view['usage']['disaster_pass'] > 0 ? ' is-red' : '' ); ?>"><?php echo (int) $view['usage']['disaster_pass']; ?></span><?php echo esc_html( __( ' times', 'wink-wp-gatekeeper' ) ); ?><?php echo (int) $view['usage']['disaster_pass'] > 0 ? esc_html( __( '— a barometer of captcha service instability', 'wink-wp-gatekeeper' ) ) : ''; ?>
				</p>
				<p class="wk-widget-actions">
					<a class="button button-primary" href="<?php echo esc_url( $tab6_url ); ?>"><?php echo esc_html( __( 'View call details', 'wink-wp-gatekeeper' ) ); ?></a>
				</p>
			</div>

			<?php /* 卡 3：今日拦截概览迷你条——三格计数 + 行动出口。 */ ?>
			<div class="wk-widget-card" id="wk-widget-overview">
				<p class="wk-widget-strip">
					<?php echo esc_html( __( 'Today: blocked', 'wink-wp-gatekeeper' ) ); ?>
					<span class="wk-widget-num"><?php echo (int) $view['overview']['reject']; ?></span>
					<?php echo esc_html( __( '| pending', 'wink-wp-gatekeeper' ) ); ?>
					<span class="wk-widget-num"><?php echo (int) $view['overview']['review']; ?></span>
					<?php echo esc_html( __( '| driver errors', 'wink-wp-gatekeeper' ) ); ?>
					<span class="wk-widget-num<?php echo esc_attr( (int) $view['overview']['error'] > 0 ? ' is-red' : '' ); ?>"><?php echo (int) $view['overview']['error']; ?></span>
				</p>
				<p class="wk-widget-actions">
					<a class="button button-primary" href="<?php echo esc_url( $tab6_url ); ?>"><?php echo esc_html( __( 'View details', 'wink-wp-gatekeeper' ) ); ?></a>
				</p>
			</div>

			<?php if ( '' !== $view['debug_note'] ) : ?>
				<p class="wk-widget-debug"><?php echo esc_html( (string) $view['debug_note'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * cron 重新激活处理（健康卡「重新激活」深链落点；nonce + manage_options 双闸）。
	 *
	 * 重排逻辑 = EventsTable::register_cron（幂等——已排程不重复注册）；完成后回仪表盘。
	 *
	 * @return void
	 */
	public function handle_reactivate_cron(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wink-wp-gatekeeper' ), 403 );
		}
		check_admin_referer( 'wink_gatekeeper_reactivate_cron' );
		EventsTable::register_cron();
		wp_safe_redirect( admin_url( 'index.php' ) );
		exit;
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
			// 【M5FIX】CDN 击穿语义：EdgeOne 按 URL 缓存 admin 资源——版本键只随
			// WINK_GATEKEEPER_VERSION 走时，版本号不变批次内的 JS 内容变化击不穿
			// 缓存（实站回传：admin-brand.js 旧文件持续命中）。纪律：本文件内容
			// 变化必须伴随本行版本参数变化（后缀递进 -m5fix1 → -m5fix2 …）。
			// 【M6·批B】本批 JS 内容变化（网址核查独立凭据测试按钮绑定）→ -m6b1。
			// 【W3·批A·N7】本批 JS 内容变化（scheme 前端兜底/校验首段——design-system
			// §02·P4 前端侧）→ -w3a1。CSS 注册行不加内联后缀（0.8.5 进位一次击穿，
			// 施工图 §5 风险 3 / R9 口径——M5FixAssetVerTest CSS 形态钉同步）。
			// 【W3·批B】本批 JS 内容变化（八 Tab 重组绑定面注记——M5Fix 纪律批内递进）
			// → -w3b1。
			// 【W3·批C】本批 JS 内容变化（首启向导交互模块——stepper 显隐/radio-card .on
			// 同步/下一步 referer 改写/驱动·云审卡面显隐）→ -w3c1（M5Fix 纪律批内递进；
			// 0.8.5 进位一次击穿在批 D 收口）。
			// 【W3-FIX】本批 JS 内容变化（测试凭据徽标全量回写共享件 wkProbeWriteBadges
			// + 九个 probe log 闭集驱动探活接线——W3F2 手测缺陷①）→ -w3fix1。
			// 【i18n 批 I-3】本批 JS 内容变化（admin-brand.js 全量文案 L10N 通道化）→ -i3a1。
			// 【i18n 批 I-4a·后缀退役】0.9.0 milestone 进位（0.8.7-i3a1 → 0.9.0 裸值）——
			// 版本号一次击穿 CDN 缓存，内联后缀退役归零；M5FIX 纪律本体不变（本文件
			// 内容再变化时须 bump 进位或重新加缀，M5FixAssetVerTest 断言同步翻转）。
			WINK_GATEKEEPER_VERSION,
			true
		);

		// 【i18n 批 I-3】admin 侧 L10N 注入（admin-brand.js 动态文案键集——PHP 侧全 __()）。
		wp_localize_script( 'wink-gatekeeper-admin-brand', 'wkAdminL10n', self::admin_l10n() );
	}

	/**
	 * 加载文本域（WP 6.7 起可省略 WP 截至 4.6 的手动目录约定，仍显式声明）。
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'wink-wp-gatekeeper',
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
			'title'  => __( 'Wink captcha drill mode is active — site-wide human verification bypassed (auto-expires in 24h)', 'wink-wp-gatekeeper' ),
			'href'   => admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG . '&tab=captcha' ),
			'meta'   => array( 'class' => 'wk-admin-bar-test-mode' ),
		) );
	}
}
