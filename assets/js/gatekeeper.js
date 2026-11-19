/**
 * wink-wp-gatekeeper 前台调度器（03 §2.1，v1.2 frontend 元数据分派 + v1.3 渐进增强）。
 *
 * 职责：扫描 .wk-gate 容器 → 按 data-driver 在适配器注册表（window.wkGateDrivers）查找
 * 适配器 → 校验 frontend 元数据（data-interaction 与适配器声明一致才分派）→ 交给适配器
 * 自行初始化。调度器不认驱动 id（M4 的 hCaptcha 嵌入式/Turnstile 无感式由各自适配器承载），
 * 也不实现任何具体验证码逻辑。
 *
 * 【C1 · 未验证禁用提交（03 §2.1 v1.3 渐进增强）】适配器 init 成功后对本表单提交控件
 * 置 disabled + aria-disabled；适配器向容器派发 wk-gate-token（token 写域非空）→ 解除；
 * wk-gate-expire（票据过期/作废）→ 重新禁用。次序保证：无 JS / JS 加载失败 / init 抛错 =
 * 不禁用（一律还原可点，等价无 JS 场景）——服务端 verify 永远兜底，禁用只是 UX 前移。
 *
 * 适配器契约（各驱动 JS 声明）：
 *   window.wkGateDrivers['<driver-id>'] = {
 *     interaction:  'click' | 'inline' | 'invisible',   // 与容器 data-interaction 双向核对
 *     tokenFields:  ['wk_captcha_ticket', 'wk_captcha_randstr'],
 *     init: function (container) { ... }                // 一次性初始化（含按钮绑定/动态脚本加载）
 *   };
 * 适配器生命周期事件（容器上派发、bubbles）：
 *   wk-gate-token  = token 已写入域（解除提交禁用）
 *   wk-gate-expire = token 已作废/过期（重新禁用提交）
 */
( function () {
	'use strict';

	var registry = window.wkGateDrivers || ( window.wkGateDrivers = {} );

	function byId( id ) {
		return Object.prototype.hasOwnProperty.call( registry, id ) ? registry[ id ] : null;
	}

	/* ---------- 未验证禁用提交（渐进增强）+ 禁用反馈（M2-FIX2 D3） ---------- */

	var HINT_TEXT = ( window.wkGateL10n && window.wkGateL10n.hint ) || 'Please complete human verification first';
	var HINT_MS = 2500; // 禁用提交点击提示的驻留时长。

	function ownedForm( container ) {
		return container.closest ? ( container.closest( 'form' ) || null ) : null;
	}

	function submitControls( form ) {
		if ( ! form || ! form.querySelectorAll ) {
			return [];
		}
		return Array.prototype.slice.call( form.querySelectorAll( 'input[type="submit"], button[type="submit"]' ) );
	}

	/**
	 * 禁用提交的反馈（M2-FIX2 D3）：验证按钮滚动到位 + 文案临时切换提示（aria-live 同步
	 * 播报）。按钮文字 2.5s 后还原（仅当仍是提示文案——不覆盖适配器正在展示的状态文案）。
	 */
	function flashHint( form ) {
		var gates = form.querySelectorAll( '.wk-gate[data-wk-armed="1"]:not([data-wk-token="1"])' );
		var container = gates.length > 0 ? gates[ 0 ] : form.querySelector( '.wk-gate[data-wk-armed="1"]' );
		if ( ! container ) {
			return;
		}
		var btn = container.querySelector( '.wk-captcha-btn' );
		if ( ! btn ) {
			return;
		}
		try { btn.scrollIntoView( { block: 'nearest', behavior: 'smooth' } ); } catch ( err ) {}
		try { btn.focus(); } catch ( err ) {} // 键盘可达：焦点移到验证按钮。
		if ( btn.textContent === HINT_TEXT ) {
			return; // 提示已在展示（连点去抖）。
		}
		var previous = btn.textContent;
		btn.textContent = HINT_TEXT;
		window.setTimeout( function () {
			if ( btn.textContent === HINT_TEXT ) {
				btn.textContent = previous;
			}
		}, HINT_MS );
	}

	/**
	 * 禁用控件包裹捕获（M2-FIX2 D3）：禁用的表单控件不派发 click（连祖先捕获都收不到），
	 * 标准 workaround = 用透明 span 包裹、在包裹层收 click → 提示。包裹幂等（已包裹跳过），
	 * 解禁后保留包裹（无视觉影响，监听器按控件实时 data 标志判定）。
	 */
	function wrapForCapture( form, control ) {
		if ( ! control.parentNode || ( control.parentNode.classList && control.parentNode.classList.contains( 'wk-gate-lock' ) ) ) {
			return; // 已包裹。
		}
		var wrap = document.createElement( 'span' );
		wrap.className = 'wk-gate-lock';
		wrap.setAttribute( 'data-wk-active', '1' ); // 禁用期间：not-allowed 光标态（CSS）。
		control.parentNode.insertBefore( wrap, control );
		wrap.appendChild( control );
		wrap.addEventListener( 'click', function () {
			if ( control.getAttribute( 'data-wk-gate-disabled' ) === '1' ) {
				flashHint( form );
			}
		} );
	}

	/** 键盘路径：禁用提交按钮后回车隐式提交也被浏览器吞——表单捕获 keydown 拦截提示。 */
	function guardEnterKey( form ) {
		if ( form.dataset.wkHintEnterBound === '1' ) {
			return;
		}
		form.dataset.wkHintEnterBound = '1';
		form.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' !== event.key || event.defaultPrevented ) {
				return;
			}
			var target = event.target;
			if ( ! target || 'INPUT' !== target.tagName ) {
				return; // textarea 换行 / 按钮自身激活不拦。
			}
			var type = ( target.getAttribute( 'type' ) || 'text' ).toLowerCase();
			if ( 'button' === type || 'submit' === type || 'reset' === type || 'file' === type ) {
				return;
			}
			var armed = form.querySelectorAll( '.wk-gate[data-wk-armed="1"]' );
			var pending = form.querySelectorAll( '.wk-gate[data-wk-armed="1"]:not([data-wk-token="1"])' );
			if ( armed.length > 0 && pending.length > 0 ) {
				event.preventDefault(); // 隐式提交已被禁用按钮挡下——补提示路径。
				flashHint( form );
			}
		}, true );
	}

	function armContainer( container ) {
		// init 成功才武装（=禁用提交）；失败路径从不调用本函数——按钮天然可点（无 JS 等价）。
		var form = ownedForm( container );
		if ( ! form ) {
			// 【W2·无按钮模式降级】无 form 容器（怪主题）：submit 拦截链无法装配——移除
			// 按钮 hidden 回退经典形态（运行时降级不冻结；降级矩阵末行，R6）。
			if ( isSubmitGate( container ) ) {
				restoreClassicButton( container );
			}
			return;
		}
		container.setAttribute( 'data-wk-armed', '1' );
		container.removeAttribute( 'data-wk-token' );
		armPrecheckGate( container, form );
		if ( isSubmitGate( container ) ) {
			// 【W2·R3】无按钮分支：提交按钮恒可点是本模式存在前提——guardEnterKey/
			// refreshLocks（含 wrapForCapture/flashHint 禁用四件）整体停用（代码保留服务
			// 经典形态）；防护语义由 submit 拦截链承载（未过码提交根本发不出）。
			armSubmitGate( container, form );
			return;
		}
		guardEnterKey( form );
		refreshLocks( form );
	}
	/* ---------- 【v1.4·R2】预检门（03 §2.1 信用免验预检门；适配器零改动契约） ----------
	 *
	 * 容器 data-wk-trust="1" 且 window.wkGatePrecheck 在场时启用（缺失/功能关=零行为变化）。
	 * 表单捕获阶段拦截验证按钮 click：按钮 →「检查中…」＋ POST wk_gate_precheck
	 * （nonce+wk_stub+昵称/邮箱/正文实时草稿；fetch 超时 fail-safe；点击期零外呼）。
	 * 三分支：
	 *   trust_pass      → 写 wk_trust 隐藏域 + 按钮「已通过验证」+ 派发 wk-gate-token（解除
	 *                     提交禁用）+ 前端 TTL 计时器到点清凭证回退（对齐票据过期回退模式）；
	 *   four_gate_fail  → 按钮还原 + 内联错误条 .wk-gate-msg（工坊文案，草稿保留框内；
	 *                     验证码不出；输入即清条）；
	 *   captcha_needed / error / 超时 → 放行本次点击给适配器 bubble 处理器（bypass 标记
	 *                     + 程序化补点——预检基础设施任何故障只退化为「多验一次码」）。
	 * 预检是闸级能力（不是驱动级）——M4 新驱动自动继承；调度器仍不认驱动 id。 */

	function precheckConfig() {
		return window.wkGatePrecheck || null;
	}

	function gateButton( container ) {
		return container ? container.querySelector( '.wk-captcha-btn' ) : null;
	}

	/** 三态单按钮直写（tencent.js 的 setState 同形态——预检门先行期间适配器尚未接手）。 */
	function setGateButton( container, stateKey, text ) {
		var button = gateButton( container );
		if ( ! button ) {
			return;
		}
		button.setAttribute( 'data-wk-state', stateKey );
		button.disabled = 'verified' === stateKey;
		button.setAttribute( 'aria-disabled', 'verified' === stateKey ? 'true' : 'false' );
		button.textContent = text;
	}

	function l10n( key, fallback ) {
		return ( window.wkGateL10n && window.wkGateL10n[ key ] ) || fallback;
	}

	/** 内联错误条（.wk-gate-msg：按钮正下、与 .wk-gate-embed 同位不同元素互斥显示）。 */
	function ensureMsgBar( container ) {
		var msg = container.querySelector( '.wk-gate-msg' );
		if ( ! msg ) {
			msg = document.createElement( 'div' );
			msg.className = 'wk-gate-msg';
			msg.setAttribute( 'role', 'alert' );
			msg.hidden = true;
			var embed = container.nextElementSibling;
			if ( embed && embed.classList && embed.classList.contains( 'wk-gate-embed' ) ) {
				embed.parentNode.insertBefore( msg, embed );
			} else {
				container.parentNode.insertBefore( msg, container.nextSibling );
			}
		}
		return msg;
	}

	function showMsgBar( container, text ) {
		cancelMsgClear( container ); // 新错误展示时取消未决防抖清除（不被旧计时器吃掉）。
		var msg = ensureMsgBar( container );
		msg.textContent = text; // 服务端文案经 textContent 注入（不 innerHTML）。
		msg.hidden = false;
	}

	function hideMsgBar( container ) {
		var msg = container.querySelector( '.wk-gate-msg' );
		if ( msg ) {
			msg.hidden = true;
			msg.textContent = '';
		}
	}

	/* 【M6·批H5·H5-a】Toast 气泡（0.8.3 形态）：body 级单例——已存在即只更新文案
	 * （双容器幂等）；textContent 注入（XSS 红线）；fixed 顶部居中淡入（样式在
	 * front-gate.css .wk-gate-toast）；页面跳转自然带走，无清除逻辑。 */
	function showToast( text ) {
		var toast = document.getElementById( 'wk-gate-toast' );
		if ( ! toast ) {
			toast = document.createElement( 'div' );
			toast.id = 'wk-gate-toast';
			toast.className = 'wk-gate-toast';
			toast.setAttribute( 'role', 'status' );
			toast.setAttribute( 'aria-live', 'polite' );
			( document.body || document.documentElement ).appendChild( toast );
		}
		toast.textContent = text;
		toast.classList.add( 'wk-gate-toast--show' );
	}

	/* 【v1.5·F4-2】内联错误条清除调度（03 §2.1 v1.5 三清除时机——互不替代、全部实施）：
	 *   ① 重新预检通过时清除（onTrustPass / releaseToAdapter 两成功分支）；
	 *   ② 评论框/昵称/邮箱 input 事件防抖 MSG_CLEAR_MS=300ms 清除（内容变了旧错误无意义）；
	 *   ③ TrustStub 前端 TTL 过期回退同拍清除（onTrustPass 计时器回调）。
	 * 防抖纯逻辑集中于此：未决计时器先取消（300ms 内连续输入只保留最后一次到点清除）。 */
	var MSG_CLEAR_MS = 300;

	function scheduleMsgClear( container ) {
		if ( container.wkMsgClearTimer ) {
			window.clearTimeout( container.wkMsgClearTimer );
		}
		container.wkMsgClearTimer = window.setTimeout( function () {
			container.wkMsgClearTimer = null;
			hideMsgBar( container );
		}, MSG_CLEAR_MS );
	}

	function cancelMsgClear( container ) {
		if ( container.wkMsgClearTimer ) {
			window.clearTimeout( container.wkMsgClearTimer );
			container.wkMsgClearTimer = null;
		}
	}

	/** 表单实时草稿取值（即取即弃——预检不落草稿不进 session）。 */
	function collectDraft( form, container ) {
		function valueOf( selectors ) {
			for ( var i = 0; i < selectors.length; i++ ) {
				var el = form.querySelector( selectors[ i ] );
				if ( el && el.value !== undefined ) {
					return el.value;
				}
			}
			return '';
		}
		var stub = container.querySelector( 'input[name="wk_stub"]' );
		return {
			stub: stub ? stub.value : '',
			postId: valueOf( [ 'input[name="comment_post_ID"]', 'input[name="post_ID"]' ] ),
			author: valueOf( [ '#author', 'input[name="author"]' ] ),
			email: valueOf( [ '#email', 'input[name="email"]' ] ),
			content: valueOf( [ '#comment', 'textarea[name="comment"]' ] )
		};
	}

	function armPrecheckGate( container, form ) {
		if ( container.getAttribute( 'data-wk-trust' ) !== '1' || ! precheckConfig() ) {
			return; // 预检门不适用：零行为变化（适配器独占按钮）。
		}
		if ( form.dataset.wkPrecheckBound === '1' ) {
			return; // 单表单一次绑定（多容器防御：回复嵌套表单）。
		}
		form.dataset.wkPrecheckBound = '1';

		// 【v1.5·F4-2②】内容变更即防抖清错误条（300ms——旧错误对新草稿无意义）。
		form.addEventListener( 'input', function ( event ) {
			var target = event.target;
			if ( target && ( 'TEXTAREA' === target.tagName || 'INPUT' === target.tagName ) ) {
				clearNativeGateHint( form ); // 【G2 清除①】内容变了原生气泡即解禁（先于防抖——气泡即时消失）。
				scheduleMsgClear( container );
			}
		} );

		// 捕获阶段拦截（表单层 capture 先于按钮目标相位的一切监听——含适配器 click 绑定）。
		form.addEventListener( 'click', function ( event ) {
			if ( form.getAttribute( 'data-wk-precheck-bypass' ) === '1' ) {
				form.removeAttribute( 'data-wk-precheck-bypass' ); // 放行本次点击（预检降级/不适用）。
				return;
			}
			var target = event.target;
			var button = gateButton( container );
			if ( ! button || ! target || ! ( target === button || button.contains( target ) ) ) {
				return; // 非本容器验证按钮的点击：不管。
			}
			if ( container.getAttribute( 'data-wk-checking' ) === '1'
				|| container.getAttribute( 'data-wk-token' ) === '1' ) {
				event.preventDefault();
				event.stopPropagation(); // checking 可等待不可再点；已验证按钮 disabled 的防御性兜底。
				return;
			}
			event.preventDefault();
			event.stopPropagation();
			if ( ! nativeValidityGuard( form ) ) {
				// 【G1】有未填必填字段：浏览器已弹原生提示框——预检请求不发（四闸管内容，原生管完整性）。
				return;
			}
			runPrecheck( container, form, button );
		}, true );
	}

	/* 【M4UX·G2】本地内容四闸预检失败 → 评论框原生气泡（用户需求：「评论全英文点完成
	 * 人机验证后浏览器原生提示框弹出『这条评论看起来像模板群发的第 4,000 份…』——
	 * 不写死，用同样的后台自定义文案」）。
	 * 失败源过滤：仅本地内容四闸 rule_hit 值域（LocalRulesDriver Verdict::reject reason——
	 * Precheck four_gate_fail 面唯一来源）；captcha_missing/IP 信誉等其余失败维持现形态
	 * （按钮态/wk-gate-msg/适配器），不挂原生（hold 类裁决不属于预检失败面勿扩）。
	 * 文案动态：message=预检响应 message（工坊文案经 Copywork/Labels 渲染值），零写死。
	 * fallback：评论框元素/setCustomValidity/reportValidity 缺失（老环境）→ 既有
	 * wk-gate-msg 通道（渐进增强，同 G1 守卫哲学；wk-gate-msg 保留不删）。
	 * 清除时机三处（做漏=字段 invalid 让浏览器阻止提交并弹内容错误气泡挡住正常评论）：
	 *   ① 评论框 input 事件（用户改内容即解禁，先于 msg 条防抖——气泡即时消失）；
	 *   ② 预检成功/信用免验签发路径（releaseToAdapter / onTrustPass）；
	 *   ③ 验证通过态设置处（markToken present=true——适配器 wk-gate-token 统一入口）。 */
	var LOCAL_CONTENT_RULES = { too_short: 1, too_long: 1, no_chinese: 1, banned_word: 1 };

	function commentField( form ) {
		if ( ! form || ! form.querySelector ) {
			return null;
		}
		return form.querySelector( '#comment' ) || form.querySelector( 'textarea[name="comment"]' );
	}

	function showNativeGateHint( form, message ) {
		var field = commentField( form );
		if ( ! field || typeof field.setCustomValidity !== 'function' || typeof field.reportValidity !== 'function' ) {
			return false; // fallback：老环境走 wk-gate-msg 通道（渐进增强）。
		}
		field.setCustomValidity( message ); // 原生气泡文案=后台自定义文案（预检响应 message）。
		field.reportValidity();
		return true;
	}

	function clearNativeGateHint( form ) {
		var field = commentField( form );
		if ( field && typeof field.setCustomValidity === 'function' ) {
			field.setCustomValidity( '' ); // 清除=合法态恢复（残留 invalid 会挡住正常提交）。
		}
	}

	/* 【M4UX·G1】原生校验分层守卫（03 §2.1 信用免验预检门前置增量）：
	 * 浏览器原生 constraint validation 管「表单没填」（required 必填项），wink 四闸预检
	 * 管「填了但不合规」——两层先后分工，不替换。点「完成人机验证」先调
	 * form.reportValidity()：返回 false = 有未填必填字段 → 浏览器自动弹原生提示框
	 * （invalid 字段聚焦+提示），预检请求不发（wk-gate-msg 不出现——它仍只报内容不合规）；
	 * 返回 true → 走既有四闸预检。表单元素缺失/不支持 reportValidity：守卫放行
	 * （等价无原生校验环境，防御形态）。 */
	function nativeValidityGuard( form ) {
		if ( ! form || typeof form.reportValidity !== 'function' ) {
			return true; // 守卫防御：元素缺失/旧浏览器不阻断既有预检链。
		}
		return form.reportValidity();
	}

	function runPrecheck( container, form, button ) {
		var config = precheckConfig();
		if ( ! config ) {
			return;
		}
		container.setAttribute( 'data-wk-checking', '1' );
		setGateButton( container, 'checking', l10n( 'checking', 'Checking…' ) );

		var draft = collectDraft( form, container );
		var body = new URLSearchParams();
		body.append( 'action', config.action || 'wk_gate_precheck' );
		if ( config.nonce ) {
			body.append( 'nonce', config.nonce );
		}
		body.append( 'wk_stub', draft.stub );
		body.append( 'post_id', draft.postId );
		body.append( 'author', draft.author );
		body.append( 'email', draft.email );
		body.append( 'content', draft.content );

		var controller = 'AbortController' in window ? new AbortController() : null;
		var timer = window.setTimeout( function () {
			if ( controller ) {
				controller.abort(); // 3s 超时（precheck_timeout_ms）：fail-safe 降级出码。
			}
		}, config.timeoutMs || 3000 );

		function finish() {
			window.clearTimeout( timer );
			container.removeAttribute( 'data-wk-checking' );
		}

		function releaseToAdapter() {
			// 放行本次点击给适配器 bubble 处理器：bypass 标记 + 程序化补点（捕获层见标记即放行）。
			// 【v1.5·F4-2①】captcha_needed 成功转验证码链：旧错误条已不成立，同拍清除。
			// 【G2 清除②a】预检放行成功链路：残留原生气泡会让浏览器挡住后续提交——同拍清。
			finish();
			clearNativeGateHint( form );
			hideMsgBar( container );
			setGateButton( container, 'unverified', l10n( 'idle', 'Complete human verification' ) );
			if ( isSubmitGate( container ) ) {
				// 【W2·R5】无按钮模式：预检放行 = 续接人机验证（无按钮可点——requestVerify
				// 契约承接；gating 标志已在拦截链④置位）。
				startVerification( container, form );
				return;
			}
			form.setAttribute( 'data-wk-precheck-bypass', '1' );
			try { button.click(); } catch ( err ) {}
		}

		window.fetch( config.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
			signal: controller ? controller.signal : undefined
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'http ' + response.status ); // 403（nonce 陈旧）等 → 降级出码。
			}
			return response.json();
		} ).then( function ( data ) {
			if ( data && 'trust_pass' === data.result && data.trust ) {
				finish();
				onTrustPass( container, data.trust );
				return;
			}
			if ( data && 'four_gate_fail' === data.result ) {
				finish();
				form.removeAttribute( 'data-wk-gating' ); // 【W2】gating 期结束（拦截止于本次提交——访客改稿后重新提交）。
				setGateButton( container, 'unverified', l10n( 'idle', 'Complete human verification' ) );
				if ( data.rule && LOCAL_CONTENT_RULES[ data.rule ] ) {
					// 【G2】本地内容四闸失败 → 评论框原生气泡弹后台自定义文案（动态不写死，
					// wk-gate-msg 不出现）；老环境 fallback → 既有 wk-gate-msg 通道（渐进增强）。
					if ( ! showNativeGateHint( form, data.message || '' ) ) {
						showMsgBar( container, data.message || data.rule || '' );
					}
					return;
				}
				showMsgBar( container, data.message || data.rule || '' ); // 值域外防御：维持既有通道。
				return;
			}
			releaseToAdapter(); // captcha_needed（IP fail/信用不足）。
		} ).catch( function () {
			releaseToAdapter(); // 网络/超时/解析异常：预检基础设施故障 ≠ 拦提交。
		} );
	}

	function onTrustPass( container, trustValue ) {
		clearNativeGateHint( ownedForm( container ) ); // 【G2 清除②b】信用免验签发成功链路：残留原生气泡会让浏览器挡住提交。
		hideMsgBar( container ); // 【v1.5·F4-2①】trust_pass 成功分支：旧错误条已不成立。
		var field = container.querySelector( 'input[name="wk_trust"]' );
		if ( ! field ) {
			field = document.createElement( 'input' );
			field.type = 'hidden';
			field.name = 'wk_trust';
			container.appendChild( field );
		}
		field.value = trustValue;

		// 【v1.5·F4-1】免验轻提示（区别于真验通过的「已通过验证」——让「免验发生了」前台
		// 可感知：多次正常评论后不再出验证码属设计行为）。aria-live 由按钮 polite 播报。
		setGateButton( container, 'verified', l10n( 'wk_trust_hint', 'Old friend, no verification needed' ) );
		container.dispatchEvent( new CustomEvent( 'wk-gate-token', { bubbles: true } ) ); // 解除提交禁用。

		// 前端 TTL 计时器（对齐天御票据 5min 过期回退模式——03 §2.1）：到点清凭证回退未验证态。
		var config = precheckConfig();
		var ttlMs = config && config.ttlMs ? config.ttlMs : 600000;
		window.setTimeout( function () {
			if ( container.getAttribute( 'data-wk-token' ) !== '1' ) {
				return; // 已被后续状态变更接管（票据过期回退等）。
			}
			field.value = '';
			hideMsgBar( container ); // 【v1.5·F4-2③】凭证过期旧错误语境失效，同拍清错误条。
			setGateButton( container, 'unverified', l10n( 'expired', 'Verification expired — please verify again' ) );
			container.dispatchEvent( new CustomEvent( 'wk-gate-expire', { bubbles: true } ) ); // 重新禁用提交。
		}, ttlMs );
	}

	function markToken( container, present ) {
		var form = ownedForm( container );
		if ( present ) {
			container.setAttribute( 'data-wk-token', '1' );
			clearNativeGateHint( form ); // 【G2 清除③】验证通过态（适配器 wk-gate-token 统一入口）：残留 invalid 会挡住正常提交。
		} else {
			container.removeAttribute( 'data-wk-token' );
		}
		if ( form && isSubmitGate( container ) ) {
			return; // 【W2·R3】无按钮容器：提交控件恒可点——refreshLocks（禁用/解锁）不适用
			// （票据过期回退不得禁用提交；防护语义由 submit 拦截链承载）。
		}
		if ( form ) {
			refreshLocks( form );
		}
	}

	function refreshLocks( form ) {
		// 表单内全部已武装容器都持有 token 才解除禁用（多容器防御：回复嵌套表单）。
		var armed = Array.prototype.slice.call( form.querySelectorAll( '.wk-gate[data-wk-armed="1"]' ) );
		var allReady = armed.length > 0 && armed.every( function ( gate ) {
			return gate.getAttribute( 'data-wk-token' ) === '1';
		} );

		submitControls( form ).forEach( function ( control ) {
			if ( allReady ) {
				if ( control.getAttribute( 'data-wk-gate-disabled' ) === '1' ) {
					// 只还原我们自己禁用过的控件（不碰主题自身禁用逻辑）。
					control.disabled = false;
					control.removeAttribute( 'aria-disabled' );
					control.removeAttribute( 'data-wk-gate-disabled' );
					control.removeAttribute( 'title' );
					if ( control.parentNode && control.parentNode.classList && control.parentNode.classList.contains( 'wk-gate-lock' ) ) {
						control.parentNode.removeAttribute( 'data-wk-active' ); // 包裹层光标还原。
					}
				}
				return;
			}
			if ( ! control.disabled ) {
				control.disabled = true;
				control.setAttribute( 'aria-disabled', 'true' );
				control.setAttribute( 'data-wk-gate-disabled', '1' );
				control.setAttribute( 'title', HINT_TEXT ); // 悬停同文提示（M2-FIX2 D3）。
				wrapForCapture( form, control ); // 点击捕获（禁用控件不派发 click）。
			}
		} );
	}

	/* ---------- 容器装配 ---------- */

	function bootContainer( container ) {
		if ( container.getAttribute( 'data-wk-ready' ) === '1' ) {
			return; // 幂等：单容器只初始化一次。
		}

		var driverId = container.getAttribute( 'data-driver' );
		var interaction = container.getAttribute( 'data-interaction' );
		var adapter = byId( driverId );

		if ( ! adapter ) {
			// 适配器脚本未就绪：延后到注册事件（脚本异步加载竞态）。
			container.setAttribute( 'data-wk-pending', '1' );
			return;
		}

		if ( adapter.interaction !== interaction ) {
			// 元数据不匹配（渲染层与适配器声明漂移）：拒绝分派，提示刷新。
			if ( isSubmitGate( container ) ) {
				restoreClassicButton( container ); // 【W2】hidden 按钮还原——错误文案可见可点。
			}
			setStatus( container, window.wkGateL10n && window.wkGateL10n.mismatch ? window.wkGateL10n.mismatch : 'The verification widget failed to load. Please refresh the page and try again.' );
			return;
		}

		container.setAttribute( 'data-wk-ready', '1' );
		container.removeAttribute( 'data-wk-pending' );

		// 生命周期事件监听先挂（防适配器 init 同步派发 token 的竞态）。
		container.addEventListener( 'wk-gate-token', function () {
			markToken( container, true );
		} );
		container.addEventListener( 'wk-gate-expire', function () {
			markToken( container, false );
		} );

		/* 【M6·批H5·H5-a】真实提交等待期提示（0.8.3 改 Toast 气泡形态——用户反馈不要
		 * 评论区下方内联条）：表单原生 POST 发生（验证已过/预检放行）即为同步闸判定
		 * 等待期（访客视角"转圈"）——页面顶部 Toast 气泡告知检查进行中+提交控件禁用
		 * 防重复提交。form 级标志防双容器重复执行；Toast body 级单例幂等；服务端完成
		 * 即自然跳转（结果页/拦截页），无需清除逻辑；无 JS/无验证码容器面不提示
		 * （渐进增强哲学）。 */
		var submitForm = container.closest ? ( container.closest( 'form' ) || null ) : null;
		if ( submitForm && submitForm.getAttribute( 'data-wk-submitting' ) !== '1' ) {
			submitForm.setAttribute( 'data-wk-submitting', '1' );
			submitForm.addEventListener( 'submit', function () {
				if ( submitForm.getAttribute( 'data-wk-gating' ) === '1' ) {
					return; // 【W2·R4】gating 期抑制：人机验证等待期 ≠「接收检查」——验证期
					// 反馈由 submit-gate 引导 Toast 承载（语义错位）；续提时标志已清（真实
					// 提交在途，本监听正常触发——真实提交语义不变）。
				}
				showToast( window.wkGateL10n && window.wkGateL10n.checking_submit
					? window.wkGateL10n.checking_submit
					: 'Your comment is being checked, please wait…' );
				submitControls( submitForm ).forEach( function ( control ) {
					control.disabled = true; // 防重复提交（本次提交已在途，禁用无碍）。
				} );
			}, false );
		}

		try {
			adapter.init( container );
			armContainer( container ); // init 成功 → 未验证禁用提交（渐进增强）。
		} catch ( err ) {
			setStatus( container, ( window.wkGateL10n && window.wkGateL10n.error ) || 'The verification widget failed to initialize — refresh to retry' );
			// 初始化异常：还原可点（可能已被部分武装——等价无 JS 场景）。
			container.removeAttribute( 'data-wk-armed' );
			var form = ownedForm( container );
			if ( form ) {
				if ( isSubmitGate( container ) ) {
					// 【W2】无按钮容器 init 抛错：Toast 播报 + 回退经典形态（降级矩阵——
					// 适配器故障=无拦截+服务端兜底；错误文案随 hidden 还原可见可点）。
					showToast( ( window.wkGateL10n && window.wkGateL10n.error ) || 'The verification widget failed to initialize — refresh to retry' );
					restoreClassicButton( container );
				} else {
					refreshLocks( form );
				}
			}
		}
	}


	/* ---------- 【W2·无按钮模式】submit-gate 模块（0.8.4；容器 data-submit-gate="1"） ----------
	 *
	 * 访客直接点原生提交按钮（评论「发表评论」/登录「登录」）即触发人机验证，过码后自动
	 * 放行提交（§2.1 统一拦截时序①-⑦）。适配器契约新增 requestVerify(container) 单方法
	 * （W-3：拦截/G1 分层/预检编排/续提防递归全是闸级能力——调度器仍不认驱动 id），交互
	 * 声明与容器 data-interaction 均不动（'click' 照旧，分派契约零翻转）。
	 *
	 * 拦截链次序（R5 分层语义不变，触发面从验证按钮 click 平移到 submit）：
	 *   ① 续提防递归门（data-wk-resubmit 一次性标志 → 放行本次程序化续提）；
	 *   ② token 在场直通（embed 页面加载即渲染的常规路径；H5-a Toast 自然触发）；
	 *   ③ G1 原生校验（nativeValidityGuard——原生管完整性 → 四闸管内容 → 人机验证）；
	 *   ④ 预检门（trust 开且配置在场：trust_pass=写凭证+wk-gate-token 续提 / four_gate_fail=
	 *      G2 气泡 / captcha_needed=releaseToAdapter 无按钮分支续接 startVerification）；
	 *   ⑤ 人机验证（preventDefault 截住本次提交 → adapter.requestVerify(container)）；
	 *   ⑥ token 到域且 gating 期未决 → requestSubmit 续提（监听链完整——AJAX 评论主题兼容）；
	 *   ⑦ 失败面（适配器 wk-gate-verify-failed 事件）→ Toast 播报 + 保持拦截（再点提交重试）。
	 *
	 * 降级矩阵：无 JS/拦截器未装配=原生提交直发（服务端 captcha_missing 兜底，与经典形态
	 * 等价）；适配器契约缺失/抛错=回退经典形态（按钮 hidden 还原）；无 form 容器=装配期
	 * 即回退（armContainer）。gating 期 H5-a 抑制（R4）——验证期等待反馈由引导 Toast 承载。 */

	function isSubmitGate( container ) {
		return container.getAttribute( 'data-submit-gate' ) === '1';
	}

	/** 经典形态回退（R6/降级矩阵）：submit-gate 锚摘除 + 按钮 hidden 还原——验证按钮恢复
	 * 可见可点（适配器经典 click 绑定仍在），提交直发由服务端 captcha_missing 兜底。 */
	function restoreClassicButton( container ) {
		container.removeAttribute( 'data-submit-gate' );
		var button = gateButton( container );
		if ( button ) {
			button.hidden = false;
		}
	}

	function submitGateContainers( form ) {
		return Array.prototype.slice.call( form.querySelectorAll( '.wk-gate[data-submit-gate="1"][data-wk-armed="1"]' ) );
	}

	/** 续提（R2）：置一次性 bypass 标志（拦截链①放行——防递归）→ requestSubmit（submit
	 * 事件重入，监听链完整——H5-a Toast 与第三方/AJAX 主题监听不丢）；无 requestSubmit
	 * 老环境回退 form.submit()（丢监听链的如实降级——2021+ 浏览器全覆盖 WP 受众）。 */
	function resubmitForm( form ) {
		form.setAttribute( 'data-wk-resubmit', '1' );
		if ( typeof form.requestSubmit === 'function' ) {
			form.requestSubmit();
		} else {
			form.submit();
		}
	}

	/** ⑤ 人机验证启动：gating 标志（R4——抑制 H5-a 误报）+ 适配器 requestVerify 契约。
	 * 契约缺失/抛错 → 经典形态回退（降级矩阵：适配器故障=无拦截+服务端兜底）。 */
	function startVerification( container, form ) {
		form.setAttribute( 'data-wk-gating', '1' );
		var adapter = byId( String( container.getAttribute( 'data-driver' ) || '' ) );
		if ( adapter && typeof adapter.requestVerify === 'function' ) {
			try {
				adapter.requestVerify( container );
				showToast( l10n( 'submit_gate_verify', 'Please complete human verification before submitting' ) );
				return;
			} catch ( err ) {} // 契约抛错 → 经典回退。
		}
		restoreClassicButton( container );
		form.removeAttribute( 'data-wk-gating' );
	}

	function armSubmitGate( container, form ) {
		// ⑥ token 到域续提（每容器各挂）：gating 期未决 → 自动 requestSubmit——trust_pass
		// 与过码两路径统一由 wk-gate-token 收口（onTrustPass 派发同一事件）。
		container.addEventListener( 'wk-gate-token', function () {
			if ( form.getAttribute( 'data-wk-gating' ) !== '1' ) {
				return;
			}
			form.removeAttribute( 'data-wk-gating' );
			resubmitForm( form );
		} );

		// ⑦ 失败面播报：适配器失败态（loadFail/onError/关层未过码）派发 wk-gate-verify-failed
		// → Toast（R10：按钮 hidden 后的外显主通道；访客可再点提交重试——保持拦截）。
		container.addEventListener( 'wk-gate-verify-failed', function () {
			showToast( l10n( 'submit_gate_retry', 'Human verification incomplete — click submit once more to retry' ) );
		} );

		if ( form.dataset.wkSubmitGateBound === '1' ) {
			return; // form 级拦截链一次绑定（多容器防御：容器集合按实时属性判定）。
		}
		form.dataset.wkSubmitGateBound = '1';

		// 捕获阶段 submit 拦截（capture=true——先于 H5-a/第三方 bubble 监听；Enter 隐式提交/
		// 密码管理器 requestSubmit/用户点击三路径全部产生 submit 事件，天然全覆盖）。
		form.addEventListener( 'submit', function ( event ) {
			// ① 续提防递归门：程序化续提（resubmitForm 引发的 submit）直接放行。
			if ( form.getAttribute( 'data-wk-resubmit' ) === '1' ) {
				form.removeAttribute( 'data-wk-resubmit' );
				return;
			}
			// ② token 在场直通（embed 页面加载即渲染的常规路径——多数提交零拦截零续提；
			// 全部已武装 submit-gate 容器齐票才放行，refreshLocks 多容器语义同源）。
			var gates = submitGateContainers( form );
			var pending = [];
			var i;
			for ( i = 0; i < gates.length; i++ ) {
				if ( gates[ i ].getAttribute( 'data-wk-token' ) !== '1' ) {
					pending.push( gates[ i ] );
				}
			}
			if ( pending.length === 0 ) {
				return;
			}
			// ③ G1 原生校验（R5 次序不变：原生管完整性 → 四闸管内容 → 人机验证）——
			// 有未填必填字段：浏览器已弹原生提示框，验证码层不拉起。
			if ( ! nativeValidityGuard( form ) ) {
				event.preventDefault();
				return;
			}
			// 截住本次提交（⑤），编排预检/人机验证。
			event.preventDefault();
			var container = pending[ 0 ];
			if ( container.getAttribute( 'data-wk-checking' ) === '1' ) {
				return; // 预检在途：可等待不可再触发（提交扣住——预检收敛后统一续接/续提）。
			}
			if ( container.getAttribute( 'data-wk-trust' ) === '1' && precheckConfig() ) {
				// ④ 预检门（trust 开且配置在场）：三分支由 runPrecheck 收口——trust_pass=写
				// 凭证+wk-gate-token 续提；four_gate_fail=G2 气泡/错误条；captcha_needed=
				// releaseToAdapter 无按钮分支续接 startVerification。
				form.setAttribute( 'data-wk-gating', '1' );
				runPrecheck( container, form, gateButton( container ) );
				return;
			}
			startVerification( container, form );
		}, true );
	}


	/**
	 * 状态输出（M2-FIX2 v1.4 适配）：三态单按钮形态下文案落在验证按钮上；
	 * 仍渲染 .wk-captcha-status 的旧形态/第三方适配器走状态字回退。
	 */
	function setStatus( container, text ) {
		var button = container.querySelector( '.wk-captcha-btn' );
		if ( button ) {
			button.textContent = text;
			return;
		}
		var status = container.querySelector( '.wk-captcha-status' );
		if ( status ) {
			status.textContent = text;
		}
	}

	function bootAll( root ) {
		var containers = ( root || document ).querySelectorAll( '.wk-gate[data-driver]' );
		var i;
		for ( i = 0; i < containers.length; i++ ) {
			bootContainer( containers[ i ] );
		}
	}

	// 适配器注册事件：动态加载的适配器注册后唤醒 pending 容器（竞态收口）。
	window.addEventListener( 'wk-gate-driver-registered', function () {
		bootAll();
	} );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			bootAll();
		} );
	} else {
		bootAll();
	}
} )();
