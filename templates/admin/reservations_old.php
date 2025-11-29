<div class="wrap">
    <h1>مدیریت رزروهای صندلی</h1>

    <!-- فیلترها -->
    <div class="vsbbm-filters" style="background: #fff; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <h3>فیلتر رزروها</h3>
        <form method="get" action="">
            <input type="hidden" name="page" value="vsbbm-reservations">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
                <div>
                    <label for="status">وضعیت:</label>
                    <select name="status" id="status" style="width: 100%;">
                        <option value="">همه وضعیت‌ها</option>
                        <?php foreach ($statuses as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($filters['status'], $key); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="product_id">محصول:</label>
                    <select name="product_id" id="product_id" style="width: 100%;">
                        <option value="">همه محصولات</option>
                        <?php
                        $products = get_posts(array(
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
                        foreach ($products as $product):
                        ?>
                            <option value="<?php echo esc_attr($product->ID); ?>" <?php selected($filters['product_id'], $product->ID); ?>>
                                <?php echo esc_html($product->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="date_from">از تاریخ:</label>
                    <input type="date" name="date_from" id="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" style="width: 100%;">
                </div>

                <div>
                    <label for="date_to">تا تاریخ:</label>
                    <input type="date" name="date_to" id="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" style="width: 100%;">
                </div>
            </div>

            <div style="display: flex; gap: 10px;">
                <button type="submit" class="button button-primary">فیلتر</button>
                <a href="<?php echo admin_url('admin.php?page=vsbbm-reservations'); ?>" class="button">پاک کردن فیلترها</a>
            </div>
        </form>
    </div>

    <!-- جدول رزروها -->
    <div class="vsbbm-reservations-table" style="background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden;">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 60px;">ID</th>
                    <th>محصول</th>
                    <th>صندلی</th>
                    <th>کاربر</th>
                    <th>سفارش</th>
                    <th>وضعیت</th>
                    <th>تاریخ رزرو</th>
                    <th>انقضا</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($reservations)): ?>
                    <?php foreach ($reservations as $reservation): ?>
                        <tr>
                            <td><?php echo esc_html($reservation->id); ?></td>
                            <td>
                                <strong><?php echo esc_html($reservation->product_name ?: 'محصول حذف شده'); ?></strong>
                                <br>
                                <small>ID: <?php echo esc_html($reservation->product_id); ?></small>
                            </td>
                            <td><strong><?php echo esc_html($reservation->seat_number); ?></strong></td>
                            <td><?php echo esc_html($reservation->user_name ?: 'کاربر مهمان'); ?></td>
                            <td>
                                <?php if ($reservation->order_id): ?>
                                    <a href="<?php echo admin_url('post.php?post=' . $reservation->order_id . '&action=edit'); ?>" target="_blank">
                                        #<?php echo esc_html($reservation->order_id); ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="vsbbm-status-badge vsbbm-status-<?php echo esc_attr($reservation->status); ?>">
                                    <?php echo esc_html($statuses[$reservation->status] ?? $reservation->status); ?>
                                </span>
                            </td>
                            <td><?php echo date('Y/m/d H:i', strtotime($reservation->reserved_at)); ?></td>
                            <td>
                                <?php if ($reservation->expires_at && $reservation->status === 'reserved'): ?>
                                    <span class="<?php echo (strtotime($reservation->expires_at) < time()) ? 'vsbbm-expired' : 'vsbbm-active'; ?>">
                                        <?php echo date('Y/m/d H:i', strtotime($reservation->expires_at)); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($reservation->status === 'reserved'): ?>
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=vsbbm-reservations&action=confirm&reservation_id=' . $reservation->id), 'vsbbm_reservation_action'); ?>"
                                       class="button button-small button-primary"
                                       onclick="return confirm('آیا از تایید این رزرو اطمینان دارید؟')">
                                        ✅ تایید
                                    </a>
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=vsbbm-reservations&action=cancel&reservation_id=' . $reservation->id), 'vsbbm_reservation_action'); ?>"
                                       class="button button-small button-secondary"
                                       onclick="return confirm('آیا از لغو این رزرو اطمینان دارید؟')">
                                        ❌ لغو
                                    </a>
                                <?php else: ?>
                                    <span style="color: #999; font-style: italic;">عملیات موجود نیست</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px;">
                            <p style="color: #666; font-size: 16px;">هیچ رزروی یافت نشد.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- آمار سریع -->
    <div class="vsbbm-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-top: 20px;">
        <?php
        $stats = VSBBM_Seat_Reservations::get_reservation_stats();
        $stats_labels = array(
            'reserved_count' => 'رزرو شده',
            'confirmed_count' => 'تایید شده',
            'cancelled_count' => 'لغو شده',
            'expired_count' => 'منقضی شده'
        );
        $stats_colors = array(
            'reserved_count' => '#f39c12',
            'confirmed_count' => '#27ae60',
            'cancelled_count' => '#e74c3c',
            'expired_count' => '#95a5a6'
        );
        ?>

        <?php foreach ($stats_labels as $key => $label): ?>
            <div class="vsbbm-stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center;">
                <div style="font-size: 24px; font-weight: bold; color: <?php echo $stats_colors[$key]; ?>; margin-bottom: 5px;">
                    <?php echo intval($stats->$key); ?>
                </div>
                <div style="color: #666; font-size: 14px;"><?php echo esc_html($label); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<style>
.vsbbm-status-badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
}

.vsbbm-status-reserved { background: #fff3cd; color: #856404; }
.vsbbm-status-confirmed { background: #d1e7dd; color: #0f5132; }
.vsbbm-status-cancelled { background: #f8d7da; color: #721c24; }
.vsbbm-status-expired { background: #e2e3e5; color: #383d41; }

.vsbbm-expired { color: #e74c3c; font-weight: bold; }
.vsbbm-active { color: #27ae60; }

.vsbbm-filters {
    margin-bottom: 20px;
}

.vsbbm-filters label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
}

.vsbbm-filters select,
.vsbbm-filters input {
    padding: 6px 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
}
</style>