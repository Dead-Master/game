<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Game;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class ExternalBotServiceClient
{
    public function triggerTurn(int $gameId): void
    {
        $enabled = (bool) config('services.bot_service.enabled', false);
        if (!$enabled) {
            return;
        }

        $baseUrl = (string) config('services.bot_service.base_url', '');
        $token = (string) config('services.bot_service.token', '');
        $timeoutSeconds = (int) config('services.bot_service.timeout_seconds', 8);

        if ($baseUrl === '' || $token === '') {
            Log::warning('Bot service is enabled but not fully configured.');
            return;
        }

        $game = Game::query()->find($gameId);
        $strategy = $game ? (string) ($game->bot_strategy ?? 'codex_v2') : 'codex_v2';

        $url = rtrim($baseUrl, '/') . '/api/bot/play-turn?strategy=' . urlencode($strategy);

        $response = Http::timeout($timeoutSeconds)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Bot-Service-Token' => $token,
            ])
            ->post($url, [
                'game_id' => $gameId,
                'side' => 'player_2',
            ]);

        if ($response->failed()) {
            Log::warning('Bot service call failed', [
                'game_id' => $gameId,
                'url' => $url,
                'strategy' => $strategy,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}
