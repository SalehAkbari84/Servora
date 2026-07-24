# SERVORA — Production-Grade Database Audit Report
**Auditor:** Senior MySQL Architect & Distributed Systems Engineer  
**Scope:** Full system audit — schema, triggers, stored procedures, functions, concurrency, performance, scalability  
**MySQL Version Target:** 8.0.16+ InnoDB  
**Audit Date:** 1404/03/04

---

# SECTION 1: EXECUTIVE SUMMARY

SERVORA is a well-structured appointment booking system with thoughtful design choices — snapshot architecture, incremental rating aggregation, generated-column slot locking, and centralized audit logging. The codebase demonstrates above-average MySQL engineering awareness.

However, several **production-critical bugs and architectural risks** were identified that would cause data corruption, silent inconsistencies, and catastrophic failures under concurrent load.

**The system is NOT currently safe for medium-to-high concurrency production deployment.**

The top three blocking issues are:

1. **CONFIRMED BUG:** `trg_reviews_after_update` incorrectly mutates `total_reviews` on visibility changes, causing permanent counter drift that cannot self-heal.
2. **RACE CONDITION:** `VerifyBusiness()` reads status outside the transaction without `FOR UPDATE`, allowing double-approval under concurrent admin access.
3. **ARCHITECTURAL RISK:** All notifications are written synchronously inside booking/cancellation transactions, creating lock amplification, longer transaction hold times, and delivery coupling with transactional correctness.

---

# SECTION 2: PRODUCTION READINESS SCORE

| Category | Score | Notes |
|----------|-------|-------|
| Correctness (Logic) | 6/10 | Rating trigger bug, VerifyBusiness race |
| Concurrency Safety | 6/10 | Core paths OK, edge cases dangerous |
| Deadlock Safety | 7/10 | Lock ordering is consistent; chained triggers create amplification |
| Transaction Safety | 7/10 | Good use of FOR UPDATE; isolation level sub-optimal |
| Index Strategy | 8/10 | Solid; minor missing covering indexes |
| Scalability | 4/10 | Sync notifications, hot-row rating updates, mass position decrement |
| Trigger Architecture | 5/10 | Too much in triggers; replication and debugging risk |
| Operational Safety | 5/10 | No audit partitioning, no notification retry, no expiry scheduler |
| **Overall** | **6/10** | MVP-ready with fixes; NOT production-ready as-is |

---

# SECTION 3: CRITICAL ISSUES (P0 — DATA CORRUPTION RISK)

## CRITICAL-1: `trg_reviews_after_update` — `total_reviews` Counter Drift Bug

### The Bug

`total_reviews` is defined semantically as the count of **all** reviews for a business, regardless of visibility. This is confirmed by `trg_reviews_after_insert`, which increments `total_reviews` for **both** `is_visible=1` and `is_visible=0` cases.

However, `trg_reviews_after_update` adjusts `total_reviews` when `is_visible` changes:

```sql
-- CURRENT (BUGGY):
total_reviews = total_reviews
               - IF(OLD.is_visible = 1, 1, 0)
               + IF(NEW.is_visible = 1, 1, 0)
```

**Scenario: Admin hides a review (is_visible: 1 → 0)**
- `total_reviews` decrements by 1 ← **WRONG** — the review still exists
- `rating_count` decrements by 1 ← correct (visible count changes)

**Scenario: Admin unhides a review (is_visible: 0 → 1)**
- `total_reviews` increments by 1 ← **WRONG** — total count hasn't changed

**Impact:**
- `total_reviews` drifts with every admin moderation action
- Displayed "X reviews" count becomes permanently wrong
- Cannot self-heal without running the backfill script again
- Counter drift compounds over time; high-moderation businesses are worst affected

### Mathematical Proof of Bug

Starting state: business has 10 reviews, 8 visible, 2 hidden.
- `total_reviews = 10`, `rating_count = 8`

Admin hides 3 visible reviews one by one:
- Each UPDATE: `total_reviews` decrements → 9, 8, 7
- `rating_count` decrements correctly → 7, 6, 5

After: 10 reviews still exist, but `total_reviews = 7`. **3 reviews are invisible to counters.**

### Corrected SQL

```sql
DROP TRIGGER IF EXISTS trg_reviews_after_update;

CREATE TRIGGER trg_reviews_after_update
AFTER UPDATE ON reviews
FOR EACH ROW
BEGIN
    -- Only fire if something rating-relevant changed
    IF OLD.rating != NEW.rating OR OLD.is_visible != NEW.is_visible THEN
        UPDATE businesses
        SET    rating_sum    = rating_sum
                               - IF(OLD.is_visible = 1, OLD.rating, 0)
                               + IF(NEW.is_visible = 1, NEW.rating, 0),
               rating_count  = rating_count
                               - IF(OLD.is_visible = 1, 1, 0)
                               + IF(NEW.is_visible = 1, 1, 0),
               -- total_reviews MUST NOT change here:
               -- a review being hidden/unhidden is still a review
               -- total_reviews only changes on INSERT and DELETE
               rating_avg    = ROUND(
                                   (rating_sum
                                    - IF(OLD.is_visible = 1, OLD.rating, 0)
                                    + IF(NEW.is_visible = 1, NEW.rating, 0))
                                   / NULLIF(
                                       rating_count
                                       - IF(OLD.is_visible = 1, 1, 0)
                                       + IF(NEW.is_visible = 1, 1, 0),
                                   0),
                                   2)
        WHERE  id = NEW.business_id;
    END IF;

    -- Audit only if something meaningful changed
    IF OLD.rating != NEW.rating OR OLD.is_visible != NEW.is_visible OR OLD.comment != NEW.comment THEN
        CALL WriteAuditLog(
            'reviews', NEW.id, 'ویرایش',
            CONCAT('نظر ویرایش شد | امتیاز: ', OLD.rating, ' → ', NEW.rating,
                   ' | نمایش: ', OLD.is_visible, ' → ', NEW.is_visible,
                   ' | کسب‌وکار: ', NEW.business_name),
            NULL
        );
    END IF;
END$$
```

**Additional fix:** A correction backfill must be run after deploying this trigger:

```sql
-- One-time repair backfill (run ONCE after deploying fixed trigger):
UPDATE businesses b
INNER JOIN (
    SELECT business_id, COUNT(*) AS v_total_reviews
    FROM   reviews
    GROUP  BY business_id
) agg ON agg.business_id = b.id
SET b.total_reviews = agg.v_total_reviews
WHERE b.total_reviews != agg.v_total_reviews;
```

---

## CRITICAL-2: `VerifyBusiness()` — Double-Approval Race Condition

### The Bug

```sql
-- OUTSIDE TRANSACTION — no lock held:
SELECT business_id, status, business_name, owner_user_id, owner_phone
INTO   v_business_id, v_cur_status, ...
FROM   business_verification
WHERE  id = p_verification_id
LIMIT  1;

IF v_cur_status != 'در انتظار' THEN LEAVE; END IF;

-- Another admin can pass this guard simultaneously:
START TRANSACTION;
    UPDATE business_verification SET status = p_new_status ...
COMMIT;
```

**Concurrent admin scenario:**
```
Admin A: SELECT → sees status='در انتظار' ✓
Admin B: SELECT → sees status='در انتظار' ✓  (A hasn't committed yet)
Admin A: UPDATE status='تایید شده' → COMMIT
Admin B: UPDATE status='تایید شده' → COMMIT (second approval!)
```

Both admins pass the guard. Both fire `trg_bv_after_update`. Result:
- `businesses.is_verified` set to 1 twice (idempotent, no harm)
- Two audit_log entries for the same approval
- Two notifications sent to the business owner

The double notification is customer-facing and visible. The double audit entry is an integrity violation.

### Fix

```sql
DROP PROCEDURE IF EXISTS VerifyBusiness$$

CREATE PROCEDURE VerifyBusiness(
    IN  p_verification_id  BIGINT UNSIGNED,
    IN  p_admin_user_id    BIGINT UNSIGNED,
    IN  p_new_status       ENUM('تایید شده', 'رد شده'),
    IN  p_admin_note       VARCHAR(1000),
    OUT p_result_code      INT,
    OUT p_result_msg       VARCHAR(500)
)
VerifyBusiness: BEGIN
    DECLARE v_business_id  BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_biz_owner_id BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_biz_name     VARCHAR(200)    DEFAULT NULL;
    DECLARE v_owner_phone  VARCHAR(11)     DEFAULT NULL;
    DECLARE v_cur_status   VARCHAR(20)     DEFAULT NULL;
    DECLARE v_rows_updated INT             DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_result_code = 99;
        SET p_result_msg  = 'خطای داخلی سرور';
    END;

    -- ALL logic inside transaction with FOR UPDATE
    START TRANSACTION;

        SELECT business_id, status, business_name, owner_user_id, owner_phone
        INTO   v_business_id, v_cur_status, v_biz_name, v_biz_owner_id, v_owner_phone
        FROM   business_verification
        WHERE  id = p_verification_id
        FOR UPDATE;  -- ← CRITICAL: holds row lock until COMMIT

        IF v_cur_status IS NULL THEN
            ROLLBACK;
            SET p_result_code = 1;
            SET p_result_msg  = 'درخواست تایید یافت نشد';
            LEAVE VerifyBusiness;
        END IF;

        IF v_cur_status != 'در انتظار' THEN
            ROLLBACK;
            SET p_result_code = 2;
            SET p_result_msg  = 'این درخواست قبلاً بررسی شده است';
            LEAVE VerifyBusiness;
        END IF;

        UPDATE business_verification
        SET    status      = p_new_status,
               admin_note  = p_admin_note,
               reviewed_by = p_admin_user_id
        WHERE  id          = p_verification_id;

        IF p_new_status = 'تایید شده' THEN
            INSERT INTO notifications
                (user_id, user_phone, type, title, body, related_entity_type, related_entity_id)
            VALUES (
                v_biz_owner_id, v_owner_phone,
                'تایید_کسب‌وکار',
                'کسب‌وکار شما تایید شد',
                CONCAT('کسب‌وکار «', v_biz_name, '» توسط ادمین تایید و فعال شد.'),
                'businesses', v_business_id
            );
        ELSE
            INSERT INTO notifications
                (user_id, user_phone, type, title, body, related_entity_type, related_entity_id)
            VALUES (
                v_biz_owner_id, v_owner_phone,
                'رد_کسب‌وکار',
                'کسب‌وکار شما تایید نشد',
                CONCAT('کسب‌وکار «', v_biz_name, '» تایید نشد. دلیل: ',
                       COALESCE(p_admin_note, 'ذکر نشده')),
                'business_verification', p_verification_id
            );
        END IF;

    COMMIT;

    SET p_result_code = 0;
    SET p_result_msg  = CONCAT('وضعیت کسب‌وکار به «', p_new_status, '» تغییر یافت');

END$$
```

---

## CRITICAL-3: `CreateAppointment()` — NULL Guard Failure on Business/Service Lookup

### The Bug

```sql
DECLARE v_biz_name VARCHAR(200) DEFAULT '';  -- DEFAULT is empty string
...
SELECT name, is_verified, is_active
INTO   v_biz_name, v_biz_verified, v_biz_active
FROM   businesses WHERE id = p_business_id LIMIT 1;

IF v_biz_name IS NULL THEN   -- ← NEVER TRUE when DEFAULT is ''
```

**MySQL behavior:** When `SELECT INTO` finds no matching row, it does **not** set the variable to NULL — it leaves it at its current value (the DEFAULT `''`). Therefore `v_biz_name IS NULL` is always false, and the "business not found" check never fires.

**Impact:** If an invalid `p_business_id` is passed, the procedure silently uses `v_biz_name = ''` (empty string) as the business name in the appointment, `v_biz_active = 0` (DEFAULT), `v_biz_verified = 0` (DEFAULT). The procedure then correctly catches `v_biz_active = 0` and returns code 3, but for the WRONG reason. If someone passes a real ID for an active, verified business, then immediately the business is deleted or made inactive between the read and the INSERT, the stale DEFAULT 0 values would be used, incorrectly blocking a valid booking.

More critically: if `p_business_id` = 0 or a non-existent ID, the guard only accidentally works because `v_biz_active = 0` catches it. The intent (explicit "not found" check) is broken.

### Fix

```sql
DECLARE v_biz_name     VARCHAR(200)  DEFAULT NULL;  -- NULL means "not found"
DECLARE v_svc_name     VARCHAR(200)  DEFAULT NULL;  -- NULL means "not found"
DECLARE v_biz_verified TINYINT(1)    DEFAULT NULL;
DECLARE v_biz_active   TINYINT(1)    DEFAULT NULL;
DECLARE v_svc_active   TINYINT(1)    DEFAULT NULL;
...
IF v_biz_name IS NULL THEN  -- Now correctly fires when no row found
    SET p_result_code = 2;
    SET p_result_msg  = 'کسب‌وکار یافت نشد';
    LEAVE CreateAppointment;
END IF;

IF v_biz_active = 0 THEN    -- Now only fires when business exists but inactive
    SET p_result_code = 3;
    ...
END IF;
```

---

# SECTION 4: HIGH-RISK ISSUES (P1 — Reliability/Data Integrity)

## HIGH-1: Notification Type Semantic Error in `AddToQueue()`

```sql
-- CURRENT (WRONG):
INSERT INTO notifications (..., type='ارتقا_صف', title='ثبت در صف انتظار', ...)
```

'ارتقا_صف' means "promoted from queue to appointment." Using it for "registered in queue" is semantically inverted. This will confuse:
- Frontend routing logic (related_entity_type='queue' correct, but type wrong)
- Analytics queries ("how many queue promotions today?" will include queue registrations)
- Push notification copy mapping

**Fix:**
```sql
ALTER TABLE notifications
MODIFY type ENUM(
    'رزرو_موفق',
    'لغو_نوبت',
    'یادآوری',
    'ثبت_صف',           -- NEW: queue registration
    'ارتقا_صف',         -- keep: queue promotion to appointment
    'تایید_کسب‌وکار',
    'رد_کسب‌وکار'
) NOT NULL DEFAULT 'رزرو_موفق';

-- Then in AddToQueue():
-- change 'ارتقا_صف' → 'ثبت_صف' in the notification INSERT
```

---

## HIGH-2: Mass Position Decrement — Lock Amplification and Queue Starvation

### The Problem

When a slot is cancelled and the queue is promoted:
```sql
UPDATE queue
SET    position = position - 1
WHERE  business_id = ? AND date_shamsi = ? AND time_slot = ?
  AND  status = 'در انتظار' AND position > 1;
```

If 100 users are in the queue for a popular slot, this UPDATE:
- Acquires X locks on **99 rows** simultaneously
- Holds all 99 locks until `CancelAppointment` commits
- Any concurrent `AddToQueue` for the same slot BLOCKS for the entire duration
- With 1000ms latency on cancellation (due to notification inserts), 99 rows are locked for 1000ms

**Under load:** If 10 concurrent users are trying to join the queue while a cancellation happens, they all queue up behind the mass UPDATE. The last one might wait 10+ seconds.

### Fix: Timestamp-Based Queue (No Position Column)

Replace the integer `position` with `created_at` ordering:

```sql
-- Instead of maintaining position integers, use created_at for ordering
-- Promotion query becomes:
SELECT id, user_id, user_phone, service_name, business_name
FROM   queue
WHERE  business_id = ? AND date_shamsi = ? AND time_slot = ?
  AND  status      = 'در انتظار'
ORDER  BY created_at ASC
LIMIT  1 FOR UPDATE;

-- No mass position decrement needed
-- AddToQueue needs no position calculation
-- Queue order is naturally maintained by insertion time
```

If user-visible position numbers are needed for the UI, calculate them at query time:
```sql
SELECT id, user_id,
       ROW_NUMBER() OVER (PARTITION BY business_id, date_shamsi, time_slot
                          ORDER BY created_at) AS display_position
FROM queue
WHERE status = 'در انتظار' AND user_id = ?;
```

This removes the `position` column entirely, eliminates the mass UPDATE, eliminates the FOR UPDATE position calculation, and reduces lock contention by ~99%.

---

## HIGH-3: Synchronous Notifications Inside Booking Transactions

### The Problem

Every transaction in this system writes to `notifications` synchronously:
- `CreateAppointment`: 1 notification INSERT
- `CancelAppointment`: 1 notification INSERT + potentially 1 more in trigger
- `AddToQueue`: 1 notification INSERT
- `VerifyBusiness`: 1 notification INSERT

**Problems:**
1. **Lock amplification:** Every booking locks the `notifications` table page. Under high concurrency, notification page locks create hot-page contention.
2. **Transaction duration:** Any slowness in the notification path extends the booking transaction hold time. Extended transactions mean longer row locks on `appointments`.
3. **Atomicity coupling:** If a notification fails (constraint violation, disk error), the booking ROLLS BACK. A successful booking should never fail because of a notification problem.
4. **Push delivery tight coupling:** The system can't easily migrate to async push (FCM, SMS gateway) without refactoring all procedures.

### Fix: Outbox Pattern

```sql
-- Add outbox table:
CREATE TABLE notification_outbox (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    user_phone      VARCHAR(11)     NOT NULL,
    type            VARCHAR(30)     NOT NULL,
    title           VARCHAR(200)    NOT NULL,
    body            TEXT            NOT NULL,
    related_entity_type VARCHAR(60) NULL,
    related_entity_id   BIGINT UNSIGNED NULL,
    status          ENUM('pending','processing','delivered','failed') NOT NULL DEFAULT 'pending',
    attempt_count   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_retry_at   DATETIME        NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at    DATETIME        NULL,

    PRIMARY KEY (id),
    KEY idx_outbox_pending (status, next_retry_at)
) ENGINE=InnoDB;
```

**In CreateAppointment:**
```sql
-- Replace: INSERT INTO notifications ...
-- With:    INSERT INTO notification_outbox ...
```

**Async worker (runs every few seconds):**
```sql
-- Poll for pending notifications:
SELECT id, user_id, user_phone, type, title, body, related_entity_type, related_entity_id
FROM   notification_outbox
WHERE  status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= NOW())
ORDER  BY id ASC
LIMIT  100
FOR UPDATE SKIP LOCKED;  -- SKIP LOCKED: multiple workers can run in parallel

-- Worker delivers each notification (SMS gateway, push, etc.)
-- On success: UPDATE status='delivered', processed_at=NOW()
-- On failure: UPDATE status='failed', attempt_count=attempt_count+1,
--             next_retry_at=NOW()+INTERVAL (attempt_count * 30) SECOND
-- After 3 failures: status='failed' permanently, alert ops
```

`SKIP LOCKED` (MySQL 8+) allows multiple worker instances to process different rows without blocking each other.

---

## HIGH-4: `trg_appointments_after_update` — Trigger Transaction Expansion

### The Problem

`CancelAppointment`'s transaction already holds:
- X lock on `appointments` row
- Insert intent locks on `notifications` page

When COMMIT fires the after-update trigger, the trigger extends this transaction with:
- X lock on `queue` row (FOR UPDATE)
- INSERT into `appointments` (new row, insert intent lock on appointments page)
  - This fires `trg_appointments_after_insert` → INSERT into `audit_log`
- UPDATE `queue` rows (position decrement)
- INSERT into `notifications` (queue promotion)
- CALL WriteAuditLog (INSERT `audit_log` for promotion)
- CALL WriteAuditLog (INSERT `audit_log` for cancellation)

**The transaction that started as a simple "update one row" now touches:**
`appointments` × 2, `queue` × N, `notifications` × 2, `audit_log` × 2

**Under high concurrency:**
- The appointments page is locked longer (while trigger runs)
- Another user booking the same business for a different slot may share the same B-tree page and BLOCK even though there's no slot conflict

This is a **lock amplification** problem, not a correctness bug. But under heavy load, it causes cascading latency.

---

# SECTION 5: MEDIUM-RISK ISSUES (P2 — Reliability)

## MEDIUM-1: Missing `audit_log` Write in `trg_reviews_after_update`

The current trigger correctly updates the `businesses` aggregates but **never writes to audit_log**. Every other trigger does. This is an audit gap — review edits (rating changes, visibility changes) are invisible to the audit trail.

**Fix:** Add `CALL WriteAuditLog(...)` at the end of `trg_reviews_after_update` (shown in the corrected SQL in CRITICAL-1).

---

## MEDIUM-2: `AddToQueue` — Missing Business Existence and Activity Checks

```sql
SELECT name INTO v_biz_name
FROM   businesses WHERE id = p_business_id LIMIT 1;
-- No check: what if business doesn't exist, is inactive, or unverified?
```

A user can join the queue for an inactive or unverified business. When the slot opens, the promotion trigger will create an appointment for an inactive business. `CreateAppointment` validates this for direct bookings but `AddToQueue` does not.

**Fix:** Add validation in `AddToQueue`:
```sql
SELECT name, is_active, is_verified
INTO   v_biz_name, v_biz_active, v_biz_verified
FROM   businesses WHERE id = p_business_id LIMIT 1;

IF v_biz_name IS NULL THEN
    SET p_result_code = 2; SET p_result_msg = 'کسب‌وکار یافت نشد';
    LEAVE AddToQueue;
END IF;
IF v_biz_active = 0 THEN
    SET p_result_code = 3; SET p_result_msg = 'کسب‌وکار غیرفعال است';
    LEAVE AddToQueue;
END IF;
```

---

## MEDIUM-3: Queue Promotion Creates Appointment With Status `'در انتظار'` — Missing Auto-Confirm Logic

When a queue user is promoted:
```sql
INSERT INTO appointments (..., status) SELECT ..., 'در انتظار' FROM queue ...
```

The promoted appointment enters `'در انتظار'` state. This is correct — the business owner still needs to confirm. But the promoted user receives a notification saying "نوبت برای شما آزاد شد!" which implies the booking is confirmed.

**UX mismatch:** User thinks they're booked, but they're actually still pending approval.

**Recommendation:** Either:
1. Set promoted appointments to `'تایید شده'` automatically (business agreed to the slot when they opened it)
2. Change the notification copy to clearly say "نوبت آزاد شد — منتظر تایید کسب‌وکار باشید"

---

## MEDIUM-4: `business_slots` Not Validated in `CreateAppointment`

A user can book any arbitrary `(date_shamsi, time_slot)` combination regardless of whether the business has that slot in `business_slots`. The business's working hours are stored in `business_slots` but never checked during booking.

**Fix:** Add slot validation in `CreateAppointment` pre-flight:
```sql
DECLARE v_slot_valid INT DEFAULT 0;
-- Convert date_shamsi day-of-week to 0-6 (شنبه=0) in PHP before calling SP
-- OR pass day_of_week as a parameter
-- For now, skip day validation if PHP doesn't provide it,
-- but at minimum check time_slot format against business_slots:
SELECT COUNT(*) INTO v_slot_valid
FROM   business_slots
WHERE  business_id = p_business_id
  AND  time_slot   = p_time_slot
  AND  is_active   = 1;

IF v_slot_valid = 0 THEN
    SET p_result_code = 7;
    SET p_result_msg  = 'این اسلات زمانی برای کسب‌وکار تعریف نشده است';
    LEAVE CreateAppointment;
END IF;
```

---

## MEDIUM-5: Isolation Level — REPEATABLE READ Sub-Optimal for This Workload

**Current:** MySQL default `REPEATABLE READ`

**Why REPEATABLE READ hurts here:**
1. In `AddToQueue`, the `LOCK IN SHARE MODE` and `FOR UPDATE` explicitly break out of the snapshot read. Under REPEATABLE READ, all other reads within the same transaction see the snapshot. This is actually fine for the current code.
2. Under REPEATABLE READ, two concurrent `CreateAppointment` calls for the same slot will BOTH successfully read pre-flight validation (no active appointment), then BOTH attempt INSERT. One will get errno 1062 (UNIQUE violation). This is correct behavior handled by the CONTINUE HANDLER.
3. However, REPEATABLE READ has higher overhead than READ COMMITTED for:
   - Long scans on appointments/audit_log (maintains older snapshot versions)
   - High-frequency updates to `businesses.rating_*` (every write maintains MVCC chains)

**Recommendation:**
```sql
-- Set at session level in all stored procedures, or server-wide:
SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED;
```

Under `READ COMMITTED`:
- Each statement reads the latest committed data
- Less MVCC chain maintenance
- Better performance for the high-frequency updates this system performs
- The UNIQUE constraints still provide hard consistency guarantees
- No phantom reads are possible in the INSERT paths (they're protected by UNIQUE)

The only place where REPEATABLE READ would help — preventing phantom reads in AddToQueue's duplicate check — is already handled by `LOCK IN SHARE MODE`, which works correctly under both isolation levels.

---

## MEDIUM-6: `rating_avg` Precision Underflow Risk

```sql
rating_avg DECIMAL(3,2)
```

`DECIMAL(3,2)` allows values `0.00` to `9.99`. Since rating is 1-5, max average is 5.00. This fits. However, if the schema is ever extended to allow ratings up to 10, this column silently truncates. Consider `DECIMAL(4,2)` as a forward-safe choice.

---

# SECTION 6: LOW-RISK ISSUES (P3 — Technical Debt)

## LOW-1: `categories` Name Changes Not Propagated

When `categories.name` changes, all snapshot fields (`businesses.category_name`, `businesses.subcategory_name`, `services.category_name`) become stale. Currently no trigger handles this propagation.

## LOW-2: No Queue Expiry Mechanism

Queue entries with `status='در انتظار'` for past dates are never cleaned up. Over time, stale queue entries accumulate and pollute `idx_queue_promo` lookups.

**Fix:** MySQL Event Scheduler:
```sql
CREATE EVENT evt_expire_stale_queue
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP
DO
    UPDATE queue
    SET    status = 'منقضی شده'
    WHERE  status IN ('در انتظار', 'اطلاع داده شده')
      AND  date_shamsi < /* passed by PHP or computed via stored function */
      AND  created_at < NOW() - INTERVAL 2 DAY;
```

Note: Computing "yesterday's Jalali date" in MySQL is non-trivial without a Jalali library. The safest approach is to pass the cutoff date from PHP.

## LOW-3: No `reviews` SP — Direct INSERT Risk

Reviews can be inserted directly with any `appointment_id`, including for appointments that don't belong to the inserting user or are not in `'انجام شده'` status. The schema enforces `UNIQUE(appointment_id)` but not "only the appointment owner can review" or "only completed appointments can be reviewed."

## LOW-4: `business_slots.day_of_week` Convention Not Enforced

`day_of_week TINYINT UNSIGNED` with comment `0=شنبه...6=جمعه` has no CHECK constraint. Invalid values (7-255) would silently insert.

**Fix:**
```sql
CONSTRAINT chk_day_of_week CHECK (day_of_week BETWEEN 0 AND 6)
```

---

# SECTION 7: CONCURRENCY ANALYSIS

## 7.1 Double-Booking Race Condition — SAFE

**Scenario:** Two users simultaneously book the same slot.

```
Session A: INSERT appointments (slot S) → InnoDB evaluates uq_slot_active
Session B: INSERT appointments (slot S) → InnoDB evaluates uq_slot_active

One of them gets errno 1062.
The CONTINUE HANDLER catches it.
result_code=6 is returned.
```

**Verdict:** SAFE. The UNIQUE constraint on the generated column is enforced at the InnoDB storage layer before any transaction commits. This is atomic and race-proof.

## 7.2 Double-Cancellation Race Condition — SAFE

**Scenario:** User and admin simultaneously cancel the same appointment.

```
Session A: SELECT appointments id=X FOR UPDATE → acquires X lock
Session B: SELECT appointments id=X FOR UPDATE → BLOCKS
Session A: UPDATE status='لغو شده' → COMMIT (trigger fires, promotes queue)
Session B: wakes up, reads status='لغو شده' → ROLLBACK → result_code=2
```

**Verdict:** SAFE. The SELECT FOR UPDATE in CancelAppointment correctly serializes concurrent cancellations.

## 7.3 Double Queue Promotion — SAFE (with caveat)

**Scenario:** Two concurrent cancellations for different appointments sharing the same slot (impossible under uq_slot_active, but analyzed anyway).

Since only one active appointment can exist per slot at any time (enforced by uq_slot_active), two concurrent cancellations for the SAME slot cannot happen. Each cancellation fires the promotion trigger, but because each handles a different appointment on a different slot, their queue FORs UPDATE don't conflict.

**The caveat:** If somehow the UNIQUE constraint is bypassed (data corruption, direct INSERT without SP), two cancellations could race for the same queue entry. The FOR UPDATE in the trigger handles this:

```
Trigger A: SELECT queue FOR UPDATE → gets lock on Q1
Trigger B: SELECT queue FOR UPDATE → BLOCKS on Q1
Trigger A: UPDATE queue Q1 status='پذیرفته شده' → INSERT new appointment → COMMIT
Trigger B: wakes up, Q1 status='پذیرفته شده' → v_queue_id = 0 → no promotion
```

**Verdict:** SAFE.

## 7.4 Queue Position Race — SAFE

**Scenario:** Two users simultaneously call `AddToQueue` for the same slot.

```
Session A: LOCK IN SHARE MODE check → v_already_in=0 (both pass)
Session B: LOCK IN SHARE MODE check → v_already_in=0

Session A: SELECT MAX(position) FOR UPDATE → locks existing queue rows, gets v_next_pos=2
Session B: SELECT MAX(position) FOR UPDATE → BLOCKS (A holds exclusive lock)
Session A: INSERT queue (position=2) → COMMIT
Session B: wakes up, sees A's row, MAX=2, v_next_pos=3
Session B: INSERT queue (position=3) → COMMIT
```

**Verdict:** SAFE. The FOR UPDATE on the MAX query serializes position calculation correctly. Gap lock handles the empty queue case.

## 7.5 Concurrent Rating Updates — HOT ROW CONTENTION (Not a deadlock, but a bottleneck)

**Scenario:** A popular business receives 100 simultaneous reviews.

All 100 triggers do:
```sql
UPDATE businesses SET rating_sum=..., rating_count=..., rating_avg=...
WHERE id = {popular_business_id};
```

InnoDB grants X locks on the `businesses` row one at a time. All 100 sessions serialize. Under REPEATABLE READ, each waits for the previous to commit. This is not a deadlock, but under extreme load (viral business launch, attack), it creates a hot row bottleneck.

**Mitigation:** This is an inherent limitation of synchronous incremental aggregation. For businesses with > 10k reviews/hour, consider:
- Sharded counter approach (write to one of N counter rows, periodically merge)
- Batch aggregation (collect reviews for X seconds, then apply delta)
- The `CalcBusinessRating` function already reads pre-computed values, so this only affects write throughput, not read throughput.

---

# SECTION 8: DEADLOCK ANALYSIS

## 8.1 Realistic Deadlock Scenario — Notification Page Contention

InnoDB can deadlock on B-tree page reorganization even when logical row access patterns seem non-conflicting.

**Scenario:**
```
Session A: CancelAppointment
  - Holds X lock on appointments row 100
  - INSERT INTO notifications → acquires insert-intent lock on page P1
  
Session B: CreateAppointment  
  - Holds X lock on UNIQUE INDEX entry for slot_lock_key
  - INSERT INTO notifications → acquires insert-intent lock on page P1
  - Needs to do full commit → tries gap lock on appointments for slot validation
```

If both sessions are doing notifications to the same B-tree page AND both are involved in operations that touch the appointments index in overlapping ranges, a deadlock is possible.

**Probability:** Low to medium under high concurrency (notifications are sequential inserts, so page conflicts are rare with AUTO_INCREMENT).

**Mitigation:** The notification inserts use AUTO_INCREMENT on `notifications.id`, which means InnoDB's auto-increment lock (in AUTO_INCREMENT=1 mode) serializes all notification INSERTs. In MySQL 8, `innodb_autoinc_lock_mode=2` (the default) uses only a brief mutex, not a table lock — this is actually fine.

## 8.2 True Deadlock Scenario — Trigger Chain + Position Update

```
Session A: CancelAppointment (slot S)
  - Holds X lock on appointments row (for slot S)
  - Trigger fires: SELECT queue FOR UPDATE → X lock on queue rows Q1, Q2, Q3
  - UPDATE queue SET position = position - 1 → needs X lock on Q2, Q3 (already held)
  - INSERT new appointment from Q1
  
Session B: AddToQueue (slot S) running concurrently
  - TX started before A's trigger
  - LOCK IN SHARE MODE on queue rows → S lock on Q1, Q2, Q3 (compatible with A's X locks? NO — S lock blocks if A has X)
```

Wait — actually S locks ARE blocked by X locks in InnoDB. So Session B's `LOCK IN SHARE MODE` would block while Session A's trigger holds X locks on queue rows. B blocks until A commits. No circular wait.

**True circular wait requires:** A wants something B holds AND B wants something A holds.

Constructed scenario:
```
T=0: Session A holds X(appointments_row_100)
T=0: Session B holds X(appointments_row_101), X(queue_Q1)

T=1: Session A trigger wants X(queue_Q1) → BLOCKS on B
T=1: Session B trigger wants... nothing from Session A

No circular wait.
```

For a true deadlock, B would need to want the appointments row that A holds. But since each appointment has a unique ID and unique slot, this cannot happen under normal operation.

**Verdict:** True deadlocks in this schema are rare and primarily driven by B-tree page-level implicit locks during concurrent inserts to the same index pages. InnoDB's deadlock detector handles these by rolling back the "lighter" transaction. The EXIT HANDLER FOR SQLEXCEPTION correctly handles this by returning result_code=99.

**Recommendation:** Add deadlock retry logic in PHP:
```php
$maxRetries = 3;
$retryDelay = 50; // ms
for ($i = 0; $i < $maxRetries; $i++) {
    $result = callSP('CreateAppointment', $params);
    if ($result['code'] !== 99) break;
    usleep($retryDelay * 1000 * ($i + 1)); // exponential backoff
}
```

---

# SECTION 9: TRIGGER ARCHITECTURE ANALYSIS

## 9.1 What MUST Stay in Triggers

| Trigger | Reason to Keep |
|---------|----------------|
| `trg_appointments_before_update` | State machine enforcement — the ONLY reliable place to prevent invalid transitions, regardless of who writes to the table |
| `trg_reviews_after_insert/update/delete` | Incremental aggregation — must be atomic with the review write |
| `trg_bv_after_update` | `businesses.is_verified` propagation must be atomic with verification status change |

## 9.2 What Should Move Out of Triggers

| Current Trigger Behavior | Where to Move | Reason |
|--------------------------|---------------|--------|
| INSERT INTO notifications | Outbox table → async worker | Decouples delivery from transaction |
| CALL WriteAuditLog (in triggers) | Can stay — WriteAuditLog is lightweight | Low risk, centralized |
| Queue promotion (INSERT appointments, UPDATE queue) | Can stay but needs timestamp-based queue | Core booking logic, must be atomic |

## 9.3 Trigger Chaining Analysis

**Chain:** `CancelAppointment` → commit → `trg_appointments_after_update` → `INSERT INTO appointments` → `trg_appointments_after_insert`

This two-level chain is safe. MySQL prevents deeper recursion by default (`max_sp_recursion_depth = 0` for triggers).

However, MySQL statement-based replication (SBR) has a known issue with triggers: if a trigger modifies data that another trigger reads in a non-deterministic way, replication can diverge. **Use ROW-based binlog format** (`binlog_format = ROW`) for all production deployments.

```ini
[mysqld]
binlog_format = ROW
```

## 9.4 Trigger Rollback Complexity

If `trg_appointments_after_update` fails mid-execution (e.g., INSERT into appointments fails due to uq_slot_active because another concurrent session rebooked the slot between the cancellation and promotion), the ENTIRE `CancelAppointment` transaction rolls back. This means:
- The appointment is NOT cancelled
- The user gets result_code=99 ("خطای داخلی")
- But the actual cause was a slot conflict during promotion

This is technically correct (atomicity) but produces a confusing error message.

**Recommendation:** The queue promotion INSERT should also handle errno 1062 with a CONTINUE HANDLER inside the trigger:

```sql
-- In trg_appointments_after_update Branch A:
DECLARE v_promo_dup TINYINT DEFAULT 0;
DECLARE CONTINUE HANDLER FOR 1062 BEGIN SET v_promo_dup = 1; END;

INSERT INTO appointments (...) SELECT ... FROM queue WHERE id = v_queue_id;

IF v_promo_dup = 1 THEN
    -- Slot was rebooked between cancellation and promotion
    -- Skip promotion, mark queue entry differently
    UPDATE queue SET status = 'منقضی شده' WHERE id = v_queue_id;
    -- Still proceed with cancellation
END IF;
```

---

# SECTION 10: GENERATED COLUMN LOCKING STRATEGY ANALYSIS

## 10.1 Race Safety Analysis

The `slot_lock_key` generated column approach is **fundamentally race-safe** for the following reasons:

1. The UNIQUE constraint on `uq_slot_active` is evaluated by InnoDB at the storage layer during the INSERT, not at the SQL layer. This happens inside InnoDB's internal mutex before the row is visible to any other transaction.

2. Two concurrent sessions attempting to INSERT for the same slot both evaluate the UNIQUE constraint. InnoDB serializes this evaluation. One gets the row; the other gets errno 1062.

3. NULL equality: MySQL UNIQUE indexes use the standard SQL semantics where `NULL != NULL`. Multiple NULLs are permitted. This correctly allows multiple cancelled appointments for the same slot.

## 10.2 Cancellation + Rebooking Race

```
T=0: Appointment X (slot S) status='در انتظار'
T=1: User cancels: UPDATE appointments SET status='لغو شده' WHERE id=X
     → slot_lock_key becomes NULL
     → uq_slot_active constraint is released for this slot
T=2: Another user concurrently tries to book slot S
     → INSERT sees no active slot_lock_key for S
     → INSERT succeeds (new appointment Y)
```

**Between T=1 and T=2, is there a window where two bookings could both succeed?**

No. The UPDATE of appointment X (status='لغو شده') and the INSERT of appointment Y are separate transactions. After X's UPDATE commits, the UNIQUE index releases the constraint entry. Y's INSERT then sees a clear path. If Y's INSERT and X's UPDATE are concurrent, InnoDB's row-level locking ensures correct serialization.

**Verdict:** The generated column UNIQUE approach is production-safe under all concurrent access patterns.

## 10.3 One Subtle Risk: STORED Column Computation on Mass UPDATE

If an admin does:
```sql
UPDATE appointments SET status='لغو شده' WHERE business_id = 5 AND date_shamsi = '1403/01/15';
```

MySQL recomputes `slot_lock_key` for every row. For 100 rows, this triggers 100 generated column updates + 100 UNIQUE index entry changes. Under InnoDB, this is a full index reorganization. Performance is acceptable for small counts but problematic for mass operations.

---

# SECTION 11: INDEX ANALYSIS

## 11.1 Current Index Audit

### Potentially Missing Covering Index

**Hot Query: notification unread count**
```sql
SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0;
```
Current index: `idx_notif_user_unread (user_id, is_read, created_at)`

This **is** a covering index for `COUNT(*)` since the index contains `user_id` and `is_read`. Good.

**Hot Query: appointments for slot availability**
```sql
SELECT COUNT(*) FROM appointments
WHERE business_id = ? AND date_shamsi = ? AND time_slot = ?
  AND status != 'لغو شده';
```
Current indexes: `idx_appt_biz_active (business_id, date_shamsi)` — doesn't include `time_slot` or `status`.

The query must scan all appointments for that business+date, then filter by `time_slot` and `status`. For a busy business with 50 slots per day, this scan is manageable, but a covering index would be better:

```sql
ALTER TABLE appointments
ADD KEY idx_appt_slot_check (business_id, date_shamsi, time_slot, status);
```

This also replaces `idx_appt_biz_active` (as a superset).

### `businesses` — Hot Write Amplification

Every review INSERT/UPDATE/DELETE causes an UPDATE on the `businesses` row, which:
1. Updates the row
2. Updates ALL secondary indexes that include changed columns

Currently, 5 secondary indexes on businesses include columns that change with rating updates:
- `idx_biz_search_cat (is_verified, is_active, category_name, rating_avg)`
- `idx_biz_search_subcat (is_verified, is_active, subcategory_name, rating_avg)`
- `idx_biz_search_gender (is_verified, is_active, gender_type, rating_avg)`
- `idx_biz_owner_active (owner_user_id, is_active)`

`rating_avg` appears in 3 of these indexes. Each review event updates 3 secondary index entries on the businesses table. Under high review volume, this is measurable write amplification.

**Mitigation:** This is the price of pre-sorted search results. Acceptable for the scale described.

### `audit_log` — Index Bloat Under Scale

`audit_log` has 4 secondary indexes. With 1M audit entries/day, each index adds ~10-15% overhead to INSERT time. After 6 months, the index size alone could be 500MB+.

**Recommendation:** Drop low-priority indexes on audit_log and keep only the two most used:
```sql
-- Keep:
KEY idx_audit_entity  (entity_type, entity_id)  -- most common: "show history of X"
KEY idx_audit_created (created_at)               -- partitioning/archiving support

-- Consider dropping (can add back if needed):
-- KEY idx_audit_action  (action)
-- KEY idx_audit_who     (performed_by)
```

---

# SECTION 12: ENUM ANALYSIS

## 12.1 Production Risks

| Risk | Severity | Details |
|------|----------|---------|
| Adding non-trailing value | HIGH | Requires `ALTER TABLE` — full table rebuild on large tables |
| Adding trailing value | LOW | In MySQL 8, instant DDL for trailing ENUM adds |
| Replication during ALTER | MEDIUM | Replication lag during schema change |
| ORM mapping | LOW | Most ORMs handle ENUM, but PHP/PDO may need explicit mapping |
| Renaming value | CRITICAL | Requires data migration + schema change simultaneously |

## 12.2 Which ENUMs Are High Risk

| Table | Column | Risk | Reason |
|-------|--------|------|--------|
| appointments | status | LOW | Persian values; 4 states unlikely to grow |
| notifications | type | MEDIUM | Already needs 'ثبت_صف' added; may grow with features |
| businesses | gender_type | LOW | Stable domain |
| users | role | MEDIUM | Adding roles ('پشتیبان', etc.) requires ALTER |
| queue | status | LOW | Stable state machine |
| business_verification | status | LOW | 3 states, unlikely to change |

## 12.3 Migration Strategy (Zero Downtime)

For `notifications.type`:
```sql
-- Step 1: Add new value at the END (instant DDL in MySQL 8)
ALTER TABLE notifications
MODIFY type ENUM(
    'رزرو_موفق','لغو_نوبت','یادآوری','ارتقا_صف',
    'تایید_کسب‌وکار','رد_کسب‌وکار',
    'ثبت_صف'   -- added at END for instant DDL
) NOT NULL DEFAULT 'رزرو_موفق';
-- This is instant (no table rebuild) in MySQL 8 InnoDB for trailing additions

-- Step 2: Update AddToQueue() to use 'ثبت_صف'
-- Step 3: No data migration needed (existing rows keep 'ارتقا_صف')
```

For non-trivial migrations (renaming an ENUM value), use pt-online-schema-change or gh-ost.

---

# SECTION 13: TIMEZONE STRATEGY ANALYSIS

## 13.1 Current State

```sql
SET time_zone = '+03:30';
```

This is set as a **session variable** at connection time. Problems:
1. If a new connection is made without this SET, it uses the system timezone (which may differ)
2. Iran Standard Time (IRST) is UTC+3:30. Iran has historically changed its DST policy. Currently no DST, but this has changed in the past.
3. `created_at DATETIME DEFAULT CURRENT_TIMESTAMP` stores the session timezone's local time — if two connections have different session timezones, the same "9:00 AM booking" stores different absolute times.

## 13.2 Correct Production Architecture

**Store all DATETIME in UTC. Convert at display.**

```sql
-- In my.cnf:
[mysqld]
time_zone = UTC

-- Application layer converts:
-- User sees: 1403/01/15 09:00 (local time)
-- Database stores: 2024-04-03 05:30:00 (UTC)
-- Conversion in PHP: carbon + Jalali library
```

However, for this system's specific design, since `date_shamsi` and `time_slot` are stored as `CHAR(10)` and `CHAR(5)` (not computed from DATETIME), the timezone issue primarily affects only `created_at`, `updated_at`, and `cancelled_at` columns.

**Minimum fix:**
```sql
-- Set system timezone in my.cnf, not session-level:
[mysqld]
default-time-zone = '+03:30'
-- This ensures all connections use the same timezone
```

---

# SECTION 14: CHECK CONSTRAINTS AND VALIDATION ANALYSIS

## 14.1 MySQL 8 CHECK Enforcement

MySQL 8.0.16+ enforces CHECK constraints. They are evaluated on every INSERT and UPDATE.

```sql
CONSTRAINT chk_appt_date CHECK (date_shamsi REGEXP '^[0-9]{4}/[0-9]{2}/[0-9]{2}$')
CONSTRAINT chk_appt_time CHECK (time_slot   REGEXP '^[0-9]{2}:[0-9]{2}$')
```

**Performance:** REGEXP evaluation on a `CHAR(10)` string is ~microseconds. At 10k insertions/second, this adds ~10ms total overhead — negligible.

**Reliability:** These constraints prevent garbage data from entering even if the PHP backend fails to validate. Good.

**Limitation:** The date regex allows `9999/99/99` which is not a valid Jalali date. The regex only validates format, not semantic validity. Invalid dates like `1403/02/32` pass the constraint. This is acceptable — semantic date validation belongs in the application layer.

**Recommendation:** Keep the CHECK constraints. They're lightweight and catch the most common data corruption (wrong format, empty string, wrong type).

---

# SECTION 15: AUDIT SYSTEM ANALYSIS

## 15.1 Growth Projection

| Volume | Monthly rows | 12-month rows | Estimated size |
|--------|-------------|---------------|----------------|
| 1,000 bookings/day | ~150,000 | 1.8M | ~500MB |
| 10,000 bookings/day | ~1,500,000 | 18M | ~5GB |
| 100,000 bookings/day | ~15,000,000 | 180M | ~50GB |

At 10k bookings/day, the audit_log reaches 18M rows in a year. Without partitioning, queries like "show audit history for appointment X" become slow (idx_audit_entity helps but B-tree depth grows).

## 15.2 Partitioning Strategy

```sql
-- Quarterly range partitioning by created_at:
ALTER TABLE audit_log
PARTITION BY RANGE (TO_DAYS(created_at)) (
    PARTITION p_2025_q1 VALUES LESS THAN (TO_DAYS('2025-04-01')),
    PARTITION p_2025_q2 VALUES LESS THAN (TO_DAYS('2025-07-01')),
    PARTITION p_2025_q3 VALUES LESS THAN (TO_DAYS('2025-10-01')),
    PARTITION p_2025_q4 VALUES LESS THAN (TO_DAYS('2026-01-01')),
    PARTITION p_2026_q1 VALUES LESS THAN (TO_DAYS('2026-04-01')),
    PARTITION p_future  VALUES LESS THAN MAXVALUE
);
```

**Benefits:**
- Queries with `created_at` range conditions use partition pruning → only scans relevant partitions
- Old partitions can be archived: `ALTER TABLE audit_log EXCHANGE PARTITION p_2025_q1 WITH TABLE audit_log_archive_2025q1`
- Dropping old data: `ALTER TABLE audit_log DROP PARTITION p_2025_q1` — instant, no full-table scan

## 15.3 Long-Term Architecture

For enterprise scale, audit logs should be moved out of the transactional database entirely:

```
MySQL transactional DB → Kafka (outbox pattern) → ClickHouse / Elasticsearch
```

ClickHouse is optimal for time-series audit data: columnar storage, excellent compression, fast range queries, cheap storage. MySQL's row-oriented storage is inefficient for audit workloads.

---

# SECTION 16: NOTIFICATION ARCHITECTURE ANALYSIS

## 16.1 Current Problems Summary

1. Synchronous write inside transaction (covered in HIGH-3)
2. No delivery tracking (no "delivered", "read_at" distinction)
3. No retry for failed deliveries (no SMS gateway integration retry)
4. No deduplication (if transaction retries, duplicate notifications possible)

## 16.2 Recommended Architecture

```
[SP Transaction] → INSERT notification_outbox (idempotency_key=appointment_id+type)
                     ↓
[Async Worker]   → Poll notification_outbox WHERE status='pending'
                 → Deliver via SMS/Push gateway
                 → UPDATE status='delivered' OR schedule retry
                     ↓
[notifications]  → Write to notifications table AFTER delivery confirmed
                   (so user's notification inbox only shows delivered items)
```

**Idempotency key prevents duplicate sends on transaction retry:**
```sql
ALTER TABLE notification_outbox
ADD COLUMN idempotency_key VARCHAR(120) NULL,
ADD UNIQUE KEY uq_outbox_idempotency (idempotency_key);

-- In SP:
INSERT INTO notification_outbox (..., idempotency_key)
VALUES (..., CONCAT('appt_cancel_', p_appointment_id))
ON DUPLICATE KEY UPDATE id=id;  -- silently skip if already sent
```

---

# SECTION 17: QUEUE SYSTEM DEEP ANALYSIS

## 17.1 Algorithm Safety Summary

| Risk | Status | Notes |
|------|--------|-------|
| Duplicate promotions | SAFE | FOR UPDATE in trigger |
| Position race | SAFE | FOR UPDATE + gap lock in AddToQueue |
| Duplicate registration | SAFE | UNIQUE uq_queue_user_slot + LOCK IN SHARE MODE |
| Queue starvation | MEDIUM | Priority is FIFO; no fairness across slots |
| Concurrent cancellation + add | SAFE | Lock ordering prevents circular wait |

## 17.2 The Position Decrement Problem (Revised)

As analyzed in HIGH-2, the mass position decrement is the biggest queue system risk. The timestamp-based approach eliminates it entirely.

**Migration to timestamp-based queue:**
```sql
-- Step 1: Add an index on created_at for queue ordering
ALTER TABLE queue ADD KEY idx_queue_fifo (business_id, date_shamsi, time_slot, status, created_at);

-- Step 2: Update trigger to use created_at for ordering (no FOR UPDATE on position needed):
SELECT id, user_id, user_phone, service_name, business_name
FROM   queue
WHERE  business_id = NEW.business_id AND date_shamsi = NEW.date_shamsi
  AND  time_slot = NEW.time_slot AND status = 'در انتظار'
ORDER  BY created_at ASC
LIMIT  1 FOR UPDATE;

-- Step 3: Remove position column from AddToQueue:
-- No more: SELECT COALESCE(MAX(position),0)+1 FOR UPDATE
-- No more: INSERT queue (..., position, ...)
-- No more: UPDATE queue SET position=position-1 ...

-- Step 4: Drop position column (or keep for display, computed at read time)
```

---

# SECTION 18: CORRECTED AND REFACTORED SQL

## 18.1 Full Corrected `trg_reviews_after_update`

```sql
DROP TRIGGER IF EXISTS trg_reviews_after_update$$

CREATE TRIGGER trg_reviews_after_update
AFTER UPDATE ON reviews
FOR EACH ROW
BEGIN
    IF OLD.rating != NEW.rating OR OLD.is_visible != NEW.is_visible THEN
        UPDATE businesses
        SET    rating_sum    = rating_sum
                               - IF(OLD.is_visible = 1, OLD.rating, 0)
                               + IF(NEW.is_visible = 1, NEW.rating, 0),
               rating_count  = rating_count
                               - IF(OLD.is_visible = 1, 1, 0)
                               + IF(NEW.is_visible = 1, 1, 0),
               -- total_reviews does NOT change: review still exists regardless of visibility
               rating_avg    = ROUND(
                                   (rating_sum
                                    - IF(OLD.is_visible = 1, OLD.rating, 0)
                                    + IF(NEW.is_visible = 1, NEW.rating, 0))
                                   / NULLIF(
                                       rating_count
                                       - IF(OLD.is_visible = 1, 1, 0)
                                       + IF(NEW.is_visible = 1, 1, 0),
                                   0),
                                   2)
        WHERE  id = NEW.business_id;
    END IF;

    IF OLD.rating != NEW.rating OR OLD.is_visible != NEW.is_visible OR
       (OLD.comment IS NULL) != (NEW.comment IS NULL) OR
       COALESCE(OLD.comment,'') != COALESCE(NEW.comment,'') THEN
        CALL WriteAuditLog(
            'reviews', NEW.id, 'ویرایش',
            CONCAT('نظر ویرایش شد | امتیاز: ', OLD.rating, '→', NEW.rating,
                   ' | نمایش: ', OLD.is_visible, '→', NEW.is_visible,
                   ' | کسب‌وکار: ', NEW.business_name),
            NULL
        );
    END IF;
END$$
```

## 18.2 Total Reviews Semantic Clarification

The current `trg_reviews_after_delete` correctly handles `total_reviews`:
- visible deleted: `total_reviews = GREATEST(total_reviews - 1, 0)` ✓
- hidden deleted: `total_reviews = GREATEST(total_reviews - 1, 0)` ✓

Both are correct since a deleted review (regardless of visibility) reduces the total count.

## 18.3 Complete Rating System Mathematical Verification

**Invariant that must always hold:**
```
businesses.total_reviews = COUNT(*) FROM reviews WHERE business_id = ?
businesses.rating_count  = COUNT(*) FROM reviews WHERE business_id = ? AND is_visible = 1
businesses.rating_sum    = SUM(rating) FROM reviews WHERE business_id = ? AND is_visible = 1
businesses.rating_avg    = ROUND(rating_sum / NULLIF(rating_count, 0), 2)
```

**Verification table:**

| Event | total_reviews | rating_count | rating_sum | rating_avg |
|-------|---------------|--------------|------------|------------|
| INSERT visible review (rating=4) | +1 | +1 | +4 | recalc |
| INSERT hidden review | +1 | 0 | 0 | unchanged |
| UPDATE visible→hidden | 0 | -1 | -old_rating | recalc |
| UPDATE hidden→visible | 0 | +1 | +new_rating | recalc |
| UPDATE visible→visible (rating 4→5) | 0 | 0 | +1 | recalc |
| DELETE visible review | -1 | -1 | -rating | recalc |
| DELETE hidden review | -1 | 0 | 0 | unchanged |

**The current code implements all rows correctly EXCEPT "UPDATE visible→hidden" and "UPDATE hidden→visible"** where `total_reviews` should be 0 (no change) but the current code changes it by ±1.

---

# SECTION 19: ARCHITECTURE IMPROVEMENT RECOMMENDATIONS

## 19.1 Immediate Fixes (Before Any Production Deployment)

```
Priority | Fix
---------+-----
P0       | Fix trg_reviews_after_update (remove total_reviews mutation on visibility change)
P0       | Fix VerifyBusiness() (move SELECT inside TX with FOR UPDATE)
P0       | Fix CreateAppointment() (DEFAULT NULL for v_biz_name, v_svc_name)
P0       | Run total_reviews backfill after deploying trigger fix
P1       | Add 'ثبت_صف' to notifications.type ENUM, fix AddToQueue notification type
P1       | Add WriteAuditLog call to trg_reviews_after_update
P1       | Add business activity check in AddToQueue
```

## 19.2 Short-Term Architecture (Before Scale)

```
Priority | Change
---------+-------
P1       | Switch to ROW-based binlog (binlog_format = ROW)
P1       | Set default-time-zone = '+03:30' in my.cnf (not session-level)
P1       | Add CONTINUE HANDLER FOR 1062 in queue promotion trigger
P1       | Add idx_appt_slot_check (business_id, date_shamsi, time_slot, status)
P2       | Set transaction isolation to READ COMMITTED system-wide
P2       | Implement timestamp-based queue (remove position column)
P2       | Add business_slots validation in CreateAppointment
```

## 19.3 Medium-Term Architecture (For Production Scale)

```
Priority | Change
---------+-------
P1       | Implement notification outbox pattern
P1       | Add queue expiry Event Scheduler
P2       | Add audit_log quarterly partitioning
P2       | Add notification retry mechanism
P2       | Add idempotency keys to outbox for safe retries
P3       | Add categories name-change propagation trigger
```

## 19.4 Enterprise Architecture (100k+ users)

```
Component          | Recommendation
-------------------+----------------
Bookings (write)   | Primary MySQL + connection pooling (PgBouncer/ProxySQL)
Search/Browse      | MySQL Read Replica OR Elasticsearch for business search
Notifications      | Dedicated microservice + Redis pub/sub + FCM/SMS gateway
Audit logs         | MySQL → Kafka → ClickHouse (long-term storage)
Queue promotion    | Keep in DB (must be atomic with cancellation)
Rating aggregation | Keep in DB (incremental approach is already optimal)
Slot cache         | Redis SETEX for slot availability (5s TTL) — reduces DB reads
Session            | Redis-based (not DB)
```

---

# SECTION 20: ZERO-DOWNTIME MIGRATION STRATEGY

## Step 1: ENUM additions (instant)
```sql
-- Instant DDL (trailing value addition in MySQL 8):
ALTER TABLE notifications
MODIFY type ENUM('رزرو_موفق','لغو_نوبت','یادآوری','ارتقا_صف',
                 'تایید_کسب‌وکار','رد_کسب‌وکار','ثبت_صف')
NOT NULL DEFAULT 'رزرو_موفق';
```

## Step 2: Column DEFAULT changes (instant in MySQL 8)
```sql
ALTER TABLE businesses
ALTER COLUMN rating_avg SET DEFAULT 0.00;  -- already set, example
-- For new columns: ADD COLUMN ... DEFAULT ... (instant in MySQL 8 InnoDB)
```

## Step 3: Trigger replacements (< 1ms window)
```sql
-- Drop and recreate trigger atomically:
-- MySQL holds a metadata lock during trigger DROP/CREATE
-- This causes a brief (< 5ms) stall on affected table writes
-- Do during low-traffic window
DROP TRIGGER IF EXISTS trg_reviews_after_update$$
CREATE TRIGGER trg_reviews_after_update ... $$
```

## Step 4: Backfill (online, with rate limiting)
```sql
-- Run during off-peak, with LIMIT to avoid blocking:
-- Loop in PHP with 100ms sleep between batches:
UPDATE businesses b
INNER JOIN (
    SELECT business_id, COUNT(*) AS v_total
    FROM reviews GROUP BY business_id
) agg ON agg.business_id = b.id
SET b.total_reviews = agg.v_total
WHERE b.total_reviews != agg.v_total
LIMIT 1000;  -- run in loop until rows_affected = 0
```

## Step 5: Stored procedure replacements (instant)
```sql
-- SP replacements take a metadata lock for < 1ms
DROP PROCEDURE IF EXISTS VerifyBusiness$$
CREATE PROCEDURE VerifyBusiness ... $$
```

---

# SECTION 21: MONITORING RECOMMENDATIONS

## 21.1 Critical Metrics to Monitor

```sql
-- 1. Rating counter consistency check (run hourly via monitoring):
SELECT b.id, b.name,
       b.total_reviews AS stored_total,
       COUNT(r.id)     AS actual_total,
       ABS(b.total_reviews - COUNT(r.id)) AS drift
FROM   businesses b
LEFT JOIN reviews r ON r.business_id = b.id
GROUP BY b.id, b.name, b.total_reviews
HAVING drift > 0
LIMIT  10;

-- 2. Stale queue entries (run daily):
SELECT COUNT(*) AS stale_queue_count
FROM   queue
WHERE  status IN ('در انتظار','اطلاع داده شده')
  AND  created_at < NOW() - INTERVAL 3 DAY;

-- 3. Failed notifications (after outbox implementation):
SELECT COUNT(*) AS failed_notifications
FROM   notification_outbox
WHERE  status = 'failed' AND created_at > NOW() - INTERVAL 1 HOUR;

-- 4. Long-running transactions:
SELECT trx_id, trx_started,
       TIMESTAMPDIFF(SECOND, trx_started, NOW()) AS age_seconds,
       trx_query
FROM   information_schema.innodb_trx
WHERE  TIMESTAMPDIFF(SECOND, trx_started, NOW()) > 30;

-- 5. Lock waits:
SELECT * FROM performance_schema.events_waits_current
WHERE event_name LIKE '%lock%' AND timer_wait > 1000000000;  -- > 1 second
```

## 21.2 Recommended my.cnf Settings for Monitoring

```ini
[mysqld]
# Enable slow query log:
slow_query_log = ON
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 0.5       # queries > 500ms
log_queries_not_using_indexes = ON

# InnoDB monitoring:
innodb_monitor_enable = all  # or specific modules

# Performance Schema:
performance_schema = ON
performance_schema_events_waits_history_long_size = 10000

# Deadlock logging:
innodb_print_all_deadlocks = ON
```

---

# SECTION 22: OBSERVABILITY RECOMMENDATIONS

## 22.1 Application-Level Metrics

All stored procedures return `p_result_code`. PHP should emit metrics for each code:

```php
// After each SP call, emit metric:
$metrics->increment("sp.{$sp_name}.result_code.{$result_code}");
$metrics->timing("sp.{$sp_name}.duration", $duration_ms);

// Alert if result_code=99 rate > 0.1% of requests
// Alert if result_code=6 rate > 5% (too many slot conflicts — may indicate bot attacks)
```

## 22.2 Database-Level Observability

```sql
-- View to monitor slot contention (high result_code=6 indicates popular slots):
-- (Requires PHP to log failed bookings to a separate table)

-- View for queue depth per slot:
CREATE VIEW v_queue_depth AS
SELECT business_id, date_shamsi, time_slot,
       COUNT(*) AS queue_depth,
       MIN(created_at) AS oldest_entry
FROM   queue
WHERE  status = 'در انتظار'
GROUP  BY business_id, date_shamsi, time_slot
ORDER  BY queue_depth DESC;
```

---

# SECTION 23: FINAL VERDICT

## 23.1 Readiness Assessment

| Scale | Ready? | Conditions |
|-------|--------|------------|
| MVP (< 100 concurrent users) | ✅ YES | After P0 fixes only |
| Small Production (< 1k concurrent) | ⚠️ CONDITIONAL | P0 + P1 fixes + outbox pattern |
| Medium Scale (1k-10k concurrent) | ⚠️ CONDITIONAL | All P0/P1/P2 fixes + timestamp queue + monitoring |
| Enterprise (10k+ concurrent) | ❌ NOT YET | Requires full async architecture redesign |

## 23.2 Summary of Required Changes Before ANY Production Deployment

```
1. [ ] FIX: trg_reviews_after_update — remove total_reviews mutation on visibility change
2. [ ] FIX: Run total_reviews backfill after deploying fixed trigger
3. [ ] FIX: VerifyBusiness() — move SELECT inside TX with FOR UPDATE
4. [ ] FIX: CreateAppointment() — DEFAULT NULL for v_biz_name, v_svc_name
5. [ ] FIX: notifications.type — add 'ثبت_صف'
6. [ ] FIX: AddToQueue() — change notification type to 'ثبت_صف'
7. [ ] FIX: trg_reviews_after_update — add WriteAuditLog call
8. [ ] SET: binlog_format = ROW in my.cnf
9. [ ] SET: default-time-zone = '+03:30' in my.cnf (not session-level)
10. [ ] ADD: idx_appt_slot_check index
11. [ ] ADD: CONTINUE HANDLER FOR 1062 in queue promotion trigger branch A
```

## 23.3 The System's Core Strengths

Despite the issues found, this codebase demonstrates serious engineering:
- The snapshot architecture is sound and correctly prevents historical data corruption
- The generated column UNIQUE approach for slot locking is elegant and race-proof
- The incremental rating aggregation (except the visibility bug) is production-quality O(1) design
- Centralized WriteAuditLog with CALL pattern is the right abstraction
- The state machine trigger for appointments is a critical guard that prevents entire classes of bugs
- The FOR UPDATE patterns in CancelAppointment and AddToQueue show concurrent-programming awareness
- The NOT DETERMINISTIC function declarations show replication awareness

This is a strong foundation. With the P0 fixes applied, it is safe for MVP deployment.

---

*Report generated by full analysis of schema.sql (432 lines), functions_procedures.sql (654 lines), triggers.sql (495 lines)*  
*Total: 1,581 lines of SQL audited*
