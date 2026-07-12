<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Наблюдение за игрой #{{ $gameId }}</title>
    <style>
        body { font-family: sans-serif; margin: 20px; }
        .top { margin-bottom: 12px; }
        .meta { display: flex; gap: 16px; margin-bottom: 12px; flex-wrap: wrap; }
        .board { display: grid; grid-template-columns: repeat(5, 100px); grid-template-rows: repeat(3, 100px); gap: 8px; }
        .cell { border: 1px solid #ccc; border-radius: 8px; padding: 6px; font-size: 12px; background: #f9fafb; position: relative; }
        .coord { position: absolute; top: 4px; right: 6px; color: #666; font-size: 10px; }
        .base { font-weight: 700; }
        .u1 { color: #b91c1c; }
        .u2 { color: #1d4ed8; }
        .muted { color: #666; }
    </style>
</head>
<body>
<div id="watch-root"
     data-game-id="{{ $gameId }}"
     data-ws-key="{{ env('REVERB_APP_KEY', 'app-key') }}"
     data-ws-host="{{ env('REVERB_HOST', request()->getHost()) }}"
     data-ws-port="{{ env('REVERB_PORT', 8080) }}"
     data-ws-scheme="{{ env('REVERB_SCHEME', 'http') }}">
    <h1>Наблюдение за игрой #{{ $gameId }}</h1>

    <div class="top">
        <a href="{{ route('game.show', ['id' => $gameId]) }}">Перейти в игру</a>
    </div>

    <div class="meta">
        <div>Статус: <strong id="m-status">—</strong></div>
        <div>Ход: <strong id="m-turn">—</strong></div>
        <div>Раунд: <strong id="m-round">—</strong></div>
        <div>Текущий: <strong id="m-side">—</strong></div>
        <div class="muted">Обновлено: <span id="m-updated">—</span></div>
    </div>

    <div class="meta">
        <div>Игрок 1 HP базы: <strong id="p1-hp">—</strong></div>
        <div>Игрок 2 HP базы: <strong id="p2-hp">—</strong></div>
    </div>

    <div id="board" class="board"></div>
</div>

<script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
<script src="{{ asset('js/game-watch.js') }}"></script>
</body>
</html>
