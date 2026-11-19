<?php
/**
 * i18n 批 I-1 · 架构件红先测试（波 5 全量英文化改造，纯增量零运行时行为）。
 *
 * 五面覆盖（TDD 先红后绿——先写本测试跑红存证，再落地架构件跑绿）：
 *   ① languages/ 目录 + pot 首跑入库：languages/wink-wp-gatekeeper.pot 存在、
 *      非空、^msgid 行数 === 864（中文 key 形态过渡产物——【批 I-2 key 化后
 *      翻转为新条数】，本钉仅对批 I-1 首跑形态负责）；
 *   ② pot 模板形态：抽查首个非头部条目为 msgid "…" 紧跟 msgstr "" 的空翻译
 *      模板（pot=翻译模板，msgstr 全空）；
 *   ③ phpcs.xml.dist text_domain 配置翻转：value wink-gatekeeper →
 *      wink-wp-gatekeeper（=== 严格断言，旧值零残留）——代码 domain 未翻，
 *      phpcs 预期红=批 I-4「零 error」验收的先红清单对照基线；
 *   ④ includes/Bootstrap.php 文本域接线在位：load_plugin_textdomain( 调用
 *      存在 + '/languages' 相对路径拼接待存在（不 pin domain 值——批 I-2
 *      domain 翻转与 key 化同 pass 时另立 pin）；
 *   ⑤ .gitignore 策略 pin：pot/po/mo 三产物全部入库（自托管开箱即用），
 *      零忽略 languages/ 或 *.mo/*.po/*.pot 的生效规则行。
 *
 * 授权链：用户 2026-11-20「请无人值守持续推进」→ 统筹（波 5 i18n 摸底与
 * 拍板）→ 遣派本批 I-1（纯增量架构件，不动运行时、不动 989 个 i18n 调用点）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;

final class I18nInfraTest extends TestCase {

	/**
	 * 仓库根下相对路径源码读取（既有 source-scan 测试同形态）。
	 *
	 * @param string $rel 仓库根相对路径。
	 * @return string
	 */
	private static function src( string $rel ): string {
		$src = file_get_contents( dirname( __DIR__ ) . '/' . $rel );
		self::assertIsString( $src, "{$rel} 必须可读" );
		return (string) $src;
	}

	/* ---------- ①② languages/ + pot 首跑入库 ---------- */

	public function test_pot_file_exists_and_non_empty(): void {
		$path = dirname( __DIR__ ) . '/languages/wink-wp-gatekeeper.pot';
		self::assertFileExists( $path, '批 I-1 架构件：languages/wink-wp-gatekeeper.pot 首跑产物必须入库' );
		self::assertFileIsReadable( $path, 'pot 必须可读' );
		self::assertGreaterThan( 0, (int) filesize( $path ), 'pot 必须非空（wp-cli i18n make-pot 首跑成功）' );
	}

	public function test_pot_msgid_line_count(): void {
		$src   = self::src( 'languages/wink-wp-gatekeeper.pot' );
		$count = preg_match_all( '/^msgid /m', $src );
		self::assertIsInt( $count );
		// 【批 I-1】中文 key 形态过渡产物首跑实测条数（含头部空 msgid）：864。
		// 【批 I-2b key 化后英文 key pot】869 = 864 目录唯一键 + 4 插件头元数据
		// （Text Domain 翻新为 wink-wp-gatekeeper 后 make-pot 纳入 Name/Description/
		// PluginURI/Author）+ 1 头部空 msgid；msgctxt 4 条（call-count/error-count/
		// drill-active/audit-running）各计一行 msgid。
		self::assertSame( 1369, $count, 'pot ^msgid 行数 === 1369（i18n 批 I-3 数据面/JS/属性/_n 收编后：1368 唯一 msgid + 头部空 msgid；_n 18 处 msgid_plural 另计；插件头 4 元数据已英文化）' );
	}

	public function test_pot_first_entry_is_empty_translation_template(): void {
		$src   = self::src( 'languages/wink-wp-gatekeeper.pot' );
		$lines = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $src ) );

		// 抽查首个非头部条目（msgid 非空单行起头）——其翻译槽必须是空模板 msgstr ""。
		foreach ( $lines as $i => $line ) {
			if ( 1 === preg_match( '/^msgid "[^"]+"/', $line ) ) {
				$next = isset( $lines[ $i + 1 ] ) ? trim( $lines[ $i + 1 ] ) : '';
				self::assertSame( 'msgstr ""', $next, 'pot=翻译模板：首个非头部条目 msgid 后紧跟空翻译槽 msgstr ""' );
				return;
			}
		}
		self::fail( 'pot 无非头部 msgid 条目（make-pot 未抽取到任何字符串？）' );
	}

	/* ---------- ③ phpcs text_domain 配置翻转 ---------- */

	public function test_phpcs_text_domain_config_flipped_to_plugin_slug(): void {
		$src = self::src( 'phpcs.xml.dist' );

		self::assertStringContainsString(
			'<config name="text_domain" value="wink-wp-gatekeeper"/>',
			$src,
			'phpcs text_domain 配置已翻转为插件 slug wink-wp-gatekeeper（批 I-1 单行翻转）'
		);
		// === 严格翻转：旧值（代码旧 domain 同形）在配置面零残留。
		self::assertStringNotContainsString(
			'value="wink-gatekeeper"',
			$src,
			'phpcs text_domain 旧值 wink-gatekeeper 必须零残留（严格翻转）'
		);
	}

	/* ---------- ④ Bootstrap 文本域接线在位 ---------- */

	public function test_bootstrap_textdomain_wiring_present(): void {
		$src = self::src( 'includes/Bootstrap.php' );

		self::assertStringContainsString(
			'load_plugin_textdomain(',
			$src,
			'Bootstrap 文本域加载调用必须在位（批 I-1 不动运行时——仅钉存在性）'
		);
		self::assertStringContainsString(
			"'/languages'",
			$src,
			"文本域目录拼接 '/languages' 必须在位（languages/ 目录与 pot 同 slug 命名承接）"
		);
		// 注意：刻意不 pin domain 字面值——批 I-2 domain 翻转（wink-gatekeeper →
		// wink-wp-gatekeeper）与 key 化同 pass 时另立 pin，本批不动运行时代码。
	}

	/* ---------- ⑥ 批 I-2b key 化+domain 翻转+zh_CN 产线钉 ---------- */

	/**
	 * 批 I-2b：load_plugin_textdomain 首参=插件 slug 新 domain（domain 翻转钉）。
	 */
	public function test_bootstrap_loads_new_textdomain_slug(): void {
		$src = self::src( 'includes/Bootstrap.php' );
		self::assertMatchesRegularExpression(
			"/load_plugin_textdomain\(\s*'wink-wp-gatekeeper'/",
			$src,
			'批 I-2b domain 翻转：load_plugin_textdomain 首参必须已是 wink-wp-gatekeeper'
		);
	}

	/**
	 * 批 I-2b：zh_CN po/mo 产物在场 + po 条目数口径钉。
	 *
	 * 852 = 851 翻译条（873 目录 - 22 既有英文键）+ 头部空 msgid；4 插件头元数据
	 * 与 13 既有英文键不入 po（批 I-2b 口径——po 只承翻译面）。
	 */
	public function test_zh_cn_po_mo_present_with_expected_entry_count(): void {
		$base = dirname( __DIR__ ) . '/languages/wink-wp-gatekeeper-zh_CN';
		self::assertFileExists( $base . '.po', '批 I-2b 产线：zh_CN po 必须入库' );
		self::assertFileExists( $base . '.mo', '批 I-2b 产线：zh_CN mo 必须入库' );
		self::assertGreaterThan( 0, (int) filesize( $base . '.mo' ), 'mo 非空（wk-mo --strict 编译产物）' );
		$po_src  = (string) file_get_contents( $base . '.po' );
		$count   = preg_match_all( '/^msgid /m', $po_src );
		self::assertIsInt( $count );
		self::assertSame( 1347, $count, 'zh_CN po ^msgid === 1347（i18n 批 I-3：1346 翻译条 + 头部空 msgid；对账 i3-work 目录 1388 条 - 20 retired - 22 既有英文键）' );
	}

	/**
	 * 批 I-2b：po 头部 locale 钉（zh_CN 单复数 + Language 标记）。
	 */
	/**
	 * 【i18n 批 I-3】JS 注入面钉：admin_l10n（admin-brand.js 通道）与 front_l10n
	 * checking_submit 补录键在场——数据面收编后 JS 文案全走注入/兜底 en 通道。
	 */
	public function test_i3_js_l10n_injections_present(): void {
		$bootstrap = self::src( 'includes/Bootstrap.php' );
		self::assertStringContainsString(
			"wp_localize_script( 'wink-gatekeeper-admin-brand', 'wkAdminL10n', self::admin_l10n() )",
			$bootstrap,
			'批 I-3：admin-brand.js 的 wkAdminL10n 注入在场（enqueue admin-brand 处）'
		);
		self::assertStringContainsString(
			"'checking_submit' => __( 'Your comment is being checked, please wait…', 'wink-wp-gatekeeper' )",
			$bootstrap,
			'批 I-3：front_l10n 补录 checking_submit 键（gatekeeper.js 提交等待期 Toast 此前恒缺键走 zh 兜底）'
		);
		$admin_js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/admin-brand.js' );
		self::assertStringContainsString( 'window.wkAdminL10n', $admin_js, '批 I-3：admin-brand.js 读 wkAdminL10n 通道' );
	}

	/**
	 * 【i18n 批 I-3】插件头英文化钉：Plugin Name/Description 已为 en 键原文（zh 走 po
	 * msgstr——WP 后台插件列表零漂移）；pot 侧 4 条 meta msgid 随之全英文属预期。
	 */
	public function test_i3_plugin_header_english_meta(): void {
		$plugin = self::src( 'wink-wp-gatekeeper.php' );
		self::assertStringContainsString( 'Plugin Name:       Wink WP Guard', $plugin, '批 I-3：插件头 Plugin Name 英文化（zh=Wink WP 保安 走 po）' );
		self::assertStringContainsString( 'Plugin Name of the plugin', (string) file_get_contents( dirname( __DIR__ ) . '/languages/wink-wp-gatekeeper.pot' ), 'pot 插件头 meta 提取形态在场' );
		self::assertStringContainsString( 'msgid "Wink WP Guard"', (string) file_get_contents( dirname( __DIR__ ) . '/languages/wink-wp-gatekeeper.pot' ), '批 I-3：pot meta msgid 已英文化（原中文 meta msgid 消失属预期对账项）' );
	}

	public function test_zh_cn_po_header_locale_pins(): void {
		$po_src = (string) file_get_contents( dirname( __DIR__ ) . '/languages/wink-wp-gatekeeper-zh_CN.po' );
		self::assertStringContainsString( 'Plural-Forms: nplurals=1; plural=0;', $po_src, 'zh_CN 单复数形态头' );
		self::assertStringContainsString( 'Language: zh_CN', $po_src, 'Language: zh_CN 头标记' );
	}

	/**
	 * 批 I-2b：旧 text domain 串全库（生产+tests，排除 languages/）零残留钉。
	 *
	 * 豁免：\WP_CLI::add_command 行——wp-cli 命令名与旧 domain 同形，非 text domain。
	 */
	public function test_old_text_domain_zero_residuals(): void {
		$root     = dirname( __DIR__ );
		$files    = array_merge(
			glob( $root . '/*.php' ) ?: array(),
			self::php_files_under( $root . '/includes' ),
			self::php_files_under( $root . '/tests' )
		);
		$offenders = array();
		foreach ( $files as $file ) {
			$src = (string) file_get_contents( $file );
			foreach ( preg_split( '/\r\n|\r|\n/', $src ) as $lineno => $line ) {
				if ( str_contains( $line, "'wink-gatekeeper'" ) && ! str_contains( $line, 'add_command' ) ) {
					$offenders[] = basename( $file ) . ':' . ( $lineno + 1 );
				}
			}
		}
		self::assertSame( array(), $offenders, '旧 text domain 串零残留（wp-cli add_command 命令名同形豁免）。违例：' . implode( ', ', $offenders ) );
	}

	/**
	 * 递归收集目录下全部 .php 文件（旧 domain 扫描钉辅助）。
	 *
	 * @param string $dir 目录。
	 * @return array<int, string>
	 */
	private static function php_files_under( string $dir ): array {
		$out = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file_info ) {
			if ( $file_info instanceof \SplFileInfo && 'php' === $file_info->getExtension() ) {
				$out[] = $file_info->getPathname();
			}
		}
		return $out;
	}

	/* ---------- 【i18n 批 I-4a】0.9.0 版本进位收口钉（红先） ---------- */

	/**
	 * 批 I-4a：版本进位钉——插件头 Version / WINK_GATEKEEPER_VERSION 常量 / readme
	 * Stable tag 三位 0.9.0（milestone 位：i18n 全量英文化改造波 5 收口）。
	 */
	public function test_i4a_version_bump_to_0_9_0(): void {
		$plugin = self::src( 'wink-wp-gatekeeper.php' );
		self::assertMatchesRegularExpression(
			'/^ \* Version:\s+0\.9\.0$/m',
			$plugin,
			'批 I-4a 版本进位：插件头 Version 须为 0.9.0（milestone 位）'
		);
		self::assertStringContainsString(
			"define( 'WINK_GATEKEEPER_VERSION', '0.9.0' )",
			$plugin,
			'批 I-4a 版本进位：版本常量须为 0.9.0'
		);
		$readme = self::src( 'readme.txt' );
		self::assertMatchesRegularExpression(
			'/^Stable tag: 0\.9\.0$/m',
			$readme,
			'批 I-4a 版本进位：readme Stable tag 须为 0.9.0'
		);
	}

	/**
	 * 批 I-4a：readme changelog 0.9.0 条目钉——倒序纪律（位于 0.8.7 之前）+
	 * 条目要点在场（i18n 全量英文化 + zh_CN 内置翻译包零变化 + B 类开发者面保留）。
	 */
	public function test_i4a_readme_changelog_0_9_0_entry(): void {
		$readme = self::src( 'readme.txt' );
		self::assertStringContainsString( '= 0.9.0 =', $readme, '批 I-4a：readme changelog 须有 0.9.0 条目' );
		$pos090  = (int) strpos( $readme, '= 0.9.0 =' );
		$pos087  = (int) strpos( $readme, '= 0.8.7 =' );
		self::assertLessThan( $pos087, $pos090, '批 I-4a：0.9.0 条目位次在 0.8.7 之前（Changelog 倒序纪律）' );
		$entry   = substr( $readme, $pos090, $pos087 - $pos090 );
		self::assertStringContainsString( '英文化', $entry, '0.9.0 条目要点：i18n 全量英文化改造' );
		self::assertStringContainsString( 'zh_CN', $entry, '0.9.0 条目要点：内置 zh_CN 翻译包' );
		self::assertStringContainsString( '零变化', $entry, '0.9.0 条目要点：中文站点体验零变化' );
	}

	/**
	 * 批 I-4a：make-pot warning 状态注记钉（不 pin 动态数）——唯一 warning 源 =
	 * Bootstrap admin_l10n stampDay 单空格分隔键（"string without translatable
	 * content"），属 ⑤-补 跨节点量词形态口径：键值改动=触碰渲染结构（红线 1），
	 * **登记处置不改码**（验收口径=零 warning 或逐条处置，本条走处置档）。
	 * 在位钉=该键行与其 phpcs:ignore 处置注记同在生产源（处置档连续性）。
	 */
	public function test_i4a_pot_warning_disposition_registered(): void {
		$bootstrap = self::src( 'includes/Bootstrap.php' );
		self::assertMatchesRegularExpression(
			"/'stampDay'\s*=>\s*__\( ' ', 'wink-wp-gatekeeper' \)/",
			$bootstrap,
			'批 I-4a warning 处置：stampDay 单空格分隔键在位（make-pot warning 1 条=登记处置——跨节点量词形态，改键=触碰渲染结构红线，不改码）'
		);
		self::assertMatchesRegularExpression(
			'#stampDay.{0,80}phpcs:ignore WordPress\.WP\.I18n#s',
			$bootstrap,
			'批 I-4a warning 处置：stampDay 行 phpcs:ignore 处置注记同在（I-3 已裁定口径的连续性钉）'
		);
	}

	/* ---------- ⑤ .gitignore 三产物全入库策略 pin ---------- */

	public function test_gitignore_has_no_rule_ignoring_translation_artifacts(): void {
		$src = self::src( '.gitignore' );

		// 策略 pin（统筹拍板）：pot/po/mo 三产物全部入库（自托管开箱即用）——
		// .gitignore 只允许有说明注释，零生效忽略规则。
		$offenders = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $src ) as $line ) {
			$rule = trim( $line );
			if ( '' === $rule || str_starts_with( $rule, '#' ) ) {
				continue; // 注释行/空行非生效规则。
			}
			if ( preg_match( '#(^|/)languages/?$#', $rule ) || preg_match( '#\*\.(mo|po|pot)#', $rule ) ) {
				$offenders[] = $rule;
			}
		}
		self::assertSame(
			array(),
			$offenders,
			'策略 pin：languages/ 目录与 pot/po/mo 三产物全入库，.gitignore 零忽略规则。违例规则：' . implode( ', ', $offenders )
		);
	}
}
