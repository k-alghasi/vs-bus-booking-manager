<?php
/**
 * Class VSBBM_Booking_Handler
 *
 * Handles WooCommerce cart integration, validation, and order metadata storage.
 *
 * @package VSBBM
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class VSBBM_Booking_Handler {

    /**
     * Initialize hooks.
     */
    public static function init() {
        // Validation
        add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_booking' ), 10, 3 );

        // Cart Item Data
        add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 10, 3 );
        add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_cart_item_data' ), 10, 2 );

        // Cart Item Quantity & Price
        add_filter( 'woocommerce_add_cart_item', array( __CLASS__, 'calculate_cart_item_price' ), 10, 2 );
        add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'get_cart_item_from_session' ), 10, 2 );
        add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'change_cart_item_quantity_display' ), 10, 3 );
        
        // Cart Price Display
        add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'display_cart_item_price' ), 10, 3 );
        add_filter( 'woocommerce_cart_item_subtotal', array( __CLASS__, 'display_cart_item_subtotal' ), 10, 3 );

        // Order Storage
        add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_order_item_meta' ), 10, 4 );

        // UI Tweaks
        add_filter( 'woocommerce_product_single_add_to_cart_text', array( __CLASS__, 'change_add_to_cart_text' ), 10, 2 );
        add_filter( 'woocommerce_is_sold_individually', array( __CLASS__, 'sold_individually' ), 10, 2 );
    }

    /**
     * Validate booking data before adding to cart.
     * Note: Most validation happens via AJAX in VSBBM_Seat_Manager, but this is a fallback.
     */
    public static function validate_booking( $passed, $product_id, $quantity ) {
        if ( ! VSBBM_Seat_Manager::is_seat_booking_enabled( $product_id ) ) {
            return $passed;
        }

        // If added via standard form POST (fallback mechanism)
        if ( isset( $_POST['vsbbm_passenger_data'] ) ) {
            $passenger_data = json_decode( wp_unslash( $_POST['vsbbm_passenger_data'] ), true );

            if ( ! is_array( $passenger_data ) || empty( $passenger_data ) ) {
                wc_add_notice( __( 'Invalid passenger data.', 'vs-bus-booking-manager' ), 'error' );
                return false;
            }

            // Basic required fields validation logic can be repeated here if needed for non-AJAX requests.
            // For now, we trust the AJAX handler or simple check.
            return true;
        }
        
        // If added via AJAX (standard flow), validation is handled there before WC cart add.
        return $passed;
    }

    /**
     * Add custom data to cart item.
     */
    public static function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
        if ( ! VSBBM_Seat_Manager::is_seat_booking_enabled( $product_id ) ) {
            return $cart_item_data;
        }

        // 1. Passengers Data
        if ( ! empty( $_POST['vsbbm_passenger_data'] ) ) {
            $cart_item_data['vsbbm_passengers'] = json_decode( wp_unslash( $_POST['vsbbm_passenger_data'] ), true );
        }

        // 2. Departure Date
        if ( ! empty( $_POST['vsbbm_departure_timestamp'] ) ) {
            $cart_item_data['vsbbm_departure_timestamp'] = sanitize_text_field( $_POST['vsbbm_departure_timestamp'] );
        }

        return $cart_item_data;
    }

    /**
     * Calculate price when item is added to cart.
     * We set quantity to 1 (representing one booking group) but price = unit_price * passengers.
     */
    public static function calculate_cart_item_price( $cart_item_data, $cart_item_key ) {
        if ( isset( $cart_item_data['vsbbm_passengers'] ) ) {
            $passenger_count = count( $cart_item_data['vsbbm_passengers'] );
            
            // Get original price (consider variations if applicable)
            $original_price = (float) $cart_item_data['data']->get_price();

            // Store original unit price for recalculations
            $cart_item_data['vsbbm_original_price']  = $original_price;
            $cart_item_data['vsbbm_passenger_count'] = $passenger_count;

            // Set new total price for this item
            $new_price = $original_price * $passenger_count;
            $cart_item_data['data']->set_price( $new_price );
        }
        return $cart_item_data;
    }

    /**
     * Restore custom price when loading cart from session.
     */
    public static function get_cart_item_from_session( $cart_item_data, $values ) {
        if ( isset( $values['vsbbm_passengers'] ) ) {
            $cart_item_data['vsbbm_passengers']        = $values['vsbbm_passengers'];
            $cart_item_data['vsbbm_passenger_count']   = count( $values['vsbbm_passengers'] );
            $cart_item_data['vsbbm_original_price']    = isset( $values['vsbbm_original_price'] ) ? (float) $values['vsbbm_original_price'] : (float) $cart_item_data['data']->get_price();
            
            if ( isset( $values['vsbbm_departure_timestamp'] ) ) {
                $cart_item_data['vsbbm_departure_timestamp'] = $values['vsbbm_departure_timestamp'];
            }

            // Recalculate price
            $new_price = $cart_item_data['vsbbm_original_price'] * $cart_item_data['vsbbm_passenger_count'];
            $cart_item_data['data']->set_price( $new_price );
        }
        return $cart_item_data;
    }

    /**
     * Display custom data in cart and checkout.
     */
    public static function display_cart_item_data( $item_data, $cart_item ) {
        if ( isset( $cart_item['vsbbm_passengers'] ) ) {
            foreach ( $cart_item['vsbbm_passengers'] as $index => $passenger ) {
                $display_parts = array();

                foreach ( $passenger as $key => $value ) {
                    if ( 'seat_number' !== $key && ! empty( $value ) ) {
                        // Translate keys roughly or display as is
                        $label = str_replace( '_', ' ', $key );
                        $display_parts[] = esc_html( $label . ': ' . $value );
                    }
                }

                if ( ! empty( $passenger['seat_number'] ) ) {
                    $display_parts[] = sprintf( __( 'Seat: %s', 'vs-bus-booking-manager' ), esc_html( $passenger['seat_number'] ) );
                }

                $item_data[] = array(
                    'name'  => sprintf( __( 'Passenger %d', 'vs-bus-booking-manager' ), $index + 1 ),
                    'value' => implode( ' | ', $display_parts ),
                );
            }
        }

        if ( ! empty( $cart_item['vsbbm_departure_timestamp'] ) ) {
            $timestamp = $cart_item['vsbbm_departure_timestamp'];
            try {
                // Use wp_date for better localization support in WP
                $formatted_date = wp_date( 'Y/m/d H:i', $timestamp );
            } catch ( Exception $e ) {
                $formatted_date = $timestamp;
            }

            $item_data[] = array(
                'name'  => __( 'Departure Date', 'vs-bus-booking-manager' ),
                'value' => $formatted_date,
            );
        }

        return $item_data;
    }

    /**
     * Save metadata to order items.
     */
    public static function save_order_item_meta( $item, $cart_item_key, $values, $order ) {
        if ( isset( $values['vsbbm_passengers'] ) ) {
            foreach ( $values['vsbbm_passengers'] as $index => $passenger ) {
                $meta_parts = array();

                foreach ( $passenger as $key => $value ) {
                    if ( 'seat_number' !== $key && ! empty( $value ) ) {
                        $label = str_replace( '_', ' ', $key );
                        $meta_parts[] = $label . ': ' . $value;
                    }
                }

                if ( ! empty( $passenger['seat_number'] ) ) {
                    $meta_parts[] = sprintf( __( 'Seat: %s', 'vs-bus-booking-manager' ), $passenger['seat_number'] );
                }

                $item->add_meta_data(
                    sprintf( __( 'Passenger %d', 'vs-bus-booking-manager' ), $index + 1 ),
                    implode( ' | ', $meta_parts )
                );
            }
        }

        if ( ! empty( $values['vsbbm_departure_timestamp'] ) ) {
            $timestamp = $values['vsbbm_departure_timestamp'];
            
            // Private meta for logic
            $item->add_meta_data( '_vsbbm_departure_timestamp', $timestamp );

            // Public meta for display
            $display_date = wp_date( 'Y/m/d H:i', $timestamp );
            $item->add_meta_data( __( 'Departure Date', 'vs-bus-booking-manager' ), $display_date );
        }
    }

    /**
     * Force product to be sold individually if seat booking is enabled.
     * Because we handle quantity internally via passenger count within one item.
     */
    public static function sold_individually( $return, $product ) {
        if ( VSBBM_Seat_Manager::is_seat_booking_enabled( $product->get_id() ) ) {
            return true;
        }
        return $return;
    }

    /**
     * Change Add to Cart button text.
     */
    public static function change_add_to_cart_text( $text, $product ) {
        if ( VSBBM_Seat_Manager::is_seat_booking_enabled( $product->get_id() ) ) {
            return __( 'Book Seat', 'vs-bus-booking-manager' );
        }
        return $text;
    }

    /**
     * Display passenger count instead of quantity in cart.
     */
    public static function change_cart_item_quantity_display( $product_quantity, $cart_item_key, $cart_item ) {
        if ( isset( $cart_item['vsbbm_passengers'] ) ) {
            $count = count( $cart_item['vsbbm_passengers'] );
            return sprintf( '<span class="vsbbm-passenger-count">%d %s</span>', $count, __( 'Passenger(s)', 'vs-bus-booking-manager' ) );
        }
        return $product_quantity;
    }

    /**
     * Display correct calculated price in cart table.
     */
    public static function display_cart_item_price( $price, $cart_item, $cart_item_key ) {
        if ( isset( $cart_item['vsbbm_passengers'] ) ) {
            $count          = count( $cart_item['vsbbm_passengers'] );
            $original_price = isset( $cart_item['vsbbm_original_price'] ) ? $cart_item['vsbbm_original_price'] : 0;

            if ( $original_price > 0 ) {
                $total_price_html = wc_price( $original_price * $count );
                $unit_price_html  = wc_price( $original_price );

                return sprintf( '%s <small class="vsbbm-price-breakdown">(%s × %d)</small>', $total_price_html, $unit_price_html, $count );
            }
        }
        return $price;
    }

    /**
     * Display correct subtotal in cart table.
     */
    public static function display_cart_item_subtotal( $subtotal, $cart_item, $cart_item_key ) {
        if ( isset( $cart_item['vsbbm_passengers'] ) ) {
            $count          = count( $cart_item['vsbbm_passengers'] );
            $original_price = isset( $cart_item['vsbbm_original_price'] ) ? $cart_item['vsbbm_original_price'] : 0;
            
            return wc_price( $original_price * $count );
        }
        return $subtotal;
    }
}

// Initialize the handler
VSBBM_Booking_Handler::init();