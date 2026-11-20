<?php
/**
 * PHP 语法兼容契约（批 ST2，2026-11-21；0.9.3 起锚 Requires PHP 8.0）。
 *
 * 背景：插件自 0.5.x 起运行时代码实际混用 PHP 8.0 专属语法 16 处（match 表达式
 * 14 + 原生联合参数 2——M5/M6 期代码），而插件头/composer 声明 7.4；B0 时即留档
 * 「7.4 兼容性未经机制验证」（CI workflow 注记），phpstan 2.x 升级批（批 ST2）
 * 实证该声明从未成立——7.4 站点后台类加载即解析致命。统筹代裁（可翻案）：
 * **下限升 8.0**（7.4 已于 2022-11 EOL；B 机/CI/本地全 8.3 实况；声明与代码对齐，
 * WP.org 送审 PHP 兼容检查口径一致），readme Breaking note (since 0.9.3) 披露。
 *
 * 本契约锚新下限：**禁 8.1+ 语法**（声明 8.0 则代码不得越位）。match/联合类型/
 * nullsafe 等为 8.0 原生语法自本批起合法，不入钉面。启发式源码扫描口径——
 * 8.1 四族实锤钉（readonly 属性/enum 声明/never 返回类型/8.3 类型化类常量），
 * 其余 8.1+ 形态（first-class callable、DNF 类型、new in initializers 等）无廉价
 * 正则不设钉，登记为覆盖缺口（phpstan phpVersion 面随下限自动收紧互证）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;

final class PhpSyntaxCompatTest extends TestCase {

	/**
	 * 收集运行时源文件全集（includes/ 递归 + 入口两件；tests/tools 不在运行面）。
	 *
	 * @return array<int, string> 绝对路径表。
	 */
	private static function runtime_php_files(): array {
		$root  = dirname( __DIR__ );
		$files = array();
		$iter  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iter as $f ) {
			if ( $f->isFile() && 'php' === $f->getExtension() ) {
				$files[] = (string) $f->getPathname();
			}
		}
		$files[] = $root . '/wink-wp-gatekeeper.php';
		$files[] = $root . '/uninstall.php';
		return $files;
	}

	/**
	 * 四族 8.1+ 语法逐一零残留断言（声明下限 8.0 的越位防线）。
	 *
	 * @return void
	 */
	public function test_no_php81_plus_syntax_in_runtime_sources(): void {
		$rules = array(
			'readonly 属性（8.1）'        => '/\bprivate\s+readonly\b|\bpublic\s+readonly\b|\bprotected\s+readonly\b|\breadonly\s+\$/',
			'enum 声明（8.1）'            => '/^\s*(abstract\s+|final\s+)?enum\s+[A-Z]/m',
			'never 返回类型（8.1）'       => '/\)\s*:\s*never\b/',
			'类型化类常量（8.3）'         => '/^\s*(public|private|protected)\s+const\s+(int|float|string|bool|array|list|dict|class|interface|mixed)\b/m',
		);
		$files = self::runtime_php_files();
		foreach ( $rules as $label => $pattern ) {
			$hits = array();
			foreach ( $files as $path ) {
				$src = (string) file_get_contents( $path );
				if ( 1 === preg_match( $pattern, $src ) ) {
					$hits[] = (string) substr( $path, strlen( dirname( __DIR__ ) ) + 1 );
				}
			}
			self::assertSame( array(), $hits, $label . ' 零残留（Requires PHP: 8.0 声明下限——代码越位 8.1+ 即本钉转红；命中处应改 8.0 等价形态或再次升限并披露）' );
		}
	}

	/**
	 * 声明下限三位一致性钉：插件头 / readme meta / composer.json 三处必须同为 8.0。
	 *
	 * @return void
	 */
	public function test_requires_php_floor_pinned_three_positions(): void {
		$root     = dirname( __DIR__ );
		$main     = (string) file_get_contents( $root . '/wink-wp-gatekeeper.php' );
		$readme   = (string) file_get_contents( $root . '/readme.txt' );
		$composer = (string) file_get_contents( $root . '/composer.json' );
		self::assertMatchesRegularExpression( '/Requires PHP:\s+8\.0/', $main, '插件头 Requires PHP === 8.0（0.9.3 批 ST2 下限升位）' );
		self::assertMatchesRegularExpression( '/Requires PHP: 8\.0/', $readme, 'readme meta Requires PHP === 8.0' );
		self::assertMatchesRegularExpression( '/"php":\s*">=8\.0"/', $composer, 'composer.json php 平台 === >=8.0' );
	}
}
