<?php
defined('ABSPATH') || exit;

global $product;

$seat_numbers = VSBBM_Seat_Manager::get_seat_numbers($product->get_id());
$reserved_seats = VSBBM_Seat_Manager::get_reserved_seats($product->get_id());
$rows = 4;
$cols = 8;
?>

<div id="vsbbm-seat-booking">
    <h3><?php _e('انتخاب صندلی و اطلاعات مسافر', 'vs-bus-booking-manager'); ?></h3>
    
    <div id="vsbbm-seat-map">
        <table>
            <?php
            $i = 0;
            for ($r = 1; $r <= $rows; $r++) {
                echo '<tr>';
                for ($c = 1; $c <= $cols; $c++) {
                    $i++;
                    $is_available = in_array($i, $seat_numbers);
                    $is_reserved = in_array($i, $reserved_seats);
                    $is_selectable = $is_available && !$is_reserved;
                    
                    $class = 'vsbbm-seat';
                    if (!$is_available) $class .= ' unavailable';
                    if ($is_reserved) $class .= ' reserved';
                    if ($is_selectable) $class .= ' available';
                    
                    echo '<td>';
                    if ($is_available) {
                        echo '<div class="' . $class . '" data-seat="' . $i . '" ' . 
                             (!$is_selectable ? 'style="cursor: not-allowed;"' : '') . '>';
                        echo $i;
                        echo '</div>';
                    } else {
                        echo '<div class="vsbbm-seat unavailable"></div>';
                    }
                    echo '</td>';
                }
                echo '</tr>';
            }
            ?>
        </table>
        
        <div class="vsbbm-seat-legend">
            <div class="legend-item">
                <div class="seat-example available"></div>
                <span>صندلی آزاد</span>
            </div>
            <div class="legend-item">
                <div class="seat-example reserved"></div>
                <span>رزرو شده</span>
            </div>
            <div class="legend-item">
                <div class="seat-example selected"></div>
                <span>انتخاب شما</span>
            </div>
            <div class="legend-item">
                <div class="seat-example unavailable"></div>
                <span>غیرفعال</span>
            </div>
        </div>
    </div>
    
    <div id="vsbbm-passenger-form" style="display: none;">
        <h4><?php _e('اطلاعات مسافران', 'vs-bus-booking-manager'); ?></h4>
        <div id="vsbbm-passenger-fields"></div>
    </div>
    
    <input type="hidden" name="vsbbm_passenger_data" id="vsbbm_passenger_data" value="">
</div>

<style>
#vsbbm-seat-booking {
    margin: 20px 0;
    padding: 20px;
    border: 1px solid #ddd;
    border-radius: 5px;
    background: #f9f9f9;
}

#vsbbm-seat-map table {
    border-collapse: collapse;
    margin: 0 auto;
}

#vsbbm-seat-map td {
    padding: 5px;
    text-align: center;
}

.vsbbm-seat {
    width: 40px;
    height: 40px;
    line-height: 40px;
    border: 2px solid #ccc;
    border-radius: 5px;
    cursor: pointer;
    font-weight: bold;
    transition: all 0.3s ease;
}

.vsbbm-seat.available {
    background: #e8f5e8;
    border-color: #4caf50;
    color: #2e7d32;
}

.vsbbm-seat.available:hover {
    background: #c8e6c9;
    transform: scale(1.05);
}

.vsbbm-seat.reserved {
    background: #ffebee;
    border-color: #f44336;
    color: #c62828;
    cursor: not-allowed;
    opacity: 0.6;
}

.vsbbm-seat.unavailable {
    background: #f5f5f5;
    border-color: #9e9e9e;
    cursor: not-allowed;
    opacity: 0.3;
}

.vsbbm-seat.selected {
    background: #2196f3;
    border-color: #1976d2;
    color: white;
    transform: scale(1.1);
}

.vsbbm-seat-legend {
    display: flex;
    justify-content: center;
    margin-top: 20px;
    gap: 20px;
    flex-wrap: wrap;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
}

.seat-example {
    width: 20px;
    height: 20px;
    border: 2px solid;
    border-radius: 3px;
}

.seat-example.available {
    background: #e8f5e8;
    border-color: #4caf50;
}

.seat-example.reserved {
    background: #ffebee;
    border-color: #f44336;
}

.seat-example.selected {
    background: #2196f3;
    border-color: #1976d2;
}

.seat-example.unavailable {
    background: #f5f5f5;
    border-color: #9e9e9e;
}

.vsbbm-passenger-field {
    background: white;
    padding: 15px;
    margin: 10px 0;
    border: 1px solid #ddd;
    border-radius: 5px;
}

.vsbbm-passenger-field h5 {
    margin: 0 0 10px 0;
    color: #333;
    border-bottom: 2px solid #2196f3;
    padding-bottom: 5px;
}

.vsbbm-passenger-field .form-row {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}

.vsbbm-passenger-field .form-row label {
    min-width: 100px;
    font-weight: bold;
}

.vsbbm-passenger-field input {
    padding: 8px;
    border: 1px solid #ccc;
    border-radius: 3px;
    min-width: 200px;
}
</style>

<script>
jQuery(document).ready(function($) {
    let selectedSeats = [];
    let passengerFields = [];
    
    // مدیریت کلیک روی صندلی‌ها - نسخه اصلاح شده
    // مدیریت کلیک روی صندلی‌ها - نسخه اصلاح شده
$(document).on('click', '.vsbbm-seat.available, .vsbbm-seat.selected', function() {
    const seatNumber = parseInt($(this).data('seat'));
    const $seat = $(this);
    
    if ($seat.hasClass('selected')) {
        // حذف صندلی - با تأییدیه
        if (confirm(`آیا از حذف صندلی ${seatNumber} مطمئن هستید؟`)) {
            removePassengerFieldCompletely(seatNumber, $seat);
        }
    } else {
        // افزودن صندلی
        selectedSeats.push(seatNumber);
        $seat.removeClass('available').addClass('selected');
        addPassengerField(seatNumber);
        
        // افکت انتخاب
        $seat.animate({scale: 1.2}, 200).animate({scale: 1.1}, 100);
    }
    
    updatePassengerForm();
    updateHiddenField();
});

// تابع کاملاً جدید برای حذف ایمن
    function removePassengerFieldCompletely(seatNumber, $seatElement) {
        // حذف از آرایه selectedSeats
        const index = selectedSeats.indexOf(seatNumber);
        if (index !== -1) {
            selectedSeats.splice(index, 1);
        }
        
        // حذف از آرایه passengerFields
        passengerFields = passengerFields.filter(field => field.seatNumber !== seatNumber);
        
        // حذف از DOM با انیمیشن
        const $passengerField = $(`#vsbbm-passenger-fields .vsbbm-passenger-field[data-seat="${seatNumber}"]`);
        if ($passengerField.length) {
            $passengerField.slideUp(300, function() {
                $(this).remove();
                // بعد از حذف کامل، صندلی رو بازگردون به حالت available
                $seatElement.removeClass('selected').addClass('available')
                        .animate({scale: 0.9}, 100).animate({scale: 1}, 100);
            });
        } else {
            // اگر عنصر DOM پیدا نشد، باز هم صندلی رو بازگردون
            $seatElement.removeClass('selected').addClass('available')
                    .animate({scale: 0.9}, 100).animate({scale: 1}, 100);
        }
        
        // اگر هیچ صندلی انتخاب نشده، فرم رو مخفی کن
        if (selectedSeats.length === 0) {
            $('#vsbbm-passenger-form').slideUp(300);
        }
    }

    // تابع addPassengerField رو هم کمی بهینه‌تر کنیم
    function addPassengerField(seatNumber) {
        // بررسی نکنیم که آیا از قبل وجود داره یا نه - همیشه جدید بسازیم
        const fieldIndex = Date.now(); // استفاده از timestamp برای unique ID
        
        const fieldHtml = `
            <div class="vsbbm-passenger-field" data-seat="${seatNumber}" id="passenger-field-${fieldIndex}">
                <div class="vsbbm-passenger-header">
                    <h5>مسافر صندلی ${seatNumber}</h5>
                    <button type="button" class="vsbbm-passenger-remove" data-seat="${seatNumber}">✕</button>
                </div>
                <div class="vsbbm-passenger-content">
                    <!-- فیلدهای فرم -->
                </div>
            </div>
        `;
        
        passengerFields.push({seatNumber, html: fieldHtml, id: fieldIndex});
        $('#vsbbm-passenger-fields').append(fieldHtml);
    }

    // اضافه کردن امکان حذف از طریق دکمه ✕
    $(document).on('click', '.vsbbm-passenger-remove', function() {
        const seatNumber = parseInt($(this).data('seat'));
        const $seatElement = $(`.vsbbm-seat[data-seat="${seatNumber}"]`);
        
        if (confirm(`آیا از حذف مسافر صندلی ${seatNumber} مطمئن هستید؟`)) {
            removePassengerFieldCompletely(seatNumber, $seatElement);
            updateHiddenField();
        }
        });
    
        function addPassengerField(seatNumber) {
        const fieldIndex = passengerFields.length;
        const fieldHtml = `
            <div class="vsbbm-passenger-field" data-seat="${seatNumber}">
                <div class="vsbbm-passenger-header">
                    <h5>مسافر صندلی ${seatNumber}</h5>
                    <button type="button" class="vsbbm-passenger-toggle">▼</button>
                </div>
                <div class="vsbbm-passenger-content">
                    <div class="vsbbm-form-row">
                        <label for="passenger_name_${fieldIndex}">نام کامل *</label>
                        <input type="text" id="passenger_name_${fieldIndex}" 
                            name="passenger_names[${seatNumber}]" 
                            placeholder="نام و نام خانوادگی" 
                            required 
                            data-persian-pattern="^[\u0600-\u06FF\s]{3,50}$">
                        <div class="error-message">نام باید بین ۳ تا ۵۰ حرف فارسی باشد</div>
                    </div>
                    
                    <div class="vsbbm-form-row">
                        <label for="passenger_national_code_${fieldIndex}">کد ملی *</label>
                        <input type="text" id="passenger_national_code_${fieldIndex}" 
                               name="passenger_national_codes[${seatNumber}]" 
                               placeholder="۱۰ رقمی" 
                               required 
                               pattern="[0-9]{10}"
                               oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10)">
                        <div class="error-message">کد ملی باید ۱۰ رقم باشد</div>
                    </div>
                    
                    <div class="vsbbm-form-row">
                        <label for="passenger_birthdate_${fieldIndex}">تاریخ تولد</label>
                        <input type="date" id="passenger_birthdate_${fieldIndex}" 
                               name="passenger_birthdates[${seatNumber}]">
                    </div>
                    
                    <div class="vsbbm-form-row">
                        <label for="passenger_education_${fieldIndex}">تحصیلات</label>
                        <select id="passenger_education_${fieldIndex}" 
                                name="passenger_educations[${seatNumber}]">
                            <option value="">انتخاب کنید</option>
                            <option value="diploma">دیپلم</option>
                            <option value="bachelor">لیسانس</option>
                            <option value="master">فوق لیسانس</option>
                            <option value="phd">دکترا</option>
                        </select>
                    </div>
                    
                    <div class="vsbbm-form-row">
                        <label for="passenger_occupation_${fieldIndex}">شغل</label>
                        <input type="text" id="passenger_occupation_${fieldIndex}" 
                               name="passenger_occupations[${seatNumber}]" 
                               placeholder="شغل">
                    </div>
                    
                    <div class="vsbbm-form-row">
                        <label for="passenger_medical_${fieldIndex}">سابقه بیماری خاص</label>
                        <textarea id="passenger_medical_${fieldIndex}" 
                                  name="passenger_medicals[${seatNumber}]" 
                                  placeholder="در صورت وجود بیماری خاص ذکر کنید"
                                  rows="2"></textarea>
                    </div>
                    
                    <div class="vsbbm-form-row">
                        <label for="passenger_phone_${fieldIndex}">شماره موبایل *</label>
                        <input type="tel" id="passenger_phone_${fieldIndex}" 
                               name="passenger_phones[${seatNumber}]" 
                               placeholder="09xxxxxxxxx" 
                               required 
                               pattern="09[0-9]{9}"
                               oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11)">
                        <div class="error-message">شماره موبایل باید ۱۱ رقم باشد</div>
                    </div>
                </div>
                <input type="hidden" name="passenger_seats[${seatNumber}]" value="${seatNumber}">
            </div>
        `;
    passengerFields.push({seatNumber, html: fieldHtml});
    }
    
    // مدیریت collapse/expand
    $(document).on('click', '.vsbbm-passenger-toggle', function() {
        $(this).closest('.vsbbm-passenger-field').toggleClass('collapsed');
    });
    
    // real-time validation
    $(document).on('blur', 'input[required], select[required]', function() {
        validateField($(this));
    });
    
    function validateField($field) {
        const isValid = $field[0].checkValidity();
        const $row = $field.closest('.vsbbm-form-row');
        const $error = $row.find('.error-message');
        
        if (isValid) {
            $row.removeClass('error');
            $error.hide();
        } else {
            $row.addClass('error');
            $error.show();
        }
        
        return isValid;
    }
    
    function updatePassengerForm() {
        if (selectedSeats.length > 0) {
            $('#vsbbm-passenger-form').show();
            // فقط فیلدهای جدید رو اضافه کن، قبلی‌ها رو دست نزن
            const existingSeats = new Set($('#vsbbm-passenger-fields .vsbbm-passenger-field').map(function() {
                return $(this).data('seat');
            }).get());
            
            passengerFields.forEach(field => {
                if (!existingSeats.has(field.seatNumber)) {
                    $('#vsbbm-passenger-fields').append(field.html);
                }
            });
        } else {
            $('#vsbbm-passenger-form').hide();
            $('#vsbbm-passenger-fields').empty();
        }
    }
    
    function updateHiddenField() {
        const passengerData = [];
        
        $('.vsbbm-passenger-field').each(function() {
            const seatNumber = $(this).data('seat');
            const name = $(this).find(`input[name="passenger_names[${seatNumber}]"]`).val() || '';
            const nationalCode = $(this).find(`input[name="passenger_national_codes[${seatNumber}]"]`).val() || '';
            
            if (name && nationalCode) {
                passengerData.push({
                    seat_number: parseInt(seatNumber),
                    name: name,
                    national_code: nationalCode
                });
            }
        });
        
        $('#vsbbm_passenger_data').val(JSON.stringify(passengerData));
    }
    
    // بروزرسانی خودکار فیلد مخفی هنگام تغییر اطلاعات
    $(document).on('input', '.vsbbm-passenger-field input', function() {
        updateHiddenField();
    });
    
    // اعتبارسنجی قبل از افزودن به سبد خرید
    $('form.cart').on('submit', function(e) {
        if (selectedSeats.length === 0) {
            e.preventDefault();
            alert('لطفا حداقل یک صندلی انتخاب کنید.');
            return false;
        }
        
        let allFieldsFilled = true;
        $('.vsbbm-passenger-field input[required]').each(function() {
            if (!$(this).val().trim()) {
                allFieldsFilled = false;
                $(this).addClass('error-field');
            } else {
                $(this).removeClass('error-field');
            }
        });
        
        if (!allFieldsFilled) {
            e.preventDefault();
            alert('لطفا اطلاعات تمام مسافران را کامل کنید.');
            return false;
        }
        
        // بررسی کد ملی‌های تکراری
        const nationalCodes = new Set();
        let duplicateFound = false;
        
        $('.vsbbm-passenger-field input[name^="passenger_national_codes"]').each(function() {
            const code = $(this).val().trim();
            if (code && nationalCodes.has(code)) {
                duplicateFound = true;
                $(this).addClass('error-field');
            } else if (code) {
                nationalCodes.add(code);
                $(this).removeClass('error-field');
            }
        });
        
        if (duplicateFound) {
            e.preventDefault();
            alert('کد ملی تکراری وارد شده است. هر مسافر باید کد ملی منحصر به فرد داشته باشد.');
            return false;
        }
        
        updateHiddenField();
        return true;
    });
    
    // استایل برای فیلدهای خطادار
    const style = document.createElement('style');
    style.textContent = `
        .error-field {
            border-color: #ff0000 !important;
            background-color: #fff0f0 !important;
        }
        .vsbbm-seat {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .vsbbm-seat:hover {
            transform: scale(1.05);
        }
    `;
    document.head.appendChild(style);
});
</script>
<style>
/* استایل‌های جدید برای فرم پیشرفته */
.vsbbm-passenger-field {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 15px;
    padding: 20px;
    margin: 15px 0;
    color: white;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.vsbbm-passenger-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    cursor: pointer;
}

.vsbbm-passenger-header h5 {
    margin: 0;
    font-size: 18px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.vsbbm-passenger-header h5:before {
    content: "👤";
    font-size: 20px;
}

.vsbbm-passenger-toggle {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    border-radius: 50%;
    width: 30px;
    height: 30px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.vsbbm-passenger-toggle:hover {
    background: rgba(255,255,255,0.3);
    transform: rotate(180deg);
}

.vsbbm-passenger-content {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.vsbbm-form-row {
    display: flex;
    flex-direction: column;
}

.vsbbm-form-row label {
    font-weight: bold;
    margin-bottom: 5px;
    font-size: 12px;
    opacity: 0.9;
}

.vsbbm-form-row input,
.vsbbm-form-row select,
.vsbbm-form-row textarea {
    padding: 12px;
    border: 2px solid rgba(255,255,255,0.3);
    border-radius: 8px;
    background: rgba(255,255,255,0.1);
    color: white;
    transition: all 0.3s ease;
}

.vsbbm-form-row input:focus,
.vsbbm-form-row select:focus,
.vsbbm-form-row textarea:focus {
    border-color: rgba(255,255,255,0.8);
    background: rgba(255,255,255,0.2);
    outline: none;
}

.vsbbm-form-row input::placeholder {
    color: rgba(255,255,255,0.7);
}

/* استایل برای حالت collapsed */
.vsbbm-passenger-field.collapsed .vsbbm-passenger-content {
    display: none;
}

.vsbbm-passenger-field.collapsed .vsbbm-passenger-toggle {
    transform: rotate(180deg);
}

/* responsive adjustments */
@media (max-width: 768px) {
    .vsbbm-passenger-content {
        grid-template-columns: 1fr;
    }
}

/* استایل‌های validation */
.vsbbm-form-row.error input,
.vsbbm-form-row.error select,
.vsbbm-form-row.error textarea {
    border-color: #ff6b6b;
    background: rgba(255, 107, 107, 0.1);
}

.error-message {
    color: #ff6b6b;
    font-size: 12px;
    margin-top: 5px;
    display: none;
}
</style>