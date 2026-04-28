<?php
/**
 * Migration Script: Add Supporting Document Column to Pardon Requests
 * Run this script to add the supporting_document column and make reason required
 */

require_once '../../includes/config.php';
require_once '../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    echo "Running supporting document migration for pardon requests...\n";
    
    // Check if column already exists (check both old and new names)
    $stmt = $db->query("SHOW COLUMNS FROM pardon_requests WHERE Field = 'supporting_document' OR Field = 'supporting_documents'");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $hasOldColumn = false;
    $hasNewColumn = false;
    
    foreach ($columns as $col) {
        if ($col['Field'] === 'supporting_document') {
            $hasOldColumn = true;
        }
        if ($col['Field'] === 'supporting_documents') {
            $hasNewColumn = true;
        }
    }
    
    if ($hasOldColumn && !$hasNewColumn) {
        // Rename old column to new name and change to TEXT
        $db->exec("ALTER TABLE pardon_requests CHANGE COLUMN supporting_document supporting_documents TEXT NULL");
        echo "✅ Successfully renamed supporting_document to supporting_documents and changed to TEXT.\n";
    } else if (!$hasNewColumn) {
        // Add supporting_documents column as TEXT
        $db->exec("ALTER TABLE pardon_requests ADD COLUMN supporting_documents TEXT NULL AFTER reason");
        echo "✅ Successfully added supporting_documents column.\n";
    } else {
        echo "✅ Column supporting_documents already exists. Skipping.\n";
    }
    
    // Make reason required (check current column definition)
    $stmt = $db->query("SHOW COLUMNS FROM pardon_requests WHERE Field = 'reason'");
    $columnInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($columnInfo && strpos($columnInfo['Null'], 'YES') !== false) {
        // Column allows NULL, make it NOT NULL
        $db->exec("ALTER TABLE pardon_requests MODIFY COLUMN reason TEXT NOT NULL");
        echo "✅ Made reason field required.\n";
    } else {
        echo "✅ Reason field is already required. Skipping.\n";
    }
    
    // Create upload directory if it doesn't exist
    $uploadDir = __DIR__ . '/../../uploads/pardon_requests/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
        echo "✅ Created uploads/pardon_requests directory.\n";
    } else {
        echo "✅ uploads/pardon_requests directory already exists.\n";
    }
    
    echo "\nMigration completed successfully!\n";
    echo "Next steps:\n";
    echo "1. Faculty can now upload multiple supporting documents when submitting pardon requests\n";
    echo "2. Justification is now required for all pardon requests\n";
    echo "3. Admins can view and download all supporting documents from the Pardon Requests page\n";
    
} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
?>

