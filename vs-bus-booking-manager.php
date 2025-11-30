<?php
/**
 * Plugin Name: VS Bus Booking Manager
 * Plugin URI: https://vs-plugins.com/bus-booking-manager
 * Description: سیستم حرفه‌ای رزرواسیون و مدیریت اتوبوس با معماری ماژولار و پشتیبانی از نسخه‌های Free/Pro
 * Version: 2.0.1
 * Author: VernaSoft
 * Author URI: https://vernasoft.ir
 * License: GPL v2 or later
 * Text Domain: vs-bus-booking-manager
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 🔒 امنیت - جلوگیری از دسترسی مستقیم
 */
defined('ABSPATH') or die('دسترسی غیرمجاز!');

/**
 * 🏗️ تعریف Constants پایه
 */
define('VSBBM_VERSION', '2.0.1');
define('VSBBM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VSBBM_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('VSBBM_PLUGIN_FILE', __FILE__);
define('VSBBM_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Constants برای مسیرهای اصلی
define('VSBBM_CORE_PATH', VSBBM_PLUGIN_PATH . 'core/');
define('VSBBM_MODULES_PATH', VSBBM_PLUGIN_PATH . 'modules/');
define('VSBBM_TEMPLATES_PATH', VSBBM_PLUGIN_PATH . 'templates/');
define('VSBBM_ASSETS_PATH', VSBBM_PLUGIN_PATH . 'assets/');
define('VSBBM_INCLUDES_PATH', VSBBM_PLUGIN_PATH . 'includes/');

/**
 * 🎯 کلاس اصلی پلاگین
 */
final class VS_Bus_Booking_Manager
{
    /**
     * Instance شیء پلاگین
     */
    private static $instance = null;

    /**
     * ماژول‌های فعال
     */
    private $modules = [];

    /**
     * Singleton Instance
     */
    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->define_constants();
        $this->init_hooks();
        $this->check_requirements();
    }

    /**
     * تعریف Constants اضافی
     */
    private function define_constants()
    {
        // Constants برای نوع لایسنس
        define('VSBBM_LICENSE_FREE', 'free');
        define('VSBBM_LICENSE_PRO', 'pro');
        define('VSBBM_LICENSE_ENTERPRISE', 'enterprise');

        // Constants برای وضعیت لایسنس
        define('VSBBM_LICENSE_ACTIVE', 'active');
        define('VSBBM_LICENSE_EXPIRED', 'expired');
        define('VSBBM_LICENSE_CANCELLED', 'cancelled');

        // Constants برای نسخه دیتابیس
        define('VSBBM_DB_VERSION', '2.0.1');
    }

    /**
     * ثبت هوک‌های وردپرس
     */
    private function init_hooks()
    {
        // هوک‌های فعال‌سازی و غیرفعال‌سازی
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // هوک‌های اجرایی
        add_action('plugins_loaded', [$this, 'init_plugin'], 0);
        add_action('init', [$this, 'load_textdomain']);

        // هوک‌های مدیریت لایسنس
        add_action('admin_init', [$this, 'check_license_status']);

        // هوک بررسی به‌روزرسانی دیتابیس
        add_action('admin_init', [$this, 'check_db_version']);
    }

    /**
     * بررسی پیش‌نیازهای سیستم
     */
    private function check_requirements()
    {
        require_once VSBBM_INCLUDES_PATH . 'class-requirements-checker.php';
        
        if (file_exists(VSBBM_INCLUDES_PATH . 'class-requirements-checker.php')) {
            $checker = new VSBBM_Requirements_Checker();
            $checker->check();
        }
    }

    /**
     * بررسی نسخه دیتابیس
     */
    public function check_db_version()
    {
        $current_db_version = get_option('vsbbm_db_version', '1.0.0');
        
        if (version_compare($current_db_version, VSBBM_DB_VERSION, '<')) {
            $this->upgrade_database($current_db_version);
        }
    }

    /**
     * ارتقاء دیتابیس
     */
    private function upgrade_database($from_version)
    {
        // ایجاد جداول جدید یا آپدیت ساختار
        $this->create_tables();
        
        // به‌روزرسانی نسخه دیتابیس
        update_option('vsbbm_db_version', VSBBM_DB_VERSION);
        
        $this->log_event('database_upgraded', 'دیتابیس از نسخه ' . $from_version . ' به ' . VSBBM_DB_VERSION . ' ارتقاء یافت');
    }

    /**
     * فعال‌سازی پلاگین
     */
    public function activate()
    {
        // ایجاد جداول دیتابیس
        $this->create_tables();

        // راه‌اندازی داده‌های اولیه
        $this->setup_default_data();

        // ثبت نسخه دیتابیس
        update_option('vsbbm_db_version', VSBBM_DB_VERSION);

        // ثبت رویداد فعال‌سازی
        $this->log_event('plugin_activated', 'پلاگین نسخه ' . VSBBM_VERSION . ' فعال شد');

        // فلاش برای نمایش پیام موفقیت
        set_transient('vsbbm_activated', true, 30);
    }

    /**
     * غیرفعال‌سازی پلاگین
     */
    public function deactivate()
    {
        // پاک کردن cron jobs
        $this->clear_scheduled_events();

        // ثبت رویداد غیرفعال‌سازی
        $this->log_event('plugin_deactivated', 'پلاگین غیرفعال شد');
    }

    /**
     * ایجاد جداول دیتابیس
     */
    private function create_tables()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // 🗃️ جدول اتوبوس‌ها
        $table_buses = $wpdb->prefix . 'vsbbm_buses';
        $sql_buses = "CREATE TABLE {$table_buses} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            bus_number VARCHAR(50) NOT NULL,
            bus_name VARCHAR(255) NOT NULL,
            total_seats INT NOT NULL DEFAULT 40,
            amenities TEXT,
            status ENUM('active', 'inactive', 'maintenance') DEFAULT 'active',
            created_by BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY bus_number (bus_number)
        ) {$charset_collate};";

        // 🎫 جدول رزروها
        $table_bookings = $wpdb->prefix . 'vsbbm_bookings';
        $sql_bookings = "CREATE TABLE {$table_bookings} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_number VARCHAR(100) NOT NULL,
            bus_id BIGINT UNSIGNED NOT NULL,
            customer_name VARCHAR(255) NOT NULL,
            customer_email VARCHAR(255) NOT NULL,
            customer_phone VARCHAR(50),
            seat_numbers VARCHAR(255) NOT NULL,
            total_seats INT NOT NULL DEFAULT 1,
            journey_date DATE NOT NULL,
            journey_time TIME NOT NULL,
            total_amount DECIMAL(10,2) NOT NULL,
            currency VARCHAR(10) DEFAULT 'IRT',
            status ENUM('pending', 'confirmed', 'cancelled', 'completed') DEFAULT 'pending',
            payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY booking_number (booking_number),
            KEY bus_id (bus_id),
            KEY journey_date (journey_date),
            KEY customer_email (customer_email)
        ) {$charset_collate};";

        // 🔐 جدول لایسنس‌ها
        $table_licenses = $wpdb->prefix . 'vsbbm_licenses';
        $sql_licenses = "CREATE TABLE {$table_licenses} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            license_key VARCHAR(255) NOT NULL,
            customer_email VARCHAR(255) NOT NULL,
            status ENUM('active', 'expired', 'cancelled') DEFAULT 'active',
            product_type ENUM('free', 'pro', 'enterprise') DEFAULT 'free',
            expires_at DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY license_key (license_key),
            KEY customer_email (customer_email),
            KEY status (status)
        ) {$charset_collate};";

        // 📊 جدول لاگ‌ها
        $table_logs = $wpdb->prefix . 'vsbbm_logs';
        $sql_logs = "CREATE TABLE {$table_logs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(100) NOT NULL,
            event_message TEXT NOT NULL,
            user_id BIGINT UNSIGNED,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $tables_created = dbDelta([
            $sql_buses,
            $sql_bookings,
            $sql_licenses,
            $sql_logs
        ]);

        // ثبت رویداد ایجاد جداول
        $this->log_event('tables_created', 'جداول دیتابیس ایجاد شدند: ' . implode(', ', array_keys($tables_created)));
    }

    /**
     * راه‌اندازی داده‌های اولیه
     */
    private function setup_default_data()
    {
        // ایجاد لایسنس پیش‌فرض رایگان
        $this->setup_default_license();

        // ایجاد نقش‌های کاربری لازم
        $this->setup_user_roles();

        // تنظیم گزینه‌های پیش‌فرض
        $this->setup_default_options();
    }

    /**
     * ایجاد لایسنس پیش‌فرض
     */
    private function setup_default_license()
    {
        global $wpdb;
        
        $table_licenses = $wpdb->prefix . 'vsbbm_licenses';
        
        // بررسی وجود لایسنس
        $existing_license = $wpdb->get_var("SELECT COUNT(*) FROM {$table_licenses} WHERE product_type = 'free'");
        
        if (!$existing_license) {
            $default_license = [
                'license_key' => 'FREE-' . strtoupper(wp_generate_password(16, false)),
                'customer_email' => get_option('admin_email'),
                'status' => VSBBM_LICENSE_ACTIVE,
                'product_type' => VSBBM_LICENSE_FREE,
                'expires_at' => null,
                'created_at' => current_time('mysql')
            ];

            $wpdb->insert($table_licenses, $default_license);
        }
    }

    /**
     * ایجاد نقش‌های کاربری
     */
    private function setup_user_roles()
    {
        // نقش اپراتور اتوبوس
        if (!get_role('bus_operator')) {
            add_role('bus_operator', 'اپراتور اتوبوس', [
                'read' => true,
                'manage_bus_bookings' => true,
                'view_bus_reports' => true
            ]);
        }

        // نقش مدیر رزرواسیون
        if (!get_role('booking_manager')) {
            add_role('booking_manager', 'مدیر رزرواسیون', [
                'read' => true,
                'manage_bus_bookings' => true,
                'manage_buses' => true,
                'view_bus_reports' => true,
                'export_bookings' => true
            ]);
        }
    }

    /**
     * تنظیم گزینه‌های پیش‌فرض
     */
    private function setup_default_options()
    {
        $default_options = [
            'vsbbm_currency' => 'IRT',
            'vsbbm_timezone' => 'Asia/Tehran',
            'vsbbm_date_format' => 'Y/m/d',
            'vsbbm_seat_capacity' => 40,
            'vsbbm_booking_timeout' => 15,
            'vsbbm_license_type' => 'free',
            'vsbbm_version' => VSBBM_VERSION
        ];

        foreach ($default_options as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }

    /**
     * پاک کردن رویدادهای زمان‌بندی شده
     */
    private function clear_scheduled_events()
    {
        $events = [
            'vsbbm_daily_cleanup',
            'vsbbm_license_check',
            'vsbbm_backup_data'
        ];

        foreach ($events as $event) {
            $timestamp = wp_next_scheduled($event);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $event);
            }
        }
    }

    /**
     * بارگذاری فایل‌های ترجمه
     */
    public function load_textdomain()
    {
        load_plugin_textdomain(
            'vs-bus-booking-manager',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    /**
     * بررسی وضعیت لایسنس
     */
    public function check_license_status()
    {
        // این تابع در فاز ۲ کامل خواهد شد
        $license_manager = $this->get_license_manager();
        if ($license_manager) {
            $license_manager->validate_license();
        }
    }

    /**
     * راه‌اندازی اصلی پلاگین
     */
    public function init_plugin()
    {
        // بررسی وابستگی‌ها
        if (!$this->check_dependencies()) {
            return;
        }

        // بارگذاری core
        $this->load_core();

        // بارگذاری ماژول‌ها
        $this->load_modules();

        // راه‌اندازی API
        $this->init_rest_api();

        // ثبت هوک‌های frontend
        $this->init_frontend();

        // ثبت هوک‌های admin
        if (is_admin()) {
            $this->init_admin();
        }

        // ثبت رویداد راه‌اندازی
        $this->log_event('plugin_initialized', 'پلاگین نسخه ' . VSBBM_VERSION . ' راه‌اندازی شد');
    }

    /**
     * بررسی وابستگی‌ها
     */
    private function check_dependencies()
    {
        $dependencies = [
            'php_version' => version_compare(PHP_VERSION, '7.4.0', '>='),
            'wordpress_version' => version_compare(get_bloginfo('version'), '6.0', '>=')
        ];

        foreach ($dependencies as $dep => $status) {
            if (!$status) {
                $this->handle_dependency_error($dep);
                return false;
            }
        }

        return true;
    }

    /**
     * مدیریت خطای وابستگی‌ها
     */
    private function handle_dependency_error($dependency)
    {
        $messages = [
            'php_version' => 'نیازمند PHP نسخه 7.4 یا بالاتر است',
            'wordpress_version' => 'نیازمند وردپرس نسخه 6.0 یا بالاتر است'
        ];

        $message = $messages[$dependency] ?? 'وابستگی ضروری برقرار نیست';

        add_action('admin_notices', function() use ($message) {
            echo '<div class="error"><p>VS Bus Booking Manager: ' . esc_html($message) . '</p></div>';
        });

        $this->log_event('dependency_error', $message);
    }

    /**
     * بارگذاری core سیستم
     */
    private function load_core()
    {
        $core_files = [
            'class-core.php',
            'license/class-license-manager.php',
            'database/class-database-manager.php',
            'api/class-rest-api.php',
            'admin/class-admin-manager.php',
            'frontend/class-frontend-manager.php'
        ];

        foreach ($core_files as $file) {
            $file_path = VSBBM_CORE_PATH . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }

        // راه‌اندازی core
        if (class_exists('VSBBM_Core')) {
            VSBBM_Core::instance()->init();
        }
    }

    /**
     * بارگذاری ماژول‌ها
     */
    private function load_modules()
    {
        $module_dirs = [
            'free' => VSBBM_MODULES_PATH . 'free/',
            'pro' => VSBBM_MODULES_PATH . 'pro/',
            'platform' => VSBBM_MODULES_PATH . 'platform/'
        ];

        foreach ($module_dirs as $type => $path) {
            if (is_dir($path)) {
                $this->load_module_type($type, $path);
            }
        }
    }

    /**
     * بارگذاری ماژول‌های خاص
     */
    private function load_module_type($type, $path)
    {
        $module_files = glob($path . '*.php');

        foreach ($module_files as $file) {
            if (file_exists($file)) {
                $module_name = basename($file, '.php');
                $this->modules[$type][$module_name] = require_once $file;
            }
        }
    }

    /**
     * راه‌اندازی REST API
     */
    private function init_rest_api()
    {
        add_action('rest_api_init', function() {
            if (class_exists('VSBBM_REST_API')) {
                $api_manager = new VSBBM_REST_API();
                $api_manager->register_routes();
            }
        });
    }

    /**
     * راه‌اندازی frontend
     */
    private function init_frontend()
    {
        if (class_exists('VSBBM_Frontend_Manager')) {
            $frontend_manager = new VSBBM_Frontend_Manager();
            $frontend_manager->init();
        }
    }

    /**
     * راه‌اندازی admin
     */
    private function init_admin()
    {
        if (class_exists('VSBBM_Admin_Manager')) {
            $admin_manager = new VSBBM_Admin_Manager();
            $admin_manager->init();
        }
    }

    /**
     * ثبت رویداد در لاگ
     */
    public function log_event($event_type, $message)
    {
        global $wpdb;
        
        $table_logs = $wpdb->prefix . 'vsbbm_logs';
        
        $log_data = [
            'event_type' => $event_type,
            'event_message' => $message,
            'user_id' => get_current_user_id(),
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => current_time('mysql')
        ];

        $wpdb->insert($table_logs, $log_data);
    }

    /**
     * دریافت IP کاربر
     */
    private function get_client_ip()
    {
        $ip_keys = [
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * دریافت مدیریت لایسنس
     */
    public function get_license_manager()
    {
        if (class_exists('VSBBM_License_Manager')) {
            return VSBBM_License_Manager::instance();
        }
        return null;
    }

    /**
     * دریافت ماژول‌ها
     */
    public function get_modules($type = null)
    {
        if ($type) {
            return $this->modules[$type] ?? [];
        }
        return $this->modules;
    }

    /**
     * بررسی فعال بودن ماژول
     */
    public function is_module_active($module_name, $type = 'free')
    {
        return isset($this->modules[$type][$module_name]);
    }

    /**
     * دریافت نسخه پلاگین
     */
    public function get_version()
    {
        return VSBBM_VERSION;
    }
}

/**
 * 📦 تابع اصلی برای دسترسی به پلاگین
 */
function VS_Bus_Booking_Manager() {
    return VS_Bus_Booking_Manager::instance();
}

// راه‌اندازی پلاگین
add_action('plugins_loaded', 'VS_Bus_Booking_Manager');

/**
 * 🔧 هوک فعال‌سازی شبکه (برای Multisite)
 */
register_activation_hook(__FILE__, function($network_wide) {
    if ($network_wide && is_multisite()) {
        foreach (get_sites() as $site) {
            switch_to_blog($site->blog_id);
            VS_Bus_Booking_Manager()->activate();
            restore_current_blog();
        }
    } else {
        VS_Bus_Booking_Manager()->activate();
    }
});

/**
 * 📝 هوک حذف پلاگین
 */
register_uninstall_hook(__FILE__, 'vsbbm_uninstall');

function vsbbm_uninstall() {
    // این تابع در فاز بعدی کامل خواهد شد
    // امکان حذف داده‌ها با تأیید کاربر
}