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
				'data_fields'  => '昵称、邮箱、评论正文（拼接串；关闭「昵称/邮箱拼接送审」时仅正文）',
				'region_note'  => '境内节点 ap-guangzhou，数据不出境',
			),
			'deepseek'    => array(
				'cross_border' => false,
				'privacy_url'  => '', // M3 抓取 platform.deepseek.com 隐私政策后补 URL（05 §4 置信列）。
				'data_fields'  => '昵称、邮箱、评论正文（拼接串；关闭拼接时仅正文）',
				'region_note'  => '境内服务商',
			),
			'openai_custom' => array(
				'cross_border' => null, // 端点所在司法辖区未知。
				'privacy_url'  => '',   // 站长自负。
				'data_fields'  => '昵称、邮箱、评论正文（拼接串；关闭拼接时仅正文）',
				'region_note'  => '端点所在司法辖区未知，请自行评估数据出境合规（v1.2 通用出境提示原文迁入）',
			),
			'moli'        => array(
				'cross_border' => false,
				'privacy_url'  => '', // M4 逐家抓取。
				'data_fields'  => '昵称、邮箱、评论正文（拼接串；关闭拼接时仅正文）',
				'region_note'  => '境内服务商',
			),
			'baidu'       => array(
				'cross_border' => false,
				'privacy_url'  => '', // M4 逐家抓取。
				// 【v1.5·B6】分字段送审（昵称+正文两行）——**邮箱不送审**（真机实锤：任何邮箱
				// 地址本身判 36-广告 360104 误杀，IMPL-B6 偏差 1——阿里 pt_to_contact 同类；
				// 邮箱防线在 LocalRules 拼接串）。设计原「拼接串含邮箱」口径作废。
				'data_fields'  => '昵称、评论正文（分字段送审；邮箱不送审）',
				'region_note'  => '境内节点 aip.baidubce.com，数据不出境',
			),
			'aliyun_green' => array(
				'cross_border' => false,
				'privacy_url'  => '', // M4 逐家抓取。
				// 【v1.5·B5】05 §2.5 registry 建议行口径：双 Service 分送（昵称/正文两调）。
				'data_fields'  => '昵称、邮箱、评论正文（分通道送审）',
				'region_note'  => '境内节点 green-cip.cn-shanghai，数据不出境',
			),
			'tencent'     => array(
				'cross_border' => false,
				'privacy_url'  => self::TENCENT_PRIVACY_URL,
				'data_fields'  => '访客 IP、票据 Ticket/Randstr（DescribeCaptchaResult UserIp 参数）',
				'region_note'  => '境内，数据不出境',
			),
			'aliyun'      => array(
				'cross_border' => false,
				'privacy_url'  => '', // M4 逐家抓取。
				'data_fields'  => '访客 IP（行为采集，经阿里客户端 SDK）、CaptchaVerifyParam', // 【v1.5·B3】05 §1.2 registry 行口径。
				'region_note'  => '境内，数据不出境',
			),
			'geetest'     => array(
				'cross_border' => false,
				'privacy_url'  => '', // M4 逐家抓取。
				'data_fields'  => '访客 IP、验证六参数',
				'region_note'  => '境内，数据不出境',
			),
			'hcaptcha'    => array(
				'cross_border' => true,
				'privacy_url'  => 'https://www.hcaptcha.com/privacy-policy',
				'data_fields'  => '访客 IP、验证 token',
				'region_note'  => '境外服务——数据出境',
			),
			'turnstile'   => array(
				'cross_border' => true,
				'privacy_url'  => 'https://www.cloudflare.com/privacypolicy/',
				'data_fields'  => '访客 IP、验证 token',
				'region_note'  => '境外服务——数据出境',
			),
			'abuseipdb'   => array(
				'cross_border' => true,
				'privacy_url'  => 'https://www.abuseipdb.com/privacy', // M3 核对（05 §4 置信列）。
				'data_fields'  => '访客 IP（check 查询与 report 上报两方向）；上报备注仅站点域名+事件编号（无 PII）',
				'region_note'  => '境外信誉库——数据出境',
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
