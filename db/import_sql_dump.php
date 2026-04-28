<?php
/**
 * Import a mysqldump .sql file into MySQL/MariaDB via mysqli (works when mysql.exe auth plugin fails).
 * Usage: php import_sql_dump.php path/to/dump.sql [--fresh]
 *        --fresh  Drop and recreate DB before import (recommended if a prior import was partial).
 */
ini_set('memory_limit', '512M');
set_time_limit(0);

$extra = array_values(array_filter($argv, fn ($a) => $a !== '--fresh'));
$dump = $extra[1] ?? '';
if ($dump === '' || !is_readable($dump)) {
    fwrite(STDERR, "Usage: php import_sql_dump.php <path-to.sql> [--fresh]\n");
    exit(1);
}
$fresh = in_array('--fresh', $argv, true);

require dirname(__DIR__) . '/includes/config.php';

$host = DB_HOST === 'localhost' ? '127.0.0.1' : DB_HOST;
$mysqli = new mysqli($host, DB_USER, DB_PASS);
if ($mysqli->connect_error) {
    fwrite(STDERR, 'Connection failed: ' . $mysqli->connect_error . "\n");
    exit(1);
}

$mysqli->set_charset('utf8mb4');

$dbname = DB_NAME;
$dbident = '`' . str_replace('`', '``', $dbname) . '`';

if ($fresh) {
    echo "Dropping database if exists `" . $dbname . "`...\n";
    if (!$mysqli->query("DROP DATABASE IF EXISTS $dbident")) {
        fwrite(STDERR, 'DROP DATABASE failed: ' . $mysqli->error . "\n");
        exit(1);
    }
}

if (!$mysqli->query("CREATE DATABASE IF NOT EXISTS $dbident DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
    fwrite(STDERR, 'CREATE DATABASE failed: ' . $mysqli->error . "\n");
    exit(1);
}
if (!$mysqli->select_db($dbname)) {
    fwrite(STDERR, 'select_db failed: ' . $mysqli->error . "\n");
    exit(1);
}

$sql = file_get_contents($dump);
if ($sql === false) {
    fwrite(STDERR, "Could not read file.\n");
    exit(1);
}

// mysqldump uses DELIMITER ;; for triggers/routines; mysqli does not understand that (mysql CLI only).
$sql = preg_replace('/^\s*DELIMITER\s+;;\s*$/m', '', $sql);
$sql = preg_replace('/^\s*DELIMITER\s+;\s*$/m', '', $sql);
$sql = str_replace('END */;;', 'END */;', $sql);

echo "Read " . strlen($sql) . " bytes. Executing...\n";

if (!$mysqli->multi_query($sql)) {
    fwrite(STDERR, 'multi_query failed: ' . $mysqli->error . "\n");
    exit(1);
}

do {
    if ($result = $mysqli->store_result()) {
        $result->free();
    }
} while ($mysqli->next_result());

if ($mysqli->errno) {
    fwrite(STDERR, 'Error after statements: ' . $mysqli->error . "\n");
    exit(1);
}

echo "Import finished successfully into database `" . $dbname . "`.\n";
$mysqli->close();
