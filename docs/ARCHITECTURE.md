# docs/ARCHITECTURE.md — معماری VS Bus Booking Manager

> **هدف:** مستندسازی معماری فعلی و معماری هدف، مسئولیت کلاس‌ها، قراردادهای WooCommerce و API، و رابطه نسخه‌های Free و Pro.

**سند مادر:** `PROJECT_RECOVERY_PLAN.md`
**آخرین به‌روزرسانی:** ۲۰۲۵-۰۱

---

## ۱) معماری فعلی (نسخه ۲.۱.۰)

### ۱-۱) لایه‌بندی کلی

```
┌─────────────────────────────────────────────────────────────────┐
│  لایه ۱: Bootstrap (vs-bus-booking-manager.php)                │
│  - تعریف ثابت‌ها                                                │
│  - بارگذاری ترتیبی وابستگی‌ها                                  │
│  - ثبت هوک‌های فعال‌سازی                                        │
└─────────────────────────┬───────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────┐
│  لایه ۲: Settings (class-settings.php)                         │
│  - VSBBM_Settings (مرکز تنظیمات)                               │
│  - is_pro_active() — Gating اصلی Pro                           │
└─────────────────────────┬───────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────┐
│  لایه ۳: Models (دسترسی به داده)                               │
│  - VSBBM_Cache_Manager                                         │
│  - VSBBM_Blacklist                                             │
│  - VSBBM_Seat_Reservations (با active_key unique constraint)   │
│  - VSBBM_Ticket_Manager                                        │
└─────────────────────────┬───────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────┐
│  لایه ۴: Services (منطق کسب‌وکار)                              │
│  - VSBBM_Reservation_Service (single source of truth)          │
│    - create_booking()                                          │
│    - cancel_booking()                                          │
│    - get_seat_availability()                                   │
└─────────────────────────┬───────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────┐
│  لایه ۵: Controllers (هوک‌ها و تعامل با کاربر)                 │
│  - VSBBM_Seat_Manager (admin + frontend + AJAX)                │
│  - VSBBM_Booking_Handler (WooCommerce integration)             │
│  - VSBBM_Email_Notifications                                   │
│  - VSBBM_License_Manager (مدیریت لایسنس Pro)                   │
│  --- Pro Features (conditional loading) ---                    │
│  - VSBBM_SMS_Notifications (Pro)                               │
│  - VSBBM_Admin_Interface (مشترک، با gating منوها)              │
│  - VSBBM_REST_API (Pro)                                        │
└─────────────────────────┬───────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────┐
│  لایه ۶: Integrations                                          │
│  - VSBBM_Elementor_Integration                                 │
└─────────────────────────────────────────────────────────────────┘
```

### ۱-۲) جریان رزرو (Reservation Flow)

```
کاربر → انتخاب تاریخ → انتخاب صندلی → پر کردن فرم مسافر
     ↓
    Book Now (AJAX)
     ↓
    VSBBM_Seat_Manager::add_to_cart_ajax()
     ↓
    VSBBM_Reservation_Service::create_booking()
     ├── validate_booking_input()
     ├── check_blacklist()
     ├── reserve_seat() [atomic با active_key]
     └── rollback در صورت خطا
     ↓
    WC()->cart->add_to_cart() با cart_data
     ├── vsbbm_reservation_keys
     ├── vsbbm_departure_timestamp
     ├── vsbbm_seats
     └── vsbbm_passengers
     ↓
    Checkout
     ↓
    VSBBM_Booking_Handler::save_order_item_meta()
     ├── ذخیره _vsbbm_passengers
     ├── ذخیره _vsbbm_reservation_keys (P0-3)
     └── ذخیره _vsbbm_departure_timestamp
     ↓
    woocommerce_checkout_order_processed
     ↓
    VSBBM_Booking_Handler::link_reservations_to_order()
     ↓
    VSBBM_Seat_Reservations::link_reservations_by_keys()
     ├── UPDATE order_id WHERE id IN (...)
     └── clear_reservation_cache()
     ↓
    سفارش → processing/completed/on-hold
     ↓
    VSBBM_Seat_Reservations::handle_order_status_change()
     ├── link_orphan_reservations_for_order() (fallback)
     ├── status = 'confirmed'
     └── active_key = 'ACTIVE'
     ↓
    VSBBM_Ticket_Manager::generate_tickets_for_order()
     ├── تولید ticket_number (با random_bytes)
     ├── تولید QR data
     └── تولید PDF (Pro) یا HTML (Free)
```

---

## ۲) معماری هدف (Free/Pro)

### ۲-۱) ساختار پوشه‌ها

```
vs-bus-booking-manager/                    ← Free (WordPress.org)
├── vs-bus-booking-manager.php             ← Bootstrap Free
├── includes/
│   ├── class-settings.php                 ← مشترک (با is_pro_active)
│   ├── class-cache-manager.php            ← Free
│   ├── class-blacklist.php                ← Free (پایه)
│   ├── class-seat-reservations.php        ← Free
│   ├── class-ticket-manager.php           ← Free (HTML+QR)
│   ├── class-reservation-service.php      ← Free
│   ├── class-seat-manager.php             ← Free
│   ├── class-booking-handler.php          ← Free
│   ├── class-email-notifications.php      ← Free
│   ├── class-admin-interface.php          ← Free (با gating)
│   ├── class-license-manager.php          ← Free (UI)
│   └── class-elementor-integration.php    ← Free
├── assets/
│   ├── css/
│   ├── js/
│   │   ├── admin.js
│   │   └── frontend.js
│   └── ...
├── templates/
│   ├── admin/
│   ├── emails/
│   └── ...
└── languages/

vs-bus-booking-manager-pro/               ← Pro (از سایت خودمان)
├── vs-bus-booking-manager-pro.php         ← Bootstrap Pro
├── includes/
│   ├── class-pro-bootstrap.php            ← بررسی وابستگی به Free
│   ├── class-sms-notifications.php        ← Pro (منتقل از Free)
│   ├── class-rest-api.php                 ← Pro (منتقل از Free)
│   ├── class-ticket-scanner.php           ← Pro (منتقل از admin-interface)
│   ├── class-pdf-ticket-manager.php       ← Pro (PDF با لوگو)
│   └── class-advanced-reporting.php       ← Pro (جدید)
├── assets/
│   └── js/
│       └── scanner.js                     ← Pro (منتقل از Free)
└── languages/
```

### ۲-۲) ارتباط Free و Pro

```
┌─────────────────────────────────────────────────────────────┐
│  Free (همیشه فعال)                                          │
│  ├── VSBBM_Settings::is_pro_active()  ← خواندن لایسنس      │
│  ├── do_action('vsbbm_init')           ← Pro به این hook    │
│  ├── do_action('vsbbm_booking_created') ← Pro گوش می‌دهد   │
│  └── apply_filters('vsbbm_*')          ← Pro فیلتر می‌کند   │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│  Pro (فقط اگر Free فعال و لایسنس معتبر باشد)                │
│  ├── روی 'vsbbm_init' hook منتظر می‌ماند                   │
│  ├── بررسی VSBBM() exists (وابستگی به Free)                │
│  ├── بررسی VSBBM_Settings::is_pro_active()                 │
│  ├── ثبت منو‌های Pro روی 'admin_menu'                      │
│  ├── ثبت REST routes روی 'rest_api_init'                   │
│  └── ثبت AJAX handlers روی 'wp_ajax_*'                     │
└─────────────────────────────────────────────────────────────┘
```

### ۲-۳) Hookهای قابل توسعه (برای Pro)

| Hook | نوع | زمان اجرا | کاربرد Pro |
|------|-----|----------|-----------|
| `vsbbm_init` | action | بعد از راه‌اندازی Free | راه‌اندازی Pro |
| `vsbbm_booking_created` | action | بعد از رزرو موفق | ارسال SMS |
| `vsbbm_booking_cancelled` | action | بعد از لغو رزرو | ارسال SMS لغو |
| `vsbbm_rest_booking_created` | action | بعد از رزرو REST API | لاگ‌گیری |
| `vsbbm_max_seats_per_booking` | filter | هنگام validation | حداکثر صندلی متغیر |
| `vsbbm_ticket_generated` | action | بعد از تولید بلیط | ارسال PDF به ایمیل |
| `vsbbm_seat_price` | filter | هنگام محاسبه قیمت | قیمت‌گذاری پویا |

---

## ۳) مسئولیت کلاس‌ها

### ۳-۱) Free (هسته)

| کلاس | مسئولیت | وابستگی‌ها |
|------|---------|-----------|
| `VS_Bus_Booking_Manager` | Bootstrap، لود فایل‌ها، ثبت هوک‌ها | همه کلاس‌ها |
| `VSBBM_Settings` | مرکز تنظیمات، is_pro_active() | $wpdb |
| `VSBBM_Cache_Manager` | کش چندلایه | $wpdb, VSBBM_Settings |
| `VSBBM_Blacklist` | بررسی لیست سیاه | $wpdb |
| `VSBBM_Seat_Reservations` | CRUD رزروها + active_key | $wpdb |
| `VSBBM_Ticket_Manager` | تولید بلیط HTML + QR | VSBBM_Seat_Reservations |
| `VSBBM_Reservation_Service` | منطق رزرو متمرکز | Models, Blacklist, Settings |
| `VSBBM_Seat_Manager` | UI انتخاب صندلی + AJAX | Reservation_Service |
| `VSBBM_Booking_Handler` | ادغام WooCommerce | VSBBM_Seat_Manager |
| `VSBBM_Email_Notifications` | ایمیل‌های پایه | WC_Order |
| `VSBBM_Admin_Interface` | منوها + Dashboard | همه کلاس‌ها |
| `VSBBM_License_Manager` | فعال‌سازی لایسنس | VSBBM_Settings |
| `VSBBM_Elementor_Integration` | ویجت المنتور | VSBBM_Seat_Manager |

### ۳-۲) Pro (افزونه مکمل)

| کلاس | مسئولیت | وابستگی‌ها |
|------|---------|-----------|
| `VSBBM_Pro_Bootstrap` | بررسی وابستگی به Free | VSBBM() |
| `VSBBM_SMS_Notifications` | ارسال SMS (۷ پنل) | VSBBM_Settings, WC_Order |
| `VSBBM_REST_API` | REST API موبایل | VSBBM_Reservation_Service |
| `VSBBM_Ticket_Scanner` | اسکنر QR وب | VSBBM_Ticket_Manager |
| `VSBBM_PDF_Ticket_Manager` | PDF با لوگو | TCPDF, VSBBM_Ticket_Manager |
| `VSBBM_Advanced_Reporting` | گزارش‌گیری پیشرفته | $wpdb |

---

## ۴) قراردادهای WooCommerce

### ۴-۱) Cart Item Data

```php
$cart_item_data = array(
    'vsbbm_reservation_keys'    => array(1, 2, 3),  // IDs رزرو
    'vsbbm_departure_timestamp' => 1792225800,       // timestamp
    'vsbbm_seats'               => array('1', '2'),  // شماره صندلی‌ها
    'vsbbm_passengers'          => array(            // داده مسافران
        array(
            'seat_number' => '1',
            'نام کامل'    => 'علی احمدی',
            'کد ملی'      => '1234567890',
        ),
    ),
);
```

### ۴-۲) Order Item Meta

```php
// متاهای مخفی (برای منطق سیستم)
$item->add_meta_data('_vsbbm_passengers', $passengers);
$item->add_meta_data('_vsbbm_seats', $seats);
$item->add_meta_data('_vsbbm_reservation_keys', $keys);
$item->add_meta_data('_vsbbm_departure_timestamp', $timestamp);

// متاهای نمایشی (برای ادمین و کاربر)
$item->add_meta_data(__('Departure Date', 'vs-bus-booking-manager'), $display_date);
$item->add_meta_data(__('Passenger 1', 'vs-bus-booking-manager'), $display_text);
```

### ۴-۳) Order Status Mapping

| WC Status | Reservation Status | توضیح |
|-----------|-------------------|------|
| `pending` | `reserved` | منتظر پرداخت |
| `processing` | `confirmed` | پرداخت شد (آنلاین) |
| `on-hold` | `confirmed` | پرداخت با چک |
| `completed` | `confirmed` | تکمیل شد |
| `cancelled` | `cancelled` | لغو شد |
| `refunded` | `cancelled` | استرداد شد |
| `failed` | `cancelled` | پرداخت ناموفق |

### ۴-۴) Hookهای WooCommerce

| Hook | متد | کاربرد |
|------|-----|--------|
| `woocommerce_add_to_cart_validation` | `validate_booking` | اعتبارسنجی |
| `woocommerce_add_cart_item_data` | `add_cart_item_data` | داده سبد |
| `woocommerce_add_cart_item` | `calculate_cart_item_price` | محاسبه قیمت |
| `woocommerce_get_cart_item_from_session` | `get_cart_item_from_session` | بازیابی سبد |
| `woocommerce_checkout_create_order_line_item` | `save_order_item_meta` | ذخیره در سفارش |
| `woocommerce_checkout_order_processed` | `link_reservations_to_order` | اتصال رزرو |
| `woocommerce_order_status_changed` | `handle_order_status_change` | تغییر status |
| `woocommerce_order_status_completed` | `generate_tickets_for_order` | تولید بلیط |
| `woocommerce_order_status_processing` | `generate_tickets_for_order` | تولید بلیط |

---

## ۵) قراردادهای REST API (Pro)

### ۵-۱) Authentication

```http
Authorization: Bearer <token>
```

توکن در جدول `vsbbm_api_tokens` ذخیره می‌شود (هش شده در فاز ۴).

### ۵-۲) Endpoints

| Method | Endpoint | Auth | توضیح |
|--------|----------|------|------|
| POST | `/vsbbm/v1/auth/login` | ❌ | ورود |
| POST | `/vsbbm/v1/auth/register` | ❌ | ثبت‌نام |
| GET | `/vsbbm/v1/products` | ❌ | لیست محصولات |
| GET | `/vsbbm/v1/products/{id}` | ❌ | جزئیات محصول |
| GET | `/vsbbm/v1/products/{id}/seats?timestamp=X` | ❌ | وضعیت صندلی‌ها |
| POST | `/vsbbm/v1/reservations` | ✅ | رزرو |
| GET | `/vsbbm/v1/user/bookings` | ✅ | رزروهای کاربر |
| GET | `/vsbbm/v1/tickets/{code}` | ❌ | جزئیات بلیط (برای QR) |

### ۵-۳) Response Format

```json
{
    "success": true,
    "data": { ... },
    "error": null
}
```

خطا:
```json
{
    "code": "rest_reservation_failed",
    "message": "Seat 1 is already reserved.",
    "data": {
        "status": 400
    }
}
```

---

## ۶) دیتابیس

### ۶-۱) جداول

| جدول | هدف | کلید یکتا |
|------|-----|----------|
| `{prefix}vsbbm_seat_reservations` | رزروها | `(product_id, seat_number, departure_timestamp, active_key)` |
| `{prefix}vsbbm_tickets` | بلیط‌ها | `ticket_number` |
| `{prefix}vsbbm_blacklist` | لیست سیاه | `national_code` |
| `{prefix}vsbbm_api_tokens` | توکن‌های API | `token` |

### ۶-۲) active_key مکانیزم

```
status='reserved'   → active_key='ACTIVE'  (قفل می‌کند)
status='confirmed'  → active_key='ACTIVE'  (قفل می‌کند)
status='cancelled'  → active_key=NULL      (آزاد می‌کند)
status='expired'    → active_key=NULL      (آزاد می‌کند)
```

این مکانیزم guarantees می‌کند که برای هر صندلی + حرکت، فقط یک رزرو فعال وجود داشته باشه.

---

## ۷) امنیت

### ۷-۱) Nonces

| Action | Nonce Key | محل استفاده |
|--------|-----------|-------------|
| `vsbbm_seat_nonce` | `nonce` | AJAX انتخاب صندلی |
| `vsbbm_scanner_nonce` | `nonce` | AJAX اسکنر (Pro) |
| `vsbbm_admin_nonce` | `nonce` | AJAX ادمین |
| `vsbbm_license_activation` | `nonce` | AJAX فعال‌سازی لایسنس |

### ۷-۲) Sanitization

همه ورودی‌های کاربر در `VSBBM_Reservation_Service::validate_booking_input()` sanitize می‌شوند.

### ۷-۳) Escaping

همه خروجی‌ها با `esc_html`, `esc_attr`, `esc_url` escape می‌شوند.

---

## ۸) نقاط توسعه (برای فازهای بعدی)

### ۸-۱) PSR-4 Autoloading (فاز ۳)
جایگزینی `require_once` با autoloader.

### ۸-۲) Database Migrations (فاز ۳)
سیستم versioning برای schema changes.

### ۸-۳) Testing (فاز ۴)
PHPUnit + WP_Mock برای Models و Services.

### ۸-۴) Caching Strategy (فاز ۴)
Object Cache + Transients + CDN.

---

## ۹) تغییرات سند

| تاریخ | تغییر |
|------|------|
| ۲۰۲۵-۰۱ | ایجاد سند معماری (نسخه ۱.۰) |
