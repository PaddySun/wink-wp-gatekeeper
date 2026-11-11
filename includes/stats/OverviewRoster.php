<?php
/**
 * Tab① 总览点名册推导纯逻辑层（UI 批 3——DESIGN-UI-tab1 B 提案块 B/C/D）。
 *
 * 定位（agent C §E-b 方案甲）：点名册=渲染侧合并推导——registry 全集骨架×配置树
 * （排班态：DriverConfigSpec 必填判定）×DriverHealth（健康态：state map 只读消费）
 * ×Usage/StatsCache（今日摘要）——**不改 DriverHealth 存储**（方案乙 N9 不采纳）。
 * 战报小票/副行与岔子紧凑行同入本层（全部零 WP 依赖，WP 边界在 SettingsPage）。
 *
 * 分层纪律（B 提案 §6）：Tab① 只看今天、每数字一句人话、无表格化明细（明细归
 * Tab⑥）；术语一律走 Labels 映射（对照表资产）——「驱动/调用/待审/停转」等黑话
 * 不出现在站长文案。
 *
 * @package WinkGatekeeper\Stats
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Stats;

use WinkGatekeeper\Settings\DriverConfigSpec;

final class OverviewRoster {

	/** 排班态：当班（正在使用）。 */
	public const DUTY_ACTIVE = 'active';

	/** 排班态：待命（已配好但没排它上岗）。 */
	public const DUTY_STANDBY = 'standby';

	/** 排班态：未配置（必填没配齐）。 */
	public const DUTY_UNCONFIGURED = 'unconfigured';

	/**
	 * 岔子行数（入口性质——明细归 Tab⑥）。
	 */
	public const INCIDENT_LIMIT = 5;

	/**
	 * 点名册卡组装（渲染侧推导矩阵：排班态×健康态全组合；异常置顶微排序）。
	 *
	 * 排班态推导：当班=验证码选中该 id ∨（云审启用 ∧ 在链）；待命=必填齐 ∧ 非当班；
	 * 未配置=必填缺（DriverConfigSpec::missing_fields 非空——F11 单一来源）。
	 * 健康态：halted map 消费（null=正常 F8）——残留停转态不盖排班态（当班为准，
	 * 批 1 driver_card 同款纪律）。排序：异常卡浮最左 → 当班 → 待命 → 未配置；
	 * 组内保持 registry 序（PHP 8 排序稳定——位置稳定便于肌肉记忆，B 提案块 C）。
	 *
	 * @param array<int, array<string, mixed>> $drivers registry 全集行（id/label/type/
	 *                                                   settings 声明齐备；local_rules
	 *                                                   由调用方剔除不入册）。
	 * @param array<string, mixed> $ctx{
	 *       captcha_driver string   settings captcha.driver（''=未配置）。
	 *       cloud_enabled  bool     review.cloud.enabled。
	 *       chain          string[] review.cloud.chain。
	 *       configs        array<string, array<string, mixed>>  driver_id => 设置子树
	 *                                （验证码=captcha.<id>；云审=review.cloud.<id>）。
	 *       halted         array<string, string|null>           driver_id => DriverHealth
	 *                                当日停转态（null=正常）。
	 *       usage          array<string, int>                   driver_id => 今日调用数。
	 *       counts         array<string, array<string, int>>    driver_id => by_driver
	 *                                今日计数（InterceptDigest::driver_counts 形态）。
	 * }
	 * @return array<int, array<string, mixed>> 卡组（driver_id/label/type/role/duty/
	 *                duty_word/halt/healthy/health_word/halt_note/summary/config_tab）。
	 */
	public static function cards( array $drivers, array $ctx ): array {
		$captcha_driver = isset( $ctx['captcha_driver'] ) && is_string( $ctx['captcha_driver'] ) ? $ctx['captcha_driver'] : '';
		$cloud_enabled  = ! empty( $ctx['cloud_enabled'] );
		$chain          = isset( $ctx['chain'] ) && is_array( $ctx['chain'] ) ? $ctx['chain'] : array();
		$configs        = isset( $ctx['configs'] ) && is_array( $ctx['configs'] ) ? $ctx['configs'] : array();
		$halted         = isset( $ctx['halted'] ) && is_array( $ctx['halted'] ) ? $ctx['halted'] : array();
		$usage          = isset( $ctx['usage'] ) && is_array( $ctx['usage'] ) ? $ctx['usage'] : array();
		$counts         = isset( $ctx['counts'] ) && is_array( $ctx['counts'] ) ? $ctx['counts'] : array();

		$cards = array();
		foreach ( $drivers as $meta ) {
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$id = isset( $meta['id'] ) && is_string( $meta['id'] ) ? $meta['id'] : '';
			if ( '' === $id ) {
				continue;
			}
			$type  = isset( $meta['type'] ) && is_string( $meta['type'] ) ? $meta['type'] : '';
			$label = isset( $meta['label'] ) && is_string( $meta['label'] ) ? $meta['label'] : $id;
			$config = isset( $configs[ $id ] ) && is_array( $configs[ $id ] ) ? $configs[ $id ] : array();

			// 排班态（必填缺失判定走 DriverConfigSpec——Tab②③ 同源，F11）。
			$missing = DriverConfigSpec::missing_fields( $meta, $config );
			if ( array() !== $missing ) {
				$duty = self::DUTY_UNCONFIGURED;
			} elseif ( self::is_on_duty( $id, $type, $captcha_driver, $cloud_enabled, $chain ) ) {
				$duty = self::DUTY_ACTIVE;
			} else {
				$duty = self::DUTY_STANDBY;
			}

			// 健康态（只读消费 halted map——状态码不出站长文案）。
			$halt          = isset( $halted[ $id ] ) && is_string( $halted[ $id ] ) && '' !== $halted[ $id ] ? $halted[ $id ] : null;
			$driver_counts = isset( $counts[ $id ] ) && is_array( $counts[ $id ] ) ? $counts[ $id ] : array();
			$used          = isset( $usage[ $id ] ) && is_numeric( $usage[ $id ] ) ? (int) $usage[ $id ] : 0;

			$cards[] = array(
				'driver_id'   => $id,
				'label'       => $label,
				'type'        => $type,
				'role'        => Labels::stage_word( $type ),
				'duty'        => $duty,
				'duty_word'   => Labels::duty_word( $duty ),
				'halt'        => $halt,
				'healthy'     => null === $halt,
				'health_word' => self::DUTY_UNCONFIGURED === $duty ? '' : Labels::health_word( $halt ),
				'halt_note'   => self::DUTY_UNCONFIGURED === $duty ? '' : Labels::halt_note( $halt ),
				'summary'     => self::DUTY_UNCONFIGURED === $duty
					? ''
					: self::card_summary( $duty, $type, $used, $driver_counts ),
				'config_tab'  => 'captcha' === $type ? 'captcha' : 'review',
			);
		}

		// 微排序：异常(0) → 当班(1) → 待命(2) → 未配置(3)；组内稳定（registry 序）。
		usort( $cards, static function ( array $a, array $b ): int {
			return self::sort_rank( $a ) <=> self::sort_rank( $b );
		} );

		return $cards;
	}

	/**
	 * 当班判定（验证码=driver 选中；云审=启用 ∧ 在链）。
	 *
	 * @param string   $id             驱动 id。
	 * @param string   $type           驱动类型。
	 * @param string   $captcha_driver settings captcha.driver。
	 * @param bool     $cloud_enabled  review.cloud.enabled。
	 * @param string[] $chain          review.cloud.chain。
	 * @return bool
	 */
	private static function is_on_duty( string $id, string $type, string $captcha_driver, bool $cloud_enabled, array $chain ): bool {
		if ( 'captcha' === $type ) {
			return '' !== $captcha_driver && $captcha_driver === $id;
		}
		return $cloud_enabled && in_array( $id, $chain, true );
	}

	/**
	 * 排序权重（异常置顶——故障/额度用完卡自动浮到点名册最左）。
	 *
	 * @param array<string, mixed> $card 卡。
	 * @return int
	 */
	private static function sort_rank( array $card ): int {
		if ( null !== ( $card['halt'] ?? null ) ) {
			return 0;
		}
		return match ( $card['duty'] ?? '' ) {
			self::DUTY_ACTIVE       => 1,
			self::DUTY_STANDBY      => 2,
			self::DUTY_UNCONFIGURED => 3,
			default                 => 4,
		};
	}

	/**
	 * 今日一句摘要（每数字一句人话；零值显式空态——「恒 0 不可区分」教训延续）。
	 *
	 * @param string              $duty   排班态。
	 * @param string              $type   驱动类型（动词依据 Labels::action_verb）。
	 * @param int                 $used   今日调用数（Usage）。
	 * @param array<string, int>  $counts by_driver 今日计数。
	 * @return string ''=未配置（无摘要，给去处不给数字）。
	 */
	private static function card_summary( string $duty, string $type, int $used, array $counts ): string {
		if ( self::DUTY_UNCONFIGURED === $duty ) {
			return '';
		}
		if ( self::DUTY_STANDBY === $duty ) {
			return sprintf( '今日 %d 次（未排班）', $used );
		}

		// 当班：主行=今日{验/审} N 次；副段=结果人话（拦下/转人工/出错/全部通过）。
		$reject = (int) ( $counts['reject'] ?? 0 );
		if ( 'captcha' === $type ) {
			// captcha_reject=账户域净口径（F3）；reject=评论域 captcha 阶段（同键混合树按驱动读）。
			$reject += (int) ( $counts['captcha_reject'] ?? 0 );
		}
		$review = (int) ( $counts['review'] ?? 0 );
		$error  = (int) ( $counts['error'] ?? 0 );

		if ( 0 === $used && 0 === $reject && 0 === $review && 0 === $error ) {
			return Labels::zero_call_word() . '（0 次）';
		}

		$parts = array( '今日' . Labels::action_verb( $type ) . ' ' . $used . ' 次' );
		if ( $reject > 0 ) {
			$parts[] = '拦下 ' . $reject . ' 次';
		}
		if ( $review > 0 ) {
			$parts[] = '转人工 ' . $review . ' 次';
		}
		if ( $error > 0 ) {
			$parts[] = '出错 ' . $error . ' 次';
		}
		if ( 0 === $reject && 0 === $review && 0 === $error ) {
			// 有调用零拦下：验证码=全部通过（推算口径，脚注另交代）；审核=没有可疑内容。
			$parts[] = 'captcha' === $type ? '全部通过' : '没有可疑内容';
		}
		return implode( ' · ', $parts );
	}

	/* -------------------------------------------------------------------
	 * 战报小票（块 B——三闸速览）
	 * ------------------------------------------------------------------- */

	/**
	 * 小票主行（0 显式空态「平静的一天」；非零=拦下 N + 量词——对照表 3/B-3）。
	 *
	 * @param int    $reject 今日拦下数。
	 * @param string $gate   闸 id（量词：评论=条、其余=次）。
	 * @return string
	 */
	public static function ticket_main( int $reject, string $gate ): string {
		if ( $reject <= 0 ) {
			return '平静的一天';
		}
		return Labels::reject_word() . ' ' . $reject . ' ' . Labels::count_unit( $gate );
	}

	/**
	 * 小票副行（N4 今日分布 map → 人话族前 3 段「关键词黑名单 2 · 纯外文 1」形态；
	 * 计数倒序取前 3——总览不铺全表，明细归 Tab⑥；空分布=空副行）。
	 *
	 * @param array<string, int> $dist rule_hit => 计数（N4/distribution_from_rows 形态）。
	 * @return string
	 */
	public static function ticket_subline( array $dist ): string {
		arsort( $dist );
		$parts = array();
		foreach ( $dist as $rule_hit => $n ) {
			if ( count( $parts ) >= 3 ) {
				break;
			}
			$word    = Labels::rule_family_word( is_string( $rule_hit ) ? $rule_hit : '' );
			$parts[] = $word . ' ' . (int) $n;
		}
		return implode( ' · ', $parts );
	}

	/* -------------------------------------------------------------------
	 * 岔子行（块 D——批 2 fault_timeline_rows 输出的 Tab① 紧凑形态）
	 * ------------------------------------------------------------------- */

	/**
	 * 友好时间（今天/昨天/日期三档——纯函数；今日昨日由 WP 边界算好传入）。
	 *
	 * @param string $mysql_time created_at（Y-m-d H:i:s）。
	 * @param string $today     今日 Y-m-d（站点时区）。
	 * @param string $yesterday 昨日 Y-m-d。
	 * @return string 「今天 09:12」/「昨天 21:04」/「11月8日 03:00」（月日去前导零）；
	 *                空值回落「—」。
	 */
	public static function friendly_time( string $mysql_time, string $today, string $yesterday ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2})/', $mysql_time, $m ) ) {
			return '—';
		}
		$date = $m[1] . '-' . $m[2] . '-' . $m[3];
		$hm   = $m[4];
		if ( $date === $today ) {
			return '今天 ' . $hm;
		}
		if ( $date === $yesterday ) {
			return '昨天 ' . $hm;
		}
		return (int) $m[2] . '月' . (int) $m[3] . '日 ' . $hm;
	}

	/**
	 * 岔子紧凑行组装（批 2 fault_timeline_rows 输出 → 时间/服务商/一句话；
	 * LIMIT 钳 1–10——入口性质只留 5 条，明细归 Tab⑥）。
	 *
	 * 文案形态：岔子人话（rule_word）+「然后呢」处理话（handling）——同语不重复拼接
	 * （driver_resumed 两字段同人话）；空行/缺键安全回落。
	 *
	 * @param array<int, array<string, mixed>> $tl_rows   fault_timeline_rows 输出。
	 * @param string                           $today     今日 Y-m-d。
	 * @param string                           $yesterday 昨日 Y-m-d。
	 * @param int                              $limit     行数（钳 1–10）。
	 * @return array<int, array{time_word: string, driver: string, text: string}>
	 */
	public static function incident_lines( array $tl_rows, string $today, string $yesterday, int $limit = self::INCIDENT_LIMIT ): array {
		$max   = max( 1, min( 10, $limit ) );
		$lines = array();
		foreach ( $tl_rows as $row ) {
			if ( count( $lines ) >= $max ) {
				break;
			}
			if ( ! is_array( $row ) ) {
				continue;
			}

			$created_at = isset( $row['created_at'] ) && is_string( $row['created_at'] ) ? $row['created_at'] : '';
			$driver     = isset( $row['driver_label'] ) && is_string( $row['driver_label'] ) ? $row['driver_label'] : '';
			$rule_word  = isset( $row['rule_word'] ) && is_string( $row['rule_word'] ) ? $row['rule_word'] : '';
			$handling   = isset( $row['handling'] ) && is_string( $row['handling'] ) ? $row['handling'] : '';

			// 岔子一句 + 「然后呢」处理一句（同语不重复——driver_resumed 两字段同人话）。
			$text = $rule_word;
			if ( '' !== $handling && $handling !== $rule_word ) {
				$text = '' === $text ? $handling : $text . '；' . $handling;
			}

			$lines[] = array(
				'time_word' => self::friendly_time( $created_at, $today, $yesterday ),
				'driver'    => $driver,
				'text'      => $text,
			);
		}
		return $lines;
	}
}