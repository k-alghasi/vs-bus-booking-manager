<?php
defined('ABSPATH') || exit;

/**
 * Class VSBBM_Seat_Reservations
 * مدیریت رزرو واقعی صندلی‌ها
 */
class VSBBM_Seat_Reservations {

    private static $instance = null;
    private static $table_name = 'vsbbm_seat_reservations';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // ایجاد جدول دیتابیس هنگام فعال‌سازی پلاگین
        register_activation_hook(VSBBM_PLUGIN_PATH . 'vs-bus-booking-manager.php', array($this, 'create_table'));

        // پاکسازی رزروهای منقضی شده
        add_action('vsbbm_cleanup_expired_reservations', array($this, 'cleanup_expired_reservations'));

        // برنامه‌ریزی پاکسازی خودکار
        if (!wp_next_scheduled('vsbbm_cleanup_expired_reservations')) {
            wp_schedule_event(time(), 'hourly', 'vsbbm_cleanup_expired_reservations');
        }

        // مدیریت رزرو هنگام تغییر وضعیت سفارش
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 4);
    }

    /**
     * ایجاد جدول رزرو صندلی‌ها
     */
    public static function create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) NOT NULL,
            seat_number INT(11) NOT NULL,
            order_id BIGINT(20) DEFAULT NULL,
            user_id BIGINT(20) DEFAULT NULL,
            status ENUM('reserved', 'confirmed', 'cancelled', 'expired') DEFAULT 'reserved',
            reserved_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            passenger_data LONGTEXT,
            PRIMARY KEY (id),
            UNIQUE KEY unique_reservation (product_id, seat_number, order_id),
            KEY product_seat (product_id, seat_number),
            KEY product_status (product_id, status),
            KEY order_id (order_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY status_expires (status, expires_at),
            KEY expires_at (expires_at),
            KEY reserved_at (reserved_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        error_log('VSBBM: Seat reservations table created');
    }

    /**
     * پاک کردن کش رزروها برای محصول
     */
    private static function clear_reservation_cache($product_id) {
        $cache_key = 'vsbbm_reserved_seats_' . $product_id;
        delete_transient($cache_key);
    }

    /**
     * رزرو صندلی‌ها
     */
    public static function reserve_seats($product_id, $seats, $order_id = null, $user_id = null, $passenger_data = array()) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;
        $reserved_seats = array();
        $expiration_time = date('Y-m-d H:i:s', strtotime('+15 minutes')); // 15 دقیقه زمان رزرو

        foreach ($seats as $seat_number) {
            // بررسی موجود بودن صندلی
            if (!self::is_seat_available($product_id, $seat_number)) {
                // آزاد کردن صندلی‌های رزرو شده قبلی در صورت خطا
                self::cancel_reservation_by_order($order_id);
                return new WP_Error('seat_not_available', sprintf('صندلی %d در حال حاضر رزرو شده است', $seat_number));
            }

            $result = $wpdb->insert($table_name, array(
                'product_id' => $product_id,
                'seat_number' => $seat_number,
                'order_id' => $order_id,
                'user_id' => $user_id,
                'status' => 'reserved',
                'expires_at' => $expiration_time,
                'passenger_data' => !empty($passenger_data) ? wp_json_encode($passenger_data) : null
            ));

            if ($result) {
                $reserved_seats[] = $seat_number;
            } else {
                // آزاد کردن صندلی‌های رزرو شده قبلی در صورت خطا
                self::cancel_reservation_by_order($order_id);
                return new WP_Error('reservation_failed', 'خطا در رزرو صندلی');
            }
        }

        // پاک کردن کش بعد از رزرو موفق
        self::clear_reservation_cache($product_id);

        error_log('VSBBM: Seats reserved: ' . implode(', ', $reserved_seats) . ' for order: ' . $order_id);
        return $reserved_seats;
    }

    /**
     * تایید رزرو (تبدیل reserved به confirmed)
     */
    public static function confirm_reservation($order_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        $result = $wpdb->update(
            $table_name,
            array('status' => 'confirmed', 'expires_at' => null),
            array('order_id' => $order_id, 'status' => 'reserved')
        );

        if ($result) {
            // پاک کردن کش برای محصول مرتبط
            $product_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT product_id FROM $table_name WHERE order_id = %d",
                $order_id
            ));

            foreach ($product_ids as $product_id) {
                self::clear_reservation_cache($product_id);
            }

            error_log('VSBBM: Reservation confirmed for order: ' . $order_id);
        }

        return $result;
    }

    /**
     * کنسل کردن رزرو
     */
    public static function cancel_reservation($order_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        $result = $wpdb->update(
            $table_name,
            array('status' => 'cancelled'),
            array('order_id' => $order_id)
        );

        if ($result) {
            // پاک کردن کش برای محصول مرتبط
            $product_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT product_id FROM $table_name WHERE order_id = %d",
                $order_id
            ));

            foreach ($product_ids as $product_id) {
                self::clear_reservation_cache($product_id);
            }

            error_log('VSBBM: Reservation cancelled for order: ' . $order_id);
        }

        return $result;
    }

    /**
     * کنسل کردن رزرو بر اساس سفارش (برای پاکسازی در صورت خطا)
     */
    public static function cancel_reservation_by_order($order_id) {
        if (!$order_id) return;

        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $wpdb->delete($table_name, array('order_id' => $order_id, 'status' => 'reserved'));
    }

    /**
     * پاکسازی رزروهای منقضی شده
     */
    public static function cleanup_expired_reservations() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;
        $current_time = current_time('mysql');

        $expired = $wpdb->update(
            $table_name,
            array('status' => 'expired'),
            array(
                'status' => 'reserved',
                'expires_at' => array('<', $current_time)
            )
        );

        if ($expired > 0) {
            error_log('VSBBM: Cleaned up ' . $expired . ' expired reservations');
        }

        return $expired;
    }

    /**
     * دریافت صندلی‌های رزرو شده برای محصول (با کش)
     */
    public static function get_reserved_seats($product_id) {
        $cache_key = 'vsbbm_reserved_seats_' . $product_id;
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $reserved_seats = $wpdb->get_col($wpdb->prepare(
            "SELECT seat_number FROM $table_name
             WHERE product_id = %d
             AND status IN ('reserved', 'confirmed')",
            $product_id
        ));

        $reserved_seats = array_map('intval', $reserved_seats);

        // کش برای ۵ دقیقه
        set_transient($cache_key, $reserved_seats, 300);

        return $reserved_seats;
    }

    /**
     * بررسی موجود بودن صندلی
     */
    public static function is_seat_available($product_id, $seat_number) {
        $reserved_seats = self::get_reserved_seats($product_id);
        return !in_array($seat_number, $reserved_seats);
    }

    /**
     * دریافت جزئیات رزرو برای سفارش
     */
    public static function get_reservation_details($order_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_id = %d",
            $order_id
        ));
    }

    /**
     * مدیریت تغییر وضعیت سفارش
     */
    public function handle_order_status_change($order_id, $old_status, $new_status, $order) {
        // بررسی اینکه آیا سفارش شامل رزرو صندلی است
        $has_seat_reservation = false;
        foreach ($order->get_items() as $item) {
            if (VSBBM_Seat_Manager::is_seat_booking_enabled($item->get_product_id())) {
                $has_seat_reservation = true;
                break;
            }
        }

        if (!$has_seat_reservation) return;

        switch ($new_status) {
            case 'completed':
            case 'processing':
                // تایید رزرو
                self::confirm_reservation($order_id);
                break;

            case 'cancelled':
            case 'refunded':
            case 'failed':
                // کنسل کردن رزرو
                self::cancel_reservation($order_id);
                break;
        }
    }

    /**
     * بروزرسانی order_id برای رزروها
     */
    public static function update_reservation_order_id($order_id, $passengers) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        foreach ($passengers as $passenger) {
            if (!empty($passenger['seat_number'])) {
                $seat_number = $passenger['seat_number'];

                // پیدا کردن رزرو بدون order_id برای این صندلی و کاربر
                $reservation_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table_name
                     WHERE seat_number = %d
                     AND order_id IS NULL
                     AND user_id = %d
                     AND status = 'reserved'
                     ORDER BY reserved_at DESC LIMIT 1",
                    $seat_number,
                    get_current_user_id()
                ));

                if ($reservation_id) {
                    $wpdb->update(
                        $table_name,
                        array('order_id' => $order_id),
                        array('id' => $reservation_id)
                    );

                    error_log('VSBBM: Updated reservation ' . $reservation_id . ' with order ID: ' . $order_id);
                }
            }
        }
    }

    /**
     * کنسل کردن رزرو بر اساس ID رزرو
     */
    public static function cancel_reservation_by_id($reservation_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        $result = $wpdb->update(
            $table_name,
            array('status' => 'cancelled'),
            array('id' => $reservation_id)
        );

        if ($result) {
            error_log('VSBBM: Reservation ' . $reservation_id . ' cancelled manually');
        }

        return $result;
    }

    /**
     * تایید رزرو بر اساس ID رزرو
     */
    public static function confirm_reservation_by_id($reservation_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        $result = $wpdb->update(
            $table_name,
            array('status' => 'confirmed', 'expires_at' => null),
            array('id' => $reservation_id)
        );

        if ($result) {
            error_log('VSBBM: Reservation ' . $reservation_id . ' confirmed manually');
        }

        return $result;
    }

    /**
     * دریافت جزئیات رزرو بر اساس ID
     */
    public static function get_reservation_details_by_id($reservation_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, p.post_title as product_name FROM $table_name r
             LEFT JOIN {$wpdb->posts} p ON r.product_id = p.ID
             WHERE r.id = %d",
            $reservation_id
        ));
    }

    /**
     * دریافت آمار رزروها
     */
    public static function get_reservation_stats($product_id = null) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;
        $where_clause = $product_id ? $wpdb->prepare('WHERE product_id = %d', $product_id) : '';

        $stats = $wpdb->get_row(
            "SELECT
                COUNT(CASE WHEN status = 'reserved' THEN 1 END) as reserved_count,
                COUNT(CASE WHEN status = 'confirmed' THEN 1 END) as confirmed_count,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_count,
                COUNT(CASE WHEN status = 'expired' THEN 1 END) as expired_count
             FROM $table_name $where_clause"
        );

        return $stats;
    }
}

// Initialize the class
VSBBM_Seat_Reservations::get_instance();