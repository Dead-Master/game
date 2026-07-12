<?php

declare(strict_types=1);

namespace App\Models;

use App\Events\GameUpdated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class GameEvent extends Model
{
    public const string TYPE_DEPLOY_CARD = 'deploy_card';
    public const string TYPE_MOVE_UNIT = 'move_unit';
    public const string TYPE_ATTACK_UNIT = 'attack_unit';
    public const string TYPE_ATTACK_BASE = 'attack_base';
    public const string TYPE_ATTACK_WITH_BASE = 'attack_with_base';
    public const string TYPE_END_TURN = 'end_turn';
    public const string TYPE_GAME_FINISHED = 'game_finished';

    public $timestamps = false;

    protected $fillable = [
        'game_id',
        'sequence',
        'turn_number',
        'round_number',
        'actor_side',
        'event_type',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'turn_number' => 'integer',
        'round_number' => 'integer',
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    public static function record(Game $game, string $actorSide, string $eventType, ?array $payload = null): self
    {
        return DB::transaction(function () use ($game, $actorSide, $eventType, $payload): self {
            $lastSequence = (int) self::query()
                ->where('game_id', $game->id)
                ->max('sequence');

            $event = self::query()->create([
                'game_id' => $game->id,
                'sequence' => $lastSequence + 1,
                'turn_number' => (int) $game->current_turn,
                'round_number' => (int) $game->round_number,
                'actor_side' => $actorSide,
                'event_type' => $eventType,
                'payload' => $payload,
            ]);

            DB::afterCommit(function () use ($event): void {
                event(new GameUpdated(
                    gameId: (int) $event->game_id,
                    sequence: (int) $event->sequence,
                    eventType: (string) $event->event_type,
                    actorSide: (string) $event->actor_side,
                    payload: is_array($event->payload) ? $event->payload : [],
                ));
            });

            return $event;
        });
    }
}
