<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $table = 'conversations';

    protected $fillable = [
        'user_id', 'business_id', 'business_name', 'user_name',
        'last_message_at', 'last_message_preview', 'last_message_sender',
        'unread_for_user', 'unread_for_owner',
    ];

    protected $casts = [
        'last_message_at'  => 'datetime',
        'unread_for_user'  => 'integer',
        'unread_for_owner' => 'integer',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id')->orderBy('created_at');
    }
}
