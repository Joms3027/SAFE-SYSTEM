<?php
/**
 * Delete PDS records for a specific employee ID
 * Usage: Run this script via web browser or command line
 * Example: delete_pds_by_employee_id.php?employee_id=WPU-2026-00004
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';

// Check if running from command line
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    // Allow admin access only for web requests
    requireAdmin();
    header('Content-Type: application/json');
}

// Get employee ID from command line args, GET, or POST
if ($isCli) {
    // Parse command line arguments
    $employeeId = '';
    if (isset($argv[1])) {
        // Support both "employee_id=WPU-2026-00004" and "WPU-2026-00004" formats
        if (strpos($argv[1], '=') !== false) {
            parse_str($argv[1], $params);
            $employeeId = $params['employee_id'] ?? '';
        } else {
            $employeeId = $argv[1];
        }
    }
} else {
    $employeeId = $_GET['employee_id'] ?? $_POST['employee_id'] ?? '';
}

if (empty($employeeId)) {
    $message = 'Safe Employee ID is required. Usage: php delete_pds_by_employee_id.php WPU-2026-00004';
    if ($isCli) {
        echo $message . "\n";
    } else {
        echo json_encode(['success' => false, 'message' => $message]);
    }
    exit(1);
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // Find user_id from employee_id
    $stmt = $db->prepare("
        SELECT fp.user_id, u.first_name, u.last_name 
        FROM faculty_profiles fp 
        INNER JOIN users u ON fp.user_id = u.id 
        WHERE TRIM(fp.employee_id) = ?
    ");
    $stmt->execute([trim($employeeId)]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $message = "Employee with ID '{$employeeId}' not found";
        if ($isCli) {
            echo $message . "\n";
        } else {
            echo json_encode(['success' => false, 'message' => $message]);
        }
        exit(1);
    }
    
    $userId = $user['user_id'];
    $userName = $user['first_name'] . ' ' . $user['last_name'];
    
    // Begin transaction
    $db->beginTransaction();
    
    try {
        // Get all PDS IDs for this user
        $stmt = $db->prepare("SELECT id FROM faculty_pds WHERE faculty_id = ?");
        $stmt->execute([$userId]);
        $pdsRecords = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($pdsRecords)) {
            $db->rollBack();
            $message = "No PDS records found for employee '{$employeeId}' ({$userName})";
            if ($isCli) {
                echo $message . "\n";
            } else {
                echo json_encode(['success' => false, 'message' => $message]);
            }
            exit(1);
        }
        
        $pdsCount = count($pdsRecords);
        $pdsIds = implode(',', array_map('intval', $pdsRecords));
        
        // Delete related PDS child records
        // Note: Using IN clause for efficiency when multiple PDS records exist
        $tablesToClean = [
            'pds_children',
            'pds_education',
            'faculty_civil_service_eligibility',
            'pds_experience',
            'pds_voluntary',
            'pds_learning',
            'pds_references'
        ];
        
        $deletedCounts = [];
        foreach ($tablesToClean as $table) {
            try {
                $stmt = $db->prepare("DELETE FROM {$table} WHERE pds_id IN ({$pdsIds})");
                $stmt->execute();
                $deletedCounts[$table] = $stmt->rowCount();
            } catch (PDOException $e) {
                // Table might not exist, continue
                $deletedCounts[$table] = 0;
            }
        }
        
        // Delete main PDS records
        $stmt = $db->prepare("DELETE FROM faculty_pds WHERE faculty_id = ?");
        $stmt->execute([$userId]);
        $deletedPdsCount = $stmt->rowCount();
        
        // Commit transaction
        $db->commit();
        
        // Log the action (only if session is available)
        if (!$isCli && function_exists('logAction')) {
            logAction('PDS_DELETED', "Deleted {$deletedPdsCount} PDS record(s) for employee: {$userName} (ID: {$employeeId}, User ID: {$userId})");
        }
        
        $message = "Successfully deleted {$deletedPdsCount} PDS record(s) for employee '{$employeeId}' ({$userName})";
        if ($isCli) {
            echo $message . "\n";
            echo "Details:\n";
            echo "  Safe Employee ID: {$employeeId}\n";
            echo "  User Name: {$userName}\n";
            echo "  User ID: {$userId}\n";
            echo "  PDS Records Deleted: {$deletedPdsCount}\n";
            echo "  Related Records Deleted:\n";
            foreach ($deletedCounts as $table => $count) {
                echo "    - {$table}: {$count}\n";
            }
        } else {
            echo json_encode([
                'success' => true,
                'message' => $message,
                'details' => [
                    'employee_id' => $employeeId,
                    'user_name' => $userName,
                    'user_id' => $userId,
                    'pds_records_deleted' => $deletedPdsCount,
                    'related_records_deleted' => $deletedCounts
                ]
            ]);
        }
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $db->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    $errorMessage = 'Failed to delete PDS records: ' . $e->getMessage();
    if ($isCli) {
        echo $errorMessage . "\n";
        exit(1);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => $errorMessage
        ]);
    }
}
?>
