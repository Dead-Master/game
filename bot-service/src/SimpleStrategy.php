<?php

declare(strict_types=1);

namespace BotService;

final class SimpleStrategy
{
    public function chooseLowestHpEnemy(array $state, string $side): ?array
    {
        $enemies = array_values(array_filter(
            $state['units'] ?? [],
            fn (array $u): bool => ($u['state'] ?? '') === 'board' && ($u['owner_side'] ?? '') !== $side
        ));

        if ($enemies === []) {
            return null;
        }

        usort($enemies, function (array $a, array $b): int {
            $hpA = (int) ($a['hp'] ?? 999);
            $hpB = (int) ($b['hp'] ?? 999);
            if ($hpA !== $hpB) {
                return $hpA <=> $hpB;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        return $enemies[0];
    }

    public function chooseAvailableArcher(array $state, string $side): ?array
    {
        $archers = array_values(array_filter(
            $state['units'] ?? [],
            fn (array $u): bool =>
                ($u['state'] ?? '') === 'board'
                && ($u['owner_side'] ?? '') === $side
                && ($u['type'] ?? '') === 'archer'
                && !((bool) ($u['has_attacked_this_turn'] ?? false))
        ));

        if ($archers === []) {
            return null;
        }

        return $archers[0];
    }

    public function canBaseAttack(array $state, string $side): bool
    {
        foreach (($state['players'] ?? []) as $player) {
            if (($player['side'] ?? '') === $side) {
                return !((bool) ($player['base_has_attacked_this_turn'] ?? false));
            }
        }

        return false;
    }
}
