<?php
/**
 * Parallel TARF/NTARF endorsement: supervisor + applicable endorser + fund (Budget/Accounting)
 * share status pending_joint until all required parties endorse.
 *
 * Run once alone, or use: php db/migrations/run_tarf_ntarf_migrations.php (runs all TARF/NTARF steps).
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/tarf_workflow.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();

    echo "TARF parallel joint endorsements migration...\n";

    $tbl = $db->query("SHOW TABLES LIKE 'tarf_requests'");
    if (!$tbl || $tbl->rowCount() === 0) {
        echo "Table tarf_requests missing. Run: php db/migrations/run_tarf_ntarf_migrations.php\n";
        exit(1);
    }

    $add = [
        'fund_availability_target_user_id' => 'INT(11) NULL DEFAULT NULL COMMENT \'Resolved from Budget vs Accounting choice\'',
        'fund_availability_endorsed_at' => 'DATETIME NULL DEFAULT NULL',
        'fund_availability_endorsed_by' => 'INT(11) NULL DEFAULT NULL',
        'fund_availability_comment' => 'TEXT NULL',
    ];

    foreach ($add as $col => $def) {
        $check = $db->query('SHOW COLUMNS FROM tarf_requests LIKE ' . $db->quote($col));
        if ($check->rowCount() === 0) {
            $db->exec("ALTER TABLE tarf_requests ADD COLUMN `$col` $def");
            echo "Added column $col\n";
        } else {
            echo "Column $col exists.\n";
        }
    }

    // Backfill fund_availability_target_user_id from form_data where applicable
    $sel = $db->query('SELECT id, form_data FROM tarf_requests WHERE fund_availability_target_user_id IS NULL');
    while ($r = $sel->fetch(PDO::FETCH_ASSOC)) {
        $row = ['form_data' => $r['form_data'], 'id' => $r['id']];
        if (!function_exists('tarf_request_requires_fund_availability_endorsement')) {
            require_once __DIR__ . '/../../includes/tarf_workflow.php';
        }
        if (!tarf_request_requires_fund_availability_endorsement(['form_data' => $r['form_data']])) {
            continue;
        }
        $fd = json_decode($r['form_data'] ?? '{}', true);
        $key = is_array($fd) ? trim((string) ($fd['endorser_fund_availability'] ?? '')) : '';
        if ($key === '') {
            continue;
        }
        $uid = tarf_resolve_fund_availability_endorser_user_id($key, $db);
        if ($uid !== null && $uid > 0) {
            $up = $db->prepare('UPDATE tarf_requests SET fund_availability_target_user_id = ? WHERE id = ?');
            $up->execute([$uid, (int) $r['id']]);
            echo 'Backfilled fund target for id ' . (int) $r['id'] . "\n";
        }
    }

    // Normalize workflow statuses to pending_joint
    $db->exec("UPDATE tarf_requests SET status = 'pending_joint' WHERE status IN ('pending_supervisor','pending_endorser')");
    echo "Normalized pending_supervisor / pending_endorser → pending_joint.\n";

    echo "Done.\n";
} catch (PDOException $e) {
    die('Migration failed: ' . $e->getMessage() . "\n");
}
