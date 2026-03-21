<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('channel.' . $this->message->channel_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id'               => $this->message->id,
            'channel_id'       => $this->message->channel_id,
            'author'           => $this->message->username,
            'content'          => $this->message->content,
            'at'               => $this->message->created_at->toISOString(),
            'reply_to_id'      => $this->message->reply_to_id,
            'reply_to_author'  => $this->message->reply_to_author,
            'reply_to_preview' => $this->message->reply_to_preview,
        ];
    }
}
