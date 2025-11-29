<?php
/**
 * Class VSBBM_License_Manager
 *
 * Handles product licensing, activation, and remote verification.
 *
 * @package VSBBM
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class VSBBM_License_Manager {

    /**
     * Singleton instance.
     */
    private static $instance = null;

    /**
     * API Endpoint.
     */
    private $license_server = 'https://api.vernasoft.ir/v1/license';

    /**
     * Product ID.
     */
    private $product_id = 'vsbbm-pro';

    /**
     * Option key for storing license data.
     */
    private $option_key = 'vsbbm_license_data';

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
        add_action( 'admin_init', array( $this, 'check_license_status' ) );
        add_action( 'admin_notices', array( $this, 'display_license_notices' ) );
        
        add_action( 'wp_ajax_vsbbm_activate_license', array( $this, 'ajax_activate_license' ) );
        add_action( 'wp_ajax_vsbbm_deactivate_license', array( $this, 'ajax_deactivate_license' ) );
    }

    /**
     * Check License Status on Admin Init.
     */
    public function check_license_status() {
        $license_data = $this->get_license_data();

        if ( empty( $license_data ) ) {
            return;
        }

        // Check Expiration
        if ( $this->is_license_expired( $license_data ) ) {
            $this->deactivate_license();
            return;
        }

        // Remote Verify every 24 hours
        $last_check = get_option( 'vsbbm_license_last_check', 0 );
        if ( time() - $last_check > 86400 ) { // 24 hours
            $this->verify_license_remotely();
        }
    }

    /**
     * Display Admin Notices.
     */
    public function display_license_notices() {
        // Only show to admins
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Don't show on license page itself to avoid clutter
        $screen = get_current_screen();
        if ( $screen && strpos( $screen->id, 'vsbbm-license' ) !== false ) {
            return;
        }

        $license_data = $this->get_license_data();

        if ( empty( $license_data ) ) {
            $this->render_notice( 'error', __( 'Please activate your license to use Pro features.', 'vs-bus-booking-manager' ), __( 'Activate License', 'vs-bus-booking-manager' ) );
        } elseif ( $this->is_license_expired( $license_data ) ) {
            $this->render_notice( 'error', __( 'Your license has expired. Please renew it.', 'vs-bus-booking-manager' ), __( 'Renew License', 'vs-bus-booking-manager' ) );
        } elseif ( ! $license_data['active'] ) {
            $this->render_notice( 'warning', __( 'Your license is inactive.', 'vs-bus-booking-manager' ), __( 'Activate License', 'vs-bus-booking-manager' ) );
        }
    }

    /**
     * Helper to render notice HTML.
     */
    private function render_notice( $type, $message, $button_text ) {
        $url = admin_url( 'admin.php?page=vsbbm-license' );
        ?>
        <div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
            <p>
                <strong><?php esc_html_e( 'VS Bus Booking Manager:', 'vs-bus-booking-manager' ); ?></strong> 
                <?php echo esc_html( $message ); ?>
                <a href="<?php echo esc_url( $url ); ?>" class="button button-primary" style="margin-left: 10px;"><?php echo esc_html( $button_text ); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * AJAX: Activate License.
     */
    public function ajax_activate_license() {
        check_ajax_referer( 'vsbbm_license_activation', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'vs-bus-booking-manager' ) );
        }

        $license_key = sanitize_text_field( $_POST['license_key'] );
        $email       = sanitize_email( $_POST['email'] );

        if ( empty( $license_key ) || empty( $email ) ) {
            wp_send_json_error( __( 'License key and email are required.', 'vs-bus-booking-manager' ) );
        }

        $result = $this->activate_license( $license_key, $email );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'message'      => __( 'License activated successfully.', 'vs-bus-booking-manager' ),
            'license_data' => $result,
        ) );
    }

    /**
     * AJAX: Deactivate License.
     */
    public function ajax_deactivate_license() {
        check_ajax_referer( 'vsbbm_license_deactivation', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'vs-bus-booking-manager' ) );
        }

        $result = $this->deactivate_license();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( __( 'License deactivated.', 'vs-bus-booking-manager' ) );
    }

    /**
     * Activate License Logic.
     */
    public function activate_license( $license_key, $email ) {
        $site_url = get_site_url();

        $response = wp_remote_post( $this->license_server . '/activate', array(
            'timeout' => 15,
            'body'    => array(
                'license_key' => $license_key,
                'email'       => $email,
                'site_url'    => $site_url,
                'product_id'  => $this->product_id,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'connection_error', __( 'Connection error. Please try again later.', 'vs-bus-booking-manager' ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! $data || ! isset( $data['success'] ) ) {
            return new WP_Error( 'invalid_response', __( 'Invalid response from server.', 'vs-bus-booking-manager' ) );
        }

        if ( ! $data['success'] ) {
            return new WP_Error( 'activation_failed', $data['message'] ?? __( 'Activation failed.', 'vs-bus-booking-manager' ) );
        }

        $license_data = array(
            'license_key'  => $license_key,
            'email'        => $email,
            'active'       => true,
            'expires_at'   => $data['expires_at'] ?? null,
            'activated_at' => current_time( 'mysql' ),
            'site_url'     => $site_url,
            'details'      => $data,
        );

        update_option( $this->option_key, $license_data );
        update_option( 'vsbbm_license_last_check', time() );

        return $license_data;
    }

    /**
     * Deactivate License Logic.
     */
    public function deactivate_license() {
        $license_data = $this->get_license_data();

        if ( ! empty( $license_data ) && $license_data['active'] ) {
            wp_remote_post( $this->license_server . '/deactivate', array(
                'timeout' => 10,
                'body'    => array(
                    'license_key' => $license_data['license_key'],
                    'site_url'    => get_site_url(),
                ),
            ) );
        }

        delete_option( $this->option_key );
        delete_option( 'vsbbm_license_last_check' );

        return true;
    }

    /**
     * Verify License Remotely.
     */
    private function verify_license_remotely() {
        $license_data = $this->get_license_data();

        if ( empty( $license_data ) ) {
            return;
        }

        $response = wp_remote_post( $this->license_server . '/verify', array(
            'timeout' => 10,
            'body'    => array(
                'license_key' => $license_data['license_key'],
                'site_url'    => get_site_url(),
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            // Fail-safe: Don't deactivate on connection error, just skip
            return;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! $data || ! isset( $data['success'] ) ) {
            return;
        }

        if ( ! $data['success'] ) {
            $this->deactivate_license();
        } else {
            $license_data['details'] = $data;
            update_option( $this->option_key, $license_data );
        }

        update_option( 'vsbbm_license_last_check', time() );
    }

    /**
     * Get License Data.
     */
    public function get_license_data() {
        return get_option( $this->option_key, array() );
    }

    /**
     * Check if license is active.
     */
    public function is_license_active() {
        $license_data = $this->get_license_data();

        if ( empty( $license_data ) || ! $license_data['active'] ) {
            return false;
        }

        if ( $this->is_license_expired( $license_data ) ) {
            return false;
        }

        return true;
    }

    /**
     * Check Expiration.
     */
    private function is_license_expired( $license_data ) {
        if ( empty( $license_data['expires_at'] ) ) {
            return false; // Lifetime
        }
        return strtotime( $license_data['expires_at'] ) < time();
    }

    /**
     * Get Info for UI Display.
     */
    public function get_license_info() {
        $license_data = $this->get_license_data();

        if ( empty( $license_data ) ) {
            return array(
                'status'  => 'inactive',
                'message' => __( 'License is inactive.', 'vs-bus-booking-manager' ),
            );
        }

        $status  = 'active';
        $message = __( 'License is active.', 'vs-bus-booking-manager' );

        if ( ! $license_data['active'] ) {
            $status  = 'inactive';
            $message = __( 'License is inactive.', 'vs-bus-booking-manager' );
        } elseif ( $this->is_license_expired( $license_data ) ) {
            $status  = 'expired';
            $message = __( 'License has expired.', 'vs-bus-booking-manager' );
        }

        return array(
            'status'       => $status,
            'message'      => $message,
            'license_key'  => substr( $license_data['license_key'], 0, 8 ) . '****',
            'email'        => $license_data['email'],
            'expires_at'   => $license_data['expires_at'] ?? __( 'Lifetime', 'vs-bus-booking-manager' ),
            'activated_at' => $license_data['activated_at'],
            'site_url'     => $license_data['site_url'],
        );
    }
}

// Initialize
VSBBM_License_Manager::get_instance();