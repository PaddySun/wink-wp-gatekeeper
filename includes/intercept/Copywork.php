<?php
/**
 * 文案工坊单一来源（03 §Tab⑤、04 §2 copy 子树）。
 *
 * keys() 是文案键清单的唯一权威来源——Tab⑤ 表单渲染、SettingsSchema 默认值、
 * Sanitize 文案键白名单全部从其派生（闸/驱动可自注册追加键），消除
 * 「加键漏改白名单 → 保存被静默剥除」的故障模式（v1.2 可扩展性审核 #6）。
 *
 * 默认文案集按 wink 语气三原则撰写（只调侃行为 / 留台阶 / 带下一步），
 * 上游 baby-wp 四闸文案风格迁移。
 *
 * @package WinkGatekeeper\Intercept
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Intercept;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（S2-F1 纵深加固——类文件直访零执行，守卫为纵深面）。
}

final class Copywork {

	/**
	 * 支持的占位符（03 Tab⑤ 占位符体系）：
	 * {min_length}/{max_length} 继承上游；{event_no}/{rule}/{time}/{contact_email} 本项目新增。
	 * 注意：{rule} 前台只替换为规则键名，命中关键词仅进后台事件流（防探测 oracle）。
	 */
	public const PLACEHOLDER_MIN_LENGTH    = 'min_length';
	public const PLACEHOLDER_MAX_LENGTH    = 'max_length';
	public const PLACEHOLDER_EVENT_NO      = 'event_no';
	public const PLACEHOLDER_RULE          = 'rule';
	public const PLACEHOLDER_TIME          = 'time';
	public const PLACEHOLDER_CONTACT_EMAIL = 'contact_email';

	/**
	 * 拦截页预览样例值（【M4UX·G3】Tab⑤「查看效果」按钮——常量防硬编码）：
	 * {event_no}=WK-00000000、{rule}=示例规则；{time}=预览时刻（动态，不设常量）、
	 * {contact_email}=站长当前配置值。
	 */
	public const PREVIEW_EVENT_NO     = 'WK-00000000';
	public const PREVIEW_RULE_SAMPLE  = 'Sample rule'; // i18n 批 I-3：值同步 en（消费位 __() 字面量拍平，zh msgstr=示例规则）。

	/**
	 * 云端 Label 映射键前缀（03 §3【v1.5·F4-3】：映射表落点=Copywork 键值表，键 wk-label-<Label>）。
	 */
	public const LABEL_KEY_PREFIX = 'wk-label-';

	/**
	 * 规则键清单（03 Tab⑤：上游 4 条迁移 + 本项目新增 4 条 + M2 反馈2 T2 核心拒绝 2 条，共十键）。
	 *
	 * @return string[] 规范顺序的键列表。
	 */
	public static function keys(): array {
		return array(
			'too_short',
			'too_long',
			'no_chinese',
			'banned_word',
			'captcha_failed',
			'captcha_missing',
			'ip_malicious',
			'cloud_error',
			'duplicate_comment',
			'comment_flood',
		);
	}

	/**
	 * 支持的占位符名单。
	 *
	 * @return string[]
	 */
	public static function placeholders(): array {
		return array(
			self::PLACEHOLDER_MIN_LENGTH,
			self::PLACEHOLDER_MAX_LENGTH,
			self::PLACEHOLDER_EVENT_NO,
			self::PLACEHOLDER_RULE,
			self::PLACEHOLDER_TIME,
			self::PLACEHOLDER_CONTACT_EMAIL,
		);
	}

	/**
	 * 默认文案集：拦截文案（messages）与页面标题（titles）两组，键集同 keys()。
	 *
	 * @return array{messages: array<string,string>, titles: array<string,string>}
	 */
	public static function defaults(): array {
		return array(
			'messages' => array(
				'too_short'       => __( 'This comment looks unfinished — maybe an accidental early submit. Write {min_length} characters or more (under {max_length}) and try again?', 'wink-wp-gatekeeper' ),
				'too_long'        => __( 'This comment looks like template copy #4,000 — or maybe you got carried away. Keep it between {min_length} and {max_length} characters and try again?', 'wink-wp-gatekeeper' ),
				'no_chinese'      => __( 'This comment looks like template copy #4,000 — or maybe a network hiccup pressed submit for you. Try again?', 'wink-wp-gatekeeper' ),
				'banned_word'     => __( 'We have seen this string before — how about plain human words instead?', 'wink-wp-gatekeeper' ),
				'captcha_failed'  => __( 'The wink did not land — refresh the page and try again? Another browser works too. If all else fails: {contact_email}', 'wink-wp-gatekeeper' ),
				'captcha_missing' => __( 'One wink missing — complete human verification, then hit submit.', 'wink-wp-gatekeeper' ), // 【v1.4·反馈5/R2 §5 改词】原「完成上方的验证」——嵌入式后验证码不一定在「上方」（键名不变）。
				'ip_malicious'    => __( 'This connection looks a bit suspicious. If you are a regular visitor, try another network or come back later; if you think this is a false positive: {contact_email}', 'wink-wp-gatekeeper' ),
				'cloud_error'     => __( 'Your comment has been parked in the pending queue — the site owner will review it shortly, no need to resubmit.', 'wink-wp-gatekeeper' ),
				'duplicate_comment' => __( 'This comment was just submitted — did you double-click by accident? Go back and check whether it is already queued or showing.', 'wink-wp-gatekeeper' ),
				'comment_flood'   => __( 'Faster than a blink — wait a few seconds before posting again.', 'wink-wp-gatekeeper' ),
			),
			'titles'   => array(
				'too_short'       => __( 'This comment is a bit short', 'wink-wp-gatekeeper' ),
				'too_long'        => __( 'This comment is a bit long', 'wink-wp-gatekeeper' ),
				'no_chinese'      => __( 'Missing some Chinese', 'wink-wp-gatekeeper' ),
				'banned_word'     => __( 'We know this string', 'wink-wp-gatekeeper' ),
				'captcha_failed'  => __( 'The wink did not land', 'wink-wp-gatekeeper' ),
				'captcha_missing' => __( 'One wink missing', 'wink-wp-gatekeeper' ),
				'ip_malicious'    => __( 'This connection looks suspicious', 'wink-wp-gatekeeper' ),
				'cloud_error'     => __( 'Parked in pending review', 'wink-wp-gatekeeper' ),
				'duplicate_comment' => __( 'This comment was already posted', 'wink-wp-gatekeeper' ),
				'comment_flood'   => __( 'Faster than a blink', 'wink-wp-gatekeeper' ),
			),
		);
	}

	/**
	 * 站长自定义拦截页文案键默认值（03 Tab⑤【v1.5·WORDFENCE-ADOPTION B1】）。
	 *
	 * 键落位 = copy 子树根（copy.die_page_title / copy.die_page_message），不属规则键集
	 * （keys() 不收——既有键与默认集零改动条款）；空串 = 回落对应场景默认文案。
	 *
	 * @return array{die_page_title: string, die_page_message: string}
	 */
	public static function custom_defaults(): array {
		return array(
			'die_page_title'   => '',
			'die_page_message' => '',
		);
	}

	/**
	 * 云端驱动 Label → 中文映射表（03 §3【v1.5·F4-3】：证据行「命中规则」人类可读化）。
	 *
	 * 键形态 = wk-label-<Label>（LABEL_KEY_PREFIX 前缀，映射关系键名本身不可改——
	 * Tab⑤ 只读预览区展示同一键形态）；文案可经 wink_gatekeeper_error_messages filter
	 * 覆盖（humanize_rule 的 $label_overrides，同键覆盖不扩键）。
	 *
	 * TMS 集为 L0 实测定稿 Label 表（05 §2.4）；阿里集为 05 §2.5 摘录族——B5（M3' 批次 3）
	 * 接入时按摘录表核对补缺；文案仍可经 wink_gatekeeper_error_messages filter 覆盖。
	 *
	 * @return array<string, string> wk-label-<Label> => 中文文案。
	 */
	public static function cloud_labels(): array {
		return array(
			// 腾讯云 TMS 文本审核（实测定稿 Label 集）。
			'wk-label-Abuse'      => __( 'Abusive', 'wink-wp-gatekeeper' ),
			'wk-label-Ad'         => __( 'Advertising', 'wink-wp-gatekeeper' ),
			'wk-label-Porn'       => __( 'Pornographic / vulgar', 'wink-wp-gatekeeper' ),
			'wk-label-Politics'   => __( 'Politics', 'wink-wp-gatekeeper' ),
			'wk-label-Terrorism'  => __( 'Terrorism', 'wink-wp-gatekeeper' ),
			'wk-label-Contraband' => __( 'Contraband', 'wink-wp-gatekeeper' ),
			'wk-label-Sexy'       => __( 'Sexual content', 'wink-wp-gatekeeper' ),
			// 阿里文本审核增强版 PLUS（05 §2.5 摘录族；B5 核对补缺——L0 实测 pt_* 三标签命中）。
			'wk-label-pt_to_sites'              => __( 'Suspected off-site traffic diversion', 'wink-wp-gatekeeper' ),
			'wk-label-pt_to_contact'            => __( 'Suspected ad-spam account', 'wink-wp-gatekeeper' ),
			'wk-label-pt_by_recruitment'        => __( 'Suspected money-making job ad', 'wink-wp-gatekeeper' ),
			'wk-label-ad_compliance'            => __( 'Ad-law violating content', 'wink-wp-gatekeeper' ),
			'wk-label-inappropriate_profanity'  => __( 'Suspected abusive attack', 'wink-wp-gatekeeper' ),
			'wk-label-inappropriate_oral'       => __( 'Suspected vulgar language', 'wink-wp-gatekeeper' ),
			'wk-label-inappropriate_nonsense'   => __( 'Suspected meaningless flooding', 'wink-wp-gatekeeper' ),
			'wk-label-inappropriate_discrimination' => __( 'Suspected bias / discrimination', 'wink-wp-gatekeeper' ),
			'wk-label-inappropriate_ethics'     => __( 'Suspected harmful values', 'wink-wp-gatekeeper' ),
			'wk-label-inappropriate_superstition' => __( 'Suspected superstition', 'wink-wp-gatekeeper' ),
			'wk-label-pornographic_adult'       => __( 'Suspected pornography', 'wink-wp-gatekeeper' ),
			'wk-label-sexual_terms'             => __( 'Suspected sexual-health content', 'wink-wp-gatekeeper' ),
			'wk-label-sexual_suggestive'        => __( 'Suspected vulgarity', 'wink-wp-gatekeeper' ),
			'wk-label-political_figure'         => __( 'Suspected political figure', 'wink-wp-gatekeeper' ),
			'wk-label-political_entity'         => __( 'Suspected political entity', 'wink-wp-gatekeeper' ),
			'wk-label-political_n'              => __( 'Suspected politically sensitive', 'wink-wp-gatekeeper' ),
			'wk-label-political_p'              => __( 'Suspected banned political promotion', 'wink-wp-gatekeeper' ),
			'wk-label-political_a'              => __( 'Suspected special political campaign', 'wink-wp-gatekeeper' ),
			'wk-label-violent_extremist'        => __( 'Suspected extremist group', 'wink-wp-gatekeeper' ),
			'wk-label-violent_incidents'        => __( 'Suspected violent incident', 'wink-wp-gatekeeper' ),
			'wk-label-violent_weapons'          => __( 'Suspected weapons', 'wink-wp-gatekeeper' ),
			'wk-label-contraband_drug'          => __( 'Suspected drugs', 'wink-wp-gatekeeper' ),
			'wk-label-contraband_gambling'      => __( 'Suspected gambling', 'wink-wp-gatekeeper' ),
			'wk-label-contraband_act'           => __( 'Suspected banned behavior', 'wink-wp-gatekeeper' ),
			'wk-label-contraband_entity'        => __( 'Suspected banned tools', 'wink-wp-gatekeeper' ),
			'wk-label-religion_b'               => __( 'Buddhism-related', 'wink-wp-gatekeeper' ),
			'wk-label-religion_t'               => __( 'Taoism-related', 'wink-wp-gatekeeper' ),
			'wk-label-religion_c'               => __( 'Christianity-related', 'wink-wp-gatekeeper' ),
			'wk-label-religion_i'               => __( 'Islam-related', 'wink-wp-gatekeeper' ),
			'wk-label-religion_h'               => __( 'Hinduism-related', 'wink-wp-gatekeeper' ),
			'wk-label-customized'               => __( 'Custom word list hit', 'wink-wp-gatekeeper' ),
			'wk-label-aigc'                     => __( 'Text suspected AI-generated', 'wink-wp-gatekeeper' ),
			'wk-label-ugc'                      => __( 'Not AI-generated', 'wink-wp-gatekeeper' ),
		);
	}

	/**
	 * 驱动人类名前缀（03 §3 v1.5：registry type+label 派生）——
	 * review「云端文本审核（{label}）」/ captcha「人机验证（{label}）」/ ip「IP 信誉（{label}）」。
	 *
	 * @param string $type  DriverRegistry type（captcha|review|ip；未知类型回退裸 label）。
	 * @param string $label registry label（驱动人类名）。
	 * @return string
	 */
	public static function driver_prefix( string $type, string $label ): string {
		switch ( $type ) {
			case 'review':
				return __( 'Cloud text moderation (', 'wink-wp-gatekeeper' ) . $label . __( ')', 'wink-wp-gatekeeper' );
			case 'captcha':
				return __( 'Human verification (', 'wink-wp-gatekeeper' ) . $label . __( ')', 'wink-wp-gatekeeper' );
			case 'ip':
				return __( 'IP reputation (', 'wink-wp-gatekeeper' ) . $label . __( ')', 'wink-wp-gatekeeper' );
		}
		return $label;
	}

	/**
	 * 证据行 rule_hit 人类可读化（纯函数；03 §3【v1.5·F4-3】）。
	 *
	 * 只对云端驱动 `Label,Score` 形态（如 `Abuse,100`）做映射：
	 *   {驱动人类名前缀}：{标签中文} · 置信 {Score}
	 * 兜底（03 §3 边界条款，不放宽）：
	 *   - 未收录标签原样显示（宁可英文不可错误中文；nonLabel 伪标签不映射整条直通）；
	 *   - 非 Label,Score 形态（LocalRules 键 banned_word / 核心拒绝键 duplicate_comment /
	 *     remote_* / unknown_suggestion:* 等）一律原样返回。
	 *
	 * @param string                $prefix          驱动人类名前缀（driver_prefix 产物）。
	 * @param string                $reason          verdict reason（rule_hit 原文）。
	 * @param array<string, string> $label_overrides filter 覆盖（键 wk-label-<Label> => 中文）。
	 * @return string
	 */
	public static function humanize_rule( string $prefix, string $reason, array $label_overrides = array() ): string {
		if ( '' === $reason || ! preg_match( '/^([A-Za-z0-9_]+),(\d{1,3})$/', $reason, $m ) ) {
			return $reason;
		}
		$label = $m[1];
		if ( 'nonLabel' === $label ) {
			return $reason; // 伪标签（未检测出风险）不映射。
		}
		$labels = array_merge( self::cloud_labels(), $label_overrides );
		$key    = self::LABEL_KEY_PREFIX . $label;
		$zh     = isset( $labels[ $key ] ) && is_string( $labels[ $key ] ) && '' !== $labels[ $key ]
			? $labels[ $key ]
			: $label; // 未收录标签原样兜底。
		return $prefix . __( ':', 'wink-wp-gatekeeper' ) . $zh . __( ' · confidence ', 'wink-wp-gatekeeper' ) . $m[2];
	}

	/**
	 * 占位符替换：只替换 $vars 中实际提供的占位符；缺失的占位符保留原文，
	 * 非标量变量值不参与替换。
	 *
	 * @param string                $text 含占位符的文案。
	 * @param array<string, mixed>  $vars 占位符名 => 标量替换值。
	 * @return string
	 */
	public static function format( string $text, array $vars ): string {
		$pairs = array();
		foreach ( $vars as $name => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$pairs[ '{' . $name . '}' ] = (string) $value;
		}
		if ( array() === $pairs ) {
			return $text;
		}
		return strtr( $text, $pairs );
	}
}
