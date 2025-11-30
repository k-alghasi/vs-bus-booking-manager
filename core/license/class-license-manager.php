<?php
/**
 * 🔐 مدیریت لایسنس و فعال‌سازی
 */

if (!defined('ABSPATH')) {
    exit;
}

class VSBBM_License_Manager {
    
    private static $instance = null;
    private $license_data;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->load_license_data();
    }
    
    private function load_license_data() {
        global $wpdb;
        
        $this->license_data = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}vsbbm_licenses WHERE status = 'active' LIMIT 1"
        );
    }
    
    public function is_valid() {
        if (!$this->license_data) {
            return false;
        }
        
        if ($this->license_data->product_type === 'free') {
            return true;
        }
        
        if ($this->license_data->expires_at && strtotime($this->license_data->expires_at) < time()) {
            $this->deactivate_expired_license();
            return false;
        }
        
        return true;
    }
    
    public function validate_license() {
        // در فاز ۲ کامل می‌شود
        return true;
    }
    
    private function deactivate_expired_license() {
        global $wpdb;
        
        $wpdb->update(
            "{$wpdb->prefix}vsbbm_licenses",
            ['status' => 'expired'],
            ['id' => $this->license_data->id]
        );
        
        VS_Bus_Booking_Manager()->log_event('license_expired', 'لایسنس منقضی شد');
    }
    
    public function get_license_type() {
        return $this->license_data ? $this->license_data->product_type : 'free';
    }
    
    public function get_features() {
        $license_type = $this->get_license_type();
        
        $features = [
            'free' => [
                'max_buses' => 5,
                'max_bookings_per_day' => 50,
                'basic_analytics' => true,
                'email_notifications' => false,
                'sms_notifications' => false,
                'custom_templates' => false
            ],
            'pro' => [
                'max_buses' => 50,
                'max_bookings_per_day' => 1000,
                'basic_analytics' => true,
                'email_notifications' => true,
                'sms_notifications' => true,
                'custom_templates' => true
            ]
        ];
        
        return $features[$license_type] ?? $features['free'];
    }
}