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

        // دریافت اطلاعات مسافران
        $passengers = $this->get_passengers_from_order($order);

        if (empty($passengers)) return;

        // تولید بلیط برای هر مسافر
        foreach ($passengers as $passenger) {
            $this->generate_single_ticket($order, $passenger);
        }

        error_log('VSBBM: Generated tickets for order: ' . $order_id);
    }

    /**
     * تولید یک بلیط
     */
    private function generate_single_ticket($order, $passenger_data) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        // تولید شماره بلیط منحصر به فرد
        $ticket_number = $this->generate_ticket_number($order->get_id());

        // تولید داده QR code
        $qr_data = $this->generate_qr_data($order, $passenger_data, $ticket_number);

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
    private function generate_qr_data($order, $passenger_data, $ticket_number) {
        $data = array(
            'ticket_number' => $ticket_number,
            'order_id' => $order->get_id(),
            'passenger_name' => $passenger_data['name'] ?? '',
            'passenger_national_id' => $passenger_data['national_id'] ?? '',
            'seat_number' => $passenger_data['seat_number'] ?? '',
            'issued_at' => current_time('timestamp'),
            'valid_until' => strtotime('+30 days', current_time('timestamp')) // اعتبار 30 روزه
        );

        return wp_json_encode($data);
    }

    /**
     * تولید فایل QR code
     */
    private function generate_qr_code_file($ticket_id, $qr_data) {
        // استفاده از لایبرری chillerlan/php-qrcode
        if (!class_exists('chillerlan\QRCode\QRCode')) {
            require_once VSBBM_PLUGIN_PATH . 'vendor/autoload.php';
        }

        $upload_dir = wp_upload_dir();
        $qr_dir = $upload_dir['basedir'] . '/vsbbm-qr-codes/';

        // ایجاد دایرکتوری اگر وجود ندارد
        if (!file_exists($qr_dir)) {
            wp_mkdir_p($qr_dir);
        }

        $filename = 'qr_' . $ticket_id . '.png';
        $filepath = $qr_dir . $filename;

        // تولید QR code
        $qrCode = new \chillerlan\QRCode\QRCode();
        $qrCode->render($qr_data, $filepath);

        // ذخیره مسیر در دیتابیس
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        $relative_path = str_replace($upload_dir['basedir'], '', $filepath);

        $wpdb->update($table_name,
            array('qr_code_path' => $relative_path),
            array('id' => $ticket_id)
        );
    }

    /**
     * تولید PDF بلیط
     */
    private function generate_pdf_ticket($ticket_id, $order, $passenger_data, $ticket_number) {
        // استفاده از لایبرری TCPDF
        if (!class_exists('TCPDF')) {
            require_once VSBBM_PLUGIN_PATH . 'vendor/autoload.php';
        }

        // ایجاد PDF
        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        // تنظیمات PDF
        $pdf->SetCreator('VS Bus Booking Manager');
        $pdf->SetAuthor('VernaSoft');
        $pdf->SetTitle('Bus Ticket - ' . $ticket_number);
        $pdf->SetSubject('Electronic Bus Ticket');

        // حذف header/footer پیش‌فرض
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // اضافه کردن صفحه
        $pdf->AddPage();

        // تولید محتوای HTML
        $html = $this->get_ticket_html_template($order, $passenger_data, $ticket_number, $ticket_id);

        // نوشتن HTML در PDF
        $pdf->writeHTML($html, true, false, true, false, '');

        // اضافه کردن QR code
        $this->add_qr_to_pdf($pdf, $ticket_id);

        // ذخیره فایل
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/vsbbm-tickets/';

        if (!file_exists($pdf_dir)) {
            wp_mkdir_p($pdf_dir);
        }

        $filename = 'ticket_' . $ticket_number . '.pdf';
        $filepath = $pdf_dir . $filename;

        $pdf->Output($filepath, 'F');

        // ذخیره مسیر در دیتابیس
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        $relative_path = str_replace($upload_dir['basedir'], '', $filepath);

        $wpdb->update($table_name,
            array('pdf_path' => $relative_path),
            array('id' => $ticket_id)
        );
    }

    /**
     * قالب HTML بلیط
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

        ob_start();
        ?>
        <style>
            .ticket-container {
                font-family: 'DejaVu Sans', Arial, sans-serif;
                border: 2px solid #333;
                border-radius: 10px;
                padding: 20px;
                max-width: 600px;
                margin: 0 auto;
                background: linear-gradient(135deg, #f5f5f5 0%, #ffffff 100%);
            }
            .ticket-header {
                text-align: center;
                border-bottom: 2px solid #333;
                padding-bottom: 15px;
                margin-bottom: 20px;
            }
            .ticket-title {
                font-size: 24px;
                font-weight: bold;
                color: #333;
                margin-bottom: 5px;
            }
            .ticket-subtitle {
                font-size: 14px;
                color: #666;
            }
            .ticket-info {
                display: table;
                width: 100%;
                margin-bottom: 20px;
            }
            .info-row {
                display: table-row;
            }
            .info-label {
                display: table-cell;
                font-weight: bold;
                padding: 8px 15px 8px 0;
                width: 150px;
                background: #f0f0f0;
                border-bottom: 1px solid #ddd;
            }
            .info-value {
                display: table-cell;
                padding: 8px 0;
                border-bottom: 1px solid #ddd;
            }
            .passenger-section {
                background: #fff8e1;
                padding: 15px;
                border-radius: 8px;
                margin: 20px 0;
                border-left: 4px solid #ffc107;
            }
            .qr-placeholder {
                text-align: center;
                margin: 20px 0;
                padding: 20px;
                background: #f9f9f9;
                border: 1px dashed #ccc;
                border-radius: 5px;
            }
            .ticket-footer {
                text-align: center;
                font-size: 12px;
                color: #666;
                margin-top: 20px;
                border-top: 1px solid #ddd;
                padding-top: 15px;
            }
        </style>

        <div class="ticket-container">
            <div class="ticket-header">
                <div class="ticket-title">بلیط اتوبوس الکترونیکی</div>
                <div class="ticket-subtitle">Electronic Bus Ticket</div>
            </div>

            <div class="ticket-info">
                <div class="info-row">
                    <div class="info-label">شماره بلیط:</div>
                    <div class="info-value"><?php echo esc_html($ticket_number); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">شماره سفارش:</div>
                    <div class="info-value">#<?php echo esc_html($order->get_id()); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">سرویس:</div>
                    <div class="info-value"><?php echo esc_html($product_name); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">تاریخ صدور:</div>
                    <div class="info-value"><?php echo date_i18n('Y/m/d H:i', current_time('timestamp')); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">وضعیت:</div>
                    <div class="info-value">فعال / Active</div>
                </div>
            </div>

            <div class="passenger-section">
                <h3 style="margin-top: 0; color: #f57c00;">اطلاعات مسافر</h3>
                <div class="ticket-info">
                    <div class="info-row">
                        <div class="info-label">نام و نام خانوادگی:</div>
                        <div class="info-value"><?php echo esc_html($passenger_data['name'] ?? ''); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">کد ملی:</div>
                        <div class="info-value"><?php echo esc_html($passenger_data['national_id'] ?? ''); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">شماره صندلی:</div>
                        <div class="info-value"><?php echo esc_html($passenger_data['seat_number'] ?? ''); ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">شماره تماس:</div>
                        <div class="info-value"><?php echo esc_html($passenger_data['phone'] ?? ''); ?></div>
                    </div>
                </div>
            </div>

            <div class="qr-placeholder">
                <strong>QR Code برای تایید بلیط</strong><br>
                <em>این بخش در فایل PDF با QR Code جایگزین خواهد شد</em>
            </div>

            <div class="ticket-footer">
                <p><strong>مهم:</strong> لطفا این بلیط را در زمان سوار شدن به همراه داشته باشید.</p>
                <p>این بلیط تا ۳۰ روز پس از صدور معتبر می‌باشد.</p>
                <p>ساخته شده توسط سیستم رزرواسیون VernaSoft</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
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
        $passengers = array();

        foreach ($order->get_items() as $item) {
            $item_passengers = array();
            foreach ($item->get_meta_data() as $meta) {
                if (strpos($meta->key, 'مسافر') !== false) {
                    // استخراج شماره مسافر از کلید
                    preg_match('/مسافر (\d+)/', $meta->key, $matches);
                    if ($matches) {
                        $passenger_num = $matches[1];
                        $item_passengers[$passenger_num] = $meta->value;
                    }
                }
            }

            if (!empty($item_passengers)) {
                // تبدیل به فرمت مناسب
                foreach ($item_passengers as $num => $data) {
                    $passenger_info = json_decode($data, true);
                    if ($passenger_info) {
                        $passengers[] = $passenger_info;
                    }
                }
            }
        }

        return $passengers;
    }

    /**
     * اضافه کردن endpoint دانلود بلیط
     */
    public function add_ticket_download_endpoint() {
        add_rewrite_endpoint('ticket-download', EP_ROOT | EP_PAGES);
    }

    /**
     * هندل دانلود بلیط
     */
    public function handle_ticket_download() {
        if (!is_user_logged_in()) {
            wp_die('شما باید وارد سیستم شوید.');
        }

        $ticket_id = isset($_GET['ticket_id']) ? intval($_GET['ticket_id']) : 0;
        if (!$ticket_id) {
            wp_die('شماره بلیط نامعتبر است.');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        $ticket = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $ticket_id
        ));

        if (!$ticket) {
            wp_die('بلیط یافت نشد.');
        }

        // بررسی مالکیت بلیط
        $order = wc_get_order($ticket->order_id);
        if (!$order || $order->get_customer_id() !== get_current_user_id()) {
            wp_die('شما دسترسی به این بلیط را ندارید.');
        }

        // دانلود فایل PDF
        if ($ticket->pdf_path) {
            $upload_dir = wp_upload_dir();
            $file_path = $upload_dir['basedir'] . $ticket->pdf_path;

            if (file_exists($file_path)) {
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="ticket_' . $ticket->ticket_number . '.pdf"');
                header('Content-Length: ' . filesize($file_path));
                readfile($file_path);
                exit;
            }
        }

        wp_die('فایل بلیط یافت نشد.');
    }

    /**
     * اضافه کردن منوی بلیط به حساب کاربری
     */
    public function add_ticket_menu_to_account($items) {
        $items['tickets'] = 'بلیط‌های من';
        return $items;
    }

    /**
     * نمایش بلیط‌ها در حساب کاربری
     */
    public function display_tickets_in_account() {
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        // دریافت سفارشات کاربر
        $customer_orders = wc_get_orders(array(
            'customer' => $user_id,
            'status' => array('completed', 'processing'),
            'limit' => -1
        ));

        $order_ids = array_map(function($order) {
            return $order->get_id();
        }, $customer_orders);

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
                <div class="tickets-list">
                    <?php foreach ($tickets as $ticket): ?>
                        <div class="ticket-card <?php echo esc_attr($ticket->status); ?>">
                            <div class="ticket-header">
                                <span class="ticket-number"><?php echo esc_html($ticket->ticket_number); ?></span>
                                <span class="ticket-status status-<?php echo esc_attr($ticket->status); ?>">
                                    <?php echo $this->get_status_label($ticket->status); ?>
                                </span>
                            </div>

                            <div class="ticket-info">
                                <p><strong>شماره سفارش:</strong> #<?php echo esc_html($ticket->order_id); ?></p>
                                <p><strong>تاریخ صدور:</strong> <?php echo date_i18n('Y/m/d H:i', strtotime($ticket->created_at)); ?></p>

                                <?php
                                $passenger_data = json_decode($ticket->passenger_data, true);
                                if ($passenger_data):
                                ?>
                                    <p><strong>مسافر:</strong> <?php echo esc_html($passenger_data['name'] ?? ''); ?></p>
                                    <p><strong>صندلی:</strong> <?php echo esc_html($passenger_data['seat_number'] ?? ''); ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="ticket-actions">
                                <?php if ($ticket->status === 'active'): ?>
                                    <a href="<?php echo wc_get_endpoint_url('ticket-download', 'ticket_id=' . $ticket->id); ?>"
                                       class="button download-ticket" target="_blank">
                                        دانلود PDF
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p>هیچ بلیطی یافت نشد.</p>
            <?php endif; ?>
        </div>

        <style>
            .vsbbm-account-tickets {
                max-width: 800px;
            }
            .tickets-list {
                display: grid;
                gap: 20px;
            }
            .ticket-card {
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 20px;
                background: white;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            .ticket-card.active {
                border-left: 4px solid #28a745;
            }
            .ticket-card.used {
                border-left: 4px solid #17a2b8;
                opacity: 0.7;
            }
            .ticket-card.cancelled {
                border-left: 4px solid #dc3545;
                opacity: 0.7;
            }
            .ticket-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 15px;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            .ticket-number {
                font-size: 18px;
                font-weight: bold;
                color: #333;
            }
            .ticket-status {
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: bold;
            }
            .status-active {
                background: #d4edda;
                color: #155724;
            }
            .status-used {
                background: #d1ecf1;
                color: #0c5460;
            }
            .status-cancelled {
                background: #f8d7da;
                color: #721c24;
            }
            .ticket-info p {
                margin: 5px 0;
                color: #666;
            }
            .ticket-actions {
                margin-top: 15px;
                text-align: left;
            }
            .download-ticket {
                background: #007cba;
                color: white;
                padding: 8px 16px;
                text-decoration: none;
                border-radius: 4px;
                display: inline-block;
            }
            .download-ticket:hover {
                background: #005a87;
            }
        </style>
        <?php
    }

    /**
     * دریافت لیبل وضعیت
     */
    private function get_status_label($status) {
        $labels = array(
            'active' => 'فعال',
            'used' => 'استفاده شده',
            'cancelled' => 'لغو شده',
            'expired' => 'منقضی شده'
        );

        return $labels[$status] ?? $status;
    }

    /**
     * دریافت بلیط‌های یک سفارش
     */
    public static function get_tickets_for_order($order_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_id = %d ORDER BY created_at ASC",
            $order_id
        ));
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