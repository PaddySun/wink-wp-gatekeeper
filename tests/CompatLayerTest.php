<?php
/**
 * W6-A 批 · 可插拔统一兼容层契约测试（施工图
 * docs/W6A-统一兼容层设计定稿-2026-11-21.md §13——TDD 红先行回归门）。
 *
 * 七层断言面（定稿 §13.2）：
 *   ① registry 闭集：恰四模块 / key 闭集全等固定序 / 无重复 / 契约形状 / 无状态
 *      （all() 每次现造实例——零缓存）；
 *   ② 每模块 detect() 桩态两态矩阵（WinkGatekeeper\Compat 命名空间桩可控——
 *      bootstrap.php 桩族先例同款；判据逐路覆盖：常数腿/类腿/函数腿各自播种验真，
 *      逐路源码锚点见定稿 §6 判据表）；
 *   ③ 文案契约：status/absent 非空且互异 + 模块源文件 gettext 键化形态
 *      （恰 4 处 domain 尾缀=make-pot 可抽取——pot 活键⊆po 键闭环钉的前置）；
 *   ④ Tab⑧ 渲染消费层（render_tab_enhance 装配针 + compat_detection_rows 消费
 *      件针 + 检测行零 wk-plan-tag=规划徽标闭集防污染）；
 *   ⑤ 旧灰字文案零残留（两灰字规划行退役——label/sw-tag/why 五旧字面全文件零命中）；
 *   ⑥ hCaptcha DOM 锚原样 + 行序（hcaptcha 两实键行在层驱动行之前——定稿 §9 不迁移）；
 *   ⑦ 甲档红线钉（定稿 §4）：R1 判定链零感知（Bootstrap 零 Compat 引用）/ R2 探测
 *      只读（compat 六文件零写入原语零读取存储面）/ R5 判据单点登记（探测字面量
 *      全库仅对应模块文件命中——散点探测=设计失败）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Compat\CompatModule;
use WinkGatekeeper\Compat\CompatRegistry;

final class CompatLayerTest extends TestCase {

	protected function setUp(): void {
		CompatProbeStub::reset();
	}

	protected function tearDown(): void {
		CompatProbeStub::reset();
	}

	/* -----------------------------------------------------------------
	 * 源码提取件（W3TabRemodelTest 同款零件：src/sp/body/functionBody）
	 * ----------------------------------------------------------------- */

	private static function src( string $rel ): string {
		$src = file_get_contents( dirname( __DIR__ ) . '/' . $rel );
		self::assertIsString( $src, "{$rel} 必须可读" );
		return (string) $src;
	}

	private static function sp(): string {
		return self::src( 'includes/settings/SettingsPage.php' );
	}

	private static function functionBody( string $src, string $name ): string {
		$start = strpos( $src, 'function ' . $name );
		self::assertNotFalse( $start, "函数 {$name} 必须存在" );
		$open  = strpos( $src, '{', (int) $start );
		$depth = 0;
		$len   = strlen( $src );
		for ( $i = (int) $open; $i < $len; $i++ ) {
			if ( '{' === $src[ $i ] ) {
				$depth++;
			} elseif ( '}' === $src[ $i ] ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $src, (int) $open, $i - $open + 1 );
				}
			}
		}
		self::fail( "函数 {$name} 花括号不配平" );
		return '';
	}

	private static function body( string $name ): string {
		return self::functionBody( self::sp(), $name );
	}

	/* ================================================================
	 * ① registry 闭集（定稿 §3——单点登记，闭集=四模块）
	 * ================================================================ */

	public function test_registry_closed_set_four_modules(): void {
		$modules = CompatRegistry::all();
		self::assertCount( 4, $modules, 'registry 闭集=四模块（akismet/wordfence/fluentcomments/wparmour）' );
		self::assertSame(
			array( 'akismet', 'wordfence', 'fluentcomments', 'wparmour' ),
			array_map(
				static function ( CompatModule $m ): string {
					return $m->key();
				},
				$modules
			),
			'key 闭集全等+固定序（序=渲染序——定稿 §3）'
		);

		$seen = array();
		foreach ( $modules as $module ) {
			self::assertInstanceOf( CompatModule::class, $module, '每实例实现模块契约接口' );
			self::assertNotSame( '', $module->label(), "label 非空（{$module->key()}）" );
			self::assertNotSame( '', $module->tag(), "tag 非空（{$module->key()}）" );
			self::assertArrayNotHasKey( $module->key(), $seen, 'key 无重复（registry 去重语义）' );
			$seen[ $module->key() ] = true;
		}

		// 无状态无缓存：all() 每次现造实例（W6-A 定稿 §2 契约裁决 3）。
		$again = CompatRegistry::all();
		self::assertCount( 4, $again, '第二次调用同闭集' );
		self::assertNotSame( $modules[0], $again[0], 'all() 两次调用返回独立实例（registry 不持实例缓存）' );
	}

	/* ================================================================
	 * ② 每模块 detect() 桩态两态矩阵（判据逐路覆盖——定稿 §6 判据表）
	 * ================================================================ */

	public function test_akismet_detect_two_legs(): void {
		$module = new \WinkGatekeeper\Compat\Akismet();

		CompatProbeStub::$constants = array( 'AKISMET_VERSION' );
		self::assertTrue( $module->detect(), '常数腿在场即探测（akismet/akismet.php:42 锚）' );

		CompatProbeStub::reset();
		CompatProbeStub::$classes = array( 'Akismet' );
		self::assertTrue( $module->detect(), '类腿在场即探测（akismet/class.akismet.php:9 锚）' );

		CompatProbeStub::reset();
		CompatProbeStub::$classes = array( 'Akismet_Admin' );
		self::assertFalse( $module->detect(), '无关类在场不误报（判据字面全等语义）' );

		CompatProbeStub::reset();
		self::assertFalse( $module->detect(), '空桩=未探测（absent 态）' );
	}

	public function test_wordfence_detect_three_legs(): void {
		$module = new \WinkGatekeeper\Compat\Wordfence();

		CompatProbeStub::$constants = array( 'WORDFENCE_VERSION' );
		self::assertTrue( $module->detect(), '常数腿在场即探测（wf/wordfence.php:41 锚）' );

		CompatProbeStub::reset();
		CompatProbeStub::$classes = array( 'wordfence' );
		self::assertTrue( $module->detect(), '主类腿在场即探测（wf/lib/wordfenceClass.php:78 锚）' );

		CompatProbeStub::reset();
		CompatProbeStub::$classes = array( 'wfConfig' );
		self::assertTrue( $module->detect(), '配置类腿在场即探测（wf/lib/wfConfig.php:2 锚——分体加载形态防单点）' );

		CompatProbeStub::reset();
		self::assertFalse( $module->detect(), '空桩=未探测' );
	}

	public function test_fluentcomments_detect_two_legs(): void {
		$module = new \WinkGatekeeper\Compat\FluentComments();

		CompatProbeStub::$constants = array( 'FLUENT_COMMENTS_VERSION' );
		self::assertTrue( $module->detect(), '常数腿在场即探测（flc/fluent-comments.php:21 锚）' );

		CompatProbeStub::reset();
		CompatProbeStub::$classes = array( 'FluentCommentsPlugin' );
		self::assertTrue( $module->detect(), '主类腿在场即探测（flc/fluent-comments.php:23 锚）' );

		CompatProbeStub::reset();
		self::assertFalse( $module->detect(), '空桩=未探测' );
	}

	public function test_wparmour_detect_two_legs(): void {
		$module = new \WinkGatekeeper\Compat\WpArmour();

		CompatProbeStub::$functions = array( 'wpa_check_is_spam' );
		self::assertTrue( $module->detect(), '蜜罐判定函数腿在场即探测（wpa/includes/wpa_functions.php:136 锚）' );

		CompatProbeStub::reset();
		CompatProbeStub::$functions = array( 'wpa_load_scripts' );
		self::assertTrue( $module->detect(), '脚本装载函数腿在场即探测（wpa/includes/wpa_functions.php:4 锚）' );

		CompatProbeStub::reset();
		self::assertFalse( $module->detect(), '空桩=未探测' );
	}

	/* ================================================================
	 * ③ 文案契约（非空/互异/gettext 键化=make-pot 可抽取）
	 * ================================================================ */

	public function test_copy_contract_nonempty_and_distinct(): void {
		foreach ( CompatRegistry::all() as $module ) {
			self::assertNotSame( '', $module->status_copy(), "status 文案非空（{$module->key()}）" );
			self::assertNotSame( '', $module->absent_copy(), "absent 文案非空（{$module->key()}）" );
			self::assertNotSame( $module->status_copy(), $module->absent_copy(), "status/absent 两态文案互异（{$module->key()}）" );
		}
	}

	public function test_module_copy_gettext_keyed_exactly_four_per_module(): void {
		// 每模块源文件恰 4 处 gettext 调用（label/tag/status/absent 全键化——定稿 §2
		// 契约裁决 1：裸字符串 return 抽不进 pot，I4c「pot 活键⊆po 键」闭环会漏）。
		$map = array(
			'Akismet'         => 'includes/compat/Akismet.php',
			'Wordfence'       => 'includes/compat/Wordfence.php',
			'FluentComments'  => 'includes/compat/FluentComments.php',
			'WpArmour'        => 'includes/compat/WpArmour.php',
		);
		foreach ( $map as $module_name => $rel ) {
			$src = self::src( $rel );
			self::assertSame(
				4,
				substr_count( $src, ", 'wink-wp-gatekeeper' )" ),
				"{$module_name} 模块 gettext 键化恰 4 处（{$rel}）"
			);
			self::assertStringContainsString( 'declare( strict_types=1 )', $src, "{$module_name} strict_types 在场" );
			self::assertStringContainsString( 'if ( ! \\defined( \'ABSPATH\' ) )', $src, "{$module_name} ABSPATH 纵深守卫（全限定形态——命名空间桩穿透，S2-F1 纪律）" );
			self::assertStringContainsString( 'final class ' . $module_name, $src, "{$module_name} final class" );
		}
	}

	public function test_module_copy_en_fragments_match_catalog_source_of_truth(): void {
		// 文案 EN 源碎片钉（catalog 同源——改文案必须同步 catalog+i18n 链）。
		$fragments = array(
			'akismet'        => array( "__( 'Akismet',", 'Comment spam screening', 'Akismet is active and screens comment spam', 'No interaction. If Akismet is added later' ),
			'wordfence'      => array( "__( 'Wordfence',", 'Login captcha overlap', 'Wordfence is active.', 'No interaction. If Wordfence is added later' ),
			'fluentcomments' => array( "__( 'FluentComments',", 'AJAX comment form', 'FluentComments replaces the native comment form', 'No interaction. If FluentComments is added later' ),
			'wparmour'       => array( "__( 'WP Armour',", 'Honeypot anti-spam', "WP Armour\\'s honeypot guards comment submissions", 'No interaction. If WP Armour is added later' ),
		);
		$map = array(
			'akismet'        => 'includes/compat/Akismet.php',
			'wordfence'      => 'includes/compat/Wordfence.php',
			'fluentcomments' => 'includes/compat/FluentComments.php',
			'wparmour'       => 'includes/compat/WpArmour.php',
		);
		foreach ( $fragments as $key => $needles ) {
			$src = self::src( $map[ $key ] );
			foreach ( $needles as $needle ) {
				self::assertStringContainsString( $needle, $src, "{$key} 文案碎片在场（catalog 同源防漂移）：{$needle}" );
			}
		}
	}

	/* ================================================================
	 * ④ Tab⑧ 渲染消费层（定稿 §10）
	 * ================================================================ */

	public function test_tab8_render_consumes_compat_layer(): void {
		$enhance = self::body( 'render_tab_enhance' );

		self::assertStringContainsString(
			'self::compat_detection_rows()',
			$enhance,
			'检测行渲染装配针（render_tab_enhance 内——层驱动唯一入口）'
		);

		$rows = self::functionBody( self::sp(), 'compat_detection_rows' );
		self::assertStringContainsString( '\WinkGatekeeper\Compat\CompatRegistry::all()', $rows, '渲染循环消费 registry 闭集（单点登记）' );
		self::assertStringContainsString( '->detect()', $rows, '探测=渲染期现算现显（零缓存零落库——定稿 §4 R3）' );
		self::assertStringContainsString( 'badge b-ok', $rows, '探测到=badge b-ok（已检测形态）' );
		self::assertStringContainsString( 'badge b-dim', $rows, '未探测=badge b-dim 暗灰（不冒充实装——R6）' );
		self::assertStringContainsString( '->status_copy()', $rows, '探测到文案走模块 status' );
		self::assertStringContainsString( '->absent_copy()', $rows, '未探测文案走模块 absent' );
		self::assertStringNotContainsString( 'wk-plan-tag', $rows, '检测行零规划徽标类（规划徽标闭集防污染——W3TabRemodelTest 闭集钉的同伴约束）' );
		self::assertStringContainsString( 'Detected · coexists', $rows, '已检测徽标文案键（zh=已检测 · 共存）' );
		self::assertStringContainsString( 'Not installed', $rows, '未安装徽标文案键' );
	}

	public function test_tab8_plan_tag_closed_set_flips_to_four(): void {
		$enhance = self::body( 'render_tab_enhance' );
		self::assertSame(
			4,
			substr_count( $enhance, 'wk-plan-tag' ),
			'规划徽标闭集 6→4（RSS/REST/UA 三卡头 + xmlrpc 关总开关行——【W6-A】coexist 两灰字规划行退役）'
		);
		self::assertSame( 3, substr_count( $enhance, 'wk-card--ghost' ), '三规划卡虚线壳原样（RSS/REST/UA）' );
	}

	/* ================================================================
	 * ⑤ 旧灰字文案零残留（两灰字规划行退役——定稿 §10.1）
	 * ================================================================ */

	public function test_legacy_gray_rows_zero_residual(): void {
		$sp = self::sp();
		foreach ( array(
			'Akismet detected',                                      // 旧行 label
			'Wordfence detected',                                    // 旧行 label
			'Keyword gate deduplication',                            // 旧行 sw-tag
			'Login captcha exclusivity notice',                      // 旧行 sw-tag
			'When Akismet has already tossed the same spam into its queue', // 旧行 why
			'the detection row notes “who guards the login captcha”', // 旧行 why
			'Planned row — audit notes in docs/reports/DESIGN-AKISMET-AUDIT-2026-11-13.md', // 旧行 why 文档指引
		) as $stale ) {
			self::assertStringNotContainsString( $stale, $sp, "旧灰字零残留：{$stale}" );
		}
	}

	/* ================================================================
	 * ⑥ hCaptcha DOM 锚原样 + 行序（定稿 §9 不迁移）
	 * ================================================================ */

	public function test_hcaptcha_rows_untouched_and_rendered_before_compat_rows(): void {
		$enhance = self::body( 'render_tab_enhance' );

		self::assertStringContainsString( 'id="wk-coexist-card"', $enhance, '共存卡锚原样' );
		self::assertStringContainsString( "'coexist', 'hcaptcha_yield_comment'", $enhance, 'hCaptcha 实键一原样（行为键不进层）' );
		self::assertStringContainsString( "'coexist', 'hcaptcha_yield_login'", $enhance, 'hCaptcha 实键二原样' );

		$pos_hcaptcha   = strpos( $enhance, "'coexist', 'hcaptcha_yield_comment'" );
		$pos_compat_row = strpos( $enhance, 'self::compat_detection_rows()' );
		self::assertGreaterThan( 0, (int) $pos_hcaptcha, 'hCaptcha 行在场' );
		self::assertGreaterThan( 0, (int) $pos_compat_row, '层驱动行装配在场' );
		self::assertGreaterThan( $pos_hcaptcha, $pos_compat_row, '行序：hCaptcha 两实键行在层驱动检测行之前（DOM 锚原样——定稿 §9）' );
	}

	/* ================================================================
	 * ⑧ 行为腿契约（【W6-A2】定稿 §15——甲+乙档演进第一件：FluentComments
	 *    allow-list 回注本插件主判定腿；behaviors() 声明面 + registry mount 统一挂载）
	 * ================================================================ */

	/**
	 * 纯呈现模块 behaviors 闭集：三模块恒空数组（空数组=纯呈现零动作短路——定稿 §15.2）。
	 */
	public function test_behaviors_contract_presentation_modules_empty(): void {
		foreach ( CompatRegistry::all() as $module ) {
			if ( 'fluentcomments' === $module->key() ) {
				continue;
			}
			self::assertSame( array(), $module->behaviors(), "{$module->key()} 纯呈现模块 behaviors 必为空数组" );
		}
	}

	/**
	 * FluentComments 行为腿闭集：恰一项、规格四键形状全等、回调可命名（实例方法对——
	 * callbackName 归一化形态，定稿 §15.1 结论 a）。
	 */
	public function test_fluentcomments_behavior_spec_closed_set(): void {
		$module   = new \WinkGatekeeper\Compat\FluentComments();
		$behavior = $module->behaviors();
		self::assertCount( 1, $behavior, 'FluentComments 行为腿闭集=恰一项（白名单回注）' );

		$spec = $behavior[0];
		self::assertSame( 'fluent_comments/allowed_comment_hooks', $spec['hook'], '应对钩子名=FluentComments 官方预留加白 filter（CommentSubmission.php:264 锚）' );
		self::assertSame( 10, $spec['priority'], '挂载优先级 10（白名单成员序无关——in_array 语义）' );
		self::assertSame( 1, $spec['accepted_args'], '单参（\$allowed 白名单数组）' );
		self::assertSame( array( $module, 'allow_list_gate_preprocess' ), $spec['callback'], '回调=模块实例方法对（可命名形态——callbackName :321-338 get_class 分支）' );
	}

	/**
	 * 回注回调语义：默认白名单+本插件主判定腿全等追加 / 幂等 / 非数组透传 / 空数组可回注。
	 */
	public function test_allow_list_callback_appends_gate_entry_idempotent(): void {
		$module = new \WinkGatekeeper\Compat\FluentComments();
		$entry  = 'WinkGatekeeper\\Gates\\CommentGate::hook_preprocess_comment';

		$result = $module->allow_list_gate_preprocess( array( 'Akismet::auto_check_comment' ) );
		self::assertSame(
			array( 'Akismet::auto_check_comment', $entry ),
			$result,
			'回注语义=默认白名单（Akismet::auto_check_comment，CommentSubmission.php:264-266）+本插件主判定腿'
		);

		self::assertSame( $result, $module->allow_list_gate_preprocess( $result ), '重复回注零重复（in_array 幂等）' );
		self::assertSame( array( $entry ), $module->allow_list_gate_preprocess( array() ), '空数组可回注' );
		self::assertSame( 'garbage', $module->allow_list_gate_preprocess( 'garbage' ), '非数组输入原样透传（防御性——suppressForeignHooks 外层 (array) cast 兜底）' );
	}

	/**
	 * mount 只排程：plugins_loaded 延迟挂载（全插件装载后 detect 原语可靠——定稿 §15.3，
	 * 字母序装载依赖回避）。
	 */
	public function test_mount_defers_to_plugins_loaded(): void {
		\WinkGatekeeper\Compat\CompatRegistry::mount();
		self::assertCount( 1, CompatProbeStub::$actions, 'mount 恰排程一 action' );
		self::assertSame( 'plugins_loaded', CompatProbeStub::$actions[0]['hook'], '排程钩子=plugins_loaded' );
		self::assertSame( 10, CompatProbeStub::$actions[0]['priority'], '排程优先级 10' );
		self::assertSame( 0, CompatProbeStub::$actions[0]['accepted_args'], '零参回调' );
	}

	/**
	 * mount_behaviors detect 开态：FluentComments 在场=恰挂一 filter（规格全等透传）。
	 */
	public function test_mount_behaviors_detect_on_registers_exactly_one_filter(): void {
		CompatProbeStub::$constants = array( 'FLUENT_COMMENTS_VERSION' );
		\WinkGatekeeper\Compat\CompatRegistry::mount_behaviors();
		self::assertCount( 1, CompatProbeStub::$filters, 'detect 开=恰挂一 filter（激活条件——任务书）' );
		$rec = CompatProbeStub::$filters[0];
		self::assertSame( 'fluent_comments/allowed_comment_hooks', $rec['hook'], '挂载钩子=应对 filter' );
		self::assertSame( 10, $rec['priority'], '优先级透传' );
		self::assertSame( 1, $rec['accepted_args'], '参数数透传' );
		self::assertIsCallable( $rec['callback'], '回调可调用' );
	}

	/**
	 * mount_behaviors detect 关态：目标未装=零动作（零 filter 记录）。
	 */
	public function test_mount_behaviors_detect_off_zero_action(): void {
		\WinkGatekeeper\Compat\CompatRegistry::mount_behaviors();
		self::assertSame( array(), CompatProbeStub::$filters, 'detect 关=零动作（插件未装零挂载）' );
	}

	/**
	 * 四模块全探测也只挂 FluentComments 一条（呈现模块 behaviors 空=零动作短路先于 detect）。
	 */
	public function test_mount_behaviors_presentation_modules_zero_action_even_when_detected(): void {
		CompatProbeStub::$constants  = array( 'AKISMET_VERSION', 'WORDFENCE_VERSION', 'FLUENT_COMMENTS_VERSION' );
		CompatProbeStub::$classes    = array( 'FluentCommentsPlugin' );
		CompatProbeStub::$functions  = array( 'wpa_check_is_spam' );
		\WinkGatekeeper\Compat\CompatRegistry::mount_behaviors();
		self::assertCount( 1, CompatProbeStub::$filters, '四模块全探测仍只挂一条（呈现模块零行为腿）' );
		self::assertSame( 'fluent_comments/allowed_comment_hooks', CompatProbeStub::$filters[0]['hook'], '唯一挂载=FluentComments 应对 filter' );
	}

	/**
	 * R5 扩展（定稿 §15.4）：行为腿三字面量全库（includes/）仅 FluentComments.php 命中；
	 * includes/compat/ 内 add_filter 调用点唯一=CompatRegistry（mount）。
	 */
	public function test_r5_behavior_literals_single_point(): void {
		$dir   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__ ) . '/includes' ) );
		$codes = array();
		foreach ( $dir as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$codes[ str_replace( chr( 92 ), '/', (string) $file ) ] = (string) file_get_contents( (string) $file );
			}
		}

		$probes = array(
			'WinkGatekeeper\\Gates\\CommentGate::hook_preprocess_comment' => 'FluentComments.php',
			'allow_list_gate_preprocess'                                  => 'FluentComments.php',
			'fluent_comments/allowed_comment_hooks'                       => 'FluentComments.php',
		);
		foreach ( $probes as $literal => $module_file ) {
			$hits     = array();
			$expected = array();
			foreach ( $codes as $path => $code ) {
				if ( false !== strpos( $code, $literal ) ) {
					$hits[] = $path;
				}
				if ( str_ends_with( $path, '/compat/' . $module_file ) ) {
					$expected[] = $path;
				}
			}
			self::assertSame( $expected, $hits, "R5 扩展：行为腿字面量 {$literal} 全库仅 {$module_file} 单点（命中 " . count( $hits ) . ' 文件）' );
		}

		$compat_add_filter = array();
		foreach ( $codes as $path => $code ) {
			if ( false !== strpos( $path, '/compat/' ) && false !== strpos( $code, 'add_filter(' ) ) {
				$compat_add_filter[] = $path;
			}
		}
		$expected_mount = array();
		foreach ( $codes as $path => $unused ) {
			unset( $unused );
			if ( str_ends_with( $path, '/compat/CompatRegistry.php' ) ) {
				$expected_mount[] = $path;
			}
		}
		self::assertSame( $expected_mount, $compat_add_filter, 'includes/compat/ 内 add_filter 调用点唯一=registry mount（模块=声明面零挂载——定稿 §15.2）' );
	}

	/**
	 * 入口装配根挂载：恰一次 CompatRegistry::mount()（FQCN 全限定形态）——W6-A R1 钉
	 * 「Bootstrap 零 'Compat'」不破坏（挂载点=入口非判定链装配，定稿 §15.3）。
	 */
	public function test_entry_mounts_layer_once(): void {
		$entry = self::src( 'wink-wp-gatekeeper.php' );
		self::assertSame( 1, substr_count( $entry, 'CompatRegistry::mount()' ), '入口装配根恰一挂载调用' );
		self::assertStringContainsString( '\\WinkGatekeeper\\Compat\\CompatRegistry::mount()', $entry, '挂载调用=FQCN 全限定形态' );
		self::assertStringContainsString( '【W6-A2】', $entry, '挂载调用带批次注记（装配根可读性）' );
	}

	/* ================================================================
	 * ⑦ 甲档红线钉（定稿 §4：R1 判定链零感知 / R2 探测只读 /
	 *    R3 零缓存零落库 / R5 判据单点登记）
	 * ================================================================ */

	public function test_r1_judge_chain_zero_compat_awareness(): void {
		$bootstrap = self::src( 'includes/Bootstrap.php' );
		self::assertStringNotContainsString( 'Compat', $bootstrap, 'R1：判定链装配（Bootstrap）零 Compat 引用——层与判定链零接触' );
	}

	public function test_r2_r3_compat_files_readonly_no_storage(): void {
		// R2 探测只读 + R3 零缓存零落库：includes/compat/ 六文件零写入/存储/外呼原语。
		$dir    = dirname( __DIR__ ) . '/includes/compat';
		$it     = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );
		$files  = array();
		foreach ( $it as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = (string) $file;
			}
		}
		self::assertCount( 6, $files, 'compat 目录闭集=六文件（registry+interface+四模块）' );

		foreach ( $files as $file ) {
			$code = (string) file_get_contents( $file );
			foreach ( array(
				'update_option',
				'add_option',
				'delete_option',
				'set_transient',
				'delete_transient',
				'get_option',
				'wp_remote',
				'wp_mail',
			) as $primitive ) {
				self::assertStringNotContainsString( $primitive, $code, 'R2/R3：compat 文件零写入/存储/外呼原语（' . basename( $file ) . ' :: ' . $primitive . '）' );
			}
		}
	}

	public function test_r5_probe_literals_single_point_registration(): void {
		// R5 判据单点登记：探测判据字面量全库（includes/）仅对应模块文件命中——
		// 散点探测（Bootstrap/SettingsPage 别处探测）=设计失败（W6 草案 §7 ③）。
		$probes = array(
			'AKISMET_VERSION'          => 'Akismet.php',
			'WORDFENCE_VERSION'        => 'Wordfence.php',
			'FLUENT_COMMENTS_VERSION'  => 'FluentComments.php',
			'wpa_check_is_spam'        => 'WpArmour.php',
			'wpa_load_scripts'         => 'WpArmour.php',
		);

		$dir   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__ ) . '/includes' ) );
		$codes = array();
		foreach ( $dir as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$codes[ str_replace( chr( 92 ), '/', (string) $file ) ] = (string) file_get_contents( (string) $file );
			}
		}

		foreach ( $probes as $literal => $module_file ) {
			$hits = array();
			foreach ( $codes as $path => $code ) {
				if ( false !== strpos( $code, $literal ) ) {
					$hits[] = $path;
				}
			}
			$expected = array();
			foreach ( $codes as $path => $unused ) {
				unset( $unused );
				if ( str_ends_with( $path, '/compat/' . $module_file ) ) {
					$expected[] = $path;
				}
			}
			self::assertSame(
				$expected,
				$hits,
				"R5：探测判据字面量 {$literal} 全库仅 {$module_file} 单点登记（命中 " . count( $hits ) . ' 文件）'
			);
		}
	}
}
