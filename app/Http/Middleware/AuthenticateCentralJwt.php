<?php

namespace App\Http\Middleware;

use App\Models\ServerMember;
use App\Services\CentralJwtService;
use Closure;
use Firebase\JWT\ExpiredException;
use Illuminate\Http\Request;

class AuthenticateCentralJwt
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

        $userId   = $payload->sub;
        $username = $payload->username ?? 'Unknown';

        $member = ServerMember::with(['roles'])->find($userId);

        if (! $member) {
            return response()->json(['error' => 'not_a_member'], 403);
        }

        if ($member->status === 'banned') {
            return response()->json(['error' => 'membership_banned', 'message' => 'You have been banned from this server.'], 403);
        }

        if ($member->status === 'pending') {
            return response()->json(['error' => 'membership_pending', 'message' => 'Your request to join this server is pending approval.'], 403);
        }

        $member->update(['username' => $username, 'last_seen_at' => now(), 'presence' => 'online']);

        $request->attributes->set('central_user_id', $userId);
        $request->attributes->set('username', $member->username);
        $request->attributes->set('member', $member);

        return $next($request);
    }
}
