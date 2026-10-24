<?php

namespace Starter\Administration\Audit;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Starter\Administration\Models\AuditEvent;

/**
 * Records who did what, from which channel and on which resource.
 *
 * The same actions are reachable from the panel, the API, the CLI and the
 * AI chat tools: the channel tells them apart in the audit trail.
 */
class Auditor
{
    public const CHANNEL_WEB = 'web';

    public const CHANNEL_API = 'api';

    public const CHANNEL_CLI = 'cli';

    public const CHANNEL_CHAT = 'chat';

    public const CHANNEL_SYSTEM = 'system';

    /**
     * Keys whose values are never written to the audit trail.
     *
     * @var list<string>
     */
    protected array $sensitive = [
        'password', 'password_confirmation', 'current_password', 'secret', 'token',
        'api_key', 'code', 'verifier', 'two_factor_secret', 'recovery_codes',
    ];

    protected ?string $channel = null;

    /**
     * Record an audit event.
     *
     * @param  array<string, mixed>  $properties
     */
    public function record(string $action, ?Model $subject = null, array $properties = [], ?Authenticatable $actor = null): AuditEvent
    {
        $actor ??= Auth::user();
        $channel = $this->channel();
        $request = $channel === self::CHANNEL_CLI ? null : $this->request();

        return AuditEvent::query()->create([
            'action' => $action,
            'channel' => $channel,
            'actor_id' => $actor?->getAuthIdentifier(),
            'actor_label' => $actor instanceof Model ? (string) ($actor->getAttribute('email') ?? $actor->getKey()) : null,
            'subject_type' => $subject !== null ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'properties' => $properties === [] ? null : $this->redact($properties),
            'ip_address' => $request?->ip(),
            'user_agent' => $request !== null ? (Str::limit((string) $request->userAgent(), 500, '') ?: null) : null,
            'request_id' => Context::get('request_id'),
        ]);
    }

    /**
     * Run the callback recording its events under the given channel.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function via(string $channel, Closure $callback): mixed
    {
        $previous = $this->channel;
        $this->channel = $channel;

        try {
            return $callback();
        } finally {
            $this->channel = $previous;
        }
    }

    /**
     * Get the channel of the current execution.
     */
    public function channel(): string
    {
        if ($this->channel !== null) {
            return $this->channel;
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return self::CHANNEL_CLI;
        }

        return $this->request()?->is('api/*') ? self::CHANNEL_API : self::CHANNEL_WEB;
    }

    protected function request(): ?Request
    {
        return app()->bound('request') ? app('request') : null;
    }

    /**
     * Remove secrets from the event properties.
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    protected function redact(array $properties): array
    {
        foreach ($properties as $key => $value) {
            if (is_string($key) && in_array(Str::lower($key), $this->sensitive, true)) {
                $properties[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $properties[$key] = $this->redact($value);
            }
        }

        return $properties;
    }
}
