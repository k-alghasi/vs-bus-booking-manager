jQuery(document).ready(function($) {
    
    // متغیرهای سراسری
    let html5QrcodeScanner;
    const $resultBox = $('#vsbbm-scan-result');
    
    // 1. راه‌اندازی اسکنر QR
    function onScanSuccess(decodedText, decodedResult) {
        // قطع اسکن موقت
        // html5QrcodeScanner.clear();
        
        // اگر فرمت جیسون بود، کد را استخراج کن (فرمت ما: {"ticket":"TK-..."})
        let ticketCode = decodedText;
        try {
            const obj = JSON.parse(decodedText);
            if (obj.tn) ticketCode = obj.tn; // اگر از کلید کوتاه tn استفاده کردیم
            else if (obj.ticket) ticketCode = obj.ticket;
        } catch(e) {}

        $('#vsbbm-ticket-code').val(ticketCode);
        validateTicket(ticketCode);
    }

    if ($('#vsbbm-qr-reader').length) {
        html5QrcodeScanner = new Html5QrcodeScanner(
            "vsbbm-qr-reader", 
            { fps: 10, qrbox: 250 }, 
            /* verbose= */ false
        );
        html5QrcodeScanner.render(onScanSuccess);
    }

    // 2. دکمه چک دستی
    $('#vsbbm-check-btn').click(function() {
        const code = $('#vsbbm-ticket-code').val();
        if(code) validateTicket(code);
    });

    // 3. اعتبارسنجی با AJAX
    function validateTicket(code) {
        $resultBox.hide().removeClass('success error warning');
        
        $.ajax({
            url: vsbbm_scanner_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'vsbbm_validate_ticket',
                ticket_code: code,
                nonce: vsbbm_scanner_vars.nonce
            },
            success: function(res) {
                if (res.success) {
                    showResult(res.data);
                } else {
                    showError(res.data.message);
                }
            },
            error: function() {
                showError('خطا در ارتباط با سرور');
            }
        });
    }

    // 4. نمایش نتیجه
    function showResult(data) {
        $resultBox.show();
        let html = '';
        
        if (data.is_valid) {
            $resultBox.addClass('success');
            $('.scan-status-icon').html('✅');
            $('.scan-title').text('بلیط معتبر است');
            $('#vsbbm-confirm-usage').show().data('id', data.ticket_id);
        } else {
            $('#vsbbm-confirm-usage').hide();
            if(data.status === 'used') {
                $resultBox.addClass('warning');
                $('.scan-status-icon').html('⚠️');
                $('.scan-title').text('این بلیط قبلاً استفاده شده!');
            } else {
                $resultBox.addClass('error');
                $('.scan-status-icon').html('❌');
                $('.scan-title').text('بلیط نامعتبر یا منقضی');
            }
        }

        html += `<p><strong>مسافر:</strong> ${data.passenger}</p>`;
        html += `<p><strong>صندلی:</strong> ${data.seat}</p>`;
        html += `<p><strong>تاریخ حرکت:</strong> ${data.date}</p>`;
        html += `<p><small>کد بلیط: ${data.ticket_no}</small></p>`;
        
        $('.scan-details').html(html);
    }

    function showError(msg) {
        $resultBox.show().addClass('error');
        $('.scan-status-icon').html('🚫');
        $('.scan-title').text('خطا');
        $('.scan-details').html(msg);
        $('#vsbbm-confirm-usage').hide();
    }

    // 5. دکمه ابطال (Check-in)
    $('#vsbbm-confirm-usage').click(function() {
        const btn = $(this);
        const id = btn.data('id');
        btn.prop('disabled', true).text('در حال ثبت...');

        $.ajax({
            url: vsbbm_scanner_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'vsbbm_checkin_ticket',
                ticket_id: id,
                nonce: vsbbm_scanner_vars.nonce
            },
            success: function(res) {
                if(res.success) {
                    btn.hide();
                    $resultBox.removeClass('success').addClass('warning');
                    $('.scan-title').text('بلیط با موفقیت باطل شد');
                    $('.scan-status-icon').html('🏁');
                }
            }
        });
    });
});