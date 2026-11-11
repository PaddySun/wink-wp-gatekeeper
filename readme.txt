=== Wink 门口保安 ===
Contributors: wink
Tags: comments, spam, captcha, security, login
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.4.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

对真人眨眨眼，对机器人睁着眼。评论验证 × 登录验证 × 内容过滤三闸合一，验证码与云端审核驱动化可插拔。

== Description ==

Wink 门口保安是面向中文独立博客的 WordPress 门口保安：

* **三闸合一**：评论验证、登录验证、内容过滤，统一编排（闸 = 编排者，驱动 = 执行者）。
* **驱动化可插拔**：验证码（腾讯天御 2.0 首发）、云审核（DeepSeek 首发）、IP 信誉（AbuseIPDB）三类能力驱动注册表接入，换驱动零改代码。
* **wink 品牌**：眨眼 logo、事件编号（`WK-` 前缀）、台阶文案——被拦的访客也「给台阶、指下一步」。
* **零 composer 依赖**：G5 形态发行，核心逻辑为纯 PHP。

当前版本为 **M1 评论验证 MVP**（0.1.0）：在 M0 骨架/激活建表/设置框架之上，实装腾讯天御验证码驱动（TC3 签名 + 传输脊重试）、LocalRules 四闸内容过滤、评论闸编排（`preprocess_comment` 最早优先级 + REST 评论封堵）、真实 IP 解析（直连/XFF 仅取最右/自定义头 + 信任链不变式）、事件记录与统计 v1、wp_die 品牌拦截页与文案工坊。登录闸 M2、云审核与 IP 信誉 M3。

== Installation ==

1. 上传 `wink-wp-gatekeeper` 目录到 `/wp-content/plugins/`；
2. 在「插件」菜单激活——激活时自动建事件表并写入默认配置；
3. 前往「设置 → Wink 门口保安」：Tab② 选择「腾讯天御验证码 2.0」并配置 CaptchaAppId 与 CAM 密钥对（SecretId/SecretKey），评论表单即出现验证组件。

== Frequently Asked Questions ==

= 卸载会删除数据吗？=

默认不删。设置页的「卸载时清理」开关（`cleanup_on_uninstall`）打开后，卸载才删除全部 option、事件表、cron 与缓存前缀。

= 会与官方 hCaptcha 插件冲突吗？=

共存让位协议在 M4 实装（检测到官方插件接管评论验证时本插件自动让位）。

== Changelog ==

= 0.4.3 =

FIX 轮 6·Tab①⑥ 视觉层级与三色状态点（2026-11-12 用户实测浏览器反馈）：① 防线分组标题（h4 wk-defense-group）由灰(--wk-fog)改墨(--wk-ink)+字号 0.95rem+weight 700——与段标题(1.15rem)层级拉开（原灰色+近字号=层级消失）；② 驱动卡状态点由字符(●○◐✕)改彩色圆点 span（wk-dot 体系复用+新增 wk-dot--standby 蓝=#2271b1）：绿=启用/蓝=已配置未启用/红=配置问题/黄=今日暂停——dot_class 字段入 driver_card 纯函数（四分支单测钉）。1262 tests / 8634 assertions 全绿。

= 0.4.2 =

UI-FIX·设置页 UI 密度与样式克制修正（2026-11-12 用户实测反馈两条合并批，UI-FIX-FEEDBACK-2026-11-12 拍板方向）：①FIX-A·Tab① 点名册未配置收拢行（反馈 1 折中方案）：默认只展开已配置卡（当班+待命，含其上异常卡——异常置顶微排序不变），未配置服务商收拢一行「未配置的服务商：人话名 · 人话名 [去配置 →]」——保留可发现性不占屏；单入口替代每卡一入口（跳转目标=未配置卡 config_tab 去重，全同域链对应 Tab②/③，混合域回落 Tab② 三步引导）；未配置灰卡不再单独渲染（wk-roster-card--disabled 退役）；纯渲染层改动（OverviewRoster::cards 纯函数/存储/排序零变更）。②FIX-B·Tab⑥ 文字样式克制（反馈 2 三宗罪）：两级标题制——段标题 wk-sec-title（1.15rem）>段内小标题 wk-sub-title（16px 加粗）两档全页统一，样式走 admin-settings.css 类禁内联（原始回复展开块内联 style 迁 .wk-fault-raw 类）；全页零序号——「一、二、三」「①②③」双序号体系全清（契约扫渲染体含函数体内注释）；配置指引收敛——「未启用/未配置」跳转指引只在段标题位置出现一次（stats_config_hint：段内有未启用卡才出现「 · 去配置」，评论段/登录段两调用点恰各≤1），驱动卡内部蓝链接退役（未启用卡=灰徽标+极简一句无链接，占位句去 Tab 序号引用，卡不承担导航职责——config_tab 数据面保留供段级消费）；密度对齐 O3 widget——正文单层字号（wk-defense-group 0.9rem 多级字号收敛），徽标/数字加粗即强调。③测试：UiTab6StatsContractTest 契约翻转（序号断言清零/蓝链接计数/标题类三新例+五段骨架断言翻新）+ UiTab1OverviewContractTest 收拢行新例（「五卡齐」形态断言翻转「已配置展开+未配置一行」）+ InterceptDigestCardsTest 占位句反钉，版本钉 0.4.2 五测试文件同步。真机（厦门 wpclean）：部署 0.4.2 后 render 通道 DOM 断言 Tab① 收拢行在场+未配置卡零单独渲染、Tab⑥ 零序号字符+段标题类在场+蓝链接每段≤1（IMPL 报告：docs/reports/IMPL-UIFIX-2026-11-12.md）。

= 0.4.1 =

O3·仪表盘统计卡片三卡上线（2026-11-12，O3-统计卡片设计建议.md §七 步骤 1-5——参照 Wordfence 9.1.0 wfActivityReport 三视图分级架构：wordfenceClass.php:7410-7429 注册骨架 + activity-report.php 转义/深链纪律）：①DashboardDigest 纯逻辑视图模型类（widget_overview 全可注入——类头注钉「视图①零 EventsTable 明细查询、只读 option 计数器」不变式，19 单测覆盖三卡各字段/空态/达限红态/disaster_pass 键/异形输入防御）；②Bootstrap 注册（wp_dashboard_setup + 两处有意改造：manage_options 能力门禁自拦/暂不挂网络仪表盘 + stats.dashboard_widget_enabled 开关键入 SettingsSchema（默认 true，仿 wfConfig email_summary_dashboard_widget_enabled）+Sanitize bool 规则——schema↔rules 双向一致性测试自动封口）；③三卡 HTML/CSS（卡 1 系统健康=四闸状态点+验证码配置态+停转红警报+cron 存活；卡 2 调用量=各驱动今日行+配额「N / M」达限红+站点总外呼+容灾放行行；卡 3 概览迷你条=「今日：拦截 N ｜ 待审 N ｜ 驱动错误 N」；每卡一条 button-primary 行动出口深链——健康卡→Tab①/调用量卡与概览条→Tab⑥；assets/css/admin-widget.css 品牌同款 token 版本化入队；esc_html/esc_attr/esc_url/(int) 转义全覆盖）；④cron 存活检测（wp_next_scheduled(EventsTable::HOOK_DAILY) 丢失红点+「重新激活」admin_action 通道——nonce+manage_options 双闸+register_cron 幂等重排）；⑤StatsCache disaster_pass 计数特例（CommentGate 默认 recorder meta.disaster_pass 语境 bump 独立键；写入矩阵 pass 特例同步放行容灾放行事件落库——hits 携带 disaster_pass 标签与 trust_pass 同法）。测试：DashboardDigestTest 19 + O3DashboardWidgetContractTest 16 + EventsTableWriteMatrixTest/StatsCacheRecordBridgeTest/CaptchaDriverV11Test 各扩 1-2 例，版本钉 0.4.1 五测试文件同步。

= 0.4.0 =

UI 批 3·Tab① 总览页点名册形态重构（2026-11-12，DESIGN-UI-tab1 B 提案五块 + 统筹裁决——「服务商」语汇/点名册直白命名不用保安比喻/闸字保留为分组名；总览大改版小版本号进位 0.3.9→0.4.0）：①五块新布局（DOM 锚 wk-overview-alerts/report/roster/incidents/footer）：站务提醒通栏（演练模式/统计停用/验证码域兜底新黄条/审核域兜底——从卡内迁出置顶）、今日战报（评论闸/登录闸/注册·找回闸三张小票——0=平静的一天显式空态，评论副行=N4 今日按规则分布人话族；行动行=待你过目 WP 待审队列实数（W2/N8 口径）+ IP 信誉强制验证码单列行（>0 才出现）+ 垃圾箱存档（≥500 该清一清））、服务商点名册（registry 全集五服务商一字排开——排班态（当班/待命/未配置）×健康态（正常/故障/额度用完）渲染侧推导 OverviewRoster 纯函数，不改 DriverHealth 存储；异常置顶微排序；未配置灰卡带 [去配置]；本地规则不入册给脚注；旧「驱动健康」表退役）、最近岔子（批 2 N2∪N3 合并时间线紧凑形态 5 条——故障与解除都入列，空态「最近 7 天风平浪静」，入口链 Tab⑥）、站点底账一行（统计计数 · 演练模式 · 事件记录 N/10,000 容量读数（N11，EventsTable::row_count）· 首次使用三步引导）；②Labels 扩表：DESIGN-UI-tab1 对照表并入（当班/待命/未配置、故障/额度用完+原因括号注、待你过目/备胎方案/每日额度/演练模式/垃圾箱存档、站内规矩脚注/当前审核服务/放行不记数注、按岗位动词 验/审/查、战报副行人话族——批 1 rule_hit_word 同资产回落）；③旧占位卡退役（待审队列/事件流预览/驱动健康表——render_driver_health_rows 除名）；分层纪律：Tab① 只看今天、每数字一句人话、无表格化明细（明细归 Tab⑥）；④批 2 读路径缺陷修正（真机 DOM 断言拦截）：N2/N3 列集补 id 列（两源归并序键——批 2 文档声称在列而 SQL 漏选，归并退化）+ fault_timeline_rows 按 id 去重（停转行双谓词命中只渲染一次）——Tab⑥ 时间线与 Tab① 岔子两消费面同时受益，零 DDL 零写路径变更。测试：OverviewRosterTest 13 + LabelsTest 扩 6 + UiTab1OverviewContractTest 16 + InterceptDigestRowsTest 去重钉 1 新例，版本钉 0.4.0 四文件同步。真机（厦门 wpclean）：部署 0.4.0 后三相 DOM 断言 clean 52/52+通栏 9/9+seeded 45/45（IMPL 报告：docs/reports/IMPL-UI3-overview-2026-11-12.md）。

= 0.3.9 =

UI 批 2·事件查询面×5 + Tab⑥ 批 1 占位接线（2026-11-12，DESIGN-UI-tech-feasibility §D/§G 批 2——全部只读 SELECT+prepare+LIMIT，零 DDL、零 recorder 写路径变更，idx_gate_driver/idx_created 既有索引命中）：①五个新查询（EventsTable 纯 SQL 模板 builder+边界方法成对）：N1 recent_account_rejects（登录/注册/找回三闸 reject 明细，stub/captcha 两阶段，列集不带 ip_hash——PIPL 最小化）、N2 recent_faults（全闸故障时间线，fault_predicate_sql 同源复用——F7）、N3 recent_health_timeline（停转进入·解除 system/health 留痕，7 天窗口——driver_resumed 非故障口径独立谓词）、N4 today_reject_distribution（今日按规则拦下分布，批 3 战报副行数据源）、N6 today_fault_by_driver（今日故障按驱动聚合，支持 ACCOUNT_GATES 分域过滤=N7a）；②三块接线：登录段④「最近拦下的登录尝试」明细表（时间/事件编号/哪张门/哪道防线/为什么拦——trigger_source 归因人话，无账号无 IP 哈希）、报错历史②全闸时间线（N2∪N3 按 id 倒序归并，新增「哪个门」列，「当时怎么处理」组合映射 handling_word——含服务商当日暂停与恢复留痕行，批 1「当前显示评论门报错」注记移除，recent_comment_faults 退役）、服务商统一卡出错格（评论卡=事件真值∪计数缓存取大补 captcha 远端故障；登录卡=三闸分域出错数——批 1 隐藏格解除）；③Labels::gate_word 收录 system=服务商健康；InterceptDigest 行组装扩展（account_reject_rows/fault_timeline_rows/handling_word/distribution_from_rows 全纯函数）。测试：EventsTableQueriesTest 10 + InterceptDigestRowsTest 7 + UiTab6StatsContractTest 接线 3 新例（批 1 占位断言随接线翻转），版本钉 0.3.9 三文件同步。真机（厦门 wpclean）：部署 0.3.9 后造数（登录 reject/远端故障/停转解除行）DOM 断言明细卡+时间线+出错格（IMPL 报告：docs/reports/IMPL-UI2-queries-2026-11-12.md）。

= 0.3.8 =

UI 批 1·Tab⑥ 统计页五段重构（2026-11-12，DESIGN-UI-tab6 A 提案+统筹裁决三拍板——「服务商」语汇/待审排除 IP 风控/三批串行）：①五段骨架（速览条→一、评论防护→二、登录防护（登录/注册/找回密码 三张门）→三、报错历史→四、拦截记录流水→五、统计设置·清零，DOM 锚 wk-stats-overview/comment/login/faults/records/settings）——最近拦截评论明细归位评论段③（P3）、云端错误子表迁出为报错历史（P8）、统计开关三项迁入尾卡（P7）、事件流占位去 roadmap（P6）、重置文案去「对账口径」（P10）、开发者备忘删除（P5）、累计补「自上次清零起」窗口（P9）；②服务商统一卡模板（四档健康徽标 ●○◐✕/箭头行「今日人机验证 N 次 → 放行·拦下·转人工·出错」/额度行/未启用灰卡带 Tab②③ 跳转——评论段与登录段复用同模板，InterceptDigest::driver_card 纯函数）；③术语人话映射表 Labels 新纯类（N5——对照表 24 条全表钉测：调用→人机验证/送审/信誉查询、通过→验证放行、待审→转人工复核、登录失败尝试→密码输错、维度→防线、rule_hit/trigger_source 键族人话）；④待审排除 IP 风控（review_split 纯函数——「转人工复核」只计云审/规则待审，「IP 信誉强制验证码」单列一行不入待审数字）；⑤本地规则卡砍「检查 N 条」三数式（A-3）、登录明细卡/全闸报错时间线占位（批 2 接线）。测试：LabelsTest 22 + InterceptDigestCardsTest 13 + UiTab6StatsContractTest 13 新例（48 例），InterceptDigestTest/InterceptDigestUsageTest/B1bStatsTest/B1bContractTest 随批迁移新契约，版本钉 0.3.8 三文件同步。真机（厦门 wpclean）：部署 0.3.8 后 Tab⑥ DOM 断言五段锚+统一卡+黑话退场（IMPL 报告：docs/reports/IMPL-UI1-tab6-2026-11-12.md）。

= 0.3.7 =

FIX 轮 5·flood 拦截页品牌化接管（2026-11-12 用户实测「手速比眨眼还快」文案品牌化但页面壳为 WP 原生 wp_die）：hook_comment_flood_trigger 对齐 duplicate_comment 形态——原生上下文（wp-comments-post 表单）DiePage::show('comment_flood') 品牌页接管（事件编号/规则/时间占位符齐），AJAX/REST 分支保持核心响应+文案 filter（零变化）。B1aContractTest 回归断言（DiePage 调用+AJAX 豁免）。1119 tests / 7716 assertions 全绿。真机：连发评论第二条=品牌拦截页实锤（厦门 wpclean）。

= 0.3.6 =

FIX 轮 4·登录错误掩码场景串域修复（2026-11-12 用户实测「登录页错用户名密码却显示重置邮件文案」，统筹真机复现闭环）：登录框支持邮箱登录——不存在的邮箱形态输入报 invalid_email，原按码分域把它固定映射到找回域文案（「暂时无法发送重置邮件」打到登录页）。修复：掩码分域改按**消费场景**（mask_message 增加 scene 参数：SCENE_LOGIN 登录框一律「用户名或口令不正确」/SCENE_RESET 找回框一律「无法发送重置邮件」——同一码两场景语义不同，场景由消费 filter 决定）。LoginErrorMaskTest 场景断言×3 新增。1118 tests / 7713 assertions 全绿。真机复现：错用户名→登录文案 ✓；邮箱形态→登录文案 ✓（修复前=重置邮件文案实锤复现）。

= 0.3.5 =

FIX 轮 3·Tab③ 云端审核驱动下拉切换联动修复（2026-11-11 用户人工测试反馈「tab=review 下拉选阿里云内容安全但下方配置区仍是腾讯云 TMS」，统筹多 agent 流水线 FIX-3 批次）：根因=Tab③ 三厂商配置区按 chain[0] 互斥 if/elseif 单区渲染（v1.4 遗留形态），前端切下拉后无区可显（TMS 区残留恒显）；修复对齐 Tab② 验证码配置区 data-wk-vendor 形态：① SettingsPage render_tab_review 三区（wk-tms-config-area / wk-green-config-area / wk-baidu-config-area）并列渲染 + data-wk-vendor="tencent_tms|aliyun_green|baidu" 属性 + PHP 初始 hidden 按 $cloud_selected（chain[0]），互斥分支除名；未配置（chain 空）三区全 hidden + 空态说明行 #wk-review-unconfigured-note（状态点+描述行，对齐 Tab② unconfiguredNote）；三区 sanitize 保存链 /「测试凭据」AJAX / PIPL 合规行 / 接入指引 / 链路汇总行零改动；② admin-brand.js Tab② 专属 syncCaptchaArea 泛化公用 bindVendorSwitcher( select, noteId )——area 容器作用域取 select 所属 .wk-panel 内 [data-wk-vendor]（Tab 页隔离防跨 Tab 误显隐；M4 后续驱动自动入列不硬化名单），Tab②（wk-captcha-driver，现行为零变化）与 Tab③（wk-cloud-driver）各挂一处 change；必填缺失弹窗（closest form 拦截+标红+二次放行）推广 Tab③——必填面=TMS SecretId/SecretKey + 阿里 AccessKey 对 + 百度 API Key/Secret Key（05 §1.2/§2.4/§2.5 registry settings.required 语义渲染面；password 已配置留空不算缺契约保持）；③ 契约测试 Fix3Tab3ToggleContractTest 8 例（全区 vendor 形态 / 互斥分支除名 / 空态说明行 / 保护现行为钉 / JS 泛化两挂接 / 必填弹窗推广 / 面板作用域 / 版本 0.3.5）+ B1a/B1b 版本钉同步 0.3.5。1118 tests / 7710 assertions 全绿（1110 基线 + 8 新例）；node --check admin-brand.js 过。真机（厦门 wpclean）：部署 0.3.5 后 tab=review DOM 断言三区 data-wk-vendor + 初始 hidden=chain[0]，select change 显隐翻转实测（FIX-3 报告：docs/reports/IMPL-FIX3-tab3-toggle-2026-11-11.md）。

= 0.3.4 =

FIX 轮 2·阿里验证码 embed 挂载点不可见修复（2026-11-11 用户人工测试暴露「验证码加载中永恒卡住」，统筹浏览器实测闭环）：真机排障七连（curl SDK GET 200 排除拉取/自建测试页 A-B-v3-v5-v6-v7 对照+console 劫持+performance resourceStatus 逐资源核验）锤定：SubmitRow 的 .wk-gate-embed 初始 hidden，SDK 往 hidden 容器**照常渲染完整组件（v7 实锤 5955 字符）**但用户不可见——视角即「点击→加载中永恒卡住+没弹验证码」；后台 0 调用自洽（组件从未过码）。B3 漏抄 tencent.js:287「挂载点展开」课程（天御同款：mount.hidden=false 后才注入）。修复：aliyun.js initCaptcha embed 分支 mount.hidden=false+AliyunFrontendJsContractTest 回归断言。附带定论：init 接口链路全通（28qnkx.captcha-open.aliyuncs.com 200/CertifyId 下发/FeiLin 行为采集引擎加载）；语言参数 'cn' 合法（'zh-CN' 报不合法——适配器原值正确）；同页双 init 互踩（SDK 单例）是测试页设计缺陷非产品问题。1111 tests / 7632 assertions 全绿（1109 基线 + 2 新例）。真机（厦门 wpclean）：部署后浏览器实测见「确认您不是机器人」滑块渲染于评论区提交按钮正下方。

= 0.3.3 =

FIX 轮·评论闸装配错位修复（2026-11-11 真机人工测试暴露，统筹诊断闭环）：B2（0.2.5，71840e3）重写 CommentGate 构造尾部时吞掉 require_gate 的 null 占位——17 实参 vs 18 形参，PHP 缺省参数静默错位一格，$degraded_allow（DegradedPassLimiter::allow 回调）顶入 require_gate 位被当豁免判定调用恒 false → 评论闸渲染+验证自 0.2.5 起双双静默跳过（前台无「完成人机验证」按钮、无票评论直发、captcha 阶段零事件——B2/B3/B5/B6 四批真机无一踩中该点：B2 被 test_mode 旁路巧合掩盖、B3 断言面在登录闸、B5/B6 只断言 review 链）。修复：Bootstrap 装配尾部补 null 占位（防回归注释）+ 新增 BootstrapAssemblyContractTest（四闸装配实参数（源码顶层逗号计数）=== 反射构造形参数——末位缺省参数静默错位在此红；+ CommentGate require_gate 占位注释语义锚点）。1109 tests / 7638 assertions 全绿（1104 基线 + 5 新例）。诊断法留档：真机 wp eval 反射生产装配实例逐关探测（is_enabled/captcha_driver/is_configured/require_gate 回调+过滤器监听清单）→ REQ_RESULT=false ∧ NO_LISTENERS 锤定闭包非默认闭包 → 参数计数对位。

= 0.3.2 =

M3' 轻量批·百度文本审核驱动上线 B6（2026-11-11，设计文档 05 §2.4 百度行——L0-baidu-2026-11-09 在线核验+冒烟实测定稿）：① 新驱动 baidu——includes/drivers/review/BaiduTextModerationDriver.php（ReviewDriver 契约：check/last_check_meta/fail_mode 闸侧），文本审核 v2（aip.baidubce.com text_censor/v2/user_defined——v2 即现行版，2020 前老接口已下线）+ oauth client_credentials token 流；**token 30 天 transient 缓存复用**（L0 实测 expires_in=2592000；WP 边界闭包注入 get_transient/set_transient，TTL=expires_in-300s 安全边际，键含凭据指纹防换 AK 串用旧 token；token 获取不计审核 usage，缓存命中与否落 meta.token_cache=hit/miss 供侧证；error_code 110/111 自动清缓存自愈重取）；AppID 不参与请求（L0 实测，仅控制台标识不作配置门槛）；② 判定映射（05 §2.4 规范性，L0 逐项实测吻合）：conclusionType 唯一判据——1 合规→pass / 2 不合规→reject（reason=conclusion+首条命中）/ 3 疑似→review / 4 审核失败→error（链上 failover+闸按 fail_mode 收口）；枚举漂移→review fail-safe（TMS unknown_suggestion 先例）；hits=data[].type/subType 人类可读格式（「12-文本反作弊(subType=4)」——L0 广告文本三条标签逐项，TYPE_NAMES 表内置）；送审串口径**邮箱不并入**（真机实锤偏差 1：百度对任何邮箱地址本身判 36-广告 subType=360104 误杀——隔离实验 email_only→reject/body_only→pass，与阿里 pt_to_contact 误杀同类、IMPL-B5 偏差 3 先例；送审串=「昵称
正文」分字段两行，include_author_fields 关=仅正文；邮箱防线收回到 LocalRules 拼接串，TMS/LLM 口径零改动）；text ≤20000 字节按字节安全截断（meta 标 truncated/original_bytes）；error_map body error_code 级（110/111/100/6/17/19→semantic 当日停转、2/3/4/5/18→transport 退避重试、未知码→契约哨兵）；空内容本地防呆零外发；③ Tab③ 百度配置区——API Key+Secret Key password 型不回显（P0-α 教训）+ 超时/日配额（计费防呆 500 次/天默认）/降级单选 + 合规行（privacy 维：aip.baidubce.com 境内不出境/拼接串含邮箱）+ 接入指引；「测试凭据」三态（绿=conclusionType=1 探测合规顺带解除停转；黄=键缺或 error_code=100；红=鉴权失败 error_code=110/111/6 指明控制台核对）；registry 登记（Tab③ 下拉 chain[0] 可选，脱离 future 里程碑段——F42 切换机制：切百度/TMS/阿里配置各自独立保留零改代码）；④ schema review.cloud.baidu 子树 M0 已落位（本批零新键，Sanitize password 型核对钉测）。1104 tests / 7620 assertions 全绿（1050 基线 + 54 新例：驱动 34（含邮箱排除回归钉 4）/设置面 9/凭据三态 11——L0 实测响应形态金测试+真机隔离实验夹具；含真机偏差 1 修正轮追加 4 例，B7 收线核准数）。真机（厦门 wpclean）：凭据三态绿（conclusionType=1，990ms）/黄/红（HTTP 401 鉴权）全过；正常评论 pass、广告样文 reject（36-广告 360101+12-文本反作弊 subType=4）；meta.token_cache=hit 侧证 token 缓存复用；Tab③ 切百度→切回 TMS 双驱动配置零清空（F42）。

**B7 收线补记（2026-11-11）**：收线批（IMPL-B7-closeout-2026-11-12.md）完成 01 验收行登记、本 Changelog 结构核准、e2e 工具箱脱敏入库 26 件（占位符化+六类敏感 token 复扫零命中；evidence/ 与含凭据快照保持本地）、人工测试卡 18 卡总呈（MANUAL-CARD-M3-阿里系.md）。

= 0.3.1 =

M3' 批次 3·阿里文本审核增强版 PLUS 驱动上线 B5（2026-11-11，设计文档 v1.5 05 §2.5 + DESIGN-D2/L0-ali-green-pro 定稿）：① 新驱动 aliyun_green——includes/drivers/review/AliyunGreenPlusDriver.php（ReviewDriver 契约：check/last_check_meta/fail_mode 闸侧），TextModerationPlus（green-cip.cn-shanghai · Green 2022-03-02 · ACS3 复用 B3 AliyunV3Signer form body 形态零新增签名代码）；**双 Service 分送**（02 §2.2 例外条款拍板 D20）：昵称→nickname_detection_pro 单独一调（昵称特化模型，L0 实测短广告串命中 pt_* 三标签）、邮箱+正文→comment_detection_pro 一调，先昵称后正文 reject 快短路，OR 取严；**单通道故障不吞另一通道**（一通道 error 另一通道判定仍有效，双通道全故障才 error）；F36 兼容回退开关 review.cloud.aliyun_green.dual_dispatch（默认开；关=拼接串单调同口径）+ review.include_author_fields 关时单调仅正文；② 判定映射（05 §2.5 规范性）：**RiskLevel 双轨制**——none/low→pass / medium→review / high→reject（reason=首条 Label,Description；hits=全部 Label；meta 记 Confidence+RiskWords 摘要；Confidence 不参与收口——本站恒 100 二值化注记）；**nonLabel 伪标签剔除**（L0 实测修正：干净文本 Result 返回单条 nonLabel 而非空数组）；兜底 RiskLevel 缺失但有标签→review；600 字截断（meta 标 truncated）；error_map body Code 级（400/408→semantic 当日停转、500/581/588→transport、未知 Code→契约哨兵）；③ Tab③ 阿里 PLUS 配置区——AccessKey 两键 password 型不回显（P0-α 教训）+ 双 Service 名（默认 pro 值可配）+ dual_dispatch 开关 + 超时/日配额/降级单选 + 合规行（privacy 维：分通道送审/green-cip 境内不出境）+ 接入指引（含控制台细粒度检测开启引导）；「测试凭据」三态（绿=探测 Code=200 顺带解除停转；黄=AK 缺或 Service 名空；红=Code=408 PERMISSION_DENY 指明补授 AliyunYundunGreenWebFullAccess 或签名类错）；registry 登记（Tab③ 下拉 chain[0] 可选，api_version=2022-03-02 与 x-acs-version 同源）；④ F4-3 阿里标签映射核对补缺——05 §2.5 摘录族 22 新键进 Copywork::cloud_labels（inappropriate_ethics/superstition、sexual_terms、political_* 五族、violent_* 三族、contraband_* 四族、religion_* 五族、ugc）；⑤ schema review.aliyun_green 双 Service 键+dual_dispatch（merge_defaults 老树回填；Sanitize 规则同步，AccessKey 两键 text→password）。1050 tests / 7457 assertions 全绿（998 基线 + 52 新例：驱动 29/设置面 10/凭据三态 13——L0 实测响应形态金测试）。

= 0.3.0 =

M3' 批次 2·阿里云验证码 2.0 驱动上线 B3（2026-11-11，设计文档 v1.5 05 §1.2 + DESIGN-D2 定稿）：① 新驱动 aliyun（M3' 批次 2）——includes/drivers/captcha/AliyunCaptchaDriver.php，verify_tri 三态（**二态制无评分**：Result.VerifyResult Boolean 唯一判据，pass 恒 score=1.0；reject 按 VerifyCode 码表分档 user_retry=[F008,F015,F019,F018,F020]/config_error=[F005,F006,F012,F022]/blocked=[F001,F009,F010,F011,F014,F016,F017,F024,F025]，未知码兜底拦截 fail-closed；error=传输/语义/契约终态）；disasterPolicy 恒 'none'（阿里无容灾票概念）、errorPolicy 默认 'block'（pass_limited 留 SLA 评估）；registry 注册行七维+scoring:false+privacy（境内不出境，data_fields=访客 IP（行为采集，经阿里客户端 SDK）、CaptchaVerifyParam）；② includes/http/AliyunV3Signer.php——ACS3-HMAC-SHA256 V3 签名自持（tools/api-smoke/aliyun-sign.php 蓝本升格，**金测试=阿里云官方文档测试向量**，与 L0 两次实调同锚），业务参数 form body（官方不建议 JSON）；服务端 VerifyIntelligentCaptcha（captcha.cn-shanghai · captcha-2023-03-05）经传输脊 RemoteClient（重试/分类/计量/脱敏统一）；③ 前端 assets/js/drivers/aliyun.js——V3 三步形态（window.AliyunCaptchaConfig={region:'cn',prefix} 先行 / **AliyunCaptcha.js 动态引入红线** / initAliyunCaptcha），captchaVerifyParam 禁止修改原文写 wk_captcha_verify_param + 90 秒时效（F019）到期回退；embed（默认，.wk-gate-embed 挂载点内 #wk-captcha-element-<gate>，与天御同一锚点）/popup（零占位）两形态可配（04 §2 captcha.aliyun.mode）；gatekeeper.js 调度器零改动（frontend 元数据分派不认驱动 id）；④ 闸映射制度化——AbstractGate::canonical_token_key/extract_token_context 单点（wk_captcha_verify_param→captcha_verify_param；天御双域行为不变，C3 登录闸 D2 教训第三域扩展）；⑤ Tab② 阿里配置区——四字段（AccessKeyId/AccessKeySecret 均 password 型不回显 + SceneId 必填化 F012 防线 + prefix）+ mode 单选 + 合规行（privacy 维）+ 接入指引；「测试凭据」三态（绿=假参实调 VerifyCode=F002/F003；黄=必填缺不发起调用；红=InvalidAccessKeyId*/SignatureDoesNotMatch/403 Forbidden.NoPermission（文案指明补授 AliyunYundunAFSFullAccess+AuthAction）/403 Forbidden.AccountAccessDenied）；⑥ schema captcha.aliyun.mode 键（merge_defaults 老树回填，Sanitize 枚举 embed|popup 非法值归 embed）。997 tests / 7131 assertions 全绿（933 基线 + 64 新例：签名官方向量金测试/驱动三态与码表/闸映射/凭据三态/设置面）。

= 0.2.5 =

M3' 批次 2·验证码驱动接口升级 B2（2026-11-11，设计文档 v1.5 C1 三态契约 + DESIGN-C1 统筹裁决）：① CaptchaDriverInterface 升格 v1.2 冻结——verify_tri() 主通道（三态结构化返回：pass（score 归一化 (0,1]，二态驱动恒 1.0）/reject（业务不通过）/error（传输/语义/契约终态，meta.rule=remote_<category>|contract_mismatch+raw 快照必带））取代 verify():?string（接口内删除，天御 verify()/last_verify_meta() 保留为 legacy 垫片，闸层 method_exists 能力探测 + CaptchaTriInterpreter::legacy_to_tri 折算）；+errorPolicy() 声明（默认 'block'——天御零行为变化，pass_limited 留阿里按 SLA 评估）；② 共享件 CaptchaTriInterpreter（includes/gates/）——02 §2.1 v1.5 映射表单点实现（pass+score<钳后阈值→reject+user_retry 档 / error 态首纳 / error+pass_limited+限流内→事件 error+落地放行+不写 VerifyCache），三闸（评论/登录/注册/找回）captcha 段统一消费，**行为变化点 1：captcha 阶段远端故障事件 verdict 由 reject 改 error（统计纠偏——captcha_reject 不再混入故障量）**；DegradedPassLimiter 共享限流器（TokenBucket {reset,count} transient 桶，默认 5 次/IP/h，天御容灾 wp_disaster_limiter 委托共用同一形态、wk_dsr_ 键不变）；③ captcha.min_score schema 键（闸侧消费，钳 0.1–1.0，默认 0.5）+ registry scoring 维（天御 false）+ Tab② 派生控件（评分制驱动接入才显示）+ pass/reject 事件 meta 记 score+threshold；④ test_mode 演练旁路安全门五件套（02 §2.8）——显式开关默认关（Tab② 黄条+红字说明+二次确认复选框）/drill 事件标注+统计隔离（rule_hit=test_mode_pass、driver=gate、meta.drill 恒落库不计数）/Tab① 红点行+admin-bar 黄条（仅 manage_options）/24h 懒过期（test_mode_since+test_mode_expired 事件，on/off/expired 全程留痕）/旁路期不写 VerifyCache、预检不签发 TrustStub（关测后零免验残留窗）；**行为变化点 2：新增事件 rule_hit test_mode_pass/test_mode_on|off|expired/remote_transport_degraded**；⑤ 豁免过滤器 wink_gatekeeper_require_gate（Wordfence wordfence_ls_require_captcha 先例）——落三闸 captcha 阶段入口（渲染判定与验证判定共用一判），豁免=等同未配置驱动语义（不影响 LocalRules/云审链/登录计数器；XML-RPC 恒结构化拒绝不受豁免影响）；**行为变化点 3：02 §2.1「verify():?string 稳定主通道」条款作废（M4 重评已执行）**。933 tests / 6851 assertions 全绿（860 基线 + 73 新例：映射表金测试/限流器/懒过期/三闸三态+test_mode+豁免/schema 钳制）。

= 0.2.4 =

M3' 前置批次 1·登录/统计域 B1b（2026-11-11，设计文档 v1.5 F4-4/F4-5/F4-6 + WORDFENCE-ADOPTION B2/B3 + P2-3）：① F4-4+F4-6 同根因合并修——账户闸 recorder 口径统一（02 §3.2a：captcha 判定收口按 verdict 三维 bump（pass→captcha_pass、reject→reject+captcha_reject、error→error），计数与落库解耦；drill 行不计数；真机复核 gate=login reject 行与 verify_cache_hit pass 行均落库——写入矩阵无第二层缺口）；by_gate 扩 attempts/captcha_pass/captcha_reject 三维（04 §4 v1.5），登录失败尝试数经 wp_login_failed 路径直记（empty_* 码不计）；Tab⑥「账户保护」分区四维展示（今日/累计 × 调用/通过/拦截 + 登录失败尝试，调用=captcha_pass+captcha_reject+error，register/lostpassword 无 attempts 显示 0）；② F4-5 embed 容器同宽——登录/注册/找回页 .wk-gate-embed 与 wk-gate-login-status 同占表单内容宽（通用实现不绑厂商，min-width:min(300px,100%) 通用规格注记 v1.5 条款）；③ B2 登录错误掩码——WP 原生泄露存在性错误码（invalid_username/incorrect_password/invalid_email/invalidcombo——复现样本「Unknown email address. Check again or try the guide.」）在呈现层聚合输出处（wp_login_errors/lostpassword_errors filter）替换统一文案，只动文案不动内部错误码（F39 双信号分类零影响），filter wink_gatekeeper_login_error_mask_enabled 默认开可关；④ B3 认证失败随机 sleep——LoginGate 失败分支（wp-authenticate 压轴拒绝与 XML-RPC 拒绝）随机延迟 0–2000ms（时序混淆防用户名枚举侧信道；filter wink_gatekeeper_login_fail_sleep_enabled 默认开，量级注记见 02 §3.2）；⑤ P2-3 半——AccountFormGate 访客可见证据行时间 date()→wp_date()（B1a 同款守卫）。

= 0.2.3 =

M3’ 前置批次 1·评论域 B1a（2026-11-11，设计文档 v1.5 F4-1/F4-2/F4-3 + WORDFENCE-ADOPTION B1 + P2-3）：① F4-1 免验轻提示——信用免验（pass_source=trust/verify_cache）命中时三态单按钮差异化文案「老朋友，免验放行」（wkGateL10n 键 wk_trust_hint，aria-live 随按钮播报，区别于真验通过的「已通过验证」）；② F4-2 内联错误条三清除时机（.wk-gate-msg）——重新预检通过（trust_pass/captcha_needed 两成功分支）/评论框·昵称·邮箱 input 防抖 300ms 清除/TrustStub 前端 TTL 过期回退同拍清除（清除调度集中 scheduleMsgClear/cancelMsgClear，showMsgBar 取消未决清除）；③ F4-3 拦截页证据行本地化——「命中规则」对云端驱动 Label,Score 做人类可读映射（驱动人类名前缀 + 标签中文 + 置信，「云端文本审核（腾讯云 TMS 文本审核）：辱骂违规 · 置信 100」式；映射表落点 Copywork::cloud_labels() 键 wk-label-<Label> + Tab⑤ 只读预览 + wink_gatekeeper_error_messages filter 覆盖文案；未收录标签原样兜底、nonLabel 不映射、LocalRules 键与 {rule} 键名替换不放宽）；④ B1 站长自定义拦截页文案——copy.die_page_title/die_page_message 新键（Tab⑤「拦截页自定义」分组，空值=回落场景默认文案，占位符 {event_no}/{rule}/{time}/{contact_email} 同享，schema/sanitize/Tab⑤ 三处同步）；⑤ P2-3——CommentGate 访客可见证据行时间 date()→wp_date()（站点时区，Tc3Signer gmdate 不动）。819 tests / 3657 assertions 全绿。

= 0.2.2 =

M2 增补轮·批次 3（2026-11-10，设计文档 v1.4 R3/F39-F41/D17）：登录域重构——① LoginCounters 双计数器重构（键 A′ 纯用户名键跨 IP 同账号累计（阈 user_threshold 默认 3，堵分布式爆破盲区）+ 键 B IP 聚合键（阈 10）+ 站点级账号警报 wk_lf_alarm（任一用户名维度饱和期间全站登录页出码）；双信号真实用户过滤（wp_authenticate_user 捕获钩子 + wp_login_failed 错误码分类——unknown 用户名只计 IP，旧 v1.2 洪泛机制 max_user_keys/索引键整体删除）；② 登录页嵌入式（wk-gate-login-status 播报位 + 驱动 embed render 直接注入表单，tencent-login.js「提交时唤起」链退役删除；登录页资产与评论页同一套 gatekeeper.js/tencent.js/front-gate.css；渲染⇔验证不变式：展示过恒验票；LoginIpRep 三态联动载体；XML-RPC 触发集扩展 over∨iprep）；③ 注册闸/找回密码闸常显（RegisterGate registration_errors filter 返回型 + LostPasswordGate lostpassword_post action 突变型，失败一律 WP_Error 注入 wp-login 原生错误框不 wp_die；AccountFormGate 公共基类——credentialFields 规范键映射 D2 教训制度化）；④ schema v1.4 迁移（threshold→user_threshold 平移、max_user_keys/fail_mode 丢弃、chain 默认空、gates.register/lostpassword 等新键读时补齐）；Tab② 登录高级区（user_threshold/window/ip_threshold/iprep_gate）+ Tab⑥ 账户保护分区扩 register/lostpassword。782 tests / 3476 assertions 全绿（含真机 Fatal 缺陷契约回归 2 例）。**发布说明补记（B7 收线 2026-11-11 补，交接遗留①闭环）**：本轮批次 3 收尾真机部署时抓得**登录页渲染期 Fatal**（三闸多态缺法——单测全绿真机炸的典型案例），已以 AccountFormGate 抽象化（公共基类，见上句③）编译期封口修复，部署验证通过；凡从 0.1.x/0.2.1 及以前升级的用户务必升级至 0.2.2 及以上以获得该修复。

= 0.2.1 =

M2 增补轮·批次 2（2026-11-10，设计文档 v1.4 F38/D16）：评论闸「信用免验制」全链——点击「完成人机验证」预检门（admin-ajax 端点：nonce+存根+30/h 限流三门禁 → 本地四闸草稿判定 → IP 信誉三态（IpReputationService 骨架 M2.5=未启用跳过）→ 历史信用 COUNT（同昵称+邮箱或登录 user_id 已批 ≥N，默认 3；布尔缓存 5min+审批即时失效））全过免真实验证码签发 TrustStub（10min/IP+表单+身份三重绑定）；提交编排四闸前移到 captcha 之前（四闸 reject 零验票调用）与 captcha 阶段免验三来源（trust → 验证短缓存 → 真验票，事件 pass_source 三来源口径）；IP 信誉 fail 档评论闸改「强制验证码、过码放行」（不再整页拦截）；Tab② 信用免验折叠区（五键+IP 信誉三态说明块+去 Tab④ 配置引导）。

= 0.2.0 =

M2 增补轮·批次 1（2026-11-10，设计文档 v1.4）：错误可观测性体系（meta.raw 脱敏快照/错误事件 rule_hit=remote_<category>/Tab⑥ 今日故障卡与错误信息列/Tab① 健康三态与最近错误摘要）、待审收口留痕（meta.source=cloud|cloud_all_failed——三种待审可区分）、停转进入/解除事件留痕、Tab⑥ 驱动行并入 usage 双口径、Tab③ 云端审核单选下拉+逐驱动合规行（registry privacy 第七维）、「账户保护」分区更名、文案改词两处（完成人机验证/无中文评论拦截）、schema v1.4 迁移（threshold→user_threshold、fail_mode 作废、chain 默认空）。

= 0.1.0 =

* M1 评论验证 MVP：腾讯天御 2.0 驱动（TC3-HMAC-SHA256 签名金测试 + CaptchaCode 错误映射含 trerror 容灾票）、传输脊 RemoteClient（四类错误分类/指数退避重试/出站红线/计量/脱敏）、LocalRules 四闸（非法正则跳过）、CommentGate 编排（存根三态/REST 封堵/强制验证码标志条款预留）、IpResolver 三模式 + TrustedProxies（Cloudflare 段快照）、EventId + 事件写入矩阵 + StatsCache 统计 v1 + 用量计数、wp_die 品牌拦截页、文案工坊 16 输入 + 恢复默认、前台 gatekeeper.js 调度器 + 天御适配器（官方要求动态加载）。
* M0 脚手架：插件骨架与激活流程（事件表 dbDelta 建表、默认 option、每日维护 cron）。
* 设置框架：单 option `wink_gatekeeper_settings` + 7 Tab 聚合器（总览/验证码/内容审核/IP 信誉/文案工坊/统计/工具）。
* wink 品牌视觉：Night 深色页头、眨眼 logo 动效（4.2s 空闲/悬停/输入停眨/prefers-reduced-motion 降级）。
* 核心纯逻辑层就绪：Verdict 值对象、GateRegistry、DriverRegistry（六维元数据）、SettingsSchema、Sanitize、Copywork、EventsTable DDL。
