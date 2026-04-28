<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

try {
    $db = (new Database())->getConnection();
    $stmt = $db->query("SHOW COLUMNS FROM faculty_civil_service_eligibility");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Columns for faculty_civil_service_eligibility:\n";
    foreach ($cols as $c) {
        echo "- {$c['Field']} ({$c['Type']})" . PHP_EOL;
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}
