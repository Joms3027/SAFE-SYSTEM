<?php
/**
 * DTR Submission API (Daily)
 * Policy: Each day's DTR must be submitted the next day or after (e.g., Monday's logs submitted Tuesday or later).
 * Attendance data is only visible to dean/admin for dates that have been submitted.
 * GET: returns submitted dates and dates available for submission. POST: submit DTR for a specific date.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';

requireFaculty();
header('Content-Type: application/json; charset=utf-8');
// User-specific JSON; never cache GET (stale submitted state after POST was breaking UI on refresh).
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$database = Database::getInstance();
$db = $database->getConnection();

try {
    // Ensure dtr_daily_submissions table exists
    $tableCheck = $db->query("SHOW TABLES LIKE 'dtr_daily_submissions'");
    if (!$tableCheck || $tableCheck->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'DTR submissions are not configured. Please contact admin.']);
        exit;
    }

    $today = date('Y-m-d');
    $userId = $_SESSION['user_id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
        $month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');
        $dateFromParam = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
        $dateToParam = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromParam) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToParam)) {
            $dateFrom = $dateFromParam;
            $dateTo = $dateToParam;
        } else {
            $dateFrom = sprintf('%04d-%02d-01', $year, $month);
            $lastDay = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
            $dateTo = sprintf('%04d-%02d-%02d', $year, $month, $lastDay);
        }

        $stmt = $db->prepare("SELECT log_date, submitted_at FROM dtr_daily_submissions WHERE user_id = ? AND log_date >= ? AND log_date <= ? ORDER BY log_date ASC");
        $stmt->execute([$userId, $dateFrom, $dateTo]);
        $submitted = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ld = $row['log_date'] ?? '';
            if ($ld === '') {
                continue;
            }
            $submitted[date('Y-m-d', strtotime($ld))] = $row['submitted_at'];
        }

        // Dates that can be submitted: at least 1 day old (yesterday or earlier), not yet submitted
        $canSubmit = [];
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $fromTs = strtotime($dateFrom);
        $toTs = strtotime($dateTo);
        for ($ts = $fromTs; $ts <= $toTs; $ts += 86400) {
            $logDate = date('Y-m-d', $ts);
            if ($logDate <= $yesterday && !isset($submitted[$logDate])) {
                $canSubmit[] = $logDate;
            }
        }

        // Encode empty as JSON object {} so clients never treat submitted as [] (truthy in JS).
        $submittedJson = $submitted === [] ? new stdClass() : $submitted;
        echo json_encode([
            'success' => true,
            'year' => $year,
            'month' => $month,
            'submitted' => $submittedJson,
            'canSubmit' => $canSubmit,
            'policy' => 'Submit each day\'s DTR the next day or after. Your Dean will verify it; after verification it becomes visible to Admin.',
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $logDate = isset($_POST['log_date']) ? trim($_POST['log_date']) : '';
        if ($logDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate)) {
            echo json_encode(['success' => false, 'message' => 'Invalid date. Provide log_date as YYYY-MM-DD.']);
            exit;
        }

        // Rule: can only submit if log_date is at least 1 day before today (next day or after)
        $logDateTs = strtotime($logDate);
        $yesterday = strtotime('-1 day', strtotime($today));
        if ($logDateTs > $yesterday) {
            echo json_encode(['success' => false, 'message' => 'You can only submit a day\'s DTR the next day or after. Example: Monday\'s logs must be submitted on Tuesday or later.']);
            exit;
        }

        if ($logDate > $today) {
            echo json_encode(['success' => false, 'message' => 'Cannot submit future dates.']);
            exit;
        }

        $stmt = $db->prepare("SELECT id FROM dtr_daily_submissions WHERE user_id = ? AND log_date = ? LIMIT 1");
        $stmt->execute([$userId, $logDate]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'You have already submitted your DTR for ' . date('F j, Y', $logDateTs) . '.']);
            exit;
        }

        // Faculty with designation → HR (super_admin) verifies. Staff → assigned pardon opener verifies. Faculty without designation → Dean verifies.
        $profileStmt = $db->prepare("SELECT fp.designation, fp.department, fp.employee_id, u.first_name, u.last_name, u.user_type FROM faculty_profiles fp JOIN users u ON fp.user_id = u.id WHERE fp.user_id = ? LIMIT 1");
        $profileStmt->execute([$userId]);
        $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);
        $designation = trim($profile['designation'] ?? '');
        $userType = $profile['user_type'] ?? 'faculty';
        $employeeId = trim($profile['employee_id'] ?? '');
        $directToSuperAdmin = ($userType === 'faculty' && $designation !== '');
        $isStaff = ($userType === 'staff');

        // HR (super_admin) verifies faculty with designation; pardon opener verifies staff; dean verifies faculty without designation
        $stmt = $db->prepare("INSERT INTO dtr_daily_submissions (user_id, log_date) VALUES (?, ?)");
        $stmt->execute([$userId, $logDate]);
        $submittedAt = date('Y-m-d H:i:s');

        require_once __DIR__ . '/../includes/notifications.php';
        $notificationManager = getNotificationManager();
        $employeeName = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
        $department = $profile['department'] ?? '';
        $dateLabel = date('F j, Y', $logDateTs);

        if ($isStaff) {
            // Staff: notify assigned pardon opener(s) from admin/settings opener section
            $openerIds = function_exists('getOpenerUserIdsForEmployee') ? getOpenerUserIdsForEmployee($employeeId, $db) : [];
            $staffDtrLink = 'faculty/dtr_submissions.php';
            foreach ($openerIds as $openerId) {
                $notificationManager->createNotification(
                    $openerId,
                    'dtr_submitted',
                    'DTR Submitted',
                    $employeeName . ' submitted their DTR for ' . $dateLabel . '. Verify in DTR Submissions.',
                    $staffDtrLink,
                    'normal'
                );
            }
        } elseif ($directToSuperAdmin) {
            // Faculty with designation: notify super_admin (HR)
            $adminStmt = $db->prepare("SELECT id FROM users WHERE user_type = 'super_admin' AND is_active = 1");
            $adminStmt->execute();
            foreach ($adminStmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
                $notificationManager->createNotification(
                    $adminId,
                    'dtr_submitted',
                    'DTR Submitted (HR)',
                    $employeeName . ' (' . ($designation ?: 'designation') . ') submitted DTR for ' . $dateLabel . '. Verify in DTR Submissions.',
                    'dtr_submissions.php',
                    'normal'
                );
            }
        } elseif ($department !== '') {
            // Faculty without designation: notify dean
            $deanStmt = $db->prepare("SELECT fp.user_id FROM faculty_profiles fp WHERE fp.department = ? AND LOWER(TRIM(COALESCE(fp.designation, ''))) = 'dean' LIMIT 1");
            $deanStmt->execute([$department]);
            $dean = $deanStmt->fetch(PDO::FETCH_ASSOC);
            if ($dean) {
                $notificationManager->createNotification(
                    $dean['user_id'],
                    'dtr_submitted',
                    'DTR Submitted',
                    $employeeName . ' submitted their DTR for ' . $dateLabel . '. Verify to make it available to Admin.',
                    'dtr_submissions.php',
                    'normal'
                );
            }
        }

        $successMessage = $isStaff
            ? 'DTR for ' . $dateLabel . ' submitted. Your assigned supervisor will verify it.'
            : ($directToSuperAdmin
                ? 'DTR for ' . $dateLabel . ' submitted. It will appear directly in HR for review.'
                : 'DTR for ' . $dateLabel . ' submitted to your Dean. It will appear in Admin after the Dean verifies it.');

        echo json_encode([
            'success' => true,
            'message' => $successMessage,
            'log_date' => $logDate,
            'submittedAt' => $submittedAt,
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
} catch (Exception $e) {
    error_log('DTR submit API error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
