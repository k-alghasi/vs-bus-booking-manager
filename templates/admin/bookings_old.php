<?php
defined('ABSPATH') || exit;
?>

<div class="wrap vsbbm-admin-bookings">
    <h1 class="wp-heading-inline">مدیریت رزروها</h1>
    
    <!-- فیلترها -->
    <div class="vsbbm-filters">
        <form method="get" class="vsbbm-filter-form">
            <input type="hidden" name="page" value="vsbbm-bookings">

            <div class="filter-row">
                <div class="filter-group">
                    <label for="status">وضعیت:</label>
                    <select name="status" id="status">
                        <option value="">همه وضعیت‌ها</option>
                        <?php foreach ($statuses as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($filters['status'], $key); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="product_id">سرویس:</label>
                    <select name="product_id" id="product_id">
                        <option value="">همه سرویس‌ها</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?php echo esc_attr($product->ID); ?>" <?php selected($filters['product_id'], $product->ID); ?>>
                                <?php echo esc_html($product->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="date_from">از تاریخ:</label>
                    <input type="date" name="date_from" id="date_from" value="<?php echo esc_attr($filters['date_from']); ?>">
                </div>

                <div class="filter-group">
                    <label for="date_to">تا تاریخ:</label>
                    <input type="date" name="date_to" id="date_to" value="<?php echo esc_attr($filters['date_to']); ?>">
                </div>

                <div class="filter-group">
                    <label for="search">جستجو:</label>
                    <input type="text" name="search" id="search" value="<?php echo esc_attr($filters['search']); ?>" placeholder="شماره سفارش، نام یا ایمیل">
                </div>

                <div class="filter-actions">
                    <button type="submit" class="button button-primary">اعمال فیلتر</button>
                    <a href="<?php echo admin_url('admin.php?page=vsbbm-bookings'); ?>" class="button">حذف فیلترها</a>
                </div>
            </div>
        </form>
    </div>

    <!-- آمار سریع -->
    <div class="vsbbm-quick-stats">
        <div class="quick-stat">
            <span class="stat-number"><?php echo number_format(count($bookings)); ?></span>
            <span class="stat-label">تعداد رزروها</span>
        </div>
        <div class="quick-stat">
            <span class="stat-number"><?php echo wc_price(array_sum(array_column($bookings, 'order_total'))); ?></span>
            <span class="stat-label">درآمد کل</span>
        </div>
        <div class="quick-stat">
            <span class="stat-number"><?php echo $this->calculate_passengers_from_bookings($bookings); ?></span>
            <span class="stat-label">تعداد مسافران</span>
        </div>
    </div>

    <!-- جدول رزروها -->
    <form method="post" id="vsbbm-bulk-form">
        <?php wp_nonce_field('vsbbm_bulk_action'); ?>
        <div class="vsbbm-bookings-table">
            <table id="vsbbm-bookings-datatable" class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" id="select-all-bookings"></th>
                        <th>شماره سفارش</th>
                        <th>تاریخ رزرو</th>
                        <th>مشتری</th>
                        <th>ایمیل</th>
                        <th>سرویس</th>
                        <th>تعداد مسافران</th>
                        <th>مبلغ</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
            <tbody>
    <?php if (!empty($bookings)): ?>
        <?php foreach ($bookings as $booking): ?>
            <?php
            $passenger_count = $this->get_passenger_count_for_booking($booking->ID);
            $status_class = 'status-' . str_replace('wc-', '', $booking->post_status);
            $order = wc_get_order($booking->ID);
            $service_name = 'نامشخص';

            if ($order) {
                foreach ($order->get_items() as $item) {
                    $product = $item->get_product();
                    if ($product && VSBBM_Seat_Manager::is_seat_booking_enabled($product->get_id())) {
                        $service_name = $product->get_name();
                        break;
                    }
                }
            }
            ?>
            <tr>
                <td>
                    <input type="checkbox" name="booking_ids[]" value="<?php echo esc_attr($booking->ID); ?>" class="booking-checkbox">
                </td>
                <td>
                    <strong>#<?php echo esc_html($booking->ID); ?></strong>
                </td>
                <td>
                    <?php echo date_i18n('Y/m/d H:i', strtotime($booking->post_date)); ?>
                </td>
                <td>
                    <?php echo esc_html($booking->display_name ?: 'مهمان'); ?>
                </td>
                <td>
                    <?php echo esc_html($booking->user_email ?: '-'); ?>
                </td>
                <td>
                    <span class="service-name"><?php echo esc_html($service_name); ?></span>
                </td>
                <td>
                    <span class="passenger-count"><?php echo $passenger_count; ?> نفر</span>
                </td>
                <td>
                    <strong><?php echo $booking->order_total ? wc_price($booking->order_total) : '۰'; ?></strong>
                </td>
                <td>
                    <span class="status-badge <?php echo esc_attr($status_class); ?>">
                        <?php echo $this->get_status_label($booking->post_status); ?>
                    </span>
                </td>
                <td>
                    <div class="action-buttons">
                        <button type="button" class="button button-small view-booking" 
                                data-booking-id="<?php echo $booking->ID; ?>">
                            مشاهده
                        </button>
                        
                        <div class="dropdown">
                            <button type="button" class="button button-small dropdown-toggle">
                                ...
                            </button>
                            <div class="dropdown-menu">
                                <?php if ($booking->post_status !== 'wc-cancelled'): ?>
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=vsbbm-bookings&action=cancel&booking_id=' . $booking->ID), 'vsbbm_booking_action'); ?>" 
                                       class="dropdown-item" 
                                       onclick="return confirm('آیا از لغو این رزرو مطمئن هستید؟')">
                                        لغو رزرو
                                    </a>
                                <?php endif; ?>
                                
                                <a href="<?php echo get_edit_post_link($booking->ID); ?>" 
                                   class="dropdown-item" target="_blank">
                                    ویرایش در ووکامرس
                                </a>
                                
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=vsbbm-bookings&action=delete&booking_id=' . $booking->ID), 'vsbbm_booking_action'); ?>" 
                                   class="dropdown-item text-danger" 
                                   onclick="return confirm('آیا از حذف این رزرو مطمئن هستید؟ این عمل غیرقابل بازگشت است.')">
                                    حذف
                                </a>
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="10" class="no-records">
                <div class="no-data-message">
                    <span class="dashicons dashicons-info"></span>
                    <p>هیچ سفارشی یافت نشد. پس از ایجاد سفارش‌های رزرو، اینجا نمایش داده می‌شوند.</p>
                </div>
            </td>
        </tr>
    <?php endif; ?>
</tbody>
            </table>
        </div>
    </form>

    <!-- اکشن‌های گروهی -->
    <div class="vsbbm-bulk-actions">
        <div class="bulk-actions-left">
            <select name="action" id="bulk-action-selector">
                <option value="">عملیات گروهی</option>
                <option value="status_completed">تغییر وضعیت به تکمیل شده</option>
                <option value="status_processing">تغییر وضعیت به در حال پردازش</option>
                <option value="status_cancelled">تغییر وضعیت به لغو شده</option>
                <option value="export">خروجی Excel</option>
            </select>
            <button type="submit" id="apply-bulk-action" class="button button-secondary">
                <span class="dashicons dashicons-yes"></span>
                اعمال عملیات
            </button>
        </div>

        <div class="bulk-actions-right">
            <button type="button" id="export-all-bookings" class="button button-primary">
                <span class="dashicons dashicons-download"></span>
                خروجی Excel از همه رزروها
            </button>
            <a href="<?php echo admin_url('admin.php?page=vsbbm-reservations'); ?>" class="button button-outline">
                <span class="dashicons dashicons-calendar"></span>
                مدیریت رزروها
            </a>
        </div>
    </div>
</div>

<!-- مودال مشاهده جزئیات رزرو -->
<div id="vsbbm-booking-modal" class="vsbbm-modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>جزئیات رزرو</h3>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="booking-details-content">
                <!-- محتوای جزئیات رزرو اینجا لود می‌شود -->
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="button button-primary modal-close">بستن</button>
        </div>
    </div>
</div>

<style>
.vsbbm-admin-bookings {
    padding: 20px;
}

/* استایل فیلترها */
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

/* آمار سریع */
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

/* جدول */
.vsbbm-bookings-table {
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

#vsbbm-bookings-datatable {
    width: 100% !important;
    border: none;
}

#vsbbm-bookings-datatable th {
    background: #f8f9fa;
    font-weight: bold;
    padding: 15px;
}

#vsbbm-bookings-datatable td {
    padding: 12px 15px;
    vertical-align: middle;
}

/* وضعیت‌ها */
.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
    display: inline-block;
}

.status-completed {
    background: #e8f5e8;
    color: #2e7d32;
}

.status-processing {
    background: #e3f2fd;
    color: #1976d2;
}

.status-pending {
    background: #fff3e0;
    color: #f57c00;
}

.status-on-hold {
    background: #fce4ec;
    color: #c2185b;
}

.status-cancelled {
    background: #ffebee;
    color: #c62828;
}

/* دکمه‌های عملیات */
.action-buttons {
    display: flex;
    gap: 5px;
    align-items: center;
}

.dropdown {
    position: relative;
    display: inline-block;
}

.dropdown-toggle {
    padding: 4px 8px;
    min-width: 30px;
}

.dropdown-menu {
    display: none;
    position: absolute;
    right: 0;
    top: 100%;
    background: white;
    border: 1px solid #ddd;
    border-radius: 4px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    min-width: 150px;
    z-index: 1000;
}

.dropdown:hover .dropdown-menu {
    display: block;
}

.dropdown-item {
    display: block;
    padding: 8px 12px;
    text-decoration: none;
    color: #333;
    border: none;
    background: none;
    width: 100%;
    text-align: right;
    cursor: pointer;
}

.dropdown-item:hover {
    background: #f5f5f5;
}

.text-danger {
    color: #dc3545 !important;
}

.text-danger:hover {
    background: #ffe6e6 !important;
}

/* وقتی داده‌ای وجود ندارد */
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

/* اکشن‌های گروهی */
.vsbbm-bulk-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    opacity: 0.6;
    transition: opacity 0.3s ease;
}

.vsbbm-bulk-actions.has-selection {
    opacity: 1;
    box-shadow: 0 4px 15px rgba(0,0,0,0.15);
}

.bulk-actions-left {
    display: flex;
    gap: 10px;
    align-items: center;
}

/* مودال */
.vsbbm-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: white;
    border-radius: 8px;
    width: 90%;
    max-width: 600px;
    max-height: 80vh;
    overflow: auto;
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #ddd;
    text-align: left;
}

/* رسپانسیو */
@media (max-width: 768px) {
    .filter-row {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-group {
        width: 100%;
    }
    
    .vsbbm-bulk-actions {
        flex-direction: column;
        gap: 15px;
        align-items: stretch;
    }
    
    .action-buttons {
        flex-direction: column;
    }
    
    .dropdown-menu {
        right: auto;
        left: 0;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // راه‌اندازی DataTable
    $('#vsbbm-bookings-datatable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/fa.json'
        },
        order: [[1, 'desc']], // تغییر به ستون شماره سفارش
        pageLength: 25,
        responsive: true,
        autoWidth: false,
        columnDefs: [
            { targets: 0, orderable: false, width: '40px' }, // checkbox column
            { targets: '_all', width: 'auto' }
        ]
    });

    // انتخاب همه چک‌باکس‌ها
    $('#select-all-bookings').on('change', function() {
        $('.booking-checkbox').prop('checked', $(this).prop('checked'));
        updateBulkActionsVisibility();
    });

    // بروزرسانی وضعیت دکمه‌های bulk action
    $('.booking-checkbox').on('change', function() {
        updateBulkActionsVisibility();
    });

    function updateBulkActionsVisibility() {
        const checkedCount = $('.booking-checkbox:checked').length;
        if (checkedCount > 0) {
            $('.vsbbm-bulk-actions').addClass('has-selection');
            $('#apply-bulk-action').html('<span class="dashicons dashicons-yes"></span> اعمال روی ' + checkedCount + ' مورد');
        } else {
            $('.vsbbm-bulk-actions').removeClass('has-selection');
            $('#apply-bulk-action').html('<span class="dashicons dashicons-yes"></span> اعمال عملیات');
        }
    }
    
    // مشاهده جزئیات رزرو
    $('.view-booking').on('click', function() {
        const bookingId = $(this).data('booking-id');
        
        // نمایش loading
        $('#booking-details-content').html('<div style="text-align: center; padding: 40px;">در حال بارگذاری...</div>');
        $('#vsbbm-booking-modal').show();
        
        // دریافت جزئیات از طریق AJAX
        $.ajax({
            url: vsbbm_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'vsbbm_get_booking_details',
                booking_id: bookingId,
                nonce: vsbbm_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    const booking = response.data;
                    let html = `
                        <div class="booking-details">
                            <div class="detail-row">
                                <strong>شماره سفارش:</strong> #${booking.id}
                            </div>
                            <div class="detail-row">
                                <strong>تاریخ رزرو:</strong> ${booking.date}
                            </div>
                            <div class="detail-row">
                                <strong>مشتری:</strong> ${booking.customer_name}
                            </div>
                            <div class="detail-row">
                                <strong>ایمیل:</strong> ${booking.customer_email}
                            </div>
                            <div class="detail-row">
                                <strong>تلفن:</strong> ${booking.customer_phone || '-'}
                            </div>
                            <div class="detail-row">
                                <strong>مبلغ:</strong> ${booking.total_amount} ریال
                            </div>
                            <div class="detail-row">
                                <strong>وضعیت:</strong> <span class="status-badge">${booking.status}</span>
                            </div>
                            <div class="detail-row">
                                <strong>روش پرداخت:</strong> ${booking.payment_method || '-'}
                            </div>
                    `;
                    
                    if (booking.passengers && booking.passengers.length > 0) {
                        html += `<div class="detail-section">
                                    <h4>مسافران</h4>`;
                        booking.passengers.forEach(passenger => {
                            html += `<div class="passenger-item">${passenger}</div>`;
                        });
                        html += `</div>`;
                    }
                    
                    html += `</div>`;
                    $('#booking-details-content').html(html);
                } else {
                    $('#booking-details-content').html('<div class="error">خطا در دریافت اطلاعات</div>');
                }
            },
            error: function() {
                $('#booking-details-content').html('<div class="error">خطا در ارتباط با سرور</div>');
            }
        });
    });
    
    // بستن مودال
    $('.modal-close').on('click', function() {
        $('#vsbbm-booking-modal').hide();
    });
    
    // خروجی Excel
    $('#export-all-bookings').on('click', function() {
        const $button = $(this);
        const originalText = $button.html();
        
        $button.html('<span class="dashicons dashicons-update spin"></span> در حال آماده‌سازی...').prop('disabled', true);
        
        // دریافت فیلترهای فعلی
        const filters = {
            status: $('#status').val(),
            date_from: $('#date_from').val(),
            date_to: $('#date_to').val(),
            search: $('#search').val()
        };
        
        // ایجاد فرم موقت برای ارسال داده
        const $form = $('<form>').attr({
            method: 'POST',
            action: vsbbm_admin.ajax_url
        }).append(
            $('<input>').attr({type: 'hidden', name: 'action', value: 'vsbbm_export_bookings'}),
            $('<input>').attr({type: 'hidden', name: 'nonce', value: vsbbm_admin.nonce}),
            $('<input>').attr({type: 'hidden', name: 'status', value: filters.status}),
            $('<input>').attr({type: 'hidden', name: 'date_from', value: filters.date_from}),
            $('<input>').attr({type: 'hidden', name: 'date_to', value: filters.date_to})
        );
        
        $('body').append($form);
        $form.submit();
        
        // بازگشت دکمه به حالت عادی بعد از 2 ثانیه
        setTimeout(function() {
            $button.html(originalText).prop('disabled', false);
            $form.remove();
        }, 2000);
    });
});
</script>