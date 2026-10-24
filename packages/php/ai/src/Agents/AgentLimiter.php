<?php

namespace Starter\Ai\Agents;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Starter\Ai\Exceptions\AgentLimitExceededException;
use Starter\Ai\Models\AgentVersion;

/**
 * Per-user limits of an agent: requests per minute and concurrent calls.
 * Concurrency uses one cache lock per slot, so it works with the database
 * cache store (no Redis required).
 */
class AgentLimiter
{
    /**
     * Seconds a slot stays taken if the release never happens (crash).
     */
    public const SLOT_TTL = 300;

    /**
     * Take a slot for a call. Returns the callback that frees it.
     *
     * @return Closure(): void
     *
     * @throws AgentLimitExceededException
     */
    public function acquire(AgentVersion $version, Authenticatable $user): Closure
    {
        $config = $version->settings();
        $key = 'ai-agent:'.$version->agent_id.':user:'.$user->getAuthIdentifier();

        if (($perMinute = $config->requestsPerMinute()) !== null) {
            if (RateLimiter::tooManyAttempts($key, $perMinute)) {
                $seconds = RateLimiter::availableIn($key);

                throw new AgentLimitExceededException((string) __('Too many messages. Try again in :seconds seconds.', ['seconds' => $seconds]), $seconds);
            }

            RateLimiter::hit($key, 60);
        }

        $slots = $config->concurrentPerUser();

        if ($slots === null) {
            return static function (): void {};
        }

        for ($slot = 0; $slot < $slots; $slot++) {
            $lock = Cache::lock($key.':slot:'.$slot, self::SLOT_TTL);

            if ($lock->get()) {
                return static function () use ($lock): void {
                    $lock->release();
                };
            }
        }

        throw new AgentLimitExceededException((string) __('You already have :count answers in progress with this agent. Wait for them to finish.', ['count' => $slots]));
    }
}
