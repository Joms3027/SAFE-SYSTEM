<?php
/**
 * Attendance report for a single calendar date — styled Excel (.xlsx), or plain CSV (?format=csv).
 */
ob_start();

require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

while (ob_get_level()) {
    ob_end_clean();
}

$date = $_GET['date'] ?? date('Y-m-d');
$search = trim($_GET['search'] ?? '');
$station_id = isset($_GET['station_id']) ? trim($_GET['station_id']) : '';
$format = strtolower(trim($_GET['format'] ?? 'xlsx'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid date. Use YYYY-MM-DD.';
    exit;
}

/**
 * @param string|null $t TIME string or null
 */
function attendance_format_time(?string $t): string
{
    if ($t === null || $t === '') {
        return '';
    }
    if (preg_match('/^(\d{2}):(\d{2})/', $t, $m)) {
        return $m[1] . ':' . $m[2];
    }
    return '';
}

/**
 * @param string|null $t
 */
function attendance_parse_minutes(?string $t): ?int
{
    if ($t === null || $t === '') {
        return null;
    }
    if (preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) {
        return (int) $m[1] * 60 + (int) $m[2];
    }
    return null;
}

/**
 * @param array<string,mixed> $record
 */
function attendance_total_hours(array $record): string
{
    $totalMinutes = 0;
    $ti = attendance_parse_minutes($record['time_in'] ?? null);
    $lo = attendance_parse_minutes($record['lunch_out'] ?? null);
    $li = attendance_parse_minutes($record['lunch_in'] ?? null);
    $to = attendance_parse_minutes($record['time_out'] ?? null);
    $oi = attendance_parse_minutes($record['ot_in'] ?? null);
    $oo = attendance_parse_minutes($record['ot_out'] ?? null);

    if ($ti !== null && $lo !== null && $lo > $ti) {
        $totalMinutes += ($lo - $ti);
    }
    if ($li !== null && $to !== null && $to > $li) {
        $totalMinutes += ($to - $li);
    }
    if ($oi !== null && $oo !== null && $oo > $oi) {
        $totalMinutes += ($oo - $oi);
    }
    if ($totalMinutes <= 0) {
        return '';
    }
    $h = (int) floor($totalMinutes / 60);
    $m = (int) ($totalMinutes % 60);
    return $h . 'h ' . $m . 'm';
}

/**
 * @param PDO $db
 */
function attendance_filter_station_label(PDO $db, string $station_id, string $search): string
{
    $parts = [];
    if ($station_id !== '') {
        if ($station_id === 'null' || $station_id === 'none') {
            $parts[] = 'Station: Unspecified';
        } else {
            $sid = (int) $station_id;
            if ($sid > 0) {
                $st = $db->prepare('SELECT name FROM stations WHERE id = ? LIMIT 1');
                $st->execute([$sid]);
                $name = $st->fetchColumn();
                $parts[] = 'Station: ' . ($name ? (string) $name : '#' . $sid);
            }
        }
    } else {
        $parts[] = 'Station: All';
    }
    if ($search !== '') {
        $parts[] = 'Search: ' . $search;
    }
    return implode('  |  ', $parts);
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    date_default_timezone_set('Asia/Manila');

    $whereClause = '1=1 AND DATE(al.log_date) = ?';
    $params = [$date];

    if ($search !== '') {
        $whereClause .= ' AND (al.employee_id LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
        $searchTerm = '%' . $search . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    if ($station_id !== '') {
        if ($station_id === 'null' || $station_id === 'none') {
            $whereClause .= ' AND al.station_id IS NULL';
        } else {
            $stationIdInt = (int) $station_id;
            if ($stationIdInt > 0) {
                $whereClause .= ' AND al.station_id = ?';
                $params[] = $stationIdInt;
            }
        }
    }

    $stmt = $db->prepare("
        SELECT 
            al.id,
            al.employee_id,
            al.log_date,
            al.time_in,
            al.lunch_out,
            al.lunch_in,
            al.time_out,
            al.ot_in,
            al.ot_out,
            al.remarks,
            al.station_id,
            COALESCE(
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, ''))),
                CONCAT('Employee ID: ', al.employee_id)
            ) as name,
            s.name as station_name
        FROM attendance_logs al
        LEFT JOIN faculty_profiles fp ON al.employee_id = fp.employee_id
        LEFT JOIN users u ON fp.user_id = u.id
        LEFT JOIN stations s ON al.station_id = s.id
        WHERE $whereClause
        ORDER BY al.log_date DESC, al.time_in ASC, COALESCE(u.last_name, ''), COALESCE(u.first_name, ''), al.employee_id ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $filterLine = attendance_filter_station_label($db, $station_id, $search);
    $generatedAt = date('Y-m-d H:i:s');
    $prettyDate = date('F j, Y', strtotime($date));

    if ($format === 'csv') {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $safeFile = 'attendance_' . $date . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeFile . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Daily Attendance Report']);
        fputcsv($out, ['Attendance date:', $date]);
        fputcsv($out, ['Generated:', $generatedAt]);
        fputcsv($out, ['Filters:', $filterLine]);
        fputcsv($out, ['Total entries:', (string) count($rows)]);
        fputcsv($out, []);
        $headers = [
            '#', 'Date', 'Employee ID', 'Name', 'Time In', 'Lunch Out', 'Lunch In', 'Time Out',
            'OT In', 'OT Out', 'Total Hours', 'Station', 'Remarks',
        ];
        fputcsv($out, $headers);
        $n = 0;
        foreach ($rows as $r) {
            $n++;
            $total = attendance_total_hours($r);
            fputcsv($out, [
                $n,
                $r['log_date'] ?? '',
                $r['employee_id'] ?? '',
                trim($r['name'] ?: 'N/A'),
                attendance_format_time($r['time_in'] ?? null),
                attendance_format_time($r['lunch_out'] ?? null),
                attendance_format_time($r['lunch_in'] ?? null),
                attendance_format_time($r['time_out'] ?? null),
                attendance_format_time($r['ot_in'] ?? null),
                attendance_format_time($r['ot_out'] ?? null),
                $total,
                $r['station_name'] ?? '',
                $r['remarks'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    $phpSpreadsheetAvailable = false;
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
        $phpSpreadsheetAvailable = class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet');
    }

    if (!$phpSpreadsheetAvailable) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Excel export requires PhpSpreadsheet. Use ?format=csv for a plain file, or install Composer dependencies.';
        exit;
    }

    $lastCol = 'M';
    $spreadsheet = new PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Attendance');

    $row = 1;
    $sheet->setCellValue('A' . $row, 'DAILY ATTENDANCE REPORT');
    $sheet->mergeCells('A' . $row . ':' . $lastCol . $row);
    $sheet->getStyle('A' . $row)->applyFromArray([
        'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4788']],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
        ],
    ]);
    $sheet->getRowDimension($row)->setRowHeight(36);
    $row += 2;

    $metaStyleLabel = [
        'font' => ['bold' => true, 'size' => 11],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F4F8']],
    ];
    $metaStyleValue = [
        'font' => ['size' => 11],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FCFF']],
    ];

    $sheet->setCellValue('A' . $row, 'Institution');
    $sheet->setCellValue('B' . $row, 'Western Philippines University');
    $sheet->mergeCells('B' . $row . ':' . $lastCol . $row);
    $sheet->getStyle('A' . $row)->applyFromArray($metaStyleLabel);
    $sheet->getStyle('B' . $row)->applyFromArray($metaStyleValue);
    $row++;

    $sheet->setCellValue('A' . $row, 'Attendance date');
    $sheet->setCellValue('B' . $row, $prettyDate . ' (' . $date . ')');
    $sheet->mergeCells('B' . $row . ':' . $lastCol . $row);
    $sheet->getStyle('A' . $row)->applyFromArray($metaStyleLabel);
    $sheet->getStyle('B' . $row)->applyFromArray($metaStyleValue);
    $row++;

    $sheet->setCellValue('A' . $row, 'Generated');
    $sheet->setCellValue('B' . $row, $generatedAt);
    $sheet->mergeCells('B' . $row . ':' . $lastCol . $row);
    $sheet->getStyle('A' . $row)->applyFromArray($metaStyleLabel);
    $sheet->getStyle('B' . $row)->applyFromArray($metaStyleValue);
    $row++;

    $sheet->setCellValue('A' . $row, 'Filters');
    $sheet->setCellValue('B' . $row, $filterLine);
    $sheet->mergeCells('B' . $row . ':' . $lastCol . $row);
    $sheet->getStyle('A' . $row)->applyFromArray($metaStyleLabel);
    $sheet->getStyle('B' . $row)->applyFromArray(array_merge($metaStyleValue, [
        'alignment' => ['wrapText' => true, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP],
    ]));
    $sheet->getRowDimension($row)->setRowHeight(28);
    $row++;

    $sheet->setCellValue('A' . $row, 'Total entries');
    $sheet->setCellValue('B' . $row, count($rows));
    $sheet->mergeCells('B' . $row . ':' . $lastCol . $row);
    $sheet->getStyle('A' . $row)->applyFromArray($metaStyleLabel);
    $sheet->getStyle('B' . $row)->applyFromArray(array_merge($metaStyleValue, [
        'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '1F4788']],
    ]));
    $row += 2;

    $headerRow = $row;
    $headers = [
        'A' => '#',
        'B' => 'Date',
        'C' => 'Employee ID',
        'D' => 'Name',
        'E' => 'Time In',
        'F' => 'Lunch Out',
        'G' => 'Lunch In',
        'H' => 'Time Out',
        'I' => 'OT In',
        'J' => 'OT Out',
        'K' => 'Total Hours',
        'L' => 'Station',
        'M' => 'Remarks',
    ];
    foreach ($headers as $col => $label) {
        $sheet->setCellValue($col . $row, $label);
    }
    $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->applyFromArray([
        'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '3498DB']],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            'wrap' => true,
        ],
        'borders' => [
            'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => '2980B9']],
        ],
    ]);
    $sheet->getRowDimension($row)->setRowHeight(30);
    $row++;

    $dataStartRow = $row;
    $counter = 0;
    foreach ($rows as $r) {
        $counter++;
        $total = attendance_total_hours($r);
        $logD = $r['log_date'] ?? '';
        $logPretty = $logD ? date('M j, Y', strtotime($logD)) : '';

        $sheet->setCellValue('A' . $row, $counter);
        $sheet->setCellValue('B' . $row, $logPretty);
        $sheet->setCellValue('C' . $row, $r['employee_id'] ?? '');
        $sheet->setCellValue('D' . $row, trim($r['name'] ?: 'N/A'));
        $sheet->setCellValue('E' . $row, attendance_format_time($r['time_in'] ?? null));
        $sheet->setCellValue('F' . $row, attendance_format_time($r['lunch_out'] ?? null));
        $sheet->setCellValue('G' . $row, attendance_format_time($r['lunch_in'] ?? null));
        $sheet->setCellValue('H' . $row, attendance_format_time($r['time_out'] ?? null));
        $sheet->setCellValue('I' . $row, attendance_format_time($r['ot_in'] ?? null));
        $sheet->setCellValue('J' . $row, attendance_format_time($r['ot_out'] ?? null));
        $sheet->setCellValue('K' . $row, $total !== '' ? $total : '—');
        $sheet->setCellValue('L' . $row, $r['station_name'] ?? '');
        $sheet->setCellValue('M' . $row, $r['remarks'] ?? '');

        $fillColor = ($row % 2 === 0) ? 'F8F9FA' : 'FFFFFF';
        $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->applyFromArray([
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $fillColor]],
            'borders' => [
                'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'E0E0E0']],
            ],
            'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        foreach (['E', 'F', 'G', 'H', 'I', 'J', 'K'] as $tc) {
            $sheet->getStyle($tc . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        }
        $sheet->getStyle('D' . $row)->getAlignment()->setWrapText(true);
        $sheet->getStyle('M' . $row)->getAlignment()->setWrapText(true);

        $row++;
    }

    if (count($rows) > 0) {
        $sheet->setAutoFilter('A' . $headerRow . ':' . $lastCol . ($row - 1));
    }
    $sheet->freezePane('A' . $dataStartRow);

    $sheet->getColumnDimension('A')->setWidth(6);
    $sheet->getColumnDimension('B')->setWidth(14);
    $sheet->getColumnDimension('C')->setWidth(14);
    $sheet->getColumnDimension('D')->setWidth(28);
    foreach (['E', 'F', 'G', 'H', 'I', 'J'] as $c) {
        $sheet->getColumnDimension($c)->setWidth(11);
    }
    $sheet->getColumnDimension('K')->setWidth(14);
    $sheet->getColumnDimension('L')->setWidth(18);
    $sheet->getColumnDimension('M')->setWidth(36);

    $filename = 'attendance_' . $date . '.xlsx';
    $tempDir = sys_get_temp_dir();
    $tempFile = tempnam($tempDir, 'attendance_report_');

    if ($tempFile === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Error: Failed to create temporary file.';
        exit;
    }

    try {
        $writer = new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($tempFile);
        $fileSize = filesize($tempFile);
        if ($fileSize === false) {
            @unlink($tempFile);
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Error: Failed to read generated file.';
            exit;
        }

        while (ob_get_level()) {
            ob_end_clean();
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (headers_sent($hsFile, $hsLine)) {
            @unlink($tempFile);
            http_response_code(500);
            die("Headers already sent in $hsFile on line $hsLine");
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Content-Transfer-Encoding: binary');

        if (@readfile($tempFile) === false) {
            @unlink($tempFile);
            http_response_code(500);
            die('Error: Failed to send file.');
        }
        @unlink($tempFile);
        exit;
    } catch (Exception $e) {
        @unlink($tempFile);
        while (ob_get_level()) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        error_log('download_attendance_report.php: ' . $e->getMessage());
        die('Error generating Excel file.');
    }
} catch (Exception $e) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    error_log('download_attendance_report.php: ' . $e->getMessage());
    die('Could not generate report.');
}
