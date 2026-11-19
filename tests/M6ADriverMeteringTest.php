<?php
/**
 * M6 批 A · P1-2 计量接线修复回归测试（2026-11-15）。
 *
 * 根因（审计 P1-2，P3-F1/F2 实锤）：DeepSeekReviewDriver 从未给继承属性 $driver_id
 * 赋值 → 基类默认 '' → OpenAiCompatibleReviewDriver::call() 的 usage_key='' →
 * RemoteClient:179-181 跳过计量 → 云审零计量/200 日配额闸死/事件 driver=gate（meta
 * 自相矛盾）/Tab③Tab⑥ 全虚报。
 *
 * 修复契约（M6 定稿 §0-2）：**基类** OpenAiCompatibleReviewDriver 构造器尾部统一接线
 * `$this->driver_id = static::DRIVER_ID`（未来 openai_custom/moli 子类自动免疫）；
 * 基类自身无 DRIVER_ID 常量（直接实例化形态，如单测 fake）→ 保持 ''（defined 守卫，
 * 存量行为零变化）。
 *
 * 回归四钉（施工图 §2 A-2）：① usage 回调收到非空键='deepseek'；② 事件 verdict
 * driver=deepseek（非 gate/空）；③ Usage::get()['counters']['deepseek'] 经
 * bump_option 通道递增（Bootstrap:347 生产接线同一消费端）；④ 播种计数至配额 →
 * Usage::quota_reached 判据可达（Bootstrap halt_check 的原料，纯函数面）。
 *
 * 桩注记：WinkGatekeeper\Stats 命名空间桩（get_option/update_option/current_time）
 * 与 StatsCacheRecordBridgeTest 同款同 $GLOBALS 键——先后加载互操作（function_exists
 * 守卫幂等，先载者生效、行为逐字一致）。
 *
 * @package WinkGatekeeper\Tests
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Stats {

	$GLOBALS['wk_stats_option_store']  = array();
	$GLOBALS['wk_stats_calls']         = array( 'update' => array(), 'error_log' => array(), 'get' => array() );
	$GLOBALS['wk_stats_update_result'] = true;

	if ( ! function_exists( 'WinkGatekeeper\Stats\get_option' ) ) {
		/**
		 * get_option 桩（StatsCacheRecordBridgeTest 同款）。
		 *
		 * @param string $name    option 名。
		 * @param mixed  $default 缺省值。
		 * @return mixed
		 */
		function get_option( string $name, $default = false ) {
			$GLOBALS['wk_stats_calls']['get'][] = $name;
			return array_key_exists( $name, $GLOBALS['wk_stats_option_store'] )
				? $GLOBALS['wk_stats_option_store'][ $name ]
				: $default;
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\update_option' ) ) {
		/**
		 * update_option 桩（StatsCacheRecordBridgeTest 同款）。
		 *
		 * @param string $name     option 名。
		 * @param mixed  $value    值。
		 * @param mixed  $autoload 自动加载（忽略）。
		 * @return bool
		 */
		function update_option( string $name, $value, $autoload = null ): bool {
			$GLOBALS['wk_stats_calls']['update'][] = $name;
			if ( $GLOBALS['wk_stats_update_result'] ) {
				$GLOBALS['wk_stats_option_store'][ $name ] = $value;
			}
			return $GLOBALS['wk_stats_update_result'];
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\current_time' ) ) {
		/**
		 * current_time 桩（StatsCacheRecordBridgeTest 同款 + Y-m-d 档）。
		 *
		 * @param string $type 类型。
		 * @return string
		 */
		function current_time( string $type ): string {
			if ( 'Ymd' === $type ) {
				return '20261110';
			}
			if ( 'Y-m-d' === $type ) {
				return '2026-11-10';
			}
			return '';
		}
	}

	if ( ! function_exists( 'WinkGatekeeper\Stats\error_log' ) ) {
		/**
		 * error_log 桩（捕获不落盘）。
		 *
		 * @param string $message 消息。
		 * @return bool
		 */
		function error_log( string $message ): bool {
			$GLOBALS['wk_stats_calls']['error_log'][] = $message;
			return true;
		}
	}
}

namespace WinkGatekeeper\Tests {

	use PHPUnit\Framework\TestCase;
	use WinkGatekeeper\Drivers\Review\DeepSeekReviewDriver;
	use WinkGatekeeper\Drivers\Review\OpenAiCompatibleReviewDriver;
	use WinkGatekeeper\Http\RemoteClient;
	use WinkGatekeeper\Stats\Usage;

	final class M6ADriverMeteringTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['wk_stats_option_store']  = array();
			$GLOBALS['wk_stats_calls']         = array( 'update' => array(), 'error_log' => array(), 'get' => array() );
			$GLOBALS['wk_stats_update_result'] = true;
		}

		/**
		 * 构造 DeepSeek 驱动（fake transport + 计量捕获）。
		 *
		 * @param array<int, array<string, mixed>> $responses 依次返回的 WP HTTP 形态响应。
		 * @return array{driver: DeepSeekReviewDriver, requests: array<int, array>, usage_keys: array<int, string>}
		 */
		private static function makeDeepSeek( array $responses ): array {
			$request_log = array();
			$usage_keys  = array();
			$last        = null;
			$transport   = static function ( string $url, array $args ) use ( &$request_log, &$responses, &$last ) {
				$request_log[] = array( 'url' => $url, 'args' => $args );
				$resp = array_shift( $responses );
				if ( null !== $resp ) {
					$last = $resp;
				} else {
					$resp = $last;
				}
				return $resp;
			};
			$client = new RemoteClient(
				$transport,
				static function ( string $key ) use ( &$usage_keys ): void {
					$usage_keys[] = $key;
				},
				static function ( int $ms ): void {} // 零等待。
			);
			$config = array(
				'api_key'        => 'sk-test',
				'base_url'       => 'https://api.deepseek.com',
				'model'          => 'deepseek-flash',
				'timeout'        => 8,
				'daily_quota'    => 200,
				'fail_mode'      => 'review',
				'min_confidence' => 0.8,
				'two_stage'      => false,
				'upgrade_model'  => 'deepseek-reasoner',
				'system_prompt'  => 'system',
			);
			return array(
				'driver'     => new DeepSeekReviewDriver( $config, $client, static function ( array $rec ): void {} ), // K2 sink 注入（M5U4 同款——单测环境无 WP option）。
				'requests'   => &$request_log,
				'usage_keys' => &$usage_keys,
			);
		}

		/**
		 * chat/completions 200 响应（判定 pass 高置信）。
		 *
		 * @return array<string, mixed>
		 */
		private static function chatResponse(): array {
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => (string) json_encode( array(
					'choices' => array( array(
						'message'       => array( 'role' => 'assistant', 'content' => (string) json_encode( array( 'verdict' => 'pass', 'confidence' => 0.95, 'reason' => 'Normal', 'hits' => array() ) ) ),
						'finish_reason' => 'stop',
					) ),
					'usage'   => array( 'prompt_tokens' => 100, 'completion_tokens' => 20 ),
				) ),
			);
		}

		/* ===============================================================
		 * 钉① usage 回调收到非空键='deepseek'（P1-2 主修面）
		 * =============================================================== */

		public function test_usage_callback_receives_deepseek_key(): void {
			$env = self::makeDeepSeek( array( self::chatResponse() ) );

			$env['driver']->check( '张三\n好评论', array( 'author' => '张三' ) );

			self::assertNotEmpty( $env['usage_keys'], '计量回调必须被触发（usage_key 空串=RemoteClient 跳过计量——P1-2 主缺陷）' );
			self::assertSame( array( 'deepseek' ), $env['usage_keys'], 'usage_key 必须为 deepseek（K1 传输脊键=驱动 id）' );
		}

		/* ===============================================================
		 * 钉② verdict/事件归桶 driver=deepseek（非空非 gate）
		 * =============================================================== */

		public function test_verdict_carries_deepseek_driver_id(): void {
			$env = self::makeDeepSeek( array( self::chatResponse() ) );

			$verdict = $env['driver']->check( '张三\n好评论', array( 'author' => '张三' ) );

			self::assertSame( 'deepseek', $verdict->driver_id(), '事件/统计 driver 归桶=deepseek（修复前空串=审计实测 driver=gate 自相矛盾）' );
		}

		public function test_error_verdict_also_carries_deepseek_driver_id(): void {
			// 传输失败路径（error verdict 收口）——归桶同样必须正确（审计事件 0002/0003 复证面）。
			$env = self::makeDeepSeek( array( array(
				'response' => array( 'code' => 500 ),
				'headers'  => array(),
				'body'     => 'oops',
			) ) );

			$verdict = $env['driver']->check( '张三\n好评论', array( 'author' => '张三' ) );

			self::assertSame( 'error', $verdict->verdict() );
			self::assertSame( 'deepseek', $verdict->driver_id(), 'error verdict driver 同归 deepseek' );
		}

		/* ===============================================================
		 * 钉③ 基类接线契约：未来子类自动免疫（§0-2）
		 * =============================================================== */

		public function test_future_subclass_driver_id_auto_wired(): void {
			$usage_keys = array();
			$transport  = static function (): array {
				return array( 'response' => array( 'code' => 500 ), 'headers' => array(), 'body' => 'x' );
			};
			$client = new RemoteClient(
				$transport,
				static function ( string $key ) use ( &$usage_keys ): void {
					$usage_keys[] = $key;
				},
				static function ( int $ms ): void {}
			);
			$driver = new class( array( 'api_key' => 'sk', 'base_url' => 'https://api.example.com' ), $client ) extends OpenAiCompatibleReviewDriver {
				public const DRIVER_ID = 'openai_custom';
			};
			// 500 响应走 error 收口（不触发 K2 回写）——本测只钉 K1 计量键归桶。

			$driver->check( '内容', array() );

			// 500 响应触发传输重试（3 次尝试）——K1 含失败按次口径：每次尝试各计 1，
			// 全部归子类 DRIVER_ID（修复前空串=整链零计量）。
			self::assertNotEmpty( $usage_keys, '基类构造器统一接线 static::DRIVER_ID——未来子类自动免疫（§0-2）' );
			self::assertSame( array( 'openai_custom', 'openai_custom', 'openai_custom' ), $usage_keys );
		}

		public function test_base_constructor_wiring_present_in_source(): void {
			$src = file_get_contents( dirname( __DIR__ ) . '/includes/drivers/review/OpenAiCompatibleReviewDriver.php' );
			self::assertIsString( $src );
			self::assertStringContainsString(
				'$this->driver_id = static::DRIVER_ID;',
				$src,
				'基类构造器尾部统一接线（修复落点 §0-2）'
			);
		}

		/* ===============================================================
		 * 钉④ 配额闸判据可达（Bootstrap halt_check 原料）
		 * =============================================================== */

		public function test_usage_counter_increments_via_option_channel(): void {
			// Bootstrap:347 生产接线=array( Usage::class, 'bump_option' )——同一消费端递增面。
			Usage::bump_option( 'deepseek' );
			Usage::bump_option( 'deepseek' );

			$usage = Usage::get();
			self::assertSame( 2, (int) ( $usage['counters']['deepseek'] ?? 0 ), 'counters.deepseek 递增（Tab③ 今日 N 次/额度 N/200 的数据源）' );
		}

		public function test_quota_reached_criterion_reachable_for_deepseek(): void {
			for ( $i = 0; $i < 200; $i++ ) {
				Usage::bump_option( 'deepseek' );
			}

			self::assertTrue(
				Usage::quota_reached( Usage::get(), 'deepseek', 200 ),
				'播种至配额 200 → 判据可达（Bootstrap halt_check 当日停转生效的原料面）'
			);
		}
	}
}
