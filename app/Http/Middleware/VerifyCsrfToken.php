<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * Исключаем все маршруты с префиксом /api из проверки CSRF.
     */
    protected $except = [
        'api/*',
    ];
}
