<?php
/**
 * Migration Script: Create Pardon Requests Table
 * Run this script to create the pardon_requests table
 */

require_once '../../includes/config.php';
require_once '../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    echo "Running pardon requests migration...\n";
    
    // Read migration SQL file
    $sqlFile = __DIR__ . '/20250101_create_pardon_requests_table.sql';
    if (!file_exists($sqlFile)) {
        die("Migration file not found: $sqlFile\n");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Check if table already exists
    $stmt = $db->query("SHOW TABLES LIKE 'pardon_requests'");
    $tableExists = $stmt->rowCount() > 0;
    
    if (!$tableExists) {
        // Execute migration
        $db->exec($sql);
        echo "✅ Successfully created pardon_requests table.\n";
    } else {
        echo "✅ Table pardon_requests already exists. Skipping.\n";
    }
    
    echo "\nMigration completed successfully!\n";
    echo "Next steps:\n";
    echo "1. Faculty can now request pardons for their attendance logs\n";
    echo "2. Admins can review and approve/reject pardon requests from the Pardon Requests page\n";
    echo "3. When approved, the attendance log will be updated with the requested times\n";
    
} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
?>

