<?php
/**
 * Plugin Name:       vsBus Booking Manager
 * Plugin URI:        https://vernasoft.ir
 * Description:       Bus seat reservation system with graphical selection, time-based scheduling, and PDF tickets.
 * Version:           2.0.0
 * Author:            VernaSoft (Kazem Alghasi)
 * Author URI:        https://vernasoft.ir
 * Text Domain:       vs-bus-booking-manager
 * Domain Path:       /languages
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * License:           GPL v2 or later
 */

defined( 'ABSPATH' ) || exit;

// تعریف ثابت‌های پلاگین
if ( ! defined( 'VSBBM_VERSION' ) ) {
    define( 'VSBBM_VERSION', '2.0.0' );
}
if ( ! defined( 'VSBBM_PLUGIN_URL' ) ) {
    define( 'VSBBM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'VSBBM_PLUGIN_PATH' ) ) {
    define( 'VSBBM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

/**
 * Main Plugin Class
 */
class VS_Bus_Booking_Manager {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get the singleton instance.
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
     */
    private function load_dependencies() {
        // 0. Composer Autoloader (اولویت اول برای PDF)
        if ( file_exists( VSBBM_PLUGIN_PATH . 'vendor/autoload.php' ) ) {
            require_once VSBBM_PLUGIN_PATH . 'vendor/autoload.php';
        }

        // 1. Utilities & Helpers
        require_once VSBBM_PLUGIN_PATH . 'includes/class-cache-manager.php';
        
        // 2. Models (منطق دیتابیس - باید قبل از کنترلرها باشند)
        require_once VSBBM_PLUGIN_PATH . 'includes/class-blacklist.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-seat-reservations.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-ticket-manager.php';
        
        // 3. Controllers (مدیریت منطق)
        require_once VSBBM_PLUGIN_PATH . 'includes/class-seat-manager.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-booking-handler.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-email-notifications.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-sms-notifications.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-license-manager.php';
        
        // 4. Admin & API
        require_once VSBBM_PLUGIN_PATH . 'includes/class-admin-interface.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-rest-api.php';

        // 5. Elementor Integration (فقط اگر المنتور فعال باشد)
        add_action( 'plugins_loaded', function() {
            if ( did_action( 'elementor/loaded' ) ) {
                if ( file_exists( VSBBM_PLUGIN_PATH . 'includes/class-elementor-integration.php' ) ) {
                    require_once VSBBM_PLUGIN_PATH . 'includes/class-elementor-integration.php';
                }
            }
        });
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
     * Activation logic (Create Tables).
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

        flush_rewrite_rules();
    }

    /**
     * Create API tokens table.
     */
    private function create_api_tokens_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'vsbbm_api_tokens';
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
     */
    public function deactivate() {
        flush_rewrite_rules();
        // معمولاً جداول را هنگام غیرفعال‌سازی پاک نمی‌کنیم تا داده‌ها حفظ شوند
        // برای پاکسازی کامل باید از uninstall.php استفاده کرد
    }

    /**
     * Core initialization.
     */
    public function init() {
        // بارگذاری ترجمه‌ها
        load_plugin_textdomain( 'vs-bus-booking-manager', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        
        // مقداردهی اولیه کلاس‌ها (Controllers)
        // نکته: کلاس‌های مدل (مانند Reservations) به صورت Singleton و Static accessed هستند و نیاز به init صریح ندارند
        
        // راه اندازی Seat Manager (به صورت Singleton)
        VSBBM_Seat_Manager::get_instance();
        
        // راه اندازی هندلرهای دیگر
        VSBBM_Booking_Handler::init();
        VSBBM_Admin_Interface::init();
        VSBBM_REST_API::init();
        
        // Notifications
        VSBBM_Email_Notifications::get_instance();
        VSBBM_SMS_Notifications::get_instance();
        
        // License
        if ( class_exists( 'VSBBM_License_Manager' ) ) {
            VSBBM_License_Manager::get_instance();
        }
    }

    /**
     * Admin specific initialization.
     */
    public function admin_init() {
        // لینک تنظیمات در صفحه پلاگین‌ها
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );
    }

    /**
     * Add settings link.
     */
    public function add_settings_link( $links ) {
        $settings_link = '<a href="' . admin_url( 'admin.php?page=vsbbm-settings' ) . '">' . __( 'Settings', 'vs-bus-booking-manager' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }
}

/**
 * Main instance wrapper.
 */
function VSBBM() {
    return VS_Bus_Booking_Manager::get_instance();
}

// Start the plugin
VSBBM();