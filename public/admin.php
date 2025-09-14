<?php
declare(strict_types=1);
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/../src/config.php';
require_auth();

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'] ?? '';

// --- build_url как и раньше ---
function build_url(array $extra = []): string {
    $base = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $q    = array_merge($_GET, $extra);
    foreach ($q as $k => $v) if ($v === '' || $v === null) unset($q[$k]);
    return $base . ($q ? ('?' . http_build_query($q)) : '');
}

$fatalError = null;

$brand     = '';
$region    = '';
$q         = '';
$page      = 1;
$perPage   = 20;
$totalPages= 1;
$stats     = [];
$brands    = [];
$regions   = [];
$lastRun   = '—';

try {
    $pdo = db();

    $brand    = trim((string)($_GET['brand']   ?? ''));
    $region   = trim((string)($_GET['region']  ?? ''));
    $q        = trim((string)($_GET['q']       ?? ''));
    $page     = max(1, (int)($_GET['page']     ?? 1));

    $perPage  = (int)($_GET['per_page'] ?? 20);
    if     ($perPage < 10)  $perPage = 10;
    elseif ($perPage > 100) $perPage = 100;
    $offset = ($page - 1) * $perPage;

    try {
        $brands  = $pdo->query("SELECT name FROM brands ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (\Throwable $e) { $brands = []; }

    try {
        $regions = $pdo->query("SELECT name FROM regions ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (\Throwable $e) { $regions = []; }

    $where  = [];
    $params = [];
    if ($brand !== '')  { $where[] = 'brand = :brand';   $params[':brand']  = $brand; }
    if ($region !== '') { $where[] = 'region = :region'; $params[':region'] = $region; }
    if ($q !== '')      { $where[] = 'query ILIKE :q';   $params[':q']      = '%'.$q.'%'; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $totalRows = 0;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM stats $whereSql");
        $stmt->execute($params);
        $totalRows  = (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        $totalRows = 0;
    }

    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    try {
        $sql = "SELECT region, brand, query, query_count, created_at
                FROM stats
                $whereSql
                ORDER BY created_at DESC, id DESC
                LIMIT $perPage OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $stats = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $stats = [];
    }

    try {
        $lastRunRaw = (string)($pdo->query("SELECT MAX(created_at) FROM stats")->fetchColumn() ?: '');
        if ($lastRunRaw !== '') {
            $d = DateTime::createFromFormat('Y-m-d H:i:s.u', $lastRunRaw) ?: new DateTime($lastRunRaw);
            $lastRun = $d->format('d.m.Y H:i');
        }
    } catch (\Throwable $e) {
        $lastRun = '—';
    }
} catch (\Throwable $e) {
    $fatalError = 'Не удалось подготовить данные страницы. Попробуйте обновить страницу.';
}

?><!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Админка — Я.Вордстат</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= asset_url('assets/admin.css') ?>">
</head>
<body>
<div class="wrap">
  <div class="card">

    <?php if ($fatalError): ?>
      <div class="notice">
        <?= htmlspecialchars($fatalError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <div class="cap">
      <div><strong>Админка — Я.Вордстат</strong></div>
      <div class="right">
        <?php $btnDisabled = $fatalError ? 'disabled' : ''; ?>
        <button class="btn" id="btnCollect" type="button" title="Собрать статистику" <?= $btnDisabled ?>>
          <svg aria-hidden="true" viewBox="0 0 24 24" width="16" height="16"
               fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 12a9 9 0 0 1 15.36-6.36"/>
            <path d="M18 3v5h-5"/>
            <path d="M21 12a9 9 0 0 1-15.36 6.36"/>
            <path d="M6 21v-5h5"/>
          </svg>
          <span>Собрать статистику</span>
        </button>

        <a class="btn" id="btnExport"
           href="<?= '/export.php' . (empty($_GET) ? '' : '?' . http_build_query($_GET)) ?>"
           title="Экспортировать текущую выборку" <?= $fatalError ? 'aria-disabled="true" style="pointer-events:none;opacity:.55;"' : '' ?>>
          <svg aria-hidden="true" viewBox="0 0 24 24" width="16" height="16"
               fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
            <path d="M12 12v5"/>
            <path d="M9.5 14.5L12 17l2.5-2.5"/>
          </svg>
          <span>Экспорт в Excel</span>
        </a>
        <form action="/logout.php" method="post" style="display:inline;">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <button class="btn" type="submit" title="Выйти">
          <svg aria-hidden="true" viewBox="0 0 24 24" width="16" height="16"
              fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
              <path d="M16 17l5-5-5-5"/>
              <path d="M21 12H9"/>
            </svg>
            <span>Выйти</span>
          </button>
        </form>
      </div>
    </div>

    <div class="prog"><div class="bar" id="bar"></div></div>
    <div id="status" class="status muted">Готов к работе</div>

    <div class="muted" style="margin-top:6px;">
      Последний сбор статистики был произведён:
      <b id="lastRun"><?= htmlspecialchars($lastRun, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></b>
    </div>

    <form method="get" class="row filters">
      <div class="control">
        <div class="muted" style="font-size:12px;">Бренд</div>
        <select name="brand" style="min-width:180px" <?= $fatalError ? 'disabled' : '' ?>>
          <option value="">— все бренды —</option>
          <?php foreach ($brands as $b): ?>
            <option value="<?= htmlspecialchars($b, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $b===$brand ? 'selected' : '' ?>>
              <?= htmlspecialchars($b, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="control">
        <div class="muted" style="font-size:12px;">Город / Регион</div>
        <select name="region" style="min-width:200px" <?= $fatalError ? 'disabled' : '' ?>>
          <option value="">— все города —</option>
          <?php foreach ($regions as $r): ?>
            <option value="<?= htmlspecialchars($r, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $r===$region ? 'selected' : '' ?>>
              <?= htmlspecialchars($r, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="control">
        <div class="muted" style="font-size:12px;">Поиск</div>
        <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
               placeholder="Итоговый запрос…" <?= $fatalError ? 'disabled' : '' ?>>
      </div>

      <div class="control">
        <div class="muted" style="font-size:12px;">На странице</div>
        <select name="per_page" style="width:120px" <?= $fatalError ? 'disabled' : '' ?>>
          <?php foreach ([10,20,50,100] as $pp): ?>
            <option value="<?= $pp ?>" <?= $pp===$perPage ? 'selected' : '' ?>><?= $pp ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="actions">
        <button class="btn" type="submit" <?= $fatalError ? 'disabled' : '' ?>>Показать</button>
        <a class="btn" href="<?= strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?>" <?= $fatalError ? 'aria-disabled="true" style="pointer-events:none;opacity:.55;"' : '' ?>>
          Сбросить
        </a>
      </div>
    </form>

    <div class="table-wrap">
      <table class="table-sticky">
        <thead>
          <tr>
            <th>Регион</th>
            <th>Бренд</th>
            <th>Итоговый запрос</th>
            <th>Кол-во запросов за 30 дней</th>
            <th>Когда</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$stats): ?>
          <tr><td colspan="5" class="muted">Нет данных под выбранные фильтры.</td></tr>
        <?php else: foreach ($stats as $row): ?>
          <tr>
            <td><?= htmlspecialchars((string)$row['region'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)$row['brand'],  ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)$row['query'],  ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= (int)$row['query_count'] ?></td>
            <?php
              $dtRaw = (string)($row['created_at'] ?? '');
              try {
                  $d = DateTime::createFromFormat('Y-m-d H:i:s.u', $dtRaw) ?: new DateTime($dtRaw);
                  $dtFmt = $d->format('d.m.Y H:i');
              } catch (\Throwable $e) {
                  $dtFmt = $dtRaw !== '' ? $dtRaw : '—';
              }
            ?>
            <td class="muted"><?= htmlspecialchars($dtFmt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav class="pager<?= $fatalError ? ' is-disabled' : '' ?>">
        <a class="btn" href="<?= htmlspecialchars(build_url(['page'=>max(1, $page-1)]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
           style="<?= $page<=1 ? 'pointer-events:none;opacity:.5;' : '' ?>">« Назад</a>
        <?php
          $pages = [1];
          for ($p=$page-2; $p <= $page+2; $p++) if ($p>1 && $p<$totalPages) $pages[] = $p;
          if ($totalPages>1) $pages[] = $totalPages;
          $pages = array_values(array_unique($pages)); sort($pages);
          $prev = 0;
          foreach ($pages as $p) {
            if ($prev && $p > $prev+1) echo '<span class="muted" style="padding:0 4px;">…</span>';
            $cls = $p===$page ? 'btn active' : 'btn';
            echo '<a class="'.$cls.'" href="'.htmlspecialchars(build_url(['page'=>$p]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$p.'</a>';
            $prev = $p;
          }
        ?>
        <a class="btn" href="<?= htmlspecialchars(build_url(['page'=>min($totalPages, $page+1)]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
           style="<?= $page>=$totalPages ? 'pointer-events:none;opacity:.5;' : '' ?>">Вперёд »</a>
      </nav>
    <?php endif; ?>

  </div>
</div>

<script src="<?= asset_url('assets/admin.js') ?>" defer></script>
</body>
</html>
