<?php
/**
 * PHPStan 静态分析桩：WP-CLI 门面类（批 ST1，2026-11-21）。
 *
 * 为何手写而非引入 php-stubs/wp-cli-stubs：上游包最高 v2.6.0 钉
 * php-stubs/wordpress-stubs ^4.7||^5.0，与本批 I-5a 接入的 ^7.1 冲突，
 * composer 别名绕行为脆弱方案不取。桩面刻意最小：只声明本插件实际调用的
 * 静态方法（ExistingAuditCli.php——add_command/error/log/success/warning，
 * 运行时全部有 WP_CLI defined() 守卫保护，见 register()）。签名按 WP-CLI
 * 2.x 公开 API；error() 刻意声明 void 而非 never——真实 WP_CLI::error()
 * 会退出，但声明 never 会把同一 catch 块中后续 error() 调用误判为不可达。
 *
 * 经 phpstan.neon.dist scanFiles 静态摄入——运行时零加载（phpunit 只扫
 * *Test.php，bootstrap 无 glob，本文件不进任何运行链）。
 *
 * @package WinkGatekeeper\Tests
 */

class WP_CLI {

	/**
	 * 注册 wp 命令（WP-CLI 2.x：name/ callable|class-string|array/ args）。
	 *
	 * @param string                $name     命令名。
	 * @param callable|string|array $callable 命令实现。
	 * @param array<string, mixed>  $args     注册参数。
	 * @return void
	 */
	public static function add_command( string $name, $callable, array $args = array() ): void {}

	/**
	 * 输出错误（真实实现默认 exit——此处刻意 void，见文件头注）。
	 *
	 * @param mixed    $message 错误消息。
	 * @param bool|int $exit    是否退出（真实签名 bool|int）。
	 * @return void
	 */
	public static function error( $message, $exit = true ): void {}

	/**
	 * 输出普通日志行。
	 *
	 * @param mixed $message 日志消息。
	 * @return void
	 */
	public static function log( $message ): void {}

	/**
	 * 输出成功消息。
	 *
	 * @param mixed $message 成功消息。
	 * @return void
	 */
	public static function success( $message ): void {}

	/**
	 * 输出警告消息。
	 *
	 * @param mixed $message 警告消息。
	 * @return void
	 */
	public static function warning( $message ): void {}
}
