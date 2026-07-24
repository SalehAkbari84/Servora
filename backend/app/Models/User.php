<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'users';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'username',
        'full_name',
        'phone',
        'password_hash',
        'role',
        'avatar_url',
        'is_active',
        'last_seen_at',
        'is_primary_admin',
        'permissions',
    ];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'is_active'        => 'boolean',
        'is_primary_admin' => 'boolean',
        'permissions'      => 'array',
        'last_seen_at'     => 'datetime',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    /**
     * Does this admin have access to the given section? Primary admins get
     * an implicit pass for every section (including `settings`, which is
     * never granted via permissions list).
     */
    public function canAccessSection(string $section): bool
    {
        if ($this->role !== 'ادمین' || !$this->is_active) return false;
        if ($this->is_primary_admin) return true;
        // `settings` is primary-only — never granted via permissions JSON
        if ($section === 'settings' || $section === 'admins') return false;
        $perms = $this->permissions ?? [];
        return is_array($perms) && in_array($section, $perms, true);
    }

    public function getAuthIdentifierName(): string
    {
        return 'phone';
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'user_id');
    }

    public function queueEntries(): HasMany
    {
        return $this->hasMany(QueueEntry::class, 'user_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'user_id');
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class, 'owner_user_id');
    }
}
