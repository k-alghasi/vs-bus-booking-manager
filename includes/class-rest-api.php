<?php
/**
 * REST API برای اپلیکیشن موبایل VS Bus Booking Manager
 *
 * @package VSBBM
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

class VSBBM_REST_API {

    private static $instance = null;
    private $namespace = 'vsbbm/v1';

    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * ثبت تمام routes
     */
    public function register_routes() {
        // Authentication
        register_rest_route($this->namespace, '/auth/login', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_login'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route($this->namespace, '/auth/register', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_register'),
            'permission_callback' => '__return_true',
        ));

        // Products/Tours
        register_rest_route($this->namespace, '/products', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_products'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route($this->namespace, '/products/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_product_details'),
            'permission_callback' => '__return_true',
        ));

        // Seat Management
        register_rest_route($this->namespace, '/products/(?P<id>\d+)/seats', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_product_seats'),
            'permission_callback' => array($this, 'check_authentication'),
        ));

        register_rest_route($this->namespace, '/reservations', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_reservation'),
            'permission_callback' => array($this, 'check_authentication'),
        ));

        // User Bookings
        register_rest_route($this->namespace, '/user/bookings', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_user_bookings'),
            'permission_callback' => array($this, 'check_authentication'),
        ));

        register_rest_route($this->namespace, '/user/bookings/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_booking_details'),
            'permission_callback' => array($this, 'check_authentication'),
        ));

        // Tickets
        register_rest_route($this->namespace, '/tickets/(?P<code>[a-zA-Z0-9]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_ticket_details'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route($this->namespace, '/tickets/(?P<code>[a-zA-Z0-9]+)/use', array(
            'methods' => 'POST',
            'callback' => array($this, 'use_ticket'),
            'permission_callback' => '__return_true',
        ));

        // Profile
        register_rest_route($this->namespace, '/user/profile', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_user_profile'),
            'permission_callback' => array($this, 'check_authentication'),
        ));

        register_rest_route($this->namespace, '/user/profile', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_user_profile'),
            'permission_callback' => array($this, 'check_authentication'),
        ));
    }

    /**
     * بررسی authentication کاربر
     */
    public function check_authentication($request) {
        $auth_header = $request->get_header('Authorization');

        if (!$auth_header || !preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
            return new WP_Error('rest_forbidden', 'توکن احراز هویت یافت نشد', array('status' => 401));
        }

        $token = $matches[1];
        $user_id = $this->validate_token($token);

        if (!$user_id) {
            return new WP_Error('rest_forbidden', 'توکن نامعتبر است', array('status' => 401));
        }

        // ذخیره user_id در request برای استفاده در callbacks
        $request->set_param('user_id', $user_id);
        return true;
    }

    /**
     * اعتبار سنجی JWT token
     */
    private function validate_token($token) {
        // بررسی token در دیتابیس یا کش
        global $wpdb;
        $table_name = $wpdb->prefix . 'vsbbm_api_tokens';

        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM $table_name WHERE token = %s AND expires_at > NOW()",
            $token
        ));

        return $user_id;
    }

    /**
     * تولید JWT token
     */
    private function generate_token($user_id) {
        $token = wp_generate_password(32, false);
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));

        global $wpdb;
        $table_name = $wpdb->prefix . 'vsbbm_api_tokens';

        $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'token' => $token,
                'expires_at' => $expires_at,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s')
        );

        return $token;
    }

    /**
     * Handle user login
     */
    public function handle_login($request) {
        $params = $request->get_json_params();

        if (empty($params['username']) || empty($params['password'])) {
            return new WP_Error('rest_invalid_param', 'نام کاربری و رمز عبور الزامی است', array('status' => 400));
        }

        $user = wp_authenticate($params['username'], $params['password']);

        if (is_wp_error($user)) {
            return new WP_Error('rest_invalid_credentials', 'نام کاربری یا رمز عبور اشتباه است', array('status' => 401));
        }

        $token = $this->generate_token($user->ID);

        return array(
            'success' => true,
            'token' => $token,
            'user' => array(
                'id' => $user->ID,
                'username' => $user->user_login,
                'email' => $user->user_email,
                'display_name' => $user->display_name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
            )
        );
    }

    /**
     * Handle user registration
     */
    public function handle_register($request) {
        $params = $request->get_json_params();

        $required_fields = array('username', 'email', 'password', 'first_name', 'last_name');
        foreach ($required_fields as $field) {
            if (empty($params[$field])) {
                return new WP_Error('rest_invalid_param', "فیلد $field الزامی است", array('status' => 400));
            }
        }

        // بررسی وجود کاربر
        if (username_exists($params['username'])) {
            return new WP_Error('rest_user_exists', 'این نام کاربری قبلاً ثبت شده است', array('status' => 409));
        }

        if (email_exists($params['email'])) {
            return new WP_Error('rest_email_exists', 'این ایمیل قبلاً ثبت شده است', array('status' => 409));
        }

        // ایجاد کاربر
        $user_id = wp_create_user(
            $params['username'],
            $params['password'],
            $params['email']
        );

        if (is_wp_error($user_id)) {
            return new WP_Error('rest_registration_failed', 'خطا در ثبت نام', array('status' => 500));
        }

        // بروزرسانی اطلاعات کاربر
        wp_update_user(array(
            'ID' => $user_id,
            'first_name' => sanitize_text_field($params['first_name']),
            'last_name' => sanitize_text_field($params['last_name']),
            'display_name' => sanitize_text_field($params['first_name'] . ' ' . $params['last_name'])
        ));

        // اضافه کردن فیلدهای اضافی
        if (!empty($params['phone'])) {
            update_user_meta($user_id, 'billing_phone', sanitize_text_field($params['phone']));
        }

        if (!empty($params['national_id'])) {
            update_user_meta($user_id, 'vsbbm_national_id', sanitize_text_field($params['national_id']));
        }

        $token = $this->generate_token($user_id);

        return array(
            'success' => true,
            'token' => $token,
            'user' => array(
                'id' => $user_id,
                'username' => $params['username'],
                'email' => $params['email'],
                'display_name' => $params['first_name'] . ' ' . $params['last_name'],
                'first_name' => $params['first_name'],
                'last_name' => $params['last_name'],
            )
        );
    }

    /**
     * دریافت لیست محصولات
     */
    public function get_products($request) {
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => isset($_GET['per_page']) ? intval($_GET['per_page']) : 10,
            'paged' => isset($_GET['page']) ? intval($_GET['page']) : 1,
            'meta_query' => array(
                array(
                    'key' => '_vsbbm_enable_seat_booking',
                    'value' => 'yes',
                    'compare' => '='
                )
            )
        );

        $query = new WP_Query($args);
        $products = array();

        foreach ($query->posts as $post) {
            $product = wc_get_product($post->ID);

            if (!$product) continue;

            $products[] = array(
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'description' => wp_trim_words($product->get_description(), 20),
                'price' => $product->get_price(),
                'regular_price' => $product->get_regular_price(),
                'sale_price' => $product->get_sale_price(),
                'image' => wp_get_attachment_image_url($product->get_image_id(), 'medium'),
                'availability' => $this->get_product_availability($product->get_id()),
                'total_seats' => count(VSBBM_Seat_Manager::get_seat_numbers($product->get_id())),
                'available_seats' => $this->get_available_seats_count($product->get_id()),
            );
        }

        return array(
            'success' => true,
            'products' => $products,
            'pagination' => array(
                'total' => $query->found_posts,
                'total_pages' => $query->max_num_pages,
                'current_page' => $args['paged'],
                'per_page' => $args['posts_per_page']
            )
        );
    }

    /**
     * دریافت جزئیات محصول
     */
    public function get_product_details($request) {
        $product_id = intval($request->get_param('id'));
        $product = wc_get_product($product_id);

        if (!$product) {
            return new WP_Error('rest_product_not_found', 'محصول یافت نشد', array('status' => 404));
        }

        if (!VSBBM_Seat_Manager::is_seat_booking_enabled($product_id)) {
            return new WP_Error('rest_not_bus_product', 'این محصول سرویس اتوبوس نیست', array('status' => 400));
        }

        return array(
            'success' => true,
            'product' => array(
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'description' => $product->get_description(),
                'short_description' => $product->get_short_description(),
                'price' => $product->get_price(),
                'regular_price' => $product->get_regular_price(),
                'sale_price' => $product->get_sale_price(),
                'images' => $this->get_product_images($product),
                'attributes' => $this->get_product_attributes($product),
                'availability' => $this->get_product_availability($product->get_id()),
                'seats' => array(
                    'total' => count(VSBBM_Seat_Manager::get_seat_numbers($product->get_id())),
                    'available' => $this->get_available_seats_count($product->get_id()),
                    'layout' => $this->get_seat_layout($product->get_id())
                ),
                'schedule' => array(
                    'start_date' => get_post_meta($product_id, '_vsbbm_sale_start_date', true),
                    'end_date' => get_post_meta($product_id, '_vsbbm_sale_end_date', true)
                )
            )
        );
    }

    /**
     * دریافت صندلی‌های محصول
     */
    public function get_product_seats($request) {
        $product_id = intval($request->get_param('id'));
        $user_id = $request->get_param('user_id');

        if (!VSBBM_Seat_Manager::is_seat_booking_enabled($product_id)) {
            return new WP_Error('rest_not_bus_product', 'این محصول سرویس اتوبوس نیست', array('status' => 400));
        }

        $available_seats = VSBBM_Seat_Manager::get_seat_numbers($product_id);
        $reserved_seats = VSBBM_Seat_Manager::get_reserved_seats($product_id);

        $seats = array();
        foreach ($available_seats as $seat_number) {
            $seats[] = array(
                'number' => $seat_number,
                'status' => in_array($seat_number, $reserved_seats) ? 'reserved' : 'available',
                'price' => get_post_meta($product_id, '_price', true) // قیمت پایه
            );
        }

        return array(
            'success' => true,
            'seats' => $seats
        );
    }

    /**
     * ایجاد رزرو جدید
     */
    public function create_reservation($request) {
        $params = $request->get_json_params();
        $user_id = $request->get_param('user_id');

        $required_fields = array('product_id', 'seats', 'passengers');
        foreach ($required_fields as $field) {
            if (empty($params[$field])) {
                return new WP_Error('rest_invalid_param', "فیلد $field الزامی است", array('status' => 400));
            }
        }

        $product_id = intval($params['product_id']);
        $selected_seats = $params['seats'];
        $passengers = $params['passengers'];

        // بررسی محصول
        if (!VSBBM_Seat_Manager::is_seat_booking_enabled($product_id)) {
            return new WP_Error('rest_not_bus_product', 'این محصول سرویس اتوبوس نیست', array('status' => 400));
        }

        // بررسی دسترسی محصول
        if (!VSBBM_Seat_Manager::is_product_available($product_id)) {
            return new WP_Error('rest_product_unavailable', 'این محصول در حال حاضر قابل رزرو نیست', array('status' => 400));
        }

        // رزرو صندلی‌ها
        $reservation_result = VSBBM_Seat_Reservations::reserve_seats(
            $product_id,
            $selected_seats,
            null, // order_id will be set later
            $user_id,
            $passengers
        );

        if (is_wp_error($reservation_result)) {
            return new WP_Error('rest_reservation_failed', $reservation_result->get_error_message(), array('status' => 400));
        }

        // ایجاد سفارش WooCommerce
        $order = $this->create_woocommerce_order($product_id, $selected_seats, $passengers, $user_id);

        if (is_wp_error($order)) {
            // لغو رزرو در صورت خطا
            VSBBM_Seat_Reservations::cancel_reservation_by_order(null);
            return new WP_Error('rest_order_failed', 'خطا در ایجاد سفارش', array('status' => 500));
        }

        // بروزرسانی order_id در رزروها
        VSBBM_Seat_Reservations::update_reservation_order_id($reservation_result, $order->get_id());

        return array(
            'success' => true,
            'reservation_id' => $reservation_result,
            'order_id' => $order->get_id(),
            'order_key' => $order->get_order_key(),
            'total_amount' => $order->get_total(),
            'status' => 'pending_payment'
        );
    }

    /**
     * دریافت رزروهای کاربر
     */
    public function get_user_bookings($request) {
        $user_id = $request->get_param('user_id');

        $args = array(
            'post_type' => 'shop_order',
            'post_status' => array('wc-pending', 'wc-processing', 'wc-completed'),
            'author' => $user_id,
            'posts_per_page' => isset($_GET['per_page']) ? intval($_GET['per_page']) : 10,
            'paged' => isset($_GET['page']) ? intval($_GET['page']) : 1,
        );

        $query = new WP_Query($args);
        $bookings = array();

        foreach ($query->posts as $post) {
            $order = wc_get_order($post->ID);

            if (!$order) continue;

            $booking_data = $this->get_booking_data_from_order($order);
            if ($booking_data) {
                $bookings[] = $booking_data;
            }
        }

        return array(
            'success' => true,
            'bookings' => $bookings,
            'pagination' => array(
                'total' => $query->found_posts,
                'total_pages' => $query->max_num_pages,
                'current_page' => $args['paged'],
                'per_page' => $args['posts_per_page']
            )
        );
    }

    /**
     * دریافت جزئیات رزرو
     */
    public function get_booking_details($request) {
        $booking_id = intval($request->get_param('id'));
        $user_id = $request->get_param('user_id');

        $order = wc_get_order($booking_id);

        if (!$order || $order->get_customer_id() !== $user_id) {
            return new WP_Error('rest_booking_not_found', 'رزرو یافت نشد', array('status' => 404));
        }

        $booking_data = $this->get_booking_data_from_order($order);

        if (!$booking_data) {
            return new WP_Error('rest_invalid_booking', 'این سفارش رزرو اتوبوس نیست', array('status' => 400));
        }

        return array(
            'success' => true,
            'booking' => $booking_data
        );
    }

    /**
     * دریافت جزئیات بلیط
     */
    public function get_ticket_details($request) {
        $ticket_code = sanitize_text_field($request->get_param('code'));

        global $wpdb;
        $table_name = $wpdb->prefix . 'vsbbm_tickets';

        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE ticket_number = %s",
            $ticket_code
        ));

        if (!$ticket) {
            return new WP_Error('rest_ticket_not_found', 'بلیط یافت نشد', array('status' => 404));
        }

        $order = wc_get_order($ticket->order_id);
        $passenger_data = json_decode($ticket->passenger_data, true);

        return array(
            'success' => true,
            'ticket' => array(
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'order_id' => $ticket->order_id,
                'passenger' => $passenger_data,
                'seat_number' => $passenger_data['seat_number'] ?? null,
                'status' => $ticket->status,
                'created_at' => $ticket->created_at,
                'used_at' => $ticket->used_at,
                'product_name' => $order ? $order->get_order_number() : 'نامشخص',
                'qr_code' => $this->generate_qr_code_url($ticket->ticket_number)
            )
        );
    }

    /**
     * استفاده از بلیط
     */
    public function use_ticket($request) {
        $ticket_code = sanitize_text_field($request->get_param('code'));

        $result = VSBBM_Ticket_Manager::use_ticket_by_code($ticket_code);

        if (is_wp_error($result)) {
            return new WP_Error('rest_ticket_use_failed', $result->get_error_message(), array('status' => 400));
        }

        return array(
            'success' => true,
            'message' => 'بلیط با موفقیت استفاده شد',
            'used_at' => current_time('mysql')
        );
    }

    /**
     * دریافت پروفایل کاربر
     */
    public function get_user_profile($request) {
        $user_id = $request->get_param('user_id');
        $user = get_userdata($user_id);

        if (!$user) {
            return new WP_Error('rest_user_not_found', 'کاربر یافت نشد', array('status' => 404));
        }

        return array(
            'success' => true,
            'profile' => array(
                'id' => $user->ID,
                'username' => $user->user_login,
                'email' => $user->user_email,
                'display_name' => $user->display_name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone' => get_user_meta($user_id, 'billing_phone', true),
                'national_id' => get_user_meta($user_id, 'vsbbm_national_id', true),
                'registered_date' => $user->user_registered
            )
        );
    }

    /**
     * بروزرسانی پروفایل کاربر
     */
    public function update_user_profile($request) {
        $params = $request->get_json_params();
        $user_id = $request->get_param('user_id');

        $update_data = array();

        if (isset($params['first_name'])) {
            $update_data['first_name'] = sanitize_text_field($params['first_name']);
        }

        if (isset($params['last_name'])) {
            $update_data['last_name'] = sanitize_text_field($params['last_name']);
        }

        if (isset($params['display_name'])) {
            $update_data['display_name'] = sanitize_text_field($params['display_name']);
        }

        if (!empty($update_data)) {
            $update_data['ID'] = $user_id;
            wp_update_user($update_data);
        }

        if (isset($params['phone'])) {
            update_user_meta($user_id, 'billing_phone', sanitize_text_field($params['phone']));
        }

        if (isset($params['national_id'])) {
            update_user_meta($user_id, 'vsbbm_national_id', sanitize_text_field($params['national_id']));
        }

        return array(
            'success' => true,
            'message' => 'پروفایل با موفقیت بروزرسانی شد'
        );
    }

    /**
     * Helper: ایجاد سفارش WooCommerce
     */
    private function create_woocommerce_order($product_id, $seats, $passengers, $user_id) {
        $product = wc_get_product($product_id);
        $quantity = count($seats);

        $order = wc_create_order(array(
            'customer_id' => $user_id
        ));

        $order->add_product($product, $quantity);

        // اضافه کردن اطلاعات مسافران
        $order->update_meta_data('_vsbbm_passengers', $passengers);
        $order->update_meta_data('_vsbbm_seats', $seats);

        $order->calculate_totals();
        $order->save();

        return $order;
    }

    /**
     * Helper: دریافت داده‌های رزرو از سفارش
     */
    private function get_booking_data_from_order($order) {
        $passengers = $order->get_meta('_vsbbm_passengers');
        $seats = $order->get_meta('_vsbbm_seats');

        if (!$passengers || !$seats) {
            return null; // این سفارش رزرو اتوبوس نیست
        }

        $items = $order->get_items();
        $product_name = '';
        foreach ($items as $item) {
            $product = $item->get_product();
            if ($product && VSBBM_Seat_Manager::is_seat_booking_enabled($product->get_id())) {
                $product_name = $product->get_name();
                break;
            }
        }

        return array(
            'id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'status' => $order->get_status(),
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'date_created' => $order->get_date_created()->date('Y-m-d H:i:s'),
            'product_name' => $product_name,
            'seats' => $seats,
            'passengers' => $passengers,
            'tickets' => $this->get_order_tickets($order->get_id())
        );
    }

    /**
     * Helper: دریافت بلیط‌های سفارش
     */
    private function get_order_tickets($order_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'vsbbm_tickets';

        $tickets = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_id = %d",
            $order_id
        ));

        $ticket_data = array();
        foreach ($tickets as $ticket) {
            $passenger_data = json_decode($ticket->passenger_data, true);
            $ticket_data[] = array(
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'passenger' => $passenger_data,
                'status' => $ticket->status,
                'qr_code' => $this->generate_qr_code_url($ticket->ticket_number)
            );
        }

        return $ticket_data;
    }

    /**
     * Helper: تولید URL QR code
     */
    private function generate_qr_code_url($ticket_code) {
        return add_query_arg(array(
            'action' => 'vsbbm_qr_code',
            'ticket' => $ticket_code
        ), admin_url('admin-ajax.php'));
    }

    /**
     * Helper: دریافت تصاویر محصول
     */
    private function get_product_images($product) {
        $images = array();

        // تصویر اصلی
        if ($product->get_image_id()) {
            $images[] = array(
                'id' => $product->get_image_id(),
                'src' => wp_get_attachment_image_url($product->get_image_id(), 'full'),
                'alt' => get_post_meta($product->get_image_id(), '_wp_attachment_image_alt', true)
            );
        }

        // تصاویر گالری
        $gallery_ids = $product->get_gallery_image_ids();
        foreach ($gallery_ids as $image_id) {
            $images[] = array(
                'id' => $image_id,
                'src' => wp_get_attachment_image_url($image_id, 'full'),
                'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true)
            );
        }

        return $images;
    }

    /**
     * Helper: دریافت attributes محصول
     */
    private function get_product_attributes($product) {
        $attributes = array();

        foreach ($product->get_attributes() as $attribute) {
            if ($attribute->is_taxonomy()) {
                $terms = wc_get_product_terms($product->get_id(), $attribute->get_name(), array('fields' => 'names'));
                $attributes[$attribute->get_name()] = $terms;
            } else {
                $attributes[$attribute->get_name()] = $attribute->get_options();
            }
        }

        return $attributes;
    }

    /**
     * Helper: دریافت وضعیت دسترسی محصول
     */
    private function get_product_availability($product_id) {
        return VSBBM_Seat_Manager::get_product_availability_status($product_id);
    }

    /**
     * Helper: شمارش صندلی‌های موجود
     */
    private function get_available_seats_count($product_id) {
        $total_seats = VSBBM_Seat_Manager::get_seat_numbers($product_id);
        $reserved_seats = VSBBM_Seat_Manager::get_reserved_seats($product_id);

        return count($total_seats) - count($reserved_seats);
    }

    /**
     * Helper: دریافت چیدمان صندلی‌ها
     */
    private function get_seat_layout($product_id) {
        $seats = VSBBM_Seat_Manager::get_seat_numbers($product_id);
        $reserved_seats = VSBBM_Seat_Manager::get_reserved_seats($product_id);

        $layout = array();
        foreach ($seats as $seat) {
            $layout[] = array(
                'number' => $seat,
                'available' => !in_array($seat, $reserved_seats)
            );
        }

        return $layout;
    }
}

// Initialize the REST API
VSBBM_REST_API::init();