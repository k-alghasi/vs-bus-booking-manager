<?php
defined('ABSPATH') || exit;
?>

<div class="wrap vsbbm-admin-settings">
    <h1 class="wp-heading-inline">تنظیمات سیستم رزرو اتوبوس</h1>
    
    <div class="vsbbm-settings-container">
        <div class="settings-main">
            <!-- فرم تنظیمات -->
            <form method="post" class="vsbbm-settings-form">
                <?php wp_nonce_field('vsbbm_save_settings'); ?>
                
                <div class="settings-section">
                    <h2>⚙️ تنظیمات عمومی</h2>
                    
                    <div class="form-row">
                        <label for="reservation_timeout">زمان رزرو موقت (دقیقه)</label>
                        <input type="number" id="reservation_timeout" name="reservation_timeout" 
                               value="<?php echo esc_attr($settings['reservation_timeout']); ?>" 
                               min="5" max="60" class="regular-text">
                        <p class="description">مدت زمانی که صندلی به صورت موقت برای کاربر رزرو می‌شود</p>
                    </div>
                    
                    <div class="form-row">
                        <label for="max_seats_per_booking">حداکثر صندلی در هر رزرو</label>
                        <input type="number" id="max_seats_per_booking" name="max_seats_per_booking" 
                               value="<?php echo esc_attr($settings['max_seats_per_booking']); ?>" 
                               min="1" max="50" class="regular-text">
                        <p class="description">حداکثر تعداد صندلی‌هایی که یک کاربر می‌تواند در یک رزرو انتخاب کند</p>
                    </div>
                </div>
                
                <div class="settings-section">
                    <h2>📧 تنظیمات اطلاع‌رسانی</h2>
                    
                    <div class="form-row checkbox-row">
                        <label>
                            <input type="checkbox" name="enable_email_notifications" value="1" 
                                   <?php checked($settings['enable_email_notifications'], true); ?>>
                            فعال‌سازی ارسال ایمیل تأیید رزرو
                        </label>
                        <p class="description">ارسال ایمیل خودکار پس از تکمیل هر رزرو</p>
                    </div>
                    
                    <div class="form-row">
                        <label for="admin_email">ایمیل مدیر</label>
                        <input type="email" id="admin_email" name="admin_email" 
                               value="<?php echo esc_attr(get_option('admin_email')); ?>" 
                               class="regular-text" readonly>
                        <p class="description">ایمیل دریافت گزارش‌های روزانه (غیرقابل تغییر)</p>
                    </div>
                </div>
                
                <div class="settings-section">
                    <h2>🎫 تنظیمات صندلی‌ها</h2>
                    
                    <div class="form-row">
                        <label for="default_seat_layout">طرح پیش‌فرض صندلی‌ها</label>
                        <select id="default_seat_layout" name="default_seat_layout" class="regular-text">
                            <option value="4x8">۴ ردیف ۸ تایی (۳۲ صندلی)</option>
                            <option value="5x8">۵ ردیف ۸ تایی (۴۰ صندلی)</option>
                            <option value="6x8">۶ ردیف ۸ تایی (۴۸ صندلی)</option>
                        </select>
                        <p class="description">طرح پیش‌فرض چیدمان صندلی‌های اتوبوس</p>
                    </div>
                    
                    <div class="form-row checkbox-row">
                        <label>
                            <input type="checkbox" name="allow_seat_selection" value="1" checked>
                            امکان انتخاب صندلی توسط کاربر
                        </label>
                        <p class="description">اجازه دادن به کاربران برای انتخاب صندلی مورد نظر خود</p>
                    </div>
                </div>
                
                <div class="settings-actions">
                    <button type="submit" name="vsbbm_save_settings" class="button button-primary button-large">
                        ذخیره تنظیمات
                    </button>
                    <button type="button" id="reset-settings" class="button button-secondary">
                        بازنشانی به پیش‌فرض
                    </button>
                </div>
            </form>
        </div>
        
        <div class="settings-sidebar">
            <!-- کارت وضعیت سیستم -->
            <div class="status-card">
                <h3>📊 وضعیت سیستم</h3>
                <div class="status-items">
                    <div class="status-item">
                        <span class="status-label">تعداد رزروهای فعال:</span>
                        <span class="status-value"><?php echo $this->get_active_bookings_count(); ?></span>
                    </div>
                    <div class="status-item">
                        <span class="status-label">صندلی‌های اشغال شده:</span>
                        <span class="status-value"><?php echo $this->calculate_total_passengers(); ?> از ۳۲</span>
                    </div>
                    <div class="status-item">
                        <span class="status-label">نرخ اشغال:</span>
                        <span class="status-value"><?php echo $this->calculate_occupancy_rate(); ?>%</span>
                    </div>
                    <div class="status-item">
                        <span class="status-label">آخرین به‌روزرسانی:</span>
                        <span class="status-value"><?php echo date('Y/m/d H:i'); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- کارت راهنما -->
            <div class="help-card">
                <h3>❓ راهنمای سریع</h3>
                <ul class="help-list">
                    <li>زمان رزرو موقت را بین ۱۰-۲۰ دقیقه تنظیم کنید</li>
                    <li>حداکثر صندلی معمولاً بین ۵-۱۰ عدد مناسب است</li>
                    <li>ایمیل تأیید را برای تجربه کاربری بهتر فعال کنید</li>
                    <li>طرح صندلی را متناسب با اتوبوس خود انتخاب کنید</li>
                </ul>
            </div>
            
            <!-- کارت پشتیبانی -->
            <div class="support-card">
                <h3>🛠️ پشتیبانی</h3>
                <p>در صورت نیاز به راهنمایی با ما در تماس باشید:</p>
                <div class="support-contacts">
                    <p>📧 ایمیل: support@vernasoft.ir</p>
                    <p>🌐 وبسایت: vernasoft.ir</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.vsbbm-admin-settings {
    padding: 20px;
}

.vsbbm-settings-container {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 30px;
    margin-top: 20px;
}

/* فرم تنظیمات */
.settings-main {
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.settings-section {
    margin-bottom: 40px;
    padding-bottom: 30px;
    border-bottom: 1px solid #eee;
}

.settings-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.settings-section h2 {
    margin: 0 0 25px 0;
    color: #333;
    font-size: 18px;
    padding-bottom: 10px;
    border-bottom: 2px solid #667eea;
}

.form-row {
    margin-bottom: 20px;
}

.form-row label {
    display: block;
    font-weight: bold;
    margin-bottom: 8px;
    color: #555;
}

.form-row input[type="text"],
.form-row input[type="number"],
.form-row input[type="email"],
.form-row select {
    width: 100%;
    max-width: 400px;
    padding: 10px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
}

.form-row input:focus,
.form-row select:focus {
    border-color: #667eea;
    outline: none;
    box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
}

.checkbox-row label {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: normal;
    cursor: pointer;
}

.checkbox-row input[type="checkbox"] {
    margin: 0;
}

.description {
    margin: 8px 0 0 0;
    color: #666;
    font-size: 13px;
    line-height: 1.4;
}

.settings-actions {
    margin-top: 40px;
    padding-top: 20px;
    border-top: 1px solid #eee;
    display: flex;
    gap: 15px;
}

/* نوار کناری */
.settings-sidebar {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.status-card,
.help-card,
.support-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.status-card h3,
.help-card h3,
.support-card h3 {
    margin: 0 0 20px 0;
    color: #333;
    font-size: 16px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.status-items {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.status-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
}

.status-label {
    color: #666;
    font-size: 14px;
}

.status-value {
    font-weight: bold;
    color: #333;
}

.help-list {
    margin: 0;
    padding-right: 15px;
}

.help-list li {
    margin-bottom: 10px;
    color: #666;
    font-size: 14px;
    line-height: 1.4;
}

.help-list li:last-child {
    margin-bottom: 0;
}

.support-contacts p {
    margin: 10px 0;
    color: #666;
    font-size: 14px;
}

/* رسپانسیو */
@media (max-width: 1024px) {
    .vsbbm-settings-container {
        grid-template-columns: 1fr;
    }
    
    .settings-sidebar {
        order: -1;
    }
}

@media (max-width: 768px) {
    .settings-main {
        padding: 20px;
    }
    
    .settings-actions {
        flex-direction: column;
    }
    
    .form-row input[type="text"],
    .form-row input[type="number"],
    .form-row input[type="email"],
    .form-row select {
        max-width: 100%;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // اعتبارسنجی فرم
    $('.vsbbm-settings-form').on('submit', function(e) {
        let isValid = true;
        
        // بررسی زمان رزرو
        const timeout = $('#reservation_timeout').val();
        if (timeout < 5 || timeout > 60) {
            alert('زمان رزرو موقت باید بین ۵ تا ۶۰ دقیقه باشد');
            isValid = false;
        }
        
        // بررسی تعداد صندلی
        const maxSeats = $('#max_seats_per_booking').val();
        if (maxSeats < 1 || maxSeats > 50) {
            alert('حداکثر صندلی باید بین ۱ تا ۵۰ باشد');
            isValid = false;
        }
        
        if (!isValid) {
            e.preventDefault();
        }
    });
    
    // بازنشانی تنظیمات
    $('#reset-settings').on('click', function() {
        if (confirm('آیا از بازنشانی تنظیمات به مقادیر پیش‌فرض مطمئن هستید؟')) {
            $('#reservation_timeout').val(15);
            $('#max_seats_per_booking').val(10);
            $('input[name="enable_email_notifications"]').prop('checked', true);
            $('#default_seat_layout').val('4x8');
            $('input[name="allow_seat_selection"]').prop('checked', true);
            
            alert('تنظیمات به مقادیر پیش‌فرض بازنشانی شدند. برای ذخیره تغییرات، دکمه "ذخیره تنظیمات" را بزنید.');
        }
    });
    
    // نمایش پیشنمایش تغییرات
    $('input, select').on('change', function() {
        $(this).addClass('changed');
    });
});
</script>