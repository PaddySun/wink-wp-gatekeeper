<?php
/**
 * 内容审核驱动接口（02 §2.2：说的是不是人话）。
 *
 * 「单输入三态判定」契约：pass | reject | review（review → pre_comment_approved=0，D8）。
 * 串联语义（v1.2）：reject 终止后续驱动；review/pass 继续；error 不终止（chain 即 failover 链），
 * 最终取「最严有效判定」——error 不参与定级。
 *
 * $meta 键集开放（v1.2）：当前 author/email/url/ip_hash/post_id/user_agent，驱动按需读取，
 * 新增键不算接口变更。
 *
 * M1 实现：LocalRulesDriver（内部类形态挂接本接口——02 §1.1 将其列为 M3 并入接口，
 * 实现期提前挂上以统一闸内调用面，链路重构 M3 进行）。
 *
 * @package WinkGatekeeper\Drivers
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Drivers;

use WinkGatekeeper\Verdict;

interface ReviewDriverInterface {

	/**
	 * 审核评论正文。
	 *
	 * @param string                $content 评论正文（作者名等 meta 拼装由驱动自决）。
	 * @param array<string, mixed>  $meta    {author, email, url, ip_hash, post_id, user_agent}（开放键集）。
	 * @return Verdict pass | reject | review（恒二值驱动如 LocalRules 无 review 态）。
	 */
	public function check( string $content, array $meta ): Verdict;
}
