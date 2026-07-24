<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $table = 'messages';

    /** This table is append-only — no updated_at column. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'conversation_id', 'sender_id', 'sender_type', 'body', 'is_read',
    ];

    protected $casts = [
        'is_read'    => 'boolean',
        'created_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }
}
