<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    $sql = file_get_contents(__DIR__ . '/20260317_create_email_queue.sql');
    $db->exec($sql);
    echo "Created email_queue table.\n";
} catch (Exception $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
