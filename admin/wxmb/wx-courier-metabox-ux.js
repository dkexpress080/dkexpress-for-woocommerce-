/* WebExpert courier plugins — Improved UX order metabox (shared, v1). */
(function ($) {
	'use strict';

	if (window.wxCourierMetaboxUXLoaded) { return; }
	window.wxCourierMetaboxUXLoaded = true;

	/* wxPdf (shared, v2): a label opens as a named viewer page (Print / Download PDF), not a bare blob. Identical in every courier plugin. */
	window.wxPdf = window.wxPdf || (function () {
		var pages = {};
		var el = /^el/i.test(document.documentElement.lang || '');
		var T = el
			? { print: 'Εκτύπωση', dl: 'Λήψη PDF', open: 'Άνοιγμα', blocked: 'Ο browser μπλόκαρε το νέο παράθυρο. Το voucher είναι έτοιμο:' }
			: { print: 'Print', dl: 'Download PDF', open: 'Open', blocked: 'Your browser blocked the new window. The voucher is ready:' };
		var I = {
			print: '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" d="M6 9V3h12v6M6 18H4a1 1 0 0 1-1-1v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6a1 1 0 0 1-1 1h-2M7 14h10v7H7z"/></svg>',
			dl: '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" d="M12 3v12m-5-5 5 5 5-5M4 21h16"/></svg>',
			open: '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>'
		};
		var BTN = 'display:inline-flex;align-items:center;gap:6px;min-height:36px;padding:0 14px;border-radius:4px;border:1px solid #2271b1;font:600 13px/1 -apple-system,Segoe UI,Roboto,sans-serif;text-decoration:none;cursor:pointer;white-space:nowrap';
		function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); }
		function clean(n) {
			n = String(n || 'voucher').replace(/[\\\/:*?"<>|\s]+/g, '-');
			return /\.pdf$/i.test(n) ? n : n + '.pdf';
		}
		function url(b64, filename) {
			var bin = atob(b64), len = bin.length, bytes = new Uint8Array(len);
			for (var i = 0; i < len; i++) { bytes[i] = bin.charCodeAt(i); }
			var pdf = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
			var name = clean(filename);
			var html = '<!doctype html><html lang="' + (el ? 'el' : 'en') + '"><head><meta charset="utf-8"><title>' + esc(name) + '</title>' +
				'<style>html,body{margin:0;height:100%;background:#525659;font:14px/1.4 -apple-system,"Segoe UI",Roboto,sans-serif}' +
				'header{box-sizing:border-box;height:56px;display:flex;align-items:center;gap:10px;padding:0 16px;background:#fff;color:#1d2327;border-bottom:1px solid #c3c4c7}' +
				'header b{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:15px}' +
				'.b{' + BTN + '}.s{background:#fff;color:#2271b1}.p{background:#2271b1;color:#fff}' +
				'.s:hover,.s:focus-visible{background:#f0f6fc}.p:hover,.p:focus-visible{background:#135e96;border-color:#135e96}' +
				'.b:focus-visible{outline:2px solid #2271b1;outline-offset:2px}' +
				'iframe{display:block;border:0;width:100%;height:calc(100% - 56px)}</style></head><body>' +
				'<header><b>' + esc(name) + '</b>' +
				'<a class="b s" href="' + pdf + '" download="' + esc(name) + '">' + I.dl + T.dl + '</a>' +
				'<button class="b p" type="button" onclick="document.getElementById(\'wxpdf\').contentWindow.print()">' + I.print + T.print + '</button></header>' +
				'<iframe id="wxpdf" src="' + pdf + '" title="' + esc(name) + '"></iframe></body></html>';
			var page = URL.createObjectURL(new Blob([html], { type: 'text/html' }));
			pages[page] = { pdf: pdf, name: name };
			return page;
		}
		/* Popup-blocked notice body (message + Open / Download PDF buttons) for a page from url(); '' for any other URL. */
		function blocked(page) {
			var p = pages[page];
			if (!p) { return ''; }
			return '<span style="display:block">' + T.blocked + '</span>' +
				'<span style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">' +
				'<a href="' + page + '" target="_blank" style="' + BTN + ';min-height:30px;padding:0 10px;font-size:12px;background:#2271b1;color:#fff">' + I.open + T.open + '</a>' +
				'<a href="' + p.pdf + '" download="' + esc(p.name) + '" style="' + BTN + ';min-height:30px;padding:0 10px;font-size:12px;background:#fff;color:#2271b1">' + I.dl + T.dl + '</a>' +
				'</span>';
		}
		/* Box for the classic metaboxes (WordPress warning style). */
		var BOX = 'display:block;margin-top:10px;padding:10px 12px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #dba617;border-radius:4px;font-size:12.5px;line-height:1.45;color:#1d2327';
		return { url: url, blocked: blocked, box: BOX };
	})();

	var ajaxUrl = (window.wxCourierMetaboxUX && window.wxCourierMetaboxUX.ajaxUrl) || window.ajaxurl;

	function cfg($root) {
		try { return JSON.parse($root.attr('data-wxmb') || '{}'); } catch (e) { return {}; }
	}

	function notice($root, type, html, sticky) {
		var $n = $('<div class="wxmb-notice wxmb-notice-' + type + '"><span></span><button type="button" class="wxmb-x" aria-label="Dismiss">&times;</button></div>');
		$n.find('span').html(html);
		$root.find('.wxmb-notices').empty().append($n);
		if (!sticky) { setTimeout(function () { $n.fadeOut(200, function () { $(this).remove(); }); }, 6000); }
	}

	function busy($btn, on) {
		$btn.toggleClass('is-busy', !!on).prop('disabled', !!on);
	}

	function errorText(resp, c) {
		if (!resp) { return c.strings.error; }
		if (typeof resp === 'string') { return resp === 'success' ? '' : resp; }
		if (resp.data) {
			if (typeof resp.data === 'string') { return resp.data; }
			if (resp.data.message) { return resp.data.message; }
			if (resp.data.error) { return resp.data.error; }
		}
		if (resp.error) { return resp.error; }
		return c.strings.error;
	}

	function isLicenseError(resp) {
		return resp && resp.data && resp.data.license_error === true;
	}

	function post(data) {
		return $.ajax({ type: 'post', url: ajaxUrl, data: data, dataType: 'json' });
	}

	function refresh($root, c, message, type) {
		return post({ action: c.ajax.refresh, order_id: c.orderId, nonce2: c.nonce2 }).done(function (resp) {
			if (resp && resp.success && resp.data && resp.data.html) {
				swap($root, resp.data, message, type);
			} else {
				window.location.reload();
			}
		}).fail(function () { window.location.reload(); });
	}

	/* Move the hidden header fragment (logo + title + status chip) into the postbox title. */
	function applyHead($head) {
		var $h = $head.closest('.postbox').find('.postbox-header .hndle').first();
		if (!$h.length) { return; }
		$h.find('.wxmb-title, .wxmb-chip').remove();
		$h.contents().filter(function () { return this.nodeType === 3 || (this.nodeType === 1 && !$(this).is('.wxmb-title, .wxmb-chip')); }).remove();
		$h.append($head.children().clone());
	}

	function swap($root, data, message, type) {
		var $new = $(data.html);
		$root.replaceWith($new);
		$new.find('.wxmb-head').each(function () { applyHead($(this)); });
		// Keep the order form's Tracking number in step, or Update saves the stale value over the voucher.
		if (typeof data.tracking === 'string') { $('#_shipping_tracking_number').val(data.tracking); }
		if (message) { notice($new, type || 'ok', message); }
	}

	$(function () {
		$('.wxmb-head').each(function () { applyHead($(this)); });
	});

	function collect($root, c) {
		var out = {};
		$.each(c.createFields || {}, function (postKey, fieldKey) {
			var $el = $root.find('[data-field="' + fieldKey + '"]');
			if (!$el.length) { return; }
			if ($el.is('select[multiple]')) {
				out[postKey] = $el.val() || [];
			} else if ($el.is(':checkbox')) {
				// data-array="1": post as a one-element array (plugins reading services[]);
				// otherwise post the value ('1') or '0'.
				var on = $el.is(':checked'), val = $el.val() || '1';
				if ($el.data('array')) { out[postKey] = on ? [val] : []; } else { out[postKey] = on ? val : '0'; }
			} else {
				out[postKey] = $el.val();
			}
		});
		return out;
	}

	function openUrl(url, $root, c) {
		var w = window.open(url, '_blank');
		if (!w || w.closed || typeof w.closed === 'undefined') {
			notice($root, 'warn', window.wxPdf.blocked(url) || (c.strings.popup + ' <a href="' + url + '" target="_blank" rel="noopener">' + c.strings.popupLink + '</a>'), true);
		} else {
			notice($root, 'ok', c.strings.printed);
		}
	}

	function openBase64(b64, $root, c, filename) {
		try {
			openUrl(window.wxPdf.url(b64, filename), $root, c);
		} catch (e) {
			notice($root, 'err', c.strings.noPrint, true);
		}
	}

	function handlePrint(resp, $root, c) {
		var urls = [];
		switch (c.printResult) {
			case 'success_url':
				if (resp && resp.success && typeof resp.data === 'string') { urls = [resp.data]; }
				break;
			case 'success_url_or_list':
				if (resp && resp.success) { urls = $.isArray(resp.data) ? resp.data : (typeof resp.data === 'string' ? [resp.data] : []); }
				break;
			case 'files':
				if (resp && resp.files && resp.files.length) { $.each(resp.files, function (i, f) { if (f && f.url) { urls.push(f.url); } }); }
				break;
			case 'upload':
				if (resp && resp.url) { urls = [resp.url]; }
				break;
			case 'string_url':
				if (typeof resp === 'string' && /^https?:\/\//.test(resp)) { urls = [resp]; }
				break;
			case 'success_base64':
				// Labels come back as base64 and open as blobs; nothing is written to uploads.
				if (resp && resp.success && resp.data && resp.data.Voucher) { openBase64(resp.data.Voucher, $root, c, resp.data.Filename); return; }
				if (resp && resp.success && resp.data && $.isArray(resp.data.Vouchers) && resp.data.Vouchers.length) {
					$.each(resp.data.Vouchers, function (i, b64) { openBase64(b64, $root, c, (resp.data.Filenames || [])[i]); });
					return;
				}
				break;
		}
		if (urls.length) {
			$.each(urls, function (i, u) { openUrl(u, $root, c); });
		} else {
			var msg = errorText(resp, c) || c.strings.noPrint;
			notice($root, 'err', msg, true);
		}
	}

	/* ---- events -------------------------------------------------------- */

	$(document)
		.on('click', '.wxmb .wxmb-x', function () {
			$(this).closest('.wxmb-notice').remove();
		})
		.on('click', '.wxmb .wxmb-step', function () {
			var $in = $(this).siblings('input'), v = parseInt($in.val(), 10) || 1;
			v = Math.max(1, v + parseInt($(this).data('step'), 10));
			$in.val(v).trigger('change');
		})
		.on('click', '.wxmb .wxmb-chip-btn[data-code]', function () {
			var $b = $(this);
			if ($b.hasClass('lock')) { return; }
			var $root = $b.closest('.wxmb'), on = !$b.hasClass('on');
			$b.toggleClass('on', on).attr('aria-pressed', on ? 'true' : 'false');
			var $sel = $root.find('#' + $b.closest('.wxmb-chips').data('target'));
			$sel.find('option[value="' + $b.data('code') + '"]').prop('selected', on);
			var $sub = $root.find('.wxmb-sub[data-service="' + $b.data('code') + '"]');
			if ($sub.length) { $sub.prop('hidden', !on); if (!on) { $sub.find('input').val(''); } }
		})
		.on('click', '.wxmb .wxmb-more', function () {
			var $b = $(this), $more = $b.siblings('.wxmb-chips-more');
			var open = $more.prop('hidden');
			$more.prop('hidden', !open);
			$b.toggleClass('open', open);
		})
		.on('change', '.wxmb .wxmb-service-check', function () {
			var $c = $(this), $root = $c.closest('.wxmb');
			$root.find('.wxmb-hidden-select option[value="' + $c.data('code') + '"]').prop('selected', $c.is(':checked'));
		})
		.on('click', '.wxmb .wxmb-copy', function () {
			var $b = $(this), text = $b.data('copy') + '';
			var done = function () { $b.addClass('is-copied'); setTimeout(function () { $b.removeClass('is-copied'); }, 1500); };
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done);
			} else {
				var ta = $('<textarea>').val(text).appendTo('body').select();
				try { document.execCommand('copy'); done(); } catch (e) {}
				ta.remove();
			}
		})
		.on('click', '.wxmb .wxmb-toggle-events', function (e) {
			e.preventDefault();
			var $ul = $(this).closest('.wxmb-info').find('.wxmb-events');
			$ul.prop('hidden', !$ul.prop('hidden'));
		})
		.on('click', '.wxmb .wxmb-caret, .wxmb .wxmb-kebab', function (e) {
			e.preventDefault();
			var $b = $(this), $m = $b.siblings('.wxmb-menu');
			var open = $m.prop('hidden');
			$('.wxmb .wxmb-menu').prop('hidden', true);
			$('.wxmb .wxmb-caret, .wxmb .wxmb-kebab').attr('aria-expanded', 'false');
			$m.prop('hidden', !open);
			$b.attr('aria-expanded', open ? 'true' : 'false');
		})
		.on('click', function (e) {
			if (!$(e.target).closest('.wxmb-menu, .wxmb-caret, .wxmb-kebab').length) {
				$('.wxmb .wxmb-menu').prop('hidden', true);
			}
		})

		/* Create */
		.on('click', '.wxmb .wxmb-create', function () {
			var $btn = $(this), $root = $btn.closest('.wxmb'), c = cfg($root);
			if (!c.ajax || !c.ajax.create) { return; }
			var data = $.extend({ action: c.ajax.create, order_id: c.orderId, nonce: c.nonce }, collect($root, c));
			busy($btn, true);
			post(data).done(function (resp) {
				if (isLicenseError(resp)) { busy($btn, false); notice($root, 'err', c.strings.license + ' <a href="' + c.licenseUrl + '">' + c.strings.license + '</a>', true); return; }
				var ok = (resp === 'success') || (resp && resp.success === true);
				if (ok) {
					refresh($root, c, c.strings.created, 'ok');
				} else {
					busy($btn, false);
					notice($root, 'err', errorText(resp, c), true);
				}
			}).fail(function (xhr) {
				busy($btn, false);
				var resp = null; try { resp = JSON.parse(xhr.responseText); } catch (e) {}
				if (isLicenseError(resp)) { notice($root, 'err', c.strings.license, true); return; }
				notice($root, 'err', errorText(resp, c), true);
			});
		})

		/* Print (main button and menu options) */
		.on('click', '.wxmb .wxmb-print, .wxmb .wxmb-print-opt', function (e) {
			e.preventDefault();
			var $el = $(this), $root = $el.closest('.wxmb'), c = cfg($root);
			var $btn = $root.find('.wxmb-print').first();
			var type = $el.data('type');
			$('.wxmb .wxmb-menu').prop('hidden', true);
			if (!c.ajax || !c.ajax.print) { return; }
			var data = { action: c.ajax.print, order_id: c.orderId, nonce: c.nonce };
			if (c.printField && type !== undefined && type !== '') { data[c.printField] = type; }
			busy($btn, true);
			$.ajax({ type: 'post', url: ajaxUrl, data: data, dataType: 'text' }).done(function (text) {
				busy($btn, false);
				var resp = text;
				try { resp = JSON.parse(text); } catch (err) { resp = text; }
				if (isLicenseError(resp)) { notice($root, 'err', c.strings.license, true); return; }
				handlePrint(resp, $root, c);
			}).fail(function (xhr) {
				busy($btn, false);
				var resp = null; try { resp = JSON.parse(xhr.responseText); } catch (err) {}
				notice($root, 'err', errorText(resp, c), true);
			});
		})

		/* Cancel */
		.on('click', '.wxmb .wxmb-cancel', function (e) {
			e.preventDefault();
			var $a = $(this), $root = $a.closest('.wxmb'), c = cfg($root);
			var msg = (c.strings.confirmCancel || '%s').replace('%s', $a.data('voucher') || '');
			if (!window.confirm(msg)) { return; }
			$a.css('opacity', .5);
			post({ action: c.ajax.cancel, order_id: c.orderId, nonce2: c.nonce2 }).done(function (resp) {
				if (resp && resp.success && resp.data && resp.data.html) {
					swap($root, resp.data, resp.data.message || c.strings.cancelled, 'warn');
				} else {
					$a.css('opacity', 1);
					notice($root, 'err', errorText(resp, c), true);
				}
			}).fail(function (xhr) {
				$a.css('opacity', 1);
				var resp = null; try { resp = JSON.parse(xhr.responseText); } catch (err) {}
				notice($root, 'err', errorText(resp, c), true);
			});
		})

		/* Return voucher: issue */
		.on('click', '.wxmb .wxmb-return-create', function () {
			var $btn = $(this), $root = $btn.closest('.wxmb'), c = cfg($root);
			if (!window.confirm(c.strings.confirmReturn)) { return; }
			busy($btn, true);
			post({ action: c.ajax.returnCreate, order_id: c.orderId, nonce2: c.nonce2 }).done(function (resp) {
				if (resp && resp.success && resp.data && resp.data.html) {
					swap($root, resp.data, resp.data.message || c.strings.returned, 'ok');
				} else {
					busy($btn, false);
					notice($root, 'err', errorText(resp, c), true);
				}
			}).fail(function (xhr) {
				busy($btn, false);
				var resp = null; try { resp = JSON.parse(xhr.responseText); } catch (err) {}
				notice($root, 'err', errorText(resp, c), true);
			});
		})

		/* Return voucher: print */
		.on('click', '.wxmb .wxmb-return-print', function () {
			var $btn = $(this), $root = $btn.closest('.wxmb'), c = cfg($root);
			$btn.prop('disabled', true);
			post({ action: c.ajax.returnPrint, order_id: c.orderId, nonce2: c.nonce2 }).done(function (resp) {
				$btn.prop('disabled', false);
				if (resp && resp.success && resp.data) {
					if (resp.data.url) { openUrl(resp.data.url, $root, c); }
					else if (resp.data.base64) { openBase64(resp.data.base64, $root, c, resp.data.filename); }
					else { notice($root, 'err', c.strings.noPrint, true); }
				} else {
					notice($root, 'err', errorText(resp, c), true);
				}
			}).fail(function (xhr) {
				$btn.prop('disabled', false);
				var resp = null; try { resp = JSON.parse(xhr.responseText); } catch (err) {}
				notice($root, 'err', errorText(resp, c), true);
			});
		})

		/* Fresh track on demand */
		.on('click', '.wxmb .wxmb-track-now', function (e) {
			e.preventDefault();
			var $a = $(this), $root = $a.closest('.wxmb'), c = cfg($root);
			if ($a.data('busy')) { return; }
			$a.data('busy', 1).css('opacity', .5);
			post({ action: c.ajax.track, order_id: c.orderId, nonce2: c.nonce2 }).done(function (resp) {
				if (resp && resp.success && resp.data && resp.data.html) {
					swap($root, resp.data, resp.data.message || c.strings.tracked, 'ok');
				} else {
					$a.data('busy', 0).css('opacity', 1);
					notice($root, 'err', errorText(resp, c), true);
				}
			}).fail(function (xhr) {
				$a.data('busy', 0).css('opacity', 1);
				var resp = null; try { resp = JSON.parse(xhr.responseText); } catch (err) {}
				notice($root, 'err', errorText(resp, c), true);
			});
		})

		/* Reset */
		.on('click', '.wxmb .wxmb-reset', function (e) {
			e.preventDefault();
			var $a = $(this), $root = $a.closest('.wxmb'), c = cfg($root);
			$('.wxmb .wxmb-menu').prop('hidden', true);
			if (!window.confirm(c.strings.confirmReset)) { return; }
			post({ action: c.ajax.reset, order_id: c.orderId, nonce2: c.nonce2 }).done(function (resp) {
				if (resp && resp.success && resp.data && resp.data.html) {
					swap($root, resp.data, resp.data.message || c.strings.reset, 'warn');
				} else {
					notice($root, 'err', errorText(resp, c), true);
				}
			}).fail(function () { notice($root, 'err', c.strings.error, true); });
		});

})(jQuery);
