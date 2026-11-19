/**
 * Wink 门口保安 · 设置页后台交互集（03 §1/§4/§6；03 §6 后台操作安全约定）
 *
 * 【M6·批H4·H4-5】页头 logo 眨眼动效退役（用户拍板「使用静态版」——手测二轮 F1：
 * 4.2s 周期切帧+压缩在工作界面语境被感受为「一抖一抖」干扰；src 本就是静态版
 * logo-wink.svg，眨眼源属性已随本批移除。拦截页 130ms 单次眨眼为 M1 独立面不受
 * 影响）。
 * 【W3·批A·N7】新增 scheme 前端兜底/校验首段（design-system §02·P4——明暗跟随
 * WP 配色的前端侧；PHP 主路径定型+本段校验纠正）。本文件其余功能照旧：统计重置/
 * 文案工坊恢复默认/驱动下拉联动/各测试凭据探活/诊断面板/滑杆随动等。
 * 【W3·批B】八 Tab 渲染面重组绑定面注记：八驱动测试凭据钮/头像测试钮 id 锚零变化
 * （PHP 侧迁 test_credential_btn 五态徽标初态统一——JS 探活响应通道不变）；Tab②③
 * 驱动下拉 bindVendorSwitcher 现机制保持。
 * 【W3·批C】新增向导交互模块（R5 附案——stepper/面板切换纯显隐零提交语义+radio-card
 * .on 视觉同步+「下一步」提交前改写 _wp_http_referer 指向下一步；驱动/云审 radio 卡
 * 显隐联动；动图内联时序脚本随 PHP 步二面板自带、不在本文件）；region radio 零 JS
 * 联动面（前端容器 data-wk-region 由 PHP 渲染定型，保存后下次前台渲染生效——施工图
 * 批 C admin-brand.js 行的 region 联动项据此落空，偏差已登记）。
 * 【W3-FIX·0.8.6】新增测试凭据徽标全量回写共享件 wkProbeWriteBadges（W3F2 手测缺陷①：
 * 五态徽标 chip 与「上次测试」小字此前只有刷新后的服务端 probe_log 真值，AJAX 探活
 * 当场零回写；回写按 data-wk-driver-key 属性选择器 forEach 批量命中——向导页同屏
 * 同驱动徽标多副本〔步二凭据面+步四上岗引导复用同 id 口径〕场景全量正确）；九个
 * probe log 闭集驱动探活响应接线（avatar 结果不落库语境不入回写——N1 红线）。
 * 本文件内容变化 → Bootstrap 注册行版本参数后缀递进（-m6b1 → -w3a1 → -w3b1 → -w3c1 → -w3fix1）。
 */
( function () {
	'use strict';

	/* -----------------------------------------------------------------
	 * W3·N7 · scheme 前端兜底/校验（design-system §02·P4 双路径之前端侧）
	 *   PHP 主路径（SettingsPage::admin_color_scheme_class → .wk-wrap 根 class，
	 *   服务端定型免闪切换）渲染后，本段按 body.admin-color-{scheme} 校验容器
	 *   定型 class，不符则纠正（防第三方主题/缓存错位）。深浅集合表与
	 *   SettingsPage::ADMIN_COLOR_{DEEP,LIGHT}_SCHEMES 双写同源——两集合改动须
	 *   同批同步本段与 W3SchemeTokensTest。未知/缺 admin-color-* class → 不动
	 *   （PHP 侧恒暗色组保底已定型）。
	 * ----------------------------------------------------------------- */

	var wkWrap = document.querySelector( '.wk-wrap' );
	if ( wkWrap ) {
		var wkDeepSchemes = [ 'midnight', 'coffee', 'ectoplasm', 'ocean' ];
		var wkLightSchemes = [ 'fresh', 'light', 'blue', 'modern', 'sunrise' ];
		var wkSchemeMatch = /(?:^| )admin-color-([a-z0-9_-]+)(?: |$)/.exec( document.body.className || '' );
		if ( wkSchemeMatch ) {
			var wkSlug = wkSchemeMatch[ 1 ];
			var wkWant = '';
			if ( wkLightSchemes.indexOf( wkSlug ) !== -1 ) {
				wkWant = 'wk-scheme-light';
			} else if ( wkDeepSchemes.indexOf( wkSlug ) !== -1 ) {
				wkWant = 'wk-scheme-dark';
			} // 未知 scheme：不动（暗色保底已在 PHP 侧定型）。
			if ( wkWant ) {
				var wkCurrent = wkWrap.classList.contains( 'wk-scheme-light' )
					? 'wk-scheme-light'
					: ( wkWrap.classList.contains( 'wk-scheme-dark' ) ? 'wk-scheme-dark' : '' );
				if ( '' === wkCurrent ) {
					wkWrap.classList.add( wkWant ); // PHP 定型缺失=兜底补挂。
				} else if ( wkCurrent !== wkWant ) {
					wkWrap.classList.remove( wkCurrent ); // 校验不符=纠正。
					wkWrap.classList.add( wkWant );
				}
			}
		}
	}

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

	// 【M4UX UI-2-5】Tab④ 真实 IP 获取方式：仅 custom_header 显示自定义头名行
	// （PHP 初始态已按存量值 hidden；此处接管切换显隐——bindVendorSwitcher 同款模式）。
	( function () {
		var modeSelect = document.getElementById( 'wk-iprep-mode' );
		var headerRow  = document.getElementById( 'wk-iprep-custom-header-row' );
		if ( ! modeSelect || ! headerRow ) {
			return;
		}
		var syncHeaderRow = function () {
			headerRow.hidden = modeSelect.value !== 'custom_header';
		};
		modeSelect.addEventListener( 'change', syncHeaderRow );
		syncHeaderRow();
	} )();

	// 【M5·U4】Tab③ DeepSeek：仅 two_stage 开显示升级用模型行（custom_header 联动范式同款
	// ——PHP 初始态已按存量值 hidden，此处接管 checkbox change 显隐）。
	( function () {
		var twoStage = document.getElementById( 'wk-deepseek-two-stage' );
		var upgradeRow = document.getElementById( 'wk-deepseek-upgrade-model-row' );
		if ( ! twoStage || ! upgradeRow ) {
			return;
		}
		var syncUpgradeRow = function () {
			upgradeRow.hidden = ! twoStage.checked;
		};
		twoStage.addEventListener( 'change', syncUpgradeRow );
		syncUpgradeRow();
	} )();

	// 【M6·批E】Tab③ openai_custom：仅 two_stage 开显示升级用模型行（wk-deepseek-two-stage
	// 范式同款——PHP 初始态已按存量值 hidden，此处接管 checkbox change 显隐；升级模型为
	// 手填 text，留空=不升级由服务端基类守卫短路）。
	( function () {
		var twoStage = document.getElementById( 'wk-openai-custom-two-stage' );
		var upgradeRow = document.getElementById( 'wk-openai-custom-upgrade-model-row' );
		if ( ! twoStage || ! upgradeRow ) {
			return;
		}
		var syncUpgradeRow = function () {
			upgradeRow.hidden = ! twoStage.checked;
		};
		twoStage.addEventListener( 'change', syncUpgradeRow );
		syncUpgradeRow();
	} )();

	/* -----------------------------------------------------------------
	 * 【W3-FIX·0.8.6】测试凭据徽标全量回写共享件（W3F2 手测缺陷①）：
	 *   五态徽标 chip（.badge）与「上次测试」小字（.last-test）此前只有刷新后的
	 *   服务端真值（probe_log N1——SettingsPage::probe_state_badge 渲染），AJAX 探活
	 *   当场不回写。本件在各探活响应成功分支按 driver key 调用：data-wk-driver-key
	 *   属性选择器 forEach 批量命中全部同驱动徽标（向导页同屏同驱动徽标多副本——
	 *   步二凭据面 + 步四上岗引导复用同 id 口径——单 id 窄选择器对此场景必漏）。
	 *   五态词/徽标类与 SettingsPage::probe_state_badge 闭集双写同源（改动须同批
	 *   同步 W3FixW3F2Test 契约）；avatar 镜像探活不入回写（ok/slow/fail 态在五态
	 *   闭集外且结果不落库——N1 红线，徽标保持服务端「未测试」真值）。
	 * ----------------------------------------------------------------- */

	var WK_PROBE_BADGE_STATES = {
		green:  { badge: 'b-ok',   word: '贯通 · 凭据可用', short: '贯通' },
		yellow: { badge: 'b-warn', word: '缺字 · 必填缺失', short: '缺字' },
		red:    { badge: 'b-err',  word: '被拒 · 密钥错', short: '被拒' },
		gray:   { badge: 'b-dim',  word: '契约不符 · 状态未知', short: '契约不符' }
	};

	// 本地时刻 m月d日 H:i（服务端 date_i18n 'm月d日 H:i' 同形——上次测试小字当场值）。
	function wkProbeStamp() {
		var d = new Date();
		function p2( n ) {
			return ( n < 10 ? '0' : '' ) + n;
		}
		return p2( d.getMonth() + 1 ) + '月' + p2( d.getDate() ) + '日 ' + p2( d.getHours() ) + ':' + p2( d.getMinutes() );
	}

	function wkProbeWriteBadges( driverKey, state ) {
		var conf = WK_PROBE_BADGE_STATES[ state ];
		if ( ! conf || ! driverKey ) {
			return;
		}
		document.querySelectorAll( '[data-wk-driver-key="' + driverKey + '"]' ).forEach( function ( chip ) {
			var badge = chip.querySelector( '.badge' );
			var last  = chip.querySelector( '.last-test' );
			if ( badge ) {
				badge.className = 'badge ' + conf.badge;
				badge.textContent = conf.word;
			}
			if ( last ) {
				last.textContent = '上次测试：' + wkProbeStamp() + ' · ' + conf.short;
			}
		} );
	}

	// 【M5·U4】Tab③ DeepSeek「测试凭据」（GET /models 探活三态——D5 零 token 成本）。
	var deepseekProbeButton = document.getElementById( 'wk-deepseek-test-btn' );
	if ( deepseekProbeButton ) {
		deepseekProbeButton.addEventListener( 'click', function () {
			var result = document.getElementById( 'wk-deepseek-test-result' );
			deepseekProbeButton.disabled = true;
			if ( result ) {
				result.textContent = '测试中……';
				result.className = '';
			}
			function dsFieldOrEmpty( id ) {
				var el = document.getElementById( id );
				return el ? el.value.trim() : '';
			}
			var body = new URLSearchParams();
			body.append( 'action', 'wink_gatekeeper_test_credentials_deepseek' );
			body.append( 'nonce', deepseekProbeButton.getAttribute( 'data-nonce' ) || '' );
			body.append( 'deepseek_api_key', dsFieldOrEmpty( 'wk-field-review-cloud-deepseek-api_key' ) );
			body.append( 'deepseek_base_url', dsFieldOrEmpty( 'wk-deepseek-base-url' ) );
			body.append( 'deepseek_model', dsFieldOrEmpty( 'wk-deepseek-model' ) );

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
					var dsStateClass = { green: 'wk-probe--green', yellow: 'wk-probe--yellow', red: 'wk-probe--red' };
					result.className = 'wk-probe ' + ( dsStateClass[ data.state ] || 'wk-probe--gray' );
					wkProbeWriteBadges( 'deepseek', data.state );
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
				deepseekProbeButton.disabled = false;
			} );
		} );
	}

	// 【M6·批E】Tab③ openai_custom「测试凭据」（GET /models 三态探活——泛端点无标准
	// 余额 API，零 token 消耗；表单值覆盖=password 空串回落已存值语义；判定模型随表
	// 提交供绿态列表校验）。
	var openaiCustomProbeButton = document.getElementById( 'wk-openai-custom-test-btn' );
	if ( openaiCustomProbeButton ) {
		openaiCustomProbeButton.addEventListener( 'click', function () {
			var result = document.getElementById( 'wk-openai-custom-test-result' );
			openaiCustomProbeButton.disabled = true;
			if ( result ) {
				result.textContent = '测试中……';
				result.className = '';
			}
			function ocFieldOrEmpty( id ) {
				var el = document.getElementById( id );
				return el ? el.value.trim() : '';
			}
			var body = new URLSearchParams();
			body.append( 'action', 'wink_gatekeeper_test_credentials_openai_custom' );
			body.append( 'nonce', openaiCustomProbeButton.getAttribute( 'data-nonce' ) || '' );
			body.append( 'openai_custom_api_key', ocFieldOrEmpty( 'wk-field-review-cloud-openai-custom-api_key' ) );
			body.append( 'openai_custom_base_url', ocFieldOrEmpty( 'wk-openai-custom-base-url' ) );
			body.append( 'openai_custom_model', ocFieldOrEmpty( 'wk-openai-custom-model' ) );

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
					var ocStateClass = { green: 'wk-probe--green', yellow: 'wk-probe--yellow', red: 'wk-probe--red' };
					result.className = 'wk-probe ' + ( ocStateClass[ data.state ] || 'wk-probe--gray' );
					wkProbeWriteBadges( 'openai_custom', data.state );
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
				openaiCustomProbeButton.disabled = false;
			} );
		} );
	}

	// 【M6·批B·B-3】Tab③ 网址核查独立凭据「测试凭据」（N4 范围边界——DeepSeek 协议族
	// 同用余额探测，base_url 跟随其配置；表单值覆盖=password 空串回落已存值语义）。
	var urlAuditProbeButton = document.getElementById( 'wk-url-audit-provider-test-btn' );
	if ( urlAuditProbeButton ) {
		urlAuditProbeButton.addEventListener( 'click', function () {
			var result = document.getElementById( 'wk-url-audit-provider-test-result' );
			urlAuditProbeButton.disabled = true;
			if ( result ) {
				result.textContent = '测试中……';
				result.className = '';
			}
			function uaFieldOrEmpty( id ) {
				var el = document.getElementById( id );
				return el ? el.value.trim() : '';
			}
			var body = new URLSearchParams();
			body.append( 'action', 'wink_gatekeeper_test_credentials_url_audit' );
			body.append( 'nonce', urlAuditProbeButton.getAttribute( 'data-nonce' ) || '' );
			body.append( 'url_audit_provider_api_key', uaFieldOrEmpty( 'wk-field-url-audit-provider-api_key' ) );
			body.append( 'url_audit_provider_base_url', uaFieldOrEmpty( 'wk-url-audit-provider-base-url' ) );

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
					var uaStateClass = { green: 'wk-probe--green', yellow: 'wk-probe--yellow', red: 'wk-probe--red' };
					result.className = 'wk-probe ' + ( uaStateClass[ data.state ] || 'wk-probe--gray' );
					wkProbeWriteBadges( 'url_audit_provider', data.state );
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
				urlAuditProbeButton.disabled = false;
			} );
		} );
	}

	// 【M5·U2】Tab⑧ 卡二头像源：仅 custom 显示自定义地址行（custom_header 联动范式同款
	// ——PHP 初始态已按存量值 hidden，此处接管切换显隐）。
	( function () {
		var avatarSelect = document.getElementById( 'wk-avatar-source-select' );
		var customRow    = document.getElementById( 'wk-avatar-custom-base-row' );
		if ( ! avatarSelect || ! customRow ) {
			return;
		}
		var syncCustomRow = function () {
			customRow.hidden = avatarSelect.value !== 'custom';
		};
		avatarSelect.addEventListener( 'change', syncCustomRow );
		syncCustomRow();
	} )();

	// 【M5 修复批·D4】Tab③ 网址核查判定凭证：仅独立凭证（custom）显示三行独立配置
	// （api_key/base_url/model——custom_header 联动范式同款；PHP 初始态已按存量值
	// hidden，此处接管切换显隐。回落 inherit 时三行整组隐藏）。
	( function () {
		var providerMode = document.getElementById( 'wk-url-audit-provider-mode' );
		var providerRows = document.getElementById( 'wk-url-audit-provider-rows' );
		if ( ! providerMode || ! providerRows ) {
			return;
		}
		var syncProviderRows = function () {
			providerRows.hidden = providerMode.value !== 'custom';
		};
		providerMode.addEventListener( 'change', syncProviderRows );
		syncProviderRows();
	} )();

	// 【M6·批D·D-2】Tab③ 网页抓取服务商：仅「DeepSeek 网页抓取」（deepseek）档显示
	// 「抓取专用 DeepSeek Key」行（provider rows 联动范式同款；PHP 初始态已按存量值
	// hidden，此处接管切换显隐；留空=跟随 AI 判定凭证）。
	( function () {
		var fetcherMode = document.getElementById( 'wk-url-audit-fetcher-mode' );
		var fetcherRows = document.getElementById( 'wk-url-audit-fetcher-rows' );
		if ( ! fetcherMode || ! fetcherRows ) {
			return;
		}
		var syncFetcherRows = function () {
			fetcherRows.hidden = fetcherMode.value !== 'deepseek';
		};
		fetcherMode.addEventListener( 'change', syncFetcherRows );
		syncFetcherRows();
	} )();

	// 【M5·U2】Tab⑧ 卡二「测试当前头像来源」（三态：绿=正常/黄=能用但慢/红=连不上——
	// 测的是镜像通不通非密钥；服务端 RemoteClient 探测零计量零事件）。
	var avatarProbeButton = document.getElementById( 'wk-avatar-test-btn' );
	if ( avatarProbeButton ) {
		avatarProbeButton.addEventListener( 'click', function () {
			var result = document.getElementById( 'wk-avatar-test-result' );
			avatarProbeButton.disabled = true;
			if ( result ) {
				result.textContent = '测试中……';
				result.className = '';
			}
			var sourceSelect = document.getElementById( 'wk-avatar-source-select' );
			var customInput  = document.getElementById( 'wk-avatar-custom-base' );
			var body = new URLSearchParams();
			body.append( 'action', 'wink_gatekeeper_test_avatar_source' );
			body.append( 'nonce', avatarProbeButton.getAttribute( 'data-nonce' ) || '' );
			body.append( 'source', sourceSelect ? sourceSelect.value : 'official' );
			body.append( 'custom_base', customInput ? customInput.value.trim() : '' );

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
					var stateClass = { ok: 'wk-probe--green', slow: 'wk-probe--yellow', fail: 'wk-probe--red', yellow: 'wk-probe--yellow' };
					result.className = 'wk-probe ' + ( stateClass[ data.state ] || 'wk-probe--gray' );
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
				avatarProbeButton.disabled = false;
			} );
		} );
	}

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
					wkProbeWriteBadges( 'tencent', data.state );
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
					wkProbeWriteBadges( 'aliyun', data.state );
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
					wkProbeWriteBadges( 'tencent_tms', data.state );
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
					wkProbeWriteBadges( 'aliyun_green', data.state );
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
					wkProbeWriteBadges( 'baidu', data.state );
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

	/* -----------------------------------------------------------------
	 * I0 · Tab④ 诊断面板「手动聚合查询」（M3-IP 定稿 §5.1：站长输 IP → 并行四源卡片——
	 * 威胁组 AbuseIPDB/StopForumSpam/ip.nc.gy + 地理组 ip.sb。结果纯请求态渲染：
	 * 不落库不缓存不计调用次数；任一源失败仅该卡片失败。
	 * ----------------------------------------------------------------- */

	var ipDiagButton = document.getElementById( 'wk-ip-diag-query' );
	if ( ipDiagButton ) {
		var ipDiagSourceLabels = {
			abuseipdb: 'AbuseIPDB（威胁组）',
			stopforumspam: 'StopForumSpam（威胁组）',
			ipncgy: 'ip.nc.gy（威胁组）',
			ipsb: 'ip.sb（地理组）'
		};
		var ipDiagOrder = [ 'abuseipdb', 'stopforumspam', 'ipncgy', 'ipsb' ];

		/* 【P0-1·F1 批·安全修复】远端 API 返回的任意串（ISP/ASN/地区/错误文案）一律经
		 * textContent 注入——innerHTML 拼接是管理态反射面（响应被劫持/被黑即管理员浏览器
		 * 执行脚本）。主收口=本函数 DOM API 组装；PHP 侧 IpDiagnostic::scalar_str 预转义
		 * 为纵深防御（双层独立收口）。渲染结果与旧实现同形（结构/类名零变化）。 */
		function ipDiagRenderCard( sourceId, card ) {
			var el = document.createElement( 'div' );
			el.className = 'wk-ip-diag-card' + ( card && card.ok ? '' : ' wk-ip-diag-card--fail' );
			el.id = 'wk-ip-diag-card-' + sourceId;

			var heading = document.createElement( 'h4' );
			heading.textContent = ipDiagSourceLabels[ sourceId ] || sourceId;
			el.appendChild( heading );

			if ( card && card.ok ) {
				var list = document.createElement( 'dl' );
				list.className = 'wk-ip-diag-rows';
				Object.keys( card.rows || {} ).forEach( function ( key ) {
					var term = document.createElement( 'dt' );
					term.textContent = key;
					var detail = document.createElement( 'dd' );
					detail.textContent = card.rows[ key ] || '—';
					list.appendChild( term );
					list.appendChild( detail );
				} );
				el.appendChild( list );
			} else {
				var errorLine = document.createElement( 'p' );
				errorLine.className = 'wk-ip-diag-error';
				errorLine.textContent = ( card && card.error ) || '查询失败。';
				el.appendChild( errorLine );
			}
			return el;
		}

		ipDiagButton.addEventListener( 'click', function () {
			var input = document.getElementById( 'wk-ip-diag-ip' );
			var out = document.getElementById( 'wk-ip-diag-result' );
			var status = document.getElementById( 'wk-ip-diag-status' );
			var ip = input ? input.value.trim() : '';
			if ( ! ip ) {
				if ( status ) {
					status.textContent = '请先输入要查询的 IP。';
				}
				return;
			}

			ipDiagButton.disabled = true;
			if ( status ) {
				status.textContent = '查询中……（3 秒超时/源，最长约 12 秒）';
			}
			if ( out ) {
				out.textContent = '';
			}

			var body = new URLSearchParams();
			body.append( 'action', 'wink_gatekeeper_ip_diagnose' );
			body.append( 'nonce', ipDiagButton.getAttribute( 'data-nonce' ) || '' );
			body.append( 'ip', ip );

			window.fetch( window.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( payload ) {
				if ( out ) {
					out.textContent = '';
				}
				if ( ! payload || ! payload.success ) {
					if ( status ) {
						status.textContent = ( payload && payload.data && payload.data.message ) ? payload.data.message : '查询失败。';
					}
					return;
				}
				if ( status ) {
					status.textContent = '已完成：' + ( payload.data.ip || ip );
				}
				var cards = ( payload.data && payload.data.cards ) || {};
				if ( out ) {
					ipDiagOrder.forEach( function ( sourceId ) {
						out.appendChild( ipDiagRenderCard( sourceId, cards[ sourceId ] ) );
					} );
				}
			} ).catch( function () {
				if ( status ) {
					status.textContent = '查询失败（网络错误）。';
				}
			} ).finally( function () {
				ipDiagButton.disabled = false;
			} );
		} );
	}

	/* -----------------------------------------------------------------
	 * I4 · Tab④ 分区②「测试凭据」（M3-IP 定稿 §6【O5 回写·P2-6】：最小 check 查询
	 * 三态——绿=HTTP 200 且 JSON 可解析（isPublic:false 亦绿，验证的是凭据不是 IP）/
	 * 黄=API Key 空（服务端本地预检）/红=401/402 错钥/灰=429·传输·契约。Tab② 先例
	 * 同款交互：结果缓存在按钮旁，不落库。）
	 * ----------------------------------------------------------------- */

	var iprepProbeButton = document.getElementById( 'wk-iprep-test-credentials' );
	if ( iprepProbeButton ) {
		iprepProbeButton.addEventListener( 'click', function () {
			var result = document.getElementById( 'wk-iprep-test-result' );
			iprepProbeButton.disabled = true;
			if ( result ) {
				result.textContent = '测试中……';
				result.className = '';
			}

			function iprepFieldOrEmpty( id ) {
				var el = document.getElementById( id );
				return el ? el.value.trim() : '';
			}

			var body = new URLSearchParams();
			body.append( 'action', 'wink_gatekeeper_test_iprep' );
			body.append( 'nonce', iprepProbeButton.getAttribute( 'data-nonce' ) || '' );
			body.append( 'api_key', iprepFieldOrEmpty( 'wk-field-iprep-abuseipdb-api_key' ) );

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
					wkProbeWriteBadges( 'abuseipdb', data.state );
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
				iprepProbeButton.disabled = false;
			} );
		} );
	}

	/* -----------------------------------------------------------------
	 * I4 · Tab④ 分区①「校验网段格式」（前端格式预检——每行一条 CIDR 或裸 IP；
	 * 非法行保存时由服务端解析层跳过并在诊断面板标出，此处仅即时反馈）
	 * + 阈值双滑杆 output 数值随动（无 JS 时 output 显示服务器渲染值，不阻塞）。
	 * ----------------------------------------------------------------- */

	/* 行格式校验共享件（I5：受信网段与白名单两处 textarea 复用同一校验——每行一条
	 * CIDR 或裸 IP，粗检与服务端 TrustedProxies::parse_cidr 同口径，服务端为权威）。 */
	function wkValidV4( ip ) {
		var parts = ip.split( '.' );
		if ( parts.length !== 4 ) {
			return false;
		}
		for ( var i = 0; i < 4; i++ ) {
			if ( ! /^\d{1,3}$/.test( parts[ i ] ) || parseInt( parts[ i ], 10 ) > 255 ) {
				return false;
			}
		}
		return true;
	}

	function wkValidV6( ip ) {
		// 粗检（服务端 inet_pton 为权威）：冒号在场、十六进制段合法、双冒号至多一个。
		if ( ! /^[0-9a-fA-F:]+$/.test( ip ) || ip.indexOf( ':' ) === -1 ) {
			return false;
		}
		return ip.split( '::' ).length <= 2;
	}

	function wkCheckCidrLines( raw ) {
		var lines = raw.split( /\r\n|\r|\n/ );
		var bad = [];
		var good = 0;
		lines.forEach( function ( line ) {
			var entry = line.trim();
			if ( ! entry ) {
				return;
			}
			var slash = entry.indexOf( '/' );
			var ip = slash === -1 ? entry : entry.slice( 0, slash );
			var prefix = slash === -1 ? null : entry.slice( slash + 1 );
			var ok = wkValidV4( ip ) || wkValidV6( ip );
			if ( ok && prefix !== null ) {
				if ( ! /^\d+$/.test( prefix ) ) {
					ok = false;
				} else if ( ip.indexOf( ':' ) === -1 && parseInt( prefix, 10 ) > 32 ) {
					ok = false;
				} else if ( ip.indexOf( ':' ) !== -1 && parseInt( prefix, 10 ) > 128 ) {
					ok = false;
				}
			}
			if ( ok ) {
				good++;
			} else {
				bad.push( entry );
			}
		} );
		return { good: good, bad: bad };
	}

	function wkRenderCidrResult( out, checked ) {
		if ( ! out ) {
			return;
		}
		if ( checked.bad.length === 0 ) {
			out.textContent = '全部 ' + checked.good + ' 行格式合法。';
			out.className = 'wk-muted';
		} else {
			out.textContent = '有 ' + checked.bad.length + ' 行不是合法 CIDR/IP：' + checked.bad.slice( 0, 3 ).join( '、' ) + ( checked.bad.length > 3 ? ' 等' : '' ) + '。';
			out.className = 'wk-probe wk-probe--yellow';
		}
	}

	var cidrButton = document.getElementById( 'wk-iprep-cidr-check' );
	if ( cidrButton ) {
		cidrButton.addEventListener( 'click', function () {
			var input = document.getElementById( 'wk-iprep-custom-cidr' );
			var out = document.getElementById( 'wk-iprep-cidr-result' );
			wkRenderCidrResult( out, wkCheckCidrLines( input ? input.value : '' ) );
		} );
	}

	/* I5 · Tab④ 分区① 白名单「校验白名单格式」——同一共享校验件复用。 */
	var whitelistButton = document.getElementById( 'wk-iprep-whitelist-check' );
	if ( whitelistButton ) {
		whitelistButton.addEventListener( 'click', function () {
			var input = document.getElementById( 'wk-iprep-whitelist' );
			var out = document.getElementById( 'wk-iprep-whitelist-result' );
			wkRenderCidrResult( out, wkCheckCidrLines( input ? input.value : '' ) );
		} );
	}

	Array.prototype.forEach.call(
		document.querySelectorAll( 'input[type="range"].wk-score-slider' ),
		function ( slider ) {
			slider.addEventListener( 'input', function () {
				var out = slider.nextElementSibling;
				if ( out && out.tagName === 'OUTPUT' ) {
					out.textContent = slider.value;
				}
			} );
		}
	);

	/* -----------------------------------------------------------------
	 * 【W3·批C·R5】首启向导交互模块（纯显隐零提交语义——保存仍走 wk-form 主通道）：
	 *   ① stepper 点击/面板切换（li[data-wk-goto] ↔ section[data-wk-step]）；
	 *   ② radio-card 视觉 .on 同步（checked 变化时组内互斥重绘）；
	 *   ③ 驱动/云审 radio 卡 → 对应凭据面 .wizard-frow 显隐（data-wk-driver-toggle /
	 *      data-wk-chain-toggle ↔ data-wk-driver-face / data-wk-chain-face）；
	 *   ④ 「下一步」提交前改写 #wk-wizard-referer（_wp_http_referer）指向目标步——
	 *      每步落盘、保存后落在下一步（options.php 按 _wp_http_referer 回跳）。
	 * 无 JS 降级：非当前步面板由 PHP 侧 hidden，步进钮照常提交保存（落在当前步）。
	 * ----------------------------------------------------------------- */
	( function () {
		var wizard = document.getElementById( 'wk-wizard' );
		if ( ! wizard ) {
			return;
		}

		function showStep( n ) {
			wizard.setAttribute( 'data-wk-current', String( n ) );
			wizard.querySelectorAll( '[data-wk-step]' ).forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-wk-step' ) !== String( n );
			} );
			wizard.querySelectorAll( '.wk-stepper [data-wk-goto]' ).forEach( function ( li ) {
				li.classList.toggle( 'on', li.getAttribute( 'data-wk-goto' ) === String( n ) );
			} );
			if ( wizard.scrollIntoView ) {
				try { wizard.scrollIntoView( { block: 'start', behavior: 'smooth' } ); } catch ( err ) {}
			}
		}

		// ① stepper 点击（纯显隐——不提交；未保存的表单输入保留在 DOM 中）。
		wizard.querySelectorAll( '.wk-stepper [data-wk-goto]' ).forEach( function ( li ) {
			li.addEventListener( 'click', function () {
				showStep( parseInt( li.getAttribute( 'data-wk-goto' ), 10 ) || 1 );
			} );
		} );

		// ② radio-card .on 视觉同步（组内互斥——checked 变化重绘组内全部卡）。
		wizard.querySelectorAll( '[data-wk-radio-group]' ).forEach( function ( group ) {
			group.addEventListener( 'change', function ( event ) {
				if ( ! event.target || ! event.target.checked ) {
					return;
				}
				group.querySelectorAll( '.radio-card' ).forEach( function ( card ) {
					card.classList.remove( 'on' );
				} );
				var card = event.target.closest( '.radio-card' );
				if ( card ) {
					card.classList.add( 'on' );
				}
			} );
		} );

		// ③ 驱动/云审 radio 卡 → 凭据面显隐（face 全 hidden，再显当前选中项；none/'tencent' 说明行同理）。
		function bindFaceToggles( toggleAttr, faceAttr, noneKey ) {
			var toggles = wizard.querySelectorAll( '[data-' + toggleAttr + ']' );
			if ( toggles.length === 0 ) {
				return;
			}
			var faces = wizard.querySelectorAll( '[data-' + faceAttr + ']' );
			toggles.forEach( function ( radio ) {
				radio.addEventListener( 'change', function () {
					if ( ! radio.checked ) {
						return;
					}
					var key = radio.getAttribute( 'data-' + toggleAttr );
					faces.forEach( function ( face ) {
						face.hidden = face.getAttribute( 'data-' + faceAttr ) !== key;
					} );
				} );
			} );
			// 初始态按 checked 归一（PHP 已带 hidden——防御性同步）。
			toggles.forEach( function ( radio ) {
				if ( radio.checked ) {
					var key = radio.getAttribute( 'data-' + toggleAttr );
					faces.forEach( function ( face ) {
						face.hidden = face.getAttribute( 'data-' + faceAttr ) !== key;
					} );
				}
			} );
		}
		bindFaceToggles( 'wk-driver-toggle', 'wk-driver-face', 'none' );
		bindFaceToggles( 'wk-chain-toggle', 'wk-chain-face', 'none' );

		// ④ 「下一步」提交前改写 referer（options.php 保存后回跳目标步）。
		var referer = document.getElementById( 'wk-wizard-referer' );
		if ( referer ) {
			wizard.querySelectorAll( 'button[data-wk-wizard-next]' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var step = parseInt( btn.getAttribute( 'data-wk-wizard-next' ), 10 );
					var base = wizard_url_base();
					if ( base ) {
						referer.value = base + '&step=' + ( step || 1 );
					}
				} );
			} );
		}
		function wizard_url_base() {
			// 由当前 URL 派生：去掉旧 step 参数（保留 wizard=1 与 page 等其余参数）。
			return window.location.href.split( '#' )[ 0 ].replace( /([?&])step=\d+/, '$1' );
		}
	}() );
}() );
