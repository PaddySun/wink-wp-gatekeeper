<?php
/**
 * M6·批 I · I-1 调用量卡行名人话化（2026-11-17——P-1 来源：IMPL-M6-H-VERIFY §2 目验新发现，
 * 批 H 九件收尾抛光项；TDD 先红后绿回归门）。
 *
 * 契约（批 I 任务书 I-1）：
 *   - 网页抓取域行名按计量键映射人话：url_audit_fetch=「网页抓取（DeepSeek）」；
 *     url_audit_incr=「网址判定·增量车道（DeepSeek）」；url_audit_batch=「网址判定·批量车道
 *     （DeepSeek）」。三键均只由 DeepSeek 抓取适配器/判定引擎计量（批 H H-1 语义：本站直抓
 *     零外呼零计量、抓取未配置零计量），键→名静态映射恒真；若按「当前 fetcher」动态解析，
 *     日内改配置后会把上午的 DeepSeek 计数错标成本站直抓——不撒谎纪律优先，取静态键映射。
 *   - 网页搜索域行名：bocha_search=「网页搜索（博查）」；deepseek_search=「网页搜索（DeepSeek）」。
 *   - 评论审核域行名经 registry 显示名（deepseek→「DeepSeek」——Bootstrap 注入面补录；
 *     baidu/tencent_tms 等既有映射不动，单一来源=registry_entry()['label']）。
 *   - 人机验证域照旧（registry label 注入面已存在）。
 *   - 本站直抓声明行批 H 语义不变（「本站直接打开——不计费」bill_free 无次数行）。
 *   - 五域固定顺序回归（批 H 钉沿用——混合搭配场景再钉一次）。
 *   - 渲染消费面零原始计量键泄漏（混合搭配全行是人话）。
 *
 * 消费面：DashboardDigest::driver_label() 回落链 注入映射 → Labels::usage_row_word()
 * → 原始键（不吞键不虚构——未收录键照旧原样）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver;
use WinkGatekeeper\Settings\SettingsSchema;
use WinkGatekeeper\Stats\DashboardDigest;
use WinkGatekeeper\Stats\Labels;
use WinkGatekeeper\Stats\Usage;

final class M6IUsageCardLabelsTest extends TestCase {

	private const YMD      = '20261117';
	private const YMD_DASH = '2026-11-17';

	/**
	 * 组装 Digest 输入（M6HUiSemanticsTest::digestArgs 同款基线）。
	 *
	 * @param array<string, mixed> $overrides 覆盖键。
	 * @return array<string, mixed>
	 */
	private function args( array $overrides = array() ): array {
		$base = array(
			'settings'             => SettingsSchema::defaults(),
			'stats'                => array( 'today' => '19700101', 'by_gate' => array() ),
			'usage'                => Usage::seed(),
			'health'               => array(),
			'today_ymd'            => self::YMD,
			'today_dash'           => self::YMD_DASH,
			'captcha_configured'   => true,
			'captcha_driver'       => 'aliyun',
			'captcha_driver_label' => '阿里云验证码 2.0',
			'driver_labels'        => array(),
			'cron_alive'           => true,
			'debug'                => false,
		);
		return array_merge( $base, $overrides );
	}

	/**
	 * 生产注入面形态（Bootstrap::render_dashboard_widget 的 $driver_labels 同构——
	 * registry label 单一来源 + abuseipdb 两键 M3 前手名）。
	 *
	 * @return array<string, string>
	 */
	private function productionDriverLabels(): array {
		return array(
			'tencent'          => (string) \WinkGatekeeper\Drivers\Captcha\TencentCaptchaDriver::registry_entry()['label'],
			'aliyun'           => (string) \WinkGatekeeper\Drivers\Captcha\AliyunCaptchaDriver::registry_entry()['label'],
			'local_rules'      => (string) \WinkGatekeeper\Drivers\Review\LocalRulesDriver::registry_entry()['label'],
			'tencent_tms'      => (string) \WinkGatekeeper\Drivers\Review\TencentTmsDriver::registry_entry()['label'],
			'aliyun_green'     => (string) \WinkGatekeeper\Drivers\Review\AliyunGreenPlusDriver::registry_entry()['label'],
			'baidu'            => (string) \WinkGatekeeper\Drivers\Review\BaiduTextModerationDriver::registry_entry()['label'],
			'deepseek'         => (string) DeepSeekReviewDriver::registry_entry()['label'],
			'abuseipdb'        => 'AbuseIPDB',
			'abuseipdb_report' => 'AbuseIPDB（上报）',
		);
	}

	/**
	 * 按 id 取域。
	 *
	 * @param array<string, mixed> $usage_view usage 视图模型。
	 * @param string               $id         域 id。
	 * @return array<string, mixed>|null
	 */
	private function domainById( array $usage_view, string $id ): ?array {
		foreach ( $usage_view['domains'] as $domain ) {
			if ( $id === $domain['id'] ) {
				return $domain;
			}
		}
		return null;
	}

	/* ================= I-1 本体：计量键 → 人话名映射 ================= */

	public function test_i1_labels_usage_row_word_closed_set(): void {
		$this->assertSame( '网页抓取（DeepSeek）', Labels::usage_row_word( 'url_audit_fetch' ), '抓取成功次=网页抓取（DeepSeek）' );
		$this->assertSame( '网址判定·增量车道（DeepSeek）', Labels::usage_row_word( 'url_audit_incr' ), '增量车道判定次' );
		$this->assertSame( '网址判定·批量车道（DeepSeek）', Labels::usage_row_word( 'url_audit_batch' ), '批量车道判定次' );
		$this->assertSame( '网页搜索（博查）', Labels::usage_row_word( 'bocha_search' ), '博查搜索次' );
		$this->assertSame( '网页搜索（DeepSeek）', Labels::usage_row_word( 'deepseek_search' ), 'DeepSeek 搜索次' );
		$this->assertSame( '', Labels::usage_row_word( 'deepseek' ), '驱动 id 不进本表（单一来源=registry 注入面）' );
		$this->assertSame( '', Labels::usage_row_word( 'abuseipdb' ), 'abuseipdb 不进本表（Bootstrap 注入面既有）' );
		$this->assertSame( '', Labels::usage_row_word( 'unknown_future_key' ), '未收录键=空串（回落原键不吞）' );
	}

	public function test_i1_fetch_domain_rows_human_labels(): void {
		$usage = Usage::seed();
		foreach ( array( 'url_audit_fetch', 'url_audit_incr', 'url_audit_batch' ) as $key ) {
			$usage = Usage::bump( $usage, self::YMD_DASH, $key );
		}
		$usage = Usage::bump( $usage, self::YMD_DASH, 'url_audit_fetch' ); // 抓取 2 次。

		$settings                            = SettingsSchema::defaults();
		$settings['url_audit']['enabled']    = true;
		$settings['url_audit']['fetcher']['mode'] = 'deepseek';

		$out     = DashboardDigest::widget_overview( $this->args( array( 'usage' => $usage, 'settings' => $settings ) ) );
		$fetch   = $this->domainById( $out['usage'], 'fetch' );

		$this->assertNotNull( $fetch, '抓取域渲染' );
		$this->assertSame( '网页抓取（DeepSeek）', $fetch['rows'][0]['label'], '抓取服务商行人话（原 url_audit_fetch 裸键退役）' );
		$this->assertSame( 2, $fetch['rows'][0]['used'], '抓取行计次不变' );
		$this->assertSame( '网址判定·增量车道（DeepSeek）', $fetch['rows'][1]['label'], '增量车道判定行人话' );
		$this->assertSame( '网址判定·批量车道（DeepSeek）', $fetch['rows'][2]['label'], '批量车道判定行人话' );
		$this->assertSame( 'url_audit_fetch', $fetch['rows'][0]['driver_id'], 'driver_id 原键保留（机器可读面不变）' );
	}

	public function test_i1_search_domain_rows_human_labels(): void {
		$usage = Usage::seed();
		$usage = Usage::bump( $usage, self::YMD_DASH, 'bocha_search' );
		$usage = Usage::bump( $usage, self::YMD_DASH, 'deepseek_search' );

		$out   = DashboardDigest::widget_overview( $this->args( array( 'usage' => $usage ) ) );
		$search = $this->domainById( $out['usage'], 'search' );

		$this->assertNotNull( $search, '搜索域渲染' );
		$this->assertSame( '网页搜索（博查）', $search['rows'][0]['label'], '博查行人话（原 bocha_search 裸键退役）' );
		$this->assertSame( '网页搜索（DeepSeek）', $search['rows'][1]['label'], 'DeepSeek 搜索行人话' );
		$this->assertSame( 'bocha_search', $search['rows'][0]['driver_id'], 'driver_id 原键保留' );
	}

	public function test_i1_review_domain_deepseek_registry_label(): void {
		$usage = Usage::seed();
		$usage = Usage::bump( $usage, self::YMD_DASH, 'deepseek' );

		$settings                             = SettingsSchema::defaults();
		$settings['review']['cloud']['chain'] = array( 'deepseek' );

		$out = DashboardDigest::widget_overview( $this->args( array(
			'usage'         => $usage,
			'settings'      => $settings,
			'driver_labels' => $this->productionDriverLabels(),
		) ) );

		$review = $this->domainById( $out['usage'], 'review' );
		$this->assertNotNull( $review, '评论审核域渲染' );
		$this->assertSame( 'DeepSeek', $review['rows'][0]['label'], 'deepseek 行经 registry 显示名（原裸 id 退役）' );
		$this->assertSame( 'deepseek', $review['rows'][0]['driver_id'], 'driver_id 原键保留' );
	}

	public function test_i1_review_domain_tms_registry_label_unchanged(): void {
		// 既有映射不动（baidu/tencent_tms 等各显 registry 显示名——批 H 语义回归）。
		$usage = Usage::seed();
		$usage = Usage::bump( $usage, self::YMD_DASH, 'tencent_tms' );

		$settings                             = SettingsSchema::defaults();
		$settings['review']['cloud']['chain'] = array( 'tencent_tms' );

		$out = DashboardDigest::widget_overview( $this->args( array(
			'usage'         => $usage,
			'settings'      => $settings,
			'driver_labels' => $this->productionDriverLabels(),
		) ) );

		$review = $this->domainById( $out['usage'], 'review' );
		$this->assertNotNull( $review );
		$this->assertSame( '腾讯云 TMS 文本审核', $review['rows'][0]['label'], 'tencent_tms registry 显示名照旧' );
	}

	/* ================= 混合搭配全场景：顺序 + 全行 人话 + 零裸键泄漏 ================= */

	public function test_i1_mixed_domains_fixed_order_and_no_raw_key_leak(): void {
		$usage = Usage::seed();
		foreach ( array(
			'aliyun', 'deepseek',
			'url_audit_fetch', 'url_audit_incr', 'url_audit_batch',
			'bocha_search', 'deepseek_search',
			'abuseipdb', 'abuseipdb_report',
		) as $key ) {
			$usage = Usage::bump( $usage, self::YMD_DASH, $key );
		}

		$settings                             = SettingsSchema::defaults();
		$settings['review']['cloud']['chain'] = array( 'deepseek' );
		$settings['url_audit']['enabled']     = true;
		$settings['url_audit']['fetcher']['mode'] = 'deepseek';

		$out = DashboardDigest::widget_overview( $this->args( array(
			'usage'         => $usage,
			'settings'      => $settings,
			'driver_labels' => $this->productionDriverLabels(),
		) ) );

		$this->assertSame(
			array( 'captcha', 'review', 'fetch', 'search', 'iprep' ),
			array_column( $out['usage']['domains'], 'id' ),
			'五域固定顺序（批 H 契约混合场景回归）'
		);

		// 全行扫描：渲染 label 一律不等于原始计量键（人话化收口——零裸键泄漏）。
		foreach ( $out['usage']['domains'] as $domain ) {
			foreach ( $domain['rows'] as $row ) {
				$this->assertNotSame(
					$row['driver_id'],
					$row['label'],
					'域 ' . $domain['id'] . ' 行 ' . $row['driver_id'] . ' 仍显示原始键——人话化缺口'
				);
			}
		}

		// 关键行抽验（混合搭配各域各行如实显示）。
		$this->assertSame( '阿里云验证码 2.0', $this->domainById( $out['usage'], 'captcha' )['rows'][0]['label'] );
		$this->assertSame( 'DeepSeek', $this->domainById( $out['usage'], 'review' )['rows'][0]['label'] );
		$this->assertSame( '网页抓取（DeepSeek）', $this->domainById( $out['usage'], 'fetch' )['rows'][0]['label'] );
		$this->assertSame( '网页搜索（博查）', $this->domainById( $out['usage'], 'search' )['rows'][0]['label'] );
		$this->assertSame( 'AbuseIPDB', $this->domainById( $out['usage'], 'iprep' )['rows'][0]['label'] );
	}

	/* ================= 批 H 语义回归：本站直抓声明行不变 ================= */

	public function test_i1_self_fetch_declaration_row_keeps_batch_h_semantics(): void {
		$settings                               = SettingsSchema::defaults();
		$settings['url_audit']['enabled']       = true;
		$settings['url_audit']['fetcher']['mode'] = 'self';

		$out   = DashboardDigest::widget_overview( $this->args( array( 'settings' => $settings ) ) );
		$fetch = $this->domainById( $out['usage'], 'fetch' );

		$this->assertNotNull( $fetch, '本站直抓=抓取域渲染（声明行）' );
		$this->assertCount( 1, $fetch['rows'], '无计次行（批 D/H 语义）' );
		$this->assertTrue( (bool) $fetch['rows'][0]['bill_free'], 'bill_free 标记' );
		$this->assertSame( '本站直接打开——不计费', $fetch['rows'][0]['label'], '声明行文案批 H 语义不变' );
	}

	/* ================= Bootstrap 注入面：registry label 单一来源补录 ================= */

	public function test_i1_bootstrap_wiring_deepseek_registry_label(): void {
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/Bootstrap.php' );
		$this->assertIsString( $src, 'Bootstrap.php 必须可读' );

		// 只扫 render_dashboard_widget 函数体（全文件其他函数本就引用 deepseek——面收窄防假绿）。
		$start = strpos( (string) $src, 'public function render_dashboard_widget' );
		$this->assertNotFalse( $start, 'render_dashboard_widget 必须存在' );
		$open  = strpos( (string) $src, '{', $start );
		$depth = 0;
		$body  = '';
		$len   = strlen( (string) $src );
		for ( $i = $open; $i < $len; $i++ ) {
			if ( '{' === $src[ $i ] ) {
				$depth++;
			} elseif ( '}' === $src[ $i ] ) {
				$depth--;
				if ( 0 === $depth ) {
					$body = substr( (string) $src, $open, $i - $open + 1 );
					break;
				}
			}
		}
		$this->assertNotSame( '', $body, '函数体提取成功' );

		$this->assertStringContainsString(
			'DeepSeekReviewDriver::registry_entry()',
			$body,
			'仪表盘 driver_labels 注入面补录 deepseek 键——显示名走 registry 单一来源（勿发明第二映射源）'
		);
	}
}
