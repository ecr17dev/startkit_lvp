<?php

namespace Starter\Administration\Health;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Readiness checks of the application and the services it needs.
 *
 * Required checks (database, cache, storage) decide readiness. Optional
 * services only become required when their feature flag is enabled, so a
 * disabled RAG or PostgREST never makes the application unhealthy.
 */
class HealthChecker
{
    public const SCHEDULER_HEARTBEAT_KEY = 'starter:scheduler:heartbeat';

    /**
     * Extra checks registered by other modules.
     *
     * @var array<string, array{callback: Closure(): HealthCheck, required: Closure(): bool}>
     */
    protected array $extra = [];

    /**
     * Register an extra check.
     *
     * @param  Closure(): HealthCheck  $callback
     * @param  (Closure(): bool)|null  $required
     */
    public function register(string $name, Closure $callback, ?Closure $required = null): void
    {
        $this->extra[$name] = ['callback' => $callback, 'required' => $required ?? fn () => false];
    }

    /**
     * Run every check.
     *
     * @return list<HealthCheck>
     */
    public function run(): array
    {
        $checks = [
            $this->measure('database', true, function () {
                DB::connection()->select('select 1');

                return [HealthCheck::OK, DB::connection()->getDriverName()];
            }),
            $this->measure('cache', true, function () {
                $key = 'starter:health:'.Str::random(8);
                Cache::put($key, 'ok', 10);
                $value = Cache::pull($key);

                return $value === 'ok' ? [HealthCheck::OK, (string) config('cache.default')] : [HealthCheck::FAILED, 'Cache did not return the stored value.'];
            }),
            $this->measure('storage', true, function () {
                $path = 'health/'.Str::random(8).'.txt';
                Storage::disk('local')->put($path, 'ok');
                Storage::disk('local')->delete($path);

                return [HealthCheck::OK, null];
            }),
            $this->measure('queue', false, function () {
                if (config('queue.default') !== 'database') {
                    return [HealthCheck::OK, (string) config('queue.default')];
                }

                $table = (string) config('queue.connections.database.table', 'jobs');
                $pending = DB::table($table)->count();
                $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

                return [$failed > 0 ? HealthCheck::WARNING : HealthCheck::OK, "pending={$pending} failed={$failed}"];
            }),
            $this->measure('scheduler', false, function () {
                $heartbeat = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);

                if (! is_string($heartbeat)) {
                    return [HealthCheck::WARNING, 'No heartbeat yet. Is `php artisan schedule:work` running?'];
                }

                $age = Carbon::parse($heartbeat)->diffInSeconds(now());

                return [$age > 180 ? HealthCheck::WARNING : HealthCheck::OK, "last heartbeat {$heartbeat}"];
            }),
        ];

        foreach ($this->extra as $name => $check) {
            $required = (bool) ($check['required'])();

            try {
                $result = ($check['callback'])();
                $checks[] = new HealthCheck($name, $result->status, $required, $result->message, $result->durationMs);
            } catch (Throwable $e) {
                $checks[] = new HealthCheck($name, HealthCheck::FAILED, $required, $e->getMessage());
            }
        }

        return $checks;
    }

    /**
     * Determine whether every required check passed.
     *
     * @param  list<HealthCheck>  $checks
     */
    public function ready(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check->blocksReadiness()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  Closure(): array{0: string, 1: string|null}  $callback
     */
    protected function measure(string $name, bool $required, Closure $callback): HealthCheck
    {
        $started = microtime(true);

        try {
            [$status, $message] = $callback();
        } catch (Throwable $e) {
            [$status, $message] = [HealthCheck::FAILED, Str::limit($e->getMessage(), 300)];
        }

        return new HealthCheck($name, $status, $required, $message, round((microtime(true) - $started) * 1000, 2));
    }
}
