<?php
/**
 * Class VSBBM_Email_Notifications
 *
 * Handles sending email notifications for bookings.
 * Fixed: Syntax errors and duplicate methods.
 *
 * @package VSBBM
 * @since   2.0.2
 */

defined( 'ABSPATH' ) || exit;

class VSBBM_Email_Notifications {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_change' ), 20, 4 );
        add_action( 'vsbbm_reservation_confirmed', array( $this, 'send_booking_confirmation_email' ), 10, 2 );
        add_action( 'vsbbm_reservation_cancelled', array( $this, 'send_booking_cancellation_email' ), 10, 2 );
        add_action( 'vsbbm_new_reservation', array( $this, 'send_admin_new_booking_notification' ), 10, 2 );
        add_action( 'vsbbm_reservation_expired', array( $this, 'send_admin_expired_reservation_notification' ), 10, 1 );
    }

    public function handle_order_status_change( $order_id, $old_status, $new_status, $order ) {
        if ( ! $this->order_has_seat_booking( $order ) ) return;

        switch ( $new_status ) {
            case 'completed':
                $this->send_customer_booking_confirmation( $order );
                break;
            case 'cancelled':
            case 'refunded':
                $this->send_customer_booking_cancellation( $order );
                break;
            case 'processing':
                if ( $this->get_email_setting( 'enable_customer_processing_email' ) ) {
                    $this->send_customer_booking_confirmation( $order );
                }
                break;
        }
    }

    private function order_has_seat_booking( $order ) {
        foreach ( $order->get_items() as $item ) {
            if ( VSBBM_Seat_Manager::is_seat_booking_enabled( $item->get_product_id() ) ) return true;
        }
        return false;
    }

    public function send_customer_booking_confirmation( $order ) {
        if ( ! $this->get_email_setting( 'enable_customer_confirmation_email' ) ) return;

        $customer_email = $order->get_billing_email();
        $subject        = $this->get_email_subject( 'customer_confirmation' );
        $message        = $this->get_customer_confirmation_email_content( $order );
        $attachments    = $this->get_ticket_attachments( $order );

        $this->send_email( $customer_email, $subject, $message, 'customer_confirmation', $attachments );
    }

    public function send_customer_booking_cancellation( $order ) {
        if ( ! $this->get_email_setting( 'enable_customer_cancellation_email' ) ) return;

        $customer_email = $order->get_billing_email();
        $subject        = $this->get_email_subject( 'customer_cancellation' );
        $message        = $this->get_customer_cancellation_email_content( $order );

        $this->send_email( $customer_email, $subject, $message, 'customer_cancellation' );
    }

    public function send_admin_new_booking_notification( $order_id, $passengers ) {
        if ( ! $this->get_email_setting( 'enable_admin_new_booking_email' ) ) return;

        $admin_email = $this->get_admin_email();
        $subject     = $this->get_email_subject( 'admin_new_booking' );
        $message     = $this->get_admin_new_booking_email_content( $order_id, $passengers );

        $this->send_email( $admin_email, $subject, $message, 'admin_new_booking' );
    }

    public function send_admin_expired_reservation_notification( $reservation_id ) {
        if ( ! $this->get_email_setting( 'enable_admin_expired_reservation_email' ) ) return;

        $admin_email = $this->get_admin_email();
        $subject     = $this->get_email_subject( 'admin_expired_reservation' );
        $message     = $this->get_admin_expired_reservation_email_content( $reservation_id );

        $this->send_email( $admin_email, $subject, $message, 'admin_expired_reservation' );
    }

    private function get_email_setting( $setting_key ) {
        $defaults = array(
            'enable_customer_confirmation_email'     => true,
            'enable_customer_cancellation_email'     => true,
            'enable_customer_processing_email'       => false,
            'enable_admin_new_booking_email'         => true,
            'enable_admin_expired_reservation_email' => false,
            'admin_email'                            => get_option( 'admin_email' ),
            'from_name'                              => get_bloginfo( 'name' ),
            'from_email'                             => get_option( 'admin_email' ),
            'bcc_admin_on_customer_emails'           => false,
            'customer_confirmation_subject'          => '',
            'customer_cancellation_subject'          => '',
            'admin_new_booking_subject'              => '',
            'admin_expired_reservation_subject'      => '',
        );

        $settings = get_option( 'vsbbm_email_settings', array() );
        $settings = wp_parse_args( $settings, $defaults );

        return isset( $settings[ $setting_key ] ) ? $settings[ $setting_key ] : $defaults[ $setting_key ];
    }

    private function get_email_subject( $email_type ) {
        $subjects = array(
            'customer_confirmation'     => __( 'Booking Confirmation', 'vs-bus-booking-manager' ),
            'customer_cancellation'     => __( 'Booking Cancellation', 'vs-bus-booking-manager' ),
            'admin_new_booking'         => __( 'New Seat Booking', 'vs-bus-booking-manager' ),
            'admin_expired_reservation' => __( 'Expired Reservation', 'vs-bus-booking-manager' ),
        );

        $custom_subject = $this->get_email_setting( "{$email_type}_subject" );
        return $custom_subject ?: $subjects[ $email_type ];
    }

    private function get_admin_email() {
        return $this->get_email_setting( 'admin_email' );
    }

    private function send_email( $to, $subject, $message, $email_type, $attachments = array() ) {
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->get_email_setting( 'from_name' ) . ' <' . $this->get_email_setting( 'from_email' ) . '>',
        );

        if ( 'customer_confirmation' === $email_type && $this->get_email_setting( 'bcc_admin_on_customer_emails' ) ) {
            $headers[] = 'Bcc: ' . $this->get_admin_email();
        }

        wp_mail( $to, $subject, $message, $headers, $attachments );
    }

    private function get_ticket_attachments( $order ) {
        $attachments = array();

        if ( class_exists( 'VSBBM_Ticket_Manager' ) ) {
            $tickets = VSBBM_Ticket_Manager::get_tickets_for_order( $order->get_id() );

            foreach ( $tickets as $ticket ) {
                if ( $ticket->pdf_path ) {
                    $upload_dir = wp_upload_dir();
                    $file_path = $upload_dir['basedir'] . '/' . ltrim( $ticket->pdf_path, '/' );
                    
                    if ( file_exists( $file_path ) ) {
                        $attachments[] = $file_path;
                    }
                }
            }
        }

        return $attachments;
    }

    /**
     * Generate Customer Confirmation Content.
     */
    private function get_customer_confirmation_email_content( $order ) {
        $template_path = VSBBM_PLUGIN_PATH . 'templates/emails/customer-booking-confirmation.php';
        
        if ( file_exists( $template_path ) ) {
            $passengers   = $this->get_passengers_from_order( $order );
            $product_info = $this->get_product_info_from_order( $order );
            ob_start();
            include $template_path;
            return ob_get_clean();
        }
        
        return '<p>' . __( 'Thank you for your booking.', 'vs-bus-booking-manager' ) . '</p>';
    }

    /**
     * Generate Customer Cancellation Content.
     */
    private function get_customer_cancellation_email_content( $order ) {
        $template_path = VSBBM_PLUGIN_PATH . 'templates/emails/customer-booking-cancellation.php';

        if ( file_exists( $template_path ) ) {
            $passengers   = $this->get_passengers_from_order( $order );
            $product_info = $this->get_product_info_from_order( $order );
            ob_start();
            include $template_path;
            return ob_get_clean();
        }

        return '<p>' . __( 'Your booking has been cancelled.', 'vs-bus-booking-manager' ) . '</p>';
    }

    /**
     * Generate Admin New Booking Content.
     */
    private function get_admin_new_booking_email_content( $order_id, $passengers ) {
        $template_path = VSBBM_PLUGIN_PATH . 'templates/emails/admin-new-booking.php';

        if ( file_exists( $template_path ) ) {
            $order        = wc_get_order( $order_id );
            $product_info = $this->get_product_info_from_order( $order );
            ob_start();
            include $template_path;
            return ob_get_clean();
        }

        return '<p>' . sprintf( __( 'New booking received. Order ID: %d', 'vs-bus-booking-manager' ), $order_id ) . '</p>';
    }

    /**
     * Generate Admin Expired Reservation Content.
     */
    private function get_admin_expired_reservation_email_content( $reservation_id ) {
        $template_path = VSBBM_PLUGIN_PATH . 'templates/emails/admin-expired-reservation.php';

        if ( file_exists( $template_path ) ) {
            if ( class_exists( 'VSBBM_Seat_Reservations' ) ) {
                $reservation = VSBBM_Seat_Reservations::get_reservation_details_by_id( $reservation_id );
                if ( $reservation ) {
                    ob_start();
                    include $template_path;
                    return ob_get_clean();
                }
            }
        }

        return '<p>' . sprintf( __( 'Reservation #%d has expired.', 'vs-bus-booking-manager' ), $reservation_id ) . '</p>';
    }

    /**
     * Helper: Get Passengers.
     */
    private function get_passengers_from_order( $order ) {
        $passengers = array();
        foreach ( $order->get_items() as $item ) {
            foreach ( $item->get_meta_data() as $meta ) {
                if ( false !== strpos( $meta->key, 'مسافر' ) || false !== strpos( $meta->key, 'Passenger' ) ) {
                    $passengers[] = $meta->value;
                }
            }
        }
        return $passengers;
    }

    /**
     * Helper: Get Product Info.
     */
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
}

// Initialize
VSBBM_Email_Notifications::get_instance();