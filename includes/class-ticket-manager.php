<?php
defined('ABSPATH') || exit;

/**
 * Class VSBBM_Ticket_Manager
 * مدیریت سیستم بلیط الکترونیکی با QR Code
 */
class VSBBM_Ticket_Manager {

    private static $instance = null;
    private static $table_name = 'vsbbm_tickets';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // اطمینان از لود شدن کلاس‌های مورد نیاز
        if (!class_exists('VSBBM_Seat_Manager')) {
            require_once VSBBM_PLUGIN_PATH . 'includes/class-seat-manager.php';
        }
        $this->init_hooks();
    }

    private function init_hooks() {
        // ایجاد جدول دیتابیس
        register_activation_hook(VSBBM_PLUGIN_PATH . 'vs-bus-booking-manager.php', array($this, 'create_table'));

        // تولید بلیط هنگام تکمیل سفارش
        add_action('woocommerce_order_status_completed', array($this, 'generate_tickets_for_order'), 10, 1);
        add_action('woocommerce_order_status_processing', array($this, 'generate_tickets_for_order'), 10, 1);

        // اضافه کردن endpoint برای دانلود بلیط
        add_action('init', array($this, 'add_ticket_download_endpoint'));
        add_action('woocommerce_account_ticket-download_endpoint', array($this, 'handle_ticket_download'));

        // اضافه کردن منوی بلیط به حساب کاربری
        add_filter('woocommerce_account_menu_items', array($this, 'add_ticket_menu_to_account'));
        add_action('woocommerce_account_tickets_endpoint', array($this, 'display_tickets_in_account'));
    }

    /**
     * ایجاد جدول بلیط‌ها
     */
    public static function create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) NOT NULL,
            ticket_number VARCHAR(50) NOT NULL UNIQUE,
            qr_code_data TEXT,
            qr_code_path VARCHAR(255),
            pdf_path VARCHAR(255),
            passenger_data LONGTEXT,
            status ENUM('active', 'used', 'cancelled', 'expired') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            used_at TIMESTAMP NULL,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY order_status (order_id, status),
            KEY ticket_number (ticket_number),
            KEY status (status),
            KEY status_created (status, created_at),
            KEY created_at (created_at),
            KEY used_at (used_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        error_log('VSBBM: Tickets table created');
    }

    /**
     * تولید بلیط برای سفارش
     */
    public function generate_tickets_for_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        // بررسی اینکه آیا سفارش شامل رزرو صندلی است
        $has_seat_booking = false;
        foreach ($order->get_items() as $item) {
            if (VSBBM_Seat_Manager::is_seat_booking_enabled($item->get_product_id())) {
                $has_seat_booking = true;
                break;
            }
        }

        if (!$has_seat_booking) return;
        
        // --- گام D: دریافت تاریخ حرکت ---
        $departure_timestamp = $this->get_departure_timestamp_from_order($order);
        if (!$departure_timestamp) {
            error_log('VSBBM: No departure timestamp found for order: ' . $order_id);
            $departure_timestamp = ''; // Fallback
        }

        // دریافت اطلاعات مسافران
        $passengers = $this->get_passengers_from_order($order);

        if (empty($passengers)) return;

        // تولید بلیط برای هر مسافر
        foreach ($passengers as $passenger) {
            // --- گام D: ارسال تاریخ حرکت به تابع تولید بلیط ---
            $this->generate_single_ticket($order, $passenger, $departure_timestamp);
        }

        error_log('VSBBM: Generated tickets for order: ' . $order_id);
    }

    /**
     * تولید یک بلیط
     */
    private function generate_single_ticket($order, $passenger_data, $departure_timestamp = '') { // New parameter
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        // تولید شماره بلیط منحصر به فرد
        $ticket_number = $this->generate_ticket_number($order->get_id());

        // --- گام D: تولید داده QR code با تاریخ حرکت ---
        $qr_data = $this->generate_qr_data($order, $passenger_data, $ticket_number, $departure_timestamp);

        // --- گام D: اضافه کردن تاریخ حرکت به اطلاعات مسافر برای ذخیره در دیتابیس ---
        if (!empty($departure_timestamp)) {
            $passenger_data['_vsbbm_departure_timestamp'] = $departure_timestamp;
        }

        // ذخیره در دیتابیس
        $result = $wpdb->insert($table_name, array(
            'order_id' => $order->get_id(),
            'ticket_number' => $ticket_number,
            'qr_code_data' => $qr_data,
            'passenger_data' => wp_json_encode($passenger_data),
            'status' => 'active'
        ));

        if ($result) {
            $ticket_id = $wpdb->insert_id;

            // تولید فایل‌های QR و PDF
            $this->generate_qr_code_file($ticket_id, $qr_data);
            $this->generate_pdf_ticket($ticket_id, $order, $passenger_data, $ticket_number);

            error_log('VSBBM: Generated ticket ' . $ticket_number . ' for order ' . $order->get_id());
        }
    }

    /**
     * تولید شماره بلیط منحصر به فرد
     */
    private function generate_ticket_number($order_id) {
        $prefix = 'TCK';
        $timestamp = date('ymdHis');
        $random = strtoupper(substr(md5(uniqid()), 0, 4));
        return $prefix . $timestamp . $random;
    }

    /**
     * تولید داده QR code
     */
    private function generate_qr_data($order, $passenger_data, $ticket_number, $departure_timestamp = '') { // New parameter
        $data = array(
            'ticket_number' => $ticket_number,
            'order_id' => $order->get_id(),
            'passenger_name' => $passenger_data['name'] ?? '',
            'passenger_national_id' => $passenger_data['کد ملی'] ?? '',
            'seat_number' => $passenger_data['seat_number'] ?? '',
            // --- گام D: اضافه کردن تاریخ حرکت به QR Code ---
            'departure_timestamp' => $departure_timestamp,
            // ----------------------------------------------
            'issued_at' => current_time('timestamp')
        );

        return json_encode($data);
    }

    /**
     * تولید و ذخیره فایل QR Code
     */
    private function generate_qr_code_file($ticket_id, $qr_data) {
        // ... (فرض بر وجود کلاس یا توابع تولید QR Code است)
        // این بخش بدون تغییر باقی می‌ماند، فقط داده جدید در qr_data وجود دارد

        // مثال ساده:
        $qr_code_path = '/vsbbm/qr-' . $ticket_id . '.png';
        
        // در یک سیستم واقعی باید در اینجا تابع تولید QR Code فراخوانی و ذخیره شود.
        // update_qr_code_path_in_db($ticket_id, $qr_code_path); 

        return $qr_code_path;
    }

    /**
     * تولید فایل PDF بلیط
     */
    private function generate_pdf_ticket($ticket_id, $order, $passenger_data, $ticket_number) {
        // ... (فرض بر وجود کتابخانه mpdf است)
        // $mpdf = new \Mpdf\Mpdf([ ... ]);

        $html = $this->get_ticket_html_template($order, $passenger_data, $ticket_number, $ticket_id);
        
        // $mpdf->WriteHTML($html);
        // $this->add_qr_to_pdf($mpdf, $ticket_id);
        
        // $upload_dir = wp_upload_dir();
        // $file_path = '/vsbbm/ticket-' . $ticket_number . '.pdf';
        // $full_path = $upload_dir['basedir'] . $file_path;
        
        // $mpdf->Output($full_path, \Mpdf\Output\Destination::FILE);

        // update_pdf_path_in_db($ticket_id, $file_path); 

        return $file_path ?? null;
    }

    /**
     * دریافت تمپلیت HTML برای بلیط
     */
    private function get_ticket_html_template($order, $passenger_data, $ticket_number, $ticket_id) {
        $product_name = '';
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && VSBBM_Seat_Manager::is_seat_booking_enabled($product->get_id())) {
                $product_name = $product->get_name();
                break;
            }
        }
        
        // --- گام D: استخراج و فرمت‌دهی تاریخ حرکت ---
        $departure_timestamp = $passenger_data['_vsbbm_departure_timestamp'] ?? '';
        $departure_date_display = '';
        $departure_time_display = '';
        
        if (!empty($departure_timestamp)) {
            try {
                $datetime_obj = new DateTime($departure_timestamp);
                $timestamp_int = $datetime_obj->getTimestamp();
                
                // فرمت‌دهی به صورت تاریخ بومی
                $departure_date_display = date_i18n('Y/m/d', $timestamp_int); 
                $departure_time_display = date_i18n('H:i', $timestamp_int);
            } catch (Exception $e) {
                $departure_date_display = $departure_timestamp;
            }
        }
        // ---------------------------------------------------

        ob_start(); ?>
        <style>
            .ticket-container { font-family: 'DejaVu Sans', Arial, sans-serif; border: 2px solid #333; border-radius: 10px; padding: 20px; max-width: 600px; margin: 0 auto; background: linear-gradient(135deg, #f5f5f5 0%, #ffffff 100%); }
            .ticket-header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 15px; margin-bottom: 20px; }
            .ticket-title { font-size: 24px; font-weight: bold; color: #333; }
            .ticket-details { margin-bottom: 20px; }
            .detail-row { display: flex; justify-content: space-between; margin-bottom: 8px; border-bottom: 1px dotted #ccc; padding-bottom: 5px; }
            .detail-row .label { font-weight: bold; color: #555; }
            .detail-row .value { color: #000; }
            .passenger-info { border: 1px solid #ccc; padding: 15px; border-radius: 5px; background-color: #fff; margin-bottom: 20px; }
            .passenger-info h4 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 5px; }
            .ticket-footer { text-align: center; font-size: 12px; color: #777; border-top: 1px solid #ccc; padding-top: 10px; }
            .qr-placeholder { text-align: center; margin-top: 20px; }
        </style>
        <div class="ticket-container">
            <div class="ticket-header">
                <div class="ticket-title">بلیط الکترونیکی</div>
                <div>سفارش شماره: <?php echo esc_html($order->get_order_number()); ?></div>
            </div>

            <div class="ticket-details">
                <div class="detail-row">
                    <span class="label">محصول/سفر:</span>
                    <span class="value"><?php echo esc_html($product_name); ?></span>
                </div>
                <?php if (!empty($departure_date_display)): ?>
                <div class="detail-row">
                    <span class="label">تاریخ حرکت:</span>
                    <span class="value"><?php echo esc_html($departure_date_display); ?></span>
                </div>
                <div class="detail-row">
                    <span class="label">ساعت حرکت:</span>
                    <span class="value"><?php echo esc_html($departure_time_display); ?></span>
                </div>
                <?php endif; ?>
                <div class="detail-row">
                    <span class="label">شماره بلیط:</span>
                    <span class="value"><?php echo esc_html($ticket_number); ?></span>
                </div>
                
            </div>

            <div class="passenger-info">
                <h4>اطلاعات مسافر</h4>
                <div class="detail-row">
                    <span class="label">صندلی:</span>
                    <span class="value"><?php echo esc_html($passenger_data['seat_number'] ?? 'N/A'); ?></span>
                </div>
                <?php 
                foreach ($passenger_data as $key => $value) {
                    // نمایش بقیه فیلدهای مسافر به جز seat_number و فیلد داخلی تاریخ
                    if ($key !== 'seat_number' && $key !== '_vsbbm_departure_timestamp' && !empty($value)) {
                        $label = str_replace('_', ' ', $key);
                        ?>
                        <div class="detail-row">
                            <span class="label"><?php echo esc_html($label); ?>:</span>
                            <span class="value"><?php echo esc_html($value); ?></span>
                        </div>
                        <?php
                    }
                }
                ?>
            </div>

            <div class="qr-placeholder">
                <p style="font-size: 10px; color: #888;">فضای QR Code</p>
            </div>

            <div class="ticket-footer">
                <p>این بلیط تا یک روز پس از صدور معتبر می‌باشد.</p>
                <p>ساخته شده توسط سیستم رزرواسیون VernaSoft</p>
            </div>
        </div>
        <?php return ob_get_clean();
    }

    /**
     * اضافه کردن QR code به PDF
     */
    private function add_qr_to_pdf($pdf, $ticket_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT qr_code_path FROM $table_name WHERE id = %d",
            $ticket_id
        ));
        if ($ticket && $ticket->qr_code_path) {
            $upload_dir = wp_upload_dir();
            $qr_path = $upload_dir['basedir'] . $ticket->qr_code_path;
            if (file_exists($qr_path)) {
                // اضافه کردن QR code در موقعیت مناسب
                $pdf->Image($qr_path, 140, 200, 30, 30, 'PNG');
            }
        }
    }

    /**
     * دریافت اطلاعات مسافران از سفارش
     */
    private function get_passengers_from_order($order) {
        $passengers = [];
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            
            if ($product && VSBBM_Seat_Manager::is_seat_booking_enabled($product->get_id())) {
                $item_passengers = $item->get_meta('vsbbm_passengers', true);
                if (is_array($item_passengers)) {
                    $passengers = array_merge($passengers, $item_passengers);
                }
            }
        }
        return $passengers;
    }
    
    /**
     * گام D: تابع کمکی برای دریافت تاریخ حرکت از آیتم سفارش
     */
    private function get_departure_timestamp_from_order($order) {
        foreach ($order->get_items() as $item_id => $item) {
            if (VSBBM_Seat_Manager::is_seat_booking_enabled($item->get_product_id())) {
                $timestamp = $item->get_meta('_vsbbm_departure_timestamp', true);
                if (!empty($timestamp)) {
                    return $timestamp;
                }
            }
        }
        return false;
    }

    // ... (ادامه توابع دیگر کلاس VSBBM_Ticket_Manager)
    
    public function add_ticket_download_endpoint() {
        add_rewrite_endpoint('ticket-download', EP_ROOT | EP_PAGES);
    }

    public function handle_ticket_download() {
        if (!is_user_logged_in() || !isset(get_query_var('ticket-download')[0])) {
            wp_safe_redirect(wc_get_page_permalink('myaccount'));
            exit;
        }

        $ticket_id = absint(get_query_var('ticket-download')[0]);
        $this->download_ticket($ticket_id);
    }
    
    // ... (ادامه توابع add_ticket_menu_to_account و display_tickets_in_account و توابع دیگر)

    /**
     * دانلود فایل بلیط (PDF)
     */
    private function download_ticket($ticket_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $ticket_id
        ));

        if (!$ticket || empty($ticket->pdf_path)) {
            wc_add_notice('فایل بلیط مورد نظر یافت نشد.', 'error');
            return;
        }
        
        $order = wc_get_order($ticket->order_id);
        if (!$order || $order->get_customer_id() !== get_current_user_id()) {
            wc_add_notice('شما اجازه دسترسی به این بلیط را ندارید.', 'error');
            return;
        }
        
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . $ticket->pdf_path;

        if (!file_exists($file_path)) {
             wc_add_notice('فایل بلیط روی سرور یافت نشد. لطفا با پشتیبانی تماس بگیرید.', 'error');
             // تلاش برای تولید مجدد در صورت نیاز
             return;
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    }

    /**
     * اضافه کردن منوی بلیط به حساب کاربری
     */
    public function add_ticket_menu_to_account($items) {
        $items = array_slice($items, 0, 1, true) + 
                 array('tickets' => __('بلیط‌های من', 'vs-bus-booking-manager')) + 
                 array_slice($items, 1, count($items) - 1, true);
        return $items;
    }

    /**
     * نمایش لیست بلیط‌ها در حساب کاربری
     */
    public function display_tickets_in_account() {
        global $wpdb;
        $user_id = get_current_user_id();
        $table_name = $wpdb->prefix . self::$table_name;
        
        // دریافت سفارشات کاربر
        $customer_orders = wc_get_orders(array(
            'customer' => $user_id,
            'status' => array('completed', 'processing'),
            'limit' => -1
        ));
        
        $order_ids = array_map(function($order) { return $order->get_id(); }, $customer_orders);
        
        if (empty($order_ids)) {
            echo '<p>شما هنوز هیچ بلیطی ندارید.</p>';
            return;
        }

        // دریافت بلیط‌ها
        $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
        $tickets = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_id IN ($placeholders) ORDER BY created_at DESC", 
            $order_ids
        ));
        ?>
        <div class="vsbbm-account-tickets">
            <h2>بلیط‌های الکترونیکی من</h2>
            <?php if (!empty($tickets)): ?>
            <table class="woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders">
                <thead>
                    <tr>
                        <th><?php _e('شماره بلیط', 'vs-bus-booking-manager'); ?></th>
                        <th><?php _e('شماره صندلی', 'vs-bus-booking-manager'); ?></th>
                        <th><?php _e('وضعیت', 'vs-bus-booking-manager'); ?></th>
                        <th><?php _e('تاریخ صدور', 'vs-bus-booking-manager'); ?></th>
                        <th><?php _e('عملیات', 'vs-bus-booking-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $ticket): 
                        $passenger_data = json_decode($ticket->passenger_data, true);
                        $status_label = $this->get_ticket_status_label($ticket->status);
                    ?>
                    <tr>
                        <td data-title="<?php esc_attr_e('شماره بلیط', 'vs-bus-booking-manager'); ?>">
                            <?php echo esc_html($ticket->ticket_number); ?>
                        </td>
                        <td data-title="<?php esc_attr_e('شماره صندلی', 'vs-bus-booking-manager'); ?>">
                            <?php echo esc_html($passenger_data['seat_number'] ?? 'N/A'); ?>
                        </td>
                         <td data-title="<?php esc_attr_e('وضعیت', 'vs-bus-booking-manager'); ?>">
                            <span class="woocommerce-orders-table__badge woocommerce-orders-table__badge--<?php echo esc_attr($ticket->status); ?>"><?php echo esc_html($status_label); ?></span>
                        </td>
                        <td data-title="<?php esc_attr_e('تاریخ صدور', 'vs-bus-booking-manager'); ?>">
                            <time datetime="<?php echo esc_attr(date('Y-m-d H:i', strtotime($ticket->created_at))); ?>"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($ticket->created_at))); ?></time>
                        </td>
                        <td data-title="<?php esc_attr_e('عملیات', 'vs-bus-booking-manager'); ?>">
                            <?php if ($ticket->pdf_path): ?>
                                <a href="<?php echo esc_url(wc_get_endpoint_url('ticket-download', $ticket->id, wc_get_page_permalink('myaccount'))); ?>" class="woocommerce-button button">
                                    <?php _e('دانلود بلیط', 'vs-bus-booking-manager'); ?>
                                </a>
                            <?php else: ?>
                                <span><?php _e('در حال تولید...', 'vs-bus-booking-manager'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p><?php _e('شما هنوز هیچ بلیطی ندارید.', 'vs-bus-booking-manager'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * دریافت لیبل وضعیت بلیط
     */
    private function get_ticket_status_label($status) {
        $labels = array(
            'active' => 'فعال',
            'used' => 'استفاده شده',
            'cancelled' => 'لغو شده',
            'expired' => 'منقضی شده'
        );
        return $labels[$status] ?? $status;
    }

    /**
     * دریافت بلیط‌های یک سفارش (با کش)
     */
    public static function get_tickets_for_order($order_id) {
        // ... (فرض بر وجود VSBBM_Cache_Manager است)
        return false;
    }

    /**
     * بررسی اعتبار بلیط با QR code
     */
    public static function validate_ticket($qr_data) {
        $data = json_decode($qr_data, true);
        if (!$data || !isset($data['ticket_number'])) {
            return false;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE ticket_number = %s",
            $data['ticket_number']
        ));

        if (!$ticket) {
            return false;
        }

        // بررسی وضعیت
        if ($ticket->status !== 'active') {
            return false;
        }

        // بررسی تاریخ انقضا
        if (isset($data['valid_until']) && current_time('timestamp') > $data['valid_until']) {
            // بروزرسانی وضعیت به expired
            $wpdb->update($table_name,
                array('status' => 'expired'),
                array('id' => $ticket->id)
            );
            return false;
        }

        return $ticket;
    }

    /**
     * استفاده از بلیط (برای اسکن QR)
     */
    public static function use_ticket($ticket_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $result = $wpdb->update($table_name,
            array(
                'status' => 'used',
                'used_at' => current_time('mysql')
            ),
            array('id' => $ticket_id)
        );

        return $result;
    }

    /**
     * استفاده از بلیط با کد بلیط
     */
    public static function use_ticket_by_code($ticket_code) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        // یافتن بلیط با کد
        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM $table_name WHERE ticket_number = %s",
            $ticket_code
        ));

        if (!$ticket) {
            return new WP_Error('ticket_not_found', 'بلیط یافت نشد');
        }

        if ($ticket->status !== 'active') {
            return new WP_Error('ticket_not_active', 'بلیط فعال نیست');
        }

        // استفاده از بلیط
        $result = $wpdb->update($table_name,
            array(
                'status' => 'used',
                'used_at' => current_time('mysql')
            ),
            array('id' => $ticket->id)
        );

        if ($result) {
            return array(
                'success' => true,
                'message' => 'بلیط با موفقیت استفاده شد',
                'ticket_id' => $ticket->id
            );
        }

        return new WP_Error('ticket_use_failed', 'خطا در استفاده از بلیط');
    }
}

// Initialize the class
VSBBM_Ticket_Manager::get_instance();