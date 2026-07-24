<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessSlot extends Model
{
    protected $table = 'business_slots';

    // No timestamp columns at all
    public $timestamps = false;

    protected $fillable = [
        'business_id',
        'business_name',
        'day_of_week',
        'time_slot',
        'max_capacity',
        'is_active',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'day_of_week'  => 'integer',
        'max_capacity' => 'integer',
    ];

    /**
     * Persian day names. day_of_week: 0=شنبه ... 6=جمعه
     */
    private static array $dayNames = [
        0 => 'شنبه',
        1 => 'یکشنبه',
        2 => 'دوشنبه',
        3 => 'سه‌شنبه',
        4 => 'چهارشنبه',
        5 => 'پنجشنبه',
        6 => 'جمعه',
    ];

    /**
     * Accessor: getDayNameAttribute() → $slot->day_name
     */
    public function getDayNameAttribute(): string
    {
        return self::$dayNames[$this->day_of_week] ?? 'نامشخص';
    }

    // -------------------------------------------------------
    // Relationships
    // -------------------------------------------------------

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'business_id');
    }
}
