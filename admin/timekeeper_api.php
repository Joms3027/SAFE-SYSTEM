<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

header('Content-Type: application/json');

$database = Database::getInstance();
$db = $database->getConnection();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'create':
            $userId = (int)$_POST['employee_id'];
            $stationId = (int)$_POST['station_id'];
            $password = $_POST['password'] ?? '';
            $repeatPassword = $_POST['repeat_password'] ?? '';
            
            // Validate inputs
            if ($userId <= 0) {
                throw new Exception('Employee is required.');
            }
            
            if ($stationId <= 0) {
                throw new Exception('Station is required.');
            }
            
            if (empty($password)) {
                throw new Exception('Password is required.');
            }
            
            if ($password !== $repeatPassword) {
                throw new Exception('Passwords do not match.');
            }
            
            if (strlen($password) < 6) {
                throw new Exception('Password must be at least 6 characters long.');
            }
            
            // Check if user exists and is active, and get email and employee_id
            $stmt = $db->prepare("
                SELECT u.id, u.first_name, u.last_name, u.middle_name, u.email, fp.employee_id
                FROM users u
                LEFT JOIN faculty_profiles fp ON u.id = fp.user_id
                WHERE u.id = ? AND u.is_active = 1 AND u.user_type IN ('faculty', 'staff')
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user) {
                throw new Exception('Selected employee not found or is not active.');
            }
            
            if (empty($user['email'])) {
                throw new Exception('Employee email address is required. Please ensure the employee has a valid email address.');
            }
            
            if (empty($user['employee_id'])) {
                throw new Exception('Safe Employee ID is required. Please ensure the employee has a Safe Employee ID assigned.');
            }
            
            // Check if user already has a timekeeper account
            $stmt = $db->prepare("SELECT id FROM timekeepers WHERE user_id = ?");
            $stmt->execute([$userId]);
            if ($stmt->fetch()) {
                throw new Exception('This employee already has a timekeeper account.');
            }
            
            // Check if station exists and get station name with department
            $stmt = $db->prepare("SELECT s.id, s.name, d.name as department_name FROM stations s LEFT JOIN departments d ON s.department_id = d.id WHERE s.id = ?");
            $stmt->execute([$stationId]);
            $station = $stmt->fetch();
            
            if (!$station) {
                throw new Exception('Selected station does not exist.');
            }
            
            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert new timekeeper
            $stmt = $db->prepare("
                INSERT INTO timekeepers (user_id, station_id, password) 
                VALUES (?, ?, ?)
            ");
            
            if ($stmt->execute([$userId, $stationId, $hashedPassword])) {
                logAction('TIMEKEEPER_CREATE', "Created timekeeper for user ID: $userId");
                
                // Send email with credentials to the timekeeper
                require_once '../includes/mailer.php';
                $mailer = new Mailer();
                
                $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                $fullName = trim($fullName);
                $loginUrl = SITE_URL . "/station_login.php";
                
                $emailSent = $mailer->sendTimekeeperAccountCreationEmail(
                    $user['email'],
                    $fullName,
                    $user['employee_id'],
                    $password,
                    $station['name'],
                    $station['department_name'] ?? 'N/A',
                    $loginUrl
                );
                
                if ($emailSent) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Timekeeper added successfully! Login credentials have been sent to ' . htmlspecialchars($user['email']) . '.'
                    ]);
                } else {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Timekeeper added successfully! However, the email could not be sent to ' . htmlspecialchars($user['email']) . '. Please provide the credentials manually.'
                    ]);
                }
            } else {
                throw new Exception('Failed to add timekeeper.');
            }
            break;
            
        case 'update':
            $timekeeperId = (int)$_POST['timekeeper_id'];
            $userId = (int)$_POST['employee_id'];
            $stationId = (int)$_POST['station_id'];
            $password = $_POST['password'] ?? '';
            $repeatPassword = $_POST['repeat_password'] ?? '';
            
            // Validate inputs
            if ($timekeeperId <= 0) {
                throw new Exception('Invalid timekeeper ID.');
            }
            
            if ($userId <= 0) {
                throw new Exception('Employee is required.');
            }
            
            if ($stationId <= 0) {
                throw new Exception('Station is required.');
            }
            
            // Check if timekeeper exists
            $stmt = $db->prepare("SELECT user_id FROM timekeepers WHERE id = ?");
            $stmt->execute([$timekeeperId]);
            $existingTimekeeper = $stmt->fetch();
            
            if (!$existingTimekeeper) {
                throw new Exception('Timekeeper not found.');
            }
            
            // Check if user exists and is active
            $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1 AND user_type IN ('faculty', 'staff')");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user) {
                throw new Exception('Selected employee not found or is not active.');
            }
            
            // Check if another timekeeper already uses this user (if user changed)
            if ($existingTimekeeper['user_id'] != $userId) {
                $stmt = $db->prepare("SELECT id FROM timekeepers WHERE user_id = ? AND id != ?");
                $stmt->execute([$userId, $timekeeperId]);
                if ($stmt->fetch()) {
                    throw new Exception('This employee already has a timekeeper account.');
                }
            }
            
            // Check if station exists
            $stmt = $db->prepare("SELECT id FROM stations WHERE id = ?");
            $stmt->execute([$stationId]);
            if (!$stmt->fetch()) {
                throw new Exception('Selected station does not exist.');
            }
            
            // Update timekeeper
            if (!empty($password)) {
                if ($password !== $repeatPassword) {
                    throw new Exception('Passwords do not match.');
                }
                
                if (strlen($password) < 6) {
                    throw new Exception('Password must be at least 6 characters long.');
                }
                
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("
                    UPDATE timekeepers 
                    SET user_id = ?, station_id = ?, password = ? 
                    WHERE id = ?
                ");
                
                if ($stmt->execute([$userId, $stationId, $hashedPassword, $timekeeperId])) {
                    logAction('TIMEKEEPER_UPDATE', "Updated timekeeper ID: $timekeeperId");
                    echo json_encode([
                        'success' => true,
                        'message' => 'Timekeeper updated successfully!'
                    ]);
                } else {
                    throw new Exception('Failed to update timekeeper.');
                }
            } else {
                // Update without password
                $stmt = $db->prepare("
                    UPDATE timekeepers 
                    SET user_id = ?, station_id = ? 
                    WHERE id = ?
                ");
                
                if ($stmt->execute([$userId, $stationId, $timekeeperId])) {
                    logAction('TIMEKEEPER_UPDATE', "Updated timekeeper ID: $timekeeperId");
                    echo json_encode([
                        'success' => true,
                        'message' => 'Timekeeper updated successfully!'
                    ]);
                } else {
                    throw new Exception('Failed to update timekeeper.');
                }
            }
            break;
            
        case 'delete':
            $timekeeperId = (int)$_POST['timekeeper_id'];
            
            // Check if timekeeper exists
            $stmt = $db->prepare("SELECT tk.id, u.first_name, u.last_name FROM timekeepers tk LEFT JOIN users u ON tk.user_id = u.id WHERE tk.id = ?");
            $stmt->execute([$timekeeperId]);
            $timekeeper = $stmt->fetch();
            
            if (!$timekeeper) {
                throw new Exception('Timekeeper not found.');
            }
            
            // Delete timekeeper
            $stmt = $db->prepare("DELETE FROM timekeepers WHERE id = ?");
            
            if ($stmt->execute([$timekeeperId])) {
                $name = $timekeeper['first_name'] . ' ' . $timekeeper['last_name'];
                logAction('TIMEKEEPER_DELETE', "Deleted timekeeper: $name");
                echo json_encode([
                    'success' => true,
                    'message' => 'Timekeeper deleted successfully!'
                ]);
            } else {
                throw new Exception('Failed to delete timekeeper.');
            }
            break;
            
        default:
            throw new Exception('Invalid action.');
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

