=== Wink 门口保安 ===
Contributors: wink
Tags: comments, spam, captcha, security, login
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.2.3
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

= 0.2.3 =

M3’ 前置批次 1·评论域 B1a（2026-11-11，设计文档 v1.5 F4-1/F4-2/F4-3 + WORDFENCE-ADOPTION B1 + P2-3）：① F4-1 免验轻提示——信用免验（pass_source=trust/verify_cache）命中时三态单按钮差异化文案「老朋友，免验放行」（wkGateL10n 键 wk_trust_hint，aria-live 随按钮播报，区别于真验通过的「已通过验证」）；② F4-2 内联错误条三清除时机（.wk-gate-msg）——重新预检通过（trust_pass/captcha_needed 两成功分支）/评论框·昵称·邮箱 input 防抖 300ms 清除/TrustStub 前端 TTL 过期回退同拍清除（清除调度集中 scheduleMsgClear/cancelMsgClear，showMsgBar 取消未决清除）；③ F4-3 拦截页证据行本地化——「命中规则」对云端驱动 Label,Score 做人类可读映射（驱动人类名前缀 + 标签中文 + 置信，「云端文本审核（腾讯云 TMS 文本审核）：辱骂违规 · 置信 100」式；映射表落点 Copywork::cloud_labels() 键 wk-label-<Label> + Tab⑤ 只读预览 + wink_gatekeeper_error_messages filter 覆盖文案；未收录标签原样兜底、nonLabel 不映射、LocalRules 键与 {rule} 键名替换不放宽）；④ B1 站长自定义拦截页文案——copy.die_page_title/die_page_message 新键（Tab⑤「拦截页自定义」分组，空值=回落场景默认文案，占位符 {event_no}/{rule}/{time}/{contact_email} 同享，schema/sanitize/Tab⑤ 三处同步）；⑤ P2-3——CommentGate 访客可见证据行时间 date()→wp_date()（站点时区，Tc3Signer gmdate 不动）。819 tests / 3657 assertions 全绿。

= 0.2.2 =

M2 增补轮·批次 3（2026-11-10，设计文档 v1.4 R3/F39-F41/D17）：登录域重构——① LoginCounters 双计数器重构（键 A′ 纯用户名键跨 IP 同账号累计（阈 user_threshold 默认 3，堵分布式爆破盲区）+ 键 B IP 聚合键（阈 10）+ 站点级账号警报 wk_lf_alarm（任一用户名维度饱和期间全站登录页出码）；双信号真实用户过滤（wp_authenticate_user 捕获钩子 + wp_login_failed 错误码分类——unknown 用户名只计 IP，旧 v1.2 洪泛机制 max_user_keys/索引键整体删除）；② 登录页嵌入式（wk-gate-login-status 播报位 + 驱动 embed render 直接注入表单，tencent-login.js「提交时唤起」链退役删除；登录页资产与评论页同一套 gatekeeper.js/tencent.js/front-gate.css；渲染⇔验证不变式：展示过恒验票；LoginIpRep 三态联动载体；XML-RPC 触发集扩展 over∨iprep）；③ 注册闸/找回密码闸常显（RegisterGate registration_errors filter 返回型 + LostPasswordGate lostpassword_post action 突变型，失败一律 WP_Error 注入 wp-login 原生错误框不 wp_die；AccountFormGate 公共基类——credentialFields 规范键映射 D2 教训制度化）；④ schema v1.4 迁移（threshold→user_threshold 平移、max_user_keys/fail_mode 丢弃、chain 默认空、gates.register/lostpassword 等新键读时补齐）；Tab② 登录高级区（user_threshold/window/ip_threshold/iprep_gate）+ Tab⑥ 账户保护分区扩 register/lostpassword。782 tests / 3476 assertions 全绿（含真机 Fatal 缺陷契约回归 2 例）。

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
