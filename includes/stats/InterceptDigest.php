<?php
/**
 * Tab⑥ 统计分区纯逻辑层（03 Tab⑥ v1.3·用户建议4；C2）。
 *
 * 三块职责（全部零 WP 依赖，WP 边界函数只在 SettingsPage/StatsCache 边界层）：
 *   1. 分区渲染数据结构：计数卡拆「评论拦截 / 登录拦截」两独立分区——两闸运营节奏不同
 *      （评论看日均趋势、登录看偶发攻击）；登录分区数据 C3（LoginGate）填，本层立结构+空态；
 *   2. 「最近拦截评论」明细卡行组装：events 表 reject 事件 + comment_id 直查 wp_comments
 *      的昵称/邮箱/正文——昵称/邮箱打码展示、正文摘要 60 字、comment_id 编辑链接直达；
 *      stub 事件不进此卡（无评论数据，02 §3.1 ②）；
 *   3. spam 留档量黄条阈值决策（阈值硬编码 500，任务口径 C2）。
 *
 * @package WinkGatekeeper\Stats
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Stats;

final class InterceptDigest {

	/**
	 * 明细卡行数（最近 N 条 reject 事件）。
	 */
	public const RECENT_LIMIT = 10;

	/**
	 * 正文摘要长度（字符）。
	 */
	public const EXCERPT_MAX = 60;

	/**
	 * spam 留档量黄条阈值（超过才提示；任务口径硬编码——docs 03 v1.3 原文为 200，
	 * C2 施工任务定为 500，偏差已在 C2 报告记录）。
	 */
	public const SPAM_WARN_THRESHOLD = 500;

	/**
	 * 昵称打码：保留首字符 + ***（明细卡为管理端摘要视图，原文在 spam 队列/事件流可查）。
	 *
	 * @param string $author 昵称原值。
	 * @return string
	 */
	public static function mask_author( string $author ): string {
		if ( '' === $author ) {
			return '—';
		}
		return mb_substr( $author, 0, 1, 'UTF-8' ) . '***';
	}

	/**
	 * 邮箱打码：本地部分首字符 + *** + @域名（域名保留便于人工辨识来源域）。
	 *
	 * @param string $email 邮箱原值。
	 * @return string
	 */
	public static function mask_email( string $email ): string {
		if ( '' === $email ) {
			return '—';
		}
		$at = strpos( $email, '@' );
		if ( false === $at || 0 === $at ) {
			return mb_substr( $email, 0, 1, 'UTF-8' ) . '***';
		}
		return mb_substr( $email, 0, 1, 'UTF-8' ) . '***' . substr( $email, $at );
	}

	/**
	 * 正文摘要：去标签、换行折叠（CJK 邻接不引入空格——中文摘要不出现「换 行 折叠」形态）、
	 * 60 字截断加省略号。
	 *
	 * @param string $content 正文原值。
	 * @param int    $max     截断长度（默认 60）。
	 * @return string
	 */
	public static function excerpt( string $content, int $max = self::EXCERPT_MAX ): string {
		if ( '' === $content ) {
			return '';
		}
		// 1) 去标签 + 全部空白折叠为单空格；2) 两侧均为 CJK 的空格移除（中文摘要不出现「换 行」形态；
		// CJK-拉丁边界保留单空格）。
		$flat = trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $content ) ) );
		$cjk  = '\x{4e00}-\x{9fa5}\x{3000}-\x{303f}\x{ff00}-\x{ffef}';
		$flat = (string) preg_replace( '/(?<=[' . $cjk . ']) (?=[' . $cjk . '])/u', '', $flat );
		if ( mb_strlen( $flat, 'UTF-8' ) <= $max ) {
			return $flat;
		}
		return mb_substr( $flat, 0, $max, 'UTF-8' ) . '…';
	}

	/**
	 * 明细卡行组装（纯逻辑：events 行 + comments 映射 → 展示行）。
	 *
	 * @param array<int, array<string, mixed>> $events 事件行（event_no/created_at/stage/
	 *                                                   rule_hit/meta[decoded]；EventsTable 边界查回）。
	 * @param array<int, array<string, mixed>|null> $comments_by_id comment_id => 评论字段映射
	 *                                                   （comment_author/comment_author_email/comment_content；
	 *                                                   缺失 = 已删/未留档）。
	 * @return array<int, array<string, mixed>> 展示行（event_no/created_at/stage/rule_hit/
	 *                                                   author/email/excerpt/comment_id/edit_url）。
	 */
	public static function rows( array $events, array $comments_by_id ): array {
		$rows = array();
		foreach ( $events as $event ) {
			$meta       = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
			$comment_id = isset( $meta['comment_id'] ) && is_int( $meta['comment_id'] ) ? $meta['comment_id'] : null;

			$author  = '';
			$email   = '';
			$content = '';
			$comment = ( null !== $comment_id && isset( $comments_by_id[ $comment_id ] ) && is_array( $comments_by_id[ $comment_id ] ) )
				? $comments_by_id[ $comment_id ]
				: null;
			if ( null !== $comment ) {
				$author   = isset( $comment['comment_author'] ) && is_string( $comment['comment_author'] ) ? $comment['comment_author'] : '';
				$email    = isset( $comment['comment_author_email'] ) && is_string( $comment['comment_author_email'] ) ? $comment['comment_author_email'] : '';
				$content  = isset( $comment['comment_content'] ) && is_string( $comment['comment_content'] ) ? $comment['comment_content'] : '';
			}

			$rows[] = array(
				'event_no'   => isset( $event['event_no'] ) && is_string( $event['event_no'] ) ? $event['event_no'] : '',
				'created_at' => isset( $event['created_at'] ) && is_string( $event['created_at'] ) ? $event['created_at'] : '',
				'stage'      => isset( $event['stage'] ) && is_string( $event['stage'] ) ? $event['stage'] : '',
				'rule_hit'   => isset( $event['rule_hit'] ) && is_string( $event['rule_hit'] ) ? $event['rule_hit'] : '',
				'author'     => '' !== $author ? self::mask_author( $author ) : '—',
				'email'      => '' !== $email ? self::mask_email( $email ) : '—',
				'excerpt'    => '' !== $content ? self::excerpt( $content ) : '—',
				'comment_id' => $comment_id,
				// 编辑链接仅当评论仍存在（站长删除后不留死链；渲染层 esc_url）。
				'edit_url'   => ( null !== $comment ) && null !== $comment_id
					? 'comment.php?action=editcomment&c=' . $comment_id
					: null,
			);
		}
		return $rows;
	}

	/**
	 * spam 留档量黄条决策（> 阈值才提示）。
	 *
	 * @param int      $spam_count 本插件拦截留档且仍在垃圾分队的评论数（StatsCache::archived_spam_count）。
	 * @param int|null $threshold  阈值（null = 常量默认 500）。
	 * @return bool
	 */
	public static function spam_warn( int $spam_count, ?int $threshold = null ): bool {
		return $spam_count > ( null !== $threshold ? $threshold : self::SPAM_WARN_THRESHOLD );
	}

	/**
	 * 驱动行「调用」双口径摘要（03 Tab⑥ v1.4·R1 §6-1：「全 pass 正常」与「从未调用」
	 * 在 Tab⑥ 不可区分的可观测性缺口修复）。
	 *
	 * 形态（03 Tab⑥ v1.4 行）：计数空 + N>0 =「调用 N · 全通过」；计数非空 = 计数 + 调用并存；
	 * N=0 =「0 调用」（停转/配额尽时「0 调用 · 当日停转（<state>）」——与 Tab① 一致）。
	 *
	 * @param array<string, int> $today_counts 今日判定计数（by_driver 行；pass 键不进摘要）。
	 * @param int                $used         今日调用数（Usage counters，含失败调用按请求计）。
	 * @param string|null        $halt_state   当日停转状态（DriverHealth；null=正常）。
	 * @return string
	 */
	public static function driver_usage_note( array $today_counts, int $used, ?string $halt_state ): string {
		unset( $today_counts['pass'] ); // pass 计数（verify_cache_hit 特例）不进摘要——调用数已表达。

		$parts  = array();
		$labels = array( 'reject' => '拦截', 'review' => '待审', 'error' => '故障' );
		foreach ( $labels as $verdict => $label ) {
			if ( ! empty( $today_counts[ $verdict ] ) ) {
				$parts[] = $label . ' ' . (int) $today_counts[ $verdict ];
			}
		}
		if ( $used > 0 ) {
			$parts[] = ( array() === $parts ) ? "调用 {$used} · 全通过" : "调用 {$used}";
		} else {
			$parts[] = '0 调用'; // 停转/配额尽（03 Tab⑥ v1.4：「0 调用 + 状态」，与 Tab① 一致）。
		}
		if ( null !== $halt_state && '' !== $halt_state ) {
			$parts[] = '当日停转（' . $halt_state . '）';
		}
		return implode( ' · ', $parts );
	}

	/**
	 * 计数卡分区数据结构（评论拦截 / 账户保护两独立分区，03 Tab⑥ v1.3+ v1.4）。
	 *
	 * 【v1.4·R3】原「登录拦截」分区更名「账户保护」——login/register/lostpassword 三分闸
	 * 小字标注（02 §2.7 两闸注册后 by_gate 键自动扩展）。【v1.4·R1 §6-1】驱动行并入 usage
	 * 口径：$usage_context 携今日调用数与停转态（读 Usage/DriverHealth option 的 WP 边界
	 * 由 SettingsPage 传入；缺省无 usage 列——行为兼容）。
	 *
	 * @param array<string, mixed> $cache StatsCache 树。
	 * @param array<string, string> $comment_driver_labels 评论域驱动 id => 标签（DriverRegistry）。
	 * @param array{usage_today?: array<string, int>, halted?: array<string, string>} $usage_context
	 *        驱动行 usage 上下文（usage_today=Usage counters 子集；halted=DriverHealth 停转映射）。
	 * @return array{comment: array{label: string, today_reject: int, rows: array<int, array{label: string, today: array<string, int>, total: array<string, int>, usage_today?: int, halt_state?: string|null, usage_note?: string}>},
	 *               login: array{label: string, today_reject: int, rows: array<int, array{label: string, today: array<string, int>, total: array<string, int>, usage_note: string, usage_note_total: string}>, empty: bool, empty_note: string}}
	 *               【v1.5·F4-6】账户行带 usage_note/usage_note_total 四维小字（调用/通过/拦截/登录失败尝试）。
	 */
	public static function partitions( array $cache, array $comment_driver_labels, array $usage_context = array() ) {
		$comment_rows = array_merge(
			array( self::gate_dimension_row( $cache, 'comment', '评论闸' ) ),
			self::driver_dimension_rows( $cache, $comment_driver_labels, $usage_context )
		);

		/* 【v1.4·R3】「账户保护」三分闸行（login/register/lostpassword——by_gate 键随
		 * GateRegistry 自动扩展，04 §4 示例；Tab⑥ 可回答「验证码被谁触发」按闸归因）。 */
		$account_gates = array(
			'login'        => '登录闸',
			'register'     => '注册闸',
			'lostpassword' => '找回密码闸',
		);
		$account_rows  = array();
		$account_today = 0;
		$account_empty = true;
		foreach ( $account_gates as $gate_id => $label ) {
			$today_counts = self::gate_counts( $cache, $gate_id, false );
			$total_counts = self::gate_counts( $cache, $gate_id, true );
			$row          = self::gate_dimension_row( $cache, $gate_id, $label );
			// 【v1.5·F4-4/F4-6】账户保护四维小字（今日/累计两行——03 Tab⑥ v1.5）。
			$row['usage_note']       = self::account_usage_note( $today_counts );
			$row['usage_note_total'] = self::account_usage_note( $total_counts );
			$account_rows[] = $row;
			$account_today += (int) ( $today_counts['reject'] ?? 0 );
			if ( array() !== array_filter( $today_counts ) || array() !== array_filter( $total_counts ) ) {
				$account_empty = false;
			}
		}

		return array(
			'comment' => array(
				'label'        => '评论拦截',
				'today_reject' => (int) ( self::gate_counts( $cache, 'comment', false )['reject'] ?? 0 ),
				'rows'         => $comment_rows,
			),
				'login'   => array(
					// 【v1.4·R3】「账户保护」更名（login/register/lostpassword 三分闸小字标注）。
					'label'        => '账户保护',
					'today_reject' => $account_today,
					'rows'         => $account_rows,
					'empty'        => $account_empty,
					// 空态文案：三分闸已上线——空=今日无触发（零打扰正常态），非未实装。
					'empty_note'   => '今日无账户保护触发（登录/注册/找回验证码均未拦截——自适应模式零打扰的正常形态）。',
				),
		);
	}

	/**
	 * 账户保护四维小字（【v1.5·F4-4/F4-6】03 Tab⑥——今日/累计 × 调用/通过/拦截 +
	 * 登录失败尝试数；零值显式展示，「恒 0 不可区分」教训延续）。
	 *
	 * 口径（04 §4 v1.5）：调用 N = captcha_pass + captcha_reject + captcha 阶段 error
	 * 之和（不新增第四存储维）；通过 M = captcha_pass；拦截 K = 既有 reject 维（全阶段）；
	 * 登录失败尝试 T = attempts 维（recorder 直记——register/lostpassword 无此维显示 0）。
	 *
	 * @param array<string, int> $counts 计数 map（StatsCache by_gate 行）。
	 * @return string 形态「调用 N · 通过 M · 拦截 K · 登录失败尝试 T」。
	 */
	public static function account_usage_note( array $counts ): string {
		$pass      = isset( $counts['captcha_pass'] ) && is_numeric( $counts['captcha_pass'] ) ? (int) $counts['captcha_pass'] : 0;
		$reject_c  = isset( $counts['captcha_reject'] ) && is_numeric( $counts['captcha_reject'] ) ? (int) $counts['captcha_reject'] : 0;
		$error     = isset( $counts['error'] ) && is_numeric( $counts['error'] ) ? (int) $counts['error'] : 0;
		$intercept = isset( $counts['reject'] ) && is_numeric( $counts['reject'] ) ? (int) $counts['reject'] : 0;
		$attempts  = isset( $counts['attempts'] ) && is_numeric( $counts['attempts'] ) ? (int) $counts['attempts'] : 0;

		return sprintf(
			'调用 %d · 通过 %d · 拦截 %d · 登录失败尝试 %d',
			$pass + $reject_c + $error,
			$pass,
			$intercept,
			$attempts
		);
	}

	/**
	 * 闸维度行（今日/累计判定计数 map）。
	 *
	 * @param array<string, mixed> $cache    StatsCache 树。
	 * @param string               $gate_id  闸 id。
	 * @param string               $label    行标签。
	 * @return array{label: string, today: array<string, int>, total: array<string, int>}
	 */
	private static function gate_dimension_row( array $cache, string $gate_id, string $label ): array {
		return array(
			'label' => $label,
			'today' => self::gate_counts( $cache, $gate_id, false ),
			'total' => self::gate_counts( $cache, $gate_id, true ),
		);
	}

	/**
	 * 驱动维度行（今日/累计 + usage 双口径，03 Tab⑥ v1.4·R1 §6-1）。
	 *
	 * @param array<string, mixed> $cache StatsCache 树。
	 * @param array<string, string> $driver_labels 驱动 id => 标签。
	 * @param array{usage_today?: array<string, int>, halted?: array<string, string>} $usage_context
	 *        驱动行 usage 上下文（缺省无 usage 列——行为兼容）。
	 * @return array<int, array<string, mixed>>
	 */
	private static function driver_dimension_rows( array $cache, array $driver_labels, array $usage_context = array() ): array {
		$usage_today = isset( $usage_context['usage_today'] ) && is_array( $usage_context['usage_today'] ) ? $usage_context['usage_today'] : array();
		$halted      = isset( $usage_context['halted'] ) && is_array( $usage_context['halted'] ) ? $usage_context['halted'] : array();

		$rows = array();
		foreach ( $driver_labels as $driver_id => $label ) {
			$today = self::driver_counts( $cache, $driver_id, false );

			// usage 双口径列：今日调用数 + 停转态 + 摘要（调用 0/全通过并存——R1 §6-1）。
			$used       = isset( $usage_today[ $driver_id ] ) && is_numeric( $usage_today[ $driver_id ] ) ? (int) $usage_today[ $driver_id ] : 0;
			$halt_state = isset( $halted[ $driver_id ] ) && is_string( $halted[ $driver_id ] ) ? $halted[ $driver_id ] : null;

			$rows[] = array(
				'label'      => $label,
				'today'      => $today,
				'total'      => self::driver_counts( $cache, $driver_id, true ),
				'usage_today' => $used,
				'halt_state' => $halt_state,
				'usage_note' => self::driver_usage_note( $today, $used, $halt_state ),
			);
		}
		return $rows;
	}

	/**
	 * @param array<string, mixed> $cache StatsCache 树。
	 * @param string $gate_id 闸 id。
	 * @param bool   $total   true = 累计树。
	 * @return array<string, int>
	 */
	private static function gate_counts( array $cache, string $gate_id, bool $total ): array {
		$scope = $total ? ( $cache['total']['by_gate'] ?? array() ) : ( $cache['by_gate'] ?? array() );
		$counts = isset( $scope[ $gate_id ] ) && is_array( $scope[ $gate_id ] ) ? $scope[ $gate_id ] : array();
		return array_map( 'intval', array_filter( $counts, 'is_numeric' ) );
	}

	/**
	 * @param array<string, mixed> $cache StatsCache 树。
	 * @param string $driver_id 驱动 id。
	 * @param bool   $total     true = 累计树。
	 * @return array<string, int>
	 */
	private static function driver_counts( array $cache, string $driver_id, bool $total ): array {
		$scope = $total ? ( $cache['total']['by_driver'] ?? array() ) : ( $cache['by_driver'] ?? array() );
		$counts = isset( $scope[ $driver_id ] ) && is_array( $scope[ $driver_id ] ) ? $scope[ $driver_id ] : array();
		return array_map( 'intval', array_filter( $counts, 'is_numeric' ) );
	}
}
