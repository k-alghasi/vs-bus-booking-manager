# docs/BUGS_AND_RISKS.md — فهرست باگ‌ها و ریسک‌ها

> **هدف:** ثبت تمام باگ‌ها و ریسک‌ها با شواهد، شدت، وضعیت تأیید و نتیجه اصلاح.

**سند مادر:** `PROJECT_RECOVERY_PLAN.md`
**آخرین به‌روزرسانی:** ۲۰۲۵-۰۱

---

## راهنمای وضعیت‌ها

### وضعیت تأیید
- ✅ **تأییدشده با شواهد** — کد/گیت آن را اثبات می‌کند
- ⚠️ **محتمل ولی تأییدنشده** — منطقاً مشکوک ولی نیازمند تست اجرایی
- ❓ **نیازمند آزمون عملی** — فقط با اجرای واقعی قابل تأیید

### شدت
- 🔴 **بحرانی پولی** — روی پول مشتری یا صحت سفارش اثر مستقیم دارد
- 🟠 **بحرانی عملکردی** — قابلیت اصلی خراب می‌شود
- 🟡 **متوسط** — تجربه کاربری یا داده‌ها تحت تأثیر قرار می‌گیرند
- 🟢 **کم** — نکته بهبودی

### وضعیت اصلاح
- ⏳ در صف
- 🔧 در حال اصلاح
- ✅ اصلاح شد
- 🧪 اصلاح شد، نیازمند تست
- ⏭️ به تعویق افتاد (با دلیل)

---

## فاز ۰: باگ‌های بحرانی ✅ همه کامل شد

### P0-1: ثبت دوباره هوک‌های Booking_Handler → ضرب قیمت ✅
| فیلد | مقدار |
|------|------|
| **فایل** | `vs-bus-booking-manager.php:173` + `includes/class-booking-handler.php:299` |
| **شدت** | 🔴 بحرانی پولی |
| **وضعیت تأیید** | ✅ تأییدشده با شواهد |
| **وضعیت اصلاح** | ✅ اصلاح شد + تأیید تست کاربر |

**راه‌حل:** اضافه شدن guard `$initialized` به `init()` + حذف فراخوانی تکراری.

### P0-2: ۴ endpoint از ۱۲ تا REST API متد ندارند ✅
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-rest-api.php` |
| **شدت** | 🟠 بحرانی عملکردی |
| **وضعیت تأیید** | ✅ تأییدشده با شواهد |
| **وضعیت اصلاح** | ✅ غیرفعال موقت (تا فاز ۲) |

**راه‌حل:** ۴ route با کامنت `/* */` غیرفعال شدند.

### P0-3: رزروها در مسیر سایت به سفارش وصل نمی‌شوند ✅
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-booking-handler.php` + `includes/class-seat-reservations.php` |
| **شدت** | 🔴 بحرانی پولی |
| **وضعیت تأیید** | ✅ تأییدشده با شواهد |
| **وضعیت اصلاح** | ✅ اصلاح شد + تأیید تست کاربر |

**راه‌حل:**
- ذخیره `_vsbbm_reservation_keys` در order item meta
- هوک `woocommerce_checkout_order_processed` → `link_reservations_to_order()`
- متد جدید `link_reservations_by_keys()` (دقیق، بدون user_id)

### P0-4: رزرو منقضی‌شده صندلی را بلوکه می‌کند ✅
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-seat-reservations.php` |
| **شدت** | 🟠 بحرانی عملکردی |
| **وضعیت اصلاح** | ✅ اصلاح شد + تأیید تست کاربر |

**راه‌حل:** اضافه شدن فیلتر `expires_at > NOW()` به کوئری + استفاده از `current_time('mysql')`.

### P0-5: Overbooking race condition ✅
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-seat-reservations.php` |
| **شدت** | 🔴 بحرانی پولی |
| **وضعیت اصلاح** | ✅ اصلاح شد + تأیید تست کاربر (race condition تست شد) |

**راه‌حل نهایی:** DB-level unique constraint با ستون `active_key`.
- active_key='ACTIVE' برای رزروهای فعال
- active_key=NULL برای cancelled/expired
- UNIQUE KEY (product_id, seat_number, departure_timestamp, active_key)

**مسیر حل:**
1. Transaction + FOR UPDATE → شکست (روی رکوردهای غیرموجود قفل نمی‌ذاره)
2. GET_LOCK() → شکست (مشکل محیطی)
3. **DB unique constraint → موفقیت** ✅

### P0-6: قیمت اختصاصی صندلی در سبد لحاظ نمی‌شود ✅
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-booking-handler.php` |
| **شدت** | 🟠 بحرانی عملکردی |
| **وضعیت اصلاح** | ✅ اصلاح شد (forward-compatible — نیاز به UI قیمت‌گذاری در فاز ۳) |

**راه‌حل:** متد جدید `calculate_seats_total_price()` + ذخیره `vsbbm_calculated_total`.

### P0-7: صفحات All Bookings و Reservations خالی هستند ✅
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-admin-interface.php` |
| **شدت** | 🟡 متوسط |
| **وضعیت اصلاح** | ✅ اصلاح شد |

**راه‌حل:** صفحه Bookings با لینک به WC Orders + صفحه Reservations با جدول آخرین ۵۰ رزرو.

### P0-8: کوئری درآمد داشبورد همه سفارشات WC را می‌شمارد ✅
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-admin-interface.php` |
| **شدت** | 🟡 متوسط |
| **وضعیت اصلاح** | ✅ اصلاح شد |

**راه‌حل:** فیلتر EXISTS روی `_vsbbm_passengers` + اضافه شدن `wc-on-hold`.

### P0-9: cache manager با status='active' کوئری می‌کند ✅
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-cache-manager.php` |
| **شدت** | 🟡 متوسط |
| **وضعیت اصلاح** | ✅ اصلاح شد |

**راه‌حل:** تغییر به `status IN ('reserved', 'confirmed')`.

### NEW-1: nonce در فرم مسافر نمی‌رفت ✅
| فیلد | مقدار |
|------|------|
| **فایل** | `assets/js/frontend.js` |
| **شدت** | 🔴 بحرانی عملکردی |
| **وضعیت اصلاح** | ✅ اصلاح شد + تأیید تست کاربر |

**راه‌حل:** اضافه شدن `nonce: this.settings.nonce` به درخواست AJAX.

### NEW-2: ۱۹۷۴ حرف خروجی غیرمنتظره + جدول tickets ساخته نمی‌شود ✅
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-seat-reservations.php` + `includes/class-ticket-manager.php` |
| **شدت** | 🟠 بحرانی عملکردی |
| **وضعیت اصلاح** | ✅ اصلاح شد + تأیید تست کاربر |

**راه‌حل:**
- حذف `register_activation_hook` از کلاس‌ها (PHP 8+ deprecation)
- اصلاح SQL به فرمت dbDelta (UNIQUE KEY مجزا)
- اضافه شدن `ensure_table_exists()` self-healing روی `admin_init`

### NEW-3: هشدار لایسنس روی همه صفحات ✅
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-license-manager.php` |
| **شدت** | 🟡 متوسط |
| **وضعیت اصلاح** | ✅ اصلاح شد |

**راه‌حل:** فقط روی صفحات `vsbbm-*` + تغییر از `error` به `info`.

### NEW-4: status on-hold (پرداخت با چک) ✅
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-seat-reservations.php` |
| **شدت** | 🔴 بحرانی پولی |
| **وضعیت اصلاح** | ✅ اصلاح شد + تأیید تست کاربر |

**راه‌حل:** اضافه شدن `'on-hold'` به لیست status های confirm.

### P1-2: cancel_reservations_by_keys کش را پاک نمی‌کند ✅
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-seat-reservations.php` |
| **شدت** | 🟡 متوسط |
| **وضعیت اصلاح** | ✅ اصلاح شد |

**راه‌حل:** fetch product_id+timestamp قبل از cancel + پاکسازی کش.

---

## فاز ۱: بازآرایی ✅ کامل شد (مراحل ۱.۱-۱.۴)

### P1-1.1: ساخت کلاس Settings مرکزی ✅
| فیلد | مقدار |
|------|------|
| **فایل جدید** | `includes/class-settings.php` |
| **وضعیت اصلاح** | ✅ اصلاح شد + تأیید تست کاربر |

**راه‌حل:** کلاس `VSBBM_Settings` با in-memory cache + defaults + `is_pro_active()`.

### P1-1.2: ساخت Reservation_Service مرکزی ✅
| فیلد | مقدار |
|------|------|
| **فایل جدید** | `includes/class-reservation-service.php` |
| **وضعیت اصلاح** | ✅ اصلاح شد + تأیید تست کاربر |

**راه‌حل:** کلاس `VSBBM_Reservation_Service` با `create_booking()`, `cancel_booking()`, `get_seat_availability()`.

### P1-1.3: ادغام سرویس در seat-manager (AJAX) ✅
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-seat-manager.php` |
| **وضعیت اصلاح** | ✅ اصلاح شد + تأیید تست کاربر (۵ تست) |

**راه‌حل:** `add_to_cart_ajax` و `get_passenger_fields_ajax` به سرویس delegate می‌کنند.

### P1-1.4: ادغام سرویس در rest-api ✅
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-rest-api.php` |
| **وضعیت اصلاح** | ✅ اصلاح شد + تأیید تست کاربر |

**راه‌حل:** `create_reservation` و `get_product_seats` به سرویس delegate می‌کنند.

---

## فاز ۲: تفکیک Free/Pro 🔄 در حال انجام

### P2-1: تعیین ماتریس نهایی Free/Pro ✅
| فیلد | مقدار |
|------|------|
| **وضعیت اصلاح** | ✅ کامل شد |

**نتیجه:** ماتریس در `PROJECT_RECOVERY_PLAN.md` بخش ۳ ثبت شد.

### P2-2: Gating در Free ✅
| فیلد | مقدار |
|------|------|
| **فایل** | `vs-bus-booking-manager.php` + `includes/class-admin-interface.php` |
| **وضعیت اصلاح** | ✅ اصلاح شد + تأیید تست کاربر |

**راه‌حل:**
- Conditional loading فایل‌های Pro در bootstrap (با `file_exists` check)
- Gating منوهای Pro در admin-interface
- Gating اسکریپت‌های Pro (scanner.js)

### P2-3: ساخت اسکلت افزونه Pro ✅
| فیلد | مقدار |
|------|------|
| **فایل** | `vs-bus-booking-manager-pro/vs-bus-booking-manager-pro.php` + `includes/class-pro-bootstrap.php` |
| **وضعیت اصلاح** | ✅ کامل شد + تأیید تست کاربر |

**راه‌حل:**
- Bootstrap با بررسی وابستگی به Free (`is_free_active()`)
- Helper utilities در `VSBBM_Pro_Bootstrap`
- Admin notices برای Free موردنیاز و لایسنس نامعتبر
- Hook `vsbbm_pro_init` برای توسعه‌دهندگان

### P2-4: انتقال فیچرها به Pro ✅
| فیلد | مقدار |
|------|------|
| **فایل** | فایل‌های منتقل‌شده به `vs-bus-booking-manager-pro/` |
| **وضعیت اصلاح** | ✅ کامل شد + تأیید تست کاربر |

**فایل‌های منتقل‌شده:**
- `includes/class-sms-notifications.php` (با استفاده از `VSBBM_Settings`)
- `includes/class-rest-api.php` (با `link_reservations_by_keys`)
- `includes/class-ticket-scanner.php` (جدید — استخراج از admin-interface)
- `assets/js/scanner.js`

### P2-5: تست نهایی Free و Pro ✅
| فیلد | مقدار |
|------|------|
| **وضعیت اصلاح** | ✅ تأیید تست کاربر |

**نتایج تست:**
- Free بدون Pro: ✅ کار می‌کنه
- Free + Pro با لایسنس: ✅ همه فیچرها فعال
- REST API: ✅ کار می‌کنه
- Scanner منو: ✅ ظاهر می‌شه
- SMS/API Settings منو: ✅ ظاهر می‌شن

### P2.5: بهبود چیدمان صندلی‌ها ✅
| فیلد | مقدار |
|------|------|
| **فایل** | `assets/js/admin.js` + `assets/js/frontend.js` + `includes/class-seat-manager.php` + `assets/css/frontend.css` |
| **وضعیت اصلاح** | ✅ کامل شد + تأیید تست کاربر |

**بهبودها:**
- چیدمان واقعی اتوبوس با راهروی وسط
- رعایت bus_type (2x2, 1x2 VIP, 1x1 Luxury)
- حفظ position صندلی‌ها (row, col, side)
- فیکس VIP (دوتایی راست، تکی چپ در RTL)
- نوار جلوی اتوبوس حرفه‌ای
- راهنمای UX در Visual Seat Editor
- حذف نوار درب عقب (جای خالی کافیه)
- تغییر زنده گرید با تغییر bus_type

---

## فاز ۴: ریسک‌های امنیتی (به تعویق)

### P4-1: مسیر عمومی /tickets/{code} + uniqid قابل حدس
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-rest-api.php` + `class-ticket-manager.php` |
| **شدت** | 🟡 متوسط |
| **وضعیت اصلاح** | ⏳ به تعویق (فاز ۴) |

**راه‌حل:** استفاده از `wp_generate_password(32, false)` + rate limiting.

### P4-2: توکن‌های API به‌صورت plaintext در DB
| فیلد | مقدار |
|------|------|
| **فایل** | `vs-bus-booking-manager.php` + `class-rest-api.php` |
| **شدت** | 🟡 متوسط |
| **وضعیت اصلاح** | ⏳ به تعویق (فاز ۴) |

**راه‌حل:** هش کردن توکن با `wp_hash_password()`.

### P4-3: بدون Rate Limiting روی auth/OTP
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-rest-api.php` + `class-sms-notifications.php` |
| **شدت** | 🟠 بحرانی عملکردی |
| **وضعیت اصلاح** | ⏳ به تعویق (فاز ۴) |

**راه‌حل:** Rate limiting با transient.

---

## ریسک‌های نیازمند آزمون عملی

### R-1: آیا Visual Seat Editor واقعاً کار می‌کند؟ ✅
| فیلد | مقدار |
|------|------|
| **وضعیت تأیید** | ✅ تأییدشده با تست کاربر |
| **وضعیت اصلاح** | ✅ کار می‌کند (grid-based ساده) |

### R-2: رفتار دقیق ضرب قیمت ✅
| فیلد | مقدار |
|------|------|
| **وضعیت تأیید** | ✅ تأییدشده (باگ P0-1 حل شد) |

### R-3: آیا فایل‌های _old واقعاً مرده‌اند؟
| فیلد | مقدار |
|------|------|
| **وضعیت تأیید** | ✅ تأییدشده با شواهد (۰ ارجاع) |
| **وضعیت اصلاح** | ⏳ حذف در فاز ۳ |

---

## خلاصه اولویت‌بندی

| فاز | تعداد باگ | تأیید کاربر | باقی‌مانده |
|----|----------|------------|------------|
| فاز ۰ | ۱۴ | ✅ همه | ۰ |
| فاز ۱ | ۴ | ✅ همه | ۰ |
| فاز ۲ | ۵ + بهبود چیدمان | ✅ همه | ۰ |
| فاز ۴ | ۳ | — | ۳ (به تعویق) |

---

## تغییرات سند

| تاریخ | تغییر |
|------|------|
| ۲۰۲۵-۰۱ | ایجاد سند (نسخه ۱.۰) |
| ۲۰۲۵-۰۱ | به‌روزرسانی پس از فاز ۰ (۱۴ باگ) |
| ۲۰۲۵-۰۱ | به‌روزرسانی پس از فاز ۱ (۴ مورد) |
| ۲۰۲۵-۰۱ | به‌روزرسانی پس از فاز ۲.۱-۲.۲ (Gating) |
| ۲۰۲۵-۰۱ | v2.0: تکمیل فاز ۲ + بهبود چیدمان صندلی‌ها |
