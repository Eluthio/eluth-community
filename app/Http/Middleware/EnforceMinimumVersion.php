<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnforceMinimumVersion
{
    /**
     * Routes that admins can always reach regardless of version status.
     * Everything else gets a maintenance page when the server is out of date.
     */
    const ADMIN_PREFIXES = [
        '/api/admin',
        '/api/members/presence',
        '/api/operators',
        '/admin',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $minVersion = Cache::get('eluth_min_version');

        // No minimum enforced yet (central hasn't been synced, or no requirement set)
        if (! $minVersion) return $next($request);

        $installed = trim(file_get_contents(base_path('VERSION')) ?: '0.0.0');

        if (version_compare($installed, $minVersion, '>=')) return $next($request);

        // Server is behind the minimum — allow admins through, block everyone else
        if ($this->isAdminRoute($request)) return $next($request);

        $latestVersion = Cache::get('eluth_latest_version', '');
        $slug          = config('eluth.update_backend_slug', env('UPDATE_BACKEND_SLUG', ''));
        $updateUrl     = $slug ? url($slug) : null;

        // API requests get a JSON error
        if ($request->expectsJson() || str_starts_with($request->path(), 'api/')) {
            return response()->json([
                'error'   => 'server_update_required',
                'message' => "This server requires an update (installed: {$installed}, required: {$minVersion}).",
            ], 503);
        }

        // Browser requests get a maintenance page
        return response($this->maintenancePage($installed, $minVersion, $latestVersion, $updateUrl), 503);
    }

    private function isAdminRoute(Request $request): bool
    {
        // Check if authenticated user is an admin
        if ($request->bearerToken()) {
            try {
                $payload = json_decode(base64_decode(explode('.', $request->bearerToken())[1] ?? ''), true);
                $userId  = $payload['sub'] ?? null;
                if ($userId) {
                    $isAdmin = DB::table('members')->where('central_user_id', $userId)->value('is_admin');
                    if ($isAdmin) return true;
                }
            } catch (\Throwable) {
                // Not a valid token — not an admin
            }
        }

        foreach (self::ADMIN_PREFIXES as $prefix) {
            if (str_starts_with('/' . $request->path(), $prefix)) return true;
        }

        return false;
    }

    private function maintenancePage(string $installed, string $min, string $latest, ?string $updateUrl): string
    {
        $updateLink = $updateUrl
            ? "<a href=\"" . htmlspecialchars($updateUrl) . "\" class=\"btn\">Open Update Manager</a>"
            : "<p>Run <code>php artisan eluth:update</code> on your server.</p>";

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Server Update Required</title>
        <style>
            *{box-sizing:border-box;margin:0;padding:0}
            body{font-family:system-ui,sans-serif;background:#050810;color:rgba(255,255,255,.88);
                 display:flex;align-items:center;justify-content:center;min-height:100vh}
            .card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);
                  border-radius:12px;padding:48px;max-width:480px;text-align:center}
            .icon{font-size:48px;margin-bottom:20px}
            h1{font-size:22px;font-weight:700;margin-bottom:12px}
            p{color:rgba(255,255,255,.55);font-size:14px;line-height:1.6;margin-bottom:8px}
            .versions{display:flex;gap:16px;justify-content:center;margin:24px 0;flex-wrap:wrap}
            .ver{background:rgba(255,255,255,.05);border-radius:8px;padding:12px 20px}
            .ver-label{font-size:11px;color:rgba(255,255,255,.35);text-transform:uppercase;margin-bottom:4px}
            .ver-num{font-size:18px;font-weight:700}
            .ver-num--alert{color:#fb923c}
            .btn{display:inline-block;margin-top:24px;padding:10px 24px;background:#22d3ee;
                 color:#050810;border-radius:7px;font-weight:700;text-decoration:none;font-size:14px}
            code{background:rgba(255,255,255,.08);padding:2px 6px;border-radius:4px;font-size:13px}
        </style>
        </head>
        <body>
        <div class="card">
            <div class="icon">⬡</div>
            <h1>Server Update Required</h1>
            <p>This server needs to be updated before members can access it.</p>
            <p>Administrators can still log in and use the Update Manager.</p>
            <div class="versions">
                <div class="ver">
                    <div class="ver-label">Installed</div>
                    <div class="ver-num ver-num--alert">v{$installed}</div>
                </div>
                <div class="ver">
                    <div class="ver-label">Required</div>
                    <div class="ver-num">v{$min}</div>
                </div>
                <div class="ver">
                    <div class="ver-label">Latest</div>
                    <div class="ver-num">v{$latest}</div>
                </div>
            </div>
            {$updateLink}
        </div>
        </body>
        </html>
        HTML;
    }
}
