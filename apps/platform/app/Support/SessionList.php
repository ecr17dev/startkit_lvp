<?php

namespace App\Support;

use App\Actions\Users\RevokeSessions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Read the browser sessions stored by the database session driver.
 */
class SessionList
{
    /**
     * @return list<array{id: string, ip_address: string|null, browser: string, platform: string, last_active_at: string, current: bool}>
     */
    public static function forUser(int $userId, ?string $currentSessionId = null): array
    {
        return array_values(DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->orderByDesc('last_activity')
            ->limit(50)
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session) => [
                'id' => RevokeSessions::publicId((string) $session->id),
                'ip_address' => is_string($session->ip_address) ? $session->ip_address : null,
                ...self::describe((string) $session->user_agent),
                'last_active_at' => Carbon::createFromTimestamp((int) $session->last_activity)->toIso8601String(),
                'current' => $currentSessionId !== null && hash_equals((string) $session->id, $currentSessionId),
            ])
            ->all());
    }

    /**
     * Get a readable browser and platform from a user agent.
     *
     * @return array{browser: string, platform: string}
     */
    public static function describe(string $userAgent): array
    {
        $browser = match (true) {
            Str::contains($userAgent, 'Edg/') => 'Edge',
            Str::contains($userAgent, 'OPR/') => 'Opera',
            Str::contains($userAgent, 'Firefox/') => 'Firefox',
            Str::contains($userAgent, 'Chrome/') => 'Chrome',
            Str::contains($userAgent, 'Safari/') => 'Safari',
            Str::contains($userAgent, ['curl/', 'Guzzle', 'Symfony']) => 'HTTP client',
            default => 'Unknown',
        };

        $platform = match (true) {
            Str::contains($userAgent, ['iPhone', 'iPad']) => 'iOS',
            Str::contains($userAgent, 'Android') => 'Android',
            Str::contains($userAgent, 'Windows') => 'Windows',
            Str::contains($userAgent, ['Macintosh', 'Mac OS']) => 'macOS',
            Str::contains($userAgent, 'Linux') => 'Linux',
            default => 'Unknown',
        };

        return ['browser' => $browser, 'platform' => $platform];
    }
}
