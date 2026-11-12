<?php
/**
 * 上报可选能力接口（M3-IP 定稿 §4.1——【v1.7·M3-IP 裁决】签名以本文件为准，
 * 02 §2.3a 旧三参草案 report(ip, category, comment) 作废）。
 *
 * 消费形态=instanceof 能力探测（O2-P2-7 范式）：触发点（CommentGate/Precheck/LoginGate）
 * 对已持有的 IpReputationProbe 做能力判定，兼实现本接口方可上报——Null 服务/未装配
 * =零上报面零行为变化（闸构造器零改动）。
 *
 * fire-and-forget 语义：调用方不等待业务结果、失败不影响拦截流程；返回约定——
 *   true  = 已受理（**含冷却去重命中视为成功免重报**）；
 *   false = 未受理（开关关/触发面关/白名单/自检未过/冷却外闸丢弃/发送失败——静默）。
 *
 * 发送失败静默（事件 meta 记 error 分类），丢报无损（每 IP 冷却 60min 后下个触发点
 * 自然重试——重试语义由服务面闸承载，调用方零重试）。
 *
 * @package WinkGatekeeper\Ip
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Ip;

interface SupportsReportingInterface {

	/**
	 * 上报一次恶意行为（fire-and-forget；冷却去重命中视为成功免重报）。
	 *
	 * @param string               $ip      访客 IP（IpResolver 解析产物；服务内归一定键）。
	 * @param array<string, mixed> $context 触发上下文：trigger（captcha_failed|review_reject|
	 *                                      login_attempts|local_rules_fail）/gate（comment|login）/
	 *                                      event_no（触发事件编号——{event_no} 占位符渲染所需）；
	 *                                      其余键服务面忽略（负载键白名单自证零泄漏）。
	 * @return bool true=已受理（含冷却去重命中视为成功免重报）。
	 */
	public function report_ip( string $ip, array $context ): bool;
}
