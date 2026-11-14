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
	public const PREVIEW_RULE_SAMPLE  = '示例规则';

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
				'too_short'       => '这条评论看起来像还没写完。也可能是手滑提前按了提交——写到 {min_length} 字（{max_length} 字以内）再试试？',
				'too_long'        => '这条评论看起来像模板群发的第 4,000 份。也可能是话痨附体——控制在 {min_length}–{max_length} 字之间再试试？',
				'no_chinese'      => '这条评论看起来像模板群发的第 4,000 份。也可能是网络抽风替你按了提交——再试一次？',
				'banned_word'     => '这串字符我们眼熟——换句人话再试试？',
				'captcha_failed'  => '眨眼没对上——刷新页面再试一次？换个浏览器也行。实在不行：{contact_email}',
				'captcha_missing' => '还差一个眨眼——完成人机验证再点提交。', // 【v1.4·反馈5/R2 §5 改词】原「完成上方的验证」——嵌入式后验证码不一定在「上方」（键名不变）。
				'ip_malicious'    => '这次连接的来路有点可疑。如果你是正常访客，换个网络或稍后再来；认为是误判：{contact_email}',
				'cloud_error'     => '这条评论先替你收进待审区了——站长稍后会看，不用重发。',
				'duplicate_comment' => '这条评论刚刚已经发过一遍了——是手滑连点了两下？返回看看它是否已经在排队/显示。',
				'comment_flood'   => '手速比眨眼还快——稍等几秒再发。',
			),
			'titles'   => array(
				'too_short'       => '这条评论有点短',
				'too_long'        => '这条评论有点长',
				'no_chinese'      => '缺了点中文味儿',
				'banned_word'     => '这串字符我们眼熟',
				'captcha_failed'  => '眨眼没对上',
				'captcha_missing' => '还差一个眨眼',
				'ip_malicious'    => '这次连接的来路有点可疑',
				'cloud_error'     => '已收进待审区',
				'duplicate_comment' => '这条评论已经发过了',
				'comment_flood'   => '手速比眨眼还快',
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
			'wk-label-Abuse'      => '辱骂违规',
			'wk-label-Ad'         => '广告',
			'wk-label-Porn'       => '色情低俗',
			'wk-label-Politics'   => '涉政',
			'wk-label-Terrorism'  => '暴恐',
			'wk-label-Contraband' => '违禁',
			'wk-label-Sexy'       => '性感的',
			// 阿里文本审核增强版 PLUS（05 §2.5 摘录族；B5 核对补缺——L0 实测 pt_* 三标签命中）。
			'wk-label-pt_to_sites'              => '疑似站外引流',
			'wk-label-pt_to_contact'            => '疑似引流广告号',
			'wk-label-pt_by_recruitment'        => '疑似网赚兼职广告',
			'wk-label-ad_compliance'            => '违反广告法内容',
			'wk-label-inappropriate_profanity'  => '疑似攻击辱骂',
			'wk-label-inappropriate_oral'       => '疑似低俗口头语',
			'wk-label-inappropriate_nonsense'   => '疑似无意义灌水',
			'wk-label-inappropriate_discrimination' => '疑似偏见歧视',
			'wk-label-inappropriate_ethics'     => '疑似不良价值观',
			'wk-label-inappropriate_superstition' => '疑似封建迷信',
			'wk-label-pornographic_adult'       => '疑似色情',
			'wk-label-sexual_terms'             => '疑似性健康',
			'wk-label-sexual_suggestive'        => '疑似低俗',
			'wk-label-political_figure'         => '疑似政治人物',
			'wk-label-political_entity'         => '疑似政治实体',
			'wk-label-political_n'              => '疑似政治敏感',
			'wk-label-political_p'              => '疑似政治禁宣',
			'wk-label-political_a'              => '疑似政治专项',
			'wk-label-violent_extremist'        => '疑似极端组织',
			'wk-label-violent_incidents'        => '疑似暴力事件',
			'wk-label-violent_weapons'          => '疑似武器弹药',
			'wk-label-contraband_drug'          => '疑似毒品',
			'wk-label-contraband_gambling'      => '疑似赌博',
			'wk-label-contraband_act'           => '疑似违禁行为',
			'wk-label-contraband_entity'        => '疑似违禁工具',
			'wk-label-religion_b'               => '涉佛教',
			'wk-label-religion_t'               => '涉道教',
			'wk-label-religion_c'               => '涉基督',
			'wk-label-religion_i'               => '涉伊斯兰',
			'wk-label-religion_h'               => '涉印度教',
			'wk-label-customized'               => '命中自定义词库',
			'wk-label-aigc'                     => '文本疑似 AI 生成',
			'wk-label-ugc'                      => '非 AI 生成',
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
				return '云端文本审核（' . $label . '）';
			case 'captcha':
				return '人机验证（' . $label . '）';
			case 'ip':
				return 'IP 信誉（' . $label . '）';
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
		return $prefix . '：' . $zh . ' · 置信 ' . $m[2];
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
