<?php
/**
 * BootstrapAssemblyContractTest — 四闸装配实参/形参计数契约（B2 错位事故回归门）。
 *
 * 【背景·2026-11-11 真机事故】B2（71840e3）在 Bootstrap::assemble_gates() 重写
 * CommentGate 构造尾部时吞掉 require_gate 的 null 占位——17 实参 vs 18 形参，
 * $degraded_allow（DegradedPassLimiter::allow 回调）静默顶入 require_gate 位被当
 * 豁免判定调用（恒 false）→ 评论闸渲染+验证双双跳过（无按钮+无票直发）。
 * PHP 缺省参数的静默错位无法被运行时发现，纯逻辑单测又不覆盖 WP 边界装配——
 * 本测试以「源码实参计数（顶层逗号）=== 反射形参数」钉死四闸装配面。
 *
 * 已知限界（G5 纪律）：本测试不做语法级解析——实参计数用括号深度扫描的顶层逗号
 * 计数法，要求 Bootstrap 装配段内单引号字符串不含 ASCII 括号/顶层逗号歧义
 * （现状成立：字符串均为短标识符；中文注释用全角标点）。改装配形态时若本测试
 * 误报，先核对真实实参数再调整扫描器，不得删断言。
 */

declare( strict_types = 1 );

use WinkGatekeeper\Gates\CommentGate;
use WinkGatekeeper\Gates\LoginGate;
use WinkGatekeeper\Gates\RegisterGate;
use WinkGatekeeper\Gates\LostPasswordGate;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

final class BootstrapAssemblyContractTest extends TestCase {

	private static function bootstrap_src(): string {
		$src = file_get_contents( __DIR__ . '/../includes/Bootstrap.php' );
		self::assertNotFalse( $src, 'Bootstrap.php 可读' );
		return $src;
	}

	/**
	 * 提取 `new <Class>(` 起的实参段并按括号深度扫描计数顶层逗号 → 实参数。
	 */
	private static function count_args( string $src, string $class ): int {
		$needle = 'new ' . $class . '(';
		$start  = strpos( $src, $needle );
		self::assertNotFalse( $start, 'Bootstrap.php 中存在 ' . $needle . ' 装配点' );
		$depth = 0;
		$commas = 0;
		$len = strlen( $src );
		for ( $i = $start + strlen( $needle ) - 1; $i < $len; $i++ ) {
			$ch = $src[ $i ];
			if ( '(' === $ch ) {
				++$depth;
			} elseif ( ')' === $ch ) {
				--$depth;
				if ( 0 === $depth ) {
					return $commas + 1;
				}
			} elseif ( ',' === $ch && 1 === $depth ) {
				++$commas;
			}
		}
		self::fail( 'new ' . $class . '( 括号未闭合——Bootstrap.php 语法异常' );
	}

	/**
	 * 单闸装配实参数 === 反射构造形参数（错位即红——含末位缺省参数静默错位形态）。
	 */
	private static function assert_assembly_matches( string $class ): void {
		$ctor = ( new ReflectionClass( $class ) )->getConstructor();
		self::assertNotNull( $ctor, $class . ' 存在构造器' );
		$expected = $ctor->getNumberOfParameters();

		$actual = self::count_args( self::bootstrap_src(), ( new ReflectionClass( $class ) )->getShortName() );
		self::assertSame(
			$expected,
			$actual,
			sprintf(
				'%s 装配实参数(%d) ≠ 构造形参数(%d)——末位缺省参数的静默错位（B2 事故形态：require_gate 位被顶占）会在此红。',
				$class,
				$actual,
				$expected
			)
		);
	}

	public function test_comment_gate_assembly_arg_count(): void {
		self::assert_assembly_matches( CommentGate::class );
	}

	public function test_login_gate_assembly_arg_count(): void {
		self::assert_assembly_matches( LoginGate::class );
	}

	public function test_register_gate_assembly_arg_count(): void {
		self::assert_assembly_matches( RegisterGate::class );
	}

	public function test_lostpassword_gate_assembly_arg_count(): void {
		self::assert_assembly_matches( LostPasswordGate::class );
	}

	/**
	 * CommentGate 装配段必须保留 require_gate 的 null 占位及防回归注释
	 * （语义锚点——防未来重写尾部时再次吞位）。
	 */
	public function test_comment_gate_require_gate_null_placeholder_present(): void {
		self::assertStringContainsString(
			'require_gate：null = WP 边界默认闭包',
			self::bootstrap_src(),
			'CommentGate 装配段缺 require_gate null 占位注释——B2 错位事故的防回归锚点被删除。'
		);
	}
}
