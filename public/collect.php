<?php
declare(strict_types=1);

require __DIR__ . '/../src/config.php';
require_auth();

$db   = db();
$data = new \App\DataService($db);
$data->ensureSeed();

$api = new \App\WordstatClient();

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
        if ($found === null) throw new \RuntimeException('Не найден регион в API: '.$name);
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
        }

        if ($sum === 0) {
            try {
                $r    = $api->regions($phrase, 'cities', ['all']);
                $list = $r['regions'] ?? $r['items'] ?? (is_array($r) ? $r : []);
                foreach ($list as $it) {
                    $rid2 = (int)($it['regionId'] ?? $it['id'] ?? $it['value'] ?? 0);
                    if ($rid2 === $rid) { $sum = pickCount((array)$it); break; }
                }
            } catch (\Throwable $e) {}
        }

        $sumById[$rid] = $sum;
    }

    foreach ($regionMap as $rname => $rid) {
        $data->saveStat($rname, $brand, $phrase, $sumById[$rid] ?? 0);
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

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['collect'])) {
    $regions   = array_column($data->getRegions(), 'name');
    $regionMap = buildRegionMap($api, $regions);
    $brands    = array_column($data->getBrands(), 'name');
    $templates = array_column($data->getPhraseTemplates(), 'template');

    $data->clearStats();

    $_SESSION['collect'] = [
        'brands'    => $brands,
        'templates' => $templates,
        'regionMap' => $regionMap,
        'i'         => 0,
        'total'     => count($brands) * count($templates),
        'from'      => (new DateTimeImmutable('-30 days'))->format('Y-m-d'),
        'to'        => (new DateTimeImmutable('today'))->format('Y-m-d'),
    ];

    echo json_encode(['ok'=>true, 'total'=>$_SESSION['collect']['total'], 'resume'=>false], JSON_UNESCAPED_UNICODE);
    exit;
}

$c = &$_SESSION['collect'];
echo json_encode(doOneStep($c, $api, $data), JSON_UNESCAPED_UNICODE);
