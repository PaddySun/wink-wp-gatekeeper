<?php
/**
 * Wink 门口保安（wink-wp-gatekeeper）
 *
 * 对真人眨眨眼，对机器人睁着眼。WordPress 评论验证 × 登录验证 × 内容过滤三闸合一，
 * 验证码与云端审核能力驱动化可插拔（G5：零 composer 依赖发行）。
 *
 * 当前里程碑 M1（评论验证 MVP）：天御验证码驱动 + LocalRules 四闸 + CommentGate 编排 +
 * 传输脊 RemoteClient + IpResolver + 事件写入/统计 v1 + wp_die 品牌拦截页 + 文案工坊实装。
 * M0 交付：骨架/激活/建表/卸载 + 7 Tab 设置框架。登录闸 M2、云审核与 IP 信誉 M3。
 *
 * @package WinkGatekeeper
 *
 * @wordpress-plugin
 * Plugin Name:       Wink 门口保安
 * Plugin URI:        https://github.com/wink/wink-wp-gatekeeper
 * Description:       对真人眨眨眼，对机器人睁着眼——评论验证 + 登录验证 + 内容过滤三闸合一，验证码与云端审核驱动化可插拔。
 * Version:           0.5.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Wink
 * Text Domain:       wink-gatekeeper
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问。
}

/* ----------------------------------------------------------------------
 * 常量
 * -------------------------------------------------------------------- */

define( 'WINK_GATEKEEPER_VERSION', '0.5.1' ); // I5·站长白名单 UI+判定接线（M3-IP 定稿 §7 误伤防护·判定层 + 【实施注记·I5】，2026-11-13：① schema 新键 iprep.whitelist（textarea 每行一个 IP/CIDR，空=无白名单——Sanitize textarea 型，merge_defaults 整树补齐零 migrate）② 判定纯函数 AbuseIpdbService::whitelist_hits(ip,lines)+whitelist_lines_from(raw)（CIDR 语义复用 TrustedProxies::parse_cidr/matches 既有零件；非法行忽略不放宽豁免面）③ 步 0 白名单接线——构造器默认 whitelist_match 闭包改消费 iprep.whitelist（生产 assemble 传设置树即生效，构造器签名零改动，I2 注入位契约保持——命中者 probe null skip 零外呼零计量、cache_only 同豁免先于防呆；report_ip 白名单前置同源——永不报不计数先于突增计数）④ Tab④ 分区① 白名单区——textarea id=wk-iprep-whitelist+「校验白名单格式」按钮（admin-brand.js 共享校验件 wkCheckCidrLines 泛化复用）+服务端非法行即时标出+文案三行（永不查信誉永不上报/与受信网段区别：受信网段管解析信任链白名单管信誉豁免/误伤防护爬虫办公出口）⑤ 诊断面板豁免徽标——IpDiagnostic::analyze 扩第 4 参 whitelist_lines+effective 行扩 whitelisted 列（渲染「白名单豁免」）——IpWhitelistTest 新 17 例回归门；0.5.0→0.5.1）+ I4·Tab④ 四分区全量 UI 收官（M3-IP 定稿 §6/§11，2026-11-12：① 分区①真实 IP 来源——network 子树全量控件（ip_source 模式下拉 direct/xff/traverse/custom_header+适用拓扑注记/自定义头名/Cloudflare 预设选择器+PRESET_VERIFIED 快照日期/EdgeOne 空预设 UI 隐藏保持/受信网段 textarea+CIDR 前端校验按钮——零新端点纪律）+I0 诊断面板原样挪入分区①（行为零改动只挪渲染位置；占位三卡退役——950/fail_mode/M3 实装文案漂移全修）② 分区②IP 信誉查询——enabled+驱动下拉+api_key password_field（P0-α 教训）+阈值双滑杆 85/60（type=range+output 随动）+soft_quota 3000+daily_quota 4750+缓存双键（cache_minutes abuseipdb 子树/negative_cache_minutes iprep 顶层——实况键名读实况）+timeout 1–5s+健康行（IpReputationService::state_label 三态+DriverHealth 停转态）+软顶命中黄条【O5 回写·P2-4】+「测试凭据」按钮——AJAX wink_gatekeeper_test_iprep（nonce+manage_options 双闸；AbuseIpdbService::probe_credentials 新方法=最小 check 查询 127.0.0.2 保留地址+maxAgeInDays=1 省配额，零计量零写零事件；CredentialProbe::classify_abuseipdb/local_precheck_abuseipdb 三态判定法 O5-P2-6——绿=HTTP 200 且 JSON 可解析 isPublic:false 亦绿/黄=缺参/红=401/402 错钥/灰=429·传输·契约；admin-brand.js 泛化件同款三态交互）③ 分区③上报——report.enabled 总开关+出境黄条（PIPL）+T1–T4 勾选组（每面开关+阈值框（T2/T4；T1/T3 无阈值框）+类目下拉（AbuseIpdbCategories::MAP 1–23+default_for 默认标注）+Comment 自定义框（占位符 {event_no}/{category}/{gate} 提示+公开可见警示））+冷却 60min/限速 60/h/日顶 500 只读展示+启用门禁状态行（real_ip_self_check 消费——未过红字「上报已强制失效：真实 IP 解析异常（NAT/反代共显）」）④ 分区④说明卡——skip/pass/fail 三态人话+信用免验联动（老朋友通道也过码）+软顶人话句——UiTab4ZonesContractTest 新 16 例+CredentialProbeAbuseIpdbTest 新 10 例+UiTab4IpDiagContractTest 契约翻转（面板嵌入分区①）回归门；0.4.7→0.5.0 M3-IP 收官）+ I3·IP 信誉 report 路径（M3-IP 定稿 §4/§7/§9/§10-3，2026-11-12：① 接口面——SupportsReportingInterface 新件（report_ip(ip,context):bool，includes/ip/；02 §2.3a 旧三参草案作废）+AbuseIpdbService 实现之（instanceof 能力探测消费 O2-P2-7——闸构造器零改动）；传输=RemoteClient mode='report' 2s 零重试永不阻塞（通道已建待消费），发送失败静默 false+事件 error 分类，冷却后下触发点自然重试；返回 true=已受理含冷却去重命中免重报 ② 触发集 T1–T4——T1 captcha_failed（CommentGate 有票核验失败 reject 收口 reject_with_message 分派；无票 captcha_missing 与 transport error 不触发）/T2 review_reject（云端 reject 累计 N=5）/T3 login_attempts（LoginGate 双信号计数 IP 聚合维度饱和判定处复用 LoginCounters 10 零新增计数器；LoginIpRep::reporter() 能力透出）/T4 local_rules_fail（Precheck 预检四闸+CommentGate 步骤 2.5 提交四闸两处幂等计 1，累计 N=5）；计数键 wk_rp_cnt_<face>_<ip_hash16> 24h 滚动窗 ③ 四道闸——①总开关 iprep.report.enabled 默认 true→false（出境与隐私拍板 0.4，存量特征值 migrate 平移）②每 IP 冷却 wk_rp_cool_<hash16> 60min（发送成败都落——丢报无损；命中视为成功免重报）③全局 wk_rp_global 小时桶 60/h（I1 TransientRateLimiter 参数化共享件）+daily_cap 500/日独立分桶（abuseipdb_report 独立计量，配额尽停 report 不停 check）④启用门禁=真实 IP 自检未过强制失效无开关（real_ip_self_check：REMOTE_ADDR scope private/invalid=Docker NAT 共显类）④ 突增告警——wk_rp_spike 10min 窗原始触发>20（计数点=四道闸之前含被冷却/限速丢弃者 O5-P3-1）→usage_spike 事件（gate=system/stage=ip）+Tab① 站务提醒通栏黄条（today_rule_count 消费）⑤ Comment 设计拍板 0.8——硬前缀 [wink-wp-gatekeeper] 发送前拼接不存设置+默认英文短语四条+自定义覆盖键 comment_<trigger>+占位符白名单 {event_no}/{category}/{gate} 白名单外原样保留+255 截断；负载键白名单 ip/categories/comment 自证零正文零词表零域名；AbuseIpdbCategories 类目常量类 1–23+每面 category_<trigger> 覆盖（0=默认 T1/T3=18 T2/T4=10 非法码回落）⑥ schema——report 子树新键 triggers.login_attempts/local_rules_fail+两 _threshold 子键+daily_cap+category_/comment_ 每面覆盖键；ip_reject 废弃键 migrate_fields 幂等清除（O5-P1-3）；Usage last_report_at 落 option（mark_report_at 注释位兑现）；should_record pass 特例+report_sent；assemble report-only 分支（!enabled 且 report.enabled 且密钥在仍装配——probe 恒 null 与 Null 语义等价，report 独立于 iprep.enabled 04 §2）——AbuseIpdbReportTest 35 例+IpReportTriggerWiringTest 11 例+Schema/Sanitize/Matrix/Assembler 扩例回归门；0.4.6→0.4.7）+ I2·IP 信誉 check 路径全链（M3-IP 定稿 §2/§3/§9，2026-11-12：① AbuseIpdbService 新件（includes/ip/）——probe() 七步流水线（白名单→防呆→规范化→退避 wk_irb_ 300s→停转→软顶 soft_quota 3000 从严→缓存 wk_ip_ 24h/1h→外呼 maxAgeInDays=90 映射 85/60 双阈值→落账只在外呼成功后写缓存+RemoteClient 计量钩子外呼尝试即计）+fail-open 四类失败处置（transport 退避不停转/429 退避+quota_exhausted 停转+Retry-After≥300s 尊重/401·402 config_error 停转/契约不符 contract_mismatch 哨兵+退避）+cache_only 只读模式（O5-P1-1：预检/登录渲染期必传零外呼，未命中∨退避∨停转返 fail cache_miss_strict/backoff/halted 不返 null）+事件 meta 经 Verdict::event_meta 携带（score 整数直存/cached/soft_quota/raw 脱敏快照）② assemble 防呆（enabled+未配钥=fail not_configured 从严；白名单/管理员前置豁免）+registry 注册 abuseipdb（type='ip'/billing='free_quota' 枚举扩展/supports_report=true——Tab① 点名册卡/Tab⑥ 服务商统一卡「IP 信誉防线」组/Tab⑥ IP 单列行折减自动生效；overview_roster_cards+stats_driver_cards 收集扩 type='ip' 三域——渲染侧 Labels 信誉查询/stage_word/action_verb 原生词零新增）③ 缓存归一（缓存内化 AbuseIpdbService；LoginIpRep 退化纯映射——get/set/hmac 退役占位形参，无双写双读；LoginGate 渲染路径 cache_only/验证路径完整模式分接线）④ schema v1.7 平移（iprep.abuseipdb.soft_quota 新键默认 3000；cache_minutes 60→1440/negative_cache_minutes 10→60 特征值 migrate 平移；Sanitize 白名单同步）⑤ CommentGate::order() 口径统一（ip,captcha,review 与实际编排一致）+uninstall 补 wk_irb_ 前缀+DriverPrivacy privacy_url 在线核验标注清除——AbuseIpdbServiceTest 30 例+LoginIpRepTest 重写+IpReputationServiceTest/PrecheckTest/LoginGateTest 扩例回归门；0.4.5→0.4.6）+ I1·CC 防线 P0×2（M3-IP 定稿 §8，2026-11-12：① P0-A 评论提交限流器——wk_cm_ per-IP 小时桶（gates.comment.submit_rate_limit 默认 30 次/h/IP，0=不限流），TransientRateLimiter 参数化共享件（DegradedPassLimiter 同形态泛化新类——后续消费方 check/report 面/P1-C 送审帽），orchestrate 统一插入面（存根校验后、四闸之前）覆盖 wp-comments-post/admin-ajax/REST 三通道，超额=统一 captcha_missing 文案不外呼（stage=rate+meta.rate_limited 不留档——洪水防膨胀），uninstall 清单补 wk_cm_ 前缀 ② P0-B XML-RPC per-request 一次化——multicall 首拒后零 sleep 零事件（请求内静态标志 xmlrpc_attempt_plan）+每请求 auth 尝试上限 20 超限 fault（封杀 AUDIT-CC §2.5 worker 池灭绝形态）③ breaking：gates.login.xmlrpc_login 默认 true→false（存量不迁移——升级站点不受影响，readme/changelog 注明）——TransientRateLimiterTest+CommentGateRateLimitTest+LoginGateXmlrpcOnceTest+SettingsSchemaTest/SanitizeTest 扩例回归门；0.4.4→0.4.5）+ I0·IP 解析层三件（M3-IP 定稿 §5，2026-11-12：① Tab④ 顶卡「IP 检测」诊断面板两区——本地解析区五态 ip_src 行矩阵+配置一致性红标+受信网段快照黄条+手动聚合四源卡片（AbuseIPDB/StopForumSpam/ip.nc.gy/ip.sb，RemoteClient standard 3s 零落库零缓存零统计，四源 URL 常量集中 IpDiagnostic::REMOTE_SOURCES https 硬约束）② network.ip_source 新增 traverse 链式回溯档（REMOTE_ADDR 不受信恒退化 ignored_untrusted）③ 预设校准 CLI tools/api-smoke/refresh-presets.php + EdgeOne 空预设 UI 隐藏注记——IpDiagnosticTest+IpResolverTraverseTest+RemoteClientGetTest+UiTab4IpDiagContractTest 回归门；0.4.3→0.4.4）+ UI-FIX·设置页密度与样式克制（2026-11-12 用户实测反馈两条合并批：FIX-A Tab① 点名册未配置收拢行+单入口 / FIX-B Tab⑥ 两级标题制全页零序号+指引收敛段标题+密度对齐 O3——UiTab1OverviewContractTest+UiTab6StatsContractTest 契约翻转；0.4.1→0.4.3）+ O3·仪表盘统计卡片三卡（O3-统计卡片设计建议 §七 步骤 1-5：DashboardDigest 视图①纯逻辑类零明细查询不变式/Bootstrap wp_dashboard_setup 注册 manage_options 门禁+开关键/三卡 HTML+CSS+行动出口深链/cron 存活检测+重新激活通道/StatsCache disaster_pass 计数特例+写入矩阵 pass 特例——DashboardDigestTest+O3DashboardWidgetContractTest 回归门；小版本号进位 0.4.0→0.4.1）+ UI 批 3·Tab① 总览页点名册形态重构（DESIGN-UI-tab1 B 提案五块，0.3.9→0.4.0）+ UI 批 2·事件查询面×5 + UI 批 1·Tab⑥ 五段重构 + FIX 轮 5·flood 品牌页 + FIX 轮 4·登录错误掩码分域 + FIX 轮 3·Tab③ 下拉联动——wp plugin list 可辨轮次。
define( 'WINK_GATEKEEPER_FILE', __FILE__ );
define( 'WINK_GATEKEEPER_DIR', plugin_dir_path( __FILE__ ) );
define( 'WINK_GATEKEEPER_URL', plugin_dir_url( __FILE__ ) );

/* ----------------------------------------------------------------------
 * Autoload（G5 零依赖：WinkGatekeeper\ → includes/；核心逻辑类零 WP 依赖）
 * -------------------------------------------------------------------- */

require_once WINK_GATEKEEPER_DIR . 'includes/Autoloader.php';
\WinkGatekeeper\Autoloader::register( WINK_GATEKEEPER_DIR . 'includes' );

/* ----------------------------------------------------------------------
 * 激活：建 events 表（dbDelta）+ 写默认 option（含 schema_version:1）+ 注册每日维护 cron
 * -------------------------------------------------------------------- */

register_activation_hook(
	__FILE__,
	static function (): void {
		// 建表（04 §3 DDL，dbDelta 幂等）。
		\WinkGatekeeper\Stats\EventsTable::install();

		// 默认设置树整包写入（add_option：已存在时不覆盖，升级走 migrate_* 约定）。
		// 第 4 参 false = 不 autoload（WP 6.6+ 推荐显式布尔，替代 legacy 字符串 'no'）。
		add_option(
			\WinkGatekeeper\Settings\SettingsPage::OPTION_NAME,
			\WinkGatekeeper\Settings\SettingsSchema::defaults(),
			'',
			false
		);

		// 每日维护（90 天清理 + 1 万行滚动上限，04 §3）。
		\WinkGatekeeper\Stats\EventsTable::register_cron();
	}
);

/* ----------------------------------------------------------------------
 * 停用：仅清 cron（沿「停用不清设置」习惯；数据清理按开关走卸载）
 * -------------------------------------------------------------------- */

register_deactivation_hook(
	__FILE__,
	static function (): void {
		\WinkGatekeeper\Stats\EventsTable::clear_cron();
	}
);

/* ----------------------------------------------------------------------
 * 启动（02 §4：普通时序；M0 只装配设置页。顶层共存检测 M4 接入，02 §3.4）
 * -------------------------------------------------------------------- */

\WinkGatekeeper\Bootstrap::instance( WINK_GATEKEEPER_DIR, WINK_GATEKEEPER_URL )->boot();
