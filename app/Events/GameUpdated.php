<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param array<string, mixed>|null $payload
     */
    public function __construct(
        public int $gameId,
        public int $sequence,
        public string $eventType,
        public string $actorSide,
        public ?array $payload = null,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('game.' . $this->gameId);
    }

    public function broadcastAs(): string
    {
        return 'game.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'game_id' => $this->gameId,
            'sequence' => $this->sequence,
            'event_type' => $this->eventType,
            'actor_side' => $this->actorSide,
            'payload' => $this->payload ?? [],
        ];
    }
}
