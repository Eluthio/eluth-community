<?php

namespace App\Http\Controllers\Api;

use App\Events\ChannelLiveEnded;
use App\Events\ChannelLiveStarted;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StreamController extends Controller
{
    // POST /api/streams/{channel}/start
    public function start(Request $request, Channel $channel)
    {
        $member   = $request->attributes->get('member');
        $username = $request->attributes->get('username');

        if (! $member?->can(Permissions::STREAM)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        if ($channel->is_live) {
            return response()->json(['error' => 'already_live', 'message' => 'Someone is already streaming in this channel.'], 409);
        }

        $mimeType = $request->input('mime_type', 'video/webm;codecs=vp8,opus');

        Storage::disk('public')->makeDirectory('streams/' . $channel->id);

        $state = [
            'is_live'           => true,
            'streamer_username' => $username,
            'started_at'        => now()->toISOString(),
            'latest_seq'        => -1,
            'mime_type'         => $mimeType,
        ];
        Storage::disk('public')->put(
            'streams/' . $channel->id . '/state.json',
            json_encode($state)
        );

        $channel->update([
            'is_live'               => true,
            'live_streamer_username' => $username,
            'live_started_at'       => now(),
            'stream_seq'            => -1,
        ]);

        broadcast(new ChannelLiveStarted($channel->id, $username))->toOthers();

        return response()->json(['message' => 'Stream started.']);
    }

    // POST /api/streams/{channel}/chunk
    public function chunk(Request $request, Channel $channel)
    {
        $member   = $request->attributes->get('member');
        $username = $request->attributes->get('username');

        if (! $member?->can(Permissions::STREAM)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        if (! $channel->is_live) {
            return response()->json(['error' => 'not_live'], 409);
        }

        if ($channel->live_streamer_username !== $username) {
            return response()->json(['error' => 'not_the_streamer'], 403);
        }

        $request->validate([
            'seq'   => 'required|integer|min:0',
            'chunk' => 'required|file|max:10240', // 10 MB max per chunk
        ]);

        $seq      = (int) $request->input('seq');
        $chunkDir = 'streams/' . $channel->id;
        $chunkPath = $chunkDir . '/chunk-' . $seq . '.webm';

        Storage::disk('public')->put($chunkPath, $request->file('chunk')->get());

        // Update state.json
        $statePath = $chunkDir . '/state.json';
        if (Storage::disk('public')->exists($statePath)) {
            $state = json_decode(Storage::disk('public')->get($statePath), true);
            $state['latest_seq'] = $seq;
            Storage::disk('public')->put($statePath, json_encode($state));
        }

        // Update DB every 20 chunks to avoid hammering the database
        if ($seq % 20 === 0) {
            $channel->update(['stream_seq' => $seq]);
        }

        // Clean up old chunks: keep init (0) + last 60 chunks (~4 min at 4s/chunk)
        if ($seq > 61) {
            $old = $seq - 61;
            if ($old > 0) {
                Storage::disk('public')->delete($chunkDir . '/chunk-' . $old . '.webm');
            }
        }

        return response()->json(['seq' => $seq]);
    }

    // POST /api/streams/{channel}/stop
    public function stop(Request $request, Channel $channel)
    {
        $member   = $request->attributes->get('member');
        $username = $request->attributes->get('username');

        $canStop = $member?->isSuperAdmin()
            || $member?->can(Permissions::MANAGE_CHANNELS)
            || $channel->live_streamer_username === $username;

        if (! $canStop) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $statePath = 'streams/' . $channel->id . '/state.json';
        if (Storage::disk('public')->exists($statePath)) {
            $state = json_decode(Storage::disk('public')->get($statePath), true);
            $state['is_live'] = false;
            Storage::disk('public')->put($statePath, json_encode($state));
        }

        $channelId = $channel->id;
        $channel->update([
            'is_live'               => false,
            'live_streamer_username' => null,
            'live_started_at'       => null,
        ]);

        broadcast(new ChannelLiveEnded($channelId))->toOthers();

        return response()->json(['message' => 'Stream stopped.']);
    }

    // GET /api/streams/{channel}/state  (no auth — viewers may be unauthenticated on public servers)
    public function state(Channel $channel)
    {
        $statePath = 'streams/' . $channel->id . '/state.json';

        if (Storage::disk('public')->exists($statePath)) {
            $state = json_decode(Storage::disk('public')->get($statePath), true);
            return response()->json($state);
        }

        return response()->json([
            'is_live'           => false,
            'latest_seq'        => -1,
            'streamer_username' => null,
            'started_at'        => null,
            'mime_type'         => 'video/webm;codecs=vp8,opus',
        ]);
    }

    // GET /api/streams/{channel}/chunks/{seq}  — serve a chunk file
    public function serveChunk(Channel $channel, int $seq)
    {
        $path = 'streams/' . $channel->id . '/chunk-' . $seq . '.webm';

        if (! Storage::disk('public')->exists($path)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->file(
            Storage::disk('public')->path($path),
            ['Content-Type' => 'video/webm', 'Cache-Control' => 'no-cache']
        );
    }
}
