<?php
declare(strict_types=1);

namespace App;

final class WordstatClient
{
    private string $baseUrl;
    private string $token;
    private string $clientId;
    private int $connectTimeout = 5;   
    private int $timeout        = 20;  
    private int $maxRetries     = 3;   
    private int $retryBaseMs    = 500; 

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

    private function backoffSleep(int $attempt, ?int $retryAfterSec): void
    {
        if ($retryAfterSec !== null && $retryAfterSec > 0) {
            sleep(min($retryAfterSec, 10)); 
            return;
        }
        $ms = min($this->retryBaseMs * $attempt, 2000); 
        usleep($ms * 1000);
    }

    private function isRetryable(int $code): bool
    {
        return $code === 429 || ($code >= 500 && $code <= 599);
    }

    private function request(string $path, array|object $payload): array
    {
        $url = $this->baseUrl . $path;
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $lastErr  = null;
        $lastCode = 0;

        for ($attempt = 0; $attempt < $this->maxRetries; $attempt++) {
            $headersOut = [];
            $resp = $this->doCurl($url, $payloadJson, $headersOut);
            $lastCode = $resp['code'];
            $body     = $resp['body'];
            $retryAfter = $this->parseRetryAfter($headersOut);

            if ($lastCode < 200 || $lastCode >= 300) {
                $msg = $this->extractErrorMessage($body) ?? ('HTTP '.$lastCode);
                $lastErr = new \RuntimeException($msg, $lastCode);

                if ($this->isRetryable($lastCode) && $attempt + 1 < $this->maxRetries) {
                    $this->backoffSleep($attempt + 1, $retryAfter);
                    continue;
                }
                throw $lastErr;
            }

            $data = json_decode($body, true);
            if (!is_array($data)) {
                $lastErr = new \RuntimeException('Некорректный JSON от API (HTTP '.$lastCode.'): '. $this->trimForLog($body));
                if ($attempt + 1 < $this->maxRetries) {
                    $this->backoffSleep($attempt + 1, null);
                    continue;
                }
                throw $lastErr;
            }

            return $data;
        }

        throw ($lastErr ?? new \RuntimeException('Запрос к API не удался'));
    }

    private function doCurl(string $url, string $payloadJson, array &$headersOut): array
    {
        $ch = curl_init($url);

        $receivedHeaders = [];
        $headerFn = static function ($ch, string $header) use (&$receivedHeaders) {
            $line = trim($header);
            if ($line !== '') $receivedHeaders[] = $line;
            return strlen($header);
        };

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Client-Id: ' . $this->clientId,
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json',
                'Accept-Encoding: gzip',
                'User-Agent: WordstatClient/1.0',
            ],
            CURLOPT_POSTFIELDS     => $payloadJson,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_ENCODING       => '', 
            CURLOPT_HEADERFUNCTION => $headerFn,
        ]);

        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body === false) {
            $err = curl_error($ch) ?: 'curl_exec failed';
            curl_close($ch);
            return ['code' => 0, 'body' => $err, 'headers' => []];
        }

        curl_close($ch);
        $headersOut = $receivedHeaders;

        return ['code' => $code, 'body' => (string)$body, 'headers' => $receivedHeaders];
    }

    private function parseRetryAfter(array $headers): ?int
    {
        foreach ($headers as $h) {
            if (stripos($h, 'Retry-After:') === 0) {
                $v = trim(substr($h, strlen('Retry-After:')));
                if (ctype_digit($v)) return (int)$v;
            }
        }
        return null;
    }

    private function extractErrorMessage(string $body): ?string
    {
        $data = json_decode($body, true);
        if (is_array($data)) {
            if (isset($data['error']['message'])) return (string)$data['error']['message'];
            if (isset($data['message'])) return (string)$data['message'];
            if (isset($data['error'])) return is_string($data['error']) ? $data['error'] : json_encode($data['error'], JSON_UNESCAPED_UNICODE);
        }
        $short = $this->trimForLog($body);
        return $short !== '' ? $short : null;
    }

    private function trimForLog(string $s, int $max = 400): string
    {
        $s = preg_replace('/[^\P{C}\n\t]+/u', '', $s ?? ''); 
        $s = trim($s);
        return mb_strimwidth($s, 0, $max, '…', 'UTF-8');
    }

    private function normalizePeriod(string $p): string
    {
        return match (strtolower($p)) {
            'day','daily'     => 'daily',
            'week','weekly'   => 'weekly',
            'month','monthly' => 'monthly',
            default           => 'daily',
        };
    }

    private function normalizeDevices(array $devices): array
    {
        $allow = ['all','desktop','mobile','tablet'];
        $out = [];
        foreach ($devices as $d) {
            $d = strtolower((string)$d);
            if (in_array($d, $allow, true)) $out[] = $d;
        }
        return $out ?: ['all'];
    }

    private function normalizeRegionType(string $t): string
    {
        $t = strtolower($t);
        return in_array($t, ['cities','regions','federal','all'], true) ? $t : 'cities';
    }


    public function getRegionsTree(): array
    {
        $data = $this->request('/v1/getRegionsTree', (object)[]);
        if (isset($data['items']) && is_array($data['items'])) return $data['items'];
        if (isset($data['data'])  && is_array($data['data']))  return $data['data'];
        if (isset($data['tree'])  && is_array($data['tree']))  return $data['tree'];
        return is_array($data) ? $data : [];
    }

    public function flattenRegionsTree(): array
    {
        $tree = $this->getRegionsTree();
        $flat = [];

        $walk = function(array $nodes) use (&$walk, &$flat): void {
            foreach ($nodes as $n) {
                $id   = $n['id'] ?? $n['regionId'] ?? $n['value'] ?? null;
                $name = $n['name'] ?? $n['regionName'] ?? $n['label'] ?? null;
                if ($id !== null && $name !== null) {
                    $flat[] = ['id' => (int)$id, 'name' => (string)$name];
                }
                if (!empty($n['children']) && is_array($n['children'])) {
                    $walk($n['children']);
                }
            }
        };

        $walk(is_array($tree) ? $tree : []);
        return $flat;
    }

    public function regions(string $phrase, string $regionType = 'cities', array $devices = ['all']): array
    {
        $payload = [
            'phrase'     => (string)$phrase,
            'regionType' => $this->normalizeRegionType($regionType),
            'devices'    => $this->normalizeDevices($devices),
        ];
        return $this->request('/v1/regions', $payload);
    }

    public function dynamics(
        string $phrase,
        string $period,
        string $fromDate,
        ?string $toDate = null,
        ?array $regionsId = null,
        array $devices = ['all']
    ): array {
        $payload = [
            'phrase'  => (string)$phrase,
            'period'  => $this->normalizePeriod($period),
            'fromDate'=> $fromDate,
        ];
        if ($toDate !== null)    $payload['toDate']  = $toDate;
        if ($regionsId !== null) $payload['regions'] = array_values(array_map('intval', $regionsId));
        $payload['devices'] = $this->normalizeDevices($devices);

        return $this->request('/v1/dynamics', $payload);
    }
}
