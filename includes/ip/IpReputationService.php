<?php
/**
 * IP 信誉服务装配器（02 §2.3b 实现载体节/05 §3.0——【v1.4·R2/R3，M2.5 骨架】）。
 *
 * Bootstrap 装配单一接线点：`iprep.enabled && 驱动已配置 ? AbuseIPDB 实现（M3 接入）: Null 实现`。
 * M2.5：AbuseIPDB 驱动未实装——无论开关与否均装配 Null（恒 skip）。M3 驱动接入后在本类
 * 补驱动构造分支，三处消费方（预检端点/评论提交期 ip 阶段/登录闸 LoginIpRep）零改动。
 *
 * 「未启用」分支=probe()===null 一处定义三处消费（02 §2.3b）。
 *
 * @package WinkGatekeeper\Ip
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Ip;

final class IpReputationService {

	/**
	 * 装配当前设置下的探测服务。
	 *
	 * @param array<string, mixed> $settings 完整设置树（读 iprep 子树）。
	 * @return IpReputationProbe
	 */
	public static function assemble( array $settings ): IpReputationProbe {
		$iprep = isset( $settings['iprep'] ) && is_array( $settings['iprep'] ) ? $settings['iprep'] : array();
		if ( empty( $iprep['enabled'] ) ) {
			return new NullIpReputationService();
		}
		// 开关开了但驱动未实装（M2.5 唯一可达形态）：仍 Null——「未装配」不构成故障判定面
		// （防呆从严语义由 M3 驱动接入后的「已启用但未配置」分支承载，05 §3.0）。
		return new NullIpReputationService();
	}

	/**
	 * 健康面状态标签（纯函数占位：Tab④ 分区二 M3 消费；本批交付「未启用」态可见化——
	 * T1「未启用=未启用态」）。
	 *
	 * @param array<string, mixed> $settings 完整设置树。
	 * @return string '未启用' | '未装配（M3）' | '已启用'（M3 驱动接入后补第三态）。
	 */
	public static function state_label( array $settings ): string {
		$iprep = isset( $settings['iprep'] ) && is_array( $settings['iprep'] ) ? $settings['iprep'] : array();
		if ( empty( $iprep['enabled'] ) ) {
			return '未启用';
		}
		return '未装配（M3）';
	}
}
