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
			'app_secret_key' => 'AppSecretKey（验证码控制台）',
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
			'prefix'            => 'prefix（身份标）',
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
			'label' => '未配置（跳过验证码阶段）',
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
			'configured'   => '已配置',
			'unconfigured' => '未配置',
			'error'        => '配置错误',
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
			'hidden_note' => '未配置 = 验证码阶段整体跳过，评论闸仅内容过滤生效（02 §2.1 未配置行为）。',
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
		$label = 'tencent' === $driver ? '腾讯天御验证码 2.0' : ( 'aliyun' === $driver ? '阿里云验证码 2.0' : $driver );
		return '已选择验证码驱动「' . $label . '」，但必填字段缺少：' . implode( '、', $missing )
			. '。设置已保存，但该驱动在补齐前视为未配置（验证码阶段整体跳过，评论闸仅内容过滤生效）。';
	}

	/**
	 * 阿里接入指引内容（05 §1.2 提炼；配置区顶部折叠面板消费）。
	 *
	 * @return array{steps: string[], console_url: string, doc_url: string, ticket_note: string}
	 */
	public static function aliyun_guide(): array {
		return array(
			'steps'       => array(
				'1. 前往阿里云验证码 2.0 控制台开通服务（按量计费，有免费额度档）。',
				'2. 在【场景管理】创建验证场景，获取 SceneId（必填——服务端补传防前端篡改场景，错配返回 F012）。',
				'3. 在控制台概览页获取 prefix（前端身份标，AliyunCaptchaConfig 全局配置用）。',
				'4. 前往「访问管理 → RAM」创建 AccessKey 对（禁主账号 AK），并授权 AliyunYundunAFSFullAccess。',
				'5. 将四个字段粘贴至上方配置区（密钥仅服务端使用，绝不输出前端）。',
				'6. 点击「测试凭据」——三态判定：绿=凭据可用；红=AK/签名/权限错；黄=必填字段缺。',
			),
			'console_url' => 'https://captcha2.console.aliyun.com/',
			'doc_url'     => 'https://help.aliyun.com/zh/captcha/user-guide/verify-intelligent-captcha',
			'ticket_note' => '前端红线：AliyunCaptcha.js 必须动态引入（静态部署致对抗失效/误拦）；CaptchaVerifyParam 单次有效、90 秒时效（F019）——超时后前端自动回退未验证态，需重新完成验证；region 恒 cn（前端与服务端 endpoint 映射须一致）。',
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
				'1. 前往腾讯云验证码控制台开通验证码服务（免费开通，验证量按套餐计费）。',
				'2. 在【验证管理】创建验证场景，获取 CaptchaAppId 与 AppSecretKey（密钥列同页）。',
				'3. 前往「访问管理 → API 密钥管理」准备 CAM 密钥对 SecretId/SecretKey（TC3 签名用，与验证码控制台密钥分属两套凭据体系）。',
				'4. 将四个字段粘贴至上方配置区（密钥仅服务端使用，绝不输出前端）。',
				'5. 点击「测试凭据」——三态判定：绿=凭据可用；红=CAM 密钥错；黄=必填参数缺。',
			),
			'console_url' => 'https://console.cloud.tencent.com/captcha/graphical',
			'doc_url'     => 'https://cloud.tencent.com/document/product/1110/36926',
			'ticket_note' => '验证票据（Ticket）有效期 5 分钟且单次使用——超时后前端自动回退未验证态，需重新完成验证。',
		);
	}
}
