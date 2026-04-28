<?php
/**
 * CSV-Only Batch Upload Handler
 * Fallback implementation that doesn't require PHPSpreadsheet
 * Use this if PHP extensions (zip, gd) are not available
 */

// Add this to create_faculty.php if PHPSpreadsheet is not available

function processCsvBatchUpload($file, $db) {
    $batchResults = [];
    $batchErrors = [];
    $successCount = 0;
    $errorCount = 0;
    
    if (($handle = fopen($file['tmp_name'], 'r')) !== false) {
        // Read header row
        $header = fgetcsv($handle);
        
        if (!$header) {
            return [
                'success' => false,
                'error' => 'Invalid CSV file format',
                'results' => [],
                'errors' => []
            ];
        }
        
        $rowNum = 2; // Start from 2 (1 is header)
        
        while (($row = fgetcsv($handle)) !== false) {
            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }
            
            // Extract data
            $firstName = trim($row[0] ?? '');
            $lastName = trim($row[1] ?? '');
            $middleName = trim($row[2] ?? '');
            $email = trim($row[3] ?? '');
            $userType = trim(strtolower($row[4] ?? 'faculty'));
            $department = trim($row[5] ?? '');
            $position = trim($row[6] ?? '');
            $employmentStatus = trim($row[7] ?? '');
            // Convert empty string to NULL for database insertion
            $employmentStatus = empty($employmentStatus) ? null : $employmentStatus;
            
            // Skip header-like rows (rows that contain header keywords)
            $headerKeywords = ['first name', 'last name', 'email', 'user type', 'department', 'position', 'employment status'];
            $rowValues = array_map('strtolower', array_map('trim', array_filter($row)));
            $isHeaderRow = false;
            foreach ($headerKeywords as $keyword) {
                if (in_array($keyword, $rowValues) || preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', implode(' ', $rowValues))) {
                    $isHeaderRow = true;
                    break;
                }
            }
            if ($isHeaderRow) {
                $rowNum++;
                continue;
            }
            
            // Clean user type: remove asterisks, parentheses, and other formatting characters, but preserve letters
            $userType = trim(strtolower($userType));
            $userType = preg_replace('/[^a-z\s]/i', '', $userType); // Remove non-alphabetic except spaces
            $userType = preg_replace('/\s+/', '', $userType); // Remove spaces
            if (empty($userType)) {
                $userType = 'faculty'; // Default fallback
            }
            
            // Validate required fields
            if (empty($firstName) || empty($lastName) || empty($email)) {
                $batchErrors[] = "Row $rowNum: Missing required fields (First Name, Last Name, or Email)";
                $errorCount++;
                $rowNum++;
                continue;
            }
            
            // Validate user type
            if (!in_array($userType, ['faculty', 'staff'])) {
                $batchErrors[] = "Row $rowNum: Invalid user type '$userType'. Must be 'faculty' or 'staff'";
                $errorCount++;
                $rowNum++;
                continue;
            }
            
            // Validate email
            if (!validateWPUEmail($email)) {
                $batchErrors[] = "Row $rowNum: Invalid email '$email'. Only @wpu.edu.ph addresses are allowed";
                $errorCount++;
                $rowNum++;
                continue;
            }
            
            // Normalize email (trim and lowercase)
            $email = trim(strtolower($email));
            
            // Check if email exists for the same user type (faculty/staff)
            // Allow duplicate emails for different user types (admin vs faculty/staff)
            $stmt = $db->prepare("SELECT id, user_type FROM users WHERE LOWER(TRIM(email)) = ? AND user_type = ?");
            $stmt->execute([$email, $userType]);
            $existingUser = $stmt->fetch();
            
            if ($existingUser) {
                $batchErrors[] = "Row $rowNum: Email '$email' is already registered as a " . ucfirst($userType) . " account. Each email can only be used once per user type.";
                $errorCount++;
                $rowNum++;
                continue;
            }
            
            // Create account
            try {
                $db->beginTransaction();
                
                // Ensure SQL_MODE allows AUTO_INCREMENT to work properly
                // This prevents issues with NO_AUTO_VALUE_ON_ZERO mode
                $db->exec("SET sql_mode = REPLACE(@@sql_mode, 'NO_AUTO_VALUE_ON_ZERO', '')");
                
                // Check and fix any orphaned row with id=0 in faculty_profiles (if it exists)
                // This can happen if previous inserts failed
                try {
                    $checkStmt = $db->prepare("SELECT id FROM faculty_profiles WHERE id = 0 LIMIT 1");
                    $checkStmt->execute();
                    if ($checkStmt->fetch()) {
                        // Delete orphaned row with id=0
                        $db->exec("DELETE FROM faculty_profiles WHERE id = 0");
                        // Reset AUTO_INCREMENT to next available value
                        $maxStmt = $db->query("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM faculty_profiles");
                        $maxResult = $maxStmt->fetch();
                        if ($maxResult && isset($maxResult['next_id'])) {
                            $nextId = intval($maxResult['next_id']);
                            $db->exec("ALTER TABLE faculty_profiles AUTO_INCREMENT = " . $nextId);
                        }
                    }
                } catch (Exception $e) {
                    // Ignore errors in cleanup - not critical
                    error_log("Warning: Could not clean up orphaned faculty_profiles row: " . $e->getMessage());
                }
                
                $generatedPwd = bin2hex(random_bytes(4));
                $hashedPassword = password_hash($generatedPwd, PASSWORD_DEFAULT);
                
                // Verify email is not empty before insert
                if (empty($email)) {
                    throw new Exception("Email cannot be empty");
                }
                
                // Explicitly exclude id column to ensure AUTO_INCREMENT works
                $stmt = $db->prepare("INSERT INTO users (email, password, user_type, first_name, last_name, middle_name, is_verified, is_active) VALUES (?, ?, ?, ?, ?, ?, 1, 1)");
                
                // Execute and check for errors
                try {
                    $executeResult = $stmt->execute([$email, $hashedPassword, $userType, $firstName, $lastName, $middleName]);
                    
                    if ($executeResult === false) {
                        $errorInfo = $stmt->errorInfo();
                        throw new Exception("INSERT failed: " . ($errorInfo[2] ?? 'Unknown error') . " (SQLSTATE: " . ($errorInfo[0] ?? 'N/A') . ")");
                    }
                } catch (PDOException $e) {
                    throw new Exception("Database error during INSERT: " . $e->getMessage() . " (Code: " . $e->getCode() . ")");
                }
                
                $userId = $db->lastInsertId();
                
                // Validate that user was created successfully
                if (!$userId || $userId <= 0) {
                    // Try to verify if the insert actually happened by querying the email
                    $verifyStmt = $db->prepare("SELECT id FROM users WHERE email = ? AND user_type = ? ORDER BY id DESC LIMIT 1");
                    $verifyStmt->execute([$email, $userType]);
                    $verifyUser = $verifyStmt->fetch();
                    
                    if ($verifyUser && isset($verifyUser['id'])) {
                        // User was created but lastInsertId() failed, use the verified ID
                        $userId = $verifyUser['id'];
                    } else {
                        $errorInfo = $stmt->errorInfo();
                        $errorMsg = "Failed to create user account. User ID is invalid: " . ($userId ?: 'null');
                        if ($errorInfo && $errorInfo[0] !== '00000') {
                            $errorMsg .= " | SQL Error: " . ($errorInfo[2] ?? 'Unknown') . " (SQLSTATE: " . ($errorInfo[0] ?? 'N/A') . ")";
                        }
                        throw new Exception($errorMsg);
                    }
                }
                
                // Double-check userId is valid before proceeding
                if (!$userId || $userId <= 0) {
                    throw new Exception("Invalid user ID ($userId) - cannot create faculty profile");
                }
                
                $employeeId = generateEmployeeID();
                
                // Generate QR code for attendance (contains: Employee ID)
                $qrCodePath = generateQRCode($userId, $employeeId);
                
                // Check if QR code generation failed
                if ($qrCodePath === false) {
                    error_log("Failed to generate QR code for user ID: $userId, Name: $lastName, $firstName");
                    $qrCodePath = null; // Set to null for database insertion
                }
                
                // Ensure SQL_MODE is still correct for faculty_profiles insert
                $db->exec("SET sql_mode = REPLACE(@@sql_mode, 'NO_AUTO_VALUE_ON_ZERO', '')");
                
                // Explicitly exclude id column to ensure AUTO_INCREMENT works
                $stmt = $db->prepare("INSERT INTO faculty_profiles (user_id, employee_id, department, position, employment_status, qr_code) VALUES (?, ?, ?, ?, ?, ?)");
                
                try {
                    $stmt->execute([$userId, $employeeId, $department, $position, $employmentStatus, $qrCodePath]);
                } catch (PDOException $e) {
                    throw new Exception("Failed to create faculty profile: " . $e->getMessage() . " (User ID: $userId)");
                }
                
                $db->commit();
                
                // Send email
                $mailer = new Mailer();
                $fullName = trim($firstName . ' ' . $middleName . ' ' . $lastName);
                $loginUrl = SITE_URL . "/login.php";
                
                $emailSent = $mailer->sendAccountCreationEmail($email, $fullName, $userType, $employeeId, $generatedPwd, $loginUrl);
                
                $batchResults[] = [
                    'name' => $fullName,
                    'email' => $email,
                    'employee_id' => $employeeId,
                    'password' => $generatedPwd,
                    'user_type' => $userType,
                    'email_sent' => $emailSent
                ];
                
                $successCount++;
                logAction('CREATE_FACULTY_BATCH', "Admin created account: $email (Employee ID: $employeeId)");
                
            } catch (Exception $e) {
                $db->rollBack();
                $batchErrors[] = "Row $rowNum: Failed to create account for '$email' - " . $e->getMessage();
                $errorCount++;
            }
            
            $rowNum++;
        }
        
        fclose($handle);
    } else {
        return [
            'success' => false,
            'error' => 'Failed to read CSV file',
            'results' => [],
            'errors' => []
        ];
    }
    
    return [
        'success' => $successCount > 0,
        'message' => "Batch upload completed! Successfully created $successCount account(s)." . ($errorCount > 0 ? " $errorCount row(s) had errors." : ""),
        'results' => $batchResults,
        'errors' => $batchErrors,
        'success_count' => $successCount,
        'error_count' => $errorCount
    ];
}

/**
 * Generate CSV template without PHPSpreadsheet
 */
function generateCsvTemplate() {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="faculty_batch_upload_template.csv"');
    header('Cache-Control: max-age=0');
    
    $output = fopen('php://output', 'w');
    
    // Headers
    fputcsv($output, [
        'First Name*',
        'Last Name*',
        'Middle Name',
        'Email (@wpu.edu.ph)*',
        'User Type* (faculty/staff)',
        'Department',
        'Position',
        'Employment Status'
    ]);
    
    // Example data
    fputcsv($output, [
        'Juan',
        'Dela Cruz',
        'Santos',
        'juan.delacruz@wpu.edu.ph',
        'faculty',
        'Computer Science',
        'Professor',
        'Permanent'
    ]);
    
    fclose($output);
    exit;
}

?>
