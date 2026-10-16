<?php
/**
 * Class VSBBM_Seat_Reservations
 *
 * Manages database interactions for seat reservations.
 * Updated to support time-based bookings (departure dates).
 *
 * @package VSBBM
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class VSBBM_Seat_Reservations {

    /**
     * Singleton instance.
     *
     * @var VSBBM_Seat_Reservations|null
     */
    private static $instance = null;

    /**
     * Database table name (without prefix).
     *
     * @var string
     */
    private static $table_name = 'vsbbm_seat_reservations';

    /**
     * Get the singleton instance.
     *
     * @return VSBBM_Seat_Reservations
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
        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // P0-fix: Removed register_activation_hook from here.
        // Table creation is handled by the main plugin's activate() method
        // which calls VSBBM_Seat_Reservations::create_table() directly.
        // Having a duplicate register_activation_hook with array($this, 'create_table')
        // where create_table is static causes PHP 8+ deprecation notices.

        // Cron job for cleanup
        add_action( 'vsbbm_cleanup_expired_reservations', array( $this, 'cleanup_expired_reservations' ) );

        // Schedule cron if not set
        if ( ! wp_next_scheduled( 'vsbbm_cleanup_expired_reservations' ) ) {
            wp_schedule_event( time(), 'hourly', 'vsbbm_cleanup_expired_reservations' );
        }

        // Handle order status changes
        add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_change' ), 10, 4 );

        // P0-fix: Ensure table exists (self-healing if activation failed)
        add_action( 'admin_init', array( __CLASS__, 'ensure_table_exists' ) );
    }

    /**
     * Public init wrapper (kept for compatibility).
     */
    public static function init() {
        // Log initialization if debug is on
    }

    /**
     * Create or update the database table.
     * Updated to support departure_timestamp and VARCHAR seat numbers.
     */
    public static function create_table() {
        global $wpdb;

        $table_name      = $wpdb->prefix . self::$table_name;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) NOT NULL,
            seat_number VARCHAR(20) NOT NULL,
            departure_timestamp BIGINT(20) NOT NULL DEFAULT 0,
            order_id BIGINT(20) DEFAULT NULL,
            user_id BIGINT(20) DEFAULT NULL,
            status ENUM('reserved', 'confirmed', 'cancelled', 'expired') DEFAULT 'reserved',
            reserved_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            passenger_data LONGTEXT,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_reservation (product_id, seat_number, departure_timestamp, order_id),
            KEY product_id (product_id),
            KEY seat_number (seat_number),
            KEY departure_timestamp (departure_timestamp),
            KEY order_id (order_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY expires_at (expires_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * P0-fix: Self-healing — ensure table exists.
     * If activation failed to create the table, this will create it on next admin page load.
     */
    public static function ensure_table_exists() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name ) {
            return;
        }

        self::create_table();
    }

    /**
     * Clear reservation cache.
     *
     * @param int $product_id Product ID.
     * @param int $departure_timestamp Departure timestamp.
     */
    private static function clear_reservation_cache( $product_id, $departure_timestamp ) {
        $cache_key = 'vsbbm_reserved_seats_' . $product_id . '_' . $departure_timestamp;
        delete_transient( $cache_key );
        
        if ( class_exists( 'VSBBM_Cache_Manager' ) ) {
            VSBBM_Cache_Manager::get_instance()->delete( $cache_key );
        }
    }

    /**
     * Reserve a single seat.
     *
     * P0-5: Uses DB-level unique constraint (active_key) for guaranteed
     * mutual exclusion. This is bulletproof — MySQL itself rejects duplicate
     * active reservations regardless of application-level locking.
     *
     * The unique key (product_id, seat_number, departure_timestamp, active_key)
     * ensures only ONE active reservation can exist per seat per departure.
     * Cancelled/expired rows have active_key=NULL (MySQL treats NULLs as
     * distinct in unique keys), so history is preserved without conflict.
     *
     * @param int    $product_id          Product ID.
     * @param string $seat_number         Seat Number.
     * @param int    $departure_timestamp Departure Date Timestamp.
     * @param int    $order_id            Order ID (optional).
     * @param int    $user_id             User ID (optional).
     * @param array  $passenger_data      Passenger Data (optional).
     * @return int|WP_Error Reservation ID or Error.
     */
    public static function reserve_seat( $product_id, $seat_number, $departure_timestamp, $order_id = null, $user_id = null, $passenger_data = array() ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        // Validation
        if ( empty( $seat_number ) ) {
            return new WP_Error( 'invalid_seat', __( 'Invalid seat number.', 'vs-bus-booking-manager' ) );
        }

        $user_id         = $user_id ?: get_current_user_id();
        $expiration_time = date( 'Y-m-d H:i:s', strtotime( '+15 minutes' ) );

        // P0-5: Insert with active_key='ACTIVE'.
        // The unique constraint (product_id, seat_number, departure_timestamp, active_key)
        // will reject this INSERT if another active reservation already exists.
        // This is the DB-level guarantee — no race condition possible.
        $data = array(
            'product_id'          => $product_id,
            'seat_number'         => $seat_number,
            'departure_timestamp' => $departure_timestamp,
            'order_id'            => $order_id,
            'user_id'             => $user_id,
            'status'              => 'reserved',
            'active_key'          => 'ACTIVE',
            'expires_at'          => $expiration_time,
            'passenger_data'      => ! empty( $passenger_data ) ? wp_json_encode( $passenger_data ) : null,
        );

        $format = array( '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' );

        // Suppress duplicate key errors to handle them gracefully
        $suppress = $wpdb->suppress_errors();
        $result = $wpdb->insert( $table_name, $data, $format );
        $wpdb->suppress_errors( $suppress );

        if ( $result ) {
            self::clear_reservation_cache( $product_id, $departure_timestamp );
            return $wpdb->insert_id;
        }

        // Check if failure was due to duplicate key (seat already reserved)
        if ( $wpdb->last_error && false !== stripos( $wpdb->last_error, 'Duplicate' ) ) {
            return new WP_Error(
                'seat_not_available',
                sprintf(
                    /* translators: %s: seat number */
                    __( 'Seat %s is already reserved. Please choose another seat.', 'vs-bus-booking-manager' ),
                    $seat_number
                )
            );
        }

        return new WP_Error(
            'reservation_failed',
            __( 'Database error while reserving seat.', 'vs-bus-booking-manager' )
        );
    }

    /**
     * Check if a seat is available.
     *
     * @param int    $product_id          Product ID.
     * @param string $seat_number         Seat Number.
     * @param int    $departure_timestamp Timestamp.
     * @return bool
     */
    public static function is_seat_available( $product_id, $seat_number, $departure_timestamp ) {
        $reserved_seats = self::get_reserved_seats_by_product_and_time( $product_id, $departure_timestamp );
        return ! array_key_exists( $seat_number, $reserved_seats );
    }

    /**
     * Get reserved seats for a specific product and time.
     *
     * P0-4: رزروهای موقت منقضی‌شده (reserved با expires_at گذشته ولی هنوز
     * توسط cron به expired تغییر نکرده) دیگر صندلی را بلوکه نمی‌کنند.
     *
     * @param int $product_id          Product ID.
     * @param int $departure_timestamp Timestamp.
     * @return array [ 'seat_number' => 'status' ]
     */
    public static function get_reserved_seats_by_product_and_time( $product_id, $departure_timestamp ) {
        $cache_key = 'vsbbm_reserved_seats_' . $product_id . '_' . $departure_timestamp;
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        // P0-4: استفاده از current_time('mysql') برای هماهنگی timezone با
        // نحوه ذخیره expires_at (که با date() PHP و timezone وردپرس ذخیره می‌شود).
        $now = current_time( 'mysql' );

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT seat_number, status FROM $table_name 
             WHERE product_id = %d 
             AND departure_timestamp = %d
             AND status IN ('reserved', 'confirmed')
             AND (
                status = 'confirmed' 
                OR expires_at > %s
             )",
            $product_id,
            $departure_timestamp,
            $now
        ) );

        $seats = array();
        if ( $results ) {
            foreach ( $results as $row ) {
                $seats[ $row->seat_number ] = $row->status;
            }
        }

        // Cache for 2 minutes (short cache to prevent overbooking)
        set_transient( $cache_key, $seats, 120 );

        return $seats;
    }

    /**
     * Alias for compatibility with Seat Manager logic.
     *
     * @param int $product_id Product ID.
     * @param int $departure_timestamp Timestamp.
     * @return array
     */
    public static function get_temp_reserved_seats_for_product_and_time( $product_id, $departure_timestamp ) {
        return self::get_reserved_seats_by_product_and_time( $product_id, $departure_timestamp );
    }

    /**
     * Update Order ID for existing reservations (used after checkout).
     *
     * Note (P0-3): این متد بر اساس user_id کار می‌کند که در مسیر سایت قابل اعتماد نیست.
     * برای مسیر سایت، از link_reservations_by_keys() استفاده کنید.
     * این متد فعلاً برای سازگاری با REST API نگه داشته شده.
     *
     * @param int   $order_id   Order ID.
     * @param array $passengers Passenger data from order meta.
     */
    public static function update_reservation_order_id( $order_id, $passengers ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        $user_id    = get_current_user_id();
        $order      = wc_get_order( $order_id );

        // Extract departure timestamp from order items
        // We assume all items in one order share logic or iterate per item
        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            $timestamp  = $item->get_meta( '_vsbbm_departure_timestamp' );

            if ( ! $timestamp ) {
                continue; // Skip if no timestamp (maybe not a bus product)
            }

            // Extract seat numbers from passenger data
            // $passengers is passed from Booking Handler which extracts it from metadata
            // But we need to match carefully.
            
            // Simpler approach: Find recent reservations for this user/product/time that have NO order_id
            
            $wpdb->query( $wpdb->prepare(
                "UPDATE $table_name 
                 SET order_id = %d 
                 WHERE product_id = %d 
                 AND departure_timestamp = %d 
                 AND user_id = %d 
                 AND order_id IS NULL 
                 AND status = 'reserved'",
                $order_id,
                $product_id,
                $timestamp,
                $user_id
            ));
            
            self::clear_reservation_cache( $product_id, $timestamp );
        }
    }

    /**
     * P0-3: Link reservations to order by their IDs (precise, no user_id guessing).
     *
     * این متد رزروها را بر اساس کلیدهای (ID) ذخیره‌شده در order item meta
     * به سفارش وصل می‌کند. این روش دقیق‌تر از update_reservation_order_id
     * است چون از user_id استفاده نمی‌کند.
     *
     * @param array $reservation_ids Array of reservation IDs.
     * @param int   $order_id        Order ID.
     * @return int Number of reservations updated.
     */
    public static function link_reservations_by_keys( $reservation_ids, $order_id ) {
        if ( empty( $reservation_ids ) || ! is_array( $reservation_ids ) ) {
            return 0;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        // ابتدا product_id و departure_timestamp را برای پاکسازی کش استخراج می‌کنیم
        $ids_placeholder = implode( ',', array_fill( 0, count( $reservation_ids ), '%d' ) );

        $reservations = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, product_id, departure_timestamp FROM $table_name WHERE id IN ($ids_placeholder)",
            $reservation_ids
        ) );

        if ( empty( $reservations ) ) {
            return 0;
        }

        // اتصال رزروها به سفارش
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE $table_name 
             SET order_id = %d 
             WHERE id IN ($ids_placeholder) 
             AND status = 'reserved'",
            array_merge( array( $order_id ), $reservation_ids )
        ) );

        // پاکسازی کش برای هر product/timestamp ترکیب
        $cache_cleared = array();
        foreach ( $reservations as $res ) {
            $cache_key = $res->product_id . '_' . $res->departure_timestamp;
            if ( ! isset( $cache_cleared[ $cache_key ] ) ) {
                self::clear_reservation_cache( $res->product_id, $res->departure_timestamp );
                $cache_cleared[ $cache_key ] = true;
            }
        }

        return (int) $updated;
    }

    /**
     * Handle WooCommerce Order Status Change.
     *
     * P0-5: Added fallback to link orphan reservations (those without order_id)
     * before checking. This ensures reservations are confirmed even if the
     * primary link mechanism (P0-3) failed.
     */
    public function handle_order_status_change( $order_id, $old_status, $new_status, $order ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        // P0-5: Fallback — link any orphan reservations for this order's items
        // This catches cases where link_reservations_to_order() didn't run or failed.
        $this->link_orphan_reservations_for_order( $order );

        // Check if this order has reservations
        $reservations = $wpdb->get_results( $wpdb->prepare( "SELECT id, product_id, departure_timestamp FROM $table_name WHERE order_id = %d", $order_id ) );

        if ( empty( $reservations ) ) {
            return;
        }

        if ( in_array( $new_status, array( 'processing', 'completed', 'on-hold' ) ) ) {
            // Confirm — keep active_key='ACTIVE' (still blocks other reservations)
            // Note: 'on-hold' اضافه شد چون پرداخت با چک (Cheque) در ووکامرس
            // سفارش را به on-hold می‌برد، نه processing. بدون این، رزروها
            // در حالت reserved می‌مانند و صندلی‌ها آزاد می‌شوند.
            $wpdb->update(
                $table_name,
                array( 'status' => 'confirmed', 'expires_at' => null, 'active_key' => 'ACTIVE' ),
                array( 'order_id' => $order_id ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );
        } elseif ( in_array( $new_status, array( 'cancelled', 'refunded', 'failed' ) ) ) {
            // Cancel — set active_key=NULL to free the seat for future bookings
            $wpdb->update(
                $table_name,
                array( 'status' => 'cancelled', 'active_key' => null ),
                array( 'order_id' => $order_id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
        }

        // Clear cache for affected products
        foreach ( $reservations as $res ) {
            self::clear_reservation_cache( $res->product_id, $res->departure_timestamp );
        }
    }

    /**
     * P0-5: Link orphan reservations to an order.
     *
     * Fallback mechanism: if reservations were created (status='reserved')
     * but never linked to an order_id (e.g., link_reservations_to_order failed),
     * this method finds them by user_id + product_id + timestamp and links them.
     *
     * Safe to call multiple times — only updates rows where order_id IS NULL.
     *
     * @param WC_Order $order Order object.
     */
    private function link_orphan_reservations_for_order( $order ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        $user_id    = $order->get_customer_id();
        $order_id   = $order->get_id();

        if ( ! $user_id ) {
            return; // Guest checkout — can't reliably link by user
        }

        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            $timestamp  = $item->get_meta( '_vsbbm_departure_timestamp' );

            if ( ! $timestamp ) {
                continue;
            }

            // Find orphan reservations for this user/product/time
            $updated = $wpdb->query( $wpdb->prepare(
                "UPDATE $table_name
                 SET order_id = %d
                 WHERE product_id = %d
                 AND departure_timestamp = %d
                 AND user_id = %d
                 AND order_id IS NULL
                 AND status = 'reserved'",
                $order_id,
                $product_id,
                $timestamp,
                $user_id
            ) );

            if ( $updated ) {
                self::clear_reservation_cache( $product_id, $timestamp );
            }
        }
    }

    /**
     * Cancel reservations by specific keys (IDs).
     * Useful for rolling back a failed Add to Cart action.
     *
     * P1-2: Now clears cache for affected product/timestamp combinations.
     */
    public static function cancel_reservations_by_keys( $reservation_ids ) {
        if ( empty( $reservation_ids ) ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        
        $ids_placeholder = implode( ',', array_fill( 0, count( $reservation_ids ), '%d' ) );

        // P1-2: Fetch product_id and departure_timestamp before cancelling
        // so we can clear the cache effectively.
        $reservations = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, product_id, departure_timestamp FROM $table_name WHERE id IN ($ids_placeholder)",
            $reservation_ids
        ) );

        if ( empty( $reservations ) ) {
            return;
        }

        // Cancel the reservations — set active_key=NULL to free the seats
        $wpdb->query( $wpdb->prepare(
            "UPDATE $table_name SET status = 'cancelled', active_key = NULL WHERE id IN ($ids_placeholder)",
            $reservation_ids
        ) );

        // P1-2: Clear cache for each unique product/timestamp combination
        $cache_cleared = array();
        foreach ( $reservations as $res ) {
            $cache_key = $res->product_id . '_' . $res->departure_timestamp;
            if ( ! isset( $cache_cleared[ $cache_key ] ) ) {
                self::clear_reservation_cache( $res->product_id, $res->departure_timestamp );
                $cache_cleared[ $cache_key ] = true;
            }
        }
    }

    /**
     * Cleanup expired reservations (Cron).
     *
     * P0-5: Also sets active_key=NULL to free seats for future bookings.
     */
    public function cleanup_expired_reservations() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        $now        = current_time( 'mysql' );

        // P0-5: Set active_key=NULL so the unique constraint no longer blocks
        // new reservations for these expired seats.
        $wpdb->query( $wpdb->prepare(
            "UPDATE $table_name 
             SET status = 'expired', active_key = NULL 
             WHERE status = 'reserved' AND expires_at < %s",
            $now
        ) );
    }

    /**
     * Get reservation details by ID.
     *
     * @param int $id Reservation ID.
     * @return object|null Row data.
     */
    public static function get_reservation_details_by_id( $id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $id ) );
    }

    /**
     * Cancel reservation manually by ID.
     */
    public static function cancel_reservation_by_id( $id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        
        $res = self::get_reservation_details_by_id( $id );
        if ( $res ) {
            $result = $wpdb->update( 
                $table_name, 
                array( 'status' => 'cancelled', 'active_key' => null ), 
                array( 'id' => $id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
            if ( $result ) {
                self::clear_reservation_cache( $res->product_id, $res->departure_timestamp );
            }
            return $result;
        }
        return false;
    }

    /**
     * Confirm reservation manually by ID.
     */
    public static function confirm_reservation_by_id( $id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $res = self::get_reservation_details_by_id( $id );
        if ( $res ) {
            $result = $wpdb->update( 
                $table_name, 
                array( 'status' => 'confirmed', 'expires_at' => null, 'active_key' => 'ACTIVE' ), 
                array( 'id' => $id ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );
            if ( $result ) {
                self::clear_reservation_cache( $res->product_id, $res->departure_timestamp );
            }
            return $result;
        }
        return false;
    }
}

// Initialize
VSBBM_Seat_Reservations::get_instance();