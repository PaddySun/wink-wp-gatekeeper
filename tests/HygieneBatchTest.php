<?php
/**
 * HygieneBatchTest——独立审计 deferred 修复批 3（卫生批）契约测试。
 *
 * 范围（任务书 #2 卫生批 + REPORT §10 优先序 6；**0.8.7 AUDIT-FIX 已修件经盘点豁免**：
 * S1-F2 CSV 中和/S1-F3 wp_die esc/S4-F2 写点参/P1-9 autoload 自愈/P1-2 验证缓存计次/
 * S6-F2 信用键含昵称/S4-F6 前缀=A1-F9 已在位）。本批真缺口 14 项：
 *   - A1-F10 双 tick cron 卸载/停用漏清
 *   - wk_dgd_/wk_dsr_ transient 前缀补录（修复批新发现——DegradedPassLimiter 两前缀）
 *   - S2-F1 includes 全文件 ABSPATH 纵深守卫（批前仅 1/88）
 *   - S4-F3 OpenAI 兼容驱动 raw 快照绕过 raw_capture 开关（唯一未走桥的驱动）
 *   - S4-F4 redact 键名集补 Key/client_id 形态（防御纵深，当前结构性不可达）
 *   - S4-F5 探针 message 拼厂商原文未 redact（6 组装点）
 *   - S5-F2 check_url 非点分 IP 字面量（十进制/0x 十六进制/短式/前导零）放行——fail-closed 拒
 *   - S5-F3 absolutize 对 scheme 前缀 Location（gopher 族）走相对路径拼接（docblock 与行为不符）
 *   - S5-F4 validate 拒绝误分类 transport（fetch_class 误报 timeout + 白转 2 次重试）
 *   - S3-F4 precheck 事件不在 EventSampling 采样域
 *   - S3-F5 登录用户名维回落键材大小写敏感
 *   - S3-F6 pingback.ping 无插件层封堵
 *   - S3-F7 容量闸拒写期 stats 照计（统计与明细不可对账）
 *   - S3-F8 bocha 搜索回退无独立日闸
 *   - S3-F9 存根 24h 无限复用无单票消费帽
 *
 * 红先：本文件先红后绿；increase-only（零既有断言删除）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Stats\EventSampling;
use WinkGatekeeper\Stats\InterceptDigest;
use WinkGatekeeper\UrlAudit\UrlFetcher;

final class HygieneBatchTest extends TestCase {

	/* ---------------- A1-F10 + 前缀补录（uninstall/停用） ---------------- */

	public function test_uninstall_and_deactivation_clear_both_ticks(): void {
		$uninstall = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
		$this->assertStringContainsString( "wp_clear_scheduled_hook( 'wink_gatekeeper_url_audit_tick' )", $uninstall, 'A1-F10：uninstall 清 url_audit_tick（boot 常驻排期，卸载瞬间必然在册）' );
		$this->assertStringContainsString( "wp_clear_scheduled_hook( 'wink_gatekeeper_audit_tick' )", $uninstall, 'A1-F10：uninstall 清 audit_tick（存量审核 tick 条件在册）' );

		$main = (string) file_get_contents( dirname( __DIR__ ) . '/wink-wp-gatekeeper.php' );
		$this->assertStringContainsString( 'wink_gatekeeper_url_audit_tick', $main, 'A1-F10：停用钩子清 url_audit_tick（批前停用仅清 daily）' );
		$this->assertStringContainsString( 'wink_gatekeeper_audit_tick', $main, 'A1-F10：停用钩子清 audit_tick' );

		$this->assertStringContainsString( "'wk_dgd_'", $uninstall, '前缀补录：wk_dgd_（DegradedPassLimiter pass_limited 桶）' );
		$this->assertStringContainsString( "'wk_dsr_'", $uninstall, '前缀补录：wk_dsr_（disaster 降级放行桶）' );
	}

	/* ---------------- S2-F1 ABSPATH 纵深守卫 ---------------- */

	public function test_all_includes_files_carry_abspath_guard(): void {
		$dir     = dirname( __DIR__ ) . '/includes';
		$rii     = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );
		$total   = 0;
		$unguard = array();
		foreach ( $rii as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$total++;
			$src = (string) file_get_contents( $file->getPathname() );
			if ( false === strpos( $src, "defined( 'ABSPATH' )" ) ) {
				$unguard[] = $file->getPathname();
			}
		}
		$this->assertGreaterThan( 50, $total, 'includes 文件总量面 sane（防 glob 失效空跑）' );
		$this->assertSame( array(), $unguard, 'S2-F1：includes 全部 PHP 文件带 ABSPATH 纵深守卫（缺者列于失败信息）' );
	}

	/* ---------------- S4-F3 raw 快照桥 ---------------- */

	public function test_openai_driver_uses_raw_snapshot_bridge(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/drivers/review/OpenAiCompatibleReviewDriver.php' );
		$this->assertStringContainsString( 'raw_snapshot_from_settings', $src, 'S4-F3：raw 快照走桥（尊重 stats.raw_capture 开关+raw_max_bytes——同族驱动同参形态）' );
		$this->assertSame( 0, substr_count( $src, 'raw_snapshot( 512 )' ), 'S4-F3：裸 raw_snapshot(512) 直调退役' );
	}

	/* ---------------- S4-F4 redact 键名集补形态 ---------------- */

	public function test_redact_covers_key_header_and_client_id(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/http/RemoteClient.php' );
		$this->assertStringContainsString( 'client_id', $src, 'S4-F4：键名集补 client_id（百度 token 端点形态）' );

		$this->assertSame( 'Key: [REDACTED]', RemoteClient::redact( 'Key: sk-live-abcdef123456' ), 'S4-F4：裸 Key: 头形态脱敏（AbuseIPDB 专用头；[REDACTED] 大写=系内惯例）' );
		$this->assertStringNotContainsString( 'sk-live', RemoteClient::redact( 'Key: sk-live-abcdef123456' ) );
		$this->assertStringNotContainsString( 'AK-secret-9', RemoteClient::redact( 'client_id=AK-secret-9&client_secret=xxx' ), 'S4-F4：client_id 赋值形态脱敏' );
		// 既有形态回归锚：api_key 赋值仍脱敏。
		$this->assertStringNotContainsString( 'super-secret-val', RemoteClient::redact( 'api_key=super-secret-val' ) );
	}

	/* ---------------- S4-F5 探针 message redact ---------------- */

	public function test_credential_probe_wraps_vendor_messages_with_redact(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/settings/CredentialProbe.php' );
		$this->assertGreaterThanOrEqual( 5, substr_count( $src, 'RemoteClient::redact(' ), 'S4-F5：探针 message 组装点统一包 redact（灰态/未知码分支厂商原文）' );
	}

	/* ---------------- S5-F2 非点分 IP 字面量拒 ---------------- */

	public function test_check_url_rejects_non_dotted_ip_literals(): void {
		foreach ( array( '2130706433', '127.1', '0x7f000001', '0177.0.0.1' ) as $host ) {
			$reason = RemoteClient::check_url( 'https://' . $host . '/' );
			$this->assertNotNull( $reason, "{$host} 必须被拒（S5-F2 fail-closed——inet_aton 语义形态对插件出站面永非合法目标）" );
		}
		// 标准形态不受新规则影响（127.0.0.1 仍走私网拒路径，example.com 正常放行）。
		$loopback = RemoteClient::check_url( 'https://127.0.0.1/' );
		$this->assertNotNull( $loopback, '标准点分回环仍被私网规则拒' );
		$this->assertStringNotContainsString( 'non-standard', (string) $loopback, '标准点分不走 S5-F2 新规则（归私网既有规则）' );
		$this->assertNull( RemoteClient::check_url( 'https://api.example.com/v1' ), '正常域名放行（现行行为零变化）' );
		// 域名形近误伤回归锚：纯字母域名/带连字域名不吃新规则。
		$this->assertNull( RemoteClient::check_url( 'https://beef-cafe.example.com/' ), 'hex 风格多级域名不吃新规则' );
	}

	/* ---------------- S5-F3 absolutize scheme 拒 ---------------- */

	public function test_absolutize_rejects_non_http_scheme_location(): void {
		$this->assertNull( UrlFetcher::absolutize( 'gopher://127.0.0.1:6379/_INFO', 'https://jump.example/a' ), 'S5-F3：gopher Location 拒（批前按相对路径拼接——docblock 与行为不符）' );
		$this->assertNull( UrlFetcher::absolutize( 'file:///etc/passwd', 'https://jump.example/a' ), 'S5-F3：file scheme 拒' );
		// 注：'javascript:alert(1)'（冒号无 //）不属 scheme:// 前缀类——落相对路径拼接（无害：
		// 请求路径面非可导航 URI），与批前行为一致，不纳入拒绝面。
		$this->assertNotNull( UrlFetcher::absolutize( 'https://ok.example/x', 'https://jump.example/a' ), 'http(s) 绝对 Location 照常' );
		$this->assertNotNull( UrlFetcher::absolutize( '//cdn.example/lib.js', 'https://jump.example/a' ), '协议相对形态无 scheme 前缀不受影响' );
		$this->assertNotNull( UrlFetcher::absolutize( 'path/to?x=1', 'https://jump.example/a' ), '纯相对路径照常' );
	}

	/* ---------------- S5-F4 validate 拒绝语义归位 ---------------- */

	public function test_classify_maps_validate_refusal_to_semantic(): void {
		$refusal = new class implements \Stringable {
			public function get_error_code(): string {
				return 'http_request_url_failed';
			}
			public function __toString(): string {
				return 'WP_Error-shape';
			}
		};
		$result = RemoteResult::classify( $refusal );
		$this->assertSame( RemoteResult::SEMANTIC, $result->category(), 'S5-F4：validate 拒绝=SEMANTIC（批前误分类 transport）' );
		$this->assertFalse( $result->retryable(), 'S5-F4：不可重试（白转 2 次重试退役——零出站同前，预算不再被拒向量消耗）' );
	}

	/* ---------------- S3-F4 precheck 入采样域 ---------------- */

	public function test_precheck_stage_in_sampling_domain(): void {
		$this->assertTrue( EventSampling::sampled_stage( 'precheck' ), 'S3-F4：precheck 事件入采样域（10 行/IP/h 封顶同族）' );
	}

	/* ---------------- S3-F5 回落键材小写 ---------------- */

	public function test_login_username_fallback_lowercased(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/gates/LoginGate.php' );
		$this->assertStringContainsString( 'strtolower( $submitted_username )', $src, 'S3-F5：主信号丢失回落键材小写归一（形态变体摊薄面收口）' );
	}

	/* ---------------- S3-F6 pingback 封堵 ---------------- */

	public function test_pingback_ping_removed_from_xmlrpc_methods(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Bootstrap.php' );
		$this->assertStringContainsString( 'xmlrpc_methods', $src, 'S3-F6：xmlrpc_methods 过滤器在场' );
		$this->assertStringContainsString( 'unset( $methods[\'pingback.ping\']', $src, 'S3-F6：pingback.ping 摘除（匿名反射/试探面）' );
	}

	/* ---------------- S3-F7 容量拒写 stats 对账 ---------------- */

	public function test_capacity_refusal_signal_stops_stats(): void {
		$this->assertTrue( method_exists( 'WinkGatekeeper\\Stats\\EventsTable', 'capacity_refused_last' ), 'S3-F7：拒写信号读面在场' );
		$gate = (string) file_get_contents( dirname( __DIR__ ) . '/includes/gates/CommentGate.php' );
		$this->assertStringContainsString( 'capacity_refused_last', $gate, 'S3-F7：记录器闭包消费拒写信号（拒写期 stats 跳过——统计与明细可对账）' );
		$precheck = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Bootstrap.php' );
		$this->assertStringContainsString( 'capacity_refused_last', $precheck, 'S3-F7：precheck 记录器同口径（同族消费）' );
	}

	/* ---------------- S3-F8 bocha 独立日闸 ---------------- */

	public function test_search_fallback_daily_gate(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/urlaudit/UrlAuditWorker.php' );
		$this->assertStringContainsString( 'search_daily_allowed', $src, 'S3-F8：搜索回退日闸判定件在场' );
		$this->assertStringContainsString( 'wk_usd_', $src, 'S3-F8：日桶键前缀（卸载面无需清扫——纯缓存组键/transient 2 天 TTL 自然回收）' );
		$this->assertStringContainsString( 'wink_gatekeeper_url_audit_search_daily_cap', $src, 'S3-F8：帽值 filter 可调' );
	}

	/* ---------------- S3-F9 存根单票复用帽 ---------------- */

	public function test_stub_reuse_cap(): void {
		$this->assertSame( 50, constant( 'WinkGatekeeper\\Gates\\CommentGate::STUB_REUSE_CAP' ), 'S3-F9：单票复用帽默认 50（audit 建议值）' );
		$this->assertFalse( \WinkGatekeeper\Gates\CommentGate::stub_replay_over( null ), '无计数上下文（无持久对象缓存主机 AtomicCounter=false）=帽不生效=现行行为（如实登记）' );
		$this->assertFalse( \WinkGatekeeper\Gates\CommentGate::stub_replay_over( 50 ), '恰满帽不拒' );
		$this->assertTrue( \WinkGatekeeper\Gates\CommentGate::stub_replay_over( 51 ), '超帽拒（第 51 次起）' );

		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/gates/CommentGate.php' );
		$this->assertStringContainsString( 'stub_replay_exceeded', $src, 'S3-F9：验证点接线在场' );
	}

	/* ---------------- 已修件盘点锚（0.8.7 落地确认——防回归） ---------------- */

	public function test_previously_fixed_items_stay_fixed(): void {
		// S6-F2：信用缓存键含昵称（0.8.7 已修——盘点锚）。
		$ct = (string) file_get_contents( dirname( __DIR__ ) . '/includes/gates/CommentTrust.php' );
		$this->assertStringContainsString( 'cache_key( \'g\', $email . \'|\' . $author', $ct, 'S6-F2 盘点锚：g 键含昵称' );
		// S1-F2 盘点锚：CSV 全列中和件在场。
		$this->assertStringContainsString( 'neutralize_csv_formula', (string) file_get_contents( dirname( __DIR__ ) . '/includes/stats/InterceptDigest.php' ) );
		$this->assertNotEmpty( InterceptDigest::EXPORT_CSV_COLUMNS );
	}
}
