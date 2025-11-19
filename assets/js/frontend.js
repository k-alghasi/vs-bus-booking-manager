// اسکریپت‌های فرانت‌اند
jQuery(document).ready(function($) {
    // کدهای جاوااسکریپت می‌توانند اینجا باشند
    jQuery(document).ready(function($) {
    // مدیریت انتخاب صندلی با انیمیشن
    $('.vsbbm-seat-available').on('click', function() {
        const seatNumber = $(this).data('seat');
        vsbbmHandleSeatSelection(seatNumber, $(this));
    });

    // انیمیشن نمایش فرم مسافر
    function showPassengerForm() {
        $('.vsbbm-passenger-form').fadeIn(400, function() {
            $(this).addClass('vsbbm-passenger-form');
        });
    }

    // انیمیشن مخفی کردن فرم مسافر
    function hidePassengerForm() {
        $('.vsbbm-passenger-form').fadeOut(300);
    }

    // انیمیشن نمایش پیام موفقیت
    function showSuccessMessage(message) {
        const $message = $('<div class="vsbbm-success-message">' + message + '</div>');
        $('body').append($message);
        $message.fadeIn(400).delay(3000).fadeOut(400, function() {
            $(this).remove();
        });
    }

    // انیمیشن نمایش پیام خطا
    function showErrorMessage(message) {
        const $message = $('<div class="vsbbm-error-message">' + message + '</div>');
        $('body').append($message);
        $message.fadeIn(400).delay(4000).fadeOut(400, function() {
            $(this).remove();
        });
    }

    // انیمیشن لرزش برای عناصر خطا
    $.fn.shake = function() {
        this.each(function() {
            $(this).animate({
                'margin-left': '-10px'
            }, 100).animate({
                'margin-left': '10px'
            }, 100).animate({
                'margin-left': '0px'
            }, 100);
        });
        return this;
    };

    // انیمیشن اسکرول smooth به فرم
    function scrollToForm() {
        $('html, body').animate({
            scrollTop: $('.vsbbm-passenger-form').offset().top - 50
        }, 600, 'easeInOutCubic');
    }

    // اضافه کردن easing برای انیمیشن‌های بهتر
    $.extend($.easing, {
        easeInOutCubic: function (x, t, b, c, d) {
            if ((t/=d/2) < 1) return c/2*t*t*t + b;
            return c/2*((t-=2)*t*t + 2) + b;
        }
    });
});

function vsbbmHandleSeatSelection(seatNumber, element) {
    // اگر همین صندلی انتخاب شده، لغو انتخاب با انیمیشن
    if (element.hasClass('vsbbm-seat-selected')) {
        element.fadeTo(200, 0.7).fadeTo(200, 1, function() {
            element.removeClass('vsbbm-seat-selected');
            $('#vsbbm-selected-seat').slideUp(300);
            localStorage.removeItem('vsbbm_selected_seat');
        });
        return;
    }

    // لغو انتخاب قبلی با انیمیشن
    $('.vsbbm-seat-selected').fadeTo(200, 0.7).fadeTo(200, 1, function() {
        $(this).removeClass('vsbbm-seat-selected');
    });

    // انتخاب جدید با انیمیشن
    element.delay(400).queue(function(next) {
        element.addClass('vsbbm-seat-selected');
        $('#vsbbm-seat-number').text(seatNumber);
        $('#vsbbm-selected-seat').slideDown(400);
        localStorage.setItem('vsbbm_selected_seat', seatNumber);
        next();
    });
}

// تابع عمومی برای نمایش پیام‌ها
function vsbbmShowMessage(type, message) {
    if (type === 'success') {
        showSuccessMessage(message);
    } else if (type === 'error') {
        showErrorMessage(message);
    }
}

// تابع عمومی برای لرزش عنصر
function vsbbmShakeElement(selector) {
    jQuery(selector).shake();
}
});

