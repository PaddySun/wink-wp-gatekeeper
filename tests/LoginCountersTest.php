<?php
/**
 * LoginCounters 单元测试（02 §3.2 v1.4 重构——R3/D17①纯用户名键）。
 *
 * 覆盖：键 A′（纯用户名键 wk_lf_u_ 阈 3——不绑 IP 跨 IP 累计）/ 键 B（IP 聚合阈 10）/
 * 双信号错误码分类表（classify_error_code：incorrect_password 双计、invalid_username 只计
 * IP、wk_captcha_failed 只计 IP、第三方保守只计 IP、empty_* 不计）/ 站点级警报
 * wk_lf_alarm（用户名维度饱和点亮、TTL=window 随新饱和续期、成功登录不清、损坏按点亮）/
 * 渲染标志（饱和点亮 + light_flag 验证期闭环入口）/ 洪泛索引机制删除（max_user_keys 作废）/
 * saturation_source 触发归因 / 固定窗口语义 / fail-safe 读（损坏按饱和）。
 *
 * 存储经注入数组后端（get/set/del 闭包），时钟注入可拨动——纯逻辑零 WP 依赖。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\LoginCounters;

final class LoginCountersTest extends TestCase {

	/**
	 * 内存 transient 后端：数组 + 可变时钟（引用）。
	 *
	 * @param int $clock 时钟（引用拨动）。
	 * @return array{counters: LoginCounters, store: array<string, mixed>}
	 */
	private function make( int &$clock, array $settings = array() ): array {
		$store = array();

		$counters = new LoginCounters(
			$settings,
			static function ( string $key ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : null;
			},
			static function ( string $key, $value, int $ttl ) use ( &$store ): void {
				unset( $ttl ); // 后端不计 TTL——过期语义由桶内 exp 承担（实现同判 exp）。
				$store[ $key ] = $value;
			},
			static function ( string $key ) use ( &$store ): void {
				unset( $store[ $key ] );
			},
			static function ( string $data ): string {
				return substr( hash_hmac( 'sha256', $data, 'login-counters-test' ), 0, 16 );
			},
			static function () use ( &$clock ): int {
				return $clock;
			}
		);

		return array( 'counters' => $counters, 'store' => &$store );
	}

	private static function defaults(): array {
		// v1.4 键集（04 §2 schema v2）：user_threshold/window_minutes/ip_threshold——max_user_keys 已删除。
		return array( 'user_threshold' => 3, 'window_minutes' => 15, 'ip_threshold' => 10 );
	}

	/* ---------- 键 A′：纯用户名键（阈 3，不绑 IP 跨 IP 累计） ---------- */

	public function test_user_key_saturates_after_three_failures_same_user(): void {
		$clock = 1000000;
		$c     = $this->make( $clock )['counters'];

		$c->record_failure( '203.0.113.7', 'admin' );
		$this->assertFalse( $c->is_saturated( '203.0.113.7', 'admin' ), '1 次未超阈' );
		$c->record_failure( '203.0.113.7', 'admin' );
		$this->assertFalse( $c->is_saturated( '203.0.113.7', 'admin' ), '2 次未超阈' );
		$c->record_failure( '203.0.113.7', 'admin' );
		$this->assertTrue( $c->is_saturated( '203.0.113.7', 'admin' ), '3 次达阈值 3' );
	}

	/**
	 * 【R3 核心增益】分布式爆破：同账号换 IP 各试 1–2 次也累计——旧组合键此形态永不触发。
	 */
	public function test_user_key_accumulates_across_ips_distributed_brute_force(): void {
		$clock = 1000000;
		$c     = $this->make( $clock )['counters'];

		$c->record_failure( '198.51.100.1', 'admin' ); // 僵尸网络节点 1。
		$c->record_failure( '198.51.100.2', 'admin' ); // 节点 2。
		$this->assertFalse( $c->is_saturated( '198.51.100.3', 'admin' ), '2 次未超阈' );
		$c->record_failure( '198.51.100.3', 'admin' ); // 节点 3——跨 IP 累计达 3。

		$this->assertTrue( $c->is_saturated( '203.0.113.99', 'admin' ), '纯用户名键跨 IP 累计：任意 IP 验证期均超阈' );
	}

	public function test_user_key_is_per_username(): void {
		$clock = 1000000;
		$c     = $this->make( $clock )['counters'];

		for ( $i = 0; $i < 3; $i++ ) {
			$c->record_failure( '203.0.113.7', 'admin' );
		}
		$this->assertTrue( $c->is_saturated( '198.51.100.9', 'admin' ), '用户维度与验证 IP 无关' );
		$this->assertFalse( $c->is_saturated( '203.0.113.7', 'editor' ), '异用户名各自独立计数' );
	}

	public function test_user_key_hash_is_namespaced_with_u_pipe_prefix(): void {
		$clock = 1000000;
		$mk    = $this->make( $clock );
		$mk['counters']->record_failure( '203.0.113.7', 'admin' );

		$expected = LoginCounters::KEY_USER . substr( hash_hmac( 'sha256', 'u|admin', 'login-counters-test' ), 0, 16 );
		$this->assertArrayHasKey( $expected, $mk['store'], '用户名键 = wk_lf_u_<HMAC16("u|"+user_login)>（02 §3.2）' );
	}

	/* ---------- 键 B：IP 聚合键（阈 10，防 password spray） ---------- */

	public function test_key_b_saturates_across_usernames_same_ip(): void {
		$clock = 1000000;
		$c     = $this->make( $clock )['counters'];

		// 10 个不同用户名各错 1 次：用户名键每键 1 次不超阈，IP 键聚合 10 次达阈。
		for ( $i = 1; $i <= 10; $i++ ) {
			$c->record_failure( '203.0.113.7', 'spray_user_' . $i );
		}

		$this->assertTrue( $c->is_saturated( '203.0.113.7', 'spray_user_11' ), 'IP 维度饱和：新用户名同超阈' );
		$this->assertTrue( $c->render_flag( '203.0.113.7' ), 'IP 键饱和也点亮渲染标志' );
	}

	public function test_ip_only_failure_omits_user_dimension(): void {
		$clock = 1000000;
		$mk    = $this->make( $clock );
		$c     = $mk['counters'];

		// unknown username / wk_captcha_failed / 第三方码：只计 IP（user 维度传空）。
		$c->record_failure( '203.0.113.7', '' );
		$c->record_failure( '203.0.113.7', '' );

		$user_keys = 0;
		foreach ( array_keys( $mk['store'] ) as $key ) {
			if ( 0 === strpos( $key, LoginCounters::KEY_USER ) ) {
				$user_keys++;
			}
		}
		$this->assertSame( 0, $user_keys, '空用户名维度不建 wk_lf_u_* 键（真实用户过滤面在上游双信号）' );

		$ip_key = LoginCounters::KEY_IP . substr( hash_hmac( 'sha256', '203.0.113.7', 'login-counters-test' ), 0, 16 );
		$this->assertSame( 2, $mk['store'][ $ip_key ]['n'], 'IP 维度照常累计' );
	}

	/* ---------- 双信号：错误码分类表（02 §3.2） ---------- */

	public function test_classify_error_code_table(): void {
		// 用户存在+口令错——唯一双计形态（用户原话「真实存在的用户的密码错误」）。
		$this->assertSame( array( 'ip' => true, 'user' => true ), LoginCounters::classify_error_code( 'incorrect_password' ) );
		// 用户不存在（枚举/喷洒）——filter 未触发，只计 IP。
		$this->assertSame( array( 'ip' => true, 'user' => false ), LoginCounters::classify_error_code( 'invalid_username' ) );
		$this->assertSame( array( 'ip' => true, 'user' => false ), LoginCounters::classify_error_code( 'invalid_email' ) );
		// 本闸自身验证码失败——口令正确只是验证码失败，计入会惩罚已过码手滑的真人。
		$this->assertSame( array( 'ip' => true, 'user' => false ), LoginCounters::classify_error_code( 'wk_captcha_failed' ) );
		// 空提交（core 忽略清单不触发动作——防御性归零）。
		$this->assertSame( array( 'ip' => false, 'user' => false ), LoginCounters::classify_error_code( 'empty_username' ) );
		$this->assertSame( array( 'ip' => false, 'user' => false ), LoginCounters::classify_error_code( 'empty_password' ) );
		// 第三方认证回调自定义码——存在性不可知，保守只计 IP。
		$this->assertSame( array( 'ip' => true, 'user' => false ), LoginCounters::classify_error_code( 'some_oauth_plugin_error' ) );
		$this->assertSame( array( 'ip' => true, 'user' => false ), LoginCounters::classify_error_code( '' ) );
	}

	/* ---------- 站点级账号警报（wk_lf_alarm——新单键） ---------- */

	public function test_site_alarm_lights_on_user_dimension_saturation(): void {
		$clock = 1000000;
		$mk    = $this->make( $clock );
		$c     = $mk['counters'];

		$this->assertFalse( $c->site_alarm(), '零打扰基线：无警报' );
		for ( $i = 0; $i < 3; $i++ ) {
			$c->record_failure( '198.51.100.' . ( $i + 1 ), 'admin' ); // 跨 IP 饱和（每 IP 1 次）。
		}
		$this->assertTrue( $c->site_alarm(), '用户名维度饱和 → 站点警报点亮（全站登录页出码）' );
	}

	public function test_site_alarm_not_lit_by_ip_dimension_saturation_alone(): void {
		$clock = 1000000;
		$c     = $this->make( $clock )['counters'];

		for ( $i = 1; $i <= 10; $i++ ) {
			$c->record_failure( '203.0.113.7', '' ); // 只 IP 维度。
		}
		$this->assertTrue( $c->render_flag( '203.0.113.7' ), 'IP 饱和 → flag 亮' );
		$this->assertFalse( $c->site_alarm(), 'IP 维度饱和不点站点警报（警报=用户名维度专属聚合）' );
	}

	public function test_site_alarm_expires_with_window_and_renews_on_new_saturation(): void {
		$clock = 1000000;
		$mk    = $this->make( $clock );
		$c     = $mk['counters'];

		for ( $i = 0; $i < 3; $i++ ) {
			$c->record_failure( '198.51.100.' . ( $i + 1 ), 'admin' );
		}
		$this->assertTrue( $c->site_alarm() );

		$clock += 901; // 窗口 15min=900s 过：警报自然熄灭。
		$this->assertFalse( $c->site_alarm(), '警报 TTL=window 到期自然熄灭' );

		// 新窗口内重新饱和 3 次 → 警报随新饱和再次点亮（续期语义）。
		for ( $i = 0; $i < 3; $i++ ) {
			$c->record_failure( '198.51.100.' . ( $i + 10 ), 'admin' );
		}
		$this->assertTrue( $c->site_alarm(), '新的用户名维度饱和 → 警报再点亮' );
	}

	public function test_site_alarm_survives_successful_login_clear(): void {
		$clock = 1000000;
		$mk    = $this->make( $clock );
		$c     = $mk['counters'];

		for ( $i = 0; $i < 3; $i++ ) {
			$c->record_failure( '198.51.100.' . ( $i + 1 ), 'admin' );
		}
		$this->assertTrue( $c->site_alarm() );

		$c->clear( '203.0.113.7', 'admin' ); // 成功登录清键。

		$this->assertTrue( $c->site_alarm(), '站点警报不随单次成功清零（单次成功≠攻击停止，02 §3.2）' );
	}

	public function test_corrupted_site_alarm_reads_as_lit(): void {
		$clock = 1000000;
		$mk    = $this->make( $clock );
		$mk['store'][ LoginCounters::KEY_ALARM ] = 'corrupted'; // 直接置损坏值。
		$this->assertTrue( $mk['counters']->site_alarm(), '警报损坏按已点亮（fail-safe①同语义）' );
	}

	/* ---------- 渲染标志 + 验证期点亮闭环入口 ---------- */

	public function test_render_flag_lights_on_user_dimension_saturation_from_reporting_ip(): void {
		$clock = 1000000;
		$c     = $this->make( $clock )['counters'];

		for ( $i = 0; $i < 3; $i++ ) {
			$c->record_failure( '198.51.100.' . ( $i + 1 ), 'admin' ); // 报告者 IP 各异。
		}
		$this->assertTrue( $c->site_alarm() );
		$this->assertTrue( $c->render_flag( '198.51.100.3' ), '最后一个报告 IP 的 flag 点亮（饱和点亮源）' );
		$this->assertFalse( $c->render_flag( '203.0.113.77' ), '未涉 IP 的 flag 不亮（该 IP 出码由站点警报表达）' );
	}

	public function test_light_flag_is_public_for_verify_period_closure(): void {
		$clock = 1000000;
		$mk    = $this->make( $clock );
		$c     = $mk['counters'];

		$c->light_flag( '203.0.113.7' ); // 验证期拒绝 → 点亮（02 §3.2 验证期 flag 点亮闭环）。
		$this->assertTrue( $c->render_flag( '203.0.113.7' ), '闭环入口点亮后渲染期可读' );

		$clock += 901;
		$this->assertFalse( $c->render_flag( '203.0.113.7' ), 'flag TTL=window 到期熄灭' );
	}

	public function test_light_flag_ignores_empty_ip(): void {
		$clock = 1000000;
		$mk    = $this->make( $clock );
		$mk['counters']->light_flag( '' );
		$this->assertFalse( $mk['counters']->render_flag( '' ) );
	}

	/* ---------- 洪泛索引机制删除（max_user_keys 作废） ---------- */

	public function test_no_index_keys_are_created_and_user_keys_uncapped(): void {
		$clock = 1000000;
		$mk    = $this->make( $clock, array( 'user_threshold' => 100 ) ); // 阈拉高避免饱和短路。
		$c     = $mk['counters'];

		// 30 个用户名各 1 次（旧 max_user_keys=20 会被索引截断；新机制键空间=真实用户数，上游过滤）。
		for ( $i = 1; $i <= 30; $i++ ) {
			$c->record_failure( '203.0.113.7', 'real_user_' . $i );
		}

		foreach ( array_keys( $mk['store'] ) as $key ) {
			$this->assertStringStartsNotWith( 'wk_lf_idx_', $key, 'wk_lf_idx_* 索引机制整体删除（02 §3.2 v1.2 机制作废）' );
		}
		$user_keys = 0;
		foreach ( array_keys( $mk['store'] ) as $key ) {
			if ( 0 === strpos( $key, LoginCounters::KEY_USER ) ) {
				$user_keys++;
			}
		}
		$this->assertSame( 30, $user_keys, '用户名键无上限（定容价值由上游真实用户过滤承担）' );
	}

	public function test_old_combined_key_form_is_not_written(): void {
		$clock = 1000000;
		$mk    = $this->make( $clock );
		$mk['counters']->record_failure( '203.0.113.7', 'admin' );

		// 旧组合键形态 = wk_lf_<hmac(ip|username)>（无 u_/ip_ 子前缀）——新代码不再读写。
		$legacy = 'wk_lf_' . substr( hash_hmac( 'sha256', '203.0.113.7|admin', 'login-counters-test' ), 0, 16 );
		$this->assertArrayNotHasKey( $legacy, $mk['store'], '旧组合键形态零写入（TTL 自然过期零迁移）' );
	}

	/* ---------- saturation_source：触发归因（trigger_source 事件 meta 来源） ---------- */

	public function test_saturation_source_attributions(): void {
		$clock = 1000000;
		$c     = $this->make( $clock )['counters'];

		$this->assertNull( $c->saturation_source( '203.0.113.7', 'admin' ), '未饱和 → null' );

		for ( $i = 0; $i < 3; $i++ ) {
			$c->record_failure( '198.51.100.' . ( $i + 1 ), 'admin' ); // 用户维度饱和。
		}
		$this->assertSame( 'user_threshold', $c->saturation_source( '203.0.113.7', 'admin' ), '用户名维度 → user_threshold' );

		$c2 = $this->make( $clock )['counters'];
		for ( $i = 1; $i <= 10; $i++ ) {
			$c2->record_failure( '203.0.113.7', 'u' . $i ); // IP 维度饱和。
		}
		$this->assertSame( 'ip_threshold', $c2->saturation_source( '203.0.113.7', 'brand_new' ), 'IP 维度 → ip_threshold' );
	}

	/* ---------- 窗口语义（固定窗口：自首次失败起 15min，增量不续期） ---------- */

	public function test_window_expiry_resets_saturation(): void {
		$clock = 1000000;
		$c     = $this->make( $clock )['counters'];

		for ( $i = 0; $i < 3; $i++ ) {
			$c->record_failure( '203.0.113.7', 'admin' );
		}
		$this->assertTrue( $c->is_saturated( '203.0.113.7', 'admin' ) );

		$clock += 901; // 窗口 15min=900s 过后。
		$this->assertFalse( $c->is_saturated( '203.0.113.7', 'admin' ), '窗口过期计数作废' );
		$this->assertFalse( $c->render_flag( '203.0.113.7' ), '渲染标志同窗过期' );
	}

	public function test_window_is_fixed_not_extended_by_later_failures(): void {
		$clock = 1000000;
		$c     = $this->make( $clock )['counters'];

		for ( $i = 0; $i < 3; $i++ ) {
			$c->record_failure( '203.0.113.7', 'admin' );
		}
		$clock += 899; // 窗口内（<900s）再来一次失败：计数 +1 但不续期窗口。
		$c->record_failure( '203.0.113.7', 'editor' ); // 异用户名：不动 admin 桶。

		$clock += 2; // 越过 admin 桶原窗口终点（1000000+900）。
		$this->assertFalse( $c->is_saturated( '203.0.113.7', 'admin' ), '固定窗口：失败不刷新 exp' );
	}

	public function test_post_window_failure_reopens_fresh_bucket(): void {
		$clock = 1000000;
		$c     = $this->make( $clock )['counters'];

		for ( $i = 0; $i < 3; $i++ ) {
			$c->record_failure( '203.0.113.7', 'admin' );
		}
		$clock += 901;
		$c->record_failure( '203.0.113.7', 'admin' ); // 过期后新失败 → 新窗口计数 1。

		$this->assertFalse( $c->is_saturated( '203.0.113.7', 'admin' ), '过期后重开桶计数 1' );

		$clock += 899;
		$c->record_failure( '203.0.113.7', 'admin' );
		$c->record_failure( '203.0.113.7', 'admin' );
		$this->assertTrue( $c->is_saturated( '203.0.113.7', 'admin' ), '新窗口内累计 3 次再次饱和' );
	}

	/* ---------- fail-safe 读（v1.3 吸收①：损坏按饱和） ---------- */

	public function test_corrupted_user_key_reads_as_saturated(): void {
		$clock = 1000000;
		$mk    = $this->make( $clock );
		$c     = $mk['counters'];

		$c->record_failure( '203.0.113.7', 'admin' );
		$mk['store'][ LoginCounters::KEY_USER . substr( hash_hmac( 'sha256', 'u|admin', 'login-counters-test' ), 0, 16 ) ] = 'corrupted';
		$this->assertTrue( $c->is_saturated( '203.0.113.7', 'admin' ), '用户名键损坏按饱和（宁多渲染不漏放）' );
	}

	public function test_corrupted_ip_key_reads_as_saturated(): void {
		$clock = 1000000;
		$mk    = $this->make( $clock );
		$c     = $mk['counters'];

		$c->record_failure( '203.0.113.7', 'admin' );
		$mk['store'][ LoginCounters::KEY_IP . substr( hash_hmac( 'sha256', '203.0.113.7', 'login-counters-test' ), 0, 16 ) ] = array( 1, 2, 3 );
		$this->assertTrue( $c->is_saturated( '203.0.113.7', 'whoever' ), 'IP 键损坏按饱和' );
	}

	public function test_corrupted_flag_reads_as_true(): void {
		$clock = 1000000;
		$mk    = $this->make( $clock );
		$c     = $mk['counters'];

		$c->record_failure( '203.0.113.7', 'admin' );
		$this->assertFalse( $c->render_flag( '203.0.113.7' ), '1 次失败未饱和：标志不亮' );

		$c->record_failure( '203.0.113.7', 'admin' );
		$c->record_failure( '203.0.113.7', 'admin' ); // 饱和 → 标志点亮。
		$mk['store'][ LoginCounters::KEY_FLAG . substr( hash_hmac( 'sha256', '203.0.113.7', 'login-counters-test' ), 0, 16 ) ] = 42;
		$this->assertTrue( $c->render_flag( '203.0.113.7' ), '渲染标志损坏按已点亮' );
	}

	/* ---------- 登录成功清零（v1.3 吸收③：直接删键，接受清零竞态） ---------- */

	public function test_clear_on_success_removes_both_keys_and_flag_but_not_alarm(): void {
		$clock = 1000000;
		$c     = $this->make( $clock )['counters'];

		for ( $i = 0; $i < 3; $i++ ) {
			$c->record_failure( '203.0.113.7', 'admin' );
		}
		$this->assertTrue( $c->is_saturated( '203.0.113.7', 'admin' ) );
		$this->assertTrue( $c->site_alarm() );

		$c->clear( '203.0.113.7', 'admin' );

		$this->assertFalse( $c->is_saturated( '198.51.100.9', 'admin' ), '成功登录清用户名键（跨 IP 维度整清）' );
		$this->assertFalse( $c->is_saturated( '203.0.113.7', 'spray_other' ), '成功登录清 IP 键（IP 聚合整清）' );
		$this->assertFalse( $c->render_flag( '203.0.113.7' ), '渲染标志一并清' );
		$this->assertTrue( $c->site_alarm(), '站点警报保留（聚合语义）' );
	}

	/* ---------- 可配阈值（user_threshold 迁移键） ---------- */

	public function test_custom_thresholds_are_consumed(): void {
		$clock = 1000000;
		$c     = $this->make( $clock, array( 'user_threshold' => 2, 'ip_threshold' => 4 ) )['counters'];

		$c->record_failure( '203.0.113.7', 'admin' );
		$this->assertFalse( $c->is_saturated( '203.0.113.7', 'admin' ) );
		$c->record_failure( '198.51.100.1', 'admin' ); // 换 IP 照样累计。
		$this->assertTrue( $c->is_saturated( '203.0.113.7', 'admin' ), '自定义用户名阈 2' );

		$c2 = $this->make( $clock, array( 'user_threshold' => 2, 'ip_threshold' => 4 ) )['counters'];
		for ( $i = 1; $i <= 4; $i++ ) {
			$c2->record_failure( '203.0.113.8', 'u' . $i );
		}
		$this->assertTrue( $c2->is_saturated( '203.0.113.8', 'u_any' ), '自定义 IP 聚合阈 4' );
	}

	public function test_legacy_threshold_key_still_read_for_migration_compatibility(): void {
		$clock = 1000000;
		$c     = $this->make( $clock, array( 'threshold' => 2 ) )['counters'];
		$c->record_failure( '203.0.113.7', 'admin' );
		$c->record_failure( '198.51.100.1', 'admin' );
		$this->assertTrue( $c->is_saturated( '203.0.113.7', 'admin' ), '迁移前 option 直读场景：threshold 兼容（schema v2 迁移后不再出现）' );
	}

	public function test_invalid_threshold_values_fall_back_to_minimums(): void {
		$clock = 1000000;
		$c     = $this->make( $clock, array( 'user_threshold' => 0, 'window_minutes' => 0, 'ip_threshold' => -5 ) )['counters'];

		$c->record_failure( '203.0.113.7', 'admin' );
		$this->assertTrue( $c->is_saturated( '203.0.113.7', 'admin' ), '阈值下限 1：1 次失败即饱和' );
	}

	/* ---------- 缺席（无 transient）= 未超阈 ---------- */

	public function test_absent_keys_read_as_not_saturated(): void {
		$clock = 1000000;
		$c     = $this->make( $clock )['counters'];

		$this->assertFalse( $c->is_saturated( '203.0.113.7', 'admin' ), '无记录=零打扰' );
		$this->assertFalse( $c->render_flag( '203.0.113.7' ) );
		$this->assertFalse( $c->site_alarm() );
	}

	/* ---------- 饱和短路（R3 §4.2：桶已饱和跳过 bump 写） ---------- */

	public function test_saturated_bucket_skips_bump_write(): void {
		$clock = 1000000;
		$mk    = $this->make( $clock );
		$c     = $mk['counters'];

		for ( $i = 0; $i < 3; $i++ ) {
			$c->record_failure( '203.0.113.7', 'admin' );
		}
		$key = LoginCounters::KEY_USER . substr( hash_hmac( 'sha256', 'u|admin', 'login-counters-test' ), 0, 16 );
		$before = $mk['store'][ $key ];

		$c->record_failure( '203.0.113.7', 'admin' ); // 饱和后继续失败——写短路（值不变）。

		$this->assertSame( $before, $mk['store'][ $key ], '饱和桶跳过 bump 写（值与 exp 均不变）' );
	}
}
