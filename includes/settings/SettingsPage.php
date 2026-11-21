<?php
/**
 * 设置页（WP 边界层，03 §1：单 option + 7 Tab + 品牌页头）。
 *
 * - register_setting 的 sanitize 回调桥到纯逻辑层 Sanitize::clean()，
 *   再经 SettingsSchema::merge_defaults() 补全默认树（schema_version 收口）；
 * - 渲染：Night #111114 品牌页头 + 静态 logo【M6·批H4·H4-5 眨眼动效退役】+ 七 Tab 骨架（M0：区块占位，
 *   总览放「今日拦截」静态占位卡）；
 * - 安全线：settings_fields（nonce）、manage_options、esc_html/esc_attr 全覆盖。
 *
 * @package WinkGatekeeper\Settings
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（S2-F1 纵深加固——类文件直访零执行，守卫为纵深面）。
}

use WinkGatekeeper\Drivers\captcha\AliyunCaptchaDriver;
use WinkGatekeeper\Drivers\captcha\TencentCaptchaDriver;
use WinkGatekeeper\Drivers\Review\AliyunGreenPlusDriver;
use WinkGatekeeper\Drivers\Review\BaiduTextModerationDriver;
use WinkGatekeeper\Drivers\Review\TencentTmsDriver;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Ip\AbuseIpdbCategories;
use WinkGatekeeper\Ip\AbuseIpdbService;
use WinkGatekeeper\Ip\IpDiagnostic;
use WinkGatekeeper\Ip\IpReputationService;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Ip\TrustedProxies;
use WinkGatekeeper\Settings\CaptchaTabSpec;
use WinkGatekeeper\Settings\CredentialProbe;
use WinkGatekeeper\Settings\DriverConfigSpec;
use WinkGatekeeper\Stats\DriverHealth;
use WinkGatekeeper\Stats\Usage;

final class SettingsPage {

	// 【i18n 批 I-4c·PAGE_SLUG 复原】值回 'wink-gatekeeper'（v0.8.7 原值）——I-2b 域翻转
	// 误伤 URL slug 面（B 机旧设置页 URL ?page=wink-gatekeeper 硬 403 实锤），back-compat
	// 复通；text domain 仍为 wink-wp-gatekeeper（两域已分立，本行非 domain 面）。
	const PAGE_SLUG    = 'wink-gatekeeper';
	const OPTION_NAME  = 'wink_gatekeeper_settings';
	const OPTION_GROUP = 'wink_gatekeeper';

	/**
	 * 【W3·批C·N1】测试凭据持久化单 option（R4：per 驱动一槽覆盖写，键=驱动 id 闭集
	 * PROBE_LOG_DRIVERS，值=array(state, tested_at)——message 不入库红线）。卸载清单
	 * 与 OptionHygieneTest 键集契约同步登记（uninstall.php delete_option）。
	 */
	const OPTION_PROBE_LOG = 'wink_gatekeeper_probe_log';

	/**
	 * 【W3·批C·R5】首启向导状态 option（枚举 pending|done|skipped，缺省=无记录）。
	 * **只由 register_activation_hook 写 'pending'**（wink-wp-gatekeeper.php 激活回调）——
	 * 存量站升级不触发 activation → option 恒缺失 → 永不自动弹（零 migrate 扫描差分，
	 * R5）；done/skipped 由向导出口两 handler 写入（不可逆，无重置 UI）。
	 */
	const OPTION_WIZARD_STATE = 'wink_gatekeeper_wizard_state';

	/** 【W3·批C·R5】向导状态三态闭集（缺省=无记录）。 */
	const WIZARD_STATE_PENDING = 'pending';
	const WIZARD_STATE_DONE    = 'done';
	const WIZARD_STATE_SKIPPED = 'skipped';

	/**
	 * @var string[] 向导状态闭集（wizard_state 读件白名单）。
	 */
	const WIZARD_STATES = array( 'pending', 'done', 'skipped' );

	/**
	 * 【W3·批C·N1】probe log 驱动键闭集（R4——九凭据测试位；avatar 头像探测非凭据
	 * 语境不入集，结果照旧不落库）。
	 *
	 * @var string[]
	 */
	const PROBE_LOG_DRIVERS = array(
		'tencent',
		'aliyun',
		'tencent_tms',
		'aliyun_green',
		'baidu',
		'deepseek',
		'openai_custom',
		'url_audit_provider',
		'abuseipdb',
	);

	/**
	 * 【W3·批C·R5】向导完成出口 admin action（manage_options + nonce 双闸在 handler；
	 * GET 链接形态——wp_nonce_url，reactivate_cron 先例）。
	 * 【W3-FIX·0.8.6】链接生成面=admin-post.php?action=…（admin_post_* 钩子只由
	 * admin-post.php 派发，admin.php 不派发——0.8.5 出口四链曾用 admin.php 形态=死链，
	 * W3F2 手测实锤白屏/wizard_state 不翻转；die_preview/审计四件/CSV 导出先例同口径）。
	 */
	const ACTION_WIZARD_DONE = 'wink_gatekeeper_wizard_done';

	/** 【W3·批C·R5】向导跳过出口 admin action（跳过=不提交表单直接置 skipped）。 */
	const ACTION_WIZARD_SKIP = 'wink_gatekeeper_wizard_skip';

	/**
	 * 【W3·批A·N7】深色 admin color scheme 集合（R1 明度二分的成员表形态）——
	 * WP「管理配色方案」基色明度 L<0.5 的深底系（Midnight/Coffee/Ectoplasm/Ocean）。
	 * 集合成员为静态推断（凭 WP 通用认知，未实机核 get_admin_color_schemes() 基色
	 * 结构——施工批真机核安排挂手测卡 MANUAL-CARD-W3-A）；未知/自定义 scheme 不入
	 * 两集合=恒暗色保底（design-system §02·P4 兜底条款）。
	 *
	 * @var string[]
	 */
	const ADMIN_COLOR_DEEP_SCHEMES = array( 'midnight', 'coffee', 'ectoplasm', 'ocean' );

	/**
	 * 【W3·批A·N7】浅色 admin color scheme 集合——出厂默认 Fresh + Light/Blue/Modern
	 * （R1）；Sunrise 为施工批裁定归浅色（原 3.0 浅色系之一——基色红橙但页底为浅色的
	 * 静态推断，未实机核；翻案只动本单数组，W3SchemeTokensTest 同步翻转）。
	 *
	 * @var string[]
	 */
	const ADMIN_COLOR_LIGHT_SCHEMES = array( 'fresh', 'light', 'blue', 'modern', 'sunrise' );

	/**
	 * 【W3·批A→批B 翻转】notice_yellow_bar 四级 → 插件级黄条 class 映射（闭集）：
	 * 黄=要留意/红=已出事/绿=报平安/蓝=中性说明。批 A 迁段为 WP 原生 notice 等价
	 * 形态（M6H3/UiM4UxF2 source-scan 契约字面量钉）；批 B 八 Tab 重组时翻转插件级
	 * 类（CSS 组件族 .wk-notice--{warn|danger|ok|info}——design-system §04），
	 * classes 迁段兼容位随之退役（编排位显式字面量清零，回落本映射）。
	 *
	 * @var array<string, string>
	 */
	const NOTICE_LEVEL_CLASSES = array(
		'warn'   => 'wk-notice wk-notice--warn',
		'danger' => 'wk-notice wk-notice--danger',
		'ok'     => 'wk-notice wk-notice--ok',
		'info'   => 'wk-notice wk-notice--info',
	);

	/**
	 * 统计重置 AJAX action（03 Tab⑥；manage_options + check_ajax_referer）。
	 */
	const AJAX_RESET_STATS = 'wink_gatekeeper_reset_stats';

	/**
	 * Tab②「测试凭据」AJAX action（03 §1 契约④：nonce + manage_options 双闸；结果记录最近一次测试状态与时间——probe log option，N1；message 不入库）。
	 */
	const AJAX_TEST_CREDENTIALS = 'wink_gatekeeper_test_credentials';

	/**
	 * Tab② 阿里「测试凭据」AJAX action（【v1.5·B3】：nonce + manage_options 双闸；结果记录最近一次测试状态与时间——probe log option，N1；message 不入库）。
	 */
	const AJAX_TEST_CREDENTIALS_ALIYUN = 'wink_gatekeeper_test_credentials_aliyun';

	/**
	 * Tab③ TMS「测试凭据」AJAX action（C4：nonce + manage_options 双闸；结果记录最近一次测试状态与时间——probe log option，N1；message 不入库；
	 * 绿态顺带解除 tencent_tms 当日停转——02 §5「自检通过」解除条件）。
	 */
	const AJAX_TEST_CREDENTIALS_TMS = 'wink_gatekeeper_test_credentials_tms';

	/**
	 * Tab③ 阿里 PLUS「测试凭据」AJAX action（【v1.5·B5】：nonce + manage_options 双闸；
	 * 结果记录最近一次测试状态与时间——probe log option，N1；message 不入库；绿态顺带解除 aliyun_green 当日停转——05 §2.5 凭据自检三态/TMS 先例同款）。
	 */
	const AJAX_TEST_CREDENTIALS_ALIYUN_GREEN = 'wink_gatekeeper_test_credentials_aliyun_green';

	/**
	 * Tab③ 百度「测试凭据」AJAX action（【v1.5·B6】：nonce + manage_options 双闸；
	 * 结果记录最近一次测试状态与时间——probe log option，N1；message 不入库；绿态顺带解除 baidu 当日停转——05 §2.4 凭据自检三态/TMS 先例同款）。
	 */
	const AJAX_TEST_CREDENTIALS_BAIDU = 'wink_gatekeeper_test_credentials_baidu';

	/**
	 * Tab③ DeepSeek「测试凭据」AJAX action（【M5·U4】D5 裁决：GET /models 探活——
	 * 零 token 成本证 Bearer 鉴权通；nonce + manage_options 双闸；结果记录最近一次测试状态与时间——probe log option，N1；message 不入库；零计量）。
	 */
	const AJAX_TEST_CREDENTIALS_DEEPSEEK = 'wink_gatekeeper_test_credentials_deepseek';

	/**
	 * Tab③ openai_custom「测试凭据」AJAX action（【M6·批E】：GET {base_url}/models 探活
	 * 三态——泛端点无标准余额 API，零 token 消耗；nonce + manage_options 双闸；结果
	 * 记录最近一次测试状态与时间——probe log option，N1；message 不入库；零计量；响应闭集不含提交值）。
	 */
	const AJAX_TEST_CREDENTIALS_OPENAI_CUSTOM = 'wink_gatekeeper_test_credentials_openai_custom';

	/**
	 * 【M6·批B·B-3】网址核查独立凭证测试 AJAX action（provider custom——DeepSeek 协议族
	 * 同用余额探测，base_url 跟随其配置）；【W3·批C·N1】结果记录最近一次测试状态与时间
	 * （probe log option；message 不入库）。
	 */
	const AJAX_TEST_CREDENTIALS_URL_AUDIT = 'wink_gatekeeper_test_credentials_url_audit';

	/**
	 * Tab④ 诊断面板「手动聚合查询」AJAX action（M3-IP 定稿 §5.1，I0 批）：
	 * nonce + manage_options 双闸；**设计红线：四源仅此手动区出现——零闸路径/零缓存/零统计/
	 * 零自动调用（不带 usage_key），任一源失败仅该卡片失败**；结果不落库（纯请求态渲染）。
	 */
	const AJAX_IP_DIAGNOSE = 'wink_gatekeeper_ip_diagnose';

	/**
	 * Tab④ 分区②「测试凭据」AJAX action（M3-IP 定稿 §6【O5 回写·P2-6】，I4 收官批）：
	 * nonce + manage_options 双闸（B4 声明式纪律）；最小 check 查询（127.0.0.2 保留地址+
	 * maxAgeInDays=1 省配额）三态——绿=HTTP 200 且 JSON 可解析（isPublic:false 亦绿——验证的
	 * 是凭据不是 IP）/黄=缺参（API Key 空）/红=401/402 错钥；结果记录最近一次测试状态与时间
	 * （probe log option，N1；message 不入库）——零计量零写。
	 */
	const AJAX_TEST_IPREP = 'wink_gatekeeper_test_iprep';

	/**
	 * Tab⑤ 拦截页「查看效果」预览 admin action（【M4UX·G3】人工反馈第 3 轮，0.5.5）：
	 * manage_options + nonce 双闸（03 §1 契约④）→ DiePage 样例实渲染（当前自定义文案+
	 * 占位符示例值填充）→ echo 完整品牌页 → exit；**零落库零事件零统计**（只读 option，
	 * 不写任何状态）。
	 */
	const ACTION_DIE_PREVIEW = 'wink_gatekeeper_die_preview';

	/**
	 * Tab⑥ 拦截记录流水「导出 CSV」admin action（【W3·批D·N8】施工图 R6——全图唯一
	 * 实锤空接线收口，钮与端点同 commit）：admin_post GET 形态（die_preview 先例同款
	 * wp_nonce_url 链接）+ manage_options + check_admin_referer 双闸；导出**当日**拦截
	 * 流水（EventsTable::today_intercept_rows——recent_* 同族当日窗体），五列闭集
	 * （时间/结果/哪张门/原因/事件编号——InterceptDigest::EXPORT_CSV_COLUMNS，零明文
	 * 账号/邮箱/评论正文/零 IP——PIPL 最小化与登录流水表 Q3 同口径）；当日零记录=
	 * 黄条提示不输出文件（回 Tab⑥ 带 wk-export-empty 标志）。**零计量零写**。
	 */
	const ACTION_EXPORT_CSV = 'wink_gatekeeper_export_csv';

	/**
	 * Tab⑧ 卡二「测试当前头像来源」AJAX action（【M5·U2】UI 设计块 1 卡二三态按钮）：
	 * nonce + manage_options 双闸；对当前表单所选头像源发一次固定 email hash 探测
	 * （每源 1 请求——UI 设计块 1.3），三态=绿（200+图片）/黄（>3s 能用但慢）/
	 * 红（连不上或非图片）；**测的是镜像通不通非密钥**（与凭据测试不同语境）；结果不落库
	 * 零计量零事件（RemoteClient 传输脊，不带 usage_key——诊断查询同口径；N1 probe log
	 * 不覆盖本位——avatar 不入 PROBE_LOG_DRIVERS 闭集）。
	 */
	const AJAX_TEST_AVATAR = 'wink_gatekeeper_test_avatar_source';

	/**
	 * Tab⑦「历史评论体检」操作面 admin action 四件（【M5·U3】UI 设计块 5.1——D6 裁决：
	 * Tab⑦ 发起/Tab⑥ 报告分工）——nonce + manage_options 双闸在 handle_audit_action；
	 * start/resume 侧重排 cron tick、pause/reset 侧清；结果重定向回 Tab⑦（零落库副作用
	 * 除进度 option/meta）。
	 */
	const ACTION_AUDIT_START  = 'wink_gatekeeper_audit_start';
	const ACTION_AUDIT_PAUSE  = 'wink_gatekeeper_audit_pause';
	const ACTION_AUDIT_RESUME = 'wink_gatekeeper_audit_resume';
	const ACTION_AUDIT_RESET  = 'wink_gatekeeper_audit_reset';

	/**
	 * 头像源探测固定 email hash（md5('wink-gatekeeper-avatar-probe@example.com')——
	 * 每源 1 请求口径；探测的是源可达性非真实头像）。
	 */
	const AVATAR_PROBE_HASH = 'f36f1fe24ea129522b9eb822fef2aa59';

	/**
	 * @var \WinkGatekeeper\Drivers\DriverRegistry|null Bootstrap 装配期注入的驱动注册表。
	 */
	private static $driver_registry = null;

	/**
	 * 七 Tab 信息架构（03 §1）：tab 键 => [序号, 标题]。
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	const TABS = array(
		'overview' => array( '①', 'Overview' ),
		'captcha'  => array( '②', 'Captcha' ),
		'review'   => array( '③', 'Content moderation' ),
		'iprep'    => array( '④', 'IP reputation' ),
		'copy'     => array( '⑤', 'Copy Workshop' ),
		'stats'    => array( '⑥', 'Statistics' ),
		'tools'    => array( '⑦', 'Tools' ),
		// 【M5·UI-A】⑧「站点增强」顺延追加（UI 设计 D1 裁决——零重编号迁移；语义=
		// 影响站点前台行为的长期设置：网站字段/头像源。⑦=工具（一次性操作）、
		// ⑧=站点增强（长期设置）——hover/文档统一话术「⑧ 改的是站点长什么样，
		// ⑦ 做的是你想干的某件事」）。
		'enhance'  => array( '⑧', 'Site enhancements' ),
	);

	/**
	 * Bootstrap 装配期注入驱动注册表（Tab② 驱动卡片渲染消费，05 §4）。
	 *
	 * @param \WinkGatekeeper\Drivers\DriverRegistry $registry
	 * @return void
	 */
	public static function set_driver_registry( $registry ): void {
		self::$driver_registry = $registry;
	}

	/**
	 * 请求内设置树缓存（【A2-F3】Tab① 单请求 9-10 次全树 merge 收敛到 1——照抄
	 * StatsCache::$request_settings_cache 先例：同请求内设置树恒定〔保存走 sanitize
	 * 桥独立面、渲染纯读〕，merge 结果缓存一次复用；每请求独立静态=PHP 进程模型，
	 * 无并发语义影响。渲染读取行为零变化）。
	 *
	 * @var array<string, mixed>|null
	 */
	private static $request_settings_cache = null;

	/**
	 * 复位请求内设置树缓存（单测隔离桥；生产请求生命周期内无需显式调用——
	 * StatsCache::reset_request_cache 同款）。
	 *
	 * @return void
	 */
	public static function reset_request_settings_cache(): void {
		self::$request_settings_cache = null;
	}

	/**
	 * 当前设置树（option 读取 + 默认树补全，渲染单一数据源）。
	 *
	 * @return array<string, mixed>
	 */
	private static function settings(): array {
		if ( null === self::$request_settings_cache ) {
			$stored = get_option( self::OPTION_NAME, array() );
			self::$request_settings_cache = SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() );
		}
		return self::$request_settings_cache;
	}

	/**
	 * 注册设置（admin_init）：sanitize 回调桥到纯逻辑层。
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => SettingsSchema::defaults(),
			)
		);
	}

	/**
	 * sanitize 桥：单表单多 Tab 语义（仅活动 Tab 的字段随表单提交）。
	 *
	 * 合并序：已存储 option ⊕ 本次提交（提交键优先）→ 默认树补全——保存 Tab⑤ 不会
	 * 把 Tab② 的密钥抹回默认。password 型字段显式提交空串 = 保留已存值（「已配置✓」
	 * 不回显语义的写侧配套）；checkbox 带 hidden 0 伴随域（未勾选显式提交 0）。
	 *
	 * @param mixed $input 表单提交的设置数组。
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		// 【M6·批H·H-6】显式清除凭据旗标（渲染层 hidden 0 伴随域+独立 <leaf>_clear 键名）
		// 在清洗前剥除——旗标键不进设置树不进 rejected 报告；返回被勾选（旗标=1）的
		// password 点分路径列表（保存树收口阶段消费）。提交态为空的勾选路径记账（在
		// 「空串保留」之前——提交态语义，preserve 还原值不作数）。
		$clear_flags = self::strip_credential_clear_flags( $input );
		$clear_empty = array();
		foreach ( $clear_flags as $flag_path ) {
			$segments = explode( '.', $flag_path );
			if ( '' === (string) self::dig( is_array( $input ) ? $input : array(), $segments, '' ) ) {
				$clear_empty[] = $flag_path;
			}
		}

		list( $clean, $rejected ) = Sanitize::clean( $input );

		// M1：$rejected（点分路径列表）接入「已剥除未知字段」后台提示。
		if ( array() !== $rejected ) {
			/**
			 * 未知字段被剥除的通知钩子（预留）。
			 *
			 * @param string[] $rejected 被剥除的点分路径列表。
			 */
			do_action( 'wink_gatekeeper_settings_rejected', $rejected );
		}

		$stored = get_option( self::OPTION_NAME, array() );
		$stored = is_array( $stored ) ? $stored : array();

		// password 型空串显式提交 → 保留已存值（不回显键值的写侧语义）。
		foreach ( Sanitize::rules() as $path => $type ) {
			if ( 'password' !== $type ) {
				continue;
			}
			$segments = explode( '.', $path );
			$node     = &$clean;
			$present  = true;
			foreach ( $segments as $segment ) {
				if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
					$present = false;
					break;
				}
				$node = &$node[ $segment ];
			}
			if ( $present && '' === $node ) {
				$node = self::dig( $stored, $segments, '' );
			}
			unset( $node );
		}

		// 【M6·批H·H-6】清除旗标收口（在「空串保留」之后应用——显式清空覆盖保留语义）：
		// 勾选且提交值为空串 → 凭据置空串（渲染层「已配置✓」消失，U-1/黄条联动照常）；
		// 勾选但提交了新值 → 新值优先（显式替换语义，清除控件与换钥互不劫持）。
		foreach ( $clear_empty as $flag_path ) {
			self::assign_path( $clean, explode( '.', $flag_path ), '' );
		}

		$merged = self::merge_submitted( $stored, $clean );

		/* chain 复选框（C4，列表整体替换）：活动 Tab 提交了 review.cloud.chain（hidden 伴随域
		 * 使「未勾选」也显式提交——sanitize 后为空数组）→ 链表按提交值整体收口——
		 * 防「取消勾选被旧链表深合并回填」（merge_submitted 对空数组的 map 递归合并是 no-op）。 */
		$submitted_chain = self::dig( $clean, array( 'review', 'cloud', 'chain' ), null );
		if ( is_array( $submitted_chain ) ) {
			if ( ! is_array( $merged['review']['cloud'] ?? null ) ) {
				$merged['review']['cloud'] = array();
			}
			$merged['review']['cloud']['chain'] = $submitted_chain;
		}

		/* 【v1.5·C1】test_mode 生命周期（02 §2.8 门 #1/#5-①）：on/off 切换 → since 写
		 * time()（on）/清零（off）+ 落 test_mode_on|off 事件（gate=system 留痕——on/off/expired
		 * 三态全程可审计；record_lifecycle 内含纯单测环境守卫，纯 PHP 桥测试零依赖）。 */
		$stored_tm = (bool) self::dig( $stored, array( 'captcha', 'test_mode' ), false );
		$merged_tm = (bool) ( $merged['captcha']['test_mode'] ?? false );
		$lifecycle = \WinkGatekeeper\Gates\TestMode::lifecycle_rule( $stored_tm, $merged_tm );
		if ( null !== $lifecycle ) {
			$merged['captcha']['test_mode_since'] = $merged_tm ? time() : 0; // WP 边界时钟。
			\WinkGatekeeper\Gates\TestMode::record_lifecycle( $lifecycle );
		}

		/* 【AUDIT-FIX 2026-11-20·S4-F6】百度凭据清除/换钥联动删旧 token transient（派生凭证
		 * 生命周期与凭据本体对齐——残留 access_token 在 30 天 TTL 内仍可调百度审核接口）。 */
		self::maybe_delete_baidu_token_transient( $stored, $merged );

		return SettingsSchema::merge_defaults( $merged );
	}

	/**
	 * 百度凭据清除/换钥 → 联动删旧 token transient（【AUDIT-FIX 2026-11-20·S4-F6】sanitize 桥收口）。
	 *
	 * token 缓存键 = wk_baidu_token_ + sha256(api_key) 前 12（BaiduTextModerationDriver::
	 * token_cache_key 单一来源——不重写第二套键推导）。判定口径：旧树有 api_key 且
	 * (api_key 或 secret_key 任一变化) → 按旧 api_key 指纹删除该键 transient。
	 *   - 清除（勾选 clear + 留空 → api_key 置空串）：旧键删除（清除承诺覆盖派生凭证）；
	 *   - 换钥（A→B）：旧 A 键删除（新 B 键无缓存——首次使用自建，驱动 110/111 自愈通道兜底）；
	 *   - 仅换 Secret Key（同 API Key 键面）：同键删除（旧 token 对新凭据已失效）；
	 *   - 未变化 / 首配（旧树无凭据）：零联动（防每保存误删——token 缓存复用是计费防呆第一道）。
	 *
	 * WP 边界（delete_transient）——仅 sanitize 桥（管理上下文）执行；卸载面由 uninstall.php
	 * 步骤 4 的 wk_baidu_token_ 前缀清理承接（同批 P3-F5/A1-F9 补录）。
	 *
	 * @param array<string, mixed> $stored 已存储树（get_option 原值）。
	 * @param array<string, mixed> $merged 本次合并后树（清洗+清除旗标+merge_submitted 产物）。
	 * @return void
	 */
	private static function maybe_delete_baidu_token_transient( array $stored, array $merged ): void {
		$old = self::dig( $stored, array( 'review', 'cloud', 'baidu' ), array() );
		$new = self::dig( $merged, array( 'review', 'cloud', 'baidu' ), array() );

		$old_api    = is_array( $old ) && isset( $old['api_key'] ) && is_string( $old['api_key'] ) ? $old['api_key'] : '';
		$new_api    = is_array( $new ) && isset( $new['api_key'] ) && is_string( $new['api_key'] ) ? $new['api_key'] : '';
		$old_secret = is_array( $old ) && isset( $old['secret_key'] ) && is_string( $old['secret_key'] ) ? $old['secret_key'] : '';
		$new_secret = is_array( $new ) && isset( $new['secret_key'] ) && is_string( $new['secret_key'] ) ? $new['secret_key'] : '';

		if ( '' === $old_api ) {
			return; // 旧树无凭据（首配/从未配置）：无派生 token 可吊销。
		}
		if ( $old_api === $new_api && $old_secret === $new_secret ) {
			return; // 凭据未变化：零联动。
		}
		delete_transient( BaiduTextModerationDriver::token_cache_key( $old_api ) ); // WP 边界（测试经 WinkGatekeeper\Settings\delete_transient 桩承载）。
	}

	/**
	 * 已存储树 ⊕ 提交树（提交键优先；提交树未出现的键保留已存值）。
	 *
	 * C4 修正：列表值（如 review.cloud.chain——顺序整数键）整体替换不做按键深合并——
	 * 否则「取消勾选 chain 复选框（提交 []）」会被旧链表按键合并回填（取消不生效）。
	 *
	 * @param array<string, mixed> $stored  已存储 option。
	 * @param array<string, mixed> $clean   本次提交（已清洗）。
	 * @return array<string, mixed>
	 */
	private static function merge_submitted( array $stored, array $clean ): array {
		foreach ( $clean as $key => $value ) {
			if ( is_array( $value ) && isset( $stored[ $key ] ) && is_array( $stored[ $key ] )
				&& self::is_map( $value ) && self::is_map( $stored[ $key ] ) ) {
				$stored[ $key ] = self::merge_submitted( $stored[ $key ], $value );
			} else {
				$stored[ $key ] = $value; // 标量与列表：整体替换。
			}
		}
		return $stored;
	}

	/**
	 * 是否为 map（存在至少一个非顺序整数键 ⇒ map；空数组按 map 便于递归——沿
	 * SettingsSchema::is_map 同语义，chain 类顺序列表整体替换判定用）。
	 *
	 * @param mixed $value
	 * @return bool
	 */
	private static function is_map( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( array() === $value ) {
			return true;
		}
		return array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}

	/**
	 * 按路径段取值（缺键返回默认）。
	 *
	 * @param array    $tree
	 * @param string[] $segments
	 * @param mixed    $default
	 * @return mixed
	 */
	private static function dig( array $tree, array $segments, $default ) {
		$node = $tree;
		foreach ( $segments as $segment ) {
			if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
				return $default;
			}
			$node = $node[ $segment ];
		}
		return $node;
	}

	/**
	 * 【M6·批H·H-6】显式清除凭据旗标剥除（sanitize 桥清洗前置步）：遍历 password 型
	 * 规则路径，提交树同层若携带独立旗标键 <leaf>_clear（渲染层 hidden 0 伴随域+checkbox
	 * 形态）→ 剥除旗标键（不进设置树不进 rejected 报告）并按值记账（'1'=勾选清除）。
	 *
	 * @param mixed $input 表单提交树（引用——旗标键就地剥除；非数组安全退空）。
	 * @return string[] 被勾选的 password 点分路径列表。
	 */
	private static function strip_credential_clear_flags( &$input ): array {
		$flagged = array();
		if ( ! is_array( $input ) ) {
			return $flagged;
		}
		foreach ( Sanitize::rules() as $path => $type ) {
			if ( 'password' !== $type ) {
				continue;
			}
			// 【S4-F7/A1-F8】休眠键清除旗标不记账（休眠=写入路径整体冻结——伪造旗标不得
			// 清掉存量休眠凭据；旗标键本身仍剥除不进设置树）。
			if ( Sanitize::is_dormant( $path ) ) {
				continue;
			}
			$segments = explode( '.', $path );
			$leaf     = (string) end( $segments );
			$parent   = &self::dig_ref( $input, array_slice( $segments, 0, -1 ) );
			if ( is_array( $parent ) && array_key_exists( $leaf . '_clear', $parent ) ) {
				if ( '1' === (string) $parent[ $leaf . '_clear' ] ) {
					$flagged[] = $path;
				}
				unset( $parent[ $leaf . '_clear' ] );
			}
			unset( $parent );
		}
		return $flagged;
	}

	/**
	 * 按路径段取引用（缺中间节点就地建空数组——assign_path 配套）。
	 *
	 * @param array    $tree     树（引用）。
	 * @param string[] $segments 路径段。
	 * @return array|null 最深节点引用（越界标量=null）。
	 */
	private static function &dig_ref( array &$tree, array $segments ): ?array {
		$node = &$tree;
		foreach ( $segments as $segment ) {
			if ( ! is_array( $node ) ) {
				$null = null;
				return $null;
			}
			if ( ! array_key_exists( $segment, $node ) || ! is_array( $node[ $segment ] ) ) {
				$node[ $segment ] = array();
			}
			$node = &$node[ $segment ];
		}
		return $node;
	}

	/**
	 * 按路径段赋值（缺中间节点建空数组——清除凭据收口消费）。
	 *
	 * @param array    $tree     树（引用）。
	 * @param string[] $segments 路径段。
	 * @param mixed    $value    值。
	 * @return void
	 */
	private static function assign_path( array &$tree, array $segments, $value ): void {
		$leaf = array_pop( $segments );
		$node = &self::dig_ref( $tree, $segments );
		if ( is_array( $node ) && null !== $leaf ) {
			$node[ $leaf ] = $value;
		}
	}

	/**
	 * 【W3·批A·N7】admin color scheme 明暗定型（纯函数，design-system §02·P4 双路径
	 * 之 PHP 主路径）：scheme slug → 容器根 class。明度阈值二分以集合成员表承载
	 * （ADMIN_COLOR_DEEP_SCHEMES / ADMIN_COLOR_LIGHT_SCHEMES——优先明度阈值而非逐
	 * scheme 白名单选择器，规避 WP 版本增删 scheme 漂移）；两集合皆外的 slug（未知/
	 * 自定义 scheme）与空串（侦测失败）恒落暗色组=品牌语言现行态保底。
	 *
	 * 前端兜底=assets/js/admin-brand.js 首段按 body.admin-color-{scheme} 校验纠正
	 * （同集合表双写——两集合改动须同批同步 JS 与 W3SchemeTokensTest）。
	 *
	 * @param string $scheme wp_get_user_admin_color() 返回的 scheme slug（可空串）。
	 * @return string 'wk-scheme-dark'|'wk-scheme-light'。
	 */
	public static function admin_color_scheme_class( string $scheme ): string {
		$slug = (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( trim( $scheme ) ) );
		if ( '' === $slug ) {
			return 'wk-scheme-dark'; // 兜底条款：侦测失败恒暗色组（不出现无样式裸态）。
		}
		if ( in_array( $slug, self::ADMIN_COLOR_LIGHT_SCHEMES, true ) ) {
			return 'wk-scheme-light';
		}
		return 'wk-scheme-dark'; // deep 集合与其余未知 scheme 同落暗色组。
	}

	/**
	 * 设置页渲染入口（add_options_page 回调）。
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions: manage_options required.', 'wink-wp-gatekeeper' ) );
		}

		$scheme_class = self::admin_color_scheme_class( function_exists( 'wp_get_user_admin_color' ) ? (string) wp_get_user_admin_color() : '' );
		$active_tab   = self::active_tab();
		$version    = WINK_GATEKEEPER_VERSION;
		$stats      = \WinkGatekeeper\Stats\StatsCache::get();
		$today_rejects = 0;
		foreach ( $stats['by_gate'] as $counts ) {
			$today_rejects += (int) ( $counts['reject'] ?? 0 );
		}
		?>
		<div class="wrap wk-wrap <?php echo esc_attr( $scheme_class ); ?>">
			<header class="wk-header">
				<div class="wk-header-brand">
					<?php /* 【M6·批H4·H4-5】眨眼源属性退役（静态版 logo——用户拍板，手测二轮 F1）。 */ ?>
					<img
						id="wk-brand-logo"
						class="wk-logo"
						src="<?php echo esc_url( WINK_GATEKEEPER_URL . 'assets/brand/logo-wink.svg' ); ?>"
						alt="<?php esc_attr_e( 'Wink WP Gatekeeper', 'wink-wp-gatekeeper' ); ?>"
						width="40" height="45" decoding="async"
					/>
					<span class="wk-wordmark">Wink <em>WP</em> <?php esc_html_e( 'Gatekeeper', 'wink-wp-gatekeeper' ); ?></span>
					<span class="wk-tagline"><?php esc_html_e( 'It winks at real visitors and keeps its eyes open for bots.', 'wink-wp-gatekeeper' ); ?></span>
				</div>
				<div class="wk-header-meta">
					<span class="wk-badge"><?php echo esc_html( sprintf( /* translators: %s: 今日拦截数 */ __( 'Blocked today: %s', 'wink-wp-gatekeeper' ), (string) $today_rejects ) ); ?></span>
					<span class="wk-version"><?php echo esc_html( sprintf( /* translators: %s: 版本号 */ __( 'v%s', 'wink-wp-gatekeeper' ), $version ) ); ?></span>
				</div>
			</header>

			<?php if ( self::wizard_mode_active() ) : ?>
				<?php self::render_wizard(); // 【W3·批C·R5】向导模式：四步全屏面板替代 Tab 面（pending 自动 / wizard=1 手动）。 ?>
			<?php else : ?>
			<nav class="nav-tab-wrapper wk-tabs" aria-label="<?php esc_attr_e( 'Wink WP Gatekeeper settings sections', 'wink-wp-gatekeeper' ); ?>">
				<?php foreach ( self::TABS as $tab_key => $tab_def ) : ?>
					<a
						href="<?php echo esc_url( self::tab_url( $tab_key ) ); ?>"
						class="nav-tab<?php echo $tab_key === $active_tab ? ' nav-tab-active' : ''; ?>"
					>
						<?php
						// 输出固定常量（序号 + Tab 标题），无需转义；仍走 esc_html 保持统一纪律。
						// i18n 批 I-3：Tab 标题经 __() 翻译（const 常量不能内联调用——值=en 键，zh 走 po）。
						echo esc_html( $tab_def[0] . ' ' . __( $tab_def[1], 'wink-wp-gatekeeper' ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- const TABS 常量值=en 键（PHP 常量表达式禁函数调用）；msgid 皆目录键，pot 由 catalog 管线承载。
						?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php" class="wk-form">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<div class="wk-panel">
					<?php self::render_tab( $active_tab ); ?>
				</div>
				<?php submit_button( __( 'Save settings', 'wink-wp-gatekeeper' ) ); ?>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ===================================================================
	 * W3 批 A · render helper 区（施工图 §2 批 A「helper 公用化第一批」——
	 * 同文件私有方法，单文件口径不拆新 PHP 文件；既有 8 件私有 helper
	 * （password_field/field_name/truncate_reason/render_roster_card 等）先例续建。
	 * 纪律：建件必配迁段消费（无空接线）；迁段=一次等价改写，DOM 锚不变，
	 * 既有契约测试零翻转；其余消费面随批 B 各 Tab 重组迁完。
	 * =================================================================== */

	/**
	 * 黄条四级渲染件（W3 批 A 建件）。语义：黄=要留意、红=已出事、绿=报平安、
	 * 蓝=中性说明；「有话才出现」纪律由调用方判定（本件只负责形态）。
	 *
	 * 迁段等价口径：输出与现行 `<div class="notice notice-* inline">…</div>` 逐锚
	 * 同构（M6H3/M6C 等 source-scan 契约零翻转）——$hd/$p/$act 为**调用方已转义**
	 * 的原样 HTML 片段（本件不二次转义；字面文案与 esc/拼接留在编排位=锚字面量
	 * 不迁位）。$p 支持 string|string[]（数组=逐段一 <p>，hd 拼首段、act 拼末段）。
	 *
	 * @param string          $level   四级闭集 warn|danger|ok|info（NOTICE_LEVEL_CLASSES）。
	 * @param string          $hd      标题 HTML（含 <strong>/状态点 span，已转义）。
	 * @param string|string[] $p       正文 HTML（已转义；数组=多个 <p> 段）。
	 * @param string          $act     动作出口 HTML（<a> 链接，已转义）。
	 * @param string          $classes 迁段兼容位（【W3·批B 已退役】：批 A 迁段期显式 class
	 *                                 字面量位——批 B 契约翻转后编排位字面量清零，全量回落
	 *                                 $level 映射；参数形态保留作未来例外口，当前零调用传值）。
	 * @param string          $id      可选 DOM 锚 id（如 wk-overview-existing-bar）。
	 * @return void
	 */
	private static function notice_yellow_bar( string $level, string $hd = '', $p = '', string $act = '', string $classes = '', string $id = '' ): void {
		$cls   = isset( self::NOTICE_LEVEL_CLASSES[ $level ] ) ? self::NOTICE_LEVEL_CLASSES[ $level ] : self::NOTICE_LEVEL_CLASSES['warn'];
		$cls   = '' !== $classes ? $classes : $cls;
		$paras = is_array( $p ) ? $p : array( $p );
		$n     = count( $paras );
		echo '<div class="' . esc_attr( $cls ) . '"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>';
		foreach ( array_values( $paras ) as $i => $text ) {
			$piece = ( 0 === $i ? $hd : '' ) . $text . ( $n - 1 === $i ? $act : '' );
			echo '<p>' . $piece . '</p>';
		}
		echo '</div>';
	}

	/**
	 * 高级配置折叠渲染件（W3 批 A 建件；design-system §04 adv-fold——「⚙ 高级配置」
	 * 统一命名）。【W3·批B 翻转】折叠壳翻转为批 B 重组目标形态 `<details class="adv"
	 * {attrs}>`（⚙ 前缀由 CSS ::before 承载——summary 纯文本不加字面 ⚙）；默认收起
	 * 态（不渲染 open 属性）。$inner 用闭包承载，折叠内标记文本留在编排函数体内
	 * （source-scan 锚/字段 name 路径不迁位）。接入指引类引导折叠（guide 语境）不经
	 * 本件——保持 `<details class="wk-guide">` 原壳（guide≠adv，原型两形态并存）。
	 *
	 * @param string   $attrs 开标签属性片段（调用方常量，如 'id="wk-url-audit-advanced"'——
	 *                        含 id= 引号形态的原样字面量，M6B 系 blockBetween 起标记）。
	 * @param string   $title summary 标题纯文本（本件 esc_html）。
	 * @param callable $inner 折叠内标记渲染闭包（无参）。
	 * @return void
	 */
	private static function advanced_fold( string $attrs, string $title, callable $inner ): void {
		echo '<details class="adv" ' . $attrs . '><summary>' . esc_html( $title ) . '</summary>';
		$inner();
		echo "</details>\n";
	}

	/**
	 * 测试凭据钮渲染件（W3 批 A 建件；design-system §04 cred-test 新形态=单按钮 +
	 * 徽标五态 + 「上次测试」等宽小字位）。【W3·批C·N1】读真值：$state/$last_tested
	 * 传空且 $driver_key 在 PROBE_LOG_DRIVERS 闭集内时改读 probe log option 真值
	 * （有记录显真实态+等宽小字时间「m月d日 H:i · 状态词」；无记录=未测试中性灰初态）。
	 * 显式传 $state（测试桩/未来覆写位）优先于 option。
	 *
	 * $button_attrs/$result_attrs 为调用方常量字面量（'id="…"' 形态——各驱动按钮/
	 * 结果位 id 锚是 admin-brand.js 探活绑定与 source-scan 契约的定位面，不迁位）。
	 *
	 * @param string $button_attrs 按钮属性片段（含 id 锚）。
	 * @param string $result_attrs 结果位 span 属性片段（含 id 锚；aria-live 由本件补）。
	 * @param string $nonce        wp_create_nonce 产物（调用方生成——nonce action 字面量留编排位）。
	 * @param string $label        按钮文案（本件 esc_html）。
	 * @param string $driver_key   驱动 id（probe log 读取键——PROBE_LOG_DRIVERS 闭集内生效）。
	 * @param string $state        五态闭集 ''|green|yellow|red|gray（空=读 probe log）。
	 * @param string $last_tested  上次测试时间文案（date_i18n 预格式化；空=随 state 解析）。
	 * @return void
	 */
	private static function test_credential_btn( string $button_attrs, string $result_attrs, string $nonce, string $label, string $driver_key = '', string $state = '', string $last_tested = '' ): void {
		if ( '' === $state && '' !== $driver_key && in_array( $driver_key, self::PROBE_LOG_DRIVERS, true ) ) {
			list( $state, $last_tested ) = self::probe_log_badge( $driver_key ); // N1：option 真值（无记录=未测试）。
		}
		?>
		<p class="cred-test">
			<button type="button" class="button" <?php echo $button_attrs; // 调用方常量（id 锚，非用户输入）。 ?> data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php echo esc_html( $label ); ?></button>
			<span <?php echo $result_attrs; // 调用方常量（id 锚）。 ?> aria-live="polite"></span>
			<?php echo self::probe_state_badge( $state, $last_tested, $driver_key ); // 五态徽标闭集（本件转义；driver_key 随附=JS 回写定位面）。 ?>
		</p>
		<?php
	}

	/**
	 * 测试凭据五态徽标（design-system §04 cred-test 样张闭集）：未测试（中性灰初始态）/
	 * 绿贯通/黄缺字/红被拒/灰契约不符 + 「上次测试：时间 · 状态」等宽小字位。
	 * 状态词表已按 CredentialProbe 分类三态（绿贯通/黄缺字/红被拒）+ 契约灰档定稿（N1）。
	 * 【W3-FIX·0.8.6】$driver_key 非空时徽标 span 附 data-wk-driver-key（admin-brand.js
	 * 探活响应全量回写定位面——向导页同屏同驱动徽标多副本〔步二凭据面+步四上岗引导〕
	 * 场景必须按属性选择器批量命中；五态词/徽标类与 JS WK_PROBE_BADGE_STATES 双写同源，
	 * 两处改动须同批同步）。
	 *
	 * @param string $state       ''|green|yellow|red|gray（闭集外回落未测试）。
	 * @param string $last_tested 预格式化时间文案（空=「--」）。
	 * @param string $driver_key  驱动 id（空=不带键——无回写定位需求的历史位）。
	 * @return string HTML。
	 */
	private static function probe_state_badge( string $state, string $last_tested, string $driver_key = '' ): string {
		$states = array(
			''       => array( 'b-dim', __( 'Not tested', 'wink-wp-gatekeeper' ) ),
			'green'  => array( 'b-ok', __( 'Connected · credentials work', 'wink-wp-gatekeeper' ) ),
			'yellow' => array( 'b-warn', __( 'Incomplete · required fields missing', 'wink-wp-gatekeeper' ) ),
			'red'    => array( 'b-err', __( 'Rejected · key error', 'wink-wp-gatekeeper' ) ),
			'gray'   => array( 'b-dim', __( 'Contract mismatch · status unknown', 'wink-wp-gatekeeper' ) ),
		);
		$conf = isset( $states[ $state ] ) ? $states[ $state ] : $states[''];
		$last = '' !== $last_tested ? $last_tested : '--';
		$attr = '' !== $driver_key ? ' data-wk-driver-key="' . esc_attr( $driver_key ) . '"' : '';
		return '<span class="test-state"' . $attr . '><span class="badge ' . esc_attr( $conf[0] ) . '">' . esc_html( $conf[1] ) . '</span>'
			/* translators: %s: date and time of the last verification test */
			. '<span class="last-test">' . esc_html( sprintf( __( 'Last test: %s', 'wink-wp-gatekeeper' ), $last ) ) . '</span></span>';
	}

	/* -------------------------------------------------------------------
	 * N1 · 测试凭据持久化（probe log——R4 单 option 数组形态，本类内私有方法对，
	 * 不建新类；写入点=各 AJAX 测试凭据 handler 分类完成后、wp_send_json 前；
	 * message 不入库红线=落库面只有 state 枚举+tested_at 时间戳两键）。
	 * ------------------------------------------------------------------ */

	/**
	 * probe log 读件（option 数组；非数组防御回落空——「未测试」缺席态语义）。
	 *
	 * @return array<string, array{state: string, tested_at: int}>
	 */
	private static function probe_log_get(): array {
		$raw = get_option( self::OPTION_PROBE_LOG, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * 五态归一（纯函数，公开件——闭集纪律测试面）：green|yellow|red|gray 原样；
	 * 其余（含 ''/闭集外/非标量）一律归 ''（未测试缺席态，不落脏数据）。
	 *
	 * @param mixed $state 原始状态值。
	 * @return string ''|green|yellow|red|gray。
	 */
	public static function probe_log_normalize_state( $state ): string {
		return is_string( $state ) && in_array( $state, array( 'green', 'yellow', 'red', 'gray' ), true ) ? $state : '';
	}

	/**
	 * probe log 写件（per 驱动一槽覆盖写——每驱动一槽自限量、零 retention/清理口需求；
	 * message 不入库：只存 state 枚举+时间戳。闭集外驱动键/状态拒绝落库 fail-safe）。
	 *
	 * @param string $driver_key 驱动 id（PROBE_LOG_DRIVERS 闭集）。
	 * @param string $state      分类态（probe_log_normalize_state 归一）。
	 * @return void
	 */
	private static function probe_log_record( string $driver_key, string $state ): void {
		if ( ! in_array( $driver_key, self::PROBE_LOG_DRIVERS, true ) ) {
			return;
		}
		$state = self::probe_log_normalize_state( $state );
		if ( '' === $state ) {
			return; // 未测试缺席态不落库（空态不覆盖既有记录）。
		}
		$log                = self::probe_log_get();
		$log[ $driver_key ] = array(
			'state'     => $state,
			'tested_at' => time(), // WP 边界时钟。
		);
		update_option( self::OPTION_PROBE_LOG, $log, false ); // 【AUDIT-FIX 2026-11-20·P1-9/S4-F2】第三参 false——写点全量对齐（缺参=行缺失重建即漂移 autoload=auto，S4 确定性漂移口收口）。
	}

	/**
	 * probe log 徽标读值（test_credential_btn 消费）：返回五态 state + 「上次测试：
	 * m月d日 H:i · 状态词」文案（date_i18n；无记录=未测试「--」）。
	 *
	 * @param string $driver_key 驱动 id（闭集内）。
	 * @return array{0: string, 1: string} [state, last_tested]。
	 */
	private static function probe_log_badge( string $driver_key ): array {
		$entry = self::probe_log_get()[ $driver_key ] ?? null;
		if ( ! is_array( $entry ) || ! isset( $entry['state'] ) ) {
			return array( '', '' ); // 无记录=未测试中性灰（缺席态）。
		}
		$state = self::probe_log_normalize_state( $entry['state'] );
		if ( '' === $state ) {
			return array( '', '' );
		}
		$words = array(
			'green'  => __( 'Connected', 'wink-wp-gatekeeper' ),
			'yellow' => __( 'Incomplete', 'wink-wp-gatekeeper' ),
			'red'    => __( 'Rejected', 'wink-wp-gatekeeper' ),
			'gray'   => __( 'Contract mismatch', 'wink-wp-gatekeeper' ),
		);
		$ts    = isset( $entry['tested_at'] ) && is_numeric( $entry['tested_at'] ) ? (int) $entry['tested_at'] : 0;
		$last  = $ts > 0
			? date_i18n( __( 'M j, H:i', 'wink-wp-gatekeeper' ), $ts ) . ' · ' . ( $words[ $state ] ?? $state )
			: ( $words[ $state ] ?? $state ); // 时间戳缺失防御：只显状态词。
		return array( $state, $last );
	}

	/**
	 * 调用量卡渲染件（W3 批 A 建件；design-system §04 usage-card 五域样张）。
	 * 视图模型=DashboardDigest::widget_overview()['usage']（includes/Stats/
	 * DashboardDigest.php——纯逻辑零新查询；本件只管 echo 形态）。渲染纪律：
	 * 五域固定顺序、每域只列实际启用驱动行、零数据域不渲染（空态显式「今日无
	 * 外呼」）、bill_free 声明行无次数（M6·批H·H-4 口径同源）。
	 *
	 * @param array<string, mixed> $usage {domains: [{id,label,rows:[{driver_id,label,used,quota,quota_reached,bill_free}]}], site_total: int, disaster_pass: int}。
	 * @return void
	 */
	private static function usage_card_html( array $usage ): void {
		$domains   = isset( $usage['domains'] ) && is_array( $usage['domains'] ) ? $usage['domains'] : array();
		$site_total = isset( $usage['site_total'] ) && is_numeric( $usage['site_total'] ) ? (int) $usage['site_total'] : 0;
		$disaster   = isset( $usage['disaster_pass'] ) && is_numeric( $usage['disaster_pass'] ) ? (int) $usage['disaster_pass'] : 0;
		?>
		<section class="usage-card" id="wk-overview-usage">
			<div class="usage-hd"><?php esc_html_e( 'API calls today', 'wink-wp-gatekeeper' ); ?><span class="sum"><?php echo esc_html( sprintf( /* translators: %d: 站点今日总外呼次数 */ __( 'Total site outbound calls: %d', 'wink-wp-gatekeeper' ), $site_total ) ); ?></span></div>
			<?php if ( array() === $domains ) : ?>
				<div class="usage-row free"><?php esc_html_e( 'No outbound calls today.', 'wink-wp-gatekeeper' ); ?></div>
			<?php else : ?>
				<?php foreach ( $domains as $domain ) : ?>
					<?php
					if ( ! is_array( $domain ) || ! isset( $domain['rows'] ) || ! is_array( $domain['rows'] ) ) {
						continue;
					}
					?>
					<div class="usage-domain"><?php echo esc_html( (string) ( $domain['label'] ?? '' ) ); ?></div>
					<?php foreach ( $domain['rows'] as $row ) : ?>
						<?php
						if ( ! is_array( $row ) ) {
							continue;
						}
						$used    = isset( $row['used'] ) && is_numeric( $row['used'] ) ? (int) $row['used'] : 0;
						$quota   = isset( $row['quota'] ) && is_numeric( $row['quota'] ) ? (int) $row['quota'] : 0;
						$reached = ! empty( $row['quota_reached'] );
						?>
						<?php if ( ! empty( $row['bill_free'] ) ) : ?>
							<div class="usage-row free"><?php echo esc_html( (string) ( $row['label'] ?? '' ) ); ?></div>
						<?php else : ?>
							<div class="usage-row">
								<span class="nm"><b><?php echo esc_html( (string) ( $row['label'] ?? '' ) ); ?></b></span>
								<?php if ( $quota > 0 ) : ?>
									<span class="num"><?php echo esc_html( sprintf( '%d', $used ) ); ?></span>
									<span class="prog" aria-hidden="true"><i style="width:<?php echo esc_attr( (string) self::usage_pct( $used, $quota ) ); ?>%"></i></span>
									<span class="quota"><?php echo esc_html( sprintf( '%d / %d', $used, $quota ) ); ?><?php echo $reached ? esc_html__( '— quota reached, paused for today', 'wink-wp-gatekeeper' ) : ''; ?></span>
								<?php else : ?>
									<span class="num"><?php echo esc_html( sprintf( /* translators: %d: 调用次数 */ _x( '%d calls', 'call-count', 'wink-wp-gatekeeper' ), $used ) ); ?></span>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					<?php endforeach; ?>
				<?php endforeach; ?>
			<?php endif; ?>
			<div class="usage-row free"><?php echo esc_html( __( 'Failover passes today', 'wink-wp-gatekeeper' ) ); ?> <?php echo esc_html( sprintf( '%d', $disaster ) ); ?><?php echo esc_html( __( ' times', 'wink-wp-gatekeeper' ) ); ?><?php echo $disaster > 0 ? esc_html__( '— a barometer of captcha service instability', 'wink-wp-gatekeeper' ) : ''; ?></div>
		</section>
		<?php
	}

	/**
	 * 进度百分比纯函数（usage-card prog 位——quota>0 才调；上限 100 防溢出）。
	 *
	 * @param int $used  已用计数。
	 * @param int $quota 配额（>0）。
	 * @return int 0–100。
	 */
	private static function usage_pct( int $used, int $quota ): int {
		if ( $quota <= 0 ) {
			return 0;
		}
		return (int) min( 100, round( ( $used / $quota ) * 100 ) );
	}

	/**
	 * 报告卡渲染件（W3 批 A 建件；Tab⑦ 存量审核报告卡等价收口——三票面 wk-report-ticket
	 * + 原因明细折叠既有结构）。迁段等价口径：全部文案/锚 id/票面词/动作链接/原因行
	 * 由调用方组装传入（XSS 红线=原因攻击者可控，esc_html+截断留在编排位），本件只
	 * 负责结构形态与既有 DOM 逐锚同构。
	 *
	 * @param array<string, mixed> $view {
	 *   state           'empty'|其他（空态只渲染 empty 行）。
	 *   section_id/title/empty_id/empty_text/meta_id/meta_text/tickets_class/caliber_id
	 *   tickets         array<{name,main,sub}>（纯文本，本件 esc_html）。
	 *   actions_html    动作行 HTML（调用方已 esc_url/esc_html 组装，原样输出）。
	 *   recovery_text   恢复口径行纯文本（本件 esc_html）。
	 *   reasons_id/reasons_summary/reasons_html 原因折叠（HTML 调用方组装，原样输出）。
	 * }
	 * @return void
	 */
	private static function report_card( array $view ): void {
		?>
		<section class="wk-card" id="<?php echo esc_attr( (string) $view['section_id'] ); ?>">
			<h2><?php echo esc_html( (string) $view['title'] ); ?></h2>
			<?php if ( 'empty' === (string) $view['state'] ) : ?>
				<p id="<?php echo esc_attr( (string) $view['empty_id'] ); ?>" class="wk-muted"><?php echo esc_html( (string) $view['empty_text'] ); ?></p>
			<?php else : ?>
				<p id="<?php echo esc_attr( (string) $view['meta_id'] ); ?>" class="wk-muted"><?php echo esc_html( (string) $view['meta_text'] ); ?></p>
				<div class="<?php echo esc_attr( (string) $view['tickets_class'] ); ?>" id="<?php echo esc_attr( (string) $view['caliber_id'] ); ?>">
					<?php foreach ( (array) $view['tickets'] as $ticket ) : ?>
						<div class="wk-report-ticket">
							<p class="wk-report-ticket__name"><strong><?php echo esc_html( (string) $ticket['name'] ); ?></strong></p>
							<p class="wk-report-ticket__main"><?php echo esc_html( (string) $ticket['main'] ); ?></p>
							<p class="wk-muted"><?php echo esc_html( (string) $ticket['sub'] ); ?></p>
						</div>
					<?php endforeach; ?>
				</div>
				<p class="wk-overview-actions"><?php echo (string) $view['actions_html']; // 调用方已转义组装。 ?></p>
				<p class="wk-muted"><?php echo esc_html( (string) $view['recovery_text'] ); ?></p>
				<details id="<?php echo esc_attr( (string) $view['reasons_id'] ); ?>">
					<summary><?php echo esc_html( (string) $view['reasons_summary'] ); ?></summary>
					<?php echo (string) $view['reasons_html']; // 调用方已转义组装（esc_html+截断）。 ?>
				</details>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * 当前激活 Tab（白名单外的取值回落总览）。
	 *
	 * @return string
	 */
	private static function active_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读 Tab 导航展示，无写操作。
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return array_key_exists( $tab, self::TABS ) ? $tab : 'overview';
	}

	/**
	 * @param string $tab Tab 键。
	 * @return string
	 */
	private static function tab_url( string $tab ): string {
		return add_query_arg(
			array( 'page' => self::PAGE_SLUG, 'tab' => $tab ),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * @param string $tab Tab 键（active_tab 白名单后取值）。
	 * @return void
	 */
	private static function render_tab( string $tab ): void {
		switch ( $tab ) {
			case 'captcha':
				self::render_tab_captcha();
				break;
			case 'review':
				self::render_tab_review();
				break;
			case 'iprep':
				self::render_tab_iprep();
				break;
			case 'copy':
				self::render_tab_copy();
				break;
			case 'stats':
				self::render_tab_stats();
				break;
			case 'tools':
				self::render_tab_tools();
				break;
			case 'enhance':
				self::render_tab_enhance();
				break;
			case 'overview':
			default:
				self::render_tab_overview();
				break;
		}
	}

	/* -------------------------------------------------------------------
	 * Tab① 总览（UI 批 3·B 提案五块——点名册形态，v0.4.0）
	 *   ① 站务提醒通栏（无卡壳，有话才出现）
	 *   ② 今日战报（三闸小票 + 待审/垃圾箱行动行）
	 *   ③ 服务商点名册（registry 全集×配置树×DriverHealth×Usage 渲染侧推导——零存储变更）
	 *   ④ 最近岔子（批 2 N2∪N3 合并时间线的紧凑形态，入口性质 5 条）
	 *   ⑤ 站点底账（一行灰字带）
	 *
	 * 分层纪律（B 提案 §6）：Tab① 只看今天、每数字一句人话、无表格化明细（明细归
	 * Tab⑥）；唯一合法重叠=页头「今日拦截 N」徽标。术语全走 Labels 映射（对照表
	 * 资产）；点名册直白命名（统筹裁决 1：不用保安比喻，系统层叫「服务商」）。
	 * ------------------------------------------------------------------ */

	private static function render_tab_overview(): void {
		self::render_overview_onboarding();
		self::render_overview_alerts();
		self::render_overview_report();
		self::render_overview_usage(); // 【W3·批A】调用量卡新落位（原型 Tab① 六块序=战报→调用量→点名册）。
		self::render_overview_roster();
		self::render_overview_incidents();
		self::render_overview_footer();
	}

	/**
	 * 块 B'·调用量卡（W3 批 A 新落位——原型 Tab① 调用量卡五域；现码 Tab① 原无此卡）。
	 * 渲染薄委托：视图模型=DashboardDigest::widget_overview()['usage']（纯逻辑零新
	 * 查询——只读 option 计数器，不变式见 DashboardDigest 类头注）；形态=usage_card_html
	 * helper（usage-card 组件族）。Widget 侧 Bootstrap::render_dashboard_widget 形态为
	 * 参照不迁（跨文件口径——widget 保持自渲染）。
	 *
	 * @return void
	 */
	private static function render_overview_usage(): void {
		$settings       = self::settings();
		$captcha_driver = is_string( $settings['captcha']['driver'] ?? null ) && '' !== $settings['captcha']['driver']
			? $settings['captcha']['driver'] : 'aliyun'; // 【M6·批H·H-3】防御回落同款（usage 行驱动 id）。

		// 用量行人话名（registry_entry label 单一来源——Bootstrap widget 装配同款映射，
		// 不硬抄字面；未注册 id 经 DashboardDigest 回落原名不虚构）。
		$driver_labels = array(
			'tencent'          => (string) TencentCaptchaDriver::registry_entry()['label'],
			'aliyun'           => (string) AliyunCaptchaDriver::registry_entry()['label'],
			'tencent_tms'      => (string) TencentTmsDriver::registry_entry()['label'],
			'aliyun_green'     => (string) AliyunGreenPlusDriver::registry_entry()['label'],
			'baidu'            => (string) BaiduTextModerationDriver::registry_entry()['label'],
			'deepseek'         => (string) \WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver::registry_entry()['label'],
			'openai_custom'    => (string) \WinkGatekeeper\Drivers\Review\OpenAiCustomReviewDriver::registry_entry()['label'],
			'abuseipdb'        => 'AbuseIPDB',
			'abuseipdb_report' => __( 'AbuseIPDB (reporting)', 'wink-wp-gatekeeper' ),
		);
		foreach ( self::future_review_drivers() as $future_id => $future_meta ) {
			$driver_labels[ (string) $future_id ] = isset( $future_meta['label'] ) && is_string( $future_meta['label'] )
				? $future_meta['label'] : (string) $future_id;
		}

		$view = \WinkGatekeeper\Stats\DashboardDigest::widget_overview( array(
			'settings'             => $settings,
			'stats'                => \WinkGatekeeper\Stats\StatsCache::get(),
			'usage'                => \WinkGatekeeper\Stats\Usage::get(),
			'health'               => array(), // 本块只消费 usage 视图（health 卡归 Widget）。
			'today_ymd'            => current_time( 'Ymd' ),
			'today_dash'           => current_time( 'Y-m-d' ),
			'captcha_configured'   => false,
			'captcha_driver'       => $captcha_driver,
			'captcha_driver_label' => isset( $driver_labels[ $captcha_driver ] ) ? $driver_labels[ $captcha_driver ] : $captcha_driver,
			'driver_labels'        => $driver_labels,
			'cron_alive'           => true,
			'debug'                => false,
		) );
		self::usage_card_html( $view['usage'] );
	}

	/**
	 * 页顶入口卡（【W3·批B】onboarding 通栏与「继续配置」常驻入口**合并为一张卡**
	 * ——施工图 §2 批 B Tab① 行：两者同判定源 overview_configured_count，不双显）。
	 *
	 * 两态（同一卡槽 id="wk-overview-entry-card"）：
	 *   - 未配置（===0）：三步引导黄条形态（配好任意一家自动消失——原 onboarding
	 *     语义原样保留，判定复用 overview_configured_count）；【W3·批C】入口改指首启向导；
	 *   - 已配置：ghost 弱化形态常驻（P6 常驻入口·R5——【W3·批C】向导已实装，本入口
	 *     改指向 wizard 重进入口（state=done/skipped 也可再进）。
	 *
	 * @return void
	 */
	private static function render_overview_onboarding(): void {
		$settings   = self::settings();
		$configured = 0 !== self::overview_configured_count( $settings );
		?>
		<section id="wk-overview-entry-card"<?php echo $configured ? ' class="wk-card wk-card--ghost"' : ''; ?>>
			<?php if ( ! $configured ) : ?>
				<?php
				// 【W3·批A 迁段→批B 翻转】classes 兼容位退役，回落 warn 级插件级黄条类；
				// 文案/esc/链接组装留在编排位（等价迁移）；【W3·批C】入口改指向导。
				self::notice_yellow_bar(
					'warn',
					'<strong>' . esc_html__( 'Start protection in three steps', 'wink-wp-gatekeeper' ) . '</strong>',
					'——① ' . esc_html__( 'Pick a captcha (Tab 2: Captcha)', 'wink-wp-gatekeeper' )
						. ' → ② ' . esc_html__( 'Add cloud moderation / IP reputation (Tab 3: Content moderation / Tab 4: IP reputation)', 'wink-wp-gatekeeper' )
						. ' → ③ ' . esc_html__( 'Use “Test credentials” on each provider card to verify connectivity.', 'wink-wp-gatekeeper' )
						. esc_html__( 'This notice disappears once any provider is configured.', 'wink-wp-gatekeeper' ),
					' <a href="' . esc_url( self::wizard_url() ) . '">' . esc_html__( 'Start from step 1 →', 'wink-wp-gatekeeper' ) . '</a>'
				);
				?>
			<?php else : ?>
				<p class="wk-muted"><?php esc_html_e( 'Protection is on duty. To add captcha, cloud moderation, or IP reputation later, rerun the setup wizard at any time (or fine-tune things directly on each tab).', 'wink-wp-gatekeeper' ); ?>
					<a href="<?php echo esc_url( self::wizard_url() ); ?>"><?php esc_html_e( 'Continue setup (wizard) →', 'wink-wp-gatekeeper' ); ?></a></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * 【W3·批C·R5】向导重进入口 URL（纯件）：page=设置页 + wizard=1 + step（白名单
	 * 1–4 越界回落 1）。wizard=1 手动入口不受向导状态限制（done/skipped 也可再进——
	 * proto-wizard 步四「重新走一遍向导」佐证）。
	 *
	 * @param int $step 步序（1–4）。
	 * @return string
	 */
	public static function wizard_url( int $step = 1 ): string {
		$step = ( $step >= 1 && $step <= 4 ) ? $step : 1;
		return add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'wizard' => '1',
				'step'   => (string) $step,
			),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * 向导状态读件（WP 边界）：option 三态白名单，缺失/闭集外归一无记录 ''
	 * （存量升级站 option 恒缺失=永不自动弹——R5 差分语义的读取面）。
	 *
	 * @return string ''|pending|done|skipped。
	 */
	public static function wizard_state(): string {
		$raw = get_option( self::OPTION_WIZARD_STATE, '' );
		return is_string( $raw ) && in_array( $raw, self::WIZARD_STATES, true ) ? $raw : '';
	}

	/**
	 * auto-open 纯判定件（R5 差分语义——单测面）：state==='pending' 且无显式 tab 参数
	 * 才自动弹；done/skipped/无记录（存量站）恒不弹。
	 *
	 * @param string $state             向导状态（wizard_state 读值）。
	 * @param bool   $tab_param_present GET 是否携带显式 tab 参数。
	 * @return bool
	 */
	public static function wizard_should_auto_open( string $state, bool $tab_param_present ): bool {
		return self::WIZARD_STATE_PENDING === $state && ! $tab_param_present;
	}

	/**
	 * 向导出口动作 → 状态映射（纯件）：done→done / skip→skipped；闭集外拒绝（不可逆
	 * 态只认两个闭集入口）。
	 *
	 * @param string $action 出口动作（done|skip）。
	 * @return string ''|done|skipped（''=不写）。
	 */
	public static function wizard_state_for_action( string $action ): string {
		if ( 'done' === $action ) {
			return self::WIZARD_STATE_DONE;
		}
		if ( 'skip' === $action ) {
			return self::WIZARD_STATE_SKIPPED;
		}
		return '';
	}

	/**
	 * 向导模式判定（render 入口；active_tab 旁 wizard 分流）：
	 *   ① manual：GET wizard=1（Tab① 常驻入口卡/步四「重新走一遍」——done/skipped 也可再进）；
	 *   ② auto：wizard_should_auto_open（pending + 无显式 tab——新装激活后首次进入）。
	 *
	 * @return bool
	 */
	private static function wizard_mode_active(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读导航展示参数，无写操作。
		$manual = isset( $_GET['wizard'] ) && '1' === sanitize_key( wp_unslash( (string) $_GET['wizard'] ) );
		if ( $manual ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读导航展示参数，无写操作。
		$has_tab = isset( $_GET['tab'] );
		return self::wizard_should_auto_open( self::wizard_state(), $has_tab );
	}

	/**
	 * 【W3·批C·R5】向导完成出口处理器（admin_post_{ACTION_WIZARD_DONE}）：
	 * manage_options + check_admin_referer 双闸（GET 链接 wp_nonce_url 形态——
	 * reactivate_cron 先例）；置 done（不可逆——无重置 UI，R5）+ 重定向回设置页 Tab①。
	 *
	 * @return void
	 */
	public static function handle_wizard_finish(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wink-wp-gatekeeper' ), 403 );
		}
		check_admin_referer( self::ACTION_WIZARD_DONE );
		update_option( self::OPTION_WIZARD_STATE, self::WIZARD_STATE_DONE, false );
		// 出口去向白名单（步四双出口：进入总览=主 / 去文案工坊=品牌彩蛋位——proto-wizard 步四）。
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce 已上行 check_admin_referer 校验。
		$to = isset( $_GET['to'] ) ? sanitize_key( wp_unslash( (string) $_GET['to'] ) ) : 'overview';
		wp_safe_redirect( self::tab_url( array_key_exists( $to, self::TABS ) ? $to : 'overview' ) );
		exit;
	}

	/**
	 * 向导跳过出口（admin_post_{ACTION_WIZARD_SKIP}）：跳过=不提交表单、直接置 skipped
	 * + 跳 Tab①（R5 附案——跳过的域保持未配置，Tab① 兜底黄条照常）。
	 *
	 * @return void
	 */
	public static function handle_wizard_skip(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wink-wp-gatekeeper' ), 403 );
		}
		check_admin_referer( self::ACTION_WIZARD_SKIP );
		update_option( self::OPTION_WIZARD_STATE, self::WIZARD_STATE_SKIPPED, false );
		wp_safe_redirect( self::tab_url( 'overview' ) );
		exit;
	}

	/* ===================================================================
	 * 【W3·批C·R5/N5/P5/P6】首启向导（4 步：欢迎/人机验证/内容审核/完成）。
	 * 同页渲染模式（R5 附案）：向导=SettingsPage.php 内 render 分组（单文件口径），
	 * 复用 wk-form 主保存通道（settings_fields + options.php）——「下一步/完成」=
	 * submit 变体（提交→sanitize→_wp_http_referer 指向下一步——每步落盘、中途关闭
	 * 不丢已填）；「跳过全部」=不提交表单、直接置 skipped（handle_wizard_skip）。
	 * 向导内字段名与 Tab②③④ 完全同名同键（N5 红线：零新 schema 键）；WP 原生两
	 * 开关只读黄条=真值展示零本插件表单字段（:2131 红线照守）。stepper/面板切换=
	 * 纯显隐零提交语义（admin-brand.js 向导模块）；无 JS=仅当前步可见+步进钮照常
	 * 工作（渐进增强）。向导页无前台验证码容器，不受 gatekeeper.js submit-gate 影响。
	 * =================================================================== */

	/**
	 * 向导渲染分组（wizard_mode_active() 为真时由 render() 调用；步序 GET step 白名单
	 * 1–4 越界回落 1——面板服务端 hidden 定当前步，JS stepper 点击纯显隐切换）。
	 *
	 * @return void
	 */
	private static function render_wizard(): void {
		$settings = self::settings();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读步进展示参数，无写操作。
		$step = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 1;
		$step = ( $step >= 1 && $step <= 4 ) ? $step : 1;

		$aliyun   = is_array( $settings['captcha']['aliyun'] ?? null ) ? $settings['captcha']['aliyun'] : array();
		$cloud    = is_array( $settings['review']['cloud'] ?? null ) ? $settings['review']['cloud'] : array();
		$chain    = is_array( $cloud['chain'] ?? null ) ? $cloud['chain'] : array();
		$selected = in_array( 'baidu', $chain, true ) ? 'baidu'
			: ( in_array( 'deepseek', $chain, true ) ? 'deepseek'
			: ( in_array( 'aliyun_green', $chain, true ) ? 'aliyun_green'
			: ( in_array( 'tencent_tms', $chain, true ) ? 'tencent_tms'
			: ( in_array( 'openai_custom', $chain, true ) ? 'openai_custom' : '' ) ) ) );
		$captcha_driver = is_string( $settings['captcha']['driver'] ?? null ) ? $settings['captcha']['driver'] : '';
		?>
		<div class="wk-wizard" id="wk-wizard" data-wk-current="<?php echo esc_attr( (string) $step ); ?>">
			<p class="wizard-head">
				<span class="wk-wordmark">Wink <em>WP</em> <?php esc_html_e( 'Gatekeeper', 'wink-wp-gatekeeper' ); ?></span>
				<span class="wk-muted">· <?php esc_html_e( 'First day on duty', 'wink-wp-gatekeeper' ); ?></span>
			</p>

			<ol class="wk-stepper">
				<li data-wk-goto="1"<?php echo 1 === $step ? ' class="on"' : ''; ?>><?php esc_html_e( 'Welcome', 'wink-wp-gatekeeper' ); ?></li>
				<li data-wk-goto="2"<?php echo 2 === $step ? ' class="on"' : ''; ?>><?php esc_html_e( 'Human verification', 'wink-wp-gatekeeper' ); ?></li>
				<li data-wk-goto="3"<?php echo 3 === $step ? ' class="on"' : ''; ?>><?php esc_html_e( 'Content moderation', 'wink-wp-gatekeeper' ); ?></li>
				<li data-wk-goto="4"<?php echo 4 === $step ? ' class="on"' : ''; ?>><?php esc_html_e( 'Done', 'wink-wp-gatekeeper' ); ?></li>
			</ol>

			<form method="post" action="options.php" class="wk-form" id="wk-wizard-form">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<input type="hidden" name="_wp_http_referer" id="wk-wizard-referer" value="<?php echo esc_url( self::wizard_url( $step ) ); ?>" />

				<?php /* ---------- 步 1 · 欢迎（零字段——纯引导+跳过承诺） ---------- */ ?>
				<section class="wk-step-panel" data-wk-step="1"<?php echo 1 !== $step ? ' hidden' : ''; ?>>
					<h2><?php esc_html_e( 'On duty in three steps, five minutes', 'wink-wp-gatekeeper' ); ?></h2>
					<p class="wk-muted"><?php esc_html_e( 'Wink WP Gatekeeper stands at your blog\'s door: it blocks spam comments, stops credential-stuffing logins, and routes suspicious content to manual review. This wizard walks you through the two most important keys — you can skip any step and come back later; skipped parts keep fallback protection, so you are never left exposed.', 'wink-wp-gatekeeper' ); ?></p>
					<ul class="wk-checklist">
						<li><?php esc_html_e( 'STEP 1 Human verification — pick a captcha provider to keep bots out. Real visitors barely notice it.', 'wink-wp-gatekeeper' ); ?></li>
						<li><?php esc_html_e( 'STEP 2 Content moderation — the built-in rules are free and already on duty; add a cloud moderation provider so machine-crafted wording cannot slip through either.', 'wink-wp-gatekeeper' ); ?></li>
						<li><?php esc_html_e( 'STEP 3 Test your credentials — click “Test credentials” after configuring each provider; green means on duty.', 'wink-wp-gatekeeper' ); ?></li>
					</ul>
					<?php
					self::notice_yellow_bar(
						'info',
						'',
						esc_html__( 'The built-in rules alone are enough — cloud moderation is an enhancement layer that leaves basic protection untouched; when enabled, comments the built-in rules do not block get one more round of cloud review.', 'wink-wp-gatekeeper' )
					);
					?>
					<div class="wizard-foot">
						<span class="wk-muted"><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_WIZARD_SKIP ), self::ACTION_WIZARD_SKIP ) ); ?>"><?php esc_html_e( 'Not now — go straight to settings (skip everything) →', 'wink-wp-gatekeeper' ); ?></a></span>
						<a class="button button-primary" href="<?php echo esc_url( self::wizard_url( 2 ) ); ?>"><?php esc_html_e( 'Start step 1 →', 'wink-wp-gatekeeper' ); ?></a>
					</div>
				</section>

				<?php /* ---------- 步 2 · 人机验证（驱动 radio 三卡 + 阿里四键 + AbuseIPDB 折叠卡 + submit_flow radio + 动图） ---------- */ ?>
				<section class="wk-step-panel" data-wk-step="2"<?php echo 2 !== $step ? ' hidden' : ''; ?>>
					<h2><?php esc_html_e( 'Step 1 · Human verification', 'wink-wp-gatekeeper' ); ?></h2>
					<p class="wk-muted"><?php esc_html_e( 'Pick a captcha provider. For sites serving visitors in mainland China the default is recommended — it loads fastest there.', 'wink-wp-gatekeeper' ); ?></p>
					<div class="radio-cards" data-wk-radio-group="captcha-driver">
						<label class="radio-card<?php echo 'aliyun' === $captcha_driver ? ' on' : ''; ?>">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'driver' ) ) ); ?>" value="aliyun" data-wk-driver-toggle="aliyun" <?php checked( 'aliyun', $captcha_driver ); ?> />
							<span class="rc-hd"><span class="rc-dot"></span><?php esc_html_e( 'Aliyun Captcha 2.0', 'wink-wp-gatekeeper' ); ?></span>
							<span class="rc-tag"><?php esc_html_e( 'Recommended · Default', 'wink-wp-gatekeeper' ); ?></span>
							<span class="rc-bd"><?php esc_html_e( 'Fast mainland direct connection · generous free tier · smart verification that leaves humans alone.', 'wink-wp-gatekeeper' ); ?></span>
						</label>
						<label class="radio-card<?php echo 'tencent' === $captcha_driver ? ' on' : ''; ?>">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'driver' ) ) ); ?>" value="tencent" data-wk-driver-toggle="tencent" <?php checked( 'tencent', $captcha_driver ); ?> />
							<span class="rc-hd"><span class="rc-dot"></span><?php esc_html_e( 'Tencent Tianyu 2.0', 'wink-wp-gatekeeper' ); ?></span>
							<span class="rc-bd"><?php esc_html_e( 'Retired as default: the account plan has been discontinued and it is no longer recommended — sites already configured can keep using it.', 'wink-wp-gatekeeper' ); ?></span>
						</label>
						<label class="radio-card<?php echo '' === $captcha_driver ? ' on' : ''; ?>">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'driver' ) ) ); ?>" value="" data-wk-driver-toggle="none" <?php checked( '', $captcha_driver ); ?> />
							<span class="rc-hd"><span class="rc-dot"></span><?php esc_html_e( 'Not now', 'wink-wp-gatekeeper' ); ?></span>
							<span class="rc-bd"><?php esc_html_e( 'The comment gate keeps its four built-in rule defenses; you can configure a captcha later on the “2: Captcha” tab.', 'wink-wp-gatekeeper' ); ?></span>
						</label>
					</div>

					<div class="wizard-frow" data-wk-driver-face="aliyun"<?php echo 'aliyun' === $captcha_driver ? '' : ' hidden'; ?>>
						<?php /* 阿里四键最小面——字段 id 与 Tab② 同名（admin-brand.js 阿里探活绑定面复用）。 */ ?>
						<p><strong><?php esc_html_e( 'AccessKeyId / AccessKeySecret (Aliyun RAM)', 'wink-wp-gatekeeper' ); ?></strong></p>
						<?php self::password_field( array( 'captcha', 'aliyun', 'access_key_id' ), '' !== (string) ( $aliyun['access_key_id'] ?? '' ), 'AccessKeyId' ); ?>
						<?php self::password_field( array( 'captcha', 'aliyun', 'access_key_secret' ), '' !== (string) ( $aliyun['access_key_secret'] ?? '' ), 'AccessKeySecret' ); ?>
						<p><label for="wk-aliyun-scene-id"><strong><?php esc_html_e( 'SceneId (scene ID · required)', 'wink-wp-gatekeeper' ); ?></strong></label></p>
						<input type="text" id="wk-aliyun-scene-id" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'aliyun', 'scene_id' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $aliyun['scene_id'] ?? '' ) ); ?>" class="regular-text" data-wk-required="1" data-wk-label="SceneId" />
						<p><label for="wk-aliyun-prefix"><strong><?php esc_html_e( 'prefix (front-end identity tag · required)', 'wink-wp-gatekeeper' ); ?></strong></label></p>
						<input type="text" id="wk-aliyun-prefix" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'aliyun', 'prefix' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $aliyun['prefix'] ?? '' ) ); ?>" class="regular-text" data-wk-required="1" data-wk-label="prefix" />
						<p class="description"><?php esc_html_e( 'Where to get them: Aliyun console → Captcha 2.0 → Scene management. Create a scene and copy the SceneId and prefix over together; the RAM key needs the AliyunYundunAFSFullAccess policy. After pasting, click “Test credentials” below: green = credentials work, yellow = required fields missing, red = key rejected.', 'wink-wp-gatekeeper' ); ?></p>
						<?php
						self::test_credential_btn(
							'id="wk-aliyun-test-credentials"',
							'id="wk-aliyun-test-result"',
							wp_create_nonce( self::AJAX_TEST_CREDENTIALS_ALIYUN ),
							__( 'Test credentials', 'wink-wp-gatekeeper' ),
							'aliyun'
						);
						?>
					</div>
					<div class="wizard-frow" data-wk-driver-face="tencent"<?php echo 'tencent' === $captcha_driver ? '' : ' hidden'; ?>>
						<?php
						self::notice_yellow_bar(
							'info',
							'',
							esc_html__( 'Tianyu is retired as default (existing setups still work) — its settings live on the “2: Captcha” tab; it stays here only as a choice.', 'wink-wp-gatekeeper' )
						);
						?>
					</div>
					<div class="wizard-frow" data-wk-driver-face="none"<?php echo '' === $captcha_driver ? '' : ' hidden'; ?>>
						<?php
						self::notice_yellow_bar(
							'warn',
							'',
							esc_html__( '“Not now” selected — the comment gate relies on the built-in rules alone and the captcha stage is skipped entirely. The overview page keeps a yellow banner reminding you to come back.', 'wink-wp-gatekeeper' )
						);
						?>
					</div>

					<?php /* AbuseIPDB 可选折叠卡（第三稿——「配好验证码后顺手接上」；实况键最小面）。 */ ?>
					<?php
					self::advanced_fold(
						'id="wk-wizard-abuseipdb"',
						__( 'IP reputation (AbuseIPDB) · optional — add it once your captcha is set up', 'wink-wp-gatekeeper' ),
						static function () use ( $settings ): void {
							$iprep = is_array( $settings['iprep'] ?? null ) ? $settings['iprep'] : array();
							$abuse = is_array( $iprep['abuseipdb'] ?? null ) ? $iprep['abuseipdb'] : array();
							?>
							<p class="description"><?php esc_html_e( 'Treat suspicious visitors strictly based on their IP record (force human verification before letting them through) — shared by the comment and login gates; skipping it does not affect any protection above, and you can fine-tune later on the “4: IP reputation” tab (threshold / soft cap / daily quota all live under ⚙ Advanced there).', 'wink-wp-gatekeeper' ); ?></p>
							<div class="switch-row">
								<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'iprep', 'enabled' ) ) ); ?>" value="0" />
								<label>
									<input type="checkbox" class="sw" name="<?php echo esc_attr( self::field_name( array( 'iprep', 'enabled' ) ) ); ?>" value="1" <?php checked( ! empty( $iprep['enabled'] ) ); ?> />
									<span class="sw-label"><span class="sw-hd"><b><?php esc_html_e( 'Enable IP reputation lookups', 'wink-wp-gatekeeper' ); ?></b></span>
										<span class="why"><?php esc_html_e( 'It only takes effect with a key filled in; when off or empty, the trust chain skips this item. When enabled, visitor IPs are sent to an overseas service for lookup — see Tab 4 for the data-transfer notice.', 'wink-wp-gatekeeper' ); ?></span></span>
								</label>
							</div>
							<p><label for="wk-field-iprep-abuseipdb-api_key"><strong><?php esc_html_e( 'AbuseIPDB API Key', 'wink-wp-gatekeeper' ); ?></strong></label></p>
							<?php self::password_field( array( 'iprep', 'abuseipdb', 'api_key' ), '' !== (string) ( $abuse['api_key'] ?? '' ), 'API Key' ); ?>
							<p class="description"><?php esc_html_e( 'Free tier: 1,000 lookups/day (agreed baseline). Register at abuseipdb.com; lookups count against the quota, and the soft cap / daily quota limits are tuned under ⚙ Advanced on Tab 4.', 'wink-wp-gatekeeper' ); ?></p>
							<?php
							self::test_credential_btn(
								'id="wk-iprep-test-credentials"',
								'id="wk-iprep-test-result"',
								wp_create_nonce( self::AJAX_TEST_IPREP ),
								__( 'Test credentials', 'wink-wp-gatekeeper' ),
								'abuseipdb'
							);
							?>
							<?php
						}
					);
					?>

					<h3><?php esc_html_e( 'Submit-trigger flow', 'wink-wp-gatekeeper' ); ?></h3>
					<p class="wk-muted"><?php esc_html_e( 'How visitors get challenged — two flows, compared in the two recordings below (real captures from v0.9.1).', 'wink-wp-gatekeeper' ); ?></p>
					<div class="radio-cards" data-wk-radio-group="submit-flow">
						<?php $submit_flow = 'button' === ( $settings['captcha']['submit_flow'] ?? 'on_submit' ) ? 'button' : 'on_submit'; ?>
						<label class="radio-card<?php echo 'on_submit' === $submit_flow ? ' on' : ''; ?>">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'submit_flow' ) ) ); ?>" value="on_submit" <?php checked( 'on_submit', $submit_flow ); ?> />
							<span class="rc-hd"><span class="rc-dot"></span><?php esc_html_e( 'No-button mode', 'wink-wp-gatekeeper' ); ?></span>
							<span class="rc-tag"><?php esc_html_e( 'Recommended · Default', 'wink-wp-gatekeeper' ); ?></span>
							<span class="rc-bd"><?php esc_html_e( 'No separate verify button — clicking the native “Post Comment / Log In” runs the check, and the form resubmits automatically afterwards.', 'wink-wp-gatekeeper' ); ?></span>
						</label>
						<label class="radio-card<?php echo 'button' === $submit_flow ? ' on' : ''; ?>">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'submit_flow' ) ) ); ?>" value="button" <?php checked( 'button', $submit_flow ); ?> />
							<span class="rc-hd"><span class="rc-dot"></span><?php esc_html_e( 'Dedicated verify button (classic)', 'wink-wp-gatekeeper' ); ?></span>
							<span class="rc-bd"><?php esc_html_e( 'Click “Complete human verification” in the form first, then submit — the behavior up to 0.8.3.', 'wink-wp-gatekeeper' ); ?></span>
						</label>
					</div>

					<?php /* 两形态实录演示卡（【W6-C】用户 2026-11-21 提供 v0.9.1 实录 GIF 入库
					 * assets/img/——合成四步文字帧循环退役；引言行/说明行=「真实录屏」语义；
					 * radio 联动=轻量 JS class 切换零状态语义，初始 .on 服务端按 $submit_flow
					 * 渲染；img 非 CSS 动画件，减动效语境天然可看（登记不设守卫）；四步文字保留为无按钮卡下说明列表——③自动续提语义/④链路顺序为
					 * GIF 画面不承载的独立信息。 */ ?>
					<div class="wk-flow-demo" id="wk-flow-demo">
						<figure class="wk-flow-card<?php echo 'on_submit' === $submit_flow ? ' on' : ''; ?>" data-wk-flow-demo="on_submit">
							<figcaption class="wk-flow-cap"><?php esc_html_e( 'No-button mode (current default)', 'wink-wp-gatekeeper' ); ?></figcaption>
							<img class="wk-flow-gif"
								src="<?php echo esc_url( WINK_GATEKEEPER_URL . 'assets/img/demo-trigger-nobutton.gif' ); ?>"
								alt="<?php echo esc_attr( __( 'No-button mode (current default)', 'wink-wp-gatekeeper' ) ); ?>"
								loading="lazy" width="968" height="888" />
						</figure>
						<figure class="wk-flow-card<?php echo 'button' === $submit_flow ? ' on' : ''; ?>" data-wk-flow-demo="button">
							<figcaption class="wk-flow-cap"><?php esc_html_e( 'Classic button mode', 'wink-wp-gatekeeper' ); ?></figcaption>
							<img class="wk-flow-gif"
								src="<?php echo esc_url( WINK_GATEKEEPER_URL . 'assets/img/demo-trigger-classic.gif' ); ?>"
								alt="<?php echo esc_attr( __( 'Classic button mode', 'wink-wp-gatekeeper' ) ); ?>"
								loading="lazy" width="980" height="738" />
						</figure>
					</div>
					<script>
						/* 【W6-C】radio（captcha.submit_flow）↔ 实录卡联动——纯视觉 class 切换，
						 * 零状态零提交语义（表单值归 radio 本尊；初始 .on 服务端渲染）。 */
						( function () {
							var demo = document.getElementById( 'wk-flow-demo' );
							var group = document.querySelector( '[data-wk-radio-group="submit-flow"]' );
							if ( ! demo || ! group ) { return; }
							var cards = demo.querySelectorAll( '[data-wk-flow-demo]' );
							group.addEventListener( 'change', function ( event ) {
								if ( ! event.target || ! event.target.checked ) { return; }
								cards.forEach( function ( card ) {
									card.classList.toggle( 'on', event.target.value === card.getAttribute( 'data-wk-flow-demo' ) );
								} );
							} );
						} )();
					</script>

					<div class="wizard-foot">
						<span class="wk-muted"><a href="<?php echo esc_url( self::wizard_url( 3 ) ); ?>"><?php esc_html_e( 'Skip this step for now →', 'wink-wp-gatekeeper' ); ?></a></span>
						<span>
							<a class="button" href="<?php echo esc_url( self::wizard_url( 1 ) ); ?>"><?php esc_html_e( '← Back', 'wink-wp-gatekeeper' ); ?></a>
							<button type="submit" class="button button-primary" data-wk-wizard-next="3"><?php esc_html_e( 'Next · Content moderation →', 'wink-wp-gatekeeper' ); ?></button>
						</span>
					</div>
				</section>

				<?php /* ---------- 步 3 · 内容审核（WP 两开关只读黄条 + 云审推荐序 radio 六卡 + 最小凭据面） ---------- */ ?>
				<section class="wk-step-panel" data-wk-step="3"<?php echo 3 !== $step ? ' hidden' : ''; ?>>
					<h2><?php esc_html_e( 'Step 2 · Content moderation', 'wink-wp-gatekeeper' ); ?></h2>
					<p class="wk-muted"><?php esc_html_e( 'The built-in rules (length / no-Chinese / keywords) are free and already on duty. For more strength, add a cloud moderation provider — it polices wording and ad variants. Choosing “Not now” does not affect existing protection at all.', 'wink-wp-gatekeeper' ); ?></p>
					<?php self::render_wizard_wp_switches_bar(); ?>
					<div class="switch-row">
						<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'enabled' ) ) ); ?>" value="0" />
						<label>
							<input type="checkbox" class="sw" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'enabled' ) ) ); ?>" value="1" <?php checked( ! empty( $cloud['enabled'] ) ); ?> />
							<span class="sw-label"><span class="sw-hd"><b><?php esc_html_e( 'Enable cloud moderation', 'wink-wp-gatekeeper' ); ?></b></span>
								<span class="why"><?php esc_html_e( 'When on, submissions run through “local rules → selected cloud driver” in series; local rules always stay first in the chain and cannot be turned off.', 'wink-wp-gatekeeper' ); ?></span></span>
						</label>
					</div>
					<div class="radio-cards" data-wk-radio-group="cloud-chain">
						<?php /* 云审推荐序（2026-11-18 拍板）：百度推荐·默认 → DeepSeek 次选 → 阿里 Green → 腾讯 TMS → openai_custom → 暂不配置。radio 同名 []（text_list 单值数组——checked 一项 + hidden '' 伴随域，与 Tab③ checkbox 组同一保存通道）。 */ ?>
						<label class="radio-card<?php echo 'baidu' === $selected ? ' on' : ''; ?>">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'chain' ) ) ); ?>[]" value="baidu" data-wk-chain-toggle="baidu" <?php checked( 'baidu', $selected ); ?> />
							<span class="rc-hd"><span class="rc-dot"></span><?php esc_html_e( 'Baidu Text Moderation', 'wink-wp-gatekeeper' ); ?></span>
							<span class="rc-tag"><?php esc_html_e( 'Recommended · Default', 'wink-wp-gatekeeper' ); ?></span>
							<span class="rc-bd"><?php esc_html_e( 'Recommended · mainland direct connection / pay-as-you-go — the lowest barrier to entry.', 'wink-wp-gatekeeper' ); ?></span>
						</label>
						<label class="radio-card<?php echo 'deepseek' === $selected ? ' on' : ''; ?>">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'chain' ) ) ); ?>[]" value="deepseek" data-wk-chain-toggle="deepseek" <?php checked( 'deepseek', $selected ); ?> />
							<span class="rc-hd"><span class="rc-dot"></span><?php esc_html_e( 'DeepSeek (LLM moderation)', 'wink-wp-gatekeeper' ); ?></span>
							<span class="rc-bd"><?php esc_html_e( 'Second choice — moderation that actually “reads” the text, with stronger verdicts; submissions leave the country.', 'wink-wp-gatekeeper' ); ?></span>
						</label>
						<label class="radio-card<?php echo 'aliyun_green' === $selected ? ' on' : ''; ?>">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'chain' ) ) ); ?>[]" value="aliyun_green" data-wk-chain-toggle="aliyun_green" <?php checked( 'aliyun_green', $selected ); ?> />
							<span class="rc-hd"><span class="rc-dot"></span><?php esc_html_e( 'Aliyun text moderation PLUS', 'wink-wp-gatekeeper' ); ?></span>
							<span class="rc-bd"><?php esc_html_e( 'Mainland · dual-channel submission (nickname and body sent separately) · shares an account with Aliyun Captcha.', 'wink-wp-gatekeeper' ); ?></span>
						</label>
						<label class="radio-card<?php echo 'tencent_tms' === $selected ? ' on' : ''; ?>">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'chain' ) ) ); ?>[]" value="tencent_tms" data-wk-chain-toggle="tencent_tms" <?php checked( 'tencent_tms', $selected ); ?> />
							<span class="rc-hd"><span class="rc-dot"></span><?php esc_html_e( 'Tencent TMS text moderation', 'wink-wp-gatekeeper' ); ?></span>
							<span class="rc-bd"><?php esc_html_e( 'Mainland · fits the Tencent Cloud ecosystem (pick it if you already have Tencent Cloud keys).', 'wink-wp-gatekeeper' ); ?></span>
						</label>
						<label class="radio-card<?php echo 'openai_custom' === $selected ? ' on' : ''; ?>">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'chain' ) ) ); ?>[]" value="openai_custom" data-wk-chain-toggle="openai_custom" <?php checked( 'openai_custom', $selected ); ?> />
							<span class="rc-hd"><span class="rc-dot"></span><?php esc_html_e( 'Custom OpenAI-compatible endpoint', 'wink-wp-gatekeeper' ); ?></span>
							<span class="rc-bd"><?php esc_html_e( 'For relays / self-hosted gateways — endpoint and model name are entered manually.', 'wink-wp-gatekeeper' ); ?></span>
						</label>
						<label class="radio-card<?php echo '' === $selected ? ' on' : ''; ?>">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'chain' ) ) ); ?>[]" value="" data-wk-chain-toggle="none" <?php checked( '', $selected ); ?> />
							<span class="rc-hd"><span class="rc-dot"></span><?php esc_html_e( 'Not now', 'wink-wp-gatekeeper' ); ?></span>
							<span class="rc-bd"><?php esc_html_e( 'Built-in rules only — perfectly usable; add a provider later on the “3: Content moderation” tab.', 'wink-wp-gatekeeper' ); ?></span>
						</label>
					</div>
					<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'chain' ) ) ); ?>[]" value="" />

					<?php /* 各驱动最小凭据面（实况键+JS 探活绑定 id 与 Tab③ 同名——password_field 路径即 id）。 */ ?>
					<div class="wizard-frow" data-wk-chain-face="baidu"<?php echo 'baidu' === $selected ? '' : ' hidden'; ?>>
						<p><strong><?php esc_html_e( 'API Key / Secret Key (Baidu AI Cloud app)', 'wink-wp-gatekeeper' ); ?></strong></p>
						<?php self::password_field( array( 'review', 'cloud', 'baidu', 'api_key' ), '' !== (string) ( $cloud['baidu']['api_key'] ?? '' ), 'API Key' ); ?>
						<?php self::password_field( array( 'review', 'cloud', 'baidu', 'secret_key' ), '' !== (string) ( $cloud['baidu']['secret_key'] ?? '' ), 'Secret Key' ); ?>
						<p class="description"><?php esc_html_e( 'Why it is the default recommendation = lowest barrier to entry: Baidu AI Cloud is free to enable, pay-as-you-go, and connects directly within the mainland — with both keys in hand you are ready to go. After pasting, click “Test credentials” (a real call with a benign sample): green = credentials work, yellow = required fields missing (listed per field), red = key rejected.', 'wink-wp-gatekeeper' ); ?></p>
						<?php
						self::test_credential_btn(
							'id="wk-baidu-test-credentials"',
							'id="wk-baidu-test-result"',
							wp_create_nonce( self::AJAX_TEST_CREDENTIALS_BAIDU ),
							__( 'Test credentials', 'wink-wp-gatekeeper' ),
							'baidu'
						);
						?>
					</div>
					<div class="wizard-frow" data-wk-chain-face="deepseek"<?php echo 'deepseek' === $selected ? '' : ' hidden'; ?>>
						<p><strong><?php esc_html_e( 'API Key (DeepSeek platform)', 'wink-wp-gatekeeper' ); ?></strong></p>
						<?php self::password_field( array( 'review', 'cloud', 'deepseek', 'api_key' ), '' !== (string) ( $cloud['deepseek']['api_key'] ?? '' ), 'API Key' ); ?>
						<p class="description"><?php esc_html_e( 'Note: LLM moderation sends the comment body abroad (DeepSeek platform); commenter IPs are not sent. Pay-as-you-go — past the daily quota, items go to pending review first, so the bill cannot explode.', 'wink-wp-gatekeeper' ); ?></p>
						<?php
						self::test_credential_btn(
							'id="wk-deepseek-test-btn"',
							'id="wk-deepseek-test-result"',
							wp_create_nonce( self::AJAX_TEST_CREDENTIALS_DEEPSEEK ),
							__( 'Test credentials', 'wink-wp-gatekeeper' ),
							'deepseek'
						);
						?>
					</div>
					<div class="wizard-frow" data-wk-chain-face="aliyun_green"<?php echo 'aliyun_green' === $selected ? '' : ' hidden'; ?>>
						<p><strong><?php esc_html_e( 'AccessKeyId / AccessKeySecret (Aliyun RAM)', 'wink-wp-gatekeeper' ); ?></strong></p>
						<?php self::password_field( array( 'review', 'cloud', 'aliyun_green', 'access_key_id' ), '' !== (string) ( $cloud['aliyun_green']['access_key_id'] ?? '' ), 'AccessKeyId' ); ?>
						<?php self::password_field( array( 'review', 'cloud', 'aliyun_green', 'access_key_secret' ), '' !== (string) ( $cloud['aliyun_green']['access_key_secret'] ?? '' ), 'AccessKeySecret' ); ?>
						<p class="description"><?php esc_html_e( 'Already picked Aliyun Captcha in step 1? You can share the same RAM user (two product lines, one account). Dual-service submission: nickname and email+body each go through their own channel (OR, stricter wins). Grant the extra content-safety enhanced permission.', 'wink-wp-gatekeeper' ); ?></p>
						<?php
						self::test_credential_btn(
							'id="wk-green-test-credentials"',
							'id="wk-green-test-result"',
							wp_create_nonce( self::AJAX_TEST_CREDENTIALS_ALIYUN_GREEN ),
							__( 'Test credentials', 'wink-wp-gatekeeper' ),
							'aliyun_green'
						);
						?>
					</div>
					<div class="wizard-frow" data-wk-chain-face="tencent_tms"<?php echo 'tencent_tms' === $selected ? '' : ' hidden'; ?>>
						<p><strong><?php esc_html_e( 'SecretId / SecretKey (Tencent Cloud CAM)', 'wink-wp-gatekeeper' ); ?></strong></p>
						<?php self::password_field( array( 'review', 'cloud', 'tencent_tms', 'secret_id' ), '' !== (string) ( $cloud['tencent_tms']['secret_id'] ?? '' ), 'SecretId' ); ?>
						<?php self::password_field( array( 'review', 'cloud', 'tencent_tms', 'secret_key' ), '' !== (string) ( $cloud['tencent_tms']['secret_key'] ?? '' ), 'SecretKey' ); ?>
						<p class="description"><?php esc_html_e( 'Mainland, per-call billing with a free tier; fits the Tencent Cloud ecosystem. When enabled, comment nicknames, emails, and bodies are sent to a third party (Tencent Cloud, in the mainland) — this notice is always shown.', 'wink-wp-gatekeeper' ); ?></p>
						<?php
						self::test_credential_btn(
							'id="wk-tms-test-credentials"',
							'id="wk-tms-test-result"',
							wp_create_nonce( self::AJAX_TEST_CREDENTIALS_TMS ),
							__( 'Test credentials', 'wink-wp-gatekeeper' ),
							'tencent_tms'
						);
						?>
					</div>
					<div class="wizard-frow" data-wk-chain-face="openai_custom"<?php echo 'openai_custom' === $selected ? '' : ' hidden'; ?>>
						<p><strong><?php esc_html_e( 'Endpoint / API Key / model name (manual entry)', 'wink-wp-gatekeeper' ); ?></strong></p>
						<p><input type="url" id="wk-openai-custom-base-url" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'openai_custom', 'base_url' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $cloud['openai_custom']['base_url'] ?? '' ) ); ?>" class="regular-text code" placeholder="https://api.example.com/v1" /></p>
						<?php self::password_field( array( 'review', 'cloud', 'openai_custom', 'api_key' ), '' !== (string) ( $cloud['openai_custom']['api_key'] ?? '' ), 'API Key' ); ?>
						<p><input type="text" id="wk-openai-custom-model" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'openai_custom', 'model' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $cloud['openai_custom']['model'] ?? '' ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'The model name must match the endpoint (“Test credentials” can check the /models list)', 'wink-wp-gatekeeper' ); ?>" /></p>
						<p class="description"><?php esc_html_e( 'The jurisdiction of the endpoint is unknown — the generic data-transfer notice is always shown; assess compliance yourself. Path prefix trap: for the official OpenAI family, fill up to …/v1 (this plugin appends /chat/completions).', 'wink-wp-gatekeeper' ); ?></p>
						<?php
						self::test_credential_btn(
							'id="wk-openai-custom-test-btn"',
							'id="wk-openai-custom-test-result"',
							wp_create_nonce( self::AJAX_TEST_CREDENTIALS_OPENAI_CUSTOM ),
							__( 'Test credentials', 'wink-wp-gatekeeper' ),
							'openai_custom'
						);
						?>
					</div>
					<div class="wizard-frow" data-wk-chain-face="none"<?php echo '' === $selected ? '' : ' hidden'; ?>>
						<?php
						self::notice_yellow_bar(
							'ok',
							'',
							esc_html__( 'Good choice — the built-in rules stay on duty at zero cost and zero outbound calls. The overview page will not flag this one.', 'wink-wp-gatekeeper' )
						);
						?>
					</div>

					<div class="wizard-foot">
						<span class="wk-muted"><a href="<?php echo esc_url( self::wizard_url( 4 ) ); ?>"><?php esc_html_e( 'Skip this step for now →', 'wink-wp-gatekeeper' ); ?></a></span>
						<span>
							<a class="button" href="<?php echo esc_url( self::wizard_url( 2 ) ); ?>"><?php esc_html_e( '← Back', 'wink-wp-gatekeeper' ); ?></a>
							<button type="submit" class="button button-primary" data-wk-wizard-next="4"><?php esc_html_e( 'Finish setup →', 'wink-wp-gatekeeper' ); ?></button>
						</span>
					</div>
				</section>

				<?php /* ---------- 步 4 · 完成（上岗确认单=真值核对清单 + 测试凭据引导 + 出口两枚） ---------- */ ?>
				<section class="wk-step-panel" data-wk-step="4"<?php echo 4 !== $step ? ' hidden' : ''; ?>>
					<h2><?php esc_html_e( 'On-duty checklist', 'wink-wp-gatekeeper' ); ?></h2>
					<p class="wk-muted"><?php esc_html_e( 'Whatever you finished counts — below is your current protection checklist; missing items can be added later on the settings page.', 'wink-wp-gatekeeper' ); ?></p>
					<?php self::render_wizard_checklist( $settings ); ?>
					<div class="wizard-foot">
						<span class="wk-muted"><a href="<?php echo esc_url( self::wizard_url( 1 ) ); ?>"><?php esc_html_e( 'Rerun the wizard', 'wink-wp-gatekeeper' ); ?></a></span>
						<span>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_WIZARD_DONE . '&to=copy' ), self::ACTION_WIZARD_DONE ) ); ?>"><?php esc_html_e( 'Open the Copy Workshop to give the block page your own voice', 'wink-wp-gatekeeper' ); ?></a>
							<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_WIZARD_DONE ), self::ACTION_WIZARD_DONE ) ); ?>"><?php esc_html_e( 'Go to overview →', 'wink-wp-gatekeeper' ); ?></a>
						</span>
					</div>
				</section>
			</form>

			<p class="wizard-exit wk-muted"><?php esc_html_e( 'Each “Next” step saves what you have filled in so far; ', 'wink-wp-gatekeeper' ); ?><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_WIZARD_SKIP ), self::ACTION_WIZARD_SKIP ) ); ?>"><?php esc_html_e( 'Skip everything and go straight to settings →', 'wink-wp-gatekeeper' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * 向导步 3 · WP 原生两开关联动只读黄条（真值展示零本插件表单字段——SettingsPage
	 * 「不进本插件表单字段」红线照守；与 Tab③ 同数据源 comment_moderation /
	 * comment_whitelist，两开关任一为是才渲染=F3-5 三态守卫；原型「演示开关」落施工态
	 * 改真值展示——演示开关不落库仅原型，施工不虚构交互）。
	 *
	 * @return void
	 */
	private static function render_wizard_wp_switches_bar(): void {
		$wp_moderation = (bool) get_option( 'comment_moderation', 0 );
		$wp_whitelist  = (bool) get_option( 'comment_whitelist', 0 );
		if ( ! $wp_moderation && ! $wp_whitelist ) {
			return; // 两开关都为否：整行不渲染（零 DOM——F3-5 同口径）。
		}
		$state_word = static function ( bool $on ): string {
			return $on ? __( 'Enabled', 'wink-wp-gatekeeper' ) : __( 'Disabled', 'wink-wp-gatekeeper' );
		};
		self::notice_yellow_bar(
			'warn',
			'<strong>' . esc_html__( 'A WordPress native moderation switch is on', 'wink-wp-gatekeeper' ) . '</strong>',
			esc_html( sprintf(
				/* translators: 1: 手动批准状态 2: 先前已批准状态 */
				__( '— Comments still require manual approval after passing the captcha (this is not a malfunction). “Comment must be manually approved”: %1$s; “Comment author must have a previously approved comment”: %2$s.', 'wink-wp-gatekeeper' ),
				$state_word( $wp_moderation ),
				$state_word( $wp_whitelist )
			) )
				. ' <a href="' . esc_url( admin_url( 'options-discussion.php' ) ) . '">' . esc_html__( 'Adjust in Discussion Settings', 'wink-wp-gatekeeper' ) . '</a>'
		);
	}

	/**
	 * 向导步 4 · 上岗确认单核对清单（真值态——配几步算几步，未配项灰字不责备）+
	 * 测试凭据引导（复用 test_credential_btn 新形态——对当前所选验证码驱动实测一次）。
	 *
	 * @param array<string, mixed> $settings 设置树。
	 * @return void
	 */
	private static function render_wizard_checklist( array $settings ): void {
		$captcha_driver = is_string( $settings['captcha']['driver'] ?? null ) ? $settings['captcha']['driver'] : '';
		$captcha_ok     = false;
		$captcha_word   = __( 'Not configured — the comment captcha stage is skipped', 'wink-wp-gatekeeper' );
		if ( 'aliyun' === $captcha_driver ) {
			$aliyun = is_array( $settings['captcha']['aliyun'] ?? null ) ? $settings['captcha']['aliyun'] : array();
			$captcha_ok = '' !== trim( (string) ( $aliyun['access_key_id'] ?? '' ) )
				&& '' !== trim( (string) ( $aliyun['access_key_secret'] ?? '' ) )
				&& '' !== trim( (string) ( $aliyun['scene_id'] ?? '' ) )
				&& '' !== trim( (string) ( $aliyun['prefix'] ?? '' ) );
		} elseif ( 'tencent' === $captcha_driver ) {
			$tencent = is_array( $settings['captcha']['tencent'] ?? null ) ? $settings['captcha']['tencent'] : array();
			$captcha_ok = '' !== trim( (string) ( $tencent['app_id'] ?? '' ) )
				&& '' !== trim( (string) ( $tencent['secret_id'] ?? '' ) )
				&& '' !== trim( (string) ( $tencent['secret_key'] ?? '' ) );
		}
		if ( 'aliyun' === $captcha_driver ) {
			$captcha_word = $captcha_ok
				? sprintf(
					/* translators: %s: 提交触发形态（经典按钮 / 无按钮模式） */
					__( 'Aliyun Captcha 2.0 · all four keys set (submit-trigger flow=%s)', 'wink-wp-gatekeeper' ),
					'button' === ( $settings['captcha']['submit_flow'] ?? 'on_submit' ) ? __( 'Classic button', 'wink-wp-gatekeeper' ) : __( 'No-button mode', 'wink-wp-gatekeeper' )
				)
				: __( 'Aliyun Captcha 2.0 · four keys incomplete', 'wink-wp-gatekeeper' );
		} elseif ( 'tencent' === $captcha_driver ) {
			$captcha_word = $captcha_ok ? __( 'Tencent Tianyu 2.0 · credentials set (existing setups still work)', 'wink-wp-gatekeeper' ) : __( 'Tencent Tianyu 2.0 · credentials incomplete', 'wink-wp-gatekeeper' );
		}
		$cloud    = is_array( $settings['review']['cloud'] ?? null ) ? $settings['review']['cloud'] : array();
		$chain    = is_array( $cloud['chain'] ?? null ) ? $cloud['chain'] : array();
		$selected = in_array( 'baidu', $chain, true ) ? 'baidu'
			: ( in_array( 'deepseek', $chain, true ) ? 'deepseek'
			: ( in_array( 'aliyun_green', $chain, true ) ? 'aliyun_green'
			: ( in_array( 'tencent_tms', $chain, true ) ? 'tencent_tms'
			: ( in_array( 'openai_custom', $chain, true ) ? 'openai_custom' : '' ) ) ) );
		$iprep = is_array( $settings['iprep'] ?? null ) ? $settings['iprep'] : array();
		?>
		<ul class="wk-checklist">
			<li>✓ <?php esc_html_e( 'Four built-in rule defenses · on duty (length / no-Chinese / keywords / regex — free, zero outbound calls, always on)', 'wink-wp-gatekeeper' ); ?></li>
			<li><?php echo '' !== $captcha_driver ? '✓' : '·'; ?> <?php echo esc_html( sprintf( /* translators: %s: 人机验证状态句 */ __( 'Human verification · %s', 'wink-wp-gatekeeper' ), $captcha_word ) ); ?></li>
			<li><?php echo ! empty( $cloud['enabled'] ) && '' !== $selected ? '✓' : '·'; ?> <?php echo esc_html( ! empty( $cloud['enabled'] ) && '' !== $selected ? __( 'Cloud moderation · enabled (provider credentials count as good when “Test credentials” shows green)', 'wink-wp-gatekeeper' ) : __( 'Cloud moderation · disabled (the built-in rules keep watch as usual)', 'wink-wp-gatekeeper' ) ); ?></li>
			<li><?php echo ! empty( $iprep['enabled'] ) ? '✓' : '·'; ?> <?php echo esc_html( ! empty( $iprep['enabled'] ) ? __( 'IP reputation (AbuseIPDB) · enabled (credentials count as good when “Test credentials” shows green)', 'wink-wp-gatekeeper' ) : __( 'IP reputation (AbuseIPDB) · optional enhancement — skipping it does not affect the protections above', 'wink-wp-gatekeeper' ) ); ?></li>
		</ul>
		<?php if ( '' !== $captcha_driver ) : ?>
			<p><strong><?php esc_html_e( 'Last step: click “Test credentials” — green means on duty.', 'wink-wp-gatekeeper' ); ?></strong></p>
			<?php
			if ( 'aliyun' === $captcha_driver ) {
				self::test_credential_btn(
					'id="wk-aliyun-test-credentials"',
					'id="wk-aliyun-test-result"',
					wp_create_nonce( self::AJAX_TEST_CREDENTIALS_ALIYUN ),
					__( 'Test credentials', 'wink-wp-gatekeeper' ),
					'aliyun'
				);
			} elseif ( 'tencent' === $captcha_driver ) {
				self::test_credential_btn(
					'id="wk-tencent-test-credentials"',
					'id="wk-tencent-test-result"',
					wp_create_nonce( self::AJAX_TEST_CREDENTIALS ),
					__( 'Test credentials', 'wink-wp-gatekeeper' ),
					'tencent'
				);
			}
			?>
			<p class="description wk-muted"><?php esc_html_e( 'The “Test credentials” buttons for cloud moderation and IP reputation live on their cards / on the “3: Content moderation” and “4: IP reputation” tabs; each button\'s status badge and “last test” time are persisted (N1).', 'wink-wp-gatekeeper' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * 块 A·站务提醒通栏（异常状态置顶——唯一「需要站长动手」的信息；无话不渲染）。
	 *
	 * 内容（均现行已有，仅换位置 + 新增验证码域兜底）：演练模式通栏（原藏驱动健康
	 * 卡内——02 §2.8 安全门 #3 全站级旁路最高警示位）/ 统计停用提示（原藏今日拦截
	 * 卡内，FIX-δ）/ 审核域兜底（原驱动健康卡顶迁位）/ 验证码域兜底（新增——对齐
	 * 审核域既有兜底，agent C B-1）。
	 *
	 * @return void
	 */
	private static function render_overview_alerts(): void {
		$settings = self::settings();
		?>
		<section id="wk-overview-alerts">
		<?php
		// ⓪【M5·U3】存量审核运行中黄条（A-U3——UI 设计块 4.2；有话才出现，任务完成/暂停即隐）。
		$audit_prog = self::audit_worker()->status();
		$audit_st   = $audit_prog['status'] ?? 'idle';
		if ( 'running' === $audit_st || 'quota_paused' === $audit_st ) {
			// 【W3·批A】迁段 notice_yellow_bar（文案/esc/链接组装留编排位——等价迁移）。
			self::notice_yellow_bar(
				'warn',
				'',
				sprintf(
					/* translators: 1: processed 2: total */
					esc_html__( 'Existing comment audit in progress (%1$d of %2$d processed)', 'wink-wp-gatekeeper' ),
					(int) ( $audit_prog['processed'] ?? 0 ),
					(int) ( $audit_prog['total'] ?? 0 )
				)
				. ' ——<a href="' . esc_url( self::tab_url( 'tools' ) ) . '">' . esc_html__( 'Pause', 'wink-wp-gatekeeper' ) . '</a>'
				. ' · <a href="' . esc_url( self::tab_url( 'tools' ) ) . '">' . esc_html__( 'View report →', 'wink-wp-gatekeeper' ) . '</a>',
				'',
				'',
				'wk-overview-existing-bar'
			);
		}
		// ⓪'【M6·批H·H-7】存量网址核查运行中黄条（对齐 A-U3 先例——运行记录未完成或
		// 队列非空才出现；完成/空队列熄灭）。每日额度推进消耗外呼——总览页可见性兜底。
		if ( 'running' === ( new \WinkGatekeeper\UrlAudit\UrlAuditWorker( $settings ) )->backlog_report_data()['state'] ) {
			// 【W3·批A】迁段 notice_yellow_bar。
			self::notice_yellow_bar(
				'warn',
				'',
				esc_html__( 'Existing URL check in progress… advances automatically within the daily quota (violations go to Spam, unclear ones to pending — both recoverable by hand).', 'wink-wp-gatekeeper' )
				. ' ——<a href="' . esc_url( self::tab_url( 'tools' ) ) . '">' . esc_html__( 'View report →', 'wink-wp-gatekeeper' ) . '</a>',
				'',
				'',
				'wk-overview-url-backlog-bar'
			);
		}
		// ① 演练模式（wp_active 含 24h 懒过期判定——过期不显示）。
		if ( \WinkGatekeeper\Gates\TestMode::wp_active() ) {
			$tm_since = isset( $settings['captcha']['test_mode_since'] ) && is_numeric( $settings['captcha']['test_mode_since'] ) ? (int) $settings['captcha']['test_mode_since'] : 0;
			$tm_time  = $tm_since > 0 ? date_i18n( 'H:i', $tm_since ) : '—'; // WP 边界（站点时区）。
			$tm_left  = $tm_since > 0 ? max( 1, (int) ceil( ( $tm_since + \WinkGatekeeper\Gates\TestMode::TTL_SECONDS - time() ) / HOUR_IN_SECONDS ) ) : 24;
			?>
			<?php
			// 【W3·批A】迁段 notice_yellow_bar（演练模式通栏——Labels::test_mode_word 映射词留编排位）。
			self::notice_yellow_bar(
				'warn',
				'<span class="wk-dot wk-dot--alert" aria-hidden="true"></span> <strong>' . esc_html( \WinkGatekeeper\Stats\Labels::test_mode_word() . __( 'Active (enabled today at ', 'wink-wp-gatekeeper' ) . $tm_time . __( ', about ', 'wink-wp-gatekeeper' ) . $tm_left . sprintf( /* translators: %d: 剩余小时数 */ _n( ' hour)', ' hours)', (int) $tm_left, 'wink-wp-gatekeeper' ), (int) $tm_left ) ) . '</strong>',
				'——' . esc_html__( 'Site-wide human verification is temporarily bypassed for pipeline drills only; it ends automatically after 24 hours (bypassed events are tagged drill and excluded from statistics).', 'wink-wp-gatekeeper' ),
				' <a href="' . esc_url( self::tab_url( 'captcha' ) ) . '">' . esc_html__( 'Turn it off now', 'wink-wp-gatekeeper' ) . '</a>'
			);
			?>
			<?php
		}

		// ② 统计停用（FIX-δ 从「今日拦截」卡内迁出为通栏）。
		if ( empty( $settings['stats']['enabled'] ) ) {
			// 【W3·批A】迁段 notice_yellow_bar。
			self::notice_yellow_bar(
				'warn',
				'<strong>' . esc_html__( 'Statistics counting is disabled', 'wink-wp-gatekeeper' ) . '</strong>',
				'——' . esc_html__( 'The report below keeps no counts; event records still land as usual (Tab 6 is authoritative).', 'wink-wp-gatekeeper' ),
				' <a href="' . esc_url( self::tab_url( 'stats' ) ) . '">' . esc_html__( 'Turn on', 'wink-wp-gatekeeper' ) . '</a>'
			);
		}

		// ③ 验证码域兜底（新增——验证码没选服务商时的可见性兜底）。
		$captcha_driver = isset( $settings['captcha']['driver'] ) && is_string( $settings['captcha']['driver'] ) ? $settings['captcha']['driver'] : '';
		if ( '' === $captcha_driver ) {
			// 【W3·批A】迁段 notice_yellow_bar。
			self::notice_yellow_bar(
				'warn',
				'<strong>' . esc_html__( 'No captcha provider selected yet', 'wink-wp-gatekeeper' ) . '</strong>',
				'——' . esc_html__( 'Bots are not stopped by the challenge; comments are still screened by the built-in rules.', 'wink-wp-gatekeeper' ),
				' <a href="' . esc_url( self::tab_url( 'captcha' ) ) . '">' . esc_html__( 'Configure', 'wink-wp-gatekeeper' ) . '</a>'
			);
		}

		// ④ 内容审核域兜底（「云端审核已启用但无可用驱动」黄条迁位，文案人话化）。
		$cloud = isset( $settings['review']['cloud'] ) && is_array( $settings['review']['cloud'] ) ? $settings['review']['cloud'] : array();
		$chain = isset( $cloud['chain'] ) && is_array( $cloud['chain'] ) ? $cloud['chain'] : array();
		if ( ! empty( $cloud['enabled'] ) && array() !== $chain && 0 === self::chain_usable_count( $chain ) ) {
			// 【W3·批A】迁段 notice_yellow_bar。
			self::notice_yellow_bar(
				'warn',
				'<strong>' . esc_html__( 'Content moderation is enabled, but no moderation provider is configured yet', 'wink-wp-gatekeeper' ) . '</strong>',
				'——' . esc_html__( 'Comments currently rely on the built-in rules alone.', 'wink-wp-gatekeeper' ),
				' <a href="' . esc_url( self::tab_url( 'review' ) ) . '">' . esc_html__( 'Configure', 'wink-wp-gatekeeper' ) . '</a>'
			);
		}

		// ⑤ 上报突增告警（【I3·定稿 §4.5/§9】usage_spike 当日事件消费——诬报/攻击感知黄条；
		// 事件由 AbuseIpdbService report 面在 10min 原始触发 >20 时落库，gate=system/stage=ip）。
		$spike_rows = \WinkGatekeeper\Stats\EventsTable::today_rule_count( \WinkGatekeeper\Ip\AbuseIpdbService::RULE_USAGE_SPIKE );
		if ( $spike_rows > 0 ) {
			// 【W3·批A】迁段 notice_yellow_bar（上报突增告警——usage_spike 只读计数判定留编排位）。
			self::notice_yellow_bar(
				'warn',
				'<span class="wk-dot wk-dot--alert" aria-hidden="true"></span> <strong>' . esc_html__( 'Suspicious: a sudden spike in IP report requests today', 'wink-wp-gatekeeper' ) . '</strong>',
				'——' . esc_html__( 'A script forging captchas may be triggering reports in bulk, or someone may be abusing your site as a false-report weapon. Blocking still works, and reporting has cooldown and quota guardrails; if it persists, temporarily disable the trigger under IP reputation → Reporting.', 'wink-wp-gatekeeper' ),
				' <a href="' . esc_url( self::tab_url( 'iprep' ) ) . '">' . esc_html__( 'Open reporting settings', 'wink-wp-gatekeeper' ) . '</a>'
			);
		}
		?>
		</section>
		<?php
	}

	/**
	 * 链上已配好服务商计数（审核域兜底判定——registry meta×配置树比对；
	 * 未实装 id（老站残留 deepseek 链值）不计为可用）。
	 *
	 * @param string[] $chain review.cloud.chain。
	 * @return int
	 */
	private static function chain_usable_count( array $chain ): int {
		if ( null === self::$driver_registry ) {
			return 0;
		}
		$settings_tree = self::settings(); // 【A2-F3】单行 3 次调用 → 1 次局部变量（缓存后成本已收敛，语义仍收口）。
		$cloud         = isset( $settings_tree['review']['cloud'] ) && is_array( $settings_tree['review']['cloud'] ) ? $settings_tree['review']['cloud'] : array();
		$registered = self::$driver_registry->by_type( 'review' );
		$usable     = 0;
		foreach ( $chain as $driver_id ) {
			if ( ! is_string( $driver_id ) || '' === $driver_id ) {
				continue;
			}
			$meta = isset( $registered[ $driver_id ] ) && is_array( $registered[ $driver_id ] ) ? $registered[ $driver_id ] : null;
			if ( null === $meta ) {
				continue;
			}
			$config = isset( $cloud[ $driver_id ] ) && is_array( $cloud[ $driver_id ] ) ? $cloud[ $driver_id ] : array();
			if ( array() === DriverConfigSpec::missing_fields( $meta, $config ) ) {
				$usable++;
			}
		}
		return $usable;
	}

	/**
	 * 块 B·今日战报（替代原「今日拦截」数卡 + 「待审队列」占位卡）。
	 *
	 * 三闸小票：主行=OverviewRoster::ticket_main 纯函数（0=平静的一天显式空态）；
	 * 评论副行=N4 今日按规则分布（批 2 查询喂数——统计停用不出查询，小票整体灰字）。
	 * 行动行：待你过目（W2/N8 口径=WP 待审队列全量实数，跳转待审队列）+ IP 信誉
	 * 强制验证码单列行（统筹裁决 2——不入待审数字，>0 才出现）+ 垃圾箱存档
	 * （≥500 阈值黄话术升级，F14）。
	 *
	 * @return void
	 */
	private static function render_overview_report(): void {
		$settings      = self::settings();
		$stats         = \WinkGatekeeper\Stats\StatsCache::get();
		$stats_enabled = ! empty( $settings['stats']['enabled'] );

		$comment_reject = (int) ( \WinkGatekeeper\Stats\InterceptDigest::gate_counts( $stats, 'comment', false )['reject'] ?? 0 );
		$login_reject   = (int) ( \WinkGatekeeper\Stats\InterceptDigest::gate_counts( $stats, 'login', false )['reject'] ?? 0 );
		$attempts       = (int) ( \WinkGatekeeper\Stats\InterceptDigest::gate_counts( $stats, 'login', false )['attempts'] ?? 0 );
		$acct_reject    = (int) ( \WinkGatekeeper\Stats\InterceptDigest::gate_counts( $stats, 'register', false )['reject'] ?? 0 )
			+ (int) ( \WinkGatekeeper\Stats\InterceptDigest::gate_counts( $stats, 'lostpassword', false )['reject'] ?? 0 );

		// N4 今日按规则分布（战报副行——批 2 喂数）。
		$comment_dist = $stats_enabled ? \WinkGatekeeper\Stats\EventsTable::today_reject_distribution( 'comment' ) : array();

		// IP 信誉强制验证码单列口径（裁决 2——review_split 折减推导，M3 注册即生效）。
		$ip_ids    = null !== self::$driver_registry ? array_keys( self::$driver_registry->by_type( 'ip' ) ) : array();
		$ip_split  = \WinkGatekeeper\Stats\InterceptDigest::review_split( $stats, $ip_ids );
		$ip_captcha = (int) ( $ip_split['ip_captcha'] ?? 0 );

		// 待审实数（W2/N8——WP 待审队列全量；要过目的=全部待审）与垃圾箱存档量。
		$pending  = wp_count_comments(); // WP 边界。
		$pending_n = is_object( $pending ) && isset( $pending->moderated ) ? (int) $pending->moderated : 0;
		$spam_n    = \WinkGatekeeper\Stats\StatsCache::archived_spam_count();
		$today_label = date_i18n( __( 'M j', 'wink-wp-gatekeeper' ) ); // WP 边界（站点时区）。
		?>
		<section id="wk-overview-report" class="wk-card">
			<h2><?php
				printf(
					/* translators: %s: 日期（n月j日）. */
					esc_html__( 'Today\'s report (%s)', 'wink-wp-gatekeeper' ),
					esc_html( $today_label )
				);
				?> <span class="wk-muted"><?php echo esc_html( $stats_enabled ? __( 'Statistics: enabled', 'wink-wp-gatekeeper' ) : __( 'Statistics: disabled', 'wink-wp-gatekeeper' ) ); ?></span></h2>
			<div class="wk-report-tickets">
				<?php if ( ! $stats_enabled ) : ?>
					<?php foreach ( array( __( 'Comment gate', 'wink-wp-gatekeeper' ), __( 'Login gate', 'wink-wp-gatekeeper' ), __( 'Registration & recovery gate', 'wink-wp-gatekeeper' ) ) as $gray_gate ) : ?>
						<div class="wk-report-ticket wk-report-ticket--disabled">
							<p class="wk-report-ticket__name"><strong><?php echo esc_html( $gray_gate ); ?></strong></p>
							<p class="wk-muted"><?php esc_html_e( 'Statistics are disabled, so nothing was counted today (events are still recorded)', 'wink-wp-gatekeeper' ); ?></p>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
					<div class="wk-report-ticket">
						<p class="wk-report-ticket__name"><strong><?php esc_html_e( 'Comment gate', 'wink-wp-gatekeeper' ); ?></strong></p>
						<p class="wk-report-ticket__main"><?php echo esc_html( \WinkGatekeeper\Stats\OverviewRoster::ticket_main( $comment_reject, 'comment' ) ); ?></p>
						<p class="wk-muted"><?php
							echo esc_html(
								array() !== $comment_dist
									? \WinkGatekeeper\Stats\OverviewRoster::ticket_subline( $comment_dist )
									: __( 'No suspicious comments', 'wink-wp-gatekeeper' )
							);
						?></p>
					</div>
					<div class="wk-report-ticket">
						<p class="wk-report-ticket__name"><strong><?php esc_html_e( 'Login gate', 'wink-wp-gatekeeper' ); ?></strong></p>
						<p class="wk-report-ticket__main"><?php echo esc_html( \WinkGatekeeper\Stats\OverviewRoster::ticket_main( $login_reject, 'login' ) ); ?></p>
						<p class="wk-muted"><?php
							echo esc_html(
								$attempts > 0
									? sprintf(
										/* translators: %d: 密码输错次数. */
										__( '%d wrong passwords, stopped by a captcha', 'wink-wp-gatekeeper' ),
										$attempts
									)
									: __( 'Nobody tried to pick the locks', 'wink-wp-gatekeeper' )
							);
						?></p>
					</div>
					<div class="wk-report-ticket">
						<p class="wk-report-ticket__name"><strong><?php esc_html_e( 'Registration & recovery gate', 'wink-wp-gatekeeper' ); ?></strong></p>
						<p class="wk-report-ticket__main"><?php echo esc_html( \WinkGatekeeper\Stats\OverviewRoster::ticket_main( $acct_reject, 'register' ) ); ?></p>
						<p class="wk-muted"><?php esc_html_e( 'No abnormal registrations or recoveries', 'wink-wp-gatekeeper' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
			<p class="wk-overview-actions">
				<a href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=moderated' ) ); ?>"><?php
					echo esc_html( sprintf(
						/* translators: 1: 待你过目. 2: 条数. */
						__( 'Comments by %1$s: %2$d →', 'wink-wp-gatekeeper' ),
						\WinkGatekeeper\Stats\Labels::pending_word(),
						$pending_n
					) );
					?> <?php esc_html_e( 'Review them', 'wink-wp-gatekeeper' ); ?></a>
				<?php if ( $ip_captcha > 0 ) : ?>
					· <span class="wk-muted"><?php
						echo esc_html( sprintf(
							/* translators: %d: IP 信誉强制验证码次数. */
							__( 'Plus %d captcha challenges enforced by IP reputation (counted separately — not included in the figures above)', 'wink-wp-gatekeeper' ),
							$ip_captcha
						) );
					?></span>
				<?php endif; ?>
				· <a href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=spam' ) ); ?>"><?php
					echo esc_html( sprintf(
						/* translators: 1: 垃圾箱存档. 2: 条数. */
						__( '%1$s: %2$d →', 'wink-wp-gatekeeper' ),
						\WinkGatekeeper\Stats\Labels::spam_word(),
						$spam_n
					) );
					?> <?php echo esc_html( \WinkGatekeeper\Stats\InterceptDigest::spam_warn( $spam_n ) ? __( 'Take a look (time for a cleanup)', 'wink-wp-gatekeeper' ) : __( 'Take a look', 'wink-wp-gatekeeper' ) ); ?></a>
			</p>
		</section>
		<?php
	}

	/**
	 * 块 C·服务商点名册（本次核心——原「驱动健康」表退役；UI-FIX A 收拢行）。
	 *
	 * registry 全集（验证码 2 + 云端审核 3）；本地规则不入册给脚注。UI-FIX A
	 * （2026-11-12 用户实测反馈折中方案）：默认只展开已配置卡（当班+待命，含其上
	 * 异常卡）；未配置服务商收拢一行「未配置的服务商：人话名 · 人话名 [去配置 →]」
	 * ——保留可发现性、不占屏；单入口替代每卡一入口（跳转目标=未配置卡 config_tab
	 * 去重，全同域链对应 Tab，混合域回落 Tab② 三步引导）。每卡固定信息：岗位小字
	 * + 人话名 / 排班态·健康态（Labels 词）/ 今日一句摘要。数据=渲染侧推导
	 * （overview_roster_cards 边界收集 + OverviewRoster::cards 纯函数组装）——
	 * 不改 DriverHealth 存储（agent C §E-b 方案甲，N9 不采纳）。
	 *
	 * @return void
	 */
	private static function render_overview_roster(): void {
		$cards = self::overview_roster_cards();
		$shown = array();
		$pending_setup = array();
		foreach ( $cards as $roster_card ) {
			if ( 'unconfigured' === (string) ( $roster_card['duty'] ?? '' ) ) {
				$pending_setup[] = $roster_card;
			} else {
				$shown[] = $roster_card;
			}
		}
		// 收拢行单入口目标：未配置卡 config_tab 去重——恰一域=该域 Tab；混合域=Tab②（三步引导起点）。
		$setup_tabs = array_values( array_unique( array_filter( array_map(
			static function ( array $roster_card ): string {
				return (string) ( $roster_card['config_tab'] ?? '' );
			},
			$pending_setup
		) ) ) );
		$setup_tab = 1 === count( $setup_tabs ) ? (string) $setup_tabs[0] : 'captcha';
		$setup_names = array();
		foreach ( $pending_setup as $roster_card ) {
			$setup_names[] = (string) ( $roster_card['label'] ?? '' );
		}
		?>
		<section id="wk-overview-roster" class="wk-card">
			<h2><?php
				echo esc_html( sprintf(
					/* translators: %d: 服务商数. */
					__( 'Provider roster (%d)', 'wink-wp-gatekeeper' ),
					count( $cards )
				) );
				?> <span class="wk-muted"><?php esc_html_e( 'Even the error-free get called each day, so you can tell at a glance', 'wink-wp-gatekeeper' ); ?></span></h2>
			<div class="wk-roster-cards">
				<?php foreach ( $shown as $roster_card ) { self::render_roster_card( $roster_card ); } ?>
			</div>
			<?php if ( array() !== $pending_setup ) : ?>
				<p class="wk-roster-unconfigured"><?php
					echo esc_html( sprintf(
						/* translators: %s: 未配置服务商人话名清单. */
						__( 'Unconfigured providers: %s', 'wink-wp-gatekeeper' ),
						implode( ' · ', $setup_names )
					) );
					?> <a href="<?php echo esc_url( self::tab_url( $setup_tab ) ); ?>"><?php esc_html_e( 'Configure →', 'wink-wp-gatekeeper' ); ?></a></p>
			<?php endif; ?>
			<p class="wk-muted"><?php echo esc_html( \WinkGatekeeper\Stats\Labels::local_rules_footnote() ); ?></p>
			<p class="wk-muted"><?php echo esc_html( \WinkGatekeeper\Stats\Labels::pass_uncounted_note() ); ?></p>
		</section>
		<?php
	}

	/**
	 * 点名册数据收集（WP 边界：registry 全集×配置树×DriverHealth×Usage×StatsCache
	 * 只读取数；推导收口 OverviewRoster::cards 纯函数——排班态矩阵单测钉在
	 * OverviewRosterTest）。
	 *
	 * @return array<int, array<string, mixed>> 点名册卡组（异常置顶微排序后）。
	 */
	private static function overview_roster_cards(): array {
		if ( null === self::$driver_registry ) {
			return array();
		}
		$settings = self::settings();
		$captcha  = isset( $settings['captcha'] ) && is_array( $settings['captcha'] ) ? $settings['captcha'] : array();
		$cloud    = isset( $settings['review']['cloud'] ) && is_array( $settings['review']['cloud'] ) ? $settings['review']['cloud'] : array();
		$chain    = isset( $cloud['chain'] ) && is_array( $cloud['chain'] ) ? $cloud['chain'] : array();
		/* 【v1.7·M3-IP·定稿 §9】iprep 子树入册（AbuseIPDB 卡自动入点名册——type='ip'
		 * 驱动收集；config=iprep.<id>；当班=iprep.enabled，!enabled+必填齐=待命蓝点）。 */
		$iprep    = isset( $settings['iprep'] ) && is_array( $settings['iprep'] ) ? $settings['iprep'] : array();
		$usage    = \WinkGatekeeper\Stats\Usage::get();
		$cache    = \WinkGatekeeper\Stats\StatsCache::get();

		$drivers    = array();
		$configs    = array();
		$halted     = array();
		$usage_map  = array();
		$counts_map = array();
		foreach ( array( 'captcha', 'review', 'ip' ) as $driver_type ) {
			foreach ( self::$driver_registry->by_type( $driver_type ) as $driver_id => $meta ) {
				$driver_id = (string) $driver_id;
				// 本地驱动不入册（无外部故障面——脚注交代，B 提案块 C）。
				if ( 'local_rules' === $driver_id ) {
					continue;
				}
				$config = 'captcha' === $driver_type
					? ( isset( $captcha[ $driver_id ] ) && is_array( $captcha[ $driver_id ] ) ? $captcha[ $driver_id ] : array() )
					: ( 'ip' === $driver_type
						? ( isset( $iprep[ $driver_id ] ) && is_array( $iprep[ $driver_id ] ) ? $iprep[ $driver_id ] : array() )
						: ( isset( $cloud[ $driver_id ] ) && is_array( $cloud[ $driver_id ] ) ? $cloud[ $driver_id ] : array() ) );
				$drivers[]                = $meta;
				$configs[ $driver_id ]    = $config;
				$halted[ $driver_id ]     = DriverHealth::state_today( $driver_id );
				$usage_map[ $driver_id ]  = \WinkGatekeeper\Stats\Usage::daily_total_for( $usage, $driver_id );
				$counts_map[ $driver_id ] = \WinkGatekeeper\Stats\InterceptDigest::driver_counts( $cache, $driver_id, false );
			}
		}

		return \WinkGatekeeper\Stats\OverviewRoster::cards( $drivers, array(
			'captcha_driver' => isset( $captcha['driver'] ) && is_string( $captcha['driver'] ) ? $captcha['driver'] : '',
			'cloud_enabled'  => ! empty( $cloud['enabled'] ),
			'chain'          => $chain,
			'configs'        => $configs,
			'halted'         => $halted,
			'usage'          => $usage_map,
			'counts'         => $counts_map,
			'iprep_enabled'  => ! empty( $iprep['enabled'] ),
		) );
	}

	/**
	 * 点名册单卡渲染（只接已配置卡——未配置收拢行见 render_overview_roster；
	 * 停转=警示/黄色状态点——颜色映射在渲染层）。
	 *
	 * @param array<string, mixed> $roster_card OverviewRoster::cards 输出行。
	 * @return void
	 */
	private static function render_roster_card( array $roster_card ): void {
		$dot = 'wk-dot--ok';
		if ( ! ( $roster_card['healthy'] ?? true ) ) {
			$dot = 'quota_exhausted' === ( $roster_card['halt'] ?? '' ) ? 'wk-dot--warn' : 'wk-dot--alert';
		}

		$status = (string) ( $roster_card['duty_word'] ?? '' );
		if ( '' !== (string) ( $roster_card['health_word'] ?? '' ) ) {
			$status .= ' · ' . (string) $roster_card['health_word'];
		}
		?>
		<div class="wk-roster-card">
			<p class="wk-roster-role"><?php echo esc_html( (string) ( $roster_card['role'] ?? '' ) ); ?></p>
			<p><strong><?php echo esc_html( (string) ( $roster_card['label'] ?? '' ) ); ?></strong></p>
			<p class="wk-roster-status">
				<span class="wk-dot <?php echo esc_attr( $dot ); ?>" aria-hidden="true"></span>
				<?php echo esc_html( $status ); ?>
			</p>
			<?php if ( '' !== (string) ( $roster_card['halt_note'] ?? '' ) ) : ?>
				<p class="wk-muted"><?php echo esc_html( (string) $roster_card['halt_note'] ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== (string) ( $roster_card['summary'] ?? '' ) ) : ?>
				<p class="wk-muted"><?php echo esc_html( (string) $roster_card['summary'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * 块 D·最近岔子（原「事件流预览」占位卡升级——批 2 N2∪N3 合并时间线的紧凑形态）。
	 *
	 * 入口性质：只留 5 条（明细与筛选归 Tab⑥）；故障与解除记录都入列（「故障→修复」
	 * 完整小剧场，F9 停转留痕）；空态显式人话「最近 7 天风平浪静」；卡头入口链接
	 * Tab⑥（事件流筛选页 roadmap——先链页顶，agent C C4）。
	 *
	 * @return void
	 */
	private static function render_overview_incidents(): void {
		$faults = \WinkGatekeeper\Stats\EventsTable::recent_faults( \WinkGatekeeper\Stats\OverviewRoster::INCIDENT_LIMIT );
		$health = \WinkGatekeeper\Stats\EventsTable::recent_health_timeline( \WinkGatekeeper\Stats\OverviewRoster::INCIDENT_LIMIT, 7 );

		$driver_labels = array();
		if ( null !== self::$driver_registry ) {
			foreach ( self::$driver_registry->all() as $driver_id => $meta ) {
				$driver_labels[ (string) $driver_id ] = is_string( $meta['label'] ?? null ) ? $meta['label'] : (string) $driver_id;
			}
		}

		$timeline  = \WinkGatekeeper\Stats\InterceptDigest::fault_timeline_rows( $faults, $health, $driver_labels );
		$now       = current_time( 'mysql' ); // WP 边界（站点时区）。
		$today     = substr( $now, 0, 10 );
		$yesterday = gmdate( 'Y-m-d', (int) strtotime( $now . ' -1 day' ) );
		$lines     = \WinkGatekeeper\Stats\OverviewRoster::incident_lines( $timeline, $today, $yesterday, \WinkGatekeeper\Stats\OverviewRoster::INCIDENT_LIMIT );
		?>
		<section id="wk-overview-incidents" class="wk-card">
			<h2><?php esc_html_e( 'Recent incidents', 'wink-wp-gatekeeper' ); ?><?php if ( array() !== $lines ) : ?> <span class="wk-muted"><?php
				echo esc_html( sprintf(
					/* translators: %d: 条数. */
					__( '(last %d)', 'wink-wp-gatekeeper' ),
					count( $lines )
				) );
			?></span><?php endif; ?>
				<a class="wk-overview-more" href="<?php echo esc_url( self::tab_url( 'stats' ) ); ?>"><?php esc_html_e( 'Full records → Statistics', 'wink-wp-gatekeeper' ); ?></a></h2>
			<?php if ( array() === $lines ) : ?>
				<p class="wk-muted wk-overview-calm"><?php esc_html_e( 'All quiet for the last 7 days.', 'wink-wp-gatekeeper' ); ?></p>
			<?php else : ?>
				<ul class="wk-incident-list">
					<?php foreach ( $lines as $line ) : ?>
						<li>
							<span class="wk-muted"><?php echo esc_html( (string) $line['time_word'] ); ?></span>
							<strong><?php echo esc_html( (string) $line['driver'] ); ?></strong>
							<?php echo esc_html( (string) $line['text'] ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * 块 E·站点底账（一行灰字带——「不出错没人看、出错才重要」的全局开关态收纳）。
	 *
	 * 内容：统计计数开/关 · 演练模式开/关（开时与块 A 通栏呼应）· 事件表容量读数
	 * （N11——agent C B-6 裁决替代无指标的「事件记录正常」）。
	 * 【M4UX UI-1-1】旧「首次使用？三步配好 →」页脚引导退役——引导升位为页顶
	 * 通栏（render_overview_onboarding，判定复用 overview_configured_count）。
	 *
	 * @return void
	 */
	private static function render_overview_footer(): void {
		$settings = self::settings();
		$stats_on = ! empty( $settings['stats']['enabled'] );
		$rows     = \WinkGatekeeper\Stats\EventsTable::row_count();
		$max_rows = \WinkGatekeeper\Stats\EventsTable::MAX_ROWS;
		?>
		<section id="wk-overview-footer" class="wk-overview-footer">
			<?php echo esc_html( sprintf(
				/* translators: 1: 统计计数态. 2: 演练模式态. 3: 当前事件行数. 4: 容量上限. */
				__( 'Statistics counting %1$s · %2$s %3$s · event records %4$d / %5$d', 'wink-wp-gatekeeper' ),
				$stats_on ? __( 'On', 'wink-wp-gatekeeper' ) : __( 'Off', 'wink-wp-gatekeeper' ),
				\WinkGatekeeper\Stats\Labels::test_mode_word(),
				\WinkGatekeeper\Gates\TestMode::wp_active() ? _x( 'Active', 'drill-active', 'wink-wp-gatekeeper' ) : __( 'Disabled', 'wink-wp-gatekeeper' ),
				$rows,
				$max_rows
			) ); ?>
		</section>
		<?php
	}

	/**
	 * 全站已配好服务商计数（首次使用引导显隐判定——验证码与云审两域合并；
	 * 必填缺失判定单一来源 DriverConfigSpec——F11）。
	 *
	 * @param array<string, mixed> $settings 设置树。
	 * @return int
	 */
	private static function overview_configured_count( array $settings ): int {
		if ( null === self::$driver_registry ) {
			return 0;
		}
		$captcha = isset( $settings['captcha'] ) && is_array( $settings['captcha'] ) ? $settings['captcha'] : array();
		$cloud   = isset( $settings['review']['cloud'] ) && is_array( $settings['review']['cloud'] ) ? $settings['review']['cloud'] : array();
		$chain   = isset( $cloud['chain'] ) && is_array( $cloud['chain'] ) ? $cloud['chain'] : array();

		$count = 0;
		foreach ( self::$driver_registry->by_type( 'captcha' ) as $driver_id => $meta ) {
			$driver_id = (string) $driver_id;
			$config    = isset( $captcha[ $driver_id ] ) && is_array( $captcha[ $driver_id ] ) ? $captcha[ $driver_id ] : array();
			if ( array() === DriverConfigSpec::missing_fields( is_array( $meta ) ? $meta : array(), $config ) ) {
				$count++;
			}
		}
		$registered = self::$driver_registry->by_type( 'review' );
		foreach ( $chain as $driver_id ) {
			if ( ! is_string( $driver_id ) || ! isset( $registered[ $driver_id ] ) || ! is_array( $registered[ $driver_id ] ) ) {
				continue;
			}
			$config = isset( $cloud[ $driver_id ] ) && is_array( $cloud[ $driver_id ] ) ? $cloud[ $driver_id ] : array();
			if ( array() === DriverConfigSpec::missing_fields( $registered[ $driver_id ], $config ) ) {
				$count++;
			}
		}
		return $count;
	}

	/* -------------------------------------------------------------------
	 * Tab② 验证码（03 §1 v1.3 用户建议1+2：下拉选择 + 分厂商配置区 + 切换保留 +
	 * 双层必填校验 + 现场测试凭据；渲染数据从 CaptchaTabSpec 纯逻辑派生）
	 * ------------------------------------------------------------------ */

	private static function render_tab_captcha(): void {
		$settings = self::settings();
		$active   = is_string( $settings['captcha']['driver'] ?? null ) ? $settings['captcha']['driver'] : '';
		$tencent  = $settings['captcha']['tencent'];
		$aliyun   = $settings['captcha']['aliyun']; // 【v1.5·B3】阿里 2.0 配置子树（04 §2 v1.5）。

		/* 【M6·批H·H-2】登录闸描述动态化（说明随当前配置值渲染——阈值缺键回落出厂默认 3/3）；
		 * 【W3·批B】动态描述行随登录保护独立卡渲染（id 锚 wk-login-adaptive-desc 保持）。 */
		$adaptive            = isset( $settings['captcha']['login_adaptive'] ) && is_array( $settings['captcha']['login_adaptive'] ) ? $settings['captcha']['login_adaptive'] : array();
		$desc_user_threshold = isset( $adaptive['user_threshold'] ) && is_numeric( $adaptive['user_threshold'] ) ? max( 1, (int) $adaptive['user_threshold'] ) : 3;
		$desc_ip_threshold   = isset( $adaptive['ip_threshold'] ) && is_numeric( $adaptive['ip_threshold'] ) ? max( 1, (int) $adaptive['ip_threshold'] ) : 3;

		// 服务端黄条（双层校验第二层）：选了厂商但必填空 → 驱动视为未配置（设置仍保存）。
		// 【W3·批A 迁段→批B 翻转】classes 兼容位退役（M6H3 U-1 字面量钉翻转——回落 warn 级插件级黄条类）。
		$notice = CaptchaTabSpec::server_notice( $active, $tencent, $aliyun );
		if ( null !== $notice ) {
			self::notice_yellow_bar(
				'warn',
				'<strong>' . esc_html( $notice ) . '</strong>'
			);
		}

		/* 【M6·批H3·H3-A】驱动未配置 notice 补位（手测反馈 A：Tab② 顶部也提示——此前
		 * 仅 Tab① 总览兜底；U-1 式形态，文案与 Tab① 同义）。 */
		if ( '' === $active ) {
			// 【W3·批A 迁段→批B 翻转】id 锚留编排位（M6H3 契约钉）；classes 位退役。
			self::notice_yellow_bar(
				'warn',
				'<strong>' . esc_html__( 'No captcha provider selected yet', 'wink-wp-gatekeeper' ) . '</strong>',
				'——' . esc_html__( 'Bots are not stopped by the challenge; comments are still screened by the built-in rules — pick a provider under “Provider” below.', 'wink-wp-gatekeeper' ),
				'',
				'',
				'wk-captcha-unconfigured-notice'
			);
		}

		$area     = CaptchaTabSpec::config_area_state( $active );
		$dots     = CaptchaTabSpec::dot_labels();
		$options  = CaptchaTabSpec::driver_options(
			null !== self::$driver_registry ? self::$driver_registry->by_type( 'captcha' ) : array(),
			$tencent,
			$aliyun
		);
		$guide    = CaptchaTabSpec::guide();
		?>
		<section class="wk-card" id="wk-captcha-gates-card">
			<h2><?php esc_html_e( 'Gate switches', 'wink-wp-gatekeeper' ); ?></h2>
			<?php /* 【W3·批B】四闸卡改 switch-row 行形态（design-system §04 tab8 sw-label
			 * 行契约——开关恒最左、说明 .why 同排换行不坠落）；字段面零变化。 */ ?>
			<div class="switch-row">
				<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'gates', 'comment', 'enabled' ) ) ); ?>" value="0" />
				<input type="checkbox" class="sw" name="<?php echo esc_attr( self::field_name( array( 'gates', 'comment', 'enabled' ) ) ); ?>" value="1" <?php checked( ! empty( $settings['gates']['comment']['enabled'] ) ); ?> />
				<span class="sw-label"><b><?php esc_html_e( 'Comment gate · Enable comment protection', 'wink-wp-gatekeeper' ); ?></b>
					<span class="why"><?php esc_html_e( '= master switch for the four local rule gates plus the captcha and IP reputation stages; to disable only the captcha, set the provider to “Not configured”. When off, the comment form has zero DOM changes and submissions are not verified.', 'wink-wp-gatekeeper' ); ?></span>
				</span>
			</div>

			<?php
			/* 【v1.4·反馈6+澄清A/R2/03 Tab②】评论保护·信用免验折叠区（保留件——落评论闸
			 * 行内折叠，默认收起；五键 + IP 信誉三态说明块（澄清 A 强制）+「去配置 IP 信誉」
			 * 跳 Tab④ 引导 + 整页缓存提示——字段面零变化）。 */
			$trust = isset( $settings['captcha']['trust'] ) && is_array( $settings['captcha']['trust'] ) ? $settings['captcha']['trust'] : array();
			?>
			<details class="wk-guide" style="margin-top:10px;">
				<summary><?php esc_html_e( 'Comment protection · trusted-visitor waiver (pre-check without a captcha)', 'wink-wp-gatekeeper' ); ?></summary>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Trusted-visitor waiver', 'wink-wp-gatekeeper' ); ?></th>
						<td>
							<!-- captcha.trust.enabled（04 §2 v1.4 六键之一；纯 on/off——三态语义由澄清 A 定死无需语义开关）。 -->
							<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'trust', 'enabled' ) ) ); ?>" value="0" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'trust', 'enabled' ) ) ); ?>" value="1" <?php checked( ! isset( $trust['enabled'] ) || ! empty( $trust['enabled'] ) ); ?> />
								<?php esc_html_e( 'When “Complete human verification” is clicked, a pre-check runs first (four local gates + IP reputation + track record); passing everything skips the real captcha', 'wink-wp-gatekeeper' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'The pre-check is a UX rehearsal — at submission time the four gates / cloud moderation still run the full chain; only the captcha challenge itself is waived.', 'wink-wp-gatekeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wk-trust-min-approved"><?php esc_html_e( 'Approved-comments threshold N', 'wink-wp-gatekeeper' ); ?></label></th>
						<td>
							<input type="number" id="wk-trust-min-approved" min="1" max="10" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'trust', 'min_approved' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $trust['min_approved'] ?? 3 ) ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Visitors (same nickname + email) or logged-in users with N approved comments are waived; the comparable WP core precedent is 1, and because a captcha waiver is stronger, the default is tightened to 3.', 'wink-wp-gatekeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wk-trust-ttl"><?php esc_html_e( 'Trust voucher validity (minutes)', 'wink-wp-gatekeeper' ); ?></label></th>
						<td>
							<input type="number" id="wk-trust-ttl" min="1" max="60" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'trust', 'ttl_minutes' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $trust['ttl_minutes'] ?? 10 ) ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Passing the pre-check issues a trust voucher (bound to IP + form + identity, not content — editing and resubmitting does not invalidate it); same width as the short verification cache window.', 'wink-wp-gatekeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wk-trust-credit-cache"><?php esc_html_e( 'Trust cache (minutes)', 'wink-wp-gatekeeper' ); ?></label></th>
						<td>
							<input type="number" id="wk-trust-credit-cache" min="0" max="60" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'trust', 'credit_cache_minutes' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $trust['credit_cache_minutes'] ?? 5 ) ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Boolean cache TTL for track-record checks (0 = query the database on every click); approving a new comment invalidates the cache immediately.', 'wink-wp-gatekeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wk-trust-precheck-limit"><?php esc_html_e( 'Pre-check rate limit (per hour per IP)', 'wink-wp-gatekeeper' ); ?></label></th>
						<td>
							<input type="number" id="wk-trust-precheck-limit" min="0" max="360" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'trust', 'precheck_hourly_limit' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $trust['precheck_hourly_limit'] ?? 30 ) ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Blocks scripts hammering the keyword-list / email-trust probes; real humans click the button single-digit times per hour, and 30 leaves room for edits and retries. Over the limit it always degrades to showing a captcha.', 'wink-wp-gatekeeper' ); ?></p>
						</td>
					</tr>
				</table>
				<!-- IP 信誉三态说明块（澄清 A 强制；03 Tab② 与登录保护卡同文）+ 跳 Tab④ 引导。 -->
				<?php
				// 【W3·批A】迁段 notice_yellow_bar（info 级——双 <p> 形态）。
				self::notice_yellow_bar(
					'info',
					'<strong>' . esc_html__( 'Three IP reputation states:', 'wink-wp-gatekeeper' ) . '</strong>',
					array(
						esc_html__( 'When IP reputation is disabled the trust chain skips it, leaving waiver eligibility and login counts untouched; when enabled, any misconfiguration / timeout / error / over-threshold is judged strictly (treated as a fail and a captcha is shown outright) — configuring IP reputation is recommended for stronger protection.', 'wink-wp-gatekeeper' ),
						'<a href="' . esc_url( self::tab_url( 'iprep' ) ) . '">' . esc_html__( '→ Configure IP reputation (Tab 4)', 'wink-wp-gatekeeper' ) . '</a>',
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Full-page cache note: keep the cache cycle under 12 hours — a stale nonce silently degrades the waiver into always-show-a-captcha (fail-safe, submissions still work).', 'wink-wp-gatekeeper' ); ?></p>
			</details>

			<div class="switch-row">
				<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'gates', 'login', 'enabled' ) ) ); ?>" value="0" />
				<input type="checkbox" class="sw" name="<?php echo esc_attr( self::field_name( array( 'gates', 'login', 'enabled' ) ) ); ?>" value="1" <?php checked( ! empty( $settings['gates']['login']['enabled'] ) ); ?> />
				<span class="sw-label"><b><?php esc_html_e( 'Login gate · Enable login verification', 'wink-wp-gatekeeper' ); ?></b>
					<span class="why"><?php esc_html_e( 'Mode levels are in the dropdown on the right; thresholds / windows and other details are in the separate “Login protection” card below.', 'wink-wp-gatekeeper' ); ?></span>
				</span>
				<select name="<?php echo esc_attr( self::field_name( array( 'gates', 'login', 'render_mode' ) ) ); ?>" aria-label="<?php esc_attr_e( 'When login verification appears', 'wink-wp-gatekeeper' ); ?>">
					<?php foreach ( array( 'off' => __( 'Never', 'wink-wp-gatekeeper' ), 'adaptive' => __( 'Adaptive (default)', 'wink-wp-gatekeeper' ), 'always' => __( 'Always', 'wink-wp-gatekeeper' ) ) as $mode => $label ) : ?>
						<option value="<?php echo esc_attr( $mode ); ?>" <?php selected( $settings['gates']['login']['render_mode'], $mode ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<p class="description" id="wk-login-adaptive-desc"><?php
				echo esc_html( sprintf(
					/* translators: 1: 同用户名失败阈值 2: 同 IP 聚合阈值（均随当前配置值渲染） */
					__( 'Adaptive: the login captcha appears only after %1$d cumulative wrong passwords for one account across IPs (only accounts that actually exist are counted), %2$d aggregated for one IP, or a site-level account alert trips — zero bother otherwise; Always: verify before every login. Failure counting also applies to XML-RPC; with no captcha provider configured, going over the threshold still does not block.', 'wink-wp-gatekeeper' ),
					$desc_user_threshold,
					$desc_ip_threshold
				) );
			?></p>

			<?php
			/* 【v1.4·反馈4/R3/03 Tab②】注册闸/找回密码闸两新开关（常显模式无自适应档；
			 * 默认开；未配验证码驱动自动 inert；users_can_register=off 站注册表单核心不可达；
			 * 多站点注册走 wp-signup.php 本闸不覆盖——02 §3.2a）。 */
			foreach ( array(
				'register'     => array( 'label' => __( 'Registration gate', 'wink-wp-gatekeeper' ), 'hint' => __( 'The registration form (wp-login.php?action=register) always shows a captcha; ticketless registrations are rejected and no user is created.', 'wink-wp-gatekeeper' ) ),
				'lostpassword' => array( 'label' => __( 'Password recovery gate', 'wink-wp-gatekeeper' ), 'hint' => __( 'The lost-password form always shows a captcha; ticketless submissions are rejected and no reset email is sent (including submissions for nonexistent users — anti-enumeration).', 'wink-wp-gatekeeper' ) ),
			) as $account_key => $account_meta ) :
				?>
			<div class="switch-row">
				<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'gates', $account_key, 'enabled' ) ) ); ?>" value="0" />
				<input type="checkbox" class="sw" name="<?php echo esc_attr( self::field_name( array( 'gates', $account_key, 'enabled' ) ) ); ?>" value="1" <?php checked( ! empty( $settings['gates'][ $account_key ]['enabled'] ) ); ?> />
				<span class="sw-label"><b><?php echo esc_html( $account_meta['label'] ); ?> · <?php esc_html_e( 'Enable (always — the captcha shows as soon as the page loads)', 'wink-wp-gatekeeper' ); ?></b>
					<span class="why"><?php echo esc_html( $account_meta['hint'] ); ?><?php esc_html_e( 'With no captcha provider configured, nothing renders and nothing blocks; if “Settings → General” disallows registration, the signup form is unreachable (an idle switch is harmless); multisite signups go through wp-signup.php, which this gate does not cover.', 'wink-wp-gatekeeper' ); ?></span>
				</span>
			</div>
			<?php endforeach; ?>
		</section>

		<section class="wk-card">
			<h2><?php esc_html_e( 'Provider selection', 'wink-wp-gatekeeper' ); ?></h2>
			<label for="wk-captcha-driver" class="screen-reader-text"><?php esc_html_e( 'Captcha provider', 'wink-wp-gatekeeper' ); ?></label>
			<select id="wk-captcha-driver" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'driver' ) ) ); ?>">
				<?php foreach ( $options as $option ) : ?>
					<option value="<?php echo esc_attr( $option['value'] ); ?>" <?php selected( $option['value'], $active ); ?>>
						<?php
						// 选项文字后缀状态点（03 Tab② v1.3：未配置/已配置/配置错误）。
						$text = $option['label'];
						if ( isset( $option['dot'] ) ) {
							$text .= ' — ' . $dots[ $option['dot'] ];
						}
						echo esc_html( $text );
						?>
					</option>
				<?php endforeach; ?>
			</select>
			<!-- M4 厂商项注册位：阿里 2.0 / hCaptcha / Turnstile / 极验——DriverRegistry 注册后自动入下拉与配置区。 -->
			<p class="description"><?php esc_html_e( 'Selecting a vendor shows its settings below; switching vendors does not clear filled fields (each saves independently). “Not configured” = the captcha stage is skipped entirely.', 'wink-wp-gatekeeper' ); ?></p>
			<p id="wk-captcha-unconfigured-note" class="description<?php echo $area['visible'] ? ' hidden' : ''; ?>">
				<span class="wk-dot wk-dot--alert" aria-hidden="true"></span>
				<?php echo esc_html( $area['hidden_note'] ); ?>
			</p>
			<!-- 共存提示位：检测到官方 hCaptcha 插件接管时黄条「评论验证码由官方 hCaptcha 插件接管，本插件验证码已让位」——M4（02 §3.4）。 -->
		</section>

		<?php /* 【W2·无按钮模式】提交触发形态 radio 组（0.8.4，Q2=①一刀切默认 on_submit）：
		 * 驱动无关（captcha.submit_flow 在 captcha 子树顶层——Q6 腾讯同批后两驱动共用），
		 * 故落驱动选择卡之后的常显卡位，不进厂商配置区（那边按 active 驱动 hidden 切换）。 */ ?>
		<section class="wk-card">
			<h2><?php esc_html_e( 'Submit-trigger flow', 'wink-wp-gatekeeper' ); ?></h2>
			<?php $submit_flow = 'button' === ( $settings['captcha']['submit_flow'] ?? 'on_submit' ) ? 'button' : 'on_submit'; ?>
			<label style="display:block;margin-bottom:6px;">
				<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'submit_flow' ) ) ); ?>" value="on_submit" <?php checked( 'on_submit', $submit_flow ); ?> />
				<?php esc_html_e( 'No-button mode (default) — no separate verify button; visitors click “Post Comment / Log In” directly, human verification triggers, and the submission goes through once cleared', 'wink-wp-gatekeeper' ); ?>
			</label>
			<label style="display:block;">
				<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'submit_flow' ) ) ); ?>" value="button" <?php checked( 'button', $submit_flow ); ?> />
				<?php esc_html_e( 'Dedicated verify button (classic) — click “Complete human verification” in the form first, then submit (the behavior up to 0.8.3)', 'wink-wp-gatekeeper' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'Since 0.8.4 the default is no-button mode (across the board — upgraded sites switch front-end flow on update); with no JS or a broken widget it falls back to the classic button, and server-side verification always has your back. All four forms — comment / login / registration / recovery — behave the same.', 'wink-wp-gatekeeper' ); ?></p>
		</section>

		<?php
		/* 【W3·批B】演练模式黄条+二次确认（保留件——自天御配置卡迁出为「驱动卡区顶部
		 * 常显位」：test_mode 在 captcha 子树顶层、两驱动共用，原「选中天御才显」的卡内
		 * 位置与键语义不符（施工图批 B 保留清单）。复合表单黄条（内嵌表单控件）超纯文本
		 * 黄条口径不经 notice_yellow_bar——壳翻插件级类，控件与二次确认内联脚本原样
		 * （批 A 延后件收口）。写路径=register_setting 主保存通道（manage_options+nonce
		 * 双闸，无新端点；02 §2.8 安全门 #1——显式开关默认关+红字说明+二次确认复选框；
		 * 启用中黄条尾部注明 24h 自动过期）。 */
		$tm_checked = ! empty( $settings['captcha']['test_mode'] );
		?>
		<div class="wk-notice wk-notice--warn">
			<p>
				<strong><?php esc_html_e( 'Drill mode (bypass human verification)', 'wink-wp-gatekeeper' ); ?></strong>
				<span style="color:#b32d2e;"><?php esc_html_e( 'When enabled, site-wide human verification is bypassed — for pipeline drills only.', 'wink-wp-gatekeeper' ); ?></span>
				<?php esc_html_e( 'Once enabled it expires automatically after 24 hours; bypassed events are tagged drill (excluded from statistics) and never write the short verification cache; the content / cloud / IP stages still run the full chain.', 'wink-wp-gatekeeper' ); ?>
			</p>
			<p>
				<label>
					<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'test_mode' ) ) ); ?>" value="0" />
					<input type="checkbox" id="wk-test-mode" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'test_mode' ) ) ); ?>" value="1" <?php checked( $tm_checked ); ?> <?php echo $tm_checked ? '' : 'disabled'; ?> />
					<?php esc_html_e( 'Enable drill mode', 'wink-wp-gatekeeper' ); ?>
				</label>
				<label style="margin-left:1em;">
					<input type="checkbox" id="wk-test-mode-confirm" />
					<?php esc_html_e( 'I confirm I want to bypass site-wide human verification (double confirmation)', 'wink-wp-gatekeeper' ); ?>
				</label>
			</p>
		</div>
		<script>
			( function() {
				var main = document.getElementById( 'wk-test-mode' );
				var confirm = document.getElementById( 'wk-test-mode-confirm' );
				if ( ! main || ! confirm ) { return; }
				var sync = function() { main.disabled = ! main.checked && ! confirm.checked; };
				confirm.addEventListener( 'change', function() {
					sync();
					if ( ! confirm.checked && ! main.checked ) { return; }
				} );
				main.addEventListener( 'change', function() {
					if ( main.checked ) { confirm.checked = true; }
					sync();
				} );
				if ( main.checked ) { confirm.checked = true; } // 启用中保持可提交（关闭不受二次确认阻塞）。
				sync();
			} )();
		</script>

		<section class="wk-card" id="wk-captcha-config-area" data-wk-vendor="tencent"<?php echo ( $area['visible'] && 'tencent' === $active ) ? '' : ' hidden'; ?>>
			<h2><?php esc_html_e( 'Tencent Tianyu Captcha 2.0 · Configuration', 'wink-wp-gatekeeper' ); ?>
				<span class="badge b-dim"><?php esc_html_e( 'Retired · usable if configured', 'wink-wp-gatekeeper' ); ?></span></h2>
			<p class="description"><?php esc_html_e( 'Retired as default (the factory default switched to Aliyun Captcha 2.0 — H-3 breaking): selecting this card still lets you maintain existing credentials; all four keys keep working.', 'wink-wp-gatekeeper' ); ?></p>

			<?php
			// 【v1.4·反馈9/R4 §C.3】逐驱动合规行（registry privacy 维渲染；原接入指引内的
			// 「核验将提交访客 IP」注记上移至此——常驻不可关闭，cross_border=true 升级黄条）。
			// 【W3·批B】黄条壳 helper 化（判定/文案留编排位——DriverPrivacyTable 契约零变化）。
			$privacy_row = DriverConfigSpec::privacy_notice( TencentCaptchaDriver::registry_entry(), __( 'Tencent Tianyu Captcha 2.0', 'wink-wp-gatekeeper' ) );
			if ( null !== $privacy_row ) :
				self::notice_yellow_bar( 'warn' === $privacy_row['level'] ? 'warn' : 'info', '', esc_html( $privacy_row['text'] ) );
			endif;
			?>

			<details class="wk-guide">
				<summary><?php esc_html_e( 'Setup guide (expand on first configuration)', 'wink-wp-gatekeeper' ); ?></summary>
				<ol>
					<?php foreach ( $guide['steps'] as $step ) : ?>
						<li><?php echo esc_html( $step ); ?></li>
					<?php endforeach; ?>
				</ol>
				<p>
					<a href="<?php echo esc_url( $guide['console_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Captcha console', 'wink-wp-gatekeeper' ); ?></a>
					· <a href="<?php echo esc_url( $guide['doc_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Ticket verification API documentation', 'wink-wp-gatekeeper' ); ?></a>
				</p>
				<p class="description"><?php echo esc_html( $guide['ticket_note'] ); ?></p>
			</details>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-tencent-app-id"><?php esc_html_e( 'CaptchaAppId', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-tencent-app-id" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'tencent', 'app_id' ) ) ); ?>"
							value="<?php echo esc_attr( (string) $tencent['app_id'] ); ?>" class="regular-text"
							data-wk-required="1" data-wk-label="<?php esc_attr_e( 'CaptchaAppId', 'wink-wp-gatekeeper' ); ?>" />
						<p class="description"><?php esc_html_e( 'Captcha console → Verification management → Keys column (public level, used by the front-end widget).', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-tencent-secret-id"><?php esc_html_e( 'SecretId（CAM）', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'captcha', 'tencent', 'secret_id' ), '' !== $tencent['secret_id'], 'SecretId（CAM）' ); ?>
						<p class="description"><?php esc_html_e( 'Cloud API key (for TC3 signing, Access Management → API Key Management). Separate from the captcha console\'s AppSecretKey — two different credential systems.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-tencent-secret-key"><?php esc_html_e( 'SecretKey（CAM）', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'captcha', 'tencent', 'secret_key' ), '' !== $tencent['secret_key'], 'SecretKey（CAM）' ); ?>
						<p class="description"><?php esc_html_e( 'The CAM key paired with the SecretId; used only for server-side TC3 signing and never exposed to the front end.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-tencent-app-secret-key"><?php esc_html_e( 'AppSecretKey (captcha console)', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'captcha', 'tencent', 'app_secret_key' ), '' !== $tencent['app_secret_key'], __( 'AppSecretKey (captcha console)', 'wink-wp-gatekeeper' ) ); ?>
						<p class="description"><?php esc_html_e( 'The key on the same captcha console page as the CaptchaAppId — a required body parameter of the verification request (enforced by server-side online checks, confirmed by live testing on 2026-11-09). Server-side only.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<?php
				/* 【v1.5·DESIGN-C1 §4.2/03 Tab②】min_score 派生控件（registry scoring 维驱动显隐）：
				 * 评分制驱动接入时自动出现（默认 0.5、钳 0.1–1.0）；现役驱动全部二态
				 * （scoring=false）控件隐藏——二态恒 score=1.0 对闸透明。【W3·批B 保留件】。 */
				$active_meta = ( null !== self::$driver_registry && '' !== $active ) ? self::$driver_registry->get( $active ) : null;
				if ( is_array( $active_meta ) && ! empty( $active_meta['scoring'] ) ) :
					?>
					<tr>
						<th scope="row"><label for="wk-captcha-min-score"><?php esc_html_e( 'Bot-score threshold (min_score)', 'wink-wp-gatekeeper' ); ?></label></th>
						<td>
							<input type="number" id="wk-captcha-min-score" step="0.1" min="0.1" max="1"
								name="<?php echo esc_attr( self::field_name( array( 'captcha', 'min_score' ) ) ); ?>"
								value="<?php echo esc_attr( (string) ( $settings['captcha']['min_score'] ?? 0.5 ) ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Score-based providers only: the bot score is normalized to (0,1] and anything below the threshold is blocked as a fail (the server clamps to 0.1–1.0 so 0 is not a free pass and 1.5 is not an always-reject). Event meta records score + threshold so you can inspect the gap.', 'wink-wp-gatekeeper' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Failover tickets (pass on bad networks)', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<?php $disaster = 'block' === ( $tencent['disaster_pass'] ?? 'allow' ) ? 'block' : 'allow'; ?>
						<label style="display:block;margin-bottom:6px;">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'tencent', 'disaster_pass' ) ) ); ?>" value="allow" <?php checked( 'allow', $disaster ); ?> />
							<?php esc_html_e( 'Pass on bad networks (default) — front-end failover tickets count as passed, limited to 5 per IP per hour, events tagged trerror', 'wink-wp-gatekeeper' ); ?>
						</label>
						<label style="display:block;">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'tencent', 'disaster_pass' ) ) ); ?>" value="block" <?php checked( 'block', $disaster ); ?> />
							<?php esc_html_e( 'Block (safety first) — switch temporarily during attacks; failover tickets are never accepted', 'wink-wp-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Official semantics: users on weak networks may receive failover tickets (trerror prefix); blocking them by default would hit real humans — rate limiting guards against abuse.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
			</table>

			<?php
			// 【W3·批B】测试凭据迁 test_credential_btn（五态徽标「未测试」初态统一；
			// 按钮/结果位 id 锚与 nonce action 字面量留编排位——admin-brand.js 绑定面不变）。
			self::test_credential_btn(
				'id="wk-tencent-test-credentials"',
				'id="wk-tencent-test-result"',
				wp_create_nonce( self::AJAX_TEST_CREDENTIALS ),
				__( 'Test credentials', 'wink-wp-gatekeeper' ),
				'tencent'
			);
			?>
			<p class="description"><?php esc_html_e( 'Sends one full-parameter fake-ticket verification: green = credentials work; red = wrong CAM keys (SecretId/SecretKey); yellow = required parameters missing. The last test status and time are recorded.', 'wink-wp-gatekeeper' ); ?></p>
		</section>

		<?php
		/* ---------- 【v1.5·B3】阿里云验证码 2.0 配置区（03 Tab②【v1.5】/05 §1.2/DESIGN-D2 §1.4）----------
		 * 【W3·批B】⚙ 高级配置折叠新落位：error_policy 实键渲染（schema+sanitize 既有通道
		 * 无渲染面——coexist 同类新接线）；【W3·批C·N2】region 实键 radio（cn|sgp 默认 cn，
		 * ENDPOINT_MAP 双端联动——规划位退役）+【N3】mode「默认」角标换位 popup。
		 * ---------- */
		$aliyun_guide = CaptchaTabSpec::aliyun_guide();
		?>
		<section class="wk-card" data-wk-vendor="aliyun"<?php echo ( $area['visible'] && 'aliyun' === $active ) ? '' : ' hidden'; ?>>
			<h2><?php esc_html_e( 'Aliyun Captcha 2.0 · Configuration', 'wink-wp-gatekeeper' ); ?></h2>

			<?php
			// 逐驱动合规行（registry privacy 维渲染——R4 §C.3 同款；常驻不可关闭）。
			$privacy_row = DriverConfigSpec::privacy_notice( AliyunCaptchaDriver::registry_entry(), __( 'Aliyun Captcha 2.0', 'wink-wp-gatekeeper' ) );
			if ( null !== $privacy_row ) :
				self::notice_yellow_bar( 'warn' === $privacy_row['level'] ? 'warn' : 'info', '', esc_html( $privacy_row['text'] ) );
			endif;
			?>

			<details class="wk-guide">
				<summary><?php esc_html_e( 'Setup guide (expand on first configuration)', 'wink-wp-gatekeeper' ); ?></summary>
				<ol>
					<?php foreach ( $aliyun_guide['steps'] as $step ) : ?>
						<li><?php echo esc_html( $step ); ?></li>
					<?php endforeach; ?>
				</ol>
				<p>
					<a href="<?php echo esc_url( $aliyun_guide['console_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Captcha console', 'wink-wp-gatekeeper' ); ?></a>
					· <a href="<?php echo esc_url( $aliyun_guide['doc_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'VerifyIntelligentCaptcha API documentation', 'wink-wp-gatekeeper' ); ?></a>
				</p>
				<p class="description"><?php echo esc_html( $aliyun_guide['ticket_note'] ); ?></p>
			</details>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-aliyun-access-key-id"><?php esc_html_e( 'AccessKeyId', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'captcha', 'aliyun', 'access_key_id' ), '' !== $aliyun['access_key_id'], 'AccessKeyId' ); ?>
						<p class="description"><?php esc_html_e( 'RAM AccessKey (primary-account AKs forbidden — for ACS3 V3 signing, Access Management → RAM). Server-side only, never exposed to the front end.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-aliyun-access-key-secret"><?php esc_html_e( 'AccessKeySecret', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'captcha', 'aliyun', 'access_key_secret' ), '' !== $aliyun['access_key_secret'], 'AccessKeySecret' ); ?>
						<p class="description"><?php esc_html_e( 'The RAM key paired with the AccessKeyId (the raw HMAC signing key). Server-side only. The RAM user needs the AliyunYundunAFSFullAccess policy.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-aliyun-scene-id"><?php esc_html_e( 'SceneId', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="text" id="wk-aliyun-scene-id" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'aliyun', 'scene_id' ) ) ); ?>"
							value="<?php echo esc_attr( (string) $aliyun['scene_id'] ); ?>" class="regular-text"
							data-wk-required="1" data-wk-label="<?php esc_attr_e( 'SceneId', 'wink-wp-gatekeeper' ); ?>" />
						<p class="description"><?php esc_html_e( 'Captcha console → Scene management (required — the server re-sends it so the front end cannot tamper with the scene; a mismatch returns F012).', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-aliyun-prefix"><?php esc_html_e( 'prefix (identity tag)', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="text" id="wk-aliyun-prefix" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'aliyun', 'prefix' ) ) ); ?>"
							value="<?php echo esc_attr( (string) $aliyun['prefix'] ); ?>" class="regular-text"
							data-wk-required="1" data-wk-label="<?php esc_attr_e( 'prefix (identity tag)', 'wink-wp-gatekeeper' ); ?>" />
						<p class="description"><?php esc_html_e( 'Get it from the console overview page — used by the front-end AliyunCaptchaConfig global config (a public front-end parameter, not a server-side API credential).', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Front-end style (mode)', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<?php /* 【W3·批C·N3·breaking】出厂默认 embed→popup 平移（schema migrate 撞车守卫：凭据已配=显式选择保留 embed）；「默认」角标换位 popup。 */ ?>
						<?php $aliyun_mode = 'popup' === ( $aliyun['mode'] ?? 'popup' ) ? 'popup' : 'embed'; ?>
						<label style="display:block;margin-bottom:6px;">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'aliyun', 'mode' ) ) ); ?>" value="popup" <?php checked( 'popup', $aliyun_mode ); ?> />
							<?php esc_html_e( 'Popup (default) — zero footprint: clicking “Post Comment / Log In” opens the interactive popup directly (the primary no-button flow)', 'wink-wp-gatekeeper' ); ?>
						</label>
						<label style="display:block;">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'aliyun', 'mode' ) ) ); ?>" value="embed" <?php checked( 'embed', $aliyun_mode ); ?> />
							<?php esc_html_e( 'Embedded trigger bar — renders a “click to start verification” bar in the page; clicking it opens the puzzle / image-restore popup (the default up to 0.8.4)', 'wink-wp-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Slider width follows the official 320px minimum; switching mode does not affect server-side verification (ticket checking uses the same API). The recommended level already shifted (embed → popup) — upgraded sites with credentials keep the embedded style as an explicit choice; without credentials the new popup default applies. Once you pick the embedded bar again and save, it never shifts back.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
			</table>

			<?php
			// ⚙ 高级配置折叠（原型 Tab② 分区二样张——复杂参数收折叠，主卡只留必填+mode+测试凭据）。
			self::advanced_fold(
				'id="wk-aliyun-advanced"',
				__( 'Advanced (region / failure handling)', 'wink-wp-gatekeeper' ),
				static function () use ( $settings ): void {
					?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wk-aliyun-error-policy"><?php esc_html_e( 'What to do on failure', 'wink-wp-gatekeeper' ); ?></label></th>
							<td>
								<?php /* 【W3·批B】captcha.error_policy 实键渲染（schema SettingsSchema.php:115 +
								 * Sanitize error_policy 枚举既有通道无渲染面——coexist 同类新接线；
								 * 默认 block=现行行为保底，非法值保存时回落「故障即拦」）。 */ ?>
								<?php $error_policy = 'pass_limited' === ( $settings['captcha']['error_policy'] ?? 'block' ) ? 'pass_limited' : 'block'; ?>
								<select id="wk-aliyun-error-policy" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'error_policy' ) ) ); ?>">
									<option value="block" <?php selected( 'block', $error_policy ); ?>><?php esc_html_e( 'Block on failure (default) — when the captcha service misbehaves, prefer not to let things through (safety first)', 'wink-wp-gatekeeper' ); ?></option>
									<option value="pass_limited" <?php selected( 'pass_limited', $error_policy ); ?>><?php esc_html_e( 'Limited pass — transport failures only, plus a 5-per-IP-per-hour pass limit', 'wink-wp-gatekeeper' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Shared by both providers (top-level captcha key); past the rate limit it tightens back to “block on failure”.', 'wink-wp-gatekeeper' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Region', 'wink-wp-gatekeeper' ); ?></th>
							<td>
								<?php /* 【W3·批C·N2】region 实键 radio（schema captcha.aliyun.region cn|sgp 默认 cn——ENDPOINT_MAP 双端联动；规划位退役）。 */ ?>
								<?php $aliyun_region = 'sgp' === ( $settings['captcha']['aliyun']['region'] ?? 'cn' ) ? 'sgp' : 'cn'; ?>
								<label style="display:block;margin-bottom:6px;">
									<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'aliyun', 'region' ) ) ); ?>" value="cn" <?php checked( 'cn', $aliyun_region ); ?> />
									<?php esc_html_e( 'Mainland China (cn · default) — endpoint captcha.cn-shanghai.aliyuncs.com', 'wink-wp-gatekeeper' ); ?>
								</label>
								<label style="display:block;">
									<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'aliyun', 'region' ) ) ); ?>" value="sgp" <?php checked( 'sgp', $aliyun_region ); ?> />
									<?php esc_html_e( 'International (sgp) — endpoint captcha.ap-southeast-1.aliyuncs.com (not verified on a live site)', 'wink-wp-gatekeeper' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'region must match the server side — the front-end SDK and the server endpoint are linked, and a mismatch errors out; after switching, run “Test credentials” once. The sgp endpoint is a static inference (not verified on a live site; the level will be disabled if live re-verification fails).', 'wink-wp-gatekeeper' ); ?></p>
							</td>
						</tr>
					</table>

					<?php
					/* ---------- 【W3·批D·N4】阿里「每日验证额度」规划壳（判定=规划位定稿）：
					 * 现码 captcha 子树无日额度等价键（SettingsSchema captcha 全键面行为级走树
					 * 核对——W3BatchDTest 钉）；usage 计数在 Stats 面无额度闸语义。落地形态
					 * =ghost 规划行+口径注（原型文案「额度用完当日停转、次日自愈」），**零新键
					 * 零控件**——实装后转实线卡。 ---------- */
					?>
					<div class="wk-card--ghost" style="padding:12px 16px;margin-top:8px;">
						<p><b><?php esc_html_e( 'Daily verification quota', 'wink-wp-gatekeeper' ); ?></b> <span class="wk-plan-tag"><?php esc_html_e( 'Planned · not yet built', 'wink-wp-gatekeeper' ); ?></span></p>
						<p class="description"><?php esc_html_e( 'Planned behavior: when the quota runs out it stops for the day and self-heals the next. The captcha service currently sets no daily cap (no settings on this row yet) — it becomes a live card once implemented.', 'wink-wp-gatekeeper' ); ?></p>
					</div>
					<?php
				}
			);
			?>

			<?php
			// 【W3·批B】测试凭据迁 test_credential_btn（五态徽标「未测试」初态统一）。
			self::test_credential_btn(
				'id="wk-aliyun-test-credentials"',
				'id="wk-aliyun-test-result"',
				wp_create_nonce( self::AJAX_TEST_CREDENTIALS_ALIYUN ),
				__( 'Test credentials', 'wink-wp-gatekeeper' ),
				'aliyun'
			);
			?>
			<p class="description"><?php esc_html_e( 'Makes one real call with fake parameters (VerifyIntelligentCaptcha): green = credentials work; red = wrong AccessKey / signature / permissions (including RAM-not-authorized hints); yellow = required parameters missing. The last test status and time are recorded.', 'wink-wp-gatekeeper' ); ?></p>
		</section>

		<?php
		/* ---------- 【W3·批B】登录保护升独立卡（原型 Tab② 分区四——login_adaptive 四键
		 * + iprep_gate + xmlrpc_login 自原「高级：阈值/窗口/IP 信誉联动/XML-RPC」折叠区
		 * 迁出为独立卡；字段面零变化，折叠壳退役）。 ---------- */
		?>
		<section class="wk-card" id="wk-login-guard-card">
			<h2><?php esc_html_e( 'Login protection', 'wink-wp-gatekeeper' ); ?></h2>
			<p class="wk-muted"><?php esc_html_e( 'Captcha over threshold — no account locks, no collateral damage to real users: crossing the threshold within the window only adds a captcha challenge, never locking accounts or IPs (to avoid locking out office egress IPs / home NAT users; Wordfence-style hard lockouts are deliberately not done).', 'wink-wp-gatekeeper' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-login-user-threshold"><?php esc_html_e( 'Per-username failure threshold', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<!-- 【v1.4·R4-G】threshold → user_threshold（schema v2 迁移键）。 -->
						<input type="number" id="wk-login-user-threshold" min="1" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'login_adaptive', 'user_threshold' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $adaptive['user_threshold'] ?? 3 ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'N cumulative wrong passwords for one account across IPs triggers a captcha — distributed brute force still trips it; only accounts that actually exist are counted.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-login-window"><?php esc_html_e( 'Counting window (minutes)', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-login-window" min="1" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'login_adaptive', 'window_minutes' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $adaptive['window_minutes'] ?? 15 ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Fixed window for failure counting: counts reset naturally once the window passes (a successful login resets immediately).', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-login-ip-threshold"><?php esc_html_e( 'Per-IP failure threshold', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-login-ip-threshold" min="1" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'login_adaptive', 'ip_threshold' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $adaptive['ip_threshold'] ?? 3 ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Aggregate threshold for username spraying from one IP: however the username changes, this many cumulative failures triggers it.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'IP reputation tie-in', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<!-- 【v1.4·澄清A】iprep_gate：登录闸 IP 信誉三态联动开关（M3 前未装配=恒 skip=现行行为）。 -->
						<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'login_adaptive', 'iprep_gate' ) ) ); ?>" value="0" />
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'login_adaptive', 'iprep_gate' ) ) ); ?>" value="1" <?php checked( ! empty( $adaptive['iprep_gate'] ) ); ?> />
							<?php esc_html_e( 'When enabled, the three IP reputation states take part in deciding when to show the login captcha', 'wink-wp-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Without IP reputation the trust chain skips it and login counts are unaffected; when enabled, any misconfiguration / timeout / error / over-threshold is judged strictly (a captcha is shown outright).', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Disable XML-RPC logins', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<?php $xmlrpc_login = isset( $settings['gates']['login']['xmlrpc_login'] ) ? (bool) $settings['gates']['login']['xmlrpc_login'] : false; // 缺键回落=false（v1.7 默认翻转——存量不迁移，仅新装/缺键站点按新默认）。 ?>
						<!-- 反选形态：hidden 恒 1，勾选提交 0（后值覆盖前值 = 关闭）。默认关（v1.7 breaking）——使用 XML-RPC 发布工具的站长在此手动开启（取消勾选并保存）。 -->
						<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'gates', 'login', 'xmlrpc_login' ) ) ); ?>" value="1" />
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'gates', 'login', 'xmlrpc_login' ) ) ); ?>" value="0" <?php checked( false, $xmlrpc_login ); ?> />
							<?php esc_html_e( 'Completely disable XML-RPC logins (recommended hardening)', 'wink-wp-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Off by default (since 0.4.5): publishers using XML-RPC tools (blog clients) should untick this and save to re-enable; upgraded sites that had it on are unaffected. When ticked, login-type XML-RPC methods (wp.getUsersBlogs, credentials carried by system.multicall, etc.) get an across-the-board 405; read-only methods such as system.listMethods are unaffected. While on, over-threshold counting also rejects XML-RPC authentication attempt by attempt (a captcha cannot be presented inside XML-RPC).', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
			</table>
			<?php
			// IP 信誉三态说明块（澄清 A 强制；03 Tab② 登录保护卡）+ 跳 Tab④ 引导。
			self::notice_yellow_bar(
				'info',
				'<strong>' . esc_html__( 'Three IP reputation states:', 'wink-wp-gatekeeper' ) . '</strong>',
				array(
					esc_html__( 'When IP reputation is disabled the trust chain skips it, leaving waiver eligibility and login counts untouched; when enabled, any misconfiguration / timeout / error / over-threshold is judged strictly (treated as a fail and a captcha is shown outright) — configuring IP reputation is recommended for stronger protection.', 'wink-wp-gatekeeper' ),
					'<a href="' . esc_url( self::tab_url( 'iprep' ) ) . '">' . esc_html__( '→ Configure IP reputation (Tab 4)', 'wink-wp-gatekeeper' ) . '</a>',
				)
			);
			?>
		</section>
		<?php
	}

	/**
	 * Tab②「测试凭据」AJAX 端点（03 §1 契约④：nonce + manage_options 双闸；结果记录最近一次测试状态与时间——probe log，N1）。
	 *
	 * 表单值（password 空串 = 回落已存值）+ 全参假票核验 → 三态呈现（L0-tencent 判定法）。
	 *
	 * @return void
	 */
	public static function ajax_test_credentials(): void {
		check_ajax_referer( self::AJAX_TEST_CREDENTIALS, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions: manage_options required.', 'wink-wp-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$stored  = get_option( self::OPTION_NAME, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$stored  = SettingsSchema::merge_defaults( $stored );
		$current = isset( $stored['captcha']['tencent'] ) && is_array( $stored['captcha']['tencent'] ) ? $stored['captcha']['tencent'] : array();

		// 表单值覆盖（password 空串保留已存值——与保存契约同语义）。
		$tencent = array(
			'app_id'         => isset( $post['app_id'] ) && is_scalar( $post['app_id'] ) ? sanitize_text_field( (string) $post['app_id'] ) : (string) $current['app_id'],
			'secret_id'      => self::probe_field( $post, 'secret_id', (string) ( $current['secret_id'] ?? '' ) ),
			'secret_key'     => self::probe_field( $post, 'secret_key', (string) ( $current['secret_key'] ?? '' ) ),
			'app_secret_key' => self::probe_field( $post, 'app_secret_key', (string) ( $current['app_secret_key'] ?? '' ) ),
		);

		$precheck = CredentialProbe::local_precheck( $tencent );
		if ( null !== $precheck ) {
			self::probe_log_record( 'tencent', (string) ( $precheck['state'] ?? '' ) ); // W3-C N1 黄态（必填缺）同落最近一次测试态。
			wp_send_json_success( $precheck );
		}

		$settings = array_merge( $stored, array( 'captcha' => array_merge( $stored['captcha'], array( 'tencent' => $tencent ) ) ) );

		$driver = new TencentCaptchaDriver(
			$settings,
			new RemoteClient() // WP 边界：默认 wp_remote_post 传输（探测一次，不计 usage）。
		);
		$state = CredentialProbe::classify( $driver->probe_credentials() );
		self::probe_log_record( 'tencent', (string) ( $state['state'] ?? '' ) ); // W3-C N1 分类完成后落库（message 不入库）。
		wp_send_json_success( $state );
	}

	/**
	 * Tab② 阿里「测试凭据」AJAX 端点（【v1.5·B3】：03 §1 契约④同款——nonce +
	 * manage_options 双闸；结果记录最近一次测试状态与时间——probe log，N1）。
	 *
	 * 表单值（password 空串 = 回落已存值）+ 假参实调（VerifyIntelligentCaptcha 全参假票）
	 * → 三态呈现（CredentialProbe::classify_aliyun，DESIGN-D2 §1.4 判定法）。
	 *
	 * @return void
	 */
	public static function ajax_test_credentials_aliyun(): void {
		check_ajax_referer( self::AJAX_TEST_CREDENTIALS_ALIYUN, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions: manage_options required.', 'wink-wp-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$stored  = get_option( self::OPTION_NAME, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$stored  = SettingsSchema::merge_defaults( $stored );
		$current = isset( $stored['captcha']['aliyun'] ) && is_array( $stored['captcha']['aliyun'] ) ? $stored['captcha']['aliyun'] : array();

		// 表单值覆盖（password 空串保留已存值——与保存契约同语义）。
		$aliyun = array(
			'access_key_id'     => self::probe_field( $post, 'ali_access_key_id', (string) ( $current['access_key_id'] ?? '' ) ),
			'access_key_secret' => self::probe_field( $post, 'ali_access_key_secret', (string) ( $current['access_key_secret'] ?? '' ) ),
			'scene_id'          => isset( $post['ali_scene_id'] ) && is_scalar( $post['ali_scene_id'] ) ? sanitize_text_field( (string) $post['ali_scene_id'] ) : (string) ( $current['scene_id'] ?? '' ),
			'prefix'            => isset( $post['ali_prefix'] ) && is_scalar( $post['ali_prefix'] ) ? sanitize_text_field( (string) $post['ali_prefix'] ) : (string) ( $current['prefix'] ?? '' ),
		);

		$precheck = CredentialProbe::local_precheck_aliyun( $aliyun );
		if ( null !== $precheck ) {
			self::probe_log_record( 'aliyun', (string) ( $precheck['state'] ?? '' ) ); // W3-C N1 黄态同落最近一次测试态。
			wp_send_json_success( $precheck );
		}

		$settings = array_merge( $stored, array( 'captcha' => array_merge( $stored['captcha'], array( 'aliyun' => $aliyun ) ) ) );

		$driver = new AliyunCaptchaDriver(
			$settings,
			new RemoteClient() // WP 边界：默认 wp_remote_post 传输（探测一次，不计 usage）。
		);
		$state = CredentialProbe::classify_aliyun( $driver->probe_credentials() );
		self::probe_log_record( 'aliyun', (string) ( $state['state'] ?? '' ) ); // W3-C N1 分类完成后落库（message 不入库）。
		wp_send_json_success( $state );
	}

	/**
	 * 测试凭据表单字段（password 空串回落已存值）。
	 *
	 * @param array<string, mixed> $post    AJAX 提交。
	 * @param string               $key     字段名。
	 * @param string               $fallback 已存值。
	 * @return string
	 */
	private static function probe_field( array $post, string $key, string $fallback ): string {
		if ( ! isset( $post[ $key ] ) || ! is_scalar( $post[ $key ] ) ) {
			return $fallback;
		}
		$value = sanitize_text_field( (string) $post[ $key ] );
		return '' !== $value ? $value : $fallback;
	}

	/**
	 * Tab③ TMS「测试凭据」AJAX 端点（C4；03 §1 契约④：nonce + manage_options 双闸）。
	 *
	 * 表单值（password 空串 = 回落已存值）+ 良性样本文本真调用 → 三态呈现
	 * （CredentialProbe::classify_tms）；绿态顺带解除 tencent_tms 当日停转
	 * （02 §5「重存密钥并点自检通过」的解除条件落点）。
	 *
	 * @return void
	 */
	public static function ajax_test_credentials_tms(): void {
		check_ajax_referer( self::AJAX_TEST_CREDENTIALS_TMS, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions: manage_options required.', 'wink-wp-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$stored  = get_option( self::OPTION_NAME, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$stored  = SettingsSchema::merge_defaults( $stored );
		$current = isset( $stored['review']['cloud']['tencent_tms'] ) && is_array( $stored['review']['cloud']['tencent_tms'] ) ? $stored['review']['cloud']['tencent_tms'] : array();

		// 表单值覆盖（password 空串保留已存值——与保存契约同语义）。
		$tms = array(
			'secret_id'    => self::probe_field( $post, 'tms_secret_id', (string) ( $current['secret_id'] ?? '' ) ),
			'secret_key'   => self::probe_field( $post, 'tms_secret_key', (string) ( $current['secret_key'] ?? '' ) ),
			'biz_type'     => self::probe_field( $post, 'tms_biz_type', (string) ( $current['biz_type'] ?? '' ) ),
		);

		if ( '' === $tms['secret_id'] || '' === $tms['secret_key'] ) {
			$missing = array();
			if ( '' === $tms['secret_id'] ) {
				$missing[] = 'SecretId';
			}
			if ( '' === $tms['secret_key'] ) {
				$missing[] = 'SecretKey';
			}
			self::probe_log_record( 'tencent_tms', 'yellow' ); // W3-C N1 黄态（必填缺）同落最近一次测试态。
			wp_send_json_success( array(
				'state'   => 'yellow',
				'message' => __( 'Required fields missing: ', 'wink-wp-gatekeeper' ) . implode( '、', $missing ) . __( ' — fill them in and test again.', 'wink-wp-gatekeeper' ),
				'missing' => $missing,
				'code'    => '',
			) );
		}

		$settings = $stored;
		$settings['review']['cloud']['tencent_tms'] = array_merge( $current, $tms );

		$driver = new TencentTmsDriver(
			$settings,
			new RemoteClient() // WP 边界：默认 wp_remote_post 传输（探测一次，不计 usage）。
		);
		$state = CredentialProbe::classify_tms( $driver->probe_credentials() );
		self::probe_log_record( 'tencent_tms', (string) ( $state['state'] ?? '' ) ); // W3-C N1 分类完成后落库（message 不入库）。

		// 绿态解除当日停转（02 §5 解除条件：自检通过；probe 不计 usage）。
		if ( 'green' === $state['state'] ) {
			DriverHealth::clear_option( TencentTmsDriver::DRIVER_ID );
		}

		wp_send_json_success( $state );
	}

	/**
	 * Tab③ 阿里 PLUS「测试凭据」AJAX 端点（【v1.5·B5】05 §2.5 凭据自检三态；03 §1 契约④：
	 * nonce + manage_options 双闸）。
	 *
	 * 表单值（password 空串 = 回落已存值）+ 良性短文本真调用 → 三态呈现
	 * （CredentialProbe::classify_aliyun_green）；绿态顺带解除 aliyun_green 当日停转
	 * （02 §5「自检通过」解除条件落点——TMS 先例同款）。
	 *
	 * @return void
	 */
	public static function ajax_test_credentials_aliyun_green(): void {
		check_ajax_referer( self::AJAX_TEST_CREDENTIALS_ALIYUN_GREEN, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions: manage_options required.', 'wink-wp-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$stored  = get_option( self::OPTION_NAME, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$stored  = SettingsSchema::merge_defaults( $stored );
		$current = isset( $stored['review']['cloud']['aliyun_green'] ) && is_array( $stored['review']['cloud']['aliyun_green'] )
			? $stored['review']['cloud']['aliyun_green']
			: array();

		// 表单值覆盖（password 空串保留已存值——与保存契约同语义）。
		$green = array(
			'access_key_id'     => self::probe_field( $post, 'green_access_key_id', (string) ( $current['access_key_id'] ?? '' ) ),
			'access_key_secret' => self::probe_field( $post, 'green_access_key_secret', (string) ( $current['access_key_secret'] ?? '' ) ),
			'service_nickname'  => self::probe_field( $post, 'green_service_nickname', (string) ( $current['service_nickname'] ?? '' ) ),
			'service_comment'   => self::probe_field( $post, 'green_service_comment', (string) ( $current['service_comment'] ?? '' ) ),
		);

		// 本地预检（黄态）：必填缺逐字段列出，不发远端调用（05 §2.5 黄态条款）。
		$precheck = CredentialProbe::local_precheck_aliyun_green( $green );
		if ( null !== $precheck ) {
			self::probe_log_record( 'aliyun_green', (string) ( $precheck['state'] ?? '' ) ); // W3-C N1 黄态同落最近一次测试态。
			wp_send_json_success( array(
				'state'   => $precheck['state'],
				'message' => $precheck['message'],
				'missing' => $precheck['missing'],
				'code'    => '',
			) );
		}

		$settings                             = $stored;
		$settings['review']['cloud']['aliyun_green'] = array_merge( $current, $green );

		$driver = new AliyunGreenPlusDriver(
			$settings,
			new RemoteClient() // WP 边界：默认 wp_remote_post 传输（探测一次，不计 usage）。
		);
		$state = CredentialProbe::classify_aliyun_green( $driver->probe_credentials() );
		self::probe_log_record( 'aliyun_green', (string) ( $state['state'] ?? '' ) ); // W3-C N1 分类完成后落库（message 不入库）。

		// 绿态解除当日停转（02 §5 解除条件：自检通过；probe 不计 usage）。
		if ( 'green' === $state['state'] ) {
			DriverHealth::clear_option( AliyunGreenPlusDriver::DRIVER_ID );
		}

		wp_send_json_success( $state );
	}

	/**
	 * Tab③ 百度「测试凭据」AJAX 端点（【v1.5·B6】05 §2.4 凭据自检三态；03 §1 契约④：
	 * nonce + manage_options 双闸）。
	 *
	 * 表单值（password 空串 = 回落已存值）+ 良性样本文本真调用（token 流含在内）→ 三态呈现
	 * （CredentialProbe::classify_baidu）；绿态顺带解除 baidu 当日停转
	 * （02 §5「自检通过」解除条件落点——TMS/阿里先例同款）。
	 *
	 * @return void
	 */
	public static function ajax_test_credentials_baidu(): void {
		check_ajax_referer( self::AJAX_TEST_CREDENTIALS_BAIDU, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions: manage_options required.', 'wink-wp-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$stored  = get_option( self::OPTION_NAME, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$stored  = SettingsSchema::merge_defaults( $stored );
		$current = isset( $stored['review']['cloud']['baidu'] ) && is_array( $stored['review']['cloud']['baidu'] )
			? $stored['review']['cloud']['baidu']
			: array();

		// 表单值覆盖（password 空串保留已存值——与保存契约同语义）。
		$baidu = array(
			'api_key'    => self::probe_field( $post, 'baidu_api_key', (string) ( $current['api_key'] ?? '' ) ),
			'secret_key' => self::probe_field( $post, 'baidu_secret_key', (string) ( $current['secret_key'] ?? '' ) ),
		);

		// 本地预检（黄态）：必填缺逐字段列出，不发远端调用（05 §2.4 黄态条款）。
		$precheck = CredentialProbe::local_precheck_baidu( $baidu );
		if ( null !== $precheck ) {
			self::probe_log_record( 'baidu', (string) ( $precheck['state'] ?? '' ) ); // W3-C N1 黄态同落最近一次测试态。
			wp_send_json_success( array(
				'state'   => $precheck['state'],
				'message' => $precheck['message'],
				'missing' => $precheck['missing'],
				'code'    => '',
			) );
		}

		$settings = $stored;
		$settings['review']['cloud']['baidu'] = array_merge( $current, $baidu );

		$driver = new BaiduTextModerationDriver(
			$settings,
			new RemoteClient() // WP 边界：默认 wp_remote_post 传输（探测一次，不计 usage）。
		);
		$state = CredentialProbe::classify_baidu( $driver->probe_credentials() );
		self::probe_log_record( 'baidu', (string) ( $state['state'] ?? '' ) ); // W3-C N1 分类完成后落库（message 不入库）。

		// 绿态解除当日停转（02 §5 解除条件：自检通过；probe 不计 usage）。
		if ( 'green' === $state['state'] ) {
			DriverHealth::clear_option( BaiduTextModerationDriver::DRIVER_ID );
		}

		wp_send_json_success( $state );
	}

	/**
	 * password 型字段（04 §2 安全规则：不回显键值，仅「已配置✓」状态提示）。
	 *
	 * 【M6·批H·H-6】显式清除凭据控件（已配置时随附「清除此凭据」checkbox）：hidden 0
	 * 伴随域（未勾选显式提交 0=既有「空串保留」语义不动）+独立清空键名 <leaf>_clear
	 * （不进设置树——sanitize 桥清洗前剥除消费）。清除语义=勾选+留空保存 → 凭据置空串；
	 * 勾选+填新值 → 新值优先。仅 manage_options（register_setting 主保存通道双闸照旧）。
	 *
	 * @param string[] $path       设置树路径。
	 * @param bool     $configured 是否已有值。
	 * @param string   $label      字段显示名（JS 必填标红提示消费，data-wk-label）。
	 * @return void
	 */
	private static function password_field( array $path, bool $configured, string $label = '' ): void {
		$name = self::field_name( $path );
		$id   = 'wk-field-' . implode( '-', $path );
		echo '<input type="password" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="" class="regular-text" autocomplete="new-password"'
			. ' data-wk-required="1" data-wk-label="' . esc_attr( $label ) . '" data-wk-configured="' . ( $configured ? '1' : '0' ) . '" />';
		if ( $configured ) {
			echo ' <span class="wk-muted">' . esc_html__( 'Configured ✓ (save with it blank to keep it unchanged)', 'wink-wp-gatekeeper' ) . '</span>';
			$leaf       = (string) end( $path );
			$clear_name = self::field_name( array_merge( array_slice( $path, 0, -1 ), array( $leaf . '_clear' ) ) );
			$clear_id   = 'wk-clear-' . implode( '-', $path );
			echo '<label class="wk-clear-credential" for="' . esc_attr( $clear_id ) . '">'
				. '<input type="hidden" name="' . esc_attr( $clear_name ) . '" value="0" />'
				. '<input type="checkbox" id="' . esc_attr( $clear_id ) . '" name="' . esc_attr( $clear_name ) . '" value="1" /> '
				. esc_html__( 'Clear this credential', 'wink-wp-gatekeeper' )
				. '</label>';
		}
	}

	/**
	 * 表单字段名（option 数组点路径 → name="wink_gatekeeper_settings[a][b][c]"）。
	 *
	 * @param string[] $path
	 * @return string
	 */
	private static function field_name( array $path ): string {
		$parts = array( self::OPTION_NAME );
		foreach ( $path as $segment ) {
			$parts[] = '[' . $segment . ']';
		}
		return implode( '', $parts );
	}

	/**
	 * 报告原因截断单点件（【M6·批H·H-8】用户 2026-11-16——截断展示尾部补「…」省略号，
	 * 全消费面生效）：单一实现在 InterceptDigest::truncate_reason（纯逻辑层——渲染面与
	 * 归堆面〔UrlAuditWorker::backlog_report_view〕同规约）；本件为渲染侧薄委托。
	 *
	 * @param string $reason 原因文本。
	 * @param int    $max    截断上限（默认 40——两消费面既有口径）。
	 * @return string 未达限原样；超限=前 N 字+「…」。
	 */
	private static function truncate_reason( string $reason, int $max = 40 ): string {
		return \WinkGatekeeper\Stats\InterceptDigest::truncate_reason( $reason, $max );
	}

	/* -------------------------------------------------------------------
	 * Tab③ 内容审核（M1 本地规则四闸 + C4 云端审核 TMS：03 Tab③ v1.3 同款配置模式）
	 * ------------------------------------------------------------------ */

	private static function render_tab_review(): void {
		$settings = self::settings();
		$local    = $settings['review']['local'];
		$cloud    = $settings['review']['cloud'];
		$tms      = is_array( $cloud['tencent_tms'] ?? null ) ? $cloud['tencent_tms'] : array();
		$green    = is_array( $cloud['aliyun_green'] ?? null ) ? $cloud['aliyun_green'] : array();
		$baidu    = is_array( $cloud['baidu'] ?? null ) ? $cloud['baidu'] : array(); // 【v1.5·B6】百度文本审核子树。
		$deepseek = is_array( $cloud['deepseek'] ?? null ) ? $cloud['deepseek'] : array(); // 【M5·U4】DeepSeek 子树。
		$openai_custom = is_array( $cloud['openai_custom'] ?? null ) ? $cloud['openai_custom'] : array(); // 【M6·批E】openai_custom 子树（8 键 schema 既有）。
		$chain    = is_array( $cloud['chain'] ?? null ) ? $cloud['chain'] : array();
		$tms_in_chain   = in_array( 'tencent_tms', $chain, true );
		$green_in_chain = in_array( 'aliyun_green', $chain, true );
		$baidu_in_chain = in_array( 'baidu', $chain, true );
		$deepseek_in_chain = in_array( 'deepseek', $chain, true ); // 【M5·U4】.
		$openai_custom_in_chain = in_array( 'openai_custom', $chain, true ); // 【M6·批E】.

		// 【v1.4·R4】DriverConfigSpec 通用件消费：registry 已实装 review 驱动（local_rules 剔除）
		// + 运行时停转映射（下拉「配置错误」点）+ 链路汇总行素材。
		$cloud_registered = array();
		if ( null !== self::$driver_registry ) {
			$cloud_registered = self::$driver_registry->by_type( 'review' );
			unset( $cloud_registered['local_rules'] );
		}
		if ( array() === $cloud_registered ) {
			// registry 未注入的降级读取（单测/早载场景）——已实装四驱动硬编码兜底。
			$cloud_registered = array(
				TencentTmsDriver::DRIVER_ID        => TencentTmsDriver::registry_entry(),
				AliyunGreenPlusDriver::DRIVER_ID   => AliyunGreenPlusDriver::registry_entry(),
				BaiduTextModerationDriver::DRIVER_ID => BaiduTextModerationDriver::registry_entry(),
				\WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver::DRIVER_ID => \WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver::registry_entry(), // 【M5·U4】.
				\WinkGatekeeper\Drivers\Review\OpenAiCustomReviewDriver::DRIVER_ID => \WinkGatekeeper\Drivers\Review\OpenAiCustomReviewDriver::registry_entry(), // 【M6·批E】.
			);
		}
		$cloud_halted = array();
		foreach ( array_keys( $cloud_registered ) as $registered_id ) {
			$halt_state = DriverHealth::state_today( (string) $registered_id );
			if ( null !== $halt_state ) {
				$cloud_halted[ (string) $registered_id ] = $halt_state;
			}
		}

		// 各驱动设置子树（下拉状态点/服务端黄条/链路汇总共用素材）。
		$cloud_configs = array(
			'tencent_tms'  => $tms,
			'aliyun_green' => $green,
			'baidu'        => $baidu,
			'deepseek'     => $deepseek, // 【M5·U4】.
			'openai_custom' => $openai_custom, // 【M6·批E】.
		);

		// 当前选中驱动（下拉=chain[0] 可视面；未配置=''）。
		$cloud_selected = $tms_in_chain ? 'tencent_tms' : ( $green_in_chain ? 'aliyun_green' : ( $baidu_in_chain ? 'baidu' : ( $deepseek_in_chain ? 'deepseek' : ( $openai_custom_in_chain ? 'openai_custom' : '' ) ) ) );

		// 【M4UX UI-1-2·定稿 §3】页顶「云端审核未配置未开启」notice（判定法与 Tab① 引导
		// 同源：enabled 关 + chain 空=未配置未开启；配好即消失。enabled 开但无可用驱动的
		// 形态已由 Tab① 通栏④兜底，不在此重复）。
		if ( empty( $cloud['enabled'] ) && array() === $chain ) {
			// 【W3·批B】迁段 notice_yellow_bar（id 锚留编排位；插件级黄条类——UiM4UxF2 字面钉翻转）。
			self::notice_yellow_bar(
				'warn',
				'<strong>' . esc_html__( 'Cloud moderation is neither configured nor enabled', 'wink-wp-gatekeeper' ) . '</strong>',
				'——' . esc_html__( 'Comments currently run the local rule gates only (length / Chinese / banned words); to add cloud review, enable it on the “Cloud moderation” card below and pick a provider.', 'wink-wp-gatekeeper' )
					. '<a href="#wk-review-cloud-card">' . esc_html__( 'Configure', 'wink-wp-gatekeeper' ) . '</a>',
				'',
				'',
				'wk-review-onboarding-notice'
			);
		}

		// 服务端黄条（双层校验第二层——DriverConfigSpec 文案参数化版）。
		$cloud_notice = null;
		if ( '' !== $cloud_selected && isset( $cloud_registered[ $cloud_selected ] ) ) {
			$cloud_notice = DriverConfigSpec::server_notice(
				$cloud_selected,
				$cloud_registered[ $cloud_selected ],
				isset( $cloud_configs[ $cloud_selected ] ) ? $cloud_configs[ $cloud_selected ] : array(),
				__( 'Cloud moderation', 'wink-wp-gatekeeper' )
			);
		}
		if ( null !== $cloud_notice ) {
			// 【W3·批B】迁段 notice_yellow_bar（等价形态——hd 加粗单段）。
			self::notice_yellow_bar( 'warn', '<strong>' . esc_html( $cloud_notice ) . '</strong>' );
		}

		$wp_keys = get_option( 'disallowed_keys', '' );
		$wp_keys = is_string( $wp_keys ) && '' !== $wp_keys ? preg_split( '/\r\n|\r|\n/', $wp_keys ) : array();
		$wp_keys = array_values( array_filter( $wp_keys ) );
		?>
		<section class="wk-card">
			<h2><?php esc_html_e( 'Local rules (four gates, migrated from upstream)', 'wink-wp-gatekeeper' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-local-min"><?php esc_html_e( 'Minimum length', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-local-min" min="0" name="<?php echo esc_attr( self::field_name( array( 'review', 'local', 'min_length' ) ) ); ?>" value="<?php echo esc_attr( (string) $local['min_length'] ); ?>" class="small-text" />
						<span class="wk-muted"><?php esc_html_e( 'characters (0 = no limit)', 'wink-wp-gatekeeper' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-local-max"><?php esc_html_e( 'Maximum length', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-local-max" min="0" name="<?php echo esc_attr( self::field_name( array( 'review', 'local', 'max_length' ) ) ); ?>" value="<?php echo esc_attr( (string) $local['max_length'] ); ?>" class="small-text" />
						<span class="wk-muted"><?php esc_html_e( 'characters (default 1800)', 'wink-wp-gatekeeper' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Require Chinese', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'review', 'local', 'require_chinese' ) ) ); ?>" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'review', 'local', 'require_chinese' ) ) ); ?>" value="1" <?php checked( ! empty( $local['require_chinese'] ) ); ?> />
							<?php esc_html_e( 'Block comments without Chinese', 'wink-wp-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Blocks comments whose body contains no Chinese characters at all (the third of the upstream four gates)', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-local-words"><?php esc_html_e( 'Custom banned keywords', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<textarea id="wk-local-words" class="large-text" rows="5" name="<?php echo esc_attr( self::field_name( array( 'review', 'local', 'custom_banned_words' ) ) ); ?>" placeholder="<?php esc_attr_e( "One per line; /regex/flags entries match as regular expressions, e.g. /代.{0,3}开发票/i", 'wink-wp-gatekeeper' ); ?>"><?php echo esc_textarea( (string) $local['custom_banned_words'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Invalid regex lines are skipped automatically and logged as events (other lines are unaffected).', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Merge WordPress discussion keywords', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'review', 'local', 'use_wp_keywords' ) ) ); ?>" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'review', 'local', 'use_wp_keywords' ) ) ); ?>" value="1" <?php checked( ! empty( $local['use_wp_keywords'] ) ); ?> />
							<?php esc_html_e( 'Merges “Settings → Discussion → Disallowed comment keywords”', 'wink-wp-gatekeeper' ); ?>
						</label>
						<?php if ( array() !== $wp_keys ) : ?>
							<p class="description">
								<?php
								echo esc_html( sprintf( /* translators: %s: 关键词数 */ __( 'Currently %s:', 'wink-wp-gatekeeper' ), number_format_i18n( count( $wp_keys ) ) ) );
								echo esc_html( implode( '、', array_slice( $wp_keys, 0, 10 ) ) );
								echo count( $wp_keys ) > 10 ? esc_html( '…' ) : '';
								?>
								<a href="<?php echo esc_url( admin_url( 'options-discussion.php' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Manage', 'wink-wp-gatekeeper' ); ?></a>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<?php
				/* 【M4UX UI-1-3·定稿 §3】WP 原生讨论两开关只读状态行——查询 option 仅展示
				 * 不改行为（红线：不进本插件表单字段）；管理入口=设置 → 讨论。
				 * 【M4UX F3-5】条件展示三态：两开关任一为是（会阻碍插件自动审核）→ 渲染行
				 * 并以 warning notice 呈现；都为否 → 整行不渲染（零 DOM）。 */
				$wp_moderation = (bool) get_option( 'comment_moderation', 0 );
				$wp_whitelist  = (bool) get_option( 'comment_whitelist', 0 );
				$wp_switch_state = static function ( bool $on ): string {
					return $on
						? __( 'Enabled', 'wink-wp-gatekeeper' )
						: __( 'Disabled', 'wink-wp-gatekeeper' );
				};
				?>
				<?php if ( $wp_moderation || $wp_whitelist ) : ?>
				<tr id="wk-review-wp-switches">
					<th scope="row"><?php esc_html_e( 'Two native WP switches (read-only display)', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<?php
						// 【W3·批B】迁段 notice_yellow_bar（三段数组形态——文案/判定留编排位，
						// UiM4UxF2 UI-1-3 锚 id/红线「不进表单字段」零变化）。
						self::notice_yellow_bar(
							'warn',
							'',
							array(
								esc_html__( '“Comment must be manually approved” (Settings → Discussion):', 'wink-wp-gatekeeper' )
									. '<strong>' . esc_html( $wp_switch_state( $wp_moderation ) ) . '</strong>'
									. ( $wp_moderation ? '——' . esc_html__( 'On: even when the plugin approves a comment, it still stays hidden until you manually approve it in WP.', 'wink-wp-gatekeeper' ) : '' ),
								esc_html__( '“Comment author must have a previously approved comment”:', 'wink-wp-gatekeeper' )
									. '<strong>' . esc_html( $wp_switch_state( $wp_whitelist ) ) . '</strong>'
									. ( $wp_whitelist ? '——' . esc_html__( 'On: even when the plugin approves a comment, returning authors without an approval record still need manual approval.', 'wink-wp-gatekeeper' ) : '' ),
								esc_html__( 'This plugin shows them read-only and never changes their behavior; to adjust, go to ', 'wink-wp-gatekeeper' )
									. '<a href="' . esc_url( admin_url( 'options-discussion.php' ) ) . '">' . esc_html__( 'Settings → Discussion', 'wink-wp-gatekeeper' ) . '</a>',
							)
						);
						?>
					</td>
				</tr>
				<?php endif; ?>
			</table>
		</section>
		<section class="wk-card" id="wk-review-cloud-card">
			<h2><?php esc_html_e( 'Cloud moderation', 'wink-wp-gatekeeper' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable cloud moderation', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'enabled' ) ) ); ?>" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'enabled' ) ) ); ?>" value="1" <?php checked( ! empty( $cloud['enabled'] ) ); ?> />
							<?php esc_html_e( 'When on, submissions run through “local rules → selected cloud driver” in series (failover chain)', 'wink-wp-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Local rules always stay first in the chain and cannot be disabled; a failing driver hands over to the next automatically, and when every cloud driver fails each one\'s “failure fallback” level applies (default: pending review).', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-cloud-driver"><?php esc_html_e( 'Current cloud moderation driver', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<?php
						/* 【v1.4·反馈7+澄清B/R4 §A】下拉单选（=chain[0] 可视面）：
						 * hidden 伴随域 chain[]='' + select chain[]=<id>——sanitize text_list 剔除空项后
						 * 收口为单元素数组（选「未配置」→ [] 空 chain）。选项文字后缀状态点
						 * （registry settings.required 派生：已配置/未配置/配置错误——DriverHealth 运行时态）。
						 * 「串接顺序（拖拽）」UI 删除（澄清 B：首发单主驱动从简）；chain 有序数组 schema
						 * 保留兼容未来 failover 备用链（R4 附录 A-1）。 */
						$cloud_options = DriverConfigSpec::driver_options(
							$cloud_registered,
							$cloud_configs,
							self::future_review_drivers(),
							$cloud_halted
						);
						$cloud_dots     = DriverConfigSpec::dot_labels();
						?>
						<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'chain' ) ) ); ?>[]" value="" />
						<label for="wk-cloud-driver" class="screen-reader-text"><?php esc_html_e( 'Cloud moderation driver', 'wink-wp-gatekeeper' ); ?></label>
						<select id="wk-cloud-driver" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'chain' ) ) ); ?>[]">
							<?php foreach ( $cloud_options as $cloud_option ) : ?>
								<option value="<?php echo esc_attr( $cloud_option['value'] ); ?>" <?php selected( $cloud_option['value'], $cloud_selected ); ?>>
									<?php
									$cloud_text = $cloud_option['label'];
									if ( isset( $cloud_option['dot'] ) ) {
										$cloud_text .= ' — ' . $cloud_dots[ $cloud_option['dot'] ];
									}
									echo esc_html( $cloud_text );
									?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Selecting a driver shows its settings below (each vendor saves independently and switching does not clear fields); “Not configured” = the cloud moderation stage is skipped (enabled + not configured matches Tab 2 semantics). DeepSeek is implemented; Moli is a future milestone driver.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
			</table>
		</section>
		<?php
		/* 【v1.4·反馈9/R4 §C】v1.2「顶部集中 PIPL 黄条」作废——合规行迁入各驱动配置栏
		 * （registry privacy 维渲染，禁 SettingsPage 硬编码合规文案）；集中位降级为链路汇总行（底部）。
		 * 【FIX-3·2026-11-11】三厂商配置区并列渲染（对齐 Tab② data-wk-vendor 形态）：互斥 if/elseif
		 * 分支作废（病株：只渲染 chain[0] 一个区，下拉切换无区可显）——三区恒渲染，PHP 初始
		 * hidden 按 $cloud_selected（chain[0]）；前端 admin-brand.js bindVendorSwitcher 监听
		 * #wk-cloud-driver 切换显隐（各厂商 schema 子树独立保存、切换不清空已填）；
		 * 「未配置」= 三区全 hidden + 说明行 #wk-review-unconfigured-note。 */
		$tms_meta      = isset( $cloud_registered['tencent_tms'] ) ? $cloud_registered['tencent_tms'] : TencentTmsDriver::registry_entry();
		$tms_privacy   = DriverConfigSpec::privacy_notice( $tms_meta, __( 'Tencent Cloud TMS Text Moderation', 'wink-wp-gatekeeper' ) );
		$green_meta    = isset( $cloud_registered['aliyun_green'] ) ? $cloud_registered['aliyun_green'] : AliyunGreenPlusDriver::registry_entry();
		$green_privacy = DriverConfigSpec::privacy_notice( $green_meta, __( 'Aliyun Content Security · Text Moderation Plus', 'wink-wp-gatekeeper' ) );
		$baidu_meta    = isset( $cloud_registered['baidu'] ) ? $cloud_registered['baidu'] : BaiduTextModerationDriver::registry_entry();
		$baidu_privacy = DriverConfigSpec::privacy_notice( $baidu_meta, __( 'Baidu Text Moderation', 'wink-wp-gatekeeper' ) );
		?>
		<section class="wk-card" id="wk-tms-config-area" data-wk-vendor="tencent_tms"<?php echo ( 'tencent_tms' === $cloud_selected ) ? '' : ' hidden'; ?>>
			<h2><?php esc_html_e( 'Tencent Cloud TMS text moderation · Configuration', 'wink-wp-gatekeeper' ); ?></h2>

			<?php
			// 【W3·批B】privacy 行黄条 helper 化（判定/文案留编排位——DriverPrivacyTable 契约零变化）。
			if ( null !== $tms_privacy ) :
				self::notice_yellow_bar( 'warn' === $tms_privacy['level'] ? 'warn' : 'info', '', esc_html( $tms_privacy['text'] ) );
			endif;
			?>

			<details class="wk-guide">
				<summary><?php esc_html_e( 'Setup guide (expand on first configuration)', 'wink-wp-gatekeeper' ); ?></summary>
				<ol>
					<li><?php esc_html_e( 'Enable “Content Security · Text Moderation” in the Tencent Cloud console and purchase/claim a package (TextModeration bills by policy).', 'wink-wp-gatekeeper' ); ?></li>
					<li><?php esc_html_e( 'Get the policy ID (BizType) from the Content Security console (e.g. TencentCloudDefault or a custom policy) — preferably one bound to your package.', 'wink-wp-gatekeeper' ); ?></li>
					<li><?php esc_html_e( 'Prepare a CAM key pair (Access Management → API Key Management; same system as the captcha CAM keys, so you can reuse one pair).', 'wink-wp-gatekeeper' ); ?></li>
					<li><?php esc_html_e( 'Paste below → click “Test credentials” (a normal-text live call should return Pass).', 'wink-wp-gatekeeper' ); ?></li>
				</ol>
				<p><a href="https://cloud.tencent.com/document/api/1124/51860" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'TextModeration API documentation', 'wink-wp-gatekeeper' ); ?></a></p>
			</details>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-tms-secret-id"><?php esc_html_e( 'SecretId（CAM）', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'review', 'cloud', 'tencent_tms', 'secret_id' ), '' !== (string) ( $tms['secret_id'] ?? '' ), 'SecretId（CAM）' ); ?>
						<p class="description"><?php esc_html_e( 'Cloud API key (for TC3 signing). Server-side only, never exposed to the front end.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-tms-secret-key"><?php esc_html_e( 'SecretKey（CAM）', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'review', 'cloud', 'tencent_tms', 'secret_key' ), '' !== (string) ( $tms['secret_key'] ?? '' ), 'SecretKey（CAM）' ); ?>
						<p class="description"><?php esc_html_e( 'The CAM key paired with the SecretId.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-tms-biz-type"><?php esc_html_e( 'BizType (policy ID)', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="text" id="wk-tms-biz-type" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'tencent_tms', 'biz_type' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $tms['biz_type'] ?? '' ) ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'The recognition policy ID from the Content Security console. Officially optional (blank uses the default policy); preferably fill in one bound to your package, otherwise you may get “not authorized for this API”.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
			</table>

			<?php
			// 【W3·批B】⚙ 高级配置折叠（原型 Tab③ 样张——直拒阈值/超时/日配额/故障处置收折叠；
			// 字段 name 路径零变化，闭包承载=source-scan 锚留编排体）。
			self::advanced_fold(
				'id="wk-tms-advanced"',
				__( 'Advanced (block threshold / timeout / daily quota / failure handling)', 'wink-wp-gatekeeper' ),
				static function () use ( $tms ): void {
					?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wk-tms-reject-score"><?php esc_html_e( 'Direct-block score threshold', 'wink-wp-gatekeeper' ); ?></label></th>
							<td>
								<input type="number" id="wk-tms-reject-score" min="0" max="100" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'tencent_tms', 'reject_score' ) ) ); ?>"
									value="<?php echo esc_attr( (string) ( $tms['reject_score'] ?? 80 ) ); ?>" class="small-text" />
								<span class="wk-muted"><?php esc_html_e( '(0-100, default 80)', 'wink-wp-gatekeeper' ); ?></span>
								<p class="description"><?php esc_html_e( 'Tencent must judge Block with a score ≥ this value for a direct block; below the threshold it drops to pending review (manual). Real malicious-ad samples scored 99 in testing, so the default 80 leaves a gray-zone buffer.', 'wink-wp-gatekeeper' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wk-tms-timeout"><?php esc_html_e( 'Timeout', 'wink-wp-gatekeeper' ); ?></label></th>
							<td>
								<input type="number" id="wk-tms-timeout" min="1" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'tencent_tms', 'timeout' ) ) ); ?>"
									value="<?php echo esc_attr( (string) ( $tms['timeout'] ?? 8 ) ); ?>" class="small-text" />
								<span class="wk-muted"><?php esc_html_e( 'seconds (default 8)', 'wink-wp-gatekeeper' ); ?></span>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wk-tms-quota"><?php esc_html_e( 'Daily quota', 'wink-wp-gatekeeper' ); ?></label></th>
							<td>
								<input type="number" id="wk-tms-quota" min="0" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'tencent_tms', 'daily_quota' ) ) ); ?>"
									value="<?php echo esc_attr( (string) ( $tms['daily_quota'] ?? 500 ) ); ?>" class="small-text" />
								<span class="wk-muted"><?php esc_html_e( 'calls/day (0 = unlimited) — when reached, disabled for the day and handled per “failure fallback”', 'wink-wp-gatekeeper' ); ?></span>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Failure fallback', 'wink-wp-gatekeeper' ); ?></th>
							<td>
								<?php $fail_mode = 'open' === ( $tms['fail_mode'] ?? 'review' ) ? 'open' : 'review'; ?>
								<label style="display:block;margin-bottom:6px;">
									<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'tencent_tms', 'fail_mode' ) ) ); ?>" value="review" <?php checked( 'review', $fail_mode ); ?> />
									<?php esc_html_e( 'Pending review (default) — on cloud failure or exhausted quota, comments go to the pending queue for you to handle later', 'wink-wp-gatekeeper' ); ?>
								</label>
								<label style="display:block;">
									<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'tencent_tms', 'fail_mode' ) ) ); ?>" value="open" <?php checked( 'open', $fail_mode ); ?> />
									<?php esc_html_e( 'Pass — on failure, neither blocked nor pended (local rules only)', 'wink-wp-gatekeeper' ); ?>
								</label>
							</td>
						</tr>
					</table>
					<?php
				}
			);
			?>

						<?php
			// 【W3·批B】测试凭据迁 test_credential_btn（五态徽标「未测试」初态统一；id 锚/nonce 字面量留编排位）。
			self::test_credential_btn(
				'id="wk-tms-test-credentials"',
				'id="wk-tms-test-result"',
				wp_create_nonce( self::AJAX_TEST_CREDENTIALS_TMS ),
				__( 'Test credentials', 'wink-wp-gatekeeper' ),
				'tencent_tms'
			);
			?>
			<p class="description"><?php esc_html_e( 'One live call with normal text: green = credentials work (and the day\'s pause is lifted); red = wrong keys or no package; yellow = bad parameters. The last test status and time are recorded.', 'wink-wp-gatekeeper' ); ?></p>
		</section>

		<section class="wk-card" id="wk-green-config-area" data-wk-vendor="aliyun_green"<?php echo ( 'aliyun_green' === $cloud_selected ) ? '' : ' hidden'; ?>>
			<h2><?php esc_html_e( 'Aliyun Content Security · Text Moderation PLUS · Configuration', 'wink-wp-gatekeeper' ); ?></h2>

			<?php
			// 【W3·批B】privacy 行黄条 helper 化（判定/文案留编排位——DriverPrivacyTable 契约零变化）。
			if ( null !== $green_privacy ) :
				self::notice_yellow_bar( 'warn' === $green_privacy['level'] ? 'warn' : 'info', '', esc_html( $green_privacy['text'] ) );
			endif;
			?>

			<details class="wk-guide">
				<summary><?php esc_html_e( 'Setup guide (expand on first configuration)', 'wink-wp-gatekeeper' ); ?></summary>
				<ol>
					<li><?php esc_html_e( 'Enable “Content Security · Text Moderation Plus” in the Aliyun console (TextModerationPlus, Green 2022-03-02).', 'wink-wp-gatekeeper' ); ?></li>
					<li><?php esc_html_e( 'Prepare a RAM AccessKey pair (shareable with the Captcha 2.0 RAM user) and grant it AliyunYundunGreenWebFullAccess.', 'wink-wp-gatekeeper' ); ?></li>
					<li><?php esc_html_e( 'Paste below (the dual service names default to nickname/comment_detection_pro and rarely need changes) → click “Test credentials”.', 'wink-wp-gatekeeper' ); ?></li>
					<li><?php esc_html_e( 'Verdict mapping (not admin-adjustable): RiskLevel none/low → pass, medium → pending review, high → direct block; the band boundaries live in the Aliyun console\'s rule config. For a medium/low middle band and decimal confidence scores, enable fine-grained detection in the Content Security console rules.', 'wink-wp-gatekeeper' ); ?></li>
				</ol>
				<p><a href="https://help.aliyun.com/zh/green/developer-reference/api-green-2022-03-02-textmoderationplus" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'TextModerationPlus API documentation', 'wink-wp-gatekeeper' ); ?></a></p>
			</details>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-green-ak-id"><?php esc_html_e( 'AccessKeyId', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'review', 'cloud', 'aliyun_green', 'access_key_id' ), '' !== (string) ( $green['access_key_id'] ?? '' ), 'AccessKeyId' ); ?>
						<p class="description"><?php esc_html_e( 'RAM AccessKey (for ACS3 signing). Server-side only, never exposed to the front end.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-green-ak-secret"><?php esc_html_e( 'AccessKeySecret', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'review', 'cloud', 'aliyun_green', 'access_key_secret' ), '' !== (string) ( $green['access_key_secret'] ?? '' ), 'AccessKeySecret' ); ?>
						<p class="description"><?php esc_html_e( 'The key paired with the AccessKeyId.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-green-service-nickname"><?php esc_html_e( 'Nickname service name', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="text" id="wk-green-service-nickname" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'aliyun_green', 'service_nickname' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $green['service_nickname'] ?? 'nickname_detection_pro' ) ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Nickname submission channel (default nickname_detection_pro). The nickname-tuned model catches short ad strings more precisely; rarely needs changes.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-green-service-comment"><?php esc_html_e( 'Body service name', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="text" id="wk-green-service-comment" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'aliyun_green', 'service_comment' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $green['service_comment'] ?? 'comment_detection_pro' ) ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Email + body submission channel (default comment_detection_pro).', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Dual-service submission', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'aliyun_green', 'dual_dispatch' ) ) ); ?>" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'aliyun_green', 'dual_dispatch' ) ) ); ?>" value="1" <?php checked( ! isset( $green['dual_dispatch'] ) || ! empty( $green['dual_dispatch'] ) ); ?> />
							<?php esc_html_e( 'One call for the nickname plus one for email/body (default; better accuracy, and the doubled cost is negligible)', 'wink-wp-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Off = compatibility fallback: one single call with “nickname\\nemail\\nbody” joined (same as the other cloud drivers).', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
			</table>

			<?php
			// 【W3·批B】⚙ 高级配置折叠（超时/日配额/故障处置收折叠——字段面零变化）。
			self::advanced_fold(
				'id="wk-green-advanced"',
				__( 'Advanced (timeout / daily quota / failure handling)', 'wink-wp-gatekeeper' ),
				static function () use ( $green ): void {
					?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wk-green-timeout"><?php esc_html_e( 'Timeout', 'wink-wp-gatekeeper' ); ?></label></th>
							<td>
								<input type="number" id="wk-green-timeout" min="1" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'aliyun_green', 'timeout' ) ) ); ?>"
									value="<?php echo esc_attr( (string) ( $green['timeout'] ?? 8 ) ); ?>" class="small-text" />
								<span class="wk-muted"><?php esc_html_e( 'seconds (default 8)', 'wink-wp-gatekeeper' ); ?></span>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wk-green-quota"><?php esc_html_e( 'Daily quota', 'wink-wp-gatekeeper' ); ?></label></th>
							<td>
								<input type="number" id="wk-green-quota" min="0" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'aliyun_green', 'daily_quota' ) ) ); ?>"
									value="<?php echo esc_attr( (string) ( $green['daily_quota'] ?? 500 ) ); ?>" class="small-text" />
								<span class="wk-muted"><?php esc_html_e( 'calls/day (0 = unlimited) — when reached, disabled for the day and handled per “failure fallback”; dual submission counts as two calls', 'wink-wp-gatekeeper' ); ?></span>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Failure fallback', 'wink-wp-gatekeeper' ); ?></th>
							<td>
								<?php $green_fail_mode = 'open' === ( $green['fail_mode'] ?? 'review' ) ? 'open' : 'review'; ?>
								<label style="display:block;margin-bottom:6px;">
									<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'aliyun_green', 'fail_mode' ) ) ); ?>" value="review" <?php checked( 'review', $green_fail_mode ); ?> />
									<?php esc_html_e( 'Pending review (default) — on cloud failure or exhausted quota, comments go to the pending queue for you to handle later', 'wink-wp-gatekeeper' ); ?>
								</label>
								<label style="display:block;">
									<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'aliyun_green', 'fail_mode' ) ) ); ?>" value="open" <?php checked( 'open', $green_fail_mode ); ?> />
									<?php esc_html_e( 'Pass — on failure, neither blocked nor pended (local rules only)', 'wink-wp-gatekeeper' ); ?>
								</label>
							</td>
						</tr>
					</table>
					<?php
				}
			);
			?>

						<?php
			// 【W3·批B】测试凭据迁 test_credential_btn（五态徽标「未测试」初态统一；id 锚/nonce 字面量留编排位）。
			self::test_credential_btn(
				'id="wk-green-test-credentials"',
				'id="wk-green-test-result"',
				wp_create_nonce( self::AJAX_TEST_CREDENTIALS_ALIYUN_GREEN ),
				__( 'Test credentials', 'wink-wp-gatekeeper' ),
				'aliyun_green'
			);
			?>
			<p class="description"><?php esc_html_e( 'One live call with a normal short text: green = credentials work (and the day\'s pause is lifted); red = unauthorized / in arrears / not enabled (Code=408) or wrong key / signature; yellow = bad parameters or missing required fields. The last test status and time are recorded.', 'wink-wp-gatekeeper' ); ?></p>
		</section>
		<section class="wk-card" id="wk-baidu-config-area" data-wk-vendor="baidu"<?php echo ( 'baidu' === $cloud_selected ) ? '' : ' hidden'; ?>>
			<h2><?php esc_html_e( 'Baidu Text Moderation · Configuration', 'wink-wp-gatekeeper' ); ?></h2>

			<?php
			// 【W3·批B】privacy 行黄条 helper 化（判定/文案留编排位——DriverPrivacyTable 契约零变化）。
			if ( null !== $baidu_privacy ) :
				self::notice_yellow_bar( 'warn' === $baidu_privacy['level'] ? 'warn' : 'info', '', esc_html( $baidu_privacy['text'] ) );
			endif;
			?>

			<details class="wk-guide">
				<summary><?php esc_html_e( 'Setup guide (expand on first configuration)', 'wink-wp-gatekeeper' ); ?></summary>
				<ol>
					<li><?php esc_html_e( 'Enable “Content Moderation · Text Moderation” in the Baidu AI Cloud console (current v2 endpoint, text moderation is pay-as-you-go).', 'wink-wp-gatekeeper' ); ?></li>
					<li><?php esc_html_e( 'Create an app under “Application List” in the console to get the API Key and Secret Key (the AppID is just a console label and never appears in requests).', 'wink-wp-gatekeeper' ); ?></li>
					<li><?php esc_html_e( 'Paste below → click “Test credentials” (a normal-text live call should return Compliant).', 'wink-wp-gatekeeper' ); ?></li>
					<li><?php esc_html_e( 'Verdict mapping (not admin-adjustable): conclusionType 1 compliant → pass, 2 non-compliant → direct block, 3 suspected → pending review; the access_token is cached and reused by the plugin (30-day validity, no manual management).', 'wink-wp-gatekeeper' ); ?></li>
				</ol>
				<p><a href="https://cloud.baidu.com/doc/ANTIPORN/s/Rk3h6xb3i" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Text Moderation v2 API documentation', 'wink-wp-gatekeeper' ); ?></a></p>
			</details>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-baidu-api-key"><?php esc_html_e( 'API Key', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'review', 'cloud', 'baidu', 'api_key' ), '' !== (string) ( $baidu['api_key'] ?? '' ), 'API Key' ); ?>
						<p class="description"><?php esc_html_e( 'Baidu AI Cloud app API Key (for client_credentials auth). Server-side only, never exposed to the front end.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-baidu-secret-key"><?php esc_html_e( 'Secret Key', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'review', 'cloud', 'baidu', 'secret_key' ), '' !== (string) ( $baidu['secret_key'] ?? '' ), 'Secret Key' ); ?>
						<p class="description"><?php esc_html_e( 'The Secret Key paired with the API Key (the access_token is valid 30 days and cached/reused by the plugin).', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
			</table>

			<?php
			// 【W3·批B】⚙ 高级配置折叠（超时/日配额/故障处置收折叠——字段面零变化）。
			self::advanced_fold(
				'id="wk-baidu-advanced"',
				__( 'Advanced (timeout / daily quota / failure handling)', 'wink-wp-gatekeeper' ),
				static function () use ( $baidu ): void {
					?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wk-baidu-timeout"><?php esc_html_e( 'Timeout', 'wink-wp-gatekeeper' ); ?></label></th>
							<td>
								<input type="number" id="wk-baidu-timeout" min="1" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'baidu', 'timeout' ) ) ); ?>"
									value="<?php echo esc_attr( (string) ( $baidu['timeout'] ?? 8 ) ); ?>" class="small-text" />
								<span class="wk-muted"><?php esc_html_e( 'seconds (default 8)', 'wink-wp-gatekeeper' ); ?></span>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wk-baidu-quota"><?php esc_html_e( 'Daily quota', 'wink-wp-gatekeeper' ); ?></label></th>
							<td>
								<input type="number" id="wk-baidu-quota" min="0" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'baidu', 'daily_quota' ) ) ); ?>"
									value="<?php echo esc_attr( (string) ( $baidu['daily_quota'] ?? 500 ) ); ?>" class="small-text" />
								<span class="wk-muted"><?php esc_html_e( 'calls/day (0 = unlimited) — when reached, disabled for the day and handled per “failure fallback” (billing guard, project brief §6.4)', 'wink-wp-gatekeeper' ); ?></span>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Failure fallback', 'wink-wp-gatekeeper' ); ?></th>
							<td>
								<?php $baidu_fail_mode = 'open' === ( $baidu['fail_mode'] ?? 'review' ) ? 'open' : 'review'; ?>
								<label style="display:block;margin-bottom:6px;">
									<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'baidu', 'fail_mode' ) ) ); ?>" value="review" <?php checked( 'review', $baidu_fail_mode ); ?> />
									<?php esc_html_e( 'Pending review (default) — on cloud failure or exhausted quota, comments go to the pending queue for you to handle later', 'wink-wp-gatekeeper' ); ?>
								</label>
								<label style="display:block;">
									<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'baidu', 'fail_mode' ) ) ); ?>" value="open" <?php checked( 'open', $baidu_fail_mode ); ?> />
									<?php esc_html_e( 'Pass — on failure, neither blocked nor pended (local rules only)', 'wink-wp-gatekeeper' ); ?>
								</label>
							</td>
						</tr>
					</table>
					<?php
				}
			);
			?>

						<?php
			// 【W3·批B】测试凭据迁 test_credential_btn（五态徽标「未测试」初态统一；id 锚/nonce 字面量留编排位）。
			self::test_credential_btn(
				'id="wk-baidu-test-credentials"',
				'id="wk-baidu-test-result"',
				wp_create_nonce( self::AJAX_TEST_CREDENTIALS_BAIDU ),
				__( 'Test credentials', 'wink-wp-gatekeeper' ),
				'baidu'
			);
			?>
			<p class="description"><?php esc_html_e( 'One live call with normal text: green = credentials work (and the day\'s pause is lifted); red = auth failed (wrong API Key / Secret Key or service not enabled); yellow = bad parameters or missing required fields. The last test status and time are recorded.', 'wink-wp-gatekeeper' ); ?></p>
		</section>

		<section class="wk-card" id="wk-review-deepseek-card" data-wk-vendor="deepseek"<?php echo ( 'deepseek' === $cloud_selected ) ? '' : ' hidden'; ?>>
			<h2><?php esc_html_e( 'DeepSeek (AI verdict) · Configuration', 'wink-wp-gatekeeper' ); ?></h2>

			<?php
			$deepseek_meta    = isset( $cloud_registered['deepseek'] ) ? $cloud_registered['deepseek'] : \WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver::registry_entry();
			$deepseek_privacy = DriverConfigSpec::privacy_notice( $deepseek_meta, 'DeepSeek' );
			if ( null !== $deepseek_privacy ) :
				// 【W3·批B】privacy 行黄条 helper 化（判定/文案留编排位——契约零变化）。
				self::notice_yellow_bar( 'warn' === $deepseek_privacy['level'] ? 'warn' : 'info', '', esc_html( $deepseek_privacy['text'] ) );
			endif;
			?>

			<details class="wk-guide">
				<summary><?php esc_html_e( 'Setup guide (expand on first configuration)', 'wink-wp-gatekeeper' ); ?></summary>
				<ol>
					<li><?php esc_html_e( 'Create an API Key on the DeepSeek platform (platform.deepseek.com) and top up (pay-as-you-go; one verdict costs roughly a few hundred tokens).', 'wink-wp-gatekeeper' ); ?></li>
					<li><?php esc_html_e( 'Paste the API Key below → click “Test credentials” (a zero-cost balance probe — you also get to see the balance).', 'wink-wp-gatekeeper' ); ?></li>
					<li><?php esc_html_e( '“Low-confidence auto-upgrade” is on by default — see the switch row below (once per passage).', 'wink-wp-gatekeeper' ); ?></li>
					<li><?php esc_html_e( 'Verdict mapping (not admin-adjustable): confidence ≥0.8 is taken as high confidence, <0.8 always goes to pending review; an AI pass never approves a comment by itself (it only tightens, never loosens).', 'wink-wp-gatekeeper' ); ?></li>
				</ol>
				<p><a href="https://api-docs.deepseek.com/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'DeepSeek API documentation', 'wink-wp-gatekeeper' ); ?></a></p>
			</details>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-deepseek-api-key"><?php esc_html_e( 'API Key', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'review', 'cloud', 'deepseek', 'api_key' ), '' !== (string) ( $deepseek['api_key'] ?? '' ), 'API Key' ); ?>
						<p class="description"><?php esc_html_e( 'Server-side only, never exposed to the front end.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-deepseek-base-url"><?php esc_html_e( 'Endpoint URL', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="url" id="wk-deepseek-base-url" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'deepseek', 'base_url' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $deepseek['base_url'] ?? 'https://api.deepseek.com' ) ); ?>" class="regular-text code">
						<p class="description"><?php esc_html_e( 'Pre-filled with the official address; rarely needs changing.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-deepseek-model"><?php esc_html_e( 'Verdict model', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<select id="wk-deepseek-model" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'deepseek', 'model' ) ) ); ?>">
							<option value="deepseek-flash" <?php selected( (string) ( $deepseek['model'] ?? 'deepseek-flash' ), 'deepseek-flash' ); ?>><?php esc_html_e( 'Standard (deepseek-flash, cheap and fast)', 'wink-wp-gatekeeper' ); ?></option>
							<option value="deepseek-reasoner" <?php selected( (string) ( $deepseek['model'] ?? '' ), 'deepseek-reasoner' ); ?>><?php esc_html_e( 'Deep-think (deepseek-reasoner, stronger but slower)', 'wink-wp-gatekeeper' ); ?></option>
						</select>
					</td>
				</tr>
				<tr id="wk-deepseek-two-stage-row">
					<th scope="row"><?php esc_html_e( 'Low-confidence auto-upgrade', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'two_stage' ) ) ); ?>" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'two_stage' ) ) ); ?>" value="1" <?php checked( ! empty( $cloud['two_stage'] ?? true ) ); ?>>
							<?php esc_html_e( 'Comments the AI is unsure about are automatically re-judged once by the better “deep-think” model, at most two verdicts per comment', 'wink-wp-gatekeeper' ); ?>
						</label>
					</td>
				</tr>
				<tr id="wk-deepseek-upgrade-model-row"<?php echo ! empty( $cloud['two_stage'] ?? true ) ? '' : ' hidden'; ?>>
					<th scope="row"><label for="wk-deepseek-upgrade-model"><?php esc_html_e( 'Upgrade model', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<select id="wk-deepseek-upgrade-model" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'upgrade_model' ) ) ); ?>">
							<option value="deepseek-reasoner" <?php selected( (string) ( $cloud['upgrade_model'] ?? 'deepseek-reasoner' ), 'deepseek-reasoner' ); ?>><?php esc_html_e( 'Deep-think (deepseek-reasoner)', 'wink-wp-gatekeeper' ); ?></option>
							<option value="deepseek-flash" <?php selected( (string) ( $cloud['upgrade_model'] ?? '' ), 'deepseek-flash' ); ?>><?php esc_html_e( 'Standard (deepseek-flash)', 'wink-wp-gatekeeper' ); ?></option>
						</select>
					</td>
				</tr>
			</table>

			<?php
			$ds_usage = \WinkGatekeeper\Stats\Usage::get();
			$ds_used  = \WinkGatekeeper\Stats\Usage::daily_total_for( $ds_usage, 'deepseek' );
			$ds_quota = max( 0, (int) ( $deepseek['daily_quota'] ?? 200 ) );
			// 【W3·批B】⚙ 高级配置折叠（降级方式/今日判定额度收折叠；两段式行组保留可见——施工图保留清单；
			// 字段面零变化，$ds_* 取值上移闭包外）。
			self::advanced_fold(
				'id="wk-deepseek-advanced"',
				__( 'Advanced (fallback / today\'s verdict quota)', 'wink-wp-gatekeeper' ),
				static function () use ( $deepseek, $ds_used, $ds_quota ): void {
					?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Fallback behavior', 'wink-wp-gatekeeper' ); ?></th>
							<td>
								<select name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'deepseek', 'fail_mode' ) ) ); ?>">
									<option value="review" <?php selected( (string) ( $deepseek['fail_mode'] ?? 'review' ), 'review' ); ?>><?php esc_html_e( 'On failure, comments go to pending review (recommended)', 'wink-wp-gatekeeper' ); ?></option>
									<option value="open" <?php selected( (string) ( $deepseek['fail_mode'] ?? '' ), 'open' ); ?>><?php esc_html_e( 'On failure, comments pass as usual (not recommended)', 'wink-wp-gatekeeper' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( '8-second timeout (guardrail range); the AI verdict only tightens: even a pass never approves a comment.', 'wink-wp-gatekeeper' ); ?></p>
							</td>
						</tr>
						<tr id="wk-deepseek-quota-line">
							<th scope="row"><?php esc_html_e( 'Today\'s verdict quota', 'wink-wp-gatekeeper' ); ?></th>
							<td>
								<input type="number" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'deepseek', 'daily_quota' ) ) ); ?>" value="<?php echo esc_attr( (string) $ds_quota ); ?>" class="small-text" min="0">
								<?php esc_html_e( 'verdicts (0 = unlimited)', 'wink-wp-gatekeeper' ); ?>
								<?php /* translators: 1: verdicts issued today, 2: remaining daily quota */ ?>
								<p class="description"><?php printf( esc_html__( '%1$d verdicts issued today · %2$s left in quota.', 'wink-wp-gatekeeper' ), (int) $ds_used, 0 === $ds_quota ? '∞' : (string) max( 0, $ds_quota - $ds_used ) ); ?></p>
							</td>
						</tr>
					</table>
					<?php
				}
			);
			?>

						<?php
			// 【W3·批B】测试凭据迁 test_credential_btn（五态徽标「未测试」初态统一；id 锚/nonce 字面量留编排位）。
			self::test_credential_btn(
				'id="wk-deepseek-test-btn"',
				'id="wk-deepseek-test-result"',
				wp_create_nonce( self::AJAX_TEST_CREDENTIALS_DEEPSEEK ),
				__( 'Test credentials', 'wink-wp-gatekeeper' ),
				'deepseek'
			);
			?>
			<p class="description"><?php esc_html_e( 'Balance probe (zero cost): green = credentials valid and account usable (balance shown); yellow = account temporarily unusable (possibly in arrears); red = invalid key or unreachable; yellow = API Key blank. The last test status and time are recorded. Custom endpoints are probed via the model list (OpenAI-compatible, no standard balance API).', 'wink-wp-gatekeeper' ); ?></p>
		</section>

		<?php
		/* 【M6·批E】Tab③ openai_custom 配置卡（设计 §2.1——deepseek 卡形态参照，差异：
		 * 接入地址空占位+路径前缀提示（站长全量配置无官方预填）；判定模型/升级用模型=
		 * 手填 text（泛端点无静态模型集）；测试凭据=/models 探活（泛端点无标准余额 API）。
		 * system_prompt/min_confidence 高级键不开 UI（deepseek 卡对称——两键保留
		 * sanitize/schema 通道，未来「高级折叠区」统一开）。 */
		$oc_meta    = isset( $cloud_registered['openai_custom'] ) ? $cloud_registered['openai_custom'] : \WinkGatekeeper\Drivers\Review\OpenAiCustomReviewDriver::registry_entry();
		$oc_privacy = DriverConfigSpec::privacy_notice( $oc_meta, __( 'Custom OpenAI-compatible endpoint', 'wink-wp-gatekeeper' ) );
		?>
		<section class="wk-card" id="wk-review-openai-custom-card" data-wk-vendor="openai_custom"<?php echo ( 'openai_custom' === $cloud_selected ) ? '' : ' hidden'; ?>>
			<h2><?php esc_html_e( 'Custom OpenAI-compatible endpoint (AI verdict) · Configuration', 'wink-wp-gatekeeper' ); ?></h2>

			<?php
			// 【W3·批B】privacy 行黄条 helper 化（判定/文案留编排位——契约零变化）。
			if ( null !== $oc_privacy ) :
				self::notice_yellow_bar( 'warn' === $oc_privacy['level'] ? 'warn' : 'info', '', esc_html( $oc_privacy['text'] ) );
			endif;
			?>

			<details class="wk-guide">
				<summary><?php esc_html_e( 'Setup guide (expand on first configuration)', 'wink-wp-gatekeeper' ); ?></summary>
				<ol>
					<li><?php esc_html_e( 'The endpoint must provide chat/completions (OpenAI-compatible) — relays / self-hosted gateways (LiteLLM-style) / any compatible vendor all work.', 'wink-wp-gatekeeper' ); ?></li>
					<li><?php esc_html_e( 'Fill in the endpoint URL (mind the path prefix: the plugin appends /chat/completions to what you enter — for the official OpenAI family, stop at …/v1).', 'wink-wp-gatekeeper' ); ?></li>
					<li><?php esc_html_e( 'Fill in the API Key and model name → click “Test credentials” (model-list probe, zero tokens — generic endpoints have no standard balance API, so no balance is shown).', 'wink-wp-gatekeeper' ); ?></li>
					<li><?php esc_html_e( 'Reachability and compliance are on you: data goes to the jurisdiction of the endpoint you enter (private-network addresses are not supported — the transport-layer private-address refusal red line wins).', 'wink-wp-gatekeeper' ); ?></li>
				</ol>
			</details>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-field-review-cloud-openai-custom-api_key"><?php esc_html_e( 'API Key', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'review', 'cloud', 'openai_custom', 'api_key' ), '' !== (string) ( $openai_custom['api_key'] ?? '' ), 'API Key' ); ?>
						<p class="description"><?php esc_html_e( 'The API Key issued by that endpoint (relays / gateways each issue their own). Server-side only, never exposed to the front end.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-openai-custom-base-url"><?php esc_html_e( 'Endpoint URL', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="url" id="wk-openai-custom-base-url" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'openai_custom', 'base_url' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $openai_custom['base_url'] ?? '' ) ); ?>" class="regular-text code"
							placeholder="<?php esc_attr_e( 'https://your-endpoint/v1', 'wink-wp-gatekeeper' ); ?>" />
						<p class="description"><?php esc_html_e( 'Enter the root URL of your OpenAI-compatible endpoint — the plugin appends /chat/completions to it. For the official OpenAI family stop at https://…/v1; for official DeepSeek use https://api.deepseek.com (without /v1).', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-openai-custom-model"><?php esc_html_e( 'Verdict model', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="text" id="wk-openai-custom-model" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'openai_custom', 'model' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $openai_custom['model'] ?? '' ) ); ?>" class="regular-text"
							placeholder="<?php esc_attr_e( 'Enter a model name this endpoint supports', 'wink-wp-gatekeeper' ); ?>" />
						<p class="description"><?php esc_html_e( 'The model name must match the endpoint — “Test credentials” can check it against the available list (it may still work if absent; some endpoints have incomplete lists).', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr id="wk-openai-custom-two-stage-row">
					<th scope="row"><?php esc_html_e( 'Low-confidence auto-upgrade', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'two_stage' ) ) ); ?>" value="0" />
							<input type="checkbox" id="wk-openai-custom-two-stage" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'two_stage' ) ) ); ?>" value="1" <?php checked( ! empty( $cloud['two_stage'] ?? true ) ); ?>>
							<?php esc_html_e( 'Comments the AI is unsure about are automatically re-judged once by a stronger model, at most two verdicts per comment', 'wink-wp-gatekeeper' ); ?>
						</label>
					</td>
				</tr>
				<tr id="wk-openai-custom-upgrade-model-row"<?php echo ! empty( $cloud['two_stage'] ?? true ) ? '' : ' hidden'; ?>>
					<th scope="row"><label for="wk-openai-custom-upgrade-model"><?php esc_html_e( 'Upgrade model', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<?php /* 【M6·批E·R1 裁定】手填 text+留空短路（不走子树加键）：顶层
							upgrade_model 为 deepseek/openai_custom 共用键——从 DeepSeek 切来
							的残留值在此可见可清理（可见=可清理，不隐式吞）；留空=基类守卫
							短路两段式（不发起 r2 调用）。 */ ?>
						<input type="text" id="wk-openai-custom-upgrade-model" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'upgrade_model' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $cloud['upgrade_model'] ?? '' ) ); ?>" class="regular-text"
							placeholder="<?php esc_attr_e( 'Enter a stronger model this endpoint supports; blank = no upgrade', 'wink-wp-gatekeeper' ); ?>" />
						<p class="description"><?php esc_html_e( 'Shares the same key with DeepSeek: an upgrade model name used for DeepSeek lingers here — clear it if you do not want upgrades (blank = no second verdict).', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
			</table>

			<?php
			$oc_usage = \WinkGatekeeper\Stats\Usage::get();
			$oc_used  = \WinkGatekeeper\Stats\Usage::daily_total_for( $oc_usage, 'openai_custom' );
			$oc_quota = max( 0, (int) ( $openai_custom['daily_quota'] ?? 200 ) );
			// 【W3·批B】⚙ 高级配置折叠（降级方式/今日判定额度收折叠；手填模型/升级模型行组保留可见——施工图保留清单；
			// 字段面零变化，$oc_* 取值上移闭包外）。
			self::advanced_fold(
				'id="wk-openai-custom-advanced"',
				__( 'Advanced (fallback / today\'s verdict quota)', 'wink-wp-gatekeeper' ),
				static function () use ( $openai_custom, $oc_used, $oc_quota ): void {
					?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Fallback behavior', 'wink-wp-gatekeeper' ); ?></th>
							<td>
								<select name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'openai_custom', 'fail_mode' ) ) ); ?>">
									<option value="review" <?php selected( (string) ( $openai_custom['fail_mode'] ?? 'review' ), 'review' ); ?>><?php esc_html_e( 'On failure, comments go to pending review (recommended)', 'wink-wp-gatekeeper' ); ?></option>
									<option value="open" <?php selected( (string) ( $openai_custom['fail_mode'] ?? '' ), 'open' ); ?>><?php esc_html_e( 'On failure, comments pass as usual (not recommended)', 'wink-wp-gatekeeper' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Timeout defaults to 8 seconds; the AI verdict only tightens: even a pass never approves a comment.', 'wink-wp-gatekeeper' ); ?></p>
							</td>
						</tr>
						<tr id="wk-openai-custom-quota-line">
							<th scope="row"><?php esc_html_e( 'Today\'s verdict quota', 'wink-wp-gatekeeper' ); ?></th>
							<td>
								<input type="number" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'openai_custom', 'daily_quota' ) ) ); ?>" value="<?php echo esc_attr( (string) $oc_quota ); ?>" class="small-text" min="0">
								<?php esc_html_e( 'verdicts (0 = unlimited)', 'wink-wp-gatekeeper' ); ?>
								<?php /* translators: 1: verdicts issued today, 2: remaining daily quota */ ?>
								<p class="description"><?php printf( esc_html__( '%1$d verdicts issued today · %2$s left in quota.', 'wink-wp-gatekeeper' ), (int) $oc_used, 0 === $oc_quota ? '∞' : (string) max( 0, $oc_quota - $oc_used ) ); ?></p>
							</td>
						</tr>
					</table>
					<?php
				}
			);
			?>

						<?php
			// 【W3·批B】测试凭据迁 test_credential_btn（五态徽标「未测试」初态统一；id 锚/nonce 字面量留编排位）。
			self::test_credential_btn(
				'id="wk-openai-custom-test-btn"',
				'id="wk-openai-custom-test-result"',
				wp_create_nonce( self::AJAX_TEST_CREDENTIALS_OPENAI_CUSTOM ),
				__( 'Test credentials', 'wink-wp-gatekeeper' ),
				'openai_custom'
			);
			?>
			<p class="description"><?php esc_html_e( 'Model-list probe (zero tokens): green = credentials work (if a verdict model is filled in, it is also checked against the list); yellow = model not in the list or required fields missing; red = key rejected by the endpoint; gray = no /models or a transport error. Generic endpoints have no standard balance API, so no balance is shown. The last test status and time are recorded.', 'wink-wp-gatekeeper' ); ?></p>
		</section>

		<?php self::render_url_audit_card( $settings ); ?>

		<!-- 【FIX-3】未配置空态说明行（对齐 Tab② unconfiguredNote 形态；JS 切换联动显隐）。 -->
		<p id="wk-review-unconfigured-note" class="description<?php echo '' !== $cloud_selected ? ' hidden' : ''; ?>">
			<span class="wk-dot wk-dot--alert" aria-hidden="true"></span>
			<?php esc_html_e( 'Not configured = the cloud moderation stage is skipped (enabled + not configured matches Tab 2 semantics). Selecting a driver shows that vendor\'s settings below; each saves independently and switching does not clear fields.', 'wink-wp-gatekeeper' ); ?>
		</p>

			<?php
			/* 链路汇总行（03 Tab③ v1.4：链启用期间底部小字——任一配置区共用；链上任一驱动
			 * 出境/辖区未知时由消费方升级黄条（region_note 摘要已含「数据不出境/出境」短语）。 */
			$chain_summary = DriverConfigSpec::chain_summary( $chain, $cloud_registered, $cloud_configs );
			if ( null !== $chain_summary ) :
				$summary_warn = false;
				foreach ( $chain as $summary_id ) {
					if ( isset( $cloud_registered[ $summary_id ]['privacy']['cross_border'] )
						&& false !== $cloud_registered[ $summary_id ]['privacy']['cross_border'] ) {
						$summary_warn = true;
					}
				}
				?>
				<p class="description <?php echo $summary_warn ? 'wk-notice-inline-warn' : 'wk-muted'; ?>" style="margin-top:0.5rem;">
					<?php echo esc_html( $chain_summary ); ?>
				</p>
			<?php endif;
	}

	/* -------------------------------------------------------------------
	 * Tab④ IP 信誉——四分区全量（M3-IP 定稿 §6 + 03 Tab④【v1.7·M3-IP】回写版蓝本，
	 * I4 收官批；原 M0 占位三卡退役——950/fail_mode/M3 实装文案漂移一并修正）：
	 *   分区① 真实 IP 来源（network 子树 + 诊断面板嵌入位——I0 件原样挪入，行为零改动）
	 *   分区② IP 信誉查询（iprep/iprep.abuseipdb 子树 + 测试凭据 AJAX + 健康行 + 软顶黄条）
	 *   分区③ 上报（iprep.report 子树 T1–T4 + 出境黄条 + 只读闸参数 + 启用门禁状态行）
	 *   分区④ 说明卡（三态人话 + 信用免验联动 + 软顶人话句）
	 * 表单全走 register_setting 主保存通道（零新端点——除测试凭据 AJAX，§1 契约④）。
	 * ------------------------------------------------------------------ */

	private static function render_tab_iprep(): void {
		$settings = self::settings();

		/* 【AUDIT-FIX 2026-11-20·P0-E1②】CDN 代理转发头 × ip_source=direct 冲突黄条（Tab④ 页顶
		 * 常显位——部署级告警：per-IP 防线（提交限流/登录阈/事件采样/信誉归因）可能整体失效；
		 * 判定=IpResolver::proxy_header_conflict 纯函数单一来源（vendor 无关零网段知识——只认
		 * 典型代理头非空），helper 复用 notice_yellow_bar（warn 级）+ DOM 锚。 */
		$ip_source_mode = isset( $settings['network']['ip_source'] ) && is_string( $settings['network']['ip_source'] )
			? $settings['network']['ip_source'] : IpResolver::MODE_DIRECT;
		if ( IpResolver::proxy_header_conflict( $ip_source_mode, isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array() ) ) {
			self::notice_yellow_bar(
				'warn',
				'<strong>' . esc_html__( 'Proxy forwarding headers detected but the IP source is direct', 'wink-wp-gatekeeper' ) . '</strong>',
				esc_html__( 'per-IP defenses (rate limiting / login thresholds / reputation attribution) may fail entirely; choose an IP source that matches your deployment topology in the network settings (CDN sites = custom_header, self-hosted reverse proxies = xff/traverse), and verify the parsed result in the diagnostics panel below.', 'wink-wp-gatekeeper' ),
				'',
				'',
				'wk-iprep-proxy-header-alert'
			);
		}

		self::render_iprep_zone_source( $settings );
		self::render_iprep_zone_check( $settings );
		self::render_iprep_zone_report( $settings );
		self::render_iprep_zone_notes( $settings );
	}

	/**
	 * 分区① 真实 IP 来源（03 Tab④【v1.7·M3-IP】：影响验证码核验/登录计数/IP 信誉/上报
	 * 全部 IP 链路故置顶）：获取模式下拉（direct/xff/traverse/custom_header+适用拓扑注记）+
	 * 自定义头名 + 预设选择器（Cloudflare 官方段+快照核对日期；EdgeOne 空预设 UI 隐藏——
	 * 定稿 §5.3，I0 行为保持）+ 受信网段 textarea + CIDR 前端校验按钮（零新端点纪律——
	 * 服务端解析仍以 TrustedProxies::parse_cidr 为准，非法行保存时被解析层跳过并在
	 * 诊断面板「自定义网段非法行」标出）+ 诊断面板嵌入位。
	 *
	 * @param array<string, mixed> $settings 设置树（merge_defaults 后全树）。
	 * @return void
	 */
	private static function render_iprep_zone_source( array $settings ): void {
		$network = isset( $settings['network'] ) && is_array( $settings['network'] ) ? $settings['network'] : array();
		$proxies = isset( $network['trusted_proxies'] ) && is_array( $network['trusted_proxies'] ) ? $network['trusted_proxies'] : array();
		$mode    = isset( $network['ip_source'] ) && is_string( $network['ip_source'] ) ? $network['ip_source'] : IpResolver::MODE_DIRECT;
		/* 【I5·定稿 §7】站长手动白名单（iprep.whitelist）——服务端行格式校验反馈：非法行
		 * 保存时被判定层忽略（TrustedProxies::parse_cidr 既有零件），此处按存量值即时标出。 */
		$whitelist_raw   = isset( $settings['iprep']['whitelist'] ) && is_string( $settings['iprep']['whitelist'] ) ? $settings['iprep']['whitelist'] : '';
		$whitelist_lines = AbuseIpdbService::whitelist_lines_from( $whitelist_raw );
		$whitelist_bad   = ( new TrustedProxies( $whitelist_lines ) )->invalid_lines();
		$modes   = array(
			IpResolver::MODE_DIRECT        => __( 'Direct (default)', 'wink-wp-gatekeeper' ),
			IpResolver::MODE_XFF           => __( 'XFF trust chain (rightmost only)', 'wink-wp-gatekeeper' ),
			IpResolver::MODE_TRAVERSE      => __( 'traverse chain walk (multi-level self-hosted proxies)', 'wink-wp-gatekeeper' ),
			IpResolver::MODE_CUSTOM_HEADER => __( 'custom_header', 'wink-wp-gatekeeper' ),
		);
		?>
		<section class="wk-card" id="wk-iprep-source">
			<h2 class="wk-sec-title"><?php esc_html_e( 'Real IP source', 'wink-wp-gatekeeper' ); ?></h2>
			<p class="description wk-muted"><?php esc_html_e( 'This decides “where a visitor\'s real IP is read from” — captcha verification, login counting, IP reputation, and reporting all follow it. Confirm the diagnostics panel below parses correctly before configuring further.', 'wink-wp-gatekeeper' ); ?></p>

			<?php self::render_ip_diagnostic_panel( $settings ); // 【I4】I0 件原样挪入分区①——行为零改动只挪渲染位置。 ?>

			<!-- 【M4UX G2】折叠区旁常显提示一行（用户原话整理）——引导核验后按需展开。 -->
			<p class="description wk-muted"><?php esc_html_e( 'Please check whether the current IP matches your actual egress address; if not, expand the configuration.', 'wink-wp-gatekeeper' ); ?></p>

			<!-- 【M4UX G2】模式选择/自定义头/预设选择器/受信网段/白名单/CIDR 校验收进「高级设置」
			 * 折叠（details/wk-guide F3-3 同款形态）——默认展开仅诊断面板（「当前生效」行+
			 * 「手动聚合查询工具」卡）。诊断面板的红标/黄条警示不折叠常显。 -->
			<?php
			// 【W3·批B】折叠迁 advanced_fold（details.adv ⚙ 目标形态；id 锚留编排位——htmlBlock 收口标记源抽取锁死解除，内容闭包承载）。
			self::advanced_fold(
				'id="wk-iprep-source-advanced"',
				__( 'Advanced (real-IP method / custom header / trusted ranges / allowlist)', 'wink-wp-gatekeeper' ),
				static function () use ( $modes,  $mode,  $network,  $proxies,  $whitelist_raw,  $whitelist_bad ): void {
					?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-iprep-mode"><?php esc_html_e( 'Real-IP retrieval method', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<select id="wk-iprep-mode" name="<?php echo esc_attr( self::field_name( array( 'network', 'ip_source' ) ) ); ?>">
							<?php foreach ( $modes as $mode_key => $mode_label ) : ?>
								<option value="<?php echo esc_attr( $mode_key ); ?>" <?php selected( $mode, $mode_key ); ?>><?php echo esc_html( $mode_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Matching topologies: site directly exposed → direct; behind Cloudflare or another CDN → custom_header (set the header name to CF-Connecting-IP); multi-level self-hosted reverse proxy → traverse, with the proxy egress filled into trusted ranges.', 'wink-wp-gatekeeper' ); ?>
							<?php esc_html_e( 'traverse = REMOTE_ADDR must hit a trusted range, then walk the XFF right-to-left skipping trusted proxies and take the first untrusted IP — not the default: on deployments that can reach the origin directly, walking backwards would trust a forged chain.', 'wink-wp-gatekeeper' ); ?>
							<?php esc_html_e( 'When REMOTE_ADDR is outside the trusted ranges, forwarding headers are ignored outright (anti-forgery invariant).', 'wink-wp-gatekeeper' ); ?>
						</p>
					</td>
				</tr>
				<tr id="wk-iprep-custom-header-row"<?php echo IpResolver::MODE_CUSTOM_HEADER !== $mode ? ' hidden' : ''; ?>>
					<!-- 【M4UX UI-2-5】仅 ip_source=custom_header 时显示（PHP 初始态 + admin-brand.js 联动，bindVendorSwitcher 同款显隐模式）。 -->
					<th scope="row"><label for="wk-iprep-custom-header"><?php esc_html_e( 'Custom header name', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="text" id="wk-iprep-custom-header" class="regular-text" placeholder="CF-Connecting-IP"
							name="<?php echo esc_attr( self::field_name( array( 'network', 'custom_header' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $network['custom_header'] ?? '' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Takes effect when the real-IP method = custom header (a single-value IP header — Cloudflare\'s CF-Connecting-IP is the authoritative override header with the smallest forgery surface).', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Trusted ranges', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<!-- 预设选择器（定稿 §5.3：EdgeOne 官方无静态网段列表——UI 隐藏，I0 行为保持）。 -->
						<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'network', 'trusted_proxies', 'cloudflare' ) ) ); ?>" value="0" />
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'network', 'trusted_proxies', 'cloudflare' ) ) ); ?>" value="1" <?php checked( ! empty( $proxies['cloudflare'] ) ); ?> />
							<?php
							printf(
								/* translators: 1: 快照核对日期 2: 网段条数 */
								esc_html__( 'Use the official Cloudflare ranges (%1$s verified, %2$d entries for v4 + v6)', 'wink-wp-gatekeeper' ),
								esc_html( (string) TrustedProxies::PRESET_VERIFIED ),
								(int) count( TrustedProxies::CLOUDFLARE_V4 ) + count( TrustedProxies::CLOUDFLARE_V6 )
							);
							?>
						</label>
						<p class="description"><?php esc_html_e( 'EdgeOne publishes no official static range list (api.edgeone.ai/ips is dynamic with 3–6 month rotation — shipping an empty preset would mean silent no-protection), so no preset is offered; sites behind EdgeOne should hand-enter the origin ranges published in the official docs below.', 'wink-wp-gatekeeper' ); ?></p>
						<label class="screen-reader-text" for="wk-iprep-custom-cidr"><?php esc_html_e( 'Custom trusted ranges', 'wink-wp-gatekeeper' ); ?></label>
						<textarea id="wk-iprep-custom-cidr" class="large-text" rows="4" placeholder="203.0.113.0/24&#10;2001:db8::/32"
							name="<?php echo esc_attr( self::field_name( array( 'network', 'trusted_proxies', 'custom' ) ) ); ?>"><?php echo esc_textarea( (string) ( $proxies['custom'] ?? '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One CIDR per line (or a bare IP, v4/v6) — usually the egress ranges of your reverse proxy / load balancer.', 'wink-wp-gatekeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<!-- 【I5·定稿 §7 误伤防护·判定层】站长手动白名单——信誉判定豁免入口
						 （与受信网段是两个东西：受信网段管解析信任链，白名单管信誉豁免）。 -->
						<th scope="row"><label for="wk-iprep-whitelist"><?php esc_html_e( 'Allowlist (reputation exemption)', 'wink-wp-gatekeeper' ); ?></label></th>
						<td>
							<textarea id="wk-iprep-whitelist" class="large-text" rows="4" placeholder="8.8.8.8&#10;203.0.113.0/24&#10;2001:db8::/32"
								name="<?php echo esc_attr( self::field_name( array( 'iprep', 'whitelist' ) ) ); ?>"><?php echo esc_textarea( $whitelist_raw ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One IP or CIDR per line (v4/v6) — matches are never reputation-checked and never reported (exempt on both the verdict and reporting channels, zero outbound calls).', 'wink-wp-gatekeeper' ); ?></p>
							<p class="description"><?php esc_html_e( 'Versus “trusted ranges”: trusted ranges govern the parsing trust chain (whose forwarded headers to believe), while the allowlist governs reputation exemption (who is never checked) — neither substitutes for the other.', 'wink-wp-gatekeeper' ); ?></p>
							<p class="description"><?php esc_html_e( 'False-positive guard: add IPs that reputation databases misjudge, such as search-engine crawlers or office egress addresses — those visitors pass straight through without a forced captcha.', 'wink-wp-gatekeeper' ); ?></p>
							<?php if ( array() !== $whitelist_bad ) : ?>
								<p class="description">
									<span class="wk-dot wk-dot--alert" aria-hidden="true"></span>
									<?php
									printf(
										/* translators: 1: 非法行数 2: 逗号连接的非法行原文 */
										esc_html__( 'The allowlist has %1$d invalid lines (ignored on save, no effect): %2$s', 'wink-wp-gatekeeper' ),
										count( $whitelist_bad ),
										esc_html( implode( '、', $whitelist_bad ) )
									);
									?>
								</p>
							<?php endif; ?>
							<p>
								<button type="button" class="button" id="wk-iprep-whitelist-check"><?php esc_html_e( 'Validate allowlist format', 'wink-wp-gatekeeper' ); ?></button>
								<span id="wk-iprep-whitelist-result" class="wk-muted" aria-live="polite"></span>
							</p>
						</td>
					</tr>
				</table>
			<p>
				<button type="button" class="button" id="wk-iprep-cidr-check"><?php esc_html_e( 'Validate range format', 'wink-wp-gatekeeper' ); ?></button>
				<span id="wk-iprep-cidr-result" class="wk-muted" aria-live="polite"></span>
			</p>
			<p class="description"><?php esc_html_e( 'Validation is a front-end pre-check; invalid lines are skipped by the parsing layer on save and flagged under “Custom range invalid lines” in the diagnostics panel above (calibration tool: tools/api-smoke/refresh-presets.php).', 'wink-wp-gatekeeper' ); ?></p>
					<?php
				}
			);
			?>
		</section>
		<?php
	}

	/**
	 * 分区② IP 信誉查询（03 Tab④【v1.7·M3-IP】——三态语义权威=02 §2.3b 澄清 A）：
	 * enabled 开关+驱动下拉+api_key（password 型——P0-α 丢键教训）+阈值双滑杆（85/60）+
	 * 软顶 900+日配额 950（【W3·批C·N6】默认平移——免费档 1000/天留 5%）+缓存双键（cache_minutes 在 abuseipdb 子树、negative_cache_minutes
	 * 在 iprep 顶层——实况键名，渲染读实况）+timeout（1–5s 防呆范围）+「测试凭据」按钮
	 * （AJAX 三态，O5-P2-6 判定法）+健康行（state_label 三态+DriverHealth 停转态）+
	 * 软顶命中黄条（【O5 回写·P2-4】）。
	 *
	 * @param array<string, mixed> $settings 设置树。
	 * @return void
	 */
	private static function render_iprep_zone_check( array $settings ): void {
		$iprep      = isset( $settings['iprep'] ) && is_array( $settings['iprep'] ) ? $settings['iprep'] : array();
		$abuse      = isset( $iprep['abuseipdb'] ) && is_array( $iprep['abuseipdb'] ) ? $iprep['abuseipdb'] : array();
		$soft_quota = max( 0, (int) ( $abuse['soft_quota'] ?? 900 ) ); // 【W3·批C·N6】软顶出厂平移（免费档 1000/天留 5%——拍板统一口径）。
		$soft_hit   = $soft_quota > 0 && Usage::daily_total_for( Usage::get(), 'abuseipdb' ) >= $soft_quota;
		$halt       = DriverHealth::state_today( AbuseIpdbService::DRIVER_ID );
		$health     = null !== $halt
			? ( DriverHealth::STATE_QUOTA_EXHAUSTED === $halt
				? __( 'Quota exhausted (paused for today — auto-resumes tomorrow)', 'wink-wp-gatekeeper' )
				: __( 'Configuration problem (key rejected — paused for today; re-saving the key and getting a green “Test credentials” clears it)', 'wink-wp-gatekeeper' ) )
			: __( 'Normal', 'wink-wp-gatekeeper' );
		?>
		<section class="wk-card" id="wk-iprep-check">
			<h2 class="wk-sec-title"><?php esc_html_e( 'IP reputation lookup', 'wink-wp-gatekeeper' ); ?></h2>
			<p class="description wk-muted"><?php esc_html_e( 'When enabled, before comment and login verdicts the visitor\'s IP reputation score is looked up at AbuseIPDB — malicious IPs must pass human verification before going through.', 'wink-wp-gatekeeper' ); ?></p>

			<?php if ( $soft_hit ) : ?>
				<?php
				// 【W3·批B】迁段 notice_yellow_bar（软顶黄条——警示常显不折叠红线照守：黄条在折叠区外）。
				self::notice_yellow_bar(
					'warn',
					'<span class="wk-dot wk-dot--alert" aria-hidden="true"></span> <strong>' . esc_html__( 'Today\'s lookup quota is exhausted — human verification is temporarily enforced for all visitors today and auto-resumes tomorrow.', 'wink-wp-gatekeeper' ) . '</strong>',
					esc_html__( 'Soft-cap protection: once the day\'s lookup count reaches the soft cap, no more outbound calls and everything is treated strictly as “fail” — the last slice of quota is reserved as a buffer so the reputation layer never goes silent on attackers.', 'wink-wp-gatekeeper' )
				);
				?>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'iprep', 'enabled' ) ) ); ?>" value="0" />
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'iprep', 'enabled' ) ) ); ?>" value="1" <?php checked( ! empty( $iprep['enabled'] ) ); ?> />
							<?php esc_html_e( 'Enable IP reputation lookup (strengthens both the comment and login gates — the Tab 2 linked switch is on by default)', 'wink-wp-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When disabled the trust chain skips it, leaving waiver eligibility and login counts untouched; when enabled, any misconfiguration / timeout / error / over-threshold is judged strictly (treated as a fail — a captcha is shown).', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-iprep-driver"><?php esc_html_e( 'Provider', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<select id="wk-iprep-driver" name="<?php echo esc_attr( self::field_name( array( 'iprep', 'driver' ) ) ); ?>">
							<option value="abuseipdb" <?php selected( 'abuseipdb', (string) ( $iprep['driver'] ?? 'abuseipdb' ) ); ?>><?php esc_html_e( 'AbuseIPDB (IP reputation lookup)', 'wink-wp-gatekeeper' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'IP reputation providers currently available (more vendors in later milestones).', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-field-iprep-abuseipdb-api_key"><?php esc_html_e( 'API Key', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'iprep', 'abuseipdb', 'api_key' ), '' !== (string) ( $abuse['api_key'] ?? '' ), 'API Key' ); ?>
						<p class="description">
							<?php esc_html_e( 'AbuseIPDB free tier: 1,000 lookups/day (agreed baseline). Register at abuseipdb.com. Server-side only; stored values are not echoed back. Defaults: soft cap 900 / daily quota 950 (a 5% buffer each).', 'wink-wp-gatekeeper' ); ?>
							<a href="https://www.abuseipdb.com/account/api" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get an API Key', 'wink-wp-gatekeeper' ); ?></a>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Health status', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<p class="description">
							<?php if ( null !== $halt ) : ?>
								<span class="wk-dot wk-dot--alert" aria-hidden="true"></span>
							<?php else : ?>
								<span class="wk-dot wk-dot--ok" aria-hidden="true"></span>
							<?php endif; ?>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: 停转态/正常 2: 装配态标签（未启用/已启用（AbuseIPDB）） */
									__( '%1$s · %2$s', 'wink-wp-gatekeeper' ),
									$health,
									IpReputationService::state_label( $settings )
								)
							);
							?>
						</p>
					</td>
				</tr>
			</table>
			<!-- 【M4UX F3-3】阈值/软顶/日配额/缓存双键/超时收进「高级设置」折叠（details/wk-guide
			 * 既有形态）——默认展开仅 启用开关+服务商+凭据；软顶命中黄条在上方不折叠（警示常显）。 -->
			<?php
			// 【W3·批B】折叠迁 advanced_fold（details.adv ⚙ 目标形态；id 锚留编排位——htmlBlock 收口标记源抽取锁死解除，内容闭包承载）。
			self::advanced_fold(
				'id="wk-iprep-advanced"',
				__( 'Advanced (thresholds / soft cap / daily quota / cache / timeout)', 'wink-wp-gatekeeper' ),
				static function () use ( $abuse,  $iprep ): void {
					?>
				<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-iprep-malicious-score"><?php esc_html_e( 'Malicious threshold', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="range" id="wk-iprep-malicious-score" class="wk-score-slider" min="1" max="100" step="1"
							name="<?php echo esc_attr( self::field_name( array( 'iprep', 'abuseipdb', 'malicious_score' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $abuse['malicious_score'] ?? 85 ) ); ?>" />
						<output for="wk-iprep-malicious-score"><?php echo esc_html( (string) ( $abuse['malicious_score'] ?? 85 ) ); ?></output>
						<p class="description"><?php esc_html_e( 'Scores ≥ this value count as malicious (default 85) — such visitors must pass human verification. AbuseIPDB scores aggregate multiple reporters; a single report does not blacklist an IP.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-iprep-suspect-score"><?php esc_html_e( 'Suspected threshold', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="range" id="wk-iprep-suspect-score" class="wk-score-slider" min="1" max="100" step="1"
							name="<?php echo esc_attr( self::field_name( array( 'iprep', 'abuseipdb', 'suspect_score' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $abuse['suspect_score'] ?? 60 ) ); ?>" />
						<output for="wk-iprep-suspect-score"><?php echo esc_html( (string) ( $abuse['suspect_score'] ?? 60 ) ); ?></output>
						<p class="description"><?php esc_html_e( 'Scores ≥ this value count as suspected (default 60) — the same “fail” band as the malicious threshold, forcing a captcha; the two bands differ only in how events are recorded.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-iprep-soft-quota"><?php esc_html_e( 'Soft cap', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-iprep-soft-quota" min="0" class="small-text"
							name="<?php echo esc_attr( self::field_name( array( 'iprep', 'abuseipdb', 'soft_quota' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $abuse['soft_quota'] ?? 900 ) ); ?>" />
						<span class="wk-muted"><?php esc_html_e( 'calls/day (default 900; 0 = unlimited) — once reached, no more outbound calls and everything is treated strictly as “fail” (better strict than silently waved through). The free tier\'s 1,000/day (agreed baseline) keeps a 5% buffer.', 'wink-wp-gatekeeper' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-iprep-daily-quota"><?php esc_html_e( 'Daily quota', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-iprep-daily-quota" min="0" class="small-text"
							name="<?php echo esc_attr( self::field_name( array( 'iprep', 'abuseipdb', 'daily_quota' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $abuse['daily_quota'] ?? 950 ) ); ?>" />
						<span class="wk-muted"><?php esc_html_e( 'calls/day (default 950) — a 5% buffer under the free tier\'s 1,000/day (agreed baseline); once reached, paused for the day (triggered by 429, self-heals the next day).', 'wink-wp-gatekeeper' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-iprep-cache-minutes"><?php esc_html_e( 'Clean IP cache', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-iprep-cache-minutes" min="0" class="small-text"
							name="<?php echo esc_attr( self::field_name( array( 'iprep', 'abuseipdb', 'cache_minutes' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $abuse['cache_minutes'] ?? 1440 ) ); ?>" />
						<span class="wk-muted"><?php esc_html_e( 'minutes (default 1440 = 24h) — how long IPs judged “pass” stay cached, saving quota.', 'wink-wp-gatekeeper' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-iprep-negative-cache-minutes"><?php esc_html_e( 'Suspicious IP cache', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-iprep-negative-cache-minutes" min="0" class="small-text"
							name="<?php echo esc_attr( self::field_name( array( 'iprep', 'negative_cache_minutes' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $iprep['negative_cache_minutes'] ?? 60 ) ); ?>" />
						<span class="wk-muted"><?php esc_html_e( 'minutes (default 60 = 1h) — short cache for “suspected/malicious” verdicts, so a malicious IP can be re-checked and downgraded.', 'wink-wp-gatekeeper' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-iprep-timeout"><?php esc_html_e( 'Lookup timeout', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-iprep-timeout" min="1" max="5" class="small-text"
							name="<?php echo esc_attr( self::field_name( array( 'iprep', 'abuseipdb', 'timeout' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $abuse['timeout'] ?? 1 ) ); ?>" />
						<span class="wk-muted"><?php esc_html_e( 'seconds (1–5, default 1) — a timeout is treated strictly as “fail” (captcha shown); this one key is shared by login / comment pre-check / submission.', 'wink-wp-gatekeeper' ); ?></span>
					</td>
				</tr>
				</table>
					<?php
				}
			);
			?>
			<?php
			// 【W3·批A】迁段 test_credential_btn（按钮/结果位 id 锚与 nonce action 字面量
			// 留编排位——UiTab4Zones 契约钉；state 恒「未测试」=现行不落库态，N1 持久化
			// 批 C 接线读取。五态徽标/上次测试位=helper 闭集输出）。
			self::test_credential_btn(
				'id="wk-iprep-test-credentials"',
				'id="wk-iprep-test-result"',
				wp_create_nonce( self::AJAX_TEST_IPREP ),
				__( 'Test credentials', 'wink-wp-gatekeeper' ),
				'abuseipdb'
			);
			?>
			<p class="description"><?php esc_html_e( 'One minimal live lookup (reserved address 127.0.0.2, nearly zero quota): green = HTTP 200 with parseable JSON (credentials work — isPublic:false from the reserved address is still green; it is the credentials being tested, not the IP); red = 401/402 key rejected; yellow = API Key blank. The last test status and time are recorded.', 'wink-wp-gatekeeper' ); ?></p>
		</section>
		<?php
	}

	/**
	 * 分区③ 上报（M3-IP 定稿 §4——触发集 T1–T4，拍板 0.4：每面独立开关+独立阈值，
	 * 涉出境与隐私默认从关开始 T1 除外）：总开关+出境黄条（PIPL）+T1–T4 勾选组
	 * （每面开关+阈值数字框（T2/T4）+类目下拉（AbuseIpdbCategories 1–23，默认类目标注）+
	 * Comment 自定义框（placeholder 回显默认短语——UI-2-2；说明收敛为 foreach 外共享
	 * 一条——UI-2-1：前缀不可去+255 截断+出境警示+官方类目外链+占位符统一说明示例））+
	 * 冷却/限速/日顶只读展示 + 启用门禁状态行（real_ip_self_check 消费——未过=上报强制
	 * 失效无开关，定稿 §4.3 闸④）。
	 *
	 * @param array<string, mixed> $settings 设置树。
	 * @return void
	 */
	private static function render_iprep_zone_report( array $settings ): void {
		$report   = isset( $settings['iprep']['report'] ) && is_array( $settings['iprep']['report'] ) ? $settings['iprep']['report'] : array();
		$triggers = isset( $report['triggers'] ) && is_array( $report['triggers'] ) ? $report['triggers'] : array();
		$self_ok  = AbuseIpdbService::real_ip_self_check(
			isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array(), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读 $_SERVER 展示，无表单消费。
			$settings
		);

		// 触发面声明表（路径显式展开——schema 键面即渲染面，I3 注记「schema 键全集」）。
		$faces = array(
			'captcha_failed' => array(
				'title'          => __( 'T1 · Captcha ticket verification failed', 'wink-wp-gatekeeper' ),
				'desc'           => __( 'Someone submitted a forged or replayed captcha ticket — “report only with a ticket”: submissions without completed verification (no ticket) never trigger it. On by default.', 'wink-wp-gatekeeper' ),
				'toggle_path'    => array( 'iprep', 'report', 'triggers', 'captcha_failed' ),
				'threshold_path' => null, // T1 无阈值——直达四道闸.
				'category_path'  => array( 'iprep', 'report', 'category_captcha_failed' ),
				'comment_path'   => array( 'iprep', 'report', 'comment_captcha_failed' ),
				'comment_value'  => (string) ( $report['comment_captcha_failed'] ?? '' ),
				'category_value' => (int) ( $report['category_captcha_failed'] ?? 0 ),
			),
			'review_reject'  => array(
				'title'          => __( 'T2 · Cumulative cloud moderation rejections', 'wink-wp-gatekeeper' ),
				'desc'           => __( 'The same IP judged “rejected” by cloud moderation N times within 24 hours (content-spammer pattern). Off by default.', 'wink-wp-gatekeeper' ),
				'toggle_path'    => array( 'iprep', 'report', 'triggers', 'review_reject' ),
				'threshold_path' => array( 'iprep', 'report', 'triggers', 'review_reject_threshold' ),
				'threshold_value' => (int) ( $triggers['review_reject_threshold'] ?? 5 ),
				'category_path'  => array( 'iprep', 'report', 'category_review_reject' ),
				'comment_path'   => array( 'iprep', 'report', 'comment_review_reject' ),
				'comment_value'  => (string) ( $report['comment_review_reject'] ?? '' ),
				'category_value' => (int) ( $report['category_review_reject'] ?? 0 ),
			),
			'login_attempts' => array(
				'title'          => __( 'T3 · Login brute force (per-IP count saturation)', 'wink-wp-gatekeeper' ),
				'desc'           => __( 'Login failures from one IP reach the existing threshold (10/IP — reusing the login gate\'s own counter, no separate threshold). Off by default.', 'wink-wp-gatekeeper' ),
				'toggle_path'    => array( 'iprep', 'report', 'triggers', 'login_attempts' ),
				'threshold_path' => null, // T3 沿用 LoginCounters 10 次/IP 不单设.
				'category_path'  => array( 'iprep', 'report', 'category_login_attempts' ),
				'comment_path'   => array( 'iprep', 'report', 'comment_login_attempts' ),
				'comment_value'  => (string) ( $report['comment_login_attempts'] ?? '' ),
				'category_value' => (int) ( $report['category_login_attempts'] ?? 0 ),
			),
			'local_rules_fail' => array(
				'title'          => __( 'T4 · Cumulative local rule blocks', 'wink-wp-gatekeeper' ),
				'desc'           => __( 'The same IP blocked by the four local rule gates (length / no-Chinese / banned words) N times within 24 hours (both pre-check and submission count). Off by default.', 'wink-wp-gatekeeper' ),
				'toggle_path'    => array( 'iprep', 'report', 'triggers', 'local_rules_fail' ),
				'threshold_path' => array( 'iprep', 'report', 'triggers', 'local_rules_fail_threshold' ),
				'threshold_value' => (int) ( $triggers['local_rules_fail_threshold'] ?? 5 ),
				'category_path'  => array( 'iprep', 'report', 'category_local_rules_fail' ),
				'comment_path'   => array( 'iprep', 'report', 'comment_local_rules_fail' ),
				'comment_value'  => (string) ( $report['comment_local_rules_fail'] ?? '' ),
				'category_value' => (int) ( $report['category_local_rules_fail'] ?? 0 ),
			),
		);
		?>
		<section class="wk-card" id="wk-iprep-report">
			<h2 class="wk-sec-title"><?php esc_html_e( 'Malicious activity reporting', 'wink-wp-gatekeeper' ); ?></h2>
			<p class="description wk-muted"><?php esc_html_e( 'Optional capability: report confirmed malicious activity to the AbuseIPDB community reputation database, helping sites worldwide recognize these IPs. All off by default.', 'wink-wp-gatekeeper' ); ?></p>

			<?php
			// 【W3·批B】迁段 notice_yellow_bar（出境 PIPL 黄条——常显不折叠红线照守）。
			self::notice_yellow_bar(
				'warn',
				'<strong>' . esc_html__( 'When enabled, visitor IPs are submitted to the overseas service AbuseIPDB.', 'wink-wp-gatekeeper' ) . '</strong>',
				esc_html__( 'Report details are publicly visible on AbuseIPDB (no comment content); assess cross-border data compliance (PIPL) yourself.', 'wink-wp-gatekeeper' )
			);
			?>

			<p class="description">
				<?php if ( $self_ok ) : ?>
					<span class="wk-dot wk-dot--ok" aria-hidden="true"></span>
					<?php esc_html_e( 'Enablement gate: real-IP self-check passed — reporting is ready to enable.', 'wink-wp-gatekeeper' ); ?>
				<?php else : ?>
					<span class="wk-dot wk-dot--alert" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Reporting has been force-disabled: real-IP resolution is abnormal (NAT / reverse proxy both present).', 'wink-wp-gatekeeper' ); ?></strong>
					<?php esc_html_e( 'The current request comes from a private network / cannot be determined — reporting a shared egress IP would pollute the database; it auto-recovers once a trusted reverse proxy is configured in Section 1 and saved (this gate has no switch; better to under-report).', 'wink-wp-gatekeeper' ); ?>
				<?php endif; ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Reporting master switch', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'iprep', 'report', 'enabled' ) ) ); ?>" value="0" />
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'iprep', 'report', 'enabled' ) ) ); ?>" value="1" <?php checked( ! empty( $report['enabled'] ) ); ?> />
							<?php esc_html_e( 'Enable malicious activity reporting (sent only when one of the trigger surfaces in Advanced fires)', 'wink-wp-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Independent of the “IP reputation lookup” switch above — reports only, no lookups; silent send failures never affect blocking.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
			</table>
			<!-- 【M4UX F3-3】T1–T4 勾选组+备注说明+限流参数默认整组折叠（details/wk-guide 既有形态）——
			 * 总开关/出境 PIPL 黄条/启用门禁状态行不折叠常显（警示常显红线）。 -->
			<?php
			// 【W3·批B】折叠迁 advanced_fold（details.adv ⚙ 目标形态；id 锚留编排位——htmlBlock 收口标记源抽取锁死解除，内容闭包承载）。
			self::advanced_fold(
				'id="wk-iprep-report-advanced"',
				__( 'Advanced (trigger surfaces T1–T4 / notes / rate-limit parameters)', 'wink-wp-gatekeeper' ),
				static function () use ( $faces,  $triggers ): void {
					?>
				<table class="form-table" role="presentation">
				<?php foreach ( $faces as $face_key => $face ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $face['title'] ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( $face['toggle_path'] ) ); ?>" value="0" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::field_name( $face['toggle_path'] ) ); ?>" value="1" <?php checked( ! empty( self::dig( $triggers, array( $face_key ), false ) ) ); ?> />
								<?php esc_html_e( 'Enable this trigger surface', 'wink-wp-gatekeeper' ); ?>
							</label>
							<p class="description"><?php echo esc_html( $face['desc'] ); ?></p>
							<?php if ( null !== $face['threshold_path'] ) : ?>
								<p>
									<label for="wk-iprep-threshold-<?php echo esc_attr( $face_key ); ?>"><?php esc_html_e( 'Threshold N (24h window cumulative)', 'wink-wp-gatekeeper' ); ?></label>
									<input type="number" id="wk-iprep-threshold-<?php echo esc_attr( $face_key ); ?>" min="1" class="small-text"
										name="<?php echo esc_attr( self::field_name( $face['threshold_path'] ) ); ?>"
										value="<?php echo esc_attr( (string) ( $face['threshold_value'] ?? 5 ) ); ?>" />
								</p>
							<?php endif; ?>
							<p>
								<label for="wk-iprep-category-<?php echo esc_attr( $face_key ); ?>"><?php esc_html_e( 'Report category', 'wink-wp-gatekeeper' ); ?></label>
								<select id="wk-iprep-category-<?php echo esc_attr( $face_key ); ?>"
									name="<?php echo esc_attr( self::field_name( $face['category_path'] ) ); ?>">
									<option value="0" <?php selected( 0, $face['category_value'] ); ?>>
										<?php
										printf(
											/* translators: 1: 默认类目码 2: 默认类目名 */
											esc_html__( 'Use the default (%1$d %2$s)', 'wink-wp-gatekeeper' ),
											(int) AbuseIpdbCategories::default_for( $face_key ),
											esc_html( AbuseIpdbCategories::label( AbuseIpdbCategories::default_for( $face_key ) ) )
										);
										?>
									</option>
									<?php foreach ( AbuseIpdbCategories::MAP as $cat_id => $cat_label ) : ?>
										<option value="<?php echo esc_attr( (string) $cat_id ); ?>" <?php selected( $cat_id, $face['category_value'] ); ?>><?php echo esc_html( $cat_id . ' ' . $cat_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<p>
								<label class="screen-reader-text" for="wk-iprep-comment-<?php echo esc_attr( $face_key ); ?>"><?php esc_html_e( 'Custom report note', 'wink-wp-gatekeeper' ); ?></label>
								<textarea id="wk-iprep-comment-<?php echo esc_attr( $face_key ); ?>" class="large-text" rows="2"
									name="<?php echo esc_attr( self::field_name( $face['comment_path'] ) ); ?>"
									placeholder="<?php echo esc_attr( AbuseIpdbService::default_comment( $face_key ) ); ?>"><?php echo esc_textarea( $face['comment_value'] ); ?></textarea>
							</p>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Report note description (shared by T1–T4)', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<!-- 【M4UX UI-2-1】Comment 说明收敛：foreach 外共享一条（此前逐面重复 ×4——V3 收口）；
						 placeholder 说明只留 description（UI-2-2——输入框空值即回显默认短语）。 -->
						<p class="description">
							<?php esc_html_e( 'A blank note sends that trigger surface\'s default English phrase (already echoed in each input\'s placeholder). The [wink-wp-gatekeeper] prefix is added automatically on send (not removable), and over-length notes are truncated (255 characters).', 'wink-wp-gatekeeper' ); ?>
							<?php esc_html_e( 'Do not enter sensitive site information or personal data — AbuseIPDB report details are publicly visible (visitor IPs are submitted to an overseas service; assess cross-border PIPL compliance yourself).', 'wink-wp-gatekeeper' ); ?>
						</p>
						<p class="description">
							<?php esc_html_e( 'Placeholders supported: {event_no} (block event number), {category} (the AbuseIPDB category code for this report), {gate} (trigger surface T1/T2/T3/T4). Usage example:', 'wink-wp-gatekeeper' ); ?>
						</p>
						<!-- 【M4UX F3-4】示例块独立样式行（术语 code 与示例排版分离——消扁平混杂）。 -->
						<code class="wk-example-line">Forged captcha ticket #{event_no} (category {category}, gate {gate})</code>
						<p class="description">
							<?php esc_html_e( 'The official full category table for the dropdown is at ', 'wink-wp-gatekeeper' ); ?>
							<a href="https://www.abuseipdb.com/categories" target="_blank" rel="noopener noreferrer">abuseipdb.com/categories</a>
							<?php esc_html_e( '(AbuseIPDB official categories 1–23; the same table is built into the dropdown).', 'wink-wp-gatekeeper' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Rate-limit parameters (read-only)', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<p class="description">
							<?php
							printf(
								/* translators: 1: 冷却分钟 2: 每小时次数 3: 每日上限 */
								esc_html__( 'Per-IP cooldown %1$d minutes · global rate limit %2$d/hour · daily ceiling %3$d/day (a separate reporting pool that does not eat lookup quota).', 'wink-wp-gatekeeper' ),
								(int) ( $report['cooldown_minutes'] ?? 60 ),
								(int) ( $report['hourly_limit'] ?? 60 ),
								(int) ( $report['daily_cap'] ?? 500 )
							);
							?>
							<?php esc_html_e( 'Repeated triggers inside the cooldown window are not re-reported; more than 20 triggers within 10 minutes raises a notice on the overview page (someone may be flooding with forged tickets).', 'wink-wp-gatekeeper' ); ?>
						</p>
					</td>
				</tr>
				</table>
					<?php
				}
			);
			?>
		</section>
		<?php
	}

	/**
	 * 分区④ 说明卡（定稿 §6【O5 回写·P2-4】：三态语义人话各一行+与信用免验联动说明+
	 * 软顶人话一句——非故障预期管理）。
	 *
	 * @return void
	 */
	private static function render_iprep_zone_notes(): void {
		?>
		<section class="wk-card" id="wk-iprep-notes">
			<h2 class="wk-sec-title"><?php esc_html_e( 'Three possible verdicts (plain words)', 'wink-wp-gatekeeper' ); ?></h2>
			<ul class="description">
				<li><?php esc_html_e( 'Skip: IP reputation is disabled, or the lookup happens to be unavailable right now (backoff / paused / private network) — no say in the verdict, verification proceeds as usual, and “old friend” waiver eligibility is unaffected.', 'wink-wp-gatekeeper' ); ?></li>
				<li><?php esc_html_e( 'Pass: good reputation — through as usual, invisible to the visitor.', 'wink-wp-gatekeeper' ); ?></li>
				<li><?php esc_html_e( 'Fail: malicious / suspected IP, or misconfiguration, timeout, error, over-threshold — always strict: the visitor must pass human verification first.', 'wink-wp-gatekeeper' ); ?></li>
			</ul>
			<p class="description"><?php esc_html_e( 'Tie-in with the trusted-visitor waiver: when IP reputation says “fail”, even an “old friend” (waiver channel) is asked for one captcha — a fresh verdict outranks the trust voucher, and passage resumes as usual afterwards.', 'wink-wp-gatekeeper' ); ?></p>
			<p class="description"><?php esc_html_e( 'After the day\'s lookup quota is exhausted, the captcha is temporarily enforced for all visitors — it auto-resumes the next day; not a malfunction.', 'wink-wp-gatekeeper' ); ?></p>
		</section>
		<?php
	}

	/**
	 * 「IP 检测」诊断面板（Tab④ 顶卡，M3-IP 定稿 §5.1——纯函数行矩阵 + 本 WP 边界渲染）。
	 *
	 * 本地解析区：REMOTE_ADDR（标受信命中）/CF-Connecting-IP/X-Real-IP/X-Forwarded-For
	 * （逗号链逐项标 受信/公网/私网）+ 当前生效行五态 ip_src + 配置一致性红标 +
	 * 受信网段行（预设名+手填 CIDR+快照校准日期，>180 天黄条；EdgeOne 空预设不入行——定稿 §5.3）。
	 * 手动聚合查询区：站长输 IP → 四源卡片（出境说明常驻；结果纯请求态渲染不落库）。
	 *
	 * @param array<string, mixed> $settings 设置树（merge_defaults 后全树）。
	 * @return void
	 */
	private static function render_ip_diagnostic_panel( array $settings ): void {
		$panel = IpDiagnostic::analyze( // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读 $_SERVER 展示，无表单消费。
			$_SERVER,
			$settings['network'] ?? array(),
			time(),
			AbuseIpdbService::whitelist_lines_from( $settings['iprep']['whitelist'] ?? '' ) // 【I5】豁免徽标判定源。
		);

		$scope_labels = array(
			'trusted' => __( 'Trusted', 'wink-wp-gatekeeper' ),
			'public'  => __( 'Public', 'wink-wp-gatekeeper' ),
			'private' => __( 'Private', 'wink-wp-gatekeeper' ),
			'invalid' => __( 'Invalid', 'wink-wp-gatekeeper' ),
		);
		$src_labels   = array(
			IpResolver::SRC_DIRECT            => __( 'Direct', 'wink-wp-gatekeeper' ),
			IpResolver::SRC_XFF               => __( 'XFF chain', 'wink-wp-gatekeeper' ),
			IpResolver::SRC_HEADER            => __( 'Custom header', 'wink-wp-gatekeeper' ),
			IpResolver::SRC_UNRESOLVED        => __( 'Unresolved (fallback)', 'wink-wp-gatekeeper' ),
			IpResolver::SRC_IGNORED_UNTRUSTED => __( 'Source untrusted · forwarding headers ignored', 'wink-wp-gatekeeper' ),
		);
		$mode_labels  = array(
			IpResolver::MODE_DIRECT        => __( 'direct', 'wink-wp-gatekeeper' ),
			IpResolver::MODE_XFF           => __( 'XFF trust chain (rightmost only)', 'wink-wp-gatekeeper' ),
			IpResolver::MODE_CUSTOM_HEADER => __( 'custom_header', 'wink-wp-gatekeeper' ),
			IpResolver::MODE_TRAVERSE      => __( 'traverse chain walk', 'wink-wp-gatekeeper' ),
		);
		?>
		<section class="wk-card" id="wk-iprep-diag">
			<h2><?php esc_html_e( 'IP detection diagnostics panel', 'wink-wp-gatekeeper' ); ?></h2>
			<p class="description wk-muted"><?php esc_html_e( 'This panel shows the parsing of the current page request as it happened; the manual query below sends the entered IP to four providers (AbuseIPDB / StopForumSpam / ip.nc.gy / ip.sb) — results are shown for this request only, never stored, cached, or counted.', 'wink-wp-gatekeeper' ); ?></p>

			<h3 class="wk-sub-title"><?php esc_html_e( 'Local parsing', 'wink-wp-gatekeeper' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'REMOTE_ADDR', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<code><?php echo '' !== $panel['remote']['raw'] ? esc_html( $panel['remote']['raw'] ) : esc_html__( '(missing)', 'wink-wp-gatekeeper' ); ?></code>
						<span class="wk-muted">
							<?php
							printf(
								/* translators: 1: 作用域标注 2: 受信命中说明 */
								esc_html__( '%1$s、%2$s', 'wink-wp-gatekeeper' ),
								esc_html( $scope_labels[ $panel['remote']['scope'] ] ?? (string) $panel['remote']['scope'] ),
								esc_html( $panel['remote']['trusted'] ? __( 'In a trusted range', 'wink-wp-gatekeeper' ) : __( 'Not in a trusted range', 'wink-wp-gatekeeper' ) )
							);
							?>
						</span>
					</td>
				</tr>
				<?php foreach ( $panel['headers'] as $header_row ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $header_row['name'] ); ?></th>
						<td>
							<?php if ( null === $header_row['raw'] ) : ?>
								<span class="wk-muted"><?php esc_html_e( '(absent in this request)', 'wink-wp-gatekeeper' ); ?></span>
							<?php else : ?>
								<code><?php echo esc_html( (string) $header_row['raw'] ); ?></code>
								<?php $items = array(); ?>
								<?php foreach ( $header_row['items'] as $item ) : ?>
									<?php
									$items[] = sprintf(
										'%s（%s）',
										(null !== $item['ip']) ? $item['ip'] : $item['raw'],
										$scope_labels[ $item['scope'] ] ?? (string) $item['scope']
									);
									?>
								<?php endforeach; ?>
								<?php if ( array() !== $items ) : ?>
									<span class="wk-muted"><?php echo esc_html( implode( ' · ', $items ) ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Currently in effect', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<strong><code><?php echo esc_html( '' !== $panel['effective']['ip'] ? $panel['effective']['ip'] : '—' ); ?></code></strong>
						<?php if ( ! empty( $panel['effective']['whitelisted'] ) ) : ?>
							<!-- 【I5】站长白名单命中徽标（AbuseIpdbService 步 0 同一判定函数）。 -->
							<span class="wk-muted">· <?php esc_html_e( 'Allowlist-exempt', 'wink-wp-gatekeeper' ); ?></span>
						<?php endif; ?>
						<span class="wk-muted">
							<?php
							printf(
								/* translators: 1: 五态 ip_src 2: 获取模式 */
								esc_html__( 'ip_src=%1$s · mode=%2$s', 'wink-wp-gatekeeper' ),
								esc_html( ( $src_labels[ $panel['effective']['ip_src'] ] ?? (string) $panel['effective']['ip_src'] ) . '（' . $panel['effective']['ip_src'] . '）' ),
								esc_html( $mode_labels[ $panel['mode'] ] ?? (string) $panel['mode'] )
							);
							?>
						</span>
					</td>
				</tr>
			</table>

			<?php if ( $panel['flag'] ) : ?>
				<p class="description">
					<span class="wk-dot wk-dot--alert" aria-hidden="true"></span>
					<?php
					printf(
						/* translators: %s: 配置的自定义头名 */
						esc_html__( 'Configuration consistency: custom header “%s” is configured but absent in this request — please check your topology (same warning as Wordfence\'s “Configured but not valid”).', 'wink-wp-gatekeeper' ),
						esc_html( $panel['custom_header'] )
					);
					?>
				</p>
			<?php endif; ?>

			<p class="description">
				<span class="wk-muted">
					<?php
					$trusted_bits = array();
					foreach ( $panel['trusted']['rows'] as $row ) {
						$trusted_bits[] = sprintf( /* translators: 1: 网段名 2: 条数 */ _n( '%1$s (%2$d range)', '%1$s (%2$d ranges)', (int) $row['count'], 'wink-wp-gatekeeper' ), $row['label'], (int) $row['count'] );
					}
					if ( null !== $panel['trusted']['verified'] ) {
						$trusted_bits[] = sprintf(
							/* translators: %s: 快照核对日期 */
							__( 'Preset snapshot verified on: %s', 'wink-wp-gatekeeper' ),
							(string) $panel['trusted']['verified']
						);
					}
					echo array() !== $trusted_bits
						? esc_html( sprintf(
							/* translators: %s: 受信网段列表 */
							__( 'Trusted ranges: %s', 'wink-wp-gatekeeper' ),
							implode( ' · ', $trusted_bits )
						) )
						: esc_html__( 'Trusted ranges: (no preset or custom ranges enabled — forwarded headers take no part in parsing)', 'wink-wp-gatekeeper' );
					?>
				</span>
			</p>
			<?php if ( $panel['trusted']['stale'] ) : ?>
				<p class="wk-notice-inline-warn description">
					<?php
					printf(
						/* translators: 1: 快照日期 2: 距今天数 */
						esc_html__( 'The trusted-range snapshot is over 180 days old (last verified %1$s, %2$d days ago) — ranges may be stale; recalibrate (dev tool tools/api-smoke/refresh-presets.php pulls the latest official ranges for a manual diff-and-edit).', 'wink-wp-gatekeeper' ),
						esc_html( (string) $panel['trusted']['verified'] ),
						(int) ( $panel['trusted']['age_days'] ?? 0 )
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( array() !== $panel['trusted']['invalid'] ) : ?>
				<p class="description">
					<span class="wk-dot wk-dot--alert" aria-hidden="true"></span>
					<?php
					printf(
						/* translators: %s: 非法行列表 */
						esc_html__( 'Custom range invalid lines (skipped): %s', 'wink-wp-gatekeeper' ),
						esc_html( implode( '、', $panel['trusted']['invalid'] ) )
					);
					?>
				</p>
			<?php endif; ?>

			<h3 class="wk-sub-title"><?php esc_html_e( 'Manual aggregate query', 'wink-wp-gatekeeper' ); ?></h3>
			<p>
				<label class="screen-reader-text" for="wk-ip-diag-ip"><?php esc_html_e( 'IP address to look up', 'wink-wp-gatekeeper' ); ?></label>
				<input type="text" id="wk-ip-diag-ip" class="regular-text" placeholder="<?php esc_attr_e( '8.8.8.8 or 2001:db8::1', 'wink-wp-gatekeeper' ); ?>" autocomplete="off" />
				<button type="button" class="button" id="wk-ip-diag-query" data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX_IP_DIAGNOSE ) ); ?>"><?php esc_html_e( 'Sources to query', 'wink-wp-gatekeeper' ); ?></button>
				<span id="wk-ip-diag-status" class="wk-muted" aria-live="polite"></span>
			</p>
			<div id="wk-ip-diag-result" class="wk-ip-diag-grid"></div>
			<p class="description wk-muted">
				<?php esc_html_e( 'Threat group: AbuseIPDB (score / usage / ISP / Tor) · StopForumSpam (blacklist hits / frequency) · ip.nc.gy (proxy / VPN / Tor / hosting / CDN / anonymity — six flags); geo group: ip.sb (location / ISP / ASN). 3-second timeout per source; a failing source fails only its own card while the rest still report.', 'wink-wp-gatekeeper' ); ?>
			</p>
		</section>
		<?php
	}

	/**
	 * Tab④ 诊断面板「手动聚合查询」AJAX（M3-IP 定稿 §5.1，I0 批）。
	 *
	 * nonce + manage_options 双闸（03 §1 契约④）；四源 URL 常量集中一处
	 * （IpDiagnostic::REMOTE_SOURCES，§12-2——https 硬约束由 RemoteClient::check_url 再验一道）；
	 * RemoteClient standard timeout 3s；**零落库零缓存零统计**（无 transient/option 写、无 usage_key）；
	 * 任一源失败仅该卡片失败（error 卡片人话由 IpDiagnostic::card_error）。
	 *
	 * @return void
	 */
	public static function ajax_ip_diagnose(): void {
		check_ajax_referer( self::AJAX_IP_DIAGNOSE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions: manage_options required.', 'wink-wp-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post   = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		$ip_raw = isset( $post['ip'] ) && is_string( $post['ip'] ) ? trim( $post['ip'] ) : '';
		$ip     = IpResolver::normalize( $ip_raw );
		if ( null === $ip ) {
			wp_send_json_error( array( 'message' => __( 'Invalid IP format.', 'wink-wp-gatekeeper' ) ), 400 );
		}

		$stored  = get_option( self::OPTION_NAME, array() );
		$stored  = SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() );
		$api_key = isset( $stored['iprep']['abuseipdb']['api_key'] ) && is_string( $stored['iprep']['abuseipdb']['api_key'] )
			? $stored['iprep']['abuseipdb']['api_key'] : '';

		$urls   = IpDiagnostic::source_urls( $ip );
		$client = new RemoteClient(); // WP 边界默认传输；诊断查询零计量（不传计量键）。

		$cards = array();
		foreach ( $urls as $id => $url ) {
			$args = array(
				'timeout'           => 3,
			);
			if ( 'abuseipdb' === $id ) {
				if ( '' === $api_key ) {
					$cards[ $id ] = array( 'ok' => false, 'rows' => array(), 'error' => __( 'No API Key configured (Tab 4, section 2)', 'wink-wp-gatekeeper' ) );
					continue;
				}
				$args['headers'] = array(
					'Key'    => $api_key,
					'Accept' => 'application/json',
				);
			}
			$result = $client->get( $url, $args );
			if ( $result->is_ok() && is_array( $result->data() ) ) {
				$cards[ $id ] = IpDiagnostic::parse_source( $id, $result->data() );
			} else {
				$cards[ $id ] = array(
					'ok'    => false,
					'rows'  => array(),
					'error' => IpDiagnostic::card_error( $result->category(), $result->status() ),
				);
			}
		}

		wp_send_json_success( array( 'ip' => $ip, 'cards' => $cards ) );
	}

	/**
	 * Tab④ 分区②「测试凭据」AJAX 端点（M3-IP 定稿 §6【O5 回写·P2-6】，I4 收官批；
	 * 03 §1 契约④：nonce + manage_options 双闸；结果记录最近一次测试状态与时间——probe log，N1；message 不入库）。
	 *
	 * 表单值（password 空串 = 回落已存值——与保存契约同语义，Tab② 先例）→ 本地预检
	 * （API Key 空=黄态不发远端）→ AbuseIpdbService::probe_credentials 最小 check 查询
	 * （127.0.0.2 保留地址+maxAgeInDays=1 省配额；零计量零写零事件）→
	 * CredentialProbe::classify_abuseipdb 三态（绿=HTTP 200 且 JSON 可解析，isPublic:false
	 * 亦绿——验证的是凭据不是 IP；红=401/402 错钥；灰=429/传输终态/契约不符）。
	 *
	 * @return void
	 */
	public static function ajax_test_iprep(): void {
		check_ajax_referer( self::AJAX_TEST_IPREP, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions: manage_options required.', 'wink-wp-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$stored = get_option( self::OPTION_NAME, array() );
		$stored = SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() );
		$stored_key = isset( $stored['iprep']['abuseipdb']['api_key'] ) && is_string( $stored['iprep']['abuseipdb']['api_key'] )
			? $stored['iprep']['abuseipdb']['api_key'] : '';

		// 表单值覆盖（password 空串保留已存值——Tab② probe_field 同语义）。
		$api_key = self::probe_field( $post, 'api_key', $stored_key );

		$precheck = CredentialProbe::local_precheck_abuseipdb( $api_key );
		if ( null !== $precheck ) {
			self::probe_log_record( 'abuseipdb', (string) ( $precheck['state'] ?? '' ) ); // W3-C N1 黄态（Key 空）同落最近一次测试态。
			wp_send_json_success( $precheck );
		}

		$settings                     = $stored;
		$settings['iprep']['abuseipdb']['api_key'] = $api_key;

		$service = new AbuseIpdbService(
			$settings,
			new RemoteClient() // WP 边界：默认传输（探测一次——probe_credentials 零计量零写零事件）。
		);
		$state = CredentialProbe::classify_abuseipdb( $service->probe_credentials() );
		self::probe_log_record( 'abuseipdb', (string) ( $state['state'] ?? '' ) ); // W3-C N1 分类完成后落库（message 不入库——state 枚举+时间戳两键）。
		wp_send_json_success( $state );
	}

	/* -------------------------------------------------------------------
	 * Tab⑤ 拦截页预览（【M4UX·G3】「查看效果」——新标签页实渲染 DiePage 样例）
	 * ------------------------------------------------------------------ */

	/**
	 * 拦截页预览载荷（纯函数，单测覆盖；【M4UX·G3】）。
	 *
	 * 用当前设置树的 copy 子树（场景默认文案打底 + 站长自定义 die_page_title/die_page_message
	 * 覆盖——DiePage::resolve_page_text 同一归一口径）按占位符示例值填充：
	 * {event_no}=Copywork::PREVIEW_EVENT_NO（WK-00000000）、{rule}=Copywork::PREVIEW_RULE_SAMPLE
	 * （示例规则）、{time}=预览时刻（调用方传站点时区当前时间）、{contact_email}=brand 子树值。
	 * 证据行同 CommentGate::die_payload 三键形态（事件编号/命中规则/时间）——预览即实渲染形态。
	 *
	 * @param array<string, mixed> $settings 设置树（brand/copy 子树）。
	 * @param string               $time     预览时刻字符串（站点时区）。
	 * @return array{title: string, message: string, evidence: array<string, string>}
	 */
	public static function die_preview_payload( array $settings, string $time ): array {
		$copy    = is_array( $settings['copy'] ?? null ) ? $settings['copy'] : array();
		$contact = isset( $settings['brand']['contact_email'] ) && is_string( $settings['brand']['contact_email'] )
			? $settings['brand']['contact_email'] : '';

		$vars = array(
			'event_no' => Copywork::PREVIEW_EVENT_NO,
			'rule'     => __( 'Sample rule', 'wink-wp-gatekeeper' ),
			'time'     => $time,
		);
		if ( '' !== $contact ) {
			$vars['contact_email'] = $contact;
		}

		$page = \WinkGatekeeper\Intercept\DiePage::resolve_page_text( 'banned_word', $copy, $vars );

		return array(
			'title'    => $page['title'],
			'message'  => $page['message'],
			'evidence' => array(
				__( 'Event No.', 'wink-wp-gatekeeper' ) => Copywork::PREVIEW_EVENT_NO,
				__( 'Matched rule', 'wink-wp-gatekeeper' ) => __( 'Sample rule', 'wink-wp-gatekeeper' ),
				__( 'Time', 'wink-wp-gatekeeper' )     => $time,
			),
		);
	}

	/**
	 * Tab⑤「查看效果」预览处理器（admin_action_wink_gatekeeper_die_preview；【M4UX·G3】）。
	 *
	 * manage_options + nonce 双闸（03 §1 契约④）→ die_preview_payload 纯函数载荷
	 * → DiePage::build 完整品牌页 echo → exit。**零落库零事件零统计**（只读 option，
	 * 不写任何状态——预览即实渲染形态，与真拦截页同一构造器）。
	 *
	 * @return void
	 */
	public static function handle_die_preview(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wink-wp-gatekeeper' ), 403 );
		}
		check_admin_referer( self::ACTION_DIE_PREVIEW );

		$settings = self::settings();
		$payload  = self::die_preview_payload( $settings, date_i18n( 'Y-m-d H:i:s' ) );
		$contact  = isset( $settings['brand']['contact_email'] ) && is_string( $settings['brand']['contact_email'] )
			? $settings['brand']['contact_email'] : '';

		// phpcs:ignore WordPress.Security.EscapeOutput -- DiePage::build 内部逐值 htmlspecialchars。
		echo \WinkGatekeeper\Intercept\DiePage::build(
			$payload['title'],
			$payload['message'],
			$payload['evidence'],
			$contact,
			WINK_GATEKEEPER_URL . 'assets/brand/logo-wink.svg',
			get_locale()
		);
		exit;
	}

	/* -------------------------------------------------------------------
	 * Tab⑦ 存量审核操作面（【M5·U3】admin_post 四件；D6 分工——Tab⑦ 发起/Tab⑥ 报告）
	 * ------------------------------------------------------------------ */

	/**
	 * Tab③ DeepSeek「测试凭据」AJAX 处理器（【M6·批B·N4】——余额端点三态探测）。
	 *
	 * 【M6·批B·N4 契约翻转】models 探活（M5·U4 D5 裁决）退役→GET /user/balance 余额
	 * 探测（零费用端点，信息量升级：Key 有效性+账户可用性+可见余额一次拿全；绿态
	 * 人话行「余额 110.00 CNY（含赠金 10.00）」）。probe_models 保留给 openai_custom
	 * 泛兼容端点（无标准余额 API——UI 注明差异）。
	 *
	 * 绿=200 且 is_available=true（附 balance 对象 currency/total_balance/granted_balance）；
	 * 黄=200 且 is_available=false（欠费）；红=401/402/403/transport；灰=契约异常；
	 * 另黄=API Key 未填（本地预检）。表单值覆盖（password 空串保留已存值语义）；
	 * 响应闭集不含提交值（凭据零回显）；不计 usage 不落库。
	 *
	 * @return void
	 */
	public static function ajax_test_credentials_deepseek(): void {
		check_ajax_referer( self::AJAX_TEST_CREDENTIALS_DEEPSEEK, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions: manage_options required.', 'wink-wp-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$stored  = get_option( self::OPTION_NAME, array() );
		$stored  = SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() );
		$current = isset( $stored['review']['cloud']['deepseek'] ) && is_array( $stored['review']['cloud']['deepseek'] )
			? $stored['review']['cloud']['deepseek'] : array();

		$api_key  = self::probe_field( $post, 'deepseek_api_key', (string) ( $current['api_key'] ?? '' ) );
		$base_url = isset( $post['deepseek_base_url'] ) && is_scalar( $post['deepseek_base_url'] )
			? trim( (string) $post['deepseek_base_url'] ) : (string) ( $current['base_url'] ?? 'https://api.deepseek.com' );

		$precheck = CredentialProbe::local_precheck_deepseek( $api_key );
		if ( null !== $precheck ) {
			self::probe_log_record( 'deepseek', (string) ( $precheck['state'] ?? '' ) ); // W3-C N1 黄态（Key 空）同落最近一次测试态。
			wp_send_json_success( $precheck );
		}

		$config = array(
			'api_key'  => $api_key,
			'base_url' => '' !== $base_url ? $base_url : 'https://api.deepseek.com',
		);
		$driver = new \WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver( $config, new RemoteClient() ); // 探测一次不计 usage。
		$state  = CredentialProbe::classify_deepseek_balance( $driver->probe_balance() );
		self::probe_log_record( 'deepseek', (string) ( $state['state'] ?? '' ) ); // W3-C N1 分类完成后落库（message 不入库）。
		wp_send_json_success( $state );
	}

	/**
	 * Tab③ 网址核查独立凭据「测试凭据」AJAX 处理器（【M6·批B·B-3】N4 范围边界；
	 * 【M6·批E·E-4】按 base_url 分流）。
	 *
	 * provider custom 独立凭证：base_url host 为 DeepSeek 官方域（或空回落官方）→
	 * 维持 /user/balance 余额探测（信息量优先）；非官方 base_url（中转站/自建网关）→
	 * 切 openai_custom /models 探活（泛端点无标准余额 API——此前硬走余额探测必契约
	 * 不符灰态）。分流点收敛 UrlAuditWorker::provider_probe_is_deepseek 一处；表单覆盖
	 * （password 空串保留已存值语义）；响应闭集不含提交值；不计 usage 不落库。
	 *
	 * @return void
	 */
	public static function ajax_test_credentials_url_audit(): void {
		check_ajax_referer( self::AJAX_TEST_CREDENTIALS_URL_AUDIT, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions: manage_options required.', 'wink-wp-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$stored   = get_option( self::OPTION_NAME, array() );
		$stored   = SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() );
		$provider = isset( $stored['url_audit']['provider'] ) && is_array( $stored['url_audit']['provider'] )
			? $stored['url_audit']['provider'] : array();

		$api_key  = self::probe_field( $post, 'url_audit_provider_api_key', (string) ( $provider['api_key'] ?? '' ) );
		$base_url = isset( $post['url_audit_provider_base_url'] ) && is_scalar( $post['url_audit_provider_base_url'] )
			? trim( (string) $post['url_audit_provider_base_url'] ) : (string) ( $provider['base_url'] ?? '' );

		$precheck = CredentialProbe::local_precheck_deepseek( $api_key );
		if ( null !== $precheck ) {
			self::probe_log_record( 'url_audit_provider', (string) ( $precheck['state'] ?? '' ) ); // W3-C N1 黄态（Key 空）同落最近一次测试态。
			wp_send_json_success( $precheck );
		}

		if ( \WinkGatekeeper\UrlAudit\UrlAuditWorker::provider_probe_is_deepseek( $base_url ) ) {
			// 官方域（或空回落官方）：DeepSeek 余额探测（零费用——信息量优先）。
			$config = array(
				'api_key'  => $api_key,
				'base_url' => '' !== $base_url ? $base_url : \WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver::DEFAULT_BASE_URL,
			);
			$driver = new \WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver( $config, new RemoteClient() ); // 探测一次不计 usage。
			$state  = CredentialProbe::classify_deepseek_balance( $driver->probe_balance() );
			self::probe_log_record( 'url_audit_provider', (string) ( $state['state'] ?? '' ) ); // W3-C N1 分类完成后落库（message 不入库）。
			wp_send_json_success( $state );
		}

		// 非官方域：openai_custom /models 探活（模型列表三态——泛端点无标准余额 API）。
		$driver = new \WinkGatekeeper\Drivers\Review\OpenAiCustomReviewDriver(
			array( 'api_key' => $api_key, 'base_url' => $base_url ),
			new RemoteClient() // 探测一次不计 usage。
		);
		$state = CredentialProbe::classify_openai_custom_models( $driver->probe_models() );
		self::probe_log_record( 'url_audit_provider', (string) ( $state['state'] ?? '' ) ); // W3-C N1 分类完成后落库（message 不入库）。
		wp_send_json_success( $state );
	}

	/**
	 * Tab③ openai_custom「测试凭据」AJAX 处理器（【M6·批E】——/models 三态探活）。
	 *
	 * GET {base_url}/models（Bearer，零 token 消耗——泛端点无标准余额 API）。表单值
	 * 覆盖（password 空串保留已存值语义）；本地预检三键缺=黄态零外呼；绿态含手填
	 * 模型则顺带校验在不在列表；响应闭集 {state,message,missing,code} 不含提交值
	 * （凭据零回显）；不计 usage 不落库。
	 *
	 * @return void
	 */
	public static function ajax_test_credentials_openai_custom(): void {
		check_ajax_referer( self::AJAX_TEST_CREDENTIALS_OPENAI_CUSTOM, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions: manage_options required.', 'wink-wp-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$stored  = get_option( self::OPTION_NAME, array() );
		$stored  = SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() );
		$current = isset( $stored['review']['cloud']['openai_custom'] ) && is_array( $stored['review']['cloud']['openai_custom'] )
			? $stored['review']['cloud']['openai_custom'] : array();

		$sub = array(
			'api_key'  => self::probe_field( $post, 'openai_custom_api_key', (string) ( $current['api_key'] ?? '' ) ),
			'base_url' => isset( $post['openai_custom_base_url'] ) && is_scalar( $post['openai_custom_base_url'] )
				? trim( (string) $post['openai_custom_base_url'] ) : (string) ( $current['base_url'] ?? '' ),
			'model'    => isset( $post['openai_custom_model'] ) && is_scalar( $post['openai_custom_model'] )
				? trim( (string) $post['openai_custom_model'] ) : (string) ( $current['model'] ?? '' ),
		);

		$precheck = CredentialProbe::local_precheck_openai_custom( $sub );
		if ( null !== $precheck ) {
			self::probe_log_record( 'openai_custom', (string) ( $precheck['state'] ?? '' ) ); // W3-C N1 黄态（必填缺）同落最近一次测试态。
			wp_send_json_success( $precheck );
		}

		$driver = new \WinkGatekeeper\Drivers\Review\OpenAiCustomReviewDriver(
			array( 'api_key' => $sub['api_key'], 'base_url' => $sub['base_url'] ),
			new RemoteClient() // 探测一次不计 usage。
		);
		$state = CredentialProbe::classify_openai_custom_models( $driver->probe_models(), $sub['model'] );
		self::probe_log_record( 'openai_custom', (string) ( $state['state'] ?? '' ) ); // W3-C N1 分类完成后落库（message 不入库）。
		wp_send_json_success( $state );
	}

	/**
	 * 存量审核 Worker（生产装配——全默认 WP 通道；设置树 merge 单源同 CommentGate）。
	 *
	 * @return \WinkGatekeeper\Audit\ExistingAuditWorker
	 */
	private static function audit_worker(): \WinkGatekeeper\Audit\ExistingAuditWorker {
		$stored = get_option( self::OPTION_NAME, array() );
		return new \WinkGatekeeper\Audit\ExistingAuditWorker(
			SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() )
		);
	}

	/**
	 * 存量审核四操作统一处理器（admin_post_wink_gatekeeper_audit_{start|pause|resume|reset}）：
	 * manage_options + check_admin_referer 双闸；start/resume 重排 cron tick、pause/reset
	 * 清 tick（CLI run 通道自跑，cron 是站长侧补充面）；完成后重定向回 Tab⑦。
	 *
	 * @return void
	 */
	public static function handle_audit_action(): void {
		$action = current_action();
		$op     = is_string( $action ) ? str_replace( 'admin_post_', '', $action ) : '';
		$op     = str_replace( 'wink_gatekeeper_audit_', '', $op );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wink-wp-gatekeeper' ), 403 );
		}
		check_admin_referer( 'wink_gatekeeper_audit_' . $op );

		$worker = self::audit_worker();
		try {
			switch ( $op ) {
				case 'start':
					try {
						// 【M6·批C·C-1】宇宙快照化：发起时固化 ID 清单（F1「23/22」修复——
						// worker 只处理清单内 ID，发起后新增不扫入）。
						$universe = \WinkGatekeeper\Audit\ExistingAuditWorker::unaudited_universe_ids();
						$worker->start( count( $universe ), $universe );
					} catch ( \RuntimeException $e ) {
						// 【M5 修复批·C3】start 专属显式错误回跳（wk-audit-error=1）——
						// 无已配置云审驱动时区别于下方竞态静默回跳：任务未落盘（C1 守卫
						// 抛出前置），Tab⑦ 状态行仍 idle，站长需看到「为什么没发起」。
						wp_safe_redirect( add_query_arg( array( 'wk-audit-error' => '1' ), self::tab_url( 'tools' ) ) );
						exit;
					}
					self::audit_schedule_tick();
					break;
				case 'pause':
					$worker->pause_task();
					self::audit_unschedule_tick();
					break;
				case 'resume':
					$worker->resume_task();
					self::audit_schedule_tick();
					break;
				case 'reset':
					$worker->reset_task( \WinkGatekeeper\Audit\ExistingAuditWorker::universe_ids() );
					self::audit_unschedule_tick();
					break;
			}
		} catch ( \RuntimeException $e ) {
			// 已在运行等竞态：静默回 Tab⑦（状态行会显示现态）。
			// 【A1-F13】观测决策声明：本分支=预期竞态（管理员双击/并发操作），非异常态
			// ——有意无观测（落事件/日志=预期双击噪音；现态已由 Tab⑦ 状态行呈现）。
		}

		wp_safe_redirect( self::tab_url( 'tools' ) );
		exit;
	}

	/**
	 * Tab③「网址核查」配置区（【M5·U5】UI 设计块 2-B——增强层：关闭=零行为变化）。
	 *
	 * 三档 profile（radio+dl 逐档人话）；「0.95 自动定罪门」系统掌握不露旋钮（D8——
	 * 只放大严）；高级折叠（【M6·批B·B-1】博查「核查前先搜一搜」整行迁入+搜索源
	 * 下拉【B-2】/双车道日额度/robots 开关/独立凭据测试按钮【B-3】/清缓存按钮）；
	 * 未配置 notice 条件显隐（【B-4】enabled 且 AI 判定凭证链为空）；inert notice
	 * 条件显隐（site_field 收集关闭=增量面失效）。
	 *
	 * @param array<string, mixed> $settings 设置树。
	 * @return void
	 */
	private static function render_url_audit_card( array $settings ): void {
		$ua       = isset( $settings['url_audit'] ) && is_array( $settings['url_audit'] ) ? $settings['url_audit'] : array();
		$enabled  = ! empty( $ua['enabled'] );
		$profile  = (string) ( $ua['profile'] ?? 'conservative' );
		$site_off = empty( $settings['comments']['site_field']['enabled'] ); // 收集关闭=增量面无对象.

		/* 【M6·批B·B-4】AI 判定凭证链空判定（UrlAuditWorker::provider_config 同源语义）：
		 * custom 且独立 key 非空=独立凭证；否则回落云审 DeepSeek key——两者皆空=链空。 */
		$ua_provider      = is_array( $ua['provider'] ?? null ) ? $ua['provider'] : array();
		$ua_provider_mode = (string) ( $ua_provider['mode'] ?? 'inherit' );
		$ua_own_key       = trim( (string) ( $ua_provider['api_key'] ?? '' ) );
		$cloud_ds_key     = (string) ( $settings['review']['cloud']['deepseek']['api_key'] ?? '' );
		$chain_empty      = ( ! ( 'custom' === $ua_provider_mode && '' !== $ua_own_key ) ) && '' === trim( $cloud_ds_key );
		?>
		<section class="wk-card" id="wk-review-url-audit-card">
			<h2><?php esc_html_e( 'URL check (enhancement layer — with it off, comment protection is unchanged, zero behavior difference)', 'wink-wp-gatekeeper' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable the URL check', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'enabled' ) ) ); ?>" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'enabled' ) ) ); ?>" value="1" <?php checked( $enabled ); ?>>
							<?php esc_html_e( 'The website a commenter fills in is first opened for a look, then the AI judges whether the site is problematic (gambling / scams / phishing, etc.)', 'wink-wp-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Only tightens: a clean URL never approves a comment by itself.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><span id="wk-url-audit-profile-group"><?php esc_html_e( 'Handling level', 'wink-wp-gatekeeper' ); ?></span></th>
					<td>
						<dl>
							<dt><label><input type="radio" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'profile' ) ) ); ?>" value="conservative" <?php checked( $profile, 'conservative' ); ?>> <?php esc_html_e( 'Conservative (default) — violations are not auto-spammed, only reported and flagged; unclear URLs go to pending review for your call', 'wink-wp-gatekeeper' ); ?></label></dt>
							<dt><label><input type="radio" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'profile' ) ) ); ?>" value="balanced" <?php checked( $profile, 'balanced' ); ?>> <?php esc_html_e( 'Balanced (recommended) — clear-cut violations are auto-spammed (one-click undo), the rest go to pending review', 'wink-wp-gatekeeper' ); ?></label></dt>
							<dt><label><input type="radio" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'profile' ) ) ); ?>" value="aggressive" <?php checked( $profile, 'aggressive' ); ?>> <?php esc_html_e( 'Aggressive — even not-so-sure cases are auto-spammed, unclear ones go to pending review; for emergencies during spam floods, switch back afterwards', 'wink-wp-gatekeeper' ); ?></label></dt>
						</dl>
						<p class="description"><?php esc_html_e( 'The “clear-cut evidence” bar is system-controlled with no UI knob — it only tightens.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
			</table>

			<?php
			// 【W3·批A】迁段 advanced_fold（折叠壳=既有 details.wk-guide 形态原样保留——
			// 等价迁移；折叠内标记/字段 name 留闭包=编排函数体内，source-scan 锚不迁位；
			// id= 起标记字面量留本位——M6B 系 blockBetween 契约）。
			self::advanced_fold(
				'id="wk-url-audit-advanced"',
				__( 'Advanced (collapsed by default)', 'wink-wp-gatekeeper' ),
				static function () use ( $ua, $ua_provider, $ua_provider_mode ): void {
					?>
					<table class="form-table" role="presentation">
					<?php /* 【M6·批B·B-1】「核查前先搜一搜」整行自主体 form-table 迁入本折叠
					 * （N2：搜索属高级配置；字段 name 零变化=schema 零变化；bocha.enabled
					 * hidden 0 伴随域照查）。 */ ?>
					<tr id="wk-url-audit-bocha-row">
						<th scope="row"><?php esc_html_e( 'Search before checking', 'wink-wp-gatekeeper' ); ?></th>
						<td>
							<label>
								<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'bocha', 'enabled' ) ) ); ?>" value="0" />
								<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'bocha', 'enabled' ) ) ); ?>" value="1" <?php checked( ! empty( $ua['bocha']['enabled'] ) ); ?>>
								<?php esc_html_e( 'When a page will not open, search results can still serve as clues, reducing “could not tell” cases (off by default)', 'wink-wp-gatekeeper' ); ?>
							</label>
							<?php self::password_field( array( 'url_audit', 'bocha', 'api_key' ), '' !== (string) ( $ua['bocha']['api_key'] ?? '' ), __( 'Bocha API Key', 'wink-wp-gatekeeper' ) ); ?>
							<p>
								<label for="wk-url-audit-search-source"><?php esc_html_e( 'Search source', 'wink-wp-gatekeeper' ); ?></label>
								<select id="wk-url-audit-search-source" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'search_source' ) ) ); ?>">
									<option value="bocha" <?php selected( (string) ( $ua['search_source'] ?? 'bocha' ), 'bocha' ); ?>><?php esc_html_e( 'Bocha web search (default — needs the Bocha key above; per-call billing, metered separately)', 'wink-wp-gatekeeper' ); ?></option>
									<option value="deepseek" <?php selected( (string) ( $ua['search_source'] ?? 'bocha' ), 'deepseek' ); ?>><?php esc_html_e( 'DeepSeek web search (uses the LLM provider\'s credentials above — billed per success)', 'wink-wp-gatekeeper' ); ?></option>
								</select>
							</p>
							<p class="description"><?php esc_html_e( 'Bocha requires an API Key from the Bocha open platform (bocha.cn; per-call billing, metered separately); DeepSeek follows the LLM provider\'s credentials above (no extra key, billed per success).', 'wink-wp-gatekeeper' ); ?></p>
						</td>
					</tr>
				</table>
				<table class="form-table" role="presentation">
					<?php
					/* 【M5 修复批·D4】网址核查判定凭证（独立/回落云审二态）：无 checkbox
					 * 故 hidden 0 伴随域不适用；mode 显式枚举使「清空独立 key 回落」在 UI
					 * 上可逆（D1 注）。条件显隐=custom_header 联动范式（PHP 初始 hidden +
						 * admin-brand.js change 绑定；DOM 锚 wk-url-audit-provider-mode /
						 * wk-url-audit-provider-rows）。独立凭证只用于网址核查（与评论云审
						 * 配额/健康态互不影响）。【M6·批E】判定模型行改手填 text——空=标准档
						 * 回落语义保留（provider_config 回落同源；行内注释同步改写）。 */
					?>
						<tr>
							<!-- 【M6·批H·H-5】行标签改「LLM提供商」（用户 2026-11-16）；
							 * inherit 选项文案与抓取服务商统一为「跟随云端审核」语义（§0-5）。 -->
							<th scope="row"><label for="wk-url-audit-provider-mode"><?php esc_html_e( 'LLM provider', 'wink-wp-gatekeeper' ); ?></label></th>
							<td>
								<select id="wk-url-audit-provider-mode" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'provider', 'mode' ) ) ); ?>">
									<option value="inherit" <?php selected( $ua_provider_mode, 'inherit' ); ?>><?php esc_html_e( 'Follow cloud moderation (default — when cloud moderation is DeepSeek, verdicts use the same configuration)', 'wink-wp-gatekeeper' ); ?></option>
									<option value="custom" <?php selected( $ua_provider_mode, 'custom' ); ?>><?php esc_html_e( 'Separate credentials (for the URL check)', 'wink-wp-gatekeeper' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Follow (default): when cloud moderation is DeepSeek, both verdicts and page fetching use DeepSeek (one set of credentials); with another driver, verdicts use its configuration and page fetching does not auto-enable (unconfigured — pick one manually under “Fetch provider” below). Separate: the URL check uses its own DeepSeek credentials (independent of comment cloud moderation; only needed for a different account/quota).', 'wink-wp-gatekeeper' ); ?></p>
							</td>
						</tr>
				</table>
				<table class="form-table" role="presentation" id="wk-url-audit-provider-rows"<?php echo 'custom' === $ua_provider_mode ? '' : ' hidden'; ?>>
					<tr>
						<th scope="row"><span class="screen-reader-text"><?php esc_html_e( 'Separate-credentials API Key', 'wink-wp-gatekeeper' ); ?></span></th>
						<td>
							<?php self::password_field( array( 'url_audit', 'provider', 'api_key' ), '' !== (string) ( $ua_provider['api_key'] ?? '' ), __( 'URL check API Key', 'wink-wp-gatekeeper' ) ); ?>
							<p class="description"><?php esc_html_e( 'An API Key from the DeepSeek platform (may be a different account from Tab 3 cloud moderation).', 'wink-wp-gatekeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wk-url-audit-provider-base-url"><?php esc_html_e( 'Separate-credentials endpoint', 'wink-wp-gatekeeper' ); ?></label></th>
						<td>
							<input type="url" id="wk-url-audit-provider-base-url" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'provider', 'base_url' ) ) ); ?>"
								value="<?php echo esc_attr( (string) ( $ua_provider['base_url'] ?? '' ) ); ?>" class="regular-text code"
								placeholder="https://api.deepseek.com" />
							<p class="description"><?php esc_html_e( 'Leave blank to use the official address (rarely needs changing).', 'wink-wp-gatekeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wk-url-audit-provider-model"><?php esc_html_e( 'Separate-credentials verdict model', 'wink-wp-gatekeeper' ); ?></label></th>
						<td>
							<?php /* 【M6·批E·E-4】改手填 text（原 DeepSeek 静态两选项 select 退役——
								独立端点可为任意 OpenAI 兼容端点）；空=标准档回落语义保留
								（provider_config 空 model 回落 deepseek-flash 同源——UI 如实
								显示空值不虚构已存模型名）。 */ ?>
							<input type="text" id="wk-url-audit-provider-model" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'provider', 'model' ) ) ); ?>"
								value="<?php echo esc_attr( (string) ( $ua_provider['model'] ?? '' ) ); ?>" class="regular-text"
								placeholder="<?php esc_attr_e( 'Enter the model name manually (for OpenAI-compatible endpoints, one it supports)', 'wink-wp-gatekeeper' ); ?>" />
							<p class="description"><?php esc_html_e( 'The model name must match the endpoint; blank = Standard (deepseek-flash fallback — DeepSeek endpoints can stay blank).', 'wink-wp-gatekeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Test separate credentials', 'wink-wp-gatekeeper' ); ?></th>
						<td>
							<?php /* 【M6·批B·B-3】独立凭据测试（N4 范围边界：DeepSeek 协议族同用
							 * 余额探测，base_url 跟随其配置）。【M6·批E·E-4】按 base_url 分流：
							 * 官方地址=余额探测 / 其他地址（中转站/自建网关）=模型列表探活
							 * （泛端点无标准余额 API）。【W3·批B】迁 test_credential_btn
							 * （五态徽标「未测试」初态统一；id 锚/nonce 字面量留编排位）。 */
							self::test_credential_btn(
								'id="wk-url-audit-provider-test-btn"',
								'id="wk-url-audit-provider-test-result"',
								wp_create_nonce( self::AJAX_TEST_CREDENTIALS_URL_AUDIT ),
								__( 'Test credentials', 'wink-wp-gatekeeper' ),
								'url_audit_provider'
							);
							?>
							<p class="description"><?php esc_html_e( 'Official address = balance probe (zero cost, shows the balance); other addresses = model-list probe (zero tokens). Green = credentials work; red = key rejected or unreachable. The last test status and time are recorded.', 'wink-wp-gatekeeper' ); ?></p>
						</td>
					</tr>
				</table>
				<?php
				/* 【M6·批D·D-2】网页抓取服务商三态（N1 抓取服务商化，§4 C-2）：全部在高级
				 * 折叠区。抓取专用 Key 行组条件显隐（mode=deepseek 才显示——PHP 初始 hidden +
				 * admin-brand.js change 绑定，provider rows 联动范式同款；DOM 锚
				 * wk-url-audit-fetcher-mode / wk-url-audit-fetcher-rows）。 */
				$fetcher_mode = (string) ( $ua['fetcher']['mode'] ?? 'inherit' );
				?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wk-url-audit-fetcher-mode"><?php esc_html_e( 'Fetch provider', 'wink-wp-gatekeeper' ); ?></label></th>
							<td>
								<select id="wk-url-audit-fetcher-mode" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'fetcher', 'mode' ) ) ); ?>">
									<!-- 【M6·批H·H-5】inherit 文案与 LLM提供商行统一「跟随云端审核」语义
									 * +末句保留抓取自身动作说明；【M6·批H·H-1】新语义如实入文案。 -->
									<option value="inherit" <?php selected( $fetcher_mode, 'inherit' ); ?>><?php esc_html_e( 'Follow cloud moderation (default — when cloud moderation is DeepSeek, fetching uses the same configuration; with another driver, fetching is unconfigured and direct fetching from this site does not auto-enable)', 'wink-wp-gatekeeper' ); ?></option>
									<option value="self" <?php selected( $fetcher_mode, 'self' ); ?>><?php esc_html_e( 'Open directly from this site (free)', 'wink-wp-gatekeeper' ); ?></option>
									<option value="deepseek" <?php selected( $fetcher_mode, 'deepseek' ); ?>><?php esc_html_e( 'DeepSeek page fetching (pay-per-use ¥5/1,000)', 'wink-wp-gatekeeper' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'With DeepSeek fetching, your server no longer opens commenters\' URLs itself — DeepSeek fetches the content instead (a much better success rate on blocked / anti-scraping sites, e.g. certain adult or heavily protected sites), billed per success. Direct opening is free but exposes your origin IP and often “cannot tell” on anti-scraping sites — it is never auto-enabled; select it here manually.', 'wink-wp-gatekeeper' ); ?></p>
							</td>
						</tr>
					</table>
				<table class="form-table" role="presentation" id="wk-url-audit-fetcher-rows"<?php echo 'deepseek' === $fetcher_mode ? '' : ' hidden'; ?>>
					<tr>
						<th scope="row"><span class="screen-reader-text"><?php esc_html_e( 'DeepSeek key for fetching', 'wink-wp-gatekeeper' ); ?></span></th>
						<td>
							<?php self::password_field( array( 'url_audit', 'fetcher', 'api_key' ), '' !== (string) ( $ua['fetcher']['api_key'] ?? '' ), __( 'DeepSeek key for fetching', 'wink-wp-gatekeeper' ) ); ?>
							<p class="description"><?php esc_html_e( 'Blank = use the “LLM provider” key above (usually leave it empty). Fill it in only to give page fetching its own DeepSeek account.', 'wink-wp-gatekeeper' ); ?></p>
						</td>
					</tr>
				</table>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Daily allowance', 'wink-wp-gatekeeper' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Batch lane', 'wink-wp-gatekeeper' ); ?> <input type="number" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'batch_quota' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $ua['batch_quota'] ?? 50 ) ); ?>" class="small-text" min="0"> <?php esc_html_e( 'times', 'wink-wp-gatekeeper' ); ?></label>
							<label><?php esc_html_e( 'New-comment lane', 'wink-wp-gatekeeper' ); ?> <input type="number" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'incr_quota' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $ua['incr_quota'] ?? 100 ) ); ?>" class="small-text" min="0"> <?php esc_html_e( 'verdicts (0 = unlimited)', 'wink-wp-gatekeeper' ); ?></label>
							<p class="description"><?php esc_html_e( 'When the quota runs out: the batch lane stops first, new comments stay protected, and everything auto-resumes the next day.', 'wink-wp-gatekeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'robots disallow rules', 'wink-wp-gatekeeper' ); ?></th>
						<td>
							<label><input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'robots_comply' ) ) ); ?>" value="0" /><input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'robots_comply' ) ) ); ?>" value="1" <?php checked( ! empty( $ua['robots_comply'] ) ); ?>> <?php esc_html_e( 'Honor the site\'s robots disallow rules (off by default — honoring them means more “could not tell” cases)', 'wink-wp-gatekeeper' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Check-record cache', 'wink-wp-gatekeeper' ); ?></th>
						<td>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . \WinkGatekeeper\UrlAudit\UrlAuditWorker::ACTION_PURGE ), \WinkGatekeeper\UrlAudit\UrlAuditWorker::ACTION_PURGE ) ); ?>"
								onclick="return confirm('<?php echo esc_js( __( 'Clear all URL check records? The next encounter with the same URL will be checked again (uses quota).', 'wink-wp-gatekeeper' ) ); ?>');"><?php esc_html_e( 'Clear the URL check record cache', 'wink-wp-gatekeeper' ); ?></a>
						</td>
					</tr>
				</table>
					<?php },
			);

			/* 【M6·批H·H-1】网页抓取未配置黄条（U-1 式——§0-1 用户拍板「本站直抓永不
			 * 自动启用」配套可见性）：enabled 且 fetcher.mode 解析=unconfigured（inherit+
			 * 云端审核非 DeepSeek/未配置）才渲染；显式 self/inherit+DS/功能关不渲染
			 * （范式=B-4 notice 同款，折叠区外常显+深链高级区）。 */ ?>
			<?php if ( $enabled && 'unconfigured' === \WinkGatekeeper\UrlAudit\UrlAuditWorker::fetcher_mode( $settings ) ) : ?>
			<?php
			// 【W3·批B】迁段 notice_yellow_bar（H-1 抓取未配置 U-1 式——id 锚/深链留编排位）。
			self::notice_yellow_bar(
				'warn',
				'',
				esc_html__( 'Page fetching is unconfigured — direct fetching from this site is not auto-enabled because it exposes the origin IP; please ', 'wink-wp-gatekeeper' )
					. '<a href="#wk-url-audit-advanced">' . esc_html__( 'pick one manually under “Fetch provider” in the advanced section', 'wink-wp-gatekeeper' ) . '</a>。',
				'',
				'',
				'wk-url-audit-fetcher-unconfigured-notice'
			);
			?>
			<?php endif; ?>

			<?php /* 【M6·批H2·H2-2】DeepSeek 抓取连续未成功风险黄条（fetch_streak_notice_data
			 * 读取器三条件：enabled+达阈 5+48h 新鲜——陈旧静默；动态 N printf 注入=H-2
			 * 动态文案先例。计数口径见 UrlAuditWorker::record_fetch_streak 头注）。 */ ?>
			<?php $fetch_streak = \WinkGatekeeper\UrlAudit\UrlAuditWorker::fetch_streak_notice_data( $settings ); ?>
			<?php if ( null !== $fetch_streak ) : ?>
			<?php
			// 【W3·批B】迁段 notice_yellow_bar（H2-2 连续未成功风险条——动态 N printf 留编排位）。
			self::notice_yellow_bar(
				'warn',
				'',
			/* translators: %d: consecutive failed page-fetch attempts */
				sprintf( esc_html__( 'DeepSeek page fetching has failed %d times in a row — URL check verdicts may not work properly; check credentials, balance, and endpoint status.', 'wink-wp-gatekeeper' ), (int) $fetch_streak['n'] ),
				'',
				'',
				'wk-url-audit-fetch-streak-notice'
			);
			?>
			<?php endif; ?>

			<?php /* 【M6·批B·B-4】网址核查未配置 notice（条件显隐——U-1 缺口）：enabled 且
			 * AI 判定凭证链为空才渲染；配置齐不渲染（范式=UI-1-2 云审未配置 notice 同款）。 */ ?>
			<?php if ( $enabled && $chain_empty ) : ?>
			<?php
			// 【W3·批B】迁段 notice_yellow_bar（B-4 未配置 notice——双深链留编排位）。
			self::notice_yellow_bar(
				'warn',
				'',
				esc_html__( 'The URL check is enabled but the AI verdict (DeepSeek) is not configured yet — checks cannot reach a verdict.', 'wink-wp-gatekeeper' )
					. '<a href="#wk-review-deepseek-card">' . esc_html__( 'enter an API Key in the DeepSeek section above', 'wink-wp-gatekeeper' ) . '</a>'
					. esc_html__( ', or ', 'wink-wp-gatekeeper' )
					. '<a href="#wk-url-audit-advanced">' . esc_html__( 'set up separate credentials for the URL check under “Advanced” below', 'wink-wp-gatekeeper' ) . '</a>。',
				'',
				'',
				'wk-url-audit-unconfigured-notice'
			);
			?>
			<?php endif; ?>

			<?php if ( $site_off ) : ?>
			<?php
			// 【W3·批B】迁段 notice_yellow_bar（inert 条件条——id 锚留编排位）。
			self::notice_yellow_bar(
				'warn',
				'',
				esc_html__( 'Website-field collection is off — new comments carry no URL to check, so this feature auto-disables for new comments; existing URLs can still be handled via “Existing URL check” on the tools page.', 'wink-wp-gatekeeper' ),
				'',
				'',
				'wk-url-audit-inert-notice'
			);
			?>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * cron tick 重排（start/resume 侧；幂等先清后排）。
	 *
	 * @return void
	 */
	private static function audit_schedule_tick(): void {
		self::audit_unschedule_tick();
		wp_schedule_event( time() + 60, \WinkGatekeeper\Audit\ExistingAuditTask::CRON_INTERVAL, \WinkGatekeeper\Audit\ExistingAuditTask::HOOK_TICK );
	}

	/**
	 * cron tick 清除（pause/reset 侧）。
	 *
	 * @return void
	 */
	private static function audit_unschedule_tick(): void {
		$ts = wp_next_scheduled( \WinkGatekeeper\Audit\ExistingAuditTask::HOOK_TICK );
		if ( $ts ) {
			wp_unschedule_event( $ts, \WinkGatekeeper\Audit\ExistingAuditTask::HOOK_TICK );
		}
	}

	/* -------------------------------------------------------------------
	 * Tab③ 网址核查（【M5·U5】UI 设计块 2-B——配置面；清缓存动作）
	 * ------------------------------------------------------------------ */

	/**
	 * 网址核查清缓存处理器（admin_post_wink_gatekeeper_url_audit_purge——Tab③ 高级折叠）：
	 * manage_options+nonce 双闸；清空后重定向回 Tab③（URL 审核语义=下次遇到同一网址重新核查）。
	 *
	 * @return void
	 */
	public static function handle_url_audit_purge(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wink-wp-gatekeeper' ), 403 );
		}
		check_admin_referer( \WinkGatekeeper\UrlAudit\UrlAuditWorker::ACTION_PURGE );

		\WinkGatekeeper\UrlAudit\UrlAuditCache::purge_all();
		wp_safe_redirect( self::tab_url( 'review' ) );
		exit;
	}

	/**
	 * host 级清缓存处理器（Tab⑥ 报告卡「重新核查此站」——站长举报误判入口，nw §4.3）：
	 * manage_options+nonce 双闸；PHP 端 host 比对（防宽匹配误伤他站缓存）。
	 *
	 * @return void
	 */
	public static function handle_url_audit_host_purge(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wink-wp-gatekeeper' ), 403 );
		}
		check_admin_referer( 'wink_gatekeeper_url_host_purge' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- check_admin_referer 已验。
		$host = isset( $_GET['host'] ) && is_scalar( $_GET['host'] ) ? (string) $_GET['host'] : '';
		$host = sanitize_text_field( $host );
		if ( '' !== $host ) {
			\WinkGatekeeper\UrlAudit\UrlAuditCache::purge_host( $host );
		}
		// 【M6·批C·C-3】host 表随报告迁址 Tab⑦——回跳同址（原 stats）。
		wp_safe_redirect( self::tab_url( 'tools' ) );
		exit;
	}

	/**
	 * 【M6·P1-3】存量网址核查显式回捞处理器（admin_post_wink_gatekeeper_url_audit_backlog
	 * ——Tab⑦ 操作卡「开始存量网址核查」）：manage_options+nonce 双闸；把「有 URL 且无
	 * 判定 meta」的存量评论一次性并入批量队列（零外呼零判定——消费走批量车道日额度
	 * 50/日 pacing，额度尽暂停黄条语义照旧）；完成回 Tab⑦（D6 分工：操作卡在此卡）。
	 *
	 * @return void
	 */
	public static function handle_url_audit_backlog(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wink-wp-gatekeeper' ), 403 );
		}
		check_admin_referer( \WinkGatekeeper\UrlAudit\UrlAuditWorker::ACTION_BACKLOG );

		$worker = new \WinkGatekeeper\UrlAudit\UrlAuditWorker( self::settings() );
		$added  = $worker->enqueue_batch_backlog();
		// 【M6·批C·C-3】点击反馈：回跳携带本次已入队数（Tab⑦ 立即呈现「已入队 N 条·
		// 开始核查」——用户 F6「点击运行了但零展示零反馈」）。
		wp_safe_redirect( add_query_arg( array( 'wk-url-backlog' => (string) max( 0, $added ) ), self::tab_url( 'tools' ) ) );
		exit;
	}

	/* -------------------------------------------------------------------
	 * Tab⑤ 文案工坊（03 Tab⑤：16 输入 + 占位符帮助 + 恢复默认）
	 * ------------------------------------------------------------------ */

	private static function render_tab_copy(): void {
		$settings = self::settings();
		$defaults = Copywork::defaults();
		?>
		<section class="wk-card">
			<h2><?php esc_html_e( 'Global: contact email', 'wink-wp-gatekeeper' ); ?></h2>
			<p class="description">
				<input type="email" name="<?php echo esc_attr( self::field_name( array( 'brand', 'contact_email' ) ) ); ?>" value="<?php echo esc_attr( (string) $settings['brand']['contact_email'] ); ?>" class="regular-text" placeholder="webmaster@example.com" />
				<?php esc_html_e( 'Fills the “contact the site owner” spot in default copy such as captcha_failed / ip_malicious (the {contact_email} placeholder).', 'wink-wp-gatekeeper' ); ?>
			</p>
		</section>

		<section class="wk-card">
			<h2><?php esc_html_e( 'Block messages and page titles', 'wink-wp-gatekeeper' ); ?></h2>
			<p class="wk-muted">
				<?php
				// 语气三原则常驻小抄（03 Tab⑤）——不折叠常显。
				esc_html_e( 'Three tone rules: tease the behavior only / leave a way out / point to the next step.', 'wink-wp-gatekeeper' );
				?>
			</p>
			<!-- 【M4UX G3】拦截文案组默认整组折叠（details/wk-guide 既有形态）——
			 * 占位符说明区随组折叠；语气三原则小抄不折叠常显。 -->
			<?php
			// 【W3·批B】文案折叠迁 advanced_fold（G3a：折叠组形态收口；占位符说明/恢复默认钮/文案表随组折叠原样，id 锚留编排位）。
			self::advanced_fold(
				'id="wk-copy-advanced"',
				__( 'Advanced (block messages / page titles / placeholder reference)', 'wink-wp-gatekeeper' ),
				static function () use ( $settings, $defaults ): void {
					?>
			<dl class="wk-placeholder-list">
				<!-- 【M4UX UI-3-1·定稿 §3】占位符逐个定义（原一行罗列退役）；{rule} 附 Labels 映射示例。 -->
				<dt><code>{min_length}</code> / <code>{max_length}</code></dt>
				<dd><?php esc_html_e( 'The local length gate\'s actual verdict values (the current “Minimum length / Maximum length” settings on Tab 3).', 'wink-wp-gatekeeper' ); ?></dd>
				<dt><code>{event_no}</code></dt>
				<dd><?php esc_html_e( 'The event number of this block — use it to look up details in the Tab 6 event stream.', 'wink-wp-gatekeeper' ); ?></dd>
				<dt><code>{rule}</code></dt>
				<dd>
					<?php esc_html_e( 'The human-readable name of the matched rule key (translated by the Labels map; unmapped keys display as-is; the actual keyword hit is visible to admins only in the event stream). Mapping examples:', 'wink-wp-gatekeeper' ); ?>
					<!-- 【M4UX F3-4】映射示例独立样式行（术语 code 与示例排版分离）。 -->
					<code class="wk-example-line"><?php echo esc_html( __( 'remote_semantic → “Provider refused the request (check configuration or billing)” · banned_word → “Keyword blacklist hit”', 'wink-wp-gatekeeper' ) ); ?></code>
				</dd>
				<dt><code>{time}</code></dt>
				<dd><?php esc_html_e( 'When the block happened (site timezone).', 'wink-wp-gatekeeper' ); ?></dd>
				<dt><code>{contact_email}</code></dt>
				<dd><?php esc_html_e( 'The “contact email” value from above (if blank, the whole sentence with this placeholder is omitted).', 'wink-wp-gatekeeper' ); ?></dd>
			</dl>
			<button type="button" class="button" id="wk-copy-restore-defaults" data-confirm="<?php echo esc_attr__( 'Restore all copy to defaults? Unsaved changes will be lost.', 'wink-wp-gatekeeper' ); ?>">
				<?php esc_html_e( 'Restore default copy', 'wink-wp-gatekeeper' ); ?>
			</button>

			<table class="form-table" role="presentation">
				<?php foreach ( Copywork::keys() as $key ) : ?>
					<tr>
						<th scope="row">
							<code><?php echo esc_html( $key ); ?></code><br />
							<span class="wk-muted"><?php echo esc_html( self::copy_scene_label( $key ) ); ?></span>
						</th>
						<td>
							<label class="screen-reader-text" for="wk-copy-message-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( sprintf( /* translators: %s: 规则键 */ __( '%s block message', 'wink-wp-gatekeeper' ), $key ) ); ?></label>
							<textarea id="wk-copy-message-<?php echo esc_attr( $key ); ?>" class="large-text" rows="2"
								name="<?php echo esc_attr( self::field_name( array( 'copy', 'messages', $key ) ) ); ?>"
								placeholder="<?php echo esc_attr( $defaults['messages'][ $key ] ); ?>"><?php echo esc_textarea( (string) $settings['copy']['messages'][ $key ] ); ?></textarea>
							<label class="screen-reader-text" for="wk-copy-title-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( sprintf( /* translators: %s: 规则键 */ __( '%s page title', 'wink-wp-gatekeeper' ), $key ) ); ?></label>
							<input type="text" id="wk-copy-title-<?php echo esc_attr( $key ); ?>" class="regular-text"
								name="<?php echo esc_attr( self::field_name( array( 'copy', 'titles', $key ) ) ); ?>"
								placeholder="<?php echo esc_attr( $defaults['titles'][ $key ] ); ?>"
								value="<?php echo esc_attr( (string) $settings['copy']['titles'][ $key ] ); ?>" />
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
					<?php
				}
			);
			?>
		</section>

		<section class="wk-card">
			<h2><?php esc_html_e( 'Block page customization', 'wink-wp-gatekeeper' ); ?></h2>
			<p class="wk-muted">
				<?php
				// 【v1.5·B1】站长自定义拦截页覆盖位（Wordfence blockCustomText 先例）。
				esc_html_e( 'Applies to the title and body of the branded full-page block page (shared by all scenarios). Blank = each scenario\'s default copy. Placeholders: {event_no} {rule} {time} {contact_email} — {rule} shows the rule key only.', 'wink-wp-gatekeeper' );
				?>
			</p>
			<p class="description"><?php esc_html_e( 'A permanent brand signature line “Wink WP Gatekeeper” at the end of the body — click “Preview” to see it in the live-render preview (landed in W3 batch B).', 'wink-wp-gatekeeper' ); ?></p>
			<p>
				<!-- 【M4UX G3】「查看效果」——新标签页打开拦截页实渲染预览（admin action
				 * wink_gatekeeper_die_preview：manage_options+nonce 双闸，零落库零事件零统计）。 -->
				<a class="button button-secondary" id="wk-die-preview" target="_blank" rel="noopener"
					href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_DIE_PREVIEW ), self::ACTION_DIE_PREVIEW ) ); ?>">
					<?php esc_html_e( 'Preview', 'wink-wp-gatekeeper' ); ?>
				</a>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-copy-die-page-title"><?php esc_html_e( 'Block page title', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="text" id="wk-copy-die-page-title" class="regular-text"
							name="<?php echo esc_attr( self::field_name( array( 'copy', 'die_page_title' ) ) ); ?>"
							value="<?php echo esc_attr( (string) $settings['copy']['die_page_title'] ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-copy-die-page-message"><?php esc_html_e( 'Block page body', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<textarea id="wk-copy-die-page-message" class="large-text" rows="3"
							name="<?php echo esc_attr( self::field_name( array( 'copy', 'die_page_message' ) ) ); ?>"><?php echo esc_textarea( (string) $settings['copy']['die_page_message'] ); ?></textarea>
					</td>
				</tr>
			</table>
		</section>

			<section class="wk-card">
				<h2><?php esc_html_e( 'Cloud label mapping (read-only preview)', 'wink-wp-gatekeeper' ); ?></h2>
				<p class="wk-muted">
					<?php
					// 【v1.5·F4-3】映射表只读预览（落点=Copywork 键值表，映射键名不可改）；
					// 文案可经 wink_gatekeeper_error_messages filter 覆盖，未收录标签原样显示。
					esc_html_e( 'The block page\'s “matched rule” evidence line maps raw Label,Score from cloud drivers into human-readable form (provider name prefix + translated label + confidence). Unmapped labels display as-is.', 'wink-wp-gatekeeper' );
					?>
				</p>
				<p class="description">
					<!-- 【M4UX UI-3-2·定稿 §3】统一表说明行（用户提问「云端标签映射是否跨驱动」——是）。 -->
					<?php esc_html_e( 'One shared table for all cloud drivers: each driver\'s reason is already normalized to Label,Score form, so one table serves Tencent TMS / Aliyun Green / Baidu alike — no per-driver tables needed.', 'wink-wp-gatekeeper' ); ?>
				</p>
			<table class="form-table" role="presentation">
				<?php foreach ( Copywork::cloud_labels() as $label_key => $zh ) : ?>
					<tr>
						<th scope="row"><code><?php echo esc_html( $label_key ); ?></code></th>
						<td><?php echo esc_html( $zh ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		</section>
		<?php
	}

	/**
	 * 文案键的场景说明（展示用途，随 Copywork::keys() 派生）。
	 *
	 * @param string $key 文案键。
	 * @return string
	 */
	private static function copy_scene_label( string $key ): string {
		$labels = array(
			'too_short'       => __( 'Length gate: comment too short', 'wink-wp-gatekeeper' ),
			'too_long'        => __( 'Length gate: comment too long', 'wink-wp-gatekeeper' ),
			'no_chinese'      => __( 'Chinese gate: no Chinese present', 'wink-wp-gatekeeper' ),
			'banned_word'     => __( 'Keyword / regex match', 'wink-wp-gatekeeper' ),
			'captcha_failed'  => __( 'Captcha verification failed', 'wink-wp-gatekeeper' ),
			'captcha_missing' => __( 'Submitted without completing verification', 'wink-wp-gatekeeper' ),
			'ip_malicious'    => __( 'Blocked by malicious IP score', 'wink-wp-gatekeeper' ),
			'cloud_error'     => __( 'Cloud moderation failure, sent to pending review', 'wink-wp-gatekeeper' ),
		);
		return $labels[ $key ] ?? $key;
	}

	/* -------------------------------------------------------------------
	 * Tab⑥ 统计与导出（03 Tab⑥ v1.3：分区计数卡 + 最近拦截明细 + 黄条 + 重置 AJAX）
	 * ------------------------------------------------------------------ */

	private static function render_tab_stats(): void {
		$settings = self::settings();
		$cache    = \WinkGatekeeper\Stats\StatsCache::get();

		/* UI 批 1（DESIGN-UI-tab6 A 提案三口径骨架）：五段编排——速览条→评论防护→
		 * 登录防护→报错历史→拦截记录流水→统计设置·清零；每段「结果→归因→案例」节奏。
		 * 批 2 已接线：登录明细卡（N1）/全闸报错时间线（N2+N3）/服务商卡出错格（N6+N7a）
		 * ——占位提示全部移除。UI-FIX B（2026-11-12 用户反馈）：两级标题制全页零序号
		 * （段标题 wk-sec-title > 段内小标题 wk-sub-title），配置指引收敛段标题单入口。 */
		$registry      = null !== self::$driver_registry ? self::$driver_registry : null;
		$ip_driver_ids = array_keys( null !== $registry ? $registry->by_type( 'ip' ) : array() );
		$split_today   = \WinkGatekeeper\Stats\InterceptDigest::review_split( $cache, $ip_driver_ids, false );

		// 累计窗口（last_reset 纯展示——从未清零为 0，不标注起点）。
		$last_reset = isset( $cache['last_reset'] ) && is_numeric( $cache['last_reset'] ) ? (int) $cache['last_reset'] : 0;
		$since_date = $last_reset > 0 ? date_i18n( 'Y-m-d', $last_reset ) : '';

		self::render_stats_overview_strip( $cache, $settings, $split_today, $since_date );
		self::render_stats_comment_section( $cache, $split_today, $since_date );
		// 【M6·批C·C-3】历史评论体检的报告卡退役迁「工具」页（用户 2026-11-16 反馈
		// 「统计页展示消息已够杂乱」）——本页零残留；运行中黄条保留在总览页通栏（报告
		// 迁址后其入口链接随之改指工具页）。相关指引文案同步改指工具页存量网址核查。
		self::render_stats_login_section( $cache, $settings );
		self::render_stats_fault_section();
		self::render_stats_records_card();
		self::render_stats_settings_card( $settings );
	}

	/**
	 * 速览条（全站一眼——DESIGN-UI-tab6 §6：今日拦下/转人工复核/出错 + 累计窗口 + spam 黄条原位）。
	 *
	 * 口径：拦下=by_gate reject 全闸求和（次）；转人工复核=review_split 排除 IP 信誉强制
	 * 验证码（条——review 键只存在于评论门）；出错=by_driver error 求和（次）；
	 * 累计=total.reject + last_reset 窗口标注（P9 修复）。
	 *
	 * @param array<string, mixed>  $cache       StatsCache 树。
	 * @param array<string, mixed>  $settings    设置树。
	 * @param array{review: int, ip_review: int, ip_captcha: int} $split_today 待审拆分（今日）。
	 * @param string                $since_date  上次清零日期（''=从未清零）。
	 * @return void
	 */
	private static function render_stats_overview_strip( array $cache, array $settings, array $split_today, string $since_date ): void {
		$today_reject = 0;
		foreach ( (array) ( $cache['by_gate'] ?? array() ) as $counts ) {
			$today_reject += (int) ( is_array( $counts ) ? ( $counts['reject'] ?? 0 ) : 0 );
		}
		$today_error = 0;
		foreach ( (array) ( $cache['by_driver'] ?? array() ) as $counts ) {
			$today_error += (int) ( is_array( $counts ) ? ( $counts['error'] ?? 0 ) : 0 );
		}
		$review_n = (int) ( $split_today['review'] ?? 0 );

		// 留档量黄条数据（spam 计数 = 本插件留档且仍在垃圾分队）。
		$spam_count = \WinkGatekeeper\Stats\StatsCache::archived_spam_count();
		?>
		<section class="wk-card wk-card--metric" id="wk-stats-overview">
			<h2 class="wk-sec-title"><?php esc_html_e( 'Today at a glance', 'wink-wp-gatekeeper' ); ?></h2>
			<p class="wk-metric"><span class="wk-metric__num"><?php echo esc_html( (string) $today_reject ); ?></span></p>
			<p>
				<?php
				echo esc_html( sprintf(
					/* translators: 1: 今日拦下, 2: 转人工复核, 3: 出错 */
					__( '%1$d blocked today · %2$d to manual review · %3$d errors', 'wink-wp-gatekeeper' ),
					$today_reject,
					$review_n,
					$today_error
				) );
				?>
			</p>
			<p class="wk-muted"><?php echo esc_html( \WinkGatekeeper\Stats\Labels::cumulative_line( (string) number_format_i18n( (int) ( $cache['total']['reject'] ?? 0 ) ), __( 'times', 'wink-wp-gatekeeper' ), $since_date ) ); ?></p>
			<?php if ( empty( $settings['stats']['enabled'] ) ) : ?>
				<p class="wk-muted"><strong><?php esc_html_e( 'Counting disabled', 'wink-wp-gatekeeper' ); ?></strong><?php esc_html_e( '— blocks during statistics-off periods are not counted, while the event table still records them (this page is authoritative for block details).', 'wink-wp-gatekeeper' ); ?></p>
			<?php endif; ?>
		</section>

		<?php if ( \WinkGatekeeper\Stats\InterceptDigest::spam_warn( $spam_count ) ) : ?>
			<section class="wk-notice wk-notice--warn">
				<p>
					<?php
					echo esc_html( sprintf(
						/* translators: 1: 留档条数, 2: 阈值 */
						__( 'This plugin\'s block archive holds %1$s entries (threshold %2$s) — WP\'s native spam cleanup (30 days by default) handles it automatically, or clear it manually under the Spam category; archiving can also be disabled in settings.', 'wink-wp-gatekeeper' ),
						number_format_i18n( $spam_count ),
						number_format_i18n( \WinkGatekeeper\Stats\InterceptDigest::SPAM_WARN_THRESHOLD )
					) );
					?>
					<a href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=spam' ) ); ?>"><?php esc_html_e( 'View the Spam category', 'wink-wp-gatekeeper' ); ?></a>
				</p>
			</section>
		<?php endif; ?>
		<?php
	}

	/**
	 * 评论防护段（口径一——DESIGN-UI-tab6 §2.1：今日概览→谁拦的→最近明细）。
	 *
	 * UI-FIX B：段标题两级制（wk-sec-title/wk-sub-title，零序号）；配置指引收敛到
	 * 段标题单入口（stats_config_hint——段内有未启用卡才出现）。
	 *
	 * @param array<string, mixed> $cache       StatsCache 树。
	 * @param array{review: int, ip_review: int, ip_captcha: int} $split_today 待审拆分（今日）。
	 * @param string               $since_date  上次清零日期。
	 * @return void
	 */
	private static function render_stats_comment_section( array $cache, array $split_today, string $since_date ): void {
		$comment       = \WinkGatekeeper\Stats\InterceptDigest::gate_counts( $cache, 'comment', false );
		$comment_total = \WinkGatekeeper\Stats\InterceptDigest::gate_counts( $cache, 'comment', true );
		$local_rules   = \WinkGatekeeper\Stats\InterceptDigest::local_rules_card( $cache );
		$cards         = self::stats_driver_cards( 'comment' );
		$ip_captcha    = (int) ( $split_today['ip_captcha'] ?? 0 );
		?>
		<section class="wk-card" id="wk-stats-comment">
			<h2 class="wk-sec-title"><?php esc_html_e( 'Comment protection', 'wink-wp-gatekeeper' ); ?><?php echo self::stats_config_hint( $cards ); // phpcs:ignore WordPress.Security.EscapeOutput -- 内部组装已转义。 ?></h2>

			<h3 class="wk-sub-title"><?php esc_html_e( 'Today\'s overview', 'wink-wp-gatekeeper' ); ?></h3>
			<p>
				<?php
				echo esc_html( sprintf(
					/* translators: 1: 拦下, 2: 转人工复核, 3: 出错 */
					__( '%1$d blocked · %2$d to manual review · %3$d errors', 'wink-wp-gatekeeper' ),
					(int) ( $comment['reject'] ?? 0 ),
					(int) ( $split_today['review'] ?? 0 ),
					(int) ( $comment['error'] ?? 0 )
				) );
				?>
			</p>
			<p class="wk-muted">
				<?php
				echo esc_html( sprintf(
					/* translators: %s: 累计行（含窗口标注） */
					__( '“To manual review” means it sits in the pending queue awaiting you. %s', 'wink-wp-gatekeeper' ),
					\WinkGatekeeper\Stats\Labels::cumulative_line( (string) number_format_i18n( (int) ( $comment_total['reject'] ?? 0 ) ), __( 'comments', 'wink-wp-gatekeeper' ), $since_date )
				) );
				?>
			</p>
			<p class="wk-muted"><?php echo esc_html( sprintf( /* translators: %d: IP 风控强制验证码次数 */ __( '%d IP-reputation-enforced captchas (counted separately — not included in the manual-review figures above).', 'wink-wp-gatekeeper' ), $ip_captcha ) ); ?></p>

			<?php
			/* 【M5·U5】网址核查统计行（UI 块 3.3——与 IP 风控单列行同位形态；K8/K9/K10 口径）：
			 * 核查=评论者网址被打开看过（缓存命中不算）；自动标垃圾可撤销；待复核=URL 相关待审。 */
			$url_stats = ( new \WinkGatekeeper\UrlAudit\UrlAuditWorker( $settings ?? array() ) )->stats_line();
			if ( $url_stats['today_checked'] > 0 || $url_stats['auto_rejected'] > 0 || $url_stats['pending_review'] > 0 ) :
				?>
			<p id="wk-stats-url-line" class="wk-muted"><?php
				printf(
				/* translators: 1: URLs checked today, 2: auto-marked as spam, 3: awaiting your review */
					esc_html__( 'URL check: %1$d checked today · %2$d auto-spammed · %3$d awaiting your review (checked = the URL was opened and looked at; auto-spam is undoable)', 'wink-wp-gatekeeper' ),
					(int) $url_stats['today_checked'],
					(int) $url_stats['auto_rejected'],
					(int) $url_stats['pending_review']
				);
				?></p>
			<?php endif; ?>

			<?php
			/* 【M6·批B·B-2】网址核查搜索消耗行（统计页消耗段加行——deepseek_search 成功次；
			 * 数据门控渲染：零消耗整行不显，K 系口径与今日没用到同纪律；注：本函数体内
			 * 禁 circled 序号字样——零序号契约扫描函数体含注释）。 */
			$url_search_used = \WinkGatekeeper\Stats\Usage::daily_total_for( \WinkGatekeeper\Stats\Usage::get(), \WinkGatekeeper\Stats\Usage::KEY_DEEPSEEK_SEARCH );
			if ( $url_search_used > 0 ) :
				?>
			<p id="wk-stats-url-search-line" class="wk-muted"><?php
				printf(
					/* translators: %d: 今日 DeepSeek 搜索成功次数 */
					esc_html__( 'URL check search (DeepSeek): %1$d today (billed per success; search clues for pages that will not open)', 'wink-wp-gatekeeper' ),
					(int) $url_search_used
				);
				?></p>
			<?php endif; ?>

			<?php
			/* 【M6·批D·D-5】网页抓取消耗行（K 系口径：抓取=第三方打开网页看过——DeepSeek
			 * Fetch 成功次；数据门控渲染：零消耗整行不显，同上纪律）。 */
			$url_fetch_used = \WinkGatekeeper\Stats\Usage::daily_total_for( \WinkGatekeeper\Stats\Usage::get(), \WinkGatekeeper\Stats\Usage::KEY_URL_AUDIT_FETCH );
			if ( $url_fetch_used > 0 ) :
				?>
			<p id="wk-stats-url-fetch-line" class="wk-muted"><?php
				printf(
					/* translators: %d: 今日 DeepSeek 网页抓取成功次数 */
					esc_html__( 'Page fetching (DeepSeek opens pages for you): %1$d today (billed per success — with “DeepSeek page fetching” selected, the third party opened and viewed the page)', 'wink-wp-gatekeeper' ),
					(int) $url_fetch_used
				);
				?></p>
			<?php endif; ?>

			<h3 class="wk-sub-title"><?php esc_html_e( 'Who blocked it (in chain order)', 'wink-wp-gatekeeper' ); ?></h3>
			<div class="wk-driver-card">
				<p><strong><?php echo esc_html( (string) $local_rules['label'] ); ?></strong> <span class="wk-muted"><?php esc_html_e( '— length / keyword / Chinese checks, costs nothing', 'wink-wp-gatekeeper' ); ?></span></p>
				<p><?php echo esc_html( (string) $local_rules['line'] ); ?></p>
				<p class="wk-muted"><?php echo esc_html( (string) $local_rules['total_line'] ); ?></p>
			</div>

			<h4 class="wk-defense-group"><?php esc_html_e( 'Human verification line (third party, pay-per-use)', 'wink-wp-gatekeeper' ); ?></h4>
			<?php foreach ( $cards as $card ) : ?>
				<?php if ( 'captcha' === ( $card['type'] ?? '' ) ) { self::render_driver_card( $card ); } ?>
			<?php endforeach; ?>

			<h4 class="wk-defense-group"><?php esc_html_e( 'Cloud moderation line (third party, pay-per-use)', 'wink-wp-gatekeeper' ); ?></h4>
			<?php foreach ( $cards as $card ) : ?>
				<?php if ( 'review' === ( $card['type'] ?? '' ) ) { self::render_driver_card( $card ); } ?>
			<?php endforeach; ?>

			<?php /* 【v1.7·M3-IP·定稿 §9】IP 信誉防线组——registry type='ip' 驱动卡（动作名 Labels「信誉查询」）。 */ ?>
			<h4 class="wk-defense-group"><?php esc_html_e( 'IP reputation line (third party, pay-per-use)', 'wink-wp-gatekeeper' ); ?></h4>
			<?php foreach ( $cards as $card ) : ?>
				<?php if ( 'ip' === ( $card['type'] ?? '' ) ) { self::render_driver_card( $card ); } ?>
			<?php endforeach; ?>
			<p class="wk-muted"><?php esc_html_e( 'Passed = derived figure (today\'s actions − blocked − manual review − errors).', 'wink-wp-gatekeeper' ); ?></p>

			<?php
			/* 【M6·批H·H-9】网址核查今日处置数据源（meta 反查——零新事件零写放大，事件表
			 * 拦截口径保持纯净 P1-4 不稀释）；【M6·批H3·H3-D】取值上移一次反查两处消费
			 * （H-9 头部行+H3-D「谁拦的」复核块）；零处置=两处均不渲染（K 系纪律）。 */
			$url_disposal = ( new \WinkGatekeeper\UrlAudit\UrlAuditWorker( $settings ?? array() ) )->today_disposal_line();
			/* 【M6·批H3·H3-D】网址核查复核处置块（手测反馈 D；用户拍板口径：转待审也算拦）
			 * ——「谁拦的（按拦截链顺序）」区尾殿后块（复核处置不在同步链上故殿后，区别于
			 * 上面四道同步防线）。 */
			if ( $url_disposal['total'] > 0 ) :
				?>
			<?php /* 【M6·批H4·H4-4】复核块套防线组标题（手测二轮⑭打回：裸卡与四道防线
			 * 「h4 分组+卡」节奏不齐观感诡异——对齐组结构）。 */ ?>
			<h4 class="wk-defense-group"><?php esc_html_e( 'Post-publish review line (URL check)', 'wink-wp-gatekeeper' ); ?></h4>
			<div class="wk-driver-card" id="wk-stats-url-intercept-block">
				<p><strong><?php echo esc_html__( 'URL check (review handling)', 'wink-wp-gatekeeper' ); // phpcs:ignore WordPress.Security.EscapeOutput -- esc_html__ 已转义。 ?></strong> <span class="wk-muted"><?php esc_html_e( '— URLs are re-checked after the comment is published; both violations and unclear ones are held for your review', 'wink-wp-gatekeeper' ); ?></span></p>
				<p><?php
					printf(
						/* translators: 1: spam 2: hold */
						esc_html__( '%1$d sent to Spam today · %2$d to pending review', 'wink-wp-gatekeeper' ),
						(int) $url_disposal['spam'],
						(int) $url_disposal['hold']
					);
				?></p>
			</div>
			<?php endif; ?>

			<h3 class="wk-sub-title"><?php esc_html_e( 'Recently blocked comments (last 10)', 'wink-wp-gatekeeper' ); ?></h3>
			<?php
			/* 【M6·批H·H-9】网址核查今日处置汇总行（区头部——「网址核查今日处置 N 条
			 * （垃圾箱 X·待审 Y）→ 查看详情」深链工具页报告锚）。 */
			if ( $url_disposal['total'] > 0 ) :
				?>
			<p id="wk-stats-url-disposal-line" class="wk-muted"><?php
				printf(
					/* translators: 1: total 2: spam 3: hold */
					esc_html__( 'URL check handled %1$d today (Spam %2$d · pending %3$d)', 'wink-wp-gatekeeper' ),
					(int) $url_disposal['total'],
					(int) $url_disposal['spam'],
					(int) $url_disposal['hold']
				);
				?><a href="<?php echo esc_url( self::tab_url( 'tools' ) . '#wk-tools-url-audit-report' ); ?>"><?php esc_html_e( '→ View details', 'wink-wp-gatekeeper' ); ?></a></p>
			<?php endif; ?>
			<?php self::render_comment_recent_table(); ?>
		</section>
		<?php
	}

	/**
	 * 登录防护段（口径二——DESIGN-UI-tab6 §2.2：今日概览→三张门分账→服务商口径→明细）。
	 *
	 * UI-FIX B：段标题两级制零序号；配置指引收敛段标题单入口（「登录防护 · 去配置」）。
	 *
	 * @param array<string, mixed> $cache    StatsCache 树。
	 * @param array<string, mixed> $settings 设置树。
	 * @return void
	 */
	private static function render_stats_login_section( array $cache, array $settings ): void {
		// 窗口标注随配置动态（D3——login_adaptive.window_minutes）。
		$window = isset( $settings['captcha']['login_adaptive']['window_minutes'] ) && is_numeric( $settings['captcha']['login_adaptive']['window_minutes'] )
			? (int) $settings['captcha']['login_adaptive']['window_minutes']
			: 15;
		$today_overview = \WinkGatekeeper\Stats\InterceptDigest::account_overview( $cache, false );
		$total_overview = \WinkGatekeeper\Stats\InterceptDigest::account_overview( $cache, true );
		$gate_rows      = \WinkGatekeeper\Stats\InterceptDigest::account_gate_rows( $cache );
		// 登录门说明列带动态窗口（account_gate_rows 用默认 15 组装——此处按配置覆写）。
		if ( isset( $gate_rows[0] ) && 'login' === ( $gate_rows[0]['gate'] ?? '' ) ) {
			$gate_rows[0]['note'] = \WinkGatekeeper\Stats\InterceptDigest::gate_note( 'login', $gate_rows[0]['today'], $window );
		}
		$cards = self::stats_driver_cards( 'login' );
		?>
		<section class="wk-card" id="wk-stats-login">
			<h2 class="wk-sec-title"><?php esc_html_e( 'Login protection (three gates: login / registration / password recovery)', 'wink-wp-gatekeeper' ); ?><?php echo self::stats_config_hint( $cards ); // phpcs:ignore WordPress.Security.EscapeOutput -- 内部组装已转义。 ?></h2>

			<h3 class="wk-sub-title"><?php esc_html_e( 'Today\'s overview', 'wink-wp-gatekeeper' ); ?></h3>
			<p>
				<?php
				echo esc_html( sprintf(
					/* translators: 1: 拦下, 2: 验证放行, 3: 密码输错, 4: 窗口分钟 */
					__( '%1$d blocked today · %2$d passed via verification · %3$d wrong passwords (same account within %4$d minutes)', 'wink-wp-gatekeeper' ),
					(int) ( $today_overview['reject'] ?? 0 ),
					(int) ( $today_overview['passed'] ?? 0 ),
					(int) ( $today_overview['attempts'] ?? 0 ),
					$window
				) );
				?>
			</p>
			<p class="wk-muted"><?php echo esc_html( sprintf( /* translators: 1: 累计拦下, 2: 累计密码输错 */ __( '%1$d blocked cumulatively · %2$d wrong passwords', 'wink-wp-gatekeeper' ), (int) ( $total_overview['reject'] ?? 0 ), (int) ( $total_overview['attempts'] ?? 0 ) ) ); ?></p>

			<h3 class="wk-sub-title"><?php esc_html_e( 'Breakdown by gate', 'wink-wp-gatekeeper' ); ?></h3>
			<table class="wk-table">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html( \WinkGatekeeper\Stats\Labels::gate_word( 'login' ) ); ?>/<?php echo esc_html( \WinkGatekeeper\Stats\Labels::gate_word( 'register' ) ); ?>/<?php echo esc_html( \WinkGatekeeper\Stats\Labels::gate_word( 'lostpassword' ) ); ?></th>
						<th scope="col"><?php esc_html_e( 'Blocked today', 'wink-wp-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Blocked cumulatively', 'wink-wp-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Notes', 'wink-wp-gatekeeper' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $gate_rows as $gate_row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $gate_row['label'] ); ?></td>
							<td><?php echo esc_html( (string) $gate_row['today_reject'] ); ?></td>
							<td><?php echo esc_html( (string) $gate_row['total_reject'] ); ?></td>
							<td class="wk-muted"><?php echo esc_html( (string) $gate_row['note'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3 class="wk-sub-title"><?php esc_html_e( 'Who blocked it (by provider)', 'wink-wp-gatekeeper' ); ?></h3>
			<?php foreach ( $cards as $card ) : ?>
				<?php self::render_driver_card( $card ); ?>
			<?php endforeach; ?>

			<h3 class="wk-sub-title"><?php esc_html_e( 'Recently blocked login attempts (last 10)', 'wink-wp-gatekeeper' ); ?></h3>
			<?php self::render_account_recent_table(); ?>
		</section>
		<?php
	}

	/**
	 * 「最近拦下的登录尝试」明细表（UI 批 2 接线——批 1 占位卡退役；数据源 N1：
	 * EventsTable::recent_account_rejects + 行组装 InterceptDigest::account_reject_rows）。
	 *
	 * 列面：时间 / 事件编号 / 哪张门 / 哪道防线 / 为什么拦（trigger_source 归因人话，
	 * 括号注防线触发；缺省回落 rule_hit 人话）。PIPL 最小化：IP 哈希省略（查询列集
	 * 即不含）、无明文账号列（事件表本就不存——只显特征与结果）。
	 *
	 * @return void
	 */
	private static function render_account_recent_table(): void {
		$events = \WinkGatekeeper\Stats\EventsTable::recent_account_rejects( \WinkGatekeeper\Stats\InterceptDigest::RECENT_LIMIT );
		$rows   = \WinkGatekeeper\Stats\InterceptDigest::account_reject_rows( $events );
		?>
		<p class="wk-muted"><?php esc_html_e( 'Only characteristics and outcomes are shown, never accounts or IPs; outcome = stopped-by-captcha / stopped-without-captcha (passes never enter this table); why blocked = trigger attribution (too many consecutive failures for one account, etc.) or the captcha result.', 'wink-wp-gatekeeper' ); ?></p>
		<?php if ( array() === $rows ) : ?>
			<p class="wk-muted"><?php esc_html_e( 'No login attempts blocked recently — that is a good state.', 'wink-wp-gatekeeper' ); ?></p>
		<?php else : ?>
			<?php /* 【W3·批B·四改三】登录流水列面=现数据面（Q3 统筹代裁——原型账号/IP 两打码列
			 * 现码无数据源且踩 PIPL 红线〔InterceptDigest.php:182 IP 哈希省略〕，不虚构列；
			 * 原型措辞回写译文基线：时间/结果三态〔出码·拦下｜未过码·拦下——放行不在拒绝数据面〕/
			 * 哪张门/为什么拦/WK- 等宽。结果映射走 Labels::account_result_word 纯函数）。 */ ?>
			<table class="wk-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Time', 'wink-wp-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Result', 'wink-wp-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Gate', 'wink-wp-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Why blocked', 'wink-wp-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Event No.', 'wink-wp-gatekeeper' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
							<td><?php echo esc_html( \WinkGatekeeper\Stats\Labels::account_result_word( (string) $row['stage'] ) ); ?></td>
							<td><?php echo esc_html( (string) $row['gate_word'] ); ?></td>
							<td>
								<?php echo esc_html( (string) $row['reason'] ); ?>
								<?php if ( '' !== (string) $row['trigger'] ) : ?>
									<span class="wk-muted">（<?php echo esc_html( (string) $row['trigger'] ); ?>）</span>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( (string) $row['event_no'] ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * 三、报错历史（口径三——DESIGN-UI-tab6 §2.3：今日出错 + 人话分布 + 最近报错表迁入）。
	 *
	 * 【UI 批 2 接线】最近报错小节 = 全闸报错时间线（N2：EventsTable::recent_faults，
	 * fault_predicate_sql 同源复用——F7）∪ 停转进入·解除时间线（N3：recent_health_timeline，
	 * driver_resumed 非故障口径的解除留痕）——InterceptDigest::fault_timeline_rows 按 id
	 * 倒序归并，「当时怎么处理」组合映射（handling_word），meta.raw 展开行保留。
	 *
	 * @return void
	 */
	private static function render_stats_fault_section(): void {
		$cache = \WinkGatekeeper\Stats\StatsCache::get();

		// 今日出错总数：分布合计与计数缓存对账取大（现行口径保留——【v1.4·反馈1/R4 §D.2】）。
		$fault_dist  = \WinkGatekeeper\Stats\EventsTable::today_fault_distribution();
		$fault_total = array_sum( $fault_dist );
		foreach ( (array) ( $cache['by_driver'] ?? array() ) as $fault_driver_counts ) {
			$fault_total += (int) ( is_array( $fault_driver_counts ) ? ( $fault_driver_counts['error'] ?? 0 ) : 0 );
		}

		// 全闸时间线行（N2 故障 ∪ N3 停转进入·解除；服务商名 registry label 解析）。
		$fault_events  = \WinkGatekeeper\Stats\EventsTable::recent_faults( \WinkGatekeeper\Stats\InterceptDigest::RECENT_LIMIT );
		$health_events = \WinkGatekeeper\Stats\EventsTable::recent_health_timeline( \WinkGatekeeper\Stats\InterceptDigest::RECENT_LIMIT, 7 );
		$driver_labels = array();
		$registry      = null !== self::$driver_registry ? self::$driver_registry : null;
		if ( null !== $registry ) {
			foreach ( $registry->all() as $driver_id => $driver_meta ) {
				if ( is_string( $driver_meta['label'] ?? null ) ) {
					$driver_labels[ (string) $driver_id ] = (string) $driver_meta['label'];
				}
			}
		}
		$fault_rows = \WinkGatekeeper\Stats\InterceptDigest::fault_timeline_rows( $fault_events, $health_events, $driver_labels );
		?>
		<section class="wk-card" id="wk-stats-faults">
			<h2 class="wk-sec-title"><?php esc_html_e( 'Error history (things that went wrong with third-party providers)', 'wink-wp-gatekeeper' ); ?></h2>

			<h3 class="wk-sub-title"><?php esc_html_e( 'Errors today', 'wink-wp-gatekeeper' ); ?></h3>
			<p>
				<?php echo esc_html( sprintf( /* translators: %d: 今日出错次数 */ _x( '%d errors', 'error-count', 'wink-wp-gatekeeper' ), $fault_total ) ); ?>
			</p>
			<?php if ( array() !== $fault_dist ) : ?>
				<p class="wk-muted">
					<?php
					$fault_parts = array();
					foreach ( $fault_dist as $fault_rule => $fault_n ) {
						// rule_hit → 人话（N5 映射；未收录键原样——一律 esc_html 转义直出）。
						$fault_parts[] = esc_html( \WinkGatekeeper\Stats\Labels::rule_hit_word( (string) $fault_rule ) ) . ' ×' . esc_html( (string) $fault_n );
					}
					echo implode( ' · ', $fault_parts ); // 逐段已 esc_html（rule_hit 驱动来源文本一律转义）。
					?>
				</p>
			<?php else : ?>
				<p class="wk-muted"><?php esc_html_e( 'No errors today — every line is healthy.', 'wink-wp-gatekeeper' ); ?></p>
			<?php endif; ?>

			<h3 class="wk-sub-title"><?php esc_html_e( 'Recent errors (all gates, including provider pauses and recoveries)', 'wink-wp-gatekeeper' ); ?></h3>
			<p class="wk-muted"><?php esc_html_e( 'Incidents from all four gates and every provider share one timeline; a provider\'s same-day pause and next-day recovery are logged here too.', 'wink-wp-gatekeeper' ); ?></p>
			<?php if ( array() === $fault_rows ) : ?>
				<p class="wk-muted"><?php esc_html_e( 'No recent errors — all is well.', 'wink-wp-gatekeeper' ); ?></p>
			<?php else : ?>
				<table class="wk-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Time', 'wink-wp-gatekeeper' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Event No.', 'wink-wp-gatekeeper' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Which gate', 'wink-wp-gatekeeper' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Which provider', 'wink-wp-gatekeeper' ); ?></th>
							<th scope="col"><?php esc_html_e( 'What went wrong', 'wink-wp-gatekeeper' ); ?></th>
							<th scope="col"><?php esc_html_e( 'How it was handled', 'wink-wp-gatekeeper' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $fault_rows as $fault_row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $fault_row['created_at'] ); ?></td>
								<td><code><?php echo esc_html( (string) $fault_row['event_no'] ); ?></code></td>
								<td><?php echo esc_html( (string) $fault_row['gate_word'] ); ?></td>
								<td><?php echo esc_html( (string) $fault_row['driver_label'] ); // 服务商名恒中性不着色（四改一——着色只落原因档位）。 ?></td>
								<td>
									<?php /* 【W3·批B·四改一】reason-tag 着色纪律：原因按 severity 档着色
									 *（Labels::fault_reason_severity 纯函数映射 red|amber|green|dim——
									 * 原型 tab6 样张：服务商名中性、原因着色）。 */ ?>
									<i class="reason-tag reason-tag--<?php echo esc_attr( \WinkGatekeeper\Stats\Labels::fault_reason_severity( (string) $fault_row['rule_hit'] ) ); ?>"><?php echo esc_html( (string) $fault_row['rule_word'] ); ?></i>
									<?php $fault_brief = trim( ( isset( $fault_row['raw']['code'] ) && is_string( $fault_row['raw']['code'] ) ? $fault_row['raw']['code'] : '' )
										. ' ' . ( isset( $fault_row['raw']['message'] ) && is_string( $fault_row['raw']['message'] ) ? $fault_row['raw']['message'] : '' ) ); ?>
									<?php if ( '' !== $fault_brief ) : ?>
										<span class="wk-muted">（<?php echo esc_html( mb_substr( $fault_brief, 0, 120, 'UTF-8' ) ); ?>）</span>
									<?php endif; ?>
									<?php if ( array() !== $fault_row['raw'] ) : ?>
										<details class="wk-fault-raw">
											<summary><?php esc_html_e( 'Expand to see the provider\'s raw reply (for troubleshooting, redacted)', 'wink-wp-gatekeeper' ); ?></summary>
											<pre><?php echo esc_textarea( (string) wp_json_encode( $fault_row['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre>
										</details>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( (string) $fault_row['handling'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * 四、拦截记录流水（占位——去 roadmap 人话化，P6 修复；事件流列表实装后回填链接）。
	 *
	 * @return void
	 */
	private static function render_stats_records_card(): void {
		// 【W3·批D·N8】空数据回跳标志（handle_export_csv 当日零记录时带此参数回来——
		// 黄条「有话才出现」；只读展示无写操作，nonce 校验在导出端点内）。
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读黄条标志展示。
		$export_empty = isset( $_GET['wk-export-empty'] ) && '1' === sanitize_key( wp_unslash( (string) $_GET['wk-export-empty'] ) );
		?>
		<section class="wk-card" id="wk-stats-records">
			<h2 class="wk-sec-title"><?php esc_html_e( 'Block record ledger (browse all records / export)', 'wink-wp-gatekeeper' ); ?></h2>
			<?php if ( $export_empty ) : ?>
				<?php self::notice_yellow_bar( 'warn', '', esc_html__( 'No block records today — no export file was generated; export today\'s ledger once there are records.', 'wink-wp-gatekeeper' ) ); ?>
			<?php endif; ?>
			<p class="wk-muted"><?php esc_html_e( 'Every block, manual review, and error has a number (e.g. WK-20261112-0042). When a visitor reports a problem, the number pinpoints it.', 'wink-wp-gatekeeper' ); ?></p>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_EXPORT_CSV ), self::ACTION_EXPORT_CSV ) ); ?>"><?php esc_html_e( 'Export today\'s block ledger (CSV)', 'wink-wp-gatekeeper' ); ?></a>
			</p>
			<p class="description"><?php esc_html_e( 'The export contains only today\'s blocked records: time / result / gate / reason / event number — no accounts, emails, comment bodies, or IPs (privacy-minimized, same policy as the login ledger).', 'wink-wp-gatekeeper' ); ?></p>
		</section>
		<?php
	}

	/**
	 * 五、统计设置 · 清零（统计开关三项迁入本卡垫底——P7 修复；单一大 form 迁位零表单风险，
	 * agent C F18；重置文案去「对账口径」黑话——P10 修复；AJAX 锚 id 保留）。
	 *
	 * @param array<string, mixed> $settings 设置树。
	 * @return void
	 */
	private static function render_stats_settings_card( array $settings ): void {
		?>
		<section class="wk-card" id="wk-stats-settings">
			<h2 class="wk-sec-title"><?php esc_html_e( 'Statistics settings · Reset', 'wink-wp-gatekeeper' ); ?></h2>
			<p>
				<label>
					<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'stats', 'enabled' ) ) ); ?>" value="0" />
					<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'stats', 'enabled' ) ) ); ?>" value="1" <?php checked( ! empty( $settings['stats']['enabled'] ) ); ?> />
					<?php esc_html_e( 'Enable statistics counting (turned off, the numbers on this page stop growing while blocking continues)', 'wink-wp-gatekeeper' ); ?>
				</label>
			</p>
			<p>
				<label>
					<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'stats', 'raw_capture' ) ) ); ?>" value="0" />
					<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'stats', 'raw_capture' ) ) ); ?>" value="1" <?php checked( ! empty( $settings['stats']['raw_capture'] ) ); ?> />
					<?php esc_html_e( 'Record providers\' raw replies (for troubleshooting; stores an extra chunk of the response on errors)', 'wink-wp-gatekeeper' ); ?>
				</label>
				<br />
				<label>
					<?php esc_html_e( 'Storage length cap (characters)', 'wink-wp-gatekeeper' ); ?>
					<input type="number" min="128" max="2048" name="<?php echo esc_attr( self::field_name( array( 'stats', 'raw_max_bytes' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $settings['stats']['raw_max_bytes'] ?? 512 ) ); ?>" class="small-text" />
					<span class="wk-muted"><?php esc_html_e( '(128–2048, default 512; body and error messages are truncated separately)', 'wink-wp-gatekeeper' ); ?></span>
				</label>
			</p>
			<p class="wk-muted"><?php echo esc_html( sprintf( /* translators: %s: 清零范围 */ __( '“Clear all statistics” will wipe %s — unrecoverable.', 'wink-wp-gatekeeper' ), \WinkGatekeeper\Stats\Labels::reset_scope_word() ) ); ?></p>
			<button type="button" class="button button-danger" id="wk-stats-reset"
				data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX_RESET_STATS ) ); ?>"
				data-confirm="<?php echo esc_attr__( 'Confirm the wipe? All statistics and block records will be cleared, unrecoverable.', 'wink-wp-gatekeeper' ); ?>">
				<?php esc_html_e( 'Clear all statistics', 'wink-wp-gatekeeper' ); ?>
			</button>
			<span id="wk-stats-reset-result" aria-live="polite"></span>
		</section>
		<?php
	}

	/**
	 * Tab⑥ 拦截记录流水「导出 CSV」处理器（admin_post_{self::ACTION_EXPORT_CSV}；
	 * 【W3·批D·N8】施工图 R6——全图唯一实锤空接线收口，钮与端点同 commit）。
	 *
	 * manage_options + check_admin_referer 双闸（GET 链接 wp_nonce_url——die_preview/
	 * wizard 出口同形态，03 §1 契约④全件）。数据=**当日**拦截记录（只读 SELECT，
	 * EventsTable::today_intercept_rows——recent_* 同族当日窗体），行组装
	 * InterceptDigest::export_csv_rows 纯逻辑层五列闭集（时间/结果/哪张门/原因/
	 * 事件编号——零明文账号/邮箱/评论正文/零 IP，与登录流水表 Q3 同口径 PIPL 红线，
	 * 复核注记在按钮侧 description）。fputcsv 流式输出 text/csv attachment
	 * （filename=wink-intercepts-YYYY-MM-DD.csv——InterceptDigest::export_csv_filename
	 * 纯函数件；UTF-8 BOM 供 Excel 打开中文表头不乱码）；当日零记录=黄条提示不输出
	 * 文件（wp_safe_redirect 回 Tab⑥ 带 wk-export-empty 标志）。**零计量零写**。
	 *
	 * @return void
	 */
	public static function handle_export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wink-wp-gatekeeper' ), 403 );
		}
		check_admin_referer( self::ACTION_EXPORT_CSV );

		$today = current_time( 'Y-m-d' );
		$rows  = \WinkGatekeeper\Stats\EventsTable::today_intercept_rows( $today );

		if ( 0 === count( $rows ) ) {
			// 当日零记录：不输出空文件，回 Tab⑥ 黄条提示（有话才出现——notice 纪律）。
			wp_safe_redirect(
				add_query_arg(
					array( 'page' => self::PAGE_SLUG, 'tab' => 'stats', 'wk-export-empty' => '1' ),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}

		// 流式输出（逐行 fputcsv——零全量驻留；表头闭集 InterceptDigest::EXPORT_CSV_COLUMNS）。
		$filename = \WinkGatekeeper\Stats\InterceptDigest::export_csv_filename( $today );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			wp_die( esc_html__( 'Failed to open the export stream.', 'wink-wp-gatekeeper' ), 500 );
		}
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM（Excel 中文表头兼容）。
		// i18n 批 I-3：CSV 列头经 __() 翻译（msgid 皆既有键，pot 由字面量位点与 catalog 管线承载）。
		fputcsv( $out, array_map( static function ( string $column ): string {
			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- const 列头闭集值=en 键（PHP 常量表达式禁函数调用）。
			return __( $column, 'wink-wp-gatekeeper' );
		}, \WinkGatekeeper\Stats\InterceptDigest::EXPORT_CSV_COLUMNS ) );
		foreach ( \WinkGatekeeper\Stats\InterceptDigest::export_csv_rows( $rows ) as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out );
		exit;
	}

	/* -------------------------------------------------------------------
	 * Tab⑥ 驱动统一卡组（DESIGN-UI-tab6 §3——评论/登录两段复用同一模板）
	 * ------------------------------------------------------------------ */

	/**
	 * 服务商统一卡组数据组装（WP 边界：registry/设置树/Usage/DriverHealth 读 → 纯函数入参）。
	 *
	 * 排班态推导（agent C E-b 方案甲）：captcha=设置 captcha.driver 选中；review=在
	 * cloud.chain 中（含 failover 位——备用位会真实出数）。登录段只列验证码类；
	 * 登录域净口径（F3/E-c）：动作数=captcha_pass+captcha_reject、放行=直读 captcha_pass。
	 *
	 * 【UI 批 2】出错格接线（A-5/N6+N7a）：事件表故障口径按驱动聚合——
	 *   评论卡=全闸聚合取大（事件真值 ∪ by_driver.error——补 captcha 阶段以 reject 落库的远端故障）；
	 *   登录卡=三闸分域聚合（N7a——by_driver.error 全闸混合不可分域，F3）。
	 *
	 * @param string $context comment|login。
	 * @return array<int, array<string, mixed>> InterceptDigest::driver_card 输入组。
	 */
	private static function stats_driver_cards( string $context ): array {
		$settings = self::settings();
		$cache    = \WinkGatekeeper\Stats\StatsCache::get();
		$usage    = \WinkGatekeeper\Stats\Usage::get();
		$registry = null !== self::$driver_registry ? self::$driver_registry : null;
		if ( null === $registry ) {
			return array();
		}

		$captcha_on    = is_string( $settings['captcha']['driver'] ?? null ) ? (string) $settings['captcha']['driver'] : '';
		$cloud         = is_array( $settings['review']['cloud'] ?? null ) ? $settings['review']['cloud'] : array();
		$chain         = is_array( $cloud['chain'] ?? null ) ? $cloud['chain'] : array();
		$cloud_enabled = ! empty( $cloud['enabled'] );
		/* 【v1.7·M3-IP·定稿 §9】IP 类驱动入服务商统一卡（type='ip'——卡=iprep.<id> 子树，
		 * 启用=iprep.enabled；!enabled=蓝点未启用占位卡（已配置未启用态如实呈现）。 */
		$iprep         = is_array( $settings['iprep'] ?? null ) ? $settings['iprep'] : array();

		// 出错格数据源（N6/N7a——事件表真值；登录卡分域过滤 ACCOUNT_GATES）。
		$fault_all       = \WinkGatekeeper\Stats\EventsTable::today_fault_by_driver();
		$fault_in_account = \WinkGatekeeper\Stats\EventsTable::today_fault_by_driver( \WinkGatekeeper\Stats\InterceptDigest::ACCOUNT_GATES );

		$types = 'login' === $context ? array( 'captcha' ) : array( 'captcha', 'review', 'ip' );
		$cards = array();
		foreach ( $types as $type ) {
			foreach ( $registry->by_type( $type ) as $driver_id => $meta ) {
				$driver_id = (string) $driver_id;
				// 本地规则在评论段走三数式专卡（不花钱、无外部故障面）——不入服务商卡组。
				if ( 'local_rules' === $driver_id ) {
					continue;
				}
				$label   = is_string( $meta['label'] ?? null ) ? $meta['label'] : $driver_id;
				$enabled = 'captcha' === $type
					? ( '' !== $captcha_on && $captcha_on === $driver_id )
					: ( 'ip' === $type
						? ! empty( $iprep['enabled'] )
						: ( $cloud_enabled && in_array( $driver_id, $chain, true ) ) );
				$halt    = DriverHealth::state_today( $driver_id );
				$today   = \WinkGatekeeper\Stats\InterceptDigest::driver_counts( $cache, $driver_id, false );
				$total   = \WinkGatekeeper\Stats\InterceptDigest::driver_counts( $cache, $driver_id, true );

				if ( 'login' === $context ) {
					$passed = (int) ( $today['captcha_pass'] ?? 0 );
					// 输入经 InterceptDigest::driver_card 纯函数组装（渲染层只消费其输出形态）。
					$cards[] = \WinkGatekeeper\Stats\InterceptDigest::driver_card( array(
						'label'      => $label,
						'type'       => $type,
						'context'    => $context,
						'enabled'    => $enabled,
						'halt_state' => $halt,
						'used'       => $passed + (int) ( $today['captcha_reject'] ?? 0 ) + (int) ( $today['error'] ?? 0 ),
						'passed'     => $passed,
						'rejected'   => (int) ( $today['captcha_reject'] ?? 0 ),
						'review'     => null, // 登录族无待审——格隐藏。
						'errors'     => (int) ( $fault_in_account[ $driver_id ] ?? 0 ), // N7a 分域出错（事件表真值）。
						'quota'      => 0,
						'total'      => array(
							'passed'   => (int) ( $total['captcha_pass'] ?? 0 ),
							'rejected' => (int) ( $total['captcha_reject'] ?? 0 ),
							'review'   => null,
							'errors'   => null, // 累计分域出错不在本批查询面——格省略（不虚构）。
						),
						'config_tab' => 'captcha',
					) );
					continue;
				}

				$config = 'ip' === $type && is_array( $iprep[ $driver_id ] ?? null )
					? $iprep[ $driver_id ]
					: ( 'review' === $type && is_array( $cloud[ $driver_id ] ?? null ) ? $cloud[ $driver_id ] : array() );
				$cards[] = \WinkGatekeeper\Stats\InterceptDigest::driver_card( array(
					'label'      => $label,
					'type'       => $type,
					'context'    => $context,
					'enabled'    => $enabled,
					'halt_state' => $halt,
					'used'       => (int) \WinkGatekeeper\Stats\Usage::daily_total_for( $usage, $driver_id ),
					'passed'     => null, // 减法推导（D4）。
					'rejected'   => (int) ( $today['reject'] ?? 0 ),
					'review'     => (int) ( $today['review'] ?? 0 ),
					'errors'     => max( (int) ( $fault_all[ $driver_id ] ?? 0 ), (int) ( $today['error'] ?? 0 ) ), // N6 事件真值 ∪ 计数缓存取大。
					'quota'      => (int) ( $config['daily_quota'] ?? 0 ),
					'total'      => array(
						'passed'   => null, // 累计动作数 Usage 不留存——放行格省略（不虚构）。
						'rejected' => (int) ( $total['reject'] ?? 0 ),
						'review'   => (int) ( $total['review'] ?? 0 ),
						'errors'   => (int) ( $total['error'] ?? 0 ),
					),
					'config_tab' => 'captcha' === $type ? 'captcha' : ( 'ip' === $type ? 'iprep' : 'review' ),
				) );
			}
		}
		return $cards;
	}

	/**
	 * 段级配置指引（UI-FIX B②——2026-11-12 用户反馈「指引只在段级小标题位置出现一次」）。
	 *
	 * 段内驱动卡存在未启用卡时，段标题尾部追加「 · 去配置」链接（形如「登录防护 ·
	 * 去配置」）；目标=首个未启用卡的 config_tab（卡组序=captcha 在前——混合域时
	 * 指向第一道防线）。全部启用返回空串（无卡段不调用）。
	 *
	 * @param array<int, array<string, mixed>> $cards stats_driver_cards 输出。
	 * @return string HTML 片段（''=无指引；esc_url/esc_html 全转义）。
	 */
	private static function stats_config_hint( array $cards ): string {
		foreach ( $cards as $hint_card ) {
			if ( empty( $hint_card['enabled'] ) && '' !== (string) ( $hint_card['config_tab'] ?? '' ) ) {
				return ' · <a class="wk-config-link" href="' . esc_url( self::tab_url( (string) $hint_card['config_tab'] ) ) . '">'
					. esc_html( __( 'Configure', 'wink-wp-gatekeeper' ) ) . '</a>';
			}
		}
		return '';
	}

	/**
	 * 服务商统一卡渲染（头=服务商名+四档健康徽标；行=箭头式；额度行；未启用灰卡
	 * 极简一句无链接——UI-FIX B②：跳转指引收敛段标题单入口，卡不承担导航职责）。
	 *
	 * @param array<string, mixed> $card InterceptDigest::driver_card 输出。
	 * @return void
	 */
	private static function render_driver_card( array $card ): void {
		$label = (string) ( $card['label'] ?? '' );
		$dot   = (string) ( $card['dot_class'] ?? 'wk-dot--ok' ); // 【v1.5·FIX】彩色状态点（用户三色体系：绿启用/蓝待命/红故障/黄暂停）。
		if ( empty( $card['enabled'] ) ) {
			// 未启用占位卡（蓝点=已配置未启用；极简一句，无链接）。
			echo '<div class="wk-driver-card wk-driver-card--disabled">';
			echo '<p><strong>' . esc_html( $label ) . '</strong> <span class="' . esc_attr( $dot ) . ' wk-dot"></span> ' . esc_html( (string) $card['health'] ) . '</p>';
			echo '<p class="wk-muted">' . esc_html( (string) $card['placeholder_note'] ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<div class="wk-driver-card">';
		echo '<p><strong>' . esc_html( $label ) . '</strong> <span class="wk-driver-badge"><span class="' . esc_attr( $dot ) . ' wk-dot"></span> ' . esc_html( (string) $card['health'] ) . '</span></p>';
		foreach ( (array) ( $card['lines'] ?? array() ) as $line ) {
			echo '<p>' . esc_html( (string) $line ) . '</p>';
		}
		foreach ( (array) ( $card['total_lines'] ?? array() ) as $line ) {
			echo '<p class="wk-muted">' . esc_html( (string) $line ) . '</p>';
		}
		if ( null !== ( $card['quota_line'] ?? null ) ) {
			echo '<p class="wk-muted">' . esc_html( (string) $card['quota_line'] ) . '</p>';
		}
		if ( '' !== (string) ( $card['health_note'] ?? '' ) ) {
			echo '<p class="wk-muted">' . esc_html( (string) $card['health_note'] ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * 「最近拦下的评论」明细表（v1.3 卡体保留、迁入口径一③——P3 修复；列头人话化，
	 * 内嵌云端错误子表迁出为口径三——P3/P8 修复）。
	 *
	 * @return void
	 */
	private static function render_comment_recent_table(): void {
		$events = \WinkGatekeeper\Stats\EventsTable::recent_comment_rejects( \WinkGatekeeper\Stats\InterceptDigest::RECENT_LIMIT );

		// comment_id 直查 wp_comments（get_comment 任意状态：站长可能已恢复/删除留档）。
		$comments_by_id = array();
		foreach ( $events as $event ) {
			$meta = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
			$cid  = isset( $meta['comment_id'] ) && is_int( $meta['comment_id'] ) ? $meta['comment_id'] : null;
			if ( null === $cid || isset( $comments_by_id[ $cid ] ) ) {
				continue;
			}
			$comment = get_comment( $cid );
			$comments_by_id[ $cid ] = null === $comment ? null : array(
				'comment_author'       => (string) $comment->comment_author,
				'comment_author_email' => (string) $comment->comment_author_email,
				'comment_content'      => (string) $comment->comment_content,
			);
		}

		$rows = \WinkGatekeeper\Stats\InterceptDigest::rows( $events, $comments_by_id );
		?>
		<p class="wk-muted"><?php esc_html_e( 'Nicknames/emails are masked here; see the archive link for the originals. Bot probes (fake-submit detection) leave no comment and never enter this table.', 'wink-wp-gatekeeper' ); ?></p>
		<?php if ( array() === $rows ) : ?>
			<p class="wk-muted"><?php esc_html_e( 'No comments blocked recently — that is a good state.', 'wink-wp-gatekeeper' ); ?></p>
		<?php else : ?>
			<table class="wk-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Time', 'wink-wp-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Event No.', 'wink-wp-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Which line blocked it', 'wink-wp-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Reason', 'wink-wp-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Nickname', 'wink-wp-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Email', 'wink-wp-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( 'What was said', 'wink-wp-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Archive', 'wink-wp-gatekeeper' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
							<td><code><?php echo esc_html( (string) $row['event_no'] ); ?></code></td>
							<td><?php echo esc_html( \WinkGatekeeper\Stats\Labels::stage_word( (string) $row['stage'] ) ); ?></td>
							<td><?php echo esc_html( \WinkGatekeeper\Stats\Labels::rule_hit_word( (string) $row['rule_hit'] ) ); ?></td>
							<td><?php echo esc_html( (string) $row['author'] ); ?></td>
							<td><?php echo esc_html( (string) $row['email'] ); ?></td>
							<td><?php echo esc_html( (string) $row['excerpt'] ); ?></td>
							<td>
								<?php if ( null !== $row['edit_url'] ) : ?>
									<a href="<?php echo esc_url( admin_url( (string) $row['edit_url'] ) ); ?>"><?php esc_html_e( 'View archive', 'wink-wp-gatekeeper' ); ?></a>
								<?php elseif ( null !== $row['comment_id'] ) : ?>
									<span class="wk-muted">#<?php echo esc_html( (string) $row['comment_id'] ); ?><?php esc_html_e( '(comment deleted)', 'wink-wp-gatekeeper' ); ?></span>
								<?php else : ?>
									<span class="wk-muted"><?php esc_html_e( 'Not archived', 'wink-wp-gatekeeper' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
			<p class="wk-table-foot">
				<a class="button" href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=spam' ) ); ?>"><?php esc_html_e( 'View the Spam category', 'wink-wp-gatekeeper' ); ?></a>
			</p>
		<?php
	}

	/**
	 * 统计重置 AJAX 处理器（03 §6 后台操作安全约定：manage_options + check_ajax_referer）。
	 *
	 * @return void
	 */
	public static function ajax_reset_stats(): void {
		check_ajax_referer( self::AJAX_RESET_STATS, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions: manage_options required.', 'wink-wp-gatekeeper' ) ), 403 );
		}

		\WinkGatekeeper\Stats\StatsCache::reset_all();

		wp_send_json_success( array( 'message' => __( 'Statistics reset.', 'wink-wp-gatekeeper' ) ) );
	}

	/* -------------------------------------------------------------------
	 * Tab⑦ 工具（M0 骨架）
	 * ------------------------------------------------------------------ */

	/**
	 * 未实装云端审核驱动（下拉可见但不可用——03 Tab③ v1.4：DeepSeek 等 M3/M4 驱动
	 * 带里程碑后缀；required 字段名列表供状态点派生）。
	 *
	 * 【M6·批H2·H2-1】private→public：未来驱动显示名单一来源公开件（Bootstrap
	 * $driver_labels 同源注入——Tab③ 下拉与仪表盘调用量卡行名同表；openai_custom
	 * 无 registry_entry=实例态驱动，不发明第二映射源）。
	 *
	 * @return array<string, array{label: string, milestone: string, required: string[]}>
	 */
	public static function future_review_drivers(): array {
		return array(
			'moli' => array( 'label' => __( 'Moli System One', 'wink-wp-gatekeeper' ), 'milestone' => 'M4', 'required' => array( 'api_key' ) ),
			// openai_custom 已于 M6·批E 实装——登记入 registry，脱离 future 段（下拉已实装驱动）。
			// deepseek 已于 M5 U4 批实装——同上。
			// aliyun_green 已于 M3' 批次 3（B5）实装——同上。
			// baidu 已于 M3' 轻量批（B6）实装——同上。
		);
	}

	private static function render_tab_tools(): void {
		?>
		<section class="wk-card" id="wk-tools-existing-audit-card">
			<h2><?php esc_html_e( 'Existing comment audit', 'wink-wp-gatekeeper' ); ?></h2>
			<?php
			/* 【M6·批H4·H4-2】状态取值+运行中黄条上移卡顶（h2 后第一元素——与网址核查
			 * 黄条同位；手测二轮⑫打回：原在中部状态行位观感割裂）。 */
			$worker  = self::audit_worker();
			$prog    = $worker->status();
			$st      = $prog['status'] ?? 'idle';
			$line    = '';
			$actions = array();
			// 【M5 修复批·C4】idle 态发起钮前置闸：复用 has_driver（与 C1 start 守卫同一
			// 判定源）——无已配置云审驱动时不渲染「开始体检」，替换为黄条引导去 Tab③。
			$has_driver = \WinkGatekeeper\Audit\ExistingAuditWorker::has_driver( self::settings() );
			if ( 'running' === $st || 'quota_paused' === $st ) {
				// 【M5 修复批·C4】driver 空串（历史半残任务/驱动解析失败）→ 人话显示，
				// 不再出现「当前服务商：-」裸占位。
				$driver_id = (string) ( $prog['driver'] ?? '' );
				if ( '' === $driver_id ) {
					$driver_id = __( 'Not configured (no provider configured — submissions cannot be sent for review)', 'wink-wp-gatekeeper' );
				}
				$line = sprintf(
					/* translators: 1: processed 2: total 3: driver id */
					__( 'In progress (%1$d / %2$d) · current provider: %3$s', 'wink-wp-gatekeeper' ),
					(int) ( $prog['processed'] ?? 0 ),
					(int) ( $prog['total'] ?? 0 ),
					esc_html( $driver_id )
				);
				$actions['pause'] = __( 'Pause', 'wink-wp-gatekeeper' );
				if ( 'quota_paused' === $st ) {
					$line .= ' ' . __( '— today\'s quota is used up; it auto-resumes tomorrow.', 'wink-wp-gatekeeper' );
				}
			} elseif ( 'paused' === $st ) {
				$line = sprintf(
					/* translators: 1: processed 2: total */
					__( 'Paused (%1$d / %2$d)', 'wink-wp-gatekeeper' ),
					(int) ( $prog['processed'] ?? 0 ),
					(int) ( $prog['total'] ?? 0 )
				);
				$actions['resume'] = __( 'Resume', 'wink-wp-gatekeeper' );
			} elseif ( 'completed' === $st ) {
				$line = sprintf(
					/* translators: 1: finished time */
					__( 'Last completed on %1$s', 'wink-wp-gatekeeper' ),
					esc_html( date_i18n( 'Y-m-d H:i', (int) ( $prog['finished_at'] ?? 0 ) ) )
				);
				$actions['reset'] = __( 'Re-run audit', 'wink-wp-gatekeeper' );
			} else {
				$line = __( 'No audit run yet', 'wink-wp-gatekeeper' );
				if ( $has_driver ) {
					$actions['start'] = __( 'Start audit', 'wink-wp-gatekeeper' );
				}
			}
			/* 【M6·批H3·H3-B】运行中黄条统一（手测反馈 B）：running/quota_paused 态
			 * 升级 notice-warning（与网址核查运行中黄条同形态）；paused/completed/idle
			 * 保持文本行（非运行态）。 */ ?>
			<?php if ( 'running' === $st || 'quota_paused' === $st ) : ?>
			<?php
			// 【W3·批B】迁段 notice_yellow_bar（H3-B 统一黄条位保持；$line 为编排位已转义组装——原样透传）。
			self::notice_yellow_bar( 'warn', '', $line, '', '', 'wk-tools-audit-running-bar' );
			?>
			<?php else : ?>
			<p id="wk-tools-audit-state-line" class="wk-muted"><?php echo esc_html( $line ); ?></p>
			<?php endif; ?>
			<?php if ( 'idle' === $st && ! $has_driver ) : ?>
			<?php
			// 【W3·批B】迁段 notice_yellow_bar（C4 发起守卫黄条——id 锚留编排位）。
			self::notice_yellow_bar(
				'warn',
				'',
				esc_html__( 'Cloud moderation is not configured yet — configure and enable a provider on Tab 3 first, then start the audit.', 'wink-wp-gatekeeper' ),
				'',
				'',
				'wk-tools-audit-no-driver-notice'
			);
			?>
			<?php endif; ?>
			<?php
			// 【W3·批B】⚙ 联动展示折叠（原型 tab7——送审范围/处置方式收折叠；纯说明零新键，
			// 送审红线注「昵称+正文」锚原样保留在编排体内）。
			self::advanced_fold(
				'id="wk-tools-audit-advanced"',
				__( 'Advanced notes (submission scope / handling)', 'wink-wp-gatekeeper' ),
				static function (): void {
					?>
					<p class="wk-muted"><?php esc_html_e( 'Hands all existing comments to the provider for another pass: violations go to Spam, unclear ones to pending review — both recoverable by hand; clean ones are left alone. The report appears just below this card.', 'wink-wp-gatekeeper' ); ?></p>
					<p class="wk-muted"><?php esc_html_e( 'Red line: the audit submits “nickname + body” only — emails and URLs never enter the submission (email-exclusion discipline; the URL check is a separate line).', 'wink-wp-gatekeeper' ); ?></p>
					<?php
				}
			);
			?>
			<p>
			<?php foreach ( $actions as $op => $label ) : ?>
				<?php
				$confirm = '';
				if ( 'start' === $op ) {
					$confirm = __( 'Submissions go to the currently enabled provider and consume per-call quota — start now? Violations go to Spam, unclear ones to pending review (both recoverable by hand).', 'wink-wp-gatekeeper' );
				} elseif ( 'reset' === $op ) {
					$confirm = __( 'Re-running the audit wipes the previous marks and starts over; processed results are voided — sure?', 'wink-wp-gatekeeper' );
				}
				?>
				<a class="button" <?php echo 'start' === $op ? 'id="wk-tools-audit-start-btn" ' : ''; ?>href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . constant( __CLASS__ . '::ACTION_AUDIT_' . strtoupper( $op ) ) ), constant( __CLASS__ . '::ACTION_AUDIT_' . strtoupper( $op ) ) ) ); ?>"
					<?php echo '' !== $confirm ? 'onclick="return confirm(\'' . esc_js( $confirm ) . '\');"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
			<?php if ( 'paused' === $st ) : ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_AUDIT_RESET ), self::ACTION_AUDIT_RESET ) ); ?>"
					onclick="return confirm('<?php echo esc_js( __( 'Re-running the audit wipes the previous marks and starts over; processed results are voided — sure?', 'wink-wp-gatekeeper' ) ); ?>');"><?php esc_html_e( 'Re-run audit', 'wink-wp-gatekeeper' ); ?></a>
			<?php endif; ?>
			</p>
		</section>
		<?php
		/* 【M6·批C·C-3】存量审核报告卡（迁址自 Tab⑥——用户 2026-11-16「⑥ 统计页面中
		 * 展示的消息已经够杂乱」；D6 分工更新：⑦ 发起+报告同处）。wk-overview-report
		 * 同款票面（SettingsPage render_overview_report 形态复用）：报告头部=服务商+发起/
		 * 完成时间；口径三行=处理口径（没问题/待审核/垃圾箱）；原因明细 details 折叠。
		 * 三态：空态/运行中（含暂停与配额暂停）/完成。渲染红线：原因=攻击者可控
		 * （AI 原文/驱动透传）——esc_html+截断（XSS）。 */
		$rep_worker = self::audit_worker();
		$rep_prog   = $rep_worker->status();
		$rep_data   = $rep_worker->report_data();
		$rep_view   = \WinkGatekeeper\Audit\ExistingAuditTask::report_view( is_array( $rep_prog ) ? $rep_prog : array(), is_array( $rep_data['counts'] ?? null ) ? $rep_data['counts'] : null );

		// 【W3·批A】迁段 report_card（视图组装留编排位——文案/锚 id/票面词/动作链接/
		// 原因行的 esc+截断全部不迁位，XSS 红线面照旧；helper 只负责结构形态）。
		$st_words = array(
			'running'      => _x( 'In progress', 'audit-running', 'wink-wp-gatekeeper' ),
			'paused'       => __( 'Paused', 'wink-wp-gatekeeper' ),
			'quota_paused' => __( 'Paused — today\'s quota is used up (auto-resumes tomorrow)', 'wink-wp-gatekeeper' ),
			'completed'    => __( 'Completed', 'wink-wp-gatekeeper' ),
		);
		$st_word      = $st_words[ (string) ( $rep_prog['status'] ?? 'idle' ) ] ?? (string) ( $rep_prog['status'] ?? 'idle' );
		$driver_label = '' !== $rep_view['driver'] ? $rep_view['driver'] : __( 'Not configured', 'wink-wp-gatekeeper' );
		$meta_line    = sprintf(
			/* translators: 1: 服务商 2: 发起时间 3: 状态词 */
			__( 'Provider: %1$s · started: %2$s · %3$s', 'wink-wp-gatekeeper' ),
			$driver_label,
			$rep_view['started_at'] > 0 ? date_i18n( 'Y-m-d H:i', $rep_view['started_at'] ) : '—',
			$st_word
		);
		if ( 'completed' === $rep_view['state'] && $rep_view['finished_at'] > 0 ) {
			$meta_line .= ' · ' . sprintf(
				/* translators: %s: 完成时间 */
				__( 'Completed: %s', 'wink-wp-gatekeeper' ),
				date_i18n( 'Y-m-d H:i', $rep_view['finished_at'] )
			);
		} elseif ( 'running' === $rep_view['state'] && $rep_view['total'] > 0 ) {
			$meta_line .= ' · ' . sprintf(
				/* translators: 1: processed 2: total */
				__( '%1$d of %2$d comments audited', 'wink-wp-gatekeeper' ),
				$rep_view['processed'],
				$rep_view['total']
			);
		}
		$recovery_line = esc_html__( 'Violations go to Spam, unclear ones to pending review — both recoverable by hand.', 'wink-wp-gatekeeper' );
		if ( $rep_view['caliber']['gone'] > 0 || $rep_view['caliber']['error'] > 0 ) {
			$recovery_line .= ' ' . esc_html( sprintf(
				/* translators: 1: 评论已删条数 2: 出错条数 */
				__( 'Also: %1$d comments deleted · %2$d errors.', 'wink-wp-gatekeeper' ),
				$rep_view['caliber']['gone'],
				$rep_view['caliber']['error']
			) );
		}
		$reasons = is_array( $rep_data['reasons'] ?? null ) ? $rep_data['reasons'] : array();
		if ( array() !== $reasons ) {
			$parts = array();
			foreach ( $reasons as $row ) {
				// 【M6·批H·H-8】截断展示补「…」省略号（单点件全消费面生效）；
				// 【M6·批H3·H3-C】行尾补处置去向（→ 垃圾箱/→ 待审核——去向词常量安全）。
				$parts[] = sprintf(
					/* translators: 1: 原因 2: 条数 3: 去向 */ _n( '“%1$s” %2$d item → %3$s', '“%1$s” %2$d items → %3$s', (int) $row['n'], 'wink-wp-gatekeeper' ),
					esc_html( self::truncate_reason( (string) $row['reason'] ) ),
					(int) $row['n'],
					'spam' === (string) ( $row['disposal'] ?? 'hold' ) ? __( 'Spam', 'wink-wp-gatekeeper' ) : __( 'Pending review', 'wink-wp-gatekeeper' )
				);
			}
			// XSS 红线：原因=攻击者可控（AI 原文/驱动透传）——转义+截断（编排在位）。
			$reasons_html = '<p>' . esc_html( implode( ' · ', $parts ) ) . '</p>';
		} else {
			$reasons_html = '<p class="wk-muted">' . esc_html__( 'No matches', 'wink-wp-gatekeeper' ) . '</p>';
		}
		self::report_card( array(
			'state'           => (string) $rep_view['state'],
			'section_id'      => 'wk-tools-existing-audit-report',
			'title'           => __( 'Existing-comment audit report', 'wink-wp-gatekeeper' ),
			'empty_id'        => 'wk-tools-existing-report-empty',
			'empty_text'      => __( 'No existing-comment audit yet — click “Start audit” above and the report will appear here.', 'wink-wp-gatekeeper' ),
			'meta_id'         => 'wk-tools-existing-report-meta',
			'meta_text'       => $meta_line,
			'tickets_class'   => 'wk-report-tickets',
			'caliber_id'      => 'wk-tools-existing-audit-caliber',
			'tickets'         => array(
				array(
					'name' => __( 'Clean (pass)', 'wink-wp-gatekeeper' ),
					'main' => sprintf( /* translators: %s: 条数 */ __( '%s items', 'wink-wp-gatekeeper' ), number_format_i18n( $rep_view['caliber']['pass'] ) ),
					'sub'  => __( 'Verified clean — comment status unchanged', 'wink-wp-gatekeeper' ),
				),
				array(
					'name' => __( 'Pending review (could not tell, no verdict)', 'wink-wp-gatekeeper' ),
					'main' => sprintf( /* translators: %s: 条数 */ __( '%s items', 'wink-wp-gatekeeper' ), number_format_i18n( $rep_view['caliber']['pending'] ) ),
					'sub'  => __( 'Sitting in the pending queue for your review', 'wink-wp-gatekeeper' ),
				),
				array(
					'name' => __( 'Spam (verdict: violation)', 'wink-wp-gatekeeper' ),
					'main' => sprintf( /* translators: %s: 条数 */ __( '%s items', 'wink-wp-gatekeeper' ), number_format_i18n( $rep_view['caliber']['spam'] ) ),
					'sub'  => __( 'Moved to Spam', 'wink-wp-gatekeeper' ),
				),
			),
			'actions_html'    => '<a href="' . esc_url( admin_url( 'edit-comments.php?comment_status=moderated' ) ) . '">'
				. esc_html( sprintf(
					/* translators: %d: 待审核条数 */
					_n( 'Handle the pending queue (%d item) →', 'Handle the pending queue (%d items) →', $rep_view['caliber']['pending'], 'wink-wp-gatekeeper' ),
					$rep_view['caliber']['pending']
				) )
				. '</a> · <a href="' . esc_url( admin_url( 'edit-comments.php?comment_status=spam' ) ) . '">'
				. esc_html( sprintf(
					/* translators: %d: 垃圾箱条数 */
					_n( 'View Spam (%d item) →', 'View Spam (%d items) →', $rep_view['caliber']['spam'], 'wink-wp-gatekeeper' ),
					$rep_view['caliber']['spam']
				) )
				. '</a>',
			'recovery_text'   => $recovery_line,
			'reasons_id'      => 'wk-tools-existing-audit-reasons',
			'reasons_summary' => sprintf(
				/* translators: %d: 原因类数 */
				__( 'Reason breakdown (%d kinds)', 'wink-wp-gatekeeper' ),
				count( $reasons )
			),
			'reasons_html'    => $reasons_html,
		) );
		/* 【M6·P1-3】存量网址核查操作卡（显式触发语义：开启网址核查本身**不**回捞历史
		 * ——站长点按钮才一次性入队，批量车道按日额度 50 自动推进、额度尽暂停黄条照旧）。
		 * 【M6·批C·C-2】处置人话补注（用户 2026-11-16 拍板）：判违规进垃圾箱、没看清进
		 * 待审，都能手动恢复。 */
		$url_audit_settings   = self::settings();
		$url_audit_on         = ! empty( $url_audit_settings['url_audit']['enabled'] );
		$backlog_worker       = new \WinkGatekeeper\UrlAudit\UrlAuditWorker( $url_audit_settings );
		$backlog_count        = $backlog_worker->batch_queue_count();
		/* 【M6·批C·C-3】点击反馈（F6：点击运行了但零展示零反馈）：入队回跳携带已入队数
		 * （wk-url-backlog）——立即呈现「已入队 N 条·开始核查」。 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读回跳状态渲染（无副作用；nonce 双闸在 handler）。
		$backlog_added = isset( $_GET['wk-url-backlog'] ) && is_scalar( $_GET['wk-url-backlog'] ) ? absint( (string) $_GET['wk-url-backlog'] ) : -1;
		/* 【M6·批H·H-7】运行中黄条数据源（自报告卡前置到操作卡——两卡同源三态）：
		 * state=running（运行记录未完成或队列非空）→ 操作卡头 notice-warning「存量网址
		 * 核查进行中…」；completed/empty 不渲染（完成/空队列熄灭——A-U3 有话才出现先例）。 */
		$url_view = $backlog_worker->backlog_report_data();
		?>
		<section class="wk-card" id="wk-tools-url-audit-card">
			<h2><?php esc_html_e( 'Existing URL check', 'wink-wp-gatekeeper' ); ?></h2>
			<?php if ( 'running' === $url_view['state'] ) : ?>
			<?php
			// 【W3·批B】迁段 notice_yellow_bar（批H-7 同源黄条——id 锚留编排位）。
			self::notice_yellow_bar(
				'warn',
				'',
				esc_html__( 'Existing URL check in progress… advances automatically within the daily quota (violations to Spam, unclear ones to pending review, both recoverable by hand); the report appears below when done.', 'wink-wp-gatekeeper' ),
				'',
				'',
				'wk-tools-url-audit-running-bar'
			);
			?>
			<?php endif; ?>
			<?php
			// 【W3·批B】⚙ 联动展示折叠（原型 tab7——每日推进额度/重查口径收折叠；额度与 robots
			// 真实键在 Tab③ url_audit 高级区，本卡为联动展示不另造键——零新键）。
			self::advanced_fold(
				'id="wk-tools-url-audit-advanced"',
				__( 'Advanced notes (daily advance quota / re-check policy)', 'wink-wp-gatekeeper' ),
				static function (): void {
					?>
					<p class="wk-muted"><?php esc_html_e( 'Hands every website URL in existing comments to the “URL check” for a full re-pass (open the page + AI verdict on whether the site is problematic). The everyday URL check only covers new comments — this button is for digging through history, with predictable cost.', 'wink-wp-gatekeeper' ); ?></p>
					<p class="wk-muted"><?php esc_html_e( 'Pauses automatically when the daily quota runs out (continues the next day): violations to Spam, unclear ones to pending review, both recoverable by hand.', 'wink-wp-gatekeeper' ); ?></p>
					<?php
				}
			);
			?>
			<?php /* 【M6·批H4·H4-3】未开启态抽独立黄条（手测二轮⑦附：提示族统一黄条形态）；
			 * 排队/空闲两态保留文本行。 */ ?>
			<?php if ( ! $url_audit_on ) : ?>
			<?php
			// 【W3·批B】迁段 notice_yellow_bar（H4-3 未开启独立黄条——id 锚留编排位）。
			self::notice_yellow_bar(
				'warn',
				'',
				esc_html__( 'The URL check is off — enable “URL check” on the “Cloud moderation” tab first, then come back to start the existing-URL check.', 'wink-wp-gatekeeper' ),
				'',
				'',
				'wk-tools-url-audit-off-notice'
			);
			?>
			<?php endif; ?>
			<p id="wk-tools-url-audit-state-line" class="wk-muted">
				<?php if ( ! $url_audit_on ) : ?>
					<?php esc_html_e( '(Once enabled, you can start the existing-URL check here.)', 'wink-wp-gatekeeper' ); ?>
				<?php elseif ( $backlog_count > 0 ) : ?>
					<?php
					printf(
						/* translators: %s: 待核查条数。 */
						esc_html( /* translators: %s: 待核查条数 */ _n( '%s URL queued for checking (advances automatically within the daily quota).', '%s URLs queued for checking (advances automatically within the daily quota).', $backlog_count, 'wink-wp-gatekeeper' ) ),
						esc_html( number_format_i18n( $backlog_count ) )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'No existing URLs waiting — the button below re-queues every approved comment that carries a URL.', 'wink-wp-gatekeeper' ); ?>
				<?php endif; ?>
			</p>
			<?php if ( $backlog_added >= 0 ) : ?>
			<p id="wk-tools-url-audit-feedback"><strong><?php
				echo esc_html( $backlog_added > 0
					? sprintf(
						/* translators: %s: 本次入队条数 */
						_n( '%s URL queued · checking starts (advances automatically within the daily quota; you can leave this page).', '%s URLs queued · checking starts (advances automatically within the daily quota; you can leave this page).', $backlog_added, 'wink-wp-gatekeeper' ),
						number_format_i18n( $backlog_added )
					)
					: __( 'No newly found existing URLs to check — they may already be queued.', 'wink-wp-gatekeeper' ) );
			?></strong></p>
			<?php endif; ?>
			<?php if ( $url_audit_on ) : ?>
			<p>
				<a class="button" id="wk-tools-url-audit-start-btn"
					href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . \WinkGatekeeper\UrlAudit\UrlAuditWorker::ACTION_BACKLOG ), \WinkGatekeeper\UrlAudit\UrlAuditWorker::ACTION_BACKLOG ) ); ?>"
					onclick="return confirm('<?php echo esc_js( __( 'Re-check everything? Historical results are ignored (site content may have changed), approved comments with URLs are re-queued, and checks proceed one by one within the daily quota (consuming quota). Violations go to Spam, unclear ones to pending review (both recoverable by hand).', 'wink-wp-gatekeeper' ) ); ?>');"><?php esc_html_e( 'Start the existing URL check (re-check everything — historical results ignored, site content may have changed)', 'wink-wp-gatekeeper' ); ?></a>
			</p>
			<?php endif; ?>
		</section>
		<?php
		/* 【M6·批C·C-3】存量网址核查报告卡（新增——操作卡下方，同 wk-overview-report
		 * 款式）：口径三行统计（没问题/待审核/垃圾箱）+起止时间+队列残留数。数据源=
		 * backlog 运行记录 option（入队时建 record：started_at+ids+enqueued）+渲染时
		 * meta 反查统计（tick 零写——施工图 §3b C-3 最小方案，报告落档说明）。
		 * 【M6·批H·H-7】$url_view 已前置（操作卡运行中黄条同源）——本卡直接消费。 */
		?>
		<section class="wk-card" id="wk-tools-url-audit-report">
			<h2><?php esc_html_e( 'Existing URL check report', 'wink-wp-gatekeeper' ); ?></h2>
			<?php if ( 'empty' === $url_view['state'] ) : ?>
				<p id="wk-tools-url-report-empty" class="wk-muted"><?php esc_html_e( 'No existing URL check has run yet — click “Start the existing URL check” above and the report will appear here.', 'wink-wp-gatekeeper' ); ?></p>
			<?php else : ?>
				<p id="wk-tools-url-report-meta" class="wk-muted"><?php
					if ( 'running' === $url_view['state'] ) {
						echo esc_html( sprintf(
							/* translators: 1: 发起时间 2: 已核查数 3: 共数 4: 队列残留数 */
							__( 'Started: %1$s · in progress — %2$d of %3$d checked · %4$d left in queue (advances automatically within the daily quota)', 'wink-wp-gatekeeper' ),
							$url_view['started_at'] > 0 ? date_i18n( 'Y-m-d H:i', $url_view['started_at'] ) : '—',
							$url_view['judged'],
							$url_view['enqueued'],
							$url_view['queue_residual']
						) );
					} else {
						echo esc_html( sprintf(
							/* translators: 1: 发起时间 2: 完成时间 3: 共数 */
							__( 'Started: %1$s · completed: %2$s · %3$d checked in total (this round fully wrapped up)', 'wink-wp-gatekeeper' ),
							$url_view['started_at'] > 0 ? date_i18n( 'Y-m-d H:i', $url_view['started_at'] ) : '—',
							$url_view['finished_at'] > 0 ? date_i18n( 'Y-m-d H:i', $url_view['finished_at'] ) : '—',
							$url_view['judged']
						) );
					}
				?></p>
				<div class="wk-report-tickets" id="wk-tools-url-audit-caliber">
					<div class="wk-report-ticket">
						<p class="wk-report-ticket__name"><strong><?php esc_html_e( 'Clean (pass)', 'wink-wp-gatekeeper' ); ?></strong></p>
						<p class="wk-report-ticket__main"><?php echo esc_html( sprintf( /* translators: %s: 条数 */ __( '%s items', 'wink-wp-gatekeeper' ), number_format_i18n( $url_view['caliber']['pass'] ) ) ); ?></p>
						<p class="wk-muted"><?php esc_html_e( 'Site verified clean — comment status unchanged', 'wink-wp-gatekeeper' ); ?></p>
					</div>
					<div class="wk-report-ticket">
						<p class="wk-report-ticket__name"><strong><?php esc_html_e( 'Pending review (could not tell, no verdict)', 'wink-wp-gatekeeper' ); ?></strong></p>
						<p class="wk-report-ticket__main"><?php echo esc_html( sprintf( /* translators: %s: 条数 */ __( '%s items', 'wink-wp-gatekeeper' ), number_format_i18n( $url_view['caliber']['pending'] ) ) ); ?></p>
						<p class="wk-muted"><?php esc_html_e( 'Sitting in the pending queue for your review', 'wink-wp-gatekeeper' ); ?></p>
					</div>
					<div class="wk-report-ticket">
						<p class="wk-report-ticket__name"><strong><?php esc_html_e( 'Spam (verdict: violation)', 'wink-wp-gatekeeper' ); ?></strong></p>
						<p class="wk-report-ticket__main"><?php echo esc_html( sprintf( /* translators: %s: 条数 */ __( '%s items', 'wink-wp-gatekeeper' ), number_format_i18n( $url_view['caliber']['spam'] ) ) ); ?></p>
						<p class="wk-muted"><?php esc_html_e( 'Moved to Spam', 'wink-wp-gatekeeper' ); ?></p>
					</div>
				</div>
				<p class="wk-overview-actions">
					<a href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=moderated' ) ); ?>"><?php
						echo esc_html( sprintf(
							/* translators: %d: 待审核条数 */
							_n( 'Handle the pending queue (%d item) →', 'Handle the pending queue (%d items) →', $url_view['caliber']['pending'], 'wink-wp-gatekeeper' ),
							$url_view['caliber']['pending']
						) );
					?></a>
					· <a href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=spam' ) ); ?>"><?php
						echo esc_html( sprintf(
							/* translators: %d: 垃圾箱条数 */
							_n( 'View Spam (%d item) →', 'View Spam (%d items) →', $url_view['caliber']['spam'], 'wink-wp-gatekeeper' ),
							$url_view['caliber']['spam']
						) );
					?></a>
				</p>
				<p class="wk-muted"><?php esc_html_e( 'Violations go to Spam, unclear ones to pending review — both recoverable by hand.', 'wink-wp-gatekeeper' ); ?><?php
					if ( $url_view['gone'] > 0 ) {
						echo ' ' . esc_html( sprintf(
							/* translators: %d: 评论已删条数 */
							_n( 'Also: %d comment deleted.', 'Also: %d comments deleted.', $url_view['gone'], 'wink-wp-gatekeeper' ),
							$url_view['gone']
						) );
					}
				?></p>
				<?php
				/* 【M6·批F-a·§4-add2】原因明细折叠（URL 报告——与存量审核报告同款式）：
				 * evidence=search 的原因行补人话标记「网址打不开，依据搜索线索判定」——
				 * 人工复核一眼可知「此判定基于搜索线索而非页面正文」。渲染红线：原因=
				 * 攻击者可控（AI 原文/第三方摘要透传）——单次 esc_html 收口+截断（XSS）。 */
				$url_reasons = is_array( $url_view['reasons'] ?? null ) ? $url_view['reasons'] : array();
				?>
				<details id="wk-tools-url-audit-reasons">
					<summary><?php echo esc_html( sprintf(
						/* translators: %d: 原因类数 */
						__( 'Reason breakdown (%d kinds)', 'wink-wp-gatekeeper' ),
						count( $url_reasons )
					) ); ?></summary>
					<?php if ( array() !== $url_reasons ) : ?>
						<p><?php
							$url_parts = array();
							foreach ( $url_reasons as $url_reason_row ) {
								$url_prefix = 'search' === (string) ( $url_reason_row['evidence'] ?? '' )
									? __( 'URL would not open; judged from search clues', 'wink-wp-gatekeeper' )
									: '';
							$url_parts[] = sprintf(
								/* translators: 1: 原因 2: 前缀 3: 条数 4: 去向 */ _n( '%2$s“%1$s” %3$d item → %4$s', '%2$s“%1$s” %3$d items → %4$s', (int) ( $url_reason_row['n'] ?? 0 ), 'wink-wp-gatekeeper' ),
								self::truncate_reason( (string) ( $url_reason_row['reason'] ?? '' ) ), // 【M6·批H·H-8】截断补「…」（单点件）。
								'' !== $url_prefix ? '【' . $url_prefix . '】' : '',
								(int) ( $url_reason_row['n'] ?? 0 ),
								'spam' === (string) ( $url_reason_row['disposal'] ?? 'hold' ) ? __( 'Spam', 'wink-wp-gatekeeper' ) : __( 'Pending review', 'wink-wp-gatekeeper' ) // 【M6·批H3·H3-C】行尾处置去向。
							);
							}
							echo esc_html( implode( ' · ', $url_parts ) ); // XSS 红线：单次转义收口。
						?></p>
					<?php else : ?>
						<p class="wk-muted"><?php esc_html_e( 'No matches', 'wink-wp-gatekeeper' ); ?></p>
					<?php endif; ?>
				</details>
				<?php
				/* 按网站归堆表随报告迁址（原 Tab⑥ 报告卡内 U5 批节——「重新核查此站」
				 * 站长举报误判入口保留，nw §4.3）。 */
				$host_rows = $backlog_worker->host_aggregates();
				if ( array() !== $host_rows ) :
					?>
				<details id="wk-tools-url-audit-hosts">
					<summary><?php esc_html_e( 'Grouped by site (URL check violations)', 'wink-wp-gatekeeper' ); ?></summary>
					<table class="widefat striped" style="max-width:640px;">
						<thead><tr><th><?php esc_html_e( 'Website', 'wink-wp-gatekeeper' ); ?></th><th><?php esc_html_e( 'Comments hit', 'wink-wp-gatekeeper' ); ?></th><th><?php esc_html_e( 'Violation', 'wink-wp-gatekeeper' ); ?></th><th><?php esc_html_e( 'Suggestion', 'wink-wp-gatekeeper' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $host_rows as $host_row ) : ?>
							<tr>
								<td><?php echo esc_html( mb_substr( (string) $host_row['host'], 0, 60 ) ); ?></td>
								<td><?php echo (int) $host_row['hits']; ?></td>
								<td><?php echo (int) $host_row['rejects']; ?></td>
								<td>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wink_gatekeeper_url_host_purge&host=' . rawurlencode( (string) $host_row['host'] ) ), 'wink_gatekeeper_url_host_purge' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Clear this site\'s URL check cache and re-check it?', 'wink-wp-gatekeeper' ) ); ?>');"><?php esc_html_e( 'Re-check this site', 'wink-wp-gatekeeper' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</details>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<section class="wk-card wk-card--ghost">
			<h2><?php esc_html_e( 'Import/export settings', 'wink-wp-gatekeeper' ); ?> <span class="wk-plan-tag"><?php esc_html_e( 'Planned · not yet built', 'wink-wp-gatekeeper' ); ?></span></h2>
			<p class="wk-muted"><?php esc_html_e( 'JSON export (keys excluded by default) / import validation (schema version check). Implemented in M4 (F27).', 'wink-wp-gatekeeper' ); ?></p>
		</section>
		<section class="wk-card wk-card--ghost">
			<h2><?php esc_html_e( 'Driver self-check and fallback drills', 'wink-wp-gatekeeper' ); ?> <span class="wk-plan-tag"><?php esc_html_e( 'Planned · not yet built', 'wink-wp-gatekeeper' ); ?></span></h2>
			<p class="wk-muted"><?php esc_html_e( 'Key trial calls, front-end JS reachability probes, real-IP self-check (CIDR validation + parsing preview), fallback drills. Implemented in M3/M4 (F28/F33).', 'wink-wp-gatekeeper' ); ?></p>
		</section>
		<section class="wk-card wk-card--ghost">
			<h2><?php esc_html_e( 'System info', 'wink-wp-gatekeeper' ); ?> <span class="wk-plan-tag"><?php esc_html_e( 'Planned · not yet built', 'wink-wp-gatekeeper' ); ?></span></h2>
			<p class="wk-muted"><?php esc_html_e( 'PHP/WP version requirement check card (following upstream baby_wp_check_requirements behavior).', 'wink-wp-gatekeeper' ); ?></p>
		</section>
		<?php
	}

	/* -------------------------------------------------------------------
	 * Tab⑧ 站点增强（【M5·UI-A】UI 设计块 1——U1「网站」字段两独立开关 +
	 * U2 头像国内镜像；两开关完全独立互不关联（定稿 §0.1 唯一权威）。
	 * 文案红线：A 行说明只讲收集不提 B、B 行只讲显示不提 A；「后台始终可见+
	 * 存量不删」背景常显说明只写卡内顶部一处（段级一次）。
	 * ------------------------------------------------------------------ */

	/**
	 * Tab⑧ 渲染（【W3·批B】七卡=原型 tab8 IA）：卡1 RSS（规划壳）/ 卡2 XML-RPC 管控
	 * （真实控件搬位+两规划行）/ 卡3 REST（规划壳）/ 卡4 UA·HTTP 头规则（规划壳）/
	 * 卡5 coexist 共存（两实键开关+两规划检测行）/ 卡6 评论「网站」字段 / 卡7 头像来源。
	 *
	 * @return void
	 */
	private static function render_tab_enhance(): void {
		$settings         = self::settings();
		$collect_enabled  = ! empty( $settings['comments']['site_field']['enabled'] );
		$link_enabled     = ! empty( $settings['comments']['site_link']['enabled'] );
		$avatar_source    = \WinkGatekeeper\Site\AvatarSources::normalize( $settings['avatar']['source'] ?? null );
		$avatar_custom    = (string) ( $settings['avatar']['custom_base'] ?? '' );
		/* A 关闭提示（条件显隐）：收集已关闭 **且** 网址核查特性已在场（url_audit 子树——
		 * U5 批落位后自动生效；特性不在场时「提示审核了一个不存在的功能」违反 F33 纪律
		 * （不用的代码路径=不工作的代码路径），本批零渲染）。UI 设计块 1.3 的「A=true
		 * 时显示」按定稿 §1 权威语义实施=**收集关闭时**（键语义 enabled=true=收集）。 */
		$url_audit_exists = array_key_exists( 'url_audit', $settings );
		?>
		<?php /* 【W3·批B】八 Tab 重组=原型 tab8 七卡信息架构：卡1 RSS（规划壳）/ 卡2 XML-RPC
		 * 管控（真实控件搬位——gates.login.xmlrpc_login 同键副本，Q4 代裁：双入口同键，保存
		 * 通道天然合并）/ 卡3 REST（规划壳）/ 卡4 UA·HTTP 头规则（规划壳）/ 卡5 coexist
		 * 共存（hCaptcha 两 yield 开关=实键新接线——schema SettingsSchema.php:433–436 +
		 * Sanitize.php:242–243 既有通道无渲染面；【W6-A】Akismet/Wordfence 两灰字规划行退役
		 * →统一兼容层 registry 闭集四目标驱动渲染）/ 卡6 网站字段 / 卡7 头像来源（原两卡
		 * 零重排——DOM 锚全保）。 */ ?>

		<section class="wk-card wk-card--ghost">
			<h2><?php esc_html_e( 'RSS protection', 'wink-wp-gatekeeper' ); ?> <span class="wk-plan-tag"><?php esc_html_e( 'Planned · not yet built', 'wink-wp-gatekeeper' ); ?></span></h2>
			<p class="wk-muted"><?php esc_html_e( 'Feed request controls such as RSS/Atom feed rate limiting and excerpting feeds (full text → excerpt) — all off by default, effective only when enabled. Placeholder: zero controls and zero behavior today; becomes a live card once implemented.', 'wink-wp-gatekeeper' ); ?></p>
		</section>

		<section class="wk-card" id="wk-xmlrpc-control-card">
			<h2><?php esc_html_e( 'XML-RPC controls', 'wink-wp-gatekeeper' ); ?> <span class="badge b-dim"><?php esc_html_e( 'Current controls moved over and extended from the Tab 2 login protection card', 'wink-wp-gatekeeper' ); ?></span></h2>
			<div class="switch-row">
				<?php /* 【W3·批B·Q4 代裁】gates.login.xmlrpc_login 同键副本（反选形态同款两处渲染
				 * 同 name——hidden 恒 1 勾选提交 0；Tab② 登录保护卡保留原行，本卡为管控中心副本；
				 * 两处渲染同 checked 态，后值覆盖前值=保存通道天然合并）。 */ ?>
				<?php $xmlrpc_login = isset( $settings['gates']['login']['xmlrpc_login'] ) ? (bool) $settings['gates']['login']['xmlrpc_login'] : false; ?>
				<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'gates', 'login', 'xmlrpc_login' ) ) ); ?>" value="1" />
				<input type="checkbox" class="sw" name="<?php echo esc_attr( self::field_name( array( 'gates', 'login', 'xmlrpc_login' ) ) ); ?>" value="0" <?php checked( false, $xmlrpc_login ); ?> />
				<span class="sw-label"><span class="sw-hd"><b><?php esc_html_e( 'Fully disable XML-RPC logins', 'wink-wp-gatekeeper' ); ?></b><span class="sw-tag"><?php esc_html_e( 'Recommended hardening', 'wink-wp-gatekeeper' ); ?></span></span>
					<span class="why"><?php esc_html_e( 'Login-type XML-RPC methods (wp.getUsersBlogs, credentials carried by system.multicall, etc.) get an across-the-board 405; publishers using blog-client tools should untick and save to re-enable. Same switch as the Tab 2 “Login protection” card (saving in either place takes effect).', 'wink-wp-gatekeeper' ); ?></span>
				</span>
			</div>
			<div class="switch-row">
				<span class="sw-label"><span class="sw-hd"><b><?php esc_html_e( 'Shut down XML-RPC entirely', 'wink-wp-gatekeeper' ); ?></b><span class="wk-plan-tag"><?php esc_html_e( 'Planned · not yet built', 'wink-wp-gatekeeper' ); ?></span></span>
					<span class="why"><?php esc_html_e( 'An across-the-board 405 including pingback and every authenticated method — more thorough than disabling logins only, for sites that never use XML-RPC. Placeholder: zero controls and zero behavior today.', 'wink-wp-gatekeeper' ); ?></span>
				</span>
			</div>
			<?php /* 【0.9.3 批 T8】pingback 行状态化：S3-F6 已于 0.9.2 无条件摘除
			 * pingback.ping / pingback.extensions.getPingbacks 两方法（Bootstrap.xmlrpc_methods
			 * 过滤器）——「Planned · not yet built」占位文案自 0.9.2 起失实，转状态行
			 * （badge b-ok + 状态 why 文案；无开关=恒开语义如实披露）。03 Tab⑧ 卡内说明
			 * 可补注项兑现；0.9.2 changelog 披露不变。 */ ?>
			<div class="switch-row">
				<span class="sw-label"><span class="sw-hd"><b><?php esc_html_e( 'Pingback off', 'wink-wp-gatekeeper' ); ?></b><span class="sw-tag"><?php esc_html_e( 'Anti-reflection abuse', 'wink-wp-gatekeeper' ); ?></span><span class="badge b-ok"><?php esc_html_e( 'Done at plugin layer', 'wink-wp-gatekeeper' ); ?></span></span>
					<span class="why"><?php esc_html_e( 'This plugin removes the pingback.ping and pingback.extensions.getPingbacks XML-RPC methods unconditionally — pingback needs no authentication, so the login switch above does not cover it. Always on with no switch; the anonymous pingback reflection surface is closed at the plugin layer.', 'wink-wp-gatekeeper' ); ?></span>
				</span>
			</div>
		</section>

		<section class="wk-card wk-card--ghost">
			<h2><?php esc_html_e( 'REST API controls', 'wink-wp-gatekeeper' ); ?> <span class="wk-plan-tag"><?php esc_html_e( 'Planned · not yet built', 'wink-wp-gatekeeper' ); ?></span></h2>
			<p class="wk-muted"><?php esc_html_e( 'Narrowing controls such as disabling unauthenticated user enumeration (/wp/v2/users) and counting REST attempts in the login gate\'s failure counter — trade-off statement: narrowing, not shutting down. Placeholder: zero controls and zero behavior today; becomes a live card once implemented.', 'wink-wp-gatekeeper' ); ?></p>
		</section>

		<section class="wk-card wk-card--ghost">
			<h2><?php esc_html_e( 'UA / HTTP header rules', 'wink-wp-gatekeeper' ); ?> <span class="wk-plan-tag"><?php esc_html_e( 'Planned · not yet built', 'wink-wp-gatekeeper' ); ?></span></h2>
			<p class="wk-muted"><?php esc_html_e( 'Custom allow/block rule rows by request headers (UA etc.) — division of labor with the Tab 4 IP reputation allowlist: these rules govern “what the request looks like”, that one governs “who the IP is”. Placeholder: zero controls and zero behavior today; becomes a live card once implemented.', 'wink-wp-gatekeeper' ); ?></p>
		</section>

		<section class="wk-card" id="wk-site-fields-card">
			<h2><?php esc_html_e( 'The comment “Website” field', 'wink-wp-gatekeeper' ); ?></h2>
			<p id="wk-site-fields-constant-note" class="wk-muted"><?php esc_html_e( 'Commenters may fill in their own website. Whatever the switches below say, the admin comments screen always shows this URL so you can identify visitors; stored URLs are never deleted.', 'wink-wp-gatekeeper' ); ?></p>

			<table class="form-table">
				<tr id="wk-site-field-collect-row">
					<th scope="row"><?php esc_html_e( 'Collect the website field on new comments', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="wink_gatekeeper_settings[comments][site_field][enabled]" value="0" />
							<input type="checkbox" name="wink_gatekeeper_settings[comments][site_field][enabled]" value="1" <?php checked( $collect_enabled ); ?>>
							<?php esc_html_e( 'A “Website” field appears on the comment form and new comments store the URL', 'wink-wp-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Unticked (default): the field never appears and any submitted URL is discarded — keeping spam-submit bots from probing it. Published comments are unaffected.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<?php if ( ! $collect_enabled && $url_audit_exists ) : ?>
				<tr>
					<td colspan="2" id="wk-site-field-a-notice">
						<?php
						// 【W3·批B】迁段 notice_yellow_bar（A 关闭条件黄条——url_audit 在场才渲染，
						// F33 纪律照守；id 锚留编排位）。
						self::notice_yellow_bar(
							'warn',
							'',
							esc_html__( 'Website-field collection is off — the URL check auto-disables for new comments; existing URLs can still be handled via “Existing URL check” on the tools page.', 'wink-wp-gatekeeper' )
						);
						?>
					</td>
				</tr>
				<?php endif; ?>
				<tr id="wk-site-field-delink-row">
					<th scope="row"><?php esc_html_e( 'Keep commenter names clickable on the front end', 'wink-wp-gatekeeper' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="wink_gatekeeper_settings[comments][site_link][enabled]" value="0" />
							<input type="checkbox" name="wink_gatekeeper_settings[comments][site_link][enabled]" value="1" <?php checked( $link_enabled ); ?>>
							<?php esc_html_e( 'Commenter names in front-end comment areas display as clickable links', 'wink-wp-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Unticked (default): only the name itself is shown. Names are always clickable for you in the admin.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
			</table>
		</section>

		<section class="wk-card" id="wk-avatar-card">
			<h2><?php esc_html_e( 'Commenter avatar source', 'wink-wp-gatekeeper' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="wk-avatar-source-select"><?php esc_html_e( 'Avatar source', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<select id="wk-avatar-source-select" name="wink_gatekeeper_settings[avatar][source]">
							<option value="official" <?php selected( $avatar_source, 'official' ); ?>><?php esc_html_e( 'Not configured (WordPress official source)', 'wink-wp-gatekeeper' ); ?></option>
							<option value="cravatar" <?php selected( $avatar_source, 'cravatar' ); ?>><?php esc_html_e( 'Cravatar (mainland mirror, officially compatible)', 'wink-wp-gatekeeper' ); ?></option>
							<option value="weavatar" <?php selected( $avatar_source, 'weavatar' ); ?>><?php esc_html_e( 'WeAvatar (mainland mirror, multi-level matching)', 'wink-wp-gatekeeper' ); ?></option>
							<option value="sepcc" <?php selected( $avatar_source, 'sepcc' ); ?>><?php esc_html_e( 'sep.cc (mainland mirror)', 'wink-wp-gatekeeper' ); ?></option>
							<option value="custom" <?php selected( $avatar_source, 'custom' ); ?>><?php esc_html_e( 'Custom URL', 'wink-wp-gatekeeper' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'The official source may load slowly or fail for mainland visitors; a mainland mirror speeds it up.', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr id="wk-avatar-custom-base-row"<?php echo 'custom' === $avatar_source ? '' : ' hidden'; ?>>
					<th scope="row"><label for="wk-avatar-custom-base"><?php esc_html_e( 'Custom URL', 'wink-wp-gatekeeper' ); ?></label></th>
					<td>
						<input type="url" id="wk-avatar-custom-base" name="wink_gatekeeper_settings[avatar][custom_base]" class="regular-text code" value="<?php echo esc_attr( $avatar_custom ); ?>" placeholder="https://example.com/avatar/">
						<p class="description"><?php esc_html_e( 'Must start with https:// and end with /avatar/ — follow the mirror\'s instructions; an invalid address leaves the avatar source unchanged (official source used).', 'wink-wp-gatekeeper' ); ?></p>
					</td>
				</tr>
			</table>
			<p id="wk-avatar-switch-note" class="wk-muted"><?php esc_html_e( 'After switching avatar sources, cached old avatars may take a while to refresh — not a malfunction.', 'wink-wp-gatekeeper' ); ?></p>
			<?php
			// 【W3·批B】测试迁 test_credential_btn（五态徽标「未测试」初态统一——测的是镜像通
			// 不通非密钥，AJAX_TEST_AVATAR 双闸/零计量语义不变；id 锚/nonce 字面量留编排位）。
			self::test_credential_btn(
				'id="wk-avatar-test-btn"',
				'id="wk-avatar-test-result"',
				wp_create_nonce( self::AJAX_TEST_AVATAR ),
				__( 'Test the current avatar source', 'wink-wp-gatekeeper' ),
				'avatar'
			);
			?>
		</section>
		<?php /* 【0.9.4 批 P】coexist 卡殿后=Tab⑧ 最后一栏（用户手测卡 W6 拍板 2026-11-21——
		 * 共存收尾；此前位次=卡5 在网站字段卡之前，W3TabRemodelTest 位次钉随批翻转）。 */ ?>
		<section class="wk-card" id="wk-coexist-card">
			<h2><?php esc_html_e( 'Coexistence with other security plugins', 'wink-wp-gatekeeper' ); ?> <span class="badge b-ok"><?php esc_html_e( 'Detect and yield · no fights', 'wink-wp-gatekeeper' ); ?></span></h2>
			<?php /* 【W3·批B】coexist 共存卡新接线：hCaptcha 两 yield 开关=实键（coexist 子树
			 * schema/sanitize 既有通道此前无渲染面——同键登记型新接线，默认开=现行行为一致：
			 * 闸侧 02 §3.4 让位逻辑已在场，本卡只补显隐口径）。 */ ?>
			<div class="switch-row">
				<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'coexist', 'hcaptcha_yield_comment' ) ) ); ?>" value="0" />
				<input type="checkbox" class="sw" name="<?php echo esc_attr( self::field_name( array( 'coexist', 'hcaptcha_yield_comment' ) ) ); ?>" value="1" <?php checked( ! isset( $settings['coexist']['hcaptcha_yield_comment'] ) || ! empty( $settings['coexist']['hcaptcha_yield_comment'] ) ); ?> />
				<span class="sw-label"><span class="sw-hd"><b><?php esc_html_e( 'hCaptcha detected handling comments', 'wink-wp-gatekeeper' ); ?></b><span class="sw-tag"><?php esc_html_e( 'Captcha yields', 'wink-wp-gatekeeper' ); ?></span></span>
					<span class="why"><?php esc_html_e( 'When the official hCaptcha plugin takes over comment captchas, this plugin\'s captcha yields (yellow-banner notice; login / registration / recovery stay guarded by this plugin). On by default.', 'wink-wp-gatekeeper' ); ?></span>
				</span>
			</div>
			<div class="switch-row">
				<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'coexist', 'hcaptcha_yield_login' ) ) ); ?>" value="0" />
				<input type="checkbox" class="sw" name="<?php echo esc_attr( self::field_name( array( 'coexist', 'hcaptcha_yield_login' ) ) ); ?>" value="1" <?php checked( ! isset( $settings['coexist']['hcaptcha_yield_login'] ) || ! empty( $settings['coexist']['hcaptcha_yield_login'] ) ); ?> />
				<span class="sw-label"><span class="sw-hd"><b><?php esc_html_e( 'hCaptcha detected handling logins', 'wink-wp-gatekeeper' ); ?></b><span class="sw-tag"><?php esc_html_e( 'Captcha yields', 'wink-wp-gatekeeper' ); ?></span></span>
					<span class="why"><?php esc_html_e( 'When the official hCaptcha plugin takes over the login captcha, this plugin\'s login captcha yields. On by default — independent of the comment-yield switch.', 'wink-wp-gatekeeper' ); ?></span>
				</span>
			</div>
			<?php /* 【W6-A】两灰字规划行退役→统一兼容层驱动渲染（docs/W6A-统一兼容层设计定稿-2026-11-21.md
			 * §10）：registry 单点闭集四目标行（Akismet/Wordfence/FluentComments/WP Armour）——探测到
			 * =badge b-ok「已检测 · 共存」+status 文案，未探测=badge b-dim「未安装」+absent 文案（不冒
			 * 充实装）；探测=渲染期现算现显（零缓存零落库——甲档 R3）；hCaptcha 两 yield 行为实键不进
			 * 层（定稿 §9 不迁移——DOM 锚原样）。 */ ?>
			<?php self::compat_detection_rows(); ?>
		</section>
		<?php
	}

	/**
	 * Tab⑧ 共存卡「统一兼容层」检测行渲染（【W6-A】）。
	 *
	 * 消费 \WinkGatekeeper\Compat\CompatRegistry 闭集（registry 单点登记——施工图
	 * docs/W6A-统一兼容层设计定稿-2026-11-21.md §3/§10；散点探测=设计失败）。
	 * 探测=渲染期现算现显（零缓存零落库——定稿 §4 R3）；探测到=badge b-ok
	 * 「已检测 · 共存」+status 文案，未探测=badge b-dim「未安装」+absent 文案
	 * （不冒充实装——R6）。hCaptcha 两 yield 行为实键不在此循环（定稿 §9 不迁移）。
	 *
	 * @return void
	 */
	private static function compat_detection_rows(): void {
		foreach ( \WinkGatekeeper\Compat\CompatRegistry::all() as $module ) {
			$detected = $module->detect();
			?>
			<div class="switch-row">
				<span class="sw-label"><span class="sw-hd"><b><?php echo esc_html( $module->label() ); ?></b><span class="sw-tag"><?php echo esc_html( $module->tag() ); ?></span><span class="<?php echo esc_attr( $detected ? 'badge b-ok' : 'badge b-dim' ); ?>"><?php echo esc_html( $detected ? __( 'Detected · coexists', 'wink-wp-gatekeeper' ) : __( 'Not installed', 'wink-wp-gatekeeper' ) ); ?></span></span>
					<span class="why"><?php echo esc_html( $detected ? $module->status_copy() : $module->absent_copy() ); ?></span>
				</span>
			</div>
			<?php
		}
	}

	/**
	 * Tab⑧ 卡二「测试当前头像来源」AJAX 处理器（【M5·U2】）。
	 *
	 * 语义：测的是**这个镜像通不通**（非密钥）；以表单当前所选 source/custom_base 为准
	 * （未保存也可测——与「配置与实测同域」D7 裁决一致）；custom 地址非法=红态提示不合法。
	 * 传输脊 RemoteClient（D13 纪律——全库唯一 HTTP 出口），不带 usage_key=零计量零写
	 * 零事件（诊断查询同口径）；固定 email hash、每源 1 请求（UI 设计块 1.3）。
	 *
	 * @return void
	 */
	public static function ajax_test_avatar_source(): void {
		check_ajax_referer( self::AJAX_TEST_AVATAR, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions: manage_options required.', 'wink-wp-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post   = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		$source = \WinkGatekeeper\Site\AvatarSources::normalize( isset( $post['source'] ) && is_scalar( $post['source'] ) ? (string) $post['source'] : '' );
		$custom = isset( $post['custom_base'] ) && is_scalar( $post['custom_base'] ) ? trim( (string) $post['custom_base'] ) : '';

		$base = \WinkGatekeeper\Site\AvatarSources::base_for( $source, $custom );
		if ( null === $base ) {
			if ( 'custom' === $source ) {
				// custom 但地址非法：给「缺参数」黄态（逐字段指出，同凭据测试黄态语义）。
				wp_send_json_success( array(
					'state'   => 'yellow',
					'message' => __( 'Invalid custom URL: it must start with https:// and end with /avatar/.', 'wink-wp-gatekeeper' ),
				) );
			}
			// official：探测官方源（对照形态——官方源慢时正好引导切镜像）。
			$base = 'https://secure.gravatar.com/avatar/';
		}

		$url = $base . self::AVATAR_PROBE_HASH . '?s=80&d=mm';
		$client = new RemoteClient(); // WP 边界默认传输（探测一次零计量）。
		$result = $client->get( $url, array( 'timeout' => 5 ) );

		$status  = $result->status();
		$headers = $result->headers();
		$ctype   = '';
		foreach ( array( 'content-type', 'Content-Type' ) as $key ) {
			if ( isset( $headers[ $key ] ) && is_string( $headers[ $key ] ) ) {
				$ctype = $headers[ $key ];
				break;
			}
		}
		$latency = $result->latency_ms();
		$seconds = null !== $latency ? ( $latency / 1000 ) : 0.0;
		$state   = \WinkGatekeeper\Site\AvatarSources::judge( $status, $ctype, $seconds );

		$messages = array(
			'ok'   => sprintf(
				/* translators: %s: 响应耗时秒。 */
				__( 'OK (%.1f s)', 'wink-wp-gatekeeper' ),
				$seconds
			),
			'slow' => sprintf(
				/* translators: %s: 响应耗时秒。 */
				__( 'Works but slow (%.1f s)', 'wink-wp-gatekeeper' ),
				$seconds
			),
			'fail' => __( 'Cannot reach this mirror (or the response is not an image).', 'wink-wp-gatekeeper' ),
		);

		wp_send_json_success( array(
			'state'   => $state,
			'message' => $messages[ $state ] ?? (string) __( 'Test failed.', 'wink-wp-gatekeeper' ),
			'source'  => $source,
		) );
	}
}
