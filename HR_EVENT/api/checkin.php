<?php
/**
 * HR Event Check-in API
 * Event staff at the venue scan employees' SAFE (profile) QR codes.
 * POST: event_id, token (event secret), qr_data (scanned employee QR = employee_id or user_id).
 * No session required – authorization is the event token in the scanner URL.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$eventId = isset($input['event_id']) ? (int) $input['event_id'] : 0;
$token = isset($input['token']) ? trim($input['token']) : '';
$qrData = isset($input['qr_data']) ? trim($input['qr_data']) : '';
$checkMode = isset($input['check_mode']) ? strtolower(trim($input['check_mode'])) : '';

if (!$eventId || $token === '' || $qrData === '') {
    echo json_encode(['success' => false, 'message' => 'Missing event_id, token, or employee QR data.']);
    exit;
}

$qrData = str_replace(["\0", "\r", "\n", "\t"], '', $qrData);
$qrData = trim($qrData);
if ($qrData === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid QR code data.']);
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

try {
    // Validate event
    $stmt = $db->prepare("
        SELECT id, title, event_date, event_time, location, is_active
        FROM hr_events
        WHERE id = ? AND qr_token = ? AND is_active = 1
    ");
    $stmt->execute([$eventId, $token]);
    $event = $stmt->fetch();
    if (!$event) {
        echo json_encode(['success' => false, 'message' => 'Invalid or inactive event. Use the scanner link from Admin → HR Events.']);
        exit;
    }

    // Scanning allowed anytime – no event date restriction
    // Resolve user_id from employee SAFE QR (same logic as timekeeper get-user-info)
    $userId = null;
    $qrJson = json_decode($qrData, true);
    if ($qrJson && isset($qrJson['user_id'])) {
        $userId = (int) $qrJson['user_id'];
    } elseif ($qrJson && isset($qrJson['employee_id'])) {
        $empId = trim(preg_replace('/[\0\r\n\t]/', '', $qrJson['employee_id']));
        if ($empId !== '') {
            $stmtU = $db->prepare("SELECT user_id FROM faculty_profiles fp INNER JOIN users u ON fp.user_id = u.id WHERE TRIM(fp.employee_id) = ?");
            $stmtU->execute([$empId]);
            $row = $stmtU->fetch();
            if ($row) $userId = (int) $row['user_id'];
        }
    } elseif (is_numeric($qrData)) {
        $userId = (int) $qrData;
    } else {
        $searchId = trim(preg_replace('/[\0\r\n\t]/', '', $qrData));
        if ($searchId !== '') {
            $stmtU = $db->prepare("SELECT user_id FROM faculty_profiles fp INNER JOIN users u ON fp.user_id = u.id WHERE TRIM(fp.employee_id) = ?");
            $stmtU->execute([$searchId]);
            $row = $stmtU->fetch();
            if ($row) $userId = (int) $row['user_id'];
        }
    }

    if (!$userId) {
        echo json_encode(['success' => false, 'message' => 'Employee not found. Please scan the QR from the employee\'s SAFE account.']);
        exit;
    }

    // Ensure user is faculty/staff and has faculty_profile
    $stmtProf = $db->prepare("
        SELECT u.id, u.first_name, u.last_name, fp.employee_id
        FROM users u
        INNER JOIN faculty_profiles fp ON fp.user_id = u.id
        WHERE u.id = ? AND u.user_type IN ('faculty', 'staff') AND u.is_active = 1
    ");
    $stmtProf->execute([$userId]);
    $profile = $stmtProf->fetch();
    if (!$profile) {
        echo json_encode(['success' => false, 'message' => 'Not an active employee. Only faculty/staff can be checked in.']);
        exit;
    }

    $employeeId = $profile['employee_id'] ? trim($profile['employee_id']) : null;
    $name = trim($profile['first_name'] . ' ' . $profile['last_name']);

    // 4-point attendance: IN morning → LUNCH OUT (12PM) → LUNCH IN (1PM) → OUT afternoon
    $checkOrder = ['in_morning', 'out_noon', 'in_afternoon', 'out_afternoon'];
    $checkLabels = [
        'in_morning' => 'IN (morning)',
        'out_noon' => 'Lunch OUT (12:00 PM)',
        'in_afternoon' => 'Lunch IN (1:00 PM)',
        'out_afternoon' => 'OUT (afternoon)'
    ];

    $stmtGet = $db->prepare("
        SELECT check_type FROM hr_event_attendances
        WHERE event_id = ? AND user_id = ?
        ORDER BY FIELD(check_type, 'in_morning', 'out_noon', 'in_afternoon', 'out_afternoon')
    ");
    $stmtGet->execute([$eventId, $userId]);
    $existing = $stmtGet->fetchAll(PDO::FETCH_COLUMN);

    // Require explicit 4-point selection: in_morning, out_noon, in_afternoon, out_afternoon
    $validModes = ['in_morning', 'out_noon', 'in_afternoon', 'out_afternoon'];
    if (!in_array($checkMode, $validModes, true)) {
        echo json_encode(['success' => false, 'message' => 'Select IN, LUNCH OUT, LUNCH IN, or OUT before scanning.']);
        exit;
    }

    // Already recorded this type?
    if (in_array($checkMode, $existing, true)) {
        echo json_encode([
            'success' => true,
            'message' => $checkLabels[$checkMode] . ' already recorded.',
            'employee_name' => $name,
            'employee_id' => $employeeId,
            'event_title' => $event['title'],
            'already_checked_in' => true,
            'check_type' => $checkMode
        ]);
        exit;
    }

    // No sequence enforcement – IN, LUNCH OUT, LUNCH IN, OUT can be scanned in any order, anytime
    $nextType = $checkMode;

    $stmtIns = $db->prepare("
        INSERT INTO hr_event_attendances (event_id, user_id, employee_id, check_type, scanned_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmtIns->execute([$eventId, $userId, $employeeId, $nextType]);

    echo json_encode([
        'success' => true,
        'message' => $checkLabels[$nextType] . ' recorded.',
        'employee_name' => $name,
        'employee_id' => $employeeId,
        'event_title' => $event['title'],
        'already_checked_in' => false,
        'check_type' => $nextType,
        'check_label' => $checkLabels[$nextType]
    ]);
} catch (Exception $e) {
    error_log('HR Event checkin error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not record check-in. Please try again.']);
}
