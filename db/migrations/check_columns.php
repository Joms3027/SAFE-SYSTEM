<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

$db = Database::getInstance()->getConnection();
$result = $db->query('SHOW COLUMNS FROM stations');
echo "Columns in stations table:\n";
foreach($result as $row) { 
    echo "  - " . $row['Field'] . "\n"; 
}
