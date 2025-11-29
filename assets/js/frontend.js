/**
 * VSBBM Frontend Script - Enhanced
 * @version 2.1.0
 */
(function($) {
    'use strict';

    const VSBBM_Frontend = {
        settings: vsbbm_ajax_object,
        state: {
            selectedSeats: [],
            departureTimestamp: '',
            seatData: {},
            isLoading: false
        },
        elements: {
            container: '#vsbbm-seat-selector',
            dateInput: '#vsbbm_departure_date',
            timestampInput: '#vsbbm_departure_timestamp',
            mapContainer: '#vsbbm-seat-map',
            passengerContainer: '.vsbbm-passenger-data-form',
            passengerFields: '#vsbbm-passenger-fields',
            totalPrice: '#vsbbm-total-price',
            countDisplay: '#vsbbm-selected-seats-count',
            addToCartBtn: '#vsbbm-add-to-cart-button',
            loader: '.vsbbm-loading-overlay'
        },

        init: function() {
            this.cacheElements();
            this.bindEvents();
            this.initDatepicker();
            
            if (this.settings.schedule_settings.enable_schedule !== 'yes') {
                this.loadSeatMap(0);
            }
        },

        cacheElements: function() {
            this.$container = $(this.elements.container);
            this.$dateInput = $(this.elements.dateInput);
            this.$timestampInput = $(this.elements.timestampInput);
            this.$map = $(this.elements.mapContainer);
            this.$passengerContainer = $(this.elements.passengerContainer);
            this.$passengerFields = $(this.elements.passengerFields);
            this.$totalPrice = $(this.elements.totalPrice);
            this.$count = $(this.elements.countDisplay);
            this.$btn = $(this.elements.addToCartBtn);
            this.$loader = $(this.elements.loader);
        },

        bindEvents: function() {
            this.$container.on('click', '.vsbbm-seat.available', (e) => this.handleSeatClick(e));
            this.$btn.on('click', (e) => this.handleAddToCart(e));
        },

        initDatepicker: function() {
            if (!this.$dateInput.length) return;
            const schedule = this.settings.schedule_settings;
            
            this.$dateInput.datepicker({
                dateFormat: 'yy-mm-dd',
                minDate: parseInt(schedule.min_days_advance) || 0,
                maxDate: parseInt(schedule.max_days_advance) || 365,
                firstDay: 6,
                isRTL: true,
                beforeShowDay: (date) => this.checkAvailableDates(date),
                onSelect: (dateText) => {
                    const selectedDate = new Date(dateText + 'T' + schedule.departure_time + ':00');
                    const timestamp = Math.floor(selectedDate.getTime() / 1000);
                    this.state.departureTimestamp = timestamp;
                    this.$timestampInput.val(timestamp);
                    this.loadSeatMap(timestamp);
                }
            });
        },

        checkAvailableDates: function(date) {
            const schedule = this.settings.schedule_settings;
            const type = schedule.schedule_type;
            const dateString = $.datepicker.formatDate('yy-mm-dd', date);
            const daysMap = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
            const dayName = daysMap[date.getDay()];

            if (type === 'specific_dates') {
                const allowedDates = schedule.specific_dates || [];
                return [allowedDates.includes(dateString)];
            } 
            if (type === 'weekdays') {
                const allowedDays = schedule.allowed_weekdays || [];
                return [allowedDays.includes(dayName)];
            }
            return [true];
        },

        loadSeatMap: function(timestamp) {
            this.setLoading(true);
            this.resetSelection();

            $.ajax({
                url: this.settings.ajax_url,
                type: 'POST',
                data: {
                    action: 'vsbbm_get_reserved_seats',
                    product_id: this.settings.product_id,
                    departure_timestamp: timestamp,
                    nonce: this.settings.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.renderSeatMap(response.data);
                    } else {
                        this.$map.html(`<p class="vsbbm-error">${response.data.message}</p>`);
                    }
                },
                complete: () => this.setLoading(false)
            });
        },

        renderSeatMap: function(data) {
            const reservedSeats = data.reserved || [];
            const allSeats = data.all_seats || {};
            this.state.seatData = allSeats;

            const cols = parseInt(this.$container.data('cols'));
            let html = '<div class="vsbbm-grid-wrapper" style="display: grid; grid-template-columns: repeat(' + (cols * 2 + 1) + ', 1fr); gap: 10px;">';
            
            const seatNumbers = Object.keys(allSeats).sort((a, b) => parseInt(a) - parseInt(b));
            
            if (seatNumbers.length === 0) {
                this.$map.html('<p>چیدمان صندلی تعریف نشده است.</p>');
                return;
            }

            seatNumbers.forEach(seatNum => {
                const seatInfo = allSeats[seatNum];
                const isReserved = reservedSeats.includes(seatNum) || reservedSeats.includes(parseInt(seatNum));
                const statusClass = isReserved ? 'reserved' : 'available';
                const seatLabel = seatInfo.label || seatNum;
                const price = seatInfo.price || 0;
                
                // Tooltip info
                const tooltip = isReserved ? 'رزرو شده' : `صندلی ${seatLabel} - قیمت: ${price > 0 ? price : 'پایه'}`;

                html += `
                    <div class="vsbbm-seat ${statusClass}" 
                         data-seat-number="${seatNum}" 
                         title="${tooltip}">
                        ${seatLabel}
                        ${price > 0 ? '<span class="seat-price-badge"></span>' : ''} 
                    </div>
                `;
            });

            html += '</div>';
            this.$map.html(html);
        },

        handleSeatClick: function(e) {
            const $seat = $(e.currentTarget);
            const seatNum = $seat.data('seat-number');

            if ($seat.hasClass('selected')) {
                $seat.removeClass('selected');
                this.state.selectedSeats = this.state.selectedSeats.filter(s => s !== seatNum);
                this.removePassengerField(seatNum);
            } else {
                if (this.state.selectedSeats.length >= 5) {
                    alert('حداکثر ۵ صندلی می‌توانید انتخاب کنید.');
                    return;
                }
                $seat.addClass('selected');
                this.state.selectedSeats.push(seatNum);
                this.addPassengerField(seatNum);
            }
            this.updateSummary();
        },

        addPassengerField: function(seatNum) {
            this.$passengerContainer.show();
            // لودینگ کوچک برای فرم
            if(this.$passengerFields.is(':empty')) {
                this.$passengerFields.html('<p class="loading-text">در حال بارگذاری فرم...</p>');
            }

            // کش کردن فیلدها در متغیر سراسری برای جلوگیری از درخواست تکراری
            if (this.state.formFieldsCache) {
                this.renderPassengerFormHTML(seatNum, this.state.formFieldsCache);
            } else {
                $.ajax({
                    url: this.settings.ajax_url,
                    data: { action: 'vsbbm_get_passenger_fields' },
                    success: (response) => {
                        if (response.success) {
                            this.state.formFieldsCache = response.data; // کش کردن
                            this.$passengerFields.find('.loading-text').remove();
                            this.renderPassengerFormHTML(seatNum, response.data);
                        }
                    }
                });
            }
        },

        // تابع جدید برای رندر HTML فرم
        renderPassengerFormHTML: function(seatNum, fields) {
            let html = `<div class="vsbbm-passenger-row" data-seat="${seatNum}">`;
            html += `<div class="row-header"><h4>مسافر صندلی ${seatNum}</h4><button type="button" class="remove-seat-btn" onclick="jQuery('.vsbbm-seat[data-seat-number=${seatNum}]').click()">×</button></div>`;
            html += `<div class="row-body">`;
            
            fields.forEach(field => {
                const inputName = `passengers[${seatNum}][${field.label}]`; // استفاده از label به عنوان کلید
                const required = field.required ? 'required' : '';
                const validationAttr = field.validation ? `data-validate="${field.validation}"` : '';
                
                html += `<div class="form-item">`;
                html += `<label>${field.label} ${field.required ? '*' : ''}</label>`;
                
                if (field.type === 'select' && field.options) {
                    html += `<select name="${inputName}" class="vsbbm-input" ${required}>`;
                    // اگر آپشن‌ها آبجکت باشند (کلید => مقدار)
                    if (typeof field.options === 'object' && !Array.isArray(field.options)) {
                         for (const [key, value] of Object.entries(field.options)) {
                             html += `<option value="${value}">${value}</option>`; // مقدار را فارسی ذخیره می‌کنیم
                         }
                    } else {
                        // اگر آرایه باشد
                        field.options.forEach(opt => {
                            html += `<option value="${opt}">${opt}</option>`;
                        });
                    }
                    html += `</select>`;
                } else {
                    html += `<input type="${field.type}" name="${inputName}" class="vsbbm-input" placeholder="${field.placeholder}" ${required} ${validationAttr}>`;
                }
                html += `</div>`;
            });
            
            html += `<input type="hidden" name="passengers[${seatNum}][seat_number]" value="${seatNum}">`;
            html += `</div></div>`;

            this.$passengerFields.append(html);
        },

        removePassengerField: function(seatNum) {
            this.$passengerFields.find(`.vsbbm-passenger-row[data-seat="${seatNum}"]`).remove();
            if (this.state.selectedSeats.length === 0) {
                this.$passengerContainer.hide();
            }
        },

        updateSummary: function() {
            const count = this.state.selectedSeats.length;
            this.$count.text(count);
            let total = 0;
            const basePrice = parseFloat(this.$container.data('price')) || 0;

            this.state.selectedSeats.forEach(seatNum => {
                const seatPrice = this.state.seatData[seatNum]?.price || basePrice;
                total += parseFloat(seatPrice);
            });

            this.$totalPrice.text(total.toLocaleString() + ' ' + this.settings.currency_symbol);
            this.$btn.prop('disabled', count === 0);
        },

        // تابع اعتبارسنجی کد ملی ایران
        validateNationalCode: function(code) {
            if (code.length !== 10 || isNaN(code)) return false;
            var code = code.split(''); 
            var p = 10;
            var sum = 0;
            
            for (var i = 0; i < 9; i++) {
                sum += parseInt(code[i]) * p;
                p--;
            }
            
            var remainder = sum % 11;
            var control = parseInt(code[9]);
            
            if (remainder < 2 && control === remainder) return true;
            if (remainder >= 2 && control === 11 - remainder) return true;
            
            return false;
        },

        handleAddToCart: function(e) {
            e.preventDefault();
            if (this.state.selectedSeats.length === 0) return;

            const passengers = [];
            let isValid = true;
            const self = this;

            this.$passengerFields.find('.vsbbm-passenger-row').each(function() {
                const $row = $(this);
                const seatNum = $row.data('seat');
                const passenger = { seat_number: seatNum };

                $row.find('input, select').each(function() {
                    const $input = $(this);
                    const name = $input.attr('name');
                    // Regex برای استخراج نام فیلد بین براکت دوم
                    const match = name.match(/\[([^\]]+)\]$/); 
                    
                    if (match) {
                        const key = match[1]; // نام فیلد (مثلا 'کد ملی')
                        const val = $input.val();
                        
                        // بررسی اجباری بودن
                        if ($input.prop('required') && !val) {
                            isValid = false;
                            $input.addClass('vsbbm-invalid');
                            return; // ادامه loop
                        } else {
                            $input.removeClass('vsbbm-invalid');
                        }

                        // بررسی کد ملی
                        if ($input.data('validate') === 'national_code') {
                            if (!self.validateNationalCode(val)) {
                                isValid = false;
                                alert(`کد ملی مسافر صندلی ${seatNum} نامعتبر است.`);
                                $input.addClass('vsbbm-invalid');
                                return false;
                            }
                        }

                        passenger[key] = val;
                    }
                });

                passengers.push(passenger);
            });

            if (!isValid) {
                alert('لطفاً اطلاعات را به درستی تکمیل کنید.');
                return;
            }

            this.setLoading(true, 'در حال پردازش...');
            this.$btn.prop('disabled', true);

            $.ajax({
                url: this.settings.ajax_url,
                type: 'POST',
                data: {
                    action: 'vsbbm_add_to_cart',
                    product_id: this.settings.product_id,
                    vsbbm_departure_timestamp: this.state.departureTimestamp, // توجه: نام پارامتر
                    selected_seats: JSON.stringify(this.state.selectedSeats),
                    passengers_data: JSON.stringify(passengers),
                    nonce: this.settings.nonce
                },
                success: (response) => {
                    if (response.success) {
                        window.location.href = response.data.checkout_url;
                    } else {
                        alert(response.data.message);
                        if (response.data.code === 'seat_not_available') {
                            this.loadSeatMap(this.state.departureTimestamp);
                        }
                        this.$btn.prop('disabled', false);
                    }
                },
                error: () => { alert('خطا در سرور'); this.$btn.prop('disabled', false); },
                complete: () => this.setLoading(false)
            });
        },

        resetSelection: function() {
            this.state.selectedSeats = [];
            this.$passengerFields.empty();
            this.$passengerContainer.hide();
            this.updateSummary();
        },

        setLoading: function(show, text) {
            if (show) {
                this.$loader.addClass('active').find('p').text(text || 'لطفاً صبر کنید...');
            } else {
                this.$loader.removeClass('active');
            }
        }
    };

    $(document).ready(function() { VSBBM_Frontend.init(); });

})(jQuery);