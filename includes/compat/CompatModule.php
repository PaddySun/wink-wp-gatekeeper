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
}
