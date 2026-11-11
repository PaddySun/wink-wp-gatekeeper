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

final class Labels {

	/**
	 * 「调用」按服务商类型翻译（对照表 1）。
	 *
	 * @param string $driver_type 服务商类型（captcha|review|ip；registry 注册校验过枚举）。
	 * @return string 人机验证|送审|信誉查询（未知类型回落「动作」，不回落「调用」黑话）。
	 */
	public static function action_word( string $driver_type ): string {
		return match ( $driver_type ) {
			'captcha' => '人机验证',
			'review'  => '送审',
			'ip'      => '信誉查询',
			default   => '动作',
		};
	}

	/**
	 * 通过 → 验证放行（对照表 2——「是真访客，过了码就进」）。
	 */
	public static function pass_word(): string {
		return '验证放行';
	}

	/**
	 * 拦截 → 拦下（对照表 3——口语词）。
	 */
	public static function reject_word(): string {
		return '拦下';
	}

	/**
	 * 计数量词（对照表 3 备注：条=评论、次=登录）。
	 *
	 * @param string $gate 闸 id。
	 */
	public static function count_unit( string $gate ): string {
		return 'comment' === $gate ? '条' : '次';
	}

	/**
	 * 待审 → 转人工复核（对照表 4——首次出现处由渲染层加括号注「进了待审区，等你处理」）。
	 */
	public static function review_word(): string {
		return '转人工复核';
	}

	/**
	 * 故障/error → 出错（对照表 5）。
	 */
	public static function error_word(): string {
		return '出错';
	}

	/**
	 * 登录失败尝试 → 密码输错（对照表 6）。
	 */
	public static function attempts_word(): string {
		return '密码输错';
	}

	/**
	 * 驱动 → 服务商（对照表 7——统筹裁决 1：系统层统一叫「服务商」）。
	 */
	public static function driver_word(): string {
		return '服务商';
	}

	/**
	 * 维度（表头）→ 防线（对照表 8——「谁拦的」）。
	 */
	public static function defense_word(): string {
		return '防线';
	}

	/**
	 * 判定（表头）→ 结果（对照表 9）。
	 */
	public static function verdict_word(): string {
		return '结果';
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
			'quota_exhausted' => '额度用完',
			'config_error'    => '配置有问题',
			default           => $state,
		};
	}

	/**
	 * 0 调用 → 今日没用到（对照表 11）。
	 */
	public static function zero_call_word(): string {
		return '今日没用到';
	}

	/**
	 * 阶段 → 防线词（对照表 12——stub/签名存根→机器人试探；明细列「哪道防线拦的」）。
	 *
	 * @param string $stage 阶段键（stub|ip|captcha|review；开放枚举原样返回）。
	 */
	public static function stage_word( string $stage ): string {
		return match ( $stage ) {
			'stub'    => '机器人试探',
			'ip'      => 'IP 信誉',
			'captcha' => '人机验证',
			'review'  => '内容审核',
			default   => $stage,
		};
	}

	/**
	 * rule_hit 键 → 人话（对照表 13-17 + 评论规则族；未收录键原样返回）。
	 *
	 * 覆盖四族：远端故障族（remote_*）、契约/停转族（contract_mismatch/driver_halted:*）、
	 * 评论规则族（too_short/too_long/no_chinese/banned_word/hold_moderation）、
	 * 验证码族（captcha_missing/bad-signature/CaptchaCode=N）。
	 *
	 * @param string $rule_hit 命中规则键（可能逗号拼接——未收录原样）。
	 */
	public static function rule_hit_word( string $rule_hit ): string {
		if ( '' === $rule_hit ) {
			return '';
		}

		$exact = array(
			'remote_transport'  => '网络连不上',
			'remote_semantic'   => '服务商拒绝了（查配置或欠费）',
			'remote_contract'   => '服务商返回了看不懂的结果',
			'contract_mismatch' => '服务商返回了看不懂的结果',
			'driver_resumed'    => '服务商恢复正常',
			'too_short'         => '字数太少',
			'too_long'          => '超出字数上限',
			'no_chinese'        => '没有中文内容',
			'banned_word'       => '命中关键词黑名单',
			'hold_moderation'   => '收进待审区等站长复核',
			'captcha_missing'   => '没带验证码票据',
			'bad-signature'     => '票据签名不对',
			'trust_pass'        => '信用免验通过',
			'verify_cache_hit'  => '窗口内免重复验票',
		);
		if ( isset( $exact[ $rule_hit ] ) ) {
			return $exact[ $rule_hit ];
		}

		if ( 'driver_halted:quota_exhausted' === $rule_hit ) {
			return '当日额度用完';
		}
		if ( 'driver_halted:config_error' === $rule_hit ) {
			return '配置有问题（服务商拒绝了请求）';
		}
		if ( 0 === strpos( $rule_hit, 'driver_halted:' ) ) {
			return '服务商暂停（' . substr( $rule_hit, strlen( 'driver_halted:' ) ) . '）';
		}
		if ( 0 === strpos( $rule_hit, 'CaptchaCode=' ) ) {
			return '人机验证没通过（返回码 ' . substr( $rule_hit, strlen( 'CaptchaCode=' ) ) . '）';
		}
		if ( 0 === strpos( $rule_hit, 'remote_' ) ) {
			return '服务商出了通讯故障';
		}
		return $rule_hit; // 未收录键原样（不撒谎）。
	}

	/**
	 * meta.raw/脱敏快照 → 服务商原始回复（对照表 18——设置项与展开行共用）。
	 */
	public static function snapshot_word(): string {
		return '服务商原始回复';
	}

	/**
	 * 快照截断字节 → 保存长度上限（字）（对照表 19）。
	 */
	public static function snapshot_limit_word(): string {
		return '保存长度上限（字）';
	}

	/**
	 * 事件流 → 拦截记录流水（对照表 21）。
	 */
	public static function event_stream_word(): string {
		return '拦截记录流水';
	}

	/**
	 * 计数缓存/事件表/对账口径 → 所有统计数字和拦截记录（对照表 22——重置卡人话）。
	 */
	public static function reset_scope_word(): string {
		return '所有统计数字和拦截记录';
	}

	/**
	 * 闸 → 段名（对照表 23——评论防护/登录防护）。
	 *
	 * @param string $gate 闸 id。
	 */
	public static function section_word( string $gate ): string {
		return match ( $gate ) {
			'comment' => '评论防护',
			'login'   => '登录防护',
			default   => $gate,
		};
	}

	/**
	 * 闸 → 门名（对照表 23——闸字退到副标题括号隐喻层，正文用「门」）。
	 *
	 * @param string $gate 闸 id。
	 */
	public static function gate_word( string $gate ): string {
		return match ( $gate ) {
			'login'        => '登录门',
			'register'     => '注册门',
			'lostpassword' => '找回密码门',
			'comment'      => '评论门',
			default        => $gate,
		};
	}

	/**
	 * 累计拦截 → 累计拦下（对照表 24 词头）。
	 */
	public static function cumulative_word(): string {
		return '累计拦下';
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
			$line .= '（自 ' . $since_date . ' 上次清零起）';
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
			'user_threshold' => '同一账号连错太多次',
			'ip_threshold'   => '同一 IP 试了太多次',
			'iprep'          => 'IP 声名狼藉',
			'alarm'          => '站点警报期（近期失败太多）',
			'stale_form'     => '表单停留太久才提交',
			'always'         => '配置为一律要过人机验证',
			'xmlrpc'         => '经 XML-RPC 接口尝试',
			default          => $source,
		};
	}
}
