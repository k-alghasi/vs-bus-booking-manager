Changelog
All notable changes to the "VS Bus Booking Manager" project will be documented in this file.

[2.1.0] - 2025-01
Major Update — Architecture & Seat Layout Improvements

🔥 New Features
چیدمان واقعی اتوبوس: نمایش صندلی‌ها با راهروی وسط (استاندارد ۲×۲، VIP ۱×۲، لوکس ۱×۱)
حفظ position صندلی‌ها: ذخیره و بازیابی جایگاه دقیق صندلی‌ها (row, col, side)
نوار جلوی اتوبوس: نمایش حرفه‌ای نوع اتوبوس و تعداد صندلی
راهنمای UX در Visual Seat Editor: راهنمای غیرفعال‌سازی صندلی‌ها
🏗️ Architecture
معماری Free/Pro: تفکیک کامل قابلیت‌های رایگان و Pro
کلاس Settings مرکزی: VSBBM_Settings با کش درون‌حافظه‌ای
سرویس رزرو مرکزی: VSBBM_Reservation_Service به‌عنوان single source of truth
افزونه Pro مستقل: vs-bus-booking-manager-pro با Bootstrap و Helper
Gating قابلیت‌ها: منوهای Pro فقط با لایسنس فعال ظاهر می‌شوند
🐛 Bug Fixes (14 items)
P0-1: جلوگیری از ثبت دوباره هوک‌های Booking_Handler (ضرب قیمت)
P0-2: غیرفعال‌سازی ۴ endpoint ناقص REST API
P0-3: اتصال رزروها به سفارش در مسیر سایت (link_reservations_by_keys)
P0-4: رزروهای منقضی‌شده صندلی را بلوکه نمی‌کنند
P0-5: حل قطعی overbooking با DB unique constraint (active_key)
P0-6: محاسبه قیمت اختصاصی صندلی در سبد (forward-compatible)
P0-7: صفحات All Bookings و Reservations با placeholder مفید
P0-8: کوئری درآمد داشبورد فقط سفارشات اتوبوسی را می‌شمارد
P0-9: اصلاح کوئری cache manager (status='active' → reserved/confirmed)
NEW: فیکس nonce فرم مسافر
NEW: فیکس PHP 8+ deprecation در activation
NEW: Self-healing جداول دیتابیس (ensure_table_exists)
NEW: هشدار لایسنس فقط روی صفحات افزونه
NEW: پشتیبانی از status on-hold (پرداخت با چک)
🔧 Technical
حذف register_activation_hook از کلاس‌ها (PHP 8+ deprecation)
اصلاح SQL جدول tickets (UNIQUE KEY مجزا برای dbDelta)
پاکسازی کش در cancel_reservations_by_keys
Hook vsbbm_init برای راه‌اندازی Pro
Hook vsbbm_booking_created برای توسعه‌دهندگان
🗑️ Removed
حذف فایل‌های مرده _old (bookings_old, reservations_old, reports_old, settings_old, seat-selector_old)
حذف فایل‌های Pro از Free (SMS, REST API, Scanner) — به Pro منتقل شدند
[2.0.0] - 2025-11-29
Major Release - The "Intelligence" Update

🔥 New Features
Time-Based Architecture: Added departure_timestamp to database to support recurring trips
Visual Seat Editor: Brand new Admin UI to design seat layouts using a click-and-paint grid system
Ticket Scanner: Built-in Web QR Code Scanner for validating and checking in tickets
PDF Tickets: Integrated TCPDF to generate real PDF tickets with QR codes
Dynamic Fields: New setting to create custom passenger fields
Elementor Widget: Added [vsbbm_booking_form] widget for Elementor
Blacklist Target: Ability to select which passenger field triggers the blacklist check
⚡ Improvements
Sticky summary bar for mobile devices
Smart Datepicker that disables non-scheduled days
Dynamic passenger form generation based on selected seats
Dashboard with live Chart.js graphs
Self-Healing logic to fix missing tickets
🔧 Technical
Complete rewrite to Singleton pattern and MVC architecture
Database optimization with new indexes for departure_timestamp
Composer vendor folder support for TCPDF and QR Code
REST API updated to support timestamp-based availability checks
Strict Nonce verification on all AJAX endpoints
Directory protection (index.php) to all folders
[1.9.1] - 2025-01-17
Added
Advanced caching system with transient storage
Gzip compression for AJAX responses
Performance
Reduced seat loading time by 70% using smart caching
Optimized database queries for high-traffic sites
[1.9.0] - 2025-01-XX
Added
Basic seat reservation database structure
Email notification system with HTML templates
Admin reporting interface
[1.0.0] - Initial Release
Basic WooCommerce integration
Simple seat selection