<?php
defined('ABSPATH') || exit;

class VSBBM_Booking_Handler {
    
    public static function init() {
        // add_action('woocommerce_before_add_to_cart_button', array(__CLASS__, 'render_seat_selector')); // غیرفعال - استفاده از سیستم جدید
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'validate_booking'), 10, 3);
        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'add_cart_item_data'), 10, 3);
        add_filter('woocommerce_add_cart_item', array(__CLASS__, 'update_cart_item_quantity'), 10, 2);
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'display_cart_item_data'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'save_order_item_meta'), 10, 4);
        //add_filter('woocommerce_is_sold_individually', array(__CLASS__, 'sold_individually'), 10, 2);
        add_filter('woocommerce_product_single_add_to_cart_text', array(__CLASS__, 'change_add_to_cart_text'), 10, 2);
        add_filter('woocommerce_cart_item_quantity', array(__CLASS__, 'change_cart_item_quantity'), 10, 3);
    }
    
    public static function render_seat_selector() {
        global $product;
        
        if (!VSBBM_Seat_Manager::is_seat_booking_enabled($product->get_id())) {
            return;
        }
        
        $seat_numbers = VSBBM_Seat_Manager::get_seat_numbers($product->get_id());
        // توجه: این تابع get_reserved_seats باید به‌روزرسانی شود تا تاریخ را نیز در نظر بگیرد
        $reserved_seats = VSBBM_Seat_Reservations::get_reserved_seats($product->get_id());
        
        wp_enqueue_style('vsbbm-frontend', VSBBM_PLUGIN_URL . 'assets/css/frontend.css');
        wp_enqueue_script('vsbbm-frontend', VSBBM_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), VSBBM_VERSION, true);
        
        wp_localize_script('vsbbm-frontend', 'vsbbm_data', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'reserved_seats' => $reserved_seats,
            'select_at_least_one' => __('لطفا حداقل یک صندلی انتخاب کنید', 'vs-bus-booking-manager'),
            'fill_all_fields' => __('لطفا تمام فیلدها را پر کنید', 'vs-bus-booking-manager')
        ));
        
        include VSBBM_PLUGIN_PATH . 'templates/seat-selector.php';
    }
    
    public static function validate_booking($passed, $product_id, $quantity) {
        if (!VSBBM_Seat_Manager::is_seat_booking_enabled($product_id)) {
            return $passed;
        }
        
        // توجه: از آنجایی که از سیستم AJAX جدید استفاده می‌کنیم، این اعتبارسنجی‌ها 
        // در class-seat-manager::add_to_cart_ajax انجام می‌شوند و این تابع در حقیقت 
        // دیگر برای ماژول رزرو صندلی فعال نخواهد بود. اما برای حفظ بک‌وارد کامپتیبیلیتی باقی می‌ماند.
        
        if (empty($_POST['vsbbm_passenger_data'])) {
            wc_add_notice('❌ لطفا ابتدا صندلی انتخاب کرده و اطلاعات مسافران را وارد کنید', 'error');
            return false;
        }
        
        $passenger_data = json_decode(wp_unslash($_POST['vsbbm_passenger_data']), true);
        
        if (!is_array($passenger_data) || empty($passenger_data)) {
            wc_add_notice('❌ اطلاعات مسافران نامعتبر است', 'error');
            return false;
        }
        
        // توجه: این فراخوانی باید به‌روزرسانی شود تا تاریخ حرکت را نیز لحاظ کند
        $reserved_seats = VSBBM_Seat_Reservations::get_reserved_seats($product_id); 
        $selected_seats = array();
        $national_codes = array();
        
        foreach ($passenger_data as $index => $passenger) {
            // بررسی وجود seat_number
            if (empty($passenger['seat_number'])) {
                wc_add_notice(sprintf('❌ شماره صندلی مسافر %d مشخص نیست', $index + 1), 'error');
                return false;
            }
            
            $seat_number = $passenger['seat_number'];
            
            // بررسی فیلدهای اجباری - دینامیک بر اساس فیلدهای تعریف شده
            $required_fields = get_option('vsbbm_passenger_fields', array());
            
            error_log('🔍 Checking passenger for seat ' . $seat_number);
            error_log('🔍 Passenger data: ' . print_r($passenger, true));
            error_log('🔍 Required fields config: ' . print_r($required_fields, true));
            
            foreach ($required_fields as $field) {
                if ($field['required']) {
                    // نام فیلد ممکنه با فاصله یا _ باشه
                    $field_label = $field['label'];
                    $field_key = str_replace(' ', '_', $field_label);
                    $field_key_alt = str_replace(' ', '', $field_label); // بدون فاصله و _
                    
                    error_log('🔍 Looking for field: ' . $field_label . ' (key: ' . $field_key . ')');
                    
                    // بررسی با چند حالت مختلف
                    $field_value = null;
                    if (isset($passenger[$field_key])) {
                        $field_value = $passenger[$field_key];
                    } elseif (isset($passenger[$field_label])) {
                        $field_value = $passenger[$field_label];
                    } elseif (isset($passenger[$field_key_alt])) {
                        $field_value = $passenger[$field_key_alt];
                    }
                    
                    error_log('🔍 Field value found: ' . ($field_value ? $field_value : 'EMPTY'));
                    
                    if (empty($field_value)) {
                        wc_add_notice(sprintf('❌ فیلد "%s" برای مسافر صندلی %d الزامی است', $field_label, $seat_number), 'error');
                        return false;
                    }
                    
                    // ذخیره کد ملی برای بررسی لیست سیاه و تکراری
                    if ($field_label === 'کد ملی' || $field_key === 'کد_ملی') {
                        $national_code = $field_value;
                        
                        // بررسی لیست سیاه
                        if (VSBBM_Blacklist::is_blacklisted($national_code)) {
                            wc_add_notice(sprintf('❌ کد ملی %s در لیست سیاه قرار دارد', $national_code), 'error');
                            return false;
                        }
                        
                        // بررسی تکراری نبودن کد ملی
                        if (in_array($national_code, $national_codes)) {
                            wc_add_notice('❌ کد ملی تکراری وجود دارد. هر مسافر باید کد ملی منحصر به فرد داشته باشد', 'error');
                            return false;
                        }
                        $national_codes[] = $national_code;
                    }
                }
            }
            
            // بررسی تکراری نبودن صندلی
            if (in_array($seat_number, $selected_seats)) {
                wc_add_notice(sprintf('❌ صندلی %d بیش از یک بار انتخاب شده است', $seat_number), 'error');
                return false;
            }
            
            // بررسی رزرو نبودن صندلی
            if (in_array($seat_number, $reserved_seats)) {
                wc_add_notice(sprintf('❌ صندلی %d قبلا رزرو شده است', $seat_number), 'error');
                return false;
            }
            
            $selected_seats[] = $seat_number;
        }
        
        return true;
    }
    
    /**
     * اضافه کردن داده‌های رزرو به آیتم سبد خرید
     * توجه: در حالت AJAX، بخش عمده داده‌ها توسط class-seat-manager اضافه می‌شود.
     * این تابع به عنوان یک لایه کمکی برای حالت‌های دیگر باقی می‌ماند.
     */
    public static function add_cart_item_data($cart_item_data, $product_id, $variation_id) {
        if (!VSBBM_Seat_Manager::is_seat_booking_enabled($product_id)) {
            return $cart_item_data;
        }
        
        // منطق قبلی برای مسافران
        if (!empty($_POST['vsbbm_passenger_data'])) {
            $passenger_data = json_decode(wp_unslash($_POST['vsbbm_passenger_data']), true);
            $cart_item_data['vsbbm_passengers'] = $passenger_data;
        }

        // --- گام C.۳: اضافه کردن تاریخ حرکت (اگر از طریق AJAX ارسال نشده باشد) ---
        if (isset($_POST['vsbbm_departure_timestamp']) && !empty($_POST['vsbbm_departure_timestamp'])) {
            $cart_item_data['vsbbm_departure_timestamp'] = sanitize_text_field($_POST['vsbbm_departure_timestamp']);
        }
        
        return $cart_item_data;
    }
    
    public static function update_cart_item_quantity($cart_item_data, $cart_item_key) {
        if (isset($cart_item_data['vsbbm_passengers'])) {
            // تعداد آیتم سبد خرید همیشه برابر با تعداد مسافران است
            $cart_item_data['quantity'] = count($cart_item_data['vsbbm_passengers']);
        }
        return $cart_item_data;
    }
    
    /**
     * نمایش اطلاعات مسافر و تاریخ حرکت در صفحات سبد خرید و تسویه‌حساب
     */
    public static function display_cart_item_data($item_data, $cart_item) {
        if (isset($cart_item['vsbbm_passengers'])) {
            foreach ($cart_item['vsbbm_passengers'] as $index => $passenger) {
                // ساخت متن نمایشی بر اساس فیلدهای موجود
                $display_parts = array();
                
                foreach ($passenger as $key => $value) {
                    if ($key !== 'seat_number' && !empty($value)) {
                        // تبدیل _ به فاصله برای نمایش بهتر
                        $label = str_replace('_', ' ', $key);
                        $display_parts[] = "$label: $value";
                    }
                }
                
                // اضافه کردن شماره صندلی در آخر
                if (!empty($passenger['seat_number'])) {
                    $display_parts[] = "صندلی: " . $passenger['seat_number'];
                }
                
                $item_data[] = array(
                    'name' => sprintf('🎫 مسافر %d', $index + 1),
                    'value' => implode(' | ', $display_parts)
                );
            }
        }
        
        // --- گام C.۳: نمایش تاریخ حرکت ---
        if (isset($cart_item['vsbbm_departure_timestamp']) && !empty($cart_item['vsbbm_departure_timestamp'])) {
            $timestamp = $cart_item['vsbbm_departure_timestamp'];
            
            // اطمینان از فرمت تاریخ
            try {
                $datetime_obj = new DateTime($timestamp);
                // استفاده از date_i18n برای نمایش صحیح تاریخ بومی
                $formatted_date = date_i18n('Y/m/d H:i', $datetime_obj->getTimestamp()); 
            } catch (Exception $e) {
                $formatted_date = $timestamp; // Fallback
            }
            
            $item_data[] = array(
                'name'  => esc_html__('تاریخ حرکت', 'vs-bus-booking-manager'),
                'value' => $formatted_date
            );
        }
        
        return $item_data;
    }
    
    /**
     * ذخیره متادیتای آیتم سفارش (مسافران و تاریخ حرکت)
     */
    public static function save_order_item_meta($item, $cart_item_key, $values, $order) {
        if (isset($values['vsbbm_passengers'])) {
            foreach ($values['vsbbm_passengers'] as $index => $passenger) {
                // ساخت متن ذخیره بر اساس فیلدهای موجود
                $meta_parts = array();

                foreach ($passenger as $key => $value) {
                    if ($key !== 'seat_number' && !empty($value)) {
                        $label = str_replace('_', ' ', $key);
                        $meta_parts[] = "$label: $value";
                    }
                }

                // اضافه کردن شماره صندلی در آخر
                if (!empty($passenger['seat_number'])) {
                    $meta_parts[] = "صندلی: " . $passenger['seat_number'];
                }

                $item->add_meta_data(
                    sprintf('🎫 مسافر %d', $index + 1),
                    implode(' | ', $meta_parts)
                );
            }

            // بروزرسانی رزروها با order ID
            $order_id = $order->get_id();
            // فرض می‌کنیم کلاس VSBBM_Seat_Reservations در دسترس است
            if (class_exists('VSBBM_Seat_Reservations')) {
                 VSBBM_Seat_Reservations::update_reservation_order_id($order_id, $values['vsbbm_passengers']);
            }
        }
        
        // --- گام C.۳: ذخیره تاریخ حرکت ---
        if (isset($values['vsbbm_departure_timestamp']) && !empty($values['vsbbm_departure_timestamp'])) {
            $timestamp = $values['vsbbm_departure_timestamp'];
            
            // ذخیره تاریخ خام (کلید خصوصی برای Ticket Manager)
            $item->add_meta_data(
                '_vsbbm_departure_timestamp', 
                $timestamp
            );
            
            // ذخیره تاریخ قابل نمایش برای مدیر
            try {
                $datetime_obj = new DateTime($timestamp);
                $display_date = date_i18n('Y/m/d H:i', $datetime_obj->getTimestamp());
            } catch (Exception $e) {
                $display_date = $timestamp;
            }
            
             $item->add_meta_data(
                esc_html__('تاریخ حرکت', 'vs-bus-booking-manager'),
                $display_date
            );
        }
    }
    
    public static function sold_individually($return, $product) {
        if (VSBBM_Seat_Manager::is_seat_booking_enabled($product->get_id())) {
            return true;
        }
        return $return;
    }
    
    public static function change_add_to_cart_text($text, $product) {
        if (VSBBM_Seat_Manager::is_seat_booking_enabled($product->get_id())) {
            return 'رزرو صندلی';
        }
        return $text;
    }
    
    public static function change_cart_item_quantity($product_quantity, $cart_item_key, $cart_item) {
        if (isset($cart_item['vsbbm_passengers'])) {
            return sprintf('<span class="vsbbm-passenger-count">%d مسافر</span>', count($cart_item['vsbbm_passengers']));
        }
        return $product_quantity;
    }
    /**
     * محاسبه قیمت بر اساس تعداد صندلی‌های انتخاب شده
     */
    public static function update_cart_item_price($cart_item_data, $cart_item_key) {
        if (isset($cart_item_data['vsbbm_passengers'])) {
            $passenger_count = count($cart_item_data['vsbbm_passengers']);
            $original_price = $cart_item_data['data']->get_price();
            
            // قیمت جدید = قیمت اصلی × تعداد مسافران
            $new_price = $original_price * $passenger_count;
            $cart_item_data['data']->set_price($new_price);
            
            // ذخیره اطلاعات برای استفاده در session
            $cart_item_data['vsbbm_original_price'] = $original_price;
            $cart_item_data['vsbbm_passenger_count'] = $passenger_count;
        }
        return $cart_item_data;
    }

    public static function get_cart_item_from_session($cart_item_data, $values) {
        if (isset($values['vsbbm_passengers'])) {
            $passenger_count = count($values['vsbbm_passengers']);
            $original_price = isset($values['vsbbm_original_price']) ? 
                             $values['vsbbm_original_price'] : 
                             $cart_item_data['data']->get_price();
            
            // محاسبه مجدد قیمت هنگام لود از session
            $new_price = $original_price * $passenger_count;
            $cart_item_data['data']->set_price($new_price);
            
            // نگهداری اطلاعات اصلی
            $cart_item_data['vsbbm_original_price'] = $original_price;
            $cart_item_data['vsbbm_passenger_count'] = $passenger_count;
        }
        return $cart_item_data;
    }

    /**
     * نمایش قیمت صحیح در سبد خرید
     */
    public static function display_cart_item_price($price, $cart_item, $cart_item_key) {
        if (isset($cart_item['vsbbm_passengers'])) {
            $passenger_count = count($cart_item['vsbbm_passengers']);
            $original_price = isset($cart_item['vsbbm_original_price']) ? 
                             $cart_item['vsbbm_original_price'] : 
                             $cart_item['data']->get_price() / $passenger_count;
            
            // نمایش: (قیمت واحد × تعداد) = قیمت نهایی
            $total_price = wc_price($original_price * $passenger_count);
            $unit_price = wc_price($original_price);
            
            return sprintf('%s <small>(%s × %d)</small>', 
                          $total_price, 
                          $unit_price, 
                          $passenger_count);
        }
        return $price;
    }

    /**
     * نمایش زیرمجموعه صحیح در سبد خرید
     */
    public static function display_cart_item_subtotal($subtotal, $cart_item, $cart_item_key) {
        if (isset($cart_item['vsbbm_passengers'])) {
            $passenger_count = count($cart_item['vsbbm_passengers']);
            $original_price = isset($cart_item['vsbbm_original_price']) ? 
                             $cart_item['vsbbm_original_price'] : 
                             $cart_item['data']->get_price() / $passenger_count;
            
            return wc_price($original_price * $passenger_count);
        }
        return $subtotal;
    }
} // <-- End of class

/**
 * محاسبه قیمت بر اساس تعداد صندلی‌های انتخاب شده
 */
add_filter('woocommerce_add_cart_item', array('VSBBM_Booking_Handler', 'update_cart_item_price'), 10, 2);
add_filter('woocommerce_get_cart_item_from_session', array('VSBBM_Booking_Handler', 'get_cart_item_from_session'), 10, 2);

/**
 * نمایش قیمت صحیح در سبد خرید
 */
add_filter('woocommerce_cart_item_price', array('VSBBM_Booking_Handler', 'display_cart_item_price'), 10, 3);

/**
 * نمایش زیرمجموعه صحیح در سبد خرید
 */
add_filter('woocommerce_cart_item_subtotal', array('VSBBM_Booking_Handler', 'display_cart_item_subtotal'), 10, 3);