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
        // Create DB table on activation
        register_activation_hook( VSBBM_PLUGIN_PATH . 'vs-bus-booking-manager.php', array( $this, 'create_table' ) );

        // Cron job for cleanup
        add_action( 'vsbbm_cleanup_expired_reservations', array( $this, 'cleanup_expired_reservations' ) );

        // Schedule cron if not set
        if ( ! wp_next_scheduled( 'vsbbm_cleanup_expired_reservations' ) ) {
            wp_schedule_event( time(), 'hourly', 'vsbbm_cleanup_expired_reservations' );
        }

        // Handle order status changes
        add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_change' ), 10, 4 );
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

        // Check availability logic
        if ( ! self::is_seat_available( $product_id, $seat_number, $departure_timestamp ) ) {
            return new WP_Error( 'seat_not_available', sprintf( __( 'Seat %s is already reserved.', 'vs-bus-booking-manager' ), $seat_number ) );
        }

        $user_id         = $user_id ?: get_current_user_id();
        $expiration_time = date( 'Y-m-d H:i:s', strtotime( '+15 minutes' ) );

        $data = array(
            'product_id'          => $product_id,
            'seat_number'         => $seat_number,
            'departure_timestamp' => $departure_timestamp,
            'order_id'            => $order_id,
            'user_id'             => $user_id,
            'status'              => 'reserved',
            'expires_at'          => $expiration_time,
            'passenger_data'      => ! empty( $passenger_data ) ? wp_json_encode( $passenger_data ) : null,
        );

        $format = array( '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s' );

        $result = $wpdb->insert( $table_name, $data, $format );

        if ( $result ) {
            self::clear_reservation_cache( $product_id, $departure_timestamp );
            return $wpdb->insert_id;
        }

        return new WP_Error( 'reservation_failed', __( 'Database error while reserving seat.', 'vs-bus-booking-manager' ) );
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

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT seat_number, status FROM $table_name 
             WHERE product_id = %d 
             AND departure_timestamp = %d
             AND status IN ('reserved', 'confirmed')",
            $product_id,
            $departure_timestamp
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
     * Handle WooCommerce Order Status Change.
     */
    public function handle_order_status_change( $order_id, $old_status, $new_status, $order ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        // Check if this order has reservations
        $reservations = $wpdb->get_results( $wpdb->prepare( "SELECT id, product_id, departure_timestamp FROM $table_name WHERE order_id = %d", $order_id ) );

        if ( empty( $reservations ) ) {
            return;
        }

        if ( in_array( $new_status, array( 'processing', 'completed' ) ) ) {
            // Confirm
            $wpdb->update(
                $table_name,
                array( 'status' => 'confirmed', 'expires_at' => null ),
                array( 'order_id' => $order_id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
        } elseif ( in_array( $new_status, array( 'cancelled', 'refunded', 'failed' ) ) ) {
            // Cancel
            $wpdb->update(
                $table_name,
                array( 'status' => 'cancelled' ),
                array( 'order_id' => $order_id ),
                array( '%s' ),
                array( '%d' )
            );
        }

        // Clear cache for affected products
        foreach ( $reservations as $res ) {
            self::clear_reservation_cache( $res->product_id, $res->departure_timestamp );
        }
    }

    /**
     * Cancel reservations by specific keys (IDs).
     * Useful for rolling back a failed Add to Cart action.
     *
     * @param array $reservation_ids Array of IDs.
     */
    public static function cancel_reservations_by_keys( $reservation_ids ) {
        if ( empty( $reservation_ids ) ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        
        $ids_placeholder = implode( ',', array_fill( 0, count( $reservation_ids ), '%d' ) );

        // We need product_id and timestamp to clear cache effectively, but for cancellation just update DB
        // The cache TTL will handle the rest or manual clear if critical
        $wpdb->query( $wpdb->prepare(
            "UPDATE $table_name SET status = 'cancelled' WHERE id IN ($ids_placeholder)",
            $reservation_ids
        ) );
    }

    /**
     * Cleanup expired reservations (Cron).
     */
    public function cleanup_expired_reservations() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        $now        = current_time( 'mysql' );

        // Find expired to clear cache (optional, could be heavy if many expired)
        // Simple update:
        $wpdb->query( $wpdb->prepare(
            "UPDATE $table_name SET status = 'expired' 
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
            $result = $wpdb->update( $table_name, array( 'status' => 'cancelled' ), array( 'id' => $id ) );
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
            $result = $wpdb->update( $table_name, array( 'status' => 'confirmed', 'expires_at' => null ), array( 'id' => $id ) );
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