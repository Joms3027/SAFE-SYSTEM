<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

header('Content-Type: application/json');

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // Get all active employees (faculty and staff) with their employee IDs and positions
    // Use the same logic as employee_logs.php to get the latest position
    $stmt = $db->prepare("
        SELECT 
            u.id,
            u.first_name,
            u.last_name,
            u.user_type,
            COALESCE(fp.employee_id, CONCAT('USER_', u.id)) AS employee_id,
            CONCAT(u.first_name, ' ', u.last_name) AS name,
            fp.position
        FROM users u
        LEFT JOIN (
            SELECT fp1.user_id, fp1.employee_id, fp1.position
            FROM faculty_profiles fp1
            INNER JOIN (
                SELECT user_id, MAX(id) as max_id
                FROM faculty_profiles
                GROUP BY user_id
            ) fp2 ON fp1.user_id = fp2.user_id AND fp1.id = fp2.max_id
        ) fp ON u.id = fp.user_id
        WHERE u.user_type IN ('faculty', 'staff') 
        AND u.is_active = 1 
        AND u.is_verified = 1
        AND fp.employee_id IS NOT NULL
        AND fp.employee_id != ''
        ORDER BY u.user_type, u.last_name, u.first_name
    ");
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'employees' => $employees]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>

