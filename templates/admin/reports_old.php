<?php
defined('ABSPATH') || exit;
?>

<div class="wrap vsbbm-admin-reports">
    <h1 class="wp-heading-inline">گزارش‌گیری و آمار</h1>
    
    <!-- تب‌های گزارش -->
    <div class="vsbbm-report-tabs">
        <nav class="nav-tab-wrapper">
            <a href="<?php echo admin_url('admin.php?page=vsbbm-reports&report_type=daily'); ?>" 
               class="nav-tab <?php echo $report_type === 'daily' ? 'nav-tab-active' : ''; ?>">
                📊 گزارش روزانه
            </a>
            <a href="<?php echo admin_url('admin.php?page=vsbbm-reports&report_type=weekly'); ?>" 
               class="nav-tab <?php echo $report_type === 'weekly' ? 'nav-tab-active' : ''; ?>">
                📈 گزارش هفتگی
            </a>
            <a href="<?php echo admin_url('admin.php?page=vsbbm-reports&report_type=monthly'); ?>" 
               class="nav-tab <?php echo $report_type === 'monthly' ? 'nav-tab-active' : ''; ?>">
                📅 گزارش ماهانه
            </a>
        </nav>
    </div>

    <!-- کارت‌های خلاصه آمار -->
    <div class="vsbbm-report-summary">
        <div class="summary-card">
            <div class="summary-icon">🛒</div>
            <div class="summary-content">
                <h3>تعداد رزروها</h3>
                <span class="summary-number">
                    <?php echo array_sum(array_column($report_data, 'bookings')); ?>
                </span>
            </div>
        </div>
        
        <div class="summary-card">
            <div class="summary-icon">💰</div>
            <div class="summary-content">
                <h3>درآمد کل</h3>
                <span class="summary-number">
                    <?php echo wc_price(array_sum(array_column($report_data, 'revenue'))); ?>
                </span>
            </div>
        </div>
        
        <div class="summary-card">
            <div class="summary-icon">📈</div>
            <div class="summary-content">
                <h3>میانگین هر رزرو</h3>
                <span class="summary-number">
                    <?php 
                    $total_bookings = array_sum(array_column($report_data, 'bookings'));
                    $total_revenue = array_sum(array_column($report_data, 'revenue'));
                    echo $total_bookings > 0 ? wc_price($total_revenue / $total_bookings) : wc_price(0);
                    ?>
                </span>
            </div>
        </div>
    </div>

    <!-- نمودار -->
    <div class="vsbbm-report-chart">
        <div class="chart-container">
            <h3>نمودار <?php echo $report_type === 'daily' ? 'روزانه' : ($report_type === 'weekly' ? 'هفتگی' : 'ماهانه'); ?></h3>
            <canvas id="reportChart" height="100"></canvas>
        </div>
    </div>

    <!-- جدول گزارش -->
    <div class="vsbbm-report-table">
        <div class="table-header">
            <h3>گزارش تفصیلی</h3>
            <button type="button" id="export-report" class="button button-primary">
                <span class="dashicons dashicons-download"></span>
                خروجی Excel
            </button>
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <?php if ($report_type === 'daily'): ?>
                        <th>تاریخ</th>
                    <?php elseif ($report_type === 'weekly'): ?>
                        <th>هفته</th>
                        <th>بازه زمانی</th>
                    <?php else: ?>
                        <th>ماه</th>
                        <th>بازه زمانی</th>
                    <?php endif; ?>
                    <th>تعداد رزرو</th>
                    <th>درآمد</th>
                    <th>میانگین هر رزرو</th>
                    <th>عملکرد</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($report_data)): ?>
                    <?php foreach ($report_data as $report): ?>
                        <tr>
                            <?php if ($report_type === 'daily'): ?>
                                <td>
                                    <strong><?php echo esc_html($report['date']); ?></strong>
                                </td>
                            <?php elseif ($report_type === 'weekly'): ?>
                                <td>
                                    <strong><?php echo esc_html($report['week']); ?></strong>
                                </td>
                                <td><?php echo esc_html($report['period']); ?></td>
                            <?php else: ?>
                                <td>
                                    <strong><?php echo esc_html($report['month']); ?></strong>
                                </td>
                                <td><?php echo esc_html($report['period']); ?></td>
                            <?php endif; ?>
                            
                            <td>
                                <span class="booking-count"><?php echo number_format($report['bookings']); ?></span>
                            </td>
                            <td>
                                <strong><?php echo wc_price($report['revenue']); ?></strong>
                            </td>
                            <td>
                                <?php 
                                $avg = $report['bookings'] > 0 ? $report['revenue'] / $report['bookings'] : 0;
                                echo wc_price($avg);
                                ?>
                            </td>
                            <td>
                                <div class="performance-indicator">
                                    <?php
                                    $max_bookings = max(array_column($report_data, 'bookings'));
                                    $percentage = $max_bookings > 0 ? ($report['bookings'] / $max_bookings) * 100 : 0;
                                    ?>
                                    <div class="performance-bar">
                                        <div class="performance-fill" style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                    <span class="performance-text"><?php echo round($percentage); ?>%</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="no-data">
                            <div class="no-data-message">
                                <span class="dashicons dashicons-chart-bar"></span>
                                <p>داده‌ای برای نمایش وجود ندارد.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- گزارش مقایسه‌ای -->
    <div class="vsbbm-comparison-report">
        <div class="comparison-header">
            <h3>📊 گزارش مقایسه‌ای</h3>
        </div>
        <div class="comparison-cards">
            <div class="comparison-card">
                <h4>امروز vs دیروز</h4>
                <div class="comparison-value <?php echo $this->get_comparison_class($report_data[0]['bookings'], $report_data[1]['bookings']); ?>">
                    <?php echo $this->get_comparison_percentage($report_data[0]['bookings'], $report_data[1]['bookings']); ?>%
                </div>
                <small>تغییر در تعداد رزروها</small>
            </div>
            
            <div class="comparison-card">
                <h4>این هفته vs هفته قبل</h4>
                <div class="comparison-value positive">
                    +۱۵%
                </div>
                <small>رشد درآمد</small>
            </div>
            
            <div class="comparison-card">
                <h4>پرطرفدارترین روز</h4>
                <div class="comparison-value neutral">
                    <?php echo $this->get_most_popular_day($report_data); ?>
                </div>
                <small>بر اساس تعداد رزرو</small>
            </div>
        </div>
    </div>
</div>

<style>
.vsbbm-admin-reports {
    padding: 20px;
}

/* تب‌ها */
.vsbbm-report-tabs {
    margin-bottom: 30px;
}

.nav-tab-wrapper {
    border-bottom: 1px solid #ccc;
}

.nav-tab {
    font-size: 14px;
    padding: 10px 20px;
    margin-right: 5px;
}

/* خلاصه آمار */
.vsbbm-report-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.summary-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    gap: 20px;
    border-left: 4px solid #667eea;
}

.summary-icon {
    font-size: 40px;
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 50%;
    color: white;
}

.summary-content h3 {
    margin: 0 0 5px 0;
    color: #666;
    font-size: 14px;
}

.summary-number {
    font-size: 28px;
    font-weight: bold;
    color: #333;
}

/* نمودار */
.vsbbm-report-chart {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    margin-bottom: 30px;
}

.chart-container h3 {
    margin: 0 0 20px 0;
    text-align: center;
    color: #333;
}

/* جدول گزارش */
.vsbbm-report-table {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    margin-bottom: 30px;
    overflow: hidden;
}

.table-header {
    padding: 20px;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.table-header h3 {
    margin: 0;
}

.vsbbm-report-table table {
    margin: 0;
    border: none;
}

.vsbbm-report-table th {
    background: #f8f9fa;
    font-weight: bold;
    padding: 15px;
}

.vsbbm-report-table td {
    padding: 12px 15px;
    vertical-align: middle;
}

/* نشانگر عملکرد */
.performance-indicator {
    display: flex;
    align-items: center;
    gap: 10px;
}

.performance-bar {
    flex: 1;
    height: 8px;
    background: #f0f0f0;
    border-radius: 4px;
    overflow: hidden;
}

.performance-fill {
    height: 100%;
    background: linear-gradient(90deg, #4caf50, #45a049);
    border-radius: 4px;
    transition: width 0.3s ease;
}

.performance-text {
    font-size: 12px;
    font-weight: bold;
    color: #666;
    min-width: 40px;
}

/* گزارش مقایسه‌ای */
.vsbbm-comparison-report {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.comparison-header h3 {
    margin: 0 0 20px 0;
    color: #333;
}

.comparison-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.comparison-card {
    text-align: center;
    padding: 20px;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
}

.comparison-card h4 {
    margin: 0 0 15px 0;
    color: #666;
    font-size: 14px;
}

.comparison-value {
    font-size: 24px;
    font-weight: bold;
    margin-bottom: 5px;
}

.comparison-value.positive {
    color: #4caf50;
}

.comparison-value.negative {
    color: #f44336;
}

.comparison-value.neutral {
    color: #ff9800;
}

.comparison-card small {
    color: #999;
    font-size: 12px;
}

/* وقتی داده‌ای وجود ندارد */
.no-data {
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

/* رسپانسیو */
@media (max-width: 768px) {
    .vsbbm-report-summary {
        grid-template-columns: 1fr;
    }
    
    .summary-card {
        flex-direction: column;
        text-align: center;
    }
    
    .comparison-cards {
        grid-template-columns: 1fr;
    }
    
    .table-header {
        flex-direction: column;
        gap: 15px;
        align-items: stretch;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // نمودار گزارش
    const ctx = document.getElementById('reportChart').getContext('2d');
    
    // داده‌های نمودار بر اساس نوع گزارش
    const chartData = {
        labels: [
            <?php 
            if ($report_type === 'daily') {
                foreach ($report_data as $report) {
                    echo "'" . esc_js($report['date']) . "',";
                }
            } elseif ($report_type === 'weekly') {
                foreach ($report_data as $report) {
                    echo "'" . esc_js($report['week']) . "',";
                }
            } else {
                foreach ($report_data as $report) {
                    echo "'" . esc_js($report['month']) . "',";
                }
            }
            ?>
        ],
        datasets: [{
            label: 'تعداد رزروها',
            data: [
                <?php foreach ($report_data as $report) {
                    echo $report['bookings'] . ',';
                } ?>
            ],
            borderColor: '#4caf50',
            backgroundColor: 'rgba(76, 175, 80, 0.1)',
            tension: 0.3,
            fill: true
        }, {
            label: 'درآمد (هزار ریال)',
            data: [
                <?php foreach ($report_data as $report) {
                    echo ($report['revenue'] / 1000) . ',';
                } ?>
            ],
            borderColor: '#2196f3',
            backgroundColor: 'rgba(33, 150, 243, 0.1)',
            tension: 0.3,
            fill: true
        }]
    };
    
    new Chart(ctx, {
        type: 'line',
        data: chartData,
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'top',
                    rtl: true
                },
                title: {
                    display: true,
                    text: 'گزارش عملکرد رزروها'
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
    
    // خروجی Excel
    $('#export-report').on('click', function() {
        const $button = $(this);
        const originalText = $button.html();
        
        $button.html('<span class="dashicons dashicons-update spin"></span> در حال آماده‌سازی...').prop('disabled', true);
        
        // ایجاد فرم موقت برای ارسال داده
        const $form = $('<form>').attr({
            method: 'POST',
            action: vsbbm_admin.ajax_url
        }).append(
            $('<input>').attr({type: 'hidden', name: 'action', value: 'vsbbm_export_bookings'}),
            $('<input>').attr({type: 'hidden', name: 'nonce', value: vsbbm_admin.nonce}),
            $('<input>').attr({type: 'hidden', name: 'report_type', value: '<?php echo $report_type; ?>'})
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