<?php
/**
 * Uninstall VS Bus Booking Manager
 *
 * این فایل هنگام حذف افزونه از وردپرس اجرا می‌شود.
 * تمام جداول دیتابیس و تنظیمات را پاک می‌کند.
 *
 * @package VSBBM
 * @since   2.1.0
 */

// اگر مستقیم صدا زده شود، خروج
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// ----------------------------------------------------------------------------
// ۱. حذف جداول دیتابیس
// ----------------------------------------------------------------------------

 $tables = array(
    $wpdb->prefix . 'vsbbm_seat_reservations',
    $wpdb->prefix . 'vsbbm_tickets',
    $wpdb->prefix . 'vsbbm_blacklist',
    $wpdb->prefix . 'vsbbm_api_tokens',
);

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS $table" );
}

// ----------------------------------------------------------------------------
// ۲. حذف تنظیمات (options)
// ----------------------------------------------------------------------------

 $options = array(
    'vsbbm_passenger_fields',
    'vsbbm_blacklist_target_field',
    'vsbbm_cache_enabled',
    'vsbbm_cache_ttl',
    'vsbbm_cache_max_keys',
    'vsbbm_cache_hits',
    'vsbbm_cache_total_requests',
    'vsbbm_cache_start_time',
    'vsbbm_last_cache_clear',
    'vsbbm_email_settings',
    'vsbbm_sms_settings',
    'vsbbm_license_data',
    'vsbbm_license_last_check',
    'vsbbm_pro_activation_error',
    'vsbbm_pro_activated_at',
);

foreach ( $options as $option ) {
    delete_option( $option );
}

// ----------------------------------------------------------------------------
// ۳. حذف transients
// ----------------------------------------------------------------------------

 $wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_vsbbm\_%'
     OR option_name LIKE '\_transient\_timeout\_vsbbm\_%'"
);

// ----------------------------------------------------------------------------
// ۴. حذف post meta (از محصولات)
// ----------------------------------------------------------------------------

 $post_meta_keys = array(
    '_vsbbm_enable_seat_booking',
    '_vsbbm_bus_type',
    '_vsbbm_bus_rows',
    '_vsbbm_bus_columns',
    '_vsbbm_seat_numbers',
    '_vsbbm_enable_schedule',
    '_vsbbm_schedule_type',
    '_vsbbm_departure_time',
    '_vsbbm_allowed_weekdays',
    '_vsbbm_specific_dates',
    '_vsbbm_min_days_advance',
    '_vsbbm_max_days_advance',
);

foreach ( $post_meta_keys as $meta_key ) {
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
            $meta_key
        )
    );
}

// ----------------------------------------------------------------------------
// ۵. حذف cron jobs
// ----------------------------------------------------------------------------

 $timestamp = wp_next_scheduled( 'vsbbm_cleanup_expired_reservations' );
if ( $timestamp ) {
    wp_unschedule_event( $timestamp, 'vsbbm_cleanup_expired_reservations' );
}

// پاک‌سازی همه cron های مرتبط
wp_clear_scheduled_hook( 'vsbbm_cleanup_expired_reservations' );

// ----------------------------------------------------------------------------
// ۶. حذف rewrite rules
// ----------------------------------------------------------------------------

flush_rewrite_rules();