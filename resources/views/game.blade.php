<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Игра</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <style>
        .game-board {
            display: grid;
            grid-template-columns: repeat(5, 200px);
            grid-template-rows: repeat(3, 200px);
            gap: 9px;
            margin: 20px 0;
            /* Игровое поле начинается с левого нижнего угла */
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
            width: 150px;
            height: 150px;
            border-radius: 50%;
            display: flex;
            align-items: flex-start;
            justify-content: flex-start;
            font-weight: bold;
            flex-direction: column;
            gap: 6px;
            padding: 9px;
            cursor: pointer;
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

        .sword {
            font-size: 48px;
        }

        .attack-value {
            font-size: 36px;
            font-weight: bold;
        }

        .heart {
            font-size: 42px;
        }

        .hp-value {
            font-size: 36px;
            font-weight: bold;
        }

        .supplies {
            font-size: 24px;
            margin-top: 5px;
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
        }

        .card:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .card.selected {
            transform: scale(1.1);
            border: 3px solid #ff6b6b;
            box-shadow: 0 6px 12px rgba(0,0,0,0.3);
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

        .player-hand {
            margin: 20px 0;
            display: flex;
            overflow-x: auto;
            padding: 10px 0;
        }
    </style>
</head>
<body>
<div id="game-container" data-game-id="{{ $game->id }}">
    <h1>Игра</h1>

    <div class="game-info">
        <p>Ход: {{ $game->current_turn }}</p>
        <p>Раунд: {{ $game->round_number }}</p>
    </div>

    <div class="game-board">
        <!-- Игровое поле 5x3 -->
        @for ($y = 2; $y >= 0; $y--)
            @for ($x = 0; $x < 5; $x++)
                <div class="cell" data-x="{{ $x }}" data-y="{{ $y }}">
                    <div class="cell-number">{{ $x }},{{ $y }}</div>
                    <!-- Отображение штабов игроков -->
                    @if ($x == 0 && $y == 0)
                        <div class="player-base player-1-base" data-cell-x="0" data-cell-y="0">
                            <div class="base-content">
                                <div class="sword">⚔️</div>
                                <div class="attack-value">1</div>
                            </div>
                            <div class="base-content">
                                <div class="heart">❤️</div>
                                <div class="hp-value">10</div>
                            </div>
                            <div class="supplies">Припасы: 5</div>
                        </div>
                    @elseif ($x == 4 && $y == 2)
                        <div class="player-base player-2-base" data-cell-x="4" data-cell-y="2">
                            <div class="base-content">
                                <div class="sword">⚔️</div>
                                <div class="attack-value">1</div>
                            </div>
                            <div class="base-content">
                                <div class="heart">❤️</div>
                                <div class="hp-value">10</div>
                            </div>
                            <div class="supplies">Припасы: 5</div>
                        </div>
                    @endif
                </div>
            @endfor
        @endfor
    </div>

    <div class="player-info">
        <h2>Игрок 1: {{ $game->player_1_name }}</h2>
        <h2>Игрок 2: {{ $game->player_2_name }}</h2>
    </div>

    <div class="player-hand">
        <h3>Ваша рука:</h3>
        @if(isset($player1) && !empty($player1->hand))
            @foreach($player1->hand as $card)
                <div class="card" data-card-type="{{ $card['type'] }}">
                    {{ ucfirst($card['type']) }}
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

    <button onclick="endTurn({{ $game->id }})">Закончить ход</button>
</div>

<script src="{{ asset('js/game.js') }}"></script>
</body>
</html>
