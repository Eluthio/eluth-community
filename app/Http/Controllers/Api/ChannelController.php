<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Http\Request;

class ChannelController extends Controller
{
    public function index()
    {
        $channels = Channel::orderBy('position')->get();

        $sections = $channels
            ->groupBy('section')
            ->map(fn ($group, $label) => [
                'id'       => $label,
                'label'    => $label,
                'channels' => $group->map(fn ($ch) => [
                    'id'         => $ch->id,
                    'name'       => $ch->name,
                    'type'       => $ch->type,
                    'topic'      => $ch->topic,
                    'is_private' => $ch->is_private,
                ])->values(),
            ])
            ->values();

        return response()->json(['sections' => $sections]);
    }

    // ── Admin: Sections ────────────────────────────────────────────────────

    public function createSection(Request $request)
    {
        $this->requirePermission($request, Permissions::MANAGE_CHANNELS);

        $validated = $request->validate(['name' => 'required|string|max:64']);
        $name = trim($validated['name']);

        return response()->json(['section' => ['id' => $name, 'label' => $name, 'channels' => []]], 201);
    }

    public function deleteSection(Request $request, string $section)
    {
        $this->requirePermission($request, Permissions::MANAGE_CHANNELS);

        Channel::where('section', $section)->delete();

        return response()->json(['message' => 'Section deleted.']);
    }

    // ── Admin: Channels ────────────────────────────────────────────────────

    public function createChannel(Request $request)
    {
        $this->requirePermission($request, Permissions::MANAGE_CHANNELS);

        $validated = $request->validate([
            'section_id' => 'required|string|max:64',
            'name'       => 'required|string|max:100',
            'type'       => 'required|in:text,announcement,voice,video',
        ]);

        $maxPos = Channel::where('section', $validated['section_id'])->max('position') ?? 0;

        $channel = Channel::create([
            'name'     => $validated['name'],
            'type'     => $validated['type'],
            'section'  => $validated['section_id'],
            'position' => $maxPos + 10,
        ]);

        return response()->json(['channel' => [
            'id'         => $channel->id,
            'name'       => $channel->name,
            'type'       => $channel->type,
            'topic'      => $channel->topic,
            'is_private' => $channel->is_private,
        ]], 201);
    }

    public function updateChannel(Request $request, Channel $channel)
    {
        $this->requirePermission($request, Permissions::MANAGE_CHANNELS);

        $validated = $request->validate([
            'name'       => 'required|string|max:100',
            'type'       => 'required|in:text,announcement,voice,video',
            'topic'      => 'nullable|string|max:500',
            'is_private' => 'boolean',
        ]);

        $channel->update($validated);

        return response()->json(['channel' => [
            'id'         => $channel->id,
            'name'       => $channel->name,
            'type'       => $channel->type,
            'topic'      => $channel->topic,
            'is_private' => $channel->is_private,
        ]]);
    }

    public function deleteChannel(Request $request, Channel $channel)
    {
        $this->requirePermission($request, Permissions::MANAGE_CHANNELS);

        $channel->messages()->delete();
        $channel->delete();

        return response()->json(['message' => 'Channel deleted.']);
    }

    // ── Admin: Channel permissions ─────────────────────────────────────────

    public function getPermissions(Request $request, Channel $channel)
    {
        $this->requirePermission($request, Permissions::MANAGE_CHANNELS);

        $roles = Role::orderByDesc('position')->get(['id', 'name', 'color', 'is_system']);

        $overwrites = \DB::table('channel_permission_overwrites')
            ->where('channel_id', $channel->id)
            ->get()
            ->keyBy('role_id');

        $result = $roles->map(fn ($role) => [
            'role_id'    => $role->id,
            'role_name'  => $role->name,
            'role_color' => $role->color,
            'is_system'  => $role->is_system,
            'can_view'   => $overwrites->has($role->id) ? (bool) $overwrites[$role->id]->can_view : true,
            'can_send'   => $overwrites->has($role->id) ? (bool) $overwrites[$role->id]->can_send : true,
            'overridden' => $overwrites->has($role->id),
        ]);

        return response()->json([
            'channel'    => ['id' => $channel->id, 'name' => $channel->name, 'is_private' => $channel->is_private],
            'permissions' => $result,
        ]);
    }

    public function updatePermissions(Request $request, Channel $channel)
    {
        $this->requirePermission($request, Permissions::MANAGE_CHANNELS);

        $validated = $request->validate([
            'overwrites'            => 'present|array',
            'overwrites.*.role_id'  => 'required|uuid|exists:roles,id',
            'overwrites.*.can_view' => 'required|boolean',
            'overwrites.*.can_send' => 'required|boolean',
        ]);

        // Replace all overwrites for this channel
        \DB::table('channel_permission_overwrites')->where('channel_id', $channel->id)->delete();

        foreach ($validated['overwrites'] as $overwrite) {
            // Only store if it differs from the default (both true)
            if (! $overwrite['can_view'] || ! $overwrite['can_send']) {
                \DB::table('channel_permission_overwrites')->insert([
                    'channel_id' => $channel->id,
                    'role_id'    => $overwrite['role_id'],
                    'can_view'   => $overwrite['can_view'],
                    'can_send'   => $overwrite['can_send'],
                ]);
            }
        }

        return response()->json(['message' => 'Permissions saved.']);
    }

    private function requirePermission(Request $request, string $permission): void
    {
        $member = $request->attributes->get('member');
        if (! $member?->can($permission)) {
            abort(response()->json(['error' => 'forbidden'], 403));
        }
    }
}
