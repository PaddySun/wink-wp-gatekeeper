<?php
/**
 * U1+U2 站点增强·WP 边界层（M5 定稿 §1/§2——五 filter 装配于 Bootstrap::assemble_gates）。
 *
 * 装配条件：**无条件**（与闸启停无关——Tab⑧ 是站点内容设置面，闸关不剥夺；
 * 各开关自带 false 默认=关闭收集/前台去链接，未装配语义等价于官方零变化）。
 *
 * U1-A 双保险（定稿 §1）：comment_form_defaults 隐藏表单字段（展示面）+
 * preprocess_comment 丢弃 comment_author_url 入参（提交面——curl 可伪造面收口）；
 * 存量 comment_author_url 零接触。
 *
 * U1-B（定稿 §1/§0.1）：get_comment_author_link + get_comment_author_url 双挂点
 * ——前台返回纯文本名字；后台（is_admin）照常链接（站长识别邻居核心语义）。
 * 【M5FIX1】get_comment_author_url 补挂：WP core 区块 wp-block-comment-author-name
 * （core/blocks 源码）直接用 get_comment_author_url() 拼锚点，绕过
 * get_comment_author_link filter——区块主题（twentytwentyfive 等）下存量带网址
 * 评论的作者名仍是链接（实站 4/11 条复现）。去链接激活时前台返回空串：区块在
 * url 为空时渲染纯文本名字；经典主题消费 comment_author_url 的场景同样收口。
 *
 * comment_form 回填影响面（取舍注记）：前台表单 url 字段 value 来自
 * wp_get_current_commenter() 的 cookie（comment_author_url_* cookie），不走
 * get_comment_author_url filter——表单回填不受本 filter 影响；用户重新提交的
 * url 是否收集由 A 开关（site_field.enabled）决定，A 开启时表单字段照常显示
 * （A/B 完全独立语义不变）。
 *
 * U2（定稿 §2）：pre_get_avatar_data 短路改写 args['url']（WP 契约：filter 返回的
 * url 非空则核心跳过官方 URL 生成）——镜像不可解析时原样返回（官方源现行行为）。
 *
 * @package WinkGatekeeper\Site
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Site;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（S2-F1 纵深加固——类文件直访零执行，守卫为纵深面）。
}

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
	 * 【M6·P1-1】签名与 core 实签名对齐：WP core 7.1.4 `get_comment_author_link`
	 * filter 三参**实签名** =（$link, 作者名字符串, 数字串 comment_ID）
	 * （comment-template.php:292 容器实源）——旧实现 `int $comment_id` 提示下
	 * strict_types 参数绑定即 TypeError（仪表盘 Activity critical error+wink 三卡
	 * widget 零渲染+经典主题前台评论/RSS 同炸；审计 P1-1 实锤）。**第 2 参不得带
	 * 类型提示**；方法体按实参形态鲁棒分派（resolve_comment_target 四形态）。
	 *
	 * 【M6·S1-F1】无链接分支收口：返回作者名经 esc_html（本 filter 消费语境=HTML
	 * 输出面，core get_comment_author_link 返回值被主题直接 echo 是主流形态——
	 * 不转义时 kses 白名单内标记可随作者名注入）。主题若自行二次转义（对返回值
	 * 再 esc_html），可挂 `wink_gatekeeper_site_enhance_author_raw` 过滤器返回
	 * 真值豁免（原文返回不转义）。
	 *
	 * @param mixed                     $link         当前作者链接 HTML。
	 * @param mixed                     $author_or_id 作者名字符串（core 实形态第 2 参）/数字 id（防御形态）。
	 * @param \WP_Comment|\stdClass|int|string|null $comment 评论对象或数字串 id（core 实形态第 3 参）。
	 * @return mixed
	 */
	public function filter_comment_author_link( $link, $author_or_id = 0, $comment = null ) {
		if ( $this->front_link ) {
			return $link;
		}
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return $link; // 后台照常显示可点链接（0.1 语义核心）。
		}
		if ( ! function_exists( 'get_comment_author' ) ) {
			return $link; // 纯单测环境守卫（边界层 WP 函数不在时零行为）。
		}
		$target = $this->resolve_comment_target( $author_or_id, $comment );
		if ( null === $target ) {
			return $link; // 形态④：定位不到评论——fail-open 渲染（绝不因签名形态炸渲染链）。
		}
		$comment_obj = is_object( $target ) ? $target : get_comment( $target );
		if ( ! is_object( $comment_obj ) ) {
			return $link; // 评论已删/id 落空——fail-open 原样返回。
		}
		$name = get_comment_author( $comment_obj );
		if ( ! is_string( $name ) || '' === $name ) {
			return $link;
		}
		$raw = function_exists( 'apply_filters' )
			? (bool) apply_filters( 'wink_gatekeeper_site_enhance_author_raw', false, $name )
			: false;
		return $raw ? $name : esc_html( $name );
	}

	/**
	 * 评论目标解析（P1-1 四形态分派——纯逻辑可测）。
	 *
	 * ① core 实形态：作者名字符串在第 2 参、数字串 id 在第 3 参 → 以第 3 参为 id；
	 * ② 第 3 参是评论对象（WP_Comment/stdClass）→ 直接用；
	 * ③ 第 2 参是数字（docblock 历史形态/其它调用方防御）→ 以第 2 参为 id；
	 * ④ 全部落空 → null（调用方原样返回 $link）。
	 *
	 * @param mixed $author_or_id 第 2 参。
	 * @param mixed $comment      第 3 参。
	 * @return object|int|string|null 评论对象、id，或 null=不可解析。
	 */
	private function resolve_comment_target( $author_or_id, $comment ) {
		if ( is_object( $comment ) ) {
			return $comment; // 形态②。
		}
		if ( is_numeric( $comment ) && ! is_numeric( $author_or_id ) ) {
			return (int) $comment; // 形态①（core 7.1.4 实形态）。
		}
		if ( is_numeric( $author_or_id ) && (int) $author_or_id > 0 ) {
			return (int) $author_or_id; // 形态③。
		}
		return null; // 形态④。
	}

	/**
	 * 【M5FIX1】U1-B 区块主题收口：get_comment_author_url 清空（前台去链接激活时）。
	 *
	 * WP core 区块 wp-block-comment-author-name 直接消费本 filter 的返回值拼锚点
	 * （绕过 get_comment_author_link）——去链接激活且前台（!is_admin）时返回空串：
	 * 区块在 url 空时渲染纯文本名字，经典主题消费 comment_author_url 的场景同样
	 * 收口。语义与 filter_comment_author_link 同构：B 开启原样返回 / 后台原样
	 * （后台评论区照常可点——0.1 语义核心）。
	 *
	 * comment_form 回填影响面见类 docblock（url 字段 value 走 cookie 不走本 filter，
	 * A/B 独立语义不变）。
	 *
	 * @param mixed $url 当前作者 URL（get_comment_author_url 单参形态）。
	 * @return mixed
	 */
	public function filter_comment_author_url( $url ) {
		if ( $this->front_link ) {
			return $url; // B 开启：前台保留链接，零行为变化。
		}
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return $url; // 后台照常（站长识别邻居——与 filter_comment_author_link 同构）。
		}
		return ''; // 前台去链接：区块拼锚点拿空 url → 渲染纯文本名字。
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
