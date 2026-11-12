<?php
/**
 * 三态判定值对象（02 §2.2）。
 *
 * verdict: pass | reject | review | error
 *   - pass / reject / review 为有效判定（review = 入待审，D8）；
 *   - error 为驱动故障态，「不参与定级」——链上比较时被跳过（链即 failover 链），
 *     云端全故障的收口由闸编排负责（M1+，本值对象只提供比较语义）。
 *
 * 携带：reason（驱动理由，落事件表）、hits（命中标签数组）、driver_id、
 * confidence（0–1 可选，LLM 驱动填写；非法/越界一律归 null）。
 *
 * 不可变：构造器私有，只能经工厂方法创建。
 *
 * @package WinkGatekeeper
 */

declare( strict_types=1 );

namespace WinkGatekeeper;

final class Verdict {

	public const PASS   = 'pass';
	public const REJECT = 'reject';
	public const REVIEW = 'review';
	public const ERROR  = 'error';

	/**
	 * 有效判定的严格度（reject > review > pass）。error 不在此表中 = 不参与定级。
	 */
	private const SEVERITY = array(
		self::REJECT => 3,
		self::REVIEW => 2,
		self::PASS   => 1,
	);

	/**
	 * @var string pass|reject|review|error
	 */
	private $verdict;

	/**
	 * @var string 驱动给出的理由（落事件表；云端 reason 仅站长可见，不展示给访客）。
	 */
	private $reason;

	/**
	 * @var array 命中标签数组，如 ["广告", "灌水"]。
	 */
	private $hits;

	/**
	 * @var string 驱动注册 id（DriverRegistry 注册 id）。
	 */
	private $driver_id;

	/**
	 * @var float|null 0–1 置信度，LLM 驱动可选填写。
	 */
	private $confidence;

	/**
	 * @var array<string, mixed> 事件 meta 增量（【v1.7·M3-IP·定稿 §9】——verdict 携带，
	 *                            消费方记录事件时并入 meta：score（0–100 整数直存）/
	 *                            cached（缓存命中）/ soft_quota（软顶命中）/ raw（失败
	 *                            脱敏快照）。缺省空数组=零增量（既有 verdict 形态不变）。
	 */
	private $event_meta;

	/**
	 * @param string     $verdict    判定枚举。
	 * @param string     $reason     理由。
	 * @param array      $hits       命中标签。
	 * @param string     $driver_id  驱动 id。
	 * @param float|null $confidence 0–1 置信度（非法/越界归 null）。
	 * @param array<string, mixed> $event_meta 事件 meta 增量（【v1.7·M3-IP】缺省空）。
	 */
	private function __construct( string $verdict, string $reason, array $hits, string $driver_id, $confidence = null, array $event_meta = array() ) {
		$this->verdict    = $verdict;
		$this->reason     = $reason;
		$this->hits       = $hits;
		$this->driver_id  = $driver_id;
		$this->confidence = self::normalize_confidence( $confidence );
		$this->event_meta = $event_meta;
	}

	public static function pass( string $driver_id = '', string $reason = '', array $hits = array() ): Verdict {
		return new self( self::PASS, $reason, $hits, $driver_id );
	}

	public static function reject( string $driver_id = '', string $reason = '', array $hits = array() ): Verdict {
		return new self( self::REJECT, $reason, $hits, $driver_id );
	}

	public static function review( string $driver_id = '', string $reason = '', array $hits = array(), $confidence = null ): Verdict {
		return new self( self::REVIEW, $reason, $hits, $driver_id, $confidence );
	}

	/**
	 * 故障态：远端调用经传输脊重试穷尽等场景。error 不参与定级（is_rankable() = false）。
	 *
	 * 【v1.4·R4 §D.1】$hits 可选补齐：错误事件 rule_hit=remote_<category> /
	 * driver_halted:<state> 等类别标签（04 §3——rule_hit 列原本恒空只能解 meta JSON，
	 * 补齐后 idx_gate_driver 可直接按类别索引筛选）。
	 *
	 * @param string   $driver_id 驱动 id。
	 * @param string   $reason    理由（remote_<category> / driver_halted:<state> …）。
	 * @param string[] $hits      命中标签（rule_hit 列来源；缺省空=保持旧行为）。
	 */
	public static function error( string $driver_id = '', string $reason = '', array $hits = array() ): Verdict {
		return new self( self::ERROR, $reason, $hits, $driver_id );
	}

	public function verdict(): string {
		return $this->verdict;
	}

	public function reason(): string {
		return $this->reason;
	}

	public function hits(): array {
		return $this->hits;
	}

	public function driver_id(): string {
		return $this->driver_id;
	}

	public function confidence(): ?float {
		return $this->confidence;
	}

	/**
	 * 事件 meta 增量（【v1.7·M3-IP·定稿 §9】IP 信誉域新键的承载面——score/cached/
	 * soft_quota/raw；缺省空数组=既有 verdict 零增量）。消费方记录事件时并入 meta，
	 * 记录代码形态不变（新键自然落 meta——定稿 §9「消费方既有事件写入代码保持形态」）。
	 *
	 * @return array<string, mixed>
	 */
	public function event_meta(): array {
		return $this->event_meta;
	}

	/**
	 * 带事件 meta 增量的副本（不可变语义——with_attempts/with_latency_ms 同模式 wither）。
	 *
	 * @param array<string, mixed> $meta meta 增量（score/cached/soft_quota/raw…）。
	 * @return self
	 */
	public function with_meta( array $meta ): Verdict {
		$clone            = clone $this;
		$clone->event_meta = $meta;
		return $clone;
	}

	/**
	 * 该判定是否参与「最严有效判定」定级（error 不参与）。
	 */
	public function is_rankable(): bool {
		return self::ERROR !== $this->verdict;
	}

	/**
	 * 取「最严有效判定」reject > review > pass；error 候选被跳过。
	 * 全部为 error（或空）时返回 null——「云端全故障收口」由编排层处理，不在值对象内决策。
	 */
	public static function most_severe( Verdict ...$candidates ): ?Verdict {
		$winner = null;
		$best   = 0;
		foreach ( $candidates as $candidate ) {
			if ( ! $candidate->is_rankable() ) {
				continue;
			}
			$severity = self::SEVERITY[ $candidate->verdict() ];
			if ( $severity > $best ) {
				$best   = $severity;
				$winner = $candidate;
			}
		}
		return $winner;
	}

	/**
	 * @param mixed $confidence
	 * @return float|null
	 */
	private static function normalize_confidence( $confidence ): ?float {
		if ( ! is_numeric( $confidence ) ) {
			return null;
		}
		$value = (float) $confidence;
		return ( $value >= 0 && $value <= 1 ) ? $value : null;
	}
}
