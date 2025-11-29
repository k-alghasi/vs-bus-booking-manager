<?php
/**
 * Plugin Name:       vsBus Booking Manager
 * Plugin URI:        https://vernasoft.ir
 * Description:       Bus seat reservation system with graphical selection and blacklist management.
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

// Define Constants
if ( ! defined( 'VSBBM_VERSION' ) ) {
    define( 'VSBBM_VERSION', '2.0.0' );
}
if ( ! defined( 'VSBBM_PLUGIN_URL' ) ) {
    define( 'VSBBM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'VSBBM_PLUGIN_PATH' ) ) {
    define( 'VSBBM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

class VS_Bus_Booking_Manager {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies() {
        // 1. Utilities & Helpers (باید اول لود شوند)
        require_once VSBBM_PLUGIN_PATH . 'includes/class-cache-manager.php';
        
        // 2. Models (دیتابیس و منطق داده)
        require_once VSBBM_PLUGIN_PATH . 'includes/class-blacklist.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-seat-reservations.php'; // حیاتی: قبل از منیجر لود شود
        require_once VSBBM_PLUGIN_PATH . 'includes/class-ticket-manager.php';
        
        // 3. Controllers (مدیریت منطق)
        require_once VSBBM_PLUGIN_PATH . 'includes/class-seat-manager.php'; // از کلاس‌های بالا استفاده می‌کند
        require_once VSBBM_PLUGIN_PATH . 'includes/class-booking-handler.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-email-notifications.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-sms-notifications.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-license-manager.php';
        
        // 4. Admin & API
        require_once VSBBM_PLUGIN_PATH . 'includes/class-admin-interface.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-rest-api.php';
    }

    private function init_hooks() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        add_action( 'init', array( $this, 'init' ) );
        add_action( 'admin_init', array( $this, 'admin_init' ) );
    }

    public function activate() {
        // ایجاد جداول
        if ( class_exists( 'VSBBM_Blacklist' ) ) VSBBM_Blacklist::create_table();
        if ( class_exists( 'VSBBM_Seat_Reservations' ) ) VSBBM_Seat_Reservations::create_table();
        if ( class_exists( 'VSBBM_Ticket_Manager' ) ) VSBBM_Ticket_Manager::create_table();
        
        $this->create_api_tokens_table();
        flush_rewrite_rules();
    }

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

    public function init() {
        load_plugin_textdomain( 'vs-bus-booking-manager', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        
        // مقداردهی کلاس‌ها
        VSBBM_Seat_Manager::init();
        VSBBM_Booking_Handler::init();
        VSBBM_Admin_Interface::init();
        // سایر کلاس‌ها Singleton هستند و با get_instance فراخوانی می‌شوند
    }

    public function admin_init() {
        // لینک تنظیمات
    }
}

function VSBBM() {
    return VS_Bus_Booking_Manager::get_instance();
}

VSBBM();