<?php
/**
 * 登录闸 IP 信誉三态判定纯映射类（02 §3.2 IP 信誉三态联动；语义权威定义 02 §2.3b——
 * 澄清 A 2026-11-10 拍板；R3/T2 建，【v1.7·M3-IP·定稿 §2】I2 缓存归一后退化纯映射）。
 *
 * state(ip) ∈ { 'skip', 'pass', 'fail' }：
 *   skip —— iprep_gate=false（站长关闭联动=纯计数，现行行为完全恢复）；或 probe 未装配
 *           （Null 服务恒 null=恒 skip）；或空 IP（IpResolver unresolved——
 *           宁不判定不误伤，与计数器同口径）。「未启用=跳过判定项」（澄清 A：不是恒出码）。
 *   pass —— probe 判定 pass（启用且驱动已配置且查询正常且 score < suspect）→ 按计数规则。
 *   fail —— probe 判定 review/reject/error（疑似 60–84 与恶意 ≥85 同档出码——登录不存在
 *           「入待审」；配置错/超时/报错/当日停转/软顶/防呆同档）→ 渲染期出码 + 验证期强制验票。
 *
 * 【v1.7·M3-IP·定稿 §2 缓存归一】缓存职能已内化 AbuseIpdbService（wk_ip_ 键读写与回填）：
 *   本类删除自带 get/set 回填闭包的缓存职能（消灭「服务缓存+登录域缓存」双写双读），保留
 *   映射与事件 meta 职能。渲染路径（render_orchestrate）与提交路径（verify_orchestrate/
 *   XML-RPC）的区分由 $cache_only 承载：渲染期传 true → probe 收到 cache_only=true 上下文
 *   （零外呼只读；未命中从严 fail——消费方既有 fail 出码路径零改动承接）；提交期缺省完整
 *   模式（登录提交期=唯二完整模式外呼点之二）。
 *
 * 分层：probe 注入（缓存/外呼全在服务内）——纯逻辑零 WP 依赖（生产装配在 Bootstrap）。
 * 构造器保留 get/set/hmac 形参位（退役占位——装配位形态稳定，闭包不再被调用；测试注入
 * spy 断言「零缓存读写」=无双写实证）。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

use WinkGatekeeper\Ip\IpReputationProbe;
use WinkGatekeeper\Verdict;

final class LoginIpRep {

	/**
	 * 缓存键前缀常量（02 §2.3b：wk_ip_<driver>_<ip_hash>——归一后由 AbuseIpdbService
	 * 持有同形常量；本常量保留为键形契约注记，读写已不在本类）。
	 */
	public const CACHE_PREFIX = 'wk_ip_';

	/**
	 * @var bool iprep_gate（captcha.login_adaptive.iprep_gate，默认 true——关闭=纯计数）。
	 */
	private $gate_enabled;

	/**
	 * @var IpReputationProbe 探测服务（缓存/外呼内化；Null 实现=恒 skip=现行行为）。
	 */
	private $probe;

	/**
	 * @param array<string, mixed> $settings 完整设置树（读 captcha.login_adaptive.iprep_gate）。
	 * @param IpReputationProbe    $probe    探测服务（生产=IpReputationService::assemble()）。
	 * @param callable|null        $get      【退役占位】缓存读闭包（归一后不再被调用——
	 *                                       保留形参位防装配漂移；注入 spy 可断言零调用）。
	 * @param callable|null        $set      【退役占位】缓存写闭包（同上——无双写实证位）。
	 * @param callable|null        $hmac     【退役占位】定键 HMAC（同上——键构造已随缓存
	 *                                       职能内化服务；保留位与 Bootstrap 注入位对齐）。
	 */
	public function __construct(
		array $settings,
		IpReputationProbe $probe,
		?callable $get = null,
		?callable $set = null,
		?callable $hmac = null
	) {
		$adaptive = isset( $settings['captcha']['login_adaptive'] ) && is_array( $settings['captcha']['login_adaptive'] )
			? $settings['captcha']['login_adaptive']
			: array();

		$this->gate_enabled = ! array_key_exists( 'iprep_gate', $adaptive ) || (bool) $adaptive['iprep_gate'];
		$this->probe        = $probe;
		// 【v1.7·M3-IP】$get/$set/$hmac 退役占位——缓存职能内化 AbuseIpdbService（定稿 §2），
		// 本类零缓存读写（无双写双读）；形参保留以维持 Bootstrap 装配位与既有调用形态稳定。
		unset( $get, $set, $hmac );
	}

	/**
	 * 三态判定（渲染期与验证期共用；判定次序固定本地在前——调用方先查 flag/alarm）。
	 *
	 * 【v1.7·M3-IP·定稿 §3.4】$cache_only=true 用于渲染路径（render_orchestrate）：
	 * probe 收到 cache_only=true 上下文（零外呼只读，未命中从严 fail）；提交路径
	 * （verify_orchestrate/XML-RPC）缺省完整模式（登录提交期=唯二完整模式外呼点之二）。
	 *
	 * @param string $ip         已归一化的访客 IP（IpResolver 解析产物）。
	 * @param bool   $cache_only true=渲染期只读模式（零外呼）。
	 * @return string 'skip' | 'pass' | 'fail'
	 */
	public function state( string $ip, bool $cache_only = false ): string {
		if ( '' === $ip || ! $this->gate_enabled ) {
			return 'skip'; // 空 IP / 站长关闭联动（02 §2.3b skip 行）——零 probe。
		}

		$verdict = $this->probe->probe( $ip, $cache_only ? array( 'cache_only' => true ) : array() );
		if ( null === $verdict ) {
			return 'skip'; // 未启用（Null 服务恒 null——现行行为；零缓存读写）。
		}

		// review/reject/error → fail 同档（登录不存在「入待审」——02 §2.3b fail ④）。
		return ( Verdict::PASS === $verdict->verdict() ) ? 'pass' : 'fail';
	}
}
