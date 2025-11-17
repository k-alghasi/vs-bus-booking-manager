<?php
/**
 * Plugin Name: vsBus Booking Manager
 * Plugin URI:  https://vernasoft.ir
 * Description: سیستم رزرواسیون صندلی اتوبوس با انتخاب گرافیکی و لیست سیاه
 * Version:     1.9.0
 * Author:      VernaSoft (Kazem Alghasi)
 * Author URI:  https://vernasoft.ir
 * Text Domain: vs-bus-booking-manager
 * License:     GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

defined('ABSPATH') || exit;

// تعریف ثابت‌های پلاگین
define('VSBBM_VERSION', '1.9.0');
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
        require_once VSBBM_PLUGIN_PATH . 'includes/class-blacklist.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-seat-manager.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-booking-handler.php';
        require_once VSBBM_PLUGIN_PATH . 'includes/class-admin-interface.php';
    }
    
    public function activate() {
        VSBBM_Blacklist::create_table();
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    public function init() {
    load_plugin_textdomain('vs-bus-booking-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // مقداردهی اولیه ماژول‌ها - اینجا هوک‌ها ثبت می‌شن
    error_log('🎯 VSBBM: Main init called');
    
    if (class_exists('VSBBM_Seat_Manager')) {
        VSBBM_Seat_Manager::init();
        error_log('🎯 VSBBM: Seat Manager initialized');
    } else {
        error_log('🎯 VSBBM: Seat Manager class not found!');
    }
    
    VSBBM_Blacklist::init();
    VSBBM_Booking_Handler::init();
    VSBBM_Admin_Interface::init();
}
    
    public function admin_init() {
        // اضافه کردن لینک تنظیمات به صفحه پلاگین‌ها
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
    }
    
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=vsbbm-blacklist') . '">' . __('تنظیمات', 'vs-bus-booking-manager') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}

// راه‌اندازی پلاگین
function VSBBM() {
    return VS_Bus_Booking_Manager::get_instance();
}

// راه‌اندازی
VSBBM();

// فایل‌های include
require_once VSBBM_PLUGIN_PATH . 'includes/class-blacklist.php';
require_once VSBBM_PLUGIN_PATH . 'includes/class-seat-manager.php';
require_once VSBBM_PLUGIN_PATH . 'includes/class-seat-reservations.php';
require_once VSBBM_PLUGIN_PATH . 'includes/class-booking-handler.php';
require_once VSBBM_PLUGIN_PATH . 'includes/class-admin-interface.php';
require_once VSBBM_PLUGIN_PATH . 'includes/class-email-notifications.php';
require_once VSBBM_PLUGIN_PATH . 'includes/class-ticket-manager.php';

// مقداردهی اولیه ماژول‌ها - این‌ها در کلاس اصلی فراخوانی می‌شوند
// VSBBM_Blacklist::init();
// VSBBM_Seat_Manager::init();
// VSBBM_Booking_Handler::init();
// VSBBM_Admin_Interface::init();

// بعد از خط 77 (قبل از پایانی)
add_action('wp_head', function() {
    if (is_product()) {
        echo '<!-- 🎯 VSBBM: Plugin MAIN FILE is loaded -->';
        
        if (class_exists('VSBBM_Seat_Manager')) {
            echo '<!-- 🎯 VSBBM: Seat Manager Class EXISTS -->';
        } else {
            echo '<!-- 🎯 VSBBM: Seat Manager Class NOT FOUND -->';
        }
    }
});

// تست مستقیم هوک - بیرون از کلاس
// add_action('woocommerce_after_add_to_cart_button', function() {
//     echo '<div style="background: blue; color: white; padding: 20px; margin: 20px 0;">';
//     echo '🔵 تست مستقیم: هوک از فایل اصلی پلاگین کار می‌کند!';
//     echo '</div>';
// }, 20);

// تزریق صندلی‌ها به محتوای محصول - نسخه نهایی
// add_filter('the_content', 'vsbbm_inject_seats_into_content');

function vsbbm_inject_seats_into_content($content) {
    // فقط در صفحه محصول و در لوپ اصلی
    if (is_product() && in_the_loop() && is_main_query()) {
        
        global $product;
        
        if ($product && class_exists('VSBBM_Seat_Manager')) {
            $product_id = $product->get_id();
            $is_enabled = VSBBM_Seat_Manager::is_seat_booking_enabled($product_id);
            
            if ($is_enabled) {
                ob_start();
                VSBBM_Seat_Manager::display_seat_selection();
                $seats_content = ob_get_clean();
                $content .= $seats_content;
            }
        }
    }
    
    return $content;
}