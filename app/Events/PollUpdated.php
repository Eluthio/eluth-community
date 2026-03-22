<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PollUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public array $poll) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('polls.' . $this->poll['channel_id']),
        ];
    }

    public function broadcastAs(): string
    {
        return 'poll.updated';
    }

    public function broadcastWith(): array
    {
        return ['poll' => $this->poll];
    }
}
