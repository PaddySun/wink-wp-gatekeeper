<?php
/**
 * 统一兼容层 · 模块契约接口（【W6-A】施工图 docs/W6A-统一兼容层设计定稿-2026-11-21.md §2）。
 *
 * 每个兼容目标一个模块类实现本接口：内聚「特征检查（detect）+ 呈现描述（文案四件）」，
 * registry（CompatRegistry）单点注册——散点探测（Bootstrap/SettingsPage 别处探测）=
 * 设计失败（W6 草案 §7 拍板 ③）。
 *
 * 契约要点（定稿 §2，三条裁决）：
 *   1. 文案方法**直返译文**——模块内 `__( 'EN', 'wink-wp-gatekeeper' )` 键化直返：
 *      make-pot 只抽取源码 gettext 调用形态，裸字符串 return 抽不进 pot
 *      （I4c「pot 活键 ⊆ po 键」闭环钉的前置）。
 *   2. `tag()` 为任务书五方法外的加法：Tab⑧ switch-row 行形态契约=「标题 + sw-tag
 *      域签 + 徽标 + why 文案」四件（W3 批 B 定形），层驱动渲染须提供域签数据件保形。
 *   3. 模块无状态：final class 无属性无构造依赖，registry 每次现造实例；detect()
 *      结果零缓存零存储（甲档红线 R3——定稿 §4）。
 *
 * @package WinkGatekeeper\Compat
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Compat;

// ABSPATH 纵深守卫（S2-F1 纪律）用 \defined **全限定**形态：本命名空间在测试语境有
// defined() 命名空间桩（tests/bootstrap.php 桩族——模块 detect() 的可编程探测缝），
// 非全限定会被桩遮蔽导致守卫误判；生产语境命名空间回退 PHP 全局内建，语义全等
// （遮蔽/穿透两态已在 PHP 8.3.36 施工前置探针实测）。
if ( ! \defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（类文件直访零执行，守卫为纵深面）。
}

interface CompatModule {

	/**
	 * 稳定注册键（registry 去重/闭集核对用；小写无空格，跨批次永不改值）。
	 *
	 * @return string
	 */
	public function key(): string;

	/**
	 * 目标显示名（专有名词；模块内 gettext 键化直返）。
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * 能力域标签（sw-tag 形态一行短词，标「对方是什么」非「我们做什么」）。
	 *
	 * @return string
	 */
	public function tag(): string;

	/**
	 * 探测：多路或集只读原语（≥2 路防单点，逐路源码锚点实证——定稿 §6 判据表）。
	 * 渲染期现算现显：零缓存、零存储、零外呼（甲档红线 R2/R3）。
	 *
	 * @return bool
	 */
	public function detect(): bool;

	/**
	 * 探测到时的呈现文案（整段；诚实口径=只描述在场与合成关系，零未实装行为承诺——R6）。
	 *
	 * @return string
	 */
	public function status_copy(): string;

	/**
	 * 未探测到的呈现文案（如实「无交互+将来自动亮行」语义——不冒充实装）。
	 *
	 * @return string
	 */
	public function absent_copy(): string;

	/**
	 * 应对行为腿声明（【W6-A2】行为腿契约——定稿 docs/W6A-统一兼容层设计定稿-2026-11-21.md
	 * §15：甲档→甲+乙档演进，用户拍板 2026-11-21「统一兼容层要承载应对行为」）。
	 *
	 * 形状：array<int, array{hook: string, callback: callable, priority: int, accepted_args: int}>
	 * ——每项=一个**第三方暴露的应对 filter** 挂载规格。空数组=纯呈现模块（本批四模块中
	 * 仅 FluentComments 非空：官方预留加白 filter 白名单回注——钩子名/回调字面量单点登记
	 * 在其模块文件，R5 扩展钉）。挂载由 CompatRegistry::mount_behaviors() 统一执行：
	 * detect() 为真才挂（目标未装=零动作）；
	 * 回调可命名性由模块自证（R5 扩展——回调方法名字面量+回注项字符串仅模块文件命中）。
	 * 本方法=声明面：模块内零 add_filter 调用（层挂载点唯一=registry mount——R5 扩展钉）。
	 * 层挂载钩子闭集红线：只挂第三方应对 filter，WP 核心/判定链钩子零挂载（§15.4）。
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function behaviors(): array;

	/**
	 * 存根降级声明（【W6-A3】接口第八方法——统一层第二行为件，定稿 §16）。
	 *
	 * true = 本模块宿主**接管了评论提交管道**且该管道**无法呈现挑战 UI**（其自绘表单
	 * 永无本插件的 wk_stub 存根字段）——经该管道的提交，闸的挑战段（存根三态+验票）
	 * 降级放行至后续判定链（限流/IP 信誉/本地四闸/云审照常全跑）。false = 纯呈现模块
	 * （无管道接管，挑战语义零涉入）。
	 *
	 * 真机依据（W64 终验）：FluentComments 表单永远没有 wk_stub 存根字段（挑战在其
	 * UI 无法呈现）→ 闸存根三态对 FC 访客提交恒 false → bad-signature 拦死全部提交。
	 * 用户拍板 2026-11-21「AJAX 分支降级为存根」。安全取舍（用户裁决接受）：针对该
	 * 管道端点的定向提交可绕过挑战层，由内容闸+IP 信誉+限流兜底——设计定稿 §16 明示。
	 *
	 * 本方法=静态能力声明（零请求读取）；「当前请求是否正经由该管道」由
	 * request_in_pipeline()（第九方法）承载，复合判定在 CompatRegistry::stub_degrade_active()
	 * 单点收口（模块声明 真+detect 真+请求判据 真）。
	 *
	 * @return bool
	 */
	public function stub_degrade(): bool;

	/**
	 * 管道判据（【W6-A3】接口第九方法——请求参数级只读判据声明面，定稿 §16）。
	 *
	 * 当前请求是否正经由本模块宿主的提交管道（只读判据面：$_SERVER/$_POST 参数级，
	 * 零写入零外呼零存储——R2/R3 口径同 detect()）。无自有管道的模块恒 false
	 * （三呈现模块零超全局读取面）。判据字面量（如第三方 action 名）单点登记在
	 * 模块文件内（R5 扩展钉——散点判据=设计失败）；全限定 \defined 守卫纪律同模块
	 * 探测原语（非全限定 defined() 在测试语境可被命名空间桩遮蔽——§13 桩族）。
	 *
	 * @return bool
	 */
	public function request_in_pipeline(): bool;
}
