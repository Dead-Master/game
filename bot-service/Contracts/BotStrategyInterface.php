<?php

declare(strict_types=1);

namespace BotService\Contracts;

use BotService\GameApiClient;

interface BotStrategyInterface
{
    /**
     * @return array<string, mixed>
     */
    public function playTurn(GameApiClient $api, int $gameId, string $side): array;

    public function name(): string;
}
