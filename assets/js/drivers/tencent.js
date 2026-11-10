/**
 * 腾讯天御 2.0 前端适配器（05 §1.1；click 形态）。
 *
 * 官方硬性要求：TJCaptcha.js 必须动态引入（静态引入致对抗失效/误拦）——本适配器
 * 在用户首次点击按钮时动态创建 script 节点加载 https://turing.captcha.qcloud.com/TJCaptcha.js，
 * 回调 {ret, ticket, randstr} → 写隐藏域 wk_captcha_ticket / wk_captcha_randstr
 * （token_fields 声明的两个写域，03 §2.1）。
 *
 * 【M2-FIX2 D1 v1.4 形态度（2026-11-10 用户反馈①②）】嵌入式 + 三态单按钮：
 *   - data-mode="embed"（评论域渲染默认）：官方「嵌入式验证码」形态——挑战面板渲染
 *     进提交按钮下方的 .wk-gate-embed 挂载点（构造签名 new TencentCaptcha(容器,
 *     appId, cb, {type:'embed'})，尺寸 300×230），show 时 scrollIntoView 就近可见。
 *     旧弹层形态 fixed 页顶，用户在页底评论表单点击后看不到弹层（D1 真相）。
 *     挂载点缺失（主题改写 DOM 等）→ 运行时降级弹层，不冻结。
 *   - data-mode="popup"（登录域/降级）：弹层形态 new TencentCaptcha(appId, cb)。
 *   - 三态单按钮：unverified「完成人机验证」→ loading/pending「验证中…」→ verified
 *     「已通过验证」（disabled）；失败/超时/过期等文案也由按钮承载（aria-live=polite
 *     上按钮），不再有独立状态字/徽标（用户反馈②）。
 *
 * 加载器 = FIX-γ 定稿模式（onload≠全局可用校验 / 8s 看门狗 / 失败可重试 / 无同步
 * 回调路径 / 陈旧事件守卫）。另增 show 看门狗（20s）：「验证中」状态必须有超时出口
 * ——回调永不落地（弹层被拦截/嵌入面板加载吊死）时按钮回到可重试态，不锁死。
 *
 * 天御票据 5 分钟有效：到期清 token 写域、回退未验证态、派发 wk-gate-expire
 * （gatekeeper.js 据此重新禁用提交）。verified 态按钮 disabled（用户反馈②指定），
 * 重做通道 = 票据 5min 过期自动回退。
 * 服务端只认 DescribeCaptchaResult 核验——前端回调结果不构成通过凭据。
 */
( function () {
	'use strict';

	var SCRIPT_URL = 'https://turing.captcha.qcloud.com/TJCaptcha.js';
	var LOAD_TIMEOUT_MS = 8000; // 03 §2.1 v1.3：参照 makotowu ensureCaptchaSdk 的 8s 上限。
	var SHOW_TIMEOUT_MS = 20000; // M2-FIX2：show 后回调 20s 未落地 → 回退可重试（「验证中」超时出口）。
	var TICKET_TTL_MS = 5 * 60 * 1000; // 天御票据 5 分钟有效（05 §1.1 码表 CaptchaCode=8）。


	// 共享加载状态（同页多容器复用一次脚本加载）。
	// state: 'idle'（未加载/失败可重试）| 'loading'（加载中）| 'ready'（已验证全局在）。
	var state = 'idle';
	var queue = []; // 等待收敛的回调对 {onReady, onFail}。
	var scriptEl = null;
	var watchdog = null;

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
		return typeof window.TencentCaptcha === 'function';
	}

	/**
	 * 收敛一次加载尝试（成功/失败只收敛一次）。
	 */
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
	 * 确保 TJCaptcha.js 就绪（FIX-γ：回调只经脚本事件/看门狗定时器异步触发）。
	 */
	function ensureSdk( onReady, onFail ) {
		if ( 'ready' === state ) {
			return; // 调用方已先行短路——防御性兜底，不回调。
		}
		queue.push( { onReady: onReady, onFail: onFail } );
		if ( 'loading' === state ) {
			return;
		}
		state = 'loading';

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
		// appId 以字符串传入（官方插件同口径）；空/非数字串 → 配置错误，不进入加载链。
		var appIdRaw = String( container.getAttribute( 'data-app-id' ) || '' ).trim();
		var appId = /^\d+$/.test( appIdRaw ) ? appIdRaw : '';
		var button = container.querySelector( '.wk-captcha-btn' );
		var form = container.closest ? ( container.closest( 'form' ) || null ) : null;

		/* ---- M2-FIX2 D1：形态分派（embed=评论域默认；popup=登录域/挂载点缺失降级） ---- */
		var mode = 'embed' === container.getAttribute( 'data-mode' ) ? 'embed' : 'popup';
		var mount = null;
		if ( 'embed' === mode ) {
			var next = container.nextElementSibling;
			mount = ( next && next.classList && next.classList.contains( 'wk-gate-embed' ) )
				? next
				: ( form ? form.querySelector( '.wk-gate-embed' ) : null );
			if ( ! mount ) {
				mode = 'popup'; // 挂载点被主题改写/移除：降级弹层，不冻结。
			}
		}

		var captcha = null;
		var showing = false; // 防重入：加载中/面板中/构造中的再次点击直接忽略。
		var done = false;
		var expireTimer = null;
		var showTimer = null;

		/* ---- 三态单按钮（用户反馈②）：文案 + data-wk-state + disabled 全在按钮上 ----
		 * spec = [data-wk-state 属性值, L10N 键, 兜底文案]。 */
		var STATE_TEXT = {
			unverified: [ 'unverified', 'idle', '完成人机验证' ],
			loading:    [ 'loading', 'loading', '验证码加载中……' ],
			pending:    [ 'pending', 'pending', '验证中……' ],
			verified:   [ 'verified', 'done', '已通过验证' ],
			retry:      [ 'retry', 'retry', '验证未完成，点击重试' ],
			mismatch:   [ 'retry', 'mismatch', '验证组件加载异常，请刷新页面重试。' ],
			loadFail:   [ 'retry', 'loadFail', '验证码加载失败，点击重试' ],
			loadTimeout:[ 'retry', 'loadTimeout', '网络不佳，点击重试' ],
			showTimeout:[ 'retry', 'loadTimeout', '验证超时，请点击重试' ],
			configError:[ 'retry', 'configError', '验证码配置缺失，请联系站长。' ],
			error:      [ 'retry', 'error', '验证组件初始化失败，请刷新重试' ],
			expired:    [ 'unverified', 'expired', '验证已过期，请重新验证' ]
		};

		function setState( key ) {
			var spec = STATE_TEXT[ key ] || STATE_TEXT.unverified;
			if ( button ) {
				button.setAttribute( 'data-wk-state', spec[ 0 ] );
				button.disabled = 'verified' === key; // verified 态禁用（用户反馈②）；其余态可点/可重试。
				button.setAttribute( 'aria-disabled', 'verified' === key ? 'true' : 'false' );
				button.textContent = text( spec[ 1 ], spec[ 2 ] );
			}
		}

		function fire( name ) {
			// gatekeeper.js 监听（token 到域 → 解除提交禁用；过期 → 重新禁用）。
			container.dispatchEvent( new CustomEvent( name, { bubbles: true } ) );
		}

		function stopTimers() {
			if ( expireTimer ) {
				window.clearTimeout( expireTimer );
				expireTimer = null;
			}
			if ( showTimer ) {
				window.clearTimeout( showTimer );
				showTimer = null;
			}
		}

		function hideMount() {
			if ( mount ) {
				mount.hidden = true;
			}
		}

		function destroyCaptcha() {
			if ( captcha ) {
				try { captcha.destroy(); } catch ( err ) {} // 官方实例方法：隐藏验证码，可反复调用。
				captcha = null;
			}
			hideMount();
		}

		function invalidateToken() {
			// 票据作废（过期）：清写域、回退未验证态、广播——提交按钮随之重新禁用。
			stopTimers();
			destroyCaptcha();
			var ticket = container.querySelector( 'input[name="wk_captcha_ticket"]' );
			var randstr = container.querySelector( 'input[name="wk_captcha_randstr"]' );
			if ( ticket ) { ticket.value = ''; }
			if ( randstr ) { randstr.value = ''; }
			done = false;
			setState( 'expired' );
			fire( 'wk-gate-expire' );
		}

		function armShowTimeout() {
			showTimer = window.setTimeout( function () {
				showTimer = null;
				showing = false;
				destroyCaptcha();
				setState( 'showTimeout' ); // 「验证中」超时出口（M2-FIX2）：不锁死、可重试。
			}, SHOW_TIMEOUT_MS );
		}

		function handleResult( result ) {
			showing = false;
			if ( showTimer ) {
				window.clearTimeout( showTimer );
				showTimer = null;
			}
			// 官方回调结构：{ret: 0|2, ticket, randstr, errorCode, errorMessage}。
			// ret=0 + ticket + randstr（含 errorCode 容灾票 trerror_*——服务端 DescribeCaptchaResult
			// 按 CaptchaCode=21/trerror 语义核，前端不判）→ 通过。
			if ( result && result.ret === 0 && result.ticket && result.randstr ) {
				ensureHiddenField( container, 'wk_captcha_ticket' ).value = result.ticket;
				ensureHiddenField( container, 'wk_captcha_randstr' ).value = result.randstr;
				done = true;
				destroyCaptcha(); // 面板收起：反馈由按钮「已通过验证」承载。
				setState( 'verified' );
				fire( 'wk-gate-token' );
				// 天御票据 5 分钟有效（05 §1.1）：到期自动回退未验证态 + 重新禁用提交。
				expireTimer = window.setTimeout( function () {
					expireTimer = null;
					invalidateToken();
				}, TICKET_TTL_MS );
			} else {
				// 取消（ret=2）/异常（errorCode 且无票）：收起面板、回退可重试态。
				done = false;
				destroyCaptcha();
				setState( 'retry' );
			}
		}

		function launch() {
			try {
				if ( ! captcha ) {
					if ( 'embed' === mode ) {
						mount.hidden = false; // 挂载点展开（hidden 属性移除后 SDK 注入 iframe）。
						captcha = new window.TencentCaptcha( mount, appId, handleResult, { type: 'embed' } ); // eslint-disable-line no-undef
					} else {
						captcha = new window.TencentCaptcha( appId, handleResult ); // eslint-disable-line no-undef
					}
				} else if ( 'embed' === mode ) {
					mount.hidden = false; // 复用实例重 show。
				}
				captcha.show();
				setState( 'pending' );
				if ( 'embed' === mode && mount.scrollIntoView ) {
					try { mount.scrollIntoView( { block: 'nearest', behavior: 'smooth' } ); } catch ( err ) {}
				}
				armShowTimeout();
			} catch ( err ) {
				// 构造/唤起抛错：置错可重试，收起挂载点。
				captcha = null;
				hideMount();
				setState( 'error' );
			}
			showing = false;
		}

		function show() {
			if ( showing || done ) {
				return; // 防重入 / 已验证（verified 态按钮 disabled，防御性兜底）。
			}
			if ( ! appId ) {
				setState( 'configError' );
				return;
			}
			if ( sdkReady() ) {
				showing = true;
				launch(); // 全局已验证存在：直启（无加载链、无同步回调回路）。
				return;
			}

			showing = true;
			setState( 'loading' );
			ensureSdk(
				function () {
					launch(); // 收敛回调直接拉起——不再重入 show()。
				},
				function ( failKey ) {
					showing = false; // 按钮不锁死：用户可再点重试。
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

	// 适配器注册：frontend 元数据自声明（调度器据此分派）。
	var registry = window.wkGateDrivers || ( window.wkGateDrivers = {} );
	registry.tencent = {
		interaction: 'click',
		tokenFields: [ 'wk_captcha_ticket', 'wk_captcha_randstr' ],
		init: initContainer
	};
	window.dispatchEvent( new Event( 'wk-gate-driver-registered' ) );
} )();
