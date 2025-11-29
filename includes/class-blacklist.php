<?php
/**
 * Class VSBBM_Blacklist
 *
 * Handles the logic for the passenger blacklist system.
 *
 * @package VSBBM
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class VSBBM_Blacklist {

    /**
     * Initialize the class.
     * Note: Menu registration is handled by VSBBM_Admin_Interface.
     */
    public static function init() {
        // Hooks related to blacklist logic can be added here in the future.
    }

    /**
     * Create the blacklist table.
     */
    public static function create_table() {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'vsbbm_blacklist';
        $charset_collate = $wpdb->get_charset_collate();

        // SQL syntax specifically formatted for dbDelta (2 spaces after PRIMARY KEY)
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            national_code VARCHAR(20) NOT NULL,
            reason TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY national_code (national_code)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Render the admin page content.
     * Called by VSBBM_Admin_Interface.
     */
    public static function render_admin_page() {
        // 1. Security Check
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'vs-bus-booking-manager' ) );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'vsbbm_blacklist';

        // 2. Handle Form Submission (Add)
        if ( isset( $_POST['vsbbm_add_blacklist'] ) && check_admin_referer( 'vsbbm_add_blacklist_action', 'vsbbm_nonce_field' ) ) {
            $national_code = sanitize_text_field( $_POST['national_code'] );
            $reason        = sanitize_textarea_field( $_POST['reason'] );

            if ( $national_code ) {
                // Check format (basic 10 digits check)
                if ( ! preg_match( '/^[0-9]{10}$/', $national_code ) ) {
                    echo '<div class="notice notice-error"><p>' . esc_html__( 'Invalid National ID format. It must be 10 digits.', 'vs-bus-booking-manager' ) . '</p></div>';
                } else {
                    $result = $wpdb->replace( // Use replace to handle duplicates gracefully or update reason
                        $table_name,
                        array(
                            'national_code' => $national_code,
                            'reason'        => $reason,
                        ),
                        array( '%s', '%s' )
                    );

                    if ( false !== $result ) {
                        echo '<div class="notice notice-success"><p>' . esc_html__( 'National ID added to blacklist successfully.', 'vs-bus-booking-manager' ) . '</p></div>';
                        // Clear cache
                        wp_cache_delete( 'blacklist_' . $national_code, 'vsbbm_blacklist' );
                    } else {
                        echo '<div class="notice notice-error"><p>' . esc_html__( 'Error adding National ID.', 'vs-bus-booking-manager' ) . '</p></div>';
                    }
                }
            }
        }

        // 3. Handle Deletion
        if ( isset( $_GET['delete'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'vsbbm_delete_blacklist' ) ) {
            $id = absint( $_GET['delete'] );
            
            // Get national code before delete for cache clearing
            $nc = $wpdb->get_var( $wpdb->prepare( "SELECT national_code FROM $table_name WHERE id = %d", $id ) );
            
            $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );
            
            if ( $nc ) {
                wp_cache_delete( 'blacklist_' . $nc, 'vsbbm_blacklist' );
            }
            
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Record deleted successfully.', 'vs-bus-booking-manager' ) . '</p></div>';
        }

        // 4. Retrieve Data
        $blacklist = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY created_at DESC" );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Blacklist Management', 'vs-bus-booking-manager' ); ?></h1>
            <hr class="wp-header-end">

            <!-- Add New Form -->
            <div class="card vsbbm-form-card">
                <h2><?php esc_html_e( 'Add New National ID', 'vs-bus-booking-manager' ); ?></h2>
                <form method="post">
                    <?php wp_nonce_field( 'vsbbm_add_blacklist_action', 'vsbbm_nonce_field' ); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="national_code"><?php esc_html_e( 'National ID', 'vs-bus-booking-manager' ); ?></label></th>
                            <td>
                                <input type="text" name="national_code" id="national_code" 
                                       class="regular-text" required pattern="[0-9]{10}" 
                                       title="<?php esc_attr_e( 'Must be 10 digits', 'vs-bus-booking-manager' ); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="reason"><?php esc_html_e( 'Reason (Optional)', 'vs-bus-booking-manager' ); ?></label></th>
                            <td>
                                <textarea name="reason" id="reason" class="large-text" rows="3"></textarea>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" name="vsbbm_add_blacklist" class="button button-primary"><?php esc_html_e( 'Add to Blacklist', 'vs-bus-booking-manager' ); ?></button>
                    </p>
                </form>
            </div>

            <br>

            <!-- List Table -->
            <h2><?php esc_html_e( 'Blacklisted IDs', 'vs-bus-booking-manager' ); ?></h2>
            <table class="wp-list-table widefat fixed striped table-view-list">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'National ID', 'vs-bus-booking-manager' ); ?></th>
                        <th><?php esc_html_e( 'Reason', 'vs-bus-booking-manager' ); ?></th>
                        <th><?php esc_html_e( 'Date Added', 'vs-bus-booking-manager' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'vs-bus-booking-manager' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $blacklist ) ) : ?>
                        <?php foreach ( $blacklist as $item ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $item->national_code ); ?></strong></td>
                                <td><?php echo esc_html( $item->reason ? $item->reason : '-' ); ?></td>
                                <td><?php echo esc_html( date_i18n( 'Y/m/d H:i', strtotime( $item->created_at ) ) ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=vsbbm-blacklist&delete=' . $item->id ), 'vsbbm_delete_blacklist' ) ); ?>" 
                                       class="button button-small button-link-delete" 
                                       onclick="return confirm('<?php esc_attr_e( 'Are you sure?', 'vs-bus-booking-manager' ); ?>')">
                                        <?php esc_html_e( 'Delete', 'vs-bus-booking-manager' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="4" style="text-align: center;"><?php esc_html_e( 'No records found.', 'vs-bus-booking-manager' ); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <style>
            .vsbbm-form-card {
                background: #fff;
                padding: 1px 20px 20px; /* Adjust padding for card look */
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                max-width: 800px;
            }
        </style>
        <?php
    }

    /**
     * Check if a National ID is blacklisted.
     * Uses caching to reduce DB hits during multi-seat booking.
     *
     * @param string $national_code The ID to check.
     * @return bool True if blacklisted, false otherwise.
     */
    public static function is_blacklisted( $national_code ) {
        if ( empty( $national_code ) ) {
            return false;
        }

        $national_code = sanitize_text_field( $national_code );
        $cache_key     = 'blacklist_' . $national_code;
        $cache_group   = 'vsbbm_blacklist';

        // Check Cache
        $cached_status = wp_cache_get( $cache_key, $cache_group );
        if ( false !== $cached_status ) {
            return (bool) $cached_status;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'vsbbm_blacklist';

        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE national_code = %s",
            $national_code
        ));

        $is_listed = ( $count > 0 );

        // Set Cache (Expire in 1 hour)
        wp_cache_set( $cache_key, $is_listed, $cache_group, 3600 );

        return $is_listed;
    }
}