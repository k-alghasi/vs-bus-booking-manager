<?php
/**
 * Plugin Name:       vsBus Booking Manager
 * Plugin URI:        https://vernasoft.ir
 * Description:       Bus seat reservation system with graphical selection and blacklist management.
 * Version:           1.9.1
 * Author:            VernaSoft (Kazem Alghasi)
 * Author URI:        https://vernasoft.ir
 * Text Domain:       vs-bus-booking-manager
 * Domain Path:       /languages
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

// جلوگیری از دسترسی مستقیم
defined( 'ABSPATH' ) || exit;

// تعریف ثابت‌های پلاگین
if ( ! defined( 'VSBBM_VERSION' ) ) {
    define( 'VSBBM_VERSION', '1.9.1' );
}
if ( ! defined( 'VSBBM_PLUGIN_URL' ) ) {
    define( 'VSBBM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'VSBBM_PLUGIN_PATH' ) ) {
    define( 'VSBBM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'VSBBM_TEXT_DOMAIN' ) ) {
    define( 'VSBBM_TEXT_DOMAIN', 'vs-bus-booking-manager' );
}

/**
 * Main Plugin Class
 *
 * @since 1.0.0
 */
class VS_Bus_Booking_Manager {

    /**
     * Singleton instance
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
        if ( ! this->check_requirements() ) {
            return;
        }

        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Check server requirements.
     *
     * @return bool
     */
    private function check_requirements() {
        if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
            add_action( 'admin_notices', function() {
                echo '<div class="error"><p>' . esc_html__( 'VS Bus Booking Manager requires PHP 7.4 or higher.', 'vs-bus-booking-manager' ) . '</p></div>';
            });
            return false;
        }
        return true;
    }

    /**
     * Load required files.
     */
    private function load_dependencies() {
        $files = array(
            'includes/class-blacklist.php',
            'includes/class-seat-manager.php',
            'includes/class-seat-reservations.php',
            'includes/class-booking-handler.php',
            'includes/class-admin-interface.php',
            'includes/class-email-notifications.php',
            'includes/class-ticket-manager.php',
            'includes/class-sms-notifications.php',
            'includes/class-rest-api.php',
            'includes/class-license-manager.php',
            'includes/class-cache-manager.php',
        );

        foreach ( $files as $file ) {
            if ( file_exists( VSBBM_PLUGIN_PATH . $file ) ) {
                require_once VSBBM_PLUGIN_PATH . $file;
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
     * Activation logic.
     */
    public function activate() {
        // ایجاد جداول کلاس‌های دیگر
        $classes_with_tables = array(
            'VSBBM_Blacklist',
            'VSBBM_Seat_Reservations',
            'VSBBM_Ticket_Manager'
        );

        foreach ( $classes_with_tables as $class_name ) {
            if ( class_exists( $class_name ) && method_exists( $class_name, 'create_table' ) ) {
                call_user_func( array( $class_name, 'create_table' ) );
            }
        }

        // ایجاد جدول توکن‌ها (بهتر است این متد هم به یک کلاس Install منتقل شود)
        $this->create_api_tokens_table();

        flush_rewrite_rules();
    }

    /**
     * Create API tokens table with strict dbDelta syntax.
     */
    private function create_api_tokens_table() {
        global $wpdb;
        $table_name      = $wpdb->prefix . 'vsbbm_api_tokens';
        $charset_collate = $wpdb->get_charset_collate();

        // نکته مهم: dbDelta به دو فاصله بعد از PRIMARY KEY نیاز دارد
        // و هر فیلد باید در یک خط جداگانه باشد.
        $sql = "CREATE TABLE $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            token varchar(255) NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY user_id (user_id),
            KEY expires_at (expires_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Deactivation logic.
     */
    public function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Core initialization.
     */
    public function init() {
        // بارگذاری ترجمه‌ها
        load_plugin_textdomain( 'vs-bus-booking-manager', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

        // لیست کلاس‌هایی که نیاز به init دارند
        $modules = array(
            'VSBBM_Seat_Manager',
            'VSBBM_Blacklist',
            'VSBBM_Booking_Handler',
            'VSBBM_Admin_Interface',
            'VSBBM_Seat_Reservations',
        );

        foreach ( $modules as $module ) {
            if ( class_exists( $module ) && method_exists( $module, 'init' ) ) {
                call_user_func( array( $module, 'init' ) );
            }
        }
    }

    /**
     * Admin specific initialization.
     */
    public function admin_init() {
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );
    }

    /**
     * Add settings link to plugins page.
     *
     * @param array $links Existing links.
     * @return array Modified links.
     */
    public function add_settings_link( $links ) {
        // تغییر مهم: متن انگلیسی برای ترجمه‌پذیری
        $settings_link = '<a href="' . admin_url( 'admin.php?page=vsbbm-settings' ) . '">' . __( 'Settings', 'vs-bus-booking-manager' ) . '</a>';
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