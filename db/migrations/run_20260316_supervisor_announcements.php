<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // 1. Create supervisor_announcements table
    $db->exec("CREATE TABLE IF NOT EXISTS supervisor_announcements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supervisor_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
        is_active TINYINT(1) DEFAULT 1,
        expires_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_supervisor (supervisor_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Created supervisor_announcements table.\n";
    
    // 1b. Add or alter target_audience (Faculty/Staff + employment status: faculty|CONTRACT OF SERVICE, staff|PERMANENT, etc.)
    $stmt = $db->query("SHOW COLUMNS FROM supervisor_announcements LIKE 'target_audience'");
    if ($stmt->rowCount() === 0) {
        $db->exec("ALTER TABLE supervisor_announcements ADD COLUMN target_audience VARCHAR(255) NOT NULL DEFAULT 'all' AFTER priority");
        echo "Added target_audience to supervisor_announcements.\n";
    } else {
        $col = $stmt->fetch(PDO::FETCH_ASSOC);
        if (stripos($col['Type'] ?? '', 'enum') !== false) {
            $db->exec("ALTER TABLE supervisor_announcements MODIFY COLUMN target_audience VARCHAR(255) NOT NULL DEFAULT 'all'");
            echo "Altered target_audience to VARCHAR for Faculty/Staff + employment status.\n";
        } else {
            echo "target_audience already has correct type.\n";
        }
    }
    
    // 2. Add scope_supervisor_id to calendar_events if not exists
    $stmt = $db->query("SHOW COLUMNS FROM calendar_events LIKE 'scope_supervisor_id'");
    if ($stmt->rowCount() === 0) {
        $db->exec("ALTER TABLE calendar_events ADD COLUMN scope_supervisor_id INT NULL AFTER created_by");
        echo "Added scope_supervisor_id to calendar_events.\n";
    } else {
        echo "scope_supervisor_id already exists in calendar_events.\n";
    }
    
    // 3. Add index if not exists
    $stmt = $db->query("SHOW INDEXES FROM calendar_events WHERE Key_name = 'idx_scope_supervisor'");
    if ($stmt->rowCount() === 0) {
        $db->exec("ALTER TABLE calendar_events ADD INDEX idx_scope_supervisor (scope_supervisor_id)");
        echo "Added idx_scope_supervisor index.\n";
    }
    
    echo "\nMigration completed successfully!\n";
    
} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
