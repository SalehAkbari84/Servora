<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    protected $table = 'appointments';

    public $timestamps = true;

    /**
     * slot_lock_key is a GENERATED ALWAYS column — never include in fillable.
     */
    protected $fillable = [
        'user_id',
        'user_name',
        'user_phone',
        'business_id',
        'business_name',
        'service_id',
        'service_name',
        'duration_minutes',
        'price',
        'date_shamsi',
        'time_slot',
        'status',
        'cancel_reason',
        'cancelled_by',
        'cancelled_at',
    ];

    protected $casts = [
        'status'           => 'string',
        'price'            => 'decimal:0',
        'duration_minutes' => 'integer',
        'cancelled_at'     => 'datetime',
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

    public function review(): HasOne
    {
        return $this->hasOne(Review::class, 'appointment_id');
    }
}
