<?php
/**
 * 评论闸（02 §3.1 编排精确落地；F05，M1 实装）。
 *
 * 渲染：comment_form_submit_field filter @ 10 注入容器（03 §2.1 结构：容器 + 签名存根 +
 * 驱动组件占位，按 frontend 元数据）；未配置驱动不渲染组件（02 §2.1）。
 *
 * 验证编排（preprocess_comment @ -PHP_INT_MAX，比一切评论插件早——A3 依据；【v1.4·R2】次序修订）：
 *   0. 管理员/后台（DOING_AUTOSAVE、WP_IMPORTING、is_admin）直接放行（上游 is_admin 语义）；
 *   1. 验签名存根（stage=stub）：false → 拦 bad-signature；true → 免验证放行 captcha；
 *      null（展示过）→ 必须 verify；
 *   2. Stage:ip（【v1.4·R2】IpReputationProbe 消费：null=skip；pass=继续；review/reject/error=
 *      强制验证码标志+事件——「出码过码放行」非拦截，02 §2.3 修订表/§2.3b）；
 *   2.5 Stage:review·本地段（【v1.4·R2】LocalRules 四闸前移到 captcha 之前——reject 零验票
 *      调用、事件口径 stage=review/driver=local_rules 不变）；
 *   3. Stage:captcha（【v1.4·R2】三来源按序短路：① TrustStub（02 §3.3a，六项校验，命中记
 *      trust_pass/pass_source='trust'，强制标志下撤销）→ ② VerifyCache（现行，pass_source=
 *      'verify_cache'）→ ③ 真验票）：未配置整体跳过（02 §2.1）；缺票 → captcha_missing；
 *      有票核验失败 → 驱动文案拦截。token 字段核验后 unset（A6 单次消费，边界钩子执行）；
 *      强制验证码标志优先于存根 true（v1.2 安全审核 #2——访客手里没有 ticket，放行等于
 *      给干净存根持有者开跳过通道）。
 *   4. Stage:review·云端段（C4：reject 终止省调用 / review 不短路 / error 不终止，最严有效
 *      判定收口 + 云端全故障收口忽略 LocalRules pass 按 fail_mode 最严者降级，02 §2.2）；
 *      停转消费：halt_check 命中零调用、semantic 终态 halt_mark 当日停转（02 §5）。
 *   5. 全过 → 放行（pass 默认不落库；trust_pass/verify_cache_hit 例外留审计足迹——04 §3）。
 *
 * REST：rest_preprocess_comment 同编排（临时隔离 $_POST 防外层 token 泄漏，手法沿
 * 拆解报告 CommentBase.php:68-113）；REST 无存根必拦（stub 校验 false）→ WP_Error JSON
 * + X-Wink-Gatekeeper-* 头（02 §3.5 ②）。
 *
 * 失败响应落地（02 §3.5 v1.3 判定与落地分离）：preprocess_comment 判定期只留档+落事件；
 * 拦截响应按请求形态三分支——原生（SCRIPT_FILENAME realpath）品牌页 / AJAX（DOING_AJAX）
 * 头协议四头+简短 JSON（admin-ajax 主题评论，豁免修正后匿名 AJAX 提交照常走闸）/ 其余品牌页。
 *
 * 分层：orchestrate() 为纯编排（驱动/记录器/解析器/哈希/时钟/IP 信誉探测全部注入，单测覆盖）；
 * hook_* 方法为 WP 边界（$_POST 读取、wp_die、wp_unslash、$_POST unset）。
 *
 * @package WinkGatekeeper\Gates
 */

declare( strict_types=1 );

namespace WinkGatekeeper\Gates;

use WinkGatekeeper\Drivers\CaptchaDriverInterface;
use WinkGatekeeper\Drivers\Review\LocalRulesDriver;
use WinkGatekeeper\Drivers\ReviewDriverInterface;
use WinkGatekeeper\Http\RemoteClient;
use WinkGatekeeper\Http\RemoteResult;
use WinkGatekeeper\Intercept\Copywork;
use WinkGatekeeper\Intercept\DiePage;
use WinkGatekeeper\Intercept\Stub;
use WinkGatekeeper\Ip\IpReputationProbe;
use WinkGatekeeper\Ip\IpResolver;
use WinkGatekeeper\Gates\ResponseDispatcher;
use WinkGatekeeper\Gates\TrustStub;
use WinkGatekeeper\Stats\EventsTable;
use WinkGatekeeper\Stats\StatsCache;
use WinkGatekeeper\Verdict;

final class CommentGate extends AbstractGate {

	protected $id = 'comment';

	public const CONTEXT_POST_FIELD = 'comment_post_ID';

	/**
	 * @var array<string, mixed> 设置树。
	 */
	private $settings;

	/**
	 * @var CaptchaDriverInterface|null 验证码驱动（null/未配置 = captcha 阶段整体跳过）。
	 */
	private $captcha;

	/**
	 * @var ReviewDriverInterface 内容审核驱动（M1 单驱动 LocalRules）。
	 */
	private $review;

	/**
	 * @var callable|null 事件记录器 fn(stage, Verdict, meta): ?string(event_no)。
	 *                    缺省 = EventsTable::insert + StatsCache::record（WP 边界闭包）。
	 */
	private $recorder;

	/**
	 * @var IpResolver 真实 IP 解析（全站唯一出口）。
	 */
	private $resolver;

	/**
	 * @var callable 存根哈希回调（生产 = wp_hash 封装）。
	 */
	private $stub_hash;

	/**
	 * @var callable 时钟回调（生产 = time()）。
	 */
	private $now;

	/**
	 * @var callable 渲染期 context_id 提供器（生产 = get_the_ID()，WP 边界）。
	 */
	private $context_provider;

	/**
	 * @var callable|null 拦截留档器 fn(commentdata 归一化载荷): ?int(comment_id)。
	 *                    null = 未装配（单测默认，跳过留档）；生产 = Bootstrap 注入
	 *                    wp_insert_comment 直插闭包（02 §3.1 v1.3「拦截留档」，F37/D15）。
	 */
	private $archiver;

	/**
	 * @var array<string, ReviewDriverInterface> 云端审核链（driver_id => 已配置驱动实例；
	 *                C4 实装——LocalRules 恒首不入此表，顺序由 review.cloud.chain 决定；
	 *                Bootstrap 构造期只装配「已配置」的云端驱动）。
	 */
	private $cloud_chain = array();

	/**
	 * @var callable|null 停转检查 fn(string $driver_id): ?string(停转状态)——命中则跳过
	 *                    该驱动调用（02 §5 快速失败：semantic 停转/配额尽防空转）。
	 *                    null = 未装配（单测默认，无停转面）。
	 */
	private $halt_check;

	/**
	 * @var callable|null 停转标记 fn(string $driver_id): void——云端驱动 semantic 终态时
	 *                   由编排回调（生产 = DriverHealth::mark_option('config_error')）。
	 */
	private $halt_mark;

	/**
	 * @var VerifyCache|null 验证短缓存（M2 反馈2 T3：核验成功后窗口内免重复验票；
	 *                       null = 未装配，行为与 v1.4 一致——恒走驱动验票）。
	 */
	private $verify_cache;

	/**
	 * @var IpReputationProbe|null IP 信誉三态（02 §3.1 步骤 2/§2.3b——【v1.4·R2】M2.5 装配
	 *                              Null 服务=恒 skip=现行行为；null 属性=未装配同 skip）。
	 */
	private $iprep;

	/**
	 * @var callable|null 信任凭证身份/IP HMAC fn(string $data): string（生产 = wp_salt('auth')
	 *                    派生 SHA256[:16]——与事件 ip_hash 同盐同源；null = WP 边界默认）。
	 */
	private $trust_mac;

	/**
	 * @var bool 本次请求编排产出 review 判定（评论应入待审）——pre_comment_approved
	 *           filter 消费（C4 真机发现：WP 7.x wp_new_comment 在 preprocess_comment 之后
	 *           经 wp_allow_comment 重算 comment_approved，直接改 commentdata 会被覆盖；
	 *           pre_comment_approved 是 Akismet 同款 canonical 通道）。
	 */
	private $hold_moderation = false;

	/**
	 * @var string|null 待审来源（02 §3.1 v1.4·R1 G1）：'cloud'（云端 review 判定收口）|
	 *                  'cloud_all_failed'（云端全故障 fail-review 收口）；hold_moderation
	 *                  置位时随 hold_meta 一并记录，filter_pre_comment_approved 消费时
	 *                  落收口事件（04 §3 写入矩阵 review 行 meta.source——三种待审可区分：
	 *                  核心首评待审不产生插件事件）。
	 */
	private $hold_source = null;

	/**
	 * @var array<string, mixed> 收口事件 meta 素材（post_id/author/email/ip_src/client_ip——
	 *                           编排期解析值暂存；comment_id 事后对账，v1.2 口径沿用）。
	 */
	private $hold_meta = array();

	/**
	 * @var string|null 收口事件归因驱动 id（产出 review 判定者；全故障收口='cloud'）。
	 */
	private $hold_driver = null;

	/**
	 * @var bool 收口事件已留痕闩锁（WP 7.x 对 pre_comment_approved 双回调——只落一行；
	 *           orchestrate 开头重置）。
	 */
	private $hold_event_recorded = false;

	/**
	 * @param array<string, mixed>        $settings 设置树（SettingsSchema::defaults() 形态）。
	 * @param CaptchaDriverInterface|null $captcha  验证码驱动。
	 * @param ReviewDriverInterface|null  $review   审核驱动（null = 自建 LocalRules，无 WP 关键词联动回调）。
	 * @param callable|null               $recorder 事件记录器（null = WP 边界默认）。
	 * @param IpResolver|null             $resolver IP 解析器（null = direct 模式）。
	 * @param callable|null               $stub_hash 存根哈希（null = wp_hash 封装，WP 边界）。
	 * @param callable|null               $now      时钟（null = time()）。
	 * @param callable|null               $context_provider 渲染期 context_id（null = get_the_ID()）。
	 * @param callable|null               $archiver 拦截留档器（null = 不留档；Bootstrap 注入
	 *                                             wp_insert_comment 直插闭包——WP 边界）。
	 * @param array<string, ReviewDriverInterface> $cloud_chain 云端审核链（id => 已配置驱动；C4）。
	 * @param callable|null               $halt_check 停转检查（null = 无停转面；Bootstrap 注入）。
	 * @param callable|null               $halt_mark 停转标记（null = 不标记；Bootstrap 注入）。
	 * @param VerifyCache|null            $verify_cache 验证短缓存（null = 未装配；T3）。
	 * @param IpReputationProbe|null      $iprep IP 信誉三态（null = 未装配=skip；【v1.4·R2】）。
	 * @param callable|null               $trust_mac 信任凭证 HMAC（null = wp_salt('auth') 默认）。
	 */
	public function __construct(
		array $settings,
		?CaptchaDriverInterface $captcha = null,
		?ReviewDriverInterface $review = null,
		?callable $recorder = null,
		?IpResolver $resolver = null,
		?callable $stub_hash = null,
		?callable $now = null,
		?callable $context_provider = null,
		?callable $archiver = null,
		array $cloud_chain = array(),
		?callable $halt_check = null,
		?callable $halt_mark = null,
		?VerifyCache $verify_cache = null,
		?IpReputationProbe $iprep = null,
		?callable $trust_mac = null
	) {
		$this->settings = $settings;
		$this->captcha  = $captcha;
		$this->review   = $review
			?? new LocalRulesDriver( isset( $settings['review']['local'] ) && is_array( $settings['review']['local'] ) ? $settings['review']['local'] : array() );
		$this->recorder = $recorder
			?? function ( string $stage, Verdict $verdict, array $meta ): ?string {
				// WP 边界默认：事件表写入矩阵（04 §3——R1 G6 回归生产路径：pass 默认不落库、
				// 免验票审计特例与采样开关在 should_record 内收口）+ 统计计数（04 §4）。
				$driver_id = ( '' !== $verdict->driver_id() ) ? $verdict->driver_id() : 'gate';
				$event_no  = null;
				if ( EventsTable::should_record( $stage, $verdict, $this->settings ) ) {
					$event_no = EventsTable::insert( 'comment', $stage, $driver_id, $verdict, $meta, self::ip_hash( $meta['client_ip'] ?? '' ) );
				}
				// 待审收口留痕行（hold_moderation / cloud_all_failed——v1.4·R1 G1）只入事件表：
				// 驱动判定事件已逐条计数，收口行再计会使 by_gate/by_driver 双计同一评论。
				if ( ! in_array( $verdict->reason(), array( 'hold_moderation', 'cloud_all_failed' ), true ) ) {
					StatsCache::record( 'comment', $driver_id, $verdict->verdict() );
				}
				return $event_no;
			};
		$this->resolver  = $resolver ?? new IpResolver( is_array( $settings['network'] ?? null ) ? $settings['network'] : array() );
		$this->stub_hash = $stub_hash ?? static function ( string $data ): string {
			return wp_hash( $data, 'nonce' ); // WP 边界。
		};
		$this->now       = $now ?? static function (): int {
			return time();
		};
		$this->context_provider = $context_provider ?? static function (): string {
			return (string) get_the_ID(); // WP 边界：评论表单渲染期所在文章 ID。
		};
		$this->archiver = $archiver; // 显式注入才留档（单测默认跳过；Bootstrap 装 WP 边界闭包）。
		$this->cloud_chain = $cloud_chain; // C4：云端 failover 链（Bootstrap 构造期过滤已配置驱动）。
		$this->halt_check  = $halt_check;  // C4：停转检查（DriverHealth + 配额）。
		$this->halt_mark   = $halt_mark;   // C4：semantic 终态 → 当日停转标记。
		$this->verify_cache = $verify_cache; // T3：验证短缓存（null = 未装配恒验票）。
		$this->iprep      = $iprep;      // 【v1.4·R2】IP 信誉三态（null = 未装配=skip）。
		$this->trust_mac  = $trust_mac ?? static function ( string $data ): string {
			return hash_hmac( 'sha256', $data, wp_salt( 'auth' ) ); // WP 边界（与 VerifyCache 同源）。
		};
	}

	public function render_hooks(): array {
		return array( 'comment_form_submit_field' => 10 );
	}

	public function verify_hooks(): array {
		return array( 'preprocess_comment' => -PHP_INT_MAX ); // 比一切评论插件早（A3 依据）。
	}

	/**
	 * 闸内启用阶段顺序（M1：captcha → review；ip 阶段 M3 接入 IPDriver 后置首）。
	 *
	 * @return string[]
	 */
	public function order(): array {
		return array( 'captcha', 'review' ); // M3: array( 'ip', 'captcha', 'review' ) + 云端链。
	}

	/* -------------------------------------------------------------------
	 * 纯编排（单测覆盖；02 §3.1 步骤 0-5）
	 * ------------------------------------------------------------------ */

	/**
	 * 编排入口。
	 *
	 * @param array<string, mixed> $commentdata   评论数据（preprocess_comment 形态）。
	 * @param array<string, mixed> $request       提交请求面（$_POST / REST body 注入）。
	 * @param array<string, mixed> $server        $_SERVER（IP 解析注入）。
	 * @param bool                 $exempt        步骤 0 豁免标志（管理员/后台/导入，边界判定）。
	 * @param bool                 $force_captcha 强制验证码标志（M1 恒 false；M3 IPDriver review 判定置位，
	 *                                            优先于存根 true——02 §3.1 v1.2 条款）。
	 * @return array{decision: string, commentdata: array<string, mixed>, message?: string, evidence?: array<string, string>}
	 */
	public function orchestrate( array $commentdata, array $request, array $server, bool $exempt = false, bool $force_captcha = false ): array {
		$this->hold_moderation = false; // 每次编排重置（pre_comment_approved 只消费本次判定）。
		$this->hold_source     = null;  // v1.4·R1 G1：收口事件素材同步重置。
		$this->hold_meta       = array();
		$this->hold_driver     = null;
		$this->hold_event_recorded = false; // 收口事件闩锁重置（单请求单评论一行语义）。

		if ( $exempt ) {
			// 步骤 0：管理员/后台豁免（DOING_AUTOSAVE/WP_IMPORTING/is_admin 由边界钩子判定）。
			return array( 'decision' => 'allow', 'commentdata' => $commentdata );
		}

		// 全闸复用同一次 IP 解析（02 §6：一次解析，全闸复用）。
		$resolved = $this->resolver->resolve( $server );

		/* 拦截留档环境（02 §3.1 v1.3「拦截留档」，F37/D15）：判定期 commentdata + 解析 IP + UA。
		 * 留档器归一化直插 wp_insert_comment（comment_approved='spam'）；stub 阶段不消费。 */
		$reject_env = array(
			'commentdata' => $commentdata,
			'ip'          => isset( $resolved['ip'] ) && is_string( $resolved['ip'] ) ? $resolved['ip'] : '',
			'ua'          => isset( $server['HTTP_USER_AGENT'] ) && is_scalar( $server['HTTP_USER_AGENT'] ) ? (string) $server['HTTP_USER_AGENT'] : '',
		);

		$captcha_active = null !== $this->captcha && $this->captcha->is_configured();

		/* 步骤 1：签名存根三态（stage=stub）。
		 * 未配置驱动 = captcha 阶段整体跳过（02 §2.1：等同存根 true 语义，不渲染组件
		 * 不验证——渲染期未签发存根，提交期不得反向要求）。 */
		if ( $captcha_active ) {
			$context_id = $this->context_id( $commentdata, $request );
			$stub_raw   = isset( $request['wk_stub'] ) && is_scalar( $request['wk_stub'] ) ? (string) $request['wk_stub'] : '';
			$state      = Stub::verify( $stub_raw, $this->id, $context_id, (int) call_user_func( $this->now ), $this->stub_hash );

			if ( false === $state ) {
				// 拦 bad-signature（防跨表单 token 混用）；文案给「刷新页面」台阶（存根 TTL×缓存误拦同解）。
				// stage=stub 不留档（02 §3.1 ②：脚本探测流无归档价值，防扫描器洪水撑爆垃圾库）。
				return $this->reject(
					'stub',
					Verdict::reject( '', 'bad-signature', array( 'bad-signature' ) ),
					'captcha_missing',
					array(),
					array( 'ip_src' => $resolved['ip_src'], 'client_ip' => $resolved['ip'], 'post_id' => $this->post_id( $commentdata, $request ) )
				);
			}

			/* 【v1.4 起本检点移至步骤 3 开头（四闸之后）——强制标志可由步骤 2 ip 阶段置位，
			 * 且 too_short 等内容判定先出（R2 §2.7 修复性次序）；条款本体见步骤 3 注释。】 */
		}

		/* 步骤 2：Stage:ip（02 §3.1 步骤 2 + §2.3b 三态语义——【v1.4·R2】IpReputationProbe 消费）。
		 * null（未启用/未装配，M2.5 Null 服务恒 null）→ 无动作无事件（skip=现行行为）；
		 * pass → 继续（不落库，04 §3）；review/reject/error → 强制验证码标志置位 + 事件落库
		 * （阈值证据保留，meta.ip_force_captcha）。
		 * 【v1.4·反馈6.5/02 §2.3 修订表】reject 档不再 wp_die/不再当步拦截——「强制验证码、
		 * 过码即放行进内容闸」（脏 VPN IP 不误伤；防线=该 IP 上脚本必须真过码）；终拦若发生
		 * 移至 captcha 阶段按该阶段留档。上报「有票才报」触发点（02 §2.3a）M3 实装。 */
		$ip_force_captcha = false;
		if ( null !== $this->iprep ) {
			$ip_verdict = $this->iprep->probe( is_string( $resolved['ip'] ) ? $resolved['ip'] : '' );
			if ( null !== $ip_verdict && Verdict::PASS !== $ip_verdict->verdict() ) {
				$ip_force_captcha = true;
				call_user_func( $this->recorder, 'ip', $ip_verdict, array(
					'ip_src'           => $resolved['ip_src'],
					'client_ip'        => $resolved['ip'],
					'ip_force_captcha' => true,
				) );
			}
		}
		$force = $force_captcha || $ip_force_captcha;

		/* 步骤 2.5：Stage:review·本地段（【v1.4·反馈6.1/R2 §2.7】LocalRules 四闸前移到 captcha
		 * 之前——「四闸不通过=与验证码无关直接拦」在提交期字面成立；reject 零验票调用（票据
		 * 未被消费，真人改完内容 5min 票据窗内可直接重提）；事件口径不变（stage=review、
		 * driver=local_rules——只动次序不动语义）；纯本地 1-2ms 前置零成本；stats 口径不变。
		 * 送审拼接（02 §2.2 v1.3，F36）：闸统一拼装送审串 =「昵称 \n 邮箱 \n 正文」三行
		 * （review.include_author_fields 默认开；关 = 仅正文）——与预检端点共用同一实例、同一
		 * settings、同一拼装函数（两时点纯逻辑字面一致）；字数/中文闸仍只算正文。 */
		$content = isset( $commentdata['comment_content'] ) && is_scalar( $commentdata['comment_content'] ) ? (string) $commentdata['comment_content'] : '';
		$author  = isset( $commentdata['comment_author'] ) && is_scalar( $commentdata['comment_author'] ) ? (string) $commentdata['comment_author'] : '';
		$email   = isset( $commentdata['comment_author_email'] ) && is_scalar( $commentdata['comment_author_email'] ) ? (string) $commentdata['comment_author_email'] : '';

		$review_content = $this->assemble_review_content( $author, $email, $content );

		$review_meta = array(
			'post_id' => $this->post_id( $commentdata, $request ),
			'ip_hash' => '',
			'author'  => $author,
			'email'   => $email,
			'content' => $content, // 正文原值：LocalRules ①②③（字数/中文）只算正文（02 §2.2 例外条款）。
		);

		$review_verdict = $this->review->check( $review_content, $review_meta );

		if ( Verdict::REJECT === $review_verdict->verdict() ) {
			$meta = array(
				'ip_src' => $resolved['ip_src'],
				'client_ip' => $resolved['ip'],
				'driver' => $review_verdict->driver_id(),
			);
			if ( method_exists( $this->review, 'notes' ) && array() !== $this->review->notes() ) {
				$meta['notes'] = $this->review->notes();
			}
			return $this->reject(
				'review',
				$review_verdict,
				$review_verdict->reason(), // 文案键（too_short/no_chinese/banned_word…）。
				array(
					// {rule} 前台只替换规则键名（防探测 oracle，安全审核 #11）。
					'rule'       => $review_verdict->reason(),
					'min_length' => $this->local_setting( 'min_length' ),
					'max_length' => $this->local_setting( 'max_length' ),
				),
				$meta,
				$reject_env
			);
		}

		/* 步骤 3：Stage:captcha（【v1.4·R2】免验票三来源按序短路：① TrustStub（零 I/O 纯哈希）
		 * → ② VerifyCache（现行）→ ③ 真验票——02 §2.1/§3.3a；pass 事件 meta.pass_source
		 * 统一三来源口径；强制标志下不存在免验通道（新鲜判定压过凭证——§3.3a 校验表末行）。 */
		if ( $captcha_active ) {
			/* 强制验证码标志优先于存根 true（02 §3.1 v1.2 安全审核 #2 条款；v1.4 起标志含
			 * 步骤 2 ip 阶段 fail 档置位，检点移至四闸之后）：存根 true（免验证通道）且强制
			 * 标志置位 → 按 captcha_missing 拦截——访客手里没有 ticket，放行等于给干净存根
			 * 持有者开跳过通道。 */
			if ( true === $state && $force ) {
				return $this->reject(
					'captcha',
					Verdict::reject( '', 'captcha_missing', array( 'captcha_missing' ) ),
					'captcha_missing',
					array(),
					array( 'ip_src' => $resolved['ip_src'], 'client_ip' => $resolved['ip'], 'ip_force_captcha' => true ),
					$reject_env
				);
			}

			// 存根 null（展示过）才必须验；true（未展示/未配置）免验证放行 captcha。
			if ( null === $state ) {
				$exempt = false; // 三来源前两源命中标志（命中即免真验票）。

				/* 3a TrustStub 校验（§3.3a 六项校验表——签名/ctx/TTL/ip/身份五项内化于
				 * TrustStub::verify；⑥「新鲜 ip 判定压过凭证」=$force 置位即撤销，先于此
				 * 判断短路）。命中 → pass（rule_hit='trust_pass'、driver=gate——信用免验
				 * 不是驱动产物；meta.trust_pass + pass_source='trust'）。 */
				if ( ! $force && $this->trust_enabled() ) {
					$trust_raw = isset( $request['wk_trust'] ) && is_scalar( $request['wk_trust'] ) ? trim( (string) $request['wk_trust'] ) : '';
					if ( '' !== $trust_raw
						&& TrustStub::verify(
							$trust_raw,
							$context_id,
							$this->trust_identity_hash( $commentdata, $author, $email ),
							$this->trust_ip_hash( is_string( $resolved['ip'] ) ? $resolved['ip'] : '' ),
							$this->trust_ttl_minutes(),
							(int) call_user_func( $this->now ),
							$this->stub_hash // 复用 wp_hash('nonce') 摘要通道（02 §3.3a 格式注）。
						) ) {
						call_user_func( $this->recorder, 'captcha', Verdict::pass( 'gate', 'trust_pass', array( 'trust_pass' ) ), array(
							'ip_src'      => $resolved['ip_src'],
							'client_ip'   => $resolved['ip'],
							'trust_pass'  => true,
							'pass_source' => 'trust',
						) );
						$exempt = true;
					}
				}

				/* 3b T3 验证短缓存（M2 反馈2，D+A-lite）：同存根+同 IP 在窗口内已核验成功过 →
				 * 跳过验票直接进内容闸（真票单次消费语义的窗口化补充）。零驱动调用 = usage
				 * 不计数；事件 verdict=pass 留审计足迹（pass_source='verify_cache'）。 */
				if ( ! $exempt && ! $force
					&& null !== $this->verify_cache
					&& $this->verify_cache->hit( $stub_raw, is_string( $resolved['ip'] ) ? $resolved['ip'] : '' ) ) {
					call_user_func( $this->recorder, 'captcha', Verdict::pass( $this->captcha_driver_id(), 'verify_cache_hit', array( 'verify_cache_hit' ) ), array(
						'ip_src'           => $resolved['ip_src'],
						'client_ip'        => $resolved['ip'],
						'verify_cache_hit' => true,
						'pass_source'      => 'verify_cache',
					) );
					$exempt = true;
				}

				/* 3c 真验票（缺票 → captcha_missing；有票 → CaptchaDriver.verify）。 */
				if ( ! $exempt ) {
					$ticket  = isset( $request['wk_captcha_ticket'] ) && is_scalar( $request['wk_captcha_ticket'] ) ? trim( (string) $request['wk_captcha_ticket'] ) : '';
					$randstr = isset( $request['wk_captcha_randstr'] ) && is_scalar( $request['wk_captcha_randstr'] ) ? trim( (string) $request['wk_captcha_randstr'] ) : '';

					if ( '' === $ticket || '' === $randstr ) {
						// 无票 = captcha_missing（真人漏点按钮/JS 未加载——不上报，M3「有票才报」口径；
						// ip fail 档强制标志下无票同此拦——「过码才放行」的拦点）。
						return $this->reject(
							'captcha',
							Verdict::reject( $this->captcha_driver_id(), 'captcha_missing', array( 'captcha_missing' ) ),
							'captcha_missing',
							array(),
							array( 'ip_src' => $resolved['ip_src'], 'client_ip' => $resolved['ip'] ),
							$reject_env
						);
					}

					$error = $this->captcha->verify( array(
						'ticket'  => $ticket,
						'randstr' => $randstr,
						'user_ip' => $resolved['ip'], // 天御 UserIp = 真实 IP（非 CDN 节点，F29 验收）。
					) );

					if ( null !== $error ) {
						$meta = array( 'ip_src' => $resolved['ip_src'], 'client_ip' => $resolved['ip'] );
						if ( method_exists( $this->captcha, 'last_verify_meta' ) ) {
							$driver_meta = $this->captcha->last_verify_meta();
							$meta        = array_merge( $driver_meta, $meta ); // Score/GetCaptchaTime/CaptchaCode 入 meta。
						}
						$rule = isset( $meta['rule'] ) && is_string( $meta['rule'] ) ? $meta['rule'] : 'captcha_failed';
						return $this->reject_with_message(
							'captcha',
							Verdict::reject( $this->captcha_driver_id(), $rule, array( $rule ) ),
							$error, // 驱动错误文案已终态（含台阶与指引），直接展示（输出层转义）。
							$meta,
							array(),
							$reject_env
						);
					}

					/* T3：核验成功（verify 返回 null）→ 写验证短缓存（仅成功写；键=存根原文|IP 双绑定）。 */
					if ( null !== $this->verify_cache ) {
						$this->verify_cache->remember( $stub_raw, is_string( $resolved['ip'] ) ? $resolved['ip'] : '' );
					}

					/* 容灾放行事件（02 §2.1 disasterPolicy allow 档 + 05 §1.1「统计可查容灾放行量」）：
					 * 驱动 meta 标注 disaster_pass（CaptchaCode=21 + trerror 票据、限速内放行）→
					 * 落一条 pass 事件（rule_hit=trerror）；不拦截，编排继续。 */
					if ( method_exists( $this->captcha, 'last_verify_meta' ) ) {
						$after_meta = $this->captcha->last_verify_meta();
						if ( ! empty( $after_meta['disaster_pass'] ) ) {
							$rule = isset( $after_meta['rule'] ) && is_string( $after_meta['rule'] ) ? $after_meta['rule'] : 'CaptchaCode=21/trerror';
							call_user_func( $this->recorder, 'captcha', Verdict::pass( $this->captcha_driver_id(), $rule, array( $rule ) ), array(
								'ip_src'        => $resolved['ip_src'],
								'client_ip'     => $resolved['ip'],
								'disaster_pass' => true,
							) );
						}
					}
				}

				/* 有票核验失败（captcha_failed）→ 上报该 IP（fire-and-forget）：M1 留桩不实现
				 * （无 IPDriver/AbuseIPDB，02 §3.1 上报触发点；M3 接 SupportsReportingInterface：
				 * 冷却 60min、全局 60/h、与 check 合并配额——04 §6；【v1.4】四闸前移后「四闸
				 * reject 先烧一次验票」的顺序缺陷已消除——R2 §2.7）。 */
			}
		}

		/* 步骤 4：Stage:review·云端段（C4 实装，02 §2.2 v1.2 串联语义；【v1.4·R2】LocalRules
		 * 已前移至步骤 2.5，此处仅云端 failover 链——外呼省调用原则不变：验票失败的请求
		 * 不进云审）：
		 *   - 云端链按 review.cloud.chain 顺序逐驱动 check：reject 终止（省调用）；review 不短路；
		 *     pass 继续；error 不终止（链即 failover 链）——最终取最严有效判定 reject > review > pass；
		 *   - 云端全故障收口：启用中的云端驱动全部 error/停转（云端无有效判定）→ 忽略 LocalRules
		 *     的 pass、按云端降级档处理（fail_mode 最严者：fail-review 优先），事件已记 verdict=error；
		 *   - 停转消费（02 §5）：halt_check 命中（semantic 停转/配额尽）→ 跳过调用计为 error；
		 *     semantic 终态 → halt_mark 当日停转（防每次提交空转注定失败的调用）。
		 * 送审串/$review_meta 由步骤 2.5 统一拼装（同一拼装函数——本地与云端同口径）。 */
		$cloud_ids     = $this->active_cloud_chain_ids();
		$effective     = array(); // driver_id => Verdict（非 error 的有效判定）。
		foreach ( $cloud_ids as $cloud_id ) {
			$cloud_driver = $this->cloud_chain[ $cloud_id ];

			// 停转检查（02 §5 快速失败）：semantic 停转/配额尽 → 零调用，计为该驱动 error。
			$halt_state = null;
			if ( null !== $this->halt_check ) {
				$halt_state = call_user_func( $this->halt_check, $cloud_id );
			}
			if ( null !== $halt_state && '' !== (string) $halt_state ) {
				$this->record_cloud_event(
					$cloud_id,
					Verdict::error( $cloud_id, 'driver_halted:' . $halt_state, array( 'driver_halted:' . $halt_state ) ),
					$resolved
				);
				continue; // 无有效判定（error 不参与定级）。
			}

			$cloud_verdict = $cloud_driver->check( $review_content, $review_meta );

			if ( Verdict::ERROR === $cloud_verdict->verdict() ) {
				$driver_meta = $this->cloud_driver_meta( $cloud_driver );
				$this->record_cloud_event( $cloud_id, $cloud_verdict, $resolved, $driver_meta );
				// semantic 终态 → 当日停转标记（密钥错/欠费不靠重试解决，02 §5）。
				if ( null !== $this->halt_mark
					&& isset( $driver_meta['category'] ) && RemoteResult::SEMANTIC === $driver_meta['category'] ) {
					call_user_func( $this->halt_mark, $cloud_id );
				}
				continue; // error 不终止（failover 链，02 §2.2）。
			}

			if ( Verdict::REJECT === $cloud_verdict->verdict() ) {
				// 云端高置信直拒：文案 banned_word 档（只调侃行为）、rule = 驱动 reason（如 Ad,99）。
				$meta = array_merge(
					$this->cloud_driver_meta( $cloud_driver ),
					array( 'ip_src' => $resolved['ip_src'], 'client_ip' => $resolved['ip'], 'driver' => $cloud_id )
				);
				return $this->reject(
					'review',
					$cloud_verdict,
					'banned_word',
					array( 'rule' => $cloud_verdict->reason() ),
					$meta,
					$reject_env
				);
			}

			if ( Verdict::REVIEW === $cloud_verdict->verdict() ) {
				// review 不短路：记录事件（04 §3 写入矩阵 review ✓），继续链上下一驱动。
				$this->record_cloud_event( $cloud_id, $cloud_verdict, $resolved, $this->cloud_driver_meta( $cloud_driver ) );
				$effective[ $cloud_id ] = $cloud_verdict;
				continue;
			}

			$effective[ $cloud_id ] = $cloud_verdict; // pass 继续（默认不落库）。
		}

		/* 最严有效判定收口（error 不参与定级）：review → 入待审（D8：comment_approved='0'，
		 * 核心正常流程——评论经 wp_new_comment 创建，事件已记、事后对账）。
		 * C4 真机发现：WP 7.x wp_new_comment 在 preprocess_comment 后经 wp_allow_comment 重算
		 * comment_approved——直接改 commentdata 会被覆盖；hold_moderation 标志由
		 * pre_comment_approved filter（Bootstrap 装配）消费，Akismet 同款 canonical 通道。 */
		$severe = Verdict::most_severe( ...array_values( $effective ) );
		if ( null !== $severe && Verdict::REVIEW === $severe->verdict() ) {
			$this->hold_moderation = true;
			$this->hold_source     = 'cloud'; // 【v1.4·R1 G1】待审来源：云端 review 判定收口。
			$this->hold_driver     = $severe->driver_id();
			$this->hold_meta       = array(
				'post_id'   => $this->post_id( $commentdata, $request ),
				'author'    => $author,
				'email'     => $email,
				'ip_src'    => $resolved['ip_src'],
				'client_ip' => $resolved['ip'],
			);
			$commentdata['comment_approved'] = '0';
			return array( 'decision' => 'allow', 'commentdata' => $commentdata );
		}

		/* 云端全故障收口（02 §2.2）：启用中的云端驱动全部 error/停转 → 忽略 LocalRules 的 pass、
		 * 按云端降级档处理（否则 LocalRules 恒有的 pass 使云端全故障退化为事实放行，违背 D6）。
		 * 多驱动 fail_mode 不一致取最严者（fail-review 优先）。 */
		if ( array() !== $cloud_ids && array() === $effective ) {
			if ( 'review' === $this->strictest_fail_mode( $cloud_ids ) ) {
				$this->hold_moderation = true;
				$this->hold_source     = 'cloud_all_failed'; // 【v1.4·R1 G1】云端全故障 fail-review 兜底。
				$this->hold_driver     = 'cloud';
				$this->hold_meta       = array(
					'post_id'   => $this->post_id( $commentdata, $request ),
					'author'    => $author,
					'email'     => $email,
					'ip_src'    => $resolved['ip_src'],
					'client_ip' => $resolved['ip'],
				);
				$commentdata['comment_approved'] = '0'; // fail-review：入待审（error 事件已逐驱动记录）。
			}
			// fail-open：放行（error 事件照记——fail-open 不等于无痕）。
			return array( 'decision' => 'allow', 'commentdata' => $commentdata );
		}

		/* 步骤 5：全过 → 放行。pass 默认不落库（04 §3 写入矩阵；pass_sampling 采样为高级开关，
		 * 由默认记录器路径的调用方 EventsTable::should_record 决定——M1 编排不落 pass）。 */
		return array( 'decision' => 'allow', 'commentdata' => $commentdata );
	}

	/* -------------------------------------------------------------------
	 * WP 边界钩子（单测环境不可达，静态自查清单覆盖）
	 * ------------------------------------------------------------------ */

	/**
	 * preprocess_comment @ -PHP_INT_MAX。
	 *
	 * @param array<string, mixed> $commentdata 评论数据。
	 * @return array<string, mixed>
	 */
	public function hook_preprocess_comment( array $commentdata ): array {
		$exempt = $this->is_exempt_context();

		$request = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 评论表单无原生 nonce（未登录访客），验证由存根+票据承担。

		$result = $this->orchestrate( $commentdata, $request, isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array(), $exempt );

		/* token 单次消费（A6：核验后从 $_POST unset，防重放/互斥插件踩踏）。
		 * 接口 v1.3 契约：unset 发生在闸边界，按 credentialFields() 清单统一清理
		 * （M4 驱动的 token 域不再是硬编码 wk_captcha_* 两枚）。
		 * 【v1.4·R2】wk_trust 随 unset_fields 一并清理（闸级字段防泄漏到下游插件——03 §2.1）。 */
		$unset_fields = array( 'wk_stub', 'wk_trust' );
		if ( null !== $this->captcha ) {
			foreach ( $this->captcha->credentialFields() as $token_field ) {
				$unset_fields[] = $token_field;
			}
		}
		foreach ( $unset_fields as $unset_field ) {
			unset( $_POST[ $unset_field ] );
		}

		if ( 'reject' === $result['decision'] ) {
			$this->dispatch_reject( $result );
		}

		return $result['commentdata'];
	}

	/**
	 * pre_comment_approved filter（C4，02 §3.1 D8 的 WP canonical 通道）：
	 * 本次编排产出 review 判定 → 评论强制入待审。
	 *
	 * 真机发现（2026-11-10）：WP 7.x wp_new_comment 在 preprocess_comment 之后经
	 * wp_allow_comment → wp_check_comment_data 重算 comment_approved，编排期对
	 * commentdata 的赋值被覆盖——本 filter 为 Akismet 同款落点。只降不升：仅当
	 * 核心判定为已批准（1）且我方判 review 时降 '0'，他方 spam/trash 与核心原生
	 * 待审判定保留。hold_moderation 消费后自清（单请求单评论语义）。
	 *
	 * 【v1.4·R1 G1/F45】hold_moderation 消费时落**待审收口事件**（meta.source 标注来源）：
	 * 'cloud'（云端 review 判定，verdict=review）/ 'cloud_all_failed'（云端全故障 fail-review
	 * 兜底，verdict=error）——「WP 核心首评待审」不产生插件事件，三种待审可区分（04 §3 矩阵
	 * review 行）。收口事件不进统计计数（by_driver 已按驱动 verdict 逐条计过——防双计）。
	 *
	 * @param int|string $approved    核心判定的审批状态（1|0|'spam'|'trash'）。
	 * @param array      $commentdata 评论数据。
	 * @return int|string
	 */
	public function filter_pre_comment_approved( $approved, array $commentdata ) {
		if ( $this->hold_moderation ) {
			// 待审收口留痕（02 §3.1 v1.4——幂等，单请求单评论一行）。
			$this->record_hold_event();

			/* 【真机补注 2026-11-10（IMPL-B1）】WP 7.x wp_new_comment 对本 filter 两度回调：
			 * wp_allow_comment（首算）→ wp_check_comment_data（wp_filter_comment 后重算——
			 * comment_whitelist 命中时重算回 1）。hold 标志须持续压低至插入完成，不能首次
			 * 消费即自清——否则第二次重算把降级覆盖（真机实测 comment_approved=1 漏待审）。
			 * 标志与留痕素材保持到下一次 orchestrate 重置（单请求单评论语义）。 */
			if ( 1 === (int) $approved ) {
				return '0';
			}
		}
		return $approved;
	}

	/**
	 * 待审收口事件（04 §3 写入矩阵 review 行 v1.4 注）：hold_moderation 置位消费时落一行
	 * stage=review + meta.source 的事件；verdict 随来源（cloud=review / cloud_all_failed=error）。
	 *
	 * 不进 StatsCache（驱动 review/error 判定事件已逐条计数——收口行是来源标注，防双计）。
	 * comment_id 事后对账（评论由核心创建于本 filter 之后，v1.2 口径沿用：记 post_id/author/email）。
	 * 幂等：同请求内 WP 7.x 双回调只落一行（$hold_event_recorded 闩锁）。
	 *
	 * @return void
	 */
	private function record_hold_event(): void {
		if ( $this->hold_event_recorded ) {
			return;
		}
		$source = is_string( $this->hold_source ) ? $this->hold_source : 'cloud';
		$driver = is_string( $this->hold_driver ) && '' !== $this->hold_driver ? $this->hold_driver : 'cloud';
		$verdict = ( 'cloud_all_failed' === $source )
			? Verdict::error( $driver, 'cloud_all_failed', array( 'cloud_all_failed' ) )
			: Verdict::review( $driver, 'hold_moderation', array( 'hold_moderation' ) );

		$meta = array_merge( $this->hold_meta, array( 'source' => $source ) );
		call_user_func( $this->recorder, 'review', $verdict, $meta );

		// 只闩「已留痕」；hold_moderation 保持置位（WP 7.x 双回调持续压低，见 filter 头注）。
		$this->hold_event_recorded = true;
	}

	/* -------------------------------------------------------------------
	 * M2 反馈2 T2：WP 核心评论拒绝品牌化（重复评论 / 评论过快）
	 *
	 * 接管点（WP 7.1.3 core 源位）：
	 *   - wp_allow_comment() 查重命中 → do_action('comment_duplicate_trigger', $commentdata)
	 *     后默认 wp_die 英文「Duplicate comment detected…」——挂 action（优先级 10、参数 1）
	 *     输出 DiePage 品牌页（文案键 duplicate_comment），接管后 wp_die 终止（核心语义保持）；
	 *     AJAX/REST 上下文不接管页面（核心各自 die()/WP_Error JSON），文案经
	 *     comment_duplicate_message filter 换为 wink 键。
	 *   - wp_check_comment_flood() 洪泛命中 → do_action('comment_flood_trigger') 后
	 *     wp_die(apply_filters('comment_flood_message', 英文))——filter 换文案为 wink 键
	 *     comment_flood（页面形态=WP 原生可接受，文案带台阶；pre_comment_on_flood 钩子
	 *     在 WP 7.1.3 不存在，容器内 grep 核实）。
	 * 事件（两路同形）：gate=comment、stage=core（开放枚举）、driver=wp_core、
	 * rule_hit=duplicate_comment|comment_flood；不留 spam 档（非内容判定——重复/过快
	 * 与内容无关，核心亦未创建评论，无留档对象）。
	 * ------------------------------------------------------------------ */

	/**
	 * 核心拒绝纯逻辑面：记事件 + 组装品牌页面载荷（文案/证据行）。
	 *
	 * @param string               $rule   规则键（Copywork::keys() 之一：duplicate_comment|comment_flood）。
	 * @param array<string, mixed> $server $_SERVER（IP 解析注入）。
	 * @param array<string, mixed> $meta_extra 附加事件 meta（如 post_id）。
	 * @return array{message: string, evidence: array<string, string>} 品牌页面载荷。
	 */
	public function core_rejection( string $rule, array $server, array $meta_extra = array() ): array {
		$resolved = $this->resolver->resolve( $server );

		$meta = array_merge( array(
			'ip_src' => $resolved['ip_src'],
			'client_ip' => $resolved['ip'],
			'driver' => 'wp_core',
		), $meta_extra );

		$event_no = (string) call_user_func( $this->recorder, 'core', Verdict::reject( 'wp_core', $rule, array( $rule ) ), $meta );

		$messages = $this->messages();
		$message  = isset( $messages[ $rule ] ) && is_string( $messages[ $rule ] ) ? $messages[ $rule ] : $rule;

		$evidence = array();
		if ( '' !== $event_no ) {
			$evidence['事件编号'] = $event_no;
		}
		$evidence['命中规则'] = $rule;
		$evidence['时间']     = date( 'Y-m-d H:i:s', (int) call_user_func( $this->now ) );

		return array(
			'message'  => $message,
			'evidence' => $evidence,
		);
	}

	/**
	 * comment_duplicate_trigger action @ 10（WP 边界）：原生上下文 = 品牌页 wp_die；
	 * AJAX/REST = 只记事件（响应形态交还核心：die() 纯文案 / WP_Error JSON，文案已由
	 * filter_comment_duplicate_message 换）。
	 *
	 * @param array<string, mixed> $commentdata 核心查重命中的评论数据。
	 * @return void
	 */
	public function hook_comment_duplicate_trigger( array $commentdata ): void {
		$meta = array();
		if ( isset( $commentdata['comment_post_ID'] ) && is_scalar( $commentdata['comment_post_ID'] ) ) {
			$meta['post_id'] = (int) $commentdata['comment_post_ID'];
		}

		$payload = $this->core_rejection( 'duplicate_comment', isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array(), $meta );

		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return; // 核心各自响应（die 纯文案 / WP_Error JSON，文案已 filter 换）；本闸只补事件。
		}

		// 原生上下文：品牌页 wp_die 终止（保持核心「重复即终止」语义；标题/文案/联系邮箱
		// 占位符由 DiePage::show 从文案工坊统一读取）。
		DiePage::show( 'duplicate_comment', array(
			'event_no' => isset( $payload['evidence']['事件编号'] ) ? $payload['evidence']['事件编号'] : '',
			'rule'     => 'duplicate_comment',
			'time'     => isset( $payload['evidence']['时间'] ) ? $payload['evidence']['时间'] : '',
		), $payload['evidence'] );
	}

	/**
	 * comment_duplicate_message filter：核心英文文案 → wink 键（AJAX die / REST WP_Error 消费）。
	 *
	 * @param string $message 核心默认文案。
	 * @return string
	 */
	public function filter_comment_duplicate_message( string $message ): string {
		$messages = $this->messages();
		return isset( $messages['duplicate_comment'] ) && is_string( $messages['duplicate_comment'] )
			? $messages['duplicate_comment']
			: $message; // 未注册键兜底：核心文案原样（不空白）。
	}

	/**
	 * comment_flood_trigger action @ 10（WP 边界）：记事件（洪泛触发，die 与 avoid_die 两路都过此）。
	 *
	 * @param int $time_lastcomment 上次评论时间戳（未消费）。
	 * @param int $time_newcomment  本次时间戳（未消费）。
	 * @return void
	 */
	public function hook_comment_flood_trigger( int $time_lastcomment, int $time_newcomment ): void {
		unset( $time_lastcomment, $time_newcomment );
		$this->core_rejection( 'comment_flood', isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array() );
	}

	/**
	 * comment_flood_message filter：核心英文文案 → wink 键（页面形态保持 WP 原生 wp_die）。
	 *
	 * @param string $message 核心默认文案。
	 * @return string
	 */
	public function filter_comment_flood_message( string $message ): string {
		$messages = $this->messages();
		return isset( $messages['comment_flood'] ) && is_string( $messages['comment_flood'] )
			? $messages['comment_flood']
			: $message;
	}

	/**
	 * 豁免判定（02 §3.1 步骤 0 的边界形态；C1 修正 02 §3.5 配套）：
	 * 自动保存/导入/常规后台（wp-admin 页面）放行；**admin-ajax（DOING_AJAX）主题评论
	 * 提交不再因 is_admin()=true 被豁免**——AJAX 评论主题（Argon/Sakurairo 等）照常走闸
	 * （拦截走头协议分支），仅持有管理评论能力的管理员快速回复豁免。
	 *
	 * @return bool
	 */
	private function is_exempt_context(): bool {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) ) {
			return true;
		}
		if ( ! is_admin() ) {
			return false; // 前台原生/主题提交：不豁免。
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			// admin-ajax：匿名主题评论提交走闸；管理员快速回复（moderate_comments）豁免。
			return current_user_can( 'moderate_comments' );
		}
		return true; // wp-admin 常规后台上下文。
	}

	/**
	 * 失败响应落地分派（02 §3.5 三分支：判定已在 orchestrate 完成——留档/事件等拦截
	 * 证据三条分支完全一致，此处只选响应形态）。
	 *
	 * 次序防呆：先原生（SCRIPT_FILENAME realpath）→ 再 AJAX（DOING_AJAX）→ 兜底品牌页。
	 *
	 * @param array<string, mixed> $result orchestrate 的 reject 结果。
	 * @return void
	 */
	private function dispatch_reject( array $result ): void {
		$script  = isset( $_SERVER['SCRIPT_FILENAME'] ) && is_scalar( $_SERVER['SCRIPT_FILENAME'] ) ? (string) $_SERVER['SCRIPT_FILENAME'] : '';
		$native  = ResponseDispatcher::is_native_submission( $script, defined( 'ABSPATH' ) ? ABSPATH : '' );
		$branch  = ResponseDispatcher::branch( $native, defined( 'DOING_AJAX' ) && DOING_AJAX );

		if ( 'ajax' === $branch ) {
			$this->send_ajax_reject( $result );
			return; // send 内 exit；此行仅为控制流自明。
		}

		// 原生表单（wp-comments-post.php）与非原生非 AJAX 边角（程序化 wp_new_comment）：品牌页。
		$this->intercept_result( $result );
	}

	/**
	 * AJAX 分支头协议响应（02 §3.5 ③）：四头 + 简短 JSON body；headers_sent 退化
	 * 为 body 全量携带——杜绝「AJAX 主题收到整页 HTML 致命错误」事故形态（红线 6）。
	 *
	 * @param array<string, mixed> $result reject 结果。
	 * @return void
	 */
	private function send_ajax_reject( array $result ): void {
		$sent = headers_sent();
		if ( ! $sent ) {
			foreach ( ResponseDispatcher::ajax_headers( $result, $this->id ) as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}
		// WP AJAX 约定形态 {success:false,data:{…}}；默认 200（admin-ajax 惯例，
		// 主题 JS 按 success/data 解析；头协议承载错误语义）。
		echo wp_json_encode( ResponseDispatcher::ajax_body( $result, $this->id, $sent ) );
		exit;
	}

	/**
	 * rest_preprocess_comment：同一编排 + $_POST 隔离（拆解报告 CommentBase.php:68-113 手法：
	 * 临时清空 $_POST 防外层主请求 token 泄漏进编排）；REST 上下文拦截返回 WP_Error（JSON）。
	 *
	 * @param array<string, mixed> $comment REST 准备的评论数组。
	 * @return array<string, mixed>|\WP_Error
	 */
	public function hook_rest_preprocess_comment( array $comment ) {
		// REST 无签名存根 → 存根校验必拦（等价封禁 REST 直接评论；GraphQL/REST 正式支持为 Backlog）。
		$backup = isset( $_POST ) ? $_POST : null;
		$_POST  = array(); // 隔离：编排请求面来自 REST body 而非全局 $_POST。

		$request = array(
			'comment_post_ID'      => isset( $comment['comment_post_ID'] ) ? $comment['comment_post_ID'] : '',
			'comment_content'      => isset( $comment['comment_content'] ) ? $comment['comment_content'] : '',
			// 作者字段随编排（F36 拼接送审 + F37 留档载荷；REST prepared 同名键直传）。
			'comment_author'       => isset( $comment['comment_author'] ) && is_scalar( $comment['comment_author'] ) ? (string) $comment['comment_author'] : '',
			'comment_author_email' => isset( $comment['comment_author_email'] ) && is_scalar( $comment['comment_author_email'] ) ? (string) $comment['comment_author_email'] : '',
			'comment_author_url'   => isset( $comment['comment_author_url'] ) && is_scalar( $comment['comment_author_url'] ) ? (string) $comment['comment_author_url'] : '',
			'comment_parent'       => isset( $comment['comment_parent'] ) && is_scalar( $comment['comment_parent'] ) ? (int) $comment['comment_parent'] : 0,
		);

		$result = $this->orchestrate( $request, $request, isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array() );

		if ( null !== $backup ) {
			$_POST = $backup; // 恢复外层 $_POST。
		}

		if ( 'reject' === $result['decision'] ) {
			// 02 §3.5 ②：REST = WP_Error JSON（控制器转 400/403 JSON）+ 补头协议
			// （X-Wink-Gatekeeper-Scene/-Error/-Code/-Msg——REST 客户端可读）。
			if ( ! headers_sent() ) {
				foreach ( ResponseDispatcher::rest_error_headers( $result, $this->id ) as $name => $value ) {
					header( $name . ': ' . $value );
				}
			}
			return new \WP_Error(
				'wink_gatekeeper_blocked',
				isset( $result['message'] ) ? $result['message'] : __( '评论未通过验证。', 'wink-gatekeeper' ),
				array( 'status' => 403 )
			);
		}

		// C4：review 判定（comment_approved='0'）发生在编排返回的 commentdata 上——
		// 回传编排产物而非入参原值（REST 路径与原生路径同语义，02 §3.1 D8）。
		return array() === $result['commentdata'] ? $comment : $result['commentdata'];
	}

	/**
	 * comment_form_submit_field @ 10：容器注入（03 §2.1 v1.3 并排——SubmitRow 把 inline
	 * 容器插进主题提交段落、提交控件之前；无提交控件主题回退前置堆叠形态）。
	 *
	 * @param string $submit_field 提交区 HTML。
	 * @return string
	 */
	public function render_submit_field( string $submit_field ): string {
		if ( ! $this->is_enabled() || null === $this->captcha || ! $this->captcha->is_configured() ) {
			// 闸关/未配置驱动：零 DOM 变化（02 §2.1 未配置行为）。
			return $submit_field;
		}

		$context_id = (string) call_user_func( $this->context_provider ); // 评论闸 context_id = comment_post_ID（02 §3.3）。
		$stub       = Stub::issue( $this->id, $context_id, true, (int) call_user_func( $this->now ), $this->stub_hash );

		$html = $this->captcha->render( array(
			'gate'       => $this->id,
			'action'     => 'comment',
			'context_id' => $context_id,
			'stub'       => $stub,
		) );

		/* 信用免验标记注入（03 §2.1/02 §6——【v1.4·R2】闸渲染层职责）：
		 *   - 容器 data-wk-trust="1"：gatekeeper.js 预检门生效条件（缺失/功能关=零行为变化）；
		 *   - 隐藏域 wk_trust（与 wk_stub 并列——闸级字段，不进 credentialFields() 契约；
		 *     预检 trust_pass 后由 JS 写入，提交期随表单携带）。
		 * 注入采用标记替换（驱动渲染产物的容器/存根行形态约定）；M4 新驱动标记不匹配时
		 * 自然缺省=预检门不生效（fail-safe 退化为现行验证码链，不报错）。 */
		if ( $this->trust_enabled() ) {
			$marked = preg_replace(
				'/(class="wk-gate"([^>]*?)data-gate="comment")/i',
				'$1 data-wk-trust="1"',
				$html,
				1
			);
			if ( null !== $marked && $marked !== $html ) {
				$html = $marked;
				$html = (string) preg_replace(
					'/(<input type="hidden" name="wk_stub")/i',
					'<input type="hidden" name="wk_trust" value="">$1',
					$html,
					1
				);
			}
		}

		return SubmitRow::inject( $submit_field, $html );
	}

	/* -------------------------------------------------------------------
	 * 内部
	 * ------------------------------------------------------------------ */

	/**
	 * 统一拦截出口：留档（spam）→ 记事件 → 组装消息/证据（事件编号/规则/时间——不暴露 IP/原文/reason）。
	 *
	 * @param string               $stage    阶段。
	 * @param Verdict              $verdict  判定（reason = 规则键，落事件）。
	 * @param string               $copy_key 文案键（Copywork::keys() 之一；空串 = 用 $direct_message）。
	 * @param array<string, mixed> $vars     占位符变量。
	 * @param array<string, mixed> $meta     事件 meta。
	 * @param array<string, mixed> $reject_env 留档环境（commentdata/ip/ua；stub 站点不传）。
	 * @return array{decision: string, commentdata: array<string, mixed>, message: string, evidence: array<string, string>}
	 */
	private function reject( string $stage, Verdict $verdict, string $copy_key, array $vars, array $meta, array $reject_env = array() ): array {
		$messages = $this->messages();
		$template = isset( $messages[ $copy_key ] ) && is_string( $messages[ $copy_key ] ) ? $messages[ $copy_key ] : '';
		$message  = Copywork::format( $template, $vars );

		return $this->reject_with_message( $stage, $verdict, $message, $meta, $vars, $reject_env );
	}

	/**
	 * 拦截出口（消息终态版：驱动文案直通）。
	 *
	 * 落地次序（02 §3.1 v1.3 / §3.5）：先 spam 留档（meta 回填 comment_id）→ 落事件 →
	 * 后响应——原生/AJAX/REST 三分支拦截证据一致（AJAX 分支分派是 C1 域，复用本方法）。
	 *
	 * @param string               $stage    阶段。
	 * @param Verdict              $verdict  判定。
	 * @param string               $message  终态消息。
	 * @param array<string, mixed> $meta     事件 meta。
	 * @param array<string, mixed> $vars     额外占位符（event_no/rule/time 由本方法注入）。
	 * @param array<string, mixed> $reject_env 留档环境（commentdata/ip/ua）。
	 * @return array{decision: string, commentdata: array<string, mixed>, message: string, evidence: array<string, string>}
	 */
	private function reject_with_message( string $stage, Verdict $verdict, string $message, array $meta, array $vars = array(), array $reject_env = array() ): array {
		$now = (int) call_user_func( $this->now );

		// 拦截留档（F37/D15）：stage ∈ {ip, captcha, review} 先入垃圾库，事件 meta 回填 comment_id。
		$comment_id = $this->archive_rejected( $stage, $reject_env );
		if ( null !== $comment_id ) {
			$meta['comment_id'] = $comment_id;
		}

		$event_no = (string) call_user_func( $this->recorder, $stage, $verdict, $meta );

		// 证据行仅事件编号/规则名/时间（03 §3 不暴露清单：IP/原文/内部码/reason 不出）。
		$evidence = array();
		if ( '' !== $event_no ) {
			$evidence['事件编号'] = $event_no;
		}
		if ( '' !== $verdict->reason() ) {
			$evidence['命中规则'] = $verdict->reason();
		}
		$evidence['时间'] = date( 'Y-m-d H:i:s', $now );

		// 文案内 event_no/time 占位符替换（记录在案后回填）。
		$message = Copywork::format( $message, array_merge( $vars, array(
			'event_no' => $event_no,
			'time'     => $evidence['时间'],
			'rule'     => $verdict->reason(),
		) ) );

		return array(
			'decision'    => 'reject',
			'commentdata' => array(),
			'message'     => $message,
			'evidence'    => $evidence,
		);
	}

	/* -------------------------------------------------------------------
	 * 云端链编排辅助（C4，02 §2.2 串联语义）
	 * ------------------------------------------------------------------ */

	/**
	 * 生效的云端链 id 列表（按 review.cloud.chain 顺序；enabled 关或未装配的 id 剔除）。
	 *
	 * 「未装配的 id 剔除」语义：chain 里声明了但驱动未实现/未配置（如默认 deepseek——M3）
	 * 的条目静默跳过，且不计入「云端全故障」收口判定（未启用的驱动不构成故障面）。
	 *
	 * @return string[]
	 */
	private function active_cloud_chain_ids(): array {
		if ( empty( $this->settings['review']['cloud']['enabled'] ) ) {
			return array();
		}
		$chain = $this->settings['review']['cloud']['chain'] ?? array();
		if ( ! is_array( $chain ) ) {
			return array();
		}
		$ids = array();
		foreach ( $chain as $chain_id ) {
			if ( is_string( $chain_id ) && '' !== $chain_id && isset( $this->cloud_chain[ $chain_id ] ) ) {
				$ids[] = $chain_id;
			}
		}
		return $ids;
	}

	/**
	 * 云端驱动 fail_mode 最严者（fail-review 优先；非法值归 review——默认且更严）。
	 *
	 * @param string[] $cloud_ids 生效云端驱动 id 列表。
	 * @return string 'review' | 'open'
	 */
	private function strictest_fail_mode( array $cloud_ids ): string {
		foreach ( $cloud_ids as $cloud_id ) {
			$mode = $this->settings['review']['cloud'][ $cloud_id ]['fail_mode'] ?? 'review';
			if ( 'open' !== $mode ) {
				return 'review';
			}
		}
		return 'open';
	}

	/**
	 * 云端驱动事件 meta 读取（last_check_meta 模式——C1 泛化模式二抄：方法在则读，不在则空）。
	 *
	 * @param ReviewDriverInterface $driver 云端驱动。
	 * @return array<string, mixed>
	 */
	private function cloud_driver_meta( ReviewDriverInterface $driver ): array {
		if ( method_exists( $driver, 'last_check_meta' ) ) {
			$meta = call_user_func( array( $driver, 'last_check_meta' ) );
			return is_array( $meta ) ? $meta : array();
		}
		return array();
	}

	/**
	 * 云端事件记录（review/review/error 判定落库，04 §3 写入矩阵；meta 带 ip_src）。
	 *
	 * @param string               $cloud_id 驱动 id。
	 * @param Verdict              $verdict  判定。
	 * @param array<string, mixed> $resolved IP 解析结果（ip_src 注 meta）。
	 * @param array<string, mixed> $driver_meta 驱动附加 meta（Label/Score/category 等）。
	 * @return void
	 */
	private function record_cloud_event( string $cloud_id, Verdict $verdict, array $resolved, array $driver_meta = array() ): void {
		$meta = array_merge( $driver_meta, array( 'ip_src' => $resolved['ip_src'], 'client_ip' => $resolved['ip'] ) );
		call_user_func( $this->recorder, 'review', $verdict, $meta );
	}

	/* -------------------------------------------------------------------
	 * 送审拼装与拦截留档（F36/F37，02 §2.2 / §3.1 v1.3）
	 * ------------------------------------------------------------------ */

	/**
	 * 送审串统一拼装（02 §2.2 v1.3 消费约定：闸拼装，驱动不各自拼）。
	 *
	 * review.include_author_fields 默认 true（04 §2）——LocalRules 闸④与云审核送审串同口径；
	 * false 时退回仅正文。恒为三行结构（空字段保留空行——单一确定性形态，杜绝口径漂移）。
	 * 【v1.4·R2】private 提为 public：预检端点与提交期共用同一拼装函数（两时点纯逻辑
	 * 字面一致——R2 §2.1「同一套纯逻辑」的字面落实；Bootstrap 注入 Precheck）。
	 *
	 * @param string $author 昵称。
	 * @param string $email  邮箱。
	 * @param string $body   正文原值。
	 * @return string「昵称 \n 邮箱 \n 正文」或正文。
	 */
	public function assemble_review_content( string $author, string $email, string $body ): string {
		$include = true;
		if ( isset( $this->settings['review'] ) && is_array( $this->settings['review'] )
			&& array_key_exists( 'include_author_fields', $this->settings['review'] ) ) {
			$include = (bool) $this->settings['review']['include_author_fields'];
		}
		return $include ? $author . "\n" . $email . "\n" . $body : $body;
	}

	/**
	 * 拦截留档入口（02 §3.1 v1.3「拦截留档」①②，F37/D15）。
	 *
	 * 仅 stage ∈ {ip, captcha, review}（携带完整评论数据的判定）且开关开、留档器已装配时
	 * 直插垃圾库；stub 不留档（②）。AJAX 分支（C1 域）分派前同样经此方法留档。
	 *
	 * @param string               $stage      阶段。
	 * @param array<string, mixed> $reject_env commentdata/ip/ua。
	 * @return int|null comment_id（未留档 = null）。
	 */
	private function archive_rejected( string $stage, array $reject_env ): ?int {
		if ( ! in_array( $stage, array( 'ip', 'captcha', 'review' ), true ) ) {
			return null; // stub 及未知阶段不留档（02 §3.1 ②）。
		}
		if ( null === $this->archiver || ! $this->archive_spam_enabled() ) {
			return null; // 留档器未装配（单测默认）或 intercept.archive_spam=false（直接拦不入库形态）。
		}
		$comment_id = call_user_func( $this->archiver, self::archive_comment_payload( $reject_env ) );
		return ( is_int( $comment_id ) && $comment_id > 0 ) ? $comment_id : null;
	}

	/**
	 * 留档开关（04 §2 intercept.archive_spam，默认 true；缺子树按默认——merge_defaults 同语义兜底）。
	 *
	 * @return bool
	 */
	private function archive_spam_enabled(): bool {
		return ! isset( $this->settings['intercept']['archive_spam'] )
			|| (bool) $this->settings['intercept']['archive_spam'];
	}

	/**
	 * 留档载荷归一化（02 §3.1 实现要点：REST 上下文 prepared 字段差异在此统一映射）。
	 *
	 * comment_approved='spam'、comment_type=''（默认）；作者/邮箱/正文取判定期 commentdata，
	 * IP 取 IpResolver 解析值、UA 随档。经留档器直插 wp_insert_comment（不经 preprocess 链）。
	 *
	 * @param array<string, mixed> $reject_env commentdata/ip/ua。
	 * @return array<string, mixed> wp_insert_comment 形态载荷。
	 */
	private static function archive_comment_payload( array $reject_env ): array {
		$commentdata = isset( $reject_env['commentdata'] ) && is_array( $reject_env['commentdata'] ) ? $reject_env['commentdata'] : array();

		return array(
			'comment_post_ID'      => isset( $commentdata['comment_post_ID'] ) && is_scalar( $commentdata['comment_post_ID'] ) ? (int) $commentdata['comment_post_ID'] : 0,
			'comment_author'       => isset( $commentdata['comment_author'] ) && is_scalar( $commentdata['comment_author'] ) ? (string) $commentdata['comment_author'] : '',
			'comment_author_email' => isset( $commentdata['comment_author_email'] ) && is_scalar( $commentdata['comment_author_email'] ) ? (string) $commentdata['comment_author_email'] : '',
			'comment_author_url'   => isset( $commentdata['comment_author_url'] ) && is_scalar( $commentdata['comment_author_url'] ) ? (string) $commentdata['comment_author_url'] : '',
			'comment_content'      => isset( $commentdata['comment_content'] ) && is_scalar( $commentdata['comment_content'] ) ? (string) $commentdata['comment_content'] : '',
			'comment_parent'       => isset( $commentdata['comment_parent'] ) && is_scalar( $commentdata['comment_parent'] ) ? (int) $commentdata['comment_parent'] : 0,
			'user_id'              => isset( $commentdata['user_id'] ) && is_scalar( $commentdata['user_id'] ) ? (int) $commentdata['user_id'] : 0,
			'comment_author_IP'    => isset( $reject_env['ip'] ) && is_string( $reject_env['ip'] ) ? $reject_env['ip'] : '',
			'comment_agent'        => isset( $reject_env['ua'] ) && is_string( $reject_env['ua'] ) ? $reject_env['ua'] : '',
			'comment_type'         => '',   // 02 §3.1：comment_type 默认。
			'comment_approved'     => 'spam', // 拦截留档分类（wp_insert_comment 直插语义）。
		);
	}

	/**
	 * WP 边界拦截落地（wp_die 品牌页 + back_link；03 §3）。
	 *
	 * @param array<string, mixed> $result orchestrate 的 reject 结果。
	 * @return void
	 */
	private function intercept_result( array $result ): void {
		$settings = get_option( 'wink_gatekeeper_settings', array() );
		$titles   = isset( $settings['copy']['titles'] ) && is_array( $settings['copy']['titles'] ) ? $settings['copy']['titles'] : array();

		$title = '';
		// 按命中规则推断标题键（evidence 的规则名 = Copywork 键或驱动 rule 码）。
		if ( isset( $result['evidence']['命中规则'] ) ) {
			$rule  = (string) $result['evidence']['命中规则'];
			$first = explode( '/', $rule )[0]; // rule 码可能为 CaptchaCode=8 形态。
			$first = explode( '=', $first )[0];
			$key   = ( 'bad-signature' === $rule || 'captcha_missing' === $rule ) ? 'captcha_missing' : $first;
			if ( isset( $titles[ $key ] ) && is_string( $titles[ $key ] ) ) {
				$title = $titles[ $key ];
			}
		}

		$contact = isset( $settings['brand']['contact_email'] ) && is_string( $settings['brand']['contact_email'] ) ? $settings['brand']['contact_email'] : '';
		$message = isset( $result['message'] ) ? $result['message'] : '';
		if ( '' !== $contact && false !== strpos( $message, '{contact_email}' ) ) {
			$message = Copywork::format( $message, array( 'contact_email' => $contact ) );
		}

		wp_die(
			DiePage::build( $title, $message, $result['evidence'], $contact, WINK_GATEKEEPER_URL . 'assets/brand/logo-wink.svg', get_locale() ),
			$title,
			array( 'back_link' => false ) // back_link 由品牌按钮承担（03 §3 版式）。
		);
	}

	private function is_enabled(): bool {
		return ! empty( $this->settings['gates']['comment']['enabled'] );
	}

	/**
	 * 当前装配的验证码驱动（Bootstrap 前台资产入队用；未配置返回 null）。
	 *
	 * @return \WinkGatekeeper\Drivers\CaptchaDriverInterface|null
	 */
	public function captcha_driver(): ?CaptchaDriverInterface {
		return $this->captcha;
	}

	/**
	 * @return array<string, string>
	 */
	private function messages(): array {
		$messages = $this->settings['copy']['messages'] ?? array();
		return is_array( $messages ) ? array_merge( Copywork::defaults()['messages'], array_filter( $messages, 'is_string' ) ) : Copywork::defaults()['messages'];
	}

	/**
	 * 当前验证码驱动 id（接口 v1.1 id()：事件表 driver 列/分驱动统计键的单一来源）。
	 */
	private function captcha_driver_id(): string {
		$captcha = $this->captcha;
		return ( null !== $captcha && method_exists( $captcha, 'id' ) ) ? $captcha->id() : '';
	}

	/* -------------------------------------------------------------------
	 * 信用免验辅助（02 §3.3a——【v1.4·R2】）
	 * ------------------------------------------------------------------ */

	/**
	 * 信用免验功能开关（04 §2 captcha.trust.enabled；缺子树按默认开——merge_defaults 同语义）。
	 */
	private function trust_enabled(): bool {
		return ! isset( $this->settings['captcha']['trust']['enabled'] )
			|| (bool) $this->settings['captcha']['trust']['enabled'];
	}

	/**
	 * 信任凭证有效期（分钟；04 §2 captcha.trust.ttl_minutes，默认与 verify_cache 同宽）。
	 */
	private function trust_ttl_minutes(): int {
		$ttl = (int) ( $this->settings['captcha']['trust']['ttl_minutes'] ?? TrustStub::DEFAULT_TTL_MINUTES );
		return $ttl > 0 ? $ttl : TrustStub::DEFAULT_TTL_MINUTES;
	}

	/**
	 * 本次提交的信用身份哈希（02 §3.3a：游客=lower(email)|昵称；登录='u:'+user_id；
	 * HMAC-SHA16=前 16 位——与注入回调形态解耦的规范收口）。
	 *
	 * @param array<string, mixed> $commentdata 评论数据（user_id/作者字段）。
	 * @param string               $author     昵称（commentdata 同源值）。
	 * @param string               $email      邮箱。
	 * @return string
	 */
	private function trust_identity_hash( array $commentdata, string $author, string $email ): string {
		$user_id = isset( $commentdata['user_id'] ) && is_numeric( $commentdata['user_id'] ) ? (int) $commentdata['user_id'] : 0;
		$mac     = $this->trust_mac;
		$input   = ( $user_id > 0 )
			? TrustStub::user_identity_input( $user_id )
			: TrustStub::guest_identity_input( $email, $author );
		return substr( (string) $mac( $input ), 0, 16 );
	}

	/**
	 * 本次解析 IP 哈希（与事件表 ip_hash 同盐同源——HMAC-SHA256 前 16 位）。
	 *
	 * @param string $ip IpResolver 解析值。
	 * @return string
	 */
	private function trust_ip_hash( string $ip ): string {
		$mac = $this->trust_mac;
		return substr( (string) $mac( $ip ), 0, 16 );
	}

	private function context_id( array $commentdata, array $request ): string {
		return isset( $request[ self::CONTEXT_POST_FIELD ] ) && is_scalar( $request[ self::CONTEXT_POST_FIELD ] )
			? (string) $request[ self::CONTEXT_POST_FIELD ]
			: ( isset( $commentdata['comment_post_ID'] ) && is_scalar( $commentdata['comment_post_ID'] ) ? (string) $commentdata['comment_post_ID'] : '' );
	}

	/**
	 * @return int|string|null
	 */
	private function post_id( array $commentdata, array $request ) {
		foreach ( array( $commentdata, $request ) as $source ) {
			if ( isset( $source['comment_post_ID'] ) && is_scalar( $source['comment_post_ID'] ) ) {
				return (int) $source['comment_post_ID'];
			}
		}
		return null;
	}

	/**
	 * @param string $key review.local 子键。
	 * @return int
	 */
	private function local_setting( string $key ): int {
		return (int) ( $this->settings['review']['local'][ $key ] ?? 0 );
	}

	/**
	 * IP 哈希（wp_salt('auth') 派生 HMAC 前 16 位，04 §3——WP 边界；默认记录器闭包与
	 * Bootstrap 预检记录器共用——【v1.4·R2】提为 public）。
	 *
	 * @param string $ip
	 * @return string
	 */
	public static function ip_hash( string $ip ): string {
		if ( '' === $ip ) {
			return '';
		}
		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 16 );
	}
}
