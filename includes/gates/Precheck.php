<?php
/**
 * 信用免验预检端点纯逻辑（03 §2.1「信用免验预检门」/R2 §2.1 §3.2——T4）。
 *
 * 判定链（与用户反馈第 6 条一一对应；点击期零外呼——硬约束）：
 *   0 门禁三道：nonce → 存根校验（Stub::verify(wk_stub,'comment',post_id) 必须 true/null——
 *     先渲染过页面才可预检，抬脚本探测成本）→ 每 IP 限流 wk_pk_<HMAC16(ip)>（小时桶，
 *     captcha.trust.precheck_hourly_limit 默认 30）。任一失败 → captcha_needed
 *     （HTTP 200，前端降级出码——不区分报错原因，不给探测面）；
 *   ① 本地四闸（草稿值；与提交期同一 LocalRulesDriver 实例、同一 settings、同一拼装函数——
 *     两时点纯逻辑字面一致）：reject → four_gate_fail + 规则键 + 工坊文案（占位符已格式化）
 *     + 事件 stage=precheck（driver=local_rules；不留档、meta 无正文——04 §3 红线）；
 *   ② IP 信誉三态（IpReputationProbe）：null=跳过判定项（不影响免验资格——澄清 A）；
 *     pass=通过；review/reject/error=不通过（缓存未命中的「无证据从严」由 M3 服务实现承载）；
 *   ③ 历史信用（CommentTrust::history_pass——游客同昵称+邮箱/登录 user_id 已批 ≥N）；
 *   全过 → 签发 TrustStub → trust_pass + trust；任一不过（非四闸）→ captcha_needed
 *   （IP 信誉不通过但过码的也放行——反馈 6.5，出码处置在提交期闸内）。
 *
 * 安全地位：预检=UX 预演，安全地位为零（预检全挂也不拦提交——提交期另有全链兜底）。
 * 隐私：草稿正文只在本站预检内判即弃（无第三方外呼）；事件不留正文；无预取/暂存/session。
 *
 * 分层：本类为纯编排（驱动/记录器/限流/哈希全部注入，单测覆盖）；WP 边界（nonce 读取、
 * admin-ajax 注册、transient 限流桶）在 Bootstrap/handler 闭包。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

use WinkGatekeeper\Drivers\Review\LocalRulesDriver;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Intercept\Stub;
use WinkGatekeeper\Ip\IpReputationProbe;
use WinkGatekeeper\Verdict;

final class Precheck {

	/**
	 * admin-ajax action（03 §2.1 端点契约表：wp_ajax_ + wp_ajax_nopriv_ 双注册，游客可用）。
	 */
	public const ACTION = 'wk_gate_precheck';

	/**
	 * nonce 动作名（wp_create_nonce('wk-gate-precheck')，随 inline config 注入页面）。
	 */
	public const NONCE_ACTION = 'wk-gate-precheck';

	/**
	 * 预检限流 transient 前缀（04 §1/§7：wk_pk_<HMAC16(ip)> 小时桶）。
	 */
	public const RATE_PREFIX = 'wk_pk_';

	public const RESULT_TRUST     = 'trust_pass';
	public const RESULT_FOUR_GATE = 'four_gate_fail';
	public const RESULT_CAPTCHA   = 'captcha_needed';

	/**
	 * @var array<string, mixed> 完整设置树。
	 */
	private $settings;

	/**
	 * @var LocalRulesDriver 四闸驱动（与提交期同一实例——Bootstrap 注入）。
	 */
	private $rules;

	/**
	 * @var callable 送审串拼装 fn(author, email, body): string（= CommentGate::assemble_review_content，
	 *                同一函数两时点字面一致）。
	 */
	private $assemble;

	/**
	 * @var CommentTrust 历史信用判定。
	 */
	private $trust;

	/**
	 * @var IpReputationProbe|null IP 信誉三态（null=未装配=skip）。
	 */
	private $iprep;

	/**
	 * @var callable 限流判定 fn(string $client_ip): bool（true=额度内；生产=wk_pk_* 小时桶 transient）。
	 */
	private $rate_allow;

	/**
	 * @var callable 事件记录器 fn(stage, Verdict, meta): ?string（默认 recorder 形态）。
	 */
	private $recorder;

	/**
	 * @var callable 存根哈希回调（Stub 校验；生产 = wp_hash 封装）。
	 */
	private $stub_hash;

	/**
	 * @var callable 信任凭证哈希回调（TrustStub 签发；生产 = wp_hash 封装）。
	 */
	private $trust_hash;

	/**
	 * @var callable 身份/IP HMAC 回调（生产 = wp_salt('auth') 派生 SHA256[:16]）。
	 */
	private $mac;

	/**
	 * @var callable 时钟回调（生产 = time()）。
	 */
	private $now;

	/**
	 * @param array<string, mixed>   $settings   完整设置树。
	 * @param LocalRulesDriver       $rules      四闸驱动（与提交期同一实例）。
	 * @param callable               $assemble   送审串拼装函数。
	 * @param CommentTrust           $trust      历史信用判定。
	 * @param IpReputationProbe|null $iprep      IP 信誉三态（null=skip）。
	 * @param callable               $rate_allow 限流判定。
	 * @param callable               $recorder   事件记录器。
	 * @param callable               $stub_hash  存根哈希。
	 * @param callable               $trust_hash 信任凭证哈希。
	 * @param callable               $mac        身份/IP HMAC。
	 * @param callable|null          $now        时钟（null = time()）。
	 */
	public function __construct(
		array $settings,
		LocalRulesDriver $rules,
		callable $assemble,
		CommentTrust $trust,
		?IpReputationProbe $iprep,
		callable $rate_allow,
		callable $recorder,
		callable $stub_hash,
		callable $trust_hash,
		callable $mac,
		?callable $now = null
	) {
		$this->settings   = $settings;
		$this->rules      = $rules;
		$this->assemble   = $assemble;
		$this->trust      = $trust;
		$this->iprep      = $iprep;
		$this->rate_allow = $rate_allow;
		$this->recorder   = $recorder;
		$this->stub_hash  = $stub_hash;
		$this->trust_hash = $trust_hash;
		$this->mac        = $mac;
		$this->now        = $now ?? static function (): int {
			return time(); // WP 边界。
		};
	}

	/**
	 * 限流键：前缀 + HMAC16(ip)（04 §1：wk_pk_<HMAC16(ip)>）。
	 *
	 * @param string   $ip 访客 IP。
	 * @param callable $mac HMAC 回调。
	 * @return string
	 */
	public static function rate_key( string $ip, callable $mac ): string {
		return self::RATE_PREFIX . substr( (string) call_user_func( $mac, $ip ), 0, 16 );
	}

	/**
	 * 预检处理（纯逻辑：三门禁 → 四闸 → IP 三态 → 历史信用 → 签发）。
	 *
	 * @param array<string, mixed> $input    [wk_stub, post_id, author, email, content]。
	 * @param bool                 $nonce_ok nonce 校验结果（边界层 check_ajax_referer 产物）。
	 * @param string               $client_ip 访客 IP（IpResolver 解析值）。
	 * @param int                  $user_id   登录用户 ID（游客 0）。
	 * @return array{result: string, trust?: string, rule?: string, message?: string}
	 */
	public function handle( array $input, bool $nonce_ok, string $client_ip, int $user_id ): array {
		// 功能开关纵深（端点注册条件同源：gates.comment.enabled ∧ 驱动已配置由注册面收口；
		// 此处校验 captcha.trust.enabled——容器不会带 data-wk-trust，仅防直接打端点）。
		if ( empty( $this->settings['captcha']['trust']['enabled'] ) ) {
			return array( 'result' => self::RESULT_CAPTCHA );
		}

		/* 门禁 ①②③（任一失败 → captcha_needed，不区分原因——不给探测面，03 §2.1）。 */
		if ( ! $nonce_ok ) {
			return array( 'result' => self::RESULT_CAPTCHA );
		}

		$stub_raw = isset( $input['wk_stub'] ) && is_scalar( $input['wk_stub'] ) ? (string) $input['wk_stub'] : '';
		$post_id  = isset( $input['post_id'] ) && is_scalar( $input['post_id'] ) ? (string) $input['post_id'] : '';
		$state    = Stub::verify( $stub_raw, 'comment', $post_id, (int) call_user_func( $this->now ), $this->stub_hash );
		if ( false === $state ) {
			return array( 'result' => self::RESULT_CAPTCHA );
		}

		if ( ! call_user_func( $this->rate_allow, $client_ip ) ) {
			return array( 'result' => self::RESULT_CAPTCHA );
		}

		$author  = isset( $input['author'] ) && is_scalar( $input['author'] ) ? (string) $input['author'] : '';
		$email   = isset( $input['email'] ) && is_scalar( $input['email'] ) ? strtolower( trim( (string) $input['email'] ) ) : '';
		$content = isset( $input['content'] ) && is_scalar( $input['content'] ) ? (string) $input['content'] : '';

		/* ① 本地四闸（草稿值；与提交期同一实例同一拼装函数——判定结果两时点必然一致）。 */
		$review_content = (string) call_user_func( $this->assemble, $author, $email, $content );
		$verdict        = $this->rules->check( $review_content, array(
			'content' => $content, // 字数/中文闸仍只算正文（02 §2.2 例外条款）。
			'author'  => $author,
			'email'   => $email,
		) );

		if ( Verdict::REJECT === $verdict->verdict() ) {
			// 事件 stage=precheck（04 §3 矩阵：不留档、meta 无正文；门禁失败不落库在上方已收口）。
			$meta = array(
				'post_id'   => '' !== $post_id ? (int) $post_id : 0,
				'author'    => $author,
				'email'     => $email,
				'client_ip' => $client_ip,
				'driver'    => '' !== $verdict->driver_id() ? $verdict->driver_id() : 'local_rules',
			);
			if ( method_exists( $this->rules, 'notes' ) && array() !== $this->rules->notes() ) {
				$meta['notes'] = $this->rules->notes();
			}
			call_user_func( $this->recorder, 'precheck', $verdict, $meta );

			return array(
				'result'  => self::RESULT_FOUR_GATE,
				'rule'    => $verdict->reason(),
				'message' => $this->formatted_message( $verdict->reason() ),
			);
		}

		/* ② IP 信誉三态（02 §2.3b：null=skip 不影响免验资格；fail=出码——过码放行在提交期闸内）。 */
		if ( null !== $this->iprep ) {
			$ip_verdict = $this->iprep->probe( $client_ip );
			if ( null !== $ip_verdict && Verdict::PASS !== $ip_verdict->verdict() ) {
				return array( 'result' => self::RESULT_CAPTCHA );
			}
		}

		/* ③ 历史信用（游客=昵称+邮箱；登录=user_id 信用键）。 */
		$identity = ( $user_id > 0 )
			? array( 'user_id' => $user_id, 'email' => $email )
			: array( 'email' => $email, 'author' => $author );
		if ( ! $this->trust->history_pass( $identity ) ) {
			return array( 'result' => self::RESULT_CAPTCHA );
		}

		/* 【v1.5·C1】test_mode 旁路期不签发 TrustStub（02 §2.8 安全门 #5-②——机制性保证
		 * 关测后无 10min 免验残留窗；降级 captcha_needed 契约形态不变，旁路期提交期
		 * 本就直通、前端照常渲染，预检结果仅少一个免验位）。 */
		if ( TestMode::active( $this->settings, (int) call_user_func( $this->now ) ) ) {
			return array( 'result' => self::RESULT_CAPTCHA );
		}

		/* 全过 → 签发 TrustStub（IP+表单+身份三重绑定，10min——02 §3.3a；id/ip 哈希
		 * 规范收口 HMAC-SHA16=前 16 位——与 CommentGate 提交期校验同形态）。 */
		$mac      = $this->mac;
		$id_input = ( $user_id > 0 )
			? TrustStub::user_identity_input( $user_id )
			: TrustStub::guest_identity_input( $email, $author );
		$trust    = TrustStub::issue(
			$post_id,
			substr( (string) $mac( $id_input ), 0, 16 ),
			substr( (string) $mac( $client_ip ), 0, 16 ),
			(int) call_user_func( $this->now ),
			$this->trust_hash
		);

		return array(
			'result' => self::RESULT_TRUST,
			'trust'  => $trust,
		);
	}

	/**
	 * 四闸文案（工坊文案 + 占位符格式化——服务端已格式化，前端内联展示即用）。
	 *
	 * @param string $rule 规则键（too_short/too_long/no_chinese/banned_word）。
	 * @return string
	 */
	private function formatted_message( string $rule ): string {
		$messages = isset( $this->settings['copy']['messages'] ) && is_array( $this->settings['copy']['messages'] )
			? array_merge( Copywork::defaults()['messages'], array_filter( $this->settings['copy']['messages'], 'is_string' ) )
			: Copywork::defaults()['messages'];

		$template = isset( $messages[ $rule ] ) && is_string( $messages[ $rule ] ) ? $messages[ $rule ] : $rule;
		$local    = isset( $this->settings['review']['local'] ) && is_array( $this->settings['review']['local'] )
			? $this->settings['review']['local']
			: array();

		return Copywork::format( $template, array(
			'min_length' => (int) ( $local['min_length'] ?? 0 ),
			'max_length' => (int) ( $local['max_length'] ?? 0 ),
		) );
	}
}
