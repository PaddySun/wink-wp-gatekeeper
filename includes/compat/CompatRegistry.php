<?php
/**
 * 统一兼容层 · 静态注册表（【W6-A】施工图 docs/W6A-统一兼容层设计定稿-2026-11-21.md §3）。
 *
 * 单点登记：每兼容目标一个模块文件（includes/compat/<Target>.php 实现 CompatModule）
 * + 本表 all() 数组追加一行——**目标=1 新文件+1 registry 行**（扩展指引定稿 §3）。
 * 不动 SettingsPage（渲染循环消费 all() 零逐目标代码）、不动 Bootstrap（层与判定链
 * 零接触——红线 R1）、不动 schema/sanitize（呈现级零新键——红线 R4）。
 *
 * 显式数组注册（非发现式加载）：目录扫描/反射发现=隐式注册，违背「单点登记」可读性。
 * 返回序=渲染序（Tab⑧ 共存卡检测行）。
 *
 * @package WinkGatekeeper\Compat
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Compat;

if ( ! \defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（S2-F1 纵深加固——类文件直访零执行，守卫为纵深面）。
}

final class CompatRegistry {

	/**
	 * 注册闭集（W6-A 固定四目标；新增目标=新模块文件+此处一行）。
	 *
	 * 消费方（闭集核对）：
	 *   - SettingsPage::compat_detection_rows()（Tab⑧ 共存卡检测行渲染——唯一运行时消费方）；
	 *   - tests/CompatLayerTest.php（闭集/契约/红线钉）。
	 *
	 * @return array<int, CompatModule> 模块实例集（每次现造——模块无状态零缓存）。
	 */
	public static function all(): array {
		return array(
			new Akismet(),
			new Wordfence(),
			new FluentComments(),
			new WpArmour(),
		);
	}
}
