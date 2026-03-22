<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WatchPartySessionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public array $session) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('watch-party.' . $this->session['channel_id']),
        ];
    }

    public function broadcastAs(): string
    {
        return 'session.updated';
    }

    public function broadcastWith(): array
    {
        return $this->session;
    }
}
