<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/GameApiClient.php';
require_once __DIR__ . '/../src/BotRunner.php';
require_once __DIR__ . '/../src/StrategyFactory.php';
require_once __DIR__ . '/../Contracts/BotStrategyInterface.php';
require_once __DIR__ . '/../Strategies/AIAgentV3BotStrategy.php';
require_once __DIR__ . '/../Strategies/ScriptedBotStrategy.php';


use BotService\BotRunner;
use BotService\GameApiClient;
use BotService\StrategyFactory;

function envOr(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$query = [];
parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? '', $query);

if ($method !== 'POST' || $path !== '/api/bot/play-turn') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$expectedToken = envOr('BOT_SERVICE_TOKEN', '');
$providedToken = $_SERVER['HTTP_X_BOT_SERVICE_TOKEN'] ?? '';

if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '{}', true);

$gameId = (int) ($payload['game_id'] ?? 0);
$side = (string) ($payload['side'] ?? 'player_2');
$strategyName = (string) ($query['strategy'] ?? envOr('BOT_STRATEGY', 'scripted_p2'));

if ($gameId <= 0 || !in_array($side, ['player_1', 'player_2'], true)) {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid payload'], JSON_UNESCAPED_UNICODE);
    exit;
}

$gameApiBaseUrl = envOr('GAME_API_BASE_URL', 'http://127.0.0.1:8000');
$gameApiToken = envOr('GAME_API_TOKEN', null);

$client = new GameApiClient($gameApiBaseUrl, $gameApiToken);

try {
    $strategy = StrategyFactory::make($strategyName);
    $runner = new BotRunner($client, $strategy);
    $result = $runner->playTurn($gameId, $side);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'strategy' => $strategy->name(),
        'result' => $result,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Bot execution failed',
        'details' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
