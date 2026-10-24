<?php

namespace App\Settings;

use App\Models\Setting;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class SettingsManager
{
    /**
     * The resolved settings for the current request.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $resolved = null;

    public function __construct(protected Cache $cache) {}

    /**
     * Get a setting value using "dot" notation (e.g. "branding.app_name").
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->all(), $key, $default);
    }

    /**
     * Get every setting of the given group.
     *
     * @return array<string, mixed>
     */
    public function group(string $group): array
    {
        return (array) $this->get($group, []);
    }

    /**
     * Get every setting merged over the configured defaults.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $settings = (array) config('settings.defaults', []);

        foreach ($this->stored() as $key => $row) {
            Arr::set($settings, $key, $this->decode($row['value'], $row['encrypted']));
        }

        return $this->resolved = $settings;
    }

    /**
     * Persist the given settings. Keys use "dot" notation.
     *
     * @param  array<string, mixed>  $values
     */
    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            $encrypted = $this->isEncrypted($key);

            Setting::query()->updateOrCreate(['key' => $key], [
                'value' => $this->encode($value, $encrypted),
                'encrypted' => $encrypted,
            ]);
        }

        $this->flush();
    }

    /**
     * Persist the given values for a group, prefixing every key.
     *
     * @param  array<string, mixed>  $values
     */
    public function setGroup(string $group, array $values): void
    {
        $this->set(Arr::mapWithKeys($values, fn ($value, $key) => ["{$group}.{$key}" => $value]));
    }

    /**
     * Remove the stored values so the defaults apply again.
     *
     * @param  array<int, string>  $keys
     */
    public function forget(array $keys): void
    {
        Setting::query()->whereIn('key', $keys)->delete();

        $this->flush();
    }

    /**
     * Clear the cached settings.
     */
    public function flush(): void
    {
        $this->resolved = null;

        $this->cache->forget($this->cacheKey());
    }

    /**
     * Determine whether the given key is stored encrypted.
     */
    public function isEncrypted(string $key): bool
    {
        return in_array($key, (array) config('settings.encrypted', []), true);
    }

    /**
     * Get the raw stored rows, cached forever until a setting changes.
     *
     * Encrypted values stay encrypted inside the cache store.
     *
     * @return array<string, array{value: string|null, encrypted: bool}>
     */
    protected function stored(): array
    {
        try {
            return $this->cache->rememberForever($this->cacheKey(), fn () => Setting::query()
                ->get(['key', 'value', 'encrypted'])
                ->mapWithKeys(fn (Setting $setting) => [$setting->key => [
                    'value' => $setting->value,
                    'encrypted' => $setting->encrypted,
                ]])
                ->all());
        } catch (Throwable) {
            // The settings table may not exist yet (fresh install, before migrating)...
            return [];
        }
    }

    protected function encode(mixed $value, bool $encrypted): ?string
    {
        if ($value === null) {
            return null;
        }

        $json = json_encode($value, JSON_THROW_ON_ERROR);

        return $encrypted ? Crypt::encryptString($json) : $json;
    }

    protected function decode(?string $value, bool $encrypted): mixed
    {
        if ($value === null) {
            return null;
        }

        try {
            $json = $encrypted ? Crypt::decryptString($value) : $value;
        } catch (DecryptException) {
            return null;
        }

        return json_decode($json, true);
    }

    protected function cacheKey(): string
    {
        return (string) config('settings.cache_key', 'app.settings');
    }
}
