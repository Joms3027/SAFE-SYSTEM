<?php
declare(strict_types=1);
/**
 * Validates `tarf_requests` columns required by TARF + NTARF (shared table).
 *
 * CLI: php db/migrations/verify_tarf_ntarf_schema.php
 * Exit code 0 = OK, 1 = missing columns or table / DB error.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

$requiredColumns = [
    // Base (20260416_create_tarf_requests_table.sql)
    'id', 'user_id', 'employee_id', 'serial_year', 'form_data', 'attachments', 'status',
    'created_at', 'updated_at',
    // Workflow (run_add_tarf_workflow_columns.php)
    'endorser_target_user_id',
    'supervisor_endorsed_at', 'supervisor_endorsed_by', 'supervisor_comment',
    'endorser_endorsed_at', 'endorser_endorsed_by', 'endorser_comment',
    'rejected_at', 'rejected_by', 'rejection_reason', 'rejection_stage',
    // President (run_add_tarf_president_columns.php)
    'president_endorsed_at', 'president_endorsed_by', 'president_comment',
    // Parallel fund endorsement (run_tarf_parallel_joint_endorsements.php)
    'fund_availability_target_user_id',
    'fund_availability_endorsed_at', 'fund_availability_endorsed_by', 'fund_availability_comment',
];

try {
    $db = Database::getInstance()->getConnection();
    $tbl = $db->query("SHOW TABLES LIKE 'tarf_requests'");
    if (!$tbl || $tbl->rowCount() === 0) {
        fwrite(STDERR, "FAIL: tarf_requests table does not exist. Run php db/migrations/run_tarf_ntarf_migrations.php\n");
        exit(1);
    }

    $missing = [];
    foreach ($requiredColumns as $col) {
        if (!preg_match('/^[a-z0-9_]+$/i', $col)) {
            continue;
        }
        $chk = $db->query('SHOW COLUMNS FROM tarf_requests LIKE ' . $db->quote($col));
        if (!$chk || $chk->rowCount() === 0) {
            $missing[] = $col;
        }
    }

    if ($missing !== []) {
        fwrite(STDERR, 'FAIL: missing column(s): ' . implode(', ', $missing) . "\n");
        exit(1);
    }

    echo 'OK: tarf_requests has all ' . count($requiredColumns) . " columns required for TARF/NTARF.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
