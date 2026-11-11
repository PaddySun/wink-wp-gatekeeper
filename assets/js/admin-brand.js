/**
 * Wink 门口保安 · 设置页页头 logo 眨眼动效（03 §4 / wink brand system v1.0）
 *
 * 节奏接近真人：慢、短、不规律感。
 *   - 空闲：每 4.2s 自主眨眼一次；
 *   - 悬停：立即眨一次，作为回应；
 *   - 有输入/聚焦：停止眨眼，先处理人；
 *   - prefers-reduced-motion：始终睁眼，不播放动画。
 * 帧参数：闭眼 130ms（切换 logo-wink-blink.svg + CSS .is-blinking 纵向压缩 8%），
 *         睁眼 120ms；曲线 cubic-bezier(.4,0,.2,1)（CSS 侧）。
 * M0 只服务设置页页头；拦截页 130ms 单次眨眼为 M1（F07）。
 */
( function () {
	'use strict';

	var IDLE_MS = 4200; // 空闲自主眨眼间隔。
	var CLOSE_MS = 130; // 闭眼帧时长。
	var OPEN_MS = 120;  // 睁眼恢复时长。

	var logo = document.getElementById( 'wk-brand-logo' );
	if ( ! logo ) {
		return;
	}

	var blinkSrc = logo.getAttribute( 'data-blink-src' );
	var openSrc = logo.getAttribute( 'src' );
	if ( ! blinkSrc || ! openSrc ) {
		return;
	}

	// 减少动效：始终睁眼，不播放任何动画（可访问性底线）。
	var reducedMotion = window.matchMedia ? window.matchMedia( '(prefers-reduced-motion: reduce)' ) : null;
	if ( reducedMotion && reducedMotion.matches ) {
		return;
	}

	var idleTimer = null;
	var animating = false;

	function playBlink() {
		if ( animating ) {
			return;
		}
		animating = true;
		logo.classList.add( 'is-blinking' );
		logo.src = blinkSrc; // 闭眼帧。

		window.setTimeout( function () {
			logo.classList.remove( 'is-blinking' );
			logo.src = openSrc; // 睁眼帧。
			window.setTimeout( function () {
				animating = false;
			}, OPEN_MS );
		}, CLOSE_MS );
	}

	function startIdle() {
		stopIdle();
		idleTimer = window.setInterval( playBlink, IDLE_MS );
	}

	function stopIdle() {
		if ( idleTimer !== null ) {
			window.clearInterval( idleTimer );
			idleTimer = null;
		}
	}

	// 悬停：立即眨一次作为回应，并重排空闲节奏。
	logo.addEventListener( 'mouseenter', function () {
		playBlink();
		startIdle();
	} );

	// 键盘可达性：聚焦等同悬停回应。
	logo.addEventListener( 'focus', function () {
		playBlink();
	} );

	// 有输入/聚焦：停止眨眼，先处理人（表单控件 focusin/focusout 冒泡）。
	document.addEventListener( 'focusin', function ( event ) {
		var target = event.target;
		if ( target && target.matches && target.matches( 'input, textarea, select, button' ) ) {
			stopIdle();
		}
	} );
	document.addEventListener( 'focusout', function () {
		startIdle();
	} );

	startIdle();

	/* -----------------------------------------------------------------
	 * M1 · Tab⑥ 统计重置（AJAX + nonce + confirm；03 §6 后台操作安全约定）
	 * ----------------------------------------------------------------- */

	var resetButton = document.getElementById( 'wk-stats-reset' );
	if ( resetButton ) {
		resetButton.addEventListener( 'click', function () {
			if ( ! window.confirm( resetButton.getAttribute( 'data-confirm' ) || '' ) ) {
				return;
			}
			var result = document.getElementById( 'wk-stats-reset-result' );
			resetButton.disabled = true;
			if ( result ) {
				result.textContent = '……';
			}

			var body = new URLSearchParams();
			body.append( 'action', 'wink_gatekeeper_reset_stats' );
			body.append( 'nonce', resetButton.getAttribute( 'data-nonce' ) || '' );

			window.fetch( window.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( payload ) {
				if ( result ) {
					result.textContent = payload && payload.data && payload.data.message
						? payload.data.message
						: ( payload && payload.success ? '已重置。' : '重置失败。' );
				}
				if ( payload && payload.success ) {
					window.location.reload(); // 计数卡归零展示。
				}
			} ).catch( function () {
				if ( result ) {
					result.textContent = '重置失败（网络错误）。';
				}
			} ).finally( function () {
				resetButton.disabled = false;
			} );
		} );
	}

	/* -----------------------------------------------------------------
	 * M1 · Tab⑤ 文案工坊「恢复默认」（确认 + placeholder 回填输入框）
	 * ----------------------------------------------------------------- */

	var restoreButton = document.getElementById( 'wk-copy-restore-defaults' );
	if ( restoreButton ) {
		restoreButton.addEventListener( 'click', function () {
			if ( ! window.confirm( restoreButton.getAttribute( 'data-confirm' ) || '' ) ) {
				return;
			}
			document.querySelectorAll( '[id^="wk-copy-message-"], [id^="wk-copy-title-"]' ).forEach( function ( field ) {
				field.value = field.getAttribute( 'placeholder' ) || '';
			} );
		} );
	}

	/* -----------------------------------------------------------------
	 * C1/FIX-3 · 驱动下拉切换联动（Tab② 验证码 / Tab③ 云端审核共用，03 §1 v1.3 用户建议1+2）
	 *   - 公用 bindVendorSwitcher( select, noteId )：select 切换 → select 所属 .wk-panel
	 *     内全部 [data-wk-vendor] 配置区显隐（vendor=选中值者显）+ 未配置说明行即时显隐
	 *     （切换保留：不清空任何字段，各厂商独立保存）；
	 *   - 必填双层校验 JS 侧：选了厂商且必填空 → 标红缺失字段 + 阻止提交一次
	 *     （提示具体字段名；第二次提交放行——服务端黄条兜底）；Tab②③ 同款推广；
	 *   - 测试凭据按钮：各驱动 AJAX 三态（绿/红/黄/灰），结果缓存在按钮旁（不落库）。
	 * ----------------------------------------------------------------- */

	function bindVendorSwitcher( select, noteId ) {
		if ( ! select ) {
			return null;
		}
		/* 【FIX-3】area 容器作用域=select 所属面板（每页仅渲染一个 Tab，.wk-panel 缺失兜底
		 * document）——Tab②③ 互不越界（防跨 Tab 误显隐）；M4 后续驱动注册后自动入列
		 * （不硬化厂商名单）。 */
		var scope = select.closest( '.wk-panel' ) || document;
		var areas = Array.prototype.slice.call( scope.querySelectorAll( '[data-wk-vendor]' ) );
		var note = noteId ? document.getElementById( noteId ) : null;
		var blockOnce = false;

		function syncAreas() {
			var vendorSelected = '' !== select.value;
			if ( note ) {
				note.classList.toggle( 'hidden', vendorSelected );
			}
			areas.forEach( function ( area ) {
				area.hidden = area.getAttribute( 'data-wk-vendor' ) !== select.value;
			} );
		}

		select.addEventListener( 'change', syncAreas );
		syncAreas();

		// 必填标红缺失字段清单（password 已配置且留空 = 不算缺——「留空保存则不修改」契约）。
		function missingFields() {
			var missing = [];
			if ( '' === select.value ) {
				return missing;
			}
			areas.forEach( function ( area ) {
				if ( area.hidden ) {
					return;
				}
				area.querySelectorAll( '[data-wk-required="1"]' ).forEach( function ( field ) {
					var label = field.getAttribute( 'data-wk-label' ) || field.name;
					var configured = '1' === ( field.getAttribute( 'data-wk-configured' ) || '0' );
					var isPassword = 'password' === field.type;
					if ( '' === field.value.trim() && ! ( isPassword && configured ) ) {
						missing.push( { field: field, label: label } );
					}
				} );
			} );
			return missing;
		}

		var form = select.closest( 'form' );
		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				var missing = missingFields();
				if ( missing.length > 0 && ! blockOnce ) {
					event.preventDefault();
					blockOnce = true;
					missing.forEach( function ( item ) {
						item.field.classList.add( 'wk-field-missing' );
					} );
					var names = missing.map( function ( item ) { return item.label; } ).join( '、' );
					window.alert( '驱动「' + select.options[ select.selectedIndex ].text.replace( /\s*—.*$/, '' ) + '」缺少：' + names + '——请补齐后再保存（本次已拦截，再次点击保存可强制提交，服务端将按「驱动视为未配置」处理）。' );
				}
			} );
		}
		return {
			// 字段有输入即重置「阻止一次」标志（标红摘除走共用 document 监听）。
			resetBlockOnce: function () {
				blockOnce = false;
			}
		};
	}

	var driverGroups = [];
	// Tab② 验证码驱动（C1 既有行为原样——函数名泛化，语义零变化）。
	driverGroups.push( bindVendorSwitcher( document.getElementById( 'wk-captcha-driver' ), 'wk-captcha-unconfigured-note' ) );
	// 【FIX-3】Tab③ 云端审核驱动：三厂商配置区（data-wk-vendor）并列渲染，PHP 初始
	// hidden=chain[0]；此处接管切换显隐（病株修复：此前 Tab③ 无 JS 联动、无区可切）。
	driverGroups.push( bindVendorSwitcher( document.getElementById( 'wk-cloud-driver' ), 'wk-review-unconfigured-note' ) );

	// 字段有输入即摘除标红 + 重置「阻止一次」标志（全部驱动组共用一个监听）。
	document.addEventListener( 'input', function ( event ) {
		var target = event.target;
		if ( target && target.classList && target.classList.contains( 'wk-field-missing' ) ) {
			target.classList.remove( 'wk-field-missing' );
			driverGroups.forEach( function ( group ) {
				if ( group ) {
					group.resetBlockOnce();
				}
			} );
		}
	} );

	var probeButton = document.getElementById( 'wk-tencent-test-credentials' );
	if ( probeButton ) {
		probeButton.addEventListener( 'click', function () {
			var result = document.getElementById( 'wk-tencent-test-result' );
			probeButton.disabled = true;
			if ( result ) {
				result.textContent = '测试中……';
				result.className = '';
			}

			function fieldOrEmpty( id ) {
				var el = document.getElementById( id );
				return el ? el.value.trim() : '';
			}

			var body = new URLSearchParams();
			body.append( 'action', 'wink_gatekeeper_test_credentials' );
			body.append( 'nonce', probeButton.getAttribute( 'data-nonce' ) || '' );
			body.append( 'app_id', fieldOrEmpty( 'wk-tencent-app-id' ) );
			body.append( 'secret_id', fieldOrEmpty( 'wk-field-captcha-tencent-secret_id' ) );
			body.append( 'secret_key', fieldOrEmpty( 'wk-field-captcha-tencent-secret_key' ) );
			body.append( 'app_secret_key', fieldOrEmpty( 'wk-field-captcha-tencent-app_secret_key' ) );

			window.fetch( window.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( payload ) {
				if ( ! result ) {
					return;
				}
				var data = payload && payload.data ? payload.data : null;
				if ( payload && payload.success && data ) {
					result.textContent = data.message || '';
					result.className = 'wk-probe wk-probe--' + ( data.state || 'gray' );
				} else {
					result.textContent = ( data && data.message ) ? data.message : '测试失败。';
					result.className = 'wk-probe wk-probe--gray';
				}
			} ).catch( function () {
				if ( result ) {
					result.textContent = '测试失败（网络错误）。';
					result.className = 'wk-probe wk-probe--gray';
				}
			} ).finally( function () {
				probeButton.disabled = false;
			} );
		} );
	}

	/* -----------------------------------------------------------------
	 * B3 · Tab② 阿里「测试凭据」（05 §1.2 / DESIGN-D2 §1.4：假参实调
	 * VerifyIntelligentCaptcha 三态——绿 VerifyCode=F002/F003 / 红 AK·签名·权限 /
	 * 黄必填缺；结果缓存在按钮旁，不落库）。
	 * ----------------------------------------------------------------- */

	var aliyunProbeButton = document.getElementById( 'wk-aliyun-test-credentials' );
	if ( aliyunProbeButton ) {
		aliyunProbeButton.addEventListener( 'click', function () {
			var result = document.getElementById( 'wk-aliyun-test-result' );
			aliyunProbeButton.disabled = true;
			if ( result ) {
				result.textContent = '测试中……';
				result.className = '';
			}

			function aliFieldOrEmpty( id ) {
				var el = document.getElementById( id );
				return el ? el.value.trim() : '';
			}

			var body = new URLSearchParams();
			body.append( 'action', 'wink_gatekeeper_test_credentials_aliyun' );
			body.append( 'nonce', aliyunProbeButton.getAttribute( 'data-nonce' ) || '' );
			body.append( 'ali_access_key_id', aliFieldOrEmpty( 'wk-field-captcha-aliyun-access_key_id' ) );
			body.append( 'ali_access_key_secret', aliFieldOrEmpty( 'wk-field-captcha-aliyun-access_key_secret' ) );
			body.append( 'ali_scene_id', aliFieldOrEmpty( 'wk-aliyun-scene-id' ) );
			body.append( 'ali_prefix', aliFieldOrEmpty( 'wk-aliyun-prefix' ) );

			window.fetch( window.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( payload ) {
				if ( ! result ) {
					return;
				}
				var data = payload && payload.data ? payload.data : null;
				if ( payload && payload.success && data ) {
					result.textContent = data.message || '';
					result.className = 'wk-probe wk-probe--' + ( data.state || 'gray' );
				} else {
					result.textContent = ( data && data.message ) ? data.message : '测试失败。';
					result.className = 'wk-probe wk-probe--gray';
				}
			} ).catch( function () {
				if ( result ) {
					result.textContent = '测试失败（网络错误）。';
					result.className = 'wk-probe wk-probe--gray';
				}
			} ).finally( function () {
				aliyunProbeButton.disabled = false;
			} );
		} );
	}

	/* -----------------------------------------------------------------
	 * C4 · Tab③ TMS「测试凭据」（03 Tab③ 同款模式：良性样本文本真调用三态；
	 * 绿态在服务端顺带解除当日停转——页面不重载，灯状态下次刷新可见）。
	 * ----------------------------------------------------------------- */

	var tmsProbeButton = document.getElementById( 'wk-tms-test-credentials' );
	if ( tmsProbeButton ) {
		tmsProbeButton.addEventListener( 'click', function () {
			var result = document.getElementById( 'wk-tms-test-result' );
			tmsProbeButton.disabled = true;
			if ( result ) {
				result.textContent = '测试中……';
				result.className = '';
			}

			function tmsFieldOrEmpty( id ) {
				var el = document.getElementById( id );
				return el ? el.value.trim() : '';
			}

			var body = new URLSearchParams();
			body.append( 'action', 'wink_gatekeeper_test_credentials_tms' );
			body.append( 'nonce', tmsProbeButton.getAttribute( 'data-nonce' ) || '' );
			body.append( 'tms_secret_id', tmsFieldOrEmpty( 'wk-field-review-cloud-tencent_tms-secret_id' ) );
			body.append( 'tms_secret_key', tmsFieldOrEmpty( 'wk-field-review-cloud-tencent_tms-secret_key' ) );
			body.append( 'tms_biz_type', tmsFieldOrEmpty( 'wk-tms-biz-type' ) );

			window.fetch( window.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( payload ) {
				if ( ! result ) {
					return;
				}
				var data = payload && payload.data ? payload.data : null;
				if ( payload && payload.success && data ) {
					result.textContent = data.message || '';
					result.className = 'wk-probe wk-probe--' + ( data.state || 'gray' );
				} else {
					result.textContent = ( data && data.message ) ? data.message : '测试失败。';
					result.className = 'wk-probe wk-probe--gray';
				}
			} ).catch( function () {
				if ( result ) {
					result.textContent = '测试失败（网络错误）。';
					result.className = 'wk-probe wk-probe--gray';
				}
			} ).finally( function () {
				tmsProbeButton.disabled = false;
			} );
		} );
	}

	/* -----------------------------------------------------------------
	 * B5 · Tab③ 阿里 PLUS「测试凭据」（03 Tab③【v1.5】同款模式：良性短文本真调用三态；
	 * 绿态在服务端顺带解除当日停转——页面不重载，灯状态下次刷新可见）。
	 * ----------------------------------------------------------------- */

	var greenProbeButton = document.getElementById( 'wk-green-test-credentials' );
	if ( greenProbeButton ) {
		greenProbeButton.addEventListener( 'click', function () {
			var result = document.getElementById( 'wk-green-test-result' );
			greenProbeButton.disabled = true;
			if ( result ) {
				result.textContent = '测试中……';
				result.className = '';
			}

			function greenFieldOrEmpty( id ) {
				var el = document.getElementById( id );
				return el ? el.value.trim() : '';
			}

			var body = new URLSearchParams();
			body.append( 'action', 'wink_gatekeeper_test_credentials_aliyun_green' );
			body.append( 'nonce', greenProbeButton.getAttribute( 'data-nonce' ) || '' );
			body.append( 'green_access_key_id', greenFieldOrEmpty( 'wk-field-review-cloud-aliyun_green-access_key_id' ) );
			body.append( 'green_access_key_secret', greenFieldOrEmpty( 'wk-field-review-cloud-aliyun_green-access_key_secret' ) );
			body.append( 'green_service_nickname', greenFieldOrEmpty( 'wk-green-service-nickname' ) );
			body.append( 'green_service_comment', greenFieldOrEmpty( 'wk-green-service-comment' ) );

			window.fetch( window.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( payload ) {
				if ( ! result ) {
					return;
				}
				var data = payload && payload.data ? payload.data : null;
				if ( payload && payload.success && data ) {
					result.textContent = data.message || '';
					result.className = 'wk-probe wk-probe--' + ( data.state || 'gray' );
				} else {
					result.textContent = ( data && data.message ) ? data.message : '测试失败。';
					result.className = 'wk-probe wk-probe--gray';
				}
			} ).catch( function () {
				if ( result ) {
					result.textContent = '测试失败（网络错误）。';
					result.className = 'wk-probe wk-probe--gray';
				}
			} ).finally( function () {
				greenProbeButton.disabled = false;
			} );
		} );
	}

	/* -----------------------------------------------------------------
	 * B6 · Tab③ 百度「测试凭据」（03 Tab③ 同款模式：良性文本真调用三态——token 流含在内；
	 * 绿态在服务端顺带解除当日停转——页面不重载，灯状态下次刷新可见）。
	 * ----------------------------------------------------------------- */

	var baiduProbeButton = document.getElementById( 'wk-baidu-test-credentials' );
	if ( baiduProbeButton ) {
		baiduProbeButton.addEventListener( 'click', function () {
			var result = document.getElementById( 'wk-baidu-test-result' );
			baiduProbeButton.disabled = true;
			if ( result ) {
				result.textContent = '测试中……';
				result.className = '';
			}

			function baiduFieldOrEmpty( id ) {
				var el = document.getElementById( id );
				return el ? el.value.trim() : '';
			}

			var body = new URLSearchParams();
			body.append( 'action', 'wink_gatekeeper_test_credentials_baidu' );
			body.append( 'nonce', baiduProbeButton.getAttribute( 'data-nonce' ) || '' );
			body.append( 'baidu_api_key', baiduFieldOrEmpty( 'wk-field-review-cloud-baidu-api_key' ) );
			body.append( 'baidu_secret_key', baiduFieldOrEmpty( 'wk-field-review-cloud-baidu-secret_key' ) );

			window.fetch( window.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( payload ) {
				if ( ! result ) {
					return;
				}
				var data = payload && payload.data ? payload.data : null;
				if ( payload && payload.success && data ) {
					result.textContent = data.message || '';
					result.className = 'wk-probe wk-probe--' + ( data.state || 'gray' );
				} else {
					result.textContent = ( data && data.message ) ? data.message : '测试失败。';
					result.className = 'wk-probe wk-probe--gray';
				}
			} ).catch( function () {
				if ( result ) {
					result.textContent = '测试失败（网络错误）。';
					result.className = 'wk-probe wk-probe--gray';
				}
			} ).finally( function () {
				baiduProbeButton.disabled = false;
			} );
		} );
	}
}() );
