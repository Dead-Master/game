<?php

declare(strict_types=1);

namespace BotService\Strategies;

use BotService\Contracts\BotStrategyInterface;
use BotService\GameApiClient;

final class ScriptedBotStrategy implements BotStrategyInterface
{
    private const array CARD_COSTS = [
        'archer' => 3,
        'berserker' => 4,
        'infantry' => 2,
        'scout' => 1,
    ];

    public function name(): string
    {
        return 'scripted';
    }

    public function playTurn(GameApiClient $api, int $gameId, string $side): array
    {
        $actions = [];

        if (!in_array($side, ['player_1', 'player_2'], true)) {
            return ['status' => 'invalid_side', 'actions' => $actions, 'strategy' => $this->name()];
        }

        $targetSide = $side === 'player_1' ? 'player_2' : 'player_1';

        $state = $this->refreshState($api, $gameId);
        if (($state['game']['status'] ?? 'finished') !== 'active') {
            return ['status' => 'game_not_active', 'actions' => $actions, 'strategy' => $this->name()];
        }

        if (($state['current_player_side'] ?? '') !== $side) {
            return ['status' => 'not_bot_turn', 'actions' => $actions, 'strategy' => $this->name()];
        }

        // Все координаты ниже заданы в "канонических" координатах player_1
        // (своя база (0,0), вражеская (4,2)) и зеркалятся для player_2
        // через cell()/lineX(): (x, y) -> (4 - x, 2 - y).

        [$keyCellX, $keyCellY] = $this->cell($side, 0, 1);          // клетка деплоя у своей базы
        [$keyForwardX, $keyForwardY] = $this->cell($side, 1, 1);    // куда уходит не-archer из спец-ветки
        [$archerForwardX, $archerForwardY] = $this->cell($side, 0, 2);
        [$nearEnemyX, $nearEnemyY] = $this->cell($side, 4, 1);      // клетка у вражеской базы
        [$approachX, $approachY] = $this->cell($side, 4, 0);        // подход к клетке у вражеской базы

        // Спец-ветка: если на клетке деплоя стоит не archer
        $unitAtKey = $this->unitAt($state, $keyCellX, $keyCellY);
        if ($unitAtKey !== null && ($unitAtKey['owner_side'] ?? '') === $side && ($unitAtKey['type'] ?? '') !== 'archer') {
            $this->tryAttackBestForUnit($api, $gameId, $side, (int) $unitAtKey['id'], $actions);
            $state = $this->refreshState($api, $gameId);

            $freshAtKey = $this->findOwnUnitById($state, $side, (int) $unitAtKey['id']);
            if ($freshAtKey !== null && $this->cellEmpty($state, $keyForwardX, $keyForwardY) && $this->canMoveByType($freshAtKey, $keyForwardX, $keyForwardY)) {
                $res = $api->moveUnit($gameId, $side, (int) $freshAtKey['id'], $keyForwardX, $keyForwardY);
                $actions[] = $this->action('move_unit', $res, [
                    'unit_id' => (int) $freshAtKey['id'],
                    'to' => ['x' => $keyForwardX, 'y' => $keyForwardY],
                ]);

                $state = $this->refreshState($api, $gameId);

                $moved = $this->findOwnUnitById($state, $side, (int) $unitAtKey['id']);
                if ($moved !== null && !((bool) ($moved['has_attacked_this_turn'] ?? false))) {
                    $this->tryAttackBestForUnit($api, $gameId, $side, (int) $moved['id'], $actions);
                    $state = $this->refreshState($api, $gameId);
                }
            }

            if ($this->cellEmpty($state, $keyCellX, $keyCellY)) {
                $botPlayer = $this->getPlayerBySide($state, $side);
                $playable = $this->pickPlayableCard(
                    is_array($botPlayer['hand'] ?? null) ? $botPlayer['hand'] : [],
                    (int) ($botPlayer['supplies_current'] ?? 0)
                );

                if ($playable !== null) {
                    $res = $api->deployCard($gameId, $side, (string) $playable['type'], $keyCellX, $keyCellY);
                    $actions[] = $this->action('deploy_card', $res, [
                        'type' => (string) $playable['type'],
                        'to' => ['x' => $keyCellX, 'y' => $keyCellY],
                    ]);

                    $state = $this->refreshState($api, $gameId);
                    $justDeployed = $this->unitAt($state, $keyCellX, $keyCellY);
                    if ($justDeployed !== null && ($justDeployed['owner_side'] ?? '') === $side) {
                        $this->tryAttackBestForUnit($api, $gameId, $side, (int) $justDeployed['id'], $actions);
                    }
                }
            }

            $end = $api->endTurn($gameId, $side);
            $actions[] = $this->action('end_turn', $end);

            return ['status' => 'ok', 'actions' => $actions, 'strategy' => $this->name()];
        }

        // 1) archer с клетки деплоя двигается вперёд, если пусто
        $archerAtKey = $this->unitAt($state, $keyCellX, $keyCellY);
        if (
            $archerAtKey !== null
            && ($archerAtKey['owner_side'] ?? '') === $side
            && ($archerAtKey['type'] ?? '') === 'archer'
            && $this->cellEmpty($state, $archerForwardX, $archerForwardY)
            && $this->canMoveByType($archerAtKey, $archerForwardX, $archerForwardY)
        ) {
            $res = $api->moveUnit($gameId, $side, (int) $archerAtKey['id'], $archerForwardX, $archerForwardY);
            $actions[] = $this->action('move_unit', $res, [
                'unit_id' => (int) $archerAtKey['id'],
                'to' => ['x' => $archerForwardX, 'y' => $archerForwardY],
            ]);
            $state = $this->refreshState($api, $gameId);
        }

        // 2) если хватает ресурсов и есть archer в руке -> деплой на клетку деплоя
        $botPlayer = $this->getPlayerBySide($state, $side);
        $hasArcher = $this->hasCardType(is_array($botPlayer['hand'] ?? null) ? $botPlayer['hand'] : [], 'archer');
        if ((int) ($botPlayer['supplies_current'] ?? 0) >= self::CARD_COSTS['archer'] && $hasArcher && $this->cellEmpty($state, $keyCellX, $keyCellY)) {
            $res = $api->deployCard($gameId, $side, 'archer', $keyCellX, $keyCellY);
            $actions[] = $this->action('deploy_card', $res, ['type' => 'archer', 'to' => ['x' => $keyCellX, 'y' => $keyCellY]]);
            $state = $this->refreshState($api, $gameId);
        }

        // 3) если у вражеской базы стоит свой юнит -> атакует штаб
        $unitNearEnemy = $this->unitAt($state, $nearEnemyX, $nearEnemyY);
        if ($unitNearEnemy !== null && ($unitNearEnemy['owner_side'] ?? '') === $side && $this->canAttackBase($unitNearEnemy, $targetSide)) {
            $res = $api->attackBaseWithUnit($gameId, $side, (int) $unitNearEnemy['id'], $targetSide);
            $actions[] = $this->action('attack_base_with_unit', $res, ['attacker_unit_id' => (int) $unitNearEnemy['id']]);
            $state = $this->refreshState($api, $gameId);
        }

        // 4) если на подходе стоит свой юнит -> двигаем к базе врага -> атака штаба
        $unitOnApproach = $this->unitAt($state, $approachX, $approachY);
        if ($unitOnApproach !== null && ($unitOnApproach['owner_side'] ?? '') === $side) {
            if ($this->cellEmpty($state, $nearEnemyX, $nearEnemyY) && $this->canMoveByType($unitOnApproach, $nearEnemyX, $nearEnemyY)) {
                $res = $api->moveUnit($gameId, $side, (int) $unitOnApproach['id'], $nearEnemyX, $nearEnemyY);
                $actions[] = $this->action('move_unit', $res, [
                    'unit_id' => (int) $unitOnApproach['id'],
                    'to' => ['x' => $nearEnemyX, 'y' => $nearEnemyY],
                ]);
                $state = $this->refreshState($api, $gameId);
            }

            $unitNearEnemy = $this->unitAt($state, $nearEnemyX, $nearEnemyY);
            if ($unitNearEnemy !== null && ($unitNearEnemy['owner_side'] ?? '') === $side && $this->canAttackBase($unitNearEnemy, $targetSide)) {
                $res = $api->attackBaseWithUnit($gameId, $side, (int) $unitNearEnemy['id'], $targetSide);
                $actions[] = $this->action('attack_base_with_unit', $res, ['attacker_unit_id' => (int) $unitNearEnemy['id']]);
                $state = $this->refreshState($api, $gameId);
            }
        }

        // 5) линии (канонич. x=3..1): атака линии впереди, move вперёд, доатака после move
        foreach ([3, 2, 1] as $canonicalLineX) {
            $lineX = $this->lineX($side, $canonicalLineX);
            $frontX = $this->lineX($side, $canonicalLineX + 1);

            $state = $this->refreshState($api, $gameId);
            $lineUnits = array_values(array_filter(
                $this->getOwnUnits($state, $side),
                fn (array $u): bool => (int) ($u['position_x'] ?? -1) === $lineX
            ));

            usort($lineUnits, fn (array $a, array $b): int => ((int) $a['position_y']) <=> ((int) $b['position_y']));

            foreach ($lineUnits as $unit) {
                $state = $this->refreshState($api, $gameId);
                $me = $this->findOwnUnitById($state, $side, (int) $unit['id']);
                if ($me === null) {
                    continue;
                }

                $targetOnFront = $this->findFrontLineTargetForUnit($state, $me, $frontX, $side);
                if ($targetOnFront !== null) {
                    $res = $api->attackUnit($gameId, $side, (int) $me['id'], (int) $targetOnFront['id']);
                    $actions[] = $this->action('attack_unit', $res, [
                        'attacker_unit_id' => (int) $me['id'],
                        'target_unit_id' => (int) $targetOnFront['id'],
                    ]);
                    $state = $this->refreshState($api, $gameId);
                }

                $meAfter = $this->findOwnUnitById($state, $side, (int) $unit['id']);
                if ($meAfter === null) {
                    continue;
                }

                if ($this->cellEmpty($state, $frontX, (int) $meAfter['position_y']) && $this->canMoveByType($meAfter, $frontX, (int) $meAfter['position_y'])) {
                    $res = $api->moveUnit($gameId, $side, (int) $meAfter['id'], $frontX, (int) $meAfter['position_y']);
                    $actions[] = $this->action('move_unit', $res, [
                        'unit_id' => (int) $meAfter['id'],
                        'to' => ['x' => $frontX, 'y' => (int) $meAfter['position_y']],
                    ]);

                    $state = $this->refreshState($api, $gameId);
                    $moved = $this->findOwnUnitById($state, $side, (int) $unit['id']);
                    if ($moved !== null && !((bool) ($moved['has_attacked_this_turn'] ?? false))) {
                        $this->tryAttackBestForUnit($api, $gameId, $side, (int) $moved['id'], $actions);
                    }
                }
            }
        }

        // 6) деплой (канонич. (1,1), (1,0)) и попытка атаки
        $deployCells = [
            $this->cell($side, 1, 1),
            $this->cell($side, 1, 0),
        ];

        foreach ($deployCells as [$cellX, $cellY]) {
            $state = $this->refreshState($api, $gameId);
            $botPlayer = $this->getPlayerBySide($state, $side);
            if ($botPlayer === null || !$this->cellEmpty($state, $cellX, $cellY)) {
                continue;
            }

            $playable = $this->pickPlayableCard(
                is_array($botPlayer['hand'] ?? null) ? $botPlayer['hand'] : [],
                (int) ($botPlayer['supplies_current'] ?? 0)
            );

            if ($playable === null) {
                continue;
            }

            $res = $api->deployCard($gameId, $side, (string) $playable['type'], $cellX, $cellY);
            $actions[] = $this->action('deploy_card', $res, [
                'type' => (string) $playable['type'],
                'to' => ['x' => $cellX, 'y' => $cellY],
            ]);

            $state = $this->refreshState($api, $gameId);
            $justDeployed = $this->unitAt($state, $cellX, $cellY);
            if ($justDeployed !== null && ($justDeployed['owner_side'] ?? '') === $side) {
                $this->tryAttackBestForUnit($api, $gameId, $side, (int) $justDeployed['id'], $actions);
            }
        }

        // 7) archers атакуют юнита с min HP
        $state = $this->refreshState($api, $gameId);
        $myArchers = array_values(array_filter(
            $this->getOwnUnits($state, $side),
            fn (array $u): bool => ($u['type'] ?? '') === 'archer' && !((bool) ($u['has_attacked_this_turn'] ?? false))
        ));

        foreach ($myArchers as $archer) {
            $state = $this->refreshState($api, $gameId);
            $fresh = $this->findOwnUnitById($state, $side, (int) $archer['id']);
            if ($fresh === null || (bool) ($fresh['has_attacked_this_turn'] ?? false)) {
                continue;
            }

            $target = $this->findLowestHpEnemy($state, $side);
            if ($target === null) {
                break;
            }

            $res = $api->attackUnit($gameId, $side, (int) $fresh['id'], (int) $target['id']);
            $actions[] = $this->action('attack_unit', $res, [
                'attacker_unit_id' => (int) $fresh['id'],
                'target_unit_id' => (int) $target['id'],
            ]);
        }

        // 8) штаб атакует юнита с min HP
        $state = $this->refreshState($api, $gameId);
        if ($this->canBaseAttack($state, $side)) {
            $target = $this->findLowestHpEnemy($state, $side);
            if ($target !== null) {
                $res = $api->attackUnitWithBase($gameId, $side, (int) $target['id']);
                $actions[] = $this->action('attack_with_base', $res, [
                    'target_unit_id' => (int) $target['id'],
                ]);
                $state = $this->refreshState($api, $gameId);
            }
        }

        // 9) если у штаба ещё есть атака — бьёт вражеский штаб
        if ($this->canBaseAttack($state, $side)) {
            $res = $api->attackBaseWithBase($gameId, $side, $targetSide);
            $actions[] = $this->action('attack_base_with_base', $res, [
                'target_side' => $targetSide,
            ]);
        }

        // 10) end turn
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

    /**
     * Преобразует "канонические" координаты player_1 в координаты нужной стороны.
     * Для player_2 доска зеркалится: (x, y) -> (4 - x, 2 - y).
     *
     * @return array{0:int, 1:int}
     */
    private function cell(string $side, int $x, int $y): array
    {
        return $side === 'player_1' ? [$x, $y] : [4 - $x, 2 - $y];
    }

    private function lineX(string $side, int $x): int
    {
        return $side === 'player_1' ? $x : 4 - $x;
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

    private function cellEmpty(array $state, int $x, int $y): bool
    {
        if ($this->isBaseCell($x, $y)) {
            return false;
        }

        return $this->unitAt($state, $x, $y) === null;
    }

    private function isBaseCell(int $x, int $y): bool
    {
        return ($x === 0 && $y === 0) || ($x === 4 && $y === 2);
    }

    private function findOwnUnitById(array $state, string $side, int $unitId): ?array
    {
        foreach ($this->getOwnUnits($state, $side) as $u) {
            if ((int) ($u['id'] ?? 0) === $unitId) {
                return $u;
            }
        }

        return null;
    }

    private function hasCardType(array $hand, string $type): bool
    {
        foreach ($hand as $card) {
            if (($card['type'] ?? '') === $type) {
                return true;
            }
        }

        return false;
    }

    private function pickPlayableCard(array $hand, int $supplies): ?array
    {
        usort($hand, function (array $a, array $b): int {
            $costA = self::CARD_COSTS[$a['type'] ?? ''] ?? 999;
            $costB = self::CARD_COSTS[$b['type'] ?? ''] ?? 999;
            return $costA <=> $costB;
        });

        foreach ($hand as $card) {
            $cost = self::CARD_COSTS[$card['type'] ?? ''] ?? 999;
            if ($supplies >= $cost) {
                return $card;
            }
        }

        return null;
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

        $dx = abs((int) $attacker['position_x'] - (int) $defender['position_x']);
        $dy = abs((int) $attacker['position_y'] - (int) $defender['position_y']);

        return max($dx, $dy) === 1;
    }

    private function canAttackBase(array $attacker, string $targetSide): bool
    {
        if ((bool) ($attacker['has_attacked_this_turn'] ?? false)) {
            return false;
        }

        $basePos = $targetSide === 'player_1' ? ['x' => 0, 'y' => 0] : ['x' => 4, 'y' => 2];
        if (($attacker['type'] ?? '') === 'archer') {
            return true;
        }

        $dx = abs((int) $attacker['position_x'] - $basePos['x']);
        $dy = abs((int) $attacker['position_y'] - $basePos['y']);

        return max($dx, $dy) === 1;
    }

    private function findLowestHpEnemy(array $state, string $side): ?array
    {
        $enemies = $this->getEnemyUnits($state, $side);
        if ($enemies === []) {
            return null;
        }

        usort($enemies, function (array $a, array $b): int {
            $hpCmp = ((int) $a['hp']) <=> ((int) $b['hp']);
            if ($hpCmp !== 0) {
                return $hpCmp;
            }
            return ((int) $a['id']) <=> ((int) $b['id']);
        });

        return $enemies[0];
    }

    private function findFrontLineTargetForUnit(array $state, array $unit, int $frontX, string $side): ?array
    {
        $candidates = array_values(array_filter(
            $this->getEnemyUnits($state, $side),
            fn (array $e): bool =>
                (int) ($e['position_x'] ?? -1) === $frontX
                && $this->canAttackUnitByType($unit, $e)
        ));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $a, array $b) use ($unit): int {
            $hpCmp = ((int) $a['hp']) <=> ((int) $b['hp']);
            if ($hpCmp !== 0) {
                return $hpCmp;
            }

            $dyA = abs((int) $a['position_y'] - (int) $unit['position_y']);
            $dyB = abs((int) $b['position_y'] - (int) $unit['position_y']);
            if ($dyA !== $dyB) {
                return $dyA <=> $dyB;
            }

            return ((int) $a['id']) <=> ((int) $b['id']);
        });

        return $candidates[0];
    }

    private function tryAttackBestForUnit(GameApiClient $api, int $gameId, string $side, int $attackerId, array &$actions): bool
    {
        $state = $this->refreshState($api, $gameId);
        $attacker = $this->findOwnUnitById($state, $side, $attackerId);

        if ($attacker === null || (bool) ($attacker['has_attacked_this_turn'] ?? false)) {
            return false;
        }

        $targets = array_values(array_filter(
            $this->getEnemyUnits($state, $side),
            fn (array $e): bool => $this->canAttackUnitByType($attacker, $e)
        ));

        if ($targets === []) {
            return false;
        }

        usort($targets, function (array $a, array $b): int {
            $hpCmp = ((int) $a['hp']) <=> ((int) $b['hp']);
            if ($hpCmp !== 0) {
                return $hpCmp;
            }
            return ((int) $a['id']) <=> ((int) $b['id']);
        });

        $target = $targets[0];
        $res = $api->attackUnit($gameId, $side, $attackerId, (int) $target['id']);

        $actions[] = $this->action('attack_unit', $res, [
            'attacker_unit_id' => $attackerId,
            'target_unit_id' => (int) $target['id'],
        ]);

        return (bool) ($res['success'] ?? false);
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
}
