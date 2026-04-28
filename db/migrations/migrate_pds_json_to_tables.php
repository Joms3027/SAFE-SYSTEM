<?php
// One-time migration: move JSON repeatable PDS fields into normalized tables
// Run this from CLI: php migrate_pds_json_to_tables.php
// Make a DB backup before running.

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

$database = new Database();
$db = $database->getConnection();

// Fetch all PDS rows
$stmt = $db->prepare("SELECT * FROM faculty_pds");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    $pds_id = $row['id'];

    // Skip if child rows already exist (idempotent)
    $check = $db->prepare("SELECT COUNT(*) FROM pds_children WHERE pds_id = ?");
    $check->execute([$pds_id]);
    if ($check->fetchColumn() > 0) {
        echo "Skipping pds_id $pds_id - already migrated\n";
        continue;
    }

    // CHILDREN
    $children = json_decode($row['children_info'] ?? '[]', true) ?: [];
    foreach ($children as $c) {
        $ins = $db->prepare("INSERT INTO pds_children (pds_id, name, dob) VALUES (?, ?, ?)");
        $dob = !empty($c['dob']) ? $c['dob'] : null;
        $ins->execute([$pds_id, $c['name'] ?? '', $dob]);
    }

    // EDUCATION
    $education = json_decode($row['educational_background'] ?? '[]', true) ?: [];
    foreach ($education as $e) {
        $ins = $db->prepare("INSERT INTO pds_education (pds_id, level, school, degree) VALUES (?, ?, ?, ?)");
        $ins->execute([$pds_id, $e['level'] ?? '', $e['school'] ?? '', $e['degree'] ?? '']);
    }

    // EXPERIENCE
    $experience = json_decode($row['work_experience'] ?? '[]', true) ?: [];
    foreach ($experience as $ex) {
        $ins = $db->prepare("INSERT INTO pds_experience (pds_id, dates, position, company, salary) VALUES (?, ?, ?, ?, ?)");
        $ins->execute([$pds_id, $ex['dates'] ?? '', $ex['position'] ?? '', $ex['company'] ?? '', $ex['salary'] ?? '']);
    }

    // VOLUNTARY
    $voluntary = json_decode($row['voluntary_work'] ?? '[]', true) ?: [];
    foreach ($voluntary as $v) {
        $ins = $db->prepare("INSERT INTO pds_voluntary (pds_id, org, dates, hours, position) VALUES (?, ?, ?, ?, ?)");
        $ins->execute([$pds_id, $v['org'] ?? '', $v['dates'] ?? '', $v['hours'] ?? '', $v['position'] ?? '']);
    }

    // LEARNING
    $learning = json_decode($row['learning_development'] ?? '[]', true) ?: [];
    foreach ($learning as $l) {
        $ins = $db->prepare("INSERT INTO pds_learning (pds_id, title, dates, hours, type, conducted_by) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute([$pds_id, $l['title'] ?? '', $l['dates'] ?? '', $l['hours'] ?? '', $l['type'] ?? '', $l['conducted_by'] ?? '']);
    }

    // REFERENCES stored under other_info->references if present
    $other = json_decode($row['other_info'] ?? '{}', true) ?: [];
    $refs = $other['references'] ?? [];
    foreach ($refs as $r) {
        $ins = $db->prepare("INSERT INTO pds_references (pds_id, name, address, phone) VALUES (?, ?, ?, ?)");
        $ins->execute([$pds_id, $r['name'] ?? '', $r['address'] ?? '', $r['phone'] ?? '']);
    }

    echo "Migrated pds_id $pds_id\n";
}

echo "Migration complete.\n";

?>