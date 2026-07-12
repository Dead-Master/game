<?php

declare(strict_types=1);

namespace BotService;

use BotService\Contracts\BotStrategyInterface;

final class BotRunner
{
    public function __construct(
        private GameApiClient $api,
        private BotStrategyInterface $strategy,
    ) {
    }

    public function playTurn(int $gameId, string $side): array
    {
        return $this->strategy->playTurn($this->api, $gameId, $side);
    }
}
