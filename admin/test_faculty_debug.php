<?php
// Enable all error reporting FIRST
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../storage/logs/php_errors.log');

// Disable output buffering to see errors immediately
if (ob_get_level()) {
    ob_end_clean();
}

echo "<h1>Faculty.php Diagnostic Test</h1>";
echo "<pre>";

echo "Step 1: Testing config.php...\n";
try {
    require_once '../includes/config.php';
    echo "✓ config.php loaded successfully\n";
} catch (Throwable $e) {
    die("✗ config.php FAILED: " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\n");
}

echo "Step 2: Testing functions.php...\n";
try {
    require_once '../includes/functions.php';
    echo "✓ functions.php loaded successfully\n";
} catch (Throwable $e) {
    die("✗ functions.php FAILED: " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\n");
}

echo "Step 3: Testing database.php...\n";
try {
    require_once '../includes/database.php';
    echo "✓ database.php loaded successfully\n";
} catch (Throwable $e) {
    die("✗ database.php FAILED: " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\n");
}

echo "Step 4: Testing requireAdmin()...\n";
try {
    // Start session if needed
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    echo "Session ID: " . session_id() . "\n";
    echo "User ID: " . ($_SESSION['user_id'] ?? 'NOT SET') . "\n";
    echo "User Type: " . ($_SESSION['user_type'] ?? 'NOT SET') . "\n";
    
    // Try requireAdmin
    requireAdmin();
    echo "✓ requireAdmin() passed\n";
} catch (Throwable $e) {
    echo "⚠ requireAdmin() failed (expected if not logged in): " . $e->getMessage() . "\n";
}

echo "Step 5: Testing Database connection...\n";
try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    echo "✓ Database connection successful\n";
} catch (Throwable $e) {
    die("✗ Database connection FAILED: " . $e->getMessage() . "\n");
}

echo "Step 6: Testing departments query...\n";
try {
    $deptStmt = $db->prepare("SELECT id, name FROM departments ORDER BY name");
    $deptStmt->execute();
    $departments = $deptStmt->fetchAll();
    echo "✓ Departments query successful (found " . count($departments) . " departments)\n";
} catch (Throwable $e) {
    echo "⚠ Departments query failed: " . $e->getMessage() . "\n";
}

echo "Step 7: Testing faculty query...\n";
try {
    $whereClause = "u.user_type IN ('faculty','staff')";
    $params = [];
    
    $sql = "SELECT u.*, fp.employee_id, fp.department, fp.position, fp.employment_status, fp.hire_date,
            ps.salary_grade, ps.annual_salary
            FROM users u
            LEFT JOIN faculty_profiles fp ON u.id = fp.user_id
            LEFT JOIN (
                SELECT ps1.position_title, ps1.salary_grade, ps1.annual_salary
                FROM position_salary ps1
                INNER JOIN (
                    SELECT position_title, MIN(id) as min_id
                    FROM position_salary
                    GROUP BY position_title
                ) ps2 ON ps1.position_title = ps2.position_title AND ps1.id = ps2.min_id
            ) ps ON fp.position = ps.position_title
            WHERE $whereClause
            GROUP BY u.id
            ORDER BY u.created_at DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $faculty = $stmt->fetchAll();
    echo "✓ Faculty query successful (found " . count($faculty) . " faculty/staff)\n";
} catch (Throwable $e) {
    die("✗ Faculty query FAILED: " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\n");
}

echo "Step 8: Testing admin_layout_helper.php...\n";
try {
    require_once '../includes/admin_layout_helper.php';
    echo "✓ admin_layout_helper.php loaded successfully\n";
} catch (Throwable $e) {
    die("✗ admin_layout_helper.php FAILED: " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\n");
}

echo "Step 9: Testing navigation.php...\n";
try {
    require_once '../includes/navigation.php';
    echo "✓ navigation.php loaded successfully\n";
} catch (Throwable $e) {
    die("✗ navigation.php FAILED: " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine() . "\n");
}

echo "\n";
echo "========================================\n";
echo "ALL TESTS PASSED!\n";
echo "========================================\n";
echo "\n";
echo "If faculty.php still shows HTTP 500, the issue might be:\n";
echo "1. Output buffering issues\n";
echo "2. Headers already sent\n";
echo "3. Memory limit exceeded\n";
echo "4. A fatal error in the HTML/JavaScript section\n";
echo "\n";
echo "Check the PHP error log at: storage/logs/php_errors.log\n";

echo "</pre>";
