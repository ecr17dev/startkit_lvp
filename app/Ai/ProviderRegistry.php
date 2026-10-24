<?php

namespace App\Ai;

use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\AiManager;
use Throwable;

/**
 * Loads the AI providers configured from the admin panel into the
 * "ai" configuration used by the Laravel AI SDK.
 */
class ProviderRegistry
{
    public const CACHE_KEY = 'ai.providers.registry';

    /**
     * The provider names registered by the last call to apply().
     *
     * @var list<string>
     */
    protected static array $applied = [];

    /**
     * Register the database providers into the runtime configuration.
     */
    public static function apply(): void
    {
        $registry = static::load();

        foreach (array_diff(static::$applied, array_keys($registry['providers'])) as $stale) {
            config()->offsetUnset("ai.providers.{$stale}");
        }

        foreach ($registry['providers'] as $name => $config) {
            config(["ai.providers.{$name}" => $config]);
        }

        static::$applied = array_keys($registry['providers']);

        if ($registry['default'] !== null) {
            config(['ai.default' => $registry['default']]);
        }
    }

    /**
     * Forget the cached registry and the resolved SDK provider instances.
     */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);

        if (app()->resolved(AiManager::class)) {
            app(AiManager::class)->forgetInstance(array_keys((array) config('ai.providers', [])));
        }

        static::apply();
    }

    /**
     * Get the price entry of the given provider and model.
     */
    public static function modelFor(string $provider, string $model): ?AiModel
    {
        return AiModel::query()
            ->whereHas('provider', fn ($query) => $query->where('name', $provider))
            ->where('model', $model)
            ->first();
    }

    /**
     * Get the default provider and model pair.
     *
     * @return array{provider: string|null, model: string|null}
     */
    public static function defaults(): array
    {
        $registry = static::load();
        $provider = $registry['default'];

        return [
            'provider' => $provider,
            'model' => $provider !== null ? ($registry['providers'][$provider]['models']['text']['default'] ?? null) : null,
        ];
    }

    /**
     * Load the providers from the cache or the database.
     *
     * Secrets stay encrypted inside the cache store: the cached
     * payload is encrypted as a whole with the application key.
     *
     * @return array{providers: array<string, array<string, mixed>>, default: string|null}
     */
    protected static function load(): array
    {
        try {
            $payload = Cache::rememberForever(self::CACHE_KEY, fn () => encrypt(static::build()));

            return decrypt($payload);
        } catch (Throwable) {
            return ['providers' => [], 'default' => null];
        }
    }

    /**
     * @return array{providers: array<string, array<string, mixed>>, default: string|null}
     */
    protected static function build(): array
    {
        $providers = AiProvider::query()->active()->orderByDesc('is_default')->orderBy('id')->get();

        return [
            'providers' => $providers->mapWithKeys(fn (AiProvider $provider) => [$provider->name => $provider->toAiConfig()])->all(),
            'default' => $providers->first()?->name,
        ];
    }
}
