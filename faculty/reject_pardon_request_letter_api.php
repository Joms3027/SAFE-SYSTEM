<?php
/**
 * Reject Pardon Request Letter - API for pardon openers to reject a request.
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAuth();

header('Content-Type: application/json');

$database = Database::getInstance();
$db = $database->getConnection();

try {
    if (!isFaculty() && !isStaff()) {
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    if (!hasPardonOpenerAssignments($_SESSION['user_id'], $db)) {
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    $requestId = (int) ($_POST['request_id'] ?? $_GET['request_id'] ?? 0);
    if (!$requestId) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }

    // Get the request and verify it's in the user's scope
    $stmt = $db->prepare("SELECT * FROM pardon_request_letters WHERE id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Request not found or already processed']);
        exit;
    }

    $employeeIdsInScope = getEmployeeIdsInScope($_SESSION['user_id'], $db);
    if (!in_array($req['employee_id'], $employeeIdsInScope)) {
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    $rejectionComment = trim($_POST['rejection_comment'] ?? $_GET['rejection_comment'] ?? '');

    try {
        $stmt = $db->prepare("UPDATE pardon_request_letters SET status = 'rejected', rejection_comment = ? WHERE id = ?");
        $stmt->execute([$rejectionComment ?: null, $requestId]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'rejection_comment') !== false) {
            $stmt = $db->prepare("UPDATE pardon_request_letters SET status = 'rejected' WHERE id = ?");
            $stmt->execute([$requestId]);
        } elseif (strpos($e->getMessage(), 'rejected') !== false) {
            $stmt = $db->prepare("UPDATE pardon_request_letters SET status = 'closed' WHERE id = ?");
            $stmt->execute([$requestId]);
        } else {
            throw $e;
        }
    }

    // Notify the employee (in-app notification + email) before responding
    try {
        $stmtEmp = $db->prepare("SELECT fp.user_id, u.email, u.first_name, u.last_name 
                                 FROM faculty_profiles fp 
                                 INNER JOIN users u ON fp.user_id = u.id 
                                 WHERE fp.employee_id = ? AND u.is_active = 1 LIMIT 1");
        $stmtEmp->execute([$req['employee_id']]);
        $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);
        if ($emp && !empty($emp['user_id'])) {
            require_once __DIR__ . '/../includes/notifications.php';
            $notificationManager = getNotificationManager();
            if (method_exists($notificationManager, 'notifyEmployeePardonRejected')) {
                $notificationManager->notifyEmployeePardonRejected((int)$emp['user_id'], $req['pardon_date'], $rejectionComment);
            }
            if (!empty($emp['email'])) {
                require_once __DIR__ . '/../includes/mailer.php';
                $mailer = new Mailer();
                $empName = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')) ?: trim(($req['employee_first_name'] ?? '') . ' ' . ($req['employee_last_name'] ?? '')) ?: 'Employee';
                $mailer->sendPardonRejectedEmail($emp['email'], $empName, $req['pardon_date'], $rejectionComment);
            }
        }
    } catch (Exception $e) {
        error_log('Failed to notify employee about pardon rejection: ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'message' => 'Pardon request rejected']);
} catch (Exception $e) {
    error_log("Error rejecting pardon request letter: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error processing request']);
}
