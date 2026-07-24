<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $table = 'reviews';

    // reviews has created_at but no updated_at
    const UPDATED_AT = null;

    protected $fillable = [
        'business_id',
        'business_name',
        'appointment_id',
        'service_id',
        'service_name',
        'date_shamsi',
        'user_id',
        'user_name',
        'rating',
        'comment',
        'owner_reply',
        'owner_reply_at',
        'is_visible',
    ];

    protected $casts = [
        'is_visible'     => 'boolean',
        'rating'         => 'integer',
        'created_at'     => 'datetime',
        'owner_reply_at' => 'datetime',
    ];

    // -------------------------------------------------------
    // Relationships
    // -------------------------------------------------------

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
}
