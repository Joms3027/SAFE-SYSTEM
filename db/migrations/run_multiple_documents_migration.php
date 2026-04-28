<?php
/**
 * Migration Script: Change Supporting Document to Multiple Documents
 * Run this script to update the pardon_requests table to support multiple documents
 */

require_once '../../includes/config.php';
require_once '../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    echo "Running multiple documents migration for pardon requests...\n";
    
    // Check if column exists and what type it is
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
        // Rename column and change type to TEXT
        $db->exec("ALTER TABLE pardon_requests CHANGE COLUMN supporting_document supporting_documents TEXT NULL");
        echo "✅ Successfully renamed supporting_document to supporting_documents and changed to TEXT.\n";
    } else if ($hasNewColumn) {
        // Check if it's already TEXT
        $stmt = $db->query("SHOW COLUMNS FROM pardon_requests WHERE Field = 'supporting_documents'");
        $colInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($colInfo && strpos($colInfo['Type'], 'varchar') !== false) {
            // Change VARCHAR to TEXT
            $db->exec("ALTER TABLE pardon_requests MODIFY COLUMN supporting_documents TEXT NULL");
            echo "✅ Successfully changed supporting_documents column to TEXT.\n";
        } else {
            echo "✅ Column supporting_documents already exists as TEXT. Skipping.\n";
        }
    } else {
        // Add new column
        $db->exec("ALTER TABLE pardon_requests ADD COLUMN supporting_documents TEXT NULL AFTER reason");
        echo "✅ Successfully added supporting_documents column.\n";
    }
    
    // Migrate existing single document data to JSON array format
    $stmt = $db->query("SELECT id, supporting_documents FROM pardon_requests WHERE supporting_documents IS NOT NULL AND supporting_documents != ''");
    $existingRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $migrated = 0;
    foreach ($existingRecords as $record) {
        $documents = $record['supporting_documents'];
        
        // Check if it's already JSON array
        $decoded = json_decode($documents, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            continue; // Already in correct format
        }
        
        // Convert single document string to JSON array
        $jsonArray = json_encode([$documents]);
        $updateStmt = $db->prepare("UPDATE pardon_requests SET supporting_documents = ? WHERE id = ?");
        $updateStmt->execute([$jsonArray, $record['id']]);
        $migrated++;
    }
    
    if ($migrated > 0) {
        echo "✅ Migrated $migrated existing records to JSON array format.\n";
    } else {
        echo "✅ No existing records needed migration.\n";
    }
    
    echo "\nMigration completed successfully!\n";
    echo "Next steps:\n";
    echo "1. Faculty can now upload multiple supporting documents when submitting pardon requests\n";
    echo "2. All documents are stored as JSON array in the supporting_documents field\n";
    echo "3. Admins can view and download all supporting documents from the Pardon Requests page\n";
    
} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
?>

