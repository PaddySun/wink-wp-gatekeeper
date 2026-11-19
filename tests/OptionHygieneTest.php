<?php
/**
 * option 键卫生测试（M4UX 定稿 §2 P0-2/P0-7，F1 批）。
 *
 * P0-2（R-AK §D P0-1）：uninstall.php 漏删 wink_gatekeeper_health（云审停转状态树）——
 *   开 cleanup_on_uninstall 卸载后残留，重装首日读到旧停转态（「新装第一天驱动被停转」
 *   诡异态，静态推断）。钉两面：①uninstall delete_option 键集 === 全插件 OPTION_NAME
 *   常量集（新增 option 自动入列防再漂移）；②头注清单与实际同步。
 *
 * P0-7（R-AK §D P0-6）：CommentGate/DiePage 两处 'wink_gatekeeper_settings' 魔法串
 *   换 SettingsPage::OPTION_NAME 常量（规范源唯一——防未来改 option 名时字面量位必漏）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Tests;

use PHPUnit\Framework\TestCase;
use WinkGatekeeper\Audit\ExistingAuditTask;
use WinkGatekeeper\Gates\TestMode;
use WinkGatekeeper\Settings\SettingsPage;
use WinkGatekeeper\Stats\DriverHealth;
use WinkGatekeeper\Stats\LlmUsage;
use WinkGatekeeper\Stats\StatsCache;
use WinkGatekeeper\Stats\Usage;
use WinkGatekeeper\UrlAudit\UrlAuditWorker;

final class OptionHygieneTest extends TestCase {

	private static function uninstall_src(): string {
		$src = file_get_contents( __DIR__ . '/../uninstall.php' );
		self::assertIsString( $src, 'uninstall.php 必须可读' );
		return (string) $src;
	}

	/* ---------- P0-2：uninstall 键集一致性 ---------- */

	public function test_uninstall_deletes_exactly_all_plugin_option_names(): void {
		$src = self::uninstall_src();

		preg_match_all( "/delete_option\(\s*'([^']+)'\s*\)/", $src, $matches );
		$deleted = array_values( array_unique( $matches[1] ) );
		sort( $deleted );

		// 全插件 OPTION_NAME 常量集（新增 option 挂进本清单自动受钉——防漏删再漂移）。
		// 【v1.9·M5 回写·审计 C2】M5 三 option 扩钉：LlmUsage / UrlAuditWorker 增量队列 /
		// ExistingAuditTask 进度。【M6·批A·P1-3】UrlAuditWorker 批量回捞队列扩钉
		// （新 option 落位即入卸载清单——同族卫生纪律，非断言翻转）。
		// 【M6·批B·B-5】UrlAuditWorker 配额暂停边沿标记扩钉（同上，非断言翻转）。
		// 【M6·批C·C-3】UrlAuditWorker 存量网址核查运行记录扩钉（同上，非断言翻转）。
		// 【W3·批C】N1 probe log + R5 向导状态两新 option 扩钉（同上，非断言翻转）。
		$expected = array_values( array_unique( array(
			SettingsPage::OPTION_NAME,
			TestMode::OPTION_NAME, // 与 SettingsPage 同值——同键去重后仍须在删除清单。
			StatsCache::OPTION_NAME,
			Usage::OPTION_NAME,
			DriverHealth::OPTION_NAME,
			LlmUsage::OPTION_NAME,
			UrlAuditWorker::OPTION_INCR_QUEUE,
			UrlAuditWorker::OPTION_BATCH_QUEUE,
			UrlAuditWorker::OPTION_PAUSE_EDGE,
			UrlAuditWorker::OPTION_BACKLOG_RECORD,
			UrlAuditWorker::OPTION_FETCH_STREAK, // 【M6·批H2·H2-2】抓取连续未成功计数扩钉（同上，非断言翻转）。
			ExistingAuditTask::OPTION_PROGRESS,
			SettingsPage::OPTION_PROBE_LOG,   // 【W3·批C·N1】测试凭据最近一次测试态。
			SettingsPage::OPTION_WIZARD_STATE, // 【W3·批C·R5】首启向导状态。
		) ) );
		sort( $expected );

		$this->assertSame( $expected, $deleted, 'uninstall delete_option 键集 === 全插件 OPTION_NAME 常量集（漏删=卸载残留）' );
		$this->assertContains( DriverHealth::OPTION_NAME, $deleted, 'wink_gatekeeper_health（云审停转状态树）必须在卸载清单' );
		$this->assertContains( LlmUsage::OPTION_NAME, $deleted, 'wink_gatekeeper_llm_usage（M5 LLM 消耗统计）必须在卸载清单' );
		$this->assertContains( UrlAuditWorker::OPTION_INCR_QUEUE, $deleted, 'wink_gatekeeper_url_audit_incr_queue（M5 网址核查增量队列）必须在卸载清单' );
		$this->assertContains( UrlAuditWorker::OPTION_BATCH_QUEUE, $deleted, 'wink_gatekeeper_url_audit_batch_queue（M6 批A 网址核查批量回捞队列）必须在卸载清单' );
		$this->assertContains( UrlAuditWorker::OPTION_PAUSE_EDGE, $deleted, 'wink_gatekeeper_url_audit_pause_edge（M6 批B 暂停边沿标记）必须在卸载清单' );
		$this->assertContains( UrlAuditWorker::OPTION_BACKLOG_RECORD, $deleted, 'wink_gatekeeper_url_audit_backlog_record（M6 批C 存量网址核查运行记录）必须在卸载清单' );
		$this->assertContains( ExistingAuditTask::OPTION_PROGRESS, $deleted, 'wink_gatekeeper_audit_existing_progress（M5 存量审核进度）必须在卸载清单' );
		$this->assertContains( SettingsPage::OPTION_PROBE_LOG, $deleted, 'wink_gatekeeper_probe_log（W3·批C·N1 测试凭据测试态）必须在卸载清单' );
		$this->assertContains( SettingsPage::OPTION_WIZARD_STATE, $deleted, 'wink_gatekeeper_wizard_state（W3·批C·R5 首启向导状态）必须在卸载清单' );
	}

	public function test_uninstall_header_documents_all_options(): void {
		$src = self::uninstall_src();

		// 【AUDIT-FIX 2026-11-20·A1-F11】12→13（注释漂移修正：delete_option 实删 13 处
		// 而头注自 W3 批 C 起漏记 1——数字对齐实况，非新增 option；P3-F5/A1-F9 卸载链批同步）。
		$this->assertStringContainsString( '13 个 option', $src, '头注清单与实际删除数同步（4→7·M5 三 option 补录；7→8·M6 批A 回捞队列补录；8→9·M6 批B 暂停边沿标记补录；9→10·M6 批C 运行记录补录；10→12·W3 批C probe log+向导状态补录；12→13·A1-F11 注释漂移修正）' );
		$this->assertStringNotContainsString( '删 12 个 option', $src, '旧计数 12 退役（A1-F11——实际 13）' );
		$this->assertStringContainsString( DriverHealth::OPTION_NAME, $src, '头注点名 health 键（R-AK §D P0-1：头注自己写的清单就是承诺）' );
		$this->assertStringContainsString( LlmUsage::OPTION_NAME, $src, '头注点名 llm_usage 键（M5·审计 C2）' );
		$this->assertStringContainsString( UrlAuditWorker::OPTION_INCR_QUEUE, $src, '头注点名 url_audit_incr_queue 键（M5·审计 C2）' );
		$this->assertStringContainsString( ExistingAuditTask::OPTION_PROGRESS, $src, '头注点名 audit_existing_progress 键（M5·审计 C2）' );
		$this->assertStringContainsString( SettingsPage::OPTION_PROBE_LOG, $src, '头注点名 probe_log 键（W3·批C·N1）' );
		$this->assertStringContainsString( SettingsPage::OPTION_WIZARD_STATE, $src, '头注点名 wizard_state 键（W3·批C·R5）' );
	}

	/* ---------- 【v1.9·M5 回写·审计 C2】comment meta 键集钉 ---------- */

	public function test_uninstall_cleans_m5_comment_meta_keys(): void {
		$src = self::uninstall_src();

		// M5 两 meta 键与既有 wink_gatekeeper_archive 同款 delete_metadata 逐键纪律。
		foreach ( array( 'wink_gatekeeper_archive', ExistingAuditTask::META_KEY, UrlAuditWorker::META_KEY ) as $meta_key ) {
			$this->assertMatchesRegularExpression(
				"/delete_metadata\(\s*'comment'\s*,\s*0\s*,\s*'" . preg_quote( $meta_key, '/' ) . "'\s*,\s*''\s*,\s*true\s*\)/",
				$src,
				"comment meta {$meta_key} 必须逐键 delete_metadata（M5 审计 C2：存量审核/网址核查标记不清则重装后「已处理」假象）"
			);
		}
		$this->assertStringContainsString( ExistingAuditTask::META_KEY, $src, '头注/步骤注释须点名 wink_audit_existing' );
		$this->assertStringContainsString( UrlAuditWorker::META_KEY, $src, '头注/步骤注释须点名 url_audit meta 键' );
	}

	/* ---------- P0-7：option 名魔法串清零 ---------- */

	public function test_gates_and_intercept_use_option_name_constant(): void {
		foreach ( array(
			'includes/gates/CommentGate.php',
			'includes/intercept/DiePage.php',
		) as $rel ) {
			$src = file_get_contents( __DIR__ . '/../' . $rel );
			self::assertIsString( $src, "{$rel} 必须可读" );

			$this->assertStringNotContainsString( "'wink_gatekeeper_settings'", $src, "{$rel} 魔法串必须换 SettingsPage::OPTION_NAME 常量（防漂移）" );
			$this->assertStringContainsString( 'SettingsPage::OPTION_NAME', $src, "{$rel} 须引用规范源常量" );
		}
	}
}
