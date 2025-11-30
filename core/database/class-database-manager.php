<?php
/**
 * 🗃️ مدیریت دیتابیس و عملیات CRUD
 */

if (!defined('ABSPATH')) {
    exit;
}

class VSBBM_Database_Manager {
    
    private static $instance = null;
    private $wpdb;
    private $table_prefix;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_prefix = $wpdb->prefix . 'vsbbm_';
    }
    
    public function check_tables() {
        $tables = ['buses', 'bookings', 'licenses', 'logs'];
        $missing_tables = [];
        
        foreach ($tables as $table) {
            $table_name = $this->table_prefix . $table;
            if ($this->wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                $missing_tables[] = $table;
            }
        }
        
        return empty($missing_tables);
    }
    
    // مدیریت اتوبوس‌ها
    public function get_buses($args = []) {
        $defaults = [
            'number' => 20,
            'offset' => 0,
            'status' => 'active',
            'orderby' => 'id',
            'order' => 'DESC'
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $where = "WHERE status = '" . esc_sql($args['status']) . "'";
        
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_prefix}buses 
                 {$where} 
                 ORDER BY {$args['orderby']} {$args['order']} 
                 LIMIT %d, %d",
                $args['offset'], $args['number']
            )
        );
    }
    
    public function insert_bus($data) {
        $defaults = [
            'bus_number' => '',
            'bus_name' => '',
            'total_seats' => 40,
            'amenities' => '',
            'status' => 'active',
            'created_by' => get_current_user_id()
        ];
        
        $data = wp_parse_args($data, $defaults);
        $data = $this->sanitize_data($data);
        
        $result = $this->wpdb->insert(
            $this->table_prefix . 'buses',
            $data,
            ['%s', '%s', '%d', '%s', '%s', '%d']
        );
        
        if ($result) {
            VS_Bus_Booking_Manager()->log_event('bus_created', 'اتوبوس جدید ایجاد شد: ' . $data['bus_number']);
        }
        
        return $result ? $this->wpdb->insert_id : false;
    }
    
    // مدیریت رزروها
    public function create_booking($data) {
        $defaults = [
            'booking_number' => $this->generate_booking_number(),
            'bus_id' => 0,
            'customer_name' => '',
            'customer_email' => '',
            'customer_phone' => '',
            'seat_numbers' => '',
            'total_seats' => 1,
            'journey_date' => '',
            'journey_time' => '',
            'total_amount' => 0,
            'status' => 'pending',
            'payment_status' => 'pending'
        ];
        
        $data = wp_parse_args($data, $defaults);
        $data = $this->sanitize_data($data);
        
        // بررسی موجودیت صندلی‌ها
        if (!$this->check_seat_availability($data['bus_id'], $data['journey_date'], $data['seat_numbers'])) {
            return new WP_Error('seats_not_available', 'صندلی‌های انتخاب شده موجود نیستند');
        }
        
        $result = $this->wpdb->insert(
            $this->table_prefix . 'bookings',
            $data,
            ['%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%f', '%s', '%s']
        );
        
        if ($result) {
            VS_Bus_Booking_Manager()->log_event('booking_created', 'رزرو جدید ایجاد شد: ' . $data['booking_number']);
        }
        
        return $result ? $this->wpdb->insert_id : false;
    }
    
    private function generate_booking_number() {
        return 'VS' . date('Ymd') . strtoupper(wp_generate_password(6, false));
    }
    
    private function check_seat_availability($bus_id, $journey_date, $seat_numbers) {
        $booked_seats = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT GROUP_CONCAT(seat_numbers) FROM {$this->table_prefix}bookings 
                 WHERE bus_id = %d AND journey_date = %s AND status IN ('pending', 'confirmed')",
                $bus_id, $journey_date
            )
        );
        
        if (!$booked_seats) {
            return true;
        }
        
        $requested_seats = explode(',', $seat_numbers);
        $occupied_seats = explode(',', $booked_seats);
        
        return empty(array_intersect($requested_seats, $occupied_seats));
    }
    
    private function sanitize_data($data) {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = sanitize_text_field($value);
            } elseif (is_numeric($value)) {
                $data[$key] = intval($value);
            }
        }
        return $data;
    }
    
    public function cleanup_expired_bookings() {
        $expired_time = date('Y-m-d H:i:s', strtotime('-24 hours'));
        
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table_prefix}bookings 
                 SET status = 'cancelled' 
                 WHERE status = 'pending' AND created_at < %s",
                $expired_time
            )
        );
        
        if ($result) {
            VS_Bus_Booking_Manager()->log_event('bookings_cleaned', 'رزروهای منقضی پاکسازی شدند');
        }
    }
    
    public function backup_tables() {
        // پیاده‌سازی پشتیبان‌گیری اولیه
        VS_Bus_Booking_Manager()->log_event('backup_created', 'پشتیبان دیتابیس ایجاد شد');
    }
}