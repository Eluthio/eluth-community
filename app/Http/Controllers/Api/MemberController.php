<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServerMember;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    // A member is considered online if they sent a heartbeat within the last 90 seconds
    private const ONLINE_WINDOW = 90;

    private function effectivePresence(ServerMember $m): string
    {
        if ($m->presence === 'offline') return 'offline';
        if (! $m->last_seen_at)        return 'offline';

        $stale = now()->timestamp - $m->last_seen_at->timestamp > self::ONLINE_WINDOW;
        return $stale ? 'offline' : $m->presence;
    }

    public function index(\Illuminate\Http\Request $request)
    {
        $members = ServerMember::where('status', 'member')
            ->with(['roles.permissions'])
            ->orderBy('username')
            ->get();

        // If a channel_id is given, exclude members whose roles all deny can_view
        if ($channelId = $request->query('channel_id')) {
            $deniedRoleIds = \DB::table('channel_permission_overwrites')
                ->where('channel_id', $channelId)
                ->where('can_view', false)
                ->pluck('role_id')
                ->flip(); // use as a set for O(1) lookup

            $members = $members->filter(function ($m) use ($deniedRoleIds) {
                if ($m->isSuperAdmin()) return true;

                $roleIds = $m->roles->pluck('id');
                if ($roleIds->isEmpty()) return true; // no roles = default access

                // Member can view if at least one of their roles is NOT denied
                return $roleIds->some(fn ($id) => ! $deniedRoleIds->has($id));
            });
        }

        return response()->json([
            'members' => $members->map(fn ($m) => [
                'id'              => $m->central_user_id,
                'username'        => $m->username,
                'presence'        => $this->effectivePresence($m),
                'roles'           => $m->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'color' => $r->color]),
                'is_super_admin'  => $m->isSuperAdmin(),
                'permissions'     => $m->isSuperAdmin() ? ['*'] : $m->roles->flatMap(fn ($r) => $r->permissions->pluck('permission'))->unique()->values(),
                'welcomed_at'     => $m->welcomed_at?->toISOString(),
                'avatar_url'      => rtrim(config('services.central.url', ''), '/') . '/avatars/' . $m->central_user_id . '.jpg',
            ])->values(),
        ]);
    }

    public function heartbeat(Request $request)
    {
        $request->attributes->get('member')->update(['last_seen_at' => now()]);
        return response()->json(['ok' => true]);
    }

    public function dismissWelcome(Request $request)
    {
        $member = $request->attributes->get('member');
        if (! $member->welcomed_at) {
            $member->update(['welcomed_at' => now()]);
        }
        return response()->json(['ok' => true]);
    }

    public function updatePresence(Request $request)
    {
        $request->validate([
            'presence' => 'required|in:online,idle,dnd,offline',
        ]);

        $member = $request->attributes->get('member');
        $member->update([
            'presence'     => $request->input('presence'),
            'last_seen_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }
}
