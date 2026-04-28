<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/salary_calculator.php';

requireAdmin();
if (function_exists('closeSessionEarly')) { closeSessionEarly(); }

ob_clean();
header('Content-Type: application/json');

$employee_id = $_GET['employee_id'] ?? '';
$position = $_GET['position'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$month = isset($_GET['month']) ? str_pad(intval($_GET['month']), 2, '0', STR_PAD_LEFT) : date('m');
$period = $_GET['period'] ?? 'full';

// When date_from and date_to are provided, derive year/month for response and pass range to calculator
if ($date_from !== '' && $date_to !== '') {
    $from_ts = strtotime($date_from);
    $to_ts = strtotime($date_to);
    if ($from_ts === false || $to_ts === false || $from_ts > $to_ts) {
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid date_from or date_to']);
        ob_end_flush();
        exit();
    }
    $year = (int) date('Y', $from_ts);
    $month = date('m', $from_ts);
    $period = 'full';
}

if (!$employee_id || !$position) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing employee_id or position']);
    ob_end_flush();
    exit();
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    $calculator = new SalaryCalculator($db);
    $result = $calculator->calculate($employee_id, $position, $year, $month, $period, $date_from ?: null, $date_to ?: null);

    if (!$result['success']) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => $result['message'] ?? 'Calculation failed']);
        ob_end_flush();
        exit();
    }

    $d = $result['data'];
    if ($date_from !== '' && $date_to !== '') {
        $first_day = $date_from;
        $last_day = $date_to;
    } else {
        $first_day = "$year-$month-01";
        $last_day = date("Y-m-t", strtotime($first_day));
        if ($period === 'first') $last_day = "$year-$month-15";
        elseif ($period === 'second') $first_day = "$year-$month-16";
    }
    $debug_info = ['period' => "$first_day to $last_day", 'calculator' => 'SalaryCalculator'];

    $response = [
        'success' => true,
        'employee_id' => $employee_id,
        'position' => $position,
        'month' => $d['month'],
        'year' => $d['year'],
        'total_hours' => $d['total_hours'],
        'total_late' => $d['total_late'],
        'total_undertime' => $d['total_undertime'],
        'total_overtime' => $d['total_overtime'],
        'total_coc_points' => $d['total_coc_points'],
        'days_worked' => $d['days_worked'],
        'annual_salary' => $d['annual_salary'],
        'monthly_rate' => $d['monthly_rate'],
        'weekly_rate' => $d['weekly_rate'],
        'daily_rate' => $d['daily_rate'],
        'hourly_rate' => $d['hourly_rate'],
        'late_deduction' => number_format(0, 2, '.', ''),
        'undertime_deduction' => number_format(0, 2, '.', ''),
        'absence_deduction' => $d['absence_deduction'],
        'total_absences' => $d['total_absences'],
        'additional_deductions_total' => $d['additional_deductions_total'],
        'total_deductions_only' => $d['total_deductions_only'],
        'total_additions' => $d['total_additions'],
        'additional_deductions' => $d['additional_deductions'],
        'gross_salary' => $d['gross_salary'],
        'adjusted_gross_salary' => $d['adjusted_gross_salary'],
        'net_income' => $d['net_income'],
        'warning' => $d['warning'],
        'debug' => $debug_info
    ];

    ob_clean();
    echo json_encode($response);
    ob_end_flush();
    exit();
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error calculating salary: ' . $e->getMessage()]);
    ob_end_flush();
    exit();
}
