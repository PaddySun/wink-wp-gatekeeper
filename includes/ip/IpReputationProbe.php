<?php
/**
 * IP 信誉探测接口（02 §2.3b/05 §3.0——【v1.4·R2/R3，M2.5 占位】三态语义载体，非驱动）。
 *
 * 权威三态定义（澄清 A 2026-11-10 拍板，02 §2.3b 规范性判定表）：
 *   null  = skip（未启用）——iprep.enabled=false / 登录侧 iprep_gate=false / M2.5 未装配
 *           （Null 服务恒 null）。「未启用=跳过判定项」，不影响免验资格与登录计数。
 *   pass  = 启用且驱动已配置且查询正常且 score < suspect（默认 60）。
 *   fail  = ①启用但驱动未配置/密钥不全（防呆）；②查询超时（iprep.abuseipdb.timeout 默认 1s
 *           无重试档）；③报错（四类终态/当日停转）；④score ≥ suspect——Verdict
 *           review/reject/error 形态。处置=强制验证码（评论闸过码放行/登录闸出码——定死无开关）。
 *
 * 消费方三处（02 §2.3b）：评论点击期预检端点（03 §2.1）、评论提交期 ip 阶段（02 §3.1 步骤 2）、
 * 登录闸 LoginIpRep（02 §3.2，R3 批次）——M3 的 AbuseIPDB 驱动接入同一接口后自动生效，消费方零改动。
 *
 * 缓存约定（02 §2.3b）：结果 transient 键 wk_ip_<driver>_<ip_hash>——只写于外呼之后（提交期/
 * 登录验证期外呼回填）；预检（点击期）与登录渲染期只读、不产生（点击期零外呼硬约束：已启用且
 * 缓存未命中时预检直接判 fail→出码，不发实时调用）；pass TTL=cache_minutes（1440=24h，
 * 【v1.7·M3-IP】默认平移）、fail TTL=negative_cache_minutes（60=1h——短负缓存，配置修复/
 * 信誉恢复后自愈）。停转态（配置错误/当日停转）在服务内部返回 reject 语义（防呆从严——
 * 「开而不配好」可见、可自愈）。
 *
 * 【v1.7·M3-IP·定稿 §2 O5-P1-1】$ctx['cache_only']=true 为只读模式（预检/登录渲染期**必传**
 * ——零外呼；未命中∨退避中∨停转中→返 fail Verdict reason=cache_miss_strict/backoff/halted，
 * 不返 null——澄清 A「已启用但无证据=不通过」的精确落点；消费方 fail 路径零改动承接）。
 * 完整模式（缺省）：七步流水线照旧（白名单→规范化→退避→停转→软顶→缓存→外呼映射→落账）。
 * 缓存内化实现（ AbuseIpdbService ），本接口不承载缓存职能。
 *
 * @package WinkGatekeeper\Ip
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Ip;

use WinkGatekeeper\Verdict;

interface IpReputationProbe {

	/**
	 * 探测一个 IP 的信誉判定。
	 *
	 * @param string                $ip  已归一化的访客 IP（IpResolver 解析产物）。
	 * @param array<string, mixed>  $ctx 上下文（【v1.7·M3-IP】cache_only=true 只读模式——
	 *                                   预检/登录渲染期必传，零外呼；参可省=完整模式，向后兼容）。
	 * @return Verdict|null null=未启用（skip）；pass=通过；review/reject/error=fail 档
	 *                      （消费方一律按「强制验证码」处置——02 §2.3b 三态表）。
	 */
	public function probe( string $ip, array $ctx = array() ): ?Verdict;
}
