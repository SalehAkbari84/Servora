<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class name QueueEntry to avoid collision with Laravel's Queue facade/class.
 * Maps to the `queue` table.
 */
class QueueEntry extends Model
{
    protected $table = 'queue';

    public $timestamps = true;

    protected $fillable = [
        'business_id',
        'business_name',
        'user_id',
        'user_name',
        'user_phone',
        'service_id',
        'service_name',
        'duration_minutes',
        'price',
        'date_shamsi',
        'time_slot',
        'status',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'price'            => 'decimal:0',
        'status'           => 'string',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    // -------------------------------------------------------
    // Relationships
    // -------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
}
