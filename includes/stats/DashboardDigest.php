<?php
/**
 * 仪表盘卡片三视图摘要类（O3 §二/§七——视图①纯逻辑视图模型；wfActivityReport 三视图
 * 分级架构的 wink 落法，views/reports/activity-report.php 的数据面同源形态）。
 *
 * ┌─ 类级不变式（O3 §二「裁剪规则」原文钉死，防未来顺手加查询）──────────────
 * │ 视图①（本类 widget_overview → wp-admin 仪表盘卡片）与视图③（email_overview，
 * │ M4）**零 EventsTable 明细查询——只读 option 计数器**（wink_gatekeeper_stats /
 * │ wink_gatekeeper_usage / wink_gatekeeper_health 三个 option + 设置树 + 可注入布尔）。
 * │ 对应 Wordfence 公理「重查询永不进高频视图」（wfActivityReport.php:74-77——卡片视图
 * │ 砍掉 wfHits 扫描与全盘文件 mtime 扫描，仅 3 条聚合 SQL）；wink 计数器是请求内缓存
 * │ 的 option，比 Wordfence 的聚合 SQL 更轻（O3 §一「统计读取比 Wordfence 更便宜」）。
 * │ 只有视图②（Tab⑥ InterceptDigest/EventsTable 查询族）允许碰事件明细表。
 * └─────────────────────────────────────────────────────────────────────────
 *
 * 完全可注入形态（O3 §七 步骤 1）：输入 = 三个 option 树 + 设置树 + 验证码配置态/
 * cron 存活布尔（WP 边界用真实驱动 is_configured()/wp_next_scheduled 计算后传入），
 * 输出 = 三卡视图模型数组。缺键/异形输入安全回落空态（不抛异常、不虚构数字）。
 * echo 不在本层——渲染边界在 Bootstrap::render_dashboard_widget（wp_add_dashboard_widget
 * 静态回调形态对齐 wfActivityReport::outputDashboardWidget，wordfenceClass.php:7423-7428）。
 *
 * @package WinkGatekeeper\Stats
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Stats;

use WinkGatekeeper\Settings\SettingsSchema;

final class DashboardDigest {

	/**
	 * 四闸状态点渲染序（O3 §三卡 1：三闸启停态 + 健康卡置顶）。
	 *
	 * @var string[]
	 */
	private const GATE_ORDER = array( 'comment', 'login', 'register', 'lostpassword' );

	/**
	 * 仪表盘卡片视图模型（视图①；全部数据源见类头注不变式）。
	 *
	 * 输入键（全可注入；缺键安全回落）：
	 *   settings              merge_defaults 后的设置树。
	 *   stats                 StatsCache 树（wink_gatekeeper_stats）。
	 *   usage                 Usage 树（wink_gatekeeper_usage）。
	 *   health                DriverHealth 树（wink_gatekeeper_health）。
	 *   today_ymd             今日日期 YYYYMMDD（stats 树口径——边界层 current_time('Ymd')）。
	 *   today_dash            今日日期 YYYY-MM-DD（usage/health 树口径）。
	 *   captcha_configured    验证码驱动配置态（边界经驱动 is_configured() 判定后传入）。
	 *   captcha_driver        选中验证码驱动 id。
	 *   captcha_driver_label  驱动人话名（registry label；缺省回落驱动 id）。
	 *   driver_labels         usage 行 driver_id => 人话名（缺省回落 driver id）。
	 *   cron_alive            每日维护 cron 存活（边界 wp_next_scheduled 判定后传入）。
	 *   debug                 WP_DEBUG 态（true 时输出 schema 调试行——仿
	 *                         activity-report.php:209-210「Generated in」只在 debug 显示）。
	 *
	 * @param array<string, mixed> $args 输入（见概述）。
	 * @return array<string, mixed> {
	 *   health:  {gates: [{gate,label,enabled}], captcha: {driver_id,label,configured},
	 *            halts: [{driver_id,state,note,detail}], cron_alive: bool}
	 *   usage:   {rows: [{driver_id,label,used,quota,quota_reached}], site_total: int,
	 *            disaster_pass: int}
	 *   overview:{reject: int, review: int, error: int}
	 *   debug_note: string
	 * }
	 */
	public static function widget_overview( array $args ): array {
		$settings = isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : array();
		$stats    = isset( $args['stats'] ) && is_array( $args['stats'] ) ? $args['stats'] : array();
		$usage    = isset( $args['usage'] ) && is_array( $args['usage'] ) ? $args['usage'] : array();
		$health   = isset( $args['health'] ) && is_array( $args['health'] ) ? $args['health'] : array();
		$today_ymd  = isset( $args['today_ymd'] ) && is_string( $args['today_ymd'] ) ? $args['today_ymd'] : '';
		$today_dash = isset( $args['today_dash'] ) && is_string( $args['today_dash'] ) ? $args['today_dash'] : '';

		return array(
			'health'     => self::health_card( $settings, $health, $args, $today_dash ),
			'usage'      => self::usage_card( $settings, $usage, $stats, $args, $today_dash, $today_ymd ),
			'overview'   => self::overview_strip( $stats, $today_ymd ),
			'debug_note' => empty( $args['debug'] ) ? '' : 'schema v' . self::schema_version( $settings ),
		);
	}

	/**
	 * 卡 1 系统健康视图模型（「保安今天在不在岗、装备齐不齐」）。
	 *
	 * @param array<string, mixed> $settings   设置树。
	 * @param array<string, mixed> $health    DriverHealth 树。
	 * @param array<string, mixed> $args      全量输入（验证码配置态/驱动名/cron 态）。
	 * @param string               $today_dash 今日（usage/health 口径）。
	 * @return array<string, mixed>
	 */
	private static function health_card( array $settings, array $health, array $args, string $today_dash ): array {
		// 四闸状态点（绿=启用，灰=关闭）。
		$gates = array();
		foreach ( self::GATE_ORDER as $gate_id ) {
			$gates[] = array(
				'gate'    => $gate_id,
				'label'   => Labels::gate_word( $gate_id ),
				'enabled' => ! empty( $settings['gates'][ $gate_id ]['enabled'] ),
			);
		}

		// 驱动当日停转红警报（含一句话原因——健康卡核心警报位；跨日=自动恢复无警报）。
		$halts = array();
		$halted = isset( $health['date'] ) && $health['date'] === $today_dash
			&& isset( $health['drivers'] ) && is_array( $health['drivers'] );
		if ( $halted ) {
			foreach ( $health['drivers'] as $driver_id => $state ) {
				if ( ! is_string( $driver_id ) || ! is_string( $state ) || '' === $state ) {
					continue;
				}
				$halts[] = array(
					'driver_id' => $driver_id,
					'state'     => $state,
					'note'      => Labels::halt_word( $state ),
					'detail'    => Labels::halt_note( $state ),
				);
			}
		}

		$driver_id = isset( $args['captcha_driver'] ) && is_string( $args['captcha_driver'] ) && '' !== $args['captcha_driver']
			? $args['captcha_driver'] : 'tencent';
		$driver_label = isset( $args['captcha_driver_label'] ) && is_string( $args['captcha_driver_label'] ) && '' !== $args['captcha_driver_label']
			? $args['captcha_driver_label'] : $driver_id;

		return array(
			'gates'   => $gates,
			'captcha' => array(
				'driver_id'  => $driver_id,
				'label'      => $driver_label,
				'configured' => ! empty( $args['captcha_configured'] ),
			),
			'halts'     => $halts,
			'cron_alive' => ! empty( $args['cron_alive'] ),
		);
	}

	/**
	 * 卡 2 第三方调用量视图模型（「今天花了多少额度、哪些调用白花了」）。
	 *
	 * 配额映射：review.cloud.{id}.daily_quota（云审核驱动）；abuseipdb /
	 * abuseipdb_report 两键共用 iprep.abuseipdb.daily_quota（同一账户配额——上报与
	 * 查询共用 04 §6 口径）；其余键（验证码驱动）无日配额=0（不渲染 N / M 行）。
	 *
	 * @param array<string, mixed> $settings   设置树。
	 * @param array<string, mixed> $usage     Usage 树。
	 * @param array<string, mixed> $stats     StatsCache 树（容灾放行键）。
	 * @param array<string, mixed> $args      全量输入（驱动人话名）。
	 * @param string               $today_dash  今日（usage 口径）。
	 * @param string               $today_ymd   今日（stats 口径）。
	 * @return array<string, mixed>
	 */
	private static function usage_card( array $settings, array $usage, array $stats, array $args, string $today_dash, string $today_ymd ): array {
		$rows        = array();
		$site_total  = 0;
		$counters    = isset( $usage['date'], $usage['counters'] ) && $usage['date'] === $today_dash && is_array( $usage['counters'] )
			? $usage['counters'] : array();

		if ( array() !== $counters ) {
			$keys = array_keys( $counters );
			sort( $keys ); // 驱动行字典序（确定性渲染——DOM 断言稳定）。
			foreach ( $keys as $key ) {
				if ( ! is_string( $key ) || '' === $key ) {
					continue;
				}
				$used  = Usage::daily_total_for( $usage, $key );
				$quota = self::quota_for( $settings, $key );
				$rows[] = array(
					'driver_id'     => $key,
					'label'         => self::driver_label( $args, $key ),
					'used'          => $used,
					'quota'         => $quota,
					'quota_reached' => Usage::quota_reached( $usage, $key, $quota ),
				);
			}
			$site_total = Usage::daily_total( $usage );
		}

		return array(
			'rows'          => $rows,
			'site_total'    => $site_total,
			'disaster_pass' => self::today_disaster_pass( $stats, $today_ymd ),
		);
	}

	/**
	 * 容灾放行今日计数（StatsCache 特例键 disaster_pass——O3 §三卡 2：卡片读 option
	 * 而非 COUNT 事件明细；键由 CommentGate 默认 recorder 在 meta.disaster_pass 语境
	 * bump，今日树日期 != 今日时=0）。
	 *
	 * @param array<string, mixed> $stats     StatsCache 树。
	 * @param string               $today_ymd 今日（Ymd）。
	 * @return int
	 */
	private static function today_disaster_pass( array $stats, string $today_ymd ): int {
		if ( ! isset( $stats['today'] ) || $stats['today'] !== $today_ymd ) {
			return 0;
		}
		$counts = isset( $stats['by_gate']['comment'] ) && is_array( $stats['by_gate']['comment'] )
			? $stats['by_gate']['comment'] : array();
		return isset( $counts['disaster_pass'] ) && is_numeric( $counts['disaster_pass'] )
			? (int) $counts['disaster_pass'] : 0;
	}

	/**
	 * 卡 3 今日拦截概览迷你条视图模型（拦截/待审/驱动错误三格——by_gate 今日树
	 * 全闸遍历求和；日期 != 今日时=0——跨日翻转由写入侧 incr 完成，读取侧不搬运）。
	 *
	 * @param array<string, mixed> $stats     StatsCache 树。
	 * @param string               $today_ymd 今日（Ymd）。
	 * @return array{reject: int, review: int, error: int}
	 */
	private static function overview_strip( array $stats, string $today_ymd ): array {
		$sums = array( 'reject' => 0, 'review' => 0, 'error' => 0 );
		if ( ! isset( $stats['today'] ) || $stats['today'] !== $today_ymd ) {
			return $sums;
		}
		$by_gate = isset( $stats['by_gate'] ) && is_array( $stats['by_gate'] ) ? $stats['by_gate'] : array();
		foreach ( $by_gate as $counts ) {
			if ( ! is_array( $counts ) ) {
				continue;
			}
			foreach ( array_keys( $sums ) as $verdict ) {
				if ( isset( $counts[ $verdict ] ) && is_numeric( $counts[ $verdict ] ) ) {
					$sums[ $verdict ] += (int) $counts[ $verdict ];
				}
			}
		}
		return $sums;
	}

	/**
	 * 计量键 → 日配额（0=无配额/不限量——Usage::quota_reached 同口径）。
	 *
	 * @param array<string, mixed> $settings 设置树。
	 * @param string               $key      计量键（驱动 id / abuseipdb_report）。
	 * @return int
	 */
	private static function quota_for( array $settings, string $key ): int {
		// AbuseIPDB 两键（查询/上报）共用同一账户配额。
		if ( 'abuseipdb' === $key || 'abuseipdb_report' === $key ) {
			return isset( $settings['iprep']['abuseipdb']['daily_quota'] ) && is_numeric( $settings['iprep']['abuseipdb']['daily_quota'] )
				? (int) $settings['iprep']['abuseipdb']['daily_quota'] : 0;
		}
		return isset( $settings['review']['cloud'][ $key ]['daily_quota'] ) && is_numeric( $settings['review']['cloud'][ $key ]['daily_quota'] )
			? (int) $settings['review']['cloud'][ $key ]['daily_quota'] : 0;
	}

	/**
	 * 计量键 → 人话名（注入映射优先，回落驱动 id——不虚构人话名）。
	 *
	 * @param array<string, mixed> $args 全量输入。
	 * @param string               $key  计量键。
	 * @return string
	 */
	private static function driver_label( array $args, string $key ): string {
		$labels = isset( $args['driver_labels'] ) && is_array( $args['driver_labels'] ) ? $args['driver_labels'] : array();
		return isset( $labels[ $key ] ) && is_string( $labels[ $key ] ) && '' !== $labels[ $key ] ? $labels[ $key ] : $key;
	}

	/**
	 * schema 版本读数（调试行；缺键回落当前常量）。
	 *
	 * @param array<string, mixed> $settings 设置树。
	 * @return string
	 */
	private static function schema_version( array $settings ): string {
		return isset( $settings['schema_version'] ) && is_numeric( $settings['schema_version'] )
			? (string) (int) $settings['schema_version'] : (string) SettingsSchema::SCHEMA_VERSION;
	}
}
