<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();
if (function_exists('closeSessionEarly')) { closeSessionEarly(); }

header('Content-Type: application/json');

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
    $formatted_records = [];
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
            
            $formatted_records[] = [
                'id' => $log['id'],
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
                'late_hours' => number_format($late_hours, 2, '.', '')
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'records' => $formatted_records,
        'count' => count($formatted_records)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching tardiness records: ' . $e->getMessage()
    ]);
}

