<?php
// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "Starting diagnostic test...<br><br>";

// Test 1: config.php
echo "1. Testing config.php... ";
try {
    require_once '../includes/config.php';
    echo "<span style='color: green;'>SUCCESS</span><br>";
} catch (Exception $e) {
    die("<span style='color: red;'>FAILED: " . htmlspecialchars($e->getMessage()) . "</span>");
} catch (Error $e) {
    die("<span style='color: red;'>FATAL ERROR: " . htmlspecialchars($e->getMessage()) . " in " . $e->getFile() . " on line " . $e->getLine() . "</span>");
}

// Test 2: functions.php
echo "2. Testing functions.php... ";
try {
    require_once '../includes/functions.php';
    echo "<span style='color: green;'>SUCCESS</span><br>";
} catch (Exception $e) {
    die("<span style='color: red;'>FAILED: " . htmlspecialchars($e->getMessage()) . "</span>");
} catch (Error $e) {
    die("<span style='color: red;'>FATAL ERROR: " . htmlspecialchars($e->getMessage()) . " in " . $e->getFile() . " on line " . $e->getLine() . "</span>");
}

// Test 3: database.php
echo "3. Testing database.php... ";
try {
    require_once '../includes/database.php';
    echo "<span style='color: green;'>SUCCESS</span><br>";
} catch (Exception $e) {
    die("<span style='color: red;'>FAILED: " . htmlspecialchars($e->getMessage()) . "</span>");
} catch (Error $e) {
    die("<span style='color: red;'>FATAL ERROR: " . htmlspecialchars($e->getMessage()) . " in " . $e->getFile() . " on line " . $e->getLine() . "</span>");
}

// Test 4: Database connection
echo "4. Testing database connection... ";
try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    echo "<span style='color: green;'>SUCCESS</span><br>";
} catch (Exception $e) {
    die("<span style='color: red;'>FAILED: " . htmlspecialchars($e->getMessage()) . "</span>");
} catch (Error $e) {
    die("<span style='color: red;'>FATAL ERROR: " . htmlspecialchars($e->getMessage()) . " in " . $e->getFile() . " on line " . $e->getLine() . "</span>");
}

// Test 5: admin_layout_helper.php
echo "5. Testing admin_layout_helper.php... ";
try {
    require_once '../includes/admin_layout_helper.php';
    echo "<span style='color: green;'>SUCCESS</span><br>";
} catch (Exception $e) {
    die("<span style='color: red;'>FAILED: " . htmlspecialchars($e->getMessage()) . "</span>");
} catch (Error $e) {
    die("<span style='color: red;'>FATAL ERROR: " . htmlspecialchars($e->getMessage()) . " in " . $e->getFile() . " on line " . $e->getLine() . "</span>");
}

// Test 6: navigation.php
echo "6. Testing navigation.php... ";
try {
    require_once '../includes/navigation.php';
    echo "<span style='color: green;'>SUCCESS</span><br>";
} catch (Exception $e) {
    die("<span style='color: red;'>FAILED: " . htmlspecialchars($e->getMessage()) . "</span>");
} catch (Error $e) {
    die("<span style='color: red;'>FATAL ERROR: " . htmlspecialchars($e->getMessage()) . " in " . $e->getFile() . " on line " . $e->getLine() . "</span>");
}

echo "<br><strong style='color: green;'>All tests passed! The issue might be with authentication or specific page logic.</strong><br>";
echo "<br>Now testing requireAdmin()...<br>";

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "Session status: " . (isset($_SESSION['user_id']) ? "Logged in as user " . $_SESSION['user_id'] : "Not logged in") . "<br>";
echo "User type: " . ($_SESSION['user_type'] ?? 'Not set') . "<br>";

?>
