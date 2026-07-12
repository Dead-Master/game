<?php

// возьми бота `codex_v2`, переделай стратегию на v3
//так что бы он аккуратнее вёл атаки, и получал меньше урона при контратаках
//например делал расчёт что если он не может сразу добавить врага
//то проверял - а нельзя ли сначала нанести ему дальний урон (лучником или штабом)
//чтобы снизить ХР до уровня когда можно добить противника не получив урона
//при этом стараться лишний уров не наносить, а наносить ровно столько чтобы убить

declare(strict_types=1);

namespace BotService\Strategies;

use BotService\Contracts\BotStrategyInterface;
use BotService\GameApiClient;

final class CodexV3BotStrategy implements BotStrategyInterface
{
    private const array CARD_COSTS = [
        'archer' => 3,
        'berserker' => 4,
        'infantry' => 2,
        'scout' => 1,
    ];

    public function name(): string
    {
        return 'codex_v3';
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

            if ($this->tryBestUnitAttackV3($api, $gameId, $state, $side, $actions)) {
                continue;
            }

            if ($this->tryBaseAttackUnitPrecision($api, $gameId, $state, $side, $actions)) {
                continue;
            }

            if ($this->tryBestDeployConservative($api, $gameId, $state, $side, $actions)) {
                continue;
            }

            if ($this->tryBestMoveConservative($api, $gameId, $state, $side, $actions)) {
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

        usort($candidates, function (array $a, array $b) use ($baseHp): int {
            $overflowA = max(0, (int) ($a['attack_power'] ?? 0) - $baseHp);
            $overflowB = max(0, (int) ($b['attack_power'] ?? 0) - $baseHp);
            if ($overflowA !== $overflowB) {
                return $overflowA <=> $overflowB;
            }

            return ((int) ($a['attack_power'] ?? 0)) <=> ((int) ($b['attack_power'] ?? 0));
        });

        $attacker = $candidates[0];
        $res = $api->attackBaseWithUnit($gameId, $side, (int) $attacker['id'], $targetSide);
        $actions[] = $this->action('attack_base_with_unit', $res, [
            'attacker_unit_id' => (int) $attacker['id'],
            'target_side' => $targetSide,
        ]);

        return (bool) ($res['success'] ?? false);
    }

    private function tryBestUnitAttackV3(GameApiClient $api, int $gameId, array $state, string $side, array &$actions): bool
    {
        $best = null;
        $bestScore = -999999;

        $ownUnits = $this->getOwnUnits($state, $side);
        $enemyUnits = $this->getEnemyUnits($state, $side);

        foreach ($ownUnits as $attacker) {
            if ((bool) ($attacker['has_attacked_this_turn'] ?? false)) {
                continue;
            }

            foreach ($enemyUnits as $defender) {
                if (!$this->canAttackUnitByType($attacker, $defender)) {
                    continue;
                }

                $score = $this->scoreAttackV3($attacker, $defender, $ownUnits);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = ['attacker' => $attacker, 'defender' => $defender];
                }
            }
        }

        if ($best === null || $bestScore < 25) {
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

    private function scoreAttackV3(array $attacker, array $defender, array $ownUnits): int
    {
        $attackerAtk = (int) ($attacker['attack_power'] ?? 0);
        $attackerHp = (int) ($attacker['hp'] ?? 999);
        $defenderHp = (int) ($defender['hp'] ?? 999);
        $defenderAtk = (int) ($defender['attack_power'] ?? 0);

        if ($attackerAtk <= 0 || $defenderHp <= 0) {
            return -999999;
        }

        $dealt = min($attackerAtk, $defenderHp);
        $kill = $attackerAtk >= $defenderHp;
        $overflow = max(0, $attackerAtk - $defenderHp);

        $counterDamage = $this->expectedCounterDamage($attacker, $defender, $kill);

        $score = 0;
        $score += $dealt * 24;
        $score += $kill ? 260 : 0;
        $score -= $overflow * 14;
        $score -= $counterDamage * 55;

        if ($counterDamage > 0 && $counterDamage >= $attackerHp) {
            $score -= 700;
        }

        if (($attacker['type'] ?? '') === 'archer') {
            $score += 40;
            if (!$kill && $this->canAnyMeleeFinishAfterChip($ownUnits, $attacker, $defender, $attackerAtk)) {
                $score += 120;
            }
        }

        if (($attacker['type'] ?? '') !== 'archer' && !$kill && $counterDamage > 0) {
            $score -= 180;
        }

        if (($attacker['type'] ?? '') !== 'archer' && $kill && $counterDamage === 0) {
            $score += 80;
        }

        $score += (int) ($defenderAtk * 2);

        return $score;
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

        return (int) ($defender['attack_power'] ?? 0);
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

            if ((int) ($unit['attack_power'] ?? 0) >= $hpAfterChip) {
                return true;
            }
        }

        return false;
    }

    private function tryBaseAttackUnitPrecision(GameApiClient $api, int $gameId, array $state, string $side, array &$actions): bool
    {
        if (!$this->canBaseAttack($state, $side)) {
            return false;
        }

        $enemies = $this->getEnemyUnits($state, $side);
        if ($enemies === []) {
            return false;
        }

        $baseAttackPower = $this->getBaseAttackPowerBySide($state, $side);
        if ($baseAttackPower <= 0) {
            return false;
        }

        $best = null;
        $bestScore = -999999;

        foreach ($enemies as $enemy) {
            $hp = (int) ($enemy['hp'] ?? 999);
            $overflow = max(0, $baseAttackPower - $hp);
            $kill = $baseAttackPower >= $hp;

            $score = 0;
            $score += min($baseAttackPower, $hp) * 22;
            $score += $kill ? 230 : 0;
            $score -= $overflow * 18;
            $score += (int) (($enemy['attack_power'] ?? 0) * 3);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $enemy;
            }
        }

        if ($best === null || $bestScore < 40) {
            return false;
        }

        $res = $api->attackUnitWithBase($gameId, $side, (int) $best['id']);
        $actions[] = $this->action('attack_with_base', $res, [
            'target_unit_id' => (int) $best['id'],
        ]);

        return (bool) ($res['success'] ?? false);
    }

    private function tryBestMoveConservative(GameApiClient $api, int $gameId, array $state, string $side, array &$actions): bool
    {
        $enemyBase = $side === 'player_1' ? ['x' => 4, 'y' => 2] : ['x' => 0, 'y' => 0];
        $ownBase = $side === 'player_1' ? ['x' => 0, 'y' => 0] : ['x' => 4, 'y' => 2];
        $round = (int) ($state['game']['round_number'] ?? 1);

        $occupied = $this->occupiedMap($state);
        $enemies = $this->getEnemyUnits($state, $side);

        $best = null;
        $bestScore = -999999;

        foreach ($this->getOwnUnits($state, $side) as $u) {
            $movePoints = (int) ($u['movement_points'] ?? 0);
            if ($movePoints <= 0) {
                continue;
            }

            $fromX = (int) ($u['position_x'] ?? -1);
            $fromY = (int) ($u['position_y'] ?? -1);
            $beforeDistEnemyBase = abs($fromX - $enemyBase['x']) + abs($fromY - $enemyBase['y']);

            for ($x = 0; $x <= 4; $x++) {
                for ($y = 0; $y <= 2; $y++) {
                    $key = $x . ':' . $y;
                    if (isset($occupied[$key])) {
                        continue;
                    }

                    if (!$this->canMoveByType($u, $x, $y)) {
                        continue;
                    }

                    $afterDistEnemyBase = abs($x - $enemyBase['x']) + abs($y - $enemyBase['y']);
                    $improve = $beforeDistEnemyBase - $afterDistEnemyBase;
                    if ($improve <= 0) {
                        continue;
                    }

                    $risk = $this->estimateRiskAtCell($enemies, $u, $x, $y);

                    $score = 0;
                    $score += $improve * 11;
                    $score -= $risk * 16;

                    if ($round <= 3) {
                        $distOwnBase = abs($x - $ownBase['x']) + abs($y - $ownBase['y']);
                        $score -= max(0, $distOwnBase - 2) * 10;
                    }

                    if (($u['type'] ?? '') === 'archer') {
                        $score += 8;
                    }

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = ['unit' => $u, 'to' => ['x' => $x, 'y' => $y]];
                    }
                }
            }
        }

        if ($best === null || $bestScore < 10) {
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

    private function estimateRiskAtCell(array $enemies, array $unit, int $x, int $y): int
    {
        $risk = 0;
        $hp = (int) ($unit['hp'] ?? 1);

        foreach ($enemies as $enemy) {
            $enemyType = (string) ($enemy['type'] ?? '');
            $enemyAtk = (int) ($enemy['attack_power'] ?? 0);

            if ($enemyType === 'archer') {
                $risk += 3;
                if ($enemyAtk >= $hp) {
                    $risk += 5;
                }
                continue;
            }

            $dx = abs((int) ($enemy['position_x'] ?? 0) - $x);
            $dy = abs((int) ($enemy['position_y'] ?? 0) - $y);

            if (max($dx, $dy) === 1) {
                $risk += 3;
                if ($enemyAtk >= $hp) {
                    $risk += 5;
                }
            }
        }

        return $risk;
    }

    private function tryBestDeployConservative(GameApiClient $api, int $gameId, array $state, string $side, array &$actions): bool
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

                $distEnemyBase = abs($cell['x'] - $enemyBase['x']) + abs($cell['y'] - $enemyBase['y']);

                $score = 0;
                $score -= $distEnemyBase * 8;
                $score += match ($type) {
                    'infantry' => 22,
                    'berserker' => 19,
                    'archer' => 21,
                    'scout' => 9,
                    default => 0,
                };
                $score -= $cost * 2;

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

        $baseHp = $this->getBaseHpBySide($state, $targetSide) ?? 999;

        usort($candidates, function (array $a, array $b) use ($baseHp): int {
            $overflowA = max(0, (int) ($a['attack_power'] ?? 0) - $baseHp);
            $overflowB = max(0, (int) ($b['attack_power'] ?? 0) - $baseHp);
            if ($overflowA !== $overflowB) {
                return $overflowA <=> $overflowB;
            }

            return ((int) ($b['attack_power'] ?? 0)) <=> ((int) ($a['attack_power'] ?? 0));
        });

        $attacker = $candidates[0];
        $res = $api->attackBaseWithUnit($gameId, $side, (int) $attacker['id'], $targetSide);
        $actions[] = $this->action('attack_base_with_unit', $res, [
            'attacker_unit_id' => (int) $attacker['id'],
            'target_side' => $targetSide,
        ]);

        return (bool) ($res['success'] ?? false);
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

    private function getBaseAttackPowerBySide(array $state, string $side): int
    {
        foreach (($state['players'] ?? []) as $player) {
            if (($player['side'] ?? '') === $side) {
                return (int) ($player['base_attack_power'] ?? 0);
            }
        }

        return 0;
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
