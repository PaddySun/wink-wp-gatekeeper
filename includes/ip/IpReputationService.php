<?php
/**
 * IP 信誉服务装配器（02 §2.3b 实现载体节/05 §3.0——【v1.7·M3-IP·定稿 §2】I2 起三态装配）。
 *
 * Bootstrap 装配单一接线点（四闸构造器不动——O5-P3-4：装配经本类单点返回）：
 *   !iprep.enabled              → Null 实现（恒 skip=现行行为）；
 *   iprep.enabled && api_key 空 → AbuseIpdbService（防呆分支——服务内 probe 恒 fail 从严，
 *                                 不静默 skip；「开而不配好」可见、可自愈）；
 *   iprep.enabled && api_key 非空 → AbuseIpdbService（check 七步流水线全量）。
 *
 * 「未启用」分支=probe()===null 一处定义三处消费（02 §2.3b）：预检端点/评论提交期 ip 阶段/
 * 登录闸 LoginIpRep。
 *
 * @package WinkGatekeeper\Ip
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Ip;

use WinkGatekeeper\Http\RemoteClient;

final class IpReputationService {

	/**
	 * 装配当前设置下的探测服务。
	 *
	 * @param array<string, mixed> $settings 完整设置树（读 iprep 子树）。
	 * @param RemoteClient|null    $remote   传输脊（生产=Bootstrap 共享实例——usage 计量钩子在
	 *                                   脊上；null=服务内缺省 WP 边界薄包装）。
	 * @return IpReputationProbe
	 */
	public static function assemble( array $settings, ?RemoteClient $remote = null ): IpReputationProbe {
		$iprep = isset( $settings['iprep'] ) && is_array( $settings['iprep'] ) ? $settings['iprep'] : array();
		if ( empty( $iprep['enabled'] ) ) {
			/* 【I3·定稿 §4/04 §2】report 独立于 iprep.enabled：上报子系统开启（report.enabled）
			 * 且密钥在 → 仍装配 AbuseIpdbService（report-only 形态——probe 的 check 面由服务内
			 * iprep.enabled 开关承载，恒 null skip 与 Null 服务语义等价、零外呼零计量；report_ip
			 * 经 instanceof 能力探测可达）。密钥缺 → 上报面无从发送 → Null（静默；防呆只属 check 面）。 */
			$report = isset( $iprep['report'] ) && is_array( $iprep['report'] ) ? $iprep['report'] : array();
			$abuse  = isset( $iprep['abuseipdb'] ) && is_array( $iprep['abuseipdb'] ) ? $iprep['abuseipdb'] : array();
			$key    = isset( $abuse['api_key'] ) && is_string( $abuse['api_key'] ) ? $abuse['api_key'] : '';
			if ( ! empty( $report['enabled'] ) && '' !== $key ) {
				return new AbuseIpdbService( $settings, $remote );
			}
			return new NullIpReputationService();
		}

		/* 【v1.7·M3-IP·定稿 §2】enabled 即装配 AbuseIpdbService——api_key 空的防呆从严
		 * （probe 恒 fail reason=not_configured）在服务内承载（步 0 白名单仍前置豁免：
		 * 站长本人/白名单 IP 不因漏配钥被误伤——行为层「永不查、永不 fail」优先于防呆）。 */
		return new AbuseIpdbService( $settings, $remote );
	}

	/**
	 * 健康面状态标签（纯函数：Tab④ 分区二健康行消费——M2.5 两态 + 【v1.7·M3-IP】第三态）。
	 *
	 * @param array<string, mixed> $settings 完整设置树。
	 * @return string '未启用' | '已启用（AbuseIPDB）'（enabled 即装配——防呆态由 Tab①/Tab④
	 *                健康灯 config_error 语义呈现，非「未装配」）。
	 */
	public static function state_label( array $settings ): string {
		$iprep = isset( $settings['iprep'] ) && is_array( $settings['iprep'] ) ? $settings['iprep'] : array();
		if ( empty( $iprep['enabled'] ) ) {
			return '未启用';
		}
		return '已启用（AbuseIPDB）';
	}
}
