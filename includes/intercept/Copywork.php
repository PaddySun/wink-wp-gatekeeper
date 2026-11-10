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
