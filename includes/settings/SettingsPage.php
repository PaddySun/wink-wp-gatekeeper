<?php
/**
 * 设置页（WP 边界层，03 §1：单 option + 7 Tab + 品牌页头）。
 *
 * - register_setting 的 sanitize 回调桥到纯逻辑层 Sanitize::clean()，
 *   再经 SettingsSchema::merge_defaults() 补全默认树（schema_version 收口）；
 * - 渲染：Night #111114 品牌页头 + 静态 logo【M6·批H4·H4-5 眨眼动效退役】+ 七 Tab 骨架（M0：区块占位，
 *   总览放「今日拦截」静态占位卡）；
 * - 安全线：settings_fields（nonce）、manage_options、esc_html/esc_attr 全覆盖。
 *
 * @package WinkGatekeeper\Settings
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Settings;

use WinkGatekeeper\Drivers\captcha\AliyunCaptchaDriver;
use WinkGatekeeper\Drivers\captcha\TencentCaptchaDriver;
use WinkGatekeeper\Drivers\Review\AliyunGreenPlusDriver;
use WinkGatekeeper\Drivers\Review\BaiduTextModerationDriver;
use WinkGatekeeper\Drivers\Review\TencentTmsDriver;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Ip\AbuseIpdbCategories;
use WinkGatekeeper\Ip\AbuseIpdbService;
use WinkGatekeeper\Ip\IpDiagnostic;
use WinkGatekeeper\Ip\IpReputationService;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Ip\TrustedProxies;
use WinkGatekeeper\Settings\CaptchaTabSpec;
use WinkGatekeeper\Settings\CredentialProbe;
use WinkGatekeeper\Settings\DriverConfigSpec;
use WinkGatekeeper\Stats\DriverHealth;
use WinkGatekeeper\Stats\Usage;

final class SettingsPage {

	const PAGE_SLUG    = 'wink-gatekeeper';
	const OPTION_NAME  = 'wink_gatekeeper_settings';
	const OPTION_GROUP = 'wink_gatekeeper';

	/**
	 * 统计重置 AJAX action（03 Tab⑥；manage_options + check_ajax_referer）。
	 */
	const AJAX_RESET_STATS = 'wink_gatekeeper_reset_stats';

	/**
	 * Tab②「测试凭据」AJAX action（03 §1 契约④：nonce + manage_options 双闸；结果不落库）。
	 */
	const AJAX_TEST_CREDENTIALS = 'wink_gatekeeper_test_credentials';

	/**
	 * Tab② 阿里「测试凭据」AJAX action（【v1.5·B3】：nonce + manage_options 双闸；结果不落库）。
	 */
	const AJAX_TEST_CREDENTIALS_ALIYUN = 'wink_gatekeeper_test_credentials_aliyun';

	/**
	 * Tab③ TMS「测试凭据」AJAX action（C4：nonce + manage_options 双闸；结果不落库；
	 * 绿态顺带解除 tencent_tms 当日停转——02 §5「自检通过」解除条件）。
	 */
	const AJAX_TEST_CREDENTIALS_TMS = 'wink_gatekeeper_test_credentials_tms';

	/**
	 * Tab③ 阿里 PLUS「测试凭据」AJAX action（【v1.5·B5】：nonce + manage_options 双闸；
	 * 结果不落库；绿态顺带解除 aliyun_green 当日停转——05 §2.5 凭据自检三态/TMS 先例同款）。
	 */
	const AJAX_TEST_CREDENTIALS_ALIYUN_GREEN = 'wink_gatekeeper_test_credentials_aliyun_green';

	/**
	 * Tab③ 百度「测试凭据」AJAX action（【v1.5·B6】：nonce + manage_options 双闸；
	 * 结果不落库；绿态顺带解除 baidu 当日停转——05 §2.4 凭据自检三态/TMS 先例同款）。
	 */
	const AJAX_TEST_CREDENTIALS_BAIDU = 'wink_gatekeeper_test_credentials_baidu';

	/**
	 * Tab③ DeepSeek「测试凭据」AJAX action（【M5·U4】D5 裁决：GET /models 探活——
	 * 零 token 成本证 Bearer 鉴权通；nonce + manage_options 双闸；结果不落库零计量）。
	 */
	const AJAX_TEST_CREDENTIALS_DEEPSEEK = 'wink_gatekeeper_test_credentials_deepseek';

	/**
	 * Tab③ openai_custom「测试凭据」AJAX action（【M6·批E】：GET {base_url}/models 探活
	 * 三态——泛端点无标准余额 API，零 token 消耗；nonce + manage_options 双闸；结果
	 * 不落库零计量；响应闭集不含提交值）。
	 */
	const AJAX_TEST_CREDENTIALS_OPENAI_CUSTOM = 'wink_gatekeeper_test_credentials_openai_custom';

	/**
	 * 【M6·批B·B-3】网址核查独立凭证测试 AJAX action（provider custom——DeepSeek 协议族
	 * 同用余额探测，base_url 跟随其配置）。
	 */
	const AJAX_TEST_CREDENTIALS_URL_AUDIT = 'wink_gatekeeper_test_credentials_url_audit';

	/**
	 * Tab④ 诊断面板「手动聚合查询」AJAX action（M3-IP 定稿 §5.1，I0 批）：
	 * nonce + manage_options 双闸；**设计红线：四源仅此手动区出现——零闸路径/零缓存/零统计/
	 * 零自动调用（不带 usage_key），任一源失败仅该卡片失败**；结果不落库（纯请求态渲染）。
	 */
	const AJAX_IP_DIAGNOSE = 'wink_gatekeeper_ip_diagnose';

	/**
	 * Tab④ 分区②「测试凭据」AJAX action（M3-IP 定稿 §6【O5 回写·P2-6】，I4 收官批）：
	 * nonce + manage_options 双闸（B4 声明式纪律）；最小 check 查询（127.0.0.2 保留地址+
	 * maxAgeInDays=1 省配额）三态——绿=HTTP 200 且 JSON 可解析（isPublic:false 亦绿——验证的
	 * 是凭据不是 IP）/黄=缺参（API Key 空）/红=401/402 错钥；结果不落库（零计量零写）。
	 */
	const AJAX_TEST_IPREP = 'wink_gatekeeper_test_iprep';

	/**
	 * Tab⑤ 拦截页「查看效果」预览 admin action（【M4UX·G3】人工反馈第 3 轮，0.5.5）：
	 * manage_options + nonce 双闸（03 §1 契约④）→ DiePage 样例实渲染（当前自定义文案+
	 * 占位符示例值填充）→ echo 完整品牌页 → exit；**零落库零事件零统计**（只读 option，
	 * 不写任何状态）。
	 */
	const ACTION_DIE_PREVIEW = 'wink_gatekeeper_die_preview';

	/**
	 * Tab⑧ 卡二「测试当前头像来源」AJAX action（【M5·U2】UI 设计块 1 卡二三态按钮）：
	 * nonce + manage_options 双闸；对当前表单所选头像源发一次固定 email hash 探测
	 * （每源 1 请求——UI 设计块 1.3），三态=绿（200+图片）/黄（>3s 能用但慢）/
	 * 红（连不上或非图片）；**测的是镜像通不通非密钥**（与凭据测试不同语境）；结果
	 * 不落库零计量零事件（RemoteClient 传输脊，不带 usage_key——诊断查询同口径）。
	 */
	const AJAX_TEST_AVATAR = 'wink_gatekeeper_test_avatar_source';

	/**
	 * Tab⑦「历史评论体检」操作面 admin action 四件（【M5·U3】UI 设计块 5.1——D6 裁决：
	 * Tab⑦ 发起/Tab⑥ 报告分工）——nonce + manage_options 双闸在 handle_audit_action；
	 * start/resume 侧重排 cron tick、pause/reset 侧清；结果重定向回 Tab⑦（零落库副作用
	 * 除进度 option/meta）。
	 */
	const ACTION_AUDIT_START  = 'wink_gatekeeper_audit_start';
	const ACTION_AUDIT_PAUSE  = 'wink_gatekeeper_audit_pause';
	const ACTION_AUDIT_RESUME = 'wink_gatekeeper_audit_resume';
	const ACTION_AUDIT_RESET  = 'wink_gatekeeper_audit_reset';

	/**
	 * 头像源探测固定 email hash（md5('wink-gatekeeper-avatar-probe@example.com')——
	 * 每源 1 请求口径；探测的是源可达性非真实头像）。
	 */
	const AVATAR_PROBE_HASH = 'f36f1fe24ea129522b9eb822fef2aa59';

	/**
	 * @var \WinkGatekeeper\Drivers\DriverRegistry|null Bootstrap 装配期注入的驱动注册表。
	 */
	private static $driver_registry = null;

	/**
	 * 七 Tab 信息架构（03 §1）：tab 键 => [序号, 标题]。
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	const TABS = array(
		'overview' => array( '①', '总览' ),
		'captcha'  => array( '②', '验证码' ),
		'review'   => array( '③', '内容审核' ),
		'iprep'    => array( '④', 'IP 信誉' ),
		'copy'     => array( '⑤', '文案工坊' ),
		'stats'    => array( '⑥', '统计' ),
		'tools'    => array( '⑦', '工具' ),
		// 【M5·UI-A】⑧「站点增强」顺延追加（UI 设计 D1 裁决——零重编号迁移；语义=
		// 影响站点前台行为的长期设置：网站字段/头像源。⑦=工具（一次性操作）、
		// ⑧=站点增强（长期设置）——hover/文档统一话术「⑧ 改的是站点长什么样，
		// ⑦ 做的是你想干的某件事」）。
		'enhance'  => array( '⑧', '站点增强' ),
	);

	/**
	 * Bootstrap 装配期注入驱动注册表（Tab② 驱动卡片渲染消费，05 §4）。
	 *
	 * @param \WinkGatekeeper\Drivers\DriverRegistry $registry
	 * @return void
	 */
	public static function set_driver_registry( $registry ): void {
		self::$driver_registry = $registry;
	}

	/**
	 * 当前设置树（option 读取 + 默认树补全，渲染单一数据源）。
	 *
	 * @return array<string, mixed>
	 */
	private static function settings(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		return SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * 注册设置（admin_init）：sanitize 回调桥到纯逻辑层。
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => SettingsSchema::defaults(),
			)
		);
	}

	/**
	 * sanitize 桥：单表单多 Tab 语义（仅活动 Tab 的字段随表单提交）。
	 *
	 * 合并序：已存储 option ⊕ 本次提交（提交键优先）→ 默认树补全——保存 Tab⑤ 不会
	 * 把 Tab② 的密钥抹回默认。password 型字段显式提交空串 = 保留已存值（「已配置✓」
	 * 不回显语义的写侧配套）；checkbox 带 hidden 0 伴随域（未勾选显式提交 0）。
	 *
	 * @param mixed $input 表单提交的设置数组。
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		// 【M6·批H·H-6】显式清除凭据旗标（渲染层 hidden 0 伴随域+独立 <leaf>_clear 键名）
		// 在清洗前剥除——旗标键不进设置树不进 rejected 报告；返回被勾选（旗标=1）的
		// password 点分路径列表（保存树收口阶段消费）。提交态为空的勾选路径记账（在
		// 「空串保留」之前——提交态语义，preserve 还原值不作数）。
		$clear_flags = self::strip_credential_clear_flags( $input );
		$clear_empty = array();
		foreach ( $clear_flags as $flag_path ) {
			$segments = explode( '.', $flag_path );
			if ( '' === (string) self::dig( is_array( $input ) ? $input : array(), $segments, '' ) ) {
				$clear_empty[] = $flag_path;
			}
		}

		list( $clean, $rejected ) = Sanitize::clean( $input );

		// M1：$rejected（点分路径列表）接入「已剥除未知字段」后台提示。
		if ( array() !== $rejected ) {
			/**
			 * 未知字段被剥除的通知钩子（预留）。
			 *
			 * @param string[] $rejected 被剥除的点分路径列表。
			 */
			do_action( 'wink_gatekeeper_settings_rejected', $rejected );
		}

		$stored = get_option( self::OPTION_NAME, array() );
		$stored = is_array( $stored ) ? $stored : array();

		// password 型空串显式提交 → 保留已存值（不回显键值的写侧语义）。
		foreach ( Sanitize::rules() as $path => $type ) {
			if ( 'password' !== $type ) {
				continue;
			}
			$segments = explode( '.', $path );
			$node     = &$clean;
			$present  = true;
			foreach ( $segments as $segment ) {
				if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
					$present = false;
					break;
				}
				$node = &$node[ $segment ];
			}
			if ( $present && '' === $node ) {
				$node = self::dig( $stored, $segments, '' );
			}
			unset( $node );
		}

		// 【M6·批H·H-6】清除旗标收口（在「空串保留」之后应用——显式清空覆盖保留语义）：
		// 勾选且提交值为空串 → 凭据置空串（渲染层「已配置✓」消失，U-1/黄条联动照常）；
		// 勾选但提交了新值 → 新值优先（显式替换语义，清除控件与换钥互不劫持）。
		foreach ( $clear_empty as $flag_path ) {
			self::assign_path( $clean, explode( '.', $flag_path ), '' );
		}

		$merged = self::merge_submitted( $stored, $clean );

		/* chain 复选框（C4，列表整体替换）：活动 Tab 提交了 review.cloud.chain（hidden 伴随域
		 * 使「未勾选」也显式提交——sanitize 后为空数组）→ 链表按提交值整体收口——
		 * 防「取消勾选被旧链表深合并回填」（merge_submitted 对空数组的 map 递归合并是 no-op）。 */
		$submitted_chain = self::dig( $clean, array( 'review', 'cloud', 'chain' ), null );
		if ( is_array( $submitted_chain ) ) {
			if ( ! is_array( $merged['review']['cloud'] ?? null ) ) {
				$merged['review']['cloud'] = array();
			}
			$merged['review']['cloud']['chain'] = $submitted_chain;
		}

		/* 【v1.5·C1】test_mode 生命周期（02 §2.8 门 #1/#5-①）：on/off 切换 → since 写
		 * time()（on）/清零（off）+ 落 test_mode_on|off 事件（gate=system 留痕——on/off/expired
		 * 三态全程可审计；record_lifecycle 内含纯单测环境守卫，纯 PHP 桥测试零依赖）。 */
		$stored_tm = (bool) self::dig( $stored, array( 'captcha', 'test_mode' ), false );
		$merged_tm = (bool) ( $merged['captcha']['test_mode'] ?? false );
		$lifecycle = \WinkGatekeeper\Gates\TestMode::lifecycle_rule( $stored_tm, $merged_tm );
		if ( null !== $lifecycle ) {
			$merged['captcha']['test_mode_since'] = $merged_tm ? time() : 0; // WP 边界时钟。
			\WinkGatekeeper\Gates\TestMode::record_lifecycle( $lifecycle );
		}

		return SettingsSchema::merge_defaults( $merged );
	}

	/**
	 * 已存储树 ⊕ 提交树（提交键优先；提交树未出现的键保留已存值）。
	 *
	 * C4 修正：列表值（如 review.cloud.chain——顺序整数键）整体替换不做按键深合并——
	 * 否则「取消勾选 chain 复选框（提交 []）」会被旧链表按键合并回填（取消不生效）。
	 *
	 * @param array<string, mixed> $stored  已存储 option。
	 * @param array<string, mixed> $clean   本次提交（已清洗）。
	 * @return array<string, mixed>
	 */
	private static function merge_submitted( array $stored, array $clean ): array {
		foreach ( $clean as $key => $value ) {
			if ( is_array( $value ) && isset( $stored[ $key ] ) && is_array( $stored[ $key ] )
				&& self::is_map( $value ) && self::is_map( $stored[ $key ] ) ) {
				$stored[ $key ] = self::merge_submitted( $stored[ $key ], $value );
			} else {
				$stored[ $key ] = $value; // 标量与列表：整体替换。
			}
		}
		return $stored;
	}

	/**
	 * 是否为 map（存在至少一个非顺序整数键 ⇒ map；空数组按 map 便于递归——沿
	 * SettingsSchema::is_map 同语义，chain 类顺序列表整体替换判定用）。
	 *
	 * @param mixed $value
	 * @return bool
	 */
	private static function is_map( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( array() === $value ) {
			return true;
		}
		return array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}

	/**
	 * 按路径段取值（缺键返回默认）。
	 *
	 * @param array    $tree
	 * @param string[] $segments
	 * @param mixed    $default
	 * @return mixed
	 */
	private static function dig( array $tree, array $segments, $default ) {
		$node = $tree;
		foreach ( $segments as $segment ) {
			if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
				return $default;
			}
			$node = $node[ $segment ];
		}
		return $node;
	}

	/**
	 * 【M6·批H·H-6】显式清除凭据旗标剥除（sanitize 桥清洗前置步）：遍历 password 型
	 * 规则路径，提交树同层若携带独立旗标键 <leaf>_clear（渲染层 hidden 0 伴随域+checkbox
	 * 形态）→ 剥除旗标键（不进设置树不进 rejected 报告）并按值记账（'1'=勾选清除）。
	 *
	 * @param mixed $input 表单提交树（引用——旗标键就地剥除；非数组安全退空）。
	 * @return string[] 被勾选的 password 点分路径列表。
	 */
	private static function strip_credential_clear_flags( &$input ): array {
		$flagged = array();
		if ( ! is_array( $input ) ) {
			return $flagged;
		}
		foreach ( Sanitize::rules() as $path => $type ) {
			if ( 'password' !== $type ) {
				continue;
			}
			$segments = explode( '.', $path );
			$leaf     = (string) end( $segments );
			$parent   = &self::dig_ref( $input, array_slice( $segments, 0, -1 ) );
			if ( is_array( $parent ) && array_key_exists( $leaf . '_clear', $parent ) ) {
				if ( '1' === (string) $parent[ $leaf . '_clear' ] ) {
					$flagged[] = $path;
				}
				unset( $parent[ $leaf . '_clear' ] );
			}
			unset( $parent );
		}
		return $flagged;
	}

	/**
	 * 按路径段取引用（缺中间节点就地建空数组——assign_path 配套）。
	 *
	 * @param array    $tree     树（引用）。
	 * @param string[] $segments 路径段。
	 * @return array|null 最深节点引用（越界标量=null）。
	 */
	private static function &dig_ref( array &$tree, array $segments ): ?array {
		$node = &$tree;
		foreach ( $segments as $segment ) {
			if ( ! is_array( $node ) ) {
				$null = null;
				return $null;
			}
			if ( ! array_key_exists( $segment, $node ) || ! is_array( $node[ $segment ] ) ) {
				$node[ $segment ] = array();
			}
			$node = &$node[ $segment ];
		}
		return $node;
	}

	/**
	 * 按路径段赋值（缺中间节点建空数组——清除凭据收口消费）。
	 *
	 * @param array    $tree     树（引用）。
	 * @param string[] $segments 路径段。
	 * @param mixed    $value    值。
	 * @return void
	 */
	private static function assign_path( array &$tree, array $segments, $value ): void {
		$leaf = array_pop( $segments );
		$node = &self::dig_ref( $tree, $segments );
		if ( is_array( $node ) && null !== $leaf ) {
			$node[ $leaf ] = $value;
		}
	}

	/**
	 * 设置页渲染入口（add_options_page 回调）。
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足：需要 manage_options。', 'wink-gatekeeper' ) );
		}

		$active_tab = self::active_tab();
		$version    = WINK_GATEKEEPER_VERSION;
		$stats      = \WinkGatekeeper\Stats\StatsCache::get();
		$today_rejects = 0;
		foreach ( $stats['by_gate'] as $counts ) {
			$today_rejects += (int) ( $counts['reject'] ?? 0 );
		}
		?>
		<div class="wrap wk-wrap">
			<header class="wk-header">
				<div class="wk-header-brand">
					<?php /* 【M6·批H4·H4-5】眨眼源属性退役（静态版 logo——用户拍板，手测二轮 F1）。 */ ?>
					<img
						id="wk-brand-logo"
						class="wk-logo"
						src="<?php echo esc_url( WINK_GATEKEEPER_URL . 'assets/brand/logo-wink.svg' ); ?>"
						alt="<?php esc_attr_e( 'Wink 门口保安', 'wink-gatekeeper' ); ?>"
						width="40" height="45" decoding="async"
					/>
					<span class="wk-wordmark">wink</span>
					<span class="wk-tagline"><?php esc_html_e( '对真人眨眨眼，对机器人睁着眼。', 'wink-gatekeeper' ); ?></span>
				</div>
				<div class="wk-header-meta">
					<span class="wk-badge"><?php echo esc_html( sprintf( /* translators: %s: 今日拦截数 */ __( '今日拦截 %s', 'wink-gatekeeper' ), (string) $today_rejects ) ); ?></span>
					<span class="wk-version"><?php echo esc_html( sprintf( /* translators: %s: 版本号 */ __( 'v%s', 'wink-gatekeeper' ), $version ) ); ?></span>
				</div>
			</header>

			<nav class="nav-tab-wrapper wk-tabs" aria-label="<?php esc_attr_e( 'Wink 门口保安设置分区', 'wink-gatekeeper' ); ?>">
				<?php foreach ( self::TABS as $tab_key => $tab_def ) : ?>
					<a
						href="<?php echo esc_url( self::tab_url( $tab_key ) ); ?>"
						class="nav-tab<?php echo $tab_key === $active_tab ? ' nav-tab-active' : ''; ?>"
					>
						<?php
						// 输出固定常量（序号 + Tab 标题），无需转义；仍走 esc_html 保持统一纪律。
						echo esc_html( $tab_def[0] . ' ' . $tab_def[1] );
						?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php" class="wk-form">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<div class="wk-panel">
					<?php self::render_tab( $active_tab ); ?>
				</div>
				<?php submit_button( __( '保存设置', 'wink-gatekeeper' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * 当前激活 Tab（白名单外的取值回落总览）。
	 *
	 * @return string
	 */
	private static function active_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读 Tab 导航展示，无写操作。
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return array_key_exists( $tab, self::TABS ) ? $tab : 'overview';
	}

	/**
	 * @param string $tab Tab 键。
	 * @return string
	 */
	private static function tab_url( string $tab ): string {
		return add_query_arg(
			array( 'page' => self::PAGE_SLUG, 'tab' => $tab ),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * @param string $tab Tab 键（active_tab 白名单后取值）。
	 * @return void
	 */
	private static function render_tab( string $tab ): void {
		switch ( $tab ) {
			case 'captcha':
				self::render_tab_captcha();
				break;
			case 'review':
				self::render_tab_review();
				break;
			case 'iprep':
				self::render_tab_iprep();
				break;
			case 'copy':
				self::render_tab_copy();
				break;
			case 'stats':
				self::render_tab_stats();
				break;
			case 'tools':
				self::render_tab_tools();
				break;
			case 'enhance':
				self::render_tab_enhance();
				break;
			case 'overview':
			default:
				self::render_tab_overview();
				break;
		}
	}

	/* -------------------------------------------------------------------
	 * Tab① 总览（UI 批 3·B 提案五块——点名册形态，v0.4.0）
	 *   ① 站务提醒通栏（无卡壳，有话才出现）
	 *   ② 今日战报（三闸小票 + 待审/垃圾箱行动行）
	 *   ③ 服务商点名册（registry 全集×配置树×DriverHealth×Usage 渲染侧推导——零存储变更）
	 *   ④ 最近岔子（批 2 N2∪N3 合并时间线的紧凑形态，入口性质 5 条）
	 *   ⑤ 站点底账（一行灰字带）
	 *
	 * 分层纪律（B 提案 §6）：Tab① 只看今天、每数字一句人话、无表格化明细（明细归
	 * Tab⑥）；唯一合法重叠=页头「今日拦截 N」徽标。术语全走 Labels 映射（对照表
	 * 资产）；点名册直白命名（统筹裁决 1：不用保安比喻，系统层叫「服务商」）。
	 * ------------------------------------------------------------------ */

	private static function render_tab_overview(): void {
		self::render_overview_onboarding();
		self::render_overview_alerts();
		self::render_overview_report();
		self::render_overview_roster();
		self::render_overview_incidents();
		self::render_overview_footer();
	}

	/**
	 * 页顶三步引导通栏（【M4UX UI-1-1·定稿 §3】初次安装引导从页脚一行升为页顶通栏
	 * ——Akismet 激活旅程先例（R-UI G2）：notice-warning inline 形态，显隐判定复用
	 * overview_configured_count()===0（验证码与云审两域合并计数），配好任意一家即
	 * 自动消失；页脚旧「首次使用？三步配好 →」行随之退役（V1 收口）。
	 *
	 * @return void
	 */
	private static function render_overview_onboarding(): void {
		$settings = self::settings();
		if ( 0 !== self::overview_configured_count( $settings ) ) {
			return; // 已配置任意一家服务商：引导自动消失（配好即退场）。
		}
		?>
		<section id="wk-overview-onboarding">
			<div class="notice notice-warning inline"><p>
				<strong><?php esc_html_e( '三步开始防护', 'wink-gatekeeper' ); ?></strong>
				——① <?php esc_html_e( '选验证码（Tab② 验证码）', 'wink-gatekeeper' ); ?>
				→ ② <?php esc_html_e( '配云审 / IP 信誉（Tab③ 内容审核 / Tab④ IP 信誉）', 'wink-gatekeeper' ); ?>
				→ ③ <?php esc_html_e( '在各配置卡点「测试凭据」实测连通。', 'wink-gatekeeper' ); ?>
				<?php esc_html_e( '配好任意一家后本提示自动消失。', 'wink-gatekeeper' ); ?>
				<a href="<?php echo esc_url( self::tab_url( 'captcha' ) ); ?>"><?php esc_html_e( '从第一步开始 →', 'wink-gatekeeper' ); ?></a>
			</p></div>
		</section>
		<?php
	}

	/**
	 * 块 A·站务提醒通栏（异常状态置顶——唯一「需要站长动手」的信息；无话不渲染）。
	 *
	 * 内容（均现行已有，仅换位置 + 新增验证码域兜底）：演练模式通栏（原藏驱动健康
	 * 卡内——02 §2.8 安全门 #3 全站级旁路最高警示位）/ 统计停用提示（原藏今日拦截
	 * 卡内，FIX-δ）/ 审核域兜底（原驱动健康卡顶迁位）/ 验证码域兜底（新增——对齐
	 * 审核域既有兜底，agent C B-1）。
	 *
	 * @return void
	 */
	private static function render_overview_alerts(): void {
		$settings = self::settings();
		?>
		<section id="wk-overview-alerts">
		<?php
		// ⓪【M5·U3】存量审核运行中黄条（A-U3——UI 设计块 4.2；有话才出现，任务完成/暂停即隐）。
		$audit_prog = self::audit_worker()->status();
		$audit_st   = $audit_prog['status'] ?? 'idle';
		if ( 'running' === $audit_st || 'quota_paused' === $audit_st ) {
			?>
			<div class="notice notice-warning inline" id="wk-overview-existing-bar"><p>
				<?php
				printf(
					/* translators: 1: processed 2: total */
					esc_html__( '历史评论体检进行中（已处理 %1$d / 共 %2$d）', 'wink-gatekeeper' ),
					(int) ( $audit_prog['processed'] ?? 0 ),
					(int) ( $audit_prog['total'] ?? 0 )
				);
				?>
				——<a href="<?php echo esc_url( self::tab_url( 'tools' ) ); ?>"><?php esc_html_e( '暂停', 'wink-gatekeeper' ); ?></a>
				· <a href="<?php echo esc_url( self::tab_url( 'tools' ) ); ?>"><?php esc_html_e( '看报告 →', 'wink-gatekeeper' ); ?></a>
			</p></div>
			<?php
		}
		// ⓪'【M6·批H·H-7】存量网址核查运行中黄条（对齐 A-U3 先例——运行记录未完成或
		// 队列非空才出现；完成/空队列熄灭）。每日额度推进消耗外呼——总览页可见性兜底。
		if ( 'running' === ( new \WinkGatekeeper\UrlAudit\UrlAuditWorker( $settings ) )->backlog_report_data()['state'] ) {
			?>
			<div class="notice notice-warning inline" id="wk-overview-url-backlog-bar"><p>
				<?php esc_html_e( '存量网址核查进行中…按每日额度自动推进（判违规进垃圾箱、没看清进待审，都可手动恢复）。', 'wink-gatekeeper' ); ?>
				——<a href="<?php echo esc_url( self::tab_url( 'tools' ) ); ?>"><?php esc_html_e( '看报告 →', 'wink-gatekeeper' ); ?></a>
			</p></div>
			<?php
		}
		// ① 演练模式（wp_active 含 24h 懒过期判定——过期不显示）。
		if ( \WinkGatekeeper\Gates\TestMode::wp_active() ) {
			$tm_since = isset( $settings['captcha']['test_mode_since'] ) && is_numeric( $settings['captcha']['test_mode_since'] ) ? (int) $settings['captcha']['test_mode_since'] : 0;
			$tm_time  = $tm_since > 0 ? date_i18n( 'H:i', $tm_since ) : '—'; // WP 边界（站点时区）。
			$tm_left  = $tm_since > 0 ? max( 1, (int) ceil( ( $tm_since + \WinkGatekeeper\Gates\TestMode::TTL_SECONDS - time() ) / HOUR_IN_SECONDS ) ) : 24;
			?>
			<div class="notice notice-warning inline"><p>
				<span class="wk-dot wk-dot--alert" aria-hidden="true"></span>
				<strong><?php echo esc_html( \WinkGatekeeper\Stats\Labels::test_mode_word() . '进行中（今天 ' . $tm_time . ' 开启，还剩约 ' . $tm_left . ' 小时）' ); ?></strong>
				——<?php esc_html_e( '全站人机验证暂时被跳过，仅用于链路演练；24 小时后自动结束（旁路事件标注 drill，不进统计）。', 'wink-gatekeeper' ); ?>
				<a href="<?php echo esc_url( self::tab_url( 'captcha' ) ); ?>"><?php esc_html_e( '现在去关闭', 'wink-gatekeeper' ); ?></a>
			</p></div>
			<?php
		}

		// ② 统计停用（FIX-δ 从「今日拦截」卡内迁出为通栏）。
		if ( empty( $settings['stats']['enabled'] ) ) {
			?>
			<div class="notice notice-warning inline"><p>
				<strong><?php esc_html_e( '统计计数已停用', 'wink-gatekeeper' ); ?></strong>
				——<?php esc_html_e( '下方战报不记账；事件记录照常落库（以 Tab⑥ 为准）。', 'wink-gatekeeper' ); ?>
				<a href="<?php echo esc_url( self::tab_url( 'stats' ) ); ?>"><?php esc_html_e( '去开启', 'wink-gatekeeper' ); ?></a>
			</p></div>
			<?php
		}

		// ③ 验证码域兜底（新增——验证码没选服务商时的可见性兜底）。
		$captcha_driver = isset( $settings['captcha']['driver'] ) && is_string( $settings['captcha']['driver'] ) ? $settings['captcha']['driver'] : '';
		if ( '' === $captcha_driver ) {
			?>
			<div class="notice notice-warning inline"><p>
				<strong><?php esc_html_e( '人机验证还没选服务商', 'wink-gatekeeper' ); ?></strong>
				——<?php esc_html_e( '机器人不会被出题拦住；评论仍受站内规矩把关。', 'wink-gatekeeper' ); ?>
				<a href="<?php echo esc_url( self::tab_url( 'captcha' ) ); ?>"><?php esc_html_e( '去配置', 'wink-gatekeeper' ); ?></a>
			</p></div>
			<?php
		}

		// ④ 内容审核域兜底（「云端审核已启用但无可用驱动」黄条迁位，文案人话化）。
		$cloud = isset( $settings['review']['cloud'] ) && is_array( $settings['review']['cloud'] ) ? $settings['review']['cloud'] : array();
		$chain = isset( $cloud['chain'] ) && is_array( $cloud['chain'] ) ? $cloud['chain'] : array();
		if ( ! empty( $cloud['enabled'] ) && array() !== $chain && 0 === self::chain_usable_count( $chain ) ) {
			?>
			<div class="notice notice-warning inline"><p>
				<strong><?php esc_html_e( '内容审核已启用，但还没配好任何一家审核服务商', 'wink-gatekeeper' ); ?></strong>
				——<?php esc_html_e( '评论目前全靠站内规矩把关。', 'wink-gatekeeper' ); ?>
				<a href="<?php echo esc_url( self::tab_url( 'review' ) ); ?>"><?php esc_html_e( '去配置', 'wink-gatekeeper' ); ?></a>
			</p></div>
			<?php
		}

		// ⑤ 上报突增告警（【I3·定稿 §4.5/§9】usage_spike 当日事件消费——诬报/攻击感知黄条；
		// 事件由 AbuseIpdbService report 面在 10min 原始触发 >20 时落库，gate=system/stage=ip）。
		$spike_rows = \WinkGatekeeper\Stats\EventsTable::today_rule_count( \WinkGatekeeper\Ip\AbuseIpdbService::RULE_USAGE_SPIKE );
		if ( $spike_rows > 0 ) {
			?>
			<div class="notice notice-warning inline"><p>
				<span class="wk-dot wk-dot--alert" aria-hidden="true"></span>
				<strong><?php esc_html_e( '可疑：IP 上报请求今天突然增多', 'wink-gatekeeper' ); ?></strong>
				——<?php esc_html_e( '可能有伪造验证码的脚本在批量触发上报，或有人试图把你的站点当枪使（诬报）。拦截照常有效、上报有冷却与限额兜底；若持续出现，可在 IP 信誉 → 上报分区临时关闭对应触发面。', 'wink-gatekeeper' ); ?>
				<a href="<?php echo esc_url( self::tab_url( 'iprep' ) ); ?>"><?php esc_html_e( '去看上报设置', 'wink-gatekeeper' ); ?></a>
			</p></div>
			<?php
		}
		?>
		</section>
		<?php
	}

	/**
	 * 链上已配好服务商计数（审核域兜底判定——registry meta×配置树比对；
	 * 未实装 id（老站残留 deepseek 链值）不计为可用）。
	 *
	 * @param string[] $chain review.cloud.chain。
	 * @return int
	 */
	private static function chain_usable_count( array $chain ): int {
		if ( null === self::$driver_registry ) {
			return 0;
		}
		$cloud      = isset( self::settings()['review']['cloud'] ) && is_array( self::settings()['review']['cloud'] ) ? self::settings()['review']['cloud'] : array();
		$registered = self::$driver_registry->by_type( 'review' );
		$usable     = 0;
		foreach ( $chain as $driver_id ) {
			if ( ! is_string( $driver_id ) || '' === $driver_id ) {
				continue;
			}
			$meta = isset( $registered[ $driver_id ] ) && is_array( $registered[ $driver_id ] ) ? $registered[ $driver_id ] : null;
			if ( null === $meta ) {
				continue;
			}
			$config = isset( $cloud[ $driver_id ] ) && is_array( $cloud[ $driver_id ] ) ? $cloud[ $driver_id ] : array();
			if ( array() === DriverConfigSpec::missing_fields( $meta, $config ) ) {
				$usable++;
			}
		}
		return $usable;
	}

	/**
	 * 块 B·今日战报（替代原「今日拦截」数卡 + 「待审队列」占位卡）。
	 *
	 * 三闸小票：主行=OverviewRoster::ticket_main 纯函数（0=平静的一天显式空态）；
	 * 评论副行=N4 今日按规则分布（批 2 查询喂数——统计停用不出查询，小票整体灰字）。
	 * 行动行：待你过目（W2/N8 口径=WP 待审队列全量实数，跳转待审队列）+ IP 信誉
	 * 强制验证码单列行（统筹裁决 2——不入待审数字，>0 才出现）+ 垃圾箱存档
	 * （≥500 阈值黄话术升级，F14）。
	 *
	 * @return void
	 */
	private static function render_overview_report(): void {
		$settings      = self::settings();
		$stats         = \WinkGatekeeper\Stats\StatsCache::get();
		$stats_enabled = ! empty( $settings['stats']['enabled'] );

		$comment_reject = (int) ( \WinkGatekeeper\Stats\InterceptDigest::gate_counts( $stats, 'comment', false )['reject'] ?? 0 );
		$login_reject   = (int) ( \WinkGatekeeper\Stats\InterceptDigest::gate_counts( $stats, 'login', false )['reject'] ?? 0 );
		$attempts       = (int) ( \WinkGatekeeper\Stats\InterceptDigest::gate_counts( $stats, 'login', false )['attempts'] ?? 0 );
		$acct_reject    = (int) ( \WinkGatekeeper\Stats\InterceptDigest::gate_counts( $stats, 'register', false )['reject'] ?? 0 )
			+ (int) ( \WinkGatekeeper\Stats\InterceptDigest::gate_counts( $stats, 'lostpassword', false )['reject'] ?? 0 );

		// N4 今日按规则分布（战报副行——批 2 喂数）。
		$comment_dist = $stats_enabled ? \WinkGatekeeper\Stats\EventsTable::today_reject_distribution( 'comment' ) : array();

		// IP 信誉强制验证码单列口径（裁决 2——review_split 折减推导，M3 注册即生效）。
		$ip_ids    = null !== self::$driver_registry ? array_keys( self::$driver_registry->by_type( 'ip' ) ) : array();
		$ip_split  = \WinkGatekeeper\Stats\InterceptDigest::review_split( $stats, $ip_ids );
		$ip_captcha = (int) ( $ip_split['ip_captcha'] ?? 0 );

		// 待审实数（W2/N8——WP 待审队列全量；要过目的=全部待审）与垃圾箱存档量。
		$pending  = wp_count_comments(); // WP 边界。
		$pending_n = is_object( $pending ) && isset( $pending->moderated ) ? (int) $pending->moderated : 0;
		$spam_n    = \WinkGatekeeper\Stats\StatsCache::archived_spam_count();
		$today_label = date_i18n( 'n月j日' ); // WP 边界（站点时区）。
		?>
		<section id="wk-overview-report" class="wk-card">
			<h2><?php
				printf(
					/* translators: %s: 日期（n月j日）. */
					esc_html__( '今日战报（%s）', 'wink-gatekeeper' ),
					esc_html( $today_label )
				);
				?> <span class="wk-muted"><?php echo esc_html( $stats_enabled ? __( '统计：开启中', 'wink-gatekeeper' ) : __( '统计：已停用', 'wink-gatekeeper' ) ); ?></span></h2>
			<div class="wk-report-tickets">
				<?php if ( ! $stats_enabled ) : ?>
					<?php foreach ( array( '评论闸', '登录闸', '注册·找回闸' ) as $gray_gate ) : ?>
						<div class="wk-report-ticket wk-report-ticket--disabled">
							<p class="wk-report-ticket__name"><strong><?php echo esc_html( $gray_gate ); ?></strong></p>
							<p class="wk-muted"><?php esc_html_e( '统计已停用，今日没有记账（事件记录照常）', 'wink-gatekeeper' ); ?></p>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
					<div class="wk-report-ticket">
						<p class="wk-report-ticket__name"><strong><?php esc_html_e( '评论闸', 'wink-gatekeeper' ); ?></strong></p>
						<p class="wk-report-ticket__main"><?php echo esc_html( \WinkGatekeeper\Stats\OverviewRoster::ticket_main( $comment_reject, 'comment' ) ); ?></p>
						<p class="wk-muted"><?php
							echo esc_html(
								array() !== $comment_dist
									? \WinkGatekeeper\Stats\OverviewRoster::ticket_subline( $comment_dist )
									: __( '没有可疑评论', 'wink-gatekeeper' )
							);
						?></p>
					</div>
					<div class="wk-report-ticket">
						<p class="wk-report-ticket__name"><strong><?php esc_html_e( '登录闸', 'wink-gatekeeper' ); ?></strong></p>
						<p class="wk-report-ticket__main"><?php echo esc_html( \WinkGatekeeper\Stats\OverviewRoster::ticket_main( $login_reject, 'login' ) ); ?></p>
						<p class="wk-muted"><?php
							echo esc_html(
								$attempts > 0
									? sprintf(
										/* translators: %d: 密码输错次数. */
										__( '密码输错 %d 次，出码拦下', 'wink-gatekeeper' ),
										$attempts
									)
									: __( '没人试图撬门', 'wink-gatekeeper' )
							);
						?></p>
					</div>
					<div class="wk-report-ticket">
						<p class="wk-report-ticket__name"><strong><?php esc_html_e( '注册·找回闸', 'wink-gatekeeper' ); ?></strong></p>
						<p class="wk-report-ticket__main"><?php echo esc_html( \WinkGatekeeper\Stats\OverviewRoster::ticket_main( $acct_reject, 'register' ) ); ?></p>
						<p class="wk-muted"><?php esc_html_e( '没有异常注册或找回', 'wink-gatekeeper' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
			<p class="wk-overview-actions">
				<a href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=moderated' ) ); ?>"><?php
					echo esc_html( sprintf(
						/* translators: 1: 待你过目. 2: 条数. */
						__( '%1$s的评论 %2$d 条 →', 'wink-gatekeeper' ),
						\WinkGatekeeper\Stats\Labels::pending_word(),
						$pending_n
					) );
					?> <?php esc_html_e( '去处理', 'wink-gatekeeper' ); ?></a>
				<?php if ( $ip_captcha > 0 ) : ?>
					· <span class="wk-muted"><?php
						echo esc_html( sprintf(
							/* translators: %d: IP 信誉强制验证码次数. */
							__( '另有 IP 信誉强制验证码 %d 次（单列口径——不计入上方数字）', 'wink-gatekeeper' ),
							$ip_captcha
						) );
					?></span>
				<?php endif; ?>
				· <a href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=spam' ) ); ?>"><?php
					echo esc_html( sprintf(
						/* translators: 1: 垃圾箱存档. 2: 条数. */
						__( '%1$s %2$d 条 →', 'wink-gatekeeper' ),
						\WinkGatekeeper\Stats\Labels::spam_word(),
						$spam_n
					) );
					?> <?php echo esc_html( \WinkGatekeeper\Stats\InterceptDigest::spam_warn( $spam_n ) ? __( '去看看（该清一清了）', 'wink-gatekeeper' ) : __( '去看看', 'wink-gatekeeper' ) ); ?></a>
			</p>
		</section>
		<?php
	}

	/**
	 * 块 C·服务商点名册（本次核心——原「驱动健康」表退役；UI-FIX A 收拢行）。
	 *
	 * registry 全集（验证码 2 + 云端审核 3）；本地规则不入册给脚注。UI-FIX A
	 * （2026-11-12 用户实测反馈折中方案）：默认只展开已配置卡（当班+待命，含其上
	 * 异常卡）；未配置服务商收拢一行「未配置的服务商：人话名 · 人话名 [去配置 →]」
	 * ——保留可发现性、不占屏；单入口替代每卡一入口（跳转目标=未配置卡 config_tab
	 * 去重，全同域链对应 Tab，混合域回落 Tab② 三步引导）。每卡固定信息：岗位小字
	 * + 人话名 / 排班态·健康态（Labels 词）/ 今日一句摘要。数据=渲染侧推导
	 * （overview_roster_cards 边界收集 + OverviewRoster::cards 纯函数组装）——
	 * 不改 DriverHealth 存储（agent C §E-b 方案甲，N9 不采纳）。
	 *
	 * @return void
	 */
	private static function render_overview_roster(): void {
		$cards = self::overview_roster_cards();
		$shown = array();
		$pending_setup = array();
		foreach ( $cards as $roster_card ) {
			if ( 'unconfigured' === (string) ( $roster_card['duty'] ?? '' ) ) {
				$pending_setup[] = $roster_card;
			} else {
				$shown[] = $roster_card;
			}
		}
		// 收拢行单入口目标：未配置卡 config_tab 去重——恰一域=该域 Tab；混合域=Tab②（三步引导起点）。
		$setup_tabs = array_values( array_unique( array_filter( array_map(
			static function ( array $roster_card ): string {
				return (string) ( $roster_card['config_tab'] ?? '' );
			},
			$pending_setup
		) ) ) );
		$setup_tab = 1 === count( $setup_tabs ) ? (string) $setup_tabs[0] : 'captcha';
		$setup_names = array();
		foreach ( $pending_setup as $roster_card ) {
			$setup_names[] = (string) ( $roster_card['label'] ?? '' );
		}
		?>
		<section id="wk-overview-roster" class="wk-card">
			<h2><?php
				echo esc_html( sprintf(
					/* translators: %d: 服务商数. */
					__( '服务商点名册（%d 名）', 'wink-gatekeeper' ),
					count( $cards )
				) );
				?> <span class="wk-muted"><?php esc_html_e( '没出错的也每天点名，一眼见分晓', 'wink-gatekeeper' ); ?></span></h2>
			<div class="wk-roster-cards">
				<?php foreach ( $shown as $roster_card ) { self::render_roster_card( $roster_card ); } ?>
			</div>
			<?php if ( array() !== $pending_setup ) : ?>
				<p class="wk-roster-unconfigured"><?php
					echo esc_html( sprintf(
						/* translators: %s: 未配置服务商人话名清单. */
						__( '未配置的服务商：%s', 'wink-gatekeeper' ),
						implode( ' · ', $setup_names )
					) );
					?> <a href="<?php echo esc_url( self::tab_url( $setup_tab ) ); ?>"><?php esc_html_e( '去配置 →', 'wink-gatekeeper' ); ?></a></p>
			<?php endif; ?>
			<p class="wk-muted"><?php echo esc_html( \WinkGatekeeper\Stats\Labels::local_rules_footnote() ); ?></p>
			<p class="wk-muted"><?php echo esc_html( \WinkGatekeeper\Stats\Labels::pass_uncounted_note() ); ?></p>
		</section>
		<?php
	}

	/**
	 * 点名册数据收集（WP 边界：registry 全集×配置树×DriverHealth×Usage×StatsCache
	 * 只读取数；推导收口 OverviewRoster::cards 纯函数——排班态矩阵单测钉在
	 * OverviewRosterTest）。
	 *
	 * @return array<int, array<string, mixed>> 点名册卡组（异常置顶微排序后）。
	 */
	private static function overview_roster_cards(): array {
		if ( null === self::$driver_registry ) {
			return array();
		}
		$settings = self::settings();
		$captcha  = isset( $settings['captcha'] ) && is_array( $settings['captcha'] ) ? $settings['captcha'] : array();
		$cloud    = isset( $settings['review']['cloud'] ) && is_array( $settings['review']['cloud'] ) ? $settings['review']['cloud'] : array();
		$chain    = isset( $cloud['chain'] ) && is_array( $cloud['chain'] ) ? $cloud['chain'] : array();
		/* 【v1.7·M3-IP·定稿 §9】iprep 子树入册（AbuseIPDB 卡自动入点名册——type='ip'
		 * 驱动收集；config=iprep.<id>；当班=iprep.enabled，!enabled+必填齐=待命蓝点）。 */
		$iprep    = isset( $settings['iprep'] ) && is_array( $settings['iprep'] ) ? $settings['iprep'] : array();
		$usage    = \WinkGatekeeper\Stats\Usage::get();
		$cache    = \WinkGatekeeper\Stats\StatsCache::get();

		$drivers    = array();
		$configs    = array();
		$halted     = array();
		$usage_map  = array();
		$counts_map = array();
		foreach ( array( 'captcha', 'review', 'ip' ) as $driver_type ) {
			foreach ( self::$driver_registry->by_type( $driver_type ) as $driver_id => $meta ) {
				$driver_id = (string) $driver_id;
				// 本地驱动不入册（无外部故障面——脚注交代，B 提案块 C）。
				if ( 'local_rules' === $driver_id ) {
					continue;
				}
				$config = 'captcha' === $driver_type
					? ( isset( $captcha[ $driver_id ] ) && is_array( $captcha[ $driver_id ] ) ? $captcha[ $driver_id ] : array() )
					: ( 'ip' === $driver_type
						? ( isset( $iprep[ $driver_id ] ) && is_array( $iprep[ $driver_id ] ) ? $iprep[ $driver_id ] : array() )
						: ( isset( $cloud[ $driver_id ] ) && is_array( $cloud[ $driver_id ] ) ? $cloud[ $driver_id ] : array() ) );
				$drivers[]                = $meta;
				$configs[ $driver_id ]    = $config;
				$halted[ $driver_id ]     = DriverHealth::state_today( $driver_id );
				$usage_map[ $driver_id ]  = \WinkGatekeeper\Stats\Usage::daily_total_for( $usage, $driver_id );
				$counts_map[ $driver_id ] = \WinkGatekeeper\Stats\InterceptDigest::driver_counts( $cache, $driver_id, false );
			}
		}

		return \WinkGatekeeper\Stats\OverviewRoster::cards( $drivers, array(
			'captcha_driver' => isset( $captcha['driver'] ) && is_string( $captcha['driver'] ) ? $captcha['driver'] : '',
			'cloud_enabled'  => ! empty( $cloud['enabled'] ),
			'chain'          => $chain,
			'configs'        => $configs,
			'halted'         => $halted,
			'usage'          => $usage_map,
			'counts'         => $counts_map,
			'iprep_enabled'  => ! empty( $iprep['enabled'] ),
		) );
	}

	/**
	 * 点名册单卡渲染（只接已配置卡——未配置收拢行见 render_overview_roster；
	 * 停转=警示/黄色状态点——颜色映射在渲染层）。
	 *
	 * @param array<string, mixed> $roster_card OverviewRoster::cards 输出行。
	 * @return void
	 */
	private static function render_roster_card( array $roster_card ): void {
		$dot = 'wk-dot--ok';
		if ( ! ( $roster_card['healthy'] ?? true ) ) {
			$dot = 'quota_exhausted' === ( $roster_card['halt'] ?? '' ) ? 'wk-dot--warn' : 'wk-dot--alert';
		}

		$status = (string) ( $roster_card['duty_word'] ?? '' );
		if ( '' !== (string) ( $roster_card['health_word'] ?? '' ) ) {
			$status .= ' · ' . (string) $roster_card['health_word'];
		}
		?>
		<div class="wk-roster-card">
			<p class="wk-roster-role"><?php echo esc_html( (string) ( $roster_card['role'] ?? '' ) ); ?></p>
			<p><strong><?php echo esc_html( (string) ( $roster_card['label'] ?? '' ) ); ?></strong></p>
			<p class="wk-roster-status">
				<span class="wk-dot <?php echo esc_attr( $dot ); ?>" aria-hidden="true"></span>
				<?php echo esc_html( $status ); ?>
			</p>
			<?php if ( '' !== (string) ( $roster_card['halt_note'] ?? '' ) ) : ?>
				<p class="wk-muted"><?php echo esc_html( (string) $roster_card['halt_note'] ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== (string) ( $roster_card['summary'] ?? '' ) ) : ?>
				<p class="wk-muted"><?php echo esc_html( (string) $roster_card['summary'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * 块 D·最近岔子（原「事件流预览」占位卡升级——批 2 N2∪N3 合并时间线的紧凑形态）。
	 *
	 * 入口性质：只留 5 条（明细与筛选归 Tab⑥）；故障与解除记录都入列（「故障→修复」
	 * 完整小剧场，F9 停转留痕）；空态显式人话「最近 7 天风平浪静」；卡头入口链接
	 * Tab⑥（事件流筛选页 roadmap——先链页顶，agent C C4）。
	 *
	 * @return void
	 */
	private static function render_overview_incidents(): void {
		$faults = \WinkGatekeeper\Stats\EventsTable::recent_faults( \WinkGatekeeper\Stats\OverviewRoster::INCIDENT_LIMIT );
		$health = \WinkGatekeeper\Stats\EventsTable::recent_health_timeline( \WinkGatekeeper\Stats\OverviewRoster::INCIDENT_LIMIT, 7 );

		$driver_labels = array();
		if ( null !== self::$driver_registry ) {
			foreach ( self::$driver_registry->all() as $driver_id => $meta ) {
				$driver_labels[ (string) $driver_id ] = is_string( $meta['label'] ?? null ) ? $meta['label'] : (string) $driver_id;
			}
		}

		$timeline  = \WinkGatekeeper\Stats\InterceptDigest::fault_timeline_rows( $faults, $health, $driver_labels );
		$now       = current_time( 'mysql' ); // WP 边界（站点时区）。
		$today     = substr( $now, 0, 10 );
		$yesterday = gmdate( 'Y-m-d', (int) strtotime( $now . ' -1 day' ) );
		$lines     = \WinkGatekeeper\Stats\OverviewRoster::incident_lines( $timeline, $today, $yesterday, \WinkGatekeeper\Stats\OverviewRoster::INCIDENT_LIMIT );
		?>
		<section id="wk-overview-incidents" class="wk-card">
			<h2><?php esc_html_e( '最近岔子', 'wink-gatekeeper' ); ?><?php if ( array() !== $lines ) : ?> <span class="wk-muted"><?php
				echo esc_html( sprintf(
					/* translators: %d: 条数. */
					__( '（最近 %d 条）', 'wink-gatekeeper' ),
					count( $lines )
				) );
			?></span><?php endif; ?>
				<a class="wk-overview-more" href="<?php echo esc_url( self::tab_url( 'stats' ) ); ?>"><?php esc_html_e( '完整记录 → 统计页', 'wink-gatekeeper' ); ?></a></h2>
			<?php if ( array() === $lines ) : ?>
				<p class="wk-muted wk-overview-calm"><?php esc_html_e( '最近 7 天风平浪静。', 'wink-gatekeeper' ); ?></p>
			<?php else : ?>
				<ul class="wk-incident-list">
					<?php foreach ( $lines as $line ) : ?>
						<li>
							<span class="wk-muted"><?php echo esc_html( (string) $line['time_word'] ); ?></span>
							<strong><?php echo esc_html( (string) $line['driver'] ); ?></strong>
							<?php echo esc_html( (string) $line['text'] ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * 块 E·站点底账（一行灰字带——「不出错没人看、出错才重要」的全局开关态收纳）。
	 *
	 * 内容：统计计数开/关 · 演练模式开/关（开时与块 A 通栏呼应）· 事件表容量读数
	 * （N11——agent C B-6 裁决替代无指标的「事件记录正常」）。
	 * 【M4UX UI-1-1】旧「首次使用？三步配好 →」页脚引导退役——引导升位为页顶
	 * 通栏（render_overview_onboarding，判定复用 overview_configured_count）。
	 *
	 * @return void
	 */
	private static function render_overview_footer(): void {
		$settings = self::settings();
		$stats_on = ! empty( $settings['stats']['enabled'] );
		$rows     = \WinkGatekeeper\Stats\EventsTable::row_count();
		$max_rows = \WinkGatekeeper\Stats\EventsTable::MAX_ROWS;
		?>
		<section id="wk-overview-footer" class="wk-overview-footer">
			<?php echo esc_html( sprintf(
				/* translators: 1: 统计计数态. 2: 演练模式态. 3: 当前事件行数. 4: 容量上限. */
				__( '统计计数 %1$s · %2$s %3$s · 事件记录 %4$d / %5$d 条', 'wink-gatekeeper' ),
				$stats_on ? __( '开启', 'wink-gatekeeper' ) : __( '停用', 'wink-gatekeeper' ),
				\WinkGatekeeper\Stats\Labels::test_mode_word(),
				\WinkGatekeeper\Gates\TestMode::wp_active() ? __( '进行中', 'wink-gatekeeper' ) : __( '未开启', 'wink-gatekeeper' ),
				$rows,
				$max_rows
			) ); ?>
		</section>
		<?php
	}

	/**
	 * 全站已配好服务商计数（首次使用引导显隐判定——验证码与云审两域合并；
	 * 必填缺失判定单一来源 DriverConfigSpec——F11）。
	 *
	 * @param array<string, mixed> $settings 设置树。
	 * @return int
	 */
	private static function overview_configured_count( array $settings ): int {
		if ( null === self::$driver_registry ) {
			return 0;
		}
		$captcha = isset( $settings['captcha'] ) && is_array( $settings['captcha'] ) ? $settings['captcha'] : array();
		$cloud   = isset( $settings['review']['cloud'] ) && is_array( $settings['review']['cloud'] ) ? $settings['review']['cloud'] : array();
		$chain   = isset( $cloud['chain'] ) && is_array( $cloud['chain'] ) ? $cloud['chain'] : array();

		$count = 0;
		foreach ( self::$driver_registry->by_type( 'captcha' ) as $driver_id => $meta ) {
			$driver_id = (string) $driver_id;
			$config    = isset( $captcha[ $driver_id ] ) && is_array( $captcha[ $driver_id ] ) ? $captcha[ $driver_id ] : array();
			if ( array() === DriverConfigSpec::missing_fields( is_array( $meta ) ? $meta : array(), $config ) ) {
				$count++;
			}
		}
		$registered = self::$driver_registry->by_type( 'review' );
		foreach ( $chain as $driver_id ) {
			if ( ! is_string( $driver_id ) || ! isset( $registered[ $driver_id ] ) || ! is_array( $registered[ $driver_id ] ) ) {
				continue;
			}
			$config = isset( $cloud[ $driver_id ] ) && is_array( $cloud[ $driver_id ] ) ? $cloud[ $driver_id ] : array();
			if ( array() === DriverConfigSpec::missing_fields( $registered[ $driver_id ], $config ) ) {
				$count++;
			}
		}
		return $count;
	}

	/* -------------------------------------------------------------------
	 * Tab② 验证码（03 §1 v1.3 用户建议1+2：下拉选择 + 分厂商配置区 + 切换保留 +
	 * 双层必填校验 + 现场测试凭据；渲染数据从 CaptchaTabSpec 纯逻辑派生）
	 * ------------------------------------------------------------------ */

	private static function render_tab_captcha(): void {
		$settings = self::settings();
		$active   = is_string( $settings['captcha']['driver'] ?? null ) ? $settings['captcha']['driver'] : '';
		$tencent  = $settings['captcha']['tencent'];
		$aliyun   = $settings['captcha']['aliyun']; // 【v1.5·B3】阿里 2.0 配置子树（04 §2 v1.5）。

		// 服务端黄条（双层校验第二层）：选了厂商但必填空 → 驱动视为未配置（设置仍保存）。
		$notice = CaptchaTabSpec::server_notice( $active, $tencent, $aliyun );
		if ( null !== $notice ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( $notice ) . '</strong></p></div>';
		}

		/* 【M6·批H3·H3-A】驱动未配置 notice 补位（手测反馈 A：Tab② 顶部也提示——此前
		 * 仅 Tab① 总览兜底；U-1 式形态，文案与 Tab① 同义）。 */
		if ( '' === $active ) {
			echo '<div class="notice notice-warning inline" id="wk-captcha-unconfigured-notice"><p><strong>'
				. esc_html__( '人机验证还没选服务商', 'wink-gatekeeper' )
				. '</strong>——'
				. esc_html__( '机器人不会被出题拦住；评论仍受站内规矩把关，请在下方「驱动选择」中挑选。', 'wink-gatekeeper' )
				. '</p></div>';
		}

		$area     = CaptchaTabSpec::config_area_state( $active );
		$dots     = CaptchaTabSpec::dot_labels();
		$options  = CaptchaTabSpec::driver_options(
			null !== self::$driver_registry ? self::$driver_registry->by_type( 'captcha' ) : array(),
			$tencent,
			$aliyun
		);
		$guide    = CaptchaTabSpec::guide();
		?>
		<section class="wk-card">
			<h2><?php esc_html_e( '闸开关', 'wink-gatekeeper' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '评论闸', 'wink-gatekeeper' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'gates', 'comment', 'enabled' ) ) ); ?>" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'gates', 'comment', 'enabled' ) ) ); ?>" value="1" <?php checked( ! empty( $settings['gates']['comment']['enabled'] ) ); ?> />
							<?php esc_html_e( '启用评论保护', 'wink-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( '= 本地规则四闸 + 验证码 + IP 信誉三阶段总开关；只关验证码请把驱动选「未配置」。关闭后评论表单零 DOM 变化、提交不验证。', 'wink-gatekeeper' ); ?></p>

						<?php
						/* 【v1.4·反馈6+澄清A/R2/03 Tab②】评论保护·信用免验折叠区（默认收起）：
						 * 五键 + IP 信誉三态说明块（澄清 A 强制）+「去配置 IP 信誉」跳 Tab④ 引导 + 整页缓存提示。 */
						$trust = isset( $settings['captcha']['trust'] ) && is_array( $settings['captcha']['trust'] ) ? $settings['captcha']['trust'] : array();
						?>
						<details class="wk-guide" style="margin-top:10px;">
							<summary><?php esc_html_e( '评论保护·信用免验（预检免验证码）', 'wink-gatekeeper' ); ?></summary>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row"><?php esc_html_e( '信用免验', 'wink-gatekeeper' ); ?></th>
									<td>
										<!-- captcha.trust.enabled（04 §2 v1.4 六键之一；纯 on/off——三态语义由澄清 A 定死无需语义开关）。 -->
										<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'trust', 'enabled' ) ) ); ?>" value="0" />
										<label>
											<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'trust', 'enabled' ) ) ); ?>" value="1" <?php checked( ! isset( $trust['enabled'] ) || ! empty( $trust['enabled'] ) ); ?> />
											<?php esc_html_e( '点击「完成人机验证」时先预检（本地四闸 + IP 信誉 + 历史信用），全过免真实验证码', 'wink-gatekeeper' ); ?>
										</label>
										<p class="description"><?php esc_html_e( '预检是 UX 预演——提交期四闸/云审照常全链兜底，免验的只有验证码挑战这一件事。', 'wink-gatekeeper' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="wk-trust-min-approved"><?php esc_html_e( '已批评论阈值 N', 'wink-gatekeeper' ); ?></label></th>
									<td>
										<input type="number" id="wk-trust-min-approved" min="1" max="10" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'trust', 'min_approved' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $trust['min_approved'] ?? 3 ) ); ?>" class="small-text" />
										<p class="description"><?php esc_html_e( '游客（同昵称+邮箱）或登录用户已有 N 条通过审核的评论即免验；WP 核心同类先例为 1，验证码豁免更强故默认收紧至 3。', 'wink-gatekeeper' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="wk-trust-ttl"><?php esc_html_e( '信任凭证有效期（分钟）', 'wink-gatekeeper' ); ?></label></th>
									<td>
										<input type="number" id="wk-trust-ttl" min="1" max="60" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'trust', 'ttl_minutes' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $trust['ttl_minutes'] ?? 10 ) ); ?>" class="small-text" />
										<p class="description"><?php esc_html_e( '预检通过签发信任凭证（绑定 IP+表单+身份，不绑内容——改内容重提不失效）；与验证短缓存窗口同宽。', 'wink-gatekeeper' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="wk-trust-credit-cache"><?php esc_html_e( '信用缓存（分钟）', 'wink-gatekeeper' ); ?></label></th>
									<td>
										<input type="number" id="wk-trust-credit-cache" min="0" max="60" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'trust', 'credit_cache_minutes' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $trust['credit_cache_minutes'] ?? 5 ) ); ?>" class="small-text" />
										<p class="description"><?php esc_html_e( '历史信用判定的布尔缓存 TTL（0 = 每次点击都查库）；站长批准新评论后缓存即时失效。', 'wink-gatekeeper' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="wk-trust-precheck-limit"><?php esc_html_e( '预检限流（次/时/IP）', 'wink-gatekeeper' ); ?></label></th>
									<td>
										<input type="number" id="wk-trust-precheck-limit" min="0" max="360" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'trust', 'precheck_hourly_limit' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $trust['precheck_hourly_limit'] ?? 30 ) ); ?>" class="small-text" />
										<p class="description"><?php esc_html_e( '封脚本高频试探关键词表/邮箱信用；真人一小时点按钮不超过个位数，30 容纳改稿重试。超限一律降级为出验证码。', 'wink-gatekeeper' ); ?></p>
									</td>
								</tr>
							</table>
							<!-- IP 信誉三态说明块（澄清 A 强制；03 Tab② 与登录高级区同文）+ 跳 Tab④ 引导。 -->
							<div class="notice notice-info inline">
								<p><strong><?php esc_html_e( 'IP 信誉三态：', 'wink-gatekeeper' ); ?></strong><?php esc_html_e( '未启用 IP 信誉时，信任链跳过该项，不影响免验资格与登录计数；启用后若配置异常/超时/报错/超阈值，将从严判定（按不通过处理，直接展示验证码）——建议配置 IP 信誉以提升防护。', 'wink-gatekeeper' ); ?></p>
								<p><a href="<?php echo esc_url( self::tab_url( 'iprep' ) ); ?>"><?php esc_html_e( '→ 去配置 IP 信誉（Tab④）', 'wink-gatekeeper' ); ?></a></p>
							</div>
							<p class="description"><?php esc_html_e( '整页缓存提示：启用整页缓存的站点建议缓存周期 <12 小时——nonce 陈旧会使免验静默退化为恒出码（fail-safe，不伤提交）。', 'wink-gatekeeper' ); ?></p>
						</details>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '登录闸', 'wink-gatekeeper' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'gates', 'login', 'enabled' ) ) ); ?>" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'gates', 'login', 'enabled' ) ) ); ?>" value="1" <?php checked( ! empty( $settings['gates']['login']['enabled'] ) ); ?> />
							<?php esc_html_e( '启用登录验证', 'wink-gatekeeper' ); ?>
						</label>
						<select name="<?php echo esc_attr( self::field_name( array( 'gates', 'login', 'render_mode' ) ) ); ?>">
							<?php foreach ( array( 'off' => __( '关闭', 'wink-gatekeeper' ), 'adaptive' => __( '自适应（默认）', 'wink-gatekeeper' ), 'always' => __( '常显', 'wink-gatekeeper' ) ) as $mode => $label ) : ?>
								<option value="<?php echo esc_attr( $mode ); ?>" <?php selected( $settings['gates']['login']['render_mode'], $mode ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php
						/* 【M6·批H·H-2】登录闸描述动态化（用户 2026-11-16——说明写死「10 次」与
						 * 当前配置值不符）：阈值按当前配置值渲染（缺键回落出厂默认 3/3），改阈值
						 * 保存后说明同步——不再硬编码次数。 */
						$adaptive = isset( $settings['captcha']['login_adaptive'] ) && is_array( $settings['captcha']['login_adaptive'] ) ? $settings['captcha']['login_adaptive'] : array();
						$desc_user_threshold = isset( $adaptive['user_threshold'] ) && is_numeric( $adaptive['user_threshold'] ) ? max( 1, (int) $adaptive['user_threshold'] ) : 3;
						$desc_ip_threshold   = isset( $adaptive['ip_threshold'] ) && is_numeric( $adaptive['ip_threshold'] ) ? max( 1, (int) $adaptive['ip_threshold'] ) : 3;
						?>
						<p class="description" id="wk-login-adaptive-desc"><?php
							echo esc_html( sprintf(
								/* translators: 1: 同用户名失败阈值 2: 同 IP 聚合阈值（均随当前配置值渲染） */
								__( '自适应：同一账户跨 IP 累计 %1$d 次口令错误（仅统计真实存在的账户）、或同 IP 聚合 %2$d 次、或站点级账号警报点亮后，登录页才出现验证码，平时零打扰；常显：每次登录都先验证。失败计数对 XML-RPC 同样生效；验证码驱动未配置时超阈也不拦。', 'wink-gatekeeper' ),
								$desc_user_threshold,
								$desc_ip_threshold
							) );
						?></p>

						
						<details class="wk-guide" style="margin-top:10px;">
							<summary><?php esc_html_e( '高级：阈值 / 窗口 / IP 信誉联动 / XML-RPC', 'wink-gatekeeper' ); ?></summary>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row"><label for="wk-login-user-threshold"><?php esc_html_e( '同用户名失败阈值', 'wink-gatekeeper' ); ?></label></th>
									<td>
										<!-- 【v1.4·R4-G】threshold → user_threshold（schema v2 迁移键）。 -->
										<input type="number" id="wk-login-user-threshold" min="1" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'login_adaptive', 'user_threshold' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $adaptive['user_threshold'] ?? 3 ) ); ?>" class="small-text" />
										<p class="description"><?php esc_html_e( '同一账户跨 IP 累计 N 次口令错误出码——分布式爆破也触发；仅统计真实存在的账户。', 'wink-gatekeeper' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="wk-login-window"><?php esc_html_e( '计数窗口（分钟）', 'wink-gatekeeper' ); ?></label></th>
									<td>
										<input type="number" id="wk-login-window" min="1" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'login_adaptive', 'window_minutes' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $adaptive['window_minutes'] ?? 15 ) ); ?>" class="small-text" />
										<p class="description"><?php esc_html_e( '失败计数的固定窗口：窗口过后计数自然清零（成功登录立即清零）。', 'wink-gatekeeper' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="wk-login-ip-threshold"><?php esc_html_e( '同 IP 失败阈值', 'wink-gatekeeper' ); ?></label></th>
									<td>
										<input type="number" id="wk-login-ip-threshold" min="1" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'login_adaptive', 'ip_threshold' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $adaptive['ip_threshold'] ?? 3 ) ); ?>" class="small-text" />
										<p class="description"><?php esc_html_e( '同 IP 换用户名喷洒的聚合阈值：无论用户名怎么换，累计达此次数即触发。', 'wink-gatekeeper' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'IP 信誉联动', 'wink-gatekeeper' ); ?></th>
									<td>
										<!-- 【v1.4·澄清A】iprep_gate：登录闸 IP 信誉三态联动开关（M3 前未装配=恒 skip=现行行为）。 -->
										<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'login_adaptive', 'iprep_gate' ) ) ); ?>" value="0" />
										<label>
											<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'login_adaptive', 'iprep_gate' ) ) ); ?>" value="1" <?php checked( ! empty( $adaptive['iprep_gate'] ) ); ?> />
											<?php esc_html_e( '启用后按 IP 信誉三态参与登录出码判定', 'wink-gatekeeper' ); ?>
										</label>
										<p class="description"><?php esc_html_e( '未启用 IP 信誉时信任链跳过该项，不影响登录计数；启用后若配置异常/超时/报错/超阈值，将从严判定（直接展示验证码）。', 'wink-gatekeeper' ); ?></p>
									</td>
								</tr>
									<tr>
										<th scope="row"><?php esc_html_e( '关闭 XML-RPC 登录', 'wink-gatekeeper' ); ?></th>
										<td>
											<?php $xmlrpc_login = isset( $settings['gates']['login']['xmlrpc_login'] ) ? (bool) $settings['gates']['login']['xmlrpc_login'] : false; // 缺键回落=false（v1.7 默认翻转——存量不迁移，仅新装/缺键站点按新默认）。 ?>
											<!-- 反选形态：hidden 恒 1，勾选提交 0（后值覆盖前值 = 关闭）。默认关（v1.7 breaking）——使用 XML-RPC 发布工具的站长在此手动开启（取消勾选并保存）。 -->
											<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'gates', 'login', 'xmlrpc_login' ) ) ); ?>" value="1" />
											<label>
												<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'gates', 'login', 'xmlrpc_login' ) ) ); ?>" value="0" <?php checked( false, $xmlrpc_login ); ?> />
												<?php esc_html_e( '彻底关闭 XML-RPC 登录（推荐加固）', 'wink-gatekeeper' ); ?>
											</label>
											<p class="description"><?php esc_html_e( '默认关闭（0.4.5 起）：使用 XML-RPC 发布工具（博客客户端）的站长请取消勾选并保存以开启；升级站点已开启的不受影响。勾选后登录类 XML-RPC 方法（wp.getUsersBlogs、system.multicall 携带的认证等）整体 405 拒绝；system.listMethods 等只读方法不受影响。开启状态下超阈计数也会先行逐次拒绝 XML-RPC 认证（验证码无法在 XML-RPC 内呈现）。', 'wink-gatekeeper' ); ?></p>
										</td>
									</tr>
								</table>
								<!-- IP 信誉三态说明块（澄清 A 强制；03 Tab② 登录高级区）+ 跳 Tab④ 引导。 -->
								<div class="notice notice-info inline">
									<p><strong><?php esc_html_e( 'IP 信誉三态：', 'wink-gatekeeper' ); ?></strong><?php esc_html_e( '未启用 IP 信誉时，信任链跳过该项，不影响免验资格与登录计数；启用后若配置异常/超时/报错/超阈值，将从严判定（按不通过处理，直接展示验证码）——建议配置 IP 信誉以提升防护。', 'wink-gatekeeper' ); ?></p>
									<p><a href="<?php echo esc_url( self::tab_url( 'iprep' ) ); ?>"><?php esc_html_e( '→ 去配置 IP 信誉（Tab④）', 'wink-gatekeeper' ); ?></a></p>
								</div>
							</details>
						</td>
					</tr>
					<?php
					/* 【v1.4·反馈4/R3/03 Tab②】注册闸/找回密码闸两新开关（常显模式无自适应档；
					 * 默认开；未配验证码驱动自动 inert；users_can_register=off 站注册表单核心不可达；
					 * 多站点注册走 wp-signup.php 本闸不覆盖——02 §3.2a）。 */
					foreach ( array(
						'register'     => array( 'label' => '注册闸', 'hint' => '注册表单（wp-login.php?action=register）常显验证码，无票注册被拒且用户不创建。' ),
						'lostpassword' => array( 'label' => '找回密码闸', 'hint' => '找回密码表单常显验证码，无票提交被拒且重置邮件不发（含用户不存在的提交——防枚举）。' ),
					) as $account_key => $account_meta ) :
						?>
					<tr>
						<th scope="row"><?php echo esc_html( $account_meta['label'] ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'gates', $account_key, 'enabled' ) ) ); ?>" value="0" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'gates', $account_key, 'enabled' ) ) ); ?>" value="1" <?php checked( ! empty( $settings['gates'][ $account_key ]['enabled'] ) ); ?> />
								<?php esc_html_e( '启用（常显——进页面即出验证码）', 'wink-gatekeeper' ); ?>
							</label>
							<p class="description"><?php echo esc_html( $account_meta['hint'] ); ?><?php esc_html_e( '未配置验证码驱动时自动不渲染不拦截；「设置 → 常规」关闭任何人可注册后注册表单不可达（开关空转无害）；多站点注册走 wp-signup.php，本闸不覆盖。', 'wink-gatekeeper' ); ?></p>
						</td>
					</tr>
					<?php endforeach; ?>
				</table>
			</section>

		<section class="wk-card">
			<h2><?php esc_html_e( '驱动选择', 'wink-gatekeeper' ); ?></h2>
			<label for="wk-captcha-driver" class="screen-reader-text"><?php esc_html_e( '验证码驱动', 'wink-gatekeeper' ); ?></label>
			<select id="wk-captcha-driver" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'driver' ) ) ); ?>">
				<?php foreach ( $options as $option ) : ?>
					<option value="<?php echo esc_attr( $option['value'] ); ?>" <?php selected( $option['value'], $active ); ?>>
						<?php
						// 选项文字后缀状态点（03 Tab② v1.3：未配置/已配置/配置错误）。
						$text = $option['label'];
						if ( isset( $option['dot'] ) ) {
							$text .= ' — ' . $dots[ $option['dot'] ];
						}
						echo esc_html( $text );
						?>
					</option>
				<?php endforeach; ?>
			</select>
			<!-- M4 厂商项注册位：阿里 2.0 / hCaptcha / Turnstile / 极验——DriverRegistry 注册后自动入下拉与配置区。 -->
			<p class="description"><?php esc_html_e( '选择厂商后下方显示配置区；切换厂商不清空已填字段（各自独立保存）。「未配置」= 验证码阶段整体跳过。', 'wink-gatekeeper' ); ?></p>
			<p id="wk-captcha-unconfigured-note" class="description<?php echo $area['visible'] ? ' hidden' : ''; ?>">
				<span class="wk-dot wk-dot--alert" aria-hidden="true"></span>
				<?php echo esc_html( $area['hidden_note'] ); ?>
			</p>
			<!-- 共存提示位：检测到官方 hCaptcha 插件接管时黄条「评论验证码由官方 hCaptcha 插件接管，本插件验证码已让位」——M4（02 §3.4）。 -->
		</section>

		<section class="wk-card" id="wk-captcha-config-area" data-wk-vendor="tencent"<?php echo ( $area['visible'] && 'tencent' === $active ) ? '' : ' hidden'; ?>>
			<h2><?php esc_html_e( '腾讯天御验证码 2.0 · 配置', 'wink-gatekeeper' ); ?></h2>

			<?php
			/* 【v1.5·DESIGN-C1 §3/03 Tab②】test_mode 演练开关黄条（02 §2.8 安全门 #1——
			 * 显式开关默认关 + 红字说明 + 二次确认复选框；启用中黄条尾部注明 24h 自动过期；
			 * 写路径=register_setting 主保存通道（manage_options+nonce 双闸，无新端点）。 */
			$tm_checked = ! empty( $settings['captcha']['test_mode'] );
			?>
			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( '演练模式（旁路人机验证）', 'wink-gatekeeper' ); ?></strong>
					<span style="color:#b32d2e;"><?php esc_html_e( '启用后全站人机验证旁路，仅用于链路演练。', 'wink-gatekeeper' ); ?></span>
					<?php esc_html_e( '启用中 24 小时后自动过期；旁路事件标注 drill（不进统计）、不写验证短缓存；内容闸/云审/IP 阶段照常全链兜底。', 'wink-gatekeeper' ); ?>
				</p>
				<p>
					<label>
						<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'test_mode' ) ) ); ?>" value="0" />
						<input type="checkbox" id="wk-test-mode" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'test_mode' ) ) ); ?>" value="1" <?php checked( $tm_checked ); ?> <?php echo $tm_checked ? '' : 'disabled'; ?> />
						<?php esc_html_e( '启用演练模式', 'wink-gatekeeper' ); ?>
					</label>
					<label style="margin-left:1em;">
						<input type="checkbox" id="wk-test-mode-confirm" />
						<?php esc_html_e( '我确认要旁路全站人机验证（二次确认）', 'wink-gatekeeper' ); ?>
					</label>
				</p>
			</div>
			<script>
				( function() {
					var main = document.getElementById( 'wk-test-mode' );
					var confirm = document.getElementById( 'wk-test-mode-confirm' );
					if ( ! main || ! confirm ) { return; }
					var sync = function() { main.disabled = ! main.checked && ! confirm.checked; };
					confirm.addEventListener( 'change', function() {
						sync();
						if ( ! confirm.checked && ! main.checked ) { return; }
					} );
					main.addEventListener( 'change', function() {
						if ( main.checked ) { confirm.checked = true; }
						sync();
					} );
					if ( main.checked ) { confirm.checked = true; } // 启用中保持可提交（关闭不受二次确认阻塞）。
					sync();
				} )();
			</script>

			<?php
			// 【v1.4·反馈9/R4 §C.3】逐驱动合规行（registry privacy 维渲染；原接入指引内的
			// 「核验将提交访客 IP」注记上移至此——常驻不可关闭，cross_border=true 升级黄条）。
			$privacy_row = DriverConfigSpec::privacy_notice( TencentCaptchaDriver::registry_entry(), '腾讯天御验证码 2.0' );
			if ( null !== $privacy_row ) :
				?>
				<div class="notice <?php echo 'warn' === $privacy_row['level'] ? 'notice-warning inline' : 'notice-info inline'; ?>">
					<p><?php echo esc_html( $privacy_row['text'] ); ?></p>
				</div>
			<?php endif; ?>

			<details class="wk-guide">
				<summary><?php esc_html_e( '接入指引（首次配置请展开）', 'wink-gatekeeper' ); ?></summary>
				<ol>
					<?php foreach ( $guide['steps'] as $step ) : ?>
						<li><?php echo esc_html( $step ); ?></li>
					<?php endforeach; ?>
				</ol>
				<p>
					<a href="<?php echo esc_url( $guide['console_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '验证码控制台', 'wink-gatekeeper' ); ?></a>
					· <a href="<?php echo esc_url( $guide['doc_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '票据核验 API 文档', 'wink-gatekeeper' ); ?></a>
				</p>
				<p class="description"><?php echo esc_html( $guide['ticket_note'] ); ?></p>
			</details>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-tencent-app-id"><?php esc_html_e( 'CaptchaAppId', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-tencent-app-id" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'tencent', 'app_id' ) ) ); ?>"
							value="<?php echo esc_attr( (string) $tencent['app_id'] ); ?>" class="regular-text"
							data-wk-required="1" data-wk-label="<?php esc_attr_e( 'CaptchaAppId', 'wink-gatekeeper' ); ?>" />
						<p class="description"><?php esc_html_e( '验证码控制台 → 验证管理 → 密钥列（公开级，前端组件加载用）。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-tencent-secret-id"><?php esc_html_e( 'SecretId（CAM）', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'captcha', 'tencent', 'secret_id' ), '' !== $tencent['secret_id'], 'SecretId（CAM）' ); ?>
						<p class="description"><?php esc_html_e( '云 API 密钥（TC3 签名用，访问管理 → API 密钥管理）。与验证码控制台的 AppSecretKey 分属两套凭据体系。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-tencent-secret-key"><?php esc_html_e( 'SecretKey（CAM）', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'captcha', 'tencent', 'secret_key' ), '' !== $tencent['secret_key'], 'SecretKey（CAM）' ); ?>
						<p class="description"><?php esc_html_e( '与 SecretId 配对的 CAM 密钥，仅服务端 TC3 签名使用，绝不出前端。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-tencent-app-secret-key"><?php esc_html_e( 'AppSecretKey（验证码控制台）', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'captcha', 'tencent', 'app_secret_key' ), '' !== $tencent['app_secret_key'], 'AppSecretKey（验证码控制台）' ); ?>
						<p class="description"><?php esc_html_e( '验证码控制台与 CaptchaAppId 同页的密钥——核验请求 body 必填参数（服务端在线校验强制，2026-11-09 实测定论）。仅服务端使用。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<?php
				/* 【v1.5·DESIGN-C1 §4.2/03 Tab②】min_score 派生控件（registry scoring 维驱动显隐）：
				 * 评分制驱动接入时自动出现（默认 0.5、钳 0.1–1.0）；现役驱动全部二态
				 * （scoring=false）控件隐藏——二态恒 score=1.0 对闸透明。 */
				$active_meta = ( null !== self::$driver_registry && '' !== $active ) ? self::$driver_registry->get( $active ) : null;
				if ( is_array( $active_meta ) && ! empty( $active_meta['scoring'] ) ) :
					?>
					<tr>
						<th scope="row"><label for="wk-captcha-min-score"><?php esc_html_e( '机器分阈值（min_score）', 'wink-gatekeeper' ); ?></label></th>
						<td>
							<input type="number" id="wk-captcha-min-score" step="0.1" min="0.1" max="1"
								name="<?php echo esc_attr( self::field_name( array( 'captcha', 'min_score' ) ) ); ?>"
								value="<?php echo esc_attr( (string) ( $settings['captcha']['min_score'] ?? 0.5 ) ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( '评分制驱动专用：机器分折算 (0,1] 后低于该阈值即按不通过拦截（服务端钳 0.1–1.0，防 0 形同虚设/1.5 恒拒）。事件 meta 记 score+threshold 可查差值。', 'wink-gatekeeper' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( '容灾票据（网络差放行）', 'wink-gatekeeper' ); ?></th>
					<td>
						<?php $disaster = 'block' === ( $tencent['disaster_pass'] ?? 'allow' ) ? 'block' : 'allow'; ?>
						<label style="display:block;margin-bottom:6px;">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'tencent', 'disaster_pass' ) ) ); ?>" value="allow" <?php checked( 'allow', $disaster ); ?> />
							<?php esc_html_e( '网络差时放行（默认）——前端容灾票按通过 + 每 IP 每小时限 5 次 + 事件标注 trerror', 'wink-gatekeeper' ); ?>
						</label>
						<label style="display:block;">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'tencent', 'disaster_pass' ) ) ); ?>" value="block" <?php checked( 'block', $disaster ); ?> />
							<?php esc_html_e( '拦截（安全优先）——攻击期临时切换，容灾票一律不认', 'wink-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( '官方语义：弱网用户可能拿到容灾票（trerror 前缀），默认拦了就是误杀真人；限速防滥用。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
			</table>

			<p>
				<button type="button" class="button" id="wk-tencent-test-credentials"
					data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX_TEST_CREDENTIALS ) ); ?>">
					<?php esc_html_e( '测试凭据', 'wink-gatekeeper' ); ?>
				</button>
				<span id="wk-tencent-test-result" aria-live="polite"></span>
			</p>
			<p class="description"><?php esc_html_e( '发一次全参假票核验：绿 = 凭据可用；红 = CAM 密钥（SecretId/SecretKey）错；黄 = 必填参数缺。结果不落库。', 'wink-gatekeeper' ); ?></p>
		</section>

		<?php
		/* ---------- 【v1.5·B3】阿里云验证码 2.0 配置区（03 Tab②【v1.5】/05 §1.2/DESIGN-D2 §1.4）---------- */
		$aliyun_guide = CaptchaTabSpec::aliyun_guide();
		?>
		<section class="wk-card" data-wk-vendor="aliyun"<?php echo ( $area['visible'] && 'aliyun' === $active ) ? '' : ' hidden'; ?>>
			<h2><?php esc_html_e( '阿里云验证码 2.0 · 配置', 'wink-gatekeeper' ); ?></h2>

			<?php
			// 逐驱动合规行（registry privacy 维渲染——R4 §C.3 同款；常驻不可关闭）。
			$privacy_row = DriverConfigSpec::privacy_notice( AliyunCaptchaDriver::registry_entry(), '阿里云验证码 2.0' );
			if ( null !== $privacy_row ) :
				?>
				<div class="notice <?php echo 'warn' === $privacy_row['level'] ? 'notice-warning inline' : 'notice-info inline'; ?>">
					<p><?php echo esc_html( $privacy_row['text'] ); ?></p>
				</div>
			<?php endif; ?>

			<details class="wk-guide">
				<summary><?php esc_html_e( '接入指引（首次配置请展开）', 'wink-gatekeeper' ); ?></summary>
				<ol>
					<?php foreach ( $aliyun_guide['steps'] as $step ) : ?>
						<li><?php echo esc_html( $step ); ?></li>
					<?php endforeach; ?>
				</ol>
				<p>
					<a href="<?php echo esc_url( $aliyun_guide['console_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '验证码控制台', 'wink-gatekeeper' ); ?></a>
					· <a href="<?php echo esc_url( $aliyun_guide['doc_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'VerifyIntelligentCaptcha API 文档', 'wink-gatekeeper' ); ?></a>
				</p>
				<p class="description"><?php echo esc_html( $aliyun_guide['ticket_note'] ); ?></p>
			</details>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-aliyun-access-key-id"><?php esc_html_e( 'AccessKeyId', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'captcha', 'aliyun', 'access_key_id' ), '' !== $aliyun['access_key_id'], 'AccessKeyId' ); ?>
						<p class="description"><?php esc_html_e( 'RAM AccessKey（禁主账号 AK——ACS3 V3 签名用，访问管理 → RAM）。仅服务端使用，绝不出前端。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-aliyun-access-key-secret"><?php esc_html_e( 'AccessKeySecret', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'captcha', 'aliyun', 'access_key_secret' ), '' !== $aliyun['access_key_secret'], 'AccessKeySecret' ); ?>
						<p class="description"><?php esc_html_e( '与 AccessKeyId 配对的 RAM 密钥（HMAC 签名密钥原文）。仅服务端使用。RAM 用户需授权 AliyunYundunAFSFullAccess。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-aliyun-scene-id"><?php esc_html_e( 'SceneId', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="text" id="wk-aliyun-scene-id" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'aliyun', 'scene_id' ) ) ); ?>"
							value="<?php echo esc_attr( (string) $aliyun['scene_id'] ); ?>" class="regular-text"
							data-wk-required="1" data-wk-label="<?php esc_attr_e( 'SceneId', 'wink-gatekeeper' ); ?>" />
						<p class="description"><?php esc_html_e( '验证码控制台 → 场景管理（必填——服务端补传防前端被篡改场景，错配返回 F012）。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-aliyun-prefix"><?php esc_html_e( 'prefix（身份标）', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="text" id="wk-aliyun-prefix" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'aliyun', 'prefix' ) ) ); ?>"
							value="<?php echo esc_attr( (string) $aliyun['prefix'] ); ?>" class="regular-text"
							data-wk-required="1" data-wk-label="<?php esc_attr_e( 'prefix（身份标）', 'wink-gatekeeper' ); ?>" />
						<p class="description"><?php esc_html_e( '控制台概览页获取——前端 AliyunCaptchaConfig 全局配置用（前端公开级参数，非服务端 API 凭据）。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '前端形态（mode）', 'wink-gatekeeper' ); ?></th>
					<td>
						<?php $aliyun_mode = 'popup' === ( $aliyun['mode'] ?? 'embed' ) ? 'popup' : 'embed'; ?>
						<label style="display:block;margin-bottom:6px;">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'aliyun', 'mode' ) ) ); ?>" value="embed" <?php checked( 'embed', $aliyun_mode ); ?> />
							<?php esc_html_e( '嵌入触发条（默认）——页内渲染「点击开始验证」触发条，点击后弹出拼图/图像复原交互弹层（SDK 自管）', 'wink-gatekeeper' ); ?>
						</label>
						<label style="display:block;">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'aliyun', 'mode' ) ) ); ?>" value="popup" <?php checked( 'popup', $aliyun_mode ); ?> />
							<?php esc_html_e( '弹层（零占位）——页内无组件，点击验证按钮直接弹出交互弹层', 'wink-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'region 恒 cn（前端与服务端 endpoint 映射须一致，错配即报错）；滑块宽度按官方最小 320px 传参。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
			</table>

			<p>
				<button type="button" class="button" id="wk-aliyun-test-credentials"
					data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX_TEST_CREDENTIALS_ALIYUN ) ); ?>">
					<?php esc_html_e( '测试凭据', 'wink-gatekeeper' ); ?>
				</button>
				<span id="wk-aliyun-test-result" aria-live="polite"></span>
			</p>
			<p class="description"><?php esc_html_e( '发一次假参实调（VerifyIntelligentCaptcha）：绿 = 凭据可用；红 = AccessKey/签名/权限错（含 RAM 未授权提示）；黄 = 必填参数缺。结果不落库。', 'wink-gatekeeper' ); ?></p>
		</section>
		<?php
	}

	/**
	 * Tab②「测试凭据」AJAX 端点（03 §1 契约④：nonce + manage_options 双闸；结果不落库）。
	 *
	 * 表单值（password 空串 = 回落已存值）+ 全参假票核验 → 三态呈现（L0-tencent 判定法）。
	 *
	 * @return void
	 */
	public static function ajax_test_credentials(): void {
		check_ajax_referer( self::AJAX_TEST_CREDENTIALS, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足：需要 manage_options。', 'wink-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$stored  = get_option( self::OPTION_NAME, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$stored  = SettingsSchema::merge_defaults( $stored );
		$current = isset( $stored['captcha']['tencent'] ) && is_array( $stored['captcha']['tencent'] ) ? $stored['captcha']['tencent'] : array();

		// 表单值覆盖（password 空串保留已存值——与保存契约同语义）。
		$tencent = array(
			'app_id'         => isset( $post['app_id'] ) && is_scalar( $post['app_id'] ) ? sanitize_text_field( (string) $post['app_id'] ) : (string) $current['app_id'],
			'secret_id'      => self::probe_field( $post, 'secret_id', (string) ( $current['secret_id'] ?? '' ) ),
			'secret_key'     => self::probe_field( $post, 'secret_key', (string) ( $current['secret_key'] ?? '' ) ),
			'app_secret_key' => self::probe_field( $post, 'app_secret_key', (string) ( $current['app_secret_key'] ?? '' ) ),
		);

		$precheck = CredentialProbe::local_precheck( $tencent );
		if ( null !== $precheck ) {
			wp_send_json_success( $precheck );
		}

		$settings = array_merge( $stored, array( 'captcha' => array_merge( $stored['captcha'], array( 'tencent' => $tencent ) ) ) );

		$driver = new TencentCaptchaDriver(
			$settings,
			new RemoteClient() // WP 边界：默认 wp_remote_post 传输（探测一次，不计 usage）。
		);
		$state = CredentialProbe::classify( $driver->probe_credentials() );
		wp_send_json_success( $state );
	}

	/**
	 * Tab② 阿里「测试凭据」AJAX 端点（【v1.5·B3】：03 §1 契约④同款——nonce +
	 * manage_options 双闸；结果不落库）。
	 *
	 * 表单值（password 空串 = 回落已存值）+ 假参实调（VerifyIntelligentCaptcha 全参假票）
	 * → 三态呈现（CredentialProbe::classify_aliyun，DESIGN-D2 §1.4 判定法）。
	 *
	 * @return void
	 */
	public static function ajax_test_credentials_aliyun(): void {
		check_ajax_referer( self::AJAX_TEST_CREDENTIALS_ALIYUN, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足：需要 manage_options。', 'wink-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$stored  = get_option( self::OPTION_NAME, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$stored  = SettingsSchema::merge_defaults( $stored );
		$current = isset( $stored['captcha']['aliyun'] ) && is_array( $stored['captcha']['aliyun'] ) ? $stored['captcha']['aliyun'] : array();

		// 表单值覆盖（password 空串保留已存值——与保存契约同语义）。
		$aliyun = array(
			'access_key_id'     => self::probe_field( $post, 'ali_access_key_id', (string) ( $current['access_key_id'] ?? '' ) ),
			'access_key_secret' => self::probe_field( $post, 'ali_access_key_secret', (string) ( $current['access_key_secret'] ?? '' ) ),
			'scene_id'          => isset( $post['ali_scene_id'] ) && is_scalar( $post['ali_scene_id'] ) ? sanitize_text_field( (string) $post['ali_scene_id'] ) : (string) ( $current['scene_id'] ?? '' ),
			'prefix'            => isset( $post['ali_prefix'] ) && is_scalar( $post['ali_prefix'] ) ? sanitize_text_field( (string) $post['ali_prefix'] ) : (string) ( $current['prefix'] ?? '' ),
		);

		$precheck = CredentialProbe::local_precheck_aliyun( $aliyun );
		if ( null !== $precheck ) {
			wp_send_json_success( $precheck );
		}

		$settings = array_merge( $stored, array( 'captcha' => array_merge( $stored['captcha'], array( 'aliyun' => $aliyun ) ) ) );

		$driver = new AliyunCaptchaDriver(
			$settings,
			new RemoteClient() // WP 边界：默认 wp_remote_post 传输（探测一次，不计 usage）。
		);
		$state = CredentialProbe::classify_aliyun( $driver->probe_credentials() );
		wp_send_json_success( $state );
	}

	/**
	 * 测试凭据表单字段（password 空串回落已存值）。
	 *
	 * @param array<string, mixed> $post    AJAX 提交。
	 * @param string               $key     字段名。
	 * @param string               $fallback 已存值。
	 * @return string
	 */
	private static function probe_field( array $post, string $key, string $fallback ): string {
		if ( ! isset( $post[ $key ] ) || ! is_scalar( $post[ $key ] ) ) {
			return $fallback;
		}
		$value = sanitize_text_field( (string) $post[ $key ] );
		return '' !== $value ? $value : $fallback;
	}

	/**
	 * Tab③ TMS「测试凭据」AJAX 端点（C4；03 §1 契约④：nonce + manage_options 双闸）。
	 *
	 * 表单值（password 空串 = 回落已存值）+ 良性样本文本真调用 → 三态呈现
	 * （CredentialProbe::classify_tms）；绿态顺带解除 tencent_tms 当日停转
	 * （02 §5「重存密钥并点自检通过」的解除条件落点）。
	 *
	 * @return void
	 */
	public static function ajax_test_credentials_tms(): void {
		check_ajax_referer( self::AJAX_TEST_CREDENTIALS_TMS, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足：需要 manage_options。', 'wink-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$stored  = get_option( self::OPTION_NAME, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$stored  = SettingsSchema::merge_defaults( $stored );
		$current = isset( $stored['review']['cloud']['tencent_tms'] ) && is_array( $stored['review']['cloud']['tencent_tms'] ) ? $stored['review']['cloud']['tencent_tms'] : array();

		// 表单值覆盖（password 空串保留已存值——与保存契约同语义）。
		$tms = array(
			'secret_id'    => self::probe_field( $post, 'tms_secret_id', (string) ( $current['secret_id'] ?? '' ) ),
			'secret_key'   => self::probe_field( $post, 'tms_secret_key', (string) ( $current['secret_key'] ?? '' ) ),
			'biz_type'     => self::probe_field( $post, 'tms_biz_type', (string) ( $current['biz_type'] ?? '' ) ),
		);

		if ( '' === $tms['secret_id'] || '' === $tms['secret_key'] ) {
			$missing = array();
			if ( '' === $tms['secret_id'] ) {
				$missing[] = 'SecretId';
			}
			if ( '' === $tms['secret_key'] ) {
				$missing[] = 'SecretKey';
			}
			wp_send_json_success( array(
				'state'   => 'yellow',
				'message' => '必填字段缺少：' . implode( '、', $missing ) . '——补齐后重新测试。',
				'missing' => $missing,
				'code'    => '',
			) );
		}

		$settings = $stored;
		$settings['review']['cloud']['tencent_tms'] = array_merge( $current, $tms );

		$driver = new TencentTmsDriver(
			$settings,
			new RemoteClient() // WP 边界：默认 wp_remote_post 传输（探测一次，不计 usage）。
		);
		$state = CredentialProbe::classify_tms( $driver->probe_credentials() );

		// 绿态解除当日停转（02 §5 解除条件：自检通过；probe 不计 usage）。
		if ( 'green' === $state['state'] ) {
			DriverHealth::clear_option( TencentTmsDriver::DRIVER_ID );
		}

		wp_send_json_success( $state );
	}

	/**
	 * Tab③ 阿里 PLUS「测试凭据」AJAX 端点（【v1.5·B5】05 §2.5 凭据自检三态；03 §1 契约④：
	 * nonce + manage_options 双闸）。
	 *
	 * 表单值（password 空串 = 回落已存值）+ 良性短文本真调用 → 三态呈现
	 * （CredentialProbe::classify_aliyun_green）；绿态顺带解除 aliyun_green 当日停转
	 * （02 §5「自检通过」解除条件落点——TMS 先例同款）。
	 *
	 * @return void
	 */
	public static function ajax_test_credentials_aliyun_green(): void {
		check_ajax_referer( self::AJAX_TEST_CREDENTIALS_ALIYUN_GREEN, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足：需要 manage_options。', 'wink-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$stored  = get_option( self::OPTION_NAME, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$stored  = SettingsSchema::merge_defaults( $stored );
		$current = isset( $stored['review']['cloud']['aliyun_green'] ) && is_array( $stored['review']['cloud']['aliyun_green'] )
			? $stored['review']['cloud']['aliyun_green']
			: array();

		// 表单值覆盖（password 空串保留已存值——与保存契约同语义）。
		$green = array(
			'access_key_id'     => self::probe_field( $post, 'green_access_key_id', (string) ( $current['access_key_id'] ?? '' ) ),
			'access_key_secret' => self::probe_field( $post, 'green_access_key_secret', (string) ( $current['access_key_secret'] ?? '' ) ),
			'service_nickname'  => self::probe_field( $post, 'green_service_nickname', (string) ( $current['service_nickname'] ?? '' ) ),
			'service_comment'   => self::probe_field( $post, 'green_service_comment', (string) ( $current['service_comment'] ?? '' ) ),
		);

		// 本地预检（黄态）：必填缺逐字段列出，不发远端调用（05 §2.5 黄态条款）。
		$precheck = CredentialProbe::local_precheck_aliyun_green( $green );
		if ( null !== $precheck ) {
			wp_send_json_success( array(
				'state'   => $precheck['state'],
				'message' => $precheck['message'],
				'missing' => $precheck['missing'],
				'code'    => '',
			) );
		}

		$settings                             = $stored;
		$settings['review']['cloud']['aliyun_green'] = array_merge( $current, $green );

		$driver = new AliyunGreenPlusDriver(
			$settings,
			new RemoteClient() // WP 边界：默认 wp_remote_post 传输（探测一次，不计 usage）。
		);
		$state = CredentialProbe::classify_aliyun_green( $driver->probe_credentials() );

		// 绿态解除当日停转（02 §5 解除条件：自检通过；probe 不计 usage）。
		if ( 'green' === $state['state'] ) {
			DriverHealth::clear_option( AliyunGreenPlusDriver::DRIVER_ID );
		}

		wp_send_json_success( $state );
	}

	/**
	 * Tab③ 百度「测试凭据」AJAX 端点（【v1.5·B6】05 §2.4 凭据自检三态；03 §1 契约④：
	 * nonce + manage_options 双闸）。
	 *
	 * 表单值（password 空串 = 回落已存值）+ 良性样本文本真调用（token 流含在内）→ 三态呈现
	 * （CredentialProbe::classify_baidu）；绿态顺带解除 baidu 当日停转
	 * （02 §5「自检通过」解除条件落点——TMS/阿里先例同款）。
	 *
	 * @return void
	 */
	public static function ajax_test_credentials_baidu(): void {
		check_ajax_referer( self::AJAX_TEST_CREDENTIALS_BAIDU, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足：需要 manage_options。', 'wink-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$stored  = get_option( self::OPTION_NAME, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$stored  = SettingsSchema::merge_defaults( $stored );
		$current = isset( $stored['review']['cloud']['baidu'] ) && is_array( $stored['review']['cloud']['baidu'] )
			? $stored['review']['cloud']['baidu']
			: array();

		// 表单值覆盖（password 空串保留已存值——与保存契约同语义）。
		$baidu = array(
			'api_key'    => self::probe_field( $post, 'baidu_api_key', (string) ( $current['api_key'] ?? '' ) ),
			'secret_key' => self::probe_field( $post, 'baidu_secret_key', (string) ( $current['secret_key'] ?? '' ) ),
		);

		// 本地预检（黄态）：必填缺逐字段列出，不发远端调用（05 §2.4 黄态条款）。
		$precheck = CredentialProbe::local_precheck_baidu( $baidu );
		if ( null !== $precheck ) {
			wp_send_json_success( array(
				'state'   => $precheck['state'],
				'message' => $precheck['message'],
				'missing' => $precheck['missing'],
				'code'    => '',
			) );
		}

		$settings = $stored;
		$settings['review']['cloud']['baidu'] = array_merge( $current, $baidu );

		$driver = new BaiduTextModerationDriver(
			$settings,
			new RemoteClient() // WP 边界：默认 wp_remote_post 传输（探测一次，不计 usage）。
		);
		$state = CredentialProbe::classify_baidu( $driver->probe_credentials() );

		// 绿态解除当日停转（02 §5 解除条件：自检通过；probe 不计 usage）。
		if ( 'green' === $state['state'] ) {
			DriverHealth::clear_option( BaiduTextModerationDriver::DRIVER_ID );
		}

		wp_send_json_success( $state );
	}

	/**
	 * password 型字段（04 §2 安全规则：不回显键值，仅「已配置✓」状态提示）。
	 *
	 * 【M6·批H·H-6】显式清除凭据控件（已配置时随附「清除此凭据」checkbox）：hidden 0
	 * 伴随域（未勾选显式提交 0=既有「空串保留」语义不动）+独立清空键名 <leaf>_clear
	 * （不进设置树——sanitize 桥清洗前剥除消费）。清除语义=勾选+留空保存 → 凭据置空串；
	 * 勾选+填新值 → 新值优先。仅 manage_options（register_setting 主保存通道双闸照旧）。
	 *
	 * @param string[] $path       设置树路径。
	 * @param bool     $configured 是否已有值。
	 * @param string   $label      字段显示名（JS 必填标红提示消费，data-wk-label）。
	 * @return void
	 */
	private static function password_field( array $path, bool $configured, string $label = '' ): void {
		$name = self::field_name( $path );
		$id   = 'wk-field-' . implode( '-', $path );
		echo '<input type="password" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="" class="regular-text" autocomplete="new-password"'
			. ' data-wk-required="1" data-wk-label="' . esc_attr( $label ) . '" data-wk-configured="' . ( $configured ? '1' : '0' ) . '" />';
		if ( $configured ) {
			echo ' <span class="wk-muted">' . esc_html__( '已配置 ✓（留空保存则不修改）', 'wink-gatekeeper' ) . '</span>';
			$leaf       = (string) end( $path );
			$clear_name = self::field_name( array_merge( array_slice( $path, 0, -1 ), array( $leaf . '_clear' ) ) );
			$clear_id   = 'wk-clear-' . implode( '-', $path );
			echo '<label class="wk-clear-credential" for="' . esc_attr( $clear_id ) . '">'
				. '<input type="hidden" name="' . esc_attr( $clear_name ) . '" value="0" />'
				. '<input type="checkbox" id="' . esc_attr( $clear_id ) . '" name="' . esc_attr( $clear_name ) . '" value="1" /> '
				. esc_html__( '清除此凭据', 'wink-gatekeeper' )
				. '</label>';
		}
	}

	/**
	 * 表单字段名（option 数组点路径 → name="wink_gatekeeper_settings[a][b][c]"）。
	 *
	 * @param string[] $path
	 * @return string
	 */
	private static function field_name( array $path ): string {
		$parts = array( self::OPTION_NAME );
		foreach ( $path as $segment ) {
			$parts[] = '[' . $segment . ']';
		}
		return implode( '', $parts );
	}

	/**
	 * 报告原因截断单点件（【M6·批H·H-8】用户 2026-11-16——截断展示尾部补「…」省略号，
	 * 全消费面生效）：单一实现在 InterceptDigest::truncate_reason（纯逻辑层——渲染面与
	 * 归堆面〔UrlAuditWorker::backlog_report_view〕同规约）；本件为渲染侧薄委托。
	 *
	 * @param string $reason 原因文本。
	 * @param int    $max    截断上限（默认 40——两消费面既有口径）。
	 * @return string 未达限原样；超限=前 N 字+「…」。
	 */
	private static function truncate_reason( string $reason, int $max = 40 ): string {
		return \WinkGatekeeper\Stats\InterceptDigest::truncate_reason( $reason, $max );
	}

	/* -------------------------------------------------------------------
	 * Tab③ 内容审核（M1 本地规则四闸 + C4 云端审核 TMS：03 Tab③ v1.3 同款配置模式）
	 * ------------------------------------------------------------------ */

	private static function render_tab_review(): void {
		$settings = self::settings();
		$local    = $settings['review']['local'];
		$cloud    = $settings['review']['cloud'];
		$tms      = is_array( $cloud['tencent_tms'] ?? null ) ? $cloud['tencent_tms'] : array();
		$green    = is_array( $cloud['aliyun_green'] ?? null ) ? $cloud['aliyun_green'] : array();
		$baidu    = is_array( $cloud['baidu'] ?? null ) ? $cloud['baidu'] : array(); // 【v1.5·B6】百度文本审核子树。
		$deepseek = is_array( $cloud['deepseek'] ?? null ) ? $cloud['deepseek'] : array(); // 【M5·U4】DeepSeek 子树。
		$openai_custom = is_array( $cloud['openai_custom'] ?? null ) ? $cloud['openai_custom'] : array(); // 【M6·批E】openai_custom 子树（8 键 schema 既有）。
		$chain    = is_array( $cloud['chain'] ?? null ) ? $cloud['chain'] : array();
		$tms_in_chain   = in_array( 'tencent_tms', $chain, true );
		$green_in_chain = in_array( 'aliyun_green', $chain, true );
		$baidu_in_chain = in_array( 'baidu', $chain, true );
		$deepseek_in_chain = in_array( 'deepseek', $chain, true ); // 【M5·U4】.
		$openai_custom_in_chain = in_array( 'openai_custom', $chain, true ); // 【M6·批E】.

		// 【v1.4·R4】DriverConfigSpec 通用件消费：registry 已实装 review 驱动（local_rules 剔除）
		// + 运行时停转映射（下拉「配置错误」点）+ 链路汇总行素材。
		$cloud_registered = array();
		if ( null !== self::$driver_registry ) {
			$cloud_registered = self::$driver_registry->by_type( 'review' );
			unset( $cloud_registered['local_rules'] );
		}
		if ( array() === $cloud_registered ) {
			// registry 未注入的降级读取（单测/早载场景）——已实装四驱动硬编码兜底。
			$cloud_registered = array(
				TencentTmsDriver::DRIVER_ID        => TencentTmsDriver::registry_entry(),
				AliyunGreenPlusDriver::DRIVER_ID   => AliyunGreenPlusDriver::registry_entry(),
				BaiduTextModerationDriver::DRIVER_ID => BaiduTextModerationDriver::registry_entry(),
				\WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver::DRIVER_ID => \WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver::registry_entry(), // 【M5·U4】.
				\WinkGatekeeper\Drivers\Review\OpenAiCustomReviewDriver::DRIVER_ID => \WinkGatekeeper\Drivers\Review\OpenAiCustomReviewDriver::registry_entry(), // 【M6·批E】.
			);
		}
		$cloud_halted = array();
		foreach ( array_keys( $cloud_registered ) as $registered_id ) {
			$halt_state = DriverHealth::state_today( (string) $registered_id );
			if ( null !== $halt_state ) {
				$cloud_halted[ (string) $registered_id ] = $halt_state;
			}
		}

		// 各驱动设置子树（下拉状态点/服务端黄条/链路汇总共用素材）。
		$cloud_configs = array(
			'tencent_tms'  => $tms,
			'aliyun_green' => $green,
			'baidu'        => $baidu,
			'deepseek'     => $deepseek, // 【M5·U4】.
			'openai_custom' => $openai_custom, // 【M6·批E】.
		);

		// 当前选中驱动（下拉=chain[0] 可视面；未配置=''）。
		$cloud_selected = $tms_in_chain ? 'tencent_tms' : ( $green_in_chain ? 'aliyun_green' : ( $baidu_in_chain ? 'baidu' : ( $deepseek_in_chain ? 'deepseek' : ( $openai_custom_in_chain ? 'openai_custom' : '' ) ) ) );

		// 【M4UX UI-1-2·定稿 §3】页顶「云端审核未配置未开启」notice（判定法与 Tab① 引导
		// 同源：enabled 关 + chain 空=未配置未开启；配好即消失。enabled 开但无可用驱动的
		// 形态已由 Tab① 通栏④兜底，不在此重复）。
		if ( empty( $cloud['enabled'] ) && array() === $chain ) {
			?>
			<div class="notice notice-warning inline" id="wk-review-onboarding-notice"><p>
				<strong><?php esc_html_e( '云端审核还没配置，也没开启', 'wink-gatekeeper' ); ?></strong>
				——<?php esc_html_e( '评论目前只走本地规则闸（字数/中文/禁词）；想加云端送审，请在下方「云端审核」卡开启并选择一家服务商。', 'wink-gatekeeper' ); ?>
				<a href="#wk-review-cloud-card"><?php esc_html_e( '去配置', 'wink-gatekeeper' ); ?></a>
			</p></div>
			<?php
		}

		// 服务端黄条（双层校验第二层——DriverConfigSpec 文案参数化版）。
		$cloud_notice = null;
		if ( '' !== $cloud_selected && isset( $cloud_registered[ $cloud_selected ] ) ) {
			$cloud_notice = DriverConfigSpec::server_notice(
				$cloud_selected,
				$cloud_registered[ $cloud_selected ],
				isset( $cloud_configs[ $cloud_selected ] ) ? $cloud_configs[ $cloud_selected ] : array(),
				'云端审核'
			);
		}
		if ( null !== $cloud_notice ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( $cloud_notice ) . '</strong></p></div>';
		}

		$wp_keys = get_option( 'disallowed_keys', '' );
		$wp_keys = is_string( $wp_keys ) && '' !== $wp_keys ? preg_split( '/\r\n|\r|\n/', $wp_keys ) : array();
		$wp_keys = array_values( array_filter( $wp_keys ) );
		?>
		<section class="wk-card">
			<h2><?php esc_html_e( '本地规则（四闸，上游迁移）', 'wink-gatekeeper' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-local-min"><?php esc_html_e( '字数下限', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-local-min" min="0" name="<?php echo esc_attr( self::field_name( array( 'review', 'local', 'min_length' ) ) ); ?>" value="<?php echo esc_attr( (string) $local['min_length'] ); ?>" class="small-text" />
						<span class="wk-muted"><?php esc_html_e( '字（0 = 不限）', 'wink-gatekeeper' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-local-max"><?php esc_html_e( '字数上限', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-local-max" min="0" name="<?php echo esc_attr( self::field_name( array( 'review', 'local', 'max_length' ) ) ); ?>" value="<?php echo esc_attr( (string) $local['max_length'] ); ?>" class="small-text" />
						<span class="wk-muted"><?php esc_html_e( '字（默认 1800）', 'wink-gatekeeper' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '要求包含中文', 'wink-gatekeeper' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'review', 'local', 'require_chinese' ) ) ); ?>" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'review', 'local', 'require_chinese' ) ) ); ?>" value="1" <?php checked( ! empty( $local['require_chinese'] ) ); ?> />
							<?php esc_html_e( '无中文评论拦截', 'wink-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( '评论正文不含任何中文字符时拦截（上游四闸之三）', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-local-words"><?php esc_html_e( '自定义禁止关键词', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<textarea id="wk-local-words" class="large-text" rows="5" name="<?php echo esc_attr( self::field_name( array( 'review', 'local', 'custom_banned_words' ) ) ); ?>" placeholder="<?php esc_attr_e( "每行一个；/正则/flags 形态按正则匹配，如 /代.{0,3}开发票/i", 'wink-gatekeeper' ); ?>"><?php echo esc_textarea( (string) $local['custom_banned_words'] ); ?></textarea>
						<p class="description"><?php esc_html_e( '非法正则行自动跳过并记入事件（不影响其它行）。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '联动 WP 讨论设置关键词', 'wink-gatekeeper' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'review', 'local', 'use_wp_keywords' ) ) ); ?>" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'review', 'local', 'use_wp_keywords' ) ) ); ?>" value="1" <?php checked( ! empty( $local['use_wp_keywords'] ) ); ?> />
							<?php esc_html_e( '合并「设置 → 讨论 → 被禁止的关键词」', 'wink-gatekeeper' ); ?>
						</label>
						<?php if ( array() !== $wp_keys ) : ?>
							<p class="description">
								<?php
								echo esc_html( sprintf( /* translators: %s: 关键词数 */ __( '当前 %s 条：', 'wink-gatekeeper' ), number_format_i18n( count( $wp_keys ) ) ) );
								echo esc_html( implode( '、', array_slice( $wp_keys, 0, 10 ) ) );
								echo count( $wp_keys ) > 10 ? esc_html( '…' ) : '';
								?>
								<a href="<?php echo esc_url( admin_url( 'options-discussion.php' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '管理', 'wink-gatekeeper' ); ?></a>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<?php
				/* 【M4UX UI-1-3·定稿 §3】WP 原生讨论两开关只读状态行——查询 option 仅展示
				 * 不改行为（红线：不进本插件表单字段）；管理入口=设置 → 讨论。
				 * 【M4UX F3-5】条件展示三态：两开关任一为是（会阻碍插件自动审核）→ 渲染行
				 * 并以 warning notice 呈现；都为否 → 整行不渲染（零 DOM）。 */
				$wp_moderation = (bool) get_option( 'comment_moderation', 0 );
				$wp_whitelist  = (bool) get_option( 'comment_whitelist', 0 );
				$wp_switch_state = static function ( bool $on ): string {
					return $on
						? __( '已开启', 'wink-gatekeeper' )
						: __( '未开启', 'wink-gatekeeper' );
				};
				?>
				<?php if ( $wp_moderation || $wp_whitelist ) : ?>
				<tr id="wk-review-wp-switches">
					<th scope="row"><?php esc_html_e( 'WP 原生两开关（只读展示）', 'wink-gatekeeper' ); ?></th>
					<td>
						<div class="notice notice-warning inline">
						<p class="description">
							<?php esc_html_e( '「评论必须手动批准」（设置 → 讨论）：', 'wink-gatekeeper' ); ?>
							<strong><?php echo esc_html( $wp_switch_state( $wp_moderation ) ); ?></strong><?php echo $wp_moderation ? '——' . esc_html__( '已开启：即使插件审核通过，评论仍需你在 WP 侧人工批准后才会显示。', 'wink-gatekeeper' ) : ''; ?>
						</p>
						<p class="description">
							<?php esc_html_e( '「作者须有先前已批准的评论」：', 'wink-gatekeeper' ); ?>
							<strong><?php echo esc_html( $wp_switch_state( $wp_whitelist ) ); ?></strong><?php echo $wp_whitelist ? '——' . esc_html__( '已开启：即使插件审核通过，没有批准记录的老作者评论仍需人工批准。', 'wink-gatekeeper' ) : ''; ?>
						</p>
						<p class="description">
							<?php esc_html_e( '本插件只读展示、不改这两个开关的行为；如需调整请到', 'wink-gatekeeper' ); ?>
							<a href="<?php echo esc_url( admin_url( 'options-discussion.php' ) ); ?>"><?php esc_html_e( '设置 → 讨论', 'wink-gatekeeper' ); ?></a>
						</p>
						</div>
					</td>
				</tr>
				<?php endif; ?>
			</table>
		</section>
		<section class="wk-card" id="wk-review-cloud-card">
			<h2><?php esc_html_e( '云端审核', 'wink-gatekeeper' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '启用云端审核', 'wink-gatekeeper' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'enabled' ) ) ); ?>" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'enabled' ) ) ); ?>" value="1" <?php checked( ! empty( $cloud['enabled'] ) ); ?> />
							<?php esc_html_e( '开启后按「本地规则 → 已选云端驱动」串联送审（failover 链）', 'wink-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( '本地规则恒在链首且不可关闭；某驱动故障时自动接力下一驱动，云端全部故障时按各驱动「故障降级」档处理（默认入待审）。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-cloud-driver"><?php esc_html_e( '当前云端审核驱动', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<?php
						/* 【v1.4·反馈7+澄清B/R4 §A】下拉单选（=chain[0] 可视面）：
						 * hidden 伴随域 chain[]='' + select chain[]=<id>——sanitize text_list 剔除空项后
						 * 收口为单元素数组（选「未配置」→ [] 空 chain）。选项文字后缀状态点
						 * （registry settings.required 派生：已配置/未配置/配置错误——DriverHealth 运行时态）。
						 * 「串接顺序（拖拽）」UI 删除（澄清 B：首发单主驱动从简）；chain 有序数组 schema
						 * 保留兼容未来 failover 备用链（R4 附录 A-1）。 */
						$cloud_options = DriverConfigSpec::driver_options(
							$cloud_registered,
							$cloud_configs,
							self::future_review_drivers(),
							$cloud_halted
						);
						$cloud_dots     = DriverConfigSpec::dot_labels();
						?>
						<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'chain' ) ) ); ?>[]" value="" />
						<label for="wk-cloud-driver" class="screen-reader-text"><?php esc_html_e( '云端审核驱动', 'wink-gatekeeper' ); ?></label>
						<select id="wk-cloud-driver" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'chain' ) ) ); ?>[]">
							<?php foreach ( $cloud_options as $cloud_option ) : ?>
								<option value="<?php echo esc_attr( $cloud_option['value'] ); ?>" <?php selected( $cloud_option['value'], $cloud_selected ); ?>>
									<?php
									$cloud_text = $cloud_option['label'];
									if ( isset( $cloud_option['dot'] ) ) {
										$cloud_text .= ' — ' . $cloud_dots[ $cloud_option['dot'] ];
									}
									echo esc_html( $cloud_text );
									?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( '选择驱动后下方显示其配置区（各厂商配置独立保存、切换不清空已填）；「未配置」= 云端审核阶段跳过（enabled 开 + 未配置 = 与 Tab② 语义对齐）。DeepSeek 已实装；模力为后续里程碑驱动。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
			</table>
		</section>
		<?php
		/* 【v1.4·反馈9/R4 §C】v1.2「顶部集中 PIPL 黄条」作废——合规行迁入各驱动配置栏
		 * （registry privacy 维渲染，禁 SettingsPage 硬编码合规文案）；集中位降级为链路汇总行（底部）。
		 * 【FIX-3·2026-11-11】三厂商配置区并列渲染（对齐 Tab② data-wk-vendor 形态）：互斥 if/elseif
		 * 分支作废（病株：只渲染 chain[0] 一个区，下拉切换无区可显）——三区恒渲染，PHP 初始
		 * hidden 按 $cloud_selected（chain[0]）；前端 admin-brand.js bindVendorSwitcher 监听
		 * #wk-cloud-driver 切换显隐（各厂商 schema 子树独立保存、切换不清空已填）；
		 * 「未配置」= 三区全 hidden + 说明行 #wk-review-unconfigured-note。 */
		$tms_meta      = isset( $cloud_registered['tencent_tms'] ) ? $cloud_registered['tencent_tms'] : TencentTmsDriver::registry_entry();
		$tms_privacy   = DriverConfigSpec::privacy_notice( $tms_meta, '腾讯云 TMS 文本审核' );
		$green_meta    = isset( $cloud_registered['aliyun_green'] ) ? $cloud_registered['aliyun_green'] : AliyunGreenPlusDriver::registry_entry();
		$green_privacy = DriverConfigSpec::privacy_notice( $green_meta, '阿里云内容安全·文本审核增强版 PLUS' );
		$baidu_meta    = isset( $cloud_registered['baidu'] ) ? $cloud_registered['baidu'] : BaiduTextModerationDriver::registry_entry();
		$baidu_privacy = DriverConfigSpec::privacy_notice( $baidu_meta, '百度文本审核' );
		?>
		<section class="wk-card" id="wk-tms-config-area" data-wk-vendor="tencent_tms"<?php echo ( 'tencent_tms' === $cloud_selected ) ? '' : ' hidden'; ?>>
			<h2><?php esc_html_e( '腾讯云 TMS 文本审核 · 配置', 'wink-gatekeeper' ); ?></h2>

			<?php if ( null !== $tms_privacy ) : ?>
				<div class="notice <?php echo 'warn' === $tms_privacy['level'] ? 'notice-warning inline' : 'notice-info inline'; ?>">
					<p><?php echo esc_html( $tms_privacy['text'] ); ?></p>
				</div>
			<?php endif; ?>

			<details class="wk-guide">
				<summary><?php esc_html_e( '接入指引（首次配置请展开）', 'wink-gatekeeper' ); ?></summary>
				<ol>
					<li><?php esc_html_e( '腾讯云控制台开通「内容安全 · 文本审核」并购买/领取套餐包（TextModeration 按策略计费）。', 'wink-gatekeeper' ); ?></li>
					<li><?php esc_html_e( '内容安全控制台获取策略编号（BizType，如 TencentCloudDefault 或自定义策略）——建议填写绑定套餐包的策略。', 'wink-gatekeeper' ); ?></li>
					<li><?php esc_html_e( '准备 CAM 密钥对（访问管理 → API 密钥管理，与验证码的 CAM 密钥同体系，可复用同对）。', 'wink-gatekeeper' ); ?></li>
					<li><?php esc_html_e( '粘贴至下方 → 点「测试凭据」（正常文本实测应返回 Pass）。', 'wink-gatekeeper' ); ?></li>
				</ol>
				<p><a href="https://cloud.tencent.com/document/api/1124/51860" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'TextModeration API 文档', 'wink-gatekeeper' ); ?></a></p>
			</details>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-tms-secret-id"><?php esc_html_e( 'SecretId（CAM）', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'review', 'cloud', 'tencent_tms', 'secret_id' ), '' !== (string) ( $tms['secret_id'] ?? '' ), 'SecretId（CAM）' ); ?>
						<p class="description"><?php esc_html_e( '云 API 密钥（TC3 签名用）。仅服务端使用，绝不出前端。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-tms-secret-key"><?php esc_html_e( 'SecretKey（CAM）', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'review', 'cloud', 'tencent_tms', 'secret_key' ), '' !== (string) ( $tms['secret_key'] ?? '' ), 'SecretKey（CAM）' ); ?>
						<p class="description"><?php esc_html_e( '与 SecretId 配对的 CAM 密钥。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-tms-biz-type"><?php esc_html_e( 'BizType（策略编号）', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="text" id="wk-tms-biz-type" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'tencent_tms', 'biz_type' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $tms['biz_type'] ?? '' ) ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( '内容安全控制台的识别策略编号。官方参数可选（留空走默认策略）；建议填写绑定套餐包的策略，未开通对应策略时会收到「未获接口授权」。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-tms-reject-score"><?php esc_html_e( '直拒分数阈值', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-tms-reject-score" min="0" max="100" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'tencent_tms', 'reject_score' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $tms['reject_score'] ?? 80 ) ); ?>" class="small-text" />
						<span class="wk-muted"><?php esc_html_e( '（0-100，默认 80）', 'wink-gatekeeper' ); ?></span>
						<p class="description"><?php esc_html_e( '腾讯判定 Block 且分数 ≥ 此值才直接拦截；低于阈值降为入待审（人工复核）。实测恶意广告样本 99 分，默认 80 留出灰区缓冲。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-tms-timeout"><?php esc_html_e( '超时', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-tms-timeout" min="1" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'tencent_tms', 'timeout' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $tms['timeout'] ?? 8 ) ); ?>" class="small-text" />
						<span class="wk-muted"><?php esc_html_e( '秒（默认 8）', 'wink-gatekeeper' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-tms-quota"><?php esc_html_e( '日配额', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-tms-quota" min="0" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'tencent_tms', 'daily_quota' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $tms['daily_quota'] ?? 500 ) ); ?>" class="small-text" />
						<span class="wk-muted"><?php esc_html_e( '次/天（0 = 不限）——达到后当日停用并按「故障降级」处理', 'wink-gatekeeper' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '故障降级', 'wink-gatekeeper' ); ?></th>
					<td>
						<?php $fail_mode = 'open' === ( $tms['fail_mode'] ?? 'review' ) ? 'open' : 'review'; ?>
						<label style="display:block;margin-bottom:6px;">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'tencent_tms', 'fail_mode' ) ) ); ?>" value="review" <?php checked( 'review', $fail_mode ); ?> />
							<?php esc_html_e( '入待审（默认）——云端故障/配额尽时评论进待审队列，站长稍后处理', 'wink-gatekeeper' ); ?>
						</label>
						<label style="display:block;">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'tencent_tms', 'fail_mode' ) ) ); ?>" value="open" <?php checked( 'open', $fail_mode ); ?> />
							<?php esc_html_e( '放行——故障时不拦不待审（仅本地规则生效）', 'wink-gatekeeper' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<p>
				<button type="button" class="button" id="wk-tms-test-credentials"
					data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX_TEST_CREDENTIALS_TMS ) ); ?>">
					<?php esc_html_e( '测试凭据', 'wink-gatekeeper' ); ?>
				</button>
				<span id="wk-tms-test-result" aria-live="polite"></span>
			</p>
			<p class="description"><?php esc_html_e( '发一次正常文本实测：绿 = 凭据可用（顺带解除当日停转）；红 = 密钥错或未开通套餐包；黄 = 参数错。结果不落库。', 'wink-gatekeeper' ); ?></p>
		</section>

		<section class="wk-card" id="wk-green-config-area" data-wk-vendor="aliyun_green"<?php echo ( 'aliyun_green' === $cloud_selected ) ? '' : ' hidden'; ?>>
			<h2><?php esc_html_e( '阿里云内容安全·文本审核增强版 PLUS · 配置', 'wink-gatekeeper' ); ?></h2>

			<?php if ( null !== $green_privacy ) : ?>
				<div class="notice <?php echo 'warn' === $green_privacy['level'] ? 'notice-warning inline' : 'notice-info inline'; ?>">
					<p><?php echo esc_html( $green_privacy['text'] ); ?></p>
				</div>
			<?php endif; ?>

			<details class="wk-guide">
				<summary><?php esc_html_e( '接入指引（首次配置请展开）', 'wink-gatekeeper' ); ?></summary>
				<ol>
					<li><?php esc_html_e( '阿里云控制台开通「内容安全 · 文本审核增强版」（TextModerationPlus，Green 2022-03-02）。', 'wink-gatekeeper' ); ?></li>
					<li><?php esc_html_e( '准备 RAM AccessKey 对（可与验证码 2.0 共用同一 RAM 用户），并授予 AliyunYundunGreenWebFullAccess 权限。', 'wink-gatekeeper' ); ?></li>
					<li><?php esc_html_e( '粘贴至下方（双 Service 名默认 nickname/comment_detection_pro，一般无需修改）→ 点「测试凭据」。', 'wink-gatekeeper' ); ?></li>
					<li><?php esc_html_e( '判定映射（站长不可调）：RiskLevel none/low→放行、medium→入待审、high→直接拦截；分档界线在阿里控制台规则配置。如需 medium/low 中间态与小数置信分，请在内容安全控制台规则配置中开启细粒度检测。', 'wink-gatekeeper' ); ?></li>
				</ol>
				<p><a href="https://help.aliyun.com/zh/green/developer-reference/api-green-2022-03-02-textmoderationplus" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'TextModerationPlus API 文档', 'wink-gatekeeper' ); ?></a></p>
			</details>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-green-ak-id"><?php esc_html_e( 'AccessKeyId', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'review', 'cloud', 'aliyun_green', 'access_key_id' ), '' !== (string) ( $green['access_key_id'] ?? '' ), 'AccessKeyId' ); ?>
						<p class="description"><?php esc_html_e( 'RAM AccessKey（ACS3 签名用）。仅服务端使用，绝不出前端。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-green-ak-secret"><?php esc_html_e( 'AccessKeySecret', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'review', 'cloud', 'aliyun_green', 'access_key_secret' ), '' !== (string) ( $green['access_key_secret'] ?? '' ), 'AccessKeySecret' ); ?>
						<p class="description"><?php esc_html_e( '与 AccessKeyId 配对的密钥。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-green-service-nickname"><?php esc_html_e( '昵称 Service 名', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="text" id="wk-green-service-nickname" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'aliyun_green', 'service_nickname' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $green['service_nickname'] ?? 'nickname_detection_pro' ) ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( '昵称分送通道（默认 nickname_detection_pro）。昵称特化模型对短广告串命中更细；一般无需修改。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-green-service-comment"><?php esc_html_e( '正文 Service 名', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="text" id="wk-green-service-comment" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'aliyun_green', 'service_comment' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $green['service_comment'] ?? 'comment_detection_pro' ) ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( '邮箱+正文送审通道（默认 comment_detection_pro）。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '双 Service 分送', 'wink-gatekeeper' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'aliyun_green', 'dual_dispatch' ) ) ); ?>" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'aliyun_green', 'dual_dispatch' ) ) ); ?>" value="1" <?php checked( ! isset( $green['dual_dispatch'] ) || ! empty( $green['dual_dispatch'] ) ); ?> />
							<?php esc_html_e( '昵称单独一调 + 邮箱/正文一调（默认，精度更好，成本 ×2 可忽略）', 'wink-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( '关闭 = 兼容回退：按「昵称\\n邮箱\\n正文」拼接串单调一次（与其他云驱动同口径）。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-green-timeout"><?php esc_html_e( '超时', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-green-timeout" min="1" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'aliyun_green', 'timeout' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $green['timeout'] ?? 8 ) ); ?>" class="small-text" />
						<span class="wk-muted"><?php esc_html_e( '秒（默认 8）', 'wink-gatekeeper' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-green-quota"><?php esc_html_e( '日配额', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-green-quota" min="0" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'aliyun_green', 'daily_quota' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $green['daily_quota'] ?? 500 ) ); ?>" class="small-text" />
						<span class="wk-muted"><?php esc_html_e( '次/天（0 = 不限）——达到后当日停用并按「故障降级」处理；双送按两次调用计量', 'wink-gatekeeper' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '故障降级', 'wink-gatekeeper' ); ?></th>
					<td>
						<?php $green_fail_mode = 'open' === ( $green['fail_mode'] ?? 'review' ) ? 'open' : 'review'; ?>
						<label style="display:block;margin-bottom:6px;">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'aliyun_green', 'fail_mode' ) ) ); ?>" value="review" <?php checked( 'review', $green_fail_mode ); ?> />
							<?php esc_html_e( '入待审（默认）——云端故障/配额尽时评论进待审队列，站长稍后处理', 'wink-gatekeeper' ); ?>
						</label>
						<label style="display:block;">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'aliyun_green', 'fail_mode' ) ) ); ?>" value="open" <?php checked( 'open', $green_fail_mode ); ?> />
							<?php esc_html_e( '放行——故障时不拦不待审（仅本地规则生效）', 'wink-gatekeeper' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<p>
				<button type="button" class="button" id="wk-green-test-credentials"
					data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX_TEST_CREDENTIALS_ALIYUN_GREEN ) ); ?>">
					<?php esc_html_e( '测试凭据', 'wink-gatekeeper' ); ?>
				</button>
				<span id="wk-green-test-result" aria-live="polite"></span>
			</p>
			<p class="description"><?php esc_html_e( '发一次正常短文本实测：绿 = 凭据可用（顺带解除当日停转）；红 = 未授权/欠费/未开通（Code=408）或密钥/签名错；黄 = 参数错或必填缺。结果不落库。', 'wink-gatekeeper' ); ?></p>
		</section>
		<section class="wk-card" id="wk-baidu-config-area" data-wk-vendor="baidu"<?php echo ( 'baidu' === $cloud_selected ) ? '' : ' hidden'; ?>>
			<h2><?php esc_html_e( '百度文本审核 · 配置', 'wink-gatekeeper' ); ?></h2>

			<?php if ( null !== $baidu_privacy ) : ?>
				<div class="notice <?php echo 'warn' === $baidu_privacy['level'] ? 'notice-warning inline' : 'notice-info inline'; ?>">
					<p><?php echo esc_html( $baidu_privacy['text'] ); ?></p>
				</div>
			<?php endif; ?>

			<details class="wk-guide">
				<summary><?php esc_html_e( '接入指引（首次配置请展开）', 'wink-gatekeeper' ); ?></summary>
				<ol>
					<li><?php esc_html_e( '百度智能云控制台开通「内容审核 · 文本审核」（现行 v2 端点，文本审核按量计费）。', 'wink-gatekeeper' ); ?></li>
					<li><?php esc_html_e( '控制台「应用列表」创建应用获取 API Key 与 Secret Key（AppID 仅控制台标识，不参与请求）。', 'wink-gatekeeper' ); ?></li>
					<li><?php esc_html_e( '粘贴至下方 → 点「测试凭据」（正常文本实测应返回合规）。', 'wink-gatekeeper' ); ?></li>
					<li><?php esc_html_e( '判定映射（站长不可调）：conclusionType 1 合规→放行、2 不合规→直接拦截、3 疑似→入待审；access_token 由插件缓存复用（30 天有效期，无需手动管理）。', 'wink-gatekeeper' ); ?></li>
				</ol>
				<p><a href="https://cloud.baidu.com/doc/ANTIPORN/s/Rk3h6xb3i" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '文本审核 v2 API 文档', 'wink-gatekeeper' ); ?></a></p>
			</details>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-baidu-api-key"><?php esc_html_e( 'API Key', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'review', 'cloud', 'baidu', 'api_key' ), '' !== (string) ( $baidu['api_key'] ?? '' ), 'API Key' ); ?>
						<p class="description"><?php esc_html_e( '百度智能云应用 API Key（client_credentials 鉴权用）。仅服务端使用，绝不出前端。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-baidu-secret-key"><?php esc_html_e( 'Secret Key', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'review', 'cloud', 'baidu', 'secret_key' ), '' !== (string) ( $baidu['secret_key'] ?? '' ), 'Secret Key' ); ?>
						<p class="description"><?php esc_html_e( '与 API Key 配对的 Secret Key（access_token 有效期 30 天，由插件缓存复用）。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-baidu-timeout"><?php esc_html_e( '超时', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-baidu-timeout" min="1" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'baidu', 'timeout' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $baidu['timeout'] ?? 8 ) ); ?>" class="small-text" />
						<span class="wk-muted"><?php esc_html_e( '秒（默认 8）', 'wink-gatekeeper' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-baidu-quota"><?php esc_html_e( '日配额', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-baidu-quota" min="0" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'baidu', 'daily_quota' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $baidu['daily_quota'] ?? 500 ) ); ?>" class="small-text" />
						<span class="wk-muted"><?php esc_html_e( '次/天（0 = 不限）——达到后当日停用并按「故障降级」处理（计费防呆，立项书 §6.4）', 'wink-gatekeeper' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '故障降级', 'wink-gatekeeper' ); ?></th>
					<td>
						<?php $baidu_fail_mode = 'open' === ( $baidu['fail_mode'] ?? 'review' ) ? 'open' : 'review'; ?>
						<label style="display:block;margin-bottom:6px;">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'baidu', 'fail_mode' ) ) ); ?>" value="review" <?php checked( 'review', $baidu_fail_mode ); ?> />
							<?php esc_html_e( '入待审（默认）——云端故障/配额尽时评论进待审队列，站长稍后处理', 'wink-gatekeeper' ); ?>
						</label>
						<label style="display:block;">
							<input type="radio" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'baidu', 'fail_mode' ) ) ); ?>" value="open" <?php checked( 'open', $baidu_fail_mode ); ?> />
							<?php esc_html_e( '放行——故障时不拦不待审（仅本地规则生效）', 'wink-gatekeeper' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<p>
				<button type="button" class="button" id="wk-baidu-test-credentials"
					data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX_TEST_CREDENTIALS_BAIDU ) ); ?>">
					<?php esc_html_e( '测试凭据', 'wink-gatekeeper' ); ?>
				</button>
				<span id="wk-baidu-test-result" aria-live="polite"></span>
			</p>
			<p class="description"><?php esc_html_e( '发一次正常文本实测：绿 = 凭据可用（顺带解除当日停转）；红 = 鉴权失败（API Key/Secret Key 错或未开通服务）；黄 = 参数错或必填缺。结果不落库。', 'wink-gatekeeper' ); ?></p>
		</section>

		<section class="wk-card" id="wk-review-deepseek-card" data-wk-vendor="deepseek"<?php echo ( 'deepseek' === $cloud_selected ) ? '' : ' hidden'; ?>>
			<h2><?php esc_html_e( 'DeepSeek（AI 判定） · 配置', 'wink-gatekeeper' ); ?></h2>

			<?php
			$deepseek_meta    = isset( $cloud_registered['deepseek'] ) ? $cloud_registered['deepseek'] : \WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver::registry_entry();
			$deepseek_privacy = DriverConfigSpec::privacy_notice( $deepseek_meta, 'DeepSeek' );
			if ( null !== $deepseek_privacy ) :
				?>
				<div class="notice <?php echo 'warn' === $deepseek_privacy['level'] ? 'notice-warning inline' : 'notice-info inline'; ?>">
					<p><?php echo esc_html( $deepseek_privacy['text'] ); ?></p>
				</div>
			<?php endif; ?>

			<details class="wk-guide">
				<summary><?php esc_html_e( '接入指引（首次配置请展开）', 'wink-gatekeeper' ); ?></summary>
				<ol>
					<li><?php esc_html_e( 'DeepSeek 开放平台（platform.deepseek.com）创建 API Key 并充值（按量计费，判定一次约几百 token）。', 'wink-gatekeeper' ); ?></li>
					<li><?php esc_html_e( '粘贴 API Key 至下方 → 点「测试凭据」（余额探测，零费用——顺带看到余额数字）。', 'wink-gatekeeper' ); ?></li>
					<li><?php esc_html_e( '「低置信自动升级」默认开——说明见下方开关行（段级一次）。', 'wink-gatekeeper' ); ?></li>
					<li><?php esc_html_e( '判定映射（站长不可调）：置信 ≥0.8 高置信直取、<0.8 一律入待审；AI 判 pass 不替评论放行（只放大严）。', 'wink-gatekeeper' ); ?></li>
				</ol>
				<p><a href="https://api-docs.deepseek.com/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'DeepSeek API 文档', 'wink-gatekeeper' ); ?></a></p>
			</details>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-deepseek-api-key"><?php esc_html_e( 'API Key', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'review', 'cloud', 'deepseek', 'api_key' ), '' !== (string) ( $deepseek['api_key'] ?? '' ), 'API Key' ); ?>
						<p class="description"><?php esc_html_e( '仅服务端使用，绝不出前端。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-deepseek-base-url"><?php esc_html_e( '接入地址', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="url" id="wk-deepseek-base-url" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'deepseek', 'base_url' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $deepseek['base_url'] ?? 'https://api.deepseek.com' ) ); ?>" class="regular-text code">
						<p class="description"><?php esc_html_e( '预填官方地址，一般不用改。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-deepseek-model"><?php esc_html_e( '判定模型', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<select id="wk-deepseek-model" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'deepseek', 'model' ) ) ); ?>">
							<option value="deepseek-flash" <?php selected( (string) ( $deepseek['model'] ?? 'deepseek-flash' ), 'deepseek-flash' ); ?>><?php esc_html_e( '标准（deepseek-flash，便宜快）', 'wink-gatekeeper' ); ?></option>
							<option value="deepseek-reasoner" <?php selected( (string) ( $deepseek['model'] ?? '' ), 'deepseek-reasoner' ); ?>><?php esc_html_e( '深思（deepseek-reasoner，更强慢）', 'wink-gatekeeper' ); ?></option>
						</select>
					</td>
				</tr>
				<tr id="wk-deepseek-two-stage-row">
					<th scope="row"><?php esc_html_e( '低置信自动升级', 'wink-gatekeeper' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'two_stage' ) ) ); ?>" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'two_stage' ) ) ); ?>" value="1" <?php checked( ! empty( $cloud['two_stage'] ?? true ) ); ?>>
							<?php esc_html_e( 'AI 拿不准的评论，自动换更擅长的「深思」模型再判一次，每条评论最多判两次', 'wink-gatekeeper' ); ?>
						</label>
					</td>
				</tr>
				<tr id="wk-deepseek-upgrade-model-row"<?php echo ! empty( $cloud['two_stage'] ?? true ) ? '' : ' hidden'; ?>>
					<th scope="row"><label for="wk-deepseek-upgrade-model"><?php esc_html_e( '升级用模型', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<select id="wk-deepseek-upgrade-model" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'upgrade_model' ) ) ); ?>">
							<option value="deepseek-reasoner" <?php selected( (string) ( $cloud['upgrade_model'] ?? 'deepseek-reasoner' ), 'deepseek-reasoner' ); ?>><?php esc_html_e( '深思（deepseek-reasoner）', 'wink-gatekeeper' ); ?></option>
							<option value="deepseek-flash" <?php selected( (string) ( $cloud['upgrade_model'] ?? '' ), 'deepseek-flash' ); ?>><?php esc_html_e( '标准（deepseek-flash）', 'wink-gatekeeper' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '降级方式', 'wink-gatekeeper' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'deepseek', 'fail_mode' ) ) ); ?>">
							<option value="review" <?php selected( (string) ( $deepseek['fail_mode'] ?? 'review' ), 'review' ); ?>><?php esc_html_e( '出岔子时评论进待审区（推荐）', 'wink-gatekeeper' ); ?></option>
							<option value="open" <?php selected( (string) ( $deepseek['fail_mode'] ?? '' ), 'open' ); ?>><?php esc_html_e( '出岔子时照常放行（不推荐）', 'wink-gatekeeper' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( '超时 8 秒（防呆范围）；AI 判定只放大严：判 pass 也不会替评论放行。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr id="wk-deepseek-quota-line">
					<th scope="row"><?php esc_html_e( '今日判定额度', 'wink-gatekeeper' ); ?></th>
					<td>
						<?php
						$ds_usage  = \WinkGatekeeper\Stats\Usage::get();
						$ds_used   = \WinkGatekeeper\Stats\Usage::daily_total_for( $ds_usage, 'deepseek' );
						$ds_quota  = max( 0, (int) ( $deepseek['daily_quota'] ?? 200 ) );
						?>
						<input type="number" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'deepseek', 'daily_quota' ) ) ); ?>" value="<?php echo esc_attr( (string) $ds_quota ); ?>" class="small-text" min="0">
						<?php esc_html_e( '次（0=不限量）', 'wink-gatekeeper' ); ?>
						<p class="description"><?php printf( esc_html__( '今日已判定 %1$d 次 · 额度剩 %2$s 次。', 'wink-gatekeeper' ), (int) $ds_used, 0 === $ds_quota ? '∞' : (string) max( 0, $ds_quota - $ds_used ) ); ?></p>
					</td>
				</tr>
			</table>

			<p>
				<button type="button" class="button" id="wk-deepseek-test-btn"
					data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX_TEST_CREDENTIALS_DEEPSEEK ) ); ?>">
					<?php esc_html_e( '测试凭据', 'wink-gatekeeper' ); ?>
				</button>
				<span id="wk-deepseek-test-result" aria-live="polite"></span>
			</p>
			<p class="description"><?php esc_html_e( '余额探测（零费用）：绿 = 凭据有效且账户可用（显示余额）；黄 = 账户暂不可用（可能欠费）；红 = 密钥无效或连不上；黄 = API Key 未填。结果不落库。独立端点用模型列表探活（OpenAI 兼容、无标准余额 API）。', 'wink-gatekeeper' ); ?></p>
		</section>

		<?php
		/* 【M6·批E】Tab③ openai_custom 配置卡（设计 §2.1——deepseek 卡形态参照，差异：
		 * 接入地址空占位+路径前缀提示（站长全量配置无官方预填）；判定模型/升级用模型=
		 * 手填 text（泛端点无静态模型集）；测试凭据=/models 探活（泛端点无标准余额 API）。
		 * system_prompt/min_confidence 高级键不开 UI（deepseek 卡对称——两键保留
		 * sanitize/schema 通道，未来「高级折叠区」统一开）。 */
		$oc_meta    = isset( $cloud_registered['openai_custom'] ) ? $cloud_registered['openai_custom'] : \WinkGatekeeper\Drivers\Review\OpenAiCustomReviewDriver::registry_entry();
		$oc_privacy = DriverConfigSpec::privacy_notice( $oc_meta, '自定义 OpenAI 兼容端点' );
		?>
		<section class="wk-card" id="wk-review-openai-custom-card" data-wk-vendor="openai_custom"<?php echo ( 'openai_custom' === $cloud_selected ) ? '' : ' hidden'; ?>>
			<h2><?php esc_html_e( '自定义 OpenAI 兼容端点（AI 判定） · 配置', 'wink-gatekeeper' ); ?></h2>

			<?php if ( null !== $oc_privacy ) : ?>
				<div class="notice <?php echo 'warn' === $oc_privacy['level'] ? 'notice-warning inline' : 'notice-info inline'; ?>">
					<p><?php echo esc_html( $oc_privacy['text'] ); ?></p>
				</div>
			<?php endif; ?>

			<details class="wk-guide">
				<summary><?php esc_html_e( '接入指引（首次配置请展开）', 'wink-gatekeeper' ); ?></summary>
				<ol>
					<li><?php esc_html_e( '端点需提供 chat/completions（OpenAI 兼容）——中转站/自建网关（LiteLLM 类）/任意兼容厂商皆可。', 'wink-gatekeeper' ); ?></li>
					<li><?php esc_html_e( '填接入地址（注意路径前缀：插件在所填地址后拼 /chat/completions——OpenAI 官方族需填到 …/v1 为止）。', 'wink-gatekeeper' ); ?></li>
					<li><?php esc_html_e( '填 API Key 与模型名 → 点「测试凭据」（模型列表探活，零 token 消耗——泛端点无标准余额 API，不显示余额）。', 'wink-gatekeeper' ); ?></li>
					<li><?php esc_html_e( '可达性与合规由站长自负：数据送你填的端点所在辖区（不支持内网地址——传输脊私网拒绝红线优先）。', 'wink-gatekeeper' ); ?></li>
				</ol>
			</details>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-field-review-cloud-openai-custom-api_key"><?php esc_html_e( 'API Key', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'review', 'cloud', 'openai_custom', 'api_key' ), '' !== (string) ( $openai_custom['api_key'] ?? '' ), 'API Key' ); ?>
						<p class="description"><?php esc_html_e( '该端点签发的 API Key（中转站/网关各自发放）。仅服务端使用，绝不出前端。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-openai-custom-base-url"><?php esc_html_e( '接入地址', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="url" id="wk-openai-custom-base-url" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'openai_custom', 'base_url' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $openai_custom['base_url'] ?? '' ) ); ?>" class="regular-text code"
							placeholder="https://你的端点/v1" />
						<p class="description"><?php esc_html_e( '填你的 OpenAI 兼容端点根地址——插件在其后拼接 /chat/completions。OpenAI 官方族填到 https://…/v1 为止；DeepSeek 官方填 https://api.deepseek.com（不带 /v1）。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-openai-custom-model"><?php esc_html_e( '判定模型', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="text" id="wk-openai-custom-model" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'openai_custom', 'model' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $openai_custom['model'] ?? '' ) ); ?>" class="regular-text"
							placeholder="<?php esc_attr_e( '填该端点支持的模型名', 'wink-gatekeeper' ); ?>" />
						<p class="description"><?php esc_html_e( '模型名须与端点一致——点「测试凭据」可校验是否在可用列表（列表不含也可能仍可用，部分端点列表不全）。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr id="wk-openai-custom-two-stage-row">
					<th scope="row"><?php esc_html_e( '低置信自动升级', 'wink-gatekeeper' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'two_stage' ) ) ); ?>" value="0" />
							<input type="checkbox" id="wk-openai-custom-two-stage" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'two_stage' ) ) ); ?>" value="1" <?php checked( ! empty( $cloud['two_stage'] ?? true ) ); ?>>
							<?php esc_html_e( 'AI 拿不准的评论，自动换更强的模型再判一次，每条评论最多判两次', 'wink-gatekeeper' ); ?>
						</label>
					</td>
				</tr>
				<tr id="wk-openai-custom-upgrade-model-row"<?php echo ! empty( $cloud['two_stage'] ?? true ) ? '' : ' hidden'; ?>>
					<th scope="row"><label for="wk-openai-custom-upgrade-model"><?php esc_html_e( '升级用模型', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<?php /* 【M6·批E·R1 裁定】手填 text+留空短路（不走子树加键）：顶层
							upgrade_model 为 deepseek/openai_custom 共用键——从 DeepSeek 切来
							的残留值在此可见可清理（可见=可清理，不隐式吞）；留空=基类守卫
							短路两段式（不发起 r2 调用）。 */ ?>
						<input type="text" id="wk-openai-custom-upgrade-model" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'upgrade_model' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $cloud['upgrade_model'] ?? '' ) ); ?>" class="regular-text"
							placeholder="<?php esc_attr_e( '填端点支持的更强模型名；留空=不升级', 'wink-gatekeeper' ); ?>" />
						<p class="description"><?php esc_html_e( '与 DeepSeek 共用同一键：在 DeepSeek 用过的升级模型名会残留在这里——不用升级就清空（留空=不发起第二次判定）。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '降级方式', 'wink-gatekeeper' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'openai_custom', 'fail_mode' ) ) ); ?>">
							<option value="review" <?php selected( (string) ( $openai_custom['fail_mode'] ?? 'review' ), 'review' ); ?>><?php esc_html_e( '出岔子时评论进待审区（推荐）', 'wink-gatekeeper' ); ?></option>
							<option value="open" <?php selected( (string) ( $openai_custom['fail_mode'] ?? '' ), 'open' ); ?>><?php esc_html_e( '出岔子时照常放行（不推荐）', 'wink-gatekeeper' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( '超时默认 8 秒；AI 判定只放大严：判 pass 也不会替评论放行。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr id="wk-openai-custom-quota-line">
					<th scope="row"><?php esc_html_e( '今日判定额度', 'wink-gatekeeper' ); ?></th>
					<td>
						<?php
						$oc_usage = \WinkGatekeeper\Stats\Usage::get();
						$oc_used  = \WinkGatekeeper\Stats\Usage::daily_total_for( $oc_usage, 'openai_custom' );
						$oc_quota = max( 0, (int) ( $openai_custom['daily_quota'] ?? 200 ) );
						?>
						<input type="number" name="<?php echo esc_attr( self::field_name( array( 'review', 'cloud', 'openai_custom', 'daily_quota' ) ) ); ?>" value="<?php echo esc_attr( (string) $oc_quota ); ?>" class="small-text" min="0">
						<?php esc_html_e( '次（0=不限量）', 'wink-gatekeeper' ); ?>
						<p class="description"><?php printf( esc_html__( '今日已判定 %1$d 次 · 额度剩 %2$s 次。', 'wink-gatekeeper' ), (int) $oc_used, 0 === $oc_quota ? '∞' : (string) max( 0, $oc_quota - $oc_used ) ); ?></p>
					</td>
				</tr>
			</table>

			<p>
				<button type="button" class="button" id="wk-openai-custom-test-btn"
					data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX_TEST_CREDENTIALS_OPENAI_CUSTOM ) ); ?>">
					<?php esc_html_e( '测试凭据', 'wink-gatekeeper' ); ?>
				</button>
				<span id="wk-openai-custom-test-result" aria-live="polite"></span>
			</p>
			<p class="description"><?php esc_html_e( '模型列表探活（零 token）：绿 = 凭据可用（填了判定模型则顺带校验在不在列表）；黄 = 模型不在列表或必填缺；红 = 密钥被端点拒绝；灰 = 端点不提供 /models 或传输异常。泛端点无标准余额 API，不显示余额。结果不落库。', 'wink-gatekeeper' ); ?></p>
		</section>

		<?php self::render_url_audit_card( $settings ); ?>

		<!-- 【FIX-3】未配置空态说明行（对齐 Tab② unconfiguredNote 形态；JS 切换联动显隐）。 -->
		<p id="wk-review-unconfigured-note" class="description<?php echo '' !== $cloud_selected ? ' hidden' : ''; ?>">
			<span class="wk-dot wk-dot--alert" aria-hidden="true"></span>
			<?php esc_html_e( '未配置 = 云端审核阶段跳过（enabled 开 + 未配置 = 云端阶段跳过，与 Tab② 语义对齐）。选择驱动后下方显示对应厂商配置区；各厂商配置独立保存、切换不清空已填。', 'wink-gatekeeper' ); ?>
		</p>

			<?php
			/* 链路汇总行（03 Tab③ v1.4：链启用期间底部小字——任一配置区共用；链上任一驱动
			 * 出境/辖区未知时由消费方升级黄条（region_note 摘要已含「数据不出境/出境」短语）。 */
			$chain_summary = DriverConfigSpec::chain_summary( $chain, $cloud_registered, $cloud_configs );
			if ( null !== $chain_summary ) :
				$summary_warn = false;
				foreach ( $chain as $summary_id ) {
					if ( isset( $cloud_registered[ $summary_id ]['privacy']['cross_border'] )
						&& false !== $cloud_registered[ $summary_id ]['privacy']['cross_border'] ) {
						$summary_warn = true;
					}
				}
				?>
				<p class="description <?php echo $summary_warn ? 'wk-notice-inline-warn' : 'wk-muted'; ?>" style="margin-top:0.5rem;">
					<?php echo esc_html( $chain_summary ); ?>
				</p>
			<?php endif;
	}

	/* -------------------------------------------------------------------
	 * Tab④ IP 信誉——四分区全量（M3-IP 定稿 §6 + 03 Tab④【v1.7·M3-IP】回写版蓝本，
	 * I4 收官批；原 M0 占位三卡退役——950/fail_mode/M3 实装文案漂移一并修正）：
	 *   分区① 真实 IP 来源（network 子树 + 诊断面板嵌入位——I0 件原样挪入，行为零改动）
	 *   分区② IP 信誉查询（iprep/iprep.abuseipdb 子树 + 测试凭据 AJAX + 健康行 + 软顶黄条）
	 *   分区③ 上报（iprep.report 子树 T1–T4 + 出境黄条 + 只读闸参数 + 启用门禁状态行）
	 *   分区④ 说明卡（三态人话 + 信用免验联动 + 软顶人话句）
	 * 表单全走 register_setting 主保存通道（零新端点——除测试凭据 AJAX，§1 契约④）。
	 * ------------------------------------------------------------------ */

	private static function render_tab_iprep(): void {
		$settings = self::settings();
		self::render_iprep_zone_source( $settings );
		self::render_iprep_zone_check( $settings );
		self::render_iprep_zone_report( $settings );
		self::render_iprep_zone_notes( $settings );
	}

	/**
	 * 分区① 真实 IP 来源（03 Tab④【v1.7·M3-IP】：影响验证码核验/登录计数/IP 信誉/上报
	 * 全部 IP 链路故置顶）：获取模式下拉（direct/xff/traverse/custom_header+适用拓扑注记）+
	 * 自定义头名 + 预设选择器（Cloudflare 官方段+快照核对日期；EdgeOne 空预设 UI 隐藏——
	 * 定稿 §5.3，I0 行为保持）+ 受信网段 textarea + CIDR 前端校验按钮（零新端点纪律——
	 * 服务端解析仍以 TrustedProxies::parse_cidr 为准，非法行保存时被解析层跳过并在
	 * 诊断面板「自定义网段非法行」标出）+ 诊断面板嵌入位。
	 *
	 * @param array<string, mixed> $settings 设置树（merge_defaults 后全树）。
	 * @return void
	 */
	private static function render_iprep_zone_source( array $settings ): void {
		$network = isset( $settings['network'] ) && is_array( $settings['network'] ) ? $settings['network'] : array();
		$proxies = isset( $network['trusted_proxies'] ) && is_array( $network['trusted_proxies'] ) ? $network['trusted_proxies'] : array();
		$mode    = isset( $network['ip_source'] ) && is_string( $network['ip_source'] ) ? $network['ip_source'] : IpResolver::MODE_DIRECT;
		/* 【I5·定稿 §7】站长手动白名单（iprep.whitelist）——服务端行格式校验反馈：非法行
		 * 保存时被判定层忽略（TrustedProxies::parse_cidr 既有零件），此处按存量值即时标出。 */
		$whitelist_raw   = isset( $settings['iprep']['whitelist'] ) && is_string( $settings['iprep']['whitelist'] ) ? $settings['iprep']['whitelist'] : '';
		$whitelist_lines = AbuseIpdbService::whitelist_lines_from( $whitelist_raw );
		$whitelist_bad   = ( new TrustedProxies( $whitelist_lines ) )->invalid_lines();
		$modes   = array(
			IpResolver::MODE_DIRECT        => __( '直连 direct（默认）', 'wink-gatekeeper' ),
			IpResolver::MODE_XFF           => __( 'XFF 信任链（仅取最右）', 'wink-gatekeeper' ),
			IpResolver::MODE_TRAVERSE      => __( 'traverse 链式回溯（自建反代多级）', 'wink-gatekeeper' ),
			IpResolver::MODE_CUSTOM_HEADER => __( '自定义头 custom_header', 'wink-gatekeeper' ),
		);
		?>
		<section class="wk-card" id="wk-iprep-source">
			<h2 class="wk-sec-title"><?php esc_html_e( '真实 IP 来源', 'wink-gatekeeper' ); ?></h2>
			<p class="description wk-muted"><?php esc_html_e( '这里决定「访客的真实 IP 从哪读」——验证码核验、登录计数、IP 信誉与上报全部链路都以它为准。先确认下方诊断面板解析对了，再往下配。', 'wink-gatekeeper' ); ?></p>

			<?php self::render_ip_diagnostic_panel( $settings ); // 【I4】I0 件原样挪入分区①——行为零改动只挪渲染位置。 ?>

			<!-- 【M4UX G2】折叠区旁常显提示一行（用户原话整理）——引导核验后按需展开。 -->
			<p class="description wk-muted"><?php esc_html_e( '请核对当前 IP 是否与您的真实出口一致，不一致请展开配置。', 'wink-gatekeeper' ); ?></p>

			<!-- 【M4UX G2】模式选择/自定义头/预设选择器/受信网段/白名单/CIDR 校验收进「高级设置」
			 * 折叠（details/wk-guide F3-3 同款形态）——默认展开仅诊断面板（「当前生效」行+
			 * 「手动聚合查询工具」卡）。诊断面板的红标/黄条警示不折叠常显。 -->
			<details class="wk-guide" id="wk-iprep-source-advanced">
				<summary><?php esc_html_e( '高级设置（真实 IP 获取方式 / 自定义头 / 受信网段 / 白名单）', 'wink-gatekeeper' ); ?></summary>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-iprep-mode"><?php esc_html_e( '真实 IP 获取方式', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<select id="wk-iprep-mode" name="<?php echo esc_attr( self::field_name( array( 'network', 'ip_source' ) ) ); ?>">
							<?php foreach ( $modes as $mode_key => $mode_label ) : ?>
								<option value="<?php echo esc_attr( $mode_key ); ?>" <?php selected( $mode, $mode_key ); ?>><?php echo esc_html( $mode_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( '适用拓扑：站点直连源站→direct；Cloudflare 等 CDN 托管→custom_header（头名填 CF-Connecting-IP）；自建反代多级→traverse，并在受信网段填反代出口。', 'wink-gatekeeper' ); ?>
							<?php esc_html_e( 'traverse=REMOTE_ADDR 必须命中受信网段，再沿 XFF 从右往左跳过受信代理取第一个非受信 IP——不作默认：可直连源站的部署下回溯会把伪造链当真。', 'wink-gatekeeper' ); ?>
							<?php esc_html_e( 'REMOTE_ADDR 不在受信网段时，转发头一律忽略（防伪造不变式）。', 'wink-gatekeeper' ); ?>
						</p>
					</td>
				</tr>
				<tr id="wk-iprep-custom-header-row"<?php echo IpResolver::MODE_CUSTOM_HEADER !== $mode ? ' hidden' : ''; ?>>
					<!-- 【M4UX UI-2-5】仅 ip_source=custom_header 时显示（PHP 初始态 + admin-brand.js 联动，bindVendorSwitcher 同款显隐模式）。 -->
					<th scope="row"><label for="wk-iprep-custom-header"><?php esc_html_e( '自定义头名', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="text" id="wk-iprep-custom-header" class="regular-text" placeholder="CF-Connecting-IP"
							name="<?php echo esc_attr( self::field_name( array( 'network', 'custom_header' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $network['custom_header'] ?? '' ) ); ?>" />
						<p class="description"><?php esc_html_e( '真实 IP 获取方式=自定义头时生效（单值 IP 头——Cloudflare 官方头 CF-Connecting-IP 是权威覆写头，伪造面最小）。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '受信网段', 'wink-gatekeeper' ); ?></th>
					<td>
						<!-- 预设选择器（定稿 §5.3：EdgeOne 官方无静态网段列表——UI 隐藏，I0 行为保持）。 -->
						<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'network', 'trusted_proxies', 'cloudflare' ) ) ); ?>" value="0" />
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'network', 'trusted_proxies', 'cloudflare' ) ) ); ?>" value="1" <?php checked( ! empty( $proxies['cloudflare'] ) ); ?> />
							<?php
							printf(
								/* translators: 1: 快照核对日期 2: 网段条数 */
								esc_html__( '使用 Cloudflare 官方段（%1$s 核对，v4+v6 共 %2$d 条）', 'wink-gatekeeper' ),
								esc_html( (string) TrustedProxies::PRESET_VERIFIED ),
								(int) count( TrustedProxies::CLOUDFLARE_V4 ) + count( TrustedProxies::CLOUDFLARE_V6 )
							);
							?>
						</label>
						<p class="description"><?php esc_html_e( 'EdgeOne 无官方静态网段列表（api.edgeone.ai/ips 动态发布、3–6 月轮换——空预设入选=静默无保护），故不提供预设；托管在 EdgeOne 的站点请在下方手填官方文档公布的回源网段。', 'wink-gatekeeper' ); ?></p>
						<label class="screen-reader-text" for="wk-iprep-custom-cidr"><?php esc_html_e( '自定义受信网段', 'wink-gatekeeper' ); ?></label>
						<textarea id="wk-iprep-custom-cidr" class="large-text" rows="4" placeholder="203.0.113.0/24&#10;2001:db8::/32"
							name="<?php echo esc_attr( self::field_name( array( 'network', 'trusted_proxies', 'custom' ) ) ); ?>"><?php echo esc_textarea( (string) ( $proxies['custom'] ?? '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( '每行一条 CIDR（或裸 IP，v4/v6）——通常是反代/负载均衡的出口网段。', 'wink-gatekeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<!-- 【I5·定稿 §7 误伤防护·判定层】站长手动白名单——信誉判定豁免入口
						 （与受信网段是两个东西：受信网段管解析信任链，白名单管信誉豁免）。 -->
						<th scope="row"><label for="wk-iprep-whitelist"><?php esc_html_e( '白名单（信誉豁免）', 'wink-gatekeeper' ); ?></label></th>
						<td>
							<textarea id="wk-iprep-whitelist" class="large-text" rows="4" placeholder="8.8.8.8&#10;203.0.113.0/24&#10;2001:db8::/32"
								name="<?php echo esc_attr( self::field_name( array( 'iprep', 'whitelist' ) ) ); ?>"><?php echo esc_textarea( $whitelist_raw ); ?></textarea>
							<p class="description"><?php esc_html_e( '每行一个 IP 或 CIDR（v4/v6）——命中者永不查信誉、永不上报（判定与上报双通道同时豁免，零外呼）。', 'wink-gatekeeper' ); ?></p>
							<p class="description"><?php esc_html_e( '与「受信网段」的区别：受信网段管解析信任链（决定信谁报来的转发头），白名单管信誉豁免（决定谁永不查信誉）——两者互不替代。', 'wink-gatekeeper' ); ?></p>
							<p class="description"><?php esc_html_e( '误伤防护：搜索引擎爬虫、办公出口等被信誉库误伤的 IP 加在这里——访客直接放行，不会被强制过码。', 'wink-gatekeeper' ); ?></p>
							<?php if ( array() !== $whitelist_bad ) : ?>
								<p class="description">
									<span class="wk-dot wk-dot--alert" aria-hidden="true"></span>
									<?php
									printf(
										/* translators: 1: 非法行数 2: 逗号连接的非法行原文 */
										esc_html__( '白名单存在 %1$d 条非法行（保存时会被忽略，不生效）：%2$s', 'wink-gatekeeper' ),
										count( $whitelist_bad ),
										esc_html( implode( '、', $whitelist_bad ) )
									);
									?>
								</p>
							<?php endif; ?>
							<p>
								<button type="button" class="button" id="wk-iprep-whitelist-check"><?php esc_html_e( '校验白名单格式', 'wink-gatekeeper' ); ?></button>
								<span id="wk-iprep-whitelist-result" class="wk-muted" aria-live="polite"></span>
							</p>
						</td>
					</tr>
				</table>
			<p>
				<button type="button" class="button" id="wk-iprep-cidr-check"><?php esc_html_e( '校验网段格式', 'wink-gatekeeper' ); ?></button>
				<span id="wk-iprep-cidr-result" class="wk-muted" aria-live="polite"></span>
			</p>
			<p class="description"><?php esc_html_e( '校验为前端格式预检；非法行保存时由解析层跳过，并在上方诊断面板「自定义网段非法行」标出（校准工具：tools/api-smoke/refresh-presets.php）。', 'wink-gatekeeper' ); ?></p>
			</details>
		</section>
		<?php
	}

	/**
	 * 分区② IP 信誉查询（03 Tab④【v1.7·M3-IP】——三态语义权威=02 §2.3b 澄清 A）：
	 * enabled 开关+驱动下拉+api_key（password 型——P0-α 丢键教训）+阈值双滑杆（85/60）+
	 * 软顶 3000+日配额 4750+缓存双键（cache_minutes 在 abuseipdb 子树、negative_cache_minutes
	 * 在 iprep 顶层——实况键名，渲染读实况）+timeout（1–5s 防呆范围）+「测试凭据」按钮
	 * （AJAX 三态，O5-P2-6 判定法）+健康行（state_label 三态+DriverHealth 停转态）+
	 * 软顶命中黄条（【O5 回写·P2-4】）。
	 *
	 * @param array<string, mixed> $settings 设置树。
	 * @return void
	 */
	private static function render_iprep_zone_check( array $settings ): void {
		$iprep      = isset( $settings['iprep'] ) && is_array( $settings['iprep'] ) ? $settings['iprep'] : array();
		$abuse      = isset( $iprep['abuseipdb'] ) && is_array( $iprep['abuseipdb'] ) ? $iprep['abuseipdb'] : array();
		$soft_quota = max( 0, (int) ( $abuse['soft_quota'] ?? 3000 ) );
		$soft_hit   = $soft_quota > 0 && Usage::daily_total_for( Usage::get(), 'abuseipdb' ) >= $soft_quota;
		$halt       = DriverHealth::state_today( AbuseIpdbService::DRIVER_ID );
		$health     = null !== $halt
			? ( DriverHealth::STATE_QUOTA_EXHAUSTED === $halt
				? __( '额度用完（当日停转——次日自动恢复）', 'wink-gatekeeper' )
				: __( '配置问题（密钥被拒——当日停转；重存密钥并「测试凭据」绿态即解除）', 'wink-gatekeeper' ) )
			: __( '正常', 'wink-gatekeeper' );
		?>
		<section class="wk-card" id="wk-iprep-check">
			<h2 class="wk-sec-title"><?php esc_html_e( 'IP 信誉查询', 'wink-gatekeeper' ); ?></h2>
			<p class="description wk-muted"><?php esc_html_e( '启用后，评论与登录判定前会向 AbuseIPDB 查询访客 IP 的信誉分——恶意 IP 强制访客先过人机验证再放行。', 'wink-gatekeeper' ); ?></p>

			<?php if ( $soft_hit ) : ?>
				<div class="notice notice-warning inline"><p>
					<span class="wk-dot wk-dot--alert" aria-hidden="true"></span>
					<strong><?php esc_html_e( '当日查询配额已用尽——今日人机验证将临时对所有访客强制，次日自动恢复。', 'wink-gatekeeper' ); ?></strong>
					<?php esc_html_e( '软顶保护：当日查询计数达到软顶后不再外呼、一律按「不通过」从严处理——把最后配额留给缓冲，防信誉层对攻击者静默。', 'wink-gatekeeper' ); ?>
				</p></div>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '启用', 'wink-gatekeeper' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'iprep', 'enabled' ) ) ); ?>" value="0" />
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'iprep', 'enabled' ) ) ); ?>" value="1" <?php checked( ! empty( $iprep['enabled'] ) ); ?> />
							<?php esc_html_e( '启用 IP 信誉查询（同时提升评论闸与登录闸两处防护——Tab② 联动开关默认开）', 'wink-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( '未启用时信任链跳过该项，不影响信用免验资格与登录计数；启用后若配置异常/超时/报错/超阈值，将从严判定（按不通过处理，出码验证）。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-iprep-driver"><?php esc_html_e( '服务商', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<select id="wk-iprep-driver" name="<?php echo esc_attr( self::field_name( array( 'iprep', 'driver' ) ) ); ?>">
							<option value="abuseipdb" <?php selected( 'abuseipdb', (string) ( $iprep['driver'] ?? 'abuseipdb' ) ); ?>><?php esc_html_e( 'AbuseIPDB（IP 信誉查询）', 'wink-gatekeeper' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( '当前可用的 IP 信誉服务商（更多厂商随后续里程碑接入）。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-field-iprep-abuseipdb-api_key"><?php esc_html_e( 'API Key', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<?php self::password_field( array( 'iprep', 'abuseipdb', 'api_key' ), '' !== (string) ( $abuse['api_key'] ?? '' ), 'API Key' ); ?>
						<p class="description">
							<?php esc_html_e( 'AbuseIPDB 免费档（贡献者 5000 次/天）在 abuseipdb.com 注册获取。仅服务端使用，不回显已存值。', 'wink-gatekeeper' ); ?>
							<a href="https://www.abuseipdb.com/account/api" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '获取 API Key', 'wink-gatekeeper' ); ?></a>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '健康状态', 'wink-gatekeeper' ); ?></th>
					<td>
						<p class="description">
							<?php if ( null !== $halt ) : ?>
								<span class="wk-dot wk-dot--alert" aria-hidden="true"></span>
							<?php else : ?>
								<span class="wk-dot wk-dot--ok" aria-hidden="true"></span>
							<?php endif; ?>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: 停转态/正常 2: 装配态标签（未启用/已启用（AbuseIPDB）） */
									__( '%1$s · %2$s', 'wink-gatekeeper' ),
									$health,
									IpReputationService::state_label( $settings )
								)
							);
							?>
						</p>
					</td>
				</tr>
			</table>
			<!-- 【M4UX F3-3】阈值/软顶/日配额/缓存双键/超时收进「高级设置」折叠（details/wk-guide
			 * 既有形态）——默认展开仅 启用开关+服务商+凭据；软顶命中黄条在上方不折叠（警示常显）。 -->
			<details class="wk-guide" id="wk-iprep-advanced">
				<summary><?php esc_html_e( '高级设置（阈值 / 软顶 / 日配额 / 缓存 / 超时）', 'wink-gatekeeper' ); ?></summary>
				<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-iprep-malicious-score"><?php esc_html_e( '恶意阈值', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="range" id="wk-iprep-malicious-score" class="wk-score-slider" min="1" max="100" step="1"
							name="<?php echo esc_attr( self::field_name( array( 'iprep', 'abuseipdb', 'malicious_score' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $abuse['malicious_score'] ?? 85 ) ); ?>" />
						<output for="wk-iprep-malicious-score"><?php echo esc_html( (string) ( $abuse['malicious_score'] ?? 85 ) ); ?></output>
						<p class="description"><?php esc_html_e( '信誉分≥此值判恶意（默认 85）——强制访客过人机验证。AbuseIPDB 信誉分为多报告者聚合，单票不成黑 IP。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-iprep-suspect-score"><?php esc_html_e( '疑似阈值', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="range" id="wk-iprep-suspect-score" class="wk-score-slider" min="1" max="100" step="1"
							name="<?php echo esc_attr( self::field_name( array( 'iprep', 'abuseipdb', 'suspect_score' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $abuse['suspect_score'] ?? 60 ) ); ?>" />
						<output for="wk-iprep-suspect-score"><?php echo esc_html( (string) ( $abuse['suspect_score'] ?? 60 ) ); ?></output>
						<p class="description"><?php esc_html_e( '信誉分≥此值判可疑（默认 60）——与恶意阈值同为「不通过」档，强制验证码；两档差别仅在事件留档口径。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-iprep-soft-quota"><?php esc_html_e( '软顶', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-iprep-soft-quota" min="0" class="small-text"
							name="<?php echo esc_attr( self::field_name( array( 'iprep', 'abuseipdb', 'soft_quota' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $abuse['soft_quota'] ?? 3000 ) ); ?>" />
						<span class="wk-muted"><?php esc_html_e( '次/天（默认 3000；0=不限）——当日达到后不再外呼、一律按「不通过」从严处理（宁可从严不可静默放行）。', 'wink-gatekeeper' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-iprep-daily-quota"><?php esc_html_e( '日配额', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-iprep-daily-quota" min="0" class="small-text"
							name="<?php echo esc_attr( self::field_name( array( 'iprep', 'abuseipdb', 'daily_quota' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $abuse['daily_quota'] ?? 4750 ) ); ?>" />
						<span class="wk-muted"><?php esc_html_e( '次/天（默认 4750）——账户实配 5000/天留 5% 缓冲；达到后当日停转（429 触发，次日自愈）。', 'wink-gatekeeper' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-iprep-cache-minutes"><?php esc_html_e( '干净 IP 缓存', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-iprep-cache-minutes" min="0" class="small-text"
							name="<?php echo esc_attr( self::field_name( array( 'iprep', 'abuseipdb', 'cache_minutes' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $abuse['cache_minutes'] ?? 1440 ) ); ?>" />
						<span class="wk-muted"><?php esc_html_e( '分钟（默认 1440=24h）——查询判「通过」的 IP 结果缓存时长，省配额。', 'wink-gatekeeper' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-iprep-negative-cache-minutes"><?php esc_html_e( '可疑 IP 缓存', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-iprep-negative-cache-minutes" min="0" class="small-text"
							name="<?php echo esc_attr( self::field_name( array( 'iprep', 'negative_cache_minutes' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $iprep['negative_cache_minutes'] ?? 60 ) ); ?>" />
						<span class="wk-muted"><?php esc_html_e( '分钟（默认 60=1h）——判「可疑/恶意」的短缓存，恶意 IP 可复查降级。', 'wink-gatekeeper' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-iprep-timeout"><?php esc_html_e( '查询超时', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="number" id="wk-iprep-timeout" min="1" max="5" class="small-text"
							name="<?php echo esc_attr( self::field_name( array( 'iprep', 'abuseipdb', 'timeout' ) ) ); ?>"
							value="<?php echo esc_attr( (string) ( $abuse['timeout'] ?? 1 ) ); ?>" />
						<span class="wk-muted"><?php esc_html_e( '秒（1–5，默认 1）——超时按「不通过」从严处理（出码验证）；登录/评论预检/提交期三处共用此键。', 'wink-gatekeeper' ); ?></span>
					</td>
				</tr>
				</table>
			</details>
			<p>
				<button type="button" class="button" id="wk-iprep-test-credentials"
					data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX_TEST_IPREP ) ); ?>">
					<?php esc_html_e( '测试凭据', 'wink-gatekeeper' ); ?>
				</button>
				<span id="wk-iprep-test-result" aria-live="polite"></span>
			</p>
			<p class="description"><?php esc_html_e( '发一次最小查询实测（查询保留地址 127.0.0.2，几乎不耗配额）：绿 = HTTP 200 且响应 JSON 可解析（凭据可用——保留地址返回 isPublic:false 亦绿，验证的是凭据不是 IP）；红 = 401/402 密钥被拒；黄 = API Key 未填。结果不落库。', 'wink-gatekeeper' ); ?></p>
		</section>
		<?php
	}

	/**
	 * 分区③ 上报（M3-IP 定稿 §4——触发集 T1–T4，拍板 0.4：每面独立开关+独立阈值，
	 * 涉出境与隐私默认从关开始 T1 除外）：总开关+出境黄条（PIPL）+T1–T4 勾选组
	 * （每面开关+阈值数字框（T2/T4）+类目下拉（AbuseIpdbCategories 1–23，默认类目标注）+
	 * Comment 自定义框（placeholder 回显默认短语——UI-2-2；说明收敛为 foreach 外共享
	 * 一条——UI-2-1：前缀不可去+255 截断+出境警示+官方类目外链+占位符统一说明示例））+
	 * 冷却/限速/日顶只读展示 + 启用门禁状态行（real_ip_self_check 消费——未过=上报强制
	 * 失效无开关，定稿 §4.3 闸④）。
	 *
	 * @param array<string, mixed> $settings 设置树。
	 * @return void
	 */
	private static function render_iprep_zone_report( array $settings ): void {
		$report   = isset( $settings['iprep']['report'] ) && is_array( $settings['iprep']['report'] ) ? $settings['iprep']['report'] : array();
		$triggers = isset( $report['triggers'] ) && is_array( $report['triggers'] ) ? $report['triggers'] : array();
		$self_ok  = AbuseIpdbService::real_ip_self_check(
			isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array(), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读 $_SERVER 展示，无表单消费。
			$settings
		);

		// 触发面声明表（路径显式展开——schema 键面即渲染面，I3 注记「schema 键全集」）。
		$faces = array(
			'captcha_failed' => array(
				'title'          => __( 'T1 · 验证码有票核验失败', 'wink-gatekeeper' ),
				'desc'           => __( '有人提交了伪造或重放的验证票据——「有票才报」：未完成验证的提交（无票）不触发。默认开。', 'wink-gatekeeper' ),
				'toggle_path'    => array( 'iprep', 'report', 'triggers', 'captcha_failed' ),
				'threshold_path' => null, // T1 无阈值——直达四道闸.
				'category_path'  => array( 'iprep', 'report', 'category_captcha_failed' ),
				'comment_path'   => array( 'iprep', 'report', 'comment_captcha_failed' ),
				'comment_value'  => (string) ( $report['comment_captcha_failed'] ?? '' ),
				'category_value' => (int) ( $report['category_captcha_failed'] ?? 0 ),
			),
			'review_reject'  => array(
				'title'          => __( 'T2 · 云端审核拒绝累计', 'wink-gatekeeper' ),
				'desc'           => __( '同一 IP 被云端审核判「拒绝」24 小时内累计达 N 次（内容垃圾发布者形态）。默认关。', 'wink-gatekeeper' ),
				'toggle_path'    => array( 'iprep', 'report', 'triggers', 'review_reject' ),
				'threshold_path' => array( 'iprep', 'report', 'triggers', 'review_reject_threshold' ),
				'threshold_value' => (int) ( $triggers['review_reject_threshold'] ?? 5 ),
				'category_path'  => array( 'iprep', 'report', 'category_review_reject' ),
				'comment_path'   => array( 'iprep', 'report', 'comment_review_reject' ),
				'comment_value'  => (string) ( $report['comment_review_reject'] ?? '' ),
				'category_value' => (int) ( $report['category_review_reject'] ?? 0 ),
			),
			'login_attempts' => array(
				'title'          => __( 'T3 · 登录爆破（同 IP 计数饱和）', 'wink-gatekeeper' ),
				'desc'           => __( '同一 IP 登录失败达到既有阈值（10 次/IP——沿登录闸现成计数，不单设阈值）。默认关。', 'wink-gatekeeper' ),
				'toggle_path'    => array( 'iprep', 'report', 'triggers', 'login_attempts' ),
				'threshold_path' => null, // T3 沿用 LoginCounters 10 次/IP 不单设.
				'category_path'  => array( 'iprep', 'report', 'category_login_attempts' ),
				'comment_path'   => array( 'iprep', 'report', 'comment_login_attempts' ),
				'comment_value'  => (string) ( $report['comment_login_attempts'] ?? '' ),
				'category_value' => (int) ( $report['category_login_attempts'] ?? 0 ),
			),
			'local_rules_fail' => array(
				'title'          => __( 'T4 · 本地规则拦截累计', 'wink-gatekeeper' ),
				'desc'           => __( '同一 IP 被本地四规则闸（字数/无中文/禁词）拦 24 小时内累计达 N 次（预检与提交两处都计数）。默认关。', 'wink-gatekeeper' ),
				'toggle_path'    => array( 'iprep', 'report', 'triggers', 'local_rules_fail' ),
				'threshold_path' => array( 'iprep', 'report', 'triggers', 'local_rules_fail_threshold' ),
				'threshold_value' => (int) ( $triggers['local_rules_fail_threshold'] ?? 5 ),
				'category_path'  => array( 'iprep', 'report', 'category_local_rules_fail' ),
				'comment_path'   => array( 'iprep', 'report', 'comment_local_rules_fail' ),
				'comment_value'  => (string) ( $report['comment_local_rules_fail'] ?? '' ),
				'category_value' => (int) ( $report['category_local_rules_fail'] ?? 0 ),
			),
		);
		?>
		<section class="wk-card" id="wk-iprep-report">
			<h2 class="wk-sec-title"><?php esc_html_e( '恶意行为上报', 'wink-gatekeeper' ); ?></h2>
			<p class="description wk-muted"><?php esc_html_e( '可选能力：把确凿的恶意行为报告给 AbuseIPDB 社区信誉库，帮全世界的站点一起识别这些 IP。默认全关。', 'wink-gatekeeper' ); ?></p>

			<div class="notice notice-warning inline"><p>
				<strong><?php esc_html_e( '启用后访客 IP 将提交至境外服务 AbuseIPDB。', 'wink-gatekeeper' ); ?></strong>
				<?php esc_html_e( '报告详情在 AbuseIPDB 公开可见（不含评论内容）；请自行评估数据出境合规（PIPL）。', 'wink-gatekeeper' ); ?>
			</p></div>

			<p class="description">
				<?php if ( $self_ok ) : ?>
					<span class="wk-dot wk-dot--ok" aria-hidden="true"></span>
					<?php esc_html_e( '启用门禁：真实 IP 自检通过——上报具备启用条件。', 'wink-gatekeeper' ); ?>
				<?php else : ?>
					<span class="wk-dot wk-dot--alert" aria-hidden="true"></span>
					<strong><?php esc_html_e( '上报已强制失效：真实 IP 解析异常（NAT/反代共显）。', 'wink-gatekeeper' ); ?></strong>
					<?php esc_html_e( '当前请求来源为私网/无法判定——向信誉库上报共享出口 IP 会污染库；在分区① 配好受信反代并保存后自动恢复（此门禁无开关，宁可少报）。', 'wink-gatekeeper' ); ?>
				<?php endif; ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '上报总开关', 'wink-gatekeeper' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'iprep', 'report', 'enabled' ) ) ); ?>" value="0" />
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'iprep', 'report', 'enabled' ) ) ); ?>" value="1" <?php checked( ! empty( $report['enabled'] ) ); ?> />
							<?php esc_html_e( '启用恶意行为上报（高级设置里任一触发面命中才发送）', 'wink-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( '独立于上方「IP 信誉查询」开关——只上报不查询；发送失败静默不影响拦截。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
			</table>
			<!-- 【M4UX F3-3】T1–T4 勾选组+备注说明+限流参数默认整组折叠（details/wk-guide 既有形态）——
			 * 总开关/出境 PIPL 黄条/启用门禁状态行不折叠常显（警示常显红线）。 -->
			<details class="wk-guide" id="wk-iprep-report-advanced">
				<summary><?php esc_html_e( '高级设置（触发面 T1–T4 / 备注 / 限流参数）', 'wink-gatekeeper' ); ?></summary>
				<table class="form-table" role="presentation">
				<?php foreach ( $faces as $face_key => $face ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $face['title'] ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( $face['toggle_path'] ) ); ?>" value="0" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::field_name( $face['toggle_path'] ) ); ?>" value="1" <?php checked( ! empty( self::dig( $triggers, array( $face_key ), false ) ) ); ?> />
								<?php esc_html_e( '启用此触发面', 'wink-gatekeeper' ); ?>
							</label>
							<p class="description"><?php echo esc_html( $face['desc'] ); ?></p>
							<?php if ( null !== $face['threshold_path'] ) : ?>
								<p>
									<label for="wk-iprep-threshold-<?php echo esc_attr( $face_key ); ?>"><?php esc_html_e( '阈值 N（24h 窗累计）', 'wink-gatekeeper' ); ?></label>
									<input type="number" id="wk-iprep-threshold-<?php echo esc_attr( $face_key ); ?>" min="1" class="small-text"
										name="<?php echo esc_attr( self::field_name( $face['threshold_path'] ) ); ?>"
										value="<?php echo esc_attr( (string) ( $face['threshold_value'] ?? 5 ) ); ?>" />
								</p>
							<?php endif; ?>
							<p>
								<label for="wk-iprep-category-<?php echo esc_attr( $face_key ); ?>"><?php esc_html_e( '上报类目', 'wink-gatekeeper' ); ?></label>
								<select id="wk-iprep-category-<?php echo esc_attr( $face_key ); ?>"
									name="<?php echo esc_attr( self::field_name( $face['category_path'] ) ); ?>">
									<option value="0" <?php selected( 0, $face['category_value'] ); ?>>
										<?php
										printf(
											/* translators: 1: 默认类目码 2: 默认类目名 */
											esc_html__( '用默认（%1$d %2$s）', 'wink-gatekeeper' ),
											(int) AbuseIpdbCategories::default_for( $face_key ),
											esc_html( AbuseIpdbCategories::label( AbuseIpdbCategories::default_for( $face_key ) ) )
										);
										?>
									</option>
									<?php foreach ( AbuseIpdbCategories::MAP as $cat_id => $cat_label ) : ?>
										<option value="<?php echo esc_attr( (string) $cat_id ); ?>" <?php selected( $cat_id, $face['category_value'] ); ?>><?php echo esc_html( $cat_id . ' ' . $cat_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<p>
								<label class="screen-reader-text" for="wk-iprep-comment-<?php echo esc_attr( $face_key ); ?>"><?php esc_html_e( '自定义上报备注', 'wink-gatekeeper' ); ?></label>
								<textarea id="wk-iprep-comment-<?php echo esc_attr( $face_key ); ?>" class="large-text" rows="2"
									name="<?php echo esc_attr( self::field_name( $face['comment_path'] ) ); ?>"
									placeholder="<?php echo esc_attr( AbuseIpdbService::default_comment( $face_key ) ); ?>"><?php echo esc_textarea( $face['comment_value'] ); ?></textarea>
							</p>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><?php esc_html_e( '上报备注说明（T1–T4 共用）', 'wink-gatekeeper' ); ?></th>
					<td>
						<!-- 【M4UX UI-2-1】Comment 说明收敛：foreach 外共享一条（此前逐面重复 ×4——V3 收口）；
						 placeholder 说明只留 description（UI-2-2——输入框空值即回显默认短语）。 -->
						<p class="description">
							<?php esc_html_e( '备注留空=发送该触发面的默认英文短语（已回显在各输入框 placeholder）。发送时自动加前缀 [wink-wp-gatekeeper]（不可去除），超长自动截断（255 字符）。', 'wink-gatekeeper' ); ?>
							<?php esc_html_e( '请勿填写站点敏感信息与个人数据——AbuseIPDB 报告详情公开可见（访客 IP 提交至境外服务，数据出境请自行评估 PIPL 合规）。', 'wink-gatekeeper' ); ?>
						</p>
						<p class="description">
							<?php esc_html_e( '支持占位符 {event_no}（拦截事件编号）、{category}（本次上报的 AbuseIPDB 类目码）、{gate}（触发面 T1/T2/T3/T4）。使用示例：', 'wink-gatekeeper' ); ?>
						</p>
						<!-- 【M4UX F3-4】示例块独立样式行（术语 code 与示例排版分离——消扁平混杂）。 -->
						<code class="wk-example-line">Forged captcha ticket #{event_no} (category {category}, gate {gate})</code>
						<p class="description">
							<?php esc_html_e( '类目下拉的官方全表见', 'wink-gatekeeper' ); ?>
							<a href="https://www.abuseipdb.com/categories" target="_blank" rel="noopener noreferrer">abuseipdb.com/categories</a>
							<?php esc_html_e( '（AbuseIPDB 官方类目 1–23，下拉内已内置同表）。', 'wink-gatekeeper' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '限流参数（只读）', 'wink-gatekeeper' ); ?></th>
					<td>
						<p class="description">
							<?php
							printf(
								/* translators: 1: 冷却分钟 2: 每小时次数 3: 每日上限 */
								esc_html__( '每 IP 冷却 %1$d 分钟 · 全局限速 %2$d 次/小时 · 日顶 %3$d 次/日（上报独立池，不占查询配额）。', 'wink-gatekeeper' ),
								(int) ( $report['cooldown_minutes'] ?? 60 ),
								(int) ( $report['hourly_limit'] ?? 60 ),
								(int) ( $report['daily_cap'] ?? 500 )
							);
							?>
							<?php esc_html_e( '冷却窗内重复触发免重报；10 分钟内触发超 20 次会在总览页提醒（可能有人在伪造票据灌水）。', 'wink-gatekeeper' ); ?>
						</p>
					</td>
				</tr>
				</table>
			</details>
		</section>
		<?php
	}

	/**
	 * 分区④ 说明卡（定稿 §6【O5 回写·P2-4】：三态语义人话各一行+与信用免验联动说明+
	 * 软顶人话一句——非故障预期管理）。
	 *
	 * @return void
	 */
	private static function render_iprep_zone_notes(): void {
		?>
		<section class="wk-card" id="wk-iprep-notes">
			<h2 class="wk-sec-title"><?php esc_html_e( '判定的三种结果（人话版）', 'wink-gatekeeper' ); ?></h2>
			<ul class="description">
				<li><?php esc_html_e( '跳过（skip）：IP 信誉未启用，或本次恰好查不了（退避/停转/私网）——不参与判定，验证照常走，也不影响「老朋友」免验资格。', 'wink-gatekeeper' ); ?></li>
				<li><?php esc_html_e( '通过（pass）：信誉良好——照常放行，访客无感。', 'wink-gatekeeper' ); ?></li>
				<li><?php esc_html_e( '不通过（fail）：恶意/可疑 IP，或配置错、超时、报错、超阈值——一律从严：强制访客先过人机验证再放行。', 'wink-gatekeeper' ); ?></li>
			</ul>
			<p class="description"><?php esc_html_e( '与信用免验的联动：IP 信誉判「不通过」时，就算是「老朋友」（信用免验通道）也会被要求先过一次码——新鲜判定压过信任凭证，过码后照常放行。', 'wink-gatekeeper' ); ?></p>
			<p class="description"><?php esc_html_e( '当日查询配额用尽后，验证码将临时对所有访客强制——次日自动恢复，非故障。', 'wink-gatekeeper' ); ?></p>
		</section>
		<?php
	}

	/**
	 * 「IP 检测」诊断面板（Tab④ 顶卡，M3-IP 定稿 §5.1——纯函数行矩阵 + 本 WP 边界渲染）。
	 *
	 * 本地解析区：REMOTE_ADDR（标受信命中）/CF-Connecting-IP/X-Real-IP/X-Forwarded-For
	 * （逗号链逐项标 受信/公网/私网）+ 当前生效行五态 ip_src + 配置一致性红标 +
	 * 受信网段行（预设名+手填 CIDR+快照校准日期，>180 天黄条；EdgeOne 空预设不入行——定稿 §5.3）。
	 * 手动聚合查询区：站长输 IP → 四源卡片（出境说明常驻；结果纯请求态渲染不落库）。
	 *
	 * @param array<string, mixed> $settings 设置树（merge_defaults 后全树）。
	 * @return void
	 */
	private static function render_ip_diagnostic_panel( array $settings ): void {
		$panel = IpDiagnostic::analyze( // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读 $_SERVER 展示，无表单消费。
			$_SERVER,
			$settings['network'] ?? array(),
			time(),
			AbuseIpdbService::whitelist_lines_from( $settings['iprep']['whitelist'] ?? '' ) // 【I5】豁免徽标判定源。
		);

		$scope_labels = array(
			'trusted' => __( '受信', 'wink-gatekeeper' ),
			'public'  => __( '公网', 'wink-gatekeeper' ),
			'private' => __( '私网', 'wink-gatekeeper' ),
			'invalid' => __( '非法', 'wink-gatekeeper' ),
		);
		$src_labels   = array(
			IpResolver::SRC_DIRECT            => __( '直连', 'wink-gatekeeper' ),
			IpResolver::SRC_XFF               => __( 'XFF 链', 'wink-gatekeeper' ),
			IpResolver::SRC_HEADER            => __( '自定义头', 'wink-gatekeeper' ),
			IpResolver::SRC_UNRESOLVED        => __( '未解析（回退）', 'wink-gatekeeper' ),
			IpResolver::SRC_IGNORED_UNTRUSTED => __( '来源不受信·转发头已忽略', 'wink-gatekeeper' ),
		);
		$mode_labels  = array(
			IpResolver::MODE_DIRECT        => __( '直连 direct', 'wink-gatekeeper' ),
			IpResolver::MODE_XFF           => __( 'XFF 信任链（仅取最右）', 'wink-gatekeeper' ),
			IpResolver::MODE_CUSTOM_HEADER => __( '自定义头 custom_header', 'wink-gatekeeper' ),
			IpResolver::MODE_TRAVERSE      => __( 'traverse 链式回溯', 'wink-gatekeeper' ),
		);
		?>
		<section class="wk-card" id="wk-iprep-diag">
			<h2><?php esc_html_e( 'IP 检测诊断面板', 'wink-gatekeeper' ); ?></h2>
			<p class="description wk-muted"><?php esc_html_e( '本面板展示「当前这次页面请求」的解析实况；下方手动查询会把所输 IP 发往四个服务商（AbuseIPDB / StopForumSpam / ip.nc.gy / ip.sb）——结果仅本次展示、不落库不缓存、不计调用次数。', 'wink-gatekeeper' ); ?></p>

			<h3 class="wk-sub-title"><?php esc_html_e( '本地解析区', 'wink-gatekeeper' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'REMOTE_ADDR', 'wink-gatekeeper' ); ?></th>
					<td>
						<code><?php echo '' !== $panel['remote']['raw'] ? esc_html( $panel['remote']['raw'] ) : esc_html__( '（缺失）', 'wink-gatekeeper' ); ?></code>
						<span class="wk-muted">
							<?php
							printf(
								/* translators: 1: 作用域标注 2: 受信命中说明 */
								esc_html__( '%1$s、%2$s', 'wink-gatekeeper' ),
								esc_html( $scope_labels[ $panel['remote']['scope'] ] ?? (string) $panel['remote']['scope'] ),
								esc_html( $panel['remote']['trusted'] ? __( '命中受信网段', 'wink-gatekeeper' ) : __( '不在受信网段', 'wink-gatekeeper' ) )
							);
							?>
						</span>
					</td>
				</tr>
				<?php foreach ( $panel['headers'] as $header_row ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $header_row['name'] ); ?></th>
						<td>
							<?php if ( null === $header_row['raw'] ) : ?>
								<span class="wk-muted"><?php esc_html_e( '（本请求未出现）', 'wink-gatekeeper' ); ?></span>
							<?php else : ?>
								<code><?php echo esc_html( (string) $header_row['raw'] ); ?></code>
								<?php $items = array(); ?>
								<?php foreach ( $header_row['items'] as $item ) : ?>
									<?php
									$items[] = sprintf(
										'%s（%s）',
										(null !== $item['ip']) ? $item['ip'] : $item['raw'],
										$scope_labels[ $item['scope'] ] ?? (string) $item['scope']
									);
									?>
								<?php endforeach; ?>
								<?php if ( array() !== $items ) : ?>
									<span class="wk-muted"><?php echo esc_html( implode( ' · ', $items ) ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><?php esc_html_e( '当前生效', 'wink-gatekeeper' ); ?></th>
					<td>
						<strong><code><?php echo esc_html( '' !== $panel['effective']['ip'] ? $panel['effective']['ip'] : '—' ); ?></code></strong>
						<?php if ( ! empty( $panel['effective']['whitelisted'] ) ) : ?>
							<!-- 【I5】站长白名单命中徽标（AbuseIpdbService 步 0 同一判定函数）。 -->
							<span class="wk-muted">· <?php esc_html_e( '白名单豁免', 'wink-gatekeeper' ); ?></span>
						<?php endif; ?>
						<span class="wk-muted">
							<?php
							printf(
								/* translators: 1: 五态 ip_src 2: 获取模式 */
								esc_html__( 'ip_src=%1$s · 模式=%2$s', 'wink-gatekeeper' ),
								esc_html( ( $src_labels[ $panel['effective']['ip_src'] ] ?? (string) $panel['effective']['ip_src'] ) . '（' . $panel['effective']['ip_src'] . '）' ),
								esc_html( $mode_labels[ $panel['mode'] ] ?? (string) $panel['mode'] )
							);
							?>
						</span>
					</td>
				</tr>
			</table>

			<?php if ( $panel['flag'] ) : ?>
				<p class="description">
					<span class="wk-dot wk-dot--alert" aria-hidden="true"></span>
					<?php
					printf(
						/* translators: %s: 配置的自定义头名 */
						esc_html__( '配置一致性：已配置自定义头「%s」但本请求没有该头——请核对当前拓扑（Wordfence「Configured but not valid」同款警示）。', 'wink-gatekeeper' ),
						esc_html( $panel['custom_header'] )
					);
					?>
				</p>
			<?php endif; ?>

			<p class="description">
				<span class="wk-muted">
					<?php
					$trusted_bits = array();
					foreach ( $panel['trusted']['rows'] as $row ) {
						$trusted_bits[] = sprintf( '%s（%d 条）', $row['label'], (int) $row['count'] );
					}
					if ( null !== $panel['trusted']['verified'] ) {
						$trusted_bits[] = sprintf(
							/* translators: %s: 快照核对日期 */
							__( '预设快照核对日期：%s', 'wink-gatekeeper' ),
							(string) $panel['trusted']['verified']
						);
					}
					echo esc_html( array() !== $trusted_bits
						? __( '受信网段：', 'wink-gatekeeper' ) . implode( ' · ', $trusted_bits )
						: __( '受信网段：（未启用任何预设或自定义网段——转发头一概不参与解析）', 'wink-gatekeeper' ) );
					?>
				</span>
			</p>
			<?php if ( $panel['trusted']['stale'] ) : ?>
				<p class="wk-notice-inline-warn description">
					<?php
					printf(
						/* translators: 1: 快照日期 2: 距今天数 */
						esc_html__( '受信网段快照已超过 180 天（最后核对 %1$s，距今 %2$d 天）——网段可能过期，请校准（开发期工具 tools/api-smoke/refresh-presets.php 拉官方最新段 diff 后人工手改）。', 'wink-gatekeeper' ),
						esc_html( (string) $panel['trusted']['verified'] ),
						(int) ( $panel['trusted']['age_days'] ?? 0 )
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( array() !== $panel['trusted']['invalid'] ) : ?>
				<p class="description">
					<span class="wk-dot wk-dot--alert" aria-hidden="true"></span>
					<?php
					printf(
						/* translators: %s: 非法行列表 */
						esc_html__( '自定义网段非法行（已跳过）：%s', 'wink-gatekeeper' ),
						esc_html( implode( '、', $panel['trusted']['invalid'] ) )
					);
					?>
				</p>
			<?php endif; ?>

			<h3 class="wk-sub-title"><?php esc_html_e( '手动聚合查询区', 'wink-gatekeeper' ); ?></h3>
			<p>
				<label class="screen-reader-text" for="wk-ip-diag-ip"><?php esc_html_e( '要查询的 IP 地址', 'wink-gatekeeper' ); ?></label>
				<input type="text" id="wk-ip-diag-ip" class="regular-text" placeholder="8.8.8.8 或 2001:db8::1" autocomplete="off" />
				<button type="button" class="button" id="wk-ip-diag-query" data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX_IP_DIAGNOSE ) ); ?>"><?php esc_html_e( '查询四源', 'wink-gatekeeper' ); ?></button>
				<span id="wk-ip-diag-status" class="wk-muted" aria-live="polite"></span>
			</p>
			<div id="wk-ip-diag-result" class="wk-ip-diag-grid"></div>
			<p class="description wk-muted">
				<?php esc_html_e( '威胁组：AbuseIPDB（评分/用途/ISP/Tor）· StopForumSpam（黑名单出现/频次）· ip.nc.gy（代理/VPN/Tor/机房/CDN/匿名六旗）；地理组：ip.sb（归属地/ISP/ASN）。查询超时 3 秒/源；任一源失败仅该卡片失败，其余照常出数。', 'wink-gatekeeper' ); ?>
			</p>
		</section>
		<?php
	}

	/**
	 * Tab④ 诊断面板「手动聚合查询」AJAX（M3-IP 定稿 §5.1，I0 批）。
	 *
	 * nonce + manage_options 双闸（03 §1 契约④）；四源 URL 常量集中一处
	 * （IpDiagnostic::REMOTE_SOURCES，§12-2——https 硬约束由 RemoteClient::check_url 再验一道）；
	 * RemoteClient standard timeout 3s；**零落库零缓存零统计**（无 transient/option 写、无 usage_key）；
	 * 任一源失败仅该卡片失败（error 卡片人话由 IpDiagnostic::card_error）。
	 *
	 * @return void
	 */
	public static function ajax_ip_diagnose(): void {
		check_ajax_referer( self::AJAX_IP_DIAGNOSE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足：需要 manage_options。', 'wink-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post   = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		$ip_raw = isset( $post['ip'] ) && is_string( $post['ip'] ) ? trim( $post['ip'] ) : '';
		$ip     = IpResolver::normalize( $ip_raw );
		if ( null === $ip ) {
			wp_send_json_error( array( 'message' => __( 'IP 格式无效。', 'wink-gatekeeper' ) ), 400 );
		}

		$stored  = get_option( self::OPTION_NAME, array() );
		$stored  = SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() );
		$api_key = isset( $stored['iprep']['abuseipdb']['api_key'] ) && is_string( $stored['iprep']['abuseipdb']['api_key'] )
			? $stored['iprep']['abuseipdb']['api_key'] : '';

		$urls   = IpDiagnostic::source_urls( $ip );
		$client = new RemoteClient(); // WP 边界默认传输；诊断查询零计量（不传计量键）。

		$cards = array();
		foreach ( $urls as $id => $url ) {
			$args = array(
				'timeout'           => 3,
			);
			if ( 'abuseipdb' === $id ) {
				if ( '' === $api_key ) {
					$cards[ $id ] = array( 'ok' => false, 'rows' => array(), 'error' => __( '未配置 API Key（Tab④ 分区二）', 'wink-gatekeeper' ) );
					continue;
				}
				$args['headers'] = array(
					'Key'    => $api_key,
					'Accept' => 'application/json',
				);
			}
			$result = $client->get( $url, $args );
			if ( $result->is_ok() && is_array( $result->data() ) ) {
				$cards[ $id ] = IpDiagnostic::parse_source( $id, $result->data() );
			} else {
				$cards[ $id ] = array(
					'ok'    => false,
					'rows'  => array(),
					'error' => IpDiagnostic::card_error( $result->category(), $result->status() ),
				);
			}
		}

		wp_send_json_success( array( 'ip' => $ip, 'cards' => $cards ) );
	}

	/**
	 * Tab④ 分区②「测试凭据」AJAX 端点（M3-IP 定稿 §6【O5 回写·P2-6】，I4 收官批；
	 * 03 §1 契约④：nonce + manage_options 双闸；结果不落库）。
	 *
	 * 表单值（password 空串 = 回落已存值——与保存契约同语义，Tab② 先例）→ 本地预检
	 * （API Key 空=黄态不发远端）→ AbuseIpdbService::probe_credentials 最小 check 查询
	 * （127.0.0.2 保留地址+maxAgeInDays=1 省配额；零计量零写零事件）→
	 * CredentialProbe::classify_abuseipdb 三态（绿=HTTP 200 且 JSON 可解析，isPublic:false
	 * 亦绿——验证的是凭据不是 IP；红=401/402 错钥；灰=429/传输终态/契约不符）。
	 *
	 * @return void
	 */
	public static function ajax_test_iprep(): void {
		check_ajax_referer( self::AJAX_TEST_IPREP, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足：需要 manage_options。', 'wink-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$stored = get_option( self::OPTION_NAME, array() );
		$stored = SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() );
		$stored_key = isset( $stored['iprep']['abuseipdb']['api_key'] ) && is_string( $stored['iprep']['abuseipdb']['api_key'] )
			? $stored['iprep']['abuseipdb']['api_key'] : '';

		// 表单值覆盖（password 空串保留已存值——Tab② probe_field 同语义）。
		$api_key = self::probe_field( $post, 'api_key', $stored_key );

		$precheck = CredentialProbe::local_precheck_abuseipdb( $api_key );
		if ( null !== $precheck ) {
			wp_send_json_success( $precheck );
		}

		$settings                     = $stored;
		$settings['iprep']['abuseipdb']['api_key'] = $api_key;

		$service = new AbuseIpdbService(
			$settings,
			new RemoteClient() // WP 边界：默认传输（探测一次——probe_credentials 零计量零写零事件）。
		);
		$state = CredentialProbe::classify_abuseipdb( $service->probe_credentials() );
		wp_send_json_success( $state );
	}

	/* -------------------------------------------------------------------
	 * Tab⑤ 拦截页预览（【M4UX·G3】「查看效果」——新标签页实渲染 DiePage 样例）
	 * ------------------------------------------------------------------ */

	/**
	 * 拦截页预览载荷（纯函数，单测覆盖；【M4UX·G3】）。
	 *
	 * 用当前设置树的 copy 子树（场景默认文案打底 + 站长自定义 die_page_title/die_page_message
	 * 覆盖——DiePage::resolve_page_text 同一归一口径）按占位符示例值填充：
	 * {event_no}=Copywork::PREVIEW_EVENT_NO（WK-00000000）、{rule}=Copywork::PREVIEW_RULE_SAMPLE
	 * （示例规则）、{time}=预览时刻（调用方传站点时区当前时间）、{contact_email}=brand 子树值。
	 * 证据行同 CommentGate::die_payload 三键形态（事件编号/命中规则/时间）——预览即实渲染形态。
	 *
	 * @param array<string, mixed> $settings 设置树（brand/copy 子树）。
	 * @param string               $time     预览时刻字符串（站点时区）。
	 * @return array{title: string, message: string, evidence: array<string, string>}
	 */
	public static function die_preview_payload( array $settings, string $time ): array {
		$copy    = is_array( $settings['copy'] ?? null ) ? $settings['copy'] : array();
		$contact = isset( $settings['brand']['contact_email'] ) && is_string( $settings['brand']['contact_email'] )
			? $settings['brand']['contact_email'] : '';

		$vars = array(
			'event_no' => Copywork::PREVIEW_EVENT_NO,
			'rule'     => Copywork::PREVIEW_RULE_SAMPLE,
			'time'     => $time,
		);
		if ( '' !== $contact ) {
			$vars['contact_email'] = $contact;
		}

		$page = \WinkGatekeeper\Intercept\DiePage::resolve_page_text( 'banned_word', $copy, $vars );

		return array(
			'title'    => $page['title'],
			'message'  => $page['message'],
			'evidence' => array(
				'事件编号' => Copywork::PREVIEW_EVENT_NO,
				'命中规则' => Copywork::PREVIEW_RULE_SAMPLE,
				'时间'     => $time,
			),
		);
	}

	/**
	 * Tab⑤「查看效果」预览处理器（admin_action_wink_gatekeeper_die_preview；【M4UX·G3】）。
	 *
	 * manage_options + nonce 双闸（03 §1 契约④）→ die_preview_payload 纯函数载荷
	 * → DiePage::build 完整品牌页 echo → exit。**零落库零事件零统计**（只读 option，
	 * 不写任何状态——预览即实渲染形态，与真拦截页同一构造器）。
	 *
	 * @return void
	 */
	public static function handle_die_preview(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'wink-gatekeeper' ), 403 );
		}
		check_admin_referer( self::ACTION_DIE_PREVIEW );

		$settings = self::settings();
		$payload  = self::die_preview_payload( $settings, date_i18n( 'Y-m-d H:i:s' ) );
		$contact  = isset( $settings['brand']['contact_email'] ) && is_string( $settings['brand']['contact_email'] )
			? $settings['brand']['contact_email'] : '';

		// phpcs:ignore WordPress.Security.EscapeOutput -- DiePage::build 内部逐值 htmlspecialchars。
		echo \WinkGatekeeper\Intercept\DiePage::build(
			$payload['title'],
			$payload['message'],
			$payload['evidence'],
			$contact,
			WINK_GATEKEEPER_URL . 'assets/brand/logo-wink.svg',
			get_locale()
		);
		exit;
	}

	/* -------------------------------------------------------------------
	 * Tab⑦ 存量审核操作面（【M5·U3】admin_post 四件；D6 分工——Tab⑦ 发起/Tab⑥ 报告）
	 * ------------------------------------------------------------------ */

	/**
	 * Tab③ DeepSeek「测试凭据」AJAX 处理器（【M6·批B·N4】——余额端点三态探测）。
	 *
	 * 【M6·批B·N4 契约翻转】models 探活（M5·U4 D5 裁决）退役→GET /user/balance 余额
	 * 探测（零费用端点，信息量升级：Key 有效性+账户可用性+可见余额一次拿全；绿态
	 * 人话行「余额 110.00 CNY（含赠金 10.00）」）。probe_models 保留给 openai_custom
	 * 泛兼容端点（无标准余额 API——UI 注明差异）。
	 *
	 * 绿=200 且 is_available=true（附 balance 对象 currency/total_balance/granted_balance）；
	 * 黄=200 且 is_available=false（欠费）；红=401/402/403/transport；灰=契约异常；
	 * 另黄=API Key 未填（本地预检）。表单值覆盖（password 空串保留已存值语义）；
	 * 响应闭集不含提交值（凭据零回显）；不计 usage 不落库。
	 *
	 * @return void
	 */
	public static function ajax_test_credentials_deepseek(): void {
		check_ajax_referer( self::AJAX_TEST_CREDENTIALS_DEEPSEEK, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足：需要 manage_options。', 'wink-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$stored  = get_option( self::OPTION_NAME, array() );
		$stored  = SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() );
		$current = isset( $stored['review']['cloud']['deepseek'] ) && is_array( $stored['review']['cloud']['deepseek'] )
			? $stored['review']['cloud']['deepseek'] : array();

		$api_key  = self::probe_field( $post, 'deepseek_api_key', (string) ( $current['api_key'] ?? '' ) );
		$base_url = isset( $post['deepseek_base_url'] ) && is_scalar( $post['deepseek_base_url'] )
			? trim( (string) $post['deepseek_base_url'] ) : (string) ( $current['base_url'] ?? 'https://api.deepseek.com' );

		$precheck = CredentialProbe::local_precheck_deepseek( $api_key );
		if ( null !== $precheck ) {
			wp_send_json_success( $precheck );
		}

		$config = array(
			'api_key'  => $api_key,
			'base_url' => '' !== $base_url ? $base_url : 'https://api.deepseek.com',
		);
		$driver = new \WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver( $config, new RemoteClient() ); // 探测一次不计 usage。
		$state  = CredentialProbe::classify_deepseek_balance( $driver->probe_balance() );
		wp_send_json_success( $state );
	}

	/**
	 * Tab③ 网址核查独立凭据「测试凭据」AJAX 处理器（【M6·批B·B-3】N4 范围边界；
	 * 【M6·批E·E-4】按 base_url 分流）。
	 *
	 * provider custom 独立凭证：base_url host 为 DeepSeek 官方域（或空回落官方）→
	 * 维持 /user/balance 余额探测（信息量优先）；非官方 base_url（中转站/自建网关）→
	 * 切 openai_custom /models 探活（泛端点无标准余额 API——此前硬走余额探测必契约
	 * 不符灰态）。分流点收敛 UrlAuditWorker::provider_probe_is_deepseek 一处；表单覆盖
	 * （password 空串保留已存值语义）；响应闭集不含提交值；不计 usage 不落库。
	 *
	 * @return void
	 */
	public static function ajax_test_credentials_url_audit(): void {
		check_ajax_referer( self::AJAX_TEST_CREDENTIALS_URL_AUDIT, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足：需要 manage_options。', 'wink-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$stored   = get_option( self::OPTION_NAME, array() );
		$stored   = SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() );
		$provider = isset( $stored['url_audit']['provider'] ) && is_array( $stored['url_audit']['provider'] )
			? $stored['url_audit']['provider'] : array();

		$api_key  = self::probe_field( $post, 'url_audit_provider_api_key', (string) ( $provider['api_key'] ?? '' ) );
		$base_url = isset( $post['url_audit_provider_base_url'] ) && is_scalar( $post['url_audit_provider_base_url'] )
			? trim( (string) $post['url_audit_provider_base_url'] ) : (string) ( $provider['base_url'] ?? '' );

		$precheck = CredentialProbe::local_precheck_deepseek( $api_key );
		if ( null !== $precheck ) {
			wp_send_json_success( $precheck );
		}

		if ( \WinkGatekeeper\UrlAudit\UrlAuditWorker::provider_probe_is_deepseek( $base_url ) ) {
			// 官方域（或空回落官方）：DeepSeek 余额探测（零费用——信息量优先）。
			$config = array(
				'api_key'  => $api_key,
				'base_url' => '' !== $base_url ? $base_url : \WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver::DEFAULT_BASE_URL,
			);
			$driver = new \WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver( $config, new RemoteClient() ); // 探测一次不计 usage。
			wp_send_json_success( CredentialProbe::classify_deepseek_balance( $driver->probe_balance() ) );
		}

		// 非官方域：openai_custom /models 探活（模型列表三态——泛端点无标准余额 API）。
		$driver = new \WinkGatekeeper\Drivers\Review\OpenAiCustomReviewDriver(
			array( 'api_key' => $api_key, 'base_url' => $base_url ),
			new RemoteClient() // 探测一次不计 usage。
		);
		wp_send_json_success( CredentialProbe::classify_openai_custom_models( $driver->probe_models() ) );
	}

	/**
	 * Tab③ openai_custom「测试凭据」AJAX 处理器（【M6·批E】——/models 三态探活）。
	 *
	 * GET {base_url}/models（Bearer，零 token 消耗——泛端点无标准余额 API）。表单值
	 * 覆盖（password 空串保留已存值语义）；本地预检三键缺=黄态零外呼；绿态含手填
	 * 模型则顺带校验在不在列表；响应闭集 {state,message,missing,code} 不含提交值
	 * （凭据零回显）；不计 usage 不落库。
	 *
	 * @return void
	 */
	public static function ajax_test_credentials_openai_custom(): void {
		check_ajax_referer( self::AJAX_TEST_CREDENTIALS_OPENAI_CUSTOM, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足：需要 manage_options。', 'wink-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		$stored  = get_option( self::OPTION_NAME, array() );
		$stored  = SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() );
		$current = isset( $stored['review']['cloud']['openai_custom'] ) && is_array( $stored['review']['cloud']['openai_custom'] )
			? $stored['review']['cloud']['openai_custom'] : array();

		$sub = array(
			'api_key'  => self::probe_field( $post, 'openai_custom_api_key', (string) ( $current['api_key'] ?? '' ) ),
			'base_url' => isset( $post['openai_custom_base_url'] ) && is_scalar( $post['openai_custom_base_url'] )
				? trim( (string) $post['openai_custom_base_url'] ) : (string) ( $current['base_url'] ?? '' ),
			'model'    => isset( $post['openai_custom_model'] ) && is_scalar( $post['openai_custom_model'] )
				? trim( (string) $post['openai_custom_model'] ) : (string) ( $current['model'] ?? '' ),
		);

		$precheck = CredentialProbe::local_precheck_openai_custom( $sub );
		if ( null !== $precheck ) {
			wp_send_json_success( $precheck );
		}

		$driver = new \WinkGatekeeper\Drivers\Review\OpenAiCustomReviewDriver(
			array( 'api_key' => $sub['api_key'], 'base_url' => $sub['base_url'] ),
			new RemoteClient() // 探测一次不计 usage。
		);
		wp_send_json_success( CredentialProbe::classify_openai_custom_models( $driver->probe_models(), $sub['model'] ) );
	}

	/**
	 * 存量审核 Worker（生产装配——全默认 WP 通道；设置树 merge 单源同 CommentGate）。
	 *
	 * @return \WinkGatekeeper\Audit\ExistingAuditWorker
	 */
	private static function audit_worker(): \WinkGatekeeper\Audit\ExistingAuditWorker {
		$stored = get_option( self::OPTION_NAME, array() );
		return new \WinkGatekeeper\Audit\ExistingAuditWorker(
			SettingsSchema::merge_defaults( is_array( $stored ) ? $stored : array() )
		);
	}

	/**
	 * 存量审核四操作统一处理器（admin_post_wink_gatekeeper_audit_{start|pause|resume|reset}）：
	 * manage_options + check_admin_referer 双闸；start/resume 重排 cron tick、pause/reset
	 * 清 tick（CLI run 通道自跑，cron 是站长侧补充面）；完成后重定向回 Tab⑦。
	 *
	 * @return void
	 */
	public static function handle_audit_action(): void {
		$action = current_action();
		$op     = is_string( $action ) ? str_replace( 'admin_post_', '', $action ) : '';
		$op     = str_replace( 'wink_gatekeeper_audit_', '', $op );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'wink-gatekeeper' ), 403 );
		}
		check_admin_referer( 'wink_gatekeeper_audit_' . $op );

		$worker = self::audit_worker();
		try {
			switch ( $op ) {
				case 'start':
					try {
						// 【M6·批C·C-1】宇宙快照化：发起时固化 ID 清单（F1「23/22」修复——
						// worker 只处理清单内 ID，发起后新增不扫入）。
						$universe = \WinkGatekeeper\Audit\ExistingAuditWorker::unaudited_universe_ids();
						$worker->start( count( $universe ), $universe );
					} catch ( \RuntimeException $e ) {
						// 【M5 修复批·C3】start 专属显式错误回跳（wk-audit-error=1）——
						// 无已配置云审驱动时区别于下方竞态静默回跳：任务未落盘（C1 守卫
						// 抛出前置），Tab⑦ 状态行仍 idle，站长需看到「为什么没发起」。
						wp_safe_redirect( add_query_arg( array( 'wk-audit-error' => '1' ), self::tab_url( 'tools' ) ) );
						exit;
					}
					self::audit_schedule_tick();
					break;
				case 'pause':
					$worker->pause_task();
					self::audit_unschedule_tick();
					break;
				case 'resume':
					$worker->resume_task();
					self::audit_schedule_tick();
					break;
				case 'reset':
					$worker->reset_task( \WinkGatekeeper\Audit\ExistingAuditWorker::universe_ids() );
					self::audit_unschedule_tick();
					break;
			}
		} catch ( \RuntimeException $e ) {
			// 已在运行等竞态：静默回 Tab⑦（状态行会显示现态）。
		}

		wp_safe_redirect( self::tab_url( 'tools' ) );
		exit;
	}

	/**
	 * Tab③「网址核查」配置区（【M5·U5】UI 设计块 2-B——增强层：关闭=零行为变化）。
	 *
	 * 三档 profile（radio+dl 逐档人话）；「0.95 自动定罪门」系统掌握不露旋钮（D8——
	 * 只放大严）；高级折叠（【M6·批B·B-1】博查「核查前先搜一搜」整行迁入+搜索源
	 * 下拉【B-2】/双车道日额度/robots 开关/独立凭据测试按钮【B-3】/清缓存按钮）；
	 * 未配置 notice 条件显隐（【B-4】enabled 且 AI 判定凭证链为空）；inert notice
	 * 条件显隐（site_field 收集关闭=增量面失效）。
	 *
	 * @param array<string, mixed> $settings 设置树。
	 * @return void
	 */
	private static function render_url_audit_card( array $settings ): void {
		$ua       = isset( $settings['url_audit'] ) && is_array( $settings['url_audit'] ) ? $settings['url_audit'] : array();
		$enabled  = ! empty( $ua['enabled'] );
		$profile  = (string) ( $ua['profile'] ?? 'conservative' );
		$site_off = empty( $settings['comments']['site_field']['enabled'] ); // 收集关闭=增量面无对象.

		/* 【M6·批B·B-4】AI 判定凭证链空判定（UrlAuditWorker::provider_config 同源语义）：
		 * custom 且独立 key 非空=独立凭证；否则回落云审 DeepSeek key——两者皆空=链空。 */
		$ua_provider      = is_array( $ua['provider'] ?? null ) ? $ua['provider'] : array();
		$ua_provider_mode = (string) ( $ua_provider['mode'] ?? 'inherit' );
		$ua_own_key       = trim( (string) ( $ua_provider['api_key'] ?? '' ) );
		$cloud_ds_key     = (string) ( $settings['review']['cloud']['deepseek']['api_key'] ?? '' );
		$chain_empty      = ( ! ( 'custom' === $ua_provider_mode && '' !== $ua_own_key ) ) && '' === trim( $cloud_ds_key );
		?>
		<section class="wk-card" id="wk-review-url-audit-card">
			<h2><?php esc_html_e( '网址核查（增强层——关闭时评论防护照常，零行为变化）', 'wink-gatekeeper' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '启用网址核查', 'wink-gatekeeper' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'enabled' ) ) ); ?>" value="0" />
							<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'enabled' ) ) ); ?>" value="1" <?php checked( $enabled ); ?>>
							<?php esc_html_e( '评论者填的网站地址，先试着打开看一眼，再交给 AI 判定该网站是否有问题（赌博/诈骗/钓鱼等）', 'wink-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( '只放大严：网址没问题也不会替评论放行。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><span id="wk-url-audit-profile-group"><?php esc_html_e( '处置档位', 'wink-gatekeeper' ); ?></span></th>
					<td>
						<dl>
							<dt><label><input type="radio" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'profile' ) ) ); ?>" value="conservative" <?php checked( $profile, 'conservative' ); ?>> <?php esc_html_e( '保守（默认）——违规不自动进垃圾箱，只出报告和标记；网址没看清的会先进待审等你判定', 'wink-gatekeeper' ); ?></label></dt>
							<dt><label><input type="radio" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'profile' ) ) ); ?>" value="balanced" <?php checked( $profile, 'balanced' ); ?>> <?php esc_html_e( '均衡（推荐）——证据确凿的自动进垃圾箱（可一键撤销），其余进待审等你复核', 'wink-gatekeeper' ); ?></label></dt>
							<dt><label><input type="radio" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'profile' ) ) ); ?>" value="aggressive" <?php checked( $profile, 'aggressive' ); ?>> <?php esc_html_e( '激进——拿不太准的也自动进垃圾箱，没看清的进待审；垃圾评论洪水期应急用，用完建议切回来', 'wink-gatekeeper' ); ?></label></dt>
						</dl>
						<p class="description"><?php esc_html_e( '「证据确凿」的门槛由系统掌握，界面不提供旋钮——只放大严。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
			</table>

			<details class="wk-guide" id="wk-url-audit-advanced">
				<summary><?php esc_html_e( '高级（默认收起）', 'wink-gatekeeper' ); ?></summary>
				<table class="form-table" role="presentation">
					<?php /* 【M6·批B·B-1】「核查前先搜一搜」整行自主体 form-table 迁入本折叠
					 * （N2：搜索属高级配置；字段 name 零变化=schema 零变化；bocha.enabled
					 * hidden 0 伴随域照查）。 */ ?>
					<tr id="wk-url-audit-bocha-row">
						<th scope="row"><?php esc_html_e( '核查前先搜一搜', 'wink-gatekeeper' ); ?></th>
						<td>
							<label>
								<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'bocha', 'enabled' ) ) ); ?>" value="0" />
								<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'bocha', 'enabled' ) ) ); ?>" value="1" <?php checked( ! empty( $ua['bocha']['enabled'] ) ); ?>>
								<?php esc_html_e( '打不开网页时，搜索结果也能当判定线索，减少「没看清」的次数（默认关）', 'wink-gatekeeper' ); ?>
							</label>
							<?php self::password_field( array( 'url_audit', 'bocha', 'api_key' ), '' !== (string) ( $ua['bocha']['api_key'] ?? '' ), '博查 API Key' ); ?>
							<p>
								<label for="wk-url-audit-search-source"><?php esc_html_e( '搜索源', 'wink-gatekeeper' ); ?></label>
								<select id="wk-url-audit-search-source" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'search_source' ) ) ); ?>">
									<option value="bocha" <?php selected( (string) ( $ua['search_source'] ?? 'bocha' ), 'bocha' ); ?>><?php esc_html_e( '博查网页搜索（默认——需上方博查 Key，按次计费独立计量）', 'wink-gatekeeper' ); ?></option>
									<option value="deepseek" <?php selected( (string) ( $ua['search_source'] ?? 'bocha' ), 'deepseek' ); ?>><?php esc_html_e( 'DeepSeek 网页搜索（用上方 LLM提供商的凭证——按成功次计费）', 'wink-gatekeeper' ); ?></option>
								</select>
							</p>
							<p class="description"><?php esc_html_e( '选博查需在博查开放平台（bocha.cn）申请 API Key（按次计费、独立计量）；选 DeepSeek 则跟随上方 LLM提供商的凭证（无需额外 Key，按成功次数计费）。', 'wink-gatekeeper' ); ?></p>
						</td>
					</tr>
				</table>
				<table class="form-table" role="presentation">
					<?php
					/* 【M5 修复批·D4】网址核查判定凭证（独立/回落云审二态）：无 checkbox
					 * 故 hidden 0 伴随域不适用；mode 显式枚举使「清空独立 key 回落」在 UI
					 * 上可逆（D1 注）。条件显隐=custom_header 联动范式（PHP 初始 hidden +
						 * admin-brand.js change 绑定；DOM 锚 wk-url-audit-provider-mode /
						 * wk-url-audit-provider-rows）。独立凭证只用于网址核查（与评论云审
						 * 配额/健康态互不影响）。【M6·批E】判定模型行改手填 text——空=标准档
						 * 回落语义保留（provider_config 回落同源；行内注释同步改写）。 */
					?>
						<tr>
							<!-- 【M6·批H·H-5】行标签改「LLM提供商」（用户 2026-11-16）；
							 * inherit 选项文案与抓取服务商统一为「跟随云端审核」语义（§0-5）。 -->
							<th scope="row"><label for="wk-url-audit-provider-mode"><?php esc_html_e( 'LLM提供商', 'wink-gatekeeper' ); ?></label></th>
							<td>
								<select id="wk-url-audit-provider-mode" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'provider', 'mode' ) ) ); ?>">
									<option value="inherit" <?php selected( $ua_provider_mode, 'inherit' ); ?>><?php esc_html_e( '跟随云端审核（默认——云端审核为 DeepSeek 时使用与云端审核相同的配置进行判定）', 'wink-gatekeeper' ); ?></option>
									<option value="custom" <?php selected( $ua_provider_mode, 'custom' ); ?>><?php esc_html_e( '独立凭证（网址核查专用）', 'wink-gatekeeper' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( '跟随（默认）：云端审核为 DeepSeek 时，判定与网页抓取都用 DeepSeek（同一份凭证）；云端审核是其他驱动时，判定走其配置、网页抓取不会自动启用（抓取未配置——需要抓取请在下方「网页抓取服务商」手动选择）。独立：网址核查用单独的 DeepSeek 凭证（与评论云端审核互不影响，想用不同的账号/额度时才需要选独立）。', 'wink-gatekeeper' ); ?></p>
							</td>
						</tr>
				</table>
				<table class="form-table" role="presentation" id="wk-url-audit-provider-rows"<?php echo 'custom' === $ua_provider_mode ? '' : ' hidden'; ?>>
					<tr>
						<th scope="row"><span class="screen-reader-text"><?php esc_html_e( '独立凭证 API Key', 'wink-gatekeeper' ); ?></span></th>
						<td>
							<?php self::password_field( array( 'url_audit', 'provider', 'api_key' ), '' !== (string) ( $ua_provider['api_key'] ?? '' ), '网址核查 API Key' ); ?>
							<p class="description"><?php esc_html_e( 'DeepSeek 开放平台的 API Key（与 Tab③ 云端审核可不同账号）。', 'wink-gatekeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wk-url-audit-provider-base-url"><?php esc_html_e( '独立凭证接入地址', 'wink-gatekeeper' ); ?></label></th>
						<td>
							<input type="url" id="wk-url-audit-provider-base-url" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'provider', 'base_url' ) ) ); ?>"
								value="<?php echo esc_attr( (string) ( $ua_provider['base_url'] ?? '' ) ); ?>" class="regular-text code"
								placeholder="https://api.deepseek.com" />
							<p class="description"><?php esc_html_e( '留空用官方地址（一般不用改）。', 'wink-gatekeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wk-url-audit-provider-model"><?php esc_html_e( '独立凭证判定模型', 'wink-gatekeeper' ); ?></label></th>
						<td>
							<?php /* 【M6·批E·E-4】改手填 text（原 DeepSeek 静态两选项 select 退役——
								独立端点可为任意 OpenAI 兼容端点）；空=标准档回落语义保留
								（provider_config 空 model 回落 deepseek-flash 同源——UI 如实
								显示空值不虚构已存模型名）。 */ ?>
							<input type="text" id="wk-url-audit-provider-model" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'provider', 'model' ) ) ); ?>"
								value="<?php echo esc_attr( (string) ( $ua_provider['model'] ?? '' ) ); ?>" class="regular-text"
								placeholder="<?php esc_attr_e( '手填模型名（OpenAI 兼容端点填其支持的模型）', 'wink-gatekeeper' ); ?>" />
							<p class="description"><?php esc_html_e( '模型名须与端点一致；留空=标准档（deepseek-flash 回落——DeepSeek 端点可直接留空）。', 'wink-gatekeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '测试独立凭证', 'wink-gatekeeper' ); ?></th>
						<td>
							<?php /* 【M6·批B·B-3】独立凭据测试（N4 范围边界：DeepSeek 协议族同用
							 * 余额探测，base_url 跟随其配置）。【M6·批E·E-4】按 base_url 分流：
							 * 官方地址=余额探测 / 其他地址（中转站/自建网关）=模型列表探活
							 * （泛端点无标准余额 API）。 */ ?>
							<button type="button" class="button" id="wk-url-audit-provider-test-btn"
								data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX_TEST_CREDENTIALS_URL_AUDIT ) ); ?>">
								<?php esc_html_e( '测试凭据', 'wink-gatekeeper' ); ?>
							</button>
							<span id="wk-url-audit-provider-test-result" aria-live="polite"></span>
							<p class="description"><?php esc_html_e( '官方地址=余额探测（零费用，显示余额）；其他地址=模型列表探活（零 token）。绿 = 凭据可用；红 = 密钥被拒或连不上。结果不落库。', 'wink-gatekeeper' ); ?></p>
						</td>
					</tr>
				</table>
				<?php
				/* 【M6·批D·D-2】网页抓取服务商三态（N1 抓取服务商化，§4 C-2）：全部在高级
				 * 折叠区。抓取专用 Key 行组条件显隐（mode=deepseek 才显示——PHP 初始 hidden +
				 * admin-brand.js change 绑定，provider rows 联动范式同款；DOM 锚
				 * wk-url-audit-fetcher-mode / wk-url-audit-fetcher-rows）。 */
				$fetcher_mode = (string) ( $ua['fetcher']['mode'] ?? 'inherit' );
				?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wk-url-audit-fetcher-mode"><?php esc_html_e( '网页抓取服务商', 'wink-gatekeeper' ); ?></label></th>
							<td>
								<select id="wk-url-audit-fetcher-mode" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'fetcher', 'mode' ) ) ); ?>">
									<!-- 【M6·批H·H-5】inherit 文案与 LLM提供商行统一「跟随云端审核」语义
									 * +末句保留抓取自身动作说明；【M6·批H·H-1】新语义如实入文案。 -->
									<option value="inherit" <?php selected( $fetcher_mode, 'inherit' ); ?>><?php esc_html_e( '跟随云端审核（默认——云端审核为 DeepSeek 时使用与云端审核相同的配置进行抓取；其他驱动时抓取未配置，本站直抓不自动启用）', 'wink-gatekeeper' ); ?></option>
									<option value="self" <?php selected( $fetcher_mode, 'self' ); ?>><?php esc_html_e( '本站直接打开（免费）', 'wink-gatekeeper' ); ?></option>
									<option value="deepseek" <?php selected( $fetcher_mode, 'deepseek' ); ?>><?php esc_html_e( 'DeepSeek 网页抓取（按次计费 ¥5/1000 次）', 'wink-gatekeeper' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( '选 DeepSeek 抓取时，本站服务器不再直接打开评论者的网址——由 DeepSeek 代为抓取正文（对打不开/反爬的站点成功率高很多，如某些成人站、强反爬站），按成功次数计费。本站直接打开不花钱但会暴露源站 IP，且遇到反爬站容易「没看清」——默认不自动启用，需在此手动选择。', 'wink-gatekeeper' ); ?></p>
							</td>
						</tr>
					</table>
				<table class="form-table" role="presentation" id="wk-url-audit-fetcher-rows"<?php echo 'deepseek' === $fetcher_mode ? '' : ' hidden'; ?>>
					<tr>
						<th scope="row"><span class="screen-reader-text"><?php esc_html_e( '抓取专用 DeepSeek Key', 'wink-gatekeeper' ); ?></span></th>
						<td>
							<?php self::password_field( array( 'url_audit', 'fetcher', 'api_key' ), '' !== (string) ( $ua['fetcher']['api_key'] ?? '' ), '抓取专用 DeepSeek Key' ); ?>
							<p class="description"><?php esc_html_e( '留空=用上方「LLM提供商」的 Key（一般不用填）。想给网页抓取单独用一个 DeepSeek 账号时才填。', 'wink-gatekeeper' ); ?></p>
						</td>
					</tr>
				</table>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( '日额度', 'wink-gatekeeper' ); ?></th>
						<td>
							<label><?php esc_html_e( '批量车道', 'wink-gatekeeper' ); ?> <input type="number" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'batch_quota' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $ua['batch_quota'] ?? 50 ) ); ?>" class="small-text" min="0"> <?php esc_html_e( '次', 'wink-gatekeeper' ); ?></label>
							<label><?php esc_html_e( '新评论车道', 'wink-gatekeeper' ); ?> <input type="number" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'incr_quota' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $ua['incr_quota'] ?? 100 ) ); ?>" class="small-text" min="0"> <?php esc_html_e( '次（0=不限量）', 'wink-gatekeeper' ); ?></label>
							<p class="description"><?php esc_html_e( '额度用完：批量车道先停、新评论照常防护，次日自动恢复。', 'wink-gatekeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'robots 禁爬声明', 'wink-gatekeeper' ); ?></th>
						<td>
							<label><input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'robots_comply' ) ) ); ?>" value="0" /><input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'url_audit', 'robots_comply' ) ) ); ?>" value="1" <?php checked( ! empty( $ua['robots_comply'] ) ); ?>> <?php esc_html_e( '遵守网站的 robots 禁爬声明（默认关——开了会多「没看清」）', 'wink-gatekeeper' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '核查记录缓存', 'wink-gatekeeper' ); ?></th>
						<td>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . \WinkGatekeeper\UrlAudit\UrlAuditWorker::ACTION_PURGE ), \WinkGatekeeper\UrlAudit\UrlAuditWorker::ACTION_PURGE ) ); ?>"
								onclick="return confirm('<?php echo esc_js( __( '清空全部网址核查记录？下次遇到同一网址会重新核查（消耗额度）。', 'wink-gatekeeper' ) ); ?>');"><?php esc_html_e( '清空网址核查记录缓存', 'wink-gatekeeper' ); ?></a>
						</td>
					</tr>
				</table>
			</details>

			<?php /* 【M6·批H·H-1】网页抓取未配置黄条（U-1 式——§0-1 用户拍板「本站直抓永不
			 * 自动启用」配套可见性）：enabled 且 fetcher.mode 解析=unconfigured（inherit+
			 * 云端审核非 DeepSeek/未配置）才渲染；显式 self/inherit+DS/功能关不渲染
			 * （范式=B-4 notice 同款，折叠区外常显+深链高级区）。 */ ?>
			<?php if ( $enabled && 'unconfigured' === \WinkGatekeeper\UrlAudit\UrlAuditWorker::fetcher_mode( $settings ) ) : ?>
			<div class="notice notice-warning inline" id="wk-url-audit-fetcher-unconfigured-notice"><p>
				<?php esc_html_e( '网页抓取未配置——本站直抓因暴露源站 IP 默认不自动启用，请在', 'wink-gatekeeper' ); ?>
				<a href="#wk-url-audit-advanced"><?php esc_html_e( '高级区的「网页抓取服务商」手动选择', 'wink-gatekeeper' ); ?></a>。
			</p></div>
			<?php endif; ?>

			<?php /* 【M6·批H2·H2-2】DeepSeek 抓取连续未成功风险黄条（fetch_streak_notice_data
			 * 读取器三条件：enabled+达阈 5+48h 新鲜——陈旧静默；动态 N printf 注入=H-2
			 * 动态文案先例。计数口径见 UrlAuditWorker::record_fetch_streak 头注）。 */ ?>
			<?php $fetch_streak = \WinkGatekeeper\UrlAudit\UrlAuditWorker::fetch_streak_notice_data( $settings ); ?>
			<?php if ( null !== $fetch_streak ) : ?>
			<div class="notice notice-warning inline" id="wk-url-audit-fetch-streak-notice"><p>
				<?php printf( esc_html__( 'DeepSeek 网页抓取已连续 %d 次未成功——网址核查可能无法正常判定，请检查凭据、余额与端点状态。', 'wink-gatekeeper' ), (int) $fetch_streak['n'] ); ?>
			</p></div>
			<?php endif; ?>

			<?php /* 【M6·批B·B-4】网址核查未配置 notice（条件显隐——U-1 缺口）：enabled 且
			 * AI 判定凭证链为空才渲染；配置齐不渲染（范式=UI-1-2 云审未配置 notice 同款）。 */ ?>
			<?php if ( $enabled && $chain_empty ) : ?>
			<div class="notice notice-warning inline" id="wk-url-audit-unconfigured-notice"><p>
				<?php esc_html_e( '网址核查已启用，但 AI 判定（DeepSeek）还没有配置——核查无法完成判定。', 'wink-gatekeeper' ); ?>
				<a href="#wk-review-deepseek-card"><?php esc_html_e( '到上方 DeepSeek 配置区填写 API Key', 'wink-gatekeeper' ); ?></a><?php esc_html_e( '，或在', 'wink-gatekeeper' ); ?><a href="#wk-url-audit-advanced"><?php esc_html_e( '下方「高级」里为网址核查单独配置', 'wink-gatekeeper' ); ?></a>。
			</p></div>
			<?php endif; ?>

			<?php if ( $site_off ) : ?>
			<div class="notice notice-warning inline" id="wk-url-audit-inert-notice"><p>
				<?php esc_html_e( '网站字段收集已关闭——新评论没有网址可核查，本功能对新评论自动失效；存量网址仍可在工具页「存量网址核查」中处理。', 'wink-gatekeeper' ); ?>
			</p></div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * cron tick 重排（start/resume 侧；幂等先清后排）。
	 *
	 * @return void
	 */
	private static function audit_schedule_tick(): void {
		self::audit_unschedule_tick();
		wp_schedule_event( time() + 60, \WinkGatekeeper\Audit\ExistingAuditTask::CRON_INTERVAL, \WinkGatekeeper\Audit\ExistingAuditTask::HOOK_TICK );
	}

	/**
	 * cron tick 清除（pause/reset 侧）。
	 *
	 * @return void
	 */
	private static function audit_unschedule_tick(): void {
		$ts = wp_next_scheduled( \WinkGatekeeper\Audit\ExistingAuditTask::HOOK_TICK );
		if ( $ts ) {
			wp_unschedule_event( $ts, \WinkGatekeeper\Audit\ExistingAuditTask::HOOK_TICK );
		}
	}

	/* -------------------------------------------------------------------
	 * Tab③ 网址核查（【M5·U5】UI 设计块 2-B——配置面；清缓存动作）
	 * ------------------------------------------------------------------ */

	/**
	 * 网址核查清缓存处理器（admin_post_wink_gatekeeper_url_audit_purge——Tab③ 高级折叠）：
	 * manage_options+nonce 双闸；清空后重定向回 Tab③（URL 审核语义=下次遇到同一网址重新核查）。
	 *
	 * @return void
	 */
	public static function handle_url_audit_purge(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'wink-gatekeeper' ), 403 );
		}
		check_admin_referer( \WinkGatekeeper\UrlAudit\UrlAuditWorker::ACTION_PURGE );

		\WinkGatekeeper\UrlAudit\UrlAuditCache::purge_all();
		wp_safe_redirect( self::tab_url( 'review' ) );
		exit;
	}

	/**
	 * host 级清缓存处理器（Tab⑥ 报告卡「重新核查此站」——站长举报误判入口，nw §4.3）：
	 * manage_options+nonce 双闸；PHP 端 host 比对（防宽匹配误伤他站缓存）。
	 *
	 * @return void
	 */
	public static function handle_url_audit_host_purge(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'wink-gatekeeper' ), 403 );
		}
		check_admin_referer( 'wink_gatekeeper_url_host_purge' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- check_admin_referer 已验。
		$host = isset( $_GET['host'] ) && is_scalar( $_GET['host'] ) ? (string) $_GET['host'] : '';
		$host = sanitize_text_field( $host );
		if ( '' !== $host ) {
			\WinkGatekeeper\UrlAudit\UrlAuditCache::purge_host( $host );
		}
		// 【M6·批C·C-3】host 表随报告迁址 Tab⑦——回跳同址（原 stats）。
		wp_safe_redirect( self::tab_url( 'tools' ) );
		exit;
	}

	/**
	 * 【M6·P1-3】存量网址核查显式回捞处理器（admin_post_wink_gatekeeper_url_audit_backlog
	 * ——Tab⑦ 操作卡「开始存量网址核查」）：manage_options+nonce 双闸；把「有 URL 且无
	 * 判定 meta」的存量评论一次性并入批量队列（零外呼零判定——消费走批量车道日额度
	 * 50/日 pacing，额度尽暂停黄条语义照旧）；完成回 Tab⑦（D6 分工：操作卡在此卡）。
	 *
	 * @return void
	 */
	public static function handle_url_audit_backlog(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'wink-gatekeeper' ), 403 );
		}
		check_admin_referer( \WinkGatekeeper\UrlAudit\UrlAuditWorker::ACTION_BACKLOG );

		$worker = new \WinkGatekeeper\UrlAudit\UrlAuditWorker( self::settings() );
		$added  = $worker->enqueue_batch_backlog();
		// 【M6·批C·C-3】点击反馈：回跳携带本次已入队数（Tab⑦ 立即呈现「已入队 N 条·
		// 开始核查」——用户 F6「点击运行了但零展示零反馈」）。
		wp_safe_redirect( add_query_arg( array( 'wk-url-backlog' => (string) max( 0, $added ) ), self::tab_url( 'tools' ) ) );
		exit;
	}

	/* -------------------------------------------------------------------
	 * Tab⑤ 文案工坊（03 Tab⑤：16 输入 + 占位符帮助 + 恢复默认）
	 * ------------------------------------------------------------------ */

	private static function render_tab_copy(): void {
		$settings = self::settings();
		$defaults = Copywork::defaults();
		?>
		<section class="wk-card">
			<h2><?php esc_html_e( '全局：联系邮箱', 'wink-gatekeeper' ); ?></h2>
			<p class="description">
				<input type="email" name="<?php echo esc_attr( self::field_name( array( 'brand', 'contact_email' ) ) ); ?>" value="<?php echo esc_attr( (string) $settings['brand']['contact_email'] ); ?>" class="regular-text" placeholder="webmaster@example.com" />
				<?php esc_html_e( '出现在 captcha_failed / ip_malicious 等默认文案的「联系站长」处（{contact_email} 占位符）。', 'wink-gatekeeper' ); ?>
			</p>
		</section>

		<section class="wk-card">
			<h2><?php esc_html_e( '拦截文案与页面标题', 'wink-gatekeeper' ); ?></h2>
			<p class="wk-muted">
				<?php
				// 语气三原则常驻小抄（03 Tab⑤）——不折叠常显。
				esc_html_e( '语气三原则：只调侃行为 / 留台阶 / 带下一步。', 'wink-gatekeeper' );
				?>
			</p>
			<!-- 【M4UX G3】拦截文案组默认整组折叠（details/wk-guide 既有形态）——
			 * 占位符说明区随组折叠；语气三原则小抄不折叠常显。 -->
			<details class="wk-guide" id="wk-copy-advanced">
				<summary><?php esc_html_e( '高级设置（拦截文案 / 页面标题 / 占位符说明）', 'wink-gatekeeper' ); ?></summary>
			<dl class="wk-placeholder-list">
				<!-- 【M4UX UI-3-1·定稿 §3】占位符逐个定义（原一行罗列退役）；{rule} 附 Labels 映射示例。 -->
				<dt><code>{min_length}</code> / <code>{max_length}</code></dt>
				<dd><?php esc_html_e( '本地字数闸的实际判定值（Tab③「字数下限 / 字数上限」当前配置）。', 'wink-gatekeeper' ); ?></dd>
				<dt><code>{event_no}</code></dt>
				<dd><?php esc_html_e( '本次拦截的事件编号——可拿它去 Tab⑥ 事件流对号查详情。', 'wink-gatekeeper' ); ?></dd>
				<dt><code>{rule}</code></dt>
				<dd>
					<?php esc_html_e( '命中规则键的人话名（由 Labels 映射表翻译，未收录键原样显示键名；命中的具体关键词仅站长在事件流可见）。映射示例：', 'wink-gatekeeper' ); ?>
					<!-- 【M4UX F3-4】映射示例独立样式行（术语 code 与示例排版分离）。 -->
					<code class="wk-example-line"><?php echo esc_html( 'remote_semantic → 「服务商拒绝了（查配置或欠费）」 · banned_word → 「命中关键词黑名单」' ); ?></code>
				</dd>
				<dt><code>{time}</code></dt>
				<dd><?php esc_html_e( '拦截发生时间（站点时区）。', 'wink-gatekeeper' ); ?></dd>
				<dt><code>{contact_email}</code></dt>
				<dd><?php esc_html_e( '上方「联系邮箱」的值（未填写则该占位符整句省略）。', 'wink-gatekeeper' ); ?></dd>
			</dl>
			<button type="button" class="button" id="wk-copy-restore-defaults" data-confirm="<?php echo esc_attr__( '确认恢复全部文案为默认？未保存的修改将丢失。', 'wink-gatekeeper' ); ?>">
				<?php esc_html_e( '恢复默认文案', 'wink-gatekeeper' ); ?>
			</button>

			<table class="form-table" role="presentation">
				<?php foreach ( Copywork::keys() as $key ) : ?>
					<tr>
						<th scope="row">
							<code><?php echo esc_html( $key ); ?></code><br />
							<span class="wk-muted"><?php echo esc_html( self::copy_scene_label( $key ) ); ?></span>
						</th>
						<td>
							<label class="screen-reader-text" for="wk-copy-message-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( sprintf( /* translators: %s: 规则键 */ __( '%s 拦截文案', 'wink-gatekeeper' ), $key ) ); ?></label>
							<textarea id="wk-copy-message-<?php echo esc_attr( $key ); ?>" class="large-text" rows="2"
								name="<?php echo esc_attr( self::field_name( array( 'copy', 'messages', $key ) ) ); ?>"
								placeholder="<?php echo esc_attr( $defaults['messages'][ $key ] ); ?>"><?php echo esc_textarea( (string) $settings['copy']['messages'][ $key ] ); ?></textarea>
							<label class="screen-reader-text" for="wk-copy-title-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( sprintf( /* translators: %s: 规则键 */ __( '%s 页面标题', 'wink-gatekeeper' ), $key ) ); ?></label>
							<input type="text" id="wk-copy-title-<?php echo esc_attr( $key ); ?>" class="regular-text"
								name="<?php echo esc_attr( self::field_name( array( 'copy', 'titles', $key ) ) ); ?>"
								placeholder="<?php echo esc_attr( $defaults['titles'][ $key ] ); ?>"
								value="<?php echo esc_attr( (string) $settings['copy']['titles'][ $key ] ); ?>" />
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			</details>
		</section>

		<section class="wk-card">
			<h2><?php esc_html_e( '拦截页自定义', 'wink-gatekeeper' ); ?></h2>
			<p class="wk-muted">
				<?php
				// 【v1.5·B1】站长自定义拦截页覆盖位（Wordfence blockCustomText 先例）。
				esc_html_e( '作用于品牌整页拦截页的标题与正文（全部场景共用）。留空 = 使用各场景默认文案。占位符：{event_no} {rule} {time} {contact_email}——{rule} 只显示规则键名。', 'wink-gatekeeper' );
				?>
			</p>
			<p>
				<!-- 【M4UX G3】「查看效果」——新标签页打开拦截页实渲染预览（admin action
				 * wink_gatekeeper_die_preview：manage_options+nonce 双闸，零落库零事件零统计）。 -->
				<a class="button button-secondary" id="wk-die-preview" target="_blank" rel="noopener"
					href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_DIE_PREVIEW ), self::ACTION_DIE_PREVIEW ) ); ?>">
					<?php esc_html_e( '查看效果', 'wink-gatekeeper' ); ?>
				</a>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wk-copy-die-page-title"><?php esc_html_e( '拦截页标题', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="text" id="wk-copy-die-page-title" class="regular-text"
							name="<?php echo esc_attr( self::field_name( array( 'copy', 'die_page_title' ) ) ); ?>"
							value="<?php echo esc_attr( (string) $settings['copy']['die_page_title'] ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wk-copy-die-page-message"><?php esc_html_e( '拦截页正文', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<textarea id="wk-copy-die-page-message" class="large-text" rows="3"
							name="<?php echo esc_attr( self::field_name( array( 'copy', 'die_page_message' ) ) ); ?>"><?php echo esc_textarea( (string) $settings['copy']['die_page_message'] ); ?></textarea>
					</td>
				</tr>
			</table>
		</section>

			<section class="wk-card">
				<h2><?php esc_html_e( '云端标签映射（只读预览）', 'wink-gatekeeper' ); ?></h2>
				<p class="wk-muted">
					<?php
					// 【v1.5·F4-3】映射表只读预览（落点=Copywork 键值表，映射键名不可改）；
					// 文案可经 wink_gatekeeper_error_messages filter 覆盖，未收录标签原样显示。
					esc_html_e( '拦截页「命中规则」证据行对云端驱动的原始 Label,Score 做人类可读映射（驱动人类名前缀 + 标签中文 + 置信）。未收录标签原样显示。', 'wink-gatekeeper' );
					?>
				</p>
				<p class="description">
					<!-- 【M4UX UI-3-2·定稿 §3】统一表说明行（用户提问「云端标签映射是否跨驱动」——是）。 -->
					<?php esc_html_e( '此表为全部云端驱动共用的一张映射表：各驱动返回的 reason 已统一为 Label,Score 形态，故一张表同时服务腾讯 TMS / 阿里 Green / 百度全部云端驱动，无需按驱动分表。', 'wink-gatekeeper' ); ?>
				</p>
			<table class="form-table" role="presentation">
				<?php foreach ( Copywork::cloud_labels() as $label_key => $zh ) : ?>
					<tr>
						<th scope="row"><code><?php echo esc_html( $label_key ); ?></code></th>
						<td><?php echo esc_html( $zh ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		</section>
		<?php
	}

	/**
	 * 文案键的场景说明（展示用途，随 Copywork::keys() 派生）。
	 *
	 * @param string $key 文案键。
	 * @return string
	 */
	private static function copy_scene_label( string $key ): string {
		$labels = array(
			'too_short'       => __( '字数闸：评论过短', 'wink-gatekeeper' ),
			'too_long'        => __( '字数闸：评论过长', 'wink-gatekeeper' ),
			'no_chinese'      => __( '中文闸：缺中文', 'wink-gatekeeper' ),
			'banned_word'     => __( '关键词/正则命中', 'wink-gatekeeper' ),
			'captcha_failed'  => __( '验证码核验失败', 'wink-gatekeeper' ),
			'captcha_missing' => __( '未完成验证就提交', 'wink-gatekeeper' ),
			'ip_malicious'    => __( 'IP 恶意分拦截', 'wink-gatekeeper' ),
			'cloud_error'     => __( '云审核故障已入待审', 'wink-gatekeeper' ),
		);
		return $labels[ $key ] ?? $key;
	}

	/* -------------------------------------------------------------------
	 * Tab⑥ 统计与导出（03 Tab⑥ v1.3：分区计数卡 + 最近拦截明细 + 黄条 + 重置 AJAX）
	 * ------------------------------------------------------------------ */

	private static function render_tab_stats(): void {
		$settings = self::settings();
		$cache    = \WinkGatekeeper\Stats\StatsCache::get();

		/* UI 批 1（DESIGN-UI-tab6 A 提案三口径骨架）：五段编排——速览条→评论防护→
		 * 登录防护→报错历史→拦截记录流水→统计设置·清零；每段「结果→归因→案例」节奏。
		 * 批 2 已接线：登录明细卡（N1）/全闸报错时间线（N2+N3）/服务商卡出错格（N6+N7a）
		 * ——占位提示全部移除。UI-FIX B（2026-11-12 用户反馈）：两级标题制全页零序号
		 * （段标题 wk-sec-title > 段内小标题 wk-sub-title），配置指引收敛段标题单入口。 */
		$registry      = null !== self::$driver_registry ? self::$driver_registry : null;
		$ip_driver_ids = array_keys( null !== $registry ? $registry->by_type( 'ip' ) : array() );
		$split_today   = \WinkGatekeeper\Stats\InterceptDigest::review_split( $cache, $ip_driver_ids, false );

		// 累计窗口（last_reset 纯展示——从未清零为 0，不标注起点）。
		$last_reset = isset( $cache['last_reset'] ) && is_numeric( $cache['last_reset'] ) ? (int) $cache['last_reset'] : 0;
		$since_date = $last_reset > 0 ? date_i18n( 'Y-m-d', $last_reset ) : '';

		self::render_stats_overview_strip( $cache, $settings, $split_today, $since_date );
		self::render_stats_comment_section( $cache, $split_today, $since_date );
		// 【M6·批C·C-3】历史评论体检的报告卡退役迁「工具」页（用户 2026-11-16 反馈
		// 「统计页展示消息已够杂乱」）——本页零残留；运行中黄条保留在总览页通栏（报告
		// 迁址后其入口链接随之改指工具页）。相关指引文案同步改指工具页存量网址核查。
		self::render_stats_login_section( $cache, $settings );
		self::render_stats_fault_section();
		self::render_stats_records_card();
		self::render_stats_settings_card( $settings );
	}

	/**
	 * 速览条（全站一眼——DESIGN-UI-tab6 §6：今日拦下/转人工复核/出错 + 累计窗口 + spam 黄条原位）。
	 *
	 * 口径：拦下=by_gate reject 全闸求和（次）；转人工复核=review_split 排除 IP 信誉强制
	 * 验证码（条——review 键只存在于评论门）；出错=by_driver error 求和（次）；
	 * 累计=total.reject + last_reset 窗口标注（P9 修复）。
	 *
	 * @param array<string, mixed>  $cache       StatsCache 树。
	 * @param array<string, mixed>  $settings    设置树。
	 * @param array{review: int, ip_review: int, ip_captcha: int} $split_today 待审拆分（今日）。
	 * @param string                $since_date  上次清零日期（''=从未清零）。
	 * @return void
	 */
	private static function render_stats_overview_strip( array $cache, array $settings, array $split_today, string $since_date ): void {
		$today_reject = 0;
		foreach ( (array) ( $cache['by_gate'] ?? array() ) as $counts ) {
			$today_reject += (int) ( is_array( $counts ) ? ( $counts['reject'] ?? 0 ) : 0 );
		}
		$today_error = 0;
		foreach ( (array) ( $cache['by_driver'] ?? array() ) as $counts ) {
			$today_error += (int) ( is_array( $counts ) ? ( $counts['error'] ?? 0 ) : 0 );
		}
		$review_n = (int) ( $split_today['review'] ?? 0 );

		// 留档量黄条数据（spam 计数 = 本插件留档且仍在垃圾分队）。
		$spam_count = \WinkGatekeeper\Stats\StatsCache::archived_spam_count();
		?>
		<section class="wk-card wk-card--metric" id="wk-stats-overview">
			<h2 class="wk-sec-title"><?php esc_html_e( '今日速览', 'wink-gatekeeper' ); ?></h2>
			<p class="wk-metric"><span class="wk-metric__num"><?php echo esc_html( (string) $today_reject ); ?></span></p>
			<p>
				<?php
				echo esc_html( sprintf(
					/* translators: 1: 今日拦下, 2: 转人工复核, 3: 出错 */
					__( '今日拦下 %1$d 次 · 转人工复核 %2$d 条 · 出错 %3$d 次', 'wink-gatekeeper' ),
					$today_reject,
					$review_n,
					$today_error
				) );
				?>
			</p>
			<p class="wk-muted"><?php echo esc_html( \WinkGatekeeper\Stats\Labels::cumulative_line( (string) number_format_i18n( (int) ( $cache['total']['reject'] ?? 0 ) ), '次', $since_date ) ); ?></p>
			<?php if ( empty( $settings['stats']['enabled'] ) ) : ?>
				<p class="wk-muted"><strong><?php esc_html_e( '计数已停用', 'wink-gatekeeper' ); ?></strong><?php esc_html_e( '——统计开关关闭期间的拦截不计入读数，事件表照常落库（拦截明细以本页为准）。', 'wink-gatekeeper' ); ?></p>
			<?php endif; ?>
		</section>

		<?php if ( \WinkGatekeeper\Stats\InterceptDigest::spam_warn( $spam_count ) ) : ?>
			<section class="wk-notice wk-notice--warn">
				<p>
					<?php
					echo esc_html( sprintf(
						/* translators: 1: 留档条数, 2: 阈值 */
						__( '本插件拦截留档已 %1$s 条（阈值 %2$s）——WP 原生垃圾清理（默认 30 天）会自动处理，或到垃圾评论分类手动清空；也可在设置中关闭留档。', 'wink-gatekeeper' ),
						number_format_i18n( $spam_count ),
						number_format_i18n( \WinkGatekeeper\Stats\InterceptDigest::SPAM_WARN_THRESHOLD )
					) );
					?>
					<a href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=spam' ) ); ?>"><?php esc_html_e( '查看垃圾评论分类', 'wink-gatekeeper' ); ?></a>
				</p>
			</section>
		<?php endif; ?>
		<?php
	}

	/**
	 * 评论防护段（口径一——DESIGN-UI-tab6 §2.1：今日概览→谁拦的→最近明细）。
	 *
	 * UI-FIX B：段标题两级制（wk-sec-title/wk-sub-title，零序号）；配置指引收敛到
	 * 段标题单入口（stats_config_hint——段内有未启用卡才出现）。
	 *
	 * @param array<string, mixed> $cache       StatsCache 树。
	 * @param array{review: int, ip_review: int, ip_captcha: int} $split_today 待审拆分（今日）。
	 * @param string               $since_date  上次清零日期。
	 * @return void
	 */
	private static function render_stats_comment_section( array $cache, array $split_today, string $since_date ): void {
		$comment       = \WinkGatekeeper\Stats\InterceptDigest::gate_counts( $cache, 'comment', false );
		$comment_total = \WinkGatekeeper\Stats\InterceptDigest::gate_counts( $cache, 'comment', true );
		$local_rules   = \WinkGatekeeper\Stats\InterceptDigest::local_rules_card( $cache );
		$cards         = self::stats_driver_cards( 'comment' );
		$ip_captcha    = (int) ( $split_today['ip_captcha'] ?? 0 );
		?>
		<section class="wk-card" id="wk-stats-comment">
			<h2 class="wk-sec-title"><?php esc_html_e( '评论防护', 'wink-gatekeeper' ); ?><?php echo self::stats_config_hint( $cards ); // phpcs:ignore WordPress.Security.EscapeOutput -- 内部组装已转义。 ?></h2>

			<h3 class="wk-sub-title"><?php esc_html_e( '今日概览', 'wink-gatekeeper' ); ?></h3>
			<p>
				<?php
				echo esc_html( sprintf(
					/* translators: 1: 拦下, 2: 转人工复核, 3: 出错 */
					__( '拦下 %1$d 条 · 转人工复核 %2$d 条 · 出错 %3$d 次', 'wink-gatekeeper' ),
					(int) ( $comment['reject'] ?? 0 ),
					(int) ( $split_today['review'] ?? 0 ),
					(int) ( $comment['error'] ?? 0 )
				) );
				?>
			</p>
			<p class="wk-muted">
				<?php
				echo esc_html( sprintf(
					/* translators: %s: 累计行（含窗口标注） */
					__( '转人工复核=进了待审区，等你处理。%s', 'wink-gatekeeper' ),
					\WinkGatekeeper\Stats\Labels::cumulative_line( (string) number_format_i18n( (int) ( $comment_total['reject'] ?? 0 ) ), '条', $since_date )
				) );
				?>
			</p>
			<p class="wk-muted"><?php echo esc_html( sprintf( /* translators: %d: IP 风控强制验证码次数 */ __( 'IP 信誉强制验证码 %d 次（单列口径——不计入上方转人工复核数字）。', 'wink-gatekeeper' ), $ip_captcha ) ); ?></p>

			<?php
			/* 【M5·U5】网址核查统计行（UI 块 3.3——与 IP 风控单列行同位形态；K8/K9/K10 口径）：
			 * 核查=评论者网址被打开看过（缓存命中不算）；自动标垃圾可撤销；待复核=URL 相关待审。 */
			$url_stats = ( new \WinkGatekeeper\UrlAudit\UrlAuditWorker( $settings ?? array() ) )->stats_line();
			if ( $url_stats['today_checked'] > 0 || $url_stats['auto_rejected'] > 0 || $url_stats['pending_review'] > 0 ) :
				?>
			<p id="wk-stats-url-line" class="wk-muted"><?php
				printf(
					esc_html__( '网址核查：今日核查 %1$d 条 · 自动标垃圾 %2$d 条 · 待你复核 %3$d 条（核查=网址被打开看过；自动标垃圾可撤销）', 'wink-gatekeeper' ),
					(int) $url_stats['today_checked'],
					(int) $url_stats['auto_rejected'],
					(int) $url_stats['pending_review']
				);
				?></p>
			<?php endif; ?>

			<?php
			/* 【M6·批B·B-2】网址核查搜索消耗行（统计页消耗段加行——deepseek_search 成功次；
			 * 数据门控渲染：零消耗整行不显，K 系口径与今日没用到同纪律；注：本函数体内
			 * 禁 circled 序号字样——零序号契约扫描函数体含注释）。 */
			$url_search_used = \WinkGatekeeper\Stats\Usage::daily_total_for( \WinkGatekeeper\Stats\Usage::get(), \WinkGatekeeper\Stats\Usage::KEY_DEEPSEEK_SEARCH );
			if ( $url_search_used > 0 ) :
				?>
			<p id="wk-stats-url-search-line" class="wk-muted"><?php
				printf(
					/* translators: %d: 今日 DeepSeek 搜索成功次数 */
					esc_html__( '网址核查搜索（DeepSeek）：今日 %1$d 次（按成功次数计费，打不开网页时的搜索线索）', 'wink-gatekeeper' ),
					(int) $url_search_used
				);
				?></p>
			<?php endif; ?>

			<?php
			/* 【M6·批D·D-5】网页抓取消耗行（K 系口径：抓取=第三方打开网页看过——DeepSeek
			 * Fetch 成功次；数据门控渲染：零消耗整行不显，同上纪律）。 */
			$url_fetch_used = \WinkGatekeeper\Stats\Usage::daily_total_for( \WinkGatekeeper\Stats\Usage::get(), \WinkGatekeeper\Stats\Usage::KEY_URL_AUDIT_FETCH );
			if ( $url_fetch_used > 0 ) :
				?>
			<p id="wk-stats-url-fetch-line" class="wk-muted"><?php
				printf(
					/* translators: %d: 今日 DeepSeek 网页抓取成功次数 */
					esc_html__( '网页抓取（DeepSeek 代开网页）：今日 %1$d 次（按成功次数计费——选「DeepSeek 网页抓取」时第三方打开网页看过）', 'wink-gatekeeper' ),
					(int) $url_fetch_used
				);
				?></p>
			<?php endif; ?>

			<h3 class="wk-sub-title"><?php esc_html_e( '谁拦的（按拦截链顺序）', 'wink-gatekeeper' ); ?></h3>
			<div class="wk-driver-card">
				<p><strong><?php echo esc_html( (string) $local_rules['label'] ); ?></strong> <span class="wk-muted"><?php esc_html_e( '——字数 / 关键词 / 中文检查，不花钱', 'wink-gatekeeper' ); ?></span></p>
				<p><?php echo esc_html( (string) $local_rules['line'] ); ?></p>
				<p class="wk-muted"><?php echo esc_html( (string) $local_rules['total_line'] ); ?></p>
			</div>

			<h4 class="wk-defense-group"><?php esc_html_e( '人机验证防线（第三方，按次计费）', 'wink-gatekeeper' ); ?></h4>
			<?php foreach ( $cards as $card ) : ?>
				<?php if ( 'captcha' === ( $card['type'] ?? '' ) ) { self::render_driver_card( $card ); } ?>
			<?php endforeach; ?>

			<h4 class="wk-defense-group"><?php esc_html_e( '云端审核防线（第三方，按次计费）', 'wink-gatekeeper' ); ?></h4>
			<?php foreach ( $cards as $card ) : ?>
				<?php if ( 'review' === ( $card['type'] ?? '' ) ) { self::render_driver_card( $card ); } ?>
			<?php endforeach; ?>

			<?php /* 【v1.7·M3-IP·定稿 §9】IP 信誉防线组——registry type='ip' 驱动卡（动作名 Labels「信誉查询」）。 */ ?>
			<h4 class="wk-defense-group"><?php esc_html_e( 'IP 信誉防线（第三方，按次计费）', 'wink-gatekeeper' ); ?></h4>
			<?php foreach ( $cards as $card ) : ?>
				<?php if ( 'ip' === ( $card['type'] ?? '' ) ) { self::render_driver_card( $card ); } ?>
			<?php endforeach; ?>
			<p class="wk-muted"><?php esc_html_e( '放行=推算值（今日动作数−拦下−转人工−出错）。', 'wink-gatekeeper' ); ?></p>

			<?php
			/* 【M6·批H·H-9】网址核查今日处置数据源（meta 反查——零新事件零写放大，事件表
			 * 拦截口径保持纯净 P1-4 不稀释）；【M6·批H3·H3-D】取值上移一次反查两处消费
			 * （H-9 头部行+H3-D「谁拦的」复核块）；零处置=两处均不渲染（K 系纪律）。 */
			$url_disposal = ( new \WinkGatekeeper\UrlAudit\UrlAuditWorker( $settings ?? array() ) )->today_disposal_line();
			/* 【M6·批H3·H3-D】网址核查复核处置块（手测反馈 D；用户拍板口径：转待审也算拦）
			 * ——「谁拦的（按拦截链顺序）」区尾殿后块（复核处置不在同步链上故殿后，区别于
			 * 上面四道同步防线）。 */
			if ( $url_disposal['total'] > 0 ) :
				?>
			<?php /* 【M6·批H4·H4-4】复核块套防线组标题（手测二轮⑭打回：裸卡与四道防线
			 * 「h4 分组+卡」节奏不齐观感诡异——对齐组结构）。 */ ?>
			<h4 class="wk-defense-group"><?php esc_html_e( '发布后复核防线（网址核查）', 'wink-gatekeeper' ); ?></h4>
			<div class="wk-driver-card" id="wk-stats-url-intercept-block">
				<p><strong><?php echo esc_html__( '网址核查（复核处置）', 'wink-gatekeeper' ); // phpcs:ignore WordPress.Security.EscapeOutput -- esc_html__ 已转义。 ?></strong> <span class="wk-muted"><?php esc_html_e( '——评论发布后复核网址；判违规与没看清都拦下等你复核', 'wink-gatekeeper' ); ?></span></p>
				<p><?php
					printf(
						/* translators: 1: spam 2: hold */
						esc_html__( '今日判进垃圾箱 %1$d 条 · 转待审 %2$d 条', 'wink-gatekeeper' ),
						(int) $url_disposal['spam'],
						(int) $url_disposal['hold']
					);
				?></p>
			</div>
			<?php endif; ?>

			<h3 class="wk-sub-title"><?php esc_html_e( '最近拦下的评论（最近 10 条）', 'wink-gatekeeper' ); ?></h3>
			<?php
			/* 【M6·批H·H-9】网址核查今日处置汇总行（区头部——「网址核查今日处置 N 条
			 * （垃圾箱 X·待审 Y）→ 查看详情」深链工具页报告锚）。 */
			if ( $url_disposal['total'] > 0 ) :
				?>
			<p id="wk-stats-url-disposal-line" class="wk-muted"><?php
				printf(
					/* translators: 1: total 2: spam 3: hold */
					esc_html__( '网址核查今日处置 %1$d 条（垃圾箱 %2$d · 待审 %3$d）', 'wink-gatekeeper' ),
					(int) $url_disposal['total'],
					(int) $url_disposal['spam'],
					(int) $url_disposal['hold']
				);
				?><a href="<?php echo esc_url( self::tab_url( 'tools' ) . '#wk-tools-url-audit-report' ); ?>"><?php esc_html_e( '→ 查看详情', 'wink-gatekeeper' ); ?></a></p>
			<?php endif; ?>
			<?php self::render_comment_recent_table(); ?>
		</section>
		<?php
	}

	/**
	 * 登录防护段（口径二——DESIGN-UI-tab6 §2.2：今日概览→三张门分账→服务商口径→明细）。
	 *
	 * UI-FIX B：段标题两级制零序号；配置指引收敛段标题单入口（「登录防护 · 去配置」）。
	 *
	 * @param array<string, mixed> $cache    StatsCache 树。
	 * @param array<string, mixed> $settings 设置树。
	 * @return void
	 */
	private static function render_stats_login_section( array $cache, array $settings ): void {
		// 窗口标注随配置动态（D3——login_adaptive.window_minutes）。
		$window = isset( $settings['captcha']['login_adaptive']['window_minutes'] ) && is_numeric( $settings['captcha']['login_adaptive']['window_minutes'] )
			? (int) $settings['captcha']['login_adaptive']['window_minutes']
			: 15;
		$today_overview = \WinkGatekeeper\Stats\InterceptDigest::account_overview( $cache, false );
		$total_overview = \WinkGatekeeper\Stats\InterceptDigest::account_overview( $cache, true );
		$gate_rows      = \WinkGatekeeper\Stats\InterceptDigest::account_gate_rows( $cache );
		// 登录门说明列带动态窗口（account_gate_rows 用默认 15 组装——此处按配置覆写）。
		if ( isset( $gate_rows[0] ) && 'login' === ( $gate_rows[0]['gate'] ?? '' ) ) {
			$gate_rows[0]['note'] = \WinkGatekeeper\Stats\InterceptDigest::gate_note( 'login', $gate_rows[0]['today'], $window );
		}
		$cards = self::stats_driver_cards( 'login' );
		?>
		<section class="wk-card" id="wk-stats-login">
			<h2 class="wk-sec-title"><?php esc_html_e( '登录防护（登录 / 注册 / 找回密码 三张门）', 'wink-gatekeeper' ); ?><?php echo self::stats_config_hint( $cards ); // phpcs:ignore WordPress.Security.EscapeOutput -- 内部组装已转义。 ?></h2>

			<h3 class="wk-sub-title"><?php esc_html_e( '今日概览', 'wink-gatekeeper' ); ?></h3>
			<p>
				<?php
				echo esc_html( sprintf(
					/* translators: 1: 拦下, 2: 验证放行, 3: 密码输错, 4: 窗口分钟 */
					__( '今日拦下 %1$d 次 · 验证放行 %2$d 人 · 密码输错 %3$d 次（%4$d 分钟内同账号）', 'wink-gatekeeper' ),
					(int) ( $today_overview['reject'] ?? 0 ),
					(int) ( $today_overview['passed'] ?? 0 ),
					(int) ( $today_overview['attempts'] ?? 0 ),
					$window
				) );
				?>
			</p>
			<p class="wk-muted"><?php echo esc_html( sprintf( /* translators: 1: 累计拦下, 2: 累计密码输错 */ __( '累计拦下 %1$d 次 · 密码输错 %2$d 次', 'wink-gatekeeper' ), (int) ( $total_overview['reject'] ?? 0 ), (int) ( $total_overview['attempts'] ?? 0 ) ) ); ?></p>

			<h3 class="wk-sub-title"><?php esc_html_e( '三张门分账', 'wink-gatekeeper' ); ?></h3>
			<table class="wk-table">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html( \WinkGatekeeper\Stats\Labels::gate_word( 'login' ) ); ?>/<?php echo esc_html( \WinkGatekeeper\Stats\Labels::gate_word( 'register' ) ); ?>/<?php echo esc_html( \WinkGatekeeper\Stats\Labels::gate_word( 'lostpassword' ) ); ?></th>
						<th scope="col"><?php esc_html_e( '今日拦下', 'wink-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( '累计拦下', 'wink-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( '说明', 'wink-gatekeeper' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $gate_rows as $gate_row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $gate_row['label'] ); ?></td>
							<td><?php echo esc_html( (string) $gate_row['today_reject'] ); ?></td>
							<td><?php echo esc_html( (string) $gate_row['total_reject'] ); ?></td>
							<td class="wk-muted"><?php echo esc_html( (string) $gate_row['note'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3 class="wk-sub-title"><?php esc_html_e( '谁拦的（按服务商口径）', 'wink-gatekeeper' ); ?></h3>
			<?php foreach ( $cards as $card ) : ?>
				<?php self::render_driver_card( $card ); ?>
			<?php endforeach; ?>

			<h3 class="wk-sub-title"><?php esc_html_e( '最近拦下的登录尝试（最近 10 条）', 'wink-gatekeeper' ); ?></h3>
			<?php self::render_account_recent_table(); ?>
		</section>
		<?php
	}

	/**
	 * 「最近拦下的登录尝试」明细表（UI 批 2 接线——批 1 占位卡退役；数据源 N1：
	 * EventsTable::recent_account_rejects + 行组装 InterceptDigest::account_reject_rows）。
	 *
	 * 列面：时间 / 事件编号 / 哪张门 / 哪道防线 / 为什么拦（trigger_source 归因人话，
	 * 括号注防线触发；缺省回落 rule_hit 人话）。PIPL 最小化：IP 哈希省略（查询列集
	 * 即不含）、无明文账号列（事件表本就不存——只显特征与结果）。
	 *
	 * @return void
	 */
	private static function render_account_recent_table(): void {
		$events = \WinkGatekeeper\Stats\EventsTable::recent_account_rejects( \WinkGatekeeper\Stats\InterceptDigest::RECENT_LIMIT );
		$rows   = \WinkGatekeeper\Stats\InterceptDigest::account_reject_rows( $events );
		?>
		<p class="wk-muted"><?php esc_html_e( '只显示特征与结果，不显示账号与 IP；为什么拦=触发归因（同一账号连错太多次等）或验证码结果。', 'wink-gatekeeper' ); ?></p>
		<?php if ( array() === $rows ) : ?>
			<p class="wk-muted"><?php esc_html_e( '最近没拦过任何登录尝试——这是好状态。', 'wink-gatekeeper' ); ?></p>
		<?php else : ?>
			<table class="wk-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( '时间', 'wink-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( '事件编号', 'wink-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( '哪张门', 'wink-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( '哪道防线', 'wink-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( '为什么拦', 'wink-gatekeeper' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
							<td><code><?php echo esc_html( (string) $row['event_no'] ); ?></code></td>
							<td><?php echo esc_html( (string) $row['gate_word'] ); ?></td>
							<td><?php echo esc_html( (string) $row['stage_word'] ); ?></td>
							<td>
								<?php echo esc_html( (string) $row['reason'] ); ?>
								<?php if ( '' !== (string) $row['trigger'] ) : ?>
									<span class="wk-muted">（<?php echo esc_html( (string) $row['trigger'] ); ?>）</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * 三、报错历史（口径三——DESIGN-UI-tab6 §2.3：今日出错 + 人话分布 + 最近报错表迁入）。
	 *
	 * 【UI 批 2 接线】最近报错小节 = 全闸报错时间线（N2：EventsTable::recent_faults，
	 * fault_predicate_sql 同源复用——F7）∪ 停转进入·解除时间线（N3：recent_health_timeline，
	 * driver_resumed 非故障口径的解除留痕）——InterceptDigest::fault_timeline_rows 按 id
	 * 倒序归并，「当时怎么处理」组合映射（handling_word），meta.raw 展开行保留。
	 *
	 * @return void
	 */
	private static function render_stats_fault_section(): void {
		$cache = \WinkGatekeeper\Stats\StatsCache::get();

		// 今日出错总数：分布合计与计数缓存对账取大（现行口径保留——【v1.4·反馈1/R4 §D.2】）。
		$fault_dist  = \WinkGatekeeper\Stats\EventsTable::today_fault_distribution();
		$fault_total = array_sum( $fault_dist );
		foreach ( (array) ( $cache['by_driver'] ?? array() ) as $fault_driver_counts ) {
			$fault_total += (int) ( is_array( $fault_driver_counts ) ? ( $fault_driver_counts['error'] ?? 0 ) : 0 );
		}

		// 全闸时间线行（N2 故障 ∪ N3 停转进入·解除；服务商名 registry label 解析）。
		$fault_events  = \WinkGatekeeper\Stats\EventsTable::recent_faults( \WinkGatekeeper\Stats\InterceptDigest::RECENT_LIMIT );
		$health_events = \WinkGatekeeper\Stats\EventsTable::recent_health_timeline( \WinkGatekeeper\Stats\InterceptDigest::RECENT_LIMIT, 7 );
		$driver_labels = array();
		$registry      = null !== self::$driver_registry ? self::$driver_registry : null;
		if ( null !== $registry ) {
			foreach ( $registry->all() as $driver_id => $driver_meta ) {
				if ( is_string( $driver_meta['label'] ?? null ) ) {
					$driver_labels[ (string) $driver_id ] = (string) $driver_meta['label'];
				}
			}
		}
		$fault_rows = \WinkGatekeeper\Stats\InterceptDigest::fault_timeline_rows( $fault_events, $health_events, $driver_labels );
		?>
		<section class="wk-card" id="wk-stats-faults">
			<h2 class="wk-sec-title"><?php esc_html_e( '报错历史（和第三方服务商打交道时出的岔子）', 'wink-gatekeeper' ); ?></h2>

			<h3 class="wk-sub-title"><?php esc_html_e( '今日出错', 'wink-gatekeeper' ); ?></h3>
			<p>
				<?php echo esc_html( sprintf( /* translators: %d: 今日出错次数 */ __( '%d 次', 'wink-gatekeeper' ), $fault_total ) ); ?>
			</p>
			<?php if ( array() !== $fault_dist ) : ?>
				<p class="wk-muted">
					<?php
					$fault_parts = array();
					foreach ( $fault_dist as $fault_rule => $fault_n ) {
						// rule_hit → 人话（N5 映射；未收录键原样——一律 esc_html 转义直出）。
						$fault_parts[] = esc_html( \WinkGatekeeper\Stats\Labels::rule_hit_word( (string) $fault_rule ) ) . ' ×' . esc_html( (string) $fault_n );
					}
					echo implode( ' · ', $fault_parts ); // 逐段已 esc_html（rule_hit 驱动来源文本一律转义）。
					?>
				</p>
			<?php else : ?>
				<p class="wk-muted"><?php esc_html_e( '今日没出过错——各防线都正常。', 'wink-gatekeeper' ); ?></p>
			<?php endif; ?>

			<h3 class="wk-sub-title"><?php esc_html_e( '最近报错（全闸，含服务商暂停与恢复）', 'wink-gatekeeper' ); ?></h3>
			<p class="wk-muted"><?php esc_html_e( '四张门和服务商的岔子都在一张时间线上；服务商当日暂停与次日恢复也会留痕在此。', 'wink-gatekeeper' ); ?></p>
			<?php if ( array() === $fault_rows ) : ?>
				<p class="wk-muted"><?php esc_html_e( '最近没出过错——一切正常。', 'wink-gatekeeper' ); ?></p>
			<?php else : ?>
				<table class="wk-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( '时间', 'wink-gatekeeper' ); ?></th>
							<th scope="col"><?php esc_html_e( '事件编号', 'wink-gatekeeper' ); ?></th>
							<th scope="col"><?php esc_html_e( '哪个门', 'wink-gatekeeper' ); ?></th>
							<th scope="col"><?php esc_html_e( '哪个服务商', 'wink-gatekeeper' ); ?></th>
							<th scope="col"><?php esc_html_e( '出了什么岔子', 'wink-gatekeeper' ); ?></th>
							<th scope="col"><?php esc_html_e( '当时怎么处理', 'wink-gatekeeper' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $fault_rows as $fault_row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $fault_row['created_at'] ); ?></td>
								<td><code><?php echo esc_html( (string) $fault_row['event_no'] ); ?></code></td>
								<td><?php echo esc_html( (string) $fault_row['gate_word'] ); ?></td>
								<td><?php echo esc_html( (string) $fault_row['driver_label'] ); ?></td>
								<td>
									<?php echo esc_html( (string) $fault_row['rule_word'] ); ?>
									<?php $fault_brief = trim( ( isset( $fault_row['raw']['code'] ) && is_string( $fault_row['raw']['code'] ) ? $fault_row['raw']['code'] : '' )
										. ' ' . ( isset( $fault_row['raw']['message'] ) && is_string( $fault_row['raw']['message'] ) ? $fault_row['raw']['message'] : '' ) ); ?>
									<?php if ( '' !== $fault_brief ) : ?>
										<span class="wk-muted">（<?php echo esc_html( mb_substr( $fault_brief, 0, 120, 'UTF-8' ) ); ?>）</span>
									<?php endif; ?>
									<?php if ( array() !== $fault_row['raw'] ) : ?>
										<details class="wk-fault-raw">
											<summary><?php esc_html_e( '展开看服务商原始回复（排障用，已脱敏）', 'wink-gatekeeper' ); ?></summary>
											<pre><?php echo esc_textarea( (string) wp_json_encode( $fault_row['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre>
										</details>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( (string) $fault_row['handling'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * 四、拦截记录流水（占位——去 roadmap 人话化，P6 修复；事件流列表实装后回填链接）。
	 *
	 * @return void
	 */
	private static function render_stats_records_card(): void {
		?>
		<section class="wk-card" id="wk-stats-records">
			<h2 class="wk-sec-title"><?php esc_html_e( '拦截记录流水（查全部记录 / 导出）', 'wink-gatekeeper' ); ?></h2>
			<p class="wk-muted"><?php esc_html_e( '每一条拦下、转人工复核、报错都有编号（如 WK-20261112-0042）。访客报障时报编号即可精确定位；按门/服务商/结果筛选与导出表格接入后启用。', 'wink-gatekeeper' ); ?></p>
		</section>
		<?php
	}

	/**
	 * 五、统计设置 · 清零（统计开关三项迁入本卡垫底——P7 修复；单一大 form 迁位零表单风险，
	 * agent C F18；重置文案去「对账口径」黑话——P10 修复；AJAX 锚 id 保留）。
	 *
	 * @param array<string, mixed> $settings 设置树。
	 * @return void
	 */
	private static function render_stats_settings_card( array $settings ): void {
		?>
		<section class="wk-card" id="wk-stats-settings">
			<h2 class="wk-sec-title"><?php esc_html_e( '统计设置 · 清零', 'wink-gatekeeper' ); ?></h2>
			<p>
				<label>
					<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'stats', 'enabled' ) ) ); ?>" value="0" />
					<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'stats', 'enabled' ) ) ); ?>" value="1" <?php checked( ! empty( $settings['stats']['enabled'] ) ); ?> />
					<?php esc_html_e( '开启统计计数（关掉后本页数字停止增长，拦截照常工作）', 'wink-gatekeeper' ); ?>
				</label>
			</p>
			<p>
				<label>
					<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'stats', 'raw_capture' ) ) ); ?>" value="0" />
					<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'stats', 'raw_capture' ) ) ); ?>" value="1" <?php checked( ! empty( $settings['stats']['raw_capture'] ) ); ?> />
					<?php esc_html_e( '记录服务商原始回复（排障用，出错时多存一段返回内容）', 'wink-gatekeeper' ); ?>
				</label>
				<br />
				<label>
					<?php esc_html_e( '保存长度上限（字）', 'wink-gatekeeper' ); ?>
					<input type="number" min="128" max="2048" name="<?php echo esc_attr( self::field_name( array( 'stats', 'raw_max_bytes' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $settings['stats']['raw_max_bytes'] ?? 512 ) ); ?>" class="small-text" />
					<span class="wk-muted"><?php esc_html_e( '（128–2048，默认 512；正文与错误消息各自截断）', 'wink-gatekeeper' ); ?></span>
				</label>
			</p>
			<p class="wk-muted"><?php echo esc_html( sprintf( /* translators: %s: 清零范围 */ __( '「清空全部统计」会清掉%s，不可恢复。', 'wink-gatekeeper' ), \WinkGatekeeper\Stats\Labels::reset_scope_word() ) ); ?></p>
			<button type="button" class="button button-danger" id="wk-stats-reset"
				data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX_RESET_STATS ) ); ?>"
				data-confirm="<?php echo esc_attr__( '确认清空？所有统计数字和拦截记录将被清掉，不可恢复。', 'wink-gatekeeper' ); ?>">
				<?php esc_html_e( '清空全部统计', 'wink-gatekeeper' ); ?>
			</button>
			<span id="wk-stats-reset-result" aria-live="polite"></span>
		</section>
		<?php
	}

	/* -------------------------------------------------------------------
	 * Tab⑥ 驱动统一卡组（DESIGN-UI-tab6 §3——评论/登录两段复用同一模板）
	 * ------------------------------------------------------------------ */

	/**
	 * 服务商统一卡组数据组装（WP 边界：registry/设置树/Usage/DriverHealth 读 → 纯函数入参）。
	 *
	 * 排班态推导（agent C E-b 方案甲）：captcha=设置 captcha.driver 选中；review=在
	 * cloud.chain 中（含 failover 位——备用位会真实出数）。登录段只列验证码类；
	 * 登录域净口径（F3/E-c）：动作数=captcha_pass+captcha_reject、放行=直读 captcha_pass。
	 *
	 * 【UI 批 2】出错格接线（A-5/N6+N7a）：事件表故障口径按驱动聚合——
	 *   评论卡=全闸聚合取大（事件真值 ∪ by_driver.error——补 captcha 阶段以 reject 落库的远端故障）；
	 *   登录卡=三闸分域聚合（N7a——by_driver.error 全闸混合不可分域，F3）。
	 *
	 * @param string $context comment|login。
	 * @return array<int, array<string, mixed>> InterceptDigest::driver_card 输入组。
	 */
	private static function stats_driver_cards( string $context ): array {
		$settings = self::settings();
		$cache    = \WinkGatekeeper\Stats\StatsCache::get();
		$usage    = \WinkGatekeeper\Stats\Usage::get();
		$registry = null !== self::$driver_registry ? self::$driver_registry : null;
		if ( null === $registry ) {
			return array();
		}

		$captcha_on    = is_string( $settings['captcha']['driver'] ?? null ) ? (string) $settings['captcha']['driver'] : '';
		$cloud         = is_array( $settings['review']['cloud'] ?? null ) ? $settings['review']['cloud'] : array();
		$chain         = is_array( $cloud['chain'] ?? null ) ? $cloud['chain'] : array();
		$cloud_enabled = ! empty( $cloud['enabled'] );
		/* 【v1.7·M3-IP·定稿 §9】IP 类驱动入服务商统一卡（type='ip'——卡=iprep.<id> 子树，
		 * 启用=iprep.enabled；!enabled=蓝点未启用占位卡（已配置未启用态如实呈现）。 */
		$iprep         = is_array( $settings['iprep'] ?? null ) ? $settings['iprep'] : array();

		// 出错格数据源（N6/N7a——事件表真值；登录卡分域过滤 ACCOUNT_GATES）。
		$fault_all       = \WinkGatekeeper\Stats\EventsTable::today_fault_by_driver();
		$fault_in_account = \WinkGatekeeper\Stats\EventsTable::today_fault_by_driver( \WinkGatekeeper\Stats\InterceptDigest::ACCOUNT_GATES );

		$types = 'login' === $context ? array( 'captcha' ) : array( 'captcha', 'review', 'ip' );
		$cards = array();
		foreach ( $types as $type ) {
			foreach ( $registry->by_type( $type ) as $driver_id => $meta ) {
				$driver_id = (string) $driver_id;
				// 本地规则在评论段走三数式专卡（不花钱、无外部故障面）——不入服务商卡组。
				if ( 'local_rules' === $driver_id ) {
					continue;
				}
				$label   = is_string( $meta['label'] ?? null ) ? $meta['label'] : $driver_id;
				$enabled = 'captcha' === $type
					? ( '' !== $captcha_on && $captcha_on === $driver_id )
					: ( 'ip' === $type
						? ! empty( $iprep['enabled'] )
						: ( $cloud_enabled && in_array( $driver_id, $chain, true ) ) );
				$halt    = DriverHealth::state_today( $driver_id );
				$today   = \WinkGatekeeper\Stats\InterceptDigest::driver_counts( $cache, $driver_id, false );
				$total   = \WinkGatekeeper\Stats\InterceptDigest::driver_counts( $cache, $driver_id, true );

				if ( 'login' === $context ) {
					$passed = (int) ( $today['captcha_pass'] ?? 0 );
					// 输入经 InterceptDigest::driver_card 纯函数组装（渲染层只消费其输出形态）。
					$cards[] = \WinkGatekeeper\Stats\InterceptDigest::driver_card( array(
						'label'      => $label,
						'type'       => $type,
						'context'    => $context,
						'enabled'    => $enabled,
						'halt_state' => $halt,
						'used'       => $passed + (int) ( $today['captcha_reject'] ?? 0 ) + (int) ( $today['error'] ?? 0 ),
						'passed'     => $passed,
						'rejected'   => (int) ( $today['captcha_reject'] ?? 0 ),
						'review'     => null, // 登录族无待审——格隐藏。
						'errors'     => (int) ( $fault_in_account[ $driver_id ] ?? 0 ), // N7a 分域出错（事件表真值）。
						'quota'      => 0,
						'total'      => array(
							'passed'   => (int) ( $total['captcha_pass'] ?? 0 ),
							'rejected' => (int) ( $total['captcha_reject'] ?? 0 ),
							'review'   => null,
							'errors'   => null, // 累计分域出错不在本批查询面——格省略（不虚构）。
						),
						'config_tab' => 'captcha',
					) );
					continue;
				}

				$config = 'ip' === $type && is_array( $iprep[ $driver_id ] ?? null )
					? $iprep[ $driver_id ]
					: ( 'review' === $type && is_array( $cloud[ $driver_id ] ?? null ) ? $cloud[ $driver_id ] : array() );
				$cards[] = \WinkGatekeeper\Stats\InterceptDigest::driver_card( array(
					'label'      => $label,
					'type'       => $type,
					'context'    => $context,
					'enabled'    => $enabled,
					'halt_state' => $halt,
					'used'       => (int) \WinkGatekeeper\Stats\Usage::daily_total_for( $usage, $driver_id ),
					'passed'     => null, // 减法推导（D4）。
					'rejected'   => (int) ( $today['reject'] ?? 0 ),
					'review'     => (int) ( $today['review'] ?? 0 ),
					'errors'     => max( (int) ( $fault_all[ $driver_id ] ?? 0 ), (int) ( $today['error'] ?? 0 ) ), // N6 事件真值 ∪ 计数缓存取大。
					'quota'      => (int) ( $config['daily_quota'] ?? 0 ),
					'total'      => array(
						'passed'   => null, // 累计动作数 Usage 不留存——放行格省略（不虚构）。
						'rejected' => (int) ( $total['reject'] ?? 0 ),
						'review'   => (int) ( $total['review'] ?? 0 ),
						'errors'   => (int) ( $total['error'] ?? 0 ),
					),
					'config_tab' => 'captcha' === $type ? 'captcha' : ( 'ip' === $type ? 'iprep' : 'review' ),
				) );
			}
		}
		return $cards;
	}

	/**
	 * 段级配置指引（UI-FIX B②——2026-11-12 用户反馈「指引只在段级小标题位置出现一次」）。
	 *
	 * 段内驱动卡存在未启用卡时，段标题尾部追加「 · 去配置」链接（形如「登录防护 ·
	 * 去配置」）；目标=首个未启用卡的 config_tab（卡组序=captcha 在前——混合域时
	 * 指向第一道防线）。全部启用返回空串（无卡段不调用）。
	 *
	 * @param array<int, array<string, mixed>> $cards stats_driver_cards 输出。
	 * @return string HTML 片段（''=无指引；esc_url/esc_html 全转义）。
	 */
	private static function stats_config_hint( array $cards ): string {
		foreach ( $cards as $hint_card ) {
			if ( empty( $hint_card['enabled'] ) && '' !== (string) ( $hint_card['config_tab'] ?? '' ) ) {
				return ' · <a class="wk-config-link" href="' . esc_url( self::tab_url( (string) $hint_card['config_tab'] ) ) . '">'
					. esc_html( __( '去配置', 'wink-gatekeeper' ) ) . '</a>';
			}
		}
		return '';
	}

	/**
	 * 服务商统一卡渲染（头=服务商名+四档健康徽标；行=箭头式；额度行；未启用灰卡
	 * 极简一句无链接——UI-FIX B②：跳转指引收敛段标题单入口，卡不承担导航职责）。
	 *
	 * @param array<string, mixed> $card InterceptDigest::driver_card 输出。
	 * @return void
	 */
	private static function render_driver_card( array $card ): void {
		$label = (string) ( $card['label'] ?? '' );
		$dot   = (string) ( $card['dot_class'] ?? 'wk-dot--ok' ); // 【v1.5·FIX】彩色状态点（用户三色体系：绿启用/蓝待命/红故障/黄暂停）。
		if ( empty( $card['enabled'] ) ) {
			// 未启用占位卡（蓝点=已配置未启用；极简一句，无链接）。
			echo '<div class="wk-driver-card wk-driver-card--disabled">';
			echo '<p><strong>' . esc_html( $label ) . '</strong> <span class="' . esc_attr( $dot ) . ' wk-dot"></span> ' . esc_html( (string) $card['health'] ) . '</p>';
			echo '<p class="wk-muted">' . esc_html( (string) $card['placeholder_note'] ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<div class="wk-driver-card">';
		echo '<p><strong>' . esc_html( $label ) . '</strong> <span class="wk-driver-badge"><span class="' . esc_attr( $dot ) . ' wk-dot"></span> ' . esc_html( (string) $card['health'] ) . '</span></p>';
		foreach ( (array) ( $card['lines'] ?? array() ) as $line ) {
			echo '<p>' . esc_html( (string) $line ) . '</p>';
		}
		foreach ( (array) ( $card['total_lines'] ?? array() ) as $line ) {
			echo '<p class="wk-muted">' . esc_html( (string) $line ) . '</p>';
		}
		if ( null !== ( $card['quota_line'] ?? null ) ) {
			echo '<p class="wk-muted">' . esc_html( (string) $card['quota_line'] ) . '</p>';
		}
		if ( '' !== (string) ( $card['health_note'] ?? '' ) ) {
			echo '<p class="wk-muted">' . esc_html( (string) $card['health_note'] ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * 「最近拦下的评论」明细表（v1.3 卡体保留、迁入口径一③——P3 修复；列头人话化，
	 * 内嵌云端错误子表迁出为口径三——P3/P8 修复）。
	 *
	 * @return void
	 */
	private static function render_comment_recent_table(): void {
		$events = \WinkGatekeeper\Stats\EventsTable::recent_comment_rejects( \WinkGatekeeper\Stats\InterceptDigest::RECENT_LIMIT );

		// comment_id 直查 wp_comments（get_comment 任意状态：站长可能已恢复/删除留档）。
		$comments_by_id = array();
		foreach ( $events as $event ) {
			$meta = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
			$cid  = isset( $meta['comment_id'] ) && is_int( $meta['comment_id'] ) ? $meta['comment_id'] : null;
			if ( null === $cid || isset( $comments_by_id[ $cid ] ) ) {
				continue;
			}
			$comment = get_comment( $cid );
			$comments_by_id[ $cid ] = null === $comment ? null : array(
				'comment_author'       => (string) $comment->comment_author,
				'comment_author_email' => (string) $comment->comment_author_email,
				'comment_content'      => (string) $comment->comment_content,
			);
		}

		$rows = \WinkGatekeeper\Stats\InterceptDigest::rows( $events, $comments_by_id );
		?>
		<p class="wk-muted"><?php esc_html_e( '昵称/邮箱打码展示，原文在留档链接查看；机器人试探（假提交探测）不留评论，不进此表。', 'wink-gatekeeper' ); ?></p>
		<?php if ( array() === $rows ) : ?>
			<p class="wk-muted"><?php esc_html_e( '最近没拦过任何评论——这是好状态。', 'wink-gatekeeper' ); ?></p>
		<?php else : ?>
			<table class="wk-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( '时间', 'wink-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( '事件编号', 'wink-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( '哪道防线拦的', 'wink-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( '原因', 'wink-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( '昵称', 'wink-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( '邮箱', 'wink-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( '说了什么', 'wink-gatekeeper' ); ?></th>
						<th scope="col"><?php esc_html_e( '留档', 'wink-gatekeeper' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
							<td><code><?php echo esc_html( (string) $row['event_no'] ); ?></code></td>
							<td><?php echo esc_html( \WinkGatekeeper\Stats\Labels::stage_word( (string) $row['stage'] ) ); ?></td>
							<td><?php echo esc_html( \WinkGatekeeper\Stats\Labels::rule_hit_word( (string) $row['rule_hit'] ) ); ?></td>
							<td><?php echo esc_html( (string) $row['author'] ); ?></td>
							<td><?php echo esc_html( (string) $row['email'] ); ?></td>
							<td><?php echo esc_html( (string) $row['excerpt'] ); ?></td>
							<td>
								<?php if ( null !== $row['edit_url'] ) : ?>
									<a href="<?php echo esc_url( admin_url( (string) $row['edit_url'] ) ); ?>"><?php esc_html_e( '查看留档', 'wink-gatekeeper' ); ?></a>
								<?php elseif ( null !== $row['comment_id'] ) : ?>
									<span class="wk-muted">#<?php echo esc_html( (string) $row['comment_id'] ); ?><?php esc_html_e( '（评论已删除）', 'wink-gatekeeper' ); ?></span>
								<?php else : ?>
									<span class="wk-muted"><?php esc_html_e( '未留档', 'wink-gatekeeper' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
			<p class="wk-table-foot">
				<a class="button" href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=spam' ) ); ?>"><?php esc_html_e( '查看垃圾评论分类', 'wink-gatekeeper' ); ?></a>
			</p>
		<?php
	}

	/**
	 * 统计重置 AJAX 处理器（03 §6 后台操作安全约定：manage_options + check_ajax_referer）。
	 *
	 * @return void
	 */
	public static function ajax_reset_stats(): void {
		check_ajax_referer( self::AJAX_RESET_STATS, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足：需要 manage_options。', 'wink-gatekeeper' ) ), 403 );
		}

		\WinkGatekeeper\Stats\StatsCache::reset_all();

		wp_send_json_success( array( 'message' => __( '统计已重置。', 'wink-gatekeeper' ) ) );
	}

	/* -------------------------------------------------------------------
	 * Tab⑦ 工具（M0 骨架）
	 * ------------------------------------------------------------------ */

	/**
	 * 未实装云端审核驱动（下拉可见但不可用——03 Tab③ v1.4：DeepSeek 等 M3/M4 驱动
	 * 带里程碑后缀；required 字段名列表供状态点派生）。
	 *
	 * 【M6·批H2·H2-1】private→public：未来驱动显示名单一来源公开件（Bootstrap
	 * $driver_labels 同源注入——Tab③ 下拉与仪表盘调用量卡行名同表；openai_custom
	 * 无 registry_entry=实例态驱动，不发明第二映射源）。
	 *
	 * @return array<string, array{label: string, milestone: string, required: string[]}>
	 */
	public static function future_review_drivers(): array {
		return array(
			'moli' => array( 'label' => '模力 System One', 'milestone' => 'M4', 'required' => array( 'api_key' ) ),
			// openai_custom 已于 M6·批E 实装——登记入 registry，脱离 future 段（下拉已实装驱动）。
			// deepseek 已于 M5 U4 批实装——同上。
			// aliyun_green 已于 M3' 批次 3（B5）实装——同上。
			// baidu 已于 M3' 轻量批（B6）实装——同上。
		);
	}

	private static function render_tab_tools(): void {
		?>
		<section class="wk-card" id="wk-tools-existing-audit-card">
			<h2><?php esc_html_e( '历史评论体检', 'wink-gatekeeper' ); ?></h2>
			<?php
			/* 【M6·批H4·H4-2】状态取值+运行中黄条上移卡顶（h2 后第一元素——与网址核查
			 * 黄条同位；手测二轮⑫打回：原在中部状态行位观感割裂）。 */
			$worker  = self::audit_worker();
			$prog    = $worker->status();
			$st      = $prog['status'] ?? 'idle';
			$line    = '';
			$actions = array();
			// 【M5 修复批·C4】idle 态发起钮前置闸：复用 has_driver（与 C1 start 守卫同一
			// 判定源）——无已配置云审驱动时不渲染「开始体检」，替换为黄条引导去 Tab③。
			$has_driver = \WinkGatekeeper\Audit\ExistingAuditWorker::has_driver( self::settings() );
			if ( 'running' === $st || 'quota_paused' === $st ) {
				// 【M5 修复批·C4】driver 空串（历史半残任务/驱动解析失败）→ 人话显示，
				// 不再出现「当前服务商：-」裸占位。
				$driver_id = (string) ( $prog['driver'] ?? '' );
				if ( '' === $driver_id ) {
					$driver_id = __( '未配置（服务商未配置——发起后无法送审）', 'wink-gatekeeper' );
				}
				$line = sprintf(
					/* translators: 1: processed 2: total 3: driver id */
					__( '进行中（%1$d / %2$d）· 当前服务商：%3$s', 'wink-gatekeeper' ),
					(int) ( $prog['processed'] ?? 0 ),
					(int) ( $prog['total'] ?? 0 ),
					esc_html( $driver_id )
				);
				$actions['pause'] = __( '暂停', 'wink-gatekeeper' );
				if ( 'quota_paused' === $st ) {
					$line .= ' ' . __( '——今日额度已用完，次日自动恢复。', 'wink-gatekeeper' );
				}
			} elseif ( 'paused' === $st ) {
				$line = sprintf(
					/* translators: 1: processed 2: total */
					__( '已暂停（%1$d / %2$d）', 'wink-gatekeeper' ),
					(int) ( $prog['processed'] ?? 0 ),
					(int) ( $prog['total'] ?? 0 )
				);
				$actions['resume'] = __( '继续', 'wink-gatekeeper' );
			} elseif ( 'completed' === $st ) {
				$line = sprintf(
					/* translators: 1: finished time */
					__( '上次完成于 %1$s', 'wink-gatekeeper' ),
					esc_html( date_i18n( 'Y-m-d H:i', (int) ( $prog['finished_at'] ?? 0 ) ) )
				);
				$actions['reset'] = __( '重新体检', 'wink-gatekeeper' );
			} else {
				$line = __( '还没体检过', 'wink-gatekeeper' );
				if ( $has_driver ) {
					$actions['start'] = __( '开始体检', 'wink-gatekeeper' );
				}
			}
			/* 【M6·批H3·H3-B】运行中黄条统一（手测反馈 B）：running/quota_paused 态
			 * 升级 notice-warning（与网址核查运行中黄条同形态）；paused/completed/idle
			 * 保持文本行（非运行态）。 */ ?>
			<?php if ( 'running' === $st || 'quota_paused' === $st ) : ?>
			<div class="notice notice-warning inline" id="wk-tools-audit-running-bar"><p><?php echo esc_html( $line ); ?></p></div>
			<?php else : ?>
			<p id="wk-tools-audit-state-line" class="wk-muted"><?php echo esc_html( $line ); ?></p>
			<?php endif; ?>
			<?php if ( 'idle' === $st && ! $has_driver ) : ?>
			<div class="notice notice-warning inline" id="wk-tools-audit-no-driver-notice"><p>
				<?php esc_html_e( '云端审核还没配置——先到 Tab③ 配置并启用服务商，再发起体检。', 'wink-gatekeeper' ); ?>
			</p></div>
			<?php endif; ?>
			<p class="wk-muted"><?php esc_html_e( '把站里已有的评论整体交服务商再查一遍：判违规的进垃圾箱、没看清的进待审，都能手动恢复；没问题的不动。报告就展示在本卡下方。', 'wink-gatekeeper' ); ?></p>
			<p class="wk-muted"><?php esc_html_e( '红线注：体检只按「昵称+正文」送审，邮箱与网址不进入送审内容（邮箱剔除纪律；网址核查是另一条线）。', 'wink-gatekeeper' ); ?></p>
			<p>
			<?php foreach ( $actions as $op => $label ) : ?>
				<?php
				$confirm = '';
				if ( 'start' === $op ) {
					$confirm = __( '按当前启用的服务商送审，消耗按次额度——确定开始？判违规的会进垃圾箱、没看清的进待审（都能手动恢复）。', 'wink-gatekeeper' );
				} elseif ( 'reset' === $op ) {
					$confirm = __( '重新体检=清掉上次标记重来，已处理结果作废——确定？', 'wink-gatekeeper' );
				}
				?>
				<a class="button" <?php echo 'start' === $op ? 'id="wk-tools-audit-start-btn" ' : ''; ?>href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . constant( __CLASS__ . '::ACTION_AUDIT_' . strtoupper( $op ) ) ), constant( __CLASS__ . '::ACTION_AUDIT_' . strtoupper( $op ) ) ) ); ?>"
					<?php echo '' !== $confirm ? 'onclick="return confirm(\'' . esc_js( $confirm ) . '\');"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
			<?php if ( 'paused' === $st ) : ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_AUDIT_RESET ), self::ACTION_AUDIT_RESET ) ); ?>"
					onclick="return confirm('<?php echo esc_js( __( '重新体检=清掉上次标记重来，已处理结果作废——确定？', 'wink-gatekeeper' ) ); ?>');"><?php esc_html_e( '重新体检', 'wink-gatekeeper' ); ?></a>
			<?php endif; ?>
			</p>
		</section>
		<?php
		/* 【M6·批C·C-3】存量审核报告卡（迁址自 Tab⑥——用户 2026-11-16「⑥ 统计页面中
		 * 展示的消息已经够杂乱」；D6 分工更新：⑦ 发起+报告同处）。wk-overview-report
		 * 同款票面（SettingsPage render_overview_report 形态复用）：报告头部=服务商+发起/
		 * 完成时间；口径三行=处理口径（没问题/待审核/垃圾箱）；原因明细 details 折叠。
		 * 三态：空态/运行中（含暂停与配额暂停）/完成。渲染红线：原因=攻击者可控
		 * （AI 原文/驱动透传）——esc_html+截断（XSS）。 */
		$rep_worker = self::audit_worker();
		$rep_prog   = $rep_worker->status();
		$rep_data   = $rep_worker->report_data();
		$rep_view   = \WinkGatekeeper\Audit\ExistingAuditTask::report_view( is_array( $rep_prog ) ? $rep_prog : array(), is_array( $rep_data['counts'] ?? null ) ? $rep_data['counts'] : null );
		?>
		<section class="wk-card" id="wk-tools-existing-audit-report">
			<h2><?php esc_html_e( '存量审核报告（历史评论体检）', 'wink-gatekeeper' ); ?></h2>
			<?php if ( 'empty' === $rep_view['state'] ) : ?>
				<p id="wk-tools-existing-report-empty" class="wk-muted"><?php esc_html_e( '还没体检过历史评论——点上方「开始体检」发起，报告会展示在这里。', 'wink-gatekeeper' ); ?></p>
			<?php else : ?>
				<p id="wk-tools-existing-report-meta" class="wk-muted"><?php
					$st_words = array(
						'running'      => __( '进行中', 'wink-gatekeeper' ),
						'paused'       => __( '已暂停', 'wink-gatekeeper' ),
						'quota_paused' => __( '今日额度用完已暂停（次日自动恢复）', 'wink-gatekeeper' ),
						'completed'    => __( '已完成', 'wink-gatekeeper' ),
					);
					$st_word      = $st_words[ (string) ( $rep_prog['status'] ?? 'idle' ) ] ?? (string) ( $rep_prog['status'] ?? 'idle' );
					$driver_label = '' !== $rep_view['driver'] ? $rep_view['driver'] : __( '未配置', 'wink-gatekeeper' );
					$meta_line    = sprintf(
						/* translators: 1: 服务商 2: 发起时间 3: 状态词 */
						__( '服务商：%1$s · 发起：%2$s · %3$s', 'wink-gatekeeper' ),
						$driver_label,
						$rep_view['started_at'] > 0 ? date_i18n( 'Y-m-d H:i', $rep_view['started_at'] ) : '—',
						$st_word
					);
					if ( 'completed' === $rep_view['state'] && $rep_view['finished_at'] > 0 ) {
						$meta_line .= ' · ' . sprintf(
							/* translators: %s: 完成时间 */
							__( '完成：%s', 'wink-gatekeeper' ),
							date_i18n( 'Y-m-d H:i', $rep_view['finished_at'] )
						);
					} elseif ( 'running' === $rep_view['state'] && $rep_view['total'] > 0 ) {
						$meta_line .= ' · ' . sprintf(
							/* translators: 1: processed 2: total */
							__( '已体检 %1$d / %2$d 条', 'wink-gatekeeper' ),
							$rep_view['processed'],
							$rep_view['total']
						);
					}
					echo esc_html( $meta_line );
				?></p>
				<div class="wk-report-tickets" id="wk-tools-existing-audit-caliber">
					<div class="wk-report-ticket">
						<p class="wk-report-ticket__name"><strong><?php esc_html_e( '没问题（pass）', 'wink-gatekeeper' ); ?></strong></p>
						<p class="wk-report-ticket__main"><?php echo esc_html( sprintf( /* translators: %s: 条数 */ __( '%s 条', 'wink-gatekeeper' ), number_format_i18n( $rep_view['caliber']['pass'] ) ) ); ?></p>
						<p class="wk-muted"><?php esc_html_e( '已核无问题——评论状态不动', 'wink-gatekeeper' ); ?></p>
					</div>
					<div class="wk-report-ticket">
						<p class="wk-report-ticket__name"><strong><?php esc_html_e( '待审核（没看清，不下结论）', 'wink-gatekeeper' ); ?></strong></p>
						<p class="wk-report-ticket__main"><?php echo esc_html( sprintf( /* translators: %s: 条数 */ __( '%s 条', 'wink-gatekeeper' ), number_format_i18n( $rep_view['caliber']['pending'] ) ) ); ?></p>
						<p class="wk-muted"><?php esc_html_e( '已进待审队列等你过目', 'wink-gatekeeper' ); ?></p>
					</div>
					<div class="wk-report-ticket">
						<p class="wk-report-ticket__name"><strong><?php esc_html_e( '垃圾箱（判违规）', 'wink-gatekeeper' ); ?></strong></p>
						<p class="wk-report-ticket__main"><?php echo esc_html( sprintf( /* translators: %s: 条数 */ __( '%s 条', 'wink-gatekeeper' ), number_format_i18n( $rep_view['caliber']['spam'] ) ) ); ?></p>
						<p class="wk-muted"><?php esc_html_e( '已进垃圾箱', 'wink-gatekeeper' ); ?></p>
					</div>
				</div>
				<p class="wk-overview-actions">
					<a href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=moderated' ) ); ?>"><?php
						echo esc_html( sprintf(
							/* translators: %d: 待审核条数 */
							__( '去待审队列处理（%d 条）→', 'wink-gatekeeper' ),
							$rep_view['caliber']['pending']
						) );
					?></a>
					· <a href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=spam' ) ); ?>"><?php
						echo esc_html( sprintf(
							/* translators: %d: 垃圾箱条数 */
							__( '去垃圾箱查看（%d 条）→', 'wink-gatekeeper' ),
							$rep_view['caliber']['spam']
						) );
					?></a>
				</p>
				<p class="wk-muted"><?php esc_html_e( '判违规进垃圾箱、没看清进待审，都能手动恢复。', 'wink-gatekeeper' ); ?><?php
					if ( $rep_view['caliber']['gone'] > 0 || $rep_view['caliber']['error'] > 0 ) {
						echo ' ' . esc_html( sprintf(
							/* translators: 1: 评论已删条数 2: 出错条数 */
							__( '另：评论已删除 %1$d 条 · 出错 %2$d 条。', 'wink-gatekeeper' ),
							$rep_view['caliber']['gone'],
							$rep_view['caliber']['error']
						) );
					}
				?></p>
				<details id="wk-tools-existing-audit-reasons">
					<summary><?php echo esc_html( sprintf(
						/* translators: %d: 原因类数 */
						__( '原因明细（%d 类）', 'wink-gatekeeper' ),
						count( is_array( $rep_data['reasons'] ?? null ) ? $rep_data['reasons'] : array() )
					) ); ?></summary>
					<?php if ( array() !== ( $rep_data['reasons'] ?? array() ) ) : ?>
						<p><?php
							$parts = array();
							foreach ( $rep_data['reasons'] as $row ) {
								// 【M6·批H·H-8】截断展示补「…」省略号（单点件全消费面生效）；
								// 【M6·批H3·H3-C】行尾补处置去向（→ 垃圾箱/→ 待审核——去向词常量安全）。
								$parts[] = sprintf(
									'「%s」%d 条 → %s',
									esc_html( self::truncate_reason( (string) $row['reason'] ) ),
									(int) $row['n'],
									'spam' === (string) ( $row['disposal'] ?? 'hold' ) ? '垃圾箱' : '待审核'
								);
							}
							echo esc_html( implode( ' · ', $parts ) ); // XSS 红线：原因=攻击者可控（AI 原文/驱动透传）——转义+截断。
						?></p>
					<?php else : ?>
						<p class="wk-muted"><?php esc_html_e( '暂无命中', 'wink-gatekeeper' ); ?></p>
					<?php endif; ?>
				</details>
			<?php endif; ?>
		</section>
		<?php
		/* 【M6·P1-3】存量网址核查操作卡（显式触发语义：开启网址核查本身**不**回捞历史
		 * ——站长点按钮才一次性入队，批量车道按日额度 50 自动推进、额度尽暂停黄条照旧）。
		 * 【M6·批C·C-2】处置人话补注（用户 2026-11-16 拍板）：判违规进垃圾箱、没看清进
		 * 待审，都能手动恢复。 */
		$url_audit_settings   = self::settings();
		$url_audit_on         = ! empty( $url_audit_settings['url_audit']['enabled'] );
		$backlog_worker       = new \WinkGatekeeper\UrlAudit\UrlAuditWorker( $url_audit_settings );
		$backlog_count        = $backlog_worker->batch_queue_count();
		/* 【M6·批C·C-3】点击反馈（F6：点击运行了但零展示零反馈）：入队回跳携带已入队数
		 * （wk-url-backlog）——立即呈现「已入队 N 条·开始核查」。 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读回跳状态渲染（无副作用；nonce 双闸在 handler）。
		$backlog_added = isset( $_GET['wk-url-backlog'] ) && is_scalar( $_GET['wk-url-backlog'] ) ? absint( (string) $_GET['wk-url-backlog'] ) : -1;
		/* 【M6·批H·H-7】运行中黄条数据源（自报告卡前置到操作卡——两卡同源三态）：
		 * state=running（运行记录未完成或队列非空）→ 操作卡头 notice-warning「存量网址
		 * 核查进行中…」；completed/empty 不渲染（完成/空队列熄灭——A-U3 有话才出现先例）。 */
		$url_view = $backlog_worker->backlog_report_data();
		?>
		<section class="wk-card" id="wk-tools-url-audit-card">
			<h2><?php esc_html_e( '存量网址核查', 'wink-gatekeeper' ); ?></h2>
			<?php if ( 'running' === $url_view['state'] ) : ?>
			<div class="notice notice-warning inline" id="wk-tools-url-audit-running-bar"><p>
				<?php esc_html_e( '存量网址核查进行中…按每日额度自动推进（判违规进垃圾箱、没看清进待审，都可手动恢复）；完成后可看下方报告。', 'wink-gatekeeper' ); ?>
			</p></div>
			<?php endif; ?>
			<p class="wk-muted"><?php esc_html_e( '把站里已有评论中的网站地址交「网址核查」全部重查一遍（打开网页+AI 判定该网站有没有问题）。平时开启网址核查只查新评论——这里点按钮才翻查历史，消耗可预期。', 'wink-gatekeeper' ); ?></p>
			<p class="wk-muted"><?php esc_html_e( '每日额度用完自动暂停（次日自动接着查）：判违规的进垃圾箱、没看清的进待审，都能手动恢复。', 'wink-gatekeeper' ); ?></p>
			<?php /* 【M6·批H4·H4-3】未开启态抽独立黄条（手测二轮⑦附：提示族统一黄条形态）；
			 * 排队/空闲两态保留文本行。 */ ?>
			<?php if ( ! $url_audit_on ) : ?>
			<div class="notice notice-warning inline" id="wk-tools-url-audit-off-notice"><p>
				<?php esc_html_e( '网址核查未开启——先到「云端审核」页开启「网址核查」，再回来发起存量核查。', 'wink-gatekeeper' ); ?>
			</p></div>
			<?php endif; ?>
			<p id="wk-tools-url-audit-state-line" class="wk-muted">
				<?php if ( ! $url_audit_on ) : ?>
					<?php esc_html_e( '（开启后这里可发起存量核查。）', 'wink-gatekeeper' ); ?>
				<?php elseif ( $backlog_count > 0 ) : ?>
					<?php
					printf(
						/* translators: %s: 待核查条数。 */
						esc_html__( '已排队 %s 条待核查（按每日额度自动推进）。', 'wink-gatekeeper' ),
						esc_html( number_format_i18n( $backlog_count ) )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( '没有待核查的存量网址——点下方按钮可把带网址的已批准评论全部重查一遍。', 'wink-gatekeeper' ); ?>
				<?php endif; ?>
			</p>
			<?php if ( $backlog_added >= 0 ) : ?>
			<p id="wk-tools-url-audit-feedback"><strong><?php
				echo esc_html( $backlog_added > 0
					? sprintf(
						/* translators: %s: 本次入队条数 */
						__( '已入队 %s 条·开始核查（按每日额度自动推进，可离开此页）。', 'wink-gatekeeper' ),
						number_format_i18n( $backlog_added )
					)
					: __( '没有新发现需核查的存量网址——可能都已在队列中。', 'wink-gatekeeper' ) );
			?></strong></p>
			<?php endif; ?>
			<?php if ( $url_audit_on ) : ?>
			<p>
				<a class="button" id="wk-tools-url-audit-start-btn"
					href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . \WinkGatekeeper\UrlAudit\UrlAuditWorker::ACTION_BACKLOG ), \WinkGatekeeper\UrlAudit\UrlAuditWorker::ACTION_BACKLOG ) ); ?>"
					onclick="return confirm('<?php echo esc_js( __( '全部重查一遍？将忽略历史结果（网站内容可能已变化），把带网址的已批准评论重新排队核查，按每日额度逐条进行（消耗额度）。判违规的会进垃圾箱、没看清的进待审（都能手动恢复）。', 'wink-gatekeeper' ) ); ?>');"><?php esc_html_e( '开始存量网址核查（全部重查一遍——忽略历史结果，网站内容可能已变化）', 'wink-gatekeeper' ); ?></a>
			</p>
			<?php endif; ?>
		</section>
		<?php
		/* 【M6·批C·C-3】存量网址核查报告卡（新增——操作卡下方，同 wk-overview-report
		 * 款式）：口径三行统计（没问题/待审核/垃圾箱）+起止时间+队列残留数。数据源=
		 * backlog 运行记录 option（入队时建 record：started_at+ids+enqueued）+渲染时
		 * meta 反查统计（tick 零写——施工图 §3b C-3 最小方案，报告落档说明）。
		 * 【M6·批H·H-7】$url_view 已前置（操作卡运行中黄条同源）——本卡直接消费。 */
		?>
		<section class="wk-card" id="wk-tools-url-audit-report">
			<h2><?php esc_html_e( '存量网址核查报告', 'wink-gatekeeper' ); ?></h2>
			<?php if ( 'empty' === $url_view['state'] ) : ?>
				<p id="wk-tools-url-report-empty" class="wk-muted"><?php esc_html_e( '还没运行过存量网址核查——点上方「开始存量网址核查」发起，报告会展示在这里。', 'wink-gatekeeper' ); ?></p>
			<?php else : ?>
				<p id="wk-tools-url-report-meta" class="wk-muted"><?php
					if ( 'running' === $url_view['state'] ) {
						echo esc_html( sprintf(
							/* translators: 1: 发起时间 2: 已核查数 3: 共数 4: 队列残留数 */
							__( '发起：%1$s · 进行中——已核查 %2$d / %3$d 条 · 队列残留 %4$d 条（按每日额度自动推进）', 'wink-gatekeeper' ),
							$url_view['started_at'] > 0 ? date_i18n( 'Y-m-d H:i', $url_view['started_at'] ) : '—',
							$url_view['judged'],
							$url_view['enqueued'],
							$url_view['queue_residual']
						) );
					} else {
						echo esc_html( sprintf(
							/* translators: 1: 发起时间 2: 完成时间 3: 共数 */
							__( '发起：%1$s · 完成：%2$s · 共核查 %3$d 条（本轮全部收口）', 'wink-gatekeeper' ),
							$url_view['started_at'] > 0 ? date_i18n( 'Y-m-d H:i', $url_view['started_at'] ) : '—',
							$url_view['finished_at'] > 0 ? date_i18n( 'Y-m-d H:i', $url_view['finished_at'] ) : '—',
							$url_view['judged']
						) );
					}
				?></p>
				<div class="wk-report-tickets" id="wk-tools-url-audit-caliber">
					<div class="wk-report-ticket">
						<p class="wk-report-ticket__name"><strong><?php esc_html_e( '没问题（pass）', 'wink-gatekeeper' ); ?></strong></p>
						<p class="wk-report-ticket__main"><?php echo esc_html( sprintf( /* translators: %s: 条数 */ __( '%s 条', 'wink-gatekeeper' ), number_format_i18n( $url_view['caliber']['pass'] ) ) ); ?></p>
						<p class="wk-muted"><?php esc_html_e( '网站核过没问题——评论状态不动', 'wink-gatekeeper' ); ?></p>
					</div>
					<div class="wk-report-ticket">
						<p class="wk-report-ticket__name"><strong><?php esc_html_e( '待审核（没看清，不下结论）', 'wink-gatekeeper' ); ?></strong></p>
						<p class="wk-report-ticket__main"><?php echo esc_html( sprintf( /* translators: %s: 条数 */ __( '%s 条', 'wink-gatekeeper' ), number_format_i18n( $url_view['caliber']['pending'] ) ) ); ?></p>
						<p class="wk-muted"><?php esc_html_e( '已进待审队列等你过目', 'wink-gatekeeper' ); ?></p>
					</div>
					<div class="wk-report-ticket">
						<p class="wk-report-ticket__name"><strong><?php esc_html_e( '垃圾箱（判违规）', 'wink-gatekeeper' ); ?></strong></p>
						<p class="wk-report-ticket__main"><?php echo esc_html( sprintf( /* translators: %s: 条数 */ __( '%s 条', 'wink-gatekeeper' ), number_format_i18n( $url_view['caliber']['spam'] ) ) ); ?></p>
						<p class="wk-muted"><?php esc_html_e( '已进垃圾箱', 'wink-gatekeeper' ); ?></p>
					</div>
				</div>
				<p class="wk-overview-actions">
					<a href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=moderated' ) ); ?>"><?php
						echo esc_html( sprintf(
							/* translators: %d: 待审核条数 */
							__( '去待审队列处理（%d 条）→', 'wink-gatekeeper' ),
							$url_view['caliber']['pending']
						) );
					?></a>
					· <a href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=spam' ) ); ?>"><?php
						echo esc_html( sprintf(
							/* translators: %d: 垃圾箱条数 */
							__( '去垃圾箱查看（%d 条）→', 'wink-gatekeeper' ),
							$url_view['caliber']['spam']
						) );
					?></a>
				</p>
				<p class="wk-muted"><?php esc_html_e( '判违规进垃圾箱、没看清进待审，都能手动恢复。', 'wink-gatekeeper' ); ?><?php
					if ( $url_view['gone'] > 0 ) {
						echo ' ' . esc_html( sprintf(
							/* translators: %d: 评论已删条数 */
							__( '另：评论已删除 %d 条。', 'wink-gatekeeper' ),
							$url_view['gone']
						) );
					}
				?></p>
				<?php
				/* 【M6·批F-a·§4-add2】原因明细折叠（URL 报告——与存量审核报告同款式）：
				 * evidence=search 的原因行补人话标记「网址打不开，依据搜索线索判定」——
				 * 人工复核一眼可知「此判定基于搜索线索而非页面正文」。渲染红线：原因=
				 * 攻击者可控（AI 原文/第三方摘要透传）——单次 esc_html 收口+截断（XSS）。 */
				$url_reasons = is_array( $url_view['reasons'] ?? null ) ? $url_view['reasons'] : array();
				?>
				<details id="wk-tools-url-audit-reasons">
					<summary><?php echo esc_html( sprintf(
						/* translators: %d: 原因类数 */
						__( '原因明细（%d 类）', 'wink-gatekeeper' ),
						count( $url_reasons )
					) ); ?></summary>
					<?php if ( array() !== $url_reasons ) : ?>
						<p><?php
							$url_parts = array();
							foreach ( $url_reasons as $url_reason_row ) {
								$url_prefix = 'search' === (string) ( $url_reason_row['evidence'] ?? '' )
									? __( '网址打不开，依据搜索线索判定', 'wink-gatekeeper' )
									: '';
							$url_parts[] = sprintf(
								'%2$s「%1$s」%3$d 条 → %4$s',
								self::truncate_reason( (string) ( $url_reason_row['reason'] ?? '' ) ), // 【M6·批H·H-8】截断补「…」（单点件）。
								'' !== $url_prefix ? '【' . $url_prefix . '】' : '',
								(int) ( $url_reason_row['n'] ?? 0 ),
								'spam' === (string) ( $url_reason_row['disposal'] ?? 'hold' ) ? '垃圾箱' : '待审核' // 【M6·批H3·H3-C】行尾处置去向。
							);
							}
							echo esc_html( implode( ' · ', $url_parts ) ); // XSS 红线：单次转义收口。
						?></p>
					<?php else : ?>
						<p class="wk-muted"><?php esc_html_e( '暂无命中', 'wink-gatekeeper' ); ?></p>
					<?php endif; ?>
				</details>
				<?php
				/* 按网站归堆表随报告迁址（原 Tab⑥ 报告卡内 U5 批节——「重新核查此站」
				 * 站长举报误判入口保留，nw §4.3）。 */
				$host_rows = $backlog_worker->host_aggregates();
				if ( array() !== $host_rows ) :
					?>
				<details id="wk-tools-url-audit-hosts">
					<summary><?php esc_html_e( '按网站归堆（网址核查判违规）', 'wink-gatekeeper' ); ?></summary>
					<table class="widefat striped" style="max-width:640px;">
						<thead><tr><th><?php esc_html_e( '网站', 'wink-gatekeeper' ); ?></th><th><?php esc_html_e( '命中评论', 'wink-gatekeeper' ); ?></th><th><?php esc_html_e( '判违规', 'wink-gatekeeper' ); ?></th><th><?php esc_html_e( '建议', 'wink-gatekeeper' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $host_rows as $host_row ) : ?>
							<tr>
								<td><?php echo esc_html( mb_substr( (string) $host_row['host'], 0, 60 ) ); ?></td>
								<td><?php echo (int) $host_row['hits']; ?></td>
								<td><?php echo (int) $host_row['rejects']; ?></td>
								<td>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wink_gatekeeper_url_host_purge&host=' . rawurlencode( (string) $host_row['host'] ) ), 'wink_gatekeeper_url_host_purge' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( '清空此站的网址核查缓存并重新核查？', 'wink-gatekeeper' ) ); ?>');"><?php esc_html_e( '重新核查此站', 'wink-gatekeeper' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</details>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<section class="wk-card">
			<h2><?php esc_html_e( '导入/导出设置', 'wink-gatekeeper' ); ?></h2>
			<p class="wk-muted"><?php esc_html_e( 'JSON 导出（默认不含密钥）/ 导入校验（schema 版本校验）。M4 实装（F27）。', 'wink-gatekeeper' ); ?></p>
		</section>
		<section class="wk-card">
			<h2><?php esc_html_e( '驱动自检与降级演练', 'wink-gatekeeper' ); ?></h2>
			<p class="wk-muted"><?php esc_html_e( '密钥试调用、前端 JS 可达性探测、真实 IP 自检（CIDR 校验 + 解析预览）、降级演练。M3/M4 实装（F28/F33）。', 'wink-gatekeeper' ); ?></p>
		</section>
		<section class="wk-card">
			<h2><?php esc_html_e( '系统信息', 'wink-gatekeeper' ); ?></h2>
			<p class="wk-muted"><?php esc_html_e( 'PHP/WP 版本要求检查卡片（沿上游 baby_wp_check_requirements 交互）。', 'wink-gatekeeper' ); ?></p>
		</section>
		<?php
	}

	/* -------------------------------------------------------------------
	 * Tab⑧ 站点增强（【M5·UI-A】UI 设计块 1——U1「网站」字段两独立开关 +
	 * U2 头像国内镜像；两开关完全独立互不关联（定稿 §0.1 唯一权威）。
	 * 文案红线：A 行说明只讲收集不提 B、B 行只讲显示不提 A；「后台始终可见+
	 * 存量不删」背景常显说明只写卡内顶部一处（段级一次）。
	 * ------------------------------------------------------------------ */

	/**
	 * Tab⑧ 渲染。两卡：卡一评论「网站」字段 / 卡二头像来源。
	 *
	 * @return void
	 */
	private static function render_tab_enhance(): void {
		$settings         = self::settings();
		$collect_enabled  = ! empty( $settings['comments']['site_field']['enabled'] );
		$link_enabled     = ! empty( $settings['comments']['site_link']['enabled'] );
		$avatar_source    = \WinkGatekeeper\Site\AvatarSources::normalize( $settings['avatar']['source'] ?? null );
		$avatar_custom    = (string) ( $settings['avatar']['custom_base'] ?? '' );
		/* A 关闭提示（条件显隐）：收集已关闭 **且** 网址核查特性已在场（url_audit 子树——
		 * U5 批落位后自动生效；特性不在场时「提示审核了一个不存在的功能」违反 F33 纪律
		 * （不用的代码路径=不工作的代码路径），本批零渲染）。UI 设计块 1.3 的「A=true
		 * 时显示」按定稿 §1 权威语义实施=**收集关闭时**（键语义 enabled=true=收集）。 */
		$url_audit_exists = array_key_exists( 'url_audit', $settings );
		?>
		<section class="wk-card" id="wk-site-fields-card">
			<h2><?php esc_html_e( '评论「网站」字段', 'wink-gatekeeper' ); ?></h2>
			<p id="wk-site-fields-constant-note" class="wk-muted"><?php esc_html_e( '评论者可以填一个自己的网站。无论下面开关怎么设，后台评论区始终显示这个网址供你识别来访者；已存的网址不会删除。', 'wink-gatekeeper' ); ?></p>

			<table class="form-table">
				<tr id="wk-site-field-collect-row">
					<th scope="row"><?php esc_html_e( '收集新评论的网站字段', 'wink-gatekeeper' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="wink_gatekeeper_settings[comments][site_field][enabled]" value="0" />
							<input type="checkbox" name="wink_gatekeeper_settings[comments][site_field][enabled]" value="1" <?php checked( $collect_enabled ); ?>>
							<?php esc_html_e( '评论表单出现「网站」一栏，新评论保存网址', 'wink-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( '不勾选（默认）时，评论表单不出现该栏、提交的网址一律丢弃——防止垃圾提交程序试探采集。已发表评论不受影响。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<?php if ( ! $collect_enabled && $url_audit_exists ) : ?>
				<tr>
					<td colspan="2" id="wk-site-field-a-notice">
						<div class="notice notice-warning inline"><p><?php esc_html_e( '网站字段收集已关闭——网址核查对新评论自动失效，存量网址仍可在工具页的「存量网址核查」中处理。', 'wink-gatekeeper' ); ?></p></div>
					</td>
				</tr>
				<?php endif; ?>
				<tr id="wk-site-field-delink-row">
					<th scope="row"><?php esc_html_e( '前台评论者名字保留可点链接', 'wink-gatekeeper' ); ?></th>
					<td>
						<label>
							<input type="hidden" name="wink_gatekeeper_settings[comments][site_link][enabled]" value="0" />
							<input type="checkbox" name="wink_gatekeeper_settings[comments][site_link][enabled]" value="1" <?php checked( $link_enabled ); ?>>
							<?php esc_html_e( '前台评论区的评论者名字显示为可点开的链接', 'wink-gatekeeper' ); ?>
						</label>
						<p class="description"><?php esc_html_e( '不勾选（默认）时只显示名字本身。你自己在后台看评论时始终是可点链接。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
			</table>
		</section>

		<section class="wk-card" id="wk-avatar-card">
			<h2><?php esc_html_e( '评论者头像来源', 'wink-gatekeeper' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="wk-avatar-source-select"><?php esc_html_e( '头像来源', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<select id="wk-avatar-source-select" name="wink_gatekeeper_settings[avatar][source]">
							<option value="official" <?php selected( $avatar_source, 'official' ); ?>><?php esc_html_e( '未配置（用 WordPress 官方源）', 'wink-gatekeeper' ); ?></option>
							<option value="cravatar" <?php selected( $avatar_source, 'cravatar' ); ?>><?php esc_html_e( 'Cravatar（国内镜像，官方兼容）', 'wink-gatekeeper' ); ?></option>
							<option value="weavatar" <?php selected( $avatar_source, 'weavatar' ); ?>><?php esc_html_e( 'WeAvatar（国内镜像，多级匹配）', 'wink-gatekeeper' ); ?></option>
							<option value="sepcc" <?php selected( $avatar_source, 'sepcc' ); ?>><?php esc_html_e( 'sep.cc（国内镜像）', 'wink-gatekeeper' ); ?></option>
							<option value="custom" <?php selected( $avatar_source, 'custom' ); ?>><?php esc_html_e( '自定义地址', 'wink-gatekeeper' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( '国内访客加载官方源可能很慢或失败；选用国内镜像可提速。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
				<tr id="wk-avatar-custom-base-row"<?php echo 'custom' === $avatar_source ? '' : ' hidden'; ?>>
					<th scope="row"><label for="wk-avatar-custom-base"><?php esc_html_e( '自定义地址', 'wink-gatekeeper' ); ?></label></th>
					<td>
						<input type="url" id="wk-avatar-custom-base" name="wink_gatekeeper_settings[avatar][custom_base]" class="regular-text code" value="<?php echo esc_attr( $avatar_custom ); ?>" placeholder="https://example.com/avatar/">
						<p class="description"><?php esc_html_e( '须以 https:// 开头、以 /avatar/ 结尾——按镜像站说明填写；地址不合法时不改写头像来源（用官方源）。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
			</table>
			<p id="wk-avatar-switch-note" class="wk-muted"><?php esc_html_e( '切换头像来源后，已缓存的旧头像可能过一段时间才更新，不是故障。', 'wink-gatekeeper' ); ?></p>
			<p>
				<button type="button" class="button" id="wk-avatar-test-btn"
					data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX_TEST_AVATAR ) ); ?>"><?php esc_html_e( '测试当前头像来源', 'wink-gatekeeper' ); ?></button>
				<span id="wk-avatar-test-result" class="wk-probe"></span>
			</p>
		</section>
		<?php
	}

	/**
	 * Tab⑧ 卡二「测试当前头像来源」AJAX 处理器（【M5·U2】）。
	 *
	 * 语义：测的是**这个镜像通不通**（非密钥）；以表单当前所选 source/custom_base 为准
	 * （未保存也可测——与「配置与实测同域」D7 裁决一致）；custom 地址非法=红态提示不合法。
	 * 传输脊 RemoteClient（D13 纪律——全库唯一 HTTP 出口），不带 usage_key=零计量零写
	 * 零事件（诊断查询同口径）；固定 email hash、每源 1 请求（UI 设计块 1.3）。
	 *
	 * @return void
	 */
	public static function ajax_test_avatar_source(): void {
		check_ajax_referer( self::AJAX_TEST_AVATAR, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足：需要 manage_options。', 'wink-gatekeeper' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer 已验。
		$post   = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		$source = \WinkGatekeeper\Site\AvatarSources::normalize( isset( $post['source'] ) && is_scalar( $post['source'] ) ? (string) $post['source'] : '' );
		$custom = isset( $post['custom_base'] ) && is_scalar( $post['custom_base'] ) ? trim( (string) $post['custom_base'] ) : '';

		$base = \WinkGatekeeper\Site\AvatarSources::base_for( $source, $custom );
		if ( null === $base ) {
			if ( 'custom' === $source ) {
				// custom 但地址非法：给「缺参数」黄态（逐字段指出，同凭据测试黄态语义）。
				wp_send_json_success( array(
					'state'   => 'yellow',
					'message' => __( '自定义地址不合法：须以 https:// 开头、以 /avatar/ 结尾。', 'wink-gatekeeper' ),
				) );
			}
			// official：探测官方源（对照形态——官方源慢时正好引导切镜像）。
			$base = 'https://secure.gravatar.com/avatar/';
		}

		$url = $base . self::AVATAR_PROBE_HASH . '?s=80&d=mm';
		$client = new RemoteClient(); // WP 边界默认传输（探测一次零计量）。
		$result = $client->get( $url, array( 'timeout' => 5 ) );

		$status  = $result->status();
		$headers = $result->headers();
		$ctype   = '';
		foreach ( array( 'content-type', 'Content-Type' ) as $key ) {
			if ( isset( $headers[ $key ] ) && is_string( $headers[ $key ] ) ) {
				$ctype = $headers[ $key ];
				break;
			}
		}
		$latency = $result->latency_ms();
		$seconds = null !== $latency ? ( $latency / 1000 ) : 0.0;
		$state   = \WinkGatekeeper\Site\AvatarSources::judge( $status, $ctype, $seconds );

		$messages = array(
			'ok'   => sprintf(
				/* translators: %s: 响应耗时秒。 */
				__( '正常（%.1f 秒）', 'wink-gatekeeper' ),
				$seconds
			),
			'slow' => sprintf(
				/* translators: %s: 响应耗时秒。 */
				__( '能用但慢（%.1f 秒）', 'wink-gatekeeper' ),
				$seconds
			),
			'fail' => __( '连不上该镜像（或返回的不是图片）。', 'wink-gatekeeper' ),
		);

		wp_send_json_success( array(
			'state'   => $state,
			'message' => $messages[ $state ] ?? (string) __( '测试失败。', 'wink-gatekeeper' ),
			'source'  => $source,
		) );
	}
}
