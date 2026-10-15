# docs/BUGS_AND_RISKS.md — فهرست باگ‌ها و ریسک‌ها

> **هدف:** ثبت تمام باگ‌ها و ریسک‌ها با شواهد، شدت، وضعیت تأیید و نتیجه اصلاح. هیچ فرضیه‌ای بدون تأیید به‌عنوان «باگ قطعی» ثبت نمی‌شود.

**سند مادر:** `PROJECT_RECOVERY_PLAN.md`
**آخرین به‌روزرسانی:** ۲۰۲۵-۰۱

---

## راهنمای وضعیت‌ها

### وضعیت تأیید
- ✅ **تأییدشده با شواهد** — کد/گیت آن را اثبات می‌کند (grep/show)
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

## فاز ۰: باگ‌های بحرانی

### P0-1: ثبت دوباره هوک‌های Booking_Handler → ضرب قیمت
| فیلد | مقدار |
|------|------|
| **فایل** | `vs-bus-booking-manager.php:173` + `includes/class-booking-handler.php:299` |
| **شدت** | 🔴 بحرانی پولی |
| **وضعیت تأیید** | ✅ تأییدشده با شواهد |
| **وضعیت اصلاح** | ⏳ در صف |

**شواهد:**
```
# در فایل اصلی افزونه
vs-bus-booking-manager.php:173:  VSBBM_Booking_Handler::init();

# در انتهای فایل کلاس (دوباره!)
class-booking-handler.php:299:   VSBBM_Booking_Handler::init();
```

متد `init()` هیچ guard‌ای برای جلوگیری از اجرای مجدد ندارد. هوک‌های `woocommerce_add_cart_item` (محاسبه قیمت) دو بار ثبت می‌شوند.

**اثر احتمالی:** فیلتر `calculate_cart_item_price` دو بار اجرا می‌شود. بار اول: `set_price(base × passengers)`. بار دوم: `set_price((base × passengers) × passengers)`. مثال: ۳ مسافر × ۱۰۰هزار → انتظار ۳۰۰هزار، واقعیت احتمالی ۹۰۰هزار.

**نکته:** اثر دقیق نیازمند تست اجرایی است (ووکامرس ممکن است guard داخلی داشته باشد)، اما خود ثبت دوباره قطعی است.

**راه‌حل پیشنهادی:**
1. حذف خط ۲۹۹ (`VSBBM_Booking_Handler::init();`) از انتهای فایل کلاس
2. اضافه کردن guard به `init()`:
```php
private static $initialized = false;
public static function init() {
    if ( self::$initialized ) return;
    self::$initialized = true;
    // ... existing hooks
}
```

**تست تأیید:** رزرو ۳ مسافر برای محصول ۱۰۰هزار → بررسی قیمت در سبد = ۳۰۰هزار (نه بیشتر).

---

### P0-2: ۴ endpoint از ۱۲ تا REST API متد ندارند → Fatal Error
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-rest-api.php` |
| **شدت** | 🟠 بحرانی عملکردی |
| **وضعیت تأیید** | ✅ تأییدشده با شواهد |
| **وضعیت اصلاح** | ⏳ در صف |

**شواهد:**
```
# مسیرهای ثبت‌شده با callback که تعریف نشده‌اند:
array( $this, 'get_booking_details' )   → 0 تعریف
array( $this, 'use_ticket' )            → 0 تعریف
array( $this, 'get_user_profile' )      → 0 تعریف
array( $this, 'update_user_profile' )   → 0 تعریف

# مسیرهای سالم (۱۲ تا کل، ۱۰ تا تعریف دارند):
handle_login, handle_register, get_products, get_product_details,
get_product_seats, create_reservation, get_user_bookings, get_ticket_details
```

**اثر:** هر درخواست به این ۴ endpoint با Fatal Error مواجه می‌شود (callback غیرقابل فراخوانی).

**راه‌حل پیشنهادی:**
- گزینه A: پیاده‌سازی این ۴ متد (محتوای آن‌ها در `class-ticket-manager.php` و منطق مشابه وجود دارد)
- گزینه B: حذف موقت این ۴ route تا فاز ۲ (REST API به Pro می‌رود)

**توصیه:** گزینه B برای فاز ۰ (سریع‌تر)، گزینه A در فاز ۲ هنگام انتقال به Pro.

---

### P0-3: رزروها در مسیر سایت به سفارش وصل نمی‌شوند
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-booking-handler.php` + `includes/class-seat-reservations.php:247` |
| **شدت** | 🔴 بحرانی پولی |
| **وضعیت تأیید** | ✅ تأییدشده با شواهد |
| **وضعیت اصلاح** | ⏳ در صف |

**شواهد:**
```
# ۱. add_to_cart_ajax کلیدهای رزرو را در cart data ذخیره می‌کند:
class-seat-manager.php:393:  'vsbbm_reservation_keys' => $keys,

# ۲. اما save_order_item_meta این کلیدها را نادیده می‌گیرد:
class-booking-handler.php:  فقط _vsbbm_passengers و _vsbbm_seats ذخیره می‌شود
                              (هیچ ارجاعی به reservation_keys وجود ندارد)

# ۳. update_reservation_order_id فقط در REST API صدا زده می‌شود:
class-rest-api.php:437:     VSBBM_Seat_Reservations::update_reservation_order_id(...)
# در مسیر سایت هیچ‌جا صدا زده نمی‌شود.
```

**اثر زنجیره‌ای (بحرانی):**
1. مشتری رزرو می‌کند → رکورد با `order_id = NULL` و `status = 'reserved'`
2. سفارش processing می‌شود
3. `handle_order_status_change` با `WHERE order_id = X` کوئری می‌کند → **خالی برمی‌گردد** چون هیچ رزروی order_id ندارد
4. رزروها در وضعیت `reserved` باقی می‌مانند
5. cron ساعتی بعد از ۱۵ دقیقه آن‌ها را `expired` می‌کند
6. **نتیجه:** مشتری پول داده، ولی صندلی‌هایش آزاد شده‌اند و بلیط صادر نمی‌شود

**راه‌حل پیشنهادی:**
1. در `save_order_item_meta`: ذخیره `reservation_keys` در order item meta
2. اضافه کردن hook روی `woocommerce_checkout_order_processed` که `update_reservation_order_id` را صدا بزند
3. یا بهتر: استفاده از `reservation_keys` ذخیره‌شده برای اتصال مستقیم

**تست تأیید:**
- رزرو ۲ صندلی → checkout → بررسی دیتابیس: `order_id` رزروها باید برابر ID سفارش باشد
- بررسی `status` رزروها باید `confirmed` باشد (نه `expired`)

---

### P0-4: رزرو منقضی‌شده صندلی را بلوکه می‌کند
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-seat-reservations.php:208-215` |
| **شدت** | 🟠 بحرانی عملکردی |
| **وضعیت تأیید** | ✅ تأییدشده با شواهد |
| **وضعیت اصلاح** | ⏳ در صف |

**شواهد:**
```sql
-- کوئری فعلی (هیچ فیلتری روی expires_at ندارد):
SELECT seat_number, status FROM vsbbm_seat_reservations
WHERE product_id = %d
AND departure_timestamp = %d
AND status IN ('reserved', 'confirmed')
-- مشکل: رزروهای 'reserved' که expires_at گذشته ولی هنوز
-- توسط cron به 'expired' تغییر نکرده‌اند، صندلی را اشغال می‌کنند
```

**اثر:** تا ۱ ساعت (زمان اجرای cron) یک صندلی که رزرو موقتش منقضی شده، همچنان به‌عنوان اشغال نمایش داده می‌شود و قابل رزرو نیست.

**راه‌حل پیشنهادی:**
```sql
AND status IN ('reserved', 'confirmed')
AND (status = 'confirmed' OR expires_at > NOW())
```
یا منطق PHP: اگر `status='reserved'` و `expires_at < now()` → در نظر نگیر.

**تست تأیید:**
- رزرو یک صندلی → دستکاری `expires_at` در DB به زمان گذشته → بررسی اینکه صندلی آزاد نمایش داده می‌شود.

---

### P0-5: Overbooking race condition + کلید یکتای ناقص
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-seat-reservations.php:97, 137-175` |
| **شدت** | 🔴 بحرانی پولی |
| **وضعیت تأیید** | ✅ تأییدشده با شواهد (بخش کلید یکتا) / ⚠️ محتمل (race condition) |
| **وضعیت اصلاح** | ⏳ در صف |

**شواهد (کلید یکتا):**
```sql
UNIQUE KEY unique_reservation (product_id, seat_number, departure_timestamp, order_id)
-- مشکل: order_id در فاز سبد NULL است.
-- MySQL مقادیر NULL را در UNIQUE KEY متمایز می‌داند.
-- یعنی چند رکورد با order_id=NULL برای یک صندلی مجازند.
```

**شواهد (race condition):**
```php
// ۱. اول چک می‌کند:
if ( ! self::is_seat_available( $product_id, $seat_number, $departure_timestamp ) ) {
    return new WP_Error(...);
}
// ۲. بعد درج می‌کند:
$result = $wpdb->insert( $table_name, $data, $format );
// بین این دو مرحله، دو درخواست هم‌زمان می‌توانند هر دو صندلی را آزاد ببینند.
```

**اثر:** در ترافیک بالا، دو کاربر هم‌زمان می‌توانند یک صندلی را رزرو کنند.

**راه‌حل پیشنهادی (اصولی - فاز ۱):**
جدول جدید `vsbbm_seat_assignments`:
```sql
CREATE TABLE vsbbm_seat_assignments (
    id BIGINT AUTO_INCREMENT,
    trip_id BIGINT NOT NULL,  -- نیاز به مفهوم trip واقعی
    seat_number VARCHAR(20) NOT NULL,
    reservation_id BIGINT NOT NULL,
    status ENUM('held','booked') DEFAULT 'held',
    PRIMARY KEY (id),
    UNIQUE KEY unique_active_assignment (trip_id, seat_number, status)
    -- یا حذف نرم + reinsert
);
```

**راه‌حل موقت (فاز ۰):** transaction + retry روی خطای duplicate:
```php
$wpdb->query('START TRANSACTION');
try {
    $result = $wpdb->insert(...);
    if ($wpdb->last_error && strpos($wpdb->last_error, 'Duplicate') !== false) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('seat_taken', 'صندلی هم‌اکنون رزرو شد');
    }
    $wpdb->query('COMMIT');
} catch (Exception $e) {
    $wpdb->query('ROLLBACK');
}
```

**نکته:** راه‌حل کلید یکتای `CASE WHEN` (پیشنهاد اولیه) **اشتباه بود** چون `id` auto-increment متمایز است. حذف شد.

**تست تأیید:** اسکریپت هم‌زمان ۱۰ درخواست برای یک صندلی → فقط ۱ موفق، ۹ خطا.

---

### P0-6: قیمت اختصاصی صندلی در سبد لحاظ نمی‌شود
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-booking-handler.php:95-111` |
| **شدت** | 🟠 بحرانی عملکردی |
| **وضعیت تأیید** | ⚠️ محتمل ولی تأییدنشده |
| **وضعیت اصلاح** | ⏳ در صف |

**شواهد:**
```php
// calculate_cart_item_price فقط از قیمت پایه محصول استفاده می‌کند:
$original_price = (float) $cart_item_data['data']->get_price();
$new_price = $original_price * $passenger_count;

// اما در frontend.js و REST API، هر صندلی قیمت جداگانه دارد:
$seats[] = array(
    'price' => (float) $seat_data['price'] > 0 ? $seat_data['price'] : 0,
);
// و در display_seat_selection:
echo 'data-price="' . esc_attr( $product->get_price() ) . '"';
// فقط قیمت پایه محصول پاس داده می‌شود، نه قیمت هر صندلی.
```

**اثر:** اگر صندلی VIP با قیمت ۱۵۰هزار و صندلی معمولی با ۱۰۰هزار تعریف شده باشند، هر دو در سبد با قیمت پایه محصول محاسبه می‌شوند.

**وضعیت تأیید:** ⚠️ نیازمند تست اجرایی. ممکن است ووکامرس رفتار متفاوتی داشته باشد. باید با محصول واقعی تست شود.

**راه‌حل پیشنهادی:**
```php
public static function calculate_cart_item_price( $cart_item_data, $cart_item_key ) {
    if ( isset( $cart_item_data['vsbbm_passengers'] ) ) {
        $product_id = $cart_item_data['product_id'];
        $base_price = (float) $cart_item_data['data']->get_price();
        $seat_numbers = array_column( $cart_item_data['vsbbm_passengers'], 'seat_number' );
        $all_seats = VSBBM_Seat_Manager::get_seat_numbers( $product_id );

        $total = 0;
        foreach ( $seat_numbers as $seat_num ) {
            $seat_price = isset( $all_seats[ $seat_num ]['price'] ) && $all_seats[ $seat_num ]['price'] > 0
                ? (float) $all_seats[ $seat_num ]['price']
                : $base_price;
            $total += $seat_price;
        }

        $cart_item_data['vsbbm_original_price'] = $base_price;
        $cart_item_data['vsbbm_calculated_total'] = $total;
        $cart_item_data['data']->set_price( $total );
    }
    return $cart_item_data;
}
```

**تست تأیید:** تعریف صندلی VIP با قیمت ۱۵۰هزار → رزرو → بررسی قیمت سبد = ۱۵۰هزار (نه ۱۰۰هزار پایه).

---

### P0-7: صفحات All Bookings و Reservations خالی هستند
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-admin-interface.php:326-327` |
| **شدت** | 🟡 متوسط |
| **وضعیت تأیید** | ✅ تأییدشده با شواهد |
| **وضعیت اصلاح** | ⏳ در صف |

**شواهد:**
```php
// ارجاع به فایل‌هایی که وجود ندارند:
public function render_bookings_page() {
    if ( file_exists( VSBBM_PLUGIN_PATH . 'templates/admin/bookings.php' ) )
        include VSBBM_PLUGIN_PATH . 'templates/admin/bookings.php';
}
// اما فقط bookings_old.php وجود دارد:
// templates/admin/bookings_old.php  ✓
// templates/admin/bookings.php      ✗ (موجود نیست)
```

**اثر:** منوی «All Bookings» و «Reservations» در ادمین صفحه خالی نمایش می‌دهند.

**راه‌حل پیشنهادی:**
- گزینه A: بازسازی `bookings.php` و `reservations.php` از روی `_old` (با پاکسازی)
- گزینه B: حذف موقت این دو منو تا فاز ۱

**توصیه:** گزینه B برای فاز ۰، گزینه A در فاز ۱.

---

### P0-8: کوئری درآمد داشبورد همه سفارشات WC را می‌شمارد
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-admin-interface.php:163-171, 211-219` |
| **شدت** | 🟡 متوسط |
| **وضعیت تأیید** | ✅ تأییدشده با شواهد |
| **وضعیت اصلاح** | ⏳ در صف |

**شواهد:**
```sql
-- کوئری فعلی (هیچ فیلتری روی سفارشات اتوبوسی ندارد):
SELECT SUM(pm.meta_value)
FROM wp_postmeta pm
JOIN wp_posts p ON p.ID = pm.post_id
WHERE p.post_type = 'shop_order'
AND p.post_status IN ('wc-completed', 'wc-processing')
AND pm.meta_key = '_order_total'
AND EXISTS (SELECT 1 FROM wp_woocommerce_order_items oi WHERE oi.order_id = p.ID)
-- مشکل: EXISTS فقط چک می‌کند که سفارش آیتم دارد، نه اینکه آیتم اتوبوسی دارد.
```

**اثر:** اگر فروشگاه محصولات دیگری هم بفروشد، درآمد کل با درآمد بلیط قاطی می‌شود.

**راه‌حل پیشنهادی:**
```sql
AND EXISTS (
    SELECT 1 FROM wp_woocommerce_order_items oi
    JOIN wp_woocommerce_order_itemmeta oim ON oim.order_item_id = oi.order_item_id
    WHERE oi.order_id = p.ID
    AND oim.meta_key = '_vsbbm_passengers'
)
```

**تست تأیید:** ایجاد یک سفارش غیر-اتوبوسی → بررسی اینکه درآمد داشبورد فقط بلیط‌ها را می‌شمارد.

---

### P0-9: cache manager با status='active' کوئری می‌کند (همیشه خالی)
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-cache-manager.php:253` |
| **شدت** | 🟡 متوسط |
| **وضعیت تأیید** | ✅ تأییدشده با شواهد |
| **وضعیت اصلاح** | ⏳ در صف |

**شواهد:**
```php
// cache-manager.php:253
"SELECT seat_number FROM $table_name WHERE product_id = %d AND status = 'active'"

// اما schema جدول:
status ENUM('reserved', 'confirmed', 'cancelled', 'expired') DEFAULT 'reserved'
-- هیچ 'active' ای وجود ندارد!
```

**اثر:** متد `get_reserved_seats()` در cache manager همیشه خالی برمی‌گرداند. فعلاً بلااستفاده به‌نظر می‌رسد (چون `seat-reservations` خودش کش جداگانه دارد) ولی یک بمب ساعته.

**راه‌حل پیشنهادی:**
```php
"SELECT seat_number FROM $table_name WHERE product_id = %d AND status IN ('reserved', 'confirmed')"
```

**تست تأیید:** فراخوانی متد → بررسی خروجی غیرخالی وقتی رزرو وجود دارد.

---

## فاز ۱: ریسک‌های معماری

### P1-1: update_reservation_order_id در API از get_current_user_id اشتباه استفاده می‌کند
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-seat-reservations.php:250` |
| **شدت** | 🟠 بحرانی عملکردی |
| **وضعیت تأیید** | ⚠️ محتمل ولی تأییدنشده |
| **وضعیت اصلاح** | ⏳ در صف |

**شواهد:**
```php
public static function update_reservation_order_id( $order_id, $passengers ) {
    $user_id = get_current_user_id();  // ❌ در API، کاربر جاری WP کاربر توکن نیست
    // ...
    WHERE user_id = %d AND order_id IS NULL
}
```

در REST API، احراز هویت با Bearer token انجام می‌شود و `user_id` در پارامتر request قرار می‌گیرد، نه در `get_current_user_id()`.

**راه‌حل:** پارامتر `user_id` به‌صورت صریح پاس داده شود.

---

### P1-2: cancel_reservations_by_keys کش را پاک نمی‌کند
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-seat-reservations.php:333-349` |
| **شدت** | 🟡 متوسط |
| **وضعیت تأیید** | ✅ تأییدشده با شواهد (کامنت کد خودش اعتراف می‌کند) |
| **وضعیت اصلاح** | ⏳ در صف |

**شواهد:**
```php
// We need product_id and timestamp to clear cache effectively, but for cancellation just update DB
// The cache TTL will handle the rest or manual clear if critical
$wpdb->query( "UPDATE ... SET status = 'cancelled' WHERE id IN (...)" );
// هیچ clear_reservation_cache ای صدا زده نمی‌شود!
```

**اثر:** وقتی رزرو در حین add_to_cart لغو می‌شود (مثلاً خطا در cart)، کش قدیمی تا ۲ دقیقه صندلی را اشغال نشان می‌دهد.

**راه‌حل:** قبل از UPDATE، `product_id` و `departure_timestamp` رزروها را fetch کنیم، بعد کش را پاک کنیم.

---

## فاز ۲: امنیت

### P2-1: مسیر عمومی /tickets/{code} + uniqid قابل حدس
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-rest-api.php:113-117` + `class-ticket-manager.php:165` |
| **شدت** | 🟡 متوسط |
| **وضعیت تأیید** | ✅ تأییدشده با شواهد |
| **وضعیت اصلاح** | ⏳ در صف |

**شواهد:**
```php
// ticket_number با uniqid تولید می‌شود (غیرکریپتوگرافیک):
return strtoupper( uniqid( 'TK-' . $order_id . '-' ) );

// و مسیر عمومی است:
register_rest_route( $namespace, '/tickets/(?P<code>[a-zA-Z0-9-]+)', array(
    'permission_callback' => '__return_true', // عمومی!
));
```

**اثر:** با حدس `order_id` و brute-force بخش `uniqid`، اطلاعات مسافر قابل دسترسی است.

**راه‌حل:**
- استفاده از `wp_generate_password(32, false)` یا `bin2hex(random_bytes(16))`
- اضافه کردن rate limiting
- یا نیاز به احراز هویت برای endpoint

---

### P2-2: توکن‌های API به‌صورت plaintext در DB
| فیلد | مقدار |
|------|------|
| **فایل** | `vs-bus-booking-manager.php:135-144` + `class-rest-api.php:178-196` |
| **شدت** | 🟡 متوسط |
| **وضعیت تأیید** | ✅ تأییدشده با شواهد |
| **وضعیت اصلاح** | ⏳ در صف |

**راه‌حل:** هش کردن توکن با `wp_hash_password()` قبل از ذخیره، و مقایسه با `wp_check_password()`.

---

### P2-3: بدون Rate Limiting روی auth/OTP
| فیلد | مقدار |
|------|------|
| **فایل** | `includes/class-rest-api.php:54-64` + `class-sms-notifications.php` |
| **شدت** | 🟠 بحرانی عملکردی |
| **وضعیت تأیید** | ✅ تأییدشده با شواهد |
| **وضعیت اصلاح** | ⏳ در صف |

**اثر:** حمله brute-force روی `/auth/login` و SMS bombing روی OTP.

**راه‌حل:** Rate limiting با transient (مثلاً ۵ تلاش در ۵ دقیقه برای هر IP).

---

## ریسک‌های نیازمند آزمون عملی

### R-1: آیا Visual Seat Editor واقعاً کار می‌کند؟
| فیلد | مقدار |
|------|------|
| **وضعیت تأیید** | ❓ نیازمند آزمون عملی |
| **وضعیت اصلاح** | ⏳ در صف |

README می‌گوید «Visual Seat Editor» و «Dynamic Pricing» وجود دارد. کد یک ویرایشگر grid-based ساده دارد (نه drag-and-drop). باید در محیط ادمین تست شود تا میزان کارکردش مشخص شود.

### R-2: رفتار دقیق ضرب قیمت
| فیلد | مقدار |
|------|------|
| **وضعیت تأیید** | ❓ نیازمند آزمون عملی |
| **وضعیت اصلاح** | وابسته به P0-1 |

باگ P0-1 تأیید شده (ثبت دوباره هوک)، اما اثر دقیق آن (آیا واقعاً ۳ برابر می‌شود یا ووکامرس جلوگیری می‌کند؟) نیازمند تست اجرایی است.

### R-3: آیا فایل‌های _old واقعاً مرده‌اند؟
| فیلد | مقدار |
|------|------|
| **وضعیت تأیید** | ✅ تأییدشده با شواهد (۰ ارجاع مستقیم) |
| **وضعیت اصلاح** | ⏳ در صف (با احتیاط) |

`grep -rn` نشان داد هیچ ارجاع مستقیمی به `bookings_old`، `reservations_old`، `reports_old`، `settings_old`، `seat-selector_old` وجود ندارد. اما قبل از حذف، باید صفحات ادمین تست شوند (ممکن است include پویا باشد).

---

## خلاصه اولویت‌بندی فاز ۰

| ترتیب | ID | شدت | زمان تخمینی |
|------|----|----|------------|
| ۱ | P0-1 | 🔴 بحرانی پولی | ۳۰ دقیقه |
| ۲ | P0-3 | 🔴 بحرانی پولی | ۱-۲ ساعت |
| ۳ | P0-4 | 🟠 بحرانی عملکردی | ۳۰ دقیقه |
| ۴ | P0-5 | 🔴 بحرانی پولی | ۲-۳ ساعت |
| ۵ | P0-6 | 🟠 بحرانی عملکردی | ۱ ساعت |
| ۶ | P0-2 | 🟠 بحرانی عملکردی | ۳۰ دقیقه (حذف موقت) |
| ۷ | P0-9 | 🟡 متوسط | ۱۵ دقیقه |
| ۸ | P0-8 | 🟡 متوسط | ۳۰ دقیقه |
| ۹ | P0-7 | 🟡 متوسط | ۱۵ دقیقه (حذف موقت منو) |

**مجموع تخمینی فاز ۰:** ۷-۹ ساعت کد + تست

---

## تغییرات سند

| تاریخ | تغییر |
|------|------|
| ۲۰۲۵-۰۱ | ایجاد سند با ۹ باگ P0 + ۲ باگ P1 + ۳ ریسک P2 + ۳ ریسک نیازمند آزمون |
