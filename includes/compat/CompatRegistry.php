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
	 *   - SettingsPage::compat_detection_rows()（Tab⑧ 共存卡检测行渲染——呈现面消费方）；
	 *   - self::mount_behaviors()（【W6-A2】行为腿统一挂载——生命周期消费方）；
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

	/**
	 * 行为腿挂载排程（【W6-A2】——层唯一生命周期入口，由插件入口文件装配根调用一次）。
	 *
	 * 只排程不判定：plugins_loaded 时全部插件主文件已执行（detect 原语=常量/类/函数
	 * 可靠性面；回避插件装载字母序依赖）——mount_behaviors 再逐模块 detect()+behaviors()
	 * 判定。检测每请求现算一次零存储（R3 口径不变）。
	 *
	 * @return void
	 */
	public static function mount(): void {
		add_action( 'plugins_loaded', array( self::class, 'mount_behaviors' ), 10, 0 );
	}

	/**
	 * 行为腿统一挂载（plugins_loaded 期执行——定稿 §15.3）。
	 *
	 * 激活条件（任务书）：behaviors() 空数组=纯呈现模块零动作短路；detect() 为假=目标
	 * 未装零动作；两者皆过才按规格挂载（规格防御校验：hook 非空字符串/callable/整数，
	 * 缺省回落 priority 10 / accepted_args 1）。层挂载钩子闭集红线：只挂第三方暴露的
	 * 应对 filter（本批恰一个——钩子名见模块声明），WP 核心/判定链
	 * 钩子零挂载（§15.4 修订 R1）。具体应对钩子名的字面量单点登记在各模块文件（R5 扩展钉
	 * ——本挂载点只消费 behaviors() 规格，零硬编码钩子名）。
	 *
	 * @return void
	 */
	public static function mount_behaviors(): void {
		foreach ( self::all() as $module ) {
			$behaviors = $module->behaviors();
			if ( array() === $behaviors || ! $module->detect() ) {
				continue; // 纯呈现模块零动作 / 目标未装零动作（激活条件）。
			}
			foreach ( $behaviors as $behavior ) {
				$hook      = isset( $behavior['hook'] ) && is_string( $behavior['hook'] ) ? $behavior['hook'] : '';
				$callback  = isset( $behavior['callback'] ) ? $behavior['callback'] : null;
				$priority  = isset( $behavior['priority'] ) && is_int( $behavior['priority'] ) ? $behavior['priority'] : 10;
				$arguments = isset( $behavior['accepted_args'] ) && is_int( $behavior['accepted_args'] ) ? $behavior['accepted_args'] : 1;
				if ( '' === $hook || ! is_callable( $callback ) ) {
					continue; // 规格非法零挂载（防御性——坏规格静默跳过不致命）。
				}
				add_filter( $hook, $callback, $priority, $arguments );
			}
		}
	}
}
