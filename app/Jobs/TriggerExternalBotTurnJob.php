<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Game;
use App\Services\ExternalBotServiceClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class TriggerExternalBotTurnJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 15;

    public function __construct(
        public int $gameId
    ) {
    }

    public function handle(ExternalBotServiceClient $botClient): void
    {
        $lockKey = 'bot-turn:' . $this->gameId;
        $lock = Cache::lock($lockKey, 20);

        if (!$lock->get()) {
            return;
        }

        try {
            $game = Game::query()->find($this->gameId);
            if (!$game) {
                return;
            }

            $currentSide = ((int) $game->current_turn % 2 === 1) ? 'player_1' : 'player_2';
            if ($game->status !== 'active' || $currentSide !== 'player_2') {
                return;
            }

            $botClient->triggerTurn($this->gameId);
        } catch (\Throwable $e) {
            Log::error('TriggerExternalBotTurnJob failed', [
                'game_id' => $this->gameId,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
