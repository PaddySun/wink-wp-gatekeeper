<?php
/**
 * W3 批 C · 功能新增回归门（施工图 W3-重设计施工图-2026-11-18.md §2 批 C）。
 *
 * 五件覆盖（TDD 先红后绿）：
 *   N1 测试凭据持久化——probe_log 单 option（per 驱动一槽覆盖写）/五态闭集/message
 *      不入库红线（R4）/AJAX 端点写入桥 source-scan/test_credential_btn 读真值；
 *   N2 region 显式键——schema captcha.aliyun.region（cn|sgp 默认 cn）+ Sanitize 白名单
 *      + ENDPOINT_MAP 双端联动（PHP endpoint_for_region + aliyun.js data-wk-region，
 *      sgp 值=静态推断 R7）/Tab② radio 实键化；
 *   N3 mode 平移——出厂默认 embed→popup + migrate 撞车守卫（凭据已配=显式选择保留
 *      embed，H-3 同款）/卡面注记改「已平移」；
 *   N6 AbuseIPDB 平移——soft_quota 3000→900 / daily_quota 4750→950（免费档 1000/天
 *      留 5%，用户 2026-11-18 拍板）+ 双键独立特征值平移 + Tab④ hint 文案；
 *   向导 4 步实装——wink_gatekeeper_wizard_state option 只由 activation hook 写 'pending'
 *      （R5：存量升级站 option 缺失=永不自动弹）/4 步面板/实键闭集/百度推荐序/WP 两开关
 *      只读黄条真值零字段名/完成跳过置 done|skipped。
 *
 * 行为级面：probe log / 向导 option 读写经 tests/bootstrap.php 的 WpOptionStub
 * （WinkGatekeeper\Settings 命名空间 get_option/update_option/date_i18n 桩）；
 * 渲染与端点写入桥为 source-scan（纯 PHP 单测口径，浏览器验证归部署手测批）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\captcha\AliyunCaptchaDriver;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Settings\Sanitize;
use WinkGatekeeper\Settings\SettingsPage;
use WinkGatekeeper\Settings\SettingsSchema;

final class W3BatchCTest extends TestCase {

	protected function setUp(): void {
		WpOptionStub::reset();
	}

	protected function tearDown(): void {
		WpOptionStub::reset();
	}

	/* -----------------------------------------------------------------
	 * 源码提取件（花括号配平，UiTab4Zones 契约测试同形态）
	 * ----------------------------------------------------------------- */

	private static function src( string $rel ): string {
		$src = file_get_contents( __DIR__ . '/../' . $rel );
		self::assertIsString( $src, "{$rel} 必须可读" );
		return (string) $src;
	}

	private static function sp(): string {
		return self::src( 'includes/settings/SettingsPage.php' );
	}

	private static function function_body( string $src, string $name ): string {
		$start = strpos( $src, 'function ' . $name );
		self::assertNotFalse( $start, "函数 {$name} 必须存在" );
		$open  = strpos( $src, '{', (int) $start );
		$depth = 0;
		$len   = strlen( $src );
		for ( $i = (int) $open; $i < $len; $i++ ) {
			if ( '{' === $src[ $i ] ) {
				$depth++;
			} elseif ( '}' === $src[ $i ] ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $src, (int) $open, $i - (int) $open + 1 );
				}
			}
		}
		self::fail( "函数 {$name} 花括号不配平" );
		return '';
	}

	private static function body( string $name ): string {
		return self::function_body( self::sp(), $name );
	}

	/**
	 * Reflection 直调私有静态方法（echo 型 ob 捕获；return 型取返回值——void 方法返回输出缓冲）。
	 *
	 * @param string $method 方法名。
	 * @param mixed  ...$args 参数。
	 * @return mixed
	 */
	private static function invoke( string $method, ...$args ) {
		$ref = new \ReflectionMethod( SettingsPage::class, $method );
		$ref->setAccessible( true );
		ob_start();
		$out = $ref->invoke( null, ...$args );
		$buf = (string) ob_get_clean();
		return null !== $out ? $out : $buf;
	}

	private static function aliyun_settings( ?array $aliyun = null ): array {
		return array(
			'captcha' => array(
				'aliyun' => $aliyun ?? array(
					'access_key_id'     => 'LTAIkeksi',
					'access_key_secret' => 'sekreto',
					'scene_id'          => 'scene-9',
					'prefix'            => 'wkpre',
					'mode'              => 'embed',
				),
			),
			'copy'    => array( 'messages' => array() ),
			'brand'   => array( 'contact_email' => '' ),
		);
	}

	/* ================================================================
	 * N3 · mode 平移（embed→popup + migrate 撞车守卫）
	 * ================================================================ */

	public function test_mode_factory_default_is_popup(): void {
		$this->assertSame( 'popup', SettingsSchema::defaults()['captcha']['aliyun']['mode'], 'N3：出厂默认 embed→popup（用户拍板批 C 落地）' );
	}

	public function test_mode_shift_legacy_embed_without_creds_moves_to_popup(): void {
		// 出厂特征值 embed + 四凭据全空（站长从未动过）→ 移除键由 merge_defaults 补 popup。
		$merged = SettingsSchema::migrate( array(
			'captcha' => array(
				'aliyun' => array(
					'access_key_id'     => '',
					'access_key_secret' => '',
					'scene_id'          => '',
					'prefix'            => '',
					'mode'              => 'embed',
				),
			),
		) );
		$this->assertSame( 'popup', $merged['captcha']['aliyun']['mode'], '存量 embed（零凭据）平移新默认 popup' );
	}

	public function test_mode_shift_keeps_embed_when_creds_configured(): void {
		// 撞车守卫：凭据已配=站长显式选择的铁证 → embed 原样保留（H-3 同款判据）。
		$merged = SettingsSchema::migrate( array(
			'captcha' => array(
				'aliyun' => array(
					'access_key_id'     => 'LTAI',
					'access_key_secret' => 'sec',
					'scene_id'          => 's',
					'prefix'            => 'p',
					'mode'              => 'embed',
				),
			),
		) );
		$this->assertSame( 'embed', $merged['captcha']['aliyun']['mode'], '凭据已配=显式选择保留 embed（保守正确——宁可漏平移不误迁）' );
	}

	public function test_mode_shift_preserves_explicit_popup_and_is_idempotent(): void {
		$merged = SettingsSchema::migrate( array(
			'captcha' => array( 'aliyun' => array( 'mode' => 'popup' ) ),
		) );
		$this->assertSame( 'popup', $merged['captcha']['aliyun']['mode'], '显式 popup 原样保留' );
		$this->assertSame( $merged, SettingsSchema::migrate( $merged ), '幂等——对已迁移树再跑一次无变化' );
	}

	public function test_tab2_mode_radio_default_tag_on_popup_and_shift_note(): void {
		$body = self::body( 'render_tab_captcha' );
		$this->assertStringContainsString( '弹层（默认）', $body, 'popup 档挂「默认」角标（N3 平移后）' );
		$this->assertStringNotContainsString( '嵌入触发条（默认）', $body, 'embed 档「默认」角标退役' );
		$this->assertStringContainsString( '已平移', $body, '卡面注记改「已平移」（批 B「待施工平移」记号退役）' );
		$this->assertStringNotContainsString( '随批 C 落地', $body, '「随批 C 落地」待施工措辞退役' );
	}

	/* ================================================================
	 * N6 · AbuseIPDB 平移（soft_quota 3000→900 / daily_quota 4750→950）
	 * ================================================================ */

	public function test_abuseipdb_quota_defaults_shifted(): void {
		$abuse = SettingsSchema::defaults()['iprep']['abuseipdb'];
		$this->assertSame( 900, $abuse['soft_quota'], 'N6：软顶 3000→900（免费档 1000/天留 5%——用户拍板）' );
		$this->assertSame( 950, $abuse['daily_quota'], 'N6：日配额 4750→950' );
		$this->assertGreaterThan( $abuse['soft_quota'], $abuse['daily_quota'], '软顶 < 硬停转——两道额度闸序保持' );
	}

	public function test_quota_shift_dual_feature_values_move(): void {
		$merged = SettingsSchema::migrate( array(
			'iprep' => array( 'abuseipdb' => array( 'soft_quota' => 3000, 'daily_quota' => 4750 ) ),
		) );
		$this->assertSame( 900, $merged['iprep']['abuseipdb']['soft_quota'], '双特征值平移' );
		$this->assertSame( 950, $merged['iprep']['abuseipdb']['daily_quota'], '双特征值平移' );
	}

	public function test_quota_shift_is_independent_per_key(): void {
		// 单显式保留：soft_quota 显式 1200 不动、daily_quota 特征值 4750 平移——两键独立可各自命中。
		$merged = SettingsSchema::migrate( array(
			'iprep' => array( 'abuseipdb' => array( 'soft_quota' => 1200, 'daily_quota' => 4750 ) ),
		) );
		$this->assertSame( 1200, $merged['iprep']['abuseipdb']['soft_quota'], '显式其他值原样保留' );
		$this->assertSame( 950, $merged['iprep']['abuseipdb']['daily_quota'], '特征值键独立平移（不强制配对）' );

		$merged2 = SettingsSchema::migrate( array(
			'iprep' => array( 'abuseipdb' => array( 'soft_quota' => 3000, 'daily_quota' => 4800 ) ),
		) );
		$this->assertSame( 900, $merged2['iprep']['abuseipdb']['soft_quota'], 'soft 特征值平移' );
		$this->assertSame( 4800, $merged2['iprep']['abuseipdb']['daily_quota'], 'daily 显式其他值保留' );
	}

	public function test_quota_shift_custom_values_preserved(): void {
		$merged = SettingsSchema::migrate( array(
			'iprep' => array( 'abuseipdb' => array( 'soft_quota' => 2000, 'daily_quota' => 3000 ) ),
		) );
		$this->assertSame( 2000, $merged['iprep']['abuseipdb']['soft_quota'] );
		$this->assertSame( 3000, $merged['iprep']['abuseipdb']['daily_quota'] );
	}

	public function test_tab4_free_tier_copy_unified(): void {
		$body = self::body( 'render_iprep_zone_check' );
		$this->assertStringContainsString( '免费档 1000', $body, 'Tab④ 免费档文案统一「1000 次/天（拍板统一口径）」' );
		$this->assertStringNotContainsString( '贡献者 5000', $body, '「贡献者 5000 次/天」旧文案按 N6 拍板退役' );
		$this->assertStringContainsString( '900', $body, '软顶默认显示 900' );
		$this->assertStringContainsString( '950', $body, '日配额默认显示 950' );
		$this->assertStringNotContainsString( '默认 3000', $body, '软顶「默认 3000」措辞退役' );
		$this->assertStringNotContainsString( '默认 4750', $body, '日配额「默认 4750」措辞退役' );
		$this->assertStringNotContainsString( '账户实配 5000/天', $body, '「账户实配 5000/天」个人账户口径退役（统一为免费档口径）' );
	}

	/* ================================================================
	 * N2 · region 显式键（schema/Sanitize/ENDPOINT_MAP 双端联动）
	 * ================================================================ */

	public function test_region_new_key_defaults_cn(): void {
		$this->assertSame( 'cn', SettingsSchema::defaults()['captcha']['aliyun']['region'], 'N2：captcha.aliyun.region 新键默认 cn（现行行为）' );
		$this->assertSame( 'cn', SettingsSchema::merge_defaults( array() )['captcha']['aliyun']['region'], '存量站 merge_defaults 整树补齐' );
	}

	public function test_region_sanitize_whitelist(): void {
		$this->assertSame( 'aliyun_region', Sanitize::rules()['captcha.aliyun.region'], '规则表新类型（submit_flow 同族白名单形态）' );
		$this->assertSame( 'cn', Sanitize::clean_value( 'cn', 'aliyun_region' ) );
		$this->assertSame( 'sgp', Sanitize::clean_value( 'sgp', 'aliyun_region' ), 'sgp 显式值保留' );
		$this->assertSame( 'cn', Sanitize::clean_value( 'us', 'aliyun_region' ), '非法值归 cn（fail-safe 现行行为）' );
		$this->assertSame( 'cn', Sanitize::clean_value( '', 'aliyun_region' ) );
		$this->assertSame( 'cn', Sanitize::clean_value( null, 'aliyun_region' ) );
		list( $clean, ) = Sanitize::clean( array( 'captcha' => array( 'aliyun' => array( 'region' => 'sgp' ) ) ) );
		$this->assertSame( 'sgp', $clean['captcha']['aliyun']['region'], '整树清洗白名单类型对原始标量比对' );
	}

	public function test_endpoint_map_constants(): void {
		$ref  = new \ReflectionClass( AliyunCaptchaDriver::class );
		$cn   = $ref->getConstant( 'ENDPOINT_MAP' );
		$host = $ref->getConstant( 'HOST_MAP' );
		$this->assertSame( 'https://captcha.cn-shanghai.aliyuncs.com/', $cn['cn'], 'cn 档=现行端点' );
		$this->assertSame( 'https://captcha.ap-southeast-1.aliyuncs.com/', $cn['sgp'], 'sgp 档=ap-southeast-1（静态推断 R7——未实机验证，复验不过则 sgp 档 disabled）' );
		$this->assertSame( 'captcha.cn-shanghai.aliyuncs.com', $host['cn'] );
		$this->assertSame( 'captcha.ap-southeast-1.aliyuncs.com', $host['sgp'] );
		$src = self::src( 'includes/drivers/captcha/AliyunCaptchaDriver.php' );
		$this->assertStringContainsString( '静态推断', $src, 'sgp 档静态推断注记在场（R7 纪律）' );
	}

	public function test_endpoint_for_region_matrix(): void {
		// 缺键/显式 cn/非法值 → cn 现值=现行行为保底；sgp → ap-southeast-1。
		$missing = AliyunCaptchaDriver::endpoint_for_region( $this->aliyun_settings( array( 'access_key_id' => 'a' ) ) );
		$this->assertSame( 'https://captcha.cn-shanghai.aliyuncs.com/', $missing['endpoint'], '缺键回落 cn（现行行为保底）' );
		$this->assertSame( 'captcha.cn-shanghai.aliyuncs.com', $missing['host'] );

		$cn = AliyunCaptchaDriver::endpoint_for_region( $this->aliyun_settings( array( 'region' => 'cn' ) ) );
		$this->assertSame( 'https://captcha.cn-shanghai.aliyuncs.com/', $cn['endpoint'] );

		$sgp = AliyunCaptchaDriver::endpoint_for_region( $this->aliyun_settings( array( 'region' => 'sgp' ) ) );
		$this->assertSame( 'https://captcha.ap-southeast-1.aliyuncs.com/', $sgp['endpoint'] );
		$this->assertSame( 'captcha.ap-southeast-1.aliyuncs.com', $sgp['host'] );

		$bogus = AliyunCaptchaDriver::endpoint_for_region( $this->aliyun_settings( array( 'region' => 'us-west' ) ) );
		$this->assertSame( 'https://captcha.cn-shanghai.aliyuncs.com/', $bogus['endpoint'], '非法值闭集回落 cn' );
	}

	public function test_verify_tri_uses_region_endpoint(): void {
		$captured = null;
		$client   = new RemoteClient( static function ( string $url, array $args ) use ( &$captured ) {
			$captured = array( $url, $args );
			return array( 'response' => array( 'code' => 200 ), 'body' => '{"Result":{"VerifyResult":true,"VerifyCode":"T001"}}' );
		}, static function ( string $usage_key ): void {}, static function (): void {} );
		$settings                    = $this->aliyun_settings( array(
			'access_key_id'     => 'LTAIkeksi',
			'access_key_secret' => 'sekreto',
			'scene_id'          => 'scene-9',
			'prefix'            => 'wkpre',
			'region'            => 'sgp',
		) );
		$driver = new AliyunCaptchaDriver( $settings, $client );
		$driver->verify_tri( array( 'captcha_verify_param' => 'b64', 'user_ip' => '1.2.3.4' ) );
		list( $url, $args ) = $captured;
		$this->assertSame( 'https://captcha.ap-southeast-1.aliyuncs.com/', $url, 'verify 走 region→endpoint 映射' );
		$this->assertSame( 'captcha.ap-southeast-1.aliyuncs.com', $args['headers']['host'], '签名 host 同组联动' );
	}

	public function test_render_outputs_data_wk_region(): void {
		$cn = ( new AliyunCaptchaDriver( $this->aliyun_settings(), new RemoteClient() ) )->render( array( 'gate' => 'comment', 'stub' => 'S' ) );
		$this->assertStringContainsString( 'data-wk-region="cn"', $cn, '前端容器带 region（aliyun.js 读实键——恒 cn 硬编码退役）' );

		$sgp_driver = new AliyunCaptchaDriver( $this->aliyun_settings( array(
			'access_key_id'     => 'a',
			'access_key_secret' => 's',
			'scene_id'          => 'x',
			'prefix'            => 'p',
			'region'            => 'sgp',
		) ), new RemoteClient() );
		$this->assertStringContainsString( 'data-wk-region="sgp"', $sgp_driver->render( array( 'gate' => 'comment', 'stub' => 'S' ) ), 'sgp 显式值透传前端' );

		$missing = ( new AliyunCaptchaDriver( $this->aliyun_settings( array( 'access_key_id' => 'a' ) ), new RemoteClient() ) )->render( array( 'gate' => 'comment', 'stub' => 'S' ) );
		$this->assertStringContainsString( 'data-wk-region="cn"', $missing, '缺键渲染回落 cn' );
	}

	public function test_aliyun_js_reads_region_from_container(): void {
		$src = self::src( 'assets/js/drivers/aliyun.js' );
		$this->assertStringContainsString( 'data-wk-region', $src, '适配器从容器 data-wk-region 读 region（PHP render 输出）' );
		$this->assertDoesNotMatchRegularExpression( "/region:\s*'cn'/", $src, 'AliyunCaptchaConfig region 恒 cn 硬编码退役（读实键值）' );
	}

	public function test_tab2_region_radio_real_control(): void {
		$body = self::body( 'render_tab_captcha' );
		$this->assertStringContainsString( "'captcha', 'aliyun', 'region'", $body, 'Tab② 阿里卡 ⚙ 折叠内 region radio 实键（规划行退役）' );
		$this->assertStringContainsString( 'value="sgp"', $body, 'sgp 档可选（复验不过则批 D 前 disabled——R7）' );
		$this->assertStringContainsString( '切换后建议点测试凭据', $body, 'region 变更提示「须与服务端一致，切换后建议点测试凭据」' );
		$this->assertStringContainsString( '未实机验证', $body, 'sgp 档静态推断注记在 UI 描述（R7 施工纪律）' );
		$this->assertStringNotContainsString( '本行为规划位', $body, 'region 规划位文案退役（实键控件落位）' );
	}

	/* ================================================================
	 * N1 · 测试凭据持久化（probe log 单 option）
	 * ================================================================ */

	public function test_probe_log_option_constants(): void {
		$this->assertSame( 'wink_gatekeeper_probe_log', SettingsPage::OPTION_PROBE_LOG, 'N1 单 option 名（R4）' );
		$this->assertSame( 'wink_gatekeeper_wizard_state', SettingsPage::OPTION_WIZARD_STATE, 'R5 向导状态 option 名' );
	}

	public function test_probe_state_closed_set_matrix(): void {
		foreach ( array( 'green', 'yellow', 'red', 'gray' ) as $state ) {
			$this->assertSame( $state, SettingsPage::probe_log_normalize_state( $state ), "记录态 {$state} 在闭集内原样" );
		}
		$this->assertSame( '', SettingsPage::probe_log_normalize_state( '' ), '空=未测试（缺席态）' );
		foreach ( array( 'bogus', 'GREEN', 1, null, array() ) as $bad ) {
			$this->assertSame( '', SettingsPage::probe_log_normalize_state( $bad ), '闭集外/非字符串回落未测试（五态闭集纪律）' );
		}
	}

	public function test_probe_log_driver_keys_closed_set(): void {
		$expected = array( 'tencent', 'aliyun', 'tencent_tms', 'aliyun_green', 'baidu', 'deepseek', 'openai_custom', 'url_audit_provider', 'abuseipdb' );
		$this->assertSame( $expected, array_values( SettingsPage::PROBE_LOG_DRIVERS ), '驱动键闭集（R4——avatar 头像探测非凭据不入集）' );
		$this->assertNotContains( 'avatar', SettingsPage::PROBE_LOG_DRIVERS );
	}

	public function test_probe_log_record_overwrite_write_shape(): void {
		self::invoke( 'probe_log_record', 'aliyun', 'green' );
		$log = WpOptionStub::$options[ SettingsPage::OPTION_PROBE_LOG ];
		$this->assertSame( array( 'state' => 'green' ), array_intersect_key( $log['aliyun'], array( 'state' => 1 ) ), 'state 落库' );
		$this->assertArrayHasKey( 'tested_at', $log['aliyun'], '时间戳落库' );
		$this->assertArrayNotHasKey( 'message', $log['aliyun'], 'message 不入库（R4 隐私红线——服务商响应摘要最小化）' );
		$this->assertIsInt( $log['aliyun']['tested_at'] );

		// per 驱动一槽覆盖写：同驱动二次记录覆盖、他驱动槽不受扰。
		self::invoke( 'probe_log_record', 'aliyun', 'red' );
		self::invoke( 'probe_log_record', 'baidu', 'yellow' );
		$log2 = WpOptionStub::$options[ SettingsPage::OPTION_PROBE_LOG ];
		$this->assertSame( 'red', $log2['aliyun']['state'], '覆盖式——每驱动一槽自限量（零 retention 口需求）' );
		$this->assertSame( 'yellow', $log2['baidu']['state'], '他驱动槽互不影响' );
		$this->assertCount( 2, $log2, '槽位不膨胀（per 驱动一槽）' );
	}

	public function test_probe_log_invalid_inputs_fail_safe(): void {
		self::invoke( 'probe_log_record', 'bogus_driver', 'green' );
		$this->assertArrayNotHasKey( SettingsPage::OPTION_PROBE_LOG, WpOptionStub::$options, '闭集外驱动键不落库' );
		self::invoke( 'probe_log_record', 'aliyun', 'bogus_state' );
		$log = WpOptionStub::$options[ SettingsPage::OPTION_PROBE_LOG ] ?? array();
		if ( isset( $log['aliyun'] ) ) {
			$this->assertSame( '', $log['aliyun']['state'], '闭集外状态归一未测试（空态不落脏数据）' );
		}
	}

	public function test_probe_log_record_source_scan_shape(): void {
		$body = self::body( 'probe_log_record' );
		$this->assertStringContainsString( 'update_option', $body, '写入=update_option 覆盖式（R4）' );
		$this->assertStringContainsString( "'state'", $body, 'state 键' );
		$this->assertStringContainsString( 'tested_at', $body, 'tested_at 键（时间戳）' );
		$this->assertDoesNotMatchRegularExpression( "/'message'/", $body, 'message 不入库红线（源面闭集——只有 state+tested_at 两键）' );
	}

	public function test_ajax_handlers_write_probe_log(): void {
		$handlers = array(
			'ajax_test_credentials'            => 'tencent',
			'ajax_test_credentials_aliyun'     => 'aliyun',
			'ajax_test_credentials_tms'        => 'tencent_tms',
			'ajax_test_credentials_aliyun_green' => 'aliyun_green',
			'ajax_test_credentials_baidu'      => 'baidu',
			'ajax_test_credentials_deepseek'   => 'deepseek',
			'ajax_test_credentials_openai_custom' => 'openai_custom',
			'ajax_test_credentials_url_audit'  => 'url_audit_provider',
			'ajax_test_iprep'                  => 'abuseipdb',
		);
		foreach ( $handlers as $handler => $driver_key ) {
			$body = self::body( $handler );
			$this->assertStringContainsString( 'probe_log_record(', $body, "{$handler} 收口写 probe log（N1 写入点=各 AJAX 测试凭据 handler）" );
			$this->assertMatchesRegularExpression(
				"/probe_log_record\(\s*'" . $driver_key . "'/",
				$body,
				"{$handler} 记录驱动键 {$driver_key}（闭集登记）"
			);
			$rec_pos = strrpos( $body, 'probe_log_record(' );
			$json_pos = strrpos( $body, 'wp_send_json' );
			$this->assertLessThan( $json_pos, $rec_pos, "{$handler} 写入序=分类完成后、wp_send_json 前（蓝图 N1 收口位）" );
		}
		// 头像探测非凭据域：不写 probe log（测的是镜像通不通非密钥）。
		$avatar = self::body( 'ajax_test_avatar_source' );
		$this->assertStringNotContainsString( 'probe_log_record', $avatar, 'avatar 探测不写 probe log（非凭据语境）' );
	}

	public function test_credential_btn_reads_probe_log_truth(): void {
		// 有记录 → 显真实态 + 等宽小字时间（批 A/B「未测试」初态退役）。
		self::invoke( 'probe_log_record', 'abuseipdb', 'green' );
		// tested_at 锚定（record 写 time()——WP 边界时钟；测试锚定后断言 date_i18n 形态）。
		$log = WpOptionStub::$options[ SettingsPage::OPTION_PROBE_LOG ];
		$log['abuseipdb']['tested_at'] = strtotime( '2026-11-18 14:30:00' );
		WpOptionStub::set( SettingsPage::OPTION_PROBE_LOG, $log );
		$html = (string) self::invoke( 'test_credential_btn', 'id="wk-x-test-credentials"', 'id="wk-x-test-result"', 'nonce123', '测试凭据', 'abuseipdb' );
		$this->assertStringContainsString( 'badge b-ok', $html, '有记录=绿态徽标' );
		$this->assertStringContainsString( '贯通 · 凭据可用', $html, '状态词' );
		$this->assertStringContainsString( '上次测试：11月18日 14:30 · 贯通', $html, '「上次测试：m月d日 H:i · 状态词」（date_i18n）' );
		$this->assertStringNotContainsString( '上次测试：--', $html, '无占位残留' );

		// 红态矩阵。
		self::invoke( 'probe_log_record', 'tencent', 'red' );
		$html2 = (string) self::invoke( 'test_credential_btn', 'id="wk-y-test-credentials"', 'id="wk-y-test-result"', 'nonce123', '测试凭据', 'tencent' );
		$this->assertStringContainsString( 'badge b-err', $html2, '红=被拒徽标' );

		// 无记录 → 未测试中性灰初始态保持。
		$html3 = (string) self::invoke( 'test_credential_btn', 'id="wk-z-test-credentials"', 'id="wk-z-test-result"', 'nonce123', '测试凭据', 'deepseek' );
		$this->assertStringContainsString( 'badge b-dim', $html3, '无记录=未测试' );
		$this->assertStringContainsString( '上次测试：--', $html3, '无记录时间位占位' );
	}

	public function test_result_persistence_copy_contract_flipped(): void {
		$sp = self::sp();
		// 「结果不落库」字样仅存于两处非凭据语境（IP 诊断面板 + 头像源探测）。
		$this->assertSame(
			2,
			substr_count( $sp, '结果不落库' ),
			'「结果不落库」字样收口两处非凭据语境（IP 诊断/头像源——其余凭据语境全线改写为「结果记录最近一次测试状态与时间」）'
		);
		$this->assertGreaterThanOrEqual( 9, substr_count( $sp, '结果记录最近一次测试状态与时间' ), '九个凭据测试位新契约文案在场（tencent/aliyun/tms/green/baidu/deepseek/openai_custom/url_audit/abuseipdb——const/handler docblock 同批翻转计数只增）' );
		foreach ( array( 1905, 2034, 2827, 2949, 3043, 3160, 3289, 3659, 4632 ) as $desc_line_hint ) {
			unset( $desc_line_hint ); // 行号随施工漂移——九描述位由下方逐位锚定。
		}
		foreach ( array(
			'发一次全参假票核验：绿 = 凭据可用；红 = CAM 密钥（SecretId/SecretKey）错；黄 = 必填参数缺。结果记录最近一次测试状态与时间。',
			'发一次假参实调（VerifyIntelligentCaptcha）：绿 = 凭据可用；红 = AccessKey/签名/权限错（含 RAM 未授权提示）；黄 = 必填参数缺。结果记录最近一次测试状态与时间。',
			'发一次正常文本实测：绿 = 凭据可用（顺带解除当日停转）；红 = 密钥错或未开通套餐包；黄 = 参数错。结果记录最近一次测试状态与时间。',
			'发一次正常短文本实测：绿 = 凭据可用（顺带解除当日停转）；红 = 未授权/欠费/未开通（Code=408）或密钥/签名错；黄 = 参数错或必填缺。结果记录最近一次测试状态与时间。',
			'发一次正常文本实测：绿 = 凭据可用（顺带解除当日停转）；红 = 鉴权失败（API Key/Secret Key 错或未开通服务）；黄 = 参数错或必填缺。结果记录最近一次测试状态与时间。',
			'黄 = API Key 未填。结果记录最近一次测试状态与时间。',
			'泛端点无标准余额 API，不显示余额。结果记录最近一次测试状态与时间。',
			'红 = 401/402 密钥被拒；黄 = API Key 未填。结果记录最近一次测试状态与时间。',
			'绿 = 凭据可用；红 = 密钥被拒或连不上。结果记录最近一次测试状态与时间。',
		) as $needle ) {
			$this->assertStringContainsString( $needle, $sp, '凭据测试位描述翻转（结果记录最近一次测试状态与时间）：' . mb_substr( $needle, 0, 24 ) . '…' );
		}
	}

	/* ================================================================
	 * 向导 4 步实装（R5 + N5 键面闭集）
	 * ================================================================ */

	public function test_wizard_state_constants_closed_set(): void {
		$this->assertSame( 'pending', SettingsPage::WIZARD_STATE_PENDING, 'R5：激活写 pending' );
		$this->assertSame( 'done', SettingsPage::WIZARD_STATE_DONE );
		$this->assertSame( 'skipped', SettingsPage::WIZARD_STATE_SKIPPED );
		$this->assertSame( array( 'pending', 'done', 'skipped' ), array_values( SettingsPage::WIZARD_STATES ), '三态闭集（缺省=无记录）' );
	}

	public function test_wizard_state_read_whitelist(): void {
		foreach ( array( 'pending', 'done', 'skipped' ) as $state ) {
			WpOptionStub::set( SettingsPage::OPTION_WIZARD_STATE, $state );
			$this->assertSame( $state, SettingsPage::wizard_state(), "合法态 {$state} 读回" );
		}
		WpOptionStub::set( SettingsPage::OPTION_WIZARD_STATE, 'bogus' );
		$this->assertSame( '', SettingsPage::wizard_state(), '闭集外归一无记录' );
		WpOptionStub::reset();
		$this->assertSame( '', SettingsPage::wizard_state(), 'option 缺失=无记录（存量升级站语义——永不自动弹）' );
	}

	public function test_wizard_auto_open_only_pending_without_tab(): void {
		$this->assertTrue( SettingsPage::wizard_should_auto_open( 'pending', false ), 'pending + 无显式 tab=自动弹（新装首次进入）' );
		$this->assertFalse( SettingsPage::wizard_should_auto_open( 'pending', true ), '显式 tab 参数=正常 Tab 面（不弹）' );
		$this->assertFalse( SettingsPage::wizard_should_auto_open( 'done', false ), 'done 不弹' );
		$this->assertFalse( SettingsPage::wizard_should_auto_open( 'skipped', false ), 'skipped 不弹' );
		$this->assertFalse( SettingsPage::wizard_should_auto_open( '', false ), '无记录（存量站）恒不弹——零 migrate 扫描差分' );
	}

	public function test_wizard_state_for_action_map(): void {
		$this->assertSame( 'done', SettingsPage::wizard_state_for_action( 'done' ), '完成→done' );
		$this->assertSame( 'skipped', SettingsPage::wizard_state_for_action( 'skip' ), '跳过→skipped' );
		$this->assertSame( '', SettingsPage::wizard_state_for_action( 'bogus' ), '非法 action 拒写（不可逆态只认闭集入口）' );
	}

	public function test_wizard_state_written_only_by_activation_hook(): void {
		$plugin = self::src( 'wink-wp-gatekeeper.php' );
		$this->assertStringContainsString( 'register_activation_hook', $plugin, 'activation hook 在场' );
		$this->assertMatchesRegularExpression(
			"/add_option\(\s*[\s\S]{0,120}SettingsPage::OPTION_WIZARD_STATE,\s*[\s\S]{0,120}SettingsPage::WIZARD_STATE_PENDING/",
			$plugin,
			'activation hook 回调写向导状态 pending（add_option 不覆盖——done/skipped 不可逆）'
		);
		$this->assertStringNotContainsString( 'wizard', self::src( 'includes/settings/SettingsSchema.php' ), 'schema 零 wizard 键（R5：不进配置树、零 migrate 扫描）' );
		// SettingsPage 内向导状态写点仅两 handler（done/skip）。
		foreach ( array( 'handle_wizard_finish', 'handle_wizard_skip' ) as $fn ) {
			$body = self::body( $fn );
			$this->assertStringContainsString( 'update_option( self::OPTION_WIZARD_STATE', $body, "{$fn} 写向导状态 option" );
		}
		$this->assertSame(
			2,
			substr_count( self::sp(), 'update_option( self::OPTION_WIZARD_STATE' ),
			'向导状态写点收口恰两处（done/skip——pending 只由 activation hook 写）'
		);
	}

	public function test_wizard_actions_registered(): void {
		$this->assertSame( 'wink_gatekeeper_wizard_done', SettingsPage::ACTION_WIZARD_DONE, '完成 admin action' );
		$this->assertSame( 'wink_gatekeeper_wizard_skip', SettingsPage::ACTION_WIZARD_SKIP, '跳过 admin action' );
		$bootstrap = self::src( 'includes/Bootstrap.php' );
		$this->assertStringContainsString( "admin_post_' . SettingsPage::ACTION_WIZARD_DONE", $bootstrap, 'Bootstrap 注册完成 action' );
		$this->assertStringContainsString( "admin_post_' . SettingsPage::ACTION_WIZARD_SKIP", $bootstrap, 'Bootstrap 注册跳过 action' );
		foreach ( array( 'handle_wizard_finish', 'handle_wizard_skip' ) as $fn ) {
			$body = self::body( $fn );
			$this->assertStringContainsString( "current_user_can( 'manage_options' )", $body, "{$fn} 能力闸" );
			$this->assertStringContainsString( 'check_admin_referer', $body, "{$fn} nonce 闸" );
			$this->assertStringContainsString( 'wp_safe_redirect', $body, "{$fn} 重定向回设置页" );
		}

		// 【W3-FIX·0.8.6】URL 生成面钉（W3F2 手测缺陷②防回退）：admin_post_* 钩子只由
		// admin-post.php 派发，admin.php 不派发——0.8.5 出口四链曾用 admin.php?action=
		// 形态=死链（实测 200/414B 空 admin shell、wizard_state 不翻转）。上方注册钉
		// 只测挂钩不测生成面，故补出口/跳过四链生成面字面（admin-post.php 形态恰四条
		// ——步四双出口 done/done&to=copy + 步一 wizard-foot 跳过/页脚 wizard-exit 跳过）
		// + admin.php 死链形态全文件零残留。
		$sp = self::sp();
		$this->assertSame(
			2,
			substr_count( $sp, "'admin-post.php?action=' . self::ACTION_WIZARD_DONE" ),
			'完成出口两链（进入总览/去文案工坊）URL 生成面=admin-post.php 形态（W3F2 缺陷②）'
		);
		$this->assertSame(
			2,
			substr_count( $sp, "'admin-post.php?action=' . self::ACTION_WIZARD_SKIP" ),
			'跳过出口两链（步一 wizard-foot/页脚 wizard-exit）URL 生成面=admin-post.php 形态（W3F2 缺陷②）'
		);
		$this->assertSame(
			0,
			preg_match_all( "/admin\.php\?action=/", $sp ),
			'SettingsPage 全文件零 admin.php?action= 死链形态残留（admin_post_* 只由 admin-post.php 派发——全仓 admin_post 链接同口径）'
		);
	}

	public function test_render_routes_to_wizard_group(): void {
		$render = self::body( 'render' );
		$this->assertStringContainsString( 'wizard_mode_active()', $render, 'render 入口向导模式判定（active_tab 旁）' );
		$this->assertStringContainsString( 'render_wizard()', $render, '向导模式渲染分组替代 Tab 内容' );
		$mode = self::body( 'wizard_mode_active' );
		$this->assertStringContainsString( "wizard_should_auto_open", $mode, 'auto-open 走纯函数判定件' );
		$this->assertStringContainsString( "'wizard'", $mode, '手动重进入口 wizard=1 参数（done/skipped 也可再进）' );
	}

	public function test_wizard_four_panels_and_step_words(): void {
		$wiz = self::body( 'render_wizard' );
		foreach ( array( 'wk-wizard', 'wk-stepper', 'wk-step-panel', 'wk-checklist' ) as $cls ) {
			$this->assertStringContainsString( $cls, $wiz, "向导骨架类族消费（批 A 已建——{$cls}）" );
		}
		foreach ( array( '欢迎', '人机验证', '内容审核', '完成' ) as $word ) {
			$this->assertStringContainsString( $word, $wiz, "步名照原型 proto-wizard（{$word}）" );
		}
		$this->assertSame( 4, substr_count( $wiz, 'data-wk-step' ), '四步面板（1–4）' );
		$this->assertStringContainsString( '上岗确认单', $wiz, '步四核对清单（原型文案）' );
		$this->assertStringContainsString( '进入总览', $wiz, '完成出口（主）' );
		$this->assertStringContainsString( '跳过全部', $wiz, '全程跳过路径' );
	}

	public function test_wizard_key_face_closed_set(): void {
		$wiz  = self::body( 'render_wizard' );
		$paths = array();
		preg_match_all( "/self::(?:field_name|password_field)\( array\( ([^)]*) \)/", $wiz, $m );
		foreach ( $m[1] as $args ) {
			preg_match_all( "/'([a-z0-9_]+)'/", (string) $args, $segs );
			$paths[] = implode( '.', $segs[1] );
		}
		$paths = array_values( array_unique( $paths ) );
		sort( $paths );
		$allowed = array(
			'captcha.aliyun.access_key_id',
			'captcha.aliyun.access_key_secret',
			'captcha.aliyun.prefix',
			'captcha.aliyun.scene_id',
			'captcha.driver',
			'captcha.submit_flow',
			'iprep.abuseipdb.api_key',
			'iprep.enabled',
			'review.cloud.aliyun_green.access_key_id',
			'review.cloud.aliyun_green.access_key_secret',
			'review.cloud.baidu.api_key',
			'review.cloud.baidu.secret_key',
			'review.cloud.chain',
			'review.cloud.deepseek.api_key',
			'review.cloud.enabled',
			'review.cloud.openai_custom.api_key',
			'review.cloud.openai_custom.base_url',
			'review.cloud.openai_custom.model',
			'review.cloud.tencent_tms.secret_id',
			'review.cloud.tencent_tms.secret_key',
		);
		$unexpected = array_diff( $paths, $allowed );
		$this->assertSame( array(), array_values( $unexpected ), '向导键面闭集=实况键（零新 schema 键——N5 红线）。越界：' . implode( ', ', $unexpected ) );
		// 必备键在场（步一四键/submit_flow/iprep 两键/步二百度两键）。
		foreach ( array( 'captcha.driver', 'captcha.submit_flow', 'iprep.enabled', 'iprep.abuseipdb.api_key', 'review.cloud.baidu.api_key', 'review.cloud.baidu.secret_key' ) as $must ) {
			$this->assertContains( $must, $paths, "向导必备实键 {$must} 在场" );
		}
		// 每个用到的键都是 schema 既有实键（同 Tab②③④ 同名同键）。
		$rules = Sanitize::rules();
		foreach ( $paths as $path ) {
			$this->assertArrayHasKey( $path, $rules, "向导字段 {$path} 与既有清洗规则同名同键（零新键）" );
		}
	}

	public function test_wizard_driver_radio_cards_with_retired_tencent(): void {
		$wiz = self::body( 'render_wizard' );
		$this->assertStringContainsString( '阿里云验证码 2.0', $wiz, '驱动 radio 卡一（阿里推荐·默认）' );
		$this->assertStringContainsString( '退役', $wiz, '腾讯天御退役注（存量可用）' );
		$this->assertStringContainsString( '暂不配置', $wiz, '驱动 radio 卡三（暂不配置）' );
	}

	public function test_wizard_baidu_first_recommendation_order(): void {
		$wiz = self::body( 'render_wizard' );
		$seq = array(
			'baidu'       => strpos( $wiz, "'baidu'" ),
			'deepseek'    => strpos( $wiz, "'deepseek'" ),
			'aliyun_green' => strpos( $wiz, "'aliyun_green'" ),
			'tencent_tms' => strpos( $wiz, "'tencent_tms'" ),
			'openai_custom' => strpos( $wiz, "'openai_custom'" ),
		);
		$prev = -1;
		foreach ( $seq as $driver_id => $pos ) {
			$this->assertGreaterThan( 0, $pos, "云审 radio 六卡含 {$driver_id}" );
			$this->assertGreaterThan( $prev, $pos, "百度推荐序位次（{$driver_id} 按拍板顺序出现）" );
			$prev = $pos;
		}
		$this->assertStringContainsString( '推荐 · 默认', $wiz, '百度卡「推荐 · 默认」角标（开通门槛最低口径）' );
		$this->assertStringContainsString( '次选', $wiz, 'DeepSeek「次选」措辞' );
	}

	public function test_wizard_wp_switches_readonly_bar_truth_no_field_names(): void {
		$this->assertTrue( method_exists( SettingsPage::class, 'render_wizard_wp_switches_bar' ), 'WP 两开关联动只读黄条独立渲染件' );
		$bar = self::body( 'render_wizard_wp_switches_bar' );
		$this->assertStringContainsString( 'comment_moderation', $bar, '读 WP 原生真值（评论必须手动批准）' );
		$this->assertStringContainsString( 'comment_whitelist', $bar, '读 WP 原生真值（作者须有先前已批准评论）' );
		$this->assertStringNotContainsString( 'field_name(', $bar, '只读黄条零本插件表单字段名（SettingsPage :2131 红线照守——演示不落库）' );
		$this->assertStringContainsString( '这不是故障', $bar, '黄条人话（过码后仍需人工批准——这不是故障）' );
		$this->assertStringContainsString( 'options-discussion.php', $bar, '管理入口深链' );
	}

	public function test_wizard_submit_flow_radio_and_abuseipdb_fold(): void {
		$wiz = self::body( 'render_wizard' );
		$this->assertStringContainsString( "'captcha', 'submit_flow'", $wiz, '步一提交触发形态 radio（captcha.submit_flow 实键）' );
		$this->assertStringContainsString( '无按钮模式', $wiz, 'on_submit 选项文案' );
		$this->assertStringContainsString( 'AbuseIPDB', $wiz, 'AbuseIPDB 可选折叠卡' );
		$this->assertStringContainsString( '免费档 1000', $wiz, '折叠卡免费档注（1000 次/天拍板统一口径）' );
		$this->assertStringContainsString( "self::password_field( array( 'iprep', 'abuseipdb', 'api_key' )", $wiz, 'api_key 走 password_field（H-6 清除 checkbox 能力随 helper 附带——已配置态渲染「清除此凭据」）' );
		$this->assertStringContainsString( 'self::test_credential_btn(', $wiz, '折叠卡 cred-test 复用新形态' );
	}

	public function test_wizard_flow_demo_present_with_reduced_motion_guard(): void {
		$wiz = self::body( 'render_wizard' );
		$this->assertStringContainsString( 'wk-flow-demo', $wiz, '两形态循环 demo（wk-flow-demo 类族批 A 已建）' );
		$this->assertStringContainsString( 'wk-flow-step', $wiz, '演示帧结构' );
		$this->assertStringContainsString( 'prefers-reduced-motion', $wiz, 'reduced-motion 降级静态帧守卫（R8 内联时序脚本前置）' );
	}

	public function test_wizard_finish_panel_credential_guidance(): void {
		$wiz = self::body( 'render_wizard' );
		$pos_panel4 = strpos( $wiz, 'data-wk-step="4"' );
		$this->assertGreaterThan( 0, $pos_panel4, '步四面板在场' );
		$panel4 = substr( $wiz, (int) $pos_panel4 ) . self::body( 'render_wizard_checklist' );
		$this->assertStringContainsString( '测试凭据', $panel4, '步四测试凭据引导（复用 test_credential_btn 新形态）' );
		$this->assertStringContainsString( 'self::render_wizard_checklist(', $wiz, '上岗确认单=独立核对清单渲染件（真值态）' );
		$this->assertStringContainsString( '上岗确认单', $panel4, '步四核对清单（原型文案）' );
	}

	public function test_wizard_entry_card_points_to_wizard(): void {
		$entry = self::body( 'render_overview_onboarding' );
		$this->assertStringContainsString( 'wizard_url(', $entry, 'Tab① 常驻入口卡改指 wizard 重进入口' );
		$this->assertStringContainsString( '向导', $entry, '入口文案点明向导（done/skipped 也可再进）' );
		$mode = self::body( 'wizard_mode_active' );
		$this->assertStringContainsString( "'1'", $mode, 'wizard=1 手动入口参数' );
	}

	public function test_wizard_url_builder_whitelists_step(): void {
		$url = SettingsPage::wizard_url( 3 );
		$this->assertStringContainsString( 'wizard=1', (string) $url, '重进入口参数' );
		$this->assertStringContainsString( 'step=3', (string) $url, '步参数（白名单内）' );
		$this->assertStringContainsString( 'step=1', (string) SettingsPage::wizard_url( 99 ), '步越界回落 1' );
	}
}
