<?php
defined('ABSPATH') || exit;

/**
 * Class VSBBM_Email_Notifications
 * مدیریت اعلان‌های ایمیلی سیستم رزرواسیون
 */
class VSBBM_Email_Notifications {

    private static $instance = null;

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
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 20, 4);

        // هوک‌های سفارشی برای رزروها
        add_action('vsbbm_reservation_confirmed', array($this, 'send_booking_confirmation_email'), 10, 2);
        add_action('vsbbm_reservation_cancelled', array($this, 'send_booking_cancellation_email'), 10, 2);
        add_action('vsbbm_new_reservation', array($this, 'send_admin_new_booking_notification'), 10, 2);

        // هوک پاکسازی رزروهای منقضی شده
        add_action('vsbbm_reservation_expired', array($this, 'send_admin_expired_reservation_notification'), 10, 1);
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
                // ارسال ایمیل تایید رزرو به مشتری
                $this->send_customer_booking_confirmation($order);
                break;

            case 'cancelled':
            case 'refunded':
                // ارسال ایمیل لغو رزرو به مشتری
                $this->send_customer_booking_cancellation($order);
                break;

            case 'processing':
                // ارسال ایمیل تایید رزرو به مشتری (برای پرداخت‌های اعتباری)
                if ($this->get_email_setting('enable_customer_processing_email')) {
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
     * ارسال ایمیل تایید رزرو به مشتری
     */
    public function send_customer_booking_confirmation($order) {
        if (!$this->get_email_setting('enable_customer_confirmation_email')) {
            return;
        }

        $customer_email = $order->get_billing_email();
        $subject = $this->get_email_subject('customer_confirmation');
        $message = $this->get_customer_confirmation_email_content($order);

        // پیوست کردن بلیط‌ها
        $attachments = $this->get_ticket_attachments($order);

        $this->send_email($customer_email, $subject, $message, 'customer_confirmation', $attachments);
    }

    /**
     * ارسال ایمیل لغو رزرو به مشتری
     */
    public function send_customer_booking_cancellation($order) {
        if (!$this->get_email_setting('enable_customer_cancellation_email')) {
            return;
        }

        $customer_email = $order->get_billing_email();
        $subject = $this->get_email_subject('customer_cancellation');
        $message = $this->get_customer_cancellation_email_content($order);

        $this->send_email($customer_email, $subject, $message, 'customer_cancellation');
    }

    /**
     * ارسال ایمیل اطلاع‌رسانی رزرو جدید به ادمین
     */
    public function send_admin_new_booking_notification($order_id, $passengers) {
        if (!$this->get_email_setting('enable_admin_new_booking_email')) {
            return;
        }

        $admin_email = $this->get_admin_email();
        $subject = $this->get_email_subject('admin_new_booking');
        $message = $this->get_admin_new_booking_email_content($order_id, $passengers);

        $this->send_email($admin_email, $subject, $message, 'admin_new_booking');
    }

    /**
     * ارسال ایمیل اطلاع‌رسانی رزرو منقضی شده به ادمین
     */
    public function send_admin_expired_reservation_notification($reservation_id) {
        if (!$this->get_email_setting('enable_admin_expired_reservation_email')) {
            return;
        }

        $admin_email = $this->get_admin_email();
        $subject = $this->get_email_subject('admin_expired_reservation');
        $message = $this->get_admin_expired_reservation_email_content($reservation_id);

        $this->send_email($admin_email, $subject, $message, 'admin_expired_reservation');
    }

    /**
     * دریافت تنظیمات ایمیل
     */
    private function get_email_setting($setting_key) {
        $defaults = array(
            'enable_customer_confirmation_email' => true,
            'enable_customer_cancellation_email' => true,
            'enable_customer_processing_email' => false,
            'enable_admin_new_booking_email' => true,
            'enable_admin_expired_reservation_email' => false,
            'admin_email' => get_option('admin_email'),
            'from_name' => get_bloginfo('name'),
            'from_email' => get_option('admin_email'),
        );

        $settings = get_option('vsbbm_email_settings', array());
        $settings = wp_parse_args($settings, $defaults);

        return isset($settings[$setting_key]) ? $settings[$setting_key] : $defaults[$setting_key];
    }

    /**
     * دریافت موضوع ایمیل
     */
    private function get_email_subject($email_type) {
        $subjects = array(
            'customer_confirmation' => __('تایید رزرو صندلی', 'vs-bus-booking-manager'),
            'customer_cancellation' => __('لغو رزرو صندلی', 'vs-bus-booking-manager'),
            'admin_new_booking' => __('رزرو جدید صندلی', 'vs-bus-booking-manager'),
            'admin_expired_reservation' => __('رزرو منقضی شده', 'vs-bus-booking-manager'),
        );

        $custom_subject = $this->get_email_setting("{$email_type}_subject");
        return $custom_subject ?: $subjects[$email_type];
    }

    /**
     * دریافت ایمیل ادمین
     */
    private function get_admin_email() {
        return $this->get_email_setting('admin_email');
    }

    /**
     * ارسال ایمیل
     */
    private function send_email($to, $subject, $message, $email_type, $attachments = array()) {
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->get_email_setting('from_name') . ' <' . $this->get_email_setting('from_email') . '>'
        );

        // اضافه کردن BCC اگر تنظیم شده باشد
        if ($email_type === 'customer_confirmation' && $this->get_email_setting('bcc_admin_on_customer_emails')) {
            $headers[] = 'Bcc: ' . $this->get_admin_email();
        }

        $sent = wp_mail($to, $subject, $message, $headers, $attachments);

        if ($sent) {
            error_log("VSBBM Email sent successfully: {$email_type} to {$to}");
        } else {
            error_log("VSBBM Email failed: {$email_type} to {$to}");
        }

        return $sent;
    }

    /**
     * دریافت پیوست‌های بلیط برای سفارش
     */
    private function get_ticket_attachments($order) {
        $attachments = array();

        if (class_exists('VSBBM_Ticket_Manager')) {
            $tickets = VSBBM_Ticket_Manager::get_tickets_for_order($order->get_id());

            foreach ($tickets as $ticket) {
                if ($ticket->pdf_path) {
                    $file_path = wp_upload_dir()['basedir'] . $ticket->pdf_path;
                    if (file_exists($file_path)) {
                        $attachments[] = $file_path;
                    }
                }
            }
        }

        return $attachments;
    }

    /**
     * تولید محتوای ایمیل تایید رزرو برای مشتری
     */
    private function get_customer_confirmation_email_content($order) {
        $passengers = $this->get_passengers_from_order($order);
        $product_info = $this->get_product_info_from_order($order);

        ob_start();
        include VSBBM_PLUGIN_PATH . 'templates/emails/customer-booking-confirmation.php';
        return ob_get_clean();
    }

    /**
     * تولید محتوای ایمیل لغو رزرو برای مشتری
     */
    private function get_customer_cancellation_email_content($order) {
        $passengers = $this->get_passengers_from_order($order);
        $product_info = $this->get_product_info_from_order($order);

        ob_start();
        include VSBBM_PLUGIN_PATH . 'templates/emails/customer-booking-cancellation.php';
        return ob_get_clean();
    }

    /**
     * تولید محتوای ایمیل اطلاع‌رسانی رزرو جدید برای ادمین
     */
    private function get_admin_new_booking_email_content($order_id, $passengers) {
        $order = wc_get_order($order_id);
        $product_info = $this->get_product_info_from_order($order);

        ob_start();
        include VSBBM_PLUGIN_PATH . 'templates/emails/admin-new-booking.php';
        return ob_get_clean();
    }

    /**
     * تولید محتوای ایمیل اطلاع‌رسانی رزرو منقضی شده برای ادمین
     */
    private function get_admin_expired_reservation_email_content($reservation_id) {
        $reservation = VSBBM_Seat_Reservations::get_reservation_details_by_id($reservation_id);

        ob_start();
        include VSBBM_PLUGIN_PATH . 'templates/emails/admin-expired-reservation.php';
        return ob_get_clean();
    }

    /**
     * دریافت اطلاعات مسافران از سفارش
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
     * ارسال ایمیل یادآوری رزرو (برای استفاده در آینده)
     */
    public function send_booking_reminder($order_id, $days_before = 1) {
        if (!$this->get_email_setting('enable_customer_reminder_email')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) return;

        $customer_email = $order->get_billing_email();
        $subject = sprintf(__('یادآوری رزرو - %d روز دیگر', 'vs-bus-booking-manager'), $days_before);
        $message = $this->get_customer_reminder_email_content($order, $days_before);

        $this->send_email($customer_email, $subject, $message, 'customer_reminder');
    }

    /**
     * تولید محتوای ایمیل یادآوری برای مشتری
     */
    private function get_customer_reminder_email_content($order, $days_before) {
        $passengers = $this->get_passengers_from_order($order);
        $product_info = $this->get_product_info_from_order($order);

        ob_start();
        include VSBBM_PLUGIN_PATH . 'templates/emails/customer-booking-reminder.php';
        return ob_get_clean();
    }
}

// Initialize the class
VSBBM_Email_Notifications::get_instance();