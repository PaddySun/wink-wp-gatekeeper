<?php
/**
 * Null IP 信誉服务（02 §2.3b 实现载体/05 §3.0——M2.5 默认装配）。
 *
 * probe() 恒 null=恒 skip=现行行为（澄清 A 裁决注——M3 上线前所有站的登录闸/免验链
 * 即现行行为，不会出现「未配 IP 信誉的站恒需真实验证码/恒出码」）。
 *
 * @package WinkGatekeeper\Ip
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Ip;

use WinkGatekeeper\Verdict;

final class NullIpReputationService implements IpReputationProbe {

	/**
	 * @param string               $ip
	 * @param array<string, mixed> $ctx 【v1.7·M3-IP】cache_only 占位（Null 服务零行为差异——恒 null）。
	 * @return null
	 */
	public function probe( string $ip, array $ctx = array() ): ?Verdict {
		unset( $ip, $ctx );
		return null;
	}
}
