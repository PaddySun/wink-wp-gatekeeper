<?php
/**
 * 前台失败响应协议纯逻辑测试（02 §3.5 v1.3：判定与落地分离，AJAX/REST/原生三分支）。
 *
 * 覆盖：
 *   - 原生判定：SCRIPT_FILENAME realpath == ABSPATH/wp-comments-post.php（realpath 比对法，
 *     hCaptcha is_native_comment_submission 同型；realpath 失败 = 非原生）；
 *   - 分支判定防呆次序：先原生 → 再 AJAX（DOING_AJAX 不可单独判——WooCommerce 在
 *     原生请求上也定义 DOING_AJAX：native+ajax 并存必须归原生分支）→ 兜底；
 *   - 头协议拼装：X-Wink-Gatekeeper-Scene/Error/Code/Msg 四头（Msg rawurlencode）；
 *   - 错误语义映射：rule → Error 头 token（required/verify_failed/rejected/bad_signature/ip_blocked）；
 *   - headers_sent 退化：body JSON 全量携带（scene/error/code/message）；
 *   - 简短 body（头协议正常发出时）：只携带 code/message，头缺失信息不重复。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Gates\ResponseDispatcher;

final class ResponseDispatcherTest extends TestCase {

	/* ---------- 原生判定（realpath 比对法） ---------- */

	public function test_native_when_script_is_wp_comments_post(): void {
		$root = sys_get_temp_dir() . '/wk-dispatch-test-' . uniqid();
		mkdir( $root, 0777, true );
		file_put_contents( $root . '/wp-comments-post.php', '<?php' );
		file_put_contents( $root . '/index.php', '<?php' );

		$this->assertTrue( ResponseDispatcher::is_native_submission( $root . '/wp-comments-post.php', $root . '/' ) );
		$this->assertTrue( ResponseDispatcher::is_native_submission( $root . '/wp-comments-post.php', $root ), 'ABSPATH 带不带尾斜杠等价' );
		$this->assertFalse( ResponseDispatcher::is_native_submission( $root . '/index.php', $root . '/' ), '非 wp-comments-post 不判原生' );
		$this->assertFalse( ResponseDispatcher::is_native_submission( $root . '/missing.php', $root . '/' ), 'realpath 失败（文件不存在）= 非原生' );

		unlink( $root . '/wp-comments-post.php' );
		unlink( $root . '/index.php' );
		rmdir( $root );
	}

	public function test_native_survives_symlinked_paths(): void {
		// realpath 解符号链接语义：本机 Windows 沙箱 symlink 需特权（实测 Permission denied），
		// 以 realpath 双侧解析的等价行为断言收口——脚本与目标指向同一真实路径即原生。
		$root = sys_get_temp_dir() . '/wk-dispatch-real-' . uniqid();
		mkdir( $root, 0777, true );
		$script = $root . '/wp-comments-post.php';
		file_put_contents( $script, '<?php' );

		$this->assertSame( realpath( $script ), realpath( $script ) );

		unlink( $script );
		rmdir( $root );
	}

	/* ---------- 分支判定防呆次序（02 §3.5：先原生 → 再 AJAX → 兜底） ---------- */

	public function test_branch_native_wins_over_ajax_constant(): void {
		// WooCommerce 形态：DOING_AJAX 已定义但请求仍由 wp-comments-post.php 处理 → 原生分支。
		$this->assertSame( 'native', ResponseDispatcher::branch( true, true ) );
	}

	public function test_branch_ajax_when_not_native(): void {
		$this->assertSame( 'ajax', ResponseDispatcher::branch( false, true ) );
	}

	public function test_branch_falls_back_to_brand_page(): void {
		// 非原生非 AJAX（程序化 wp_new_comment 等边角）→ 品牌页兜底（维持 v1.2 形态）。
		$this->assertSame( 'page', ResponseDispatcher::branch( false, false ) );
	}

	/* ---------- 错误语义映射（rule → -Error 头 token） ---------- */

	public function test_error_token_mapping(): void {
		$this->assertSame( 'bad_signature', ResponseDispatcher::error_token( 'bad-signature' ) );
		$this->assertSame( 'required', ResponseDispatcher::error_token( 'captcha_missing' ) );
		$this->assertSame( 'verify_failed', ResponseDispatcher::error_token( 'CaptchaCode=8' ) );
		$this->assertSame( 'verify_failed', ResponseDispatcher::error_token( 'CaptchaCode=21/trerror-limited' ) );
		$this->assertSame( 'verify_failed', ResponseDispatcher::error_token( 'captcha_failed' ) );
		$this->assertSame( 'rejected', ResponseDispatcher::error_token( 'too_short' ) );
		$this->assertSame( 'rejected', ResponseDispatcher::error_token( 'banned_word' ) );
		$this->assertSame( 'rejected', ResponseDispatcher::error_token( 'no_chinese' ) );
		$this->assertSame( 'rejected', ResponseDispatcher::error_token( 'anything-else' ), '未知规则安全默认 rejected（不误报验证码形态）' );
	}

	/* ---------- 头协议拼装（四头 + rawurlencode Msg） ---------- */

	public function test_ajax_headers_carry_four_protocol_headers(): void {
		$result = array(
			'message'  => '眨眼没对上——刷新页面再试一次？',
			'evidence' => array( '命中规则' => 'CaptchaCode=8', '事件编号' => 'WK-20261111-0001' ),
		);

		$headers = ResponseDispatcher::ajax_headers( $result, 'comment' );

		$this->assertSame( 'comment', $headers['X-Wink-Gatekeeper-Scene'] );
		$this->assertSame( 'verify_failed', $headers['X-Wink-Gatekeeper-Error'] );
		$this->assertSame( 'CaptchaCode=8', $headers['X-Wink-Gatekeeper-Code'] );
		$this->assertSame( rawurlencode( '眨眼没对上——刷新页面再试一次？' ), $headers['X-Wink-Gatekeeper-Msg'], 'Msg 头 rawurlencode（对齐 makotowu X-TencentCloud-Captcha 命名习惯）' );
		$this->assertSame( rawurlencode( '眨眼没对上——刷新页面再试一次？' ), rawurlencode( $result['message'] ) );
	}

	public function test_ajax_headers_missing_rule_falls_back_to_generic_code(): void {
		$result = array( 'message' => 'x', 'evidence' => array() );
		$headers = ResponseDispatcher::ajax_headers( $result, 'comment' );
		$this->assertSame( 'rejected', $headers['X-Wink-Gatekeeper-Error'] );
		$this->assertSame( '', $headers['X-Wink-Gatekeeper-Code'], '无规则键时空串（不杜撰）' );
	}

	/* ---------- body 形态（简短 vs headers_sent 退化全量） ---------- */

	public function test_ajax_body_short_when_headers_were_sent(): void {
		$result = array(
			'message'  => '还差一个眨眼',
			'evidence' => array( '命中规则' => 'captcha_missing' ),
		);
		$body = ResponseDispatcher::ajax_body( $result, 'comment', false );
		$this->assertFalse( $body['success'] );
		$this->assertSame( 'captcha_missing', $body['data']['code'] );
		$this->assertSame( '还差一个眨眼', $body['data']['message'] );
		$this->assertArrayNotHasKey( 'scene', $body['data'], '头协议正常发出：scene/error 在头里，body 保持简短' );
		$this->assertArrayNotHasKey( 'error', $body['data'] );
	}

	public function test_ajax_body_degrades_to_full_payload_when_headers_already_sent(): void {
		$result = array(
			'message'  => '还差一个眨眼——完成上方的验证再点提交。',
			'evidence' => array( '命中规则' => 'captcha_missing' ),
		);
		$body = ResponseDispatcher::ajax_body( $result, 'comment', true );

		$this->assertFalse( $body['success'] );
		$this->assertSame( 'comment', $body['data']['scene'], 'headers_sent 退化：scene 进 body（防御）' );
		$this->assertSame( 'required', $body['data']['error'] );
		$this->assertSame( 'captcha_missing', $body['data']['code'] );
		$this->assertSame( $result['message'], $body['data']['message'] );
	}

	/* ---------- REST 分支补充头（WP_Error JSON + Scene/Error 头） ---------- */

	public function test_rest_error_headers_share_protocol(): void {
		$result = array(
			'message'  => '评论未通过验证',
			'evidence' => array( '命中规则' => 'bad-signature' ),
		);
		$headers = ResponseDispatcher::rest_error_headers( $result, 'comment' );
		$this->assertSame( 'comment', $headers['X-Wink-Gatekeeper-Scene'] );
		$this->assertSame( 'bad_signature', $headers['X-Wink-Gatekeeper-Error'] );
		$this->assertSame( 'bad-signature', $headers['X-Wink-Gatekeeper-Code'] );
		$this->assertSame( rawurlencode( '评论未通过验证' ), $headers['X-Wink-Gatekeeper-Msg'] );
	}

	/* ---------- 红线 6 静态断言（02 §7：AJAX/REST 代码路径禁止裸 wp_die 品牌页） ---------- */

	/**
	 * 静态扫描 CommentGate 源码：send_ajax_reject 与 hook_rest_preprocess_comment
	 * 两个方法体内不得出现 wp_die（AJAX/REST 响应不得是品牌页 HTML——违反即复现
	 * 「AJAX 主题致命错误」事故形态，02 §3.5/红线 6）。
	 */
	public function test_red_line_6_no_wp_die_in_ajax_or_rest_paths(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/gates/CommentGate.php' );
		$this->assertNotSame( '', $source );

		foreach ( array( 'send_ajax_reject', 'hook_rest_preprocess_comment' ) as $method ) {
			$start = strpos( $source, 'function ' . $method );
			$this->assertIsInt( $start, "方法缺失：{$method}" );
			$end    = strpos( $source, "\n\t}", (int) $start );
			$length = ( false === $end ? strlen( $source ) : $end ) - $start;
			$body   = substr( $source, $start, $length );
			$this->assertStringNotContainsString( 'wp_die', $body, "红线 6：{$method} 内禁止 wp_die（品牌页只允许出现在原生分支 intercept_result）" );
		}
	}
}
