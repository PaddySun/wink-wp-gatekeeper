<?php
/**
 * M6 批 A · P1-1 SiteEnhance `filter_comment_author_link` 签名错配修复回归测试（2026-11-15）。
 *
 * 根因（审计 P1-1，P3-F4 实锤）：WP core 7.1.4 `get_comment_author_link` filter 三参
 * **实签名** =（$link, 作者名字符串, 数字串 comment_id）（comment-template.php:292 容器实源）
 * ——旧实现声明 `int $comment_id` 类型提示：strict_types 下作者名字符串（非数字串）
 * 绑定 int 即 TypeError → 仪表盘 Activity critical error + wink 三卡 widget 零渲染
 * + 经典主题前台评论/RSS 同炸（区块主题走 get_comment_author_url 通道掩盖）。
 *
 * 修复契约（M6 定稿 §2 A-1）：签名 `( $link, $author_or_id = 0, $comment = null )` 去
 * int 提示；方法体鲁棒支持四种实参形态：
 *   ① core 实形态（作者名在第 2 参、数字串 id 在第 3 参）→ 以第 3 参为 id 取评论对象；
 *   ② 第 3 参是评论对象（WP_Comment/stdClass）→ 直接用；
 *   ③ 第 2 参是数字（防御其它调用方/docblock 历史形态）→ 以第 2 参为 id；
 *   ④ 全部取不到 → 原样返回 $link（fail-open 渲染——绝不因签名形态炸渲染链）。
 *
 * 附带修（审计 S1-F1）：无链接分支返回 get_comment_author() 原文未转义——本批收口为
 * 输出面 esc_html（消费语境=HTML 输出面；core get_comment_author_link 返回值被主题
 * 直接 echo 不二次转义是主流形态），主题二次转义场景可
 * `wink_gatekeeper_site_enhance_author_raw` 过滤器豁免（返回真值=原样返回不转义）。
 *
 * 桩注记：SiteEnhance 以 `function_exists()`（**全局**函数名）守卫 WP 边界——桩必须
 * 定义为全局函数（命名空间桩不改变 function_exists 判定）。四个桩全部取「无 filter
 * 注册」语义最小面：apply_filters 直通返回原值（与单测环境无 filter 等价——
 * CommentGate:325/AccountFormGate:127 的 function_exists 守卫分支两臂语义一致，
 * 零行为漂移）；esc_html=htmlspecialchars ENT_QUOTES（WP 同款）；get_comment/
 * get_comment_author 查桩评论表。is_admin() 刻意不定义（守卫=单测环境恒前台语境）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace {
	$GLOBALS['wk_site_comments'] = array();   // 桩评论表：id => 对象。
	$GLOBALS['wk_site_filters']  = array();   // 桩 filter 注册表：tag => callable[]。

	if ( ! function_exists( 'get_comment' ) ) {
		/**
		 * get_comment 桩：id 查桩评论表，缺失返 null（模拟 core 语义）。
		 *
		 * @param int|string $id 评论 id。
		 * @return object|null
		 */
		function get_comment( $id ) {
			return $GLOBALS['wk_site_comments'][ (int) $id ] ?? null;
		}
	}

	if ( ! function_exists( 'get_comment_author' ) ) {
		/**
		 * get_comment_author 桩：对象→取 comment_author；id→查表取。
		 *
		 * @param object|int|null $comment 评论对象或 id。
		 * @return string
		 */
		function get_comment_author( $comment = null ): string {
			if ( is_object( $comment ) ) {
				return (string) ( $comment->comment_author ?? '' );
			}
			$object = get_comment( (int) $comment );
			return is_object( $object ) ? (string) ( $object->comment_author ?? '' ) : '';
		}
	}

	if ( ! function_exists( 'esc_html' ) ) {
		/**
		 * esc_html 桩：htmlspecialchars ENT_QUOTES 双编码（WP 同款最小面）。
		 *
		 * @param string $text 文本。
		 * @return string
		 */
		function esc_html( string $text ): string {
			return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		/**
		 * apply_filters 桩：执行桩注册表回调（缺省空=值原样返回——无 filter 语义）。
		 *
		 * @param string $tag   filter 名。
		 * @param mixed  $value 值。
		 * @return mixed
		 */
		function apply_filters( string $tag, $value ) {
			foreach ( $GLOBALS['wk_site_filters'][ $tag ] ?? array() as $callback ) {
				$value = $callback( $value );
			}
			return $value;
		}
	}
}

namespace WinkGatekeeper\Tests {


	use PHPUnit\Framework\TestCase;
	use WinkGatekeeper\Settings\SettingsSchema;
	use WinkGatekeeper\Site\SiteEnhance;

	final class M6ASiteEnhanceSignatureTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['wk_site_comments'] = array();
			$GLOBALS['wk_site_filters']  = array();
		}

		/**
		 * @param bool $keep_link site_link.enabled（true=前台保留链接）。
		 * @return SiteEnhance
		 */
		private function enhance( bool $keep_link = false ): SiteEnhance {
			$settings = SettingsSchema::defaults();
			$settings['comments']['site_link']['enabled'] = $keep_link;
			return new SiteEnhance( $settings );
		}

		/**
		 * 桩评论表种一条。
		 *
		 * @param int    $id     评论 id。
		 * @param string $author 作者名。
		 * @return object
		 */
		private function seed_comment( int $id, string $author ): object {
			$comment                                       = new \stdClass();
			$comment->comment_ID                           = $id;
			$comment->comment_author                       = $author;
			$GLOBALS['wk_site_comments'][ $id ]            = $comment;
			return $comment;
		}

		/* ===============================================================
		 * 签名面（P1-1 主修）：去 int 提示——core 实参形态不再 TypeError
		 * =============================================================== */

		public function test_second_parameter_has_no_int_type_hint(): void {
			$ref  = new \ReflectionMethod( SiteEnhance::class, 'filter_comment_author_link' );
			$type = $ref->getParameters()[1]->getType();
			self::assertNull(
				$type,
				'第 2 参不得带类型提示——core 以作者名字符串实参（strict_types 下 int 提示即 TypeError，审计 P1-1 主证）'
			);
		}

		/* ===============================================================
		 * 形态① core 实形态：('链接','作者名','123') 数字串 id 在第 3 参
		 * =============================================================== */

		public function test_core_real_form_returns_plain_author_name(): void {
			$this->seed_comment( 123, '张三' );

			$out = $this->enhance()->filter_comment_author_link( '<a href="https://x.example">张三</a>', '张三', '123' );

			self::assertSame( '张三', $out, 'core 实形态：以第 3 参 id 取评论 → 返回纯文本作者名（去链接语义）' );
		}

		public function test_core_real_form_escapes_author_name(): void {
			// S1-F1 收口：作者名带 kses 白名单内标记 → 输出面 esc_html（本 filter 消费语境=HTML）。
			$this->seed_comment( 201, '甲<b>粗</b>&乙' );

			$out = $this->enhance()->filter_comment_author_link( '链接', '甲<b>粗</b>&乙', '201' );

			self::assertSame( '甲&lt;b&gt;粗&lt;/b&gt;&amp;乙', $out, '无链接分支作者名必须转义出 filter（S1-F1 收口）' );
		}

		public function test_core_real_form_missing_comment_fails_open(): void {
			// 评论已删/取不到 → 原样返回 $link（fail-open 渲染——渲染链绝不因签名形态炸）。
			$link = '<a href="https://x.example">someone</a>';
			self::assertSame( $link, $this->enhance()->filter_comment_author_link( $link, 'someone', '999' ) );
		}

		/* ===============================================================
		 * 形态② 第 3 参评论对象（WP_Comment/stdClass）直接用
		 * =============================================================== */

		public function test_object_third_param_used_directly(): void {
			$comment = $this->seed_comment( 77, '对象作者' );

			$out = $this->enhance()->filter_comment_author_link( '链接', 77, $comment );

			self::assertSame( '对象作者', $out, '第 3 参是评论对象 → 直接用（不经 get_comment 再查）' );
		}

		/* ===============================================================
		 * 形态③ 第 2 参数字（docblock 历史形态/其它调用方防御）
		 * =============================================================== */

		public function test_numeric_second_param_used_as_id(): void {
			$this->seed_comment( 456, '防御作者' );

			$out = $this->enhance()->filter_comment_author_link( '链接', '456' );

			self::assertSame( '防御作者', $out, '第 2 参是数字 id → 以第 2 参取评论（历史 docblock 形态兼容）' );
		}

		public function test_int_second_param_used_as_id(): void {
			$this->seed_comment( 78, '整数防御作者' );

			$out = $this->enhance()->filter_comment_author_link( '链接', 78 );

			self::assertSame( '整数防御作者', $out );
		}

		/* ===============================================================
		 * 形态④ 全部取不到 → fail-open 原样返回
		 * =============================================================== */

		public function test_unresolvable_returns_link_unchanged(): void {
			// 作者名字符串在第 2 参且无第 3 参（缺参形态）→ 无法定位评论 → 原样返回。
			$link = '<a href="https://x.example">匿名</a>';
			self::assertSame( $link, $this->enhance()->filter_comment_author_link( $link, '匿名' ) );
		}

		public function test_default_no_args_beyond_link_returns_link(): void {
			// 单参调用（accepted_args=1 的第三方注册形态）→ 零行为变化。
			$link = '<a href="https://x.example">x</a>';
			self::assertSame( $link, $this->enhance()->filter_comment_author_link( $link ) );
		}

		/* ===============================================================
		 * 豁免过滤器：wink_gatekeeper_site_enhance_author_raw（主题二次转义场景）
		 * =============================================================== */

		public function test_author_raw_exemption_filter_returns_unescaped_name(): void {
			$GLOBALS['wk_site_filters']['wink_gatekeeper_site_enhance_author_raw'] = array(
				static function (): bool {
					return true;
				},
			);
			$this->seed_comment( 301, '李四&<b>' );

			$out = $this->enhance()->filter_comment_author_link( '链接', '李四&<b>', '301' );

			self::assertSame( '李四&<b>', $out, '豁免 filter 真值=原文返回（主题自行转义的场景）' );
		}

		/* ===============================================================
		 * 既有语义回归：B 开启/后台语境零行为变化 + Bootstrap 注册保持
		 * =============================================================== */

		public function test_link_kept_returns_link_unchanged(): void {
			$link = '<a href="https://x.example">张三</a>';
			self::assertSame( $link, $this->enhance( true )->filter_comment_author_link( $link, '张三', '123' ), 'B 开启=前台保留链接零行为变化' );
		}

		public function test_bootstrap_registration_keeps_accepted_args_3(): void {
			$src = file_get_contents( dirname( __DIR__ ) . '/includes/Bootstrap.php' );
			self::assertIsString( $src );
			self::assertStringContainsString(
				"add_filter( 'get_comment_author_link', array( \$site_enhance, 'filter_comment_author_link' ), 10, 3 );",
				$src,
				'Bootstrap 注册行 accepted_args=3 保持（core 三参实形态依赖第 3 参取 id）'
			);
		}

		public function test_method_docblock_documents_core_real_signature(): void {
			// docblock 同步纠正（防 docblock 形态复发——审计 P1-1 根因之一）。
			$src = file_get_contents( dirname( __DIR__ ) . '/includes/site/SiteEnhance.php' );
			self::assertIsString( $src );
			self::assertStringContainsString( '作者名字符串', $src, 'docblock 须写明 core 实签名（第 2 参=作者名字符串）' );
			self::assertStringContainsString( 'wink_gatekeeper_site_enhance_author_raw', $src, '豁免 filter 须在 docblock 注记' );
		}
	}
}
