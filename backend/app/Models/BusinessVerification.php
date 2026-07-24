<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessVerification extends Model
{
    protected $table = 'business_verification';

    public $timestamps = true;

    protected $fillable = [
        'business_id',
        'business_name',
        'owner_user_id',
        'owner_phone',
        'phone_verified',
        'address_text',
        'document_url',
        'admin_note',
        'reviewed_by',
        'status',
    ];

    protected $casts = [
        'phone_verified' => 'boolean',
        'status'         => 'string',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    // -------------------------------------------------------
    // Relationships
    // -------------------------------------------------------

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
