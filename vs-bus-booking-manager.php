<?php
/**
 * Plugin Name:       vsBus Booking Manager
 * Plugin URI:        https://vernasoft.ir
 * Description:       Bus seat reservation system with graphical selection, time-based scheduling, and PDF tickets.
 * Version:           2.1.0
 * Author:            VernaSoft (Kazem Alghasi)
 * Author URI:        https://vernasoft.ir
 * Text Domain:       vs-bus-booking-manager
 * Domain Path:       /languages
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * License:           GPL v2 or later
 */

defined( 'ABSPATH' ) || exit;

// ----------------------------------------------------------------------------
// تعریف ثابت‌های پلاگین
// ----------------------------------------------------------------------------

if ( ! defined( 'VSBBM_VERSION' ) ) {
    define( 'VSBBM_VERSION', '2.1.0' );
}
if ( ! defined( 'VSBBM_PLUGIN_URL' ) ) {
    define( 'VSBBM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'VSBBM_PLUGIN_PATH' ) ) {
    define( 'VSBBM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'VSBBM_PLUGIN_FILE' ) ) {
    define( 'VSBBM_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'VSBBM_PLUGIN_BASENAME' ) ) {
    define( 'VSBBM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

/**
 * Main Plugin Class
 *
 * مسئولیت‌ها:
 *   - بارگذاری ترتیبی وابستگی‌ها (load order مهم است)
 *   - ثبت هوک‌های فعال‌سازی/غیرفعال‌سازی
 *   - راه‌اندازی کلاس‌های اصلی روی init
 *
 * @since 1.0.0
 */
class VS_Bus_Booking_Manager {

    /**
     * Singleton instance.
     *
     * @var VS_Bus_Booking_Manager|null
     */
    private static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return VS_Bus_Booking_Manager
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load all required files.
     *
     * ترتیب بارگذاری بسیار مهم است:
     *   1. Composer (کتابخانه‌های خارجی مثل TCPDF)
     *   2. Settings (همه‌چیز ازش استفاده می‌کند)
     *   3. Models (منطق دیتابیس — بدون وابستگی به Controllers)
     *   4. Services (منطق کسب‌وکار — از Models استفاده می‌کند)
     *   5. Controllers (از Services و Models استفاده می‌کنند)
     *   6. Admin & API (از Controllers استفاده می‌کنند)
     *   7. Integrations (شرطی — فقط اگر افزونه‌های دیگر فعال باشند)
     *
     * P2-4: فایل‌های Pro (SMS, REST API) به‌صورت conditional لود می‌شوند.
     * این فایل‌ها ممکن است در Free وجود نداشته باشند (چون به Pro منتقل شده‌اند).
     */
    private function load_dependencies() {
        // 1. Composer Autoloader (برای PDF و QR Code)
        if ( file_exists( VSBBM_PLUGIN_PATH . 'vendor/autoload.php' ) ) {
            require_once VSBBM_PLUGIN_PATH . 'vendor/autoload.php';
        }

        // 2. Settings (مرکز تنظیمات — باید اول لود شود)
        require_once VSBBM_PLUGIN_PATH . 'includes/class-settings.php';

        // 3. Models (لایه دسترسی به داده — مستقل از Controllers)
        require_once VSBBM_PLUGIN_PATH . 'includes/class-cache-manager.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-blacklist.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-seat-reservations.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-ticket-manager.php';

        // 4. Services (منطق کسب‌وکار — از Models استفاده می‌کند)
        require_once VSBBM_PLUGIN_PATH . 'includes/class-reservation-service.php';

        // 5. Controllers (مدیریت منطق و هوک‌ها — از Services استفاده می‌کنند)
        require_once VSBBM_PLUGIN_PATH . 'includes/class-seat-manager.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-booking-handler.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-email-notifications.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-license-manager.php';

        // 6. Admin Interface (همیشه لود می‌شود — با gating داخلی برای منوهای Pro)
        require_once VSBBM_PLUGIN_PATH . 'includes/class-admin-interface.php';

        // P2-4: Pro features — فقط اگر فایل وجود داشته باشد و لایسنس فعال باشد
        // این فایل‌ها در نسخه انتشار به افزونه Pro منتقل می‌شوند.
        // فعلاً برای توسعه، اگر در Free وجود داشته باشند و لایسنس فعال باشد، لود می‌شوند.
        if ( VSBBM_Settings::get_instance()->is_pro_active() ) {
            $pro_files = array(
                'includes/class-sms-notifications.php',
                'includes/class-rest-api.php',
            );
            foreach ( $pro_files as $pro_file ) {
                $full_path = VSBBM_PLUGIN_PATH . $pro_file;
                if ( file_exists( $full_path ) ) {
                    require_once $full_path;
                }
            }
        }

        // 7. Elementor Integration (شرطی — فقط اگر المنتور فعال باشد)
        add_action( 'plugins_loaded', array( $this, 'maybe_load_elementor_integration' ) );
    }

    /**
     * Load Elementor integration if Elementor is active.
     */
    public function maybe_load_elementor_integration() {
        if ( did_action( 'elementor/loaded' ) ) {
            $file = VSBBM_PLUGIN_PATH . 'includes/class-elementor-integration.php';
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        add_action( 'init', array( $this, 'init' ) );
        add_action( 'admin_init', array( $this, 'admin_init' ) );
    }

    /**
     * Activation logic — create database tables.
     *
     * جداول ساخته می‌شوند و در صورت نیاز به‌روزرسانی می‌شوند (dbDelta).
     * برای جداولی که فعال‌سازی قبلی ناموفق بوده، کلاس‌های مربوعه
     * مکانیزم self-healing روی admin_init دارند (ensure_table_exists).
     */
    public function activate() {
        // ایجاد جداول کلاس‌های مدل
        if ( class_exists( 'VSBBM_Blacklist' ) ) {
            VSBBM_Blacklist::create_table();
        }
        if ( class_exists( 'VSBBM_Seat_Reservations' ) ) {
            VSBBM_Seat_Reservations::create_table();
        }
        if ( class_exists( 'VSBBM_Ticket_Manager' ) ) {
            VSBBM_Ticket_Manager::create_table();
        }

        // ایجاد جدول توکن‌های API
        $this->create_api_tokens_table();

        // زمان شروع فعال‌سازی (برای آمار cache)
        if ( ! get_option( 'vsbbm_cache_start_time' ) ) {
            update_option( 'vsbbm_cache_start_time', current_time( 'mysql' ) );
        }

        flush_rewrite_rules();
    }

    /**
     * Create API tokens table.
     */
    private function create_api_tokens_table() {
        global $wpdb;
        $table_name      = $wpdb->prefix . 'vsbbm_api_tokens';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            token varchar(255) NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY user_id (user_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Deactivation logic.
     *
     * توجه: جداول حذف نمی‌شوند تا داده‌ها حفظ شوند.
     * برای پاکسازی کامل، از uninstall.php استفاده کنید.
     */
    public function deactivate() {
        // لغو cron job پاکسازی رزروهای منقضی
        $timestamp = wp_next_scheduled( 'vsbbm_cleanup_expired_reservations' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'vsbbm_cleanup_expired_reservations' );
        }

        flush_rewrite_rules();
    }

    /**
     * Core initialization — runs on 'init' hook.
     *
     * P2-4: کلاس‌های Pro (SMS, REST API) فقط در صورت وجود و فعال بودن
     * لایسنس راه‌اندازی می‌شوند.
     */
    public function init() {
        // بارگذاری ترجمه‌ها
        load_plugin_textdomain(
            'vs-bus-booking-manager',
            false,
            dirname( VSBBM_PLUGIN_BASENAME ) . '/languages'
        );

        // راه‌اندازی کلاس‌های اصلی (Free — همیشه)
        VSBBM_Seat_Manager::get_instance();
        VSBBM_Booking_Handler::init();
        VSBBM_Admin_Interface::init();
        VSBBM_Email_Notifications::get_instance();

        // راه‌اندازی License Manager (همیشه — مدیریت لایسنس Pro)
        if ( class_exists( 'VSBBM_License_Manager' ) ) {
            VSBBM_License_Manager::get_instance();
        }

        // P2-4: Pro features — فقط اگر لایسنس فعال باشد و کلاس‌ها موجود باشند
        if ( VSBBM_Settings::get_instance()->is_pro_active() ) {
            // SMS Notifications (Pro)
            if ( class_exists( 'VSBBM_SMS_Notifications' ) ) {
                VSBBM_SMS_Notifications::get_instance();
            }
            // REST API (Pro)
            if ( class_exists( 'VSBBM_REST_API' ) ) {
                VSBBM_REST_API::init();
            }
        }

        /**
         * Action: vsbbm_init
         *
         * پس از راه‌اندازی کامل افزونه اجرا می‌شود.
         * افزونه‌های مکمل (مثل Pro) می‌توانند از این action برای
         * راه‌اندازی خود استفاده کنند.
         *
         * @since 2.1.0
         */
        do_action( 'vsbbm_init' );
    }

    /**
     * Admin-specific initialization — runs on 'admin_init' hook.
     */
    public function admin_init() {
        // لینک تنظیمات در صفحه پلاگین‌ها
        add_filter( 'plugin_action_links_' . VSBBM_PLUGIN_BASENAME, array( $this, 'add_settings_link' ) );
    }

    /**
     * Add settings link to plugins page.
     *
     * @param array $links Existing links.
     * @return array Modified links.
     */
    public function add_settings_link( $links ) {
        $settings_link = '<a href="' . admin_url( 'admin.php?page=vsbbm-settings' ) . '">'
            . __( 'Settings', 'vs-bus-booking-manager' )
            . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }
}

/**
 * Main instance wrapper.
 *
 * @return VS_Bus_Booking_Manager
 */
function VSBBM() {
    return VS_Bus_Booking_Manager::get_instance();
}

// Start the plugin
VSBBM();