<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `business_verification` rows for any business that doesn't
 * already have a verification record.
 *
 * Before this migration, owners had to submit a separate verification
 * request after registering. Many skipped that step, so the admin's
 * verification queue showed nothing while plenty of unverified
 * businesses sat in limbo. We now auto-create the request on business
 * creation (see OwnerController::createBusiness), and this migration
 * catches up historical data so admins can act on existing accounts.
 *
 * Status assignment:
 *   - is_verified = 1  →  status = 'تایید شده'  (already approved, just record it)
 *   - is_verified = 0  →  status = 'در انتظار'  (queue for review)
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $missing = DB::table('businesses as b')
            ->leftJoin('business_verification as v', 'v.business_id', '=', 'b.id')
            ->whereNull('v.id')
            ->select('b.*')
            ->get();

        foreach ($missing as $b) {
            DB::table('business_verification')->insert([
                'business_id'    => $b->id,
                'business_name'  => $b->name,
                'owner_user_id'  => $b->owner_user_id,
                'owner_phone'    => $b->owner_phone ?? '',
                'phone_verified' => 0,
                'address_text'   => $b->address_text ?? '',
                'document_url'   => null,
                'admin_note'     => null,
                'reviewed_by'    => null,
                'status'         => $b->is_verified ? 'تایید شده' : 'در انتظار',
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Reversal would require knowing which rows were backfilled vs
        // legitimately submitted by owners — we can't safely distinguish,
        // so the down() is intentionally a no-op.
    }
};
