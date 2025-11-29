/**
 * VSBBM Admin Script
 * Handles the Visual Seat Editor in WooCommerce Product Edit Page.
 *
 * @package VSBBM
 * @version 2.0.1
 */

(function($) {
    'use strict';

    const VSBBM_Admin_Seat_Editor = {

        // State Management
        state: {
            rows: 10,
            cols: 2,
            type: '2x2', 
            seats: {},
            isDrawing: false,
            drawMode: 'seat'
        },

        // DOM Elements
        elements: {
            wrapper: '#vsbbm-seat-manager',
            editor: '.vsbbm-seat-layout-editor',
            grid: '#vsbbm-seat-grid',
            inputHidden: '#vsbbm_seat_numbers_input',
            inputRows: '#_vsbbm_bus_rows',
            inputCols: '#_vsbbm_bus_columns',
            inputType: '#_vsbbm_bus_type',
            currentSeatsDisplay: '#vsbbm-current-seats'
        },

        /**
         * Initialize
         */
        init: function() {
            if ($(this.elements.wrapper).length === 0) {
                return;
            }

            this.cacheElements();
            this.addToolbar();
            this.loadInitialData();
            this.renderGrid(); // اولین رندر
            this.bindEvents();
            
            // بازیابی صندلی‌های ذخیره شده
            this.restoreSavedSeats();

            console.log('VSBBM Admin Editor Initialized');
        },

        /**
         * Cache jQuery objects
         */
        cacheElements: function() {
            this.$wrapper = $(this.elements.wrapper);
            this.$editor = $(this.elements.editor);
            this.$grid = $(this.elements.grid);
            this.$inputHidden = $(this.elements.inputHidden);
            this.$inputRows = $(this.elements.inputRows);
            this.$inputCols = $(this.elements.inputCols);
            this.$inputType = $(this.elements.inputType);
            this.$display = $(this.elements.currentSeatsDisplay);
        },

        /**
         * Create Toolbar Buttons
         */
        addToolbar: function() {
            // جلوگیری از ایجاد تکراری
            if ($('.vsbbm-toolbar').length > 0) return;

            const toolbar = `
                <div class="vsbbm-toolbar" style="margin-bottom: 15px; display: flex; gap: 10px; align-items: center; background: #f0f0f1; padding: 10px; border-radius: 4px;">
                    <button type="button" class="button button-secondary" id="vsbbm-btn-refresh">🔄 بازسازی شبکه</button>
                    <button type="button" class="button button-secondary" id="vsbbm-btn-autonumber">1️⃣ شماره‌گذاری خودکار</button>
                    <button type="button" class="button button-secondary" id="vsbbm-btn-clear" style="color: #a00;">🗑️ پاکسازی همه</button>
                    <span style="margin-right: auto; font-size: 12px; color: #666;">کلیک کنید یا بکشید (Drag) تا صندلی‌ها فعال شوند.</span>
                </div>
            `;
            this.$editor.before(toolbar);
        },

        /**
         * Load data from DOM/DB
         */
        loadInitialData: function() {
            // استفاده از مقادیر پیش‌فرض اگر ورودی‌ها خالی باشند
            let rows = parseInt(this.$editor.data('rows'));
            let cols = parseInt(this.$editor.data('cols'));

            if (isNaN(rows) || rows <= 0) rows = 10;
            if (isNaN(cols) || cols <= 0) cols = 2;

            this.state.rows = rows;
            this.state.cols = cols;
            this.state.type = this.$editor.data('type') || '2x2';
            
            console.log(`Loaded Dimensions: ${this.state.rows}x${this.state.cols}`);
        },

        /**
         * Render the Grid System
         */
        renderGrid: function() {
            this.$grid.empty();
            
            const sideCols = this.state.cols;
            const totalCols = (sideCols * 2) + 1; // +1 برای راهرو

            // تنظیم استایل گرید
            this.$grid.css({
                'display': 'grid',
                'grid-template-columns': `repeat(${totalCols}, 40px)`,
                'gap': '5px',
                'justify-content': 'center',
                'margin': '0 auto'
            });

            for (let r = 1; r <= this.state.rows; r++) {
                for (let c = 1; c <= totalCols; c++) {
                    const $cell = $('<div class="vsbbm-admin-cell"></div>');
                    
                    // تشخیص راهرو (ستون وسط)
                    const isAisle = (c === sideCols + 1);
                    
                    $cell.attr('data-row', r);
                    $cell.attr('data-col', c);
                    
                    if (isAisle) {
                        $cell.addClass('aisle');
                    } else {
                        $cell.addClass('seat-placeholder');
                        // برای تست: اگر می‌خواهید خانه‌ها پیش‌فرض مشخص باشند، border اضافه کنید
                    }

                    this.$grid.append($cell);
                }
            }
        },

        /**
         * بازیابی وضعیت صندلی‌های ذخیره شده
         */
        restoreSavedSeats: function() {
            const rawData = this.$inputHidden.val();
            if (!rawData) return;

            try {
                // فرمت داده: {"1":{"label":"1",...}, "2":{...}}
                // ما باید بر اساس تعداد صندلی‌های ذخیره شده، گرید را پر کنیم.
                // چون در فاز ۱ مختصات ذخیره نکردیم، به ترتیب پر می‌کنیم.
                
                const savedSeats = JSON.parse(rawData);
                const seatKeys = Object.keys(savedSeats);
                
                if (seatKeys.length > 0) {
                    const $cells = this.$grid.find('.seat-placeholder');
                    
                    // پر کردن سلول‌ها به تعداد صندلی‌های ذخیره شده
                    $cells.each(function(index) {
                        if (index < seatKeys.length) {
                            const seatLabel = seatKeys[index];
                            $(this).addClass('active').text(seatLabel);
                        }
                    });
                    
                    this.syncInput();
                }
            } catch (e) {
                console.error('Error parsing saved seats:', e);
            }
        },

        /**
         * Bind Events
         */
        bindEvents: function() {
            const self = this;

            // Mouse Events for Painting
            this.$grid.off('mousedown').on('mousedown', '.seat-placeholder', function(e) {
                e.preventDefault();
                self.state.isDrawing = true;
                self.state.drawMode = $(this).hasClass('active') ? 'aisle' : 'seat';
                self.toggleCell($(this));
            });

            this.$grid.off('mouseover').on('mouseover', '.seat-placeholder', function() {
                if (self.state.isDrawing) {
                    self.toggleCell($(this));
                }
            });

            $(document).off('mouseup').on('mouseup', function() {
                if (self.state.isDrawing) {
                    self.state.isDrawing = false;
                    self.syncInput();
                }
            });

            // Refresh Button
            $('#vsbbm-btn-refresh').off('click').on('click', function(e) {
                e.preventDefault();
                
                // خواندن مقادیر جدید از اینپوت‌ها
                let newRows = parseInt(self.$inputRows.val());
                let newCols = parseInt(self.$inputCols.val());

                if (isNaN(newRows) || newRows < 1) newRows = 10;
                if (isNaN(newCols) || newCols < 1) newCols = 2;

                if(confirm(`آیا مطمئن هستید؟ شبکه با ابعاد ${newRows} ردیف و ${newCols} ستون بازسازی می‌شود.`)) {
                    self.state.rows = newRows;
                    self.state.cols = newCols;
                    self.renderGrid();
                    self.syncInput(); // پاک کردن داده‌های قبلی
                }
            });

            // Auto Number Button
            $('#vsbbm-btn-autonumber').off('click').on('click', function(e) {
                e.preventDefault();
                self.autoNumberSeats();
            });

            // Clear Button
            $('#vsbbm-btn-clear').off('click').on('click', function(e) {
                e.preventDefault();
                if(confirm('همه صندلی‌ها حذف شوند؟')) {
                    self.$grid.find('.active').removeClass('active').text('');
                    self.syncInput();
                }
            });
        },

        /**
         * Toggle Cell State
         */
        toggleCell: function($cell) {
            if (this.state.drawMode === 'seat') {
                if (!$cell.hasClass('active')) {
                    $cell.addClass('active');
                }
            } else {
                if ($cell.hasClass('active')) {
                    $cell.removeClass('active').text('');
                }
            }
        },

        /**
         * Auto Number Logic
         */
        autoNumberSeats: function() {
            let counter = 1;
            const $cells = this.$grid.find('.active');
            
            if ($cells.length === 0) {
                alert('هیچ صندلی فعالی وجود ندارد. لطفاً ابتدا روی خانه‌ها کلیک کنید.');
                return;
            }

            $cells.each(function() {
                $(this).text(counter);
                counter++;
            });
            
            this.syncInput();
        },

        /**
         * Sync to Input
         */
        syncInput: function() {
            const seatsData = {};
            const seatsList = [];

            this.$grid.find('.active').each(function() {
                let label = $(this).text();
                // اگر لیبل نداشت (تازه کلیک شده)، موقتاً شماره نمی‌دهیم تا دکمه شماره‌گذاری زده شود
                // اما برای اینکه در دیتابیس به عنوان "صندلی" شناخته شود، فعلاً ذخیره‌ش نمی‌کنیم
                // مگر اینکه کاربر دکمه "شماره‌گذاری" را بزند.
                
                // نکته UX: بیایید اگر خالی بود، به طور موقت یک مقدار بدهیم یا نادیده بگیریم
                if (label) {
                    seatsData[label] = {
                        label: label,
                        price: 0,
                        type: 'default'
                    };
                    seatsList.push(label);
                }
            });

            this.$inputHidden.val(JSON.stringify(seatsData));
            this.$display.text(seatsList.join(', '));
        }
    };

    $(document).ready(function() {
        VSBBM_Admin_Seat_Editor.init();
    });

})(jQuery);