# Battle Grid

## Установка из Git и запуск в Docker

Требуются Docker Engine, Docker Compose v2 и включённый BuildKit. Исходный код при сборке скачивается из публичной ветки `master` репозитория `https://github.com/Dead-Master/game.git`.

На чистом сервере достаточно скачать Compose-файл и запустить сборку:

```bash
mkdir -p battle-grid && cd battle-grid
curl -fsSLO https://raw.githubusercontent.com/Dead-Master/game/master/compose.yaml
docker compose up --build -d
```

Из уже клонированного рабочего каталога команда та же:

```bash
docker compose up --build -d
```

Пока Docker-файлы ещё не отправлены в Git, локальную версию можно собрать так:

```bash
DOCKER_BUILD_CONTEXT=. docker compose up --build -d
```

После запуска:

- игра: <http://localhost:8080>;
- bot-service: <http://localhost:8090> (служебный API, обычный `GET` вернёт 404);
- MariaDB 12.3 на хосте: `127.0.0.1:3307`;
- актуальная стабильная Redis 8 доступна контейнерам во внутренней Docker-сети.

Миграции выполняются автоматически при старте контейнера `app`. Сессии, кэш и очередь Laravel используют Redis; обработчик очереди и bot-service запускаются отдельными контейнерами.

Проверить состояние и посмотреть логи:

```bash
docker compose ps
docker compose logs -f app nginx queue bot
```

Запустить тесты:

```bash
docker compose exec app php artisan test
```

Остановить проект:

```bash
docker compose down
```

База и файлы хранилища сохраняются в Docker volumes. Чтобы удалить и их тоже, используйте `docker compose down -v` — это безвозвратно удалит данные Docker-окружения проекта.

Git-контекст, порты, реквизиты базы и стратегию бота можно переопределить переменными `DOCKER_*` из [.env.docker.example](.env.docker.example). Например, для сборки конкретного коммита задайте `DOCKER_BUILD_CONTEXT=https://github.com/Dead-Master/game.git#<commit-sha>`. Docker Compose автоматически читает файл `.env`, поэтому нужные `DOCKER_*` можно добавить в него или передать перед командой запуска.

> В текущей конфигурации broadcasting пишет события в лог. Для обновления состояния игры между двумя открытыми браузерами потребуется обновление страницы; подключение WebSocket-сервера настраивается отдельно.

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
