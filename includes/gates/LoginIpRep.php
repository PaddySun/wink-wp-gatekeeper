<?php
/**
 * 登录闸 IP 信誉三态判定纯编排类（02 §3.2 IP 信誉三态联动；语义权威定义 02 §2.3b——
 * 澄清 A 2026-11-10 拍板；R3/T2）。
 *
 * state(ip) ∈ { 'skip', 'pass', 'fail' }：
 *   skip —— iprep_gate=false（站长关闭联动=纯计数，现行行为完全恢复）；或 probe 未装配
 *           （M2.5 默认=Null 服务恒 null=恒 skip）；或空 IP（IpResolver unresolved——
 *           宁不判定不误伤，与计数器同口径）。「未启用=跳过判定项」（澄清 A：不是恒出码）。
 *   pass —— probe 判定 pass（启用且驱动已配置且查询正常且 score < suspect）→ 按计数规则。
 *   fail —— probe 判定 review/reject/error（疑似 60–84 与恶意 ≥85 同档出码——登录不存在
 *           「入待审」；配置错/超时/报错/当日停转同档）→ 渲染期出码 + 验证期强制验票。
 *
 * 缓存（02 §2.3b 约定，与评论域共享键形 wk_ip_<driver>_<ip_hash>）：命中免 probe；
 * 未命中 probe 后回填——pass TTL=iprep.abuseipdb.cache_minutes（60）、fail 负缓存
 * TTL=iprep.negative_cache_minutes（10——短负缓存防故障/攻击期每次渲染都打远端，配置
 * 修复或信誉恢复后 10min 自愈）。损坏缓存值按 fail（fail-safe 从严）。skip 路径零
 * 缓存读写（Null 服务不产生任何 I/O）。
 *
 * 分层：probe/缓存/HMAC 全注入——纯逻辑零 WP 依赖（生产装配在 Bootstrap：probe=
 * IpReputationService::assemble()（M2.5=Null）、缓存=transient 闭包、HMAC=wp_salt('auth')）。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

use WinkGatekeeper\Ip\IpReputationProbe;
use WinkGatekeeper\Verdict;

final class LoginIpRep {

	/** 缓存键前缀（02 §2.3b：wk_ip_<driver>_<ip_hash>，与评论域共享约定）。 */
	public const CACHE_PREFIX = 'wk_ip_';

	/**
	 * @var bool iprep_gate（captcha.login_adaptive.iprep_gate，默认 true——关闭=纯计数）。
	 */
	private $gate_enabled;

	/**
	 * @var string 驱动 id（iprep.driver，默认 abuseipdb——缓存键成分）。
	 */
	private $driver_id;

	/**
	 * @var int pass 缓存 TTL（秒）。
	 */
	private $pass_ttl;

	/**
	 * @var int fail 负缓存 TTL（秒）。
	 */
	private $fail_ttl;

	/**
	 * @var IpReputationProbe 探测服务（Null 实现=恒 skip=现行行为）。
	 */
	private $probe;

	/**
	 * @var callable fn(string): mixed 缓存读。
	 */
	private $get;

	/**
	 * @var callable fn(string, mixed, int): void 缓存写。
	 */
	private $set;

	/**
	 * @var callable fn(string): string 定键 HMAC（16 hex）。
	 */
	private $hmac;

	/**
	 * @param array<string, mixed>  $settings 完整设置树（读 captcha.login_adaptive.iprep_gate
	 *                                        与 iprep 子树 TTL/驱动键）。
	 * @param IpReputationProbe     $probe    探测服务（生产=IpReputationService::assemble()）。
	 * @param callable|null         $get      缓存读（null = WP transient 边界闭包）。
	 * @param callable|null         $set      缓存写。
	 * @param callable|null         $hmac     定键 HMAC（null = wp_salt('auth') 派生）。
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
		$iprep    = isset( $settings['iprep'] ) && is_array( $settings['iprep'] ) ? $settings['iprep'] : array();
		$abuse    = isset( $iprep['abuseipdb'] ) && is_array( $iprep['abuseipdb'] ) ? $iprep['abuseipdb'] : array();

		$this->gate_enabled = ! array_key_exists( 'iprep_gate', $adaptive ) || (bool) $adaptive['iprep_gate'];
		$this->driver_id    = is_string( $iprep['driver'] ?? null ) && '' !== $iprep['driver'] ? $iprep['driver'] : 'abuseipdb';
		$this->pass_ttl     = max( 0, (int) ( $abuse['cache_minutes'] ?? 60 ) ) * 60;
		$this->fail_ttl     = max( 0, (int) ( $iprep['negative_cache_minutes'] ?? 10 ) ) * 60;
		$this->probe        = $probe;

		$this->get  = $get ?? static function ( string $key ) {
			return get_transient( $key ); // WP 边界。
		};
		$this->set  = $set ?? static function ( string $key, $value, int $ttl ): void {
			set_transient( $key, $value, $ttl ); // WP 边界。
		};
		$this->hmac = $hmac ?? static function ( string $data ): string {
			return substr( hash_hmac( 'sha256', $data, wp_salt( 'auth' ) ), 0, 16 ); // WP 边界。
		};
	}

	/**
	 * 三态判定（渲染期与验证期共用；渲染期预算：本地短路在前——调用方先查 flag/alarm）。
	 *
	 * @param string $ip 已归一化的访客 IP（IpResolver 解析产物）。
	 * @return string 'skip' | 'pass' | 'fail'
	 */
	public function state( string $ip ): string {
		if ( '' === $ip || ! $this->gate_enabled ) {
			return 'skip'; // 空 IP / 站长关闭联动（02 §2.3b skip 行）。
		}

		$key   = self::CACHE_PREFIX . $this->driver_id . '_' . (string) call_user_func( $this->hmac, $ip );
		$cached = call_user_func( $this->get, $key );
		if ( null !== $cached && false !== $cached ) {
			// 命中（与评论域共享缓存）；损坏/未知形态按 fail（fail-safe 从严——02 §2.3b）。
			return ( is_string( $cached ) && 'pass' === $cached ) ? 'pass' : 'fail';
		}

		$verdict = $this->probe->probe( $ip );
		if ( null === $verdict ) {
			return 'skip'; // 未启用（Null 服务恒 null——M2.5 现行行为；不写缓存）。
		}

		$state = ( Verdict::PASS === $verdict->verdict() ) ? 'pass' : 'fail'; // review/reject/error → fail 同档。
		call_user_func( $this->set, $key, $state, 'pass' === $state ? $this->pass_ttl : $this->fail_ttl );
		return $state;
	}
}
