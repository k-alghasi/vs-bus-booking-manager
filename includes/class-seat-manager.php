<?php
defined('ABSPATH') || exit;

/**
 * Class VSBBM_Seat_Manager
 * مدیریت تنظیمات صندلی و نمایش انتخابگر در محصول
 */
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
        
        // متاباکس چیدمان صندلی 
        add_action('add_meta_boxes', array(__CLASS__, 'add_seat_meta_box'));
        add_action('save_post_product', array(__CLASS__, 'save_seat_numbers'));

        // *** متاباکس جدید برای زمان‌بندی سفر ***
        add_action('add_meta_boxes', array(__CLASS__, 'add_schedule_meta_box'));
        add_action('save_post_product', array(__CLASS__, 'save_schedule_settings'));
        
        // --- FIX: افزودن هوک‌های بارگذاری اسکریپت‌ها و استایل‌ها ---
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_scripts'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_frontend_scripts'));
        // ------------------------------------------------
        
        add_action('admin_head', array(__CLASS__, 'admin_styles'));

        // ثبت هندلرهای AJAX
        self::register_ajax_handlers();

        // هوک نمایش انتخاب صندلی در صفحه محصول
        add_action('woocommerce_single_product_summary', array(__CLASS__, 'display_seat_selection'), 25);
        
        // فیلتر برای دیباگ
        add_filter('the_content', array(__CLASS__, 'check_product_page'), 1);
    }
    
    /**
     * بارگذاری اسکریپت‌ها و استایل‌های ادمین (FIX)
     */
    public static function enqueue_admin_scripts($hook) {
        $screen = get_current_screen();
        
        if ($screen && $screen->id === 'product') {
            // برای تنظیمات چیدمان صندلی
            wp_enqueue_script('vsbbm-admin-seat', VSBBM_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), VSBBM_VERSION, true);
            wp_enqueue_style('vsbbm-admin-seat', VSBBM_PLUGIN_URL . 'assets/css/admin.css', array(), VSBBM_VERSION);
            
            // برای Date/Time Picker
            wp_enqueue_style('jquery-ui-datepicker-style', '//ajax.googleapis.com/ajax/libs/jqueryui/1.10.4/themes/smoothness/jquery-ui.css');
            wp_enqueue_script('jquery-ui-datepicker');
            
            // اسکریپت زمان‌بندی سفر
            wp_enqueue_script('vsbbm-admin-schedule', VSBBM_PLUGIN_URL . 'assets/js/frontend.js', array('jquery', 'jquery-ui-datepicker'), VSBBM_VERSION, true);
        }
    }

    /**
     * بارگذاری اسکریپت‌ها و استایل‌های فرانت‌اند (FIX)
     */
    public static function enqueue_frontend_scripts() {
        if (is_product()) {
            global $post;
            
            if (self::is_seat_booking_enabled($post->ID)) {
                // استایل و اسکریپت اصلی فرانت‌اند
                wp_enqueue_style('vsbbm-frontend-seat', VSBBM_PLUGIN_URL . 'assets/css/frontend.css', array(), VSBBM_VERSION);
                wp_enqueue_script('vsbbm-frontend', VSBBM_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), VSBBM_VERSION, true);
                
                // لوکالایز کردن اسکریپت با داده‌های مورد نیاز
                wp_localize_script('vsbbm-frontend', 'vsbbm_ajax_object', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'product_id' => $post->ID,
                    'nonce' => wp_create_nonce('vsbbm_seat_nonce'),
                    // رزروها باید توسط تابع AJAX لود شوند تا کش شوند
                    'reservations' => array(), 
                    'schedule_settings' => self::get_schedule_settings($post->ID), 
                    'current_time' => current_time('timestamp'),
                    'currency_symbol' => get_woocommerce_currency_symbol(),
                    'text' => array(
                        'select_departure_date' => __('لطفاً تاریخ حرکت را انتخاب کنید.', 'vs-bus-booking-manager'),
                        'seat_reserved' => __('صندلی رزرو شده', 'vs-bus-booking-manager'),
                        'seat_selected' => __('صندلی انتخاب شده', 'vs-bus-booking-manager'),
                        'seat_available' => __('صندلی موجود', 'vs-bus-booking-manager'),
                        'fill_passenger_data' => __('لطفاً اطلاعات مسافران را تکمیل کنید.', 'vs-bus-booking-manager'),
                        'max_seats_reached' => __('تعداد صندلی‌های انتخابی مجاز نیست.', 'vs-bus-booking-manager'),
                    )
                ));
            }
        }
    }

    /**
     * اضافه کردن فیلدهای محصول
     */
    public static function add_product_fields() {
        global $product_object;

        echo '<div class="options_group">';
        
        woocommerce_wp_checkbox(array(
            'id' => '_vsbbm_enable_seat_booking',
            'value' => get_post_meta($product_object->get_id(), '_vsbbm_enable_seat_booking', true),
            'label' => __('رزرو صندلی اتوبوس', 'vs-bus-booking-manager'),
            'description' => __('فعال‌سازی سیستم انتخاب صندلی برای این محصول.', 'vs-bus-booking-manager'),
        ));

        woocommerce_wp_select(array(
            'id' => '_vsbbm_bus_type',
            'value' => get_post_meta($product_object->get_id(), '_vsbbm_bus_type', true),
            'label' => __('نوع اتوبوس', 'vs-bus-booking-manager'),
            'description' => __('چیدمان گرافیکی صندلی را انتخاب کنید.', 'vs-bus-booking-manager'),
            'options' => array(
                '' => __('هیچکدام', 'vs-bus-booking-manager'),
                '2x2' => __('2x2 (معمولی)', 'vs-bus-booking-manager'),
                '1x2' => __('1x2 (VIP)', 'vs-bus-booking-manager'),
                '1x1' => __('1x1 (سفارشی)', 'vs-bus-booking-manager'),
            ),
        ));
        
        woocommerce_wp_text_input(array(
            'id' => '_vsbbm_bus_rows',
            'value' => get_post_meta($product_object->get_id(), '_vsbbm_bus_rows', true),
            'label' => __('تعداد ردیف‌ها', 'vs-bus-booking-manager'),
            'description' => __('حداکثر تعداد ردیف‌های صندلی اتوبوس.', 'vs-bus-booking-manager'),
            'data_type' => 'number',
            'custom_attributes' => array(
                'min' => '1',
                'step' => '1',
            ),
        ));

        woocommerce_wp_text_input(array(
            'id' => '_vsbbm_bus_columns',
            'value' => get_post_meta($product_object->get_id(), '_vsbbm_bus_columns', true),
            'label' => __('تعداد ستون‌ها', 'vs-bus-booking-manager'),
            'description' => __('تعداد ستون‌های صندلی در هر سمت (برای چیدمان 2x2 باید 2 باشد).', 'vs-bus-booking-manager'),
            'data_type' => 'number',
            'custom_attributes' => array(
                'min' => '1',
                'step' => '1',
            ),
        ));

        echo '</div>';
    }

    /**
     * ذخیره فیلدهای محصول
     */
    public static function save_product_fields($post_id) {
        $enable_booking = isset($_POST['_vsbbm_enable_seat_booking']) ? 'yes' : 'no';
        update_post_meta($post_id, '_vsbbm_enable_seat_booking', sanitize_text_field($enable_booking));

        $bus_type = sanitize_text_field($_POST['_vsbbm_bus_type'] ?? '');
        update_post_meta($post_id, '_vsbbm_bus_type', $bus_type);

        $bus_rows = sanitize_text_field($_POST['_vsbbm_bus_rows'] ?? '');
        update_post_meta($post_id, '_vsbbm_bus_rows', $bus_rows);

        $bus_columns = sanitize_text_field($_POST['_vsbbm_bus_columns'] ?? '');
        update_post_meta($post_id, '_vsbbm_bus_columns', $bus_columns);
    }
    
    /**
     * اضافه کردن متاباکس چیدمان صندلی
     */
    public static function add_seat_meta_box() {
        add_meta_box(
            'vsbbm_seat_layout',
            __('چیدمان صندلی اتوبوس', 'vs-bus-booking-manager'),
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
        wp_nonce_field('vsbbm_save_seat_numbers', 'vsbbm_seat_numbers_nonce');
        $seat_numbers = self::get_seat_numbers($post->ID);
        $settings = self::get_product_settings($post->ID);

        // داده‌های مورد نیاز برای اسکریپت
        $rows = $settings['rows'];
        $columns = $settings['columns'];
        $type = $settings['type'];
        
        ?>
        <div id="vsbbm-seat-manager">
            <p><?php _e('برای فعال‌سازی، ابتدا در تب عمومی، "رزرو صندلی اتوبوس" را فعال کنید.', 'vs-bus-booking-manager'); ?></p>
            <div class="vsbbm-seat-layout-editor" data-rows="<?php echo esc_attr($rows); ?>" data-cols="<?php echo esc_attr($columns); ?>" data-type="<?php echo esc_attr($type); ?>">
                <div id="vsbbm-seat-grid"></div>
            </div>
            <input type="hidden" name="_vsbbm_seat_numbers" id="vsbbm_seat_numbers_input" value="<?php echo esc_attr(json_encode($seat_numbers)); ?>" />
            <p><strong><?php _e('صندلی‌های فعلی:', 'vs-bus-booking-manager'); ?></strong> <span id="vsbbm-current-seats"><?php echo implode(', ', array_keys($seat_numbers)); ?></span></p>
            <p><small><?php _e('برای تغییر نوع (ردیف/ستون) به تب عمومی بروید و محصول را ذخیره کنید.', 'vs-bus-booking-manager'); ?></small></p>
        </div>
        <?php
    }

    /**
     * ذخیره شماره صندلی‌ها
     */
    public static function save_seat_numbers($post_id) {
        if (!isset($_POST['vsbbm_seat_numbers_nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['vsbbm_seat_numbers_nonce']), 'vsbbm_save_seat_numbers')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (isset($_POST['_vsbbm_seat_numbers'])) {
            $seat_numbers_json = wp_kses_post(wp_unslash($_POST['_vsbbm_seat_numbers']));
            $seat_numbers = json_decode($seat_numbers_json, true);
            
            // تمیزسازی داده‌ها
            $clean_seats = array();
            if (is_array($seat_numbers)) {
                foreach ($seat_numbers as $seat_key => $seat_data) {
                    $clean_seats[sanitize_key($seat_key)] = array(
                        'label' => sanitize_text_field($seat_data['label'] ?? $seat_key),
                        'price' => floatval($seat_data['price'] ?? 0),
                        'type' => sanitize_key($seat_data['type'] ?? 'default'),
                    );
                }
            }
            
            update_post_meta($post_id, '_vsbbm_seat_numbers', $clean_seats);
            
            // پاکسازی کش رزرو
            if (class_exists('VSBBM_Cache_Manager')) {
                VSBBM_Cache_Manager::get_instance()->clear_product_cache($post_id);
            }
        }
    }
    
    /**
     * متاباکس زمان‌بندی سفر
     */
    public static function add_schedule_meta_box() {
        add_meta_box(
            'vsbbm_schedule_meta_box',
            __('زمان‌بندی سفر', 'vs-bus-booking-manager'),
            array(__CLASS__, 'render_schedule_meta_box'),
            'product',
            'side',
            'high'
        );
    }
    
    public static function render_schedule_meta_box($post) {
        $settings = self::get_schedule_settings($post->ID);
        // تمپلیت HTML برای نمایش فیلدهای زمان‌بندی سفر
        ?>
        <div class="vsbbm-schedule-settings">
            <p>
                <label for="vsbbm_enable_schedule">
                    <input type="checkbox" id="vsbbm_enable_schedule" name="vsbbm_enable_schedule" value="yes" <?php checked('yes', $settings['enable_schedule']); ?> />
                    <?php _e('فعال‌سازی انتخاب تاریخ حرکت', 'vs-bus-booking-manager'); ?>
                </label>
            </p>
            <div id="vsbbm_schedule_fields" style="<?php echo ('yes' !== $settings['enable_schedule']) ? 'display: none;' : ''; ?>">
                <p>
                    <label for="vsbbm_min_days_advance"><?php _e('حداقل روز رزرو از پیش:', 'vs-bus-booking-manager'); ?></label>
                    <input type="number" id="vsbbm_min_days_advance" name="vsbbm_min_days_advance" value="<?php echo esc_attr($settings['min_days_advance']); ?>" min="0" step="1" style="width: 100%;" />
                </p>
                <p>
                    <label for="vsbbm_max_days_advance"><?php _e('حداکثر روز رزرو از پیش:', 'vs-bus-booking-manager'); ?></label>
                    <input type="number" id="vsbbm_max_days_advance" name="vsbbm_max_days_advance" value="<?php echo esc_attr($settings['max_days_advance']); ?>" min="0" step="1" style="width: 100%;" />
                </p>
                <p>
                    <label for="vsbbm_departure_time"><?php _e('ساعت حرکت پیش‌فرض:', 'vs-bus-booking-manager'); ?></label>
                    <input type="text" id="vsbbm_departure_time" name="vsbbm_departure_time" value="<?php echo esc_attr($settings['departure_time']); ?>" placeholder="مثال: 14:30" style="width: 100%;" />
                </p>
            </div>
            <script>
                jQuery(document).ready(function($) {
                    $('#vsbbm_enable_schedule').on('change', function() {
                        $('#vsbbm_schedule_fields').toggle(this.checked);
                    });
                });
            </script>
        </div>
        <?php
    }
    
    public static function save_schedule_settings($post_id) {
        if (!isset($_POST['vsbbm_enable_schedule'])) {
            // اگر چک‌باکس ارسال نشده، به معنای غیرفعال بودن است
            update_post_meta($post_id, '_vsbbm_enable_schedule', 'no');
            return;
        }

        $enable_schedule = sanitize_text_field($_POST['vsbbm_enable_schedule']);
        $min_days = absint($_POST['vsbbm_min_days_advance'] ?? 0);
        $max_days = absint($_POST['vsbbm_max_days_advance'] ?? 365);
        $departure_time = sanitize_text_field($_POST['vsbbm_departure_time'] ?? '12:00');

        update_post_meta($post_id, '_vsbbm_enable_schedule', $enable_schedule);
        update_post_meta($post_id, '_vsbbm_min_days_advance', $min_days);
        update_post_meta($post_id, '_vsbbm_max_days_advance', $max_days);
        update_post_meta($post_id, '_vsbbm_departure_time', $departure_time);
    }
    
    public static function get_schedule_settings($product_id) {
        return array(
            'enable_schedule' => get_post_meta($product_id, '_vsbbm_enable_schedule', true) ?: 'no',
            'min_days_advance' => get_post_meta($product_id, '_vsbbm_min_days_advance', true) ?: 0,
            'max_days_advance' => get_post_meta($product_id, '_vsbbm_max_days_advance', true) ?: 365,
            'departure_time' => get_post_meta($product_id, '_vsbbm_departure_time', true) ?: '12:00',
        );
    }

    /**
     * استایل‌های ادمین
     */
    public static function admin_styles() {
        // این استایل‌ها فقط برای متاباکس‌های خودمان است.
        echo '
        <style>
            .vsbbm-seat-layout-editor {
                display: flex;
                flex-direction: column;
                gap: 10px;
                padding: 10px;
                background: #fcfcfc;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .vsbbm-seat-layout-editor .vsbbm-seat-row {
                display: flex;
                gap: 5px;
                justify-content: center;
            }
            .vsbbm-seat {
                display: inline-block;
                width: 30px;
                height: 30px;
                line-height: 30px;
                text-align: center;
                border: 1px solid #ccc;
                border-radius: 4px;
                cursor: pointer;
                background-color: #fff;
                font-size: 10px;
                user-select: none;
                transition: all 0.1s;
            }
            .vsbbm-seat:hover {
                background-color: #f0f0f0;
            }
            .vsbbm-seat.active {
                background-color: #5cb85c;
                color: #fff;
                border-color: #4cae4c;
            }
            .vsbbm-seat.aisle {
                background-color: transparent;
                border: none;
                cursor: default;
            }
            .vsbbm-seat.reserved {
                background-color: #d9534f;
                color: #fff;
                cursor: not-allowed;
            }
            .vsbbm-seat.toilet, .vsbbm-seat.door, .vsbbm-seat.none {
                background-color: #eee;
                border-color: #ddd;
                cursor: default;
                font-size: 8px;
            }
            .vsbbm-seat-editor-menu {
                margin-bottom: 10px;
            }
        </style>';
    }

    /**
     * نمایش انتخابگر صندلی در صفحه محصول (فرانت‌اند)
     */
    public static function display_seat_selection() {
        global $product;

        if (!self::is_seat_booking_enabled($product->get_id())) {
            return;
        }
        
        $settings = self::get_product_settings($product->get_id());
        $schedule_settings = self::get_schedule_settings($product->get_id());
        $seat_numbers = self::get_seat_numbers($product->get_id());
        
        if (empty($seat_numbers)) {
            echo '<p class="vsbbm-warning">' . __('برای این محصول، چیدمان صندلی تعریف نشده است.', 'vs-bus-booking-manager') . '</p>';
            return;
        }

        // --- شروع بخش فرانت‌اند ---
        ?>
        <div id="vsbbm-seat-selector" class="vsbbm-seat-selector-container" 
            data-product-id="<?php echo esc_attr($product->get_id()); ?>"
            data-rows="<?php echo esc_attr($settings['rows']); ?>"
            data-cols="<?php echo esc_attr($settings['columns']); ?>"
            data-type="<?php echo esc_attr($settings['type']); ?>"
            data-price="<?php echo esc_attr($product->get_price()); ?>"
            data-schedule-enabled="<?php echo esc_attr($schedule_settings['enable_schedule']); ?>">

            <?php if ('yes' === $schedule_settings['enable_schedule']): ?>
            <div class="vsbbm-departure-schedule-picker">
                <h3><?php _e('انتخاب تاریخ و ساعت حرکت', 'vs-bus-booking-manager'); ?></h3>
                <input type="text" id="vsbbm_departure_date" placeholder="<?php _e('تاریخ حرکت', 'vs-bus-booking-manager'); ?>" readonly />
                <input type="text" id="vsbbm_departure_time" value="<?php echo esc_attr($schedule_settings['departure_time']); ?>" readonly />
                <p id="vsbbm-date-warning" class="vsbbm-warning" style="display: none;"></p>
                <input type="hidden" id="vsbbm_departure_timestamp" />
            </div>
            <?php endif; ?>

            <div class="vsbbm-seat-layout-display">
                <h3><?php _e('انتخاب صندلی', 'vs-bus-booking-manager'); ?></h3>
                <p class="vsbbm-seat-info-message" style="display: none;"><?php _e('ابتدا تاریخ حرکت را انتخاب کنید.', 'vs-bus-booking-manager'); ?></p>
                <div id="vsbbm-seat-map">
                    <div class="vsbbm-loading-overlay">
                        <div class="vsbbm-spinner"></div>
                        <p><?php _e('در حال بارگذاری چیدمان صندلی...', 'vs-bus-booking-manager'); ?></p>
                    </div>
                </div>
                <div class="vsbbm-legend">
                    <span><i class="vsbbm-seat available"></i> <?php _e('موجود', 'vs-bus-booking-manager'); ?></span>
                    <span><i class="vsbbm-seat selected"></i> <?php _e('انتخاب شما', 'vs-bus-booking-manager'); ?></span>
                    <span><i class="vsbbm-seat reserved"></i> <?php _e('رزرو شده', 'vs-bus-booking-manager'); ?></span>
                </div>
            </div>

            <div class="vsbbm-passenger-data-form" style="display: none;">
                <h3><?php _e('اطلاعات مسافران', 'vs-bus-booking-manager'); ?></h3>
                <div id="vsbbm-passenger-fields">
                    </div>
            </div>

            <div class="vsbbm-summary-bar">
                <span class="vsbbm-summary-item">
                    <?php _e('صندلی‌های انتخابی:', 'vs-bus-booking-manager'); ?> <strong id="vsbbm-selected-seats-count">0</strong>
                </span>
                <span class="vsbbm-summary-item">
                    <?php _e('مجموع قیمت:', 'vs-bus-booking-manager'); ?> <strong id="vsbbm-total-price"><?php echo wc_price(0); ?></strong>
                </span>
                <button type="button" id="vsbbm-add-to-cart-button" class="single_add_to_cart_button button alt" disabled>
                    <?php _e('رزرو و افزودن به سبد خرید', 'vs-bus-booking-manager'); ?>
                </button>
            </div>
        </div>
        <?php
        // --- پایان بخش فرانت‌اند ---
    }
    
    /**
     * بررسی اینکه آیا سیستم رزرو صندلی فعال است یا خیر
     */
    public static function is_seat_booking_enabled($product_id) {
        return get_post_meta($product_id, '_vsbbm_enable_seat_booking', true) === 'yes';
    }

    /**
     * دریافت تنظیمات چیدمان محصول
     */
    public static function get_product_settings($product_id) {
        return array(
            'rows' => (int) get_post_meta($product_id, '_vsbbm_bus_rows', true) ?: 10,
            'columns' => (int) get_post_meta($product_id, '_vsbbm_bus_columns', true) ?: 2,
            'type' => get_post_meta($product_id, '_vsbbm_bus_type', true) ?: '2x2',
        );
    }
    
    /**
     * دریافت شماره صندلی‌های تعریف شده
     */
    public static function get_seat_numbers($product_id) {
        $seats = get_post_meta($product_id, '_vsbbm_seat_numbers', true);
        return is_array($seats) ? $seats : array();
    }
    
    /**
     * دریافت صندلی‌های رزرو شده برای تاریخ حرکت مشخص
     */
    public static function get_reserved_seats($product_id, $departure_timestamp = null) {
        if (!class_exists('VSBBM_Seat_Reservations')) {
            return array(); // اگر کلاس رزرو لود نشده بود
        }
        
        if (empty($departure_timestamp)) {
            // اگر timestamp ارسال نشده، تاریخ امروز را با ساعت پیش‌فرض محصول در نظر می‌گیرد
            $settings = self::get_schedule_settings($product_id);
            list($hour, $minute) = explode(':', $settings['departure_time']);
            $current_date = date('Y-m-d');
            $departure_timestamp = strtotime("$current_date $hour:$minute");
        }
        
        return VSBBM_Seat_Reservations::get_reserved_seats_by_product_and_time($product_id, $departure_timestamp);
    }

    /**
     * AJAX: دریافت لیست صندلی‌های رزرو شده (برای فرانت‌اند)
     */
    public static function get_reserved_seats_ajax() {
        check_ajax_referer('vsbbm_seat_nonce', 'nonce');
        
        $product_id = absint($_POST['product_id'] ?? 0);
        $departure_timestamp = absint($_POST['departure_timestamp'] ?? 0);

        if (!$product_id || !$departure_timestamp) {
            wp_send_json_error(array('message' => __('داده‌های محصول یا تاریخ حرکت نامعتبر است.', 'vs-bus-booking-manager')));
        }
        
        // استفاده از کلاس کش
        if (class_exists('VSBBM_Cache_Manager')) {
            $cache_key = 'reserved_seats_' . $product_id . '_' . $departure_timestamp;
            $cached_data = VSBBM_Cache_Manager::get_instance()->get_cache($cache_key);

            if ($cached_data !== false) {
                // اگر از کش استفاده شد، شمارنده بازدید کش را افزایش بده
                VSBBM_Cache_Manager::get_instance()->increment_cache_hit(); 
                wp_send_json_success($cached_data);
            }
            // شمارنده درخواست‌ها را افزایش بده
            VSBBM_Cache_Manager::get_instance()->increment_total_requests();
        }

        // 1. دریافت صندلی‌های رزرو شده واقعی
        $reserved_seats = self::get_reserved_seats($product_id, $departure_timestamp);
        
        // 2. دریافت صندلی‌های در حال رزرو (سبد خرید)
        if (class_exists('VSBBM_Seat_Reservations')) {
            $temp_reserved_seats = VSBBM_Seat_Reservations::get_temp_reserved_seats_for_product_and_time($product_id, $departure_timestamp);
            // ادغام صندلی‌های رزرو شده با صندلی‌های موقت
            $reserved_seats = array_merge($reserved_seats, $temp_reserved_seats);
        }

        // 3. دریافت تمام صندلی‌های تعریف شده
        $all_seats = self::get_seat_numbers($product_id);
        
        // ترکیب داده‌ها
        $response_data = array(
            'reserved' => array_keys($reserved_seats),
            'all_seats' => $all_seats,
        );
        
        // ذخیره در کش
        if (class_exists('VSBBM_Cache_Manager')) {
            VSBBM_Cache_Manager::get_instance()->set_cache($cache_key, $response_data, 60); // کش برای ۱ دقیقه
        }

        wp_send_json_success($response_data);
    }
    
    /**
     * AJAX: دریافت فیلدهای مسافر (برای فرانت‌اند)
     */
    public static function get_passenger_fields_ajax() {
        // عدم نیاز به nonce برای این فراخوانی ساده
        
        $cache_key = 'passenger_fields';
        if (class_exists('VSBBM_Cache_Manager')) {
            $cached_fields = VSBBM_Cache_Manager::get_instance()->get_cache($cache_key);
            if ($cached_fields !== false) {
                VSBBM_Cache_Manager::get_instance()->increment_cache_hit();
                wp_send_json_success($cached_fields);
            }
            VSBBM_Cache_Manager::get_instance()->increment_total_requests();
        }

        // تعریف فیلدهای مسافران
        $fields = apply_filters('vsbbm_passenger_fields', array(
            array('type' => 'text', 'label' => 'نام و نام خانوادگی', 'required' => true, 'placeholder' => 'نام کامل مسافر'),
            array('type' => 'text', 'label' => 'کد ملی', 'required' => true, 'placeholder' => 'کد ملی ۱۰ رقمی'),
            array('type' => 'tel', 'label' => 'شماره تماس', 'required' => false, 'placeholder' => '09xxxxxxxxx'),
        ));

        // کش برای ۱ ساعت
        if (class_exists('VSBBM_Cache_Manager')) {
            VSBBM_Cache_Manager::get_instance()->set_cache($cache_key, $fields, 3600);
        }

        wp_send_json_success($fields);
    }

    /**
     * AJAX: افزودن به سبد خرید از طریق رزرو صندلی
     */
    public static function add_to_cart_ajax() {
        // 1. بررسی nonce و security
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'vsbbm_seat_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'vs-bus-booking-manager')));
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        $selected_seats = json_decode(wp_kses_post(wp_unslash($_POST['selected_seats'] ?? '[]')), true);
        $passengers_data = json_decode(wp_kses_post(wp_unslash($_POST['passengers_data'] ?? '[]')), true);
        $departure_timestamp = absint($_POST['departure_timestamp'] ?? 0);

        if (!$product_id || empty($selected_seats) || count($selected_seats) !== count($passengers_data)) {
            wp_send_json_error(array('message' => __('لطفاً صندلی‌ها را انتخاب کرده و اطلاعات همه مسافران را تکمیل کنید.', 'vs-bus-booking-manager')));
        }
        
        if (!class_exists('VSBBM_Seat_Reservations') || !class_exists('VSBBM_Booking_Handler')) {
             wp_send_json_error(array('message' => __('خطا: ماژول‌های اصلی پلاگین لود نشده‌اند.', 'vs-bus-booking-manager')));
        }

        // 2. رزرو موقت صندلی‌ها
        $reservation_keys = array();
        foreach ($selected_seats as $seat_number) {
            $reservation_key = VSBBM_Seat_Reservations::reserve_seat($product_id, $seat_number, $departure_timestamp);
            if (is_wp_error($reservation_key)) {
                // اگر رزرو موقت ناموفق بود (مثلاً صندلی قبلاً رزرو شده)
                // رزروهای موقت قبلی را لغو می‌کنیم و خطا می‌دهیم
                if (!empty($reservation_keys)) {
                    VSBBM_Seat_Reservations::cancel_reservations_by_keys($reservation_keys);
                }
                wp_send_json_error(array('message' => sprintf(__('صندلی %s قبلاً رزرو شده است. لطفاً صندلی دیگری انتخاب کنید.', 'vs-bus-booking-manager'), $seat_number)));
            }
            $reservation_keys[] = $reservation_key;
        }
        
        if (empty($reservation_keys)) {
             wp_send_json_error(array('message' => __('خطا در ماژول رزرو صندلی.', 'vs-bus-booking-manager')));
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
            wp_send_json_error(array('message' => __('خطا در افزودن به سبد خرید. لطفاً دوباره تلاش کنید.', 'vs-bus-booking-manager')));
        }
    }

/**
 * ثبت هوک‌های AJAX
 */
public static function register_ajax_handlers() {
    add_action('wp_ajax_vsbbm_get_passenger_fields', array(__CLASS__, 'get_passenger_fields_ajax'));
    add_action('wp_ajax_nopriv_vsbbm_get_passenger_fields', array(__CLASS__, 'get_passenger_fields_ajax'));
    
    add_action('wp_ajax_vsbbm_get_reserved_seats', array(__CLASS__, 'get_reserved_seats_ajax'));
    add_action('wp_ajax_nopriv_vsbbm_get_reserved_seats', array(__CLASS__, 'get_reserved_seats_ajax'));
    
    // افزودن AJAX handler برای add to cart
    add_action('wp_ajax_vsbbm_add_to_cart', array(__CLASS__, 'add_to_cart_ajax'));
    add_action('wp_ajax_nopriv_vsbbm_add_to_cart', array(__CLASS__, 'add_to_cart_ajax'));
}

    /**
     * بررسی صفحه محصول برای دیباگ
     */
    public static function check_product_page($content) {
        if (is_product()) {
            global $product;
            if (self::is_seat_booking_enabled($product->get_id())) {
                // اگر فعال بود، پیامی برای دیباگ نمایش می‌دهد (اختیاری)
                // return $content . '<p style="color: green;">[VSBBM] Seat Booking Enabled.</p>';
            }
        }
        return $content;
    }

} // <-- پایان کلاس VSBBM_Seat_Manager

// فراخوانی متد init برای ثبت هوک‌ها
VSBBM_Seat_Manager::init();