<?php
/**
 * Class VSBBM_Ticket_Manager
 *
 * Manages electronic tickets, QR codes, and PDF generation.
 * Updated to support departure dates.
 *
 * @package VSBBM
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class VSBBM_Ticket_Manager {

    /**
     * Singleton instance.
     */
    private static $instance = null;

    /**
     * Table name without prefix.
     */
    private static $table_name = 'vsbbm_tickets';

    /**
     * Get singleton instance.
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
        // DB Creation
        register_activation_hook( VSBBM_PLUGIN_PATH . 'vs-bus-booking-manager.php', array( $this, 'create_table' ) );

        // Ticket Generation
        add_action( 'woocommerce_order_status_completed', array( $this, 'generate_tickets_for_order' ), 10, 1 );
        add_action( 'woocommerce_order_status_processing', array( $this, 'generate_tickets_for_order' ), 10, 1 );

        // Download Endpoint
        add_action( 'init', array( $this, 'add_ticket_download_endpoint' ) );
        add_action( 'woocommerce_account_ticket-download_endpoint', array( $this, 'handle_ticket_download' ) );

        // My Account Integration
        add_filter( 'woocommerce_account_menu_items', array( $this, 'add_ticket_menu_to_account' ) );
        add_action( 'woocommerce_account_tickets_endpoint', array( $this, 'display_tickets_in_account' ) );
    }

    /**
     * Create Database Table.
     */
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
            qr_code_path VARCHAR(255),
            pdf_path VARCHAR(255),
            passenger_data LONGTEXT,
            status ENUM('active', 'used', 'cancelled', 'expired') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            used_at TIMESTAMP NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY ticket_number (ticket_number),
            KEY departure_timestamp (departure_timestamp),
            KEY status (status)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Generate tickets for a completed/processing order.
     */
    public function generate_tickets_for_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Check if tickets already exist to prevent duplicates
        if ( $this->tickets_exist_for_order( $order_id ) ) {
            return;
        }

        foreach ( $order->get_items() as $item ) {
            if ( VSBBM_Seat_Manager::is_seat_booking_enabled( $item->get_product_id() ) ) {
                $this->process_order_item_tickets( $order, $item );
            }
        }
    }

    /**
     * Helper: Check if tickets exist.
     */
    private function tickets_exist_for_order( $order_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        return $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_name WHERE order_id = %d", $order_id ) ) > 0;
    }

    /**
     * Process a single order item and generate tickets for passengers.
     */
    private function process_order_item_tickets( $order, $item ) {
        $passengers = $item->get_meta( 'vsbbm_passengers' ); // Should be saved as array in Booking Handler
        
        // Fallback for older data structure if saved differently
        if ( empty( $passengers ) ) {
            // Logic to extract from individual meta keys if needed
            // For now, assuming standard structure from Booking Handler
            return;
        }

        $departure_timestamp = $item->get_meta( '_vsbbm_departure_timestamp', true );

        foreach ( $passengers as $passenger_data ) {
            // Inject departure time into passenger data for internal use
            if ( $departure_timestamp ) {
                $passenger_data['_vsbbm_departure_timestamp'] = $departure_timestamp;
            }
            
            $this->generate_single_ticket( $order, $passenger_data, $departure_timestamp );
        }
    }

    /**
     * Generate a single ticket record.
     */
    private function generate_single_ticket( $order, $passenger_data, $departure_timestamp = 0 ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $ticket_number = $this->generate_ticket_number( $order->get_id() );
        $qr_data       = $this->generate_qr_data( $order, $passenger_data, $ticket_number, $departure_timestamp );

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
            
            // Phase 2/3: Generate actual PDF/QR files here
            // For Phase 1, we just simulate or keep empty
            // $this->generate_pdf_ticket($ticket_id, ...);
            
            error_log( "VSBBM: Ticket generated: $ticket_number for Order #{$order->get_id()}" );
        }
    }

    /**
     * Generate unique ticket number.
     */
    private function generate_ticket_number( $order_id ) {
        return strtoupper( uniqid( 'TK-' . $order_id . '-' ) );
    }

    /**
     * Generate QR Code JSON Data.
     */
    private function generate_qr_data( $order, $passenger_data, $ticket_number, $departure_timestamp ) {
        $data = array(
            'tn' => $ticket_number, // Short keys for smaller QR
            'oid' => $order->get_id(),
            'seat' => $passenger_data['seat_number'] ?? '',
            'nid' => $passenger_data['کد ملی'] ?? ($passenger_data['National ID'] ?? ''), // Handle both keys
            'dt' => $departure_timestamp,
        );
        return wp_json_encode( $data );
    }

    /**
     * Add Rewrite Endpoint.
     */
    public function add_ticket_download_endpoint() {
        add_rewrite_endpoint( 'ticket-download', EP_ROOT | EP_PAGES );
    }

    /**
     * Handle PDF Download.
     */
    public function handle_ticket_download() {
        global $wp_query;

        if ( ! isset( $wp_query->query_vars['ticket-download'] ) ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
            exit;
        }

        $ticket_id = absint( $wp_query->query_vars['ticket-download'] );
        $this->download_ticket( $ticket_id );
        exit;
    }

    /**
     * Download Logic.
     */
    private function download_ticket( $ticket_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $ticket = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $ticket_id ) );

        if ( ! $ticket ) {
            wp_die( __( 'Ticket not found.', 'vs-bus-booking-manager' ) );
        }

        $order = wc_get_order( $ticket->order_id );
        
        // Security: Check ownership
        if ( ! $order || $order->get_customer_id() !== get_current_user_id() ) {
            wp_die( __( 'You do not have permission to view this ticket.', 'vs-bus-booking-manager' ) );
        }

        // If PDF exists on server
        if ( ! empty( $ticket->pdf_path ) ) {
            $upload_dir = wp_upload_dir();
            $file_path  = $upload_dir['basedir'] . '/' . ltrim( $ticket->pdf_path, '/' );
            
            // Prevent directory traversal
            $real_path = realpath( $file_path );
            if ( $real_path && file_exists( $real_path ) && strpos( $real_path, $upload_dir['basedir'] ) === 0 ) {
                header( 'Content-Type: application/pdf' );
                header( 'Content-Disposition: attachment; filename="Ticket-' . $ticket->ticket_number . '.pdf"' );
                readfile( $real_path );
                exit;
            }
        }

        // Fallback: Generate HTML View on the fly (Phase 1)
        // Since we don't have a PDF library bundled yet.
        $this->render_html_ticket( $ticket, $order );
        exit;
    }

    /**
     * Render HTML Ticket (Fallback/Preview).
     */
    private function render_html_ticket( $ticket, $order ) {
        $passenger_data = json_decode( $ticket->passenger_data, true );
        echo $this->get_ticket_html_template( $order, $passenger_data, $ticket->ticket_number, $ticket->id );
    }

    /**
     * Get HTML Template.
     */
    private function get_ticket_html_template( $order, $passenger_data, $ticket_number, $ticket_id ) {
        $product_name = '';
        foreach ( $order->get_items() as $item ) {
            if ( VSBBM_Seat_Manager::is_seat_booking_enabled( $item->get_product_id() ) ) {
                $product_name = $item->get_name();
                break;
            }
        }

        $departure_timestamp = $ticket->departure_timestamp ?? ($passenger_data['_vsbbm_departure_timestamp'] ?? 0); // Handle object or array
        $date_display        = $departure_timestamp ? wp_date( 'Y/m/d', $departure_timestamp ) : '-';
        $time_display        = $departure_timestamp ? wp_date( 'H:i', $departure_timestamp ) : '-';

        ob_start();
        ?>
        <!DOCTYPE html>
        <html dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
        <head>
            <meta charset="UTF-8">
            <title><?php esc_html_e( 'Ticket', 'vs-bus-booking-manager' ); ?> - <?php echo esc_html( $ticket_number ); ?></title>
            <style>
                body { font-family: Tahoma, Arial, sans-serif; background: #f0f0f0; padding: 20px; }
                .ticket-box { background: #fff; border: 1px solid #ccc; max-width: 600px; margin: 0 auto; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .ticket-header { border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 20px; text-align: center; }
                .row { display: flex; justify-content: space-between; margin-bottom: 10px; border-bottom: 1px dotted #eee; padding-bottom: 5px; }
                .label { font-weight: bold; color: #555; }
                .footer { margin-top: 20px; text-align: center; font-size: 12px; color: #999; }
                @media print { body { background: #fff; } .ticket-box { box-shadow: none; border: 2px solid #000; } }
            </style>
        </head>
        <body>
            <div class="ticket-box">
                <div class="ticket-header">
                    <h2><?php esc_html_e( 'e-Ticket', 'vs-bus-booking-manager' ); ?></h2>
                    <p><?php esc_html_e( 'Order:', 'vs-bus-booking-manager' ); ?> #<?php echo esc_html( $order->get_order_number() ); ?></p>
                </div>
                
                <div class="row">
                    <span class="label"><?php esc_html_e( 'Service:', 'vs-bus-booking-manager' ); ?></span>
                    <span><?php echo esc_html( $product_name ); ?></span>
                </div>
                <div class="row">
                    <span class="label"><?php esc_html_e( 'Ticket Number:', 'vs-bus-booking-manager' ); ?></span>
                    <span><strong><?php echo esc_html( $ticket_number ); ?></strong></span>
                </div>
                <div class="row">
                    <span class="label"><?php esc_html_e( 'Departure Date:', 'vs-bus-booking-manager' ); ?></span>
                    <span><?php echo esc_html( $date_display ); ?></span>
                </div>
                <div class="row">
                    <span class="label"><?php esc_html_e( 'Departure Time:', 'vs-bus-booking-manager' ); ?></span>
                    <span><?php echo esc_html( $time_display ); ?></span>
                </div>
                
                <hr>
                
                <h3><?php esc_html_e( 'Passenger Details', 'vs-bus-booking-manager' ); ?></h3>
                <div class="row">
                    <span class="label"><?php esc_html_e( 'Seat Number:', 'vs-bus-booking-manager' ); ?></span>
                    <span><strong style="font-size: 1.2em;"><?php echo esc_html( $passenger_data['seat_number'] ?? '-' ); ?></strong></span>
                </div>
                
                <?php foreach ( $passenger_data as $key => $value ) : 
                    if ( in_array( $key, array( 'seat_number', '_vsbbm_departure_timestamp' ) ) || empty( $value ) ) continue;
                    ?>
                    <div class="row">
                        <span class="label"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $key ) ) ); ?>:</span>
                        <span><?php echo esc_html( $value ); ?></span>
                    </div>
                <?php endforeach; ?>

                <div class="footer">
                    <p><?php esc_html_e( 'Please show this ticket to the driver.', 'vs-bus-booking-manager' ); ?></p>
                    <button onclick="window.print()" style="padding: 5px 10px; cursor: pointer;"><?php esc_html_e( 'Print Ticket', 'vs-bus-booking-manager' ); ?></button>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Add Menu Item to My Account.
     */
    public function add_ticket_menu_to_account( $items ) {
        // Insert after Dashboard (or wherever preferred)
        $new_items = array();
        foreach ( $items as $key => $value ) {
            $new_items[ $key ] = $value;
            if ( 'dashboard' === $key ) {
                $new_items['tickets'] = __( 'My Tickets', 'vs-bus-booking-manager' );
            }
        }
        return $new_items;
    }

    /**
     * Display Tickets in My Account.
     */
    public function display_tickets_in_account() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        $user_id    = get_current_user_id();

        // Get orders for this user
        $orders = wc_get_orders( array(
            'customer' => $user_id,
            'status'   => array( 'completed', 'processing' ),
            'limit'    => -1,
            'return'   => 'ids',
        ) );

        if ( empty( $orders ) ) {
            echo '<div class="woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">' . esc_html__( 'No tickets found.', 'vs-bus-booking-manager' ) . '</div>';
            return;
        }

        $order_ids_placeholder = implode( ',', array_fill( 0, count( $orders ), '%d' ) );
        
        $tickets = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_id IN ($order_ids_placeholder) ORDER BY created_at DESC",
            $orders
        ) );

        if ( empty( $tickets ) ) {
            echo '<div class="woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">' . esc_html__( 'No tickets found.', 'vs-bus-booking-manager' ) . '</div>';
            return;
        }

        ?>
        <h3><?php esc_html_e( 'My Bus Tickets', 'vs-bus-booking-manager' ); ?></h3>
        <table class="woocommerce-orders-table shop_table shop_table_responsive my_account_orders account-orders-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Ticket No', 'vs-bus-booking-manager' ); ?></th>
                    <th><?php esc_html_e( 'Seat', 'vs-bus-booking-manager' ); ?></th>
                    <th><?php esc_html_e( 'Departure', 'vs-bus-booking-manager' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'vs-bus-booking-manager' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'vs-bus-booking-manager' ); ?></th>
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
                    <td data-title="<?php esc_attr_e( 'Status', 'vs-bus-booking-manager' ); ?>">
                        <?php echo esc_html( ucfirst( $ticket->status ) ); ?>
                    </td>
                    <td data-title="<?php esc_attr_e( 'Actions', 'vs-bus-booking-manager' ); ?>">
                        <a href="<?php echo esc_url( wc_get_endpoint_url( 'ticket-download', $ticket->id, wc_get_page_permalink( 'myaccount' ) ) ); ?>" class="woocommerce-button button view">
                            <?php esc_html_e( 'View Ticket', 'vs-bus-booking-manager' ); ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    // ... Methods for QR validation & Use (Ticket Scanner) can be added here ...
    // kept minimal for Phase 1.
    
    /**
     * Mark ticket as used (API/Scanner).
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
}

// Initialize
VSBBM_Ticket_Manager::get_instance();