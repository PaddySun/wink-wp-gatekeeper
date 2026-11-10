# wink-wp-gatekeeper

> 对真人眨眨眼，对机器人睁着眼。

WordPress 评论过滤 × 人机验证一体化插件，面向中文独立博客。验证码与云端内容审核能力驱动化可插拔（hCaptcha / Turnstile / 极验 / 腾讯天御 / 阿里云验证码；百度 / 阿里 / 腾讯云文本审核）。

**状态**：M0 + M1 已交付；M2「腾讯系走通」已交付；M2 增补轮已收线——批次 1 错误可观测性/待审收口/配置交互（0.2.0）、批次 2 评论闸信用免验制（F38：IpReputationService 骨架/TrustStub/预检端点/编排三来源免验，0.2.1，717 tests 全绿）、批次 3 R3 登录域重构（F39-F41：LoginCounters 纯用户名键双计数+站点级警报/登录页嵌入式（tencent-login.js 退役）/注册·找回密码常显闸+AccountFormGate 基类+schema v1.4 迁移，0.2.2，782 tests 全绿）。

- 立项书：`../docs/立项书.md`（外层 docs，不在本仓库内）
- 品牌：wink brand system v1.0（`assets/brand/`，源出 `C:\Users\paddy\Desktop\dev\蜜罐\old\wink-brand\wink-brand`）
- 血统：baby-wp-comment-filter（obaby / h4ck.org.cn）的评论过滤能力将以 LocalRules 驱动形式迁入
- 前置研究：hCaptcha for WP v5.5.0 拆解（钩子挂点、互斥红线、API 契约均已查证）
