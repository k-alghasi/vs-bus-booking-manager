<?php
/**
 * Bootstrap for unit tests
 */

// Define test constants
define('WP_TESTS_DIR', getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib');
define('WP_CORE_DIR', getenv('WP_CORE_DIR') ?: '/tmp/wordpress');

// Mock $wpdb
global $wpdb;
$wpdb = new stdClass();
$wpdb->prefix = 'wp_';
$wpdb->get_charset_collate = function() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; };
$wpdb->get_var = function($query) { return 0; };
$wpdb->prepare = function($query, $args) { return $query; };
$wpdb->insert = function($table, $data) { return 1; };
$wpdb->delete = function($table, $where) { return 1; };
$wpdb->get_results = function($query) { return array(); };
// Load WordPress test functions
if (file_exists(WP_TESTS_DIR . '/includes/functions.php')) {
    require_once WP_TESTS_DIR . '/includes/functions.php';
} else {
    // Fallback for basic testing without WordPress
    require_once dirname(__DIR__) . '/vs-bus-booking-manager.php';
}

// Mock basic WordPress functions
if (!function_exists('wp_die')) {
    function wp_die() { die(); }
}
if (!function_exists('wp_cache_get')) {
    function wp_cache_get($key) { return false; }
}
if (!function_exists('wp_cache_set')) {
    function wp_cache_set($key, $value) { return true; }
}
if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key) { return true; }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = null) { return $default; }
}
if (!function_exists('update_option')) {
    function update_option($key, $value) { return true; }
}
if (!function_exists('add_action')) {
    function add_action($hook, $callback) { return true; }
}
if (!function_exists('do_action')) {
    function do_action($hook) { return true; }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}
if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $in_footer = false) { return true; }
}
if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style($handle, $src = '', $deps = array(), $ver = false, $media = 'all') { return true; }
}
if (!function_exists('wp_localize_script')) {
    function wp_localize_script($handle, $object_name, $l10n) { return true; }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '') { return 'http://example.com/wp-admin/' . $path; }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true) {
        if ($echo) echo '<input type="hidden" name="' . $name . '" value="test_nonce" />';
        return 'test_nonce';
    }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) { return 'test_nonce'; }
}
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) { return true; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return $str; }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) { return htmlspecialchars($text, ENT_QUOTES); }
}
if (!function_exists('esc_html')) {
    function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES); }
}
if (!function_exists('esc_url')) {
    function esc_url($url) { return $url; }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($data) { return $data; }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('current_user_can')) {
    function current_user_can($capability) { return true; }
}
if (!function_exists('wp_send_json')) {
    function wp_send_json($response) { echo json_encode($response); exit; }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($message = null) { wp_send_json(array('success' => false, 'data' => $message)); }
}
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null) { wp_send_json(array('success' => true, 'data' => $data)); }
}
if (!function_exists('wp_die')) {
    function wp_die($message = '') { throw new Exception($message); }
}
if (!function_exists('is_admin')) {
    function is_admin() { return false; }
}
if (!function_exists('wp_ajax_')) {
    function wp_ajax_($action) { return true; }
}
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action = -1, $query_arg = false, $die = true) { return true; }
}
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() { return (object) array('ID' => 1, 'user_login' => 'admin'); }
}
if (!function_exists('get_user_by')) {
    function get_user_by($field, $value) { return (object) array('ID' => 1, 'user_login' => 'admin'); }
}
if (!function_exists('wp_set_current_user')) {
    function wp_set_current_user($id) { return true; }
}
if (!function_exists('wp_authenticate')) {
    function wp_authenticate($username, $password) { return (object) array('ID' => 1, 'user_login' => 'admin'); }
}
if (!function_exists('wp_signon')) {
    function wp_signon($credentials = array()) { return (object) array('ID' => 1, 'user_login' => 'admin'); }
}
if (!function_exists('wp_logout')) {
    function wp_logout() { return true; }
}
if (!function_exists('wp_redirect')) {
    function wp_redirect($location, $status = 302) { header("Location: $location"); exit; }
}
if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect($location, $status = 302) { wp_redirect($location, $status); }
}
if (!function_exists('wp_get_referer')) {
    function wp_get_referer() { return 'http://example.com'; }
}
if (!function_exists('wp_get_raw_referer')) {
    function wp_get_raw_referer() { return 'http://example.com'; }
}
if (!function_exists('wp_get_referrer')) {
    function wp_get_referrer() { return wp_get_referer(); }
}
if (!function_exists('wp_get_session_token')) {
    function wp_get_session_token() { return 'session_token'; }
}
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) { return true; }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) { return 'nonce'; }
}
if (!function_exists('wp_nonce_url')) {
    function wp_nonce_url($url, $action = -1) { return $url . '?_wpnonce=nonce'; }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true) {
        $field = '<input type="hidden" name="' . $name . '" value="nonce" />';
        if ($echo) echo $field;
        return $field;
    }
}
if (!function_exists('wp_referer_field')) {
    function wp_referer_field($echo = true) {
        $field = '<input type="hidden" name="_wp_http_referer" value="http://example.com" />';
        if ($echo) echo $field;
        return $field;
    }
}
if (!function_exists('wp_original_referer_field')) {
    function wp_original_referer_field($echo = true, $jump_back_to = 'current') {
        $field = '<input type="hidden" name="_wp_original_http_referer" value="http://example.com" />';
        if ($echo) echo $field;
        return $field;
    }
}
if (!function_exists('wp_get_salt')) {
    function wp_get_salt($scheme = 'auth') { return 'salt'; }
}
if (!function_exists('wp_hash')) {
    function wp_hash($data, $scheme = 'auth') { return md5($data); }
}
if (!function_exists('wp_hash_password')) {
    function wp_hash_password($password) { return md5($password); }
}
if (!function_exists('wp_check_password')) {
    function wp_check_password($password, $hash, $user_id = '') { return true; }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) { return 'password'; }
}
if (!function_exists('wp_rand')) {
    function wp_rand($min = 0, $max = 0) { return rand($min, $max); }
}
if (!function_exists('wp_set_password')) {
    function wp_set_password($password, $user_id) { return true; }
}
if (!function_exists('wp_get_password_hint')) {
    function wp_get_password_hint() { return ''; }
}
if (!function_exists('wp_password_change_notification')) {
    function wp_password_change_notification($user) { return true; }
}
if (!function_exists('wp_new_user_notification')) {
    function wp_new_user_notification($user_id, $plaintext_pass = '') { return true; }
}
if (!function_exists('wp_mail')) {
    function wp_mail($to, $subject, $message, $headers = '', $attachments = array()) { return true; }
}
if (!function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url($attachment_id) { return 'http://example.com/attachment.jpg'; }
}
if (!function_exists('wp_get_attachment_image_src')) {
    function wp_get_attachment_image_src($attachment_id, $size = 'thumbnail', $icon = false) { return array('http://example.com/attachment.jpg', 100, 100); }
}
if (!function_exists('wp_get_attachment_image')) {
    function wp_get_attachment_image($attachment_id, $size = 'thumbnail', $icon = false, $attr = '') { return '<img src="http://example.com/attachment.jpg" />'; }
}
if (!function_exists('wp_get_attachment_metadata')) {
    function wp_get_attachment_metadata($attachment_id) { return array('width' => 100, 'height' => 100); }
}
if (!function_exists('wp_update_attachment_metadata')) {
    function wp_update_attachment_metadata($attachment_id, $data) { return true; }
}
if (!function_exists('wp_delete_attachment')) {
    function wp_delete_attachment($attachment_id, $force_delete = false) { return true; }
}
if (!function_exists('wp_insert_attachment')) {
    function wp_insert_attachment($attachment, $filename, $parent = 0) { return 1; }
}
if (!function_exists('wp_generate_attachment_metadata')) {
    function wp_generate_attachment_metadata($attachment_id, $file) { return array(); }
}
if (!function_exists('wp_get_image_editor')) {
    function wp_get_image_editor($path, $args = array()) { return new stdClass(); }
}
if (!function_exists('wp_save_image_file')) {
    function wp_save_image_file($filename, $image, $mime_type, $post_id) { return true; }
}
if (!function_exists('wp_get_image_sizes')) {
    function wp_get_image_sizes() { return array(); }
}
if (!function_exists('wp_get_registered_image_subsizes')) {
    function wp_get_registered_image_subsizes() { return array(); }
}
if (!function_exists('wp_get_additional_image_sizes')) {
    function wp_get_additional_image_sizes() { return array(); }
}
if (!function_exists('wp_constrain_dimensions')) {
    function wp_constrain_dimensions($current_width, $current_height, $max_width, $max_height) { return array($current_width, $current_height); }
}
if (!function_exists('wp_get_image_send_to_editor')) {
    function wp_get_image_send_to_editor($id, $caption, $title, $align, $url, $rel = false, $size = 'medium', $alt = '') { return ''; }
}
if (!function_exists('wp_get_image_tag')) {
    function wp_get_image_tag($id, $alt, $title, $align, $size = 'medium') { return ''; }
}
if (!function_exists('wp_get_image_tag_class')) {
    function wp_get_image_tag_class($class) { return $class; }
}
if (!function_exists('wp_get_image_tag_style')) {
    function wp_get_image_tag_style($style) { return $style; }
}
if (!function_exists('wp_get_image_tag_alt')) {
    function wp_get_image_tag_alt($alt) { return $alt; }
}
if (!function_exists('wp_get_image_tag_title')) {
    function wp_get_image_tag_title($title) { return $title; }
}
if (!function_exists('wp_get_image_tag_rel')) {
    function wp_get_image_tag_rel($rel) { return $rel; }
}
if (!function_exists('wp_get_image_tag_size')) {
    function wp_get_image_tag_size($size) { return $size; }
}
if (!function_exists('wp_get_image_tag_src')) {
    function wp_get_image_tag_src($src) { return $src; }
}
if (!function_exists('wp_get_image_tag_width')) {
    function wp_get_image_tag_width($width) { return $width; }
}
if (!function_exists('wp_get_image_tag_height')) {
    function wp_get_image_tag_height($height) { return $height; }
}