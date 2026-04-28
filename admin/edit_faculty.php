<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/upload.php';

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

$uploader = new FileUploader();

$facultyId = $_GET['id'] ?? null;

if (!$facultyId) {
    $_SESSION['error'] = 'Invalid faculty ID.';
    header('Location: faculty.php');
    exit();
}

// Verify faculty or staff exists
$stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND user_type IN ('faculty', 'staff')");
$stmt->execute([$facultyId]);
if (!$stmt->fetch()) {
    $_SESSION['error'] = 'Faculty or staff member not found.';
    header('Location: faculty.php');
    exit();
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'upload_profile_picture') {
        // Validate CSRF token if helper exists
        if (function_exists('validateFormToken') && !validateFormToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = "Invalid form submission. Please try again.";
            header('Location: edit_faculty.php?id=' . $facultyId);
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
                $stmt->execute([$facultyId]);
                $existing = $stmt->fetch();

                if ($existing) {
                    // Delete old file if exists
                    if (!empty($existing['profile_picture'])) {
                        $uploader->deleteFile('profiles/' . $existing['profile_picture']);
                    }
                    // Update existing profile
                    $stmt = $db->prepare("UPDATE faculty_profiles SET profile_picture = ?, updated_at = NOW() WHERE user_id = ?");
                    $updated = $stmt->execute([$filenameOnly, $facultyId]);
                } else {
                    // Insert new profile
                    $stmt = $db->prepare("INSERT INTO faculty_profiles (user_id, profile_picture, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
                    $updated = $stmt->execute([$facultyId, $filenameOnly]);
                }

                if ($updated) {
                    $_SESSION['success'] = 'Profile picture updated successfully!';
                    if (function_exists('logAction')) {
                        logAction('ADMIN_FACULTY_PICTURE_UPDATE', "Updated profile picture for faculty ID: $facultyId to: " . $filenameOnly);
                    }
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

        header('Location: edit_faculty.php?id=' . $facultyId . '&t=' . time());
        exit();
    }
    
    if ($action === 'update_all_profile_info') {
        $successMessages = [];
        $errorMessages = [];

        // Sanitize and get POST values
        $firstName = trim(sanitizeInput($_POST['first_name'] ?? ''));
        $lastName = trim(sanitizeInput($_POST['last_name'] ?? ''));
        $middleName = sanitizeInput($_POST['middle_name'] ?? '');
        $email = trim(strtolower(sanitizeInput($_POST['email'] ?? '')));
        $userType = in_array($_POST['user_type'] ?? '', ['faculty', 'staff']) ? $_POST['user_type'] : null;
        $department = sanitizeInput($_POST['department'] ?? '');
        $position = sanitizeInput($_POST['position'] ?? '');
        $designation = sanitizeInput($_POST['designation'] ?? '');
        $keyOfficial = sanitizeInput($_POST['key_official'] ?? '');
        $employmentStatus = sanitizeInput($_POST['employment_status'] ?? '');

        // Validate email format only when provided (allow partial updates)
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessages[] = 'Please enter a valid email address.';
        } elseif ($email !== '' && strpos($email, '@wpu.edu.ph') === false) {
            $errorMessages[] = 'Email must be a WPU email address (@wpu.edu.ph).';
        }

        if (!empty($errorMessages)) {
            $_SESSION['error'] = implode('<br>', $errorMessages);
            // Don't redirect - preserve entered data by using POST values when rendering form
            $preservePostData = true;
        }

        if (empty($errorMessages)) {
        // Check if email is already used by another user of the same type (only when email is provided)
        $emailConflict = false;
        if ($email !== '') {
            $stmt = $db->prepare("SELECT id, user_type FROM users WHERE LOWER(TRIM(email)) = ? AND id != ?");
            $stmt->execute([$email, $facultyId]);
            $existingUsers = $stmt->fetchAll();
            $currentUserStmt = $db->prepare("SELECT user_type FROM users WHERE id = ?");
            $currentUserStmt->execute([$facultyId]);
            $currentUser = $currentUserStmt->fetch();
            $typeToCheck = ($userType !== null) ? $userType : ($currentUser['user_type'] ?? 'faculty');
            if ($currentUser) {
                foreach ($existingUsers as $existingUser) {
                    if ($existingUser['user_type'] === $typeToCheck) {
                        $errorMessages[] = "This email address is already registered as a " . ucfirst($existingUser['user_type']) . " account. Each email can only be used once per user type.";
                        $emailConflict = true;
                        break;
                    }
                }
            }
        }

        if (!$emailConflict) {
            $updateFields = ['first_name = ?', 'last_name = ?', 'middle_name = ?', 'email = ?', 'updated_at = NOW()'];
            $updateParams = [$firstName, $lastName, $middleName, $email];
            if ($userType !== null) {
                $updateFields[] = 'user_type = ?';
                $updateParams[] = $userType;
            }
            $updateParams[] = $facultyId;
            $stmt = $db->prepare("UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?");
            if ($stmt->execute($updateParams)) {
                $successMessages[] = "Personal information updated successfully!";
                $logMsg = "Updated personal information for user ID: $facultyId";
                if ($userType !== null) {
                    $logMsg .= " (user type set to " . $userType . ")";
                }
                logAction('ADMIN_FACULTY_UPDATE', $logMsg);
            } else {
                $errorMessages[] = "Failed to update personal information.";
            }
        }

        // Update Faculty and Staff Profile
        $employeeId = sanitizeInput($_POST['employee_id'] ?? '');
        $gender = sanitizeInput($_POST['gender'] ?? '');
        $campus = sanitizeInput($_POST['campus'] ?? '');
        $employmentStatusDb = ($employmentStatus === '' || $employmentStatus === 'Select Status') ? null : $employmentStatus;
        $keyOfficialDb = empty($keyOfficial) || $keyOfficial === 'Select Key Official' ? null : $keyOfficial;
        $hireDateRaw = trim($_POST['hire_date'] ?? '');
        $hireDate = ($hireDateRaw === '' || $hireDateRaw === '0000-00-00') ? null : $hireDateRaw;
        $phone = sanitizeInput($_POST['phone'] ?? '');
        $address = sanitizeInput($_POST['address'] ?? '');
        
        // Check if employee_id is already used by another faculty
        if (!empty($employeeId)) {
            $stmt = $db->prepare("SELECT user_id FROM faculty_profiles WHERE employee_id = ? AND user_id != ?");
            $stmt->execute([$employeeId, $facultyId]);
            if ($stmt->fetch()) {
                $errorMessages[] = "Safe Employee ID already in use by another faculty member.";
            }
        }
        
        // Only proceed if no employee ID conflict
        if (empty($errorMessages)) {
            // Ensure hire_date is NULL when empty (MySQL rejects '' for date columns)
            $hireDateForDb = ($hireDate === null || $hireDate === '') ? null : $hireDate;
            $hireDateParamType = ($hireDateForDb === null) ? \PDO::PARAM_NULL : \PDO::PARAM_STR;

            // Check if faculty and staff profile exists
            $stmt = $db->prepare("SELECT id FROM faculty_profiles WHERE user_id = ?");
            $stmt->execute([$facultyId]);
            $profileExists = $stmt->fetch();
            
            if ($profileExists) {
                // Update existing profile
                $stmt = $db->prepare("UPDATE faculty_profiles SET employee_id = ?, gender = ?, campus = ?, department = ?, position = ?, designation = ?, key_official = ?, employment_status = ?, hire_date = ?, phone = ?, address = ?, updated_at = NOW() WHERE user_id = ?");
                $stmt->bindValue(1, $employeeId, \PDO::PARAM_STR);
                $stmt->bindValue(2, $gender, \PDO::PARAM_STR);
                $stmt->bindValue(3, $campus, \PDO::PARAM_STR);
                $stmt->bindValue(4, $department, \PDO::PARAM_STR);
                $stmt->bindValue(5, $position, \PDO::PARAM_STR);
                $stmt->bindValue(6, $designation, \PDO::PARAM_STR);
                $stmt->bindValue(7, $keyOfficialDb, \PDO::PARAM_STR);
                $stmt->bindValue(8, $employmentStatusDb, \PDO::PARAM_STR);
                $stmt->bindValue(9, $hireDateForDb, $hireDateParamType);
                $stmt->bindValue(10, $phone, \PDO::PARAM_STR);
                $stmt->bindValue(11, $address, \PDO::PARAM_STR);
                $stmt->bindValue(12, $facultyId, \PDO::PARAM_INT);
                $result = $stmt->execute();
            } else {
                // Create new profile
                $stmt = $db->prepare("INSERT INTO faculty_profiles (user_id, employee_id, gender, campus, department, position, designation, key_official, employment_status, hire_date, phone, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bindValue(1, $facultyId, \PDO::PARAM_INT);
                $stmt->bindValue(2, $employeeId, \PDO::PARAM_STR);
                $stmt->bindValue(3, $gender, \PDO::PARAM_STR);
                $stmt->bindValue(4, $campus, \PDO::PARAM_STR);
                $stmt->bindValue(5, $department, \PDO::PARAM_STR);
                $stmt->bindValue(6, $position, \PDO::PARAM_STR);
                $stmt->bindValue(7, $designation, \PDO::PARAM_STR);
                $stmt->bindValue(8, $keyOfficialDb, \PDO::PARAM_STR);
                $stmt->bindValue(9, $employmentStatusDb, \PDO::PARAM_STR);
                $stmt->bindValue(10, $hireDateForDb, $hireDateParamType);
                $stmt->bindValue(11, $phone, \PDO::PARAM_STR);
                $stmt->bindValue(12, $address, \PDO::PARAM_STR);
                $result = $stmt->execute();
            }
        } else {
            $result = false;
        }
        
        if ($result) {
            $successMessages[] = "Faculty and staff profile updated successfully!";
            logAction('ADMIN_FACULTY_PROFILE_UPDATE', "Updated faculty and staff profile information for faculty ID: $facultyId");
        } else {
            $errorMessages[] = "Failed to update faculty and staff profile.";
        }

        $allSuccessful = empty($errorMessages);

        if ($allSuccessful) {
            $_SESSION['success'] = "Faculty profile information has been updated successfully!";
            header('Location: edit_faculty.php?id=' . $facultyId);
            exit();
        } else {
            // If there are errors, preserve entered data - don't redirect
            if (!empty($successMessages)) {
                $_SESSION['success'] = implode('<br>', $successMessages);
            }
            if (!empty($errorMessages)) {
                $_SESSION['error'] = implode('<br>', $errorMessages);
            }
            $preservePostData = true;
        }
        }
    }
}

// Get user information (optimized: only select needed columns)
$stmt = $db->prepare("SELECT id, first_name, last_name, middle_name, email, is_active, user_type, created_at, updated_at FROM users WHERE id = ?");
$stmt->execute([$facultyId]);
$user = $stmt->fetch();

// Get faculty and staff profile (keeping SELECT * as all fields may be needed for editing)
$stmt = $db->prepare("SELECT * FROM faculty_profiles WHERE user_id = ?");
$stmt->execute([$facultyId]);
$facultyProfile = $stmt->fetch();

// When form had validation/update errors, preserve user-entered POST data so it is not lost
if (!empty($preservePostData) && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_all_profile_info') {
    $user['first_name'] = trim(sanitizeInput($_POST['first_name'] ?? $user['first_name']));
    $user['last_name'] = trim(sanitizeInput($_POST['last_name'] ?? $user['last_name']));
    $user['middle_name'] = sanitizeInput($_POST['middle_name'] ?? $user['middle_name'] ?? '');
    $user['email'] = trim(strtolower(sanitizeInput($_POST['email'] ?? $user['email'])));
    $user['user_type'] = in_array($_POST['user_type'] ?? '', ['faculty', 'staff']) ? $_POST['user_type'] : ($user['user_type'] ?? 'faculty');
    $facultyProfile = is_array($facultyProfile) ? $facultyProfile : [];
    $facultyProfile['employee_id'] = sanitizeInput($_POST['employee_id'] ?? $facultyProfile['employee_id'] ?? '');
    $facultyProfile['gender'] = sanitizeInput($_POST['gender'] ?? $facultyProfile['gender'] ?? '');
    $facultyProfile['campus'] = sanitizeInput($_POST['campus'] ?? $facultyProfile['campus'] ?? '');
    $facultyProfile['department'] = sanitizeInput($_POST['department'] ?? $facultyProfile['department'] ?? '');
    $facultyProfile['position'] = sanitizeInput($_POST['position'] ?? $facultyProfile['position'] ?? '');
    $facultyProfile['designation'] = sanitizeInput($_POST['designation'] ?? $facultyProfile['designation'] ?? '');
    $facultyProfile['key_official'] = sanitizeInput($_POST['key_official'] ?? $facultyProfile['key_official'] ?? '');
    $facultyProfile['employment_status'] = sanitizeInput($_POST['employment_status'] ?? $facultyProfile['employment_status'] ?? '');
    $facultyProfile['hire_date'] = $_POST['hire_date'] ?? $facultyProfile['hire_date'] ?? '';
    $facultyProfile['phone'] = sanitizeInput($_POST['phone'] ?? $facultyProfile['phone'] ?? '');
    $facultyProfile['address'] = sanitizeInput($_POST['address'] ?? $facultyProfile['address'] ?? '');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    require_once '../includes/admin_layout_helper.php';
    admin_page_head('Edit Faculty Profile', 'Edit faculty or staff profile information');
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
                    'Edit Faculty Profile',
                    '',
                    'fas fa-user-edit',
                    [
                        
                    ],
                    '<a href="faculty.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back to List</a>'
                );
                ?>

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
                                    <div class="position-relative d-inline-block" style="cursor: pointer;" id="profilePictureContainer">
                                        <?php if (!empty($facultyProfile['profile_picture'])): ?>
                                            <img src="../uploads/profiles/<?php echo htmlspecialchars($facultyProfile['profile_picture']); ?>?t=<?php echo time(); ?>" 
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
                                    <!-- Hidden form for profile picture upload -->
                                    <form id="profilePictureForm" method="POST" enctype="multipart/form-data" style="display: none;">
                                        <?php if (function_exists('addFormToken')) { addFormToken(); } ?>
                                        <input type="hidden" name="action" value="upload_profile_picture">
                                        <input type="file" name="profile_picture" id="profile_picture_input" accept="image/png,image/jpeg,image/jpg,image/gif">
                                    </form>
                                </div>

                                <form method="POST">
                                    <input type="hidden" name="action" value="update_all_profile_info">
                                    
                                    <h6 class="border-bottom pb-2 mb-3 text-primary">Personal Information</h6>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="first_name" class="form-label">First Name</label>
                                            <input type="text" class="form-control" id="first_name" name="first_name" 
                                                   value="<?php echo htmlspecialchars($user['first_name']); ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="last_name" class="form-label">Last Name</label>
                                            <input type="text" class="form-control" id="last_name" name="last_name" 
                                                   value="<?php echo htmlspecialchars($user['last_name']); ?>">
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
                                               value="<?php echo htmlspecialchars($user['email']); ?>">
                                        <div class="form-text">Must be a WPU email address (@wpu.edu.ph)</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="user_type" class="form-label">Employee Type</label>
                                        <select class="form-control" id="user_type" name="user_type">
                                            <option value="faculty" <?php echo ($user['user_type'] ?? 'faculty') === 'faculty' ? 'selected' : ''; ?>>Faculty</option>
                                            <option value="staff" <?php echo ($user['user_type'] ?? '') === 'staff' ? 'selected' : ''; ?>>Staff</option>
                                        </select>
                                        <div class="form-text">Whether this employee is classified as Faculty or Staff</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Account Status</label>
                                        <div>
                                            <span class="badge badge-<?php echo $user['is_active'] ? 'success' : 'danger'; ?>">
                                                <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </div>
                                        <div class="form-text">
                                            <i class="fas fa-info-circle me-1"></i>To change account status, use the toggle button on the faculty list page
                                        </div>
                                    </div>
                                    
                                    <h6 class="border-bottom pb-2 mb-3 mt-4 text-primary">Faculty and Staff Profile Information</h6>
                                    
                                    <div class="mb-3">
                                        <label for="employee_id" class="form-label">Safe Employee ID</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="employee_id" name="employee_id" 
                                                   value="<?php echo htmlspecialchars($facultyProfile['employee_id'] ?? ''); ?>" 
                                                   placeholder="Enter or generate Safe Employee ID">
                                            <button class="btn btn-outline-primary" type="button" id="generateEmployeeIdBtn" 
                                                    onclick="generateEmployeeId()">
                                                <i class="fas fa-sync-alt me-1"></i>Generate ID
                                            </button>
                                        </div>
                                        <small class="form-text text-muted">
                                            <i class="fas fa-info-circle me-1"></i>Click "Generate ID" for auto-generation or enter a custom unique Safe Employee ID
                                        </small>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="gender" class="form-label">Gender</label>
                                            <select class="form-control" id="gender" name="gender">
                                                <option value="">Select Gender</option>
                                                <option value="Male" <?php echo (($facultyProfile['gender'] ?? '') === 'Male') ? 'selected' : ''; ?>>Male</option>
                                                <option value="Female" <?php echo (($facultyProfile['gender'] ?? '') === 'Female') ? 'selected' : ''; ?>>Female</option>
                                                <option value="Other" <?php echo (($facultyProfile['gender'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
                                                <option value="Prefer not to say" <?php echo (($facultyProfile['gender'] ?? '') === 'Prefer not to say') ? 'selected' : ''; ?>>Prefer not to say</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="campus" class="form-label">Campus</label>
                                            <select class="form-control" id="campus" name="campus">
                                                <option value="">Select Campus</option>
                                                <?php foreach ($masterCampuses as $c): ?>
                                                    <option value="<?php echo htmlspecialchars($c['name']); ?>" <?php echo (($facultyProfile['campus'] ?? '') === $c['name']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="department" class="form-label">Department *</label>
                                            <select class="form-control" id="department" name="department">
                                                <option value="">Select Department</option>
                                                <?php foreach ($masterDepartments as $d): ?>
                                                    <option value="<?php echo htmlspecialchars($d['name']); ?>" <?php echo (($facultyProfile['department'] ?? '') === $d['name']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['name']); ?></option>
                                                <?php endforeach; ?>
                                                <option value="Other" <?php echo (($facultyProfile['department'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="position" class="form-label">Position *</label>
                                            <div class="position-select-wrapper">
                                                <input type="hidden" id="position" name="position" value="<?php echo htmlspecialchars($facultyProfile['position'] ?? ''); ?>">
                                                <div class="dropdown" id="positionDropdown">
                                                    <button class="form-control form-control-select text-start d-flex align-items-center justify-content-between" type="button" id="positionDisplayBtn" data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="listbox" aria-label="Select position">
                                                        <span id="positionDisplayText"><?php echo htmlspecialchars($facultyProfile['position'] ?? 'Select Position'); ?></span>
                                                        <i class="fas fa-chevron-down ms-2 text-muted"></i>
                                                    </button>
                                                    <ul class="dropdown-menu w-100 position-dropdown-menu" role="listbox" id="positionDropdownMenu">
                                                        <?php $hasSelectedPosition = !empty($facultyProfile['position'] ?? ''); ?>
                                                        <li><a class="dropdown-item position-option <?php echo !$hasSelectedPosition ? 'active' : ''; ?>" href="#" role="option" data-value="" data-display="Select Position" data-salary-grade="" data-annual-salary="">Select Position</a></li>
                                                        <?php 
                                                        $currentPosition = $facultyProfile['position'] ?? '';
                                                        $positionSelectedId = null;
                                                        foreach ($allPositions as $p): 
                                                            $step = (int)($p['step'] ?? 1);
                                                            $listText = htmlspecialchars($p['position_title']) . ' - SG-' . (int)$p['salary_grade'] . ' - Step ' . $step;
                                                            $isSelected = $currentPosition === $p['position_title'] && $positionSelectedId === null;
                                                            if ($isSelected) $positionSelectedId = $p['id'];
                                                        ?>
                                                        <li><a class="dropdown-item position-option <?php echo $isSelected ? 'active' : ''; ?>" href="#" role="option" 
                                                                data-value="<?php echo htmlspecialchars($p['position_title']); ?>" 
                                                                data-display="<?php echo htmlspecialchars($p['position_title']); ?>"
                                                                data-salary-grade="<?php echo $p['salary_grade']; ?>"
                                                                data-annual-salary="<?php echo $p['annual_salary']; ?>"><?php echo $listText; ?></a></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            </div>
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
                                            <label for="designation" class="form-label">Designation *</label>
                                            <select class="form-control" id="designation" name="designation">
                                                <option value="">Select Designation</option>
                                                <?php foreach ($masterDesignations as $d): ?>
                                                    <option value="<?php echo htmlspecialchars($d['name']); ?>" <?php echo (($facultyProfile['designation'] ?? '') === $d['name']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="form-text text-muted">e.g., Dean, Program Chair</small>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="employment_status" class="form-label">Employment Status *</label>
                                            <select class="form-control" id="employment_status" name="employment_status">
                                                <option value="">Select Status</option>
                                                <?php foreach ($masterEmploymentStatuses as $es): ?>
                                                    <option value="<?php echo htmlspecialchars($es['name']); ?>" <?php echo (($facultyProfile['employment_status'] ?? '') === $es['name']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($es['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="key_official" class="form-label">Key Official</label>
                                            <select class="form-control" id="key_official" name="key_official">
                                                <option value="">Select Key Official</option>
                                                <?php foreach ($masterKeyOfficials as $k): ?>
                                                    <option value="<?php echo htmlspecialchars($k['name']); ?>" <?php echo (($facultyProfile['key_official'] ?? '') === $k['name']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($k['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="form-text text-muted">e.g., President, VP Academic</small>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="hire_date" class="form-label">Hire Date</label>
                                            <div class="input-group">
                                                <input type="date" class="form-control" id="hire_date" name="hire_date" 
                                                       value="<?php echo htmlspecialchars($facultyProfile['hire_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php if (empty($facultyProfile['hire_date'])): ?>
                                                <button type="button" class="btn btn-outline-secondary" onclick="fillTodayDate()" title="Fill with today's date">
                                                    <i class="fas fa-calendar-day"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                            <small class="form-text text-muted">Click the calendar icon to fill with today's date</small>
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
                                        <i class="fas fa-save me-1"></i>Update Faculty Profile
                                    </button>
                                    <a href="faculty.php" class="btn btn-secondary">
                                        <i class="fas fa-times me-1"></i>Cancel
                                    </a>
                                </form>
                            </div>
                        </div>
                    </div>

                <!-- Account Information -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-info-circle me-2"></i>Account Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <p><strong>User ID:</strong> <?php echo $user['id']; ?></p>
                                        <p><strong>User Type:</strong> <?php echo ucfirst($user['user_type']); ?></p>
                                        <p><strong>Account Created:</strong> <?php echo !empty($user['created_at']) ? formatDate($user['created_at'], 'F j, Y g:i A') : 'N/A'; ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>Last Updated:</strong> <?php echo !empty($user['updated_at']) ? formatDate($user['updated_at'], 'F j, Y g:i A') : 'N/A'; ?></p>
                                        <?php
                                        // Get last login from system_logs if available
                                        $lastLoginStmt = $db->prepare("SELECT created_at FROM system_logs WHERE user_id = ? AND action = 'LOGIN' ORDER BY created_at DESC LIMIT 1");
                                        $lastLoginStmt->execute([$facultyId]);
                                        $lastLogin = $lastLoginStmt->fetch();
                                        ?>
                                        <p><strong>Last Login:</strong> <?php echo !empty($lastLogin['created_at']) ? formatDate($lastLogin['created_at'], 'F j, Y g:i A') : 'Never'; ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <?php admin_page_scripts(); ?>
    <style>
        .position-select-wrapper .form-control-select { cursor: pointer; }
        .position-select-wrapper .dropdown-menu { max-height: 280px; overflow-y: auto; }
        .position-select-wrapper .dropdown-item.position-option { white-space: normal; }
    </style>
    <script>
        // Position selection handler - custom dropdown (list shows Position + SG + Step; selected shows Position only)
        document.addEventListener('DOMContentLoaded', function() {
            const positionHidden = document.getElementById('position');
            const positionDisplayBtn = document.getElementById('positionDisplayBtn');
            const positionDisplayText = document.getElementById('positionDisplayText');
            const positionOptions = document.querySelectorAll('.position-option');
            const salaryGradeInput = document.getElementById('salary_grade');
            const annualSalaryInput = document.getElementById('annual_salary');
            const positionDropdown = document.getElementById('positionDropdown');
            
            function updateSalaryFromPosition(salaryGrade, annualSalary) {
                if (salaryGradeInput) salaryGradeInput.value = salaryGrade || '';
                if (annualSalaryInput) annualSalaryInput.value = annualSalary ? '₱' + parseFloat(annualSalary).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '';
            }
            
            function selectPosition(opt) {
                const value = opt.dataset.value || '';
                const display = opt.dataset.display || 'Select Position';
                const salaryGrade = opt.dataset.salaryGrade || '';
                const annualSalary = opt.dataset.annualSalary || '';
                
                if (positionHidden) positionHidden.value = value;
                if (positionDisplayText) positionDisplayText.textContent = display;
                updateSalaryFromPosition(salaryGrade, annualSalary);
                
                positionOptions.forEach(o => o.classList.remove('active'));
                opt.classList.add('active');
                
                if (positionDropdown && window.bootstrap) {
                    const bsDropdown = bootstrap.Dropdown.getInstance(positionDisplayBtn);
                    if (bsDropdown) bsDropdown.hide();
                }
            }
            
            if (positionOptions.length) {
                positionOptions.forEach(function(opt) {
                    opt.addEventListener('click', function(e) {
                        e.preventDefault();
                        selectPosition(this);
                    });
                });
            }
            
            // Initialize salary fields from current selection on load
            const activeOpt = document.querySelector('.position-option.active');
            if (activeOpt && salaryGradeInput && annualSalaryInput) {
                updateSalaryFromPosition(activeOpt.dataset.salaryGrade, activeOpt.dataset.annualSalary);
            }
            
            const profilePictureForm = document.getElementById('profilePictureForm');
            const profilePictureInput = document.getElementById('profile_picture_input');
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
                    
                    // Validate file type
                    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                    if (!allowedTypes.includes(file.type)) {
                        alert('Please select a valid image file (JPG, PNG, or GIF).');
                        this.value = '';
                        return;
                    }
                    
                    // Validate file size
                    const maxSize = <?php echo MAX_FILE_SIZE; ?>;
                    if (file.size > maxSize) {
                        alert('File size exceeds the maximum allowed size of <?php echo number_format(MAX_FILE_SIZE / 1024 / 1024, 1); ?> MB.');
                        this.value = '';
                        return;
                    }
                    
                    // Mark as submitting
                    isSubmitting = true;
                    
                    // Submit form automatically
                    setTimeout(function() {
                        if (profilePictureForm.requestSubmit) {
                            profilePictureForm.requestSubmit();
                        } else {
                            profilePictureForm.submit();
                        }
                    }, 100);
                });
                
                // Handle form submission
                profilePictureForm.addEventListener('submit', function(e) {
                    const file = profilePictureInput.files[0];
                    
                    if (!file) {
                        e.preventDefault();
                        alert('Please select an image file to upload.');
                        isSubmitting = false;
                        return false;
                    }
                    
                    // Validate file type
                    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                    if (!allowedTypes.includes(file.type)) {
                        e.preventDefault();
                        alert('Please select a valid image file (JPG, PNG, or GIF).');
                        isSubmitting = false;
                        return false;
                    }
                    
                    // Validate file size
                    const maxSize = <?php echo MAX_FILE_SIZE; ?>;
                    if (file.size > maxSize) {
                        e.preventDefault();
                        alert('File size exceeds the maximum allowed size of <?php echo number_format(MAX_FILE_SIZE / 1024 / 1024, 1); ?> MB.');
                        isSubmitting = false;
                        return false;
                    }
                });
            }
        });

        function generateEmployeeId() {
            const btn = document.getElementById('generateEmployeeIdBtn');
            const input = document.getElementById('employee_id');
            const originalHtml = btn.innerHTML;
            
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
    </script>
</body>
</html>

