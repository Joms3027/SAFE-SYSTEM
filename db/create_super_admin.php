<?php
/**
 * One-time script to create the initial super admin account.
 * 
 * IMPORTANT: Run the migration first: db/migrations/20260306_add_super_admin_user_type.sql
 * 
 * Usage: 
 *   php create_super_admin.php
 *   php create_super_admin.php email@wpu.edu.ph "YourPassword" "First" "Last"
 *   SUPER_ADMIN_EMAIL=admin@wpu.edu.ph SUPER_ADMIN_PASSWORD=YourPass php create_super_admin.php
 */

// Prevent accidental web execution - require CLI or explicit allow
$isCli = php_sapi_name() === 'cli';
if (!$isCli && !getenv('ALLOW_WEB_CREATE_SUPER_ADMIN')) {
    die('This script should be run from command line: php create_super_admin.php');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$database = Database::getInstance();
$db = $database->getConnection();

// Check if super_admin exists in user_type enum
$stmt = $db->query("SHOW COLUMNS FROM users LIKE 'user_type'");
$col = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$col || strpos($col['Type'], 'super_admin') === false) {
    die("ERROR: Run the migration first: db/migrations/20260306_add_super_admin_user_type.sql\n");
}

// Get credentials: argv, env, or prompt
$email = $argv[1] ?? getenv('SUPER_ADMIN_EMAIL') ?: '';
$password = $argv[2] ?? getenv('SUPER_ADMIN_PASSWORD') ?: '';
$firstName = $argv[3] ?? getenv('SUPER_ADMIN_FIRST_NAME') ?: 'Super';
$lastName = $argv[4] ?? getenv('SUPER_ADMIN_LAST_NAME') ?: 'Admin';

if (empty($email) && $isCli && function_exists('readline')) {
    $email = readline('Super Admin Email (e.g. admin@wpu.edu.ph): ');
}
if (empty($email)) {
    die("ERROR: Email is required. Usage: php create_super_admin.php email@wpu.edu.ph \"password\" \"First\" \"Last\"\n");
}

// Validate WPU email
if (!preg_match('/@wpu\.edu\.ph$/i', trim($email))) {
    die("ERROR: Only WPU email addresses (@wpu.edu.ph) are allowed.\n");
}

if (empty($password) && $isCli && function_exists('readline')) {
    $password = readline('Password (min 8 chars): ');
}
if (empty($password) || strlen($password) < 8) {
    die("ERROR: Password must be at least 8 characters.\n");
}

$email = trim(strtolower($email));
if (empty($firstName)) $firstName = 'Super';
if (empty($lastName)) $lastName = 'Admin';

// Check if super admin already exists
$stmt = $db->prepare("SELECT id FROM users WHERE user_type = 'super_admin' LIMIT 1");
$stmt->execute();
if ($stmt->fetch()) {
    die("A super admin account already exists. Use Settings to manage admin accounts.\n");
}

// Check if email already used
$stmt = $db->prepare("SELECT id FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    die("ERROR: This email is already registered. Use a different email.\n");
}

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$stmt = $db->prepare("INSERT INTO users (email, password, user_type, first_name, last_name, is_verified, is_active) VALUES (?, ?, 'super_admin', ?, ?, 1, 1)");

if ($stmt->execute([$email, $hashedPassword, $firstName, $lastName])) {
    $id = $db->lastInsertId();
    echo "SUCCESS: Super admin account created!\n";
    echo "  ID: $id\n";
    echo "  Email: $email\n";
    echo "  Name: $firstName $lastName\n";
    echo "\nYou can now log in with this account. Change the password after first login.\n";
} else {
    die("ERROR: Failed to create super admin account.\n");
}
