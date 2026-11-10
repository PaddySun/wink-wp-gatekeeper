<?php
/**
 * 验证码驱动接口（02 §2.1：是不是人）。
 *
 * 契约要点（v1.2 全量）：
 *   - verify 单次消费语义：一次请求内 verify() 只做一次远端核验；token 字段核验后
 *     从 $_POST unset（hCaptcha 互斥坑的机制性防御，拆解报告 A6）——unset 由闸边界执行；
 *   - 绝无 auto 模式（立项书红线 1）：所有驱动一律手动验证形态；
 *   - render 输出必须包含签名存根（02 §3.3，存根串由闸传入 $args）；
 *   - 未配置驱动行为：captcha.driver 为 null 或 is_configured()=false 时闸整体跳过
 *     captcha 阶段（等同存根 true 语义，不渲染组件不验证）+ 设置页红点告警；
 *   - 契约演进声明：verify(): ?string 为稳定主通道（R1 先具体后抽象）；富语义
 *     （Score 高风险 → 二次挑战等）走可选能力接口扩展（SupportsRiskSignalInterface 预留）；
 *   - 前端形态适配：驱动在 registry 声明 frontend {interaction, token_fields}，
 *     gatekeeper.js 按 frontend 元数据分派、不认驱动 id（03 §2.1）。
 *
 * M1 实现注：verify 返回的错误文案为原文（驱动层纯逻辑零 WP 依赖、无 esc_html），
 * 输出层（DiePage/REST/WP_Error 路径）负责转义——契约「可直接输出」语义在边界层闭合。
 *
 * @package WinkGatekeeper\Drivers
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Drivers;

interface CaptchaDriverInterface {

	/**
	 * 渲染验证码组件（含驱动自身所需的隐藏字段；签名存根串由闸经 $args 注入）。
	 *
	 * @param array<string, mixed> $args {gate, action, context_id, stub}。
	 * @return string HTML 片段（属性转义在驱动内完成）。
	 */
	public function render( array $args ): string;

	/**
	 * 服务端核验本次提交。null = 通过；非 null = 错误文案（输出层转义后展示）。
	 *
	 * 契约语义沿用 hCaptcha API::verify 最小契约（拆解报告 §4.6）。
	 *
	 * @param array<string, mixed> $context {ticket, randstr, user_ip, …开放键集}。
	 * @return string|null
	 */
	public function verify( array $context ): ?string;

	/**
	 * 该驱动是否完成配置（密钥齐全）——未配置的驱动不出现在设置页可选项。
	 */
	public function is_configured(): bool;

	/**
	 * 前端资源注册回调，由闸在渲染期统一挂载（WP 边界方法）。
	 */
	public function enqueue_assets(): void;

	// ---- v1.1 冻结新增五方法（02 §2.1【v1.3】采纳 O1 §二 2.1；M2 开工前冻结，防 M4 驱动矩阵返工）----
	// PHP 7.4 无接口默认实现：驱动直接实现（本插件 M1+ 仅一个验证码驱动，无中间抽象层必要）。

	/**
	 * 驱动唯一 id——registry 键、事件表 driver 字段、分驱动统计键（04 §4 driver:{id}）
	 * 的单一来源。闸消费本方法填 Verdict::driver_id（统计键）。
	 */
	public function id(): string;

	/**
	 * 驱动显示名——设置页下拉项/配置区标题；registry label 为注册默认值，方法为运行时权威。
	 */
	public function label(): string;

	/**
	 * 驱动自声明凭据（token）字段名——POST 域名全集（天御 ['wk_captcha_ticket',
	 * 'wk_captcha_randstr'] vs hCaptcha ['h-captcha-response']）。闸不再硬编码驱动字段：
	 * registry frontend.token_fields 由此派生（v1.3：派生关系取代 v1.2 的手工双声明）；
	 * 闸边界的 token unset（A6 单次消费）按本清单统一清理。
	 *
	 * @return string[]
	 */
	public function credentialFields(): array;

	/**
	 * 错误码语义分级声明（「响应形态归闸、错误语义归驱动」——02 §2.1 v1.3）：
	 * ['user_retry'=>[码集], 'config_error'=>[码集], 'pass'=>[码集]]——失败响应的「形态」
	 * （头协议/WP_Error/wp_die，02 §3.5）归闸统一分派；「语义」（哪个码=用户可重试/
	 * 站点配置错/按通过）由驱动声明，闸按本声明决定文案档与是否当日停转。
	 *
	 * @return array<string, int[]>
	 */
	public function failurePolicy(): array;

	/**
	 * 容灾票据策略：'allow'（默认：网络差放行 + 限速 + 事件标注 rule:trerror）| 'block'
	 * （安全优先拦截）| 'none'（无容灾票概念的驱动）——站长开关 04 §2 captcha.tencent.disaster_pass。
	 */
	public function disasterPolicy(): string;
}
