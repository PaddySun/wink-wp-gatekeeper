<?php
/**
 * 统一兼容层模块：Akismet（【W6-A】定稿 §6 判据表——锚点前缀 akismet/ =
 * C:\Users\paddy\Desktop\github\akismet\akismet.5.7.2\akismet\）。
 *
 * 探测判据（多路或集防单点，逐路源码实证）：
 *   ① defined( 'AKISMET_VERSION' )  —— akismet/akismet.php:42
 *      （define( 'AKISMET_VERSION', '5.7.2' );）
 *   ② class_exists( 'Akismet' )     —— akismet/class.akismet.php:9（class Akismet {）
 *
 * 形态要点（docs/reports/DESIGN-AKISMET-AUDIT-2026-11-13.md §A1/A7）：主通道
 * preprocess_comment @1（class.akismet.php:81），本插件评论闸 @-PHP_INT_MAX 先行
 * （Bootstrap 装配）——先行关系如实写进 status 文案。呈现级模块（甲档）：detect()
 * 只读原语零存储零外呼；无行为分支。
 *
 * @package WinkGatekeeper\Compat
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Compat;

// ABSPATH 纵深守卫用 \defined 全限定形态（命名空间桩穿透——见 CompatModule.php 头注）。
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

final class Akismet implements CompatModule {

	public function key(): string {
		return 'akismet';
	}

	public function label(): string {
		return __( 'Akismet', 'wink-wp-gatekeeper' );
	}

	public function tag(): string {
		return __( 'Comment spam screening', 'wink-wp-gatekeeper' );
	}

	public function detect(): bool {
		return defined( 'AKISMET_VERSION' ) || class_exists( 'Akismet' );
	}

	public function status_copy(): string {
		return __( 'Akismet is active and screens comment spam on its own hook behind this plugin\'s gate — this plugin\'s comment gate runs first, ahead of every comment plugin. Layered cooperation (skipping the built-in rules for submissions Akismet has already caught) is planned, not yet built; this row never touches Akismet\'s config.', 'wink-wp-gatekeeper' );
	}

	public function absent_copy(): string {
		return __( 'No interaction. If Akismet is added later, this row turns into the coexistence note automatically — detection runs live on each settings page render, nothing is stored.', 'wink-wp-gatekeeper' );
	}

	public function behaviors(): array {
		return array(); // 纯呈现模块（【W6-A2】行为腿契约——定稿 §15.2）。
	}
}
