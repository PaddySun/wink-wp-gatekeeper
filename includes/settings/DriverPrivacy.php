<?php
/**
 * 逐驱动合规内容表（05 §4 v1.4·R4 §C.2 收编；F43）——privacy 维的现值快照。
 *
 * 单一内容来源：registry privacy 第七维的内嵌值经 entry() 引用本表（禁双源漂移）；
 * Tab②/Tab③/Tab④ 配置区合规行、链路汇总行与导出只读 registry / 本表，
 * **禁止在 SettingsPage 硬编码合规文案**（现状两处硬编码已迁走，R4 §C.3）。
 *
 * 置信标注（05 §4 表「置信」列）：腾讯系两驱动=实测定稿/手档+L0；M3/M4 驱动=公开认知/
 * 未验证（注册时随 milestone 逐家补核 privacy_url——本表 URL 空串占位即「待核」）。
 *
 * 纯 PHP 零 WP 依赖（单测覆盖）。
 *
 * @package WinkGatekeeper\Settings
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（S2-F1 纵深加固——类文件直访零执行，守卫为纵深面）。
}

final class DriverPrivacy {

	/**
	 * 腾讯云隐私条款（境内系两驱动共用——05 §4 表 privacy_url 列）。
	 */
	private const TENCENT_PRIVACY_URL = 'https://cloud.tencent.com/document/product/301/1967';

	/**
	 * 内容表：驱动 id => privacy 维（cross_border/privacy_url/data_fields/region_note）。
	 *
	 * 键集 = 05 §4 逐驱动合规内容表全量（12 驱动；local_rules 本地无第三方外送不入表）。
	 * 送审字段与 review.include_author_fields 联动的动态部分（关=仅正文）由消费方渲染时注记。
	 *
	 * @return array<string, array{cross_border: bool|null, privacy_url: string, data_fields: string, region_note: string}>
	 */
	public static function table(): array {
		return array(
			'tencent_tms' => array(
				'cross_border' => false,
				'privacy_url'  => self::TENCENT_PRIVACY_URL,
				'data_fields'  => __( 'Nickname, email, comment body (concatenated string; body only when “nickname/email concatenation” is off)', 'wink-wp-gatekeeper' ),
				'region_note'  => __( 'Domestic node ap-guangzhou; data stays in China', 'wink-wp-gatekeeper' ),
			),
			'deepseek'    => array(
				'cross_border' => false,
				'privacy_url'  => '', // M3 抓取 platform.deepseek.com 隐私政策后补 URL（05 §4 置信列）。
				'data_fields'  => __( 'Nickname, email, comment body (concatenated string; body only when concatenation is off)', 'wink-wp-gatekeeper' ),
				'region_note'  => __( 'Domestic provider', 'wink-wp-gatekeeper' ),
			),
			'openai_custom' => array(
				'cross_border' => null, // 端点所在司法辖区未知。
				'privacy_url'  => '',   // 站长自负。
				'data_fields'  => __( 'Nickname, email, comment body (concatenated string; body only when concatenation is off)', 'wink-wp-gatekeeper' ),
				'region_note'  => __( 'The jurisdiction of the endpoint is unknown; assess cross-border compliance yourself (v1.2 generic notice migrated verbatim)', 'wink-wp-gatekeeper' ),
			),
			'moli'        => array(
				'cross_border' => false,
				'privacy_url'  => '', // M4 逐家抓取。
				'data_fields'  => __( 'Nickname, email, comment body (concatenated string; body only when concatenation is off)', 'wink-wp-gatekeeper' ),
				'region_note'  => __( 'Domestic provider', 'wink-wp-gatekeeper' ),
			),
			'baidu'       => array(
				'cross_border' => false,
				'privacy_url'  => '', // M4 逐家抓取。
				// 【v1.5·B6】分字段送审（昵称+正文两行）——**邮箱不送审**（真机实锤：任何邮箱
				// 地址本身判 36-广告 360104 误杀，IMPL-B6 偏差 1——阿里 pt_to_contact 同类；
				// 邮箱防线在 LocalRules 拼接串）。设计原「拼接串含邮箱」口径作废。
				'data_fields'  => __( 'Nickname, comment body (per-field submission; email is not submitted)', 'wink-wp-gatekeeper' ),
				'region_note'  => __( 'Domestic node aip.baidubce.com; data stays in China', 'wink-wp-gatekeeper' ),
			),
			'aliyun_green' => array(
				'cross_border' => false,
				'privacy_url'  => '', // M4 逐家抓取。
				// 【v1.5·B5】05 §2.5 registry 建议行口径：双 Service 分送（昵称/正文两调）。
				'data_fields'  => __( 'Nickname, email, comment body (per-channel submission)', 'wink-wp-gatekeeper' ),
				'region_note'  => __( 'Domestic node green-cip.cn-shanghai; data stays in China', 'wink-wp-gatekeeper' ),
			),
			'tencent'     => array(
				'cross_border' => false,
				'privacy_url'  => self::TENCENT_PRIVACY_URL,
				'data_fields'  => __( 'Visitor IP, ticket Ticket/Randstr (DescribeCaptchaResult UserIp parameter)', 'wink-wp-gatekeeper' ),
				'region_note'  => __( 'Domestic; data stays in China', 'wink-wp-gatekeeper' ),
			),
			'aliyun'      => array(
				'cross_border' => false,
				'privacy_url'  => '', // M4 逐家抓取。
				'data_fields'  => __( 'Visitor IP (behavior collection via the Aliyun client SDK), CaptchaVerifyParam', 'wink-wp-gatekeeper' ), // 【v1.5·B3】05 §1.2 registry 行口径。
				'region_note'  => __( 'Domestic; data stays in China', 'wink-wp-gatekeeper' ),
			),
			'geetest'     => array(
				'cross_border' => false,
				'privacy_url'  => '', // M4 逐家抓取。
				'data_fields'  => __( 'Visitor IP, six verification parameters', 'wink-wp-gatekeeper' ),
				'region_note'  => __( 'Domestic; data stays in China', 'wink-wp-gatekeeper' ),
			),
			'hcaptcha'    => array(
				'cross_border' => true,
				'privacy_url'  => 'https://www.hcaptcha.com/privacy-policy',
				'data_fields'  => __( 'Visitor IP, verification token', 'wink-wp-gatekeeper' ),
				'region_note'  => __( 'Overseas service — data leaves the country', 'wink-wp-gatekeeper' ),
			),
			'turnstile'   => array(
				'cross_border' => true,
				'privacy_url'  => 'https://www.cloudflare.com/privacypolicy/',
				'data_fields'  => __( 'Visitor IP, verification token', 'wink-wp-gatekeeper' ),
				'region_note'  => __( 'Overseas service — data leaves the country', 'wink-wp-gatekeeper' ),
			),
			'abuseipdb'   => array(
				'cross_border' => true,
				// 【v1.7·M3-IP 定稿 §12-5】隐私政策 URL 已在线核对（2026-11-12：
				// https://www.abuseipdb.com/privacy 返回官方「Privacy Policy for AbuseIPDB」
				// 页面——在线抓取实证；裸 curl UA 被 Cloudflare 防护拦 403 属站点侧防护，
				// URL 本身无误）：「M3 核对」待核标注清除。
				'privacy_url'  => 'https://www.abuseipdb.com/privacy',
				// 05 §4 v1.7 备注口径：硬前缀 [wink-wp-gatekeeper] + 每触发面英文攻击形态
				// 短句——不含站点域名/事件编号/规则词表/PII（原「站点域名+事件编号」口径作废）。
				'data_fields'  => __( 'Visitor IP (both directions: check lookups and report submissions); report notes = tool prefix + short English attack-form phrases (no site domain, event numbers, rule word lists, or PII)', 'wink-wp-gatekeeper' ),
				'region_note'  => __( 'Overseas reputation database — data leaves the country', 'wink-wp-gatekeeper' ),
			),
		);
	}

	/**
	 * 单驱动 privacy 行（未入表驱动=null——本地驱动/未知 id 无合规行）。
	 *
	 * @param string $driver_id 驱动 id。
	 * @return array{cross_border: bool|null, privacy_url: string, data_fields: string, region_note: string}|null
	 */
	public static function entry( string $driver_id ): ?array {
		$table = self::table();
		return $table[ $driver_id ] ?? null;
	}
}
