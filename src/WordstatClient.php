<?php
declare(strict_types=1);

namespace App;

final class WordstatClient
{
    private string $baseUrl;
    private string $token;
    private string $clientId;

    public function __construct(?string $baseUrl = null, ?string $token = null, ?string $clientId = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? ($_ENV['YW_BASE_URL'] ?? 'https://api.wordstat.yandex.net'), '/');

        $this->token = $token
            ?? $_ENV['YW_OAUTH_TOKEN']
            ?? $_ENV['YANDEX_OAUTH_TOKEN']
            ?? $_ENV['OAUTH_TOKEN']
            ?? '';

        $this->clientId = $clientId
            ?? $_ENV['YW_CLIENT_ID']
            ?? $_ENV['YANDEX_CLIENT_ID']
            ?? $_ENV['CLIENT_ID']
            ?? '';

        if ($this->token === '') {
            throw new \RuntimeException('Не задан OAuth-токен (YW_OAUTH_TOKEN / YANDEX_OAUTH_TOKEN / OAUTH_TOKEN).');
        }
        if ($this->clientId === '') {
            throw new \RuntimeException('Не задан Client-Id (YW_CLIENT_ID / YANDEX_CLIENT_ID / CLIENT_ID).');
        }
    }

    private function request(string $path, array|object $payload): array
    {
        $url = $this->baseUrl . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Client-Id: ' . $this->clientId, 
                'Content-Type: application/json; charset=utf-8',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 30,
        ]);

        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($res === false || $code >= 400) {
            throw new \RuntimeException("Wordstat API error ({$code}): " . ($res ?: $err));
        }

        $data = json_decode((string)$res, true);
        return is_array($data) ? $data : [];
    }

    private function normalizePeriod(string $p): string {
        return match (strtolower($p)) {
            'day','daily'     => 'daily',
            'week','weekly'   => 'weekly',
            'month','monthly' => 'monthly',
            default           => 'daily',
        };
    }

    public function getRegionsTree(): array
    {
        return $this->request('/v1/getRegionsTree', (object)[]);
    }

    public function flattenRegionsTree(): array
    {
        $tree = $this->getRegionsTree();
        $flat = [];
        $it = function(array $nodes) use (&$it,&$flat): void {
            foreach ($nodes as $n) {
                $id   = $n['id'] ?? $n['regionId'] ?? $n['value'] ?? null;
                $name = $n['name'] ?? $n['regionName'] ?? $n['label'] ?? null;
                if ($id !== null && $name !== null) $flat[] = ['id'=>(int)$id,'name'=>(string)$name];
                if (!empty($n['children']) && is_array($n['children'])) $it($n['children']);
            }
        };
        $it(is_array($tree) ? $tree : []);
        return $flat;
    }

    public function regions(string $phrase, string $regionType = 'cities', array $devices = ['all']): array
    {
        return $this->request('/v1/regions', [
            'phrase'     => $phrase,
            'regionType' => $regionType,
            'devices'    => $devices,
        ]);
    }

    public function dynamics(
        string $phrase,
        string $period,
        string $fromDate,
        ?string $toDate = null,
        ?array $regionsId = null,
        array $devices = ['all']
    ): array {
        $period  = $this->normalizePeriod($period);
        $payload = ['phrase'=>$phrase,'period'=>$period,'fromDate'=>$fromDate];
        if ($toDate !== null)    $payload['toDate']  = $toDate;
        if ($regionsId !== null) $payload['regions'] = array_values(array_map('intval', $regionsId));
        if ($devices)            $payload['devices'] = $devices;
        return $this->request('/v1/dynamics', $payload);
    }
}
