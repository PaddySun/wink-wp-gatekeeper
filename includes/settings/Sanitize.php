<?php
/**
 * 设置清洗（04 §2 安全规则：按 schema 类型分派；纯 PHP，WP 函数不出现在此层）。
 *
 * - 规则表 rules() 为「点分路径 => 类型」平表，与 SettingsSchema::defaults() 叶子一一对应
 *   （双向一致性由 SanitizeTest 封口——加键漏改规则的故障模式被测试封死）；
 * - 文案键白名单从 Copywork::keys() 派生（单一来源，03 Tab⑤）；
 * - 未知键剥除并收集到 rejected 报告（点分路径列表）；
 * - clean() 返回 [clean, rejected]。
 *
 * @package WinkGatekeeper\Settings
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Settings;

use WinkGatekeeper\Intercept\Copywork;

final class Sanitize {

	/**
	 * 全量规则表：点分路径 => 清洗类型。
	 *
	 * 类型集：absint / int / float / bool / text / password / textarea / url / email / text_list。
	 * review.local.require_chinese 与 use_wp_keywords 沿用上游 0/1 整型语义（absint），不用 bool。
	 *
	 * @return array<string, string>
	 */
	public static function rules(): array {
		$rules = array(
			// 闸开关区（02 §2.7：闸子树形态统一 {enabled, render_mode}）。
			'gates.comment.enabled'                     => 'bool',
			'gates.comment.render_mode'                 => 'text',
			'gates.login.enabled'                       => 'bool',
			'gates.login.render_mode'                   => 'text',
			// C3：XML-RPC 登录一键加固开关（UI 为反选形态 hidden 1 / checkbox 0——勾选=关闭）。
			'gates.login.xmlrpc_login'                  => 'bool',
			// 【v1.4·R3/R4-G】注册/找回密码两闸键（schema v2 整树落位；消费面 R3 批次）。
			'gates.register.enabled'                    => 'bool',
			'gates.register.render_mode'                => 'text',
			'gates.lostpassword.enabled'                => 'bool',
			'gates.lostpassword.render_mode'            => 'text',

			// 验证码。
			'captcha.driver'                            => 'text',
			// M2 反馈2 T3：验证短缓存窗口（分钟，0=关）。
			'captcha.verify_cache_minutes'              => 'absint',
			// 【v1.4·R2/R4-G】信用免验制六键（02 §3.3a；消费面 R2 批次）。
			'captcha.trust.enabled'                     => 'bool',
			'captcha.trust.min_approved'                => 'absint',
			'captcha.trust.ttl_minutes'                 => 'absint',
			'captcha.trust.credit_cache_minutes'        => 'absint',
			'captcha.trust.precheck_hourly_limit'       => 'absint',
			'captcha.trust.precheck_timeout_ms'         => 'absint',
			'captcha.tencent.app_id'                    => 'absint',
			'captcha.tencent.secret_id'                 => 'password', // CAM SecretId（TC3 签名，05 §1.1④）。FIX-α 2026-11-10：password 渲染（不回显）⇒ 须 password 型（空串保留旧值，03 §1 v1.3 敏感字段契约；M1 text 型致 Tab② 二次保存丢 SecretId）。
			'captcha.tencent.secret_key'                => 'password',
			'captcha.tencent.app_secret_key'            => 'password', // body 必填 AppSecretKey（L0 定论 2026-11-09）。
			'captcha.tencent.disaster_pass'             => 'disaster_pass', // allow|block 白名单（04 §2 v1.3）。
			'captcha.aliyun.access_key_id'              => 'text',
			'captcha.aliyun.access_key_secret'          => 'password',
			'captcha.aliyun.scene_id'                   => 'text',
			'captcha.aliyun.prefix'                     => 'text',
			'captcha.hcaptcha.site_key'                 => 'text',
			'captcha.hcaptcha.secret_key'               => 'password',
			'captcha.turnstile.site_key'                => 'text',
			'captcha.turnstile.secret_key'              => 'password',
			'captcha.geetest.captcha_id'                => 'text',
			'captcha.geetest.captcha_key'               => 'password',
			// 【v1.4·R4-G】threshold→user_threshold 迁移；max_user_keys 删除；iprep_gate 新增。
			'captcha.login_adaptive.user_threshold'     => 'absint',
			'captcha.login_adaptive.window_minutes'     => 'absint',
			'captcha.login_adaptive.ip_threshold'       => 'absint',
			'captcha.login_adaptive.iprep_gate'         => 'bool',

			// 本地规则四闸（上游键名迁移）+ 送审拼接开关（F36，04 §2 v1.3）。
			'review.include_author_fields'              => 'bool',
			'review.local.min_length'                   => 'absint',
			'review.local.max_length'                   => 'absint',
			'review.local.require_chinese'              => 'absint',
			'review.local.custom_banned_words'          => 'textarea',
			'review.local.use_wp_keywords'              => 'absint',

			// 云审核。
			'review.cloud.enabled'                      => 'bool',
			'review.cloud.chain'                        => 'text_list',
			'review.cloud.deepseek.api_key'             => 'password',
			'review.cloud.deepseek.base_url'            => 'url',
			'review.cloud.deepseek.model'               => 'text',
			'review.cloud.deepseek.timeout'             => 'absint',
			'review.cloud.deepseek.daily_quota'         => 'absint',
			'review.cloud.deepseek.fail_mode'           => 'text',
			'review.cloud.deepseek.system_prompt'       => 'textarea',
			'review.cloud.deepseek.min_confidence'      => 'float',
			'review.cloud.openai_custom.api_key'        => 'password',
			'review.cloud.openai_custom.base_url'       => 'url',
			'review.cloud.openai_custom.model'          => 'text',
			'review.cloud.openai_custom.timeout'        => 'absint',
			'review.cloud.openai_custom.daily_quota'    => 'absint',
			'review.cloud.openai_custom.fail_mode'      => 'text',
			'review.cloud.openai_custom.system_prompt'  => 'textarea',
			'review.cloud.openai_custom.min_confidence' => 'float',
			'review.cloud.moli.api_key'                 => 'password',
			'review.cloud.moli.base_url'                => 'url',
			'review.cloud.moli.model'                   => 'text',
			'review.cloud.moli.timeout'                 => 'absint',
			'review.cloud.moli.daily_quota'             => 'absint',
			'review.cloud.moli.fail_mode'               => 'text',
			'review.cloud.baidu.api_key'                => 'password',
			'review.cloud.baidu.secret_key'             => 'password',
			'review.cloud.baidu.timeout'                => 'absint',
			'review.cloud.baidu.daily_quota'            => 'absint',
			'review.cloud.baidu.fail_mode'              => 'text',
			'review.cloud.aliyun_green.access_key_id'     => 'text',
			'review.cloud.aliyun_green.access_key_secret' => 'password',
			'review.cloud.aliyun_green.timeout'            => 'absint',
			'review.cloud.aliyun_green.daily_quota'        => 'absint',
			'review.cloud.aliyun_green.fail_mode'           => 'text',
			'review.cloud.tencent_tms.secret_id'       => 'password', // FIX-α 同口径（password 渲染 ⇒ 空串保留），TMS 驱动 C4 实装。
			'review.cloud.tencent_tms.secret_key'      => 'password',
			'review.cloud.tencent_tms.biz_type'        => 'text', // C4：TMS 控制台策略编号（官方可选，不传走默认策略）。
			'review.cloud.tencent_tms.timeout'         => 'absint',
			'review.cloud.tencent_tms.daily_quota'     => 'absint',
			'review.cloud.tencent_tms.fail_mode'       => 'text',
			'review.cloud.tencent_tms.reject_score'    => 'absint', // C4：Block 直拒 Score 阈值（默认 80）。

			// 真实 IP 解析（D11）。
			'network.ip_source'                         => 'text',
			'network.trusted_proxies.cloudflare'        => 'bool',
			'network.trusted_proxies.edgeone'           => 'bool',
			'network.trusted_proxies.custom'            => 'textarea',
			'network.custom_header'                     => 'text',

			// IP 信誉与上报。
			'iprep.enabled'                             => 'bool',
			'iprep.driver'                              => 'text',
			// 【v1.4·R3】负缓存 TTL；【澄清A】超时键（R2/R3 合并裁决=驱动级一键）；fail_mode 作废删除。
			'iprep.negative_cache_minutes'              => 'absint',
			'iprep.abuseipdb.api_key'                   => 'password',
			'iprep.abuseipdb.suspect_score'             => 'absint',
			'iprep.abuseipdb.malicious_score'           => 'absint',
			'iprep.abuseipdb.cache_minutes'            => 'absint',
			'iprep.abuseipdb.daily_quota'               => 'absint',
			'iprep.abuseipdb.timeout'                   => 'absint',
			'iprep.report.enabled'                      => 'bool',
			'iprep.report.triggers.captcha_failed'      => 'bool',
			'iprep.report.triggers.review_reject'       => 'bool',
			'iprep.report.triggers.ip_reject'           => 'bool',
			'iprep.report.cooldown_minutes'             => 'absint',
			'iprep.report.hourly_limit'                 => 'absint',
			'iprep.report.category'                     => 'absint',

			// 拦截留档（F37/D15，04 §2 v1.3）。
			'intercept.archive_spam'                    => 'bool',

			// 统计 / 品牌 / 共存 / 卸载。
			'stats.enabled'                             => 'bool',
			'stats.pass_sampling'                       => 'bool',
			'stats.capacity_notice'                     => 'bool',
			// 【v1.4·R4 §D.4】错误事件 meta.raw 快照开关 + 截断字节（钳 128-2048）。
			'stats.raw_capture'                         => 'bool',
			'stats.raw_max_bytes'                       => 'raw_bytes',
			'brand.contact_email'                       => 'email',
			'coexist.hcaptcha_yield_comment'            => 'bool',
			'coexist.hcaptcha_yield_login'              => 'bool',
			'cleanup_on_uninstall'                      => 'bool',
		);

		// 文案键白名单：从 Copywork::keys() 单一来源派生（03 Tab⑤）。
		foreach ( Copywork::keys() as $key ) {
			$rules[ 'copy.messages.' . $key ] = 'textarea';
			$rules[ 'copy.titles.' . $key ]   = 'text';
		}

		return $rules;
	}

	/**
	 * 清洗整棵输入树：已知键按类型分派，未知键剥除并收集到 rejected（点分路径列表）。
	 *
	 * @param mixed $input 用户提交的设置数组（非数组输入返回空结果）。
	 * @return array{0: array<string, mixed>, 1: string[]} [clean, rejected]
	 */
	public static function clean( $input ): array {
		if ( ! is_array( $input ) ) {
			return array( array(), array() );
		}

		$rejected = array();
		$clean    = self::clean_node( $input, '', $rejected );
		return array( $clean, $rejected );
	}

	/**
	 * 单值清洗（类型分派入口，便于复用与测试）。
	 *
	 * @param mixed  $value 原始值。
	 * @param string $type  规则类型。
	 * @return mixed
	 */
	public static function clean_value( $value, string $type ) {
		switch ( $type ) {
			case 'absint':
				return is_scalar( $value ) ? abs( (int) $value ) : 0;
			case 'int':
				return is_scalar( $value ) ? (int) $value : 0;
			case 'float':
				return is_scalar( $value ) ? (float) $value : 0.0;
			case 'bool':
				return is_scalar( $value ) ? (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN ) : false;
			case 'text':
			case 'url':
				return self::to_text( $value );
			case 'password':
				return self::to_text( $value );
			case 'textarea':
				return self::to_textarea( $value );
			case 'email':
				$email = self::to_text( $value );
				return ( '' !== $email && false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ) ? $email : '';
			case 'disaster_pass':
				// 白名单枚举（04 §2 captcha.tencent.disaster_pass）：非 block 一律归 allow（默认档）。
				return ( is_scalar( $value ) && 'block' === (string) $value ) ? 'block' : 'allow';
			case 'raw_bytes':
				// 【v1.4·R4】raw 截断字节（stats.raw_max_bytes）：absint 后钳 128-2048（04 §3 截断/体量行）。
				return max( 128, min( 2048, is_scalar( $value ) ? abs( (int) $value ) : 512 ) );
			case 'text_list':
				if ( ! is_array( $value ) ) {
					return array();
				}
				$list = array();
				foreach ( $value as $entry ) {
					if ( is_scalar( $entry ) ) {
						$text = self::to_text( $entry );
						if ( '' !== $text ) {
							// C4：空项剔除（chain 复选框 hidden 伴随域形态——未勾选提交 ['']，
							// 勾选提交 ['','tencent_tms']，链表只留有效驱动 id）。
							$list[] = $text;
						}
					}
				}
				return $list;
			default:
				throw new \InvalidArgumentException( '未知清洗类型：' . $type );
		}
	}

	/**
	 * @param array<string, mixed> $node     当前子树。
	 * @param string               $prefix   点分路径前缀。
	 * @param string[]             $rejected 收集剥除报告（引用）。
	 * @return array<string, mixed>
	 */
	private static function clean_node( array $node, string $prefix, array &$rejected ): array {
		$rules = self::rules();
		$clean = array();

		foreach ( $node as $key => $value ) {
			$path = ( '' === $prefix ) ? (string) $key : $prefix . '.' . $key;

			if ( isset( $rules[ $path ] ) ) {
				// 叶子规则：按类型分派。非数组值交给 clean_value 归一（数组→类型默认值）。
				if ( is_array( $value ) && 'text_list' !== $rules[ $path ] ) {
					$clean[ $key ] = self::clean_value( null, $rules[ $path ] );
				} else {
					$clean[ $key ] = self::clean_value( $value, $rules[ $path ] );
				}
				continue;
			}

			if ( is_array( $value ) ) {
				// 中间节点：继续下钻（空数组天然产出空子树）。
				$clean[ $key ] = self::clean_node( $value, $path, $rejected );
				continue;
			}

			// 既无规则又不是可下钻数组：未知键，剥除并报告。
			$rejected[] = $path;
		}

		return $clean;
	}

	/**
	 * 单行文本：去标签、换行折叠为空格、压缩连续空白、去首尾（对应 WP sanitize_text_field 语义的纯 PHP 实现）。
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function to_text( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = strip_tags( (string) $value );
		$text = str_replace( array( "\r", "\n", "\t" ), ' ', $text );
		$text = preg_replace( '/ {2,}/', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * 多行文本：去标签、统一换行符、保留换行（对应 sanitize_textarea_field 语义）。
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function to_textarea( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = strip_tags( (string) $value );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		return trim( $text );
	}
}
