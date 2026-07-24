# SERVORA — مستند رسمی معماری پایگاه داده
**نسخه:** 2.0.0 | **موتور:** MySQL 8.0.16+ InnoDB | **کاراکترست:** utf8mb4 / utf8mb4_persian_ci

> این مستند برای تحویل سیستم به Senior Backend Engineer یا AI جدید نوشته شده است.
> تمام تصمیمات معماری، دلایل آن‌ها، و هشدارهای production-level در اینجا پوشش داده شده‌اند.

---

# 1. فلسفه طراحی و معماری کلان

## 1.1 اصل اول: Zero Runtime JOIN

تمام جداول transactional این سیستم (appointments, queue, reviews, audit_log, notifications, business_verification) بر اساس یک اصل محوری طراحی شده‌اند:

> **هیچ TRIGGER، STORED PROCEDURE یا FUNCTION در مسیر critical نباید در زمان اجرا JOIN بزند.**

این انتخاب یک trade-off آگاهانه است نه یک shortcut. دلایل:

### چرا JOIN در مسیر transactional خطرناک است

**1. Lock Escalation:**
وقتی یک trigger یا SP به چندین جدول JOIN می‌زند، InnoDB باید روی تمام آن جداول lock بگیرد. در یک سیستم booking با تراکم بالا، این یعنی هر INSERT روی appointments می‌تواند lock روی `users`, `businesses`, و `services` بگیرد — جداولی که هزاران session دیگر هم می‌خوانند. نتیجه: deadlock escalation.

**2. TOCTOU (Time-of-Check to Time-of-Use):**
اگر SP ابتدا از `users` اطلاعات می‌خواند و سپس INSERT می‌کند، بین این دو لحظه ممکن است user حذف یا غیرفعال شود. snapshot راه‌حل این مسئله است.

**3. Performance Predictability:**
یک INSERT با snapshot fields همیشه O(1) است. یک INSERT که به 3 جدول JOIN می‌زند، بسته به buffer pool وضعیت، ممکن است 0.5ms یا 50ms طول بکشد.

**4. Trigger Recursion Risk:**
اگر trigger A روی جدول X، در جدول Y می‌نویسد و trigger B روی Y هم به X رجوع می‌کند، حلقه recursion شکل می‌گیرد. snapshot این ریسک را از بین می‌برد.

## 1.2 اصل دوم: Business Logic فقط در Database Layer

تمام business logic در TRIGGERS، STORED PROCEDURES و FUNCTIONS قرار دارد. PHP backend فقط:
1. authentication/authorization انجام می‌دهد
2. CALL SP را صدا می‌زند
3. result_code را به frontend برمی‌گرداند

این معماری یعنی:
- اگر فردا backend از PHP به Go تغییر کند، هیچ business logic‌ای از دست نمی‌رود
- اگر یک developer مستقیم INSERT بزند (بدون SP)، trigger‌ها آن را کنترل می‌کنند
- تمام audit trail توسط database خودش نوشته می‌شود، نه application layer

## 1.3 اصل سوم: Jalali-Only، No Gregorian

تمام تاریخ‌ها به صورت `CHAR(10)` با فرمت `YYYY/MM/DD` و زمان‌ها `CHAR(5)` با فرمت `HH:MM` ذخیره می‌شوند. هیچ ستون DATETIME/DATE برای تاریخ شمسی وجود ندارد. دلایل:
- MySQL DATE arithmetic روی تاریخ شمسی معنا ندارد
- مقایسه string lexicographic برای `CHAR(10)` با فرمت `YYYY/MM/DD` دقیقاً مثل مقایسه عدد عمل می‌کند (تاریخ بزرگ‌تر = string بزرگ‌تر)
- هیچ conversion overhead وجود ندارد

## 1.4 اصل چهارم: همه وضعیت‌ها به فارسی

تمام ENUM values، پیام‌های خطا، و متن audit log به فارسی است. این برای:
- یکپارچگی با frontend فارسی
- خوانایی مستقیم audit log بدون نیاز به mapping
- collation صحیح: `utf8mb4_persian_ci`

---

# 2. نقشه جامع جداول و ارتباط منطقی

## 2.1 دیاگرام ارتباطی (Logical, not FK-based)

```
users
  ├── businesses (owner_user_id → users.id [logical])
  │     ├── services (business_id → businesses.id [logical])
  │     ├── business_slots (business_id → businesses.id [logical])
  │     └── business_verification (business_id → businesses.id [logical])
  │
  ├── appointments (user_id → users.id, business_id → businesses.id,
  │     │           service_id → services.id [all logical])
  │     └── reviews (appointment_id → appointments.id [logical UNIQUE])
  │
  ├── queue (user_id → users.id, business_id → businesses.id [logical])
  │
  ├── notifications (user_id → users.id [logical])
  │
categories
  └── businesses (category_id, subcategory_id → categories.id [logical])

audit_log (entity_type + entity_id → any table [polymorphic])
```

**نکته مهم:** هیچ FOREIGN KEY constraint فیزیکی در دیتابیس وجود ندارد. تمام روابط "logical" هستند — یعنی referential integrity توسط SP‌ها و trigger‌ها enforce می‌شود، نه توسط MySQL FK. این انتخاب برای:
- حذف overhead FK check در هر INSERT/UPDATE/DELETE
- انعطاف برای soft-delete (user غیرفعال شود بدون cascade)
- آزادی در data migration بدون FK violation

**هزینه:** باید در SP‌ها manually validation کرد. این هزینه پرداخت شده است (نگاه کنید به CreateAppointment validation steps).

## 2.2 گروه‌بندی جداول بر اساس نقش

| گروه | جداول | ویژگی |
|------|-------|--------|
| Core Identity | users, categories | کم‌تغییر، خوانده می‌شوند |
| Business Domain | businesses, services, business_slots | متوسط تغییر |
| Transactional | appointments, queue | پر تغییر، concurrency critical |
| Post-Transaction | reviews | append-mostly |
| Administrative | business_verification | low volume |
| System | audit_log, notifications | append-only, high volume |

---

# 3. تحلیل کامل جداول

## 3.1 جدول `users`

### هدف
هویت تمام actors سیستم: مشتریان، صاحبان کسب‌وکار، و ادمین‌ها. یک جدول برای همه نقش‌ها (Single Table Inheritance).

### ستون‌های کلیدی

**`role ENUM('مشتری','کسب‌وکار','ادمین')`**
Single Table Inheritance — یک جدول برای سه نوع کاربر. مزیت: query ساده. معایب: اگر در آینده نقش‌ها property‌های خیلی متفاوتی پیدا کنند، جدول bloat می‌شود.

**`phone VARCHAR(11)`**
به عنوان identifier اصلی عمل می‌کند (UNIQUE). در ایران، شماره موبایل ۱۱ رقمی (با ۰) مثل `09121234567` است.

**`password_hash VARCHAR(255)`**
طول ۲۵۵ برای future-proof بودن: bcrypt ≈ 60 کاراکتر، Argon2 ≈ 95+ کاراکتر.

**`is_active TINYINT(1)`**
Soft delete. کاربر حذف نمی‌شود، غیرفعال می‌شود. این برای audit trail ضروری است — appointments قدیمی همیشه به user_id اشاره می‌کنند.

### ایندکس‌ها

- **`uq_users_phone`**: login به جای email از phone استفاده می‌کند — UNIQUE برای authentication
- **`idx_users_role`**: فیلتر کردن ادمین‌ها یا کسب‌وکارها
- **`idx_users_active`**: `WHERE is_active = 1` در تمام validation queries

### ستون‌های Denormalized در جداول دیگر
`users.full_name` و `users.phone` در تمام جداول transactional snapshot می‌شوند به عنوان `user_name`, `user_phone`. دلیل: اگر کاربری نام خود را تغییر دهد، نوبت‌های قدیمی باید نام قدیمی را نشان دهند (historical accuracy).

### خطرات
- **Phone change:** اگر کاربر شماره خود را عوض کند، snapshot‌های قدیمی phone قدیمی دارند. این intentional است (historical record) اما باید به support team توضیح داده شود.
- **No email:** اگر در آینده email authentication اضافه شود، نیاز به migration دارد.

---

## 3.2 جدول `categories`

### هدف
درختواره دسته‌بندی کسب‌وکارها با پشتیبانی از سلسله‌مراتب دو سطحی (دسته و زیردسته).

### ستون‌های کلیدی

**`parent_id INT UNSIGNED NULL`**
Self-referencing برای ساختار درختی. NULL یعنی دسته اصلی. در این سیستم، فقط دو سطح استفاده می‌شود (category و subcategory). اگر نیاز به بیش از دو سطح باشد، باید به Closure Table یا Nested Sets تبدیل شود.

### نکته مهم
`category_name` و `subcategory_name` در `businesses` و `services` snapshot می‌شوند. این یعنی تغییر نام یک دسته، کسب‌وکارهای قدیمی را update نمی‌کند. اگر این مطلوب نیست، باید یک UPDATE trigger روی categories اضافه شود.

### خطر بالقوه — Stale Snapshots
```sql
-- اگر این query اجرا شود:
UPDATE categories SET name = 'آرایشگاه زنانه' WHERE id = 5;
-- businesses.category_name هنوز نام قدیمی دارد!

-- Fix پیشنهادی:
CREATE TRIGGER trg_categories_after_update
AFTER UPDATE ON categories FOR EACH ROW
BEGIN
    IF OLD.name != NEW.name THEN
        UPDATE businesses SET category_name = NEW.name
        WHERE category_id = NEW.id;
        UPDATE businesses SET subcategory_name = NEW.name
        WHERE subcategory_id = NEW.id;
        UPDATE services SET category_name = NEW.name
        WHERE category_name = OLD.name;
    END IF;
END;
```

---

## 3.3 جدول `businesses`

### هدف
پروفایل کامل هر کسب‌وکار. مهم‌ترین جدول از نظر query load — اکثر صفحات frontend از این جدول می‌خوانند.

### ستون‌های کلیدی

**`owner_name, owner_phone VARCHAR`**
Snapshot از `users` در زمان ثبت کسب‌وکار. اگر صاحب کسب‌وکار نامش را عوض کند، نمایش کسب‌وکار تغییر نمی‌کند (مگر با update صریح). این یک trade-off است: historical accuracy vs. real-time accuracy.

**`category_name, subcategory_name VARCHAR`**
Snapshot از `categories`. دلیل: query جستجو می‌تواند مستقیماً `WHERE category_name = 'آرایشگاه'` بزند بدون JOIN به categories.

**`rating_avg DECIMAL(3,2), rating_sum DECIMAL(10,2), rating_count INT UNSIGNED`**
این سه ستون با هم یک incremental aggregation engine می‌سازند. معماری:
- هر `INSERT` روی reviews: trigger این سه را به‌روز می‌کند → O(1)
- هر `UPDATE` روی reviews: trigger با فرمول differential آپدیت می‌کند → O(1)
- هر `DELETE` روی reviews: trigger با GREATEST guard کم می‌کند → O(1)
- `CalcBusinessRating()`: فقط `rating_sum / rating_count` از PK می‌خواند → O(1)

**در مقابل روش naive:**
```sql
-- روش naive — O(n) با هر query:
SELECT AVG(rating) FROM reviews
WHERE business_id = ? AND is_visible = 1;
-- با 100,000 نظر: full scan روی reviews table
```

**`total_reviews INT UNSIGNED`**
شمارنده کل نظرها (visible + hidden). برای نمایش "۲۳۴ نظر" در UI. جداگانه از rating_count چون rating_count فقط visible‌ها را می‌شمارد.

### ایندکس‌ها — تحلیل کامل

```sql
KEY idx_biz_search_cat    (is_verified, is_active, category_name,    rating_avg)
KEY idx_biz_search_subcat (is_verified, is_active, subcategory_name, rating_avg)
KEY idx_biz_search_gender (is_verified, is_active, gender_type,      rating_avg)
KEY idx_biz_owner_active  (owner_user_id, is_active)
FULLTEXT KEY ft_businesses_name (name)
```

**`idx_biz_search_cat`:** برای query اصلی جستجو:
```sql
SELECT * FROM businesses
WHERE is_verified = 1 AND is_active = 1
  AND category_name = 'آرایشگاه'
ORDER BY rating_avg DESC;
```
ترتیب ستون‌ها در composite index اهمیت دارد: ابتدا ستون‌های equality filter، سپس ستون range/order. `is_verified=1 AND is_active=1` cardinality پایین دارند (معمولاً اکثر businesses active/verified هستند) اما باید اول باشند چون prefix selectivity را تعریف می‌کنند. `rating_avg` آخر می‌آید چون ORDER BY را بدون filesort پشتیبانی می‌کند.

**`ft_businesses_name`:** FULLTEXT برای جستجوی آزاد. MySQL FULLTEXT با `utf8mb4_persian_ci` از چپ به راست tokenize می‌کند. محدودیت: کلمات زیر ۳ کاراکتر (ft_min_word_len) index نمی‌شوند.

### خطرات
- **rating_sum overflow:** `DECIMAL(10,2)` حداکثر ۹۹۹۹۹۹۹۹۹۹.۹۹ را نگه می‌دارد. با rating حداکثر ۵ و DECIMAL(10,2)، این یعنی ۲۰ میلیارد نظر قبل از overflow — در عمل ریسکی نیست.
- **Stale snapshots:** اگر صاحب کسب‌وکار عوض شود، `owner_name` و `owner_phone` در businesses قدیمی می‌شوند. نیاز به trigger یا SP صریح برای update.

---

## 3.4 جدول `services`

### هدف
فهرست خدمات هر کسب‌وکار با قیمت و مدت زمان.

### ستون‌های کلیدی

**`business_name VARCHAR(200)`**
Snapshot از `businesses.name`. در trigger‌های queue promotion، کل context نوبت جدید از queue می‌آید که این snapshot را دارد — بدون JOIN.

**`duration_minutes SMALLINT UNSIGNED`**
مدت زمان خدمت. در `appointments` و `queue` snapshot می‌شود چون قیمت و مدت ممکن است بعداً تغییر کنند اما نوبت‌های قبلی باید مدت اصلی را نشان دهند.

**`price DECIMAL(12,0)`**
قیمت به ریال. DECIMAL(12,0) یعنی بدون اعشار — چون ریال واحد صحیح است. حداکثر ۹۹۹,۹۹۹,۹۹۹,۹۹۹ ریال (حدود ۱۰۰ میلیون تومان) که برای هر خدمتی کافی است.

### ایندکس‌ها

```sql
KEY idx_svc_biz_active  (business_id, is_active)
KEY idx_svc_biz_gender  (business_id, gender_type, is_active)
KEY idx_services_category (category_name)
FULLTEXT KEY ft_services_name (service_name)
```

**چرا `idx_services_business(business_id)` حذف شد:**
این ایندکس یک prefix subset از `idx_svc_biz_active` بود. MySQL می‌تواند از `idx_svc_biz_active` برای `WHERE business_id = ?` استفاده کند (فقط از prefix ایندکس). نگه داشتن هر دو = دو بار نوشتن در هر INSERT بدون فایده.

---

## 3.5 جدول `appointments` — قلب سیستم

### هدف
ثبت تمام نوبت‌های رزرو شده. این جدول بیشترین write و read load را دارد.

### معماری Slot Locking — تحلیل عمیق

مشکل اصلی: چطور از double-booking جلوگیری کنیم بدون SELECT FOR UPDATE قبل از هر INSERT؟

**راه‌حل: Generated Column + UNIQUE INDEX**

```sql
slot_lock_key VARCHAR(55)
    GENERATED ALWAYS AS (
        CASE WHEN status = 'لغو شده'
             THEN NULL
             ELSE CONCAT(CAST(business_id AS CHAR), '|', date_shamsi, '|', time_slot)
        END
    ) STORED,
UNIQUE KEY uq_slot_active (slot_lock_key)
```

**چرا این کار می‌کند:**
- وقتی `status != 'لغو شده'`: مقدار مثل `42|1403/01/15|09:00` است — UNIQUE constraint جلوگیری می‌کند
- وقتی `status = 'لغو شده'`: مقدار NULL است — در MySQL، NULL != NULL در UNIQUE index؛ یعنی می‌توان چند NULL داشت
- نتیجه: یک slot فقط یک نوبت فعال دارد، اما بعد از لغو، همان slot می‌تواند دوباره رزرو شود

**چرا STORED است نه VIRTUAL:**
STORED یعنی مقدار فیزیکاً در disk ذخیره می‌شود و ایندکس روی مقدار واقعی است. VIRTUAL یعنی هر بار محاسبه می‌شود — برای UNIQUE INDEX، STORED اجباری است در MySQL 8.

**لایه دوم: CONTINUE HANDLER در CreateAppointment:**
اگر UNIQUE violation (errno 1062) رخ دهد، MySQL خطا را به handler می‌فرستد که `v_dup_slot = 1` می‌گذارد. بعد از INSERT، SP این flag را چک می‌کند و result_code=6 (پیام فارسی) برمی‌گرداند.

### ستون‌های Snapshot

| ستون | منبع | دلیل snapshot |
|------|------|---------------|
| `user_name` | users.full_name | تاریخچه دقیق، حتی اگر کاربر نام عوض کند |
| `user_phone` | users.phone | برای پیامک/تماس مستقیم بدون lookup |
| `business_name` | businesses.name | نمایش در تاریخچه بدون JOIN |
| `service_name` | services.service_name | نمایش نام خدمت در زمان رزرو |
| `duration_minutes` | services.duration_minutes | خدمت ممکن است مدتش تغییر کند |
| `price` | services.price | قیمت در لحظه رزرو — اهمیت مالی |
| `cancelled_by` | p_cancelled_by | چه کسی لغو کرد — برای audit |
| `cancelled_at` | NOW() | زمان دقیق لغو |

### Status State Machine

```
در انتظار ──→ تایید شده ──→ انجام شده
    │               │
    └──→ لغو شده ←──┘

لغو شده: TERMINAL (هیچ transition مجاز نیست)
انجام شده: TERMINAL (هیچ transition مجاز نیست)
```

این state machine در `trg_appointments_before_update` enforce می‌شود. اگر کسی بخواهد مستقیم `UPDATE appointments SET status='در انتظار' WHERE ...` بزند، SIGNAL SQLSTATE '45000' می‌گیرد.

### ایندکس‌ها — تحلیل

```sql
UNIQUE KEY uq_slot_active        (slot_lock_key)
KEY idx_appt_date        (date_shamsi)
KEY idx_appt_status      (status)
KEY idx_appt_biz_active  (business_id, date_shamsi)
KEY idx_appt_status_date (business_id, status, date_shamsi)
KEY idx_appt_user_date   (user_id, date_shamsi)
```

**`idx_appt_biz_active`:** برای نمایش تقویم کسب‌وکار:
```sql
SELECT * FROM appointments
WHERE business_id = ? AND date_shamsi = '1403/01/15';
```

**`idx_appt_status_date`:** برای dashboard ادمین:
```sql
SELECT * FROM appointments
WHERE business_id = ? AND status = 'در انتظار'
ORDER BY date_shamsi;
```

**`idx_appt_user_date`:** برای صفحه "نوبت‌های من":
```sql
SELECT * FROM appointments
WHERE user_id = ? ORDER BY date_shamsi DESC;
```

### CHECK Constraints

```sql
CONSTRAINT chk_appt_date CHECK (date_shamsi REGEXP '^[0-9]{4}/[0-9]{2}/[0-9]{2}$')
CONSTRAINT chk_appt_time CHECK (time_slot   REGEXP '^[0-9]{2}:[0-9]{2}$')
```

این constraints در MySQL 8.0.16+ اجرا می‌شوند. نکته: REGEXP در CHECK constraint پشتیبانی از کاراکترهای فارسی در مقدار ندارد (چک فرمت عدد است نه محتوا).

---

## 3.6 جدول `queue` — سیستم صف انتظار

### هدف
وقتی یک slot پر است، کاربران می‌توانند در صف انتظار بنشینند. اگر نوبت لغو شود، اولین نفر صف اتوماتیک promote می‌شود.

### معماری Position-Based Queue

هر entry در صف یک `position` دارد (1، 2، 3...). وقتی کسی promote می‌شود:
1. status آن entry به `پذیرفته شده` تغییر می‌کند
2. position تمام ردیف‌های بعدی یک واحد کم می‌شود

این یعنی position همیشه consecutive است و بدون gap.

### ستون‌های کلیدی

**`duration_minutes, price`**
Snapshot از `services`. این‌ها در زمان `AddToQueue` ذخیره می‌شوند. دلیل: وقتی کاربر promote می‌شود، trigger با `INSERT INTO appointments ... SELECT ... FROM queue WHERE id = v_queue_id` یک نوبت می‌سازد — تمام اطلاعات از queue می‌آید، بدون JOIN به services.

**`status ENUM('در انتظار','اطلاع داده شده','پذیرفته شده','منقضی شده')`**
- `در انتظار`: در صف است، slot هنوز پر است
- `اطلاع داده شده`: کسب‌وکار یا سیستم به او اطلاع داده (pre-notification)
- `پذیرفته شده`: promote شد، نوبت ساخته شده
- `منقضی شده`: تاریخ گذشت و هنوز promote نشد

### ایندکس‌های کلیدی

```sql
UNIQUE KEY uq_queue_user_slot (business_id, date_shamsi, time_slot, user_id)
KEY idx_queue_promo      (business_id, date_shamsi, time_slot, status, position)
```

**`uq_queue_user_slot`:**
Hard constraint — یک کاربر نمی‌تواند دو بار برای یک slot در صف باشد. این constraint در AddToQueue هم با `LOCK IN SHARE MODE` check می‌شود (دو لایه محافظت).

**`idx_queue_promo`:**
این ایندکس covering index است برای دقیقاً این query در trigger ارتقای صف:
```sql
SELECT id, user_id, user_phone, service_name, business_name
FROM queue
WHERE business_id = ? AND date_shamsi = ? AND time_slot = ?
  AND status = 'در انتظار'
ORDER BY position ASC
LIMIT 1
FOR UPDATE;
```
تمام ستون‌های WHERE و ORDER BY در این ایندکس هستند → index-only scan تا جایی که FOR UPDATE نیاز به row lock دارد.

---

## 3.7 جدول `reviews`

### هدف
نظرات و امتیازات کاربران پس از انجام نوبت.

### ستون‌های کلیدی

**`appointment_id BIGINT UNSIGNED`**
UNIQUE KEY — هر نوبت فقط یک نظر می‌تواند داشته باشد. این constraint در schema enforce می‌شود، نه در application.

**`service_id, service_name, date_shamsi`**
Snapshot از `appointments`. وقتی کاربر نظر می‌دهد، می‌خواهیم بدانیم برای کدام خدمت و در چه تاریخی بوده — بدون JOIN به appointments.

**`is_visible TINYINT(1)`**
کنترل نمایش. نظرات می‌توانند hidden شوند (اسپم، مشکل‌دار). وقتی `is_visible` تغییر می‌کند، trigger incremental aggregation را اجرا می‌کند.

**`CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5)`**
Database-level validation. PHP می‌تواند validation نکند — database enforce می‌کند.

### رابطه با businesses (Incremental Aggregation)

هر تغییر در reviews (insert/update/delete) به تغییر atomik سه ستون در businesses منجر می‌شود:
```
reviews event → trigger → UPDATE businesses SET rating_sum, rating_count, total_reviews, rating_avg
```

این coupling intentional و بهینه است:
- بدون این coupling: هر بار نمایش rating نیاز به AVG scan دارد
- با این coupling: rating همیشه pre-computed و آماده است

---

## 3.8 جدول `business_verification`

### هدف
فرایند KYC (Know Your Customer) برای تایید هویت کسب‌وکارها.

### ستون‌های کلیدی

**`owner_user_id, owner_phone`**
Snapshot از `businesses`. دلیل: `VerifyBusiness()` SP باید notification به owner بفرستد. قبلاً یک JOIN به businesses بود. حالا این اطلاعات اینجا denormalized است.

**`reviewed_by BIGINT UNSIGNED NULL`**
NULL یعنی هنوز بررسی نشده. وقتی SP کار می‌کند، این را با admin ID پر می‌کند قبل از COMMIT — تا trigger بتواند `NEW.reviewed_by` را در audit log بنویسد.

**`status ENUM('در انتظار','تایید شده','رد شده')`**
یک فرآیند linear است: همیشه از 'در انتظار' شروع می‌شود و نهایتاً 'تایید شده' یا 'رد شده' می‌شود.

### Race Condition موجود (Known Issue)

```sql
-- در VerifyBusiness():
SELECT ... FROM business_verification WHERE id=? LIMIT 1;
-- ← یک admin در اینجا
IF v_cur_status != 'در انتظار' THEN LEAVE; END IF;
-- ← admin دیگری هم می‌تواند اینجا باشد!
START TRANSACTION;
    UPDATE business_verification SET status = ? WHERE id = ?;
COMMIT;
```

اگر دو ادمین همزمان یک درخواست را تایید کنند، هر دو از guard رد می‌شوند. Fix پیشنهادی:

```sql
-- Fix: SELECT باید داخل TX و با FOR UPDATE باشد
START TRANSACTION;
    SELECT business_id, status, business_name, owner_user_id, owner_phone
    INTO   v_business_id, v_cur_status, v_biz_name, v_biz_owner_id, v_owner_phone
    FROM   business_verification
    WHERE  id = p_verification_id
    FOR UPDATE;

    IF v_cur_status IS NULL THEN
        ROLLBACK;
        SET p_result_code = 1; SET p_result_msg = 'درخواست یافت نشد';
        LEAVE VerifyBusiness;
    END IF;
    IF v_cur_status != 'در انتظار' THEN
        ROLLBACK;
        SET p_result_code = 2; SET p_result_msg = 'این درخواست قبلاً بررسی شده است';
        LEAVE VerifyBusiness;
    END IF;
    UPDATE business_verification SET status=p_new_status, ...;
    ...
COMMIT;
```

---

## 3.9 جدول `audit_log`

### هدف
Log append-only از تمام عملیات سیستم. هیچ‌گاه آپدیت نمی‌شود.

### طراحی کلیدی

**`action VARCHAR(30)` نه ENUM:**
اگر ENUM بود و بخواهیم action جدیدی اضافه کنیم، نیاز به `ALTER TABLE audit_log MODIFY action ENUM(...)` داریم. این روی جداول میلیون‌ردیفی متوقف‌کننده است. VARCHAR(30) این مشکل را ندارد.

**`performed_by BIGINT UNSIGNED NULL`:**
NULL یعنی عملیات سیستمی (توسط trigger، نه user). مثلاً queue promotion.

**`entity_type + entity_id`:**
Polymorphic reference — هر entity از هر جدولی می‌تواند در audit_log داشته باشد.

### ایندکس‌ها

```sql
KEY idx_audit_entity  (entity_type, entity_id)  -- "تمام لاگ‌های نوبت X"
KEY idx_audit_action  (action)                   -- "تمام لغوها"
KEY idx_audit_created (created_at)               -- "لاگ‌های امروز"
KEY idx_audit_who     (performed_by)             -- "تمام اقدامات admin Y"
```

### Partitioning پیشنهادی برای Scale

```sql
-- وقتی audit_log به 100M+ ردیف رسید:
ALTER TABLE audit_log
PARTITION BY RANGE (TO_DAYS(created_at)) (
    PARTITION p_2026_q1 VALUES LESS THAN (TO_DAYS('2026-04-01')),
    PARTITION p_2026_q2 VALUES LESS THAN (TO_DAYS('2026-07-01')),
    PARTITION p_future  VALUES LESS THAN MAXVALUE
);
```

---

## 3.10 جدول `business_slots`

### هدف
ساعات کاری منظم هر کسب‌وکار. این جدول template است، نه رزرو واقعی.

### ستون‌های کلیدی

**`day_of_week TINYINT (0=شنبه...6=جمعه)`**
استاندارد ایرانی: شنبه = 0. باید در documentation واضح باشد تا توسعه‌دهنده PHP اشتباه نکند.

**`max_capacity TINYINT UNSIGNED`**
اگر یک slot بتواند چند نفر را همزمان بپذیرد (مثل کلاس ورزشی). فعلاً این سیستم برای capacity=1 طراحی شده (تک نوبت). اگر capacity > 1 شود، `uq_slot_active` باید تغییر کند.

### خطر مهم
این جدول هیچ ارتباطی به appointments ندارد. وقتی کاربر می‌خواهد رزرو کند، باید از `business_slots` اسلات‌های مجاز را بخواند و از `appointments` چک کند کدام پر هستند. این منطق در frontend/backend است، نه database. اگر این coordination گم شود، کاربر می‌تواند برای slot‌ای که در business_slots نیست رزرو کند.

**پیشنهاد:** یک CHECK در CreateAppointment SP اضافه شود که بررسی کند slot در business_slots وجود دارد.

---

## 3.11 جدول `notifications`

### هدف
پیام‌های سیستمی به کاربران. این جدول read-heavy است (polling/push).

### ستون‌های کلیدی

**`type ENUM('رزرو_موفق','لغو_نوبت','یادآوری','ارتقا_صف','تایید_کسب‌وکار','رد_کسب‌وکار')`**

**نقص موجود:** ENUM مقدار `'ثبت_صف'` را ندارد. در `AddToQueue()` هنگام ثبت در صف، notification با type=`'ارتقا_صف'` ارسال می‌شود — که semantically اشتباه است. `'ارتقا_صف'` باید فقط وقتی کسی از صف به نوبت ارتقا پیدا می‌کند استفاده شود.

**Fix:**
```sql
ALTER TABLE notifications
MODIFY type ENUM(
    'رزرو_موفق',
    'لغو_نوبت',
    'یادآوری',
    'ثبت_صف',
    'ارتقا_صف',
    'تایید_کسب‌وکار',
    'رد_کسب‌وکار'
) NOT NULL DEFAULT 'رزرو_موفق';

-- سپس در AddToQueue() notification type را تغییر دهید:
-- 'ارتقا_صف' → 'ثبت_صف'
```

**`related_entity_type, related_entity_id`**
Deep link برای frontend. وقتی کاربر روی notification کلیک می‌کند، frontend می‌داند به کجا navigate کند:
```typescript
if (notification.related_entity_type === 'appointments') {
    router.push(`/appointments/${notification.related_entity_id}`);
}
```

**`user_phone VARCHAR(11)`**
Snapshot برای SMS gateway. سیستم می‌تواند بدون JOIN به users، SMS بفرستد.

### ایندکس‌ها

```sql
KEY idx_notif_user_date   (user_id, created_at)         -- صفحه اعلان‌ها
KEY idx_notif_user_unread (user_id, is_read, created_at) -- badge count
KEY idx_notif_type        (type)                         -- فیلتر نوع
```

**`idx_notif_user_unread`:** برای این query:
```sql
SELECT COUNT(*) FROM notifications
WHERE user_id = ? AND is_read = 0;
-- با این ایندکس: index scan فقط روی unread ردیف‌ها
```

---

# 4. Functions — تحلیل کامل

## 4.1 `GetNextQueuePosition()`

### دقیقاً چه می‌کند
MAX position در صف فعال برای یک slot را برمی‌گرداند و +1 می‌کند. اگر صف خالی باشد، 1 برمی‌گرداند.

```sql
SELECT COALESCE(MAX(position), 0) INTO v_max_pos
FROM queue
WHERE business_id = p_business_id AND date_shamsi = p_date_shamsi
  AND time_slot = p_time_slot
  AND status IN ('در انتظار', 'اطلاع داده شده');
RETURN v_max_pos + 1;
```

### چرا ساخته شده
برای استفاده احتمالی در backend یا SP‌های دیگر. در حال حاضر، `AddToQueue()` این محاسبه را inline انجام می‌دهد (با FOR UPDATE) — این function به تنهایی thread-safe نیست.

### خطر Concurrency — مهم

**این function اگر خارج از transaction با FOR UPDATE صدا زده شود، race condition دارد:**

```sql
-- Session A: CALL GetNextQueuePosition(1, '1403/01/15', '09:00') → 3
-- Session B: CALL GetNextQueuePosition(1, '1403/01/15', '09:00') → 3 ← هر دو 3 می‌گیرند!
-- Session A: INSERT INTO queue (..., position=3)
-- Session B: INSERT INTO queue (..., position=3) ← duplicate position!
```

**در `AddToQueue()`، این مشکل با FOR UPDATE حل شده:**
```sql
SELECT COALESCE(MAX(position), 0) + 1 INTO v_next_pos
FROM queue WHERE ... FOR UPDATE;
```

اما اگر `GetNextQueuePosition()` مستقیم از PHP صدا زده شود، thread-safe نیست. این باید در documentation واضح باشد.

### NOT DETERMINISTIC چرا
MySQL از این keyword برای binlog-based replication استفاده می‌کند. DETERMINISTIC یعنی همیشه با همان input، همان output. این function از `queue` می‌خواند که تغییر می‌کند — پس NOT DETERMINISTIC است. اگر اشتباه DETERMINISTIC گذاشته شود، replication ممکن است skip کند.

---

## 4.2 `IsSlotAvailable()`

### دقیقاً چه می‌کند
چک می‌کند آیا یک time slot برای یک business در یک تاریخ خالی است.

```sql
SELECT COUNT(*) INTO v_count FROM appointments
WHERE business_id = ? AND date_shamsi = ? AND time_slot = ?
  AND status != 'لغو شده';
RETURN IF(v_count = 0, 1, 0);
```

### نکته مهم
این function برای pre-check در UI استفاده می‌شود (نمایش slot‌های available). اما **نباید** به عنوان slot reservation مکانیزم استفاده شود. دلیل: بین `IsSlotAvailable()` و `CreateAppointment()` ممکن است کاربر دیگری slot را بگیرد (TOCTOU). مکانیزم واقعی slot locking همان `uq_slot_active` است.

### Performance
این query روی `idx_appt_biz_active (business_id, date_shamsi)` run می‌کند، بعد `status != 'لغو شده'` فیلتر می‌کند. برای یک کسب‌وکار با ۵۰۰ نوبت در یک تاریخ، این یک index scan محدود است.

---

## 4.3 `CalcBusinessRating()`

### دقیقاً چه می‌کند
میانگین امتیاز یک کسب‌وکار را برمی‌گرداند.

```sql
SELECT COALESCE(ROUND(rating_sum / NULLIF(rating_count, 0), 2), 0.00)
INTO v_avg FROM businesses WHERE id = p_business_id;
RETURN v_avg;
```

### چرا این معماری برتر است

**روش قدیمی (O(n)):**
```sql
SELECT AVG(rating) FROM reviews
WHERE business_id = ? AND is_visible = 1;
-- با 10,000 نظر: full scan
```

**روش جدید (O(1)):**
```sql
SELECT rating_sum / rating_count FROM businesses WHERE id = ?;
-- یک PK lookup — بدون توجه به تعداد نظرات
```

با 1 میلیون کسب‌وکار که هر کدام ۱۰۰۰ نظر دارند، این تفاوت بین 1ms و 100ms per query است.

### `NULLIF(rating_count, 0)`
اگر هیچ نظر visible‌ای نباشد، `rating_count = 0`. تقسیم بر صفر → NULL. `COALESCE(NULL, 0.00)` → 0.00. این زنجیره از division-by-zero crash جلوگیری می‌کند.

---

# 5. Stored Procedures — تحلیل کامل

## 5.1 `WriteAuditLog()`

### هدف
**Single Point of Entry برای تمام audit log writes.**

```sql
CREATE PROCEDURE WriteAuditLog(
    IN p_entity_type  VARCHAR(60),
    IN p_entity_id    BIGINT UNSIGNED,
    IN p_action       VARCHAR(30),
    IN p_description  TEXT,
    IN p_performed_by BIGINT UNSIGNED
)
BEGIN
    INSERT INTO audit_log (entity_type, entity_id, action, description, performed_by)
    VALUES (p_entity_type, p_entity_id, p_action, p_description, p_performed_by);
END
```

### چرا این pattern مهم است
قبل از این refactoring، هر trigger مستقیم `INSERT INTO audit_log` می‌زد. این یعنی ۱۲ مکان مختلف با formatting متفاوت. اگر schema audit_log عوض شود (مثلاً `ip_address` بخواهد از جایی بیاید)، باید ۱۲ trigger تغییر کند. حالا فقط `WriteAuditLog` تغییر می‌کند.

### Transaction Context
این SP داخل transaction caller اجرا می‌شود. اگر trigger آن را صدا بزند، INSERT داخل همان transaction است. اگر transaction rollback شود، audit INSERT هم rollback می‌شود. این **intentional** است — نمی‌خواهیم audit log یک عملیات ناموفق را ثبت کند.

---

## 5.2 `CreateAppointment()`

### Flow کامل اجرا

```
ورودی: user_id, business_id, service_id, date_shamsi, time_slot
خروجی: result_code, result_msg, appointment_id

1. DECLARE handlers:
   - CONTINUE HANDLER FOR 1062: v_dup_slot = 1
   - EXIT HANDLER FOR SQLEXCEPTION: ROLLBACK + result_code=99

2. Pre-flight validations (خارج از TX — intentional):
   a. SELECT users WHERE id=? AND is_active=1 → v_user_name, v_user_phone
      IF NULL/empty → result_code=1 (کاربر یافت نشد)
   b. SELECT businesses WHERE id=? → v_biz_name, v_biz_verified, v_biz_active
      IF NULL → result_code=2 (کسب‌وکار یافت نشد)
      IF inactive → result_code=3
      IF unverified → result_code=4
   c. SELECT services WHERE id=? AND business_id=? → v_svc_name, duration, price
      IF NULL OR inactive → result_code=5

3. START TRANSACTION:
   a. INSERT INTO appointments (با snapshot fields)
   b. IF v_dup_slot = 1 → ROLLBACK → result_code=6 (اسلات گرفته شده)
   c. SET v_new_id = LAST_INSERT_ID()
   d. INSERT INTO notifications (رزرو_موفق)
   e. COMMIT

4. → trg_appointments_after_insert فعال می‌شود (audit log)

خروجی موفق: result_code=0, appointment_id=v_new_id
```

### Result Codes

| کد | معنا |
|----|------|
| 0 | موفق |
| 1 | کاربر یافت نشد/غیرفعال |
| 2 | کسب‌وکار یافت نشد |
| 3 | کسب‌وکار غیرفعال |
| 4 | کسب‌وکار تایید نشده |
| 5 | خدمت یافت نشد/غیرفعال |
| 6 | اسلات گرفته شده |
| 99 | خطای داخلی |

### Transaction Boundaries — تحلیل

**Pre-flight خارج از TX چرا درست است:**
این read‌ها validation هستند. اگر بین validation و INSERT، business غیرفعال شود، UNIQUE constraint و trigger‌ها این را handle می‌کنند. اگر همه validations داخل TX بودند، TX طولانی‌تر می‌شد و lock contention بیشتر.

**نقص موجود — DEFAULT '' به جای NULL:**
```sql
-- کد فعلی (مشکل‌دار):
DECLARE v_biz_name VARCHAR(200) DEFAULT '';
...
IF v_biz_name IS NULL THEN -- ← هرگز NULL نمی‌شود اگر SELECT no-row برگردد!

-- Fix:
DECLARE v_biz_name VARCHAR(200) DEFAULT NULL;
-- یا:
IF v_biz_name IS NULL OR v_biz_name = '' THEN
```

### CONTINUE HANDLER FOR 1062 — مکانیزم دقیق

```
INSERT INTO appointments ...
    ↓
MySQL InnoDB slot_lock_key UNIQUE check
    ↓ (اگر duplicate)
errno 1062 raised
    ↓
CONTINUE HANDLER فعال می‌شود: SET v_dup_slot = 1
    ↓
اجرا ادامه می‌یابد (CONTINUE — نه EXIT)
    ↓
IF v_dup_slot = 1 THEN → ROLLBACK → result_code=6
```

نکته مهم: CONTINUE handler یعنی بعد از set کردن flag، اجرا از جایی که خطا رخ داده ادامه می‌یابد. EXIT handler یعنی بعد از handler، از block خارج می‌شود.

---

## 5.3 `CancelAppointment()`

### Flow کامل اجرا

```
ورودی: appointment_id, cancelled_by, cancel_reason
خروجی: result_code, result_msg

1. DECLARE EXIT HANDLER FOR SQLEXCEPTION: ROLLBACK + result_code=99

2. START TRANSACTION (همه چیز داخل TX):
   a. SELECT ... FROM appointments WHERE id=? FOR UPDATE
      → lock می‌گیرد روی appointment row
      IF NULL → ROLLBACK → result_code=1 (یافت نشد)
      IF status IN ('لغو شده','انجام شده') → ROLLBACK → result_code=2

   b. UPDATE appointments SET status='لغو شده',
      cancel_reason=?, cancelled_by=?, cancelled_at=NOW()

   c. INSERT INTO notifications (لغو_نوبت)

3. COMMIT
   → trg_appointments_after_update فعال می‌شود:
      - Queue promotion (اگر کسی در صف بود)
      - Audit log (با NEW.cancelled_by که SP قبل از COMMIT ست کرده)
```

### SELECT FOR UPDATE — چرا حیاتی است

بدون FOR UPDATE:
```
Session A: SELECT status='در انتظار' → OK
Session B: SELECT status='در انتظار' → OK  ← هر دو رد می‌شوند!
Session A: UPDATE SET status='لغو شده'
Session B: UPDATE SET status='لغو شده'  ← دو بار لغو!
Trigger: دو بار queue promotion!
```

با FOR UPDATE:
```
Session A: SELECT FOR UPDATE → lock گرفت
Session B: SELECT FOR UPDATE → BLOCK می‌شود
Session A: UPDATE SET status='لغو شده' → COMMIT
Session B: بیدار می‌شود، status='لغو شده' می‌بیند → ROLLBACK → result_code=2
```

### cancelled_by قبل از COMMIT

SP ستون `cancelled_by` را قبل از COMMIT ست می‌کند. وقتی COMMIT می‌شود، trigger `trg_appointments_after_update` فعال می‌شود. این trigger به `NEW.cancelled_by` دسترسی دارد — که همان مقدار ست‌شده است. در audit log می‌نویسد: "لغو توسط user X".

---

## 5.4 `AddToQueue()`

### Flow کامل اجرا

```
ورودی: user_id, business_id, service_id, date_shamsi, time_slot
خروجی: result_code, result_msg, queue_id, position

1. DECLARE CONTINUE HANDLER FOR 1062: v_dup_queue = 1
2. DECLARE EXIT HANDLER FOR SQLEXCEPTION: ROLLBACK + result_code=99

3. Pre-flight (خارج از TX):
   a. SELECT users → v_user_name, v_user_phone
      IF NULL → result_code=1
   b. SELECT businesses → v_biz_name
   c. SELECT services → v_svc_name, v_svc_duration, v_svc_price
      IF NULL → result_code=2

4. START TRANSACTION:
   a. SELECT COUNT(*) ... LOCK IN SHARE MODE
      → duplicate check (کاربر قبلاً در صف نباشد)
      IF > 0 → ROLLBACK → result_code=3

   b. SELECT COALESCE(MAX(position), 0)+1 ... FOR UPDATE
      → position calculation + gap lock

   c. INSERT INTO queue (با snapshot fields)

   d. IF v_dup_queue = 1 → ROLLBACK → result_code=3 (UNIQUE violation backup)

   e. INSERT INTO notifications (type='ارتقا_صف' — باید 'ثبت_صف' باشد)

5. COMMIT
```

### دو لایه Duplicate Prevention

**لایه ۱ — LOCK IN SHARE MODE:**
```sql
SELECT COUNT(*) INTO v_already_in
FROM queue WHERE user_id=? AND business_id=? AND date_shamsi=? AND time_slot=?
  AND status IN ('در انتظار','اطلاع داده شده')
LOCK IN SHARE MODE;
```
Shared lock روی existing rows. اگر session دیگری دارد INSERT می‌کند، این SELECT بلاک می‌شود تا آن INSERT commit شود.

**لایه ۲ — UNIQUE constraint:**
```sql
UNIQUE KEY uq_queue_user_slot (business_id, date_shamsi, time_slot, user_id)
```
حتی اگر LOCK IN SHARE MODE race condition را miss کند، UNIQUE constraint آخرین دفاع است.

### Position Race Condition Prevention

```sql
SELECT COALESCE(MAX(position), 0) + 1 INTO v_next_pos
FROM queue WHERE business_id=? AND date_shamsi=? AND time_slot=?
  AND status IN ('در انتظار','اطلاع داده شده')
FOR UPDATE;
```

FOR UPDATE روی این aggregate query:
- اگر rows وجود داشته باشد: row-level lock روی آن rows
- اگر rows نباشد (صف خالی): gap lock روی آن key range

Gap lock از اینکه دو session همزمان همان position را محاسبه کنند جلوگیری می‌کند.

---

## 5.5 `VerifyBusiness()`

### Flow کامل

```
ورودی: verification_id, admin_user_id, new_status ('تایید شده'/'رد شده'), admin_note
خروجی: result_code, result_msg

1. DECLARE EXIT HANDLER FOR SQLEXCEPTION: ROLLBACK + result_code=99

2. SELECT business_verification (خارج از TX — race condition دارد)
   IF NULL → result_code=1
   IF status != 'در انتظار' → result_code=2

3. START TRANSACTION:
   a. UPDATE business_verification SET status=?, admin_note=?, reviewed_by=?
      → trg_bv_after_update فعال می‌شود:
         IF status='تایید شده' → UPDATE businesses.is_verified=1
         CALL WriteAuditLog(...)

   b. IF status='تایید شده': INSERT notification (تایید_کسب‌وکار)
      ELSE: INSERT notification (رد_کسب‌وکار)

4. COMMIT
```

**نقص:** SELECT در مرحله ۲ باید داخل TX با FOR UPDATE باشد. (جزئیات کامل در بخش 3.8)

---

# 6. Triggers — تحلیل کامل

## 6.1 `trg_appointments_before_insert` (TRIGGER 1)

### Event: `BEFORE INSERT ON appointments`

### چرا body خالی است
این trigger عمداً خالی است. قبلاً یک `SELECT COUNT(*) ... WHERE status != 'لغو شده'` اینجا بود. این redundant بود چون `uq_slot_active` روی generated column این کار را در InnoDB storage engine می‌کند — سخت‌تر، سریع‌تر، و بدون extra read.

**Side effect:** هیچ. **Deadlock risk:** هیچ. **Recursion risk:** هیچ.

---

## 6.2 `trg_appointments_before_update` (TRIGGER 2)

### Event: `BEFORE UPDATE ON appointments`

### چه مشکلی حل می‌کند
بدون این trigger، هر code path می‌تواند status را به هر مقداری تغییر دهد. با این trigger، یک state machine enforced است که نه SP، نه frontend، نه مستقیم SQL نمی‌توانند آن را دور بزنند.

### ماتریس انتقال

```
در انتظار → تایید شده    ✓
در انتظار → لغو شده      ✓
تایید شده → انجام شده    ✓
تایید شده → لغو شده      ✓
لغو شده  → هر وضعیتی    ✗  SIGNAL
انجام شده → هر وضعیتی   ✗  SIGNAL
```

**Deadlock risk:** هیچ — فقط validation است، هیچ extra lock نمی‌گیرد.

---

## 6.3 `trg_appointments_after_insert` (TRIGGER 3)

### Event: `AFTER INSERT ON appointments`

### هدف
فقط audit log. Minimal trigger.

این trigger داخل transaction `CreateAppointment` اجرا می‌شود. اگر SP بعد از INSERT به دلیلی ROLLBACK کند، این CALL هم rollback می‌شود.

**Side effect:** یک INSERT به audit_log. **Deadlock risk:** نزدیک به صفر.

---

## 6.4 `trg_appointments_after_update` (TRIGGER 4) — پیچیده‌ترین Trigger

### Event: `AFTER UPDATE ON appointments`

### سه شاخه مستقل

#### Branch A: لغو نوبت → Queue Promotion

```
شرط: OLD.status != 'لغو شده' AND NEW.status = 'لغو شده'

قدم ۱: SELECT از queue با FOR UPDATE
        → v_queue_id (اولین نفر در صف)

قدم ۲: IF v_queue_id > 0:
   a. INSERT INTO appointments (SELECT FROM queue WHERE id=v_queue_id)
      → این INSERT باعث اجرای trg_appointments_after_insert می‌شود!
   b. UPDATE queue SET status='پذیرفته شده'
   c. UPDATE queue SET position=position-1 WHERE ... AND position>1
   d. INSERT INTO notifications (ارتقا_صف)
   e. CALL WriteAuditLog (ارتقا_صف)

قدم ۳: CALL WriteAuditLog (لغو)
```

#### Branch B: انجام نوبت
```
شرط: OLD.status != 'انجام شده' AND NEW.status = 'انجام شده'
→ CALL WriteAuditLog (ویرایش)
```

#### Branch C: تایید نوبت
```
شرط: OLD.status = 'در انتظار' AND NEW.status = 'تایید شده'
→ INSERT INTO notifications (رزرو_موفق)
```

### Trigger Chaining — مهم

در Branch A، وقتی `INSERT INTO appointments` انجام می‌شود، trigger `trg_appointments_after_insert` فعال می‌شود. این Trigger Chaining است — یک سطح: trigger A → INSERT → trigger B (که فقط CALL WriteAuditLog می‌کند). خطر infinite loop وجود ندارد.

### FOR UPDATE در Trigger — چرا لازم است

این trigger داخل transaction `CancelAppointment` اجرا می‌شود. FOR UPDATE روی queue row از race condition جلوگیری می‌کند:

```
Session A: CancelAppointment → trigger → SELECT queue FOR UPDATE → lock گرفت
Session B: CancelAppointment → trigger → SELECT queue FOR UPDATE → BLOCK
Session A: INSERT appointments از queue → COMMIT
Session B: بیدار می‌شود، status='پذیرفته شده' → v_queue_id=0 → no promotion
```

### Deadlock Analysis

ترتیب locking همیشه: appointments row → queue row. هیچ‌جا در کد معکوس نیست → deadlock کلاسیک رخ نمی‌دهد. InnoDB در صورت deadlock (edge case) یکی را rollback می‌کند؛ EXIT HANDLER آن را handle می‌کند.

---

## 6.5 `trg_reviews_after_insert` (TRIGGER 5)

### Event: `AFTER INSERT ON reviews`

```sql
IF NEW.is_visible = 1 THEN
    UPDATE businesses SET
        rating_sum    = rating_sum + NEW.rating,
        rating_count  = rating_count + 1,
        total_reviews = total_reviews + 1,
        rating_avg    = ROUND((rating_sum + NEW.rating) / (rating_count + 1), 2)
    WHERE id = NEW.business_id;
ELSE
    UPDATE businesses SET total_reviews = total_reviews + 1 WHERE id = NEW.business_id;
END IF;
```

### MySQL Right-Side Evaluation Rule — حیاتی

در یک `UPDATE SET` statement، تمام expressions در سمت راست `=` با مقادیر **قبل از UPDATE** ارزیابی می‌شوند.

پس در فرمول `rating_avg = ROUND((rating_sum + NEW.rating) / (rating_count + 1), 2)`:
- `rating_sum` = مقدار **قبل** از `rating_sum = rating_sum + NEW.rating`
- `rating_count` = مقدار **قبل** از `rating_count = rating_count + 1`

این **درست** است — چون فرمول از مقادیر پیش از این UPDATE برای محاسبه میانگین جدید استفاده می‌کند.

---

## 6.6 `trg_reviews_after_update` (TRIGGER 6)

### Event: `AFTER UPDATE ON reviews`

### چهار حالت مدیریت‌شده

| OLD.is_visible | NEW.is_visible | اثر روی businesses |
|----------------|----------------|-------------------|
| 0 | 0 | بدون تغییر در aggregates |
| 1 | 1 (rating ثابت) | بدون تغییر |
| 1 | 1 (rating تغییر) | rating_sum جایگزین می‌شود |
| 0 | 1 | اضافه به sum/count |
| 1 | 0 | کم از sum/count |

### نکته consistency

در trigger فعلی، `total_reviews` هم با تغییر visibility آپدیت می‌شود — اما اگر `total_reviews` قرار است تعداد **کل** نظرها (صرف‌نظر از visibility) را نشان دهد، این اشتباه است. در `trg_reviews_after_insert`، نظر invisible هم `total_reviews` را increment می‌کند (درست). اما در `trg_reviews_after_update`، visible→invisible `total_reviews` را کم می‌کند (احتمالاً اشتباه).

---

## 6.7 `trg_reviews_after_delete` (TRIGGER 7)

### Event: `AFTER DELETE ON reviews`

### GREATEST و COALESCE — چرا لازم است

```sql
rating_sum    = GREATEST(rating_sum - OLD.rating, 0),
rating_count  = GREATEST(rating_count - 1, 0),
total_reviews = GREATEST(total_reviews - 1, 0),
rating_avg    = COALESCE(
                   ROUND(
                       GREATEST(rating_sum - OLD.rating, 0)
                       / NULLIF(GREATEST(rating_count - 1, 0), 0),
                       2),
                   0.00)
```

**GREATEST(x, 0):** اگر به هر دلیلی counter به زیر صفر برود (داده inconsistent، migration اشتباه)، negative شدن counter را جلوگیری می‌کند.

**زنجیره COALESCE:** اگر آخرین نظر حذف شود:
```
rating_count - 1 = 0
NULLIF(0, 0) = NULL
تقسیم بر NULL = NULL
COALESCE(NULL, 0.00) = 0.00
```
بدون COALESCE، `rating_avg` می‌شد NULL که در UI مشکل ایجاد می‌کند.

---

## 6.8 `trg_users_after_insert` (TRIGGER 8)

### Event: `AFTER INSERT ON users`
فقط audit log. **Side effect:** یک INSERT به audit_log.

---

## 6.9 `trg_users_after_update` (TRIGGER 9)

### Event: `AFTER UPDATE ON users`

فقط زمانی audit می‌نویسد که role یا is_active تغییر کرده باشد. تغییر password یا full_name بدون audit است — این intentional است (password hash نباید در audit_log باشد).

---

## 6.10 `trg_businesses_after_insert` (TRIGGER 10)

### Event: `AFTER INSERT ON businesses`
فقط audit log ثبت کسب‌وکار جدید.

---

## 6.11 `trg_bv_after_insert` (TRIGGER 11)

### Event: `AFTER INSERT ON business_verification`
فقط audit log درخواست تایید.

---

## 6.12 `trg_bv_after_update` (TRIGGER 12) — مهم

### Event: `AFTER UPDATE ON business_verification`

دو کار:
1. `UPDATE businesses SET is_verified = 1/0` — propagation تایید
2. `CALL WriteAuditLog(...)` با `performed_by = NEW.reviewed_by`

**چرا is_verified update از SP به trigger منتقل شد:**
قبلاً SP هم `UPDATE businesses` می‌کرد و trigger هم. این یعنی دو UPDATE روی یک row در یک transaction → دو lock acquisition → overhead اضافه. حالا فقط trigger این UPDATE را می‌کند.

**Recursion risk:** trigger روی business_verification، businesses را update می‌کند. هیچ trigger روی businesses نیست که business_verification را update کند → بی‌خطر.

---

# 7. استراتژی Concurrency کامل

## 7.1 نقشه Lock‌های سیستم

| عملیات | Locks گرفته‌شده | زمان نگه‌داشتن |
|--------|----------------|---------------|
| CreateAppointment | uq_slot_active UNIQUE lock | فقط در INSERT |
| CancelAppointment | appointments row FOR UPDATE | تا COMMIT |
| Queue Promotion (trigger) | queue row FOR UPDATE | داخل TX cancel |
| AddToQueue | queue rows LOCK IN SHARE MODE + FOR UPDATE | تا COMMIT |
| VerifyBusiness | هیچ lock قبل از TX | race condition دارد |

## 7.2 Lock Ordering — Deadlock Prevention

برای جلوگیری از deadlock کلاسیک، ترتیب locking همیشه ثابت است:
1. appointments row
2. queue row

هیچ‌جا در کد معکوس این ترتیب نیست.

## 7.3 Race Conditions — وضعیت کامل

### حل‌شده

| Race Condition | مکانیزم حل |
|---------------|-----------|
| Double booking | uq_slot_active UNIQUE + CONTINUE HANDLER |
| Double cancel | SELECT FOR UPDATE در CancelAppointment |
| Double queue promotion | FOR UPDATE روی queue row در trigger |
| Queue position duplicate | FOR UPDATE + gap lock در AddToQueue |
| Duplicate queue registration | uq_queue_user_slot UNIQUE + LOCK IN SHARE MODE |

### حل‌نشده

| Race Condition | خطر | Fix |
|---------------|-----|-----|
| VerifyBusiness double-approve | دو ادمین هر دو approve می‌کنند | SELECT FOR UPDATE داخل TX |
| Category name stale | category_name در businesses stale | Trigger روی categories |

---

# 8. تحلیل JOIN Removal کامل

## 8.1 جدول کامل Snapshot‌ها

| snapshot field | در جدول | منبع اصلی | JOIN حذف‌شده |
|---------------|---------|-----------|-------------|
| user_name | appointments, queue | users.full_name | JOIN users |
| user_phone | appointments, queue, notifications | users.phone | JOIN users |
| business_name | appointments, queue, services, reviews, business_verification | businesses.name | JOIN businesses |
| service_name | appointments, queue, reviews | services.service_name | JOIN services |
| duration_minutes | appointments, queue | services.duration_minutes | JOIN services |
| price | appointments, queue | services.price | JOIN services |
| category_name | businesses, services | categories.name | JOIN categories |
| owner_name | businesses | users.full_name | JOIN users |
| owner_phone | businesses | users.phone | JOIN users |
| owner_user_id | business_verification | businesses.owner_user_id | JOIN businesses |

## 8.2 Performance Analysis

**با JOIN:**
```sql
SELECT a.id, a.date_shamsi, u.full_name, b.name, s.service_name, s.price
FROM appointments a
JOIN users u ON u.id = a.user_id
JOIN businesses b ON b.id = a.business_id
JOIN services s ON s.id = a.service_id
WHERE a.user_id = 123 ORDER BY a.date_shamsi DESC;
-- Plan: 4 tables, 3 hash joins, filesort
-- با 10k appointments: ~15-20ms
```

**بدون JOIN (snapshot):**
```sql
SELECT id, date_shamsi, user_name, business_name, service_name, price
FROM appointments WHERE user_id = 123 ORDER BY date_shamsi DESC;
-- Plan: index scan روی idx_appt_user_date
-- با 10k appointments: ~0.5-2ms
```

## 8.3 هزینه Consistency

| scenario | هزینه | آیا intentional است؟ |
|----------|-------|---------------------|
| کاربر نام عوض کند | نوبت‌های قدیمی نام قدیمی دارند | بله — historical record |
| قیمت خدمت عوض شود | نوبت‌های قدیمی قیمت قدیمی دارند | بله — مالی مهم |
| کسب‌وکار نام عوض کند | نوبت‌های قدیمی نام قدیمی دارند | بله — تاریخچه |
| دسته‌بندی rename شود | businesses snapshot stale می‌ماند | خیر — باید trigger fix شود |

---

# 9. استراتژی Indexing

## 9.1 قواعد

1. **Composite قبل از Single:** single-column که prefix subset composite است = redundant
2. **Selectivity اول، Range/Order آخر:** در composite index
3. **Covering برای hot queries:** تمام ستون‌های SELECT در index
4. **FULLTEXT برای free text search**

## 9.2 ایندکس‌های حذف‌شده

| ایندکس حذف‌شده | دلیل | جایگزین |
|---------------|------|---------|
| idx_services_business (business_id) | prefix subset | idx_svc_biz_active |
| idx_appt_user (user_id) | prefix subset | idx_appt_user_date |
| idx_appt_business (business_id) | prefix subset | idx_appt_biz_active |
| idx_queue_business (business_id) | prefix subset | idx_queue_promo |

## 9.3 ایندکس پیشنهادی آینده

```sql
-- گزارش روزانه با فیلتر status:
ALTER TABLE appointments
ADD KEY idx_appt_biz_date_status (business_id, date_shamsi, status);

-- notifications با فیلتر type:
ALTER TABLE notifications
ADD KEY idx_notif_user_type_date (user_id, type, created_at);
```

---

# 10. Search Strategy

## 10.1 FULLTEXT Search

```sql
-- جستجوی کسب‌وکار:
SELECT *, MATCH(name) AGAINST (? IN BOOLEAN MODE) AS score
FROM businesses
WHERE is_verified=1 AND is_active=1
  AND MATCH(name) AGAINST (? IN BOOLEAN MODE)
ORDER BY score DESC, rating_avg DESC;
```

### محدودیت‌های FULLTEXT در MySQL با فارسی

1. **Minimum word length:** `ft_min_word_len=3` (default). کلمات ۱-۲ کاراکتری index نمی‌شوند.
2. **Stop words:** MySQL stop word list انگلیسی است. فارسی stop words باید جداگانه تنظیم شوند.
3. **Tokenization:** MySQL با فاصله tokenize می‌کند. کلمات چسبیده فارسی مشکل دارند.

### تنظیم پیشنهادی

```ini
[mysqld]
ft_min_word_len = 2
innodb_ft_min_token_size = 2
```

برای production-grade Persian search، استفاده از Elasticsearch با Hazm tokenizer پیشنهاد می‌شود.

## 10.2 Composite Index Search

```sql
-- جستجو با category و sort:
SELECT * FROM businesses
WHERE is_verified=1 AND is_active=1 AND category_name='آرایشگاه'
ORDER BY rating_avg DESC LIMIT 20;
-- استفاده از: idx_biz_search_cat — بدون filesort
```

---

# 11. Reporting Strategy

## 11.1 Query‌های اصلی

### درآمد روزانه کسب‌وکار

```sql
SELECT date_shamsi,
       COUNT(*) AS total_appointments,
       SUM(CASE WHEN status='انجام شده' THEN price ELSE 0 END) AS confirmed_revenue,
       COUNT(CASE WHEN status='لغو شده' THEN 1 END) AS cancellations
FROM appointments
WHERE business_id = ? AND date_shamsi BETWEEN ? AND ?
GROUP BY date_shamsi ORDER BY date_shamsi;
```

### محبوب‌ترین خدمات

```sql
SELECT service_name, COUNT(*) AS total_bookings, SUM(price) AS total_revenue
FROM appointments
WHERE business_id = ? AND status = 'انجام شده'
GROUP BY service_name ORDER BY total_bookings DESC;
```

---

# 12. Scaling Considerations

## 12.1 Horizontal Scaling Path

### مرحله ۱: Read Replica
```
Write → Primary MySQL
Read  → Replica MySQL (async replication)
```
نکته: `NOT DETERMINISTIC` روی همه functions برای binlog-based replication ضروری است.

### مرحله ۲: Partitioning

```sql
-- appointments بر اساس سال شمسی:
ALTER TABLE appointments
PARTITION BY LIST COLUMNS (LEFT(date_shamsi, 4)) (
    PARTITION p_1402 VALUES IN ('1402'),
    PARTITION p_1403 VALUES IN ('1403'),
    PARTITION p_future DEFAULT
);
```

### مرحله ۳: Cache Layer

```
Redis cache key: business:{id}
TTL: 300 seconds
Invalidation trigger: هر UPDATE روی businesses
```

---

# 13. آینده‌پذیری طراحی

## 13.1 نقاط قوت

1. **Business logic در DB:** تغییر backend language بدون از دست دادن logic
2. **Snapshot architecture:** historical accuracy بدون JOIN
3. **Incremental aggregation:** rating scaling بدون refactor
4. **VARCHAR(30) در audit_log.action:** افزودن action جدید بدون ALTER TABLE
5. **NOT DETERMINISTIC:** آماده برای replication
6. **Persian collation:** آماده برای Persian text features

## 13.2 Roadmap اصلاح — اولویت‌بندی

### اولویت بالا (باید اصلاح شود)

**1. notifications.type — اضافه کردن 'ثبت_صف':**
```sql
ALTER TABLE notifications
MODIFY type ENUM(
    'رزرو_موفق','لغو_نوبت','یادآوری',
    'ثبت_صف','ارتقا_صف',
    'تایید_کسب‌وکار','رد_کسب‌وکار'
) NOT NULL DEFAULT 'رزرو_موفق';
```

**2. VerifyBusiness() race condition:**
Select را داخل transaction با FOR UPDATE منتقل کنید (جزئیات در بخش 3.8).

**3. CreateAppointment() DEFAULT '' bug:**
```sql
DECLARE v_biz_name VARCHAR(200) DEFAULT NULL;
DECLARE v_svc_name VARCHAR(200) DEFAULT NULL;
```

### اولویت متوسط

**4. Category sync trigger (بخش 3.2)**

**5. business_slots validation در CreateAppointment:**
```sql
DECLARE v_slot_exists INT DEFAULT 0;
SELECT COUNT(*) INTO v_slot_exists
FROM business_slots
WHERE business_id = p_business_id
  AND time_slot   = p_time_slot
  AND is_active   = 1;
IF v_slot_exists = 0 THEN
    SET p_result_code = 7;
    SET p_result_msg  = 'این اسلات زمانی برای کسب‌وکار تعریف نشده است';
    LEAVE CreateAppointment;
END IF;
```

### اولویت پایین (آینده)

**6. Partitioning برای appointments و audit_log** (وقتی به 10M+ ردیف رسید)

**7. Redis cache برای businesses** (وقتی read load بالا رفت)

**8. Elasticsearch برای جستجوی فارسی** (اگر FULLTEXT کافی نبود)

**9. Event Scheduler برای expire صف:**
```sql
CREATE EVENT evt_expire_queue
ON SCHEDULE EVERY 1 HOUR
DO
    UPDATE queue SET status = 'منقضی شده'
    WHERE status IN ('در انتظار', 'اطلاع داده شده')
      AND date_shamsi < /* تاریخ شمسی دیروز از PHP */;
```

---

# 14. چک‌لیست Deploy

## 14.1 ترتیب اجرای فایل‌ها

```bash
# ترتیب اجرا مهم است:
mysql -u root -p servora < schema.sql
mysql -u root -p servora < functions_procedures.sql  # WriteAuditLog باید قبل از triggers باشد
mysql -u root -p servora < triggers.sql
```

## 14.2 One-Time Backfill

اگر دیتابیس قبلاً داده داشت، بلاک backfill در انتهای schema.sql باید یک بار اجرا شود. بعد از اجرا، آن را comment کنید.

## 14.3 تنظیمات MySQL پیشنهادی

```ini
[mysqld]
innodb_buffer_pool_size = 1G
innodb_log_file_size = 256M
transaction_isolation = READ-COMMITTED
ft_min_word_len = 2
innodb_ft_min_token_size = 2
character_set_server = utf8mb4
collation_server = utf8mb4_persian_ci
time_zone = +03:30
```

---

*مستند بر اساس نسخه 2.0.0 فایل‌های `schema.sql`, `functions_procedures.sql`, و `triggers.sql` نوشته شده است.*
*آخرین به‌روزرسانی: 1404/03/04*
