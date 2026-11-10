<?php
/**
 * wp_die 品牌拦截页（03 §3，D4/F07）。
 *
 * build() 为纯 HTML 构造器（内联样式不依赖主题、logo 130ms 单次眨眼、
 * prefers-reduced-motion 静态、证据行等宽「作证」、back_link、16px 起字号）：
 *   - Paper #F7F7F8 底 / Wink Red #FF4D4F 强调 / Blush #FFF2F2 提示底 / Ink #17171B 正文；
 *   - 不向访客暴露（03 §3 清单）：IP、原始评论内容、驱动内部错误码细节、云端 reason
 *     （LLM 理由只进后台）——访客可见证据仅事件编号/规则名/时间。
 *
 * show() 为 WP 边界：wp_die($message, $title, ['back_link' => true])（不接管全局
 * wp_die_handler——避免兼容面，03 §3 决策）。REST 上下文不经此页（走 WP_Error → JSON）。
 *
 * @package WinkGatekeeper\Intercept
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Intercept;

final class DiePage {

	/**
	 * 构造拦截页 HTML（纯函数）。
	 *
	 * @param string                $title        页面标题（已过文案工坊）。
	 * @param string                $message      台阶文案（已过占位符替换）。
	 * @param array<string, string> $evidence     证据行（键 = 中文标签前显示名，值 = 等宽呈证；
	 *                                             调用方保证仅 event_no/rule/time 三类）。
	 * @param string                $contact_email 联系邮箱（空串则不渲染联系行）。
	 * @param string                $logo_url     logo 地址。
	 * @param string                $lang         <html lang>（跟随站点 locale）。
	 * @return string
	 */
	public static function build( string $title, string $message, array $evidence, string $contact_email, string $logo_url, string $lang ): string {
		$e = static function ( string $value ): string {
			return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
		};

		$evidence_rows = '';
		if ( array() !== $evidence ) {
			foreach ( $evidence as $label => $value ) {
				$evidence_rows .= '<tr><th scope="row">' . $e( (string) $label ) . '</th><td class="wk-ev">' . $e( (string) $value ) . '</td></tr>';
			}
			$evidence_rows = '<table class="wk-evidence" aria-label="证据">' . $evidence_rows . '</table>';
		}

		$contact = '';
		if ( '' !== $contact_email ) {
			$contact = '<p class="wk-contact">仍有疑问？联系站长：<a href="mailto:' . $e( $contact_email ) . '">' . $e( $contact_email ) . '</a></p>';
		}

		$logo = '';
		if ( '' !== $logo_url ) {
			$logo = '<img class="wk-logo" src="' . $e( $logo_url ) . '" alt="wink" width="40" height="45" decoding="async">';
		}

		return '<!DOCTYPE html><html lang="' . $e( $lang ) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . $e( $title ) . '</title>'
			. '<style>'
			. 'body{margin:0;padding:32px 16px;background:#F7F7F8;color:#17171B;'
			. 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;'
			. 'font-size:16px;line-height:1.75;display:flex;justify-content:center;}'
			// FIX-ε 2026-11-10（DEFECT-ROOTCAUSE P1-ε，证据 u2-1-tooshort.html）：wp_die 把整段
			// 文档嵌进宿主骨架 <body id="error-page">，宿主 body{max-width:700px;border;box-shadow;
			// margin:2em auto} 与 #error-page{margin-top:50px}（特异性 1,0,0）压过元素选择器——
			// id 选择器（1,0,1）+ 文档序靠后中和，独立文档语境（body 无该 id）不受影响。
			. 'body#error-page{max-width:none;margin:0;padding:32px 16px;border:0;box-shadow:none;display:flex;justify-content:center;}'
			. '.wk-card{max-width:520px;width:100%;background:#FFFFFF;border:1px solid #E8E8EC;border-radius:12px;'
			. 'padding:32px;text-align:center;}'
			. '.wk-logo{animation:wk-blink 130ms ease-in-out 1 both;}'
			. '@keyframes wk-blink{0%,100%{transform:none;}50%{transform:scaleY(0.08);}}'
			. '@media (prefers-reduced-motion: reduce){.wk-logo{animation:none;}}' // 动效降级：常睁眼。
			. 'h1{font-size:20px;font-weight:600;color:#17171B;margin:16px 0 8px;}'
			. 'p{margin:8px 0;}'
			. '.wk-msg{color:#3D3D46;}'
			. '.wk-evidence{width:100%;border-collapse:collapse;margin:20px 0 8px;border-top:1px solid #E8E8EC;border-bottom:1px solid #E8E8EC;}'
			. '.wk-evidence th,.wk-evidence td{padding:6px 8px;font-size:14px;text-align:left;font-weight:400;color:#6B6B76;}'
			. '.wk-ev{font-family:ui-monospace,SFMono-Regular,Consolas,"Courier New",monospace;color:#3D3D46;word-break:break-all;}' // 等宽「作证」。
			. '.wk-back{display:inline-block;margin-top:16px;padding:8px 18px;background:#FF4D4F;color:#FFFFFF;' // Wink Red。
			. 'border-radius:999px;text-decoration:none;font-size:16px;}'
			. '.wk-back:hover{background:#E43E40;}'
			. '.wk-contact{margin-top:20px;font-size:14px;color:#6B6B76;}'
			. '.wk-contact a{color:#FF4D4F;}'
			. '</style></head><body>'
			. '<div class="wk-card" role="alert">'
			. $logo
			. '<h1>' . $e( $title ) . '</h1>'
			. '<p class="wk-msg">' . $e( $message ) . '</p>'
			. $evidence_rows
			. '<a class="wk-back" href="javascript:history.back()">&larr; 返回上一页</a>'
			. $contact
			. '</div></body></html>';
	}

	/* -------------------------------------------------------------------
	 * WP 边界（wp_die 调用；单测环境不可达，静态自查清单覆盖）
	 * ------------------------------------------------------------------ */

	/**
	 * 存储文案子树归一（纯函数，单测覆盖）：自定义项覆盖默认、缺键回默认。
	 *
	 * 真机教训（M2 反馈2 T2，2026-11-10）：存量 option 的 copy.messages 只含保存时的键集
	 * （如旧八键）——新键（duplicate_comment 等）缺失时 show() 直接索引得 null 致
	 * Copywork::format() TypeError 品牌页 500。归一口径与闸侧 messages() 一致：
	 * defaults 打底 + 存储字符串项覆盖（非字符串项剔除）。
	 *
	 * @param array<string, mixed> $copy 设置树 copy 子树（可为空/部分）。
	 * @return array{messages: array<string,string>, titles: array<string,string>}
	 */
	public static function resolve_copy( array $copy ): array {
		$messages = isset( $copy['messages'] ) && is_array( $copy['messages'] )
			? array_merge( Copywork::defaults()['messages'], array_filter( $copy['messages'], 'is_string' ) )
			: Copywork::defaults()['messages'];
		$titles   = isset( $copy['titles'] ) && is_array( $copy['titles'] )
			? array_merge( Copywork::defaults()['titles'], array_filter( $copy['titles'], 'is_string' ) )
			: Copywork::defaults()['titles'];
		return array(
			'messages' => $messages,
			'titles'   => $titles,
		);
	}

	/**
	 * 站长自定义拦截页文案归一（【v1.5·B1】纯函数，单测覆盖）。
	 *
	 * 空值/非字符串/纯空白 = 空串（= 回落对应场景默认文案）；字符串取 trim 后值。
	 *
	 * @param array<string, mixed> $copy 设置树 copy 子树（可为空/部分）。
	 * @return array{die_page_title: string, die_page_message: string}
	 */
	public static function custom_copy( array $copy ): array {
		$out = array();
		foreach ( Copywork::custom_defaults() as $key => $unused ) {
			$value = $copy[ $key ] ?? '';
			$out[ $key ] = ( is_string( $value ) && '' !== trim( $value ) ) ? trim( $value ) : '';
		}
		return $out;
	}

	/**
	 * 拦截页标题/正文解析（【v1.5·B1】纯函数，单测覆盖）：
	 * 场景默认文案（resolve_copy 语义沿袭：defaults 打底 + 存储字符串项覆盖）→
	 * 站长自定义覆盖位（die_page_title/die_page_message 非空时全场景共用覆盖）→
	 * 占位符代入（体系同享：{event_no}/{rule}/{time}/{contact_email}；缺失占位符保留原文）。
	 *
	 * @param string               $copy_key 文案键（Copywork::keys() 之一）。
	 * @param array<string, mixed> $copy     设置树 copy 子树（可为空/部分）。
	 * @param array<string, mixed> $vars     占位符变量。
	 * @return array{title: string, message: string}
	 */
	public static function resolve_page_text( string $copy_key, array $copy, array $vars ): array {
		$resolved = self::resolve_copy( $copy );
		$messages = $resolved['messages'];
		$titles   = $resolved['titles'];

		$message = Copywork::format( is_string( $messages[ $copy_key ] ?? '' ) ? $messages[ $copy_key ] : '', $vars );
		$title   = is_string( $titles[ $copy_key ] ?? null ) ? Copywork::format( $titles[ $copy_key ], $vars ) : '';

		$custom = self::custom_copy( $copy );
		if ( '' !== $custom['die_page_title'] ) {
			$title = Copywork::format( $custom['die_page_title'], $vars );
		}
		if ( '' !== $custom['die_page_message'] ) {
			$message = Copywork::format( $custom['die_page_message'], $vars );
		}

		return array(
			'title'   => $title,
			'message' => $message,
		);
	}

	/**
	 * 拦截落地（D4）：读设置 → 文案工坊格式化 → wp_die 品牌页 + back_link。
	 *
	 * @param string                $copy_key 文案键（Copywork::keys() 之一）。
	 * @param array<string, mixed>  $vars     占位符变量（event_no/rule/time/min_length/…）。
	 * @param array<string, string> $evidence 证据行（标签 => 值）。
	 * @return void
	 */
	public static function show( string $copy_key, array $vars = array(), array $evidence = array() ): void {
		$settings = get_option( 'wink_gatekeeper_settings', array() );
		$copy     = is_array( $settings['copy'] ?? null ) ? $settings['copy'] : array();

		$contact = isset( $settings['brand']['contact_email'] ) && is_string( $settings['brand']['contact_email'] )
			? $settings['brand']['contact_email']
			: '';

		// 拦截文案含联系邮箱占位符时全局兜底注入（自定义覆盖位同享占位符体系——v1.5·B1）。
		if ( '' !== $contact ) {
			$vars['contact_email'] = $contact;
		}

		$page    = self::resolve_page_text( $copy_key, $copy, $vars );
		$message = $page['message'];
		$title   = $page['title'];

		wp_die(
			self::build( $title, $message, $evidence, $contact, WINK_GATEKEEPER_URL . 'assets/brand/logo-wink.svg', get_locale() ),
			$title,
			array( 'back_link' => false ) // back_link 由页内品牌按钮承担（03 §3 版式），不经 WP 默认返回链接。
		);
	}
}
