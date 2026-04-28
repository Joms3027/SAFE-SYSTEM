<?php
/**
 * DTR Verification API (admin and super_admin).
 * POST: Verify DTR submissions for faculty with designation only.
 * Employee DTRs are verified by the assigned pardon opener (admin/settings opener section).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';

requireAdmin();
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
        echo json_encode(['success' => false, 'message' => 'Verification feature is not configured.']);
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

    // Verify unverified DTRs for faculty with designation only (staff verified by assigned pardon opener)
    $params = [$dateFrom, $dateTo];
    $whereClause = "ds.log_date >= ? AND ds.log_date <= ?
        AND ds.dean_verified_at IS NULL
        AND COALESCE(TRIM(fp.designation), '') != ''
        AND u.user_type = 'faculty'";

    if ($employeeId !== '') {
        $whereClause .= " AND fp.employee_id = ?";
        $params[] = $employeeId;
    }

    $stmt = $db->prepare("
        UPDATE dtr_daily_submissions ds
        INNER JOIN faculty_profiles fp ON fp.user_id = ds.user_id
        INNER JOIN users u ON u.id = ds.user_id
        SET ds.dean_verified_at = NOW()
        WHERE $whereClause
    ");
    $stmt->execute($params);
    $affected = $stmt->rowCount();

    echo json_encode([
        'success' => true,
        'message' => $affected > 0
            ? "Successfully verified $affected DTR submission(s)."
            : 'No unverified DTRs to verify in the selected period.',
        'verified_count' => $affected,
    ]);
} catch (Exception $e) {
    error_log('admin/verify_dtr_api.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
