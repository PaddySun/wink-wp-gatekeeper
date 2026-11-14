<?php
/**
 * U1+U2 站点增强·WP 边界层（M5 定稿 §1/§2——四 filter 装配于 Bootstrap::assemble_gates）。
 *
 * 装配条件：**无条件**（与闸启停无关——Tab⑧ 是站点内容设置面，闸关不剥夺；
 * 各开关自带 false 默认=关闭收集/前台去链接，未装配语义等价于官方零变化）。
 *
 * U1-A 双保险（定稿 §1）：comment_form_defaults 隐藏表单字段（展示面）+
 * preprocess_comment 丢弃 comment_author_url 入参（提交面——curl 可伪造面收口）；
 * 存量 comment_author_url 零接触。
 *
 * U1-B（定稿 §1/§0.1）：get_comment_author_link 改写——前台返回纯文本名字；
 * 后台（is_admin）照常链接（站长识别邻居核心语义）。
 *
 * U2（定稿 §2）：pre_get_avatar_data 短路改写 args['url']（WP 契约：filter 返回的
 * url 非空则核心跳过官方 URL 生成）——镜像不可解析时原样返回（官方源现行行为）。
 *
 * @package WinkGatekeeper\Site
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Site;

final class SiteEnhance {

	/**
	 * A：是否收集网站字段（false=隐藏表单字段+丢弃入参）。
	 *
	 * @var bool
	 */
	private $collect_url;

	/**
	 * B：前台是否保留作者名链接（false=前台去链接）。
	 *
	 * @var bool
	 */
	private $front_link;

	/**
	 * U2：镜像 base（null=official/非法——零改写）。
	 *
	 * @var string|null
	 */
	private $avatar_base;

	/**
	 * @param array<string, mixed> $settings merge 后整树（assemble_gates 装配期快照）。
	 */
	public function __construct( array $settings ) {
		$this->collect_url = SiteFieldPolicy::wants_collection( $settings );
		$this->front_link  = SiteFieldPolicy::wants_frontend_link( $settings );
		$this->avatar_base = AvatarSources::base_for(
			AvatarSources::normalize( $settings['avatar']['source'] ?? null ),
			(string) ( $settings['avatar']['custom_base'] ?? '' )
		);
	}

	/**
	 * U1-A 展示面：comment_form_defaults 删 url 字段（A 关闭时）。
	 *
	 * @param array<string, mixed> $defaults comment_form_defaults 入参。
	 * @return array<string, mixed>
	 */
	public function filter_comment_form_defaults( array $defaults ): array {
		if ( $this->collect_url ) {
			return $defaults;
		}
		if ( isset( $defaults['fields'] ) && is_array( $defaults['fields'] ) ) {
			unset( $defaults['fields']['url'] );
		}
		return $defaults;
	}

	/**
	 * U1-A 提交面：preprocess_comment 丢弃 comment_author_url 入参（双保险）。
	 *
	 * @param array<string, mixed> $comment_data 入参。
	 * @return array<string, mixed>
	 */
	public function filter_preprocess_comment( array $comment_data ): array {
		if ( $this->collect_url ) {
			return $comment_data;
		}
		return SiteFieldPolicy::strip_url( $comment_data );
	}

	/**
	 * U1-B：前台作者名去链接（B 关闭时；后台 is_admin 照常链接）。
	 *
	 * @param mixed                    $link       当前作者链接 HTML。
	 * @param int                      $comment_id 评论 ID（get_comment_author_link 三参形态）。
	 * @param \WP_Comment|\stdClass|null $comment   评论对象（三参形态第三参）。
	 * @return mixed
	 */
	public function filter_comment_author_link( $link, int $comment_id = 0, $comment = null ) {
		if ( $this->front_link ) {
			return $link;
		}
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return $link; // 后台照常显示可点链接（0.1 语义核心）。
		}
		if ( ! function_exists( 'get_comment_author' ) ) {
			return $link; // 纯单测环境守卫（边界层 WP 函数不在时零行为）。
		}
		$name = get_comment_author( $comment_id > 0 ? $comment_id : ( $comment ?? null ) );
		return is_string( $name ) && '' !== $name ? $name : $link;
	}

	/**
	 * U2：pre_get_avatar_data 短路改写（镜像 base 在场时构造镜像 URL）。
	 *
	 * @param array<string, mixed> $args       头像参数（size/default/rating 等）。
	 * @param mixed                $id_or_email 用户 ID / WP_User / 评论对象 / 邮箱 / WP_Post。
	 * @return array<string, mixed>
	 */
	public function filter_pre_get_avatar_data( array $args, $id_or_email ): array {
		if ( null === $this->avatar_base ) {
			return $args;
		}
		$email = $this->email_from( $id_or_email );
		if ( null === $email || '' === $email ) {
			return $args; // 无法定位邮箱：交回官方生成（零改写）。
		}

		$hash  = md5( strtolower( trim( $email ) ) );
		$parts = array();
		foreach ( array( 's' => ( $args['size'] ?? '' ), 'd' => ( $args['default'] ?? '' ), 'r' => ( $args['rating'] ?? '' ) ) as $key => $value ) {
			if ( '' !== $value && null !== $value ) {
				$parts[] = $key . '=' . rawurlencode( (string) $value );
			}
		}
		$url         = $this->avatar_base . $hash . ( array() !== $parts ? '?' . implode( '&', $parts ) : '' );
		$args['url'] = $url;
		return $args;
	}

	/**
	 * 从 id_or_email 形态族提取邮箱（WP 同款判定序；不可定位=null）。
	 *
	 * @param mixed $id_or_email 头像目标。
	 * @return string|null
	 */
	private function email_from( $id_or_email ): ?string {
		if ( is_string( $id_or_email ) && false !== strpos( $id_or_email, '@' ) ) {
			return $id_or_email;
		}
		if ( is_numeric( $id_or_email ) && function_exists( 'get_userdata' ) ) {
			$user = get_userdata( (int) $id_or_email );
			return ( $user && ! empty( $user->user_email ) ) ? $user->user_email : null;
		}
		if ( $id_or_email instanceof \WP_User && ! empty( $id_or_email->user_email ) ) {
			return $id_or_email->user_email;
		}
		if ( is_object( $id_or_email ) ) {
			// 评论对象形态（WP_Comment/stdClass——pre_get_avatar_data 常规来源）。
			if ( ! empty( $id_or_email->comment_author_email ) ) {
				return (string) $id_or_email->comment_author_email;
			}
			// WP_Post 形态：取作者账户邮箱。
			if ( ! empty( $id_or_email->post_author ) && function_exists( 'get_userdata' ) ) {
				$user = get_userdata( (int) $id_or_email->post_author );
				return ( $user && ! empty( $user->user_email ) ) ? $user->user_email : null;
			}
		}
		return null;
	}
}
