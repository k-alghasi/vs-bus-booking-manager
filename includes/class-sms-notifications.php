<?php
defined('ABSPATH') || exit;

/**
 * Class VSBBM_SMS_Notifications
 * سیستم ارسال پیامک اطلاع‌رسانی (نسخه پرو)
 */
class VSBBM_SMS_Notifications {

    private static $instance = null;

    // لیست پنل‌های SMS ایرانی پشتیبانی شده
    private static $supported_panels = array(
        'ippanel' => 'IPPanel',
        'kavenegar' => 'Kavenegar',
        'smsir' => 'SMS.ir',
        'ghasedak' => 'Ghasedak',
        'payamak' => 'Payamak-panel',
        'farazsms' => 'FarazSMS',
        'melipayamak' => 'MeliPayamak'
    );

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // هوک‌های تغییر وضعیت سفارش
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 15, 4);

        // هوک‌های سفارشی برای رزروها
        add_action('vsbbm_reservation_confirmed', array($this, 'send_booking_confirmation_sms'), 10, 2);
        add_action('vsbbm_reservation_cancelled', array($this, 'send_booking_cancellation_sms'), 10, 2);
        add_action('vsbbm_ticket_used', array($this, 'send_ticket_used_sms'), 10, 2);

        // هوک‌های OTP و تایید
        add_action('vsbbm_send_otp', array($this, 'send_otp_sms'), 10, 2);
        add_action('vsbbm_verify_phone', array($this, 'send_phone_verification_sms'), 10, 1);

        // هوک پاکسازی کدهای OTP منقضی
        add_action('vsbbm_cleanup_expired_otps', array($this, 'cleanup_expired_otps'));
    }

    /**
     * مدیریت تغییر وضعیت سفارش
     */
    public function handle_order_status_change($order_id, $old_status, $new_status, $order) {
        // بررسی اینکه آیا سفارش شامل رزرو صندلی است
        if (!$this->order_has_seat_booking($order)) {
            return;
        }

        switch ($new_status) {
            case 'completed':
                // ارسال SMS تایید رزرو به مشتری
                $this->send_customer_booking_confirmation($order);
                break;

            case 'cancelled':
            case 'refunded':
                // ارسال SMS لغو رزرو به مشتری
                $this->send_customer_booking_cancellation($order);
                break;

            case 'processing':
                // ارسال SMS تایید رزرو برای پرداخت‌های اعتباری
                if ($this->get_sms_setting('enable_customer_processing_sms')) {
                    $this->send_customer_booking_confirmation($order);
                }
                break;
        }
    }

    /**
     * بررسی اینکه آیا سفارش شامل رزرو صندلی است
     */
    private function order_has_seat_booking($order) {
        foreach ($order->get_items() as $item) {
            if (VSBBM_Seat_Manager::is_seat_booking_enabled($item->get_product_id())) {
                return true;
            }
        }
        return false;
    }

    /**
     * ارسال SMS تایید رزرو به مشتری
     */
    public function send_customer_booking_confirmation($order) {
        if (!$this->get_sms_setting('enable_customer_confirmation_sms')) {
            return;
        }

        $phone = $this->get_customer_phone($order);
        if (!$phone) return;

        $passengers = $this->get_passengers_from_order($order);
        $product_info = $this->get_product_info_from_order($order);

        $message = $this->get_customer_confirmation_sms_content($order, $passengers, $product_info);

        $this->send_sms($phone, $message, 'customer_confirmation');
    }

    /**
     * ارسال SMS لغو رزرو به مشتری
     */
    public function send_customer_booking_cancellation($order) {
        if (!$this->get_sms_setting('enable_customer_cancellation_sms')) {
            return;
        }

        $phone = $this->get_customer_phone($order);
        if (!$phone) return;

        $message = $this->get_customer_cancellation_sms_content($order);

        $this->send_sms($phone, $message, 'customer_cancellation');
    }

    /**
     * ارسال SMS استفاده از بلیط
     */
    public function send_ticket_used_sms($ticket_id, $passenger_data) {
        if (!$this->get_sms_setting('enable_ticket_used_sms')) {
            return;
        }

        $phone = $passenger_data['phone'] ?? '';
        if (!$phone) return;

        $message = $this->get_ticket_used_sms_content($passenger_data);

        $this->send_sms($phone, $message, 'ticket_used');
    }

    /**
     * ارسال SMS OTP
     */
    public function send_otp_sms($phone, $otp_code) {
        if (!$this->get_sms_setting('enable_otp_sms')) {
            return false;
        }

        $message = sprintf(
            __('کد تایید شما: %s\nاین کد تا %d دقیقه معتبر است.', 'vs-bus-booking-manager'),
            $otp_code,
            $this->get_sms_setting('otp_expiry_minutes', 5)
        );

        return $this->send_sms($phone, $message, 'otp');
    }

    /**
     * ارسال SMS تایید شماره تلفن
     */
    public function send_phone_verification_sms($phone) {
        $otp_code = $this->generate_otp_code();
        $this->store_otp_code($phone, $otp_code);

        return $this->send_otp_sms($phone, $otp_code);
    }

    /**
     * تولید کد OTP
     */
    private function generate_otp_code($length = 6) {
        $characters = '0123456789';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $code;
    }

    /**
     * ذخیره کد OTP
     */
    private function store_otp_code($phone, $code) {
        $otp_data = array(
            'code' => $code,
            'phone' => $phone,
            'created_at' => current_time('timestamp'),
            'expires_at' => current_time('timestamp') + ($this->get_sms_setting('otp_expiry_minutes', 5) * 60)
        );

        $existing_otps = get_option('vsbbm_otps', array());
        $existing_otps[$phone] = $otp_data;

        // پاکسازی OTPهای منقضی
        foreach ($existing_otps as $key => $otp) {
            if ($otp['expires_at'] < current_time('timestamp')) {
                unset($existing_otps[$key]);
            }
        }

        update_option('vsbbm_otps', $existing_otps);
    }

    /**
     * تایید کد OTP
     */
    public static function verify_otp_code($phone, $code) {
        $existing_otps = get_option('vsbbm_otps', array());

        if (!isset($existing_otps[$phone])) {
            return false;
        }

        $otp_data = $existing_otps[$phone];

        // بررسی انقضا
        if ($otp_data['expires_at'] < current_time('timestamp')) {
            unset($existing_otps[$phone]);
            update_option('vsbbm_otps', $existing_otps);
            return false;
        }

        // بررسی کد
        if ($otp_data['code'] === $code) {
            unset($existing_otps[$phone]);
            update_option('vsbbm_otps', $existing_otps);
            return true;
        }

        return false;
    }

    /**
     * پاکسازی کدهای OTP منقضی
     */
    public function cleanup_expired_otps() {
        $existing_otps = get_option('vsbbm_otps', array());
        $cleaned = false;

        foreach ($existing_otps as $phone => $otp_data) {
            if ($otp_data['expires_at'] < current_time('timestamp')) {
                unset($existing_otps[$phone]);
                $cleaned = true;
            }
        }

        if ($cleaned) {
            update_option('vsbbm_otps', $existing_otps);
        }
    }

    /**
     * ارسال SMS اصلی
     */
    private function send_sms($phone, $message, $type = 'general') {
        $panel = $this->get_sms_setting('sms_panel');
        if (!$panel || !isset(self::$supported_panels[$panel])) {
            error_log("VSBBM SMS: Unsupported panel: $panel");
            return false;
        }

        // نرمال‌سازی شماره تلفن
        $phone = $this->normalize_phone_number($phone);

        try {
            $result = $this->send_via_panel($panel, $phone, $message);

            if ($result) {
                error_log("VSBBM SMS sent successfully: $type to $phone");
                do_action('vsbbm_sms_sent', $phone, $message, $type);
            } else {
                error_log("VSBBM SMS failed: $type to $phone");
                do_action('vsbbm_sms_failed', $phone, $message, $type);
            }

            return $result;
        } catch (Exception $e) {
            error_log("VSBBM SMS error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ارسال از طریق پنل انتخاب شده
     */
    private function send_via_panel($panel, $phone, $message) {
        switch ($panel) {
            case 'ippanel':
                return $this->send_via_ippanel($phone, $message);
            case 'kavenegar':
                return $this->send_via_kavenegar($phone, $message);
            case 'smsir':
                return $this->send_via_smsir($phone, $message);
            case 'ghasedak':
                return $this->send_via_ghasedak($phone, $message);
            case 'payamak':
                return $this->send_via_payamak($phone, $message);
            case 'farazsms':
                return $this->send_via_farazsms($phone, $message);
            case 'melipayamak':
                return $this->send_via_melipayamak($phone, $message);
            default:
                return false;
        }
    }

    /**
     * ارسال از طریق IPPanel
     */
    private function send_via_ippanel($phone, $message) {
        $api_key = $this->get_sms_setting('ippanel_api_key');
        $originator = $this->get_sms_setting('ippanel_originator');

        if (!$api_key || !$originator) return false;

        $url = 'https://ippanel.com/api/select';
        $data = array(
            'op' => 'send',
            'uname' => $api_key,
            'pass' => $this->get_sms_setting('ippanel_password'),
            'from' => $originator,
            'to' => array($phone),
            'time' => '',
            'text' => $message
        );

        $response = wp_remote_post($url, array(
            'body' => json_encode($data),
            'headers' => array('Content-Type' => 'application/json'),
            'timeout' => 30
        ));

        if (is_wp_error($response)) return false;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return isset($body['status']) && $body['status'] == 'success';
    }

    /**
     * ارسال از طریق Kavenegar
     */
    private function send_via_kavenegar($phone, $message) {
        $api_key = $this->get_sms_setting('kavenegar_api_key');
        $sender = $this->get_sms_setting('kavenegar_sender');

        if (!$api_key || !$sender) return false;

        $url = "https://api.kavenegar.com/v1/{$api_key}/sms/send.json";
        $data = array(
            'sender' => $sender,
            'receptor' => $phone,
            'message' => $message
        );

        $response = wp_remote_post($url, array(
            'body' => $data,
            'timeout' => 30
        ));

        if (is_wp_error($response)) return false;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return isset($body['return']['status']) && $body['return']['status'] == 200;
    }

    /**
     * ارسال از طریق SMS.ir
     */
    private function send_via_smsir($phone, $message) {
        $api_key = $this->get_sms_setting('smsir_api_key');
        $line_number = $this->get_sms_setting('smsir_line_number');

        if (!$api_key || !$line_number) return false;

        $url = 'https://api.sms.ir/v1/send/bulk';
        $data = array(
            'lineNumber' => $line_number,
            'messageText' => $message,
            'mobiles' => array($phone)
        );

        $response = wp_remote_post($url, array(
            'body' => json_encode($data),
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key
            ),
            'timeout' => 30
        ));

        if (is_wp_error($response)) return false;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return isset($body['status']) && $body['status'] == 1;
    }

    /**
     * ارسال از طریق سایر پنل‌ها (placeholders)
     */
    private function send_via_ghasedak($phone, $message) {
        // پیاده‌سازی Ghasedak
        $api_key = $this->get_sms_setting('ghasedak_api_key');
        if (!$api_key) return false;

        // TODO: Implement Ghasedak API
        error_log("VSBBM SMS: Ghasedak not implemented yet");
        return false;
    }

    private function send_via_payamak($phone, $message) {
        // پیاده‌سازی Payamak-panel
        $username = $this->get_sms_setting('payamak_username');
        $password = $this->get_sms_setting('payamak_password');
        if (!$username || !$password) return false;

        // TODO: Implement Payamak API
        error_log("VSBBM SMS: Payamak not implemented yet");
        return false;
    }

    private function send_via_farazsms($phone, $message) {
        // پیاده‌سازی FarazSMS
        $username = $this->get_sms_setting('farazsms_username');
        $password = $this->get_sms_setting('farazsms_password');
        if (!$username || !$password) return false;

        // TODO: Implement FarazSMS API
        error_log("VSBBM SMS: FarazSMS not implemented yet");
        return false;
    }

    private function send_via_melipayamak($phone, $message) {
        // پیاده‌سازی MeliPayamak
        $username = $this->get_sms_setting('melipayamak_username');
        $password = $this->get_sms_setting('melipayamak_password');
        if (!$username || !$password) return false;

        // TODO: Implement MeliPayamak API
        error_log("VSBBM SMS: MeliPayamak not implemented yet");
        return false;
    }

    /**
     * نرمال‌سازی شماره تلفن
     */
    private function normalize_phone_number($phone) {
        // حذف کاراکترهای غیر عددی
        $phone = preg_replace('/\D/', '', $phone);

        // اگر با 0 شروع شود، تبدیل به فرمت بین‌المللی
        if (substr($phone, 0, 1) === '0') {
            $phone = '98' . substr($phone, 1);
        }

        // اگر با 98 شروع نشود، اضافه کردن
        if (substr($phone, 0, 2) !== '98') {
            $phone = '98' . $phone;
        }

        return $phone;
    }

    /**
     * دریافت تنظیمات SMS
     */
    private function get_sms_setting($setting_key, $default = null) {
        $settings = get_option('vsbbm_sms_settings', array());
        return isset($settings[$setting_key]) ? $settings[$setting_key] : $default;
    }

    /**
     * دریافت شماره تلفن مشتری
     */
    private function get_customer_phone($order) {
        $phone = $order->get_billing_phone();
        return $phone ?: '';
    }

    /**
     * تولید محتوای SMS تایید رزرو
     */
    private function get_customer_confirmation_sms_content($order, $passengers, $product_info) {
        $order_number = $order->get_id();
        $total = wc_price($order->get_total());

        $message = sprintf(
            __('✅ رزرو شما تایید شد\nسفارش #%s\nمبلغ: %s\n', 'vs-bus-booking-manager'),
            $order_number,
            $total
        );

        if (!empty($product_info)) {
            foreach ($product_info as $product) {
                $message .= sprintf(
                    __('سرویس: %s\nصندلی: %d\n', 'vs-bus-booking-manager'),
                    $product['name'],
                    $product['quantity']
                );
            }
        }

        $message .= __('برای مشاهده بلیط به حساب کاربری مراجعه کنید.', 'vs-bus-booking-manager');

        return $message;
    }

    /**
     * تولید محتوای SMS لغو رزرو
     */
    private function get_customer_cancellation_sms_content($order) {
        $order_number = $order->get_id();

        return sprintf(
            __('❌ رزرو لغو شد\nسفارش #%s\nمبلغ به حساب شما بازگردانده خواهد شد.', 'vs-bus-booking-manager'),
            $order_number
        );
    }

    /**
     * تولید محتوای SMS استفاده از بلیط
     */
    private function get_ticket_used_sms_content($passenger_data) {
        $seat = $passenger_data['seat_number'] ?? '';
        $name = $passenger_data['name'] ?? '';

        return sprintf(
            __('🎫 بلیط استفاده شد\nمسافر: %s\nصندلی: %s\nخوش سفر باشید!', 'vs-bus-booking-manager'),
            $name,
            $seat
        );
    }

    /**
     * دریافت مسافران از سفارش
     */
    private function get_passengers_from_order($order) {
        $passengers = array();

        foreach ($order->get_items() as $item) {
            $item_passengers = array();
            foreach ($item->get_meta_data() as $meta) {
                if (strpos($meta->key, 'مسافر') !== false) {
                    $item_passengers[] = $meta->value;
                }
            }
            if (!empty($item_passengers)) {
                $passengers = array_merge($passengers, $item_passengers);
            }
        }

        return $passengers;
    }

    /**
     * دریافت اطلاعات محصول از سفارش
     */
    private function get_product_info_from_order($order) {
        $products = array();

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && VSBBM_Seat_Manager::is_seat_booking_enabled($product->get_id())) {
                $products[] = array(
                    'name' => $product->get_name(),
                    'quantity' => $item->get_quantity(),
                    'price' => $item->get_total()
                );
            }
        }

        return $products;
    }

    /**
     * دریافت لیست پنل‌های پشتیبانی شده
     */
    public static function get_supported_panels() {
        return self::$supported_panels;
    }

    /**
     * تست اتصال به پنل SMS
     */
    public static function test_sms_connection($panel) {
        $instance = self::get_instance();
        // ارسال SMS تست
        $test_phone = $instance->get_sms_setting('test_phone_number');
        if (!$test_phone) return false;

        return $instance->send_sms($test_phone, __('تست اتصال سیستم SMS', 'vs-bus-booking-manager'), 'test');
    }
}

// Initialize the class
VSBBM_SMS_Notifications::get_instance();