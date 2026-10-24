<?php

namespace Starter\Administration\Health;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class HealthController extends Controller
{
    /**
     * Liveness: the PHP process answers. Never touches dependencies.
     */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * Readiness: the dependencies required to serve traffic are reachable.
     *
     * Details are only included for local environments to avoid leaking
     * infrastructure information.
     */
    public function ready(HealthChecker $checker): JsonResponse
    {
        $checks = $checker->run();
        $ready = $checker->ready($checks);

        return response()->json([
            'status' => $ready ? 'ok' : 'unavailable',
            'checks' => app()->isLocal()
                ? array_map(fn (HealthCheck $check) => $check->toArray(), $checks)
                : array_map(fn (HealthCheck $check) => ['name' => $check->name, 'status' => $check->status], $checks),
        ], $ready ? 200 : 503);
    }
}
