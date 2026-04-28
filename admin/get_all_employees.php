<?php
/**
 * Get All Employees for Resend Credentials Modal
 * 
 * Returns all employees (faculty and staff) from the database
 * for use in the resend credentials modal search functionality.
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

// Set JSON header
header('Content-Type: application/json');

// Check if user is admin
try {
    requireAdmin();
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Access denied. Admin privileges required.',
        'employees' => []
    ]);
    exit;
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // Get all employees (faculty and staff) with email addresses
    $sql = "SELECT u.id, u.email, u.first_name, u.last_name, u.user_type, 
                   fp.employee_id
            FROM users u
            LEFT JOIN faculty_profiles fp ON u.id = fp.user_id
            WHERE u.user_type IN ('faculty','staff')
            AND u.email IS NOT NULL AND u.email != ''
            ORDER BY u.last_name ASC, u.first_name ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format employees for frontend
    $formattedEmployees = [];
    foreach ($employees as $employee) {
        $formattedEmployees[] = [
            'id' => $employee['id'],
            'email' => $employee['email'],
            'first_name' => $employee['first_name'],
            'last_name' => $employee['last_name'],
            'full_name' => trim($employee['first_name'] . ' ' . $employee['last_name']),
            'user_type' => $employee['user_type'],
            'employee_id' => $employee['employee_id'] ?: 'N/A'
        ];
    }
    
    echo json_encode([
        'success' => true,
        'employees' => $formattedEmployees,
        'count' => count($formattedEmployees)
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_all_employees.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while fetching employees: ' . $e->getMessage(),
        'employees' => []
    ]);
}
