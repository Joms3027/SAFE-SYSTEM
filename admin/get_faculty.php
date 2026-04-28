<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/qr_code_helper.php';

requireAdmin();

header('Content-Type: application/json');

$facultyId = (int)($_GET['id'] ?? 0);

if (!$facultyId) {
    echo json_encode(['success' => false, 'message' => 'Invalid faculty ID']);
    exit();
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    $stmt = $db->prepare("
        SELECT u.*, 
               fp.id as profile_id, fp.employee_id, fp.department, fp.position, 
               fp.employment_status, fp.hire_date, fp.phone, fp.address, 
               fp.emergency_contact_name, fp.emergency_contact_phone, 
               fp.profile_picture, fp.employment_type, fp.qr_code,
               fp.created_at as profile_created_at, fp.updated_at as profile_updated_at,
               ps.salary_grade, ps.annual_salary
        FROM users u 
        LEFT JOIN faculty_profiles fp ON u.id = fp.user_id 
        LEFT JOIN position_salary ps ON fp.position = ps.position_title
        WHERE u.id = ? AND u.user_type IN ('faculty', 'staff')
    ");
    $stmt->execute([$facultyId]);
    $faculty = $stmt->fetch();
    
    if ($faculty) {
        // Check and generate QR code if needed
        $qrCodePath = $faculty['qr_code'] ?? null;
        $employeeId = $faculty['employee_id'] ?? null;
        
        // Generate or regenerate QR code if employee ID exists
        if ($employeeId) {
            // Find which user_id actually owns this employee_id (to ensure correct name)
            $stmt = $db->prepare("SELECT user_id FROM faculty_profiles WHERE employee_id = ?");
            $stmt->execute([$employeeId]);
            $ownerResult = $stmt->fetch();
            $actualOwnerId = $ownerResult ? $ownerResult['user_id'] : $facultyId;
            
            // Check if QR code file exists on disk
            $qrCodeExists = false;
            if ($qrCodePath) {
                $fullPath = UPLOAD_PATH . $qrCodePath;
                $qrCodeExists = file_exists($fullPath);
            }
            
            // Generate or regenerate QR code if it doesn't exist or file is missing
            if (!$qrCodePath || !$qrCodeExists) {
                $regeneratedPath = generateQRCode($actualOwnerId, $employeeId);
                if ($regeneratedPath) {
                    // Verify the regenerated file exists
                    $regeneratedFullPath = UPLOAD_PATH . $regeneratedPath;
                    if (file_exists($regeneratedFullPath)) {
                        // Update database for the actual owner of the employee_id
                        $updateStmt = $db->prepare("UPDATE faculty_profiles SET qr_code = ? WHERE user_id = ?");
                        $updateStmt->execute([$regeneratedPath, $actualOwnerId]);
                        $faculty['qr_code'] = $regeneratedPath;
                        
                        // Also update for the requested facultyId if different (for consistency)
                        if ($actualOwnerId != $facultyId) {
                            $updateStmt = $db->prepare("UPDATE faculty_profiles SET qr_code = ? WHERE user_id = ?");
                            $updateStmt->execute([$regeneratedPath, $facultyId]);
                        }
                    } else {
                        // Generation returned a path but file doesn't exist - log error
                        error_log("QR code generation returned path but file not found: $regeneratedFullPath for user_id: $actualOwnerId");
                        $faculty['qr_code'] = $qrCodePath; // Keep original path if available
                    }
                } elseif (!$qrCodePath) {
                    // If generation failed and no existing QR code, try to fetch from database again
                    // (in case it was just created by another process)
                    $checkStmt = $db->prepare("SELECT qr_code FROM faculty_profiles WHERE user_id = ?");
                    $checkStmt->execute([$facultyId]);
                    $checkResult = $checkStmt->fetch();
                    if ($checkResult && !empty($checkResult['qr_code'])) {
                        $faculty['qr_code'] = $checkResult['qr_code'];
                    }
                }
            }
        }
        
        // Add QR code URL for display (using admin QR code image proxy)
        // Set URL if we have a valid QR code path in the database and the file exists on disk
        if (!empty($faculty['qr_code'])) {
            $fullPath = UPLOAD_PATH . $faculty['qr_code'];
            if (file_exists($fullPath)) {
                $faculty['qr_code_url'] = 'qr_code_image.php?user_id=' . $facultyId . '&v=' . time();
            } else {
                // File doesn't exist - set URL to null
                $faculty['qr_code_url'] = null;
                error_log("QR code file not found on disk: $fullPath for user_id: $facultyId (db path: {$faculty['qr_code']})");
            }
        } else {
            $faculty['qr_code_url'] = null;
        }
        
        echo json_encode(['success' => true, 'faculty' => $faculty]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Faculty or staff member not found']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>






