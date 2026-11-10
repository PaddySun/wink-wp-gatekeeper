/**
 * 阿里云验证码 2.0 前端适配器（05 §1.2 V3 三步形态【v1.5·DESIGN-D2 定稿】；B3）。
 *
 * 官方三步接入（业务客户端零 SDK、纯 JS）：
 *   ① 全局配置（须在引入 JS 之前）：window.AliyunCaptchaConfig = {region:'cn', prefix}
 *      ——region 必须与服务端 endpoint 映射一致（恒 cn，sgp 变体不启用），错配即报错；
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
 *   - embed（默认）：页内渲染「点击开始验证」触发条进 .wk-gate-embed 挂载点内的
 *     #wk-captcha-element-<gate>（与天御同一锚点机制，提交按钮正下方）；点击触发条后
 *     仍弹出拼图/图像复原交互弹层（SDK 自管：fixed 居中+蒙层，插件侧无需也不可定位）。
 *   - popup：页内无组件，SDK 绑定 button（本适配器的三态按钮）点击直接弹层（零占位）。
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
	 */
	function ensureSdk( sceneId, prefix, onReady, onFail ) {
		if ( 'ready' === state ) {
			return; // 调用方已先行短路——防御性兜底，不回调。
		}
		queue.push( { onReady: onReady, onFail: onFail } );
		if ( 'loading' === state ) {
			return;
		}
		state = 'loading';

		// 三步①：全局配置先于 JS 引入（官方：引入之前或 <head> 最前）。
		window.AliyunCaptchaConfig = {
			region: 'cn', // region 恒 cn（服务端 endpoint captcha.cn-shanghai 映射一致）。
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
				settle( false, 'mismatch', '验证组件加载异常，请刷新页面重试。' );
			}
		}

		scriptEl.onload = onScriptLoad;
		scriptEl.onerror = function () {
			if ( staleEvent() ) {
				return;
			}
			settle( false, 'loadFail', '验证码加载失败——请刷新页面重试。' );
		};
		scriptEl.onreadystatechange = function () {
			if ( 'loaded' === scriptEl.readyState || 'complete' === scriptEl.readyState ) {
				onScriptLoad();
			}
		};

		watchdog = setTimeout( function () {
			settle( false, 'loadTimeout', '网络不佳，请稍后重试或联系站长' );
		}, LOAD_TIMEOUT_MS );

		document.head.appendChild( scriptEl );
	}

	function initContainer( container ) {
		var sceneId = String( container.getAttribute( 'data-scene-id' ) || '' ).trim();
		var prefix = String( container.getAttribute( 'data-prefix' ) || '' ).trim();
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
			unverified: [ 'unverified', 'idle', '完成人机验证' ],
			loading:    [ 'loading', 'loading', '验证码加载中……' ],
			verified:   [ 'verified', 'done', '已通过验证' ],
			retry:      [ 'retry', 'retry', '验证未完成，点击重试' ],
			mismatch:   [ 'retry', 'mismatch', '验证组件加载异常，请刷新页面重试。' ],
			loadFail:   [ 'retry', 'loadFail', '验证码加载失败，点击重试' ],
			loadTimeout:[ 'retry', 'loadTimeout', '网络不佳，点击重试' ],
			configError:[ 'retry', 'configError', '验证码配置缺失，请联系站长。' ],
			error:      [ 'retry', 'error', '验证组件初始化失败，请刷新重试' ],
			expired:    [ 'unverified', 'expired', '验证已过期，请重新验证' ]
		};

		function setState( key ) {
			var spec = STATE_TEXT[ key ] || STATE_TEXT.unverified;
			if ( button ) {
				button.setAttribute( 'data-wk-state', spec[ 0 ] );
				button.disabled = 'verified' === key; // verified 态禁用；其余态可点/可重试。
				button.setAttribute( 'aria-disabled', 'verified' === key ? 'true' : 'false' );
				button.textContent = text( spec[ 1 ], spec[ 2 ] );
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
			try {
				window.initAliyunCaptcha( {
					SceneId: sceneId,
					mode: mode,
					element: '#' + ensureElementSelector(),
					button: '#' + btnId,
					slideStyle: { width: 320, height: 40 }, // 官方最小 320px（05 §1.2）。
					language: 'cn',
					getInstance: function ( instance ) {
						// captcha.show()/hide() 编程控制预留（当前流程 SDK 自管）。
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
						}
					}
				} );
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

		setState( 'unverified' );

		if ( button ) {
			button.addEventListener( 'click', function () {
				show();
			} );
		}

		// 页面卸载清计时器（防计时器悬挂跨页）。
		window.addEventListener( 'pagehide', stopTimers );
	}

	// 适配器注册：frontend 元数据自声明（调度器据此分派——不认驱动 id）。
	var registry = window.wkGateDrivers || ( window.wkGateDrivers = {} );
	registry.aliyun = {
		interaction: 'click',
		tokenFields: [ 'wk_captcha_verify_param' ],
		init: initContainer
	};
	window.dispatchEvent( new Event( 'wk-gate-driver-registered' ) );
} )();
