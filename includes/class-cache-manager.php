<?php
/**
 * Class VSBBM_Cache_Manager
 *
 * Handles data caching strategies to improve performance.
 * Uses both WP Object Cache (memory) and Transients API (database/persistent).
 *
 * @package VSBBM
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class VSBBM_Cache_Manager {

    /**
     * Singleton instance
     *
     * @var VSBBM_Cache_Manager|null
     */
    private static $instance = null;

    /**
     * Cache key prefix
     *
     * @var string
     */
    private $cache_prefix = 'vsbbm_';

    /**
     * Default TTL in seconds
     *
     * @var int
     */
    private $default_ttl = 300; // 5 minutes

    /**
     * Cache group name
     *
     * @var string
     */
    private $cache_group = 'vsbbm';

    /**
     * Get the singleton instance.
     *
     * @return VSBBM_Cache_Manager
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
        $this->load_settings();
        add_action( 'init', array( $this, 'init_hooks' ) );
    }

    /**
     * Load settings from DB.
     */
    private function load_settings() {
        $this->default_ttl = (int) get_option( 'vsbbm_cache_ttl', 300 );
        
        // Register non-persistent groups if external object cache is active
        if ( function_exists( 'wp_cache_add_non_persistent_groups' ) ) {
            wp_cache_add_non_persistent_groups( array( 'vsbbm' ) );
        }
    }

    /**
     * Initialize hooks.
     */
    public function init_hooks() {
        // Clear cache on data updates
        add_action( 'woocommerce_order_status_changed', array( $this, 'clear_order_related_cache' ), 10, 3 );
        add_action( 'save_post_product', array( $this, 'clear_product_cache_hook' ), 10, 3 );
        add_action( 'vsbbm_seat_reserved', array( $this, 'clear_seat_cache' ), 10, 2 );
        add_action( 'vsbbm_seat_cancelled', array( $this, 'clear_seat_cache' ), 10, 2 );
        add_action( 'vsbbm_ticket_used', array( $this, 'clear_ticket_cache_hook' ), 10, 1 );
    }

    /**
     * Get data from cache.
     * Strategy: Memory (Object Cache) -> Persistent (Transient) -> Default
     *
     * @param string $key     Cache key.
     * @param mixed  $default Default value if not found.
     * @return mixed
     */
    public function get( $key, $default = null ) {
        if ( ! $this->is_cache_enabled() ) {
            return $default;
        }

        $cache_key = $this->cache_prefix . $key;

        // 1. Check Object Cache (Memory - Fast)
        $cached = wp_cache_get( $cache_key, $this->cache_group );
        if ( false !== $cached ) {
            $this->increment_cache_hit();
            return $cached;
        }

        // 2. Check Transient (Database/Redis - Persistent)
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) {
            // Populate Object Cache for subsequent requests
            wp_cache_set( $cache_key, $cached, $this->cache_group, $this->default_ttl );
            $this->increment_cache_hit();
            return $cached;
        }

        return $default;
    }

    /**
     * Set data in cache.
     *
     * @param string   $key   Cache key.
     * @param mixed    $value Data to cache.
     * @param int|null $ttl   Time to live in seconds.
     * @return bool
     */
    public function set( $key, $value, $ttl = null ) {
        if ( ! $this->is_cache_enabled() ) {
            return false;
        }

        if ( null === $ttl ) {
            $ttl = $this->default_ttl;
        }

        $cache_key = $this->cache_prefix . $key;

        // Save to Object Cache
        wp_cache_set( $cache_key, $value, $this->cache_group, $ttl );

        // Save to Transient (for persistence across requests if no external object cache)
        set_transient( $cache_key, $value, $ttl );

        return true;
    }

    /**
     * Delete data from cache.
     *
     * @param string $key Cache key.
     * @return bool
     */
    public function delete( $key ) {
        $cache_key = $this->cache_prefix . $key;

        wp_cache_delete( $cache_key, $this->cache_group );
        delete_transient( $cache_key );

        return true;
    }

    /**
     * Delete a group of cache keys based on pattern.
     * Note: This is expensive on DB based transients.
     *
     * @param string $group_pattern Pattern to match.
     * @return bool
     */
    public function delete_group( $group_pattern ) {
        global $wpdb;

        // 1. Delete Transients (DB)
        // Note: _transient_vsbbm_pattern%
        $transient_pattern = '_transient_' . $this->cache_prefix . $group_pattern . '%';
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $transient_pattern
        ) );

        // 2. Flush Object Cache Group (if supported)
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( $this->cache_group );
        } else {
            // Fallback: We cannot easily wildcard delete from memory in standard WP Object Cache
            // without a dedicated Redis/Memcached plugin that supports it.
            // For standard installs, flushing entire cache might be overkill, so we rely on TTL.
        }

        return true;
    }

    /**
     * Cache Product Data.
     *
     * @param int $product_id Product ID.
     * @return array|false
     */
    public function get_product_data( $product_id ) {
        $key    = 'product_' . $product_id;
        $cached = $this->get( $key );

        if ( false === $cached ) {
            if ( ! function_exists( 'wc_get_product' ) ) {
                return false;
            }

            $product = wc_get_product( $product_id );
            if ( $product ) {
                $cached = array(
                    'id'            => $product->get_id(),
                    'name'          => $product->get_name(),
                    'price'         => $product->get_price(),
                    'regular_price' => $product->get_regular_price(),
                    'sale_price'    => $product->get_sale_price(),
                    'status'        => $product->get_status(),
                    'meta'          => array(
                        '_vsbbm_enable_seat_booking' => get_post_meta( $product_id, '_vsbbm_enable_seat_booking', true ),
                        '_vsbbm_sale_start_date'     => get_post_meta( $product_id, '_vsbbm_sale_start_date', true ),
                        '_vsbbm_sale_end_date'       => get_post_meta( $product_id, '_vsbbm_sale_end_date', true ),
                        '_vsbbm_seat_numbers'        => get_post_meta( $product_id, '_vsbbm_seat_numbers', true ),
                    ),
                );
                $this->set( $key, $cached, 600 ); // 10 minutes
            }
        }

        return $cached;
    }

    /**
     * Cache Reserved Seats.
     *
     * @param int $product_id Product ID.
     * @return array
     */
    public function get_reserved_seats( $product_id ) {
        $key    = 'reserved_seats_' . $product_id;
        $cached = $this->get( $key );

        if ( false === $cached ) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'vsbbm_seat_reservations';

            // Check if table exists before query to avoid errors on activation
            if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) != $table_name ) {
                return array();
            }

            $reservations = $wpdb->get_results( $wpdb->prepare(
                "SELECT seat_number FROM $table_name WHERE product_id = %d AND status = 'active'",
                $product_id
            ) );

            $cached = array_map( function( $row ) {
                return (string) $row->seat_number; // Seat numbers can be string (1A, 1B)
            }, $reservations );

            $this->set( $key, $cached, 300 ); // 5 minutes
        }

        return $cached ?: array();
    }

    /**
     * Cache Booking Stats.
     *
     * @return array
     */
    public function get_booking_stats() {
        $key    = 'booking_stats';
        $cached = $this->get( $key );

        if ( false === $cached ) {
            global $wpdb;

            $total_orders = $wpdb->get_var( "
                SELECT COUNT(*) FROM {$wpdb->posts}
                WHERE post_type = 'shop_order'
                AND post_status IN ('wc-completed', 'wc-processing')
            " );

            $total_revenue = $wpdb->get_var( "
                SELECT SUM(meta_value) FROM {$wpdb->postmeta} pm
                JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = '_order_total'
                AND p.post_type = 'shop_order'
                AND p.post_status IN ('wc-completed', 'wc-processing')
            " );

            $current_month  = date( 'Y-m' );
            $monthly_orders = $wpdb->get_var( $wpdb->prepare( "
                SELECT COUNT(*) FROM {$wpdb->posts}
                WHERE post_type = 'shop_order'
                AND post_status IN ('wc-completed', 'wc-processing')
                AND DATE_FORMAT(post_date, '%%Y-%%m') = %s
            ", $current_month ) );

            $cached = array(
                'total_orders'   => (int) $total_orders,
                'total_revenue'  => (float) $total_revenue,
                'monthly_orders' => (int) $monthly_orders,
                'last_updated'   => current_time( 'timestamp' ),
            );

            $this->set( $key, $cached, 1800 ); // 30 minutes
        }

        return $cached;
    }

    /**
     * Cache Active Products List.
     *
     * @param bool $force_refresh Force DB query.
     * @return array List of product IDs.
     */
    public function get_active_products( $force_refresh = false ) {
        $key    = 'active_products';
        $cached = $force_refresh ? false : $this->get( $key );

        if ( false === $cached ) {
            $args = array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'meta_query'     => array(
                    array(
                        'key'     => '_vsbbm_enable_seat_booking',
                        'value'   => 'yes',
                        'compare' => '=',
                    ),
                ),
                'fields'         => 'ids',
            );

            $query  = new WP_Query( $args );
            $cached = $query->posts;

            $this->set( $key, $cached, 600 ); // 10 minutes
        }

        return $cached;
    }

    /**
     * Hooks: Clear Order Related Cache.
     */
    public function clear_order_related_cache( $order_id, $old_status, $new_status ) {
        $this->delete( 'booking_stats' );
        $this->delete( 'order_tickets_' . $order_id );
        // We might want to be more specific here in Phase 2
        $this->delete_group( 'reserved_seats_' );
        $this->delete_group( 'product_' );
    }

    /**
     * Hooks: Clear Product Cache.
     */
    public function clear_product_cache_hook( $post_id, $post, $update ) {
        if ( 'product' === $post->post_type ) {
            $this->delete( 'product_' . $post_id );
            $this->delete( 'active_products' );
            $this->delete( 'reserved_seats_' . $post_id );
        }
    }

    /**
     * Hooks: Clear Seat Cache.
     */
    public function clear_seat_cache( $product_id, $seat_number ) {
        $this->delete( 'reserved_seats_' . $product_id );
    }

    /**
     * Hooks: Clear Ticket Cache.
     */
    public function clear_ticket_cache_hook( $ticket_id ) {
        $this->delete_group( 'order_tickets_' );
    }

    // --- Manual Clear Methods ---

    public function clear_product_cache( $product_id = null ) {
        if ( $product_id ) {
            $this->delete( 'product_' . $product_id );
            $this->delete( 'reserved_seats_' . $product_id );
        } else {
            $this->delete_group( 'product_' );
            $this->delete( 'active_products' );
        }
        update_option( 'vsbbm_last_cache_clear', current_time( 'mysql' ) );
    }

    public function clear_reservation_cache() {
        $this->delete_group( 'reserved_seats_' );
        update_option( 'vsbbm_last_cache_clear', current_time( 'mysql' ) );
    }

    public function clear_ticket_cache() {
        $this->delete_group( 'order_tickets_' );
        update_option( 'vsbbm_last_cache_clear', current_time( 'mysql' ) );
    }

    public function clear_stats_cache() {
        $this->delete( 'booking_stats' );
        update_option( 'vsbbm_last_cache_clear', current_time( 'mysql' ) );
    }

    public function clear_all_cache() {
        $this->delete_group( '' ); // Try to delete all prefixed transients
        wp_cache_flush(); // Flush object cache
        update_option( 'vsbbm_last_cache_clear', current_time( 'mysql' ) );

        return array(
            'success' => true,
            'message' => __( 'All caches cleared.', 'vs-bus-booking-manager' ),
        );
    }

    // --- Helpers ---

    public function increment_cache_hit() {
        $hits = (int) get_option( 'vsbbm_cache_hits', 0 );
        update_option( 'vsbbm_cache_hits', $hits + 1 );
    }

    public function increment_total_requests() {
        $reqs = (int) get_option( 'vsbbm_cache_total_requests', 0 );
        update_option( 'vsbbm_cache_total_requests', $reqs + 1 );
    }

    private function is_cache_enabled() {
        return get_option( 'vsbbm_cache_enabled', true );
    }

    /**
     * Update settings.
     */
    public function update_settings( $settings ) {
        if ( isset( $settings['cache_enabled'] ) ) {
            update_option( 'vsbbm_cache_enabled', (bool) $settings['cache_enabled'] );
        }
        if ( isset( $settings['cache_ttl'] ) ) {
            $this->default_ttl = (int) $settings['cache_ttl'];
            update_option( 'vsbbm_cache_ttl', $this->default_ttl );
        }
        if ( isset( $settings['cache_max_keys'] ) ) {
            update_option( 'vsbbm_cache_max_keys', (int) $settings['cache_max_keys'] );
        }
    }

    /**
     * Get Cache Stats for Admin UI.
     */
    public function get_cache_stats() {
        global $wpdb;

        // Count transients directly from DB
        $transient_count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_' . $this->cache_prefix . '%'
        ) );

        $total_requests = (int) get_option( 'vsbbm_cache_total_requests', 0 );
        $cache_hits     = (int) get_option( 'vsbbm_cache_hits', 0 );
        $hit_rate       = $total_requests > 0 ? round( ( $cache_hits / $total_requests ) * 100, 1 ) : 0;
        $memory_usage   = $transient_count * 1024; // Approx 1KB per key

        $uptime_start = get_option( 'vsbbm_cache_start_time', false );
        if ( $uptime_start ) {
            $uptime_hours = round( ( current_time( 'timestamp' ) - strtotime( $uptime_start ) ) / 3600, 1 );
        } else {
            $uptime_hours = 0;
            update_option( 'vsbbm_cache_start_time', current_time( 'mysql' ) );
        }

        return array(
            'total_keys'           => (int) $transient_count,
            'hit_rate'             => $hit_rate,
            'memory_usage'         => $memory_usage,
            'uptime'               => $uptime_hours,
            'object_cache_enabled' => wp_using_ext_object_cache(),
            'last_cache_clear'     => get_option( 'vsbbm_last_cache_clear', __( 'Never', 'vs-bus-booking-manager' ) ),
        );
    }
}