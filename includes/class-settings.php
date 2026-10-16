<?php
/**
 * Class VSBBM_Settings
 *
 * Centralized settings manager for VS Bus Booking Manager.
 *
 * تمام get_option/update_option های افزونه باید از این کلاس عبور کنند
 * تا یک نقطه واحد برای مدیریت تنظیمات داشته باشیم. این کلاس:
 *   - کش درون‌حافظه‌ای (in-memory cache) دارد تا تکرار کوئری دیتابیس حذف شود
 *   - مقادیر پیش‌فرض را متمرکز می‌کند
 *   - type-hint شده است تا استفاده از آن ایمن باشد
 *   - در آینده برای Free/Pro gating هم استفاده خواهد شد
 *
 * @package VSBBM
 * @since   2.1.0
 */

defined( 'ABSPATH' ) || exit;

class VSBBM_Settings {

    /**
     * Singleton instance.
     *
     * @var VSBBM_Settings|null
     */
    private static $instance = null;

    /**
     * In-memory cache for settings (avoids repeated DB queries within the same request).
     *
     * @var array
     */
    private $cache = array();

    /**
     * Default values for all settings.
     * این مقادیر در صورت نبود تنظیم در دیتابیس استفاده می‌شوند.
     *
     * @var array
     */
    private $defaults = array(
        // Passenger fields
        'passenger_fields'         => array(
            array( 'type' => 'text', 'label' => 'نام کامل', 'required' => true ),
            array( 'type' => 'text', 'label' => 'کد ملی', 'required' => true ),
            array( 'type' => 'tel', 'label' => 'شماره تماس', 'required' => false ),
        ),
        'blacklist_target_field'   => 'کد ملی',

        // Cache
        'cache_enabled'            => true,
        'cache_ttl'                => 300, // 5 minutes
        'cache_max_keys'           => 1000,

        // Email & SMS (loaded as arrays from their own options)
        'email_settings'           => array(),
        'sms_settings'             => array(),

        // License
        'license_data'             => array(),
        'license_last_check'       => 0,
    );

    /**
     * Get the singleton instance.
     *
     * @return VSBBM_Settings
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor (private for singleton).
     */
    private function __construct() {
        // Hook into option update to invalidate our in-memory cache
        add_action( 'updated_option', array( $this, 'invalidate_cache_on_update' ), 10, 3 );
        add_action( 'added_option', array( $this, 'invalidate_cache_on_add' ), 10, 2 );
    }

    /**
     * Get a setting value.
     *
     * @param string $key     Setting key (without 'vsbbm_' prefix).
     * @param mixed  $default Override default value (optional).
     * @return mixed
     */
    public function get( $key, $default = null ) {
        // Check in-memory cache first
        if ( array_key_exists( $key, $this->cache ) ) {
            return $this->cache[ $key ];
        }

        $option_name = $this->get_option_name( $key );
        $value       = get_option( $option_name, null );

        // If not in DB, use default
        if ( null === $value ) {
            $value = array_key_exists( $key, $this->defaults ) ? $this->defaults[ $key ] : null;
        }

        // Override with provided default if still null
        if ( null === $value && null !== $default ) {
            $value = $default;
        }

        // Cache it
        $this->cache[ $key ] = $value;

        return $value;
    }

    /**
     * Update a setting value.
     *
     * @param string $key   Setting key (without 'vsbbm_' prefix).
     * @param mixed  $value New value.
     * @return bool True on success.
     */
    public function set( $key, $value ) {
        $option_name = $this->get_option_name( $key );
        $result      = update_option( $option_name, $value );

        if ( $result ) {
            // Update in-memory cache
            $this->cache[ $key ] = $value;
        }

        return $result;
    }

    /**
     * Delete a setting.
     *
     * @param string $key Setting key (without 'vsbbm_' prefix).
     * @return bool True on success.
     */
    public function delete( $key ) {
        $option_name = $this->get_option_name( $key );
        $result      = delete_option( $option_name );

        if ( $result ) {
            unset( $this->cache[ $key ] );
        }

        return $result;
    }

    /**
     * Get the full option name (with prefix).
     *
     * @param string $key Setting key.
     * @return string Full option name (e.g. 'vsbbm_cache_ttl').
     */
    private function get_option_name( $key ) {
        return 'vsbbm_' . $key;
    }

    /**
     * Invalidate in-memory cache when an option is updated.
     *
     * @param string $option_name Full option name.
     * @param mixed  $old_value   Old value.
     * @param mixed  $new_value   New value.
     */
    public function invalidate_cache_on_update( $option_name, $old_value, $new_value ) {
        if ( 0 === strpos( $option_name, 'vsbbm_' ) ) {
            $key = substr( $option_name, 6 ); // remove 'vsbbm_' prefix
            unset( $this->cache[ $key ] );
        }
    }

    /**
     * Invalidate in-memory cache when an option is added.
     *
     * @param string $option_name Full option name.
     * @param mixed  $value       Value.
     */
    public function invalidate_cache_on_add( $option_name, $value ) {
        if ( 0 === strpos( $option_name, 'vsbbm_' ) ) {
            $key = substr( $option_name, 6 );
            unset( $this->cache[ $key ] );
        }
    }

    /**
     * Clear all in-memory cache (useful for testing or force-refresh).
     */
    public function clear_cache() {
        $this->cache = array();
    }

    // =========================================================================
    // Convenience methods for commonly-used settings
    // =========================================================================

    /**
     * Get passenger fields configuration.
     *
     * @return array
     */
    public function get_passenger_fields() {
        $fields = $this->get( 'passenger_fields' );
        return is_array( $fields ) ? $fields : $this->defaults['passenger_fields'];
    }

    /**
     * Get the field label used for blacklist checking.
     *
     * @return string
     */
    public function get_blacklist_target_field() {
        return $this->get( 'blacklist_target_field' );
    }

    /**
     * Check if caching is enabled.
     *
     * @return bool
     */
    public function is_cache_enabled() {
        return (bool) $this->get( 'cache_enabled' );
    }

    /**
     * Get cache TTL in seconds.
     *
     * @return int
     */
    public function get_cache_ttl() {
        return (int) $this->get( 'cache_ttl' );
    }

    /**
     * Get email settings.
     *
     * @return array
     */
    public function get_email_settings() {
        $settings = $this->get( 'email_settings' );
        return is_array( $settings ) ? $settings : array();
    }

    /**
     * Get a single email setting by key.
     *
     * @param string $key     Setting key within email settings.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public function get_email_setting( $key, $default = null ) {
        $settings = $this->get_email_settings();
        return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
    }

    /**
     * Get SMS settings.
     *
     * @return array
     */
    public function get_sms_settings() {
        $settings = $this->get( 'sms_settings' );
        return is_array( $settings ) ? $settings : array();
    }

    /**
     * Get a single SMS setting by key.
     *
     * @param string $key     Setting key within SMS settings.
     * @param mixed  $default Default value.
     * @return mixed
     */
    public function get_sms_setting( $key, $default = null ) {
        $settings = $this->get_sms_settings();
        return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
    }

    /**
     * Get license data.
     *
     * @return array
     */
    public function get_license_data() {
        $data = $this->get( 'license_data' );
        return is_array( $data ) ? $data : array();
    }

    /**
     * Check if license is active (Pro features enabled).
     *
     * This is the GATING method for Pro features. In Phase 2, all Pro-only
     * code paths will check this method before executing.
     *
     * @return bool
     */
    public function is_pro_active() {
        $license = $this->get_license_data();

        if ( empty( $license ) || empty( $license['active'] ) ) {
            return false;
        }

        // Check expiration
        if ( ! empty( $license['expires_at'] ) ) {
            $expires = strtotime( $license['expires_at'] );
            if ( $expires && $expires < time() ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get all settings (for debugging/admin display).
     *
     * @return array
     */
    public function get_all() {
        $all = array();
        foreach ( array_keys( $this->defaults ) as $key ) {
            $all[ $key ] = $this->get( $key );
        }
        return $all;
    }
}