<?php
/**
 * 验证码驱动接口（02 §2.1：是不是人）。
 *
 * 契约要点（【v1.5】接口 v1.2 冻结——DESIGN-C1 拍板形态 A；终形方法清单：
 *   render / verify_tri / is_configured / enqueue_assets / id / label / credentialFields /
 *   failurePolicy / disasterPolicy / errorPolicy）：
 *   - verify_tri 主通道三态核验（取代旧 verify(): ?string——02 §2.1 契约演进声明预定的
 *     M4 重评已执行；旧 verify() 接口内删除，闸层垫片过渡兼容，见 verify_tri docblock 与
 *     Gates\CaptchaTriInterpreter::legacy_to_tri）；
 *   - verify 单次消费语义：一次请求内只做一次远端核验；token 字段核验后从 $_POST unset
 *     （hCaptcha 互斥坑的机制性防御，拆解报告 A6）——unset 由闸边界执行；
 *   - 绝无 auto 模式（立项书红线 1）：所有驱动一律手动验证形态；
 *   - render 输出必须包含签名存根（02 §3.3，存根串由闸传入 $args）；
 *   - 未配置驱动行为：captcha.driver 为 null 或 is_configured()=false 时闸整体跳过
 *     captcha 阶段（等同存根 true 语义，不渲染组件不验证）+ 设置页红点告警；
 *   - 前端形态适配：驱动在 registry 声明 frontend {interaction, token_fields} +
 *     可选 scoring 维（05 §4——Tab② min_score 控件显隐派生），gatekeeper.js 按
 *     frontend 元数据分派、不认驱动 id（03 §2.1）。
 *
 * M1 实现注：verify_tri 的 message 为原文（驱动层纯逻辑零 WP 依赖、无 esc_html），
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
	 * 【v1.5 冻结（DESIGN-C1 §2.1，拍板形态 A）——主通道】三态核验，取代 verify(): ?string。
	 *
	 * 三态语义（Wordfence captcha.php:98-115 三态的显式化）：
	 *   state=pass    链路正常+判定通过。score 必带（归一化 0<p<=1；二态驱动恒 1.0）。
	 *   state=reject  链路正常+业务不通过（票无效/风控拒绝）。message=输出层文案（已 esc_html
	 *                 语义的原文——边界层转义），分档语义仍由 failurePolicy() 声明。
	 *   state=error   服务不可达（传输/语义/契约终态）。message=降级文案；meta 必带
	 *                 rule=remote_<category>|contract_mismatch 与 raw 快照（F44 原样）。
	 *
	 * score 归一化契约：评分制驱动必须把厂商分折算到 (0,1] 开区间；0 分/解析不出分
	 * =按 reject（业务不通过）处理，不得以 0.0 表达任何态（0.0 永不出现在本契约）。
	 *
	 * @param array<string, mixed> $context 规范键集 {ticket, randstr, user_ip, captcha_verify_param, …}
	 *                                      ——规范键契约沿 credentialFields() 派生（反馈1补注条款）。
	 * @return array{state: string, score: float|null, message: string, meta: array<string, mixed>}
	 */
	public function verify_tri( array $context ): array;

	/**
	 * 该驱动是否完成配置（密钥齐全）——未配置的驱动不出现在设置页可选项。
	 */
	public function is_configured(): bool;

	/**
	 * 前端资源注册回调，由闸在渲染期统一挂载（WP 边界方法）。
	 */
	public function enqueue_assets(): void;

	// ---- v1.1 冻结五方法（【v1.3】采纳 O1 §二 2.1）----
	// ---- 【v1.5·C1】接口升格 v1.2 冻结：+verify_tri 主通道 / +errorPolicy / −verify（主通道位）。
	//      last_verify_meta() 降级为内部实现细节（meta 已入 verify_tri 返回值；保留方法仅为
	//      闸层 legacy 垫片消费，M4 收线评估删除）。----

	/**
	 * 驱动唯一 id——registry 键、事件表 driver 字段、分驱动统计键（04 §4 `driver:{id}`）
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
	 * 闸边界的 token unset（A6 单次消费）按本清单统一清理；同时是驱动侧 context 规范键
	 * 契约（闸把表单域映射为规范键后传入 verify_tri——C3 登录闸漏抄映射的 D2 教训制度化）。
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

	/**
	 * 【v1.5 冻结（DESIGN-C1 §2.5）】error 态处置策略声明（02 §5 captcha 行的驱动侧例外位）：
	 *   'block'（默认）=故障即拦（现行降级矩阵，天御沿用——D6 零行为变化）；
	 *   'pass_limited'=仅 transport 类终态放行 + 每 IP TokenBucket 限流（默认 5 次/IP/小时，
	 *   Gates\DegradedPassLimiter 共享件——天御 disaster 与本态两个消费方共用）+
	 *   事件 verdict=error、rule_hit=remote_transport_degraded + 不写 VerifyCache；
	 *   semantic/contract/parse 终态恒 block（配置错放行=自废武功）。
	 * 站长覆盖键 captcha.error_policy（04 §2，闸侧消费——非法值/缺省回落本声明）；限流超额按 block。
	 *
	 * @return string
	 */
	public function errorPolicy(): string;
}
