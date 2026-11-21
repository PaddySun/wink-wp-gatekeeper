<?php
/**
 * 统一兼容层模块：Wordfence（【W6-A】定稿 §6 判据表——锚点前缀 wf/ =
 * C:\Users\paddy\Desktop\github\wordfence-source\wordfence\（9.1.0；同版本副本
 * wordfence\wordfence.9.1.0\wordfence\ 两处锚点核对一致）。
 *
 * 探测判据（三路或集——Wordfence 按 helper 分文件，单 class 腿有单点风险）：
 *   ① defined( 'WORDFENCE_VERSION' )  —— wf/wordfence.php:41
 *      （define('WORDFENCE_VERSION', '9.1.0');）
 *   ② class_exists( 'wordfence' )     —— wf/lib/wordfenceClass.php:78（class wordfence {）
 *   ③ class_exists( 'wfConfig' )      —— wf/lib/wfConfig.php:2（class wfConfig {）
 *
 * 形态要点：两家同在登录闸出验证码时访客可能连过两道——呈现级知情行（谁守登录
 * 验证码=后续批次拍板；Tab② 登录卡黄条=候选登记，定稿 §11，实现时必须复用本层
 * registry 消费）。呈现级模块：detect() 只读原语零存储零外呼；无行为分支。
 *
 * @package WinkGatekeeper\Compat
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Compat;

// ABSPATH 纵深守卫用 \defined 全限定形态（命名空间桩穿透——见 CompatModule.php 头注）。
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

final class Wordfence implements CompatModule {

	public function key(): string {
		return 'wordfence';
	}

	public function label(): string {
		return __( 'Wordfence', 'wink-wp-gatekeeper' );
	}

	public function tag(): string {
		return __( 'Login captcha overlap', 'wink-wp-gatekeeper' );
	}

	public function detect(): bool {
		return defined( 'WORDFENCE_VERSION' ) || class_exists( 'wordfence' ) || class_exists( 'wfConfig' );
	}

	public function status_copy(): string {
		return __( 'Wordfence is active. With both plugins verifying logins, visitors may face two captchas in a row — who guards the login captcha (keep one, or let one yield) is a decision for a later batch. Informational only; neither plugin\'s config is touched.', 'wink-wp-gatekeeper' );
	}

	public function absent_copy(): string {
		return __( 'No interaction. If Wordfence is added later, this row turns into the login-captcha overlap note automatically — detection runs live, nothing is stored.', 'wink-wp-gatekeeper' );
	}
}
