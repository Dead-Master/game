<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Replay матча #{{ $gameId }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <style>
        .layout { display: flex; align-items: flex-start; gap: 20px; }
        .main-column { flex: 1 1 0; min-width: 0; }

        .right-panel {
            width: 320px;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            padding: 14px;
            background: #fff;
            position: sticky;
            top: 12px;
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
            border: 2px solid transparent;
            box-sizing: border-box;
        }

        .player-1-base { background-color: #ff6b6b; color: white; }
        .player-2-base { background-color: #4ecdc4; color: white; }

        .base-content { display: flex; align-items: center; gap: 3px; }
        .base-content--top { width: 180px; justify-content: space-between; align-items: flex-start; }
        .attack-value { font-size: 36px; font-weight: bold; }
        .hp-value { font-size: 16px; font-weight: bold; line-height: 1.1; word-break: break-word; max-width: 120px; }
        .supplies-icons { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 2px; max-width: 62px; font-size: 16px; line-height: 1; }

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
            border: 2px solid transparent;
        }

        .board-unit.player-1 { background: #e53e3e; }
        .board-unit.player-2 { background: #3182ce; }
        .board-unit-title { font-weight: 700; font-size: 16px; }

        .hp-hearts { font-size: 18px; line-height: 1.2; word-break: break-word; }
        .hp-heart { font-size: 18px; line-height: 1; margin-right: 1px; }
        .hp-heart.alive { opacity: 1; }
        .hp-heart.lost { opacity: 0.45; filter: grayscale(1); }

        .hands-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start; margin-top: 10px; }
        .hand-column.left { text-align: left; }
        .hand-column.right { text-align: right; }
        .hand-column.right .player-hand { justify-content: flex-end; }

        .player-hand { margin: 20px 0; display: flex; overflow-x: auto; padding: 10px 0; gap: 0; flex-wrap: nowrap; }
        .card {
            padding: 15px;
            margin: 15px;
            border: 1px solid #ccc;
            border-radius: 15px;
            background-color: #f9f9f9;
            font-size: 20px;
            text-align: center;
            width: 150px;
            height: 150px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            box-sizing: border-box;
            flex: 0 0 auto;
            opacity: 0.85;
        }

        .card-stats { display: flex; justify-content: space-around; margin-top: 10px; font-size: 16px; }

        .replay-banner {
            margin: 12px 0;
            padding: 12px 14px;
            border-radius: 10px;
            background: #1d4ed8;
            color: #fff;
            font-weight: 700;
            font-size: 16px;
        }

        .controls { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin: 8px 0 12px; }

        .events-log { margin-top: 14px; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        .events-list { max-height: 420px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; padding-right: 4px; }

        .event-item { border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px; background: #fafafa; font-size: 13px; line-height: 1.35; }
        .event-item.active { background: #eff6ff; border-color: #93c5fd; }

        .replay-anim-move-from {
            box-shadow: inset 0 0 0 4px rgba(245, 158, 11, 0.85);
        }

        .replay-anim-move-to {
            box-shadow: inset 0 0 0 4px rgba(34, 197, 94, 0.85);
        }

        .replay-anim-hit-attacker .board-unit,
        .replay-anim-hit-attacker.player-base {
            transform: scale(1.05);
            transition: transform 120ms ease;
            outline: 3px solid rgba(251, 191, 36, 0.9);
            outline-offset: -3px;
        }

        .replay-anim-hit-target .board-unit,
        .replay-anim-hit-target.player-base {
            animation: replayHitFlash 280ms ease-in-out 1;
            outline: 3px solid rgba(239, 68, 68, 0.95);
            outline-offset: -3px;
        }

        @keyframes replayHitFlash {
            0% { filter: brightness(1); }
            50% { filter: brightness(1.6); }
            100% { filter: brightness(1); }
        }

        .replay-damage-pop {
            position: absolute;
            right: 10px;
            top: 10px;
            z-index: 20;
            background: rgba(239, 68, 68, 0.95);
            color: #fff;
            font-weight: 800;
            font-size: 14px;
            border-radius: 999px;
            padding: 4px 8px;
            pointer-events: none;
            animation: replayDamagePop 600ms ease-out forwards;
        }

        .replay-arrow {
            position: fixed;
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: #111827;
            box-shadow: 0 0 0 2px #fbbf24;
            z-index: 9999;
            pointer-events: none;
        }

        .replay-melee-dash {
            animation: replayMeleeDash 180ms ease-in-out 1;
        }

        @keyframes replayMeleeDash {
            0% { transform: translateX(0) scale(1); }
            50% { transform: translateX(8px) scale(1.06); }
            100% { transform: translateX(0) scale(1); }
        }

        @keyframes replayDamagePop {
            0% { opacity: 0; transform: translateY(6px) scale(0.9); }
            20% { opacity: 1; transform: translateY(0) scale(1); }
            100% { opacity: 0; transform: translateY(-12px) scale(1.05); }
        }
    </style>
</head>
<body>
<div id="replay-root"
     data-game-id="{{ $gameId }}"
     data-player-1-name="{{ $player1Name }}"
     data-player-2-name="{{ $player2Name }}">
    <div class="layout">
        <div class="main-column">
            <h1>Replay матча</h1>

            <div class="replay-banner">
                Динамический просмотр · <a href="{{ route('battles.index') }}" style="color:#fff;text-decoration:underline;">К списку боёв</a>
            </div>

            <div class="controls">
                <button id="btn-prev" type="button">⏮ Назад</button>
                <button id="btn-play" type="button">▶ Play</button>
                <button id="btn-next" type="button">⏭ Вперёд</button>

                <label for="speed">Скорость:</label>
                <select id="speed">
                    <option value="1200">0.75x</option>
                    <option value="900" selected>1x</option>
                    <option value="600">1.5x</option>
                    <option value="350">2x</option>
                    <option value="180">5x</option>
                </select>

                <span>Шаг: <strong id="step-current">0</strong>/<strong id="step-total">0</strong></span>
            </div>

            <div class="game-info">
                <p>Ход: <strong id="meta-turn">—</strong></p>
                <p>Раунд: <strong id="meta-round">—</strong></p>
                <p>Ходит: <strong id="meta-side">—</strong></p>
            </div>

            <div class="player-info">
                <h2>Игрок 1: <span id="player-1-name">{{ $player1Name }}</span></h2>
                <h2>Игрок 2: <span id="player-2-name">{{ $player2Name }}</span></h2>
            </div>

            <div id="replay-board" class="game-board"></div>

            <div class="hands-row">
                <div class="hand-column left">
                    <h3>Рука игрока 1 (<span id="hand-1-count">0</span>) — <span>{{ $player1Name }}</span></h3>
                    <div id="hand-1" class="player-hand"></div>
                </div>
                <div class="hand-column right">
                    <h3>Рука игрока 2 (<span id="hand-2-count">0</span>) — <span>{{ $player2Name }}</span></h3>
                    <div id="hand-2" class="player-hand"></div>
                </div>
            </div>
        </div>

        <aside class="right-panel">
            <h3>Состояние шага</h3>
            <div class="stat-row">
                <span>HP базы игрока 1</span>
                <span class="stat-value" id="p1-base-hp">25</span>
            </div>
            <div class="stat-row">
                <span>HP базы игрока 2</span>
                <span class="stat-value" id="p2-base-hp">25</span>
            </div>

            <div class="events-log">
                <h4>Лог действий</h4>
                <div id="events-list" class="events-list"></div>
            </div>
        </aside>
    </div>
</div>

<script src="{{ asset('js/game-replay.js') }}"></script>
</body>
</html>
