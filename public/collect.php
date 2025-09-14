<?php
declare(strict_types=1);

require __DIR__ . '/../src/config.php';
require_auth();

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$requestId = bin2hex(random_bytes(8));
header('X-Request-Id: '.$requestId);

function json_ok(array $data = []): void {
    http_response_code(200);
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}
function json_err(string $code, string $message, int $http = 400, array $extra = []): void {
    http_response_code($http);
    $payload = [
        'ok'        => false,
        'code'      => $code,
        'message'   => $message,
        'requestId' => $GLOBALS['requestId'] ?? null,
    ] + $extra;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    json_err('METHOD_NOT_ALLOWED', 'Только POST', 405);
}

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$csrfHeader)) {
    json_err('CSRF_FAILED', 'Не пройдена проверка безопасности. Обновите страницу и повторите.', 403);
}

try {
    $db   = db();
    $data = new \App\DataService($db);
    $data->ensureSeed();
    $api  = new \App\WordstatClient();
} catch (\Throwable $e) {
    error_log('[collect.php]['.$requestId.'] bootstrap failed: '.$e->getMessage());
    json_err('BOOTSTRAP_FAILED', 'Не удалось инициализировать сервисы', 500);
}

function buildRegionMap(\App\WordstatClient $client, array $targetNames): array {
    $flat = $client->flattenRegionsTree();
    $norm = static fn(string $s): string => preg_replace('/[^а-яa-z0-9]+/iu', '', mb_strtolower($s));

    $byNorm = [];
    foreach ($flat as $n) {
        $id   = $n['id'] ?? $n['regionId'] ?? $n['value'] ?? null;
        $name = (string)($n['name'] ?? $n['regionName'] ?? $n['label'] ?? '');
        if ($id === null || $name === '') continue;
        $byNorm[$norm($name)] = (int)$id;
    }

    $out = [];
    foreach ($targetNames as $name) {
        $k = $norm($name);
        if (isset($byNorm[$k])) { $out[$name] = $byNorm[$k]; continue; }

        $found = null;
        foreach ($flat as $n) {
            $id2 = $n['id'] ?? $n['regionId'] ?? $n['value'] ?? null;
            $nm2 = (string)($n['name'] ?? $n['regionName'] ?? $n['label'] ?? '');
            if ($id2 === null || $nm2 === '') continue;
            if (str_contains($norm($nm2), $k)) { $found = (int)$id2; break; }
        }
        if ($found === null) {
            error_log('[collect.php]['.$GLOBALS['requestId'].'] region not found in API: '.$name);
            throw new \RuntimeException('Не найден регион в API: '.$name);
        }
        $out[$name] = $found;
    }
    return $out;
}
function pickCount(array $p): int {
    return (int)($p['count'] ?? $p['value'] ?? $p['shows'] ?? $p['requests'] ?? $p['cnt'] ?? 0);
}
function doOneStep(array &$c, \App\WordstatClient $api, \App\DataService $data): array {
    $brands    = $c['brands'];
    $templates = $c['templates'];
    $regionMap = $c['regionMap'];
    $from      = $c['from'];
    $to        = $c['to'];
    $total     = (int)$c['total'];
    $i         = (int)$c['i'];

    if ($i >= $total) {
        $_SESSION['last_run'] = ['at'=>date('c'), 'count'=>$total];
        unset($_SESSION['collect']);
        return ['ok'=>true, 'finished'=>true, 'done'=>$total, 'total'=>$total];
    }

    $brand    = $brands[intdiv($i, count($templates))] ?? $brands[0];
    $template = $templates[$i % count($templates)] ?? $templates[0];
    $phrase   = str_replace(['[бренд]','[brand]','{brand}','{бренд}'], $brand, $template);

    $sumById = [];
    foreach ($regionMap as $rname => $rid) {
        $sum = 0;

        try {
            $resp   = $api->dynamics($phrase, 'daily', $from, $to, [$rid], ['all']);
            $series = $resp['series'] ?? $resp['days'] ?? $resp['items'] ?? $resp['data'] ?? [];
            if (is_array($series)) {
                foreach ($series as $p) $sum += pickCount((array)$p);
            }
        } catch (\Throwable $e) {
            error_log('[collect.php]['.$GLOBALS['requestId'].'] dynamics failed for "'.$phrase.'" rid='.$rid.': '.$e->getMessage());
        }

        if ($sum === 0) {
            try {
                $r    = $api->regions($phrase, 'cities', ['all']);
                $list = $r['regions'] ?? $r['items'] ?? (is_array($r) ? $r : []);
                foreach ($list as $it) {
                    $rid2 = (int)($it['regionId'] ?? $it['id'] ?? $it['value'] ?? 0);
                    if ($rid2 === $rid) { $sum = pickCount((array)$it); break; }
                }
            } catch (\Throwable $e) {
                error_log('[collect.php]['.$GLOBALS['requestId'].'] regions fallback failed for "'.$phrase.'" rid='.$rid.': '.$e->getMessage());
            }
        }

        try {
            $data->saveStat($rname, $brand, $phrase, $sumById[$rid] = ($sumById[$rid] ?? $sum));
        } catch (\Throwable $e) {
            error_log('[collect.php]['.$GLOBALS['requestId'].'] saveStat failed: '.$e->getMessage());
        }
    }

    $c['i'] = $i + 1;
    $finished = ($c['i'] >= $total);
    if ($finished) {
        $_SESSION['last_run'] = ['at'=>date('c'), 'count'=>$total];
        unset($_SESSION['collect']);
    }

    return [
        'ok'       => true,
        'finished' => $finished,
        'done'     => $finished ? $total : $c['i'],
        'total'    => $total,
        'phrase'   => $phrase,
        'brand'    => $brand,
    ];
}

try {
    if (empty($_SESSION['collect'])) {
        $regions   = array_column($data->getRegions(), 'name');
        $brands    = array_column($data->getBrands(), 'name');
        $templates = array_column($data->getPhraseTemplates(), 'template');

        if (!$regions)   { json_err('NO_REGIONS',   'Справочник регионов пуст. Проверьте таблицу regions.', 422); }
        if (!$brands)    { json_err('NO_BRANDS',    'Справочник брендов пуст. Проверьте таблицу brands.', 422); }
        if (!$templates) { json_err('NO_TEMPLATES', 'Справочник шаблонов фраз пуст. Проверьте таблицу phrases.', 422); }

        $regionMap = buildRegionMap($api, $regions);

        $data->clearStats();

        $_SESSION['collect'] = [
            'brands'    => $brands,
            'templates' => $templates,
            'regionMap' => $regionMap,
            'i'         => 0,
            'total'     => count($brands) * count($templates),
            'from'      => (new \DateTimeImmutable('-30 days'))->format('Y-m-d'),
            'to'        => (new \DateTimeImmutable('today'))->format('Y-m-d'),
        ];

        json_ok(['total' => $_SESSION['collect']['total'], 'resume' => false]);
    }

    $c = &$_SESSION['collect'];
    $res = doOneStep($c, $api, $data);
    json_ok($res);

} catch (\Throwable $e) {
    error_log('[collect.php]['.$requestId.'] fatal: '.$e->getMessage());
    json_err('UNEXPECTED', 'Непредвиденная ошибка на сервере', 500);
}
