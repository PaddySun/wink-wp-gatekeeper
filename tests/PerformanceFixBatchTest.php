<?php
/**
 * PerformanceFixBatchTest——独立审计 deferred 修复批 2（性能批）契约测试。
 *
 * 两发现（AUDIT-INDEP-2026-11 REPORT §10 优先序 4）：
 *   - P1-7（A2-F1）：评论 POST 最坏同步延迟 ≈55.2s——chain_budget 30s 是「外呼开始时刻」
 *     墙非总时长墙 → deadline-aware timeout：链预算以 meta 便车（chain_deadline）随云审
 *     调用下发，各驱动把剩余预算作本次传输超时上限（DeepSeekFetchAdapter 先例同法）；
 *     预算耗尽=error 零外呼；禁预算（chain_budget=0）不注入=现行行为零变化。
 *   - P1-8（A2-F2）残余半件：.min 构建（入队条件半件已在 0.8.7 AUDIT-FIX 落地——
 *     is_singular && comments_open 注记在案）。esbuild 固定版本产物入仓 + 非
 *     SCRIPT_DEBUG 环境入队 .min。
 *
 * 红先：本文件先红后绿；increase-only（零既有断言删除）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Drivers\ReviewChainBudget;
use WinkGatekeeper\Drivers\ReviewDriverInterface;
use WinkGatekeeper\Gates\CommentGate;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Verdict;

final class PerformanceFixBatchTest extends TestCase {

	private const HMACsalt = 'perf-batch-test';

	/* ===================================================================
	 * P1-7 · ReviewChainBudget 帮助件 + 闸侧 meta 注入 + 四驱动钳制
	 * =================================================================== */

	public function test_chain_budget_helper_semantics(): void {
		$this->assertSame( 'chain_deadline', ReviewChainBudget::META_KEY );
		$this->assertFalse( ReviewChainBudget::expired( null ), '无 deadline 恒未耗尽（现行行为零变化）' );
		$this->assertFalse( ReviewChainBudget::expired( time() + 60 ) );
		$this->assertTrue( ReviewChainBudget::expired( time() - 1 ), '墙已过=耗尽' );

		$this->assertSame( 8, ReviewChainBudget::clamp( 8, null ), '无 deadline=原值（零变化）' );
		$this->assertSame( 8, ReviewChainBudget::clamp( 8, time() + 3600 ), '剩余充足=原值' );
		$shrunk = ReviewChainBudget::clamp( 8, time() + 2 );
		$this->assertGreaterThanOrEqual( 1, $shrunk, '钳制下限 1s' );
		$this->assertLessThanOrEqual( 3, $shrunk, '剩余预算作超时上限（时钟抖动容差 ±1s）' );
		$this->assertSame( 1, ReviewChainBudget::clamp( 8, time() - 100 ), '已过期形态钳到下限（外呼由 expired 门先行拒绝）' );
		$this->assertNull( ReviewChainBudget::from_meta( array() ) );
		$this->assertSame( 1234, ReviewChainBudget::from_meta( array( 'chain_deadline' => '1234' ) ), '数值串形态归一' );
		$this->assertNull( ReviewChainBudget::from_meta( array( 'chain_deadline' => 'garbage' ) ) );
	}

	/** 复刻 gate 工厂（capture 型假云驱动 + LocalRules 正常词表）。 */
	private function gate( ReviewDriverInterface $cloud, array &$event_log, int $chain_budget ): CommentGate {
		return new CommentGate(
			array(
				'gates'     => array( 'comment' => array( 'enabled' => true, 'render_mode' => 'always', 'chain_budget' => $chain_budget ) ),
				'review'    => array(
					'local' => array(
						'min_length' => 0, 'max_length' => 1800, 'require_chinese' => 1,
						'custom_banned_words' => '私活', 'use_wp_keywords' => 0,
					),
					'cloud' => array( 'enabled' => true, 'chain' => array( 'fake' ) ),
				),
				'intercept' => array( 'archive_spam' => true ),
				'stats'     => array( 'enabled' => true, 'pass_sampling' => false ),
			),
			null, // captcha（无 wk_stub 形态=legacy 直达四闸）。
			null, // 自建 LocalRules。
			static function ( string $stage, Verdict $verdict, array $meta ) use ( &$event_log ): string {
				$event_log[] = array( 'stage' => $stage, 'meta' => $meta, 'verdict' => $verdict->verdict() );
				return 'WK-20261120-0002';
			},
			new IpResolver( array( 'ip_source' => 'direct' ) ),
			static function ( string $data ): string {
				return 'h_' . hash_hmac( 'sha256', $data, 'perf-test-key' );
			},
			static function (): int {
				return 1731168000; // 固定时钟（秒）。
			},
			null,
			null, // archiver。
			array( 'fake' => $cloud ), // cloud_chain。
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null  // archive_quota（批1 位）。
		);
	}

	public function test_gate_injects_chain_deadline_when_budget_on(): void {
		$captured   = null;
		$event_log  = array();
		$fake       = new class( $captured ) implements ReviewDriverInterface {
			public $cap;
			public function __construct( &$cap ) {
				$this->cap = &$cap;
			}
			public function driver_id(): string {
				return 'fake';
			}
			public function check( string $content, array $meta ): Verdict {
				$this->cap = $meta;
				return Verdict::pass( 'fake', 'ok' );
			}
		};
		$gate = $this->gate( $fake, $event_log, 30 );

		$result = $gate->orchestrate(
			array( 'comment_post_ID' => 42, 'comment_content' => '正常中文评论内容，无违规词。' ),
			array( 'comment_post_ID' => '42' ),
			array( 'REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'WINK-PerfTest/1.0' )
		);

		$this->assertNotNull( $captured, '云审假驱动被调用（链可达）' );
		$this->assertArrayHasKey( 'chain_deadline', $captured, '【P1-7】预算开=chain_deadline meta 便车在场' );
		$this->assertIsNumeric( $captured['chain_deadline'] );
		$this->assertGreaterThan( 0, (int) $captured['chain_deadline'], '墙边界=正整数时间戳' );
	}

	public function test_gate_no_chain_deadline_when_budget_off(): void {
		$captured  = null;
		$event_log = array();
		$fake      = new class( $captured ) implements ReviewDriverInterface {
			public $cap;
			public function __construct( &$cap ) {
				$this->cap = &$cap;
			}
			public function driver_id(): string {
				return 'fake';
			}
			public function check( string $content, array $meta ): Verdict {
				$this->cap = $meta;
				return Verdict::pass( 'fake', 'ok' );
			}
		};
		$gate = $this->gate( $fake, $event_log, 0 ); // 预算关。

		$gate->orchestrate(
			array( 'comment_post_ID' => 42, 'comment_content' => '正常中文评论内容，无违规词。' ),
			array( 'comment_post_ID' => '42' ),
			array( 'REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'WINK-PerfTest/1.0' )
		);

		$this->assertNotNull( $captured, '云审假驱动被调用' );
		$this->assertArrayNotHasKey( 'chain_deadline', $captured, '预算关=不注入（现行行为零变化）' );
	}

	public function test_openai_driver_expired_deadline_errors_without_outbound(): void {
		$calls  = array();
		$client = new RemoteClient(
			static function ( string $url, array $args ) use ( &$calls ) {
				$calls[] = array( 'url' => $url, 'args' => $args );
				return array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() );
			},
			null,
			static function ( int $ms ): void {
				unset( $ms );
			}
		);
		$driver = new \WinkGatekeeper\Drivers\review\OpenAiCompatibleReviewDriver(
			array( 'base_url' => 'https://llm.example', 'api_key' => 'k', 'model' => 'm', 'timeout' => 8 ),
			$client
		);

		$verdict = $driver->check( 'x', array( ReviewChainBudget::META_KEY => time() - 10 ) );

		$this->assertSame( Verdict::ERROR, $verdict->verdict(), '预算耗尽=error（与闸侧墙语义一致）' );
		$this->assertCount( 0, $calls, '零外呼（deadline-aware 门先行）' );
	}

	public function test_openai_driver_future_deadline_clamps_transport_timeout(): void {
		$calls  = array();
		$client = new RemoteClient(
			static function ( string $url, array $args ) use ( &$calls ) {
				$calls[] = array( 'url' => $url, 'args' => $args );
				return array( 'response' => array( 'code' => 200 ), 'body' => 'not-json', 'headers' => array() );
			},
			null,
			static function ( int $ms ): void {
				unset( $ms );
			}
		);
		$driver = new \WinkGatekeeper\Drivers\review\OpenAiCompatibleReviewDriver(
			array( 'base_url' => 'https://llm.example', 'api_key' => 'k', 'model' => 'm', 'timeout' => 8 ),
			$client
		);

		$driver->check( 'x', array( ReviewChainBudget::META_KEY => time() + 3 ) );

		$this->assertCount( 1, $calls, '外呼发起（剩余充足）' );
		$timeout = (int) $calls[0]['args']['timeout'];
		$this->assertGreaterThanOrEqual( 1, $timeout, '钳制下限 1s' );
		$this->assertLessThanOrEqual( 4, $timeout, '超时钳到剩余预算（8→≤3+时钟容差 1s）' );
	}

	public function test_openai_driver_no_deadline_keeps_configured_timeout(): void {
		$calls  = array();
		$client = new RemoteClient(
			static function ( string $url, array $args ) use ( &$calls ) {
				$calls[] = array( 'url' => $url, 'args' => $args );
				return array( 'response' => array( 'code' => 200 ), 'body' => 'not-json', 'headers' => array() );
			},
			null,
			static function ( int $ms ): void {
				unset( $ms );
			}
		);
		$driver = new \WinkGatekeeper\Drivers\review\OpenAiCompatibleReviewDriver(
			array( 'base_url' => 'https://llm.example', 'api_key' => 'k', 'model' => 'm', 'timeout' => 8 ),
			$client
		);

		$driver->check( 'x', array() );
		$this->assertCount( 1, $calls );
		$this->assertSame( 8, (int) $calls[0]['args']['timeout'], '无 deadline=配置超时原值（现行行为零变化）' );
	}

	public function test_four_review_drivers_wire_chain_budget_clamp(): void {
		$files = array(
			'includes/drivers/review/OpenAiCompatibleReviewDriver.php',
			'includes/drivers/review/BaiduTextModerationDriver.php',
			'includes/drivers/review/AliyunGreenPlusDriver.php',
			'includes/drivers/review/TencentTmsDriver.php',
		);
		foreach ( $files as $rel ) {
			$src = (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
			$this->assertStringContainsString( 'ReviewChainBudget', $src, "$rel 接入链预算钳制（P1-7）" );
		}
		$gate = (string) file_get_contents( dirname( __DIR__ ) . '/includes/gates/CommentGate.php' );
		$this->assertStringContainsString( 'ReviewChainBudget::META_KEY', $gate, '闸侧 meta 注入引用同一契约键（P1-7）' );
	}

	/* ===================================================================
	 * P1-8 残余半件 · .min 构建产物 + 入队切换
	 * =================================================================== */

	/** @return array<string, array<string, int>> */
	private function min_pairs(): array {
		$base = dirname( __DIR__ );
		return array(
			'gatekeeper'  => array(
				'src' => array( $base, 'assets/js/gatekeeper.js' ),
				'min' => array( $base, 'assets/js/gatekeeper.min.js' ),
			),
			'tencent'     => array(
				'src' => array( $base, 'assets/js/drivers/tencent.js' ),
				'min' => array( $base, 'assets/js/drivers/tencent.min.js' ),
			),
			'aliyun'      => array(
				'src' => array( $base, 'assets/js/drivers/aliyun.js' ),
				'min' => array( $base, 'assets/js/drivers/aliyun.min.js' ),
			),
			'front-gate'  => array(
				'src' => array( $base, 'assets/css/front-gate.css' ),
				'min' => array( $base, 'assets/css/front-gate.min.css' ),
			),
		);
	}

	public function test_min_artifacts_exist_and_are_smaller(): void {
		foreach ( $this->min_pairs() as $name => $pair ) {
			$src_path = $pair['src'][0] . '/' . $pair['src'][1];
			$min_path = $pair['min'][0] . '/' . $pair['min'][1];
			$this->assertFileExists( $min_path, "$name .min 产物在场（P1-8 残余半件）" );
			$this->assertLessThan(
				filesize( $src_path ),
				filesize( $min_path ),
				"$name .min 严格小于源文件（真压缩非占位）"
			);
		}
	}

	public function test_bootstrap_enqueue_uses_min_suffix(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Bootstrap.php' );
		$this->assertStringContainsString( 'SCRIPT_DEBUG', $src, 'SCRIPT_DEBUG 分支在场' );
		$this->assertGreaterThanOrEqual( 2, substr_count( $src, 'front-gate$suffix.css' ), 'front 与 login 两注册块均走 suffix 形态（front-gate$suffix.css 字面量≥2）' );
		/* 【0.9.2 部署实锤修复】插值引号契约：单引号 PHP 不插值——B 机实机页面曾渲染字面量
		 * gatekeeper$suffix.js（资产 404）。钉死双引号形态在场+单引号形态零残留。 */
		$this->assertSame( 2, substr_count( $src, '"assets/css/front-gate$suffix.css"' ), 'css 路径双引号形态恰两处（插值生效）' );
		$this->assertSame( 2, substr_count( $src, '"assets/js/gatekeeper$suffix.js"' ), 'js 路径双引号形态恰两处' );
		$this->assertSame( 2, substr_count( $src, '"assets/js/drivers/tencent$suffix.js"' ), 'tencent 路径双引号形态恰两处' );
		$this->assertSame( 2, substr_count( $src, '"assets/js/drivers/aliyun$suffix.js"' ), 'aliyun 路径双引号形态恰两处' );
		$this->assertSame( 0, substr_count( $src, chr(39) . 'assets/css/front-gate$suffix.css' . chr(39) ), 'css 路径单引号形态零残留（不插值）' );
		$this->assertSame( 0, substr_count( $src, chr(39) . 'assets/js/gatekeeper$suffix.js' . chr(39) ), 'js 路径单引号形态零残留' );
		// 源路径字面量退役（后缀变量化——两处硬编码路径不再在场）。
		$this->assertSame( 0, substr_count( $src, "'assets/css/front-gate.css'" ), 'front-gate.css 硬编码路径退役（suffix 变量化）' );
		$this->assertSame( 0, substr_count( $src, "'assets/js/gatekeeper.js'" ), 'gatekeeper.js 硬编码路径退役（suffix 变量化）' );
	}

	public function test_build_min_script_exists_with_pinned_tool(): void {
		$script = dirname( __DIR__ ) . '/tools/build-min.sh';
		$this->assertFileExists( $script, 'build-min.sh 在场' );
		$src = (string) file_get_contents( $script );
		$this->assertStringContainsString( 'esbuild', $src, 'esbuild 固定版本工具链' );
	}
}
