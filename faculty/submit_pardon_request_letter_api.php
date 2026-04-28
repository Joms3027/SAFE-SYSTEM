<?php
/**
 * Submit Pardon Request Letter - API for employees to submit a pardon request (letter file + day).
 * Notifies pardon openers assigned to the employee's department or designation.
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/upload.php';

requireFaculty();

header('Content-Type: application/json');

$database = Database::getInstance();
$db = $database->getConnection();

try {
    // Check if table exists
    $tbl = $db->query("SHOW TABLES LIKE 'pardon_request_letters'");
    if (!$tbl || $tbl->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Pardon request letters feature is not available.']);
        exit;
    }

    // Get faculty profile
    $stmt = $db->prepare("SELECT fp.employee_id, fp.department, fp.designation, u.first_name, u.last_name 
                          FROM faculty_profiles fp
                          INNER JOIN users u ON fp.user_id = u.id
                          WHERE fp.user_id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile || empty($profile['employee_id'])) {
        echo json_encode(['success' => false, 'message' => 'Safe Employee ID not found']);
        exit;
    }

    $employeeId = $profile['employee_id'];
    $employeeFirstName = $profile['first_name'] ?? '';
    $employeeLastName = $profile['last_name'] ?? '';
    $employeeDepartment = $profile['department'] ?? '';
    $employeeDesignation = $profile['designation'] ?? '';

    $pardonDate = trim($_POST['pardon_date'] ?? '');

    if (empty($pardonDate)) {
        echo json_encode(['success' => false, 'message' => 'Please select the day you want to be pardoned.']);
        exit;
    }

    // Validate file upload (required) - support multiple files
    $filePaths = [];
    $allowedTypes = ['pdf'];
    $uploader = new FileUploader();

    if (!empty($_FILES['request_letter_file']['name'])) {
        $names = $_FILES['request_letter_file']['name'];
        $isMultiple = is_array($names);
        $fileCount = $isMultiple ? count($names) : 1;
        if ($fileCount > 10) {
            echo json_encode(['success' => false, 'message' => 'Maximum 10 files allowed per request.']);
            exit;
        }

        for ($i = 0; $i < $fileCount; $i++) {
            $file = [
                'name'     => $isMultiple ? $names[$i] : $names,
                'type'     => $isMultiple ? $_FILES['request_letter_file']['type'][$i] : $_FILES['request_letter_file']['type'],
                'tmp_name' => $isMultiple ? $_FILES['request_letter_file']['tmp_name'][$i] : $_FILES['request_letter_file']['tmp_name'],
                'error'    => $isMultiple ? $_FILES['request_letter_file']['error'][$i] : $_FILES['request_letter_file']['error'],
                'size'     => $isMultiple ? $_FILES['request_letter_file']['size'][$i] : $_FILES['request_letter_file']['size'],
            ];
            if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
                continue;
            }
            $result = $uploader->uploadFile($file, 'pardon_letters', $allowedTypes, MAX_FILE_SIZE);
            if (!$result['success']) {
                echo json_encode(['success' => false, 'message' => $result['message'] ?? 'Invalid file. Please upload PDF files only.']);
                exit;
            }
            $filePaths[] = $result['file_path'];
        }
    }

    if (empty($filePaths)) {
        echo json_encode(['success' => false, 'message' => 'Please upload at least one pardon request letter file.']);
        exit;
    }

    $requestLetterFilePath = count($filePaths) === 1 ? $filePaths[0] : json_encode($filePaths);

    // Validate date
    $dateObj = DateTime::createFromFormat('Y-m-d', $pardonDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $pardonDate) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
        exit;
    }

    // Get pardon openers for this employee
    $openerUserIds = [];
    if (function_exists('getOpenerUserIdsForEmployee')) {
        $openerUserIds = getOpenerUserIdsForEmployee($employeeId, $db);
    }

    if (empty($openerUserIds)) {
        echo json_encode(['success' => false, 'message' => 'No pardon openers are assigned to your department or designation. Contact the administrator.']);
        exit;
    }

    // Insert pardon request letter (store file path in request_letter column)
    $stmt = $db->prepare("INSERT INTO pardon_request_letters 
                          (employee_id, employee_first_name, employee_last_name, employee_department, employee_designation,
                           pardon_date, request_letter, status) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
    $stmt->execute([
        $employeeId,
        $employeeFirstName,
        $employeeLastName,
        $employeeDepartment,
        $employeeDesignation,
        $pardonDate,
        $requestLetterFilePath
    ]);

    $requestId = (int) $db->lastInsertId();

    // Notify pardon openers
    try {
        require_once __DIR__ . '/../includes/notifications.php';
        $notificationManager = getNotificationManager();
        if (method_exists($notificationManager, 'notifyPardonOpenersForRequestLetter')) {
            $notificationManager->notifyPardonOpenersForRequestLetter(
                $openerUserIds,
                trim($employeeFirstName . ' ' . $employeeLastName) ?: 'Employee',
                $pardonDate,
                $requestId
            );
        }
    } catch (Exception $e) {
        error_log('Failed to notify pardon openers: ' . $e->getMessage());
    }

    logAction('PARDON_LETTER_SUBMIT', "Submitted pardon request letter for $pardonDate (Employee: $employeeId, Request ID: $requestId)");

    echo json_encode([
        'success' => true,
        'message' => 'Your pardon request has been submitted successfully. The assigned pardon openers have been notified.'
    ]);

} catch (Exception $e) {
    error_log("Error submitting pardon request letter: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error submitting request. Please try again.'
    ]);
}
