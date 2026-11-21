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
 * ⚠ 判定级冲突登记（定稿 §7——本批零应对，W6-B 拍板项）：其 AJAX 提交走
 * wp_handle_comment_submission（flc/app/Services/CommentSubmission.php:196）但
 * suppressForeignHooks（同文件 260-291 行）在插入期把 preprocess_comment 上的全部
 * 第三方回调剥净——默认白名单恰一项 Akismet::auto_check_comment（264-266 行），
 * 本插件评论闸主判定腿（Bootstrap @-PHP_INT_MAX）随之被剥。官方预留加白通道=
 * fluent_comments/allowed_comment_hooks 过滤器（253-256/264 行）；任何应对须先
 * 真机复现再拍板，本模块文案只做事实陈述（零行为承诺——R6）。
 *
 * 呈现级模块：detect() 只读原语零存储零外呼；无行为分支。
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
		return __( 'FluentComments replaces the native comment form with its own AJAX form and, while inserting a submission, strips third-party callbacks off the comment preprocessing hooks (Akismet is on its allow list; this plugin is not). This row is detection only in this release — whether the comment gate should take part in AJAX submissions is a separately signed-off decision.', 'wink-wp-gatekeeper' );
	}

	public function absent_copy(): string {
		return __( 'No interaction. If FluentComments is added later, this row turns into the AJAX-form coexistence note automatically — detection runs live, nothing is stored.', 'wink-wp-gatekeeper' );
	}
}
