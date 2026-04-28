<?php
/**
 * Official Time Requests API
 *
 * Workflow:
 * - Employee declares official time (submit_request).
 * - All employees: status = pending_dean → Supervisor (Dean or pardon opener) endorses → pending_super_admin → HR approves/rejects.
 * - When approved, the request is copied to employee_official_times (working time).
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/notifications.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$database = Database::getInstance();
$db = $database->getConnection();

$userType = $_SESSION['user_type'] ?? '';
$isSuperAdmin = ($userType === 'super_admin');
$isAdmin = isAdmin();
$isFaculty = ($userType === 'faculty' || $userType === 'staff');
$isDean = false;
$deanDepartment = '';
$hasScopeAssignments = false;
$scopeEmployeeIds = [];

$currentUserEmployeeId = '';
if ($isFaculty && isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT fp.designation, fp.department, fp.employee_id FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if (strtolower(trim($row['designation'] ?? '')) === 'dean') {
            $isDean = true;
            $deanDepartment = trim($row['department'] ?? '');
        }
        if (!empty($row['employee_id'])) {
            $currentUserEmployeeId = trim($row['employee_id']);
        }
    }
    if (!$isDean && function_exists('hasPardonOpenerAssignments') && hasPardonOpenerAssignments($_SESSION['user_id'], $db)) {
        $hasScopeAssignments = true;
        $scopeEmployeeIds = function_exists('getEmployeeIdsInScope') ? getEmployeeIdsInScope($_SESSION['user_id'], $db) : [];
        // Exclude self: cannot endorse own official time (only assigned person can endorse)
        if ($currentUserEmployeeId !== '' && in_array($currentUserEmployeeId, $scopeEmployeeIds)) {
            $scopeEmployeeIds = array_values(array_diff($scopeEmployeeIds, [$currentUserEmployeeId]));
        }
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'submit_request': {
            // Faculty or staff: submit a new official time request (own employee_id only)
            if (!$isFaculty) {
                echo json_encode(['success' => false, 'message' => 'Only employees can declare official time']);
                exit;
            }
            $stmt = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
            $stmt->execute([$_SESSION['user_id']]);
            $emp = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$emp || empty($emp['employee_id'])) {
                echo json_encode(['success' => false, 'message' => 'Employee ID not found']);
                exit;
            }
            $employee_id = $emp['employee_id'];

            $start_date = $_POST['start_date'] ?? '';
            $end_date = trim($_POST['end_date'] ?? '') ?: null;
            $weekday = $_POST['weekday'] ?? 'Monday';
            $time_in = $_POST['time_in'] ?? '08:00';
            $lunch_out = trim($_POST['lunch_out'] ?? '') ?: null;
            $lunch_in = trim($_POST['lunch_in'] ?? '') ?: null;
            $time_out = $_POST['time_out'] ?? '17:00';

            if (empty($start_date) || empty($time_in) || empty($time_out) || empty($weekday)) {
                echo json_encode(['success' => false, 'message' => 'Start date, weekday, time in, and time out are required']);
                exit;
            }
            $valid_weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            if (!in_array($weekday, $valid_weekdays)) {
                echo json_encode(['success' => false, 'message' => 'Invalid weekday']);
                exit;
            }
            if ($end_date && $end_date < $start_date) {
                echo json_encode(['success' => false, 'message' => 'End date must be after start date']);
                exit;
            }

            // Both faculty and staff go to supervisor (Dean or pardon opener) first, then HR
            $status = 'pending_dean';

            $time_in_f = (preg_match('/^\d{1,2}:\d{2}$/', $time_in)) ? $time_in . ':00' : $time_in;
            $time_out_f = (preg_match('/^\d{1,2}:\d{2}$/', $time_out)) ? $time_out . ':00' : $time_out;
            $lunch_out_f = ($lunch_out && preg_match('/^\d{1,2}:\d{2}$/', $lunch_out)) ? $lunch_out . ':00' : $lunch_out;
            $lunch_in_f = ($lunch_in && preg_match('/^\d{1,2}:\d{2}$/', $lunch_in)) ? $lunch_in . ':00' : $lunch_in;

            $stmt = $db->prepare("INSERT INTO official_time_requests 
                (employee_id, start_date, end_date, weekday, time_in, lunch_out, lunch_in, time_out, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $employee_id,
                $start_date,
                $end_date,
                $weekday,
                $time_in_f,
                $lunch_out_f,
                $lunch_in_f,
                $time_out_f,
                $status
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Official time request submitted. It will be reviewed by your supervisor, then by HR.',
                'request_id' => (int) $db->lastInsertId(),
                'status' => $status
            ]);
            exit;
        }

        case 'submit_request_batch': {
            // Faculty or staff: submit multiple official time requests (same schedule, different weekdays)
            if (!$isFaculty) {
                echo json_encode(['success' => false, 'message' => 'Only employees can declare official time']);
                exit;
            }
            $stmt = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
            $stmt->execute([$_SESSION['user_id']]);
            $emp = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$emp || empty($emp['employee_id'])) {
                echo json_encode(['success' => false, 'message' => 'Employee ID not found']);
                exit;
            }
            $employee_id = $emp['employee_id'];

            $start_date = $_POST['start_date'] ?? '';
            $end_date = trim($_POST['end_date'] ?? '') ?: null;
            $weekdays = $_POST['weekdays'] ?? [];
            if (!is_array($weekdays)) {
                $weekdays = isset($_POST['weekdays']) ? [$_POST['weekdays']] : [];
            }
            $time_in = $_POST['time_in'] ?? '08:00';
            $lunch_out = trim($_POST['lunch_out'] ?? '') ?: null;
            $lunch_in = trim($_POST['lunch_in'] ?? '') ?: null;
            $time_out = $_POST['time_out'] ?? '17:00';

            if (empty($start_date) || empty($time_in) || empty($time_out) || empty($weekdays)) {
                echo json_encode(['success' => false, 'message' => 'Start date, at least one day, time in, and time out are required']);
                exit;
            }
            $valid_weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            $weekdays = array_values(array_unique(array_intersect($weekdays, $valid_weekdays)));
            if (empty($weekdays)) {
                echo json_encode(['success' => false, 'message' => 'Select at least one valid weekday']);
                exit;
            }
            if ($end_date && $end_date < $start_date) {
                echo json_encode(['success' => false, 'message' => 'End date must be after start date']);
                exit;
            }

            $status = 'pending_dean';
            $time_in_f = (preg_match('/^\d{1,2}:\d{2}$/', $time_in)) ? $time_in . ':00' : $time_in;
            $time_out_f = (preg_match('/^\d{1,2}:\d{2}$/', $time_out)) ? $time_out . ':00' : $time_out;
            $lunch_out_f = ($lunch_out && preg_match('/^\d{1,2}:\d{2}$/', $lunch_out)) ? $lunch_out . ':00' : $lunch_out;
            $lunch_in_f = ($lunch_in && preg_match('/^\d{1,2}:\d{2}$/', $lunch_in)) ? $lunch_in . ':00' : $lunch_in;

            $stmt = $db->prepare("INSERT INTO official_time_requests 
                (employee_id, start_date, end_date, weekday, time_in, lunch_out, lunch_in, time_out, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($weekdays as $weekday) {
                $stmt->execute([
                    $employee_id,
                    $start_date,
                    $end_date,
                    $weekday,
                    $time_in_f,
                    $lunch_out_f,
                    $lunch_in_f,
                    $time_out_f,
                    $status
                ]);
            }
            $count = count($weekdays);
            $msg = $count === 1
                ? 'Official time request submitted. It will be reviewed by your supervisor, then by HR.'
                : $count . ' official time requests submitted. They will be reviewed by your supervisor, then by HR.';
            echo json_encode(['success' => true, 'message' => $msg, 'count' => $count]);
            exit;
        }

        case 'list_my': {
            // Faculty or staff: list own requests
            if (!$isFaculty) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit;
            }
            $stmt = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
            $stmt->execute([$_SESSION['user_id']]);
            $emp = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$emp || empty($emp['employee_id'])) {
                echo json_encode(['success' => true, 'requests' => []]);
                exit;
            }
            $employee_id = $emp['employee_id'];

            $stmt = $db->prepare("SELECT id, start_date, end_date, weekday, time_in, lunch_out, lunch_in, time_out, status, 
                submitted_at, dean_verified_at, super_admin_approved_at, verified_at, verified_by, rejected_at, rejection_reason 
                FROM official_time_requests WHERE employee_id = ? ORDER BY submitted_at DESC LIMIT 100");
            $stmt->execute([$employee_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $list = [];
            foreach ($rows as $r) {
                $list[] = [
                    'id' => (int) $r['id'],
                    'start_date' => $r['start_date'],
                    'end_date' => $r['end_date'],
                    'weekday' => $r['weekday'],
                    'time_in' => substr($r['time_in'], 0, 5),
                    'lunch_out' => $r['lunch_out'] ? substr($r['lunch_out'], 0, 5) : null,
                    'lunch_in' => $r['lunch_in'] ? substr($r['lunch_in'], 0, 5) : null,
                    'time_out' => substr($r['time_out'], 0, 5),
                    'status' => $r['status'],
                    'submitted_at' => $r['submitted_at'],
                    'dean_verified_at' => $r['dean_verified_at'],
                    'super_admin_approved_at' => $r['super_admin_approved_at'],
                    'verified_at' => $r['verified_at'],
                    'verified_by' => $r['verified_by'] ? (int) $r['verified_by'] : null,
                    'rejected_at' => $r['rejected_at'],
                    'rejection_reason' => $r['rejection_reason'],
                ];
            }
            echo json_encode(['success' => true, 'requests' => $list]);
            exit;
        }

        case 'list_for_dean': {
            $canList = ($isDean && $deanDepartment !== '') || $hasScopeAssignments;
            if (!$canList) {
                echo json_encode(['success' => false, 'message' => 'Only deans or assigned personnel can list official time requests']);
                exit;
            }
            if ($isDean && $deanDepartment !== '') {
                $deanListSql = "SELECT otr.id, otr.employee_id, otr.start_date, otr.end_date, otr.weekday, otr.time_in, otr.lunch_out, otr.lunch_in, otr.time_out, otr.submitted_at,
                    u.first_name, u.last_name, fp.department, fp.designation
                    FROM official_time_requests otr
                    JOIN faculty_profiles fp ON fp.employee_id = otr.employee_id
                    JOIN users u ON u.id = fp.user_id
                    WHERE otr.status = 'pending_dean' AND fp.department = ?";
                $deanListParams = [$deanDepartment];
                if ($currentUserEmployeeId !== '') {
                    $deanListSql .= " AND otr.employee_id != ?";
                    $deanListParams[] = $currentUserEmployeeId;
                }
                $deanListSql .= " ORDER BY otr.submitted_at ASC";
                $stmt = $db->prepare($deanListSql);
                $stmt->execute($deanListParams);
            } else {
                if (empty($scopeEmployeeIds)) {
                    echo json_encode(['success' => true, 'requests' => []]);
                    exit;
                }
                $placeholders = implode(',', array_fill(0, count($scopeEmployeeIds), '?'));
                $stmt = $db->prepare("SELECT otr.id, otr.employee_id, otr.start_date, otr.end_date, otr.weekday, otr.time_in, otr.lunch_out, otr.lunch_in, otr.time_out, otr.submitted_at,
                    u.first_name, u.last_name, fp.department, fp.designation
                    FROM official_time_requests otr
                    JOIN faculty_profiles fp ON fp.employee_id = otr.employee_id
                    JOIN users u ON u.id = fp.user_id
                    WHERE otr.status = 'pending_dean' AND otr.employee_id IN ($placeholders)
                    ORDER BY otr.submitted_at ASC");
                $stmt->execute($scopeEmployeeIds);
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $list = [];
            foreach ($rows as $r) {
                $list[] = [
                    'id' => (int) $r['id'],
                    'employee_id' => $r['employee_id'],
                    'employee_name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                    'department' => trim($r['department'] ?? ''),
                    'designation' => trim($r['designation'] ?? ''),
                    'start_date' => $r['start_date'],
                    'end_date' => $r['end_date'],
                    'weekday' => $r['weekday'],
                    'time_in' => substr($r['time_in'], 0, 5),
                    'lunch_out' => $r['lunch_out'] ? substr($r['lunch_out'], 0, 5) : null,
                    'lunch_in' => $r['lunch_in'] ? substr($r['lunch_in'], 0, 5) : null,
                    'time_out' => substr($r['time_out'], 0, 5),
                    'submitted_at' => $r['submitted_at'],
                ];
            }
            echo json_encode(['success' => true, 'requests' => $list]);
            exit;
        }

        case 'list_for_dean_grouped': {
            $canList = ($isDean && $deanDepartment !== '') || $hasScopeAssignments;
            if (!$canList) {
                echo json_encode(['success' => false, 'message' => 'Only deans or assigned personnel can list official time requests']);
                exit;
            }
            if ($isDean && $deanDepartment !== '') {
                $deanGroupSql = "SELECT otr.id, otr.employee_id, otr.start_date, otr.end_date, otr.weekday, otr.time_in, otr.lunch_out, otr.lunch_in, otr.time_out, otr.submitted_at,
                    u.first_name, u.last_name
                    FROM official_time_requests otr
                    JOIN faculty_profiles fp ON fp.employee_id = otr.employee_id
                    JOIN users u ON u.id = fp.user_id
                    WHERE otr.status = 'pending_dean' AND fp.department = ?";
                $deanGroupParams = [$deanDepartment];
                if ($currentUserEmployeeId !== '') {
                    $deanGroupSql .= " AND otr.employee_id != ?";
                    $deanGroupParams[] = $currentUserEmployeeId;
                }
                $deanGroupSql .= " ORDER BY otr.submitted_at ASC";
                $stmt = $db->prepare($deanGroupSql);
                $stmt->execute($deanGroupParams);
            } else {
                if (empty($scopeEmployeeIds)) {
                    echo json_encode(['success' => true, 'groups' => []]);
                    exit;
                }
                $placeholders = implode(',', array_fill(0, count($scopeEmployeeIds), '?'));
                $stmt = $db->prepare("SELECT otr.id, otr.employee_id, otr.start_date, otr.end_date, otr.weekday, otr.time_in, otr.lunch_out, otr.lunch_in, otr.time_out, otr.submitted_at,
                    u.first_name, u.last_name
                    FROM official_time_requests otr
                    JOIN faculty_profiles fp ON fp.employee_id = otr.employee_id
                    JOIN users u ON u.id = fp.user_id
                    WHERE otr.status = 'pending_dean' AND otr.employee_id IN ($placeholders)
                    ORDER BY otr.submitted_at ASC");
                $stmt->execute($scopeEmployeeIds);
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $grouped = [];
            foreach ($rows as $r) {
                $empId = $r['employee_id'];
                if (!isset($grouped[$empId])) {
                    $grouped[$empId] = [
                        'employee_id' => $empId,
                        'employee_name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                        'requests' => [],
                    ];
                }
                $grouped[$empId]['requests'][] = [
                    'id' => (int) $r['id'],
                    'start_date' => $r['start_date'],
                    'end_date' => $r['end_date'],
                    'weekday' => $r['weekday'],
                    'time_in' => substr($r['time_in'], 0, 5),
                    'lunch_out' => $r['lunch_out'] ? substr($r['lunch_out'], 0, 5) : null,
                    'lunch_in' => $r['lunch_in'] ? substr($r['lunch_in'], 0, 5) : null,
                    'time_out' => substr($r['time_out'], 0, 5),
                    'submitted_at' => $r['submitted_at'],
                ];
            }
            echo json_encode(['success' => true, 'groups' => array_values($grouped)]);
            exit;
        }

        case 'endorse': {
            $canEndorse = ($isDean && $deanDepartment !== '') || $hasScopeAssignments;
            if (!$canEndorse) {
                echo json_encode(['success' => false, 'message' => 'Only deans or assigned personnel can endorse official time requests']);
                exit;
            }
            $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
                exit;
            }
            if ($isDean && $deanDepartment !== '') {
                $deanSql = "SELECT otr.id FROM official_time_requests otr
                    JOIN faculty_profiles fp ON fp.employee_id = otr.employee_id
                    WHERE otr.id = ? AND otr.status = 'pending_dean' AND fp.department = ?";
                $deanParams = [$id, $deanDepartment];
                if ($currentUserEmployeeId !== '') {
                    $deanSql .= " AND otr.employee_id != ?";
                    $deanParams[] = $currentUserEmployeeId;
                }
                $stmt = $db->prepare($deanSql);
                $stmt->execute($deanParams);
            } else {
                if (empty($scopeEmployeeIds)) {
                    echo json_encode(['success' => false, 'message' => 'Request not found or not in your scope']);
                    exit;
                }
                $placeholders = implode(',', array_fill(0, count($scopeEmployeeIds), '?'));
                $stmt = $db->prepare("SELECT otr.id FROM official_time_requests otr
                    WHERE otr.id = ? AND otr.status = 'pending_dean' AND otr.employee_id IN ($placeholders)");
                $stmt->execute(array_merge([$id], $scopeEmployeeIds));
            }
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Request not found or not in your scope']);
                exit;
            }
            $stmt = $db->prepare("UPDATE official_time_requests SET status = 'pending_super_admin', dean_verified_at = NOW(), dean_verified_by = ? WHERE id = ?");
            $stmt->execute([$_SESSION['user_id'], $id]);
            echo json_encode(['success' => true, 'message' => 'Request endorsed. It will be reviewed by HR.']);
            exit;
        }

        case 'endorse_batch': {
            $canEndorse = ($isDean && $deanDepartment !== '') || $hasScopeAssignments;
            if (!$canEndorse) {
                echo json_encode(['success' => false, 'message' => 'Only deans or assigned personnel can endorse official time requests']);
                exit;
            }
            $employee_id = trim($_POST['employee_id'] ?? $_GET['employee_id'] ?? '');
            if (empty($employee_id)) {
                echo json_encode(['success' => false, 'message' => 'Employee ID is required']);
                exit;
            }
            if ($isDean && $deanDepartment !== '') {
                if ($employee_id === $currentUserEmployeeId) {
                    echo json_encode(['success' => false, 'message' => 'You cannot endorse your own official time. Only the person assigned to you can endorse it.']);
                    exit;
                }
                $stmt = $db->prepare("SELECT otr.id FROM official_time_requests otr
                    JOIN faculty_profiles fp ON fp.employee_id = otr.employee_id
                    WHERE otr.employee_id = ? AND otr.status = 'pending_dean' AND fp.department = ?");
                $stmt->execute([$employee_id, $deanDepartment]);
            } else {
                if (!in_array($employee_id, $scopeEmployeeIds)) {
                    echo json_encode(['success' => false, 'message' => 'Employee not in your scope']);
                    exit;
                }
                $stmt = $db->prepare("SELECT otr.id FROM official_time_requests otr
                    WHERE otr.employee_id = ? AND otr.status = 'pending_dean'");
                $stmt->execute([$employee_id]);
            }
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (empty($ids)) {
                echo json_encode(['success' => false, 'message' => 'No pending requests found for this employee in your scope']);
                exit;
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge([$_SESSION['user_id']], $ids);
            $stmt = $db->prepare("UPDATE official_time_requests SET status = 'pending_super_admin', dean_verified_at = NOW(), dean_verified_by = ? WHERE id IN ($placeholders)");
            $stmt->execute($params);
            $count = count($ids);
            echo json_encode(['success' => true, 'message' => $count . ' request(s) endorsed. They will be reviewed by HR.']);
            exit;
        }

        case 'reject_dean': {
            $canReject = ($isDean && $deanDepartment !== '') || $hasScopeAssignments;
            if (!$canReject) {
                echo json_encode(['success' => false, 'message' => 'Only deans or assigned personnel can reject official time requests']);
                exit;
            }
            $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
            $reason = trim($_POST['rejection_reason'] ?? $_GET['rejection_reason'] ?? '');
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
                exit;
            }
            $reqStmt = $db->prepare("SELECT otr.employee_id, fp.user_id, u.email, u.first_name, u.last_name FROM official_time_requests otr JOIN faculty_profiles fp ON fp.employee_id = otr.employee_id JOIN users u ON u.id = fp.user_id WHERE otr.id = ? AND otr.status = 'pending_dean' LIMIT 1");
            $reqStmt->execute([$id]);
            $reqEmp = $reqStmt->fetch(PDO::FETCH_ASSOC);

            if ($isDean && $deanDepartment !== '') {
                $deanSql = "SELECT otr.id FROM official_time_requests otr JOIN faculty_profiles fp ON fp.employee_id = otr.employee_id WHERE otr.id = ? AND otr.status = 'pending_dean' AND fp.department = ?";
                $deanParams = [$id, $deanDepartment];
                if ($currentUserEmployeeId !== '') {
                    $deanSql .= " AND otr.employee_id != ?";
                    $deanParams[] = $currentUserEmployeeId;
                }
                $stmt = $db->prepare($deanSql);
                $stmt->execute($deanParams);
            } else {
                if (empty($scopeEmployeeIds)) {
                    echo json_encode(['success' => false, 'message' => 'Request not found or not in your scope']);
                    exit;
                }
                $placeholders = implode(',', array_fill(0, count($scopeEmployeeIds), '?'));
                $stmt = $db->prepare("SELECT otr.id FROM official_time_requests otr WHERE otr.id = ? AND otr.status = 'pending_dean' AND otr.employee_id IN ($placeholders)");
                $stmt->execute(array_merge([$id], $scopeEmployeeIds));
            }
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Request not found or not in your scope']);
                exit;
            }

            $stmt = $db->prepare("UPDATE official_time_requests SET status = 'rejected', rejected_at = NOW(), rejected_by = ?, rejection_reason = ? WHERE id = ? AND status = 'pending_dean'");
            $stmt->execute([$_SESSION['user_id'], $reason ?: null, $id]);
            if ($stmt->rowCount() === 0) {
                echo json_encode(['success' => false, 'message' => 'Request not found or already processed']);
                exit;
            }
            echo json_encode(['success' => true, 'message' => 'Official time request rejected.']);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                flush();
            }
            ignore_user_abort(true);
            if ($reqEmp && !empty($reqEmp['email'])) {
                $empName = trim(($reqEmp['first_name'] ?? '') . ' ' . ($reqEmp['last_name'] ?? ''));
                $mailer = new Mailer();
                $mailer->sendOfficialTimeRejectedEmail($reqEmp['email'], $empName, $reason, 'your Dean');
                $nm = getNotificationManager();
                $nm->createNotification($reqEmp['user_id'], 'official_time', 'Official Time Rejected', 'Your official time request has been rejected by your Supervisor.' . ($reason ? ' Reason: ' . $reason : ''), 'declare_official_time.php', 'high');
            }
            exit;
        }

        case 'reject_employee_dean': {
            $canReject = ($isDean && $deanDepartment !== '') || $hasScopeAssignments;
            if (!$canReject) {
                echo json_encode(['success' => false, 'message' => 'Only deans or assigned personnel can reject official time requests']);
                exit;
            }
            $employee_id = trim($_POST['employee_id'] ?? $_GET['employee_id'] ?? '');
            $reason = trim($_POST['rejection_reason'] ?? $_GET['rejection_reason'] ?? '');
            if (empty($employee_id)) {
                echo json_encode(['success' => false, 'message' => 'Employee ID is required']);
                exit;
            }
            if ($isDean && $deanDepartment !== '') {
                if ($employee_id === $currentUserEmployeeId) {
                    echo json_encode(['success' => false, 'message' => 'You cannot reject your own official time.']);
                    exit;
                }
                $stmt = $db->prepare("SELECT otr.id FROM official_time_requests otr JOIN faculty_profiles fp ON fp.employee_id = otr.employee_id WHERE otr.employee_id = ? AND otr.status = 'pending_dean' AND fp.department = ?");
                $stmt->execute([$employee_id, $deanDepartment]);
            } else {
                if (!in_array($employee_id, $scopeEmployeeIds)) {
                    echo json_encode(['success' => false, 'message' => 'Employee not in your scope']);
                    exit;
                }
                $stmt = $db->prepare("SELECT otr.id FROM official_time_requests otr WHERE otr.employee_id = ? AND otr.status = 'pending_dean'");
                $stmt->execute([$employee_id]);
            }
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (empty($ids)) {
                echo json_encode(['success' => false, 'message' => 'No pending requests found for this employee']);
                exit;
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("UPDATE official_time_requests SET status = 'rejected', rejected_at = NOW(), rejected_by = ?, rejection_reason = ? WHERE id IN ($placeholders) AND status = 'pending_dean'");
            $stmt->execute(array_merge([$_SESSION['user_id'], $reason ?: null], $ids));
            $reqStmt = $db->prepare("SELECT fp.user_id, u.email, u.first_name, u.last_name FROM faculty_profiles fp JOIN users u ON u.id = fp.user_id WHERE fp.employee_id = ? LIMIT 1");
            $reqStmt->execute([$employee_id]);
            $reqEmp = $reqStmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'message' => count($ids) . ' request(s) rejected.']);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                flush();
            }
            ignore_user_abort(true);
            if ($reqEmp && !empty($reqEmp['email'])) {
                $empName = trim(($reqEmp['first_name'] ?? '') . ' ' . ($reqEmp['last_name'] ?? ''));
                $mailer = new Mailer();
                $mailer->sendOfficialTimeRejectedEmail($reqEmp['email'], $empName, $reason, 'your Dean');
                $nm = getNotificationManager();
                $nm->createNotification($reqEmp['user_id'], 'official_time', 'Official Time Rejected', 'Your official time request(s) have been rejected by your Supervisor.' . ($reason ? ' Reason: ' . $reason : ''), 'declare_official_time.php', 'high');
            }
            exit;
        }

        case 'endorse_all': {
            $canEndorse = ($isDean && $deanDepartment !== '') || $hasScopeAssignments;
            if (!$canEndorse) {
                echo json_encode(['success' => false, 'message' => 'Only deans or assigned personnel can endorse official time requests']);
                exit;
            }
            if ($isDean && $deanDepartment !== '') {
                $deanSql = "SELECT otr.id FROM official_time_requests otr JOIN faculty_profiles fp ON fp.employee_id = otr.employee_id WHERE otr.status = 'pending_dean' AND fp.department = ?";
                $deanParams = [$deanDepartment];
                if ($currentUserEmployeeId !== '') {
                    $deanSql .= " AND otr.employee_id != ?";
                    $deanParams[] = $currentUserEmployeeId;
                }
                $stmt = $db->prepare($deanSql);
                $stmt->execute($deanParams);
            } else {
                if (empty($scopeEmployeeIds)) {
                    echo json_encode(['success' => true, 'message' => '0 request(s) endorsed.', 'count' => 0]);
                    exit;
                }
                $placeholders = implode(',', array_fill(0, count($scopeEmployeeIds), '?'));
                $stmt = $db->prepare("SELECT otr.id FROM official_time_requests otr WHERE otr.status = 'pending_dean' AND otr.employee_id IN ($placeholders)");
                $stmt->execute($scopeEmployeeIds);
            }
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (empty($ids)) {
                echo json_encode(['success' => true, 'message' => 'No pending requests to endorse.', 'count' => 0]);
                exit;
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge([$_SESSION['user_id']], $ids);
            $stmt = $db->prepare("UPDATE official_time_requests SET status = 'pending_super_admin', dean_verified_at = NOW(), dean_verified_by = ? WHERE id IN ($placeholders)");
            $stmt->execute($params);
            $count = count($ids);
            echo json_encode(['success' => true, 'message' => $count . ' request(s) endorsed. They will be reviewed by HR.', 'count' => $count]);
            exit;
        }

        case 'list_pending_super_admin': {
            if (!$isAdmin) {
                echo json_encode(['success' => false, 'message' => 'Admin access required to view pending approvals']);
                exit;
            }
            $stmt = $db->query("SELECT otr.id, otr.employee_id, otr.start_date, otr.end_date, otr.weekday, otr.time_in, otr.lunch_out, otr.lunch_in, otr.time_out, otr.submitted_at, otr.dean_verified_at,
                u.first_name, u.last_name, u.user_type, fp.department
                FROM official_time_requests otr
                JOIN faculty_profiles fp ON fp.employee_id = otr.employee_id
                JOIN users u ON u.id = fp.user_id
                WHERE otr.status = 'pending_super_admin'
                ORDER BY otr.dean_verified_at IS NOT NULL DESC, otr.submitted_at ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $list = [];
            foreach ($rows as $r) {
                $list[] = [
                    'id' => (int) $r['id'],
                    'employee_id' => $r['employee_id'],
                    'employee_name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                    'user_type' => $r['user_type'],
                    'department' => $r['department'],
                    'start_date' => $r['start_date'],
                    'end_date' => $r['end_date'],
                    'weekday' => $r['weekday'],
                    'time_in' => substr($r['time_in'], 0, 5),
                    'lunch_out' => $r['lunch_out'] ? substr($r['lunch_out'], 0, 5) : null,
                    'lunch_in' => $r['lunch_in'] ? substr($r['lunch_in'], 0, 5) : null,
                    'time_out' => substr($r['time_out'], 0, 5),
                    'submitted_at' => $r['submitted_at'],
                    'dean_verified_at' => $r['dean_verified_at'],
                ];
            }
            echo json_encode(['success' => true, 'requests' => $list]);
            exit;
        }

        case 'list_pending_super_admin_grouped': {
            if (!$isAdmin) {
                echo json_encode(['success' => false, 'message' => 'Admin access required to view pending approvals']);
                exit;
            }
            $filterName = trim($_GET['name'] ?? '');
            $filterDepartment = trim($_GET['department'] ?? '');
            $filterDesignation = trim($_GET['designation'] ?? '');
            $filterDate = trim($_GET['date'] ?? '');

            $sql = "SELECT otr.id, otr.employee_id, otr.start_date, otr.end_date, otr.weekday, otr.time_in, otr.lunch_out, otr.lunch_in, otr.time_out, otr.submitted_at, otr.dean_verified_at, otr.dean_verified_by,
                verifier_u.first_name AS verifier_first_name, verifier_u.last_name AS verifier_last_name,
                u.first_name, u.last_name, u.user_type, fp.department, fp.designation
                FROM official_time_requests otr
                JOIN faculty_profiles fp ON fp.employee_id = otr.employee_id
                JOIN users u ON u.id = fp.user_id
                LEFT JOIN users verifier_u ON verifier_u.id = otr.dean_verified_by
                WHERE otr.status = 'pending_super_admin'";
            $params = [];

            if ($filterName !== '') {
                $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR CONCAT(u.last_name, ' ', u.first_name) LIKE ? OR otr.employee_id LIKE ?)";
                $term = '%' . $filterName . '%';
                $params = array_merge($params, [$term, $term, $term, $term, $term]);
            }
            if ($filterDepartment !== '') {
                $sql .= " AND fp.department = ?";
                $params[] = $filterDepartment;
            }
            if ($filterDesignation !== '') {
                $sql .= " AND fp.designation = ?";
                $params[] = $filterDesignation;
            }
            if ($filterDate !== '') {
                $dateObj = DateTime::createFromFormat('Y-m-d', $filterDate);
                if ($dateObj && $dateObj->format('Y-m-d') === $filterDate) {
                    $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                    $weekday = $weekdays[(int)$dateObj->format('w')];
                    $sql .= " AND otr.start_date <= ? AND (otr.end_date IS NULL OR otr.end_date >= ?) AND otr.weekday = ?";
                    $params = array_merge($params, [$filterDate, $filterDate, $weekday]);
                } else {
                    $sql .= " AND otr.start_date <= ? AND (otr.end_date IS NULL OR otr.end_date >= ?)";
                    $params = array_merge($params, [$filterDate, $filterDate]);
                }
            }

            $sql .= " ORDER BY otr.dean_verified_at IS NOT NULL DESC, otr.submitted_at ASC";

            if (!empty($params)) {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
            } else {
                $stmt = $db->query($sql);
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $grouped = [];
            foreach ($rows as $r) {
                $empId = $r['employee_id'];
                if (!isset($grouped[$empId])) {
                    $grouped[$empId] = [
                        'employee_id' => $empId,
                        'employee_name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                        'user_type' => $r['user_type'],
                        'department' => $r['department'],
                        'designation' => $r['designation'] ?? '',
                        'requests' => [],
                    ];
                }
                $verifierName = ($r['verifier_first_name'] ?? $r['verifier_last_name'] ?? '') ? trim(($r['verifier_first_name'] ?? '') . ' ' . ($r['verifier_last_name'] ?? '')) : null;
                $grouped[$empId]['requests'][] = [
                    'id' => (int) $r['id'],
                    'start_date' => $r['start_date'],
                    'end_date' => $r['end_date'],
                    'weekday' => $r['weekday'],
                    'time_in' => substr($r['time_in'], 0, 5),
                    'lunch_out' => $r['lunch_out'] ? substr($r['lunch_out'], 0, 5) : null,
                    'lunch_in' => $r['lunch_in'] ? substr($r['lunch_in'], 0, 5) : null,
                    'time_out' => substr($r['time_out'], 0, 5),
                    'submitted_at' => $r['submitted_at'],
                    'dean_verified_at' => $r['dean_verified_at'],
                    'verified_by' => $verifierName,
                    'verified_at' => $r['dean_verified_at'],
                ];
            }
            echo json_encode(['success' => true, 'groups' => array_values($grouped)]);
            exit;
        }

        case 'approve': {
            if (!$isAdmin) {
                echo json_encode(['success' => false, 'message' => 'Admin access required to approve official time requests']);
                exit;
            }
            $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
                exit;
            }
            $stmt = $db->prepare("SELECT * FROM official_time_requests WHERE id = ? AND status = 'pending_super_admin'");
            $stmt->execute([$id]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$req) {
                echo json_encode(['success' => false, 'message' => 'Request not found or already processed']);
                exit;
            }

            $db->beginTransaction();
            try {
                $empId = $req['employee_id'];
                $newStart = $req['start_date'];
                $newEnd = $req['end_date'];
                $weekday = $req['weekday'];

                // Replace current official time: end or remove overlapping entries for this employee + weekday
                $overlapStmt = $db->prepare("SELECT id, start_date, end_date FROM employee_official_times
                    WHERE employee_id = ? AND weekday = ?
                    AND (
                        (? IS NOT NULL AND end_date IS NOT NULL AND ? <= end_date AND start_date <= ?)
                        OR (? IS NOT NULL AND end_date IS NULL AND ? >= start_date)
                        OR (? IS NULL AND end_date IS NOT NULL AND ? <= end_date)
                        OR (? IS NULL AND end_date IS NULL)
                    )");
                $overlapStmt->execute([
                    $empId, $weekday,
                    $newEnd, $newStart, $newEnd,
                    $newEnd, $newEnd,
                    $newEnd, $newStart,
                    $newEnd
                ]);
                $overlapping = $overlapStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($overlapping as $old) {
                    $oldStart = $old['start_date'];
                    $cutoff = date('Y-m-d', strtotime($newStart . ' -1 day'));
                    if ($oldStart < $newStart && $cutoff >= $oldStart) {
                        // End the old one the day before the new one starts
                        $updOld = $db->prepare("UPDATE employee_official_times SET end_date = ? WHERE id = ?");
                        $updOld->execute([$cutoff, $old['id']]);
                    } else {
                        // Old starts on or after new start, or cutoff would be invalid: new replaces it, delete
                        $delOld = $db->prepare("DELETE FROM employee_official_times WHERE id = ?");
                        $delOld->execute([$old['id']]);
                    }
                }

                $ins = $db->prepare("INSERT INTO employee_official_times (employee_id, start_date, end_date, weekday, time_in, lunch_out, lunch_in, time_out) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([
                    $empId,
                    $newStart,
                    $newEnd,
                    $weekday,
                    $req['time_in'],
                    $req['lunch_out'],
                    $req['lunch_in'],
                    $req['time_out']
                ]);
                $upd = $db->prepare("UPDATE official_time_requests SET status = 'approved', super_admin_approved_at = NOW(), super_admin_approved_by = ?, verified_at = NOW(), verified_by = ? WHERE id = ?");
                $upd->execute([$_SESSION['user_id'], $_SESSION['user_id'], $id]);
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }

            $empStmt = $db->prepare("SELECT fp.user_id, u.email, u.first_name, u.last_name FROM faculty_profiles fp JOIN users u ON u.id = fp.user_id WHERE fp.employee_id = ? LIMIT 1");
            $empStmt->execute([$empId]);
            $emp = $empStmt->fetch(PDO::FETCH_ASSOC);
            if ($emp && !empty($emp['email'])) {
                $empName = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
                $mailer = new Mailer();
                $mailer->sendOfficialTimeApprovedEmail($emp['email'], $empName, $req['weekday'] . ' (' . $req['start_date'] . ' to ' . ($req['end_date'] ?? 'Ongoing') . ')');
                $nm = getNotificationManager();
                $nm->createNotification($emp['user_id'], 'official_time', 'Official Time Approved', 'Your official time request has been approved. It is now your working time.', 'declare_official_time.php', 'normal');
            }

            echo json_encode(['success' => true, 'message' => 'Official time approved. It is now the employee\'s working time.']);
            exit;
        }

        case 'approve_batch': {
            if (!$isAdmin) {
                echo json_encode(['success' => false, 'message' => 'Admin access required to approve official time requests']);
                exit;
            }
            $employee_id = trim($_POST['employee_id'] ?? $_GET['employee_id'] ?? '');
            if (empty($employee_id)) {
                echo json_encode(['success' => false, 'message' => 'Employee ID is required']);
                exit;
            }
            $stmt = $db->prepare("SELECT * FROM official_time_requests WHERE employee_id = ? AND status = 'pending_super_admin' ORDER BY weekday");
            $stmt->execute([$employee_id]);
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($requests)) {
                echo json_encode(['success' => false, 'message' => 'No pending requests found for this employee']);
                exit;
            }

            $db->beginTransaction();
            try {
                foreach ($requests as $req) {
                    $empId = $req['employee_id'];
                    $newStart = $req['start_date'];
                    $newEnd = $req['end_date'];
                    $weekday = $req['weekday'];

                    $overlapStmt = $db->prepare("SELECT id, start_date, end_date FROM employee_official_times
                        WHERE employee_id = ? AND weekday = ?
                        AND (
                            (? IS NOT NULL AND end_date IS NOT NULL AND ? <= end_date AND start_date <= ?)
                            OR (? IS NOT NULL AND end_date IS NULL AND ? >= start_date)
                            OR (? IS NULL AND end_date IS NOT NULL AND ? <= end_date)
                            OR (? IS NULL AND end_date IS NULL)
                        )");
                    $overlapStmt->execute([
                        $empId, $weekday,
                        $newEnd, $newStart, $newEnd,
                        $newEnd, $newEnd,
                        $newEnd, $newStart,
                        $newEnd
                    ]);
                    $overlapping = $overlapStmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($overlapping as $old) {
                        $oldStart = $old['start_date'];
                        $cutoff = date('Y-m-d', strtotime($newStart . ' -1 day'));
                        if ($oldStart < $newStart && $cutoff >= $oldStart) {
                            $updOld = $db->prepare("UPDATE employee_official_times SET end_date = ? WHERE id = ?");
                            $updOld->execute([$cutoff, $old['id']]);
                        } else {
                            $delOld = $db->prepare("DELETE FROM employee_official_times WHERE id = ?");
                            $delOld->execute([$old['id']]);
                        }
                    }

                    $ins = $db->prepare("INSERT INTO employee_official_times (employee_id, start_date, end_date, weekday, time_in, lunch_out, lunch_in, time_out) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([
                        $empId,
                        $newStart,
                        $newEnd,
                        $weekday,
                        $req['time_in'],
                        $req['lunch_out'],
                        $req['lunch_in'],
                        $req['time_out']
                    ]);
                    $upd = $db->prepare("UPDATE official_time_requests SET status = 'approved', super_admin_approved_at = NOW(), super_admin_approved_by = ?, verified_at = NOW(), verified_by = ? WHERE id = ?");
                    $upd->execute([$_SESSION['user_id'], $_SESSION['user_id'], $req['id']]);
                }
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }

            $empStmt = $db->prepare("SELECT fp.user_id, u.email, u.first_name, u.last_name FROM faculty_profiles fp JOIN users u ON u.id = fp.user_id WHERE fp.employee_id = ? LIMIT 1");
            $empStmt->execute([$employee_id]);
            $emp = $empStmt->fetch(PDO::FETCH_ASSOC);
            if ($emp && !empty($emp['email'])) {
                $empName = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
                $days = array_unique(array_column($requests, 'weekday'));
                $summary = count($days) . ' day(s): ' . implode(', ', $days);
                $mailer = new Mailer();
                $mailer->sendOfficialTimeApprovedEmail($emp['email'], $empName, $summary);
                $nm = getNotificationManager();
                $nm->createNotification($emp['user_id'], 'official_time', 'Official Time Approved', 'Your official time request(s) have been approved. They are now your working time.', 'declare_official_time.php', 'normal');
            }

            $count = count($requests);
            echo json_encode(['success' => true, 'message' => $count . ' official time(s) approved. They are now the employee\'s working time.']);
            exit;
        }

        case 'reject': {
            if (!$isAdmin) {
                echo json_encode(['success' => false, 'message' => 'Admin access required to reject official time requests']);
                exit;
            }
            $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
            $reason = trim($_POST['rejection_reason'] ?? $_GET['rejection_reason'] ?? '');
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
                exit;
            }
            $reqStmt = $db->prepare("SELECT otr.employee_id, fp.user_id, u.email, u.first_name, u.last_name FROM official_time_requests otr JOIN faculty_profiles fp ON fp.employee_id = otr.employee_id JOIN users u ON u.id = fp.user_id WHERE otr.id = ? AND otr.status = 'pending_super_admin' LIMIT 1");
            $reqStmt->execute([$id]);
            $reqEmp = $reqStmt->fetch(PDO::FETCH_ASSOC);
            $stmt = $db->prepare("UPDATE official_time_requests SET status = 'rejected', rejected_at = NOW(), rejected_by = ?, rejection_reason = ? WHERE id = ? AND status = 'pending_super_admin'");
            $stmt->execute([$_SESSION['user_id'], $reason ?: null, $id]);
            if ($stmt->rowCount() === 0) {
                echo json_encode(['success' => false, 'message' => 'Request not found or already processed']);
                exit;
            }
            echo json_encode(['success' => true, 'message' => 'Official time request rejected.']);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                flush();
            }
            ignore_user_abort(true);
            if ($reqEmp && !empty($reqEmp['email'])) {
                $empName = trim(($reqEmp['first_name'] ?? '') . ' ' . ($reqEmp['last_name'] ?? ''));
                $mailer = new Mailer();
                $mailer->sendOfficialTimeRejectedEmail($reqEmp['email'], $empName, $reason);
                $nm = getNotificationManager();
                $nm->createNotification($reqEmp['user_id'], 'official_time', 'Official Time Rejected', 'Your official time request has been rejected.' . ($reason ? ' Reason: ' . $reason : ''), 'declare_official_time.php', 'high');
            }
            exit;
        }

        case 'delete_my_request': {
            // Faculty or staff: delete own pending request (only while pending)
            if (!$isFaculty) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit;
            }
            $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
                exit;
            }
            $stmt = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
            $stmt->execute([$_SESSION['user_id']]);
            $emp = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$emp || empty($emp['employee_id'])) {
                echo json_encode(['success' => false, 'message' => 'Employee ID not found']);
                exit;
            }
            $employee_id = $emp['employee_id'];

            $stmt = $db->prepare("DELETE FROM official_time_requests WHERE id = ? AND employee_id = ? AND status IN ('pending_dean', 'pending_super_admin')");
            $stmt->execute([$id, $employee_id]);
            if ($stmt->rowCount() === 0) {
                echo json_encode(['success' => false, 'message' => 'Request not found, already processed, or you cannot delete it']);
                exit;
            }
            echo json_encode(['success' => true, 'message' => 'Official time request deleted.']);
            exit;
        }

        case 'reject_batch': {
            if (!$isAdmin) {
                echo json_encode(['success' => false, 'message' => 'Admin access required to reject official time requests']);
                exit;
            }
            $employee_id = trim($_POST['employee_id'] ?? $_GET['employee_id'] ?? '');
            $reason = trim($_POST['rejection_reason'] ?? $_GET['rejection_reason'] ?? '');
            if (empty($employee_id)) {
                echo json_encode(['success' => false, 'message' => 'Employee ID is required']);
                exit;
            }
            $empStmt = $db->prepare("SELECT fp.user_id, u.email, u.first_name, u.last_name FROM faculty_profiles fp JOIN users u ON u.id = fp.user_id WHERE fp.employee_id = ? LIMIT 1");
            $empStmt->execute([$employee_id]);
            $emp = $empStmt->fetch(PDO::FETCH_ASSOC);
            $stmt = $db->prepare("UPDATE official_time_requests SET status = 'rejected', rejected_at = NOW(), rejected_by = ?, rejection_reason = ? WHERE employee_id = ? AND status = 'pending_super_admin'");
            $stmt->execute([$_SESSION['user_id'], $reason ?: null, $employee_id]);
            $count = $stmt->rowCount();
            if ($count === 0) {
                echo json_encode(['success' => false, 'message' => 'No pending requests found for this employee']);
                exit;
            }
            echo json_encode(['success' => true, 'message' => $count . ' official time request(s) rejected.']);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                flush();
            }
            ignore_user_abort(true);
            if ($emp && !empty($emp['email'])) {
                $empName = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
                $mailer = new Mailer();
                $mailer->sendOfficialTimeRejectedEmail($emp['email'], $empName, $reason);
                $nm = getNotificationManager();
                $nm->createNotification($emp['user_id'], 'official_time', 'Official Time Rejected', 'Your official time request(s) have been rejected.' . ($reason ? ' Reason: ' . $reason : ''), 'declare_official_time.php', 'high');
            }
            exit;
        }

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log('official_time_requests_api: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
