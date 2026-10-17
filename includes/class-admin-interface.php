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
            'رزرو بلیط اتوبوس',
            'رزرو بلیط اتوبوس',
            'manage_options',
            'vsbbm-dashboard',
            array( $this, 'render_dashboard' ),
            'dashicons-bus',
            30
        );

        // 1. داشبورد
        add_submenu_page( 'vsbbm-dashboard', 'داشبورد', 'داشبورد', 'manage_options', 'vsbbm-dashboard', array( $this, 'render_dashboard' ) );

        // 2. عملیات (رزروها)
        add_submenu_page( 'vsbbm-dashboard', 'همه رزروها', 'همه رزروها', 'manage_options', 'vsbbm-bookings', array( $this, 'render_bookings_page' ) );
        add_submenu_page( 'vsbbm-dashboard', 'صندلی‌های رزرو شده', 'رزروهای صندلی', 'manage_options', 'vsbbm-reservations', array( $this, 'render_reservations_page' ) );

        // 3. مدیریت (لیست سیاه)
        add_submenu_page( 'vsbbm-dashboard', 'لیست سیاه', 'لیست سیاه', 'manage_options', 'vsbbm-blacklist', array( $this, 'render_blacklist_page' ) );

        // 4. تنظیمات
        add_submenu_page( 'vsbbm-dashboard', 'تنظیمات عمومی', 'تنظیمات', 'manage_options', 'vsbbm-settings', array( $this, 'render_settings_page' ) );
        add_submenu_page( 'vsbbm-dashboard', 'تنظیمات ایمیل', 'تنظیمات ایمیل', 'manage_options', 'vsbbm-email-settings', array( $this, 'render_email_settings_page' ) );
        add_submenu_page( 'vsbbm-dashboard', 'لایسنس', 'لایسنس', 'manage_options', 'vsbbm-license', array( $this, 'render_license_page' ) );

        // 5. ابزارها
        add_submenu_page( 'vsbbm-dashboard', 'کش و بهینه‌سازی', 'کش', 'manage_options', 'vsbbm-cache', array( $this, 'render_cache_page' ) );

        // P2-4: Pro menus — فقط اگر لایسنس فعال باشد
        if ( VSBBM_Settings::get_instance()->is_pro_active() ) {
            add_submenu_page( 'vsbbm-dashboard', 'تنظیمات پیامک', 'تنظیمات پیامک', 'manage_options', 'vsbbm-sms-settings', array( $this, 'render_sms_settings_page' ) );
            add_submenu_page( 'vsbbm-dashboard', 'تنظیمات API', 'تنظیمات API', 'manage_options', 'vsbbm-api-settings', array( $this, 'render_api_settings_page' ) );
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
     *
     * P-fix: استفاده از wc_get_orders به‌جای کوئری مستقیم SQL
     * برای سازگاری با HPOS (WooCommerce 8+)
     */
    private function get_quick_stats() {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'vsbbm_tickets';
        
        // 1. تعداد کل بلیط‌ها
        $total_tickets = $wpdb->get_var( "SELECT COUNT(*) FROM $tickets_table" );

        // 2. درآمد کل — استفاده از wc_get_orders برای سازگاری با HPOS
        $orders = wc_get_orders( array(
            'status' => array( 'completed', 'processing', 'on-hold' ),
            'limit'  => -1,
            'return' => 'objects',
        ) );

        $total_revenue = 0;
        foreach ( $orders as $order ) {
            // بررسی اینکه سفارش اتوبوسی هست (دارای _vsbbm_passengers)
            foreach ( $order->get_items() as $item ) {
                if ( $item->get_meta( '_vsbbm_passengers' ) ) {
                    $total_revenue += (float) $order->get_total();
                    break; // یک آیتم کافیه
                }
            }
        }

        // 3. تعداد سفرهای فعال (محصولات اتوبوسی)
        $active_trips = count( get_posts( array(
            'post_type' => 'product',
            'meta_key' => '_vsbbm_enable_seat_booking',
            'meta_value' => 'yes',
            'posts_per_page' => -1
        )));

        return array(
            'tickets' => $total_tickets ? $total_tickets : 0,
            'revenue' => $total_revenue,
            'trips'   => $active_trips
        );
    }

    /**
     * دریافت داده‌های نمودار برای JS
     *
     * P-fix: استفاده از wc_get_orders به‌جای کوئری مستقیم SQL
     * برای سازگاری با HPOS (WooCommerce 8+)
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

        // 2. نمودار خطی فروش ۷ روز گذشته — استفاده از wc_get_orders
        $sales_data = array();
        $labels = array();
        
        for ( $i = 6; $i >= 0; $i-- ) {
            $date = date( 'Y-m-d', strtotime( "-$i days" ) );
            $labels[] = date_i18n( 'l', strtotime( $date ) );
            
            // گرفتن سفارشات آن روز
            $day_orders = wc_get_orders( array(
                'status' => array( 'completed', 'processing', 'on-hold' ),
                'date_created' => $date . '...' . $date . ' 23:59:59',
                'limit' => -1,
                'return' => 'objects',
            ) );

            $daily_total = 0;
            foreach ( $day_orders as $order ) {
                foreach ( $order->get_items() as $item ) {
                    if ( $item->get_meta( '_vsbbm_passengers' ) ) {
                        $daily_total += (float) $order->get_total();
                        break;
                    }
                }
            }
            
            $sales_data[] = $daily_total;
        }

        return array(
            'status' => array(
                'labels' => array( 'فعال', 'استفاده شده', 'منقضی' ),
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
                    <div class="stat-icon">💰</div>
                    <div class="stat-content">
                        <h3>درآمد کل</h3>
                        <div class="stat-number"><?php echo wc_price( $stats['revenue'] ); ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-ickon">🎫</div>
                    <div class="stat-content">
                        <h3>بلیط‌های فروخته شده</h3>
                        <div class="stat-number"><?php echo number_format( $stats['tickets'] ); ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🚌</div>
                    <div class="stat-content">
                        <h3>سفرهای فعال</h3>
                        <div class="stat-number"><?php echo number_format( $stats['trips'] ); ?></div>
                    </div>
                </div>
            </div>

            <!-- نمودارها -->
            <div class="vsbbm-charts">
                <div class="chart-container">
                    <h3>فروش (۷ روز گذشته)</h3>
                    <canvas id="vsbbm-revenue-chart"></canvas>
                </div>
                <div class="chart-container">
                    <h3>وضعیت بلیط‌ها</h3>
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
    /**
     * Render License Page.
     *
     * P2-4: صفحه فعال‌سازی لایسنس Pro.
     */
    public function render_license_page() {
        $license_manager = VSBBM_License_Manager::get_instance();
        $license_info    = $license_manager->get_license_info();
        $is_active       = $license_manager->is_license_active();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'License Management', 'vs-bus-booking-manager' ); ?></h1>

            <div class="notice notice-info">
                <p>
                    <strong><?php esc_html_e( 'VS Bus Booking Manager Pro', 'vs-bus-booking-manager' ); ?></strong>
                    <?php esc_html_e( 'برای فعال‌سازی قابلیت‌های پیشرفته (SMS، اسکنر بلیط، REST API، PDF با لوگو)، لایسنس نسخه Pro را وارد کنید.', 'vs-bus-booking-manager' ); ?>
                </p>
            </div>

            <div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
                <?php if ( $is_active ) : ?>
                    <!-- وضعیت فعال -->
                    <h2 style="color: #46b450;">✓ <?php esc_html_e( 'License Active', 'vs-bus-booking-manager' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Status', 'vs-bus-booking-manager' ); ?></th>
                            <td><span style="color: #46b450; font-weight: bold;"><?php esc_html_e( 'Active', 'vs-bus-booking-manager' ); ?></span></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'License Key', 'vs-bus-booking-manager' ); ?></th>
                            <td><code><?php echo esc_html( $license_info['license_key'] ); ?></code></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Email', 'vs-bus-booking-manager' ); ?></th>
                            <td><?php echo esc_html( $license_info['email'] ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Expires At', 'vs-bus-booking-manager' ); ?></th>
                            <td><?php echo esc_html( $license_info['expires_at'] ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Activated At', 'vs-bus-booking-manager' ); ?></th>
                            <td><?php echo esc_html( $license_info['activated_at'] ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Site URL', 'vs-bus-booking-manager' ); ?></th>
                            <td><?php echo esc_html( $license_info['site_url'] ); ?></td>
                        </tr>
                    </table>
                    <p>
                        <button type="button" class="button button-secondary" id="vsbbm-deactivate-license">
                            <?php esc_html_e( 'Deactivate License', 'vs-bus-booking-manager' ); ?>
                        </button>
                    </p>

                <?php else : ?>
                    <!-- وضعیت غیرفعال -->
                    <h2><?php esc_html_e( 'Activate License', 'vs-bus-booking-manager' ); ?></h2>
                    <form id="vsbbm-license-form">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="license_key"><?php esc_html_e( 'License Key', 'vs-bus-booking-manager' ); ?> <span style="color:red;">*</span></label>
                                </th>
                                <td>
                                    <input type="text" id="license_key" name="license_key" class="regular-text" required placeholder="VSBBM-XXXX-XXXX-XXXX">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="email"><?php esc_html_e( 'Email', 'vs-bus-booking-manager' ); ?> <span style="color:red;">*</span></label>
                                </th>
                                <td>
                                    <input type="email" id="email" name="email" class="regular-text" required placeholder="you@example.com">
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <button type="submit" class="button button-primary" id="vsbbm-activate-license">
                                <?php esc_html_e( 'Activate License', 'vs-bus-booking-manager' ); ?>
                            </button>
                            <span class="spinner" id="vsbbm-license-spinner" style="float: none; margin: 0 10px;"></span>
                        </p>
                    </form>
                <?php endif; ?>

                <!-- نتیجه AJAX -->
                <div id="vsbbm-license-result" style="margin-top: 20px;"></div>
            </div>

            <!-- اطلاعات خرید -->
            <div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px; background: #f9f9f9;">
                <h3><?php esc_html_e( 'Get Pro Features', 'vs-bus-booking-manager' ); ?></h3>
                <p><?php esc_html_e( 'برای خرید لایسنس نسخه Pro به سایت ما مراجعه کنید:', 'vs-bus-booking-manager' ); ?></p>
                <p>
                    <a href="https://vernasoft.ir" target="_blank" class="button button-primary">
                        <?php esc_html_e( 'Buy Pro License', 'vs-bus-booking-manager' ); ?>
                    </a>
                </p>
            </div>

            <script>
            jQuery(document).ready(function($) {
                // فعال‌سازی لایسنس
                $('#vsbbm-license-form').on('submit', function(e) {
                    e.preventDefault();
                    var $btn = $('#vsbbm-activate-license');
                    var $spinner = $('#vsbbm-license-spinner');
                    var $result = $('#vsbbm-license-result');

                    $btn.prop('disabled', true);
                    $spinner.addClass('is-active');
                    $result.html('');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'vsbbm_activate_license',
                            nonce: '<?php echo wp_create_nonce( 'vsbbm_license_activation' ); ?>',
                            license_key: $('#license_key').val(),
                            email: $('#email').val()
                        },
                        success: function(response) {
                            $spinner.removeClass('is-active');
                            $btn.prop('disabled', false);

                            if (response.success) {
                                $result.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                                setTimeout(function() { window.location.reload(); }, 2000);
                            } else {
                                $result.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                            }
                        },
                        error: function() {
                            $spinner.removeClass('is-active');
                            $btn.prop('disabled', false);
                            $result.html('<div class="notice notice-error"><p>خطا در ارتباط با سرور</p></div>');
                        }
                    });
                });

                // غیرفعال‌سازی لایسنس
                $('#vsbbm-deactivate-license').on('click', function() {
                    if (!confirm('<?php esc_html_e( 'Are you sure you want to deactivate the license?', 'vs-bus-booking-manager' ); ?>')) return;

                    var $btn = $(this);
                    var $result = $('#vsbbm-license-result');

                    $btn.prop('disabled', true);

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'vsbbm_deactivate_license',
                            nonce: '<?php echo wp_create_nonce( 'vsbbm_license_deactivation' ); ?>',
                        },
                        success: function(response) {
                            $btn.prop('disabled', false);
                            if (response.success) {
                                $result.html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                                setTimeout(function() { window.location.reload(); }, 2000);
                            } else {
                                $result.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                            }
                        },
                        error: function() {
                            $btn.prop('disabled', false);
                            $result.html('<div class="notice notice-error"><p>خطا در ارتباط با سرور</p></div>');
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }
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