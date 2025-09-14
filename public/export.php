<?php
declare(strict_types=1);
require __DIR__ . '/../src/config.php';
require_auth();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method Not Allowed';
    exit;
}

$fail = function (int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html><head><meta charset='utf-8'><title>Ошибка экспорта</title></head>
          <body><div style='margin:20px;font-family:sans-serif'>
          <h2>Ошибка экспорта</h2><p>{$msg}</p>
          <p><a href='/admin.php'>Вернуться в админку</a></p></div></body></html>";
    exit;
};

try {
    $pdo = db();
} catch (\Throwable $e) {
    error_log('[export.php] db() failed: '.$e->getMessage());
    $fail(500, 'Нет соединения с базой данных.');
}

try {
    $rows = $pdo->query('SELECT region, brand, query, query_count FROM stats ORDER BY region, brand, query')
                ->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log('[export.php] SELECT failed: '.$e->getMessage());
    $fail(500, 'Не удалось прочитать данные из БД.');
}

try {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Wordstat');
    $sheet->setCellValue('A1', 'Регион');
    $sheet->setCellValue('B1', 'Бренд');
    $sheet->setCellValue('C1', 'Итоговый запрос');
    $sheet->setCellValue('D1', 'Кол-во запросов за 30 дней');

    $r = 2;
    foreach ($rows as $row) {
        $sheet->setCellValue("A{$r}", (string)$row['region']);
        $sheet->setCellValue("B{$r}", (string)$row['brand']);
        $sheet->setCellValue("C{$r}", (string)$row['query']);
        $sheet->setCellValue("D{$r}", (int)$row['query_count']);
        $r++;
    }
    foreach (['A','B','C','D'] as $col) $sheet->getColumnDimension($col)->setAutoSize(true);

    $filename = 'wordstat_' . date('Ymd_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
} catch (\Throwable $e) {
    error_log('[export.php] XLSX failed: '.$e->getMessage());
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="wordstat_fallback.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Регион','Бренд','Итоговый запрос','Кол-во запросов за 30 дней'], ';');
    foreach ($rows ?? [] as $row) {
        fputcsv($out, [(string)$row['region'], (string)$row['brand'], (string)$row['query'], (int)$row['query_count']], ';');
    }
    fclose($out);
    exit;
}
