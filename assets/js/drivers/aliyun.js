/**
 * 阿里云验证码 2.0 前端适配器（05 §1.2 V3 三步形态【v1.5·DESIGN-D2 定稿】；B3）。
 *
 * 官方三步接入（业务客户端零 SDK、纯 JS）：
 *   ① 全局配置（须在引入 JS 之前）：window.AliyunCaptchaConfig = {region, prefix}
 *      ——region 必须与服务端 endpoint 映射一致（错配即报错）【W3·批C·N2】region 从
 *      容器 data-wk-region 读实键值（PHP render 输出，缺省 cn；sgp 档静态推断 R7
 *      未实机验证），恒 cn 硬编码退役；
 *   ② 动态引入 JS：https://o.alicdn.com/captcha-frontend/aliyunCaptcha/AliyunCaptcha.js
 *      ——官方硬性红线：本地部署/静态拉取会导致对抗失效/误拦（与天御 TJCaptcha.js 同款）；
 *   ③ window.initAliyunCaptcha({SceneId, mode, element, button, success, fail, getInstance,
 *      slideStyle, language, onError, onClose})——官方要求 JS 加载/初始化与验证请求间隔
 *      须 >2s（本适配器在用户点击时才加载+init，天然满足时序）。
 *
 * token 契约：success(captchaVerifyParam) 回调的 captchaVerifyParam（V3 base64 串）即服务端
 * 验签参数，**禁止任何修改**——原文写隐藏域 wk_captcha_verify_param（tokenFields 单票据）
 * 并派发 wk-gate-token（gatekeeper.js 解除提交禁用）；服务端只认 VerifyIntelligentCaptcha
 * 核验——前端回调结果不构成通过凭据。
 *
 * 时效：CaptchaVerifyParam 行为验证→业务验签间隔 ≤90 秒（F019 码表）——token 90s 到期
 * 清写域、回退未验证态、派发 wk-gate-expire（gatekeeper.js 重新禁用提交）。
 *
 * 形态（04 §2 captcha.aliyun.mode，站长可配）：
 *   - popup（默认【W3·批C·N3 平移——出厂默认 embed→popup，存量显式值保留】）：页内
 *     无组件，SDK 绑定 button（本适配器的三态按钮）点击直接弹层（零占位）。
 *   - embed：页内渲染「点击开始验证」触发条进 .wk-gate-embed 挂载点内的
 *     #wk-captcha-element-<gate>（与天御同一锚点机制，提交按钮正下方）；点击触发条后
 *     仍弹出拼图/图像复原交互弹层（SDK 自管：fixed 居中+蒙层，插件侧无需也不可定位）。
 *   - 挂载点缺失（主题改写 DOM 等）→ 运行时降级 popup，不冻结。element/button 选择器
 *     必须真实存在否则 console 报错不渲染——初始化前自查容器存在（03 §2.1 挂载点缺失
 *     降级逻辑照用）。slideStyle 宽度恒 320（官方最小 320px，F4-5 通用条款特例值）。
 *
 * 加载器 = tencent.js 同款 FIX-γ 定稿模式（onload≠全局可用校验 / 8s 看门狗 / 失败可
 * 重试 / 无同步回调路径 / 陈旧事件守卫）。初始化错误码（IllegalUserTag/IllegalScene/
 * Forbidden.AccountAccessDenied/Throttling）经 onError 透出 → 按钮错误态（console 留痕）。
 */
( function () {
	'use strict';

	var SCRIPT_URL = 'https://o.alicdn.com/captcha-frontend/aliyunCaptcha/AliyunCaptcha.js';
	var LOAD_TIMEOUT_MS = 8000; // 03 §2.1 v1.3：8s 上限（与 tencent.js 同一加载器纪律）。
	var PARAM_TTL_MS = 90 * 1000; // F019：CaptchaVerifyParam 90 秒时效（05 §1.2 码表）。

	// 共享加载状态（同页多容器复用一次脚本加载）。
	// state: 'idle'（未加载/失败可重试）| 'loading'（加载中）| 'ready'（initAliyunCaptcha 全局可用）。
	var state = 'idle';
	var queue = []; // 等待收敛的回调对 {onReady, onFail}。
	var scriptEl = null;
	var watchdog = null;
	var idCounter = 0;

	var L10N = window.wkGateL10n || {};
	function text( key, fallback ) {
		return L10N[ key ] || fallback;
	}

	function ensureHiddenField( container, name ) {
		var field = container.querySelector( 'input[name="' + name + '"]' );
		if ( ! field ) {
			field = document.createElement( 'input' );
			field.type = 'hidden';
			field.name = name;
			container.appendChild( field );
		}
		return field;
	}

	function sdkReady() {
		return typeof window.initAliyunCaptcha === 'function';
	}

	function settle( ok, failKey, failText ) {
		if ( 'loading' !== state ) {
			return; // 已收敛（如看门狗先到、事件后到）。
		}
		var waiters = queue;
		queue = [];
		if ( watchdog ) {
			clearTimeout( watchdog );
			watchdog = null;
		}
		if ( scriptEl && scriptEl.parentNode ) {
			scriptEl.parentNode.removeChild( scriptEl );
		}
		scriptEl = null;

		if ( ok ) {
			state = 'ready';
			waiters.forEach( function ( item ) {
				item.onReady();
			} );
		} else {
			state = 'idle'; // 允许重试。
			waiters.forEach( function ( item ) {
				item.onFail( failKey, failText );
			} );
		}
	}

	/**
	 * 确保 AliyunCaptcha.js 就绪（FIX-γ：回调只经脚本事件/看门狗定时器异步触发；
	 * 全局配置①须在脚本注入前落位——本函数内先写 config 再建节点）。
	 * 【W3·批C·N2】region 参数入列（容器 data-wk-region 归一值——同页多容器同站设置，
	 * 共享加载一次即全局一致）。
	 */
	function ensureSdk( sceneId, prefix, region, onReady, onFail ) {
		if ( 'ready' === state ) {
			return; // 调用方已先行短路——防御性兜底，不回调。
		}
		queue.push( { onReady: onReady, onFail: onFail } );
		if ( 'loading' === state ) {
			return;
		}
		state = 'loading';

		// 三步①：全局配置先于 JS 引入（官方：引入之前或 <head> 最前）。
		// 【W3·批C·N2】region 从容器 data-wk-region 读实键值（PHP render 输出，闭集归一
		// 非法值回落 cn）——与服务端 ENDPOINT_MAP 双端联动；恒 cn 硬编码退役
		// （sgp 档静态推断 R7 未实机验证，复验不过则 sgp 档 disabled）。
		window.AliyunCaptchaConfig = {
			region: region,
			prefix: prefix
		};

		scriptEl = document.createElement( 'script' ); // 官方要求：动态创建 script 节点。
		scriptEl.src = SCRIPT_URL;
		scriptEl.async = true;

		// 陈旧事件守卫：上一轮超时移除的节点若迟到触发事件，不得误收敛本轮加载。
		var el = scriptEl;
		function staleEvent() {
			return 'loading' !== state || scriptEl !== el;
		}
		function onScriptLoad() {
			if ( staleEvent() ) {
				return;
			}
			if ( sdkReady() ) {
				settle( true );
			} else {
				settle( false, 'mismatch', 'The verification widget failed to load. Please refresh the page and try again.' );
			}
		}

		scriptEl.onload = onScriptLoad;
		scriptEl.onerror = function () {
			if ( staleEvent() ) {
				return;
			}
			settle( false, 'loadFail', 'Captcha failed to load — click to retry' );
		};
		scriptEl.onreadystatechange = function () {
			if ( 'loaded' === scriptEl.readyState || 'complete' === scriptEl.readyState ) {
				onScriptLoad();
			}
		};

		watchdog = setTimeout( function () {
			settle( false, 'loadTimeout', 'Weak network — please retry later or contact the site owner' );
		}, LOAD_TIMEOUT_MS );

		document.head.appendChild( scriptEl );
	}

	function initContainer( container ) {
		var sceneId = String( container.getAttribute( 'data-scene-id' ) || '' ).trim();
		var prefix = String( container.getAttribute( 'data-prefix' ) || '' ).trim();
		// 【W3·批C·N2】region 实键值（闭集归一：非 sgp 一律 cn——与服务端 endpoint_for_region 同口径）。
		var region = 'sgp' === container.getAttribute( 'data-wk-region' ) ? 'sgp' : 'cn';
		var mode = 'popup' === container.getAttribute( 'data-mode' ) ? 'popup' : 'embed';
		var button = container.querySelector( '.wk-captcha-btn' );
		var form = container.closest ? ( container.closest( 'form' ) || null ) : null;

		/* ---- 形态分派（embed=默认；popup=站长可配/挂载点缺失降级） ---- */
		var mount = null;
		var elementBox = null;
		if ( 'embed' === mode ) {
			var next = container.nextElementSibling;
			mount = ( next && next.classList && next.classList.contains( 'wk-gate-embed' ) )
				? next
				: ( form ? form.querySelector( '.wk-gate-embed' ) : null );
			elementBox = mount ? mount.querySelector( '[id^="wk-captcha-element-"]' ) : null;
			if ( ! mount || ! elementBox ) {
				mode = 'popup'; // 挂载点被主题改写/移除：降级弹层，不冻结。
			}
		}

		var inited = false; // initAliyunCaptcha 一次性（SDK 自持后续交互）。
		var done = false;
		var loading = false; // 防重入：脚本加载中的再次点击直接忽略。
		var paramTimer = null;
		var elementId = null;

		/* ---- 三态单按钮（tencent.js 同形态）：文案 + data-wk-state + disabled 全在按钮上 ----
		 * spec = [data-wk-state 属性值, L10N 键, 兜底文案]。 */
		var STATE_TEXT = {
			unverified: [ 'unverified', 'idle', 'Complete human verification' ],
			loading:    [ 'loading', 'loading', 'Loading captcha…' ],
			pending:    [ 'pending', 'pending', 'Verifying…' ],
			verified:   [ 'verified', 'done', 'Verification passed' ],
			retry:      [ 'retry', 'retry', 'Verification incomplete — click to retry' ],
			mismatch:   [ 'retry', 'mismatch', 'The verification widget failed to load. Please refresh the page and try again.' ],
			loadFail:   [ 'retry', 'loadFail', 'Captcha failed to load — click to retry' ],
			loadTimeout:[ 'retry', 'loadTimeout', 'Weak network — please retry later or contact the site owner' ],
			configError:[ 'retry', 'configError', 'Captcha is not configured. Please contact the site owner.' ],
			error:      [ 'retry', 'error', 'The verification widget failed to initialize — refresh to retry' ],
			expired:    [ 'unverified', 'expired', 'Verification expired — please verify again' ]
		};

		/* 【W2·⑦失败面播报】失败态键集——派发 wk-gate-verify-failed 供调度器 submit-gate
		 * Toast 播报（无按钮模式下按钮 hidden，按钮态文案不可见）；经典形态无监听=零影响。 */
		var FAILED_STATES = {
			retry: 1, mismatch: 1, loadFail: 1, loadTimeout: 1, configError: 1, error: 1
		};

		function setState( key ) {
			var spec = STATE_TEXT[ key ] || STATE_TEXT.unverified;
			if ( button ) {
				button.setAttribute( 'data-wk-state', spec[ 0 ] );
				button.disabled = 'verified' === key; // verified 态禁用；其余态可点/可重试。
				button.setAttribute( 'aria-disabled', 'verified' === key ? 'true' : 'false' );
				button.textContent = text( spec[ 1 ], spec[ 2 ] );
			}
			if ( FAILED_STATES[ key ] ) {
				// 【W2⑦】失败面统一播报事件（文案由调度器 submit-gate 给无按钮语境口径——
				// 「重试」=再点一次提交，适配器不自行措辞）。
				container.dispatchEvent( new CustomEvent( 'wk-gate-verify-failed', { bubbles: true } ) );
			}
		}

		function fire( name ) {
			// gatekeeper.js 监听（token 到域 → 解除提交禁用；过期 → 重新禁用）。
			container.dispatchEvent( new CustomEvent( name, { bubbles: true } ) );
		}

		function stopTimers() {
			if ( paramTimer ) {
				window.clearTimeout( paramTimer );
				paramTimer = null;
			}
		}

		function ensureElementSelector() {
			if ( elementId ) {
				return elementId;
			}
			if ( elementBox && elementBox.id ) {
				elementId = elementBox.id;
				return elementId;
			}
			// popup 形态：SDK 仍要求 element——容器内建隐藏 element 盒（唯一 id）。
			if ( ! elementBox ) {
				elementBox = document.createElement( 'div' );
				elementBox.style.display = 'none';
				container.appendChild( elementBox );
			}
			elementId = elementBox.id || ( 'wk-captcha-element-' + ( ++idCounter ) );
			elementBox.id = elementId;
			return elementId;
		}

		function invalidateToken() {
			// CaptchaVerifyParam 90 秒时效（F019）：清写域、回退未验证态、广播——提交按钮随之重新禁用。
			stopTimers();
			ensureHiddenField( container, 'wk_captcha_verify_param' ).value = '';
			done = false;
			setState( 'expired' );
			fire( 'wk-gate-expire' );
		}

		function onParam( captchaVerifyParam ) {
			// captchaVerifyParam 禁止任何修改——原文写隐藏域（05 §1.2 红线）。
			stopTimers();
			ensureHiddenField( container, 'wk_captcha_verify_param' ).value = captchaVerifyParam;
			done = true;
			setState( 'verified' );
			fire( 'wk-gate-token' );
			// F019：90 秒后参数失效——到期自动回退未验证态 + 重新禁用提交。
			paramTimer = window.setTimeout( function () {
				paramTimer = null;
				invalidateToken();
			}, PARAM_TTL_MS );
		}

		function initCaptcha() {
			if ( inited ) {
				return;
			}
			inited = true;
			var btnId = ( button && button.id ) ? button.id : 'wk-captcha-btn-aliyun-' + ( ++idCounter );
			if ( button && ! button.id ) {
				button.id = btnId;
			}
			/* 【v1.5·FIX】embed 挂载点展开（对齐 tencent.js:287 同款课程）：SubmitRow 输出的
			 * .wk-gate-embed 初始带 hidden——hidden 容器内 SDK 照常渲染（真机 v7 测试页实锤
			 * 5955 字符组件藏于其中）但用户不可见，视角即「验证码加载中」永恒卡住。B3 漏抄。 */
			if ( 'embed' === mode && mount ) {
				mount.hidden = false;
			}
			try {
				window.initAliyunCaptcha( {
					SceneId: sceneId,
					mode: mode,
					element: '#' + ensureElementSelector(),
					button: '#' + btnId,
					slideStyle: { width: 320, height: 40 }, // 官方最小 320px（05 §1.2）。
					language: 'cn',
					getInstance: function ( instance ) {
						/* 【M6·批H4·H4-1】popup 形态初始化不自动开层（SDK 等 button 下一次点击）
						 * ——而首次点击已被适配器 show() 消费于 SDK 加载+初始化，用户须再点
						 * 一次才弹层（手测二轮 F2 两步点击根因）。此处主动开层补足一步到位；
						 * embed 形态无层可开不适用。instance.show() 为静态推断 API——try 防御，
						 * 真机复验挂手测卡。 */
						if ( 'popup' === mode && instance && 'function' === typeof instance.show ) {
							try {
								instance.show();
							} catch ( e ) {
								/* 开层失败不阻塞：SDK 自管路径仍在（button 再点可弹）。 */
							}
						}
					},
					success: function ( captchaVerifyParam ) {
						onParam( captchaVerifyParam );
					},
					fail: function () {
						// 验证失败回调（SDK 自动刷新重试）——按钮回可重试态。
						if ( ! done ) {
							setState( 'retry' );
						}
					},
					onError: function () {
						// 初始化接口错误码（IllegalUserTag/IllegalScene/Forbidden.*）透出。
						setState( 'error' );
					},
					onClose: function ( reason ) {
						// reason=userDismiss|verifyComplete——用户主动关层且未过码回未验证态。
						if ( 'userDismiss' === reason && ! done ) {
							setState( 'unverified' );
							/* 【W2⑦】关层未过码=失败面（访客主动放弃）——submit-gate Toast 引导
							 * 重试（经典形态无监听=零影响）。 */
							container.dispatchEvent( new CustomEvent( 'wk-gate-verify-failed', { bubbles: true } ) );
						}
					}
				} );
				// 【v1.5·FIX】组件已渲染（embed=下方滑块 / popup=弹层）——按钮进 pending
				// 态（对齐 tencent.js:296；避免停留在「加载中」误导用户）。
				setState( 'pending' );
			} catch ( err ) {
				inited = false;
				setState( 'error' );
			}
		}

		function show() {
			if ( loading || done ) {
				return; // 防重入 / 已验证（verified 态按钮 disabled，防御性兜底）。
			}
			if ( ! sceneId || ! prefix ) {
				setState( 'configError' );
				return;
			}
			if ( sdkReady() ) {
				initCaptcha(); // 全局已就绪：直启（无加载链、无同步回调回路）。
				return;
			}

			loading = true;
			setState( 'loading' );
			ensureSdk(
				sceneId,
				prefix,
				region,
				function () {
					loading = false;
					initCaptcha();
				},
				function ( failKey ) {
					loading = false; // 按钮不锁死：用户可再点重试。
					setState( STATE_TEXT[ failKey ] ? failKey : 'retry' );
				}
			);
		}

		/* 【W2·requestVerify 契约（W-3）】无按钮模式验证拉起单入口：popup=show() 复用
		 * （H4-1 一步开层机制；SDK 加载/防重入/configError 守卫全在 show() 内）；embed=
		 * Q1 方案 A 页面加载即渲染（boot 期已 initCaptcha，触发条常驻表单内），此处补
		 * 挂载点滚动引导 + show()（boot 加载中/已完成时防重入静默返回）。 */
		function requestVerify() {
			if ( 'embed' === mode && mount && mount.scrollIntoView ) {
				try { mount.scrollIntoView( { block: 'nearest', behavior: 'smooth' } ); } catch ( err ) {}
			}
			show();
		}
		container.wkCaptchaRequestVerify = requestVerify;

		setState( 'unverified' );

		if ( button ) {
			button.addEventListener( 'click', function () {
				show();
			} );
		}

		/* 【W2·Q1=方案 A】无按钮模式 embed：页面加载即渲染——boot 期即拉 SDK+initCaptcha
		 * （mount.hidden=false 照常——触发条常驻表单内，访客可先滑后提：token 在场=提交
		 * 直通②，未过码提交由 submit-gate 拦截引导⑤）。popup 不 boot 预载——提交拦截时
		 * requestVerify(=show()) 一步开层（H4-1 复用）。 */
		if ( container.getAttribute( 'data-submit-gate' ) === '1' && 'embed' === mode ) {
			requestVerify();
		}

		// 页面卸载清计时器（防计时器悬挂跨页）。
		window.addEventListener( 'pagehide', stopTimers );
	}

	// 适配器注册：frontend 元数据自声明（调度器据此分派——不认驱动 id）。
	var registry = window.wkGateDrivers || ( window.wkGateDrivers = {} );
	registry.aliyun = {
		interaction: 'click',
		tokenFields: [ 'wk_captcha_verify_param' ],
		init: initContainer,
		/* 【W2·无按钮模式契约（W-3）】requestVerify(container)：验证拉起单方法——调度器
		 * submit-gate 拦截后调用（闸级拦截/编排全在调度器，适配器只负责拉起）。
		 * 容器未装配（init 未跑/抛错）→ 抛错交由调度器 startVerification 降级回经典形态。 */
		requestVerify: function ( target ) {
			var verify = target && target.wkCaptchaRequestVerify;
			if ( typeof verify !== 'function' ) {
				throw new Error( 'wk-gate: driver adapter not armed on this container' );
			}
			verify();
		}
	};
	window.dispatchEvent( new Event( 'wk-gate-driver-registered' ) );
} )();
