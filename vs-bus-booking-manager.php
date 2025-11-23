<?php
/**
 * Plugin Name: vsBus Booking Manager
 * Plugin URI:  https://vernasoft.ir
 * Description: سیستم رزرواسیون صندلی اتوبوس با انتخاب گرافیکی و لیست سیاه
 * Version:     1.9.1
 * Author:      VernaSoft (Kazem Alghasi)
 * Author URI:  https://vernasoft.ir
 * Text Domain: vs-bus-booking-manager
 * License:     GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

defined('ABSPATH') || exit;

// تعریف ثابت‌های پلاگین
define('VSBBM_VERSION', '1.9.1');
define('VSBBM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VSBBM_PLUGIN_PATH', plugin_dir_path(__FILE__));

// کلاس اصلی پلاگین
class VS_Bus_Booking_Manager {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
        $this->includes();
    }
    
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'admin_init'));
    }
    
    private function includes() {
        // بارگذاری تمام کلاس‌های مورد نیاز
        require_once VSBBM_PLUGIN_PATH . 'includes/class-blacklist.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-seat-manager.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-seat-reservations.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-booking-handler.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-admin-interface.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-email-notifications.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-ticket-manager.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-sms-notifications.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-rest-api.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-license-manager.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-cache-manager.php';
    }
    
    public function activate() {
        // ایجاد جداول هنگام فعال‌سازی
        if (class_exists('VSBBM_Blacklist')) {
            VSBBM_Blacklist::create_table();
        }

        if (class_exists('VSBBM_Seat_Reservations')) {
            VSBBM_Seat_Reservations::create_table();
        }
        
        if (class_exists('VSBBM_Ticket_Manager')) {
            VSBBM_Ticket_Manager::create_table();
        }

        // ایجاد جدول API tokens
        $this->create_api_tokens_table();

        flush_rewrite_rules();
    }

    /**
     * ایجاد جدول API tokens با استفاده استاندارد از dbDelta
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
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY user_id (user_id),
            KEY expires_at (expires_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function deactivate() {
        flush_rewrite_rules();
    }
    
    public function init() {
        // بارگذاری فایل ترجمه
        load_plugin_textdomain('vs-bus-booking-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // مقداردهی اولیه ماژول‌ها
        // error_log('🎯 VSBBM: Main init called'); // کامنت شده برای جلوگیری از شلوغی لاگ

        if (class_exists('VSBBM_Seat_Manager')) {
            VSBBM_Seat_Manager::init();
        }

        if (class_exists('VSBBM_Blacklist')) {
            VSBBM_Blacklist::init();
        }

        if (class_exists('VSBBM_Booking_Handler')) {
            VSBBM_Booking_Handler::init();
        }

        if (class_exists('VSBBM_Admin_Interface')) {
            VSBBM_Admin_Interface::init();
        }

        // مقداردهی اولیه سیستم رزرواسیون
        if (class_exists('VSBBM_Seat_Reservations')) {
            VSBBM_Seat_Reservations::init();
        }

        // سایر کلاس‌ها (Email, Ticket, SMS, API) معمولاً خودکار یا با get_instance در انتهای فایل‌هایشان لود می‌شوند
        // اما چک کردن وجودشان ضرری ندارد
    }
    
    public function admin_init() {
        // اضافه کردن لینک تنظیمات به صفحه پلاگین‌ها
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
    }
    
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=vsbbm-settings') . '">' . __('تنظیمات', 'vs-bus-booking-manager') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}

// تابع دسترسی جهانی به پلاگین
function VSBBM() {
    return VS_Bus_Booking_Manager::get_instance();
}

// راه‌اندازی نهایی
VSBBM();