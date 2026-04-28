<?php
/**
 * Batch Salary Calculation API
 * Returns salary data for all employees in a single request.
 * Uses the shared SalaryCalculator (same formula as calculate_salary_api.php).
 */
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

set_time_limit(300);

require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/salary_calculator.php';

requireAdmin();
$dtrFilterMode = (function_exists('isSuperAdmin') && isSuperAdmin()) ? 'submitted' : 'verified';
if (function_exists('closeSessionEarly')) { closeSessionEarly(); }

ob_clean();
header('Content-Type: application/json');

$employmentStatusFilter = isset($_GET['employment_status']) ? trim((string)$_GET['employment_status']) : '';
// Default: only employees with at least one attendance log in the selected date range
$requireAttendance = !isset($_GET['require_attendance']) || $_GET['require_attendance'] === '' || $_GET['require_attendance'] === '1' || $_GET['require_attendance'] === 'true';

$dateFromRaw = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$dateToRaw = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';
$df = DateTime::createFromFormat('Y-m-d', $dateFromRaw);
$dt = DateTime::createFromFormat('Y-m-d', $dateToRaw);
if (!$df || !$dt || $df->format('Y-m-d') !== $dateFromRaw || $dt->format('Y-m-d') !== $dateToRaw) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Valid date_from and date_to (YYYY-MM-DD) are required.']);
    ob_end_flush();
    exit;
}
if ($df > $dt) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'date_from must be on or before date_to.']);
    ob_end_flush();
    exit;
}

$rangeFirst = $dateFromRaw;
$rangeLast = $dateToRaw;
$year = (int)$df->format('Y');
$month = $df->format('m');
// Same as calculate_salary_api.php: custom range uses full-period (weekly-based) gross logic over those dates
$period = 'full';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    $calculator = new SalaryCalculator($db);

    $fpSubquery = "
        SELECT fp1.user_id, fp1.employee_id, fp1.position, fp1.employment_status
        FROM faculty_profiles fp1
        INNER JOIN (
            SELECT user_id, MAX(id) as max_id FROM faculty_profiles GROUP BY user_id
        ) fp2 ON fp1.user_id = fp2.user_id AND fp1.id = fp2.max_id
    ";
    $positionSalaryJoin = "
        LEFT JOIN (
            SELECT ps1.position_title, ps1.annual_salary
            FROM position_salary ps1
            INNER JOIN (
                SELECT position_title, MIN(id) as min_id FROM position_salary GROUP BY position_title
            ) ps2 ON ps1.position_title = ps2.position_title AND ps1.id = ps2.min_id
        ) ps ON fp.position = ps.position_title
    ";

    if ($requireAttendance) {
        // Distinct employees who have at least one attendance row in-range (same dates as salary calc)
        $sql = "
            SELECT DISTINCT fp.employee_id, fp.position, CONCAT(u.first_name, ' ', u.last_name) AS name,
                   ps.annual_salary AS position_annual_salary
            FROM attendance_logs al
            INNER JOIN ($fpSubquery) fp ON fp.employee_id = al.employee_id
            INNER JOIN users u ON u.id = fp.user_id
            $positionSalaryJoin
            WHERE al.log_date BETWEEN ? AND ?
            AND u.user_type IN ('faculty', 'staff')
            AND u.is_active = 1
            AND fp.employee_id IS NOT NULL AND fp.employee_id != ''
            AND fp.position IS NOT NULL AND fp.position != ''
        ";
        $params = [$rangeFirst, $rangeLast];
        if ($employmentStatusFilter !== '') {
            $sql .= " AND fp.employment_status = ? ";
            $params[] = $employmentStatusFilter;
        }
        $sql .= " ORDER BY u.last_name, u.first_name ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    } else {
        // Full roster (same filters as employee_logs list — no is_verified gate)
        $sql = "
            SELECT fp.employee_id, fp.position, CONCAT(u.first_name, ' ', u.last_name) AS name,
                   ps.annual_salary AS position_annual_salary
            FROM users u
            INNER JOIN ($fpSubquery) fp ON u.id = fp.user_id
            $positionSalaryJoin
            WHERE u.user_type IN ('faculty', 'staff')
            AND u.is_active = 1
            AND fp.employee_id IS NOT NULL AND fp.employee_id != ''
            AND fp.position IS NOT NULL AND fp.position != ''
        ";
        $params = [];
        if ($employmentStatusFilter !== '') {
            $sql .= " AND fp.employment_status = ? ";
            $params[] = $employmentStatusFilter;
        }
        $sql .= " ORDER BY u.last_name, u.first_name ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    }
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($employees as $emp) {
        $empId = trim($emp['employee_id']);
        $position = trim($emp['position']);
        if (!$empId || !$position) continue;

        // Pass pre-resolved salary from join when available (ensures same rate as employee_logs)
        $overrideSalary = null;
        if (!empty($emp['position_annual_salary']) && floatval($emp['position_annual_salary']) > 0) {
            $overrideSalary = floatval($emp['position_annual_salary']);
        }
        $result = $calculator->calculate($empId, $position, $year, $month, $period, $dateFromRaw, $dateToRaw, $overrideSalary, $dtrFilterMode);
        if ($result['success'] && $result['data']) {
            $d = $result['data'];
            $tardinessDeduction = 0;
            if (!empty($d['additional_deductions']) && is_array($d['additional_deductions'])) {
                foreach ($d['additional_deductions'] as $ded) {
                    $itemName = strtolower($ded['item_name'] ?? '');
                    $isTardiness = (strpos($itemName, 'tardiness') !== false || strpos($itemName, 'late') !== false ||
                        strpos($itemName, 'undertime') !== false || strpos($itemName, 'absence') !== false);
                    $isDed = ($ded['dr_cr'] ?? '') === 'Dr' || ($ded['type'] ?? '') === 'Deduct';
                    if ($isDed && $isTardiness) {
                        $tardinessDeduction += abs(floatval($ded['amount'] ?? 0));
                    }
                }
            }

            $results[] = [
                'employee_id' => $empId,
                'fullName' => $emp['name'] ?? '',
                'position' => $position,
                'total_hours' => floatval($d['total_hours']),
                'total_late' => floatval($d['total_late']),
                'total_undertime' => floatval($d['total_undertime']),
                'total_absences' => intval($d['total_absences']),
                'gross_salary' => floatval($d['gross_salary']),
                'tardiness_deduction' => $tardinessDeduction,
                'total_deductions' => abs(floatval($d['total_deductions_only'])),
                'net_income' => floatval($d['net_income']),
                'additional_deductions' => $d['additional_deductions'] ?? [],
                'additional_deductions_total' => floatval($d['additional_deductions_total'] ?? 0)
            ];
        } else {
            $results[] = [
                'employee_id' => $empId,
                'fullName' => $emp['name'] ?? '',
                'position' => $position,
                'total_hours' => 0,
                'total_late' => 0,
                'total_undertime' => 0,
                'total_absences' => 0,
                'gross_salary' => 0,
                'tardiness_deduction' => 0,
                'total_deductions' => 0,
                'net_income' => 0,
                'error' => $result['message'] ?? 'Calculation failed'
            ];
        }
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'salaries' => $results,
        'count' => count($results),
        'date_from' => $dateFromRaw,
        'date_to' => $dateToRaw
    ]);
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
ob_end_flush();
