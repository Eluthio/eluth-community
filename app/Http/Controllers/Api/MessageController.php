<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Message;
use App\Support\Permissions;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function index(Channel $channel)
    {
        $messages = $channel->messages()
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->sortBy('created_at')
            ->values()
            ->map(fn ($m) => [
                'id'               => $m->id,
                'author'           => $m->username,
                'content'          => $m->content,
                'at'               => $m->created_at->toISOString(),
                'reply_to_id'      => $m->reply_to_id,
                'reply_to_author'  => $m->reply_to_author,
                'reply_to_preview' => $m->reply_to_preview,
            ]);

        return response()->json(['messages' => $messages]);
    }

    public function store(Request $request, Channel $channel)
    {
        $request->validate([
            'content'     => 'required|string|max:4000',
            'reply_to_id' => 'nullable|uuid',
        ]);

        $member = $request->attributes->get('member');
        if (preg_match('/@(everyone|here)\b/i', $request->input('content'))) {
            if (! $member->can(Permissions::MENTION_EVERYONE)) {
                return response()->json(['error' => 'You do not have permission to use @everyone or @here.'], 403);
            }
        }

        $replyToAuthor  = null;
        $replyToPreview = null;

        if ($request->filled('reply_to_id')) {
            $parent = Message::find($request->input('reply_to_id'));
            if ($parent && $parent->channel_id === $channel->id) {
                $replyToAuthor  = $parent->username;
                $replyToPreview = mb_substr($parent->content, 0, 200);
            }
        }

        $message = Message::create([
            'channel_id'       => $channel->id,
            'central_user_id'  => $request->attributes->get('central_user_id'),
            'username'         => $request->attributes->get('username'),
            'content'          => $request->input('content'),
            'created_at'       => now(),
            'reply_to_id'      => $replyToAuthor ? $request->input('reply_to_id') : null,
            'reply_to_author'  => $replyToAuthor,
            'reply_to_preview' => $replyToPreview,
        ]);

        broadcast(new MessageSent($message))->toOthers();

        return response()->json([
            'id'               => $message->id,
            'author'           => $message->username,
            'content'          => $message->content,
            'at'               => $message->created_at->toISOString(),
            'reply_to_id'      => $message->reply_to_id,
            'reply_to_author'  => $message->reply_to_author,
            'reply_to_preview' => $message->reply_to_preview,
        ], 201);
    }
}
