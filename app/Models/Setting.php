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

    /**
     * In-process read cache (audit L12). Settings are read repeatedly
     * (default_country_code, voice config, ...) but rarely change; caching the
     * resolved value avoids a DB round-trip per call. A distinct MISS sentinel
     * lets us cache "no such row" separately from a stored null.
     *
     * SCOPE — this is process-static, NOT request-scoped: PHP-FPM resets statics
     * per request, but a long-lived queue worker keeps them across jobs. So it's
     * flushed before each queued job (AppServiceProvider::boot) and between tests
     * (Tests\TestCase::setUp), and invalidated on any model save/delete in the
     * same process (see booted()).
     *
     * @var array<string, mixed>
     */
    protected static array $cache = [];

    private const MISS = "\0__setting_miss__\0";

    protected static function booted(): void
    {
        // Any model-level write or delete invalidates the request cache. Mass
        // query-builder deletes (Setting::query()->...->delete()) bypass model
        // events, so code doing that must call flushCache() itself — but
        // production only ever writes through set()/model deletes.
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }

    public static function get(string $key, $default = null)
    {
        if (! array_key_exists($key, static::$cache)) {
            $setting = static::where('key', $key)->first();
            static::$cache[$key] = $setting ? $setting->value : self::MISS;
        }

        $value = static::$cache[$key];

        return $value === self::MISS ? $default : $value;
    }

    public static function set(string $key, $value): static
    {
        $model = static::updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );

        static::$cache[$key] = $value;

        return $model;
    }

    /**
     * Clear the request-scoped cache. Called between tests so a value written in
     * one test can't leak into the next (the DB is truncated by RefreshDatabase,
     * but this in-memory cache is process-static).
     */
    public static function flushCache(): void
    {
        static::$cache = [];
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
            // A decrypt failure almost always means APP_KEY was rotated without
            // re-encrypting stored secrets. Surface it (don't fail silently) so
            // "credentials suddenly missing" is diagnosable from the log.
            \Illuminate\Support\Facades\Log::warning('Setting::getEncrypted decrypt failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

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
