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
 * detect() 只读原语零存储零外呼。
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
