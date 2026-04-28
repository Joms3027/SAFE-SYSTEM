<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Read and execute the migration SQL file
    $sql = file_get_contents(__DIR__ . '/20251029_update_pds_tables.sql');
    
    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', 
            explode(';', $sql)
        )
    );
    
    // Execute each statement
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $db->exec($statement);
            echo "Executed: " . substr($statement, 0, 50) . "...\n";
        }
    }
    
    echo "\nMigration completed successfully!\n";
    
} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
?>