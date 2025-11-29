<?php
/**
 * Class VSBBM_Ticket_Manager
 *
 * Manages electronic tickets, QR codes, PDF generation, and Validation/Scanning.
 * Fixed: Missing methods and class structure.
 *
 * @package VSBBM
 * @since   2.0.3
 */

defined( 'ABSPATH' ) || exit;

class VSBBM_Ticket_Manager {

    private static $instance = null;
    private static $table_name = 'vsbbm_tickets';

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
        register_activation_hook( VSBBM_PLUGIN_PATH . 'vs-bus-booking-manager.php', array( $this, 'create_table' ) );

        add_action( 'woocommerce_order_status_completed', array( $this, 'generate_tickets_for_order' ), 10, 1 );
        add_action( 'woocommerce_order_status_processing', array( $this, 'generate_tickets_for_order' ), 10, 1 );

        add_action( 'init', array( $this, 'register_endpoints' ) );
        add_action( 'woocommerce_account_ticket-download_endpoint', array( $this, 'handle_ticket_download' ) );

        add_filter( 'woocommerce_account_menu_items', array( $this, 'add_ticket_menu_to_account' ) );
        add_action( 'woocommerce_account_tickets_endpoint', array( $this, 'display_tickets_in_account' ) );
        
        add_action( 'woocommerce_order_details_after_order_table', array( $this, 'display_tickets_on_order_page' ), 10, 1 );

        // Scanner AJAX Hooks
        add_action( 'wp_ajax_vsbbm_validate_ticket', array( $this, 'ajax_validate_ticket' ) );
        add_action( 'wp_ajax_vsbbm_checkin_ticket', array( $this, 'ajax_checkin_ticket' ) );
    }

    public static function create_table() {
        global $wpdb;
        $table_name      = $wpdb->prefix . self::$table_name;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) NOT NULL,
            ticket_number VARCHAR(50) NOT NULL UNIQUE,
            departure_timestamp BIGINT(20) NOT NULL DEFAULT 0,
            qr_code_data TEXT,
            pdf_path VARCHAR(255),
            passenger_data LONGTEXT,
            status ENUM('active', 'used', 'cancelled', 'expired') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            used_at TIMESTAMP NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY ticket_number (ticket_number),
            KEY status (status)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function register_endpoints() {
        add_rewrite_endpoint( 'ticket-download', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'tickets', EP_ROOT | EP_PAGES );
    }

    /**
     * Get tickets for a specific order.
     */
    public static function get_tickets_for_order( $order_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        
        return $wpdb->get_results( $wpdb->prepare( 
            "SELECT * FROM $table_name WHERE order_id = %d AND status = 'active'", 
            $order_id 
        ));
    }

    /* ==========================================================================
       TICKET GENERATION logic (generate_tickets_for_order, etc.)
       ========================================================================== */
    
    public function generate_tickets_for_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        if ( $this->tickets_exist_for_order( $order_id ) ) return;

        foreach ( $order->get_items() as $item ) {
            if ( VSBBM_Seat_Manager::is_seat_booking_enabled( $item->get_product_id() ) ) {
                $this->process_order_item_tickets( $order, $item );
            }
        }
    }

    private function tickets_exist_for_order( $order_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        $count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_name WHERE order_id = %d", $order_id ) );
        return $count > 0;
    }

    private function process_order_item_tickets( $order, $item ) {
        $passengers = $item->get_meta( '_vsbbm_passengers' );
        if ( empty( $passengers ) ) $passengers = $item->get_meta( 'vsbbm_passengers' ); // Fallback

        if ( empty( $passengers ) || ! is_array( $passengers ) ) return;

        $departure_timestamp = $item->get_meta( '_vsbbm_departure_timestamp', true );

        foreach ( $passengers as $passenger_data ) {
            if ( $departure_timestamp ) {
                $passenger_data['_vsbbm_departure_timestamp'] = $departure_timestamp;
            }
            $this->generate_single_ticket( $order, $passenger_data, $departure_timestamp );
        }
    }

    private function generate_single_ticket( $order, $passenger_data, $departure_timestamp = 0 ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $ticket_number = $this->generate_ticket_number( $order->get_id() );
        
        $qr_data = wp_json_encode( array(
            'tn' => $ticket_number,
            'oid' => $order->get_id(),
            // Ensure national ID is captured correctly based on field names
            'nid' => $passenger_data['national_id'] ?? ($passenger_data['کد ملی'] ?? ''),
        ));

        $result = $wpdb->insert(
            $table_name,
            array(
                'order_id'            => $order->get_id(),
                'ticket_number'       => $ticket_number,
                'departure_timestamp' => $departure_timestamp,
                'qr_code_data'        => $qr_data,
                'passenger_data'      => wp_json_encode( $passenger_data ),
                'status'              => 'active',
            ),
            array( '%d', '%s', '%d', '%s', '%s', '%s' )
        );

        if ( $result ) {
            $ticket_id = $wpdb->insert_id;
            $this->generate_pdf_file( $ticket_id, $order, $passenger_data, $ticket_number );
        }
    }

    private function generate_ticket_number( $order_id ) {
        return strtoupper( uniqid( 'TK-' . $order_id . '-' ) );
    }

    private function generate_pdf_file( $ticket_id, $order, $passenger_data, $ticket_number ) {
        $tcpdf_path = VSBBM_PLUGIN_PATH . 'vendor/tecnickcom/tcpdf/tcpdf.php';

        if ( ! file_exists( $tcpdf_path ) ) {
            if ( ! class_exists( 'TCPDF' ) ) {
                error_log( 'VSBBM Error: TCPDF library not found.' );
                return false;
            }
        } else {
            if ( ! class_exists( 'TCPDF' ) ) {
                require_once $tcpdf_path;
            }
        }

        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'] . '/vsbbm-tickets';
        if ( ! file_exists( $base_dir ) ) {
            wp_mkdir_p( $base_dir );
        }

        if ( ob_get_length() ) ob_end_clean();

        $pdf = new TCPDF( PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false );
        $pdf->SetCreator( 'VS Bus Booking Manager' );
        $pdf->SetAuthor( get_bloginfo( 'name' ) );
        $pdf->SetTitle( 'Ticket ' . $ticket_number );
        $pdf->setPrintHeader( false );
        $pdf->setPrintFooter( false );
        $pdf->SetFont( 'dejavusans', '', 10 );
        $pdf->AddPage();
        $pdf->setRTL( true );

        $html = $this->get_ticket_html_for_pdf( $order, $passenger_data, $ticket_number );
        $pdf->writeHTML( $html, true, false, true, false, '' );

        $qr_content = json_encode(array('ticket' => $ticket_number));
        $pdf->write2DBarcode( $qr_content, 'QRCODE,H', 15, 20, 30, 30, array(
            'border' => 0, 
            'padding' => 0, 
            'fgcolor' => array(0,0,0), 
            'bgcolor' => false
        ), 'N');

        $file_name = 'ticket-' . $ticket_number . '.pdf';
        $file_path = $base_dir . '/' . $file_name;
        
        $pdf->Output( $file_path, 'F' );

        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        $relative_path = '/vsbbm-tickets/' . $file_name;
        
        $wpdb->update( $table_name, array( 'pdf_path' => $relative_path ), array( 'id' => $ticket_id ) );

        return $relative_path;
    }

    private function get_ticket_html_for_pdf( $order, $passenger_data, $ticket_number ) {
        $product_name = '';
        foreach ( $order->get_items() as $item ) {
            if ( VSBBM_Seat_Manager::is_seat_booking_enabled( $item->get_product_id() ) ) {
                $product_name = $item->get_name();
                break;
            }
        }

        $timestamp = $passenger_data['_vsbbm_departure_timestamp'] ?? 0;
        $date_display = $timestamp ? wp_date( 'Y/m/d', $timestamp ) : '-';
        $time_display = $timestamp ? wp_date( 'H:i', $timestamp ) : '-';
        $site_name = get_bloginfo('name');
        
        // Dynamic field extraction
        $passenger_name = '-';
        $national_id = '-';
        
        foreach ($passenger_data as $key => $value) {
            // Check for name variations
            if (stripos($key, 'name') !== false || stripos($key, 'نام') !== false) {
                $passenger_name = $value;
            }
            // Check for national ID variations
            if (stripos($key, 'national') !== false || stripos($key, 'ملی') !== false || stripos($key, 'nid') !== false) {
                $national_id = $value;
            }
        }

        $html = '
        <style>
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            td { padding: 10px; border-bottom: 1px solid #ddd; }
            .header { background-color: #eee; padding: 15px; text-align: center; font-size: 16pt; font-weight: bold; }
            .label { font-weight: bold; width: 40%; }
            .value { width: 60%; }
            .footer { text-align: center; font-size: 9pt; margin-top: 30px; color: #777; }
        </style>
        <div style="border: 2px solid #000; padding: 5px; border-radius: 5px;">
            <div class="header">بلیط الکترونیکی - ' . $site_name . '</div>
            <br><br><br>
            <table>
                <tr><td class="label">شماره بلیط:</td><td class="value"><strong>' . $ticket_number . '</strong></td></tr>
                <tr><td class="label">سرویس:</td><td class="value">' . $product_name . '</td></tr>
                <tr><td class="label">تاریخ حرکت:</td><td class="value">' . $date_display . ' ساعت ' . $time_display . '</td></tr>
                <tr><td class="label">نام مسافر:</td><td class="value">' . $passenger_name . '</td></tr>
                <tr><td class="label">شماره صندلی:</td><td class="value" style="font-size: 16pt; font-weight: bold;">' . ($passenger_data['seat_number'] ?? '-') . '</td></tr>
                <tr><td class="label">کد ملی:</td><td class="value">' . $national_id . '</td></tr>
            </table>
            <div class="footer">سفارش شماره: #' . $order->get_order_number() . ' | صادر شده توسط سیستم VSBBM</div>
        </div>';

        return $html;
    }

    /* ==========================================================================
       DISPLAY & DOWNLOAD HANDLERS
       ========================================================================== */

    public function handle_ticket_download() {
        global $wp_query;
        if ( ! isset( $wp_query->query_vars['ticket-download'] ) ) return;

        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
            exit;
        }

        $ticket_id = absint( $wp_query->query_vars['ticket-download'] );
        $this->download_ticket( $ticket_id );
        exit;
    }

    private function download_ticket( $ticket_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        $ticket = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $ticket_id ) );

        if ( ! $ticket ) wp_die( 'Ticket not found.' );

        $order = wc_get_order( $ticket->order_id );
        if ( ! $order || $order->get_customer_id() !== get_current_user_id() ) {
            wp_die( 'Access denied.' );
        }

        if ( ! empty( $ticket->pdf_path ) ) {
            $upload_dir = wp_upload_dir();
            $file_path  = $upload_dir['basedir'] . $ticket->pdf_path;
            
            if ( file_exists( $file_path ) ) {
                if ( ob_get_length() ) ob_end_clean();
                header( 'Content-Type: application/pdf' );
                header( 'Content-Disposition: attachment; filename="Ticket-' . $ticket->ticket_number . '.pdf"' );
                header( 'Content-Length: ' . filesize( $file_path ) );
                readfile( $file_path );
                exit;
            }
        }
        wp_die( 'PDF file missing.' );
    }

    public function add_ticket_menu_to_account( $items ) {
        $new_items = array();
        foreach ( $items as $key => $value ) {
            $new_items[ $key ] = $value;
            if ( 'dashboard' === $key ) {
                $new_items['tickets'] = __( 'My Tickets', 'vs-bus-booking-manager' );
            }
        }
        return $new_items;
    }

    public function display_tickets_in_account() {
        $user_id = get_current_user_id();
        $orders = wc_get_orders( array( 'customer' => $user_id, 'status' => array( 'completed', 'processing' ), 'limit' => -1, 'return' => 'ids' ) );

        if ( empty( $orders ) ) {
            echo '<div class="woocommerce-message woocommerce-info">' . esc_html__( 'No tickets found.', 'vs-bus-booking-manager' ) . '</div>';
            return;
        }

        foreach ( $orders as $order_id ) {
            if ( ! $this->tickets_exist_for_order( $order_id ) ) {
                $this->generate_tickets_for_order( $order_id );
            }
        }

        $this->render_tickets_table( $orders );
    }

    public function display_tickets_on_order_page( $order ) {
        if ( ! $order ) return;
        
        if ( ! $this->tickets_exist_for_order( $order->get_id() ) ) {
            $this->generate_tickets_for_order( $order->get_id() );
        }

        if ( $this->tickets_exist_for_order( $order->get_id() ) ) {
            echo '<h2 class="woocommerce-order-details__title">' . esc_html__( 'Electronic Tickets', 'vs-bus-booking-manager' ) . '</h2>';
            $this->render_tickets_table( array( $order->get_id() ) );
        }
    }

    private function render_tickets_table( $order_ids ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        
        if ( empty( $order_ids ) ) return;

        $ids_string = implode( ',', array_map( 'intval', $order_ids ) );
        $tickets = $wpdb->get_results( "SELECT * FROM $table_name WHERE order_id IN ($ids_string) AND status = 'active' ORDER BY created_at DESC" );

        if ( empty( $tickets ) ) {
            echo '<p>' . esc_html__( 'No tickets available.', 'vs-bus-booking-manager' ) . '</p>';
            return;
        }

        ?>
        <table class="woocommerce-orders-table shop_table shop_table_responsive my_account_orders">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Ticket No', 'vs-bus-booking-manager' ); ?></th>
                    <th><?php esc_html_e( 'Seat', 'vs-bus-booking-manager' ); ?></th>
                    <th><?php esc_html_e( 'Departure', 'vs-bus-booking-manager' ); ?></th>
                    <th><?php esc_html_e( 'Download', 'vs-bus-booking-manager' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $tickets as $ticket ) : 
                    $passenger = json_decode( $ticket->passenger_data, true );
                    $date_display = $ticket->departure_timestamp ? wp_date( 'Y/m/d H:i', $ticket->departure_timestamp ) : '-';
                ?>
                <tr class="woocommerce-orders-table__row">
                    <td data-title="<?php esc_attr_e( 'Ticket No', 'vs-bus-booking-manager' ); ?>">
                        <?php echo esc_html( $ticket->ticket_number ); ?>
                    </td>
                    <td data-title="<?php esc_attr_e( 'Seat', 'vs-bus-booking-manager' ); ?>">
                        <?php echo esc_html( $passenger['seat_number'] ?? '-' ); ?>
                    </td>
                    <td data-title="<?php esc_attr_e( 'Departure', 'vs-bus-booking-manager' ); ?>">
                        <?php echo esc_html( $date_display ); ?>
                    </td>
                    <td data-title="<?php esc_attr_e( 'Download', 'vs-bus-booking-manager' ); ?>">
                        <?php if ( ! empty( $ticket->pdf_path ) ) : ?>
                            <a href="<?php echo esc_url( wc_get_endpoint_url( 'ticket-download', $ticket->id, wc_get_page_permalink( 'myaccount' ) ) ); ?>" class="button download-ticket">
                                <?php esc_html_e( 'PDF', 'vs-bus-booking-manager' ); ?>
                            </a>
                        <?php else : ?>
                            <span class="status-processing"><?php esc_html_e( 'Processing', 'vs-bus-booking-manager' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /* ==========================================================================
       SCANNER & AJAX
       ========================================================================== */

    /**
     * AJAX: Validate Ticket (Read Only)
     */
    public function ajax_validate_ticket() {
        check_ajax_referer( 'vsbbm_scanner_nonce', 'nonce' );

        $code = sanitize_text_field( $_POST['ticket_code'] );
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $ticket = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE ticket_number = %s", $code ) );

        if ( ! $ticket ) {
            wp_send_json_error( array( 'message' => __( 'Ticket not found.', 'vs-bus-booking-manager' ) ) );
        }

        // Get info
        $order = wc_get_order( $ticket->order_id );
        $passenger = json_decode( $ticket->passenger_data, true );
        $date_display = $ticket->departure_timestamp ? wp_date( 'Y/m/d H:i', $ticket->departure_timestamp ) : '-';

        $is_valid = ( $ticket->status === 'active' );
        $status_text = $ticket->status;
        
        wp_send_json_success( array(
            'ticket_id' => $ticket->id,
            'ticket_no' => $ticket->ticket_number,
            'status'    => $status_text,
            'is_valid'  => $is_valid,
            // Try to find name, national id from fields
            'passenger' => $this->get_passenger_name_from_data($passenger),
            'seat'      => $passenger['seat_number'] ?? '-',
            'date'      => $date_display,
            'order_id'  => $ticket->order_id
        ));
    }

    /**
     * AJAX: Check-in Ticket (Mark as Used)
     */
    public function ajax_checkin_ticket() {
        check_ajax_referer( 'vsbbm_scanner_nonce', 'nonce' );
        
        $ticket_id = absint( $_POST['ticket_id'] );
        
        if ( self::use_ticket( $ticket_id ) ) {
            wp_send_json_success( array( 'message' => __( 'Ticket Checked-in Successfully!', 'vs-bus-booking-manager' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Error updating ticket status.', 'vs-bus-booking-manager' ) ) );
        }
    }

    /**
     * Mark ticket as used.
     */
    public static function use_ticket( $ticket_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        return $wpdb->update( 
            $table_name, 
            array( 'status' => 'used', 'used_at' => current_time( 'mysql' ) ), 
            array( 'id' => $ticket_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Helper to extract name from passenger array
     */
    private function get_passenger_name_from_data( $data ) {
        foreach ( $data as $key => $value ) {
            if ( stripos( $key, 'name' ) !== false || stripos( $key, 'نام' ) !== false ) {
                return $value;
            }
        }
        return '-';
    }
}

VSBBM_Ticket_Manager::get_instance();