<?php

declare(strict_types=1);

namespace BotService;

use BotService\Contracts\BotStrategyInterface;
use BotService\Strategies\AIAgentV3BotStrategy;
use BotService\Strategies\AIAgentV2BotStrategy;
use BotService\Strategies\AIAgentV3ReleaseBotStrategy;
use BotService\Strategies\CodexV1BotStrategy;
use BotService\Strategies\CodexV2BotStrategy;
use BotService\Strategies\CodexV3BotStrategy;
use BotService\Strategies\FocusBaseBotStrategy;
use BotService\Strategies\ScriptedBotStrategy;

final class StrategyFactory
{
    public static function make(string $strategyName): BotStrategyInterface
    {
        $normalized = strtolower(trim($strategyName));

        return match ($normalized) {
            'ai_agent_v3_release' => new AIAgentV3ReleaseBotStrategy(),
            'ai_agent_v3' => new AIAgentV3BotStrategy(),
            'ai_agent_v2' => new AIAgentV2BotStrategy(),
            'codex_v1' => new CodexV1BotStrategy(),
            'codex_v2' => new CodexV2BotStrategy(),
            'codex_v3' => new CodexV3BotStrategy(),
            'focus_base' => new FocusBaseBotStrategy(),
            'scripted', 'default', '' => new ScriptedBotStrategy(),
            default => throw new \InvalidArgumentException("Unknown bot strategy: {$strategyName}"),
        };
    }
}
