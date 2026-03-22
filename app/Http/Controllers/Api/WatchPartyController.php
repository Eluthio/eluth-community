<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WatchPartyController extends Controller
{
    /**
     * GET /api/plugins/watch-party/proposals?channel_id=...
     * Returns all proposals for a channel with vote counts and whether the requester voted.
     */
    public function index(Request $request): JsonResponse
    {
        $channelId = $request->query('channel_id', '');
        if (! $channelId) {
            return response()->json(['proposals' => []]);
        }

        $member   = $request->attributes->get('member');
        $voterId  = $member?->central_user_id ?? '';

        $proposals = DB::table('watch_proposals')
            ->where('channel_id', $channelId)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($p) use ($voterId) {
                $votes = DB::table('watch_votes')
                    ->where('proposal_id', $p->id)
                    ->count();

                $voted = $voterId
                    ? DB::table('watch_votes')
                        ->where('proposal_id', $p->id)
                        ->where('voter_id', $voterId)
                        ->exists()
                    : false;

                return [
                    'id'          => $p->id,
                    'url'         => $p->url,
                    'title'       => $p->title,
                    'proposed_by' => $p->proposed_by,
                    'is_mine'     => $voterId && $p->proposed_by_id === $voterId,
                    'votes'       => $votes,
                    'voted'       => $voted,
                    'created_at'  => $p->created_at,
                ];
            })
            ->sortByDesc('votes')
            ->values();

        return response()->json(['proposals' => $proposals]);
    }

    /**
     * POST /api/plugins/watch-party/proposals
     * Body: { channel_id, url, title? }
     */
    public function propose(Request $request): JsonResponse
    {
        $member = $request->attributes->get('member');
        if (! $member) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'channel_id' => ['required', 'string'],
            'url'        => ['required', 'url', 'max:2048'],
            'title'      => ['nullable', 'string', 'max:512'],
        ]);

        // Limit per channel per user: max 3 active proposals
        $existing = DB::table('watch_proposals')
            ->where('channel_id', $validated['channel_id'])
            ->where('proposed_by_id', $member->central_user_id)
            ->count();

        if ($existing >= 3) {
            return response()->json(['message' => 'You already have 3 active proposals. Remove one first.'], 422);
        }

        $id = DB::table('watch_proposals')->insertGetId([
            'channel_id'     => $validated['channel_id'],
            'url'            => $validated['url'],
            'title'          => $validated['title'] ?? null,
            'proposed_by'    => $member->username,
            'proposed_by_id' => $member->central_user_id,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    /**
     * POST /api/plugins/watch-party/proposals/{id}/vote
     * Toggles the requester's vote on a proposal.
     */
    public function vote(Request $request, int $id): JsonResponse
    {
        $member = $request->attributes->get('member');
        if (! $member) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $proposal = DB::table('watch_proposals')->where('id', $id)->first();
        if (! $proposal) {
            return response()->json(['message' => 'Proposal not found.'], 404);
        }

        $exists = DB::table('watch_votes')
            ->where('proposal_id', $id)
            ->where('voter_id', $member->central_user_id)
            ->exists();

        if ($exists) {
            DB::table('watch_votes')
                ->where('proposal_id', $id)
                ->where('voter_id', $member->central_user_id)
                ->delete();
            $voted = false;
        } else {
            DB::table('watch_votes')->insert([
                'proposal_id' => $id,
                'voter_id'    => $member->central_user_id,
            ]);
            $voted = true;
        }

        $votes = DB::table('watch_votes')->where('proposal_id', $id)->count();

        return response()->json(['ok' => true, 'voted' => $voted, 'votes' => $votes]);
    }

    /**
     * DELETE /api/plugins/watch-party/proposals/{id}
     * Only the proposer or an admin can delete.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $member = $request->attributes->get('member');
        if (! $member) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $proposal = DB::table('watch_proposals')->where('id', $id)->first();
        if (! $proposal) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($proposal->proposed_by_id !== $member->central_user_id && ! $member->isAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        DB::table('watch_votes')->where('proposal_id', $id)->delete();
        DB::table('watch_proposals')->where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /api/plugins/watch-party/proposals?channel_id=...
     * Admin-only: clear all proposals for a channel.
     */
    public function clear(Request $request): JsonResponse
    {
        $member = $request->attributes->get('member');
        if (! $member?->isAdmin()) {
            return response()->json(['message' => 'Admin only.'], 403);
        }

        $channelId = $request->query('channel_id', '');
        if (! $channelId) {
            return response()->json(['message' => 'channel_id required.'], 422);
        }

        $ids = DB::table('watch_proposals')->where('channel_id', $channelId)->pluck('id');
        DB::table('watch_votes')->whereIn('proposal_id', $ids)->delete();
        DB::table('watch_proposals')->where('channel_id', $channelId)->delete();

        return response()->json(['ok' => true]);
    }
}
