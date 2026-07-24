<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Лэндинг</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="landing-container">
    <h1>Добро пожаловать в игру!</h1>

    <form action="{{ route('game.create') }}" method="POST">
        @csrf
        <input type="text" name="player_1_name" placeholder="Имя игрока 1" required>
        <input type="text" name="player_2_name" placeholder="Имя игрока 2 (бот)" value="Bot" required>

        <label for="bot_strategy">Стратегия бота:</label>
        <select id="bot_strategy" name="bot_strategy">
            <option value="ai_agent_v3_release">ai_agent_v3_release</option>
            <option value="ai_agent_v3">ai_agent_v3</option>
            <option value="focus_base">focus_base</option>
            <option value="scripted">scripted</option>
            <option value="codex_v2">codex_v2</option>
            <option value="codex_v1">codex_v1</option>
        </select>

        <button type="submit">Создать игру</button>
    </form>
</div>
</body>
</html>
