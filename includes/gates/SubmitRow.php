<?php
/**
 * 提交区并排注入（03 §2.1 v1.3：验证按钮与「发表评论」提交按钮并排、自适应）。
 *
 * comment_form_submit_field filter 收到的是主题提交区 HTML（默认形态
 * `<p class="form-submit"><input type="submit" …> …hidden 域…</p>`）。并排落地法：
 * 定位首个提交控件（input/button 的 type=submit），把闸容器（inline 级 span）插到
 * 控件之前、同一提交段落之内——外层 .wk-gate{display:flex} 与主题按钮同行；
 * 未找到提交控件的主题自定义形态 → 回退 v1.2 前置形态（堆叠兼容，不赌主题 DOM）。
 *
 * 纯 PHP 零 WP 依赖（单测覆盖）。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

final class SubmitRow {

	/**
	 * 注入点探测：input/button 元素的 type=submit 属性（属性序不定）。
	 * 捕获组 1 = 整个控件开标签（插入位置 = 其起点）。
	 */
	private const SUBMIT_CONTROL = '/<(?:input|button)\b[^>]*\btype=(["\'])submit\1[^>]*>/i';

	/**
	 * 注入（M2-FIX2 D1 v1.4：行内段插提交控件之前 + 块级尾段追加提交段落尾部）。
	 *
	 * 驱动 render() 产物可能含两段：行内 span（验证按钮组，并排 flex 行）+ 尾随块级
	 * div（嵌入式验证码挂载点 .wk-gate-embed——必须落在提交按钮「下方」：追加到
	 * 提交段落末尾，DOM 序在提交控件之后）。拆分法：首个 '<div' 之前 = 行内段。
	 *
	 * @param string $submit_field 提交区 HTML（filter 入参）。
	 * @param string $gate_html    闸容器 HTML（inline 级 span [+ 块级尾段]，驱动 render() 产物）。
	 * @return string
	 */
	public static function inject( string $submit_field, string $gate_html ): string {
		$tail_offset = strpos( $gate_html, '<div' );
		$inline_html = false === $tail_offset ? $gate_html : substr( $gate_html, 0, $tail_offset );
		$tail_html   = false === $tail_offset ? '' : substr( $gate_html, $tail_offset );

		if ( 1 !== preg_match( self::SUBMIT_CONTROL, $submit_field, $matches, PREG_OFFSET_CAPTURE ) ) {
			// 无提交控件（主题自定义形态）：回退前置（v1.2 堆叠形态，兼容不赌）。
			return $gate_html . "\n" . $submit_field;
		}

		$offset = (int) $matches[0][1];
		$out    = substr( $submit_field, 0, $offset ) . $inline_html . substr( $submit_field, $offset );
		if ( '' !== $tail_html ) {
			// 块级尾段（嵌入式挂载点）追加提交段落尾部：挑战面板出现在提交按钮下方。
			$out .= "\n" . $tail_html;
		}
		return $out;
	}
}
