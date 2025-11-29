<?php
/**
 * Class VSBBM_Seat_Manager
 *
 * Manages seat configuration, admin meta boxes, and frontend seat selection logic.
 * Refactored to OOP Singleton pattern.
 *
 * @package VSBBM
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

class VSBBM_Seat_Manager {

    /**
     * Singleton Instance
     */
    private static $instance = null;

    /**
     * Get Singleton Instance
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor (Registers Hooks)
     */
    private function __construct() {
        $this->register_admin_hooks();
        $this->register_frontend_hooks();
        $this->register_ajax_hooks();
    }

    /**
     * Register Admin Hooks
     */
    private function register_admin_hooks() {
        add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_product_fields' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_fields' ) );

        add_action( 'add_meta_boxes', array( $this, 'add_seat_meta_box' ) );
        add_action( 'save_post_product', array( $this, 'save_seat_numbers' ) );
        
        add_action( 'add_meta_boxes', array( $this, 'add_schedule_meta_box' ) );
        add_action( 'save_post_product', array( $this, 'save_schedule_settings' ) );

        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
    }

    /**
     * Register Frontend Hooks
     */
    private function register_frontend_hooks() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );
        add_action( 'woocommerce_single_product_summary', array( $this, 'display_seat_selection' ), 25 );
    }

    /**
     * Register AJAX Hooks
     */
    private function register_ajax_hooks() {
        $actions = array(
            'get_passenger_fields',
            'get_reserved_seats',
            'add_to_cart'
        );

        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_vsbbm_' . $action, array( $this, $action . '_ajax' ) );
            add_action( 'wp_ajax_nopriv_vsbbm_' . $action, array( $this, $action . '_ajax' ) );
        }
    }

    /* ==========================================================================
       ASSETS
       ========================================================================== */

    public function enqueue_admin_scripts( $hook ) {
        $screen = get_current_screen();
        if ( $screen && 'product' === $screen->id ) {
            wp_enqueue_style( 'vsbbm-admin-css', VSBBM_PLUGIN_URL . 'assets/css/admin.css', array(), VSBBM_VERSION );
            wp_enqueue_script( 'vsbbm-admin-js', VSBBM_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), VSBBM_VERSION, true );
            wp_enqueue_script( 'jquery-ui-datepicker' );
            wp_enqueue_style( 'jquery-ui-style', '//ajax.googleapis.com/ajax/libs/jqueryui/1.13.2/themes/smoothness/jquery-ui.css', array(), '1.13.2' );
        }
    }

    public function enqueue_frontend_scripts() {
        if ( ! is_product() ) return;
        global $post;
        if ( ! self::is_seat_booking_enabled( $post->ID ) ) return;

        wp_enqueue_style( 'vsbbm-frontend-css', VSBBM_PLUGIN_URL . 'assets/css/frontend.css', array(), VSBBM_VERSION );
        wp_enqueue_script( 'vsbbm-frontend-js', VSBBM_PLUGIN_URL . 'assets/js/frontend.js', array( 'jquery', 'jquery-ui-datepicker' ), VSBBM_VERSION, true );
        wp_enqueue_style( 'jquery-ui-style', '//ajax.googleapis.com/ajax/libs/jqueryui/1.13.2/themes/smoothness/jquery-ui.css', array(), '1.13.2' );

        wp_localize_script(
            'vsbbm-frontend-js',
            'vsbbm_ajax_object',
            array(
                'ajax_url'          => admin_url( 'admin-ajax.php' ),
                'product_id'        => $post->ID,
                'nonce'             => wp_create_nonce( 'vsbbm_seat_nonce' ),
                'schedule_settings' => self::get_schedule_settings( $post->ID ),
                'current_time'      => current_time( 'timestamp' ),
                'currency_symbol'   => get_woocommerce_currency_symbol(),
                'i18n'              => array(
                    'select_departure_date' => __( 'Please select a departure date.', 'vs-bus-booking-manager' ),
                    'fill_passenger_data'   => __( 'Please fill in all passenger details.', 'vs-bus-booking-manager' ),
                ),
            )
        );
    }

    /* ==========================================================================
       ADMIN METHODS
       ========================================================================== */

    public function add_product_fields() {
        global $product_object;
        if ( ! $product_object ) return;

        echo '<div class="options_group">';
        woocommerce_wp_checkbox( array(
            'id' => '_vsbbm_enable_seat_booking',
            'label' => __( 'Enable Bus Seat Booking', 'vs-bus-booking-manager' ),
        ) );
        woocommerce_wp_select( array(
            'id' => '_vsbbm_bus_type',
            'label' => __( 'Bus Layout Type', 'vs-bus-booking-manager' ),
            'options' => array( '2x2' => '2x2 (Standard)', '1x2' => '1x2 (VIP)', '1x1' => '1x1 (Luxury)' ),
        ) );
        woocommerce_wp_text_input( array( 'id' => '_vsbbm_bus_rows', 'label' => __( 'Total Rows', 'vs-bus-booking-manager' ), 'type' => 'number' ) );
        woocommerce_wp_text_input( array( 'id' => '_vsbbm_bus_columns', 'label' => __( 'Columns Per Side', 'vs-bus-booking-manager' ), 'type' => 'number' ) );
        echo '</div>';
    }

    public function save_product_fields( $post_id ) {
        if ( ! current_user_can( 'edit_product', $post_id ) ) return;

        $enable = isset( $_POST['_vsbbm_enable_seat_booking'] ) ? 'yes' : 'no';
        update_post_meta( $post_id, '_vsbbm_enable_seat_booking', $enable );

        if ( isset( $_POST['_vsbbm_bus_type'] ) ) update_post_meta( $post_id, '_vsbbm_bus_type', sanitize_text_field( $_POST['_vsbbm_bus_type'] ) );
        if ( isset( $_POST['_vsbbm_bus_rows'] ) ) update_post_meta( $post_id, '_vsbbm_bus_rows', absint( $_POST['_vsbbm_bus_rows'] ) );
        if ( isset( $_POST['_vsbbm_bus_columns'] ) ) update_post_meta( $post_id, '_vsbbm_bus_columns', absint( $_POST['_vsbbm_bus_columns'] ) );
    }

    public function add_seat_meta_box() {
        add_meta_box( 'vsbbm_seat_layout', __( 'Visual Seat Editor', 'vs-bus-booking-manager' ), array( $this, 'render_seat_meta_box' ), 'product', 'normal', 'high' );
    }

    public function render_seat_meta_box( $post ) {
        wp_nonce_field( 'vsbbm_save_seat_numbers', 'vsbbm_seat_numbers_nonce' );
        $seat_numbers = self::get_seat_numbers( $post->ID );
        $settings     = self::get_product_settings( $post->ID );
        ?>
        <div id="vsbbm-seat-manager">
            <div class="vsbbm-seat-layout-editor" data-rows="<?php echo esc_attr($settings['rows']); ?>" data-cols="<?php echo esc_attr($settings['columns']); ?>">
                <div id="vsbbm-seat-grid"></div>
            </div>
            <input type="hidden" name="_vsbbm_seat_numbers" id="vsbbm_seat_numbers_input" value="<?php echo esc_attr( json_encode( $seat_numbers ) ); ?>" />
            <p><strong><?php esc_html_e( 'Active Seats:', 'vs-bus-booking-manager' ); ?></strong> <span id="vsbbm-current-seats"><?php echo esc_html( implode( ', ', array_keys( $seat_numbers ) ) ); ?></span></p>
        </div>
        <?php
    }

    public function save_seat_numbers( $post_id ) {
        if ( ! isset( $_POST['vsbbm_seat_numbers_nonce'] ) || ! wp_verify_nonce( $_POST['vsbbm_seat_numbers_nonce'], 'vsbbm_save_seat_numbers' ) ) return;
        if ( ! current_user_can( 'edit_product', $post_id ) ) return;

        if ( isset( $_POST['_vsbbm_seat_numbers'] ) ) {
            $json = wp_unslash( $_POST['_vsbbm_seat_numbers'] );
            $data = json_decode( $json, true );
            $clean_seats = array();
            if ( is_array( $data ) ) {
                foreach ( $data as $k => $v ) {
                    $clean_seats[ sanitize_key( $k ) ] = array(
                        'label' => sanitize_text_field( $v['label'] ?? $k ),
                        'price' => floatval( $v['price'] ?? 0 ),
                        'type'  => sanitize_key( $v['type'] ?? 'default' )
                    );
                }
            }
            update_post_meta( $post_id, '_vsbbm_seat_numbers', $clean_seats );
            if ( class_exists( 'VSBBM_Cache_Manager' ) ) VSBBM_Cache_Manager::get_instance()->clear_product_cache( $post_id );
        }
    }

    public function add_schedule_meta_box() {
        add_meta_box( 'vsbbm_schedule_meta_box', __( 'Trip Schedule', 'vs-bus-booking-manager' ), array( $this, 'render_schedule_meta_box' ), 'product', 'side', 'high' );
    }

    public function render_schedule_meta_box( $post ) {
        $settings = self::get_schedule_settings( $post->ID );
        ?>
        <div class="vsbbm-schedule-settings">
            <p><label><input type="checkbox" name="vsbbm_enable_schedule" value="yes" <?php checked('yes', $settings['enable_schedule']); ?>> <?php _e('Enable Schedule', 'vs-bus-booking-manager'); ?></label></p>
            <div id="vsbbm_schedule_fields" style="<?php echo ('yes'!==$settings['enable_schedule'])?'display:none':''; ?>">
                <p><label><?php _e('Type:', 'vs-bus-booking-manager'); ?></label>
                <select name="vsbbm_schedule_type" id="vsbbm_schedule_type" class="widefat">
                    <option value="everyday" <?php selected($settings['schedule_type'], 'everyday'); ?>>Everyday</option>
                    <option value="weekdays" <?php selected($settings['schedule_type'], 'weekdays'); ?>>Weekdays</option>
                    <option value="specific_dates" <?php selected($settings['schedule_type'], 'specific_dates'); ?>>Specific Dates</option>
                </select></p>
                
                <div id="vsbbm_weekdays_container" style="display:<?php echo ('weekdays'===$settings['schedule_type'])?'block':'none'; ?>; border:1px solid #ddd; padding:10px;">
                    <?php 
                    $days = ['saturday'=>'Saturday', 'sunday'=>'Sunday', 'monday'=>'Monday', 'tuesday'=>'Tuesday', 'wednesday'=>'Wednesday', 'thursday'=>'Thursday', 'friday'=>'Friday'];
                    foreach($days as $k=>$v) {
                        $ch = in_array($k, $settings['allowed_weekdays']) ? 'checked' : '';
                        echo "<label style='display:block'><input type='checkbox' name='vsbbm_allowed_weekdays[]' value='$k' $ch> $v</label>";
                    }
                    ?>
                </div>
                
                <div id="vsbbm_dates_container" style="display:<?php echo ('specific_dates'===$settings['schedule_type'])?'block':'none'; ?>">
                    <label><?php _e('Dates (YYYY-MM-DD):', 'vs-bus-booking-manager'); ?></label>
                    <textarea name="vsbbm_specific_dates" class="widefat" rows="3"><?php echo esc_textarea(implode(', ', $settings['specific_dates'])); ?></textarea>
                </div>
                <p><label><?php _e('Time:', 'vs-bus-booking-manager'); ?></label><input type="text" name="vsbbm_departure_time" value="<?php echo esc_attr($settings['departure_time']); ?>" class="widefat"></p>
            </div>
            <script>jQuery(document).ready(function($){
                $('#vsbbm_enable_schedule').change(function(){ $('#vsbbm_schedule_fields').toggle(this.checked); });
                $('#vsbbm_schedule_type').change(function(){ 
                    $('#vsbbm_weekdays_container, #vsbbm_dates_container').hide();
                    if($(this).val()=='weekdays') $('#vsbbm_weekdays_container').show();
                    if($(this).val()=='specific_dates') $('#vsbbm_dates_container').show();
                });
            });</script>
        </div>
        <?php
    }

    public function save_schedule_settings( $post_id ) {
        if ( ! isset( $_POST['vsbbm_seat_numbers_nonce'] ) || ! wp_verify_nonce( $_POST['vsbbm_seat_numbers_nonce'], 'vsbbm_save_seat_numbers' ) ) return;
        if ( ! current_user_can( 'edit_product', $post_id ) ) return;

        update_post_meta( $post_id, '_vsbbm_enable_schedule', isset($_POST['vsbbm_enable_schedule']) ? 'yes' : 'no' );
        update_post_meta( $post_id, '_vsbbm_schedule_type', sanitize_text_field($_POST['vsbbm_schedule_type']) );
        update_post_meta( $post_id, '_vsbbm_departure_time', sanitize_text_field($_POST['vsbbm_departure_time']) );
        
        $weekdays = isset($_POST['vsbbm_allowed_weekdays']) ? array_map('sanitize_text_field', $_POST['vsbbm_allowed_weekdays']) : [];
        update_post_meta( $post_id, '_vsbbm_allowed_weekdays', $weekdays );
        
        $dates = isset($_POST['vsbbm_specific_dates']) ? array_filter(array_map('trim', explode(',', sanitize_textarea_field($_POST['vsbbm_specific_dates'])))) : [];
        update_post_meta( $post_id, '_vsbbm_specific_dates', $dates );
    }

    /* ==========================================================================
       FRONTEND DISPLAY
       ========================================================================== */

    public function display_seat_selection() {
        global $product;
        if ( ! self::is_seat_booking_enabled( $product->get_id() ) ) return;

        $settings = self::get_product_settings( $product->get_id() );
        $schedule = self::get_schedule_settings( $product->get_id() );
        $seats    = self::get_seat_numbers( $product->get_id() );

        if ( empty( $seats ) ) {
            echo '<p class="vsbbm-warning">' . esc_html__( 'Seat layout is not defined.', 'vs-bus-booking-manager' ) . '</p>';
            return;
        }
        
        echo '<div id="vsbbm-seat-selector" class="vsbbm-seat-selector-container" ';
        echo 'data-product-id="' . esc_attr( $product->get_id() ) . '" ';
        echo 'data-cols="' . esc_attr( $settings['columns'] ) . '" ';
        echo 'data-price="' . esc_attr( $product->get_price() ) . '">';
        
        if ( 'yes' === $schedule['enable_schedule'] ) {
            echo '<div class="vsbbm-departure-schedule-picker"><h3>' . __('Select Date', 'vs-bus-booking-manager') . '</h3>';
            echo '<input type="text" id="vsbbm_departure_date" readonly placeholder="' . __('Date', 'vs-bus-booking-manager') . '">';
            echo '<input type="hidden" id="vsbbm_departure_timestamp"></div>';
        }

        echo '<div class="vsbbm-seat-layout-display"><h3>' . __('Select Seats', 'vs-bus-booking-manager') . '</h3><div id="vsbbm-seat-map">';
        echo '<div class="vsbbm-loading-overlay"><div class="vsbbm-spinner"></div><p>' . __('Loading...', 'vs-bus-booking-manager') . '</p></div>';
        echo '</div></div>';

        echo '<div class="vsbbm-passenger-data-form" style="display:none;"><h3>' . __('Passenger Details', 'vs-bus-booking-manager') . '</h3><div id="vsbbm-passenger-fields"></div></div>';

        echo '<div class="vsbbm-summary-bar">';
        echo '<span class="vsbbm-summary-item">' . __('Selected:', 'vs-bus-booking-manager') . ' <strong id="vsbbm-selected-seats-count">0</strong></span>';
        echo '<span class="vsbbm-summary-item">' . __('Total:', 'vs-bus-booking-manager') . ' <strong id="vsbbm-total-price">0</strong></span>';
        echo '<button id="vsbbm-add-to-cart-button" disabled>' . __('Book Now', 'vs-bus-booking-manager') . '</button>';
        echo '</div></div>';
    }

    /* ==========================================================================
       AJAX HANDLERS
       ========================================================================== */

    public function get_passenger_fields_ajax() {
        check_ajax_referer( 'vsbbm_seat_nonce', 'nonce' );
        
        $saved_fields = get_option('vsbbm_passenger_fields');
        if ( empty($saved_fields) ) {
            $saved_fields = [
                ['type'=>'text', 'label'=>__('Full Name', 'vs-bus-booking-manager'), 'required'=>true],
                ['type'=>'text', 'label'=>__('National ID', 'vs-bus-booking-manager'), 'required'=>true],
                ['type'=>'tel', 'label'=>__('Phone', 'vs-bus-booking-manager'), 'required'=>false]
            ];
        }
        
        $frontend_fields = [];
        $blacklist_target = get_option('vsbbm_blacklist_target_field', 'کد ملی');

        foreach ($saved_fields as $field) {
            $data = [
                'type' => sanitize_text_field($field['type']),
                'label' => sanitize_text_field($field['label']),
                'placeholder' => sanitize_text_field($field['placeholder']??''),
                'required' => !empty($field['required']),
            ];
            
            if ($field['type'] === 'select' && !empty($field['options'])) {
                $data['options'] = array_map('trim', explode(',', $field['options']));
            }
            
            if ($field['label'] === $blacklist_target) {
                $data['validation'] = 'national_code'; 
                $data['required'] = true;
            }
            
            $frontend_fields[] = $data;
        }
        
        wp_send_json_success( $frontend_fields );
    }

    public function get_reserved_seats_ajax() {
        check_ajax_referer( 'vsbbm_seat_nonce', 'nonce' );

        $product_id = absint( $_POST['product_id'] );
        $timestamp  = absint( $_POST['departure_timestamp'] );

        if ( ! $product_id ) wp_send_json_error( [ 'message' => 'Invalid ID' ] );

        $reserved = self::get_reserved_seats( $product_id, $timestamp );
        $all      = self::get_seat_numbers( $product_id );

        wp_send_json_success( [
            'reserved' => array_keys( $reserved ),
            'all_seats' => $all
        ] );
    }

    public function add_to_cart_ajax() {
        check_ajax_referer( 'vsbbm_seat_nonce', 'nonce' );

        $product_id = absint( $_POST['product_id'] );
        $timestamp  = absint( $_POST['vsbbm_departure_timestamp'] ?? 0 );
        $seats      = json_decode( wp_unslash( $_POST['selected_seats'] ), true );
        $passengers = json_decode( wp_unslash( $_POST['passengers_data'] ), true );

        if ( empty( $seats ) ) wp_send_json_error( [ 'message' => __( 'No seats selected.', 'vs-bus-booking-manager' ) ] );

        // Blacklist Check
        if ( class_exists( 'VSBBM_Blacklist' ) ) {
            $target = get_option( 'vsbbm_blacklist_target_field', 'کد ملی' );
            foreach ( $passengers as $p ) {
                if ( ! empty( $p[ $target ] ) && VSBBM_Blacklist::is_blacklisted( $p[ $target ] ) ) {
                    wp_send_json_error( [ 'message' => __( 'Booking blocked by blacklist.', 'vs-bus-booking-manager' ) ] );
                }
            }
        }

        // Reservation
        $keys = [];
        foreach ( $seats as $seat ) {
            $seat_info = [];
            foreach($passengers as $p) { if(isset($p['seat_number']) && $p['seat_number'] == $seat) $seat_info = $p; }
            
            $res_id = VSBBM_Seat_Reservations::reserve_seat( $product_id, $seat, $timestamp, null, null, $seat_info );
            
            if ( is_wp_error( $res_id ) ) {
                VSBBM_Seat_Reservations::cancel_reservations_by_keys( $keys );
                wp_send_json_error( [ 'message' => $res_id->get_error_message() ] );
            }
            $keys[] = $res_id;
        }

        // WC Cart
        $data = [
            'vsbbm_reservation_keys' => $keys,
            'vsbbm_departure_timestamp' => $timestamp,
            'vsbbm_seats' => $seats,
            'vsbbm_passengers' => $passengers
        ];

        if ( WC()->cart->add_to_cart( $product_id, count( $seats ), 0, [], $data ) ) {
            wp_send_json_success( [ 'checkout_url' => wc_get_checkout_url() ] );
        } else {
            VSBBM_Seat_Reservations::cancel_reservations_by_keys( $keys );
            wp_send_json_error( [ 'message' => 'Cart Error' ] );
        }
    }

    /* ==========================================================================
       STATIC HELPERS
       ========================================================================== */

    public static function is_seat_booking_enabled( $id ) {
        return 'yes' === get_post_meta( $id, '_vsbbm_enable_seat_booking', true );
    }

    public static function get_schedule_settings( $id ) {
        return [
            'enable_schedule' => get_post_meta( $id, '_vsbbm_enable_schedule', true ) ?: 'no',
            'schedule_type' => get_post_meta( $id, '_vsbbm_schedule_type', true ) ?: 'everyday',
            'allowed_weekdays' => get_post_meta( $id, '_vsbbm_allowed_weekdays', true ) ?: [],
            'specific_dates' => get_post_meta( $id, '_vsbbm_specific_dates', true ) ?: [],
            'departure_time' => get_post_meta( $id, '_vsbbm_departure_time', true ) ?: '12:00',
            'min_days_advance' => get_post_meta( $id, '_vsbbm_min_days_advance', true ) ?: 0,
            'max_days_advance' => get_post_meta( $id, '_vsbbm_max_days_advance', true ) ?: 365,
        ];
    }

    public static function get_product_settings( $id ) {
        return [
            'rows' => (int) get_post_meta( $id, '_vsbbm_bus_rows', true ) ?: 10,
            'columns' => (int) get_post_meta( $id, '_vsbbm_bus_columns', true ) ?: 2,
            'type' => get_post_meta( $id, '_vsbbm_bus_type', true ) ?: '2x2',
        ];
    }

    public static function get_seat_numbers( $id ) {
        $s = get_post_meta( $id, '_vsbbm_seat_numbers', true );
        return is_array( $s ) ? $s : [];
    }

    public static function get_reserved_seats( $product_id, $timestamp = null ) {
        if ( ! class_exists( 'VSBBM_Seat_Reservations' ) ) return [];
        if ( empty( $timestamp ) ) {
            $s = self::get_schedule_settings( $product_id );
            list( $h, $m ) = explode( ':', $s['departure_time'] );
            $timestamp = strtotime( date( 'Y-m-d' ) . " $h:$m" );
        }
        return VSBBM_Seat_Reservations::get_reserved_seats_by_product_and_time( $product_id, $timestamp );
    }
}