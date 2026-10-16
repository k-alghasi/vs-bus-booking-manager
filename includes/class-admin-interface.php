<?php
/**
 * Class VSBBM_Admin_Interface
 *
 * Handles admin menus, dashboard, and settings pages.
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

        // Order Meta Display
        add_action( 'woocommerce_before_order_itemmeta', array( $this, 'display_order_passenger_info' ), 10, 3 );

        // Settings Registrations
        add_action( 'admin_menu', array( $this, 'add_passenger_fields_settings' ) );
        add_action( 'admin_init', array( $this, 'register_passenger_fields_settings' ) );
        add_action( 'admin_init', array( $this, 'handle_cache_settings_save' ) );
    }

    /**
     * Add admin menu pages.
     *
     * P2-4: Pro menus (Scanner, SMS, API) توسط افزونه Pro اضافه می‌شوند.
     * این فایل فقط منوهای Free را ثبت می‌کند.
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

        // 3. Management (Blacklist)
        add_submenu_page( 'vsbbm-dashboard', __( 'Blacklist', 'vs-bus-booking-manager' ), __( 'Blacklist', 'vs-bus-booking-manager' ), 'manage_options', 'vsbbm-blacklist', array( $this, 'render_blacklist_page' ) );

        // 4. Settings Group (Free)
        add_submenu_page( 'vsbbm-dashboard', __( 'General Settings', 'vs-bus-booking-manager' ), __( 'Settings', 'vs-bus-booking-manager' ), 'manage_options', 'vsbbm-settings', array( $this, 'render_settings_page' ) );
        add_submenu_page( 'vsbbm-dashboard', __( 'Email Settings', 'vs-bus-booking-manager' ), __( 'Email Settings', 'vs-bus-booking-manager' ), 'manage_options', 'vsbbm-email-settings', array( $this, 'render_email_settings_page' ) );
        add_submenu_page( 'vsbbm-dashboard', __( 'License', 'vs-bus-booking-manager' ), __( 'License', 'vs-bus-booking-manager' ), 'manage_options', 'vsbbm-license', array( $this, 'render_license_page' ) );

        // 5. Advanced / Tools
        add_submenu_page( 'vsbbm-dashboard', __( 'Cache & Optimization', 'vs-bus-booking-manager' ), __( 'Cache', 'vs-bus-booking-manager' ), 'manage_options', 'vsbbm-cache', array( $this, 'render_cache_page' ) );

        // P2-4: Pro menus — فقط اگر لایسنس فعال باشد
        // Scanner, SMS Settings, API Settings توسط افزونه Pro اضافه می‌شوند
        if ( VSBBM_Settings::get_instance()->is_pro_active() ) {
            add_submenu_page( 'vsbbm-dashboard', __( 'SMS Settings', 'vs-bus-booking-manager' ), __( 'SMS Settings', 'vs-bus-booking-manager' ), 'manage_options', 'vsbbm-sms-settings', array( $this, 'render_sms_settings_page' ) );
            add_submenu_page( 'vsbbm-dashboard', __( 'API Settings', 'vs-bus-booking-manager' ), __( 'API Settings', 'vs-bus-booking-manager' ), 'manage_options', 'vsbbm-api-settings', array( $this, 'render_api_settings_page' ) );
        }
    }

    public function add_passenger_fields_settings() {
        add_submenu_page( 'vsbbm-dashboard', __( 'Passenger Fields', 'vs-bus-booking-manager' ), __( 'Passenger Fields', 'vs-bus-booking-manager' ), 'manage_options', 'vsbbm-passenger-fields', array( $this, 'render_passenger_fields_settings' ) );
    }

    public function register_passenger_fields_settings() {
        register_setting( 'vsbbm_passenger_fields', 'vsbbm_passenger_fields', array( 'sanitize_callback' => array( $this, 'sanitize_passenger_fields' ) ) );
    }

    /**
     * Enqueue Admin Scripts.
     *
     * P2-4: Scanner scripts توسط افزونه Pro لود می‌شوند.
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
                wp_enqueue_script( 'chart-js', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.js', array(), '4.4.0', true );
                $js_deps[] = 'chart-js';
            }

            // 3. فایل اصلی جاوااسکریپت ادمین
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
        // P0-8: فیلتر EXISTS حالا چک می‌کنه که سفارش حداقل یک آیتم با
        // متای _vsbbm_passengers داشته باشه (یعنی سفارش اتوبوسی هست).
        $total_revenue = $wpdb->get_var( "
            SELECT SUM(pm.meta_value) 
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = 'shop_order' 
            AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
            AND pm.meta_key = '_order_total'
            AND EXISTS (
                SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items oi
                JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oim.order_item_id = oi.order_item_id
                WHERE oi.order_id = p.ID
                AND oim.meta_key = '_vsbbm_passengers'
            )
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
            $labels[] = date_i18n( 'l', strtotime( $date ) );
            
            // P0-8: فیلتر EXISTS برای سفارشات اتوبوسی فقط
            $daily_sales = $wpdb->get_var( $wpdb->prepare( "
                SELECT SUM(pm.meta_value) 
                FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.post_type = 'shop_order' 
                AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
                AND pm.meta_key = '_order_total'
                AND DATE(p.post_date) = %s
                AND EXISTS (
                    SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items oi
                    JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oim.order_item_id = oi.order_item_id
                    WHERE oi.order_id = p.ID
                    AND oim.meta_key = '_vsbbm_passengers'
                )
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
     * Render Dashboard Page.
     */
    public function render_dashboard() {
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

    // P0-7: صفحات All Bookings و Reservations قبلاً خالی بودن چون
    // قالب‌های bookings.php و reservations.php وجود نداشتن.
    // فعلاً یک صفحه placeholder مفید نشون می‌دیم.

    /**
     * Render Bookings page.
     */
    public function render_bookings_page() {
        if ( file_exists( VSBBM_PLUGIN_PATH . 'templates/admin/bookings.php' ) ) {
            include VSBBM_PLUGIN_PATH . 'templates/admin/bookings.php';
            return;
        }

        $orders_link = admin_url( 'edit.php?post_type=shop_order' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'All Bookings', 'vs-bus-booking-manager' ); ?></h1>
            <div class="notice notice-info">
                <p>
                    <strong><?php esc_html_e( 'This page is under construction.', 'vs-bus-booking-manager' ); ?></strong>
                </p>
                <p><?php esc_html_e( 'In the meantime, you can view all bookings (orders) in WooCommerce:', 'vs-bus-booking-manager' ); ?></p>
                <p>
                    <a href="<?php echo esc_url( $orders_link ); ?>" class="button button-primary">
                        <?php esc_html_e( 'View WooCommerce Orders', 'vs-bus-booking-manager' ); ?>
                    </a>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Render Reservations page.
     */
    public function render_reservations_page() {
        if ( file_exists( VSBBM_PLUGIN_PATH . 'templates/admin/reservations.php' ) ) {
            include VSBBM_PLUGIN_PATH . 'templates/admin/reservations.php';
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'vsbbm_seat_reservations';

        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) !== $table_name ) {
            echo '<div class="wrap"><h1>' . esc_html__( 'Seat Reservations', 'vs-bus-booking-manager' ) . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Reservations table does not exist. Please deactivate and reactivate the plugin.', 'vs-bus-booking-manager' ) . '</p></div></div>';
            return;
        }

        $reservations = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY id DESC LIMIT 50" );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Seat Reservations', 'vs-bus-booking-manager' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Showing latest 50 reservations.', 'vs-bus-booking-manager' ); ?></p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'ID', 'vs-bus-booking-manager' ); ?></th>
                        <th><?php esc_html_e( 'Product', 'vs-bus-booking-manager' ); ?></th>
                        <th><?php esc_html_e( 'Seat', 'vs-bus-booking-manager' ); ?></th>
                        <th><?php esc_html_e( 'Departure', 'vs-bus-booking-manager' ); ?></th>
                        <th><?php esc_html_e( 'Order', 'vs-bus-booking-manager' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'vs-bus-booking-manager' ); ?></th>
                        <th><?php esc_html_e( 'Reserved At', 'vs-bus-booking-manager' ); ?></th>
                        <th><?php esc_html_e( 'Expires At', 'vs-bus-booking-manager' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $reservations ) ) : ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 20px;">
                                <?php esc_html_e( 'No reservations found.', 'vs-bus-booking-manager' ); ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $reservations as $res ) :
                            $product = get_the_title( $res->product_id );
                            $departure = $res->departure_timestamp ? wp_date( 'Y/m/d H:i', $res->departure_timestamp ) : '-';
                            $order_link = $res->order_id ? '<a href="' . esc_url( admin_url( 'post.php?post=' . $res->order_id . '&action=edit' ) ) . '">#' . esc_html( $res->order_id ) . '</a>' : '<em>—</em>';
                            $status_class = '';
                            if ( $res->status === 'confirmed' ) $status_class = 'style="color: green; font-weight: bold;"';
                            elseif ( $res->status === 'cancelled' ) $status_class = 'style="color: red;"';
                            elseif ( $res->status === 'expired' ) $status_class = 'style="color: orange;"';
                        ?>
                            <tr>
                                <td><?php echo esc_html( $res->id ); ?></td>
                                <td><?php echo esc_html( $product ? $product : '—' ); ?></td>
                                <td><strong><?php echo esc_html( $res->seat_number ); ?></strong></td>
                                <td><?php echo esc_html( $departure ); ?></td>
                                <td><?php echo $order_link; // phpcs:ignore ?></td>
                                <td <?php echo $status_class; // phpcs:ignore ?>><?php echo esc_html( $res->status ); ?></td>
                                <td><?php echo esc_html( $res->reserved_at ); ?></td>
                                <td><?php echo $res->expires_at ? esc_html( $res->expires_at ) : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_blacklist_page() { if ( class_exists( 'VSBBM_Blacklist' ) ) VSBBM_Blacklist::render_admin_page(); }
    
    // Settings Pages (Placeholders)
    public function render_settings_page() { echo '<div class="wrap"><h1>General Settings</h1></div>'; }
    public function render_email_settings_page() { echo '<div class="wrap"><h1>Email Settings</h1></div>'; }
    public function render_sms_settings_page() { echo '<div class="wrap"><h1>SMS Settings</h1></div>'; }
    public function render_api_settings_page() { echo '<div class="wrap"><h1>API Settings</h1></div>'; }
    public function render_license_page() { echo '<div class="wrap"><h1>License</h1></div>'; }
    public function render_cache_page() { echo '<div class="wrap"><h1>Cache</h1></div>'; }

    // --- Passenger Fields Settings ---
    public function render_passenger_fields_settings() {
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

    // --- Helper Methods (Placeholders) ---
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