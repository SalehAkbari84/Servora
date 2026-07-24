<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushSubscription extends Model
{
    protected $table = 'push_subscriptions';

    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'endpoint',
        'endpoint_hash',
        'p256dh',
        'auth',
        'user_agent',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
