<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/auth.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();
$auth = new Auth();

$action = $_POST['action'] ?? '';
$message = '';
$error = '';
$masterListPayload = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($action) {
        case 'create_admin':
            if (!isSuperAdmin()) {
                $error = "Access denied. Only super admins can create admin accounts.";
                break;
            }
            $email = trim(strtolower(sanitizeInput($_POST['email'])));
            $password = $_POST['password'];
            $firstName = sanitizeInput($_POST['first_name']);
            $lastName = sanitizeInput($_POST['last_name']);
            $userType = in_array($_POST['user_type'] ?? '', ['admin', 'super_admin']) ? $_POST['user_type'] : 'admin';
            
            // Validate WPU email
            if (!validateWPUEmail($email)) {
                $error = "Only WPU email addresses (@wpu.edu.ph) are allowed for admin accounts.";
                break;
            }
            
            // Check if email already exists for admin/super_admin
            $stmt = $db->prepare("SELECT id, user_type FROM users WHERE LOWER(TRIM(email)) = ? AND user_type IN ('admin', 'super_admin')");
            $stmt->execute([strtolower(trim($email))]);
            $existingUser = $stmt->fetch();
            if ($existingUser) {
                $error = "This email address is already registered as an Admin account. Each email can only be used once per user type.";
                break;
            }
            
            // Create admin user (only super admins can set admin or super_admin)
            $result = $auth->createAdmin($email, $password, $firstName, $lastName, $userType);
            if ($result === 'success') {
                // Send email with credentials to the admin
                require_once '../includes/mailer.php';
                $mailer = new Mailer();
                
                $emailSent = $mailer->sendAdminAccountCreationEmail(
                    $email,
                    $firstName,
                    $lastName,
                    $password
                );
                
                if ($emailSent) {
                    $message = "Admin user created successfully! Login credentials have been sent to " . htmlspecialchars($email) . ".";
                } else {
                    $message = "Admin user created successfully! However, the email could not be sent to " . htmlspecialchars($email) . ". Please provide the credentials manually.";
                }
                logAction('ADMIN_CREATED', "Created new admin user: $email");
            } else {
                $error = $result;
            }
            break;
            
        case 'update_profile':
            $userId = (int)$_POST['user_id'];
            $firstName = sanitizeInput($_POST['first_name']);
            $lastName = sanitizeInput($_POST['last_name']);
            $email = trim(strtolower(sanitizeInput($_POST['email'])));
            
            // Check if email is already used by another user of the same type
            // Allow duplicate emails for different user types (admin vs faculty/staff)
            $stmt = $db->prepare("SELECT id, user_type FROM users WHERE LOWER(TRIM(email)) = ? AND id != ?");
            $stmt->execute([$email, $userId]);
            $existingUsers = $stmt->fetchAll();
            
            // Get the current user's type
            $currentUserStmt = $db->prepare("SELECT user_type FROM users WHERE id = ?");
            $currentUserStmt->execute([$userId]);
            $currentUser = $currentUserStmt->fetch();
            
            if ($currentUser) {
                foreach ($existingUsers as $existingUser) {
                    // Only prevent if same user type
                    if ($existingUser['user_type'] === $currentUser['user_type']) {
                        $error = "This email address is already registered as a " . ucfirst($existingUser['user_type']) . " account. Each email can only be used once per user type.";
                        break;
                    }
                }
            }
            
            if ($error) {
                break;
            }
            
            $stmt = $db->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt->execute([$firstName, $lastName, $email, $userId])) {
                $message = "Profile updated successfully!";
                logAction('PROFILE_UPDATE', "Updated profile for user ID: $userId");
            } else {
                $error = "Failed to update profile.";
            }
            break;
            
        case 'change_password':
            // Get user ID - use session user_id if not provided (for current user)
            $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : $_SESSION['user_id'];
            
            // Ensure user can only change their own password unless they're admin changing another user's
            if ($userId != $_SESSION['user_id'] && !isAdmin()) {
                $error = "You can only change your own password.";
                break;
            }
            
            // Validate CSRF token
            if (!validateFormToken($_POST['csrf_token'] ?? '')) {
                $error = "Invalid form submission. Please try again.";
                break;
            }
            
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                $error = "All password fields are required.";
                break;
            }
            
            if ($newPassword !== $confirmPassword) {
                $error = "New passwords do not match.";
                break;
            }
            
            // Validate password strength
            $passwordValidation = validatePasswordStrength($newPassword);
            if (!$passwordValidation['valid']) {
                $error = implode('<br>', $passwordValidation['errors']);
                break;
            }
            
            // Verify current password
            $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user || !password_verify($currentPassword, $user['password'])) {
                $error = "Current password is incorrect.";
                logSecurityEvent('PASSWORD_CHANGE_FAILED', "Incorrect current password for user ID: $userId");
                break;
            }
            
            // Check if new password is same as current
            if (password_verify($newPassword, $user['password'])) {
                $error = "New password must be different from your current password.";
                break;
            }
            
            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt->execute([$hashedPassword, $userId])) {
                $message = "Password changed successfully!";
                logAction('PASSWORD_CHANGE', "Changed password for user ID: $userId");
                logSecurityEvent('PASSWORD_CHANGE_SUCCESS', "Password changed for user ID: $userId");
            } else {
                $error = "Failed to change password.";
            }
            break;
            
        case 'delete_user':
            if (!isSuperAdmin()) {
                $error = "Access denied. Only super admins can delete or deactivate accounts.";
                break;
            }
            $userId = (int)$_POST['user_id'];
            
            // Prevent deleting own account
            if ($userId === $_SESSION['user_id']) {
                $error = "You cannot delete your own account.";
                break;
            }
            
            // Get user info for logging
            $stmt = $db->prepare("SELECT email, user_type FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $error = "User not found.";
                break;
            }
            
            // If deleting a faculty/staff member, delete their pardon requests
            if (in_array($user['user_type'], ['faculty', 'staff'])) {
                // Get employee_id from faculty profile
                $stmt = $db->prepare("SELECT employee_id FROM faculty_profiles WHERE user_id = ?");
                $stmt->execute([$userId]);
                $profile = $stmt->fetch();
                
                if ($profile && !empty($profile['employee_id'])) {
                    $employeeId = $profile['employee_id'];
                    
                    // Get all pardon requests for this employee
                    $stmt = $db->prepare("SELECT id, supporting_documents FROM pardon_requests WHERE employee_id = ?");
                    $stmt->execute([$employeeId]);
                    $pardonRequests = $stmt->fetchAll();
                    
                    // Delete supporting document files
                    foreach ($pardonRequests as $request) {
                        if (!empty($request['supporting_documents'])) {
                            $documents = json_decode($request['supporting_documents'], true);
                            if (is_array($documents)) {
                                foreach ($documents as $docPath) {
                                    // Path is stored as 'pardon_requests/filename.ext'
                                    $filePath = '../uploads/' . $docPath;
                                    if (file_exists($filePath)) {
                                        @unlink($filePath);
                                    }
                                }
                            }
                        }
                    }
                    
                    // Delete pardon requests
                    $stmt = $db->prepare("DELETE FROM pardon_requests WHERE employee_id = ?");
                    $stmt->execute([$employeeId]);
                }
            }
            
            // Delete user (cascade will handle related records)
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            if ($stmt->execute([$userId])) {
                $message = "User deleted successfully!";
                logAction('USER_DELETED', "Deleted {$user['user_type']} user: {$user['email']}");
            } else {
                $error = "Failed to delete user.";
            }
            break;

        case 'toggle_status':
            if (!isSuperAdmin()) {
                $error = "Access denied. Only super admins can activate or deactivate admin accounts.";
                break;
            }
            $userId = (int)$_POST['user_id'];
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            
            if ($userId === $_SESSION['user_id']) {
                $error = "You cannot change your own account status.";
                break;
            }
            
            $stmt = $db->prepare("SELECT id, email, user_type FROM users WHERE id = ? AND user_type IN ('admin', 'super_admin')");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $error = "User not found or cannot be modified.";
                break;
            }
            
            $stmt = $db->prepare("UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt->execute([$isActive, $userId])) {
                $actionLabel = $isActive ? 'activated' : 'deactivated';
                $message = "Admin user {$actionLabel} successfully.";
                logAction('ADMIN_STATUS_TOGGLED', "{$actionLabel} admin user: {$user['email']}");
            } else {
                $error = "Failed to update user status.";
            }
            break;

        case 'change_admin_role':
            if (!isSuperAdmin()) {
                $error = "Access denied. Only super admins can change admin or super-admin roles.";
                break;
            }
            if (!validateFormToken($_POST['csrf_token'] ?? '')) {
                $error = "Invalid form submission. Please try again.";
                break;
            }
            $userId = (int)$_POST['user_id'];
            $newRole = $_POST['user_type'] ?? '';
            if (!in_array($newRole, ['admin', 'super_admin'])) {
                $error = "Invalid role. Must be admin or super_admin.";
                break;
            }
            if ($userId === $_SESSION['user_id']) {
                $error = "You cannot change your own role.";
                break;
            }
            $stmt = $db->prepare("SELECT id, email, user_type FROM users WHERE id = ? AND user_type IN ('admin', 'super_admin')");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if (!$user) {
                $error = "User not found or cannot be modified.";
                break;
            }
            // Prevent demoting the last super admin
            if ($user['user_type'] === 'super_admin' && $newRole === 'admin') {
                $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE user_type = 'super_admin' AND is_active = 1");
                $stmt->execute();
                if ((int)$stmt->fetchColumn() <= 1) {
                    $error = "Cannot demote the last super admin. At least one super admin must remain.";
                    break;
                }
            }
            $stmt = $db->prepare("UPDATE users SET user_type = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt->execute([$newRole, $userId])) {
                $message = "User role updated to " . ($newRole === 'super_admin' ? 'Super Admin' : 'Admin') . " successfully.";
                logAction('ADMIN_ROLE_CHANGED', "Changed {$user['email']} from {$user['user_type']} to {$newRole}");
            } else {
                $error = "Failed to update user role.";
            }
            break;

        // Departments / Positions / Employment Status CRUD
        case 'add_department':
            $name = sanitizeInput($_POST['name'] ?? '');
            if ($name === '') { $error = 'Name is required.'; break; }
            $stmt = $db->prepare("INSERT INTO departments (name) VALUES (?)");
            try {
                $stmt->execute([$name]);
                $message = 'Department added.';
                logAction('DEPARTMENT_ADDED', "Added department: $name");
            } catch (Exception $e) {
                $error = 'Failed to add department (maybe already exists).';
            }
            break;

        case 'update_department':
            $id = (int)($_POST['id'] ?? 0);
            $name = sanitizeInput($_POST['name'] ?? '');
            if ($id <= 0 || $name === '') { $error = 'Invalid parameters.'; break; }
            $stmt = $db->prepare("UPDATE departments SET name = ? WHERE id = ?");
            if ($stmt->execute([$name, $id])) {
                $message = 'Department updated.';
                logAction('DEPARTMENT_UPDATED', "Updated department ID $id -> $name");
            } else {
                $error = 'Failed to update department.';
            }
            break;

        case 'delete_department':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { $error = 'Invalid id.'; break; }
            $stmt = $db->prepare("DELETE FROM departments WHERE id = ?");
            if ($stmt->execute([$id])) {
                $message = 'Department deleted.';
                logAction('DEPARTMENT_DELETED', "Deleted department ID $id");
            } else {
                $error = 'Failed to delete department.';
            }
            break;

        case 'add_position':
            $name = sanitizeInput($_POST['name'] ?? '');
            if ($name === '') { $error = 'Name is required.'; break; }
            $stmt = $db->prepare("INSERT INTO positions (name) VALUES (?)");
            try {
                $stmt->execute([$name]);
                $message = 'Position added.';
                logAction('POSITION_ADDED', "Added position: $name");
            } catch (Exception $e) {
                $error = 'Failed to add position (maybe already exists).';
            }
            break;

        case 'update_position':
            $id = (int)($_POST['id'] ?? 0);
            $name = sanitizeInput($_POST['name'] ?? '');
            if ($id <= 0 || $name === '') { $error = 'Invalid parameters.'; break; }
            $stmt = $db->prepare("UPDATE positions SET name = ? WHERE id = ?");
            if ($stmt->execute([$name, $id])) {
                $message = 'Position updated.';
                logAction('POSITION_UPDATED', "Updated position ID $id -> $name");
            } else {
                $error = 'Failed to update position.';
            }
            break;

        case 'delete_position':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { $error = 'Invalid id.'; break; }
            $stmt = $db->prepare("DELETE FROM positions WHERE id = ?");
            if ($stmt->execute([$id])) {
                $message = 'Position deleted.';
                logAction('POSITION_DELETED', "Deleted position ID $id");
            } else {
                $error = 'Failed to delete position.';
            }
            break;

        case 'add_employment_status':
            $name = sanitizeInput($_POST['name'] ?? '');
            if ($name === '') { $error = 'Name is required.'; break; }
            $stmt = $db->prepare("INSERT INTO employment_statuses (name) VALUES (?)");
            try {
                $stmt->execute([$name]);
                $message = 'Employment status added.';
                logAction('EMP_STATUS_ADDED', "Added employment status: $name");
            } catch (Exception $e) {
                $error = 'Failed to add employment status (maybe already exists).';
            }
            break;

        case 'update_employment_status':
            $id = (int)($_POST['id'] ?? 0);
            $name = sanitizeInput($_POST['name'] ?? '');
            if ($id <= 0 || $name === '') { $error = 'Invalid parameters.'; break; }
            $stmt = $db->prepare("UPDATE employment_statuses SET name = ? WHERE id = ?");
            if ($stmt->execute([$name, $id])) {
                $message = 'Employment status updated.';
                logAction('EMP_STATUS_UPDATED', "Updated employment status ID $id -> $name");
            } else {
                $error = 'Failed to update employment status.';
            }
            break;

        case 'delete_employment_status':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { $error = 'Invalid id.'; break; }
            $stmt = $db->prepare("DELETE FROM employment_statuses WHERE id = ?");
            if ($stmt->execute([$id])) {
                $message = 'Employment status deleted.';
                logAction('EMP_STATUS_DELETED', "Deleted employment status ID $id");
            } else {
                $error = 'Failed to delete employment status.';
            }
            break;

        case 'add_campus':
            $name = sanitizeInput($_POST['name'] ?? '');
            if ($name === '') { $error = 'Name is required.'; break; }
            $stmt = $db->prepare("INSERT INTO campuses (name) VALUES (?)");
            try {
                $stmt->execute([$name]);
                $message = 'Campus added.';
                logAction('CAMPUS_ADDED', "Added campus: $name");
            } catch (Exception $e) {
                $error = 'Failed to add campus (maybe already exists).';
            }
            break;

        case 'update_campus':
            $id = (int)($_POST['id'] ?? 0);
            $name = sanitizeInput($_POST['name'] ?? '');
            if ($id <= 0 || $name === '') { $error = 'Invalid parameters.'; break; }
            $stmt = $db->prepare("UPDATE campuses SET name = ? WHERE id = ?");
            if ($stmt->execute([$name, $id])) {
                $message = 'Campus updated.';
                logAction('CAMPUS_UPDATED', "Updated campus ID $id -> $name");
            } else {
                $error = 'Failed to update campus.';
            }
            break;

        case 'delete_campus':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { $error = 'Invalid id.'; break; }
            $stmt = $db->prepare("DELETE FROM campuses WHERE id = ?");
            if ($stmt->execute([$id])) {
                $message = 'Campus deleted.';
                logAction('CAMPUS_DELETED', "Deleted campus ID $id");
            } else {
                $error = 'Failed to delete campus.';
            }
            break;

        case 'add_designation':
            $name = sanitizeInput($_POST['name'] ?? '');
            if ($name === '') { $error = 'Name is required.'; break; }
            $stmt = $db->prepare("INSERT INTO designations (name) VALUES (?)");
            try {
                $stmt->execute([$name]);
                $message = 'Designation added.';
                logAction('DESIGNATION_ADDED', "Added designation: $name");
            } catch (Exception $e) {
                $error = 'Failed to add designation (maybe already exists).';
            }
            break;

        case 'update_designation':
            $id = (int)($_POST['id'] ?? 0);
            $name = sanitizeInput($_POST['name'] ?? '');
            if ($id <= 0 || $name === '') { $error = 'Invalid parameters.'; break; }
            $stmt = $db->prepare("UPDATE designations SET name = ? WHERE id = ?");
            if ($stmt->execute([$name, $id])) {
                $message = 'Designation updated.';
                logAction('DESIGNATION_UPDATED', "Updated designation ID $id -> $name");
            } else {
                $error = 'Failed to update designation.';
            }
            break;

        case 'delete_designation':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { $error = 'Invalid id.'; break; }
            $stmt = $db->prepare("DELETE FROM designations WHERE id = ?");
            if ($stmt->execute([$id])) {
                $message = 'Designation deleted.';
                logAction('DESIGNATION_DELETED', "Deleted designation ID $id");
            } else {
                $error = 'Failed to delete designation.';
            }
            break;

        case 'add_key_official':
            $name = sanitizeInput($_POST['name'] ?? '');
            if ($name === '') { $error = 'Name is required.'; break; }
            $stmt = $db->prepare("INSERT INTO key_officials (name) VALUES (?)");
            try {
                $stmt->execute([$name]);
                $message = 'Key official added.';
                logAction('KEY_OFFICIAL_ADDED', "Added key official: $name");
            } catch (Exception $e) {
                $error = 'Failed to add key official (maybe already exists).';
            }
            break;

        case 'update_key_official':
            $id = (int)($_POST['id'] ?? 0);
            $name = sanitizeInput($_POST['name'] ?? '');
            if ($id <= 0 || $name === '') { $error = 'Invalid parameters.'; break; }
            $stmt = $db->prepare("UPDATE key_officials SET name = ? WHERE id = ?");
            if ($stmt->execute([$name, $id])) {
                $message = 'Key official updated.';
                logAction('KEY_OFFICIAL_UPDATED', "Updated key official ID $id -> $name");
            } else {
                $error = 'Failed to update key official.';
            }
            break;

        case 'delete_key_official':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { $error = 'Invalid id.'; break; }
            $stmt = $db->prepare("DELETE FROM key_officials WHERE id = ?");
            if ($stmt->execute([$id])) {
                $message = 'Key official deleted.';
                logAction('KEY_OFFICIAL_DELETED', "Deleted key official ID $id");
            } else {
                $error = 'Failed to delete key official.';
            }
            break;

        case 'get_master_list_page':
            $listType = $_POST['list_type'] ?? '';
            $page = max(1, (int)($_POST['page'] ?? 1));
            $search = trim($_POST['search'] ?? '');
            $perPage = 5;
            $allowed = ['department' => 'departments', 'employment_status' => 'employment_statuses', 'campus' => 'campuses', 'designation' => 'designations', 'key_official' => 'key_officials'];
            if (!isset($allowed[$listType])) {
                $error = 'Invalid list type.';
                break;
            }
            $table = $allowed[$listType];
            $hasSearch = $search !== '';
            $searchPattern = $hasSearch ? '%' . $search . '%' : null;

            if ($hasSearch) {
                $countSql = "SELECT COUNT(*) FROM {$table} WHERE name LIKE ?";
                $countStmt = $db->prepare($countSql);
                $countStmt->execute([$searchPattern]);
                $total = (int)$countStmt->fetchColumn();
            } else {
                $total = (int)$db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            }
            $totalPages = max(1, (int)ceil($total / $perPage));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $perPage;

            if ($hasSearch) {
                $stmt = $db->prepare("SELECT id, name FROM {$table} WHERE name LIKE ? ORDER BY name LIMIT ? OFFSET ?");
                $stmt->bindValue(1, $searchPattern, PDO::PARAM_STR);
                $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
                $stmt->bindValue(3, $offset, PDO::PARAM_INT);
            } else {
                $stmt = $db->prepare("SELECT id, name FROM {$table} ORDER BY name LIMIT ? OFFSET ?");
                $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
                $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            }
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $masterListPayload = ['success' => true, 'items' => $items, 'page' => $page, 'totalPages' => $totalPages, 'perPage' => $perPage];
            break;
            
        case 'add_pardon_opener':
            if (!isSuperAdmin()) {
                $error = "Access denied. Only super admins can manage pardon openers.";
                break;
            }
            $userId = (int)($_POST['user_id'] ?? 0);
            $scopeType = $_POST['scope_type'] ?? '';
            $scopeValues = $_POST['scope_value'] ?? [];
            if (!is_array($scopeValues)) {
                $scopeValues = $scopeValues !== '' ? [trim(sanitizeInput($scopeValues))] : [];
            } else {
                $scopeValues = array_filter(array_map(function($v) { return trim(sanitizeInput($v)); }, $scopeValues));
            }
            if ($userId <= 0 || !in_array($scopeType, ['department', 'designation']) || empty($scopeValues)) {
                $error = "Invalid parameters. Select a user, scope type, and at least one scope value.";
                break;
            }
            $added = 0;
            $skipped = 0;
            try {
                $stmt = $db->prepare("INSERT INTO pardon_opener_assignments (user_id, scope_type, scope_value) VALUES (?, ?, ?)");
                foreach ($scopeValues as $scopeValue) {
                    if ($scopeValue === '') continue;
                    try {
                        $stmt->execute([$userId, $scopeType, $scopeValue]);
                        $added++;
                    } catch (Exception $e) {
                        $skipped++; // Duplicate or constraint
                    }
                }
                if ($added > 0) {
                    $message = $added . " pardon opener assignment(s) added." . ($skipped > 0 ? " ($skipped already existed.)" : "");
                    logAction('PARDON_OPENER_ADDED', "Added pardon opener: user_id=$userId scope=$scopeType: " . implode(',', $scopeValues));
                } else {
                    $error = "All selected assignments already exist.";
                }
            } catch (Exception $e) {
                $error = "Failed to add. Please try again.";
            }
            break;

        case 'delete_pardon_opener':
            if (!isSuperAdmin()) {
                $error = "Access denied. Only super admins can manage pardon openers.";
                break;
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { $error = 'Invalid id.'; break; }
            $stmt = $db->prepare("DELETE FROM pardon_opener_assignments WHERE id = ?");
            if ($stmt->execute([$id])) {
                $message = "Pardon opener assignment removed.";
                logAction('PARDON_OPENER_DELETED', "Deleted pardon opener assignment ID $id");
            } else {
                $error = "Failed to remove assignment.";
            }
            break;

        case 'delete_all_pardon_opener':
            if (!isSuperAdmin()) {
                $error = "Access denied. Only super admins can manage pardon openers.";
                break;
            }
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) { $error = 'Invalid user.'; break; }
            $stmt = $db->prepare("DELETE FROM pardon_opener_assignments WHERE user_id = ?");
            if ($stmt->execute([$userId])) {
                $deleted = $stmt->rowCount();
                $message = $deleted . " pardon opener assignment(s) removed.";
                logAction('PARDON_OPENER_DELETED', "Deleted all pardon opener assignments for user_id=$userId (count=$deleted)");
            } else {
                $error = "Failed to remove assignments.";
            }
            break;

        case 'update_pardon_limit':
            if (!isSuperAdmin()) {
                $error = "Access denied. Only super admins can modify the weekly pardon limit.";
                break;
            }
            // Validate CSRF token
            if (!validateFormToken($_POST['csrf_token'] ?? '')) {
                $error = "Invalid form submission. Please try again.";
                break;
            }
            
            $limit = intval($_POST['pardon_weekly_limit'] ?? 3);
            if ($limit < 1 || $limit > 100) {
                $error = "Invalid pardon limit. Must be between 1 and 100.";
                break;
            }
            
            // Check if setting exists
            $stmt = $db->prepare("SELECT id FROM system_settings WHERE setting_key = 'pardon_weekly_limit' LIMIT 1");
            $stmt->execute();
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Update existing setting
                $stmt = $db->prepare("UPDATE system_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = 'pardon_weekly_limit'");
                if ($stmt->execute([$limit])) {
                    $message = "Pardon weekly limit updated to {$limit} times per week.";
                    logAction('PARDON_LIMIT_UPDATED', "Updated pardon weekly limit to $limit");
                } else {
                    $error = "Failed to update pardon limit.";
                }
            } else {
                // Insert new setting
                $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES ('pardon_weekly_limit', ?, 'Maximum number of pardon requests allowed per employee per week (3 or 5)')");
                if ($stmt->execute([$limit])) {
                    $message = "Pardon weekly limit set to {$limit} times per week.";
                    logAction('PARDON_LIMIT_CREATED', "Created pardon weekly limit: $limit");
                } else {
                    $error = "Failed to save pardon limit.";
                }
            }
            break;
            
        default:
            $error = "Invalid action.";
    }
}

// Check if this is an AJAX request
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Optional: item payload for add_* master list actions (no page reload)
$ajaxItem = null;
if ($isAjax && $message && in_array($action, ['add_department', 'add_employment_status', 'add_campus', 'add_designation', 'add_key_official'])) {
    $ajaxItem = isset($db) ? ['id' => (int)$db->lastInsertId(), 'name' => sanitizeInput($_POST['name'] ?? '')] : null;
}

// Set message in session for display
if ($message) {
    $_SESSION['success'] = $message;
} elseif ($error) {
    $_SESSION['error'] = $error;
}

// If AJAX request, return JSON response
if ($isAjax) {
    header('Content-Type: application/json');
    if ($masterListPayload !== null) {
        echo json_encode($masterListPayload);
        exit();
    }
    $payload = [
        'success' => !empty($message),
        'message' => $message ?: $error,
        'error' => $error
    ];
    if ($ajaxItem !== null) {
        $payload['item'] = $ajaxItem;
    }
    echo json_encode($payload);
    exit();
}

// Redirect back to settings page (preserve section anchor for pardon openers)
$redirectUrl = 'settings.php';
if (in_array($action, ['add_pardon_opener', 'delete_pardon_opener', 'delete_all_pardon_opener'])) {
    $redirectUrl .= '#section-pardon-openers';
}
header('Location: ' . $redirectUrl);
exit();
?>






