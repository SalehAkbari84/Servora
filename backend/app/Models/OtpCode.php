<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    protected $table = 'otp_codes';
    public $timestamps = false;

    protected $fillable = [
        'phone', 'code_hash', 'purpose', 'attempts',
        'expires_at', 'verified_at', 'consumed_at',
        'provider_message_id', 'ip',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'verified_at' => 'datetime',
        'consumed_at' => 'datetime',
        'created_at'  => 'datetime',
        'attempts'    => 'integer',
    ];
}
