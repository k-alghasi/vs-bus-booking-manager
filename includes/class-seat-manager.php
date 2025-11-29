<?php
/**
 * Class VSBBM_Seat_Manager
 *
 * Manages seat configuration, admin meta boxes, AJAX handlers, and frontend display.
 * Fully integrated with Visual Editor and Time-Based Reservations.
 *
 * @package VSBBM
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

class VSBBM_Seat_Manager {

    /**
     * Initialize the class and register hooks.
     */
    public static function init() {
        static $initialized = false;
        if ( $initialized ) {
            return;
        }
        $initialized = true;

        // 1. Admin Product Settings (General Tab)
        add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'add_product_fields' ) );
        add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_fields' ) );

        // 2. Visual Seat Editor Meta Box
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_seat_meta_box' ) );
        add_action( 'save_post_product', array( __CLASS__, 'save_seat_numbers' ) );

        // 3. Schedule Settings Meta Box
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_schedule_meta_box' ) );
        add_action( 'save_post_product', array( __CLASS__, 'save_schedule_settings' ) );

        // 4. Assets (CSS/JS)
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_scripts' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_scripts' ) );

        // 5. AJAX Handlers
        self::register_ajax_handlers();

        // 6. Frontend Display
        add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'display_seat_selection' ), 25 );
    }

    /**
     * Enqueue Admin Scripts.
     */
    public static function enqueue_admin_scripts( $hook ) {
        $screen = get_current_screen();
        if ( $screen && 'product' === $screen->id ) {
            // Main Admin CSS (Dashboard + Editor)
            wp_enqueue_style( 'vsbbm-admin-css', VSBBM_PLUGIN_URL . 'assets/css/admin.css', array(), VSBBM_VERSION );
            
            // Visual Editor JS
            wp_enqueue_script( 'vsbbm-admin-js', VSBBM_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), VSBBM_VERSION, true );
            
            // Datepicker for Schedule settings
            wp_enqueue_script( 'jquery-ui-datepicker' );
            wp_enqueue_style( 'jquery-ui-style', '//ajax.googleapis.com/ajax/libs/jqueryui/1.13.2/themes/smoothness/jquery-ui.css', array(), '1.13.2' );
        }
    }

    /**
     * Enqueue Frontend Scripts.
     */
    public static function enqueue_frontend_scripts() {
        if ( ! is_product() ) {
            return;
        }

        global $post;
        if ( ! self::is_seat_booking_enabled( $post->ID ) ) {
            return;
        }

        // Frontend CSS
        wp_enqueue_style( 'vsbbm-frontend-css', VSBBM_PLUGIN_URL . 'assets/css/frontend.css', array(), VSBBM_VERSION );
        
        // Frontend JS
        wp_enqueue_script( 'vsbbm-frontend-js', VSBBM_PLUGIN_URL . 'assets/js/frontend.js', array( 'jquery', 'jquery-ui-datepicker' ), VSBBM_VERSION, true );
        
        // Datepicker Style
        wp_enqueue_style( 'jquery-ui-style', '//ajax.googleapis.com/ajax/libs/jqueryui/1.13.2/themes/smoothness/jquery-ui.css', array(), '1.13.2' );

        // Localize Script (Pass Data to JS)
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
                    'seat_reserved'         => __( 'Seat Reserved', 'vs-bus-booking-manager' ),
                    'seat_selected'         => __( 'Selected', 'vs-bus-booking-manager' ),
                    'seat_available'        => __( 'Available', 'vs-bus-booking-manager' ),
                    'fill_passenger_data'   => __( 'Please fill in all passenger details.', 'vs-bus-booking-manager' ),
                    'max_seats_reached'     => __( 'Maximum seat limit reached.', 'vs-bus-booking-manager' ),
                ),
            )
        );
    }

    /* ==========================================================================
       ADMIN SETTINGS & META BOXES
       ========================================================================== */

    /**
     * Add fields to "General" tab in Product Data.
     */
    public static function add_product_fields() {
        global $product_object;

        if ( ! $product_object ) return;

        echo '<div class="options_group">';

        // Enable Checkbox
        woocommerce_wp_checkbox( array(
            'id'          => '_vsbbm_enable_seat_booking',
            'value'       => get_post_meta( $product_object->get_id(), '_vsbbm_enable_seat_booking', true ),
            'label'       => __( 'Enable Bus Seat Booking', 'vs-bus-booking-manager' ),
            'description' => __( 'Enable seat selection system for this product.', 'vs-bus-booking-manager' ),
        ) );

        // Bus Type
        woocommerce_wp_select( array(
            'id'          => '_vsbbm_bus_type',
            'value'       => get_post_meta( $product_object->get_id(), '_vsbbm_bus_type', true ),
            'label'       => __( 'Bus Layout Type', 'vs-bus-booking-manager' ),
            'options'     => array(
                '2x2' => __( '2x2 (Standard - 4 seats/row)', 'vs-bus-booking-manager' ),
                '1x2' => __( '1x2 (VIP - 3 seats/row)', 'vs-bus-booking-manager' ),
                '1x1' => __( '1x1 (Luxury - 2 seats/row)', 'vs-bus-booking-manager' ),
            ),
        ) );

        // Rows
        woocommerce_wp_text_input( array(
            'id'                => '_vsbbm_bus_rows',
            'value'             => get_post_meta( $product_object->get_id(), '_vsbbm_bus_rows', true ),
            'label'             => __( 'Total Rows', 'vs-bus-booking-manager' ),
            'type'              => 'number',
            'custom_attributes' => array( 'min' => '1', 'step' => '1' ),
        ) );

        // Columns (Per Side)
        woocommerce_wp_text_input( array(
            'id'                => '_vsbbm_bus_columns',
            'value'             => get_post_meta( $product_object->get_id(), '_vsbbm_bus_columns', true ),
            'label'             => __( 'Columns Per Side', 'vs-bus-booking-manager' ),
            'description'       => __( 'e.g., Enter 2 for 2x2 layout.', 'vs-bus-booking-manager' ),
            'type'              => 'number',
            'custom_attributes' => array( 'min' => '1', 'step' => '1' ),
        ) );

        echo '</div>';
    }

    /**
     * Save General Tab Fields.
     */
    public static function save_product_fields( $post_id ) {
        $enable_booking = isset( $_POST['_vsbbm_enable_seat_booking'] ) ? 'yes' : 'no';
        update_post_meta( $post_id, '_vsbbm_enable_seat_booking', $enable_booking );

        if ( isset( $_POST['_vsbbm_bus_type'] ) ) {
            update_post_meta( $post_id, '_vsbbm_bus_type', sanitize_text_field( $_POST['_vsbbm_bus_type'] ) );
        }
        if ( isset( $_POST['_vsbbm_bus_rows'] ) ) {
            update_post_meta( $post_id, '_vsbbm_bus_rows', absint( $_POST['_vsbbm_bus_rows'] ) );
        }
        if ( isset( $_POST['_vsbbm_bus_columns'] ) ) {
            update_post_meta( $post_id, '_vsbbm_bus_columns', absint( $_POST['_vsbbm_bus_columns'] ) );
        }
    }

    /**
     * Add Visual Seat Editor Meta Box.
     */
    public static function add_seat_meta_box() {
        add_meta_box(
            'vsbbm_seat_layout',
            __( 'Visual Seat Editor', 'vs-bus-booking-manager' ),
            array( __CLASS__, 'render_seat_meta_box' ),
            'product',
            'normal',
            'high'
        );
    }

    /**
     * Render Visual Seat Editor.
     */
    public static function render_seat_meta_box( $post ) {
        wp_nonce_field( 'vsbbm_save_seat_numbers', 'vsbbm_seat_numbers_nonce' );

        $seat_numbers = self::get_seat_numbers( $post->ID );
        $settings     = self::get_product_settings( $post->ID );
        ?>
        <div id="vsbbm-seat-manager">
            <!-- Container for JS Grid -->
            <div class="vsbbm-seat-layout-editor" 
                 data-rows="<?php echo esc_attr( $settings['rows'] ); ?>" 
                 data-cols="<?php echo esc_attr( $settings['columns'] ); ?>" 
                 data-type="<?php echo esc_attr( $settings['type'] ); ?>">
                <div id="vsbbm-seat-grid"></div>
            </div>

            <!-- Hidden input to store JSON data -->
            <input type="hidden" name="_vsbbm_seat_numbers" id="vsbbm_seat_numbers_input" value="<?php echo esc_attr( json_encode( $seat_numbers ) ); ?>" />
            
            <p>
                <strong><?php esc_html_e( 'Active Seats:', 'vs-bus-booking-manager' ); ?></strong> 
                <span id="vsbbm-current-seats"><?php echo esc_html( implode( ', ', array_keys( $seat_numbers ) ) ); ?></span>
            </p>
        </div>
        <?php
    }

    /**
     * Save Seat Layout Data.
     */
    public static function save_seat_numbers( $post_id ) {
        if ( ! isset( $_POST['vsbbm_seat_numbers_nonce'] ) || ! wp_verify_nonce( $_POST['vsbbm_seat_numbers_nonce'], 'vsbbm_save_seat_numbers' ) ) {
            return;
        }

        if ( isset( $_POST['_vsbbm_seat_numbers'] ) ) {
            // Raw JSON from JS
            $json_raw     = wp_unslash( $_POST['_vsbbm_seat_numbers'] );
            $seat_numbers = json_decode( $json_raw, true );

            $clean_seats = array();
            if ( is_array( $seat_numbers ) ) {
                foreach ( $seat_numbers as $seat_key => $seat_data ) {
                    $clean_seats[ sanitize_key( $seat_key ) ] = array(
                        'label' => sanitize_text_field( $seat_data['label'] ?? $seat_key ),
                        'price' => floatval( $seat_data['price'] ?? 0 ),
                        'type'  => sanitize_key( $seat_data['type'] ?? 'default' ),
                    );
                }
            }

            update_post_meta( $post_id, '_vsbbm_seat_numbers', $clean_seats );

            // Clear Cache
            if ( class_exists( 'VSBBM_Cache_Manager' ) ) {
                VSBBM_Cache_Manager::get_instance()->clear_product_cache( $post_id );
            }
        }
    }

    /**
     * Add Schedule Settings Meta Box.
     */
    public static function add_schedule_meta_box() {
        add_meta_box(
            'vsbbm_schedule_meta_box',
            __( 'Trip Schedule Settings', 'vs-bus-booking-manager' ),
            array( __CLASS__, 'render_schedule_meta_box' ),
            'product',
            'side',
            'high'
        );
    }

    /**
     * Render Schedule Meta Box.
     */
    public static function render_schedule_meta_box( $post ) {
        $settings = self::get_schedule_settings( $post->ID );
        ?>
        <div class="vsbbm-schedule-settings">
            <p>
                <label for="vsbbm_enable_schedule">
                    <input type="checkbox" id="vsbbm_enable_schedule" name="vsbbm_enable_schedule" value="yes" <?php checked( 'yes', $settings['enable_schedule'] ); ?> />
                    <strong><?php esc_html_e( 'Enable Trip Scheduling', 'vs-bus-booking-manager' ); ?></strong>
                </label>
            </p>

            <div id="vsbbm_schedule_fields" style="<?php echo ( 'yes' !== $settings['enable_schedule'] ) ? 'display: none;' : ''; ?>">
                
                <p>
                    <label for="vsbbm_schedule_type"><?php esc_html_e( 'Schedule Type:', 'vs-bus-booking-manager' ); ?></label>
                    <select name="vsbbm_schedule_type" id="vsbbm_schedule_type" class="widefat">
                        <option value="everyday" <?php selected( $settings['schedule_type'], 'everyday' ); ?>><?php esc_html_e( 'Every Day', 'vs-bus-booking-manager' ); ?></option>
                        <option value="weekdays" <?php selected( $settings['schedule_type'], 'weekdays' ); ?>><?php esc_html_e( 'Specific Days of Week', 'vs-bus-booking-manager' ); ?></option>
                        <option value="specific_dates" <?php selected( $settings['schedule_type'], 'specific_dates' ); ?>><?php esc_html_e( 'Specific Dates Only', 'vs-bus-booking-manager' ); ?></option>
                    </select>
                </p>

                <!-- Weekdays Selection -->
                <div id="vsbbm_weekdays_container" style="display: <?php echo ( 'weekdays' === $settings['schedule_type'] ) ? 'block' : 'none'; ?>; margin-bottom: 10px; border: 1px solid #ddd; padding: 10px; border-radius:4px;">
                    <strong><?php esc_html_e( 'Select Allowed Days:', 'vs-bus-booking-manager' ); ?></strong><br>
                    <?php
                    $days = array(
                        'saturday'  => __( 'Saturday', 'vs-bus-booking-manager' ),
                        'sunday'    => __( 'Sunday', 'vs-bus-booking-manager' ),
                        'monday'    => __( 'Monday', 'vs-bus-booking-manager' ),
                        'tuesday'   => __( 'Tuesday', 'vs-bus-booking-manager' ),
                        'wednesday' => __( 'Wednesday', 'vs-bus-booking-manager' ),
                        'thursday'  => __( 'Thursday', 'vs-bus-booking-manager' ),
                        'friday'    => __( 'Friday', 'vs-bus-booking-manager' ),
                    );
                    foreach ( $days as $key => $label ) {
                        $checked = in_array( $key, $settings['allowed_weekdays'] ) ? 'checked' : '';
                        echo "<label style='display:block;'><input type='checkbox' name='vsbbm_allowed_weekdays[]' value='{$key}' {$checked}> {$label}</label>";
                    }
                    ?>
                </div>

                <!-- Specific Dates Selection -->
                <div id="vsbbm_dates_container" style="display: <?php echo ( 'specific_dates' === $settings['schedule_type'] ) ? 'block' : 'none'; ?>; margin-bottom: 10px;">
                    <label for="vsbbm_specific_dates"><?php esc_html_e( 'Allowed Dates (YYYY-MM-DD, comma separated):', 'vs-bus-booking-manager' ); ?></label>
                    <textarea name="vsbbm_specific_dates" id="vsbbm_specific_dates" class="widefat" rows="3" placeholder="2024-03-20, 2024-03-21"><?php echo esc_textarea( implode( ', ', $settings['specific_dates'] ) ); ?></textarea>
                </div>

                <hr>

                <p>
                    <label for="vsbbm_departure_time"><?php esc_html_e( 'Departure Time (24h):', 'vs-bus-booking-manager' ); ?></label>
                    <input type="text" id="vsbbm_departure_time" name="vsbbm_departure_time" value="<?php echo esc_attr( $settings['departure_time'] ); ?>" placeholder="14:30" class="widefat" />
                </p>

                <p>
                    <label for="vsbbm_min_days_advance"><?php esc_html_e( 'Min Days in Advance:', 'vs-bus-booking-manager' ); ?></label>
                    <input type="number" id="vsbbm_min_days_advance" name="vsbbm_min_days_advance" value="<?php echo esc_attr( $settings['min_days_advance'] ); ?>" min="0" step="1" class="widefat" />
                </p>
            </div>

            <script>
                jQuery(document).ready(function($) {
                    $('#vsbbm_enable_schedule').on('change', function() {
                        $('#vsbbm_schedule_fields').toggle(this.checked);
                    });
                    
                    $('#vsbbm_schedule_type').on('change', function() {
                        var type = $(this).val();
                        $('#vsbbm_weekdays_container').hide();
                        $('#vsbbm_dates_container').hide();
                        
                        if(type === 'weekdays') $('#vsbbm_weekdays_container').show();
                        if(type === 'specific_dates') $('#vsbbm_dates_container').show();
                    });
                });
            </script>
        </div>
        <?php
    }

    /**
     * Save Schedule Settings.
     */
    public static function save_schedule_settings( $post_id ) {
        if ( isset( $_POST['vsbbm_seat_numbers_nonce'] ) && ! wp_verify_nonce( $_POST['vsbbm_seat_numbers_nonce'], 'vsbbm_save_seat_numbers' ) ) {
             return;
        }

        $enable_schedule = isset( $_POST['vsbbm_enable_schedule'] ) ? 'yes' : 'no';
        $schedule_type   = isset( $_POST['vsbbm_schedule_type'] ) ? sanitize_text_field( $_POST['vsbbm_schedule_type'] ) : 'everyday';
        
        $allowed_weekdays = isset( $_POST['vsbbm_allowed_weekdays'] ) ? array_map( 'sanitize_text_field', $_POST['vsbbm_allowed_weekdays'] ) : array();
        
        $specific_dates_raw = isset( $_POST['vsbbm_specific_dates'] ) ? sanitize_textarea_field( $_POST['vsbbm_specific_dates'] ) : '';
        $specific_dates     = array_filter( array_map( 'trim', explode( ',', $specific_dates_raw ) ) );

        $departure_time = isset( $_POST['vsbbm_departure_time'] ) ? sanitize_text_field( $_POST['vsbbm_departure_time'] ) : '12:00';
        $min_days       = isset( $_POST['vsbbm_min_days_advance'] ) ? absint( $_POST['vsbbm_min_days_advance'] ) : 0;
        $max_days       = isset( $_POST['vsbbm_max_days_advance'] ) ? absint( $_POST['vsbbm_max_days_advance'] ) : 365;

        update_post_meta( $post_id, '_vsbbm_enable_schedule', $enable_schedule );
        update_post_meta( $post_id, '_vsbbm_schedule_type', $schedule_type );
        update_post_meta( $post_id, '_vsbbm_allowed_weekdays', $allowed_weekdays );
        update_post_meta( $post_id, '_vsbbm_specific_dates', $specific_dates );
        update_post_meta( $post_id, '_vsbbm_departure_time', $departure_time );
        update_post_meta( $post_id, '_vsbbm_min_days_advance', $min_days );
        update_post_meta( $post_id, '_vsbbm_max_days_advance', $max_days );
    }

    /* ==========================================================================
       FRONTEND DISPLAY & LOGIC
       ========================================================================== */

    /**
     * Display Frontend Seat Selector.
     */
    public static function display_seat_selection() {
        global $product;

        if ( ! self::is_seat_booking_enabled( $product->get_id() ) ) {
            return;
        }

        $settings          = self::get_product_settings( $product->get_id() );
        $schedule_settings = self::get_schedule_settings( $product->get_id() );
        $seat_numbers      = self::get_seat_numbers( $product->get_id() );

        if ( empty( $seat_numbers ) ) {
            echo '<p class="vsbbm-warning">' . esc_html__( 'Seat layout is not defined.', 'vs-bus-booking-manager' ) . '</p>';
            return;
        }
        ?>
        <div id="vsbbm-seat-selector" class="vsbbm-seat-selector-container"
            data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
            data-rows="<?php echo esc_attr( $settings['rows'] ); ?>"
            data-cols="<?php echo esc_attr( $settings['columns'] ); ?>"
            data-type="<?php echo esc_attr( $settings['type'] ); ?>"
            data-price="<?php echo esc_attr( $product->get_price() ); ?>"
            data-schedule-enabled="<?php echo esc_attr( $schedule_settings['enable_schedule'] ); ?>">

            <?php if ( 'yes' === $schedule_settings['enable_schedule'] ) : ?>
            <div class="vsbbm-departure-schedule-picker">
                <h3><?php esc_html_e( 'Select Departure Date', 'vs-bus-booking-manager' ); ?></h3>
                <input type="text" id="vsbbm_departure_date" placeholder="<?php esc_attr_e( 'Select Date', 'vs-bus-booking-manager' ); ?>" readonly />
                <input type="text" id="vsbbm_departure_time" value="<?php echo esc_attr( $schedule_settings['departure_time'] ); ?>" readonly />
                <input type="hidden" id="vsbbm_departure_timestamp" />
            </div>
            <?php endif; ?>

            <div class="vsbbm-seat-layout-display">
                <h3><?php esc_html_e( 'Select Seats', 'vs-bus-booking-manager' ); ?></h3>
                <?php if ( 'yes' === $schedule_settings['enable_schedule'] ) : ?>
                    <p class="vsbbm-seat-info-message"><?php esc_html_e( 'Please select a date first.', 'vs-bus-booking-manager' ); ?></p>
                <?php endif; ?>
                
                <div id="vsbbm-seat-map">
                    <div class="vsbbm-loading-overlay">
                        <div class="vsbbm-spinner"></div>
                        <p><?php esc_html_e( 'Loading seat map...', 'vs-bus-booking-manager' ); ?></p>
                    </div>
                </div>
                
                <div class="vsbbm-legend">
                    <span><i class="vsbbm-seat available"></i> <?php esc_html_e( 'Available', 'vs-bus-booking-manager' ); ?></span>
                    <span><i class="vsbbm-seat selected"></i> <?php esc_html_e( 'Selected', 'vs-bus-booking-manager' ); ?></span>
                    <span><i class="vsbbm-seat reserved"></i> <?php esc_html_e( 'Reserved', 'vs-bus-booking-manager' ); ?></span>
                </div>
            </div>

            <div class="vsbbm-passenger-data-form" style="display: none;">
                <h3><?php esc_html_e( 'Passenger Details', 'vs-bus-booking-manager' ); ?></h3>
                <div id="vsbbm-passenger-fields"></div>
            </div>

            <div class="vsbbm-summary-bar">
                <span class="vsbbm-summary-item">
                    <?php esc_html_e( 'Selected:', 'vs-bus-booking-manager' ); ?> <strong id="vsbbm-selected-seats-count">0</strong>
                </span>
                <span class="vsbbm-summary-item">
                    <?php esc_html_e( 'Total:', 'vs-bus-booking-manager' ); ?> <strong id="vsbbm-total-price"><?php echo wp_kses_post( wc_price( 0 ) ); ?></strong>
                </span>
                <button type="button" id="vsbbm-add-to-cart-button" class="button alt" disabled>
                    <?php esc_html_e( 'Book & Add to Cart', 'vs-bus-booking-manager' ); ?>
                </button>
            </div>
        </div>
        <?php
    }

    /* ==========================================================================
       AJAX HANDLERS
       ========================================================================== */

    public static function register_ajax_handlers() {
        add_action( 'wp_ajax_vsbbm_get_reserved_seats', array( __CLASS__, 'get_reserved_seats_ajax' ) );
        add_action( 'wp_ajax_nopriv_vsbbm_get_reserved_seats', array( __CLASS__, 'get_reserved_seats_ajax' ) );

        add_action( 'wp_ajax_vsbbm_get_passenger_fields', array( __CLASS__, 'get_passenger_fields_ajax' ) );
        add_action( 'wp_ajax_nopriv_vsbbm_get_passenger_fields', array( __CLASS__, 'get_passenger_fields_ajax' ) );

        add_action( 'wp_ajax_vsbbm_add_to_cart', array( __CLASS__, 'add_to_cart_ajax' ) );
        add_action( 'wp_ajax_nopriv_vsbbm_add_to_cart', array( __CLASS__, 'add_to_cart_ajax' ) );
    }

    /**
     * AJAX: Get Reserved Seats (Critical Fix for Fatal Error).
     */
    public static function get_reserved_seats_ajax() {
        // Simple security check
        check_ajax_referer( 'vsbbm_seat_nonce', 'nonce' );

        $product_id          = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $departure_timestamp = isset( $_POST['departure_timestamp'] ) ? absint( $_POST['departure_timestamp'] ) : 0;

        if ( ! $product_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid Product ID.', 'vs-bus-booking-manager' ) ) );
        }

        // Get reserved seats using the Correct Method from Reservations Class
        if ( class_exists( 'VSBBM_Seat_Reservations' ) ) {
            // FIX: Using the time-aware method name
            $reserved_seats = VSBBM_Seat_Reservations::get_reserved_seats_by_product_and_time( $product_id, $departure_timestamp );
        } else {
            $reserved_seats = array();
        }

        $all_seats = self::get_seat_numbers( $product_id );

        // Return array keys of reserved seats
        wp_send_json_success( array(
            'reserved'  => array_keys( $reserved_seats ),
            'all_seats' => $all_seats,
        ));
    }

    /**
     * AJAX: Get Passenger Fields.
     */
    /**
     * AJAX: Get Passenger Fields (Updated for Gender & Validation)
     */
    /**
     * AJAX: Get Passenger Fields (Dynamic from Database)
     */
    public static function get_passenger_fields_ajax() {
        $saved_fields = get_option('vsbbm_passenger_fields');
        $blacklist_target = get_option('vsbbm_blacklist_target_field', 'کد ملی'); // فیلد هدف

        // ... (کد ساخت فیلدهای پیش‌فرض اگر خالی بود) ...

        $frontend_fields = array();
        foreach ($saved_fields as $field) {
            $field_data = array(
                'type'        => sanitize_text_field($field['type']),
                'label'       => sanitize_text_field($field['label']),
                'placeholder' => isset($field['placeholder']) ? sanitize_text_field($field['placeholder']) : '',
                'required'    => !empty($field['required']),
            );

            // Options handling...
            if ($field['type'] === 'select' && !empty($field['options'])) {
                 // ... (کد قبلی تبدیل آپشن‌ها) ...
                 $field_data['options'] = array_map('trim', explode(',', $field['options']));
            }

            // *** تغییر جدید: تعیین فیلد لیست سیاه ***
            // اگر لیبل این فیلد برابر با فیلد هدف انتخاب شده در ادمین بود
            if ($field['label'] === $blacklist_target) {
                $field_data['validation'] = 'blacklist_check'; // تگ جدید
                $field_data['required'] = true; // همیشه اجباری می‌شود
                
                // اگر اسمش کد ملی بود، اعتبارسنجی الگوریتم ۱۰ رقمی هم اضافه کن
                if (strpos($field['label'], 'کد ملی') !== false) {
                    $field_data['validation_type'] = 'national_code';
                }
            }

            $frontend_fields[] = $field_data;
        }

        wp_send_json_success($frontend_fields);
    }

    /**
     * AJAX: Add to Cart.
     */
    /**
     * AJAX: Add to Cart with Dynamic Blacklist Validation.
     */
    public static function add_to_cart_ajax() {
        // 1. Security Check
        check_ajax_referer( 'vsbbm_seat_nonce', 'nonce' );

        // 2. Sanitize & Decode Inputs
        $product_id          = absint( $_POST['product_id'] );
        $departure_timestamp = absint( $_POST['vsbbm_departure_timestamp'] ?? 0 );
        
        $selected_seats  = json_decode( wp_unslash( $_POST['selected_seats'] ), true );
        $passengers_data = json_decode( wp_unslash( $_POST['passengers_data'] ), true );

        // Basic Validation
        if ( empty( $selected_seats ) ) {
            wp_send_json_error( array( 'message' => __( 'No seats selected.', 'vs-bus-booking-manager' ) ) );
        }

        if ( ! class_exists( 'VSBBM_Seat_Reservations' ) ) {
            wp_send_json_error( array( 'message' => __( 'Reservation module missing.', 'vs-bus-booking-manager' ) ) );
        }

        // 3. Dynamic Blacklist Check
        if ( class_exists( 'VSBBM_Blacklist' ) ) {
            // دریافت فیلدی که مدیر برای لیست سیاه تعیین کرده است (پیش‌فرض: کد ملی)
            $blacklist_target_label = get_option( 'vsbbm_blacklist_target_field', 'کد ملی' );

            foreach ( $passengers_data as $passenger ) {
                // بررسی می‌کنیم آیا این مسافر فیلد مورد نظر را پر کرده است؟
                // کلیدهای آرایه passenger برابر با Label فیلدها هستند
                if ( ! empty( $passenger[ $blacklist_target_label ] ) ) {
                    $value_to_check = sanitize_text_field( $passenger[ $blacklist_target_label ] );

                    // استعلام از کلاس لیست سیاه
                    if ( VSBBM_Blacklist::is_blacklisted( $value_to_check ) ) {
                        wp_send_json_error( array( 
                            'message' => sprintf( 
                                // پیام خطا: مسافر با [کد ملی] [1234567890] در لیست سیاه است
                                __( 'Booking blocked: Passenger with %s "%s" is in the blacklist.', 'vs-bus-booking-manager' ), 
                                $blacklist_target_label, 
                                $value_to_check 
                            ) 
                        ) );
                    }
                }
            }
        }

        // 4. Reserve Seats in Database
        $reservation_keys = array();
        foreach ( $selected_seats as $seat_number ) {
            $seat_number = sanitize_text_field( $seat_number );
            
            // پیدا کردن اطلاعات مسافر مربوط به این صندلی (برای ذخیره در جدول رزرو)
            $seat_passenger_info = array();
            foreach ( $passengers_data as $p ) {
                if ( isset( $p['seat_number'] ) && (string)$p['seat_number'] === (string)$seat_number ) {
                    $seat_passenger_info = $p;
                    break;
                }
            }

            // انجام عملیات رزرو
            $res_id = VSBBM_Seat_Reservations::reserve_seat( 
                $product_id, 
                $seat_number, 
                $departure_timestamp, 
                null, // order_id فعلا نداریم
                null, // user_id (current user)
                $seat_passenger_info // ذخیره دیتای مسافر در جدول رزرو
            );
            
            // مدیریت خطای رزرو (مثلاً اگر صندلی در لحظه پر شد)
            if ( is_wp_error( $res_id ) ) {
                // لغو رزروهای قبلی همین تراکنش (Rollback)
                if ( ! empty( $reservation_keys ) ) {
                    VSBBM_Seat_Reservations::cancel_reservations_by_keys( $reservation_keys );
                }
                wp_send_json_error( array( 'message' => $res_id->get_error_message(), 'code' => 'seat_not_available' ) );
            }
            $reservation_keys[] = $res_id;
        }

        // 5. Add to WooCommerce Cart
        $cart_item_data = array(
            'vsbbm_reservation_keys'    => $reservation_keys,
            'vsbbm_departure_timestamp' => $departure_timestamp,
            'vsbbm_seats'               => $selected_seats,
            'vsbbm_passengers'          => $passengers_data,
        );

        $added = WC()->cart->add_to_cart( $product_id, count( $selected_seats ), 0, array(), $cart_item_data );

        if ( $added ) {
            wp_send_json_success( array(
                'message'      => __( 'Added to cart.', 'vs-bus-booking-manager' ),
                'cart_url'     => wc_get_cart_url(),
                'checkout_url' => wc_get_checkout_url(),
            ));
        } else {
            // اگر ووکامرس خطا داد، رزروها را از دیتابیس پاک کن
            VSBBM_Seat_Reservations::cancel_reservations_by_keys( $reservation_keys );
            wp_send_json_error( array( 'message' => __( 'Error adding to cart.', 'vs-bus-booking-manager' ) ) );
        }
    }

    /* ==========================================================================
       HELPERS
       ========================================================================== */

    public static function is_seat_booking_enabled( $product_id ) {
        return 'yes' === get_post_meta( $product_id, '_vsbbm_enable_seat_booking', true );
    }

    public static function get_schedule_settings( $product_id ) {
        return array(
            'enable_schedule'  => get_post_meta( $product_id, '_vsbbm_enable_schedule', true ) ?: 'no',
            'schedule_type'    => get_post_meta( $product_id, '_vsbbm_schedule_type', true ) ?: 'everyday',
            'allowed_weekdays' => get_post_meta( $product_id, '_vsbbm_allowed_weekdays', true ) ?: array(),
            'specific_dates'   => get_post_meta( $product_id, '_vsbbm_specific_dates', true ) ?: array(),
            'departure_time'   => get_post_meta( $product_id, '_vsbbm_departure_time', true ) ?: '12:00',
            'min_days_advance' => get_post_meta( $product_id, '_vsbbm_min_days_advance', true ) ?: 0,
            'max_days_advance' => get_post_meta( $product_id, '_vsbbm_max_days_advance', true ) ?: 365,
        );
    }

    public static function get_product_settings( $product_id ) {
        return array(
            'rows'    => (int) get_post_meta( $product_id, '_vsbbm_bus_rows', true ) ?: 10,
            'columns' => (int) get_post_meta( $product_id, '_vsbbm_bus_columns', true ) ?: 2,
            'type'    => get_post_meta( $product_id, '_vsbbm_bus_type', true ) ?: '2x2',
        );
    }

    public static function get_seat_numbers( $product_id ) {
        $seats = get_post_meta( $product_id, '_vsbbm_seat_numbers', true );
        return is_array( $seats ) ? $seats : array();
    }
    
    // Legacy helper for Booking Handler if needed
    public static function get_reserved_seats( $product_id, $departure_timestamp = null ) {
        if ( ! class_exists( 'VSBBM_Seat_Reservations' ) ) return array();
        if ( empty( $departure_timestamp ) ) {
            $settings = self::get_schedule_settings( $product_id );
            list( $hour, $minute ) = explode( ':', $settings['departure_time'] );
            $departure_timestamp = strtotime( date( 'Y-m-d' ) . " $hour:$minute" );
        }
        return VSBBM_Seat_Reservations::get_reserved_seats_by_product_and_time( $product_id, $departure_timestamp );
    }
}