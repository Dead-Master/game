<?php

declare(strict_types=1);

namespace BotService;

final class GameApiClient
{
    public function __construct(
        private string $baseUrl,
        private ?string $apiToken = null,
    ) {
    }

    public function getPendingBotTurns(string $side, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $side = in_array($side, ['player_1', 'player_2'], true) ? $side : 'player_2';

        return $this->request('GET', "/api/bot/pending-turns?limit={$limit}&side={$side}");
    }

    public function getState(int $gameId): array
    {
        return $this->request('GET', "/api/games/{$gameId}");
    }

    public function deployCard(int $gameId, string $side, string $type, int $x, int $y): array
    {
        return $this->request('POST', "/api/games/{$gameId}/deploy-card", [
            'side' => $side,
            'type' => $type,
            'cell_x' => $x,
            'cell_y' => $y,
        ]);
    }

    public function moveUnit(int $gameId, string $side, int $unitId, int $x, int $y): array
    {
        return $this->request('POST', "/api/games/{$gameId}/move-unit", [
            'side' => $side,
            'unit_id' => $unitId,
            'x' => $x,
            'y' => $y,
        ]);
    }

    public function attackUnit(int $gameId, string $side, int $attackerUnitId, int $targetUnitId): array
    {
        return $this->request('POST', "/api/games/{$gameId}/attack-unit", [
            'side' => $side,
            'attacker_unit_id' => $attackerUnitId,
            'target_unit_id' => $targetUnitId,
        ]);
    }

    public function attackBaseWithUnit(int $gameId, string $side, int $attackerUnitId, string $targetSide): array
    {
        return $this->request('POST', "/api/games/{$gameId}/attack-base", [
            'side' => $side,
            'attacker_unit_id' => $attackerUnitId,
            'target_side' => $targetSide,
        ]);
    }

    public function attackUnitWithBase(int $gameId, string $side, int $targetUnitId): array
    {
        return $this->request('POST', "/api/games/{$gameId}/attack-base", [
            'side' => $side,
            'target_unit_id' => $targetUnitId,
        ]);
    }

    public function attackBaseWithBase(int $gameId, string $side, string $targetSide): array
    {
        return $this->request('POST', "/api/games/{$gameId}/attack-base", [
            'side' => $side,
            'target_side' => $targetSide,
        ]);
    }

    public function endTurn(int $gameId, string $side): array
    {
        return $this->request('POST', "/api/games/{$gameId}/end-turn", [
            'side' => $side,
        ]);
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        if ($this->apiToken !== null && $this->apiToken !== '') {
            $headers[] = 'X-Bot-Service-Token: ' . $this->apiToken;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $error !== '') {
            throw new \RuntimeException('HTTP request failed: ' . $error);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON response: ' . $raw);
        }

        $decoded['_http_code'] = $httpCode;
        return $decoded;
    }
}
