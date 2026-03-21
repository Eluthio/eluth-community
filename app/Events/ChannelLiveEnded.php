<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChannelLiveEnded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $channelId,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('server')];
    }

    public function broadcastAs(): string
    {
        return 'channel.live.ended';
    }

    public function broadcastWith(): array
    {
        return ['channel_id' => $this->channelId];
    }
}
