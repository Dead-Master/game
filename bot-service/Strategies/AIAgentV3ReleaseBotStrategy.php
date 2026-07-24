<?php

declare(strict_types=1);

namespace BotService\Strategies;

/**
 * Релизная версия ai_agent_v3 с жёстко вшитыми весами.
 *
 * Игнорирует env-переменные и файлы конфигурации — поведение
 * полностью детерминировано кодом. Источник значений:
 * storage/app/ai-tuning/ai-agent-v3-best-20260712-164223.json
 */
final class AIAgentV3ReleaseBotStrategy extends AIAgentV3BotStrategy
{
    /**
     * @var array<string, float>
     */
    private const array WEIGHTS = [
        "lookahead_alpha"=> 0.7670755824255547,
        "future_eval_scale"=> 0.8328830879290685,
        "scale_attack_unit"=> 1.4,
        "scale_attack_base_with_unit"=> 0.9481788411399561,
        "scale_attack_with_base"=> 1.2062810829693342,
        "scale_attack_base_with_base"=> 1.2412868166892188,
        "scale_deploy"=> 0.667987215661351,
        "scale_move"=> 0.7024484280190576,
        "eval_base_hp_weight"=> 4.258983200980335,
        "eval_unit_hp_weight"=> 2.6061556581705045,
        "eval_unit_attack_weight"=> 2.8951737365968166,
        "eval_tempo_weight"=> 0.7199651061057121,
        "min_action_score"=> 19.00868002392205
    ];
    public function name(): string
    {
        return 'ai_agent_v3_release';
    }

    protected function weights(): array
    {
        return self::WEIGHTS;
    }
}
