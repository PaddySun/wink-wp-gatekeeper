<?php
/**
 * GateRegistry 闸注册表单元测试（02 §2.7）。
 *
 * 规格：每闸注册 id/class/settings_key/stages/verdict_actions/context_id 生成器；
 * 内置 comment/login 两条注册数据（class 指向 M0 占位类）；
 * register()/get()/all()/enabled()。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\GateRegistry;
use WinkGatekeeper\Settings\SettingsSchema;

final class GateRegistryTest extends TestCase {

	private function registry(): GateRegistry {
		return new GateRegistry();
	}

	/* ---------- 内置注册数据 ---------- */

	public function test_builtins_are_comment_login_register_lostpassword(): void {
		$registry = $this->registry();
		// 【v1.4·R3/02 §2.7】GateRegistry 契约的第一次真实扩容：register/lostpassword 两新闸。
		$this->assertSame( array( 'comment', 'login', 'register', 'lostpassword' ), array_keys( $registry->all() ) );
	}

	public function test_comment_gate_registration_matches_design(): void {
		$gate = $this->registry()->get( 'comment' );

		$this->assertIsArray( $gate );
		$this->assertSame( 'comment', $gate['id'] );
		$this->assertSame( 'WinkGatekeeper\Gates\CommentGate', $gate['class'] );
		$this->assertSame( 'comment', $gate['settings_key'] );
		$this->assertSame( array( 'ip', 'captcha', 'review' ), $gate['stages'] );
		// 02 §2.7：评论闸 review → 入待审（D8）。
		$this->assertSame( array( 'review' => 'comment_approved=0' ), $gate['verdict_actions'] );
		$this->assertIsCallable( $gate['context_id'] );
	}

	public function test_login_gate_registration_matches_design(): void {
		$gate = $this->registry()->get( 'login' );

		$this->assertIsArray( $gate );
		$this->assertSame( 'login', $gate['id'] );
		$this->assertSame( 'WinkGatekeeper\Gates\LoginGate', $gate['class'] );
		$this->assertSame( 'login', $gate['settings_key'] );
		// 02 §2.7：登录闸无 review 阶段。
		$this->assertSame( array( 'ip', 'captcha' ), $gate['stages'] );
	}

	public function test_comment_context_id_reads_comment_post_id(): void {
		$generator = $this->registry()->get( 'comment' )['context_id'];
		$this->assertSame( '42', $generator( array( 'comment_post_ID' => 42 ) ) );
		$this->assertSame( '', $generator( array() ) );
	}

	public function test_login_context_id_is_constant(): void {
		$generator = $this->registry()->get( 'login' )['context_id'];
		$this->assertSame( 'login', $generator( array( 'foo' => 'bar' ) ) );
		$this->assertSame( 'login', $generator( array() ) );
	}

	/* ---------- 【v1.4·R3/02 §2.7 扩容】register/lostpassword 两新闸 ---------- */

	public function test_register_gate_registration_matches_design(): void {
		$gate = $this->registry()->get( 'register' );

		$this->assertIsArray( $gate );
		$this->assertSame( 'register', $gate['id'] );
		$this->assertSame( 'WinkGatekeeper\Gates\RegisterGate', $gate['class'] );
		$this->assertSame( 'register', $gate['settings_key'] );
		$this->assertSame( array( 'captcha' ), $gate['stages'] );
		// 02 §2.7：注册闸 reject → WP_Error 注入（用户不创建）。
		$this->assertSame( array( 'reject' => 'wp_error_registration' ), $gate['verdict_actions'] );
		$generator = $gate['context_id'];
		$this->assertSame( 'register', $generator( array() ) );
	}

	public function test_lostpassword_gate_registration_matches_design(): void {
		$gate = $this->registry()->get( 'lostpassword' );

		$this->assertIsArray( $gate );
		$this->assertSame( 'lostpassword', $gate['id'] );
		$this->assertSame( 'WinkGatekeeper\Gates\LostPasswordGate', $gate['class'] );
		$this->assertSame( 'lostpassword', $gate['settings_key'] );
		$this->assertSame( array( 'captcha' ), $gate['stages'] );
		// 02 §2.7：找回密码闸 reject → WP_Error 注入（重置邮件不发）。
		$this->assertSame( array( 'reject' => 'wp_error_lostpassword' ), $gate['verdict_actions'] );
		$generator = $gate['context_id'];
		$this->assertSame( 'lostpassword', $generator( array() ) );
	}

	/* ---------- 查询 ---------- */

	public function test_get_unknown_returns_null(): void {
		$this->assertNull( $this->registry()->get( 'signup' ) );
	}

	public function test_all_returns_registration_order(): void {
		$registry = $this->registry();
		$registry->register( array(
			'id'             => 'signup',
			'class'          => 'WinkGatekeeper\Gates\SignupGate',
			'settings_key'   => 'signup',
			'stages'         => array( 'captcha' ),
			'verdict_actions' => array(),
			'context_id'     => static function (): string {
				return 'signup';
			},
		) );
		$this->assertSame( array( 'comment', 'login', 'register', 'lostpassword', 'signup' ), array_keys( $registry->all() ) );
		$this->assertSame( 'signup', $registry->get( 'signup' )['id'] );
	}

	/* ---------- 注册校验 ---------- */

	public function test_register_duplicate_id_throws(): void {
		$registry = $this->registry();
		$this->expectException( \InvalidArgumentException::class );
		$registry->register( array(
			'id'             => 'comment',
			'class'          => 'X',
			'settings_key'   => 'comment',
			'stages'         => array( 'captcha' ),
			'verdict_actions' => array(),
			'context_id'     => 'trim',
		) );
	}

	/**
	 * 任一必填字段缺失（id/class/settings_key/stages/verdict_actions/context_id）都拒绝。
	 */
	public function test_register_missing_required_field_throws(): void {
		$complete = array(
			'id'             => 'signup',
			'class'          => 'WinkGatekeeper\Gates\SignupGate',
			'settings_key'   => 'signup',
			'stages'         => array( 'ip', 'captcha', 'review' ),
			'verdict_actions' => array( 'review' => 'comment_approved=0' ),
			'context_id'     => 'trim',
		);

		foreach ( array( 'id', 'class', 'settings_key', 'stages', 'verdict_actions', 'context_id' ) as $missing ) {
			$broken = $complete;
			unset( $broken[ $missing ] );
			try {
				$this->registry()->register( $broken );
				$this->fail( "缺少 {$missing} 应抛异常" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( $missing, $e->getMessage() );
			}
		}
	}

	public function test_register_empty_stages_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->registry()->register( array(
			'id'             => 'signup',
			'class'          => 'C',
			'settings_key'   => 'signup',
			'stages'         => array(),
			'verdict_actions' => array(),
			'context_id'     => 'trim',
		) );
	}

	public function test_register_non_callable_context_id_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->registry()->register( array(
			'id'             => 'signup',
			'class'          => 'C',
			'settings_key'   => 'signup',
			'stages'         => array( 'captcha' ),
			'verdict_actions' => array(),
			'context_id'     => 'definitely-not-a-callable-0123456789',
		) );
	}

	/* ---------- enabled() ---------- */

	public function test_enabled_reads_gate_subtree_of_settings(): void {
		$registry = $this->registry();
		$this->assertTrue( $registry->enabled( 'comment', SettingsSchema::defaults() ) );
		$this->assertTrue( $registry->enabled( 'login', SettingsSchema::defaults() ) );
	}

	public function test_enabled_false_when_gate_disabled_in_settings(): void {
		$settings = SettingsSchema::defaults();
		$settings['gates']['comment']['enabled'] = false;
		$this->assertFalse( $this->registry()->enabled( 'comment', $settings ) );
	}

	public function test_enabled_false_when_settings_subtree_missing(): void {
		$this->assertFalse( $this->registry()->enabled( 'comment', array() ) );
	}

	public function test_enabled_unknown_gate_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->registry()->enabled( 'signup', SettingsSchema::defaults() );
	}
}
