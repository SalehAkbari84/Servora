<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an owner-reply field to each review: business owners can respond to
 * customer feedback inline (one reply per review — overwriting allowed).
 *
 * Kept as columns on `reviews` (not a separate `review_replies` table)
 * because the relationship is strictly 1:1 and queries always need both
 * sides together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->text('owner_reply')->nullable()->after('comment')
                  ->comment('پاسخ صاحب کسب‌وکار به نظر کاربر');
            $table->dateTime('owner_reply_at')->nullable()->after('owner_reply')
                  ->comment('زمان ثبت پاسخ');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['owner_reply', 'owner_reply_at']);
        });
    }
};
