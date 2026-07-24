<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class name AppNotification to avoid collision with
 * Illuminate\Notifications\Notification and similar classes.
 * Maps to the `notifications` table.
 */
class AppNotification extends Model
{
    protected $table = 'notifications';

    // notifications has no updated_at column
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'user_phone',
        'type',
        'title',
        'body',
        'url',
        'related_entity_type',
        'related_entity_id',
        'is_read',
    ];

    protected $casts = [
        'is_read'    => 'boolean',
        'created_at' => 'datetime',
    ];

    // -------------------------------------------------------
    // Relationships
    // -------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
