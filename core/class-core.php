<?php
/**
 * 🏗️ هسته مرکزی سیستم VS Bus Booking Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class VSBBM_Core {
    
    private static $instance = null;
    private $database;
    private $license;
    private $api;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_components();
        $this->register_hooks();
    }
    
    private function init_components() {
        // بارگذاری کامپوننت‌های اصلی
        $this->database = VSBBM_Database_Manager::instance();
        $this->license = VSBBM_License_Manager::instance();
        $this->api = VSBBM_REST_API::instance();
    }
    
    private function register_hooks() {
        add_action('init', [$this, 'init']);
        add_filter('plugin_action_links_' . VSBBM_PLUGIN_BASENAME, [$this, 'add_plugin_links']);
    }
    
    public function init() {
        // راه‌اندازی نهایی
        $this->check_system_health();
        $this->setup_cron_jobs();
    }
    
    public function add_plugin_links($links) {
        $plugin_links = [
            '<a href="' . admin_url('admin.php?page=vsbbm-dashboard') . '">داشبورد</a>',
            '<a href="' . admin_url('admin.php?page=vsbbm-license') . '">لایسنس</a>'
        ];
        return array_merge($plugin_links, $links);
    }
    
    private function check_system_health() {
        // بررسی سلامت سیستم
        if (!$this->database->check_tables()) {
            $this->handle_system_error('database_tables_missing');
        }
        
        if (!$this->license->is_valid()) {
            $this->handle_system_error('license_invalid');
        }
    }
    
    private function setup_cron_jobs() {
        if (!wp_next_scheduled('vsbbm_daily_maintenance')) {
            wp_schedule_event(time(), 'daily', 'vsbbm_daily_maintenance');
        }
        
        add_action('vsbbm_daily_maintenance', [$this, 'run_daily_tasks']);
    }
    
    public function run_daily_tasks() {
        // پاک‌سازی رزروهای منقضی
        $this->database->cleanup_expired_bookings();
        
        // بررسی لایسنس
        $this->license->validate_license();
        
        // پشتیبان‌گیری
        $this->database->backup_tables();
    }
    
    private function handle_system_error($error_code) {
        $errors = [
            'database_tables_missing' => 'جداول دیتابیس وجود ندارد',
            'license_invalid' => 'لایسنس معتبر نیست'
        ];
        
        VS_Bus_Booking_Manager()->log_event('system_error', $errors[$error_code]);
    }
    
    public function get_database() {
        return $this->database;
    }
    
    public function get_license() {
        return $this->license;
    }
    
    public function get_api() {
        return $this->api;
    }
}