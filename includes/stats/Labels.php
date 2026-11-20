<?php
/**
 * Tab⑥ 术语人话映射表（UI 批 1·N5——DESIGN-UI-tab6 §4 对照表 24 条 + 登录
 * trigger_source 全集；agent C §C A-愿4/N5「纯展示层映射，零新数据」）。
 *
 * 定位：站长词汇资产（「一眼看懂」）——Tab⑥（批 1）渲染、批 2 事件行组装、
 * 批 3 Tab① 点名册共用同一张表（§G 批间共享件）。仿 F4-3 错误码映射层先例
 * （Copywork 键值表 + filter 覆盖形态），本层先行落地键值表本体。
 *
 * 纪律（DESIGN-UI-tab6 §4 备注）：
 *   - 「调用」永不再单独出现（按服务商类型译：人机验证/送审/信誉查询）；
 *   - 未收录键原样返回（不吞不译——转义后直出，不撒谎）；
 *   - 状态码（quota_exhausted 等）不出现在站长文案（「停转有主语」）。
 *
 * 纯函数零 WP 依赖；单测 LabelsTest 钉全表。
 *
 * @package WinkGatekeeper\Stats
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（S2-F1 纵深加固——类文件直访零执行，守卫为纵深面）。
}

final class Labels {

	/**
	 * 「调用」按服务商类型翻译（对照表 1）。
	 *
	 * @param string $driver_type 服务商类型（captcha|review|ip；registry 注册校验过枚举）。
	 * @return string 人机验证|送审|信誉查询（未知类型回落「动作」，不回落「调用」黑话）。
	 */
	public static function action_word( string $driver_type ): string {
		return match ( $driver_type ) {
			'captcha' => __( 'Human verification', 'wink-wp-gatekeeper' ),
			'review'  => __( 'Sent for review', 'wink-wp-gatekeeper' ),
			'ip'      => __( 'Reputation lookup', 'wink-wp-gatekeeper' ),
			default   => __( 'Action', 'wink-wp-gatekeeper' ),
		};
	}

	/**
	 * 通过 → 验证放行（对照表 2——「是真访客，过了码就进」）。
	 */
	public static function pass_word(): string {
		return __( 'Verification pass', 'wink-wp-gatekeeper' );
	}

	/**
	 * 拦截 → 拦下（对照表 3——口语词）。
	 */
	public static function reject_word(): string {
		return __( 'Blocked', 'wink-wp-gatekeeper' );
	}

	/**
	 * 计数量词（对照表 3 备注：条=评论、次=登录）。
	 *
	 * @param string $gate 闸 id。
	 */
	public static function count_unit( string $gate ): string {
		return 'comment' === $gate ? __( 'comments', 'wink-wp-gatekeeper' ) : __( 'times', 'wink-wp-gatekeeper' );
	}

	/**
	 * 待审 → 转人工复核（对照表 4——首次出现处由渲染层加括号注「进了待审区，等你处理」）。
	 */
	public static function review_word(): string {
		return __( 'Manual review', 'wink-wp-gatekeeper' );
	}

	/**
	 * 故障/error → 出错（对照表 5）。
	 */
	public static function error_word(): string {
		return __( 'Errors', 'wink-wp-gatekeeper' );
	}

	/**
	 * 登录失败尝试 → 密码输错（对照表 6）。
	 */
	public static function attempts_word(): string {
		return __( 'Wrong passwords', 'wink-wp-gatekeeper' );
	}

	/**
	 * 驱动 → 服务商（对照表 7——统筹裁决 1：系统层统一叫「服务商」）。
	 */
	public static function driver_word(): string {
		return __( 'Provider', 'wink-wp-gatekeeper' );
	}

	/**
	 * 维度（表头）→ 防线（对照表 8——「谁拦的」）。
	 */
	public static function defense_word(): string {
		return __( 'Defense line', 'wink-wp-gatekeeper' );
	}

	/**
	 * 判定（表头）→ 结果（对照表 9）。
	 */
	public static function verdict_word(): string {
		return __( 'Result', 'wink-wp-gatekeeper' );
	}

	/**
	 * 停转状态 → 主语词（对照表 10——状态码不进站长文案）。
	 *
	 * @param string|null $state DriverHealth 停转态（null=正常）。
	 * @return string 额度用完|配置有问题|''（未知态原样——防御性直出不吞键）。
	 */
	public static function halt_word( ?string $state ): string {
		if ( null === $state || '' === $state ) {
			return '';
		}
		return match ( $state ) {
			'quota_exhausted' => __( 'Quota used up', 'wink-wp-gatekeeper' ),
			'config_error'    => __( 'Configuration problem', 'wink-wp-gatekeeper' ),
			default           => $state,
		};
	}

	/**
	 * 0 调用 → 今日没用到（对照表 11）。
	 */
	public static function zero_call_word(): string {
		return __( 'Not used today', 'wink-wp-gatekeeper' );
	}

	/**
	 * 阶段 → 防线词（对照表 12——stub/签名存根→机器人试探；明细列「哪道防线拦的」；
	 * 【M6·批B·B-5】url_audit 运行性错误事件行（报错历史/时间线「网址核查」防线名）。
	 *
	 * @param string $stage 阶段键（stub|ip|captcha|review|url_audit；开放枚举原样返回）。
	 */
	public static function stage_word( string $stage ): string {
		return match ( $stage ) {
			'stub'    => __( 'Bot probing', 'wink-wp-gatekeeper' ),
			'ip'      => __( 'IP reputation', 'wink-wp-gatekeeper' ),
			'captcha' => __( 'Human verification', 'wink-wp-gatekeeper' ),
			'review'  => __( 'Content moderation', 'wink-wp-gatekeeper' ),
			'url_audit' => __( 'URL check', 'wink-wp-gatekeeper' ), // 【M6·批B·B-5】运行性错误事件 stage 人话.
			default   => $stage,
		};
	}

	/**
	 * rule_hit 键 → 人话（对照表 13-17 + 评论规则族；未收录键原样返回）。
	 *
	 * 覆盖四族：远端故障族（remote_*）、契约/停转族（contract_mismatch/driver_halted:*）、
	 * 评论规则族（too_short/too_long/no_chinese/banned_word/hold_moderation/duplicate_comment/
	 * comment_flood——后两键为 WP 核心重复/洪泛拒绝，UI-3-3 补录人话名，不再裸奔直出键名）、
	 * 验证码族（captcha_missing/bad-signature/CaptchaCode=N）。
	 *
	 * @param string $rule_hit 命中规则键（可能逗号拼接——未收录原样）。
	 */
	public static function rule_hit_word( string $rule_hit ): string {
		if ( '' === $rule_hit ) {
			return '';
		}

		$exact = array(
			'remote_transport'  => __( 'Network unreachable', 'wink-wp-gatekeeper' ),
			'remote_semantic'   => __( 'Provider refused the request (check configuration or billing)', 'wink-wp-gatekeeper' ),
			'remote_contract'   => __( 'Provider returned an unreadable result', 'wink-wp-gatekeeper' ),
			'contract_mismatch' => __( 'Provider returned an unreadable result', 'wink-wp-gatekeeper' ),
			'driver_resumed'    => __( 'Provider recovered', 'wink-wp-gatekeeper' ),
			'too_short'         => __( 'Too short', 'wink-wp-gatekeeper' ),
			'too_long'          => __( 'Over the length limit', 'wink-wp-gatekeeper' ),
			'no_chinese'        => __( 'No Chinese content', 'wink-wp-gatekeeper' ),
			'banned_word'       => __( 'Keyword blacklist hit', 'wink-wp-gatekeeper' ),
			'hold_moderation'   => __( 'Pended for the site owner to review', 'wink-wp-gatekeeper' ),
			'captcha_missing'   => __( 'No captcha ticket', 'wink-wp-gatekeeper' ),
			'bad-signature'     => __( 'Bad ticket signature', 'wink-wp-gatekeeper' ),
			'duplicate_comment' => __( 'Duplicate of an existing comment', 'wink-wp-gatekeeper' ),
			'comment_flood'     => __( 'Posted too fast (flood protection)', 'wink-wp-gatekeeper' ),
			'trust_pass'        => __( 'Trusted-visitor waiver passed', 'wink-wp-gatekeeper' ),
			'verify_cache_hit'  => __( 'Ticket reuse within window', 'wink-wp-gatekeeper' ),
			// 【M6·批B·B-5】网址核查配额暂停边沿事件人话（报错历史时间线）。
			'quota_paused'      => __( 'Daily quota used up (URL checks paused)', 'wink-wp-gatekeeper' ),
		);
		if ( isset( $exact[ $rule_hit ] ) ) {
			return $exact[ $rule_hit ];
		}

		if ( 'driver_halted:quota_exhausted' === $rule_hit ) {
			return __( 'Daily quota used up', 'wink-wp-gatekeeper' );
		}
		if ( 'driver_halted:config_error' === $rule_hit ) {
			return __( 'Configuration problem (the provider refused the request)', 'wink-wp-gatekeeper' );
		}
		if ( 0 === strpos( $rule_hit, 'driver_halted:' ) ) {
			return __( 'Provider paused (', 'wink-wp-gatekeeper' ) . substr( $rule_hit, strlen( 'driver_halted:' ) ) . '）';
		}
		if ( 0 === strpos( $rule_hit, 'CaptchaCode=' ) ) {
			return __( 'Human verification failed (return code ', 'wink-wp-gatekeeper' ) . substr( $rule_hit, strlen( 'CaptchaCode=' ) ) . '）';
		}
		if ( 0 === strpos( $rule_hit, 'remote_' ) ) {
			return __( 'Provider had a communication failure', 'wink-wp-gatekeeper' );
		}
		return $rule_hit; // 未收录键原样（不撒谎）。
	}

	/**
	 * 【W3·批B·四改①】故障原因 → reason-tag 严重度档（Tab⑥ 报错历史着色纪律——纯函数件
	 * 可测；设计原理=原型 tab6 样张：服务商名恒中性不着色，原因按严重度着色）。
	 *
	 * 四档闭集（CSS .reason-tag--{red|amber|green|dim}）：绿=已自愈（解除记录）、
	 * 黄=可自愈/软性故障（额度/网络类——次日恢复或瞬时）、红=需站长动手（密钥/配置/
	 * 契约被拒）、灰=中性（其余/未收录键——不虚构严重度）。映射基于 rule_hit 键面
	 * （fault_timeline_rows 行的 rule_hit 原键——前缀族 driver_halted:/remote_ 按族归档）。
	 *
	 * @param string $rule_hit 命中规则键（可含 driver_halted: 前缀）。
	 * @return string red|amber|green|dim（闭集外回落 dim=中性）。
	 */
	public static function fault_reason_severity( string $rule_hit ): string {
		if ( '' === $rule_hit ) {
			return 'dim';
		}
		// 绿=解除记录（故障→修复小剧场的「修复」侧）。
		if ( 'driver_resumed' === $rule_hit ) {
			return 'green';
		}
		// 黄=软性/可自愈：额度类（当日停转次日自愈）+ 网络传输类（瞬时）+ 洪泛（瞬时节流）。
		$amber = array( 'driver_halted:quota_exhausted', 'quota_paused', 'remote_transport', 'comment_flood' );
		if ( in_array( $rule_hit, $amber, true ) ) {
			return 'amber';
		}
		// 红=需站长动手：密钥/配置被拒（停转）+ 服务商拒绝/契约不符 + 票据签名异常。
		$red = array( 'driver_halted:config_error', 'remote_semantic', 'remote_contract', 'contract_mismatch', 'bad-signature' );
		if ( in_array( $rule_hit, $red, true ) ) {
			return 'red';
		}
		// 其余 driver_halted:* 前缀族（未知停转原因）归黄档保守提示；未收录键中性。
		if ( 0 === strpos( $rule_hit, 'driver_halted:' ) ) {
			return 'amber';
		}
		return 'dim';
	}

	/**
	 * 【W3·批B·四改③】登录流水结果列映射（Q3 统筹代裁=现数据面：时间/结果三态/哪张门/
	 * 为什么拦/WK-——「出码·拦下｜未过码·拦下」两态如实，出码·放行不在拒绝流水数据面
	 * 内不虚构）。stage 闭集=GateRegistry（ip|captcha——登录族无 review 阶段，02 §2.7）。
	 *
	 * @param string $stage 事件 stage 键。
	 * @return string 出码 · 拦下（captcha 档）| 未过码 · 拦下（其余=预检/IP 档未到出码即拦）。
	 */
	public static function account_result_word( string $stage ): string {
		if ( 'captcha' === $stage ) {
			return __( 'Captcha shown · blocked', 'wink-wp-gatekeeper' );
		}
		return __( 'Captcha not passed · blocked', 'wink-wp-gatekeeper' );
	}

	/**
	 * 【W3·批D·N8】导出「结果」列映射（Tab⑥ 拦截流水 CSV——全闸拒绝行推广面）：
	 * 出码语汇只对真实经过码关的阶段使用——captcha 阶段=出码 · 拦下（account_result_word
	 * 同源）；码前阶段（stub 预检/ip 信誉）=未过码 · 拦下（同源两态如实）；码后/免验
	 * 不可分阶段（review 等）=如实「拦下」——免验与已过码两实态在拒绝行数据面不可分，
	 * 不冒充码语汇（放行不在拒绝数据面，三态之「出码 · 放行」永不出现）。
	 *
	 * @param string $stage 事件 stage 键。
	 * @return string 出码 · 拦下｜未过码 · 拦下｜拦下。
	 */
	public static function export_result_word( string $stage ): string {
		return match ( $stage ) {
			'captcha'    => self::account_result_word( 'captcha' ),
			'stub', 'ip' => self::account_result_word( 'stub' ),
			default      => __( 'Blocked', 'wink-wp-gatekeeper' ),
		};
	}

	/**
	 * meta.raw/脱敏快照 → 服务商原始回复（对照表 18——设置项与展开行共用）。
	 */
	public static function snapshot_word(): string {
		return __( 'Raw provider response', 'wink-wp-gatekeeper' );
	}

	/**
	 * 快照截断字节 → 保存长度上限（字）（对照表 19）。
	 */
	public static function snapshot_limit_word(): string {
		return __( 'Storage length cap (characters)', 'wink-wp-gatekeeper' );
	}

	/**
	 * 事件流 → 拦截记录流水（对照表 21）。
	 */
	public static function event_stream_word(): string {
		return __( 'Intercept log', 'wink-wp-gatekeeper' );
	}

	/**
	 * 计数缓存/事件表/对账口径 → 所有统计数字和拦截记录（对照表 22——重置卡人话）。
	 */
	public static function reset_scope_word(): string {
		return __( 'All statistics and intercept records', 'wink-wp-gatekeeper' );
	}

	/**
	 * 闸 → 段名（对照表 23——评论防护/登录防护）。
	 *
	 * @param string $gate 闸 id。
	 */
	public static function section_word( string $gate ): string {
		return match ( $gate ) {
			'comment' => __( 'Comment protection', 'wink-wp-gatekeeper' ),
			'login'   => __( 'Sign-in protection', 'wink-wp-gatekeeper' ),
			default   => $gate,
		};
	}

	/**
	 * 闸 → 门名（对照表 23——闸字退到副标题括号隐喻层，正文用「门」；
	 * 【UI 批 2】system=健康域留痕行（停转进入·解除时间线门名列）。
	 *
	 * @param string $gate 闸 id。
	 */
	public static function gate_word( string $gate ): string {
		return match ( $gate ) {
			'login'        => __( 'Login', 'wink-wp-gatekeeper' ),
			'register'     => __( 'Registration', 'wink-wp-gatekeeper' ),
			'lostpassword' => __( 'Password recovery', 'wink-wp-gatekeeper' ),
			'comment'      => __( 'Comments', 'wink-wp-gatekeeper' ),
			'system'       => __( 'Provider health', 'wink-wp-gatekeeper' ),
			default        => $gate,
		};
	}

	/**
	 * 累计拦截 → 累计拦下（对照表 24 词头）。
	 */
	public static function cumulative_word(): string {
		return __( 'Blocked cumulatively', 'wink-wp-gatekeeper' );
	}

	/**
	 * 累计行组装（对照表 24——「累计拦下 N 条（自 YYYY-MM-DD 上次清零起）」）。
	 *
	 * 窗口标注（P9 修复）：$since_date 非空才带「自 … 上次清零起」段——last_reset=0
	 * （安装后从未清零）不虚构起点。
	 *
	 * @param string $formatted_total 数字串（WP 边界 number_format_i18n 后传入——本层零 WP 依赖）。
	 * @param string $unit            量词（条/次——count_unit）。
	 * @param string $since_date      上次清零日期（''=从未清零）。
	 */
	public static function cumulative_line( string $formatted_total, string $unit, string $since_date ): string {
		$line = self::cumulative_word() . ' ' . $formatted_total . ' ' . $unit;
		if ( '' !== $since_date ) {
			$line .= __( '(since ', 'wink-wp-gatekeeper' ) . $since_date . __( ', last reset)', 'wink-wp-gatekeeper' );
		}
		return $line;
	}

	/**
	 * 登录出码归因 → 人话（愿望 4/N5——trigger_source 全集 7 值，F13 素材；
	 * 批 2「最近拦下的登录尝试」为什么拦列消费，本批先落表）。
	 *
	 * @param string $source trigger_source 键（未知键原样返回）。
	 */
	public static function trigger_word( string $source ): string {
		return match ( $source ) {
			'user_threshold' => __( 'Too many consecutive failures for this account', 'wink-wp-gatekeeper' ),
			'ip_threshold'   => __( 'This IP tried too many times', 'wink-wp-gatekeeper' ),
			'iprep'          => __( 'IP has a bad reputation', 'wink-wp-gatekeeper' ),
			'alarm'          => __( 'Site alarm period (many recent failures)', 'wink-wp-gatekeeper' ),
			'stale_form'     => __( 'Form submitted after sitting too long', 'wink-wp-gatekeeper' ),
			'always'         => __( 'Configured to always require human verification', 'wink-wp-gatekeeper' ),
			'xmlrpc'         => __( 'Attempt via XML-RPC', 'wink-wp-gatekeeper' ),
			default          => $source,
		};
	}

	/* -------------------------------------------------------------------
	 * UI 批 3·Tab① 点名册（DESIGN-UI-tab1 §3 对照表 17 条并入——批 1 已收录键
	 * 不重复落表：驱动→服务商（driver_word）/额度用完·配置有问题（halt_word）/
	 * 今日没用到（zero_call_word）/人机验证·内容审核（stage_word）/转人工复核
	 * （review_word，Tab⑥ 语境）；本段只落总览新增面。统筹裁决：点名册直白命名
	 * （不用保安比喻）、系统层统一叫「服务商」。
	 * ------------------------------------------------------------------- */

	/**
	 * 排班态词（对照表 B-5——当班/待命/未配置；点名册主行）。
	 *
	 * @param string $duty 排班键（active|standby|unconfigured；未知键原样返回）。
	 */
	public static function duty_word( string $duty ): string {
		return match ( $duty ) {
			'active'       => __( 'On duty', 'wink-wp-gatekeeper' ),
			'standby'      => __( 'Standby', 'wink-wp-gatekeeper' ),
			'unconfigured' => __( 'Not configured', 'wink-wp-gatekeeper' ),
			default        => $duty,
		};
	}

	/**
	 * 健康态词（对照表 B-2/B-3——null=正常；config_error→故障；quota_exhausted→
	 * 额度用完；未知停转态原样返回不吞键）。
	 *
	 * @param string|null $state DriverHealth 当日停转态（null/''=正常）。
	 */
	public static function health_word( ?string $state ): string {
		if ( null === $state || '' === $state ) {
			return __( 'Normal', 'wink-wp-gatekeeper' );
		}
		return match ( $state ) {
			'config_error'    => __( 'Fault', 'wink-wp-gatekeeper' ),
			'quota_exhausted' => __( 'Quota used up', 'wink-wp-gatekeeper' ),
			default           => $state,
		};
	}

	/**
	 * 停转原因括号注（对照表 B-2/B-3/B-4——主语化原因，状态码不出站长文案；
	 * 正常态空串）。
	 *
	 * @param string|null $state DriverHealth 当日停转态（null/''=无注）。
	 */
	public static function halt_note( ?string $state ): string {
		if ( null === $state || '' === $state ) {
			return '';
		}
		return match ( $state ) {
			'config_error'    => __( '(key rejected or service not enabled, paused for the day; fix the credentials and click “Test credentials” to clear)', 'wink-wp-gatekeeper' ),
			'quota_exhausted' => __( '(today\'s quota is used up; resumes automatically tomorrow)', 'wink-wp-gatekeeper' ),
			default           => '（' . $state . '）',
		};
	}

	/**
	 * 待审队列 → 待你过目（对照表 B-10——总览行动行语境；Tab⑥ 明细语境仍用
	 * review_word「转人工复核」，两词各安其位）。
	 */
	public static function pending_word(): string {
		return __( 'Awaiting your review', 'wink-wp-gatekeeper' );
	}

	/**
	 * fail_mode/降级档/故障降级 → 备胎方案（对照表 B-11）。
	 */
	public static function failover_word(): string {
		return __( 'Fallback plan', 'wink-wp-gatekeeper' );
	}

	/**
	 * daily_quota → 每日额度（对照表 B-13）。
	 */
	public static function quota_word(): string {
		return __( 'Daily limit', 'wink-wp-gatekeeper' );
	}

	/**
	 * test_mode → 演练模式（对照表 B-14——总览通栏标题词；说明句在渲染层）。
	 */
	public static function test_mode_word(): string {
		return __( 'Drill mode', 'wink-wp-gatekeeper' );
	}

	/**
	 * spam 留档 → 垃圾箱存档（对照表 B-16——拦下的垃圾评论自动存档备查）。
	 */
	public static function spam_word(): string {
		return __( 'Spam archive', 'wink-wp-gatekeeper' );
	}

	/**
	 * pass 默认不计数 → 推算口径注（对照表 B-9——点名册「全部通过」脚注；
	 * 显式说清「通过」是怎么算出来的，不撒谎）。
	 */
	public static function pass_uncounted_note(): string {
		return __( '“All passed” is an estimate — normal approvals are not counted (only blocked and pended ones are)', 'wink-wp-gatekeeper' );
	}

	/**
	 * 本地规则脚注（对照表 B-7——「本地规则无外部故障面，恒正常」人话）。
	 */
	public static function local_rules_footnote(): string {
		return __( 'The built-in rules (local rules) are always on duty, depend on no external service, and need no keys', 'wink-wp-gatekeeper' );
	}

	/**
	 * chain/审核链 → 当前审核服务（对照表 B-8——首发单驱动语境不出现「链」）。
	 */
	public static function review_service_word(): string {
		return __( 'Current moderation service', 'wink-wp-gatekeeper' );
	}

	/**
	 * 按岗位动词（对照表 B-12——「今日验 N 次/今日审 N 次」；ip=查；
	 * 未知类型回落「动作」，不回落「调用」黑话）。
	 *
	 * @param string $driver_type 服务商类型（captcha|review|ip）。
	 */
	public static function action_verb( string $driver_type ): string {
		return match ( $driver_type ) {
			'captcha' => __( 'Verify', 'wink-wp-gatekeeper' ),
			'review'  => __( 'Review', 'wink-wp-gatekeeper' ),
			'ip'      => __( 'Check', 'wink-wp-gatekeeper' ),
			default   => __( 'Action', 'wink-wp-gatekeeper' ),
		};
	}

	/**
	 * 战报副行人话族（对照表 B-15——规则键→人话族短词，前 3 段紧凑形态
	 * 「关键词黑名单 2 · 纯外文 1」；未收录键回落 rule_hit_word 同一资产——
	 * 两页共用一张映射表，agent C W6 结论）。
	 *
	 * 注：B 提案示例族词「广告骚扰/骂人灌水」调整为诚实键名族词——banned_word
	 * 黑名单由站长自定义（可含广告词也可含辱骂词），归并为「广告骚扰」会撒谎
	 * （不撒谎纪律优先，偏差已列入批 3 报告 docs 差异清单给统筹）。
	 *
	 * @param string $rule_hit 命中规则键（可能逗号拼接/前缀族）。
	 */
	public static function rule_family_word( string $rule_hit ): string {
		if ( '' === $rule_hit ) {
			return '';
		}
		$exact = array(
			'banned_word'       => __( 'Keyword blacklist', 'wink-wp-gatekeeper' ),
			'no_chinese'        => __( 'Non-Chinese only', 'wink-wp-gatekeeper' ),
			'too_short'         => __( 'Too short', 'wink-wp-gatekeeper' ),
			'too_long'          => __( 'Over length limit', 'wink-wp-gatekeeper' ),
			'hold_moderation'   => __( 'Manual', 'wink-wp-gatekeeper' ),
			'captcha_missing'   => __( 'No ticket', 'wink-wp-gatekeeper' ),
			'bad-signature'     => __( 'Bad ticket signature', 'wink-wp-gatekeeper' ),
			'contract_mismatch' => __( 'Provider failure', 'wink-wp-gatekeeper' ),
		);
		if ( isset( $exact[ $rule_hit ] ) ) {
			return $exact[ $rule_hit ];
		}
		if ( 0 === strpos( $rule_hit, 'CaptchaCode=' ) ) {
			return __( 'Human verification failed', 'wink-wp-gatekeeper' );
		}
		if ( 0 === strpos( $rule_hit, 'remote_' ) ) {
			return __( 'Provider communication failure', 'wink-wp-gatekeeper' );
		}
		return self::rule_hit_word( $rule_hit );
	}

	/* -------------------------------------------------------------------
	 * 【M6·批I·I-1】仪表盘调用量卡计量键 → 行人话名（P-1 抛光——来源
	 * IMPL-M6-H-VERIFY §2 目验新发现：fetch/search 域行显示原始计量键退役）。
	 * ------------------------------------------------------------------- */

	/**
	 * 计量键 → 调用量卡行名（键位常量单一来源=Usage::KEY_*；键闭集映射，
	 * 未收录键返回 ''——消费面回落原键不吞不虚构）。
	 *
	 * 单一来源注记：url_audit_fetch/incr/batch 三键只由 DeepSeek 抓取适配器/判定引擎
	 * 计量（批 H H-1 语义——本站直抓零外呼零计量、抓取未配置零计量），键→名静态映射
	 * 恒真；若按「当前 fetcher」动态解析，日内改配置会把上午的 DeepSeek 计数错标成
	 * 本站直抓（不撒谎纪律优先，静态键映射）。驱动 id 行名**不进本表**（显示名单一
	 * 来源=registry_entry()['label'] 经 Bootstrap 注入面）。
	 *
	 * @param string $key 计量键。
	 * @return string 人话行名（未收录键=''）。
	 */
	public static function usage_row_word( string $key ): string {
		return match ( $key ) {
			Usage::KEY_URL_AUDIT_FETCH  => __( 'Page fetching (DeepSeek)', 'wink-wp-gatekeeper' ),          // 抓取成功次（DeepSeekFetchAdapter 响应侧）。
			Usage::KEY_URL_AUDIT_INCR   => __( 'URL verdict · new-comment lane (DeepSeek)', 'wink-wp-gatekeeper' ), // 新评论车道判定次。
			Usage::KEY_URL_AUDIT_BATCH  => __( 'URL verdict · batch lane (DeepSeek)', 'wink-wp-gatekeeper' ), // 存量网址核查车道判定次。
			Usage::KEY_DEEPSEEK_SEARCH  => __( 'Web search (DeepSeek)', 'wink-wp-gatekeeper' ),
			'bocha_search'              => __( 'Web search (Bocha)', 'wink-wp-gatekeeper' ),              // M5 U5 K3 键（Usage 无常量位——字面同源单点）。
			default                     => '',
		};
	}
}
