<?php
/**
 * Cache Manager برای VS Bus Booking Manager
 * مدیریت کش داده‌ها برای بهبود عملکرد
 *
 * @package VSBBM
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

class VSBBM_Cache_Manager {

    private static $instance = null;
    private $cache_prefix = 'vsbbm_';
    private $default_ttl = 300; // 5 minutes

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'init_hooks'));
    }

    public function init_hooks() {
        // پاک کردن کش هنگام بروزرسانی داده‌ها
        add_action('woocommerce_order_status_changed', array($this, 'clear_order_related_cache'), 10, 3);
        add_action('save_post_product', array($this, 'clear_product_cache_hook'), 10, 3);
        add_action('vsbbm_seat_reserved', array($this, 'clear_seat_cache'), 10, 2);
        add_action('vsbbm_seat_cancelled', array($this, 'clear_seat_cache'), 10, 2);
        add_action('vsbbm_ticket_used', array($this, 'clear_ticket_cache_hook'), 10, 1);
    }

    /**
     * دریافت داده از کش
     */
    public function get($key, $default = null) {
        $cache_key = $this->cache_prefix . $key;

        // اول چک کنیم object cache
        $cached = wp_cache_get($cache_key, 'vsbbm');
        if (false !== $cached) {
            return $cached;
        }

        // سپس transient
        $cached = get_transient($cache_key);
        if (false !== $cached) {
            // ذخیره در object cache برای دسترسی سریع‌تر
            wp_cache_set($cache_key, $cached, 'vsbbm', $this->default_ttl);
            return $cached;
        }

        return $default;
    }

    /**
     * ذخیره داده در کش
     */
    public function set($key, $value, $ttl = null) {
        if (null === $ttl) {
            $ttl = $this->default_ttl;
        }

        $cache_key = $this->cache_prefix . $key;

        // ذخیره در object cache
        wp_cache_set($cache_key, $value, 'vsbbm', $ttl);

        // ذخیره در transient برای ماندگاری بیشتر
        set_transient($cache_key, $value, $ttl);

        return true;
    }

    /**
     * حذف داده از کش
     */
    public function delete($key) {
        $cache_key = $this->cache_prefix . $key;

        wp_cache_delete($cache_key, 'vsbbm');
        delete_transient($cache_key);

        return true;
    }

    /**
     * پاک کردن گروهی کش
     */
    public function delete_group($group_pattern) {
        global $wpdb;

        // پاک کردن transientها
        $transient_pattern = '_transient_' . $this->cache_prefix . $group_pattern . '%';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $transient_pattern
        ));

        // پاک کردن object cache (تا جایی که ممکن است)
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('vsbbm');
        }

        return true;
    }

    /**
     * کش داده‌های محصول
     */
    public function get_product_data($product_id) {
        $key = 'product_' . $product_id;
        $cached = $this->get($key);

        if (false === $cached) {
            $product = wc_get_product($product_id);
            if ($product) {
                $cached = array(
                    'id' => $product->get_id(),
                    'name' => $product->get_name(),
                    'price' => $product->get_price(),
                    'regular_price' => $product->get_regular_price(),
                    'sale_price' => $product->get_sale_price(),
                    'status' => $product->get_status(),
                    'meta' => array(
                        '_vsbbm_enable_seat_booking' => get_post_meta($product_id, '_vsbbm_enable_seat_booking', true),
                        '_vsbbm_sale_start_date' => get_post_meta($product_id, '_vsbbm_sale_start_date', true),
                        '_vsbbm_sale_end_date' => get_post_meta($product_id, '_vsbbm_sale_end_date', true),
                        '_vsbbm_seat_numbers' => get_post_meta($product_id, '_vsbbm_seat_numbers', true),
                    )
                );
                $this->set($key, $cached, 600); // 10 minutes
            }
        }

        return $cached;
    }

    /**
     * کش صندلی‌های رزرو شده
     */
    public function get_reserved_seats($product_id) {
        $key = 'reserved_seats_' . $product_id;
        $cached = $this->get($key);

        if (false === $cached) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'vsbbm_seat_reservations';

            $reservations = $wpdb->get_results($wpdb->prepare(
                "SELECT seat_number FROM $table_name
                 WHERE product_id = %d AND status = 'active'",
                $product_id
            ));

            $cached = array_map(function($row) {
                return intval($row->seat_number);
            }, $reservations);

            $this->set($key, $cached, 300); // 5 minutes
        }

        return $cached;
    }

    /**
     * کش آمار رزروها
     */
    public function get_booking_stats() {
        $key = 'booking_stats';
        $cached = $this->get($key);

        if (false === $cached) {
            global $wpdb;

            // آمار کلی
            $total_orders = $wpdb->get_var("
                SELECT COUNT(*) FROM {$wpdb->posts}
                WHERE post_type = 'shop_order'
                AND post_status IN ('wc-completed', 'wc-processing')
            ");

            $total_revenue = $wpdb->get_var("
                SELECT SUM(meta_value) FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = '_order_total'
                AND p.post_type = 'shop_order'
                AND p.post_status IN ('wc-completed', 'wc-processing')
            ");

            // آمار ماه جاری
            $current_month = date('Y-m');
            $monthly_orders = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$wpdb->posts}
                WHERE post_type = 'shop_order'
                AND post_status IN ('wc-completed', 'wc-processing')
                AND DATE_FORMAT(post_date, '%%Y-%%m') = %s
            ", $current_month));

            $cached = array(
                'total_orders' => intval($total_orders),
                'total_revenue' => floatval($total_revenue),
                'monthly_orders' => intval($monthly_orders),
                'last_updated' => current_time('timestamp')
            );

            $this->set($key, $cached, 1800); // 30 minutes
        }

        return $cached;
    }

    /**
     * کش فیلدهای مسافر
     */
    public function get_passenger_fields() {
        $key = 'passenger_fields';
        $cached = $this->get($key);

        if (false === $cached) {
            $cached = get_option('vsbbm_passenger_fields', array(
                array('type' => 'text', 'label' => 'نام کامل', 'required' => true, 'placeholder' => 'نام و نام خانوادگی', 'locked' => false),
                array('type' => 'text', 'label' => 'کد ملی', 'required' => true, 'placeholder' => 'کد ملی ۱۰ رقمی', 'locked' => true),
                array('type' => 'tel', 'label' => 'شماره تماس', 'required' => true, 'placeholder' => '09xxxxxxxxx', 'locked' => false),
            ));

            $this->set($key, $cached, 3600); // 1 hour
        }

        return $cached;
    }

    /**
     * کش لیست محصولات فعال
     */
    public function get_active_products($force_refresh = false) {
        $key = 'active_products';
        $cached = $force_refresh ? false : $this->get($key);

        if (false === $cached) {
            $args = array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'meta_query' => array(
                    array(
                        'key' => '_vsbbm_enable_seat_booking',
                        'value' => 'yes',
                        'compare' => '='
                    )
                ),
                'fields' => 'ids'
            );

            $query = new WP_Query($args);
            $cached = $query->posts;

            $this->set($key, $cached, 600); // 10 minutes
        }

        return $cached;
    }

    /**
     * کش بلیط‌های یک سفارش
     */
    public function get_order_tickets($order_id) {
        $key = 'order_tickets_' . $order_id;
        $cached = $this->get($key);

        if (false === $cached) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'vsbbm_tickets';

            $cached = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name WHERE order_id = %d ORDER BY created_at ASC",
                $order_id
            ));

            $this->set($key, $cached, 1800); // 30 minutes
        }

        return $cached;
    }

    /**
     * پاک کردن کش مرتبط با سفارش
     */
    public function clear_order_related_cache($order_id, $old_status, $new_status) {
        $this->delete('booking_stats');
        $this->delete('order_tickets_' . $order_id);
        $this->delete_group('reserved_seats_');
        $this->delete_group('product_');
    }

    /**
     * پاک کردن کش محصول (hook callback)
     */
    public function clear_product_cache_hook($post_id, $post, $update) {
        if ($post->post_type === 'product') {
            $this->delete('product_' . $post_id);
            $this->delete('active_products');
            $this->delete('reserved_seats_' . $post_id);
        }
    }

    /**
     * پاک کردن کش صندلی‌ها
     */
    public function clear_seat_cache($product_id, $seat_number) {
        $this->delete('reserved_seats_' . $product_id);
    }

    /**
     * پاک کردن کش بلیط (hook callback)
     */
    public function clear_ticket_cache_hook($ticket_id) {
        $this->delete_group('order_tickets_');
    }

    /**
     * پاک کردن کش محصولات
     */
    public function clear_product_cache() {
        $this->delete_group('product_');
        $this->delete('active_products');
        update_option('vsbbm_last_cache_clear', current_time('mysql'));
    }

    /**
     * پاک کردن کش رزروها
     */
    public function clear_reservation_cache() {
        $this->delete_group('reserved_seats_');
        update_option('vsbbm_last_cache_clear', current_time('mysql'));
    }

    /**
     * پاک کردن کش بلیط‌ها
     */
    public function clear_ticket_cache() {
        $this->delete_group('order_tickets_');
        update_option('vsbbm_last_cache_clear', current_time('mysql'));
    }

    /**
     * پاک کردن کش آمار
     */
    public function clear_stats_cache() {
        $this->delete('booking_stats');
        update_option('vsbbm_last_cache_clear', current_time('mysql'));
    }

    /**
     * پاک کردن تمام کش‌ها
     */
    public function clear_all_cache() {
        $this->delete_group('');
        wp_cache_flush();
        update_option('vsbbm_last_cache_clear', current_time('mysql'));

        return array(
            'success' => true,
            'message' => 'تمام کش‌ها پاک شدند'
        );
    }

    /**
     * بروزرسانی تنظیمات کش
     */
    public function update_settings($settings) {
        if (isset($settings['cache_enabled'])) {
            update_option('vsbbm_cache_enabled', $settings['cache_enabled']);
        }
        if (isset($settings['cache_ttl'])) {
            $this->default_ttl = intval($settings['cache_ttl']);
            update_option('vsbbm_cache_ttl', $this->default_ttl);
        }
        if (isset($settings['cache_max_keys'])) {
            update_option('vsbbm_cache_max_keys', intval($settings['cache_max_keys']));
        }
    }

    /**
     * دریافت آمار کش
     */
    public function get_cache_stats() {
        global $wpdb;

        // تعداد transientها
        $transient_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_' . $this->cache_prefix . '%'
        ));

        // محاسبه نرخ موفقیت کش (تقریبی)
        $total_requests = get_option('vsbbm_cache_total_requests', 0);
        $cache_hits = get_option('vsbbm_cache_hits', 0);
        $hit_rate = $total_requests > 0 ? round(($cache_hits / $total_requests) * 100, 1) : 0;

        // محاسبه استفاده از حافظه (تقریبی)
        $memory_usage = $transient_count * 1024; // هر transient حدود 1KB

        // زمان فعالیت کش
        $uptime = get_option('vsbbm_cache_start_time', false);
        if ($uptime) {
            $uptime_hours = round((current_time('timestamp') - strtotime($uptime)) / 3600, 1);
        } else {
            $uptime_hours = 0;
            update_option('vsbbm_cache_start_time', current_time('mysql'));
        }

        return array(
            'total_keys' => intval($transient_count),
            'hit_rate' => $hit_rate,
            'memory_usage' => $memory_usage,
            'uptime' => $uptime_hours,
            'object_cache_enabled' => wp_using_ext_object_cache(),
            'last_cache_clear' => get_option('vsbbm_last_cache_clear', 'هرگز')
        );
    }

    /**
     * بهینه‌سازی کش برای عملیات bulk
     */
    public function begin_bulk_operation() {
        wp_suspend_cache_invalidation();
    }

    public function end_bulk_operation() {
        wp_suspend_cache_invalidation(false);
    }
}

// Initialize the cache manager
VSBBM_Cache_Manager::get_instance();