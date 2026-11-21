<?php
/**
 * 统一兼容层模块：FluentComments（【W6-A】定稿 §6 判据表 + §7 特别审计——锚点前缀
 * flc/ = C:\Users\paddy\Desktop\github\fluent-comments.2.1.1\）。
 *
 * 探测判据（多路或集防单点，逐路源码实证）：
 *   ① defined( 'FLUENT_COMMENTS_VERSION' ) —— flc/fluent-comments.php:21
 *      （define('FLUENT_COMMENTS_VERSION', '2.1.1');）
 *   ② class_exists( 'FluentCommentsPlugin' ) —— flc/fluent-comments.php:23
 *      （class FluentCommentsPlugin）
 *
 * 判定级冲突与应对（定稿 §7 登记 + §15 行为腿契约）：其 AJAX 提交走
 * wp_handle_comment_submission（flc/app/Services/CommentSubmission.php:196）但
 * suppressForeignHooks（同文件 260-291 行）在插入期把 preprocess_comment 上的全部
 * 第三方回调剥净——默认白名单恰一项 Akismet::auto_check_comment（264-266 行），
 * 本插件评论闸主判定腿（Bootstrap 实例方法对注册）随之被剥。【W6-A2】用户拍板
 * （2026-11-21 真机轮三层证据后）由本模块 behaviors() 声明应对腿：注册
 * fluent_comments/allowed_comment_hooks filter（官方预留加白通道 :253-256/:264）
 * 把 'WinkGatekeeper\Gates\CommentGate::hook_preprocess_comment'（callbackName
 * get_class 归一化形态全等可命名——定稿 §15.1 结论 a）追加进白名单，闸在 AJAX
 * 提交期间不再被剥。挂载由 CompatRegistry::mount_behaviors() 统一执行（detect()
 * 为真才挂）；本模块文件零 add_filter 调用（声明面非挂载面——R5 扩展钉）。
 *
 * 【W6-A3】存根降级腿（统一层第二行为件，定稿 §16）：回注腿生效后闸在其 AJAX
 * 管道内存活，但 FC 表单永远没有 wk_stub 存根字段（挑战在其 UI 无法呈现）→ 闸
 * 步骤 1 存根三态拦死全部 FC 访客提交（bad-signature）→ compose 语义不可用。
 * 用户拍板 2026-11-21「AJAX 分支降级为存根」：本模块 stub_degrade() 声明降级
 * 能力 + request_in_pipeline() 承载管道识别（admin-ajax 文件面 × 提交 action
 * 参数面 AND——判据锚见方法 docblock），CompatRegistry::stub_degrade_active()
 * 单点复合查询，CommentGate 挑战段（存根三态+验票）对管道内提交降级放行，
 * 限流/IP 信誉/本地四闸/云审照常全跑；非管道请求存根语义零变化。安全取舍
 * （用户裁决接受）：针对 FC 端点的定向提交可绕过挑战层，由内容闸+IP 信誉+限流
 * 兜底——定稿 §16 取舍声明明示。
 *
 * detect()/request_in_pipeline() 只读原语零存储零外呼。
 *
 * @package WinkGatekeeper\Compat
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Compat;

// ABSPATH 纵深守卫用 \defined 全限定形态（命名空间桩穿透——见 CompatModule.php 头注）。
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

final class FluentComments implements CompatModule {

	public function key(): string {
		return 'fluentcomments';
	}

	public function label(): string {
		return __( 'FluentComments', 'wink-wp-gatekeeper' );
	}

	public function tag(): string {
		return __( 'AJAX comment form', 'wink-wp-gatekeeper' );
	}

	public function detect(): bool {
		return defined( 'FLUENT_COMMENTS_VERSION' ) || class_exists( 'FluentCommentsPlugin' );
	}

	public function status_copy(): string {
		return __( 'FluentComments replaces the native comment form with its own AJAX form and, while inserting a submission, strips third-party callbacks off the comment preprocessing hooks (Akismet is on its allow list). When FluentComments is detected, this plugin re-registers its comment gate onto that allow list — the gate still screens AJAX submissions, and its layered spam guard composes behind it. How the challenge renders inside its AJAX flow is a live-machine follow-up.', 'wink-wp-gatekeeper' );
	}

	public function absent_copy(): string {
		return __( 'No interaction. If FluentComments is added later, this row turns into the AJAX-form coexistence note automatically — detection runs live, nothing is stored.', 'wink-wp-gatekeeper' );
	}

	public function behaviors(): array {
		/**
		 * 行为腿闭集（【W6-A2】定稿 §15.2）：恰一项=fluent_comments/allowed_comment_hooks
		 * 白名单回注（官方预留加白通道——flc/CommentSubmission.php:253-256/264）。
		 */
		return array(
			array(
				'hook'          => 'fluent_comments/allowed_comment_hooks',
				'callback'      => array( $this, 'allow_list_gate_preprocess' ),
				'priority'      => 10,
				'accepted_args' => 1,
			),
		);
	}

	/**
	 * 存根降级声明（【W6-A3】接口第八方法——统一层第二行为件，定稿 §16）。
	 *
	 * 真机依据（W64 终验）：本插件闸经白名单回注（behaviors() 腿）在其 AJAX 插入期
	 * 存活，但 FC 表单**永远没有 wk_stub 存根字段**（挑战在其自绘 Svelte UI 无法呈现）
	 * → 闸步骤 1 存根三态对 FC 访客提交恒 false（Intercept/Stub.php:56-58 缺哈希段
	 * 即 false）→ bad-signature 拦死全部 FC 访客提交 → compose 语义不可用。用户拍板
	 * 2026-11-21「AJAX 分支降级为存根」：经本模块管道的提交，闸挑战段（存根三态+验票）
	 * 降级放行至后续判定链（限流/IP 信誉/本地四闸/云审照常全跑）。安全取舍（用户裁决
	 * 接受，定稿 §16 明示）：针对该管道端点的定向提交可绕过挑战层，由内容闸+IP 信誉+
	 * 限流兜底。
	 *
	 * @return bool
	 */
	public function stub_degrade(): bool {
		return true;
	}

	/**
	 * 管道判据（【W6-A3】接口第九方法——请求参数级只读判据，两路 AND 防误判）：
	 *
	 *   ① 管道文件面：$_SERVER['SCRIPT_NAME'] basename='admin-ajax.php'——本插件评论提交
	 *      的唯一接管入口（flc/app/Services/Frontend.php:328 ajax_url=admin_url('admin-ajax.php')
	 *      + dist/js/app.js:2 前端 ar('fluent_comment_post',O) POST）。原生 wp-comments-post.php
	 *      等 basename 不合 → false——「WooCommerce 在原生请求也定义 DOING_AJAX」污染形态
	 *      一并排除（ResponseDispatcher native 优先同款教训——tests/ResponseDispatcherTest.php:8-9 头注）。
	 *      fail-closed：SCRIPT_NAME 缺失/异常 = false = 退回批前挑战语义（零安全损失，
	 *      最坏=W64 拦死形态在该主机延续）。
	 *   ② 提交 action 面：$_POST['action'] 全等 'fluent_comment_post'——服务端注册
	 *      wp_ajax(_nopriv)_fluent_comment_post（flc/app/Hooks/Handlers/CommentsHandler.php:20-21）
	 *      + 数据面 $_POST（:173 wp_unslash($_POST)；isPostRequest :312-314 限 POST）。
	 *      其列表/会话 action（fluent_comment_list/_session，:23-24/:26-27）不合 → false。
	 *
	 * 判据字面量 'fluent_comment_post' 单点登记在本文件（R5 扩展钉——CompatLayerTest
	 * 全库单点断言）。判据面零写入零外呼零存储（R2/R3 同 detect()）。REST 语境天然排除：
	 * CommentGate::hook_rest_preprocess_comment 编排期临时清空 $_POST（REST 隔离手法）→
	 * ② 恒假（REST 无存根必拦语义零变化）。
	 *
	 * @return bool
	 */
	public function request_in_pipeline(): bool {
		$script = isset( $_SERVER['SCRIPT_NAME'] ) && is_scalar( $_SERVER['SCRIPT_NAME'] ) ? (string) $_SERVER['SCRIPT_NAME'] : '';
		if ( '' === $script || 'admin-ajax.php' !== basename( $script ) ) {
			return false; // ① 管道文件面不合（原生表单/REST/wp-cli/其他插件端点）。
		}
		$action = isset( $_POST['action'] ) && is_scalar( $_POST['action'] ) ? (string) $_POST['action'] : '';
		return 'fluent_comment_post' === $action; // ② 提交 action 面（字面量=服务端注册锚）。
	}

	/**
	 * fluent_comments/allowed_comment_hooks 应对回调（【W6-A2】——白名单回注本插件
	 * 主判定腿；用户拍板 2026-11-21，定稿 §15）。
	 *
	 * 语义：把本插件评论闸 preprocess 回调（Bootstrap 以实例方法对注册——FluentComments
	 * callbackName（flc/CommentSubmission.php:321-338）经 get_class 归一化为
	 * 'WinkGatekeeper\Gates\CommentGate::hook_preprocess_comment' 全等可命名）追加进
	 * 剥除白名单，使闸在 AJAX 提交期间不再被剥（判定级冲突定稿 §7 的应对）。幂等：
	 * 已含本项则原样返回；非数组输入原样透传（suppressForeignHooks 外层 (array) cast
	 * 兜底）。SiteEnhance url 摘除腿**有意不回注**（呈现级且对方自带 url=''——定稿 §15.5）。
	 *
	 * @param mixed $allowed 白名单（FluentComments 默认 array( 'Akismet::auto_check_comment' )）。
	 * @return mixed
	 */
	public function allow_list_gate_preprocess( $allowed ) {
		$entry = 'WinkGatekeeper\Gates\CommentGate::hook_preprocess_comment';
		if ( ! is_array( $allowed ) || in_array( $entry, $allowed, true ) ) {
			return $allowed;
		}
		$allowed[] = $entry;
		return $allowed;
	}
}
