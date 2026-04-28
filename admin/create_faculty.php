<?php
// Start output buffering early to catch any output from included files
ob_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Don't display errors in browser (log instead)

// Set up custom error handler to log all errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("[ERROR] [$errno] $errstr in $errfile on line $errline");
    return false;
});

// Set up exception handler
set_exception_handler(function($exception) {
    error_log("[EXCEPTION] " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
    error_log("[TRACE] " . $exception->getTraceAsString());
});

try {
    // Log page access
    error_log("CREATE_FACULTY: Page loaded. REQUEST_METHOD=" . $_SERVER['REQUEST_METHOD'] . ", FILES_PRESENT=" . (isset($_FILES['batch_file']) ? "yes" : "no"));
    
    // Normal page flow - include all necessary files
    require_once '../includes/config.php';
    require_once '../includes/functions.php';
    require_once '../includes/database.php';
    require_once '../includes/mailer.php';
    require_once '../includes/qr_code_helper.php';

    // Check if PHPSpreadsheet is available AND required extensions are loaded
    $phpSpreadsheetAvailable = false;
    $zipExtensionAvailable = class_exists('ZipArchive');
    
    if (file_exists('../vendor/autoload.php') && $zipExtensionAvailable) {
        require_once '../vendor/autoload.php';
        $phpSpreadsheetAvailable = class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet');
    }
    
    // Log extension status for debugging
    if (!$zipExtensionAvailable) {
        error_log("CREATE_FACULTY: ZipArchive extension not available - Excel support disabled");
    }

    requireAdmin();

    $database = Database::getInstance();
    $db = $database->getConnection();

    // Load master lists if available
    $deptStmt = $db->prepare("SELECT id, name FROM departments ORDER BY name");
    try { $deptStmt->execute(); $masterDepartments = $deptStmt->fetchAll(); } catch (Exception $e) { $masterDepartments = []; }

    // Get positions from position_salary table
    $allPositions = getAllPositions();

    $empStmt = $db->prepare("SELECT id, name FROM employment_statuses ORDER BY name");
    try { $empStmt->execute(); $masterEmploymentStatuses = $empStmt->fetchAll(); } catch (Exception $e) { $masterEmploymentStatuses = []; }

    $campusStmt = $db->prepare("SELECT id, name FROM campuses ORDER BY name");
    try { $campusStmt->execute(); $masterCampuses = $campusStmt->fetchAll(); } catch (Exception $e) { $masterCampuses = []; }

    $designationStmt = $db->prepare("SELECT id, name FROM designations ORDER BY name");
    try { $designationStmt->execute(); $masterDesignations = $designationStmt->fetchAll(); } catch (Exception $e) { $masterDesignations = []; }

    $keyOfficialsStmt = $db->prepare("SELECT id, name FROM key_officials ORDER BY name");
    try { $keyOfficialsStmt->execute(); $masterKeyOfficials = $keyOfficialsStmt->fetchAll(); } catch (Exception $e) { $masterKeyOfficials = []; }

    $error = '';
    $success = '';
    $generatedPassword = '';
    $createdEmail = '';
    $batchResults = [];
    $batchErrors = [];
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    $errorMsg = "CRITICAL ERROR in create_faculty.php initialization: " . $e->getMessage();
    error_log($errorMsg);
    error_log("Stack trace: " . $e->getTraceAsString());
    die("Error initializing page. Error ID: " . md5($errorMsg) . " (logged for investigation)");
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['batch_file'])) {
    // Ensure batch variables are initialized
    $batchResults = [];
    $batchErrors = [];
    
    try {
        error_log("CREATE_FACULTY: Batch upload attempt");
        if (!isset($_POST['csrf_token']) || !validateFormToken($_POST['csrf_token'])) {
            $error = "Invalid form submission. Please try again.";
        } else {
            $file = $_FILES['batch_file'];
        
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = "File upload error (Code: " . $file['error'] . "). Please try again.";
                error_log("CREATE_FACULTY: File upload error code: " . $file['error']);
            } else {
                $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if (!in_array($fileExt, ['csv', 'xlsx', 'xls'])) {
                    $error = "Invalid file format. Please upload a CSV or Excel file.";
                } else {
                    try {
                        $rows = [];
                    
                    // Process file based on type and availability of PHPSpreadsheet
                    if ($fileExt === 'csv' || !$phpSpreadsheetAvailable) {
                        // Use native CSV parsing
                        if ($fileExt !== 'csv') {
                            $error = "Excel file uploaded but PHPSpreadsheet library is not installed. Please upload a CSV file instead or install the required library.";
                        } else {
                            if (($handle = fopen($file['tmp_name'], 'r')) !== false) {
                                while (($row = fgetcsv($handle)) !== false) {
                                    $rows[] = $row;
                                }
                                fclose($handle);
                                
                                // Check if file is empty or has no data rows
                                if (empty($rows)) {
                                    $error = "The CSV file appears to be empty. Please ensure it contains data rows.";
                                    error_log("CREATE_FACULTY: CSV file is empty");
                                }
                            } else {
                                $error = "Failed to read CSV file. Please ensure the file is not corrupted.";
                                error_log("CREATE_FACULTY: Failed to open CSV file");
                            }
                        }
                    } else {
                        // Use PHPSpreadsheet for Excel files with improved error handling
                        // Double-check that ZipArchive is available (required for Excel files)
                        if (!class_exists('ZipArchive')) {
                            throw new Exception("The ZipArchive PHP extension is required to read Excel files but is not installed. Please upload a CSV file instead, or contact your system administrator to enable the PHP zip extension.");
                        }
                        
                        try {
                            // Set reader options for better compatibility
                            $inputFileType = \PhpOffice\PhpSpreadsheet\IOFactory::identify($file['tmp_name']);
                            
                            if (!in_array($inputFileType, ['Xlsx', 'Xls', 'Ods'])) {
                                throw new Exception("Unsupported Excel file format. Please use .xlsx, .xls, or .ods files.");
                            }
                            
                            // Create reader with options
                            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($inputFileType);
                            
                            // Set read options for better performance and compatibility
                            if ($inputFileType === 'Xlsx') {
                                $reader->setReadDataOnly(true); // Read only data, not formatting (improves performance)
                            }
                            
                            // Load spreadsheet
                            $spreadsheet = $reader->load($file['tmp_name']);
                            $worksheet = $spreadsheet->getActiveSheet();
                            
                            // Get highest row and column more reliably
                            $highestRow = $worksheet->getHighestRow();
                            $highestColumn = $worksheet->getHighestColumn();
                            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
                            
                            // Read rows manually for better control
                            $rows = [];
                            for ($row = 1; $row <= $highestRow; $row++) {
                                $rowData = [];
                                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                                    $cell = $worksheet->getCellByColumnAndRow($col, $row);
                                    $value = $cell->getValue();
                                    
                                    // Handle calculated/formula values
                                    if ($cell->getDataType() === \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA) {
                                        try {
                                            $value = $cell->getCalculatedValue();
                                        } catch (\Exception $e) {
                                            $value = ''; // Use empty string if calculation fails
                                        }
                                    }
                                    
                                    // Convert to string and trim
                                    $rowData[] = $value !== null ? trim((string)$value) : '';
                                }
                                $rows[] = $rowData;
                            }
                            
                            // Clean up
                            $spreadsheet->disconnectWorksheets();
                            unset($spreadsheet);
                            
                        } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
                            throw new Exception("Failed to read Excel file: " . $e->getMessage() . ". Please ensure the file is not corrupted and is a valid Excel file.");
                        } catch (\Exception $e) {
                            throw new Exception("Error processing Excel file: " . $e->getMessage());
                        }
                    }
                    
                    if (!empty($rows) && empty($error)) {
                        // Skip header row
                        $header = array_shift($rows);
                        
                        // Check if we have any data rows after removing header
                        if (empty($rows)) {
                            $error = "The file contains only a header row. Please add data rows to create accounts.";
                            error_log("CREATE_FACULTY: File contains only header, no data rows");
                        }
                        
                        $successCount = 0;
                        $errorCount = 0;
                    
                    foreach ($rows as $index => $row) {
                        $rowNum = $index + 2; // +2 because we skipped header and arrays are 0-indexed
                        
                        // Skip empty rows
                        if (empty(array_filter($row))) {
                            continue;
                        }
                        
                        // Extract data with robust null/empty handling
                        // Helper function to safely extract cell value
                        $getCellValue = function($row, $index, $default = '') {
                            if (!isset($row[$index])) {
                                return $default;
                            }
                            $value = $row[$index];
                            // Handle null, empty, or whitespace-only values
                            if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
                                return $default;
                            }
                            // Convert to string and trim
                            return trim((string)$value);
                        };
                        
                        $firstName = $getCellValue($row, 0);
                        $lastName = $getCellValue($row, 1);
                        $middleName = $getCellValue($row, 2);
                        $email = $getCellValue($row, 3);
                        $userType = $getCellValue($row, 4, 'faculty');
                        $gender = $getCellValue($row, 5);
                        $campus = $getCellValue($row, 6);
                        $department = $getCellValue($row, 7);
                        $position = $getCellValue($row, 8);
                        $designation = $getCellValue($row, 9);
                        $employmentStatus = $getCellValue($row, 10);
                        $keyOfficial = $getCellValue($row, 11);
                        // Convert empty string to NULL for database insertion
                        $employmentStatus = empty($employmentStatus) ? null : $employmentStatus;
                        $keyOfficial = empty($keyOfficial) ? null : $keyOfficial;
                        
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
                            continue;
                        }
                        
                        // Clean user type: remove asterisks, parentheses, and other formatting characters, but preserve letters
                        $userType = trim(strtolower($userType));
                        $userType = preg_replace('/[^a-z\s]/i', '', $userType); // Remove non-alphabetic except spaces
                        $userType = preg_replace('/\s+/', '', $userType); // Remove spaces
                        if (empty($userType)) {
                            $userType = 'faculty'; // Default fallback
                        }
                        
                        // Clean position title (remove "(SG#)" part if present) and get salary grade
                        $salaryGrade = null;
                        if (!empty($position)) {
                            // Remove salary grade suffix if present (e.g., "Position (SG15)" -> "Position")
                            $positionTitle = preg_replace('/\s*\(SG\d+\)\s*$/i', '', $position);
                            $positionTitle = trim($positionTitle);
                            
                            // Always use the cleaned position title for database insertion
                            $position = $positionTitle;
                            
                            // Look up salary grade from position_salary table
                            $positionDetails = getPositionByTitle($positionTitle);
                            if ($positionDetails && isset($positionDetails['salary_grade'])) {
                                $salaryGrade = $positionDetails['salary_grade'];
                            }
                        }
                        
                        // Validate required fields
                        if (empty($firstName) || empty($lastName) || empty($email)) {
                            $batchErrors[] = "Row $rowNum: Missing required fields (First Name, Last Name, or Email)";
                            $errorCount++;
                            continue;
                        }
                        
                        // Validate user type
                        if (!in_array($userType, ['faculty', 'staff'])) {
                            $batchErrors[] = "Row $rowNum: Invalid user type '$userType'. Must be 'faculty' or 'staff'";
                            $errorCount++;
                            continue;
                        }
                        
                        // Validate email
                        if (!validateWPUEmail($email)) {
                            $batchErrors[] = "Row $rowNum: Invalid email '$email'. Only @wpu.edu.ph addresses are allowed";
                            $errorCount++;
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
                            $stmt = $db->prepare("INSERT INTO faculty_profiles (user_id, employee_id, gender, campus, department, position, designation, key_official, employment_status, qr_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            
                            try {
                                $stmt->execute([$userId, $employeeId, $gender, $campus, $department, $position, $designation, $keyOfficial, $employmentStatus, $qrCodePath]);
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
                                'email_sent' => $emailSent,
                                'position' => $position,
                                'salary_grade' => $salaryGrade
                            ];
                            
                            $successCount++;
                            logAction('CREATE_FACULTY_BATCH', "Admin created account: $email (Employee ID: $employeeId)");
                            
                        } catch (Exception $e) {
                            $db->rollBack();
                            $batchErrors[] = "Row $rowNum: Failed to create account for '$email' - " . $e->getMessage();
                            $errorCount++;
                        }
                    }
                    
                    if ($successCount > 0) {
                        $success = "Batch upload completed! Successfully created $successCount account(s).";
                        if ($errorCount > 0) {
                            $success .= " $errorCount row(s) had errors.";
                        }
                    } else {
                        $error = "Batch upload failed. No accounts were created.";
                    }
                    
                    } // Close if (!empty($rows) && empty($error))
                    
                    } catch (Exception $e) {
                        $error = "Failed to process file: " . $e->getMessage();
                        error_log("Batch upload file processing error: " . $e->getMessage());
                        error_log("Batch upload error trace: " . $e->getTraceAsString());
                    }
                }
            }
        }
    } catch (Exception $e) {
        $error = "An unexpected error occurred during batch upload: " . $e->getMessage();
        error_log("CREATE_FACULTY: Unexpected batch upload error: " . $e->getMessage());
        error_log("CREATE_FACULTY: Error trace: " . $e->getTraceAsString());
    }
}

// Now handle single account creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_FILES['batch_file'])) {
        error_log("CREATE_FACULTY: Single account creation attempt");
        $userType = sanitizeInput($_POST['user_type']);
        $email = sanitizeInput($_POST['email']);
        $firstName = sanitizeInput($_POST['first_name']);
        $lastName = sanitizeInput($_POST['last_name']);
    $middleName = sanitizeInput($_POST['middle_name'] ?? '');
    $gender = sanitizeInput($_POST['gender'] ?? '');
    $campus = sanitizeInput($_POST['campus'] ?? '');
    $department = sanitizeInput($_POST['department'] ?? '');
    $position = sanitizeInput($_POST['position'] ?? '');
    $designation = sanitizeInput($_POST['designation'] ?? '');
    $keyOfficial = sanitizeInput($_POST['key_official'] ?? '');
    $employmentStatus = sanitizeInput($_POST['employment_status'] ?? '');
    // Convert empty string to NULL for database insertion
    $employmentStatus = empty($employmentStatus) ? null : $employmentStatus;
    $keyOfficial = empty($keyOfficial) ? null : $keyOfficial;
    
    // Validate user type
    if (!in_array($userType, ['faculty', 'staff'])) {
        $error = "Invalid account type selected.";
    }
    // Validate CSRF token
    elseif (!validateFormToken($_POST['csrf_token'])) {
        $error = "Invalid form submission. Please try again.";
    }
    // Validate WPU email
    elseif (!validateWPUEmail($email)) {
        $error = "Only @wpu.edu.ph email addresses are allowed.";
    }
    else {
        // Normalize email (trim and lowercase)
        $email = trim(strtolower($email));
        
        // Check if email already exists for the same user type (faculty/staff)
        // Allow duplicate emails for different user types (admin vs faculty/staff)
        $stmt = $db->prepare("SELECT id, user_type FROM users WHERE LOWER(TRIM(email)) = ? AND user_type = ?");
        $stmt->execute([$email, $userType]);
        $existingUser = $stmt->fetch();
        
        if ($existingUser) {
            $error = "This email address is already registered as a " . ucfirst($userType) . " account. Each email can only be used once per user type.";
        } else {
            // Generate random password (8 characters: letters and numbers)
            $generatedPassword = bin2hex(random_bytes(4)); // 8 characters
            $hashedPassword = password_hash($generatedPassword, PASSWORD_DEFAULT);
            
            try {
                $db->beginTransaction();
                
                // Ensure SQL_MODE allows AUTO_INCREMENT to work properly
                // This prevents issues with NO_AUTO_VALUE_ON_ZERO mode
                $db->exec("SET sql_mode = REPLACE(@@sql_mode, 'NO_AUTO_VALUE_ON_ZERO', '')");
                
                // Insert user (is_verified = 1 since admin creates it)
                // Explicitly exclude id column to ensure AUTO_INCREMENT works
                $stmt = $db->prepare("INSERT INTO users (email, password, user_type, first_name, last_name, middle_name, is_verified, is_active) VALUES (?, ?, ?, ?, ?, ?, 1, 1)");
                $stmt->execute([$email, $hashedPassword, $userType, $firstName, $lastName, $middleName]);
                
                $userId = $db->lastInsertId();
                
                // Validate userId before proceeding
                if (!$userId || $userId <= 0) {
                    throw new Exception("Invalid user ID ($userId) - cannot create faculty profile");
                }
                
                // Generate employee ID
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
                
                // Create faculty profile with employee ID and QR code
                // Always create faculty profile for faculty users
                // Explicitly exclude id column to ensure AUTO_INCREMENT works
                $stmt = $db->prepare("INSERT INTO faculty_profiles (user_id, employee_id, gender, campus, department, position, designation, key_official, employment_status, qr_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                try {
                    $stmt->execute([$userId, $employeeId, $gender, $campus, $department, $position, $designation, $keyOfficial, $employmentStatus, $qrCodePath]);
                } catch (PDOException $e) {
                    throw new Exception("Failed to create faculty profile: " . $e->getMessage() . " (User ID: $userId)");
                }
                
                $db->commit();
                
                logAction('CREATE_FACULTY', "Admin created faculty account: $email (Employee ID: $employeeId)");
                
                // Send email with login credentials to the faculty member
                $mailer = new Mailer();
                $fullName = trim($firstName . ' ' . $middleName . ' ' . $lastName);
                $loginUrl = SITE_URL . "/login.php";
                
                $emailSent = $mailer->sendAccountCreationEmail($email, $fullName, $userType, $employeeId, $generatedPassword, $loginUrl);
                
                if ($emailSent) {
                    $success = ucfirst($userType) . " account created successfully! Safe Employee ID: $employeeId. Login credentials have been sent to " . htmlspecialchars($email) . ".";
                } else {
                    $success = ucfirst($userType) . " account created successfully! Safe Employee ID: $employeeId. However, the email could not be sent. Please provide the credentials manually.";
                }
                
                $createdEmail = $email;
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to create faculty account: " . $e->getMessage();
                error_log("Failed to create faculty: " . $e->getMessage());
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    require_once '../includes/admin_layout_helper.php';
    admin_page_head('Create Faculty/Staff Account', 'Create a new faculty or staff account');
    ?>
</head>
<body class="layout-admin">
    <?php require_once '../includes/navigation.php';
    include_navigation(); ?>
    
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <?php
                admin_page_header(
                    'Create Faculty/Staff Account',
                    '',
                    'fas fa-user-plus',
                    [
                        
                    ],
                    '<a href="faculty.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back to List</a>'
                );
                ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        <?php if (empty($batchResults)): ?>
                        <div class="mt-2">
                            <a href="create_faculty.php" class="btn btn-success btn-sm">
                                <i class="fas fa-user-plus me-2"></i>Create Another Account
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($batchResults)): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>Successfully Created Accounts</h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Important:</strong> Please save these credentials! Passwords will not be shown again.
                            </div>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Safe Employee ID</th>
                                            <th>Password</th>
                                            <th>Type</th>
                                            <th>Position</th>
                                            <th>Salary Grade</th>
                                            <th>Email Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($batchResults as $result): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($result['name']); ?></td>
                                            <td><?php echo htmlspecialchars($result['email']); ?></td>
                                            <td><strong><?php echo htmlspecialchars($result['employee_id']); ?></strong></td>
                                            <td><code><?php echo htmlspecialchars($result['password']); ?></code></td>
                                            <td><span class="badge bg-info"><?php echo ucfirst($result['user_type']); ?></span></td>
                                            <td><?php echo htmlspecialchars($result['position'] ?? '-'); ?></td>
                                            <td>
                                                <?php if (!empty($result['salary_grade'])): ?>
                                                    <span class="badge bg-success">SG<?php echo htmlspecialchars($result['salary_grade']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($result['email_sent']): ?>
                                                    <span class="badge bg-success"><i class="fas fa-check"></i> Sent</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning"><i class="fas fa-exclamation"></i> Failed</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-3">
                                <button onclick="window.print()" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-print me-2"></i>Print Credentials
                                </button>
                                <a href="create_faculty.php" class="btn btn-success btn-sm">
                                    <i class="fas fa-upload me-2"></i>Upload Another Batch
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($batchErrors)): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0"><i class="fas fa-exclamation-circle me-2"></i>Batch Upload Errors</h5>
                        </div>
                        <div class="card-body">
                            <ul class="mb-0">
                                <?php foreach ($batchErrors as $batchError): ?>
                                    <li><?php echo htmlspecialchars($batchError); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Single Card with Both Options -->
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-user-plus me-2"></i>Create Faculty/Staff Account</h5>
                    </div>
                    <div class="card-body">
                        <!-- Batch Upload Option Section -->
                        <div class="row align-items-center p-3 mb-4 bg-light rounded border">
                            <div class="col-md-9">
                                <h5 class="mb-1"><i class="fas fa-users me-2 text-primary"></i>Need to create multiple accounts?</h5>
                                <p class="mb-0 text-muted">Upload a CSV or Excel file to create multiple accounts at once.</p>
                            </div>
                            <div class="col-md-3 text-end">
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#batchUploadModal">
                                    <i class="fas fa-upload me-2"></i>Batch Upload
                                </button>
                            </div>
                        </div>

                        <!-- Divider -->
                        <div class="text-center mb-4">
                            <span class="badge bg-secondary">OR</span>
                        </div>

                        <!-- Single Account Form Section -->
                        <form method="POST" id="createFacultyForm">
                            <?php addFormToken(); ?>
                            
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <h5 class="text-primary border-bottom pb-2 mb-3">
                                        <i class="fas fa-id-card me-2"></i>Personal Information
                                    </h5>
                                </div>
                                
                                <div class="col-md-12">
                                    <label class="form-label">Account Type <span class="text-danger">*</span></label>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="user_type" id="user_type_faculty" value="faculty" checked>
                                                <label class="form-check-label" for="user_type_faculty">
                                                    <strong>Faculty</strong> - Teaching staff member
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="user_type" id="user_type_staff" value="staff">
                                                <label class="form-check-label" for="user_type_staff">
                                                    <strong>Staff</strong> - Administrative or support staff member
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" required>
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="middle_name" class="form-label">Middle Name</label>
                                    <input type="text" class="form-control" id="middle_name" name="middle_name">
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" required>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="gender" class="form-label">Gender</label>
                                    <select class="form-control" id="gender" name="gender">
                                        <option value="">Select Gender</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                        <option value="Other">Other</option>
                                        <option value="Prefer not to say">Prefer not to say</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-6">
                                    <label for="campus" class="form-label">Campus</label>
                                    <select class="form-control" id="campus" name="campus">
                                        <option value="">Select Campus</option>
                                        <?php foreach ($masterCampuses as $c): ?>
                                            <option value="<?php echo htmlspecialchars($c['name']); ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-12">
                                    <label for="email" class="form-label">WPU Email Address <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                        <input type="email" class="form-control" id="email" name="email" placeholder="name@wpu.edu.ph" required>
                                    </div>
                                    <div class="form-text">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Only @wpu.edu.ph email addresses are allowed. A random password will be auto-generated.
                                    </div>
                                </div>
                                
                                <div class="col-md-12">
                                    <h5 class="text-primary border-bottom pb-2 mt-3 mb-3">
                                        <i class="fas fa-briefcase me-2"></i>Employment Information (Optional)
                                    </h5>
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="department" class="form-label">Department</label>
                                    <select class="form-control" id="department" name="department">
                                        <option value="">Select Department</option>
                                        <?php foreach ($masterDepartments as $d): ?>
                                            <option value="<?php echo htmlspecialchars($d['name']); ?>"><?php echo htmlspecialchars($d['name']); ?></option>
                                        <?php endforeach; ?>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="position" class="form-label">Position</label>
                                    <select class="form-control" id="position" name="position">
                                        <option value="">Select Position</option>
                                        <?php foreach ($allPositions as $p): ?>
                                            <option value="<?php echo htmlspecialchars($p['position_title']); ?>" 
                                                    data-salary-grade="<?php echo $p['salary_grade']; ?>"
                                                    data-annual-salary="<?php echo $p['annual_salary']; ?>">
                                                <?php echo htmlspecialchars($p['position_title']); ?> 
                                                (SG-<?php echo $p['salary_grade']; ?> - ₱<?php echo number_format($p['annual_salary'], 2); ?>/year)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="form-text text-muted" id="position-details"></small>
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="designation" class="form-label">Designation</label>
                                    <select class="form-control" id="designation" name="designation">
                                        <option value="">Select Designation</option>
                                        <?php foreach ($masterDesignations as $d): ?>
                                            <option value="<?php echo htmlspecialchars($d['name']); ?>"><?php echo htmlspecialchars($d['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="form-text text-muted">e.g., Dean, Program Chair</small>
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="employment_status" class="form-label">Employment Status</label>
                                    <select class="form-control" id="employment_status" name="employment_status">
                                        <option value="">Select Status</option>
                                        <?php foreach ($masterEmploymentStatuses as $es): ?>
                                            <option value="<?php echo htmlspecialchars($es['name']); ?>"><?php echo htmlspecialchars($es['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="key_official" class="form-label">Key Official</label>
                                    <select class="form-control" id="key_official" name="key_official">
                                        <option value="">Select Key Official</option>
                                        <?php foreach ($masterKeyOfficials as $k): ?>
                                            <option value="<?php echo htmlspecialchars($k['name']); ?>"><?php echo htmlspecialchars($k['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="form-text text-muted">e.g., President, VP Academic</small>
                                </div>
                                
                                <div class="col-md-12">
                                    <div class="alert alert-info">
                                        <i class="fas fa-lightbulb me-2"></i>
                                        <strong>Note:</strong> The system will automatically generate a secure password for this account. 
                                        <?php if (defined('ENABLE_MAIL') && ENABLE_MAIL === true): ?>
                                        The login credentials will be automatically sent to the faculty member's email address.
                                        <?php else: ?>
                                        <span class="text-warning">Email sending is currently disabled. Make sure to save and provide the credentials to the faculty member after creation.</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="col-md-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-user-plus me-2"></i>Create Account
                                    </button>
                                    <a href="faculty.php" class="btn btn-secondary">
                                        <i class="fas fa-times me-2"></i>Cancel
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div><!-- End card-body -->
                </div><!-- End card -->
            </main>
        </div>
    </div>

    <!-- Batch Upload Modal -->
    <div class="modal fade" id="batchUploadModal" tabindex="-1" aria-labelledby="batchUploadModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="batchUploadModalLabel" style="color: white;">
                        <i class="fas fa-users me-2"></i>Batch Upload Accounts
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle me-2"></i>How to use Batch Upload:</h6>
                        <ol class="mb-0">
                            <li>Download the template file below</li>
                            <li>Fill in the account information (required fields marked with *)</li>
                            <li>Save and upload the completed file</li>
                            <li>Review the results and save the generated credentials</li>
                        </ol>
                    </div>

                    <!-- Download Template Section -->
                    <div class="card mb-3 bg-light border-success">
                        <div class="card-body">
                            <h6><i class="fas fa-download me-2 text-success"></i>Step 1: Download Template</h6>
                            <p class="mb-2">Use this template to prepare your batch upload file:</p>
                            <?php if (!$phpSpreadsheetAvailable): ?>
                                <div class="alert alert-warning mb-2">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <small>
                                        <?php if (!class_exists('ZipArchive')): ?>
                                            <strong>Excel support unavailable:</strong> The PHP zip extension is not installed.
                                        <?php else: ?>
                                            Excel library not installed.
                                        <?php endif; ?>
                                        CSV template will be downloaded instead. Please use CSV files for batch upload.
                                    </small>
                                </div>
                            <?php endif; ?>
                            <a href="download_template.php" class="btn btn-success">
                                <i class="fas fa-file-excel me-2"></i>Download <?php echo $phpSpreadsheetAvailable ? 'Excel' : 'CSV'; ?> Template
                            </a>
                        </div>
                    </div>

                    <!-- Upload File Section -->
                    <div class="card border-primary">
                        <div class="card-body">
                            <h6><i class="fas fa-upload me-2 text-primary"></i>Step 2: Upload Completed File</h6>
                            <form method="POST" enctype="multipart/form-data" id="batchUploadForm">
                                <?php addFormToken(); ?>
                                
                                <div class="mb-3">
                                    <label for="batch_file" class="form-label fw-bold">Select CSV or Excel File <span class="text-danger">*</span></label>
                                    <input type="file" class="form-control form-control-lg" id="batch_file" name="batch_file" accept=".csv<?php echo $phpSpreadsheetAvailable ? ',.xlsx,.xls' : ''; ?>" required>
                                    <div class="form-text">
                                        <i class="fas fa-file-alt me-1"></i>
                                        Supported formats: CSV (.csv)<?php echo $phpSpreadsheetAvailable ? ', Excel (.xlsx, .xls)' : ' only'; ?>
                                        <?php if (!$phpSpreadsheetAvailable): ?>
                                            <br><strong>Note:</strong> Excel files require the PHP zip extension and PHPSpreadsheet library. Please use CSV files.
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="accordion mb-3" id="uploadInstructions">
                                    <div class="accordion-item">
                                        <h2 class="accordion-header" id="headingRequirements">
                                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseRequirements">
                                                <i class="fas fa-exclamation-triangle me-2 text-warning"></i>Required & Optional Fields
                                            </button>
                                        </h2>
                                        <div id="collapseRequirements" class="accordion-collapse collapse" data-bs-parent="#uploadInstructions">
                                            <div class="accordion-body">
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <strong class="text-danger"><i class="fas fa-asterisk me-1"></i>Required Fields:</strong>
                                                        <ul class="mb-0">
                                                            <li>First Name</li>
                                                            <li>Last Name</li>
                                                            <li>Email (@wpu.edu.ph)</li>
                                                            <li>User Type (faculty/staff)</li>
                                                        </ul>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <strong class="text-secondary"><i class="fas fa-list me-1"></i>Optional Fields:</strong>
                                                        <ul class="mb-0">
                                                            <li>Middle Name</li>
                                                            <li>Gender</li>
                                                            <li>Campus</li>
                                                            <li>Department</li>
                                                            <li>Position</li>
                                                            <li>Designation</li>
                                                            <li>Employment Status</li>
                                                            <li>Key Official</li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="alert alert-secondary">
                                    <i class="fas fa-key me-2"></i>
                                    <strong>Auto-Generated Credentials:</strong> Passwords will be automatically generated for each account. Make sure to save the credentials after upload.
                                </div>
                                
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fas fa-upload me-2"></i>Upload and Create Accounts
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                        <i class="fas fa-times me-2"></i>Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal for Batch Upload -->
    <div class="modal fade" id="batchConfirmModal" tabindex="-1" aria-labelledby="batchConfirmModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header border-0 pb-0" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                    <div class="w-100 text-center py-3">
                        <div class="mb-3">
                            <i class="fas fa-exclamation-triangle fa-4x text-white" style="filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));"></i>
                        </div>
                        <h4 class="modal-title text-white mb-0" id="batchConfirmModalLabel">
                            <strong>Confirm Batch Upload</strong>
                        </h4>
                    </div>
                </div>
                <div class="modal-body text-center py-4 px-4">
                    <div class="alert alert-warning border-warning mb-4" style="background-color: #fff3cd; border-left: 4px solid #ffc107;">
                        <div class="d-flex align-items-start">
                            <i class="fas fa-info-circle fa-2x text-warning me-3 mt-1"></i>
                            <div class="text-start">
                                <h5 class="alert-heading mb-2 text-dark">
                                    <strong>Are you sure you want to create accounts from this file?</strong>
                                </h5>
                                <p class="mb-0 text-dark">
                                    Please make sure you have reviewed the data before proceeding. This action will create multiple user accounts in the system.
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-light rounded p-3 mb-3">
                        <div class="row text-start">
                            <div class="col-12 mb-2">
                                <i class="fas fa-file-alt text-primary me-2"></i>
                                <strong>File:</strong> <span id="confirmFileName" class="text-muted">-</span>
                            </div>
                            <div class="col-12">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <small class="text-muted">Please verify all data is correct before confirming.</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-center gap-2 flex-wrap">
                        <button type="button" class="btn btn-lg btn-primary px-5" id="confirmBatchUpload">
                            <i class="fas fa-check me-2"></i>Yes, Create Accounts
                        </button>
                        <button type="button" class="btn btn-lg btn-outline-secondary px-5" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        #batchConfirmModal .modal-content {
            border-radius: 15px;
            overflow: hidden;
        }
        
        #batchConfirmModal .modal-header {
            border-radius: 15px 15px 0 0;
        }
        
        #batchConfirmModal .alert-warning {
            border-radius: 10px;
        }
        
        #batchConfirmModal .btn-lg {
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        #batchConfirmModal .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        #batchConfirmModal .btn-outline-secondary:hover {
            transform: translateY(-2px);
        }
        
        #batchConfirmModal .fa-exclamation-triangle {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }
    </style>

    <?php admin_page_scripts(); ?>
    <script>
        // Position selection handler - display salary info
        document.addEventListener('DOMContentLoaded', function() {
            const positionSelect = document.getElementById('position');
            const positionDetails = document.getElementById('position-details');
            
            if (positionSelect && positionDetails) {
                positionSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    if (selectedOption.value) {
                        const salaryGrade = selectedOption.dataset.salaryGrade;
                        const annualSalary = parseFloat(selectedOption.dataset.annualSalary);
                        const monthlySalary = annualSalary / 12;
                        
                        positionDetails.innerHTML = `<i class="fas fa-info-circle me-1"></i>Salary Grade ${salaryGrade}: ₱${annualSalary.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}/year (₱${monthlySalary.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}/month)`;
                        positionDetails.classList.add('text-success');
                    } else {
                        positionDetails.innerHTML = '';
                        positionDetails.classList.remove('text-success');
                    }
                });
            }
        });
        
        // Email validation
        document.getElementById('email').addEventListener('blur', function() {
            const email = this.value;
            if (email && !email.endsWith('@wpu.edu.ph')) {
                this.setCustomValidity('Only @wpu.edu.ph email addresses are allowed');
                this.reportValidity();
            } else {
                this.setCustomValidity('');
            }
        });
        
        // File upload validation
        const batchFileInput = document.getElementById('batch_file');
        if (batchFileInput) {
            batchFileInput.addEventListener('change', function() {
                const file = this.files[0];
                if (file) {
                    const fileExt = file.name.split('.').pop().toLowerCase();
                    const allowedExts = ['csv', 'xlsx', 'xls'];
                    
                    if (!allowedExts.includes(fileExt)) {
                        alert('Invalid file format. Please upload a CSV or Excel file.');
                        this.value = '';
                        return;
                    }
                    
                    const maxSize = 5 * 1024 * 1024; // 5MB
                    if (file.size > maxSize) {
                        alert('File size must be less than 5MB.');
                        this.value = '';
                        return;
                    }
                }
            });
        }
        
        // Batch upload form confirmation with styled modal
        const batchForm = document.getElementById('batchUploadForm');
        const confirmModal = new bootstrap.Modal(document.getElementById('batchConfirmModal'));
        let formSubmitted = false;
        
        if (batchForm) {
            batchForm.addEventListener('submit', function(e) {
                const file = document.getElementById('batch_file').files[0];
                if (!file) {
                    e.preventDefault();
                    alert('Please select a file to upload.');
                    return false;
                }
                
                // Prevent default submission
                e.preventDefault();
                
                // Show file name in confirmation modal
                document.getElementById('confirmFileName').textContent = file.name;
                
                // Show confirmation modal
                confirmModal.show();
            });
            
            // Handle confirmation button click
            document.getElementById('confirmBatchUpload').addEventListener('click', function() {
                formSubmitted = true;
                confirmModal.hide();
                // Submit the form after modal closes
                setTimeout(function() {
                    batchForm.submit();
                }, 300);
            });
            
            // Reset flag when modal is hidden without confirmation
            document.getElementById('batchConfirmModal').addEventListener('hidden.bs.modal', function() {
                if (!formSubmitted) {
                    // User cancelled, do nothing
                }
                formSubmitted = false;
            });
        }
        
        // Prevent auto-opening modals on page load to avoid duplicate submissions
        // Modals should only be opened by explicit user action
        // Removed auto-open functionality to prevent accidental duplicate submissions
        
        // Additional safeguards to prevent duplicate submissions on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Ensure no modals are auto-opened when page loads
            const modals = document.querySelectorAll('.modal');
            modals.forEach(function(modal) {
                const bsModal = bootstrap.Modal.getInstance(modal);
                if (bsModal && modal.classList.contains('show')) {
                    // Only hide if modal is currently shown (shouldn't happen on fresh load)
                    bsModal.hide();
                }
            });
            
            // Remove any submission flags from forms
            const forms = document.querySelectorAll('form');
            forms.forEach(function(form) {
                form.classList.remove('is-submitting', 'was-submitted');
            });
            
            // Prevent browser back/forward from resubmitting forms
            if (window.history && window.history.replaceState) {
                // Replace current state to prevent form resubmission on back button
                window.history.replaceState(null, '', window.location.href);
            }
        });
        
        // Prevent form resubmission on page refresh/reload (browser back/forward)
        if (window.history && window.history.replaceState) {
            window.addEventListener('pageshow', function(event) {
                // If page was loaded from cache (back/forward), ensure forms are not in submitted state
                if (event.persisted) {
                    const forms = document.querySelectorAll('form');
                    forms.forEach(function(form) {
                        form.classList.remove('is-submitting', 'was-submitted');
                        // Clear any file inputs to prevent accidental resubmission
                        const fileInputs = form.querySelectorAll('input[type="file"]');
                        fileInputs.forEach(function(input) {
                            input.value = '';
                        });
                    });
                }
            });
        }
    </script>
</body>
</html>

