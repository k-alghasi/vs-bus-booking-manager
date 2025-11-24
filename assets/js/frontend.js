jQuery(document).ready(function($) {

    const $seatSelectionContainer = $('#vsbbm-seat-selection-wrapper');
    const $dateSelectorWrapper = $('#vsbbm-date-selector-wrapper');
    const $seatSelectorContainer = $('#vsbbm-seat-selector-container');
    const $addToCartButton = $('button.single_add_to_cart_button');
    let selectedSeats = [];
    let departureTimestamp = ''; // متغیر سراسری برای نگهداری تاریخ حرکت

    // --------------------------------------------------------
    // جدید: هندل کردن انتخاب تاریخ سفر (برای Recurring Trips)
    // --------------------------------------------------------

    if ($dateSelectorWrapper.length) {
        // مخفی کردن دکمه "افزودن به سبد خرید" تا زمانی که صندلی انتخاب شود
        $addToCartButton.prop('disabled', true).text(vsbbm_ajax_vars.select_date_error);
        
        $dateSelectorWrapper.on('change', '#vsbbm_departure_date', function() {
            departureTimestamp = $(this).val();
            const productId = $(this).data('product-id');
            
            if (departureTimestamp) {
                $seatSelectorContainer.html('<p class="loading-message">' + vsbbm_ajax_vars.loading_text + '</p>').show();
                $addToCartButton.prop('disabled', true).text(vsbbm_ajax_vars.loading_text);
                
                // فراخوانی AJAX برای گرفتن داده‌های صندلی‌ها بر اساس تاریخ
                $.ajax({
                    url: vsbbm_ajax_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'vsbbm_get_seat_data',
                        product_id: productId,
                        departure_timestamp: departureTimestamp,
                        _wpnonce: vsbbm_ajax_vars.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.html) {
                            $seatSelectorContainer.html(response.data.html);
                            // صندلی‌های از قبل رزرو شده را مشخص می‌کند
                            initializeSeatMap(response.data.reserved_seats); 
                            
                            // فعال‌سازی دکمه با متن اولیه
                            $addToCartButton.prop('disabled', false).text($addToCartButton.data('original-text') || 'افزودن به سبد خرید');
                        } else {
                            $seatSelectorContainer.html('<p class="error-message">' + (response.data.message || 'خطا در بارگذاری صندلی‌ها.') + '</p>');
                            $addToCartButton.prop('disabled', true).text('خطا در بارگذاری');
                        }
                    },
                    error: function() {
                        $seatSelectorContainer.html('<p class="error-message">خطا در ارتباط با سرور.</p>');
                        $addToCartButton.prop('disabled', true).text('خطا در بارگذاری');
                    }
                });
            } else {
                $seatSelectorContainer.html('لطفاً تاریخ حرکت را انتخاب کنید.').hide();
                $addToCartButton.prop('disabled', true).text(vsbbm_ajax_vars.select_date_error);
            }
        });
    } else {
        // برای سفرهای Open و One-Time، متغیر Timestamp را تنظیم کنید
        // و دکمه را فعال نگه دارید.
        departureTimestamp = $seatSelectionContainer.data('departure-timestamp') || '';
        // دکمه "افزودن به سبد خرید" را فعال می‌کند
        $addToCartButton.prop('disabled', false).data('original-text', $addToCartButton.text());
        initializeSeatMap(); // برای لود اولیه
    }

    // --------------------------------------------------------
    // تابع اصلی مدیریت نقشه صندلی
    // --------------------------------------------------------
    function initializeSeatMap(initialReservedSeats = []) {
        selectedSeats = [];
        
        // اگر صندلی‌ها قبلاً رندر شده‌اند، هندلرها را فعال می‌کنیم.
        // این بخش باید بعد از رندر HTML در #vsbbm-seat-selector-container اجرا شود.

        $seatSelectorContainer.off('click', '.vsbbm-seat:not(.reserved):not(.occupied)').on('click', '.vsbbm-seat:not(.reserved):not(.occupied)', function() {
            // ... (منطق انتخاب/حذف صندلی‌ها - بدون تغییر) ...
        });
        
        // ... (بقیه منطق initializeSeatMap مانند مدیریت MaxSeats و غیره) ...
    }
    
    // --------------------------------------------------------
    // هندلر AJAX Add to Cart (بروزرسانی شده)
    // --------------------------------------------------------
    
    $addToCartButton.click(function(e) {
        e.preventDefault();
        
        if (selectedSeats.length === 0) {
            alert('لطفاً حداقل یک صندلی انتخاب کنید.');
            return;
        }
        
        const productId = $seatSelectionContainer.data('product-id');
        const passengersData = collectPassengerData(); // تابع موجود

        // اضافه کردن تاریخ حرکت به داده‌های ارسالی
        const postData = {
            action: 'vsbbm_add_to_cart',
            product_id: productId,
            selected_seats: selectedSeats,
            passengers_data: passengersData,
            vsbbm_departure_timestamp: departureTimestamp, // <-- ارسال متغیر
            _wpnonce: vsbbm_ajax_vars.nonce
        };
        
        // ... (فراخوانی AJAX و هندل کردن پاسخ - بدون تغییر) ...
    });
    
    // ... (بقیه توابع کمکی مانند collectPassengerData، updatePriceDisplay و ...) ...

});