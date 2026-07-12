<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сыгранные бои</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <style>
        body { font-family: sans-serif; margin: 24px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .table { width: 100%; border-collapse: collapse; background: #fff; }
        .table th, .table td { border: 1px solid #e5e7eb; padding: 10px; text-align: left; }
        .table th { background: #f3f4f6; }
        .muted { color: #6b7280; }
        .actions { display: flex; gap: 10px; }
    </style>
</head>
<body>
<div class="header">
    <h1>Сыгранные бои</h1>
    <a href="{{ route('landing') }}">На главную</a>
</div>

<table class="table">
    <thead>
    <tr>
        <th>ID</th>
        <th>Игрок 1</th>
        <th>Игрок 2</th>
        <th>Статус</th>
        <th>Победитель</th>
        <th>Ход / Раунд</th>
        <th>Действия</th>
    </tr>
    </thead>
    <tbody>
    @forelse($games as $game)
        <tr>
            <td>#{{ $game->id }}</td>
            <td>{{ $game->player_1_name }}</td>
            <td>{{ $game->player_2_name }}</td>
            <td>{{ $game->status }}</td>
            <td>{{ $game->winner_name ?? '—' }}</td>
            <td>{{ $game->current_turn }} / {{ $game->round_number }}</td>
            <td>
                <div class="actions">
                    <a href="{{ route('game.replay', ['id' => $game->id]) }}">Открыть</a>
                    <a href="{{ route('game.watch', ['id' => $game->id]) }}">Live watch</a>
                </div>
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="7" class="muted">Пока нет боёв</td>
        </tr>
    @endforelse
    </tbody>
</table>

<div style="margin-top: 14px;">
    {{ $games->links() }}
</div>
</body>
</html>
