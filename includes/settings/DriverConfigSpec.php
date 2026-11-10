<?php
/**
 * DriverConfigSpec——后台驱动配置通用件（03 Tab③ v1.4·R4 §A.4：CaptchaTabSpec 范式泛化；F42）。
 *
 * Tab②/Tab③/Tab④(M3) 三处消费的下拉选项集/状态点（registry settings.required 派生）/
 * 配置区显隐（文案参数化——各 Tab 的「未配置=阶段跳过」语义不同）/缺失清单/服务端黄条
 * （文案参数化）/接入指引（registry guide 维）/Tab③ 专属 chain_summary 链路汇总行/
 * privacy_notice 逐驱动合规行（05 §4 逐驱动合规内容表经 registry privacy 维渲染）。
 *
 * 必填清单单一来源 = registry settings 声明的 required 字段——Tab① 健康行必填检查与
 * cloud_labels 同源派生（deepseek 等 非 CAM 驱动恒误报「凭据缺」的顺手缺陷修复，03 Tab①④）。
 *
 * 纯 PHP 零 WP 依赖（单测覆盖）；SettingsPage 边界层只做 esc/echo。
 *
 * @package WinkGatekeeper\Settings
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Settings;

final class DriverConfigSpec {

	/**
	 * registry settings 声明的必填字段名列表（声明序）。
	 *
	 * @param array<string, mixed> $meta 驱动注册元数据（registry_entry() 形态）。
	 * @return string[]
	 */
	public static function required_fields( array $meta ): array {
		$fields = array();
		if ( isset( $meta['settings'] ) && is_array( $meta['settings'] ) ) {
			foreach ( $meta['settings'] as $field => $decl ) {
				if ( is_array( $decl ) && ! empty( $decl['required'] ) ) {
					$fields[] = (string) $field;
				}
			}
		}
		return $fields;
	}

	/**
	 * registry settings 声明的字段 → 显示名（表单 label / 缺失清单 / 黄条共用；无 label 回退字段名）。
	 *
	 * @param array<string, mixed> $meta 驱动注册元数据。
	 * @return array<string, string>
	 */
	public static function field_labels( array $meta ): array {
		$labels = array();
		if ( isset( $meta['settings'] ) && is_array( $meta['settings'] ) ) {
			foreach ( $meta['settings'] as $field => $decl ) {
				$labels[ (string) $field ] = ( is_array( $decl ) && isset( $decl['label'] ) && is_string( $decl['label'] ) )
					? $decl['label']
					: (string) $field;
			}
		}
		return $labels;
	}

	/**
	 * 缺失必填字段的显示名列表（required 声明字段值为空串/缺失 = 缺）。
	 *
	 * @param array<string, mixed> $meta   驱动注册元数据。
	 * @param array<string, mixed> $config 该驱动的设置子树（当前已存/已提交值）。
	 * @return string[]
	 */
	public static function missing_fields( array $meta, array $config ): array {
		$labels  = self::field_labels( $meta );
		$missing = array();
		foreach ( self::required_fields( $meta ) as $field ) {
			$value = isset( $config[ $field ] ) && is_scalar( $config[ $field ] ) ? trim( (string) $config[ $field ] ) : '';
			if ( '' === $value ) {
				$missing[] = isset( $labels[ $field ] ) ? $labels[ $field ] : $field;
			}
		}
		return $missing;
	}

	/**
	 * 驱动下拉选项集（03 Tab③ 云端审核单选下拉；Tab②/Tab④ 同款模式）。
	 *
	 * 选项序：首项恒为「未配置」（无状态点）→ 已实装驱动（$registered，registry 序）→
	 * 未实装驱动（$future，带里程碑后缀「（M3）」——下拉可见但不构成链）。
	 *
	 * 状态点：required 声明字段全非空 = configured；缺 = unconfigured；运行时停转映射
	 * （$halted=DriverHealth 状态机）命中 = error（02 §5「配置错误」点）。
	 *
	 * @param array<string, array<string, mixed>> $registered 已实装驱动（id => registry meta）。
	 * @param array<string, array<string, mixed>> $configs    驱动 id => 设置子树（状态点判定）。
	 * @param array<string, array<string, mixed>> $future     未实装驱动（id => {label, milestone, required[]}）。
	 * @param array<string, string>               $halted     驱动 id => 停转状态（DriverHealth）。
	 * @return array<int, array{value: string, label: string, dot?: string}>
	 */
	public static function driver_options( array $registered, array $configs, array $future = array(), array $halted = array() ): array {
		$options = array( array(
			'value' => '',
			'label' => '未配置（跳过云端审核）',
		) );

		foreach ( $registered as $id => $meta ) {
			$config   = isset( $configs[ $id ] ) && is_array( $configs[ $id ] ) ? $configs[ $id ] : array();
			$options[] = array(
				'value' => (string) $id,
				'label' => isset( $meta['label'] ) && is_string( $meta['label'] ) ? $meta['label'] : (string) $id,
				'dot'   => isset( $halted[ $id ] ) && '' !== (string) $halted[ $id ]
					? 'error'
					: ( array() === self::missing_fields( is_array( $meta ) ? $meta : array(), $config ) ? 'configured' : 'unconfigured' ),
			);
		}

		foreach ( $future as $id => $meta ) {
			$label      = isset( $meta['label'] ) && is_string( $meta['label'] ) ? $meta['label'] : (string) $id;
			$milestone  = isset( $meta['milestone'] ) && is_string( $meta['milestone'] ) ? $meta['milestone'] : '';
			$options[] = array(
				'value' => (string) $id,
				'label' => '' !== $milestone ? "{$label}（{$milestone}）" : $label,
				'dot'   => 'unconfigured', // 未实装驱动恒未配置（不入默认链，04 §2 v1.4）。
			);
		}

		return $options;
	}

	/**
	 * 状态点 → 显示文字（02 §5 驱动健康灯状态机的配置态子集；与 CaptchaTabSpec 同枚举）。
	 *
	 * @return array<string, string>
	 */
	public static function dot_labels(): array {
		return array(
			'configured'   => '已配置',
			'unconfigured' => '未配置',
			'error'        => '配置错误',
		);
	}

	/**
	 * 配置区显隐（文案参数化：各 Tab「未配置=阶段跳过」语义不同——Tab③ 云端审核阶段跳过 /
	 * Tab② 验证码阶段整体跳过；hidden_note 由消费方传入）。
	 *
	 * @param string $selected    当前选中驱动 id（''=未配置）。
	 * @param string $hidden_note 未配置时的一行说明（消费方参数化文案）。
	 * @return array{visible: bool, vendor: ?string, hidden_note: string}
	 */
	public static function config_area_state( string $selected, string $hidden_note ): array {
		$visible = '' !== $selected;
		return array(
			'visible'     => $visible,
			'vendor'      => $visible ? $selected : null,
			'hidden_note' => $visible ? '' : $hidden_note,
		);
	}

	/**
	 * 服务端黄条文案（双层校验第二层，文案参数化）：选了驱动但必填空 → 列出缺失字段
	 * （字段显示名与 JS 端标红提示同源）。设置仍保存；运行时 is_configured()=false
	 * 自然回落「未配置=阶段跳过」语义。
	 *
	 * @param string               $selected     当前选中驱动 id。
	 * @param array<string, mixed> $meta         驱动注册元数据。
	 * @param array<string, mixed> $config       该驱动的设置子树。
	 * @param string               $domain_label 域标签（「云端审核」/「验证码」——文案参数化）。
	 * @return string|null 未选/无缺失 = null。
	 */
	public static function server_notice( string $selected, array $meta, array $config, string $domain_label ): ?string {
		if ( '' === $selected ) {
			return null;
		}
		$missing = self::missing_fields( $meta, $config );
		if ( array() === $missing ) {
			return null;
		}
		$label = isset( $meta['label'] ) && is_string( $meta['label'] ) ? $meta['label'] : $selected;
		return '已选择' . $domain_label . '驱动「' . $label . '」，但必填字段缺少：' . implode( '、', $missing )
			. '。设置已保存，但该驱动在补齐前视为未配置（相关检查阶段按「未配置」语义跳过）。';
	}

	/**
	 * Tab③ 链路汇总行文案（03 Tab③：链启用期间底部小字；纯函数可单测）。
	 *
	 * 「当前链路：本地规则 → <驱动名>（<合规摘要=region_note>）——逐驱动合规见各配置栏」。
	 * 有效链 = chain 中已注册且必填齐全的驱动（未配置驱动不构成链）；有效链空 = null。
	 *
	 * @param string[]                            $chain      review.cloud.chain。
	 * @param array<string, array<string, mixed>> $registered 已实装驱动（id => registry meta）。
	 * @param array<string, array<string, mixed>> $configs    驱动 id => 设置子树。
	 * @return string|null
	 */
	public static function chain_summary( array $chain, array $registered, array $configs ): ?string {
		$parts = array( '本地规则' );
		$count = 0;
		foreach ( $chain as $id ) {
			$id = (string) $id;
			if ( ! isset( $registered[ $id ] ) || ! is_array( $registered[ $id ] ) ) {
				continue;
			}
			$config = isset( $configs[ $id ] ) && is_array( $configs[ $id ] ) ? $configs[ $id ] : array();
			if ( array() !== self::missing_fields( $registered[ $id ], $config ) ) {
				continue; // 未配置驱动不构成链（enabled 开 + 未配置 = 云端阶段跳过）。
			}
			$label    = isset( $registered[ $id ]['label'] ) && is_string( $registered[ $id ]['label'] ) ? $registered[ $id ]['label'] : $id;
			$privacy  = isset( $registered[ $id ]['privacy'] ) && is_array( $registered[ $id ]['privacy'] ) ? $registered[ $id ]['privacy'] : array();
			$digest   = isset( $privacy['region_note'] ) && is_string( $privacy['region_note'] ) && '' !== $privacy['region_note']
				? '（' . $privacy['region_note'] . '）'
				: '';
			$parts[]  = $label . $digest;
			$count++;
		}
		if ( 0 === $count ) {
			return null;
		}
		return '当前链路：' . implode( ' → ', $parts ) . '——逐驱动合规见各配置栏';
	}

	/**
	 * 逐驱动合规行（03 Tab②/Tab③/Tab④ v1.4·R4 §C.3：registry privacy 维渲染；F43）。
	 *
	 * 形态：「启用 <驱动名> 即向第三方（<厂商>，<region_note>）提供访客信息：<data_fields>。
	 * <隐私条款>」；cross_border=true 升级黄条；null=黄条 + 辖区未知提示（通用出境提示原文）；
	 * 常驻不可关闭（启用即生效的法律提示）。本地驱动（无 privacy 维）= null 无合规行。
	 *
	 * @param array<string, mixed> $meta  驱动注册元数据（含 privacy 维）。
	 * @param string               $label 驱动显示名（运行时 label() 权威值）。
	 * @return array{level: string, text: string}|null
	 */
	public static function privacy_notice( array $meta, string $label ): ?array {
		if ( ! isset( $meta['privacy'] ) || ! is_array( $meta['privacy'] ) ) {
			return null; // 本地驱动无第三方外送（local_rules），无合规行。
		}
		$p        = $meta['privacy'];
		$border   = array_key_exists( 'cross_border', $p ) ? $p['cross_border'] : null;
		$fields   = isset( $p['data_fields'] ) && is_string( $p['data_fields'] ) ? $p['data_fields'] : '';
		$note     = isset( $p['region_note'] ) && is_string( $p['region_note'] ) ? $p['region_note'] : '';
		$url      = isset( $p['privacy_url'] ) && is_string( $p['privacy_url'] ) ? $p['privacy_url'] : '';
		$vendor   = isset( $meta['vendor'] ) && is_string( $meta['vendor'] ) ? $meta['vendor'] : $label;

		$text = "启用 {$label} 即向第三方（{$vendor}，{$note}）提供访客信息：{$fields}。";
		if ( '' !== $url ) {
			$text .= "隐私条款：{$url}";
		}
		if ( null === $border ) {
			$text .= '该服务所在司法辖区未知，请自行评估数据出境合规。';
		}

		return array(
			'level' => ( false === $border ) ? 'normal' : 'warn',
			'text'  => $text,
		);
	}
}
