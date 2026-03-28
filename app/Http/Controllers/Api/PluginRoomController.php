<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PluginRoom;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PluginRoomController extends Controller
{
    // How long (minutes) before an active-but-silent room is considered abandoned
    private const ABANDON_MINUTES = 5;

    // GET /api/plugin-rooms/{slug}/channels/{channelId}
    public function activeForChannel(Request $request, string $slug, string $channelId)
    {
        $room = $this->findActive($slug, $channelId);
        return response()->json(['room' => $room]);
    }

    // POST /api/plugin-rooms/{slug}
    public function create(Request $request, string $slug)
    {
        $data = $request->validate([
            'channel_id'  => ['required', 'string', 'max:255'],
            'max_players' => ['integer', 'min:1', 'max:8'],
            'data'        => ['nullable', 'array'],
        ]);

        // Enforce one active room per plugin+channel
        if ($this->findActive($slug, $data['channel_id'])) {
            return response()->json(['message' => 'A game is already in progress in this channel.'], 409);
        }

        $seats = $data['max_players'] ?? 2;

        $room = PluginRoom::create([
            'plugin_slug'  => $slug,
            'channel_id'   => $data['channel_id'],
            'max_players'  => $seats,
            'player_ids'   => array_fill(0, $seats, null),
            'player_names' => array_fill(0, $seats, null),
            'status'       => 'waiting',
            'data'         => $data['data'] ?? [],
        ]);

        return response()->json(['room' => $room], 201);
    }

    // GET /api/plugin-rooms/{slug}/{id}
    public function get(Request $request, string $slug, string $id)
    {
        $room = PluginRoom::where('plugin_slug', $slug)->findOrFail($id);
        return response()->json(['room' => $room]);
    }

    // POST /api/plugin-rooms/{slug}/{id}/seat
    public function claimSeat(Request $request, string $slug, string $id)
    {
        return DB::transaction(function () use ($request, $slug, $id) {
            $room = PluginRoom::where('plugin_slug', $slug)
                ->whereIn('status', ['waiting', 'active'])
                ->lockForUpdate()
                ->findOrFail($id);

            $member      = $request->attributes->get('member');
            $playerIds   = $room->player_ids   ?? [];
            $playerNames = $room->player_names ?? [];

            // Already seated?
            $existingSeat = array_search($member->central_user_id, $playerIds);
            if ($existingSeat !== false) {
                return response()->json(['seat' => $existingSeat + 1, 'room' => $room]);
            }

            // Find first empty slot
            $slot = array_search(null, $playerIds);
            if ($slot === false) {
                return response()->json(['message' => 'All seats are taken.'], 409);
            }

            $playerIds[$slot]   = $member->central_user_id;
            $playerNames[$slot] = $member->username ?? ('Player ' . ($slot + 1));

            $allFilled = !in_array(null, $playerIds);

            $room->update([
                'player_ids'   => $playerIds,
                'player_names' => $playerNames,
                'status'       => $allFilled ? 'active' : 'waiting',
            ]);

            return response()->json(['seat' => $slot + 1, 'room' => $room->fresh()]);
        });
    }

    // PUT /api/plugin-rooms/{slug}/{id}/data
    public function updateData(Request $request, string $slug, string $id)
    {
        $room = PluginRoom::where('plugin_slug', $slug)
            ->whereIn('status', ['waiting', 'active'])
            ->findOrFail($id);

        $incoming = $request->validate(['data' => ['required', 'array']]);

        $room->update([
            'data'            => array_merge($room->data ?? [], $incoming['data']),
            'data_updated_at' => now(),
        ]);

        return response()->json(['room' => $room->fresh()]);
    }

    // POST /api/plugin-rooms/{slug}/{id}/close
    public function close(Request $request, string $slug, string $id)
    {
        $room = PluginRoom::where('plugin_slug', $slug)->findOrFail($id);
        $room->update(['status' => 'finished']);
        return response()->json(['room' => $room->fresh()]);
    }

    private function findActive(string $slug, string $channelId): ?PluginRoom
    {
        return PluginRoom::where('plugin_slug', $slug)
            ->where('channel_id', $channelId)
            ->whereIn('status', ['waiting', 'active'])
            ->where(function ($q) {
                // Treat as abandoned if active but no state update for ABANDON_MINUTES
                $q->where('status', 'waiting')
                  ->orWhere(function ($q2) {
                      $q2->where('status', 'active')
                         ->where(function ($q3) {
                             $q3->whereNull('data_updated_at')
                                ->orWhere('data_updated_at', '>=', Carbon::now()->subMinutes(self::ABANDON_MINUTES));
                         });
                  });
            })
            ->orderByDesc('created_at')
            ->first();
    }
}
