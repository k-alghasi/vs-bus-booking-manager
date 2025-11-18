<?php
defined('ABSPATH') || exit;

class VSBBM_Admin_Interface {
    
    private static $instance = null;
    
    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menus'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_vsbbm_get_booking_details', array($this, 'get_booking_details_ajax'));
        add_action('wp_ajax_vsbbm_update_booking_status', array($this, 'update_booking_status_ajax'));
        add_action('wp_ajax_vsbbm_export_bookings', array($this, 'export_bookings_ajax'));
        add_action('wp_ajax_vsbbm_use_ticket', array($this, 'use_ticket_ajax'));

        // اضافه کردن hook برای نمایش اطلاعات مسافر در صفحه سفارش
        add_action('woocommerce_before_order_itemmeta', array($this, 'display_order_passenger_info'), 10, 3);

        // اضافه کردن هوک‌های جدید برای فیلدهای مسافر
        add_action('admin_menu', array($this, 'add_passenger_fields_settings'));
        add_action('admin_init', array($this, 'register_passenger_fields_settings'));

        // ذخیره تنظیمات کش
        add_action('admin_init', array($this, 'handle_cache_settings_save'));
    }
    
    public function add_admin_menus() {
        // منوی اصلی
        add_menu_page(
            'مدیریت رزرو اتوبوس',
            'رزرو اتوبوس',
            'manage_options',
            'vsbbm-dashboard',
            array($this, 'render_dashboard'),
            'dashicons-bus',
            30
        );
        
        // زیرمنوها
        add_submenu_page(
            'vsbbm-dashboard',
            'داشبورد',
            'داشبورد',
            'manage_options',
            'vsbbm-dashboard',
            array($this, 'render_dashboard')
        );
        
        add_submenu_page(
            'vsbbm-dashboard',
            'همه رزروها',
            'همه رزروها',
            'manage_options',
            'vsbbm-bookings',
            array($this, 'render_bookings_page')
        );
        
        add_submenu_page(
            'vsbbm-dashboard',
            'گزارش‌گیری',
            'گزارش‌گیری',
            'manage_options',
            'vsbbm-reports',
            array($this, 'render_reports_page')
        );

        add_submenu_page(
            'vsbbm-dashboard',
            'کش و بهینه‌سازی',
            'کش و بهینه‌سازی',
            'manage_options',
            'vsbbm-cache',
            array($this, 'render_cache_page')
        );

        add_submenu_page(
            'vsbbm-dashboard',
            'لیست سیاه',
            'لیست سیاه',
            'manage_options',
            'vsbbm-blacklist',
            array($this, 'render_blacklist_page')
        );
        
        add_submenu_page(
            'vsbbm-dashboard',
            'رزروها',
            'رزروها',
            'manage_options',
            'vsbbm-reservations',
            array($this, 'render_reservations_page')
        );

        add_submenu_page(
            'vsbbm-dashboard',
            'تنظیمات ایمیل',
            'تنظیمات ایمیل',
            'manage_options',
            'vsbbm-email-settings',
            array($this, 'render_email_settings_page')
        );

        add_submenu_page(
            'vsbbm-dashboard',
            'تنظیمات SMS',
            'تنظیمات SMS',
            'manage_options',
            'vsbbm-sms-settings',
            array($this, 'render_sms_settings_page')
        );

        add_submenu_page(
            'vsbbm-dashboard',
            'تنظیمات',
            'تنظیمات',
            'manage_options',
            'vsbbm-settings',
            array($this, 'render_settings_page')
        );

        add_submenu_page(
            'vsbbm-dashboard',
            'مدیریت بلیط‌ها',
            'بلیط‌ها',
            'manage_options',
            'vsbbm-tickets',
            array($this, 'render_tickets_page')
        );
    }
    
    /**
     * اضافه کردن منوی تنظیمات فیلدهای مسافر
     */
    public function add_passenger_fields_settings() {
        add_submenu_page(
            'vsbbm-dashboard',
            'تنظیمات فیلدهای مسافر',
            'فیلدهای مسافر',
            'manage_options',
            'vsbbm-passenger-fields',
            array($this, 'render_passenger_fields_settings')
        );
    }

    /**
     * ثبت تنظیمات فیلدهای مسافر
     */
    public function register_passenger_fields_settings() {
        register_setting('vsbbm_passenger_fields', 'vsbbm_passenger_fields', array(
            'sanitize_callback' => array($this, 'sanitize_passenger_fields')
        ));
    }
    
    public function enqueue_admin_scripts($hook) {
        // فقط در صفحات پلاگین ما لود شود
        if (strpos($hook, 'vsbbm-') !== false) {
            wp_enqueue_style('vsbbm-admin', VSBBM_PLUGIN_URL . 'assets/css/admin.css', array(), VSBBM_VERSION);
            wp_enqueue_script('vsbbm-admin', VSBBM_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), VSBBM_VERSION, true);
            
            // Chart.js برای نمودارها
            wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), null, true);
            
            // DataTables برای جدول‌ها
            wp_enqueue_style('data-tables', 'https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css');
            wp_enqueue_script('data-tables', 'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js', array('jquery'), null, true);
            wp_enqueue_script('data-tables-bootstrap', 'https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js', array('data-tables'), null, true);
            
            // localize script برای AJAX
            wp_localize_script('vsbbm-admin', 'vsbbm_admin', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('vsbbm_admin_nonce'),
                'i18n' => array(
                    'confirm_delete' => 'آیا از حذف این رزرو مطمئن هستید؟',
                    'loading' => 'در حال بارگذاری...',
                    'exporting' => 'در حال آماده‌سازی گزارش...'
                )
            ));
        }
    }
    
    public function render_dashboard() {
        $stats = $this->get_dashboard_stats();
        $recent_bookings = $this->get_recent_bookings(10);
        $weekly_data = $this->get_weekly_stats();
        
        include VSBBM_PLUGIN_PATH . 'templates/admin/dashboard.php';
    }
    
    public function render_bookings_page() {
        // پردازش actions
        $this->process_booking_actions();
        $this->process_bulk_booking_actions();

        // دریافت پارامترهای فیلتر
        $filters = array(
            'status' => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '',
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '',
            'date_to' => isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '',
            'search' => isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '',
            'product_id' => isset($_GET['product_id']) ? intval($_GET['product_id']) : ''
        );

        $bookings = $this->get_all_bookings($filters);
        $statuses = $this->get_booking_statuses();
        $products = $this->get_bus_products();

        include VSBBM_PLUGIN_PATH . 'templates/admin/bookings.php';
    }
    
    public function render_reports_page() {
        $report_type = isset($_GET['report_type']) ? sanitize_text_field($_GET['report_type']) : 'daily';
        $report_data = $this->generate_report($report_type);
        
        include VSBBM_PLUGIN_PATH . 'templates/admin/reports.php';
    }
    
    public function render_reservations_page() {
        // پردازش actions
        $this->process_reservation_actions();

        // دریافت پارامترهای فیلتر
        $filters = array(
            'status' => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '',
            'product_id' => isset($_GET['product_id']) ? intval($_GET['product_id']) : '',
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '',
            'date_to' => isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : ''
        );

        $reservations = $this->get_reservations($filters);
        $statuses = array(
            'reserved' => 'رزرو شده',
            'confirmed' => 'تایید شده',
            'cancelled' => 'لغو شده',
            'expired' => 'منقضی شده'
        );

        include VSBBM_PLUGIN_PATH . 'templates/admin/reservations.php';
    }

    public function render_email_settings_page() {
        // ذخیره تنظیمات
        if (isset($_POST['vsbbm_save_email_settings'])) {
            $this->save_email_settings();
        }

        $settings = $this->get_email_settings();

        ?>
        <div class="wrap">
            <h1>⚙️ تنظیمات اعلان‌های ایمیلی</h1>

            <div class="notice notice-info">
                <p>💡 <strong>توجه:</strong> تنظیمات ایمیل برای اطلاع‌رسانی خودکار رزروها و تغییرات سفارشات.</p>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('vsbbm_save_email_settings'); ?>

                <div class="card" style="max-width: 800px;">
                    <h3>📧 تنظیمات عمومی ایمیل</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="from_name">نام فرستنده</label></th>
                            <td>
                                <input type="text" name="from_name" id="from_name"
                                       value="<?php echo esc_attr($settings['from_name']); ?>"
                                       class="regular-text" required>
                                <p class="description">نامی که در فرستنده ایمیل نمایش داده می‌شود</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="from_email">ایمیل فرستنده</label></th>
                            <td>
                                <input type="email" name="from_email" id="from_email"
                                       value="<?php echo esc_attr($settings['from_email']); ?>"
                                       class="regular-text" required>
                                <p class="description">آدرس ایمیلی که ایمیل‌ها از آن ارسال می‌شود</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="admin_email">ایمیل مدیر</label></th>
                            <td>
                                <input type="email" name="admin_email" id="admin_email"
                                       value="<?php echo esc_attr($settings['admin_email']); ?>"
                                       class="regular-text" required>
                                <p class="description">آدرس ایمیلی که اعلان‌های ادمین به آن ارسال می‌شود</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="card" style="max-width: 800px; margin-top: 20px;">
                    <h3>👤 ایمیل‌های مشتری</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">تایید رزرو</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_customer_confirmation_email"
                                           value="1" <?php checked($settings['enable_customer_confirmation_email'], true); ?>>
                                    ارسال ایمیل تایید رزرو پس از تکمیل سفارش
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">لغو رزرو</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_customer_cancellation_email"
                                           value="1" <?php checked($settings['enable_customer_cancellation_email'], true); ?>>
                                    ارسال ایمیل اطلاع‌رسانی لغو رزرو
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">پردازش سفارش</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_customer_processing_email"
                                           value="1" <?php checked($settings['enable_customer_processing_email'], false); ?>>
                                    ارسال ایمیل تایید رزرو برای سفارشات در حال پردازش
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">یادآوری رزرو</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_customer_reminder_email"
                                           value="1" <?php checked($settings['enable_customer_reminder_email'], false); ?>>
                                    ارسال ایمیل یادآوری قبل از تاریخ حرکت (نیاز به تنظیم cron job)
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">BCC به ادمین</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="bcc_admin_on_customer_emails"
                                           value="1" <?php checked($settings['bcc_admin_on_customer_emails'], false); ?>>
                                    ارسال کپی ایمیل‌های مشتری به ادمین
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="card" style="max-width: 800px; margin-top: 20px;">
                    <h3>👨‍💼 ایمیل‌های ادمین</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">رزرو جدید</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_admin_new_booking_email"
                                           value="1" <?php checked($settings['enable_admin_new_booking_email'], true); ?>>
                                    ارسال اعلان رزرو جدید به ادمین
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">رزرو منقضی شده</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_admin_expired_reservation_email"
                                           value="1" <?php checked($settings['enable_admin_expired_reservation_email'], false); ?>>
                                    ارسال اعلان رزروهای منقضی شده به ادمین
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="card" style="max-width: 800px; margin-top: 20px;">
                    <h3>📝 موضوع‌های ایمیل</h3>
                    <p>می‌توانید موضوع پیش‌فرض ایمیل‌ها را تغییر دهید:</p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="customer_confirmation_subject">تایید رزرو مشتری</label></th>
                            <td>
                                <input type="text" name="customer_confirmation_subject" id="customer_confirmation_subject"
                                       value="<?php echo esc_attr($settings['customer_confirmation_subject'] ?: 'تایید رزرو صندلی'); ?>"
                                       class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="customer_cancellation_subject">لغو رزرو مشتری</label></th>
                            <td>
                                <input type="text" name="customer_cancellation_subject" id="customer_cancellation_subject"
                                       value="<?php echo esc_attr($settings['customer_cancellation_subject'] ?: 'لغو رزرو صندلی'); ?>"
                                       class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="admin_new_booking_subject">رزرو جدید ادمین</label></th>
                            <td>
                                <input type="text" name="admin_new_booking_subject" id="admin_new_booking_subject"
                                       value="<?php echo esc_attr($settings['admin_new_booking_subject'] ?: 'رزرو جدید صندلی'); ?>"
                                       class="regular-text">
                            </td>
                        </tr>
                    </table>
                </div>

                <p class="submit">
                    <input type="submit" name="vsbbm_save_email_settings" class="button button-primary"
                           value="💾 ذخیره تنظیمات">
                </p>
            </form>
        </div>

        <style>
            .card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
                margin-bottom: 20px;
            }
            .card h3 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
                color: #23282d;
            }
        </style>
        <?php
    }

    /**
     * نمایش صفحه تنظیمات SMS
     */
    public function render_sms_settings_page() {
        // ذخیره تنظیمات
        if (isset($_POST['vsbbm_save_sms_settings'])) {
            $this->save_sms_settings();
        }

        // تست اتصال
        if (isset($_POST['vsbbm_test_sms_connection'])) {
            $this->test_sms_connection();
        }

        $settings = $this->get_sms_settings();
        $supported_panels = VSBBM_SMS_Notifications::get_supported_panels();

        ?>
        <div class="wrap">
            <h1>📱 تنظیمات سیستم SMS</h1>

            <div class="notice notice-info">
                <p>💡 <strong>توجه:</strong> تنظیمات SMS برای ارسال پیامک‌های اطلاع‌رسانی خودکار رزروها و تغییرات سفارشات.</p>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('vsbbm_save_sms_settings'); ?>

                <div class="card" style="max-width: 800px;">
                    <h3>🔧 تنظیمات عمومی SMS</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="sms_panel">پنل SMS</label></th>
                            <td>
                                <select name="sms_panel" id="sms_panel" required>
                                    <option value="">-- انتخاب پنل --</option>
                                    <?php foreach ($supported_panels as $key => $label): ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($settings['sms_panel'], $key); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">پنل SMS مورد استفاده برای ارسال پیامک</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="test_phone_number">شماره تست</label></th>
                            <td>
                                <input type="tel" name="test_phone_number" id="test_phone_number"
                                       value="<?php echo esc_attr($settings['test_phone_number']); ?>"
                                       class="regular-text" placeholder="09123456789">
                                <p class="description">شماره تلفن برای تست اتصال پنل SMS</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="otp_expiry_minutes">زمان انقضا OTP (دقیقه)</label></th>
                            <td>
                                <input type="number" name="otp_expiry_minutes" id="otp_expiry_minutes"
                                       value="<?php echo esc_attr($settings['otp_expiry_minutes'] ?: 5); ?>"
                                       class="small-text" min="1" max="60">
                                <p class="description">زمان اعتبار کد تایید (پیش‌فرض: ۵ دقیقه)</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- تنظیمات پنل‌های مختلف -->
                <div id="panel-settings" style="display: none;">
                    <!-- IPPanel Settings -->
                    <div class="card panel-settings" id="ippanel-settings" style="max-width: 800px; margin-top: 20px; display: none;">
                        <h3>⚙️ تنظیمات IPPanel</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="ippanel_api_key">API Key</label></th>
                                <td>
                                    <input type="password" name="ippanel_api_key" id="ippanel_api_key"
                                           value="<?php echo esc_attr($settings['ippanel_api_key']); ?>"
                                           class="regular-text" required>
                                    <p class="description">کلید API از پنل IPPanel</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ippanel_originator">شماره فرستنده</label></th>
                                <td>
                                    <input type="text" name="ippanel_originator" id="ippanel_originator"
                                           value="<?php echo esc_attr($settings['ippanel_originator']); ?>"
                                           class="regular-text" placeholder="3000xxxxxx" required>
                                    <p class="description">شماره خط اختصاصی از IPPanel</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="ippanel_password">رمز عبور</label></th>
                                <td>
                                    <input type="password" name="ippanel_password" id="ippanel_password"
                                           value="<?php echo esc_attr($settings['ippanel_password']); ?>"
                                           class="regular-text">
                                    <p class="description">رمز عبور پنل IPPanel (در صورت نیاز)</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Kavenegar Settings -->
                    <div class="card panel-settings" id="kavenegar-settings" style="max-width: 800px; margin-top: 20px; display: none;">
                        <h3>⚙️ تنظیمات Kavenegar</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="kavenegar_api_key">API Key</label></th>
                                <td>
                                    <input type="password" name="kavenegar_api_key" id="kavenegar_api_key"
                                           value="<?php echo esc_attr($settings['kavenegar_api_key']); ?>"
                                           class="regular-text" required>
                                    <p class="description">کلید API از پنل Kavenegar</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="kavenegar_sender">شماره فرستنده</label></th>
                                <td>
                                    <input type="text" name="kavenegar_sender" id="kavenegar_sender"
                                           value="<?php echo esc_attr($settings['kavenegar_sender']); ?>"
                                           class="regular-text" placeholder="1000xxxxxx" required>
                                    <p class="description">شماره خط اختصاصی از Kavenegar</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- SMS.ir Settings -->
                    <div class="card panel-settings" id="smsir-settings" style="max-width: 800px; margin-top: 20px; display: none;">
                        <h3>⚙️ تنظیمات SMS.ir</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="smsir_api_key">API Key</label></th>
                                <td>
                                    <input type="password" name="smsir_api_key" id="smsir_api_key"
                                           value="<?php echo esc_attr($settings['smsir_api_key']); ?>"
                                           class="regular-text" required>
                                    <p class="description">کلید API از پنل SMS.ir</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="smsir_line_number">شماره خط</label></th>
                                <td>
                                    <input type="text" name="smsir_line_number" id="smsir_line_number"
                                           value="<?php echo esc_attr($settings['smsir_line_number']); ?>"
                                           class="regular-text" placeholder="3000xxxxxx" required>
                                    <p class="description">شماره خط اختصاصی از SMS.ir</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <div class="card" style="max-width: 800px; margin-top: 20px;">
                    <h3>📤 ارسال SMS به مشتریان</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">تایید رزرو</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_customer_confirmation_sms"
                                           value="1" <?php checked($settings['enable_customer_confirmation_sms'], true); ?>>
                                    ارسال SMS تایید رزرو پس از تکمیل سفارش
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">لغو رزرو</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_customer_cancellation_sms"
                                           value="1" <?php checked($settings['enable_customer_cancellation_sms'], true); ?>>
                                    ارسال SMS اطلاع‌رسانی لغو رزرو
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">استفاده از بلیط</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_ticket_used_sms"
                                           value="1" <?php checked($settings['enable_ticket_used_sms'], false); ?>>
                                    ارسال SMS اطلاع‌رسانی استفاده از بلیط
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">کد OTP</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_otp_sms"
                                           value="1" <?php checked($settings['enable_otp_sms'], true); ?>>
                                    ارسال SMS کد تایید شماره تلفن
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="card" style="max-width: 800px; margin-top: 20px;">
                    <h3>📝 الگوهای پیام SMS</h3>
                    <p>می‌توانید متن پیش‌فرض پیام‌های SMS را تغییر دهید:</p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="customer_confirmation_sms_template">تایید رزرو مشتری</label></th>
                            <td>
                                <textarea name="customer_confirmation_sms_template" id="customer_confirmation_sms_template"
                                          rows="3" class="large-text"><?php echo esc_textarea($settings['customer_confirmation_sms_template'] ?: "✅ رزرو شما تایید شد\nسفارش #[ORDER_ID]\nمبلغ: [AMOUNT]\nبرای مشاهده بلیط به حساب کاربری مراجعه کنید."); ?></textarea>
                                <p class="description">متغیرهای موجود: [ORDER_ID], [AMOUNT], [CUSTOMER_NAME]</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="customer_cancellation_sms_template">لغو رزرو مشتری</label></th>
                            <td>
                                <textarea name="customer_cancellation_sms_template" id="customer_cancellation_sms_template"
                                          rows="2" class="large-text"><?php echo esc_textarea($settings['customer_cancellation_sms_template'] ?: "❌ رزرو لغو شد\nسفارش #[ORDER_ID]\nمبلغ به حساب شما بازگردانده خواهد شد."); ?></textarea>
                                <p class="description">متغیرهای موجود: [ORDER_ID], [CUSTOMER_NAME]</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="otp_sms_template">کد OTP</label></th>
                            <td>
                                <textarea name="otp_sms_template" id="otp_sms_template"
                                          rows="2" class="large-text"><?php echo esc_textarea($settings['otp_sms_template'] ?: "کد تایید شما: [OTP_CODE]\nاین کد تا [EXPIRY_MINUTES] دقیقه معتبر است."); ?></textarea>
                                <p class="description">متغیرهای موجود: [OTP_CODE], [EXPIRY_MINUTES]</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <p class="submit" style="margin-top: 20px;">
                    <input type="submit" name="vsbbm_save_sms_settings" class="button button-primary"
                           value="💾 ذخیره تنظیمات">
                    <input type="submit" name="vsbbm_test_sms_connection" class="button button-secondary"
                           value="🧪 تست اتصال" style="margin-right: 10px;">
                </p>
            </form>
        </div>

        <style>
            .card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
                margin-bottom: 20px;
            }
            .card h3 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
                color: #23282d;
            }
            .panel-settings {
                border-left: 4px solid #667eea;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            function togglePanelSettings() {
                const selectedPanel = $('#sms_panel').val();
                $('.panel-settings').hide();
                $('#panel-settings').hide();

                if (selectedPanel) {
                    $('#panel-settings').show();
                    $('#' + selectedPanel + '-settings').show();
                }
            }

            $('#sms_panel').on('change', togglePanelSettings);
            togglePanelSettings(); // Initialize on page load
        });
        </script>
        <?php
    }

    /**
     * نمایش صفحه مدیریت کش
     */
    public function render_cache_page() {
        $cache_manager = VSBBM_Cache_Manager::get_instance();

        // پردازش پاکسازی کش
        if (isset($_POST['vsbbm_clear_cache']) && wp_verify_nonce($_POST['_wpnonce'], 'vsbbm_clear_cache')) {
            $cache_type = sanitize_text_field($_POST['cache_type'] ?? 'all');

            switch ($cache_type) {
                case 'all':
                    $cache_manager->clear_all_cache();
                    $message = 'تمام کش پاک شد.';
                    break;
                case 'products':
                    $cache_manager->clear_product_cache();
                    $message = 'کش محصولات پاک شد.';
                    break;
                case 'reservations':
                    $cache_manager->clear_reservation_cache();
                    $message = 'کش رزروها پاک شد.';
                    break;
                case 'tickets':
                    $cache_manager->clear_ticket_cache();
                    $message = 'کش بلیط‌ها پاک شد.';
                    break;
                case 'stats':
                    $cache_manager->clear_stats_cache();
                    $message = 'کش آمار پاک شد.';
                    break;
            }

            add_action('admin_notices', function() use ($message) {
                echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
            });
        }

        // دریافت آمار کش
        $cache_stats = $cache_manager->get_cache_stats();

        ?>
        <div class="wrap">
            <h1>🗂️ مدیریت کش و بهینه‌سازی</h1>

            <div class="notice notice-info">
                <p>💡 <strong>توجه:</strong> سیستم کش برای بهبود عملکرد استفاده می‌شود. پاکسازی کش ممکن است سرعت بارگذاری را موقتاً کاهش دهد.</p>
            </div>

            <!-- آمار کش -->
            <div class="vsbbm-cache-stats">
                <h3>📊 آمار کش</h3>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">📦</div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($cache_stats['total_keys'] ?? 0); ?></div>
                            <div class="stat-label">کل کلیدهای کش</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">⏱️</div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($cache_stats['hit_rate'] ?? 0, 1); ?>%</div>
                            <div class="stat-label">نرخ موفقیت کش</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">💾</div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($cache_stats['memory_usage'] ?? 0); ?> KB</div>
                            <div class="stat-label">استفاده از حافظه</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">🔄</div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($cache_stats['uptime'] ?? 0); ?>h</div>
                            <div class="stat-label">زمان فعالیت</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- پاکسازی کش -->
            <div class="vsbbm-cache-clear">
                <h3>🧹 پاکسازی کش</h3>
                <form method="post" action="">
                    <?php wp_nonce_field('vsbbm_clear_cache'); ?>

                    <div class="cache-options">
                        <div class="option-group">
                            <label>
                                <input type="radio" name="cache_type" value="all" checked>
                                <strong>تمام کش</strong> - پاکسازی کامل تمام داده‌های کش شده
                            </label>
                            <p class="description">پاکسازی تمام کش‌ها شامل محصولات، رزروها، بلیط‌ها و آمار</p>
                        </div>

                        <div class="option-group">
                            <label>
                                <input type="radio" name="cache_type" value="products">
                                <strong>کش محصولات</strong> - پاکسازی کش لیست محصولات و جزئیات
                            </label>
                            <p class="description">برای زمانی که محصولات را ویرایش کرده‌اید</p>
                        </div>

                        <div class="option-group">
                            <label>
                                <input type="radio" name="cache_type" value="reservations">
                                <strong>کش رزروها</strong> - پاکسازی کش صندلی‌های رزرو شده
                            </label>
                            <p class="description">برای زمانی که رزروها تغییر کرده‌اند</p>
                        </div>

                        <div class="option-group">
                            <label>
                                <input type="radio" name="cache_type" value="tickets">
                                <strong>کش بلیط‌ها</strong> - پاکسازی کش بلیط‌های الکترونیکی
                            </label>
                            <p class="description">برای زمانی که بلیط‌ها تغییر کرده‌اند</p>
                        </div>

                        <div class="option-group">
                            <label>
                                <input type="radio" name="cache_type" value="stats">
                                <strong>کش آمار</strong> - پاکسازی کش آمار و گزارش‌ها
                            </label>
                            <p class="description">برای بروزرسانی آمار داشبورد</p>
                        </div>
                    </div>

                    <p class="submit">
                        <input type="submit" name="vsbbm_clear_cache" class="button button-primary"
                               value="🗑️ پاکسازی کش انتخاب شده">
                    </p>
                </form>
            </div>

            <!-- تنظیمات کش -->
            <div class="vsbbm-cache-settings">
                <h3>⚙️ تنظیمات کش</h3>
                <form method="post" action="">
                    <?php wp_nonce_field('vsbbm_save_cache_settings'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="cache_enabled">فعال بودن کش</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cache_enabled" id="cache_enabled"
                                           value="1" <?php checked(get_option('vsbbm_cache_enabled', true), true); ?>>
                                    فعال بودن سیستم کش
                                </label>
                                <p class="description">غیرفعال کردن کش ممکن است عملکرد را کاهش دهد</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cache_ttl">زمان زندگی کش (ثانیه)</label></th>
                            <td>
                                <input type="number" name="cache_ttl" id="cache_ttl"
                                       value="<?php echo esc_attr(get_option('vsbbm_cache_ttl', 3600)); ?>"
                                       class="small-text" min="60" max="86400">
                                <p class="description">زمان نگهداری داده‌ها در کش (پیش‌فرض: ۱ ساعت)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cache_max_keys">حداکثر تعداد کلیدها</label></th>
                            <td>
                                <input type="number" name="cache_max_keys" id="cache_max_keys"
                                       value="<?php echo esc_attr(get_option('vsbbm_cache_max_keys', 1000)); ?>"
                                       class="small-text" min="100" max="10000">
                                <p class="description">حداکثر تعداد کلیدهای کش (پیش‌فرض: ۱۰۰۰)</p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <input type="submit" name="vsbbm_save_cache_settings" class="button button-primary"
                               value="💾 ذخیره تنظیمات">
                    </p>
                </form>
            </div>
        </div>

        <style>
            .vsbbm-cache-stats, .vsbbm-cache-clear, .vsbbm-cache-settings {
                background: white;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                margin-bottom: 20px;
            }

            .vsbbm-cache-stats h3, .vsbbm-cache-clear h3, .vsbbm-cache-settings h3 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 2px solid #667eea;
                color: #23282d;
            }

            .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                margin-top: 15px;
            }

            .stat-card {
                background: #f8f9fa;
                padding: 20px;
                border-radius: 8px;
                text-align: center;
                border-left: 4px solid #667eea;
                display: flex;
                align-items: center;
                gap: 15px;
            }

            .stat-icon {
                font-size: 24px;
            }

            .stat-content {
                flex: 1;
            }

            .stat-number {
                font-size: 24px;
                font-weight: bold;
                color: #333;
                margin-bottom: 5px;
            }

            .stat-label {
                font-size: 14px;
                color: #666;
            }

            .cache-options {
                margin: 20px 0;
            }

            .option-group {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 6px;
                margin-bottom: 10px;
                border-left: 4px solid #0073aa;
            }

            .option-group label {
                display: block;
                font-weight: bold;
                margin-bottom: 5px;
                cursor: pointer;
            }

            .option-group input[type="radio"] {
                margin-left: 0;
                margin-right: 8px;
            }

            .option-group .description {
                margin: 5px 0 0 20px;
                color: #666;
                font-style: italic;
            }
        </style>
        <?php
    }

    public function render_blacklist_page() {
        // این متد از کلاس blacklist استفاده می‌کند
        VSBBM_Blacklist::render_admin_page();
    }
    
    public function render_settings_page() {
        // ذخیره تنظیمات
        if (isset($_POST['vsbbm_save_settings'])) {
            $this->save_settings();
        }
        
        $settings = $this->get_settings();
        
        include VSBBM_PLUGIN_PATH . 'templates/admin/settings.php';
    }
    
    /**
     * نمایش صفحه تنظیمات فیلدهای مسافر
     */
    public function render_passenger_fields_settings() {
        $fields = get_option('vsbbm_passenger_fields', array(
            array('type' => 'text', 'label' => 'نام کامل', 'required' => true, 'placeholder' => 'نام و نام خانوادگی', 'locked' => false),
            array('type' => 'text', 'label' => 'کد ملی', 'required' => true, 'placeholder' => 'کد ملی ۱۰ رقمی', 'locked' => true),
            array('type' => 'tel', 'label' => 'شماره تماس', 'required' => true, 'placeholder' => '09xxxxxxxxx', 'locked' => false),
        ));
        ?>
        <div class="wrap">
            <h1>⚙️ تنظیمات فیلدهای اطلاعات مسافر</h1>
            
            <div class="notice notice-info">
                <p>💡 <strong>توجه:</strong> فیلد "کد ملی" قفل شده است زیرا سیستم لیست سیاه بر اساس آن کار می‌کند.</p>
            </div>
            
            <form method="post" action="options.php">
                <?php settings_fields('vsbbm_passenger_fields'); ?>
                
                <div class="card" style="max-width: 800px;">
                    <h3>فیلدهای اطلاعات مسافر</h3>
                    <p>فیلدهایی که در فرم رزرو صندلی نمایش داده می‌شوند را مدیریت کنید.</p>
                    
                    <div id="vsbbm-fields-container">
    <?php foreach ($fields as $index => $field): 
        $is_locked = ($field['label'] === 'کد ملی'); // فقط کد ملی قفل شود
        $is_national_code = ($field['label'] === 'کد ملی');
    ?>
    <div class="field-group <?php echo $is_locked ? 'locked-field' : ''; ?>" 
         style="background: <?php echo $is_locked ? '#fff3cd' : '#f9f9f9'; ?>; 
                padding: 15px; margin: 10px 0; border-radius: 5px; 
                border-left: 4px solid <?php echo $is_locked ? '#ffc107' : '#0073aa'; ?>;">
        
        <?php if ($is_locked): ?>
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px; padding: 5px 10px; background: #fff8e1; border-radius: 3px;">
            <span style="color: #856404;">🔒 این فیلد قفل شده است (سیستم لیست سیاه)</span>
        </div>
        <?php endif; ?>
        
        <div style="display: grid; grid-template-columns: 2fr 2fr 1fr 1fr <?php echo $is_locked ? '0.5fr' : '1fr'; ?>; gap: 10px; align-items: end;">
            <div>
                <label>عنوان فیلد</label>
                <input type="text" 
                       name="vsbbm_passenger_fields[<?php echo $index; ?>][label]" 
                       value="<?php echo esc_attr($field['label']); ?>" 
                       style="width: 100%; <?php echo $is_locked ? 'background: #f8f9fa;' : ''; ?>" 
                       <?php echo $is_locked ? 'readonly' : 'required'; ?>>
            </div>
            
            <div>
                <label>Placeholder</label>
                <input type="text" 
                       name="vsbbm_passenger_fields[<?php echo $index; ?>][placeholder]" 
                       value="<?php echo esc_attr($field['placeholder']); ?>" 
                       style="width: 100%; <?php echo $is_locked ? 'background: #f8f9fa;' : ''; ?>" 
                       <?php echo $is_locked ? 'readonly' : ''; ?>>
            </div>
            
            <div>
                <label>نوع فیلد</label>
                <select name="vsbbm_passenger_fields[<?php echo $index; ?>][type]" 
                        style="width: 100%; <?php echo $is_locked ? 'background: #f8f9fa;' : ''; ?>" 
                        <?php echo $is_locked ? 'disabled' : ''; ?>>
                    <option value="text" <?php selected($field['type'], 'text'); ?>>متنی</option>
                    <option value="tel" <?php selected($field['type'], 'tel'); ?>>تلفن</option>
                    <option value="email" <?php selected($field['type'], 'email'); ?>>ایمیل</option>
                    <option value="number" <?php selected($field['type'], 'number'); ?>>عدد</option>
                    <option value="select" <?php selected($field['type'], 'select'); ?>>انتخابگر</option>
                </select>
                <?php if ($is_locked): ?>
                <input type="hidden" name="vsbbm_passenger_fields[<?php echo $index; ?>][type]" value="<?php echo esc_attr($field['type']); ?>">
                <?php endif; ?>
            </div>
            
            <div>
                <label>
                    <input type="checkbox" 
                           name="vsbbm_passenger_fields[<?php echo $index; ?>][required]" 
                           value="1" <?php checked($field['required'], true); ?>
                           <?php echo $is_locked ? 'disabled' : ''; ?>>
                    اجباری
                    <?php if ($is_locked): ?>
                    <input type="hidden" name="vsbbm_passenger_fields[<?php echo $index; ?>][required]" value="1">
                    <?php endif; ?>
                </label>
            </div>
            
            <div>
                <?php if (!$is_locked): ?>
                <button type="button" class="button button-secondary remove-field" 
                        style="background: #dc3232; color: white; border: none;">
                    حذف
                </button>
                <?php else: ?>
                <span style="color: #666; font-size: 12px;">غیرقابل حذف</span>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Options for select field -->
        <div class="select-options" style="margin-top: 10px; <?php echo $field['type'] !== 'select' ? 'display: none;' : ''; ?>">
            <label>گزینه‌ها (با کاما جدا کنید)</label>
            <input type="text" 
                   name="vsbbm_passenger_fields[<?php echo $index; ?>][options]" 
                   value="<?php echo esc_attr(isset($field['options']) ? $field['options'] : ''); ?>" 
                   placeholder="مرد, زن" 
                   style="width: 100%; <?php echo $is_locked ? 'background: #f8f9fa;' : ''; ?>" 
                   <?php echo $is_locked ? 'readonly' : ''; ?>>
        </div>
        
        <!-- فیلد مخفی برای locked -->
        <input type="hidden" name="vsbbm_passenger_fields[<?php echo $index; ?>][locked]" value="<?php echo $is_locked ? '1' : '0'; ?>">
    </div>
    <?php endforeach; ?>
</div>
                    
                    <button type="button" id="add-field" class="button button-primary" style="margin-top: 15px;">
                        ➕ افزودن فیلد جدید
                    </button>
                    
                    <?php submit_button('ذخیره تغییرات'); ?>
                </div>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            let fieldIndex = <?php echo count($fields); ?>;
            
            // افزودن فیلد جدید
            $('#add-field').on('click', function() {
                const newField = `
                    <div class="field-group" style="background: #f9f9f9; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #0073aa;">
                        <div style="display: grid; grid-template-columns: 2fr 2fr 1fr 1fr 1fr; gap: 10px; align-items: end;">
                            <div>
                                <label>عنوان فیلد</label>
                                <input type="text" name="vsbbm_passenger_fields[${fieldIndex}][label]" 
                                       style="width: 100%;" required>
                            </div>
                            
                            <div>
                                <label>Placeholder</label>
                                <input type="text" name="vsbbm_passenger_fields[${fieldIndex}][placeholder]" 
                                       style="width: 100%;">
                            </div>
                            
                            <div>
                                <label>نوع فیلد</label>
                                <select name="vsbbm_passenger_fields[${fieldIndex}][type]" style="width: 100%;">
                                    <option value="text">متنی</option>
                                    <option value="tel">تلفن</option>
                                    <option value="email">ایمیل</option>
                                    <option value="number">عدد</option>
                                    <option value="select">انتخابگر</option>
                                </select>
                            </div>
                            
                            <div>
                                <label>
                                    <input type="checkbox" name="vsbbm_passenger_fields[${fieldIndex}][required]" value="1">
                                    اجباری
                                </label>
                            </div>
                            
                            <div>
                                <button type="button" class="button button-secondary remove-field" 
                                        style="background: #dc3232; color: white; border: none;">
                                    حذف
                                </button>
                            </div>
                        </div>
                        
                        <div class="select-options" style="margin-top: 10px; display: none;">
                            <label>گزینه‌ها (با کاما جدا کنید)</label>
                            <input type="text" name="vsbbm_passenger_fields[${fieldIndex}][options]" 
                                   style="width: 100%;" placeholder="مرد, زن">
                        </div>
                        
                        <input type="hidden" name="vsbbm_passenger_fields[${fieldIndex}][locked]" value="0">
                    </div>
                `;
                
                $('#vsbbm-fields-container').append(newField);
                fieldIndex++;
            });
            
            // حذف فیلد - جلوگیری از حذف فیلد کد ملی
$(document).on('click', '.remove-field', function() {
    const fieldGroup = $(this).closest('.field-group');
    const fieldLabel = fieldGroup.find('input[name$="[label]"]').val();
    
    // فقط جلوگیری از حذف فیلد کد ملی
    if (fieldLabel === 'کد ملی') {
        alert('فیلد "کد ملی" قفل شده و قابل حذف نیست.');
        return;
    }
    
    if ($('.field-group').length > 1) {
        fieldGroup.remove();
    } else {
        alert('حداقل یک فیلد باید وجود داشته باشد.');
    }
});
            
            // نمایش/پنهان کردن گزینه‌های select
            $(document).on('change', 'select[name$="[type]"]', function() {
                const optionsDiv = $(this).closest('.field-group').find('.select-options');
                if ($(this).val() === 'select') {
                    optionsDiv.show();
                } else {
                    optionsDiv.hide();
                }
            });
            
            // جلوگیری از تغییر فیلدهای قفل شده
            $(document).on('input change', '.locked-field input, .locked-field select', function(e) {
                if ($(this).closest('.locked-field').length) {
                    e.preventDefault();
                    $(this).blur();
                    alert('این فیلد قفل شده و قابل تغییر نیست.');
                }
            });
        });
        </script>
        <style>
        .field-group {
            transition: all 0.3s ease;
        }
        .field-group:hover {
            background: #f0f0f0 !important;
        }
        .locked-field:hover {
            background: #fff3cd !important;
        }
        .locked-field input:read-only,
        .locked-field select:disabled {
            cursor: not-allowed;
            opacity: 0.7;
        }
        </style>
        <?php
    }

    /**
     * سانیتیزه کردن فیلدها و حفظ فیلد کد ملی
     */
    public function sanitize_passenger_fields($input) {
    if (!is_array($input)) {
        return $input;
    }

    $sanitized = array();
    $has_national_code = false;

    foreach ($input as $index => $field) {
        $sanitized_field = array(
            'label' => sanitize_text_field($field['label'] ?? ''),
            'placeholder' => sanitize_text_field($field['placeholder'] ?? ''),
            'type' => sanitize_text_field($field['type'] ?? 'text'),
            'required' => isset($field['required']) ? true : false,
            'locked' => ($field['label'] === 'کد ملی') ? true : false, // فقط کد ملی قفل شود
            'options' => isset($field['options']) ? sanitize_text_field($field['options']) : ''
        );

        // بررسی فیلد کد ملی
        if ($sanitized_field['label'] === 'کد ملی') {
            $has_national_code = true;
            $sanitized_field['required'] = true; // کد ملی همیشه اجباری
        }

        $sanitized[] = $sanitized_field;
    }

    // اگر فیلد کد ملی وجود نداشت، اضافهش کن
    if (!$has_national_code) {
        array_unshift($sanitized, array(
            'type' => 'text',
            'label' => 'کد ملی',
            'required' => true,
            'placeholder' => 'کد ملی ۱۰ رقمی',
            'locked' => true,
            'options' => ''
        ));
    }

    // پاک کردن کش فیلدهای مسافر
    delete_transient('vsbbm_passenger_fields');

    return $sanitized;
}
    
    public function display_order_passenger_info($item_id, $item, $product) {
        if (!$product) return;
        
        // فقط برای محصولات رزرو صندلی
        if (!VSBBM_Seat_Manager::is_seat_booking_enabled($product->get_id())) {
            return;
        }
        
        echo '<div class="vsbbm-order-passengers" style="margin-top: 10px; padding: 10px; background: #f9f9f9; border-radius: 5px;">';
        echo '<strong>اطلاعات مسافران:</strong><br>';
        
        // دریافت اطلاعات مسافران از متادیتای آیتم
        $passenger_meta = $item->get_meta_data();
        
        foreach ($passenger_meta as $meta) {
            if (strpos($meta->key, 'مسافر') !== false) {
                echo '<div style="margin: 5px 0; padding: 5px; background: white; border-radius: 3px;">';
                echo '<strong>' . esc_html($meta->key) . ':</strong> ' . esc_html($meta->value);
                echo '</div>';
            }
        }
        
        echo '</div>';
    }
    
    private function get_dashboard_stats() {
        global $wpdb;
        
        $today = date('Y-m-d');
        $week_start = date('Y-m-d', strtotime('monday this week'));
        
        return array(
            'total_bookings' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} 
                 WHERE post_type = 'shop_order' 
                 AND post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')"
            ),
            'today_bookings' => $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} 
                     WHERE post_type = 'shop_order' 
                     AND post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
                     AND DATE(post_date) = %s",
                    $today
                )
            ),
            'total_revenue' => $this->calculate_total_revenue(),
            'weekly_revenue' => $this->calculate_revenue_period($week_start, $today),
            'total_passengers' => $this->calculate_total_passengers(),
            'occupancy_rate' => $this->calculate_occupancy_rate()
        );
    }
    
    private function get_weekly_stats() {
        global $wpdb;
        
        $weekly_data = array(
            'labels' => array(),
            'data' => array()
        );
        
        $days = array('شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه');
        
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $day_name = $days[date('w', strtotime($date))];
            
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} 
                     WHERE post_type = 'shop_order' 
                     AND post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
                     AND DATE(post_date) = %s",
                    $date
                )
            );
            
            $weekly_data['labels'][] = $day_name;
            $weekly_data['data'][] = $count ?: 0;
        }
        
        return $weekly_data;
    }
    
    private function get_recent_bookings($limit = 10) {
        global $wpdb;
        
        $query = "
            SELECT p.ID, p.post_date, p.post_status, p.post_title,
                   u.display_name, u.user_email,
                   (SELECT meta_value FROM {$wpdb->postmeta} 
                    WHERE post_id = p.ID AND meta_key = '_order_total') as order_total
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->users} u ON p.post_author = u.ID
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
            ORDER BY p.post_date DESC
            LIMIT %d
        ";
        
        return $wpdb->get_results($wpdb->prepare($query, $limit));
    }

    private function get_all_bookings($filters = array()) {
        global $wpdb;

        // استفاده از query بهینه‌تر برای عملکرد بهتر
        $where_parts = array();
        $where_values = array();

        // فیلتر وضعیت
        if (!empty($filters['status'])) {
            $status = str_replace('wc-', '', $filters['status']);
            $where_parts[] = "p.post_status = %s";
            $where_values[] = 'wc-' . $status;
        } else {
            // فقط سفارشات مرتبط با رزرو صندلی
            $where_parts[] = "p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending', 'wc-cancelled')";
        }

        // فیلتر محصول
        if (!empty($filters['product_id'])) {
            $where_parts[] = "EXISTS (
                SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items oi
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
                WHERE oi.order_id = p.ID
                AND oim.meta_key = '_product_id'
                AND oim.meta_value = %d
            )";
            $where_values[] = $filters['product_id'];
        }

        // فیلتر تاریخ
        if (!empty($filters['date_from'])) {
            $where_parts[] = "DATE(p.post_date) >= %s";
            $where_values[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where_parts[] = "DATE(p.post_date) <= %s";
            $where_values[] = $filters['date_to'];
        }

        // فیلتر جستجو
        if (!empty($filters['search'])) {
            $search = '%' . $wpdb->esc_like($filters['search']) . '%';
            $where_parts[] = "(p.ID LIKE %s OR pm.meta_value LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)";
            $where_values[] = $search;
            $where_values[] = $search;
            $where_values[] = $search;
            $where_values[] = $search;
        }

        $where_clause = !empty($where_parts) ? 'WHERE ' . implode(' AND ', $where_parts) : '';

        // Query بهینه با JOIN
        $query = "
            SELECT SQL_CALC_FOUND_ROWS
                p.ID,
                p.post_date,
                p.post_status,
                p.post_title,
                u.display_name,
                u.user_email,
                pm.meta_value as order_total
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->users} u ON p.post_author = u.ID
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
            {$where_clause}
            ORDER BY p.post_date DESC
            LIMIT 1000
        ";

        if (!empty($where_values)) {
            $query = $wpdb->prepare($query, $where_values);
        }

        $bookings = $wpdb->get_results($query);

        // تبدیل به فرمت مورد نیاز
        foreach ($bookings as $booking) {
            $booking->post_status = str_replace('wc-', '', $booking->post_status);
            $booking->order_total = $booking->order_total ?: '0';
        }

        error_log('VSBBM - Found ' . count($bookings) . ' bookings via optimized query');

        return $bookings;
    }

    private function get_booking_statuses() {
        // استفاده از statusهای واقعی WooCommerce
        $wc_statuses = wc_get_order_statuses();
        $statuses = array();
        
        foreach ($wc_statuses as $key => $label) {
            $clean_key = str_replace('wc-', '', $key);
            $statuses[$clean_key] = $label;
        }
        
        return $statuses;
    }

    // ... سایر متدهای موجود (calculate_total_revenue, process_booking_actions, etc.)
    
    private function calculate_total_revenue() {
        global $wpdb;
        
        return $wpdb->get_var(
            "SELECT SUM(meta_value) FROM {$wpdb->postmeta} 
             WHERE meta_key = '_order_total' 
             AND post_id IN (
                 SELECT ID FROM {$wpdb->posts} 
                 WHERE post_type = 'shop_order' 
                 AND post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
             )"
        ) ?: 0;
    }
    
    private function calculate_revenue_period($start_date, $end_date) {
        global $wpdb;
        
        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(meta_value) FROM {$wpdb->postmeta} 
                 WHERE meta_key = '_order_total' 
                 AND post_id IN (
                     SELECT ID FROM {$wpdb->posts} 
                     WHERE post_type = 'shop_order' 
                     AND post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
                     AND DATE(post_date) BETWEEN %s AND %s
                 )",
                $start_date, $end_date
            )
        ) ?: 0;
    }
    
    private function calculate_total_passengers() {
        global $wpdb;
        
        $total = 0;
        
        // شمردن تعداد مسافران از طریق آیتم‌های سفارش
        $order_items = $wpdb->get_results(
            "SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items 
             WHERE order_item_type = 'line_item'"
        );
        
        foreach ($order_items as $item) {
            $passenger_count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_itemmeta 
                     WHERE order_item_id = %d 
                     AND meta_key LIKE %s",
                    $item->order_item_id,
                    '%مسافر%'
                )
            );
            
            $total += $passenger_count ?: 0;
        }
        
        return $total;
    }
    
    private function calculate_occupancy_rate() {
        // محاسبه نرخ اشغال بر اساس تعداد صندلی‌های رزرو شده
        $total_seats = 32; // تعداد کل صندلی‌ها (فرضی)
        $reserved_seats = $this->calculate_total_passengers();
        
        if ($total_seats > 0) {
            return round(($reserved_seats / $total_seats) * 100, 2);
        }
        
        return 0;
    }
    
    private function process_booking_actions() {
        if (!isset($_GET['action']) || !isset($_GET['booking_id']) || !wp_verify_nonce($_GET['_wpnonce'], 'vsbbm_booking_action')) {
            return;
        }
        
        $action = sanitize_text_field($_GET['action']);
        $booking_id = intval($_GET['booking_id']);
        
        switch ($action) {
            case 'delete':
                $this->delete_booking($booking_id);
                break;
                
            case 'cancel':
                $this->cancel_booking($booking_id);
                break;
        }
    }
    
    private function delete_booking($booking_id) {
        // حذف سفارش و داده‌های مرتبط
        wp_delete_post($booking_id, true);
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>رزرو با موفقیت حذف شد.</p></div>';
        });
    }
    
    private function cancel_booking($booking_id) {
        // تغییر وضعیت به لغو شده
        wp_update_post(array(
            'ID' => $booking_id,
            'post_status' => 'wc-cancelled'
        ));
        
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>رزرو با موفقیت لغو شد.</p></div>';
        });
    }
    
    private function generate_report($report_type) {
        switch ($report_type) {
            case 'daily':
                return $this->generate_daily_report();
            case 'weekly':
                return $this->generate_weekly_report();
            case 'monthly':
                return $this->generate_monthly_report();
            default:
                return $this->generate_daily_report();
        }
    }
    
    private function generate_daily_report() {
        global $wpdb;
        
        $report = array();
        
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            
            $bookings = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} 
                     WHERE post_type = 'shop_order' 
                     AND post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
                     AND DATE(post_date) = %s",
                    $date
                )
            );
            
            $revenue = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT SUM(meta_value) FROM {$wpdb->postmeta} 
                     WHERE meta_key = '_order_total' 
                     AND post_id IN (
                         SELECT ID FROM {$wpdb->posts} 
                         WHERE post_type = 'shop_order' 
                         AND post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
                         AND DATE(post_date) = %s
                     )",
                    $date
                )
            );
            
            $report[] = array(
                'date' => $date,
                'bookings' => $bookings ?: 0,
                'revenue' => $revenue ?: 0
            );
        }
        
        return $report;
    }
    
    private function generate_weekly_report() {
        global $wpdb;
        
        $report = array();
        
        for ($i = 3; $i >= 0; $i--) {
            $week_start = date('Y-m-d', strtotime("monday -$i weeks"));
            $week_end = date('Y-m-d', strtotime("sunday -$i weeks"));
            
            $bookings = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} 
                     WHERE post_type = 'shop_order' 
                     AND post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
                     AND DATE(post_date) BETWEEN %s AND %s",
                    $week_start, $week_end
                )
            );
            
            $revenue = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT SUM(meta_value) FROM {$wpdb->postmeta} 
                     WHERE meta_key = '_order_total' 
                     AND post_id IN (
                         SELECT ID FROM {$wpdb->posts} 
                         WHERE post_type = 'shop_order' 
                         AND post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
                         AND DATE(post_date) BETWEEN %s AND %s
                     )",
                    $week_start, $week_end
                )
            );
            
            $report[] = array(
                'week' => "هفته " . (4 - $i),
                'period' => $week_start . ' تا ' . $week_end,
                'bookings' => $bookings ?: 0,
                'revenue' => $revenue ?: 0
            );
        }
        
        return $report;
    }
    
    private function generate_monthly_report() {
        global $wpdb;
        
        $report = array();
        
        for ($i = 5; $i >= 0; $i--) {
            $month_start = date('Y-m-01', strtotime("-$i months"));
            $month_end = date('Y-m-t', strtotime("-$i months"));
            
            $bookings = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} 
                     WHERE post_type = 'shop_order' 
                     AND post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
                     AND DATE(post_date) BETWEEN %s AND %s",
                    $month_start, $month_end
                )
            );
            
            $revenue = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT SUM(meta_value) FROM {$wpdb->postmeta} 
                     WHERE meta_key = '_order_total' 
                     AND post_id IN (
                         SELECT ID FROM {$wpdb->posts} 
                         WHERE post_type = 'shop_order' 
                         AND post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
                         AND DATE(post_date) BETWEEN %s AND %s
                     )",
                    $month_start, $month_end
                )
            );
            
            $report[] = array(
                'month' => $this->get_persian_month_name(date('m', strtotime($month_start))),
                'period' => $month_start . ' تا ' . $month_end,
                'bookings' => $bookings ?: 0,
                'revenue' => $revenue ?: 0
            );
        }
        
        return $report;
    }
    
    private function get_persian_month_name($month_number) {
        $months = array(
            '01' => 'فروردین', '02' => 'اردیبهشت', '03' => 'خرداد',
            '04' => 'تیر', '05' => 'مرداد', '06' => 'شهریور',
            '07' => 'مهر', '08' => 'آبان', '09' => 'آذر',
            '10' => 'دی', '11' => 'بهمن', '12' => 'اسفند'
        );
        
        return $months[$month_number] ?? $month_number;
    }
    
    public function get_booking_details_ajax() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'vsbbm_admin_nonce')) {
            wp_send_json_error('امنیت درخواست تایید نشد');
            return;
        }

        $booking_id = intval($_POST['booking_id']);
        $booking = $this->get_booking_details($booking_id);

        if ($booking) {
            wp_send_json_success($booking);
        } else {
            wp_send_json_error('رزرو یافت نشد');
        }
    }
    
    private function get_booking_details($booking_id) {
        $order = wc_get_order($booking_id);
        
        if (!$order) {
            return false;
        }
        
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
        
        return array(
            'id' => $order->get_id(),
            'date' => $order->get_date_created()->format('Y-m-d H:i:s'),
            'status' => $order->get_status(),
            'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customer_email' => $order->get_billing_email(),
            'customer_phone' => $order->get_billing_phone(),
            'passengers' => $passengers,
            'total_amount' => $order->get_total(),
            'payment_method' => $order->get_payment_method_title()
        );
    }
    
    public function update_booking_status_ajax() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'vsbbm_admin_nonce')) {
            wp_send_json_error('امنیت درخواست تایید نشد');
            return;
        }

        $booking_id = intval($_POST['booking_id']);
        $status = sanitize_text_field($_POST['status']);

        $order = wc_get_order($booking_id);
        if ($order) {
            $order->update_status($status);
            wp_send_json_success('وضعیت با موفقیت به‌روزرسانی شد');
        } else {
            wp_send_json_error('سفارش یافت نشد');
        }
    }
    
    public function export_bookings_ajax() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'vsbbm_admin_nonce')) {
            wp_send_json_error('امنیت درخواست تایید نشد');
            return;
        }

        $filters = array(
            'status' => isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '',
            'date_from' => isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '',
            'date_to' => isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : ''
        );

        $bookings = $this->get_all_bookings($filters);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=bookings-export-' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');

        // هدر CSV
        fputcsv($output, array(
            'شماره سفارش', 'تاریخ', 'نام مشتری', 'ایمیل', 'مبلغ', 'وضعیت'
        ));

        // داده‌ها
        foreach ($bookings as $booking) {
            fputcsv($output, array(
                $booking->ID,
                $booking->post_date,
                $booking->display_name,
                $booking->user_email,
                $booking->order_total,
                $this->get_status_label($booking->post_status)
            ));
        }

        fclose($output);
        exit;
    }
    
    private function get_status_label($status) {
        $wc_statuses = wc_get_order_statuses();
        return $wc_statuses[$status] ?? $status;
    }

    /**
     * AJAX handler for using tickets
     */
    public function use_ticket_ajax() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'vsbbm_admin_nonce')) {
            wp_send_json_error('امنیت درخواست تایید نشد');
            return;
        }

        $ticket_id = intval($_POST['ticket_id']);

        if (VSBBM_Ticket_Manager::use_ticket($ticket_id)) {
            wp_send_json_success('بلیط با موفقیت استفاده شد');
        } else {
            wp_send_json_error('خطا در بروزرسانی وضعیت بلیط');
        }
    }
    
    private function get_settings() {
        return get_option('vsbbm_settings', array(
            'enable_email_notifications' => true,
            'reservation_timeout' => 15,
            'max_seats_per_booking' => 10
        ));
    }
    
    private function save_settings() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'vsbbm_save_settings')) {
            return;
        }
        
        $settings = array(
            'enable_email_notifications' => isset($_POST['enable_email_notifications']),
            'reservation_timeout' => intval($_POST['reservation_timeout']),
            'max_seats_per_booking' => intval($_POST['max_seats_per_booking'])
        );
        
        update_option('vsbbm_settings', $settings);
        
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>تنظیمات با موفقیت ذخیره شد.</p></div>';
        });
    }
    
    private function calculate_passengers_from_bookings($bookings) {
        $total = 0;
        foreach ($bookings as $booking) {
            $total += $this->get_passenger_count_for_booking($booking->ID);
        }
        return $total;
    }

    private function get_passenger_count_for_booking($booking_id) {
        global $wpdb;
        
        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_itemmeta 
                 WHERE order_item_id IN (
                     SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items 
                     WHERE order_id = %d
                 )
                 AND meta_key LIKE %s",
                $booking_id,
                '%مسافر%'
            )
        ) ?: 0;
    }
    
    private function get_active_bookings_count() {
        global $wpdb;
        
        return $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
             WHERE post_type = 'shop_order' 
             AND post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')"
        ) ?: 0;
    }

    private function get_comparison_class($current, $previous) {
        if ($previous == 0) return 'neutral';
        return $current > $previous ? 'positive' : 'negative';
    }

    private function get_comparison_percentage($current, $previous) {
        if ($previous == 0) return 0;
        $change = (($current - $previous) / $previous) * 100;
        return round($change, 1);
    }

    private function get_most_popular_day($report_data) {
        if (empty($report_data)) return '---';

        $max_booking = max(array_column($report_data, 'bookings'));
        foreach ($report_data as $report) {
            if ($report['bookings'] == $max_booking) {
                return $report['date'] ?? $report['week'] ?? $report['month'] ?? '---';
            }
        }

        return '---';
    }

    private function get_email_settings() {
        $defaults = array(
            'from_name' => get_bloginfo('name'),
            'from_email' => get_option('admin_email'),
            'admin_email' => get_option('admin_email'),
            'enable_customer_confirmation_email' => true,
            'enable_customer_cancellation_email' => true,
            'enable_customer_processing_email' => false,
            'enable_customer_reminder_email' => false,
            'enable_admin_new_booking_email' => true,
            'enable_admin_expired_reservation_email' => false,
            'bcc_admin_on_customer_emails' => false,
            'customer_confirmation_subject' => '',
            'customer_cancellation_subject' => '',
            'admin_new_booking_subject' => '',
        );

        $settings = get_option('vsbbm_email_settings', array());
        return wp_parse_args($settings, $defaults);
    }

    private function save_email_settings() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'vsbbm_save_email_settings')) {
            return;
        }

        $settings = array(
            'from_name' => sanitize_text_field($_POST['from_name']),
            'from_email' => sanitize_email($_POST['from_email']),
            'admin_email' => sanitize_email($_POST['admin_email']),
            'enable_customer_confirmation_email' => isset($_POST['enable_customer_confirmation_email']),
            'enable_customer_cancellation_email' => isset($_POST['enable_customer_cancellation_email']),
            'enable_customer_processing_email' => isset($_POST['enable_customer_processing_email']),
            'enable_customer_reminder_email' => isset($_POST['enable_customer_reminder_email']),
            'enable_admin_new_booking_email' => isset($_POST['enable_admin_new_booking_email']),
            'enable_admin_expired_reservation_email' => isset($_POST['enable_admin_expired_reservation_email']),
            'bcc_admin_on_customer_emails' => isset($_POST['bcc_admin_on_customer_emails']),
            'customer_confirmation_subject' => sanitize_text_field($_POST['customer_confirmation_subject']),
            'customer_cancellation_subject' => sanitize_text_field($_POST['customer_cancellation_subject']),
            'admin_new_booking_subject' => sanitize_text_field($_POST['admin_new_booking_subject']),
        );

        update_option('vsbbm_email_settings', $settings);

        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>تنظیمات ایمیل با موفقیت ذخیره شد.</p></div>';
        });
    }

    /**
     * دریافت تنظیمات SMS
     */
    private function get_sms_settings() {
        $defaults = array(
            'sms_panel' => '',
            'test_phone_number' => '',
            'otp_expiry_minutes' => 5,
            'enable_customer_confirmation_sms' => true,
            'enable_customer_cancellation_sms' => true,
            'enable_ticket_used_sms' => false,
            'enable_otp_sms' => true,
            'customer_confirmation_sms_template' => '',
            'customer_cancellation_sms_template' => '',
            'otp_sms_template' => '',
            // IPPanel settings
            'ippanel_api_key' => '',
            'ippanel_originator' => '',
            'ippanel_password' => '',
            // Kavenegar settings
            'kavenegar_api_key' => '',
            'kavenegar_sender' => '',
            // SMS.ir settings
            'smsir_api_key' => '',
            'smsir_line_number' => '',
        );

        $settings = get_option('vsbbm_sms_settings', array());
        return wp_parse_args($settings, $defaults);
    }

    /**
     * ذخیره تنظیمات SMS
     */
    private function save_sms_settings() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'vsbbm_save_sms_settings')) {
            return;
        }

        $settings = array(
            'sms_panel' => sanitize_text_field($_POST['sms_panel']),
            'test_phone_number' => sanitize_text_field($_POST['test_phone_number']),
            'otp_expiry_minutes' => intval($_POST['otp_expiry_minutes']),
            'enable_customer_confirmation_sms' => isset($_POST['enable_customer_confirmation_sms']),
            'enable_customer_cancellation_sms' => isset($_POST['enable_customer_cancellation_sms']),
            'enable_ticket_used_sms' => isset($_POST['enable_ticket_used_sms']),
            'enable_otp_sms' => isset($_POST['enable_otp_sms']),
            'customer_confirmation_sms_template' => sanitize_textarea_field($_POST['customer_confirmation_sms_template']),
            'customer_cancellation_sms_template' => sanitize_textarea_field($_POST['customer_cancellation_sms_template']),
            'otp_sms_template' => sanitize_textarea_field($_POST['otp_sms_template']),
            // IPPanel settings
            'ippanel_api_key' => sanitize_text_field($_POST['ippanel_api_key']),
            'ippanel_originator' => sanitize_text_field($_POST['ippanel_originator']),
            'ippanel_password' => sanitize_text_field($_POST['ippanel_password']),
            // Kavenegar settings
            'kavenegar_api_key' => sanitize_text_field($_POST['kavenegar_api_key']),
            'kavenegar_sender' => sanitize_text_field($_POST['kavenegar_sender']),
            // SMS.ir settings
            'smsir_api_key' => sanitize_text_field($_POST['smsir_api_key']),
            'smsir_line_number' => sanitize_text_field($_POST['smsir_line_number']),
        );

        update_option('vsbbm_sms_settings', $settings);

        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>تنظیمات SMS با موفقیت ذخیره شد.</p></div>';
        });
    }

    /**
     * هندل ذخیره تنظیمات کش
     */
    public function handle_cache_settings_save() {
        if (isset($_POST['vsbbm_save_cache_settings'])) {
            $this->save_cache_settings();
        }
    }

    /**
     * ذخیره تنظیمات کش
     */
    private function save_cache_settings() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'vsbbm_save_cache_settings')) {
            return;
        }

        $settings = array(
            'cache_enabled' => isset($_POST['cache_enabled']),
            'cache_ttl' => intval($_POST['cache_ttl']),
            'cache_max_keys' => intval($_POST['cache_max_keys']),
        );

        update_option('vsbbm_cache_enabled', $settings['cache_enabled']);
        update_option('vsbbm_cache_ttl', $settings['cache_ttl']);
        update_option('vsbbm_cache_max_keys', $settings['cache_max_keys']);

        // بروزرسانی تنظیمات کش منیجر
        $cache_manager = VSBBM_Cache_Manager::get_instance();
        $cache_manager->update_settings($settings);

        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>تنظیمات کش با موفقیت ذخیره شد.</p></div>';
        });
    }

    /**
     * تست اتصال پنل SMS
     */
    private function test_sms_connection() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'vsbbm_save_sms_settings')) {
            return;
        }

        $panel = sanitize_text_field($_POST['sms_panel']);
        $test_phone = sanitize_text_field($_POST['test_phone_number']);

        if (empty($panel) || empty($test_phone)) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>لطفاً پنل SMS و شماره تلفن تست را وارد کنید.</p></div>';
            });
            return;
        }

        // ذخیره تنظیمات موقت برای تست
        $temp_settings = array(
            'sms_panel' => $panel,
            'test_phone_number' => $test_phone,
            'ippanel_api_key' => sanitize_text_field($_POST['ippanel_api_key'] ?? ''),
            'ippanel_originator' => sanitize_text_field($_POST['ippanel_originator'] ?? ''),
            'kavenegar_api_key' => sanitize_text_field($_POST['kavenegar_api_key'] ?? ''),
            'kavenegar_sender' => sanitize_text_field($_POST['kavenegar_sender'] ?? ''),
            'smsir_api_key' => sanitize_text_field($_POST['smsir_api_key'] ?? ''),
            'smsir_line_number' => sanitize_text_field($_POST['smsir_line_number'] ?? ''),
        );

        update_option('vsbbm_sms_settings', $temp_settings);

        // تست اتصال
        $test_result = VSBBM_SMS_Notifications::test_sms_connection($panel);

        if ($test_result) {
            add_action('admin_notices', function() use ($test_phone) {
                echo '<div class="notice notice-success"><p>✅ اتصال پنل SMS با موفقیت تست شد. پیامک آزمایشی به شماره ' . esc_html($test_phone) . ' ارسال شد.</p></div>';
            });
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>❌ خطا در اتصال به پنل SMS. لطفاً تنظیمات را بررسی کنید.</p></div>';
            });
        }
    }

    private function get_bus_products() {
        return get_posts(array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_vsbbm_enable_seat_booking',
                    'value' => 'yes',
                    'compare' => '='
                )
            )
        ));
    }

    private function get_reservations($filters = array()) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'vsbbm_seat_reservations';
        $where_parts = array('1=1');
        $where_values = array();

        if (!empty($filters['status'])) {
            $where_parts[] = 'status = %s';
            $where_values[] = $filters['status'];
        }

        if (!empty($filters['product_id'])) {
            $where_parts[] = 'product_id = %d';
            $where_values[] = $filters['product_id'];
        }

        if (!empty($filters['date_from'])) {
            $where_parts[] = 'DATE(reserved_at) >= %s';
            $where_values[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where_parts[] = 'DATE(reserved_at) <= %s';
            $where_values[] = $filters['date_to'];
        }

        $where_clause = implode(' AND ', $where_parts);

        $query = "SELECT r.*, p.post_title as product_name, u.display_name as user_name
                  FROM $table_name r
                  LEFT JOIN {$wpdb->posts} p ON r.product_id = p.ID
                  LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
                  WHERE $where_clause
                  ORDER BY r.reserved_at DESC";

        if (!empty($where_values)) {
            $query = $wpdb->prepare($query, $where_values);
        }

        return $wpdb->get_results($query);
    }

    private function process_reservation_actions() {
        if (!isset($_GET['action']) || !isset($_GET['reservation_id']) || !wp_verify_nonce($_GET['_wpnonce'], 'vsbbm_reservation_action')) {
            return;
        }

        $action = sanitize_text_field($_GET['action']);
        $reservation_id = intval($_GET['reservation_id']);

        switch ($action) {
            case 'cancel':
                VSBBM_Seat_Reservations::cancel_reservation_by_id($reservation_id);
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success"><p>رزرو با موفقیت لغو شد.</p></div>';
                });
                break;

            case 'confirm':
                VSBBM_Seat_Reservations::confirm_reservation_by_id($reservation_id);
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success"><p>رزرو با موفقیت تایید شد.</p></div>';
                });
                break;
        }
    }

    private function process_bulk_booking_actions() {
        if (!isset($_POST['action']) || !isset($_POST['booking_ids']) || !wp_verify_nonce($_POST['_wpnonce'], 'vsbbm_bulk_action')) {
            return;
        }

        $action = sanitize_text_field($_POST['action']);
        $booking_ids = array_map('intval', $_POST['booking_ids']);

        if (empty($booking_ids)) {
            return;
        }

        $processed = 0;

        switch ($action) {
            case 'status_completed':
                foreach ($booking_ids as $booking_id) {
                    $order = wc_get_order($booking_id);
                    if ($order) {
                        $order->update_status('completed');
                        $processed++;
                    }
                }
                break;

            case 'status_cancelled':
                foreach ($booking_ids as $booking_id) {
                    $order = wc_get_order($booking_id);
                    if ($order) {
                        $order->update_status('cancelled');
                        $processed++;
                    }
                }
                break;

            case 'export':
                // Handle export - this will be processed separately
                break;
        }

        if ($processed > 0) {
            add_action('admin_notices', function() use ($processed, $action) {
                $action_labels = array(
                    'status_completed' => 'تکمیل شده',
                    'status_cancelled' => 'لغو شده'
                );
                $label = isset($action_labels[$action]) ? $action_labels[$action] : $action;
                echo '<div class="notice notice-success"><p>' . sprintf('%d رزرو به وضعیت "%s" تغییر یافت.', $processed, $label) . '</p></div>';
            });
        }
    }

    /**
     * نمایش صفحه مدیریت بلیط‌ها
     */
    public function render_tickets_page() {
        // پردازش actions
        $this->process_ticket_actions();

        // دریافت پارامترهای فیلتر
        $filters = array(
            'status' => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '',
            'search' => isset($_GET['search']) ? sanitize_text_field($_GET['search']) : ''
        );

        $tickets = $this->get_tickets($filters);

        ?>
        <div class="wrap vsbbm-admin-tickets">
            <h1 class="wp-heading-inline">مدیریت بلیط‌های الکترونیکی</h1>

            <!-- فیلترها -->
            <div class="vsbbm-filters">
                <form method="get" class="vsbbm-filter-form">
                    <input type="hidden" name="page" value="vsbbm-tickets">

                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="status">وضعیت:</label>
                            <select name="status" id="status">
                                <option value="">همه وضعیت‌ها</option>
                                <option value="active" <?php selected($filters['status'], 'active'); ?>>فعال</option>
                                <option value="used" <?php selected($filters['status'], 'used'); ?>>استفاده شده</option>
                                <option value="cancelled" <?php selected($filters['status'], 'cancelled'); ?>>لغو شده</option>
                                <option value="expired" <?php selected($filters['status'], 'expired'); ?>>منقضی شده</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="search">جستجو:</label>
                            <input type="text" name="search" id="search" value="<?php echo esc_attr($filters['search']); ?>" placeholder="شماره بلیط یا شماره سفارش">
                        </div>

                        <div class="filter-actions">
                            <button type="submit" class="button button-primary">اعمال فیلتر</button>
                            <a href="<?php echo admin_url('admin.php?page=vsbbm-tickets'); ?>" class="button">حذف فیلترها</a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- آمار سریع -->
            <div class="vsbbm-quick-stats">
                <div class="quick-stat">
                    <span class="stat-number"><?php echo number_format(count($tickets)); ?></span>
                    <span class="stat-label">کل بلیط‌ها</span>
                </div>
                <div class="quick-stat">
                    <span class="stat-number"><?php echo $this->count_tickets_by_status($tickets, 'active'); ?></span>
                    <span class="stat-label">بلیط فعال</span>
                </div>
                <div class="quick-stat">
                    <span class="stat-number"><?php echo $this->count_tickets_by_status($tickets, 'used'); ?></span>
                    <span class="stat-label">بلیط استفاده شده</span>
                </div>
            </div>

            <!-- جدول بلیط‌ها -->
            <div class="vsbbm-tickets-table">
                <table id="vsbbm-tickets-datatable" class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>شماره بلیط</th>
                            <th>شماره سفارش</th>
                            <th>مسافر</th>
                            <th>وضعیت</th>
                            <th>تاریخ صدور</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($tickets)): ?>
                            <?php foreach ($tickets as $ticket): ?>
                                <?php
                                $passenger_data = json_decode($ticket->passenger_data, true);
                                $status_class = 'status-' . $ticket->status;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($ticket->ticket_number); ?></strong>
                                    </td>
                                    <td>
                                        <a href="<?php echo get_edit_post_link($ticket->order_id); ?>" target="_blank">
                                            #<?php echo esc_html($ticket->order_id); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php echo esc_html($passenger_data['name'] ?? 'نامشخص'); ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo esc_attr($status_class); ?>">
                                            <?php echo $this->get_ticket_status_label($ticket->status); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo date_i18n('Y/m/d H:i', strtotime($ticket->created_at)); ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($ticket->pdf_path): ?>
                                                <a href="<?php echo wp_upload_dir()['baseurl'] . $ticket->pdf_path; ?>"
                                                   class="button button-small" target="_blank">
                                                    مشاهده PDF
                                                </a>
                                            <?php endif; ?>

                                            <?php if ($ticket->status === 'active'): ?>
                                                <button type="button" class="button button-small button-secondary use-ticket"
                                                        data-ticket-id="<?php echo $ticket->id; ?>">
                                                    استفاده شد
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="no-records">
                                    <div class="no-data-message">
                                        <span class="dashicons dashicons-info"></span>
                                        <p>بلیطی یافت نشد.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <style>
            .vsbbm-admin-tickets {
                padding: 20px;
            }

            .vsbbm-filters {
                background: white;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                margin-bottom: 20px;
            }

            .filter-row {
                display: flex;
                gap: 15px;
                align-items: end;
                flex-wrap: wrap;
            }

            .filter-group {
                display: flex;
                flex-direction: column;
                gap: 5px;
            }

            .filter-group label {
                font-weight: bold;
                font-size: 12px;
                color: #666;
            }

            .filter-group input,
            .filter-group select {
                padding: 8px 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                min-width: 150px;
            }

            .filter-actions {
                display: flex;
                gap: 10px;
                align-items: center;
            }

            .vsbbm-quick-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                margin-bottom: 20px;
            }

            .quick-stat {
                background: white;
                padding: 20px;
                border-radius: 8px;
                text-align: center;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                border-left: 4px solid #667eea;
            }

            .stat-number {
                display: block;
                font-size: 24px;
                font-weight: bold;
                color: #333;
                margin-bottom: 5px;
            }

            .stat-label {
                font-size: 14px;
                color: #666;
            }

            .vsbbm-tickets-table {
                background: white;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }

            #vsbbm-tickets-datatable {
                width: 100% !important;
                border: none;
            }

            #vsbbm-tickets-datatable th {
                background: #f8f9fa;
                font-weight: bold;
                padding: 15px;
            }

            #vsbbm-tickets-datatable td {
                padding: 12px 15px;
                vertical-align: middle;
            }

            .status-badge {
                padding: 6px 12px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: bold;
                display: inline-block;
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

            .status-expired {
                background: #fff3cd;
                color: #856404;
            }

            .action-buttons {
                display: flex;
                gap: 5px;
                align-items: center;
            }

            .no-records {
                text-align: center;
                padding: 40px !important;
            }

            .no-data-message {
                color: #666;
            }

            .no-data-message .dashicons {
                font-size: 48px;
                width: 48px;
                height: 48px;
                margin-bottom: 10px;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // راه‌اندازی DataTable
            $('#vsbbm-tickets-datatable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/fa.json'
                },
                order: [[4, 'desc']], // مرتب‌سازی بر اساس تاریخ
                pageLength: 25,
                responsive: true,
                autoWidth: false
            });

            // استفاده از بلیط
            $('.use-ticket').on('click', function() {
                const ticketId = $(this).data('ticket-id');
                const $button = $(this);

                if (confirm('آیا از استفاده این بلیط مطمئن هستید؟ این عمل قابل بازگشت نیست.')) {
                    $.ajax({
                        url: vsbbm_admin.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'vsbbm_use_ticket',
                            ticket_id: ticketId,
                            nonce: vsbbm_admin.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                $button.remove();
                                location.reload();
                            } else {
                                alert('خطا در بروزرسانی وضعیت بلیط');
                            }
                        },
                        error: function() {
                            alert('خطا در ارتباط با سرور');
                        }
                    });
                }
            });
        });
        </script>
        <?php
    }

    /**
     * دریافت بلیط‌ها با فیلتر
     */
    private function get_tickets($filters = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'vsbbm_tickets';

        $where_parts = array('1=1');
        $where_values = array();

        if (!empty($filters['status'])) {
            $where_parts[] = 'status = %s';
            $where_values[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $search = '%' . $wpdb->esc_like($filters['search']) . '%';
            $where_parts[] = '(ticket_number LIKE %s OR order_id = %d)';
            $where_values[] = $search;
            $where_values[] = intval($filters['search']);
        }

        $where_clause = implode(' AND ', $where_parts);

        $query = "SELECT * FROM $table_name WHERE $where_clause ORDER BY created_at DESC";

        if (!empty($where_values)) {
            $query = $wpdb->prepare($query, $where_values);
        }

        return $wpdb->get_results($query);
    }

    /**
     * شمارش بلیط‌ها بر اساس وضعیت
     */
    private function count_tickets_by_status($tickets, $status) {
        $count = 0;
        foreach ($tickets as $ticket) {
            if ($ticket->status === $status) {
                $count++;
            }
        }
        return $count;
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
     * پردازش عملیات بلیط
     */
    private function process_ticket_actions() {
        if (!isset($_GET['action']) || !isset($_GET['ticket_id']) || !wp_verify_nonce($_GET['_wpnonce'], 'vsbbm_ticket_action')) {
            return;
        }

        $action = sanitize_text_field($_GET['action']);
        $ticket_id = intval($_GET['ticket_id']);

        switch ($action) {
            case 'use':
                VSBBM_Ticket_Manager::use_ticket($ticket_id);
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success"><p>بلیط با موفقیت استفاده شد.</p></div>';
                });
                break;
        }
    }
    
} // پایان کلاس

// Initialize the class
VSBBM_Admin_Interface::init();