<?php

declare(strict_types=1);

namespace BotService\Strategies;

use BotService\Contracts\BotStrategyInterface;
use BotService\GameApiClient;

final class AIAgentV3BotStrategy implements BotStrategyInterface
{
    private const array CARD_COSTS = [
        'archer' => 3,
        'berserker' => 4,
        'infantry' => 2,
        'scout' => 1,
    ];

    private const int MAX_ACTIONS_PER_TURN = 24;
    private const float LOOKAHEAD_ALPHA = 0.45;
    private const float MIN_ACTION_SCORE = 1.0;

    /** @var array<string, float|int|string>|null */
    private ?array $weightsCache = null;

    public function name(): string
    {
        return 'ai_agent_v3';
    }

    /**
     * Пишет диагностическое сообщение в stderr в любом SAPI
     * (константа STDERR существует только в CLI).
     */
    private function logDiag(string $message): void
    {
        $stream = defined('STDERR') ? STDERR : fopen('php://stderr', 'wb');
        if (is_resource($stream)) {
            fwrite($stream, $message);

            if (!defined('STDERR')) {
                fclose($stream);
            }
        }
    }

    public function playTurn(GameApiClient $api, int $gameId, string $side): array
    {
        $actions = [];
        $targetSide = $side === 'player_1' ? 'player_2' : 'player_1';

        $state = $this->refreshState($api, $gameId);
        if (($state['game']['status'] ?? 'finished') !== 'active') {
            return ['status' => 'game_not_active', 'actions' => $actions, 'strategy' => $this->name()];
        }

        if (($state['current_player_side'] ?? '') !== $side) {
            return ['status' => 'not_bot_turn', 'actions' => $actions, 'strategy' => $this->name()];
        }

        /** @var array<string, true> $failedActions */
        $failedActions = [];

        for ($step = 0; $step < self::MAX_ACTIONS_PER_TURN; $step++) {
            $state = $this->refreshState($api, $gameId);

            if (($state['game']['status'] ?? 'finished') !== 'active') {
                break;
            }

            if (($state['current_player_side'] ?? '') !== $side) {
                break;
            }

            $candidates = array_values(array_filter(
                $this->buildCandidateActions($state, $side, $targetSide),
                fn (array $c): bool => !isset($failedActions[$this->actionKey($c)])
            ));

            if ($candidates === []) {
                break;
            }

            $best = $this->pickBestByLookahead($state, $side, $targetSide, $candidates);

            $minScore = $this->num('min_action_score', self::MIN_ACTION_SCORE);
            if ((float) ($best['_final_score'] ?? -INF) < $minScore) {
                break;
            }

            $result = $this->executeAction($api, $gameId, $side, $best);
            $success = ($result['success'] ?? false) === true;

            $actions[] = [
                'action' => (string) ($best['type'] ?? 'unknown'),
                'success' => $success,
                'http_code' => (int) ($result['_http_code'] ?? 0),
                'score' => (float) ($best['_final_score'] ?? 0.0),
                'immediate_score' => (float) ($best['immediate_score'] ?? 0.0),
                'meta' => $best,
            ];

            if (!$success) {
                // Не прерываем ход: запоминаем неудачное действие и пробуем другие кандидаты.
                $failedActions[$this->actionKey($best)] = true;
            }
        }

        $state = $api->getState($gameId);
        $canEndTurn = ($state['success'] ?? false) === true
            && (($state['game']['status'] ?? 'finished') === 'active')
            && (($state['current_player_side'] ?? '') === $side);

        if ($canEndTurn) {
            $end = $api->endTurn($gameId, $side);
            $actions[] = [
                'action' => 'end_turn',
                'success' => (bool) ($end['success'] ?? false),
                'http_code' => (int) ($end['_http_code'] ?? 0),
            ];
        }

        return [
            'status' => 'ok',
            'game_id' => $gameId,
            'side' => $side,
            'actions' => $actions,
            'strategy' => $this->name(),
        ];
    }

    private function actionKey(array $action): string
    {
        return implode('|', [
            (string) ($action['type'] ?? ''),
            (string) ($action['attacker_id'] ?? ''),
            (string) ($action['target_id'] ?? ''),
            (string) ($action['target_side'] ?? ''),
            (string) ($action['unit_id'] ?? ''),
            (string) ($action['card_type'] ?? ''),
            (string) ($action['x'] ?? ''),
            (string) ($action['y'] ?? ''),
        ]);
    }

    private function pickBestByLookahead(array $state, string $side, string $targetSide, array $candidates): array
    {
        $alpha = $this->num('lookahead_alpha', self::LOOKAHEAD_ALPHA);
        $futureScale = $this->num('future_eval_scale', 1.0);

        // Baseline позволяет оценивать именно ИЗМЕНЕНИЕ позиции, а не её абсолютное значение.
        $baseline = $this->evaluateState($state, $side);

        $best = $candidates[0];
        $bestScore = -INF;

        foreach ($candidates as $candidate) {
            $immediate = (float) ($candidate['immediate_score'] ?? 0.0);
            $nextState = $this->applyVirtualAction($state, $side, $targetSide, $candidate);
            $delta = $this->evaluateState($nextState, $side) - $baseline;

            $final = $immediate + ($alpha * $delta * $futureScale);

            if ($final > $bestScore) {
                $bestScore = $final;
                $candidate['_final_score'] = $final;
                $best = $candidate;
            }
        }

        return $best;
    }

    private function buildCandidateActions(array $state, string $side, string $targetSide): array
    {
        $candidates = [];
        $myUnits = $this->getOwnUnits($state, $side);
        $enemyUnits = $this->getEnemyUnits($state, $side);

        foreach ($myUnits as $u) {
            if ((bool) ($u['has_attacked_this_turn'] ?? false)) {
                continue;
            }

            foreach ($enemyUnits as $e) {
                if (!$this->canAttackUnitByType($u, $e)) {
                    continue;
                }

                $attackerPower = $this->unitAttackPower($u);
                $enemyHp = (int) ($e['hp'] ?? 999);
                $attackerHp = (int) ($u['hp'] ?? 999);
                $kill = $attackerPower >= $enemyHp;
                $overflow = max(0, $attackerPower - $enemyHp);

                $counterDamage = $this->expectedCounterDamage($u, $e, $kill);
                $diesFromCounter = $counterDamage >= $attackerHp;

                $chipFinishBonus = 0;
                if (
                    (($u['type'] ?? '') === 'archer')
                    && !$kill
                    && $this->canAnyMeleeFinishAfterChip($myUnits, $u, $e, $attackerPower)
                ) {
                    $chipFinishBonus = 90;
                }

                $score = 140
                    + min($attackerPower, $enemyHp) * 18
                    + ($kill ? 170 : 0)
                    - ($overflow * 8)
                    - ($counterDamage * 26)
                    - ($diesFromCounter ? 220 : 0)
                    + $chipFinishBonus;

                if ((($u['type'] ?? '') !== 'archer') && !$kill && $counterDamage > 0) {
                    $score -= 70;
                }

                if ((($u['type'] ?? '') !== 'archer') && $kill && $counterDamage === 0) {
                    $score += 35;
                }

                $score *= $this->num('scale_attack_unit', 1.0);

                $candidates[] = [
                    'type' => 'attack_unit',
                    'attacker_id' => (int) $u['id'],
                    'target_id' => (int) $e['id'],
                    'immediate_score' => $score,
                ];
            }

            if ($this->canAttackBase($u, $targetSide)) {
                $power = $this->unitAttackPower($u);
                $enemyBaseHp = (int) ($this->getBaseHpBySide($state, $targetSide) ?? 99);
                $lethal = $power >= $enemyBaseHp;

                $d = $this->distanceToEnemyBase($u, $targetSide);
                $score = 180
                    + ($power * 20)
                    + max(0, 8 - $d) * 5
                    + ($lethal ? 10000 : 0);
                $score *= $this->num('scale_attack_base_with_unit', 1.0);

                $candidates[] = [
                    'type' => 'attack_base_with_unit',
                    'attacker_id' => (int) $u['id'],
                    'target_side' => $targetSide,
                    'immediate_score' => $score,
                ];
            }
        }

        if ($this->canBaseAttack($state, $side)) {
            $basePower = $this->getBaseAttackPowerBySide($state, $side);

            foreach ($enemyUnits as $e) {
                $hp = (int) ($e['hp'] ?? 999);
                $kill = $basePower >= $hp;

                $score = 125 + (24 - min(24, $hp)) + ($kill ? 80 : 0);
                $score *= $this->num('scale_attack_with_base', 1.0);

                $candidates[] = [
                    'type' => 'attack_with_base',
                    'target_id' => (int) $e['id'],
                    'immediate_score' => $score,
                ];
            }

            $enemyBaseHp = (int) ($this->getBaseHpBySide($state, $targetSide) ?? 99);
            $lethal = $basePower >= $enemyBaseHp;

            $score = (105 + ($lethal ? 10000 : 0)) * $this->num('scale_attack_base_with_base', 1.0);
            $candidates[] = [
                'type' => 'attack_base_with_base',
                'target_side' => $targetSide,
                'immediate_score' => $score,
            ];
        }

        $player = $this->getPlayerBySide($state, $side);
        if ($player !== null) {
            $hand = is_array($player['hand'] ?? null) ? $player['hand'] : [];
            $supplies = (int) ($player['supplies_current'] ?? 0);
            $seenCardTypes = [];

            foreach ($hand as $card) {
                $cardType = (string) ($card['type'] ?? '');
                if (isset($seenCardTypes[$cardType])) {
                    continue; // одинаковые карты дают одинаковые кандидаты
                }

                $seenCardTypes[$cardType] = true;

                $cost = self::CARD_COSTS[$cardType] ?? 999;
                if ($cost > $supplies) {
                    continue;
                }

                foreach ($this->deployCandidateCells($side) as $cell) {
                    $x = (int) $cell['x'];
                    $y = (int) $cell['y'];

                    if (!$this->cellEmpty($state, $x, $y)) {
                        continue;
                    }

                    $score = $this->scoreDeploy($cardType, $x, $y, $side) * $this->num('scale_deploy', 1.0);

                    $candidates[] = [
                        'type' => 'deploy_card',
                        'card_type' => $cardType,
                        'x' => $x,
                        'y' => $y,
                        'immediate_score' => $score,
                    ];
                }
            }
        }

        foreach ($myUnits as $u) {
            foreach ($this->possibleMovesForUnit($state, $u) as $move) {
                $score = $this->scoreMove($u, (int) $move['x'], (int) $move['y'], $targetSide) * $this->num('scale_move', 1.0);

                $candidates[] = [
                    'type' => 'move_unit',
                    'unit_id' => (int) $u['id'],
                    'x' => (int) $move['x'],
                    'y' => (int) $move['y'],
                    'immediate_score' => $score,
                ];
            }
        }

        return $candidates;
    }

    private function preferredDeployCells(string $side): array
    {
        if ($side === 'player_1') {
            return [
                ['x' => 1, 'y' => 1],
                ['x' => 1, 'y' => 0],
                ['x' => 0, 'y' => 1],
            ];
        }

        return [
            ['x' => 3, 'y' => 1],
            ['x' => 3, 'y' => 2],
            ['x' => 4, 'y' => 1],
        ];
    }

    /**
     * @return array<int, array{x:int,y:int}>
     */
    private function adjacentCells(int $x, int $y): array
    {
        $result = [];
        $deltas = [
            [0, -1], [0, 1], [-1, 0], [1, 0],
            [-1, -1], [-1, 1], [1, -1], [1, 1],
        ];

        foreach ($deltas as [$dx, $dy]) {
            $nx = $x + $dx;
            $ny = $y + $dy;

            if ($nx >= 0 && $nx <= 4 && $ny >= 0 && $ny <= 2) {
                $result[] = ['x' => $nx, 'y' => $ny];
            }
        }

        return $result;
    }

    /**
     * Сервер разрешает деплой только на клетки, смежные с базой.
     *
     * @return array<int, array{x:int,y:int}>
     */
    private function deployCandidateCells(string $side): array
    {
        $base = $side === 'player_1' ? ['x' => 0, 'y' => 0] : ['x' => 4, 'y' => 2];

        $result = [];
        $seen = [];

        foreach ($this->preferredDeployCells($side) as $cell) {
            $key = ((int) $cell['x']) . ':' . ((int) $cell['y']);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = ['x' => (int) $cell['x'], 'y' => (int) $cell['y']];
        }

        foreach ($this->adjacentCells((int) $base['x'], (int) $base['y']) as $cell) {
            $key = ((int) $cell['x']) . ':' . ((int) $cell['y']);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = ['x' => (int) $cell['x'], 'y' => (int) $cell['y']];
        }

        return $result;
    }

    private function executeAction(GameApiClient $api, int $gameId, string $side, array $action): array
    {
        return match ($action['type'] ?? '') {
            'attack_unit' => $api->attackUnit($gameId, $side, (int) $action['attacker_id'], (int) $action['target_id']),
            'attack_base_with_unit' => $api->attackBaseWithUnit($gameId, $side, (int) $action['attacker_id'], (string) $action['target_side']),
            'attack_with_base' => $api->attackUnitWithBase($gameId, $side, (int) $action['target_id']),
            'attack_base_with_base' => $api->attackBaseWithBase($gameId, $side, (string) $action['target_side']),
            'move_unit' => $api->moveUnit($gameId, $side, (int) $action['unit_id'], (int) $action['x'], (int) $action['y']),
            'deploy_card' => $api->deployCard($gameId, $side, (string) $action['card_type'], (int) $action['x'], (int) $action['y']),
            default => ['success' => false, 'error' => 'unknown_action'],
        };
    }

    private function applyVirtualAction(array $state, string $side, string $targetSide, array $action): array
    {
        $next = $state;

        $type = (string) ($action['type'] ?? '');
        if ($type === 'attack_unit') {
            $attackerId = (int) ($action['attacker_id'] ?? 0);
            $targetId = (int) ($action['target_id'] ?? 0);

            $attackerIdx = $this->findUnitIndexById($next, $attackerId);
            $targetIdx = $this->findUnitIndexById($next, $targetId);

            if ($attackerIdx === null || $targetIdx === null) {
                return $next;
            }

            $attacker = $next['units'][$attackerIdx];
            $defender = $next['units'][$targetIdx];

            $power = $this->unitAttackPower($attacker);
            $targetHp = (int) ($defender['hp'] ?? 0) - $power;

            $next['units'][$targetIdx]['hp'] = $targetHp;
            $next['units'][$attackerIdx]['has_attacked_this_turn'] = true;

            if ($targetHp <= 0) {
                $next['units'][$targetIdx]['state'] = 'graveyard';

                return $next;
            }

            // Сервер наносит контратаку, если защитник выжил, а атакующий — не лучник.
            if ((($attacker['type'] ?? '') !== 'archer') && $this->canUnitCounterAttack($defender)) {
                $attackerHp = (int) ($attacker['hp'] ?? 0) - $this->unitAttackPower($defender);
                $next['units'][$attackerIdx]['hp'] = $attackerHp;
                $next['units'][$targetIdx]['has_counter_attacked_this_turn'] = true;

                if ($attackerHp <= 0) {
                    $next['units'][$attackerIdx]['state'] = 'graveyard';
                }
            }

            return $next;
        }

        if ($type === 'attack_base_with_unit') {
            $attackerId = (int) ($action['attacker_id'] ?? 0);
            $attackerIdx = $this->findUnitIndexById($next, $attackerId);
            if ($attackerIdx !== null) {
                $power = $this->unitAttackPower($next['units'][$attackerIdx]);
                $this->applyBaseDamage($next, $targetSide, $power);
                $next['units'][$attackerIdx]['has_attacked_this_turn'] = true;
            }

            return $next;
        }

        if ($type === 'attack_with_base') {
            $targetId = (int) ($action['target_id'] ?? 0);
            $targetIdx = $this->findUnitIndexById($next, $targetId);
            $basePower = $this->getBaseAttackPowerBySide($next, $side);

            if ($targetIdx !== null && $basePower > 0) {
                $targetHp = (int) ($next['units'][$targetIdx]['hp'] ?? 0);
                $next['units'][$targetIdx]['hp'] = $targetHp - $basePower;
                if ((int) $next['units'][$targetIdx]['hp'] <= 0) {
                    $next['units'][$targetIdx]['state'] = 'graveyard';
                }
            }

            $this->markBaseAttacked($next, $side);
            return $next;
        }

        if ($type === 'attack_base_with_base') {
            $basePower = $this->getBaseAttackPowerBySide($next, $side);
            $this->applyBaseDamage($next, $targetSide, $basePower);
            $this->markBaseAttacked($next, $side);
            return $next;
        }

        if ($type === 'move_unit') {
            $unitId = (int) ($action['unit_id'] ?? 0);
            $idx = $this->findUnitIndexById($next, $unitId);
            if ($idx !== null) {
                $toX = (int) ($action['x'] ?? 0);
                $toY = (int) ($action['y'] ?? 0);

                $dx = abs((int) ($next['units'][$idx]['position_x'] ?? 0) - $toX);
                $dy = abs((int) ($next['units'][$idx]['position_y'] ?? 0) - $toY);
                $cost = max($dx, $dy);

                $next['units'][$idx]['position_x'] = $toX;
                $next['units'][$idx]['position_y'] = $toY;
                $next['units'][$idx]['movement_points'] = max(
                    0,
                    (int) ($next['units'][$idx]['movement_points'] ?? 0) - $cost
                );
            }

            return $next;
        }

        if ($type === 'deploy_card') {
            $unitType = (string) ($action['card_type'] ?? 'infantry');
            $cost = self::CARD_COSTS[$unitType] ?? 0;
            $stats = $this->virtualUnitStats($unitType);

            $next['units'][] = [
                'id' => $this->nextVirtualUnitId($next),
                'owner_side' => $side,
                'type' => $unitType,
                'state' => 'board',
                'position_x' => (int) ($action['x'] ?? 0),
                'position_y' => (int) ($action['y'] ?? 0),
                'hp' => $stats['hp'],
                'attack_power' => $stats['attack_power'],
                // Сервер уменьшает очки движения на 1 при деплое.
                'movement_points' => max(0, $stats['movement_points'] - 1),
                'has_attacked_this_turn' => false,
                'has_counter_attacked_this_turn' => false,
            ];

            foreach (($next['players'] ?? []) as $idx => $player) {
                if (($player['side'] ?? '') === $side) {
                    $supplies = (int) ($player['supplies_current'] ?? 0);
                    $next['players'][$idx]['supplies_current'] = max(0, $supplies - $cost);
                    break;
                }
            }

            return $next;
        }

        return $next;
    }

    private function evaluateState(array $state, string $side): float
    {
        $enemySide = $side === 'player_1' ? 'player_2' : 'player_1';

        $myBase = (float) ($this->getBaseHpBySide($state, $side) ?? 0);
        $enemyBase = (float) ($this->getBaseHpBySide($state, $enemySide) ?? 0);

        $myUnits = $this->getOwnUnits($state, $side);
        $enemyUnits = $this->getOwnUnits($state, $enemySide);

        $myHp = 0.0;
        $enemyHp = 0.0;
        $myAtk = 0.0;
        $enemyAtk = 0.0;
        $myTempo = 0.0;
        $enemyTempo = 0.0;

        foreach ($myUnits as $u) {
            $myHp += (float) ((int) ($u['hp'] ?? 0));
            $myAtk += (float) $this->unitAttackPower($u);
            $myTempo += (float) (10 - min(10, $this->distanceToEnemyBase($u, $enemySide)));
        }

        foreach ($enemyUnits as $u) {
            $enemyHp += (float) ((int) ($u['hp'] ?? 0));
            $enemyAtk += (float) $this->unitAttackPower($u);
            $enemyTempo += (float) (10 - min(10, $this->distanceToEnemyBase($u, $side)));
        }

        return
            ($myBase - $enemyBase) * $this->num('eval_base_hp_weight', 5.0)
            + ($myHp - $enemyHp) * $this->num('eval_unit_hp_weight', 1.2)
            + ($myAtk - $enemyAtk) * $this->num('eval_unit_attack_weight', 1.7)
            + ($myTempo - $enemyTempo) * $this->num('eval_tempo_weight', 0.8);
    }

    private function refreshState(GameApiClient $api, int $gameId): array
    {
        $state = $api->getState($gameId);

        if (($state['success'] ?? false) !== true) {
            throw new \RuntimeException('Unable to fetch valid game state.');
        }

        return $state;
    }

    private function num(string $key, float $default): float
    {
        $weights = $this->weights();
        $value = $weights[$key] ?? $default;

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @return array<string, float|int|string>
     */
    /**
     * @return array<string, float|int|string>
     */

    private function weights(): array
    {
        if ($this->weightsCache !== null) {
            return $this->weightsCache;
        }

        $weights = [];
        $source = 'defaults (no env weights found)';

        // 1) Inline JSON из env (сначала V3, затем V2 для совместимости с тюнером).
        foreach (['AI_AGENT_V3_WEIGHTS_JSON', 'AI_AGENT_V2_WEIGHTS_JSON'] as $envKey) {
            $inline = getenv($envKey);
            if (is_string($inline) && trim($inline) !== '') {
                $decoded = json_decode($inline, true);
                if (is_array($decoded)) {
                    $weights = $decoded;
                    $source = 'env ' . $envKey;
                    break;
                }

                $this->logDiag("[{$this->name()}] WARNING: {$envKey} is set but is not valid JSON, ignoring.\n");
            }
        }

        // 2) Путь к JSON-файлу из env.
        if ($weights === []) {
            foreach (['AI_AGENT_V3_WEIGHTS_FILE', 'AI_AGENT_V2_WEIGHTS_FILE'] as $envKey) {
                $file = getenv($envKey);
                if (!is_string($file) || trim($file) === '') {
                    continue;
                }

                if (!is_file($file)) {
                    $this->logDiag("[{$this->name()}] WARNING: {$envKey} points to missing file: {$file}\n");
                    continue;
                }

                $raw = file_get_contents($file);
                $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

                if (is_array($decoded)) {
                    $weights = $decoded;
                    $source = 'file ' . $file;
                    break;
                }

                $this->logDiag("[{$this->name()}] WARNING: {$envKey} file contains invalid JSON: {$file}\n");
            }
        }

        // 3) Fallback: дефолтный конфиг стратегии (важно для веб-режима без env).
        if ($weights === []) {
            $defaultFile = __DIR__ . '/../config/ai-agent-v3.default-weights.json';
            if (is_file($defaultFile)) {
                $raw = file_get_contents($defaultFile);
                $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

                if (is_array($decoded)) {
                    $weights = $decoded;
                    $source = 'default config file ' . $defaultFile;
                }
            }
        }

        // Поддержка формата best-файла тюнера: {fitness, metrics, weights: {...}}.
        if (isset($weights['weights']) && is_array($weights['weights'])) {
            $weights = $weights['weights'];
            $source .= ' (unwrapped "weights" key)';
        }

        if (getenv('AI_AGENT_DEBUG_WEIGHTS') === '1') {
            $this->logDiag(sprintf(
                "[%s] weights source: %s; keys: %s\n",
                $this->name(),
                $source,
                $weights === [] ? '(none)' : implode(', ', array_keys($weights))
            ));
        }

        $this->weightsCache = $weights;

        return $this->weightsCache;
    }


    private function getPlayerBySide(array $state, string $side): ?array
    {
        foreach (($state['players'] ?? []) as $p) {
            if (($p['side'] ?? '') === $side) {
                return $p;
            }
        }

        return null;
    }

    private function getOwnUnits(array $state, string $side): array
    {
        return array_values(array_filter(
            $state['units'] ?? [],
            fn (array $u): bool => ($u['state'] ?? '') === 'board' && ($u['owner_side'] ?? '') === $side
        ));
    }

    private function getEnemyUnits(array $state, string $side): array
    {
        return array_values(array_filter(
            $state['units'] ?? [],
            fn (array $u): bool => ($u['state'] ?? '') === 'board' && ($u['owner_side'] ?? '') !== $side
        ));
    }

    private function findUnitIndexById(array $state, int $unitId): ?int
    {
        foreach (($state['units'] ?? []) as $idx => $u) {
            if ((int) ($u['id'] ?? 0) === $unitId && ($u['state'] ?? '') === 'board') {
                return $idx;
            }
        }

        return null;
    }

    private function unitAt(array $state, int $x, int $y): ?array
    {
        foreach (($state['units'] ?? []) as $u) {
            if (($u['state'] ?? '') !== 'board') {
                continue;
            }

            if ((int) ($u['position_x'] ?? -1) === $x && (int) ($u['position_y'] ?? -1) === $y) {
                return $u;
            }
        }

        return null;
    }

    private function isBaseCell(int $x, int $y): bool
    {
        return ($x === 0 && $y === 0) || ($x === 4 && $y === 2);
    }

    private function cellEmpty(array $state, int $x, int $y): bool
    {
        if ($x < 0 || $x > 4 || $y < 0 || $y > 2) {
            return false;
        }

        if ($this->isBaseCell($x, $y)) {
            return false;
        }

        return $this->unitAt($state, $x, $y) === null;
    }

    private function canAttackUnitByType(array $attacker, array $defender): bool
    {
        if ((bool) ($attacker['has_attacked_this_turn'] ?? false)) {
            return false;
        }

        if (($attacker['owner_side'] ?? '') === ($defender['owner_side'] ?? '')) {
            return false;
        }

        if (($attacker['type'] ?? '') === 'archer') {
            return true;
        }

        $dx = abs((int) $attacker['position_x'] - (int) $defender['position_x']);
        $dy = abs((int) $attacker['position_y'] - (int) $defender['position_y']);

        return max($dx, $dy) === 1;
    }

    private function expectedCounterDamage(array $attacker, array $defender, bool $kill): int
    {
        if (($attacker['type'] ?? '') === 'archer') {
            return 0;
        }

        if ($kill) {
            return 0;
        }

        if (!$this->canUnitCounterAttack($defender)) {
            return 0;
        }

        return $this->unitAttackPower($defender);
    }

    private function canUnitCounterAttack(array $unit): bool
    {
        if ((bool) ($unit['has_counter_attacked_this_turn'] ?? false)) {
            return false;
        }

        $type = (string) ($unit['type'] ?? '');

        if ($type === 'berserker') {
            return !((bool) ($unit['has_attacked_this_turn'] ?? false));
        }

        return $type === 'infantry' || $type === 'scout';
    }

    private function canAnyMeleeFinishAfterChip(array $ownUnits, array $rangedAttacker, array $defender, int $chipDamage): bool
    {
        $hpAfterChip = (int) ($defender['hp'] ?? 0) - max(0, $chipDamage);
        if ($hpAfterChip <= 0) {
            return false;
        }

        foreach ($ownUnits as $unit) {
            if ((int) ($unit['id'] ?? 0) === (int) ($rangedAttacker['id'] ?? 0)) {
                continue;
            }

            if ((bool) ($unit['has_attacked_this_turn'] ?? false)) {
                continue;
            }

            if (($unit['type'] ?? '') === 'archer') {
                continue;
            }

            if (!$this->canAttackUnitByType($unit, $defender)) {
                continue;
            }

            if ($this->unitAttackPower($unit) >= $hpAfterChip) {
                return true;
            }
        }

        return false;
    }

    private function canAttackBase(array $attacker, string $targetSide): bool
    {
        if ((bool) ($attacker['has_attacked_this_turn'] ?? false)) {
            return false;
        }

        if (($attacker['type'] ?? '') === 'archer') {
            return true;
        }

        $basePos = $targetSide === 'player_1' ? ['x' => 0, 'y' => 0] : ['x' => 4, 'y' => 2];
        $dx = abs((int) $attacker['position_x'] - $basePos['x']);
        $dy = abs((int) $attacker['position_y'] - $basePos['y']);

        return max($dx, $dy) === 1;
    }

    private function canBaseAttack(array $state, string $side): bool
    {
        foreach (($state['players'] ?? []) as $player) {
            if (($player['side'] ?? '') === $side) {
                return !((bool) ($player['base_has_attacked_this_turn'] ?? false));
            }
        }

        return false;
    }

    private function possibleMovesForUnit(array $state, array $unit): array
    {
        $moves = [];

        $fromX = (int) ($unit['position_x'] ?? -1);
        $fromY = (int) ($unit['position_y'] ?? -1);

        for ($x = 0; $x <= 4; $x++) {
            for ($y = 0; $y <= 2; $y++) {
                if ($x === $fromX && $y === $fromY) {
                    continue;
                }

                if (!$this->cellEmpty($state, $x, $y)) {
                    continue;
                }

                if ($this->canMoveByType($unit, $x, $y)) {
                    $moves[] = ['x' => $x, 'y' => $y];
                }
            }
        }

        return $moves;
    }

    private function canMoveByType(array $unit, int $targetX, int $targetY): bool
    {
        if ($this->isBaseCell($targetX, $targetY)) {
            return false;
        }

        $movement = (int) ($unit['movement_points'] ?? 0);
        if ($movement <= 0) {
            return false;
        }

        $x = (int) ($unit['position_x'] ?? -1);
        $y = (int) ($unit['position_y'] ?? -1);

        if ($x === $targetX && $y === $targetY) {
            return false;
        }

        if ($targetX < 0 || $targetX > 4 || $targetY < 0 || $targetY > 2) {
            return false;
        }

        $dx = abs($x - $targetX);
        $dy = abs($y - $targetY);

        // Сервер списывает max(dx, dy) очков движения — их должно хватать.
        if (max($dx, $dy) > $movement) {
            return false;
        }

        $type = (string) ($unit['type'] ?? '');

        return match ($type) {
            'infantry' => ($dx + $dy === 1) || ($dx === 1 && $dy === 1),
            'archer' => true,
            'berserker' => ($dx + $dy) === 1,
            'scout' => ($dx === 0 || $dy === 0),
            default => false,
        };
    }

    private function scoreDeploy(string $cardType, int $x, int $y, string $side): float
    {
        $base = match ($cardType) {
            'archer' => 62.0,
            'infantry' => 56.0,
            'scout' => 43.0,
            'berserker' => 40.0,
            default => 30.0,
        };

        $enemyBase = $side === 'player_1' ? ['x' => 4, 'y' => 2] : ['x' => 0, 'y' => 0];
        $dist = abs($x - $enemyBase['x']) + abs($y - $enemyBase['y']);

        return $base + (12 - min(12, $dist)) * 2.0;
    }

    private function scoreMove(array $unit, int $toX, int $toY, string $targetSide): float
    {
        $currentDist = $this->distanceToEnemyBase($unit, $targetSide);
        $futureDist = abs($toX - ($targetSide === 'player_1' ? 0 : 4))
            + abs($toY - ($targetSide === 'player_1' ? 0 : 2));

        $progress = $currentDist - $futureDist;

        // Ходы без продвижения к вражеской базе не нужны — они сжигают лимит действий.
        if ($progress <= 0) {
            return -100.0;
        }

        $typeBonus = match ((string) ($unit['type'] ?? '')) {
            'infantry' => 10.0,
            'scout' => 12.0,
            'berserker' => 6.0,
            // Лучник бьёт с любой клетки — двигать его почти бессмысленно.
            'archer' => -8.0,
            default => 0.0,
        };

        return ($progress * 13.0) + $typeBonus;
    }

    private function distanceToEnemyBase(array $unit, string $targetSide): int
    {
        $baseX = $targetSide === 'player_1' ? 0 : 4;
        $baseY = $targetSide === 'player_1' ? 0 : 2;

        return abs((int) ($unit['position_x'] ?? 0) - $baseX)
            + abs((int) ($unit['position_y'] ?? 0) - $baseY);
    }

    private function getBaseHpBySide(array $state, string $side): ?int
    {
        foreach (($state['players'] ?? []) as $player) {
            if (($player['side'] ?? '') === $side) {
                return (int) ($player['base_hp'] ?? 0);
            }
        }

        return null;
    }

    private function getBaseAttackPowerBySide(array $state, string $side): int
    {
        foreach (($state['players'] ?? []) as $player) {
            if (($player['side'] ?? '') === $side) {
                // API отдаёт поле base_attack; base_attack_power оставлен как fallback.
                $power = $player['base_attack'] ?? $player['base_attack_power'] ?? 0;

                return (int) $power;
            }
        }

        return 0;
    }

    private function applyBaseDamage(array &$state, string $targetSide, int $damage): void
    {
        foreach (($state['players'] ?? []) as $idx => $player) {
            if (($player['side'] ?? '') !== $targetSide) {
                continue;
            }

            $hp = (int) ($player['base_hp'] ?? 0);
            $state['players'][$idx]['base_hp'] = max(0, $hp - max(0, $damage));
            return;
        }
    }

    private function markBaseAttacked(array &$state, string $side): void
    {
        foreach (($state['players'] ?? []) as $idx => $player) {
            if (($player['side'] ?? '') !== $side) {
                continue;
            }

            $state['players'][$idx]['base_has_attacked_this_turn'] = true;
            return;
        }
    }

    private function unitAttackPower(array $unit): int
    {
        $power = (int) ($unit['attack_power'] ?? 0);
        if ($power > 0) {
            return $power;
        }

        return (int) ($unit['attack_damage'] ?? 0);
    }

    private function nextVirtualUnitId(array $state): int
    {
        $maxId = 100000;
        foreach (($state['units'] ?? []) as $u) {
            $id = (int) ($u['id'] ?? 0);
            if ($id > $maxId) {
                $maxId = $id;
            }
        }

        return $maxId + 1;
    }

    /**
     * Значения совпадают с Unit::fromCardType() на сервере.
     *
     * @return array{hp:int,attack_power:int,movement_points:int}
     */
    private function virtualUnitStats(string $type): array
    {
        return match ($type) {
            'archer' => ['hp' => 2, 'attack_power' => 1, 'movement_points' => 1],
            'berserker' => ['hp' => 9, 'attack_power' => 4, 'movement_points' => 1],
            'scout' => ['hp' => 3, 'attack_power' => 1, 'movement_points' => 2],
            'infantry' => ['hp' => 5, 'attack_power' => 2, 'movement_points' => 1],
            default => ['hp' => 3, 'attack_power' => 1, 'movement_points' => 1],
        };
    }
}
