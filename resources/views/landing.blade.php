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
        <input type="text" name="player_2_name" placeholder="Имя игрока 2" required>
        <button type="submit">Создать игру</button>
    </form>
</div>
</body>
</html>
