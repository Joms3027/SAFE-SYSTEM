<?php
/**
 * Migration Script: Preserve Employee Information in Pardon Requests
 * This migration adds employee name and department columns to pardon_requests table
 * and populates them with existing data to preserve history even after account deletion
 */

require_once '../../includes/config.php';
require_once '../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    echo "Starting pardon requests employee info preservation migration...\n\n";
    
    // Step 1: Add new columns if they don't exist
    echo "Step 1: Adding employee information columns...\n";
    $stmt = $db->query("SHOW COLUMNS FROM pardon_requests WHERE Field IN ('employee_first_name', 'employee_last_name', 'employee_department')");
    $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($existingColumns) < 3) {
        // Add columns one by one to avoid IF NOT EXISTS issues
        $columnsToAdd = [
            'employee_first_name' => "ALTER TABLE pardon_requests ADD COLUMN employee_first_name VARCHAR(100) NULL AFTER employee_id",
            'employee_last_name' => "ALTER TABLE pardon_requests ADD COLUMN employee_last_name VARCHAR(100) NULL AFTER employee_first_name",
            'employee_department' => "ALTER TABLE pardon_requests ADD COLUMN employee_department VARCHAR(100) NULL AFTER employee_last_name"
        ];
        
        foreach ($columnsToAdd as $columnName => $sql) {
            if (!in_array($columnName, $existingColumns)) {
                try {
                    $db->exec($sql);
                    echo "✅ Added column: $columnName\n";
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'Duplicate column') === false) {
                        throw $e;
                    }
                    echo "✅ Column $columnName already exists.\n";
                }
            } else {
                echo "✅ Column $columnName already exists.\n";
            }
        }
        echo "✅ Employee information columns added successfully.\n";
    } else {
        echo "✅ Employee information columns already exist.\n";
    }
    
    // Step 2: Populate existing records with employee information
    echo "\nStep 2: Populating existing pardon requests with employee information...\n";
    
    $updateSql = "
        UPDATE pardon_requests pr
        LEFT JOIN faculty_profiles fp ON pr.employee_id = fp.employee_id
        LEFT JOIN users u ON fp.user_id = u.id
        SET 
            pr.employee_first_name = u.first_name,
            pr.employee_last_name = u.last_name,
            pr.employee_department = fp.department
        WHERE (pr.employee_first_name IS NULL OR pr.employee_last_name IS NULL)
            AND u.first_name IS NOT NULL
    ";
    
    $stmt = $db->prepare($updateSql);
    $stmt->execute();
    $updatedRows = $stmt->rowCount();
    
    echo "✅ Updated $updatedRows pardon request(s) with employee information.\n";
    
    // Step 3: Create index for searching
    echo "\nStep 3: Creating indexes...\n";
    
    // Check if index exists
    $stmt = $db->query("SHOW INDEX FROM pardon_requests WHERE Key_name = 'idx_employee_name'");
    if ($stmt->rowCount() == 0) {
        $db->exec("CREATE INDEX idx_employee_name ON pardon_requests(employee_first_name, employee_last_name)");
        echo "✅ Search index created successfully.\n";
    } else {
        echo "✅ Search index already exists.\n";
    }
    
    // Step 4: Report on any orphaned records
    echo "\nStep 4: Checking for orphaned records...\n";
    $stmt = $db->query("
        SELECT COUNT(*) as count 
        FROM pardon_requests 
        WHERE employee_first_name IS NULL OR employee_last_name IS NULL
    ");
    $orphanedCount = $stmt->fetch(PDO::FETCH_COLUMN);
    
    if ($orphanedCount > 0) {
        echo "⚠️  Warning: Found $orphanedCount pardon request(s) without employee information.\n";
        echo "   These may be from deleted accounts. They will display as 'Unknown Employee'.\n";
    } else {
        echo "✅ All pardon requests have employee information.\n";
    }
    
    echo "\n✅ Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
