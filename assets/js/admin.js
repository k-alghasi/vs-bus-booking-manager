/**
 * VSBBM Admin Script
 * Handles Seat Editor & Dashboard Charts
 * @version 2.1.0
 */

(function($) {
    'use strict';

    // ==========================================
    // 1. VISUAL SEAT EDITOR
    // ==========================================
    const VSBBM_Admin_Seat_Editor = {
        state: { rows: 10, cols: 2, type: '2x2', seats: {}, isDrawing: false, drawMode: 'seat' },
        elements: {
            wrapper: '#vsbbm-seat-manager',
            editor: '.vsbbm-seat-layout-editor',
            grid: '#vsbbm-seat-grid',
            inputHidden: '#vsbbm_seat_numbers_input',
            inputRows: '#_vsbbm_bus_rows',
            inputCols: '#_vsbbm_bus_columns',
            display: '#vsbbm-current-seats'
        },

        init: function() {
            if ($(this.elements.wrapper).length === 0) return;
            this.cacheElements();
            this.addToolbar();
            this.loadInitialData();
            this.renderGrid();
            this.bindEvents();
            this.restoreSavedSeats();
        },

        cacheElements: function() {
            this.$wrapper = $(this.elements.wrapper);
            this.$editor = $(this.elements.editor);
            this.$grid = $(this.elements.grid);
            this.$inputHidden = $(this.elements.inputHidden);
            this.$inputRows = $(this.elements.inputRows);
            this.$inputCols = $(this.elements.inputCols);
            this.$display = $(this.elements.display);
        },

        addToolbar: function() {
            if ($('.vsbbm-toolbar').length > 0) return;
            const toolbar = `
                <div class="vsbbm-toolbar" style="margin-bottom: 15px; display: flex; gap: 10px; align-items: center; background: #f0f0f1; padding: 10px; border-radius: 4px;">
                    <button type="button" class="button button-secondary" id="vsbbm-btn-refresh">🔄 بازسازی شبکه</button>
                    <button type="button" class="button button-secondary" id="vsbbm-btn-autonumber">1️⃣ شماره‌گذاری خودکار</button>
                    <button type="button" class="button button-secondary" id="vsbbm-btn-clear" style="color: #a00;">🗑️ پاکسازی</button>
                    <span style="margin-right: auto; font-size: 12px; color: #666;">کلیک یا درگ کنید.</span>
                </div>
                <div class="vsbbm-seat-editor-help" style="margin-bottom: 12px; padding: 8px 12px; background: #fff8e5; border-left: 4px solid #ffb900; font-size: 12px; color: #555; border-radius: 3px;">
                    💡 <strong>راهنما:</strong> صندلی‌های آبی فعال هستند. برای غیرفعال کردن صندلی (مثلاً جای درب عقب یا راننده)، روی آن کلیک یا درگ کنید تا رنگ آن محو شود.
                </div>`;
            this.$editor.before(toolbar);
        },

        loadInitialData: function() {
            let rows = parseInt(this.$inputRows.val());
            let cols = parseInt(this.$inputCols.val());
            let type = $('#_vsbbm_bus_type').val() || '2x2';
            if (isNaN(rows) || rows <= 0) rows = 10;
            if (isNaN(cols) || cols <= 0) cols = 2;
            this.state.rows = rows;
            this.state.cols = cols;
            this.state.type = type;
        },

        // P-layout-fix: محاسبه تعداد ستون‌های چپ و راست بر اساس bus_type
        // در RTL، leftCols سمت راست قرار می‌گیره.
        getLayoutColumns: function() {
            const type = this.state.type;
            let leftCols, rightCols;
            if (type === '1x2') {
                // VIP: دوتایی سمت راست، تکی سمت چپ
                leftCols = 2;  // سمت راست در RTL
                rightCols = 1; // سمت چپ در RTL
            } else if (type === '1x1') {
                leftCols = 1;
                rightCols = 1;
            } else {
                leftCols = this.state.cols;
                rightCols = this.state.cols;
            }
            return { leftCols, rightCols };
        },

        renderGrid: function() {
            this.$grid.empty();
            const { leftCols, rightCols } = this.getLayoutColumns();
            const totalCols = leftCols + 1 + rightCols; // +1 for aisle
            this.$grid.css({ 'display': 'grid', 'grid-template-columns': `repeat(${leftCols}, 40px) 20px repeat(${rightCols}, 40px)`, 'gap': '5px', 'justify-content': 'center' });

            for (let r = 1; r <= this.state.rows; r++) {
                // ستون‌های چپ
                for (let c = 1; c <= leftCols; c++) {
                    const $cell = $('<div class="vsbbm-admin-cell"></div>');
                    $cell.attr('data-row', r).attr('data-col', c).attr('data-side', 'left');
                    $cell.addClass('seat-placeholder');
                    this.$grid.append($cell);
                }
                // راهرو
                const $aisle = $('<div class="vsbbm-admin-cell aisle"></div>');
                $aisle.attr('data-row', r).attr('data-col', 'aisle');
                this.$grid.append($aisle);
                // ستون‌های راست
                for (let c = 1; c <= rightCols; c++) {
                    const $cell = $('<div class="vsbbm-admin-cell"></div>');
                    $cell.attr('data-row', r).attr('data-col', c).attr('data-side', 'right');
                    $cell.addClass('seat-placeholder');
                    this.$grid.append($cell);
                }
            }
        },

        restoreSavedSeats: function() {
            const rawData = this.$inputHidden.val();
            if (!rawData) return;
            try {
                const savedSeats = JSON.parse(rawData);
                const seatKeys = Object.keys(savedSeats);
                if (seatKeys.length > 0) {
                    // پاک کردن همه active فعلی
                    this.$grid.find('.active').removeClass('active').text('');

                    const self = this;
                    seatKeys.forEach(function(key) {
                        const seat = savedSeats[key];
                        // P-layout-fix: بازسازی بر اساس position (row, col, side)
                        if (seat.row && seat.col) {
                            const $cell = self.$grid.find(
                                '.seat-placeholder[data-row="' + seat.row + '"][data-col="' + seat.col + '"][data-side="' + seat.side + '"]'
                            );
                            if ($cell.length) {
                                $cell.addClass('active').text(seat.label || key);
                            }
                        }
                    });

                    this.syncInput();
                }
            } catch (e) {}
        },

        bindEvents: function() {
            const self = this;
            this.$grid.on('mousedown', '.seat-placeholder', function(e) {
                e.preventDefault();
                self.state.isDrawing = true;
                self.state.drawMode = $(this).hasClass('active') ? 'aisle' : 'seat';
                self.toggleCell($(this));
            });
            this.$grid.on('mouseover', '.seat-placeholder', function() {
                if (self.state.isDrawing) self.toggleCell($(this));
            });
            $(document).on('mouseup', function() {
                if (self.state.isDrawing) { self.state.isDrawing = false; self.syncInput(); }
            });
            $('#vsbbm-btn-refresh').on('click', function(e) {
                e.preventDefault();
                let newRows = parseInt(self.$inputRows.val()) || 10;
                let newCols = parseInt(self.$inputCols.val()) || 2;
                let newType = $('#_vsbbm_bus_type').val() || '2x2';
                if(confirm(`بازسازی شبکه با ابعاد ${newRows} ردیف و نوع ${newType}؟\n\n⚠️ توجه: صندلی‌های فعال قبلی حفظ می‌شوند.`)) {
                    self.state.rows = newRows; self.state.cols = newCols; self.state.type = newType;
                    self.renderGrid(); self.restoreSavedSeats(); self.syncInput();
                }
            });
            // P-layout-fix: وقتی bus_type تغییر کرد، گرید رو بازسازی کن
            $('#_vsbbm_bus_type').on('change', function() {
                self.state.type = $(this).val() || '2x2';
                self.renderGrid(); self.restoreSavedSeats(); self.syncInput();
            });
            // P-layout-fix: وقتی rows یا cols تغییر کرد (blur)، گرید رو بازسازی کن
            this.$inputRows.add(this.$inputCols).on('blur', function() {
                let newRows = parseInt(self.$inputRows.val()) || 10;
                let newCols = parseInt(self.$inputCols.val()) || 2;
                if (newRows !== self.state.rows || newCols !== self.state.cols) {
                    self.state.rows = newRows; self.state.cols = newCols;
                    self.renderGrid(); self.restoreSavedSeats(); self.syncInput();
                }
            });
            $('#vsbbm-btn-autonumber').on('click', function(e) { e.preventDefault(); self.autoNumberSeats(); });
            $('#vsbbm-btn-clear').on('click', function(e) {
                e.preventDefault(); if(confirm('پاکسازی همه؟')) { self.$grid.find('.active').removeClass('active').text(''); self.syncInput(); }
            });
        },

        toggleCell: function($cell) {
            // P-layout-fix: منطق toggle بهبود یافت
            // حالت ۱: drawMode = 'seat' (می‌خواهیم صندلی فعال کنیم)
            if (this.state.drawMode === 'seat') {
                if (!$cell.hasClass('active')) {
                    $cell.addClass('active');
                }
            }
            // حالت ۲: drawMode = 'aisle' (می‌خواهیم صندلی غیرفعال کنیم)
            else {
                if ($cell.hasClass('active')) {
                    $cell.removeClass('active').text('');
                }
            }
        },

        autoNumberSeats: function() {
            let counter = 1;
            const $cells = this.$grid.find('.active');
            if ($cells.length === 0) { alert('صندلی فعالی وجود ندارد.'); return; }
            $cells.each(function() { $(this).text(counter++); });
            this.syncInput();
        },

        syncInput: function() {
            const seatsData = {};
            const seatsList = [];
            this.$grid.find('.active').each(function() {
                let label = $(this).text();
                if (label) {
                    // P-layout-fix: ذخیره position (row, col, side) برای حفظ جایگاه
                    seatsData[label] = {
                        label: label,
                        price: 0,
                        type: 'default',
                        row: $(this).data('row') || 0,
                        col: $(this).data('col') || 0,
                        side: $(this).data('side') || ''
                    };
                    seatsList.push(label);
                }
            });
            this.$inputHidden.val(JSON.stringify(seatsData));
            this.$display.text(seatsList.join(', '));
        }
    };

    // ==========================================
    // 2. DASHBOARD CHARTS (Updated for Chart.js v4)
    // ==========================================
    const VSBBM_Dashboard = {
        init: function() {
            if (typeof vsbbm_admin !== 'undefined' && vsbbm_admin.chart_data) {
                // اطمینان از وجود المان‌ها قبل از رندر
                const revenueCanvas = document.getElementById('vsbbm-revenue-chart');
                const statusCanvas = document.getElementById('vsbbm-status-chart');

                if (revenueCanvas && typeof Chart !== 'undefined') {
                    this.renderRevenueChart(revenueCanvas, vsbbm_admin.chart_data.sales);
                }

                if (statusCanvas && typeof Chart !== 'undefined') {
                    this.renderStatusChart(statusCanvas, vsbbm_admin.chart_data.status);
                }
            }
        },

        renderRevenueChart: function(canvas, data) {
            new Chart(canvas, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: 'فروش (تومان)',
                        data: data.data,
                        borderColor: '#2271b1',
                        backgroundColor: 'rgba(34, 113, 177, 0.2)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.parsed.y.toLocaleString() + ' تومان';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        },

        renderStatusChart: function(canvas, data) {
            new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels: data.labels, // Active, Used, Expired
                    datasets: [{
                        data: data.data,
                        backgroundColor: [
                            '#4caf50', // Active (Green)
                            '#2196f3', // Used (Blue)
                            '#f44336'  // Expired (Red)
                        ],
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { font: { family: 'Tahoma' } }
                        }
                    }
                }
            });
        }
    };// Dashboard

    $(document).ready(function() {
        VSBBM_Admin_Seat_Editor.init();
        VSBBM_Dashboard.init();
    });

})(jQuery);