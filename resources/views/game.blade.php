<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Игра</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <style>
        .layout {
            display: flex;
            align-items: flex-start;
            gap: 20px;
        }

        .main-column {
            flex: 1 1 0;
            min-width: 0;
        }

        .right-panel {
            width: 280px;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            padding: 14px;
            background: #ffffff;
            position: sticky;
            top: 12px;
        }

        .right-panel h3 {
            margin: 0 0 10px 0;
            font-size: 20px;
            font-weight: 700;
        }

        .stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: 16px;
        }

        .stat-row:last-child {
            border-bottom: 0;
        }

        .stat-value {
            font-weight: 700;
        }

        .game-board {
            display: grid;
            grid-template-columns: repeat(5, 200px);
            grid-template-rows: repeat(3, 200px);
            gap: 9px;
            margin: 20px 0;
            direction: ltr;
        }

        .cell {
            width: 200px;
            height: 200px;
            border: 1px solid #333;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            position: relative;
            background-color: #f0f0f0;
            box-sizing: border-box;
        }

        .cell-number {
            position: absolute;
            top: 5px;
            left: 5px;
            font-size: 16px;
            font-weight: bold;
            color: #333;
        }

        .player-base {
            width: 180px;
            height: 180px;
            border-radius: 12px;
            display: flex;
            align-items: flex-start;
            justify-content: flex-start;
            font-weight: bold;
            flex-direction: column;
            gap: 6px;
            padding: 9px;
            cursor: pointer;
            border: 2px solid transparent;
            box-sizing: border-box;
        }

        .player-1-base {
            background-color: #ff6b6b;
            color: white;
        }

        .player-2-base {
            background-color: #4ecdc4;
            color: white;
        }

        .base-content {
            display: flex;
            align-items: center;
            gap: 3px;
        }

        .base-content--top {
            width: 180px;
            justify-content: space-between;
            align-items: flex-start;
        }

        .sword {
            font-size: 18px;
            line-height: 1;
        }

        .attack-value {
            font-size: 36px;
            font-weight: bold;
        }

        .heart {
            font-size: 28px;
        }

        .hp-value {
            font-size: 16px;
            font-weight: bold;
            line-height: 1.1;
            word-break: break-word;
            max-width: 120px;
        }

        .supplies-icons {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 2px;
            max-width: 62px;
            font-size: 16px;
            line-height: 1;
        }

        .board-unit {
            width: 150px;
            height: 150px;
            border-radius: 12px;
            padding: 8px;
            color: #fff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-sizing: border-box;
            font-size: 14px;
            cursor: pointer;
            border: 2px solid transparent;
        }

        .board-unit.player-1 {
            background: #e53e3e;
        }

        .board-unit.player-2 {
            background: #3182ce;
        }

        .board-unit-title {
            font-weight: 700;
            font-size: 16px;
        }

        .hp-hearts {
            font-size: 18px;
            line-height: 1.2;
            word-break: break-word;
        }

        .hp-heart {
            font-size: 18px;
            line-height: 1;
            margin-right: 1px;
        }

        .hp-heart.alive {
            filter: none;
            opacity: 1;
        }

        .hp-heart.lost {
            filter: grayscale(1);
            opacity: 0.45;
        }

        .sword-wrap {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .sword-wrap.faded {
            opacity: 0.35;
            filter: grayscale(0.5);
        }

        .movement-wrap {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .movement-wrap.faded {
            opacity: 0.35;
            filter: grayscale(0.5);
        }

        .selected-unit {
            border-color: #f59e0b !important;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.35);
        }

        .cell.move-allowed {
            outline: 4px solid #22c55e;
            outline-offset: -4px;
        }

        .cell.move-allowed-empty {
            cursor: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='36' height='36' viewBox='0 0 36 36'%3E%3Ctext x='2' y='28' font-size='26'%3E%F0%9F%90%8E%3C/text%3E%3C/svg%3E") 4 28, pointer;
        }

        .cell.move-too-far-empty {
            cursor: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='36' height='36' viewBox='0 0 36 36'%3E%3Ctext x='2' y='28' font-size='26'%3E%F0%9F%90%8E%3C/text%3E%3Cline x1='2' y1='4' x2='32' y2='32' stroke='%23ef4444' stroke-width='3'/%3E%3C/svg%3E") 4 28, not-allowed;
        }

        .cell.attack-allowed-enemy {
            cursor: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='36' height='36' viewBox='0 0 36 36'%3E%3Ctext x='2' y='28' font-size='24'%3E%E2%9A%94%EF%B8%8F%3C/text%3E%3C/svg%3E") 4 28, pointer;
        }

        .cell.attack-too-far-enemy {
            cursor: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='36' height='36' viewBox='0 0 36 36'%3E%3Ctext x='2' y='28' font-size='24'%3E%E2%9A%94%EF%B8%8F%3C/text%3E%3Cline x1='2' y1='4' x2='32' y2='32' stroke='%23ef4444' stroke-width='3'/%3E%3C/svg%3E") 4 28, not-allowed;
        }

        .cell.attack-allowed-ranged-enemy {
            cursor: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='36' height='36' viewBox='0 0 36 36'%3E%3Ctext x='2' y='28' font-size='24'%3E%F0%9F%8F%B9%3C/text%3E%3C/svg%3E") 4 28, pointer;
        }

        .cell.attack-blocked-ranged-enemy {
            cursor: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='36' height='36' viewBox='0 0 36 36'%3E%3Ctext x='2' y='28' font-size='24'%3E%F0%9F%8F%B9%3C/text%3E%3Cline x1='2' y1='4' x2='32' y2='32' stroke='%23ef4444' stroke-width='3'/%3E%3C/svg%3E") 4 28, not-allowed;
        }

        .cell.move-attack {
            outline: 4px solid #ef4444;
            outline-offset: -4px;
        }

        .hands-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            align-items: start;
            margin-top: 10px;
        }

        .hand-column.left {
            text-align: left;
        }

        .hand-column.right {
            text-align: right;
        }

        .hand-column.right .player-hand {
            justify-content: flex-end;
        }

        .player-hand {
            margin: 20px 0;
            display: flex;
            overflow-x: auto;
            padding: 10px 0;
            gap: 0;
            flex-wrap: nowrap;
        }

        .card {
            padding: 15px;
            margin: 15px;
            border: 1px solid #ccc;
            border-radius: 15px;
            background-color: #f9f9f9;
            font-size: 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            width: 150px;
            height: 150px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            box-sizing: border-box;
            flex: 0 0 auto;
        }

        .card:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .card.selected {
            transform: scale(1.1);
            border: 3px solid #ff6b6b;
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.3);
        }

        .card.disabled {
            opacity: 0.45;
            pointer-events: none;
            filter: grayscale(0.2);
        }

        .card-stats {
            display: flex;
            justify-content: space-around;
            margin-top: 10px;
            font-size: 18px;
        }

        .card-stat {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .card-stat-value {
            font-weight: bold;
            font-size: 20px;
        }

        .winner-banner {
            margin: 12px 0;
            padding: 12px 14px;
            border-radius: 10px;
            background: #16a34a;
            color: #fff;
            font-weight: 700;
            font-size: 18px;
        }

        .replay-banner {
            margin: 12px 0;
            padding: 12px 14px;
            border-radius: 10px;
            background: #1d4ed8;
            color: #fff;
            font-weight: 700;
            font-size: 16px;
        }

        .events-log {
            margin-top: 14px;
            border-top: 1px solid #e5e7eb;
            padding-top: 10px;
        }

        .events-log h4 {
            margin: 0 0 8px 0;
            font-size: 16px;
            font-weight: 700;
        }

        .events-list {
            max-height: 360px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding-right: 4px;
        }

        .event-item {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 8px;
            background: #fafafa;
            font-size: 13px;
            line-height: 1.35;
        }

        .event-meta {
            color: #6b7280;
            font-size: 12px;
            margin-bottom: 4px;
        }
    </style>
</head>
<body>
@php
    $isReplay = (bool) ($isReplay ?? false);
    $activeSide = $currentPlayerSide ?? 'player_1';
    $activePlayer = $activeSide === 'player_1' ? ($player1 ?? null) : ($player2 ?? null);

    $activeDeckCount = (isset($activePlayer) && is_array($activePlayer->deck)) ? count($activePlayer->deck) : 0;
    $activeSupplies = $activePlayer->supplies_current ?? 0;
    $activeDiscardCount = isset($activePlayer)
        ? ($game->units->where('owner_id', $activePlayer->id)->where('state', 'graveyard')->count())
        : 0;

    $player1Hand = (isset($player1) && is_array($player1->hand)) ? $player1->hand : [];
    $player2Hand = (isset($player2) && is_array($player2->hand)) ? $player2->hand : [];

    $unitsByCell = ($unitsByCell ?? collect())->mapWithKeys(function ($unit, $key) {
        return [$key => $unit];
    });
@endphp

<div id="game-container"
     data-game-id="{{ $game->id }}"
     data-current-player-side="{{ $activeSide }}"
     data-ws-key="{{ env('REVERB_APP_KEY', 'app-key') }}"
     data-ws-host="{{ env('REVERB_HOST', request()->getHost()) }}"
     data-ws-port="{{ env('REVERB_PORT', 8080) }}"
     data-ws-scheme="{{ env('REVERB_SCHEME', 'http') }}">
    <div class="layout">
        <div class="main-column">
            <h1>Игра</h1>

            @if($isReplay)
                <div class="replay-banner">
                    Режим просмотра сыгранного боя ·
                    <a href="{{ route('battles.index') }}" style="color:#fff;text-decoration:underline;">К списку боёв</a>
                </div>
            @endif

            @if(($game->status ?? null) === 'finished' && !empty($winnerName))
                <div class="winner-banner">
                    Игра завершена. Победитель: {{ $winnerName }}
                    ({{ $winnerSide === 'player_1' ? 'Игрок 1' : 'Игрок 2' }})
                </div>
            @endif

            <div class="game-info">
                <p>Ход: {{ $game->current_turn }}</p>
                <p>Раунд: {{ $game->round_number }}</p>
                <p>Статус: {{ $game->status }}</p>
            </div>

            <div class="player-info">
                <h2>Игрок 1: {{ $game->player_1_name }}</h2>
                <h2>Игрок 2: {{ $game->player_2_name }}</h2>
            </div>

            <div class="game-board">
                @for ($y = 2; $y >= 0; $y--)
                    @for ($x = 0; $x < 5; $x++)
                        @php
                            $cellKey = $x . ':' . $y;
                            $unit = $unitsByCell[$cellKey] ?? null;
                            $unitSideClass = $unit ? ($unit->owner->side === 'player_1' ? 'player-1' : 'player-2') : '';
                        @endphp
                        <div class="cell" data-x="{{ $x }}" data-y="{{ $y }}">
                            <div class="cell-number">{{ $x }},{{ $y }}</div>

                            @if ($unit)
                                @php
                                    $swordFaded = (bool)($unit->has_attacked_this_turn ?? false);
                                    $movementFaded = ((int)($unit->movement_points ?? 0) <= 0);
                                @endphp
                                <div
                                    class="board-unit {{ $unitSideClass }}"
                                    data-unit-id="{{ $unit->id }}"
                                    data-unit-type="{{ $unit->type }}"
                                    data-owner-side="{{ $unit->owner->side }}"
                                    data-movement-points="{{ $unit->movement_points }}"
                                    data-attack-power="{{ $unit->attack_power }}"
                                    data-current-hp="{{ $unit->hp }}"
                                    data-max-hp="{{ $unit->max_hp }}"
                                    data-has-attacked="{{ ($unit->has_attacked_this_turn ?? false) ? '1' : '0' }}"
                                    data-has-counter-attacked="{{ ($unit->has_counter_attacked_this_turn ?? false) ? '1' : '0' }}"
                                    data-x="{{ $x }}"
                                    data-y="{{ $y }}"
                                >
                                    <div class="board-unit-title">#{{ $unit->id }} {{ ucfirst($unit->type) }}</div>
                                    <div class="hp-hearts" data-current-hp="{{ $unit->hp }}" data-max-hp="{{ $unit->max_hp }}">
                                        @for ($i = 1; $i <= (int)$unit->max_hp; $i++)
                                            <span class="hp-heart {{ $i <= (int)$unit->hp ? 'alive' : 'lost' }}">❤️</span>
                                        @endfor
                                    </div>
                                    <div>
                                            <span class="sword-wrap {{ $swordFaded ? 'faded' : '' }}">
                                                <span>⚔️</span>
                                                <span>{{ $unit->attack_power }}</span>
                                            </span>
                                        <span> | </span>
                                        <span class="movement-wrap {{ $movementFaded ? 'faded' : '' }}">
                                                <span>🐎</span>
                                                <span>{{ $unit->movement_points }}</span>
                                            </span>
                                    </div>
                                </div>
                            @elseif ($x === 0 && $y === 0)
                                @php
                                    $base1SwordFaded = (bool)($player1->base_has_attacked_this_turn ?? false);
                                    $base1Supplies = max(0, (int)($player1->supplies_current ?? 0));
                                @endphp
                                <div class="player-base player-1-base"
                                     data-cell-x="0"
                                     data-cell-y="0"
                                     data-owner-side="player_1"
                                     data-base-attack-power="{{ $player1->base_attack ?? 1 }}"
                                     data-base-has-attacked="{{ $base1SwordFaded ? '1' : '0' }}">
                                    <div class="base-content base-content--top">
                                            <span class="sword-wrap {{ $base1SwordFaded ? 'faded' : '' }}">
                                                <span class="sword">⚔️</span>
                                                <span class="attack-value">{{ $player1->base_attack ?? 1 }}</span>
                                            </span>
                                        <span class="supplies-icons" title="Припасы: {{ $base1Supplies }}">
                                                {{ str_repeat('🌾', $base1Supplies) }}
                                            </span>
                                    </div>
                                    <div class="base-content">
                                        <div class="heart">❤️</div>
                                        <div class="hp-value">{{ str_repeat('❤️', max(0, (int)($player1->base_hp ?? 0))) }}</div>
                                    </div>
                                </div>
                            @elseif ($x === 4 && $y === 2)
                                @php
                                    $base2SwordFaded = (bool)($player2->base_has_attacked_this_turn ?? false);
                                    $base2Supplies = max(0, (int)($player2->supplies_current ?? 0));
                                @endphp
                                <div class="player-base player-2-base"
                                     data-cell-x="4"
                                     data-cell-y="2"
                                     data-owner-side="player_2"
                                     data-base-attack-power="{{ $player2->base_attack ?? 1 }}"
                                     data-base-has-attacked="{{ $base2SwordFaded ? '1' : '0' }}">
                                    <div class="base-content base-content--top">
                                            <span class="sword-wrap {{ $base2SwordFaded ? 'faded' : '' }}">
                                                <span class="sword">⚔️</span>
                                                <span class="attack-value">{{ $player2->base_attack ?? 1 }}</span>
                                            </span>
                                        <span class="supplies-icons" title="Припасы: {{ $base2Supplies }}">
                                                {{ str_repeat('🌾', $base2Supplies) }}
                                            </span>
                                    </div>
                                    <div class="base-content">
                                        <div class="heart">❤️</div>
                                        <div class="hp-value">{{ str_repeat('❤️', max(0, (int)($player2->base_hp ?? 0))) }}</div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endfor
                @endfor
            </div>

            <div class="hands-row">
                <div class="hand-column left">
                    <h3>Рука игрока 1 ({{ count($player1Hand) }}) — {{ $game->player_1_name ?? 'Игрок 1' }}</h3>
                    <div class="player-hand">
                        @if(count($player1Hand))
                            @foreach($player1Hand as $card)
                                <div class="card {{ $isReplay || $activeSide !== 'player_1' ? 'disabled' : '' }}"
                                     data-card-type="{{ $card['type'] }}"
                                     data-owner-side="player_1">
                                    {{ ucfirst($card['type']) }} ({{ match($card['type']) { 'archer' => 3, 'berserker' => 4, 'infantry' => 2, 'scout' => 1, default => 0 } }})
                                    <div class="card-stats">
                                        <div class="card-stat">
                                            <span>HP</span>
                                            <span class="card-stat-value">{{ App\Models\Unit::fromCardType($card['type'])['max_hp'] }}</span>
                                        </div>
                                        <div class="card-stat">
                                            <span>Атака</span>
                                            <span class="card-stat-value">{{ App\Models\Unit::fromCardType($card['type'])['attack_power'] }}</span>
                                        </div>
                                        <div class="card-stat">
                                            <span>Движение</span>
                                            <span class="card-stat-value">{{ App\Models\Unit::fromCardType($card['type'])['movement_points'] }}</span>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        @else
                            <p>Нет карт в руке</p>
                        @endif
                    </div>
                </div>

                <div class="hand-column right">
                    <h3>Рука игрока 2 ({{ count($player2Hand) }}) — {{ $game->player_2_name ?? 'Игрок 2' }}</h3>
                    <div class="player-hand">
                        @if(count($player2Hand))
                            @foreach($player2Hand as $card)
                                <div class="card {{ $isReplay || $activeSide !== 'player_2' ? 'disabled' : '' }}"
                                     data-card-type="{{ $card['type'] }}"
                                     data-owner-side="player_2">
                                    {{ ucfirst($card['type']) }} ({{ match($card['type']) { 'archer' => 3, 'berserker' => 4, 'infantry' => 2, 'scout' => 1, default => 0 } }})
                                    <div class="card-stats">
                                        <div class="card-stat">
                                            <span>HP</span>
                                            <span class="card-stat-value">{{ App\Models\Unit::fromCardType($card['type'])['max_hp'] }}</span>
                                        </div>
                                        <div class="card-stat">
                                            <span>Атака</span>
                                            <span class="card-stat-value">{{ App\Models\Unit::fromCardType($card['type'])['attack_power'] }}</span>
                                        </div>
                                        <div class="card-stat">
                                            <span>Движение</span>
                                            <span class="card-stat-value">{{ App\Models\Unit::fromCardType($card['type'])['movement_points'] }}</span>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        @else
                            <p>Нет карт в руке</p>
                        @endif
                    </div>
                </div>
            </div>

            @if(!$isReplay)
                <div style="display:flex; gap: 10px; margin: 8px 0 14px;">
                    <button type="button" id="end-turn-btn" onclick="endTurn({{ $game->id }})">Закончить ход</button>
                    <button type="button" id="bot-toggle-btn" data-game-id="{{ $game->id }}">Бот: выкл</button>
                </div>
                <p id="bot-toggle-hint" style="margin: 0 0 14px; color: #4b5563; font-size: 14px;">
                    Автоход за игрока 2
                </p>
            @endif
        </div>

        <aside class="right-panel">
            <h3>Ваши ресурсы</h3>
            <div class="stat-row">
                <span>Припасы</span>
                <span class="stat-value">{{ $activeSupplies }}</span>
            </div>
            <div class="stat-row">
                <span>Карт в колоде</span>
                <span class="stat-value">{{ $activeDeckCount }}</span>
            </div>
            <div class="stat-row">
                <span>Карт в отбое</span>
                <span class="stat-value">{{ $activeDiscardCount }}</span>
            </div>

            <div class="events-log">
                <h4>Лог действий</h4>
                <div class="events-list">
                    @forelse(($recentEvents ?? collect()) as $event)
                        @php
                            $actorLabel = $event->actor_side === 'player_1'
                                ? ($game->player_1_name ?? 'Игрок 1')
                                : ($game->player_2_name ?? 'Игрок 2');

                            $payload = is_array($event->payload) ? $event->payload : [];
                            $description = match ($event->event_type) {
                                'deploy_card' => 'выставил ' . ($payload['unit_type'] ?? 'карту')
                                    . ' на (' . data_get($payload, 'to.x', '?') . ',' . data_get($payload, 'to.y', '?') . ')',
                                'move_unit' => 'переместил юнита #' . ($payload['unit_id'] ?? '?')
                                    . ' с (' . data_get($payload, 'from.x', '?') . ',' . data_get($payload, 'from.y', '?') . ')'
                                    . ' на (' . data_get($payload, 'to.x', '?') . ',' . data_get($payload, 'to.y', '?') . ')',
                                'attack_unit' => 'атаковал: юнит #' . ($payload['attacker_unit_id'] ?? '?')
                                    . ' → юнит #' . ($payload['target_unit_id'] ?? '?'),
                                'attack_base' => ($payload['source_type'] ?? 'unit') === 'base'
                                    ? 'штаб атаковал штаб противника'
                                    : 'юнит #' . ($payload['attacker_unit_id'] ?? '?') . ' атаковал штаб',
                                'attack_with_base' => 'штаб атаковал юнита #' . ($payload['target_unit_id'] ?? '?'),
                                'end_turn' => 'завершил ход',
                                'game_finished' => 'завершение игры. Победитель: '
                                    . ((data_get($payload, 'winner_side') === 'player_1') ? ($game->player_1_name ?? 'Игрок 1') : ($game->player_2_name ?? 'Игрок 2')),
                                default => 'совершил действие: ' . $event->event_type,
                            };
                        @endphp

                        <div class="event-item">
                            <div class="event-meta">
                                #{{ $event->sequence }} · Ход {{ $event->turn_number }} · Раунд {{ $event->round_number }}
                            </div>
                            <div><strong>{{ $actorLabel }}</strong> {{ $description }}</div>
                        </div>
                    @empty
                        <div class="event-item">Пока нет действий</div>
                    @endforelse
                </div>
            </div>
        </aside>
    </div>
</div>

@if(!$isReplay)
    <script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
    <script src="{{ asset('js/game-bot.js') }}"></script>
    <script src="{{ asset('js/game.js') }}"></script>
@endif
</body>
</html>
