<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\PostgRest\PostgRest;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('PostgREST', weight: 90)]
class PostgRestTokenController extends Controller
{
    /**
     * Exchange the session or token for a short PostgREST JWT (direct
     * client access). Only when POSTGREST_DIRECT_TOKENS=true; the JWT
     * carries the role of the published objects and the principals of the
     * user, and expires in POSTGREST_JWT_TTL seconds.
     */
    public function __invoke(Request $request, PostgRest $postgrest): JsonResponse
    {
        abort_unless($postgrest->enabled() && config('starter.postgrest.direct_tokens') && $postgrest->signer()->configured(), 404);

        $user = $this->actor($request);
        $claims = $postgrest->claims($user);

        audit('postgrest.token_issued', null, ['expires_at' => $claims['exp']], $user);

        return response()->json(['data' => [
            'token' => $postgrest->signer()->sign($claims),
            'expires_at' => date(DATE_ATOM, (int) $claims['exp']),
            'url' => $postgrest->url(),
        ]]);
    }
}
