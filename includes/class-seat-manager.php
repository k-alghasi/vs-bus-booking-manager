<?php
defined('ABSPATH') || exit;

class VSBBM_Seat_Manager {
    
    public static function init() {
    static $initialized = false;
    if ($initialized) {
        error_log('🎯 VSBBM_Seat_Manager: Already initialized, skipping');
        return;
    }
    $initialized = true;

    error_log('🎯 VSBBM_Seat_Manager INIT called');

    // هوک‌های مدیریت محصول (ادمین)
    add_action('woocommerce_product_options_general_product_data', array(__CLASS__, 'add_product_fields'));
    add_action('woocommerce_process_product_meta', array(__CLASS__, 'save_product_fields'));
    add_action('add_meta_boxes', array(__CLASS__, 'add_seat_meta_box'));
    add_action('save_post_product', array(__CLASS__, 'save_seat_numbers'));
    add_action('admin_head', array(__CLASS__, 'admin_styles'));

    // ثبت هندلرهای AJAX
    self::register_ajax_handlers();

    // هوک نمایش انتخاب صندلی - فقط یک بار
    add_action('woocommerce_before_add_to_cart_form', array(__CLASS__, 'display_seat_selection'), 10);

    error_log('🎯 VSBBM_Seat_Manager: all hooks registered');
}
    
    public static function add_product_fields() {
        global $product_object;
        
        echo '<div class="options_group">';
        echo '<h3>🚌 تنظیمات رزرو اتوبوس</h3>';
        
        // فعال‌سازی رزرو صندلی
        woocommerce_wp_checkbox(array(
            'id' => '_vsbbm_enable_seat_booking',
            'label' => 'فعال‌سازی رزرو صندلی',
            'description' => 'این محصول به عنوان سرویس رزرو صندلی اتوبوس فعال شود',
            'value' => $product_object->get_meta('_vsbbm_enable_seat_booking') ?: 'no'
        ));
        
        echo '</div>';
        
        // تنظیمات زمان فروش
        echo '<div class="options_group">';
        echo '<h4>⏰ تنظیمات زمان فروش</h4>';
        
        woocommerce_wp_text_input(array(
            'id' => '_vsbbm_sale_start_date',
            'label' => 'تاریخ شروع فروش',
            'type' => 'datetime-local',
            'description' => 'تاریخ و ساعت شروع فروش بلیط',
            'wrapper_class' => 'vsbbm-date-field',
            'value' => $product_object->get_meta('_vsbbm_sale_start_date')
        ));
        
        woocommerce_wp_text_input(array(
            'id' => '_vsbbm_sale_end_date', 
            'label' => 'تاریخ پایان فروش',
            'type' => 'datetime-local',
            'description' => 'تاریخ و ساعت پایان فروش بلیط',
            'wrapper_class' => 'vsbbm-date-field',
            'value' => $product_object->get_meta('_vsbbm_sale_end_date')
        ));
        
        // نمایش وضعیت کنونی
        $current_status = self::get_product_availability_status($product_object->get_id());
        echo '<div class="vsbbm-status-display">';
        echo '<p><strong>وضعیت فعلی:</strong> <span class="vsbbm-status vsbbm-status-' . sanitize_html_class($current_status['class']) . '">' . $current_status['text'] . '</span></p>';
        if (!empty($current_status['description'])) {
            echo '<p class="description">' . $current_status['description'] . '</p>';
        }
        echo '</div>';
        
        echo '</div>';
    }
    
    public static function save_product_fields($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        
        // ذخیره فعال‌سازی رزرو صندلی
        $enable = isset($_POST['_vsbbm_enable_seat_booking']) ? 'yes' : 'no';
        update_post_meta($post_id, '_vsbbm_enable_seat_booking', $enable);
        
        // ذخیره تاریخ شروع فروش
        if (isset($_POST['_vsbbm_sale_start_date'])) {
            update_post_meta($post_id, '_vsbbm_sale_start_date', sanitize_text_field($_POST['_vsbbm_sale_start_date']));
        } else {
            delete_post_meta($post_id, '_vsbbm_sale_start_date');
        }
        
        // ذخیره تاریخ پایان فروش
        if (isset($_POST['_vsbbm_sale_end_date'])) {
            update_post_meta($post_id, '_vsbbm_sale_end_date', sanitize_text_field($_POST['_vsbbm_sale_end_date']));
        } else {
            delete_post_meta($post_id, '_vsbbm_sale_end_date');
        }
    }
    
    public static function add_seat_meta_box() {
        global $post;
        if ($post && self::is_seat_booking_enabled($post->ID)) {
            add_meta_box(
                'vsbbm_seat_numbers',
                'تنظیمات صندلی‌ها',
                array(__CLASS__, 'render_seat_meta_box'),
                'product',
                'normal',
                'high'
            );
        }
    }
    
    public static function render_seat_meta_box($post) {
        $seat_numbers = get_post_meta($post->ID, '_vsbbm_seat_numbers', true);
        $seat_numbers = $seat_numbers ?: range(1, 32);
        ?>
        <div class="vsbbm-seat-settings">
            <p>
                <label for="vsbbm_seat_numbers"><strong>شماره صندلی‌های موجود:</strong></label>
                <input type="text" name="vsbbm_seat_numbers" id="vsbbm_seat_numbers" 
                       value="<?php echo esc_attr(implode(',', (array)$seat_numbers)); ?>" 
                       class="large-text">
                <br>
                <span class="description">شماره صندلی‌ها را با کاما جدا کنید. مثال: 1,2,3,4,5,...,32</span>
            </p>
            
            <h4>پیش‌نمایش چیدمان صندلی‌ها:</h4>
            <div class="vsbbm-seat-preview">
                <?php
                $rows = 4;
                $cols = 8;
                $i = 0;
                echo '<table style="border-collapse: collapse; margin: 10px 0; background: white; padding: 10px; border-radius: 5px;">';
                for ($r = 0; $r < $rows; $r++) {
                    echo '<tr>';
                    for ($c = 0; $c < $cols; $c++) {
                        $i++;
                        $is_available = in_array($i, $seat_numbers);
                        echo '<td style="border: 1px solid #ccc; padding: 8px; text-align: center; width: 40px; height: 40px; background: ' . ($is_available ? '#e8f5e8' : '#f5f5f5') . ';">';
                        echo $is_available ? '<strong>' . $i . '</strong>' : '<span style="color: #999;">×</span>';
                        echo '</td>';
                    }
                    echo '</tr>';
                }
                echo '</table>';
                ?>
            </div>
        </div>
        <?php
    }
    
    public static function save_seat_numbers($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        
        if (self::is_seat_booking_enabled($post_id) && isset($_POST['vsbbm_seat_numbers'])) {
            $numbers = array_map('intval', explode(',', $_POST['vsbbm_seat_numbers']));
            $numbers = array_filter($numbers);
            update_post_meta($post_id, '_vsbbm_seat_numbers', $numbers);
        }
    }
    
    /**
     * نمایش انتخاب صندلی در صفحه محصول
     */
    
    public static function display_seat_selection() {
    global $product;
    
    if (!$product) return;
    
    $product_id = $product->get_id();
    if (!self::is_seat_booking_enabled($product_id)) return;
    
    $available_seats = self::get_seat_numbers($product_id);
    $reserved_seats = self::get_reserved_seats($product_id);
    
    ?>
    <div class="vsbbm-seat-selection" data-product-id="<?php echo esc_attr($product_id); ?>" style="background: #fff; padding: 25px; margin: 30px 0; border: 2px solid #e0e0e0; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <h3 style="color: #2c3e50; margin-bottom: 20px; text-align: center;">🎫 انتخاب صندلی</h3>
        
        <div class="vsbbm-seat-layout" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(70px, 1fr)); gap: 12px; margin: 25px 0;">
            <?php foreach ($available_seats as $seat): 
                $is_reserved = in_array($seat, $reserved_seats);
                $seat_class = $is_reserved ? 'reserved' : 'available';
            ?>
                <div class="vsbbm-seat vsbbm-seat-<?php echo $seat_class; ?>" 
                     data-seat="<?php echo $seat; ?>"
                     style="padding: 15px 10px; text-align: center; border-radius: 8px; cursor: <?php echo $is_reserved ? 'not-allowed' : 'pointer'; ?>; transition: all 0.3s ease; font-weight: bold;"
                     onclick="vsbbmSelectSeat(<?php echo $seat; ?>, this)">
                    <?php echo $is_reserved ? '⛔' : '💺'; ?>
                    <br>
                    <span style="font-size: 12px;"><?php echo $seat; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- نمایش صندلی‌های انتخاب شده -->
        <div id="vsbbm-selected-seats" style="background: #e8f5e8; padding: 20px; border-radius: 8px; margin: 20px 0; <?php echo empty($selected_seats) ? 'display: none;' : ''; ?>">
            <h4 style="margin: 0 0 15px 0; color: #27ae60;">✅ صندلی‌های انتخاب شده:</h4>
            <div id="vsbbm-seats-list" style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 15px;">
                <!-- صندلی‌های انتخاب شده اینجا نمایش داده می‌شوند -->
            </div>
            
            <!-- فرم اطلاعات مسافر -->
            <div id="vsbbm-passenger-form" style="display: none;">
                <h5 style="margin: 15px 0 10px 0; color: #2c3e50;">📝 اطلاعات مسافران</h5>
                <div id="vsbbm-passenger-fields">
                    <!-- فیلدهای اطلاعات مسافر اینجا اضافه می‌شوند -->
                </div>
                
                <button type="button" onclick="vsbbmAddToCart()" style="background: #3498db; color: white; border: none; padding: 12px 25px; border-radius: 6px; cursor: pointer; font-size: 16px; margin-top: 15px;">
                    🛒 افزودن به سبد خرید
                </button>
            </div>
        </div>
        
        <!-- Hidden field برای ارسال داده‌ها -->
        <input type="hidden" id="vsbbm_passenger_data" name="vsbbm_passenger_data" value="">
        
        <!-- راهنما -->
        <div style="display: flex; justify-content: center; gap: 20px; margin-top: 20px; font-size: 14px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 5px;">
                <div style="width: 20px; height: 20px; background: #27ae60; border-radius: 4px;"></div>
                <span>قابل انتخاب</span>
            </div>
            <div style="display: flex; align-items: center; gap: 5px;">
                <div style="width: 20px; height: 20px; background: #e74c3c; border-radius: 4px;"></div>
                <span>رزرو شده</span>
            </div>
            <div style="display: flex; align-items: center; gap: 5px;">
                <div style="width: 20px; height: 20px; background: #f39c12; border-radius: 4px;"></div>
                <span>انتخاب شده</span>
            </div>
        </div>
    </div>

    <style>
    .vsbbm-seat-available {
        background: #27ae60;
        color: white;
        border: 2px solid #219652;
    }
    
    .vsbbm-seat-available:hover {
        background: #219652;
        transform: scale(1.05);
    }
    
    .vsbbm-seat-reserved {
        background: #e74c3c;
        color: white;
        border: 2px solid #c0392b;
    }
    
    .vsbbm-seat-selected {
        background: #f39c12 !important;
        border: 2px solid #e67e22 !important;
        transform: scale(1.1);
    }
    
    .vsbbm-seat-badge {
        background: #e74c3c;
        color: white;
        padding: 5px 10px;
        border-radius: 15px;
        font-size: 12px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    
    .vsbbm-passenger-field {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        margin: 10px 0;
        border: 1px solid #dee2e6;
    }

    /* مخفی کردن دکمه اصلی ووکامرس و انتخابگر تعداد وقتی انتخاب صندلی فعال است */
    .single_add_to_cart_button,
    button[name="add-to-cart"],
    .quantity,
    .qty,
    input[name="quantity"] {
        display: none !important;
    }
    </style>

    <script>
    if (typeof window.selectedSeats === 'undefined') {
        window.selectedSeats = [];
    }
    let selectedSeats = window.selectedSeats;

    function vsbbmSelectSeat(seatNumber, element) {
        // اگر صندلی رزرو شده باشد، کاری نکن
        if (element.classList.contains('vsbbm-seat-reserved')) {
            return;
        }
        
        const seatIndex = selectedSeats.indexOf(seatNumber);
        
        // اگر صندلی قبلاً انتخاب شده، حذف کن
        if (seatIndex > -1) {
            selectedSeats.splice(seatIndex, 1);
            element.classList.remove('vsbbm-seat-selected');
        } else {
            // اضافه کردن صندلی جدید
            selectedSeats.push(seatNumber);
            element.classList.add('vsbbm-seat-selected');
        }
        
        updateSelectedSeatsDisplay();
        updatePassengerForm();
        
        // ذخیره در localStorage
        localStorage.setItem('vsbbm_selected_seats', JSON.stringify(window.selectedSeats));
    }
    
    function updateSelectedSeatsDisplay() {
        const seatsList = document.getElementById('vsbbm-seats-list');
        const selectedContainer = document.getElementById('vsbbm-selected-seats');

        seatsList.innerHTML = '';

        if (window.selectedSeats.length === 0) {
            selectedContainer.style.display = 'none';
            return;
        }

        selectedContainer.style.display = 'block';

        window.selectedSeats.forEach(seat => {
            const seatBadge = document.createElement('div');
            seatBadge.className = 'vsbbm-seat-badge';
            seatBadge.innerHTML = `💺 صندلی ${seat} <span style="cursor: pointer; margin-left: 5px;" onclick="vsbbmRemoveSeat(${seat})">❌</span>`;
            seatsList.appendChild(seatBadge);
        });
    }
    
    function vsbbmRemoveSeat(seatNumber) {
        const seatIndex = selectedSeats.indexOf(seatNumber);
        if (seatIndex > -1) {
            selectedSeats.splice(seatIndex, 1);
            const seatElement = document.querySelector(`[data-seat="${seatNumber}"]`);
            if (seatElement) {
                seatElement.classList.remove('vsbbm-seat-selected');
            }
            updateSelectedSeatsDisplay();
            updatePassengerForm();
            localStorage.setItem('vsbbm_selected_seats', JSON.stringify(window.selectedSeats));
        }
    }
    
    function updatePassengerForm() {
    const passengerForm = document.getElementById('vsbbm-passenger-form');
    const passengerFields = document.getElementById('vsbbm-passenger-fields');

    if (window.selectedSeats.length === 0) {
        passengerForm.style.display = 'none';
        return;
    }

    passengerForm.style.display = 'block';
    passengerFields.innerHTML = '<p style="text-align: center; padding: 20px;">در حال بارگذاری فرم...</p>';
    
    // دریافت فیلدها از طریق AJAX
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=vsbbm_get_passenger_fields&nonce=<?php echo wp_create_nonce('vsbbm_frontend_nonce'); ?>'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            renderPassengerFields(data.data);
        } else {
            throw new Error('Failed to load fields');
        }
    })
    .catch(error => {
        console.error('Error loading passenger fields:', error);
        // استفاده از فیلدهای پیش‌فرض در صورت خطا
        const defaultFields = [
            {type: 'text', label: 'نام کامل', required: true, placeholder: 'نام و نام خانوادگی'},
            {type: 'text', label: 'کد ملی', required: true, placeholder: 'کد ملی ۱۰ رقمی'},
            {type: 'tel', label: 'شماره تماس', required: true, placeholder: '09xxxxxxxxx'}
        ];
        renderPassengerFields(defaultFields);
    });
    
    function renderPassengerFields(fieldsConfig) {
    passengerFields.innerHTML = '';
    
    const tableContainer = document.createElement('div');
    tableContainer.innerHTML = `
        <div style="background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 5px rgba(0,0,0,0.1); margin: 15px 0;">
            <div style="background: #2c3e50; color: white; padding: 12px 15px;">
                <h4 style="margin: 0; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                    <span>📋</span> اطلاعات مسافران (${window.selectedSeats.length} نفر)
                </h4>
            </div>
            
            <div style="overflow-x: auto; font-size: 12px;">
                <table style="width: 100%; border-collapse: collapse; min-width: 400px;" class="passenger-table">
                    <thead>
                        <tr style="background: #f8f9fa;">
                            <th style="padding: 8px 10px; text-align: center; border-bottom: 1px solid #dee2e6; width: 60px; font-size: 11px;">صندلی</th>
                            ${fieldsConfig.map(field => {
                                const isAddress = field.label.includes('آدرس');
                                const colWidth = isAddress ? '200px' : '120px';
                                return `
                                    <th style="padding: 8px 10px; text-align: right; border-bottom: 1px solid #dee2e6; font-size: 11px; width: ${colWidth};">
                                        ${field.label} ${field.required ? '<span style="color: #e74c3c;">*</span>' : ''}
                                    </th>
                                `;
                            }).join('')}
                        </tr>
                    </thead>
                    <tbody>
                        ${window.selectedSeats.map(seat => `
                            <tr style="border-bottom: 1px solid #f0f0f0;">
                                <td style="padding: 6px 8px; text-align: center; background: #f8f9fa; font-weight: bold; font-size: 11px;">
                                    ${seat}
                                </td>
                                ${fieldsConfig.map(field => {
                                    const fieldId = `passenger_${field.label.replace(/\s+/g, '_')}_${seat}`;
                                    const isRequired = field.required ? 'required' : '';
                                    const isAddress = field.label.includes('آدرس');
                                    
                                    if (field.type === 'select') {
                                        const options = field.options ? field.options.split(',').map(opt => opt.trim()) : [];
                                        return `
                                            <td style="padding: 4px 6px;">
                                                <select id="${fieldId}" name="${fieldId}" 
                                                        style="width: 100%; padding: 4px 6px; border: 1px solid #ddd; border-radius: 3px; background: white; font-size: 11px;" 
                                                        ${isRequired}>
                                                    <option value="">--</option>
                                                    ${options.map(option => 
                                                        `<option value="${option}">${option}</option>`
                                                    ).join('')}
                                                </select>
                                            </td>
                                        `;
                                    } else if (isAddress) {
                                        // فیلد آدرس بزرگتر
                                        return `
                                            <td style="padding: 4px 6px;">
                                                <textarea id="${fieldId}" name="${fieldId}" 
                                                          placeholder="${field.placeholder || ''}"
                                                          style="width: 100%; padding: 6px 8px; border: 1px solid #ddd; border-radius: 3px; font-size: 11px; resize: vertical; min-height: 40px;"
                                                          ${isRequired}></textarea>
                                            </td>
                                        `;
                                    } else {
                                        // فیلدهای معمولی کوچک
                                        return `
                                            <td style="padding: 4px 6px;">
                                                <input type="${field.type}" 
                                                       id="${fieldId}" 
                                                       name="${fieldId}" 
                                                       placeholder="${field.placeholder || ''}"
                                                       style="width: 100%; padding: 4px 6px; border: 1px solid #ddd; border-radius: 3px; font-size: 11px;"
                                                       ${isRequired}>
                                            </td>
                                        `;
                                    }
                                }).join('')}
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
            
            <div style="padding: 10px 15px; background: #f8f9fa; border-top: 1px solid #dee2e6; font-size: 11px; color: #666;">
                <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 5px;">
                        <div style="width: 12px; height: 12px; background: #e74c3c; border-radius: 2px;"></div>
                        <span>فیلد اجباری</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 5px;">
                        <div style="width: 12px; height: 12px; background: #2c3e50; border-radius: 2px;"></div>
                        <span>${window.selectedSeats.length} صندلی انتخاب شده</span>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .passenger-table tr:hover {
            background: #f5f5f5 !important;
        }
        .passenger-table input:focus,
        .passenger-table select:focus,
        .passenger-table textarea:focus {
            border-color: #3498db !important;
            outline: none;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.1);
        }
        .passenger-table input, 
        .passenger-table select {
            height: 28px;
        }
        .passenger-table textarea {
            min-height: 40px;
        }
        </style>
    `;
    
    passengerFields.appendChild(tableContainer);
}
}
    
    function vsbbmAddToCart() {
        if (window.selectedSeats.length === 0) {
            alert('لطفاً حداقل یک صندلی انتخاب کنید.');
            return false;
        }
        
        // جمع‌آوری اطلاعات مسافران از جدول
        const passengerData = [];
        let allFieldsValid = true;

        window.selectedSeats.forEach(seat => {
            const passenger = {};
            
            // دریافت تمام فیلدهای این مسافر
            const fieldInputs = document.querySelectorAll(`[name*="_${seat}"]`);
            
            fieldInputs.forEach(input => {
                const fieldName = input.name.match(/passenger_(.+?)_\d+/);
                if (fieldName && fieldName[1]) {
                    const key = fieldName[1];
                    passenger[key] = input.value.trim();
                    
                    // بررسی فیلدهای اجباری
                    if (input.hasAttribute('required') && !input.value.trim()) {
                        allFieldsValid = false;
                        input.style.borderColor = '#e74c3c';
                        input.style.backgroundColor = '#ffe6e6';
                    } else {
                        input.style.borderColor = '';
                        input.style.backgroundColor = '';
                    }
                }
            });
            
            passenger.seat_number = seat;
            passengerData.push(passenger);
        });
        
        // اگر فیلدهای اجباری پر نشده
        if (!allFieldsValid) {
            alert('❌ لطفاً تمام فیلدهای اجباری (مشخص شده با *) را پر کنید.');
            return false;
        }
        
        // بررسی کدهای ملی تکراری
        const nationalCodes = passengerData.map(p => p['نام_کامل'] || p['کد_ملی']).filter(Boolean);
        const uniqueCodes = new Set(nationalCodes);
        if (nationalCodes.length !== uniqueCodes.size) {
            alert('❌ کد ملی تکراری وجود دارد. هر مسافر باید کد ملی منحصر به فرد داشته باشد.');
            return false;
        }
        
        // ذخیره در localStorage برای backup
        localStorage.setItem('vsbbm_passenger_data', JSON.stringify(passengerData));
        localStorage.setItem('vsbbm_selected_seats', JSON.stringify(window.selectedSeats));
        
        console.log('✅ داده‌های مسافران:', passengerData);
        
        // استفاده از AJAX برای افزودن به سبد خرید
        console.log('📤 استفاده از AJAX برای افزودن به سبد خرید');
        
        // پیدا کردن product ID از data attribute
        const seatContainer = document.querySelector('.vsbbm-seat-selection');
        const productId = seatContainer ? seatContainer.getAttribute('data-product-id') : null;
        
        if (!productId) {
            alert('⚠️ خطا: شناسه محصول یافت نشد.');
            console.error('Product ID not found. Seat container:', seatContainer);
            return false;
        }
        
        console.log('✅ Product ID:', productId);
        
        // نمایش loading
        const buttonText = document.querySelector('button[onclick*="vsbbmAddToCart"]');
        if (buttonText) {
            buttonText.disabled = true;
            buttonText.innerHTML = '⏳ در حال افزودن...';
        }
        
        console.log('📤 Sending AJAX request...');
        console.log('📦 Data:', {
            action: 'vsbbm_add_to_cart',
            product_id: productId,
            quantity: window.selectedSeats.length,
            passengers: passengerData.length
        });
        
        // ارسال درخواست AJAX
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'vsbbm_add_to_cart',
                nonce: '<?php echo wp_create_nonce('vsbbm_frontend_nonce'); ?>',
                product_id: productId,
                quantity: window.selectedSeats.length,
                vsbbm_passenger_data: JSON.stringify(passengerData)
            })
        })
        .then(response => {
            console.log('📨 Response status:', response.status);
            console.log('📨 Response ok:', response.ok);
            
            // بررسی اگر response ok نیست
            if (!response.ok) {
                throw new Error('HTTP error! status: ' + response.status);
            }
            
            return response.text(); // اول به text تبدیل کنیم
        })
        .then(text => {
            console.log('📨 Raw response:', text);
            
            // حالا تلاش برای parse کردن JSON
            try {
                const data = JSON.parse(text);
                console.log('📨 Parsed data:', data);
                return data;
            } catch (e) {
                console.error('❌ JSON parse error:', e);
                console.error('❌ Response was:', text);
                throw new Error('Invalid JSON response');
            }
        })
        .then(data => {
            if (data.success) {
                console.log('✅ Success!');
                alert('✅ ' + window.selectedSeats.length + ' صندلی به سبد خرید اضافه شد!');
                // رفرش صفحه یا redirect به cart
                window.location.href = '<?php echo wc_get_cart_url(); ?>';
            } else {
                console.error('❌ Server returned error:', data);
                alert('❌ خطا: ' + (data.data || 'مشکلی پیش آمده'));
                if (buttonText) {
                    buttonText.disabled = false;
                    buttonText.innerHTML = '🛒 افزودن به سبد خرید';
                }
            }
        })
        .catch(error => {
            console.error('❌ Catch error:', error);
            alert('❌ خطا در ارتباط با سرور: ' + error.message);
            if (buttonText) {
                buttonText.disabled = false;
                buttonText.innerHTML = '🛒 افزودن به سبد خرید';
            }
        });
        
        return true;
    }
    
    // مخفی کردن دکمه اصلی ووکامرس و انتخابگر تعداد
    document.addEventListener('DOMContentLoaded', function() {
        const wooButtons = document.querySelectorAll('.single_add_to_cart_button, button[name="add-to-cart"]');
        wooButtons.forEach(button => {
            button.style.display = 'none';
        });

        const quantityElements = document.querySelectorAll('.quantity, .qty, input[name="quantity"]');
        quantityElements.forEach(element => {
            element.style.display = 'none';
        });
    });

    // بازیابی صندلی‌های انتخاب شده در صورت رفرش صفحه
    document.addEventListener('DOMContentLoaded', function() {
        const savedSeats = localStorage.getItem('vsbbm_selected_seats');
        if (savedSeats) {
            window.selectedSeats = JSON.parse(savedSeats);
            selectedSeats = window.selectedSeats;
            selectedSeats.forEach(seat => {
                const seatElement = document.querySelector(`[data-seat="${seat}"]`);
                if (seatElement && !seatElement.classList.contains('vsbbm-seat-reserved')) {
                    seatElement.classList.add('vsbbm-seat-selected');
                }
            });
            updateSelectedSeatsDisplay();
            updatePassengerForm();
        }
    });
    </script>
    <?php
}
    
    
    /**
     * نمایش وضعیت دسترسی محصول
     */
    public static function display_availability_status() {
        global $product;
        
        if (self::is_seat_booking_enabled($product->get_id())) {
            $status = self::get_product_availability_status($product->get_id());
            
            echo '<div class="vsbbm-availability-status vsbbm-status-' . $status['class'] . '" style="margin: 15px 0; padding: 10px; border-radius: 5px; background: #f8f9fa; border-left: 4px solid;">';
            echo '<strong>وضعیت رزرو:</strong> ' . $status['text'];
            if (!empty($status['description'])) {
                echo '<br><small>' . $status['description'] . '</small>';
            }
            echo '</div>';
        }
    }
    
    /**
     * بررسی وضعیت محصول (با کش)
     */
    public static function get_product_availability_status($product_id) {
        $cache_key = 'vsbbm_product_status_' . $product_id;
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $start_date = get_post_meta($product_id, '_vsbbm_sale_start_date', true);
        $end_date = get_post_meta($product_id, '_vsbbm_sale_end_date', true);
        $current_time = current_time('Y-m-d\TH:i');

        $status = array();

        // اگر هیچ تاریخی تنظیم نشده
        if (empty($start_date) && empty($end_date)) {
            $status = array(
                'class' => 'always-active',
                'text' => 'همیشه فعال',
                'description' => 'این محصول در هر زمانی قابل خریداری است'
            );
        }
        // اگر هنوز شروع نشده
        elseif (!empty($start_date) && $current_time < $start_date) {
            $time_left = human_time_diff(strtotime($current_time), strtotime($start_date));
            $status = array(
                'class' => 'not-started',
                'text' => 'شروع نشده',
                'description' => 'فروش ' . $time_left . ' دیگر شروع می‌شود'
            );
        }
        // اگر منقضی شده
        elseif (!empty($end_date) && $current_time > $end_date) {
            $status = array(
                'class' => 'expired',
                'text' => 'منقضی شده',
                'description' => 'زمان فروش این محصول به پایان رسیده است'
            );
        }
        // اگر فعال است
        elseif (!empty($end_date)) {
            $time_left = human_time_diff(strtotime($current_time), strtotime($end_date));
            $status = array(
                'class' => 'active',
                'text' => 'فعال',
                'description' => $time_left . ' تا پایان فروش باقی مانده'
            );
        }
        else {
            $status = array(
                'class' => 'active',
                'text' => 'فعال',
                'description' => 'این محصول قابل خریداری است'
            );
        }

        // کش برای ۱۰ دقیقه
        set_transient($cache_key, $status, 600);

        return $status;
    }
    
    /**
     * بررسی امکان خرید محصول
     */
    public static function is_product_available($product_id) {
        $status = self::get_product_availability_status($product_id);
        return in_array($status['class'], array('active', 'always-active'));
    }
    
    /**
     * بررسی فعال بودن رزرو صندلی برای محصول
     */
    public static function is_seat_booking_enabled($product_id) {
        return get_post_meta($product_id, '_vsbbm_enable_seat_booking', true) === 'yes';
    }
    
    /**
     * دریافت شماره صندلی‌ها
     */
    public static function get_seat_numbers($product_id) {
        $numbers = get_post_meta($product_id, '_vsbbm_seat_numbers', true);
        return $numbers ?: range(1, 32);
    }
    
    /**
     * دریافت صندلی‌های رزرو شده
     */
    public static function get_reserved_seats($product_id) {
        return VSBBM_Seat_Reservations::get_reserved_seats($product_id);
    }
    
    /**
     * فیلتر بررسی دسترسی محصول
     */
    public static function check_product_availability($purchasable, $product) {
        if (self::is_seat_booking_enabled($product->get_id())) {
            return self::is_product_available($product->get_id());
        }
        return $purchasable;
    }
    
    /**
     * استایل‌های مدیریت
     */
    public static function admin_styles() {
        echo '<style>
        .vsbbm-date-field {
            background: #f7f7f7;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 10px;
            border-left: 3px solid #667eea;
        }
        .vsbbm-status-display {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-top: 10px;
        }
        .vsbbm-status {
            padding: 4px 8px;
            border-radius: 3px;
            font-weight: bold;
        }
        .vsbbm-status-active { color: #4caf50; background: #e8f5e8; }
        .vsbbm-status-always-active { color: #2196f3; background: #e3f2fd; }
        .vsbbm-status-not-started { color: #ff9800; background: #fff3e0; }
        .vsbbm-status-expired { color: #f44336; background: #ffebee; }
        
        .vsbbm-seat-settings {
            padding: 15px;
        }
        .vsbbm-seat-preview {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            margin-top: 10px;
        }
        
        /* استایل‌های frontend */
        .vsbbm-availability-status { margin: 15px 0; padding: 10px; border-radius: 5px; background: #f8f9fa; border-left: 4px solid; }
        .vsbbm-status-active { border-color: #4caf50; background: #e8f5e8 !important; }
        .vsbbm-status-always-active { border-color: #2196f3; background: #e3f2fd !important; }
        .vsbbm-status-not-started { border-color: #ff9800; background: #fff3e0 !important; }
        .vsbbm-status-expired { border-color: #f44336; background: #ffebee !important; }
        </style>';
    }

    /*
    public static function VSBBM_Seat_Manager () {
    error_log('🎯 VSBBM_Seat_Manager INIT called');

    // هوک‌های مدیریت محصول (ادمین)
    add_action('woocommerce_product_options_general_product_data', array(__CLASS__, 'add_product_fields'));
    add_action('woocommerce_process_product_meta', array(__CLASS__, 'save_product_fields'));
    add_action('add_meta_boxes', array(__CLASS__, 'add_seat_meta_box'));
    add_action('save_post_product', array(__CLASS__, 'save_seat_numbers'));
    add_action('admin_head', array(__CLASS__, 'admin_styles'));

    // هوک‌های جدید برای تم‌های مدرن
    add_action('woocommerce_before_add_to_cart_form', array(__CLASS__, 'display_seat_selection'), 10);
    add_action('woocommerce_after_single_product_summary', array(__CLASS__, 'display_seat_selection'), 5);

    // هوک‌های دیگر
    add_filter('woocommerce_is_purchasable', array(__CLASS__, 'check_product_availability'), 10, 2);
    add_action('woocommerce_single_product_summary', array(__CLASS__, 'display_availability_status'), 25);

    //هوک های مختلف
       // تست چندین هوک مختلف
    add_action('woocommerce_before_single_product', function() {
        echo '<!-- 🎯 HOOK TEST: woocommerce_before_single_product -->';
    });

    add_action('woocommerce_before_add_to_cart_form', function() {
        echo '<!-- 🎯 HOOK TEST: woocommerce_before_add_to_cart_form -->';
    });

    add_action('woocommerce_after_single_product_summary', function() {
        echo '<!-- 🎯 HOOK TEST: woocommerce_after_single_product_summary -->';
    });

    add_action('wp_footer', function() {
        if (is_product()) {
            echo '<!-- 🎯 HOOK TEST: wp_footer on product page -->';
        }
    });

    error_log('🎯 VSBBM_Seat_Manager: all hooks registered');
}

}

}

}

}

}

}

}
    */
/**
 * افزودن صندلی‌های انتخاب شده به سبد خرید از طریق AJAX
 */
public static function add_to_cart_ajax() {
    // فعال‌سازی فشرده‌سازی خروجی
    if (!headers_sent()) {
        if (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) {
            ob_start('ob_gzhandler');
        }
    }

    // بررسی nonce
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'vsbbm_frontend_nonce')) {
        wp_send_json_error('امنیت درخواست تایید نشد');
        return;
    }

    // Debug log
    error_log('🔵 VSBBM AJAX: add_to_cart_ajax called');
    error_log('🔵 POST data: ' . print_r($_POST, true));

    // بررسی داده‌ها
    if (empty($_POST['product_id']) || empty($_POST['vsbbm_passenger_data'])) {
        error_log('🔴 VSBBM AJAX: Missing data');
        wp_send_json_error('اطلاعات ناقص است');
        return;
    }
    
    $product_id = intval($_POST['product_id']);
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
    $passenger_data_json = wp_unslash($_POST['vsbbm_passenger_data']);
    
    error_log('🔵 VSBBM AJAX: Product ID = ' . $product_id);
    error_log('🔵 VSBBM AJAX: Quantity = ' . $quantity);
    
    // بررسی فعال بودن رزرو صندلی
    $is_enabled = self::is_seat_booking_enabled($product_id);
    error_log('🔵 VSBBM AJAX: Is seat booking enabled? ' . ($is_enabled ? 'YES' : 'NO'));
    
    // بررسی مستقیم meta
    $meta_value = get_post_meta($product_id, '_vsbbm_enable_seat_booking', true);
    error_log('🔵 VSBBM AJAX: Meta value = ' . $meta_value);
    
    if (!$is_enabled) {
        error_log('🔴 VSBBM AJAX: Seat booking NOT enabled for product ' . $product_id);
        wp_send_json_error('رزرو صندلی برای این محصول فعال نیست (Product ID: ' . $product_id . ', Meta: ' . $meta_value . ')');
        return;
    }

    // Parse کردن داده‌های مسافر
    $passenger_data = json_decode($passenger_data_json, true);
    error_log('🔵 VSBBM AJAX: Passenger data decoded: ' . print_r($passenger_data, true));

    if (!is_array($passenger_data) || empty($passenger_data)) {
        error_log('🔴 VSBBM AJAX: Invalid passenger data');
        wp_send_json_error('اطلاعات مسافران نامعتبر است');
        return;
    }

    // رزرو صندلی‌ها قبل از افزودن به سبد خرید
    $selected_seats = array_column($passenger_data, 'seat_number');
    $reservation_result = VSBBM_Seat_Reservations::reserve_seats(
        $product_id,
        $selected_seats,
        null, // order_id will be set later
        get_current_user_id(),
        $passenger_data
    );

    if (is_wp_error($reservation_result)) {
        error_log('🔴 VSBBM AJAX: Seat reservation failed: ' . $reservation_result->get_error_message());
        wp_send_json_error($reservation_result->get_error_message());
        return;
    }

    error_log('🟢 VSBBM AJAX: Seats reserved successfully: ' . implode(', ', $reservation_result));
    error_log('🔵 VSBBM AJAX: Passenger data decoded: ' . print_r($passenger_data, true));
    
    if (!is_array($passenger_data) || empty($passenger_data)) {
        error_log('🔴 VSBBM AJAX: Invalid passenger data');
        wp_send_json_error('اطلاعات مسافران نامعتبر است');
        return;
    }
    
    error_log('🔵 VSBBM AJAX: Starting validation...');

    // Validation (استفاده از همان لاجیک class-booking-handler)
    require_once VSBBM_PLUGIN_PATH . 'includes/class-booking-handler.php';

    // شبیه‌سازی $_POST برای validation
    $_POST['vsbbm_passenger_data'] = $passenger_data_json;

    // اجرای validation
    $validated = VSBBM_Booking_Handler::validate_booking(true, $product_id, $quantity);

    error_log('🔵 VSBBM AJAX: Validation result: ' . ($validated ? 'PASSED' : 'FAILED'));

    if (!$validated) {
        // آزاد کردن رزروهای انجام شده در صورت خطا در validation
        VSBBM_Seat_Reservations::cancel_reservation_by_order(null); // برای کاربر فعلی

        // خطاهای validation از طریق wc_add_notice اضافه شدن
        // دریافت پیام‌های خطا
        $notices = wc_get_notices('error');
        error_log('🔴 VSBBM AJAX: Validation errors: ' . print_r($notices, true));
        $error_message = '';
        if (!empty($notices)) {
            foreach ($notices as $notice) {
                $error_message .= $notice['notice'] . ' ';
            }
            wc_clear_notices();
        }
        wp_send_json_error($error_message ?: 'خطا در اعتبارسنجی');
        return;
    }

    error_log('🔵 VSBBM AJAX: Validation passed, adding to cart...');
    
    // افزودن به سبد خرید
    $cart_item_data = array(
        'vsbbm_passengers' => $passenger_data
    );
    
    error_log('🔵 VSBBM AJAX: Cart item data: ' . print_r($cart_item_data, true));
    
    $added = WC()->cart->add_to_cart($product_id, $quantity, 0, array(), $cart_item_data);
    
    error_log('🔵 VSBBM AJAX: Add to cart result: ' . ($added ? $added : 'FALSE'));
    
    if ($added) {
        error_log('🟢 VSBBM AJAX: Successfully added to cart!');
        wp_send_json_success(array(
            'message' => sprintf('%d صندلی به سبد خرید اضافه شد', $quantity),
            'cart_url' => wc_get_cart_url()
        ));
    } else {
        error_log('🔴 VSBBM AJAX: Failed to add to cart');
        wp_send_json_error('خطا در افزودن به سبد خرید');
    }
}


/**
 * دریافت فیلدهای مسافر از طریق AJAX (با کش)
 */
public static function get_passenger_fields_ajax() {
    // فعال‌سازی فشرده‌سازی خروجی
    if (!headers_sent()) {
        if (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) {
            ob_start('ob_gzhandler');
        }
    }

    // بررسی nonce
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'vsbbm_frontend_nonce')) {
        wp_send_json_error('امنیت درخواست تایید نشد');
        return;
    }

    $cache_key = 'vsbbm_passenger_fields';
    $cached = get_transient($cache_key);

    if ($cached !== false) {
        wp_send_json_success($cached);
        return;
    }

    $fields = get_option('vsbbm_passenger_fields', array(
        array('type' => 'text', 'label' => 'نام کامل', 'required' => true, 'placeholder' => 'نام و نام خانوادگی', 'locked' => false),
        array('type' => 'text', 'label' => 'کد ملی', 'required' => true, 'placeholder' => 'کد ملی ۱۰ رقمی', 'locked' => true),
        array('type' => 'tel', 'label' => 'شماره تماس', 'required' => true, 'placeholder' => '09xxxxxxxxx', 'locked' => false),
    ));

    // کش برای ۱ ساعت
    set_transient($cache_key, $fields, 3600);

    wp_send_json_success($fields);
}

/**
 * ثبت هوک‌های AJAX
 */
public static function register_ajax_handlers() {
    error_log('🟡 VSBBM: Registering AJAX handlers...');
    
    add_action('wp_ajax_vsbbm_get_passenger_fields', array(__CLASS__, 'get_passenger_fields_ajax'));
    add_action('wp_ajax_nopriv_vsbbm_get_passenger_fields', array(__CLASS__, 'get_passenger_fields_ajax'));
    
    // افزودن AJAX handler برای add to cart
    add_action('wp_ajax_vsbbm_add_to_cart', array(__CLASS__, 'add_to_cart_ajax'));
    add_action('wp_ajax_nopriv_vsbbm_add_to_cart', array(__CLASS__, 'add_to_cart_ajax'));
    
    error_log('🟢 VSBBM: AJAX handlers registered successfully');
}
}