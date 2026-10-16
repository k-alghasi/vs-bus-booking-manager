<?php
/**
 * Class VSBBM_Reservation_Service
 *
 * Central reservation service — single source of truth for booking logic.
 *
 * این کلاس تمام منطق رزرو را در یک نقطه متمرکز می‌کند. هر دو مسیر
 * (AJAX سایت و REST API موبایل) باید از این سرویس استفاده کنند تا:
 *   - منطق رزرو یکسان باشد
 *   - اعتبارسنجی یکسان باشد
 *   - Blacklist checking یکسان باشد
 *   - Rollback در صورت خطا یکسان باشد
 *   - در آینده افزودن قابلیت‌های جدید (مثل gender-based seating) در یک نقطه باشد
 *
 * @package VSBBM
 * @since   2.1.0
 */

defined( 'ABSPATH' ) || exit;

class VSBBM_Reservation_Service {

    /**
     * Singleton instance.
     *
     * @var VSBBM_Reservation_Service|null
     */
    private static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return VSBBM_Reservation_Service
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor (private for singleton).
     */
    private function __construct() {
        // No hooks — this is a pure service class, called explicitly.
    }

    /**
     * Create a booking (reserve seats).
     *
     * این متد اصلی سرویس است. تمام منطق رزرو را در یک نقطه متمرکز می‌کند:
     *   1. اعتبارسنجی ورودی‌ها
     *   2. بررسی blacklist
     *   3. رزرو هر صندلی (atomic با DB constraint)
     *   4. Rollback در صورت خطا
     *
     * @param array $args {
     *     @type int          $product_id          (required) WooCommerce product ID.
     *     @type int          $departure_timestamp (required) Unix timestamp of departure.
     *     @type array        $seats               (required) Array of seat numbers.
     *     @type array        $passengers          (required) Array of passenger data (each must have 'seat_number').
     *     @type int|null     $user_id             (optional) User ID. Default: current user.
     *     @type string|null  $source              (optional) 'ajax' or 'rest_api' for logging/debugging.
     * }
     * @return array|WP_Error {
     *     @type array $reservation_keys  Array of reservation IDs on success.
     *     @type int   $product_id        Product ID.
     *     @type int   $departure_timestamp Timestamp.
     *     @type array $passengers        Sanitized passenger data.
     * } WP_Error on failure.
     */
    public function create_booking( $args ) {
        // 1. Validate & sanitize input
        $validated = $this->validate_booking_input( $args );
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }

        $product_id          = $validated['product_id'];
        $departure_timestamp = $validated['departure_timestamp'];
        $seats               = $validated['seats'];
        $passengers          = $validated['passengers'];
        $user_id             = $validated['user_id'];
        $source              = $validated['source'];

        // 2. Check blacklist
        $blacklist_check = $this->check_blacklist( $passengers );
        if ( is_wp_error( $blacklist_check ) ) {
            return $blacklist_check;
        }

        // 3. Reserve each seat (atomic, with rollback on failure)
        $reservation_keys = array();
        foreach ( $seats as $seat_number ) {
            // Find matching passenger data for this seat
            $seat_info = $this->find_passenger_for_seat( $passengers, $seat_number );

            $res_id = VSBBM_Seat_Reservations::reserve_seat(
                $product_id,
                $seat_number,
                $departure_timestamp,
                null, // order_id — will be linked after checkout
                $user_id,
                $seat_info
            );

            if ( is_wp_error( $res_id ) ) {
                // Rollback: cancel all previously reserved seats in this booking
                if ( ! empty( $reservation_keys ) ) {
                    VSBBM_Seat_Reservations::cancel_reservations_by_keys( $reservation_keys );
                }
                return $res_id; // Pass through the WP_Error
            }

            $reservation_keys[] = $res_id;
        }

        // 4. Fire action for extensions (future Pro features can hook here)
        do_action( 'vsbbm_booking_created', $reservation_keys, $product_id, $departure_timestamp, $passengers, $source );

        return array(
            'reservation_keys'      => $reservation_keys,
            'product_id'            => $product_id,
            'departure_timestamp'   => $departure_timestamp,
            'passengers'            => $passengers,
            'seats'                 => $seats,
            'user_id'               => $user_id,
        );
    }

    /**
     * Cancel a booking (cancel all reservations in it).
     *
     * @param array $reservation_ids Array of reservation IDs.
     * @return bool True on success.
     */
    public function cancel_booking( $reservation_ids ) {
        if ( empty( $reservation_ids ) || ! is_array( $reservation_ids ) ) {
            return false;
        }

        VSBBM_Seat_Reservations::cancel_reservations_by_keys( $reservation_ids );

        do_action( 'vsbbm_booking_cancelled', $reservation_ids );

        return true;
    }

    /**
     * Validate and sanitize booking input.
     *
     * @param array $args Raw input arguments.
     * @return array|WP_Error Validated arguments or WP_Error.
     */
    private function validate_booking_input( $args ) {
        // Product ID
        $product_id = isset( $args['product_id'] ) ? absint( $args['product_id'] ) : 0;
        if ( ! $product_id ) {
            return new WP_Error(
                'invalid_product',
                __( 'Invalid product ID.', 'vs-bus-booking-manager' )
            );
        }

        // Product exists and is seat-booking enabled?
        if ( ! class_exists( 'VSBBM_Seat_Manager' ) || ! VSBBM_Seat_Manager::is_seat_booking_enabled( $product_id ) ) {
            return new WP_Error(
                'product_not_bookable',
                __( 'This product does not support seat booking.', 'vs-bus-booking-manager' )
            );
        }

        // Departure timestamp
        $departure_timestamp = isset( $args['departure_timestamp'] ) ? absint( $args['departure_timestamp'] ) : 0;
        if ( ! $departure_timestamp ) {
            return new WP_Error(
                'invalid_timestamp',
                __( 'Invalid departure timestamp.', 'vs-bus-booking-manager' )
            );
        }

        // Seats
        $seats = isset( $args['seats'] ) ? (array) $args['seats'] : array();
        if ( empty( $seats ) ) {
            return new WP_Error(
                'no_seats',
                __( 'No seats selected.', 'vs-bus-booking-manager' )
            );
        }

        // Sanitize seat numbers
        $seats = array_map( 'sanitize_text_field', $seats );

        // Limit max seats per booking (safety check)
        $max_seats = (int) apply_filters( 'vsbbm_max_seats_per_booking', 10 );
        if ( count( $seats ) > $max_seats ) {
            return new WP_Error(
                'too_many_seats',
                sprintf(
                    /* translators: %d: max seats */
                    __( 'Maximum %d seats per booking.', 'vs-bus-booking-manager' ),
                    $max_seats
                )
            );
        }

        // Passengers
        $passengers = isset( $args['passengers'] ) ? (array) $args['passengers'] : array();
        if ( empty( $passengers ) ) {
            return new WP_Error(
                'no_passengers',
                __( 'No passenger data provided.', 'vs-bus-booking-manager' )
            );
        }

        // Number of passengers must match number of seats
        if ( count( $passengers ) !== count( $seats ) ) {
            return new WP_Error(
                'passenger_seat_mismatch',
                sprintf(
                    /* translators: 1: passengers count, 2: seats count */
                    __( 'Passenger count (%1$d) does not match seat count (%2$d).', 'vs-bus-booking-manager' ),
                    count( $passengers ),
                    count( $seats )
                )
            );
        }

        // Each passenger must have a seat_number
        foreach ( $passengers as $index => $passenger ) {
            if ( empty( $passenger['seat_number'] ) ) {
                return new WP_Error(
                    'missing_seat_number',
                    sprintf(
                        /* translators: %d: passenger index */
                        __( 'Passenger %d is missing seat number.', 'vs-bus-booking-manager' ),
                        $index + 1
                    )
                );
            }
        }

        // User ID
        $user_id = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : get_current_user_id();

        // Source (for logging)
        $source = isset( $args['source'] ) ? sanitize_text_field( $args['source'] ) : 'unknown';

        return array(
            'product_id'          => $product_id,
            'departure_timestamp' => $departure_timestamp,
            'seats'               => $seats,
            'passengers'          => $passengers,
            'user_id'             => $user_id,
            'source'              => $source,
        );
    }

    /**
     * Check passengers against blacklist.
     *
     * @param array $passengers Passenger data.
     * @return true|WP_Error True if all clear, WP_Error if blacklisted.
     */
    private function check_blacklist( $passengers ) {
        if ( ! class_exists( 'VSBBM_Blacklist' ) ) {
            return true; // Blacklist not available, skip
        }

        $target_field = 'کد ملی';
        if ( class_exists( 'VSBBM_Settings' ) ) {
            $target_field = VSBBM_Settings::get_instance()->get_blacklist_target_field();
        } else {
            $target_field = get_option( 'vsbbm_blacklist_target_field', 'کد ملی' );
        }

        foreach ( $passengers as $passenger ) {
            $value = isset( $passenger[ $target_field ] ) ? $passenger[ $target_field ] : '';
            if ( ! empty( $value ) && VSBBM_Blacklist::is_blacklisted( $value ) ) {
                return new WP_Error(
                    'blacklisted',
                    __( 'Booking blocked by blacklist.', 'vs-bus-booking-manager' )
                );
            }
        }

        return true;
    }

    /**
     * Find passenger data for a specific seat.
     *
     * @param array  $passengers   Array of passenger data.
     * @param string $seat_number  Seat number to find.
     * @return array Passenger data (empty array if not found).
     */
    private function find_passenger_for_seat( $passengers, $seat_number ) {
        foreach ( $passengers as $passenger ) {
            if ( isset( $passenger['seat_number'] ) && (string) $passenger['seat_number'] === (string) $seat_number ) {
                return $passenger;
            }
        }
        return array();
    }

    /**
     * Get available seats for a product and departure time.
     *
     * Convenience method that combines seat layout + reservation status.
     *
     * @param int $product_id          Product ID.
     * @param int $departure_timestamp Departure timestamp.
     * @return array {
     *     @type string $number  Seat number.
     *     @type string $label   Seat label.
     *     @type string $status  'available' | 'reserved' | 'confirmed'.
     *     @type float  $price   Seat-specific price (0 = use product base price).
     * }
     */
    public function get_seat_availability( $product_id, $departure_timestamp ) {
        $all_seats      = VSBBM_Seat_Manager::get_seat_numbers( $product_id );
        $reserved_seats = VSBBM_Seat_Reservations::get_reserved_seats_by_product_and_time( $product_id, $departure_timestamp );

        $availability = array();
        foreach ( $all_seats as $seat_num => $seat_data ) {
            $status = 'available';
            if ( isset( $reserved_seats[ $seat_num ] ) ) {
                $status = $reserved_seats[ $seat_num ]; // 'reserved' or 'confirmed'
            }

            $availability[] = array(
                'number' => $seat_num,
                'label'  => isset( $seat_data['label'] ) ? $seat_data['label'] : $seat_num,
                'status' => $status,
                'price'  => isset( $seat_data['price'] ) ? (float) $seat_data['price'] : 0,
                'type'   => isset( $seat_data['type'] ) ? $seat_data['type'] : 'default',
            );
        }

        return $availability;
    }
}