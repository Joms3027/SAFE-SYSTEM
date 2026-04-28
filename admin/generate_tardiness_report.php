<?php
// Start output buffering immediately to prevent any output
ob_start();

// Disable error display
error_reporting(E_ALL);
ini_set('display_errors', 0);

set_time_limit(300);

require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

// Check admin access without redirecting (for file downloads)
if (!isset($_SESSION['user_id']) || !isAdmin()) {
    // Clean all buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code(403);
    header('Content-Type: text/plain');
    die('Access denied. Admin privileges required.');
}

// Clean any existing output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Check if PhpSpreadsheet is available
$phpSpreadsheetAvailable = false;
if (file_exists('../vendor/autoload.php')) {
    require_once '../vendor/autoload.php';
    $phpSpreadsheetAvailable = class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet');
}

if (!$phpSpreadsheetAvailable) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/plain');
    die('PhpSpreadsheet library is not available. Please install it via Composer.');
}

$database = Database::getInstance();
$db = $database->getConnection();

try {
    
    // Get filter parameters
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    $employee_id = $_GET['employee_id'] ?? '';
    
    // Helper function to parse TIME field to minutes from midnight
    $parseTimeToMinutes = function($timeStr) {
        if (empty($timeStr) || $timeStr === null) return null;
        $timeStr = trim((string)$timeStr);
        $parts = explode(':', $timeStr);
        if (count($parts) >= 2) {
            $hours = intval($parts[0]);
            $minutes = intval($parts[1]);
            return ($hours * 60) + $minutes;
        }
        return null;
    };
    
    // Build query to get attendance logs with tardiness
    $query = "
        SELECT 
            al.id,
            al.employee_id,
            al.log_date,
            al.time_in,
            al.lunch_in,
            COALESCE(u.first_name, '') as first_name,
            COALESCE(u.last_name, '') as last_name,
            COALESCE(fp.position, '') as position
        FROM attendance_logs al
        LEFT JOIN faculty_profiles fp ON al.employee_id = fp.employee_id
        LEFT JOIN users u ON fp.user_id = u.id
        WHERE (al.time_in IS NOT NULL AND al.time_in != '')
           OR (al.lunch_in IS NOT NULL AND al.lunch_in != '')
    ";
    
    $params = [];
    
    if ($start_date) {
        $query .= " AND al.log_date >= ?";
        $params[] = $start_date;
    }
    
    if ($end_date) {
        $query .= " AND al.log_date <= ?";
        $params[] = $end_date;
    }
    
    if ($employee_id) {
        $query .= " AND al.employee_id = ?";
        $params[] = $employee_id;
    }
    
    $query .= " ORDER BY al.log_date DESC, u.last_name ASC, u.first_name ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $attendance_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get official times for all employees (including weekday)
    $officialTimesStmt = $db->query("
        SELECT employee_id, start_date, end_date, weekday, time_in, lunch_out, lunch_in, time_out
        FROM employee_official_times
        ORDER BY employee_id, start_date DESC
    ");
    $officialTimesList = [];
    while ($ot = $officialTimesStmt->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($officialTimesList[$ot['employee_id']])) {
            $officialTimesList[$ot['employee_id']] = [];
        }
        $officialTimesList[$ot['employee_id']][] = $ot;
    }
    
    // Helper function to get weekday name from date
    $getWeekdayName = function($dateStr) {
        $date = new DateTime($dateStr);
        $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        return $weekdays[(int)$date->format('w')];
    };
    
    // Default official times
    $default_official_times = [
        'time_in' => '08:00:00',
        'lunch_out' => '12:00:00',
        'lunch_in' => '13:00:00',
        'time_out' => '17:00:00'
    ];
    
    // Process logs and calculate tardiness
    $tardiness_records = [];
    foreach ($attendance_logs as $log) {
        $logDate = $log['log_date'];
        $employeeId = $log['employee_id'];
        $logWeekday = $getWeekdayName($logDate);
        
        // Find the official times that apply to this log date AND weekday
        $official = $default_official_times;
        $mostRecentStartDate = null;
        
        if (isset($officialTimesList[$employeeId])) {
            foreach ($officialTimesList[$employeeId] as $ot) {
                $startDate = $ot['start_date'];
                $endDate = $ot['end_date'];
                $weekday = $ot['weekday'] ?? null;
                
                // Check if this official time applies to the log date AND weekday
                // If weekday is set, it must match the log's weekday
                $weekdayMatches = ($weekday === null || $weekday === $logWeekday);
                
                if ($weekdayMatches && $startDate <= $logDate && ($endDate === null || $endDate >= $logDate)) {
                    // Use the most recent one if multiple apply
                    if ($mostRecentStartDate === null || $startDate > $mostRecentStartDate) {
                        $mostRecentStartDate = $startDate;
                        $official = $ot;
                    }
                }
            }
        }
        
        // Parse times to minutes
        $official_in_minutes = $parseTimeToMinutes($official['time_in']);
        $official_lunch_in_minutes = $parseTimeToMinutes($official['lunch_in']);
        $actual_in_minutes = $parseTimeToMinutes($log['time_in']);
        $actual_lunch_in_minutes = !empty($log['lunch_in']) ? $parseTimeToMinutes($log['lunch_in']) : null;
        
        // Calculate tardiness for time_in
        $time_in_late_minutes = 0;
        if ($official_in_minutes !== null && $actual_in_minutes !== null && $actual_in_minutes > $official_in_minutes) {
            $time_in_late_minutes = $actual_in_minutes - $official_in_minutes;
        }
        
        // Calculate tardiness for lunch_in
        $lunch_in_late_minutes = 0;
        if ($official_lunch_in_minutes !== null && $actual_lunch_in_minutes !== null && $actual_lunch_in_minutes > $official_lunch_in_minutes) {
            $lunch_in_late_minutes = $actual_lunch_in_minutes - $official_lunch_in_minutes;
        }
        
        // Total tardiness = time_in tardiness + lunch_in tardiness
        $total_late_minutes = $time_in_late_minutes + $lunch_in_late_minutes;
        
        // Only include if there's any tardiness
        if ($total_late_minutes > 0) {
            $late_hours = $total_late_minutes / 60;
            
            // Format time as HH:MM:SS
            $h = floor($total_late_minutes / 60);
            $m = $total_late_minutes % 60;
            $s = 0;
            $time_info = sprintf('%02d:%02d:%02d', $h, $m, $s);
            
            $tardiness_records[] = [
                'employee_id' => $employeeId,
                'full_name' => trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')),
                'position' => $log['position'] ?? '',
                'log_date' => $logDate,
                'time_in' => $log['time_in'] ?? '',
                'lunch_in' => $log['lunch_in'] ?? '',
                'official_time_in' => $official['time_in'],
                'official_lunch_in' => $official['lunch_in'],
                'time_info' => $time_info,
                'late_minutes' => $total_late_minutes,
                'late_hours' => $late_hours
            ];
        }
    }
    
    // Create Excel file using PhpSpreadsheet
    $spreadsheet = new PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Tardiness Report');
    
    $currentRow = 1;
    
    // Header Section - Title (Row 1)
    $sheet->setCellValue('A' . $currentRow, 'TARDINESS REPORT');
    $sheet->mergeCells('A' . $currentRow . ':G' . $currentRow);
    $sheet->getStyle('A' . $currentRow)->applyFromArray([
        'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4788']],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER]
    ]);
    $sheet->getRowDimension($currentRow)->setRowHeight(35);
    $currentRow++;
    
    // Empty row
    $currentRow++;
    
    // Business Info Section
    $sheet->setCellValue('A' . $currentRow, 'Business Name:');
    $sheet->setCellValue('B' . $currentRow, 'Western Philippines University');
    $sheet->getStyle('A' . $currentRow)->applyFromArray([
        'font' => ['bold' => true, 'size' => 11],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F4F8']]
    ]);
    $currentRow++;
    
    $sheet->setCellValue('A' . $currentRow, 'Report Period:');
    $sheet->setCellValue('B' . $currentRow, ($start_date && $end_date) ? "$start_date to $end_date" : 'All Records');
    $sheet->getStyle('A' . $currentRow)->applyFromArray([
        'font' => ['bold' => true, 'size' => 11],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F4F8']]
    ]);
    $currentRow++;
    
    $sheet->setCellValue('A' . $currentRow, 'Generated:');
    $sheet->setCellValue('B' . $currentRow, date('Y-m-d H:i:s'));
    $sheet->getStyle('A' . $currentRow)->applyFromArray([
        'font' => ['bold' => true, 'size' => 11],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F4F8']]
    ]);
    $currentRow++;
    
    if ($employee_id) {
        $sheet->setCellValue('A' . $currentRow, 'Safe Employee ID:');
        $sheet->setCellValue('B' . $currentRow, $employee_id);
        $sheet->getStyle('A' . $currentRow)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F4F8']]
        ]);
        $currentRow++;
    }
    
    // Empty rows
    $currentRow += 2;
    
    // Table Headers (Row after empty rows)
    $headerRow = $currentRow;
    $sheet->setCellValue('A' . $currentRow, '#');
    $sheet->setCellValue('B' . $currentRow, 'Full Name');
    $sheet->setCellValue('C' . $currentRow, 'Position');
    $sheet->setCellValue('D' . $currentRow, 'Date');
    $sheet->setCellValue('E' . $currentRow, 'Official Time');
    $sheet->setCellValue('F' . $currentRow, 'Actual Time In');
    $sheet->setCellValue('G' . $currentRow, 'Tardiness');
    
    // Style headers
    $sheet->getStyle('A' . $currentRow . ':G' . $currentRow)->applyFromArray([
        'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '3498DB']],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        'borders' => [
            'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => '2980B9']]
        ]
    ]);
    $sheet->getRowDimension($currentRow)->setRowHeight(30);
    $currentRow++;
    
    // Table Data
    $counter = 1;
    $totalTardinessMinutes = 0;
    $dataStartRow = $currentRow;
    
    foreach ($tardiness_records as $record) {
        $logDate = date('M d, Y', strtotime($record['log_date']));
        
        $sheet->setCellValue('A' . $currentRow, $counter++);
        $sheet->setCellValue('B' . $currentRow, $record['full_name']);
        $sheet->setCellValue('C' . $currentRow, $record['position']);
        $sheet->setCellValue('D' . $currentRow, $logDate);
        $sheet->setCellValue('E' . $currentRow, $record['official_time_in']);
        $sheet->setCellValue('F' . $currentRow, $record['time_in']);
        $sheet->setCellValue('G' . $currentRow, $record['time_info']);
        
        // Alternate row colors
        $fillColor = ($currentRow % 2 == 0) ? 'F8F9FA' : 'FFFFFF';
        $sheet->getStyle('A' . $currentRow . ':G' . $currentRow)->applyFromArray([
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $fillColor]],
            'borders' => [
                'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'E0E0E0']]
            ],
            'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER]
        ]);
        
        // Center align # column
        $sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        
        // Right align Tardiness column
        $sheet->getStyle('G' . $currentRow)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'E74C3C']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT]
        ]);
        
        $totalTardinessMinutes += $record['late_minutes'];
        $currentRow++;
    }
    
    // Summary Section
    $currentRow += 2;
    $summaryStartRow = $currentRow;
    
    $sheet->setCellValue('A' . $currentRow, 'SUMMARY');
    $sheet->mergeCells('A' . $currentRow . ':G' . $currentRow);
    $sheet->getStyle('A' . $currentRow)->applyFromArray([
        'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '2C3E50']],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]
    ]);
    $currentRow++;
    
    $sheet->setCellValue('A' . $currentRow, 'Total Records:');
    $sheet->setCellValue('B' . $currentRow, count($tardiness_records));
    $sheet->getStyle('A' . $currentRow)->applyFromArray(['font' => ['bold' => true]]);
    $currentRow++;
    
    // Calculate total tardiness
    $totalHours = floor($totalTardinessMinutes / 60);
    $totalMinutes = $totalTardinessMinutes % 60;
    $totalTardinessFormatted = sprintf('%02d:%02d:00', $totalHours, $totalMinutes);
    $sheet->setCellValue('A' . $currentRow, 'Total Tardiness:');
    $sheet->setCellValue('B' . $currentRow, $totalTardinessFormatted);
    $sheet->getStyle('A' . $currentRow)->applyFromArray(['font' => ['bold' => true]]);
    $sheet->getStyle('B' . $currentRow)->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'E74C3C']]]);
    $currentRow++;
    
    // Average tardiness
    if (count($tardiness_records) > 0) {
        $avgMinutes = round($totalTardinessMinutes / count($tardiness_records));
        $avgHours = floor($avgMinutes / 60);
        $avgMins = $avgMinutes % 60;
        $avgTardinessFormatted = sprintf('%02d:%02d:00', $avgHours, $avgMins);
        $sheet->setCellValue('A' . $currentRow, 'Average Tardiness:');
        $sheet->setCellValue('B' . $currentRow, $avgTardinessFormatted);
        $sheet->getStyle('A' . $currentRow)->applyFromArray(['font' => ['bold' => true]]);
        $sheet->getStyle('B' . $currentRow)->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'E74C3C']]]);
    }
    
    // Set column widths
    $sheet->getColumnDimension('A')->setWidth(8);
    $sheet->getColumnDimension('B')->setWidth(25);
    $sheet->getColumnDimension('C')->setWidth(20);
    $sheet->getColumnDimension('D')->setWidth(15);
    $sheet->getColumnDimension('E')->setWidth(15);
    $sheet->getColumnDimension('F')->setWidth(15);
    $sheet->getColumnDimension('G')->setWidth(15);
    
    // Generate filename
    $filename = 'Tardiness_Report';
    if ($start_date && $end_date) {
        $filename .= '_' . $start_date . '_to_' . $end_date;
    } else {
        $filename .= '_' . date('Y-m-d');
    }
    $filename .= '.xlsx';
    
    // Create a temporary file to write the spreadsheet
    $tempDir = sys_get_temp_dir();
    $tempFile = tempnam($tempDir, 'tardiness_report_');
    
    if ($tempFile === false) {
        // Clean all buffers on error
        while (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        die('Error: Failed to create temporary file.');
    }
    
    try {
        // Write spreadsheet to temporary file
        $writer = new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($tempFile);
        
        // Get file size
        $fileSize = filesize($tempFile);
        if ($fileSize === false) {
            @unlink($tempFile);
            // Clean all buffers on error
            while (ob_get_level()) {
                ob_end_clean();
            }
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            die('Error: Failed to get file size.');
        }
        
        // Clear any remaining output buffers completely
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Close session before sending file (prevents session lock issues)
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        
        // Ensure no output has been sent
        if (headers_sent($file, $line)) {
            @unlink($tempFile);
            http_response_code(500);
            die("Headers already sent in $file on line $line");
        }
        
        // Set headers FIRST before any output
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Content-Transfer-Encoding: binary');
        
        // Use readfile() for proper binary file output
        if (@readfile($tempFile) === false) {
            @unlink($tempFile);
            http_response_code(500);
            die('Error: Failed to read file for download.');
        }
        
        // Clean up temporary file
        @unlink($tempFile);
        
        // Exit immediately after output
        exit;
    } catch (Exception $e) {
        // Clean up temporary file on error
        @unlink($tempFile);
        
        // Clean all buffers on error
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Only set headers if not already sent
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        die('Error generating Excel file: ' . $e->getMessage());
    }
    
} catch (Exception $e) {
    // Clean all buffers on error
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Only send JSON if headers haven't been sent yet
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        die('Error generating report: ' . $e->getMessage());
    } else {
        // Headers already sent, just output plain text
        die("\n\nError generating report: " . $e->getMessage());
    }
}

