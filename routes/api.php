<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\ChannelController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\MemberController;
use Illuminate\Support\Facades\Route;

// Public: server info (reads DB settings first, auto-seeds from central if not yet set)
Route::get('/server', function () {
    $settings = \DB::table('server_settings')->pluck('value', 'key');

    $name     = $settings['server_name'] ?? null;
    $joinMode = $settings['join_mode']   ?? config('server.join_mode', 'open');

    // First boot: if no name stored locally and OPERATOR_ID is configured, fetch from central
    if (! $name && $operatorId = config('server.operator_id')) {
        $centralUrl = config('services.central.url', '');
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(4)
                ->get($centralUrl . '/api/operators/' . $operatorId . '/bootstrap');

            if ($response->successful()) {
                $name = $response->json('server_name');
                // Persist so we don't fetch on every request
                \DB::table('server_settings')->upsert(
                    [['key' => 'server_name', 'value' => $name, 'created_at' => now(), 'updated_at' => now()]],
                    ['key'], ['value', 'updated_at']
                );
            }
        } catch (\Throwable) {
            // Central unreachable — fall through to env default
        }
    }

    $bool = fn ($v) => $v === '1' || $v === 'true';

    return response()->json([
        'name'             => $name ?? config('server.name', 'Community Server'),
        'domain'           => config('server.domain'),
        'join_mode'        => $joinMode,
        'logo'             => $settings['logo']             ?? null,
        'background_type'  => $settings['background_type']  ?? 'none',
        'background_value' => $settings['background_value'] ?? null,
        'primary_color'    => $settings['primary_color']    ?? null,
        'accent_color'     => $settings['accent_color']     ?? null,
        'welcome_enabled'  => $bool($settings['welcome_enabled']  ?? '0'),
        'welcome_message'  => $settings['welcome_message']  ?? null,
        'rules_enabled'    => $bool($settings['rules_enabled']    ?? '0'),
        'rules'            => $settings['rules']            ?? null,
        'require_rules_ack'=> $bool($settings['require_rules_ack'] ?? '0'),
    ]);
});

// Join request — JWT required but membership not required
Route::middleware('verify.jwt')->post('/join', function (\Illuminate\Http\Request $request) {
    $userId   = $request->attributes->get('central_user_id');
    $username = $request->attributes->get('username');

    $existing = \App\Models\ServerMember::find($userId);
    if ($existing) {
        return response()->json(['error' => 'already_a_member', 'status' => $existing->status], 409);
    }

    // Read join mode from DB (may have been updated via settings panel)
    $settings = \DB::table('server_settings')->pluck('value', 'key');
    $joinMode = $settings['join_mode'] ?? env('SERVER_JOIN_MODE', 'open');
    $status   = $joinMode === 'request' ? 'pending' : 'member';

    \App\Models\ServerMember::create([
        'central_user_id' => $userId,
        'username'        => $username,
        'status'          => $status,
        'role'            => 'member',
        'presence'        => 'online',
        'joined_at'       => now(),
        'last_seen_at'    => now(),
    ]);

    // Assign the default role (if open mode — pending members get it on approval instead)
    if ($status === 'member') {
        $defaultRole = \App\Models\Role::where('is_default', true)->first();
        if ($defaultRole) {
            \DB::table('member_roles')->insertOrIgnore([
                'central_user_id' => $userId,
                'role_id'         => $defaultRole->id,
            ]);
        }
    }

    return response()->json(['status' => $status]);
});

// Authenticated routes — require valid central server JWT
Route::middleware('auth.central')->group(function () {
    Route::get('/channels', [ChannelController::class, 'index']);

    Route::get('/channels/{channel}/messages', [MessageController::class, 'index']);
    Route::post('/channels/{channel}/messages', [MessageController::class, 'store']);

    Route::get('/members', [MemberController::class, 'index']);
    Route::post('/members/heartbeat',         [MemberController::class, 'heartbeat']);
    Route::post('/members/dismiss-welcome',   [MemberController::class, 'dismissWelcome']);
    Route::post('/members/presence', [MemberController::class, 'updatePresence']);

    // Server overview / appearance / content
    Route::post('/admin/server',            [AdminController::class, 'updateServer']);
    Route::post('/admin/server/appearance', [AdminController::class, 'updateAppearance']);
    Route::post('/admin/server/content',    [AdminController::class, 'updateContent']);
    Route::post('/admin/upload',            [AdminController::class, 'uploadAsset']);

    // Sections
    Route::post('/admin/sections',                    [ChannelController::class, 'createSection']);
    Route::post('/admin/sections/{section}/delete',   [ChannelController::class, 'deleteSection']);

    // Channels
    Route::post('/admin/channels',                              [ChannelController::class, 'createChannel']);
    Route::post('/admin/channels/{channel}',                    [ChannelController::class, 'updateChannel']);
    Route::post('/admin/channels/{channel}/delete',             [ChannelController::class, 'deleteChannel']);
    Route::get('/admin/channels/{channel}/permissions',         [ChannelController::class, 'getPermissions']);
    Route::post('/admin/channels/{channel}/permissions',        [ChannelController::class, 'updatePermissions']);

    // Join requests
    Route::get('/admin/join-requests', [AdminController::class, 'joinRequests']);
    Route::post('/admin/join-requests/{userId}/approve', [AdminController::class, 'approve']);
    Route::post('/admin/join-requests/{userId}/deny', [AdminController::class, 'deny']);

    // Member actions
    Route::post('/admin/members/{userId}/kick', [AdminController::class, 'kickMember']);
    Route::post('/admin/members/{userId}/ban',  [AdminController::class, 'banMember']);
    Route::post('/admin/members/{userId}/roles/{roleId}',    [AdminController::class, 'assignRole']);
    Route::delete('/admin/members/{userId}/roles/{roleId}',  [AdminController::class, 'removeRole']);

    // Roles — standard REST + POST aliases used by the settings panel
    Route::get('/admin/roles',                        [AdminController::class, 'listRoles']);
    Route::post('/admin/roles',                       [AdminController::class, 'createRole']);
    Route::put('/admin/roles/{roleId}',               [AdminController::class, 'updateRole']);
    Route::post('/admin/roles/{roleId}/update',       [AdminController::class, 'updateRole']);
    Route::delete('/admin/roles/{roleId}',            [AdminController::class, 'deleteRole']);
    Route::post('/admin/roles/{roleId}/delete',       [AdminController::class, 'deleteRole']);
});
