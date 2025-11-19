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

    // هوک نمایش انتخاب صندلی - تغییر به هوک قابل اطمینان‌تر
    add_action('woocommerce_single_product_summary', array(__CLASS__, 'display_seat_selection'), 25);

    // فیلتر برای دیباگ
    add_filter('the_content', array(__CLASS__, 'check_product_page'), 1);

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
        
        // تنظیمات چیدمان صندلی‌ها
        woocommerce_wp_select(array(
            'id' => '_vsbbm_seat_layout',
            'label' => 'نوع چیدمان صندلی‌ها',
            'description' => 'انتخاب نوع چیدمان صندلی‌های اتوبوس',
            'options' => array(
                'grid' => 'گرید ساده (پیش‌فرض)',
                '2-2-2' => '۲-۲-۲ (استاندارد)',
                '2-3-2' => '۲-۳-۲ (گسترده)',
                '1-2' => '۱-۲ (تک-دوبل)',
                'vip' => 'VIP (لوکس)',
                'with-stairs' => 'با راه پله عقب',
                'custom' => 'سفارشی (ویرایشگر بصری)'
            ),
            'value' => $product_object->get_meta('_vsbbm_seat_layout') ?: 'grid'
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

        // ذخیره نوع چیدمان صندلی‌ها
        if (isset($_POST['_vsbbm_seat_layout'])) {
            update_post_meta($post_id, '_vsbbm_seat_layout', sanitize_text_field($_POST['_vsbbm_seat_layout']));
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
        $layout = get_post_meta($post->ID, '_vsbbm_seat_layout', true) ?: 'grid';

        echo '<div class="vsbbm-seat-settings">';
        echo '<p><label for="vsbbm_seat_numbers"><strong>شماره صندلی‌های موجود:</strong></label>';
        echo '<input type="text" name="vsbbm_seat_numbers" id="vsbbm_seat_numbers" value="' . esc_attr(implode(',', (array)$seat_numbers)) . '" class="large-text">';
        echo '<br><span class="description">شماره صندلی‌ها را با کاما جدا کنید. مثال: 1,2,3,4,5,...,32</span></p>';

        echo '<h4>پیش‌نمایش چیدمان صندلی‌ها (' . esc_html($layout) . '):</h4>';
        echo '<div class="vsbbm-seat-preview" style="background: #f9f9f9; padding: 15px; border-radius: 5px; margin-top: 10px;">';

        if ($layout === 'custom') {
            echo '<div style="background: #e8f5e8; padding: 20px; border-radius: 8px; border: 2px dashed #27ae60;">';
            echo '<h4 style="color: #27ae60; margin: 0 0 15px 0;">🎨 چیدمان سفارشی</h4>';
            echo '<p style="margin: 0; color: #666; font-size: 14px;">این ویژگی به زودی اضافه خواهد شد!</p>';
            echo '</div>';
        } else {
            echo '<p>پیش‌نمایش چیدمان در اینجا نمایش داده می‌شود.</p>';
        }

        echo '</div></div>';
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

        if (!$product || !self::is_seat_booking_enabled($product->get_id())) {
            return;
        }

        $product_id = $product->get_id();
        $layout_type = get_post_meta($product_id, '_vsbbm_seat_layout', true) ?: 'grid';

        // Check if custom layout exists
        $custom_layout_json = get_post_meta($product_id, '_vsbbm_custom_layout', true);
        $use_custom_layout = ($layout_type === 'custom' && $custom_layout_json);

        if ($use_custom_layout) {
            self::display_custom_layout($product_id, $custom_layout_json);
        } else {
            self::display_standard_layout($product_id, $layout_type);
        }
    }

    /**
     * Display custom layout
     */
    private static function display_custom_layout($product_id, $layout_json) {
        $layout_data = json_decode($layout_json, true);
        if (!$layout_data || !isset($layout_data['layout'])) {
            // Fallback to standard layout
            self::display_standard_layout($product_id, 'grid');
            return;
        }

        $reserved_seats = self::get_reserved_seats($product_id) ?: array();

        echo '<div class="vsbbm-seat-selection" style="background: #fff; padding: 25px; margin: 30px 0; border: 2px solid #e0e0e0; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">';
        echo '<h3 style="color: #2c3e50; margin-bottom: 20px; text-align: center;">🎫 انتخاب صندلی</h3>';

        // Create grid layout
        $rows = $layout_data['grid']['rows'];
        $cols = $layout_data['grid']['cols'];

        echo '<div class="vsbbm-seat-layout" style="display: grid; grid-template-columns: repeat(' . $cols . ', 1fr); gap: 8px; margin: 25px 0; max-width: ' . ($cols * 60) . 'px; margin: 25px auto;">';

        // Create lookup for cells
        $cell_lookup = array();
        foreach ($layout_data['layout'] as $cell) {
            $key = $cell['x'] . '-' . $cell['y'];
            $cell_lookup[$key] = $cell;
        }

        for ($y = 0; $y < $rows; $y++) {
            for ($x = 0; $x < $cols; $x++) {
                $key = $x . '-' . $y;
                $cell = isset($cell_lookup[$key]) ? $cell_lookup[$key] : null;

                if (!$cell || $cell['type'] === 'empty') {
                    echo '<div class="vsbbm-seat-spacer" style="width: 50px; height: 50px;"></div>';
                    continue;
                }

                $is_reserved = ($cell['type'] === 'seat' && isset($cell['number']) && in_array($cell['number'], $reserved_seats));
                $seat_class = $is_reserved ? 'reserved' : 'available';
                $cursor = ($cell['type'] === 'seat' && !$is_reserved) ? 'pointer' : 'default';

                $classes = 'vsbbm-seat vsbbm-seat-' . $seat_class;
                if ($cell['type'] !== 'seat') {
                    $classes .= ' vsbbm-seat-' . $cell['type'];
                }
                if (isset($cell['class'])) {
                    $classes .= ' vsbbm-seat-' . $cell['class'];
                }

                $onclick = ($cell['type'] === 'seat' && !$is_reserved && isset($cell['number'])) ?
                    'onclick="vsbbmSelectSeat(' . $cell['number'] . ', this)"' : '';

                echo '<div class="' . $classes . '" data-seat="' . ($cell['number'] ?? '') . '" style="width: 50px; height: 50px; padding: 5px; text-align: center; border-radius: 6px; cursor: ' . $cursor . '; transition: all 0.3s ease; font-weight: bold; font-size: 14px;" ' . $onclick . '>';

                // Display content based on type
                switch ($cell['type']) {
                    case 'seat':
                        echo $is_reserved ? '⛔' : '💺';
                        if (isset($cell['number'])) {
                            echo '<br><small style="font-size: 10px;">' . $cell['number'] . '</small>';
                        }
                        break;
                    case 'aisle':
                        echo '🚶';
                        break;
                    case 'space':
                        echo '';
                        break;
                    case 'stairs':
                        echo '🪜';
                        break;
                    case 'driver':
                        echo '👤';
                        break;
                }

                echo '</div>';
            }
        }

        echo '</div>';

        echo '<div id="vsbbm-selected-seats" style="background: #e8f5e8; padding: 20px; border-radius: 8px; margin: 20px 0; display: none;">';
        echo '<h4 style="margin: 0 0 15px 0; color: #27ae60;">✅ صندلی‌های انتخاب شده:</h4>';
        echo '<div id="vsbbm-seats-list"></div>';
        echo '<button type="button" onclick="vsbbmAddToCart()" style="background: #3498db; color: white; border: none; padding: 12px 25px; border-radius: 6px; cursor: pointer; font-size: 16px; margin-top: 15px;">🛒 افزودن به سبد خرید</button>';
        echo '</div>';

        echo '<input type="hidden" id="vsbbm_passenger_data" name="vsbbm_passenger_data" value="">';
        echo '</div>';

        self::output_seat_styles();
        self::output_seat_scripts();
    }

    /**
     * Display standard layout (fallback)
     */
    private static function display_standard_layout($product_id, $layout_type) {
        $available_seats = self::get_seat_numbers($product_id);
        $reserved_seats = self::get_reserved_seats($product_id) ?: array();

        echo '<div class="vsbbm-seat-selection" style="background: #fff; padding: 25px; margin: 30px 0; border: 2px solid #e0e0e0; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">';
        echo '<h3 style="color: #2c3e50; margin-bottom: 20px; text-align: center;">🎫 انتخاب صندلی</h3>';

        $layout_css = self::get_layout_css($layout_type);
        echo '<div class="vsbbm-seat-layout" style="display: grid; ' . $layout_css . ' gap: 12px; margin: 25px 0; max-width: 800px; margin: 25px auto;">';

        foreach ($available_seats as $seat) {
            $seat_type = self::get_seat_type($seat, $layout_type);
            $is_reserved = in_array($seat, $reserved_seats);
            $seat_class = $is_reserved ? 'reserved' : 'available';

            if ($seat_type === 'space' || $seat_type === 'aisle' || $seat_type === 'stairs') {
                // Non-selectable elements
                $icon = '';
                switch ($seat_type) {
                    case 'aisle': $icon = '🚶'; break;
                    case 'space': $icon = '⬜'; break;
                    case 'stairs': $icon = '🪜'; break;
                }
                echo '<div class="vsbbm-seat vsbbm-seat-' . $seat_type . '" style="padding: 15px 10px; text-align: center; border-radius: 8px; font-weight: bold; background: #f8f9fa; border: 1px solid #dee2e6;">' . $icon . '</div>';
            } else {
                // Selectable seats
                $type_class = $seat_type ? ' vsbbm-seat-' . $seat_type : '';
                echo '<div class="vsbbm-seat vsbbm-seat-' . $seat_class . $type_class . '" data-seat="' . $seat . '" style="padding: 15px 10px; text-align: center; border-radius: 8px; cursor: ' . ($is_reserved ? 'not-allowed' : 'pointer') . '; transition: all 0.3s ease; font-weight: bold;" onclick="vsbbmSelectSeat(' . $seat . ', this)">';
                echo $is_reserved ? '⛔' : '💺';
                echo '<br><span style="font-size: 12px;">' . $seat . '</span>';
                echo '</div>';
            }
        }
        echo '</div>';

        echo '<div id="vsbbm-selected-seats" style="background: #e8f5e8; padding: 20px; border-radius: 8px; margin: 20px 0; display: none;">';
        echo '<h4 style="margin: 0 0 15px 0; color: #27ae60;">✅ صندلی‌های انتخاب شده:</h4>';
        echo '<div id="vsbbm-seats-list"></div>';
        echo '<button type="button" onclick="vsbbmAddToCart()" style="background: #3498db; color: white; border: none; padding: 12px 25px; border-radius: 6px; cursor: pointer; font-size: 16px; margin-top: 15px;">🛒 افزودن به سبد خرید</button>';
        echo '</div>';

        echo '<input type="hidden" id="vsbbm_passenger_data" name="vsbbm_passenger_data" value="">';
        echo '</div>';

        self::output_seat_styles();
        self::output_seat_scripts();
    }

    /**
     * Output seat selection styles
     */
    private static function output_seat_styles() {
        echo '<style>
        .vsbbm-seat-available { background: #27ae60; color: white; border: 2px solid #219652; }
        .vsbbm-seat-available:hover { background: #219652; transform: scale(1.05); }
        .vsbbm-seat-reserved { background: #e74c3c; color: white; border: 2px solid #c0392b; }
        .vsbbm-seat-selected { background: #f39c12 !important; border: 2px solid #e67e22 !important; transform: scale(1.1); }
        .vsbbm-seat-window { box-shadow: 0 0 0 2px #3498db; }
        .vsbbm-seat-vip { background: linear-gradient(45deg, #f39c12, #e67e22); border: 2px solid #d35400; }
        .vsbbm-seat-aisle { background: #f8f9fa; border: 1px solid #dee2e6; cursor: default; }
        .vsbbm-seat-space { background: transparent; border: none; cursor: default; }
        .vsbbm-seat-stairs { background: #6c757d; color: white; border: 1px solid #5a6268; cursor: default; }
        .vsbbm-seat-driver { background: #17a2b8; color: white; border: 1px solid #138496; cursor: default; }
        .single_add_to_cart_button, button[name="add-to-cart"], .quantity, .qty, input[name="quantity"] { display: none !important; }
        </style>';
    }

    /**
     * Output seat selection scripts
     */
    private static function output_seat_scripts() {
        echo '<script>
        if (typeof window.selectedSeats === "undefined") window.selectedSeats = [];
        function vsbbmSelectSeat(seat, el) {
            if (el.classList.contains("vsbbm-seat-reserved") || el.classList.contains("vsbbm-seat-aisle") || el.classList.contains("vsbbm-seat-space")) return;
            const idx = window.selectedSeats.indexOf(seat);
            if (idx > -1) {
                window.selectedSeats.splice(idx, 1);
                el.classList.remove("vsbbm-seat-selected");
            } else {
                window.selectedSeats.push(seat);
                el.classList.add("vsbbm-seat-selected");
            }
            updateDisplay();
        }
        function updateDisplay() {
            const container = document.getElementById("vsbbm-selected-seats");
            const list = document.getElementById("vsbbm-seats-list");
            list.innerHTML = "";
            if (window.selectedSeats.length === 0) {
                container.style.display = "none";
                return;
            }
            container.style.display = "block";
            window.selectedSeats.forEach(seat => {
                list.innerHTML += "<span style=\"background: #e74c3c; color: white; padding: 5px 10px; border-radius: 15px; margin: 5px; display: inline-block;\">💺 " + seat + "</span>";
            });
        }
        function vsbbmAddToCart() {
            if (window.selectedSeats.length === 0) {
                alert("لطفاً حداقل یک صندلی انتخاب کنید.");
                return;
            }
            alert("این ویژگی به زودی اضافه خواهد شد!");
        }
        </script>';
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
     * دریافت صندلی‌های رزرو شده (با کش)
     */
    public static function get_reserved_seats($product_id) {
        $cache_manager = VSBBM_Cache_Manager::get_instance();
        return $cache_manager->get_reserved_seats($product_id);
    }

    /**
     * تعیین نوع صندلی بر اساس چیدمان
     */
    public static function get_seat_type($seat_number, $layout = 'grid') {
        switch ($layout) {
            case '2-2-2':
                // چیدمان: صندلی1 - صندلی2 - راهرو - صندلی3 - صندلی4
                $position = ($seat_number - 1) % 5;
                if ($position == 2) return 'aisle'; // راهرو
                return $position < 2 ? 'window' : 'aisle-seat'; // پنجره یا کنار راهرو
                break;

            case '2-3-2':
                // چیدمان: صندلی1 - صندلی2 - راهرو - صندلی3 - صندلی4 - صندلی5
                $position = ($seat_number - 1) % 6;
                if ($position == 2) return 'aisle'; // راهرو
                if ($position < 2) return 'window'; // پنجره
                if ($position > 2) return 'middle'; // وسط
                return 'aisle-seat'; // کنار راهرو
                break;

            case '1-2':
                // چیدمان: صندلی1 - فضای خالی - صندلی2 - صندلی3
                $position = ($seat_number - 1) % 4;
                if ($position == 1) return 'space'; // فضای خالی
                if ($position == 0) return 'window'; // تک صندلی سمت چپ
                return 'aisle-seat'; // دو صندلی سمت راست
                break;

            case 'vip':
                // چیدمان VIP: صندلی1 - فضای خالی - صندلی2
                $position = ($seat_number - 1) % 3;
                if ($position == 1) return 'space'; // فضای خالی
                return 'vip'; // صندلی VIP
                break;

            case 'with-stairs':
                // چیدمان با راه پله: صندلی‌ها + فضای خالی در انتها
                $total_seats = count(get_post_meta(get_the_ID(), '_vsbbm_seat_numbers', true) ?: range(1, 32));
                if ($seat_number > $total_seats - 2) return 'stairs'; // دو صندلی آخر = راه پله
                // در غیر این صورت از چیدمان 2-2-2 استفاده کن
                $position = ($seat_number - 1) % 5;
                if ($position == 2) return 'aisle';
                return $position < 2 ? 'window' : 'aisle-seat';
                break;

            default:
                return 'standard'; // گرید ساده
        }
    }

    /**
     * دریافت CSS Grid برای نوع چیدمان
     */
    public static function get_layout_css($layout) {
        switch ($layout) {
            case '2-2-2':
                return 'grid-template-columns: 1fr 1fr 40px 1fr 1fr;';
            case '2-3-2':
                return 'grid-template-columns: 1fr 1fr 40px 1fr 1fr 1fr;';
            case '1-2':
                return 'grid-template-columns: 1fr 60px 1fr 1fr;';
            case 'vip':
                return 'grid-template-columns: 1fr 80px 1fr; gap: 30px;';
            case 'with-stairs':
                return 'grid-template-columns: 1fr 1fr 40px 1fr 1fr;'; // مشابه 2-2-2
            default:
                return 'grid-template-columns: repeat(auto-fill, minmax(70px, 1fr));';
        }
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
    */


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
    error_log(' VSBBM: Registering AJAX handlers...');
    
    add_action('wp_ajax_vsbbm_get_passenger_fields', array(__CLASS__, 'get_passenger_fields_ajax'));
    add_action('wp_ajax_nopriv_vsbbm_get_passenger_fields', array(__CLASS__, 'get_passenger_fields_ajax'));
    
    // افزودن AJAX handler برای add to cart
    add_action('wp_ajax_vsbbm_add_to_cart', array(__CLASS__, 'add_to_cart_ajax'));
    add_action('wp_ajax_nopriv_vsbbm_add_to_cart', array(__CLASS__, 'add_to_cart_ajax'));
    
    error_log(' VSBBM: AJAX handlers registered successfully');
}

    /**
     * بررسی صفحه محصول برای دیباگ
     */
    public static function check_product_page($content) {
        if (is_product()) {
            global $product;
            $product_id = $product ? $product->get_id() : 'null';
            error_log('VSBBM DEBUG: On product page, Product ID: ' . $product_id);
            error_log('VSBBM DEBUG: Seat booking enabled: ' . (self::is_seat_booking_enabled($product_id) ? 'YES' : 'NO'));
        }
        return $content;
    }
}