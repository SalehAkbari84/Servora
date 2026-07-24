<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Business extends Model
{
    protected $table = 'businesses';

    public $timestamps = true;

    /** Append computed full-URL alongside the raw `logo_url` path. */
    protected $appends = ['logo_url_full'];

    protected $fillable = [
        'owner_user_id',
        'owner_name',
        'owner_phone',
        'name',
        'logo_url',
        'category_id',
        'category_name',
        'subcategory_id',
        'subcategory_name',
        'gender_type',
        'description',
        'address_text',
        'province_code',
        'province_name',
        'city',
        'phone',
        'is_verified',
        'is_active',
        'rating_avg',
        'rating_sum',
        'rating_count',
        'total_reviews',
    ];

    protected $casts = [
        'is_verified'  => 'boolean',
        'is_active'    => 'boolean',
        'rating_avg'   => 'decimal:2',
        'rating_sum'   => 'decimal:2',
        'rating_count' => 'integer',
        'total_reviews' => 'integer',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    // -------------------------------------------------------
    // Relationships
    // -------------------------------------------------------

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'business_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'business_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'business_id');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(BusinessSlot::class, 'business_id');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(BusinessVerification::class, 'business_id');
    }

    // -------------------------------------------------------
    // Accessors
    // -------------------------------------------------------

    /**
     * Logo as a full URL (e.g. http://localhost:8000/storage/logos/xxx.jpg)
     * or null when the business hasn't uploaded one yet.
     */
    protected function logoUrlFull(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->logo_url
                ? Storage::disk('public')->url($this->logo_url)
                : null,
        );
    }
}
