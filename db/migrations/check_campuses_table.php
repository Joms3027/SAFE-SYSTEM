<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    echo "Checking campuses table...\n\n";
    
    // Check table structure
    $stmt = $db->query("SHOW COLUMNS FROM campuses");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Columns for campuses table:\n";
    foreach ($cols as $c) {
        echo "- {$c['Field']} ({$c['Type']})\n";
    }
    
    echo "\n";
    
    // Check data
    $stmt = $db->query("SELECT * FROM campuses ORDER BY name");
    $campuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Campuses in database (" . count($campuses) . " total):\n";
    foreach ($campuses as $campus) {
        echo "- ID: {$campus['id']}, Name: {$campus['name']}\n";
    }
    
    echo "\nCampuses table verified successfully!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>

