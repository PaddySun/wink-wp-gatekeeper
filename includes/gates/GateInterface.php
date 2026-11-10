<?php
/**
 * 闸接口（02 §1.1：GateInterface ← render_hooks() / verify_hooks() / order()）。
 *
 * 闸是编排者，驱动是执行者：闸不关心驱动内部差异，只消费统一契约；
 * 驱动不感知闸的存在，可被任意闸复用。M0 仅声明接口签名，验证编排 M1 实装。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

interface GateInterface {

	/**
	 * 渲染挂载点（WordPress filter/action 名 => 优先级）。
	 * 例：CommentGate 为 ['comment_form_submit_field' => 10]。
	 *
	 * @return array<string, int>
	 */
	public function render_hooks(): array;

	/**
	 * 验证挂载点（WordPress filter/action 名 => 优先级）。
	 * 例：CommentGate 为 ['preprocess_comment' => -PHP_INT_MAX]（拆解报告 A3 依据）。
	 *
	 * @return array<string, int>
	 */
	public function verify_hooks(): array;

	/**
	 * 闸 id（GateRegistry 注册 id；事件表 gate 字段取值）。
	 */
	public function id(): string;

	/**
	 * 该闸启用的阶段顺序（ip → captcha → review 的闸内子集）。
	 *
	 * @return string[]
	 */
	public function order(): array;
}
