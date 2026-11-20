<?php
/**
 * Tab② 驱动配置交互纯逻辑（03 §1 v1.3 用户建议1+2；渲染数据单一来源）。
 *
 * SettingsPage Tab② 边界层只做 esc/echo——下拉选项集/状态点/配置区可见性/必填缺失清单/
 * 服务端黄条文案/接入指引内容全部由此派生（JS 侧标红提示与服务端黄条共用同一字段显示名）。
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

final class CaptchaTabSpec {

	/**
	 * 天御四凭据字段 → 显示名（Tab② 表单 label / JS 标红提示 / 黄条列缺共用）。
	 *
	 * @return array<string, string>
	 */
	public static function tencent_field_labels(): array {
		return array(
			'app_id'         => 'CaptchaAppId',
			'secret_id'      => 'SecretId（CAM）',
			'secret_key'     => 'SecretKey（CAM）',
			'app_secret_key' => __( 'AppSecretKey (captcha console)', 'wink-wp-gatekeeper' ),
		);
	}

	/**
	 * 缺失必填字段的显示名列表（app_id 空 或 password 型空串 = 缺）。
	 *
	 * @param array<string, mixed> $tencent captcha.tencent 子树。
	 * @return string[]
	 */
	public static function tencent_missing_fields( array $tencent ): array {
		$missing = array();
		foreach ( self::tencent_field_labels() as $key => $label ) {
			$value = isset( $tencent[ $key ] ) && is_scalar( $tencent[ $key ] ) ? trim( (string) $tencent[ $key ] ) : '';
			if ( '' === $value ) {
				$missing[] = $label;
			}
		}
		return $missing;
	}

	/**
	 * 阿里四凭据字段 → 显示名（Tab② 表单 label / JS 标红提示 / 黄条列缺共用——05 §1.2）。
	 *
	 * @return array<string, string>
	 */
	public static function aliyun_field_labels(): array {
		return array(
			'access_key_id'     => 'AccessKeyId',
			'access_key_secret' => 'AccessKeySecret',
			'scene_id'          => 'SceneId',
			'prefix'            => __( 'prefix (identity tag)', 'wink-wp-gatekeeper' ),
		);
	}

	/**
	 * 阿里缺失必填字段的显示名列表（四字段任一空 = 缺；SceneId v1.5 必填化——F012 防线）。
	 *
	 * @param array<string, mixed> $aliyun captcha.aliyun 子树。
	 * @return string[]
	 */
	public static function aliyun_missing_fields( array $aliyun ): array {
		$missing = array();
		foreach ( self::aliyun_field_labels() as $key => $label ) {
			$value = isset( $aliyun[ $key ] ) && is_scalar( $aliyun[ $key ] ) ? trim( (string) $aliyun[ $key ] ) : '';
			if ( '' === $value ) {
				$missing[] = $label;
			}
		}
		return $missing;
	}

	/**
	 * 驱动下拉选项集（含状态点后缀；「未配置」项无点）。
	 *
	 * @param array<string, array<string, mixed>> $drivers registry captcha 类驱动（id => meta）。
	 * @param array<string, mixed>                 $tencent captcha.tencent 子树（状态点判定）。
	 * @param array<string, mixed>                 $aliyun  captcha.aliyun 子树（状态点判定，B3）。
	 * @return array<int, array{value: string, label: string, dot?: string}>
	 */
	public static function driver_options( array $drivers, array $tencent, array $aliyun = array() ): array {
		$options = array( array(
			'value' => '',
			'label' => __( 'Not configured (captcha stage skipped)', 'wink-wp-gatekeeper' ),
		) );
		foreach ( $drivers as $id => $meta ) {
			$option = array(
				'value' => (string) $id,
				'label' => isset( $meta['label'] ) && is_string( $meta['label'] ) ? $meta['label'] : (string) $id,
			);
			if ( 'tencent' === (string) $id ) {
				// 状态点：四字段齐全=已配置；缺失=未配置（「配置错误」=M3 健康灯状态机运行时态，位预留）。
				$option['dot'] = array() === self::tencent_missing_fields( $tencent ) ? 'configured' : 'unconfigured';
			}
			if ( 'aliyun' === (string) $id ) {
				// 【v1.5·B3】阿里状态点同口径（四字段齐全=已配置）。
				$option['dot'] = array() === self::aliyun_missing_fields( $aliyun ) ? 'configured' : 'unconfigured';
			}
			$options[] = $option;
		}
		return $options;
	}

	/**
	 * 状态点 → 显示文字（02 §5 驱动健康灯状态机的配置态子集）。
	 *
	 * @return array<string, string>
	 */
	public static function dot_labels(): array {
		return array(
			'configured'   => __( 'Configured', 'wink-wp-gatekeeper' ),
			'unconfigured' => __( 'Not configured', 'wink-wp-gatekeeper' ),
			'error'        => __( 'Configuration error', 'wink-wp-gatekeeper' ),
		);
	}

	/**
	 * 配置区可见性：未配置 = 隐藏 + 一行说明（红点语义）；选厂商 = 显示对应配置区。
	 *
	 * @param string $driver captcha.driver 当前值。
	 * @return array{visible: bool, vendor: ?string, hidden_note: string}
	 */
	public static function config_area_state( string $driver ): array {
		$visible = '' !== $driver;
		return array(
			'visible'     => $visible,
			'vendor'      => $visible ? $driver : null,
			'hidden_note' => __( 'Not configured = the captcha stage is skipped entirely and the comment gate relies on content filtering only (02 §2.1 unconfigured behavior).', 'wink-wp-gatekeeper' ),
		);
	}

	/**
	 * 服务端黄条文案（双层校验第二层）：选了厂商但必填空 → 驱动视为未配置 + 列出缺失字段。
	 * 设置仍保存（不阻断其它 Tab）；运行时 is_configured()=false 自然回落未配置语义。
	 *
	 * @param string               $driver  captcha.driver。
	 * @param array<string, mixed> $tencent captcha.tencent 子树。
	 * @param array<string, mixed> $aliyun  captcha.aliyun 子树（【v1.5·B3】阿里黄条判定）。
	 * @return string|null 无缺失/未配置 = null。
	 */
	public static function server_notice( string $driver, array $tencent, array $aliyun = array() ): ?string {
		if ( '' === $driver ) {
			return null;
		}
		$missing = 'aliyun' === $driver ? self::aliyun_missing_fields( $aliyun ) : self::tencent_missing_fields( $tencent );
		if ( array() === $missing ) {
			return null;
		}
		$label = 'tencent' === $driver ? __( 'Tencent Tianyu Captcha 2.0', 'wink-wp-gatekeeper' ) : ( 'aliyun' === $driver ? __( 'Aliyun Captcha 2.0', 'wink-wp-gatekeeper' ) : $driver );
		return __( 'Selected captcha driver “', 'wink-wp-gatekeeper' ) . $label . __( '”, but required fields are missing: ', 'wink-wp-gatekeeper' ) . implode( '、', $missing )
			. __( '. Settings were saved, but the driver counts as not configured until they are filled in (captcha stage skipped entirely, comment gate relies on content filtering only).', 'wink-wp-gatekeeper' );
	}

	/**
	 * 阿里接入指引内容（05 §1.2 提炼；配置区顶部折叠面板消费）。
	 *
	 * @return array{steps: string[], console_url: string, doc_url: string, ticket_note: string}
	 */
	public static function aliyun_guide(): array {
		return array(
			'steps'       => array(
				__( '1. Go to the Aliyun Captcha 2.0 console to enable the service (pay-as-you-go, with a free tier).', 'wink-wp-gatekeeper' ),
				__( '2. Create a verification scene under “Scene Management” and get the SceneId (required — the server re-sends it to prevent front-end tampering; a mismatch returns F012).', 'wink-wp-gatekeeper' ),
				__( '3. Get the prefix from the console overview page (front-end identity tag, used by the global AliyunCaptchaConfig).', 'wink-wp-gatekeeper' ),
				__( '4. Go to “Access Management → RAM” to create an AccessKey pair (main-account AKs forbidden) and grant AliyunYundunAFSFullAccess.', 'wink-wp-gatekeeper' ),
				__( '5. Paste the four fields into the configuration area above (keys are server-side only and never exposed to the front end).', 'wink-wp-gatekeeper' ),
				__( '6. Click “Test credentials” — three-state verdict: green = credentials work; red = AK/signature/permission error; yellow = required fields missing.', 'wink-wp-gatekeeper' ),
			),
			'console_url' => 'https://captcha2.console.aliyun.com/',
			'doc_url'     => 'https://help.aliyun.com/zh/captcha/user-guide/verify-intelligent-captcha',
			'ticket_note' => __( 'Front-end red line: AliyunCaptcha.js must be loaded dynamically (a static deployment defeats adversarial resistance / causes false blocks); CaptchaVerifyParam is single-use with a 90-second validity (F019) — after expiry the front end automatically falls back to unverified and verification must be redone; region is always cn (the front-end and server endpoints must map consistently).', 'wink-wp-gatekeeper' ),
		);
	}

	/**
	 * 接入指引内容（05 §1.1 提炼；配置区顶部折叠面板消费）。
	 *
	 * @return array{steps: string[], console_url: string, doc_url: string, ticket_note: string}
	 */
	public static function guide(): array {
		return array(
			'steps'       => array(
				__( '1. Go to the Tencent Cloud captcha console to enable the service (free to enable; verifications are billed by package).', 'wink-wp-gatekeeper' ),
				__( '2. Create a verification scene under “Verification Management” and get the CaptchaAppId and AppSecretKey (the key column is on the same page).', 'wink-wp-gatekeeper' ),
				__( '3. Go to “Access Management → API Key Management” to prepare the CAM key pair SecretId/SecretKey (for TC3 signing; a separate credential system from the captcha console keys).', 'wink-wp-gatekeeper' ),
				__( '4. Paste the four fields into the configuration area above (keys are server-side only and never exposed to the front end).', 'wink-wp-gatekeeper' ),
				__( '5. Click “Test credentials” — three-state verdict: green = credentials work; red = CAM key error; yellow = required parameters missing.', 'wink-wp-gatekeeper' ),
			),
			'console_url' => 'https://console.cloud.tencent.com/captcha/graphical',
			'doc_url'     => 'https://cloud.tencent.com/document/product/1110/36926',
			'ticket_note' => __( 'The verification ticket (Ticket) is valid for 5 minutes and single-use — after expiry the front end automatically falls back to unverified and verification must be redone.', 'wink-wp-gatekeeper' ),
		);
	}
}
