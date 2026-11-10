=== Wink 门口保安 ===
Contributors: wink
Tags: comments, spam, captcha, security, login
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.3.1
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
