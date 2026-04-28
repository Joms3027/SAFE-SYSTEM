<?php
/**
 * Diagnostic Tool: Check employment_status Column Type
 * This checks if the migration to change employment_status from ENUM to VARCHAR has been run
 */

require_once '../includes/config.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';

// Only allow admin access (includes super_admin)
if (!isset($_SESSION['user_id']) || !isAdmin()) {
    die("Access denied. Admin only.");
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    echo "<h2>Employment Status Column Diagnostic</h2>";
    echo "<hr>";
    
    // Check current column type
    echo "<h3>1. Current Column Type:</h3>";
    $checkStmt = $db->query("SHOW COLUMNS FROM faculty_profiles WHERE Field = 'employment_status'");
    $columnInfo = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($columnInfo) {
        echo "<pre>";
        print_r($columnInfo);
        echo "</pre>";
        
        $isEnum = stripos($columnInfo['Type'], 'enum') !== false;
        $isVarchar = stripos($columnInfo['Type'], 'varchar') !== false;
        
        if ($isEnum) {
            echo "<p style='color: red;'><strong>❌ PROBLEM FOUND:</strong> Column is still ENUM type!</p>";
            echo "<p>This is why employment status values are not being saved. The ENUM only accepts specific values.</p>";
        } elseif ($isVarchar) {
            echo "<p style='color: green;'><strong>✓ GOOD:</strong> Column is VARCHAR type.</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ Column not found!</p>";
    }
    
    // Check employment statuses in master table
    echo "<h3>2. Values in employment_statuses Master Table:</h3>";
    $empStmt = $db->query("SELECT id, name FROM employment_statuses ORDER BY name");
    $statuses = $empStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<ul>";
    foreach ($statuses as $status) {
        echo "<li><strong>{$status['name']}</strong> (ID: {$status['id']})</li>";
    }
    echo "</ul>";
    
    // Check sample faculty profiles
    echo "<h3>3. Sample Faculty Profiles (employment_status values):</h3>";
    $profileStmt = $db->query("SELECT user_id, employee_id, employment_status FROM faculty_profiles LIMIT 5");
    $profiles = $profileStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>User ID</th><th>Safe Employee ID</th><th>Employment Status</th></tr>";
    foreach ($profiles as $profile) {
        $status = empty($profile['employment_status']) ? "<em style='color: red;'>(empty)</em>" : $profile['employment_status'];
        echo "<tr><td>{$profile['user_id']}</td><td>{$profile['employee_id']}</td><td>{$status}</td></tr>";
    }
    echo "</table>";
    
    // Provide solution
    if ($isEnum) {
        echo "<hr>";
        echo "<h3>🔧 SOLUTION:</h3>";
        echo "<p><strong>You need to run the migration to fix this issue.</strong></p>";
        echo "<p>Option 1: <a href='../db/migrations/run_20251119_employment_status_migration.php' target='_blank'>";
        echo "<button style='padding: 10px 20px; background: #007bff; color: white; border: none; cursor: pointer; font-size: 16px;'>Click Here to Run Migration</button>";
        echo "</a></p>";
        echo "<p>Option 2: Manually run the SQL command in phpMyAdmin:</p>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>ALTER TABLE `faculty_profiles` MODIFY `employment_status` VARCHAR(150) DEFAULT NULL;</pre>";
    }
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>Database Error: " . $e->getMessage() . "</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>
