<?php

declare(strict_types=1);

namespace BotService\Strategies;

use BotService\Contracts\BotStrategyInterface;
use BotService\GameApiClient;

final class FocusBaseBotStrategy implements BotStrategyInterface
{
    private const array CARD_COSTS = [
        'archer' => 3,
        'berserker' => 4,
        'infantry' => 2,
        'scout' => 1,
    ];

    public function name(): string
    {
        return 'focus_base';
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
        // и зеркалятся для player_2 через cell()/lineX().

        // Ключевые клетки стратегии
        [$archerDeployX, $archerDeployY] = $this->cell($side, 0, 1);
        [$archerForwardX, $archerForwardY] = $this->cell($side, 0, 2);
        $attackLineX = $this->lineX($side, 1);

        // 1) archer с клетки деплоя лучника двигается вперёд, если пусто
        $archerCell = $this->unitAt($state, $archerDeployX, $archerDeployY);
        if (
            $archerCell !== null
            && ($archerCell['owner_side'] ?? '') === $side
            && ($archerCell['type'] ?? '') === 'archer'
            && $this->cellEmpty($state, $archerForwardX, $archerForwardY)
            && $this->canMoveByType($archerCell, $archerForwardX, $archerForwardY)
        ) {
            $res = $api->moveUnit($gameId, $side, (int) $archerCell['id'], $archerForwardX, $archerForwardY);
            $actions[] = $this->action('move_unit', $res, [
                'unit_id' => (int) $archerCell['id'],
                'to' => ['x' => $archerForwardX, 'y' => $archerForwardY],
            ]);
            $state = $this->refreshState($api, $gameId);
        }

        // 2) Если в руке есть archer — выставляем его на клетку деплоя лучника
        $state = $this->refreshState($api, $gameId);
        if ($this->cellEmpty($state, $archerDeployX, $archerDeployY)) {
            $botPlayer = $this->getPlayerBySide($state, $side);
            if ($botPlayer !== null) {
                $res = $this->tryDeployByPriority($api, $gameId, $side, $archerDeployX, $archerDeployY, ['archer'], $botPlayer);
                if ($res !== null) {
                    $actions[] = $res;
                    $state = $this->refreshState($api, $gameId);
                }
            }
        }

        // 3) Если на атакующей линии есть наши войска — атакуем ими врагов
        $this->attackWithLineXUnits($api, $gameId, $side, $attackLineX, $actions);
        $state = $this->refreshState($api, $gameId);

        // 4) Продвижение по атакующей линии: (1,1) -> (1,2), затем (1,0) -> (1,1)
        $advanceSteps = [
            [$this->cell($side, 1, 1), $this->cell($side, 1, 2)],
            [$this->cell($side, 1, 0), $this->cell($side, 1, 1)],
        ];

        foreach ($advanceSteps as [$from, $to]) {
            [$fromX, $fromY] = $from;
            [$toX, $toY] = $to;

            $unit = $this->findOwnUnitAt($state, $side, $fromX, $fromY);
            if ($unit !== null && $this->cellEmpty($state, $toX, $toY) && $this->canMoveByType($unit, $toX, $toY)) {
                $res = $api->moveUnit($gameId, $side, (int) $unit['id'], $toX, $toY);
                $actions[] = $this->action('move_unit', $res, [
                    'unit_id' => (int) $unit['id'],
                    'to' => ['x' => $toX, 'y' => $toY],
                ]);
                $state = $this->refreshState($api, $gameId);
            }
        }

        // 5) Деплой на атакующую линию: сначала (1,1), потом (1,0). Приоритет: berserker, infantry, scout
        $deployCells = [
            $this->cell($side, 1, 1),
            $this->cell($side, 1, 0),
        ];

        foreach ($deployCells as [$deployX, $deployY]) {
            if (!$this->cellEmpty($state, $deployX, $deployY)) {
                continue;
            }

            $botPlayer = $this->getPlayerBySide($state, $side);
            if ($botPlayer === null) {
                continue;
            }

            $res = $this->tryDeployByPriority($api, $gameId, $side, $deployX, $deployY, ['berserker', 'infantry', 'scout'], $botPlayer);
            if ($res !== null) {
                $actions[] = $res;
                $state = $this->refreshState($api, $gameId);
            }
        }

        // 5.1) После деплоя: юниты на атакующей линии с неисп. атакой атакуют врага
        $this->attackWithLineXUnits($api, $gameId, $side, $attackLineX, $actions);
        $state = $this->refreshState($api, $gameId);

        // 6) Лучники и штаб, если могут — бьют вражеский штаб
        $archers = array_values(array_filter(
            $this->getOwnUnits($state, $side),
            fn (array $u): bool => ($u['type'] ?? '') === 'archer' && !((bool) ($u['has_attacked_this_turn'] ?? false))
        ));

        foreach ($archers as $archer) {
            $freshState = $this->refreshState($api, $gameId);
            $freshArcher = $this->findOwnUnitById($freshState, $side, (int) $archer['id']);
            if ($freshArcher === null || (bool) ($freshArcher['has_attacked_this_turn'] ?? false)) {
                continue;
            }

            $res = $api->attackBaseWithUnit($gameId, $side, (int) $freshArcher['id'], $targetSide);
            $actions[] = $this->action('attack_base_with_unit', $res, [
                'attacker_unit_id' => (int) $freshArcher['id'],
                'target_side' => $targetSide,
            ]);
        }

        $state = $this->refreshState($api, $gameId);
        if ($this->canBaseAttack($state, $side)) {
            $res = $api->attackBaseWithBase($gameId, $side, $targetSide);
            $actions[] = $this->action('attack_base_with_base', $res, [
                'target_side' => $targetSide,
            ]);
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

    private function attackWithLineXUnits(GameApiClient $api, int $gameId, string $side, int $lineX, array &$actions): void
    {
        $state = $this->refreshState($api, $gameId);

        $lineUnits = array_values(array_filter(
            $this->getOwnUnits($state, $side),
            fn (array $u): bool =>
                (int) ($u['position_x'] ?? -1) === $lineX
                && !((bool) ($u['has_attacked_this_turn'] ?? false))
        ));

        usort($lineUnits, fn (array $a, array $b): int => ((int) ($a['position_y'] ?? 0)) <=> ((int) ($b['position_y'] ?? 0)));

        foreach ($lineUnits as $unit) {
            $freshState = $this->refreshState($api, $gameId);
            $fresh = $this->findOwnUnitById($freshState, $side, (int) $unit['id']);
            if ($fresh === null || (bool) ($fresh['has_attacked_this_turn'] ?? false)) {
                continue;
            }

            $target = $this->findBestAttackTargetForUnit($freshState, $fresh, $side);
            if ($target === null) {
                continue;
            }

            $res = $api->attackUnit($gameId, $side, (int) $fresh['id'], (int) $target['id']);
            $actions[] = $this->action('attack_unit', $res, [
                'attacker_unit_id' => (int) $fresh['id'],
                'target_unit_id' => (int) $target['id'],
            ]);
        }
    }

    private function findBestAttackTargetForUnit(array $state, array $attacker, string $side): ?array
    {
        $targets = array_values(array_filter(
            $this->getEnemyUnits($state, $side),
            fn (array $e): bool => $this->canAttackUnitByType($attacker, $e)
        ));

        if ($targets === []) {
            return null;
        }

        usort($targets, function (array $a, array $b): int {
            $hpCmp = ((int) ($a['hp'] ?? 999)) <=> ((int) ($b['hp'] ?? 999));
            if ($hpCmp !== 0) {
                return $hpCmp;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        return $targets[0];
    }

    private function tryDeployByPriority(
        GameApiClient $api,
        int $gameId,
        string $side,
        int $x,
        int $y,
        array $priority,
        array $player
    ): ?array {
        $hand = is_array($player['hand'] ?? null) ? $player['hand'] : [];
        $supplies = (int) ($player['supplies_current'] ?? 0);

        foreach ($priority as $type) {
            $inHand = false;
            foreach ($hand as $card) {
                if (($card['type'] ?? '') === $type) {
                    $inHand = true;
                    break;
                }
            }

            if (!$inHand) {
                continue;
            }

            $cost = self::CARD_COSTS[$type] ?? 999;
            if ($supplies < $cost) {
                continue;
            }

            $res = $api->deployCard($gameId, $side, $type, $x, $y);

            return $this->action('deploy_card', $res, [
                'type' => $type,
                'to' => ['x' => $x, 'y' => $y],
            ]);
        }

        return null;
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

    private function findOwnUnitAt(array $state, string $side, int $x, int $y): ?array
    {
        $u = $this->unitAt($state, $x, $y);
        if ($u === null) {
            return null;
        }

        return (($u['owner_side'] ?? '') === $side) ? $u : null;
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
