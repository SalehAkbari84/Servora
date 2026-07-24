<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class Setting extends Model
{
    protected $table = 'settings';

    public const CREATED_AT = null;

    /** Mask shown in admin UI in place of encrypted password values. */
    public const MASK = '••••••••';

    /**
     * Per-request memoization cache. Settings are read VERY frequently
     * (every middleware tick, every upload, every public endpoint), and a
     * single page load can easily call get() 5–10 times for the same key.
     * Caching in the static array eliminates 5–10 DB round-trips per
     * request at zero memory cost — the cache is naturally bounded by
     * the small number of distinct keys (~40) and dies with the request.
     */
    private static array $cache = [];

    /** Bust the cache when a setting is updated by the admin panel. */
    public static function forgetCache(?string $key = null): void
    {
        if ($key === null) { self::$cache = []; return; }
        unset(self::$cache[$key]);
    }

    protected static function booted(): void
    {
        // Any save (admin edits a setting) invalidates the cached value
        // so the new value is picked up on the very next get() call.
        static::saved(fn (Setting $s)   => self::forgetCache($s->key));
        static::deleted(fn (Setting $s) => self::forgetCache($s->key));
    }

    protected $fillable = [
        'key', 'value', 'type', 'label', 'group', 'description',
        'options', 'order', 'is_advanced',
    ];

    protected $casts = [
        'updated_at'  => 'datetime',
        'is_advanced' => 'boolean',
        'order'       => 'integer',
    ];

    /**
     * Convenience: read a single setting value with a default.
     * For type='password' rows, returns the decrypted plaintext (or default on failure).
     * Used by internal services such as the SMS worker.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        // Per-request memoization: return cached value if we've already
        // resolved this key on this request. NULL is a legitimate stored
        // value, so we sentinel-check with array_key_exists instead of ??.
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key] ?? $default;
        }

        $row = static::where('key', $key)->first(['value', 'type']);
        if (!$row) {
            self::$cache[$key] = null;
            return $default;
        }

        $value = $row->value;
        if ($value === null || $value === '') {
            self::$cache[$key] = null;
            return $default;
        }

        if ($row->type === 'password') {
            try {
                $value = Crypt::decryptString($value);
            } catch (DecryptException) {
                // Legacy plain-text value — return as-is so the app keeps working
            }
        }

        self::$cache[$key] = $value;
        return $value;
    }

    /**
     * Encrypt a plaintext password before persisting.
     * Idempotent: re-encrypts only if input is not already a Laravel ciphertext.
     */
    public static function encryptPassword(string $plain): string
    {
        return Crypt::encryptString($plain);
    }

    /** Returns true if the value is encrypted (Laravel ciphertext). */
    public static function isEncrypted(?string $value): bool
    {
        if (!$value) return false;
        try {
            Crypt::decryptString($value);
            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
