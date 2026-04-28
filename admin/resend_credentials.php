<?php
/**
 * Resend Employee Login Credentials
 * 
 * This endpoint handles resending login credentials to employees.
 * It generates new passwords and sends them via email.
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/mailer.php';

// Set JSON header
header('Content-Type: application/json');

// Check if user is admin
try {
    requireAdmin();
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Access denied. Admin privileges required.'
    ]);
    exit;
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
    exit;
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // Get employee IDs from request
    $employeeIdsJson = $_POST['employee_ids'] ?? '[]';
    $employeeIds = json_decode($employeeIdsJson, true);
    
    if (empty($employeeIds) || !is_array($employeeIds)) {
        echo json_encode([
            'success' => false,
            'message' => 'No employees selected.'
        ]);
        exit;
    }
    
    // Build query based on scope
    $whereClause = "u.user_type IN ('faculty','staff')";
    $params = [];
    
    // Handle "all" scope
    if (in_array('all', $employeeIds)) {
        // Apply filters if provided
        if (!empty($_POST['status_filter'])) {
            $whereClause .= " AND u.is_active = ?";
            $params[] = ($_POST['status_filter'] === 'active') ? 1 : 0;
        }
        
        if (!empty($_POST['department_filter'])) {
            $whereClause .= " AND fp.department = ?";
            $params[] = $_POST['department_filter'];
        }
        
        if (!empty($_POST['search_filter'])) {
            $whereClause .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
            $searchTerm = "%" . $_POST['search_filter'] . "%";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
        }
    } else {
        // Specific employee IDs
        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
        $whereClause .= " AND u.id IN ($placeholders)";
        $params = array_merge($params, $employeeIds);
    }
    
    // Fetch employees
    $sql = "SELECT u.id, u.email, u.first_name, u.last_name, u.user_type, 
                   fp.employee_id
            FROM users u
            LEFT JOIN faculty_profiles fp ON u.id = fp.user_id
            WHERE $whereClause
            AND u.email IS NOT NULL AND u.email != ''";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($employees)) {
        echo json_encode([
            'success' => false,
            'message' => 'No employees found with valid email addresses.'
        ]);
        exit;
    }
    
    $mailer = new Mailer();
    $loginUrl = SITE_URL . "/login.php";
    $successCount = 0;
    $errorCount = 0;
    $errors = [];

    // ── Phase 1: Generate passwords & persist to DB ──────────────────────────
    // All DB writes happen first so no account is left with a changed password
    // but an unsent email if the SMTP session fails partway through.
    $emailQueue   = [];   // messages ready to hand to sendMailKeepAlive
    $idByEmail    = [];   // email -> employee id  (for audit logging)

    $updateStmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");

    foreach ($employees as $employee) {
        try {
            $newPassword    = bin2hex(random_bytes(4)); // 8 hex chars
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            $updateStmt->execute([$hashedPassword, $employee['id']]);

            $fullName    = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')) ?: 'Employee';
            $accountType = $employee['user_type'] ?? 'faculty';
            $empId       = $employee['employee_id'] ?? 'N/A';

            $emailQueue[] = [
                'to'      => $employee['email'],
                'toName'  => $fullName,
                'subject' => "Welcome to WPU SAFE SYSTEM - Your Account Credentials",
                'body'    => $mailer->buildAccountCreationEmailBody(
                                 $employee['email'], $fullName, $accountType, $empId, $newPassword
                             ),
                'isHtml'  => true,
            ];
            $idByEmail[$employee['email']] = $employee['id'];

        } catch (Exception $e) {
            $errorCount++;
            $errors[] = "Error processing {$employee['email']}: " . $e->getMessage();
            error_log("Error preparing credentials for {$employee['email']}: " . $e->getMessage());
        }
    }

    // ── Return response immediately, send emails in background ──────────────
    // Passwords are already updated in the DB. Flush the HTTP response now so
    // the admin sees instant feedback, then send emails without blocking.
    $queuedCount = count($emailQueue);
    $totalProcessed = $queuedCount + $errorCount;

    $message = $queuedCount > 0
        ? "Credentials updated for {$queuedCount} employee(s). Emails are being sent in the background."
        : "No credentials were updated.";
    if ($errorCount > 0) {
        $message .= " {$errorCount} employee(s) had errors during processing.";
    }

    $response = json_encode([
        'success' => $queuedCount > 0,
        'message' => $message,
        'success_count' => $queuedCount,
        'error_count' => $errorCount
    ]);

    ignore_user_abort(true);
    header('Content-Length: ' . strlen($response));
    header('Connection: close');
    echo $response;
    if (ob_get_level() > 0) ob_end_flush();
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // ── Phase 2: Send all emails over a single SMTP connection ───────────────
    if (!empty($emailQueue)) {
        $result = $mailer->sendMailKeepAlive($emailQueue);

        foreach ($result['sent'] as $email) {
            $empId = $idByEmail[$email] ?? '?';
            logAction('RESEND_CREDENTIALS', "Resent credentials to: {$email} (ID: {$empId})");
        }

        foreach ($result['failed'] as $email) {
            $detail = $mailer->lastError ?: 'Unknown SMTP error';
            error_log("Failed to send credentials email to: {$email} - {$detail}");
        }
    }
    
} catch (Exception $e) {
    error_log("Error in resend_credentials.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}
