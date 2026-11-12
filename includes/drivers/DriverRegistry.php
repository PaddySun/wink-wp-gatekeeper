<?php
/**
 * 驱动注册表（05 §4 七维元数据；M0 注册表为空，驱动 M1 起接入）。
 *
 * 七维元数据（label/billing/frontend/error_map/settings/api_version/privacy【v1.4·R4】）
 * + 可选结构维（guide 接入指引、settings 字段扩展 label/required）是驱动
 * 「怎么调、怎么配、怎么显示、怎么判错、向谁送数据」的单一来源——新驱动 = 新文件 + 注册一行，
 * 不再散改 Sanitize/Tab/schema 三处。
 *
 * 注意：class 字段不做 class_exists 校验（M0 注册的驱动类尚未实装，
 * 注册表数据先于实现存在；装配期由 Bootstrap 负责存在性检查）。
 *
 * @package WinkGatekeeper\Drivers
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Drivers;

final class DriverRegistry {

	public const TYPE_CAPTCHA = 'captcha';
	public const TYPE_REVIEW  = 'review';
	public const TYPE_IP      = 'ip';

	private const TYPES = array( self::TYPE_CAPTCHA, self::TYPE_REVIEW, self::TYPE_IP );

	/**
	 * billing 枚举：free（无远端调用）/ usage（按量计费）/ token（预购套餐包）/
	 * free_quota（免费配额档——【v1.7·M3-IP·O5-P3-3】AbuseIPDB 贡献者 5000/天形态）。
	 */
	private const BILLINGS = array( 'free', 'usage', 'token', 'free_quota' );

	private const INTERACTIONS = array( 'click', 'inline', 'invisible' );

	/**
	 * 注册表：id => 元数据（规范化后，supports_report 已有默认值）。
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $drivers = array();

	/**
	 * 注册驱动元数据。
	 *
	 * @param array<string, mixed> $driver 驱动元数据（05 §4 结构）。
	 * @return void
	 * @throws \InvalidArgumentException 缺字段/重复 id/枚举越界/结构非法。
	 */
	public function register( array $driver ): void {
		$required = array( 'id', 'type', 'class', 'label', 'billing', 'frontend', 'error_map', 'settings', 'api_version' );
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $driver ) ) {
				throw new \InvalidArgumentException( "驱动注册缺少必填字段：{$field}" );
			}
		}

		$id = $driver['id'];
		if ( ! is_string( $id ) || '' === $id ) {
			throw new \InvalidArgumentException( '驱动注册字段非法：id 必须为非空字符串' );
		}
		if ( array_key_exists( $id, $this->drivers ) ) {
			throw new \InvalidArgumentException( "驱动注册重复 id：{$id}" );
		}
		if ( ! in_array( $driver['type'], self::TYPES, true ) ) {
			throw new \InvalidArgumentException( '驱动注册字段非法：type 必须为 captcha|review|ip' );
		}
		if ( ! is_string( $driver['class'] ) || '' === $driver['class'] ) {
			throw new \InvalidArgumentException( '驱动注册字段非法：class 必须为非空类名' );
		}
		if ( ! is_string( $driver['label'] ) || '' === $driver['label'] ) {
			throw new \InvalidArgumentException( '驱动注册字段非法：label 必须为非空字符串' );
		}
		if ( ! in_array( $driver['billing'], self::BILLINGS, true ) ) {
			throw new \InvalidArgumentException( '驱动注册字段非法：billing 必须为 free|usage|token|free_quota' );
		}
		if ( ! is_string( $driver['api_version'] ) || '' === $driver['api_version'] ) {
			throw new \InvalidArgumentException( '驱动注册字段非法：api_version 必须为非空字符串（版本键，落事件 meta）' );
		}
		$this->validate_frontend( $driver['frontend'], (string) $driver['type'] );
		$this->validate_error_map( $driver['error_map'] );
		$this->validate_settings( $driver['settings'] );

		/* 【v1.4·R4 §C.1/05 §4】第七维 privacy（可选——本地驱动无第三方外送不声明）：
		 * 存在时 cross_border ∈ {true,false,null}、privacy_url 为 url 或空串（空串=待核占位）。 */
		if ( array_key_exists( 'privacy', $driver ) ) {
			$this->validate_privacy( $driver['privacy'] );
		}

		if ( ! array_key_exists( 'supports_report', $driver ) ) {
			// supports_report 为 IP 类驱动专属能力声明（AbuseIPDB=true），缺省 false。
			$driver['supports_report'] = false;
		} elseif ( ! is_bool( $driver['supports_report'] ) ) {
			throw new \InvalidArgumentException( '驱动注册字段非法：supports_report 必须为布尔值' );
		}

		$this->drivers[ $id ] = $driver;
	}

	/**
	 * @param string $id 驱动 id。
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		return $this->drivers[ $id ] ?? null;
	}

	/**
	 * 全部注册驱动（保持注册顺序）。
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		return $this->drivers;
	}

	/**
	 * 按驱动类型筛选（captcha=验证码 / review=内容审核 / ip=IP 信誉）。
	 *
	 * @param string $type 驱动类型。
	 * @return array<string, array<string, mixed>>
	 */
	public function by_type( string $type ): array {
		$matched = array();
		foreach ( $this->drivers as $id => $driver ) {
			if ( $driver['type'] === $type ) {
				$matched[ $id ] = $driver;
			}
		}
		return $matched;
	}

	/**
	 * frontend 声明校验：captcha 驱动必须声明 interaction（click|inline|invisible）
	 * 与 token_fields（字符串数组）；review/ip 类驱动允许空数组（无前端形态）。
	 *
	 * @param mixed  $frontend 前端形态声明。
	 * @param string $type     驱动类型。
	 * @return void
	 */
	private function validate_frontend( $frontend, string $type ): void {
		if ( ! is_array( $frontend ) ) {
			throw new \InvalidArgumentException( '驱动注册字段非法：frontend 必须为数组' );
		}
		if ( array() === $frontend ) {
			if ( self::TYPE_CAPTCHA === $type ) {
				throw new \InvalidArgumentException( '驱动注册字段非法：captcha 驱动必须声明 frontend.interaction 与 frontend.token_fields' );
			}
			return;
		}

		if ( ! isset( $frontend['interaction'] ) || ! in_array( $frontend['interaction'], self::INTERACTIONS, true ) ) {
			throw new \InvalidArgumentException( '驱动注册字段非法：frontend.interaction 必须为 click|inline|invisible' );
		}
		if ( ! isset( $frontend['token_fields'] ) || ! is_array( $frontend['token_fields'] ) || array() === $frontend['token_fields'] ) {
			throw new \InvalidArgumentException( '驱动注册字段非法：frontend.token_fields 必须为非空字符串数组' );
		}
		foreach ( $frontend['token_fields'] as $field ) {
			if ( ! is_string( $field ) || '' === $field ) {
				throw new \InvalidArgumentException( '驱动注册字段非法：frontend.token_fields 元素必须为非空字符串' );
			}
		}
	}

	/**
	 * error_map 校验：body 级错误码映射（回调名 => 字符串），可为空数组。
	 *
	 * @param mixed $error_map
	 * @return void
	 */
	private function validate_error_map( $error_map ): void {
		if ( ! is_array( $error_map ) ) {
			throw new \InvalidArgumentException( '驱动注册字段非法：error_map 必须为数组' );
		}
		foreach ( $error_map as $probe => $callback ) {
			if ( ! is_string( $probe ) || ! is_string( $callback ) || '' === $callback ) {
				throw new \InvalidArgumentException( '驱动注册字段非法：error_map 值必须为非空字符串（body 探测回调名）' );
			}
		}
	}

	/**
	 * settings 校验：配置声明权下放（字段名 => {type, sanitize}），可为空数组。
	 *
	 * 【v1.4·R4 §A.4/05 §4】字段声明扩展 +label（非空字符串显示名）/+required（布尔必填判定）
	 * ——DriverConfigSpec 状态点/缺失清单/服务端黄条/Tab① 健康行必填检查同源派生。
	 *
	 * @param mixed $settings
	 * @return void
	 */
	private function validate_settings( $settings ): void {
		if ( ! is_array( $settings ) ) {
			throw new \InvalidArgumentException( '驱动注册字段非法：settings 必须为数组' );
		}
		foreach ( $settings as $field => $spec ) {
			if ( ! is_string( $field ) || '' === $field || ! is_array( $spec ) || ! isset( $spec['type'] ) || ! is_string( $spec['type'] ) || '' === $spec['type'] ) {
				throw new \InvalidArgumentException( '驱动注册字段非法：settings 声明必须为 字段名 => {type: 非空字符串, ...}' );
			}
			if ( array_key_exists( 'label', $spec ) && ( ! is_string( $spec['label'] ) || '' === $spec['label'] ) ) {
				throw new \InvalidArgumentException( '驱动注册字段非法：settings.label 必须为非空字符串（字段显示名）' );
			}
			if ( array_key_exists( 'required', $spec ) && ! is_bool( $spec['required'] ) ) {
				throw new \InvalidArgumentException( '驱动注册字段非法：settings.required 必须为布尔值（必填判定）' );
			}
		}
	}

	/**
	 * privacy 维校验（05 §4【v1.4】：合规单一来源——顶层 privacy_url/cross_border 散件已并入本维删除）。
	 *
	 * @param mixed $privacy
	 * @return void
	 */
	private function validate_privacy( $privacy ): void {
		if ( ! is_array( $privacy ) ) {
			throw new \InvalidArgumentException( '驱动注册字段非法：privacy 必须为数组' );
		}
		if ( ! array_key_exists( 'cross_border', $privacy ) || ( ! is_bool( $privacy['cross_border'] ) && null !== $privacy['cross_border'] ) ) {
			throw new \InvalidArgumentException( '驱动注册字段非法：privacy.cross_border 必须为 true|false|null（出境|境内|辖区未知）' );
		}
		$url = array_key_exists( 'privacy_url', $privacy ) ? $privacy['privacy_url'] : '';
		if ( ! is_string( $url ) || ( '' !== $url && false === filter_var( $url, FILTER_VALIDATE_URL ) ) ) {
			throw new \InvalidArgumentException( '驱动注册字段非法：privacy.privacy_url 必须为 url 或空串（空串=待核占位）' );
		}
	}
}
