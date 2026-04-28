<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireFaculty();

header('Content-Type: application/json');

$database = Database::getInstance();
$db = $database->getConnection();

try {
    // Get faculty profile to get employee_id and user information
    $stmt = $db->prepare("SELECT fp.employee_id, fp.department, u.first_name, u.last_name 
                          FROM faculty_profiles fp
                          INNER JOIN users u ON fp.user_id = u.id
                          WHERE fp.user_id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$profile || empty($profile['employee_id'])) {
        echo json_encode(['success' => false, 'message' => 'Safe Employee ID not found']);
        exit;
    }
    
    $employee_id = $profile['employee_id'];
    $employee_first_name = $profile['first_name'];
    $employee_last_name = $profile['last_name'];
    $employee_department = $profile['department'];
    $log_id = intval($_POST['log_id'] ?? 0);
    $log_date = $_POST['log_date'] ?? '';
    $pardon_type = trim($_POST['pardon_type'] ?? 'ordinary_pardon');
    $reason = trim($_POST['reason'] ?? '');
    
    $PARDON_TYPES_NEED_TIME = ['ordinary_pardon', 'tarf_ntarf', 'work_from_home'];
    $needTimeEntry = in_array($pardon_type, $PARDON_TYPES_NEED_TIME);
    $useMultiDayCalendar = ($pardon_type !== 'ordinary_pardon');
    
    if (!$log_id || !$log_date) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    
    if (empty($reason)) {
        echo json_encode(['success' => false, 'message' => 'Justification is required']);
        exit;
    }
    
    if ($needTimeEntry && (empty(trim($_POST['time_in'] ?? '')) || empty(trim($_POST['time_out'] ?? '')))) {
        echo json_encode(['success' => false, 'message' => 'Time In and Time Out are required for Ordinary Pardon, TARF/NTARF, and Work from Home']);
        exit;
    }
    
    // Verify the log belongs to this employee
    $stmt = $db->prepare("SELECT id, employee_id, log_date, time_in, lunch_out, lunch_in, time_out 
                          FROM attendance_logs 
                          WHERE id = ? AND employee_id = ? LIMIT 1");
    $stmt->execute([$log_id, $employee_id]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$log) {
        echo json_encode(['success' => false, 'message' => 'Log not found or unauthorized']);
        exit;
    }

    $log_date_only = date('Y-m-d', strtotime($log['log_date']));

    // Covered dates: ordinary = single day; TARF/NTARF & leave types = calendar selection (JSON from client)
    $coveredDates = [];
    if ($useMultiDayCalendar) {
        $rawCovered = $_POST['pardon_covered_dates'] ?? '';
        if (is_string($rawCovered) && $rawCovered !== '') {
            $decoded = json_decode($rawCovered, true);
            if (is_array($decoded)) {
                foreach ($decoded as $d) {
                    $d = trim((string) $d);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                        $coveredDates[] = $d;
                    }
                }
            }
        }
        $coveredDates = array_values(array_unique($coveredDates));
        if (!in_array($log_date_only, $coveredDates, true)) {
            $coveredDates[] = $log_date_only;
        }
        sort($coveredDates);
        if (count($coveredDates) > 60) {
            echo json_encode(['success' => false, 'message' => 'You can include at most 60 days in one pardon request.']);
            exit;
        }
    } else {
        $coveredDates = [$log_date_only];
    }
    $coveredDatesJson = json_encode($coveredDates);

    $time_in = null;
    $lunch_out = null;
    $lunch_in = null;
    $time_out = null;
    
    if ($needTimeEntry) {
        $post_time_in = trim($_POST['time_in'] ?? '');
        $post_time_out = trim($_POST['time_out'] ?? '');
        if ($post_time_in && $post_time_out) {
            $time_in = strlen($post_time_in) <= 5 ? $post_time_in . ':00' : $post_time_in;
            $time_out = strlen($post_time_out) <= 5 ? $post_time_out . ':00' : $post_time_out;
            $post_lo = trim($_POST['lunch_out'] ?? '');
            $post_li = trim($_POST['lunch_in'] ?? '');
            $lunch_out = $post_lo ? (strlen($post_lo) <= 5 ? $post_lo . ':00' : $post_lo) : null;
            $lunch_in = $post_li ? (strlen($post_li) <= 5 ? $post_li . ':00' : $post_li) : null;
        } else {
            $dateObj = new DateTime($log_date_only);
            $dayOfWeek = $dateObj->format('w');
            $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $weekday = $weekdays[$dayOfWeek];
            $stmtOT = $db->prepare("SELECT * FROM employee_official_times 
                                   WHERE employee_id = ? AND weekday = ?
                                   AND (end_date IS NULL OR end_date >= ?)
                                   AND start_date <= ?
                                   ORDER BY start_date DESC 
                                   LIMIT 1");
            $stmtOT->execute([$employee_id, $weekday, $log_date_only, $log_date_only]);
            $officialTime = $stmtOT->fetch(PDO::FETCH_ASSOC);
            if ($officialTime) {
                $time_in = $officialTime['time_in'];
                $lunch_out = ($officialTime['lunch_out'] && $officialTime['lunch_out'] != '00:00:00') ? $officialTime['lunch_out'] : null;
                $lunch_in = ($officialTime['lunch_in'] && $officialTime['lunch_in'] != '00:00:00') ? $officialTime['lunch_in'] : null;
                $time_out = $officialTime['time_out'];
            } else {
                $time_in = '08:00:00';
                $lunch_out = '12:00:00';
                $lunch_in = '13:00:00';
                $time_out = '17:00:00';
            }
        }
    }

    // Pardon must be opened by supervisor only for the anchor date (the log row used to submit).
    // Additional days in TARF/leave multi-day requests do not require separate pardon_open rows.
    try {
        $tbl = $db->query("SHOW TABLES LIKE 'pardon_open'");
        if ($tbl && $tbl->rowCount() > 0) {
            $stmtOpen = $db->prepare("SELECT 1 FROM pardon_open WHERE employee_id = ? AND log_date = ? LIMIT 1");
            $stmtOpen->execute([$employee_id, $log_date_only]);
            if (!$stmtOpen->fetch()) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Your supervisor must open pardon for this date before you can submit a request from this log.'
                ]);
                exit;
            }
        }
    } catch (Exception $e) { /* ignore if table missing */ }
    
    // Check if there's already a pending request for this log
    $stmt = $db->prepare("SELECT id, status FROM pardon_requests WHERE log_id = ? AND employee_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$log_id, $employee_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        if ($existing['status'] === 'pending') {
            echo json_encode(['success' => false, 'message' => 'You already have a pending pardon request for this log']);
            exit;
        } else if ($existing['status'] === 'approved') {
            echo json_encode(['success' => false, 'message' => 'This log already has an approved pardon request']);
            exit;
        }
        // If rejected, we'll update it below (resubmission)
    }

    // Extra covered dates may not have a log yet; those rows are created when HR approves.
    // If a log already exists for a date, it must not block this request (pending/approved elsewhere) except anchor rules above.
    $stmtLogByDate = $db->prepare("SELECT id FROM attendance_logs WHERE employee_id = ? AND DATE(log_date) = ? LIMIT 1");
    $stmtLatestPardon = $db->prepare("SELECT id, status FROM pardon_requests WHERE log_id = ? AND employee_id = ? ORDER BY created_at DESC LIMIT 1");
    foreach ($coveredDates as $cDate) {
        $stmtLogByDate->execute([$employee_id, $cDate]);
        $logRow = $stmtLogByDate->fetch(PDO::FETCH_ASSOC);
        if (!$logRow) {
            continue;
        }
        $cid = (int) $logRow['id'];
        $stmtLatestPardon->execute([$cid, $employee_id]);
        $lpr = $stmtLatestPardon->fetch(PDO::FETCH_ASSOC);
        if (!$lpr) {
            continue;
        }
        if ($cid === $log_id) {
            if ($lpr['status'] === 'pending' || $lpr['status'] === 'approved') {
                echo json_encode(['success' => false, 'message' => 'This log already has a pending or approved pardon request.']);
                exit;
            }
            if ($lpr['status'] === 'rejected' && (!$existing || (int) $existing['id'] !== (int) $lpr['id'])) {
                echo json_encode(['success' => false, 'message' => 'Pardon state for this log is inconsistent. Please refresh and try again.']);
                exit;
            }
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'The date ' . date('M j, Y', strtotime($cDate)) . ' already has a separate pardon request. Remove it from the selection or finish that request first.'
            ]);
            exit;
        }
    }
    
    // Check weekly pardon limit (only for new requests, not resubmissions)
    if (!$existing || $existing['status'] !== 'rejected') {
        // Get pardon limit from system settings
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'pardon_weekly_limit' LIMIT 1");
        $stmt->execute();
        $limitSetting = $stmt->fetch(PDO::FETCH_ASSOC);
        $weeklyLimit = $limitSetting ? intval($limitSetting['setting_value']) : 3; // Default to 3 if not set
        
        // Calculate week start (Monday) and end (Sunday) for the log date
        $logDateObj = new DateTime($log_date);
        $dayOfWeek = (int)$logDateObj->format('w'); // 0 (Sunday) to 6 (Saturday)
        // Convert to Monday = 0, Sunday = 6
        $dayOfWeek = ($dayOfWeek == 0) ? 6 : $dayOfWeek - 1;
        
        // Get Monday of the week
        $weekStart = clone $logDateObj;
        $weekStart->modify('-' . $dayOfWeek . ' days');
        $weekStart->setTime(0, 0, 0);
        
        // Get Sunday of the week
        $weekEnd = clone $weekStart;
        $weekEnd->modify('+6 days');
        $weekEnd->setTime(23, 59, 59);
        
        $weekStartStr = $weekStart->format('Y-m-d H:i:s');
        $weekEndStr = $weekEnd->format('Y-m-d H:i:s');
        
        // Count pending and approved pardon requests for this employee in this week (based on log_date, not reviewed_at)
        // The limit applies to total requests (pending + approved), not just approved
        $weekStartDateStr = $weekStart->format('Y-m-d');
        $weekEndDateStr = $weekEnd->format('Y-m-d');
        $stmt = $db->prepare("SELECT COUNT(*) as count 
                              FROM pardon_requests 
                              WHERE employee_id = ? 
                              AND status IN ('pending', 'approved') 
                              AND log_date >= ? 
                              AND log_date <= ?");
        $stmt->execute([$employee_id, $weekStartDateStr, $weekEndDateStr]);
        $requestCount = $stmt->fetch(PDO::FETCH_ASSOC);
        $requestsThisWeek = intval($requestCount['count']);
        
        if ($requestsThisWeek >= $weeklyLimit) {
            $nextWeekStart = clone $weekEnd;
            $nextWeekStart->modify('+1 day');
            echo json_encode([
                'success' => false, 
                'message' => "You have reached the weekly pardon limit of {$weeklyLimit} requests. You can submit new pardon requests starting " . $nextWeekStart->format('M d, Y') . " (next week)."
            ]);
            exit;
        }
    }
    
    // Handle multiple file uploads (required: at least one supporting document)
    $supporting_documents = [];
    if (isset($_FILES['supporting_documents']) && !empty($_FILES['supporting_documents']['name'][0])) {
        $allowedTypes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'image/jpeg',
            'image/jpg',
            'image/pjpeg',
            'image/png',
            'image/x-png',
        ];
        $maxSize = 20 * 1024 * 1024; // 20MB per file
        
        // Create upload directory if it doesn't exist
        $uploadDir = __DIR__ . '/../uploads/pardon_requests/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $files = $_FILES['supporting_documents'];
        $fileCount = count($files['name']);
        
        $allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
        for ($i = 0; $i < $fileCount; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                continue; // Skip files with upload errors
            }

            $fileExtension = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            $fileExtension = preg_replace('/[^a-z0-9]/', '', $fileExtension);
            if (!in_array($fileExtension, $allowedExtensions, true)) {
                echo json_encode(['success' => false, 'message' => 'File extension not allowed.']);
                exit;
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $files['tmp_name'][$i]);
            finfo_close($finfo);

            $mimeOk = in_array($mimeType, $allowedTypes, true);
            if (!$mimeOk && in_array($fileExtension, ['jpg', 'jpeg', 'png'], true)) {
                $imgInfo = @getimagesize($files['tmp_name'][$i]);
                if ($imgInfo !== false) {
                    if ($imgInfo[2] === IMAGETYPE_JPEG && in_array($fileExtension, ['jpg', 'jpeg'], true)) {
                        $mimeOk = true;
                    }
                    if ($imgInfo[2] === IMAGETYPE_PNG && $fileExtension === 'png') {
                        $mimeOk = true;
                    }
                }
            }
            if (!$mimeOk) {
                echo json_encode(['success' => false, 'message' => 'Invalid file type for "' . $files['name'][$i] . '". Allowed: PDF, DOC, DOCX, JPG, PNG']);
                exit;
            }

            if ($files['size'][$i] > $maxSize) {
                echo json_encode(['success' => false, 'message' => 'File "' . $files['name'][$i] . '" exceeds 20MB limit']);
                exit;
            }

            // Documents only: binary JPG/PNG can match text patterns in rare metadata; skip for images
            if (!in_array($fileExtension, ['jpg', 'jpeg', 'png'], true)) {
                $fileContent = file_get_contents($files['tmp_name'][$i], false, null, 0, 1024);
                if (preg_match('/<\?php|<\?=|<script/i', $fileContent)) {
                    echo json_encode(['success' => false, 'message' => 'File contains potentially dangerous content.']);
                    exit;
                }
            }
            $fileName = 'pardon_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $employee_id) . '_' . (int)$log_id . '_' . time() . '_' . $i . '.' . $fileExtension;
            $filePath = $uploadDir . $fileName;
            
            if (move_uploaded_file($files['tmp_name'][$i], $filePath)) {
                $supporting_documents[] = 'pardon_requests/' . $fileName;
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to upload file: ' . $files['name'][$i]]);
                exit;
            }
        }
    }
    
    if (empty($supporting_documents)) {
        echo json_encode(['success' => false, 'message' => 'At least one supporting document is required.']);
        exit;
    }
    
    // Convert array to JSON string for storage
    $supporting_documents_json = json_encode($supporting_documents);
    
    // Ensure pardon_type column exists (migration 20260316)
    try {
        $colCheck = $db->query("SHOW COLUMNS FROM pardon_requests LIKE 'pardon_type'");
        if (!$colCheck || $colCheck->rowCount() === 0) {
            $db->exec("ALTER TABLE pardon_requests ADD COLUMN pardon_type VARCHAR(80) DEFAULT 'ordinary_pardon' AFTER log_date");
        }
    } catch (Exception $e) { /* ignore */ }

    try {
        $colCd = $db->query("SHOW COLUMNS FROM pardon_requests LIKE 'pardon_covered_dates'");
        if (!$colCd || $colCd->rowCount() === 0) {
            $db->exec("ALTER TABLE pardon_requests ADD COLUMN pardon_covered_dates TEXT NULL AFTER pardon_type");
        }
    } catch (Exception $e) { /* ignore */ }
    
    // If there's a rejected request, update it (resubmission)
    if ($existing && $existing['status'] === 'rejected') {
        // Update existing rejected request
        $stmt = $db->prepare("UPDATE pardon_requests 
                              SET log_date = ?, pardon_type = ?, pardon_covered_dates = ?,
                                  original_time_in = ?, original_lunch_out = ?, original_lunch_in = ?, original_time_out = ?,
                                  requested_time_in = ?, requested_lunch_out = ?, requested_lunch_in = ?, requested_time_out = ?,
                                  reason = ?, supporting_documents = ?, status = 'pending',
                                  employee_first_name = ?, employee_last_name = ?, employee_department = ?,
                                  reviewed_by = NULL, reviewed_at = NULL, review_notes = NULL,
                                  updated_at = NOW()
                              WHERE id = ? AND employee_id = ?");
        
        $stmt->execute([
            $log_date,
            $pardon_type,
            $coveredDatesJson,
            $log['time_in'],
            $log['lunch_out'],
            $log['lunch_in'],
            $log['time_out'],
            $time_in,
            $lunch_out,
            $lunch_in,
            $time_out,
            $reason,
            $supporting_documents_json,
            $employee_first_name,
            $employee_last_name,
            $employee_department,
            $existing['id'],
            $employee_id
        ]);
        
        logAction('PARDON_RESUBMIT', "Resubmitted pardon request for $log_date (Employee: $employee_id, Request ID: {$existing['id']})");
        
        // Notify admins and super-admins about resubmitted pardon request
        try {
            require_once __DIR__ . '/../includes/notifications.php';
            $notificationManager = getNotificationManager();
            $employeeName = trim($employee_first_name . ' ' . $employee_last_name) ?: 'Employee';
            $notificationManager->notifyAdminsPardonRequest($employeeName, $log_date, $existing['id']);
        } catch (Exception $e) {
            error_log('Failed to notify admins about pardon resubmission: ' . $e->getMessage());
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Pardon request resubmitted successfully'
        ]);
    } else {
        // Insert new pardon request
        $stmt = $db->prepare("INSERT INTO pardon_requests 
                              (employee_id, employee_first_name, employee_last_name, employee_department,
                               log_id, log_date, pardon_type, pardon_covered_dates,
                               original_time_in, original_lunch_out, original_lunch_in, original_time_out,
                               requested_time_in, requested_lunch_out, requested_lunch_in, requested_time_out,
                               reason, supporting_documents, status) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
        
        $stmt->execute([
            $employee_id,
            $employee_first_name,
            $employee_last_name,
            $employee_department,
            $log_id,
            $log_date,
            $pardon_type,
            $coveredDatesJson,
            $log['time_in'],
            $log['lunch_out'],
            $log['lunch_in'],
            $log['time_out'],
            $time_in,
            $lunch_out,
            $lunch_in,
            $time_out,
            $reason,
            $supporting_documents_json
        ]);
        
        $requestId = (int) $db->lastInsertId();
        
        logAction('PARDON_SUBMIT', "Submitted pardon request for $log_date (Employee: $employee_id, Request ID: $requestId)");
        
        // Notify admins and super-admins about new pardon request
        try {
            require_once __DIR__ . '/../includes/notifications.php';
            $notificationManager = getNotificationManager();
            $employeeName = trim($employee_first_name . ' ' . $employee_last_name) ?: 'Employee';
            $notificationManager->notifyAdminsPardonRequest($employeeName, $log_date, $requestId);
        } catch (Exception $e) {
            error_log('Failed to notify admins about pardon request: ' . $e->getMessage());
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Pardon request submitted successfully'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Error submitting pardon request: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error submitting pardon request: ' . $e->getMessage()
    ]);
}
?>

