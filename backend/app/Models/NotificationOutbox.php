<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationOutbox extends Model
{
    protected $table = 'notification_outbox';

    public $timestamps = true;

    protected $fillable = [
        'idempotency_key',
        'user_id',
        'user_phone',
        'type',
        'title',
        'body',
        'related_entity_type',
        'related_entity_id',
        'status',
        'attempt_count',
        'next_retry_at',
        'processed_at',
    ];

    protected $casts = [
        'attempt_count' => 'integer',
        'next_retry_at' => 'datetime',
        'processed_at'  => 'datetime',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    // -------------------------------------------------------
    // Relationships
    // -------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
