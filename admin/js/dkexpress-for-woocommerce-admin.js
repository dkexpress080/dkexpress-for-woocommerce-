(function( $ ) {
	'use strict';

	function safeOpen(url, $context) {
		var w = window.open(url, '_blank');
		if (!w || w.closed || typeof w.closed === 'undefined') {
			$context.closest('.webexpert-field-group, .webexpert-field').find('.dkexpress-popup-notice').remove();
			var notice = $('<div class="dkexpress-popup-notice">' +
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

        function dkexpress_openPdf(base64Data, $context) {
            var byteCharacters = atob(base64Data);
            var byteNumbers = new Array(byteCharacters.length);
            for (var i = 0; i < byteCharacters.length; i++) {
                byteNumbers[i] = byteCharacters.charCodeAt(i);
            }
            var byteArray = new Uint8Array(byteNumbers);
            var blob = new Blob([byteArray], {type: 'application/pdf'});
            var blobUrl = URL.createObjectURL(blob);
            safeOpen(blobUrl, $context);
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
                        dkexpress_openPdf(response.data.Voucher, $this);
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
