<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * In-process read cache (audit L12). Settings are read repeatedly
     * (default_country_code, voice config, ...) but rarely change. The WHOLE
     * (small) table is loaded in ONE query on first access — so N distinct
     * Setting::get() calls in a request cost a single query, not one per key. A
     * key absent from the map means "no such row" → the caller's default;
     * present-with-null means a genuinely stored null.
     *
     * SCOPE — process-static, NOT request-scoped: PHP-FPM resets statics per
     * request, but a long-lived queue worker keeps them across jobs. So it's
     * flushed before each queued job (AppServiceProvider::boot) and between tests
     * (Tests\TestCase::setUp), and invalidated on any model save/delete in the
     * same process (see booted()).
     *
     * @var array<string, mixed>
     */
    protected static array $cache = [];

    /** Whether {@see $cache} has been primed from the DB this process. */
    protected static bool $primed = false;

    protected static function booted(): void
    {
        // Any model-level write or delete invalidates the cache. Mass query-
        // builder deletes (Setting::query()->...->delete()) bypass model events,
        // so code doing that must call flushCache() itself — but production only
        // ever writes through set()/model deletes.
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }

    public static function get(string $key, $default = null)
    {
        static::primeCache();

        return array_key_exists($key, static::$cache) ? static::$cache[$key] : $default;
    }

    /**
     * Load the whole settings table into the process cache in one query. Cheap —
     * the table is a handful of rows — and turns per-key reads into per-request.
     */
    protected static function primeCache(): void
    {
        if (static::$primed) {
            return;
        }

        static::$cache = static::query()->pluck('value', 'key')->all();
        static::$primed = true;
    }

    public static function set(string $key, $value): static
    {
        // updateOrCreate fires the saved event → flushCache(); the next read
        // re-primes from the DB, so the cache can never serve a stale value.
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
    }

    /**
     * Clear the process cache. Called between tests so a value written in one
     * test can't leak into the next (the DB is truncated by RefreshDatabase, but
     * this in-memory cache is process-static).
     */
    public static function flushCache(): void
    {
        static::$cache = [];
        static::$primed = false;
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
            return Crypt::decryptString($raw);
        } catch (\Throwable $e) {
            // A decrypt failure almost always means APP_KEY was rotated without
            // re-encrypting stored secrets. Surface it (don't fail silently) so
            // "credentials suddenly missing" is diagnosable from the log.
            Log::warning('Setting::getEncrypted decrypt failed', [
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
        return static::set($key, Crypt::encryptString($value));
    }
}
