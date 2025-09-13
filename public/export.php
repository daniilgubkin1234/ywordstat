<?php
declare(strict_types=1);
require __DIR__ . '/../src/config.php';
require_auth();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$pdo = db();
$rows = $pdo->query('SELECT region, brand, query, query_count FROM stats ORDER BY region, brand, query')->fetchAll();

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Wordstat');
$sheet->setCellValue('A1', 'Регион');
$sheet->setCellValue('B1', 'Бренд');
$sheet->setCellValue('C1', 'Итоговый запрос');
$sheet->setCellValue('D1', 'Количество запросов');

$r = 2;
foreach ($rows as $row) {
    $sheet->setCellValue("A{$r}", $row['region']);
    $sheet->setCellValue("B{$r}", $row['brand']);
    $sheet->setCellValue("C{$r}", $row['query']);
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
