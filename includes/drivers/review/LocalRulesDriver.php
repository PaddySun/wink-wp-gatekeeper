<?php
/**
 * LocalRules 本地规则审核驱动（05 §2.1；上游 baby-wp-comment-filter 四闸原样迁移）。
 *
 * 四闸依序短路：
 *   ① 字数下限（min_length，0=不限；mb 口径按字符计——只算正文）
 *   ② 字数上限（max_length，默认 1800——只算正文）
 *   ③ 中文检测（[\x{4e00}-\x{9fa5}]，require_chinese 可关——只算正文）
 *   ④ 关键词+正则（custom_banned_words 逐行 + WP 讨论设置 disallowed_keys 联动——
 *     取词回调注入保持零 WP 依赖；/regex/flags 形态按正则匹配，其余按不区分大小写子串；
 *     匹配对象 =「昵称\n邮箱\n正文」送审拼接串，05 §2.1 v1.3 / F36）
 *
 * 判定恒二值 pass/reject（本地规则高置信，无 review 态——04 §4 统计示例同步口径）。
 * M1 以内部类形态被 CommentGate 调用并挂接 ReviewDriverInterface（统一闸内调用面）；
 * LocalRules → 云端链路的正式串联 M3 重构（F14，编排位见 CommentGate 步骤 4 注释）。
 *
 * 正则防呆（v1.2 口径）：逐行 @preg_match 前置校验，非法正则跳过该行并记 notes
 * 「regex invalid: line N」；运行期 preg 失败（回溯限制等）同样跳过该行——
 * 站长写错一行正则不应导致评论全灭。notes 由闸并入事件 meta（04 §3）。
 *
 * 纯逻辑类：无远端调用（不经传输脊）、零 WP 依赖。
 *
 * @package WinkGatekeeper\Drivers\Review
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Drivers\Review;

use WinkGatekeeper\Drivers\ReviewDriverInterface;
use WinkGatekeeper\Verdict;

final class LocalRulesDriver implements ReviewDriverInterface {

	public const DRIVER_ID = 'local_rules';

	/**
	 * @var array<string, mixed> review.local 设置子树。
	 */
	private $settings;

	/**
	 * @var callable|null WP disallowed_keys 取词回调 fn(): string[]（边界层注入）。
	 */
	private $wp_keywords;

	/**
	 * 最近一次 check() 产生的备注（非法正则跳过行等，落事件 meta）。
	 *
	 * @var string[]
	 */
	private $notes = array();

	/**
	 * @param array<string, mixed> $settings    review.local 子树（04 §2 键名沿上游）。
	 * @param callable|null        $wp_keywords WP 讨论设置关键词取词回调。
	 */
	public function __construct( array $settings, ?callable $wp_keywords = null ) {
		$this->settings    = $settings;
		$this->wp_keywords = $wp_keywords;
	}

	/**
	 * 驱动注册元数据（05 §4 六维声明；DriverRegistry 注册用）。
	 *
	 * @return array<string, mixed>
	 */
	public static function registry_entry(): array {
		return array(
			'id'          => self::DRIVER_ID,
			'type'        => 'review',
			'class'       => self::class,
			'label'       => '本地规则四闸（上游迁移）',
			'billing'     => 'free',
			'frontend'    => array(), // review 类无前端形态.
			'error_map'   => array(),
			'api_version' => 'local-rules-1',
			'settings'    => array(
				'min_length'          => array( 'type' => 'number', 'sanitize' => 'absint' ),
				'max_length'          => array( 'type' => 'number', 'sanitize' => 'absint' ),
				'require_chinese'     => array( 'type' => 'number', 'sanitize' => 'absint' ),
				'custom_banned_words' => array( 'type' => 'textarea', 'sanitize' => 'textarea' ),
				'use_wp_keywords'     => array( 'type' => 'number', 'sanitize' => 'absint' ),
			),
			'milestone'   => 'M1',
		);
	}

	/**
	 * 四闸审核（依序短路）。
	 *
	 * 送审拼接消费约定（02 §2.2 v1.3 / 05 §2.1 v1.3，F36）：
	 *   - $content = 闸统一拼装的「昵称 \n 邮箱 \n 正文」三行拼接串（闸拼装，驱动不各自拼；
	 *     review.include_author_fields=false 时闸退回仅正文）——闸④关键词/正则
	 *     （含 WP disallowed_keys 联动）的匹配对象即此串；
	 *   - $meta['content'] = 评论正文原值——闸①②③（字数下限/上限、中文检测）仍只算正文：
	 *     拼接串稀释字数语义、昵称几乎恒含中文会架空中文闸（02 §2.2 例外条款）；
	 *     meta 不带 content（直调/旧形态）时回落 $content。
	 *
	 * @param string               $content 送审串（拼接串或正文，由闸拼装决定）。
	 * @param array<string, mixed> $meta    开放键集（content/author/email 原值；notes 并入事件 meta）。
	 * @return Verdict 恒二值 pass/reject。
	 */
	public function check( string $content, array $meta ): Verdict {
		$this->notes = array();

		$body = isset( $meta['content'] ) && is_string( $meta['content'] ) ? $meta['content'] : $content;

		$min_length = (int) ( $this->settings['min_length'] ?? 0 );
		$max_length = (int) ( $this->settings['max_length'] ?? 1800 );

		$length = mb_strlen( trim( $body ), 'UTF-8' );

		// 闸①：字数下限（0 = 不限；只算正文）。
		if ( $min_length > 0 && $length < $min_length ) {
			return Verdict::reject( self::DRIVER_ID, 'too_short', array( 'too_short' ) );
		}

		// 闸②：字数上限（只算正文）。
		if ( $max_length > 0 && $length > $max_length ) {
			return Verdict::reject( self::DRIVER_ID, 'too_long', array( 'too_long' ) );
		}

		// 闸③：中文检测（可关；只算正文）。
		if ( ! empty( $this->settings['require_chinese'] )
			&& 1 !== preg_match( '/[\x{4e00}-\x{9fa5}]/u', $body ) ) {
			return Verdict::reject( self::DRIVER_ID, 'no_chinese', array( 'no_chinese' ) );
		}

		// 闸④：关键词 + 正则（匹配对象 = 送审拼接串 $content，05 §2.1 v1.3）。
		$hits = $this->match_banned_words( $content );
		if ( array() !== $hits ) {
			return Verdict::reject( self::DRIVER_ID, 'banned_word', $hits );
		}

		return Verdict::pass( self::DRIVER_ID, '', array() );
	}

	/**
	 * 最近一次 check() 的备注（非法/失败正则行等）；闸并入事件 meta。
	 *
	 * @return string[]
	 */
	public function notes(): array {
		return $this->notes;
	}

	/**
	 * 词表匹配：自定义逐行 + WP disallowed_keys（use_wp_keywords 开关 + 注入回调）。
	 *
	 * @param string $content
	 * @return string[] 命中的词/正则行（原样，供事件 hits）。
	 */
	private function match_banned_words( string $content ): array {
		$lines = array();
		$custom = $this->settings['custom_banned_words'] ?? '';
		if ( is_string( $custom ) && '' !== $custom ) {
			$lines = array_merge( $lines, preg_split( '/\r\n|\r|\n/', $custom ) );
		}
		if ( ! empty( $this->settings['use_wp_keywords'] ) && null !== $this->wp_keywords ) {
			$wp_words = call_user_func( $this->wp_keywords );
			if ( is_array( $wp_words ) ) {
				$lines = array_merge( $lines, array_values( array_filter( $wp_words, 'is_string' ) ) );
			}
		}

		$hits = array();
		foreach ( $lines as $index => $line ) {
			if ( ! is_string( $line ) ) {
				continue;
			}
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$line_no = $index + 1;

			if ( $this->is_regex_line( $line ) ) {
				// 前置校验：@preg 编译失败 → 跳过该行（上游直接 preg 会 E_WARNING，此处加固）。
				if ( false === @preg_match( $line, '' ) && PREG_NO_ERROR !== preg_last_error() ) {
					$this->notes[] = "regex invalid: line {$line_no}";
					continue;
				}
				$matched = @preg_match( $line, $content );
				if ( false === $matched || PREG_NO_ERROR !== preg_last_error() ) {
					// 运行期失败（回溯限制等）同样跳过该行。
					$this->notes[] = "regex runtime fail: line {$line_no}";
					preg_last_error(); // 清错误状态。
					continue;
				}
				if ( 1 === $matched ) {
					$hits[] = $line;
				}
				continue;
			}

			if ( false !== stripos( $content, $line ) ) {
				$hits[] = $line;
			}
		}

		return $hits;
	}

	/**
	 * 是否为正则意图行：以 '/' 开头即视为 /regex/flags 形态（含写残的非法形态——
	 * 由前置校验捕获并跳过，而非误当普通词匹配）。
	 *
	 * @param string $line
	 * @return bool
	 */
	private function is_regex_line( string $line ): bool {
		return '/' === $line[0] && strlen( $line ) > 1;
	}
}
