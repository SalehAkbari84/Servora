<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix the three review triggers:
 *   1. trg_reviews_after_insert
 *   2. trg_reviews_after_update
 *   3. trg_reviews_after_delete
 *
 * Bug discovered:
 *   MySQL evaluates SET assignments left-to-right and column references on
 *   the RHS see the values *already set* by earlier expressions in the same
 *   statement. The original triggers re-applied the delta when computing
 *   rating_avg, causing double-counting (e.g. UPDATE 4→5 produced avg=6).
 *
 * Fix:
 *   Compute rating_avg directly from the already-updated rating_sum and
 *   rating_count columns instead of re-deriving them from old/new values.
 *
 * Side-effect:
 *   Recompute all businesses' rating_sum/count/avg/total_reviews from the
 *   real `reviews` table — the previous seed data left fake numbers that
 *   no longer correspond to actual rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Drop the broken triggers ─────────────────────────────
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_reviews_after_insert`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_reviews_after_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_reviews_after_delete`');

        // ── Recreate: INSERT ─────────────────────────────────────
        // rating_avg uses the *just-updated* rating_sum / rating_count
        // (MySQL evaluates SET expressions left-to-right).
        DB::unprepared(<<<'SQL'
        CREATE TRIGGER `trg_reviews_after_insert`
        AFTER INSERT ON `reviews` FOR EACH ROW
        BEGIN
            IF NEW.is_visible = 1 THEN
                UPDATE businesses
                SET    rating_sum    = rating_sum + NEW.rating,
                       rating_count  = rating_count + 1,
                       total_reviews = total_reviews + 1,
                       rating_avg    = ROUND(rating_sum / NULLIF(rating_count, 0), 2)
                WHERE  id = NEW.business_id;
            ELSE
                UPDATE businesses
                SET    total_reviews = total_reviews + 1
                WHERE  id = NEW.business_id;
            END IF;

            CALL WriteAuditLog(
                'reviews', NEW.id, 'ثبت',
                CONCAT('نظر جدید | امتیاز: ', NEW.rating,
                       ' | کسب‌وکار: ', NEW.business_name,
                       ' | کاربر: ', NEW.user_name),
                NEW.user_id
            );
        END
        SQL);

        // ── Recreate: UPDATE ─────────────────────────────────────
        // Same fix. Also re-orders so rating_count is updated before
        // rating_avg references it.
        DB::unprepared(<<<'SQL'
        CREATE TRIGGER `trg_reviews_after_update`
        AFTER UPDATE ON `reviews` FOR EACH ROW
        BEGIN
            DECLARE v_audit_desc TEXT DEFAULT '';

            IF OLD.rating != NEW.rating OR OLD.is_visible != NEW.is_visible THEN
                UPDATE businesses
                SET    rating_sum   = rating_sum
                                      - IF(OLD.is_visible = 1, OLD.rating, 0)
                                      + IF(NEW.is_visible = 1, NEW.rating, 0),
                       rating_count = rating_count
                                      - IF(OLD.is_visible = 1, 1, 0)
                                      + IF(NEW.is_visible = 1, 1, 0),
                       rating_avg   = ROUND(rating_sum / NULLIF(rating_count, 0), 2)
                WHERE  id = NEW.business_id;
            END IF;

            IF OLD.rating != NEW.rating THEN
                SET v_audit_desc = CONCAT(v_audit_desc, ' | امتیاز: ', OLD.rating, ' → ', NEW.rating);
            END IF;

            IF OLD.is_visible != NEW.is_visible THEN
                SET v_audit_desc = CONCAT(v_audit_desc, ' | نمایش: ',
                                          IF(OLD.is_visible, 'فعال', 'غیرفعال'),
                                          ' → ',
                                          IF(NEW.is_visible, 'فعال', 'غیرفعال'));
            END IF;

            IF v_audit_desc != '' THEN
                CALL WriteAuditLog(
                    'reviews', NEW.id, 'ویرایش',
                    CONCAT('نظر ویرایش شد | کسب‌وکار: ', NEW.business_name,
                           ' | کاربر: ', NEW.user_name, v_audit_desc),
                    NULL
                );
            END IF;
        END
        SQL);

        // ── Recreate: DELETE ─────────────────────────────────────
        DB::unprepared(<<<'SQL'
        CREATE TRIGGER `trg_reviews_after_delete`
        AFTER DELETE ON `reviews` FOR EACH ROW
        BEGIN
            IF OLD.is_visible = 1 THEN
                UPDATE businesses
                SET    rating_sum    = GREATEST(rating_sum - OLD.rating, 0),
                       rating_count  = GREATEST(rating_count - 1, 0),
                       total_reviews = GREATEST(total_reviews - 1, 0),
                       rating_avg    = COALESCE(
                                           ROUND(rating_sum / NULLIF(rating_count, 0), 2),
                                           0.00)
                WHERE  id = OLD.business_id;
            ELSE
                UPDATE businesses
                SET    total_reviews = GREATEST(total_reviews - 1, 0)
                WHERE  id = OLD.business_id;
            END IF;

            CALL WriteAuditLog(
                'reviews', OLD.id, 'حذف',
                CONCAT('نظر حذف شد | امتیاز: ', OLD.rating,
                       ' | کسب‌وکار: ', OLD.business_name,
                       ' | کاربر: ', OLD.user_name),
                NULL
            );
        END
        SQL);

        // ── Recompute all businesses from the real `reviews` table ──
        //   * rating_sum / rating_count / rating_avg from is_visible=1 rows
        //   * total_reviews from ALL rows (visible or not)
        // This wipes out the seeded fake numbers and re-establishes truth.
        DB::statement(<<<'SQL'
        UPDATE businesses b
        LEFT JOIN (
            SELECT business_id,
                   SUM(rating) AS sum_,
                   COUNT(*)    AS cnt_,
                   ROUND(AVG(rating), 2) AS avg_
            FROM   reviews
            WHERE  is_visible = 1
            GROUP  BY business_id
        ) v ON v.business_id = b.id
        LEFT JOIN (
            SELECT business_id, COUNT(*) AS total_
            FROM   reviews
            GROUP  BY business_id
        ) a ON a.business_id = b.id
        SET b.rating_sum    = COALESCE(v.sum_, 0),
            b.rating_count  = COALESCE(v.cnt_, 0),
            b.rating_avg    = COALESCE(v.avg_, 0.00),
            b.total_reviews = COALESCE(a.total_, 0)
        SQL);
    }

    public function down(): void
    {
        // Reverting just drops the fixed triggers — the original buggy ones
        // are recoverable from `database/servora.sql` if ever needed.
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_reviews_after_insert`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_reviews_after_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_reviews_after_delete`');
    }
};
