<?php
/**
 * Class VSBBM_SMS_Notifications
 *
 * Handles SMS notifications via various Iranian gateways.
 * Refactored to use Transients for OTP and improved error handling.
 *
 * @package VSBBM
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class VSBBM_SMS_Notifications {

    /**
     * Singleton instance.
     *
     * @var VSBBM_SMS_Notifications|null
     */
    private static $instance = null;

    /**
     * Supported SMS Gateways.
     *
     * @var array
     */
    private static $supported_panels = array(
        'ippanel'     => 'IPPanel',
        'kavenegar'   => 'Kavenegar',
        'smsir'       => 'SMS.ir',
        'ghasedak'    => 'Ghasedak',
        'payamak'     => 'Payamak-panel',
        'farazsms'    => 'FarazSMS',
        'melipayamak' => 'MeliPayamak',
    );

    /**
     * Get the singleton instance.
     *
     * @return VSBBM_SMS_Notifications
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
        // Order Status Hooks
        add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_change' ), 15, 4 );

        // Custom Reservation Hooks
        add_action( 'vsbbm_reservation_confirmed', array( $this, 'send_booking_confirmation_sms' ), 10, 2 );
        add_action( 'vsbbm_reservation_cancelled', array( $this, 'send_booking_cancellation_sms' ), 10, 2 );
        add_action( 'vsbbm_ticket_used', array( $this, 'send_ticket_used_sms' ), 10, 2 );

        // OTP & Verification Hooks
        add_action( 'vsbbm_send_otp', array( $this, 'send_otp_sms' ), 10, 2 );
        add_action( 'vsbbm_verify_phone', array( $this, 'send_phone_verification_sms' ), 10, 1 );
    }

    /**
     * Handle order status changes.
     */
    public function handle_order_status_change( $order_id, $old_status, $new_status, $order ) {
        if ( ! $this->order_has_seat_booking( $order ) ) {
            return;
        }

        switch ( $new_status ) {
            case 'completed':
                $this->send_customer_booking_confirmation( $order );
                break;

            case 'cancelled':
            case 'refunded':
                $this->send_customer_booking_cancellation( $order );
                break;

            case 'processing':
                if ( $this->get_sms_setting( 'enable_customer_processing_sms' ) ) {
                    $this->send_customer_booking_confirmation( $order );
                }
                break;
        }
    }

    /**
     * Check if order has seat bookings.
     */
    private function order_has_seat_booking( $order ) {
        foreach ( $order->get_items() as $item ) {
            if ( VSBBM_Seat_Manager::is_seat_booking_enabled( $item->get_product_id() ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Send Booking Confirmation SMS.
     */
    public function send_customer_booking_confirmation( $order ) {
        if ( ! $this->get_sms_setting( 'enable_customer_confirmation_sms' ) ) {
            return;
        }

        $phone = $this->get_customer_phone( $order );
        if ( ! $phone ) {
            return;
        }

        $passengers   = $this->get_passengers_from_order( $order );
        $product_info = $this->get_product_info_from_order( $order );
        $message      = $this->get_customer_confirmation_sms_content( $order, $passengers, $product_info );

        $this->send_sms( $phone, $message, 'customer_confirmation' );
    }

    /**
     * Send Booking Cancellation SMS.
     */
    public function send_customer_booking_cancellation( $order ) {
        if ( ! $this->get_sms_setting( 'enable_customer_cancellation_sms' ) ) {
            return;
        }

        $phone = $this->get_customer_phone( $order );
        if ( ! $phone ) {
            return;
        }

        $message = $this->get_customer_cancellation_sms_content( $order );

        $this->send_sms( $phone, $message, 'customer_cancellation' );
    }

    /**
     * Send Ticket Used SMS.
     */
    public function send_ticket_used_sms( $ticket_id, $passenger_data ) {
        if ( ! $this->get_sms_setting( 'enable_ticket_used_sms' ) ) {
            return;
        }

        $phone = $passenger_data['phone'] ?? '';
        if ( ! $phone ) {
            return;
        }

        $message = $this->get_ticket_used_sms_content( $passenger_data );

        $this->send_sms( $phone, $message, 'ticket_used' );
    }

    /**
     * Send OTP SMS.
     */
    public function send_otp_sms( $phone, $otp_code ) {
        if ( ! $this->get_sms_setting( 'enable_otp_sms' ) ) {
            return false;
        }

        $expiry  = $this->get_sms_setting( 'otp_expiry_minutes', 5 );
        $message = sprintf(
            // Translators: 1: OTP Code, 2: Expiry in minutes
            __( "Your verification code: %s\nValid for %d minutes.", 'vs-bus-booking-manager' ),
            $otp_code,
            $expiry
        );

        // Allow overriding the template via settings
        $custom_template = $this->get_sms_setting( 'otp_sms_template' );
        if ( ! empty( $custom_template ) ) {
            $message = str_replace( array( '[OTP_CODE]', '[EXPIRY_MINUTES]' ), array( $otp_code, $expiry ), $custom_template );
        }

        return $this->send_sms( $phone, $message, 'otp' );
    }

    /**
     * Generate and Send OTP.
     */
    public function send_phone_verification_sms( $phone ) {
        $otp_code = $this->generate_otp_code();
        $this->store_otp_code( $phone, $otp_code );

        return $this->send_otp_sms( $phone, $otp_code );
    }

    /**
     * Generate numeric OTP code.
     */
    private function generate_otp_code( $length = 5 ) {
        $characters = '0123456789';
        $code       = '';
        for ( $i = 0; $i < $length; $i++ ) {
            $code .= $characters[ rand( 0, strlen( $characters ) - 1 ) ];
        }
        return $code;
    }

    /**
     * Store OTP code using Transients (Performance Update).
     *
     * @param string $phone Phone number.
     * @param string $code  OTP Code.
     */
    private function store_otp_code( $phone, $code ) {
        $normalized_phone = $this->normalize_phone_number( $phone );
        $expiry_minutes   = $this->get_sms_setting( 'otp_expiry_minutes', 5 );
        
        // Use transient instead of a single option array to avoid race conditions
        set_transient( 'vsbbm_otp_' . $normalized_phone, $code, $expiry_minutes * 60 );
    }

    /**
     * Verify OTP code.
     */
    public static function verify_otp_code( $phone, $code ) {
        $instance         = self::get_instance(); // Helper to access normalize
        $normalized_phone = $instance->normalize_phone_number( $phone );
        
        $cached_code = get_transient( 'vsbbm_otp_' . $normalized_phone );

        if ( $cached_code && (string) $cached_code === (string) $code ) {
            // Delete after successful use
            delete_transient( 'vsbbm_otp_' . $normalized_phone );
            return true;
        }

        return false;
    }

    /**
     * Cleanup expired OTPs.
     *
     * @deprecated Since we switched to Transients, WP handles cleanup automatically.
     */
    public function cleanup_expired_otps() {
        // No longer needed with Transients API.
    }

    /**
     * Main Send SMS Method.
     */
    private function send_sms( $phone, $message, $type = 'general' ) {
        $panel = $this->get_sms_setting( 'sms_panel' );
        if ( ! $panel || ! isset( self::$supported_panels[ $panel ] ) ) {
            error_log( "VSBBM SMS: Unsupported or missing panel: $panel" );
            return false;
        }

        $phone = $this->normalize_phone_number( $phone );

        try {
            $result = $this->send_via_panel( $panel, $phone, $message );

            if ( $result ) {
                do_action( 'vsbbm_sms_sent', $phone, $message, $type );
            } else {
                error_log( "VSBBM SMS failed: $type to $phone via $panel" );
                do_action( 'vsbbm_sms_failed', $phone, $message, $type );
            }

            return $result;
        } catch ( Exception $e ) {
            error_log( 'VSBBM SMS error: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Route to specific gateway.
     */
    private function send_via_panel( $panel, $phone, $message ) {
        $method = 'send_via_' . $panel;
        if ( method_exists( $this, $method ) ) {
            return $this->$method( $phone, $message );
        }
        return false;
    }

    /**
     * IPPanel Gateway.
     */
    private function send_via_ippanel( $phone, $message ) {
        $api_key    = $this->get_sms_setting( 'ippanel_api_key' );
        $originator = $this->get_sms_setting( 'ippanel_originator' );

        if ( ! $api_key || ! $originator ) {
            return false;
        }

        $url  = 'https://ippanel.com/api/select';
        $data = array(
            'op'    => 'send',
            'uname' => $api_key, // Some IPPanel versions use uname/pass, modern uses API key
            'pass'  => $this->get_sms_setting( 'ippanel_password' ),
            'from'  => $originator,
            'to'    => array( $phone ),
            'time'  => '',
            'text'  => $message,
        );

        $response = wp_remote_post( $url, array(
            'body'    => json_encode( $data ),
            'headers' => array( 'Content-Type' => 'application/json' ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'VSBBM IPPanel Error: ' . $response->get_error_message() );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        // IPPanel usually returns integer/string status, check docs for 'success' or code 0
        return ( isset( $body['status'] ) && 'success' === $body['status'] ) || ( isset( $body[0] ) && $body[0] > 0 );
    }

    /**
     * Kavenegar Gateway.
     */
    private function send_via_kavenegar( $phone, $message ) {
        $api_key = $this->get_sms_setting( 'kavenegar_api_key' );
        $sender  = $this->get_sms_setting( 'kavenegar_sender' );

        if ( ! $api_key || ! $sender ) {
            return false;
        }

        $url  = "https://api.kavenegar.com/v1/{$api_key}/sms/send.json";
        $data = array(
            'sender'   => $sender,
            'receptor' => $phone,
            'message'  => $message,
        );

        $response = wp_remote_post( $url, array(
            'body'    => $data,
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return isset( $body['return']['status'] ) && 200 == $body['return']['status'];
    }

    /**
     * SMS.ir Gateway.
     */
    private function send_via_smsir( $phone, $message ) {
        $api_key     = $this->get_sms_setting( 'smsir_api_key' );
        $line_number = $this->get_sms_setting( 'smsir_line_number' );

        if ( ! $api_key || ! $line_number ) {
            return false;
        }

        $url  = 'https://api.sms.ir/v1/send/bulk';
        $data = array(
            'lineNumber'  => $line_number,
            'messageText' => $message,
            'mobiles'     => array( $phone ),
        );

        $response = wp_remote_post( $url, array(
            'body'    => json_encode( $data ),
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key'    => $api_key,
            ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return isset( $body['status'] ) && 1 == $body['status'];
    }

    /**
     * Placeholders for other gateways (Implement in Phase 3).
     */
    private function send_via_ghasedak( $phone, $message ) { return false; }
    private function send_via_payamak( $phone, $message ) { return false; }
    private function send_via_farazsms( $phone, $message ) { return false; }
    private function send_via_melipayamak( $phone, $message ) { return false; }

    /**
     * Normalize phone number to 98 format.
     */
    private function normalize_phone_number( $phone ) {
        $phone = preg_replace( '/\D/', '', $phone ); // Keep only digits

        if ( '0' === substr( $phone, 0, 1 ) ) {
            $phone = '98' . substr( $phone, 1 );
        }

        if ( '98' !== substr( $phone, 0, 2 ) ) {
            $phone = '98' . $phone;
        }

        return $phone;
    }

    /**
     * Get SMS Setting Helper.
     */
    private function get_sms_setting( $setting_key, $default = null ) {
        $settings = get_option( 'vsbbm_sms_settings', array() );
        return isset( $settings[ $setting_key ] ) ? $settings[ $setting_key ] : $default;
    }

    /**
     * Get Customer Phone from Order.
     */
    private function get_customer_phone( $order ) {
        return $order->get_billing_phone();
    }

    /**
     * Content Generation Methods (Views).
     */
    private function get_customer_confirmation_sms_content( $order, $passengers, $product_info ) {
        $order_number = $order->get_id();
        $total        = wc_price( $order->get_total() ); // Use strip_tags if sending plain text SMS

        $message = sprintf(
            __( "Booking Confirmed\nOrder #%s\nTotal: %s\n", 'vs-bus-booking-manager' ),
            $order_number,
            html_entity_decode( strip_tags( $total ) )
        );

        if ( ! empty( $product_info ) ) {
            foreach ( $product_info as $product ) {
                $message .= sprintf(
                    __( "Service: %s\nSeats: %d\n", 'vs-bus-booking-manager' ),
                    $product['name'],
                    $product['quantity']
                );
            }
        }

        $message .= __( 'View tickets in your account.', 'vs-bus-booking-manager' );
        return $message;
    }

    private function get_customer_cancellation_sms_content( $order ) {
        return sprintf(
            __( "Booking Cancelled\nOrder #%s\nRefund processing initiated.", 'vs-bus-booking-manager' ),
            $order->get_id()
        );
    }

    private function get_ticket_used_sms_content( $passenger_data ) {
        $name = $passenger_data['name'] ?? '';
        $seat = $passenger_data['seat_number'] ?? '';

        return sprintf(
            __( "Ticket Used\nPassenger: %s\nSeat: %s\nHave a safe trip!", 'vs-bus-booking-manager' ),
            $name,
            $seat
        );
    }

    // --- Helpers from email class duplication ---
    private function get_passengers_from_order( $order ) {
        $passengers = array();
        foreach ( $order->get_items() as $item ) {
            foreach ( $item->get_meta_data() as $meta ) {
                if ( false !== strpos( $meta->key, 'Passenger' ) || false !== strpos( $meta->key, 'مسافر' ) ) {
                    $passengers[] = $meta->value;
                }
            }
        }
        return $passengers;
    }

    private function get_product_info_from_order( $order ) {
        $products = array();
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product && VSBBM_Seat_Manager::is_seat_booking_enabled( $product->get_id() ) ) {
                $products[] = array(
                    'name'     => $product->get_name(),
                    'quantity' => $item->get_quantity(),
                    'price'    => $item->get_total(),
                );
            }
        }
        return $products;
    }

    /**
     * Get Supported Panels (Public).
     */
    public static function get_supported_panels() {
        return self::$supported_panels;
    }

    /**
     * Test Connection.
     */
    public static function test_sms_connection( $panel ) {
        $instance   = self::get_instance();
        $test_phone = $instance->get_sms_setting( 'test_phone_number' );
        
        if ( ! $test_phone ) {
            return false;
        }

        return $instance->send_sms( $test_phone, __( 'SMS System Connection Test', 'vs-bus-booking-manager' ), 'test' );
    }
}

// Initialize
VSBBM_SMS_Notifications::get_instance();