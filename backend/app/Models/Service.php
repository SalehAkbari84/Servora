<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Service extends Model
{
    protected $table = 'services';

    // services table has created_at but no updated_at
    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'business_id',
        'business_name',
        'service_name',
        'category_name',
        'gender_type',
        'duration_minutes',
        'price',
        'is_active',
    ];

    protected $casts = [
        'is_active'        => 'boolean',
        'price'            => 'decimal:0',
        'duration_minutes' => 'integer',
        'created_at'       => 'datetime',
    ];

    // -------------------------------------------------------
    // Relationships
    // -------------------------------------------------------

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'business_id');
    }
}
