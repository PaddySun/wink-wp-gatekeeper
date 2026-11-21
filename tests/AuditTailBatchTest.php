<?php
/**
 * 独立审计遗留缺陷修复批 · S 级随手件契约测试（AUDIT-INDEP-2026-11 六件）。
 *
 * 六件（任务书 2026-11-21 件 5）：
 *   - A2-F3：SettingsPage::settings() 请求级静态缓存（照抄 StatsCache::$request_settings_cache
 *     先例——Tab① 单请求 9-10 次全树 merge 收敛到 1）+ chain_usable_count 单行 3 次调用局部化；
 *   - A2-F7：LocalRules 词表每 check 重建 → 实例级缓存（首 check 惰性拆分，取词回调
 *     每实例只消费一次）；
 *   - A2-F12：comment_post 无条件构造 UrlAuditWorker（功能关时）→ 装配缓存树判
 *     url_audit.enabled 前置短路；
 *   - A1-F13：吞异常两处补观测——uninstall 失败安全包装 error_log 单行（卸载语境写日志
 *     安全）；SettingsPage 竞态分支注释声明（预期竞态=日志噪音，有意无观测）；
 *   - A1-F14：wk_bad_signature 归类洞——classify_error_code switch 补专属 case（语义
 *     归档显式化：坏签名=明显恶意，与 wk_captcha_failed 同档 IP-only）；
 *   - A1-F16：徽标 CSS 双词汇表归一（纯呈现）——wk-probe--* 即时结果态与 .b-* 徽标族
 *     色板映射单点分组 + 注释锚（chip 徽标词汇表 W3-FIX 0.8.6 已单源）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\Review\LocalRulesDriver;
use WinkGatekeeper\Gates\LoginCounters;
use WinkGatekeeper\Settings\SettingsPage;
use WinkGatekeeper\Verdict;

final class AuditTailBatchTest extends TestCase {

	protected function setUp(): void {
		\WinkGatekeeper\Tests\WpOptionStub::reset();
	}

	protected function tearDown(): void {
		\WinkGatekeeper\Tests\WpOptionStub::reset();
		if ( method_exists( SettingsPage::class, 'reset_request_settings_cache' ) ) {
			SettingsPage::reset_request_settings_cache();
		}
	}

	/* ===================================================================
	 * A2-F3 · settings() 请求级静态缓存
	 * =================================================================== */

	/**
	 * settings() merge 结果请求内缓存：同请求两次调用=同一实例语义（存储树中途变化
	 * 不再重读重 merge）；复位桥后读取新鲜树。
	 */
	public function test_settings_request_level_cache_and_reset_bridge(): void {
		$tree_v1 = array( 'stats' => array( 'enabled' => true ) );
		$tree_v2 = array( 'stats' => array( 'enabled' => false ) );

		\WinkGatekeeper\Tests\WpOptionStub::set( SettingsPage::OPTION_NAME, $tree_v1 );
		$first  = self::invoke_settings();
		$second = self::invoke_settings();
		$this->assertSame( $first, $second, '同请求两次 settings() 调用=缓存复用（A2-F3）' );

		\WinkGatekeeper\Tests\WpOptionStub::set( SettingsPage::OPTION_NAME, $tree_v2 );
		$third = self::invoke_settings();
		$this->assertSame( $first, $third, '缓存存续：存储树中途变化不重 merge（请求生命周期语义）' );

		SettingsPage::reset_request_settings_cache();
		$fourth = self::invoke_settings();
		$this->assertFalse( (bool) $fourth['stats']['enabled'], '复位桥后读取新鲜树（单测隔离面——StatsCache::reset_request_cache 先例）' );
	}

	/**
	 * chain_usable_count 体内在场 self::settings() 调用 ≤1（单行 3 次调用局部化——A2-F3 附带）。
	 */
	public function test_chain_usable_count_settings_calls_bounded(): void {
		$body = self::method_body( (string) file_get_contents( dirname( __DIR__ ) . '/includes/settings/SettingsPage.php' ), 'chain_usable_count' );
		$this->assertLessThanOrEqual( 1, substr_count( $body, 'self::settings()' ), 'chain_usable_count 至多消费 settings() 一次（局部变量收口）' );
	}

	private static function invoke_settings(): array {
		$method = new \ReflectionMethod( SettingsPage::class, 'settings' );
		$method->setAccessible( true );
		return $method->invoke( null );
	}

	/* ===================================================================
	 * A2-F7 · LocalRules 词表实例级缓存
	 * =================================================================== */

	/**
	 * 取词回调每实例只消费一次：两次 check（同一实例）回调计数=1（现形态=2）。
	 */
	public function test_word_list_cache_consumes_keywords_closure_once_per_instance(): void {
		$calls   = 0;
		$driver  = new LocalRulesDriver(
			array( 'use_wp_keywords' => 1, 'min_length' => 0, 'max_length' => 0, 'require_chinese' => 0 ),
			static function () use ( &$calls ): array {
				$calls++;
				return array( '私活', '接单' );
			}
		);

		$first  = $driver->check( '正常内容', array( 'content' => '正常内容' ) );
		$second = $driver->check( '接单啊', array( 'content' => '接单啊' ) );

		$this->assertSame( 1, $calls, '【A2-F7】取词回调每实例一次（词表拆分缓存——每 check 重建退役）' );
		$this->assertSame( Verdict::PASS, $first->verdict() );
		$this->assertSame( Verdict::REJECT, $second->verdict(), '缓存复用后判定语义不变（词表命中照常）' );
	}

	/* ===================================================================
	 * A2-F12 · comment_post 条件化构造
	 * =================================================================== */

	/**
	 * comment_post 闭包：装配缓存树判 url_audit.enabled 先于 url_audit_worker 构造
	 * （功能关=零构造；现形态=无条件构造）。
	 */
	public function test_comment_post_closure_guards_enabled_before_worker_construction(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Bootstrap.php' );
		$start = strpos( $src, "'comment_post'" );
		$this->assertNotFalse( $start, 'comment_post 注册块在场' );
		$open  = strpos( $src, '{', $start );
		$depth = 0;
		$body  = '';
		$len   = strlen( $src );
		for ( $i = $open; $i < $len; $i++ ) {
			if ( '{' === $src[ $i ] ) {
				++$depth;
			} elseif ( '}' === $src[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					$body = substr( $src, $open, $i - $open + 1 );
					break;
				}
			}
		}
		$guard_pos = strpos( $body, "assembled_settings" );
		$worker_pos = strpos( $body, 'url_audit_worker' );
		$this->assertNotFalse( $guard_pos, '闭包读装配缓存树判 enabled（A2-F12——先读缓存树）' );
		$this->assertStringContainsString( "url_audit']['enabled'", $body, 'enabled 判定在位（url_audit 子树）' );
		$this->assertGreaterThan( $guard_pos, $worker_pos, 'worker 构造在 enabled 短路之后（功能关=零构造）' );
	}

	/* ===================================================================
	 * A1-F13 · 吞异常两处补观测
	 * =================================================================== */

	/**
	 * uninstall 失败安全包装：吞异常时 error_log 单行留痕（[wink-gatekeeper] 前缀——
	 * 卸载语境写日志安全，半失败可诊断）。
	 */
	public function test_uninstall_guarded_wrapper_logs_failures(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
		$this->assertStringContainsString( "error_log( '[wink-gatekeeper]", $src, '吞异常处 error_log 单行（A1-F13——卸载半失败可发现）' );
		$this->assertStringContainsString( 'catch ( Throwable $wink_step_failure )', $src, '失败安全包装保持（宁可残留语义不动）' );
	}

	/**
	 * SettingsPage 竞态吞异常分支：注释声明观测决策（预期竞态=状态行已显示现态，
	 * 日志留痕=噪音——有意无观测声明在案）。
	 */
	public function test_race_catch_declares_observability_decision(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/settings/SettingsPage.php' );
		$this->assertStringContainsString( '【A1-F13】', $src, '竞态分支观测决策声明锚（A1-F13）' );
	}

	/* ===================================================================
	 * A1-F14 · wk_bad_signature 归类显式化
	 * =================================================================== */

	/**
	 * classify_error_code：wk_bad_signature 专属 case 在册（语义归档=IP-only，显式注释
	 * 归档理由）；行为值与 default 档一致（本件=分类学完备性，无行为翻转）。
	 */
	public function test_wk_bad_signature_has_explicit_case(): void {
		$src  = (string) file_get_contents( dirname( __DIR__ ) . '/includes/gates/LoginCounters.php' );
		$body = self::method_body( $src, 'classify_error_code' );
		$this->assertStringContainsString( "case 'wk_bad_signature':", $body, '【A1-F14】wk_bad_signature 专属 case（default 兜底形态退役）' );

		$this->assertSame( array( 'ip' => true, 'user' => false ), LoginCounters::classify_error_code( 'wk_bad_signature' ), '行为值=IP-only（与 wk_captcha_failed 同档——坏签名不惩罚用户名维）' );
	}

	/* ===================================================================
	 * A1-F16 · 徽标 CSS 双词汇表归一（纯呈现）
	 * =================================================================== */

	/**
	 * 探针即时结果态与 .b-* 徽标族的色板映射单点分组 + A1-F16 归一锚（CSS 注释契约）。
	 */
	public function test_probe_css_palette_grouped_with_badge_family(): void {
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/admin-settings.css' );
		$this->assertStringContainsString( '【A1-F16】', $css, 'CSS 归一锚（A1-F16 注释契约）' );
		foreach ( array(
			'.wk-probe--green',   // ↔ .b-ok
			'.wk-probe--yellow',  // ↔ .b-warn
			'.wk-probe--red',     // ↔ .b-err
			'.wk-probe--gray',    // ↔ .b-dim
		) as $cls ) {
			$this->assertStringContainsString( $cls, $css, "探针即时态 {$cls} 在册（词汇表角色分置——映射单点）" );
		}
		$this->assertStringContainsString( '↔ .b-ok', $css, '色板映射对照注释（green↔b-ok——改版单点声明）' );
	}

	/**
	 * JS 徽标回写共享件注释随批对齐（双写同源旧注退役——chip 词汇表已单源，
	 * 即时态色板映射归 CSS 单点）。
	 */
	public function test_admin_brand_badge_comment_updated(): void {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/admin-brand.js' );
		$this->assertStringContainsString( '【A1-F16】', $js, 'JS 徽标注释 A1-F16 归一批注' );
		$this->assertStringContainsString( 'b-ok', $js, 'chip 徽标服务端词汇表保持（W3FixW3F2Test 契约不动）' );
	}

	private static function method_body( string $src, string $name ): string {
		$start = strpos( $src, 'function ' . $name );
		self::assertNotFalse( $start, "函数 {$name} 必须存在" );
		$open  = strpos( $src, '{', $start );
		$depth = 0;
		$len   = strlen( $src );
		for ( $i = $open; $i < $len; $i++ ) {
			if ( '{' === $src[ $i ] ) {
				++$depth;
			} elseif ( '}' === $src[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $src, $open, $i - $open + 1 );
				}
			}
		}
		self::fail( "函数 {$name} 花括号不配平" );
		return '';
	}
}
