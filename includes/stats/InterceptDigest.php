<?php
/**
 * Tab⑥ 统计分区纯逻辑层（UI 批 1 重构——DESIGN-UI-tab6 A 提案三口径骨架）。
 *
 * 职责（全部零 WP 依赖，WP 边界函数只在 SettingsPage/StatsCache 边界层）：
 *   1. 「结果→归因→案例」渲染数据：待审拆分（排除 IP 信誉强制验证码——统筹裁决 2）、
 *      登录三张门分账、本地规则三数式卡；
 *   2. 驱动统一卡模板（§3——评论防护与登录防护复用同一张卡：四档健康徽标 + 箭头行 +
 *      额度行 + 未启用灰卡占位；数字自守恒/停转有主语/未启用占位三条展示纪律）；
 *   3. 「最近拦下的评论」明细行组装：events 表 reject 事件 + comment_id 直查 wp_comments
 *      的昵称/邮箱/正文——打码展示、摘要 60 字、编辑链接直达（v1.3 起沿用）；
 *   4. spam 留档量黄条阈值决策（阈值 500 沿用）；
 *   5. 【UI 批 2】事件查询行组装扩展：登录域拦截明细行（N1）/全闸故障 ∪ 停转解除
 *      合并时间线（N2+N3）/「当时怎么处理」组合映射/今日分布行侧口径（N4）。
 *
 * 术语纪律：本层输出一律走 Labels 人话（对照表资产）——「调用/维度/待审/登录失败
 * 尝试」等黑话不再出现在站长文案（N5 映射表落地）。
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
	 * 登录域三闸（by_gate 键随 GateRegistry 自动扩展，04 §4）。
	 *
	 * @var string[]
	 */
	public const ACCOUNT_GATES = array( 'login', 'register', 'lostpassword' );

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

	/* -------------------------------------------------------------------
	 * UI 批 2·事件行组装扩展（DESIGN-UI-tech-feasibility §G 批 2——门名/映射/省略三面）：
	 *   account_reject_rows  N1 登录域拦截明细行（门名/防线/原因/归因；IP 哈希省略）；
	 *   fault_timeline_rows  N2+N3 全闸故障 ∪ 停转解除合并时间线（id 倒序归并）；
	 *   handling_word        「当时怎么处理」组合映射（A-12——verdict/stage/gate）；
	 *   distribution_from_rows 分布行侧口径（N4——空 rule_hit 归并单键）。
	 * 全部零 WP 依赖；夹具行形态 = EventsTable 查回（meta 已解码）。
	 * ------------------------------------------------------------------ */

	/**
	 * N1 登录域拦截明细行组装（Tab⑥ 口径二④「最近拦下的登录尝试」）。
	 *
	 * 列面（任务口径）：时间 / 门（Labels::gate_word）/ 哪道防线（stage_word）/
	 * 为什么拦（trigger_source 归因人话优先，缺省回落 rule_hit 人话——Labels 映射，
	 * 未收录键原样转义直出不撒谎）。PIPL 最小化：IP 哈希省略（查询列集即不含）、
	 * 无明文账号列（事件表本就不存——agent C E-a：措辞「尝试特征」不渲染）。
	 *
	 * @param array<int, array<string, mixed>> $events 事件行（event_no/created_at/gate/
	 *                                                  stage/rule_hit/meta[decoded]）。
	 * @return array<int, array{event_no: string, created_at: string, gate: string, gate_word: string,
	 *                          stage_word: string, reason: string, trigger: string}>
	 */
	public static function account_reject_rows( array $events ): array {
		$rows = array();
		foreach ( $events as $event ) {
			$gate     = isset( $event['gate'] ) && is_string( $event['gate'] ) ? $event['gate'] : '';
			$stage    = isset( $event['stage'] ) && is_string( $event['stage'] ) ? $event['stage'] : '';
			$rule_hit = isset( $event['rule_hit'] ) && is_string( $event['rule_hit'] ) ? $event['rule_hit'] : '';
			$meta     = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
			$source   = isset( $meta['trigger_source'] ) && is_string( $meta['trigger_source'] ) ? $meta['trigger_source'] : '';

			$rows[] = array(
				'event_no'   => isset( $event['event_no'] ) && is_string( $event['event_no'] ) ? $event['event_no'] : '',
				'created_at' => isset( $event['created_at'] ) && is_string( $event['created_at'] ) ? $event['created_at'] : '',
				'gate'       => $gate,
				'gate_word'  => '' !== $gate ? Labels::gate_word( $gate ) : '',
				'stage_word' => '' !== $stage ? Labels::stage_word( $stage ) : '',
				'reason'     => '' !== $rule_hit ? Labels::rule_hit_word( $rule_hit ) : '',
				'trigger'    => '' !== $source ? Labels::trigger_word( $source ) : '',
			);
		}
		return $rows;
	}

	/**
	 * N2+N3 全闸故障 ∪ 停转解除合并时间线行组装（Tab⑥「三、报错历史②最近报错」）。
	 *
	 * 两源同表自增主键全局可比——按 id 倒序归并（各源查询已 id DESC，归并线性合并保持序）。
	 * 服务商名解析：registry label 映射（未注册 id 原样显示——不虚构人话名）。
	 * 「当时怎么处理」= handling_word() 组合映射；meta.raw 透传（展开行渲染层判空）。
	 *
	 * @param array<int, array<string, mixed>> $fault_events  N2 行（含 id/meta）。
	 * @param array<int, array<string, mixed>> $health_events N3 行（含 id；无 meta 列——安全回落）。
	 * @param array<string, string>            $driver_labels driver_id => registry label。
	 * @return array<int, array{id: int, event_no: string, created_at: string, gate: string,
	 *                        gate_word: string, stage: string, driver: string, driver_label: string,
	 *                        verdict: string, rule_hit: string, rule_word: string,
	 *                        handling: string, raw: array<string, mixed>}>
	 */
	public static function fault_timeline_rows( array $fault_events, array $health_events, array $driver_labels ): array {
		$merged = array_merge( $fault_events, $health_events );

		// id 倒序归并（id 缺失按 0 兜底排尾——防御性，正常查询恒带 id 列）。
		usort( $merged, static function ( array $a, array $b ): int {
			$id_a = isset( $a['id'] ) && is_numeric( $a['id'] ) ? (int) $a['id'] : 0;
			$id_b = isset( $b['id'] ) && is_numeric( $b['id'] ) ? (int) $b['id'] : 0;
			return $id_b <=> $id_a;
		} );

		$rows = array();
		foreach ( $merged as $event ) {
			$gate     = isset( $event['gate'] ) && is_string( $event['gate'] ) ? $event['gate'] : '';
			$stage    = isset( $event['stage'] ) && is_string( $event['stage'] ) ? $event['stage'] : '';
			$driver   = isset( $event['driver'] ) && is_string( $event['driver'] ) ? $event['driver'] : '';
			$verdict  = isset( $event['verdict'] ) && is_string( $event['verdict'] ) ? $event['verdict'] : '';
			$rule_hit = isset( $event['rule_hit'] ) && is_string( $event['rule_hit'] ) ? $event['rule_hit'] : '';
			$meta     = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
			$raw      = isset( $meta['raw'] ) && is_array( $meta['raw'] ) ? $meta['raw'] : array();

			$rows[] = array(
				'id'           => isset( $event['id'] ) && is_numeric( $event['id'] ) ? (int) $event['id'] : 0,
				'event_no'     => isset( $event['event_no'] ) && is_string( $event['event_no'] ) ? $event['event_no'] : '',
				'created_at'   => isset( $event['created_at'] ) && is_string( $event['created_at'] ) ? $event['created_at'] : '',
				'gate'         => $gate,
				'gate_word'    => '' !== $gate ? Labels::gate_word( $gate ) : '',
				'stage'        => $stage,
				'driver'       => $driver,
				'driver_label' => ( '' !== $driver && isset( $driver_labels[ $driver ] ) && is_string( $driver_labels[ $driver ] ) )
					? $driver_labels[ $driver ]
					: $driver,
				'verdict'      => $verdict,
				'rule_hit'     => $rule_hit,
				'rule_word'    => '' !== $rule_hit ? Labels::rule_hit_word( $rule_hit ) : '',
				'handling'     => self::handling_word( $gate, $stage, $verdict, $rule_hit ),
				'raw'          => $raw,
			);
		}
		return $rows;
	}

	/**
	 * 「当时怎么处理」组合映射（A-12：verdict/stage/gate → 一句话主语化结果；
	 * 状态码不出站长文案——优先 rule_hit 键族，缺省兜底统一话术）。
	 *
	 * 顺序敏感：停转/解除键族最先（跨 gate/verdict），降级放行次之（verdict=error 但落地放行），
	 * 再按门分域（评论 review error=收口待审；账户族 error=fail-closed 拦下），
	 * reject 形态远端故障=当场拦下（error_policy=block 兜底），其余=缺省兜底。
	 *
	 * @param string $gate     闸 id（system=健康域）。
	 * @param string $stage    阶段。
	 * @param string $verdict  判定。
	 * @param string $rule_hit 命中规则键（可能逗号拼接）。
	 * @return string
	 */
	public static function handling_word( string $gate, string $stage, string $verdict, string $rule_hit ): string {
		if ( 'driver_resumed' === $rule_hit ) {
			return '服务商恢复正常';
		}
		if ( 0 === strpos( $rule_hit, 'driver_halted:' ) ) {
			return '今日暂停，防线跳过它继续';
		}
		if ( '' !== $rule_hit && false !== strpos( $rule_hit, 'degraded' ) ) {
			return '故障降级放行（真访客不受影响）';
		}
		if ( 'error' === $verdict ) {
			if ( 'comment' === $gate && 'review' === $stage ) {
				return '先收进待审区，等你复核';
			}
			if ( in_array( $gate, self::ACCOUNT_GATES, true ) ) {
				return '拦下该次尝试（故障兜底）';
			}
			return '已按兜底策略处理';
		}
		if ( 'reject' === $verdict ) {
			return '当场拦下（故障兜底）';
		}
		return '已按兜底策略处理';
	}

	/**
	 * 分布行（rule_hit/n）→ 计数 map（N4 行侧口径——空/缺 rule_hit 归 '(无类别)' 单键，
	 * 计数强转 int；与 today_fault_distribution 现行归并一致）。
	 *
	 * @param array<int, array<string, mixed>>|mixed $rows 查询行（ARRAY_A 形态）。
	 * @return array<string, int>
	 */
	public static function distribution_from_rows( $rows ): array {
		$dist = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = isset( $row['rule_hit'] ) && is_string( $row['rule_hit'] ) && '' !== $row['rule_hit'] ? $row['rule_hit'] : '(无类别)';
			// 累加（同键多行归并——防御性；SQL GROUP BY 下不出现，行侧口径不依赖该前提）。
			$dist[ $key ] = ( $dist[ $key ] ?? 0 ) + ( isset( $row['n'] ) && is_numeric( $row['n'] ) ? (int) $row['n'] : 0 );
		}
		return $dist;
	}

	/* -------------------------------------------------------------------
	 * 待审拆分（统筹裁决 2：转人工复核排除 IP 信誉强制验证码——单列一行）
	 * ------------------------------------------------------------------ */

	/**
	 * 待审口径拆分（纯展示推导——现有统计树可答，零新数据）。
	 *
	 * 口径（04 §3）：IP 阶段 verdict=review/reject 均「强制验证码」信号（02 §3.1
	 * 修订表——reject 档也只强制过码不放行），recorder 原值记入 by_gate.comment.review
	 * 与 by_driver.{ip 服务商}.review；「转人工复核」按裁决只计云审/规则待审——
	 * 待审全量减去 IP 类服务商的 review 数，被减量与 reject 量单列展示。
	 *
	 * 现状注记：M3 IP 域服务商尚未注册（registry by_type('ip') 空）→ 折减恒 0，
	 * 待审数与既有口径一致；本函数为批 2/批 3 与 M3 接线预留同一口径源。
	 *
	 * @param array<string, mixed> $cache         StatsCache 树。
	 * @param string[]             $ip_driver_ids IP 类服务商 id 列表（registry by_type('ip') 键）。
	 * @param bool                 $total         true = 累计树。
	 * @return array{review: int, ip_review: int, ip_captcha: int}
	 *         review=转人工复核（不含 IP 风控）；ip_review=排除量；ip_captcha=单列行（review+reject）。
	 */
	public static function review_split( array $cache, array $ip_driver_ids, bool $total = false ): array {
		$review_all = (int) ( self::gate_counts( $cache, 'comment', $total )['review'] ?? 0 );

		$ip_review = 0;
		$ip_reject = 0;
		foreach ( $ip_driver_ids as $driver_id ) {
			if ( ! is_string( $driver_id ) || '' === $driver_id ) {
				continue;
			}
			$counts     = self::driver_counts( $cache, $driver_id, $total );
			$ip_review += (int) ( $counts['review'] ?? 0 );
			$ip_reject += (int) ( $counts['reject'] ?? 0 );
		}

		return array(
			'review'    => max( 0, $review_all - $ip_review ),
			'ip_review' => $ip_review,
			'ip_captcha' => $ip_review + $ip_reject,
		);
	}

	/* -------------------------------------------------------------------
	 * 驱动统一卡模板（DESIGN-UI-tab6 §3——评论/登录两段复用）
	 * ------------------------------------------------------------------ */

	/**
	 * 驱动统一卡（纯文本组装；WP 边界数据由 SettingsPage 预取进 $args）。
	 *
	 * 输入键（全部可选，缺省安全回落）：
	 *   label       服务商全名（registry label）。
	 *   type        captcha|review|ip——动作名翻译依据（Labels::action_word）。
	 *   context     comment|login——转人工/出错格取舍（登录族无待审，分域出错批 2 接线）。
	 *   enabled     是否当班（排班态推导：captcha=driver 选中；review=在 chain 中）。
	 *   halt_state  DriverHealth 当日停转态（null/''=正常）。
	 *   used        今日动作数（评论域=Usage；登录域=captcha_pass+captcha_reject 净口径）。
	 *   passed      放行数（数值=直读；null=减法推导 used−拦下−转人工−出错，下限 0——D4）。
	 *   rejected    今日拦下数。
	 *   review      今日转人工数（null=此格隐藏——登录域）。
	 *   errors      今日出错数（null=此格隐藏——分域口径未接入）。
	 *   quota       日配额（0=不限，无额度行）。
	 *   total       累计行数据（null=无累计行）：passed（数值=直读放行，否则格省略——
	 *               累计动作数 Usage 不可得）、rejected/review/errors（null=格省略）。
	 *   config_tab  未启用占位跳转目标 tab 键（''=不带链接——D6）。
	 *
	 * 展示纪律（§3.2）：零值整卡单行「今日没用到（0 次）」；停转主语化；未启用灰卡占位。
	 * 健康徽标四档优先级：未启用 > 配置问题（config_error）> 今日暂停（其余停转态）> 正常。
	 *
	 * @param array<string, mixed> $args 卡片输入（见概述）。
	 * @return array{label: string, type: string, action: string, enabled: bool, badge: string,
	 *               health: string, health_note: string, lines: string[], total_lines: string[],
	 *               quota_line: string|null, placeholder_note: string|null, config_tab: string}
	 */
	public static function driver_card( array $args ): array {
		$label   = isset( $args['label'] ) && is_string( $args['label'] ) ? $args['label'] : '';
		$type    = isset( $args['type'] ) && is_string( $args['type'] ) ? $args['type'] : '';
		$enabled = ! empty( $args['enabled'] );

		$halt = isset( $args['halt_state'] ) && is_string( $args['halt_state'] ) && '' !== $args['halt_state']
			? $args['halt_state']
			: null;
		$used      = isset( $args['used'] ) && is_numeric( $args['used'] ) ? (int) $args['used'] : 0;
		$passed_in = isset( $args['passed'] ) && is_numeric( $args['passed'] ) ? (int) $args['passed'] : null;
		$rejected  = isset( $args['rejected'] ) && is_numeric( $args['rejected'] ) ? (int) $args['rejected'] : 0;
		$has_review = array_key_exists( 'review', $args ) && null !== $args['review'];
		$review    = $has_review && is_numeric( $args['review'] ) ? (int) $args['review'] : 0;
		$has_errors = array_key_exists( 'errors', $args ) && null !== $args['errors'];
		$errors    = $has_errors && is_numeric( $args['errors'] ) ? (int) $args['errors'] : 0;
		$quota     = isset( $args['quota'] ) && is_numeric( $args['quota'] ) ? (int) $args['quota'] : 0;
		$config_tab = isset( $args['config_tab'] ) && is_string( $args['config_tab'] ) ? $args['config_tab'] : '';

		$card = array(
			'label'            => $label,
			'type'             => $type, // 回带输入类型（渲染层按防线分组筛选）。
			'action'           => Labels::action_word( $type ),
			'enabled'          => $enabled,
			'badge'            => '●',
			'health'           => '正常',
			'health_note'      => '',
			'lines'            => array(),
			'total_lines'      => array(),
			'quota_line'       => null,
			'placeholder_note' => null,
			'config_tab'       => $config_tab,
		);

		// 未启用占位灰卡（残留停转态不盖排班态——不当班为准）。
		if ( ! $enabled ) {
			$card['badge']            = '○';
			$card['health']           = '未启用';
			$card['health_note']      = '未启用——配置后这里会出现它的数字';
			$card['placeholder_note'] = '未启用——去 Tab②/③ 配置后这里会出现它的数字';
			return $card;
		}

		// 停转两档（主语化一句话，状态码不出站长文案）。
		if ( 'config_error' === $halt ) {
			$card['badge']       = '✕';
			$card['health']      = '配置问题';
			$card['health_note'] = '配置有问题——服务商拒绝了请求（查配置或欠费），当日暂停；修正凭据后点「测试凭据」解除';
		} elseif ( null !== $halt ) {
			$card['badge']       = '◐';
			$card['health']      = '今日暂停';
			$card['health_note'] = '今日暂停——' . Labels::halt_word( $halt ) . '，次日自动恢复';
		}

		// 今日行（零值纪律：全零单行；正数箭头行自守恒——放行+拦下+转人工+出错）。
		$zero = 0 === $used && 0 === $rejected && ( ! $has_review || 0 === $review ) && ( ! $has_errors || 0 === $errors );
		if ( $zero ) {
			$card['lines'] = array( Labels::zero_call_word() . '（0 次）' );
		} else {
			$passed = null !== $passed_in ? $passed_in : max( 0, $used - $rejected - $review - $errors );
			$parts  = array( '放行 ' . $passed, '拦下 ' . $rejected );
			if ( $has_review ) {
				$parts[] = '转人工 ' . $review;
			}
			if ( $has_errors ) {
				$parts[] = '出错 ' . $errors;
			}
			$card['lines'] = array( sprintf( '今日%s %d 次 → ', $card['action'], $used ) . implode( ' · ', $parts ) );
		}

		// 累计行（可选——累计动作数 Usage 不留存，放行格仅在可直读时出现）。
		$total = isset( $args['total'] ) && is_array( $args['total'] ) ? $args['total'] : null;
		if ( null !== $total ) {
			$card['total_lines'] = array( self::card_total_line( $total ) );
		}

		// 额度行（0=不限配额——无行）。
		if ( $quota > 0 ) {
			$card['quota_line'] = sprintf( '今日额度 %d / %d 次', $used, $quota );
		}

		return $card;
	}

	/**
	 * 统一卡累计行（放行格仅在 passed 直读或 used 可推导时出现——不虚构）。
	 *
	 * @param array<string, mixed> $total 累计数据（passed/review/errors 可 null=格省略）。
	 * @return string 形态「累计：放行 X · 拦下 Y · 转人工 Z · 出错 E」（格按数据取舍）。
	 */
	private static function card_total_line( array $total ): string {
		$passed_in = isset( $total['passed'] ) && is_numeric( $total['passed'] ) ? (int) $total['passed'] : null;
		$used_in   = isset( $total['used'] ) && is_numeric( $total['used'] ) ? (int) $total['used'] : null;
		$rejected  = isset( $total['rejected'] ) && is_numeric( $total['rejected'] ) ? (int) $total['rejected'] : 0;
		$has_review = array_key_exists( 'review', $total ) && null !== $total['review'];
		$review    = $has_review && is_numeric( $total['review'] ) ? (int) $total['review'] : 0;
		$has_errors = array_key_exists( 'errors', $total ) && null !== $total['errors'];
		$errors    = $has_errors && is_numeric( $total['errors'] ) ? (int) $total['errors'] : 0;

		$parts = array();
		if ( null !== $passed_in ) {
			$parts[] = '放行 ' . $passed_in;
		} elseif ( null !== $used_in ) {
			$parts[] = '放行 ' . max( 0, $used_in - $rejected - $review - $errors );
		}
		$parts[] = '拦下 ' . $rejected;
		if ( $has_review ) {
			$parts[] = '转人工 ' . $review;
		}
		if ( $has_errors ) {
			$parts[] = '出错 ' . $errors;
		}
		return '累计：' . implode( ' · ', $parts );
	}

	/* -------------------------------------------------------------------
	 * 本地规则卡（口径一②首位——三数式，agent C A-3 微调）
	 * ------------------------------------------------------------------ */

	/**
	 * 本地规则卡（「今日检查 N 条」无数据源砍除——by_driver.local_rules 三键直读）。
	 *
	 * @param array<string, mixed> $cache StatsCache 树。
	 * @return array{label: string, line: string, total_line: string}
	 */
	public static function local_rules_card( array $cache ): array {
		$today = self::driver_counts( $cache, 'local_rules', false );
		$total = self::driver_counts( $cache, 'local_rules', true );

		return array(
			'label'      => '本地规则',
			'line'       => self::triple_line( '今日拦下', $today ),
			'total_line' => self::triple_line( Labels::cumulative_word(), $total ),
		);
	}

	/**
	 * 三数式行（拦下/转人工复核/出错——零值显式，「恒 0 不可区分」教训延续）。
	 *
	 * @param string              $prefix 行前缀（今日拦下/累计拦下——首词已含「拦下」）。
	 * @param array<string, int>  $counts 判定计数。
	 * @return string 形态「今日拦下 2 · 转人工复核 1 · 出错 0」。
	 */
	private static function triple_line( string $prefix, array $counts ): string {
		$reject = (int) ( $counts['reject'] ?? 0 );
		$review = (int) ( $counts['review'] ?? 0 );
		$error  = (int) ( $counts['error'] ?? 0 );
		return sprintf(
			'%s %d · %s %d · %s %d',
			$prefix,
			$reject,
			Labels::review_word(),
			$review,
			Labels::error_word(),
			$error
		);
	}

	/* -------------------------------------------------------------------
	 * 登录防护段（口径二：概览合计 + 三张门分账）
	 * ------------------------------------------------------------------ */

	/**
	 * 登录域概览合计（三闸求和——拦下/验证放行/密码输错，今日或累计）。
	 *
	 * 放行=captcha_pass 合计（账户闸净口径，agent C E-c/F3）；密码输错=attempts 合计
	 * （仅 login 闸有此维，register/lostpassword 计 0）。
	 *
	 * @param array<string, mixed> $cache StatsCache 树。
	 * @param bool                 $total true = 累计树。
	 * @return array{reject: int, passed: int, attempts: int}
	 */
	public static function account_overview( array $cache, bool $total = false ): array {
		$reject = 0;
		$passed = 0;
		$attempts = 0;
		foreach ( self::ACCOUNT_GATES as $gate_id ) {
			$counts    = self::gate_counts( $cache, $gate_id, $total );
			$reject   += (int) ( $counts['reject'] ?? 0 );
			$passed   += (int) ( $counts['captcha_pass'] ?? 0 );
			$attempts += (int) ( $counts['attempts'] ?? 0 );
		}
		return array(
			'reject'   => $reject,
			'passed'   => $passed,
			'attempts' => $attempts,
		);
	}

	/**
	 * 三张门分账行（登录门/注册门/找回密码门——今日/累计拦下 + 说明列）。
	 *
	 * @param array<string, mixed> $cache StatsCache 树。
	 * @return array<int, array{gate: string, label: string, today_reject: int, total_reject: int,
	 *                          today: array<string, int>, total: array<string, int>, note: string}>
	 */
	public static function account_gate_rows( array $cache ): array {
		$rows = array();
		foreach ( self::ACCOUNT_GATES as $gate_id ) {
			$today = self::gate_counts( $cache, $gate_id, false );
			$total = self::gate_counts( $cache, $gate_id, true );
			$rows[] = array(
				'gate'         => $gate_id,
				'label'        => Labels::gate_word( $gate_id ),
				'today_reject' => (int) ( $today['reject'] ?? 0 ),
				'total_reject' => (int) ( $total['reject'] ?? 0 ),
				'today'        => $today,
				'total'        => $total,
				'note'         => self::gate_note( $gate_id, $today ),
			);
		}
		return $rows;
	}

	/**
	 * 分账说明列（attempts+captcha 判定推导——agent C A-8）。
	 *
	 * 登录门：密码输错 T 次（W 分钟窗口内同账号）触发过验证码（窗口随配置动态，D3）；
	 * 注册门/找回密码门：静态说明（常显验证码语义）。
	 *
	 * @param string             $gate           闸 id。
	 * @param array<string, int> $today_counts   今日计数。
	 * @param int                $window_minutes 密码输错计数窗口（login_adaptive.window_minutes）。
	 * @return string
	 */
	public static function gate_note( string $gate, array $today_counts, int $window_minutes = 15 ): string {
		if ( 'login' !== $gate ) {
			return 'register' === $gate ? '注册时一律要过人机验证' : '找回密码时一律要过人机验证';
		}
		$attempts = (int) ( $today_counts['attempts'] ?? 0 );
		return sprintf(
			'%s %d 次（%d 分钟窗口内同账号），触发过验证码',
			Labels::attempts_word(),
			$attempts,
			max( 1, $window_minutes )
		);
	}

	/* -------------------------------------------------------------------
	 * 计数读取（公开——SettingsPage 组装统一卡/速览条直用）
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string, mixed> $cache StatsCache 树。
	 * @param string $gate_id 闸 id。
	 * @param bool   $total   true = 累计树。
	 * @return array<string, int>
	 */
	public static function gate_counts( array $cache, string $gate_id, bool $total ): array {
		$scope = $total ? ( $cache['total']['by_gate'] ?? array() ) : ( $cache['by_gate'] ?? array() );
		$counts = isset( $scope[ $gate_id ] ) && is_array( $scope[ $gate_id ] ) ? $scope[ $gate_id ] : array();
		return array_map( 'intval', array_filter( $counts, 'is_numeric' ) );
	}

	/**
	 * @param array<string, mixed> $cache StatsCache 树。
	 * @param string $driver_id 服务商 id。
	 * @param bool   $total     true = 累计树。
	 * @return array<string, int>
	 */
	public static function driver_counts( array $cache, string $driver_id, bool $total ): array {
		$scope = $total ? ( $cache['total']['by_driver'] ?? array() ) : ( $cache['by_driver'] ?? array() );
		$counts = isset( $scope[ $driver_id ] ) && is_array( $scope[ $driver_id ] ) ? $scope[ $driver_id ] : array();
		return array_map( 'intval', array_filter( $counts, 'is_numeric' ) );
	}

	/**
	 * 账户四维人话小字（今日/累计共用——登录概览与分账注脚消费）。
	 *
	 * 口径（04 §4 v1.5 不变，展示词走 Labels）：人机验证 N=captcha_pass+captcha_reject+
	 * captcha 阶段 error；验证放行 M=captcha_pass；拦下 K=reject 全阶段；密码输错 T=attempts。
	 *
	 * @param array<string, int> $counts 计数 map（StatsCache by_gate 行）。
	 * @return string 形态「人机验证 6 次 · 验证放行 3 人 · 拦下 4 次 · 密码输错 7 次」。
	 */
	public static function account_usage_note( array $counts ): string {
		$pass      = isset( $counts['captcha_pass'] ) && is_numeric( $counts['captcha_pass'] ) ? (int) $counts['captcha_pass'] : 0;
		$reject_c  = isset( $counts['captcha_reject'] ) && is_numeric( $counts['captcha_reject'] ) ? (int) $counts['captcha_reject'] : 0;
		$error     = isset( $counts['error'] ) && is_numeric( $counts['error'] ) ? (int) $counts['error'] : 0;
		$intercept = isset( $counts['reject'] ) && is_numeric( $counts['reject'] ) ? (int) $counts['reject'] : 0;
		$attempts  = isset( $counts['attempts'] ) && is_numeric( $counts['attempts'] ) ? (int) $counts['attempts'] : 0;

		return sprintf(
			'%s %d 次 · %s %d 人 · %s %d 次 · %s %d 次',
			Labels::action_word( 'captcha' ),
			$pass + $reject_c + $error,
			Labels::pass_word(),
			$pass,
			Labels::reject_word(),
			$intercept,
			Labels::attempts_word(),
			$attempts
		);
	}
}
