<?php
// Prevent browser caching of this page to ensure QR code URLs are always fresh
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/upload.php';
require_once '../includes/qr_code_helper.php';

requireFaculty();

$database = Database::getInstance();
$db = $database->getConnection();

// Load master lists if available
$deptStmt = $db->prepare("SELECT id, name FROM departments ORDER BY name");
try { $deptStmt->execute(); $masterDepartments = $deptStmt->fetchAll(); } catch (Exception $e) { $masterDepartments = []; }
if (!is_array($masterDepartments)) { $masterDepartments = []; }

// Get positions from position_salary table
$allPositions = getAllPositions();
if (!is_array($allPositions)) { $allPositions = []; }

$empStmt = $db->prepare("SELECT id, name FROM employment_statuses ORDER BY name");
try { $empStmt->execute(); $masterEmploymentStatuses = $empStmt->fetchAll(); } catch (Exception $e) { $masterEmploymentStatuses = []; }
if (!is_array($masterEmploymentStatuses)) { $masterEmploymentStatuses = []; }

$campusStmt = $db->prepare("SELECT id, name FROM campuses ORDER BY name");
try { $campusStmt->execute(); $masterCampuses = $campusStmt->fetchAll(); } catch (Exception $e) { $masterCampuses = []; }
if (!is_array($masterCampuses)) { $masterCampuses = []; }

$designationStmt = $db->prepare("SELECT id, name FROM designations ORDER BY name");
try { $designationStmt->execute(); $masterDesignations = $designationStmt->fetchAll(); } catch (Exception $e) { $masterDesignations = []; }
if (!is_array($masterDesignations)) { $masterDesignations = []; }

$uploader = new FileUploader();

$userId = $_SESSION['user_id'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'upload_profile_picture') {
        // Validate CSRF token
        if (!validateFormToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = "Invalid form submission. Please try again.";
            header('Location: profile.php');
            exit();
        }

        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $result = $uploader->uploadFile($_FILES['profile_picture'], 'profiles', $allowed, MAX_FILE_SIZE);

            if ($result['success']) {
                $uploadedPath = $result['file_path']; // e.g. 'profiles/abcd.jpg'
                $filenameOnly = basename($uploadedPath);

                // Get existing profile if any
                $stmt = $db->prepare("SELECT id, profile_picture FROM faculty_profiles WHERE user_id = ?");
                $stmt->execute([$userId]);
                $existing = $stmt->fetch();

                if ($existing) {
                    // Delete old file if exists
                    if (!empty($existing['profile_picture'])) {
                        $uploader->deleteFile('profiles/' . $existing['profile_picture']);
                    }
                    // Update existing profile
                    $stmt = $db->prepare("UPDATE faculty_profiles SET profile_picture = ?, updated_at = NOW() WHERE user_id = ?");
                    $updated = $stmt->execute([$filenameOnly, $userId]);
                } else {
                    // Insert new profile
                    $stmt = $db->prepare("INSERT INTO faculty_profiles (user_id, profile_picture, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
                    $updated = $stmt->execute([$userId, $filenameOnly]);
                }

                if ($updated) {
                    $_SESSION['success'] = 'Profile picture updated successfully!';
                    logAction('PROFILE_PICTURE_UPDATE', 'Updated profile picture to: ' . $filenameOnly);
                } else {
                    $_SESSION['error'] = 'Failed to save profile picture to database.';
                }
            } else {
                $_SESSION['error'] = $result['message'] ?? 'Failed to upload file.';
            }
        } else {
            $errorMsg = 'Please select a valid image file to upload.';
            if (isset($_FILES['profile_picture'])) {
                switch ($_FILES['profile_picture']['error']) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $errorMsg = 'The uploaded file is too large.';
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $errorMsg = 'No file was uploaded.';
                        break;
                    default:
                        $errorMsg = 'An error occurred during file upload. Error code: ' . $_FILES['profile_picture']['error'];
                }
            }
            $_SESSION['error'] = $errorMsg;
        }

        header('Location: profile.php?_=' . time());
        exit();
    }
    
    if ($action === 'update_all_profile_info') {
        $successMessages = [];
        $errorMessages = [];

        // Update Personal Information
        // First, get existing user data to preserve disabled fields
        $stmt = $db->prepare("SELECT first_name, last_name, middle_name, email FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $existingUser = $stmt->fetch();
        
        // Use POST values if provided, otherwise use existing database values
        $firstName = !empty($_POST['first_name']) ? sanitizeInput($_POST['first_name']) : ($existingUser['first_name'] ?? '');
        $lastName = !empty($_POST['last_name']) ? sanitizeInput($_POST['last_name']) : ($existingUser['last_name'] ?? '');
        $middleName = isset($_POST['middle_name']) ? sanitizeInput($_POST['middle_name']) : ($existingUser['middle_name'] ?? '');
        
        // Email is disabled in the form, so always use existing value from database to prevent data loss
        $email = !empty($existingUser['email']) ? trim(strtolower($existingUser['email'])) : '';
        
        // Get old name to check if QR code needs regeneration
        $nameChanged = false;
        if ($existingUser) {
            $nameChanged = ($existingUser['first_name'] !== $firstName || $existingUser['last_name'] !== $lastName);
        }
        
        $stmt = $db->prepare("UPDATE users SET first_name = ?, last_name = ?, middle_name = ?, email = ?, updated_at = NOW() WHERE id = ?");
        if ($stmt->execute([$firstName, $lastName, $middleName, $email, $userId])) {
            $successMessages[] = "Personal information updated successfully!";
            logAction('PROFILE_UPDATE', "Updated personal information");
            
            // Regenerate QR code if name changed (but QR code is based on employee ID, so regenerate if employee ID exists)
            if ($nameChanged) {
                // Get employee ID from profile
                $stmt = $db->prepare("SELECT employee_id FROM faculty_profiles WHERE user_id = ?");
                $stmt->execute([$userId]);
                $profile = $stmt->fetch();
                if ($profile && !empty($profile['employee_id'])) {
                    $qrCodePath = generateQRCode($userId, $profile['employee_id']);
                    if ($qrCodePath) {
                        // Update QR code in faculty profile
                        $stmt = $db->prepare("UPDATE faculty_profiles SET qr_code = ? WHERE user_id = ?");
                        $stmt->execute([$qrCodePath, $userId]);
                    }
                }
            }
        } else {
            $errorMessages[] = "Failed to update personal information.";
        }

        // Update Faculty and Staff Profile
        // First, get existing profile data to preserve disabled/readonly fields
        $stmt = $db->prepare("SELECT * FROM faculty_profiles WHERE user_id = ?");
        $stmt->execute([$userId]);
        $existingProfile = $stmt->fetch();
        
        // Use existing values for disabled/readonly fields, or POST values if provided
        // Employee ID is readonly, so always use existing value
        $employeeId = !empty($existingProfile['employee_id']) ? $existingProfile['employee_id'] : sanitizeInput($_POST['employee_id'] ?? '');
        
        // Department, Employment Status, and Campus are now editable by faculty - use POST values
        $department = isset($_POST['department']) ? sanitizeInput($_POST['department']) : ($existingProfile['department'] ?? '');
        $campus = isset($_POST['campus']) ? sanitizeInput($_POST['campus']) : ($existingProfile['campus'] ?? '');
        $employmentStatus = isset($_POST['employment_status']) ? sanitizeInput($_POST['employment_status']) : ($existingProfile['employment_status'] ?? '');
        // Convert empty string to NULL for database insertion
        $employmentStatus = empty($employmentStatus) ? null : $employmentStatus;
        
        // Position remains admin-only - use existing value
        $position = !empty($existingProfile['position']) ? $existingProfile['position'] : sanitizeInput($_POST['position'] ?? '');
        
        // Editable fields: use POST value if provided, otherwise use existing value
        // Convert empty to NULL (MySQL rejects '' for date columns)
        $hireDateRaw = !empty($_POST['hire_date']) ? trim($_POST['hire_date']) : ($existingProfile['hire_date'] ?? '');
        $hireDate = ($hireDateRaw === '' || $hireDateRaw === '0000-00-00') ? null : $hireDateRaw;
        $phone = isset($_POST['phone']) ? sanitizeInput($_POST['phone']) : ($existingProfile['phone'] ?? '');
        $address = isset($_POST['address']) ? sanitizeInput($_POST['address']) : ($existingProfile['address'] ?? '');
        
        // Check if employee_id is already used by another faculty (only if it's being changed)
        $newEmployeeId = sanitizeInput($_POST['employee_id'] ?? '');
        if (!empty($newEmployeeId) && $newEmployeeId !== $employeeId) {
            $stmt = $db->prepare("SELECT user_id FROM faculty_profiles WHERE employee_id = ? AND user_id != ?");
            $stmt->execute([$newEmployeeId, $userId]);
            if ($stmt->fetch()) {
                $errorMessages[] = "Safe Employee ID already in use by another faculty member.";
            } else {
                // Only update employee_id if it's different and not empty
                $employeeId = $newEmployeeId;
            }
        }
        
        // Only proceed if no employee ID conflict
        if (empty($errorMessages)) {
            // Check if faculty and staff profile exists
            $stmt = $db->prepare("SELECT id FROM faculty_profiles WHERE user_id = ?");
            $stmt->execute([$userId]);
            $profileExists = $stmt->fetch();
            
            // Generate or regenerate QR code using the employee ID
            $qrCodePath = generateQRCode($userId, $employeeId);
            
            if ($profileExists) {
                // Update existing profile (include QR code and campus)
                // Preserve all existing values for fields that weren't submitted
                $stmt = $db->prepare("UPDATE faculty_profiles SET employee_id = ?, department = ?, campus = ?, position = ?, employment_status = ?, hire_date = ?, phone = ?, address = ?, qr_code = ?, updated_at = NOW() WHERE user_id = ?");
                $result = $stmt->execute([$employeeId, $department, $campus, $position, $employmentStatus, $hireDate, $phone, $address, $qrCodePath, $userId]);
            } else {
                // Create new profile (include QR code and campus)
                $stmt = $db->prepare("INSERT INTO faculty_profiles (user_id, employee_id, department, campus, position, employment_status, hire_date, phone, address, qr_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $result = $stmt->execute([$userId, $employeeId, $department, $campus, $position, $employmentStatus, $hireDate, $phone, $address, $qrCodePath]);
            }
        } else {
            $result = false;
        }
        
        if ($result) {
            $successMessages[] = "Faculty and staff profile updated successfully!";
            logAction('FACULTY_PROFILE_UPDATE', "Updated faculty and staff profile information");
        } else {
            $errorMessages[] = "Failed to update faculty and staff profile.";
        }

        $allSuccessful = empty($errorMessages);

        if ($allSuccessful) {
            $_SESSION['success'] = "Your profile information has been updated successfully!";
        } else {
            // If there are errors, display them along with any partial successes
            if (!empty($successMessages)) {
                $_SESSION['success'] = implode('<br>', $successMessages);
            }
            if (!empty($errorMessages)) {
                $_SESSION['error'] = implode('<br>', $errorMessages);
            }
        }
        
        header('Location: profile.php?_=' . time());
        exit();
    }
}

// Get user information (optimized: only select needed columns)
$stmt = $db->prepare("SELECT id, first_name, last_name, middle_name, email, is_active, user_type FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Check if user exists and ensure it's an array
if (!$user || !is_array($user)) {
    $_SESSION['error'] = 'User not found.';
    header('Location: dashboard.php');
    exit();
}

// Get faculty and staff profile
$stmt = $db->prepare("SELECT * FROM faculty_profiles WHERE user_id = ?");
$stmt->execute([$userId]);
$facultyProfile = $stmt->fetch();

// Ensure $facultyProfile is an array (not false)
if (!$facultyProfile || !is_array($facultyProfile)) {
    $facultyProfile = [];
}

// Helper function to convert decimal hours to hh:mm:ss format
function formatHoursToTime($decimalHours) {
    if ($decimalHours <= 0) return '00:00:00';
    $totalSeconds = round($decimalHours * 3600);
    $hours = floor($totalSeconds / 3600);
    $minutes = floor(($totalSeconds % 3600) / 60);
    $seconds = $totalSeconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
}

// Calculate total COC (Credits of Compensation) from overtime hours and from hours worked on days with no official time (permanent/temporary only)
// COC = total overtime hours (1 hour = 1 point) + hours worked on no-official-time days
$totalCOC = 0;
$employeeId = $facultyProfile['employee_id'] ?? null;
if ($employeeId) {
    // Helper function to parse TIME field to minutes from midnight
    $parseTimeToMinutes = function($timeStr) {
        if (empty($timeStr) || $timeStr === null) return null;
        $timeStr = trim((string)$timeStr);
        $parts = explode(':', $timeStr);
        if (count($parts) >= 2) {
            $hours = intval($parts[0]);
            $minutes = intval($parts[1]);
            return ($hours * 60) + $minutes;
        }
        return null;
    };
    $isTimeLogged = function($time) {
        if (empty($time)) return false;
        $time = trim((string)$time);
        return $time !== '00:00' && $time !== '00:00:00' && $time !== '0:00' && $time !== '0:00:00';
    };

    $employmentStatus = trim($facultyProfile['employment_status'] ?? '');
    $isPermanentOrTemporary = (strcasecmp($employmentStatus, 'Permanent') === 0 || strcasecmp($employmentStatus, 'Temporary') === 0);
    $official_times_list = [];
    if ($isPermanentOrTemporary) {
        $stmtOT = $db->prepare("SELECT start_date, end_date, weekday FROM employee_official_times WHERE employee_id = ? ORDER BY start_date DESC");
        $stmtOT->execute([$employeeId]);
        $official_times_list = $stmtOT->fetchAll(PDO::FETCH_ASSOC);
    }
    $getWeekdayName = function($dateStr) {
        $date = new DateTime($dateStr);
        $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        return $weekdays[(int)$date->format('w')];
    };
    $hasOfficialTimeForDate = function($list, $logDate) use ($getWeekdayName) {
        $logWeekday = $getWeekdayName($logDate);
        foreach ($list as $ot) {
            $weekday = $ot['weekday'] ?? null;
            if (($weekday === null || $weekday === $logWeekday) && $ot['start_date'] <= $logDate && ($ot['end_date'] === null || $ot['end_date'] >= $logDate)) {
                return true;
            }
        }
        return false;
    };

    // COC from hours worked on days when employee has no official time (permanent and temporary only)
    if ($isPermanentOrTemporary) {
        $cocNoOTStmt = $db->prepare("
            SELECT log_date, time_in, lunch_out, lunch_in, time_out
            FROM attendance_logs
            WHERE employee_id = ?
            AND time_in IS NOT NULL AND time_in != '' AND time_in != '00:00' AND time_in != '00:00:00'
            AND time_out IS NOT NULL AND time_out != '' AND time_out != '00:00' AND time_out != '00:00:00'
            ORDER BY log_date ASC
        ");
        $cocNoOTStmt->execute([$employeeId]);
        $logsNoOfficial = $cocNoOTStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($logsNoOfficial as $log) {
            if (!empty($official_times_list) && $hasOfficialTimeForDate($official_times_list, $log['log_date'])) {
                continue;
            }
            $in_min = $parseTimeToMinutes($log['time_in']);
            $out_min = $parseTimeToMinutes($log['time_out']);
            $lunch_out_min = $isTimeLogged($log['lunch_out']) ? $parseTimeToMinutes($log['lunch_out']) : null;
            $lunch_in_min = $isTimeLogged($log['lunch_in']) ? $parseTimeToMinutes($log['lunch_in']) : null;
            if ($in_min === null || $out_min === null || $out_min <= $in_min) {
                continue;
            }
            $worked_minutes = 0;
            if ($lunch_out_min !== null && $lunch_in_min !== null) {
                $morning = max(0, $lunch_out_min - $in_min);
                $afternoon = max(0, $out_min - $lunch_in_min);
                $worked_minutes = $morning + $afternoon;
            } else {
                $worked_minutes = $out_min - $in_min;
            }
            $totalCOC += $worked_minutes / 60;
        }
    }

    // Get all overtime hours from attendance logs where ot_in and ot_out exist
    $cocStmt = $db->prepare("
        SELECT ot_in, ot_out
        FROM attendance_logs
        WHERE employee_id = ? 
        AND ot_in IS NOT NULL 
        AND ot_out IS NOT NULL
        AND ot_in != '' 
        AND ot_out != ''
        ORDER BY log_date ASC
    ");
    $cocStmt->execute([$employeeId]);
    $cocLogs = $cocStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cocLogs as $log) {
        $ot_in_minutes = $parseTimeToMinutes($log['ot_in']);
        $ot_out_minutes = $parseTimeToMinutes($log['ot_out']);
        
        if ($ot_in_minutes !== null && $ot_out_minutes !== null && $ot_out_minutes > $ot_in_minutes) {
            // Calculate overtime from OT in to OT out (in hours)
            $overtime_minutes = $ot_out_minutes - $ot_in_minutes;
            $overtime_hours = $overtime_minutes / 60;
            // 1 hour overtime = 1 COC point
            $totalCOC += $overtime_hours;
        }
    }
}

// Format COC for display
$totalCOCFormatted = formatHoursToTime($totalCOC ?? 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#003366">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>My Profile - WPU Faculty and Staff System</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <?php
    $basePath = getBasePath();
    ?>
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile-button-fix.css', true); ?>" rel="stylesheet">
</head>
<style>

/* Remove padding on mobile for elements with data-no-mobile-padding */
@media (max-width: 767px) {
    [data-no-mobile-padding="true"] {
        padding: 0 !important;
    }
}

/* Remove excessive padding from Cancel button in modals */
.btn-outline-secondary.me-2[data-bs-dismiss="modal"][data-mobile-fixed="true"],
button.btn-outline-secondary.me-2[data-bs-dismiss="modal"][data-mobile-fixed="true"],
.btn-outline-secondary[data-bs-dismiss="modal"] {
    padding: 0.375rem 0.75rem !important;
}

/* Fix QR Code Modal z-index to appear above backdrop */
#qrCodeModal {
    z-index: 1060 !important;
}

#qrCodeModal.show {
    z-index: 1060 !important;
}

#qrCodeModal .modal-dialog {
    z-index: 1060 !important;
}

body.modal-open #qrCodeModal {
    z-index: 1060 !important;
}

body.modal-open #qrCodeModal.show {
    z-index: 1060 !important;
}

/* Ensure backdrop stays below QR code modal */
.modal-backdrop {
    z-index: 1040 !important;
}

body.modal-open .modal-backdrop {
    z-index: 1040 !important;
}
</style>
<body class="layout-faculty">
    <?php require_once '../includes/navigation.php';
    include_navigation(); ?>
    
    <div class="container-fluid">
        <div class="row">
            
            <main class="main-content">
                <div class="page-header">
                    <div class="page-title">
                        <i class="fas fa-user"></i>
                        <span>My Profile</span>
                    </div>
                </div>

                <?php displayMessage(); ?>

                <div class="row">
                    <div class="col-lg-12 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-user me-2"></i>Personal & Faculty and Staff Profile</h5>
                            </div>
                            <div class="card-body">
                                <!-- Profile picture display & upload -->
                                <div class="mb-4">
                                    <div class="d-flex align-items-start justify-content-between">
                                        <!-- Profile Picture -->
                                        <div class="text-center">
                                            <div class="position-relative d-inline-block" style="cursor: pointer;" id="profilePictureContainer">
                                                <?php if (!empty($facultyProfile['profile_picture'] ?? '')): ?>
                                                    <img src="../uploads/profiles/<?php echo htmlspecialchars($facultyProfile['profile_picture'] ?? ''); ?>?t=<?php echo time(); ?>" 
                                                         class="rounded-circle" width="96" height="96" alt="Profile Picture" style="object-fit: cover;">
                                                <?php else: ?>
                                                    <div class="bg-primary rounded-circle d-inline-flex align-items-center justify-content-center" 
                                                         style="width: 96px; height: 96px;">
                                                        <i class="fas fa-user fa-2x text-white"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <!-- Edit icon overlay -->
                                                <div class="position-absolute rounded-circle d-flex align-items-center justify-content-center" 
                                                     style="bottom: 0; right: 0; width: 28px; height: 28px; background-color: #1877f2; border: 2px solid white; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.2);"
                                                     id="profilePictureEditIcon">
                                                    <i class="fas fa-camera text-white" style="font-size: 12px;"></i>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- QR Code -->
                                        <?php
                                        // Get QR code path
                                        $qrCodePath = getQRCodePath($userId);
                                        $employeeIdForQR = $facultyProfile['employee_id'] ?? null;
                                        
                                        // If no QR code exists in database, generate one
                                        if (!$qrCodePath && $employeeIdForQR) {
                                            $qrCodePath = generateQRCode($userId, $employeeIdForQR);
                                            
                                            if ($qrCodePath) {
                                                // Check if faculty profile exists
                                                $stmt = $db->prepare("SELECT id FROM faculty_profiles WHERE user_id = ?");
                                                $stmt->execute([$userId]);
                                                $profileExists = $stmt->fetch();
                                                
                                                if ($profileExists) {
                                                    // Update existing profile
                                                    $stmt = $db->prepare("UPDATE faculty_profiles SET qr_code = ? WHERE user_id = ?");
                                                    $stmt->execute([$qrCodePath, $userId]);
                                                } else {
                                                    // Create new profile with QR code
                                                    $stmt = $db->prepare("INSERT INTO faculty_profiles (user_id, qr_code) VALUES (?, ?)");
                                                    $stmt->execute([$userId, $qrCodePath]);
                                                }
                                                
                                                // Refresh profile data
                                                $stmt = $db->prepare("SELECT * FROM faculty_profiles WHERE user_id = ?");
                                                $stmt->execute([$userId]);
                                                $facultyProfile = $stmt->fetch();
                                                if (!$facultyProfile || !is_array($facultyProfile)) {
                                                    $facultyProfile = [];
                                                }
                                                
                                                // Recalculate COC after profile refresh (same logic as initial: OT + no-official-time days for permanent/temporary)
                                                $totalCOC = 0;
                                                $employeeId = $facultyProfile['employee_id'] ?? null;
                                                if ($employeeId) {
                                                    $parseTimeToMinutes = function($timeStr) {
                                                        if (empty($timeStr) || $timeStr === null) return null;
                                                        $timeStr = trim((string)$timeStr);
                                                        $parts = explode(':', $timeStr);
                                                        if (count($parts) >= 2) {
                                                            $hours = intval($parts[0]);
                                                            $minutes = intval($parts[1]);
                                                            return ($hours * 60) + $minutes;
                                                        }
                                                        return null;
                                                    };
                                                    $isTimeLogged = function($time) {
                                                        if (empty($time)) return false;
                                                        $time = trim((string)$time);
                                                        return $time !== '00:00' && $time !== '00:00:00' && $time !== '0:00' && $time !== '0:00:00';
                                                    };
                                                    $employmentStatusRefresh = trim($facultyProfile['employment_status'] ?? '');
                                                    $isPermanentOrTemporaryRefresh = (strcasecmp($employmentStatusRefresh, 'Permanent') === 0 || strcasecmp($employmentStatusRefresh, 'Temporary') === 0);
                                                    $official_times_list_refresh = [];
                                                    if ($isPermanentOrTemporaryRefresh) {
                                                        $stmtOT = $db->prepare("SELECT start_date, end_date, weekday FROM employee_official_times WHERE employee_id = ? ORDER BY start_date DESC");
                                                        $stmtOT->execute([$employeeId]);
                                                        $official_times_list_refresh = $stmtOT->fetchAll(PDO::FETCH_ASSOC);
                                                    }
                                                    $getWeekdayNameR = function($dateStr) {
                                                        $date = new DateTime($dateStr);
                                                        $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                                                        return $weekdays[(int)$date->format('w')];
                                                    };
                                                    $hasOfficialTimeForDateR = function($list, $logDate) use ($getWeekdayNameR) {
                                                        $logWeekday = $getWeekdayNameR($logDate);
                                                        foreach ($list as $ot) {
                                                            $weekday = $ot['weekday'] ?? null;
                                                            if (($weekday === null || $weekday === $logWeekday) && $ot['start_date'] <= $logDate && ($ot['end_date'] === null || $ot['end_date'] >= $logDate)) {
                                                                return true;
                                                            }
                                                        }
                                                        return false;
                                                    };
                                                    if ($isPermanentOrTemporaryRefresh) {
                                                        $cocNoOTStmt = $db->prepare("
                                                            SELECT log_date, time_in, lunch_out, lunch_in, time_out
                                                            FROM attendance_logs
                                                            WHERE employee_id = ?
                                                            AND time_in IS NOT NULL AND time_in != '' AND time_in != '00:00' AND time_in != '00:00:00'
                                                            AND time_out IS NOT NULL AND time_out != '' AND time_out != '00:00' AND time_out != '00:00:00'
                                                            ORDER BY log_date ASC
                                                        ");
                                                        $cocNoOTStmt->execute([$employeeId]);
                                                        $logsNoOfficialR = $cocNoOTStmt->fetchAll(PDO::FETCH_ASSOC);
                                                        foreach ($logsNoOfficialR as $log) {
                                                            if (!empty($official_times_list_refresh) && $hasOfficialTimeForDateR($official_times_list_refresh, $log['log_date'])) {
                                                                continue;
                                                            }
                                                            $in_min = $parseTimeToMinutes($log['time_in']);
                                                            $out_min = $parseTimeToMinutes($log['time_out']);
                                                            $lunch_out_min = $isTimeLogged($log['lunch_out']) ? $parseTimeToMinutes($log['lunch_out']) : null;
                                                            $lunch_in_min = $isTimeLogged($log['lunch_in']) ? $parseTimeToMinutes($log['lunch_in']) : null;
                                                            if ($in_min === null || $out_min === null || $out_min <= $in_min) {
                                                                continue;
                                                            }
                                                            $worked_minutes = 0;
                                                            if ($lunch_out_min !== null && $lunch_in_min !== null) {
                                                                $morning = max(0, $lunch_out_min - $in_min);
                                                                $afternoon = max(0, $out_min - $lunch_in_min);
                                                                $worked_minutes = $morning + $afternoon;
                                                            } else {
                                                                $worked_minutes = $out_min - $in_min;
                                                            }
                                                            $totalCOC += $worked_minutes / 60;
                                                        }
                                                    }
                                                    $cocStmt = $db->prepare("
                                                        SELECT ot_in, ot_out
                                                        FROM attendance_logs
                                                        WHERE employee_id = ? 
                                                        AND ot_in IS NOT NULL 
                                                        AND ot_out IS NOT NULL
                                                        AND ot_in != '' 
                                                        AND ot_out != ''
                                                        ORDER BY log_date ASC
                                                    ");
                                                    $cocStmt->execute([$employeeId]);
                                                    $cocLogs = $cocStmt->fetchAll(PDO::FETCH_ASSOC);
                                                    foreach ($cocLogs as $log) {
                                                        $ot_in_minutes = $parseTimeToMinutes($log['ot_in']);
                                                        $ot_out_minutes = $parseTimeToMinutes($log['ot_out']);
                                                        if ($ot_in_minutes !== null && $ot_out_minutes !== null && $ot_out_minutes > $ot_in_minutes) {
                                                            $overtime_minutes = $ot_out_minutes - $ot_in_minutes;
                                                            $totalCOC += $overtime_minutes / 60;
                                                        }
                                                    }
                                                }
                                                
                                                // Format COC for display after recalculation
                                                $totalCOCFormatted = formatHoursToTime($totalCOC ?? 0);
                                                
                                                $qrCodePath = $facultyProfile['qr_code'] ?? null;
                                            }
                                        }
                                        
                                        // If QR code path exists in database but file is missing, regenerate it
                                        if ($qrCodePath) {
                                            $qrCodeFileCheck = UPLOAD_PATH . $qrCodePath;
                                            if (!file_exists($qrCodeFileCheck)) {
                                                // File is missing, regenerate QR code
                                                if ($employeeIdForQR) {
                                                    $regeneratedPath = generateQRCode($userId, $employeeIdForQR);
                                                    if ($regeneratedPath) {
                                                        // Update database with new path
                                                        $stmt = $db->prepare("UPDATE faculty_profiles SET qr_code = ? WHERE user_id = ?");
                                                        $stmt->execute([$regeneratedPath, $userId]);
                                                        $qrCodePath = $regeneratedPath;
                                                        // Refresh the file check path
                                                        $qrCodeFileCheck = UPLOAD_PATH . $qrCodePath;
                                                    } else {
                                                        // Regeneration failed, clear the path
                                                        $qrCodePath = null;
                                                    }
                                                } else {
                                                    // No employee ID, clear the path
                                                    $qrCodePath = null;
                                                }
                                            }
                                        }
                                        
                                        // Display QR code and COC side by side
                                        ?>
                                        <div class="d-flex align-items-start gap-3">
                                            <!-- QR Code -->
                                            <?php if ($qrCodePath && $employeeIdForQR):
                                                // For mobile compatibility, embed QR code as base64 data URI
                                                // This eliminates session cookie issues with separate HTTP requests
                                                $qrCodeFullPath = UPLOAD_PATH . $qrCodePath;
                                                $qrCodeDataUri = '';
                                                
                                                if (file_exists($qrCodeFullPath)) {
                                                    $qrCodeContent = file_get_contents($qrCodeFullPath);
                                                    if ($qrCodeContent !== false) {
                                                        $extension = strtolower(pathinfo($qrCodePath, PATHINFO_EXTENSION));
                                                        $mimeTypes = [
                                                            'png' => 'image/png',
                                                            'svg' => 'image/svg+xml',
                                                            'jpg' => 'image/jpeg',
                                                            'jpeg' => 'image/jpeg',
                                                            'gif' => 'image/gif'
                                                        ];
                                                        $mimeType = $mimeTypes[$extension] ?? 'image/png';
                                                        $qrCodeDataUri = 'data:' . $mimeType . ';base64,' . base64_encode($qrCodeContent);
                                                    }
                                                }
                                                
                                                // Fallback to proxy URL if data URI generation fails
                                                $qrCodeUrl = $qrCodeDataUri ?: ('qr_code_image.php?user_id=' . $userId . '&v=' . time());
                                            ?>
                                            <div class="text-center">
                                                <div class="mb-2">
                                                    <div class="d-inline-block p-2 bg-light rounded border qr-code-clickable" 
                                                         style="width: 96px; height: 96px; display: flex; align-items: center; justify-content: center;"
                                                         data-bs-toggle="modal" 
                                                         data-bs-target="#qrCodeModal"
                                                         role="button"
                                                         tabindex="0"
                                                         aria-label="View QR Code">
                                                        <img src="<?php echo htmlspecialchars($qrCodeUrl); ?>" 
                                                             alt="QR Code" 
                                                             class="img-fluid" 
                                                             style="max-width: 100%; max-height: 100%; pointer-events: none;"
                                                             loading="lazy"
                                                             onerror="console.error('QR code failed to load:', this.src); this.style.display='none'; this.parentElement.innerHTML='<div style=\'width:96px;height:96px;display:flex;align-items:center;justify-content:center;color:#999;\' title=\'QR Code not available\'><i class=\'fas fa-qrcode fa-2x\'></i></div>';">
                                                    </div>
                                                </div>
                                                <small class="text-muted d-block">QR Code</small>
                                            </div>
                                            <?php elseif (!$employeeIdForQR): ?>
                                            <div class="text-center">
                                                <div class="mb-2">
                                                    <div class="d-inline-block p-2 bg-light rounded border" 
                                                         style="width: 96px; height: 96px; display: flex; align-items: center; justify-content: center; color: #999;"
                                                         title="Safe Employee ID required for QR code">
                                                        <i class="fas fa-qrcode fa-2x"></i>
                                                    </div>
                                                </div>
                                                <small class="text-muted d-block">Safe Employee ID required</small>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <!-- COC Display -->
                                            <div class="text-center">
                                                <div class="mb-2">
                                                    <div class="d-inline-block p-2 bg-light rounded border" 
                                                         style="width: 96px; height: 96px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                                                        <i class="fas fa-star fa-2x text-primary mb-1"></i>
                                                        <span class="fw-bold text-dark" style="font-size: 1.1rem;">
                                                            <?php echo htmlspecialchars($totalCOCFormatted); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <small class="text-muted d-block">COC Hours</small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Hidden form for profile picture upload -->
                                    <form id="profilePictureForm" method="POST" enctype="multipart/form-data" style="display: none;">
                                        <?php addFormToken(); ?>
                                        <input type="hidden" name="action" value="upload_profile_picture">
                                        <input type="file" name="profile_picture" id="profile_picture_input" accept="image/png,image/jpeg,image/jpg,image/gif">
                                    </form>
                                    <div id="uploadStatus" class="mt-2" style="display: none;">
                                        <small class="text-muted"><i class="fas fa-spinner fa-spin me-1"></i>Uploading...</small>
                                    </div>
                                </div>

                                <form method="POST" id="profileUpdateForm" data-original-department="<?php echo htmlspecialchars($facultyProfile['department'] ?? ''); ?>" data-original-employment-status="<?php echo htmlspecialchars(trim($facultyProfile['employment_status'] ?? '')); ?>" data-original-campus="<?php echo htmlspecialchars($facultyProfile['campus'] ?? ''); ?>">
                                    <input type="hidden" name="action" value="update_all_profile_info">
                                    
                                    <h6 class="border-bottom pb-2 mb-3 text-primary">Personal Information</h6>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="first_name" class="form-label">First Name</label>
                                            <input type="text" class="form-control" id="first_name" name="first_name" 
                                                   value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="last_name" class="form-label">Last Name</label>
                                            <input type="text" class="form-control" id="last_name" name="last_name" 
                                                   value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" required>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="middle_name" class="form-label">Middle Name</label>
                                        <input type="text" class="form-control" id="middle_name" name="middle_name" 
                                               value="<?php echo htmlspecialchars($user['middle_name'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email Address</label>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required disabled>
                                        <div class="form-text">Must be a WPU email address (@wpu.edu.ph)</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Account Status</label>
                                        <div>
                                            <span class="badge bg-<?php echo ($user['is_active'] ?? 0) ? 'success' : 'danger'; ?>">
                                                <?php echo ($user['is_active'] ?? 0) ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                    <h6 class="border-bottom pb-2 mb-3 mt-4 text-primary">Faculty and Staff Profile Information</h6>
                    
                    <div class="mb-3">
                        <label for="employee_id" class="form-label">Safe Employee ID</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="employee_id" name="employee_id" 
                                   value="<?php echo htmlspecialchars($facultyProfile['employee_id'] ?? ''); ?>" readonly
                                   placeholder="Safe Employee ID will be generated automatically">
                            
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="gender" class="form-label">Gender</label>
                            <select class="form-control" id="gender" name="gender" disabled>
                                <option value="">Not specified</option>
                                <option value="Male" <?php echo (($facultyProfile['gender'] ?? '') === 'Male') ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo (($facultyProfile['gender'] ?? '') === 'Female') ? 'selected' : ''; ?>>Female</option>
                                <option value="Other" <?php echo (($facultyProfile['gender'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
                                <option value="Prefer not to say" <?php echo (($facultyProfile['gender'] ?? '') === 'Prefer not to say') ? 'selected' : ''; ?>>Prefer not to say</option>
                            </select>
                            <small class="form-text text-muted">Contact admin to update this field</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="campus" class="form-label">Campus</label>
                            <select class="form-control" id="campus" name="campus">
                                <option value="">Not assigned</option>
                                <?php foreach ($masterCampuses as $c): ?>
                                    <option value="<?php echo htmlspecialchars($c['name']); ?>" <?php echo (($facultyProfile['campus'] ?? '') === $c['name']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">Please ensure the data you enter is correct.</small>
                        </div>
                    </div>
                    
                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="department" class="form-label">Department</label>
                                            <select class="form-control" id="department" name="department">
                                                <option value="">Select Department</option>
                                                <?php foreach ($masterDepartments as $d): ?>
                                                    <option value="<?php echo htmlspecialchars($d['name']); ?>" <?php echo (($facultyProfile['department'] ?? '') === $d['name']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['name']); ?></option>
                                                <?php endforeach; ?>
                                                <option value="Other" <?php echo (($facultyProfile['department'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
                                            </select>
                                            <small class="form-text text-muted">Please ensure the data you enter is correct.</small>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="position" class="form-label">Position</label>
                                            <select class="form-control" id="position" name="position" disabled>
                                                <option value="">Select Position</option>
                                                <?php foreach ($allPositions as $p): ?>
                                                    <option value="<?php echo htmlspecialchars($p['position_title']); ?>" 
                                                            data-salary-grade="<?php echo $p['salary_grade']; ?>"
                                                            data-annual-salary="<?php echo $p['annual_salary']; ?>"
                                                            <?php echo (($facultyProfile['position'] ?? '') === $p['position_title']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($p['position_title']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="form-text text-muted">Contact admin to update this field</small>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="salary_grade" class="form-label">Salary Grade</label>
                                            <input type="text" class="form-control" id="salary_grade" name="salary_grade" 
                                                   value="<?php 
                                                   // Get salary grade for current position
                                                   $currentSalaryGrade = '';
                                                   if (!empty($facultyProfile['position'] ?? '')) {
                                                       foreach ($allPositions as $p) {
                                                           if ($p['position_title'] === $facultyProfile['position']) {
                                                               $currentSalaryGrade = $p['salary_grade'];
                                                               break;
                                                           }
                                                       }
                                                   }
                                                   echo htmlspecialchars($currentSalaryGrade);
                                                   ?>" 
                                                   readonly>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="annual_salary" class="form-label">Annual Salary</label>
                                            <input type="text" class="form-control" id="annual_salary" name="annual_salary" 
                                                   value="<?php 
                                                   // Get annual salary for current position
                                                   $currentAnnualSalary = '';
                                                   if (!empty($facultyProfile['position'] ?? '')) {
                                                       foreach ($allPositions as $p) {
                                                           if ($p['position_title'] === $facultyProfile['position']) {
                                                               $currentAnnualSalary = '₱' . number_format($p['annual_salary'], 2);
                                                               break;
                                                           }
                                                       }
                                                   }
                                                   echo htmlspecialchars($currentAnnualSalary);
                                                   ?>" 
                                                   readonly>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="designation" class="form-label">Designation</label>
                                            <select class="form-control" id="designation" name="designation" disabled>
                                                <option value="">Not assigned</option>
                                                <?php foreach ($masterDesignations as $d): ?>
                                                    <option value="<?php echo htmlspecialchars($d['name']); ?>" <?php echo (($facultyProfile['designation'] ?? '') === $d['name']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="form-text text-muted">Contact admin to update this field</small>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="employment_status" class="form-label">Employment Status</label>
                                            <select class="form-control" id="employment_status" name="employment_status">
                                                <option value="">Select Status</option>
                                                <?php foreach ($masterEmploymentStatuses as $es): ?>
                                                    <option value="<?php echo htmlspecialchars($es['name']); ?>" <?php echo (strcasecmp(trim($facultyProfile['employment_status'] ?? ''), trim($es['name'])) === 0) ? 'selected' : ''; ?>><?php echo htmlspecialchars($es['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="form-text text-muted">Please ensure the data you enter is correct.</small>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="hire_date" class="form-label">Hire Date</label>
                                            <div class="input-group">
                                                <input type="date" class="form-control" id="hire_date" name="hire_date" 
                                                       value="<?php echo $facultyProfile['hire_date'] ?? ''; ?>">
                                                <?php if (empty($facultyProfile['hire_date'])): ?>
                                                <button type="button" class="btn btn-outline-secondary" onclick="fillTodayDate()" title="Fill with today's date">
                                                    <i class="fas fa-calendar-day"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                            <small class="form-text text-muted">Click the calendar icon to fill with today's date</small>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <!-- Empty column for layout balance -->
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="phone" class="form-label">Phone Number</label>
                                            <input type="tel" class="form-control" id="phone" name="phone" 
                                                   value="<?php echo htmlspecialchars($facultyProfile['phone'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="address" class="form-label">Address</label>
                                            <textarea class="form-control" id="address" name="address" rows="2"><?php echo htmlspecialchars($facultyProfile['address'] ?? ''); ?></textarea>
                                        </div>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i>Update Profile
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                <!-- Account Information -->
                
            </main>
        </div>
    </div>

    <!-- QR Code Modal -->
    <?php
    // Get QR code path for modal (reuse logic from above)
    $qrCodePathModal = getQRCodePath($userId);
    $employeeIdForModal = $facultyProfile['employee_id'] ?? null;
    
    // If no QR code exists, try to generate one
    if (!$qrCodePathModal && $employeeIdForModal) {
        $qrCodePathModal = generateQRCode($userId, $employeeIdForModal);
        if ($qrCodePathModal) {
            $stmt = $db->prepare("UPDATE faculty_profiles SET qr_code = ? WHERE user_id = ?");
            $stmt->execute([$qrCodePathModal, $userId]);
        }
    }
    
    // If QR code path exists but file is missing, regenerate it
    if ($qrCodePathModal) {
        $qrCodeFileCheckModal = UPLOAD_PATH . $qrCodePathModal;
        if (!file_exists($qrCodeFileCheckModal) && $employeeIdForModal) {
            $regeneratedPathModal = generateQRCode($userId, $employeeIdForModal);
            if ($regeneratedPathModal) {
                $stmt = $db->prepare("UPDATE faculty_profiles SET qr_code = ? WHERE user_id = ?");
                $stmt->execute([$regeneratedPathModal, $userId]);
                $qrCodePathModal = $regeneratedPathModal;
            } else {
                $qrCodePathModal = null;
            }
        }
    }
    
    if ($qrCodePathModal && $employeeIdForModal):
        // For mobile compatibility, embed QR code as base64 data URI
        // This eliminates session cookie issues with separate HTTP requests
        $qrCodeFullPathModal = UPLOAD_PATH . $qrCodePathModal;
        $qrCodeDataUriModal = '';
        
        if (file_exists($qrCodeFullPathModal)) {
            $qrCodeContentModal = file_get_contents($qrCodeFullPathModal);
            if ($qrCodeContentModal !== false) {
                $extensionModal = strtolower(pathinfo($qrCodePathModal, PATHINFO_EXTENSION));
                $mimeTypesModal = [
                    'png' => 'image/png',
                    'svg' => 'image/svg+xml',
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'gif' => 'image/gif'
                ];
                $mimeTypeModal = $mimeTypesModal[$extensionModal] ?? 'image/png';
                $qrCodeDataUriModal = 'data:' . $mimeTypeModal . ';base64,' . base64_encode($qrCodeContentModal);
            }
        }
        
        // Fallback to proxy URL if data URI generation fails
        $qrCodeUrlModal = $qrCodeDataUriModal ?: ('qr_code_image.php?user_id=' . $userId . '&v=' . time());
    ?>
    <div class="modal fade" id="qrCodeModal" tabindex="-1" aria-labelledby="qrCodeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="qrCodeModalLabel">
                        <i class="fas fa-qrcode me-2"></i>Attendance QR Code
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="padding: 0 !important; touch-action: manipulation; -webkit-tap-highlight-color: rgba(0, 51, 102, 0.2); cursor: pointer; pointer-events: auto;" data-mobile-fixed="true"></button>
                </div>
                <div class="modal-body text-center">
                    <p class="text-muted mb-3">Scan this QR code for attendance tracking</p>
                    <div class="d-flex justify-content-center align-items-center mb-3">
                        <div class="p-4 bg-light rounded border" style="max-width: 100%; width: fit-content;">
                            <img src="<?php echo htmlspecialchars($qrCodeUrlModal); ?>" 
                                 alt="QR Code" 
                                 class="img-fluid" 
                                 style="max-width: 100%; height: auto; display: block;"
                                 loading="lazy"
                                 onerror="console.error('QR code failed to load in modal:', this.src); this.style.display='none'; this.parentElement.innerHTML='<div style=\'padding:2rem;color:#999;\'><i class=\'fas fa-exclamation-triangle fa-3x mb-3\'></i><p>QR Code image could not be loaded</p><p class=\'small text-muted\'>Please try refreshing the page or contact support if the issue persists.</p></div>';">
                        </div>
                    </div>
                    <div class="mb-3">
                        <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars(($user['last_name'] ?? '') . ', ' . ($user['first_name'] ?? '')); ?></p>
                        <?php if (!empty($employeeIdForModal)): ?>
                            <p class="mb-0"><strong>Safe Employee ID:</strong> <?php echo htmlspecialchars($employeeIdForModal); ?></p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php
                        // Construct download URL properly
                        $downloadUrlModal = 'qr_code_image.php?user_id=' . $userId . '&download=1&v=' . time();
                        $safeEmployeeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $employeeIdForModal ?? 'QRCode');
                        $downloadFilename = 'QR_Code_' . $safeEmployeeId . '.png';
                        ?>
                        <a href="<?php echo htmlspecialchars($downloadUrlModal); ?>" 
                           class="btn btn-primary"
                           data-no-mobile-padding="true"
                           download="<?php echo htmlspecialchars($downloadFilename); ?>"
                           style="padding: 0.375rem 0.75rem !important;">
                            <i class="fas fa-download me-2"></i>Download QR Code
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    endif;
    ?>

    <!-- Load Bootstrap first - CRITICAL for mobile interactions -->
    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <!-- Load main.js for core functionality -->
    <script src="<?php echo asset_url('js/main.js', true); ?>"></script>
    <!-- Load unified mobile interactions - replaces all mobile scripts -->
    <script src="<?php echo asset_url('js/mobile-interactions-unified.js', true); ?>"></script>
    <script>
        // Position selection handler - display salary info
        document.addEventListener('DOMContentLoaded', function() {
            // Profile form: show warning when Department, Employment Status, or Campus are edited
            const profileForm = document.getElementById('profileUpdateForm');
            if (profileForm) {
                profileForm.addEventListener('submit', function(e) {
                    const form = e.target;
                    const origDept = (form.dataset.originalDepartment || '').trim();
                    const origEmpStatus = (form.dataset.originalEmploymentStatus || '').trim().toLowerCase();
                    const origCampus = (form.dataset.originalCampus || '').trim();
                    const deptEl = document.getElementById('department');
                    const empStatusEl = document.getElementById('employment_status');
                    const campusEl = document.getElementById('campus');
                    const currDept = deptEl ? (deptEl.value || '').trim() : '';
                    const currEmpStatus = empStatusEl ? (empStatusEl.value || '').trim().toLowerCase() : '';
                    const currCampus = campusEl ? (campusEl.value || '').trim() : '';
                    const deptChanged = currDept !== origDept;
                    const empStatusChanged = currEmpStatus !== origEmpStatus;
                    const campusChanged = currCampus !== origCampus;
                    if (deptChanged || empStatusChanged || campusChanged) {
                        const fields = [];
                        if (deptChanged) fields.push('Department');
                        if (empStatusChanged) fields.push('Employment Status');
                        if (campusChanged) fields.push('Campus');
                        const msg = 'You have edited ' + fields.join(', ') + '. Please confirm that the data you entered is TRUE and correct before saving.';
                        if (!confirm(msg)) {
                            e.preventDefault();
                            return false;
                        }
                    }
                });
            }
            
            // CRITICAL FIX: Ensure all buttons work on mobile
            // Fix buttons inside clickable containers
            document.querySelectorAll('button, .btn, a.btn').forEach(function(button) {
                // Ensure button is clickable
                button.style.pointerEvents = 'auto';
                button.style.touchAction = 'manipulation';
                button.style.cursor = 'pointer';
                
                // If button has onclick, ensure it works on mobile
                if (button.onclick || button.getAttribute('onclick')) {
                    // Add click handler that works on both desktop and mobile
                    button.addEventListener('click', function(e) {
                        // Don't prevent default - let button's action work
                        // Just ensure event doesn't get blocked
                    }, { passive: true });
                }
            });
            
            const positionSelect = document.getElementById('position');
            const salaryGradeInput = document.getElementById('salary_grade');
            const annualSalaryInput = document.getElementById('annual_salary');
            
            if (positionSelect && salaryGradeInput && annualSalaryInput) {
                // Update salary fields when position changes
                positionSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    if (selectedOption.value) {
                        const salaryGrade = selectedOption.dataset.salaryGrade;
                        const annualSalary = parseFloat(selectedOption.dataset.annualSalary);
                        
                        salaryGradeInput.value = salaryGrade || '';
                        annualSalaryInput.value = annualSalary ? '₱' + annualSalary.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '';
                    } else {
                        salaryGradeInput.value = '';
                        annualSalaryInput.value = '';
                    }
                });
            }
            
            const profilePictureForm = document.getElementById('profilePictureForm');
            const profilePictureInput = document.getElementById('profile_picture_input');
            const uploadStatus = document.getElementById('uploadStatus');
            const profilePictureEditIcon = document.getElementById('profilePictureEditIcon');
            const profilePictureContainer = document.getElementById('profilePictureContainer');
            
            // Handle edit icon click to trigger file input
            if (profilePictureEditIcon && profilePictureInput) {
                profilePictureEditIcon.addEventListener('click', function(e) {
                    e.stopPropagation();
                    profilePictureInput.click();
                });
            }
            
            // Handle container click to trigger file input (clicking anywhere on the profile picture)
            if (profilePictureContainer && profilePictureInput) {
                profilePictureContainer.addEventListener('click', function(e) {
                    // Don't trigger if clicking directly on the edit icon (it has its own handler)
                    if (!e.target.closest('#profilePictureEditIcon')) {
                        profilePictureInput.click();
                    }
                });
            }
            
            // Helper function to validate image file (checks both MIME type and extension)
            function isValidImageFile(file) {
                if (!file || !file.name) {
                    console.log('Validation failed: No file or file name');
                    return false;
                }
                
                // Check file extension first (most reliable)
                const fileName = file.name.toLowerCase().trim();
                const validExtensions = ['.jpg', '.jpeg', '.png', '.gif'];
                
                // Get file extension
                const lastDotIndex = fileName.lastIndexOf('.');
                const fileExtension = lastDotIndex > 0 ? fileName.substring(lastDotIndex) : '';
                const hasValidExtension = fileExtension && validExtensions.includes(fileExtension);
                
                // Check MIME type (handle common variations)
                const fileType = (file.type || '').toLowerCase().trim();
                let hasValidMimeType = false;
                
                if (fileType) {
                    // Common MIME types for images
                    const allowedMimeTypes = [
                        'image/jpeg', 
                        'image/jpg', 
                        'image/png', 
                        'image/gif',
                        'image/pjpeg',
                        'image/x-png'
                    ];
                    
                    // Check exact match
                    if (allowedMimeTypes.includes(fileType)) {
                        hasValidMimeType = true;
                    }
                    // Check if it's an image type with valid subtype
                    else if (fileType.startsWith('image/')) {
                        const parts = fileType.split('/');
                        if (parts.length === 2) {
                            const subtype = parts[1];
                            const validSubtypes = ['jpeg', 'jpg', 'png', 'gif', 'pjpeg', 'x-png'];
                            if (validSubtypes.includes(subtype)) {
                                hasValidMimeType = true;
                            }
                        }
                    }
                }
                
                // Accept if extension is valid OR MIME type is valid
                // Extension check is more reliable, so if extension is valid, accept it
                // If MIME type is empty but extension is valid, still accept (browser inconsistency)
                const isValid = hasValidExtension || hasValidMimeType;
                
                // Always log validation details for debugging
                console.log('File validation:', {
                    fileName: file.name,
                    fileExtension: fileExtension,
                    fileType: fileType || '(empty)',
                    hasValidExtension: hasValidExtension,
                    hasValidMimeType: hasValidMimeType,
                    isValid: isValid
                });
                
                return isValid;
            }
            
            if (profilePictureForm && profilePictureInput) {
                let isSubmitting = false;
                
                // Auto-upload when file is selected
                profilePictureInput.addEventListener('change', function() {
                    const file = this.files[0];
                    
                    if (!file) {
                        return;
                    }
                    
                    // Prevent multiple submissions
                    if (isSubmitting) {
                        console.log('Upload already in progress');
                        return;
                    }
                    
                    // Validate file type (check both MIME type and extension)
                    if (!isValidImageFile(file)) {
                        alert('Please select a valid image file (JPG, PNG, or GIF).');
                        this.value = ''; // Clear the input
                        return;
                    }
                    
                    // Validate file size
                    const maxSize = <?php echo MAX_FILE_SIZE; ?>;
                    if (file.size > maxSize) {
                        alert('File size exceeds the maximum allowed size of <?php echo number_format(MAX_FILE_SIZE / 1024 / 1024, 1); ?> MB.');
                        this.value = ''; // Clear the input
                        return;
                    }
                    
                    // Mark as submitting
                    isSubmitting = true;
                    
                    // Show loading state
                    if (uploadStatus) {
                        uploadStatus.style.display = 'block';
                    }
                    
                    // Note: Don't disable the input - disabled file inputs are not submitted with the form!
                    // Instead, we use the isSubmitting flag to prevent multiple submissions
                    
                    // Debug log
                    console.log('Submitting form with file:', file.name, 'Type:', file.type, 'Size:', file.size);
                    
                    // Small delay to ensure file is ready, then submit
                    setTimeout(function() {
                        // Submit form automatically - use requestSubmit() if available for better compatibility
                        if (profilePictureForm.requestSubmit) {
                            profilePictureForm.requestSubmit();
                        } else {
                            profilePictureForm.submit();
                        }
                    }, 100);
                });
                
                // Handle form submission (minimal validation, mostly for safety)
                profilePictureForm.addEventListener('submit', function(e) {
                    const file = profilePictureInput.files[0];
                    
                    // If no file, prevent submission
                    if (!file) {
                        e.preventDefault();
                        isSubmitting = false;
                        if (uploadStatus) {
                            uploadStatus.style.display = 'none';
                        }
                        console.log('Form submission prevented: No file selected');
                        return false;
                    }
                    
                    // Final validation check (should already be validated in change handler)
                    if (!isValidImageFile(file)) {
                        e.preventDefault();
                        isSubmitting = false;
                        if (uploadStatus) {
                            uploadStatus.style.display = 'none';
                        }
                        alert('Please select a valid image file (JPG, PNG, or GIF).');
                        console.log('Form submission prevented: Invalid file type');
                        return false;
                    }
                    
                    // Validate file size one more time
                    const maxSize = <?php echo MAX_FILE_SIZE; ?>;
                    if (file.size > maxSize) {
                        e.preventDefault();
                        isSubmitting = false;
                        if (uploadStatus) {
                            uploadStatus.style.display = 'none';
                        }
                        alert('File size exceeds the maximum allowed size of <?php echo number_format(MAX_FILE_SIZE / 1024 / 1024, 1); ?> MB.');
                        console.log('Form submission prevented: File too large');
                        return false;
                    }
                    
                    // Allow form to submit
                    console.log('Form submission allowed, proceeding with upload');
                    // Reset flag after a delay (in case submission fails)
                    setTimeout(function() {
                        isSubmitting = false;
                    }, 5000);
                });
            }
        });

        function generateEmployeeId() {
            const btn = document.getElementById('generateEmployeeIdBtn');
            const input = document.getElementById('employee_id');
            const originalHtml = btn.innerHTML;
            
            // Check if employee ID already exists
            if (input.value && input.value.trim() !== '') {
                if (!confirm('A Safe Employee ID already exists. Do you want to generate a new one?')) {
                    return;
                }
            }
            
            // Show loading state
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Generating...';
            
            // Generate Safe Employee ID format: WPU-YYYY-XXXXX (e.g., WPU-2025-00001)
            const year = new Date().getFullYear();
            const timestamp = Date.now();
            const random = Math.floor(Math.random() * 99999).toString().padStart(5, '0');
            const employeeId = `WPU-${year}-${random}`;
            
            // Simulate a brief delay for better UX
            setTimeout(() => {
                input.value = employeeId;
                input.focus();
                
                // Reset button
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                
                // Show success feedback
                input.classList.add('is-valid');
                setTimeout(() => {
                    input.classList.remove('is-valid');
                }, 2000);
            }, 300);
        }
        
        function fillTodayDate() {
            const hireDateInput = document.getElementById('hire_date');
            if (hireDateInput && !hireDateInput.value) {
                const today = new Date();
                const year = today.getFullYear();
                const month = String(today.getMonth() + 1).padStart(2, '0');
                const day = String(today.getDate()).padStart(2, '0');
                hireDateInput.value = `${year}-${month}-${day}`;
                
                // Show success feedback
                hireDateInput.classList.add('is-valid');
                setTimeout(() => {
                    hireDateInput.classList.remove('is-valid');
                }, 2000);
            }
        }
        
        // Make QR code container keyboard accessible
        document.addEventListener('DOMContentLoaded', function() {
            const qrCodeClickable = document.querySelector('.qr-code-clickable');
            if (qrCodeClickable) {
                qrCodeClickable.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        const modal = new bootstrap.Modal(document.getElementById('qrCodeModal'));
                        modal.show();
                    }
                });
            }
        });
        
    </script>
</body>
</html>







