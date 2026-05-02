<?php
declare(strict_types=1);
/**
 * Apply all TARF and NTARF database migrations in order.
 * Both flows use `tarf_requests` (travel vs non-travel distinguished by JSON form_kind).
 *
 * Usage (from repo root): php db/migrations/run_tarf_ntarf_migrations.php
 */
$steps = [
    'run_tarf_requests_migration.php',
    'run_add_tarf_workflow_columns.php',
    'run_add_tarf_president_columns.php',
    'run_tarf_parallel_joint_endorsements.php',
];

$php = PHP_BINARY !== '' ? PHP_BINARY : 'php';

foreach ($steps as $name) {
    $path = __DIR__ . DIRECTORY_SEPARATOR . $name;
    if (!is_file($path)) {
        fwrite(STDERR, "Missing migration script: {$path}\n");
        exit(1);
    }
    echo "\n===== {$name} =====\n";
    passthru(escapeshellarg($php) . ' ' . escapeshellarg($path), $code);
    if ($code !== 0) {
        fwrite(STDERR, "Stopped: {$name} exited with {$code}\n");
        exit($code);
    }
}

echo "\nAll TARF/NTARF migration steps finished successfully.\n";

echo "\n===== verify_tarf_ntarf_schema.php =====\n";
passthru(
    escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'verify_tarf_ntarf_schema.php'),
    $verifyCode
);
if ($verifyCode !== 0) {
    fwrite(STDERR, "Schema verification failed (exit {$verifyCode}).\n");
    exit($verifyCode);
}
