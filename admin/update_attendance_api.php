<?php
/**
 * Update attendance log entry (super_admin only).
 * Allows overriding time_in, lunch_out, lunch_in, time_out, ot_in, ot_out, remarks, station_id.
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

if (!isSuperAdmin()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Only HR can override attendance entries.']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid attendance log ID']);
    exit;
}

$parseTime = function ($v) {
    $v = trim((string) ($v ?? ''));
    if ($v === '') return null;
    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $v, $m)) {
        return sprintf('%02d:%02d:%02d', (int)$m[1], (int)$m[2], isset($m[3]) ? (int)$m[3] : 0);
    }
    return null;
};

$time_in = $parseTime($_POST['time_in'] ?? '');
$lunch_out = $parseTime($_POST['lunch_out'] ?? '');
$lunch_in = $parseTime($_POST['lunch_in'] ?? '');
$time_out = $parseTime($_POST['time_out'] ?? '');
$ot_in = $parseTime($_POST['ot_in'] ?? '');
$ot_out = $parseTime($_POST['ot_out'] ?? '');
$remarks = isset($_POST['remarks']) ? trim(substr((string) $_POST['remarks'], 0, 500)) : '';
$station_id_raw = isset($_POST['station_id']) ? trim((string) $_POST['station_id']) : '';
$station_id = null;
if ($station_id_raw !== '' && $station_id_raw !== 'none' && $station_id_raw !== 'null') {
    $si = (int) $station_id_raw;
    if ($si > 0) $station_id = $si;
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();

    $stmt = $db->prepare("SELECT id, employee_id, log_date FROM attendance_logs WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Attendance log not found']);
        exit;
    }

    $params = [$time_in, $lunch_out, $lunch_in, $time_out, $ot_in, $ot_out, $remarks, $station_id, $id];
    $sql = "UPDATE attendance_logs SET time_in = ?, lunch_out = ?, lunch_in = ?, time_out = ?, ot_in = ?, ot_out = ?, remarks = ?, station_id = ? WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    logAction('ATTENDANCE_OVERRIDE', "Super admin overrode attendance log ID: $id for employee {$row['employee_id']} (date: {$row['log_date']})");

    echo json_encode([
        'success' => true,
        'message' => 'Attendance entry updated successfully',
        'id' => $id
    ]);

} catch (Exception $e) {
    error_log('update_attendance_api.php Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating attendance: ' . $e->getMessage()
    ]);
}
