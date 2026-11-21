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
 *   - self::stub_degrade_active()（【W6-A3】存根降级复合查询——gate 经注入缝默认闭包
 *     消费，判定链零模块字面量）；
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

	/**
	 * 存根降级复合查询（【W6-A3】——统一层第二行为件的单点收口，定稿 §16）。
	 *
	 * 遍历注册闭集：模块 stub_degrade() 真（能力声明——接口第八方法）+ detect() 真
	 * （目标在场）+ request_in_pipeline() 真（当前请求正经由其提交管道——接口第九
	 * 方法请求参数级只读判据）三者全命中 → 返回该模块 key；否则 ''（空串=无降级）。
	 *
	 * 返回形状为何是 key 而非 bool：闸侧要把「谁降级」记进事件旁证 meta
	 * （compat_stub_degraded=<模块 key>）——key 经本查询运行时单点来源，闸内零模块
	 * 字面量（R5 gate 面：CommentGate 零 FC 字面量）。非空串即真（bool 超集语义）。
	 *
	 * 消费方：CommentGate 存根阶段（挑战段降级判定——注入缝默认闭包，WP 边界）；
	 * CompatLayerTest（复合判定矩阵钉）。判据零写入零外呼零存储（R2/R3——请求级
	 * 现算现判，探测原语纪律同 detect()）。首命中即返回（闭集序=渲染序，多模块
	 * 同时命中时取序首——现闭集仅 FluentComments 声明降级能力，序首语义为将来
	 * 多管道模块预留）。
	 *
	 * @return string 降级模块 key（如 'fluentcomments'）；''=无降级（挑战语义零变化）。
	 */
	public static function stub_degrade_active(): string {
		foreach ( self::all() as $module ) {
			if ( $module->stub_degrade() && $module->detect() && $module->request_in_pipeline() ) {
				return $module->key();
			}
		}
		return '';
	}
}
