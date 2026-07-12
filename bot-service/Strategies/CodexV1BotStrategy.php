<?php

declare(strict_types=1);

namespace BotService\Strategies;

use BotService\Contracts\BotStrategyInterface;
use BotService\GameApiClient;

final class CodexV1BotStrategy implements BotStrategyInterface
{
    private const array CARD_COSTS = [
        'archer' => 3,
        'berserker' => 4,
        'infantry' => 2,
        'scout' => 1,
    ];

    public function name(): string
    {
        return 'codex_v1';
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

        if (!in_array($side, ['player_1', 'player_2'], true)) {
            return ['status' => 'invalid_side', 'actions' => $actions, 'strategy' => $this->name()];
        }

        for ($step = 0; $step < 14; $step++) {
            $state = $this->refreshState($api, $gameId);

            if (($state['game']['status'] ?? 'finished') !== 'active') {
                break;
            }

            if (($state['current_player_side'] ?? '') !== $side) {
                break;
            }

            if ($this->tryAttackBaseLethalWithUnit($api, $gameId, $state, $side, $targetSide, $actions)) {
                continue;
            }

            if ($this->tryAttackBaseLethalWithBase($api, $gameId, $state, $side, $targetSide, $actions)) {
                continue;
            }

            if ($this->tryBestUnitAttack($api, $gameId, $state, $side, $actions)) {
                continue;
            }

            if ($this->tryBestMoveTowardEnemyBase($api, $gameId, $state, $side, $actions)) {
                continue;
            }

            if ($this->tryBestDeploy($api, $gameId, $state, $side, $actions)) {
                continue;
            }

            if ($this->tryBaseAttackUnit($api, $gameId, $state, $side, $actions)) {
                continue;
            }

            if ($this->tryAttackBaseAnyUnit($api, $gameId, $state, $side, $targetSide, $actions)) {
                continue;
            }

            break;
        }

        $end = $api->endTurn($gameId, $side);
        $actions[] = $this->action('end_turn', $end);

        return [
            'status' => 'ok',
            'game_id' => $gameId,
            'side' => $side,
            'actions' => $actions,
            'strategy' => $this->name(),
        ];
    }

    private function tryAttackBaseLethalWithUnit(
        GameApiClient $api,
        int $gameId,
        array $state,
        string $side,
        string $targetSide,
        array &$actions
    ): bool {
        $baseHp = $this->getBaseHpBySide($state, $targetSide);
        if ($baseHp === null) {
            return false;
        }

        $candidates = array_values(array_filter(
            $this->getOwnUnits($state, $side),
            fn (array $u): bool =>
                !((bool) ($u['has_attacked_this_turn'] ?? false))
                && $this->canAttackBase($u, $targetSide)
                && (int) ($u['attack_power'] ?? 0) >= $baseHp
        ));

        if ($candidates === []) {
            return false;
        }

        usort($candidates, fn (array $a, array $b): int => ((int) ($b['attack_power'] ?? 0)) <=> ((int) ($a['attack_power'] ?? 0)));

        $attacker = $candidates[0];
        $res = $api->attackBaseWithUnit($gameId, $side, (int) $attacker['id'], $targetSide);
        $actions[] = $this->action('attack_base_with_unit', $res, [
            'attacker_unit_id' => (int) $attacker['id'],
            'target_side' => $targetSide,
        ]);

        return (bool) ($res['success'] ?? false);
    }

    private function tryAttackBaseLethalWithBase(
        GameApiClient $api,
        int $gameId,
        array $state,
        string $side,
        string $targetSide,
        array &$actions
    ): bool {
        if (!$this->canBaseAttack($state, $side)) {
            return false;
        }

        $baseHp = $this->getBaseHpBySide($state, $targetSide);
        $myBaseAttack = $this->getBaseAttackBySide($state, $side);

        if ($baseHp === null || $myBaseAttack === null || $myBaseAttack < $baseHp) {
            return false;
        }

        $res = $api->attackBaseWithBase($gameId, $side, $targetSide);
        $actions[] = $this->action('attack_base_with_base', $res, ['target_side' => $targetSide]);

        return (bool) ($res['success'] ?? false);
    }

    private function tryBestUnitAttack(GameApiClient $api, int $gameId, array $state, string $side, array &$actions): bool
    {
        $best = null;
        $bestScore = -999999;

        foreach ($this->getOwnUnits($state, $side) as $attacker) {
            if ((bool) ($attacker['has_attacked_this_turn'] ?? false)) {
                continue;
            }

            foreach ($this->getEnemyUnits($state, $side) as $defender) {
                if (!$this->canAttackUnitByType($attacker, $defender)) {
                    continue;
                }

                $score = $this->scoreAttack($attacker, $defender);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = ['attacker' => $attacker, 'defender' => $defender];
                }
            }
        }

        if ($best === null) {
            return false;
        }

        $res = $api->attackUnit(
            $gameId,
            $side,
            (int) $best['attacker']['id'],
            (int) $best['defender']['id']
        );

        $actions[] = $this->action('attack_unit', $res, [
            'attacker_unit_id' => (int) $best['attacker']['id'],
            'target_unit_id' => (int) $best['defender']['id'],
        ]);

        return (bool) ($res['success'] ?? false);
    }

    private function scoreAttack(array $attacker, array $defender): int
    {
        $attackerAtk = (int) ($attacker['attack_power'] ?? 0);
        $defenderHp = (int) ($defender['hp'] ?? 999);
        $defenderAtk = (int) ($defender['attack_power'] ?? 0);
        $attackerHp = (int) ($attacker['hp'] ?? 999);

        $kill = $attackerAtk >= $defenderHp;
        $isMelee = ($attacker['type'] ?? '') !== 'archer';
        $canCounter = $isMelee && $this->canUnitCounterAttack($defender);

        $score = 0;
        $score += min($attackerAtk, $defenderHp) * 12;
        $score += $kill ? 120 : 0;
        $score += (int) ($defenderAtk * 3);

        if ($canCounter) {
            $score -= min($defenderAtk, $attackerHp) * 8;
            if ($defenderAtk >= $attackerHp) {
                $score -= 90;
            }
        }

        return $score;
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

    private function tryBestMoveTowardEnemyBase(GameApiClient $api, int $gameId, array $state, string $side, array &$actions): bool
    {
        $enemyBase = $side === 'player_1' ? ['x' => 4, 'y' => 2] : ['x' => 0, 'y' => 0];
        $occupied = $this->occupiedMap($state);

        $best = null;
        $bestScore = -999999;

        foreach ($this->getOwnUnits($state, $side) as $u) {
            $movePoints = (int) ($u['movement_points'] ?? 0);
            if ($movePoints <= 0) {
                continue;
            }

            $fromX = (int) ($u['position_x'] ?? -1);
            $fromY = (int) ($u['position_y'] ?? -1);
            $beforeDist = abs($fromX - $enemyBase['x']) + abs($fromY - $enemyBase['y']);

            for ($x = 0; $x <= 4; $x++) {
                for ($y = 0; $y <= 2; $y++) {
                    $key = $x . ':' . $y;
                    if (isset($occupied[$key])) {
                        continue;
                    }

                    if (!$this->canMoveByType($u, $x, $y)) {
                        continue;
                    }

                    $afterDist = abs($x - $enemyBase['x']) + abs($y - $enemyBase['y']);
                    $improve = $beforeDist - $afterDist;
                    if ($improve <= 0) {
                        continue;
                    }

                    $score = $improve * 20;
                    $score += (($u['type'] ?? '') === 'archer') ? 5 : 0;
                    $score += (($u['type'] ?? '') === 'berserker') ? 4 : 0;

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = ['unit' => $u, 'to' => ['x' => $x, 'y' => $y]];
                    }
                }
            }
        }

        if ($best === null) {
            return false;
        }

        $res = $api->moveUnit(
            $gameId,
            $side,
            (int) $best['unit']['id'],
            (int) $best['to']['x'],
            (int) $best['to']['y']
        );

        $actions[] = $this->action('move_unit', $res, [
            'unit_id' => (int) $best['unit']['id'],
            'to' => $best['to'],
        ]);

        return (bool) ($res['success'] ?? false);
    }

    private function tryBestDeploy(GameApiClient $api, int $gameId, array $state, string $side, array &$actions): bool
    {
        $player = $this->getPlayerBySide($state, $side);
        if ($player === null) {
            return false;
        }

        $supplies = (int) ($player['supplies_current'] ?? 0);
        $hand = is_array($player['hand'] ?? null) ? $player['hand'] : [];
        if ($supplies <= 0 || $hand === []) {
            return false;
        }

        $ownBase = $side === 'player_1' ? ['x' => 0, 'y' => 0] : ['x' => 4, 'y' => 2];
        $enemyBase = $side === 'player_1' ? ['x' => 4, 'y' => 2] : ['x' => 0, 'y' => 0];

        $cells = $this->adjacentCells($ownBase['x'], $ownBase['y']);
        $occupied = $this->occupiedMap($state);

        $best = null;
        $bestScore = -999999;

        foreach ($cells as $cell) {
            $key = $cell['x'] . ':' . $cell['y'];
            if (isset($occupied[$key])) {
                continue;
            }

            foreach ($hand as $card) {
                $type = (string) ($card['type'] ?? '');
                $cost = self::CARD_COSTS[$type] ?? 999;
                if ($supplies < $cost) {
                    continue;
                }

                $dist = abs($cell['x'] - $enemyBase['x']) + abs($cell['y'] - $enemyBase['y']);
                $score = 0;
                $score -= $dist * 6;
                $score += match ($type) {
                    'berserker' => 24,
                    'archer' => 18,
                    'infantry' => 16,
                    'scout' => 12,
                    default => 0,
                };
                $score -= $cost;

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = ['type' => $type, 'x' => $cell['x'], 'y' => $cell['y']];
                }
            }
        }

        if ($best === null) {
            return false;
        }

        $res = $api->deployCard($gameId, $side, $best['type'], $best['x'], $best['y']);
        $actions[] = $this->action('deploy_card', $res, [
            'type' => $best['type'],
            'to' => ['x' => $best['x'], 'y' => $best['y']],
        ]);

        return (bool) ($res['success'] ?? false);
    }

    private function tryBaseAttackUnit(GameApiClient $api, int $gameId, array $state, string $side, array &$actions): bool
    {
        if (!$this->canBaseAttack($state, $side)) {
            return false;
        }

        $enemies = $this->getEnemyUnits($state, $side);
        if ($enemies === []) {
            return false;
        }

        usort($enemies, function (array $a, array $b): int {
            $hpCmp = ((int) ($a['hp'] ?? 999)) <=> ((int) ($b['hp'] ?? 999));
            if ($hpCmp !== 0) {
                return $hpCmp;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        $target = $enemies[0];
        $res = $api->attackUnitWithBase($gameId, $side, (int) $target['id']);
        $actions[] = $this->action('attack_with_base', $res, [
            'target_unit_id' => (int) $target['id'],
        ]);

        return (bool) ($res['success'] ?? false);
    }

    private function tryAttackBaseAnyUnit(
        GameApiClient $api,
        int $gameId,
        array $state,
        string $side,
        string $targetSide,
        array &$actions
    ): bool {
        $candidates = array_values(array_filter(
            $this->getOwnUnits($state, $side),
            fn (array $u): bool =>
                !((bool) ($u['has_attacked_this_turn'] ?? false))
                && $this->canAttackBase($u, $targetSide)
        ));

        if ($candidates === []) {
            return false;
        }

        usort($candidates, fn (array $a, array $b): int => ((int) ($b['attack_power'] ?? 0)) <=> ((int) ($a['attack_power'] ?? 0)));

        $attacker = $candidates[0];
        $res = $api->attackBaseWithUnit($gameId, $side, (int) $attacker['id'], $targetSide);
        $actions[] = $this->action('attack_base_with_unit', $res, [
            'attacker_unit_id' => (int) $attacker['id'],
            'target_side' => $targetSide,
        ]);

        return (bool) ($res['success'] ?? false);
    }

    private function refreshState(GameApiClient $api, int $gameId): array
    {
        $state = $api->getState($gameId);

        if (($state['success'] ?? false) !== true || !is_array($state)) {
            throw new \RuntimeException('Unable to fetch valid game state.');
        }

        return $state;
    }

    private function action(string $name, array $res, array $extra = []): array
    {
        return array_merge([
            'action' => $name,
            'success' => (bool) ($res['success'] ?? false),
            'http_code' => (int) ($res['_http_code'] ?? 0),
        ], $extra);
    }

    private function getPlayerBySide(array $state, string $side): ?array
    {
        foreach (($state['players'] ?? []) as $player) {
            if (($player['side'] ?? '') === $side) {
                return $player;
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

    private function canMoveByType(array $unit, int $targetX, int $targetY): bool
    {
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

        $type = (string) ($unit['type'] ?? '');

        return match ($type) {
            'infantry' => ($dx + $dy === 1) || ($dx === 1 && $dy === 1),
            'archer' => max($dx, $dy) <= $movement,
            'berserker' => ($dx + $dy) === 1,
            'scout' => ($dx === 0 || $dy === 0) && (($dx + $dy) <= $movement),
            default => false,
        };
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

        $dx = abs((int) ($attacker['position_x'] ?? 0) - (int) ($defender['position_x'] ?? 0));
        $dy = abs((int) ($attacker['position_y'] ?? 0) - (int) ($defender['position_y'] ?? 0));

        return max($dx, $dy) === 1;
    }

    private function canAttackBase(array $attacker, string $targetSide): bool
    {
        if ((bool) ($attacker['has_attacked_this_turn'] ?? false)) {
            return false;
        }

        if (($attacker['type'] ?? '') === 'archer') {
            return true;
        }

        $base = $targetSide === 'player_1' ? ['x' => 0, 'y' => 0] : ['x' => 4, 'y' => 2];

        $dx = abs((int) ($attacker['position_x'] ?? 0) - $base['x']);
        $dy = abs((int) ($attacker['position_y'] ?? 0) - $base['y']);

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

    private function getBaseHpBySide(array $state, string $side): ?int
    {
        foreach (($state['players'] ?? []) as $player) {
            if (($player['side'] ?? '') === $side) {
                return (int) ($player['base_hp'] ?? 0);
            }
        }

        return null;
    }

    private function getBaseAttackBySide(array $state, string $side): ?int
    {
        foreach (($state['players'] ?? []) as $player) {
            if (($player['side'] ?? '') === $side) {
                return (int) ($player['base_attack'] ?? 0);
            }
        }

        return null;
    }

    /**
     * @return array<string, bool>
     */
    private function occupiedMap(array $state): array
    {
        $map = [
            '0:0' => true,
            '4:2' => true,
        ];

        foreach (($state['units'] ?? []) as $u) {
            if (($u['state'] ?? '') !== 'board') {
                continue;
            }

            $x = (int) ($u['position_x'] ?? -1);
            $y = (int) ($u['position_y'] ?? -1);
            if ($x < 0 || $x > 4 || $y < 0 || $y > 2) {
                continue;
            }

            $map[$x . ':' . $y] = true;
        }

        return $map;
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
}
