<?php

namespace App\Http\Controllers\Api;

use App\Events\PollUpdated;
use App\Http\Controllers\Controller;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PollController extends Controller
{
    /**
     * GET /api/plugins/polls/active?channel_id=...
     * Returns the current active poll for a channel, with live vote counts.
     */
    public function active(Request $request): JsonResponse
    {
        $channelId = $request->query('channel_id', '');
        if (! $channelId) {
            return response()->json(['poll' => null]);
        }

        $member  = $request->attributes->get('member');
        $voterId = $member?->central_user_id ?? '';

        $poll = DB::table('polls')
            ->where('channel_id', $channelId)
            ->whereNull('closed_at')
            ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', now()))
            ->orderByDesc('created_at')
            ->first();

        if (! $poll) {
            // Also return the most recently closed poll so the UI can show final results briefly
            $closed = DB::table('polls')
                ->where('channel_id', $channelId)
                ->where(fn ($q) => $q->whereNotNull('closed_at')->orWhere('closes_at', '<=', now()))
                ->orderByDesc('created_at')
                ->first();

            if (! $closed) {
                return response()->json(['poll' => null]);
            }
            return response()->json(['poll' => $this->formatPoll($closed, $voterId, false)]);
        }

        return response()->json(['poll' => $this->formatPoll($poll, $voterId, true)]);
    }

    /**
     * POST /api/plugins/polls
     * Body: { channel_id, question, options: string[], duration_minutes: int|null }
     */
    public function create(Request $request): JsonResponse
    {
        $member = $request->attributes->get('member');
        if (! $member) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        if (! ($member->isAdmin() || $member->can(Permissions::POLL_CREATE))) {
            return response()->json(['message' => 'You do not have permission to create polls.'], 403);
        }

        $validated = $request->validate([
            'channel_id'       => ['required', 'string'],
            'question'         => ['required', 'string', 'max:300'],
            'options'          => ['required', 'array', 'min:2', 'max:10'],
            'options.*'        => ['required', 'string', 'max:100'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        // Only one active poll per channel at a time
        $existing = DB::table('polls')
            ->where('channel_id', $validated['channel_id'])
            ->whereNull('closed_at')
            ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', now()))
            ->exists();

        if ($existing) {
            return response()->json(['message' => 'A poll is already active in this channel. Close it first.'], 422);
        }

        $closesAt = isset($validated['duration_minutes'])
            ? now()->addMinutes($validated['duration_minutes'])
            : null;

        $pollId = DB::table('polls')->insertGetId([
            'channel_id'    => $validated['channel_id'],
            'question'      => $validated['question'],
            'created_by'    => $member->username,
            'created_by_id' => $member->central_user_id,
            'closes_at'     => $closesAt,
            'created_at'    => now(),
        ]);

        foreach (array_values($validated['options']) as $i => $label) {
            DB::table('poll_options')->insert([
                'poll_id'  => $pollId,
                'label'    => $label,
                'position' => $i,
            ]);
        }

        $poll    = DB::table('polls')->where('id', $pollId)->first();
        $payload = $this->formatPoll($poll, $member->central_user_id, true);
        broadcast(new PollUpdated($payload));

        return response()->json(['ok' => true, 'poll' => $payload], 201);
    }

    /**
     * POST /api/plugins/polls/{id}/vote
     * Body: { option_id }  — can be changed while poll is open.
     */
    public function vote(Request $request, int $id): JsonResponse
    {
        $member = $request->attributes->get('member');
        if (! $member) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $poll = DB::table('polls')->where('id', $id)->first();
        if (! $poll) {
            return response()->json(['message' => 'Poll not found.'], 404);
        }

        // Check poll is still active
        $isActive = is_null($poll->closed_at)
            && (is_null($poll->closes_at) || $poll->closes_at > now()->toDateTimeString());

        if (! $isActive) {
            return response()->json(['message' => 'This poll is no longer accepting votes.'], 422);
        }

        $validated = $request->validate([
            'option_id' => ['required', 'integer'],
        ]);

        // Verify option belongs to this poll
        $option = DB::table('poll_options')
            ->where('id', $validated['option_id'])
            ->where('poll_id', $id)
            ->first();

        if (! $option) {
            return response()->json(['message' => 'Invalid option.'], 422);
        }

        // Upsert vote (allow changing vote)
        DB::table('poll_votes')->updateOrInsert(
            ['poll_id' => $id, 'voter_id' => $member->central_user_id],
            ['option_id' => $validated['option_id'], 'voted_at' => now()]
        );

        $payload = $this->formatPoll($poll, $member->central_user_id, true);
        broadcast(new PollUpdated($payload));

        return response()->json(['ok' => true, 'poll' => $payload]);
    }

    /**
     * POST /api/plugins/polls/{id}/close
     * Creator or admin can close the poll early.
     */
    public function close(Request $request, int $id): JsonResponse
    {
        $member = $request->attributes->get('member');
        if (! $member) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $poll = DB::table('polls')->where('id', $id)->first();
        if (! $poll) {
            return response()->json(['message' => 'Poll not found.'], 404);
        }

        $canClose = $member->isAdmin()
            || $poll->created_by_id === $member->central_user_id
            || $member->can(Permissions::POLL_MODERATE);

        if (! $canClose) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        DB::table('polls')->where('id', $id)->update(['closed_at' => now()]);

        $poll    = DB::table('polls')->where('id', $id)->first();
        $payload = $this->formatPoll($poll, $member->central_user_id, false);
        broadcast(new PollUpdated($payload));

        return response()->json(['ok' => true, 'poll' => $payload]);
    }

    private function formatPoll(object $poll, string $voterId, bool $isActive): array
    {
        $options = DB::table('poll_options')
            ->where('poll_id', $poll->id)
            ->orderBy('position')
            ->get();

        $totalVotes = DB::table('poll_votes')->where('poll_id', $poll->id)->count();

        $myVote = $voterId
            ? DB::table('poll_votes')
                ->where('poll_id', $poll->id)
                ->where('voter_id', $voterId)
                ->value('option_id')
            : null;

        $formattedOptions = $options->map(function ($opt) use ($poll, $totalVotes) {
            $votes   = DB::table('poll_votes')
                ->where('poll_id', $poll->id)
                ->where('option_id', $opt->id)
                ->count();
            $percent = $totalVotes > 0 ? round(($votes / $totalVotes) * 100) : 0;

            return [
                'id'      => $opt->id,
                'label'   => $opt->label,
                'votes'   => $votes,
                'percent' => $percent,
            ];
        });

        return [
            'id'          => $poll->id,
            'channel_id'  => $poll->channel_id,
            'question'    => $poll->question,
            'created_by'  => $poll->created_by,
            'created_by_id' => $poll->created_by_id,
            'closes_at'   => $poll->closes_at,
            'closed_at'   => $poll->closed_at,
            'is_active'   => $isActive,
            'my_vote'     => $myVote,
            'options'     => $formattedOptions,
            'total_votes' => $totalVotes,
        ];
    }
}
