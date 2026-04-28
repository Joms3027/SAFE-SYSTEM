<?php
/**
 * Clear absent attendance records for a specific employee.
 * Records cleared dates so they won't reappear when view_logs is loaded.
 * Usage: php clear_absent_attendance.php WPU-2026-00004
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/database.php';

$employee_id = $argv[1] ?? null;
if (empty($employee_id)) {
    echo "Usage: php clear_absent_attendance.php <employee_id>\n";
    exit(1);
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();

    // Ensure absent_cleared table exists
    $db->exec("CREATE TABLE IF NOT EXISTS absent_cleared (
        employee_id VARCHAR(50) NOT NULL,
        log_date DATE NOT NULL,
        cleared_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (employee_id, log_date)
    )");

    // Get absent records before delete
    $stmt = $db->prepare("SELECT log_date FROM attendance_logs WHERE employee_id = ? AND remarks = 'Absent'");
    $stmt->execute([$employee_id]);
    $absentRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($absentRows)) {
        echo "No absent records found for $employee_id.\n";
        exit(0);
    }

    // Record cleared dates so they won't be re-inserted by fetch_my_logs_api
    $stmtInsert = $db->prepare("INSERT IGNORE INTO absent_cleared (employee_id, log_date) VALUES (?, ?)");
    foreach ($absentRows as $row) {
        $stmtInsert->execute([$employee_id, $row['log_date']]);
    }

    // Delete absent records from attendance_logs
    $stmt = $db->prepare("DELETE FROM attendance_logs WHERE employee_id = ? AND remarks = 'Absent'");
    $stmt->execute([$employee_id]);
    $deleted = $stmt->rowCount();

    echo "Cleared $deleted absent attendance record(s) for $employee_id. They will not reappear.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
