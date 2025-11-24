<?php
defined('ABSPATH') || exit;

class VSBBM_Seat_Manager {
    
    public static function init() {
        static $initialized = false;
        if ($initialized) {
            return;
        }
        $initialized = true;

        // هوک‌های مدیریت محصول (ادمین)
        add_action('woocommerce_product_options_general_product_data', array(__CLASS__, 'add_product_fields'));
        add_action('woocommerce_process_product_meta', array(__CLASS__, 'save_product_fields'));
        
        // متاباکس چیدمان صندلی (قدیمی)
        add_action('add_meta_boxes', array(__CLASS__, 'add_seat_meta_box'));
        add_action('save_post_product', array(__CLASS__, 'save_seat_numbers'));

        // *** متاباکس جدید برای زمان‌بندی سفر (گام B) ***
        add_action('add_meta_boxes', array(__CLASS__, 'add_schedule_meta_box'));
        add_action('save_post_product', array(__CLASS__, 'save_schedule_settings'));
        
        add_action('admin_head', array(__CLASS__, 'admin_styles'));

        // ثبت هندلرهای AJAX
        self::register_ajax_handlers();

        // هوک نمایش انتخاب صندلی
        add_action('woocommerce_single_product_summary', array(__CLASS__, 'display_seat_selection'), 25);
        
        // enqueue scripts (گام C.1)
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_frontend_scripts'));

        // فیلتر برای دیباگ
        add_filter('the_content', array(__CLASS__, 'check_product_page'), 1);
    }

    // --- توابع کمکی ---

    /**
     * دریافت تنظیمات سفر محصول
     */
    public static function get_trip_settings($product_id) {
        $settings = get_post_meta($product_id, '_vsbbm_trip_settings', true);
        
        // مقادیر پیش‌فرض
        return wp_parse_args($settings, array(
            'schedule_type' => 'open', // open, one-time, recurring
            'origin'        => '',
            'destination'   => '',
            'one_time_date' => '',
            'one_time_time' => '10:00',
            'recurring_days'=> array(),
            'recurring_time'=> '10:00',
            'start_date'    => '',
            'end_date'      => '',
        ));
    }
    
    /**
     * محاسبه تاریخ‌های موجود بر اساس تنظیمات تکرارشونده
     */
    private static function calculate_available_dates($settings) {
        $dates = array();
        $start_date = strtotime($settings['start_date']);
        $end_date = strtotime($settings['end_date']);
        $recurring_days = (array)$settings['recurring_days'];
        
        if (!$start_date || !$end_date || empty($recurring_days)) {
            return $dates;
        }

        $current = $start_date;
        $today = strtotime(date('Y-m-d')); // فقط تاریخ‌های آینده
        
        $day_names = array(
            0 => esc_html__('یکشنبه', 'vs-bus-booking-manager'), 
            1 => esc_html__('دوشنبه', 'vs-bus-booking-manager'), 
            2 => esc_html__('سه‌شنبه', 'vs-bus-booking-manager'), 
            3 => esc_html__('چهارشنبه', 'vs-bus-booking-manager'), 
            4 => esc_html__('پنج‌شنبه', 'vs-bus-booking-manager'), 
            5 => esc_html__('جمعه', 'vs-bus-booking-manager'), 
            6 => esc_html__('شنبه', 'vs-bus-booking-manager')
        );

        // محدودیت سه‌ماهه (۹۰ روز) برای جلوگیری از بارگذاری زیاد
        $limit_date = strtotime('+90 days'); 
        
        while ($current <= $end_date && $current <= $limit_date) {
            // فقط تاریخ‌های آینده را نشان دهید
            if ($current >= $today) {
                $day_of_week = date('w', $current);
                if (in_array($day_of_week, $recurring_days)) {
                    $date_str = date('Y-m-d', $current);
                    $dates[$date_str] = $day_names[$day_of_week];
                }
            }
            $current = strtotime('+1 day', $current);
        }
        
        return $dates;
    }

    // --- توابع مدیریت محصول (ادمین - موجود در فایل اصلی کاربر) ---

    /**
     * اضافه کردن فیلدهای محصول ووکامرس
     */
    public static function add_product_fields() {
        echo '<div class="options_group">';
        woocommerce_wp_checkbox(array(
            'id' => '_vsbbm_enable_seat_booking',
            'label' => esc_html__('فعال‌سازی رزرو صندلی', 'vs-bus-booking-manager'),
            'description' => esc_html__('با فعال‌سازی این گزینه، چیدمان صندلی‌ها در صفحه محصول نمایش داده می‌شود.', 'vs-bus-booking-manager'),
        ));
        echo '</div>';
    }

    /**
     * ذخیره فیلدهای محصول ووکامرس
     */
    public static function save_product_fields($post_id) {
        $checkbox_value = isset($_POST['_vsbbm_enable_seat_booking']) ? 'yes' : 'no';
        update_post_meta($post_id, '_vsbbm_enable_seat_booking', $checkbox_value);
    }
    
    // --- توابع مدیریت زمان‌بندی سفر (گام B) ---

    /**
     * اضافه کردن متاباکس تنظیمات سفر و زمان‌بندی
     */
    public static function add_schedule_meta_box() {
        add_meta_box(
            'vsbbm_trip_schedule',
            esc_html__('تنظیمات سفر و زمان‌بندی', 'vs-bus-booking-manager'),
            array(__CLASS__, 'render_schedule_meta_box'),
            'product',
            'normal',
            'high'
        );
    }

    /**
     * رندر متاباکس تنظیمات سفر و زمان‌بندی
     */
    public static function render_schedule_meta_box($post) {
        $post_id = $post->ID;
        $settings = self::get_trip_settings($post_id);
        
        wp_nonce_field('vsbbm_trip_schedule_nonce', 'vsbbm_trip_schedule_nonce');
        
        ?>
        <div id="vsbbm-trip-schedule-admin">
            <h3><?php echo esc_html__('مسیر سفر', 'vs-bus-booking-manager'); ?></h3>
            <p class="description"><?php echo esc_html__('تعریف مبدأ و مقصد برای نمایش بهتر در بلیط و گزارشات.', 'vs-bus-booking-manager'); ?></p>
            
            <p>
                <label for="vsbbm_origin"><strong><?php echo esc_html__('مبدأ حرکت', 'vs-bus-booking-manager'); ?>:</strong></label>
                <input type="text" id="vsbbm_origin" name="vsbbm_trip_settings[origin]" value="<?php echo esc_attr($settings['origin']); ?>" class="regular-text" placeholder="<?php echo esc_attr__('مثلاً: تهران', 'vs-bus-booking-manager'); ?>" />
            </p>
            
            <p>
                <label for="vsbbm_destination"><strong><?php echo esc_html__('مقصد سفر', 'vs-bus-booking-manager'); ?>:</strong></label>
                <input type="text" id="vsbbm_destination" name="vsbbm_trip_settings[destination]" value="<?php echo esc_attr($settings['destination']); ?>" class="regular-text" placeholder="<?php echo esc_attr__('مثلاً: اصفهان', 'vs-bus-booking-manager'); ?>" />
            </p>

            <hr/>

            <h3><?php echo esc_html__('برنامه‌ریزی حرکت', 'vs-bus-booking-manager'); ?></h3>
            
            <p>
                <label><strong><?php echo esc_html__('نوع برنامه‌ریزی', 'vs-bus-booking-manager'); ?>:</strong></label><br/>
                <input type="radio" id="schedule_open" name="vsbbm_trip_settings[schedule_type]" value="open" <?php checked($settings['schedule_type'], 'open'); ?> data-target="schedule-open" /> 
                <label for="schedule_open"><?php echo esc_html__('بدون محدودیت تاریخ (همیشه باز)', 'vs-bus-booking-manager'); ?></label><br/>
                
                <input type="radio" id="schedule_one_time" name="vsbbm_trip_settings[schedule_type]" value="one-time" <?php checked($settings['schedule_type'], 'one-time'); ?> data-target="schedule-one-time" />
                <label for="schedule_one_time"><?php echo esc_html__('یک حرکت مشخص', 'vs-bus-booking-manager'); ?></label><br/>
                
                <input type="radio" id="schedule_recurring" name="vsbbm_trip_settings[schedule_type]" value="recurring" <?php checked($settings['schedule_type'], 'recurring'); ?> data-target="schedule-recurring" />
                <label for="schedule_recurring"><?php echo esc_html__('حرکت تکرارشونده (هفتگی)', 'vs-bus-booking-manager'); ?></label>
            </p>

            <div id="schedule-options">
                
                <div id="schedule-one-time" class="schedule-group" style="display: none;">
                    <h4><?php echo esc_html__('تاریخ و زمان حرکت', 'vs-bus-booking-manager'); ?></h4>
                    <p>
                        <label for="vsbbm_one_time_date"><?php echo esc_html__('تاریخ:', 'vs-bus-booking-manager'); ?></label>
                        <input type="date" id="vsbbm_one_time_date" name="vsbbm_trip_settings[one_time_date]" value="<?php echo esc_attr($settings['one_time_date']); ?>" />
                    </p>
                    <p>
                        <label for="vsbbm_one_time_time"><?php echo esc_html__('ساعت حرکت:', 'vs-bus-booking-manager'); ?></label>
                        <input type="time" id="vsbbm_one_time_time" name="vsbbm_trip_settings[one_time_time]" value="<?php echo esc_attr($settings['one_time_time']); ?>" />
                    </p>
                </div>

                <div id="schedule-recurring" class="schedule-group" style="display: none;">
                    <h4><?php echo esc_html__('تنظیمات تکرار', 'vs-bus-booking-manager'); ?></h4>
                    <p class="description"><?php echo esc_html__('تعیین روزهای هفته و بازه زمانی برای این برنامه.', 'vs-bus-booking-manager'); ?></p>
                    
                    <p>
                        <label for="vsbbm_start_date"><?php echo esc_html__('تاریخ شروع بازه:', 'vs-bus-booking-manager'); ?></label>
                        <input type="date" id="vsbbm_start_date" name="vsbbm_trip_settings[start_date]" value="<?php echo esc_attr($settings['start_date']); ?>" />
                    </p>
                    <p>
                        <label for="vsbbm_end_date"><?php echo esc_html__('تاریخ پایان بازه:', 'vs-bus-booking-manager'); ?></label>
                        <input type="date" id="vsbbm_end_date" name="vsbbm_trip_settings[end_date]" value="<?php echo esc_attr($settings['end_date']); ?>" />
                    </p>
                    
                    <p>
                        <label><strong><?php echo esc_html__('روزهای هفته:', 'vs-bus-booking-manager'); ?></strong></label><br/>
                        <?php 
                        $week_days = array(
                            0 => esc_html__('یکشنبه', 'vs-bus-booking-manager'), 
                            1 => esc_html__('دوشنبه', 'vs-bus-booking-manager'), 
                            2 => esc_html__('سه‌شنبه', 'vs-bus-booking-manager'), 
                            3 => esc_html__('چهارشنبه', 'vs-bus-booking-manager'), 
                            4 => esc_html__('پنج‌شنبه', 'vs-bus-booking-manager'), 
                            5 => esc_html__('جمعه', 'vs-bus-booking-manager'), 
                            6 => esc_html__('شنبه', 'vs-bus-booking-manager')
                        ); 
                        
                        foreach ($week_days as $key => $day) {
                            $checked = in_array($key, (array)$settings['recurring_days']) ? 'checked' : '';
                            echo "<input type='checkbox' id='day_{$key}' name='vsbbm_trip_settings[recurring_days][]' value='{$key}' {$checked} /> <label for='day_{$key}'>{$day}</label><br/>";
                        }
                        ?>
                    </p>
                    <p>
                        <label for="vsbbm_recurring_time"><?php echo esc_html__('ساعت حرکت:', 'vs-bus-booking-manager'); ?></label>
                        <input type="time" id="vsbbm_recurring_time" name="vsbbm_trip_settings[recurring_time]" value="<?php echo esc_attr($settings['recurring_time']); ?>" />
                    </p>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            function toggleScheduleFields() {
                const selectedType = $('input[name="vsbbm_trip_settings[schedule_type]"]:checked').val();
                $('.schedule-group').hide();
                if (selectedType === 'one-time') {
                    $('#schedule-one-time').show();
                } else if (selectedType === 'recurring') {
                    $('#schedule-recurring').show();
                }
            }

            // اجرا هنگام بارگذاری صفحه و تغییر
            toggleScheduleFields();
            $('input[name="vsbbm_trip_settings[schedule_type]"]').change(toggleScheduleFields);
        });
        </script>
        <?php
    }

    /**
     * ذخیره تنظیمات سفر و زمان‌بندی
     */
    public static function save_schedule_settings($post_id) {
        // بررسی مجوزها و نانس
        if (!isset($_POST['vsbbm_trip_schedule_nonce']) || !wp_verify_nonce($_POST['vsbbm_trip_schedule_nonce'], 'vsbbm_trip_schedule_nonce')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_product', $post_id)) {
            return;
        }
        
        if (isset($_POST['vsbbm_trip_settings'])) {
            $settings = wp_unslash($_POST['vsbbm_trip_settings']);

            // تمیز کردن داده‌ها (Sanitization)
            $sanitized_settings = array();
            $sanitized_settings['schedule_type'] = sanitize_text_field($settings['schedule_type']);
            $sanitized_settings['origin'] = sanitize_text_field($settings['origin']);
            $sanitized_settings['destination'] = sanitize_text_field($settings['destination']);
            
            if ($sanitized_settings['schedule_type'] === 'one-time') {
                $sanitized_settings['one_time_date'] = sanitize_text_field($settings['one_time_date']);
                $sanitized_settings['one_time_time'] = sanitize_text_field($settings['one_time_time']);
            } elseif ($sanitized_settings['schedule_type'] === 'recurring') {
                $sanitized_settings['start_date'] = sanitize_text_field($settings['start_date']);
                $sanitized_settings['end_date'] = sanitize_text_field($settings['end_date']);
                $sanitized_settings['recurring_time'] = sanitize_text_field($settings['recurring_time']);
                // آرایه روزهای هفته
                $sanitized_settings['recurring_days'] = array_map('intval', (array)$settings['recurring_days']);
            }

            // ذخیره نهایی
            update_post_meta($post_id, '_vsbbm_trip_settings', $sanitized_settings);
        }
    }
    
    // --- توابع چیدمان صندلی (موجود در فایل اصلی کاربر) ---

    /**
     * اضافه کردن متاباکس چیدمان صندلی
     */
    public static function add_seat_meta_box() {
        add_meta_box(
            'vsbbm_seat_layout',
            esc_html__('چیدمان صندلی اتوبوس', 'vs-bus-booking-manager'),
            array(__CLASS__, 'render_seat_meta_box'),
            'product',
            'normal',
            'high'
        );
    }

    /**
     * رندر متاباکس چیدمان صندلی
     */
    public static function render_seat_meta_box($post) {
        $post_id = $post->ID;
        $seat_numbers_json = self::get_seat_numbers($post_id, true);
        $total_seats = count(self::get_seat_numbers($post_id, false));
        
        wp_nonce_field('vsbbm_seat_layout_nonce', 'vsbbm_seat_layout_nonce');
        ?>
        <div id="vsbbm-seat-manager-admin">
            <p class="description"><?php echo esc_html__('با دابل کلیک روی هر صندلی، آن را حذف یا اضافه کنید.', 'vs-bus-booking-manager'); ?></p>
            
            <div id="vsbbm-seat-map" data-initial-layout='<?php echo esc_attr($seat_numbers_json); ?>'>
                <div class="vsbbm-bus-body">
                    <div class="vsbbm-driver-seat">
                        <img src="<?php echo VSBBM_PLUGIN_URL . 'assets/images/steering-wheel.png'; ?>" alt="Driver" />
                    </div>
                </div>
            </div>
            
            <input type="hidden" id="vsbbm_seat_numbers" name="vsbbm_seat_numbers" value="<?php echo esc_attr($seat_numbers_json); ?>" />
            <p>
                <strong><?php echo esc_html__('تعداد کل صندلی‌های فعال:', 'vs-bus-booking-manager'); ?></strong> 
                <span id="vsbbm-total-seats"><?php echo esc_html($total_seats); ?></span>
            </p>
        </div>
        
        <?php
    }

    /**
     * ذخیره چیدمان صندلی‌ها
     */
    public static function save_seat_numbers($post_id) {
        // بررسی مجوزها و نانس
        if (!isset($_POST['vsbbm_seat_layout_nonce']) || !wp_verify_nonce($_POST['vsbbm_seat_layout_nonce'], 'vsbbm_seat_layout_nonce')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_product', $post_id)) {
            return;
        }

        if (isset($_POST['vsbbm_seat_numbers'])) {
            $seat_numbers = wp_unslash($_POST['vsbbm_seat_numbers']);
            // فرض می‌کنیم داده JSON معتبر است
            update_post_meta($post_id, '_vsbbm_seat_numbers', $seat_numbers);
        }
    }
    
    // --- توابع Script (ادمین و فرانت‌اند) ---

    /**
     * اضافه کردن استایل‌های ادمین
     */
    public static function admin_styles() {
        ?>
        <style>
            #vsbbm-trip-schedule-admin label strong {
                display: block;
                margin-bottom: 5px;
            }
            #vsbbm-trip-schedule-admin input[type="date"],
            #vsbbm-trip-schedule-admin input[type="time"] {
                padding: 6px;
                border-radius: 4px;
                border: 1px solid #ccc;
            }
        </style>
        <?php
    }
    
    /**
     * انکیو کردن اسکریپت‌های فرانت‌اند
     */
    public static function enqueue_frontend_scripts() {
        if (is_product()) {
            global $post;
            if (self::is_seat_booking_enabled($post->ID)) {
                
                // ما به یک اسکریپت نیاز داریم که انتخاب تاریخ را هندل کند
                wp_enqueue_script('vsbbm-frontend-date-handler', VSBBM_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), VSBBM_VERSION, true);
                
                // Localize کردن متغیرهای لازم برای جاوااسکریپت
                wp_localize_script('vsbbm-frontend-date-handler', 'vsbbm_ajax_vars', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('vsbbm_seat_selection_nonce'),
                    'loading_text' => esc_html__('در حال بارگذاری صندلی‌ها...', 'vs-bus-booking-manager'),
                    'select_date_error' => esc_html__('لطفاً یک تاریخ حرکت معتبر انتخاب کنید.', 'vs-bus-booking-manager')
                ));
            }
        }
    }


    // --- توابع نمایش صندلی (فرانت‌اند - گام C) ---

    /**
     * رندر رابط کاربری انتخاب صندلی
     */
    public static function display_seat_selection() {
        global $product;
        if (!$product || !self::is_seat_booking_enabled($product->get_id())) {
            return;
        }

        $product_id = $product->get_id();
        $settings = self::get_trip_settings($product_id);
        $schedule_type = $settings['schedule_type'];
        $departure_timestamp = ''; 
        $seat_layout = self::get_seat_numbers($product_id);

        if (empty($seat_layout)) {
            return;
        }

        // 1. نمایش انتخابگر تاریخ اگر لازم باشد
        if ($schedule_type === 'one-time') {
            // برای سفر یک‌بار مصرف، تاریخ مشخص است و مستقیماً نمایش می‌دهیم
            $date = $settings['one_time_date'];
            $time = $settings['one_time_time'];
            if ($date) {
                // ساخت timestamp کامل (YYYY-MM-DD HH:MM:SS)
                $departure_timestamp = "{$date} {$time}:00"; 
            }
        } elseif ($schedule_type === 'recurring') {
            // برای سفر تکرارشونده، انتخابگر تاریخ را نمایش می‌دهیم و نقشه صندلی مخفی می‌ماند
            self::render_date_selector($product_id, $settings);
            // از اینجا به بعد، نمایش نقشه صندلی توسط AJAX انجام می‌شود
            return; 
        }

        // 2. اگر تاریخ مشخص است (open یا one-time)، نقشه صندلی را نمایش می‌دهیم
        $reserved_seats = array();
        // برای حالت open، timestamp خالی است و VSBBM_Seat_Reservations::get_reserved_seats همه رزروهای بدون تاریخ را برمی‌گرداند.
        if (!empty($departure_timestamp) || $schedule_type === 'open') {
             // فرض می‌کنیم کلاس VSBBM_Seat_Reservations قبلاً include شده است
            $reserved_seats_data = VSBBM_Seat_Reservations::get_reserved_seats($product_id, $departure_timestamp);
            $reserved_seats = array_column($reserved_seats_data, 'seat_number');
        }

        // رندر HTML اصلی نقشه صندلی
        $args = array(
            'product_id' => $product_id,
            'schedule_type' => $schedule_type,
            'departure_timestamp' => $departure_timestamp,
            'seat_layout' => $seat_layout,
            'reserved_seats' => $reserved_seats,
            'trip_settings' => $settings,
        );
        
        // رندر template (seat-selector.php)
        wc_get_template('seat-selector.php', $args, '', VSBBM_PLUGIN_PATH . 'templates/');
    }

    /**
     * رندر انتخابگر تاریخ برای سفرهای تکرارشونده
     */
    public static function render_date_selector($product_id, $settings) {
        $available_dates = self::calculate_available_dates($settings);
        
        // اگر تاریخ‌های موجود خالی باشد، پیام مناسب نمایش داده می‌شود.
        if (empty($available_dates)) {
            echo '<div id="vsbbm-date-selector-wrapper" class="vsbbm-error-message">';
            echo '<h3>' . esc_html__('برنامه حرکت', 'vs-bus-booking-manager') . '</h3>';
            echo '<p>' . esc_html__('در حال حاضر هیچ سفری در بازه زمانی تعیین شده در دسترس نیست.', 'vs-bus-booking-manager') . '</p>';
            echo '</div>';
            return;
        }

        echo '<div id="vsbbm-date-selector-wrapper" data-product-id="' . esc_attr($product_id) . '" data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '">';
        echo '<h3>' . esc_html__('انتخاب تاریخ حرکت', 'vs-bus-booking-manager') . '</h3>';
        
        echo '<div class="vsbbm-trip-info">';
        echo '<p><strong>' . esc_html__('مسیر:', 'vs-bus-booking-manager') . '</strong> ' . esc_html($settings['origin']) . ' &rarr; ' . esc_html($settings['destination']) . '</p>';
        echo '<p><strong>' . esc_html__('ساعت حرکت:', 'vs-bus-booking-manager') . '</strong> ' . esc_html($settings['recurring_time']) . '</p>';
        echo '</div>';

        echo '<label for="vsbbm_departure_date">' . esc_html__('تاریخ حرکت را انتخاب کنید:', 'vs-bus-booking-manager') . '</label>';
        echo '<select id="vsbbm_departure_date" class="vsbbm-select-date" name="vsbbm_departure_date" data-product-id="' . esc_attr($product_id) . '">';
        echo '<option value="">-- ' . esc_html__('انتخاب کنید', 'vs-bus-booking-manager') . ' --</option>';
        
        foreach ($available_dates as $date => $day_name) {
            $timestamp = "{$date} {$settings['recurring_time']}:00";
            echo '<option value="' . esc_attr($timestamp) . '">' . esc_html("{$day_name}، {$date}") . '</option>';
        }
        
        echo '</select>';

        // Placeholder for the seat map, initially hidden
        echo '<div id="vsbbm-seat-selector-container" style="min-height: 200px;">' . esc_html__('لطفاً تاریخ حرکت را انتخاب کنید.', 'vs-bus-booking-manager') . '</div>';

        echo '</div>'; 
    }
    
    // --- توابع AJAX (گام C) ---

    /**
     * ثبت هوک‌های AJAX
     */
    public static function register_ajax_handlers() {
        
        // هندلرهای موجود
        add_action('wp_ajax_vsbbm_get_passenger_fields', array(__CLASS__, 'get_passenger_fields_ajax'));
        add_action('wp_ajax_nopriv_vsbbm_get_passenger_fields', array(__CLASS__, 'get_passenger_fields_ajax'));
        
        add_action('wp_ajax_vsbbm_add_to_cart', array(__CLASS__, 'add_to_cart_ajax'));
        add_action('wp_ajax_nopriv_vsbbm_add_to_cart', array(__CLASS__, 'add_to_cart_ajax'));

        // *** هندلر جدید برای بارگذاری صندلی بر اساس تاریخ ***
        add_action('wp_ajax_vsbbm_get_seat_data', array(__CLASS__, 'get_seat_data_ajax'));
        add_action('wp_ajax_nopriv_vsbbm_get_seat_data', array(__CLASS__, 'get_seat_data_ajax'));
    }

    /**
     * دریافت داده‌های صندلی‌ها بر اساس تاریخ حرکت (AJAX)
     */
    public static function get_seat_data_ajax() {
        // بررسی امنیتی
        if (!isset($_POST['product_id']) || !isset($_POST['departure_timestamp'])) {
            wp_send_json_error(array('message' => __('تاریخ حرکت یا شناسه محصول مشخص نشده است.', 'vs-bus-booking-manager')));
        }
        
        $product_id = intval($_POST['product_id']);
        $departure_timestamp = sanitize_text_field($_POST['departure_timestamp']); 

        // 1. دریافت تنظیمات محصول
        $settings = self::get_trip_settings($product_id);
        $seat_layout = self::get_seat_numbers($product_id);
        
        if (empty($seat_layout)) {
             wp_send_json_error(array('message' => __('چیدمان صندلی برای این محصول تعریف نشده است.', 'vs-bus-booking-manager')));
        }

        // 2. دریافت صندلی‌های رزرو شده (با فیلتر تاریخ)
        if (class_exists('VSBBM_Seat_Reservations')) {
            $reserved_seats_data = VSBBM_Seat_Reservations::get_reserved_seats($product_id, $departure_timestamp);
        } else {
             wp_send_json_error(array('message' => __('خطا: ماژول رزرو صندلی در دسترس نیست.', 'vs-bus-booking-manager')));
        }
        
        // 3. آماده‌سازی داده‌ها برای رندر
        $reserved_seats = array_column($reserved_seats_data, 'seat_number');

        // 4. رندر و ارسال HTML (استفاده از seat-selector.php)
        ob_start();
        wc_get_template('seat-selector.php', array(
            'product_id' => $product_id,
            'seat_layout' => $seat_layout,
            'reserved_seats' => $reserved_seats,
            'departure_timestamp' => $departure_timestamp,
            'trip_settings' => $settings,
        ), '', VSBBM_PLUGIN_PATH . 'templates/');
        $html = ob_get_clean();

        wp_send_json_success(array(
            'html' => $html,
            'reserved_seats' => $reserved_seats,
            'timestamp' => $departure_timestamp,
            'origin' => $settings['origin'],
            'destination' => $settings['destination'],
        ));
    }
    
    // --- توابع کمکی اصلی (موجود در فایل اصلی کاربر) ---
    
    /**
     * دریافت آرایه چیدمان صندلی
     */
    public static function get_seat_numbers($product_id, $as_json = false) {
        $seat_layout = get_post_meta($product_id, '_vsbbm_seat_numbers', true);

        if (empty($seat_layout)) {
            return $as_json ? '[]' : array();
        }

        if ($as_json) {
            return $seat_layout;
        }

        // تبدیل JSON به آرایه PHP
        $seats_array = json_decode($seat_layout, true);
        
        // اگر JSON دیکود نشد یا خالی بود
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($seats_array)) {
            return array();
        }
        
        return $seats_array;
    }

    /**
     * بررسی فعال بودن رزرو صندلی برای محصول
     */
    public static function is_seat_booking_enabled($product_id) {
        return 'yes' === get_post_meta($product_id, '_vsbbm_enable_seat_booking', true);
    }
    
    /**
     * دریافت وضعیت دسترسی محصول
     */
    public static function get_product_availability_status($product_id) {
        // (این تابع باید منطق اصلی شما را داشته باشد)
        return 'available'; 
    }

    /**
     * دریافت فیلدهای مسافر برای نمایش در فرانت‌اند (AJAX)
     */
    public static function get_passenger_fields_ajax() {
        
        // مثال داده‌های فیلد (باید با داده‌های واقعی پلاگین شما مطابقت داشته باشد)
        $fields = array(
            array('type' => 'text', 'label' => esc_html__('نام و نام خانوادگی', 'vs-bus-booking-manager'), 'required' => true, 'placeholder' => esc_html__('نام کامل مسافر', 'vs-bus-booking-manager'), 'locked' => false),
            array('type' => 'text', 'label' => esc_html__('کد ملی', 'vs-bus-booking-manager'), 'required' => true, 'placeholder' => esc_html__('کد ملی ۱۰ رقمی', 'vs-bus-booking-manager'), 'locked' => true),
            array('type' => 'tel', 'label' => esc_html__('شماره تماس', 'vs-bus-booking-manager'), 'required' => true, 'placeholder' => '09xxxxxxxxx', 'locked' => false),
        );
        
        // کش برای ۱ ساعت
        $cache_key = 'vsbbm_passenger_fields';
        set_transient($cache_key, $fields, 3600); 

        wp_send_json_success($fields);
    }
    
    /**
     * افزودن به سبد خرید (AJAX) - به‌روزرسانی شده برای تاریخ حرکت
     */
    public static function add_to_cart_ajax() {
        
        if (!isset($_POST['product_id']) || !isset($_POST['selected_seats']) || !isset($_POST['passengers_data']) || !isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'vsbbm_seat_selection_nonce')) {
            wp_send_json_error(array('message' => esc_html__('داده‌های ورودی نامعتبر است.', 'vs-bus-booking-manager')));
        }
        
        $product_id = intval($_POST['product_id']);
        $selected_seats = (array) $_POST['selected_seats'];
        $passengers_data = (array) $_POST['passengers_data'];
        $departure_timestamp = isset($_POST['vsbbm_departure_timestamp']) ? sanitize_text_field($_POST['vsbbm_departure_timestamp']) : ''; // <-- گرفتن تاریخ حرکت

        // 1. بررسی اعتبارسنجی‌ها
        if (count($selected_seats) !== count($passengers_data)) {
            wp_send_json_error(array('message' => esc_html__('تعداد صندلی‌ها و مسافران باید یکسان باشد.', 'vs-bus-booking-manager')));
        }
        
        // آماده‌سازی داده برای رزرو
        $seats_data_for_reservation = array();
        for ($i = 0; $i < count($selected_seats); $i++) {
            $seats_data_for_reservation[] = array(
                'seat_number' => $selected_seats[$i],
                'passenger_data' => $passengers_data[$i],
            );
        }

        // 2. رزرو صندلی موقت در دیتابیس (استفاده از کلاس VSBBM_Seat_Reservations)
        if (class_exists('VSBBM_Seat_Reservations')) {
            $reservation_result = VSBBM_Seat_Reservations::reserve_seats(
                $product_id, 
                $departure_timestamp, // ارسال تاریخ حرکت
                $seats_data_for_reservation, 
                15 // 15 دقیقه رزرو موقت
            );
            
            if (is_wp_error($reservation_result)) {
                wp_send_json_error(array('message' => $reservation_result->get_error_message()));
            }
            
            $reservation_keys = $reservation_result;
        } else {
            wp_send_json_error(array('message' => esc_html__('خطا در ماژول رزرو صندلی.', 'vs-bus-booking-manager')));
        }

        // 3. افزودن به سبد خرید ووکامرس
        $cart_item_data = array(
            'vsbbm_reservation_keys' => $reservation_keys,
            'vsbbm_departure_timestamp' => $departure_timestamp, // ارسال به Booking Handler
            'vsbbm_seats' => $selected_seats,
            'vsbbm_passengers' => $passengers_data,
        );

        $add_to_cart_result = WC()->cart->add_to_cart(
            $product_id, 
            count($selected_seats), // تعداد آیتم‌ها برابر تعداد صندلی‌ها
            0, // Variation ID
            array(), // Attributes
            $cart_item_data // Custom data
        );

        if ($add_to_cart_result) {
            wp_send_json_success(array(
                'message' => esc_html__('صندلی‌ها با موفقیت رزرو و به سبد خرید اضافه شدند.', 'vs-bus-booking-manager'),
                'cart_url' => wc_get_cart_url(),
                'checkout_url' => wc_get_checkout_url(),
            ));
        } else {
            // در صورت خطا در افزودن به سبد، رزروهای موقت را لغو می‌کنیم
            VSBBM_Seat_Reservations::cancel_reservations_by_keys($reservation_keys);
            wp_send_json_error(array('message' => esc_html__('خطا در افزودن محصول به سبد خرید.', 'vs-bus-booking-manager')));
        }
    }
    
    /**
     * بررسی صفحه محصول برای دیباگ
     */
    public static function check_product_page($content) {
        return $content;
    }
}