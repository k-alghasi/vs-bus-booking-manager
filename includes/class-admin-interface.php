<?php
/**
 * Class VSBBM_Admin_Interface
 *
 * Handles admin menus, dashboard, scanner, and settings pages.
 * Updated: Added Ticket Scanner & Cleanup.
 *
 * @package VSBBM
 * @since   2.1.0
 */

defined( 'ABSPATH' ) || exit;

class VSBBM_Admin_Interface {

    private static $instance = null;

    public static function init() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Menus & Scripts
        add_action( 'admin_menu', array( $this, 'add_admin_menus' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

        // AJAX Handlers
        add_action( 'wp_ajax_vsbbm_get_booking_details', array( $this, 'get_booking_details_ajax' ) );
        add_action( 'wp_ajax_vsbbm_update_booking_status', array( $this, 'update_booking_status_ajax' ) );
        add_action( 'wp_ajax_vsbbm_export_bookings', array( $this, 'export_bookings_ajax' ) );
        add_action( 'wp_ajax_vsbbm_use_ticket', array( $this, 'use_ticket_ajax' ) );
        add_action( 'wp_ajax_vsbbm_clear_cache', array( $this, 'clear_cache_ajax' ) );

        // Scanner AJAX (New)
        // Note: These should ideally be in Ticket Manager, but we register hooks here if logic is simple
        // or ensure Ticket Manager is loaded. Let's assume Ticket Manager handles the logic.

        // Order Meta Display
        add_action( 'woocommerce_before_order_itemmeta', array( $this, 'display_order_passenger_info' ), 10, 3 );

        // Settings Registrations
        add_action( 'admin_menu', array( $this, 'add_passenger_fields_settings' ) );
        add_action( 'admin_init', array( $this, 'register_passenger_fields_settings' ) );
        add_action( 'admin_init', array( $this, 'handle_cache_settings_save' ) );
    }

    /**
     * Add admin menu pages.
     */
    public function add_admin_menus() {
        // Main Menu
        add_menu_page(
            __( 'Bus Booking', 'vs-bus-booking-manager' ),
            __( 'Bus Booking', 'vs-bus-booking-manager' ),
            'manage_options',
            'vsbbm-dashboard',
            array( $this, 'render_dashboard' ),
            'dashicons-bus',
            30
        );

        // 1. Dashboard
        add_submenu_page( 'vsbbm-dashboard', __( 'Dashboard', 'vs-bus-booking-manager' ), __( 'Dashboard', 'vs-bus-booking-manager' ), 'manage_options', 'vsbbm-dashboard', array( $this, 'render_dashboard' ) );
        
        // 2. Operations (Bookings & Reservations)
        add_submenu_page( 'vsbbm-dashboard', __( 'All Bookings', 'vs-bus-booking-manager' ), __( 'All Bookings', 'vs-bus-booking-manager' ), 'manage_options', 'vsbbm-bookings', array( $this, 'render_bookings_page' ) );
        add_submenu_page( 'vsbbm-dashboard', __( 'Seat Reservations', 'vs-bus-booking-manager' ), __( 'Reservations', 'vs-bus-booking-manager' ), 'manage_options', 'vsbbm-reservations', array( $this, 'render_reservations_page' ) );
        
        // 3. Ticket Scanner (New Feature)
        add_submenu_page( 'vsbbm-dashboard', __( 'Ticket Scanner', 'vs-bus-booking-manager' ), __( 'Scanner', 'vs-bus-booking-manager' ), 'manage_options', 'vsbbm-scanner', array( $this, 'render_scanner_page' ) );

        // 4. Management (Blacklist)
        add_submenu_page( 'vsbbm-dashboard', __( 'Blacklist', 'vs-bus-booking-manager' ), __( 'Blacklist', 'vs-bus-booking-manager' ), 'manage_options', 'vsbbm-blacklist', array( $this, 'render_blacklist_page' ) );

        // 5. Settings Group (We will consolidate these into tabs later)
        add_submenu_page( 'vsbbm-dashboard', __( 'General Settings', 'vs-bus-booking-manager' ), __( 'Settings', 'vs-bus-booking-manager' ), 'manage_options', 'vsbbm-settings', array( $this, 'render_settings_page' ) );
        add_submenu_page( 'vsbbm-dashboard', __( 'Email Settings', 'vs-bus-booking-manager' ), __( 'Email Settings', 'vs-bus-booking-manager' ), 'manage_options', 'vsbbm-email-settings', array( $this, 'render_email_settings_page' ) );
        add_submenu_page( 'vsbbm-dashboard', __( 'SMS Settings', 'vs-bus-booking-manager' ), __( 'SMS Settings', 'vs-bus-booking-manager' ), 'manage_options', 'vsbbm-sms-settings', array( $this, 'render_sms_settings_page' ) );
        add_submenu_page( 'vsbbm-dashboard', __( 'API Settings', 'vs-bus-booking-manager' ), __( 'API Settings', 'vs-bus-booking-manager' ), 'manage_options', 'vsbbm-api-settings', array( $this, 'render_api_settings_page' ) );
        add_submenu_page( 'vsbbm-dashboard', __( 'License', 'vs-bus-booking-manager' ), __( 'License', 'vs-bus-booking-manager' ), 'manage_options', 'vsbbm-license', array( $this, 'render_license_page' ) );
        
        // Advanced / Tools
        add_submenu_page( 'vsbbm-dashboard', __( 'Cache & Optimization', 'vs-bus-booking-manager' ), __( 'Cache', 'vs-bus-booking-manager' ), 'manage_options', 'vsbbm-cache', array( $this, 'render_cache_page' ) );
        
        // Removed "Ticket Manager" page (it was empty placeholder) and "Reports" (merged into dashboard usually)
        // Removed "Performance Test" (Dev only)
    }

    public function add_passenger_fields_settings() {
        add_submenu_page( 'vsbbm-dashboard', __( 'Passenger Fields', 'vs-bus-booking-manager' ), __( 'Passenger Fields', 'vs-bus-booking-manager' ), 'manage_options', 'vsbbm-passenger-fields', array( $this, 'render_passenger_fields_settings' ) );
    }

    public function register_passenger_fields_settings() {
        register_setting( 'vsbbm_passenger_fields', 'vsbbm_passenger_fields', array( 'sanitize_callback' => array( $this, 'sanitize_passenger_fields' ) ) );
    }

    /**
     * Enqueue Admin Scripts (Includes Scanner and Dashboard Data).
     */
    public function enqueue_admin_scripts( $hook ) {
        // فقط در صفحات پلاگین لود شود
        if ( strpos( $hook, 'vsbbm-' ) !== false ) {
            
            // 1. استایل‌ها
            wp_enqueue_style( 'vsbbm-admin', VSBBM_PLUGIN_URL . 'assets/css/admin.css', array(), VSBBM_VERSION );

            // 2. وابستگی‌های جاوااسکریپت (Dependencies)
            $js_deps = array( 'jquery' );

            // اگر در صفحه داشبورد هستیم، Chart.js را اضافه کن
            if ( strpos( $hook, 'vsbbm-dashboard' ) !== false ) {
                // استفاده از نسخه پایدار 4.4.0
                wp_enqueue_script( 'chart-js', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.js', array(), '4.4.0', true );
                $js_deps[] = 'chart-js'; // اضافه کردن به پیش‌نیازهای فایل اصلی
            }

            // اگر در صفحه اسکنر هستیم، کتابخانه QR را اضافه کن
            if ( strpos( $hook, 'vsbbm-scanner' ) !== false ) {
                wp_enqueue_script( 'html5-qrcode', 'https://unpkg.com/html5-qrcode', array(), '2.3.8', true );
                $js_deps[] = 'html5-qrcode';
                
                // اسکریپت جداگانه اسکنر (اختیاری: یا می‌توان همه را در admin.js ادغام کرد)
                wp_enqueue_script( 'vsbbm-scanner', VSBBM_PLUGIN_URL . 'assets/js/scanner.js', array( 'jquery', 'html5-qrcode' ), VSBBM_VERSION, true );
                wp_localize_script( 'vsbbm-scanner', 'vsbbm_scanner_vars', array(
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce'    => wp_create_nonce( 'vsbbm_scanner_nonce' )
                ));
            }

            // 3. فایل اصلی جاوااسکریپت ادمین (با وابستگی‌های تعریف شده)
            wp_enqueue_script( 'vsbbm-admin', VSBBM_PLUGIN_URL . 'assets/js/admin.js', $js_deps, VSBBM_VERSION, true );

            // 4. ارسال داده‌ها به JS
            $chart_data = array();
            if ( strpos( $hook, 'vsbbm-dashboard' ) !== false ) {
                $chart_data = $this->get_dashboard_data();
            }

            wp_localize_script( 'vsbbm-admin', 'vsbbm_admin', array(
                'ajax_url'   => admin_url( 'admin-ajax.php' ),
                'nonce'      => wp_create_nonce( 'vsbbm_admin_nonce' ),
                'i18n'       => array( 'confirm_delete' => __( 'Are you sure?', 'vs-bus-booking-manager' ) ),
                'chart_data' => $chart_data
            ));
        }
    }

    /**
     * دریافت آمار سریع (کارت‌های بالای صفحه)
     */
    private function get_quick_stats() {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'vsbbm_tickets';
        
        // 1. تعداد کل بلیط‌ها
        $total_tickets = $wpdb->get_var( "SELECT COUNT(*) FROM $tickets_table" );

        // 2. درآمد کل (از سفارشات ووکامرس مربوط به بلیط‌ها)
        // یک کوئری تقریبی اما سریع
        $total_revenue = $wpdb->get_var( "
            SELECT SUM(pm.meta_value) 
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = 'shop_order' 
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND pm.meta_key = '_order_total'
            AND EXISTS (SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items oi WHERE oi.order_id = p.ID)
        " );

        // 3. تعداد سفرهای فعال (محصولات اتوبوسی)
        $active_trips = count( get_posts( array(
            'post_type' => 'product',
            'meta_key' => '_vsbbm_enable_seat_booking',
            'meta_value' => 'yes',
            'posts_per_page' => -1
        )));

        return array(
            'tickets' => $total_tickets ? $total_tickets : 0,
            'revenue' => $total_revenue ? $total_revenue : 0,
            'trips'   => $active_trips
        );
    }

    /**
     * دریافت داده‌های نمودار برای JS
     */
    private function get_dashboard_data() {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'vsbbm_tickets';

        // 1. نمودار دایره‌ای وضعیت بلیط‌ها
        $status_counts = $wpdb->get_results( "SELECT status, COUNT(*) as count FROM $tickets_table GROUP BY status" );
        $status_data = array( 'active' => 0, 'used' => 0, 'expired' => 0, 'cancelled' => 0 );
        foreach ( $status_counts as $row ) {
            $status_data[ $row->status ] = $row->count;
        }

        // 2. نمودار خطی فروش ۷ روز گذشته
        $sales_data = array();
        $labels = array();
        
        for ( $i = 6; $i >= 0; $i-- ) {
            $date = date( 'Y-m-d', strtotime( "-$i days" ) );
            $labels[] = date_i18n( 'l', strtotime( $date ) ); // نام روز هفته (شنبه، ...)
            
            // جمع مبلغ سفارشات تکمیل شده در آن روز
            $daily_sales = $wpdb->get_var( $wpdb->prepare( "
                SELECT SUM(pm.meta_value) 
                FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.post_type = 'shop_order' 
                AND p.post_status IN ('wc-completed', 'wc-processing')
                AND pm.meta_key = '_order_total'
                AND DATE(p.post_date) = %s
            ", $date ) );
            
            $sales_data[] = $daily_sales ? $daily_sales : 0;
        }

        return array(
            'status' => array(
                'labels' => array( __( 'Active', 'vs-bus-booking-manager' ), __( 'Used', 'vs-bus-booking-manager' ), __( 'Expired', 'vs-bus-booking-manager' ) ),
                'data'   => array( $status_data['active'], $status_data['used'], $status_data['expired'] )
            ),
            'sales' => array(
                'labels' => $labels,
                'data'   => $sales_data
            )
        );
    }

    /**
     * Render Scanner Page.
     */
    public function render_scanner_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Ticket Scanner', 'vs-bus-booking-manager' ); ?></h1>
            
            <div class="vsbbm-scanner-wrapper">
                <!-- Camera Box -->
                <div class="vsbbm-scanner-box">
                    <h3><?php esc_html_e( 'Scan QR Code', 'vs-bus-booking-manager' ); ?></h3>
                    <div id="vsbbm-qr-reader" style="width: 100%;"></div>
                    <p class="description"><?php esc_html_e( 'Use your device camera to scan ticket QR.', 'vs-bus-booking-manager' ); ?></p>
                </div>

                <!-- Manual Entry -->
                <div class="vsbbm-manual-entry">
                    <h3><?php esc_html_e( 'Manual Check', 'vs-bus-booking-manager' ); ?></h3>
                    <div style="display:flex; gap:10px; justify-content:center;">
                        <input type="text" id="vsbbm-ticket-code" placeholder="TK-..." class="regular-text">
                        <button class="button button-primary" id="vsbbm-check-btn"><?php esc_html_e( 'Check Ticket', 'vs-bus-booking-manager' ); ?></button>
                    </div>
                </div>

                <!-- Results -->
                <div id="vsbbm-scan-result" style="display:none;">
                    <div class="scan-status-icon"></div>
                    <h2 class="scan-title"></h2>
                    <div class="scan-details"></div>
                    <button class="button button-large button-primary" id="vsbbm-confirm-usage" style="display:none; margin-top:20px; width:100%;">
                        <?php esc_html_e( 'Confirm & Check-in', 'vs-bus-booking-manager' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    // --- Other Render Methods (Placeholders or includes) ---
    
    // Dashboard Page

    //(ساختار HTML برای نمایش کارت‌ها و نمودارها)
    public function render_dashboard() {
        // محاسبه آمارهای خلاصه
        $stats = $this->get_quick_stats();
        ?>
        <div class="wrap vsbbm-admin-dashboard">
            <h1><?php esc_html_e( 'Bus Booking Dashboard', 'vs-bus-booking-manager' ); ?></h1>

            <!-- آمار سریع -->
            <div class="vsbbm-stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">💰</div>
                    <div class="stat-content">
                        <h3><?php esc_html_e( 'Total Revenue', 'vs-bus-booking-manager' ); ?></h3>
                        <div class="stat-number"><?php echo wc_price( $stats['revenue'] ); ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🎫</div>
                    <div class="stat-content">
                        <h3><?php esc_html_e( 'Tickets Sold', 'vs-bus-booking-manager' ); ?></h3>
                        <div class="stat-number"><?php echo number_format( $stats['tickets'] ); ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🚌</div>
                    <div class="stat-content">
                        <h3><?php esc_html_e( 'Active Trips', 'vs-bus-booking-manager' ); ?></h3>
                        <div class="stat-number"><?php echo number_format( $stats['trips'] ); ?></div>
                    </div>
                </div>
            </div>

            <!-- نمودارها -->
            <div class="vsbbm-charts">
                <div class="chart-container">
                    <h3><?php esc_html_e( 'Sales (Last 7 Days)', 'vs-bus-booking-manager' ); ?></h3>
                    <canvas id="vsbbm-revenue-chart"></canvas>
                </div>
                <div class="chart-container">
                    <h3><?php esc_html_e( 'Ticket Status', 'vs-bus-booking-manager' ); ?></h3>
                    <canvas id="vsbbm-status-chart" style="max-height: 300px;"></canvas>
                </div>
            </div>
        </div>
        <?php
    }
    public function render_bookings_page() { if ( file_exists( VSBBM_PLUGIN_PATH . 'templates/admin/bookings.php' ) ) include VSBBM_PLUGIN_PATH . 'templates/admin/bookings.php'; }
    public function render_reservations_page() { if ( file_exists( VSBBM_PLUGIN_PATH . 'templates/admin/reservations.php' ) ) include VSBBM_PLUGIN_PATH . 'templates/admin/reservations.php'; }
    public function render_blacklist_page() { if ( class_exists( 'VSBBM_Blacklist' ) ) VSBBM_Blacklist::render_admin_page(); }
    
    // Settings Pages (Simple Placeholders for now)
    public function render_settings_page() { echo '<div class="wrap"><h1>General Settings</h1></div>'; }
    public function render_email_settings_page() { echo '<div class="wrap"><h1>Email Settings</h1></div>'; }
    public function render_sms_settings_page() { echo '<div class="wrap"><h1>SMS Settings</h1></div>'; }
    public function render_api_settings_page() { echo '<div class="wrap"><h1>API Settings</h1></div>'; }
    public function render_license_page() { echo '<div class="wrap"><h1>License</h1></div>'; }
    public function render_cache_page() { echo '<div class="wrap"><h1>Cache</h1></div>'; }
    public function render_tickets_page() { echo '<div class="wrap"><h1>Tickets (See Scanner)</h1></div>'; }

    // --- Passenger Fields Settings (Same as before) ---
    public function render_passenger_fields_settings() {
        // (Use the code from previous step - abbreviated here to save space)
        $fields = get_option('vsbbm_passenger_fields', array());
        $blacklist_target = get_option('vsbbm_blacklist_target_field', 'کد ملی');
        if (empty($fields)) {
            $fields = array(
                array('type' => 'text', 'label' => 'نام کامل', 'required' => true),
                array('type' => 'text', 'label' => 'کد ملی', 'required' => true),
                array('type' => 'tel', 'label' => 'شماره تماس', 'required' => false),
            );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Passenger Fields', 'vs-bus-booking-manager' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('vsbbm_passenger_fields'); ?>
                <div class="card" style="max-width: 900px; padding: 20px;">
                    <div id="vsbbm-fields-container">
                        <?php foreach ($fields as $index => $field): 
                            $label = isset($field['label']) ? $field['label'] : '';
                            $is_blacklist = ($label === $blacklist_target);
                        ?>
                        <div class="field-group" style="background: #f9f9f9; padding: 15px; margin: 10px 0; border-left: 4px solid <?php echo $is_blacklist ? '#d63638' : '#0073aa'; ?>;">
                            <div style="display: grid; grid-template-columns: 2fr 2fr 1fr 1fr 1fr 0.5fr; gap: 10px; align-items: end;">
                                <input type="text" name="vsbbm_passenger_fields[<?php echo $index; ?>][label]" value="<?php echo esc_attr($label); ?>" class="field-label-input" required placeholder="Label">
                                <input type="text" name="vsbbm_passenger_fields[<?php echo $index; ?>][placeholder]" value="<?php echo esc_attr($field['placeholder']??''); ?>" placeholder="Placeholder">
                                <select name="vsbbm_passenger_fields[<?php echo $index; ?>][type]">
                                    <option value="text" <?php selected($field['type'], 'text'); ?>>Text</option>
                                    <option value="tel" <?php selected($field['type'], 'tel'); ?>>Phone</option>
                                    <option value="email" <?php selected($field['type'], 'email'); ?>>Email</option>
                                    <option value="number" <?php selected($field['type'], 'number'); ?>>Number</option>
                                    <option value="select" <?php selected($field['type'], 'select'); ?>>Select</option>
                                </select>
                                <label><input type="checkbox" name="vsbbm_passenger_fields[<?php echo $index; ?>][required]" value="1" <?php checked(!empty($field['required'])); ?>> Required</label>
                                <div style="text-align: center;">
                                    <input type="radio" name="vsbbm_blacklist_target_temp" value="<?php echo $index; ?>" <?php checked($is_blacklist); ?> title="Blacklist Field">
                                </div>
                                <button type="button" class="button button-secondary remove-field">X</button>
                            </div>
                            <div class="select-options" style="margin-top: 10px; <?php echo ($field['type'] !== 'select') ? 'display: none;' : ''; ?>">
                                <input type="text" name="vsbbm_passenger_fields[<?php echo $index; ?>][options]" value="<?php echo esc_attr($field['options']??''); ?>" style="width: 100%;" placeholder="Options (comma separated)">
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="vsbbm_blacklist_target_field" id="vsbbm_blacklist_target_field" value="<?php echo esc_attr($blacklist_target); ?>">
                    <button type="button" id="add-field" class="button button-primary" style="margin-top: 15px;">+ Add Field</button>
                    <?php submit_button(); ?>
                </div>
            </form>
            <script>
            jQuery(document).ready(function($) {
                $(document).on('change', 'input[name="vsbbm_blacklist_target_temp"]', function() {
                    $('#vsbbm_blacklist_target_field').val($(this).closest('.field-group').find('.field-label-input').val());
                });
                $(document).on('input', '.field-label-input', function() {
                    if ($(this).closest('.field-group').find('input[name="vsbbm_blacklist_target_temp"]').is(':checked')) {
                        $('#vsbbm_blacklist_target_field').val($(this).val());
                    }
                });
                $(document).on('change', 'select', function() {
                   $(this).closest('.field-group').find('.select-options').toggle($(this).val() === 'select');
                });
                $('.remove-field').click(function(){ if($('.field-group').length > 1) $(this).closest('.field-group').remove(); });
                $('#add-field').click(function(){ alert('Please save to add new row.'); });
            });
            </script>
        </div>
        <?php
    }

    public function sanitize_passenger_fields($input) {
        if (isset($_POST['vsbbm_blacklist_target_field'])) update_option('vsbbm_blacklist_target_field', sanitize_text_field($_POST['vsbbm_blacklist_target_field']));
        $sanitized = array();
        if (is_array($input)) {
            foreach ($input as $field) {
                $sanitized[] = array(
                    'label' => sanitize_text_field($field['label']),
                    'type' => sanitize_text_field($field['type']),
                    'placeholder' => sanitize_text_field($field['placeholder'] ?? ''),
                    'required' => isset($field['required']),
                    'options' => sanitize_text_field($field['options'] ?? '')
                );
            }
        }
        delete_transient('vsbbm_passenger_fields');
        return $sanitized;
    }

    // --- Helper Methods ---
    public function get_booking_details_ajax() {}
    public function update_booking_status_ajax() {}
    public function export_bookings_ajax() {}
    public function display_order_passenger_info( $item_id, $item, $product ) {}
    public function handle_cache_settings_save() {}
    public function use_ticket_ajax() {}
    public function clear_cache_ajax() {}
}

// Initialize
VSBBM_Admin_Interface::init();