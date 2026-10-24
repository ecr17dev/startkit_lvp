<?php

namespace App\Actions\Ai;

use App\Models\User;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Starter\Ai\Enums\Driver;
use Starter\Ai\Models\Connection;
use Starter\Ai\Support\EndpointGuard;

/**
 * Create or update a provider connection. Credentials are encrypted or
 * referenced from deployment secrets; blank secrets keep the stored ones.
 */
class SaveConnection
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(?Connection $connection = null): array
    {
        return [
            'name' => [$connection ? 'sometimes' : 'required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('ai_provider_connections', 'name')->ignore($connection?->id)],
            'label' => [$connection ? 'sometimes' : 'required', 'string', 'max:100'],
            'driver' => [$connection ? 'sometimes' : 'required', Rule::enum(Driver::class)],
            'base_url' => ['nullable', 'string', 'max:255', 'url'],
            'credential_mode' => ['sometimes', 'in:stored,env'],
            'secret_ref' => ['nullable', 'string', 'max:120', function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && $value !== '' && ! Connection::isAllowedSecretRef($value)) {
                    $fail(__('Only AI provider keys can be referenced (env:OPENAI_API_KEY, env:STARTER_AI_SECRET_…).'));
                }
            }],
            'api_key' => ['nullable', 'string', 'max:2000'],
            'access_key_id' => ['nullable', 'string', 'max:255'],
            'secret_access_key' => ['nullable', 'string', 'max:2000'],
            'session_token' => ['nullable', 'string', 'max:4000'],
            'region' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
            'api_version' => ['nullable', 'string', 'max:40'],
            'deployment' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input, ?Connection $connection = null): Connection
    {
        Gate::forUser($actor)->authorize('ai.providers.manage');

        $validator = Validator::make($input, self::rules($connection));

        $validator->after(function ($validator) use ($input, $connection) {
            $driver = Driver::tryFrom((string) ($input['driver'] ?? $connection?->driver->value));

            if ($driver === null) {
                return;
            }

            $url = $input['base_url'] ?? ($connection?->base_url);

            if ($driver->requiresBaseUrl() && blank($url)) {
                $validator->errors()->add('base_url', __('This provider needs an endpoint URL.'));
            }

            if (filled($url) && ($violation = EndpointGuard::violation((string) $url, in_array($driver, [Driver::Ollama, Driver::Custom], true)))) {
                $validator->errors()->add('base_url', __($violation));
            }

            $mode = $input['credential_mode'] ?? (filled($connection?->secret_ref) ? 'env' : 'stored');
            $hasStoredKey = filled($connection?->credentials['api_key'] ?? null);

            if ($mode === 'env' && blank($input['secret_ref'] ?? $connection?->secret_ref)) {
                $validator->errors()->add('secret_ref', __('Enter the environment variable that holds the key.'));
            }

            if ($mode === 'stored' && $driver->requiresApiKey() && blank($input['api_key'] ?? null) && ! $hasStoredKey) {
                $validator->errors()->add('api_key', __('The API key is required.'));
            }
        });

        /** @var array<string, mixed> $data */
        $data = $validator->validate();

        return DB::transaction(function () use ($actor, $data, $connection) {
            $connection ??= new Connection(['created_by' => $actor->id]);
            $isNew = ! $connection->exists;
            $mode = $data['credential_mode'] ?? (filled($connection->secret_ref) ? 'env' : 'stored');

            $connection->fill(Arr::only($data, ['name', 'label', 'driver', 'base_url', 'is_active', 'is_default']));

            $driver = $connection->driver;
            $options = [];

            foreach ($driver->options() as $option) {
                $options[$option] = $data[$option] ?? $connection->option($option);
            }

            $connection->options = array_filter($options, fn ($value) => $value !== null && $value !== '') ?: null;

            // Secrets: blank keeps the current value; the env mode never stores the key...
            $credentials = (array) ($connection->credentials ?? []);
            $rotated = false;

            foreach ($driver->credentials() as $key) {
                if ($key === 'api_key' && $mode === 'env') {
                    $rotated = $rotated || array_key_exists('api_key', $credentials);
                    unset($credentials['api_key']);

                    continue;
                }

                if (filled($data[$key] ?? null) && ($credentials[$key] ?? null) !== $data[$key]) {
                    $credentials[$key] = $data[$key];
                    $rotated = true;
                }
            }

            $secretRef = $mode === 'env' ? ($data['secret_ref'] ?? $connection->secret_ref) : null;
            $rotated = $rotated || $secretRef !== $connection->secret_ref;

            $connection->credentials = $credentials ?: null;
            $connection->secret_ref = $secretRef;

            if ($rotated && ! $isNew) {
                $connection->credentials_rotated_at = now();
            }

            $changes = array_keys(Arr::except($connection->getDirty(), ['credentials', 'updated_at']));
            $connection->save();

            if ($connection->is_default) {
                Connection::query()->whereKeyNot($connection->id)->update(['is_default' => false]);
            }

            audit($isNew ? 'ai.connections.created' : 'ai.connections.updated', $connection, array_filter([
                'driver' => $connection->driver->value,
                'changed' => $isNew ? null : $changes,
                'credentials_rotated' => $rotated && ! $isNew ? true : null,
            ]), $actor);

            return $connection;
        });
    }
}
