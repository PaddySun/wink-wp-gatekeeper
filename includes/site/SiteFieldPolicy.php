<?php
/**
 * U1 「网站」字段两独立开关·纯判定层（M5 定稿 §1/§0.1）。
 *
 * 语义权威（定稿 §0.1 唯一拍板）：A（收集开关）与 B（前台链接开关）**完全独立、互不
 * 关联**；数据库 comment_author_url 永不删除——本类只提供提交面丢弃与前台展示改写
 * 两类纯判定，不提供任何存量删除/改写面。
 *
 * 键语义（定稿 §1 表格唯一权威）：
 *   comments.site_field.enabled  true=收集新评论的网站字段（默认 false=关闭收集，
 *                                防 spam 采集面）；
 *   comments.site_link.enabled   true=前台评论者名字保留可点链接（默认 false=前台
 *                                去链接；后台评论区照常显示链接——站长识别邻居）。
 *
 * @package WinkGatekeeper\Site
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Site;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 禁止直接访问（S2-F1 纵深加固——类文件直访零执行，守卫为纵深面）。
}

final class SiteFieldPolicy {

	/**
	 * A 开关：是否收集新评论的网站字段（comment_author_url）。
	 *
	 * @param array<string, mixed> $settings merge 后整树。
	 * @return bool true=收集；false=关闭收集（表单隐藏字段+提交入参丢弃双保险）。
	 */
	public static function wants_collection( array $settings ): bool {
		return ! empty( $settings['comments']['site_field']['enabled'] );
	}

	/**
	 * B 开关：前台评论者名字是否保留可点链接。
	 *
	 * @param array<string, mixed> $settings merge 后整树。
	 * @return bool true=保留链接；false=前台去链接（后台不受影响）。
	 */
	public static function wants_frontend_link( array $settings ): bool {
		return ! empty( $settings['comments']['site_link']['enabled'] );
	}

	/**
	 * 提交面丢弃 comment_author_url（A 关闭时的双保险——前端隐藏 ≠ curl 不可伪造）。
	 *
	 * 只删入参键、其余原样；无该键（登录用户代填等形态）原样返回不新增。
	 * 存量评论零接触。
	 *
	 * @param array<string, mixed> $comment_data preprocess_comment 入参。
	 * @return array<string, mixed>
	 */
	public static function strip_url( array $comment_data ): array {
		unset( $comment_data['comment_author_url'] );
		return $comment_data;
	}
}
