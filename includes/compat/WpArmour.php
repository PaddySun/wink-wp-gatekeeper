<?php
/**
 * 统一兼容层模块：WP Armour（【W6-A】定稿 §6 判据表 + §8 审计——锚点前缀 wpa/ =
 * C:\Users\paddy\Desktop\github\honeypot.2.5\）。
 *
 * 探测判据（多路或集防单点，逐路源码实证）：
 *   ① function_exists( 'wpa_check_is_spam' ) —— wpa/includes/wpa_functions.php:136
 *      （function wpa_check_is_spam($form_data){——蜜罐判定核心）
 *   ② function_exists( 'wpa_load_scripts' )  —— wpa/includes/wpa_functions.php:4
 *      （function wpa_load_scripts(){——wp-armour.php:34 挂载）
 *   两函数均 wp-armour.php 插件加载期无条件 include（wp-armour.php:10）。
 *
 * 判据裁决附注（定稿 §6）：其版本号落在 $GLOBALS['wpa_version']（wpa/includes/
 * wpa_config.php:2）而非常量——该腿不进 detect()（isset() 是语言构造，不可被命名
 * 空间桩化 → 桩态不可控，违反模块契约第 3 条）；两路函数腿已充分。全源码无常量无
 * 类（define/^class grep 零命中，实证）。
 *
 * 形态要点（定稿 §8，呈现级合成）：其评论腿同样挂 preprocess_comment
 * （wpa/includes/integration/wpa_wpcomment.php:6，默认优先级 10）——本插件闸
 * @-PHP_INT_MAX 先行，两腿合成无害（人类过挑战后自然过蜜罐；填蜜罐的机器人被其
 * 拦）。呈现级模块：detect() 只读原语零存储零外呼；无行为分支。
 *
 * @package WinkGatekeeper\Compat
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Compat;

// ABSPATH 纵深守卫用 \defined 全限定形态（命名空间桩穿透——见 CompatModule.php 头注）。
if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

final class WpArmour implements CompatModule {

	public function key(): string {
		return 'wparmour';
	}

	public function label(): string {
		return __( 'WP Armour', 'wink-wp-gatekeeper' );
	}

	public function tag(): string {
		return __( 'Honeypot anti-spam', 'wink-wp-gatekeeper' );
	}

	public function detect(): bool {
		return function_exists( 'wpa_check_is_spam' ) || function_exists( 'wpa_load_scripts' );
	}

	public function status_copy(): string {
		return __( 'WP Armour\'s honeypot guards comment submissions: bots that fill its hidden field are caught with no challenge shown, humans pass it without noticing. Its check rides the same preprocessing hook this plugin\'s gate uses, behind it — the two compose harmlessly. No config is touched either way.', 'wink-wp-gatekeeper' );
	}

	public function absent_copy(): string {
		return __( 'No interaction. If WP Armour is added later, this row turns into the honeypot composition note automatically — detection runs live, nothing is stored.', 'wink-wp-gatekeeper' );
	}

	public function behaviors(): array {
		return array(); // 纯呈现模块（【W6-A2】行为腿契约——定稿 §15.2）。
	}

	public function stub_degrade(): bool {
		return false; // 无管道接管（呈现级模块——【W6-A3】存根降级契约，定稿 §16）。
	}

	public function request_in_pipeline(): bool {
		return false; // 无自有提交管道（判据面仅管道接管模块承载——【W6-A3】）。
	}
}
