<?php
/**
 * CaptchaTriInterpreter——verify_tri 三态 → Verdict/文案档/事件 meta 集中翻译共享件
 * （02 §2.1【v1.5·C1】映射表规范性落地；DESIGN-C1 §2.2）。
 *
 * 定位：三闸 captcha 段不再各自消费驱动返回——「三闸各一份 switch 是三份漂移面」，
 * 统一经本件集中翻译。纯逻辑零 WP 依赖（G5），金测试覆盖映射表全行。
 *
 * 映射表（02 §2.1 v1.5 规范性表，本类 interpret() 逐行实现）：
 *   ┌──────────────────────────────┬─────────┬────────────────────────────────┬──────────────┬────────────┐
 *   │ verify_tri 返回              │ Verdict │ rule_hit                       │ 文案档       │ 统计口径   │
 *   ├──────────────────────────────┼─────────┼────────────────────────────────┼──────────────┼────────────┤
 *   │ pass + score≥钳后阈值        │ pass    │ 驱动 rule（如 CaptchaCode=1）  │ —            │ captcha_pass│
 *   │ pass + score<阈值            │ reject  │ captcha_failed+驱动 rule       │ user_retry   │ captcha_reject│
 *   │ reject                       │ reject  │ 驱动 rule                      │ failurePolicy│ captcha_reject│
 *   │ error（四类终态）            │ error   │ remote_<category>/contract_mismatch │ MSG_*  │ error 口径 │
 *   │ error+pass_limited+限流内    │ error+放行 │ remote_transport_degraded   │ —（不拦）    │ error 口径 │
 *   └──────────────────────────────┴─────────┴────────────────────────────────┴──────────────┴────────────┘
 *
 * 阈值归属：分数采集归驱动、阈值是站点策略归闸（本件读 captcha.min_score，构造器注入
 * 已钳值）；二态驱动恒 score=1.0 → 任何钳后阈值下自动 pass。
 *
 * score 归一化契约：评分制驱动折算 (0,1] 开区间；0 分/解析不出分=按 reject 处理
 * （0.0 永不出现在本契约——防御性兜底在本类收口）。
 *
 * legacy 垫片：legacy_to_tri() 纯函数——method_exists($driver,'verify_tri') 探测失败时
 * 闸调本函数把旧 verify():?string 契约折算为 tri（null→pass/1.0；string 且 meta.rule 含
 * remote_/contract_mismatch → error；其余 string→reject）。M4 收线确认无旧契约驱动后删。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

use WinkGatekeeper\Verdict;

final class CaptchaTriInterpreter {

	public const RULE_CAPTCHA_FAILED      = 'captcha_failed';
	public const RULE_TRANSPORT_DEGRADED  = 'remote_transport_degraded';
	public const RULE_CONTRACT_MISMATCH   = 'contract_mismatch';

	public const POLICY_BLOCK        = 'block';
	public const POLICY_PASS_LIMITED = 'pass_limited';

	/**
	 * @var float 钳后阈值（0.1–1.0；clamp_threshold 收口）。
	 */
	private $min_score;

	/**
	 * @var string error 态处置策略：block（默认）| pass_limited（仅 transport+限流放行）。
	 *             生效值=站长键 captcha.error_policy 覆盖驱动 errorPolicy() 声明（闸侧归一）。
	 */
	private $error_policy;

	/**
	 * @var callable|null 降级放行限流器 fn(string $ip): bool——true=额度内放行（并计数），
	 *                    false=超额按 block；null=未装配（pass_limited 下 fail-closed）。
	 */
	private $degraded_limiter;

	/**
	 * @param float        $min_score        钳后阈值（闸自 captcha.min_score 经 clamp_threshold 取得）。
	 * @param string       $error_policy     生效 error 处置策略（非法值归 block）。
	 * @param callable|null $degraded_limiter pass_limited 限流器（null=fail-closed）。
	 */
	public function __construct( float $min_score, string $error_policy = self::POLICY_BLOCK, ?callable $degraded_limiter = null ) {
		$this->min_score        = $min_score;
		$this->error_policy     = self::POLICY_PASS_LIMITED === $error_policy ? self::POLICY_PASS_LIMITED : self::POLICY_BLOCK;
		$this->degraded_limiter = $degraded_limiter;
	}

	/**
	 * 阈值下限钳制（Wordfence captcha.php:58-69 先例）：max(0.1, min(1.0, (float)$configured))
	 * ——防站长配 0 验证码形同虚设 / 配 1.5 恒拒；未配置/非法值归默认 0.5。
	 *
	 * @param mixed $configured 设置树 captcha.min_score 原值。
	 * @return float
	 */
	public static function clamp_threshold( $configured ): float {
		if ( ! is_numeric( $configured ) ) {
			return 0.5;
		}
		return max( 0.1, min( 1.0, (float) $configured ) );
	}

	/**
	 * legacy 垫片折算（02 §2.1【v1.5·C1】）：旧 verify():?string 契约 → tri 结构。
	 *
	 * @param string|null          $error 旧契约返回值（null=通过）。
	 * @param array<string, mixed> $meta  last_verify_meta() 侧通道 meta。
	 * @return array{state: string, score: float|null, message: string, meta: array<string, mixed>}
	 */
	public static function legacy_to_tri( ?string $error, array $meta = array() ): array {
		if ( null === $error ) {
			return array( 'state' => 'pass', 'score' => 1.0, 'message' => '', 'meta' => $meta );
		}
		$rule = isset( $meta['rule'] ) && is_string( $meta['rule'] ) ? $meta['rule'] : '';
		$is_error = ( '' !== $rule && 0 === strpos( $rule, 'remote_' ) )
			|| self::RULE_CONTRACT_MISMATCH === $rule;
		if ( $is_error ) {
			return array( 'state' => 'error', 'score' => null, 'message' => $error, 'meta' => $meta );
		}
		return array( 'state' => 'reject', 'score' => null, 'message' => $error, 'meta' => $meta );
	}

	/**
	 * 映射表消费（02 §2.1 v1.5 规范性表的唯一实现——三闸共用）。
	 *
	 * @param array{state?: mixed, score?: mixed, message?: mixed, meta?: mixed} $tri       驱动 verify_tri 返回（结构防御性归一）。
	 * @param string                                                             $client_ip 提交者真实 IP（pass_limited 限流键；空=fail-closed）。
	 * @return array{verdict: string, allow: bool, rule: string, hits: string[], message: string, meta: array<string, mixed>, write_cache: bool}
	 *         verdict=事件 Verdict 值；allow=落地放行（error+降级时与 verdict 分离）；
	 *         write_cache=是否写 VerifyCache（仅干净 pass；降级放行 false）。
	 */
	public function interpret( array $tri, string $client_ip = '' ): array {
		$state      = is_string( $tri['state'] ?? null ) ? $tri['state'] : '';
		$tri_meta   = isset( $tri['meta'] ) && is_array( $tri['meta'] ) ? $tri['meta'] : array();
		$message    = isset( $tri['message'] ) && is_string( $tri['message'] ) ? $tri['message'] : '';
		$driver_rule = isset( $tri_meta['rule'] ) && is_string( $tri_meta['rule'] ) && '' !== $tri_meta['rule'] ? $tri_meta['rule'] : '';

		if ( 'pass' === $state ) {
			$score = is_numeric( $tri['score'] ?? null ) ? (float) $tri['score'] : 0.0;
			if ( $score <= 0.0 ) {
				// 契约防御：0 分/解析不出分按 reject 处理（0.0 永不出现在本契约）。
				return $this->score_reject( $driver_rule, array( 'score' => null, 'threshold' => $this->min_score ) + $tri_meta );
			}
			$meta = array( 'score' => $score, 'threshold' => $this->min_score ) + $tri_meta;
			if ( $score >= $this->min_score ) {
				return array(
					'verdict'    => Verdict::PASS,
					'allow'      => true,
					'rule'       => $driver_rule,
					'hits'       => '' !== $driver_rule ? array( $driver_rule ) : array(),
					'message'    => '',
					'meta'       => $meta,
					'write_cache' => true,
				);
			}
			return $this->score_reject( $driver_rule, $meta );
		}

		if ( 'reject' === $state ) {
			$rule = '' !== $driver_rule ? $driver_rule : self::RULE_CAPTCHA_FAILED;
			return array(
				'verdict'    => Verdict::REJECT,
				'allow'      => false,
				'rule'       => $rule,
				'hits'       => array( $rule ),
				'message'    => $message,
				'meta'       => $tri_meta,
				'write_cache' => false,
			);
		}

		if ( 'error' === $state ) {
			return $this->interpret_error( $tri_meta, $driver_rule, $message, $client_ip );
		}

		// 未知态防御：按契约不符收口（fail-closed）。
		return $this->interpret_error(
			array( 'rule' => self::RULE_CONTRACT_MISMATCH ) + $tri_meta,
			self::RULE_CONTRACT_MISMATCH,
			$message,
			$client_ip
		);
	}

	/**
	 * error 态消费：默认 block（现行降级矩阵）；pass_limited 仅 transport 类终态 + 限流内放行
	 * （事件 verdict=error、rule_hit=remote_transport_degraded、落地放行、不写 VerifyCache）；
	 * semantic/contract/parse 终态恒 block（配置错放行=自废武功）；限流超额按 block。
	 *
	 * @param array<string, mixed> $tri_meta 驱动 meta（category/rule/raw）。
	 * @param string               $driver_rule meta.rule 原值。
	 * @param string               $message 降级文案（MSG_TRANSPORT/MSG_CONFIG）。
	 * @param string               $client_ip 限流键。
	 * @return array{verdict: string, allow: bool, rule: string, hits: string[], message: string, meta: array<string, mixed>, write_cache: bool}
	 */
	private function interpret_error( array $tri_meta, string $driver_rule, string $message, string $client_ip ): array {
		$rule         = '' !== $driver_rule ? $driver_rule : 'remote_error';
		$is_transport = ( isset( $tri_meta['category'] ) && 'transport' === $tri_meta['category'] )
			|| ( 0 === strpos( $rule, 'remote_transport' ) );

		if ( self::POLICY_PASS_LIMITED === $this->error_policy && $is_transport && '' !== $client_ip && null !== $this->degraded_limiter
			&& call_user_func( $this->degraded_limiter, $client_ip ) ) {
			return array(
				'verdict'    => Verdict::ERROR,
				'allow'      => true,
				'rule'       => self::RULE_TRANSPORT_DEGRADED,
				'hits'       => array( self::RULE_TRANSPORT_DEGRADED ),
				'message'    => '',
				'meta'       => array( 'degraded_pass' => true ) + $tri_meta, // 原 rule/category/raw 保留供排障。
				'write_cache' => false,
			);
		}

		return array(
			'verdict'    => Verdict::ERROR,
			'allow'      => false,
			'rule'       => $rule,
			'hits'       => array( $rule ),
			'message'    => $message,
			'meta'       => $tri_meta,
			'write_cache' => false,
		);
	}

	/**
	 * 评分拒（pass + score<阈值）：reject verdict + captcha_failed + 驱动 rule 双标签 +
	 * user_retry 档（message 留空——闸回落自家 captcha_failed 文案链，给刷新重试台阶）。
	 *
	 * @param string               $driver_rule 驱动 rule。
	 * @param array<string, mixed> $meta        含 score/threshold 的 meta。
	 * @return array{verdict: string, allow: bool, rule: string, hits: string[], message: string, meta: array<string, mixed>, write_cache: bool}
	 */
	private function score_reject( string $driver_rule, array $meta ): array {
		$hits = array( self::RULE_CAPTCHA_FAILED );
		if ( '' !== $driver_rule && self::RULE_CAPTCHA_FAILED !== $driver_rule ) {
			$hits[] = $driver_rule;
		}
		return array(
			'verdict'    => Verdict::REJECT,
			'allow'      => false,
			'rule'       => self::RULE_CAPTCHA_FAILED,
			'hits'       => $hits,
			'message'    => '',
			'meta'       => $meta,
			'write_cache' => false,
		);
	}
}
