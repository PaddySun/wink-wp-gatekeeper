<?php
/**
 * 设置页（WP 边界层，03 §1：单 option + 7 Tab + 品牌页头）。
 *
 * - register_setting 的 sanitize 回调桥到纯逻辑层 Sanitize::clean()，
 *   再经 SettingsSchema::merge_defaults() 补全默认树（schema_version 收口）；
 * - 渲染：Night #111114 品牌页头 + 眨眼 logo + 七 Tab 骨架（M0：区块占位，
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
use WinkGatekeeper\Drivers\Review\TencentTmsDriver;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Settings\CaptchaTabSpec;
use WinkGatekeeper\Settings\CredentialProbe;
use WinkGatekeeper\Settings\DriverConfigSpec;
use WinkGatekeeper\Stats\DriverHealth;

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
					<img
						id="wk-brand-logo"
						class="wk-logo"
						src="<?php echo esc_url( WINK_GATEKEEPER_URL . 'assets/brand/logo-wink.svg' ); ?>"
						data-blink-src="<?php echo esc_url( WINK_GATEKEEPER_URL . 'assets/brand/logo-wink-blink.svg' ); ?>"
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
			case 'overview':
			default:
				self::render_tab_overview();
				break;
		}
	}

	/* -------------------------------------------------------------------
	 * Tab① 总览仪表盘（M0：静态占位卡）
	 * ------------------------------------------------------------------ */

	private static function render_tab_overview(): void {
		$stats = \WinkGatekeeper\Stats\StatsCache::get();
		$stats_disabled = empty( self::settings()['stats']['enabled'] ); // FIX-δ：开关关闭对站长可见。
		$today_rejects = 0;
		$per_gate = array();
		foreach ( $stats['by_gate'] as $gate_id => $counts ) {
			$gate_rejects = (int) ( $counts['reject'] ?? 0 );
			$today_rejects += $gate_rejects;
			if ( $gate_rejects > 0 ) {
				$per_gate[] = $gate_id . ' ' . $gate_rejects;
			}
		}
		?>
		<section class="wk-card wk-card--metric">
			<h2><?php esc_html_e( '今日拦截', 'wink-gatekeeper' ); ?></h2>
			<p class="wk-metric"><span class="wk-metric__num"><?php echo esc_html( (string) $today_rejects ); ?></span></p>
			<?php if ( $stats_disabled ) : ?>
				<p class="wk-muted"><strong><?php esc_html_e( '计数已停用', 'wink-gatekeeper' ); ?></strong><?php esc_html_e( '——统计开关关闭期间的拦截不计入读数，事件表照常落库（以 Tab⑥ 为准）。', 'wink-gatekeeper' ); ?></p>
			<?php endif; ?>
			<p class="wk-muted"><?php echo esc_html( array() !== $per_gate ? implode( ' · ', $per_gate ) : __( '暂无数据（pass 默认不计数）', 'wink-gatekeeper' ) ); ?></p>
		</section>
		<section class="wk-card">
			<h2><?php esc_html_e( '待审队列', 'wink-gatekeeper' ); ?></h2>
			<p class="wk-muted"><?php esc_html_e( '因 review 降级进待审的评论数（不含 IP 疑似强制验证码类事件）。云审核 M3 起有数据。', 'wink-gatekeeper' ); ?></p>
		</section>
		<section class="wk-card">
			<h2><?php esc_html_e( '驱动健康', 'wink-gatekeeper' ); ?></h2>
			<?php self::render_driver_health_rows(); ?>
			<?php
			/* 【v1.5·C1】test_mode 管理员提醒双位之一（02 §2.8 安全门 #3）：红点常显行
			 * （不占 DriverHealth 状态机；wp_active 内含 24h 懒过期判定——过期不显示）。 */
			if ( \WinkGatekeeper\Gates\TestMode::wp_active() ) {
				$tm_settings = self::settings()['captcha'];
				$tm_since    = isset( $tm_settings['test_mode_since'] ) && is_numeric( $tm_settings['test_mode_since'] ) ? (int) $tm_settings['test_mode_since'] : 0;
				$tm_time     = $tm_since > 0 ? date_i18n( 'H:i', $tm_since ) : '—'; // WP 边界（站点时区）。
				?>
				<div class="notice notice-warning inline" style="margin-top:10px;">
					<p><span class="wk-dot wk-dot--alert" aria-hidden="true"></span> <strong><?php esc_html_e( 'test_mode 启用中（自 ' . $tm_time . '）', 'wink-gatekeeper' ); ?></strong>
					——<?php esc_html_e( '全站人机验证旁路，仅用于链路演练；24 小时后自动过期（旁路事件标注 drill 不进统计、不写验证短缓存）。', 'wink-gatekeeper' ); ?></p>
				</div>
			<?php } ?>
		</section>
		<section class="wk-card">
			<h2><?php esc_html_e( '事件流预览', 'wink-gatekeeper' ); ?></h2>
			<p class="wk-muted"><?php esc_html_e( '最近 10 条事件（链接到 Tab⑥ 完整事件流）。事件写入 M1 起（F09）。', 'wink-gatekeeper' ); ?></p>
		</section>
		<?php
	}

	/**
	 * Tab① 驱动健康灯行（02 §5 状态机；C4 云审核域消费面）。
	 *
	 * 【v1.4·反馈1/R4 §D.2-D.3/R1 §6】增强：
	 *   ① 必填字段检查 registry settings.required 派生（DriverConfigSpec——修复 deepseek 等
	 *      非 CAM 驱动恒误报「凭据缺」的硬编码 secret_id/secret_key 缺陷）；
	 *   ② 计数三态区分：停转（当日停转（<state>）——零调用）/ 未装配（chain 声明但未实装或
	 *      必填缺：未装配——调用未发起，Bootstrap 静默剔除语义可见化）/ 未启用（未启用云端驱动）；
	 *   ③ 最近错误摘要：note 追加该驱动最新一条故障行（rule_hit + HH:MM，读模型 events 表）；
	 *   ④ 云端审核已启用 && chain 非空 && 全部未装配 → 顶部黄条（可见性兜底）。
	 *
	 * @return void
	 */
	private static function render_driver_health_rows(): void {
		$settings = self::settings();
		$cloud    = $settings['review']['cloud'];
		$chain    = is_array( $cloud['chain'] ?? null ) ? $cloud['chain'] : array();
		$usage    = \WinkGatekeeper\Stats\Usage::get();
		$rows     = array();
		$usable   = 0; // 已装配（registry 在场 + 必填齐全）驱动数。

		// registry 元数据（label/settings.required/label 派生——05 §4 七维单一来源）。
		$registry = null !== self::$driver_registry ? self::$driver_registry : null;
		$cloud_registered = null !== $registry ? $registry->by_type( 'review' ) : array();
		unset( $cloud_registered['local_rules'] ); // 本地驱动无外部故障面，不入健康行。

		foreach ( $chain as $driver_id ) {
			if ( ! is_string( $driver_id ) || '' === $driver_id ) {
				continue;
			}
			$meta  = $cloud_registered[ $driver_id ] ?? null;
			$label = null !== $meta && is_string( $meta['label'] ?? null ) ? $meta['label'] : $driver_id;
			if ( null === $meta && 'tencent_tms' === $driver_id && class_exists( TencentTmsDriver::class ) ) {
				$meta = TencentTmsDriver::registry_entry(); // registry 未注入的降级读取（单测/早载场景）。
			}

			$config = is_array( $cloud[ $driver_id ] ?? null ) ? $cloud[ $driver_id ] : array();
			$halted = DriverHealth::state_today( $driver_id );
			$quota  = (int) ( $config['daily_quota'] ?? 0 );
			$used   = (int) ( \WinkGatekeeper\Stats\Usage::daily_total_for( $usage, $driver_id ) );

			// 必填缺失清单：registry settings.required 派生（显示名——与 Tab③ 黄条同源）。
			$missing = null !== $meta && is_array( $meta )
				? DriverConfigSpec::missing_fields( $meta, $config )
				: array();

			// 最近错误摘要（读模型：events 该驱动最新一条故障行——rule_hit + HH:MM）。
			$fault = \WinkGatekeeper\Stats\EventsTable::latest_driver_fault( $driver_id );
			$fault_note = null !== $fault
				? '；最近错误 ' . $fault['rule_hit'] . ' ' . $fault['time_hm']
				: '';

			if ( null === $meta ) {
				// 未实装（chain 声明了 registry 外的 id——如老站残留 deepseek 链值）。
				$state = '未装配';
				$dot   = 'wk-dot--alert';
				$note  = '未装配——调用未发起（驱动未实装，静默剔除语义可见化）';
			} elseif ( array() !== $missing ) {
				$state = '未装配';
				$dot   = 'wk-dot--alert';
				$note  = '未装配——调用未发起（必填缺：' . implode( '、', $missing ) . '）';
			} else {
				$usable++;
				if ( DriverHealth::STATE_CONFIG_ERROR === $halted ) {
					$state = '停转';
					$dot   = 'wk-dot--alert';
					$note  = '当日停转（config_error）——零调用；密钥/授权被拒，到 Tab③ 修正凭据后点「测试凭据」解除' . $fault_note;
				} elseif ( DriverHealth::STATE_QUOTA_EXHAUSTED === $halted ) {
					$state = '停转';
					$dot   = 'wk-dot--warn';
					$note  = "当日停转（quota_exhausted）——今日 {$used}/{$quota} 已达配额，走故障降级档；次日自动恢复" . $fault_note;
				} else {
					$state = '正常';
					$dot   = 'wk-dot--ok';
					$note  = $quota > 0 ? "今日用量 {$used}/{$quota}" : "今日用量 {$used}（不限配额）";
					$note .= $fault_note;
				}
			}
			$rows[] = array( $label, $state, $dot, $note );
		}

		if ( ! empty( $cloud['enabled'] ) && array() !== $chain && 0 === $usable ) {
			// 「云端审核已启用但无可用驱动」黄条（全入待审+计数空类故障形态的可见性兜底）。
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( '云端审核已启用但无可用驱动——评论将按本地规则 + 降级档处理。', 'wink-gatekeeper' ) . '</strong></p></div>';
		}

		if ( array() === $rows ) {
			echo '<p class="wk-muted">' . esc_html__( '未启用云端驱动（本地规则无外部故障面，恒正常）。', 'wink-gatekeeper' ) . '</p>';
			return;
		}
		?>
		<table class="wk-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( '驱动', 'wink-gatekeeper' ); ?></th>
					<th scope="col"><?php esc_html_e( '状态', 'wink-gatekeeper' ); ?></th>
					<th scope="col"><?php esc_html_e( '说明', 'wink-gatekeeper' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row[0] ); ?></td>
						<td><span class="wk-dot <?php echo esc_attr( $row[2] ); ?>" aria-hidden="true"></span> <?php echo esc_html( $row[1] ); ?></td>
						<td class="wk-muted"><?php echo esc_html( $row[3] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
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
						<p class="description"><?php esc_html_e( '自适应：同一账户跨 IP 累计 3 次口令错误（仅统计真实存在的账户）、或同 IP 聚合 10 次、或站点级账号警报点亮后，登录页才出现验证码，平时零打扰；常显：每次登录都先验证。失败计数对 XML-RPC 同样生效；验证码驱动未配置时超阈也不拦。', 'wink-gatekeeper' ); ?></p>

						<?php $adaptive = isset( $settings['captcha']['login_adaptive'] ) && is_array( $settings['captcha']['login_adaptive'] ) ? $settings['captcha']['login_adaptive'] : array(); ?>
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
										<input type="number" id="wk-login-ip-threshold" min="1" name="<?php echo esc_attr( self::field_name( array( 'captcha', 'login_adaptive', 'ip_threshold' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $adaptive['ip_threshold'] ?? 10 ) ); ?>" class="small-text" />
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
											<?php $xmlrpc_login = ! isset( $settings['gates']['login']['xmlrpc_login'] ) || (bool) $settings['gates']['login']['xmlrpc_login']; ?>
											<!-- 反选形态：hidden 恒 1（默认开），勾选提交 0（后值覆盖前值 = 关闭）。 -->
											<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'gates', 'login', 'xmlrpc_login' ) ) ); ?>" value="1" />
											<label>
												<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'gates', 'login', 'xmlrpc_login' ) ) ); ?>" value="0" <?php checked( false, $xmlrpc_login ); ?> />
												<?php esc_html_e( '彻底关闭 XML-RPC 登录（推荐加固）', 'wink-gatekeeper' ); ?>
											</label>
											<p class="description"><?php esc_html_e( '勾选后登录类 XML-RPC 方法（wp.getUsersBlogs、system.multicall 携带的认证等）整体 405 拒绝；system.listMethods 等只读方法不受影响。超阈计数已会先行逐次拒绝 XML-RPC 认证（验证码无法在 XML-RPC 内呈现），本开关是彻底封死的可选加固。', 'wink-gatekeeper' ); ?></p>
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
	 * password 型字段（04 §2 安全规则：不回显键值，仅「已配置✓」状态提示）。
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

	/* -------------------------------------------------------------------
	 * Tab③ 内容审核（M1 本地规则四闸 + C4 云端审核 TMS：03 Tab③ v1.3 同款配置模式）
	 * ------------------------------------------------------------------ */

	private static function render_tab_review(): void {
		$settings = self::settings();
		$local    = $settings['review']['local'];
		$cloud    = $settings['review']['cloud'];
		$tms      = is_array( $cloud['tencent_tms'] ?? null ) ? $cloud['tencent_tms'] : array();
		$green    = is_array( $cloud['aliyun_green'] ?? null ) ? $cloud['aliyun_green'] : array();
		$chain    = is_array( $cloud['chain'] ?? null ) ? $cloud['chain'] : array();
		$tms_in_chain   = in_array( 'tencent_tms', $chain, true );
		$green_in_chain = in_array( 'aliyun_green', $chain, true );

		// 【v1.4·R4】DriverConfigSpec 通用件消费：registry 已实装 review 驱动（local_rules 剔除）
		// + 运行时停转映射（下拉「配置错误」点）+ 链路汇总行素材。
		$cloud_registered = array();
		if ( null !== self::$driver_registry ) {
			$cloud_registered = self::$driver_registry->by_type( 'review' );
			unset( $cloud_registered['local_rules'] );
		}
		if ( array() === $cloud_registered ) {
			// registry 未注入的降级读取（单测/早载场景）——已实装两驱动硬编码兜底。
			$cloud_registered = array(
				TencentTmsDriver::DRIVER_ID      => TencentTmsDriver::registry_entry(),
				AliyunGreenPlusDriver::DRIVER_ID => AliyunGreenPlusDriver::registry_entry(),
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
		);

		// 当前选中驱动（下拉=chain[0] 可视面；未配置=''）。
		$cloud_selected = $tms_in_chain ? 'tencent_tms' : ( $green_in_chain ? 'aliyun_green' : '' );

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
			</table>
		</section>
		<section class="wk-card">
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
						<p class="description"><?php esc_html_e( '选择驱动后下方显示其配置区（各厂商配置独立保存、切换不清空已填）；「未配置」= 云端审核阶段跳过（enabled 开 + 未配置 = 与 Tab② 语义对齐）。DeepSeek / 模力 / 百度为后续里程碑驱动（选前不可用）。', 'wink-gatekeeper' ); ?></p>
					</td>
				</tr>
			</table>
		</section>
		<?php if ( $tms_in_chain ) : ?>
			<?php
			/* 【v1.4·反馈9/R4 §C】v1.2「顶部集中 PIPL 黄条」作废——合规行迁入各驱动配置栏
			 * （registry privacy 维渲染，禁 SettingsPage 硬编码合规文案）；集中位降级为链路汇总行（底部）。 */
			$tms_meta      = isset( $cloud_registered['tencent_tms'] ) ? $cloud_registered['tencent_tms'] : TencentTmsDriver::registry_entry();
			$tms_privacy   = DriverConfigSpec::privacy_notice( $tms_meta, '腾讯云 TMS 文本审核' );
			?>
			<section class="wk-card" id="wk-tms-config-area">
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

			<?php elseif ( $green_in_chain ) : ?>
				<?php
				/* 【v1.5·B5】阿里文本审核增强版 PLUS 配置区（03 Tab③【v1.5】——与 TMS 配置区同构：
				 * 合规行（registry privacy 维）/ 接入指引折叠 / 测试凭据三态按钮 / 双层必填校验。
				 * 无 reject_score 字段（RiskLevel 双轨制，05 §2.5——不引入阈值语义）。 */
				$green_meta    = isset( $cloud_registered['aliyun_green'] ) ? $cloud_registered['aliyun_green'] : AliyunGreenPlusDriver::registry_entry();
				$green_privacy = DriverConfigSpec::privacy_notice( $green_meta, '阿里云内容安全·文本审核增强版 PLUS' );
				?>
				<section class="wk-card" id="wk-green-config-area">
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
			<?php else : ?>
				<section class="wk-card">
					<h2><?php esc_html_e( '云端审核驱动', 'wink-gatekeeper' ); ?></h2>
					<p class="wk-muted"><?php esc_html_e( '未配置 = 云端审核阶段跳过（enabled 开 + 未配置 = 云端阶段跳过，与 Tab② 语义对齐）。选择驱动并保存后，此处出现该驱动的配置区与逐驱动合规行（常驻不可关闭）。', 'wink-gatekeeper' ); ?></p>
				</section>
			<?php endif; ?>

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
	 * Tab④ IP 信誉（M0 骨架：分区一 真实 IP 来源 M1 起生效）
	 * ------------------------------------------------------------------ */

	private static function render_tab_iprep(): void {
		?>
		<section class="wk-card">
			<h2><?php esc_html_e( '真实 IP 来源', 'wink-gatekeeper' ); ?></h2>
			<p class="wk-muted"><?php esc_html_e( '获取模式（直连/XFF 信任链/自定义头）+ 信任代理网段（Cloudflare/EdgeOne 预置 + 自定义 CIDR）+ 解析预览。影响全部 IP 链路，M1 起生效（F29）。', 'wink-gatekeeper' ); ?></p>
		</section>
		<section class="wk-card">
			<h2><?php esc_html_e( 'IP 信誉查询', 'wink-gatekeeper' ); ?></h2>
			<p class="wk-muted"><?php esc_html_e( 'AbuseIPDB（M3）：阈值双滑杆（疑似 60/恶意 85）、缓存 1h、日配额 950、降级（放行/强制验证码）。', 'wink-gatekeeper' ); ?></p>
		</section>
		<section class="wk-card">
			<h2><?php esc_html_e( '验证失败上报', 'wink-gatekeeper' ); ?></h2>
			<p class="wk-muted"><?php esc_html_e( '「有票才报」：仅验证码核验失败（有票）触发；同 IP 冷却 60 分钟、全局速率 60 次/小时。启用门禁需先通过真实 IP 自检。M3 实装（F30）。', 'wink-gatekeeper' ); ?></p>
		</section>
		<?php
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
				// 语气三原则常驻小抄（03 Tab⑤）。
				esc_html_e( '语气三原则：只调侃行为 / 留台阶 / 带下一步。', 'wink-gatekeeper' );
				?>
				<br />
				<?php esc_html_e( '占位符：{min_length} {max_length} {event_no} {rule} {time} {contact_email}——{rule} 前台只显示规则键名（命中的具体关键词仅站长在事件流可见）。', 'wink-gatekeeper' ); ?>
			</p>
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
		</section>

		<section class="wk-card">
			<h2><?php esc_html_e( '拦截页自定义', 'wink-gatekeeper' ); ?></h2>
			<p class="wk-muted">
				<?php
				// 【v1.5·B1】站长自定义拦截页覆盖位（Wordfence blockCustomText 先例）。
				esc_html_e( '作用于品牌整页拦截页的标题与正文（全部场景共用）。留空 = 使用各场景默认文案。占位符：{event_no} {rule} {time} {contact_email}——{rule} 只显示规则键名。', 'wink-gatekeeper' );
				?>
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
		$usage    = \WinkGatekeeper\Stats\Usage::get();

		$verdict_labels = array(
			'reject' => __( '拦截', 'wink-gatekeeper' ),
			'review' => __( '待审', 'wink-gatekeeper' ),
			'error'  => __( '故障', 'wink-gatekeeper' ),
		);

		// 评论域驱动标签（DriverRegistry；登录域驱动 C3 接入时并入 login 分区）。
		$driver_labels = array();
		foreach ( ( null !== self::$driver_registry ? self::$driver_registry->all() : array() ) as $driver_id => $meta ) {
			$driver_labels[ $driver_id ] = sprintf( /* translators: %s: 驱动名 */ __( '驱动：%s', 'wink-gatekeeper' ), (string) $meta['label'] );
		}

		// 【v1.4·R1 §6-1】usage 上下文：驱动行今日调用数（Usage）+ 停转态（DriverHealth）。
		$usage_today = array();
		foreach ( $driver_labels as $usage_id => $usage_label ) {
			$usage_today[ (string) $usage_id ] = (int) \WinkGatekeeper\Stats\Usage::daily_total_for( $usage, (string) $usage_id );
		}
		$halted_map = array();
		foreach ( array_keys( $driver_labels ) as $health_id ) {
			$halt_state = DriverHealth::state_today( (string) $health_id );
			if ( null !== $halt_state ) {
				$halted_map[ (string) $health_id ] = $halt_state;
			}
		}
		$partitions = \WinkGatekeeper\Stats\InterceptDigest::partitions(
			$cache,
			$driver_labels,
			array(
				'usage_today' => $usage_today,
				'halted'      => $halted_map,
			)
		);

		// 留档量黄条数据（spam 计数 = 本插件留档且仍在垃圾分队）。
		$spam_count = \WinkGatekeeper\Stats\StatsCache::archived_spam_count();
		$spam_warn  = \WinkGatekeeper\Stats\InterceptDigest::spam_warn( $spam_count );
		?>
		<section class="wk-card wk-card--metric">
			<h2><?php esc_html_e( '今日拦截', 'wink-gatekeeper' ); ?></h2>
			<?php
			$today_total = 0;
			foreach ( $cache['by_gate'] as $counts ) {
				$today_total += (int) ( $counts['reject'] ?? 0 );
			}
			?>
			<p class="wk-metric"><span class="wk-metric__num"><?php echo esc_html( (string) $today_total ); ?></span></p>
			<?php if ( empty( $settings['stats']['enabled'] ) ) : ?>
				<p class="wk-muted"><strong><?php esc_html_e( '计数已停用', 'wink-gatekeeper' ); ?></strong><?php esc_html_e( '——统计开关关闭期间的拦截不计入读数，事件表照常落库（下方事件流为准）。', 'wink-gatekeeper' ); ?></p>
			<?php endif; ?>
			<p class="wk-muted">
				<?php
				echo esc_html( sprintf( /* translators: %s: 累计拦截数 */ __( '累计拦截 %s 次', 'wink-gatekeeper' ), (string) $cache['total']['reject'] ) );
				?>
			</p>
		</section>

		<?php if ( $spam_warn ) : ?>
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

		<section class="wk-card">
			<h2><?php echo esc_html( $partitions['comment']['label'] ); ?></h2>
			<?php self::render_partition_table( $partitions['comment']['rows'], $verdict_labels ); ?>
			<p class="wk-muted"><?php esc_html_e( 'pass 默认不记（采样为高级开关）；仪表盘读计数缓存（快），事件流列表页以事件表为准（准）——M3 统计面板 v2。', 'wink-gatekeeper' ); ?></p>
			<p>
				<label>
					<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'stats', 'enabled' ) ) ); ?>" value="0" />
					<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'stats', 'enabled' ) ) ); ?>" value="1" <?php checked( ! empty( $settings['stats']['enabled'] ) ); ?> />
					<?php esc_html_e( '启用统计计数', 'wink-gatekeeper' ); ?>
				</label>
			</p>
			<?php
			/* 【v1.4·反馈1/R4 §D.4】统计选项折叠区增补：stats.raw_capture（错误原始返回快照
			 * 开关，默认开）+ stats.raw_max_bytes（截断字节，钳 128–2048——04 §2/§3）。 */
			?>
			<p>
				<label>
					<input type="hidden" name="<?php echo esc_attr( self::field_name( array( 'stats', 'raw_capture' ) ) ); ?>" value="0" />
					<input type="checkbox" name="<?php echo esc_attr( self::field_name( array( 'stats', 'raw_capture' ) ) ); ?>" value="1" <?php checked( ! empty( $settings['stats']['raw_capture'] ) ); ?> />
					<?php esc_html_e( '记录云端错误原始返回（脱敏快照 meta.raw）', 'wink-gatekeeper' ); ?>
				</label>
				<br />
				<label>
					<?php esc_html_e( '快照截断字节', 'wink-gatekeeper' ); ?>
					<input type="number" min="128" max="2048" name="<?php echo esc_attr( self::field_name( array( 'stats', 'raw_max_bytes' ) ) ); ?>" value="<?php echo esc_attr( (string) ( $settings['stats']['raw_max_bytes'] ?? 512 ) ); ?>" class="small-text" />
					<span class="wk-muted"><?php esc_html_e( '（128–2048，默认 512；body/message 各自截断）', 'wink-gatekeeper' ); ?></span>
				</label>
			</p>
		</section>

		<section class="wk-card">
			<h2><?php esc_html_e( '今日故障', 'wink-gatekeeper' ); ?></h2>
			<?php
			/* 【v1.4·反馈1/R4 §D.2】今日故障卡：故障行按 rule_hit 分组计数（归类口径=
			 * EventsTable::is_fault——verdict=error 与 remote_/contract_mismatch/driver_halted 前缀族，
			 * 含 captcha 阶段以 reject 落库的远端故障；分布来自事件表、总数与计数缓存对账取大）。 */
			$fault_dist = \WinkGatekeeper\Stats\EventsTable::today_fault_distribution();
			$fault_total = array_sum( $fault_dist );
			foreach ( $cache['by_driver'] as $fault_driver_counts ) {
				$fault_total += (int) ( is_array( $fault_driver_counts ) ? ( $fault_driver_counts['error'] ?? 0 ) : 0 );
			}
			?>
			<p class="wk-metric"><span class="wk-metric__num"><?php echo esc_html( (string) $fault_total ); ?></span></p>
			<?php if ( array() !== $fault_dist ) : ?>
				<p class="wk-muted">
					<?php
						$fault_parts = array();
						foreach ( $fault_dist as $fault_rule => $fault_n ) {
							$fault_parts[] = esc_html( (string) $fault_rule ) . ' ×' . esc_html( (string) $fault_n );
						}
						echo implode( ' · ', $fault_parts ); // 逐段已 esc_html（rule_hit 驱动来源文本一律转义）。
					?>
				</p>
			<?php else : ?>
				<p class="wk-muted"><?php esc_html_e( '今日无云端故障（调用正常或未启用云端驱动）。', 'wink-gatekeeper' ); ?></p>
			<?php endif; ?>
		</section>

		<section class="wk-card">
			<h2><?php echo esc_html( $partitions['login']['label'] ); ?></h2>
			<?php if ( $partitions['login']['empty'] ) : ?>
				<p class="wk-muted"><?php echo esc_html( $partitions['login']['empty_note'] ); ?></p>
			<?php else : ?>
				<?php self::render_partition_table( $partitions['login']['rows'], $verdict_labels ); ?>
			<?php endif; ?>
		</section>

		<?php self::render_recent_intercepts_card(); ?>

		<section class="wk-card">
			<h2><?php esc_html_e( '事件流列表 / 导出', 'wink-gatekeeper' ); ?></h2>
			<p class="wk-muted"><?php esc_html_e( '时间 / 事件编号（等宽）/ 闸 / 阶段 / 驱动 / 判定 / 命中规则 / IP 哈希前 8 位；筛选器含「判定=故障」预设（verdict=error ∪ remote_*/contract_mismatch/driver_halted*——错误类深挖入口）；CSV（公式注入防护）与品牌拦截报告 HTML——统计面板 v2（M3）实装。', 'wink-gatekeeper' ); ?></p>
		</section>

		<section class="wk-card">
			<h2><?php esc_html_e( '重置统计', 'wink-gatekeeper' ); ?></h2>
			<p class="wk-muted"><?php esc_html_e( '同时清零计数缓存与事件表（04 §4 对账口径），不可恢复。', 'wink-gatekeeper' ); ?></p>
			<button type="button" class="button button-danger" id="wk-stats-reset"
				data-nonce="<?php echo esc_attr( wp_create_nonce( self::AJAX_RESET_STATS ) ); ?>"
				data-confirm="<?php echo esc_attr__( '确认重置统计？计数缓存与事件表将同时清零。', 'wink-gatekeeper' ); ?>">
				<?php esc_html_e( '重置统计', 'wink-gatekeeper' ); ?>
			</button>
			<span id="wk-stats-reset-result" aria-live="polite"></span>
		</section>
		<?php
	}

	/**
	 * 分区计数表（InterceptDigest::partitions 行 → 今日/累计两列判定计数）。
	 *
	 * @param array<int, array{label: string, today: array<string, int>, total: array<string, int>}> $rows 分区行。
	 * @param array<string, string> $verdict_labels 判定 → 标签。
	 * @return void
	 */
	private static function render_partition_table( array $rows, array $verdict_labels ): void {
		?>
		<table class="wk-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( '维度', 'wink-gatekeeper' ); ?></th>
					<th scope="col"><?php esc_html_e( '今日', 'wink-gatekeeper' ); ?></th>
					<th scope="col"><?php esc_html_e( '累计', 'wink-gatekeeper' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $row['label'] ); ?></td>
						<?php foreach ( array( 'today', 'total' ) as $scope ) : ?>
							<td>
								<?php
								$counts = is_array( $row[ $scope ] ?? null ) ? $row[ $scope ] : array();
								$parts  = array();
								foreach ( $verdict_labels as $verdict => $verdict_label ) {
									if ( ! empty( $counts[ $verdict ] ) ) {
										$parts[] = $verdict_label . ' ' . (int) $counts[ $verdict ];
									}
								}
								echo esc_html( array() !== $parts ? implode( ' · ', $parts ) : '—' );
								if ( 'today' === $scope && isset( $row['usage_note'] ) ) {
									echo '<br /><span class="wk-muted">' . esc_html( (string) $row['usage_note'] ) . '</span>'; // 【v1.4·R1 §6-1】调用 N · 全通过（usage 双口径）。
								}
								if ( 'total' === $scope && isset( $row['usage_note_total'] ) ) {
									echo '<br /><span class="wk-muted">' . esc_html( (string) $row['usage_note_total'] ) . '</span>'; // 【v1.5·F4-6】账户保护四维累计行（调用/通过/拦截/登录失败尝试——03 Tab⑥ v1.5）。
								}
								?>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * 「最近拦截评论」明细卡（03 Tab⑥ v1.3·用户建议4）：最近 10 条 reject 事件——
	 * 昵称/邮箱打码、正文摘要 60 字、comment_id 直达编辑页；数据 events 表 JOIN wp_comments
	 * （comment_id 直查——WP 边界函数只在此边界层，行组装在 InterceptDigest 纯逻辑层）。
	 *
	 * @return void
	 */
	private static function render_recent_intercepts_card(): void {
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

		$rows  = \WinkGatekeeper\Stats\InterceptDigest::rows( $events, $comments_by_id );

		/* 【v1.4·反馈1/R4 §D.2】「错误信息」列：review 阶段 verdict=error 事件并入此卡
		 * （数据源=reject 查询并行扩一条 EventsTable::recent_comment_faults；行形态=判定
		 * 「入待审（云端故障）」+ 错误信息 code/message 截断 + details 展开 meta.raw——
		 * HTTP 码/category/attempts/latency_ms/body，等宽小字 esc_html/esc_textarea）。 */
		$fault_events = \WinkGatekeeper\Stats\EventsTable::recent_comment_faults( \WinkGatekeeper\Stats\InterceptDigest::RECENT_LIMIT );
		$fault_rows   = array();
		foreach ( $fault_events as $fault_event ) {
			$fault_meta  = isset( $fault_event['meta'] ) && is_array( $fault_event['meta'] ) ? $fault_event['meta'] : array();
			$fault_raw   = isset( $fault_meta['raw'] ) && is_array( $fault_meta['raw'] ) ? $fault_meta['raw'] : array();
			$fault_brief = trim( ( isset( $fault_raw['code'] ) && is_string( $fault_raw['code'] ) ? $fault_raw['code'] : '' )
				. ' ' . ( isset( $fault_raw['message'] ) && is_string( $fault_raw['message'] ) ? $fault_raw['message'] : '' ) );
			$fault_rows[] = array(
				'created_at' => isset( $fault_event['created_at'] ) && is_string( $fault_event['created_at'] ) ? $fault_event['created_at'] : '',
				'event_no'   => isset( $fault_event['event_no'] ) && is_string( $fault_event['event_no'] ) ? $fault_event['event_no'] : '',
				'driver'     => isset( $fault_event['driver'] ) && is_string( $fault_event['driver'] ) ? $fault_event['driver'] : '',
				'rule_hit'   => isset( $fault_event['rule_hit'] ) && is_string( $fault_event['rule_hit'] ) ? $fault_event['rule_hit'] : '',
				'brief'      => '' !== $fault_brief ? $fault_brief : ( isset( $fault_event['rule_hit'] ) ? (string) $fault_event['rule_hit'] : '' ),
				'raw'        => $fault_raw,
			);
		}

		$stage_labels = array(
			'ip'      => __( 'IP 信誉', 'wink-gatekeeper' ),
			'captcha' => __( '验证码', 'wink-gatekeeper' ),
			'review'  => __( '内容审核', 'wink-gatekeeper' ),
		);
		?>
		<section class="wk-card">
			<h2><?php esc_html_e( '最近拦截评论', 'wink-gatekeeper' ); ?></h2>
			<p class="wk-muted"><?php esc_html_e( '最近 10 条拦截（昵称/邮箱打码展示，原文在留档链接或事件流查看）；stub 探测拦截无评论数据，不进此卡。', 'wink-gatekeeper' ); ?></p>
			<?php if ( array() === $rows ) : ?>
				<p class="wk-muted"><?php esc_html_e( '暂无拦截记录。', 'wink-gatekeeper' ); ?></p>
			<?php else : ?>
				<table class="wk-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( '时间', 'wink-gatekeeper' ); ?></th>
							<th scope="col"><?php esc_html_e( '事件编号', 'wink-gatekeeper' ); ?></th>
							<th scope="col"><?php esc_html_e( '阶段', 'wink-gatekeeper' ); ?></th>
							<th scope="col"><?php esc_html_e( '命中规则', 'wink-gatekeeper' ); ?></th>
							<th scope="col"><?php esc_html_e( '昵称', 'wink-gatekeeper' ); ?></th>
							<th scope="col"><?php esc_html_e( '邮箱', 'wink-gatekeeper' ); ?></th>
							<th scope="col"><?php esc_html_e( '正文摘要', 'wink-gatekeeper' ); ?></th>
							<th scope="col"><?php esc_html_e( '留档', 'wink-gatekeeper' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
								<td><code><?php echo esc_html( (string) $row['event_no'] ); ?></code></td>
								<td><?php echo esc_html( $stage_labels[ $row['stage'] ] ?? (string) $row['stage'] ); ?></td>
								<td><?php echo esc_html( (string) $row['rule_hit'] ); ?></td>
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
			<p style="margin-top:0.75rem;">
				<a class="button" href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_status=spam' ) ); ?>"><?php esc_html_e( '查看垃圾评论分类', 'wink-gatekeeper' ); ?></a>
			</p>

			<?php if ( array() !== $fault_rows ) : ?>
				<h3 style="margin-top:1rem;"><?php esc_html_e( '云端错误（判定=入待审）', 'wink-gatekeeper' ); ?></h3>
				<table class="wk-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( '时间', 'wink-gatekeeper' ); ?></th>
							<th scope="col"><?php esc_html_e( '事件编号', 'wink-gatekeeper' ); ?></th>
							<th scope="col"><?php esc_html_e( '判定', 'wink-gatekeeper' ); ?></th>
							<th scope="col"><?php esc_html_e( '错误信息', 'wink-gatekeeper' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $fault_rows as $fault_row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $fault_row['created_at'] ); ?></td>
								<td><code><?php echo esc_html( (string) $fault_row['event_no'] ); ?></code></td>
								<td><?php esc_html_e( '入待审（云端故障）', 'wink-gatekeeper' ); ?></td>
								<td>
									<?php echo esc_html( mb_substr( (string) $fault_row['brief'], 0, 120, 'UTF-8' ) ); ?>
									<?php if ( array() !== $fault_row['raw'] ) : ?>
										<details style="margin-top:4px;">
											<summary><?php esc_html_e( '原始返回（meta.raw，脱敏）', 'wink-gatekeeper' ); ?></summary>
											<pre style="white-space:pre-wrap;font-size:12px;"><?php echo esc_textarea( (string) wp_json_encode( $fault_row['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre>
										</details>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
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
	 * @return array<string, array{label: string, milestone: string, required: string[]}>
	 */
	private static function future_review_drivers(): array {
		return array(
			'deepseek'      => array( 'label' => 'DeepSeek', 'milestone' => 'M3', 'required' => array( 'api_key' ) ),
			'openai_custom' => array( 'label' => '自定义 OpenAI 兼容端点', 'milestone' => 'M3', 'required' => array( 'api_key', 'base_url', 'model' ) ),
			'moli'          => array( 'label' => '模力 System One', 'milestone' => 'M4', 'required' => array( 'api_key' ) ),
			'baidu'         => array( 'label' => '百度文本审核', 'milestone' => 'M4', 'required' => array( 'api_key', 'secret_key' ) ),
			// aliyun_green 已于 M3' 批次 3（B5）实装——登记入 registry，脱离 future 段（下拉已实装驱动）。
		);
	}

	private static function render_tab_tools(): void {
		?>
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
}
