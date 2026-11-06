(function( $ ) {
	'use strict';

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

	function safeOpen(url, $context) {
		var w = window.open(url, '_blank');
		if (!w || w.closed || typeof w.closed === 'undefined') {
			$context.closest('.webexpert-field-group, .webexpert-field').find('.dkexpress-popup-notice').remove();
			var body = window.wxPdf.blocked(url);
			var notice = body ? $('<div class="dkexpress-popup-notice" style="' + window.wxPdf.box + '">' + body + '</div>') : $('<div class="dkexpress-popup-notice">' +
				'<span class="dashicons dashicons-warning"></span> ' +
				dkexpress_ajax_object.popup_blocked_msg +
				' <a href="' + url + '" target="_blank">' +
				dkexpress_ajax_object.popup_blocked_link + '</a></div>');
			$context.closest('.webexpert-field-group, .webexpert-field').append(notice);
		}
	}


	$(document).ready(function(){
        $(document).on('click', '.dkexpress-account-delete', function(e){
            e.preventDefault();
            $(this).parent().find('input').each(function(){
                $(this).val('');
            });
            $(this).parent().css('display', 'none');
        });

        $(document).on('click', '.webexpert-add-extra-dkexpress-acc', function(e){
            e.preventDefault();
            let element = $(".dkexpress-account-table").first();
            let clone = element.clone();
            clone.insertAfter($('.dkexpress-account-table').last()).hide().fadeIn(500);
            clone.find('input').each(function() {
                $(this).val('');
            });
            clone.find('.account_id').remove();
            clone.prepend('<span class="dkexpress-account-delete">&times;</span>');
        })

        if (jQuery('#dkexpress_disable_on_payments').length)
            jQuery('#dkexpress_disable_on_payments').select2();

        if (jQuery('#dkexpress_disable_on_shipping').length)
            jQuery('#dkexpress_disable_on_shipping').select2();

        if (jQuery('#dkexpress_same_day_shipping').length)
            jQuery('#dkexpress_same_day_shipping').select2();

        jQuery( function($) {
            var from = $('input[name="mishaDateFrom"]'),
                to = $('input[name="mishaDateTo"]');

            $( 'input[name="mishaDateFrom"], input[name="mishaDateTo"]' ).datepicker( {dateFormat : "dd-mm-yy"} );
            from.on( 'change', function() {
                to.datepicker( 'option', 'minDate', from.val() );
            });

            to.on( 'change', function() {
                from.datepicker( 'option', 'maxDate', to.val() );
            });
        });

        $('#dkexpress_courier_create_voucher').on("click",function (e) {
			var $this=$(this);
            $this.addClass('disabled').addClass('is-active');
            e.preventDefault();
			jQuery.ajax({
				type : "post",
				dataType : "json",
				url : dkexpress_ajax_object.ajax_url,
				data : {action: "dkexpress_courier_create_voucher", nonce: dkexpress_ajax_object.nonce, 'dkexpress_account' : $("#dkexpress_account").val() ,order_id : $this.data('order'),parcels: $('#dkexpress_parcels').val(),services: $('#dkexpress_special_cases').val(), cod: $('input[name="dkexpress_cod"]').val(), comments: $('textarea[name="dkexpress_comments"]').val(),weight: $('input[name="dkexpress_weight"]').val()},
				success: function(response) {
                    $this.removeClass('disabled').removeClass('is-active');
					if(response === "success") {
						alert($this.data('success'));
						location.reload();
					}
					else {
                        alert($this.data('error') + (typeof response === 'string' && response ? '\n\n' + response : ''));
					}
				}
			})
		});

        // Labels open in a named viewer page (wxPdf) — nothing is written to uploads.
        function dkexpress_openPdf(base64Data, $context, filename) {
            safeOpen(window.wxPdf.url(base64Data, filename), $context);
        }

        $('.dkexpress_print_voucher_type1, .dkexpress_print_voucher_type2, #dkexpress_print_voucher').on("click", function (e) {
            e.preventDefault();
            var $this = $(this);
            $this.addClass('disabled').addClass('is-active');

            var printType;
            if ($this.is('#dkexpress_print_voucher')) {
                printType = $('input[name="dkexpress_print_voucher_type"]:checked').val();
            } else {
                printType = $this.data('type');
            }

            jQuery.ajax({
                type: "post",
                dataType: "json",
                url: dkexpress_ajax_object.ajax_url,
                data: {
                    action: "dkexpress_print_voucher",
                    nonce: dkexpress_ajax_object.nonce,
                    print_type: printType,
                    order_id: $this.data('order')
                },
                success: function (response) {
                                        $this.removeClass('disabled').removeClass('is-active');
                    if (response.success) {
                        dkexpress_openPdf(response.data.Voucher, $this, response.data.Filename);
                    } else {
                        alert(dkexpress_ajax_object.print_error_msg + (typeof response.data === 'string' && response.data ? '\n\n' + response.data : ''));
                    }
                }
            });
        });

		$('.dkexpress_cancel_voucher').on("click",function (e) {
			e.preventDefault();
            var $this=$(this);
            $this.addClass('disabled').addClass('is-active');
            jQuery.ajax({
				type : "post",
				dataType : "json",
				url : dkexpress_ajax_object.ajax_url,
				data : {action: "dkexpress_cancel_voucher", nonce: dkexpress_ajax_object.nonce, order_id : $this.data('order')},
				success: function(response) {
                    $this.removeClass('disabled').removeClass('is-active');
                    if (response && response.success) {
                        alert($this.data('success'));
                        location.reload();
                    } else {
                        alert(response && typeof response.data === 'string' ? response.data : $this.data('error'));
                    }
				}
			})
		});

	});

})( jQuery );
