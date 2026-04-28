<?php
/**
 * DTR Verification API (Dean or assigned Pardon Opener).
 * POST: Batch verify DTR submissions.
 * - Dean: verifies faculty in their department.
 * - Pardon Opener: verifies staff assigned to them via admin/settings (opener section).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';

requireAuth();
// Allow faculty, staff, or admin/super_admin with pardon opener assignments (staff DTR verification)
if (!isFaculty() && !isStaff() && !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();

    $tableCheck = $db->query("SHOW TABLES LIKE 'dtr_daily_submissions'");
    if (!$tableCheck || $tableCheck->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'DTR submissions are not configured.']);
        exit;
    }

    $stmt = $db->query("SHOW COLUMNS FROM dtr_daily_submissions LIKE 'dean_verified_at'");
    if (!$stmt || $stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Verification feature is not configured. Please run the database migration.']);
        exit;
    }

    $stmt = $db->prepare("SELECT fp.designation, fp.department FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $userProfile = $stmt->fetch(PDO::FETCH_ASSOC);

    $isDean = $userProfile && strtolower(trim($userProfile['designation'] ?? '')) === 'dean';
    $deanDepartment = trim($userProfile['department'] ?? '');
    $hasPardonOpenerAssignments = hasPardonOpenerAssignments($_SESSION['user_id'], $db);

    // Get current user's employee_id - cannot verify own DTR (only assigned person can endorse)
    $currentUserEmployeeId = '';
    $stmtEmp = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
    $stmtEmp->execute([$_SESSION['user_id']]);
    $empRow = $stmtEmp->fetch(PDO::FETCH_ASSOC);
    if ($empRow && !empty($empRow['employee_id'])) {
        $currentUserEmployeeId = trim($empRow['employee_id']);
    }

    if (!$isDean && !$hasPardonOpenerAssignments) {
        echo json_encode(['success' => false, 'message' => 'Access denied. Only Deans or assigned personnel can verify DTRs.']);
        exit;
    }

    $dateFrom = isset($_POST['date_from']) ? trim($_POST['date_from']) : '';
    $dateTo = isset($_POST['date_to']) ? trim($_POST['date_to']) : '';
    $employeeId = isset($_POST['employee_id']) ? trim($_POST['employee_id']) : '';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date range. Provide date_from and date_to (YYYY-MM-DD).']);
        exit;
    }

    if (strtotime($dateFrom) > strtotime($dateTo)) {
        echo json_encode(['success' => false, 'message' => 'date_from must be before or equal to date_to.']);
        exit;
    }

    $affected = 0;

    if ($isDean && $deanDepartment !== '') {
        // Dean: verify faculty only (staff are verified by assigned pardon opener)
        // Exclude self: dean cannot verify their own DTR
        $params = [$dateFrom, $dateTo, $deanDepartment];
        $whereClause = "ds.log_date >= ? AND ds.log_date <= ?
            AND COALESCE(TRIM(fp.department), '') = ?
            AND ds.dean_verified_at IS NULL
            AND u.user_type = 'faculty'";
        if ($currentUserEmployeeId !== '') {
            $whereClause .= " AND fp.employee_id != ?";
            $params[] = $currentUserEmployeeId;
        }

        if ($employeeId !== '') {
            $whereClause .= " AND fp.employee_id = ?";
            $params[] = $employeeId;
        }

        $stmt = $db->prepare("
            UPDATE dtr_daily_submissions ds
            INNER JOIN faculty_profiles fp ON fp.user_id = ds.user_id
            INNER JOIN users u ON u.id = ds.user_id
            SET ds.dean_verified_at = NOW(), ds.dean_verified_by = ?
            WHERE $whereClause
        ");
        array_unshift($params, $_SESSION['user_id']);
        $stmt->execute($params);
        $affected = $stmt->rowCount();
    } elseif ($hasPardonOpenerAssignments) {
        // Pardon Opener: verify staff assigned to them (admin/settings opener section)
        // Exclude self: cannot verify own DTR (only assigned person can endorse)
        $scopeEmployeeIds = getEmployeeIdsInScope($_SESSION['user_id'], $db);
        if ($currentUserEmployeeId !== '' && in_array($currentUserEmployeeId, $scopeEmployeeIds)) {
            $scopeEmployeeIds = array_values(array_diff($scopeEmployeeIds, [$currentUserEmployeeId]));
        }
        $staffInScope = [];
        if (!empty($scopeEmployeeIds)) {
            $placeholders = implode(',', array_fill(0, count($scopeEmployeeIds), '?'));
            $stmt = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp
                JOIN users u ON fp.user_id = u.id
                WHERE u.user_type = 'staff' AND u.is_active = 1 AND fp.employee_id IN ($placeholders)");
            $stmt->execute($scopeEmployeeIds);
            $staffInScope = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        if (empty($staffInScope)) {
            echo json_encode(['success' => true, 'message' => 'No unverified DTRs to verify in the selected period.', 'verified_count' => 0]);
            exit;
        }
        $placeholders = implode(',', array_fill(0, count($staffInScope), '?'));
        $params = array_merge([$dateFrom, $dateTo], $staffInScope);
        $whereClause = "ds.log_date >= ? AND ds.log_date <= ?
            AND fp.employee_id IN ($placeholders)
            AND ds.dean_verified_at IS NULL";

        if ($employeeId !== '') {
            if (!in_array($employeeId, $staffInScope)) {
                echo json_encode(['success' => false, 'message' => 'Access denied. That employee is not in your scope.']);
                exit;
            }
            $whereClause .= " AND fp.employee_id = ?";
            $params[] = $employeeId;
        }

        $stmt = $db->prepare("
            UPDATE dtr_daily_submissions ds
            INNER JOIN faculty_profiles fp ON fp.user_id = ds.user_id
            SET ds.dean_verified_at = NOW(), ds.dean_verified_by = ?
            WHERE $whereClause
        ");
        array_unshift($params, $_SESSION['user_id']);
        $stmt->execute($params);
        $affected = $stmt->rowCount();
    } elseif ($isDean && $deanDepartment === '') {
        echo json_encode(['success' => false, 'message' => 'Your department is not set.']);
        exit;
    }

    // Notify admins when DTRs are verified so they appear on the admin side
    if ($affected > 0) {
        require_once __DIR__ . '/../includes/notifications.php';
        $notificationManager = getNotificationManager();
        $adminStmt = $db->prepare("SELECT id FROM users WHERE user_type IN ('admin', 'super_admin') AND is_active = 1");
        $adminStmt->execute();
        $msg = $affected === 1
            ? '1 DTR submission has been verified by the Immediate Supervisor and is now available in Admin.'
            : $affected . ' DTR submissions have been verified by the Immediate Supervisor and are now available in Admin.';
        foreach ($adminStmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
            $notificationManager->createNotification(
                $adminId,
                'dtr_verified',
                'DTR Verified',
                $msg,
                'dtr_submissions.php',
                'normal'
            );
        }
    }

    echo json_encode([
        'success' => true,
        'message' => $affected > 0
            ? "Successfully verified $affected DTR submission(s). They are now available in Admin."
            : 'No unverified DTRs to verify in the selected period.',
        'verified_count' => $affected,
    ]);
} catch (Exception $e) {
    error_log('verify_dtr_api.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
