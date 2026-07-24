<?php

declare(strict_types=1);

/**
 * Тюнер весов для ai_agent_v2 / ai_agent_v3.
 *
 * Каждый trial играет ДВЕ серии: тюнингуемый бот за P1 и за P2 (зеркально),
 * fitness считается по суммарным результатам — это убирает перекос сторон.
 *
 * Запуск:
 * php bot-service/bin/tune-ai-agent-v3.php
 *
 * Опциональные env:
 * AI_TUNE_STRATEGY=ai_agent_v3      # ai_agent_v2 | ai_agent_v3
 * AI_TUNE_MODE=explore              # explore (random search) | refine (мутации вокруг базы)
 * AI_TUNE_JITTER=0.15               # разброс мутаций в режиме refine (доля от значения)
 * AI_TUNE_TRIALS=30
 * AI_TUNE_BATTLES=60                # боёв НА КАЖДУЮ сторону (итого x2 на trial)
 * AI_TUNE_OPPONENT=codex_v1
 * AI_TUNE_PARALLEL=2                # параллельных trial-ов (процессов будет x2)
 * AI_TUNE_WEIGHTS_FILE=/path/to/base-weights.json   # может быть best-файлом тюнера
 * AI_BATTLE_BASE_URL=http://127.0.0.1:8000
 * AI_BATTLE_TOKEN=bot-service-token
 */

$projectRoot = dirname(__DIR__, 2);
$runner = $projectRoot . '/bot-service/bin/run-battle-series.php';
$resultsDir = $projectRoot . '/storage/app/ai-tuning';

if (!is_dir($resultsDir)) {
    mkdir($resultsDir, 0777, true);
}

$tunedStrategy = (string) (getenv('AI_TUNE_STRATEGY') ?: 'ai_agent_v3');
if (!in_array($tunedStrategy, ['ai_agent_v2', 'ai_agent_v3'], true)) {
    throw new RuntimeException("Unsupported AI_TUNE_STRATEGY '{$tunedStrategy}'.");
}

$mode = (string) (getenv('AI_TUNE_MODE') ?: 'explore');
if (!in_array($mode, ['explore', 'refine'], true)) {
    throw new RuntimeException("Unsupported AI_TUNE_MODE '{$mode}'. Use explore or refine.");
}

$jitter = max(0.01, min(1.0, (float) (getenv('AI_TUNE_JITTER') ?: 0.15)));
$trials = max(1, (int) (getenv('AI_TUNE_TRIALS') ?: 20));
$battles = max(1, (int) (getenv('AI_TUNE_BATTLES') ?: 60));
$opponent = (string) (getenv('AI_TUNE_OPPONENT') ?: 'codex_v1');
$parallel = max(1, (int) (getenv('AI_TUNE_PARALLEL') ?: 1));

$weightsEnvKey = $tunedStrategy === 'ai_agent_v3'
    ? 'AI_AGENT_V3_WEIGHTS_JSON'
    : 'AI_AGENT_V2_WEIGHTS_JSON';

if ($tunedStrategy === $opponent) {
    echo "WARNING: opponent equals tuned strategy — weights will leak into the opponent.\n";
}

$weightsBaseFile = (string) (getenv('AI_TUNE_WEIGHTS_FILE') ?: '');
if ($weightsBaseFile === '') {
    $strategyDefault = $projectRoot . '/bot-service/config/' . str_replace('_', '-', $tunedStrategy) . '.default-weights.json';
    $weightsBaseFile = is_file($strategyDefault)
        ? $strategyDefault
        : $projectRoot . '/bot-service/config/ai-agent-v2.default-weights.json';
}

echo "=== AI AGENT TUNING START ===\n";
echo "Tuned strategy: {$tunedStrategy}\n";
echo "Mode: {$mode}" . ($mode === 'refine' ? " (jitter={$jitter})" : '') . "\n";
echo "Trials: {$trials}\n";
echo "Battles per side: {$battles} (x2 per trial)\n";
echo "Opponent: {$opponent}\n";
echo "Parallel trials: {$parallel}\n";
echo "Base weights file: {$weightsBaseFile}\n";
echo "================================\n\n";
if (function_exists('ob_implicit_flush')) {
    ob_implicit_flush(true);
}
flush();

$baseRaw = file_get_contents($weightsBaseFile);
if (!is_string($baseRaw) || $baseRaw === '') {
    throw new RuntimeException('Unable to read base weights file.');
}

$baseWeights = json_decode($baseRaw, true);
if (!is_array($baseWeights)) {
    throw new RuntimeException('Invalid JSON in base weights file.');
}

// Поддержка best-файла тюнера как базы для refine.
if (isset($baseWeights['weights']) && is_array($baseWeights['weights'])) {
    $baseWeights = $baseWeights['weights'];
}

$filePrefix = str_replace('_', '-', $tunedStrategy);
$csvPath = $resultsDir . '/' . $filePrefix . '-tuning-' . date('Ymd-His') . '.csv';
$csv = fopen($csvPath, 'wb');
if ($csv === false) {
    throw new RuntimeException('Unable to create CSV.');
}

fputcsv($csv, ['trial', 'fitness', 'tuned_wins', 'opponent_wins', 'draws', 'errors', 'weights_json']);

$best = null;

$nextTrial = 1;
$completedTrials = 0;
/** @var array<int, array<string, mixed>> $trialsInFlight */
$trialsInFlight = [];

while ($completedTrials < $trials) {
    while (count($trialsInFlight) < $parallel && $nextTrial <= $trials) {
        $trial = $nextTrial++;
        $weights = $mode === 'refine'
            ? refineWeights($baseWeights, $jitter)
            : exploreWeights($baseWeights, $tunedStrategy);

        $jobs = [];
        foreach (['as_p1', 'as_p2'] as $role) {
            $isP1 = $role === 'as_p1';

            $env = array_merge($_ENV, [
                'AI_BATTLES_COUNT' => (string) $battles,
                'AI_MAX_TURNS' => '300',
                'AI_P1_STRATEGY' => $isP1 ? $tunedStrategy : $opponent,
                'AI_P2_STRATEGY' => $isP1 ? $opponent : $tunedStrategy,
                'AI_P1_NAME' => $isP1 ? $tunedStrategy : $opponent,
                'AI_P2_NAME' => $isP1 ? $opponent : $tunedStrategy,
                'AI_VERBOSE' => '0',
                $weightsEnvKey => json_encode($weights, JSON_UNESCAPED_UNICODE),
            ]);

            foreach (['AI_AGENT_V2_WEIGHTS_JSON', 'AI_AGENT_V3_WEIGHTS_JSON', 'AI_AGENT_V2_WEIGHTS_FILE', 'AI_AGENT_V3_WEIGHTS_FILE'] as $k) {
                if ($k !== $weightsEnvKey) {
                    unset($env[$k]);
                }
            }

            foreach (['AI_BATTLE_BASE_URL', 'AI_BATTLE_TOKEN'] as $k) {
                $v = getenv($k);
                if ($v !== false && $v !== '') {
                    $env[$k] = $v;
                }
            }

            $jobs[$role] = startTrialProcess(['php', $runner], $projectRoot, $env, $trial, $weights);
        }

        $trialsInFlight[$trial] = [
            'jobs' => $jobs,
            'weights' => $weights,
            'results' => [],
        ];

        echo "[trial {$trial}/{$trials}] started (running trials: " . count($trialsInFlight) . "/{$parallel})\n";
        flush();
    }

    foreach (array_keys($trialsInFlight) as $trial) {
        foreach ($trialsInFlight[$trial]['jobs'] as $role => &$job) {
            if (isset($trialsInFlight[$trial]['results'][$role])) {
                continue;
            }

            $state = pollTrialProcess($job, false);
            if (($state['running'] ?? true) === true) {
                continue;
            }

            if ((int) ($state['exit_code'] ?? 1) !== 0) {
                throw new RuntimeException("Trial {$trial} ({$role}) failed:\n" . (string) ($state['output'] ?? ''));
            }

            $trialsInFlight[$trial]['results'][$role] = parseSeriesMetrics((string) ($state['output'] ?? ''));
        }
        unset($job);

        if (count($trialsInFlight[$trial]['results']) < 2) {
            continue;
        }

        $completedTrials++;
        $weights = $trialsInFlight[$trial]['weights'];
        $r1 = $trialsInFlight[$trial]['results']['as_p1'];
        $r2 = $trialsInFlight[$trial]['results']['as_p2'];
        unset($trialsInFlight[$trial]);

        // В as_p1 победы тюнингуемого — p1_wins, в as_p2 — p2_wins.
        $metrics = [
            'tuned_wins' => $r1['p1_wins'] + $r2['p2_wins'],
            'opponent_wins' => $r1['p2_wins'] + $r2['p1_wins'],
            'draws' => $r1['draws'] + $r2['draws'],
            'errors' => $r1['errors'] + $r2['errors'],
        ];

        $fitness = calcFitness($metrics);

        fputcsv($csv, [
            $trial,
            sprintf('%.6f', $fitness),
            $metrics['tuned_wins'],
            $metrics['opponent_wins'],
            $metrics['draws'],
            $metrics['errors'],
            json_encode($weights, JSON_UNESCAPED_UNICODE),
        ]);

        if ($best === null || $fitness > $best['fitness']) {
            $best = ['fitness' => $fitness, 'metrics' => $metrics, 'weights' => $weights];
        }

        echo "[trial {$trial}/{$trials}] fitness=" . number_format($fitness, 4)
            . " W/L/D/E={$metrics['tuned_wins']}/{$metrics['opponent_wins']}/{$metrics['draws']}/{$metrics['errors']}"
            . " | best=" . number_format((float) ($best['fitness'] ?? 0), 4)
            . " | progress={$completedTrials}/{$trials}\n";
        flush();
    }

    if ($completedTrials < $trials) {
        usleep(120000);
    }
}

fclose($csv);

if ($best === null) {
    throw new RuntimeException('No best result produced.');
}

$bestPath = $resultsDir . '/' . $filePrefix . '-best-' . date('Ymd-His') . '.json';
file_put_contents($bestPath, json_encode([
    'strategy' => $tunedStrategy,
    'opponent' => $opponent,
    'mode' => $mode,
    'fitness' => $best['fitness'],
    'metrics' => $best['metrics'],
    'weights' => $best['weights'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n=== TUNING DONE ===\n";
echo "CSV: {$csvPath}\n";
echo "BEST: {$bestPath}\n";
echo "Best fitness: " . number_format((float) $best['fitness'], 6) . "\n";

/**
 * Глобальные диапазоны параметров.
 *
 * @return array<string, array{0: float, 1: float}>
 */
function weightRanges(): array
{
    return [
        'lookahead_alpha' => [0.20, 0.85],
        'future_eval_scale' => [0.70, 1.40],
        'scale_attack_unit' => [0.75, 2.00],
        'scale_attack_base_with_unit' => [0.75, 1.60],
        'scale_attack_with_base' => [0.70, 1.40],
        'scale_attack_base_with_base' => [0.70, 1.40],
        'scale_deploy' => [0.40, 1.50],
        'scale_move' => [0.60, 1.50],
        'eval_base_hp_weight' => [2.5, 9.0],
        'eval_unit_hp_weight' => [0.4, 3.0],
        'eval_unit_attack_weight' => [0.5, 3.5],
        'eval_tempo_weight' => [0.1, 2.5],
        'min_action_score' => [0.5, 25.0],
        'deploy_distance_weight' => [0.0, 5.0],
        'deploy_archer_forward_scale' => [-1.0, 1.5],
    ];
}

/**
 * Полностью случайные веса в пределах диапазонов (разведка).
 *
 * @param array<string, mixed> $weights
 * @return array<string, mixed>
 */
function exploreWeights(array $weights, string $strategy): array
{
    foreach (weightRanges() as $key => [$min, $max]) {
        if ($key === 'min_action_score' && $strategy !== 'ai_agent_v3') {
            continue;
        }

        $weights[$key] = randomFloat($min, $max);
    }

    return $weights;
}

/**
 * Мутация вокруг базовых значений (уточнение), с клампом в глобальные диапазоны.
 *
 * @param array<string, mixed> $weights
 * @return array<string, mixed>
 */
function refineWeights(array $weights, float $jitter): array
{
    foreach (weightRanges() as $key => [$min, $max]) {
        $base = isset($weights[$key]) && is_numeric($weights[$key])
            ? (float) $weights[$key]
            : ($min + $max) / 2.0;

        $delta = $base * $jitter;
        $value = randomFloat($base - $delta, $base + $delta);

        $weights[$key] = max($min, min($max, $value));
    }

    return $weights;
}

function randomFloat(float $min, float $max): float
{
    return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
}

/**
 * @return array{p1_wins:int,p2_wins:int,draws:int,errors:int}
 */
function parseSeriesMetrics(string $output): array
{
    preg_match_all('/wins:\s+(\d+)/', $output, $wins);
    preg_match('/Draws:\s+(\d+)/', $output, $draws);
    preg_match('/Errors:\s+(\d+)/', $output, $errors);

    return [
        'p1_wins' => isset($wins[1][0]) ? (int) $wins[1][0] : 0,
        'p2_wins' => isset($wins[1][1]) ? (int) $wins[1][1] : 0,
        'draws' => isset($draws[1]) ? (int) $draws[1] : 0,
        'errors' => isset($errors[1]) ? (int) $errors[1] : 0,
    ];
}

/**
 * @param array{tuned_wins:int,opponent_wins:int,draws:int,errors:int} $m
 */
function calcFitness(array $m): float
{
    $total = max(1, $m['tuned_wins'] + $m['opponent_wins'] + $m['draws'] + $m['errors']);
    $winRate = $m['tuned_wins'] / $total;
    $errorRate = $m['errors'] / $total;
    $drawRate = $m['draws'] / $total;

    return $winRate - (0.40 * $errorRate) - (0.05 * $drawRate);
}

/**
 * @param array<int, string> $command
 * @param array<string, string> $env
 * @param array<string, mixed> $weights
 * @return array<string, mixed>
 */
function startTrialProcess(array $command, string $cwd, array $env, int $trial, array $weights): array
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptor, $pipes, $cwd, $env);
    if (!is_resource($process)) {
        throw new RuntimeException("proc_open failed for trial {$trial}.");
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    return [
        'trial' => $trial,
        'process' => $process,
        'pipes' => $pipes,
        'stdout' => '',
        'stderr' => '',
        'weights' => $weights,
    ];
}

/**
 * @param array<string, mixed> $job
 * @return array{running:bool,exit_code?:int,output?:string}
 */
function pollTrialProcess(array &$job, bool $streamOutput): array
{
    /** @var resource $process */
    $process = $job['process'];
    /** @var array<int, resource> $pipes */
    $pipes = $job['pipes'];
    $trial = (int) $job['trial'];

    foreach ([1 => 'stdout', 2 => 'stderr'] as $fd => $buf) {
        $chunk = stream_get_contents($pipes[$fd]);
        if (is_string($chunk) && $chunk !== '') {
            $job[$buf] .= $chunk;
            if ($streamOutput) {
                echo formatStreamChunk($chunk, "trial-{$trial}" . ($fd === 2 ? '-err' : ''));
                flush();
            }
        }
    }

    $status = proc_get_status($process);
    if ((bool) ($status['running'] ?? false)) {
        return ['running' => true];
    }

    foreach ([1 => 'stdout', 2 => 'stderr'] as $fd => $buf) {
        $chunk = stream_get_contents($pipes[$fd]);
        if (is_string($chunk) && $chunk !== '') {
            $job[$buf] .= $chunk;
        }
        fclose($pipes[$fd]);
    }

    $exitCode = proc_close($process);

    return [
        'running' => false,
        'exit_code' => $exitCode,
        'output' => (string) $job['stdout'] . "\n" . (string) $job['stderr'],
    ];
}

function formatStreamChunk(string $chunk, string $prefix): string
{
    $lines = preg_split('/\R/', $chunk);
    if (!is_array($lines)) {
        return "[{$prefix}] {$chunk}";
    }

    $out = '';
    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }

        $out .= "[{$prefix}] {$line}\n";
    }

    return $out;
}
