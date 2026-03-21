<?php

namespace App\Http\Middleware;

use App\Services\CentralJwtService;
use Closure;
use Firebase\JWT\ExpiredException;
use Illuminate\Http\Request;

class VerifyCentralJwt
{
    public function __construct(private CentralJwtService $jwt) {}

    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        try {
            $payload = $this->jwt->decode($token);
        } catch (ExpiredException) {
            return response()->json(['error' => 'token_expired'], 401);
        } catch (\Throwable) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        $request->attributes->set('central_user_id', $payload->sub);
        $request->attributes->set('username', $payload->username ?? 'Unknown');

        return $next($request);
    }
}
