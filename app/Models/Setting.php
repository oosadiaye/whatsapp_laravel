<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    public static function get(string $key, $default = null)
    {
        $setting = static::where('key', $key)->first();

        return $setting ? $setting->value : $default;
    }

    public static function set(string $key, $value): static
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
    }

    /**
     * Read an encrypted setting value, decrypting via Laravel's Crypt facade
     * (uses APP_KEY internally). Returns $default on missing key or decrypt
     * failure (e.g., APP_KEY changed without re-encrypting). Decrypt failure
     * is intentionally swallowed: caller-side ConfigurationException surfaces
     * the misconfiguration when the empty value is consumed.
     */
    public static function getEncrypted(string $key, ?string $default = null): ?string
    {
        $raw = static::get($key);
        if ($raw === null) {
            return $default;
        }

        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($raw);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * Write an encrypted setting value via Crypt::encryptString. Always
     * round-trippable with getEncrypted as long as APP_KEY is unchanged.
     */
    public static function setEncrypted(string $key, string $value): static
    {
        return static::set($key, \Illuminate\Support\Facades\Crypt::encryptString($value));
    }
}
